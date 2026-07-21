<?php

namespace App\Http\Controllers\publication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestUser;
use App\Models\Publication;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Etablissement;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;
use App\Models\CategorieFlink;
use App\Models\Pays;
use App\Models\Ville;
use App\Models\Region;
use App\Models\Tags;

use Illuminate\Support\Str;
use App\Models\Statistique;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Models\PublicationBooster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

use Jenssegers\Agent\Agent;

use Illuminate\Support\Facades\Redis;

use App\Services\NotificationService;

use App\Models\Notification;
use App\Notifications\NewPublicationNotificationEmailUser;
use App\Notifications\FirstPublicationEmailReminder;
use App\Models\PublicationReposted;

class PublicationController extends Controller
{
    private function deduplicatePublications( $paginator )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator )) {
                return $paginator;
            }
            $items = $paginator->getCollection();
            $seen = [];
            $unique = $items->filter( function ( $item ) use ( &$seen ) {
                try {
                    if ($item instanceof Publication) {
                        $id = $item->id ?? null;
                        if ($id === null) {
                            return true;
                        }
                        if (isset( $seen[$id] )) {
                            return false;
                        }
                        $seen[$id] = true;
                        return true;
                    }
                    return true; // keep non-Publication items (e.g., ad placeholders)
                } catch (\Throwable $e) {
                    return true;
                }
            } )->values();
            $paginator->setCollection( $unique );
        } catch (\Throwable $e) {
        }
        return $paginator;
    }
    private function adjustRepostedScores( $paginator )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator )) {
                return $paginator;
            }
            $items = $paginator->getCollection();
            $items->transform( function ( $publication ) {
                try {
                    $repost = PublicationReposted::where( 'publication_id', $publication->id )
                        ->orderByDesc( 'created_at' )
                        ->first();
                    if ($repost) {
                        $originalScore = is_numeric( $publication->score ?? null ) ? (float) $publication->score : 0.0;
                        $publication->score = $originalScore < 3 ? ( $originalScore / 2 ) : ( $originalScore - 1.5 );
                    }
                } catch (\Throwable $e) {
                }
                return $publication;
            } );
        } catch (\Throwable $e) {
        }
        return $paginator;
    }
    private function filterRepostsForFollowers( $paginator, $followedSlugs )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator )) {
                return $paginator;
            }
            $items = $paginator->getCollection();
            $followed = is_array( $followedSlugs ) ? $followedSlugs : [];
            $items = $items->filter( function ( $publication ) use ( $followed ) {
                if (isset( $publication->repost_user ) && is_array( $publication->repost_user )) {
                    $slug = $publication->repost_user['slug'] ?? null;
                    if (!$slug)
                        return false; // no slug -> hide repost
                    return in_array( $slug, $followed );
                }
                return true; // non-repost always visible
            } )->values();
            $paginator->setCollection( $items );
        } catch (\Throwable $e) {
        }
        return $paginator;
    }
    private function topUpAfterFilter( $paginator, $orderedPublications, Request $request, $perPage, $followedSlugs )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator )) {
                return $paginator;
            }
            $items = $paginator->getCollection();
            $needed = max( 0, (int) $perPage - $items->count() );
            if ($needed <= 0)
                return $paginator;

            $currentPage = (int) $paginator->currentPage();
            $page = $currentPage + 1;
            $safety = 0;
            while ($needed > 0 && $safety < 5) {
                $safety++;
                $nextRequest = clone $request;
                $nextRequest->merge( ['page' => $page] );
                $next = addBoosterToPublicationsToFlux( $orderedPublications, $nextRequest, $perPage, true );
                if (!( $next instanceof LengthAwarePaginator ) || $next->count() === 0) {
                    break;
                }
                $next = $this->attachRepostUserToPublications( $next );
                $next = $this->filterRepostsForFollowers( $next, $followedSlugs );
                $take = $next->getCollection()->take( $needed );
                if ($take->isEmpty()) {
                    $page++;
                    continue;
                }
                $items = $items->merge( $take );
                $needed = max( 0, (int) $perPage - $items->count() );
                $page++;
            }
            $paginator->setCollection( $items->values() );
        } catch (\Throwable $e) {
        }
        return $paginator;
    }
    private function ensureFirstItemIsRealPublication( $paginator )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator )) {
                return $paginator;
            }
            $items = $paginator->getCollection();
            if ($items->isEmpty())
                return $paginator;
            $first = $items->first();
            if (!( $first instanceof Publication )) {
                $idx = $items->search( function ( $it ) {
                    return $it instanceof Publication;
                } );
                if ($idx !== false && $idx > 0) {
                    $real = $items->get( $idx );
                    $items->splice( $idx, 1 ); // remove the real item from its position
                    $items->prepend( $real );   // put it at the beginning (ad/placeholder shifts to index 1)
                    $paginator->setCollection( $items->values() );
                }
            }
        } catch (\Throwable $e) {
        }
        return $paginator;
    }
    private function positionAdPlaceholder( $paginator, Request $request, $perPage )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator ))
                return $paginator;
            $items = $paginator->getCollection();
            if ($items->isEmpty())
                return $paginator;
            // Find the first ad/placeholder: non-Publication or has type === 'ad'
            $adIdx = $items->search( function ( $it ) {
                if (!( $it instanceof Publication ))
                    return true;
                return ( is_object( $it ) || is_array( $it ) ) && ( data_get( $it, 'type' ) === 'ad' );
            } );
            if ($adIdx === false)
                return $paginator;

            // Random position between 2 and 4 (0-based indices 1..3), within bounds of current page items
            $maxTarget = max( 1, min( 3, $items->count() - 1 ) );
            if ($maxTarget < 1)
                return $paginator;
            $targetIndex = mt_rand( 1, $maxTarget );

            if ($adIdx == $targetIndex)
                return $paginator; // already placed

            $adItem = $items->get( $adIdx );
            // Remove current ad position
            $items->splice( $adIdx, 1 );
            // Adjust target if removal shifted indices
            if ($adIdx < $targetIndex) {
                $targetIndex = max( 1, $targetIndex - 1 );
            }
            // Insert ad at targetIndex
            $items->splice( $targetIndex, 0, [$adItem] );
            $paginator->setCollection( $items->values() );
        } catch (\Throwable $e) {
        }
        return $paginator;
    }
    private function isBoostedItem( $item ): bool
    {
        try {
            if ($item instanceof Publication) {
                // Prefer withCount alias 'booster' if present, else relation
                $count = data_get( $item, 'booster' );
                if (is_numeric( $count ) && (int) $count > 0)
                    return true;
                $rel = data_get( $item, 'publications_booster' );
                if (is_array( $rel ) && count( $rel ) > 0)
                    return true;
                if ($rel instanceof \Illuminate\Support\Collection && $rel->count() > 0)
                    return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }
    private function getItemId( $item )
    {
        try {
            if ($item instanceof Publication)
                return $item->id ?? null;
            if (is_array( $item ) || is_object( $item ))
                return data_get( $item, 'id' );
        } catch (\Throwable $e) {
        }
        return null;
    }
    private function hasRepost( $item ): bool
    {
        try {
            // Consider presence of repost_user as reposted variant
            return (bool) data_get( $item, 'repost_user' );
        } catch (\Throwable $e) {
            return false;
        }
    }
    private function hasAdPlaceholder( $items ): bool
    {
        try {
            foreach ($items as $it) {
                if (!( $it instanceof Publication ))
                    return true;
                if (( is_object( $it ) || is_array( $it ) ) && data_get( $it, 'type' ) === 'ad')
                    return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }
    private function transformIfRepostedForFollowed( $item, array $followedSlugs )
    {
        try {
            if (!( $item instanceof Publication ))
                return $item;
            // Find latest repost for this publication
            $reposts = PublicationReposted::where( 'publication_id', $item->id )
                ->orderByDesc( 'created_at' )
                ->limit( 5 )
                ->get();
            foreach ($reposts as $repost) {
                if ($repost->profile_type === 'user' && $repost->user_id) {
                    $u = User::select( 'id', 'slug', 'first_name', 'last_name', 'avatar' )->find( $repost->user_id );
                    if ($u && in_array( $u->slug, $followedSlugs, true )) {
                        $clone = clone $item;
                        $clone->id = (int) $repost->id; // Use repost id as unique id
                        $clone->repost_user = [
                            'type' => 'user',
                            'id' => $u->id,
                            'slug' => $u->slug,
                            'name' => trim( ( $u->first_name ?? '' ) . ' ' . ( $u->last_name ?? '' ) ),
                            'avatar' => $u->avatar,
                            'reposted_at' => (string) $repost->created_at,
                        ];
                        return $clone;
                    }
                } elseif ($repost->profile_type === 'etablissement' && $repost->etab_id) {
                    $e = Etablissement::select( 'id', 'slug', 'nom', 'logo' )->find( $repost->etab_id );
                    if ($e && in_array( $e->slug, $followedSlugs, true )) {
                        $clone = clone $item;
                        $clone->id = (int) $repost->id; // Use repost id as unique id
                        $clone->repost_user = [
                            'type' => 'etablissement',
                            'id' => $e->id,
                            'slug' => $e->slug,
                            'name' => $e->nom,
                            'logo' => $e->logo,
                            'reposted_at' => (string) $repost->created_at,
                        ];
                        return $clone;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $item;
    }
    private function fetchMoreItems( $orderedPublications, Request $request, $perPage, $followedSlugs, array $excludeIds = [], bool $boostedOnly = false, int $pagesToScan = 5 )
    {
        $results = collect();
        try {
            $startPage = max( 1, (int) $request->input( 'page', 1 ) ) + 1;
            for ($p = $startPage; $p < $startPage + $pagesToScan; $p++) {
                $req = clone $request;
                $req->merge( ['page' => $p] );
                $pg = addBoosterToPublicationsToFlux( $orderedPublications, $req, $perPage, true );
                if (!( $pg instanceof LengthAwarePaginator ) || $pg->count() === 0)
                    break;
                $pg = $this->attachRepostUserToPublications( $pg );
                $pg = $this->filterRepostsForFollowers( $pg, $followedSlugs );
                $its = $pg->getCollection();
                foreach ($its as $it) {
                    try {
                        if (!( $it instanceof Publication ))
                            continue; // ignore ads here
                        $id = $it->id ?? null;
                        if ($id === null)
                            continue;
                        if (in_array( $id, $excludeIds, true ))
                            continue;
                        if ($boostedOnly && !$this->isBoostedItem( $it ))
                            continue;
                        if (!$boostedOnly && $this->isBoostedItem( $it ))
                            continue;
                        $results->push( $it );
                    } catch (\Throwable $e) {
                    }
                }
                if ($results->count() >= $perPage)
                    break;
            }
        } catch (\Throwable $e) {
        }
        return $results;
    }
    private function getRotatedBoostedItem( $orderedPublications, Request $request, $perPage, $followedSlugs, array $excludeIds = [], $profileObject = null )
    {
        try {
            // Collect a pool of boosted items
            $pool = $this->fetchMoreItems( $orderedPublications, $request, $perPage, $followedSlugs, $excludeIds, true, 15 );
            if ($pool->count() === 0)
                return null;
            // Weight by tag overlap if profileObject provided
            $userTagIds = [];
            if ($profileObject && method_exists( $profileObject, 'tags_filters' )) {
                try {
                    $userTagIds = $profileObject->tags_filters->pluck( 'id' )->toArray();
                } catch (\Throwable $e) {
                }
            }
            $scored = $pool->map( function ( $pub ) use ( $userTagIds ) {
                $overlap = 0;
                try {
                    $tags = data_get( $pub, 'tags', [] );
                    if (is_array( $tags )) {
                        foreach ($tags as $t) {
                            $tid = is_array( $t ) || is_object( $t ) ? data_get( $t, 'id' ) : null;
                            if ($tid !== null && in_array( $tid, $userTagIds, true ))
                                $overlap++;
                        }
                    }
                } catch (\Throwable $e) {
                }
                $score = (float) ( data_get( $pub, 'score', 0 ) ?? 0 );
                return ['pub' => $pub, 'overlap' => $overlap, 'score' => $score];
            } );
            // Sort by overlap desc then score desc
            $sorted = $scored->sort( function ( $a, $b ) {
                if ($a['overlap'] === $b['overlap'])
                    return $b['score'] <=> $a['score'];
                return $b['overlap'] <=> $a['overlap'];
            } )->values();
            $keyBase = json_encode( array_diff_key( $request->query(), ['page' => true] ) );
            $cursorKey = 'boost_cursor:' . md5( $keyBase );
            $cursor = (int) ( Cache::get( $cursorKey, 0 ) ?? 0 );
            $idx = $sorted->count() > 0 ? ( $cursor % $sorted->count() ) : 0;
            Cache::put( $cursorKey, $cursor + 1, now()->addMinutes( 30 ) );
            $chosen = $sorted->get( $idx );
            return $chosen ? $chosen['pub'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    private function finalizePageComposition( $paginator, $orderedPublications, Request $request, $perPage, $followedSlugs )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator ))
                return $paginator;
            $pageNum = max( 1, (int) $request->input( 'page', 1 ) );
            $items = collect( $paginator->getCollection() );
            // 1) Remove duplicates inside the page (by id), allowing at most:
            //    - one non-reposted variant
            //    - one reposted variant (has repost_user)
            $seenNormal = [];
            $seenRepost = [];
            $items = $items->filter( function ( $it ) use ( &$seenNormal, &$seenRepost ) {
                try {
                    if (!( $it instanceof Publication ))
                        return true;
                    $id = $it->id ?? null;
                    if ($id === null)
                        return true;
                    $isRepost = (bool) data_get( $it, 'repost_user' );
                    if ($isRepost) {
                        if (isset( $seenRepost[$id] ))
                            return false;
                        $seenRepost[$id] = true;
                        return true;
                    }
                    if (isset( $seenNormal[$id] ))
                        return false;
                    $seenNormal[$id] = true;
                } catch (\Throwable $e) {
                }
                return true;
            } )->values();

            // 2) Enforce exactly one boosted per page
            $boostedIdxs = [];
            foreach ($items as $idx => $it) {
                if ($this->isBoostedItem( $it ))
                    $boostedIdxs[] = $idx;
            }
            if (count( $boostedIdxs ) === 0) {
                // Pull one boosted using rotation and tag weighting
                $exclude = array_values( array_filter( array_map( fn( $x ) => $x instanceof Publication ? ( $x->id ?? null ) : null, iterator_to_array( $items ) ) ) );
                $replacement = $this->getRotatedBoostedItem( $orderedPublications, $request, $perPage, $followedSlugs, $exclude, ( isset( $GLOBALS['profileObject'] ) ? $GLOBALS['profileObject'] : null ) );
                if ($replacement) {
                    // Replace last non-boosted if exists, else last item
                    $replaceIdx = null;
                    for ($i = $items->count() - 1; $i >= 0; $i--) {
                        if (!( $items[$i] instanceof Publication ))
                            continue;
                        if (!$this->isBoostedItem( $items[$i] )) {
                            $replaceIdx = $i;
                            break;
                        }
                    }
                    if ($replaceIdx === null)
                        $replaceIdx = max( 0, $items->count() - 1 );
                    $items[$replaceIdx] = $replacement;
                }
            } elseif (count( $boostedIdxs ) > 1) {
                // Keep the first boosted, remove the rest and backfill with non-boosted from subsequent pages
                $keepIdx = $boostedIdxs[0];
                $removeIdxs = array_slice( $boostedIdxs, 1 );
                $toRemove = count( $removeIdxs );
                $exclude = array_values( array_filter( array_map( fn( $x ) => $x instanceof Publication ? ( $x->id ?? null ) : null, iterator_to_array( $items ) ) ) );
                $extraNonBoosted = $this->fetchMoreItems( $orderedPublications, $request, $perPage, $followedSlugs, $exclude, false, 6 );
                foreach ($removeIdxs as $ri) {
                    $replacement = $extraNonBoosted->shift();
                    if ($replacement)
                        $items[$ri] = $replacement;
                    else
                        $items->forget( $ri );
                }
                $items = $items->values();
                // Ensure size 4
                while ($items->count() < (int) $perPage && $extraNonBoosted->count() > 0) {
                    $items->push( $extraNonBoosted->shift() );
                }
                if ($items->count() > (int) $perPage) {
                    $items = $items->slice( 0, (int) $perPage )->values();
                }
            }

            // 3) Inject ad on odd pages (1-based) at position between 2 and 4, not always at first
            if (( $pageNum % 2 ) === 1) {
                if (!$this->hasAdPlaceholder( $items )) {
                    $insertPos = mt_rand( 1, min( 3, max( 1, $items->count() - 1 ) ) );
                    $ad = (object) ['type' => 'ad'];
                    // Remove a non-boosted item nearest to the end to keep size
                    if ($items->count() >= (int) $perPage) {
                        $removedIdx = null;
                        for ($i = $items->count() - 1; $i >= 0; $i--) {
                            if (!( $items[$i] instanceof Publication ))
                                continue;
                            if (!$this->isBoostedItem( $items[$i] )) {
                                $removedIdx = $i;
                                break;
                            }
                        }
                        if ($removedIdx === null)
                            $removedIdx = $items->count() - 1;
                        $items->splice( $removedIdx, 1 );
                    }
                    $items->splice( $insertPos, 0, [$ad] );
                }
            } else {
                // Even pages: ensure no more than 0 ads (remove if any slipped in)
                if ($this->hasAdPlaceholder( $items )) {
                    $items = $items->filter( function ( $it ) {
                        return $it instanceof Publication;
                    } )->values();
                    // backfill from later pages to keep size
                    $exclude = array_values( array_filter( array_map( fn( $x ) => $x instanceof Publication ? ( $x->id ?? null ) : null, iterator_to_array( $items ) ) ) );
                    $extraNonBoosted = $this->fetchMoreItems( $orderedPublications, $request, $perPage, $followedSlugs, $exclude, false, 4 );
                    while ($items->count() < (int) $perPage && $extraNonBoosted->count() > 0) {
                        $items->push( $extraNonBoosted->shift() );
                    }
                }
            }

            // Trim or pad to perPage
            if ($items->count() > (int) $perPage)
                $items = $items->slice( 0, (int) $perPage )->values();
            $paginator->setCollection( $items );
        } catch (\Throwable $e) {
        }
        return $paginator;
    }

    // Build a unique, ordered stream (no duplicate ids), then slice page
    private function uniqueBeforePaginate( $paginator, $orderedPublications, Request $request, $perPage, $followedSlugs )
    {
        try {
            if (!( $paginator instanceof LengthAwarePaginator ))
                return $paginator;
            $currentPage = max( 1, (int) $request->input( 'page', 1 ) );
            $target = (int) $perPage * $currentPage * 2; // buffer to compensate removals
            // Global uniqueness across the feed
            $globalKey = 'feed_unique:' . md5( json_encode( array_diff_key( $request->query(), ['page' => true] ) ) );
            $globalSeen = Cache::get( $globalKey, [] );
            if (!is_array( $globalSeen ))
                $globalSeen = [];
            $seen = [];
            $stream = collect();
            $p = 1;
            $scans = 0;
            while ($stream->count() < $target && $scans < 30) {
                $scans++;
                $req = clone $request;
                $req->merge( ['page' => $p] );
                $pg = addBoosterToPublicationsToFlux( $orderedPublications, $req, $perPage, true );
                if (!( $pg instanceof LengthAwarePaginator ) || $pg->count() === 0)
                    break;
                $pg = $this->attachRepostUserToPublications( $pg );
                $pg = $this->filterRepostsForFollowers( $pg, $followedSlugs );
                $items = $pg->getCollection();
                foreach ($items as $it) {
                    if (!( $it instanceof Publication ))
                        continue; // ignore ads here
                    // If reposted by a followed profile, treat as unique using repost id
                    $transformed = $this->transformIfRepostedForFollowed( $it, $followedSlugs );
                    $id = $transformed->id ?? null;
                    if ($id === null)
                        continue;
                    if (isset( $seen[$id] ) || isset( $globalSeen[$id] ))
                        continue;
                    $seen[$id] = true;
                    $stream->push( $transformed );
                    if ($stream->count() >= $target)
                        break;
                }
                $p++;
            }
            $start = (int) $perPage * ( $currentPage - 1 );
            $slice = $stream->slice( $start, (int) $perPage )->values();
            $paginator->setCollection( $slice );
            // Persist global seen ids for future pages
            foreach ($slice as $it) {
                if ($it instanceof Publication) {
                    $id = $it->id ?? null;
                    if ($id !== null)
                        $globalSeen[$id] = true;
                }
            }
            Cache::put( $globalKey, $globalSeen, now()->addMinutes( 10 ) );
        } catch (\Throwable $e) {
        }
        return $paginator;
    }
    private function attachRepostUserToPublications( $publications )
    {
        try {
            return $publications->through( function ( $publication ) {
                try {
                    $repost = PublicationReposted::where( 'publication_id', $publication->id )
                        ->orderByDesc( 'created_at' )
                        ->first();
                    if ($repost) {
                        if ($repost->profile_type === 'user' && $repost->user_id) {
                            $u = User::select( 'id', 'slug', 'first_name', 'last_name', 'avatar' )
                                ->find( $repost->user_id );
                            if ($u) {
                                $publication->repost_user = [
                                    'type' => 'user',
                                    'id' => $u->id,
                                    'slug' => $u->slug,
                                    'name' => trim( ( $u->first_name ?? '' ) . ' ' . ( $u->last_name ?? '' ) ),
                                    'avatar' => $u->avatar,
                                    'reposted_at' => (string) $repost->created_at,
                                ];
                            }
                        } elseif ($repost->profile_type === 'etablissement' && $repost->etab_id) {
                            $e = Etablissement::select( 'id', 'slug', 'nom', 'logo' )
                                ->find( $repost->etab_id );
                            if ($e) {
                                $publication->repost_user = [
                                    'type' => 'etablissement',
                                    'id' => $e->id,
                                    'slug' => $e->slug,
                                    'name' => $e->nom,
                                    'logo' => $e->logo,
                                    'reposted_at' => (string) $repost->created_at,
                                ];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                }
                return $publication;
            } );
        } catch (\Throwable $e) {
            return $publications;
        }
    }
    private function addUpdateClickedPublication( array $clickedPublications, $user )
    {
        foreach ($clickedPublications as $id) {
            $publication = Publication::find( $id );
            if (!$publication) {
                continue; // Skip if publication is not found
            }
            // return $publication->tags;
            foreach ($publication->tags as $tag) {
                // Check if the tag is already assigned to the user
                $exist = $user->tags_filters()->where( 'tag_id', $tag->id )->first();
                // return $exist;

                if ($exist) {
                    // Increment the compteur value
                    $exist->pivot->increment( 'compteur' );
                } else {
                    // Attach the tag
                    $user->tags_filters()->attach( $tag->id );
                }
            }
        }
    }

    private function addUpdateDetailPublication( $publication, $user )
    {
        foreach ($publication->tags as $tag) {
            // Check if the tag is already assigned to the user
            $exist = $user->tags_filters()->where( 'tag_id', $tag->id )->first();
            // return $exist;

            if ($exist) {
                // Increment the compteur value
                $exist->pivot->increment( 'compteur' );
            } else {
                // Attach the tag
                $user->tags_filters()->attach( $tag->id );
            }
        }
    }

    private function tagsSimilaire( $tagsIds )
    {
        $publications = Publication::where( 'status', 1 )
            ->whereHas( 'tags', function ( $query ) use ( $tagsIds ) {
                $query->whereIn( 'tag_id', $tagsIds );
            } )->get();

        $tags = [];
        foreach ($publications as $publication) {
            foreach ($publication->tags as $tag) {
                $tags[] = $tag->id;
            }
        }
        return array_values( array_unique( $tags ) );
    }


    private function storeRedis( $publicationId, $action, $boostIds = [] )
    {
        $date = now()->toDateString();

        // Proper way to implode an array
        $boostString = implode( ',', $boostIds );

        $key = "stat:{$publicationId}:{$date}:{$action}:{$boostString}";
        Redis::incr( $key );

        foreach ($boostIds as $boostId) {
            $booster = PublicationBooster::find( $boostId );
            $booster->last_display = now();
            $booster->save();
        }
        // $stat = Statistique::firstOrCreate(
        //             ['publication_id' => $publicationId, 'date' => $date]
        //         );
        //         $stat->increment($action, 1);
    }


    public function index( Request $request )
    {

        $profile = $request->query( 'profile' );
        $perPage = $request->query( 'perPage', 4 );
        $page = $request->input( 'page', 1 );
        $isFirstPage = ( $page == 1 );
        $pubId = $request->query( 'pubId' );

        try {
            if ($pubId) {
                // Eager load publication with tags and check existence
                $publication = Publication::with( 'tags' )->find( $pubId );

                if (!$publication || $publication->tags->isEmpty()) {
                    return response()->json( [
                        'status' => 200,
                        'data' => ["publications" => collect()],
                    ] );
                }

                // Cache tag IDs and slugs
                $currentTagIds = $publication->tags->pluck( 'id' )->toArray();

                $currentTagSlugs = $publication->tags->pluck( 'slug' )->toArray();

                // Get similar tags in a single query
                $similarTags = Tags::where( 'status', 1 )
                    ->whereHas( 'publications.tags', function ( $q ) use ( $currentTagIds ) {
                        $q->whereIn( 'tags_flink.id', $currentTagIds );
                    } )
                    ->whereNotIn( 'id', $currentTagIds )
                    ->get( ['id', 'slug'] );


                $similarTagIds = $similarTags->pluck( 'id' )->toArray();
                $similarTagSlugs = $similarTags->pluck( 'slug' )->toArray();

                // Generate search terms for partial matches
                $searchTerms = [];
                foreach ($currentTagSlugs as $tag) {
                    $searchTerms = array_unique( array_merge( $searchTerms, generateSlugCombinations( $tag ) ) );
                }

                // Get partial match tags in a single query
                $partialTagSlugs = Tags::where( 'status', 1 )
                    ->whereNotIn( 'id', $currentTagIds )
                    ->where( function ( $query ) use ( $searchTerms ) {
                        foreach ($searchTerms as $term) {
                            $query->orWhere( 'slug', 'LIKE', '%' . $term . '%' );
                        }
                    } )
                    ->pluck( 'slug' )
                    ->toArray();

                // Base query for publications
                $publicationsQuery = Publication::query()
                    ->select( [
                        'id',
                        'is_marketplace',
                        'slug',
                        'reference',
                        'cat_g_id',
                        'user_id',
                        'etab_id',
                        'pays_id',
                        'ville_id',
                        'region_id',
                        'tele',
                        'tele_whatsapp',
                        'contact',
                        'description',
                        'image_default',
                        'image_square',
                        'images',
                        'video_origine',
                        'video_compresse',
                        'prix',
                        'prixPromo',
                        'disponibilite',
                        'disponibilite_date',
                        'created_at',
                        'score'
                    ] )
                    ->where( 'status', 1 )
                    ->where( 'id', '!=', $publication->id )
                    ->withCount( [
                        'publications_booster as booster' => function ( $query ) {
                            $query->where( 'date_fin', '>', now() );
                        }
                    ] )
                    ->with( [
                        'pays:id,slug,name',
                        'ville:id,slug,name,pays_id',
                        'region:id,slug,name,ville_id',
                        'tags:id,name,slug',
                        'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                        'etablissement:id,nom,logo,slug,is_verified,created_at',
                        'modes_laivraison:id,name',
                        'statistiques' => function ( $query ) {
                            $query->selectRaw( '
                                publication_id,
                                SUM(click_contact) as click_contact,
                                SUM(click_tele) as click_tele,
                                SUM(click_tele_whatsapp) as click_tele_whatsapp,
                                SUM(click_partage) as click_partage,
                                SUM(click_detail) as click_detail,
                                SUM(vues) as vues
                            ' )->groupBy( 'publication_id' );
                        },
                        'user_sauvgardes:id,first_name,last_name',
                        'etab_sauvgardes:id,nom',
                        'publications_booster.shopping_publications',
                        'publications_booster' => function ( $query ) {
                            $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                                ->where( 'date_fin', '>', now() )
                                ->orderByDesc( 'id' );
                        },
                    ] )
                    // Prioritize reposted publications first
                    ->selectRaw( 'EXISTS (SELECT 1 FROM publications_reposted pr WHERE pr.publication_id = publications_flink.id) as is_reposted' )
                    ->orderByDesc( 'is_reposted' );


                // Apply tag filtering
                $publicationsQuery->where( function ( $query ) use ( $currentTagIds, $similarTagIds, $partialTagSlugs ) {
                    $query->whereHas( 'tags', function ( $q ) use ( $currentTagIds ) {
                        $q->whereIn( 'publications_tags_flink.tag_id', $currentTagIds );
                    } );

                    if (!empty( $similarTagIds )) {
                        $query->orWhereHas( 'tags', function ( $q ) use ( $similarTagIds ) {
                            $q->whereIn( 'publications_tags_flink.tag_id', $similarTagIds );
                        } );
                    }

                    if (!empty( $partialTagSlugs )) {
                        $query->orWhereHas( 'tags', function ( $q ) use ( $partialTagSlugs ) {
                            $q->whereIn( 'tags_flink.slug', $partialTagSlugs );
                        } );
                    }
                } );

                // Add scoring for matches
                $publicationsQuery->selectRaw(
                    '
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM publications_tags_flink 
                            WHERE publications_tags_flink.publication_id = publications_flink.id 
                            AND publications_tags_flink.tag_id IN (' . implode( ',', $currentTagIds ) . ')
                        ) THEN 3 
                        WHEN EXISTS (
                            SELECT 1 FROM publications_tags_flink 
                            WHERE publications_tags_flink.publication_id = publications_flink.id 
                            AND publications_tags_flink.tag_id IN (' . ( !empty( $similarTagIds ) ? implode( ',', $similarTagIds ) : 'NULL' ) . ')
                        ) THEN 2 
                        WHEN EXISTS (
                            SELECT 1 FROM publications_tags_flink 
                            JOIN tags_flink ON publications_tags_flink.tag_id = tags_flink.id
                            WHERE publications_tags_flink.publication_id = publications_flink.id 
                            AND tags_flink.slug IN ("' . ( !empty( $partialTagSlugs ) ? implode( ',', $partialTagSlugs ) : 'NULL' ) . '")
                        ) THEN 1 
                        ELSE 0 
                    END as is_direct_match,
                    (
                        SELECT COUNT(*) 
                        FROM publications_tags_flink 
                        WHERE publications_tags_flink.publication_id = publications_flink.id 
                        AND publications_tags_flink.tag_id IN (' . implode( ',', $currentTagIds ) . ')
                    ) as direct_tag_count'
                )
                    ->orderByDesc( 'is_direct_match' )
                    ->orderByDesc( 'direct_tag_count' );

                // return $publicationsQuery->count();

                // Handle profile preferences if provided
                $profileObject = null;
                if ($profile) {
                    $profileObject = User::where( 'slug', $profile )
                        ->with( ['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'] )
                        ->first();

                    if (!$profileObject) {
                        $profileObject = Etablissement::where( 'slug', $profile )
                            ->with( ['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'] )
                            ->first();
                    }
                }

                if ($publicationsQuery->count() > 0) {
                    // Get and order publications
                    $orderedPublications = getOrderedPublicationsWithPreferencesTest( $publicationsQuery, $profileObject?->recommendations() );
                    $publications = addBoosterToPublicationsToFlux( $orderedPublications, $request, $perPage, true );
                } else {
                    $publications = $publicationsQuery->paginate( 2 );
                }
                // Attach repost_user info to each publication (latest reposter)
                $publications = $this->attachRepostUserToPublications( $publications );
                // Limit reposts visibility to followers of the reposter
                $followedSlugs = [];
                if ($profileObject) {
                    if (method_exists( $profileObject, 'followingUsers' ) && method_exists( $profileObject, 'followingEtablissements' )) {
                        $followedSlugs = array_merge(
                            $profileObject->followingEtablissements->pluck( 'slug' )->toArray(),
                            $profileObject->followingUsers->pluck( 'slug' )->toArray()
                        );
                    }
                }
                $publications = $this->filterRepostsForFollowers( $publications, $followedSlugs );
                // Ensure page remains full by topping up from subsequent pages if needed
                $publications = $this->topUpAfterFilter( $publications, $orderedPublications, $request, $perPage, $followedSlugs );
                $publications = $this->adjustRepostedScores( $publications );
                // Ensure no duplicate normal publications appear
                $publications = $this->deduplicatePublications( $publications );
                $publications = $this->ensureFirstItemIsRealPublication( $publications );
                // Enforce uniqueness across the whole feed stream BEFORE slicing the page
                $publications = $this->uniqueBeforePaginate( $publications, $orderedPublications, $request, $perPage, $followedSlugs );
                // After unique slicing, enforce 1 boosted per page and odd-page ad injection
                $publications = $this->finalizePageComposition( $publications, $orderedPublications, $request, $perPage, $followedSlugs );

                return response()->json( [
                    'status' => 200,
                    'data' => ["publications" => $publications],
                ] );
            }
            // Get basic publications data with eager loading of boosters
            $publications = Publication::select(
                'id',
                'slug',
                'is_marketplace',
                'reference',
                'cat_g_id',
                'catalogue_id',
                'user_id',
                'etab_id',
                'pays_id',
                'ville_id',
                'region_id',
                'tele',
                'tele_whatsapp',
                'contact',
                'description',
                'image_default',
                'image_square',
                'images',
                'video_origine',
                'video_compresse',
                'prix',
                'prixPromo',
                'disponibilite',
                'disponibilite_date',
                'created_at',
                'score'
            )
                ->where( 'status', 1 )
                ->withCount( [
                    'publications_booster as booster' => function ( $query ) {
                        $query->where( 'date_fin', '>', Carbon::now() );
                    }
                ] )
                ->having( 'booster', 0 )
                ->with( [
                    'pays:id,slug,name',
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'tags:id,name,slug',
                    'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                    'etablissement:id,nom,logo,slug,is_verified,created_at',
                    'modes_laivraison:id,name',
                    'statistiques' => function ( $query ) {
                        $query->selectRaw( '
                        publication_id,
                        SUM(click_contact) as click_contact,
                        SUM(click_tele) as click_tele,
                        SUM(click_tele_whatsapp) as click_tele_whatsapp,
                        SUM(click_partage) as click_partage,
                        SUM(click_detail) as click_detail,
                        SUM(vues) as vues
                    ' )->groupBy( 'publication_id' );
                    },
                    'user_sauvgardes:id,first_name,last_name',
                    'etab_sauvgardes:id,nom',
                ] )
                // Prioritize reposted publications first
                ->selectRaw( 'EXISTS (SELECT 1 FROM publications_reposted pr WHERE pr.publication_id = publications_flink.id) as is_reposted' )
                ->orderByDesc( 'is_reposted' );

            // Debug: Log first 100 non-boosted publication IDs before any sorting/pagination (exclude boosted)
            try {
                $first100Ids = ( clone $publications )
                    ->limit( 100 )
                    ->pluck( 'id' )
                    ->toArray();
                // Log::info('[index] First100NonBoostedIDs', ['ids' => $first100Ids]);
            } catch (\Throwable $e) {
            }


            // Get profile data if needed
            $profileObject = null;
            $followedSlugs = [];
            $savedPublicationIds = [];

            if ($profile) {
                $profileObject = User::where( 'slug', $profile )
                    ->with( ['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'] )
                    ->first();

                if (!$profileObject) {
                    $profileObject = Etablissement::where( 'slug', $profile )
                        ->with( ['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'] )
                        ->first();
                }

                if ($profileObject) {
                    // Get all followed slugs at once
                    if (method_exists( $profileObject, 'followingUsers' ) && method_exists( $profileObject, 'followingEtablissements' )) {
                        $followedSlugs = array_merge(
                            $profileObject->followingEtablissements->pluck( 'slug' )->toArray(),
                            $profileObject->followingUsers->pluck( 'slug' )->toArray()
                        );
                    }

                    // Get all saved publication IDs at once
                    if (method_exists( $profileObject, 'publications_sauvgarde' )) {
                        $savedPublicationIds = $profileObject->publications_sauvgarde->pluck( 'id' )->toArray();
                    }
                }
            }


            // if ($isFirstPage) {
            //     $publications = getDynamicPublicationsOptimized($publications, $profileObject);
            //     Cache::put('optimized_publications', $publications, now()->addMinutes(10));
            // } else {
            //     $cachedPublications = Cache::get('optimized_publications');
            //     if ($cachedPublications) {
            //         $publications = $cachedPublications;
            //     }
            // }

            // // Manual pagination
            // $total = $publications->count();
            // $paginatedItems = $publications->slice(($page - 1) * $perPage, $perPage)->values();

            // $publications = new LengthAwarePaginator(
            //     $paginatedItems,
            //     $total,
            //     $perPage,
            //     $page,
            //     ['path' => request()->url(), 'query' => request()->query()]
            // );

            if ($profileObject) {
                $orderedPublications = getOrderedPublicationsWithPreferencesTest( $publications, $profileObject->recommendations() );
            } else {
                $orderedPublications = getOrderedPublicationsWithPreferencesTest( $publications, null );
            }

            // $publications = $orderedPublications->paginate(4);
            $publications = addBoosterToPublicationsToFlux( $orderedPublications, $request, $perPage, true );
            // Attach repost_user info to each publication (latest reposter)
            $publications = $this->attachRepostUserToPublications( $publications );
            // return $publications;


            // $publications = $publications->paginate($perPage);


            // 2. Transform using through() (cleanest way)
            $publications->through( function ( $publication ) use ( $profile, $followedSlugs, $savedPublicationIds ) {
                if ($publication instanceof Publication) {
                    $publication->sauvgardes = $publication->user_sauvgardes->count() + $publication->etab_sauvgardes->count();

                    // Always set isFollowed and isSavedByProfile fields, even if profile is null
                    if ($profile) {
                        // Determine if profile follows the post creator
                        // If etab_id is null -> post created by user, check if profile follows the user
                        // If etab_id is not null -> post created by establishment, check if profile follows the establishment
                        if ($publication->etab_id) {
                            // Post created by establishment - check if profile follows the establishment
                            $publication->isFollowed = in_array( $publication->etablissement->slug ?? '', $followedSlugs ?? [] );
                        } else {
                            // Post created by user - check if profile follows the user
                            $publication->isFollowed = in_array( $publication->user->slug ?? '', $followedSlugs ?? [] );
                        }
                        $publication->isSavedByProfile = in_array( $publication->id, $savedPublicationIds ?? [] );
                    } else {
                        // If no profile, set default values
                        $publication->isFollowed = false;
                        $publication->isSavedByProfile = false;
                    }

                    // Attach latest repost user info
                    try {
                        $repost = PublicationReposted::where( 'publication_id', $publication->id )
                            ->orderByDesc( 'created_at' )
                            ->first();
                        if ($repost) {
                            if ($repost->profile_type === 'user' && $repost->user_id) {
                                $u = User::select( 'id', 'slug', 'first_name', 'last_name', 'avatar' )
                                    ->find( $repost->user_id );
                                if ($u) {
                                    $publication->repost_user = [
                                        'type' => 'user',
                                        'id' => $u->id,
                                        'slug' => $u->slug,
                                        'name' => trim( ( $u->first_name ?? '' ) . ' ' . ( $u->last_name ?? '' ) ),
                                        'avatar' => $u->avatar,
                                        'reposted_at' => (string) $repost->created_at,
                                    ];
                                }
                            } elseif ($repost->profile_type === 'etablissement' && $repost->etab_id) {
                                $e = Etablissement::select( 'id', 'slug', 'nom', 'logo' )
                                    ->find( $repost->etab_id );
                                if ($e) {
                                    $publication->repost_user = [
                                        'type' => 'etablissement',
                                        'id' => $e->id,
                                        'slug' => $e->slug,
                                        'name' => $e->nom,
                                        'logo' => $e->logo,
                                        'reposted_at' => (string) $repost->created_at,
                                    ];
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }


                return $publication;
            } );
            // Now $publications contains your transformed, paginated results


            // Increment views for visible publications only
            // foreach ($publications->items() as $publication) {
            //     $this->storeRedis($publication->id, 'vues', $publication->publications_booster()->where('date_fin', '>', Carbon::now())->orderBy('last_display', 'asc')->limit(1)->get()->pluck('id')->toArray());
            // }

            // Limit reposts visibility to followers of the reposter
            $publications = $this->filterRepostsForFollowers( $publications, $followedSlugs );
            // Ensure page remains full by topping up from subsequent pages if needed
            $publications = $this->topUpAfterFilter( $publications, $orderedPublications, $request, $perPage, $followedSlugs );
            // Adjust scores for reposted items without changing ordering/filtering logic
            $publications = $this->adjustRepostedScores( $publications );
            // Make sure the first item is a real publication (ads/placeholder not first)
            // Ensure no duplicate normal publications appear
            $publications = $this->deduplicatePublications( $publications );
            $publications = $this->ensureFirstItemIsRealPublication( $publications );
            // Then place the placeholder at positions 2..4 cycling per page
            $publications = $this->positionAdPlaceholder( $publications, $request, $perPage );
            // Final safety: de-duplicate again after any rearrangement
            $publications = $this->deduplicatePublications( $publications );

            // Final step: Ensure all publications have isFollowed and isSavedByProfile fields
            // This is needed because some methods above might add new publications without these fields
            $publications->getCollection()->transform( function ( $publication ) use ( $profile, $followedSlugs, $savedPublicationIds ) {
                if ($publication instanceof Publication) {
                    // Only set if not already set (to avoid overriding existing values)
                    if (!isset( $publication->isFollowed )) {
                        if ($profile) {
                            if ($publication->etab_id) {
                                $publication->isFollowed = in_array( $publication->etablissement->slug ?? '', $followedSlugs ?? [] );
                            } else {
                                $publication->isFollowed = in_array( $publication->user->slug ?? '', $followedSlugs ?? [] );
                            }
                        } else {
                            $publication->isFollowed = false;
                        }
                    }

                    if (!isset( $publication->isSavedByProfile )) {
                        if ($profile) {
                            $publication->isSavedByProfile = in_array( $publication->id, $savedPublicationIds ?? [] );
                        } else {
                            $publication->isSavedByProfile = false;
                        }
                    }
                }
                return $publication;
            } );

            return response()->json( [
                'status' => 201,
                'data' => ["publications" => $publications],
            ] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()] );
        }
    }

    // public function indexTest(Request $request) {

    //     $profile = $request->query('profile');
    //     $perPage = $request->query('perPage', 4);
    //     $page = $request->input('page', 1);
    //     $isFirstPage = ($page == 1);
    //     $pubId = $request->query('pubId');

    //     try {
    //         if ($pubId) {
    //             // Eager load publication with tags and check existence
    //             $publication = Publication::with('tags')->find($pubId);

    //             if (!$publication || $publication->tags->isEmpty()) {
    //                 return response()->json([
    //                     'status' => 200,
    //                     'data' => ["publications" => collect()],
    //                 ]);
    //             }

    //             // Cache tag IDs and slugs
    //             $currentTagIds = $publication->tags->pluck('id')->toArray();

    //             $currentTagSlugs = $publication->tags->pluck('slug')->toArray();

    //             // Get similar tags in a single query
    //             $similarTags = Tags::where('status', 1)
    //                 ->whereHas('publications.tags', function($q) use ($currentTagIds) {
    //                     $q->whereIn('tags_flink.id', $currentTagIds);
    //                 })
    //                 ->whereNotIn('id', $currentTagIds)
    //                 ->get(['id', 'slug']);


    //             $similarTagIds = $similarTags->pluck('id')->toArray();
    //             $similarTagSlugs = $similarTags->pluck('slug')->toArray();

    //             // Generate search terms for partial matches
    //             $searchTerms = [];
    //             foreach ($currentTagSlugs as $tag) {
    //                 $searchTerms = array_unique(array_merge($searchTerms, generateSlugCombinations($tag)));
    //             }

    //             // Get partial match tags in a single query
    //             $partialTagSlugs = Tags::where('status', 1)
    //                 ->whereNotIn('id', $currentTagIds)
    //                 ->where(function($query) use ($searchTerms) {
    //                     foreach ($searchTerms as $term) {
    //                         $query->orWhere('slug', 'LIKE', '%'.$term.'%');
    //                     }
    //                 })
    //                 ->pluck('slug')
    //                 ->toArray();

    //             // Base query for publications
    //             $publicationsQuery = Publication::query()
    //                 ->select([
    //                     'id', 'is_marketplace', 'slug', 'reference', 'cat_g_id', 'user_id', 'etab_id', 
    //                     'pays_id', 'ville_id', 'region_id', 'tele', 'tele_whatsapp', 
    //                     'contact', 'description', 'image_default', 'images', 
    //                     'video_origine', 'video_compresse', 'prix', 'prixPromo', 
    //                     'disponibilite', 'disponibilite_date', 'created_at', 'score'
    //                 ])
    //                 ->where('status', 1)
    //                 ->where('id', '!=', $publication->id)
    //                 ->withCount([
    //                     'publications_booster as booster' => function ($query) {
    //                         $query->where('date_fin', '>', now());
    //                     }
    //                 ])
    //                 ->with([
    //                     'pays:id,slug,name',
    //                     'ville:id,slug,name,pays_id',
    //                     'region:id,slug,name,ville_id',
    //                     'tags:id,name,slug',
    //                     'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
    //                     'etablissement:id,nom,logo,slug,is_verified,created_at',
    //                     'modes_laivraison:id,name',
    //                     'statistiques' => function ($query) {
    //                         $query->selectRaw('
    //                             publication_id,
    //                             SUM(click_contact) as click_contact,
    //                             SUM(click_tele) as click_tele,
    //                             SUM(click_tele_whatsapp) as click_tele_whatsapp,
    //                             SUM(click_partage) as click_partage,
    //                             SUM(click_detail) as click_detail,
    //                             SUM(vues) as vues
    //                         ')->groupBy('publication_id');
    //                     },
    //                     'user_sauvgardes:id,first_name,last_name',
    //                     'etab_sauvgardes:id,nom',
    //                     'publications_booster.shopping_publications',
    //                     'publications_booster' => function($query) {
    //                         $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message')
    //                             ->where('date_fin', '>', now())
    //                             ->orderByDesc('id');
    //                     },
    //                 ]);


    //             // Apply tag filtering
    //             $publicationsQuery->where(function($query) use ($currentTagIds, $similarTagIds, $partialTagSlugs) {
    //                 $query->whereHas('tags', function($q) use ($currentTagIds) {
    //                     $q->whereIn('publications_tags_flink.tag_id', $currentTagIds);
    //                 });

    //                 if (!empty($similarTagIds)) {
    //                     $query->orWhereHas('tags', function($q) use ($similarTagIds) {
    //                         $q->whereIn('publications_tags_flink.tag_id', $similarTagIds);
    //                     });
    //                 }

    //                 if (!empty($partialTagSlugs)) {
    //                     $query->orWhereHas('tags', function($q) use ($partialTagSlugs) {
    //                         $q->whereIn('tags_flink.slug', $partialTagSlugs);
    //                     });
    //                 }
    //             });

    //             // Add scoring for matches
    //             $publicationsQuery->selectRaw('
    //                 CASE 
    //                     WHEN EXISTS (
    //                         SELECT 1 FROM publications_tags_flink 
    //                         WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                         AND publications_tags_flink.tag_id IN ('.implode(',', $currentTagIds).')
    //                     ) THEN 3 
    //                     WHEN EXISTS (
    //                         SELECT 1 FROM publications_tags_flink 
    //                         WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                         AND publications_tags_flink.tag_id IN ('.(!empty($similarTagIds) ? implode(',', $similarTagIds) : 'NULL').')
    //                     ) THEN 2 
    //                     WHEN EXISTS (
    //                         SELECT 1 FROM publications_tags_flink 
    //                         JOIN tags_flink ON publications_tags_flink.tag_id = tags_flink.id
    //                         WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                         AND tags_flink.slug IN ("'.(!empty($partialTagSlugs) ? implode(',', $partialTagSlugs) : 'NULL').'")
    //                     ) THEN 1 
    //                     ELSE 0 
    //                 END as is_direct_match,
    //                 (
    //                     SELECT COUNT(*) 
    //                     FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN ('.implode(',', $currentTagIds).')
    //                 ) as direct_tag_count'
    //             )
    //             ->orderByDesc('is_direct_match')
    //             ->orderByDesc('direct_tag_count');

    //             // return $publicationsQuery->count();

    //             // Handle profile preferences if provided
    //             $profileObject = null;
    //             if ($profile) {
    //                 $profileObject = User::where('slug', $profile)
    //                     ->with(['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'])
    //                     ->first();

    //                 if (!$profileObject) {
    //                     $profileObject = Etablissement::where('slug', $profile)
    //                         ->with(['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'])
    //                         ->first();
    //                 }
    //             }

    //             if($publicationsQuery->count() > 0) {
    //                 // Get and order publications
    //                 $orderedPublications = getOrderedPublicationsWithPreferencesTest($publicationsQuery, $profileObject?->recommendations());
    //                 $publications = addBoosterToPublicationsToFlux($orderedPublications, $request, $perPage, true);
    //             } else {
    //                 $publications = $publicationsQuery->paginate(2);
    //             }


    //             return response()->json([
    //                 'status' => 200,
    //                 'data' => ["publications" => $publications],
    //             ]);
    //         }
    //         // Get basic publications data with eager loading of boosters
    //         $publications = Publication::select(
    //             'id', 'slug', 'is_marketplace', 'reference', 'cat_g_id', 'user_id', 'etab_id', 'pays_id', 'ville_id', 'region_id', 
    //             'tele', 'tele_whatsapp', 'contact', 'description', 'image_default', 'images',
    //             'video_origine', 'video_compresse', 'prix', 'prixPromo', 'disponibilite', 
    //             'disponibilite_date', 'created_at', 'score'
    //         )
    //         ->where('status', 1)
    //         ->withCount([
    //             'publications_booster as booster' => function ($query) {
    //                 $query->where('date_fin', '>', Carbon::now());
    //             }
    //         ])
    //         ->having('booster', 0)
    //         ->with([
    //             'pays:id,slug,name',
    //             'ville:id,slug,name,pays_id',
    //             'region:id,slug,name,ville_id',
    //             'tags:id,name,slug',
    //             'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
    //             'etablissement:id,nom,logo,slug,is_verified,created_at',
    //             'modes_laivraison:id,name',
    //             'statistiques' => function ($query) {
    //                 $query->selectRaw('
    //                     publication_id,
    //                     SUM(click_contact) as click_contact,
    //                     SUM(click_tele) as click_tele,
    //                     SUM(click_tele_whatsapp) as click_tele_whatsapp,
    //                     SUM(click_partage) as click_partage,
    //                     SUM(click_detail) as click_detail,
    //                     SUM(vues) as vues
    //                 ')->groupBy('publication_id');
    //             },
    //             'user_sauvgardes:id,first_name,last_name', 
    //             'etab_sauvgardes:id,nom',
    //             // Eagerly load active boosters with just the fields we need
    //             'publications_booster.shopping_publications',
    //             'publications_booster' => function($query) {
    //                 $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message')
    //                       ->where('date_fin', '>', Carbon::now())->orderBy('id', 'desc');
    //             },
    //         ]);


    //         // Get profile data if needed
    //         $profileObject = null;
    //         $followedSlugs = [];
    //         $savedPublicationIds = [];

    //         if ($profile) {
    //             $profileObject = User::where('slug', $profile)
    //                 ->with(['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'])
    //                 ->first();

    //             if (!$profileObject) {
    //                 $profileObject = Etablissement::where('slug', $profile)
    //                     ->with(['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'])
    //                     ->first();
    //             }

    //             if ($profileObject) {
    //                 // Get all followed slugs at once
    //                 if (method_exists($profileObject, 'followingUsers') && method_exists($profileObject, 'followingEtablissements')) {
    //                     $followedSlugs = array_merge(
    //                         $profileObject->followingEtablissements->pluck('slug')->toArray(),
    //                         $profileObject->followingUsers->pluck('slug')->toArray()
    //                     );
    //                 }

    //                 // Get all saved publication IDs at once
    //                 if (method_exists($profileObject, 'publications_sauvgarde')) {
    //                     $savedPublicationIds = $profileObject->publications_sauvgarde->pluck('id')->toArray();
    //                 }
    //             }
    //         }


    //         // if ($isFirstPage) {
    //         //     $publications = getDynamicPublicationsOptimized($publications, $profileObject);
    //         //     Cache::put('optimized_publications', $publications, now()->addMinutes(10));
    //         // } else {
    //         //     $cachedPublications = Cache::get('optimized_publications');
    //         //     if ($cachedPublications) {
    //         //         $publications = $cachedPublications;
    //         //     }
    //         // }

    //         // // Manual pagination
    //         // $total = $publications->count();
    //         // $paginatedItems = $publications->slice(($page - 1) * $perPage, $perPage)->values();

    //         // $publications = new LengthAwarePaginator(
    //         //     $paginatedItems,
    //         //     $total,
    //         //     $perPage,
    //         //     $page,
    //         //     ['path' => request()->url(), 'query' => request()->query()]
    //         // );

    //         if($profileObject) {
    //             $orderedPublications = getOrderedPublicationsWithPreferencesTest($publications, $profileObject->recommendations());
    //         } else {
    //             $orderedPublications = getOrderedPublicationsWithPreferencesTest($publications, null);
    //         }

    //         // $publications = $orderedPublications->paginate(4);
    //         $publications = addBoosterToPublicationsToFluxTest($orderedPublications, $request, $perPage, true);
    //         // return $publications;


    //         // $publications = $publications->paginate($perPage);


    //         // 2. Transform using through() (cleanest way)
    //         $publications->through(function ($publication) use ($profile, $followedSlugs, $savedPublicationIds) {
    //             if($publication instanceof Publication) {
    //                 $publication->sauvgardes = $publication->user_sauvgardes->count() + $publication->etab_sauvgardes->count();

    //                 if ($profile) {
    //                     $ownerSlug = $publication->etablissement->slug ?? $publication->user->slug ?? null;
    //                     $publication->isFollowed = (bool) $ownerSlug && in_array($ownerSlug, $followedSlugs);
    //                     $publication->isSavedByProfile = in_array($publication->id, $savedPublicationIds);
    //                 }
    //             }


    //             return $publication;
    //         });
    //         // Now $publications contains your transformed, paginated results


    //         // Increment views for visible publications only
    //         // foreach ($publications->items() as $publication) {
    //         //     $this->storeRedis($publication->id, 'vues', $publication->publications_booster()->where('date_fin', '>', Carbon::now())->orderBy('last_display', 'asc')->limit(1)->get()->pluck('id')->toArray());
    //         // }

    //         return response()->json([
    //             'status' => 201, 
    //             'data' => ["publications" => $publications],
    //         ]);

    //     } catch (\Exception $e) {
    //         Log::error("flux error : " . $e->getMessage() . ' at line ' . $e->getLine());
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage() . ' at line ' . $e->getLine()]);
    //     }
    // }


    public function keywordsTags( Request $request )
    {
        // Log::info("Call to Index Flux keywordsTags : ". $request->ip());
        $profile = $request->query( 'profile' );
        try {
            $popular_keywords = Tags::select( 'id', 'name', 'slug' )
                ->where( 'status', 1 )
                ->whereHas( 'publications', function ( $query ) {
                    $query->where( 'status', 1 );
                } )
                ->whereHas( 'publications', function ( $query ) {
                    $query->havingRaw( 'COUNT(publications_flink.id) >= 4' );
                } )
                ->orderByDesc( 'compteur_publications' )
                ->get();

            if ($profile && $profile != "null") {
                if (User::where( 'slug', $profile )->first()) {
                    $profileObject = User::where( 'slug', $profile )->with( 'tags_filters' )->first();
                } else if (Etablissement::where( 'slug', $profile )->first()) {
                    $profileObject = Etablissement::where( 'slug', $profile )->with( 'tags_filters' )->first();
                }
                $user_tags = $profileObject->tags_filters()
                    ->select(
                        'tags_flink.id',
                        'tags_flink.name',
                        'tags_flink.slug'
                    )
                    ->where( 'tags_flink.status', 1 )
                    ->whereHas( 'publications', function ( $query ) {
                        $query->where( 'publications_flink.status', 1 );
                    } )
                    ->withCount( 'publications' )
                    ->having( 'publications_count', '>=', 4 )
                    ->orderByRaw( 'user_tags_filters_flink.updated_at DESC' )
                    ->get();

                $tags_keywords = getTagsOrdrer( $popular_keywords, null, $user_tags, null, 12 );

            } else {
                $tags_keywords = getTagsOrdrer( $popular_keywords, null, null, null, 12 );
            }


            return response()->json( [
                'status' => 201,
                'data' =>
                    [
                        "keywords" => $tags_keywords,
                    ]
            ] );
        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()] );
        }
    }


    public function marketplacePublications( Request $request )
    {
        $search = $request->get( 'search' );
        $pays = $request->get( 'pays' );
        $ville = $request->get( 'ville' );
        $region = $request->get( 'region' );
        $profile = $request->get( 'profile' );

        // Add latitude and longitude to pays and ville and region
        $paysId = null;
        $villeId = null;
        $regionId = null;
        if ($pays) {
            $selectedPays = Pays::where( 'slug', $pays )->first();
            if ($selectedPays) {
                $paysId = $selectedPays->id;
                $selectedPays->compteur_search += 1;
                $selectedPays->save();
                if (!$selectedPays->latitude && !$selectedPays->longitude) {
                    try {
                        $data = setLatitudeLongitude( $selectedPays->name, 'country' );
                        if ($data) {
                            $selectedPays->latitude = $data[0]['lat'];
                            $selectedPays->longitude = $data[0]['lon'];
                            $selectedPays->save();
                        }
                    } catch (\Exception $e) {
                        // Silently ignore geocoding errors
                    }
                }
            }

        }
        if ($ville && $selectedPays) {
            $selectedVille = Ville::where( 'pays_id', $selectedPays->id )->where( 'slug', $ville )->first();
            if ($selectedVille) {
                $villeId = $selectedVille->id;
                $selectedVille->compteur_search += 1;
                $selectedVille->save();
                if (!$selectedVille->latitude && !$selectedVille->longitude) {
                    try {
                        $data = setLatitudeLongitude( $selectedVille->name, 'city' );
                        if ($data) {
                            $selectedVille->latitude = $data[0]['lat'];
                            $selectedVille->longitude = $data[0]['lon'];
                            $selectedVille->save();
                        }
                    } catch (\Exception $e) {
                        // Silently ignore geocoding errors
                    }
                }
            }

            if ($region && $selectedVille) {
                $selectedRegion = Region::where( 'ville_id', $selectedVille->id )->where( 'slug', $region )->first();
                if ($selectedRegion) {
                    $regionId = $selectedRegion->id;
                    $selectedRegion->compteur_search += 1;
                    $selectedRegion->save();
                    if (!$selectedRegion->latitude && !$selectedRegion->longitude) {
                        try {
                            $data = setLatitudeLongitude( $selectedRegion->name . "," . $selectedVille->name, 'suburb' );
                            if ($data) {
                                $selectedRegion->latitude = $data[0]['lat'];
                                $selectedRegion->longitude = $data[0]['lon'];
                                $selectedRegion->save();
                            }
                        } catch (\Exception $e) {
                            // Silently ignore geocoding errors
                        }
                    }
                }

            }
        }
        $searchTagName = null;
        if ($search) {
            $tag = Tags::where( 'slug', $search )->first();
            if ($tag) {
                $searchTagName = $tag->name;
                $tag->compteur_search += 1;
                $tag->save();
                if ($profile) {
                    $user = User::where( 'slug', $profile )->first();
                    $etablissement = Etablissement::where( 'slug', $profile )->first();
                    if ($user) {
                        // Check if the tag is already assigned to the user (without location fields)
                        $exist = $user->tags_filters()->where( 'tag_id', $tag->id )->first();

                        if ($exist) {
                            // Increment the compteur value
                            $exist->pivot->increment( 'compteur' );
                        } else {
                            // Attach the tag (without location fields)
                            $user->tags_filters()->attach( $tag->id, ['compteur' => 1] );
                        }
                    } else if ($etablissement) {
                        // Check if the tag is already assigned to the etablissement (without location fields)
                        $exist = $etablissement->tags_filters()->where( 'tag_id', $tag->id )->first();

                        if ($exist) {
                            // Increment the compteur value
                            $exist->pivot->increment( 'compteur' );
                        } else {
                            // Attach the tag (without location fields)
                            $etablissement->tags_filters()->attach( $tag->id, ['compteur' => 1] );
                        }
                    }
                }
            } else {
                // Generate a possible name from the search string
                $generatedName = Str::of( $search )->replace( '-', ' ' )->title();
                // Check if a tag exists with the same name (to avoid conflicts)
                $existingTagByName = Tags::where( 'name', $generatedName )->first();
                if (!$existingTagByName) {
                    $tag = new Tags();
                    $tag->name = $generatedName;
                    $searchTagName = $generatedName;
                    $tag->slug = $search;
                    $tag->save();
                }
            }
        }

        try {

            $publicationsQuery = Publication::select(
                'id',
                'slug',
                'user_id',
                'etab_id',
                'ville_id',
                'region_id',
                'image_default',
                'image_square',
                'prix',
                'prixPromo',
                'score'
            )
                ->where( 'status', 1 )
                ->where( 'is_marketplace', 1 )
                ->whereNotNull( 'image_default' )
                ->withCount( [
                    'publications_booster as booster' => function ( $query ) {
                        $query->where( 'date_fin', '>', Carbon::now() );
                    }
                ] )
                ->having( 'booster', 0 )
                ->with( [
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug',
                    'etablissement:id,nom,slug',
                    'tags:id,name,slug',
                    'publications_booster.shopping_publications',
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', Carbon::now() )->orderBy( 'id', 'desc' );
                    },
                ] );

            if ($profile) {
                $profileObject = null;
                $user = User::where( 'slug', $profile )->first();
                $etablissement = Etablissement::where( 'slug', $profile )->first();
                if (User::where( 'slug', $profile )->first()) {
                    $profileObject = User::where( 'slug', $profile )->with( 'tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations' )->first();
                } else if (Etablissement::where( 'slug', $profile )->first()) {
                    $profileObject = Etablissement::where( 'slug', $profile )->with( 'tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingEtablissements', 'recommendations' )->first();
                }
            } else {
                $profileObject = null;
            }


            if ($profileObject) {
                $pourToiPub = $profileObject->recommendations()->where( 'status', 1 )->where( 'is_marketplace', 1 );
                if ($search && $search !== "marketplace" && $tag) {
                    $pourToiPub->whereHas( 'tags', function ( $query ) use ( $tag ) {
                        $query->where( 'tags_flink.id', $tag->id ); // ✅ Specify table name
                    } );
                }
                $pourToiPub = $pourToiPub->withCount( [
                    'publications_booster as booster' => function ( $query ) {
                        $query->where( 'date_fin', '>', Carbon::now() );
                    }
                ] )->with( [
                            'ville:id,slug,name,pays_id',
                            'region:id,slug,name,ville_id',
                            'user:id,first_name,last_name,slug',
                            'etablissement:id,nom,slug',
                            'tags:id,name,slug',
                            'publications_booster.shopping_publications',
                            'publications_booster' => function ( $query ) {
                                $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                                    ->where( 'date_fin', '>', Carbon::now() )->orderBy( 'id', 'desc' );
                            },
                        ] )->orderByDesc( 'updated_at' )->limit( 32 )->get()
                    ->shuffle()       // Shuffle the collection for extra randomness
                    ->take( 8 );       // Take only 8 random items;
            } else {
                $pourToiPub = collect();
            }

            $TagMarocPub = Publication::where( 'status', 1 )->where( 'is_marketplace', 1 )->where( 'pays_id', 1 )->whereNotIn( 'id', $pourToiPub->pluck( 'id' )->toArray() );
            if ($search && $search !== "marketplace" && $tag) {
                $TagMarocPub->whereHas( 'tags', function ( $query ) use ( $tag ) {
                    $query->where( 'tags_flink.id', $tag->id );
                } );
            }
            if ($pays && $selectedPays)
                $TagMarocPub = $TagMarocPub->where( 'pays_id', $selectedPays->id );

            if ($ville && $selectedVille)
                $TagMarocPub = $TagMarocPub->where( 'ville_id', $selectedVille->id );

            if ($region && $selectedRegion)
                $TagMarocPub = $TagMarocPub->where( 'region_id', $selectedRegion->id );


            $TagMarocPub = $TagMarocPub->withCount( [
                'publications_booster as booster' => function ( $query ) {
                    $query->where( 'date_fin', '>', Carbon::now() );
                }
            ] )->with( [
                        'ville:id,slug,name,pays_id',
                        'region:id,slug,name,ville_id',
                        'user:id,first_name,last_name,slug',
                        'etablissement:id,nom,slug',
                        'tags:id,name,slug',
                        'publications_booster.shopping_publications',
                        'publications_booster' => function ( $query ) {
                            $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                                ->where( 'date_fin', '>', Carbon::now() )->orderBy( 'id', 'desc' );
                        },
                    ] )->orderByDesc( 'score' )
                ->limit( 32 )->get()
                ->shuffle()
                ->take( 8 );


            if ($search && $search !== "marketplace") {
                $tags = Tags::where( 'status', 1 )
                    ->where( function ( $query ) use ( $search ) {
                        $query->where( 'slug', 'like', '%' . $search . '%' )
                            ->orWhere( 'slug', 'like', '%' . Str::singular( $search ) . '%' );
                    } )
                    ->pluck( 'id' );
            }

            $paysId = $pays && $selectedPays ? $selectedPays->id : null;
            $villeId = $ville && $selectedVille ? $selectedVille->id : null;
            $regionId = $region && $selectedRegion ? $selectedRegion->id : null;

            $publications = filteredPublications( clone $publicationsQuery, $search, $paysId ? [$paysId] : [], $villeId, $villeId ? [$villeId] : [], $regionId, $regionId ? [$regionId] : [] );

            // return $publications;

            $defaultRadiusKm = 50;
            $maxAttempts = 6; // Set a maximum number of attempts to prevent infinite loop
            $attempts = 0;

            while ($publications->count() < 24 && $attempts < $maxAttempts) {
                $attempts++;

                if ($region && $selectedRegion) {
                    $publications = filteredPublications( clone $publicationsQuery, $search, $paysId ? [$paysId] : [], $villeId, $villeId ? [$villeId] : [], null, [] );
                }
                if ($publications->count() < 24 && $ville && $selectedVille) {
                    $villeIds = getNearbyLocation( $selectedVille->id, $defaultRadiusKm, "ville" );
                    $publications = filteredPublications( clone $publicationsQuery, $search, $paysId ? [$paysId] : [], $villeId, $villeIds, null, [] );
                }

                if ($publications->count() >= 24) {
                    break;
                }

                $defaultRadiusKm *= 2;

                // Optional: add a maximum radius limit
                if ($defaultRadiusKm > 200) { // Don't search beyond 100km
                    break;
                }
            }



            // $villeIds = getNearbyLocation($selectedVille->id, 400, "ville");
            // return Ville::whereIn('id', $villeIds)->pluck('name');
            // $publications = filteredPublications(clone $publicationsQuery, $search, $paysArray, $villeIds, $regionsArray);

            // return [$publications->count(), getNearbyLocation($selectedVille->id, 250, "ville")];

            // return $publications;
            // $publications = $publications->paginate(4);
            if ($profileObject)
                $profileRecommendationsWithFilter = filteredPublications( $profileObject->recommendations(), $search, $paysId ? [$paysId] : [], $villeId, $villeId ? [$villeId] : [], $regionId, $regionId ? [$regionId] : [] );
            else
                $profileRecommendationsWithFilter = null;
            $publications = getOrderedPublicationsWithPreferencesTest( $publications, $profileRecommendationsWithFilter );
            // $publications = addBoosterToPublicationsToMarketplace( $publications, $request, 24 );


            $similar_tags = null;

            $popular_keywords = Tags::select( 'id', 'name', 'slug' )
                ->where( 'status', 1 )
                ->whereNot( 'slug', $search )
                ->whereHas( 'publications', function ( $query ) {
                    $query->where( 'status', 1 );
                } )
                ->whereHas( 'publications', function ( $query ) {
                    $query->havingRaw( 'COUNT(publications_flink.id) >= 4' );
                } )
                ->orderByDesc( 'compteur_publications' )
                ->get();

            if ($profile) {
                if (User::where( 'slug', $profile )->first()) {
                    $profileObject = User::where( 'slug', $profile )->with( 'tags_filters' )->first();
                } else if (Etablissement::where( 'slug', $profile )->first()) {
                    $profileObject = Etablissement::where( 'slug', $profile )->with( 'tags_filters' )->first();
                }
                $user_tags = $profileObject->tags_filters()
                    ->select(
                        'tags_flink.id',
                        'tags_flink.name',
                        'tags_flink.slug'
                    )
                    ->where( 'tags_flink.status', 1 )
                    ->whereNot( 'tags_flink.slug', $search )
                    ->whereHas( 'publications', function ( $query ) {
                        $query->where( 'publications_flink.status', 1 );
                    } )
                    ->withCount( 'publications' )
                    ->having( 'publications_count', '>=', 4 )
                    // ->orderByPivot('updated_at', 'desc')
                    ->orderByRaw( 'user_tags_filters_flink.updated_at DESC' )
                    ->get();
                // return $user_tags;


                if ($search && $search !== "marketplace" && $tags && $tags->isNotEmpty()) {
                    $similar_tags = Tags::select( 'id', 'name', 'slug' )
                        ->where( 'status', 1 )
                        ->whereIn( 'id', $this->tagsSimilaire( $tags ) )
                        ->whereNot( 'slug', $search )
                        ->whereHas( 'publications', function ( $query ) {
                            $query->where( 'status', 1 );
                        } )
                        ->whereHas( 'publications', function ( $query ) {
                            $query->havingRaw( 'COUNT(publications_flink.id) >= 4' );
                        } )
                        ->orderByDesc( 'compteur_publications' )
                        ->get();
                    $tags_keywords = getTagsOrdrer( $popular_keywords, $similar_tags, $user_tags, $search, 12 );
                } else {
                    $tags_keywords = getTagsOrdrer( $popular_keywords, null, $user_tags, $search, 12 );
                }
            } else {
                if ($search && $search !== "marketplace" && $tags && $tags->isNotEmpty()) {
                    $similar_tags = Tags::select( 'id', 'name', 'slug' )
                        ->where( 'status', 1 )
                        ->whereIn( 'id', $this->tagsSimilaire( $tags ) )
                        ->whereNot( 'slug', $search )
                        ->whereHas( 'publications', function ( $query ) {
                            $query->where( 'status', 1 );
                        } )
                        ->whereHas( 'publications', function ( $query ) {
                            $query->havingRaw( 'COUNT(publications_flink.id) >= 4' );
                        } )
                        ->orderByDesc( 'compteur_publications' )
                        ->get();
                    $tags_keywords = getTagsOrdrer( $popular_keywords, $similar_tags, null, $search, 12 );
                } else {
                    $tags_keywords = getTagsOrdrer( $popular_keywords, null, null, $search, 12 );
                }
            }

            $totalPages = $publications->lastPage();
            $currentPage = $publications->currentPage();
            $paginationLinks = [];

            $range = 2; // Number of pages before and after the active page
            $pagesToShow = [];

            // Always add first page
            $pagesToShow[] = 1;

            // Add pages around current page
            for ($i = max( 2, $currentPage - $range ); $i <= min( $totalPages - 1, $currentPage + $range ); $i++) {
                $pagesToShow[] = $i;
            }

            // Always add last page if different from first
            if ($totalPages > 1) {
                $pagesToShow[] = $totalPages;
            }

            // Remove duplicates and sort
            $pagesToShow = array_values( array_unique( $pagesToShow ) );
            sort( $pagesToShow );

            // Build pagination links
            $prevPage = null;
            foreach ($pagesToShow as $page) {
                if ($prevPage !== null && $page > $prevPage + 1) {
                    $paginationLinks[] = ['url' => null, 'label' => '...', 'active' => false];
                }

                $paginationLinks[] = [
                    'url' => $publications->url( $page ),
                    'label' => (string) $page,
                    'active' => $page === $currentPage
                ];

                $prevPage = $page;
            }

            // Previous link
            array_unshift( $paginationLinks, [
                'url' => $publications->previousPageUrl(),
                'label' => '&laquo; Previous',
                'active' => false,
                'disabled' => !$publications->previousPageUrl()
            ] );

            // Next link
            $paginationLinks[] = [
                'url' => $publications->nextPageUrl(),
                'label' => 'Next &raquo;',
                'active' => false,
                'disabled' => !$publications->nextPageUrl()
            ];


            $pourToiPub = $this->getMixedPublications( $pourToiPub, [] );
            $TagMarocPub = $this->getMixedPublications( $TagMarocPub, $pourToiPub->pluck( 'id' ) );
            $typeTags = "Tags";
            if ($profile) {
                if ($search == "marketplace")
                    $tagsPopulaires = getTagsOrdrer( $popular_keywords, null, $user_tags, $search, 60 );
                else if (!$pays && !$ville && !$region)
                    $tagsPopulaires = getTagsOrdrer( $popular_keywords, $similar_tags, $user_tags, $search, 60 );
                else {
                    if ($region || $ville) {
                        $tagsPopulaires = Ville::where( 'status', 1 )
                            ->where( 'compteur_publications', '>=', 10 )
                            ->where( 'id', '!=', $selectedVille->id ?? 0 )
                            ->orderByDesc( 'compteur_publications' )
                            ->limit( 100 )->get()
                            ->shuffle()
                            ->take( 60 );
                        $typeTags = "Villes";
                    } else {
                        $tagsPopulaires = Ville::where( 'status', 1 )
                            ->where( 'compteur_publications', '>=', 10 )
                            ->orderByDesc( 'compteur_publications' )
                            ->limit( 100 )->get()
                            ->shuffle()
                            ->take( 60 );
                        $typeTags = "Villes";
                    }
                }
            } else {
                if ($search == "marketplace")
                    $tagsPopulaires = getTagsOrdrer( $popular_keywords, null, null, $search, 60 );
                else if (!$pays && !$ville && !$region)
                    $tagsPopulaires = getTagsOrdrer( $popular_keywords, $similar_tags, null, $search, 60 );
                else {
                    if ($region || $ville) {
                        $tagsPopulaires = Ville::where( 'status', 1 )
                            ->where( 'compteur_publications', '>=', 10 )
                            ->where( 'id', '!=', $selectedVille->id ?? 0 )
                            ->orderByDesc( 'compteur_publications' )
                            ->limit( 100 )->get()
                            ->shuffle()
                            ->take( 60 );
                        $typeTags = "Villes";
                    } else {
                        $tagsPopulaires = Ville::where( 'status', 1 )
                            ->where( 'compteur_publications', '>=', 10 )
                            ->orderByDesc( 'compteur_publications' )
                            ->limit( 100 )->get()
                            ->shuffle()
                            ->take( 60 );
                        $typeTags = "Villes";
                    }

                }
            }

            return response()->json( [
                'status' => 201,
                'data' => [
                    "title" => "Offres spéciales de " . $searchTagName
                        . ( $attempts == 0 ? "" : " à proximité" )
                        . ( $region && $selectedRegion ? " à " . $selectedRegion->name . " de" : ( $ville && $selectedVille ? " à" : "" ) )
                        . ( $ville && $selectedVille ? " " . $selectedVille->name : "" )
                        . ( $pays && $selectedPays ? " au " . $selectedPays->name : "" ),

                    "publication" => $publications,
                    "recommendedPublications" => [
                        // "RecommendationWithTag" => ["title" => "Les meilleures offres de {$recommendationTag->name}" , "data" => $publicationsRecommendationTag, "link" => $recommendationTag->slug],

                        "pourToi" => ["title" => "{$this->setTitle( $searchTagName, 'pourToi', $selectedRegion ?? null, $selectedVille ?? null, $selectedPays ?? null )}", "data" => $pourToiPub, "titleType" => "h2"],
                        "tagMaroc" => ["title" => "{$this->setTitle( $searchTagName, 'tagMaroc', $selectedRegion ?? null, $selectedVille ?? null, $selectedPays ?? null )}", "data" => $TagMarocPub, "titleType" => "h3"],

                        "tagsPopulaires" => ["title" => "{$typeTags} Populaires", "data" => $tagsPopulaires, "typeTags" => $typeTags],

                        // "autreVilles"=> ["title"=>"Découvrez des produits dans autres villes", "data"=>$recommendAutreVille],
                        // "tagsSimilaire"=> ["title"=>"Découvrez des produits similaires que vous pourriez aimer !", "data"=>$recommendTagsSimilaire],
                        // "publicationsDefault"=>["title"=> "Découvrez des produits populaires", "data"=>$recommendDefault],

                        // "recommendSimilaireSameVille" => ["title" => "Recommandations similaires dans ". ($selectedVille->name ?? 'votre ville'), "data" => $recommendSimilaireSameVille],
                        // "recommendAutreVillesWithProfile" => ["title" => "Explorez d'autres destinations", "data" => $recommendAutreVillesWithProfile],
                        // "recommendTopTagVille" => ["title" => "Top préférences pour vous", "data" => $recommendTopTagVille],
                        // "recommendPreferences" => ["title" => "Nos meilleures recommandations pour vous", "data" => $recommendPreferences],

                        // "marketplaceTag1" => ["title" => $recommendationsTags[0]->name, "data" => $marketplaceRecommendationTag1 ?? [], "link" => $recommendationsTags[0]->slug],
                        // "marketplaceTag2" => ["title" => $recommendationsTags[1]->name, "data" => $marketplaceRecommendationTag2 ?? [], "link" => $recommendationsTags[1]->slug],
                        // "marketplaceTag3" => ["title" => $recommendationsTags[2]->name, "data" => $marketplaceRecommendationTag3 ?? [], "link" => $recommendationsTags[2]->slug],
                    ],
                    "keywords" => $tags_keywords,
                    "searchTag" => $searchTagName,
                    "pagination_links" => $paginationLinks,
                    "variables" => ["search" => $search, "pays" => $pays, "ville" => $ville, "region" => $region]
                ]
            ] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()] );
        }
    }






















































    private function setTitle( $tag, $type, $region, $ville, $pays )
    {
        if ($type == "pourToi") {
            $tagName = $tag && $tag != "Marketplace" ? " de " . $tag . " " : " ";
            $titles = [
                "Top sélection pour toi",
                "Top tendance pour toi",
                "Spécial Offre pour toi"
            ];
            $randomNumber = random_int( 0, count( $titles ) - 1 );
            return $titles[$randomNumber];
        } else if ($type == "tagMaroc") {
            $localName = "";
            if ($region && isset( $region->name )) {
                $localName = "à {$region->name}";
            } elseif ($ville && isset( $ville->name )) {
                $localName = "à {$ville->name}";
            } elseif ($pays && isset( $pays->name )) {
                $localName = "au {$pays->name}";
            } else {
                $localName = ""; // fallback if all are null
            }

            $titles = [
                "L'offre immanquable du moment {$localName}",
                "La sélection tendance {$localName}",
                "Exclusivité disponible maintenant {$localName}",
                "L'affaire à ne pas rater {$localName}",
            ];
            $randomNumber = random_int( 0, count( $titles ) - 1 );
            return $titles[$randomNumber];
        }

        return ""; // default return if type doesn't match
    }

    public function getMixedPublications( $staticPublications, $exludePublicationIds )
    {

        // Calculate how many more we need to reach 32
        $remaining = 32 - $staticPublications->count();

        $globalPublications = collect();

        // If we need more publications, fetch global ones
        if ($remaining > 0) {
            $globalPublications = Publication::where( 'status', 1 )->where( 'is_marketplace', 1 )->whereNotIn( 'id', $staticPublications->pluck( 'id' ) )
                ->whereNotIn( 'id', $exludePublicationIds )
                ->withCount( [
                    'publications_booster as booster' => function ( $query ) {
                        $query->where( 'date_fin', '>', Carbon::now() );
                    }
                ] )
                ->with( [
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug',
                    'etablissement:id,nom,slug',
                    'tags:id,name,slug',
                    'publications_booster.shopping_publications',
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', Carbon::now() )->orderBy( 'id', 'desc' );
                    },
                ] )->orderByDesc( 'score' )
                ->limit( $remaining )
                ->get();
        }

        // Merge both collections
        $mixedPublications = $staticPublications->merge( $globalPublications );

        // Shuffle and return 8 random publications
        return $mixedPublications->shuffle()->take( 8 );
    }


    public function marketplacePublicationsPaginationInfos( Request $request )
    {
        $search = $request->get( 'search' );
        $pays = $request->get( 'pays' );
        $ville = $request->get( 'ville' );
        $region = $request->get( 'region' );

        // Add latitude and longitude to pays and ville and region
        $paysId = $selectedPays = null;
        $villeId = $selectedVille = null;
        $regionId = $selectedRegion = null;
        if ($pays) {
            $selectedPays = Pays::select( 'id', 'name', 'slug' )->where( 'slug', $pays )->first();
        }
        if ($ville && $selectedPays) {
            $selectedVille = Ville::select( 'id', 'name', 'slug' )->where( 'pays_id', $selectedPays->id )->where( 'slug', $ville )->first();
            if ($region && $selectedVille) {
                $selectedRegion = Region::select( 'id', 'name', 'slug' )->where( 'ville_id', $selectedVille->id )->where( 'slug', $region )->first();
            }
        }
        if ($search) {
            $tag = Tags::select( 'id', 'name', 'slug' )->where( 'slug', $search )->first();
        }
        try {
            $publicationsQuery = Publication::select(
                'id',
                'slug',
                'user_id',
                'etab_id',
                'ville_id',
                'region_id',
                'image_default',
                'image_square',
                'prix',
                'prixPromo',
                'score'
            )
                ->where( 'status', 1 )
                ->where( 'is_marketplace', 1 )
                ->whereNotNull( 'image_default' )
                ->withCount( [
                    'publications_booster as booster' => function ( $query ) {
                        $query->where( 'date_fin', '>', Carbon::now() );
                    }
                ] )
                ->having( 'booster', 0 )
                ->with( [
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug',
                    'etablissement:id,nom,slug',
                    'tags:id,name,slug',
                    'publications_booster.shopping_publications',
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', Carbon::now() )->orderBy( 'id', 'desc' );
                    },
                ] );

            $paysId = $pays && $selectedPays ? $selectedPays->id : null;
            $villeId = $ville && $selectedVille ? $selectedVille->id : null;
            $regionId = $region && $selectedRegion ? $selectedRegion->id : null;

            $publications = filteredPublications( clone $publicationsQuery, $search, $paysId ? [$paysId] : [], $villeId, $villeId ? [$villeId] : [], $regionId, $regionId ? [$regionId] : [] );

            // return $publications;

            $defaultRadiusKm = 50;
            $maxAttempts = 6; // Set a maximum number of attempts to prevent infinite loop
            $attempts = 0;

            while ($publications->count() < 24 && $attempts < $maxAttempts) {
                $attempts++;

                if ($region && $selectedRegion) {
                    $publications = filteredPublications( clone $publicationsQuery, $search, $paysId ? [$paysId] : [], $villeId, $villeId ? [$villeId] : [], null, [] );
                }
                if ($publications->count() < 24 && $ville && $selectedVille) {
                    $villeIds = getNearbyLocation( $selectedVille->id, $defaultRadiusKm, "ville" );
                    $publications = filteredPublications( clone $publicationsQuery, $search, $paysId ? [$paysId] : [], $villeId, $villeIds, null, [] );
                }

                if ($publications->count() >= 24) {
                    break;
                }

                $defaultRadiusKm *= 2;

                // Optional: add a maximum radius limit
                if ($defaultRadiusKm > 200) { // Don't search beyond 100km
                    break;
                }
            }

            // $publications = getOrderedPublicationsWithPreferencesTest($publications, null);
            // $publications = addBoosterToPublicationsToMarketplace( $publications, $request, 24 );



            $lastPage = $publications->lastPage();
            // $totalItems = $publications->total();



            $paysName = $paysId && $selectedPays ? $selectedPays->name : null;
            $villeName = $selectedPays && $villeId && $selectedVille ? $selectedVille->name : null;
            $regionName = $selectedVille && $regionId && $selectedRegion ? $selectedRegion->name : null;
            $tagName = $search && $tag ? $tag->name : null;

            return response()->json( [
                'status' => 201,
                'data' => [
                    "lastPage" => $lastPage,
                    "paysName" => $paysName,
                    "villeName" => $villeName,
                    "regionName" => $regionName,
                    "tagName" => $tagName,
                    "tagId" => isset($tag) && $tag ? $tag->id : null,
                ]
            ] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()] );
        }
    }

    // // calculate score of annonces 
    public function calculateAnnonceScores( Request $request )
    {
        // try {
        //     // Log email attempt
        //     $emailLog = $this->createEmailLog($to, $subject, $content, 'pending');

        //     // Send email
        //     $mail = new TestEmail($subject, $content);

        //     if ($from) {
        //         $mail->from($from);
        //     }

        //     Mail::to($to)->send($mail);

        //     // Check if email was sent successfully
        //     if (count(Mail::failures()) > 0) {
        //         $this->updateEmailStatus($emailLog, 'failed', 'Mail delivery failed');
        //         return false;
        //     }

        //     $this->updateEmailStatus($emailLog, 'sent', 'Email sent successfully');
        //     return true;

        // } catch (Exception $e) {
        //     Log::error('Email sending failed: ' . $e->getMessage());

        //     if ($emailLog) {
        //         $this->updateEmailStatus($emailLog, 'failed', $e->getMessage());
        //     }

        //     return false;
        // }

        // Perform the DNS check just before building the email
        // try {
        //     // Find the user
        //     $user = User::findOrFail(8067);
        //     $publication = Publication::findOrFail(1082);

        //     // Check current status for the response
        //     $currentStatus = $user->email_status;

        //     // Send the notification - This will trigger your DNS check and status update
        //     // $user->notify(
        //     //     (new FirstPublicationEmailReminder($user))
        //     //         ->delay(now()->addMinutes(1))
        //     // );
        //     $user->notifyNow(new NewPublicationNotificationEmailUser($publication));

        //     // Refresh the user model to get the updated status from the database
        //     // $user->refresh();

        //     // Prepare the response message
        //     $message = "Test email process completed. ";
        //     $message .= "Initial status: '{$currentStatus}'. ";
        //     $message .= "Final status: '{$user->email_status}'. ";

        //     if ($user->email_error) {
        //         $message .= "Error: {$user->email_error}";
        //     }

        //     return response()->json([
        //         'success' => true,
        //         'message' => $message,
        //         'user' => [
        //             'id' => $user->id,
        //             'email' => $user->email,
        //             'final_status' => $user->email_status,
        //             'error' => $user->email_error
        //         ]
        //     ]);

        // } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        //     // User not found
        //     return response()->json([
        //         'success' => false,
        //         'message' => "User with ID {$user_id} not found."
        //     ], 404);

        // } catch (\Exception $e) {
        //     // Any other exception (including our DNS exception)
        //     // Log the error for debugging
        //     Log::error('Test email failed: ' . $e->getMessage());

        //     return response()->json([
        //         'success' => false,
        //         'message' => 'An error occurred while sending the test email.',
        //         'error' => $e->getMessage()
        //     ], 500);
        // }


        $profile = User::find( 7991 );
        $profileType = "user";
        // $title = $this->getTitleText();
        // $notification = new FirstPublicationEmailReminder($profile);

        // // Delay the notification by 24 hours but still check publications at send time
        // $profile->notify($notification->delay(now()->addSeconds(10)));

        $twentyFourHoursAgo = now()->subDay();

        $followersEtablissements = DB::table( 'follows' )
            ->join( 'etablissements', 'follows.etab_follower_id', '=', 'etablissements.id' )
            ->where( function ( $query ) use ( $profile, $profileType ) {
                if ($profileType === 'user') {
                    // If profile is a user, look for user_following_id
                    $query->where( 'follows.user_following_id', $profile->id );
                } else {
                    // If profile is an establishment, look for etab_following_id  
                    $query->where( 'follows.etab_following_id', $profile->id );
                }
            } )
            ->where( 'follows.created_at', '>=', $twentyFourHoursAgo )
            ->pluck( 'etablissements.nom' )
            ->toArray();

        $followersUsers = DB::table( 'follows' )
            ->join( 'users', 'follows.user_follower_id', '=', 'users.id' )
            ->where( function ( $query ) use ( $profile, $profileType ) {
                if ($profileType === 'user') {
                    // If profile is a user, look for user_following_id
                    $query->where( 'follows.user_following_id', $profile->id );
                } else {
                    // If profile is an establishment, look for etab_following_id  
                    $query->where( 'follows.etab_following_id', $profile->id );
                }
            } )
            ->where( 'follows.created_at', '>=', $twentyFourHoursAgo )
            ->pluck( 'users.last_name' )
            ->toArray();

        $followerNames = array_merge( $followersEtablissements, $followersUsers );

        return response()->json( ['title' => $followerNames] );



        // $notifications = $profile->notifications()->where('created_at', '>=', now()->subDay())->where('type', 'App\Notifications\NewFollowerNotification')->count();
        // return response()->json(['title' => "send"]);

    }

    protected function getTitleText(): string
    {
        $profile = User::find( 7991 );
        $twentyFourHoursAgo = now()->subDay();
        $followersEtablissements = $profile->followersEtablissements()->where( 'follows.created_at', '>=', $twentyFourHoursAgo )->pluck( 'nom' )->toArray();
        $followersUsers = $profile->followersUsers()->where( 'follows.created_at', '>=', $twentyFourHoursAgo )->pluck( 'last_name' )->toArray();
        $allNames = array_merge( $followersEtablissements, $followersUsers );
        $count = count( $allNames );

        $title = '';

        if ($count === 0) {
            $title .= '0 followers';
        } elseif ($count === 1) {
            $title .= $allNames[0];
        } elseif ($count === 2) {
            $title .= $allNames[0] . ' and ' . $allNames[1];
        } else {
            $title .= $allNames[0] . ', ' . $allNames[1] . ' and ' . ( $count - 2 ) . ' more';
        }

        return $title;
    }


    private function isArabic( $text )
    {
        // Regular expression to match Arabic characters
        $arabicRegex = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

        return preg_match( $arabicRegex, $text );
    }

    public function publicationDetails( Request $request )
    {
        $profile = $request->query( 'profile' );
        $slug = $request->query( 'slug' );

        // Track every call to publicationDetails
        try {
            Log::info( 'publicationDetails called', [
                'profile' => $profile,
                'slug' => $slug,
                'ip' => $request->ip(),
            ] );
        } catch (\Throwable $e) {
        }

        // Validate slug parameter
        if (empty( $slug )) {
            try {
                Log::info( 'publicationDetails skipped (missing slug)', [
                    'profile' => $profile,
                    'ip' => $request->ip(),
                ] );
            } catch (\Throwable $e) {
            }
            return response()->json( ['error' => 'Slug parameter is required'], 400 );
        }

        // Clean the slug - remove quotes and trim whitespace
        $originalSlug = $slug;
        $slug = trim( $slug, ' "\'"' );

        try {
            // First, let's check if the publication exists at all (regardless of status)
            $publicationExists = Publication::where( 'slug', $slug )->exists();
            if (!$publicationExists) {
                try {
                    Log::info( 'publicationDetails skipped (publication not found)', [
                        'profile' => $profile,
                        'slug' => $slug,
                    ] );
                } catch (\Throwable $e) {
                }
                // Try with trimmed slug
                $trimmedSlug = trim( $slug );
                $trimmedExists = Publication::where( 'slug', $trimmedSlug )->exists();

                if ($trimmedExists) {
                    return response()->json( ['error' => 'Publication not found - slug format issue'], 404 );
                }

                // Check for case-insensitive match
                $caseInsensitiveMatch = Publication::whereRaw( 'LOWER(slug) = LOWER(?)', [$slug] )->first();
                if ($caseInsensitiveMatch) {
                    return response()->json( ['error' => 'Publication not found - case sensitivity issue'], 404 );
                }

                return response()->json( ['error' => 'Publication not found'], 404 );
            }

            // Check if publication exists but has different status
            $publicationStatus = Publication::where( 'slug', $slug )->value( 'status' );
            if ($publicationStatus !== 1) {
                try {
                    Log::info( 'publicationDetails skipped (inactive publication)', [
                        'profile' => $profile,
                        'slug' => $slug,
                        'status' => $publicationStatus,
                    ] );
                } catch (\Throwable $e) {
                }
                return response()->json( ['error' => 'Publication not available'], 404 );
            }

            $publication = Publication::select(
                'id',
                'slug',
                'is_marketplace',
                'reference',
                'cat_g_id',
                'catalogue_id',
                'user_id',
                'etab_id',
                'pays_id',
                'ville_id',
                'region_id',
                'tele',
                'tele_whatsapp',
                'contact',
                'description',
                'image_default',
                'image_square',
                'images',
                'video_origine',
                'video_compresse',
                'prix',
                'prixPromo',
                'disponibilite',
                'disponibilite_date',
                'created_at'
            )
                ->where( 'slug', $slug )
                ->where( 'status', 1 )
                ->withCount( [
                    'publications_booster as booster' => function ( $query ) {
                        $query->where( 'date_fin', '>', Carbon::now() );
                    }
                ] )
                ->with( [
                    'pays:id,slug,name,latitude,longitude',
                    'ville:id,slug,name,pays_id,latitude,longitude',
                    'region:id,slug,name,ville_id,latitude,longitude',
                    'categorie:id,slug,name',
                    'tags:id,name,slug,mngr_is_index,canonical_parent,master',
                    'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                    'etablissement:id,nom,logo,slug,is_verified,created_at',
                    'modes_laivraison:id,name',
                    'statistiques' => function ( $query ) {
                        $query->selectRaw( '
                    publication_id,
                        SUM(click_contact) as click_contact,
                        SUM(click_tele) as click_tele,
                        SUM(click_tele_whatsapp) as click_tele_whatsapp,
                        SUM(click_sauvgarder) as click_sauvgarder,
                        SUM(click_partage) as click_partage,
                        SUM(click_detail) as click_detail,
                        SUM(vues) as vues
                    ' )
                            ->groupBy( 'publication_id' );
                    },
                    // Eagerly load active boosters with just the fields we need
                    'publications_booster.shopping_publications',
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', Carbon::now() )->orderBy( 'id', 'desc' );
                    },
                ] )->first();

            if (!$publication) {
                try {
                    Log::info( 'publicationDetails skipped (publication query null)', [
                        'profile' => $profile,
                        'slug' => $slug,
                    ] );
                } catch (\Throwable $e) {
                }
                return response()->json( ['error' => 'Publication not found'], 404 );
            }


            $publication->sauvgardes = $publication->user_sauvgardes->count() + $publication->etab_sauvgardes->count();

            // Process tags to add master metadata and remove pivot
            if ($publication->tags && $publication->tags->count() > 0) {
                $processedTags = [];
                foreach ($publication->tags as $tag) {
                    $masterData = null;

                    // If tag has a master ID, fetch master details
                    if (!empty( $tag->master )) {
                        $masterTag = DB::table( 'tags_flink' )
                            ->select( 'id', 'slug', 'name', 'mngr_is_index' )
                            ->where( 'id', $tag->master )
                            ->first();

                        if ($masterTag) {
                            $masterData = [
                                'id' => $masterTag->id,
                                'slug' => $masterTag->slug,
                                'name' => $masterTag->name,
                                'mngr_is_index' => $masterTag->mngr_is_index
                            ];
                        }
                    }

                    // Build clean tag data without pivot
                    $processedTags[] = [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'mngr_is_index' => $tag->mngr_is_index ?? 1,
                        'canonical_parent' => $tag->canonical_parent ?? 0,
                        'master' => $masterData
                    ];
                }

                // Replace tags collection with processed array
                unset($publication->tags);
                $publication->tags = $processedTags;
            }

            // Determine latitude and longitude
            $latitude = null;
            $longitude = null;

            if ($publication->region && $publication->region->latitude && $publication->region->longitude) {
                $latitude = $publication->region->latitude;
                $longitude = $publication->region->longitude;
            } elseif ($publication->ville && $publication->ville->latitude && $publication->ville->longitude) {
                $latitude = $publication->ville->latitude;
                $longitude = $publication->ville->longitude;
            } elseif ($publication->pays && $publication->pays->latitude && $publication->pays->longitude) {
                $latitude = $publication->pays->latitude;
                $longitude = $publication->pays->longitude;
            }

            $publication->latitude = $latitude;
            $publication->longitude = $longitude;

            // Computed convenience flag used by the frontend to apply catalogue-specific
            // image display logic (e.g. deduplicating image_default from the gallery strip).
            // catalogue_id is already selected above — no extra query needed.
            $publication->belongs_to_catalogue = !empty( $publication->catalogue_id ) && (int) $publication->catalogue_id > 0;

            // Do not attach repost_user in details; always return the original publication only

            // if ($isPostData == "true") {
            //     // Recherche ou création d'une statistique pour la publication et la date actuelle
            //     // $stat = Statistique::firstOrCreate([
            //     //     'publication_id' => $publication->id,
            //     //     'date' => now()->toDateString(),
            //     // ]);

            //     // // Incrémentation du champ correspondant à l'action spécifiée
            //     // $stat->increment('click_detail');

            //     // $this->storeRedis($publication->id, 'vues');

            //     if ($profile) {
            //         $user = User::where('slug', $profile)->first();
            //         $etablissement = Etablissement::where('slug', $profile)->first();

            //         if ($user) {
            //             $this->addUpdateDetailPublication($publication, $user);
            //             $exist = $user->clicked_publications()->where('publication_id', $publication->id)->first();
            //             if ($exist) {
            //                 $exist->pivot->increment('compteur');
            //             } else {
            //                 $user->clicked_publications()->attach($publication->id);
            //             }
            //         } elseif ($etablissement) {
            //             $this->addUpdateDetailPublication($publication, $etablissement);
            //             $exist = $etablissement->clicked_publications()->where('publication_id', $publication->id)->first();
            //             if ($exist) {
            //                 $exist->pivot->increment('compteur');
            //             } else {
            //                 $etablissement->clicked_publications()->attach($publication->id);
            //             }
            //         }
            //     }
            // }

            // ========== ADD FOLLOW AND SAVE STATUS CODE HERE ==========
            // Only add follow and save information if profile is provided
            if ($profile) {
                // Get the profile object (either User or Etablissement)
                $profileObject = User::where( 'slug', $profile )
                    ->with( ['followingUsers', 'followingEtablissements', 'publications_sauvgarde'] )
                    ->first();

                if (!$profileObject) {
                    $profileObject = Etablissement::where( 'slug', $profile )
                        ->with( ['followingUsers', 'followingEtablissements', 'publications_sauvgarde'] )
                        ->first();
                }

                if ($profileObject) {
                    // Check if publication is saved by profile
                    if (method_exists( $profileObject, 'publications_sauvgarde' )) {
                        $publication->isSavedByProfile = $profileObject->publications_sauvgarde
                            ->contains( 'id', $publication->id );
                    }

                    // Check if profile follows the publication owner
                    if (
                        method_exists( $profileObject, 'followingUsers' ) &&
                        method_exists( $profileObject, 'followingEtablissements' )
                    ) {

                        // Get all followed slugs
                        $followedSlugs = array_merge(
                            $profileObject->followingEtablissements->pluck( 'slug' )->toArray(),
                            $profileObject->followingUsers->pluck( 'slug' )->toArray()
                        );

                        // Determine the owner slug of this publication
                        $ownerSlug = null;
                        if ($publication->etab_id && $publication->etablissement) {
                            $ownerSlug = $publication->etablissement->slug;
                        } elseif ($publication->user_id && $publication->user) {
                            $ownerSlug = $publication->user->slug;
                        }

                        if ($ownerSlug) {
                            $publication->isFollowed = in_array( $ownerSlug, $followedSlugs );
                        }
                    }

                    // Check if the connected profile has reposted this publication
                    try {
                        $isReposted = false;
                        if ($profileObject instanceof \App\Models\User) {
                            $isReposted = PublicationReposted::where( 'publication_id', $publication->id )
                                ->where( 'profile_type', 'user' )
                                ->where( 'user_id', $profileObject->id )
                                ->exists();
                        } elseif ($profileObject instanceof \App\Models\Etablissement) {
                            $isReposted = PublicationReposted::where( 'publication_id', $publication->id )
                                ->where( 'profile_type', 'etablissement' )
                                ->where( 'etab_id', $profileObject->id )
                                ->exists();
                        }
                        if ($isReposted) {
                            $publication->isRepostedByConnectedUser = true;
                        }
                    } catch (\Throwable $e) {
                        // Silently ignore; do not block details response
                    }
                }
            }
            // ========== END OF ADDED CODE ==========

            // Track tag interest for the requesting profile (user_tags_filters_flink)
            if ($profile) {

                try {
                    // Prevent duplicate increments if this endpoint is called twice rapidly (idempotency window)
                    $pubIdForKey = $publication->id ?? null;
                    if ($pubIdForKey) {
                        $idempotencyKey = 'tagfilters:details:' . ( (string) $profile ) . ':' . ( (string) $pubIdForKey );
                        if (!Cache::add( $idempotencyKey, true, now()->addSeconds( 5 ) )) {
                            try {
                                Log::info( 'user_tags_filters_flink skipped (idempotency)', [
                                    'profile' => $profile,
                                    'publication_id' => $pubIdForKey,
                                    'key' => $idempotencyKey,
                                ] );
                            } catch (\Throwable $e) {
                            }
                            return response()->json( ['status' => 201, 'data' => $publication] );
                        }
                    }

                    $profileForTags = User::where( 'slug', $profile )
                        ->with( ['tags_filters'] )
                        ->first();
                    if (!$profileForTags) {
                        $profileForTags = Etablissement::where( 'slug', $profile )
                            ->with( ['tags_filters'] )
                            ->first();
                    }

                    try {
                        Log::info( 'user_tags_filters_flink processing start', [
                            'profile' => $profile,
                            'publication_id' => $publication->id ?? null,
                            'has_profile' => (bool) $profileForTags,
                            'tags_count' => isset( $publication->tags ) ? count( $publication->tags ) : null,
                        ] );
                    } catch (\Throwable $e) {
                    }

                    if ($profileForTags && isset( $publication->tags ) && count( $publication->tags ) > 0) {
                        foreach ($publication->tags as $tag) {
                            try {
                                $tagId = data_get( $tag, 'id' );
                                $tagSlug = data_get( $tag, 'slug' );
                                try {
                                    Log::info( 'user_tags_filters_flink tag processing', [
                                        'profile' => $profile,
                                        'publication_id' => $publication->id ?? null,
                                        'tag_id' => $tagId,
                                        'tag_slug' => $tagSlug,
                                    ] );
                                } catch (\Throwable $e) {
                                }
                                if (method_exists( $profileForTags, 'tags_filters' )) {
                                    if ($tagId === null) {
                                        continue;
                                    }
                                    $existing = $profileForTags->tags_filters()->where( 'tag_id', $tagId )->first();
                                    if ($existing) {
                                        // Increment compteur
                                        $existing->pivot->increment( 'compteur' );
                                        try {
                                            Log::info( 'user_tags_filters_flink compteur incremented', [
                                                'profile' => $profile,
                                                'tag_id' => $tagId,
                                                'tag_slug' => $tagSlug,
                                                'publication_id' => $publication->id ?? null,
                                            ] );
                                        } catch (\Throwable $e) {
                                        }
                                    } else {
                                        // Attach new tag filter row with compteur = 1 (excluding location fields)
                                        $profileForTags->tags_filters()->attach( $tagId, [
                                            'compteur' => 1
                                        ] );
                                        try {
                                            Log::info( 'user_tags_filters_flink compteur initialized', [
                                                'profile' => $profile,
                                                'tag_id' => $tagId,
                                                'tag_slug' => $tagSlug,
                                                'publication_id' => $publication->id ?? null,
                                            ] );
                                        } catch (\Throwable $e) {
                                        }
                                    }
                                } else {
                                    try {
                                        Log::info( 'user_tags_filters_flink skipped (no tags_filters relation)', [
                                            'profile' => $profile,
                                            'publication_id' => $publication->id ?? null,
                                        ] );
                                    } catch (\Throwable $e) {
                                    }
                                }
                            } catch (\Throwable $e) {
                                try {
                                    Log::info( 'user_tags_filters_flink error', [
                                        'profile' => $profile,
                                        'publication_id' => $publication->id ?? null,
                                        'tag_id' => data_get( $tag, 'id' ),
                                        'error' => $e->getMessage(),
                                    ] );
                                } catch (\Throwable $e) {
                                }
                            }
                        }
                    } else {
                        try {
                            Log::info( 'user_tags_filters_flink skipped (no tags or profile)', [
                                'profile' => $profile,
                                'publication_id' => $publication->id ?? null,
                                'has_profile' => (bool) $profileForTags,
                                'tags_count' => isset( $publication->tags ) ? count( $publication->tags ) : null,
                            ] );
                        } catch (\Throwable $e) {
                        }
                    }
                } catch (\Throwable $e) {
                    // Silently ignore tag tracking errors
                }
            }

            return response()->json( ['status' => 201, 'data' => $publication] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()] );
        }
    }

    // public function publicationDetails(Request $request) {
    //     $profile = $request->query('profile');
    //     $slug = $request->query('slug');

    //     try {
    //         // Start with basic publication query
    //         $publication = Publication::query()
    //             ->select([
    //                 'id', 'slug', 'reference', 'cat_g_id', 'user_id', 'etab_id', 'pays_id', 'ville_id', 'region_id', 
    //                 'tele', 'tele_whatsapp', 'contact', 'description', 'image_default', 'images',
    //                 'video_origine', 'video_compresse', 'prix', 'prixPromo', 'disponibilite', 'disponibilite_date', 'created_at'
    //             ])
    //             ->where('slug', $slug)
    //             ->where('status', 1)
    //             ->withCount([
    //                 'publications_booster as booster' => function ($query) {
    //                     $query->where('date_fin', '>', now());
    //                 }
    //             ])
    //             ->with([
    //                 'pays:id,slug,name,latitude,longitude',
    //                 'ville:id,slug,name,pays_id,latitude,longitude',
    //                 'region:id,slug,name,ville_id,latitude,longitude',
    //                 'tags:id,name,slug',
    //                 'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
    //                 'etablissement:id,nom,logo,slug,is_verified,created_at',
    //                 'modes_laivraison:id,name',
    //                 'statistiques' => function ($query) {
    //                     $query->selectRaw('
    //                         publication_id,
    //                         SUM(click_contact) as click_contact,
    //                         SUM(click_tele) as click_tele,
    //                         SUM(click_tele_whatsapp) as click_tele_whatsapp,
    //                         SUM(click_sauvgarder) as click_sauvgarder,
    //                         SUM(click_partage) as click_partage,
    //                         SUM(click_detail) as click_detail,
    //                         SUM(vues) as vues
    //                     ')->groupBy('publication_id');
    //                 },
    //                 'publications_booster' => function($query) {
    //                     $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message')
    //                         ->where('date_fin', '>', now())
    //                         ->orderBy('id', 'desc')
    //                         ->with('shopping_publications:id,publication_booster_id,name'); // Only select needed fields
    //                 },
    //                 'user_sauvgardes:id',
    //                 'etab_sauvgardes:id'
    //             ])
    //             ->first();

    //         if (!$publication) {
    //             Log::error("Publication not found with slug: " . $slug);
    //             return response()->json(['error' => 'Publication not found'], 404);
    //         }

    //         // Calculate saves count
    //         // $publication->sauvgardes = $publication->user_sauvgardes->count() + $publication->etab_sauvgardes->count();
    //         $publication->sauvgardes = 0;

    //         // // Set coordinates (prioritize region > ville > pays)
    //         // $location = collect([$publication->region, $publication->ville, $publication->pays])
    //         //     ->firstWhere(fn($item) => $item && $item->latitude && $item->longitude);

    //         // if ($location) {
    //         //     $publication->latitude = $location->latitude;
    //         //     $publication->longitude = $location->longitude;
    //         // }

    //         // // Add follow/save status if profile is provided
    //         // if ($profile) {
    //         //     $this->addProfileRelationships($publication, $profile);
    //         // }

    //         return response()->json([
    //             'status' => 200, // Changed from 201 (Created) to 200 (OK) since we're retrieving data
    //             'data' => $publication
    //         ]);

    //     } catch (\Exception $e) {
    //         Log::error("Publication details error: " . $e->getMessage());
    //         return response()->json([
    //             'status' => 500,
    //             'error' => 'An error occurred while fetching publication details'
    //         ]);
    //     }
    // }

    /**
     * Helper method to add profile relationships to the publication
     */
    /**
     * Debug method to check publication status and details
     * This can be called to help debug publication lookup issues
     */
    public function debugPublicationStatus( Request $request )
    {
        $slug = $request->query( 'slug' );

        if (empty( $slug )) {
            return response()->json( ['error' => 'Slug parameter is required'], 400 );
        }

        try {
            // Clean and analyze the slug
            $originalSlug = $slug;
            $cleanedSlug = trim( $slug );
            $slugLength = strlen( $slug );
            $slugBytes = bin2hex( $slug );

            // Check for similar slugs (case insensitive)
            $similarSlugs = Publication::whereRaw( 'LOWER(slug) = LOWER(?)', [$slug] )
                ->select( 'id', 'slug', 'status' )
                ->get();

            // Check for slugs containing the search term
            $containingSlugs = Publication::where( 'slug', 'LIKE', '%' . $slug . '%' )
                ->select( 'id', 'slug', 'status' )
                ->limit( 10 )
                ->get();

            // Check exact match
            $exactMatch = Publication::where( 'slug', $slug )->first();

            // Check with cleaned slug
            $cleanedMatch = null;
            if ($cleanedSlug !== $slug) {
                $cleanedMatch = Publication::where( 'slug', $cleanedSlug )->first();
            }

            $debugInfo = [
                'original_slug' => $originalSlug,
                'cleaned_slug' => $cleanedSlug,
                'slug_length' => $slugLength,
                'slug_bytes' => $slugBytes,
                'exact_match' => $exactMatch ? [
                    'id' => $exactMatch->id,
                    'slug' => $exactMatch->slug,
                    'status' => $exactMatch->status,
                    'created_at' => $exactMatch->created_at
                ] : null,
                'cleaned_match' => $cleanedMatch ? [
                    'id' => $cleanedMatch->id,
                    'slug' => $cleanedMatch->slug,
                    'status' => $cleanedMatch->status,
                    'created_at' => $cleanedMatch->created_at
                ] : null,
                'case_insensitive_matches' => $similarSlugs->toArray(),
                'containing_matches' => $containingSlugs->toArray(),
                'total_publications' => Publication::count(),
                'recent_publications' => Publication::orderBy( 'id', 'desc' )
                    ->select( 'id', 'slug', 'status' )
                    ->limit( 5 )
                    ->get()
                    ->toArray()
            ];

            if ($exactMatch) {
                $debugInfo['message'] = 'Exact match found';
                $debugInfo['is_active'] = $exactMatch->status == 1;
            } elseif ($cleanedMatch) {
                $debugInfo['message'] = 'Match found with cleaned slug';
                $debugInfo['is_active'] = $cleanedMatch->status == 1;
            } elseif ($similarSlugs->count() > 0) {
                $debugInfo['message'] = 'Case-insensitive matches found';
            } elseif ($containingSlugs->count() > 0) {
                $debugInfo['message'] = 'Partial matches found';
            } else {
                $debugInfo['message'] = 'No matches found';
            }

            return response()->json( $debugInfo );

        } catch (\Exception $e) {
            return response()->json( ['error' => 'Database error occurred: ' . $e->getMessage()], 500 );
        }
    }

    protected function addProfileRelationships( $publication, $profileSlug )
    {
        $profile = User::where( 'slug', $profileSlug )
            ->with( [
                'followingUsers:id,slug',
                'followingEtablissements:id,slug',
                'publications_sauvgarde:id'
            ] )
            ->first();

        if (!$profile) {
            $profile = Etablissement::where( 'slug', $profileSlug )
                ->with( [
                    'followingUsers:id,slug',
                    'followingEtablissements:id,slug',
                    'publications_sauvgarde:id'
                ] )
                ->first();
        }

        if (!$profile)
            return;

        // Check if publication is saved by profile
        $publication->isSavedByProfile = $profile->publications_sauvgarde
            ->contains( 'id', $publication->id );

        // Determine if profile follows the post creator
        // If etab_id is null -> post created by user, check if profile follows the user
        // If etab_id is not null -> post created by establishment, check if profile follows the establishment
        if ($publication->etab_id) {
            // Post created by establishment - check if profile follows the establishment
            $publication->isFollowed = $profile->followingEtablissements
                ->contains( 'slug', $publication->etablissement->slug );
        } else {
            // Post created by user - check if profile follows the user
            $publication->isFollowed = $profile->followingUsers
                ->contains( 'slug', $publication->user->slug );
        }
    }


    // public function publicationsOfDetail(Request $request) {
    //     $profile = $request->query('profile');
    //     $pubId = $request->query('pubId');
    //     try {
    //             $slug = "images-diesel-voiture-occasion-audi-q8-06419";
    //             $profile = "mohamed_oubida";
    //             $publication = Publication::with('tags')->find($pubId);

    //             if (!$publication || $publication->tags->isEmpty()) {
    //                 return collect();
    //             }

    //             $currentTagIds = $publication->tags->pluck('id')->toArray();

    //             // Get similar tag IDs (tags that appear in publications sharing our current tags)
    //             $similarTagIds = Tags::where('status', 1)
    //                 ->whereHas('publications.tags', function($q) use ($currentTagIds) {
    //                     $q->whereIn('tags_flink.id', $currentTagIds);
    //                 })
    //                 ->whereNotIn('id', $currentTagIds)
    //                 ->pluck('id')
    //                 ->toArray();

    //             $publications = Publication::from('publications_flink')
    //             ->select(
    //                 'publications_flink.id',
    //                 'publications_flink.slug',
    //                 'publications_flink.reference',
    //                 'publications_flink.cat_g_id',
    //                 'publications_flink.user_id',
    //                 'publications_flink.etab_id',
    //                 'publications_flink.pays_id',
    //                 'publications_flink.ville_id',
    //                 'publications_flink.region_id',
    //                 'publications_flink.tele',
    //                 'publications_flink.tele_whatsapp',
    //                 'publications_flink.contact',
    //                 'publications_flink.description',
    //                 'publications_flink.image_default',
    //                 'publications_flink.images',
    //                 'publications_flink.video_origine',
    //                 'publications_flink.video_compresse',
    //                 'publications_flink.prix',
    //                 'publications_flink.prixPromo',
    //                 'publications_flink.disponibilite',
    //                 'publications_flink.disponibilite_date',
    //                 'publications_flink.created_at',
    //                 'publications_flink.score'
    //             )
    //             ->where('publications_flink.status', 1)
    //             ->where('publications_flink.id', '!=', $publication->id)
    //             ->withCount([
    //                 'publications_booster as booster' => function ($query) {
    //                     $query->where('date_fin', '>', Carbon::now());
    //                 }
    //             ])
    //             ->with([
    //                 'pays:id,slug,name',
    //                 'ville:id,slug,name,pays_id',
    //                 'region:id,slug,name,ville_id',
    //                 'tags:id,name,slug',
    //                 'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
    //                 'etablissement:id,nom,logo,slug,is_verified,created_at',
    //                 'modes_laivraison:id,name',
    //                 'statistiques' => function ($query) {
    //                     $query->selectRaw('
    //                         publication_id,
    //                         SUM(click_contact) as click_contact,
    //                         SUM(click_tele) as click_tele,
    //                         SUM(click_tele_whatsapp) as click_tele_whatsapp,
    //                         SUM(click_partage) as click_partage,
    //                         SUM(click_detail) as click_detail,
    //                         SUM(vues) as vues
    //                     ')->groupBy('publication_id');
    //                 },
    //                 'user_sauvgardes:id,first_name,last_name',
    //                 'etab_sauvgardes:id,nom',
    //                 'publications_booster.shopping_publications',
    //                 'publications_booster' => function($query) {
    //                     $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message')
    //                         ->where('date_fin', '>', Carbon::now())
    //                         ->orderBy('id', 'desc');
    //                 },
    //             ])
    //             ->where(function($query) use ($currentTagIds, $similarTagIds) {
    //                 $query->whereHas('tags', function($q) use ($currentTagIds) {
    //                     $q->whereIn('publications_tags_flink.tag_id', $currentTagIds);
    //                 });

    //                 if (!empty($similarTagIds)) {
    //                     $query->orWhereHas('tags', function($q) use ($similarTagIds) {
    //                         $q->whereIn('publications_tags_flink.tag_id', $similarTagIds);
    //                     });
    //                 }
    //             })
    //             ->selectRaw('publications_flink.*, 
    //                 CASE 
    //                     WHEN EXISTS (
    //                         SELECT 1 FROM publications_tags_flink 
    //                         WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                         AND publications_tags_flink.tag_id IN ('.implode(',', $currentTagIds).')
    //                     ) THEN 1 
    //                     ELSE 0 
    //                 END as is_direct_match,
    //                 (
    //                     SELECT COUNT(*) 
    //                     FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN ('.implode(',', $currentTagIds).')
    //                 ) as direct_tag_count'
    //             )
    //             ->orderByDesc('is_direct_match')
    //             ->orderByDesc('direct_tag_count');

    //             if ($profile) {
    //                 $profileObject = User::where('slug', $profile)
    //                     ->with(['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'])
    //                     ->first();

    //                 if (!$profileObject) {
    //                     $profileObject = Etablissement::where('slug', $profile)
    //                         ->with(['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'])
    //                         ->first();
    //                 }

    //                 if ($profileObject) {
    //                     // Get all followed slugs at once
    //                     if (method_exists($profileObject, 'followingUsers') && method_exists($profileObject, 'followingEtablissements')) {
    //                         $followedSlugs = array_merge(
    //                             $profileObject->followingEtablissements->pluck('slug')->toArray(),
    //                             $profileObject->followingUsers->pluck('slug')->toArray()
    //                         );
    //                     }

    //                     // Get all saved publication IDs at once
    //                     if (method_exists($profileObject, 'publications_sauvgarde')) {
    //                         $savedPublicationIds = $profileObject->publications_sauvgarde->pluck('id')->toArray();
    //                     }
    //                 }
    //             } else {
    //                 $profileObject = null;
    //             }

    //             $orderedPublications = getOrderedPublicationsWithPreferencesTest($publications, $profileObject->recommendations() ?? null);
    //             $publications = addBoosterToPublicationsToFlux($orderedPublications, $request);


    //             return response()->json($publications);

    //     } catch (\Exception $e) {
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    public function getPopularTags( Request $request )
    {
        $profile = $request->get( 'profile' );
        try {
            if ($profile) {
                $profileObject = null;
                $user = User::where( 'slug', $profile )->first();
                $etablissement = Etablissement::where( 'slug', $profile )->first();
                if (User::where( 'slug', $profile )->first()) {
                    $profileObject = User::where( 'slug', $profile )->with( 'tags_filters' )->first();
                } else if (Etablissement::where( 'slug', $profile )->first()) {
                    $profileObject = Etablissement::where( 'slug', $profile )->with( 'tags_filters' )->first();
                }
            } else {
                $profileObject = null;
            }
            if ($profileObject)
                $user_tags = $profileObject->tags_filters()
                    ->select(
                        'tags_flink.id',
                        'tags_flink.name',
                        'tags_flink.slug'
                    )
                    ->where( 'tags_flink.status', 1 )
                    ->whereHas( 'publications', function ( $query ) {
                        $query->where( 'publications_flink.status', 1 );
                    } )
                    ->withCount( 'publications' )
                    ->having( 'publications_count', '>=', 4 )
                    // ->orderByPivot('updated_at', 'desc')
                    ->orderByRaw( 'user_tags_filters_flink.updated_at DESC' )
                    ->get();

            $popular_keywords = Tags::select( 'id', 'name', 'slug' )
                ->where( 'status', 1 )
                ->whereHas( 'publications', function ( $query ) {
                    $query->where( 'status', 1 );
                } )
                ->whereHas( 'publications', function ( $query ) {
                    $query->havingRaw( 'COUNT(publications_flink.id) >= 4' );
                } )
                ->orderByDesc( 'compteur_publications' )
                ->get();
            $tagsPopulaires = getTagsOrdrer( $popular_keywords, null, $user_tags ?? null, null, 70 );

            return response()->json( ["status" => 201, "data" => $tagsPopulaires] );
        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()] );
        }
    }

    public function followingPublications( Request $request )
    {
        try {
            $profile = $request->query( 'profile' );
            $profileObject = null;
            $followedSlugs = [];
            $savedPublicationIds = [];
            $followingCount = 0;

            // Get profile data if needed
            if ($profile) {
                $profileObject = User::where( 'slug', $profile )
                    ->with( ['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'] )
                    ->first();

                if (!$profileObject) {
                    $profileObject = Etablissement::where( 'slug', $profile )
                        ->with( ['tags_filters', 'clicked_publications', 'publications_sauvgarde', 'followingUsers', 'followingEtablissements', 'recommendations'] )
                        ->first();
                }


                if ($profileObject) {
                    // Get all followed slugs at once
                    $followedSlugs = array_merge(
                        $profileObject->followingEtablissements->pluck( 'slug' )->toArray(),
                        $profileObject->followingUsers->pluck( 'slug' )->toArray()
                    );

                    // Get all saved publication IDs at once
                    $savedPublicationIds = $profileObject->publications_sauvgarde->pluck( 'id' )->toArray();

                    // Get following count
                    $followingCount = $profileObject->followingUsers->count() + $profileObject->followingEtablissements->count();
                }
            }

            // Base publications query
            $publicationsQuery = Publication::query()
                ->select( [
                    'id',
                    'slug',
                    'is_marketplace',
                    'reference',
                    'cat_g_id',
                    'user_id',
                    'etab_id',
                    'pays_id',
                    'ville_id',
                    'region_id',
                    'tele',
                    'tele_whatsapp',
                    'contact',
                    'description',
                    'image_default',
                    'image_square',
                    'images',
                    'video_origine',
                    'video_compresse',
                    'prix',
                    'prixPromo',
                    'disponibilite',
                    'disponibilite_date',
                    'created_at',
                    'score'
                ] )
                ->where( 'status', 1 )
                ->withCount( [
                    'publications_booster as booster' => function ( $query ) {
                        $query->where( 'date_fin', '>', now() );
                    }
                ] )
                ->having( 'booster', 0 )
                ->with( [
                    'pays:id,slug,name',
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'tags:id,name,slug',
                    'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                    'etablissement:id,nom,logo,slug,is_verified,created_at',
                    'modes_laivraison:id,name',
                    'statistiques' => function ( $query ) {
                        $query->selectRaw( '
                        publication_id,
                        SUM(click_contact) as click_contact,
                        SUM(click_tele) as click_tele,
                        SUM(click_tele_whatsapp) as click_tele_whatsapp,
                        SUM(click_partage) as click_partage,
                        SUM(click_detail) as click_detail,
                        SUM(vues) as vues
                    ' )->groupBy( 'publication_id' );
                    },
                    'user_sauvgardes:id,first_name,last_name',
                    'etab_sauvgardes:id,nom',
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', now() )
                            ->orderBy( 'id', 'desc' );
                    },
                ] );

            // return $followingCount;
            // return [$profileObject->followingUsers->count() . " : " . $profileObject->followingEtablissements->count()];
            // return $profileObject;
            if ($profileObject) {
                if ($followingCount === 0) {
                    return response()->json( ['status' => 200, 'data' => ["publications" => []]] );
                }

                // Get direct follow IDs
                $directEtabIds = $profileObject->followingEtablissements()->pluck( 'etablissements.id' );
                $directUserIds = $profileObject->followingUsers()->pluck( 'users.id' );

                // Get second-degree connections
                $secondDegreeUserIds = User::whereIn( 'id', $directUserIds )
                    ->with( [
                        'followingUsers' => function ( $query ) use ( $profileObject ) {
                            $query->where( 'users.id', '!=', $profileObject->id );
                        }
                    ] )
                    ->get()
                    ->pluck( 'followingUsers.*.id' )
                    ->flatten()
                    ->filter()
                    ->unique()
                    ->diff( $directUserIds );

                $secondDegreeEtabIds = User::whereIn( 'id', $directUserIds )
                    ->with( 'followingEtablissements' )
                    ->get()
                    ->pluck( 'followingEtablissements.*.id' )
                    ->flatten()
                    ->filter()
                    ->unique()
                    ->diff( $directEtabIds );

                // Build the priority query
                // $publications = $this->buildPriorityQuery(
                //     $publicationsQuery,
                //     $directUserIds,
                //     $directEtabIds,
                //     $secondDegreeUserIds,
                //     $secondDegreeEtabIds
                // );

                $publications = $this->buildPriorityQuery(
                    $publicationsQuery,
                    $directUserIds,
                    $directEtabIds,
                    collect(), // Disable second-degree user IDs
                    collect()  // Disable second-degree etab IDs
                );

                // return $publications->get();

                // $orderedPublications = getOrderedPublicationsWithPreferencesTest($publications, null);
                $publications = addBoosterToPublicationsToFlux( $publications, $request, 4, true );
            } else {
                // If no profile, get publications ordered by followers
                $publications = $publicationsQuery
                    ->selectRaw( '
                    COALESCE(
                        (SELECT COUNT(*) FROM follows WHERE follows.user_following_id = publications_flink.user_id),
                        0
                    ) + COALESCE(
                        (SELECT COUNT(*) FROM follows WHERE follows.etab_following_id = publications_flink.etab_id),
                        0
                    ) as total_followers
                ' )
                    ->orderBy( 'total_followers' );

                $orderedPublications = getOrderedPublicationsWithPreferencesTest( $publications, null );
                $publications = addBoosterToPublicationsToFlux( $orderedPublications, $request, 4, true );
            }

            // Transform the results
            $transformedPublications = $publications->through( function ( $publication ) use ( $profile, $followedSlugs, $savedPublicationIds ) {
                if ($publication instanceof Publication) {
                    $publication->sauvgardes = ( $publication->user_sauvgardes->count() ?? 0 ) + ( $publication->etab_sauvgardes->count() ?? 0 );

                    if ($profile) {
                        $ownerSlug = optional( $publication->etablissement )->slug ?? optional( $publication->user )->slug;
                        $publication->isFollowed = $ownerSlug && in_array( $ownerSlug, $followedSlugs );
                        $publication->isSavedByProfile = in_array( $publication->id, $savedPublicationIds );
                    }
                }
                return $publication;
            } );

            return response()->json( [
                'status' => 200,
                'data' => ["publications" => $transformedPublications],
            ] );

        } catch (\Exception $e) {
            return response()->json( [
                'status' => 500,
                'error' => 'An error occurred while fetching publications'
            ] );
        }
    }

    protected function buildPriorityQuery( $query, $directUserIds, $directEtabIds, $secondDegreeUserIds, $secondDegreeEtabIds )
    {
        // Prepare the IN clauses safely
        $directUserClause = $directUserIds->isNotEmpty() ? $directUserIds->implode( ',' ) : 'NULL';
        $directEtabClause = $directEtabIds->isNotEmpty() ? $directEtabIds->implode( ',' ) : 'NULL';

        $secondDegreeUserClause = $secondDegreeUserIds->isNotEmpty() ? $secondDegreeUserIds->implode( ',' ) : 'NULL';
        $secondDegreeEtabClause = $secondDegreeEtabIds->isNotEmpty() ? $secondDegreeEtabIds->implode( ',' ) : 'NULL';

        return $query->addSelect( DB::raw( "
            CASE 
                WHEN user_id IN ({$directUserClause}) THEN 1
                WHEN etab_id IN ({$directEtabClause}) THEN 1
                WHEN user_id IN ({$secondDegreeUserClause}) THEN 2
                WHEN etab_id IN ({$secondDegreeEtabClause}) THEN 2
                ELSE 3
            END as priority
        " ) )
            ->where( function ( $query ) use ( $directUserIds, $directEtabIds, $secondDegreeUserIds, $secondDegreeEtabIds ) {
                // Direct follows
                if ($directUserIds->isNotEmpty() || $directEtabIds->isNotEmpty()) {
                    $query->where( function ( $subQuery ) use ( $directUserIds, $directEtabIds ) {
                        if ($directUserIds->isNotEmpty()) {
                            $subQuery->orWhere( function ( $nestedQuery ) use ( $directUserIds ) {
                                $nestedQuery->whereIn( 'user_id', $directUserIds )->whereNull( 'etab_id' );
                            } );
                        }
                        if ($directEtabIds->isNotEmpty()) {
                            $subQuery->orWhereIn( 'etab_id', $directEtabIds );
                        }
                    } );
                }

                // // Second-degree follows
                // if ($secondDegreeUserIds->isNotEmpty() || $secondDegreeEtabIds->isNotEmpty()) {
                //     $query->orWhere(function($subQuery) use ($secondDegreeUserIds, $secondDegreeEtabIds) {
                //         if ($secondDegreeUserIds->isNotEmpty()) {
                //             $subQuery->orWhere(function($nestedQuery) use ($secondDegreeUserIds) {
                //                 $nestedQuery->whereIn('user_id', $secondDegreeUserIds)->whereNull('etab_id');
                //             });
                //         }
                //         if ($secondDegreeEtabIds->isNotEmpty()) {
                //             $subQuery->orWhereIn('etab_id', $secondDegreeEtabIds);
                //         }
                //     });
                // }
            } )
            ->orderBy( 'priority' )
            ->orderBy( 'created_at', 'desc' );
    }

}
