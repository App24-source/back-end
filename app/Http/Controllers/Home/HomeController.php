<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Home\functions\conceptFlux\ConceptFluxController;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Etablissement;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    protected $conceptFluxController;
    public function __construct( ConceptFluxController $conceptFluxController )
    {
        $this->conceptFluxController = $conceptFluxController;
    }

    public function index( Request $request )
    {
        try {
            // If a pubId is provided, delegate to detail_by_pubid procedure via ConceptFluxController
            $pubId = $request->query( 'pubId' );
            if ($pubId) {
                return $this->conceptFluxController->getDetailByPubIdPublications( $request );
            }

            // Check if customFlux is provided - use custom flux procedure
            $customFlux = $request->query( 'customFlux' );
            if (!empty( $customFlux )) {
                return $this->conceptFluxController->getCustomFluxPublications( $request );
            }

            // Check if isSuivis is true - use suivis procedure
            $isSuivis = $request->query( 'isSuivis' );
            if ($isSuivis === 'true' || $isSuivis === true) {
                return $this->conceptFluxController->getSuivisPublications( $request );
            }

            // Otherwise, use the concept flux dispatcher
            return $this->conceptFluxController->getConceptFluxPublications( $request );
        } catch (\Exception $e) {
            return response()->json( [
                'status' => 500,
                'message' => 'Error fetching publications',
                'error' => $e->getMessage()
            ], 500 );
        }
    }






    public function addInterestFromPublication( Request $request )
    {
        try {
            $pubIds = $request->input( 'pubIds' ); // Should be an array
            $profileSlug = (string) $request->input( 'profile' );
            $profileType = $request->input( 'profileType' ); // 'user' | 'etablissement' | null

            if (!is_array($pubIds)) {
                // Backward compatibility if it's a single ID
                $pubId = (int) $request->input('pubId');
                if ($pubId) {
                    $pubIds = [$pubId];
                }
            }

            $guestSessionId = $request->input('guestSessionId');
            $source = $request->input('source');

            if (!empty($guestSessionId)) {
                try {
                    Log::info('addInterestFromPublication disconnected sync', [
                        'guestSessionId' => $guestSessionId,
                        'source' => $source,
                        'pubIds_count' => is_array($pubIds) ? count($pubIds) : 0,
                    ]);
                    @file_put_contents(
                        storage_path('logs/disconnected_flux.log'),
                        now()->toIso8601String() . ' [info] interest_sync: Syncing offline interests guest=' . $guestSessionId . ' pubs=' . (is_array($pubIds) ? count($pubIds) : 0) . PHP_EOL,
                        FILE_APPEND | LOCK_EX
                    );
                } catch (\Throwable $e) {}
            }

            if (empty($pubIds) || !$profileSlug) {
                try {
                    Log::warning( 'addInterestFromPublication failed: missing parameters', [
                        'pubIds' => $pubIds,
                        'profile' => $profileSlug,
                        'profileType' => $profileType
                    ] );
                } catch (\Throwable $e) {
                }
                return response()->json( ['status' => 400, 'message' => 'Missing pubIds or profile'], 400 );
            }

            // Find the profile (ID or Slug)
            $profile = null;
            $isNumeric = is_numeric($profileSlug);

            if ($profileType === 'user') {
                $profile = $isNumeric ? User::find($profileSlug) : User::where('slug', $profileSlug)->first();
            } elseif ($profileType === 'etablissement') {
                $profile = $isNumeric ? Etablissement::find($profileSlug) : Etablissement::where('slug', $profileSlug)->first();
            } else {
                if ($isNumeric) {
                    $profile = User::find($profileSlug) ?: Etablissement::find($profileSlug);
                } else {
                    $profile = User::where('slug', $profileSlug)->first()
                        ?: Etablissement::where('slug', $profileSlug)->first();
                }
            }

            if (!$profile) {
                try {
                    Log::warning( 'addInterestFromPublication failed: profile not found', [
                        'pubIds' => $pubIds,
                        'profile' => $profileSlug,
                        'profileType' => $profileType
                    ] );
                } catch (\Throwable $e) {
                }
                return response()->json( ['status' => 404, 'message' => 'Profile not found'], 404 );
            }

            // Get all tags for all publications in the batch
            $allTags = \Illuminate\Support\Facades\DB::table('publications_tags_flink')
                ->whereIn('publication_id', $pubIds)
                ->pluck('tag_id');

            if ($allTags->isEmpty()) {
                console_log_info("No tags found for the provided publications.");
                return response()->json(['status' => 200, 'message' => 'No tags found for these publications']);
            }

            // Group tags by ID and count occurrences in this batch
            // Example: [tag1 => 3, tag2 => 1, tag3 => 2]
            $tagCounts = array_count_values($allTags->toArray());

            $maxCompteur = 100;
            $totalTagsUpdated = 0;

            foreach ($tagCounts as $tagId => $occurrenceCount) {
                try {
                    if (method_exists($profile, 'tags_filters')) {
                        // Check for existing interest in this tag for this profile
                        $existing = $profile->tags_filters()->where('tag_id', $tagId)->first();
                        
                        if ($existing) {
                            $current = (int) ($existing->pivot->compteur ?? 0);
                            $newCount = min($maxCompteur, $current + $occurrenceCount);
                            
                            // Update the existing record
                            $profile->tags_filters()->updateExistingPivot($tagId, [
                                'compteur' => $newCount,
                                'updated_at' => now()
                            ]);
                        } else {
                            // Create new interest record with the initial frequency count
                            $profile->tags_filters()->attach($tagId, [
                                'compteur' => min($maxCompteur, $occurrenceCount),
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                        $totalTagsUpdated++;
                    }
                } catch (\Throwable $e) {
                    // Log error and skip this tag
                    \Illuminate\Support\Facades\Log::error("Tag processing error for tag_id $tagId: " . $e->getMessage());
                }
            }

            try {
                Log::info( 'addInterestFromPublication success', [
                    'pubIds_count' => count($pubIds),
                    'profile_id' => $profile->id,
                    'total_unique_tags' => count($tagCounts),
                    'total_tags_processed' => $totalTagsUpdated
                ] );
            } catch (\Throwable $e) {}

            return response()->json( ['status' => 200, 'message' => 'Interests recorded for ' . count($pubIds) . ' publications. Unique tags updated: ' . $totalTagsUpdated] );
        } catch (\Throwable $e) {
            try {
                Log::error( 'addInterestFromPublication error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ] );
            } catch (\Throwable $ex) {
            }
            return response()->json( ['status' => 500, 'message' => 'Server error', 'error' => $e->getMessage()], 500 );
        }
    }

}