<?php

if (!function_exists('global_function')) {
    function global_function($annonces, $annoncesBooster, $cacheNameBooster, $publicites, $cacheNamePub, $page) {
        
        $CacheIndexBooster = Cache::get($cacheNameBooster, 0);
        $CacheIndexPub = Cache::get($cacheNamePub, 0);

        $RandomNumberBooster = Cache::get($cacheNameBooster.'random', 5);
        $RandomNumberPub = Cache::get($cacheNamePub.'random', 8);

        if($page == 1){
            // $RandomNumberBooster = rand(25, 30);
            $RandomNumberBooster = 5;
            // $RandomNumberPub = rand(45, 50);
            $RandomNumberPub = 2;

        }

        $annonces_Boosters_Publicites = [];
        $annonceCount = 0;

        $boosterCount = count($annoncesBooster);
        $pubCount = count($publicites);

        $IndexBooster = $CacheIndexBooster;
        $IndexPub = $CacheIndexPub;

        foreach ($annonces as $annonce) {
            $annonces_Boosters_Publicites[] = $annonce;
            $annonceCount++;

            // Ajouter une annonce après nombre random annonces
            if ($annonceCount % $RandomNumberBooster == 0 && $boosterCount > 0) {
                if($IndexBooster >= $boosterCount){
                    // Initialise Annonce Index
                    $IndexBooster = 0;
                }
                // Ajouter l'annonce courante
                $annonces_Boosters_Publicites[] = $annoncesBooster[$IndexBooster];
                $IndexBooster++;
            }
            // Ajouter une annonce après nombre random annonces
            if ($annonceCount % $RandomNumberPub == 0 && $pubCount > 0) {
                if($IndexPub >= $pubCount){
                    // Initialise Annonce Index
                    $IndexPub = 0;
                }
                // Ajouter l'annonce courante
                $annonces_Boosters_Publicites[] = $publicites[$IndexPub];
                $IndexPub++;
            }
        }
        if($page == 1){ 
            if ($boosterCount > 0) {
                $CacheIndexBooster = ($CacheIndexBooster + 1) % $boosterCount;
            }
        
            if ($pubCount > 0) {
                $CacheIndexPub = ($CacheIndexPub + 1) % $pubCount;
            }
        }
        Cache::put($cacheNameBooster, $CacheIndexBooster, 60 * 24 * 3); // Le cache est valide pour 3 jours (60 *24 * 3)
        Cache::put($cacheNamePub, $CacheIndexPub, 60 * 24 * 3); // Le cache est valide pour 3 jours (60 *24 * 3)

        Cache::put($cacheNameBooster.'random', $RandomNumberBooster, 60 * 24); // Le cache est valide pour un jour (60 *24)
        Cache::put($cacheNamePub.'random', $RandomNumberPub, 60 * 24); // Le cache est valide pour un jour (60 *24)

        return $annonces_Boosters_Publicites;
    }
}



if (!function_exists('global_function1')) {
    function global_function1($annonces, $annoncesBooster, $cacheNameBooster, $publicites, $cacheNamePub, $page) {
        $CacheIndexBooster = Cache::get($cacheNameBooster, 0);
        // $CacheIndexBooster = 0;
        $CacheIndexPub = Cache::get($cacheNamePub, 0);
        $annonces_Boosters_Publicites = [];
        $annonceCount = 0;
        $boosterCount = count($annoncesBooster);
        $pubCount = count($publicites);
        $RandomNumberBooster = rand(5, 10);
        $RandomNumberPub = rand(5, 10);
        // if($CacheIndexBooster >= $boosterCount){
        //     $CacheIndexBooster = 0;
        // }
        $IndexBooster = $CacheIndexBooster;
        // if($CacheIndexPub >= $pubCount){
        //     $CacheIndexPub = 0;
        // }
        $IndexPub = $CacheIndexPub;

        // $finBooster = false;

        foreach ($annonces as $annonce) {
            $annonces_Boosters_Publicites[] = $annonce;
            $annonceCount++;

            // Ajouter une annonce après nombre random annonces
            if ($annonceCount % $RandomNumberBooster == 0 && $boosterCount > 0 && $IndexBooster < $boosterCount) {

                if($CacheIndexBooster == 0){
                    // $annonces_Boosters_Publicites[] = $annoncesBooster[$IndexBooster];
                    // $IndexBooster = ($IndexBooster + 1) % $boosterCount;
                    $IndexBooster++;
                } elseif($IndexBooster >= $CacheIndexBooster){
                    // Ajouter l'annonce courante
                    // $annonces_Boosters_Publicites[] = $annoncesBooster[$IndexBooster];
                    $IndexBooster = ($IndexBooster + 1) % $boosterCount;
                    // if($IndexBooster >= $boosterCount){
                    //     // Initialise Annonce Index
                    //     $IndexBooster = 0;
                    // }
                } elseif($IndexBooster < $CacheIndexBooster ){
                    if($IndexBooster !== $CacheIndexBooster-1){
                        // Ajouter l'annonce courante
                        $annonces_Boosters_Publicites[] = $annoncesBooster[$IndexBooster];
                        $IndexBooster = ($IndexBooster + 1) % $boosterCount;
                    }
                }
                
                // Ajouter l'annonce courante
                // $annonces_Boosters_Publicites[] = $annoncesBooster[$IndexBooster];
                // $IndexBooster++;
                // if($IndexBooster == $CacheIndexBooster-1) $finBooster = true;
            }
            // Ajouter une annonce après nombre random annonces
            if ($annonceCount % $RandomNumberPub == 0 && $pubCount > 0 && $IndexPub >= $CacheIndexPub) {
                if($IndexPub >= $pubCount){
                    // Initialise Annonce Index
                    $IndexPub = 0;
                }
                // Ajouter l'annonce courante
                // $annonces_Boosters_Publicites[] = $publicites[$IndexPub];
                $IndexPub++;
            }
        }
        if($page == 1){ 
            $CacheIndexBooster = ($CacheIndexBooster + 1) % $boosterCount;
            
            // $CacheIndexPub = ($CacheIndexPub + 1);
            // if($CacheIndexPub >= $pubCount){
            //     $CacheIndexPub = 0;
            // }
            Cache::put($cacheNameBooster, $CacheIndexBooster,  3000); // Le cache est valide pour 3 jours (60 *24 * 3)
            Cache::put($cacheNamePub, $CacheIndexPub,  3000); // Le cache est valide pour 3 jours (60 *24 * 3)
        }
        

        return $annonces_Boosters_Publicites;
    }
}