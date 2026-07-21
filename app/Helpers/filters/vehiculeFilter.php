<?php

use App\Models\ImmoCaractiristique;
use App\Models\Marque;
use App\Models\Modeles;
use App\Models\Annee;
use App\Models\Immatriculation;
use App\Models\Fiscale;
use App\Models\Carburant;
use App\Models\Caracteristiques;

if (!function_exists('VehiculeFilters')) {
    function VehiculeFilters($query, $request) {
        $anneeModel = $request->query('anneeModel');
        $categorie = $request->query('categorie');
        $ModelId = $request->query('ModelId');
        $MarqueId = $request->query('MarqueId');
        $selectedEnergyType = $request->query('Energy');
        $kilometrage = $request->query('kilometrage'); // Fixed typo here
        $Puissance = $request->query('Puissance');
        $selectedGearboxType = $request->query('selectedGearboxType');
        $selectedOrigine = $request->query('Origine');
        $NBporte = $request->query('NBporte');
        $NBroues = $request->query('NBroues');
        $Etat = $request->query('Etat');

        $characteristics = $request->query('characteristics');
        $characteristicsMotos = $request->query('characteristicsMotos');

        $PremierMain = $request->query('PremierMain');
        $avecVideo = $request->query('avecVideo');
        $enPromo = $request->query('enPromo');
        $isDemande = $request->query('isDemande');

        // Process year model
        if ($anneeModel) {
            $arrayAnneeModel = array_map('trim', explode(',', $anneeModel));
            $anneeIds = Annee::whereIn('name', $arrayAnneeModel)->pluck('id')->toArray();
            $query->whereIn('annee_id', $anneeIds);
        }

        // Process power
        if ($Puissance) {
            $arrayPuissance = array_map('trim', explode(',', $Puissance));
            $PuissanceGet = Fiscale::whereIn('name', $arrayPuissance)->pluck('id')->toArray();
            $query->whereIn('fiscale_id', $PuissanceGet);
        }

        // Process characteristics
        if ($characteristics) {
            $arrayCharacteristics = array_map('trim', explode(',', $characteristics));
            $characteristicsGet = Caracteristiques::pluck('id')->toArray();
            $filteredCharacteristics = array_intersect($arrayCharacteristics, $characteristicsGet);
            if (!empty($filteredCharacteristics)) {
                $query->whereIn('caractiristique', $filteredCharacteristics);
            }
        }

        // Motos details for filtering
        $MotosDetails = [
            ['id' => 1, 'name' => "+Sac de réservoir"],
            ['id' => 2, 'name' => "Antivol"],
            ['id' => 3, 'name' => "Bulle Basse"],
            ['id' => 4, 'name' => "ABS/CBS"],
            ['id' => 5, 'name' => "CD/MP3/Bluetooth"],
        ];

        // Process motorcycle characteristics
        if ($characteristicsMotos) {
            $arrayCharacteristicsMotos = array_map('trim', explode(',', $characteristicsMotos));
            $characteristicsMotosGet = array_column($MotosDetails, 'id');
            $filteredCharacteristicsMotos = array_intersect($arrayCharacteristicsMotos, $characteristicsMotosGet);
            if (!empty($filteredCharacteristicsMotos)) {
                $query->whereIn('caractiristique', $filteredCharacteristicsMotos);
            }
        }

        // Apply additional filters
        if ($kilometrage) {
            $ArrayKilometrage = array_map('intval', explode(',', $kilometrage));
            $query->whereIn('kilometrage', $ArrayKilometrage);
        }
        if ($MarqueId) {
            $ArrayMarqueId = array_map('intval', explode(',', $MarqueId));
            $query->whereIn('marque_id', $ArrayMarqueId);
        }
        if ($ModelId) {
            $ArrayModelId = array_map('intval', explode(',', $ModelId));
            $query->whereIn('model_id', $ArrayModelId);
        }
        if ($selectedEnergyType) {
            $query->where('carburant_id', $selectedEnergyType);
        }
        if ($selectedGearboxType) {
            $query->where('transmission', $selectedGearboxType);
        }
        if ($Etat) {
            $query->where('etat', $Etat);
        }
        if ($selectedOrigine) {
            $query->where('dedounee', $selectedOrigine);
        }
        if ($NBporte) {
            $query->where('nbre_port', $NBporte);
        }
        if ($NBroues) {
            $query->where('infos->nbre_roue', $NBroues);
        }

        // Additional filters for motorcycles
        if ($categorie && $request->query('categorie') === 'motos') {
            if ($PremierMain) {
                $query->where('infos->premier_main', $PremierMain);
            }
            if ($anneeModel) {
                $arrayAnneeModel = array_map('trim', explode(',', $anneeModel));
                $anneeIds = Annee::whereIn('name', $arrayAnneeModel)->pluck('id')->toArray();
                $query->whereIn('infos->annee_id', $anneeIds);
            }
            if ($selectedOrigine) {
                $query->where('infos->didence', $selectedOrigine);
            }
            if ($kilometrage) {
                $ArrayKilometrage = array_map('intval', explode(',', $kilometrage));
                $query->whereIn('infos->Km1', $ArrayKilometrage);
            }
        }

        // Filter for first-hand vehicles
        if ($PremierMain) {
            $query->where('premier_main', $PremierMain);
        }
        if ($avecVideo) {
            $query->whereNotNull('video_1');
        }
        if ($enPromo) {
            $query->whereNotNull('prixPromo');
        }
        if ($isDemande) {
            $query->where('infos->is_demand', true);
        }

        // For debugging purposes
        $test = [
            'kilometrage' => $kilometrage,
            'puissance' => $Puissance,
            'anneeModel' => $anneeModel,
            'anneeIds' => $anneeIds ?? [], // Ensure this variable is defined
            'NBporte' => $NBporte,
            'selectedGearboxType' => $selectedGearboxType,
            'selectedEnergyType' => $selectedEnergyType,
            'PremierMain' => $PremierMain,
            'MarqueId' => $MarqueId,
            'model_id' => $ModelId,
            'characteristicsMotos' => $characteristicsMotos,
            'categorie' => $categorie,
            'selectedOrigine' => $selectedOrigine
        ];

        return $query;
    }
}