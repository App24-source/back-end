<?php
use App\Models\ImmoCaractiristique;
use App\Models\Marque;
use App\Models\Modeles;
use App\Models\Annee;
use App\Models\Immatriculation;
use App\Models\Fiscale;
use App\Models\Carburant;
use App\Models\Caracteristiques;
if (!function_exists('EmploiFilters')) {

    function EmploiFilters($query, $request) {
       
        $contrat = $request->query('contrat');
        $salaire = $request->query('salaire');
        $etude = $request->query('etude');
        $experience = $request->query('experience');
        $travail = $request->query('travail');
        $activite = $request->query('activite');
        $fonction = $request->query('fonction');
        $stage = $request->query('stage');
        $duree = $request->query('duree');
        $convention = $request->query('convention');
        $debut = $request->query('debut');
        $NbPersone = $request->query('NbPersone');
        $Domaine = $request->query('Domaine');
        $Eligible = $request->query('Eligible');
        $enseignement = $request->query('enseignement');
        $candidatures = $request->query('candidatures');
        $poste = $request->query('poste');


        if($poste) {
            $query = $query->where('infos->poste', $poste);
        }
        if($convention) {
            $query = $query->where('infos->convention', $convention);
        }
        if($Eligible) {
            $query = $query->where('infos->eligible', $Eligible);
        }
        if($enseignement) {
            $query = $query->where('infos->type', $enseignement);
        }
        if($NbPersone) {
            $query = $query->where('infos->personne', $NbPersone);
        }
        if($debut) {
            $query = $query->where('infos->debut', $debut);
        }
        if($duree) {
            $query = $query->where('infos->duree', $duree);
        }
        if($stage) {
            $query = $query->where('infos->stage', $stage);
        }
        if($candidatures) {
            $query = $query->where('infos->reception', $candidatures);
        }
        if($activite) {
            $Arrayactivite = array_map('intval', explode(',', $activite));
            $query = $query->whereIn('infos->activite_id', $Arrayactivite);
        }
        if($fonction) {
            $Arrayfonction = array_map('intval', explode(',', $fonction));
            $query = $query->whereIn('infos->fonction_id', $Arrayfonction);
        }
        if($Domaine) {
            $ArraydDomaine = array_map('intval', explode(',', $Domaine));
            $query = $query->whereIn('infos->formation_id', $ArraydDomaine);
        }
        if($travail) {
            $query = $query->where('infos->travail_id', $travail);
        }
        if($experience) {
            $query = $query->where('infos->experience_id', $experience);
        }
        if($contrat) {
            $query = $query->where('infos->contrat_id', $contrat);
        }
        if($salaire) {
            $query = $query->where('infos->salaire_id', $salaire);
        }
        if($etude) {
            $query = $query->where('infos->etude_id', $etude);
        }
       
        $test = [
            'contrat' => $contrat,
            'salaire' => $salaire,
            'experience' => $experience,
            'etude' => $etude,
            'activite' => $activite,
            'enseignement' => $enseignement,
        ];

        return $query;
       
     
    
}}