<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestUser;
use App\Models\Publication;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Etablissement;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;
use App\Models\CategorieFlink;
use App\Models\Pays;
use App\Models\Ville;
use App\Models\Region;
use App\Models\Tags;
use Illuminate\Support\Str;
use App\Models\Statistique;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Models\PublicationBooster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\Redis;

class TestController extends Controller
{
    // public function marketplacePublications(Request $request){
    //     $search = $request->get('search');
    //     $pays = $request->get('pays');
    //     $ville = $request->get('ville');
    //     $region = $request->get('region');
    //     $profile = $request->get('profile');

    //     // Add latitude and longitude to pays and ville and region
    //     $paysId = null;
    //     $villeId = null;
    //     $regionId = null;
    //     if($pays) {
    //         $selectedPays = Pays::where('slug', $pays)->first();
    //         if($selectedPays) {
    //             $paysId = $selectedPays->id;
    //             $selectedPays->compteur_search += 1;
    //             $selectedPays->save();
    //             if(!$selectedPays->latitude && !$selectedPays->longitude) {
    //                 $data = setLatitudeLongitude($selectedPays->name, 'country');
    //                 if($data) {
    //                     $selectedPays->latitude = $data[0]['lat'];
    //                     $selectedPays->longitude = $data[0]['lon'];
    //                     $selectedPays->save();
    //                 }
    //             }
    //         }
    //     }
    //     if($ville && $selectedPays) {
    //         $selectedVille = Ville::where('slug', $ville)->first();
    //         if($selectedVille) {
    //             $villeId = $selectedVille->id;
    //             $selectedVille->compteur_search += 1;
    //             $selectedVille->save();
    //             if(!$selectedVille->latitude && !$selectedVille->longitude) {
    //                 $data = setLatitudeLongitude($selectedVille->name, 'city');
    //                 if($data) {
    //                     $selectedVille->latitude = $data[0]['lat'];
    //                     $selectedVille->longitude = $data[0]['lon'];
    //                     $selectedVille->save();
    //                 }
    //             }
    //         }
            
    //         if($region && $selectedVille) {
    //             $selectedRegion = Region::where('slug', $region)->first();
    //             if($selectedRegion) {
    //                 $regionId = $selectedRegion->id;
    //                 $selectedRegion->compteur_search += 1;
    //                 $selectedRegion->save();
    //                 if(!$selectedRegion->latitude && !$selectedRegion->longitude) {
    //                     $data = setLatitudeLongitude($selectedRegion->name . "," . $selectedVille->name, 'suburb');
    //                     if($data) {
    //                         $selectedRegion->latitude = $data[0]['lat'];
    //                         $selectedRegion->longitude = $data[0]['lon'];
    //                         $selectedRegion->save();
    //                     }
    //                 }
    //             }
                
    //         }
    //     }
    //     $searchTagName = null;
    //     if($search) {
    //         $tag = Tags::where('slug', $search)->first();
    //         if($tag) {
    //             $searchTagName = $tag->name;
    //             $tag->compteur_search += 1;
    //             $tag->save();
    //             if($profile) {
    //                 $user = User::where('slug', $profile)->first();
    //                 $etablissement = Etablissement::where('slug', $profile)->first();
    //                 if($user) {
    //                     // Check if the tag is already assigned to the user
    //                     $exist = $user->tags_filters()->where('tag_id', $tag->id)->where('pays_id', $paysId)->where('ville_id', $villeId)->where('region_id', $regionId)->first();
                        
