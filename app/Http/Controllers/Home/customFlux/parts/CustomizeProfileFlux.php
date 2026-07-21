<?php

namespace App\Http\Controllers\Home\customFlux\parts;

use App\Models\Publication;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Tags;
use Illuminate\Support\Facades\DB;

class CustomizeProfileFlux
{
    /**
     * Get the standard relationships and statistics for publications
     */
    private static function getPublicationRelations()
    {
        return [
            'user:id,first_name,last_name,avatar,slug,is_verified',
            'etablissement:id,nom,logo,slug,is_verified',
            'tags:id,name,slug',
            'publications_booster.shopping_publications',
            'statistiques'
        ];
    }

    /**
     * Get filtered publications based on selected tags and profiles
     * 
     * @param string $profileSlug - The slug of the profile requesting the data
     * @param array $selectedTags - Array of tag IDs
     * @param array $selectedProfiles - Array of profile objects with id and type
     * @param int $perPage - Number of posts per page (default: 4)
     * @param int $page - Page number (default: 1)
     * @return array
     */
    public static function getFilteredPublications($profileSlug, $selectedTags = [], $selectedProfiles = [], $perPage = 4, $page = 1)
    {
        try {
            // Debug: Log the input parameters
            \Log::info('CustomizeProfileFlux: getFilteredPublications called with:', [
                'profileSlug' => $profileSlug,
                'selectedTags' => $selectedTags,
                'selectedProfiles' => $selectedProfiles,
                'perPage' => $perPage,
                'page' => $page
            ]);
            
            // Get profile info to determine type
            $profile = User::where('slug', $profileSlug)->first();
            $profileType = 'user';
            
            if (!$profile) {
                $profile = Etablissement::where('slug', $profileSlug)->first();
                $profileType = 'etablissement';
            }
            
            if (!$profile) {
                \Log::error('CustomizeProfileFlux: Profile not found for slug: ' . $profileSlug);
                return [
                    'status' => 404,
                    'message' => 'Profile not found',
                    'data' => ['publications' => ['data' => []]]
                ];
            }
            
            \Log::info('CustomizeProfileFlux: Found profile: ' . $profile->id . ' (type: ' . $profileType . ')');
            
            // Store current profile info for isFollowed checks
            $currentProfile = [
                'id' => $profile->id,
                'type' => $profileType
            ];
            
            $offset = ($page - 1) * $perPage;
            
            // If both tags and profiles are empty, return empty list
            if (empty($selectedTags) && empty($selectedProfiles)) {
                return [
                    'status' => 200,
                    'message' => 'No filters applied - returning empty list',
                    'data' => [
                        'publications' => [
                            'data' => [],
                            'current_page' => $page,
                            'per_page' => $perPage,
                            'total' => 0
                        ]
                    ]
                ];
            }
            
            // If only tags are provided
            if (!empty($selectedTags) && empty($selectedProfiles)) {
                \Log::info('CustomizeProfileFlux: Processing tags-only request');
                return self::getPostsWithTagsOnly($selectedTags, $profile, $profileType, $perPage, $offset, $currentProfile);
            }
            
            // If only profiles are provided
            if (empty($selectedTags) && !empty($selectedProfiles)) {
                return self::getPostsFromProfilesOnly($selectedProfiles, $perPage, $offset, $currentProfile);
            }
            
            // If both tags and profiles are provided
            if (!empty($selectedTags) && !empty($selectedProfiles)) {
                // Get ALL posts from selected profiles
                $profileResult = self::getPostsFromProfiles($selectedProfiles, 0, 0, $currentProfile);
                $profilePosts = $profileResult['publications'];
                $foundProfiles = $profileResult['found_profiles'];
                
                // Get ALL posts with selected tags
                $tagPosts = self::getPostsWithTags($selectedTags, 0, 0);
                
                // Get ALL intersection posts (posts from selected profiles that also have selected tags)
                $intersectionResult = self::getIntersectionPosts($selectedProfiles, $selectedTags, 0);
                $intersectionPosts = $intersectionResult['publications'];
                
                // Merge and prioritize posts
                $allMergedPosts = self::mergeAndPrioritizePosts($intersectionPosts, $profilePosts, $tagPosts, 0);
                
                // Clean up publications by removing unnecessary fields and add isFollowed and isSaved fields
                $cleanedPosts = collect($allMergedPosts)->map(function($publication) use ($currentProfile) {
                    $cleanedPublication = self::cleanPublicationObject($publication);
                    $cleanedPublication->isFollowed = self::checkIfFollowed($currentProfile, $publication);
                    $cleanedPublication->isSaved = self::checkIfSaved($currentProfile, $publication);
                    $cleanedPublication->statistiques = self::aggregateStatistiques(self::getPublicationStatistiques($publication));
                    return $cleanedPublication;
                });
                
                // Apply pagination
                $mergedPosts = $cleanedPosts->slice($offset, $perPage);
                
                // Keep all fields in final response
                $finalPosts = $mergedPosts;
                
                return [
                    'status' => 200,
                    'message' => 'Filtered publications retrieved successfully',
                    'data' => [
                        'publications' => [
                            'data' => $finalPosts->values()->toArray(),
                            'current_page' => ($offset / $perPage) + 1,
                            'per_page' => $perPage,
                            'total' => $cleanedPosts->count()
                        ],
                        'found_profiles' => $foundProfiles
                    ]
                ];
            }
            
        } catch (\Exception $e) {
            \Log::error('CustomizeProfileFlux: Error occurred: ' . $e->getMessage());
            \Log::error('CustomizeProfileFlux: Stack trace: ' . $e->getTraceAsString());
            
            return [
                'status' => 500,
                'message' => 'Error retrieving filtered publications',
                'error' => $e->getMessage(),
                'data' => ['publications' => ['data' => []]]
            ];
        }
    }
    
