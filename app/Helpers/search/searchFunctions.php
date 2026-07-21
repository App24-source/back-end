<?php
use App\Models\Pays;
use App\Models\Ville;
use App\Models\Region;
use App\Models\Tags;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Publication;
use App\Models\PublicationBooster;
use App\Models\User;
use App\Models\Etablissement;

// if (!function_exists('searchFiltersPublications')) {
//     function searchFiltersPublications($query, $search) {
//         $keywords = explode("-", $search);
//         $paysIds = [];
//         $villeIds = [];
//         $regionIds = [];

//         foreach ($keywords as $keyword) {
//             // Check Pays (and store only IDs)
//             $paysIds = array_merge(
//                 $paysIds,
//                 Pays::where('slug', $keyword)->pluck('id')->toArray()
//             );

//             // Check Ville (and store only IDs)
//             $villeIds = array_merge(
//                 $villeIds,
//                 Ville::where('slug', $keyword)->pluck('id')->toArray()
//             );

//             // Check Region (and store only IDs)
//             $regionIds = array_merge(
//                 $regionIds,
//                 Region::where('slug', $keyword)->pluck('id')->toArray()
//             );
//         }

//         // Remove duplicates (optional)
//         $paysIds = array_unique($paysIds);
//         $villeIds = array_unique($villeIds);
//         $regionIds = array_unique($regionIds);

//         return ["pays"=> $paysIds, "ville"=> $villeIds, "region"=> $regionIds];
//         return $keywords;
//         return $query->get();
//     }
// }

