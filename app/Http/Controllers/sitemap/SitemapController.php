<?php

namespace App\Http\Controllers\sitemap;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Publication;
use App\Models\Tags;
use App\Models\Pays;
use App\Models\Ville;
use App\Models\Region;
use App\Models\User;
use App\Models\Etablissement;
use Carbon\Carbon;
use DateTime;

class SitemapController extends Controller
{
    // Sitemap for tags
    public function generateSitemapUrlsTags()
    {
        // Fetch the tags with their publications
        $tags = Tags::where( 'status', 1 )
            // ->whereHas('publications', function ($query) {
            //     $query->where('status', 1);
            // })
            ->with( 'publications' )
            ->get();


        $urls = [];
        foreach ($tags as $tag) {
            $lastMod = $tag->publications()->where( 'status', 1 )->orderByDesc( 'created_at' )->first();
            // $lastModFormattedDate = Carbon::parse($lastMod)->format('Y-m-d');
            $baseUrl = "/$tag->slug";
            $urls[] = $this->formatUrl( $baseUrl, $tag->compteur_search, $lastMod ? Carbon::parse( $lastMod->created_at )->format( 'Y-m-d' ) : null );
        }
        return $urls;
    }

    public function generateSitemapUrlsPays()
    {
        // Fetch the tags with their publications
        $tags = Tags::where( 'status', 1 )
            //    ->whereHas('publications', function ($query) {
            //        $query->where('status', 1);
            //    })
            ->get();

        // Fetch pays with their villes and regions
        $paysList = Pays::where( 'status', 1 )
            ->with( [
                'villes' => function ( $query ) {
                    $query->where( 'status', 1 )
                        ->with( [
                            'regions' => function ( $query ) {
                                $query->where( 'status', 1 );
                            }
                        ] );
                }
            ] )
            ->get();

        $urls = [];

        foreach ($tags as $tag) {
            $baseUrl = "/$tag->slug";
            // Pre-fetch related IDs to reduce repeated queries
            $publicationPaysIds = array_values( $tag->publications->pluck( 'pays_id' )->filter()->unique()->toArray() );

            $lastMod = $tag->publications()->where( 'status', 1 )->whereIn( 'pays_id', $publicationPaysIds )->orderByDesc( 'created_at' )->first();
            //    $lastModFormattedDate = Carbon::parse($lastMod)->format('Y-m-d');
            foreach ($paysList as $pays) {
                //    if(in_array($pays->id, $publicationPaysIds)) {
                $averagePays = ( $tag->compteur_search + $pays->compteur_search ) / 2;
                $urls[] = $this->formatUrl( "$baseUrl/$pays->slug", $averagePays, $lastMod ? Carbon::parse( $lastMod->created_at )->format( 'Y-m-d' ) : null );
                //    }
            }
        }
        return $urls;
    } 

    public function generateSitemapUrlsVilles()
    {
        // 1. Get all active Villes that have at least one active publication, ordered by ID
        $villes = Ville::where( 'status', 1 )
            ->whereHas( 'publications', function ( $query ) {
                $query->where( 'status', 1 );
            } )
            ->orderBy( 'id' )
            ->get();

        $urls = [];

        foreach ($villes as $ville) {
            // 2. Find all unique active tags attached to active publications in this ville
            // Filter: tag status = 1 and mngr_is_index = 1
            $tagsInVille = Tags::where( 'status', 1 )
                ->where( 'mngr_is_index', 1 )
                ->whereHas( 'publications', function ( $query ) use ($ville) {
                    $query->where( 'status', 1 )
                        ->where( 'ville_id', $ville->id );
                } )
                ->get();

            foreach ($tagsInVille as $tag) {
                // 3. Determine the latest modification date for this specific (tag, ville) combo
                $lastModPublication = $tag->publications()
                    ->where( 'status', 1 )
                    ->where( 'ville_id', $ville->id )
                    ->orderByDesc( 'updated_at' )
                    ->first();

                // Fallback to updated_at then created_at
                $lastModDate = $lastModPublication
                    ? ( $lastModPublication->updated_at ?: $lastModPublication->created_at )
                    : null;

                // Priority based on average weight of tag and city
                $averageWeight = ( $tag->compteur_search + $ville->compteur_search ) / 2;

                // URL structure: /{tag}/maroc/{ville}
                $urlPath = "/{$tag->slug}/maroc/{$ville->slug}";

                $urls[] = $this->formatUrl(
                    $urlPath,
                    $averageWeight,
                    $lastModDate ? Carbon::parse( $lastModDate )->format( 'Y-m-d' ) : null
                );
            }
        }

        return $urls;
    }