    /**
     * Get posts with tags only - prioritize following profiles with those tags
     */
    private static function getPostsWithTagsOnly($selectedTags, $profile, $profileType, $perPage, $offset, $currentProfile)
    {
        // Debug: Log the selected tags
        \Log::info('CustomizeProfileFlux: getPostsWithTagsOnly called with tags: ' . json_encode($selectedTags));
        
        // First, let's test with a simple query to see if we can get ANY posts
        $simpleTest = DB::table('publications_tags_flink')
            ->whereIn('tag_id', $selectedTags)
            ->count();
        
        
        // Get ALL posts with these tags using direct join (faster and more reliable)
        $allTagPosts = Publication::with(self::getPublicationRelations())
        ->join('publications_tags_flink', 'publications_flink.id', '=', 'publications_tags_flink.publication_id')
        ->whereIn('publications_tags_flink.tag_id', $selectedTags)
        ->where('publications_flink.status', 1)
        ->select('publications_flink.*')
        ->distinct()
        ->get();
        
        // If the main query returns no results, try a simpler fallback query
        if ($allTagPosts->isEmpty()) {
            
            // Fallback: Try without the status filter first
            $fallbackPosts = Publication::with(self::getPublicationRelations())
            ->join('publications_tags_flink', 'publications_flink.id', '=', 'publications_tags_flink.publication_id')
            ->whereIn('publications_tags_flink.tag_id', $selectedTags)
            ->where('publications_flink.status', 1)
            ->select('publications_flink.*')
            ->distinct()
            ->get();
            
            if ($fallbackPosts->isEmpty()) {
                return [
                    'status' => 200,
                    'message' => 'No posts found with selected tags',
                    'data' => [
                        'publications' => [
                            'data' => [],
                            'current_page' => ($offset / $perPage) + 1,
                            'per_page' => $perPage,
                            'total' => 0
                        ]
                    ]
                ];
            }
            
            // Use fallback results
            $allTagPosts = $fallbackPosts;
        }
        
        // Score and sort all posts by engagement
        $scoredPosts = $allTagPosts->map(function($post) {
            $post->engagement_score = self::calculateEngagementScore($post);
            return $post;
        })->sortByDesc(function($post) {
            $engagementScore = is_object($post) ? $post->engagement_score : (is_array($post) ? $post['engagement_score'] ?? 0 : 0);
            $createdAt = is_object($post) ? $post->created_at : (is_array($post) ? $post['created_at'] ?? '1970-01-01 00:00:00' : '1970-01-01 00:00:00');
            return [$engagementScore, $createdAt];
        });
        
        // Clean up publications by removing unnecessary fields (after sorting) and add isFollowed and isSaved fields
        $cleanedPosts = $scoredPosts->map(function($publication) use ($currentProfile) {
            $cleanedPublication = self::cleanPublicationObject($publication);
            $cleanedPublication->isFollowed = self::checkIfFollowed($currentProfile, $publication);
            $cleanedPublication->isSaved = self::checkIfSaved($currentProfile, $publication);
            $cleanedPublication->statistiques = self::aggregateStatistiques(self::getPublicationStatistiques($publication));
            return $cleanedPublication;
        });
        
        // Apply pagination
        $paginatedPosts = $cleanedPosts->slice($offset, $perPage);
        
        // Keep all fields in final response
        $finalPosts = $paginatedPosts;
        
        \Log::info('CustomizeProfileFlux: Returning ' . $finalPosts->count() . ' posts out of ' . $cleanedPosts->count() . ' total');
        
        return [
            'status' => 200,
            'message' => 'Posts with tags retrieved successfully',
            'data' => [
                'publications' => [
                    'data' => $finalPosts->values()->toArray(),
                    'current_page' => ($offset / $perPage) + 1,
                    'per_page' => $perPage,
                    'total' => $cleanedPosts->count()
                ]
            ]
        ];
    }
    
