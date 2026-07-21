<?php

namespace App\Http\Controllers\facturation;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FacturationVrB;
use App\Models\Coupon;
use App\Models\Etablissement;
use App\Models\User;
use App\Models\TransactionPoint;
use App\Models\Produit;

class FacturationController extends Controller
{
    // Recapitulatif
    public function getRecapitulatifFacturation(Request $request, $userEtabId, $type)
    {
        try {
            // Start building the query with necessary relationships
            $query = FacturationVrB::with(['product', 'user', 'etablissement']); // Fetch all factures except unpaid ones

            // Apply type filter: 'etablissement' or 'user'
            if ($type === 'establishment') {
                $query->where('etab_id', $userEtabId);
            } elseif ($type === 'user') {
                $query->where('user_id', $userEtabId)->where('etab_id', null);
            } else {
                return response()->json(['error' => 'Invalid type'], 400);
            }

            // Apply date filters if provided
            if ($request->filled('startDate')) {
                $query->whereDate('created_at', '>=', $request->input('startDate'));
            }

            if ($request->filled('endDate')) {
                $query->whereDate('created_at', '<=', $request->input('endDate'));
            }

            // Apply payment status filters (accepte, enCours, refuser)
            $paidFilters = [];
            if ($request->boolean('accepte')) {
                $paidFilters[] = 1; // paid == 1
            }
            if ($request->boolean('enCours')) {
                $paidFilters[] = 2; // paid == 2
            }
            if ($request->boolean('refuser')) {
                $paidFilters[] = 0; // paid == 0
            }
            if (!empty($paidFilters)) {
                $query->whereIn('paid', $paidFilters);
            }

            // Apply product type filters (achatSold, publicite)
            if ($request->boolean('achatSold') && !$request->boolean('publicite')) {
                $query->whereHas('product', function ($q) {
                    $q->where('name', '!=', 'publicite');
                });
            } elseif (!$request->boolean('achatSold') && $request->boolean('publicite')) {
                $query->whereHas('product', function ($q) {
                    $q->where('name', '=', 'publicite');
                });
            }

            // Apply payment method filters (virementBancaire, carteBancaire, solde)
            $paymentMethods = [];
            if ($request->boolean('virementBancaire')) {
                $paymentMethods[] = 'virement_bancaire';
            }
            if ($request->boolean('carteBancaire')) {
                $paymentMethods[] = 'carte_bancaire';
            }
            if ($request->boolean('solde')) {
                $paymentMethods[] = 'solde';
            }
            if (!empty($paymentMethods)) {
                $query->whereIn('mode_paiement', $paymentMethods);
            }

            // Sort the results by created_at from newest to oldest
            $query->orderBy('created_at', 'desc');

            // Paginate the results
            $facturations = $query->paginate(6);

            // Map and format the data
            $facturationSummary = $facturations->map(function ($facturation) {
                return [
                    'id' => $facturation->id,
                    'numero' => $facturation->numero,
                    'amount' => $facturation->amount,
                    'paid' => $facturation->paid,
                    'devise' => $facturation->devise,
                    'duree' => $facturation->duree,
                    'mode_paiement' => $facturation->mode_paiement,
                    'billing_date' => $facturation->billing_date,
                    'created_at' => $facturation->created_at->toDateTimeString(),
                    'product' => [
                        'id' => optional($facturation->product)->id,
                        'name' => optional($facturation->product)->name,
                        'prix' => optional($facturation->product)->prix,
                        'prix_promo' => optional($facturation->product)->prix_promo,
                        'status' => optional($facturation->product)->status,
                    ],
                    'user' => [
                        'id' => optional($facturation->user)->id,
                        'first_name' => optional($facturation->user)->first_name,
                        'last_name' => optional($facturation->user)->last_name,
                        'email' => optional($facturation->user)->email,
                        'tele' => optional($facturation->user)->tele,
                    ],
                    'etablissement' => [
                        'id' => optional($facturation->etablissement)->id,
                        'nom' => optional($facturation->etablissement)->nom,
                    ],
                ];
            });

            // Return success response with paginated and filtered data
            return response()->json([
                'data' => $facturationSummary,
                'pagination' => [
                    'current_page' => $facturations->currentPage(),
                    'last_page' => $facturations->lastPage(),
                    'per_page' => $facturations->perPage(),
                    'total' => $facturations->total(),
                ],
                'filters' => [
                    'start_date' => $request->input('startDate'),
                    'end_date' => $request->input('endDate'),
                    'accepte' => $request->boolean('accepte'),
                    'enCours' => $request->boolean('enCours'),
                    'refuser' => $request->boolean('refuser'),
                    'achatSold' => $request->boolean('achatSold'),
                    'publicite' => $request->boolean('publicite'),
                    'virementBancaire' => $request->boolean('virementBancaire'),
                    'carteBancaire' => $request->boolean('carteBancaire'),
                    'solde' => $request->boolean('solde'),
                ]
            ], 200);

        } catch (\Exception $e) {
            // Handle exceptions and return error response
            return response()->json([
                'error' => 'An error occurred while fetching the data',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    //-------------------------------------->

    // Subscriptions
    public function getSubscriptionsFacturation(Request $request, $userEtabId, $type)
    {
        try {
            // Start building the query with necessary relationships
            $query = FacturationVrB::with(['plan.produit', 'user', 'etablissement', 'plan']);

            // Apply type filter: 'etablissement' or 'user'
            if ($type === 'establishment') {
                $query->where('etab_id', $userEtabId);
            } elseif ($type === 'user') {
                $query->where('user_id', $userEtabId);
            } else {
                return response()->json(['error' => 'Invalid type'], 400);
            }

            // Apply date filters if provided
            if ($request->filled('startBillingDate')) {
                $query->whereDate('billing_date', '>=', $request->input('startBillingDate'));
            }

            if ($request->filled('endBillingDate')) {
                $query->whereDate('billing_date', '<=', $request->input('endBillingDate'));
            }

            if ($request->filled('startExpireDate')) {
                $query->whereDate('expire_date', '>=', $request->input('startExpireDate'));
            }

            if ($request->filled('endExpireDate')) {
                $query->whereDate('expire_date', '<=', $request->input('endExpireDate'));
            }

            // Apply multiple product filters dynamically
            $filters = [
                'smartlink' => 'smartlink',
                'boutique' => 'boutique',
            ];

            $query->where(function ($subQuery) use ($filters, $request) {
                foreach ($filters as $filterKey => $filterValue) {
                    if ($request->boolean($filterKey)) {
                        $subQuery->orWhereHas('plan.produit', function ($productQuery) use ($filterValue) {
                            $productQuery->where('name', 'LIKE', '%' . $filterValue . '%');
                        });
                    }
                }
            });

            // Exclude factures with product names "boost" or "points"
            // $query->whereDoesntHave('plan.produit', function ($productQuery) {
            //     $productQuery->whereIn('name', ['booster', 'points']);
            // });

            // Apply filtering based on Active, Expire, and enCours
            $query->where(function ($subQuery) use ($request) {
                $currentDate = now();

                if ($request->boolean('Active')) {
                    $subQuery->orWhere(function ($q) use ($currentDate) {
                        $q->where('paid', 1)->whereDate('expire_date', '>=', $currentDate);
                    });
                }

                if ($request->boolean('Expire')) {
                    $subQuery->orWhere(function ($q) use ($currentDate) {
                        $q->where('paid', 1)->whereDate('expire_date', '<', $currentDate);
                    });
                }

                if ($request->boolean('enCours')) {
                    $subQuery->orWhere('paid', 0);
                }
            });

            // Sort the results by created_at from newest to oldest
            $query->orderBy('created_at', 'desc');

            // Paginate the results
            $facturations = $query->paginate(6);

            // Map and format the data
            $facturationSummary = $facturations->map(function ($facturation) {
                return [
                    'id' => $facturation->id,
                    'numero' => $facturation->numero,
                    'paid' => $facturation->paid,
                    'amount' => $facturation->amount,
                    'created_at' => $facturation->created_at->toDateTimeString(),
                    'billing_date' => $facturation->billing_date,
                    'expire_date' => $facturation->expire_date,
                    'devise' => $facturation->devise,
                    'product' => [
                        'id' => optional($facturation->plan->produit)->id,
                        'name' => optional($facturation->plan->produit)->name,
                        'plan' => [
                            'id' => optional($facturation->plan)->id,
                            'name' => optional($facturation->plan)->name,
                            'duration_value' => optional($facturation->plan)->duration_value,
                            'duration_type' => optional($facturation->plan)->duration_type,
                            'price' => optional($facturation->plan)->price,
                        ],
                    ],
                    'user' => [
                        'id' => optional($facturation->user)->id,
                        'first_name' => optional($facturation->user)->first_name,
                        'last_name' => optional($facturation->user)->last_name,
                        'email' => optional($facturation->user)->email,
                        'tele' => optional($facturation->user)->tele,
                    ],
                    'etablissement' => [
                        'id' => optional($facturation->etablissement)->id,
                        'nom' => optional($facturation->etablissement)->nom,
                    ],
                ];
            });

            // Return success response with paginated and filtered data
            return response()->json([
                'data' => $facturationSummary,
                'pagination' => [
                    'current_page' => $facturations->currentPage(),
                    'last_page' => $facturations->lastPage(),
                    'per_page' => $facturations->perPage(),
                    'total' => $facturations->total(),
                ],
                'filters' => [
                    'start_billing_date' => $request->input('startBillingDate'),
                    'end_billing_date' => $request->input('endBillingDate'),
                    'start_expire_date' => $request->input('startExpireDate'),
                    'end_expire_date' => $request->input('endExpireDate'),
                    'smartlink' => $request->boolean('smartlink'),
                    'boutique' => $request->boolean('boutique'),
                    'Active' => $request->boolean('Active'),
                    'Expire' => $request->boolean('Expire'),
                    'enCours' => $request->boolean('enCours'),
                ]
            ], 200);

        } catch (\Exception $e) {
            // Handle exceptions and return error response
            return response()->json([
                'error' => 'An error occurred while fetching the data',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Get Coupons
    public function getCoupons(Request $request, $userEtabId, $type)
    {
        try {
            // Start building the query with necessary relationships
            $query = Coupon::with(['user', 'etablissement']);

            // Apply type filter: 'etablissement' or 'user'
            if ($type === 'establishment') {
                $query->where('etab_id', $userEtabId);
            } elseif ($type === 'user') {
                $query->where('user_id', $userEtabId);
            } else {
                return response()->json(['error' => 'Invalid type'], 400);
            }

            // Sort the results by apply_date from newest to oldest
            $query->orderBy('apply_date', 'desc');

            // Paginate the results
            $coupons = $query->paginate(6);

            // Map and format the data
            $couponSummary = $coupons->map(function ($coupon) {
                return [
                    'id' => $coupon->id,
                    'promotion' => $coupon->promotion,
                    'code' => $coupon->code,
                    'solde' => $coupon->solde,
                    'etat' => $coupon->etat,
                    'etab_id' => $coupon->etab_id,
                    'user_id' => $coupon->user_id,
                    'date_exp' => $coupon->date_exp,
                    'apply_date' => $coupon->apply_date,
                    'created_at' => $coupon->created_at->toDateTimeString(),
                    'user' => [
                        'id' => optional($coupon->user)->id,
                        'first_name' => optional($coupon->user)->first_name,
                        'last_name' => optional($coupon->user)->last_name,
                        'email' => optional($coupon->user)->email,
                        'tele' => optional($coupon->user)->tele,
                        'consommation_solde' => optional($coupon->user)->consommation_solde
                    ],
                    'etablissement' => [
                        'id' => optional($coupon->etablissement)->id,
                        'nom' => optional($coupon->etablissement)->nom,
                        'consommation_solde' => optional($coupon->etablissement)->consommation_solde
                    ],
                ];
            });

            // Return success response with paginated and filtered data
            return response()->json([
                'data' => $couponSummary,
                'pagination' => [
                    'current_page' => $coupons->currentPage(),
                    'last_page' => $coupons->lastPage(),
                    'per_page' => $coupons->perPage(),
                    'total' => $coupons->total(),
                ],
                
            ], 200);

        } catch (\Exception $e) {
            // Handle exceptions and return error response
            return response()->json([
                'error' => 'An error occurred while fetching the data',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Apply Coupon
    public function couponApply(Request $request)
    {
        try {
            // Extract inputs from the request
            $userId = $request->input('userId');
            $etabId = $request->input('etabId') ?? null;
            $type = $request->input('type');
            $couponCode = $request->input('couponCode');
            // Find the Annonce by ID
            $user = User::findOrFail($userId);
            $etab = $etabId? Etablissement::findOrFail($etabId):null;

            // Fetch the coupon using the provided code
            $coupon = Coupon::where('code', $couponCode)->first();

            // Handle the case where the coupon does not exist
            if (!$coupon) {
                return response()->json([
                    'error' => 'Coupon not found',
                    'status' => false,
                ], 404);
            }

            // Get the current date and time
            $currentDate = now();

            // Check if the coupon is active and not expired
            if ($coupon->etat !== 1 || $coupon->date_exp < $currentDate) {
                return response()->json([
                    'error' => 'Coupon is either inactive or expired',
                    'status' => false,
                ], 400);
            }

            // Determine if the coupon is applicable
            $isUnassigned = is_null($coupon->user_id) && is_null($coupon->etab_id);
            $isUserMatch = $coupon->user_id == $userId;
            $isEtabMatch = $coupon->etab_id == $etabId;

            if ($isUnassigned || $isUserMatch || $isEtabMatch) {
                if ($type === "establishment") {
                   // Increment etablissment sold
                   $etab->consommation_solde += $coupon->solde;
                   $etab->save();

                   // Update coupon as applied
                    $coupon->update([
                        'etat' => 0, // Mark as used
                        'etab_id' => $etabId,
                        'apply_date' => now()
                    ]);
                } else {
                    // Increment user sold
                    $user->consommation_solde += $coupon->solde;
                    $user->save();

                    // Update coupon as applied
                    $coupon->update([
                        'etat' => 0, // Mark as used
                        'user_id' => $userId,
                        'apply_date' => now()
                    ]);
                }
                return response()->json([
                    'message' => 'Coupon applied successfully',
                    'status' => true,
                ], 200);
            }

            // Coupon is not applicable to the user or establishment
            return response()->json([
                'error' => 'Coupon cannot be applied',
                'status' => false,
            ], 400);

        } catch (\Exception $e) {
            // Handle unexpected exceptions
            return response()->json([
                'error' => 'An error occurred while applying the coupon',
                'message' => $e->getMessage(),
                'status' => false,
            ], 500);
        }
    }

    // ------------------ Points ----------------- //

    // Get Points Transactions
    public function getPointsTransactions(Request $request, $userEtabId, $type)
    {
        try {
            // Start building the query with necessary relationships
            $query = TransactionPoint::with(['user', 'etablissement', 'etablissement_destination']);

            // Apply type filter: 'etablissement' or 'user'
            if ($type === 'establishment') {
                $query->where('etab_id', $userEtabId);
            } elseif ($type === 'user') {
                $query->where('user_id', $userEtabId);
            } else {
                return response()->json(['error' => 'Invalid type'], 400);
            }

            // Sort the results by created_at from newest to oldest
            $query->orderBy('created_at', 'desc');

            // Paginate the results
            $transactions = $query->paginate(6);

            // Map and format the data
            $transactionSummary = $transactions->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'etab_id' => $transaction->etab_id,
                    'etab_destination' => $transaction->etab_destination,
                    'montant' => $transaction->montant,
                    'description' => $transaction->description,
                    'created_at' => $transaction->created_at->toDateTimeString(),
                    'user' => [
                        'id' => optional($transaction->user)->id,
                        'first_name' => optional($transaction->user)->first_name,
                        'last_name' => optional($transaction->user)->last_name,
                        'email' => optional($transaction->user)->email,
                        'tele' => optional($transaction->user)->tele,
                        'consommation_points' => optional($transaction->user)->consommation_points
                    ],
                    'etablissement' => [
                        'id' => optional($transaction->etablissement)->id,
                        'nom' => optional($transaction->etablissement)->nom,
                        'points' => optional($transaction->etablissement)->points
                    ],
                    'etablissement_destination' => [
                        'id' => optional($transaction->etablissement_destination)->id,
                        'nom' => optional($transaction->etablissement_destination)->nom,
                        'points' => optional($transaction->etablissement_destination)->points
                    ],
                ];
            });

            // Return success response with paginated and filtered data
            return response()->json([
                'data' => $transactionSummary,
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
                
            ], 200);

        } catch (\Exception $e) {
            // Handle exceptions and return error response
            return response()->json([
                'error' => 'An error occurred while fetching the data',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Sent Points
    public function sendPoints(Request $request)
    {
        try {
            // Extract required inputs from the request
            $type = $request->input('type');
            $userId = $request->input('userId');
            $etabId = $request->input('etabId') ?? null;
            $etabDestinationId = $request->input('etabDestination');
            $montant = $request->input('montant');
            $description = $request->input('description');

            // Validate and fetch the necessary entities
            $user = User::findOrFail($userId);
            $etab = $etabId ? Etablissement::findOrFail($etabId) : null;
            $etablissementDestination = Etablissement::findOrFail($etabDestinationId);

            // Initialize a new transaction
            $transaction = new TransactionPoint();
            $transaction->user_id = $userId;
            $transaction->etab_id = $etabId;
            $transaction->etab_destination = $etabDestinationId;
            $transaction->montant = $montant;
            $transaction->description = $description;

            // Handle point transfer based on the type
            if ($type === "establishment") {
                // Check if the establishment has sufficient points
                if ($etab && $etab->points < $montant) {
                    return response()->json([
                        'message' => 'Insufficient points in the source establishment.',
                        'status' => false,
                    ], 400);
                }

                // Update points for establishments
                $etablissementDestination->points += $montant;
                $etab->points -= $montant;

                // Save changes
                $transaction->save();
                $etablissementDestination->save();
                $etab->save();

                return response()->json([
                    'message' => 'Points transferred successfully from establishment to establishment.',
                    'status' => true,
                ], 200);
            } elseif ($type === "user") {
                // Check if the user has sufficient points
                if ($user->consommation_points < $montant) {
                    return response()->json([
                        'message' => 'Insufficient points in the user account.',
                        'status' => false,
                    ], 400);
                }

                // Update points for user and destination establishment
                $etablissementDestination->points += $montant;
                $user->consommation_points -= $montant;

                // Save changes
                $transaction->save();
                $etablissementDestination->save();
                $user->save();

                return response()->json([
                    'message' => 'Points transferred successfully from user to establishment.',
                    'status' => true,
                ], 200);
            } else {
                // Invalid type provided
                return response()->json([
                    'message' => 'Invalid type provided. Accepted values are "establishment" or "user".',
                    'status' => false,
                ], 400);
            }
        } catch (\Exception $e) {
            // Handle unexpected errors
            return response()->json([
                'message' => 'An unexpected error occurred while processing the transaction.',
                'error' => $e->getMessage(),
                'status' => false,
            ], 500);
        }
    }

    // ------------------ Coins ----------------- //

    public function getCointOffers(Request $request)
    {
        try {
            // Get active products
            $products = Produit::where('status', 1)->where('name', '!=', 'publicite')->get();

            // Return success response with products data
            return response()->json([
                'data' => $products,
                'status' => true
            ], 200);

        } catch (\Exception $e) {
            // Handle exceptions and return error response
            return response()->json([
                'error' => 'An error occurred while fetching the data',
                'message' => $e->getMessage(),
                'status' => false
            ], 500);
        }
    }
    
    // ------------------ Facture ----------------- //

    // Create a new facture
    public function createFacture(Request $request)
    {
        try {
            // Validate request data
            $validatedData = $request->validate([
                'product_id' => 'required|integer|exists:products,id',
                'user_id' => 'required|integer|exists:users,id',
                'etab_id' => 'nullable|integer|exists:etablissements,id',
                'amount' => 'required|numeric',
                'solde' => 'required|numeric',
                'devise' => 'required|in:DH,USD,EURO',
                'duree' => 'nullable|integer',
                'mode' => 'nullable|string',
                'paid' => 'required|integer|in:0,1,2',
                'mode_paiement' => 'required|in:solde,carte_bancaire,virement_bancaire',
            ]);
            
            // Set billing_date based on paid status
            $billingDate = null;
            if ($validatedData['paid'] == 1) {
                $billingDate = now();
                
                // Process the solde update based on whether it's for a user or establishment
                if (!empty($validatedData['etab_id'])) {
                    $etabId = $validatedData['etab_id'];
                    $etab = Etablissement::findOrFail($etabId);
                    // Increment establishment sold
                    if ($validatedData['mode_paiement'] === "solde") {
                        $etab->consommation_solde -= $validatedData['solde'];
                    } 

                    if($validatedData['mode'] === "buySolde"){
                        $etab->consommation_solde += $validatedData['solde'];
                    } 
                    
                    $etab->save();
                } else {
                    $userId = $validatedData['user_id'];
                    $user = User::findOrFail($userId);
                    // Increment user sold
                    if ($validatedData['mode_paiement'] === "solde") {
                        $user->consommation_solde -= $validatedData['solde'];
                    } 

                    if($validatedData['mode'] === "buySolde"){
                        $user->consommation_solde += $validatedData['solde'];
                    } 
                    
                    $user->save();
                }
            }
            
            // Generate facture number (letter + 6 digits)
            $prefix = 'P'; // Default prefix, can be changed as needed
            $lastFacture = FacturationVrB::orderBy('numero', 'desc')->first();
            
            if ($lastFacture) {
                // Extract the numeric part of the last numero
                $lastNumericPart = preg_replace('/[^0-9]/', '', $lastFacture->numero);
                
                // Extract the letter prefix
                $prefix = preg_replace('/[0-9]/', '', $lastFacture->numero);
                
                // If no prefix was found, use the default prefix
                if (empty($prefix)) {
                    $prefix = 'P';
                }
                
                // Increment the numeric part
                $newNumericPart = intval($lastNumericPart) + 1;
                
                // Pad the numeric part to 6 digits
                $newNumericPart = str_pad($newNumericPart, 6, '0', STR_PAD_LEFT);
                
                // Combine prefix and numeric part
                $newNumero = $prefix . $newNumericPart;
            } else {
                // If no existing invoice, start with prefix + 000001
                $newNumero = $prefix . '000001';
            }
            
            
            // Create new facture
            $facture = new FacturationVrB();
            $facture->numero = $newNumero;
            $facture->product_id = $validatedData['product_id'];
            $facture->user_id = $validatedData['user_id'];
            $facture->etab_id = $validatedData['etab_id'];
            $facture->amount = $validatedData['amount'];
            $facture->devise = $validatedData['devise'];
            $facture->duree = $validatedData['duree'];
            $facture->paid = $validatedData['paid'];
            $facture->mode_paiement = $validatedData['mode_paiement'];
            $facture->billing_date = $billingDate;
            $facture->save();
            
            // Return success response with created facture data
            return response()->json([
                'message' => 'Facture created successfully',
                'data' => $facture,
                'status' => true
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Handle validation errors
            return response()->json([
                'error' => 'Validation error',
                'message' => $e->errors(),
                'status' => false
            ], 422);
        } catch (\Exception $e) {
            // Handle general exceptions
            return response()->json([
                'error' => 'An error occurred while creating the facture',
                'message' => $e->getMessage(),
                'status' => false
            ], 500);
        }
    }

}