if (!function_exists('searchFilteredPublications')) {
    function searchFilteredPublications($query, $search) {
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

        // Remove duplicates (optional)
        $paysIds = array_unique($paysIds);
        $villeIds = array_unique($villeIds);
        $regionIds = array_unique($regionIds);

        $orderIndex = rand(0, 1);
        $orderArray = ["score", "created_at"];

        $query->where('status', 1);
        
        if(count($regionIds) !== 0) {
            $query = $query->whereIn('region_id', $regionIds);
        } else if(count($villeIds) !== 0) {
            $query = $query->whereIn('ville_id', $villeIds);
        } else if(count($paysIds) !== 0) {
            $query = $query->whereIn('pays_id', $paysIds);
        }

        if ($search && $search !== "marketplace") {
            $originalSearch = $search;
            $searchVariations = [];
            

            // 1. Find exact tag matches
            $exactTagIds = Tags::where('slug', $originalSearch)
                            ->pluck('id')
                            ->toArray();

            // 2. find tags similaire of search
            $tagsSimilaires = Tags::select('id', 'name', 'slug')
            ->where('status', 1)
            ->whereHas('publications', function($query) use ($originalSearch) {
                $query->whereHas('tags', function($q) use ($originalSearch) {
                    $q->where('name', $originalSearch)
                    ->orWhere('slug', $originalSearch);
                });
            })
            ->where(function($query) use ($originalSearch) {
                $query->where('name', '!=', $originalSearch)
                    ->where('slug', '!=', $originalSearch);
            })
            ->orderByDesc('compteur_publications')
            ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
            ->get()
            ->pluck('id')
            ->toArray();


            // 2. Find partial tag matches (keywords)
            $keywords = array_filter(
                explode('-', $originalSearch),
                fn($word) => !in_array(strtolower($word), ['a', 'au', 'la', 'le', 'des', 'et', 'etc', 'de', 'du', 'en', '&']) 
                        && strlen($word) > 1
            );

            foreach($keywords as $keyword) {
                // Generate variations by removing 1-3 letters
                for ($i = 1; $i <= min(3, strlen($keyword) - 1); $i++) {
                    $searchVariations[] = substr($keyword, 0, -$i);
                }
            }
            

            $partialTagIds = [];
            if (!empty($keywords)) {
                $partialTagIds = Tags::where(function($q) use ($keywords) {
                        foreach ($keywords as $keyword) {
                            $q->orWhere('slug', 'like', '%'.$keyword.'%')
                            ->orWhere('slug', 'like', '%'.Str::singular($keyword).'%');
                        }
                    })
                    ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
                    ->when(!empty($tagsSimilaires), fn($q) => $q->whereNotIn('id', $tagsSimilaires))
                    ->pluck('id')
                    ->toArray();
            }

            // 3. Find tags after removing letters (truncated versions)
            $truncatedTagIds = Tags::where(function($query) use ($searchVariations) {
                        foreach ($searchVariations as $variation) {
                            $query->orWhere('slug', 'like', "%{$variation}%");
                        }
                    })
                    ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
                    ->when(!empty($tagsSimilaires), fn($q) => $q->whereNotIn('id', $tagsSimilaires))
                    ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
                    ->pluck('id')
                    ->toArray();

                            // return $truncatedTagIds;
            // Check if there are any matching tags at all
            $hasAnyTagMatches = !empty($exactTagIds) || !empty($partialTagIds) || !empty($truncatedTagIds) || !empty($tagsSimilaires);

            // Return empty if no tag matches found
            if (!$hasAnyTagMatches) {
                return $query->whereRaw('1 = 0');
            }

            // Build the final query
            $query->where(function($q) use ($exactTagIds, $partialTagIds, $truncatedTagIds) {
                if (!empty($exactTagIds)) {
                    $q->whereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $exactTagIds));
                }
                
                if (!empty($partialTagIds)) {
                    $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $partialTagIds));
                }

                if (!empty($tagsSimilaires)) {
                    $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $tagsSimilaires));
                }
                
                if (!empty($truncatedTagIds)) {
                    $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $truncatedTagIds));
                }
            });

            // Add scoring
            $query->select([
                'publications_flink.*',
                \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
                        WHERE publications_flink.id = publications_booster_flink.publication_id 
                        AND date_fin > NOW()) as booster'),
                \DB::raw("CASE 
                    WHEN EXISTS (
                        SELECT 1 FROM publications_tags_flink 
                        WHERE publications_tags_flink.publication_id = publications_flink.id 
                        AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
                    ) THEN 4 
                    WHEN EXISTS (
                        SELECT 1 FROM publications_tags_flink 
                        WHERE publications_tags_flink.publication_id = publications_flink.id 
                        AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
                    ) THEN 3
                    WHEN EXISTS (
                        SELECT 1 FROM publications_tags_flink 
                        WHERE publications_tags_flink.publication_id = publications_flink.id 
                        AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
                    ) THEN 2
                    WHEN EXISTS (
                        SELECT 1 FROM publications_tags_flink 
                        WHERE publications_tags_flink.publication_id = publications_flink.id 
                        AND publications_tags_flink.tag_id IN (".implode(',', $truncatedTagIds ?: [0]).")
                    ) THEN 1
                    ELSE 0 END as match_priority")
            ])
            // ->having('match_priority', '>', 0) // Only publications with at least one matching tag
            ->orderByDesc('match_priority')
            ->orderByDesc("publications_flink.$orderArray[$orderIndex]");
        }
        
        return $query;
    }

    // function searchFilteredPublications($query, $search, $searchName, $paysIds = [], $villeIds = [], $regionIds = [], $originVilleIds = []) {

    //     $orderIndex = rand(0, 1);
    //     $orderArray = ["score", "created_at"];

    //     $query->where('status', 1);
        
    //     if(count($regionIds) !== 0) {
    //         $query = $query->whereIn('region_id', $regionIds);
    //     } else if(count($villeIds) !== 0) {
    //         $query = $query->whereIn('ville_id', $villeIds);
    //     } else if(count($paysIds) !== 0) {
    //         $query = $query->whereIn('pays_id', $paysIds);
    //     }

    //     if (!$search || $search === "marketplace") {
    //         return $query->orderByDesc("publications_flink.$orderArray[$orderIndex]");
    //     }


    //     $villesIds_50km = [];
    //     $villesIds_100km = [];
    //     if (count($originVilleIds) > 0) {    
    //         foreach ($originVilleIds as $villeId) {
    //             // Get nearby villes for current villeId
    //             $current50km = array_diff(getNearbyLocation($villeId, 50, "ville"), [$villeId]);
    //             $current100km = array_diff(getNearbyLocation($villeId, 100, "ville"), $current50km);
                
    //             // Merge with existing results (avoiding duplicates)
    //             $villesIds_50km = array_unique(array_merge($villesIds_50km, $current50km));
    //             $villesIds_100km = array_unique(array_merge($villesIds_100km, $current100km));
    //         }
            
    //         // Remove any villes that appear in 50km from 100km list
    //         $villesIds_100km = array_diff($villesIds_100km, $villesIds_50km);
    //     }

    //     // search for arabic tag
    //     // if($searchName && (bool)isArabic($searchName)) {
    //     //     // 1. Find exact tag matches
    //     //     $exactTagIds = Tags::where('name', $searchName)
    //     //         ->pluck('id')
    //     //         ->toArray();
    //     //     $exactTagNames = Tags::where('name', $searchName)
    //     //         ->pluck('slug')
    //     //         ->toArray();

                
    //     //     $keywords = explode(" ", $searchName);

    //     //     // 2. Find partial tags
    //     //     $partialTagIds = Tags::where(function($query) use ($keywords) {
    //     //         foreach ($keywords as $keyword) {
    //     //             $query->orWhere('name', 'like', '%' . $keyword . '%');
    //     //         }
    //     //     })
    //     //     ->pluck('id')
    //     //     ->toArray();
    //     //     $partialTagSlugs = Tags::where(function($query) use ($keywords) {
    //     //         foreach ($keywords as $keyword) {
    //     //             $query->orWhere('name', 'like', '%' . $keyword . '%');
    //     //         }
    //     //     })
    //     //     ->pluck('slug')
    //     //     ->toArray();


    //     //     // 3. Find similar tags
    //     //     $tagsSimilaires = Tags::whereHas('publications', fn($q) => $q->whereHas('tags', fn($q) => $q->where('name', $searchName)))
    //     //         ->where(fn($q) => $q->where('name', '!=', $searchName))
    //     //         ->orderByDesc('compteur_publications')
    //     //         ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //     //         ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
    //     //         ->get()
    //     //         ->pluck('id')
    //     //         ->toArray();
    //     //     $tagsSimilaireSlugs = Tags::whereHas('publications', fn($q) => $q->whereHas('tags', fn($q) => $q->where('name', $searchName)))
    //     //         ->where(fn($q) => $q->where('name', '!=', $searchName))
    //     //         ->orderByDesc('compteur_publications')
    //     //         ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //     //         ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
    //     //         ->get()
    //     //         ->pluck('slug')
    //     //         ->toArray();

    //     //     $filteredQuery = clone $query;
            
    //     //     $filteredQuery->where(function($q) use ($exactTagIds, $partialTagIds, $tagsSimilaires) {
    //     //         if (!empty($exactTagIds)) {
    //     //             $q->whereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $exactTagIds));
    //     //         }
                
    //     //         if (!empty($partialTagIds)) {
    //     //             $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $partialTagIds));
    //     //         }

    //     //         if (!empty($tagsSimilaires)) {
    //     //             $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $tagsSimilaires));
    //     //         }
    //     //     });

    //     //     // // Build the select statement with conditional logic for partial matches
    //     //     // $selectFields = [
    //     //     //     'publications_flink.*',
    //     //     //     \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
    //     //     //             WHERE publications_flink.id = publications_booster_flink.publication_id 
    //     //     //             AND date_fin > NOW()) as booster'),
    //     //     //     \DB::raw("CASE 
    //     //     //         WHEN EXISTS (
    //     //     //             SELECT 1 FROM publications_tags_flink 
    //     //     //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //     //             AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
    //     //     //         ) THEN 6
    //     //     //         WHEN EXISTS (
    //     //     //             SELECT 1 FROM publications_tags_flink 
    //     //     //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //     //             AND publications_tags_flink.tag_id IN (".implode(',', $exactTagSlugs ?: [0]).")
    //     //     //         ) THEN 5 
                     
    //     //     //         WHEN EXISTS (
    //     //     //             SELECT 1 FROM publications_tags_flink 
    //     //     //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //     //             AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
    //     //     //         ) THEN 4
    //     //     //         WHEN EXISTS (
    //     //     //             SELECT 1 FROM publications_tags_flink 
    //     //     //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //     //             AND publications_tags_flink.tag_id IN (".implode(',', $partialTagSlugs ?: [0]).")
    //     //     //         ) THEN 3
                    
    //     //     //         WHEN EXISTS (
    //     //     //             SELECT 1 FROM publications_tags_flink 
    //     //     //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //     //             AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
    //     //     //         ) THEN 2
    //     //     //         WHEN EXISTS (
    //     //     //             SELECT 1 FROM publications_tags_flink 
    //     //     //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //     //             AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaireSlugs ?: [0]).")
    //     //     //         ) THEN 1
    //     //     //         ELSE 0 END as match_priority")
    //     //     // ];

    //     //     $selectFields = [
    //     //         'publications_flink.*',
    //     //         \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
    //     //                 WHERE publications_flink.id = publications_booster_flink.publication_id 
    //     //                 AND date_fin > NOW()) as booster'),
    //     //         \DB::raw("CASE 
    //     //             WHEN EXISTS (
    //     //                 SELECT 1 FROM publications_tags_flink 
    //     //                 WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //                 AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
    //     //             ) THEN 6
    //     //             WHEN LOWER(publications_flink.description) REGEXP '".buildSlugRegex($exactTagSlugs)."' THEN 5
    //     //             WHEN EXISTS (
    //     //                 SELECT 1 FROM publications_tags_flink 
    //     //                 WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //                 AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
    //     //             ) THEN 4
    //     //             WHEN LOWER(publications_flink.description) REGEXP '".buildSlugRegex($partialTagSlugs)."' THEN 3
    //     //             WHEN EXISTS (
    //     //                 SELECT 1 FROM publications_tags_flink 
    //     //                 WHERE publications_tags_flink.publication_id = publications_flink.id 
    //     //                 AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
    //     //             ) THEN 2
    //     //             WHEN LOWER(publications_flink.description) REGEXP '".buildSlugRegex($tagsSimilaireSlugs)."' THEN 1
    //     //             ELSE 0 END as match_priority")
    //     //     ];

    //     //     // Add location priority to select clauses
    //     //     $selectFields[] = \DB::raw("
    //     //         CASE 
    //     //             WHEN ville_id IN (".implode(',', $originVilleIds ?: [0]).") THEN 3
    //     //             WHEN ville_id IN (".implode(',', $villesIds_50km ?: [0]).") THEN 2
    //     //             WHEN ville_id IN (".implode(',', $villesIds_100km ?: [0]).") THEN 1
    //     //             ELSE 0
    //     //         END as location_priority_ville
    //     //     ");

    //     //     $filteredQuery->select($selectFields)
    //     //         ->having('match_priority', '>', 0)
    //     //         ->orderByDesc('location_priority_ville')
    //     //         ->orderByDesc('match_priority')
    //     //         ->orderByDesc("publications_flink.$orderArray[$orderIndex]");

    //     //     return $filteredQuery;


    //     // } else {
    //         // search for non arabic tag
    //         $keywords = [];
    //         $originalSearch = $search;
    //         $partsSearch = generateSlugParts($originalSearch);
    //         foreach($partsSearch as $partSearch) {
    //             $keywords = array_unique(array_merge($keywords, generateSlugCombinations($partSearch)));
    //         }

    //         // $keywords = array_diff($keywords, [$search]);
    //         // Sort by length (longest first)
    //         usort($keywords, function($a, $b) {
    //             return strlen($b) - strlen($a);
    //         });
            
            
    //         // 1. Find exact tag matches
    //         $exactTagIds = Tags::where('slug', $originalSearch)
    //                         ->pluck('id')
    //                         ->toArray();
    //         $exactTagNames = Tags::where('slug', $originalSearch)
    //                         ->pluck('name')
    //                         ->toArray();

            
    //         // Store keyword matches for each publication
    //         $partialMatches = [];
            
    //         if (!empty($keywords)) {
    //             foreach ($keywords as $keyword) {
    //                 $length = strlen($keyword);
                    
    //                 // Find tags that exactly match the keyword
    //                 $tags = Tags::where('slug', $keyword)
    //                     ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //                     ->get();
                    
    //                 foreach ($tags as $tag) {
    //                     $partialMatches[$tag->id] = [
    //                         'type' => 'exact_slug_match',
    //                         'length' => $length,
    //                         'priority' => 3,
    //                         'keyword' => $keyword
    //                     ];
    //                 }
                    
    //                 // Find tags that contain the keyword in their slug
    //                 $tags = Tags::where('slug', 'like', '%'.$keyword.'%')
    //                     ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //                     ->when(!empty(array_keys($partialMatches)), fn($q) => $q->whereNotIn('id', array_keys($partialMatches)))
    //                     ->get();
                    
    //                 foreach ($tags as $tag) {
    //                     // Calculate how well the keyword matches within the slug
    //                     $slug = $tag->slug;
    //                     $position = strpos($slug, $keyword);
    //                     $isAtStart = $position === 0;
    //                     $isAtEnd = $position === (strlen($slug) - strlen($keyword));
                        
    //                     $partialMatches[$tag->id] = [
    //                         'type' => $isAtStart ? 'slug_starts_with' : ($isAtEnd ? 'slug_ends_with' : 'slug_contains'),
    //                         'length' => $length,
    //                         'priority' => $isAtStart ? 2 : ($isAtEnd ? 1.5 : 1),
    //                         'keyword' => $keyword,
    //                         'position' => $position
    //                     ];
    //                 }
    //             }
    //         }

    //         $partialTagIds = array_keys($partialMatches);
    //         $partialTagNames = Tags::where('slug', 'like', '%'.$keyword.'%')
    //                     ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //                     ->when(!empty(array_keys($partialMatches)), fn($q) => $q->whereNotIn('id', array_keys($partialMatches)))
    //                     ->pluck('name')->toArray();
            

    //         // 3. Find similar tags
    //         $tagsSimilaires = Tags::select('id', 'name', 'slug')
    //             ->where('status', 1)
    //             ->whereHas('publications', fn($q) => $q->whereHas('tags', fn($q) => $q->where('name', $originalSearch)->orWhere('slug', $originalSearch)))
    //             ->where(fn($q) => $q->where('name', '!=', $originalSearch)->where('slug', '!=', $originalSearch))
    //             ->orderByDesc('compteur_publications')
    //             ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //             ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
    //             ->get()
    //             ->pluck('id')
    //             ->toArray();
    //         $tagsSimilaireNames = Tags::select('id', 'name', 'slug')
    //             ->where('status', 1)
    //             ->whereHas('publications', fn($q) => $q->whereHas('tags', fn($q) => $q->where('name', $originalSearch)->orWhere('slug', $originalSearch)))
    //             ->where(fn($q) => $q->where('name', '!=', $originalSearch)->where('slug', '!=', $originalSearch))
    //             ->orderByDesc('compteur_publications')
    //             ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //             ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
    //             ->get()
    //             ->pluck('name')
    //             ->toArray();

    //         $filteredQuery = clone $query;
            
    //         // $filteredQuery->where(function($q) use ($exactTagIds, $partialTagIds, $tagsSimilaires) {
    //         //     if (!empty($exactTagIds)) {
    //         //         $q->whereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $exactTagIds));
    //         //     }
    //         //     if (!empty($exactTagNames)) {
    //         //         $q->where(function($query) use ($exactTagNames) {
    //         //             foreach ($exactTagNames as $tag) {
    //         //                 $query->orWhere('description', 'like', '%' . $tag . '%');
    //         //             }
    //         //         });
    //         //     }
                
    //         //     if (!empty($partialTagIds)) {
    //         //         $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $partialTagIds));
    //         //     }
    //         //     if (!empty($partialTagNames)) {
    //         //         $q->where(function($query) use ($partialTagNames) {
    //         //             foreach ($partialTagNames as $tag) {
    //         //                 $query->orWhere('description', 'like', '%' . $tag . '%');
    //         //             }
    //         //         });
    //         //     }

    //         //     if (!empty($tagsSimilaires)) {
    //         //         $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $tagsSimilaires));
    //         //     }
    //         //     if (!empty($tagsSimilaireNames)) {
    //         //         $q->where(function($query) use ($tagsSimilaireNames) {
    //         //             foreach ($tagsSimilaireNames as $tag) {
    //         //                 $query->orWhere('description', 'like', '%' . $tag . '%');
    //         //             }
    //         //         });
    //         //     }
    //         // });

    //         $filteredQuery->where(function($q) use ($exactTagIds, $partialTagIds, $tagsSimilaires, $exactTagNames, $partialTagNames, $tagsSimilaireNames) {
    //             // Search by tag IDs (using slugs)
    //             if (!empty($exactTagIds)) {
    //                 $q->whereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $exactTagIds));
    //             }
                
    //             if (!empty($partialTagIds)) {
    //                 $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $partialTagIds));
    //             }

    //             if (!empty($tagsSimilaires)) {
    //                 $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $tagsSimilaires));
    //             }

    //             // Search by tag names in description
    //             if (!empty($exactTagNames)) {
    //                 $q->orWhere(function($query) use ($exactTagNames) {
    //                     foreach ($exactTagNames as $tag) {
    //                         $query->orWhere('description', 'like', '%' . $tag . '%');
    //                     }
    //                 });
    //             }
                
    //             if (!empty($partialTagNames)) {
    //                 $q->orWhere(function($query) use ($partialTagNames) {
    //                     foreach ($partialTagNames as $tag) {
    //                         $query->orWhere('description', 'like', '%' . $tag . '%');
    //                     }
    //                 });
    //             }

    //             if (!empty($tagsSimilaireNames)) {
    //                 $q->orWhere(function($query) use ($tagsSimilaireNames) {
    //                     foreach ($tagsSimilaireNames as $tag) {
    //                         $query->orWhere('description', 'like', '%' . $tag . '%');
    //                     }
    //                 });
    //             }
    //         });

    //         // Build the select statement with conditional logic for partial matches
    //         // $selectFields = [
    //         //     'publications_flink.*',
    //         //     \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
    //         //             WHERE publications_flink.id = publications_booster_flink.publication_id 
    //         //             AND date_fin > NOW()) as booster'),
    //         //     \DB::raw("CASE 
    //         //         WHEN EXISTS (
    //         //             SELECT 1 FROM publications_tags_flink 
    //         //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //         //             AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
    //         //         ) THEN 4 
    //         //         WHEN EXISTS (
    //         //             SELECT 1 FROM publications_tags_flink 
    //         //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //         //             AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
    //         //         ) THEN 3
    //         //         WHEN EXISTS (
    //         //             SELECT 1 FROM publications_tags_flink 
    //         //             WHERE publications_tags_flink.publication_id = publications_flink.id 
    //         //             AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
    //         //         ) THEN 1
    //         //         ELSE 0 END as match_priority")
    //         // ];

    //         $selectFields = [
    //             'publications_flink.*',
    //             \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
    //                     WHERE publications_flink.id = publications_booster_flink.publication_id 
    //                     AND date_fin > NOW()) as booster'),
    //             \DB::raw("CASE 
    //                 WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
    //                 ) THEN 6
    //                 WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
    //                 ) THEN 4
    //                 WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
    //                 ) THEN 2
    //                 ELSE 0 END as tag_match_priority"),
    //             \DB::raw("CASE 
    //                 ".($exactTagNames ? "WHEN (".implode(" OR ", array_map(function($name) {
    //                     return "publications_flink.description LIKE '%".addslashes($name)."%'";
    //                 }, $exactTagNames)).") THEN 5" : "")."
    //                 ".($partialTagNames ? "WHEN (".implode(" OR ", array_map(function($name) {
    //                     return "publications_flink.description LIKE '%".addslashes($name)."%'";
    //                 }, $partialTagNames)).") THEN 3" : "")."
    //                 ".($tagsSimilaireNames ? "WHEN (".implode(" OR ", array_map(function($name) {
    //                     return "publications_flink.description LIKE '%".addslashes($name)."%'";
    //                 }, $tagsSimilaireNames)).") THEN 1" : "")."
    //                 ELSE 0 END as description_match_priority"),
    //             \DB::raw("GREATEST(
    //                 CASE WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
    //                 ) THEN 6 ELSE 0 END,
    //                 CASE WHEN (".($exactTagNames ? implode(" OR ", array_map(function($name) {
    //                     return "publications_flink.description LIKE '%".addslashes($name)."%'";
    //                 }, $exactTagNames)) : "FALSE").") THEN 5 ELSE 0 END,
    //                 CASE WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
    //                 ) THEN 4 ELSE 0 END,
    //                 CASE WHEN (".($partialTagNames ? implode(" OR ", array_map(function($name) {
    //                     return "publications_flink.description LIKE '%".addslashes($name)."%'";
    //                 }, $partialTagNames)) : "FALSE").") THEN 3 ELSE 0 END,
    //                 CASE WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
    //                 ) THEN 2 ELSE 0 END,
    //                 CASE WHEN (".($tagsSimilaireNames ? implode(" OR ", array_map(function($name) {
    //                     return "publications_flink.description LIKE '%".addslashes($name)."%'";
    //                 }, $tagsSimilaireNames)) : "FALSE").") THEN 1 ELSE 0 END
    //             ) as match_priority")
    //         ];

    //         // Add keyword match score fields only if we have partial matches
    //         if (!empty($partialTagIds)) {
    //             $selectFields[] = \DB::raw("COALESCE(
    //                 (SELECT MAX(match_score) FROM (
    //                     SELECT ".implode(' UNION ALL SELECT ', array_map(function($id) use ($partialMatches) {
    //                         $score = $partialMatches[$id]['priority'] * $partialMatches[$id]['length'];
    //                         if (isset($partialMatches[$id]['position'])) {
    //                             $position = $partialMatches[$id]['position'];
    //                             if ($position === 0) {
    //                                 $score *= 1.5;
    //                             } elseif ($position > 0) {
    //                                 $score *= (1 - ($position * 0.01));
    //                             }
    //                         }
    //                         return "{$score} as match_score, {$partialMatches[$id]['priority']} as match_type, {$id} as tag_id";
    //                     }, $partialTagIds))."
    //                 ) matches
    //                 JOIN publications_tags_flink pt ON pt.tag_id = matches.tag_id
    //                 WHERE pt.publication_id = publications_flink.id
    //             ), 0) as keyword_match_score");

    //             $selectFields[] = \DB::raw("COALESCE(
    //                 (SELECT MAX(match_type) FROM (
    //                     SELECT ".implode(' UNION ALL SELECT ', array_map(function($id) use ($partialMatches) {
    //                         return "{$partialMatches[$id]['priority']} as match_type, {$id} as tag_id";
    //                     }, $partialTagIds))."
    //                 ) matches
    //                 JOIN publications_tags_flink pt ON pt.tag_id = matches.tag_id
    //                 WHERE pt.publication_id = publications_flink.id
    //             ), 0) as keyword_match_type");
    //         } else {
    //             $selectFields[] = \DB::raw("0 as keyword_match_score");
    //             $selectFields[] = \DB::raw("0 as keyword_match_type");
    //         }

    //         // Add location priority to select clauses
    //         $selectFields[] = \DB::raw("
    //             CASE 
    //                 WHEN ville_id IN (".implode(',', $originVilleIds ?: [0]).") THEN 3
    //                 WHEN ville_id IN (".implode(',', $villesIds_50km ?: [0]).") THEN 2
    //                 WHEN ville_id IN (".implode(',', $villesIds_100km ?: [0]).") THEN 1
    //                 ELSE 0
    //             END as location_priority_ville
    //         ");

    //         $filteredQuery->select($selectFields)
    //             ->having('match_priority', '>', 0)
    //             ->orderByDesc('location_priority_ville')
    //             ->orderByDesc('match_priority')
    //             ->orderByDesc('keyword_match_score')
    //             ->orderByDesc('keyword_match_type')
    //             ->orderByDesc("publications_flink.$orderArray[$orderIndex]");

    //         return $filteredQuery;
    //     // }
    // }

    function buildSlugRegex(array $names = []): string {
        if (empty($names)) {
            return 'a^'; // Regex that never matches
        }

        $escaped = array_map(function($name) {
            return preg_quote(strtolower($name), '/');
        }, $names);

        return '\\b('.implode('|', $escaped).')\\b';
    }
}

