<?php

namespace App\Http\Controllers\annonce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Annonce;

class UserPublicationController extends Controller
{
    // public function index($userEtabId, $typeAnnonce){
    //     try{
    //         if($typeAnnonce == 'user') {
    //             $Annonces = Annonce::where('user_id', $userEtabId)->where('etab_id', null)
    //             ->with('user:id,first_name,last_name,avatar,consommation_points', 'etablissement:id,nom,logo,numero_telephone,points', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annonces_booster', 'candidats')
    //             ->get();
    //         } else if($typeAnnonce == 'establishment') {
    //             $Annonces = Annonce::where('etab_id', $userEtabId)
    //             ->with('user:id,first_name,last_name,avatar,consommation_points', 'etablissement:id,nom,logo,numero_telephone,points', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annonces_booster', 'candidats')
    //             ->get();
    //         }
            
    //         // return $userAnnonces;
    //         return response()->json(['status' => 201, 'data' => $Annonces]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function delete($AnnonceId){
    //     try{
    //         $deletedAnnonce = Annonce::
    //         with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //         ->find($AnnonceId);
    //         if (!$deletedAnnonce) {
    //             return response()->json(['message' => 'Annonce introuvable'], 404);
    //         }
    //         $deletedAnnonce->show_in_nade = 4;
    //         $deletedAnnonce->save();
    //         return response()->json(['status' => 201, 'message' => 'Annonce supprimée avec succès.']);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'message' => 'Erreur lors de la suppression de l\'annonce.', 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }
}