    /**
     * Get posts from profiles only
     */
    private static function getPostsFromProfilesOnly($selectedProfiles, $perPage, $offset, $currentProfile)
    {
        // Get ALL posts from selected profiles (no limit initially)
        $result = self::getPostsFromProfiles($selectedProfiles, 0, 0, $currentProfile); // Get ALL posts
        $allProfilePosts = $result['publications'];
        $foundProfiles = $result['found_profiles'];
        
        // Add engagement scores and sort by score
        $scoredPosts = $allProfilePosts->map(function($post) {
            $post->engagement_score = self::calculateEngagementScore($post);
            return $post;
        })->sortByDesc(function($post) {
            $engagementScore = is_object($post) ? $post->engagement_score : (is_array($post) ? $post['engagement_score'] ?? 0 : 0);
            $createdAt = is_object($post) ? $post->created_at : (is_array($post) ? $post['created_at'] ?? '1970-01-01 00:00:00' : '1970-01-01 00:00:00');
            return [$engagementScore, $createdAt];
        });
        
        // Clean up publications by removing unnecessary fields (after sorting) and add isFollowed and isSaved fields
        $cleanedPosts = $scoredPosts->map(function($publication) use ($currentProfile) {
            $cleanedPublication = self::cleanPublicationObject($publication);
            $cleanedPublication->isFollowed = self::checkIfFollowed($currentProfile, $publication);
            $cleanedPublication->isSaved = self::checkIfSaved($currentProfile, $publication);
            $cleanedPublication->statistiques = self::aggregateStatistiques(self::getPublicationStatistiques($publication));
            return $cleanedPublication;
        });
        
        // Apply pagination
        $paginatedPosts = $cleanedPosts->slice($offset, $perPage);
        
        // Keep all fields in final response
        $finalPosts = $paginatedPosts;
        
        return [
            'status' => 200,
            'message' => 'Posts from selected profiles (sorted by score)',
            'data' => [
                'publications' => [
                    'data' => $finalPosts->values()->toArray(),
                    'current_page' => ($offset / $perPage) + 1,
                    'per_page' => $perPage,
                    'total' => $cleanedPosts->count()
                ],
                'found_profiles' => $foundProfiles
            ]
        ];
    }
    
    /**
     * Get posts from following profiles that have specific tags
     */
    private static function getPostsFromFollowingWithTags($profile, $profileType, $selectedTags, $perPage)
    {
        // Get following profiles
        $followingUsers = $profile->followingUsers()->pluck('users.id')->toArray();
        $followingEtablissements = $profile->followingEtablissements()->pluck('etablissements.id')->toArray();
        
        $query = Publication::with(self::getPublicationRelations())
        ->where('status', 'published')
        ->whereHas('tags', function($q) use ($selectedTags) {
            $q->whereIn('tags_flink.id', $selectedTags);
        });
        
        if (!empty($followingUsers) && !empty($followingEtablissements)) {
            $query->where(function($q) use ($followingUsers, $followingEtablissements) {
                $q->whereIn('user_id', $followingUsers)
                  ->orWhereIn('etab_id', $followingEtablissements);
            });
        } elseif (!empty($followingUsers)) {
            $query->whereIn('user_id', $followingUsers);
        } elseif (!empty($followingEtablissements)) {
            $query->whereIn('etab_id', $followingEtablissements);
        } else {
            // No following profiles, return empty collection
            return collect();
        }
        
        return $query->orderBy('created_at', 'desc')
                    ->limit($perPage)
                    ->get();
    }
    
