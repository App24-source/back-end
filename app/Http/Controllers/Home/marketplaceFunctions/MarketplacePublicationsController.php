<?php

namespace App\Http\Controllers\Home\marketplaceFunctions;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

class MarketplacePublicationsController extends Controller
{
    /**
     * Get marketplace publications by calling the get_marketplace stored procedure
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMarketplacePublications( Request $request )
    {
        try {
            // Set static variable for per_page value
            $perPage = 24;

            // Get request parameters
            $search = $request->input( 'search' );
            $searchName = $request->input( 'search_name' );
            $pays = $request->input( 'pays' );
            $ville = $request->input( 'ville' );
            $region = $request->input( 'region' );
            $profileId = $request->input( 'profile_id' );
            $profileType = $request->input( 'profile_type' );
            $page = $request->input( 'page', 1 );

            // Determine user_id and etab_id based on profile_type
            $userId = null;
            $etabId = null;

            if ($profileType === 'user' && $profileId) {
                $userId = (int) $profileId;
            } elseif ($profileType === 'etablissement' && $profileId) {
                $etabId = (int) $profileId;
            }

            // Handle tag creation/update in tags_flink table before tracking user interest
            $tagId = null;

            // Always process search if provided
            // Priority: slug first (most reliable), then name if provided
            if (!empty( $search )) {
                // First, ALWAYS check if tag exists by slug (this is the primary identifier)
                $existingBySlug = DB::table( 'tags_flink' )
                    ->where( 'slug', $search )
                    ->first();

                if ($existingBySlug && isset( $existingBySlug->id )) {
                    // Tag exists by slug - ALWAYS use this tag and increment
                    // Use update() instead of increment() to ensure updated_at uses UTC_TIMESTAMP()
                    DB::table( 'tags_flink' )
                        ->where( 'id', $existingBySlug->id )
                        ->update( [
                            'compteur_search' => DB::raw( 'compteur_search + 1' ),
                            'updated_at' => DB::raw( 'UTC_TIMESTAMP()' ),
                        ] );
                    $tagId = (int) $existingBySlug->id;
                } elseif (!empty( $searchName )) {
                    // Tag doesn't exist by slug, but we have a name from cookie
                    // Check if name exists (name is UNIQUE)
                    $existingByName = DB::table( 'tags_flink' )
                        ->where( 'name', $searchName )
                        ->first();

                    if ($existingByName && isset( $existingByName->id )) {
                        // Name exists but slug is different - this means cookie name doesn't match current slug
                        // Still increment the existing tag by name, but log that slug doesn't match
                        // Use update() instead of increment() to ensure updated_at uses UTC_TIMESTAMP()
                        DB::table( 'tags_flink' )
                            ->where( 'id', $existingByName->id )
                            ->update( [
                                'compteur_search' => DB::raw( 'compteur_search + 1' ),
                                'updated_at' => DB::raw( 'UTC_TIMESTAMP()' ),
                            ] );
                        $tagId = (int) $existingByName->id;
                    } else {
                        // Neither slug nor name exists, create new tag with provided slug and name
                        try {
                            // Use MySQL UTC_TIMESTAMP() for consistent timestamps - always uses database server UTC time
                            $tagId = DB::table( 'tags_flink' )->insertGetId( [
                                'slug' => $search,
                                'name' => $searchName,
                                'status' => 0,
                                'mngr_is_index' => 1,
                                'master' => null,
                                'auto_is_index' => 1,
                                'canonical_parent' => 0,
                                'compteur_search' => 1,
                                'compteur_publications' => 0,
                                'total_pub_compteur' => 0,
                                'created_at' => DB::raw( 'UTC_TIMESTAMP()' ),
                                'updated_at' => DB::raw( 'UTC_TIMESTAMP()' ),
                            ] );
                        } catch (\Exception $e) {
                            // If insert fails (e.g., race condition or duplicate), try to find by name or slug
                            $fallbackTag = DB::table( 'tags_flink' )
                                ->where( 'name', $searchName )
                                ->orWhere( 'slug', $search )
                                ->first();

                            if ($fallbackTag && isset( $fallbackTag->id )) {
                                // Use update() instead of increment() to ensure updated_at uses UTC_TIMESTAMP()
                                DB::table( 'tags_flink' )
                                    ->where( 'id', $fallbackTag->id )
                                    ->update( [
                                        'compteur_search' => DB::raw( 'compteur_search + 1' ),
                                        'updated_at' => DB::raw( 'UTC_TIMESTAMP()' ),
                                    ] );
                                $tagId = (int) $fallbackTag->id;
                            } else {
                                $tagId = null;
                            }
                        }
                    }
                }
                // If only slug is provided and tag doesn't exist, $tagId remains null
                // We can't create a tag without a name (name is required and UNIQUE)
            }

            // Track tag interest for marketplace search (user_tags_filters_flink)
            // Always update/increment if tag exists and profile info is available
            if ($tagId !== null && ( $userId !== null || $etabId !== null )) {
                // First try to update existing record - this will return number of affected rows
                // We check for BOTH tag_id AND user_id/etab_id to ensure we're updating the correct record
                if ($userId !== null) {
                    // For user_id - check if record exists with tag_id AND user_id
                    $updated = DB::table( 'user_tags_filters_flink' )
                        ->where( 'tag_id', $tagId )
                        ->where( 'user_id', $userId )
                        ->whereNull( 'etab_id' ) // Ensure etab_id is null for user records
                        ->update( [
                            'compteur' => DB::raw( 'compteur + 1' ),
                            'updated_at' => now(),
                        ] );

                    // If no rows were updated (updated === 0), the record doesn't exist, so insert it
                    if ($updated === 0) {
                        try {
                            DB::table( 'user_tags_filters_flink' )->insert( [
                                'tag_id' => $tagId,
                                'user_id' => $userId,
                                'etab_id' => null,
                                'compteur' => 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ] );
                        } catch (\Exception $e) {
                            // If insert fails (e.g., duplicate key due to race condition), try update again
                            DB::table( 'user_tags_filters_flink' )
                                ->where( 'tag_id', $tagId )
                                ->where( 'user_id', $userId )
                                ->whereNull( 'etab_id' )
                                ->update( [
                                    'compteur' => DB::raw( 'compteur + 1' ),
                                    'updated_at' => now(),
                                ] );
                        }
                    }
                } else {
                    // For etab_id - check if record exists with tag_id AND etab_id
                    $updated = DB::table( 'user_tags_filters_flink' )
                        ->where( 'tag_id', $tagId )
                        ->where( 'etab_id', $etabId )
                        ->whereNull( 'user_id' ) // Ensure user_id is null for etab records
                        ->update( [
                            'compteur' => DB::raw( 'compteur + 1' ),
                            'updated_at' => now(),
                        ] );

                    // If no rows were updated (updated === 0), the record doesn't exist, so insert it
                    if ($updated === 0) {
                        try {
                            DB::table( 'user_tags_filters_flink' )->insert( [
                                'tag_id' => $tagId,
                                'user_id' => null,
                                'etab_id' => $etabId,
                                'compteur' => 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ] );
                        } catch (\Exception $e) {
                            // If insert fails (e.g., duplicate key due to race condition), try update again
                            DB::table( 'user_tags_filters_flink' )
                                ->where( 'tag_id', $tagId )
                                ->where( 'etab_id', $etabId )
                                ->whereNull( 'user_id' )
                                ->update( [
                                    'compteur' => DB::raw( 'compteur + 1' ),
                                    'updated_at' => now(),
                                ] );
                        }
                    }
                }
            }

            // Call the stored procedure
            // CALL get_marketplace(in_user_id, in_etab_id, in_tag, in_pays, in_ville, in_region, in_page, in_per_page)
            $results = DB::select( 'CALL get_marketplace(?, ?, ?, ?, ?, ?, ?, ?)', [
                $userId,
                $etabId,
                $search,
                $pays,
                $ville,
                $region,
                (int) $page,
                $perPage,
            ] );

            // Process results
            $publications = [];
            $currentPage = $page;
            $pagination = null;
            $countDetails = null;

            if (!empty( $results )) {
                // Get pagination info from first result
                $firstResult = $results[0];
                $currentPage = $firstResult->current_page ?? $page;

                // Extract pagination field from first result's pagination column
                if (isset( $firstResult->pagination ) && !empty( $firstResult->pagination )) {
                    // Decode pagination JSON - use false to get object instead of array
                    $pagination = json_decode( $firstResult->pagination, false );

                    // Extract count_details as separate field if it exists
                    if (isset( $pagination->count_details )) {
                        $countDetails = $pagination->count_details;
                    }
                }

                // Map results to publications array
                foreach ($results as $index => $result) {
                    $publication = [
                        'id' => $result->id ?? null,
                        'slug' => $result->slug ?? null,
                        'prix' => $result->prix ?? null,
                        'prixPromo' => $result->prixPromo ?? null,
                        'image_square' => $result->image_square ?? null,
                        'pays' => isset( $result->pays ) ? json_decode( $result->pays, true ) : null,
                        'ville' => isset( $result->ville ) ? json_decode( $result->ville, true ) : null,
                        'region' => isset( $result->region ) ? json_decode( $result->region, true ) : null,
                        'tags' => isset( $result->tags ) ? json_decode( $result->tags, true ) : [],
                        'source' => $result->source ?? null,
                        'the_score' => $result->the_score ?? null,
                        'profile' => isset( $result->profile ) ? json_decode( $result->profile, true ) : null,
                        'separator' => isset( $result->separator ) ? (bool) $result->separator : false,
                    ];

                    // Handle boosted_publications - should be object or null, not array
                    if (isset( $result->boosted_publications )) {
                        $boosted = json_decode( $result->boosted_publications, true );
                        // If it's an empty array, convert to null, otherwise keep as is (object or null)
                        $publication['boosted_publications'] = ( is_array( $boosted ) && empty( $boosted ) ) ? null : $boosted;
                    } else {
                        $publication['boosted_publications'] = null;
                    }

                    // Add header field if it exists
                    if (isset( $result->header )) {
                        $publication['header'] = json_decode( $result->header, true );
                    }

                    $publications[] = $publication;
                }
            }

            // Build response structure
            $responseData = [
                'data' => $publications,
                'current_page' => $currentPage,
            ];

            // Add pagination field if it exists (extracted from first row's pagination column)
            if ($pagination !== null) {
                $responseData['pagination'] = $pagination;
            }

            // Add count_details as separate field for easier access
            if ($countDetails !== null) {
                $responseData['count_details'] = $countDetails;
            }

            return response()->json( [
                'status' => 200,
                'data' => [
                    'publication' => $responseData,
                ],
            ] );

        } catch (PDOException $e) {
            return response()->json( [
                'status' => 500,
                'error' => 'Database error occurred',
                'message' => $e->getMessage(),
            ], 500 );
        } catch (\Exception $e) {
            return response()->json( [
                'status' => 500,
                'error' => 'An error occurred',
                'message' => $e->getMessage(),
            ], 500 );
        }
    }
}