    //                     if ($exist) {
    //                         // Increment the compteur value
    //                         $exist->pivot->increment('compteur');
    //                     } else {
    //                         // Attach the tag
    //                         $user->tags_filters()->attach($tag->id, ['pays_id' => $paysId, 'ville_id' => $villeId, 'region_id' => $regionId]);
    //                     }
    //                 } else if($etablissement) {
    //                      // Check if the tag is already assigned to the etablissement
    //                      $exist = $etablissement->tags_filters()->where('tag_id', $tag->id)->where('pays_id', $paysId)->where('ville_id', $villeId)->where('region_id', $regionId)->first();
    //                      if ($exist) {
    //                          // Increment the compteur value
    //                          $exist->pivot->increment('compteur');
    //                      } else {
    //                          // Attach the tag
    //                          $etablissement->tags_filters()->attach($tag->id, ['pays_id' => $paysId, 'ville_id' => $villeId, 'region_id' => $regionId]);
    //                      }
    //                 }
    //             }
    //         } else {
    //             // Generate a possible name from the search string
    //             $generatedName = Str::of($search)->replace('-', ' ')->title();
    //             // Check if a tag exists with the same name (to avoid conflicts)
    //             $existingTagByName = Tags::where('name', $generatedName)->first();
    //             if(!$existingTagByName) {
    //                 $tag = new Tags();
    //                 $tag->name = $generatedName;
    //                 $searchTagName = $generatedName;
    //                 $tag->slug = $search;
    //                 $tag->save();
    //             }
    //         }
    //     }

    //     try {
    //         $publicationsBooster = PublicationBooster::with('statistiques')
    //         ->where(function($query) {
    //             $query->whereNull('last_display')
    //                 ->orWhereRaw('TIMESTAMPDIFF(SECOND, last_display, NOW()) > 60');
    //         })
    //         // ->orderBy('last_display', 'ASC')
    //         ->get();
    //         return $publicationsBooster;



    //         // $orderIndex = rand(0, 1);
    //         // $orederArray = ["score", "created_at"];
    //         // $publications = Publication::select(
    //         //     'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 'image_default', 'prix', 'prixPromo', 'score'
    //         //     )
    //         //     ->where('status', 1)
    //         //     ->whereNotNull('image_default')
    //         //     ->where('is_marketplace', 1)
    //         //     ->withCount([
    //         //         'publications_booster as booster' => function ($query) {
    //         //             $query->where('date_fin', '>', Carbon::now());
    //         //         }
    //         //     ])
    //         //     ->with([
    //         //         'ville:id,slug,name,pays_id',
    //         //         'region:id,slug,name,ville_id',
    //         //         'user:id,first_name,last_name,slug',
    //         //         'etablissement:id,nom,slug',
    //         //         'tags:id,name,slug',
    //         //         'statistiques',
    //         //         'publications_booster.shopping_publications',
    //         //         'publications_booster' => function($query) {
    //         //             $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message')
    //         //                 ->where('date_fin', '>', Carbon::now())->orderBy('id', 'desc');
    //         //         },
    //         //         'recommendations_users', 'recommendations_etabs'
    //         //     ]);
            
    //             $perPage = 24;
    //             $regularPerPage = 20; // 20 regular + 4 boosted = 24 (5:1 ratio)
    //             $boostedPerPage = 4;
            
    //             // First get boosted publications
    //             $boostedQuery = Publication::select(
    //                     'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 
    //                     'image_default', 'prix', 'prixPromo', 'score'
    //                 )
    //                 ->where('status', 1)
    //                 ->whereNotNull('image_default')
    //                 ->where('is_marketplace', 1)
    //                 ->withCount([
    //                     'publications_booster as booster' => function ($query) {
    //                         $query->where('date_fin', '>', now());
    //                     }
    //                 ])
    //                 ->having('booster', '>', 0)
    //                 ->with([
    //                     'ville:id,slug,name,pays_id',
    //                     'region:id,slug,name,ville_id',
    //                     'user:id,first_name,last_name,slug',
    //                     'etablissement:id,nom,slug',
    //                     'tags:id,name,slug',
    //                     'publications_booster' => function($query) {
    //                         $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message')
    //                             ->where('date_fin', '>', now())->orderBy('id', 'desc');
    //                     },
    //                 ]);
            
