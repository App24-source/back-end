<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    protected $commands = [
        \App\Console\Commands\UpdateDailyScore::class,
        \App\Console\Commands\SyncStatisticsToDatabase::class,
        \App\Console\Commands\UpdateIndexationCanonical::class,
        \App\Console\Commands\GenerateSitemap::class,
        \App\Console\Commands\DispatchDueCatalogueRefreshes::class,
    ];
    /**
     * Define the application's command schedule.
     */
    protected function schedule( Schedule $schedule ): void
    {
        $schedule->command( 'app:update-daily-score' )->dailyAt( '05:00' );
        $schedule->command( 'statistics:sync' )
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo( storage_path( 'logs/sync.log' ) );
        $schedule->command( 'recommendations:generate' )
            ->everyTenMinutes()
            ->withoutOverlapping()->appendOutputTo( storage_path( 'logs/recommendations.log' ) );

        $schedule->command( 'bounces:process' )->dailyAt( '01:00' );

        $schedule->command( 'app:update-indexation-canonical' )
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->appendOutputTo( storage_path( 'logs/laravel-indexation.log' ) );

        $schedule->command( 'catalogues:dispatch-refreshes' )
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo( storage_path( 'logs/catalogue-auto-refresh.log' ) );
 
        // $schedule->command( 'sitemap:generate' )
        //     ->dailyAt('06:00')
        //     ->withoutOverlapping()
        //     ->sendOutputTo( storage_path( 'logs/sitemap.log' ) );



        
        // Send at 2 specific times daily (adjust times as needed)

        // $schedule->command('app:send-partage-notification')
        //     ->dailyAt('08:00'); // 8 AM

        // $schedule->command('app:send-partage-notification')
        //     ->dailyAt('22:14'); // 8 PM

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load( __DIR__ . '/Commands' );

        require base_path( 'routes/console.php' );
    }
}
