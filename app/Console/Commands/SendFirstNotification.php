<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SendFirstNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-first-notification';

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
            // Get today's start and end
            $todayStart = Carbon::today()->startOfDay();
            $todayEnd = Carbon::today()->endOfDay();

            // Check if any partages exist today
            $summaries = Statistique::where('click_partage', '>', 0)
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->with('publication')
                ->get()
                ->groupBy('publication_id');

            
            // // Get publications with shares since last notification
            // $lastRun = Carbon::now()->subHours(24); // Adjust based on your schedule
            // $summaries = Statistique::where('created_at', '>', $lastRun)
            //     ->with('publication')
            //     ->get()
            //     ->groupBy('publication_id');
            
            
            foreach ($summaries as $publicationId => $actions) {
                $publication = $actions->first()->publication;
                $shareCount = $actions->count();
                // $totalPartageClicks = $actions->sum('click_partage');
                // $totalWhatsAppClicks = $actions->sum('click_tele_whatsapp');
                // $totalAppelClicks = $actions->sum('click_tele');

                $this->info("Sent ".$shareCount." partage shareCount ");
                
                if($publication->etab_id) $profile = $publication->etablissement;
                else $profile = $publication->user;

                // if($profile->)
                app(NotificationService::class)->sendPublicationPartageNotification($publication, $totalPartageClicks);
                app(NotificationService::class)->sendPublicationClickWhatsAppNotification($publication, $totalWhatsAppClicks);
                app(NotificationService::class)->sendPublicationClickAppelNotification($publication, $totalAppelClicks);
            }
            $this->info('First notification sent successfully.');
        } catch (\Exception $e) {
            $this->error('An error occurred while sending the first notification: ' . $e->getMessage());
        }
    }
}
