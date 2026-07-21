<?php

use App\Models\Ville;
use App\Models\Region;

function getLatLong($type, $ville_id, $region_id) {

    $Adresse = 'Morocco';
    if($type == 'city' && !$region_id) {
        $selectedVille = Ville::find($ville_id);
        $Adresse = $selectedVille->name . ', ' . $Adresse;
    } 
    if($type == 'region' && $region_id) {
        $selectedVille = Ville::find($ville_id);
        $selectedRegion = Region::find($region_id);
        $Adresse = $selectedRegion->name . ', ' . $selectedVille->name . ', ' . $Adresse;
    }

    $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
        'address' => $Adresse,
        'key' => config('services.google.api_key'),
    ]);
    $data = $response->json();

    if ($response->successful() && isset($data['results']) && count($data['results']) > 0) {
        if(isset($data['results'][0]['geometry']['bounds'])) $bounds = $data['results'][0]['geometry']['bounds'];
        else $bounds = $data['results'][0]['geometry']['viewport'];
        $location = $data['results'][0]['geometry']['location'];

        if($type == 'city') {
            $selectedVille->latitude = $location['lat'];
            $selectedVille->longitude = $location['lng'];
            $selectedVille->low_latitude = $bounds['southwest']['lat'];
            $selectedVille->low_longitude = $bounds['southwest']['lng'];
            $selectedVille->high_latitude = $bounds['northeast']['lat'];
            $selectedVille->high_longitude = $bounds['northeast']['lng'];
            $selectedVille->save();
            return 'ville true';
        } else if($type == 'region') {
            $selectedRegion->latitude = $location['lat'];
            $selectedRegion->longitude = $location['lng'];
            $selectedRegion->low_latitude = $bounds['southwest']['lat'];
            $selectedRegion->low_longitude = $bounds['southwest']['lng'];
            $selectedRegion->high_latitude = $bounds['northeast']['lat'];
            $selectedRegion->high_longitude = $bounds['northeast']['lng'];
            $selectedRegion->save();
            return 'region true';
        }
        return "false";
    }

}