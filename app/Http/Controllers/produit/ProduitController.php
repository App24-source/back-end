<?php

namespace App\Http\Controllers\produit;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Annonce;
use App\Models\Panier;

class ProduitController extends Controller
{
    // public function PanierUser($userId) {
    //     try{
    //         $panier = Panier::where('user_id', $userId)
    //         ->with('annonces')
    //         ->get();
    //         return response()->json(['status' => 201, 'data'=>$panier]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }
}
