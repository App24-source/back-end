<?php

namespace App\Jobs;

use App\Http\Controllers\Home\catalogue\CatalogueController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshCatalogueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct( public int $catalogueId )
    {
    }

    public static function dispatchClaimKey( int $catalogueId ): string
    {
        return "catalogue-refresh:claim:{$catalogueId}";
    }

    public static function runtimeLockKey( int $catalogueId ): string
    {
        return "catalogue-refresh:runtime:{$catalogueId}";
    }

    public function handle(): void
    {
        $runtimeLock = Cache::lock( self::runtimeLockKey( $this->catalogueId ), 3600 );
        if (!$runtimeLock->get()) {
            return;
        }

        try {
            $controller = app( CatalogueController::class );
            $result = $controller->runScheduledRefreshById( $this->catalogueId );

            Log::channel( 'stack' )->info( 'Catalogue refresh job finished.', [
                'catalogue_id' => $this->catalogueId,
                'result' => $result,
            ] );
            Log::channel( 'stack' )->info( sprintf(
                'CATALOGUE_REFRESH id=%d name="%s" refreshed=%s time=%s',
                (int) ( $result['catalogue_id'] ?? $this->catalogueId ),
                (string) ( $result['catalogue_name'] ?? '' ),
                !empty( $result['success'] ) ? 'yes' : 'no',
                (string) ( $result['refreshed_at'] ?? now()->toDateTimeString() )
            ) );
        } catch ( \Throwable $exception ) {
            app( CatalogueController::class )->markScheduledRefreshFailureById( $this->catalogueId, $exception->getMessage() );
            throw $exception;
        } finally {
            optional( $runtimeLock )->release();
            Cache::forget( self::dispatchClaimKey( $this->catalogueId ) );
        }
    }

    public function failed( \Throwable $exception ): void
    {
        Cache::forget( self::dispatchClaimKey( $this->catalogueId ) );
        Cache::forget( self::runtimeLockKey( $this->catalogueId ) );

        Log::channel( 'stack' )->error( 'Catalogue refresh job failed.', [
            'catalogue_id' => $this->catalogueId,
            'message' => $exception->getMessage(),
        ] );
    }
}