    /**
     * Get posts from selected profiles
     */
    private static function getPostsFromProfiles($selectedProfiles, $perPage, $offset, $currentProfile = null)
    {
        if (empty($selectedProfiles)) {
            return collect();
        }
        
        // Debug: Log the selected profiles
        \Log::info('CustomizeProfileFlux: getPostsFromProfiles called with profiles: ' . json_encode($selectedProfiles));
        
        // Get user IDs and establishment IDs from profile objects
        $userIds = [];
        $etabIds = [];
        $foundProfiles = [];
        
        foreach ($selectedProfiles as $profile) {
            if ($profile['type'] === 'user') {
                $user = User::find($profile['id']);
                if ($user) {
                    $userIds[] = $profile['id'];
                    $foundProfiles[] = [
                        'id' => $user->id,
                        'name' => trim($user->first_name . ' ' . $user->last_name),
                        'slug' => $user->slug,
                        'type' => 'user'
                    ];
                }
            } elseif ($profile['type'] === 'etablissement') {
                $etab = Etablissement::find($profile['id']);
                if ($etab) {
                    $etabIds[] = $profile['id'];
                    $foundProfiles[] = [
                        'id' => $etab->id,
                        'name' => $etab->nom,
                        'slug' => $etab->slug,
                        'type' => 'etablissement'
                    ];
                }
            }
        }
        
        \Log::info('CustomizeProfileFlux: Found user IDs: ' . json_encode($userIds));
        \Log::info('CustomizeProfileFlux: Found etab IDs: ' . json_encode($etabIds));
        \Log::info('CustomizeProfileFlux: Found profiles: ' . json_encode($foundProfiles));
        
        // Use Eloquent relationships to get publications
        $allPublications = collect();
        
        // Get publications from users
        if (!empty($userIds)) {
            $userPublications = User::whereIn('id', $userIds)
                ->with(['publications' => function($query) {
                    $query->with(self::getPublicationRelations())
                    ->where('status', 1)
                    ->orderBy('created_at', 'desc');
                }])
                ->get()
                ->pluck('publications')
                ->flatten();
            
            $allPublications = $allPublications->merge($userPublications);
        }
        
        // Get publications from establishments
        if (!empty($etabIds)) {
            \Log::info('CustomizeProfileFlux: Querying establishments with IDs: ' . json_encode($etabIds));
            
            // Test: Check if there are any publications for these establishments at all
            $totalPublications = Publication::whereIn('etab_id', $etabIds)->count();
            \Log::info('CustomizeProfileFlux: Total publications in DB for these etab IDs: ' . $totalPublications);
            
            $publishedPublications = Publication::whereIn('etab_id', $etabIds)->where('status', 'published')->count();
            \Log::info('CustomizeProfileFlux: Published publications in DB for these etab IDs: ' . $publishedPublications);
            
            // Check what statuses exist for these publications
            $statuses = Publication::whereIn('etab_id', $etabIds)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status')
                ->toArray();
            \Log::info('CustomizeProfileFlux: Publication statuses for these etab IDs: ' . json_encode($statuses));
            
            $etabPublications = Etablissement::whereIn('id', $etabIds)
                ->with(['publications' => function($query) {
                    $query->with(self::getPublicationRelations())
                    ->where('status', 1)
                    ->orderBy('created_at', 'desc');
                }])
                ->get();
            
            \Log::info('CustomizeProfileFlux: Found ' . $etabPublications->count() . ' establishments');
            
            foreach ($etabPublications as $etab) {
                \Log::info('CustomizeProfileFlux: Establishment ' . $etab->id . ' (' . $etab->nom . ') has ' . $etab->publications->count() . ' publications');
            }
            
            $etabPublications = $etabPublications->pluck('publications')->flatten();
            
            \Log::info('CustomizeProfileFlux: Total etab publications after flatten: ' . $etabPublications->count());
            
            // If no publications found via relationship, try direct query as fallback
            if ($etabPublications->count() == 0 && $totalPublications > 0) {
                \Log::info('CustomizeProfileFlux: No publications via relationship, trying direct query as fallback');
                
                // First try with published status
                $directPublications = Publication::with(self::getPublicationRelations())
                ->whereIn('etab_id', $etabIds)
                ->where('status', 1)
                ->orderBy('created_at', 'desc')
                ->get();
                
                \Log::info('CustomizeProfileFlux: Direct query (published) found ' . $directPublications->count() . ' publications');
                
                // If still no published publications, try without status filter
                if ($directPublications->count() == 0) {
                    \Log::info('CustomizeProfileFlux: No published publications, trying without status filter');
                    
                    $directPublications = Publication::with(self::getPublicationRelations())
                    ->whereIn('etab_id', $etabIds)
                    ->where('status', 1)
                    ->orderBy('created_at', 'desc')
                    ->get();
                    
                    \Log::info('CustomizeProfileFlux: Direct query (all statuses) found ' . $directPublications->count() . ' publications');
                }
                
                $etabPublications = $directPublications;
            }
            
            $allPublications = $allPublications->merge($etabPublications);
        }
        
        // Sort by created_at desc and apply pagination
        $sortedPublications = $allPublications->sortByDesc('created_at');
        
        // Clean up publications by removing unnecessary fields (after sorting) and add isFollowed and isSaved fields
        $cleanedPublications = $sortedPublications->map(function($publication) use ($currentProfile) {
            $cleanedPublication = self::cleanPublicationObject($publication);
            $cleanedPublication->isFollowed = $currentProfile ? self::checkIfFollowed($currentProfile, $publication) : false;
            $cleanedPublication->isSaved = $currentProfile ? self::checkIfSaved($currentProfile, $publication) : false;
            $cleanedPublication->statistiques = self::aggregateStatistiques(self::getPublicationStatistiques($publication));
            return $cleanedPublication;
        });
        
        \Log::info('CustomizeProfileFlux: Found ' . $cleanedPublications->count() . ' publications from profiles');
        
        // If perPage is 0, return all, otherwise apply pagination
        if ($perPage == 0) {
            // Remove created_at from final response
            $finalPublications = $cleanedPublications;
            
            return [
                'publications' => $finalPublications,
                'found_profiles' => $foundProfiles
            ];
        } else {
            $paginatedPublications = $cleanedPublications->slice($offset, $perPage);
            
            // Keep all fields in final response
            $finalPublications = $paginatedPublications;
            
            return [
                'publications' => $finalPublications,
                'found_profiles' => $foundProfiles
            ];
        }
    }
    
