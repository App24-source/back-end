<?php
use App\Models\ImmoCaractiristique;

if (!function_exists('ImmobilierFilters')) {

    function ImmobilierFilters($query, $request) {
        $nbChambre = $request->query('nbChambre');
        $nbEtage = $request->query('nbEtage');
        $nbSalon = $request->query('nbSalon');
        $nbSalleBain = $request->query('nbSalleBain');
        $surfaceMin = $request->query('surfaceMin');
        $surfaceMax = $request->query('surfaceMax');
        $ageBien = $request->query('ageBien');
        $characteristics = $request->query('characteristics');
        $avecPrix = $request->query('avecPrix');
        $avecVideo = $request->query('avecVideo');
        $enPromo = $request->query('enPromo');
        $isDemande = $request->query('isDemande');
        $isLoti = $request->query('isLoti');
        $isTitre = $request->query('isTitre');
        $zoning = $request->query('zoning');



        $propertyAge = ['Neuf', '1-5 ans', '6-10 ans', '11-20 ans', '21+ ans'];        

        $ZoningList = ["Agricole","Immeuble","Industriel","Maison","Service ,public","Villa"];

        // return $numbersArray = array_map('intval', explode(',', $nbEtage));
        if($nbChambre) {
            $nbChambreArray = array_map('intval', explode(',', $nbChambre));
            $query = $query->whereIn('infos->chambre', $nbChambreArray);
        }
        if($nbEtage) {
            $nbEtageArray = array_map('intval', explode(',', $nbEtage));
            $query = $query->whereIn('infos->etage', $nbEtageArray);
        }
        if($nbSalon) {
            $nbSalonArray = array_map('intval', explode(',', $nbSalon));
            $query = $query->whereIn('infos->salon', $nbSalonArray);
        }
        if($nbSalleBain) {
            $nbSalleBainArray = array_map('intval', explode(',', $nbSalleBain));
            $query = $query->whereIn('infos->salle_bain', $nbSalleBainArray);
        }
       
        if($surfaceMin != "null") {
            $query = $query->where('infos->surface_t', '>', $surfaceMin);
        }
        if($surfaceMax != "null") {
            $query = $query->where('infos->surface_t', '<', $surfaceMax);
        }
        if($ageBien){     
            $query = $query->where('infos->age_bien', $propertyAge[$ageBien-1]);
        }
        if($zoning){     
            $query = $query->where('etat', $ZoningList[$zoning-1]);
        }
        if ($characteristics) {
            // Assuming $characteristics is a string of comma-separated values
            $characteristicsArray = array_map('intval', explode(',', $characteristics));
        
            // Fetch the 'name' fields from ImmoCaractiristique
            $selectedCaractiristiques = ImmoCaractiristique::whereIn('id', $characteristicsArray)
                ->pluck('name')
                ->toArray(); // Get the list of names directly
        
            // Apply the filtering query
            $query->where(function ($query) use ($selectedCaractiristiques) {
                foreach ($selectedCaractiristiques as $characteristic) {
                    $query->orWhereJsonContains('infos->caractiristique', $characteristic);
                }
            });
            // return $selectedCaractiristiques;
        }
        if($avecPrix) {
            $query = $query->wherenot('prix', null);
        }
        if($avecVideo) {
            $query = $query->wherenot('video_1', null);
        }
        if($enPromo) {
            $query = $query->wherenot('prixPromo', null);
        }
        if($isDemande) {
            $query = $query->where('infos->is_demand', true);
        }
        if($isLoti) {
            $query = $query->where('infos->tito', true);
        } 
         if($isTitre) {
            $query = $query->where('infos->isTitre', true);
        }
       
        $test = "true";
        return $query;
    }
}