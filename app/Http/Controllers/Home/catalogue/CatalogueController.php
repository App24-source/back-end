<?php

namespace App\Http\Controllers\Home\catalogue;

use App\Http\Controllers\Controller;
use App\Http\Controllers\publication\create\CreatePublicationController;
use App\Models\CatalogueFlink;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Tags;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Illuminate\Support\Carbon;
use App\Events\CatalogueProgressUpdated;

/**
 * CatalogueController
 *
 * Handles bulk CSV/XLSX catalogue imports for établissements.
 * Each row is parsed, validated, and converted into a publication
 * using the same logic as the manual CreatePublicationController::store().
 *
 * Route (inside auth:sanctum group in api.php):
 *   Route::post('/catalogue/import', [CatalogueController::class, 'import']);
 *
 * CSV Columns:
 *   Required:  image_default OR video_origine, tags, city_id (if is_marketplace=1)
 *   Optional:  description, price, promo_price, country_id, region_id,
 *              phone, whatsapp, delivery_modes, availability, is_marketplace
 */
class CatalogueController extends Controller
{
    private ?\Psr\Log\LoggerInterface $catalogueLogger = null;
    private ?\Psr\Log\LoggerInterface $errorLogger = null;
    private const STORE_MAX_RETRIES = 3;
    private array $tagSlugToIdCache = [];

    public function __construct()
    {
        $this->catalogueLogger = \Log::channel('catalogues');
    }

    private const HEADER_SYNONYMS = [
        'cat_post_id' => ['cat_post_id', 'post_id', 'catalog_post_id', 'id'],
        'image_default' => ['image_default', 'main_image', 'image_url'],
        'images' => ['images', 'gallery', 'additional_images'],
        'tags' => ['contextual keywords', 'tags', 'keywords', 'mots_clés', 'mots_cles'],
        'title' => ['item title', 'title', 'titre', 'name', 'nom'],
        'description' => ['item description', 'description', 'content', 'detail', 'détail'],
        'price' => ['price', 'item_price', 'prix'],
        'promo_price' => ['sale price', 'promo_price', 'sale_price', 'prix_promo'],
        'phone' => ['phone', 'telephone', 'téléphone', 'tele', 'télé'],
        'whatsapp' => ['whatsapp', 'whatsapp_phone', 'telephone_whatsapp', 'tele_whatsapp'],
        'pays' => ['pays', 'country', 'country_code', 'pays_code'],
        'ville' => ['ville', 'city', 'city_name', 'ville_name', 'item_address'],
        'region' => ['region', 'region_name'],
        'mode_livraison' => ['mode_livraison', 'mode_laivraison', 'mode__laivraison', 'modes_livraison', 'modes_laivraison', 'delivery_modes'],
        'disponibilite' => ['disponibilite', 'availability', 'stock'],
        'disponibilite_date' => ['disponibilite_date', 'availability_date'],
    ];

    // ─── Delivery mode name → ID mapping ──────────────────────────────────────
    private const DELIVERY_MODE_MAP = [
        'livraison' => '1',
        'retrait' => '2',
        'rencontre' => '3',
    ];

    // ─── Valid availability values ─────────────────────────────────────────────
    private const AVAILABILITY_VALUES = ['piece_unique', 'toujours_en_stock', 'inStock', 'vendu', 'unique'];

    // ─── Valid refresh intervals ───────────────────────────────────────────────
    private const REFRESH_INTERVALS = [
        'daily',
        'every_2_days',
        'every_3_days',
        'weekly',
        'every_2_weeks',
        'monthly',
        'yearly',
    ];

