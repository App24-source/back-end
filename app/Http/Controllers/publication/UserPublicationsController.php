<?php

namespace App\Http\Controllers\publication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Publication;
use App\Models\User;
use App\Models\Etablissement;
use Carbon\Carbon;
use App\Models\PublicationBooster;


class UserPublicationsController extends Controller
{
    public function index($slug){
        try{
            $user = User::where('slug', $slug)->first();
            $etablissement = Etablissement::where('slug', $slug)->first();
    
            if(!$user && !$etablissement) {
                return response()->json(['status' => 404, 'message' => "No profile found"]);
            }
            $query = Publication::select('id', 'slug', 'user_id', 'etab_id', 'pays_id', 'ville_id', 'region_id', 'image_default', 'status', 'created_at','reference','prix','is_marketplace')
                ->withCount([
                'publications_booster as booster' => function ($query) {
                    $query->where('date_fin', '>', Carbon::now());
                }
            ])->with([
                'user:id,slug,first_name,last_name,avatar,consommation_solde',
                'etablissement:id,slug,nom,logo,consommation_solde',
                'region:id,name,slug',
                'tags:id,name,slug',
                'ville:id,name,slug',
            ]);
            if($user) {
                $query->where('user_id', $user->id)->where('etab_id', null);
            } else if($etablissement) {
                $query->where('etab_id', $etablissement->id);
            }
            
            // Add this line to order by created_at in descending order
            $query->orderBy('created_at', 'desc');
            

              // Apply marketplace filter if requested
        if (request()->boolean('isMarketplace')) {
            $query->where('is_marketplace', 1);
        }
        
            $publications = $query->get();
    
            return response()->json(['status' => 201, 'data' => $publications]);
        } catch (\Exception $e) {
            // Handle any errors that occur during the query
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }

    
    // public function getBoostedPublications(Request $request)
    // {
    //     $slug = $request->query('slug');
        
    //     try {
    //         if (!$slug || trim($slug) === "") {
    //             return response()->json(['status' => 400, 'message' => "Slug parameter is required."], 400);
    //         }
            
    //         $user = User::where('slug', $slug)->first();
    //         $etablissement = Etablissement::where('slug', $slug)->first();
            
    //         if (!$user && !$etablissement) {
    //             return response()->json(['status' => 404, 'message' => "No profile found"], 404);
    //         }
            
    //         // Get publications IDs based on user or establishment
    //         $query = Publication::query();
            
    //         if ($user) {
    //             $query->where('user_id', $user->id)->whereNull('etab_id');
    //         } elseif ($etablissement) {
    //             $query->where('etab_id', $etablissement->id);
    //         }
            
    //         $publicationIds = $query->pluck('id')->toArray();
            
    //         // Use the PublicationBooster model to get active boosted publications
    //         $boostedPublications = PublicationBooster::whereIn('publication_id', $publicationIds)
    //             ->where('date_fin', '>', Carbon::now())
    //             ->with([
    //                 'publication' => function($query) {
    //                     $query->with([
    //                         'user:id',
    //                         'etablissement:id',
    //                         'region:id,name,slug',
    //                         'ville:id,name,slug'
    //                     ]);
    //                 }
    //             ])
    //             ->orderBy('created_at', 'desc')
    //             ->get();
                
    //         // Group boosters by publication ID and slug
    //         $groupedPublications = [];
            
    //         foreach ($boostedPublications as $booster) {
    //             $publication = $booster->publication;
                
    //             if (!$publication) {
    //                 continue;
    //             }
                
    //             $key = $publication->id . '-' . $publication->slug;
                
    //             if (!isset($groupedPublications[$key])) {
    //                 $groupedPublications[$key] = [
    //                     'id' => $publication->id,
    //                     'slug' => $publication->slug,
    //                     'user_id' => $publication->user_id,
    //                     'etab_id' => $publication->etab_id,
    //                     'pays_id' => $publication->pays_id,
    //                     'ville_id' => $publication->ville_id,
    //                     'region_id' => $publication->region_id,
    //                     'image_default' => $publication->image_default,
    //                     'status' => $publication->status,
    //                     'created_at' => $publication->created_at,
    //                     'booster' => 1,
    //                     'booster_details' => [],
    //                     'region' => $publication->region,
    //                     'ville' => $publication->ville
    //                 ];
    //             }
                
    //             // Add booster details to the array
    //             $groupedPublications[$key]['booster_details'][] = [
    //                 'id' => $booster->id,
    //                 'montant' => $booster->montant,
    //                 'nbre_jours' => $booster->nbre_jours,
    //                 'type' => $booster->type,
    //                 'date_debut' => $booster->date_debut,
    //                 'date_fin' => $booster->date_fin,
    //                 'mode_paiement' => $booster->mode_paiement,
    //                 'objectif_name' => $booster->objectif_name,
    //                 'objectif_action' => $booster->objectif_action,
    //                 'objectif_value' => $booster->objectif_value,
    //                 'status' => $booster->status
    //             ];
    //         }
            
    //         // Sort booster_details arrays by date_debut for each publication
    //         foreach ($groupedPublications as &$publication) {
    //             usort($publication['booster_details'], function($a, $b) {
    //                 return strtotime($b['date_debut']) - strtotime($a['date_debut']);
    //             });
    //         }
            
    //         // Convert to indexed array
    //         $formattedData = array_values($groupedPublications);
            
    //         return response()->json([
    //             'status' => 200, 
    //             'data' => $formattedData,
    //             'user_id' => $user ? $user->id : null,
    //             'etab_id' => $etablissement ? $etablissement->id : null
    //         ]);
            
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 500,
    //             'error' => 'An error occurred: ' . $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ], 500);
    //     }
    // }

    public function getBoostedPublications(Request $request) {
        $slug = $request->query('slug');
        
        try {
            if (!$slug || trim($slug) === "") {
                return response()->json(['status' => 400, 'message' => "Slug parameter is required."], 400);
            }
            
            $user = User::where('slug', $slug)->first();
            $etablissement = Etablissement::where('slug', $slug)->first();
            
            if (!$user && !$etablissement) {
                return response()->json(['status' => 404, 'message' => "No profile found"], 404);
            }
            
            // Get publications IDs based on user or establishment
            $query = Publication::query();
            
            if ($user) {
                $query->where('user_id', $user->id)->whereNull('etab_id');
            } elseif ($etablissement) {
                $query->where('etab_id', $etablissement->id);
            }
            
            $publicationIds = $query->pluck('id')->toArray();
            
            // Get active boosted publications with their shopping publications
            $boostedPublications = PublicationBooster::whereIn('publication_id', $publicationIds)
                ->where('date_fin', '>', Carbon::now())
                ->with([
                    'publication' => function($query) {
                        $query->with([
                            'user:id',
                            'etablissement:id',
                            'region:id,name,slug',
                            'ville:id,name,slug'
                        ]);
                    },
                    'shopping_publications:id,slug,image_default' // Load shopping publications for each boost
                ])
                ->orderBy('created_at', 'desc')
                ->get();
                
            // Group boosters by publication ID and slug
            $groupedPublications = [];
            
            foreach ($boostedPublications as $booster) {
                $publication = $booster->publication;
                
                if (!$publication) {
                    continue;
                }
                
                $key = $publication->id . '-' . $publication->slug;
                
                if (!isset($groupedPublications[$key])) {
                    $groupedPublications[$key] = [
                        'id' => $publication->id,
                        'slug' => $publication->slug,
                        'user_id' => $publication->user_id,
                        'etab_id' => $publication->etab_id,
                        'pays_id' => $publication->pays_id,
                        'ville_id' => $publication->ville_id,
                        'region_id' => $publication->region_id,
                        'image_default' => $publication->image_default,
                        'status' => $publication->status,
                        'created_at' => $publication->created_at,
                        'booster' => 1,
                        'booster_details' => [],
                        'region' => $publication->region,
                        'ville' => $publication->ville,
                        'reference' => $publication->reference,
                        'prix' => $publication->prix,
                        'prixPromo' => $publication->prixPromo,
                        'tags' => $publication->tags
                    ];
                }
                
                // Create boost details with shopping catalogue
                $boostDetails = [
                    'id' => $booster->id,
                    'montant' => $booster->montant,
                    'nbre_jours' => $booster->nbre_jours,
                    'type' => $booster->type,
                    'date_debut' => $booster->date_debut,
                    'date_fin' => $booster->date_fin,
                    'mode_paiement' => $booster->mode_paiement,
                    'objectif_name' => $booster->objectif_name,
                    'objectif_action' => $booster->objectif_action,
                    'objectif_value' => $booster->objectif_value,
                    'status' => $booster->status,
                    'message' => $booster->message,
                    'shopping_catalogue' => [] // Initialize empty array
                ];
                
                // Add shopping publications if they exist for this boost
                if ($booster->relationLoaded('shopping_publications') && $booster->shopping_publications->isNotEmpty()) {
                    $boostDetails['shopping_catalogue'] = $booster->shopping_publications->map(function($pub) {
                        return [
                            'id' => $pub->id,
                            'slug' => $pub->slug,
                            'image_default' => $pub->image_default
                        ];
                    })->toArray();
                }
                
                $groupedPublications[$key]['booster_details'][] = $boostDetails;
            }
            
            // Sort booster_details by date_debut (newest first)
            foreach ($groupedPublications as &$publication) {
                usort($publication['booster_details'], function($a, $b) {
                    return strtotime($b['date_debut']) - strtotime($a['date_debut']);
                });
            }
            
            // Convert to indexed array
            $formattedData = array_values($groupedPublications);
            
            return response()->json([
                'status' => 200, 
                'data' => $formattedData,
                'user_id' => $user ? $user->id : null,
                'etab_id' => $etablissement ? $etablissement->id : null
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'error' => 'An error occurred: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

}
