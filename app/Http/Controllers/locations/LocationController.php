<?php

namespace App\Http\Controllers\locations;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pays;
use App\Models\Ville;
use App\Models\Region;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;
use App\Models\Tags;
 
class LocationController extends Controller
{
    public function pays(Request $request) {
        // try {
        //     $pays = Pays::select('id', 'name', 'slug', 'compteur_publications')->where('status', 1)->get();
        //     return response()->json(['status' => 201, 'data' => $pays]);
        
        // } catch (\Exception $e) {
        //     return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        // }
        $searchTerm = $request->query('search');
        try {
            // Build the base query to get pays with 'status' 1
            $query = Pays::select('id', 'name', 'slug', 'compteur_search', 'compteur_publications')
            ->where('status', 1);

            // Apply the search filter if a search term is provided
            if ($searchTerm) {
                // Ensure we slugify the input so "Fès" becomes "fes" to match the database slug
                $slugifiedSearchTerm = \Illuminate\Support\Str::slug(trim($searchTerm));
                $query->where('slug', 'like', '%' . $slugifiedSearchTerm . '%');
            }

            // Fetch the pays with the search filter applied
            $pays = $query->orderBy('compteur_publications', 'desc')
                    ->limit(5)
                    ->get();

            // If no pays are found, remove the search filter and fetch all pays
            if ($pays->isEmpty() && $searchTerm) {
            $pays = Pays::select('id', 'name', 'slug', 'compteur_search', 'compteur_publications')
                        ->where('status', 1)
                        ->orderBy('compteur_publications', 'desc')
                        ->limit(5)
                        ->get();
            }

            // Return the response with the fetched data
            return response()->json(['status' => 201, 'data' => $pays]);
        
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }

    public function villes(Request $request, $pays) {
        $searchTerm = $request->query('search');
        // return json_decode($pays, true);
        try {
            // Build the base query to get villes with 'status' 1
            $query = Ville::select('id', 'name', 'slug', 'pays_id', 'compteur_search', 'compteur_publications')
                ->where('status', 1)
                ->whereIn('pays_id', json_decode($pays, true));
            
            // Apply the search filter if a search term is provided
            if ($searchTerm) {
                // Ensure we slugify the input so "Fès" becomes "fes" to match the database slug
                $slugifiedSearchTerm = \Illuminate\Support\Str::slug(trim($searchTerm));
                $query->where('slug', 'like', '%' . $slugifiedSearchTerm . '%');
            }
    
            // Fetch the villes with the search filter applied
            $villes = $query->orderBy('compteur_publications', 'desc')
                            ->limit(5)
                            ->get();
            
            // If no villes are found, remove the search filter and fetch all villes
            if ($villes->isEmpty() && $searchTerm) {
                // Fetch all villes without search term but with status 1
                $villes = Ville::select('id', 'name', 'slug', 'compteur_search', 'compteur_publications')
                            ->where('status', 1)
                            ->whereIn('pays_id', json_decode($pays, true))
                            ->orderBy('compteur_publications', 'desc')
                            ->limit(5)
                            ->get();
            }
    
            // Return the response with the fetched data
            return response()->json(['status' => 201, 'data' => $villes]);
    
        } catch (\Exception $e) {
            // Handle any exceptions and return a proper error response
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }
    

    public function regions(Request $request, $villes) {

        $searchTerm = $request->query('search');
        try {
            // Build the base query to get regions with 'status' 1
            $query = Region::select('id', 'name', 'slug', 'compteur_search', 'compteur_publications')
            ->where('status', 1)
            ->where('slug', '!=', 'toute-la-ville')
            ->whereIn('ville_id', json_decode($villes, true));

            // Apply the search filter if a search term is provided
            if ($searchTerm) {
                // Ensure we slugify the input so "Fès" becomes "fes" to match the database slug
                $slugifiedSearchTerm = \Illuminate\Support\Str::slug(trim($searchTerm));
                $query->where('slug', 'like', '%' . $slugifiedSearchTerm . '%');
            }

            // Fetch the regions with the search filter applied
            $regions = $query->orderBy('compteur_publications', 'desc')
                    ->limit(5)
                    ->get();

            // If no regions are found, remove the search filter and fetch all regions
            if ($regions->isEmpty() && $searchTerm) {
            $regions = Region::select('id', 'name', 'slug', 'compteur_search','compteur_publications')
                        ->where('status', 1)
                        ->where('slug', '!=', 'toute-la-ville')
                        ->whereIn('ville_id', json_decode($villes, true))
                        ->orderBy('compteur_publications', 'desc')
                        ->limit(5)
                        ->get();
            }

            // Return the response with the fetched data
            return response()->json(['status' => 201, 'data' => $regions]);
        
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }

    public function VerifyLocations(Request $request) {
        $locations = $request->get('location');
        $searchSlug = $request->get('base');

        $searchTagName = null;
        if($searchSlug && $searchSlug !== "") {
            $tag = Tags::where('slug', $searchSlug)->first();
            if($tag) {
                $searchTagName = $tag->name;
            }
        }


        if(!is_array($locations) || empty($locations)) {
            return response()->json([
                'success' => true,
                'pays'    => null,
                'ville'   => null,
                'region'  => null,
                'searchTag'  => $searchTagName
            ], 200);
        }

        if (count($locations) > 3) {
            return response()->json(['success' => false, 'searchTag'  => $searchTagName], 400);
        }
        

        try {

            $pays = isset($locations[0])
                ? Pays::select('id', 'slug', 'name')->where('slug', $locations[0])->first()
                : null;

            if (isset($locations[0]) && !$pays) {
                throw new ModelNotFoundException();
            }

            $ville = isset($locations[1])
                ? Ville::select('id', 'slug', 'name')->where('slug', $locations[1])->where('pays_id', $pays->id)->first()
                : null;

            if (isset($locations[1]) && !$ville) {
                throw new ModelNotFoundException();
            }

            $region = isset($locations[2])
                ? Region::select('id', 'slug', 'name')->where('slug', $locations[2])->where('ville_id', $ville->id ?? null)->first()
                : null;

            if (isset($locations[2]) && !$region) {
                throw new ModelNotFoundException();
            }

            return response()->json([
                'success' => true,
                'pays'    => $pays,
                'ville'   => $ville,
                'region'  => $region,
                'searchTag'  => $searchTagName
            ], 200);

        } catch (ModelNotFoundException) {
            return response()->json(['success' => false], 404);
        } catch (Throwable $e) {
            return response()->json(['success' => false], 500);
        }
    }



}