    public function generateSitemapUrlsRegions()
    {
        // 1. Get all active Regions that have at least one active publication (except toute-la-ville)
        $regions = Region::where( 'status', 1 )
            ->where( 'slug', '!=', 'toute-la-ville' )
            ->whereHas( 'publications', function ( $query ) {
                $query->where( 'status', 1 );
            } )
            ->with( [ 'ville.pays' ] )
            ->orderBy( 'id' )
            ->get();

        $urls = [];

        foreach ($regions as $region) {
            $ville = $region->ville;
            if (!$ville || !$ville->pays)
                continue;

            $pays = $ville->pays;

            // 2. Find all unique active tags attached to active publications in this region
            // Filter: tag status = 1 and mngr_is_index = 1
            $tagsInRegion = Tags::where( 'status', 1 )
                ->where( 'mngr_is_index', 1 )
                ->whereHas( 'publications', function ( $query ) use ($region) {
                    $query->where( 'status', 1 )
                        ->where( 'region_id', $region->id );
                } )
                ->get();

            foreach ($tagsInRegion as $tag) {
                // 3. Determine the latest modification date for this specific (tag, region) combo
                $lastModPublication = $tag->publications()
                    ->where( 'status', 1 )
                    ->where( 'region_id', $region->id )
                    ->orderByDesc( 'updated_at' )
                    ->first();

                // Fallback to updated_at then created_at
                $lastModDate = $lastModPublication
                    ? ( $lastModPublication->updated_at ?: $lastModPublication->created_at )
                    : null;

                // Priority based on average weight of tag and region
                $averageWeight = ( $tag->compteur_search + $region->compteur_search ) / 2;

                // URL structure: /{tag}/{pays}/{ville}/{region}
                $urlPath = "/{$tag->slug}/{$pays->slug}/{$ville->slug}/{$region->slug}";

                $urls[] = $this->formatUrl(
                    $urlPath,
                    $averageWeight,
                    $lastModDate ? Carbon::parse( $lastModDate )->format( 'Y-m-d' ) : null
                );
            }
        }

        return $urls;
    }




    public function generateSitemapUrlsPosts()
    {
        // $publications = Publication::where('status', 1)
        // ->pluck('slug');
        $publications = Publication::where( 'status', 1 )
            ->select( 'slug', 'updated_at' )
            ->get();

        return response()->json( [
            'status' => 201,
            'data' => $publications,
        ] );
    }
    // public function generateSitemapUrls()
    // {
    //    // Fetch the tags with their publications
    //    $tags = Tags::where('status', 1)
    //    ->whereHas('publications', function ($query) {
    //        $query->where('status', 1);
    //    })
    //    // ->limit(50)
    //    ->get();

    //    // Fetch pays with their villes and regions
    //    $paysList = Pays::where('status', 1)
    //        ->with(['villes' => function ($query) {
    //            $query->where('status', 1)
    //                ->with(['regions' => function ($query) {
    //                    $query->where('status', 1);
    //                }]);
    //        }])
    //        ->get();

    //    $urls = [];

