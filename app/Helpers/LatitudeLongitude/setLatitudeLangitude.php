<?php

if (!function_exists('setLatitudeLongitude')) {
    function setLatitudeLongitude($location, $type) {

        // OpenStreetMap API URL
        $url = "https://nominatim.openstreetmap.org/search";
        // https://nominatim.openstreetmap.org/search?q=Toulal&format=json

        // Make the API request
        // Make the API request with a custom User-Agent
        $response = Http::withHeaders([
            'User-Agent' => 'MyLaravelApp/1.0 (contact@example.com)' // Replace with your app details
        ])->get($url, [
            'q' => $location,
            'format' => 'json',
            'countrycodes' => 'MA',
            // 'limit' => 1
        ]);

        // Decode the JSON response
        $data = $response->json();

        // Filter results where "addresstype" is "city"
        $filteredData = collect($data)->where('addresstype', $type)->values();

        // Return the filtered array directly, NOT as a JsonResponse
        if ($filteredData->isNotEmpty()) {
            return $filteredData->all(); // Convert collection to array
        }


        // Return an error if no results found
        return false;
    }
}
        