    //             // Then get regular publications
    //             $regularQuery = Publication::select(
    //                     'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 
    //                     'image_default', 'prix', 'prixPromo', 'score'
    //                 )
    //                 ->where('status', 1)
    //                 ->whereNotNull('image_default')
    //                 ->where('is_marketplace', 1)
    //                 ->withCount([
    //                     'publications_booster as booster' => function ($query) {
    //                         $query->where('date_fin', '>', now());
    //                     }
    //                 ])
    //                 ->having('booster', 0)
    //                 ->with([
    //                     'ville:id,slug,name,pays_id',
    //                     'region:id,slug,name,ville_id',
    //                     'user:id,first_name,last_name,slug',
    //                     'etablissement:id,nom,slug',
    //                     'tags:id,name,slug'
    //                 ]);
            
    //             // Get paginated results
    //             $boosted = $boostedQuery->paginate($boostedPerPage, ['*'], 'boosted_page', $request->boosted_page ?? 1);
    //             $regular = $regularQuery->paginate($regularPerPage, ['*'], 'regular_page', $request->regular_page ?? 1);
            
    //             // Calculate the actual ratio based on available boosted publications
    //             $actualBoostedCount = $boosted->count();
    //             $actualRegularCount = $regular->count();
            
    //             // If we don't have enough boosted, adjust the regular count to maintain total
    //             if ($actualBoostedCount < $boostedPerPage) {
    //                 $regular = $regularQuery->paginate(
    //                     $perPage - $actualBoostedCount, 
    //                     ['*'], 
    //                     'regular_page', 
    //                     $request->regular_page ?? 1
    //                 );
    //             }
            
    //             // Merge the results in 5:1 ratio
    //             $mixedPublications = collect();
    //             $boostedItems = $boosted->items();
    //             $regularItems = $regular->items();
            
    //             $bIndex = 0;
    //             $rIndex = 0;
    //             $totalItems = min($perPage, $actualRegularCount + $actualBoostedCount);
            
    //             while ($mixedPublications->count() < $totalItems) {
    //                 // Add up to 5 regular publications
    //                 for ($i = 0; $i < 5 && $rIndex < count($regularItems) && $mixedPublications->count() < $totalItems; $i++) {
    //                     $mixedPublications->push($regularItems[$rIndex++]);
    //                 }
            
    //                 // Add 1 boosted publication if available
    //                 if ($bIndex < count($boostedItems) && $mixedPublications->count() < $totalItems) {
    //                     $mixedPublications->push($boostedItems[$bIndex++]);
    //                 } elseif ($rIndex < count($regularItems) && $mixedPublications->count() < $totalItems) {
    //                     // If no more boosted, continue with regular
    //                     $mixedPublications->push($regularItems[$rIndex++]);
    //                 }
    //             }
            
    //             // Create a custom paginator
    //             $paginatedResults = new LengthAwarePaginator(
    //                 $mixedPublications,
    //                 $regular->total() + $boosted->total(),
    //                 $perPage,
    //                 $request->page ?? 1,
    //                 ['path' => $request->url(), 'query' => $request->query()]
    //             );
            
    //         return $paginatedResults;

    //         if($pays) {
    //             $selectedPays = Pays::where('slug', $pays)->first();
    //             if($selectedPays) $publications = $publications->where('pays_id', $selectedPays->id);
    //         }
    //         if($ville && $pays && $selectedPays) {
    //             $selectedVille = Ville::where('pays_id', $selectedPays->id)->where('slug', $ville)->first();
    //             if($selectedVille) $publications = $publications->where('ville_id', $selectedVille->id);
    //         }
    //         if($region && $ville && $selectedVille) {
    //             $selectedRegion = Region::where('ville_id', $selectedVille->id)->where('slug', $region)->first();
    //             if($selectedRegion) $publications = $publications->where('region_id', $selectedRegion->id);
    //         }


    //         $keywords = explode('-', $search);
            
    //         if (count($keywords) > 1) {
    //             usort($keywords, function ($a, $b) {
    //                 return (mb_strlen($a) < 3) - (mb_strlen($b) < 3);
    //             });
    //         }

    //         // Add the original search term at the beginning
    //         if (!empty($keywords)) {
    //             array_unshift($keywords, $search);
    //             $keywords = array_unique($keywords); // Remove duplicates
    //         }

