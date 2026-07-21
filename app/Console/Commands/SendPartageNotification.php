<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Publication;
use App\Models\Statistique;
use Carbon\Carbon;
use App\Services\NotificationService;

class SendPartageNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-partage-notification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Get publications with shares since last notification
            $lastRun = Carbon::now()->subHours(24); // Adjust based on your schedule
            $summaries = Statistique::where('created_at', '>', $lastRun)
                ->with('publication')
                ->get()
                ->groupBy('publication_id');
            
            
            foreach ($summaries as $publicationId => $actions) {
                $publication = $actions->first()->publication;
                $shareCount = $actions->count();
                $totalPartageClicks = $actions->sum('click_partage');
                $totalWhatsAppClicks = $actions->sum('click_tele_whatsapp');
                $totalAppelClicks = $actions->sum('click_tele');

                $this->info("Sent ".$totalPartageClicks." partage summaries ");
                
                app(NotificationService::class)->sendPublicationPartageNotification($publication, $totalPartageClicks);
                app(NotificationService::class)->sendPublicationClickWhatsAppNotification($publication, $totalWhatsAppClicks);
                app(NotificationService::class)->sendPublicationClickAppelNotification($publication, $totalAppelClicks);
            }
            
            
        } catch (\Exception $e) {
            $this->error('An error occurred in partage notifications : ' . $e->getMessage());

        }
    }
}
