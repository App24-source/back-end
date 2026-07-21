<?php
use Carbon\Carbon;

if (!function_exists('RechercheFilters')) {

    function RechercheFilters($query, $request) {
        $searchPhrase = str_replace('-', ' ', $request->query('recherche'));
        $testArray = [];

        $result = $query->where(function($query) use ($searchPhrase) {
            $query->whereRaw('LOWER(titre) LIKE ?', ["%{$searchPhrase}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$searchPhrase}%"])
                    ->orWhereRaw('LOWER(infos) LIKE ?', ["%{$searchPhrase}%"])
                    ->orWhereHas('marque', function ($query) use ($searchPhrase) {
                        $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchPhrase}%"]);
                    })
                    ->orWhereHas('model', function ($query) use ($searchPhrase) {
                        $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchPhrase}%"]);
                    })
                    ->orWhereHas('carburant', function ($query) use ($searchPhrase) {
                        $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchPhrase}%"]);
                    })
                    ->orWhereHas('fiscale', function ($query) use ($searchPhrase) {
                        $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchPhrase}%"]);
                    });

            })->get();

        $sortResult = $result->map(function ($annonce) use ($searchPhrase) {
            // Standardize fields to lowercase for consistent matching
            $score = 0;
            if (stripos($annonce->titre, $searchPhrase) !== false) {
                $score += 20;
            }
            if (stripos(strtolower($annonce->description), $searchPhrase) !== false) {
                $score += 16;
            }
            // Add 25 points if the keyword exists in either `infos` or `marque->name` or model->name or carburant->name or fiscale->name
            if (stripos(mb_strtolower($annonce->infos), $searchPhrase) !== false ||
                ($annonce->marque && stripos(mb_strtolower($annonce->marque->name), $searchPhrase) !== false) || 
                ($annonce->model && stripos(mb_strtolower($annonce->model->name), $searchPhrase) !== false) ||
                ($annonce->carburant && stripos(mb_strtolower($annonce->carburant->name), $searchPhrase) !== false) ||
                ($annonce->fiscale && stripos(mb_strtolower($annonce->fiscale->name), $searchPhrase) !== false)) {
                $score += 25;
            }
            if ($annonce->user->email_verified_at != null) {
                $score += 5;
            }
            if ($annonce->etablissement != null && $annonce->etablissement->is_certifie != null) {
                $score += 15;
            }
            // Check if the `date_order` is more than one month old
            $dateOrder = Carbon::parse($annonce->date_order); 
            if ($dateOrder->lt(Carbon::now()->subMonth())) {
                $score *= 0.5; // Reduce score by 50%
            }
            
            $annonce->setAttribute('score', $score);

            return $annonce;
        })
        // Sort by the dynamically added score attribute
        ->sortByDesc('score')
        ->values();

    // foreach ($sortResult as $annonce) {
    //     $testArray[$annonce->id] = $annonce->score;
    //     // echo "Annonce ID: {$annonce->id}, Score: {$annonce->score}\n";
    // }

        // return $result;
        return $sortResult;
    }
}