    //         if($profile) {
    //             $profileObject = null;
    //             $user = User::where('slug', $profile)->first();
    //             $etablissement = Etablissement::where('slug', $profile)->first();
    //             if(User::where('slug', $profile)->first()) {
    //                 $profileObject = User::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
    //             } else if(Etablissement::where('slug', $profile)->first()) {
    //                 $profileObject = Etablissement::where('slug', $profile)->with('tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations')->first();
    //             }
    //         } else {
    //             $profileObject = null;
    //         }

    //         // Process search if exists
    //         if ($search && $search !== "marketplace") {
    //             $keywords = array_unique(
    //                 array_merge(
    //                     [$search],
    //                     explode('-', $search)
    //                 )
    //             );
                
    //             // Sort keywords by length (longest first)
    //             usort($keywords, function ($a, $b) {
    //                 return strlen($b) - strlen($a);
    //             });
                
    //             $tagIds = collect();
                
    //             foreach ($keywords as $keyword) {
    //                 $foundTags = Tags::where('status', 1)
    //                     ->where(function($query) use ($keyword) {
    //                         $query->where('slug', 'like', '%' . $keyword . '%')
    //                             ->orWhere('slug', 'like', '%' . Str::singular($keyword) . '%');
    //                     })
    //                     ->pluck('id');
                        
    //                 $tagIds = $tagIds->merge($foundTags);
    //             }
                
    //             $tags = $tagIds->unique()->values()->all();
                
    //             if (!empty($tags)) {
    //                 $publications->whereHas('tags', function($query) use ($tags) {
    //                     $query->whereIn('tags_flink.id', $tags);
    //                 });
    //             }
    //         }

    //         $orderedPublications = getOrderedPublicationsWithPreferencesTest($publications, $profileObject);
    //         $publications = $orderedPublications->paginate(24);
            

    //         // // Paginate the results manually
    //         // $perPage = 24;
    //         // $page = request()->input('page', 1);
    //         // $total = $orderedPublications->count();
    //         // $paginatedItems = $orderedPublications->slice(($page - 1) * $perPage, $perPage)->values();
    
    //         // // Create a paginator instance
    //         // $publications = new LengthAwarePaginator(
    //         //     $paginatedItems, // Current page items
    //         //     $total, // Total items
    //         //     $perPage, // Items per page
    //         //     $page, // Current page
    //         //     ['path' => request()->url(), 'query' => request()->query()] // Append query parameters
    //         // );

    //         // Increment the views (vues) for each publication
    //         foreach ($publications as $publication) {
    //             $this->storeRedis($publication->id, 'vues');
    //         }
    //             // $totalPages = $publications->lastPage();
    //             // $currentPage = $publications->currentPage();
    //             // $paginationLinks = [];
                
    //             // $range = 2; // Number of pages before and after the active page
    //             // $pagesToShow = [];
                
    //             // // Add first and last pages
    //             // $pagesToShow[] = 1;
    //             // $pagesToShow[] = $totalPages;
                
    //             // // Add pages before and after active page
    //             // for ($i = max(1, $currentPage - $range); $i <= min($totalPages, $currentPage + $range); $i++) {
    //             // }
                
    //             // // Remove duplicates and sort
    //             // $pagesToShow = array_values(array_unique($pagesToShow));
    //             // sort($pagesToShow);
                
    //             // // Build pagination links
    //             // $prevPage = null;
    //             // foreach ($pagesToShow as $page) {
    //             //     if ($prevPage !== null && $page > $prevPage + 1) {
    //             //         $paginationLinks[] = ['url' => null, 'label' => '...', 'active' => false]; // Add ellipsis
    //             //     }
                    
    //             //     $paginationLinks[] = [
    //             //         'url' => $publications->url($page),
    //             //         'label' => (string) $page,
    //             //         'active' => $page === $currentPage
    //             //     ];
                
    //             //     $prevPage = $page;
    //             // }
                
    //             // // Previous link (disabled if on the first page)
    //             // if ($publications->previousPageUrl()) {
    //             //     array_unshift($paginationLinks, [
    //             //         'url' => $publications->previousPageUrl(),
    //             //         'label' => '&laquo; Previous',
    //             //         'active' => false
    //             //     ]);
    //             // } else {
    //             //     array_unshift($paginationLinks, [
    //             //         'url' => null,
    //             //         'label' => '&laquo; Previous',
    //             //         'active' => true, // Disabled (active) on first page
    //             //     ]);
    //             // }
                
