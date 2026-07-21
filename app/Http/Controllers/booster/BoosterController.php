<?php

namespace App\Http\Controllers\booster;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PublicationBooster;
use App\Models\Etablissement;
use App\Models\Publication;
use App\Models\User;
use Carbon\Carbon;
use App\Models\FacturationVrB;
use App\Models\Telephone;

class BoosterController extends Controller
{
    // public function store(Request $request)
    // {
    //     try {
    //         // Validate the request data
    //         $validatedData = $this->validateRequest($request);

    //         if (is_a($validatedData, '\Illuminate\Http\JsonResponse')) {
    //             return $validatedData; // Return validation error response if validation failed
    //         }

    //         // Check if the user exists
    //         $user = User::find($validatedData['user_id']);
    //         if (!$user) {
    //             return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
    //         }

    //         // Check if the publication exists
    //         $publication = Publication::find($validatedData['publication_id']);
    //         if (!$publication) {
    //             return response()->json(['message' => 'Publication non trouvée.'], 404);
    //         }

    //         // Create a new Booster record
    //         $booster = $this->createBooster($validatedData);

    //         // Handle relationship associations and get attached IDs
    //         $attachedIds = $this->attachRelatedData($booster, $validatedData);

    //         // Load the relationships for the response
    //         $booster->load(['tags', 'villes', 'regions', 'pays']);

    //         return response()->json([
    //             'message' => 'Booster créé avec succès',
    //             'status' => 200
    //         ], 201);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'message' => 'Une erreur est survenue lors de la création du booster',
    //             'error' => $e->getMessage(),
    //             'trace' => config('app.debug') ? $e->getTraceAsString() : null
    //         ], 500);
    //     }
    // }

    // private function validateRequest(Request $request)
    // {
    //     try {
    //         $validated = $request->validate([
    //             'name' => 'required',
    //             'action' => 'required',
    //             'value' => 'nullable',
    //             'montant' => 'required|numeric|min:0',
    //             'nbre_jours' => 'required|min:1',
    //             'publication_id' => 'required',
    //             'user_id' => 'required',
    //             'etab_id' => 'nullable',
    //             'type' => 'nullable',
    //             'type_paiement' => 'required|string|max:255',
    //             'type_booste_id' => 'required|integer',
    //             'ciblage_tags_id' => 'nullable',
    //             'ciblage_pays_id' => 'nullable',
    //             'ciblage_villes_id' => 'nullable',
    //             'ciblage_regions_id' => 'nullable',
    //             'message' => 'nullable',
    //         ]);

    //         // Convert string arrays to actual arrays
    //         foreach (['ciblage_tags_id', 'ciblage_villes_id', 'ciblage_regions_id','ciblage_pays_id'] as $field) {
    //             if (isset($validated[$field]) && !is_array($validated[$field])) {
    //                 $validated[$field] = json_decode($validated[$field], true);
    //             }
    //         }

