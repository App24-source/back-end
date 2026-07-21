<?php

namespace App\Http\Controllers\laivraison;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ModeLaivraison;

class LaivraisonController extends Controller
{
    public function index() {
        try {

            $modes_laivraison = ModeLaivraison::select('id', 'name')->get();
            
            return response()->json(['status' => 201, 'data' => $modes_laivraison]);
        
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }
}