if (!function_exists('searchFilteredProfiles')) {
    // function searchFilteredProfiles($userQuery, $etablissementQuery, $search, $profile, $followedSlugs, $numberWithoutPagination = null) {
    //     // Initialize keywords
    //     // $keywords = $search ? array_filter(preg_split('/[\s\-]+/', $search)) : [];

    //     // 2. Process keywords from search term
    //     $excludedWords = ['a', 'au', 'la', 'le', 'des', 'et', 'etc', 'de', 'du', 'en', '&'];
    //     $keywords = array_filter(
    //         explode('-', $search),
    //         function($word) use ($excludedWords) {
    //             return !in_array(strtolower($word), $excludedWords) && strlen($word) > 1;
    //         }
    //     );

    //     // Apply search conditions if keywords exist
    //     if (!empty($keywords)) {
    //         $searchCallback = function($query) use ($keywords) {
    //             foreach ($keywords as $keyword) {
    //                 $query->orWhere(function($q) use ($keyword) {
    //                     $q->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$keyword}%"])
    //                     ->orWhere('bio', 'LIKE', "%{$keyword}%")
    //                     // ->orWhereHas('publications.tags', function($tagQuery) use ($keyword) {
    //                     //     $tagQuery->where('name', 'LIKE', "%{$keyword}%")
    //                     //             ->orWhere('slug', 'LIKE', "%{$keyword}%");
    //                     // });
    //                     ->orWhereHas('publications', function($pubQuery) use ($keyword) {
    //                         $pubQuery->where('status', 1)
    //                             ->whereHas('tags', function($tagQuery) use ($keyword) {
    //                                 $tagQuery->where('name', 'LIKE', "%{$keyword}%")
    //                                         ->orWhere('slug', 'LIKE', "%{$keyword}%");
    //                             });
    //                     });
    //                 });
    //             }
    //         };

    //         $userQuery->where($searchCallback);
    //         $etablissementQuery->where(function($query) use ($keywords) {
    //             foreach ($keywords as $keyword) {
    //                 $query->orWhere(function($q) use ($keyword) {
    //                     $q->where('nom', 'LIKE', "%{$keyword}%")
    //                     ->orWhere('bio', 'LIKE', "%{$keyword}%")
    //                     // ->orWhereHas('publications.tags', function($tagQuery) use ($keyword) {
    //                     //     $tagQuery->where('name', 'LIKE', "%{$keyword}%")
    //                     //             ->orWhere('slug', 'LIKE', "%{$keyword}%");
    //                     // });
    //                     ->orWhereHas('publications', function($pubQuery) use ($keyword) {
    //                         $pubQuery->where('status', 1)
    //                             ->whereHas('tags', function($tagQuery) use ($keyword) {
    //                                 $tagQuery->where('name', 'LIKE', "%{$keyword}%")
    //                                         ->orWhere('slug', 'LIKE', "%{$keyword}%");
    //                             });
    //                     });
    //                 });
    //             }
    //         });
    //     }

    //     // Get ALL results first (without pagination)
    //     $users = $userQuery->with([
    //         'followingUsers', 
    //         'followingEtablissements', 
    //         'followersUsers', 
    //         'followersEtablissements',
    //         'publications.tags'
    //     ])->get();

    //     $etablissements = $etablissementQuery->with([
    //         'followingUsers', 
    //         'followingEtablissements', 
    //         'followersUsers', 
    //         'followersEtablissements',
    //         'publications.tags'
    //     ])->get();

    //     // Combine and process all results
    //     $combined = $users->map(function($item) {
    //         $item->type = 'user';
    //         return $item;
    //     })->merge($etablissements->map(function($item) {
    //         $item->type = 'etablissement';
    //         return $item;
    //     }))->map(function ($item) use ($keywords, $profile, $followedSlugs) {
    //         // Calculate priority with specific weights
    //         $nameMatches = 0;
    //         $bioMatches = 0;
    //         $tagMatches = 0;
            
    //         $name = $item->type === 'user' 
    //             ? $item->first_name . ' ' . $item->last_name 
    //             : $item->nom;
            
    //         foreach ($keywords as $keyword) {
    //             if (stripos($name, $keyword) !== false) $nameMatches++;
    //             if (isset($item->bio) && stripos($item->bio, $keyword) !== false) $bioMatches++;
    //             if ($item->publications->flatMap->tags->contains(fn($tag) => 
    //                 stripos($tag->name, $keyword) !== false || stripos($tag->slug, $keyword) !== false
    //             )) $tagMatches++;
    //         }
            
    //         // Priority calculation with weights:
    //         // Name matches are most important (weight 3)
    //         // Bio matches are medium important (weight 2)
    //         // Tag matches are least important (weight 1)
    //         $priority = ($nameMatches * 3) + ($bioMatches * 2) + ($tagMatches * 1);
            
    //         return [
    //             'id' => $item->id,
    //             'name' => $name,
    //             'avatar' => $item->avatar ?? $item->logo,
    //             'slug' => $item->slug,
    //             'is_verified' => $item->is_verified,
    //             'score' => $item->score,
    //             'bio' => $item->bio,
    //             'type' => $item->type,
    //             'isFollowed' => $profile && in_array($item->slug, $followedSlugs),
    //             'followingCount' => $item->followingUsers->count() + $item->followingEtablissements->count(),
    //             'followersCount' => $item->followersUsers->count() + $item->followersEtablissements->count(),
    //             'publicationsCount' => $item->publications->count(),
    //             'matchedTags' => !empty($keywords) ? $item->publications->flatMap->tags
    //                 ->filter(fn($tag) => array_reduce($keywords, fn($carry, $kw) => 
    //                     $carry || stripos($tag->name, $kw) !== false || stripos($tag->slug, $kw) !== false, false))
    //                 ->unique('id')->values() : collect(),
    //             'searchKeywords' => $keywords,
    //             'search_priority' => $priority,
    //             'name_matches' => $nameMatches,
    //             'bio_matches' => $bioMatches,
    //             'tag_matches' => $tagMatches
    //         ];
    //     })->sortByDesc(function($item) {
    //         // First sort by priority (name matches weighted highest)
    //         // Then by score as a secondary sort
    //         return [$item['search_priority'], $item['score']];
    //     })->values();

    //     // Handle non-paginated case
    //     if ($numberWithoutPagination) {
    //         return $combined->take($numberWithoutPagination);
    //     }

    //     // Apply pagination to the combined results
    //     $currentPage = LengthAwarePaginator::resolveCurrentPage();
    //     $perPage = 12;
    //     $currentItems = $combined->slice(($currentPage - 1) * $perPage, $perPage)->values();

    //     $paginator = new LengthAwarePaginator(
    //         $currentItems,
    //         $combined->count(),
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

    // function searchFilteredProfiles($userQuery, $etablissementQuery, $search, $profile, $followedSlugs, $numberWithoutPagination = null) {
    //     $excludedWords = ['a', 'au', 'la', 'le', 'des', 'et', 'etc', 'de', 'du', 'en', '&'];
    //     $searchVariations = [];

    //     // Find partial tag matches (keywords)
    //     $originalKeywords = array_filter(
    //         explode('-', $search),
    //         fn($word) => !in_array(strtolower($word), $excludedWords) 
    //                 && strlen($word) > 1
    //     );

    //     foreach($originalKeywords as $keyword) {
    //         // Generate variations by removing 1-3 letters
    //         for ($i = 1; $i <= min(3, strlen($keyword) - 1); $i++) {
    //             $searchVariations[] = substr($keyword, 0, -$i);
    //         }
    //     }

    //     // Get ALL results first (without pagination)
    //     $users = $userQuery->with([
    //         'followingUsers', 
    //         'followingEtablissements', 
    //         'followersUsers', 
    //         'followersEtablissements',
    //         'publications.tags'
    //     ])->get();

    //     $etablissements = $etablissementQuery->with([
    //         'followingUsers', 
    //         'followingEtablissements', 
    //         'followersUsers', 
    //         'followersEtablissements',
    //         'publications.tags'
    //     ])->get();

    //     // Combine and process all results
    //     $combined = $users->map(function($item) {
    //         $item->type = 'user';
    //         return $item;
    //     })->merge($etablissements->map(function($item) {
    //         $item->type = 'etablissement';
    //         return $item;
    //     }))->map(function ($item) use ($originalKeywords, $searchVariations, $profile, $followedSlugs) {
    //         $name = $item->type === 'user' 
    //             ? $item->first_name . ' ' . $item->last_name 
    //             : $item->nom;
            
    //         // Check for exact matches first
    //         $exactMatch = false;
    //         $partialMatch = false;
    //         $variationMatches = [];
            
    //         // Check original search terms
    //         $nameMatches = 0;
    //         $bioMatches = 0;
    //         $tagMatches = 0;
            
    //         foreach ($originalKeywords as $keyword) {
    //             if (stripos($name, $keyword) !== false) {
    //                 $nameMatches++;
    //                 $exactMatch = true;
    //             }
    //             if (isset($item->bio) && stripos($item->bio, $keyword) !== false) {
    //                 $bioMatches++;
    //                 $exactMatch = true;
    //             }
    //             if ($item->publications->flatMap->tags->contains(fn($tag) => 
    //                 stripos($tag->name, $keyword) !== false || stripos($tag->slug, $keyword) !== false
    //             )) {
    //                 $tagMatches++;
    //                 $exactMatch = true;
    //             }
    //         }
            
    //         // Check for partial matches if no exact matches found
    //         if (!$exactMatch) {
    //             foreach ($originalKeywords as $keyword) {
    //                 foreach (str_split($keyword, 3) as $part) { // Check parts of the keyword
    //                     if (strlen($part) >= 3) {
    //                         if (stripos($name, $part) !== false) {
    //                             $nameMatches += 0.5; // Partial matches get half weight
    //                             $partialMatch = true;
    //                         }
    //                         if (isset($item->bio) && stripos($item->bio, $part) !== false) {
    //                             $bioMatches += 0.5;
    //                             $partialMatch = true;
    //                         }
    //                         if ($item->publications->flatMap->tags->contains(fn($tag) => 
    //                             stripos($tag->name, $part) !== false || stripos($tag->slug, $part) !== false
    //                         )) {
    //                             $tagMatches += 0.5;
    //                             $partialMatch = true;
    //                         }
    //                     }
    //                 }
    //             }
    //         }
            
    //         // Check search variations if no exact or partial matches
    //         if (!$exactMatch && !$partialMatch && !empty($searchVariations)) {
    //             foreach ($searchVariations as $variationIndex => $variation) {
    //                 $variationKeywords = array_filter(explode('-', $variation));
                    
    //                 foreach ($variationKeywords as $keyword) {
    //                     if (stripos($name, $keyword) !== false) {
    //                         $nameMatches += max(0.3, 1 - ($variationIndex * 0.2)); // Decreasing weight for variations
    //                         $variationMatches[] = $keyword;
    //                     }
    //                     if (isset($item->bio) && stripos($item->bio, $keyword) !== false) {
    //                         $bioMatches += max(0.2, 0.8 - ($variationIndex * 0.15));
    //                         $variationMatches[] = $keyword;
    //                     }
    //                     if ($item->publications->flatMap->tags->contains(fn($tag) => 
    //                         stripos($tag->name, $keyword) !== false || stripos($tag->slug, $keyword) !== false
    //                     )) {
    //                         $tagMatches += max(0.1, 0.5 - ($variationIndex * 0.1));
    //                         $variationMatches[] = $keyword;
    //                     }
    //                 }
    //             }
    //         }
            
    //         // Only include profiles that have at least one match
    //         if ($exactMatch || $partialMatch || !empty($variationMatches)) {
    //             // Priority calculation with weights:
    //             $priority = ($nameMatches * 3) + ($bioMatches * 2) + ($tagMatches * 1);
                
    //             // Boost exact matches to the top
    //             if ($exactMatch) {
    //                 $priority *= 2;
    //             } elseif ($partialMatch) {
    //                 $priority *= 1.5;
    //             }
                
    //             return [
    //                 'id' => $item->id,
    //                 'name' => $name,
    //                 'avatar' => $item->avatar ?? $item->logo,
    //                 'slug' => $item->slug,
    //                 'is_verified' => $item->is_verified,
    //                 'score' => $item->score,
    //                 'bio' => $item->bio,
    //                 'type' => $item->type,
    //                 'isFollowed' => $profile && in_array($item->slug, $followedSlugs),
    //                 'followingCount' => $item->followingUsers->count() + $item->followingEtablissements->count(),
    //                 'followersCount' => $item->followersUsers->count() + $item->followersEtablissements->count(),
    //                 'publicationsCount' => $item->publications->count(),
    //                 'matchedTags' => !empty($originalKeywords) ? $item->publications->flatMap->tags
    //                     ->filter(fn($tag) => array_reduce($originalKeywords, fn($carry, $kw) => 
    //                         $carry || stripos($tag->name, $kw) !== false || stripos($tag->slug, $kw) !== false, false))
    //                     ->unique('id')->values() : collect(),
    //                 'searchKeywords' => $originalKeywords,
    //                 'search_priority' => $priority,
    //                 'name_matches' => $nameMatches,
    //                 'bio_matches' => $bioMatches,
    //                 'tag_matches' => $tagMatches,
    //                 'match_type' => $exactMatch ? 'exact' : ($partialMatch ? 'partial' : (!empty($variationMatches) ? 'variation' : 'none')),
    //                 'variation_matches' => array_unique($variationMatches),
    //                 'followingUsers' => $item->followingUsers , 
    //                 'followingEtablissements' => $item->followingEtablissements , 
    //                 'followersUsers' => $item->followersUsers , 
    //                 'followersEtablissements' => $item->followersEtablissements,
    //             ];
    //         }
    //         return null; // Return null for no matches
    //     })->filter() // Remove null entries (profiles with no matches)
    //     ->sortByDesc(function($item) {
    //         // First sort by priority (exact matches first, then partial, then variations)
    //         // Then by score as a secondary sort
    //         return [
    //             $item['search_priority'],
    //             $item['match_type'] === 'exact' ? 3 : ($item['match_type'] === 'partial' ? 2 : ($item['match_type'] === 'variation' ? 1 : 0)),
    //             $item['score']
    //         ];
    //     })->values();

    //     // Handle non-paginated case
    //     if ($numberWithoutPagination) {
    //         return $combined->take($numberWithoutPagination);
    //     }
    //     return $combined;

    //     // // Apply pagination to the combined results
    //     // $currentPage = LengthAwarePaginator::resolveCurrentPage();
    //     // $perPage = 12;
    //     // $currentItems = $combined->slice(($currentPage - 1) * $perPage, $perPage)->values();

    //     // $paginator = new LengthAwarePaginator(
    //     //     $currentItems,
    //     //     $combined->count(),
    //     //     $perPage,
    //     //     $currentPage,
    //     //     [
    //     //         'path' => LengthAwarePaginator::resolveCurrentPath(),
    //     //         'pageName' => 'page'
    //     //     ]
    //     // );

    //     // $paginator->appends(request()->except('page'));
        
    //     // return $paginator;
    // }

    function searchFilteredProfiles($userQuery, $etablissementQuery, $search, $profile, $followedSlugs) {
        $originalKeywords = explode("-", $search);

        // Generate slug parts and combinations for more comprehensive matching
        $keywords = [];
        foreach ($originalKeywords as $originalKeyword) {
            $parts = generateSlugParts($originalKeyword);
            foreach ($parts as $part) {
                $combinations = generateSlugCombinations($part);
                $keywords = array_unique(array_merge($keywords, $combinations));
            }
        }
        
        // Add original keywords to ensure they're included
        $keywords = array_unique(array_merge($keywords, $originalKeywords));
        
        // Sort keywords by length (longest first) for priority matching
        usort($keywords, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        // Get ALL results first (without pagination)
        $users = $userQuery->with([
            'followingUsers', 
            'followingEtablissements', 
            'followersUsers', 
            'followersEtablissements',
            'publications.tags'
        ])->get();

        $etablissements = $etablissementQuery->with([
            'followingUsers', 
            'followingEtablissements', 
            'followersUsers', 
            'followersEtablissements',
            'publications.tags'
        ])->get();

        // Combine and process all results with enhanced matching logic
        $combined = $users->map(function($item) use ($keywords, $profile, $followedSlugs) {
            return scoreProfile($item, 'user', $keywords, $profile, $followedSlugs);
        })->merge($etablissements->map(function($item) use ($keywords, $profile, $followedSlugs) {
            return scoreProfile($item, 'etablissement', $keywords, $profile, $followedSlugs);
        }))->filter() // Remove null entries (profiles with no matches)
        // ->sortByDesc(function($item) {
        //     // Primary sort by highest priority match type
        //     // Secondary sort by number of exact matches
        //     // Tertiary sort by total match score
        //     // Finally by profile score
        //     return [
        //         $item['priority_type_score'],
        //         $item['exact_match_count'],
        //         $item['total_match_score'],
        //         $item['score']
        //     ];
        // })
        ->values();

        // Return empty collection if no matches found
        if ($combined->isEmpty()) {
            return collect();
        }

        return $combined;
    }

    function scoreProfile($item, $type, $keywords, $profile, $followedSlugs) {
        $name = $type === 'user' 
            ? $item->first_name . ' ' . $item->last_name 
            : $item->nom;
        
        $bio = $item->bio ?? '';
        $tags = $item->publications->flatMap->tags->unique('id');
        $slug = $item->slug;

        // Initialize match tracking with enhanced categories
        $matches = [
            'exact_name' => [],
            'exact_bio' => [],
            'exact_tag' => [],
            'partial_name' => [],
            'partial_bio' => [],
            'partial_tag' => [],
            'slug_variations' => []
        ];

        // Track which keywords we've matched to avoid double-counting
        $matchedKeywords = [];

        foreach ($keywords as $keyword) {
            $keywordLower = strtolower($keyword);
            $nameLower = strtolower($name);
            $bioLower = strtolower($bio);
            
            // 1. Check for EXACT matches in name (highest priority)
            if (!keywordMatched($matchedKeywords, $keyword) && 
                ($nameLower === $keywordLower || $slug === $keyword)) {
                $matches['exact_name'][] = $keyword;
                $matchedKeywords[] = $keyword;
                continue; // Skip to next keyword since we found exact match
            }
            
            // 2. Check for EXACT matches in bio (second priority)
            if (!keywordMatched($matchedKeywords, $keyword) && 
                $bioLower === $keywordLower) {
                $matches['exact_bio'][] = $keyword;
                $matchedKeywords[] = $keyword;
                continue;
            }
            
            // 3. Check for EXACT matches in tags (third priority)
            if (!keywordMatched($matchedKeywords, $keyword)) {
                foreach ($tags as $tag) {
                    if (strtolower($tag->name) === $keywordLower || 
                        strtolower($tag->slug) === $keywordLower) {
                        $matches['exact_tag'][] = $keyword;
                        $matchedKeywords[] = $keyword;
                        continue 2; // Continue to next keyword
                    }
                }
            }
            
            // 4. Check for PARTIAL matches in name
            if (!keywordMatched($matchedKeywords, $keyword) && 
                strpos($nameLower, $keywordLower) !== false) {
                $matches['partial_name'][] = $keyword;
                $matchedKeywords[] = $keyword;
                continue;
            }
            
            // 5. Check for PARTIAL matches in bio
            if (!keywordMatched($matchedKeywords, $keyword) && 
                strpos($bioLower, $keywordLower) !== false) {
                $matches['partial_bio'][] = $keyword;
                $matchedKeywords[] = $keyword;
                continue;
            }
            
            // 6. Check for PARTIAL matches in tags
            if (!keywordMatched($matchedKeywords, $keyword)) {
                foreach ($tags as $tag) {
                    if (strpos(strtolower($tag->name), $keywordLower) !== false || 
                        strpos(strtolower($tag->slug), $keywordLower) !== false) {
                        $matches['partial_tag'][] = $keyword;
                        $matchedKeywords[] = $keyword;
                        continue 2; // Continue to next keyword
                    }
                }
            }
            
            // 7. Check for slug variations if no matches yet
            if (!keywordMatched($matchedKeywords, $keyword) && 
                strpos($slug, $keyword) !== false) {
                $matches['slug_variations'][] = $keyword;
                $matchedKeywords[] = $keyword;
            }
        }

        // Calculate priority scores
        $priorityTypeScore = 0;
        $priorityType = 'none';
        
        if (!empty($matches['exact_name'])) {
            $priorityTypeScore = 6;
            $priorityType = 'exact_name';
        } elseif (!empty($matches['exact_bio'])) {
            $priorityTypeScore = 5;
            $priorityType = 'exact_bio';
        } elseif (!empty($matches['exact_tag'])) {
            $priorityTypeScore = 4;
            $priorityType = 'exact_tag';
        } elseif (!empty($matches['partial_name'])) {
            $priorityTypeScore = 3;
            $priorityType = 'partial_name';
        } elseif (!empty($matches['partial_bio'])) {
            $priorityTypeScore = 2;
            $priorityType = 'partial_bio';
        } elseif (!empty($matches['partial_tag'])) {
            $priorityTypeScore = 1;
            $priorityType = 'partial_tag';
        } elseif (!empty($matches['slug_variations'])) {
            $priorityTypeScore = 0.5;
            $priorityType = 'slug_variation';
        }

        // Calculate total match score based on keyword lengths and match types
        $totalMatchScore = 0;
        $exactMatchCount = 0;
        
        // Exact matches get highest scores
        foreach ($matches['exact_name'] as $keyword) {
            $totalMatchScore += strlen($keyword) * 3;
            $exactMatchCount++;
        }
        foreach ($matches['exact_bio'] as $keyword) {
            $totalMatchScore += strlen($keyword) * 2.5;
            $exactMatchCount++;
        }
        foreach ($matches['exact_tag'] as $keyword) {
            $totalMatchScore += strlen($keyword) * 2;
            $exactMatchCount++;
        }
        
        // Partial matches get lower scores
        foreach ($matches['partial_name'] as $keyword) {
            $totalMatchScore += strlen($keyword) * 1.5;
        }
        foreach ($matches['partial_bio'] as $keyword) {
            $totalMatchScore += strlen($keyword) * 1.2;
        }
        foreach ($matches['partial_tag'] as $keyword) {
            $totalMatchScore += strlen($keyword);
        }
        foreach ($matches['slug_variations'] as $keyword) {
            $totalMatchScore += strlen($keyword) * 0.8;
        }

        // Only include profiles that have at least one match
        if ($priorityTypeScore > 0) {
            return [
                'id' => $item->id,
                'name' => $name,
                'avatar' => $item->avatar ?? $item->logo,
                'slug' => $slug,
                'is_verified' => $item->is_verified,
                'score' => $item->score,
                'bio' => $bio,
                'type' => $type,
                'isFollowed' => $profile && in_array($slug, $followedSlugs),
                'followingCount' => $item->followingUsers->count() + $item->followingEtablissements->count(),
                'followersCount' => $item->followersUsers->count() + $item->followersEtablissements->count(),
                'publicationsCount' => $item->publications->count(),
                'priority_type_score' => $priorityTypeScore,
                'priority_type' => $priorityType,
                'exact_match_count' => $exactMatchCount,
                'total_match_score' => $totalMatchScore,
                'matches' => $matches,
                'followingUsers' => $item->followingUsers, 
                'followingEtablissements' => $item->followingEtablissements, 
                'followersUsers' => $item->followersUsers, 
                'followersEtablissements' => $item->followersEtablissements,
            ];
        }
        
        return null; // No matches found
    }

    function keywordMatched(&$matchedKeywords, $keyword) {
        return in_array($keyword, $matchedKeywords);
    }
}

if (!function_exists('addBoosterToPublicationsToSearch')) {
    function addBoosterToPublicationsToSearch($publications, $request, $perPage, $withPaginate=true, $onlyVideo=false) {
        // $perPage = 24;
        $regularPerPage = 5*$perPage/6; // 20 regular publications per page
        $boostedPerPage = $perPage/6;   // 4 boosted publications per page
        
    
        // First get all active boosted publications (not paginated)
        $boostedQuery = Publication::select(
            'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 
            'image_default', 'images', 'video_compresse', 'prix', 'prixPromo', 'score', 'miniature'
        )
        ->where('status', 1)
        ->whereNotNull('image_default');
        if($onlyVideo) $boostedQuery = $boostedQuery->whereNotNull('video_compresse');
        $boostedQuery = $boostedQuery->withCount([
            'publications_booster as booster' => function ($query) {
                $query->where('date_fin', '>', now());
            }
        ])
        ->having('booster', '>', 0)
        ->with([
            'ville:id,slug,name,pays_id',
            'region:id,slug,name,ville_id',
            'user:id,first_name,last_name,slug,avatar',
            'etablissement:id,nom,slug,logo',
            'tags:id,name,slug',
            'publications_booster' => function($query) {
                $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message', 'max_impressions', 'last_display')
                    ->where('date_fin', '>', now())
                    ->orderBy('last_display', 'asc') // Changed to asc to rotate properly
                    ->orderBy('id', 'desc');
            },
            'statistiques' => function ($query) {
                $query->selectRaw('
                    publication_id,
                    SUM(vues) as vues
                ')->groupBy('publication_id');
            },
            'publications_booster.shopping_publications'
        ])
        ->orderBy(
            PublicationBooster::select('last_display')
                ->whereColumn('publication_id', 'publications_flink.id')
                ->where('date_fin', '>', now())
                ->orderBy('last_display', 'asc')
                ->limit(1)
        );
    
        // Get ALL boosted publications (not paginated)
        $allBoosted = $boostedQuery->get();
        $totalBoosted = $allBoosted->count();
    
        // Get paginated regular publications
        $regular = $publications->paginate($regularPerPage, ['*'], 'regular_page', $request->page ?? 1);
        $totalRegular = $regular->total();

        if($totalRegular == 0 && !$withPaginate) return $publications->get();
        if($totalRegular == 0) return $regular;
     
        // Calculate how many pages we'll have (based on regular publications)
        $totalPages = ceil($totalRegular / $regularPerPage);
    
        // Create a collection for mixed results
        $mixedPublications = collect();
    
        // Get regular items for current page
        $regularItems = $regular->items();
    
        // Determine which boosted items to use for this page
        $boostedItems = collect();
        if ($totalBoosted > 0) {
            // Calculate starting index for boosted items (circular)
            $startIndex = (($request->page ?? 1) - 1) * ($boostedPerPage % $totalBoosted);
            
            // Get boosted items for this page (with circular loop)
            for ($i = 0; $i < $boostedPerPage; $i++) {
                if ($totalBoosted > 0) {
                    $index = ($startIndex + $i) % $totalBoosted;
                    $boostedItems->push($allBoosted[$index]);
                    
                    // Update last_display for the boosted publication
                    if (isset($allBoosted[$index]->publications_booster[0])) {
                        $allBoosted[$index]->publications_booster[0]->update([
                            'last_display' => now()
                        ]);
                    }
                }
            }
        }
    
        // Merge regular and boosted items in 5:1 ratio
        $bIndex = 0;
        $rIndex = 0;
        $totalItems = min($perPage, count($regularItems) + $boostedItems->count());
    
        // Random starting point for boosted insertion (between 2-4 regular items before first boosted)
        $randomIndex = rand(2, 5);
        
        while ($mixedPublications->count() < $totalItems) {
            // Add regular publications
            for ($i = 0; $i < $randomIndex && $rIndex < count($regularItems) && $mixedPublications->count() < $totalItems; $i++) {
                $mixedPublications->push($regularItems[$rIndex++]);
                $randomIndex = rand(2, 5);
            }
            
            // Add boosted publication if available
            if ($bIndex < $boostedItems->count() && $mixedPublications->count() < $totalItems) {
                $mixedPublications->push($boostedItems[$bIndex++]);
                $randomIndex = rand(2, 4); // New random position for next boosted
            }
            
            // If no more boosted but still regular items, add them
            if ($bIndex >= $boostedItems->count() && $rIndex < count($regularItems) && $mixedPublications->count() < $totalItems) {
                $mixedPublications->push($regularItems[$rIndex++]);
            }
        }

        if(!$withPaginate) return $mixedPublications->take(4);
    
        // Create a custom paginator
        $publicationsWithBooster = new LengthAwarePaginator(
            $mixedPublications,
            $totalRegular, // Total is based on regular publications
            $perPage,
            $request->page ?? 1,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    
        return $publicationsWithBooster;
    }
}

if (!function_exists('updateTagsSearch')) {
    function updateTagsSearch($search, $searchName, $profile) {
        if($search) {
            $tag = Tags::where('slug', $search)->first();
            if($tag) {
                $searchTagName = $tag->name;
                $tag->compteur_search += 1;
                $tag->save();
            } else {
                // Generate a possible name from the search string
                if($searchName) $generatedName = $searchName;
                else $generatedName = Str::of($search)->replace('-', ' ')->title();
                // Check if a tag exists with the same name (to avoid conflicts)
                $existingTagByName = Tags::where('name', $generatedName)->first();
                if(!$existingTagByName) {
                    $tag = new Tags();
                    $tag->name = $generatedName;
                    $searchTagName = $generatedName;
                    $tag->slug = $search;
                    $tag->save();
                }
            }
            $tag = Tags::where('slug', $search)->first();
            if($profile) {
                $user = User::where('slug', $profile)->first();
                $etablissement = Etablissement::where('slug', $profile)->first();
                if($user) {
                    // Check if the tag is already assigned to the 
                    $exist = $user->tags_filters()->where('tag_id', $tag->id)->whereNull('pays_id')->whereNull('ville_id')->whereNull('region_id')->first();
                    
                    if ($exist) {
                        // Increment the compteur value
                        $exist->pivot->increment('compteur');
                    } else {
                        // Attach the tag
                        $user->tags_filters()->attach($tag->id);
                    }
                } else if($etablissement) {
                        // Check if the tag is already assigned to the etablissement
                    $exist = $etablissement->tags_filters()->where('tag_id', $tag->id)->whereNull('pays_id')->whereNull('ville_id')->whereNull('region_id')->first();

                    if ($exist) {
                            // Increment the compteur value
                            $exist->pivot->increment('compteur');
                        } else {
                            // Attach the tag
                            $etablissement->tags_filters()->attach($tag->id);
                        }
                }
            }
        }
    }
}

if (!function_exists('profilePreferences')) {
    function profilePreferences($combined, $authUser = null, $numberWithoutPagination=null) {
        $orderIndex = rand(0, 1);
        $orderArray = ["score", "created_at"];
        // Convert array items to objects if needed
        $collection = collect($combined)->map(function ($item) {
            return is_array($item) ? (object)$item : $item;
        });

        // Apply preference ordering if we have an authenticated user
        $orderedResults = $collection->when($authUser, function($collection) use ($authUser) {
            return $collection->map(function($profile) use ($authUser) {
                // Initialize relationship flags
                $isFollowingYou = false;
                $youAreFollowing = false;
                $commonFollowers = 0;
                $priorityLabel = 'no_relationship';

                if ($profile->type == "user") {
                    // Check if current user follows this profile
                    $isFollowingYou = (bool)$profile->followingUsers->where('slug', $authUser->slug)->first()
                        || (bool)$profile->followingEtablissements->where('slug', $authUser->slug)->first();
                    
                    // Check if this profile follows current user
                    $youAreFollowing = (bool)$profile->followersUsers->where('slug', $authUser->slug)->first() 
                        || (bool)$profile->followersEtablissements->where('slug', $authUser->slug)->first();
                    
                    // Count common followers
                    $profileFollowers = $profile->followersUsers->pluck('id');
                    $authUserFollowers = $authUser->followersUsers->pluck('id');
                    $commonFollowers = $profileFollowers->intersect($authUserFollowers)->count();
                } else {
                    // Check if current user follows this profile
                    $isFollowingYou = (bool)$profile->followingEtablissements->where('slug', $authUser->slug)->first() 
                        || (bool)$profile->followingUsers->where('slug', $authUser->slug)->first();
                    
                    // Check if this profile follows current user
                    $youAreFollowing = (bool)$profile->followersEtablissements->where('slug', $authUser->slug)->first() 
                        || (bool)$profile->followersUsers->where('slug', $authUser->slug)->first();
                    
                    // Count common followers
                    $profileFollowers = $profile->followersEtablissements->pluck('id');
                    $authUserFollowers = $authUser->followersEtablissements->pluck('id');
                    $commonFollowers = $profileFollowers->intersect($authUserFollowers)->count();
                }

                // Calculate priority score and set label
                if ($isFollowingYou && $youAreFollowing) {
                    $priorityScore = 4;
                    $priorityLabel = 'mutual_follow';
                }
                elseif ($isFollowingYou) {
                    $priorityScore = 3;  // They follow you
                    $priorityLabel = 'follows_you';
                }
                elseif ($youAreFollowing) {
                    $priorityScore = 2;  // You follow them
                    $priorityLabel = 'you_follow';
                }
                elseif ($commonFollowers > 0) {
                    $priorityScore = 1;
                    $priorityLabel = 'common_connection';
                }
                else {
                    $priorityScore = 0;
                    $priorityLabel = 'no_relationship';
                }

                // Add priority information to the profile
                $profile->priority_score = $priorityScore;
                $profile->priority_label = $priorityLabel;
                $profile->is_following_you = $isFollowingYou;
                $profile->you_are_following = $youAreFollowing;
                $profile->common_followers_count = $commonFollowers;

                return $profile;
            })->sortByDesc(function($profile) {
                // First sort by priority score
                // Then by score as secondary sort
                return [$profile->exact_match_count, $profile->priority_type_score, $profile->total_match_score, $profile->priority_score, $profile->score];
            });
        }, function($collection) {
            // Default sorting for non-authenticated users
            return $collection->map(function($profile) {
                // Add default priority info for non-auth users
                $profile->priority_score = 0;
                $profile->priority_label = 'no_relationship';
                $profile->is_following_you = false;
                $profile->you_are_following = false;
                $profile->common_followers_count = 0;
                return $profile;
            })->sortByDesc(function($profile) {
                // First sort by priority score
                // Then by score as secondary sort
                return [$profile->exact_match_count, $profile->priority_type_score, $profile->total_match_score, $profile->priority_score, $profile->score];
            });
        });

        if($numberWithoutPagination) {
            return $orderedResults->take($numberWithoutPagination)->values();
        }

        // Pagination
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 12;
        $currentItems = $orderedResults->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $currentItems,
            $orderedResults->count(),
            $perPage,
            $currentPage,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page'
            ]
        );

        $paginator->appends(request()->except('page'));
        
        return $paginator;
    }
}

if (!function_exists('isArabic')) {
    function isArabic($text) {
        // Regular expression to match Arabic characters
        $arabicRegex = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';
        
        return preg_match($arabicRegex, $text);
    }
}
