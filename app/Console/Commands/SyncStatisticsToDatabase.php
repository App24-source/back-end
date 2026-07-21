<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Models\Statistique;
use App\Models\Publication;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\StatistiqueBooster;
use App\Models\PublicationBooster;

class SyncStatisticsToDatabase extends Command
{
/**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statistics:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync statistics from Redis to database every 10 minutes';

    /**
     * Execute the console command.
     */

    public function handle() {
        $this->info('Starting statistics sync at '.now());
        $keys      = Redis::keys('stat:*');
        $processed = 0;
        $Array     = [];

        foreach ($keys as $key) {
            $strippedKey = str_replace(config('database.redis.options.prefix'), '', $key);
            $parts = explode(':', $strippedKey);
            if (count($parts) !== 5) {
                continue;
            }

            $publicationId = $parts[1];
            $date          = $parts[2];
            $action        = $parts[3];
            $boostIds      = array_filter(explode(',', $parts[4]));
            $count         = (int) Redis::get($strippedKey);

            if ($count <= 0) {
                Redis::del($strippedKey);
                continue;
            }

            $publication = Publication::find($publicationId);
            if ($publication) {
                // Try to increment existing row for (publication_id + date)
                $affected = DB::table('statistiques_flink')
                    ->where('publication_id', $publicationId)
                    ->where('date', $date)
                    ->increment($action, $count, [
                        'updated_at' => now()
                    ]);

                // If no row exists, insert it
                if (!$affected) {
                    try {
                        DB::table('statistiques_flink')->insert([
                            'publication_id' => $publicationId,
                            'date'           => $date,
                            $action          => $count,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                    } catch (\Throwable $e) {
                        // Race condition: row was inserted in the meantime, so increment it
                        DB::table('statistiques_flink')
                            ->where('publication_id', $publicationId)
                            ->where('date', $date)
                            ->increment($action, $count, [
                                'updated_at' => now()
                            ]);
                    }
                }

                if (!empty($boostIds)) {
                    foreach ($boostIds as $boostId) {
                        if (PublicationBooster::find($boostId)) {
                            $boostStat = StatistiqueBooster::firstOrCreate(
                                ['boost_id' => $boostId, 'date' => $date]
                            );
                            $boostStat->increment('daily_impressions', 1);
                            $Array[] = [$boostId => $count];
                        }
                    }
                }

                $processed++;
            } else {
                $this->error('Publication not found: ' . $publicationId);
                Log::error('Publication not found', ['publication_id' => $publicationId]);
            }

            // Remove from Redis after syncing
            Redis::del($strippedKey);
        }

        $this->info('Processed ' . $processed . ' keys');
        $this->info('Boost Array: ' . json_encode($Array));
    }

    // public function handle()
    // {
    //     $startTime = microtime(true);
    //     $this->info('Starting statistics sync at '. now());
        
    //     try {
    //         $keys = Redis::keys('stats:*:*:*');
            
    //         if (empty($keys)) {
    //             $this->info('No statistics found in Redis.');
    //             return 0;
    //         }

    //         $processed = 0;
    //         $totalCount = 0;
    //         $errors = 0;

    //         foreach ($keys as $key) {
    //             try {
    //                 $cleanKey = str_replace(config('database.redis.options.prefix'), '', $key);
    //                 $parts = explode(':', $cleanKey);
                    
    //                 if (count($parts) < 4) {
    //                     Log::warning('Invalid key format: '.$cleanKey);
    //                     $errors++;
    //                     continue;
    //                 }

    //                 [$prefix, $date, $publicationId, $action] = $parts;
                    
    //                 // Validate date format
    //                 if (!Carbon::hasFormat($date, 'Y-m-d')) {
    //                     Log::warning('Invalid date format in key: '.$cleanKey);
    //                     $errors++;
    //                     continue;
    //                 }

    //                 $count = (int)Redis::get($cleanKey);
                    
    //                 if ($count <= 0) {
    //                     continue;
    //                 }

    //                 $stat = Statistique::firstOrCreate(
    //                     ['publication_id' => $publicationId, 'date' => $date]
    //                 );
                    
    //                 $stat->increment($action, $count);
    //                 Log::info("Successfully synced statistics to database");
    //                 $totalCount += $count;
    //                 $processed++;

    //                 if (!$this->option('keep-redis')) {
    //                     Redis::del($cleanKey);
    //                 }
    //             } catch (\Exception $e) {
    //                 Log::error('Error processing key '.$cleanKey.': '.$e->getMessage());
    //                 $errors++;
    //             }
    //         }

    //         $duration = round(microtime(true) - $startTime, 2);
            
    //         $this->info("Sync completed in {$duration}s");
    //         $this->info("Processed {$processed} keys with {$totalCount} total actions");
            
    //         if ($errors > 0) {
    //             $this->error("Encountered {$errors} errors (check logs for details)");
    //         }

    //         Log::info('Statistics sync completed', [
    //             'processed' => $processed,
    //             'total_actions' => $totalCount,
    //             'errors' => $errors,
    //             'duration' => $duration
    //         ]);

    //         return 0;
            
    //     } catch (\Exception $e) {
    //         Log::error('Statistics sync failed: '.$e->getMessage());
    //         $this->error('Sync failed: '.$e->getMessage());
    //         return 1;
    //     }
    // }

    // public function handle()
    // {
    //     $startTime = microtime(true);
    //     $this->info('Starting statistics sync at '.now());
        
    //     try {
    //         // Process statistics hashes (stores in statistique and clears Redis)
    //         $statsResult = $this->processStatisticsHashes();
            
    //         // Process recent clicks (stores in statistique and clears Redis)
    //         $clicksResult = $this->processRecentClicks();
            
    //         $duration = round(microtime(true) - $startTime, 2);
            
    //         // Summary output
    //         $this->info("Sync completed in {$duration}s");
    //         $this->info("Statistics: {$statsResult['processed']} keys processed, {$statsResult['actions']} actions stored");
    //         $this->info("Clicks: {$clicksResult['processed']} clicks processed, {$clicksResult['actions']} actions stored");
            
    //         // Error reporting
    //         if ($statsResult['errors'] > 0 || $clicksResult['errors'] > 0) {
    //             $this->error("Encountered errors - Stats: {$statsResult['errors']}, Clicks: {$clicksResult['errors']}");
    //         }
            
    //         // Clear Redis unless --keep-redis flag is set
    //         if (!$this->option('keep-redis')) {
    //             $this->info("Redis data cleared successfully");
    //         } else {
    //             $this->info("Redis data preserved (--keep-redis flag set)");
    //         }
            
    //         return 0;
            
    //     } catch (\Exception $e) {
    //         Log::error('Statistics sync failed: '.$e->getMessage());
    //         $this->error('Sync failed: '.$e->getMessage());
    //         return 1;
    //     }
    // }
    
    // protected function processStatisticsHashes(): array
    // {
    //     $cursor = 0;
    //     $processed = 0;
    //     $actions = 0;
    //     $errors = 0;
        
    //     do {
    //         // Scan Redis for statistics keys
    //         [$cursor, $keys] = Redis::scan($cursor, ['match' => 'statistics:*']);
            
    //         foreach ($keys as $key) {
    //             try {
    //                 $parts = explode(':', $key);
                    
    //                 // Validate key format
    //                 if (count($parts) < 3) {
    //                     Log::warning("Invalid statistics key: {$key}");
    //                     $errors++;
    //                     continue;
    //                 }
                    
    //                 $date = $parts[1];
    //                 $publicationId = $parts[2];
                    
    //                 // Validate date format
    //                 if (!Carbon::hasFormat($date, 'Y-m-d')) {
    //                     Log::warning("Invalid date in key: {$key}");
    //                     $errors++;
    //                     continue;
    //                 }
                    
    //                 // Process all actions for this key
    //                 $stats = Redis::hgetall($key);
    //                 foreach ($stats as $action => $count) {
    //                     $count = (int)$count;
    //                     if ($count <= 0) continue;
                        
    //                     // Update statistique table
    //                     $stat = Statistique::firstOrCreate(
    //                         ['publication_id' => $publicationId, 'date' => $date]
    //                     );
    //                     $stat->increment($action, $count);
    //                     $actions += $count;
    //                 }
                    
    //                 $processed++;
                    
    //                 // Clear Redis data unless --keep-redis is set
    //                 if (!$this->option('keep-redis')) {
    //                     Redis::del($key);
    //                 }
                    
    //             } catch (\Exception $e) {
    //                 Log::error("Error processing {$key}: ".$e->getMessage());
    //                 $errors++;
    //             }
    //         }
    //     } while ($cursor != 0);
        
    //     return compact('processed', 'actions', 'errors');
    // }
    
    // protected function processRecentClicks(): array
    // {
    //     $processed = 0;
    //     $actions = 0;
    //     $errors = 0;
        
    //     try {
    //         // Process all clicks until list is empty
    //         while ($clickData = Redis::rpop('recent_clicks')) {
    //             try {
    //                 $click = json_decode($clickData, true);
                    
    //                 // Validate required fields
    //                 if (!isset($click['publication_id'], $click['action'], $click['timestamp'])) {
    //                     Log::warning("Invalid click data: {$clickData}");
    //                     $errors++;
    //                     continue;
    //                 }
                    
    //                 // Prepare data for statistique table
    //                 $date = Carbon::createFromTimestamp($click['timestamp'])->format('Y-m-d');
    //                 $publicationId = $click['publication_id'];
    //                 $action = $click['action'];
                    
    //                 // Store in statistique table
    //                 $stat = Statistique::firstOrCreate(
    //                     ['publication_id' => $publicationId, 'date' => $date]
    //                 );
    //                 $stat->increment($action);
                    
    //                 $actions++;
    //                 $processed++;
                    
    //             } catch (\Exception $e) {
    //                 Log::error("Error processing click: ".$e->getMessage());
    //                 $errors++;
    //             }
    //         }
            
    //     } catch (\Exception $e) {
    //         Log::error("Error processing click queue: ".$e->getMessage());
    //         $errors++;
    //     }
        
    //     return compact('processed', 'actions', 'errors');
    // }

    
}
