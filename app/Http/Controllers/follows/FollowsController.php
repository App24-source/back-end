<?php

namespace App\Http\Controllers\follows;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\ProfileStatistique;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use App\Services\NotificationService;
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;


class FollowsController extends Controller
{
    private function updateFirebaseFollowCount($profileId, $profileType, $incrementBy) {
        try {
            $serviceAccountPath = storage_path('app/firebase-service-account.json');
            if (!file_exists($serviceAccountPath)) {
                \Log::warning('Firebase service account file not found at: ' . $serviceAccountPath);
                return null;
            }

            $serviceAccount = ServiceAccount::fromJsonFile($serviceAccountPath);
            $firebase = (new Factory)
                ->withServiceAccount($serviceAccount)
                ->withDatabaseUri('https://notifications-fcm-30115-default-rtdb.firebaseio.com/')
                ->create();

            $database = $firebase->getDatabase();
            $reference = $database->getReference("profiles/{$profileType}/{$profileId}/followersCount");
            
            $currentCount = $reference->getValue() ?? 0;
            $newCount = $currentCount + $incrementBy;
            $reference->set($newCount);
            
            \Log::info("Firebase follow count updated for {$profileType} {$profileId}: {$currentCount} -> {$newCount}");
            return $newCount;
        } catch (\Exception $e) {
            \Log::error('Firebase follow count update failed: ' . $e->getMessage());
            return null;
        }
    }

