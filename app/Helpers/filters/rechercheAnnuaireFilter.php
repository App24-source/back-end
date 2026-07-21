<?php
use Carbon\Carbon;
use App\Models\Etablissement; 
use App\Models\Categorie_google; 
use App\Models\NadeSearch; 
use App\Models\Categorie;
use App\Models\Ville;
use App\Models\Region;


if (!function_exists('RechercheAnnuaireFilters')) {

    function RechercheAnnuaireFilters($boutiques, $request, $resetting) {
        // Obtenir les paramètres de la requête
        if($resetting == "city") $city = null;
        else $city = $request->query('city');

        if($resetting == "city") $villes = null;
        else $villes = $request->query('villes');

        if($resetting == "region" || $resetting == "city") $regions = null;
        else $regions = $request->query('regions');


        if ($villes) {
            $villes = json_decode($villes, true);
        }
        if ($regions) {
            $regions = json_decode($regions, true);
        }
        $categoriePhrase = $request->query('categorie');
        $searchPhrase = str_replace('-', ' ', $request->query('categorie'));
        // return $city;

        // Appliquer les filtres basés sur les paramètres de la requête
        if ($city !== null) {
            $boutiques->whereHas('ville', function ($q) use ($city) {
                $q->where('slug', $city);
            });
        } elseif ($villes) {
            $boutiques->whereHas('ville', function ($q) use ($villes) {
                $q->where(function ($q) use ($villes) {
                    foreach ($villes as $ville) {
                        $q->orWhere('slug', trim($ville));
                    }
                });
            });                
        }

        if ($regions) {
            $boutiques->whereHas('region', function ($q) use ($regions) {
                $q->where(function ($q) use ($regions) {
                    foreach ($regions as $region) {
                        $q->orWhere('slug', trim($region));
                    }
                });
            });
        }

        $returnResult = null;
        $testArray = [];
        
        if($categoriePhrase){
            
            $categorieObject = Categorie_google::where('slug', $categoriePhrase)->first();
            $returnResult = $city && $city !== "maroc";
            if ($categorieObject) {
                if($city && $city !== "maroc") {
                    $boutiquesFilter = $boutiques->get()->filter(function ($boutique) use ($categorieObject) {
                        $categories = json_decode($boutique->categories, true);
                        return is_array($categories) && in_array($categorieObject->name, $categories);
                    });
                }
                // while (!$returnResult) sleep(1);

                // increment the counter 
                $categorieObject->compteur += 1;
                $categorieObject->save();

                $boutiques = $boutiques->get()->filter(function ($boutique) use ($categorieObject) {
                    $categories = json_decode($boutique->categories, true);
                    return is_array($categories) && in_array($categorieObject->name, $categories);
                });
            } else {

                $boutiques = $boutiques->where(function($query) use ($searchPhrase) {
                    $query->whereRaw('LOWER(nom) LIKE ?', ["%{$searchPhrase}%"]);
                    })->get();
                if( count($boutiques) !== 0 || $resetting == "city") {

                    $cityId = null;
                    $regionId = null;
                    if ($city !== null) {
                        $cityId = Ville::where('slug', $city)->first()->id;
                    }
                    if($regions && count($regions) == 1) {
                        $regionId = Region::where('slug', $regions[0])->first()->id;
                    }
                    $ExistInCategories = Categorie::where('slug', $searchPhrase)->get();
                    $ExistInCategoriesGoogle = Categorie_google::where('slug', $searchPhrase)->get();
                    $ExistInSearch = NadeSearch::where('slug', $searchPhrase)->where('ville_id', $cityId)->where('region_id', $regionId)
                    ->where('origine', 'annuaire')->get();

                    if( count($ExistInCategories) > 0 || count($ExistInCategoriesGoogle) > 0 || count($ExistInSearch) > 0 ) {
                        if(count($ExistInSearch) > 0) {
                            $selectedSearch = $ExistInSearch[0];
                            $selectedSearch->compteur += 1;
                            $selectedSearch->updated_at = Carbon::now();
                            $selectedSearch->save();
                        }
                    } else {
                        $newSearch = new NadeSearch();
                        $newSearch->name = ucfirst($searchPhrase);
                        $newSearch->slug = $searchPhrase;
                        $newSearch->ville_id = $cityId;
                        $newSearch->region_id = $regionId;
                        $newSearch->status = 2;
                        $newSearch->origine = "annuaire";
                        $newSearch->save();
                    }
                    
                    // $boutiques = $boutiques->get();
                }
            }
        } else {
            $boutiques = $boutiques->get();
        }

        if( count($boutiques) !== 0 || $resetting == "city") {
            if($city) {
                $cityFilter = Ville::where('slug', $city)->first();
                if($cityFilter) {
                    $cityFilter->compteur_annuaire += 1;
                    $cityFilter->save();
                }
            }
            if($regions && count($regions) > 0) {
                $regionFilter = Region::where('slug', $regions[0])->first();
                if($regionFilter) {
                    $regionFilter->compteur_annuaire += 1;
                    $regionFilter->save();
                }
            }
        }


        // return $result;
        return [$boutiques, $resetting, $returnResult];
    }
}