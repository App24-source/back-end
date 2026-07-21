<?php

namespace App\Http\Controllers\Home\functions\boostedPublications;

use App\Models\Publication;
use Illuminate\Support\Facades\DB;

class BoostedPublicationsService
{
    /**
     * Get only boosted publications (publications with active boost campaigns)
     * This function returns only publications that have active boost campaigns
     */
    public function getBoostedPublications($perPage = 4, $page = 1)
    {
        // Build query to get ONLY boosted publications
        $query = Publication::query()
            ->select([
                'id', 'is_marketplace', 'slug', 'reference', 'cat_g_id', 'user_id', 'etab_id', 
                'pays_id', 'ville_id', 'region_id', 'tele', 'tele_whatsapp', 
                'contact', 'description', 'image_default', 'images', 
                'video_origine', 'video_compresse', 'prix', 'prixPromo', 
                'disponibilite', 'disponibilite_date', 'created_at', 'score'
            ])
            ->where('status', 1)
            ->whereHas('publications_booster', function ($query) {
                $query->where('date_fin', '>', now()); // Only active boost campaigns
            })
            ->withCount([
                'publications_booster as booster' => function ($query) {
                    $query->where('date_fin', '>', now());
                }
            ])
            ->with([
                'pays:id,slug,name',
                'ville:id,slug,name,pays_id',
                'region:id,slug,name,ville_id',
                'tags:id,name,slug',
                'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                'etablissement:id,nom,logo,slug,is_verified,created_at',
                'modes_laivraison:id,name',
                'statistiques' => function ($query) {
                    $query->selectRaw('
                        publication_id,
                        SUM(click_contact) as click_contact,
                        SUM(click_tele) as click_tele,
                        SUM(click_tele_whatsapp) as click_tele_whatsapp,
                        SUM(click_partage) as click_partage,
                        SUM(click_detail) as click_detail,
                        SUM(vues) as vues
                    ')->groupBy('publication_id');
                },
                'user_sauvgardes:id,first_name,last_name',
                'etab_sauvgardes:id,nom',
                'publications_booster.shopping_publications',
                'publications_booster' => function($query) {
                    $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message')
                        ->where('date_fin', '>', now())
                        ->orderByDesc('id');
                },
            ])
            ->orderBy('created_at', 'desc');
        
        // Get all boosted publications
        $allBoostedPublications = $query->get();
        
        // Log the results for debugging
        \Log::info('BoostedPublicationsService - Found ' . $allBoostedPublications->count() . ' total boosted publications');
        
        // Log the FULL list of boosted publications
        \Log::info('BoostedPublicationsService - FULL BOOSTED LIST:');
        foreach ($allBoostedPublications as $index => $publication) {
            \Log::info('BoostedPublicationsService - #' . ($index + 1) . ' | ID: ' . $publication->id . ' | Score: ' . $publication->score . ' | Booster Count: ' . $publication->booster . ' | User: ' . $publication->user_id . ' | Etab: ' . $publication->etab_id);
        }
        
        // Create manual pagination
        $total = $allBoostedPublications->count();
        $offset = ($page - 1) * $perPage;
        $pagePublications = $allBoostedPublications->slice($offset, $perPage);
        
        // Create paginator-like object
        $publications = new \Illuminate\Pagination\LengthAwarePaginator(
            $pagePublications->values(),
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(), 
                'pageName' => 'page',
                'query' => request()->query()
            ]
        );
        
        // Log the paginated results
        $publicationIds = $publications->getCollection()->pluck('id')->toArray();
        \Log::info('BoostedPublicationsService - Page ' . $page . ' returned boosted publication IDs: ' . implode(',', $publicationIds));
        
        return $publications;
    }
}