    /**
     * Get posts with selected tags
     */
    private static function getPostsWithTags($selectedTags, $perPage, $offset)
    {
        if (empty($selectedTags)) {
            return collect();
        }
        
        $query = Publication::with(self::getPublicationRelations())
        ->join('publications_tags_flink', 'publications_flink.id', '=', 'publications_tags_flink.publication_id')
        ->whereIn('publications_tags_flink.tag_id', $selectedTags)
        ->where('publications_flink.status', 1)
        ->select('publications_flink.*')
        ->distinct();
        
        // If perPage is 0, get ALL posts, otherwise apply pagination
        if ($perPage == 0) {
            return $query->get();
        } else {
            return $query->orderBy('publications_flink.created_at', 'desc')
                        ->offset($offset)
                        ->limit($perPage)
                        ->get();
        }
    }
    
    /**
     * Get intersection posts (posts from selected profiles that also have selected tags)
     */
    private static function getIntersectionPosts($selectedProfiles, $selectedTags, $perPage)
    {
        if (empty($selectedProfiles) || empty($selectedTags)) {
            return collect();
        }
        
        // Get user IDs and establishment IDs from profile objects
        $userIds = [];
        $etabIds = [];
        $foundProfiles = [];
        
        foreach ($selectedProfiles as $profile) {
            if ($profile['type'] === 'user') {
                $user = User::find($profile['id']);
                if ($user) {
                    $userIds[] = $profile['id'];
                    $foundProfiles[] = [
                        'id' => $user->id,
                        'name' => trim($user->first_name . ' ' . $user->last_name),
                        'slug' => $user->slug,
                        'type' => 'user'
                    ];
                }
            } elseif ($profile['type'] === 'etablissement') {
                $etab = Etablissement::find($profile['id']);
                if ($etab) {
                    $etabIds[] = $profile['id'];
                    $foundProfiles[] = [
                        'id' => $etab->id,
                        'name' => $etab->nom,
                        'slug' => $etab->slug,
                        'type' => 'etablissement'
                    ];
                }
            }
        }
        
        $query = Publication::with(self::getPublicationRelations())
        ->join('publications_tags_flink', 'publications_flink.id', '=', 'publications_tags_flink.publication_id')
        ->whereIn('publications_tags_flink.tag_id', $selectedTags)
        ->where('publications_flink.status', 1)
        ->select('publications_flink.*')
        ->distinct();
        
        if (!empty($userIds) && !empty($etabIds)) {
            $query->where(function($q) use ($userIds, $etabIds) {
                $q->whereIn('publications_flink.user_id', $userIds)
                  ->orWhereIn('publications_flink.etab_id', $etabIds);
            });
        } elseif (!empty($userIds)) {
            $query->whereIn('publications_flink.user_id', $userIds);
        } elseif (!empty($etabIds)) {
            $query->whereIn('publications_flink.etab_id', $etabIds);
        }
        
        // If perPage is 0, get ALL posts, otherwise apply pagination
        if ($perPage == 0) {
            return [
                'publications' => $query->get(),
                'found_profiles' => $foundProfiles
            ];
        } else {
            return [
                'publications' => $query->orderBy('publications_flink.created_at', 'desc')
                        ->limit($perPage)
                        ->get(),
                'found_profiles' => $foundProfiles
            ];
        }
    }
    
