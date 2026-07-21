<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Publication;
use App\Models\User;
use App\Models\Etablissement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class UpdateDailyScore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-daily-score';

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
        // update users score
        $users = User::with([
            'clicked_publications',
            'publications_sauvgarde',
            'publications',
            'followingUsers',
            'followersUsers',
            'followingEtablissements',
            'followersEtablissements'
        ])
        ->get(); // Fetch all users

        foreach ($users as $user) {
            // variables
            $score = 0;

            // user verification
            if($user->is_verified) {
                $score += 1;
            }
            // // clicked publications
            // if($user->clicked_publications->count() > 0) {
            //     $clicked_publicationsCount = $user->clicked_publications->count() / 10000;
            //     $score += $clicked_publicationsCount;
            // }
            // // publications sauvgarde
            // if($user->publications_sauvgarde->count() > 0) {
            //     $publications_sauvgarde = $user->publications_sauvgarde->count() / 10000;
            //     $score += $publications_sauvgarde;
            // }
            // // publications
            // if($user->publications->count() > 0) {
            //     $publicationsScore = collect($user->publications)->sum('score');
            //     $result = intdiv($publicationsScore, 10); // Integer division
            //     $score += $result;
            // }

            // followers
            if($user->followersUsers->count() > 0 || $user->followersEtablissements->count() > 0) {
                $followerCount = $user->followersUsers->count() + $user->followersEtablissements->count();
                $result = intdiv($followerCount, 50) / 1000; // Integer division
                $score += $result;
            }
            // following
            if($user->followingUsers->count() > 0 || $user->followingEtablissements->count() > 0) {
                $followerCount = $user->followingUsers->count() + $user->followingEtablissements->count();
                $result = intdiv($followerCount, 50) / 100; // Integer division
                $score += $result;
            }

            // Store or return the score if needed
            $old = (float) ($user->score ?? 0);
            $user->score = $score;

            // Save only if changed and log
            if (abs($user->score - $old) > 1e-9) {
                $user->save();
            }
        }

        // update etablissements score
        $etablissements = Etablissement::with([
            'clicked_publications',
            'publications_sauvgarde',
            'publications',
            'followingUsers',
            'followersUsers',
            'followingEtablissements',
            'followersEtablissements',
        ])
        ->get(); // Fetch all etablissements

        foreach ($etablissements as $etablissement) {
            // variables
            $score = 0;

            // etablissement verification
            if($etablissement->is_verified) {
                $score += 1;
            }
            // // clicked publications
            // if($etablissement->clicked_publications->count() > 0) {
            //     $clicked_publicationsCount = $etablissement->clicked_publications->count() / 10;
            //     $score += $clicked_publicationsCount;
            // }
            // // publications sauvgarde
            // if($etablissement->publications_sauvgarde->count() > 0) {
            //     $publications_sauvgarde = $etablissement->publications_sauvgarde->count() / 5;
            //     $score += $publications_sauvgarde;
            // }
            // publications
            // if($etablissement->publications->count() > 0) {
            //     $publicationsScore = collect($etablissement->publications)->sum('score');
            //     $result = intdiv($publicationsScore, 10); // Integer division
            //     $score += $result;
            // }
            // followers
            if($etablissement->followersUsers->count() > 0 || $etablissement->followersEtablissements->count() > 0) {
                $followerCount = $etablissement->followersUsers->count() + $etablissement->followersEtablissements->count();
                $result = intdiv($followerCount, 50) / 1000; // Integer division
                $score += $result;
            }
            // following
            if($etablissement->followingUsers->count() > 0 || $etablissement->followingEtablissements->count() > 0) {
                $followerCount = $etablissement->followingUsers->count() + $etablissement->followingEtablissements->count();
                $result = intdiv($followerCount, 50) / 100; // Integer division
                $score += $result;
            }

            // Store or return the score if needed
            $oldE = (float) ($etablissement->score ?? 0);
            $etablissement->score = $score;

            // Save only if changed and log
            if (abs($etablissement->score - $oldE) > 1e-9) {
                $etablissement->save();
            }
        }

        // update publication score
        $annonces = Publication::with([
            'pays:id,slug,name',
            'ville:id,slug,name,pays_id',
            'region:id,slug,name,ville_id',
            'categorie:id,slug,name',
            'tags:id,name,slug',
            'user:id,first_name,last_name,is_verified,score,created_at',
            'etablissement:id,nom,is_verified,score,created_at',
            'user.publications:id,status,user_id',
            'etablissement.publications:id,status,etab_id',
            'statistiques',
        ])
        ->get(); // Fetch all annonces

        foreach ($annonces as $annonce) {
            // variables
            $score = 0;
            $createdAt = Carbon::parse($annonce->created_at);

            // existance de video
            if($annonce->video_origine) {
                $score += 0.5;
            }
            // is_Marketplace
            if($annonce->is_marketplace) {
                $score += 0.5;
            }
            // calculate number of clicks
            $click_partage = 0; $click_detail = 0; $click_sauvgarder = 0;
            if($annonce->statistiques->count() > 0) {
                // nombre de click partage
                $click_partage = collect($annonce->statistiques)->sum('click_partage') /1000;
                $score += $click_partage;
                // nombre de click detail
                $click_detail = collect($annonce->statistiques)->sum('click_detail') /100;
                $score += $click_detail;
                // nombre de click sauvgarde
                $click_sauvgarder = collect($annonce->statistiques)->sum('click_sauvgarder') /50;
                $score += $click_sauvgarder;
            }
            // anociente de la publication
            $daysSinceCreation = $createdAt->diffInDays(Carbon::now());
            if($daysSinceCreation <= 1) {
                // $scoreToAdd = ($click_partage + $click_detail + $click_sauvgarder) / $daysSinceCreation;
                // $score += round($scoreToAdd, 2); // Round to 2 decimal places
                $score += 9;
            } else if($daysSinceCreation > 1 && $daysSinceCreation <= 3) {
                $score += 8;
            } else if($daysSinceCreation > 3 && $daysSinceCreation <= 5) {
                $score += 7;
            } else if($daysSinceCreation > 5 && $daysSinceCreation < 10) {
                $score += 6;
            } else if($daysSinceCreation >= 10 && $daysSinceCreation < 20) {
                $score += 4;
            } else if($daysSinceCreation >= 20 && $daysSinceCreation < 30) {
                $score += 1;
            }
            // add score of user or etalissement of publication 
            if($annonce->etablissement) {
                $score += $annonce->etablissement->score;
            } else if($annonce->user) {
                $score += $annonce->user->score;
            }
            // add reposts contribution (0.1 effective per repost)
            try {
                $repostsCount = DB::table('publications_reposted')->where('publication_id', $annonce->id)->count();
                if ($repostsCount > 0) {
                    $score += $repostsCount * 0.2; // divided by 2 later => +0.1 each
                }
            } catch (\Throwable $e) {}
    
            // Store or return the score if needed
            $oldP = (float) ($annonce->score ?? 0);
            $annonce->score = $score / 2;
            if (abs($annonce->score - $oldP) > 1e-9) {
                $annonce->save();
            }
        }
        // Log the update
        

        $this->info('Daily score update completed.');
    }
}