    private const DEFAULT_FLINK_SHOP_SLOTS_MAX = 5;
    private const MAX_SLOT_PURCHASE_PER_ORDER = 10;
    private const SLOT_PRICE_DH = 30;
    private const MAX_POSTS_PER_CATALOGUE = 1000;
    private const OVERFLOW_MODES = ['cancel_all', 'fill_current'];
    private const MAX_CSV_BYTES = 5_242_880; // 5 MB
    private const MAX_CSV_ROWS = 5000;
    private const MAX_CELL_LENGTH = 4000;
    private const MAX_COLUMNS = 80;
    private const PROCESSING_STATE_PENDING    = 'pending';
    private const PROCESSING_STATE_RUNNING    = 'running';
    private const PROCESSING_STATE_PROCESSING = 'processing';
    private const PROCESSING_STATE_FINISHED   = 'finished';
    private const PROCESSING_STATE_FAILED     = 'failed';
    private const ALLOWED_UPLOAD_EXTENSIONS = ['csv', 'txt'];
    private const ALLOWED_UPLOAD_MIMES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'text/comma-separated-values',
    ];

    /**
     * POST /api/profile/flink_catalogues
     *
     * Returns owner's catalogue groups with publication card payload.
     * Expected payload:
     *   id   (int)    -> etablissement id
     *   type (string) -> must be "etablissement"
     */
    public function getProfileCatalogues( Request $request )
    {
        try {

            $profileType = strtolower( (string) $request->input( 'type', '' ) );
            if ($profileType !== 'etablissement') {
                return response()->json( [
                    'success' => true,
                    'data' => [],
                ] );
            }

            $etabId = (int) $request->input( 'id' );
            if ($etabId <= 0) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Etablissement invalide.',
                    'data' => [],
                ], 422 );
            }
        } catch (\Exception $e) {
            $this->logError('Exception in getProfileCatalogues', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error_code' => 'GET_PROFILE_CATALOGUES_ERROR'
            ], 500);
        }

        $slotsMax = $this->getEtabSlotsMax( $etabId );
        $authUserId = (int) optional( $request->user() )->id;
        $isOwner = false;
        $ownerContextRequested = (bool) $request->boolean( 'owner_context', false );
        if ($ownerContextRequested) {
            // Owner tab explicitly requests owner context (status 0 + 1).
            // Endpoint is public in this project, so auth user can be unavailable.
            $isOwner = true;
        } elseif (
            $authUserId > 0
            && Schema::hasTable( 'etablissements' )
            && Schema::hasColumn( 'etablissements', 'user_id' )
        ) {
            $isOwner = DB::table( 'etablissements' )
                ->where( 'id', $etabId )
                ->where( 'user_id', $authUserId )
                ->exists();
        }

        $catalogues = CatalogueFlink::query()
            ->where( 'etab_id', $etabId )
            ->orderByDesc( 'id' )
            ->get( $this->getCatalogueSelectColumns() );

        if ($catalogues->isEmpty()) {
            return response()->json( [
                'success' => true,
                'data' => [],
                'slots' => [
                    'max' => $slotsMax,
                    'used' => 0,
                    'remaining' => max( 0, $slotsMax ),
                    'price_per_slot' => self::SLOT_PRICE_DH,
                    'max_purchase_per_order' => self::MAX_SLOT_PURCHASE_PER_ORDER,
                ],
            ] );
        }

        $catalogueIds = $catalogues->pluck( 'id' )->all();
        $posts = DB::table( 'publications_flink' )
            ->whereIn( 'catalogue_id', $catalogueIds )
            ->whereNotNull( 'slug' )
            ->whereNotNull( 'image_default' )
            ->when( $isOwner, function ( $q ) {
                $q->whereIn( 'status', [0, 1] );
            }, function ( $q ) {
                $q->where( 'status', 1 );
            } )
            ->orderByDesc( 'id' )
            ->get( [
                'id',
                'catalogue_id',
                'slug',
                'image_default',
                'prix',
                'prixPromo',
                'status',
                'description',
                'created_at',
                'updated_at',
            ] )
            ->groupBy( 'catalogue_id' );

        $data = $catalogues->map( function ( $catalogue ) use ( $posts ) {
            $cataloguePosts = ( $posts[$catalogue->id] ?? collect() )->values();

            return [
                'id' => $catalogue->id,
                'catalogue_group' => $catalogue->catalogue_group,
                'csv_url' => $catalogue->source_file,
                'refresh_interval' => $catalogue->refresh_interval,
                'refresh_time' => $catalogue->refresh_time,
                'source_type' => filter_var( (string) $catalogue->source_file, FILTER_VALIDATE_URL ) ? 'url' : 'file',
                'processing_state' => (string) ( $catalogue->processing_state ?? self::PROCESSING_STATE_FINISHED ),
                'processing_operation' => $catalogue->processing_operation ?? null,
                'processing_started_at' => $catalogue->processing_started_at ?? null,
                'processing_finished_at' => $catalogue->processing_finished_at ?? null,
                'processing_message' => $catalogue->processing_message ?? null,
                'processing_current' => isset( $catalogue->processing_current ) ? (int) $catalogue->processing_current : null,
                'processing_total' => isset( $catalogue->processing_total ) ? (int) $catalogue->processing_total : null,
                'completion_notice_pending' => isset( $catalogue->completion_notice_pending ) ? (int) $catalogue->completion_notice_pending : 0,
                'completion_notice_ready' => $this->isCompletionNoticeReady( $catalogue ),
                'posts_count' => $cataloguePosts->count(),
                'posts' => $cataloguePosts,
            ];
        } )->values();

        return response()->json( [
            'success' => true,
            'data' => $data,
            'slots' => [
                'max' => $slotsMax,
                'used' => $catalogues->count(),
                'remaining' => max( 0, $slotsMax - $catalogues->count() ),
            ],
        ] );
    }

    /**
     * POST /api/catalogue/slots/purchase
     *
     * Increases Flink Shop slots capacity for an établissement.
     */
    public function purchaseSlots( Request $request )
    {
        $etabId = (int) $request->input( 'etab_id' );
        $slots = (int) $request->input( 'slots' );

        if ($etabId <= 0 || $slots <= 0) {
            return response()->json( [
                'success' => false,
                'message' => 'Paramètres invalides.',
            ], 422 );
        }

        if ($slots > self::MAX_SLOT_PURCHASE_PER_ORDER) {
            return response()->json( [
                'success' => false,
                'message' => 'Le nombre de slots dépasse la limite autorisée.',
            ], 422 );
        }

        $newSlotsMax = DB::transaction( function () use ($etabId, $slots) {
            $row = DB::table( 'etablissements' )
                ->where( 'id', $etabId )
                ->lockForUpdate()
                ->first( ['id', 'flink_shop_slots_max'] );

            if (!$row) {
                throw ValidationException::withMessages( [
                    'etab_id' => 'Établissement introuvable.',
                ] );
            }

            $currentMax = (int) ( $row->flink_shop_slots_max ?? self::DEFAULT_FLINK_SHOP_SLOTS_MAX );
            if ($currentMax <= 0) {
                $currentMax = self::DEFAULT_FLINK_SHOP_SLOTS_MAX;
            }
            $nextMax = $currentMax + $slots;

            DB::table( 'etablissements' )
                ->where( 'id', $etabId )
                ->update( ['flink_shop_slots_max' => $nextMax] );

            return $nextMax;
        } );

        $used = (int) CatalogueFlink::query()->where( 'etab_id', $etabId )->count();

        return response()->json( [
            'success' => true,
            'message' => 'Slots Flink Shop ajoutés avec succès.',
            'slots' => [
                'max' => $newSlotsMax,
                'used' => $used,
                'remaining' => max( 0, $newSlotsMax - $used ),
                'price_per_slot' => self::SLOT_PRICE_DH,
            ],
        ] );
    }

    private function getCatalogueSelectColumns(): array
    {
        $table = $this->getCatalogueTableName();
        $columns = ['id', 'catalogue_group', 'source_file', 'refresh_interval', 'refresh_time'];
        foreach ([
            'processing_state',
            'processing_operation',
            'processing_started_at',
            'processing_finished_at',
            'processing_message',
            'processing_current',
            'processing_total',
            'completion_notice_pending',
            'completion_notice_operation',
            'completion_notice_finished_at',
            'completion_notice_seen_at'
        ] as $column) {
            if (Schema::hasColumn( $table, $column )) {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    private function isCatalogueProcessing( CatalogueFlink $catalogue ): bool
    {
        $state = (string) ( $catalogue->processing_state ?? '' );
        return in_array( $state, [
            self::PROCESSING_STATE_PENDING,
            self::PROCESSING_STATE_RUNNING,
            self::PROCESSING_STATE_PROCESSING
        ], true );
    }

    private function markCatalogueProcessingState(
        CatalogueFlink $catalogue,
        string $state,
        string $operation,
        ?string $message = null
    ): void {
        $table = $this->getCatalogueTableName();
        if (!Schema::hasTable( $table )) {
            return;
        }

        $payload = [];
        if (Schema::hasColumn( $table, 'processing_state' )) {
            $payload['processing_state'] = $state;
        }
        if (Schema::hasColumn( $table, 'processing_operation' )) {
            $payload['processing_operation'] = $operation;
        }
        if (Schema::hasColumn( $table, 'processing_message' )) {
            $payload['processing_message'] = $message;
        }
        if (Schema::hasColumn( $table, 'processing_started_at' ) && in_array( $state, [self::PROCESSING_STATE_PENDING, self::PROCESSING_STATE_RUNNING], true )) {
            $payload['processing_started_at'] = now();
        }
        if (Schema::hasColumn( $table, 'processing_current' ) && in_array( $state, [self::PROCESSING_STATE_PENDING, self::PROCESSING_STATE_RUNNING], true )) {
            $payload['processing_current'] = 0;
        }
        if (Schema::hasColumn( $table, 'processing_total' ) && in_array( $state, [self::PROCESSING_STATE_PENDING, self::PROCESSING_STATE_RUNNING], true )) {
            $payload['processing_total'] = null;
        }
        if (Schema::hasColumn( $table, 'processing_finished_at' ) && in_array( $state, [self::PROCESSING_STATE_FINISHED, self::PROCESSING_STATE_FAILED], true )) {
            $payload['processing_finished_at'] = now();
        }
        if (Schema::hasColumn( $table, 'processing_current' ) && in_array( $state, [self::PROCESSING_STATE_FINISHED, self::PROCESSING_STATE_FAILED], true )) {
            $payload['processing_current'] = null;
        }
        if (Schema::hasColumn( $table, 'processing_total' ) && in_array( $state, [self::PROCESSING_STATE_FINISHED, self::PROCESSING_STATE_FAILED], true )) {
            $payload['processing_total'] = null;
        }
        if (Schema::hasColumn( $table, 'completion_notice_pending' ) && in_array( $state, [self::PROCESSING_STATE_PENDING, self::PROCESSING_STATE_RUNNING], true )) {
            $payload['completion_notice_pending'] = 0;
        }
        if (Schema::hasColumn( $table, 'completion_notice_seen_at' ) && in_array( $state, [self::PROCESSING_STATE_PENDING, self::PROCESSING_STATE_RUNNING], true )) {
            $payload['completion_notice_seen_at'] = null;
        }
        if (Schema::hasColumn( $table, 'completion_notice_finished_at' ) && in_array( $state, [self::PROCESSING_STATE_PENDING, self::PROCESSING_STATE_RUNNING], true )) {
            $payload['completion_notice_finished_at'] = null;
        }
        if (Schema::hasColumn( $table, 'completion_notice_operation' ) && in_array( $state, [self::PROCESSING_STATE_PENDING, self::PROCESSING_STATE_RUNNING], true )) {
            $payload['completion_notice_operation'] = $operation;
        }
        if (Schema::hasColumn( $table, 'completion_notice_pending' ) && $state === self::PROCESSING_STATE_FINISHED) {
            $payload['completion_notice_pending'] = 1;
        }
        if (Schema::hasColumn( $table, 'completion_notice_finished_at' ) && $state === self::PROCESSING_STATE_FINISHED) {
            $payload['completion_notice_finished_at'] = now();
        }
        if (Schema::hasColumn( $table, 'completion_notice_operation' ) && $state === self::PROCESSING_STATE_FINISHED) {
            $payload['completion_notice_operation'] = $operation;
        }

        if (empty( $payload )) {
            return;
        }

        CatalogueFlink::query()->where( 'id', $catalogue->id )->update( $payload );
        $catalogue->refresh();
    }

    private function isCompletionNoticeReady( $catalogue ): bool
    {
        $pending = (int) ( $catalogue->completion_notice_pending ?? 0 );
        if ($pending !== 1) {
            return false;
        }
        $finishedAt = $catalogue->completion_notice_finished_at ?? null;
        if (!$finishedAt) {
            return false;
        }
        try {
            return Carbon::parse( $finishedAt )->lte( now()->subMinutes( 10 ) );
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ─── Logs (catalogues_logs) ───────────────────────────────────────────────
    private function createCatalogueLog(
        int $etabId,
        int $userId,
        int $catalogueId,
        string $operation,
        bool $automatic
    ): int {
        if (!Schema::hasTable('catalogues_logs')) {
            return 0;
        }
        $row = [
            'etab_id' => $etabId,
            'user_id' => $userId,
            'catalogue_id' => $catalogueId,
            'operation' => $operation,
            'is_automatic' => $automatic ? 1 : 0,
            'created_count' => 0,
            'updated_count' => 0,
            'deleted_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'success' => 0,
            'infos_shown_to_profile' => 0,
            'started_at' => now(),
        ];
        $row = $this->mergeTableTimestampsForInsert('catalogues_logs', $row);
        return (int) DB::table('catalogues_logs')->insertGetId($row);
    }

    private function finishCatalogueLog(
        int $logId,
        array $counts,
        bool $success,
        $message = null
    ): void {
        if ($logId <= 0 || !Schema::hasTable('catalogues_logs')) return;
        
        $finalMessage = is_array($message) ? json_encode($message) : (string) $message;
        
        $payload = [
            'created_count' => (int) ($counts['created'] ?? 0),
            'updated_count' => (int) ($counts['updated'] ?? 0),
            'deleted_count' => (int) ($counts['deleted'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
            'success' => $success ? 1 : 0,
            'message' => $finalMessage,
            'finished_at' => now(),
            'infos_shown_to_profile' => 0,
        ];
        $payload = $this->mergeTableTimestampsForUpdate('catalogues_logs', $payload);
        DB::table('catalogues_logs')->where('id', $logId)->update($payload);
    }

    /** Fields logged from each CSV row (file → conversion → database). */
    private const CATALOGUE_ROW_LOG_FIELDS = [
        'cat_post_id',
        'image_default',
        'images',
        'tags',
        'title',
        'description',
        'price',
        'promo_price',
        'phone',
        'whatsapp',
        'pays',
        'ville',
        'region',
        'mode_livraison',
        'delivery_modes',
        'disponibilite',
        'disponibilite_date',
        'video_origine',
        'brand',
        'model',
        'availability',
    ];

    private function normalizeCatalogueLogValue( mixed $value ): mixed
    {
        if ($value === null) {
            return null;
        }
        $str = trim( (string) $value );
        if ($str === '') {
            return null;
        }
        if (mb_strlen( $str ) > 2000) {
            return mb_substr( $str, 0, 2000 ) . '…[truncated]';
        }

        return $str;
    }

    /**
     * Raw values read from the catalogue file (first extraction, before WebP conversion).
     */
    private function snapshotCatalogueRowExtracted( array $row, int $rowNum ): array
    {
        $snapshot = ['row' => $rowNum];
        foreach (self::CATALOGUE_ROW_LOG_FIELDS as $field) {
            if (array_key_exists( $field, $row )) {
                $snapshot[$field] = $this->normalizeCatalogueLogValue( $row[$field] );
            }
        }

        return $snapshot;
    }

    /**
     * Values that will be persisted: publication fields + converted image paths on FTP.
     */
    private function snapshotCatalogueRowDatabasePayload(
        array $row,
        int $rowNum,
        int $catalogueId,
        ?string $catPostId,
        ?array $processedImages,
        Request $syntheticRequest
    ): array {
        $data = $syntheticRequest->all();
        $imagesGallery = null;
        if ($processedImages !== null && !empty( $processedImages['images'] )) {
            $decoded = json_decode( (string) $processedImages['images'], true );
            $imagesGallery = is_array( $decoded ) ? $decoded : $processedImages['images'];
        }

        return [
            'row' => $rowNum,
            'cat_post_id' => $catPostId,
            'catalogue_id' => $catalogueId,
            'extracted_from_file' => [
                'image_default' => $this->normalizeCatalogueLogValue( $row['image_default'] ?? null ),
                'images' => $this->normalizeCatalogueLogValue( $row['images'] ?? null ),
            ],
            'converted_webp_paths' => $processedImages === null ? null : [
                'image_default' => $processedImages['main'] ?? null,
                'image_square' => $processedImages['square'] ?? null,
                'images' => $imagesGallery,
            ],
            'publication_fields' => [
                'description' => $data['description'] ?? null,
                'prix' => $data['prix'] ?? null,
                'prixPromo' => $data['prixPromo'] ?? null,
                'pays_id' => $data['pays_id'] ?? null,
                'ville_id' => $data['ville_id'] ?? null,
                'region_id' => $data['region_id'] ?? null,
                'tele' => $data['tele'] ?? null,
                'tele_whatsapp' => $data['tele_whatsapp'] ?? null,
                'tag_id' => $data['tag_id'] ?? null,
                'tag_name' => $data['tag_name'] ?? null,
                'mode_laivraison_id' => $data['mode_laivraison_id'] ?? null,
                'disponibilite' => $data['disponibilite'] ?? null,
                'disponibilite_date' => $data['disponibilite_date'] ?? null,
                'is_marketplace' => $data['is_marketplace'] ?? null,
                'etab_id' => $data['etab_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
            ],
            'uploaded_file_fields' => array_keys( $syntheticRequest->allFiles() ),
        ];
    }

    private function persistCatalogueLogMessage( int $logId, array $document ): void
    {
        if ($logId <= 0 || !Schema::hasTable( 'catalogues_logs' )) {
            return;
        }

        $json = json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        if ($json !== false && strlen( $json ) > 65000) {
            foreach (['extracted_from_file', 'database_save_plan'] as $key) {
                if (!empty( $document[$key] ) && is_array( $document[$key] ) && count( $document[$key] ) > 50) {
                    $document[$key . '_truncated'] = true;
                    $document[$key . '_total'] = count( $document[$key] );
                    $document[$key] = array_slice( $document[$key], 0, 50 );
                }
            }
            $json = json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        }

        DB::table( 'catalogues_logs' )->where( 'id', $logId )->update(
            $this->mergeTableTimestampsForUpdate( 'catalogues_logs', [
                'message' => $json !== false ? $json : json_encode( ['error' => 'json_encode_failed'] ),
            ] )
        );
    }

    /**
     * Write catalogue row snapshots to storage/logs/catalogues.log and catalogues_logs.message (JSON).
     */
    private function logCatalogueImportRowsDocument(
        int $logId,
        int $catalogueId,
        string $operation,
        string $phase,
        array $rows,
        ?array $meta = null
    ): void {
        $document = [
            'catalogue_id' => $catalogueId,
            'operation' => $operation,
            'phase' => $phase,
            'logged_at' => now()->toIso8601String(),
            'rows_count' => count( $rows ),
            'rows' => $rows,
        ];
        if ($meta !== null) {
            $document['meta'] = $meta;
        }

        $this->logInfo( "Catalogue import — {$phase}", [
            'catalogue_id' => $catalogueId,
            'log_id' => $logId,
            'rows_count' => count( $rows ),
            'rows_json' => $rows,
        ] );

        if ($logId <= 0) {
            return;
        }

        $existing = [];
        $raw = DB::table( 'catalogues_logs' )->where( 'id', $logId )->value( 'message' );
        if (is_string( $raw ) && $raw !== '') {
            $decoded = json_decode( $raw, true );
            if (is_array( $decoded )) {
                $existing = $decoded;
            }
        }

        $existing[$phase] = $rows;
        $existing['catalogue_id'] = $catalogueId;
        $existing['operation'] = $operation;
        $existing['last_phase'] = $phase;
        $existing['updated_at'] = now()->toIso8601String();
        if ($meta !== null) {
            $existing['meta'] = array_merge( $existing['meta'] ?? [], $meta );
        }

        $this->persistCatalogueLogMessage( $logId, $existing );
    }

    public function getPendingCatalogueLogs(Request $request)
    {
        $etabId = (int) $request->input('etab_id');
        $catalogueId = (int) $request->input('catalogue_id');
        if ($etabId <= 0 || !Schema::hasTable('catalogues_logs')) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $catTable = $this->getCatalogueTableName();
        $q = DB::table('catalogues_logs as cl')
            ->when(Schema::hasTable($catTable), function ($query) use ($catTable) {
                return $query->leftJoin($catTable.' as cf', 'cf.id', '=', 'cl.catalogue_id')
                    ->select('cl.*', 'cf.catalogue_group as catalogue_group');
            }, function ($query) {
                return $query->select('cl.*');
            })
            ->where('cl.etab_id', $etabId)
            ->where('cl.infos_shown_to_profile', 0)
            ->whereNotNull('cl.finished_at')
            ->orderByDesc('cl.id');
        if ($catalogueId > 0) {
            $q->where('cl.catalogue_id', $catalogueId);
        }
        $rows = $q->limit(5)->get()->map(function ($row) {
            return $this->normalizeCatalogueLogRowForApi($row);
        });
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function getCatalogueLogsHistory(Request $request)
    {
        $etabId = (int) $request->input('etab_id');
        $catalogueId = (int) $request->input('catalogue_id');
        if ($etabId <= 0 || !Schema::hasTable('catalogues_logs')) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $since = now()->subMonth();
        $q = DB::table('catalogues_logs')
            ->where('etab_id', $etabId)
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $since)
            ->orderByDesc('id');
        if ($catalogueId > 0) {
            $q->where('catalogue_id', $catalogueId);
        }
        $rows = $q->limit(30)->get()->map(function ($row) {
            return $this->normalizeCatalogueLogRowForApi($row);
        });
        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Ensure timestamps are unambiguous ISO-8601 so clients parse correct instant in any timezone.
     *
     * @param  object|array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeCatalogueLogRowForApi($row): array
    {
        $arr = is_array($row) ? $row : (array) $row;
        foreach (['finished_at', 'started_at', 'created_at', 'updated_at'] as $key) {
            if (empty($arr[$key])) {
                continue;
            }
            try {
                $arr[$key] = \Carbon\Carbon::parse($arr[$key])->toIso8601String();
            } catch (\Throwable $e) {
                // keep raw string if parse fails
            }
        }
        return $arr;
    }

    public function acknowledgeCatalogueLog(Request $request)
    {
        $logId = (int) $request->input('log_id');
        $etabId = (int) $request->input('etab_id');
        if ($logId <= 0 || $etabId <= 0 || !Schema::hasTable('catalogues_logs')) {
            return response()->json(['success' => false], 422);
        }
        DB::table('catalogues_logs')
            ->where('id', $logId)
            ->where('etab_id', $etabId)
            ->update($this->mergeTableTimestampsForUpdate('catalogues_logs', [
                'infos_shown_to_profile' => 1,
            ]));
        return response()->json(['success' => true]);
    }

    private function updateCatalogueProcessingProgress( int $catalogueId, int $current, int $total, ?array $stats = null, ?array $newPost = null, string $state = 'running' ): void
    {
        $table = $this->getCatalogueTableName();
        if (!Schema::hasTable( $table )) {
            return;
        }
        $payload = [];
        if (Schema::hasColumn( $table, 'processing_state' )) {
            // Map internal 'running' state to 'processing' for frontend spinner consistency
            $dbState = in_array($state, ['running', 'processing'], true) ? self::PROCESSING_STATE_PROCESSING : $state;
            $payload['processing_state'] = $dbState;
        }
        if (Schema::hasColumn( $table, 'processing_current' )) {
            $payload['processing_current'] = max( 0, $current );
        }
        if (Schema::hasColumn( $table, 'processing_total' )) {
            $payload['processing_total'] = max( 0, $total );
        }

        // Store detailed stats for SSE real-time tracking
        if ($stats !== null && Schema::hasColumn( $table, 'sync_stats' )) {
            $payload['sync_stats'] = json_encode( [
                'created' => $stats['created'] ?? 0,
                'updated' => $stats['updated'] ?? 0,
                'deleted' => $stats['deleted'] ?? 0,
                'skipped' => $stats['skipped'] ?? 0,
                'failed' => $stats['failed'] ?? 0,
                'last_processed_post_id' => $stats['last_post_id'] ?? null,
                'last_action' => $stats['last_action'] ?? null,
                'updated_at' => now()->toIso8601String(),
            ] );
        }

        if (empty( $payload )) {
            return;
        }
        CatalogueFlink::query()->where( 'id', $catalogueId )->update( $payload );

        // ── Broadcast real-time progress via WebSocket (Reverb/Pusher) ──────────
        // This replaces the need for polling. The frontend listens on
        // private-catalogue.{etabId} and receives '.progress' events instantly.
        try {
            $catalogue = CatalogueFlink::query()->select(['id', 'etab_id', 'processing_operation', 'catalogue_group'])->find($catalogueId);
            if ($catalogue && (int) $catalogue->etab_id > 0) {
                event(new CatalogueProgressUpdated(
                    catalogueId: $catalogueId,
                    etabId:      (int) $catalogue->etab_id,
                    current:     max(0, $current),
                    total:       max(0, $total),
                    state:       $state,
                    operation:   (string) ($catalogue->processing_operation ?? 'create'),
                    group:       $catalogue->catalogue_group,
                    newPost:     $newPost,
                ));
            }
        } catch (\Throwable $e) {
            // Broadcasting failure must never break the import — log and continue.
            \Log::warning('CatalogueProgressUpdated broadcast failed', [
                'catalogue_id' => $catalogueId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function getCatalogueTableName(): string
    {
        try {
            return (new CatalogueFlink())->getTable();
        } catch (\Throwable $e) {
            return 'catalogues_flink';
        }
    }

    private function shouldStopProcessing( int $catalogueId ): bool
    {
        // 1. Primary check via Cache (atomic and shared across processes)
        if (Cache::has("catalogue_stop_{$catalogueId}")) {
            return true;
        }

        // 2. Direct DB fallback (bypass Eloquent to avoid stale state)
        try {
            $state = DB::table('catalogues_flink')
                ->where('id', $catalogueId)
                ->value('processing_state');
                
            if ($state === 'failed' || $state === 'cancelled') {
                // If it's already failed/cancelled in DB, stop immediately
                return true;
            }
        } catch (\Throwable $e) { }

        return false;
    }

    /**
     * POST /api/catalogue/preview
     *
     * Returns row count before import/update to power progress UI.
     */
    public function previewCatalogue( Request $request )
    {
        try {
            $csvContent = $this->resolveCsvContent( $request );
            $rows = $this->parseCsv( $csvContent );
        } catch (\Exception $e) {
            return response()->json( [
                'success' => false,
                'message' => $e->getMessage(),
            ], 422 );
        }

        $extractedSnapshots = [];
        foreach ($rows as $index => $row) {
            $extractedSnapshots[] = $this->snapshotCatalogueRowExtracted( $row, $index + 2 );
        }

        $this->logInfo( 'Catalogue preview — extracted rows from file', [
            'total_rows' => count( $rows ),
            'extracted_from_file' => $extractedSnapshots,
        ] );

        return response()->json( [
            'success' => true,
            'total_rows' => count( $rows ),
            'extracted_from_file' => $extractedSnapshots,
        ] );
    }

    /**
     * POST /api/catalogue/delete
     *
     * Deletes a catalogue and all its publications.
     */
    public function deleteCatalogue( Request $request )
    {
        $catalogueId = (int) $request->input( 'catalogue_id' );
        $etabId = (int) $request->input( 'etab_id' );

        if ($catalogueId <= 0 || $etabId <= 0) {
            return response()->json( [
                'success' => false,
                'message' => 'Paramètres invalides.',
            ], 422 );
        }

        $catalogue = CatalogueFlink::query()
            ->where( 'id', $catalogueId )
            ->where( 'etab_id', $etabId )
            ->first();

        if (!$catalogue) {
            return response()->json( [
                'success' => false,
                'message' => 'Catalogue introuvable.',
            ], 404 );
        }

        $attempt = 1;
        while ($attempt <= self::STORE_MAX_RETRIES) {
            try {
                DB::transaction( function () use ($catalogueId, $etabId, $catalogue): void {
                    $publicationIds = DB::table( 'publications_flink' )
                        ->where( 'catalogue_id', $catalogueId )
                        ->where( 'etab_id', $etabId )
                        ->pluck( 'id' )
                        ->all();

                    if (!empty( $publicationIds )) {
                        // Remove dependent rows that can block FK deletes.
                        if (Schema::hasTable( 'publications_tags_flink' )) {
                            DB::table( 'publications_tags_flink' )
                                ->whereIn( 'publication_id', $publicationIds )
                                ->delete();
                        }

                        // Keep compatibility with installs that use either table name.
                        if (Schema::hasTable( 'statistiques_flink' ) && Schema::hasColumn( 'statistiques_flink', 'publication_id' )) {
                            DB::table( 'statistiques_flink' )
                                ->whereIn( 'publication_id', $publicationIds )
                                ->delete();
                        }

                        if (Schema::hasTable( 'statistiques' ) && Schema::hasColumn( 'statistiques', 'publication_id' )) {
                            DB::table( 'statistiques' )
                                ->whereIn( 'publication_id', $publicationIds )
                                ->delete();
                        }

                        if (Schema::hasTable( 'publications_sauvgardes_flink' ) && Schema::hasColumn( 'publications_sauvgardes_flink', 'publication_id' )) {
                            DB::table( 'publications_sauvgardes_flink' )
                                ->whereIn( 'publication_id', $publicationIds )
                                ->delete();
                        }

                        if (Schema::hasTable( 'recommendations' ) && Schema::hasColumn( 'recommendations', 'publication_id' )) {
                            DB::table( 'recommendations' )
                                ->whereIn( 'publication_id', $publicationIds )
                                ->delete();
                        }

                        DB::table( 'publications_flink' )
                            ->whereIn( 'id', $publicationIds )
                            ->delete();
                    }

                    // User-triggered delete must fully remove catalogue row.
                    if (method_exists( $catalogue, 'forceDelete' )) {
                        $catalogue->forceDelete();
                    } else {
                        $catalogue->delete();
                    }
                } );

                break;
            } catch (\Throwable $e) {
                $isDeadlock = str_contains( $e->getMessage(), 'Deadlock found when trying to get lock' )
                    || str_contains( $e->getMessage(), 'SQLSTATE[40001]' )
                    || str_contains( $e->getMessage(), 'Serialization failure' );

                if (!$isDeadlock || $attempt >= self::STORE_MAX_RETRIES) {
                    throw $e;
                }

                $delayMs = 150 * $attempt;
                $this->logWarning( 'Catalogue delete deadlock detected, retrying delete', [
                    'catalogue_id' => $catalogueId,
                    'attempt' => $attempt,
                    'retry_in_ms' => $delayMs,
                ] );
                usleep( $delayMs * 1000 );
                $attempt++;
            }
        }

        return response()->json( [
            'success' => true,
            'message' => 'Catalogue supprimé avec succès.',
        ] );
    }

    /**
     * POST /api/catalogue/refresh
     *
     * Syncs a URL-based catalogue with its CSV source:
     * - Creates new posts for new cat_post_id
     * - Updates existing posts only when fields changed
     * - Deletes posts that are no longer present in the CSV
     *
     * Body:
     *   catalogue_id (int) required
     *   etab_id      (int) required
     */
    public function refreshCatalogue( Request $request )
    {
        $catalogueId = (int) $request->input( 'catalogue_id' );
        $etabId = (int) $request->input( 'etab_id' );

        if ($catalogueId <= 0 || $etabId <= 0) {
            return response()->json( [
                'success' => false,
                'message' => 'Paramètres invalides.',
            ], 422 );
        }

        $catalogue = CatalogueFlink::query()
            ->where( 'id', $catalogueId )
            ->where( 'etab_id', $etabId )
            ->first();

        if (!$catalogue) {
            return response()->json( [
                'success' => false,
                'message' => 'Catalogue introuvable.',
            ], 404 );
        }

        if ($this->isCatalogueProcessing( $catalogue )) {
            return response()->json( [
                'success' => false,
                'status_code' => 409,
                'error_code' => 'catalogue_already_processing',
                'message' => 'Ce catalogue est déjà en cours de traitement.',
            ], 409 );
        }

        $userId = $this->resolveRefreshUserId(
            $catalogue,
            $etabId,
            (int) $request->input( 'user_id', 0 ),
            (int) optional( $request->user() )->id
        );

        $operation = (string) $request->input('_operation_override', 'refresh');
        $logId = $this->createCatalogueLog($etabId, $userId, (int) $catalogue->id, $operation, false);

        $this->markCatalogueProcessingState(
            $catalogue,
            self::PROCESSING_STATE_RUNNING,
            $operation,
            $operation === 'modifier' ? 'Modification en cours' : 'Actualisation en cours'
        );

        $completed = false;
        $finalMessage = null;
        try {
            $result = $this->refreshCatalogueRecord( $catalogue, $request, $etabId, $userId, false );
            $completed = !empty( $result['success'] );
            $finalMessage = (string) ( $result['message'] ?? '' );
        } catch (\Throwable $e) {
            $this->markCatalogueProcessingState(
                $catalogue,
                self::PROCESSING_STATE_FAILED,
                $operation,
                $e->getMessage()
            );
            $this->finishCatalogueLog($logId, ['failed' => 1], false, $e->getMessage());
            return response()->json( [
                'success' => false,
                'message' => $e->getMessage(),
            ], 422 );
        } finally {
            if ($completed) {
                $finishedMessage = $finalMessage !== '' ? $finalMessage : ($operation === 'modifier' ? 'Modification terminée' : 'Actualisation terminée');
                $this->markCatalogueProcessingState(
                    $catalogue,
                    self::PROCESSING_STATE_FINISHED,
                    $operation,
                    $finishedMessage
                );
            }
        }

        if (empty( $result['success'] )) {
            $this->markCatalogueProcessingState(
                $catalogue,
                self::PROCESSING_STATE_FAILED,
                $operation,
                (string) ( $result['message'] ?? ($operation === 'modifier' ? 'Modification échouée' : 'Actualisation échouée') )
            );
            $this->finishCatalogueLog($logId, ['failed' => 1], false, (string) ($result['message'] ?? null));
            return response()->json( $result, (int) ( $result['status_code'] ?? 409 ) );
        }

        $this->finishCatalogueLog($logId, [
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'deleted' => (int) ($result['deleted'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed' => (int) (is_array($result['errors'] ?? null) ? count($result['errors']) : 0),
        ], true, ['results' => $result['results'] ?? []]);

        return response()->json( $result );
    }

    public function getCatalogueProcessingStatus( Request $request )
    {
        $catalogueId = (int) $request->input( 'catalogue_id' );
        $etabId = (int) $request->input( 'etab_id' );

        if ($catalogueId <= 0 || $etabId <= 0) {
            return response()->json( [
                'success' => false,
                'message' => 'Paramètres invalides.',
            ], 422 );
        }

        $catalogue = CatalogueFlink::query()
            ->where( 'id', $catalogueId )
            ->where( 'etab_id', $etabId )
            ->first();
        if (!$catalogue) {
            return response()->json( [
                'success' => false,
                'message' => 'Catalogue introuvable.',
            ], 404 );
        }

        return response()->json( [
            'success' => true,
            'catalogue_id' => $catalogueId,
            'processing_state' => (string) ( $catalogue->processing_state ?? self::PROCESSING_STATE_FINISHED ),
            'processing_operation' => $catalogue->processing_operation ?? null,
            'processing_started_at' => $catalogue->processing_started_at ?? null,
            'processing_finished_at' => $catalogue->processing_finished_at ?? null,
            'processing_message' => $catalogue->processing_message ?? null,
            'processing_current' => isset( $catalogue->processing_current ) ? (int) $catalogue->processing_current : null,
            'processing_total' => isset( $catalogue->processing_total ) ? (int) $catalogue->processing_total : null,
        ] );
    }

    public function cancelCatalogueProcessing( Request $request )
    {
        $catalogueId = (int) $request->input( 'catalogue_id' );
        $etabId = (int) $request->input( 'etab_id' );
        if ($catalogueId <= 0 || $etabId <= 0) {
            return response()->json( [
                'success' => false,
                'message' => 'Paramètres invalides.',
            ], 422 );
        }

        $catalogue = CatalogueFlink::query()
            ->where( 'id', $catalogueId )
            ->where( 'etab_id', $etabId )
            ->first();
        if (!$catalogue) {
            return response()->json( [
                'success' => false,
                'message' => 'Catalogue introuvable.',
            ], 404 );
        }

        $operation = (string) ( $catalogue->processing_operation ?? 'refresh' );
        
        // Signal background loop to stop via Cache (instant reaction)
        Cache::put("catalogue_stop_{$catalogueId}", true, 600);

        $this->markCatalogueProcessingState(
            $catalogue,
            self::PROCESSING_STATE_FAILED,
            $operation,
            'Traitement annulé par l’utilisateur.'
        );

        // Broadcast cancellation to frontend instantly with current progress
        $this->updateCatalogueProcessingProgress(
            $catalogueId,
            (int) ($catalogue->processing_current ?? 0),
            (int) ($catalogue->processing_total ?? 0),
            null,
            null,
            'failed'
        );

        return response()->json( [
            'success' => true,
            'message' => 'Annulation demandée.',
            'catalogue_id' => $catalogueId,
        ] );
    }

    public function acknowledgeCatalogueCompletionNotice( Request $request )
    {
        $catalogueId = (int) $request->input( 'catalogue_id' );
        $etabId = (int) $request->input( 'etab_id' );
        if ($catalogueId <= 0 || $etabId <= 0) {
            return response()->json( [
                'success' => false,
                'message' => 'Paramètres invalides.',
            ], 422 );
        }

        $catalogue = CatalogueFlink::query()
            ->where( 'id', $catalogueId )
            ->where( 'etab_id', $etabId )
            ->first();
        if (!$catalogue) {
            return response()->json( [
                'success' => false,
                'message' => 'Catalogue introuvable.',
            ], 404 );
        }

        $table = $this->getCatalogueTableName();
        $payload = [];
        if (Schema::hasColumn( $table, 'completion_notice_pending' )) {
            $payload['completion_notice_pending'] = 0;
        }
        if (Schema::hasColumn( $table, 'completion_notice_seen_at' )) {
            $payload['completion_notice_seen_at'] = now();
        }
        if (!empty( $payload )) {
            CatalogueFlink::query()->where( 'id', $catalogue->id )->update( $payload );
        }

        return response()->json( [
            'success' => true,
            'catalogue_id' => $catalogueId,
        ] );
    }

    public function runScheduledRefreshById( int $catalogueId ): array
    {
        $catalogue = CatalogueFlink::query()->where( 'id', $catalogueId )->first();
        if (!$catalogue) {
            throw new \RuntimeException( 'Catalogue introuvable.' );
        }

        $etabId = (int) ( $catalogue->etab_id ?? 0 );
        if ($etabId <= 0) {
            throw new \RuntimeException( 'Catalogue auto refresh parameters are invalid.' );
        }
        $userId = $this->resolveRefreshUserId( $catalogue, $etabId, 0, 0 );
        
        $this->markCatalogueProcessingState(
            $catalogue,
            self::PROCESSING_STATE_PROCESSING,
            'refresh',
            'Actualisation automatique en cours...'
        );

        $logId = $this->createCatalogueLog( $etabId, $userId, $catalogueId, 'auto_refresh', true );

        try {
            $result = $this->refreshCatalogueRecord( $catalogue, null, $etabId, $userId, true );
            
            if (!empty( $result['success'] )) {
                $this->finishCatalogueLog($logId, [
                    'created' => (int) ($result['created'] ?? 0),
                    'updated' => (int) ($result['updated'] ?? 0),
                    'deleted' => (int) ($result['deleted'] ?? 0),
                    'skipped' => (int) ($result['skipped'] ?? 0),
                    'failed' => (int) (is_array($result['errors'] ?? null) ? count($result['errors']) : 0),
                ], true, ['results' => $result['results'] ?? []]);
            } else {
                $this->finishCatalogueLog($logId, ['failed' => 1], false, (string) ($result['message'] ?? null));
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->finishCatalogueLog($logId, ['failed' => 1], false, $e->getMessage());
            throw $e;
        }
    }

    private function refreshCatalogueRecord( CatalogueFlink $catalogue, ?Request $request, int $etabId, int $userId, bool $isAutomatic ): array
    {
        $catalogueId = (int) $catalogue->id;
        $sourceRequest = $this->resolveRefreshSourceRequest( $catalogue, $request );
        $rows = $this->loadRowsFromRefreshSource( $sourceRequest );
        $this->logInfo( 'Catalogue refresh source parsed.', [
            'catalogue_id' => $catalogueId,
            'catalogue_name' => (string) ( $catalogue->catalogue_group ?? '' ),
            'source' => (string) $sourceRequest->input( 'csv_url', '' ),
            'rows_count' => count( $rows ),
            'automatic' => $isAutomatic,
        ] );

        $syncContext = $this->buildRefreshSyncContext( $etabId, $catalogueId );

        $incomingIds = [];
        $created = 0;
        $updated = 0;
        $deleted = 0;
        $skipped = 0;
        $errors = [];
        $updatedDetails = [];
        $results = [];
        $totalRows = count( $rows );
        $this->updateCatalogueProcessingProgress( $catalogueId, 0, $totalRows );
        $createCtrl = new CreatePublicationController();
        $baseRequest = $request ?? Request::create( '/api/catalogue/refresh', 'POST', [] );
        $overflowMode = (string) ( $request?->input( 'overflow_mode', '' ) ?? '' );
        $limitState = $this->prepareCatalogueOverflowState(
            $catalogue,
            $rows,
            $syncContext,
            $overflowMode,
            $isAutomatic
        );
        if (!empty( $limitState['blocked'] )) {
            return $limitState['response'];
        }

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            // Cancellation check on every row to ensure instant termination when user clicks 'Cancel'.
            if ($this->shouldStopProcessing( $catalogueId )) {
                $this->logInfo('Catalogue processing aborted by user signal.', ['catalogue_id' => $catalogueId, 'row' => $rowNum]);
                $errors[] = [
                    'row' => $rowNum,
                    'reason' => 'Traitement interrompu par l\'utilisateur.',
                ];
                // Broadcast final "failed" (cancelled) state so UI stops immediately
                $this->updateCatalogueProcessingProgress($catalogueId, $index, $totalRows, null, null, 'failed');
                break;
            }
            $targetCatalogueId = $catalogueId;
            $targetContext = $syncContext;
            $catProbe = $this->probeRowCatPostForCatalogue( $row, $catalogueId, $syncContext );
            $isNewForCurrent = !$catProbe['is_existing'];
            if ($isNewForCurrent) {
                if ($limitState['remaining_current_slots'] > 0) {
                    $limitState['remaining_current_slots']--;
                } else {
                    $skipped++;
                    $errors[] = [
                        'row' => $rowNum,
                        'reason' => 'Limite catalogue atteinte (' . self::MAX_POSTS_PER_CATALOGUE . '). Ligne ignorée.',
                    ];
                    continue;
                }
            }

            $rowResult = $this->syncCatalogueRow(
                $row,
                $rowNum,
                $etabId,
                $userId,
                $targetCatalogueId,
                $targetContext,
                $createCtrl,
                $baseRequest
            );

            if ($rowResult['cat_post_id'] !== null) {
                $incomingIds[(string) $rowResult['cat_post_id']] = true;
            }

            $created += (int) $rowResult['created'];
            $updated += (int) $rowResult['updated'];
            $skipped += (int) $rowResult['skipped'];
            foreach ($rowResult['errors'] as $error) {
                $errors[] = $error;
            }

            // Map row result into `$results` array for the full operation report
            if (!empty($rowResult['errors'])) {
                foreach ($rowResult['errors'] as $err) {
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => $err['reason'] ?? 'Erreur de synchronisation.',
                    ];
                }
            } else if ((int)$rowResult['created'] > 0) {
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'success',
                    'publication_id' => $rowResult['post_data']['id'] ?? null,
                    'post_data' => $rowResult['post_data'],
                    'message' => 'Produit créé avec succès.'
                ];
            } else if ((int)$rowResult['updated'] > 0) {
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'success',
                    'publication_id' => $rowResult['post_data']['id'] ?? null,
                    'post_data' => $rowResult['post_data'],
                    'message' => 'Produit mis à jour avec succès.'
                ];
            } else if ((int)$rowResult['skipped'] > 0) {
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'ignored',
                    'reason' => 'Produit ignoré (aucun changement détecté).'
                ];
            } else {
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'ignored',
                    'reason' => 'Aucune action requise.'
                ];
            }

            if (!empty($rowResult['field_changes'])) {
                $updatedDetails[] = [
                    'post_id' => $rowResult['post_id'] ?? null,
                    'cat_post_id' => $rowResult['cat_post_id'] ?? null,
                    'field_changes' => $rowResult['field_changes'],
                ];
            }
            // Fire progress event for EVERY row to ensure smooth real-time increments.
            if (true) {
                // Determine last action for this row
                $lastAction = null;
                $lastPostId = $rowResult['cat_post_id'] ?? $rowResult['post_id'] ?? null;
                if ($rowResult['created'] > 0) {
                    $lastAction = 'created';
                } elseif ($rowResult['updated'] > 0) {
                    $lastAction = 'updated';
                } elseif ($rowResult['skipped'] > 0) {
                    $lastAction = 'skipped';
                }

                $this->updateCatalogueProcessingProgress( $catalogueId, $index + 1, $totalRows, [
                    'created' => $created,
                    'updated' => $updated,
                    'deleted' => 0, // Will be updated after deletion pass
                    'skipped' => $skipped,
                    'failed' => count( $errors ),
                    'last_post_id' => $lastPostId,
                    'last_action' => $lastAction,
                ], $rowResult['post_data'] );
            }
        }

        if (!$this->shouldStopProcessing( $catalogueId )) {
            $deleted += $this->deleteRemovedCataloguePosts( $etabId, $catalogueId, $incomingIds );
        }

        // Cleanup stop signal cache if any
        Cache::forget("catalogue_stop_{$catalogueId}");

        // Final stats update after deletion pass (for SSE and WebSockets)
        $this->updateCatalogueProcessingProgress( $catalogueId, $totalRows, $totalRows, [
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'failed' => count( $errors ),
            'last_post_id' => null,
            'last_action' => 'completed',
        ], null, 'finished' );

        $catalogue->last_imported_at = now();
        $sourceFile = trim( (string) ( $catalogue->source_file ?? '' ) );
        if (
            $sourceFile !== ''
            && filter_var( $sourceFile, FILTER_VALIDATE_URL )
            && !empty( $catalogue->refresh_interval )
            && !empty( $catalogue->refresh_time )
        ) {
            $catalogue->next_update_at = $this->calculateNextUpdate(
                (string) $catalogue->refresh_interval,
                (string) $catalogue->refresh_time,
                now()
            );
        } else {
            $catalogue->next_update_at = null;
        }

        $affectedCount = $created + $updated + $deleted;
        $operation = (string) ($request?->input('_operation_override') ?? '');

        // User requirement: If the modification/update resulted in no posts being affected 
        // (broken URL, empty CSV, or zero changes), we do not commit the new metadata.
        if (!$isAutomatic && $affectedCount === 0 && ($operation === 'modifier' || $operation === 'update')) {
            $catalogue->source_file = $catalogue->getOriginal('source_file');
            $catalogue->refresh_interval = $catalogue->getOriginal('refresh_interval');
            $catalogue->refresh_time = $catalogue->getOriginal('refresh_time');
            $catalogue->next_update_at = $catalogue->getOriginal('next_update_at');
            $catalogue->catalogue_group = $catalogue->getOriginal('catalogue_group');
            $catalogue->last_imported_at = $catalogue->getOriginal('last_imported_at');
        }

        $catalogue->save();

        $catalogueDeleted = $this->deleteEmptyCatalogueAfterSync( $etabId, $catalogueId );

        $result = [
            'success' => true,
            'catalogue_id' => $catalogueId,
            'catalogue_name' => (string) ( $catalogue->catalogue_group ?? '' ),
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors,
            'catalogue_deleted' => $catalogueDeleted,
            'automatic' => $isAutomatic,
            'refreshed_at' => now()->toDateTimeString(),
            'results' => $results,
        ];

        if (!$isAutomatic && !empty($updatedDetails)) {
            $this->logInfo( 'Catalogue refresh field changes.', [
                'catalogue_id' => $catalogueId,
                'catalogue_name' => (string) ( $catalogue->catalogue_group ?? '' ),
                'updated_count' => $updated,
                'updated_details' => $updatedDetails,
            ]);
        }

        if ($isAutomatic) {
            $this->logInfo( 'Catalogue auto refresh completed.', $result );
        }
        return $result;
    }

    private function prepareCatalogueOverflowState(
        CatalogueFlink $catalogue,
        array $rows,
        array $syncContext,
        string $requestedMode,
        bool $isAutomatic
    ): array {
        $existingCount = (int) DB::table( 'publications_flink' )
            ->where( 'catalogue_id', (int) $catalogue->id )
            ->when( Schema::hasColumn( 'publications_flink', 'status' ), function ( $q ) {
                $q->whereIn( 'status', [0, 1] ); // Exclude status=2 soft-deleted posts
            } )
            ->when( Schema::hasColumn( 'publications_flink', 'deleted_at' ), function ( $q ) {
                $q->whereNull( 'deleted_at' );
            } )
            ->count();
        $newCandidateCount = $this->estimateNewRowsForCatalogue( $rows, (int) $catalogue->id, $syncContext );
        $remainingSlots = max( 0, self::MAX_POSTS_PER_CATALOGUE - $existingCount );
        $overflowCount = max( 0, $newCandidateCount - $remainingSlots );

        $mode = in_array( $requestedMode, self::OVERFLOW_MODES, true ) ? $requestedMode : '';
        if ($mode === '' && $isAutomatic) {
            $mode = 'fill_current';
        }
        if ($overflowCount > 0 && $mode === '') {
            return [
                'blocked' => true,
                'response' => [
                    'success' => false,
                    'status_code' => 409,
                    'error_code' => 'catalogue_limit_choice_required',
                    'message' => "Ce catalogue a déjà {$existingCount} posts. Vous tentez d'ajouter {$newCandidateCount} nouveaux posts.",
                    'limit' => self::MAX_POSTS_PER_CATALOGUE,
                    'existing_posts' => $existingCount,
                    'new_posts' => $newCandidateCount,
                    'remaining_slots' => $remainingSlots,
                    'catalogue_id' => (int) $catalogue->id,
                    'catalogue_name' => (string) ( $catalogue->catalogue_group ?? '' ),
                    'available_choices' => self::OVERFLOW_MODES,
                ],
            ];
        }

        if ($mode === 'cancel_all') {
            return [
                'blocked' => true,
                'response' => [
                    'success' => false,
                    'status_code' => 409,
                    'error_code' => 'catalogue_limit_cancelled',
                    'message' => 'Import annulé par l’utilisateur.',
                ],
            ];
        }

        return [
            'blocked' => false,
            'mode' => $mode !== '' ? $mode : 'fill_current',
            'remaining_current_slots' => $remainingSlots,
        ];
    }

    private function estimateNewRowsForCatalogue( array $rows, int $catalogueId, array $syncContext ): int
    {
        $existing = $syncContext['existing'] ?? collect();
        $seen = [];
        foreach ($rows as $row) {
            $probe = $this->probeRowCatPostForCatalogue( $row, $catalogueId, $syncContext );
            if ($probe['cat_post_id'] === null || $probe['is_existing']) {
                continue;
            }
            $seen[(string) $probe['cat_post_id']] = true;
        }
        return count( $seen );
    }

    private function probeRowCatPostForCatalogue( array $row, int $catalogueId, array $syncContext ): array
    {
        $existing = $syncContext['existing'] ?? collect();
        $rawCatPostId = $this->normalizeCatPostId( $row['cat_post_id'] ?? null );
        if ($rawCatPostId === null) {
            return ['cat_post_id' => null, 'is_existing' => false];
        }
        $resolved = isset( $existing[(string) $rawCatPostId] )
            ? $rawCatPostId
            : $this->buildScopedCatPostId( $catalogueId, $rawCatPostId );
        return [
            'cat_post_id' => $resolved,
            'is_existing' => isset( $existing[(string) $resolved] ),
        ];
    }

    private function resolveRefreshSourceRequest( CatalogueFlink $catalogue, ?Request $request ): Request
    {
        $isFileImport = $request?->hasFile( 'csv' ) ?? false;
        $isUrlImport = $request?->filled( 'csv_url' ) ?? false;
        if ($request !== null && ( $isFileImport || $isUrlImport )) {
            return $request;
        }

        $sourceUrl = trim( (string) ( $catalogue->source_file ?? '' ) );
        if ($sourceUrl === '' || !filter_var( $sourceUrl, FILTER_VALIDATE_URL )) {
            throw new \RuntimeException( "Ce catalogue n'a pas de source URL." );
        }

        return Request::create(
            '/api/catalogue/refresh',
            'POST',
            ['csv_url' => $sourceUrl]
        );
    }

    private function resolveRefreshUserId(
        CatalogueFlink $catalogue,
        int $etabId,
        int $requestedUserId = 0,
        int $authUserId = 0
    ): int {
        if ($requestedUserId > 0) {
            return $requestedUserId;
        }
        if ($authUserId > 0) {
            return $authUserId;
        }
        $catalogueUserId = (int) ( $catalogue->user_id ?? 0 );
        if ($catalogueUserId > 0) {
            return $catalogueUserId;
        }

        if (Schema::hasTable( 'publications_flink' ) && Schema::hasColumn( 'publications_flink', 'user_id' )) {
            $publicationUserId = (int) DB::table( 'publications_flink' )
                ->where( 'etab_id', $etabId )
                ->where( 'catalogue_id', (int) $catalogue->id )
                ->whereNotNull( 'user_id' )
                ->value( 'user_id' );
            if ($publicationUserId > 0) {
                return $publicationUserId;
            }
        }

        if (Schema::hasTable( 'etablissements' ) && Schema::hasColumn( 'etablissements', 'user_id' )) {
            $ownerUserId = (int) DB::table( 'etablissements' )->where( 'id', $etabId )->value( 'user_id' );
            if ($ownerUserId > 0) {
                return $ownerUserId;
            }
        }

        return 0;
    }

    private function loadRowsFromRefreshSource( Request $sourceRequest ): array
    {
        try {
            $csvContent = $this->resolveCsvContent( $sourceRequest );
        } catch (\Exception $e) {
            throw new \RuntimeException( $e->getMessage(), 0, $e );
        }

        $rows = $this->parseCsv( $csvContent );
        if (empty( $rows )) {
            throw new \RuntimeException( 'Le fichier est vide ou impossible à analyser.' );
        }

        return $rows;
    }

    private function buildRefreshSyncContext( int $etabId, int $catalogueId ): array
    {
        $deliveryModeColumn = $this->resolvePublicationColumn( [
            'mode_laivraison_id',
            'mode_livraison_id',
            'delivery_modes',
        ] );
        $statusColumn = $this->resolvePublicationColumn( ['status'] );
        $mainImageColumn = $this->resolvePublicationColumn( [
            'image_default',
            'image',
            'image_principale',
            'image_url',
            'thumbnail',
        ] );
        $imagesColumn = $this->resolvePublicationColumn( [
            'images',
            'images_list',
            'gallery',
            'gallery_images',
        ] );
        $squareImageColumn = $this->resolvePublicationColumn( [
            'image_square',
            'square_image',
        ] );
        $titleColumn = $this->resolvePublicationColumn( [
            'titre',
            'title',
            'nom',
            'name',
        ] );

        $existingSelect = [
            'id',
            'cat_post_id',
            'description',
            'prix',
            'prixPromo',
            'is_marketplace',
            'tele',
            'tele_whatsapp',
            'pays_id',
            'ville_id',
            'region_id',
            'disponibilite',
            'disponibilite_date',
        ];
        foreach ([$deliveryModeColumn, $statusColumn, $mainImageColumn, $imagesColumn, $squareImageColumn, $titleColumn] as $column) {
            if ($column !== null) {
                $existingSelect[] = $column;
            }
        }

        $existing = DB::table( 'publications_flink' )
            ->where( 'etab_id', $etabId )
            ->where( 'catalogue_id', $catalogueId )
            ->whereNotNull( 'cat_post_id' )
            ->where( function ( $q ) {
                // Exclude posts previously soft-deleted (status=2) so that a
                // cat_post_id which reappears in a new CSV gets re-created.
                if (Schema::hasColumn( 'publications_flink', 'status' )) {
                    $q->whereIn( 'status', [0, 1] );
                }
                if (Schema::hasColumn( 'publications_flink', 'deleted_at' )) {
                    $q->whereNull( 'deleted_at' );
                }
            } )
            ->get( $existingSelect )
            ->keyBy( fn( $p ) => (string) $p->cat_post_id );

        $existingTagSigByPubId = [];
        if (Schema::hasTable( 'publications_tags_flink' ) && $existing->isNotEmpty()) {
            $pubIds = $existing->pluck( 'id' )->map( fn( $id ) => (int) $id )->all();
            $tagRows = DB::table( 'publications_tags_flink' )
                ->whereIn( 'publication_id', $pubIds )
                ->get( ['publication_id', 'tag_id'] );
            foreach ($tagRows as $row) {
                $pid = (int) $row->publication_id;
                if (!isset( $existingTagSigByPubId[$pid] )) {
                    $existingTagSigByPubId[$pid] = [];
                }
                $existingTagSigByPubId[$pid][] = (int) $row->tag_id;
            }
            foreach ($existingTagSigByPubId as $pid => $ids) {
                sort( $ids );
                $existingTagSigByPubId[$pid] = implode( ',', $ids );
            }
        }

        $existingFingerprintByCatPostId = [];
        foreach ($existing as $catId => $pub) {
            $descNorm = $this->normalizeDescriptionForDuplicateCheck( (string) ( $pub->description ?? '' ) );
            $main = $mainImageColumn !== null ? $this->normalizeImageUrlForDuplicateCheck( (string) ( $pub->{$mainImageColumn} ?? '' ) ) : '';
            $imgs = $imagesColumn !== null ? $this->normalizeImagesListForDuplicateCheck( (string) ( $pub->{$imagesColumn} ?? '' ), null ) : '';
            $tagSig = isset( $existingTagSigByPubId[(int) $pub->id] ) ? (string) $existingTagSigByPubId[(int) $pub->id] : '';
            $existingFingerprintByCatPostId[(string) $catId] = sha1( $descNorm . '|' . $main . '|' . $imgs . '|' . $tagSig );
        }

        return [
            'deliveryModeColumn' => $deliveryModeColumn,
            'statusColumn' => $statusColumn,
            'mainImageColumn' => $mainImageColumn,
            'imagesColumn' => $imagesColumn,
            'squareImageColumn' => $squareImageColumn,
            'titleColumn' => $titleColumn,
            'existing' => $existing,
            'existingTagSigByPubId' => $existingTagSigByPubId,
            'existingFingerprintByCatPostId' => $existingFingerprintByCatPostId,
        ];
    }

    private function syncCatalogueRow(
        array $row,
        int $rowNum,
        int $etabId,
        int $userId,
        int $catalogueId,
        array $syncContext,
        CreatePublicationController $createCtrl,
        Request $request
    ): array {
        $existing = $syncContext['existing'];
        $deliveryModeColumn = $syncContext['deliveryModeColumn'];
        $statusColumn = $syncContext['statusColumn'];
        $mainImageColumn = $syncContext['mainImageColumn'];
        $imagesColumn = $syncContext['imagesColumn'];
        $squareImageColumn = $syncContext['squareImageColumn'];
        $titleColumn = $syncContext['titleColumn'];
        $existingTagSigByPubId = $syncContext['existingTagSigByPubId'] ?? [];
        $existingFingerprintByCatPostId = $syncContext['existingFingerprintByCatPostId'] ?? [];

        $result = [
            'cat_post_id' => null,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'post_data' => null,
        ];

        // Double check cancellation before starting any heavy work (like image processing)
        if ($this->shouldStopProcessing($catalogueId)) {
            $result['skipped']++;
            $result['errors'][] = ['row' => $rowNum, 'reason' => 'Traitement annulé.'];
            return $result;
        }

        $rawCatPostId = $this->normalizeCatPostId( $row['cat_post_id'] ?? null );
        $catPostId = null;
        if ($rawCatPostId !== null) {
            $catPostId = isset( $existing[(string) $rawCatPostId] )
                ? $rawCatPostId
                : $this->buildScopedCatPostId( $catalogueId, $rawCatPostId );
        }
        if ($catPostId === null) {
            $result['skipped']++;
            $result['errors'][] = ['row' => $rowNum, 'reason' => 'id (cat_post_id) manquant ou invalide.'];
            return $result;
        }
        $result['cat_post_id'] = $catPostId;

        $validationError = $this->validateRow( $row );
        if ($validationError) {
            $result['skipped']++;
            $result['errors'][] = ['row' => $rowNum, 'reason' => $validationError];
            return $result;
        }

        $targetExisting = $existing[(string) $catPostId] ?? null;
        $incomingDescription = !empty( $row['description'] ) ? (string) $row['description'] : (string) ( $row['title'] ?? 'Sans titre' );
        $incomingMainImage = $this->normalizeImageUrlForDuplicateCheck( $row['image_default'] ?? null );
        $incomingImagesList = $this->normalizeImagesListForDuplicateCheck( $row['images'] ?? null, $row['image_default'] ?? null );
        $tagsString = $row['tags'] ?? '';
        $tagNames = array_values( array_unique( array_filter(
            array_map( 'trim', preg_split( '/[|,<>]/', $tagsString ) )
        ) ) ); 
        $resolvedTagIds = $this->resolveTagIds( $tagNames );

        // ── FAST PATH: Fingerprint-based comparison ───────────────────────────
        // Single hash comparison O(1) instead of field-by-field O(n)
        if ($targetExisting) {
            $incomingFingerprint = $this->generateContentFingerprint( $row );
            // Store fingerprint in sync context if available, else compute from existing
            $existingFingerprint = $existingFingerprintByCatPostId[(string) $catPostId] ?? null;

            if ($existingFingerprint !== null && hash_equals( $existingFingerprint, $incomingFingerprint )) {
                // Content identical - skip expensive update work
                $result['skipped']++;
                return $result;
            }

            // Content changed - detect what for smart status decision
            $changes = $this->detectFieldChanges( $targetExisting, $row );

            // If only cosmetic changes (description, images), keep current status
            // If critical changes (price, availability), set to pending review (status 0)
            $shouldResetStatus = !empty( $changes['critical'] );
        } else {
            $shouldResetStatus = false; // New post, will be created with status 1
        }

        $incomingPrice = ( isset( $row['price'] ) && trim( (string) $row['price'] ) !== '' ) ? (float) $row['price'] : null;
        $incomingPromo = ( isset( $row['promo_price'] ) && trim( (string) $row['promo_price'] ) !== '' ) ? (float) $row['promo_price'] : null;
        $incomingTele = isset( $row['phone'] ) && trim( (string) $row['phone'] ) !== '' ? trim( (string) $row['phone'] ) : null;
        $incomingWhats = isset( $row['whatsapp'] ) && trim( (string) $row['whatsapp'] ) !== '' ? trim( (string) $row['whatsapp'] ) : null;
        $countryId = $this->resolveCountryId( $row['pays'] ?? null );
        $cityId = $this->resolveCityId( $row['ville'] ?? null, $countryId );
        $regionId = $this->resolveRegionId( $row['region'] ?? null, $cityId );
        $availability = $this->normalizeDisponibilite( $row['disponibilite'] ?? $row['availability'] ?? null );
        if ($availability === 'vendu' && !$this->postExistsForFirstCreationGuard( $etabId, $userId, $incomingDescription )) {
            $availability = 'inStock';
        }
        $incomingDisponibiliteDate = $this->normalizeDisponibiliteDate( $row['disponibilite_date'] ?? null );

        $modeIds = [];
        foreach (['delivery_modes', 'mode_livraison'] as $field) {
            if (!empty( $row[$field] )) {
                foreach (array_map( 'trim', preg_split( '/[|,]/', $row[$field] ) ) as $name) {
                    $key = strtolower( $name );
                    if (isset( self::DELIVERY_MODE_MAP[$key] )) {
                        $modeIds[] = self::DELIVERY_MODE_MAP[$key];
                    }
                }
            }
        }
        $modeIds = array_values( array_unique( $modeIds ) );
        $incomingMode = !empty( $modeIds ) ? implode( ',', $modeIds ) : null;

        if ($targetExisting) {
            $updateResult = $this->updateExistingCataloguePublication(
                $row,
                $targetExisting,
                $catalogueId,
                $deliveryModeColumn,
                $statusColumn,
                $mainImageColumn,
                $imagesColumn,
                $squareImageColumn,
                $titleColumn,
                $incomingDescription,
                $incomingPrice,
                $incomingPromo,
                $incomingTele,
                $incomingWhats,
                $countryId,
                $cityId,
                $regionId,
                $incomingMode,
                $availability,
                $incomingDisponibiliteDate,
                $resolvedTagIds,
                $shouldResetStatus
            );

            if ($updateResult['updated']) {
                $result['updated']++;
                $result['post_data'] = [
                    'id' => (int) $targetExisting->id,
                    'slug' => $targetExisting->slug ?? null,
                    'image_default' => $targetExisting->image_default ?? null,
                ];
                if (!empty($updateResult['field_changes'])) {
                    $result['field_changes'] = $updateResult['field_changes'];
                    $result['post_id'] = (int) $targetExisting->id;
                    $result['cat_post_id'] = $catPostId;
                }
            } else {
                $result['skipped']++;
            }

            return $result;
        }

        try {
            $syntheticRequest = $this->buildPublicationRequest( $row, $etabId, $userId, $request );
            $response = $this->storeWithRetry( $createCtrl, $syntheticRequest, $rowNum );
            $responseData = json_decode( (string) $response->getContent(), true );

            if (!empty( $responseData['success'] ) && !empty( $responseData['publication'] )) {
                $pubId = (int) $responseData['publication']['id'];
                $result['post_data'] = [
                    'id' => $pubId,
                    'slug' => $responseData['publication']['slug'] ?? null,
                    'image_default' => $responseData['publication']['image_default'] ?? null,
                ];
                $linked = $this->linkPublicationToCatalogue( $pubId, $catalogueId, $catPostId, $etabId, $rowNum );
                if (!$linked) {
                    $result['skipped']++;
                    $result['errors'][] = ['row' => $rowNum, 'reason' => 'Échec de liaison au catalogue (possible doublon cat_post_id).'];
                    return $result;
                }

                // ── Explicitly write tags for new posts created via refresh/actualiser ──
                // The import() path lets CreatePublicationController::store() handle tags
                // via a real auth request. In the refresh path, $baseRequest is a blank
                // synthetic request (especially for automatic runs where $request is null),
                // so tag insertion by store() cannot be guaranteed. We mirror what
                // updateExistingCataloguePublication() already does for updated posts.
                if (!empty( $resolvedTagIds ) && Schema::hasTable( 'publications_tags_flink' )) {
                    foreach ($resolvedTagIds as $tagId) {
                        $tagInsertData = [
                            'publication_id' => $pubId,
                            'tag_id'         => (int) $tagId,
                        ];
                        $tagInsertData = $this->mergeTableTimestampsForInsert( 'publications_tags_flink', $tagInsertData );
                        try {
                            DB::table( 'publications_tags_flink' )->insert( $tagInsertData );
                        } catch (\Throwable $tagEx) {
                            // Non-fatal: log and continue — publication was created successfully
                            $this->logWarning( "Catalogue row {$rowNum}: failed inserting tag {$tagId} for pub {$pubId}", [
                                'error' => $tagEx->getMessage(),
                            ] );
                        }
                    }
                }

                $result['created']++;
                return $result;
            }

            $result['skipped']++;
            $result['errors'][] = ['row' => $rowNum, 'reason' => $responseData['message'] ?? 'Erreur création.'];
            return $result;
        } catch (\Exception $e) {
            $result['skipped']++;
            $result['errors'][] = ['row' => $rowNum, 'reason' => $e->getMessage()];
            return $result;
        }
    }

    private function updateExistingCataloguePublication(
        array $row,
        object $targetExisting,
        int $catalogueId,
        ?string $deliveryModeColumn,
        ?string $statusColumn,
        ?string $mainImageColumn,
        ?string $imagesColumn,
        ?string $squareImageColumn,
        ?string $titleColumn,
        string $incomingDescription,
        ?float $incomingPrice,
        ?float $incomingPromo,
        ?string $incomingTele,
        ?string $incomingWhats,
        ?int $countryId,
        ?int $cityId,
        ?int $regionId,
        ?string $incomingMode,
        ?string $availability,
        ?string $incomingDisponibiliteDate,
        array $resolvedTagIds,
        bool $shouldResetStatus = true
    ): array {
        $updateData = [];
        $fieldChanges = [];
        $sensitiveChanged = false;
        $tagsProvided = isset( $row['tags'] ) && trim( (string) ( $row['tags'] ?? '' ) ) !== '';
        $incomingMainImage = $this->normalizeImageUrlForDuplicateCheck( $row['image_default'] ?? null );
        $incomingImagesList = $this->normalizeImagesListForDuplicateCheck( $row['images'] ?? null, $row['image_default'] ?? null );
        $incomingTitle = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
        $existingMainImage = $mainImageColumn !== null
            ? $this->normalizeImageUrlForDuplicateCheck( (string) ( $targetExisting->{$mainImageColumn} ?? '' ) )
            : '';
        $existingImagesList = $imagesColumn !== null
            ? $this->normalizeImagesListForDuplicateCheck( (string) ( $targetExisting->{$imagesColumn} ?? '' ), null )
            : '';

        $rowDescriptionRaw = isset( $row['description'] ) ? trim( (string) ( $row['description'] ?? '' ) ) : '';
        if ($rowDescriptionRaw !== '') {
            $existingDescNorm = $this->normalizeDescriptionForDuplicateCheck( (string) ( $targetExisting->description ?? '' ) );
            $incomingDescNorm = $this->normalizeDescriptionForDuplicateCheck( $rowDescriptionRaw );
            if ($existingDescNorm !== $incomingDescNorm) {
                $updateData['description'] = $rowDescriptionRaw;
                $fieldChanges['description'] = [
                    'old' => (string) ( $targetExisting->description ?? '' ),
                    'new' => $rowDescriptionRaw,
                ];
                $sensitiveChanged = true;
            }
        }
        if ((string) ( $targetExisting->is_marketplace ?? '' ) !== '1') {
            $updateData['is_marketplace'] = '1';
            $fieldChanges['is_marketplace'] = [
                'old' => (string) ( $targetExisting->is_marketplace ?? '' ),
                'new' => '1',
            ];
        }
        if ($titleColumn !== null && $incomingTitle !== '' && (string) ( $targetExisting->{$titleColumn} ?? '' ) !== $incomingTitle) {
            $updateData[$titleColumn] = $incomingTitle;
            $fieldChanges[$titleColumn] = [
                'old' => (string) ( $targetExisting->{$titleColumn} ?? '' ),
                'new' => $incomingTitle,
            ];
        }
        $oldPrice = $this->normalizePriceForComparison( $targetExisting->prix ?? null );
        $newPrice = $this->normalizePriceForComparison( $incomingPrice );
        if ($oldPrice !== $newPrice) {
            $updateData['prix'] = $incomingPrice;
            $fieldChanges['prix'] = [
                'old' => (string) ( $targetExisting->prix ?? '' ),
                'new' => (string) ( $incomingPrice ?? '' ),
            ];
        }
        $oldPromo = $this->normalizePriceForComparison( $targetExisting->prixPromo ?? null );
        $newPromo = $this->normalizePriceForComparison( $incomingPromo );
        if ($oldPromo !== $newPromo) {
            $updateData['prixPromo'] = $incomingPromo;
            $fieldChanges['prixPromo'] = [
                'old' => (string) ( $targetExisting->prixPromo ?? '' ),
                'new' => (string) ( $incomingPromo ?? '' ),
            ];
        }
        if ((string) ( $targetExisting->tele ?? '' ) !== (string) ( $incomingTele ?? '' )) {
            $updateData['tele'] = $incomingTele;
            $fieldChanges['tele'] = [
                'old' => (string) ( $targetExisting->tele ?? '' ),
                'new' => (string) ( $incomingTele ?? '' ),
            ];
        }
        if ((string) ( $targetExisting->tele_whatsapp ?? '' ) !== (string) ( $incomingWhats ?? '' )) {
            $updateData['tele_whatsapp'] = $incomingWhats;
            $fieldChanges['tele_whatsapp'] = [
                'old' => (string) ( $targetExisting->tele_whatsapp ?? '' ),
                'new' => (string) ( $incomingWhats ?? '' ),
            ];
        }
        if ((string) ( $targetExisting->pays_id ?? '' ) !== (string) ( $countryId ?? '' )) {
            $updateData['pays_id'] = $countryId;
            $fieldChanges['pays_id'] = [
                'old' => (string) ( $targetExisting->pays_id ?? '' ),
                'new' => (string) ( $countryId ?? '' ),
            ];
        }
        if ((string) ( $targetExisting->ville_id ?? '' ) !== (string) ( $cityId ?? '' )) {
            $updateData['ville_id'] = $cityId;
            $fieldChanges['ville_id'] = [
                'old' => (string) ( $targetExisting->ville_id ?? '' ),
                'new' => (string) ( $cityId ?? '' ),
            ];
        }
        if ((string) ( $targetExisting->region_id ?? '' ) !== (string) ( $regionId ?? '' )) {
            $updateData['region_id'] = $regionId;
            $fieldChanges['region_id'] = [
                'old' => (string) ( $targetExisting->region_id ?? '' ),
                'new' => (string) ( $regionId ?? '' ),
            ];
        }
        if ($deliveryModeColumn !== null && (string) ( $targetExisting->{$deliveryModeColumn} ?? '' ) !== (string) ( $incomingMode ?? '' )) {
            $updateData[$deliveryModeColumn] = $incomingMode;
            $fieldChanges[$deliveryModeColumn] = [
                'old' => (string) ( $targetExisting->{$deliveryModeColumn} ?? '' ),
                'new' => (string) ( $incomingMode ?? '' ),
            ];
        }
        if ((string) ( $targetExisting->disponibilite ?? '' ) !== (string) ( $availability ?? '' )) {
            $updateData['disponibilite'] = $availability;
            $fieldChanges['disponibilite'] = [
                'old' => (string) ( $targetExisting->disponibilite ?? '' ),
                'new' => (string) ( $availability ?? '' ),
            ];
        }
        if ((string) ( $targetExisting->disponibilite_date ?? '' ) !== (string) ( $incomingDisponibiliteDate ?? '' )) {
            $updateData['disponibilite_date'] = $incomingDisponibiliteDate;
            $fieldChanges['disponibilite_date'] = [
                'old' => (string) ( $targetExisting->disponibilite_date ?? '' ),
                'new' => (string) ( $incomingDisponibiliteDate ?? '' ),
            ];
        }

        $mainImageChanged = $mainImageColumn !== null
            && $this->hasMeaningfulMainImageChange(
                (string) ( $row['image_default'] ?? '' ),
                (string) ( $targetExisting->{$mainImageColumn} ?? '' )
            );
        if ($mainImageChanged) {
            $sensitiveChanged = true;
        }
        $imagesChanged = $imagesColumn !== null
            && $this->hasMeaningfulGalleryImagesChange(
                (string) ( $row['images'] ?? '' ),
                (string) ( $targetExisting->{$imagesColumn} ?? '' ),
                (string) ( $row['image_default'] ?? '' )
            );
        if ($imagesChanged) {
            $sensitiveChanged = true;
        }

        if ($mainImageChanged || $imagesChanged) {
            $processed = $this->processCatalogueRowImagesToWebp( $row, $catalogueId, $targetExisting->created_at ?? null );
            if ($processed !== null) {
                // Compare with proper normalization to avoid false positives
                if ($mainImageColumn !== null && !empty( $processed['main'] )) {
                    $existingMain = (string) ( $targetExisting->{$mainImageColumn} ?? '' );
                    $newMain = $processed['main'];
                    
                    // First check: exact string match (fastest path)
                    if ($existingMain !== $newMain) {
                        // Second check: normalized comparison for different formats
                        $existingNormalized = $this->normalizeImageUrlForDuplicateCheck( $existingMain );
                        $newNormalized = $this->normalizeImageUrlForDuplicateCheck( $newMain );
                        
                        // Only record as changed if actually different after normalization
                        if ($existingNormalized !== $newNormalized) {
                            $updateData[$mainImageColumn] = $newMain;
                            $fieldChanges[$mainImageColumn] = [
                                'old' => $existingMain,
                                'new' => $newMain,
                            ];
                        }
                    }
                }
                if ($imagesColumn !== null && array_key_exists( 'images', $processed )) {
                    $existingImages = (string) ( $targetExisting->{$imagesColumn} ?? '' );
                    $newImages = $processed['images'];
                    
                    // First check: exact string match (fastest path)
                    if ($existingImages !== $newImages) {
                        // Second check: normalized comparison for different formats
                        $existingNormalized = $this->normalizeImagesListForDuplicateCheck( $existingImages, null );
                        $newNormalized = $this->normalizeImagesListForDuplicateCheck( $newImages, null );
                        
                        // Only record as changed if actually different after normalization
                        if ($existingNormalized !== $newNormalized) {
                            $updateData[$imagesColumn] = $newImages;
                            $fieldChanges[$imagesColumn] = [
                                'old' => $existingImages,
                                'new' => $newImages,
                            ];
                        }
                    }
                }
                if ($squareImageColumn !== null && !empty( $processed['square'] )) {
                    $existingSquare = (string) ( $targetExisting->{$squareImageColumn} ?? '' );
                    $newSquare = $processed['square'];
                    
                    // First check: exact string match (fastest path)
                    if ($existingSquare !== $newSquare) {
                        // Second check: normalized comparison for different formats
                        $existingNormalized = $this->normalizeImageUrlForDuplicateCheck( $existingSquare );
                        $newNormalized = $this->normalizeImageUrlForDuplicateCheck( $newSquare );
                        
                        // Only record as changed if actually different after normalization
                        if ($existingNormalized !== $newNormalized) {
                            $updateData[$squareImageColumn] = $newSquare;
                            $fieldChanges[$squareImageColumn] = [
                                'old' => $existingSquare,
                                'new' => $newSquare,
                            ];
                        }
                    }
                }
            }
        }

        $existingTagIds = [];
        if (Schema::hasTable( 'publications_tags_flink' )) {
            $existingTagIds = DB::table( 'publications_tags_flink' )
                ->where( 'publication_id', (int) $targetExisting->id )
                ->pluck( 'tag_id' )
                ->map( fn( $id ) => (int) $id )
                ->all();
        }
        sort( $existingTagIds );
        $incomingTagIds = $tagsProvided ? array_map( 'intval', $resolvedTagIds ) : $existingTagIds;
        sort( $incomingTagIds );
        $tagsChanged = $existingTagIds !== $incomingTagIds;
        if ($tagsProvided && $tagsChanged) {
            $sensitiveChanged = true;
            $fieldChanges['tags'] = [
                'old' => implode( ',', $existingTagIds ),
                'new' => implode( ',', $incomingTagIds ),
            ];
        }

        // Smart status decision:
        // - $shouldResetStatus=true: Critical changes (price, availability) → status 0 (pending review)
        // - $shouldResetStatus=false: Only cosmetic changes → keep current status
        if ($statusColumn !== null && $sensitiveChanged && $shouldResetStatus) {
            $updateData[$statusColumn] = '0';
        }

        if (empty( $updateData )) {
            return ['updated' => false, 'field_changes' => []];
        }

        // Use synchronized timestamp to ensure created_at/updated_at/deleted_at match
        $syncedNow = $this->getSynchronizedTimestamp();
        $updateData['updated_at'] = $syncedNow;
        DB::table( 'publications_flink' )
            ->where( 'id', (int) $targetExisting->id )
            ->update( $updateData );

        if (Schema::hasTable( 'publications_tags_flink' ) && $tagsChanged) {
            $syncedNow = $this->getSynchronizedTimestamp();
            DB::table( 'publications_tags_flink' )
                ->where( 'publication_id', (int) $targetExisting->id )
                ->delete();
            foreach ($resolvedTagIds as $tagId) {
                $tagInsertData = [
                    'publication_id' => (int) $targetExisting->id,
                    'tag_id' => (int) $tagId,
                ];
                // Add timestamps if table has them
                $tagInsertData = $this->mergeTableTimestampsForInsert( 'publications_tags_flink', $tagInsertData );
                DB::table( 'publications_tags_flink' )->insert( $tagInsertData );
            }
        }

        return ['updated' => true, 'field_changes' => $fieldChanges];
    }

    /**
     * Delete posts removed from CSV (not in incoming data).
     * Uses batch operations for efficiency.
     * Note: If soft delete is needed, add deleted_at=$syncedNow to the update.
     */
    private function deleteRemovedCataloguePosts( int $etabId, int $catalogueId, array $incomingIds ): int
    {
        $toDeleteIds = DB::table( 'publications_flink' )
            ->where( 'etab_id', $etabId )
            ->where( 'catalogue_id', $catalogueId )
            ->whereNotNull( 'cat_post_id' )
            ->pluck( 'id', 'cat_post_id' )
            ->filter( function ( $id, $catId ) use ( $incomingIds ) {
                return !isset( $incomingIds[(string) $catId] );
            } )
            ->values()
            ->all();

        if (empty( $toDeleteIds )) {
            return 0;
        }

        if (Schema::hasTable( 'publications_tags_flink' )) {
            DB::table( 'publications_tags_flink' )->whereIn( 'publication_id', $toDeleteIds )->delete();
        }
        if (Schema::hasTable( 'statistiques_flink' ) && Schema::hasColumn( 'statistiques_flink', 'publication_id' )) {
            DB::table( 'statistiques_flink' )->whereIn( 'publication_id', $toDeleteIds )->delete();
        }
        if (Schema::hasTable( 'statistiques' ) && Schema::hasColumn( 'statistiques', 'publication_id' )) {
            DB::table( 'statistiques' )->whereIn( 'publication_id', $toDeleteIds )->delete();
        }
        if (Schema::hasTable( 'publications_sauvgardes_flink' ) && Schema::hasColumn( 'publications_sauvgardes_flink', 'publication_id' )) {
            DB::table( 'publications_sauvgardes_flink' )->whereIn( 'publication_id', $toDeleteIds )->delete();
        }
        if (Schema::hasTable( 'recommendations' ) && Schema::hasColumn( 'recommendations', 'publication_id' )) {
            DB::table( 'recommendations' )->whereIn( 'publication_id', $toDeleteIds )->delete();
        }

        // Set status=2 (catalogue-deleted) so the catalogue view query
        // (which filters status IN [0,1]) immediately hides these posts.
        // Also stamp deleted_at for tables that support soft deletes.
        $syncedNow = $this->getSynchronizedTimestamp();
        $deletePayload = ['updated_at' => $syncedNow];
        if (Schema::hasColumn('publications_flink', 'status')) {
            $deletePayload['status'] = 2;
        }
        if (Schema::hasColumn('publications_flink', 'deleted_at')) {
            $deletePayload['deleted_at'] = $syncedNow;
        }
        DB::table('publications_flink')
            ->whereIn('id', $toDeleteIds)
            ->update($deletePayload);

        return count( $toDeleteIds );
    }

    private function deleteEmptyCatalogueAfterSync( int $etabId, int $catalogueId ): bool
    {
        // Count only active posts — soft-deleted (status=2) posts are excluded
        // so an all-deleted catalogue is auto-removed.
        $remainingCount = (int) DB::table( 'publications_flink' )
            ->where( 'etab_id', $etabId )
            ->where( 'catalogue_id', $catalogueId )
            ->when( Schema::hasColumn( 'publications_flink', 'status' ), function ( $q ) {
                $q->whereIn( 'status', [0, 1] );
            } )
            ->when( Schema::hasColumn( 'publications_flink', 'deleted_at' ), function ( $q ) {
                $q->whereNull( 'deleted_at' );
            } )
            ->count();

        if ($remainingCount !== 0) {
            return false;
        }

        try {
            $catalogueToDelete = CatalogueFlink::query()
                ->where( 'id', $catalogueId )
                ->where( 'etab_id', $etabId )
                ->first();
            if ($catalogueToDelete) {
                if (method_exists( $catalogueToDelete, 'forceDelete' )) {
                    $catalogueToDelete->forceDelete();
                } else {
                    $catalogueToDelete->delete();
                }
                return true;
            }
        } catch (\Throwable $e) {
            $this->logWarning( 'Failed deleting empty catalogue after sync', [
                'catalogue_id' => $catalogueId,
                'error' => $e->getMessage(),
            ] );
        }

        return false;
    }

    /**
     * POST /api/catalogue/update
     *
     * Updates catalogue metadata and conditionally re-imports rows into the same catalogue.
     * - If URL source is unchanged and no file is uploaded, import is skipped.
     * - If URL/file source changes, imports are appended into the same catalogue (existing posts are preserved).
     */
    public function updateCatalogue( Request $request )
    {
        $catalogueId = (int) $request->input( 'catalogue_id' );
        $etabId = (int) $request->input( 'etab_id' );
        $userId = (int) ( $request->input( 'user_id' ) ?? optional( $request->user() )->id );

        if ($catalogueId <= 0 || $etabId <= 0 || $userId <= 0) {
            return response()->json( [
                'success' => false,
                'message' => 'Paramètres invalides.',
            ], 422 );
        }

        $catalogue = CatalogueFlink::query()
            ->where( 'id', $catalogueId )
            ->where( 'etab_id', $etabId )
            ->first();

        if (!$catalogue) {
            return response()->json( [
                'success' => false,
                'message' => 'Catalogue introuvable.',
            ], 404 );
        }

        if ($this->isCatalogueProcessing( $catalogue )) {
            return response()->json( [
                'success' => false,
                'message' => 'Ce catalogue est déjà en cours de traitement.',
                'error_code' => 'catalogue_already_processing',
            ], 409 );
        }

        // ── Snapshot original metadata BEFORE any modification ─────────────────
        // getOriginal() is unreliable after save() because Eloquent syncs the
        // original array on every persist. We capture values here, pre-save.
        $originalMetadata = [
            'source_file'      => $catalogue->source_file,
            'refresh_interval' => $catalogue->refresh_interval,
            'refresh_time'     => $catalogue->refresh_time,
            'next_update_at'   => $catalogue->next_update_at,
            'catalogue_group'  => $catalogue->catalogue_group,
            'last_imported_at' => $catalogue->last_imported_at,
        ];

        $newGroup = trim( (string) $request->input( 'catalogue_group', $catalogue->catalogue_group ) );
        if ($newGroup !== '') {
            $duplicateByName = CatalogueFlink::query()
                ->where( 'etab_id', $etabId )
                ->where( 'catalogue_group', $newGroup )
                ->where( 'id', '!=', $catalogue->id )
                ->exists();
            if ($duplicateByName) {
                return response()->json( [
                    'success' => false,
                    'message' => "Le nom du Flink Shop '{$newGroup}' existe déjà. Tapez un autre nom.",
                ], 422 );
            }
        }

        $isFileImport = $request->hasFile( 'csv' );
        $isUrlImport = $request->filled( 'csv_url' );
        $incomingSource = $isFileImport
            ? $request->file( 'csv' )->getClientOriginalName()
            : ( $isUrlImport ? trim( (string) $request->input( 'csv_url' ) ) : null );


        $isUrlCatalogue = $isUrlImport || ( !$isFileImport && filter_var( (string) $catalogue->source_file, FILTER_VALIDATE_URL ) );

        $refreshInterval = null;
        $refreshTime = null;
        $nextUpdateAt = null;
        if ($isUrlCatalogue) {
            $refreshInterval = in_array( $request->input( 'refresh_interval' ), self::REFRESH_INTERVALS, true )
                ? $request->input( 'refresh_interval' )
                : ( $catalogue->refresh_interval ?: 'weekly' );
            $refreshTime = $request->filled( 'refresh_time' ) 
                ? $request->input( 'refresh_time' ) 
                : ( $catalogue->refresh_time ?: '05:00' );
            $nextUpdateAt = $this->calculateNextUpdate( $refreshInterval, $refreshTime );
        }

        $catalogue->catalogue_group = $newGroup !== '' ? $newGroup : $catalogue->catalogue_group;
        if ($incomingSource !== null && $incomingSource !== '') {
            $catalogue->source_file = $incomingSource;
        }
        $catalogue->refresh_interval = $refreshInterval;
        $catalogue->refresh_time = $refreshTime;
        $catalogue->next_update_at = $nextUpdateAt;
        $catalogue->last_imported_at = now();
        $catalogue->save(); // Immediate commit: ensure changes are visible while processing starts

        if (!$isFileImport && !$isUrlImport) {
            $this->markCatalogueProcessingState(
                $catalogue,
                self::PROCESSING_STATE_FINISHED,
                'update',
                'Catalogue mis à jour.'
            );
            $catalogue->save();
            return response()->json( [
                'success' => true,
                'catalogue_id' => $catalogue->id,
                'catalogue_group' => $catalogue->catalogue_group,
                'total' => 0,
                'success_count' => 0,
                'failed' => 0,
                'results' => [],
                'message' => 'Catalogue mis à jour.',
            ] );
        }

        $request->merge( [
            'catalogue_id' => $catalogue->id,
            'etab_id' => $etabId,
            'user_id' => $userId,
            'catalogue_group' => $catalogue->catalogue_group,
            '_operation_override' => 'modifier',
        ] );

        $response = $this->refreshCatalogue( $request );

        // ── Revert metadata if source produced no usable posts ────────────────
        // Error codes that are NOT source errors (e.g. already-processing, slot
        // limit choice) must NOT trigger a revert — the user just needs to retry.
        $responseJson = json_decode( (string) $response->getContent(), true );
        $nonSourceErrorCodes = [
            'catalogue_already_processing',
            'catalogue_limit_choice_required',
            'catalogue_limit_cancelled',
        ];
        if (
            ( $isFileImport || $isUrlImport )
            && empty( $responseJson['success'] )
            && !in_array( $responseJson['error_code'] ?? '', $nonSourceErrorCodes, true )
        ) {
            try {
                CatalogueFlink::where( 'id', $catalogue->id )->update( $originalMetadata );
                $this->logInfo( 'Catalogue metadata reverted after failed source update.', [
                    'catalogue_id' => $catalogue->id,
                ] );
            } catch ( \Throwable $revertEx ) {
                $this->logWarning( 'Failed to revert catalogue metadata after failed source update', [
                    'catalogue_id' => $catalogue->id,
                    'error'        => $revertEx->getMessage(),
                ] );
            }
        }

        return $response;
    }

    /**
     * POST /api/catalogue/import
     *
     * Form-data fields:
     *   csv          (file)   — the catalogue file (any format)
     *   csv_url      (string) — OR a remote URL to download the file from
     *   etab_id      (int)    — établissement ID
     *   user_id      (int)    — user ID (fallback: auth user)
     *   catalogue_group (string) — catalogue name; auto-generated if omitted
     *   refresh_interval (string) — one of REFRESH_INTERVALS; default: weekly
     *   refresh_time (string) — HH:MM; default: 05:00
     */
    public function import( Request $request )
    {
        
        // ── 1. Resolve CSV raw content ────────────────────────────────────────
        try {
            $csvContent = $this->resolveCsvContent( $request );
        } catch (\Exception $e) {
            return response()->json( ['success' => false, 'message' => $e->getMessage()], 422 );
        }

        // ── 2. Parse rows ─────────────────────────────────────────────────────
        $rows = $this->parseCsv( $csvContent );

        
        if (empty( $rows )) {
            return response()->json( [
                'success' => false,
                'message' => "Ce catalogue est vide. Impossible d'importer un catalogue sans produits.",
            ], 422 );
        }

        // ── 3. Resolve catalogue metadata ─────────────────────────────────────
        $etabId = $request->input( 'etab_id' );
        $userId = $request->input( 'user_id' ) ?? $request->user()->id;
        $isFileImport = $request->hasFile( 'csv' );
        $isUrlImport = $request->filled( 'csv_url' );

        $refreshInterval = null;
        $refreshTime = null;
        $nextUpdateAt = null;

        // File upload: keep schedule fields NULL in DB.
        // URL import: apply defaults when values are missing/invalid.
        if ($isUrlImport) {
            $refreshInterval = in_array( $request->input( 'refresh_interval' ), self::REFRESH_INTERVALS, true )
                ? $request->input( 'refresh_interval' )
                : 'weekly';
            $refreshTime = $request->filled( 'refresh_time' ) ? $request->input( 'refresh_time' ) : '05:00';
            $nextUpdateAt = $this->calculateNextUpdate( $refreshInterval, $refreshTime );
        }
        $catalogueGroup = $request->input( 'catalogue_group' )
            ? trim( $request->input( 'catalogue_group' ) )
            : $this->generateCatalogueSlug( $request );

        // Derive source identifier (filename or URL)
        $sourceFile = null;
        if ($request->hasFile( 'csv' )) {
            $sourceFile = $request->file( 'csv' )->getClientOriginalName();
        } elseif ($request->has( 'csv_url' )) {
            $sourceFile = $request->input( 'csv_url' );
        }

        $forceCatalogueId = (int) $request->input( 'force_catalogue_id', 0 );
        $forcedCatalogue = null;
        if ($forceCatalogueId > 0) {
            $forcedCatalogue = CatalogueFlink::query()
                ->where( 'id', $forceCatalogueId )
                ->where( 'etab_id', $etabId )
                ->first();
            if (!$forcedCatalogue) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Catalogue cible introuvable.',
                ], 404 );
            }
        }

        if (!$forcedCatalogue) {
            $slotsMax = $this->getEtabSlotsMax( (int) $etabId );
            $usedSlots = (int) CatalogueFlink::query()->where( 'etab_id', $etabId )->count();
            if ($usedSlots >= $slotsMax) {
                return response()->json( [
                    'success' => false,
                    'error_code' => 'slot_limit_reached',
                    'message' => 'Vous avez atteint la limite de Flink Shop pour ce Compte Pro.',
                    'slots' => [
                        'max' => $slotsMax,
                        'used' => $usedSlots,
                        'remaining' => max( 0, $slotsMax - $usedSlots ),
                        'price_per_slot' => self::SLOT_PRICE_DH,
                        'max_purchase_per_order' => self::MAX_SLOT_PURCHASE_PER_ORDER,
                    ],
                ], 422 );
            }
        }

        // If user explicitly provides a catalogue name, block duplicates for same etab_id.
        if ($request->filled( 'catalogue_group' ) && !$request->boolean( 'skip_duplicate_name_check', false )) {
            $existingByNameAndSource = CatalogueFlink::where( 'etab_id', $etabId )
                ->where( 'catalogue_group', $catalogueGroup )
                ->when( $sourceFile !== null, fn( $q ) => $q->where( 'source_file', $sourceFile ) )
                ->when( $forcedCatalogue !== null, fn( $q ) => $q->where( 'id', '!=', $forcedCatalogue->id ) )
                ->first();

            if ($existingByNameAndSource) {
                return response()->json( [
                    'success' => false,
                    'message' => "Ce Flink Shop existe déjà pour cet établissement (même nom et même fichier source).",
                    'catalogue_id' => $existingByNameAndSource->id,
                ], 422 );
            }

            $existingByNameOnly = CatalogueFlink::where( 'etab_id', $etabId )
                ->where( 'catalogue_group', $catalogueGroup )
                ->when( $forcedCatalogue !== null, fn( $q ) => $q->where( 'id', '!=', $forcedCatalogue->id ) )
                ->first();

            if ($existingByNameOnly) {
                return response()->json( [
                    'success' => false,
                    'message' => "Le nom du Flink Shop '{$catalogueGroup}' existe déjà. Tapez un autre nom.",
                    'catalogue_id' => $existingByNameOnly->id,
                ], 422 );
            }
        }

        // ── 4. Upsert catalogue record ────────────────────────────────────────
        $createdNewCatalogue = false;
        if ($forcedCatalogue) {
            $catalogue = $forcedCatalogue;
            $catalogue->catalogue_group = $catalogueGroup;
            if ($sourceFile !== null) {
                $catalogue->source_file = $sourceFile;
            }
            $catalogue->refresh_interval = $refreshInterval;
            $catalogue->refresh_time = $refreshTime;
            $catalogue->next_update_at = $nextUpdateAt;
            $catalogue->last_imported_at = now();
            $catalogue->save(); // Immediate commit: ensure changes are visible while processing starts
        } else {
            $createdNewCatalogue = true;
            $catalogue = CatalogueFlink::create( [
                'catalogue_group' => $catalogueGroup,
                'etab_id' => $etabId,
                'user_id' => $userId,
                'source_file' => $sourceFile,
                'refresh_interval' => $refreshInterval,
                'refresh_time' => $refreshTime,
                'next_update_at' => $nextUpdateAt,
                'last_imported_at' => now(),
            ] );
        }

                $operation = $forcedCatalogue ? 'update' : 'create';
        $logId = $this->createCatalogueLog((int) $etabId, (int) $userId, (int) $catalogue->id, $operation, false);
        $this->markCatalogueProcessingState(
            $catalogue,
            self::PROCESSING_STATE_RUNNING,
            $operation,
            $operation === 'create' ? 'Création en cours' : 'Mise à jour en cours'
        );

        $overflowMode = (string) $request->input( 'overflow_mode', '' );
        $syncContextForLimit = $this->buildRefreshSyncContext( (int) $etabId, (int) $catalogue->id );
        $limitState = $this->prepareCatalogueOverflowState(
            $catalogue,
            $rows,
            $syncContextForLimit,
            $overflowMode,
            false
        );
        if (!empty( $limitState['blocked'] )) {
            $this->markCatalogueProcessingState(
                $catalogue,
                self::PROCESSING_STATE_FAILED,
                $operation,
                (string) ( $limitState['response']['message'] ?? 'Traitement interrompu' )
            );
            $this->finishCatalogueLog($logId, ['failed' => 1], false, (string) ( $limitState['response']['message'] ?? null ));
            if ($createdNewCatalogue) {
                try {
                    if (method_exists( $catalogue, 'forceDelete' )) {
                        $catalogue->forceDelete();
                    } else {
                        $catalogue->delete();
                    }
                } catch (\Throwable $e) {
                    $this->logWarning( 'Failed deleting blocked catalogue after limit choice requirement', [
                        'catalogue_id' => $catalogue->id,
                        'error' => $e->getMessage(),
                    ] );
                }
            }
            return response()->json(
                $limitState['response'],
                (int) ( $limitState['response']['status_code'] ?? 409 )
            );
        }
        $currentCatalogueStartCount = (int) DB::table( 'publications_flink' )
            ->where( 'catalogue_id', (int) $catalogue->id )
            ->count();
        $currentRemainingSlots = max( 0, self::MAX_POSTS_PER_CATALOGUE - $currentCatalogueStartCount );

        // ── 5. Process each CSV row ───────────────────────────────────────────
        $results = [];
        $totalRows = count( $rows );
        $this->updateCatalogueProcessingProgress( (int) $catalogue->id, 0, $totalRows );
        $createCtrl = new CreatePublicationController();
        $seenByIdDescription = [];
        $seenByIdImageDefault = [];
        $seenByIdImagesList = [];
        $databaseSavePlan = [];

        // Phase 1 — log raw values extracted from the catalogue file (all rows).
        $extractedSnapshots = [];
        foreach ($rows as $index => $row) {
            $extractedSnapshots[] = $this->snapshotCatalogueRowExtracted( $row, $index + 2 );
        }
        $this->logCatalogueImportRowsDocument(
            $logId,
            (int) $catalogue->id,
            $operation,
            'extracted_from_file',
            $extractedSnapshots,
            ['total_rows' => $totalRows, 'catalogue_group' => $catalogueGroup]
        );

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // Moved to top

            // Cancellation check on every row to ensure instant termination when user clicks 'Cancel'.
            if (true) {
                if ($this->shouldStopProcessing( (int) $catalogue->id )) {
                    // Broadcast final "failed" (cancelled) state so UI stops immediately
                    $this->updateCatalogueProcessingProgress((int) $catalogue->id, $index, $totalRows, null, null, 'failed');
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => 'Traitement annulé par l’utilisateur.',
                    ];
                    break;
                }
            }
            $targetCatalogueId = (int) $catalogue->id;
            $rowProbe = $this->probeRowCatPostForCatalogue(
                $row,
                (int) $catalogue->id,
                $syncContextForLimit
            );
            $isExistingInCurrent = (bool) $rowProbe['is_existing'];
            $isNewForCurrent = !$isExistingInCurrent;
            if ($isNewForCurrent && $currentRemainingSlots <= 0) {
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'error',
                    'reason' => 'Limite catalogue atteinte (' . self::MAX_POSTS_PER_CATALOGUE . '). Ligne ignorée.',
                ];
                continue;
            }

            // Validate row
            $validationError = $this->validateRow( $row );
            if ($validationError) {
                $this->logWarning( "Catalogue row {$rowNum} validation failed", [
                    'reason' => $validationError,
                    'row_data' => $row
                ] );
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'error',
                    'reason' => $validationError,
                ];
                continue;
            }

            $rawCatPostId = $this->normalizeCatPostId( $row['cat_post_id'] ?? null );
            $catPostId = $rawCatPostId !== null
                ? $this->buildScopedCatPostId( $targetCatalogueId, $rawCatPostId )
                : null;
            if ($catPostId !== null) {

                $incomingDescription = trim( (string) ( $row['description'] ?? '' ) );
                $incomingMainImage = $this->normalizeImageUrlForDuplicateCheck( $row['image_default'] ?? null );
                $incomingImagesList = $this->normalizeImagesListForDuplicateCheck( $row['images'] ?? null, $incomingMainImage );

                $idDescriptionKey = $this->buildDuplicateKey( $etabId, $catPostId, $this->normalizeDescriptionForDuplicateCheck( $incomingDescription ) );
                if ($idDescriptionKey !== null && isset( $seenByIdDescription[$idDescriptionKey] )) {
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => "Rejet doublon (même import): id + description.",
                    ];
                    continue;
                }

                $idImageDefaultKey = $this->buildDuplicateKey( $etabId, $catPostId, $incomingMainImage );
                if ($idImageDefaultKey !== null && isset( $seenByIdImageDefault[$idImageDefaultKey] )) {
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => "Rejet doublon (même import): id + image_default.",
                    ];
                    continue;
                }

                $idImagesListKey = $this->buildDuplicateKey( $etabId, $catPostId, $incomingImagesList );
                if ($idImagesListKey !== null && isset( $seenByIdImagesList[$idImagesListKey] )) {
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => "Rejet doublon (même import): id + images.",
                    ];
                    continue;
                }

                $existingByDescription = $this->findExistingByCatPostIdAndDescription( $etabId, $catPostId, $incomingDescription );
                if ($existingByDescription) {
                    $existingLabel = $existingByDescription->description ?? 'Sans description';
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => "Rejet doublon (même établissement): id + description (existant: {$existingLabel}).",
                    ];
                    continue;
                }

                $existingByMainImage = $this->findExistingByCatPostIdAndImageDefault( $etabId, $catPostId, $incomingMainImage );
                if ($existingByMainImage) {
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => "Rejet doublon (même établissement): id + image_default.",
                    ];
                    continue;
                }

                $existingByImagesList = $this->findExistingByCatPostIdAndImagesList( $etabId, $catPostId, $incomingImagesList );
                if ($existingByImagesList) {
                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => "Rejet doublon (même établissement): id + images.",
                    ];
                    continue;
                }

                if ($idDescriptionKey !== null) {
                    $seenByIdDescription[$idDescriptionKey] = true;
                }
                if ($idImageDefaultKey !== null) {
                    $seenByIdImageDefault[$idImageDefaultKey] = true;
                }
                if ($idImagesListKey !== null) {
                    $seenByIdImagesList[$idImagesListKey] = true;
                }
            }

            try {
                // Phase 2 — convert images (WebP on FTP) then log DB payload before store().
                $processedImages = $this->processCatalogueRowImagesToWebp( $row, $targetCatalogueId, null );

                $syntheticRequest = $this->buildPublicationRequest(
                    $row,
                    $etabId,
                    $userId,
                    $request
                );

                $rowDatabaseSnapshot = $this->snapshotCatalogueRowDatabasePayload(
                    $row,
                    $rowNum,
                    $targetCatalogueId,
                    $catPostId,
                    $processedImages,
                    $syntheticRequest
                );
                $databaseSavePlan[] = $rowDatabaseSnapshot;

                $this->logInfo( "Catalogue row {$rowNum} — values to save to database", [
                    'catalogue_id' => $targetCatalogueId,
                    'log_id' => $logId,
                    'row' => $rowDatabaseSnapshot,
                ] );

                if ($this->shouldStopProcessing((int) $catalogue->id)) {
                    $this->updateCatalogueProcessingProgress((int) $catalogue->id, $index, $totalRows, null, null, 'failed');
                    break;
                }
                $response = $this->storeWithRetry( $createCtrl, $syntheticRequest, $rowNum, (int) $catalogue->id );

                // If video is still processing, retry once without video token so the post is created now.
                if ($response->getStatusCode() === 202 && $syntheticRequest->has( 'video_file_id' )) {
                    $fallbackData = $syntheticRequest->all();
                    unset($fallbackData['video_file_id']);

                    $fallbackRequest = Request::create(
                        '/api/publications/create',
                        'POST',
                        $fallbackData,
                        $syntheticRequest->cookies->all(),
                        $syntheticRequest->allFiles(),
                        $syntheticRequest->server->all()
                    );

                    $this->logWarning( "Catalogue row {$rowNum}: video still processing, creating publication without video attachment for fast import.", [
                        'video_file_id' => $syntheticRequest->input( 'video_file_id' )
                    ] );

                    $response = $this->storeWithRetry( $createCtrl, $fallbackRequest, $rowNum );
                }

                $content = $response->getContent();
                $responseData = json_decode( $content, true );

                if (!empty( $responseData['success'] ) && !empty( $responseData['publication'] )) {
                    $pubId = $responseData['publication']['id'];

                    // ── Apply URL-based deterministic naming to newly created publication ─────
                    // This ensures consistency between creation and update/refresh operations
                    $this->applyUrlBasedNamingToExistingPublication(
                        $row,
                        (int) $pubId,
                        $targetCatalogueId,
                        $responseData['publication']['created_at'] ?? null,
                        $processedImages
                    );

                    // ── Link the publication to this catalogue ─────────────
                    $linked = $this->linkPublicationToCatalogue(
                        (int) $pubId,
                        $targetCatalogueId,
                        $catPostId,
                        (int) $etabId,
                        $rowNum
                    );
                    if (!$linked) {
                        $results[] = [
                            'row' => $rowNum,
                            'status' => 'error',
                            'reason' => 'Échec de liaison au catalogue (possible doublon cat_post_id).',
                        ];
                        continue;
                    }

                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'success',
                        'publication_id' => $pubId,
                        'post_data' => [
                            'id' => $pubId,
                            'slug' => $responseData['publication']['slug'] ?? null,
                            'image_default' => $responseData['publication']['image_default'] ?? null,
                        ]
                    ];
                    if ($targetCatalogueId === (int) $catalogue->id && $isNewForCurrent && $currentRemainingSlots > 0) {
                        $currentRemainingSlots--;
                    }
                } else {
                    $this->logError( "Catalogue row {$rowNum} store() failed", [
                        'response' => $content,
                        'status' => $response->getStatusCode()
                    ] );

                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => $responseData['message'] ?? 'Erreur inconnue du contrôleur. Vérifiez les logs backend.',
                    ];
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                if (
                    $catPostId !== null
                    && ( str_contains( $errorMessage, 'uq_publications_etab_cat_post_id' )
                        || str_contains( $errorMessage, 'Duplicate entry' ) )
                ) {
                    $incomingDescription = trim( (string) ( $row['description'] ?? '' ) );
                    $existingImported = $this->findExistingByCatPostIdAndDescription( $etabId, $catPostId, $incomingDescription );

                    $existingTitle = $existingImported->description ?? 'Sans titre';

                    $results[] = [
                        'row' => $rowNum,
                        'status' => 'error',
                        'reason' => "Post déjà existant pour cet établissement (id CSV {$catPostId} + description identique : {$existingTitle}).",
                    ];
                    continue;
                }

                $this->logError( "CatalogueController row {$rowNum} exception: " . $errorMessage, [
                    'trace' => $e->getTraceAsString()
                ] );
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'error',
                    'reason' => $errorMessage,
                ];
            }
            // Fire progress event for EVERY row to ensure smooth real-time increments.
            if (true) {
                $lastResult = end($results);
                $this->updateCatalogueProcessingProgress( (int) $catalogue->id, $index + 1, $totalRows, null, $lastResult['post_data'] ?? null );
            }
        }

        // ── 6. Build summary report ───────────────────────────────────────────
        $successCount = count( array_filter( $results, fn( $r ) => $r['status'] === 'success' ) );
        $failedCount = count( array_filter( $results, fn( $r ) => $r['status'] === 'error' ) );

        $this->logInfo( "CatalogueController::import done — success:{$successCount} failed:{$failedCount}" );

        // Phase 2 (batch) — all rows ready for database persistence (JSON in catalogues_logs).
        $this->logCatalogueImportRowsDocument(
            $logId,
            (int) $catalogue->id,
            $operation,
            'database_save_plan',
            $databaseSavePlan,
            [
                'planned_rows' => count( $databaseSavePlan ),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
            ]
        );

        // Do not keep empty catalogues (0 successful posts created).
        if ($createdNewCatalogue && $successCount === 0) {
            $this->markCatalogueProcessingState(
                $catalogue,
                self::PROCESSING_STATE_FAILED,
                $operation,
                "Aucune publication n'a été créée."
            );
            $this->finishCatalogueLog($logId, ['failed' => $failedCount], false, [
                'message' => "Aucune publication n'a été créée.",
                'extracted_from_file' => $extractedSnapshots,
                'database_save_plan' => $databaseSavePlan,
                'results' => $results,
            ]);
            try {
                if (method_exists( $catalogue, 'forceDelete' )) {
                    $catalogue->forceDelete();
                } else {
                    $catalogue->delete();
                }
            } catch (\Throwable $e) {
                $this->logWarning( 'Failed deleting empty catalogue after import', [
                    'catalogue_id' => $catalogue->id,
                    'error' => $e->getMessage(),
                ] );
            }

            return response()->json( [
                'success' => false,
                'message' => "Le format du fichier CSV est incorrect. Aucune publication n'a pu être créée.",
                'total' => count( $rows ),
                'success_count' => 0,
                'failed' => $failedCount,
                'results' => $results,
            ], 422 );
        }

        $this->updateCatalogueProcessingProgress(
            (int) $catalogue->id,
            $totalRows,
            $totalRows,
            [
                'created' => $successCount,
                'updated' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'failed' => $failedCount,
                'last_post_id' => null,
                'last_action' => 'completed',
            ],
            null,
            'finished'
        );

        $this->markCatalogueProcessingState(
            $catalogue,
            self::PROCESSING_STATE_FINISHED,
            $operation,
            null
        );
        $this->finishCatalogueLog($logId, [
            'created' => $successCount,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'failed' => $failedCount,
        ], true, [
            'extracted_from_file' => $extractedSnapshots,
            'database_save_plan' => $databaseSavePlan,
            'results' => $results,
        ]);

        // If it was an update but nothing was created (0 successes), 
        // we revert the source settings to keep the last known good state.
        if (!$createdNewCatalogue && $successCount === 0 && ($operation === 'update' || $operation === 'modifier')) {
            $catalogue->source_file = $catalogue->getOriginal('source_file');
            $catalogue->refresh_interval = $catalogue->getOriginal('refresh_interval');
            $catalogue->refresh_time = $catalogue->getOriginal('refresh_time');
            $catalogue->next_update_at = $catalogue->getOriginal('next_update_at');
            $catalogue->catalogue_group = $catalogue->getOriginal('catalogue_group');
            $catalogue->last_imported_at = $catalogue->getOriginal('last_imported_at');
        }
        $catalogue->save();

        return response()->json( [
            'success' => true,
            'catalogue_id' => $catalogue->id,
            'catalogue_group' => $catalogue->catalogue_group,
            'next_update_at' => $catalogue->next_update_at,
            'total' => count( $rows ),
            'success_count' => $successCount,
            'failed' => $failedCount,
            'results' => $results,
        ] );
    }

    // ─── Generate a unique catalogue slug ─────────────────────────────────────
    private function generateCatalogueSlug( Request $request ): string
    {
        $user = $request->user();
        $firstName = trim( (string) ( $user->first_name ?? $user->prenom ?? '' ) );
        $lastName = trim( (string) ( $user->last_name ?? $user->nom ?? '' ) );

        $firstPart = $this->extractNamePart( $firstName );
        $lastPart = $this->extractNamePart( $lastName );
        $namePart = $firstPart . ( $lastPart !== '' ? $lastPart : '' );
        if ($namePart === '') {
            $namePart = 'user';
        }

        do {
            $milliseconds = (string) floor( microtime( true ) * 1000 );
            $slug = "Flink_shop_{$namePart}_{$milliseconds}";
        } while (CatalogueFlink::where( 'catalogue_group', $slug )->exists());

        return $slug;
    }

    private function extractNamePart( string $value ): string
    {
        if ($value === '') {
            return '';
        }

        $lettersOnly = preg_replace( '/[^a-zA-Z]/', '', $value ) ?? '';
        $lettersOnly = Str::lower( $lettersOnly );
        if ($lettersOnly === '') {
            return '';
        }

        return substr( $lettersOnly, 0, 2 );
    }

    // ─── Calculate next auto-update datetime ──────────────────────────────────
    private function calculateNextUpdate( string $interval, string $time, $from = null ): string
    {
        [$hour, $minute] = array_map( 'intval', explode( ':', $time . ':00' ) );
        $base = ( $from ? \Illuminate\Support\Carbon::parse( $from ) : now() )->copy()->setTime( $hour, $minute, 0 );
        $now = now();

        while ($base->lessThanOrEqualTo( $now )) {
            $base = match ( $interval ) {
                'daily' => $base->copy()->addDay(),
                'every_2_days' => $base->copy()->addDays( 2 ),
                'every_3_days' => $base->copy()->addDays( 3 ),
                'weekly' => $base->copy()->addWeek(),
                'every_2_weeks' => $base->copy()->addWeeks( 2 ),
                'monthly' => $base->copy()->addMonth(),
                'yearly' => $base->copy()->addYear(),
                default => $base->copy()->addWeek(),
            };
        }

        return $base->toDateTimeString();
    }

    public function markScheduledRefreshFailureById( int $catalogueId, string $message ): void
    {
        $catalogue = CatalogueFlink::query()->where( 'id', $catalogueId )->first();
        if (!$catalogue) {
            return;
        }

        $catalogue->next_update_at = now()->addMinutes( 15 );
        $catalogue->save();

        $this->logWarning( 'Catalogue auto refresh rescheduled after failure.', [
            'catalogue_id' => $catalogueId,
            'message' => $message,
            'next_update_at' => $catalogue->next_update_at,
        ] );
    }

    // ─── Resolve CSV raw content from uploaded file or remote URL ─────────────
    private function resolveCsvContent( Request $request ): string
    {
        if ($request->hasFile( 'csv' )) {
            $file = $request->file( 'csv' );
            if (!$file->isValid()) {
                throw new \Exception( 'Fichier invalide ou corrompu.' );
            }
            $this->validateUploadedCsvFile( $file );
            $content = file_get_contents( $file->getRealPath() );
            if (!is_string( $content ) || $content === '') {
                throw new \Exception( 'Le contenu du fichier CSV est vide.' );
            }
            $this->assertCsvContentSafety( $content );
            return $content;
        }

        if ($request->filled( 'csv_url' )) {
            $rawUrl = (string) $request->input( 'csv_url' );
            $this->validateRemoteCsvUrl( $rawUrl );
            $url = $this->appendCacheBusterToUrl( $rawUrl );
            $maxAttempts = 3;
            $response = null;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $response = Http::timeout( 30 )
                    ->withHeaders( [
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache',
                        'Accept' => 'text/csv,text/plain,*/*',
                        'User-Agent' => 'Flink-Catalogue-Refresh/1.0',
                    ] )
                    ->get( $url );

                $status = (int) $response->status();
                $shouldRetry = in_array( $status, [429, 500, 502, 503, 504], true );
                if ($response->successful() || !$shouldRetry || $attempt >= $maxAttempts) {
                    break;
                }

                $sleepMs = 400 * $attempt;
                $this->logWarning( 'Temporary remote CSV fetch error, retrying.', [
                    'url' => $rawUrl,
                    'resolved_url' => $url,
                    'status' => $status,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'retry_in_ms' => $sleepMs,
                ] );
                usleep( $sleepMs * 1000 );
            }
            if (!$response->successful()) {
                throw new \Exception(
                    "Impossible de récupérer le fichier depuis l'URL (HTTP {$response->status()}). Réessayez dans quelques instants."
                );
            }
            $contentType = strtolower( (string) $response->header( 'Content-Type', '' ) );
            if (!$this->isAllowedRemoteContentType( $contentType )) {
                throw new \Exception( 'Type de fichier distant non autorisé. Seuls les CSV/TXT sont acceptés.' );
            }
            $body = (string) $response->body();
            $this->assertCsvContentSafety( $body );
                        return $body;
        }

        throw new \Exception( 'Aucun fichier ni URL fourni.' );
    }

    private function appendCacheBusterToUrl( string $url ): string
    {
        $trimmed = trim( $url );
        if ($trimmed === '') {
            return $trimmed;
        }
        $separator = str_contains( $trimmed, '?' ) ? '&' : '?';
        return $trimmed . $separator . '_refresh=' . now()->timestamp;
    }

    // ─── Parse CSV text into an array of associative rows ─────────────────────
    private function parseCsv( string $content ): array
    {

        // 1. Handle Encoding (detect UTF-16 and convert to UTF-8)
        $bom2 = bin2hex( substr( $content, 0, 2 ) );
        $bom3 = bin2hex( substr( $content, 0, 3 ) );

        if ($bom2 === 'fffe' || $bom2 === 'feff') {
            $content = mb_convert_encoding( $content, 'UTF-8', 'UTF-16' );
        } elseif ($bom3 === 'efbbbf') {
            $content = substr( $content, 3 );
        } elseif (!preg_match( '//u', $content )) {
            if (function_exists( 'mb_convert_encoding' )) {
                $content = mb_convert_encoding( $content, 'UTF-8', 'ISO-8859-1, Windows-1252' );
            } else {
                $content = utf8_encode( $content );
            }
        }

        // 2. Normalize line endings
        $content = str_replace( ["\r\n", "\r"], "\n", $content );

        // 3. Normalize lines & Handle wrapped-quote format
        $lines = array_values(
            array_filter( explode( "\n", $content ), fn( $l ) => trim( $l ) !== '' )
        );

        // Standard CSVs sometimes wrap the whole row in quotes and double the internal quotes.
        // We detect this by checking if the first line starts and ends with a quote but has commas inside.
        if (count( $lines ) > 0) {
            $testLine = trim( $lines[0] );
            if (str_starts_with( $testLine, '"' ) && str_ends_with( $testLine, '"' ) && str_contains( $testLine, ',' )) {
                $lines = array_map( function ( $l ) {
                    $l = trim( $l );
                    if (str_starts_with( $l, '"' ) && str_ends_with( $l, '"' )) {
                        $l = substr( $l, 1, -1 );
                        $l = str_replace( '""', '"', $l );
                    }
                    return $l;
                }, $lines );
            }
        }

        if (count( $lines ) < 2) {
            return [];
        }
        if (count( $lines ) > self::MAX_CSV_ROWS + 1) {
            throw new \RuntimeException( "Le fichier dépasse la limite autorisée de " . self::MAX_CSV_ROWS . " lignes." );
        }

        // 4. Detect delimiter (comma, semicolon, tab, pipe)
        $firstLine = $lines[0];
        $delimiters = [',', ';', "\t", '|'];
        $counts = [];
        foreach ($delimiters as $d) {
            $counts[$d] = substr_count( $firstLine, $d );
        }
        arsort( $counts );
        $detectedDelimiter = (string) array_key_first( $counts );
        if ($counts[$detectedDelimiter] === 0) {
            $detectedDelimiter = ','; // default
        }


        // 5. Parse & Normalize Headers
        $rawLine = array_shift( $lines );
        $rawHeader = str_getcsv( $rawLine, $detectedDelimiter );
        if (count( $rawHeader ) > self::MAX_COLUMNS) {
            throw new \RuntimeException( "Le fichier dépasse la limite de " . self::MAX_COLUMNS . " colonnes." );
        }

        // Fallback: If we got only 1 column but the line has commas, try comma
        if (count( $rawHeader ) === 1 && $detectedDelimiter !== ',' && str_contains( $rawLine, ',' )) {
            $this->logInfo( "Catalogue parsing: fallback to comma" );
            $detectedDelimiter = ',';
            $rawHeader = str_getcsv( $rawLine, ',' );
        }

        $header = array_map( function ( $h ) {
            $clean = strtolower( trim( $h, " \t\n\r\0\x0B\"'" ) );
            $clean = str_replace( ['-', '  '], ['_', ' '], $clean );
            $clean = trim( $clean );

            foreach (self::HEADER_SYNONYMS as $canonical => $aliases) {
                if (in_array( $clean, $aliases, true )) {
                    return $canonical;
                }
            }

            return match ( $clean ) {
                'availability', 'disponibilité', 'stock' => 'availability',
                'brand', 'marque' => 'brand',
                'model', 'modèle' => 'model',
                'link', 'final_url', 'url' => 'final_url',
                default => str_replace( [' ', '-'], '_', $clean )
            };
        }, $rawHeader );

        // 6. Build Associative Rows
        $rows = [];
        foreach ($lines as $line) {
            $values = str_getcsv( $line, $detectedDelimiter );
            if (count( $values ) !== count( $header )) {
                if (count( $values ) < count( $header )) {
                    $values = array_pad( $values, count( $header ), null );
                } else {
                    $values = array_slice( $values, 0, count( $header ) );
                }
            }
            $cleanValues = array_map( fn( $value ) => $this->sanitizeCsvCellValue( $value ), $values );
            $rows[] = array_combine( $header, $cleanValues );
        }

        return $rows;
    }

    private function validateUploadedCsvFile( UploadedFile $file ): void
    {
        $extension = strtolower( (string) $file->getClientOriginalExtension() );
        if (!in_array( $extension, self::ALLOWED_UPLOAD_EXTENSIONS, true )) {
            throw new \Exception( 'Extension de fichier non autorisée. Utilisez uniquement .csv ou .txt.' );
        }

        $mime = strtolower( (string) $file->getMimeType() );
        if ($mime !== '' && !in_array( $mime, self::ALLOWED_UPLOAD_MIMES, true )) {
            throw new \Exception( 'Type MIME non autorisé pour le fichier.' );
        }

        if ($file->getSize() > self::MAX_CSV_BYTES) {
            throw new \Exception( 'Le fichier dépasse la taille maximale autorisée (5 MB).' );
        }
    }

    private function validateRemoteCsvUrl( string $url ): void
    {
        $trimmed = trim( $url );
        if ($trimmed === '' || !filter_var( $trimmed, FILTER_VALIDATE_URL )) {
            throw new \Exception( 'URL CSV invalide.' );
        }

        $parts = parse_url( $trimmed );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if (!in_array( $scheme, ['http', 'https'], true )) {
            throw new \Exception( 'Schéma URL non autorisé.' );
        }

        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        if ($host === '' || in_array( $host, ['localhost', '127.0.0.1', '::1'], true )) {
            throw new \Exception( 'Hôte URL non autorisé.' );
        }

        $resolvedIps = @gethostbynamel( $host ) ?: [];
        foreach ($resolvedIps as $ip) {
            if ($this->isPrivateOrReservedIp( $ip )) {
                throw new \Exception( 'URL CSV non autorisée (réseau privé/réservé).' );
            }
        }
    }

    private function isAllowedRemoteContentType( string $contentType ): bool
    {
        if ($contentType === '') {
            return true; // some providers omit it
        }
        foreach (self::ALLOWED_UPLOAD_MIMES as $allowed) {
            if (str_contains( $contentType, strtolower( $allowed ) )) {
                return true;
            }
        }
        // Allow octet-stream only when URL extension looks like CSV/TXT.
        return str_contains( $contentType, 'application/octet-stream' );
    }

    private function assertCsvContentSafety( string $content ): void
    {
        $size = strlen( $content );
        if ($size === 0) {
            throw new \Exception( 'Le fichier CSV est vide.' );
        }
        if ($size > self::MAX_CSV_BYTES) {
            throw new \Exception( 'Le fichier dépasse la taille maximale autorisée (5 MB).' );
        }
        if (str_contains( $content, "\0" )) {
            throw new \Exception( 'Le fichier contient des caractères binaires invalides.' );
        }
    }

    private function sanitizeCsvCellValue( mixed $value ): string
    {
        $text = trim( (string) $value );
        // Remove script/style tags and all HTML tags.
        $text = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text ) ?? $text;
        $text = strip_tags( $text );
        $text = str_replace( ["\0", "\x1A"], '', $text );
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text ) ?? $text;
        $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
        if (mb_strlen( $text ) > self::MAX_CELL_LENGTH) {
            $text = mb_substr( $text, 0, self::MAX_CELL_LENGTH );
        }
        return trim( $text );
    }

    private function isPrivateOrReservedIp( string $ip ): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    // ─── Validate a single CSV row ────────────────────────────────────────────
    private function validateRow( array $row ): ?string
    {
        $galleryUrls = !empty( $row['images'] )
            ? array_filter( array_map( 'trim', preg_split( '/[|,]/', (string) $row['images'] ) ) )
            : [];

        $hasImage = !empty( $row['image_default'] ) || !empty( $galleryUrls );
        $hasVideo = false;

        if (!$hasImage && !$hasVideo) {
            return 'Media requis : image_default manquant (vérifier les colonnes).';
        }

        if (empty( $row['tags'] )) {
            return 'tags requis.';
        }

        if (empty( $row['pays'] )) {
            return 'pays requis.';
        }

        if (empty( $row['ville'] )) {
            return 'ville requis.';
        }

        if (!empty( $row['image_default'] ) && !filter_var( $row['image_default'], FILTER_VALIDATE_URL )) {
            return "URL d'image invalide.";
        }
        foreach ($galleryUrls as $url) {
            if (!filter_var( $url, FILTER_VALIDATE_URL )) {
                return "URL d'image galerie invalide.";
            }
        }

        $disponibilite = $this->normalizeDisponibilite( $row['disponibilite'] ?? $row['availability'] ?? null );
        if (!empty( $disponibilite ) && !in_array( $disponibilite, self::AVAILABILITY_VALUES, true )) {
            return 'Valeur disponibilite invalide.';
        }

        $rawDisponibiliteDate = $row['disponibilite_date'] ?? null;
        if ($rawDisponibiliteDate !== null && trim( (string) $rawDisponibiliteDate ) !== '' && $this->normalizeDisponibiliteDate( $rawDisponibiliteDate ) === null) {
            return "Date disponibilite_date invalide (formats acceptés: YYYY-MM-DD, DD/MM/YYYY, MM/DD/YYYY).";
        }

        return null;
    }

    // ─── Build a synthetic Request for CreatePublicationController::store() ────
    private function buildPublicationRequest(
        array $row,
        int|string $etabId,
        int|string $userId,
        Request $originalRequest
    ): Request {
        // Tags — fallback to title if keywords missing
        $tagsString = $row['tags'] ?? $row['title'] ?? '';
        $tagNames = array_values( array_unique( array_filter(
            array_map( 'trim', preg_split( '/[|,<>]/', $tagsString ) )
        ) ) );

        // Delivery modes
        $modeIds = [];
        if (!empty( $row['delivery_modes'] )) {
            foreach (array_map( 'trim', preg_split( '/[|,]/', $row['delivery_modes'] ) ) as $name) {
                $key = strtolower( $name );
                if (isset( self::DELIVERY_MODE_MAP[$key] )) {
                    $modeIds[] = self::DELIVERY_MODE_MAP[$key];
                }
            }
        }
        if (!empty( $row['mode_livraison'] )) {
            foreach (array_map( 'trim', preg_split( '/[|,]/', $row['mode_livraison'] ) ) as $name) {
                $key = strtolower( $name );
                if (isset( self::DELIVERY_MODE_MAP[$key] )) {
                    $modeIds[] = self::DELIVERY_MODE_MAP[$key];
                }
            }
        }
        $modeIds = array_values( array_unique( $modeIds ) );

        $isMarketplace = true;

        $galleryUrls = !empty( $row['images'] )
            ? array_values( array_filter( array_map( 'trim', preg_split( '/[|,]/', (string) $row['images'] ) ) ) )
            : [];

        // Ensure a canonical main image exists (fallback to first gallery image)
        if (empty( $row['image_default'] ) && !empty( $galleryUrls )) {
            $row['image_default'] = $galleryUrls[0];
        }

        // Fetch image as UploadedFile if URL provided
        $uploadedFiles = [];
        
        if (!empty( $row['image_default'] )) {
            try {
                $imageFile = $this->urlToUploadedFile( $row['image_default'] );
                if ($imageFile) {
                    $uploadedFiles['image_default'] = $imageFile;
                }
            } catch (\Exception $e) {
                $this->logWarning( "Catalogue: Failed fetching image_default: " . $e->getMessage() );
            }
        }

        // Fetch multiple images (gallery)
        if (!empty( $galleryUrls )) {
            $galleryFiles = [];
            foreach ($galleryUrls as $url) {
                if (!filter_var( $url, FILTER_VALIDATE_URL )) {
                    continue;
                }
                if (!empty( $row['image_default'] )
                    && $this->catalogueImageSourcesAreSame( $url, (string) $row['image_default'] )) {
                    continue;
                }
                try {
                    $file = $this->urlToUploadedFile( $url );
                    if ($file)
                        $galleryFiles[] = $file;
                } catch (\Exception $e) {
                    $this->logWarning( "Catalogue: Failed fetching gallery image: " . $e->getMessage() );
                }
                if (count( $galleryFiles ) >= 8)
                    break; // Reasonable limit
            }
            if (!empty( $galleryFiles )) {
                $uploadedFiles['images'] = $galleryFiles;
            }
        }

        // Do not upload the same file twice to FTP via store() — images column is set from
        // applyUrlBasedNamingToExistingPublication() using the same path as image_default.

        // Content / Description fallback + Brand/Model enrichment
        $description = !empty( $row['description'] ) ? $row['description'] : ( $row['title'] ?? 'Sans titre' );

        // Enrich description with brand/model if available but not in description
        if (!empty( $row['brand'] ) || !empty( $row['model'] )) {
            $prefix = trim( ( $row['brand'] ?? '' ) . ' ' . ( $row['model'] ?? '' ) );
            if ($prefix && !str_contains( strtolower( $description ), strtolower( $prefix ) )) {
                $description = "{$prefix} - {$description}";
            }
        }

        // Resolve pays/ville/region by name or code, creating missing entities by slug.
        $countryId = $this->resolveCountryId( $row['pays'] ?? null );
        $cityId = $this->resolveCityId( $row['ville'] ?? null, $countryId );
        $regionId = $this->resolveRegionId( $row['region'] ?? null, $cityId );

        // Resolve tags by slug first; create if missing and then link by id.
        $resolvedTagIds = $this->resolveTagIds( $tagNames );

        $availability = $this->normalizeDisponibilite( $row['disponibilite'] ?? $row['availability'] ?? null );
        if ($availability === 'vendu' && !$this->postExistsForFirstCreationGuard( $etabId, $userId, $description )) {
            $availability = 'inStock';
        }

        // Map data - ensure empty strings become NULL
        $data = [
            'typePartage' => 'publication',
            'etab_id' => $etabId ?: null,
            'user_id' => $userId,
            'description' => $description,
            'prix' => ( isset( $row['price'] ) && trim( (string) $row['price'] ) !== '' ) ? (float) $row['price'] : null,
            'prixPromo' => ( isset( $row['promo_price'] ) && trim( (string) $row['promo_price'] ) !== '' ) ? (float) $row['promo_price'] : null,
            'pays_id' => $countryId,
            'ville_id' => $cityId,
            'region_id' => $regionId,
            'tele' => isset( $row['phone'] ) && trim( (string) $row['phone'] ) !== '' ? trim( (string) $row['phone'] ) : null,
            'tele_whatsapp' => isset( $row['whatsapp'] ) && trim( (string) $row['whatsapp'] ) !== '' ? trim( (string) $row['whatsapp'] ) : null,
            'tag_name' => empty( $resolvedTagIds ) ? implode( ',', $tagNames ) : null,
            'tag_id' => empty( $resolvedTagIds ) ? '0' : implode( ',', $resolvedTagIds ),
            'mode_laivraison_id' => !empty( $modeIds ) ? implode( ',', $modeIds ) : null,
            'disponibilite' => $availability,
            'disponibilite_date' => $this->normalizeDisponibiliteDate( $row['disponibilite_date'] ?? null ),
            'is_marketplace' => '1',
        ];

        $syntheticRequest = Request::create(
            '/api/publications/create',
            'POST',
            $data,
            $originalRequest->cookies->all(),
            $uploadedFiles,
            $originalRequest->server->all()
        );

        return $syntheticRequest;
    }

    // ─── Download a remote URL and wrap it as an Illuminate UploadedFile ──────
    private function urlToUploadedFile( string $url ): ?\Illuminate\Http\UploadedFile
    {
        $rawBody = $this->downloadRemoteImageBinary( $url );
        if ($rawBody === false || $rawBody === '') {
            throw new \Exception( "Unable to download image: {$url}" );
        }
        $originalName = basename( parse_url( $url, PHP_URL_PATH ) ?? 'file' );

        // Use the same image stack as CreatePublicationController (Intervention + WebpEncoder).
        $webpBinary = $this->convertBinaryImageToWebp( $rawBody );
        if ($webpBinary === null) {
            throw new \Exception( "WebP conversion failed for image: {$url}" );
        }

        $tmpPath = tempnam( sys_get_temp_dir(), 'flink_cat_' ) . '.webp';
        file_put_contents( $tmpPath, $webpBinary );
        $baseName = pathinfo( $originalName, PATHINFO_FILENAME );
        $safeName = $baseName !== '' ? $baseName . '.webp' : 'catalogue-image.webp';

        return new \Illuminate\Http\UploadedFile(
            $tmpPath,
            $safeName,
            'image/webp',
            null,
            true // test mode — skips is_uploaded_file() check
        );
    }

    private function convertBinaryImageToWebp( string $binary ): ?string
    {
        if ($binary === '') {
            return null;
        }
        try {
            $manager = new ImageManager( new Driver() );
            $image = $manager->read( $binary );
            return (string) $image->encode( new WebpEncoder( quality: 90 ) );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function downloadRemoteImageBinary( string $url ): string|false
    {
        if (function_exists( 'curl_init' )) {
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
            curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, [
                'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ] );
            $content = curl_exec( $ch );
            $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );
            if (is_string( $content ) && $content !== '' && $httpCode >= 200 && $httpCode < 300) {
                return $content;
            }
        }

        $context = stream_context_create( [
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'header' => [
                    'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                ],
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ] );
        $content = @file_get_contents( $url, false, $context );
        if (is_string( $content ) && $content !== '') {
            return $content;
        }
        return false;
    }

    private function hasMeaningfulMainImageChange( string $incomingImage, string $existingImage ): bool
    {
        $incoming = $this->normalizeImageUrlForDuplicateCheck( $incomingImage );
        $existing = $this->normalizeImageUrlForDuplicateCheck( $existingImage );
        if ($incoming === '') {
            return false;
        }
        if ($existing === '') {
            return true;
        }
        if ($incoming === $existing) {
            return false;
        }

        $incomingHash = $this->computeImageReferenceHash( $incomingImage );
        $existingHash = $this->computeImageReferenceHash( $existingImage );
        if ($incomingHash !== null && $existingHash !== null) {
            return $incomingHash !== $existingHash;
        }

        if ($this->isHttpUrl( $incoming ) && $this->isStoredPublicationPath( $existing )) {
            return false;
        }

        return true;
    }

    private function hasMeaningfulGalleryImagesChange( string $incomingImages, string $existingImages, string $incomingMain ): bool
    {
        $incomingRefs = $this->extractImageReferencesFromField( $incomingImages );
        $existingRefs = $this->extractImageReferencesFromField( $existingImages );

        $incomingMainNorm = $this->normalizeImageUrlForDuplicateCheck( $incomingMain );
        if ($incomingMainNorm !== '') {
            $incomingRefs = array_values( array_filter( $incomingRefs, function ( string $ref ) use ( $incomingMainNorm ) {
                return $this->normalizeImageUrlForDuplicateCheck( $ref ) !== $incomingMainNorm;
            } ) );
        }

        if (empty( $incomingRefs )) {
            return false;
        }
        if (empty( $existingRefs )) {
            return true;
        }

        $incomingSig = $this->buildImageListHashSignature( $incomingRefs );
        $existingSig = $this->buildImageListHashSignature( $existingRefs );
        if ($incomingSig !== '' && $existingSig !== '') {
            return $incomingSig !== $existingSig;
        }

        $incomingLooksRemote = !empty( array_filter( $incomingRefs, fn( string $ref ) => $this->isHttpUrl( $ref ) ) );
        $existingLooksStored = !empty( $existingRefs ) && count( array_filter( $existingRefs, fn( string $ref ) => $this->isStoredPublicationPath( $ref ) ) ) === count( $existingRefs );
        if ($incomingLooksRemote && $existingLooksStored) {
            return false;
        }

        $incomingNorm = $this->normalizeImagesListForDuplicateCheck( implode( '|', $incomingRefs ), null );
        $existingNorm = $this->normalizeImagesListForDuplicateCheck( implode( '|', $existingRefs ), null );
        return $incomingNorm !== '' && $incomingNorm !== $existingNorm;
    }

    private function extractImageReferencesFromField( ?string $value ): array
    {
        $raw = trim( (string) $value );
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode( $raw, true );
        if (is_array( $decoded )) {
            return array_values( array_filter( array_map( fn( $item ) => trim( (string) $item ), $decoded ) ) );
        }
        return array_values( array_filter( array_map( 'trim', preg_split( '/[|,]/', $raw ) ) ) );
    }

    private function buildImageListHashSignature( array $references ): string
    {
        $hashes = [];
        foreach ($references as $ref) {
            $hash = $this->computeImageReferenceHash( (string) $ref );
            if ($hash !== null) {
                $hashes[] = $hash;
            }
        }
        return !empty( $hashes ) ? implode( '|', $hashes ) : '';
    }

    private function computeImageReferenceHash( string $reference ): ?string
    {
        $ref = trim( $reference );
        if ($ref === '') {
            return null;
        }

        $binary = null;
        if ($this->isHttpUrl( $ref )) {
            $binary = $this->downloadRemoteImageBinary( $ref );
        } else {
            $storagePath = $this->toStorageRelativePath( $ref );
            if ($storagePath !== null && Storage::disk( 'ftp' )->exists( $storagePath )) {
                $binary = Storage::disk( 'ftp' )->get( $storagePath );
            }
        }

        if (!is_string( $binary ) || $binary === '') {
            return null;
        }

        // If the stored asset is already .webp, avoid re-encoding (can slightly change bytes),
        // so we don't falsely detect image changes between runs.
        $isWebpReference = (bool) preg_match( '/\.webp(\?|#|$)/i', $ref );
        if (!$this->isHttpUrl( $ref ) && $isWebpReference) {
            return sha1( $binary );
        }

        $webpBinary = $this->convertBinaryImageToWebp( $binary );
        return sha1( $webpBinary ?? $binary );
    }

    private function isHttpUrl( string $value ): bool
    {
        return (bool) filter_var( trim( $value ), FILTER_VALIDATE_URL );
    }

    private function isStoredPublicationPath( string $value ): bool
    {
        $relative = $this->toStorageRelativePath( $value );
        if ($relative === null) {
            return false;
        }
        return str_starts_with( $relative, 'publication/' );
    }

    private function toStorageRelativePath( string $value ): ?string
    {
        $raw = trim( $value );
        if ($raw === '') {
            return null;
        }
        if ($this->isHttpUrl( $raw )) {
            $path = parse_url( $raw, PHP_URL_PATH );
            if (!is_string( $path ) || trim( $path ) === '') {
                return null;
            }
            $raw = $path;
        }

        $raw = ltrim( $raw, '/' );
        $publicationPos = strpos( $raw, 'publication/' );
        if ($publicationPos !== false) {
            return substr( $raw, $publicationPos );
        }

        return $raw;
    }

    // ─── Guess file extension from Content-Type or URL path ───────────────────
    private function guessExtension( string $contentType, string $url ): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mp4',
        ];
        foreach ($map as $mime => $ext) {
            if (str_contains( $contentType, $mime ))
                return $ext;
        }
        $ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
        return $ext ?: 'jpg';
    }

    private function resolveTagIds( array $tagNames ): array
    {
        $names = array_values( array_unique( array_filter( array_map( fn( $v ) => trim( (string) $v ), $tagNames ) ) ) );
        if (empty( $names )) {
            return [];
        }

        $slugToName = [];
        foreach ($names as $name) {
            $slugToName[Str::slug( $name, '-' )] = $name;
        }
        $slugs = array_keys( $slugToName );

        $resolved = [];
        $missingSlugs = [];
        foreach ($slugs as $slug) {
            if (isset( $this->tagSlugToIdCache[$slug] )) {
                $resolved[$slug] = $this->tagSlugToIdCache[$slug];
            } else {
                $missingSlugs[] = $slug;
            }
        }

        if (!empty( $missingSlugs )) {
            $existingTags = Tags::query()->whereIn( 'slug', $missingSlugs )->get( ['id', 'slug'] );
            foreach ($existingTags as $tag) {
                $this->tagSlugToIdCache[(string) $tag->slug] = (string) $tag->id;
                $resolved[(string) $tag->slug] = (string) $tag->id;
            }

            foreach ($missingSlugs as $slug) {
                if (isset( $resolved[$slug] )) {
                    continue;
                }
                $tag = new Tags();
                $tag->name = $slugToName[$slug] ?? $slug;
                $tag->slug = $slug;
                $tag->save();
                $this->tagSlugToIdCache[$slug] = (string) $tag->id;
                $resolved[$slug] = (string) $tag->id;
            }
        }

        return array_values( array_unique( array_values( $resolved ) ) );
    }

    private function resolveCountryId( ?string $countryCode ): int
    {
        $countryTable = $this->resolveExistingTable( ['pays_flink', 'pays'] );
        if ($countryTable === null) {
            // Fallback to existing behavior in CreatePublicationController::store()
            return 1;
        }

        $code = strtolower( trim( (string) $countryCode ) );
        if ($code === '') {
            return 1;
        }

        if (!$this->tableHasColumn( $countryTable, 'code' )) {
            return 1;
        }

        $country = DB::table( $countryTable )->whereRaw( 'LOWER(code) = ?', [$code] )->first();
        if ($country) {
            return (int) $country->id;
        }

        $name = match ( $code ) {
            'ma' => 'Maroc',
            'fr' => 'France',
            default => strtoupper( $code ),
        };

        $insertData = [
            'name' => $name,
            'code' => strtoupper( $code ),
        ];
        if ($this->tableHasColumn( $countryTable, 'slug' )) {
            $insertData['slug'] = Str::slug( $name );
        }

        $insertData = $this->mergeTableTimestampsForInsert( $countryTable, $insertData );

        return (int) DB::table( $countryTable )->insertGetId( $insertData );
    }

    private function resolveCityId( ?string $cityName, int $countryId ): ?int
    {
        $cityTable = $this->resolveExistingTable( ['villes_flink', 'villes'] );
        if ($cityTable === null) {
            return null;
        }

        $name = trim( (string) $cityName );
        if ($name === '') {
            return null;
        }

        $slug = Str::slug( $name );
        $cityQuery = DB::table( $cityTable );
        if ($this->tableHasColumn( $cityTable, 'pays_id' )) {
            $cityQuery->where( 'pays_id', $countryId );
        }
        $cityQuery->where( function ( $q ) use ( $cityTable, $slug, $name ) {
            if ($this->tableHasColumn( $cityTable, 'slug' )) {
                $q->where( 'slug', $slug )->orWhere( 'name', $name );
            } else {
                $q->where( 'name', $name );
            }
        } );
        $city = $cityQuery->first();

        if ($city) {
            return (int) $city->id;
        }

        $insertData = ['name' => $name];
        if ($this->tableHasColumn( $cityTable, 'slug' )) {
            $insertData['slug'] = $slug;
        }
        if ($this->tableHasColumn( $cityTable, 'pays_id' )) {
            $insertData['pays_id'] = $countryId;
        }
        if ($this->tableHasColumn( $cityTable, 'compteur_publications' )) {
            $insertData['compteur_publications'] = 0;
        }

        $insertData = $this->mergeTableTimestampsForInsert( $cityTable, $insertData );

        return (int) DB::table( $cityTable )->insertGetId( $insertData );
    }

    private function resolveRegionId( ?string $regionName, ?int $cityId ): ?int
    {
        $regionTable = $this->resolveExistingTable( ['regions_flink', 'regions'] );
        if ($regionTable === null) {
            return null;
        }

        $name = trim( (string) $regionName );
        if ($name === '' || !$cityId) {
            return null;
        }

        $slug = Str::slug( $name );
        $regionQuery = DB::table( $regionTable );
        if ($this->tableHasColumn( $regionTable, 'ville_id' )) {
            $regionQuery->where( 'ville_id', $cityId );
        }
        $regionQuery->where( function ( $q ) use ( $regionTable, $slug, $name ) {
            if ($this->tableHasColumn( $regionTable, 'slug' )) {
                $q->where( 'slug', $slug )->orWhere( 'name', $name );
            } else {
                $q->where( 'name', $name );
            }
        } );
        $region = $regionQuery->first();

        if ($region) {
            return (int) $region->id;
        }

        $insertData = ['name' => $name];
        if ($this->tableHasColumn( $regionTable, 'slug' )) {
            $insertData['slug'] = $slug;
        }
        if ($this->tableHasColumn( $regionTable, 'ville_id' )) {
            $insertData['ville_id'] = $cityId;
        }
        if ($this->tableHasColumn( $regionTable, 'compteur_publications' )) {
            $insertData['compteur_publications'] = 0;
        }

        $insertData = $this->mergeTableTimestampsForInsert( $regionTable, $insertData );

        return (int) DB::table( $regionTable )->insertGetId( $insertData );
    }

    private function postExistsForFirstCreationGuard( int|string $etabId, int|string $userId, string $description ): bool
    {
        return DB::table( 'publications_flink' )
            ->where( 'etab_id', $etabId )
            ->where( 'user_id', $userId )
            ->where( 'description', $description )
            ->exists();
    }

    private function normalizeDisponibilite( ?string $value ): ?string
    {
        $raw = trim( (string) $value );
        if ($raw === '') {
            return null;
        }

        $lower = strtolower( $raw );
        return match ( $lower ) {
            'vendue' => 'vendu',
            'instock' => 'inStock',
            default => $raw,
        };
    }

    private function normalizeDisponibiliteDate( mixed $value ): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim( (string) $value );
        if ($raw === '') {
            return null;
        }

        if (preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) === 1) {
            return $raw;
        }

        foreach (['!d/m/Y', '!m/d/Y', '!d-m-Y', '!m-d-Y', '!Y/m/d'] as $format) {
            $dt = \DateTime::createFromFormat( $format, $raw );
            if ($dt instanceof \DateTime) {
                return $dt->format( 'Y-m-d' );
            }
        }

        $timestamp = strtotime( $raw );
        if ($timestamp !== false) {
            return date( 'Y-m-d', $timestamp );
        }

        return null;
    }

    private function normalizeCatPostId( mixed $value ): ?int
    {
        $raw = trim( (string) $value );
        if ($raw === '') {
            return null;
        }

        if (!preg_match( '/^\d+$/', $raw )) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Build a catalogue-scoped cat_post_id so same CSV ids can exist
     * independently in different catalogues.
     */
    private function buildScopedCatPostId( int $catalogueId, int $rawCatPostId ): int
    {
        $hash = sprintf( '%u', crc32( "{$catalogueId}:{$rawCatPostId}" ) );
        return (int) $hash;
    }

    private function findExistingByCatPostIdAndDescription( int|string $etabId, int $catPostId, string $incomingDescription ): ?object
    {
        $normalizedIncoming = $this->normalizeDescriptionForDuplicateCheck( $incomingDescription );
        if ($normalizedIncoming === '') {
            return null;
        }

        $candidates = DB::table( 'publications_flink' )
            ->where( 'etab_id', $etabId )
            ->where( 'cat_post_id', $catPostId )
            ->get( ['id', 'description'] );

        foreach ($candidates as $candidate) {
            $normalizedExisting = $this->normalizeDescriptionForDuplicateCheck( (string) ( $candidate->description ?? '' ) );
            if ($normalizedExisting !== '' && $normalizedExisting === $normalizedIncoming) {
                return $candidate;
            }
        }

        return null;
    }

    private function findExistingByCatPostIdAndImageDefault( int|string $etabId, int $catPostId, string $incomingImageDefault ): ?object
    {
        if ($incomingImageDefault === '') {
            return null;
        }

        $imageColumn = $this->resolvePublicationColumn( [
            'image_default',
            'image',
            'image_principale',
            'image_url',
            'thumbnail',
        ] );

        if ($imageColumn === null) {
            return null;
        }

        $candidates = DB::table( 'publications_flink' )
            ->where( 'etab_id', $etabId )
            ->where( 'cat_post_id', $catPostId )
            ->get( ['id', 'description', $imageColumn] );

        foreach ($candidates as $candidate) {
            $candidateImage = $this->normalizeImageUrlForDuplicateCheck( (string) ( $candidate->{$imageColumn} ?? '' ) );
            if ($candidateImage !== '' && $candidateImage === $incomingImageDefault) {
                return $candidate;
            }
        }

        return null;
    }

    private function findExistingByCatPostIdAndImagesList( int|string $etabId, int $catPostId, string $incomingImagesList ): ?object
    {
        if ($incomingImagesList === '') {
            return null;
        }

        $imagesColumn = $this->resolvePublicationColumn( [
            'images',
            'images_list',
            'gallery',
            'gallery_images',
        ] );

        if ($imagesColumn === null) {
            return null;
        }

        $candidates = DB::table( 'publications_flink' )
            ->where( 'etab_id', $etabId )
            ->where( 'cat_post_id', $catPostId )
            ->get( ['id', 'description', $imagesColumn] );

        foreach ($candidates as $candidate) {
            $candidateList = $this->normalizeImagesListForDuplicateCheck( (string) ( $candidate->{$imagesColumn} ?? '' ), null );
            if ($candidateList !== '' && $candidateList === $incomingImagesList) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeDescriptionForDuplicateCheck( string $description ): string
    {
        $normalized = trim( Str::lower( $description ) );
        // Avoid false negatives caused by spacing/newline differences.
        $normalized = preg_replace( '/\s+/', ' ', $normalized ) ?? $normalized;
        return $normalized;
    }

    /**
     * Normalize price values for comparison to handle formatting differences
     * like "1001.00" vs "1001" which are the same numeric value.
     * Accepts float or string from DB/CSV.
     */
    private function normalizePriceForComparison( float|string|null $price ): ?float
    {
        if ($price === null || $price === '') {
            return null;
        }
        $floatVal = is_float( $price ) ? $price : (float) $price;
        // Round to 2 decimal places to normalize "1001.00" and "1001"
        return round( $floatVal, 2 );
    }

    private function normalizeImageUrlForDuplicateCheck( ?string $url ): string
    {
        $raw = trim( (string) $url );
        if ($raw === '') {
            return '';
        }

        // Convert URL to deterministic filename format for comparison
        // This ensures same URL always generates same comparison value
        return $this->convertUrlToFilename( $raw );
    }

    private function normalizeImagesListForDuplicateCheck( ?string $images, ?string $mainImage ): string
    {
        $urls = [];
        $main = $this->normalizeImageUrlForDuplicateCheck( $mainImage );
        if ($main !== '') {
            $urls[] = $main;
        }

        foreach (array_map( 'trim', preg_split( '/[|,]/', (string) $images ) ) as $url) {
            $normalized = $this->normalizeImageUrlForDuplicateCheck( $url );
            if ($normalized !== '') {
                $urls[] = $normalized;
            }
        }

        if (empty( $urls )) {
            return '';
        }

        $urls = array_values( array_unique( $urls ) );
        sort( $urls );

        return implode( '|', $urls );
    }

    private function buildDuplicateKey( int|string $etabId, int $catPostId, string $value ): ?string
    {
        if ($value === '') {
            return null;
        }

        return "{$etabId}|{$catPostId}|{$value}";
    }

    private function resolvePublicationColumn( array $candidates ): ?string
    {
        foreach ($candidates as $column) {
            if ($this->tableHasColumn( 'publications_flink', $column )) {
                return $column;
            }
        }

        return null;
    }

    private function detectLikelyDuplicateImport( array $rows, int|string $etabId ): ?string
    {
        $firstRows = array_slice( $rows, 0, 3 );
        if (empty( $firstRows )) {
            return null;
        }

        $pairs = [];
        foreach ($firstRows as $row) {
            $catPostId = $this->normalizeCatPostId( $row['cat_post_id'] ?? null );
            $description = trim( (string) ( $row['description'] ?? '' ) );
            if ($catPostId === null || $description === '') {
                continue;
            }

            $pairs[] = ['cat_post_id' => $catPostId, 'description' => $description];
        }

        if (count( $pairs ) < 2) {
            return null;
        }

        $matched = 0;
        foreach ($pairs as $pair) {
            $exists = $this->findExistingByCatPostIdAndDescription(
                $etabId,
                (int) $pair['cat_post_id'],
                (string) $pair['description']
            );

            if ($exists) {
                $matched++;
            }
        }

        if ($matched >= min( 3, count( $pairs ) )) {
            return 'Les premiers posts (id + titre) semblent déjà importés pour cet établissement. Vérifiez le Flink Shop cible ou utilisez un autre fichier.';
        }

        return null;
    }

    private function resolveExistingTable( array $candidates ): ?string
    {
        foreach ($candidates as $table) {
            if (Schema::hasTable( $table )) {
                return $table;
            }
        }

        return null;
    }

    private function tableHasColumn( string $table, string $column ): bool
    {
        return Schema::hasTable( $table ) && Schema::hasColumn( $table, $column );
    }

    /**
     * MYSQL strict migrations often define created_at/updated_at NOT NULL without DEFAULT.
     */
    /**
     * Generate a synchronized timestamp for all DB operations in a single request.
     * This ensures created_at, updated_at, deleted_at are identical.
     */
    private function getSynchronizedTimestamp(): \Illuminate\Support\Carbon
    {
        // Use a cached timestamp for the duration of this request/job
        static $syncedTime = null;
        if ($syncedTime === null) {
            $syncedTime = now();
        }
        return $syncedTime;
    }

    private function mergeTableTimestampsForInsert( string $table, array $insertData ): array
    {
        $syncedNow = $this->getSynchronizedTimestamp();
        if ($this->tableHasColumn( $table, 'created_at' )) {
            $insertData['created_at'] = $syncedNow;
        }
        if ($this->tableHasColumn( $table, 'updated_at' )) {
            $insertData['updated_at'] = $syncedNow;
        }

        return $insertData;
    }

    /**
     * Add updated_at on updates when table has timestamp columns.
     * Uses synchronized timestamp to match created_at if in same operation.
     */
    private function mergeTableTimestampsForUpdate( string $table, array $updateData ): array
    {
        if ($this->tableHasColumn( $table, 'updated_at' )) {
            $updateData['updated_at'] = $this->getSynchronizedTimestamp();
        }
        return $updateData;
    }

    /**
     * Generate fast content fingerprint for comparison.
     * Combines all significant fields into single hash for O(1) comparison.
     */
    private function generateContentFingerprint( array $row, object $existing = null ): string
    {
        // Normalize values for consistent hashing
        // Tags are included so a row where only tags changed is not falsely skipped
        // as "no change" when compared against the existing fingerprint (which includes tagSig).
        $tagsString = $row['tags'] ?? '';
        $tagNamesForFp = array_values( array_unique( array_filter(
            array_map( 'trim', preg_split( '/[|,<>]/', $tagsString ) )
        ) ) );
        $resolvedTagIdsForFp = $this->resolveTagIds( $tagNamesForFp );
        sort( $resolvedTagIdsForFp );
        $tagSig = implode( ',', $resolvedTagIdsForFp );

        $data = [
            'desc' => $this->normalizeDescriptionForDuplicateCheck( (string) ( $row['description'] ?? '' ) ),
            'prix' => $this->normalizePriceForComparison( $row['price'] ?? $row['prix'] ?? null ),
            'promo' => $this->normalizePriceForComparison( $row['promo_price'] ?? $row['prixPromo'] ?? null ),
            'img' => $this->normalizeImageUrlForDuplicateCheck( $row['image_default'] ?? null ),
            'imgs' => $this->normalizeImagesListForDuplicateCheck( $row['images'] ?? null, $row['image_default'] ?? null ),
            'ville' => (int) ( $row['city_id'] ?? $row['ville_id'] ?? 0 ),
            'pays' => (int) ( $row['country_id'] ?? $row['pays_id'] ?? 0 ),
            'cat' => (int) ( $row['cat_g_id'] ?? $row['category_id'] ?? 0 ),
            'tele' => preg_replace( '/[^0-9]/', '', (string) ( $row['phone'] ?? $row['tele'] ?? '' ) ),
            'disp' => $this->normalizeDisponibilite( $row['disponibilite'] ?? $row['availability'] ?? null ),
            'tags' => $tagSig, // ← ensures tag-only changes trigger an update, not a skip
        ];

        return sha1( json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * Detect which fields changed (only call if fingerprints differ).
     * Returns array with 'critical' and 'cosmetic' changes for status decision.
     */
    private function detectFieldChanges( object $existing, array $row ): array
    {
        $changes = ['critical' => [], 'cosmetic' => []];

        // Critical fields affecting post status
        $criticalFields = [
            'prix' => ['price', 'prix', 'prixPromo', 'promo_price'],
            'disponibilite' => ['disponibilite', 'availability'],
        ];

        foreach ($criticalFields as $field => $aliases) {
            $existingVal = $existing->$field ?? null;
            $newVal = null;
            foreach ($aliases as $alias) {
                if (isset( $row[$alias] )) {
                    $newVal = $row[$alias];
                    break;
                }
            }

            // Normalize for comparison
            if ($field === 'prix') {
                $existingVal = $this->normalizePriceForComparison( $existingVal );
                $newVal = $this->normalizePriceForComparison( $newVal );
            } elseif ($field === 'disponibilite') {
                $existingVal = $this->normalizeDisponibilite( $existingVal );
                $newVal = $this->normalizeDisponibilite( $newVal );
            }

            if ($existingVal !== $newVal) {
                $changes['critical'][] = $field;
            }
        }

        // Cosmetic fields (don't affect status)
        $cosmeticFields = [
            'description' => ['description', 'desc'],
            'images' => ['image_default', 'images'],
        ];

        foreach ($cosmeticFields as $field => $aliases) {
            $existingVal = $existing->$field ?? '';
            $newVal = '';
            foreach ($aliases as $alias) {
                if (isset( $row[$alias] )) {
                    $newVal = $row[$alias];
                    break;
                }
            }

            if ($field === 'description') {
                if ($this->normalizeDescriptionForDuplicateCheck( (string) $existingVal ) !==
                    $this->normalizeDescriptionForDuplicateCheck( (string) $newVal )) {
                    $changes['cosmetic'][] = $field;
                }
            } elseif ($field === 'images') {
                $existingImg = $this->normalizeImageUrlForDuplicateCheck( $existingVal );
                $newImg = $this->normalizeImageUrlForDuplicateCheck( $newVal );
                if ($existingImg !== $newImg) {
                    $changes['cosmetic'][] = $field;
                }
            }
        }

        return $changes;
    }

    private function getEtabSlotsMax( int $etabId ): int
    {
        if (!Schema::hasTable( 'etablissements' ) || !Schema::hasColumn( 'etablissements', 'flink_shop_slots_max' )) {
            return self::DEFAULT_FLINK_SHOP_SLOTS_MAX;
        }

        $max = (int) DB::table( 'etablissements' )
            ->where( 'id', $etabId )
            ->value( 'flink_shop_slots_max' );

        return $max > 0 ? $max : self::DEFAULT_FLINK_SHOP_SLOTS_MAX;
    }

    /**
     * Re-process incoming CSV image URLs to stored WebP files + square image.
     * Returns DB-ready values for main image, images list (json), and square.
     */
    private function processCatalogueRowImagesToWebp( array $row, int $catalogueId, ?string $postCreatedAt = null ): ?array
    {
        $mainUrl = trim( (string) ( $row['image_default'] ?? '' ) );
        $galleryUrls = !empty( $row['images'] )
            ? array_values( array_filter( array_map( 'trim', preg_split( '/[|,]/', (string) $row['images'] ) ) ) )
            : [];

        if ($mainUrl === '' && !empty( $galleryUrls )) {
            $mainUrl = $galleryUrls[0];
        }
        if ($mainUrl === '' || !filter_var( $mainUrl, FILTER_VALIDATE_URL )) {
            return null;
        }

        // Use year-based folder structure as requested: publication/images/2026/168/
        $currentYear = date('Y');
        $mainDir = "publication/images/{$currentYear}/{$catalogueId}";
        $squareDir = "publication/images/{$currentYear}/{$catalogueId}/square";
        $galleryDir = "publication/images/{$currentYear}/{$catalogueId}";

        // Use URL-based deterministic naming
        $mainFilename = $this->convertUrlToFilename($mainUrl) . '.webp';
        
        // Main image
        $existingMainPath = "{$mainDir}/{$mainFilename}";
        
        $mainUploaded = $this->urlToUploadedFile( $mainUrl );
        if (!$mainUploaded) {
            return null;
        }

        if (Storage::disk('ftp')->exists($existingMainPath)) {
            $mainPath = $existingMainPath;
        } else {
            $mainPath = $mainUploaded->storeAs( $mainDir, $mainFilename, 'ftp' );
            if (!$mainPath) {
                return null;
            }
        }

        $squarePath = null;
        
        // Square image - same base name with -square suffix
        $squareFilename = $this->convertUrlToFilename($mainUrl) . '-square.webp';
        $existingSquarePath = "{$squareDir}/{$squareFilename}";
        
        if (Storage::disk('ftp')->exists($existingSquarePath)) {
            $squarePath = $existingSquarePath;
        } else {
            $tmpSquarePath = tempnam( sys_get_temp_dir(), 'flink_sq_' ) . '.webp';
            try {
                $this->createSquareWebp( $mainUploaded->getRealPath(), $tmpSquarePath );
                $squarePath = "{$squareDir}/{$squareFilename}";
                Storage::disk( 'ftp' )->put( $squarePath, file_get_contents( $tmpSquarePath ) );
            } catch (\Throwable $e) {
                $squarePath = null;
            } finally {
                if (file_exists( $tmpSquarePath )) {
                    @unlink( $tmpSquarePath );
                }
            }
        }

        $mainFileKey = $this->convertUrlToFilename( $mainUrl );
        $galleryPaths = [];

        foreach ($galleryUrls as $url) {
            if (!filter_var( $url, FILTER_VALIDATE_URL )) {
                continue;
            }

            // Same source as main → reuse exact FTP path (no second download / WebP file).
            if ($this->catalogueImageSourcesAreSame( $url, $mainUrl )
                || $this->convertUrlToFilename( $url ) === $mainFileKey) {
                $this->appendCatalogueGalleryPath( $galleryPaths, $mainPath );
                continue;
            }

            $galleryFilename = $this->convertUrlToFilename( $url ) . '.webp';
            $existingGalleryPath = "{$galleryDir}/{$galleryFilename}";

            // Already converted as main image (same deterministic name in catalogue folder).
            if ($galleryFilename === $mainFilename && !empty( $mainPath )) {
                $this->appendCatalogueGalleryPath( $galleryPaths, $mainPath );
                continue;
            }

            if (Storage::disk( 'ftp' )->exists( $existingGalleryPath )) {
                $this->appendCatalogueGalleryPath( $galleryPaths, $existingGalleryPath );
            } else {
                $galleryUploaded = $this->urlToUploadedFile( $url );
                if (!$galleryUploaded) {
                    continue;
                }
                $stored = $galleryUploaded->storeAs( $galleryDir, $galleryFilename, 'ftp' );
                if ($stored) {
                    $this->appendCatalogueGalleryPath( $galleryPaths, $stored );
                }
            }

            if (count( $galleryPaths ) >= 8) {
                break;
            }
        }

        // Single image (only image_default, or one URL in images): same path as image_default.
        if (empty( $galleryPaths ) && !empty( $mainPath )) {
            $galleryPaths = [$mainPath];
        }

        return [
            'main' => $mainPath,
            'square' => $squarePath,
            'images' => $this->encodeCatalogueGalleryPaths( $galleryPaths ),
        ];
    }

    /** JSON array of gallery paths for publications_flink.images (never null when main exists). */
    private function encodeCatalogueGalleryPaths( array $galleryPaths ): ?string
    {
        if (empty( $galleryPaths )) {
            return null;
        }

        return json_encode( array_values( $galleryPaths ) );
    }

    /**
     * True when two source URLs point to the same image (one FTP file / one WebP name).
     */
    private function catalogueImageSourcesAreSame( string $urlA, string $urlB ): bool
    {
        if ($urlA === $urlB) {
            return true;
        }

        $normalizedA = $this->normalizeCatalogueImageSourceUrl( $urlA );
        $normalizedB = $this->normalizeCatalogueImageSourceUrl( $urlB );
        if ($normalizedA !== '' && $normalizedA === $normalizedB) {
            return true;
        }

        return $this->convertUrlToFilename( $urlA ) === $this->convertUrlToFilename( $urlB );
    }

    private function normalizeCatalogueImageSourceUrl( string $url ): string
    {
        $url = trim( $url );
        if ($url === '') {
            return '';
        }

        $parts = parse_url( $url );
        if (!is_array( $parts )) {
            return strtolower( $url );
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
        $query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

        return "{$scheme}://{$host}{$path}{$query}";
    }

    private function appendCatalogueGalleryPath( array &$galleryPaths, ?string $path ): void
    {
        if ($path === null || $path === '') {
            return;
        }
        if (!in_array( $path, $galleryPaths, true )) {
            $galleryPaths[] = $path;
        }
    }

    /**
     * Convert URL to deterministic filename
     * Example: https://autoreda.ma/wp-content/uploads/2025/03/12-2-1.jpg
     * Becomes: autoreda-ma-wp-content-uploads-2025-03-12-2-1-jpg.webp
     */
    private function convertUrlToFilename(string $url): string
    {
        // Remove protocol and clean the URL
        $cleanUrl = preg_replace('/^https?:\/\//', '', $url);
        
        // Replace all special characters (/, \, ., &, ?, =, etc) with dashes
        $filename = preg_replace('/[\/\\.\\\\&?=]/', '-', $cleanUrl);
        
        // Remove multiple consecutive dashes
        $filename = preg_replace('/-+/', '-', $filename);
        
        // Remove leading/trailing dashes
        $filename = trim($filename, '-');
        
        // Convert to lowercase for consistency
        $filename = strtolower($filename);
        
        return $filename;
    }

    /**
     * Apply URL-based deterministic naming to existing publication
     * This ensures consistency between creation and update/refresh operations
     */
    private function applyUrlBasedNamingToExistingPublication(
        array $row,
        int $publicationId,
        int $catalogueId,
        ?string $postCreatedAt = null,
        ?array $processedImages = null
    ): void {
        try {
            // Reuse conversion from import when provided — one FTP file, same path in image_default + images.
            $processedImages = $processedImages ?? $this->processCatalogueRowImagesToWebp( $row, $catalogueId, $postCreatedAt );
            
            if ($processedImages !== null) {
                $publicationTable = $this->resolveExistingTable(['publications_flink', 'publications']);
                if ($publicationTable === null) {
                    return;
                }

                // Update publication with new image paths
                $updateData = [];
                
                // Get column names
                $mainImageColumn = $this->resolvePublicationColumn([
                    'image_default',
                    'image',
                    'image_principale',
                    'image_url',
                    'thumbnail',
                ]);
                
                $imagesColumn = $this->resolvePublicationColumn([
                    'images',
                    'gallery',
                    'gallerie',
                ]);
                
                $squareImageColumn = $this->resolvePublicationColumn([
                    'image_square',
                    'square_image',
                    'thumbnail_square',
                ]);

                // Set main image
                if ($mainImageColumn !== null && !empty($processedImages['main'])) {
                    $updateData[$mainImageColumn] = $processedImages['main'];
                }

                // Set gallery images
                if ($imagesColumn !== null && !empty($processedImages['images'])) {
                    $updateData[$imagesColumn] = $processedImages['images'];
                }

                // Set square image
                if ($squareImageColumn !== null && !empty($processedImages['square'])) {
                    $updateData[$squareImageColumn] = $processedImages['square'];
                }

                // Update the publication if we have changes
                if (!empty($updateData)) {
                    DB::table($publicationTable)
                        ->where('id', $publicationId)
                        ->update($updateData);
                    
                    $this->logInfo("Applied URL-based naming to publication {$publicationId}", [
                        'catalogue_id' => $catalogueId,
                        'updated_fields' => array_keys($updateData)
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logWarning("Failed to apply URL-based naming to publication {$publicationId}", [
                'error' => $e->getMessage(),
                'catalogue_id' => $catalogueId
            ]);
        }
    }

    private function createSquareWebp( string $sourcePath, string $targetPath ): void
    {
        if (!function_exists( 'imagecreatefromwebp' ) || !function_exists( 'imagewebp' )) {
            throw new \RuntimeException( 'GD WebP functions are not available.' );
        }
        $img = @imagecreatefromwebp( $sourcePath );
        if (!$img) {
            throw new \RuntimeException( 'Unable to decode source webp image.' );
        }
        try {
            $width = imagesx( $img );
            $height = imagesy( $img );
            $size = min( $width, $height );
            $srcX = (int) floor( ( $width - $size ) / 2 );
            $srcY = (int) floor( ( $height - $size ) / 2 );
            $square = imagecreatetruecolor( $size, $size );
            imagealphablending( $square, false );
            imagesavealpha( $square, true );
            imagecopyresampled( $square, $img, 0, 0, $srcX, $srcY, $size, $size, $size, $size );
            imagewebp( $square, $targetPath, 90 );
            imagedestroy( $square );
        } finally {
            imagedestroy( $img );
        }
    }

    private function storeWithRetry( CreatePublicationController $createCtrl, Request $request, int $rowNum, int $catalogueId = 0 )
    {
        $attempt = 1;
        $lastResponse = null;

        while ($attempt <= self::STORE_MAX_RETRIES) {
            $response = $createCtrl->store( $request );
            $lastResponse = $response;

            $content = (string) $response->getContent();
            $isDeadlock = str_contains( $content, 'Deadlock found when trying to get lock' )
                || str_contains( $content, 'SQLSTATE[40001]' );

            if (!$isDeadlock) {
                return $response;
            }

            if ($attempt < self::STORE_MAX_RETRIES) {
                if ($catalogueId > 0 && $this->shouldStopProcessing($catalogueId)) {
                    return response()->json(['message' => 'Cancelled'], 499);
                }
                $delayMs = 150 * $attempt;
                $this->logWarning( "Catalogue row {$rowNum}: deadlock detected, retrying store()", [
                    'attempt' => $attempt,
                    'retry_in_ms' => $delayMs,
                ] );
                usleep( $delayMs * 1000 );
            }

            $attempt++;
        }

        return $lastResponse;
    }

    /**
     * Link a freshly created publication to a catalogue safely.
     * If linkage fails (typically unique cat_post_id conflict), delete the orphan row.
     */
    private function linkPublicationToCatalogue( int $pubId, int $catalogueId, ?int $catPostId, int $etabId, int $rowNum ): bool
    {
        try {
            if ($catPostId !== null) {
                $conflictExists = DB::table( 'publications_flink' )
                    ->where( 'etab_id', $etabId )
                    ->where( 'cat_post_id', $catPostId )
                    ->where( 'id', '!=', $pubId )
                    ->exists();
                if ($conflictExists) {
                    // Prevent leaving duplicate orphan post outside catalogue.
                    DB::table( 'publications_flink' )->where( 'id', $pubId )->delete();
                    return false;
                }
            }

            $updateData = [
                'catalogue_id' => $catalogueId,
                'is_marketplace' => '1',
            ];
            if ($catPostId !== null) {
                $updateData['cat_post_id'] = $catPostId;
            }

            $updated = DB::table( 'publications_flink' )
                ->where( 'id', $pubId )
                ->update( $updateData );

            if ($updated <= 0) {
                DB::table( 'publications_flink' )->where( 'id', $pubId )->delete();
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logWarning( "Catalogue row {$rowNum}: failed linking publication to catalogue", [
                'publication_id' => $pubId,
                'catalogue_id' => $catalogueId,
                'cat_post_id' => $catPostId,
                'error' => $e->getMessage(),
            ] );
            // Best effort cleanup so we don't keep orphan duplicates.
            try {
                DB::table( 'publications_flink' )->where( 'id', $pubId )->delete();
            } catch (\Throwable $cleanupError) {
                $this->logWarning( "Catalogue row {$rowNum}: orphan cleanup failed", [
                    'publication_id' => $pubId,
                    'error' => $cleanupError->getMessage(),
                ] );
            }
            return false;
        }
    }

    private function logInfo( string $message, array $context = [] ): void
    {
        $this->catalogueLogger->info($message, $context);
    }

    private function logWarning( string $message, array $context = [] ): void
    {
        $this->catalogueLogger->warning($message, $context);
    }

    private function logError( string $message, array $context = [] ): void
    {
        $this->catalogueLogger->error($message, $context);
    }

    /**
     * SSE (Server-Sent Events) endpoint for real-time catalogue progress.
     * Streams updates as each post is processed: 1/47, 2/47, 3/47...
     * One persistent HTTP connection replaces thousands of polling requests.
     */
    public function streamProgress( Request $request, int $catalogueId )
    {
        // SSE configuration - must be set before any output
        ignore_user_abort( true );
        if (function_exists( 'set_time_limit' )) {
            @set_time_limit( 0 );
        }
        @ini_set( 'output_buffering', 'off' );
        @ini_set( 'zlib.output_compression', false );
        // Don't clear all buffers, just disable implicit flush
        ob_implicit_flush( true );

        // Set SSE headers immediately
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'X-Accel-Buffering: no' );
        header( 'Connection: keep-alive' );

        // Initialize SSE logger
        $sseLogPath = storage_path( 'logs/SSE.log' );
        $sseLogger = Log::build( [
            'driver' => 'single',
            'path' => $sseLogPath,
            'level' => 'debug',
        ] );

        try {
            $sseLogger->info( '[SSE] Connection attempt', [
                'catalogue_id' => $catalogueId,
                'timestamp' => now()->toIso8601String(),
            ] );

            // Manual token validation (EventSource can't send Authorization header)
            $token = $request->query( 'token' );
            if (!$token ) {
                $sseLogger->error( '[SSE] Missing token', ['catalogue_id' => $catalogueId] );
                header( 'Content-Type: text/event-stream' );
                echo "event: error\n";
                echo "data: " . json_encode( ['type' => 'error', 'message' => 'Missing token'] ) . "\n\n";
                flush();
                exit;
            }

            // Validate token against personal_access_tokens
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken( $token );
            if (!$accessToken || !$accessToken->tokenable ) {
                $sseLogger->error( '[SSE] Invalid token', ['catalogue_id' => $catalogueId] );
                header( 'Content-Type: text/event-stream' );
                echo "event: error\n";
                echo "data: " . json_encode( ['type' => 'error', 'message' => 'Invalid token'] ) . "\n\n";
                flush();
                exit;
            }

            // Check token expiration (if expires_at is set)
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                $sseLogger->error( '[SSE] Token expired', ['catalogue_id' => $catalogueId] );
                header( 'Content-Type: text/event-stream' );
                echo "event: error\n";
                echo "data: " . json_encode( ['type' => 'error', 'message' => 'Token expired'] ) . "\n\n";
                flush();
                exit;
            }

            $user = $accessToken->tokenable;
            $sseLogger->info( '[SSE] Token validated', [
                'catalogue_id' => $catalogueId,
                'user_id' => $user->id ?? null,
            ] );

            // Get correct table name
            $tableName = $this->getCatalogueTableName();
            $sseLogger->info( '[SSE] Using table', ['table' => $tableName] );

            // Verify catalogue ownership - skip etab_id check if user doesn't have one
            $catalogueQuery = DB::table( $tableName )->where( 'id', $catalogueId );
            if (!empty( $user->etab_id )) {
                $catalogueQuery->where( 'etab_id', $user->etab_id );
            }
            $catalogueCheck = $catalogueQuery->first();

            if (!$catalogueCheck ) {
                $sseLogger->error( '[SSE] Catalogue not found or access denied', [
                    'catalogue_id' => $catalogueId,
                    'user_etab_id' => $user->etab_id ?? 0,
                ] );
                header( 'Content-Type: text/event-stream' );
                echo "event: error\n";
                echo "data: " . json_encode( ['type' => 'error', 'message' => 'Catalogue not found or access denied'] ) . "\n\n";
                flush();
                exit;
            }

            $sseLogger->info( '[SSE] Catalogue ownership verified', ['catalogue_id' => $catalogueId] );

            // Disable output buffering for real-time streaming
            if (function_exists( 'apache_setenv' )) {
                apache_setenv( 'no-gzip', '1' );
            }
            ini_set( 'output_buffering', '0' );
            ini_set( 'zlib.output_compression', '0' );

            header( 'Content-Type: text/event-stream' );
            header( 'Cache-Control: no-cache' );
            header( 'Connection: keep-alive' );
            header( 'X-Accel-Buffering: no' ); // Disable Nginx buffering

            $startTime = time();
            $maxExecutionTime = 300; // 5 minutes max (adjust as needed)
            $lastSentCount = -1;
            $heartbeatInterval = 15; // Send heartbeat every 15 seconds to keep connection alive
            $lastHeartbeat = time();

            // Initial connection acknowledgment
            echo "event: connected\n";
            echo "data: " . json_encode( [
                'type' => 'connected',
                'catalogue_id' => $catalogueId,
                'timestamp' => now()->toIso8601String(),
            ] ) . "\n\n";
            @ob_flush();
            flush();

            $sseLogger->info( '[SSE] Connected event sent', ['catalogue_id' => $catalogueId] );

            while (true) {
                // Check for timeout
                if (time() - $startTime > $maxExecutionTime) {
                    $sseLogger->warning( '[SSE] Stream timeout', ['catalogue_id' => $catalogueId] );
                    echo "event: timeout\n";
                    echo "data: " . json_encode( [
                        'type' => 'timeout',
                        'message' => 'Stream timeout - please reconnect',
                    ] ) . "\n\n";
                    break;
                }

                // Check if client disconnected
                if (connection_aborted()) {
                    $sseLogger->info( '[SSE] Client disconnected', ['catalogue_id' => $catalogueId] );
                    break;
                }

                // Get current catalogue progress - use correct column names
                $catalogue = DB::table( $tableName )
                    ->where( 'id', $catalogueId )
                    ->first( ['processing_current', 'processing_total', 'processing_state'] );

                if (!$catalogue) {
                    $sseLogger->error( '[SSE] Catalogue not found during loop', ['catalogue_id' => $catalogueId] );
                    echo "event: error\n";
                    echo "data: " . json_encode( [
                        'type' => 'error',
                        'message' => 'Catalogue not found',
                    ] ) . "\n\n";
                    break;
                }

                $currentCount = (int) ($catalogue->processing_current ?? 0);
                $totalCount = (int) ($catalogue->processing_total ?? 0);
                $state = $catalogue->processing_state ?? 'unknown';

                // Send update only if count changed OR state changed
                if ($currentCount !== $lastSentCount || $state === 'finished' || $state === 'failed' || $state === 'cancelled') {
                    $lastSentCount = $currentCount;

                    $payload = [
                        'type' => $state === 'finished' ? 'complete' : ($state === 'failed' ? 'error' : 'progress'),
                        'catalogue_id' => $catalogueId,
                        'processed' => $currentCount,
                        'total' => $totalCount,
                        'percent' => $totalCount > 0 ? round( ($currentCount / $totalCount) * 100, 1 ) : 0,
                        'state' => $state,
                        'timestamp' => now()->toIso8601String(),
                    ];

                    echo "data: " . json_encode( $payload ) . "\n\n";
                    @ob_flush();
                    flush();

                    $sseLogger->info( '[SSE] Progress update sent', [
                        'catalogue_id' => $catalogueId,
                        'processed' => $currentCount,
                        'total' => $totalCount,
                        'state' => $state,
                    ] );

                    // If completed, failed, or cancelled, close the stream
                    if (in_array( $state, ['finished', 'failed', 'cancelled'] )) {
                        $sseLogger->info( '[SSE] Processing complete, closing stream', [
                            'catalogue_id' => $catalogueId,
                            'final_state' => $state,
                        ] );
                        break;
                    }
                }

                // Send heartbeat to keep connection alive
                if (time() - $lastHeartbeat >= $heartbeatInterval) {
                    $lastHeartbeat = time();
                    echo "event: heartbeat\n";
                    echo "data: " . json_encode( [
                        'type' => 'heartbeat',
                        'timestamp' => now()->toIso8601String(),
                    ] ) . "\n\n";
                    @ob_flush();
                    flush();
                    $sseLogger->debug( '[SSE] Heartbeat sent', ['catalogue_id' => $catalogueId] );
                }

                // Non-blocking sleep - check every 100ms
                usleep( 100000 ); // 100ms
            }

            // Clean close
            echo "event: close\n";
            echo "data: " . json_encode( ['type' => 'close'] ) . "\n\n";
            @ob_flush();
            flush();

            $sseLogger->info( '[SSE] Connection closed', ['catalogue_id' => $catalogueId] );
        } catch (\Throwable $e) {
            $sseLogger->error( '[SSE] Exception occurred', [
                'catalogue_id' => $catalogueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );

            header( 'Content-Type: text/event-stream' );
            echo "event: error\n";
            echo "data: " . json_encode( [
                'type' => 'error',
                'message' => 'Server error: ' . $e->getMessage(),
            ] ) . "\n\n";
            flush();
        }
    }
}