    public function FollowEntity(Request $request) {
        try {
            // Extract inputs from the request            
            $userFollower = $request->input('userFollower') ?? null;
            $userFollowing = $request->input('userFollowing') ?? null;
            $etabFollower = $request->input('etabFollower') ?? null;
            $etabFollowing = $request->input('etabFollowing') ?? null;
            $mode = $request->input('mode');
            
            if ($mode === "follow") {
                // Determine the type of follow relationship based on input parameters
                if ($userFollower && $userFollowing) {
                    // User following another user
                    $follower = User::findOrFail($userFollower);
                    $following = User::findOrFail($userFollowing);
                    
                    // Check if the follow relationship already exists
                    if ($follower->followingUsers()->where('user_following_id', $userFollowing)->exists()) {
                        return response()->json(['message' => 'Already following this user'], 200);
                    }
                    
                    $follower->followingUsers()->attach($userFollowing);
                    $notificationsCount = $following->notifications()->where('created_at', '>=', now()->subDay())
                    ->where('type', 'App\Notifications\NewFollowerNotification')->count();

                    if($notificationsCount == 0)
                    app(NotificationService::class)->sendNewFollowerNotification($follower, $following);

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "user_id" => $userFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    // Incrémentation du champ correspondant à l'action spécifiée
                    $stat->increment("followers_count");

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($userFollowing, 'user', 1);

                    return response()->json(['message' => 'User successfully followed', 'follower' => $follower, 'following' => $following], 200);
                } elseif ($userFollower && $etabFollowing) {
                    // User following an establishment
                    $follower = User::findOrFail($userFollower);
                    $following = Etablissement::findOrFail($etabFollowing);
                    
                    // Check if the follow relationship already exists
                    if ($follower->followingEtablissements()->where('etab_following_id', $etabFollowing)->exists()) {
                        return response()->json(['message' => 'Already following this establishment'], 200);
                    }
                    
                    $follower->followingEtablissements()->attach($etabFollowing);
                    $notificationsCount = $following->notifications()->where('created_at', '>=', now()->subDay())
                    ->where('type', 'App\Notifications\NewFollowerNotification')->count();

                    if($notificationsCount == 0)
                    app(NotificationService::class)->sendNewFollowerNotification($follower, $following);
                    

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "etab_id" => $etabFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    // Incrémentation du champ correspondant à l'action spécifiée
                    $stat->increment("followers_count");

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($etabFollowing, 'etab', 1);

                    return response()->json(['message' => 'Establishment successfully followed'], 200);
                    
                } elseif ($etabFollower && $userFollowing) {
                    // Establishment following a user
                    $follower = Etablissement::findOrFail($etabFollower);
                    $following = User::findOrFail($userFollowing);
                    
                    // Check if the follow relationship already exists
                    if ($follower->followingUsers()->where('user_following_id', $userFollowing)->exists()) {
                        return response()->json(['message' => 'Already following this user'], 200);
                    }
                    
                    $follower->followingUsers()->attach($userFollowing);
                    $notificationsCount = $following->notifications()->where('created_at', '>=', now()->subDay())
                    ->where('type', 'App\Notifications\NewFollowerNotification')->count();

                    if($notificationsCount == 0)
                    app(NotificationService::class)->sendNewFollowerNotification($follower, $following);

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "user_id" => $userFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    // Incrémentation du champ correspondant à l'action spécifiée
                    $stat->increment("followers_count");

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($userFollowing, 'user', 1);

                    return response()->json(['message' => 'User successfully followed by establishment', 'follower' => $follower, 'following' => $following], 200);
                    
                } elseif ($etabFollower && $etabFollowing) {
                    // Establishment following another establishment
                    $follower = Etablissement::findOrFail($etabFollower);
                    $following = Etablissement::findOrFail($etabFollowing);
                    
                    // Check if the follow relationship already exists
                    if ($follower->followingEtablissements()->where('etab_following_id', $etabFollowing)->exists()) {
                        return response()->json(['message' => 'Already following this establishment'], 200);
                    }
                    
                    $follower->followingEtablissements()->attach($etabFollowing);
                    $notificationsCount = $following->notifications()->where('created_at', '>=', now()->subDay())
                    ->where('type', 'App\Notifications\NewFollowerNotification')->count();

                    if($notificationsCount == 0)
                    app(NotificationService::class)->sendNewFollowerNotification($follower, $following);

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "etab_id" => $etabFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    // Incrémentation du champ correspondant à l'action spécifiée
                    $stat->increment("followers_count");

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($etabFollowing, 'etab', 1);

                    return response()->json(['message' => 'Establishment successfully followed'], 200);
                    
                } else {
                    // Invalid parameters
                    return response()->json(['error' => 'Invalid follow parameters'], 400);
                }
                
            } elseif ($mode === "unfollow") {
                // Handle unfollow using similar logic but with detach()
                if ($userFollower && $userFollowing) {
                    // User unfollowing another user
                    $follower = User::findOrFail($userFollower);
                    
                    // Check if the relationship exists before detaching
                    if (!$follower->followingUsers()->where('user_following_id', $userFollowing)->exists()) {
                        return response()->json(['message' => 'Not following this user'], 200);
                    }
                    
                    $follower->followingUsers()->detach($userFollowing);

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "user_id" => $userFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    
                    // Décrémentation du champ correspondant à l'action spécifiée
                    if ($stat["followers_count"] > 0) {
                        $stat->decrement("followers_count");
                    }

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($userFollowing, 'user', -1);

                    return response()->json(['message' => 'User successfully unfollowed'], 200);
                    
                } elseif ($userFollower && $etabFollowing) {
                    // User unfollowing an establishment
                    $follower = User::findOrFail($userFollower);
                    
                    // Check if the relationship exists before detaching
                    if (!$follower->followingEtablissements()->where('etab_following_id', $etabFollowing)->exists()) {
                        return response()->json(['message' => 'Not following this establishment'], 200);
                    }
                    
                    $follower->followingEtablissements()->detach($etabFollowing);

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "etab_id" => $etabFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    
                    // Décrémentation du champ correspondant à l'action spécifiée
                    if ($stat["followers_count"] > 0) {
                        $stat->decrement("followers_count");
                    }

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($etabFollowing, 'etab', -1);

                    return response()->json(['message' => 'Establishment successfully unfollowed'], 200);
                    
                } elseif ($etabFollower && $userFollowing) {
                    // Establishment unfollowing a user
                    $follower = Etablissement::findOrFail($etabFollower);
                    
                    // Check if the relationship exists before detaching
                    if (!$follower->followingUsers()->where('user_following_id', $userFollowing)->exists()) {
                        return response()->json(['message' => 'Not following this user'], 200);
                    }
                    
                    $follower->followingUsers()->detach($userFollowing);

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "user_id" => $userFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    
                    // Décrémentation du champ correspondant à l'action spécifiée
                    if ($stat["followers_count"] > 0) {
                        $stat->decrement("followers_count");
                    }

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($userFollowing, 'user', -1);

                    return response()->json(['message' => 'User successfully unfollowed by establishment'], 200);
                    
                } elseif ($etabFollower && $etabFollowing) {
                    // Establishment unfollowing another establishment
                    $follower = Etablissement::findOrFail($etabFollower);
                    
                    // Check if the relationship exists before detaching
                    if (!$follower->followingEtablissements()->where('etab_following_id', $etabFollowing)->exists()) {
                        return response()->json(['message' => 'Not following this establishment'], 200);
                    }
                    
                    $follower->followingEtablissements()->detach($etabFollowing);

                    // Recherche ou création d'une statistique pour la profile et la date actuelle
                    $stat = ProfileStatistique::firstOrCreate(
                        [
                            "etab_id" => $etabFollowing,
                            "date" => now()->toDateString()
                        ]
                    );
            
                    
                    // Décrémentation du champ correspondant à l'action spécifiée
                    if ($stat["followers_count"] > 0) {
                        $stat->decrement("followers_count");
                    }

                    // Update Firebase follow count
                    $this->updateFirebaseFollowCount($etabFollowing, 'etab', -1);

                    return response()->json(['message' => 'Establishment successfully unfollowed'], 200);
                    
                } else {
                    // Invalid parameters
                    return response()->json(['error' => 'Invalid unfollow parameters'], 400);
                }
                
            } else {
                // Handle invalid mode
                return response()->json(['error' => 'Invalid mode'], 400);
            }
        } catch (\Exception $e) {
            // Return more specific error information for debugging
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Check if a follow relationship exists
     */
    public function checkFollowStatus(Request $request) {
        try {
            $userFollower = $request->input("userFollower") ?? null;
            $userFollowing = $request->input("userFollowing") ?? null;
            $etabFollower = $request->input("etabFollower") ?? null;
            $etabFollowing = $request->input("etabFollowing") ?? null;
            
            $isFollowing = false;
            
            if ($userFollower && $userFollowing) {
                $follower = User::findOrFail($userFollower);
                $isFollowing = $follower->followingUsers()->where("user_following_id", $userFollowing)->exists();
                
            } elseif ($userFollower && $etabFollowing) {
                $follower = User::findOrFail($userFollower);
                $isFollowing = $follower->followingEtablissements()->where("etab_following_id", $etabFollowing)->exists();
                
            } elseif ($etabFollower && $userFollowing) {
                $follower = Etablissement::findOrFail($etabFollower);
                $isFollowing = $follower->followingUsers()->where("user_following_id", $userFollowing)->exists();
                
            } elseif ($etabFollower && $etabFollowing) {
                $follower = Etablissement::findOrFail($etabFollower);
                $isFollowing = $follower->followingEtablissements()->where("etab_following_id", $etabFollowing)->exists();
                
            } else {
                return response()->json(['error' => 'Invalid parameters'], 400);
            }
            
            return response()->json(['isFollowing' => $isFollowing], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    /**
     * Get followers list for a user or establishment
     */
    public function getFollowers(Request $request) {
        try {
            $userId = $request->input('userId');
            $etabId = $request->input('etabId');
            
            if ($userId) {
                $user = User::findOrFail($userId);
                $userFollowers = $user->followersUsers()->with('profile')->get();
                $etabFollowers = $user->followersEtablissements()->get();
                
                return response()->json([
                    'userFollowers' => $userFollowers,
                    'etabFollowers' => $etabFollowers
                ], 200);
                
            } elseif ($etabId) {
                $etab = Etablissement::findOrFail($etabId);
                $userFollowers = $etab->followersUsers()->with('profile')->get();
                $etabFollowers = $etab->followersEtablissements()->get();
                
                return response()->json([
                    'userFollowers' => $userFollowers,
                    'etabFollowers' => $etabFollowers
                ], 200);
                
            } else {
                return response()->json(['error' => 'User ID or Establishment ID required'], 400);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    /**
     * Get following list for a user or establishment
     */
    public function getFollowing(Request $request) {
        try {
            $userId = $request->input('userId');
            $etabId = $request->input('etabId');
            
            if ($userId) {
                $user = User::findOrFail($userId);
                $followingUsers = $user->followingUsers()->with('profile')->get();
                $followingEtabs = $user->followingEtablissements()->get();
                
                return response()->json([
                    'followingUsers' => $followingUsers,
                    'followingEtabs' => $followingEtabs
                ], 200);
                
            } elseif ($etabId) {
                $etab = Etablissement::findOrFail($etabId);
                $followingUsers = $etab->followingUsers()->with('profile')->get();
                $followingEtabs = $etab->followingEtablissements()->get();
                
                return response()->json([
                    'followingUsers' => $followingUsers,
                    'followingEtabs' => $followingEtabs
                ], 200);
                
            } else {
                return response()->json(['error' => 'User ID or Establishment ID required'], 400);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }



    // public function getSuggestions($profileSlug) {
    //     // Get profile data if needed
    //     $authUser = null;
    //     if ($profileSlug) {
    //         $authUser = User::where('slug', $profileSlug)
    //             ->with([
    //                 'followingUsers', 
    //                 'followingEtablissements', 
    //                 'followersUsers', 
    //                 'followersEtablissements',
    //                 'tags_filters'
    //             ])
    //             ->first();
            
    //         if (!$authUser) {
    //             $authUser = Etablissement::where('slug', $profileSlug)
    //                 ->with([
    //                     'followingUsers', 
    //                     'followingEtablissements', 
    //                     'followersUsers', 
    //                     'followersEtablissements',
    //                     'tags_filters'
    //                 ])
    //                 ->first();
    //         }
    //     }
        
    //     if (!$authUser && $profileSlug) {
    //         return response()->json(['message' => 'Profile not found'], 404);
    //     }

    //     // Get auth user's tags if available
    //     $authUserTags = $authUser ? $authUser->tags_filters()
    //         ->where('status', 1)
    //         ->orderByPivot('updated_at', 'DESC')
    //         ->limit(20)
    //         ->pluck('tags_flink.id')
    //         ->toArray() : [];

    //     // Base query for users with relationships (with limit for performance)
    //     $users = User::select(
    //         'id', 
    //         'first_name', 
    //         'last_name', 
    //         'avatar', 
    //         'slug', 
    //         'is_verified', 
    //         'score', 
    //         'bio',
    //         DB::raw("'user' as type")
    //     )
    //     ->with([
    //         'followingUsers', 
    //         'followingEtablissements', 
    //         'followersUsers', 
    //         'followersEtablissements',
    //         'tags_filters'
    //     ])
    //     ->limit(500)
    //     ->get();

    //     // Base query for establishments with relationships
    //     $etablissements = Etablissement::select(
    //         'id', 
    //         'nom', 
    //         'logo', 
    //         'slug', 
    //         'is_verified', 
    //         'score', 
    //         'bio',
    //         DB::raw("'etablissement' as type")
    //     )
    //     ->with([
    //         'followingUsers', 
    //         'followingEtablissements', 
    //         'followersUsers', 
    //         'followersEtablissements',
    //         'tags_filters'
    //     ])
    //     ->limit(500)
    //     ->get();

    //     // Combine and process all results
    //     $combined = $users->map(function($item) {
    //         $item->type = 'user';
    //         $item->name = $item->first_name . ' ' . $item->last_name;
    //         return $item;
    //     })->merge($etablissements->map(function($item) {
    //         $item->type = 'etablissement';
    //         $item->name = $item->nom;
    //         return $item;
    //     }))->filter();

    //     // Apply relationship scoring when we have an authenticated user
    //     $scoredResults = $combined->map(function($profile) use ($authUser, $authUserTags) {
    //         // Initialize relationship flags
    //         $isFollowingYou = false;
    //         $youAreFollowing = false;
    //         $commonFollowers = 0;
    //         $commonTagsCount = 0;
    //         $priorityLabel = 'no_relationship';

    //         if ($authUser) {
    //             if ($profile->type == "user") {
    //                 // Check if current user follows this profile
    //                 $isFollowingYou = $profile->followersUsers->contains('id', $authUser->id) 
    //                     || $profile->followersEtablissements->contains('id', $authUser->id);
                    
    //                 // Check if this profile follows current user
    //                 $youAreFollowing = $profile->followingUsers->contains('id', $authUser->id)
    //                     || $profile->followingEtablissements->contains('id', $authUser->id);
                    
    //                 // Count common followers
    //                 $profileFollowers = $profile->followersUsers->pluck('id');
    //                 $authUserFollowers = $authUser->followersUsers->pluck('id');
    //                 $commonFollowers = $profileFollowers->intersect($authUserFollowers)->count();
    //             } else {
    //                 // Check if current user follows this profile
    //                 $isFollowingYou = $profile->followersEtablissements->contains('id', $authUser->id)
    //                     || $profile->followersUsers->contains('id', $authUser->id);
                    
    //                 // Check if this profile follows current user
    //                 $youAreFollowing = $profile->followingEtablissements->contains('id', $authUser->id)
    //                     || $profile->followingUsers->contains('id', $authUser->id);
                    
    //                 // Count common followers
    //                 $profileFollowers = $profile->followersEtablissements->pluck('id');
    //                 $authUserFollowers = $authUser->followersEtablissements->pluck('id');
    //                 $commonFollowers = $profileFollowers->intersect($authUserFollowers)->count();
    //             }

    //             // Count common tags
    //             if (!empty($authUserTags) && $profile->tags_filters) {
    //                 $profileTags = $profile->tags_filters->pluck('id')->toArray();
    //                 $commonTagsCount = count(array_intersect($authUserTags, $profileTags));
    //             }

    //             // Calculate priority score and set label
    //             if ($isFollowingYou) {
    //                 $priorityScore = 3; // Increased from 2 to make space for common tags
    //                 $priorityLabel = 'follows_you';
    //             } elseif ($commonFollowers > 0) {
    //                 $priorityScore = 2; // Increased from 1
    //                 $priorityLabel = 'common_connection';
    //             } elseif ($commonTagsCount > 0) {
    //                 $priorityScore = 1; // New priority level for common tags
    //                 $priorityLabel = 'common_tags';
    //             } else {
    //                 $priorityScore = 0;
    //                 $priorityLabel = 'no_relationship';
    //             }

    //             // Add relationship information to the profile
    //             $profile->priority_score = $priorityScore;
    //             $profile->priority_label = $priorityLabel;
    //         } else {
    //             // Default values for non-authenticated users
    //             $profile->priority_score = 0;
    //             $profile->priority_label = 'no_relationship';
    //         }

    //         $profile->is_following_you = $isFollowingYou;
    //         $profile->you_are_following = $youAreFollowing;
    //         $profile->common_followers_count = $commonFollowers;
    //         $profile->common_tags_count = $commonTagsCount;

    //         return $profile;
    //     });

    //     // Define possible sorting options
    //     $orderOptions = ["priority_score", "common_tags_count"];
    //     $selectedOrder = $orderOptions[array_rand($orderOptions)]; // Randomly select one

    //     // Sort results with the randomly selected priority
    //     $orderedResults = $scoredResults->sortByDesc(function($profile) use ($selectedOrder) {
    //         return [
    //             $profile->{$selectedOrder}, // Primary sort by randomly selected field
    //             $profile->score             // Secondary sort by profile score
    //         ];
    //     })->values();

    //     // Pagination
    //     $currentPage = LengthAwarePaginator::resolveCurrentPage();
    //     $perPage = 12;
    //     $currentItems = $orderedResults->slice(($currentPage - 1) * $perPage, $perPage)->values();

    //     $paginator = new LengthAwarePaginator(
    //         $currentItems,
    //         $orderedResults->count(),
    //         $perPage,
    //         $currentPage,
    //         [
    //             'path' => LengthAwarePaginator::resolveCurrentPath(),
    //             'pageName' => 'page'
    //         ]
    //     );

    //     $paginator->appends(request()->except('page'));
        
    //     return $paginator;
    // }


    public function getSuggestions($profileSlug) {
        // Get profile data if needed with minimal data
        $authUser = null;
        $authUserTags = [];
        
        if ($profileSlug) {
            $authUser = Cache::remember("profile_{$profileSlug}", now()->addHours(1), function() use ($profileSlug) {
                return User::where('slug', $profileSlug)
                    ->select('id')
                    ->with(['tags_filters' => function($q) {
                        $q->where('status', 1)
                        ->orderByPivot('updated_at', 'DESC')
                        ->limit(20);
                    }])
                    ->first() ?? 
                    Etablissement::where('slug', $profileSlug)
                        ->select('id')
                        ->with(['tags_filters' => function($q) {
                            $q->where('status', 1)
                            ->orderByPivot('updated_at', 'DESC')
                            ->limit(20);
                        }])
                        ->first();
            });

            if (!$authUser) {
                return response()->json(['message' => 'Profile not found'], 404);
            }

            // Get auth user's tags
            $authUserTags = $authUser->tags_filters->pluck('id')->toArray();
        }

        // Base query for users with only needed relationships
        $usersQuery = User::select(
            'id', 
            'first_name', 
            'last_name', 
            'avatar', 
            'slug', 
            'is_verified', 
            'score', 
            'bio',
            DB::raw("'user' as type")
        )
        ->whereNot('slug', $profileSlug)
        ->with([
            'followersUsers:id',
            'followersEtablissements:id',
            'tags_filters:id'
        ]);

        // Base query for establishments with only needed relationships
        $etablissementsQuery = Etablissement::select(
            'id', 
            'nom', 
            'logo', 
            'slug', 
            'is_verified', 
            'score', 
            'bio',
            DB::raw("'etablissement' as type")
        )
        ->whereNot('slug', $profileSlug)
        ->with([
            'followersEtablissements:id',
            'followersUsers:id',
            'tags_filters:id'
        ]);

        // Execute queries in parallel if possible (using Laravel's lazy collections)
        $users = $usersQuery->get();
        $etablissements = $etablissementsQuery->get();

        // Combine and process results more efficiently
        $combined = collect()
            ->merge($users->map(function($item) {
                $item->type = 'user';
                $item->name = $item->first_name . ' ' . $item->last_name;
                return $item;
            }))
            ->merge($etablissements->map(function($item) {
                $item->type = 'etablissement';
                $item->name = $item->nom;
                return $item;
            }));

        // Pre-calculate auth user relationships if exists
        $authUserId = $authUser ? $authUser->id : null;
        $authUserType = $authUser ? ($authUser instanceof User ? 'user' : 'etablissement') : null;

        // Score results with optimized relationship checks
        $scoredResults = $combined->map(function($profile) use ($authUserId, $authUserType, $authUserTags) {
            $isFollowingYou = false;
            $commonFollowers = 0;
            $commonTagsCount = 0;
            $priorityLabel = 'no_relationship';

            if ($authUserId) {
                // Check if profile follows auth user (simplified check)
                $isFollowingYou = $profile->followersUsers->contains('id', $authUserId) || 
                                $profile->followersEtablissements->contains('id', $authUserId);

                // Common tags count (optimized)
                if (!empty($authUserTags) && $profile->tags_filters->isNotEmpty()) {
                    $profileTags = $profile->tags_filters->pluck('id')->toArray();
                    $commonTagsCount = count(array_intersect($authUserTags, $profileTags));
                }

                // Determine priority (simplified logic)
                if ($isFollowingYou) {
                    $priorityScore = 3;
                    $priorityLabel = 'follows_you';
                } elseif ($commonTagsCount > 0) {
                    $priorityScore = 2;
                    $priorityLabel = 'common_tags';
                } else {
                    $priorityScore = 0;
                }
            } else {
                $priorityScore = 0;
            }

            return [
                'profile' => $profile,
                'priority_score' => $priorityScore,
                'priority_label' => $priorityLabel,
                'is_following_you' => $isFollowingYou,
                'common_tags_count' => $commonTagsCount,
                'score' => $profile->score
            ];
        });

        // // Sort with cached random order to avoid changing on each request
        // $selectedOrder = Cache::remember('suggestion_sort_order', now()->addDay(), function() {
        //     return ["priority_score", "common_tags_count"][rand(0, 1)];
        // });

        // Define possible sorting options
        $orderOptions = ["priority_score", "common_tags_count"];
        $selectedOrder = $orderOptions[array_rand($orderOptions)]; // Randomly select one

        // // Sort results with the randomly selected priority
        // $orderedResults = $scoredResults->sortByDesc(function($profile) use ($selectedOrder) {
        //     return [
        //         $profile->{$selectedOrder}, // Primary sort by randomly selected field
        //         $profile->score             // Secondary sort by profile score
        //     ];
        // })->values();

        // Sort results more efficiently
        $orderedResults = $scoredResults->sortByDesc(function($item) use ($selectedOrder) {
            return [$item[$selectedOrder], $item['score']];
        })->values();

        // Paginate more efficiently
        $paginator = new LengthAwarePaginator(
            $orderedResults->forPage(LengthAwarePaginator::resolveCurrentPage(), 12),
            $orderedResults->count(),
            12,
            LengthAwarePaginator::resolveCurrentPage(),
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );

        return $paginator;
    }

}