    //         return $validated;
    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         return response()->json([
    //             'message' => 'Validation failed',
    //             'errors' => $e->validator->errors(),
    //             'request_data' => $request->all(),
    //         ], 422);
    //     }
    // }

    // private function createBooster(array $validatedData)
    // {
    //     $booster = new PublicationBooster();
    //     $booster->objectif_name = $validatedData['name'];
    //     $booster->objectif_action = $validatedData['action'];
    //     $booster->objectif_value = $validatedData['value'];
    //     // $booster->objectif_value = $validatedData['message'];
    //     $booster->montant = $validatedData['montant'];
    //     $booster->nbre_jours = $validatedData['nbre_jours'];
    //     $booster->publication_id = $validatedData['publication_id'];
    //     $booster->mode_paiement = 'solde';
    //     // $booster->type_booste_id = $validatedData['type_booste_id'];
    //     $booster->date_debut = now();
    //     $booster->date_fin = now()->addDays($validatedData['nbre_jours']);

    //     $booster->save();
    //     return $booster;
    // }

    // private function attachRelatedData(PublicationBooster $booster, array $validatedData)
    // {
    //     $attachedIds = [
    //         'tags' => [],
    //         'villes' => [],
    //         'regions' => [],
    //         'pays' => []
    //     ];

    //     // Convert string arrays to actual arrays if needed
    //     $tagsIds = isset($validatedData['ciblage_tags_id']) ? 
    //         (is_array($validatedData['ciblage_tags_id']) ? 
    //             $validatedData['ciblage_tags_id'] : 
    //             json_decode($validatedData['ciblage_tags_id'], true)) : 
    //         [];
    //         $paysIds = isset($validatedData['ciblage_pays_id']) ? 
    //         (is_array($validatedData['ciblage_pays_id']) ? 
    //             $validatedData['ciblage_pays_id'] : 
    //             json_decode($validatedData['ciblage_pays_id'], true)) : 
    //         [];
    //     $villesIds = isset($validatedData['ciblage_villes_id']) ? 
    //         (is_array($validatedData['ciblage_villes_id']) ? 
    //             $validatedData['ciblage_villes_id'] : 
    //             json_decode($validatedData['ciblage_villes_id'], true)) : 
    //         [];

    //     $regionsIds = isset($validatedData['ciblage_regions_id']) ? 
    //         (is_array($validatedData['ciblage_regions_id']) ? 
    //             $validatedData['ciblage_regions_id'] : 
    //             json_decode($validatedData['ciblage_regions_id'], true)) : 
    //         [];

    //     // Attach tags
    //     if (!empty($tagsIds) && is_array($tagsIds)) {
    //         foreach ($tagsIds as $tagId) {
    //             $booster->tags()->attach($tagId);
    //             $attachedIds['tags'][] = $tagId;
    //         }
    //     }
    //     if (!empty($paysIds) && is_array($paysIds)) {
    //         foreach ($paysIds as $paysId) {
    //             $booster->pays()->attach($paysId);
    //             $attachedIds['pays'][] = $paysId;
    //         }
    //     }

    //     // Attach villes
    //     if (!empty($villesIds) && is_array($villesIds)) {
    //         foreach ($villesIds as $villeId) {
    //             $booster->villes()->attach($villeId);
    //             $attachedIds['villes'][] = $villeId;
    //         }
    //     }

    //     // Attach regions
    //     if (!empty($regionsIds) && is_array($regionsIds)) {
    //         foreach ($regionsIds as $regionId) {
    //             $booster->regions()->attach($regionId);
    //             $attachedIds['regions'][] = $regionId;
    //         }
    //     }

    //     return $attachedIds;
    // }

    // private function createFacturation(array $validatedData, $userId, $etabId, $montant)
    // {
    //     $maxNumero = FacturationVrB::max('numero');
    //     $newNumero = $this->generateNewNumero($maxNumero);

    //     $facturation = new FacturationVrB();
    //     $facturation->numero = $newNumero;
    //     $facturation->product_id = $validatedData['type_booste_id'];
    //     $facturation->user_id = $userId;
    //     $facturation->etab_id = $etabId; // Set as needed
    //     $facturation->amount = $montant;
    //     $facturation->devise = 'DH'; // Default or get from request
    //     $facturation->billing_date = now();
    //     $facturation->duree = $validatedData['nbre_jours'];
    //     $facturation->paid = 2; // Default value from your schema
    //     $facturation->product_id = 5; // Default value from your schema
    //     // Map the type_paiement to one of the allowed enum values
    //     $facturation->mode_paiement = $this->mapPaymentMode($validatedData['type_paiement']);
    //     $facturation->created_at = now();
    //     $facturation->updated_at = now();

    //     $facturation->save();
    //     return $facturation;
    // }

    // private function generateNewNumero($maxNumero)
    // {
    //     if ($maxNumero && preg_match('/P(\d+)/', $maxNumero, $matches)) {
    //         $numberPart = (int)$matches[1] + 1;
    //         return 'P' . str_pad($numberPart, 6, '0', STR_PAD_LEFT);
    //     }
    //     return "P000001"; // Start from "P000001" if no previous reference
    // }

    // private function mapPaymentMode($typePaiement)
    // {
    //     $paymentModeMap = [
    //         'Global' => 'solde',
    //         'Carte' => 'carte_bancaire',
    //         'Virement' => 'virement_bancaire',
    //         // Add more mappings as needed
    //     ];

    //     return $paymentModeMap[$typePaiement] ?? 'solde'; // Default to 'solde' if no mapping exists
    // }





    public function store( Request $request )
    {
        try {
            // Validate the request data
            $validatedData = $this->validateRequest( $request );

            if (is_a( $validatedData, '\Illuminate\Http\JsonResponse' )) {
                return $validatedData; // Return validation error response if validation failed
            }

            // Check if the user exists
            $user = User::find( $validatedData['user_id'] );
            if (!$user) {
                return response()->json( ['message' => 'Utilisateur non trouvé.'], 404 );
            }

            // Check if the main publication exists
            $publication = Publication::find( $validatedData['publication_id'] );
            if (!$publication) {
                return response()->json( ['message' => 'Publication non trouvée.'], 404 );
            }

            // Check if selected publications exist (if provided)
            if (!empty( $validatedData['selected_publications'] )) {
                $existingPublications = Publication::whereIn( 'id', $validatedData['selected_publications'] )->count();
                if ($existingPublications !== count( $validatedData['selected_publications'] )) {
                    return response()->json( ['message' => 'Une ou plusieurs publications sélectionnées n\'existent pas.'], 404 );
                }
            }
            // if( $validatedData['value'] && ($validatedData['name'] == 'whatsapp' || $validatedData['name'] == 'tele')) {
            //     $user = User::find($validatedData['user_id']);


            //     if ($validatedData['etab_id']) {
            //         $etab = Etablissement::find($validatedData['etab_id']);
            //         $etab->telephones()->firstOrCreate(['telephone' => $validatedData['value']]);
            //     } else if($user) {
            //         $user->telephones()->firstOrCreate(['telephone' => $validatedData['value']]);
            //     }
            // }
            if ($validatedData['value'] && ( $validatedData['name'] == 'whatsapp' || $validatedData['name'] == 'tele' )) {
                $telePhone = Telephone::firstOrCreate( ['number' => $validatedData['value']] );
                $user = User::find( $validatedData['user_id'] );

                if ($validatedData['etab_id']) {
                    $etab = Etablissement::find( $validatedData['etab_id'] );
                    $etab->telephones()->syncWithoutDetaching( [$telePhone->id] ); // or attach()
                } elseif ($user) {
                    $user->telephones()->syncWithoutDetaching( [$telePhone->id] ); // or attach()
                }
            }
            // Create a new Booster record
            $booster = $this->createBooster( $validatedData );

            // Handle relationship associations and get attached IDs
            $attachedIds = $this->attachRelatedData( $booster, $validatedData );

            // Load the relationships for the response
            $booster->load( ['tags', 'villes', 'regions', 'pays', 'shopping_publications'] );

            return response()->json( [
                'message' => 'Booster créé avec succès',
                'status' => 200,
                'data' => $booster
            ], 201 );
        } catch (\Exception $e) {
            return response()->json( [
                'message' => 'Une erreur est survenue lors de la création du booster',
                'error' => $e->getMessage(),
                'trace' => config( 'app.debug' ) ? $e->getTraceAsString() : null
            ], 500 );
        }
    }

    private function validateRequest( Request $request )
    {
        try {
            $validated = $request->validate( [
                'name' => 'required',
                'action' => 'required',
                'value' => 'nullable',
                'montant' => 'required|numeric|min:0',
                // 'max_impressions' => 'required|numeric|min:0',
                'nbre_jours' => 'required|min:1',
                'publication_id' => 'required',
                'user_id' => 'required',
                'etab_id' => 'nullable',
                'type' => 'nullable',
                'type_paiement' => 'required|string|max:255',
                'type_booste_id' => 'required|integer',
                'ciblage_tags_id' => 'nullable',
                'ciblage_pays_id' => 'nullable',
                'ciblage_villes_id' => 'nullable',
                'ciblage_regions_id' => 'nullable',
                'message' => 'nullable',
                'selected_publications' => 'nullable', // New optional field
            ] );

            // Convert string arrays to actual arrays
            foreach ([
                'ciblage_tags_id',
                'ciblage_villes_id',
                'ciblage_regions_id',
                'ciblage_pays_id',
                'selected_publications' // Add this to the fields to be converted
            ] as $field) {
                if (isset( $validated[$field] ) && !is_array( $validated[$field] )) {
                    $validated[$field] = json_decode( $validated[$field], true ) ?? [];
                }
            }

            return $validated;
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json( [
                'message' => 'Validation failed',
                'errors' => $e->validator->errors(),
                'request_data' => $request->all(),
            ], 422 );
        }
    }

    private function createBooster( array $validatedData )
    {
        $booster = new PublicationBooster();
        $booster->objectif_name = $validatedData['name'];
        $booster->objectif_action = $validatedData['action'];
        $booster->objectif_value = $validatedData['value'];
        $booster->message = $validatedData['message'];
        $booster->montant = $validatedData['montant'];
        $booster->nbre_jours = $validatedData['nbre_jours'];
        $booster->publication_id = $validatedData['publication_id'];
        $booster->max_impressions = ( $validatedData['montant'] / $validatedData['nbre_jours'] ) * 100;
        $booster->mode_paiement = $validatedData['type_paiement'];
        $booster->date_debut = now();
        $booster->date_fin = now()->addDays( $validatedData['nbre_jours'] );

        $booster->save();
        return $booster;
    }

    private function attachRelatedData( PublicationBooster $booster, array $validatedData )
    {
        $attachedIds = [
            'tags' => [],
            'villes' => [],
            'regions' => [],
            'pays' => [],
            'shopping_publications' => []
        ];

        // Helper function to parse array fields
        $parseArrayField = function ( $fieldName ) use ( $validatedData ) {
            if (!isset( $validatedData[$fieldName] )) {
                return [];
            }
            return is_array( $validatedData[$fieldName] )
                ? $validatedData[$fieldName]
                : ( json_decode( $validatedData[$fieldName], true ) ?? [] );
        };

        // Attach tags
        $tagsIds = $parseArrayField( 'ciblage_tags_id' );
        foreach ($tagsIds as $tagId) {
            $booster->tags()->attach( $tagId );
            $attachedIds['tags'][] = $tagId;
        }

        // Attach pays
        $paysIds = $parseArrayField( 'ciblage_pays_id' );
        foreach ($paysIds as $paysId) {
            $booster->pays()->attach( $paysId );
            $attachedIds['pays'][] = $paysId;
        }

        // Attach villes
        $villesIds = $parseArrayField( 'ciblage_villes_id' );
        foreach ($villesIds as $villeId) {
            $booster->villes()->attach( $villeId );
            $attachedIds['villes'][] = $villeId;
        }

        // Attach regions
        $regionsIds = $parseArrayField( 'ciblage_regions_id' );
        foreach ($regionsIds as $regionId) {
            $booster->regions()->attach( $regionId );
            $attachedIds['regions'][] = $regionId;
        }

        // Attach selected publications (shopping publications)
        $selectedPublications = $parseArrayField( 'selected_publications' );
        foreach ($selectedPublications as $publicationId) {
            $booster->shopping_publications()->attach( $publicationId );
            $attachedIds['shopping_publications'][] = $publicationId;
        }

        return $attachedIds;
    }

    private function createFacturation( array $validatedData, $userId, $etabId, $montant )
    {
        $maxNumero = FacturationVrB::max( 'numero' );
        $newNumero = $this->generateNewNumero( $maxNumero );

        $facturation = new FacturationVrB();
        $facturation->numero = $newNumero;
        $facturation->product_id = $validatedData['type_booste_id'];
        $facturation->user_id = $userId;
        $facturation->etab_id = $etabId;
        $facturation->amount = $montant;
        $facturation->devise = 'DH';
        $facturation->billing_date = now();
        $facturation->duree = $validatedData['nbre_jours'];
        $facturation->paid = 2;
        $facturation->product_id = 2;
        $facturation->mode_paiement = $this->mapPaymentMode( $validatedData['type_paiement'] );
        $facturation->created_at = now();
        $facturation->updated_at = now();

        $facturation->save();
        return $facturation;
    }

    private function generateNewNumero( $maxNumero )
    {
        if ($maxNumero && preg_match( '/P(\d+)/', $maxNumero, $matches )) {
            $numberPart = (int) $matches[1] + 1;
            return 'P' . str_pad( $numberPart, 6, '0', STR_PAD_LEFT );
        }
        return "P000001";
    }

    private function mapPaymentMode( $typePaiement )
    {
        $paymentModeMap = [
            'Global' => 'solde',
            'Carte' => 'carte_bancaire',
            'Virement' => 'virement_bancaire',
        ];

        return $paymentModeMap[$typePaiement] ?? 'solde';
    }

    public function checkPhoneNumber( $number )
    {
        try {
            $telephone = Telephone::where( 'number', $number )
                ->with( 'users', 'etablissements' )
                ->first();



            // If the phone number doesn't exist, allow adding (true)
            if (!$telephone) {
                return response()->json( [
                    'can_add_more' => true
                ] );
            }

            $countAccounts = $telephone->users->count() + $telephone->etablissements->count();

            // If accounts < 10, allow adding (true), else block (false)
            return response()->json( [
                'can_add_more' => $countAccounts < 10
            ] );

        } catch (\Exception $e) {
            return response()->json( [
                'message' => 'Validation failed',
                'error' => $e->getMessage()
            ], 400 );
        }
    }

    public function getBoostedBySlug( Request $request )
    {
        try {
            $slug = $request->query( 'slug' );

            if (!$slug) {
                return response()->json( [
                    'message' => 'Slug parameter is required',
                    'status' => 400
                ], 400 );
            }

            // Get the publication by slug - don't specify columns to avoid SQL errors
            $publication = Publication::where( 'slug', $slug )
                ->with( [
                    'user:id,first_name,last_name,slug',
                    'etablissement:id,nom,slug',
                    'tags:id,name,slug',
                    'pays:id,name,slug',
                    'ville:id,name,slug',
                    'region:id,name,slug',
                    'publications_booster' => function ( $query ) {
                        $query->with( [
                            'tags:id,name,slug',
                            'villes:id,name,slug',
                            'regions:id,name,slug',
                            'pays:id,name,slug',
                            'shopping_publications:id,slug,image_default,image_square,prix,prixPromo,description,reference'
                        ] );
                    }
                ] )
                ->first();

            if (!$publication) {
                return response()->json( [
                    'message' => 'Publication not found',
                    'status' => 404
                ], 404 );
            }

            // Format the response similar to boosted_publications endpoint
            $formattedPublication = [
                'id' => $publication->id,
                'slug' => $publication->slug,
                'user_id' => $publication->user_id,
                'etab_id' => $publication->etab_id,
                'pays_id' => $publication->pays_id,
                'ville_id' => $publication->ville_id,
                'region_id' => $publication->region_id,
                'image_default' => $publication->image_default ?? null,
                'image_square' => $publication->image_square ?? null,
                'status' => $publication->status ?? 1,
                'created_at' => $publication->created_at ?? now(),
                'booster' => $publication->publications_booster->count(),
                'titre' => $publication->description ?? $publication->reference ?? '', // Use description or reference if titre doesn't exist
                'reference' => $publication->reference ?? '',
                'prix' => $publication->prix ?? '0',
                'prixPromo' => $publication->prixPromo ?? null,
                'user' => $publication->user ? [
                    'id' => $publication->user->id,
                    'first_name' => $publication->user->first_name ?? '',
                    'last_name' => $publication->user->last_name ?? '',
                    'slug' => $publication->user->slug,
                ] : null,
                'etablissement' => $publication->etablissement ? [
                    'id' => $publication->etablissement->id,
                    'nom' => $publication->etablissement->nom ?? '',
                    'slug' => $publication->etablissement->slug,
                ] : null,
                'tags' => $publication->tags->map( function ( $tag ) {
                    return [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ];
                } ),
                'pays' => $publication->pays ? [
                    'id' => $publication->pays->id,
                    'name' => $publication->pays->name,
                    'slug' => $publication->pays->slug,
                ] : null,
                'ville' => $publication->ville ? [
                    'id' => $publication->ville->id,
                    'name' => $publication->ville->name,
                    'slug' => $publication->ville->slug,
                ] : null,
                'region' => $publication->region ? [
                    'id' => $publication->region->id,
                    'name' => $publication->region->name,
                    'slug' => $publication->region->slug,
                ] : null,
                'booster_details' => $publication->publications_booster->map( function ( $booster ) {
                    return [
                        'id' => $booster->id,
                        'objectif_name' => $booster->objectif_name,
                        'objectif_action' => $booster->objectif_action,
                        'objectif_value' => $booster->objectif_value,
                        'message' => $booster->message,
                        'montant' => $booster->montant,
                        'nbre_jours' => $booster->nbre_jours,
                        'date_debut' => $booster->date_debut,
                        'date_fin' => $booster->date_fin,
                        'mode_paiement' => $booster->mode_paiement,
                        'max_impressions' => $booster->max_impressions,
                        'tags' => $booster->tags->map( function ( $tag ) {
                            return [
                                'id' => $tag->id,
                                'name' => $tag->name,
                                'slug' => $tag->slug,
                            ];
                        } ),
                        'villes' => $booster->villes->map( function ( $ville ) {
                            return [
                                'id' => $ville->id,
                                'name' => $ville->name,
                                'slug' => $ville->slug,
                            ];
                        } ),
                        'regions' => $booster->regions->map( function ( $region ) {
                            return [
                                'id' => $region->id,
                                'name' => $region->name,
                                'slug' => $region->slug,
                            ];
                        } ),
                        'pays' => $booster->pays->map( function ( $pays ) {
                            return [
                                'id' => $pays->id,
                                'name' => $pays->name,
                                'slug' => $pays->slug,
                            ];
                        } ),
                        'shopping_publications' => $booster->shopping_publications->map( function ( $pub ) {
                            return [
                                'id' => $pub->id,
                                'slug' => $pub->slug,
                                'image_default' => $pub->image_default,
                                'image_square' => $pub->image_square,
                                'titre' => $pub->description ?? $pub->reference ?? '', // Use description or reference if titre doesn't exist
                                'prix' => $pub->prix,
                                'prixPromo' => $pub->prixPromo,
                            ];
                        } ),
                        'shopping_catalogue' => $booster->shopping_publications->isNotEmpty(),
                    ];
                } ),
            ];

            return response()->json( [
                'message' => 'Boosted publication retrieved successfully',
                'status' => 200,
                'data' => $formattedPublication
            ], 200 );

        } catch (\Exception $e) {
            return response()->json( [
                'message' => 'An error occurred while retrieving the boosted publication',
                'error' => $e->getMessage(),
                'trace' => config( 'app.debug' ) ? $e->getTraceAsString() : null
            ], 500 );
        }
    }
}