<?php

namespace App\Console\Commands;

use App\Jobs\RefreshCatalogueJob;
use App\Models\CatalogueFlink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DispatchDueCatalogueRefreshes extends Command
{
    protected $signature = 'catalogues:dispatch-refreshes {--limit=10}';

    protected $description = 'Dispatch queued refresh jobs for due Flink catalogues.';

    public function handle(): int
    {
        $limit = max( 1, min( 50, (int) $this->option( 'limit' ) ) );
        $now = now();

        $catalogues = CatalogueFlink::query()
            ->whereNotNull( 'refresh_interval' )
            ->whereNotNull( 'refresh_time' )
            ->whereNotNull( 'next_update_at' )
            ->where( 'next_update_at', '<=', $now )
            ->orderBy( 'next_update_at' )
            ->limit( $limit )
            ->get();

        $dispatched = 0;
        foreach ($catalogues as $catalogue) {
            $sourceFile = trim( (string) ( $catalogue->source_file ?? '' ) );
            if ($sourceFile === '' || !filter_var( $sourceFile, FILTER_VALIDATE_URL )) {
                continue;
            }
            $parts = parse_url( $sourceFile );
            $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
            $host = strtolower( (string) ( $parts['host'] ?? '' ) );
            if (!in_array( $scheme, ['http', 'https'], true ) || $host === '' || in_array( $host, ['localhost', '127.0.0.1', '::1'], true )) {
                continue;
            }

            $claimKey = RefreshCatalogueJob::dispatchClaimKey( (int) $catalogue->id );
            if (!Cache::add( $claimKey, now()->toDateTimeString(), now()->addHours( 2 ) )) {
                continue;
            }

            $jobSucceeded = false;
            try {
                // FIX: Use dispatchSync() instead of dispatch().
                //
                // dispatch() pushes the job onto the async queue — it only runs if a separate
                // queue worker process (php artisan queue:work) is running. On many shared/managed
                // hosts no persistent worker is configured, so the job sits in the queue forever
                // while next_update_at has already been advanced, silently skipping every window.
                //
                // dispatchSync() runs the job immediately inside this cron process, guaranteeing
                // the catalogue actually refreshes every time the scheduler fires — no queue
                // worker required.
                RefreshCatalogueJob::dispatchSync( (int) $catalogue->id );
                $dispatched++;
                $jobSucceeded = true;

            } catch ( \Throwable $exception ) {
                // Release the claim key immediately so the next cron cycle (next minute) can
                // retry this catalogue. Previously the key stayed for 2 hours even on failure,
                // causing up to a 2-hour gap before a retry was attempted.
                Cache::forget( $claimKey );
                Log::channel( 'stack' )->warning( 'Catalogue auto-refresh job failed — claim key released for retry.', [
                    'catalogue_id' => (int) $catalogue->id,
                    'message'      => $exception->getMessage(),
                ] );
            }

            // FIX: Only advance next_update_at AFTER the job has actually run successfully.
            //
            // Previously next_update_at was updated right after dispatch(), meaning an async job
            // that was never processed (no queue worker) still consumed its refresh window,
            // giving "0 created / 0 updated" for the entire period.
            if ($jobSucceeded) {
                try {
                    $interval = (string) $catalogue->refresh_interval;
                    $time     = (string) $catalogue->refresh_time;
                    [$hour, $minute] = array_map( 'intval', explode( ':', $time . ':00' ) );
                    $base    = now()->copy()->setTime( $hour, $minute, 0 );
                    $nowTime = now();
                    while ($base->lessThanOrEqualTo( $nowTime )) {
                        $base = match ( $interval ) {
                            'daily'         => $base->copy()->addDay(),
                            'every_2_days'  => $base->copy()->addDays( 2 ),
                            'every_3_days'  => $base->copy()->addDays( 3 ),
                            'weekly'        => $base->copy()->addWeek(),
                            'every_2_weeks' => $base->copy()->addWeeks( 2 ),
                            'monthly'       => $base->copy()->addMonth(),
                            'yearly'        => $base->copy()->addYear(),
                            default         => $base->copy()->addWeek(),
                        };
                    }
                    $catalogue->next_update_at = $base->toDateTimeString();
                    $catalogue->save();
                } catch ( \Throwable $saveEx ) {
                    Log::channel( 'stack' )->warning( 'Failed advancing next_update_at after successful catalogue refresh.', [
                        'catalogue_id' => (int) $catalogue->id,
                        'message'      => $saveEx->getMessage(),
                    ] );
                }
            }
        }

        Log::channel( 'stack' )->info( 'Catalogue auto refresh dispatcher completed.', [
            'due_count'  => $catalogues->count(),
            'dispatched' => $dispatched,
            'limit'      => $limit,
        ] );

        $this->info( "[" . now()->toDateTimeString() . "] Dispatched {$dispatched} catalogue refresh job(s)." );

        return self::SUCCESS;
    }
}
