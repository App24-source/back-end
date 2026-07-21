<?php

namespace App\Http\Controllers\search;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Publication;
use App\Models\User;
use App\Models\Etablissement;

use Carbon\Carbon;
use App\Models\Tags;
use App\Models\Pays;
use App\Models\Ville;
use App\Models\Region;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class SearchController extends Controller
{
    public function TopPublications(Request $request) {
        // Log::info("Call to TopPublications : ". $request->ip());
        $search = $request->query('search');
        $searchName = $request->query('searchName');
        $profile = $request->query('profile');

        // Track tag interest for the requesting profile (user_tags_filters_flink) - ONLY for tags, not locations
        if($search && $profile) {
            try {
                // Extract only tag-related keywords from search (exclude location keywords)
                $keywords = explode("-", $search);
                $tagKeywords = [];
                
                foreach ($keywords as $keyword) {
                    // Check if this keyword is a tag (not a location)
                    $isTag = Tags::where('slug', $keyword)->exists();
                    if ($isTag) {
                        $tagKeywords[] = $keyword;
                    }
                }
                
                // Only process if we have actual tag keywords
                if (!empty($tagKeywords)) {
                    $profileForTags = User::where('slug', $profile)->first();
                    if (!$profileForTags) {
                        $profileForTags = Etablissement::where('slug', $profile)->first();
                    }
                    
                    if ($profileForTags) {
                        foreach ($tagKeywords as $tagKeyword) {
                            $tag = Tags::where('slug', $tagKeyword)->first();
                            if ($tag) {
                                $existing = $profileForTags->tags_filters()->where('tag_id', $tag->id)->first();
                                if ($existing) {
                                    // Increment compteur
                                    $existing->pivot->increment('compteur');
                                    Log::info("SearchController - Incremented tag compteur", [
                                        'profile' => $profile,
                                        'tag_id' => $tag->id,
                                        'tag_name' => $tag->name,
                                        'search_keyword' => $tagKeyword
                                    ]);
                                } else {
                                    // Attach new tag filter row with compteur = 1 (NO location fields)
                                    $profileForTags->tags_filters()->attach($tag->id, [
                                        'compteur' => 1
                                    ]);
                                    Log::info("SearchController - Added new tag filter", [
                                        'profile' => $profile,
                                        'tag_id' => $tag->id,
                                        'tag_name' => $tag->name,
                                        'search_keyword' => $tagKeyword
                                    ]);
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error("SearchController - Error in tag tracking", [
                    'profile' => $profile,
                    'search' => $search,
                    'error' => $e->getMessage()
                ]);
            }
        }
        try{

            $queryTopPublications = Publication::select(
                'id', 'slug', 'pays_id', 'ville_id', 'region_id', 'user_id', 'etab_id', 'image_default', 'prix', 'prixPromo', 'score', 'video_compresse',
                )
                ->where('status', 1)
                ->whereNotNull('image_default')
                ->withCount([
                    'publications_booster as booster' => function ($query) {
                        $query->where('date_fin', '>', Carbon::now());
                    }
                ])
                ->having('booster', 0)
                ->with([
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug,avatar',
                    'etablissement:id,nom,slug,logo',
                    'tags:id,name,slug',
                    'statistiques' => function ($query) {
                        $query->selectRaw('
                            publication_id,
                            SUM(vues) as vues
                        ')->groupBy('publication_id');
                    },
                ]);

            $keywords = explode("-", $search);
            $paysIds = [];
            $villeIds = [];
            $regionIds = [];

            foreach ($keywords as $keyword) {
                // Check Pays (and store only IDs)
                $paysIds = array_merge(
                    $paysIds,
                    Pays::where('slug', $keyword)->pluck('id')->toArray()
                );

                // Check Ville (and store only IDs)
                $villeIds = array_merge(
                    $villeIds,
                    Ville::where('slug', $keyword)->pluck('id')->toArray()
                );

                // Check Region (and store only IDs)
                $regionIds = array_merge(
                    $regionIds,
                    Region::where('slug', $keyword)->pluck('id')->toArray()
                );
            }

            // Remove duplicates
            $paysIds = array_unique($paysIds);
            $villeIds = array_unique($villeIds);
            $regionIds = array_unique($regionIds);


            $filtredPublications = searchFilteredPublications(clone $queryTopPublications, $search, $searchName, $paysIds, $villeIds, $regionIds);

            // return $filtredPublications;

            $defaultRadiusKm = 100;
            $maxAttempts = 3; // Set a maximum number of attempts to prevent infinite loop
            $attempts = 0;

            while($filtredPublications->count() < 12 && $attempts < $maxAttempts) {
                $attempts++;
                
                if(count($regionIds) !== 0) {
                    $filtredPublications = searchFilteredPublications(clone $queryTopPublications, $search, $searchName, $paysIds, $villeIds, $regionIds);
                } 
                if($filtredPublications->count() < 12 && count($villeIds) !== 0) {
                    $villeIdsArray = [];
                    foreach ($villeIds as $villeId) {
                        $nearbyLocations = getNearbyLocation($villeId, $defaultRadiusKm, "ville");
                        $villeIdsArray = array_unique(array_merge($villeIdsArray, $nearbyLocations));
                    }
                    $filtredPublications = searchFilteredPublications(clone $queryTopPublications, $search, $searchName, $paysIds, $villeIdsArray, $regionIds, $villeIds);
                    break;
                }
                
                if($filtredPublications->count() >= 12) {
                    break;
                }
            }

            if($profile) {
                $profileObject = null;
                $user = User::where('slug', $profile)->first();
                $etablissement = Etablissement::where('slug', $profile)->first();
                if(User::where('slug', $profile)->first()) {
                    $profileObject = User::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
                } else if(Etablissement::where('slug', $profile)->first()) {
                    $profileObject = Etablissement::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
                }
            } else {
                $profileObject = null;
            }
            
            if($profileObject)
                $profileRecommendationsWithFilter = searchFilteredPublications($profileObject->recommendations(), $search, $searchName, $paysIds, $villeIds, $regionIds);
            else $profileRecommendationsWithFilter = null;
            $orderedTopPublications = getOrderedPublicationsWithPreferencesTest($filtredPublications, $profileRecommendationsWithFilter);
            $topPublications = addBoosterToPublicationsToSearch($orderedTopPublications, $request, 12);

            

            // $topPublicationsMarketplace = $filtredPublications->where('is_marketplace', 1)->limit(12)->get()->shuffle()->take(4);
            $topPublicationsMarketplace = Publication::select(
                'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 'image_default', 'prix', 'prixPromo', 'score'
                )
                ->where('status', 1)
                ->whereNotNull('image_default')
                ->where('is_marketplace', 1)
                ->withCount([
                    'publications_booster as booster' => function ($query) {
                        $query->where('date_fin', '>', Carbon::now());
                    }
                ])
                ->having('booster', 0)
                ->with([
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug,avatar',
                    'etablissement:id,nom,slug,logo',
                    'tags:id,name,slug',
                    'publications_booster.shopping_publications',
                    'publications_booster' => function($query) {
                        $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message')
                            ->where('date_fin', '>', Carbon::now())->orderBy('id', 'desc');
                    },
                    'statistiques' => function ($query) {
                        $query->selectRaw('
                            publication_id,
                            SUM(vues) as vues
                        ')->groupBy('publication_id');
                    },
                ]);

            $filtredPublications = searchFilteredPublications($topPublicationsMarketplace, $search, $searchName, $paysIds, $villeIds, $regionIds);
            $orderedTopPublications = getOrderedPublicationsWithPreferencesTest($filtredPublications, $profileRecommendationsWithFilter);
            $topPublicationsMarketplace = addBoosterToPublicationsToSearch($orderedTopPublications, $request, 4, false, false);

            // profiles
            // Get profile data if needed
            $profileObject = null;
            $followedSlugs = [];

            if ($profile) {
                $profileObject = User::where('slug', $profile)
                    ->with(['followingUsers', 'followingEtablissements'])
                    ->first();
                
                if (!$profileObject) {
                    $profileObject = Etablissement::where('slug', $profile)
                        ->with(['followingUsers', 'followingEtablissements'])
                        ->first();
                }

                if ($profileObject) {
                    // Get all followed slugs at once
                    if (method_exists($profileObject, 'followingUsers') && method_exists($profileObject, 'followingEtablissements')) {
                        $followedSlugs = array_merge(
                            $profileObject->followingEtablissements->pluck('slug')->toArray(),
                            $profileObject->followingUsers->pluck('slug')->toArray()
                        );
                    }
                }
            }

             // Base query for users with relationships
            $userQuery = User::select(
                'id', 
                'first_name', 
                'last_name', 
                'avatar', 
                'slug', 
                'is_verified', 
                'score', 
                'bio',
                DB::raw("'user' as type")
            );

            // Base query for establishments with relationships
            $etablissementQuery = Etablissement::select(
                'id', 
                'nom', 
                'logo', 
                'slug', 
                'is_verified', 
                'score', 
                'bio',
                DB::raw("'etablissement' as type")
            );

            // $topProfiles = searchFilteredProfiles($userQuery, $etablissementQuery, $search, $profile, $followedSlugs, 3);
            $combined = searchFilteredProfiles($userQuery, $etablissementQuery, $search, $profile, $followedSlugs);
            $topProfiles = profilePreferences($combined, $profileObject, 3);
            // $topProfiles = $combined->take(3);



            return response()->json([
                'status' => 201, 
                'data' => ["topPublications" => $topPublications, "topPublicationsMarketplace" => $topPublicationsMarketplace, "topProfiles" => $topProfiles],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()]);
        }
    }

    public function Profiles(Request $request) {
        $search = $request->query('search');
        $profile = $request->query('profile');

        try {
            // Get profile data if needed
            $profileObject = null;
            $followedSlugs = [];
            if ($profile) {
                $profileObject = User::where('slug', $profile)
                    ->with(['followingUsers', 'followingEtablissements'])
                    ->first();
                
                if (!$profileObject) {
                    $profileObject = Etablissement::where('slug', $profile)
                        ->with(['followingUsers', 'followingEtablissements'])
                        ->first();
                }
                
                if ($profileObject) {
                    $followedSlugs = array_merge(
                        $profileObject->followingEtablissements->pluck('slug')->toArray(),
                        $profileObject->followingUsers->pluck('slug')->toArray()
                    );
                }
            }

            // Base query for users with relationships
            $userQuery = User::select(
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
            // ->with([
            //     'followingUsers', 
            //     'followingEtablissements', 
            //     'followersUsers', 
            //     'followersEtablissements',
            //     'publications.tags' // Eager load publications with their tags
            // ])
            ;

            // Base query for establishments with relationships
            $etablissementQuery = Etablissement::select(
                'id', 
                'nom', 
                'logo', 
                'slug', 
                'is_verified', 
                'score', 
                'bio',
                DB::raw("'etablissement' as type")
            )
            // ->with([
            //     'followingUsers', 
            //     'followingEtablissements', 
            //     'followersUsers', 
            //     'followersEtablissements',
            //     'publications.tags' // Eager load publications with their tags
            // ])
            ;

            $combined = searchFilteredProfiles($userQuery, $etablissementQuery, $search, $profile, $followedSlugs);
            $paginatedProfiles = profilePreferences($combined, $profileObject);

            return response()->json([
                'status' => 201,
                'data' => ["profiles" => $paginatedProfiles],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500, 
                'error' => 'An error occurred: ' . $e->getMessage()
            ]);
        }
    }

    public function MarketplaceSearch(Request $request) {
        $search = $request->query('search');
        $profile = $request->query('profile');
        $searchName = $request->query('searchName');
        try{
            $publicationsMarketplace = Publication::select(
                'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 'image_default', 'prix', 'prixPromo', 'score'
                )
                ->where('status', 1)
                ->whereNotNull('image_default')
                ->where('is_marketplace', 1)
                ->withCount([
                    'publications_booster as booster' => function ($query) {
                        $query->where('date_fin', '>', Carbon::now());
                    }
                ])
                ->having('booster', 0)
                ->with([
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug,avatar',
                    'etablissement:id,nom,slug,logo',
                    'tags:id,name,slug',
                    'publications_booster.shopping_publications',
                    'publications_booster' => function($query) {
                        $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message')
                            ->where('date_fin', '>', Carbon::now())->orderBy('id', 'desc');
                    },
                    'statistiques' => function ($query) {
                        $query->selectRaw('
                            publication_id,
                            SUM(vues) as vues
                        ')->groupBy('publication_id');
                    },
                ]);

            $keywords = explode("-", $search);
            $paysIds = [];
            $villeIds = [];
            $regionIds = [];

            foreach ($keywords as $keyword) {
                // Check Pays (and store only IDs)
                $paysIds = array_merge(
                    $paysIds,
                    Pays::where('slug', $keyword)->pluck('id')->toArray()
                );

                // Check Ville (and store only IDs)
                $villeIds = array_merge(
                    $villeIds,
                    Ville::where('slug', $keyword)->pluck('id')->toArray()
                );

                // Check Region (and store only IDs)
                $regionIds = array_merge(
                    $regionIds,
                    Region::where('slug', $keyword)->pluck('id')->toArray()
                );
            }

            // Remove duplicates
            $paysIds = array_unique($paysIds);
            $villeIds = array_unique($villeIds);
            $regionIds = array_unique($regionIds);

            // $filtredPublications = searchFilteredPublications($publicationsMarketplace, $search);

            $filtredPublications = searchFilteredPublications(clone $publicationsMarketplace, $search, $searchName, $paysIds, $villeIds, $regionIds);

            $defaultRadiusKm = 100;
            $maxAttempts = 3; // Set a maximum number of attempts to prevent infinite loop
            $attempts = 0;

            while($filtredPublications->count() < 12 && $attempts < $maxAttempts) {
                $attempts++;
                
                if(count($regionIds) !== 0) {
                    $filtredPublications = searchFilteredPublications(clone $publicationsMarketplace, $search, $paysIds, $villeIds, $regionIds);
                } 
                if($filtredPublications->count() < 12 && count($villeIds) !== 0) {
                    $villeIdsArray = [];
                    foreach ($villeIds as $villeId) {
                        $nearbyLocations = getNearbyLocation($villeId, $defaultRadiusKm, "ville");
                        $villeIdsArray = array_unique(array_merge($villeIdsArray, $nearbyLocations));
                    }
                    $filtredPublications = searchFilteredPublications(clone $publicationsMarketplace, $search, $paysIds, $villeIdsArray, $regionIds, $villeIds);
                    break;
                }
                
                if($filtredPublications->count() >= 12) {
                    break;
                }
            }

            if($profile) {
                $profileObject = null;
                $user = User::where('slug', $profile)->first();
                $etablissement = Etablissement::where('slug', $profile)->first();
                if(User::where('slug', $profile)->first()) {
                    $profileObject = User::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
                } else if(Etablissement::where('slug', $profile)->first()) {
                    $profileObject = Etablissement::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
                }
            } else {
                $profileObject = null;
            }
            if($profileObject)
                $profileRecommendationsWithFilter = searchFilteredPublications($profileObject->recommendations(), $search, $paysIds, $villeIds, $regionIds);
            else $profileRecommendationsWithFilter = null;
            $orderedPublications = getOrderedPublicationsWithPreferencesTest($filtredPublications, $profileRecommendationsWithFilter);
            $publications = addBoosterToPublicationsToSearch($orderedPublications, $request, 12);
            
            return response()->json([
                'status' => 201, 
                'data' => ["publicationsMarketplace" => $publications],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()]);
        }
    }

    public function PublicationsVideos(Request $request) {
        $search = $request->query('search');
        $profile = $request->query('profile');
        $searchName = $request->query('searchName');
        try{
           $queryVideosPublications = Publication::select(
                'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 'image_default', 'prix', 'prixPromo', 'score', 'video_compresse', 'miniature'
                )
                ->where('status', 1)
                ->whereNotNull('image_default')
                ->whereNotNull('video_compresse')
                ->withCount([
                    'publications_booster as booster' => function ($query) {
                        $query->where('date_fin', '>', Carbon::now());
                    }
                ])
                ->having('booster', 0)
                ->with([
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug,avatar',
                    'etablissement:id,nom,slug,logo',
                    'tags:id,name,slug',
                    'publications_booster.shopping_publications',
                    'publications_booster' => function($query) {
                        $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message')
                            ->where('date_fin', '>', Carbon::now())->orderBy('id', 'desc');
                    },
                    'statistiques' => function ($query) {
                        $query->selectRaw('
                            publication_id,
                            SUM(vues) as vues
                        ')->groupBy('publication_id');
                    },
                ]);


            $keywords = explode("-", $search);
            $paysIds = [];
            $villeIds = [];
            $regionIds = [];

            foreach ($keywords as $keyword) {
                // Check Pays (and store only IDs)
                $paysIds = array_merge(
                    $paysIds,
                    Pays::where('slug', $keyword)->pluck('id')->toArray()
                );

                // Check Ville (and store only IDs)
                $villeIds = array_merge(
                    $villeIds,
                    Ville::where('slug', $keyword)->pluck('id')->toArray()
                );

                // Check Region (and store only IDs)
                $regionIds = array_merge(
                    $regionIds,
                    Region::where('slug', $keyword)->pluck('id')->toArray()
                );
            }

            // Remove duplicates
            $paysIds = array_unique($paysIds);
            $villeIds = array_unique($villeIds);
            $regionIds = array_unique($regionIds);

            // $filtredPublications = searchFilteredPublications($queryVideosPublications, $search);

            $filtredPublications = searchFilteredPublications(clone $queryVideosPublications, $search, $searchName, $paysIds, $villeIds, $regionIds);

            $defaultRadiusKm = 100;
            $maxAttempts = 3; // Set a maximum number of attempts to prevent infinite loop
            $attempts = 0;

            while($filtredPublications->count() < 12 && $attempts < $maxAttempts) {
                $attempts++;
                
                if(count($regionIds) !== 0) {
                    $filtredPublications = searchFilteredPublications(clone $queryVideosPublications, $search, $paysIds, $villeIds, $regionIds);
                }
                if($filtredPublications->count() < 12 && count($villeIds) !== 0) {
                    $villeIdsArray = [];
                    foreach ($villeIds as $villeId) {
                        $nearbyLocations = getNearbyLocation($villeId, $defaultRadiusKm, "ville");
                        $villeIdsArray = array_unique(array_merge($villeIdsArray, $nearbyLocations));
                    }
                    $filtredPublications = searchFilteredPublications(clone $queryVideosPublications, $search, $paysIds, $villeIdsArray, $regionIds, $villeIds);
                    break;
                }
                
                if($filtredPublications->count() >= 12) {
                    break;
                }
            }

            if($profile) {
                $profileObject = null;
                $user = User::where('slug', $profile)->first();
                $etablissement = Etablissement::where('slug', $profile)->first();
                if(User::where('slug', $profile)->first()) {
                    $profileObject = User::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
                } else if(Etablissement::where('slug', $profile)->first()) {
                    $profileObject = Etablissement::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
                }
            } else {
                $profileObject = null;
            }
            
            if($profileObject)
                $profileRecommendationsWithFilter = searchFilteredPublications($profileObject->recommendations(), $search, $paysIds, $villeIds, $regionIds);
            else $profileRecommendationsWithFilter = null;
            $orderedVideosPublications = getOrderedPublicationsWithPreferencesTest($filtredPublications, $profileRecommendationsWithFilter);

            // $orderedPublications = getOrderedPublicationsWithPreferencesTest($filtredPublications, null);
            $videosPublications = addBoosterToPublicationsToSearch($orderedVideosPublications, $request, 12, true, true);

            // $topPublications = $queryTopPublications->orderByDesc('score')->paginate(12);
            return response()->json([
                'status' => 201, 
                'data' => ["topPublications" => $videosPublications],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()]);
        }
    }

    public function TagsSearch(Request $request) {
        $search = $request->query('search');
        // $profile = $request->query('profile');
        try{
            // $queryTags = Tags::select('id', 'name', 'slug')->where('status', 1);
            // $tags = $queryTags->orderByDesc('compteur_publications')->limit(12)->get();


            // Option 1: Get all co-occurring tags
            $tags = Tags::select('id', 'name', 'slug')
            ->whereHas('publications', function($query) use ($search) {
                $query->whereHas('tags', function($q) use ($search) {
                    $q->where('name', $search)
                    ->orWhere('slug', $search);
                });
            })
            ->where(function($query) use ($search) {
                $query->where('name', '!=', $search)
                    ->where('slug', '!=', $search);
            })
            ->orderByDesc('compteur_publications')
            ->limit(15)
            ->get()
            ->shuffle()
            ->take(12);

            if(count($tags) == 0) {
                $tags = Tags::select('id', 'name', 'slug')
                    ->where(function($query) use ($search) {
                        $query->where('name', '!=', $search)
                            ->where('slug', '!=', $search);
                    })
                    ->orderByDesc('compteur_publications')
                    ->limit(24)
                    ->get()
                    ->shuffle()
                    ->take(12);
            }

            return response()->json([
                'status' => 201, 
                'data' => ["tagsSearch" => $tags],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()]);
        }
    }

    public function TagsSearchHistoriques(Request $request) {
        $search = $request->query('search');
        $profile = $request->query('profile');

        // Get profile data if needed
        $profileObject = null;
        if ($profile) {
            $profileObject = User::where('slug', $profile)
                ->with(['followingUsers', 'followingEtablissements'])
                ->first();
            
            if (!$profileObject) {
                $profileObject = Etablissement::where('slug', $profile)
                    ->with(['followingUsers', 'followingEtablissements'])
                    ->first();
            }
        }
        try{
            if($profileObject && (!$search || $search == "")) {
                    $tags = $profileObject->tags_filters()
                    ->select('tags_flink.id', 'tags_flink.name', 'tags_flink.slug')
                    ->whereNot('tags_flink.slug', 'marketplace')
                    ->orderBy('pivot_updated_at', 'desc')
                    ->get()
                    ->unique('id') // First get all unique tags
                    ->take(12)->values();

            } else if($search) {
                    $tags = Tags::select('id', 'name', 'slug')
                    ->where('status', 1)
                    ->where(function($query) use ($search) {
                        $query->where('name', 'like', '%' . $search . '%')
                            ->orWhere('slug', 'like', '%' . $search . '%');
                    })
                    ->orderBy('compteur_publications', 'desc')
                    ->limit(12)
                    ->get();
            } else {
                $tags = Tags::select('id', 'name', 'slug')
                    ->where('status', 1)
                    ->orderBy('compteur_publications', 'desc')
                    ->limit(12)
                    ->get();
            }

            return response()->json([
                'status' => 201, 
                'data' => ["tagsSearchHistorique" => $tags],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()]);
        }
    }

    public function DeleteTagsSearch(Request $request) {
        $search = $request->query('search');
        $profile = $request->query('profile');

        // Get profile data if needed
        $profileObject = null;
        if ($profile) {
            $profileObject = User::where('slug', $profile)->first();
            
            if (!$profileObject) {
                $profileObject = Etablissement::where('slug', $profile)->first();
            }
        }
        try{
            if(!$profileObject) {
                return response()->json([
                    'status' => 404, 
                    'message' => "Profile not found",
                ]);
            }
            
            $tag = $profileObject->tags_filters()->where('slug', $search)->first();

            if($tag) $profileObject->tags_filters()->detach($tag->id);

            return response()->json([
                'status' => 201, 
                'message' => "Tag deleted successfully",
            ]);

        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()]);
        }
    }
}
