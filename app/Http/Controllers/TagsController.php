<?php

namespace App\Http\Controllers\tags;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tags;

class TagsController extends Controller
{
    public function tags(Request $request) {
        $searchTerm = $request->query('search');
        try {
            // Build the base query to get tags with 'status' 1
            $query = Tags::select('id', 'name', 'slug', 'type', 'compteur_publications', 'compteur_search')
            ->where('status', 1);

            // Apply the search filter if a search term is provided
            if ($searchTerm) {
            $query->where(function($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('slug', 'like', '%' . $searchTerm . '%');
            });
            }

            // Fetch the tags with the search filter applied
            $tags = $query->orderBy('compteur_publications', 'desc')
                    ->limit(5)
                    ->get();

            // If no tags are found, remove the search filter and fetch all tags
            if ($tags->isEmpty() && $searchTerm) {
            $tags = Tags::select('id', 'name', 'slug', 'type', 'compteur_publications', 'compteur_search')
                        ->where('status', 1)
                        ->orderBy('compteur_publications', 'desc')
                        ->limit(5)
                        ->get();
            }

            // Return the response with the fetched data
            return response()->json(['status' => 201, 'data' => $tags]);
        
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }
}
