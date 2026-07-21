<?php

namespace App\Http\Controllers\tags;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tags;
 
class TagsController extends Controller
{
    public function tags( Request $request )
    {
        $searchTerm = $request->query( 'search' );
        try {
            // Build the base query to get all tags (status 0 and 1, no filter)
            $query = Tags::select( 'id', 'name', 'slug', 'type', 'compteur_publications', 'compteur_search' );

            // Apply the search filter if a search term is provided
            if ($searchTerm) {
                $trimmedSearchTerm = trim( $searchTerm );
                $slugifiedSearchTerm = \Illuminate\Support\Str::slug($trimmedSearchTerm);
                
                $query->where( function ( $query ) use ( $trimmedSearchTerm, $slugifiedSearchTerm ) {
                    $query->where( 'name', 'like', '%' . $trimmedSearchTerm . '%' )
                        ->orWhere( 'slug', 'like', '%' . $trimmedSearchTerm . '%' )
                        ->orWhere( 'slug', 'like', '%' . $slugifiedSearchTerm . '%' );
                } );

                // Add priority ordering: exact matches first, then prefix matches, then partial matches
                // Within each priority, sort by length difference (closest match) then by compteur_publications
                $searchTermLength = strlen( $trimmedSearchTerm );
                $slugLength = strlen( $slugifiedSearchTerm );
                
                $query->orderByRaw( "
                    CASE 
                        WHEN name = ? THEN 0
                        WHEN slug = ? THEN 0
                        WHEN slug = ? THEN 0
                        WHEN name LIKE ? THEN 1
                        WHEN slug LIKE ? THEN 1
                        WHEN slug LIKE ? THEN 1
                        ELSE 2
                    END ASC,
                    LEAST(
                        ABS(LENGTH(name) - ?),
                        ABS(LENGTH(slug) - ?),
                        ABS(LENGTH(slug) - ?)
                    ) ASC,
                    compteur_publications DESC
                ", [
                    $trimmedSearchTerm,          // Exact name match
                    $trimmedSearchTerm,          // Exact slug match
                    $slugifiedSearchTerm,        // Exact slugified match
                    $trimmedSearchTerm . '%',    // Name prefix match
                    $trimmedSearchTerm . '%',    // Slug prefix match
                    $slugifiedSearchTerm . '%',  // Slugified prefix match
                    $searchTermLength,           // Length comparison for name
                    $searchTermLength,           // Length comparison for slug
                    $slugLength                  // Length comparison for slugified
                ] );
            } else {
                // If no search term, order by compteur_publications
                $query->orderBy( 'compteur_publications', 'desc' );
            }

            // Fetch the tags with the search filter applied
            $tags = $query->limit( 5 )->get();

            // If no tags are found, remove the search filter and fetch top tags
            if ($tags->isEmpty() && $searchTerm) {
                $tags = Tags::select( 'id', 'name', 'slug', 'type', 'compteur_publications', 'compteur_search' )
                    ->orderBy( 'compteur_publications', 'desc' )
                    ->limit( 5 )
                    ->get();
            }

            // Return the response with the fetched data
            return response()->json( ['status' => 201, 'data' => $tags] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()] );
        }
    }
}
