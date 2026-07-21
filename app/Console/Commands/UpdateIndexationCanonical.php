<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class UpdateIndexationCanonical extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-indexation-canonical';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update indexation and canonical settings daily';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Create custom logger for laravel-indexation.log
        $logPath = storage_path( 'logs/laravel-indexation.log' );
        $logger = new Logger( 'indexation' );
        $logger->pushHandler( new StreamHandler( $logPath, Logger::INFO ) );

        $this->info( 'Starting indexation and canonical update at ' . now() );
        $logger->info( 'Indexation and canonical update started', [
            'timestamp' => now(),
            'command' => 'app:update-indexation-canonical'
        ] );

        // TODO: Add indexation and canonical update logic here

        $this->info( 'Indexation and canonical update completed.' );
        $logger->info( 'Indexation and canonical update completed', [
            'timestamp' => now(),
            'command' => 'app:update-indexation-canonical'
        ] );

        return Command::SUCCESS;
    }
}