    /**
     * Merge and prioritize posts with smart algorithm
     */
    private static function mergeAndPrioritizePosts($intersectionPosts, $profilePosts, $tagPosts, $perPage)
    {
        $allPosts = collect();
        
        // Priority 1: Intersection posts (posts from selected profiles with selected tags)
        if ($intersectionPosts->isNotEmpty()) {
            $allPosts = $allPosts->merge($intersectionPosts);
        }
        
        // Priority 2: Posts from selected profiles (excluding already added intersection posts)
        if ($profilePosts->isNotEmpty()) {
            $intersectionIds = $intersectionPosts->pluck('id')->toArray();
            $filteredProfilePosts = $profilePosts->reject(function($post) use ($intersectionIds) {
                return in_array($post->id, $intersectionIds);
            });
            $allPosts = $allPosts->merge($filteredProfilePosts);
        }
        
        // Priority 3: Posts with selected tags (excluding already added posts)
        if ($tagPosts->isNotEmpty()) {
            $existingIds = $allPosts->pluck('id')->toArray();
            $filteredTagPosts = $tagPosts->reject(function($post) use ($existingIds) {
                return in_array($post->id, $existingIds);
            });
            $allPosts = $allPosts->merge($filteredTagPosts);
        }
        
        // Remove duplicates
        $uniquePosts = $allPosts->unique('id');
        
        // Add engagement score and sort by relevance
        $scoredPosts = $uniquePosts->map(function($post) {
            $post->engagement_score = self::calculateEngagementScore($post);
            return $post;
        });
        
        // Sort by engagement score (higher first), then by date
        $sortedPosts = $scoredPosts->sortByDesc(function($post) {
            $engagementScore = is_object($post) ? $post->engagement_score : (is_array($post) ? $post['engagement_score'] ?? 0 : 0);
            $createdAt = is_object($post) ? $post->created_at : (is_array($post) ? $post['created_at'] ?? '1970-01-01 00:00:00' : '1970-01-01 00:00:00');
            return [$engagementScore, $createdAt];
        });
        
        return $sortedPosts->values()->toArray();
    }
    
