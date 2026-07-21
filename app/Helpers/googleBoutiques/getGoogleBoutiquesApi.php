<?php
// app/Helpers/googleBoutiques/getGoogleBoutiquesApi.php

use App\Services\GoogleService;
use Illuminate\Support\Facades\Validator;
use App\Models\Etablissement;
use App\Models\Ville;
use App\Models\Region;
use Illuminate\Support\Str;


/**
 * Fetch place details based on a search query, store in database, and return results.
 *
 * @param string $searchQuery
 * @return array
 */
function getPlaceDetailsByQuery($searchQuery, $city, $regions)
{
    // return true;
    // Validate the search query input
    $validator = Validator::make(['query' => $searchQuery], [
        'query' => 'required|string|max:255',
    ]);

    if ($validator->fails()) {
        return ['error' => 'Invalid query'];
    }
    $selectedRegion = null;
    $selectedVille = Ville::where('slug', $city)->first();
    if($regions && $regions[0]) {
        $selectedRegion = Region::where('slug', $regions[0])->first();
        if($selectedRegion && !$selectedRegion->latitude) {
            $geocoding = getLatLong('region', $selectedVille->id, $selectedRegion->id);
        }
    } 
    
    if( !($regions && $regions[0]) && $selectedVille && !$selectedVille->latitude) {
        $geocoding = getLatLong('city', $selectedVille->id, null);
    }


    $low_lat = 0;
    $low_long = 0;
    $high_lat = 0;
    $high_long = 0;
    // $emplacement = ' ';
    // if($city) {
    //     $selectedVille = Ville::where('slug', $city)->first();
    //     if($selectedVille) $emplacement = $emplacement.$selectedVille->name.' ';
    // }
    // if($city && $regions) {
    //     $selectedRegion = Ville::where('slug', $regions[0])->first();
    //     if($selectedRegion) $emplacement = $emplacement.$selectedRegion->name.' ';
    // }
    if($regions && $regions[0]) {
        $selectedRegion = Region::where('slug', $regions[0])->first();
        if($selectedRegion && $selectedRegion->latitude) {
            $low_lat = (double)$selectedRegion->low_latitude;
            $low_long = (double)$selectedRegion->low_longitude;
            $high_lat = (double)$selectedRegion->high_latitude;
            $high_long = (double)$selectedRegion->high_longitude;
        }
    } else {
        $selectedVille = Ville::where('slug', $city)->first();
        if($selectedVille && $selectedVille->latitude) {
            $low_lat = (double)$selectedVille->low_latitude;
            $low_long = (double)$selectedVille->low_longitude;
            $high_lat = (double)$selectedVille->high_latitude;
            $high_long = (double)$selectedVille->high_longitude;
        }
    }
    // return $regions && $regions[0];
    // return [$low_lat, $low_long, $high_lat, $high_long];
    // Use the PlaceService to get place IDs
    $placeService = app(GoogleService::class);
    
    $placeIds = $placeService->searchPlaces($searchQuery, $low_lat, $low_long, $high_lat, $high_long);
    // return $placeIds;
    if (empty($placeIds)) {
        return ['error' => 'No places found for this query'];
    }
    // $PlaceIdsTest = ["ChIJST90YJPSpw0R2Qx_4SyPIzU", "ChIJtU9ahK3Tpw0RFovpNY9DCMA", "ChIJJwoWsA_Tpw0RSfFfjy8Mkbk", "ChIJt8TFR-zSpw0RDz6oXRUOwAY", "ChIJ32Dte5BKmA0RuFXizroFoiU"];
    // Filter out IDs that already exist in the etablissement table
    $existingPlaceIds = Etablissement::whereIn('google_map_id', $placeIds)->pluck('google_map_id')->toArray();
    $newPlaceIds = array_diff($placeIds, $existingPlaceIds);
    $newPlaceIds = array_values($newPlaceIds);
    // return [$existingPlaceIds, $newPlaceIds];
    if (empty($newPlaceIds)) {
        return ['error' => 'All places already exist in the database'];
    }

    // return $newPlaceIds;
    // Fetch detailed information for each new place ID
    $placeDetails = $placeService->getPlaceDetailsByIds($newPlaceIds);
    // return $placeDetails;
    // Store each new place detail in the etablissement table
    foreach ($placeDetails as $detail) {
        $slug = Str::slug($detail['displayName']['text']);
        // Check if the slug is unique
        $isUnique = !Etablissement::where('slug', $slug)->exists();
        $i=0;
        while(!$isUnique){
            $i++;
            $slug = Str::slug($detail['displayName']['text'] . '-0' . $i);
            $isUnique = !Etablissement::where('slug', $slug)->exists();
        }
        // return $isUnique;
        $etablissement = new Etablissement();
        $etablissement->nom = $detail['displayName']['text'];
        $etablissement->slug = $slug;
        $etablissement->google_map_id = $detail['id'];
        $etablissement->adresse = $detail['formattedAddress']; // Example; adjust as per actual data
        $etablissement->latitude = $detail['location']['latitude']; // Example fields
        $etablissement->longitude = $detail['location']['longitude']; // Example fields
        $etablissement->categories = json_encode([$searchQuery]);
        if(isset($detail['internationalPhoneNumber']))
        $etablissement->numero_telephone = $detail['internationalPhoneNumber'];
        // $etablissement->logo = $detail['iconMaskBaseUri'].'.png';
        $etablissement->status = 1;
        if($selectedRegion)
        $etablissement->region_id = $selectedRegion->id;
        foreach ( $detail['addressComponents'] as $detailComponent ) {
            if(in_array('locality', $detailComponent['types']) ) {
                $ville = Ville::where('slug', Str::slug($detailComponent['longText']))->first();
                if($ville) $etablissement->ville_id = $ville->id;
            } else {
                $etablissement->ville_id = $selectedVille->id;
            }
            // if(in_array('postal_code', $detailComponent['types']) ) {
            //     $region = Region::where('code_postal', $detailComponent['longText'])->first();
            //     if($region) $etablissement->region_id = $region->id;
            // }
        }
        // Add other fields as necessary
        $etablissement->save();

    }

    return true;
}

