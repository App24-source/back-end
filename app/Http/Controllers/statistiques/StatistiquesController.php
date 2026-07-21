<?php

namespace App\Http\Controllers\Statistiques;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Publication;
use App\Models\Statistique;
use App\Models\ProfileStatistique;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

class StatistiquesController extends Controller
{
    // Store Publication Statistic — writes directly to statistiques_flink (no Redis, no cron)
    public function storeStatistiques(Request $request)
    {
        try {
            $validated = $request->validate([
                'publication_id' => 'required|exists:publications_flink,id',
                'action' => 'required|in:click_contact,click_tele,click_tele_whatsapp,click_sauvgarder,click_partage,click_detail,vues,trafic,prospect,shopping,click_interested',
            ]);

            $publicationId = $validated['publication_id'];
            $date          = now()->toDateString();
            $action        = $validated['action'];

            // Map shopping → click_detail (no separate DB column)
            if ($action === 'shopping') {
                $action = 'click_detail';
            }

            // click_interested is tracked via reactions_flink — nothing to do here
            if ($action === 'click_interested') {
                return response()->json([
                    'success' => true,
                    'message' => 'click_interested processed via reactions',
                ]);
            }

            // ── Notification logic for share / call / whatsapp ────────────────
            if (in_array($action, ['click_partage', 'click_tele', 'click_tele_whatsapp'])) {
                try {
                    $todayStart  = Carbon::today()->startOfDay();
                    $publication = Publication::findOrFail($publicationId);
                    $profile     = $publication->etab_id ? $publication->etablissement : $publication->user;

                    if (!$profile) {
                        Log::error("Profile not found for publication ID: $publicationId");
                    } else {
                        $defaultActions = [
                            'share'          => ['first_sent_at' => null, 'grouped_sent_at' => null],
                            'whatsapp'       => ['first_sent_at' => null, 'grouped_sent_at' => null],
                            'appel'          => ['first_sent_at' => null, 'grouped_sent_at' => null],
                            'follow'         => ['first_sent_at' => null, 'grouped_sent_at' => null],
                            'sauvgarde'      => ['first_sent_at' => null, 'grouped_sent_at' => null],
                            'newpublication' => ['first_sent_at' => null, 'grouped_sent_at' => null],
                        ];

                        $actions = json_decode($profile->action_notifications, true) ?? $defaultActions;
                        $actions = array_merge($defaultActions, $actions);

                        if ($action === 'click_partage') {
                            if (isset($actions['share'])) {
                                $shareAction = $actions['share'];
                                if (empty($shareAction['first_sent_at']) || Carbon::parse($shareAction['first_sent_at'])->lt($todayStart)) {
                                    app(NotificationService::class)->sendPublicationPartageNotification($publication);
                                    app(NotificationService::class)->sendPublicationPartageGroupedNotification($profile);
                                    $actions['share']['first_sent_at'] = now()->toDateTimeString();
                                }
                                $profile->action_notifications = json_encode($actions);
                                $profile->save();
                            }
                        } elseif ($action === 'click_tele') {
                            if (isset($actions['appel'])) {
                                $shareAction = $actions['appel'];
                                if (empty($shareAction['first_sent_at']) || Carbon::parse($shareAction['first_sent_at'])->lt($todayStart)) {
                                    app(NotificationService::class)->sendPublicationClickAppelNotification($publication);
                                    app(NotificationService::class)->sendPublicationClickAppelGroupedNotification($profile);
                                    $actions['appel']['first_sent_at'] = now()->toDateTimeString();
                                }
                                $profile->action_notifications = json_encode($actions);
                                $profile->save();
                            }
                        } elseif ($action === 'click_tele_whatsapp') {
                            if (isset($actions['whatsapp'])) {
                                $shareAction = $actions['whatsapp'];
                                if (empty($shareAction['first_sent_at']) || Carbon::parse($shareAction['first_sent_at'])->lt($todayStart)) {
                                    app(NotificationService::class)->sendPublicationClickWhatsAppNotification($publication);
                                    app(NotificationService::class)->sendPublicationClickWhatsAppGroupedNotification($profile);
                                    $actions['whatsapp']['first_sent_at'] = now()->toDateTimeString();
                                }
                                $profile->action_notifications = json_encode($actions);
                                $profile->save();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('Notification processing failed', [
                        'publication_id' => $publicationId,
                        'action'         => $action,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }

            // Increment statistics in Redis (processed by the 10-minute scheduler)
            $boostIds    = [];
            $boostString = implode(',', $boostIds);
            $key         = "stat:{$publicationId}:{$date}:{$action}:{$boostString}";
            Redis::incr($key);

            return response()->json([
                'success' => true,
                'data'    => $request->all(),
            ]);

        } catch (\Throwable $e) {
            Log::error('storeStatistiques failed', [
                'error'        => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Storage failed',
            ], 500);
        }
    }

    // Store Views — writes directly to statistiques_flink (no Redis, no cron)
    public function storeViewsStatistique(Request $request)
    {
        try {
            $validated = $request->validate([
                'publication_ids' => 'required|array|exists:publications_flink,id',
            ]);

            $publicationIds = $validated['publication_ids'];
            $date           = now()->toDateString();

            foreach ($publicationIds as $publicationId) {
                $key = "stat:{$publicationId}:{$date}:vues:";
                Redis::incr($key);
            }

            return response()->json([
                'success'         => true,
                'publication_ids' => $publicationIds,
            ]);

        } catch (\Exception $e) {
            Log::error('storeViewsStatistique failed', [
                'error'  => $e->getMessage(),
                'params' => [
                    'publication_ids' => $request->input('publication_ids'),
                    'action'          => 'vues',
                    'date'            => now()->toDateString(),
                ],
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Storage failed',
            ], 500);
        }
    }

    protected function checkViewMilestones($publication)
    {
        $totalViews = $publication->statistiques->sum('vues');
        $totalViews += 1;

        if ($totalViews === 50 || $totalViews === 1000) {
            $publication->user->notify(
                new PublicationViewMilestoneNotification($publication, $totalViews)
            );
            $publication->last_milestone_notified = $totalViews;
            $publication->save();
        }
    }

    // Update Profile Statistic
    public function updateProfileStatistiques(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'etab_id' => 'nullable|exists:etablissements,id',
                'action'  => 'required|in:profile_views,followers_count',
            ]);

            if (!empty($validated['etab_id'])) {
                $stat = ProfileStatistique::firstOrCreate([
                    'etab_id' => $validated['etab_id'],
                    'date'    => now()->toDateString(),
                ]);
                $stat->increment($validated['action']);
            } elseif (!empty($validated['user_id'])) {
                $stat = ProfileStatistique::firstOrCreate([
                    'user_id' => $validated['user_id'],
                    'date'    => now()->toDateString(),
                ]);
                $stat->increment($validated['action']);
            }

            return response()->json(['message' => 'Statistique mise à jour avec succès']);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Une erreur s\'est produite',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Retrieve analytics data for publications and profile
    public function getAnalyticsData(Request $request)
    {
        $validated = $request->validate([
            'user_id'    => 'nullable|exists:users,id',
            'etab_id'    => 'nullable|exists:etablissements,id',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : Carbon::now()->subDays(6)->startOfDay();
        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->endOfDay()
            : Carbon::now()->endOfDay();

        $publicationQuery = Publication::query();
        $profileStatQuery = ProfileStatistique::query();

        if (!empty($validated['etab_id'])) {
            $publicationQuery->where('etab_id', $validated['etab_id']);
            $profileStatQuery->where('etab_id', $validated['etab_id']);
        } elseif (!empty($validated['user_id'])) {
            $publicationQuery->where('user_id', $validated['user_id']);
            $profileStatQuery->where('user_id', $validated['user_id']);
        }

        $publicationIds = $publicationQuery->pluck('id');

        $publicationStatistics = Statistique::whereIn('publication_id', $publicationIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                'date',
                DB::raw('DATE_FORMAT(date, "%d %b") as day'),
                DB::raw('SUM(vues) as views'),
                DB::raw('SUM(click_detail) as clicks'),
                DB::raw('SUM(click_contact) as contacts'),
                DB::raw('SUM(click_partage) as shares'),
                DB::raw('SUM(click_tele) as tele'),
                DB::raw('SUM(click_tele_whatsapp) as whatsapp'),
                DB::raw('SUM(click_sauvgarder) as saves')
            )
            ->groupBy('date', 'day')
            ->orderBy('date')
            ->get();

        $profileStatistics = $profileStatQuery
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                'date',
                DB::raw('DATE_FORMAT(date, "%d %b") as day'),
                DB::raw('SUM(profile_views) as profile_views'),
                DB::raw('MAX(followers_count) as followers')
            )
            ->groupBy('date', 'day')
            ->orderBy('date')
            ->get();

        $mergedStatistics = $this->mergeStatistics($publicationStatistics, $profileStatistics);
        $analyticsData    = $mergedStatistics->sortBy('date')->values()->all();
        $saves            = $this->calculateSaves($publicationIds, $startDate, $endDate);
        $totalMetrics     = $this->calculateTotalMetrics($mergedStatistics, $saves);
        $metricChanges    = $this->calculateMetricChanges($mergedStatistics);

        return response()->json([
            'analyticsData' => $analyticsData,
            'totalMetrics'  => $totalMetrics,
            'metricChanges' => $metricChanges,
        ]);
    }

    // Retrieve analytics data for a single publication
    public function getPublicationAnalytics(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => 'nullable|exists:users,id',
            'etab_id'        => 'nullable|exists:etablissements,id',
            'publication_id' => 'required|exists:publications_flink,id',
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date',
        ]);

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->endOfDay()
            : Carbon::now()->endOfDay();

        $publicationQuery = Publication::where('id', $validated['publication_id']);
        if (!empty($validated['etab_id'])) {
            $publicationQuery->where('etab_id', $validated['etab_id']);
        } elseif (!empty($validated['user_id'])) {
            $publicationQuery->where('user_id', $validated['user_id']);
        }

        $publication = $publicationQuery->with('tags')->first();

        if (!$publication) {
            return response()->json([
                'error' => 'Cette publication n\'appartient pas à l\'utilisateur ou à l\'établissement spécifié',
            ], 403);
        }

        $publicationStatistics = Statistique::where('publication_id', $validated['publication_id'])
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw('DATE_FORMAT(date, "%d %b") as day'),
                DB::raw('vues as views'),
                DB::raw('click_detail as clicks'),
                DB::raw('click_contact as contacts'),
                DB::raw('click_partage as shares'),
                DB::raw('click_tele as tele'),
                DB::raw('click_tele_whatsapp as whatsapp'),
                DB::raw('click_sauvgarder as saves')
            )
            ->orderBy('date')
            ->get();

        $saves = DB::table('sauvgarde_publications')
            ->where('publication_id', $validated['publication_id'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $totalMetrics = [
            'views'    => $publicationStatistics->sum('views'),
            'clicks'   => $publicationStatistics->sum('clicks'),
            'contacts' => $publicationStatistics->sum('contacts'),
            'shares'   => $publicationStatistics->sum('shares'),
            'tele'     => $publicationStatistics->sum('tele'),
            'whatsapp' => $publicationStatistics->sum('whatsapp'),
            'saves'    => $saves,
            'title'    => $publication->title ?? 'Publication',
            'created_at' => $publication->created_at,
        ];

        $metricChanges = [];
        if ($publicationStatistics->count() >= 2) {
            $metrics   = ['views', 'clicks', 'contacts', 'shares', 'tele', 'whatsapp', 'saves'];
            $firstRow  = $publicationStatistics->first();
            $lastRow   = $publicationStatistics->last();

            $metricChanges = collect($metrics)->mapWithKeys(function ($metric) use ($firstRow, $lastRow) {
                $firstValue = ($firstRow[$metric] ?? 0);
                $lastValue  = ($lastRow[$metric] ?? 0);
                if ($firstValue == 0) {
                    return [$metric => $lastValue > 0 ? 100 : 0];
                }
                return [$metric => (($lastValue - $firstValue) / $firstValue) * 100];
            })->toArray();
        }

        return response()->json([
            'publication'   => $publication,
            'analyticsData' => $publicationStatistics,
            'totalMetrics'  => $totalMetrics,
            'metricChanges' => $metricChanges,
        ]);
    }

    private function mergeStatistics($publicationStats, $profileStats)
    {
        $allDates = collect($publicationStats->pluck('date')->merge($profileStats->pluck('date')))->unique();

        return $allDates->map(function ($date) use ($publicationStats, $profileStats) {
            $pubData     = $publicationStats->firstWhere('date', $date) ?? new \stdClass();
            $profileData = $profileStats->firstWhere('date', $date) ?? new \stdClass();
            $day         = $pubData->day ?? ($profileData->day ?? Carbon::parse($date)->format('d M'));

            return [
                'date'          => $date,
                'day'           => $day,
                'views'         => $pubData->views ?? 0,
                'clicks'        => $pubData->clicks ?? 0,
                'contacts'      => $pubData->contacts ?? 0,
                'shares'        => $pubData->shares ?? 0,
                'tele'          => $pubData->tele ?? 0,
                'whatsapp'      => $pubData->whatsapp ?? 0,
                'saves'         => $pubData->saves ?? 0,
                'profile_views' => $profileData->profile_views ?? 0,
                'followers'     => $profileData->followers ?? 0,
            ];
        });
    }

    private function calculateSaves($publicationIds, $startDate, $endDate)
    {
        $saves = DB::table('sauvgarde_publications')
            ->whereIn('publication_id', $publicationIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        return ['total' => $saves];
    }

    private function calculateTotalMetrics($statistics, $saves)
    {
        return [
            'views'         => $statistics->sum('views'),
            'clicks'        => $statistics->sum('clicks'),
            'contacts'      => $statistics->sum('contacts'),
            'shares'        => $statistics->sum('shares'),
            'tele'          => $statistics->sum('tele'),
            'whatsapp'      => $statistics->sum('whatsapp'),
            'saves'         => $saves['total'],
            'profile_views' => $statistics->sum('profile_views'),
            'followers'     => $statistics->max('followers'),
        ];
    }

    private function calculateMetricChanges($statistics)
    {
        if ($statistics->count() < 2) {
            return [];
        }

        $metrics  = ['views', 'clicks', 'contacts', 'shares', 'tele', 'whatsapp', 'saves', 'profile_views', 'followers'];
        $firstRow = $statistics->first();
        $lastRow  = $statistics->last();

        return collect($metrics)->mapWithKeys(function ($metric) use ($firstRow, $lastRow) {
            $firstValue = ($firstRow[$metric] ?? 0);
            $lastValue  = ($lastRow[$metric] ?? 0);
            if ($firstValue == 0) {
                return [$metric => $lastValue > 0 ? 100 : 0];
            }
            return [$metric => (($lastValue - $firstValue) / $firstValue) * 100];
        })->toArray();
    }

    // ─── Reaction System ───────────────────────────────────────────────────────

    /**
     * Store or update a user's reaction on a publication.
     */
    public function storeReaction(Request $request)
    {
        try {
            $validated = $request->validate([
                'publication_id'   => 'required|exists:publications_flink,id',
                'reaction_type'    => 'required|in:interessant,bon_prix,bien_situe,clair,pro_serieux',
                'etablissement_id' => 'nullable|exists:etablissements,id',
            ]);

            $authUserId      = $request->user()->id;
            $publicationId   = $validated['publication_id'];
            $reactionType    = $validated['reaction_type'];
            $etablissementId = $validated['etablissement_id'] ?? null;

            if ($etablissementId) {
                $existing = DB::table('reactions_flink')
                    ->where('user_id', $authUserId)
                    ->where('etablissement_id', $etablissementId)
                    ->where('publication_id', $publicationId)
                    ->first();
            } else {
                $existing = DB::table('reactions_flink')
                    ->where('user_id', $authUserId)
                    ->whereNull('etablissement_id')
                    ->where('publication_id', $publicationId)
                    ->first();
            }

            if ($existing) {
                if ($existing->reaction_type === $reactionType && !$request->input('force_react')) {
                    DB::table('reactions_flink')->where('id', $existing->id)->delete();
                    $reactionType = null;
                } else {
                    DB::table('reactions_flink')->where('id', $existing->id)->update([
                        'reaction_type' => $reactionType,
                        'updated_at'    => now(),
                    ]);
                }
            } else {
                DB::table('reactions_flink')->insert([
                    'publication_id'   => $publicationId,
                    'user_id'          => $authUserId,
                    'etablissement_id' => $etablissementId,
                    'reaction_type'    => $reactionType,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            $totalCount = DB::table('reactions_flink')
                ->where('publication_id', $publicationId)
                ->count();

            $breakdown = DB::table('reactions_flink')
                ->where('publication_id', $publicationId)
                ->select('reaction_type', DB::raw('COUNT(*) as count'))
                ->groupBy('reaction_type')
                ->pluck('count', 'reaction_type');

            $mostReacted = DB::table('reactions_flink')
                ->where('publication_id', $publicationId)
                ->select('reaction_type', DB::raw('COUNT(*) as total'))
                ->groupBy('reaction_type')
                ->orderByDesc('total')
                ->limit(3)
                ->pluck('reaction_type');

            return response()->json([
                'success'      => true,
                'reaction_type' => $reactionType,
                'total_count'  => $totalCount,
                'breakdown'    => $breakdown,
                'most_reacted' => $mostReacted,
                'reactions'    => [
                    'total'         => $totalCount,
                    'user_reaction' => $reactionType,
                    'most_reacted'  => $mostReacted,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('storeReaction error detailed:', [
                'message'      => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'user_id'      => $request->user() ? $request->user()->id : 'null',
            ]);

            return response()->json([
                'success'       => false,
                'error'         => 'Could not save reaction.',
                'debug_message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the authenticated user's current reaction and the total count.
     */
    public function getReaction(Request $request)
    {
        try {
            $validated = $request->validate([
                'publication_id' => 'required|exists:publications_flink,id',
            ]);

            $publicationId = $validated['publication_id'];

            $userReaction = null;
            if ($request->user()) {
                $userReaction = DB::table('reactions_flink')
                    ->where('user_id', $request->user()->id)
                    ->where('publication_id', $publicationId)
                    ->value('reaction_type');
            }

            $rawReactions = DB::table('reactions_flink')
                ->where('publication_id', $publicationId)
                ->leftJoin('users', 'reactions_flink.user_id', '=', 'users.id')
                ->leftJoin('etablissements', 'reactions_flink.etablissement_id', '=', 'etablissements.id')
                ->select(
                    'reactions_flink.reaction_type',
                    'reactions_flink.user_id',
                    'reactions_flink.etablissement_id',
                    DB::raw('TRIM(CONCAT(IFNULL(users.first_name, ""), " ", IFNULL(users.last_name, ""))) as user_name'),
                    'users.slug as user_slug',
                    'users.avatar as user_avatar',
                    'etablissements.nom as etab_name',
                    'etablissements.slug as etab_slug',
                    'etablissements.logo as etab_avatar'
                )
                ->get();

            $totalCount         = $rawReactions->count();
            $reactionsBreakdown = [];

            foreach ($rawReactions as $row) {
                $type = $row->reaction_type;
                if (!isset($reactionsBreakdown[$type])) {
                    $reactionsBreakdown[$type] = ['count' => 0, 'details' => []];
                }
                $reactionsBreakdown[$type]['count']++;
                $isEtab = !empty($row->etablissement_id);
                $reactionsBreakdown[$type]['details'][] = [
                    'id'     => $isEtab ? $row->etablissement_id : $row->user_id,
                    'type'   => $isEtab ? 'etablissement' : 'user',
                    'name'   => $isEtab ? $row->etab_name : $row->user_name,
                    'slug'   => $isEtab ? $row->etab_slug : $row->user_slug,
                    'avatar' => $isEtab ? $row->etab_avatar : $row->user_avatar,
                ];
            }

            return response()->json([
                'success'       => true,
                'user_reaction' => $userReaction,
                'total'         => $totalCount,
                'reactions'     => $reactionsBreakdown,
            ]);

        } catch (\Exception $e) {
            Log::error('getReaction detailed failed', [
                'error'          => $e->getMessage(),
                'publication_id' => $request->publication_id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Could not fetch detailed reactions',
            ], 500);
        }
    }
}