    /**
     * Calculate engagement score for a post
     */
    private static function calculateEngagementScore($post)
    {
        $score = 0;
        
        // Base score from creation date (newer posts get higher score)
        $createdAt = is_object($post) ? (property_exists($post, 'created_at') ? $post->created_at : null) : (is_array($post) ? $post['created_at'] ?? null : null);
        if ($createdAt) {
            $daysSinceCreation = now()->diffInDays($createdAt);
            $score += max(0, 100 - $daysSinceCreation);
        }
        
        // Add score for verified profiles
        $user = is_object($post) ? (property_exists($post, 'user') ? $post->user : null) : (is_array($post) ? $post['user'] ?? null : null);
        if ($user && is_object($user) && property_exists($user, 'is_verified') && $user->is_verified) {
            $score += 50;
        } elseif ($user && is_array($user) && isset($user['is_verified']) && $user['is_verified']) {
            $score += 50;
        }
        
        $etablissement = is_object($post) ? (property_exists($post, 'etablissement') ? $post->etablissement : null) : (is_array($post) ? $post['etablissement'] ?? null : null);
        if ($etablissement && is_object($etablissement) && property_exists($etablissement, 'is_verified') && $etablissement->is_verified) {
            $score += 50;
        } elseif ($etablissement && is_array($etablissement) && isset($etablissement['is_verified']) && $etablissement['is_verified']) {
            $score += 50;
        }
        
        // Add score for posts with images/videos
        $imageDefault = is_object($post) ? (property_exists($post, 'image_default') ? $post->image_default : null) : (is_array($post) ? $post['image_default'] ?? null : null);
        $videoDefault = is_object($post) ? (property_exists($post, 'video_default') ? $post->video_default : null) : (is_array($post) ? $post['video_default'] ?? null : null);
        
        if (!empty($imageDefault)) {
            $score += 20;
        }
        if (!empty($videoDefault)) {
            $score += 30;
        }
        
        // Add score for posts with shopping publications
        $publicationsBooster = is_object($post) ? (property_exists($post, 'publications_booster') ? $post->publications_booster : null) : (is_array($post) ? $post['publications_booster'] ?? null : null);
        if ($publicationsBooster && (is_object($publicationsBooster) ? $publicationsBooster->isNotEmpty() : (is_array($publicationsBooster) && !empty($publicationsBooster)))) {
            $score += 25;
        }
        
        // Add score for posts with tags
        $tags = is_object($post) ? (property_exists($post, 'tags') ? $post->tags : null) : (is_array($post) ? $post['tags'] ?? null : null);
        if ($tags && (is_object($tags) ? $tags->isNotEmpty() : (is_array($tags) && !empty($tags)))) {
            $tagCount = is_object($tags) ? $tags->count() : count($tags);
            $score += $tagCount * 5;
        }
        
        return $score;
    }
    
    /**
     * Clean publication object by removing unnecessary fields
     */
    private static function cleanPublicationObject($publication)
    {
        // Convert to array for easier manipulation
        if (is_object($publication)) {
            // Handle Eloquent models
            if (method_exists($publication, 'toArray')) {
                $publicationArray = $publication->toArray();
            } else {
                // Handle stdClass objects
                $publicationArray = (array) $publication;
            }
        } else {
            // Already an array
            $publicationArray = $publication;
        }
        
        // Remove unnecessary fields (keep created_at and updated_at)
        $fieldsToRemove = [
            'cat_g_id',
            'pays_id', 
            'ville_id',
            'region_id',
            'reference',
            'status',
            'miniature',
            'score',
            'unlocked_number',
            'reason_rejection',
            'last_milestone_notified',
            'deleted_at',
            'engagement_score'
        ];
        
        foreach ($fieldsToRemove as $field) {
            unset($publicationArray[$field]);
        }
        
        // Clean up tags to remove pivot data
        if (isset($publicationArray['tags']) && is_array($publicationArray['tags'])) {
            $publicationArray['tags'] = array_map(function($tag) {
                return [
                    'id' => $tag['id'],
                    'name' => $tag['name'],
                    'slug' => $tag['slug']
                ];
            }, $publicationArray['tags']);
        }
        
        // Clean up publications_booster to keep only specific fields
        if (isset($publicationArray['publications_booster']) && is_array($publicationArray['publications_booster'])) {
            $publicationArray['publications_booster'] = array_map(function($booster) {
                return [
                    'id' => $booster['id'],
                    'objectif_name' => $booster['objectif_name'],
                    'objectif_action' => $booster['objectif_action'],
                    'objectif_value' => $booster['objectif_value'],
                    'message' => $booster['message'],
                    'shopping_publications' => $booster['shopping_publications'] // Keep as is
                ];
            }, $publicationArray['publications_booster']);
        }
        
        // Convert back to object
        return (object) $publicationArray;
    }
    
