<?php
use App\Models\ImmoCaractiristique;
use App\Models\Marque;
use App\Models\Modeles;
use App\Models\Annee;
use App\Models\Immatriculation;
use App\Models\Fiscale;
use App\Models\Carburant;
use App\Models\Caracteristiques;
if (!function_exists('SupermarcheFilters')) {

    function SupermarcheFilters($query, $request) {
       
        $Etat = $request->query('Etat');

        if($Etat) {
            $query = $query->where('etat', $Etat);
        }
       
        $test = [
            'etat' => $Etat,
        ];

        return $query;
       
     
    
}}