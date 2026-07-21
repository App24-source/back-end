<?php

namespace App\Http\Controllers\facturation;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Produit;
use App\Models\Plan;
use App\Models\FacturationVrB;

class AppMarketController extends Controller
{
    public function getAppMarketProducts()
    {
        try {
            // Fetch products with their associated plans, excluding the product named "Booster"
            $products = Produit::with(['plans' => function($query) {
                $query->orderBy('price', 'asc'); // Sort plans by price in ascending order
            }])
            ->where('name', '!=', 'Booster')
            ->get();

            // Return the data as JSON with a success status
            return response()->json([
                'status' => 'success',
                'data' => $products
            ], 200);
        } catch (\Exception $e) {
            // Handle any exceptions and return an error response
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Add New Facure
    public function addNewFacture(Request $request)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'userId' => 'required|integer',
                'etabId' => 'nullable|integer',
                'plan_id' => 'required|integer',
                'amount' => 'required|integer',
                'devise' => 'required|in:Dirhams,Points',
            ]);

            // Fetch the largest numero from the database
            $largestNumero = FacturationVrB::max('numero');

            // If no numero exists, start from '000000'
            if (is_null($largestNumero)) {
                $newNumero = '000000';
            } else {
                // Increment the largest numero by 1 and pad with leading zeros to ensure 6 digits
                $newNumero = str_pad((int)$largestNumero + 1, 6, '0', STR_PAD_LEFT);
            }

            // Ensure the new numero is unique (though unlikely, it's a safety check)
            while (FacturationVrB::where('numero', $newNumero)->exists()) {
                $newNumero = str_pad((int)$newNumero + 1, 6, '0', STR_PAD_LEFT);
            }

            // Initialize a new facture
            $facturationVrB = new FacturationVrB();

            // Assign values to the facture
            $facturationVrB->numero = $newNumero;
            $facturationVrB->user_id = $validatedData['userId'];
            $facturationVrB->etab_id = $validatedData['etabId'] ?? null;
            $facturationVrB->plan_id = $validatedData['plan_id'];
            $facturationVrB->amount = $validatedData['amount'];
            $facturationVrB->devise = $validatedData['devise'];
            $facturationVrB->paid = 0;

            // Save the facture to the database
            $facturationVrB->save();

            // Return a success response
            return response()->json([
                'status' => 'success',
                'message' => 'Facture created successfully',
                'data' => $facturationVrB
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Handle validation errors
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            // Handle unexpected exceptions
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while adding a new facture',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