    /**
     * Check if the current profile is following the post author
     */
    private static function checkIfFollowed($currentProfile, $publication)
    {
        if (!$currentProfile || !$publication) {
            return false;
        }
        
        $currentProfileId = $currentProfile['id'];
        $currentProfileType = $currentProfile['type'];
        
        // Get the post author info
        $postUserId = is_object($publication) ? $publication->user_id : $publication['user_id'];
        $postEtabId = is_object($publication) ? $publication->etab_id : $publication['etab_id'];
        
        // Check if current profile is following the post author
        if ($currentProfileType === 'user') {
            // Current profile is a user
            if ($postUserId && $postUserId !== $currentProfileId) {
                // Post is by a user, check if current user follows that user
                return \DB::table('follows')
                    ->where('user_follower_id', $currentProfileId)
                    ->where('user_following_id', $postUserId)
                    ->exists();
            } elseif ($postEtabId) {
                // Post is by an establishment, check if current user follows that establishment
                return \DB::table('follows')
                    ->where('user_follower_id', $currentProfileId)
                    ->where('etab_following_id', $postEtabId)
                    ->exists();
            }
        } elseif ($currentProfileType === 'etablissement') {
            // Current profile is an establishment
            if ($postUserId) {
                // Post is by a user, check if current establishment follows that user
                return \DB::table('follows')
                    ->where('etab_follower_id', $currentProfileId)
                    ->where('user_following_id', $postUserId)
                    ->exists();
            } elseif ($postEtabId && $postEtabId !== $currentProfileId) {
                // Post is by another establishment, check if current establishment follows that establishment
                return \DB::table('follows')
                    ->where('etab_follower_id', $currentProfileId)
                    ->where('etab_following_id', $postEtabId)
                    ->exists();
            }
        }
        
        return false;
    }
    
    /**
     * Check if the current profile has saved this publication
     */
    private static function checkIfSaved($currentProfile, $publication)
    {
        if (!$currentProfile || !$publication) {
            return false;
        }
        
        $currentProfileId = $currentProfile['id'];
        $currentProfileType = $currentProfile['type'];
        $publicationId = is_object($publication) ? $publication->id : $publication['id'];
        
        // Check if current profile has saved this publication
        if ($currentProfileType === 'user') {
            // Current profile is a user
            return \DB::table('sauvgarde_publications')
                ->where('user_id', $currentProfileId)
                ->where('publication_id', $publicationId)
                ->exists();
        } elseif ($currentProfileType === 'etablissement') {
            // Current profile is an establishment
            return \DB::table('sauvgarde_publications')
                ->where('etab_id', $currentProfileId)
                ->where('publication_id', $publicationId)
                ->exists();
        }
        
        return false;
    }
    
    /**
     * Aggregate statistiques data
     */
    private static function aggregateStatistiques($statistiques)
    {
        if (!$statistiques || (is_array($statistiques) && empty($statistiques)) || (is_object($statistiques) && method_exists($statistiques, 'isEmpty') && $statistiques->isEmpty())) {
            return [
                'click_contact' => 0,
                'click_tele' => 0,
                'click_tele_whatsapp' => 0,
                'click_partage' => 0,
                'click_detail' => 0,
                'vues' => 0
            ];
        }
        
        // Convert to collection if it's an array
        if (is_array($statistiques)) {
            $statistiques = collect($statistiques);
        }
        
        return [
            'click_contact' => $statistiques->sum('click_contact'),
            'click_tele' => $statistiques->sum('click_tele'),
            'click_tele_whatsapp' => $statistiques->sum('click_tele_whatsapp'),
            'click_partage' => $statistiques->sum('click_partage'),
            'click_detail' => $statistiques->sum('click_detail'),
            'vues' => $statistiques->sum('vues')
        ];
    }

    /**
     * Helper to robustly get statistiques array/collection for a publication
     */
    private static function getPublicationStatistiques($publication) {
        if (is_object($publication) && property_exists($publication, 'statistiques')) {
            $stats = $publication->statistiques;
        } elseif (is_array($publication) && isset($publication['statistiques'])) {
            $stats = $publication['statistiques'];
        } else {
            $stats = [];
        }
        if (is_array($stats)) {
            return collect($stats);
        } elseif (is_object($stats) && method_exists($stats, 'isEmpty')) {
            return $stats;
        } else {
            return collect([]);
        }
    }
}