    //             // // Next link (always exist, disabled if on the last page)
    //             // if ($publications->nextPageUrl()) {
    //             //     $paginationLinks[] = [
    //             //         'url' => $publications->nextPageUrl(),
    //             //         'label' => 'Next &raquo;',
    //             //         'active' => false
    //             //     ];
    //             // } else {
    //             //     $paginationLinks[] = [
    //             //         'url' => null,
    //             //         'label' => 'Next &raquo;',
    //             //         'active' => true, // Disabled (active) on last page
    //             //     ];
    //             // }
                
            
    //         return response()->json([
    //             'status' => 201,
    //             'data' => [
    //                 "publication"=>$publications,
    //                 ]
    //             ]);
        
    //     } catch (\Exception $e) {
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    private function setTitle($tag, $type, $region, $ville, $pays) {
        if($type == "pourToi") {
            $tagName = $tag && $tag != "Marketplace" ? " de ".$tag." " : " ";
            $titles = [
                "Top sélection pour toi",
                "Top tendance pour toi",
                "Spécial Offre pour toi"
            ];
            $randomNumber = random_int(0, count($titles)-1);
            return $titles[$randomNumber];
        } else if($type == "tagMaroc") {
            $localName = "";
            if($region && isset($region->name)) {
                $localName = "à {$region->name}";
            } elseif($ville && isset($ville->name)) {
                $localName = "à {$ville->name}";
            } elseif($pays && isset($pays->name)) {
                $localName = "au {$pays->name}";
            } else {
                $localName = ""; // fallback if all are null
            }
            
            $titles = [
                "L'offre immanquable du moment {$localName}", 
                "La sélection tendance {$localName}", 
                "Exclusivité disponible maintenant {$localName}", 
                "L'affaire à ne pas rater {$localName}",
            ];
            $randomNumber = random_int(0, count($titles)-1);
            return $titles[$randomNumber];
        }
        
        return ""; // default return if type doesn't match
    }

    private function storeRedis($publicationId, $action) {

        $date = now()->toDateString();

        $key = "stat:{$publicationId}:{$date}:{$action}";
        Redis::incr($key);
    }

    private static function generateSlug($firstname) {
        $name = $firstname;
        $slug = Str::slug($name, '_');  // Generate slug from names

        // Ensure the slug is unique by checking the database
        $slugBase = $slug;
        $count = 1;

        while (User::where('slug', $slug)->exists() || Etablissement::where('slug', $slug)->exists()) {
            $slug = $slugBase . '_' . $count;  // Append a number to make it unique
            $count++;
        }

        return $slug;
    }

    private function tagsSimilaire($tagsIds) {
        $publications = Publication::where('status', 1)
        ->whereHas('tags', function($query) use ($tagsIds) { 
            $query->whereIn('tag_id', $tagsIds);
        })->get();
    
        $tags = [];
        foreach ($publications as $publication) {
            foreach ($publication->tags as $tag) {
                $tags[] = $tag->id;
            }
        }
        return array_values(array_unique($tags));
    }

    public function getMixedPublications($staticPublications, $exludePublicationIds) {

        // Calculate how many more we need to reach 32
        $remaining = 32 - $staticPublications->count();

        $globalPublications = collect();

        // If we need more publications, fetch global ones
        if ($remaining > 0) {
            $globalPublications = Publication::where('status', 1)->where('is_marketplace', 1)->whereNotIn('id', $staticPublications->pluck('id'))
            ->whereNotIn('id', $exludePublicationIds)
                ->with([
                    'pays:id,slug,name',
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'tags:id,name,slug'
                ])->orderByDesc('score')
                ->limit($remaining)
                ->get();
        }

        // Merge both collections
        $mixedPublications = $staticPublications->merge($globalPublications);

        // Shuffle and return 8 random publications
        return $mixedPublications->shuffle()->take(8);
    }
}
