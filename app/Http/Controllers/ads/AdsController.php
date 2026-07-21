<?php

namespace App\Http\Controllers\ads;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Publication;
use Illuminate\Support\Facades\DB;

class AdsController extends Controller
{
    public function getPublicationsAds(){
        try{
            // Get last 20 publications IDs to exclude from boosted (same as stored procedure)
            $last20Ids = Publication::where('status', 1)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->pluck('id');

            // Get latest boosted publications (only latest boost per publication, excluding those in last 20)
            // This matches the stored procedure logic exactly
            $boostedPublications = DB::table('publications_booster_flink as pb')
                ->select('pb.publication_id', 'pb.created_at as boosted_at')
                ->whereRaw('pb.created_at = (
                    SELECT MAX(pb2.created_at) 
                    FROM publications_booster_flink pb2 
                    WHERE pb2.publication_id = pb.publication_id
                )')
                ->whereNotIn('pb.publication_id', $last20Ids)
                ->where('pb.date_fin', '>', now()) // only non-expired boosts
                ->get();

            // Create separate collections for boosted and date publications (same as stored procedure)
            $boostedPublicationsData = collect();
            $datePublicationsData = collect();

            // Get boosted publications with full data (same as stored procedure temp_boosted_publications)
            if ($boostedPublications->isNotEmpty()) {
                $boostedIds = $boostedPublications->pluck('publication_id');
                $boostedPublicationsData = Publication::whereIn('id', $boostedIds)
                    ->select('id', 'slug', 'user_id', 'etab_id', 'description', 'image_default', 'prix', 'prixPromo', 'pays_id', 'ville_id', 'region_id', 'reference')
                    ->with([
                        'user:id,first_name,last_name,slug',
                        'etablissement:id,nom,slug',
                        'pays:id,name',
                        'ville:id,name',
                        'region:id,name',
                        'tags:id,name,slug'
                    ])
                    ->get()
                    ->map(function($publication) {
                        $publication->source = 'boosted';
                        return $publication;
                    });
            }

            // Get last 20 regular publications with full data (same as stored procedure temp_date_publications)
            $datePublicationsData = Publication::whereIn('id', $last20Ids)
                ->select('id', 'slug', 'user_id', 'etab_id', 'description', 'image_default', 'prix', 'prixPromo', 'pays_id', 'ville_id', 'region_id', 'reference')
                ->with([
                    'user:id,first_name,last_name,slug',
                    'etablissement:id,nom,slug',
                    'pays:id,name',
                    'ville:id,name',
                    'region:id,name',
                    'tags:id,name,slug'
                ])
                ->get()
                ->map(function($publication) {
                    $publication->source = 'date';
                    return $publication;
                });

            // Combine both collections with exact same ordering as stored procedure
            // Stored procedure: ORDER BY CASE WHEN tbp.source = 'boosted' THEN 0 ELSE 1 END, pf.created_at DESC
            $allPublications = $boostedPublicationsData->concat($datePublicationsData)
                ->sortBy(function($publication) {
                    // Same ordering as stored procedure: boosted first (0), then date (1), then by created_at DESC
                    return [
                        $publication->source === 'boosted' ? 0 : 1,
                        $publication->created_at
                    ];
                }, SORT_REGULAR, true) // true for descending order
                ->values(); // Reset array keys to start from 0

            return response()->json(['status' => 201, 'data' => $allPublications]);
        } catch (\Exception $e) {
            // Handle any errors that occur during the query
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }

    public function getBoostedPublicationsOnly(Request $request)
    {
        try {
            $etabId = $request->query('etab_id');
            $slug   = $request->query('slug');

            // Get active, non-expired boosted publication IDs
            $boostedIds = DB::table('publications_booster_flink as pb')
                ->select('pb.publication_id')
                ->where('pb.date_fin', '>', now())
                ->distinct()
                ->pluck('pb.publication_id');

            $publicationQuery = Publication::whereIn('id', $boostedIds)
                ->where('status', 1);

            if ($etabId) {
                $publicationQuery->where('etab_id', $etabId);
            } elseif ($slug) {
                $publicationQuery->whereHas('etablissement', function($q) use ($slug) {
                    $q->where('slug', $slug);
                });
            }

            $publications = $publicationQuery
                ->select('id', 'slug', 'user_id', 'etab_id', 'description', 'image_default', 'prix', 'prixPromo', 'pays_id', 'ville_id', 'region_id', 'reference')
                ->with([
                    'user:id,first_name,last_name,slug',
                    'etablissement:id,nom,slug',
                    'pays:id,name',
                    'ville:id,name',
                    'region:id,name',
                    'tags:id,name,slug'
                ])
                ->get();

            return response()->json(['status' => 201, 'data' => $publications]);
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }
}
