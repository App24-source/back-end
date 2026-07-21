<?php

namespace App\Http\Controllers\Home\functions\conceptFlux;

use App\Http\Controllers\Controller;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConceptFluxController extends Controller
{
    /**
     * Get publications using concept flux procedures
     */
    public function getConceptFluxPublications(Request $request)
    {
        try {
            $requestedPerPage = (int)$request->query('perPage', 5);
            $requestedPerPage = $this->ensureMinimumPerPage($requestedPerPage);
            $basePerPage = $this->resolveBasePerPage($requestedPerPage);
            $page = (int)$request->input('page', 1);
            $profileId = $request->query('profileId');
            $profileType = $request->query('profileType'); // 'user' or 'etablissement'

            // Check if profileId and profileType are provided (handle string "null" from frontend)
            $hasProfile = !empty($profileId)
                && !empty($profileType)
                && $profileId !== 'null'
                && $profileType !== 'null';

            // Logging removed for performance

            $userId = ($hasProfile && $profileType === 'user') ? (int)$profileId : null;
            $etabId = ($hasProfile && $profileType === 'etablissement') ? (int)$profileId : null;
            $guestSessionId = $request->query('guestSessionId');
            $offlineTags = $request->query('offlineTags');
            $isDisconnected = !$hasProfile || $request->query('isDisconnected') === 'true';

            // Validate offlineTags: must be a non-empty valid JSON array before passing to MySQL
            $validOfflineTags = null;
            if (!empty($offlineTags) && is_string($offlineTags)) {
                $decoded = json_decode($offlineTags, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && count($decoded) > 0) {
                    $validOfflineTags = $offlineTags;
                }
            }

            if ($isDisconnected) {
                $this->logDisconnectedFlux('flux_request', 'Disconnected flux via get_final_flux_connected', [
                    'guestSessionId' => $guestSessionId,
                    'hasOfflineTags' => !empty($validOfflineTags),
                    'offlineTagsCount' => $validOfflineTags ? count(json_decode($validOfflineTags, true)) : 0,
                    'page' => $page,
                    'perPage' => $basePerPage,
                ]);
            }

            // Unified entry: call proc_get_percentage_mixed_flux for both connected and disconnected users
            try {
                $procedureResults = DB::select('CALL proc_get_percentage_mixed_flux(?, ?, ?, ?, ?)', [
                    $userId,
                    $etabId,
                    $page,
                    $basePerPage,
                    $validOfflineTags
                ]);
            }
            catch (\Exception $procException) {
                if ($isDisconnected) {
                    $this->logDisconnectedFlux('flux_procedure_error', $procException->getMessage(), [
                        'guestSessionId' => $guestSessionId,
                        'hasOfflineTags' => !empty($validOfflineTags),
                    ], 'error');
                }
                $procedureResults = [];
            }

            // Last-resort fallback: if the main procedure returned nothing at all,
            // call proc_get_percentage_mixed_flux directly with null credentials
            if (empty($procedureResults)) {
                if ((int)$page === 1) {
                    try {
                        $procedureResults = DB::select('CALL proc_get_percentage_mixed_flux(?, ?, ?, ?, ?)', [
                            null,
                            null,
                            $page,
                            $basePerPage,
                            $validOfflineTags
                        ]);
                    } catch (\Exception $fallbackException) {
                        $procedureResults = [];
                    }
                }
                else {
                    $procedureResults = [];
                }
            }
            else {
                // Check if results only have IDs (first result check)
                $firstResult = $procedureResults[0] ?? null;
                if ($firstResult && count((array)$firstResult) === 1 && isset($firstResult->id)) {
                    // Results only have IDs, fetch full publication data
                    $ids = array_map(function ($result) {
                        return $result->id;
                    }, $procedureResults);

                    $fullPublications = Publication::query()
                        ->whereIn('id', $ids)
                        ->where('status', 1)
                        ->get();

                    // Preserve order from procedure results
                    $orderedResults = [];
                    foreach ($ids as $id) {
                        $pub = $fullPublications->firstWhere('id', $id);
                        if ($pub) {
                            $orderedResults[] = (object)$pub->toArray();
                        }
                    }
                    $procedureResults = $orderedResults;
                }
            }

            // Pass through procedure results as-is — boosting is handled natively by the SQL procedure
            $finalResults = $procedureResults;

            // Return the raw procedure results in the original structure
            return response()->json([
                'status' => 200,
                'data' => [
                    'publications' => [
                        'data' => $finalResults,
                        'current_page' => $page,
                        'per_page' => $requestedPerPage,
                        'total' => count($finalResults),
                        'has_more' => null,
                        'from' => count($finalResults) > 0 ? 1 : null,
                        'to' => count($finalResults),
                        'last_page' => 1,
                        'first_page_url' => request()->url() . '?page=1',
                        'last_page_url' => request()->url() . '?page=1',
                        'next_page_url' => null,
                        'prev_page_url' => null,
                        'path' => request()->url(),
                        'links' => [
                            [
                                'url' => null,
                                'label' => '&laquo; Previous',
                                'active' => false
                            ],
                            [
                                'url' => request()->url() . '?page=1',
                                'label' => '1',
                                'active' => true
                            ],
                            [
                                'url' => null,
                                'label' => 'Next &raquo;',
                                'active' => false
                            ]
                        ]
                    ]
                ]
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error fetching concept flux publications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get publications using detail_by_pubid procedure
     */
    public function getDetailByPubIdPublications(Request $request)
    {
        try {
            $requestedPerPage = (int)$request->query('perPage', 5);
            $requestedPerPage = $this->ensureMinimumPerPage($requestedPerPage);
            $basePerPage = $this->resolveBasePerPage($requestedPerPage);
            $page = (int)$request->input('page', 1);
            $profileId = $request->query('profileId');
            $profileType = $request->query('profileType');
            $pubId = $request->query('pubId');

            $userId = ($profileType === 'user') ? (int)$profileId : null;
            $etabId = ($profileType === 'etablissement') ? (int)$profileId : null;

            // Call the boosted procedure for post detail flux
            // $boostedResults = DB::select('CALL proc_get_next_boosted_publication(?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            //     null,
            //     null,
            //     $page,
            //     null,
            //     null,
            //     null,
            //     null,
            //     0,
            //     $pubId
            // ]);

            // Call the detail_by_pubid procedure
            $procedureResults = DB::select('CALL detail_by_pubid(?, ?, ?, ?, ?)', [
                $userId,
                $etabId,
                $page,
                $basePerPage,
                $pubId
            ]);

            if (empty($procedureResults)) {
                $procedureResults = [];
            }

            // Pass through procedure results as-is
            $finalResults = $procedureResults;

            return response()->json([
                'status' => 200,
                'data' => [
                    'publications' => [
                        'data' => $finalResults,
                        'current_page' => $page,
                        'per_page' => $requestedPerPage,
                        'total' => count($finalResults),
                        'has_more' => null,
                        'from' => count($finalResults) > 0 ? 1 : null,
                        'to' => count($finalResults),
                        'last_page' => 1,
                        'first_page_url' => request()->url() . '?page=1',
                        'last_page_url' => request()->url() . '?page=1',
                        'next_page_url' => null,
                        'prev_page_url' => null,
                        'path' => request()->url(),
                        'links' => [
                            ['url' => null, 'label' => '&laquo; Previous', 'active' => false],
                            ['url' => request()->url() . '?page=1', 'label' => '1', 'active' => true],
                            ['url' => null, 'label' => 'Next &raquo;', 'active' => false]
                        ]
                    ]
                ]
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error fetching publications by pubId',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get publications using custom flux procedure
     */
    public function getCustomFluxPublications(Request $request)
    {
        try {
            $requestedPerPage = (int)$request->query('perPage', 5);
            $requestedPerPage = $this->ensureMinimumPerPage($requestedPerPage);
            $basePerPage = $this->resolveBasePerPage($requestedPerPage);
            $page = (int)$request->query('page', 1);
            $profileId = $request->query('profileId');
            $profileType = $request->query('profileType'); // 'user' or 'etablissement'
            $customFluxSlug = $request->query('customFlux');

            // Validate customFlux slug is provided
            if (empty($customFluxSlug)) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Custom flux slug is required',
                    'data' => [
                        'publications' => [
                            'data' => [],
                            'current_page' => $page,
                            'per_page' => $requestedPerPage,
                            'total' => 0
                        ]
                    ]
                ], 400);
            }

            // Validate profileId and profileType are provided (required for custom flux)
            if (empty($profileId) || empty($profileType) || $profileId === 'null' || $profileType === 'null') {
                return response()->json([
                    'status' => 400,
                    'message' => 'Profile ID and type are required for custom flux',
                    'data' => [
                        'publications' => [
                            'data' => [],
                            'current_page' => $page,
                            'per_page' => $requestedPerPage,
                            'total' => 0
                        ]
                    ]
                ], 400);
            }

            // Set parameters based on profile type
            $userId = ($profileType === 'user') ? (int)$profileId : null;
            $etabId = ($profileType === 'etablissement') ? (int)$profileId : null;

            $isDefault = false;
            $procedureResults = [];

            // Call the custom_flux_concept_1 procedure
            try {
                $procedureResults = DB::select('CALL custom_flux_concept_1(?, ?, ?, ?, ?)', [
                    $userId,
                    $etabId,
                    $customFluxSlug,
                    $page,
                    $basePerPage
                ]);

            // Procedure executed successfully - return actual results (even if empty)
            // Empty results mean custom flux exists but has no matching publications (valid case)
            // Only mark as DEFAULT if procedure throws an exception

            }
            catch (\Exception $procException) {
                // Procedure failed - mark as default and return empty
                $isDefault = true;
                $procedureResults = [];
            }

            // Pass through procedure results as-is
            $finalResults = $procedureResults;

            // Calculate has_more: if we got exactly basePerPage results, there might be more
            // If we got fewer, we've reached the end
            $resultsCount = count($finalResults);
            $hasMore = $resultsCount >= $basePerPage;

            // Build next_page_url if there are more results
            $nextPageUrl = null;
            if ($hasMore) {
                $nextPage = $page + 1;
                $nextPageUrl = request()->url() . '?page=' . $nextPage . '&perPage=' . $requestedPerPage . '&profileId=' . $profileId . '&profileType=' . $profileType . '&customFlux=' . urlencode($customFluxSlug);
            }

            return response()->json([
                'status' => 200,
                'data' => [
                    'publications' => [
                        'data' => $finalResults,
                        'current_page' => $page,
                        'per_page' => $requestedPerPage,
                        'total' => $resultsCount, // Current page count (procedure doesn't return total)
                        'has_more' => $hasMore,
                        'from' => $resultsCount > 0 ? (($page - 1) * $requestedPerPage + 1) : null,
                        'to' => $resultsCount > 0 ? (($page - 1) * $requestedPerPage + $resultsCount) : null,
                        'last_page' => $hasMore ? null : $page,
                        'first_page_url' => request()->url() . '?page=1&perPage=' . $requestedPerPage . '&profileId=' . $profileId . '&profileType=' . $profileType . '&customFlux=' . urlencode($customFluxSlug),
                        'last_page_url' => $hasMore ? null : (request()->url() . '?page=' . $page . '&perPage=' . $requestedPerPage . '&profileId=' . $profileId . '&profileType=' . $profileType . '&customFlux=' . urlencode($customFluxSlug)),
                        'next_page_url' => $nextPageUrl,
                        'prev_page_url' => $page > 1 ? (request()->url() . '?page=' . ($page - 1) . '&perPage=' . $requestedPerPage . '&profileId=' . $profileId . '&profileType=' . $profileType . '&customFlux=' . urlencode($customFluxSlug)) : null,
                        'path' => request()->url(),
                        'links' => [
                            [
                                'url' => $page > 1 ? (request()->url() . '?page=' . ($page - 1) . '&perPage=' . $requestedPerPage . '&profileId=' . $profileId . '&profileType=' . $profileType . '&customFlux=' . urlencode($customFluxSlug)) : null,
                                'label' => '&laquo; Previous',
                                'active' => false
                            ],
                            [
                                'url' => request()->url() . '?page=' . $page . '&perPage=' . $requestedPerPage . '&profileId=' . $profileId . '&profileType=' . $profileType . '&customFlux=' . urlencode($customFluxSlug),
                                'label' => (string)$page,
                                'active' => true
                            ],
                            [
                                'url' => $nextPageUrl,
                                'label' => 'Next &raquo;',
                                'active' => false
                            ]
                        ]
                    ],
                    'DEFAULT' => $isDefault
                ]
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error fetching custom flux publications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get publications using suivis (followers) procedure
     */
    public function getSuivisPublications(Request $request)
    {
        try {
            $requestedPerPage = (int)$request->query('perPage', 5);
            $requestedPerPage = $this->ensureMinimumPerPage($requestedPerPage);
            $basePerPage = $this->resolveBasePerPage($requestedPerPage);
            $page = (int)$request->input('page', 1);
            $profileId = $request->query('profileId');
            $profileType = $request->query('profileType'); // 'user' or 'etablissement'

            // Check if profileId and profileType are provided (handle string "null" from frontend)
            $hasProfile = !empty($profileId)
                && !empty($profileType)
                && $profileId !== 'null'
                && $profileType !== 'null'
                && $profileId !== '0';

            if (!$hasProfile) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Profile ID and type are required for suivis',
                    'data' => [
                        'publications' => [
                            'data' => [],
                            'current_page' => $page,
                            'per_page' => $requestedPerPage,
                            'total' => 0
                        ]
                    ]
                ], 400);
            }

            // Set parameters based on profile type
            $userId = ($profileType === 'user') ? (int)$profileId : null;
            $etabId = ($profileType === 'etablissement') ? (int)$profileId : null;

            // Call the suivis_concept_1_date procedure directly
            $procedureResults = DB::select('CALL get_final_suivis(?, ?, ?, ?)', [
                $userId,
                $etabId,
                $page,
                $basePerPage
            ]);

            // If procedure returns no results, return empty array
            if (empty($procedureResults)) {
                $procedureResults = [];
            }

            // Pass through procedure results as-is
            $finalResults = $procedureResults;

            return response()->json([
                'status' => 200,
                'data' => [
                    'publications' => [
                        'data' => $finalResults,
                        'current_page' => $page,
                        'per_page' => $requestedPerPage,
                        'total' => count($finalResults),
                        'has_more' => null,
                        'from' => count($finalResults) > 0 ? 1 : null,
                        'to' => count($finalResults),
                        'last_page' => 1,
                        'first_page_url' => request()->url() . '?page=1',
                        'last_page_url' => request()->url() . '?page=1',
                        'next_page_url' => null,
                        'prev_page_url' => null,
                        'path' => request()->url(),
                        'links' => [
                            [
                                'url' => null,
                                'label' => '&laquo; Previous',
                                'active' => false
                            ],
                            [
                                'url' => request()->url() . '?page=1',
                                'label' => '1',
                                'active' => true
                            ],
                            [
                                'url' => null,
                                'label' => 'Next &raquo;',
                                'active' => false
                            ]
                        ]
                    ]
                ]
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error fetching suivis publications',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    private function ensureMinimumPerPage(int $requestedPerPage): int
    {
        return max($requestedPerPage, 2);
    }

    private function resolveBasePerPage(int $requestedPerPage): int
    {
        // Pass the full perPage to the SQL procedure — boosting is handled natively in SQL, no slot reservation needed
        return max($requestedPerPage, 1);
    }

    private function buildProfileContext(?string $profileType, $profileId): array
    {
        $userId = null;
        $etabId = null;

        if ($profileType === 'user' && !empty($profileId) && $profileId !== 'null') {
            $userId = (int)$profileId;
        }
        elseif ($profileType === 'etablissement' && !empty($profileId) && $profileId !== 'null') {
            $etabId = (int)$profileId;
        }

        return [
            'userId' => $userId,
            'etabId' => $etabId
        ];
    }

    private function injectBoostOrAd(
        array $baseResults,
        int $page,
        int $requestedPerPage,
        ?int $userId,
        ?int $etabId,
        bool $isAppMobile = false
        ): array
    {
        $requestedPerPage = $this->ensureMinimumPerPage($requestedPerPage);
        $basePerPage = $this->resolveBasePerPage($requestedPerPage);
        $trimmedBase = array_slice($baseResults, 0, $basePerPage);

        // Extract publication IDs from current page results to exclude from boosts
        $excludedIds = $this->extractPublicationIds($trimmedBase);

        // Format all baseline results to make sure they have profile, tags_list, stats, etc.
        foreach ($trimmedBase as $key => $item) {
            $trimmedBase[$key] = $this->formatPublicationWithRelations($item);
            $item = $trimmedBase[$key];
            if (isset($item->stats) && is_string($item->stats)) {
                $decodedStats = json_decode($item->stats);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $item->reactions = $decodedStats->reactions ?? null;
                }
            }
        }

        $injectionData = $this->resolveInjectionPayload($userId, $etabId, $page, $requestedPerPage, $excludedIds, $isAppMobile);
        $injectedItem = $injectionData['payload'];

        if ($injectedItem && (!isset($injectedItem->type) || $injectedItem->type !== 'ad')) {
            $injectedItem = $this->formatPublicationWithRelations($injectedItem, 'boosted');
        }

        if ($injectedItem && isset($injectedItem->stats) && is_string($injectedItem->stats)) {
            $decodedStats = json_decode($injectedItem->stats);
            if (json_last_error() === JSON_ERROR_NONE) {
                $injectedItem->reactions = $decodedStats->reactions ?? null;
            }
        }

        // If no injection item (null payload), return base results without injection
        if ($injectedItem === null) {
            return $trimmedBase;
        }

        if (empty($trimmedBase)) {
            return [$injectedItem];
        }

        $insertIndex = $this->mapSlotToIndex(
            $injectionData['slot'],
            count($trimmedBase),
            $requestedPerPage
        );
        array_splice($trimmedBase, $insertIndex, 0, [$injectedItem]);

        return $trimmedBase;
    }

    private function getBoostedInjectionRow(?int $userId, ?int $etabId, int $page, int $perPage, string $excludedIds = '')
    {
        try {
            $results = DB::select('CALL get_boosted_ads_publications(?, ?, ?, ?, ?)', [
                $userId,
                $etabId,
                $page,
                $perPage,
                $excludedIds
            ]);

            return $results[0] ?? null;
        }
        catch (\Exception $e) {
            return null;
        }
    }

    private function buildAdPlaceholder(): object
    {
        return (object)['type' => 'ad'];
    }

    private function resolveInjectionIndex(int $baseCount, int $requestedPerPage): int
    {
        if ($baseCount <= 1) {
            return min(1, $baseCount);
        }

        $maxPosition = max(1, min($baseCount, $requestedPerPage - 1));
        $position = random_int(1, $maxPosition);

        return $position;
    }

    private function extractPublicationIds(array $results): string
    {
        $ids = [];
        foreach ($results as $result) {
            if (isset($result->id) && is_numeric($result->id)) {
                $ids[] = (int)$result->id;
            }
        }
        return implode(',', $ids);
    }

    private function resolveInjectionPayload(
        ?int $userId,
        ?int $etabId,
        int $page,
        int $requestedPerPage,
        string $excludedIds = '',
        bool $isAppMobile = false
        ): array
    {
        $row = $this->getBoostedInjectionRow($userId, $etabId, $page, $requestedPerPage, $excludedIds);

        if (!$row) {
            // If isAppMobile is true, don't inject ads, return empty (no injection)
            if ($isAppMobile) {
                return [
                    'payload' => null,
                    'slot' => null
                ];
            }
            return [
                'payload' => $this->buildAdPlaceholder(),
                'slot' => null
            ];
        }

        $slot = isset($row->injection_slot) ? (int)$row->injection_slot : null;
        $type = $row->injection_type ?? null;

        // If isAppMobile is true and type is 'ad', skip it (don't inject ads in mobile app)
        if ($isAppMobile && $type === 'ad') {
            return [
                'payload' => null,
                'slot' => null
            ];
        }

        if ($type === 'ad') {
            $payload = $this->decodeAdPayload($row->ad_payload ?? null);
        }
        else {
            unset($row->injection_type, $row->injection_slot, $row->random_inject_token, $row->ad_payload);
            $payload = $row;
        }

        return [
            'payload' => $payload,
            'slot' => $slot
        ];
    }

    private function decodeAdPayload($payload)
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                return $decoded;
            }
        }
        elseif (is_object($payload) || is_array($payload)) {
            return $payload;
        }

        return $this->buildAdPlaceholder();
    }

    private function mapSlotToIndex(?int $slot, int $baseCount, int $requestedPerPage): int
    {
        if ($slot === null) {
            return $this->resolveInjectionIndex($baseCount, $requestedPerPage);
        }

        $slot = max(1, min($slot, $requestedPerPage));
        $index = $slot - 1;

        if ($index > $baseCount) {
            $index = $baseCount;
        }

        return $index;
    }

    private function formatPublicationWithRelations($pub, $source = 'score')
    {
        $obj = is_object($pub) ? $pub : (object)$pub;
        
        // Ensure source is set
        if (!isset($obj->source) || empty($obj->source)) {
            $obj->source = $source;
        }

        // Ensure stats is set
        if (!isset($obj->stats) || empty($obj->stats)) {
            $obj->stats = json_encode([
                'click_sauvgarder' => 0,
                'click_partage' => 0,
                'vues' => 0,
                'reactions' => [
                    'total' => 0,
                    'most_reacted' => [],
                    'user_reaction' => null
                ]
            ]);
        }

        // Ensure profile is set
        if (!isset($obj->profile) || empty($obj->profile)) {
            $user = isset($obj->user_id) && $obj->user_id ? \App\Models\User::find($obj->user_id) : null;
            $etab = isset($obj->etab_id) && $obj->etab_id ? \App\Models\Etablissement::find($obj->etab_id) : null;

            if ($etab) {
                $obj->profile = json_encode([
                    'id' => $etab->id,
                    'slug' => $etab->slug,
                    'name' => $etab->nom,
                    'avatar' => $etab->logo
                ]);
            } elseif ($user) {
                $obj->profile = json_encode([
                    'id' => $user->id,
                    'slug' => $user->slug,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'avatar' => $user->avatar
                ]);
            } else {
                $obj->profile = null;
            }
        }

        // Ensure tags_list is set
        if (!isset($obj->tags_list) || empty($obj->tags_list)) {
            $tags = DB::table('publications_tags_flink')
                ->join('tags_flink', 'publications_tags_flink.tag_id', '=', 'tags_flink.id')
                ->where('publications_tags_flink.publication_id', $obj->id)
                ->select('tags_flink.id', 'tags_flink.name', 'tags_flink.slug')
                ->get();
            $obj->tags_list = json_encode($tags->toArray());
        }

        // Ensure other fields
        if (!isset($obj->isSaved)) {
            $obj->isSaved = 0;
        }
        if (!isset($obj->isFollowed)) {
            $obj->isFollowed = 0;
        }
        if (!isset($obj->publications_booster)) {
            $obj->publications_booster = json_encode([]);
        }

        return $obj;
    }

    private function logDisconnectedFlux(string $event, string $message, array $context = [], string $level = 'info'): void
    {
        try {
            $line = sprintf(
                '%s [%s] %s: %s %s',
                now()->toIso8601String(),
                $level,
                $event,
                $message,
                $context ? json_encode($context) : ''
            );

            $logPath = storage_path('logs/disconnected_flux.log');
            @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

            if ($level === 'error') {
                Log::error('disconnected_flux: ' . $message, $context);
            } else {
                Log::info('disconnected_flux: ' . $message, $context);
            }
        } catch (\Throwable $e) {
            // ignore logging failures
        }
    }
}