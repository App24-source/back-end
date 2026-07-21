<?php
use App\Models\Categorie;
use App\Models\Categorie_google;
use App\Models\NadeSearch;
use App\Models\Ville;
use App\Models\Region;
use Carbon\Carbon;

if (!function_exists('filter')) {
    function filter($request, $query, $resetting) {
        
        
        // Get the query parameters
        if($resetting == "city") $city = null;
        else $city = $request->query('city');

        if($resetting == "city") $villes = null;
        else $villes = $request->query('villes');

        if($resetting == "region" || $resetting == "city") $regions = null;
        else $regions = $request->query('regions');

        $categorie = $request->query('categorie');

        $pmin = $request->query('pmin');
        $pmax = $request->query('pmax');
        $vendeur = $request->query('vendeur');

        $selectedSlug = $request->query('slug');

        $recherche = str_replace('-', ' ', $request->query('recherche'));
    
        if ($villes) {
            $villes = json_decode($villes, true);
        }
        if ($regions) {
            $regions = json_decode($regions, true);
        }
        if ($vendeur) {
            $vendeur = json_decode($vendeur, true);
        }

        // return $categorie;

        // Apply filters based on query parameters
        if ($city) {
            $query->whereHas('ville', function ($q) use ($city) {
                $q->where('slug', $city);
            });
        }
        if ($villes) {
            $query->whereHas('ville', function ($q) use ($villes) {
                $q->where(function ($q) use ($villes) {
                    foreach ($villes as $ville) {
                        $q->orWhere('slug', trim($ville));
                    }
                });
            });                
        }
        
        if($regions && $city !== null){
            $query->whereHas('region', function ($q) use ($regions) {
                $q->where(function ($q) use ($regions) {
                    foreach ($regions as $region) {
                        $q->orWhere('slug', trim($region));
                    }
                });
            });                
        }
        
        $categorieArray = [];
        $isEmploi = false;
        if ($categorie && $categorie !== "marketplace") {
            $categorieObject = Categorie::where('slug', $categorie)->first();
            if($categorieObject) {
                if($categorieObject->id2 == null && $categorieObject->id != 2) {
                    if($categorieObject->id == 200) $isEmploi = true;
                    $categorieArray = Categorie::where('id2', $categorieObject->id)->pluck('id')->toArray();
                } else if($categorieObject->id2 == null && $categorieObject->id == 2) {
                    $categorieArray = Categorie::whereIn(
                        'id2', 
                        Categorie::where('id2', $categorieObject->id)->pluck('id')->toArray()
                    )->pluck('id')->toArray();
                } else if($categorieObject->id2 !== null && $categorieObject->id2 == 2) {
                    $categorieArray = Categorie::where('id2', $categorieObject->id)->pluck('id')->toArray();
                } else {
                    if($categorieObject->id2 == 200) $isEmploi = true;
                    $categorieArray = [$categorieObject->id];
                }
            }
            if(count($categorieArray) > 0)
            $query->whereIn('cat_g_id', $categorieArray);
        }

        if ($pmin && $pmin !== "null" && $pmax && $pmax !== "null") {
            $query->whereBetween('prix', [$pmin, $pmax]);
        } elseif ($pmin && $pmin !== "null") {
            $query->where('prix', '>', $pmin);
        } elseif ($pmax && $pmax !== "null") {
            $query->where('prix', '<', $pmax);
        }

        
        if (is_array($vendeur) && count($vendeur) < 2 && in_array("professionnels", $vendeur)) {
            // Apply additional filtering for "professionnels"
            $query->wherenot('etab_id', null);
        }elseif(is_array($vendeur) && count($vendeur) < 2 && in_array("particuliers", $vendeur)){
            // Apply additional filtering for "particuliers"
            $query->where('etab_id', null);
        }

        
        $Categorie1= Categorie::where('slug', $categorie)->first(); // or use firstOrFail()
        $test = "true";
        if ($Categorie1) {
            // increment the counter 
            $Categorie1->compteur += 1;
            $Categorie1->save();

            $categorieId = 11;
            if($Categorie1->id2 != null)  $categorieId = $Categorie1->id2;
            else if($Categorie1->id != null) $categorieId = $Categorie1->id;
            switch ($categorieId) {
                case 1:
                    $query = VehiculeFilters($query, $request);
                    break;
                case 2:
                case 40:
                case 50:
                case 60:
                case 70:
                case 80:
                case 90:
                    $query = ImmobilierFilters($query, $request);
                    break;
                case 800:
                    $query = LoisirFilters($query, $request);
                    break;
                case 900:
                    $query = MaterielFilter($query, $request);
                    break;
                case 600:
                    $query = MaisonFilters($query, $request);
                    break;
                case 200:
                    $query = EmploiFilters($query, $request);
                    break;
                case 4:
                    $query = SupermarcheFilters($query, $request);
                    break;
            }
        }


        if($recherche && $recherche !== "null"){
            $query = RechercheFilters($query, $request);
            if( count($query) !== 0 || $resetting == "city") {
                
                $cityId = null;
                $regionId = null;
                $categoryId = null;
                if ($city !== null) {
                    $cityId = Ville::where('slug', $city)->first()->id;
                }
                if($regions && count($regions) == 1) {
                    $regionId = Region::where('slug', $regions[0])->first()->id;
                }
                if($categorie && $categorie != "marketplace") {
                    $categoryId = Categorie::where('slug', $categorie)->first()->id;
                }
                $ExistInCategories = Categorie::where('slug', $recherche)->get();
                $ExistInCategoriesGoogle = Categorie_google::where('slug', $recherche)->get();
                $ExistInSearch = NadeSearch::where('slug', $recherche)->where('ville_id', $cityId)->where('region_id', $regionId)
                ->where('origine', 'marketplace')->where('cat_g_id', $categoryId)->get();
                if( count($ExistInCategories) > 0 || count($ExistInCategoriesGoogle) > 0 || count($ExistInSearch) > 0 ) {
                    if(count($ExistInSearch) > 0) {
                        $selectedSearch = $ExistInSearch[0];
                        $selectedSearch->compteur = $selectedSearch->compteur + 1;
                        $selectedSearch->updated_at = Carbon::now();
                        $selectedSearch->save();
                    }
                } else {
                    $newSearch = new NadeSearch();
                    $newSearch->name = ucfirst($recherche);
                    $newSearch->slug = $recherche;
                    $newSearch->ville_id = $cityId;
                    $newSearch->region_id = $regionId;
                    $newSearch->cat_g_id = $categoryId;
                    $newSearch->status = 2;
                    $newSearch->origine = "marketplace";
                    $newSearch->save();
                }
            }
            
        } else {
            $query = $query->get();
        }

        if( count($query) !== 0 || $resetting == "city") {
            if($city) {
                $cityFilter = Ville::where('slug', $city)->first();
                if($cityFilter) {
                    $cityFilter->compteur_marketplace += 1;
                    $cityFilter->save();
                }
            }
            if($regions && count($regions) > 0) {
                $regionFilter = Region::where('slug', $regions[0])->first();
                if($regionFilter) {
                    $regionFilter->compteur_marketplace += 1;
                    $regionFilter->save();
                }
            }
        }

        return [$query, $isEmploi, $resetting];
    }
}