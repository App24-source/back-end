<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleService
{

    public function searchPlaces($searchQuery, $low_lat, $low_long, $high_lat, $high_long)
    {
        $placeIds = [];
        $pageToken = null;

        do {
            // Make the API request with the query and page token (if any)
            if($low_lat == 0 || $low_long == 0 || $high_lat == 0 || $high_long == 0) {
                $response = Http::withHeaders([
                    'X-Goog-FieldMask' => 'places.id,nextPageToken',  // Specify the fields you want to include
                    'X-Goog-Api-Key' => config('services.google.api_key'),
                ])->post('https://places.googleapis.com/v1/places:searchText', [
                    'textQuery' => $searchQuery,  // Use dynamic search query
                    'pageToken' => $pageToken,  // Optional: If pagination is used
                ]);
            } else {
                $response = Http::withHeaders([
                    'X-Goog-FieldMask' => 'places.id,nextPageToken',  // Specify the fields you want to include
                    'X-Goog-Api-Key' => config('services.google.api_key'),
                ])->post('https://places.googleapis.com/v1/places:searchText', [
                    'textQuery' => $searchQuery,  // Use dynamic search query
                    'locationRestriction' => [
                        'rectangle' => [
                            'low' => [
                                'latitude' => $low_lat,  // Southwest corner of Morocco
                                'longitude' => $low_long,  // Southwest corner of Morocco
                            ],
                            'high' => [
                                'latitude' => $high_lat,  // Northeast corner of Morocco
                                'longitude' => $high_long,  // Northeast corner of Morocco
                            ],
                        ],
                    ],
                    'pageToken' => $pageToken,  // Optional: If pagination is used
                ]);
            }

            $places = $response->json();

            // // Check if the response was successful and has results
            if ($response->successful() && isset($places['places'])) {
                // Collect the place IDs from this page and merge them into the main list
                $placeIds = array_merge($placeIds, collect($places['places'])->pluck('id')->all());

                // Check if there's a next page token and update it for the next loop iteration
                $pageToken = $places['nextPageToken'] ?? null;

                // Delay to respect API rate limits (next_page_token might take a few seconds to become active)
                if ($pageToken) {
                    sleep(0.5); // Wait a bit before making the next request
                }
            } else {
                // If the response fails, break out of the loop
                break;
            }

        } while ($pageToken && count($placeIds) <= 60); // Stop if no more pages or if we reach 60 place IDs

        // Return only up to the first 60 place IDs
        return array_slice($placeIds, 0, 5);
    }



    // Function to fetch details for each place ID
    public function getPlaceDetailsByIds(array $placeIds)
    {
        $placeDetails = [];

        foreach ($placeIds as $placeId) {
            // $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
            //     'place_id' => $placeId,
            //     'key' => config('services.google.api_key'),
            //     'fields' => 'place_id,business_status,name,formatted_address,formatted_phone_number,geometry,icon,type,address_component,opening_hours,website,wheelchair_accessible_entrance',
            //     // 'Accept-Language' => 'fr '
            //     "languageCode"=> "fr"
            // ]);

            $response = Http::withHeaders([
                'X-Goog-FieldMask' => 'businessStatus,id,displayName,addressComponents,formattedAddress,location,plusCode,types,primaryType,international_phone_number,iconMaskBaseUri',  // Specify the fields you want to include
                'X-Goog-Api-Key' => config('services.google.api_key'),
            ])->get("https://places.googleapis.com/v1/places/{$placeId}", [
                'languageCode' => 'fr', 
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data)) {
                $placeDetails[] = $data;
            }
        }

        return $placeDetails;
        // return $response;
    }
}