    //    foreach ($tags as $tag) {
    //        $baseUrl = "/$tag->slug";
    //        $urls[] = $this->formatUrl($baseUrl, $tag->compteur_search);
    //        // Pre-fetch related IDs to reduce repeated queries
    //        $publicationPaysIds = array_values($tag->publications->pluck('pays_id')->filter()->unique()->toArray());
    //        $publicationVilleIds = array_values($tag->publications->pluck('ville_id')->filter()->unique()->toArray());
    //        $publicationRegionIds = array_values($tag->publications->pluck('region_id')->filter()->unique()->toArray());

    //        foreach ($paysList as $pays) {
    //            if(in_array($pays->id, $publicationPaysIds)) {
    //                $averagePays = ($tag->compteur_search + $pays->compteur_search) / 2;
    //                $urls[] = $this->formatUrl("$baseUrl/$pays->slug", $averagePays);
    //            }
    //            foreach ($pays->villes as $ville) {
    //                if(in_array($ville->id, $publicationVilleIds)) {
    //                    $averageVille = ($tag->compteur_search + $pays->compteur_search + $ville->compteur_search) / 3;
    //                    $urls[] = $this->formatUrl("$baseUrl/$pays->slug/$ville->slug", $averageVille);
    //                }
    //                foreach ($ville->regions as $region) {
    //                    if(in_array($region->id, $publicationRegionIds)) {
    //                        $averageRegion = ($tag->compteur_search + $pays->compteur_search + $ville->compteur_search + $region->compteur_search) / 4;
    //                        $urls[] = $this->formatUrl("$baseUrl/$pays->slug/$ville->slug/$region->slug", $averageRegion);
    //                    }
    //                }
    //            }
    //        }


    //    }
    //    return $urls;
    // }



    public function generateSitemapUrlsEtablissements()
    {
        $establishments = Etablissement::select( 'id', 'slug', 'is_verified', 'updated_at', 'created_at' )
            ->whereNotNull( 'slug' )
            ->where( 'slug', '!=', '' )
            ->withCount( [
                'publications as publications_count',
            ] )
            ->orderByDesc( 'updated_at' )
            ->get();

        $urls = $establishments->map( function ( $establishment ) {
            $lastMod = $establishment->updated_at ?: $establishment->created_at ?: Carbon::now();

            // Calculate priority: base 0.5, +0.2 if verified, +0.1 if has publications, +0.1 if 10+ publications
            $priority = 0.5;
            if ($establishment->is_verified) {
                $priority += 0.2;
            }
            if ($establishment->publications_count > 0) {
                $priority += 0.1;
            }
            if ($establishment->publications_count >= 10) {
                $priority += 0.1;
            }
            $priority = min( $priority, 0.9 ); // Cap at 0.9

            // Calculate a single sort score: priority * 1000000 + publications_count * 1000 + (verified ? 1 : 0)
            // This ensures proper multi-level sorting
            $sortScore = ( $priority * 1000000 ) + ( $establishment->publications_count * 1000 ) + ( $establishment->is_verified ? 1 : 0 );

            return [
                'url' => "/profile/@{$establishment->slug}",
                'percentage' => $priority,
                'lastMod' => Carbon::parse( $lastMod )->format( 'Y-m-d' ),
                'publications_count' => (int) $establishment->publications_count,
                '_sort_score' => $sortScore, // Temporary field for sorting
            ];
        } )
            ->sortByDesc( '_sort_score' )
            ->map( function ( $item ) {
                // Remove temporary sorting field
                unset($item['_sort_score']);
                return $item;
            } )
            ->values();

        return response()->json( $urls );
    }


    private function formatUrl( $url, $count, $lastMod = null )
    {
        $min = 100;
        $max = 500;
        return [
            'url' => $url,
            'percentage' => ( $count < $min ? 0.3 : ( $count > $max ? 0.9 : 0.5 ) ),
            'lastMod' => $lastMod,
        ];
    }

}
