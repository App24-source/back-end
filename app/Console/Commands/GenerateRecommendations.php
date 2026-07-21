<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Publication;

class GenerateRecommendations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recommendations:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate recommendations based on user tags and clicks';

    /**
     * Execute the console command.
     */
    public function handle() {
        $prossed = 0;
        $this->info('Starting recent recommendation generation at '. now());
        $startTime = microtime(true);

        // Calculate the cutoff time (10 minutes ago)
        $cutoffTime = now()->subMinutes(10);

        $userswithTags = DB::table('user_tags_filters_flink')->whereNotNull('user_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('user_id');
        $etabswithTags = DB::table('user_tags_filters_flink')->whereNotNull('etab_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('etab_id');
        
        $usersWithClick = DB::table('user_clicked_publications_flink')->whereNotNull('user_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('user_id');
        $etabsWithClick = DB::table('user_clicked_publications_flink')->whereNotNull('etab_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('etab_id');

        $usersFollowers = DB::table('follows')->whereNotNull('user_follower_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('user_follower_id');
        $etabsFollowers = DB::table('follows')->whereNotNull('etab_follower_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('etab_follower_id');

        $usersSauvgardes = DB::table('sauvgarde_publications')->whereNotNull('user_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('user_id');
        $etabsSauvgardes = DB::table('sauvgarde_publications')->whereNotNull('etab_id')->where('updated_at', '>=', $cutoffTime)->distinct()->pluck('etab_id');

        // Get distinct users with recent activity
        $recentPublicationIdsFromTags = $this->getRecentPublicationIdsFromTags($userswithTags, $etabswithTags, $cutoffTime);
        $prossed1 = $this->storeRecommendations($recentPublicationIdsFromTags['usersWithPublications'], "user");
        $prossed2 = $this->storeRecommendations($recentPublicationIdsFromTags['etabsWithPublications'], "etab");

        $recentPublicationIdsFromClicks = $this->getRecentPublicationIdsFromClicks($usersWithClick, $etabsWithClick, $cutoffTime);
        $prossed3 = $this->storeRecommendations($recentPublicationIdsFromClicks['publicationsClickedByUsers'], "user");
        $prossed4 = $this->storeRecommendations($recentPublicationIdsFromClicks['publicationsClickedByEtabs'], "etab");

        $recentPublicationIdsFromFollowers = $this->getRecentPublicationIdsFromFollowers($usersFollowers, $etabsFollowers, $cutoffTime);
        $prossed5 = $this->storeRecommendations($recentPublicationIdsFromFollowers['usersFollowersWithPubIds'], "user");
        $prossed6 = $this->storeRecommendations($recentPublicationIdsFromFollowers['etabsFollowersWithPubIds'], "etab");

        $recentPublicationIdsFromSauvgardes = $this->getRecentPublicationIdsFromSauvgardes($usersSauvgardes, $etabsSauvgardes, $cutoffTime);
        $prossed7 = $this->storeRecommendations($recentPublicationIdsFromSauvgardes['usersWithSauvgardedPublications'], "user");
        $prossed8 = $this->storeRecommendations($recentPublicationIdsFromSauvgardes['etabsWithSauvgardedPublications'], "etab");

        $prossed = $prossed + $prossed1 + $prossed2 + $prossed3 + $prossed4 + $prossed5 + $prossed6 + $prossed7 + $prossed8;
        $duration = round(microtime(true) - $startTime, 2);
        $this->info("Processed with Success : {$prossed}");
        $this->info("Completed in {$duration} seconds");
        return Command::SUCCESS;
    }

    protected function getRecentPublicationIdsFromTags($userswithTagsIds, $etabswithTagsIds, $cutoffTime) {
        // Get publications of users with recent tags
        $usersWithPublications = User::whereIn('id', $userswithTagsIds)
        ->with(['tags_filters' => function($query) use ($cutoffTime) {
            $query->where('user_tags_filters_flink.updated_at', '>=', $cutoffTime)
                  ->with(['publications' => function($q) {
                      $q->where('status', 1)->orderByDesc('score')->limit(10); // 10 per tag_filter
                  }]);
        }])
        ->get(['id'])
        ->mapWithKeys(function ($user) {
            $publicationIds = $user->tags_filters
                ->flatMap(function ($tagFilter) {
                    return $tagFilter->publications->where('status', 1)->pluck('id');
                })
                ->unique()
                ->take(10) // Max 10 publications per user
                ->values()
                ->toArray();
            
            return [$user->id => $publicationIds];
        })
        ->toArray();

        // Get publications of etabs with recent tags
        $etabsWithPublications = Etablissement::whereIn('id', $etabswithTagsIds)
        ->with(['tags_filters' => function($query) use ($cutoffTime) {
            $query->where('user_tags_filters_flink.updated_at', '>=', $cutoffTime)
                  ->with(['publications' => function($q) {
                      $q->where('status', 1)->orderByDesc('score')->limit(10); // 10 per tag_filter
                  }]);
        }])
        ->get(['id']) // Only select etab IDs
        ->mapWithKeys(function ($etab) {
            $publicationIds = $etab->tags_filters
                ->flatMap(function ($tagFilter) {
                    return $tagFilter->publications->where('status', 1)->pluck('id');
                })
                ->unique()
                ->values()
                ->toArray();
            
            return [$etab->id => $publicationIds];
        })
        ->toArray();

        return ["usersWithPublications"=>$usersWithPublications, "etabsWithPublications"=>$etabsWithPublications];
    }

    protected function getRecentPublicationIdsFromClicks($usersClickedIds, $etabsClickedIds, $cutoffTime) {

        $publicationsClickedByUsers = User::whereIn('id', $usersClickedIds)
        ->with(['clicked_publications' => function($query) use ($cutoffTime) {
            $query->select('publications_flink.id')
                ->where('user_clicked_publications_flink.updated_at', '>=', $cutoffTime)
                ->with(['tags.publications' => function($query) {
                    $query->select('publications_flink.id')
                    ->where('publications_flink.status', 1)->orderByDesc('publications_flink.score');
                }]);
        }])
        ->get(['id'])
        ->mapWithKeys(function ($user) {
            // Get directly clicked publications
            $directPublications = $user->clicked_publications->pluck('id');
            
            // Get publications via tags (top 10 per tag)
            $tagPublications = $user->clicked_publications
                ->flatMap(function ($publication) {
                    return $publication->tags->flatMap(function ($tag) {
                        return $tag->publications->where('status', 1)->take(10)->pluck('id');
                    });
                })
                ->unique();
                
            // Combine both and remove duplicates
            $allPublications = $directPublications->merge($tagPublications)
                ->unique()
                ->values();
            
            return [$user->id => $allPublications];
        })
        ->toArray();

        $publicationsClickedByEtabs = Etablissement::whereIn('id', $etabsClickedIds)
        ->with(['clicked_publications' => function($query) use ($cutoffTime) {
            $query->select('publications_flink.id')
                ->where('user_clicked_publications_flink.updated_at', '>=', $cutoffTime)
                ->with(['tags.publications' => function($query) {
                    $query->select('publications_flink.id')
                    ->where('publications_flink.status', 1)->orderByDesc('publications_flink.score'); // Limit to 10 publications per tag
                }]);
        }])
        ->get(['id'])
        ->mapWithKeys(function ($etab) {
            // Get directly clicked publications
            $directPublications = $etab->clicked_publications->pluck('id');
            
            // Get publications via tags (top 10 per tag)
            $tagPublications = $etab->clicked_publications
                ->flatMap(function ($publication) {
                    return $publication->tags->flatMap(function ($tag) {
                        return $tag->publications->where('status', 1)->take(10)->pluck('id');
                    });
                })
                ->unique();
                
            // Combine both and remove duplicates
            $allPublications = $directPublications->merge($tagPublications)
                ->unique()
                ->values();
            
            return [$etab->id => $allPublications];
        })
        ->toArray();

        return ["publicationsClickedByUsers"=>$publicationsClickedByUsers, "publicationsClickedByEtabs"=>$publicationsClickedByEtabs];
    }

    protected function getRecentPublicationIdsFromFollowers($usersFollowersIds, $etabsFollowersIds, $cutoffTime) {
        $usersFollowersWithPubIds = User::whereIn('id', $usersFollowersIds)
        ->with([
            'followingUsers' => function($query) use ($cutoffTime) {
                $query->wherePivot('updated_at', '>=', $cutoffTime)
                    ->with(['publications']);
            },
            'followingEtablissements' => function($query) use ($cutoffTime) {
                $query->wherePivot('updated_at', '>=', $cutoffTime)
                    ->with(['publications']);
            }
        ])
        ->get()
        ->mapWithKeys(function($user) {
            // Extract publication IDs from followed users (latest 5)
            $userPubIds = $user->followingUsers
                ->flatMap(function($followedUser) {
                    return $followedUser->publications
                        ->where('status', 1)
                        ->sortByDesc('created_at')
                        ->take(5)
                        ->pluck('id');
                });
            
            // Extract publication IDs from followed establishments (latest 5)
            $etabPubIds = $user->followingEtablissements
                ->flatMap(function($etablissement) {
                    return $etablissement->publications
                        ->where('status', 1)
                        ->sortByDesc('created_at')
                        ->take(5)
                        ->pluck('id');
                });
            
            // Combine, deduplicate, and reset keys
            $allPubIds = $userPubIds->merge($etabPubIds)
                ->unique()
                ->values() // Reset array keys
                ->toArray();
        
            return [$user->id => $allPubIds];
        })
        ->toArray();

        $etabsFollowersWithPubIds = Etablissement::whereIn('id', $etabsFollowersIds)
        ->with([
            'followingUsers' => function($query) use ($cutoffTime) {
                $query->wherePivot('updated_at', '>=', $cutoffTime)
                    ->with(['publications']);
            },
            'followingEtablissements' => function($query) use ($cutoffTime) {
                $query->wherePivot('updated_at', '>=', $cutoffTime)
                    ->with(['publications']);
            }
        ])
        ->get()
        ->mapWithKeys(function($etab) {
            // Extract publication IDs from followed users (latest 5)
            $userPubIds = $etab->followingUsers
                ->flatMap(function($followedUser) {
                    return $followedUser->publications->where('status', 1)
                        ->sortByDesc('created_at')
                        ->take(5)
                        ->pluck('id');
                });
            
            // Extract publication IDs from followed establishments (latest 5)
            $etabPubIds = $etab->followingEtablissements
                ->flatMap(function($etablissement) {
                    return $etablissement->publications->where('status', 1)
                        ->sortByDesc('created_at')
                        ->take(5)
                        ->pluck('id');
                });
            
            // Combine, deduplicate, and reset keys
            $allPubIds = $userPubIds->merge($etabPubIds)
                ->unique()
                ->values() // Reset array keys
                ->toArray();
        
            return [$etab->id => $allPubIds];
        })
        ->toArray();


        return ["usersFollowersWithPubIds"=>$usersFollowersWithPubIds, "etabsFollowersWithPubIds"=>$etabsFollowersWithPubIds];
    }

    protected function getRecentPublicationIdsFromSauvgardes($usersSauvgardesIds, $etabsSauvgardesIds, $cutoffTime) {
        $usersWithSauvgardedPublications = User::whereIn('id', $usersSauvgardesIds)
        ->with(['publications_sauvgarde' => function($query) use ($cutoffTime) {
            $query->where('sauvgarde_publications.updated_at', '>=', $cutoffTime);
        }])
        ->get(['id'])
        ->mapWithKeys(function ($user) {
            $publicationIds = $user->publications_sauvgarde->pluck('id')
                ->unique()
                ->values()
                ->toArray();
            
            return [$user->id => $publicationIds];
        })
        ->toArray();

        $etabsWithSauvgardedPublications = User::whereIn('id', $etabsSauvgardesIds)
        ->with(['publications_sauvgarde' => function($query) use ($cutoffTime) {
            $query->where('sauvgarde_publications.updated_at', '>=', $cutoffTime);
        }])
        ->get(['id'])
        ->mapWithKeys(function ($etab) {
            $publicationIds = $etab->publications_sauvgarde->pluck('id')
                ->unique()
                ->values()
                ->toArray();
            
            return [$etab->id => $publicationIds];
        })
        ->toArray();

        return ["usersWithSauvgardedPublications"=>$usersWithSauvgardedPublications, "etabsWithSauvgardedPublications"=>$etabsWithSauvgardedPublications];
    }

    protected function storeRecommendations($data, $type) {
        $prossed = 0;
        // $this->info("data {$type} : {$data}");
        // foreach ($data as $item) {
            foreach ($data as $profileId => $pubIds) {
                if($type == "user") {
                    $profile = User::with(['recommendations' => function($query) use ($pubIds) {
                        $query->whereIn('publications_flink.id', $pubIds)
                            ->withPivot('count');
                    }])->find($profileId);
                } else {
                    $profile = Etablissement::with(['recommendations' => function($query) use ($pubIds) {
                        $query->whereIn('publications_flink.id', $pubIds)
                            ->withPivot('count');
                    }])->find($profileId);
                }
                
                // $this->info("profile {$type} : {$profileId}");
                
                if (!$profile) {
                    $this->error("Profile with ID {$profileId} not found (type: {$type})");
                    continue; // Skip to next iteration if profile doesn't exist
                }
        
                foreach ($pubIds as $publicationId) {
                    $publication = Publication::find($publicationId);
                    $prossed++;
                    if ($profile->recommendations->contains($publicationId)) {
                        $profile->recommendations()
                            ->where('publication_id', $publicationId)
                            ->increment('recommendations.count');

                        // Then fetch the updated count
                        $updatedRecommendation = $profile->recommendations()
                        ->where('publication_id', $publicationId)
                        ->first();

                        $updatedCount = $updatedRecommendation->pivot->count;

                        // Calculate the new score
                        $newScore = ($updatedCount + $publication->score) / 2;

                        // Update the pivot score
                        $profile->recommendations()
                        ->where('publication_id', $publicationId)
                        ->updateExistingPivot($publicationId, ['score' => $newScore]);
                    } else {
                        $profile->recommendations()->attach($publicationId, [
                            'count' => 1,
                            'score' => (1 + $publication->score) / 2,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                }
            }
        // }
        return $prossed;
    }
}
