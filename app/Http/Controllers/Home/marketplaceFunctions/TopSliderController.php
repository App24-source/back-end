<?php

namespace App\Http\Controllers\Home\marketplaceFunctions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class TopSliderController extends Controller
{
    /**
     * Get marketplace tags slider data (uses marketplace_tags_slider procedure)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMarketplaceTagsSlider( Request $request )
    {
        try {
            $tagSlug = $request->input( 'tag' ) ?? null;
            $paysSlug = $request->input( 'pays' ) ?? null;
            $villeSlug = $request->input( 'ville' ) ?? null;
            $regionSlug = $request->input( 'region' ) ?? null;
            $limit = (int) ( $request->input( 'limit' ) ?? 12 );

            // Call the stored procedure
            $results = DB::select( 'CALL marketplace_tags_slider(?, ?, ?, ?, ?)', [
                $tagSlug,
                $paysSlug,
                $villeSlug,
                $regionSlug,
                $limit
            ] );

            // Build metadataSEO from the requested tag (if provided)
            $metadataSEO = null;
            if ($tagSlug) {
                $tag = DB::table( 'tags_flink' )
                    ->select( 'id', 'slug', 'name', 'mngr_is_index', 'canonical_parent', 'master' )
                    ->where( 'slug', $tagSlug )
                    ->first();

                if ($tag) {
                    $masterData = null;
                    if ($tag->master) {
                        $masterData = DB::table( 'tags_flink' )
                            ->select( 'id', 'slug', 'name', 'mngr_is_index' )
                            ->where( 'id', $tag->master )
                            ->first();
                    }

                    $metadataSEO = [
                        'id' => $tag->id,
                        'slug' => $tag->slug,
                        'name' => $tag->name,
                        'mngr_is_index' => $tag->mngr_is_index,
                        'canonical_parent' => $tag->canonical_parent,
                        'master' => $masterData,
                    ];
                }
            }

            return response()->json( [
                'status' => 200,
                'data' => $results,
                'metadataSEO' => $metadataSEO,
            ] );

        } catch (\PDOException $e) {
            return response()->json( [
                'status' => 500,
                'error' => 'Database error',
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null
            ], 500 );
        } catch (\Exception $e) {
            return response()->json( [
                'status' => 500,
                'error' => 'Failed to fetch marketplace tags slider data',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500 );
        }
    }
}

