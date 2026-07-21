<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Annonce;
use App\Models\Caracteristiques;
use App\Models\publicite;
use App\Models\Annonces_booster;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\Marque;
use App\Models\Modeles;
use App\Models\Annee;
use App\Models\Immatriculation;
use App\Models\Fiscale;
use App\Models\Carburant;
use App\Models\Etablissement;
use App\Models\Categorie;
use App\Models\EmploiActivite;
use App\Models\EmploiContrat;
use App\Models\EmploiEtude;
use App\Models\EmploiExperience;
use App\Models\EmploiFonction;
use App\Models\EmploiSalaire;
use App\Models\EmploiTravail;
use App\Models\ImmoCaractiristique;
use App\Models\DomainFormation;
use App\Models\teleMarque;
use App\Models\teleModel;
use App\Models\TypeVetement;
use App\Models\TailleVetement;
use Illuminate\Support\Facades\Http;
use Stevebauman\Location\Facades\Location;
use App\Models\NadeSearch;



class AnnonceController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function AllAnnonces(Request $request) {

        $selectedSlug = $request->query('slug');
        $recherche = str_replace('-', ' ', $request->query('recherche'));
    
        try{
            // Common query builder
            $initialQuery = Annonce::select('id', 'type_annonce', 'type_transaction', 'contact_method', 'description', 'prix', 'image', 'images', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'reference', 'tele', 'date_order', 
            'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'marque_id', 'model_id', 'transmission', 'carburant_id', 'video_1', 
            'kilometrage', 'annee_id', 'dedounee', 'prixPromo', 'prixRemise')
            ->where('show_in_nade', 1)
            ->with('user:id,first_name,last_name,avatar,email_verified_at,is_verified', 'etablissement:id,nom,logo,numero_telephone,is_verified', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'marque', 'model', 'annee:id,name', 'carburant:id,name', 'candidats', 'annonces_booster')
            ->orderBy('date_order', 'desc');


            // en cas de slug exists
            if($selectedSlug){
                $annonceWithSlug = Annonce::where('slug', $selectedSlug)->first();
                if($annonceWithSlug)
                $initialQuery->where('date_order', '<=', $annonceWithSlug->date_order)->orderBy('date_order', 'desc');
            }
            
            
            $city = $request->query('city');

            $villes = $request->query('villes');

            $regions = $request->query('regions');
            
            $i = 0;

            $filters = ['', 'region',  'city', ]; 
            do {
                $query = clone $initialQuery;

                $queryFilter = filter($request, $query, $filters[$i]);

                $annonces = $queryFilter[0];  // Update $annonces with the new filter result
                $count = count($annonces);    // Recheck count after applying the filter
                $i++;
                // If no results, reset the next filter
            } while ($count == 0 && $i < count($filters));

            
            $annonces = $queryFilter[0];
            $isEmploi = $queryFilter[1];
            $resetFilter = $queryFilter[2];

            // Current datetime
            $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');

            // Annonces Booster
            $annoncesBooster = Annonces_booster::
            where('date_fin', '>=', $currentDateTime)
            ->with([
                'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
                'annonce.user:id,first_name,last_name,avatar,is_verified',
                'annonce.etablissement:id,nom,logo,is_verified',
                'annonce.categorie:id,id2,name,slug',
                'annonce.region:id,name,slug',
                'annonce.ville:id,name',
                'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
                'etablissement:id,nom,logo',
                'user:id,first_name,last_name,avatar'
            ])->orderBy('created_at', 'desc')->get();

            // Publicites
            $publicites = publicite::
            where('date_fin', '>=', $currentDateTime)
            ->with('etablissement:id,nom,logo,is_verified')->orderBy('created_at', 'desc')->get();

            //jobs
            $annoncesEmploi = Annonce::select('id', 'description', 'prix', 'images', 'video_1', 'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'date_order')
            ->where('show_in_nade', 1)
            ->whereHas('categorie', function ($query) {
                $query->where('id2', 200);
            })
            ->with('user:id,first_name,last_name,avatar,is_verified', 'etablissement:id,nom,logo,is_verified', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'candidats')
            ->orderBy('date_order', 'desc')
            ->get();

            $annoncesVideo = Annonce::select('id', 'description', 'video_1', 'img_video_1', 'type_annonce', 'contact_method',
                'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'reference', 'cat_g_id', 
                'region_id', 'ville_id', 'infos')
                ->where('show_in_nade', 1)
                ->wherenot('video_1', NULL)
                ->with('user:id,first_name,last_name,avatar,is_verified', 'etablissement:id,nom,logo,is_verified', 'categorie:id,id2,name,slug',
                'region:id,name,slug', 'ville:id,name,slug', )
                ->orderBy('date_order','desc')
                ->get();


            // test
            $pubImage = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'image')
            ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
            $pubVideo = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'video')
            ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
            $pubCarosel = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'carosel')
            ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
            $annoncesBooster1 = Annonces_booster::
            where('date_fin', '>=', $currentDateTime)
            ->with([
                'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
                'annonce.user:id,first_name,last_name,avatar',
                'annonce.etablissement:id,nom,logo',
                'annonce.categorie:id,id2,name,slug',
                'annonce.region:id,name,slug',
                'annonce.ville:id,name',
                'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
                'etablissement:id,nom,logo',
                'user:id,first_name,last_name,avatar'
            ])->orderBy('created_at', 'desc')->first();
            $annoncesEmploiTest = Annonce::select('id', 'description', 'prix', 'images', 'video_1', 'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'date_order')
            ->where('show_in_nade', 1)
            ->whereHas('categorie', function ($query) {
                $query->where('id2', 200);
            })
            ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'candidats')
            ->orderBy('date_order', 'desc')
            ->first();
            $annoncesVideoTest = Annonce::select('id', 'description', 'video_1', 'img_video_1', 'type_annonce', 'contact_method',
            'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'reference', 'cat_g_id', 
            'region_id', 'ville_id', 'infos')
            ->where('show_in_nade', 1)
            ->wherenot('video_1', NULL)
            ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug',
            'region:id,name,slug', 'ville:id,name,slug', )
            ->orderBy('date_order','desc')
            ->first();

            $testArrayHome = [$pubCarosel, $annoncesVideoTest];
            $page = (int)$request->query('page');


            $annonces_Boosters_Publicites = [];
            $annonces_Boosters_Publicites = 
            global_function($annonces,
                $annoncesBooster, "booster_index", 
                $publicites, "publicite_index", 
                $page);

            
            $CacheVideoIndex = Cache::get('index_video' , 0);
            $RandomVideos = Cache::get('random_video' , 10);
            $RandomJobs = Cache::get('random_jobs' , 15);

            if($page == 1){
                // $RandomVideos = rand(200, 400);
                $RandomVideos = 10;
                // $RandomJobs = rand(55, 59);
                $RandomJobs = 15;
            }

            $jobsCount = count($annoncesEmploi);
            $videosCount = count($annoncesVideo);
            $annonceCount = 0;
            // if($CacheVideoIndex >= $videosCount){
            //     $CacheVideoIndex = 0;
            // }
            $VideoIndex = $CacheVideoIndex;

            $p1 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
            ->find(4943);
            $p2 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
            ->find(4944);
            $p3 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
            ->find(4945);
            $p4 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
            ->find(4953);

            $testAnnonces = [$p4, $p1, $p2, $p3];
            
            $totalAnnonces = count($annonces);
            $annonces_Boosters_Publicites_emplois = [];

            // foreach ($testAnnonces as $annonce){
            //     $annonces_Boosters_Publicites_emplois[] = $annonce;
            // }

            // $annonces_Boosters_Publicites_emplois[] = $annoncePubImage;
            // $annonces_Boosters_Publicites_emplois[] = $annoncePubCarosel;

            // $annonces_Boosters_Publicites_emplois[] = $annoncePubVideo;

            // $annonces_Boosters_Publicites_emplois[] = $annoncesNew;
            
            foreach ($annonces_Boosters_Publicites as $annonce) {
                
                $annonces_Boosters_Publicites_emplois[] = $annonce;
                $annonceCount++;

                // Ajouter une video après nombre random annonces
                if ($annonceCount % $RandomVideos === 0 && $videosCount > 0 && $videosCount > $VideoIndex) {
                    
                    // Ajouter la video courante
                    $annonces_Boosters_Publicites_emplois[] = $annoncesVideo[$VideoIndex];
                    if($VideoIndex == $videosCount - 1){
                        // Initialise video Index
                        $VideoIndex = 0;
                    }else{
                        $VideoIndex++;
                    }
                    // $VideoIndex = ($VideoIndex + 1) % $videosCount;
                    
                }

                // Ajouter jobs après chaque nombre random annonces
                if ($annonceCount % $RandomJobs == 0 && $jobsCount > 0) {
                    // Ajouter jobs courante
                    $selectedJobs = [$annoncesEmploi[rand(0, $jobsCount-1)], $annoncesEmploi[rand(0, $jobsCount-1)], $annoncesEmploi[rand(0, $jobsCount-1)]];
                    $annonces_Boosters_Publicites_emplois[] = $selectedJobs;
                }
            }
            
            if($page == 1 && $videosCount > 0){
                // $CacheVideoIndex = ($CacheVideoIndex + 1);
                $CacheVideoIndex = ($CacheVideoIndex + 1) % $videosCount;
            }

            // Pagination 
            $perPage = 6;
            $offset = ($page - 1) * $perPage;

            // Récupère toutes les annonces avec pagination
            $paginatedAnnonces = array_slice($annonces_Boosters_Publicites_emplois, $offset, $perPage);
            $total = count($annonces_Boosters_Publicites_emplois);

            
            Cache::put('index_video', $CacheVideoIndex, 60 * 24 * 3); // Le cache est valide pour 3 jours (60 *24 * 3)
            Cache::put('random_video', $RandomVideos, 60 * 24); // Le cache est valide pour 3 jours (60 *24 * 3)
            Cache::put('random_jobs', $RandomJobs, 60 * 24); // Le cache est valide pour 3 jours (60 *24 * 3)

            return response()->json(['status' => 200, 'data' => ["current_page"=>$page, "perPage"=>$perPage,"annonces"=>$paginatedAnnonces, "total"=>$total, "isEmploi"=>$isEmploi,"totalAnnonces"=>$totalAnnonces , 'resetFilter'=>$resetFilter]]);

        } catch (\Exception $e) {
            // Handle any errors that occur during the query
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }

    // public function AllAnnoncesTest(Request $request) {

    //     // $selectedSlug = $request->query('slug');
    //     // $recherche = str_replace('-', ' ', $request->query('recherche'));
    
    //     try{
    //         // Common query builder
    //         $initialQuery = Annonce::select('id', 'type_annonce', 'type_transaction', 'contact_method', 'description', 'prix', 'image', 'images', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'reference', 'tele', 'date_order', 
    //         'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'marque_id', 'model_id', 'transmission', 'carburant_id', 'video_1', 
    //         'kilometrage', 'annee_id', 'dedounee', 'prixPromo', 'prixRemise')
    //         ->where('show_in_nade', 1)
    //         ->with('user:id,first_name,last_name,avatar,email_verified_at,is_verified', 'etablissement:id,nom,logo,numero_telephone,is_verified', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'marque', 'model', 'annee:id,name', 'carburant:id,name', 'candidats', 'annonces_booster')
    //         ->orderBy('date_order', 'desc');
    //         $annonces = $initialQuery->limit(10)->get();



    //         // en cas de slug exists
    //         // if($selectedSlug){
    //         //     $annonceWithSlug = Annonce::where('slug', $selectedSlug)->first();
    //         //     if($annonceWithSlug)
    //         //     $initialQuery->where('date_order', '<=', $annonceWithSlug->date_order)->orderBy('date_order', 'desc');
    //         // }
            
            
    //         // $city = $request->query('city');

    //         // $villes = $request->query('villes');

    //         // $regions = $request->query('regions');
            
    //         // $i = 0;

    //         // $filters = ['', 'region',  'city', ]; 
    //         // do {
    //         //     $query = clone $initialQuery;

    //         //     $queryFilter = filter($request, $query, $filters[$i]);

    //         //     $annonces = $queryFilter[0];  // Update $annonces with the new filter result
    //         //     $count = count($annonces);    // Recheck count after applying the filter
    //         //     $i++;
    //         //     // If no results, reset the next filter
    //         // } while ($count == 0 && $i < count($filters));

            
    //         // $annonces = $queryFilter[0];
    //         // $isEmploi = $queryFilter[1];
    //         // $resetFilter = $queryFilter[2];

    //         // // Current datetime
    //         // $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');

    //         // // Annonces Booster
    //         // $annoncesBooster = Annonces_booster::
    //         // where('date_fin', '>=', $currentDateTime)
    //         // ->with([
    //         //     'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //         //     'annonce.user:id,first_name,last_name,avatar,is_verified',
    //         //     'annonce.etablissement:id,nom,logo,is_verified',
    //         //     'annonce.categorie:id,id2,name,slug',
    //         //     'annonce.region:id,name,slug',
    //         //     'annonce.ville:id,name',
    //         //     'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
    //         //     'etablissement:id,nom,logo',
    //         //     'user:id,first_name,last_name,avatar'
    //         // ])->orderBy('created_at', 'desc')->get();

    //         // // Publicites
    //         // $publicites = publicite::
    //         // where('date_fin', '>=', $currentDateTime)
    //         // ->with('etablissement:id,nom,logo,is_verified')->orderBy('created_at', 'desc')->get();

    //         // //jobs
    //         // $annoncesEmploi = Annonce::select('id', 'description', 'prix', 'images', 'video_1', 'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'date_order')
    //         // ->where('show_in_nade', 1)
    //         // ->whereHas('categorie', function ($query) {
    //         //     $query->where('id2', 200);
    //         // })
    //         // ->with('user:id,first_name,last_name,avatar,is_verified', 'etablissement:id,nom,logo,is_verified', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'candidats')
    //         // ->orderBy('date_order', 'desc')
    //         // ->get();

    //         // $annoncesVideo = Annonce::select('id', 'description', 'video_1', 'img_video_1', 'type_annonce', 'contact_method',
    //         //     'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'reference', 'cat_g_id', 
    //         //     'region_id', 'ville_id', 'infos')
    //         //     ->where('show_in_nade', 1)
    //         //     ->wherenot('video_1', NULL)
    //         //     ->with('user:id,first_name,last_name,avatar,is_verified', 'etablissement:id,nom,logo,is_verified', 'categorie:id,id2,name,slug',
    //         //     'region:id,name,slug', 'ville:id,name,slug', )
    //         //     ->orderBy('date_order','desc')
    //         //     ->get();


    //         // // test
    //         // $pubImage = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'image')
    //         // ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
    //         // $pubVideo = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'video')
    //         // ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
    //         // $pubCarosel = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'carosel')
    //         // ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
    //         // $annoncesBooster1 = Annonces_booster::
    //         // where('date_fin', '>=', $currentDateTime)
    //         // ->with([
    //         //     'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //         //     'annonce.user:id,first_name,last_name,avatar',
    //         //     'annonce.etablissement:id,nom,logo',
    //         //     'annonce.categorie:id,id2,name,slug',
    //         //     'annonce.region:id,name,slug',
    //         //     'annonce.ville:id,name',
    //         //     'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
    //         //     'etablissement:id,nom,logo',
    //         //     'user:id,first_name,last_name,avatar'
    //         // ])->orderBy('created_at', 'desc')->first();
    //         // $annoncesEmploiTest = Annonce::select('id', 'description', 'prix', 'images', 'video_1', 'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'date_order')
    //         // ->where('show_in_nade', 1)
    //         // ->whereHas('categorie', function ($query) {
    //         //     $query->where('id2', 200);
    //         // })
    //         // ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'candidats')
    //         // ->orderBy('date_order', 'desc')
    //         // ->first();
    //         // $annoncesVideoTest = Annonce::select('id', 'description', 'video_1', 'img_video_1', 'type_annonce', 'contact_method',
    //         // 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'reference', 'cat_g_id', 
    //         // 'region_id', 'ville_id', 'infos')
    //         // ->where('show_in_nade', 1)
    //         // ->wherenot('video_1', NULL)
    //         // ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug',
    //         // 'region:id,name,slug', 'ville:id,name,slug', )
    //         // ->orderBy('date_order','desc')
    //         // ->first();

    //         // $testArrayHome = [$pubCarosel, $annoncesVideoTest];
    //         // $page = (int)$request->query('page');


    //         // $annonces_Boosters_Publicites = [];
    //         // $annonces_Boosters_Publicites = 
    //         // global_function($annonces,
    //         //     $annoncesBooster, "booster_index", 
    //         //     $publicites, "publicite_index", 
    //         //     $page);

            
    //         // $CacheVideoIndex = Cache::get('index_video' , 0);
    //         // $RandomVideos = Cache::get('random_video' , 10);
    //         // $RandomJobs = Cache::get('random_jobs' , 15);

    //         // if($page == 1){
    //         //     // $RandomVideos = rand(200, 400);
    //         //     $RandomVideos = 10;
    //         //     // $RandomJobs = rand(55, 59);
    //         //     $RandomJobs = 15;
    //         // }

    //         // $jobsCount = count($annoncesEmploi);
    //         // $videosCount = count($annoncesVideo);
    //         // $annonceCount = 0;
    //         // // if($CacheVideoIndex >= $videosCount){
    //         // //     $CacheVideoIndex = 0;
    //         // // }
    //         // $VideoIndex = $CacheVideoIndex;

    //         // $p1 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //         // ->find(4943);
    //         // $p2 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //         // ->find(4944);
    //         // $p3 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //         // ->find(4945);
    //         // $p4 = Annonce::with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //         // ->find(4953);

    //         // $testAnnonces = [$p4, $p1, $p2, $p3];
            
    //         // $totalAnnonces = count($annonces);
    //         // $annonces_Boosters_Publicites_emplois = [];

    //         // // foreach ($testAnnonces as $annonce){
    //         // //     $annonces_Boosters_Publicites_emplois[] = $annonce;
    //         // // }

    //         // // $annonces_Boosters_Publicites_emplois[] = $annoncePubImage;
    //         // // $annonces_Boosters_Publicites_emplois[] = $annoncePubCarosel;

    //         // // $annonces_Boosters_Publicites_emplois[] = $annoncePubVideo;

    //         // // $annonces_Boosters_Publicites_emplois[] = $annoncesNew;
            
    //         // foreach ($annonces_Boosters_Publicites as $annonce) {
                
    //         //     $annonces_Boosters_Publicites_emplois[] = $annonce;
    //         //     $annonceCount++;

    //         //     // Ajouter une video après nombre random annonces
    //         //     if ($annonceCount % $RandomVideos === 0 && $videosCount > 0 && $videosCount > $VideoIndex) {
                    
    //         //         // Ajouter la video courante
    //         //         $annonces_Boosters_Publicites_emplois[] = $annoncesVideo[$VideoIndex];
    //         //         if($VideoIndex == $videosCount - 1){
    //         //             // Initialise video Index
    //         //             $VideoIndex = 0;
    //         //         }else{
    //         //             $VideoIndex++;
    //         //         }
    //         //         // $VideoIndex = ($VideoIndex + 1) % $videosCount;
                    
    //         //     }

    //         //     // Ajouter jobs après chaque nombre random annonces
    //         //     if ($annonceCount % $RandomJobs == 0 && $jobsCount > 0) {
    //         //         // Ajouter jobs courante
    //         //         $selectedJobs = [$annoncesEmploi[rand(0, $jobsCount-1)], $annoncesEmploi[rand(0, $jobsCount-1)], $annoncesEmploi[rand(0, $jobsCount-1)]];
    //         //         $annonces_Boosters_Publicites_emplois[] = $selectedJobs;
    //         //     }
    //         // }
            
    //         // if($page == 1 && $videosCount > 0){
    //         //     // $CacheVideoIndex = ($CacheVideoIndex + 1);
    //         //     $CacheVideoIndex = ($CacheVideoIndex + 1) % $videosCount;
    //         // }

    //         // // Pagination 
    //         // $perPage = 60;
    //         // $offset = ($page - 1) * $perPage;

    //         // // Récupère toutes les annonces avec pagination
    //         // $paginatedAnnonces = array_slice($annonces_Boosters_Publicites_emplois, $offset, $perPage);
    //         // $total = count($annonces_Boosters_Publicites_emplois);

            
    //         // Cache::put('index_video', $CacheVideoIndex, 60 * 24 * 3); // Le cache est valide pour 3 jours (60 *24 * 3)
    //         // Cache::put('random_video', $RandomVideos, 60 * 24); // Le cache est valide pour 3 jours (60 *24 * 3)
    //         // Cache::put('random_jobs', $RandomJobs, 60 * 24); // Le cache est valide pour 3 jours (60 *24 * 3)

    //         return response()->json(['status' => 201, 'data' => 
    //         // ["current_page"=>$page, "perPage"=>$perPage,"annonces"=>$paginatedAnnonces, "total"=>$total, "isEmploi"=>$isEmploi,"totalAnnonces"=>$totalAnnonces , 'resetFilter'=>$resetFilter]
    //         $annonces    
    //     ]);

    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getPubliciteText(){
    //     $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');
    //     $pubIndex = Cache::get('pub_index' , 0);
    //     try{
    //         $publicites = publicite::where('format_pub','text')->where('date_fin', '>=', $currentDateTime)
    //         ->with('etablissement:id,nom,logo')->get();

    //         $pubCount = count($publicites);

    //         $publicite = [];

    //         if ($pubCount > 0) {
    //             $publicite[] = $publicites[$pubIndex];
    //             $pubIndex = ($pubIndex + 1) % $pubCount;
    //         }
    //         Cache::put('pub_index', $pubIndex, 60 * 24 * 3); // Le cache est valide pour 3 jours (60 *24 * 3)

    //         return response()->json(['status' => 200, 'data'=>$publicite]);
    //     }catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
        
    // }

    // public function getAnnoncesBooster(){
    //     $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');
    //     try {
    //         $annoncesBoosterIndex = Cache::get('annonce_booster_index', 0);
    //         $annonces_Boosters = [];
    //         $annoncesBooster = Annonces_booster::where('date_fin', '>=', $currentDateTime)
    //         ->with([
    //             'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //             'annonce.user:id,first_name,last_name,avatar',
    //             'annonce.etablissement:id,nom,logo',
    //             'annonce.categorie:id,id2,name,slug',
    //             'annonce.region:id,name,slug',
    //             'annonce.ville:id,name',
    //             'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
    //             'etablissement:id,nom,logo',
    //             'user:id,first_name,last_name,avatar'
    //         ])
    //         ->whereHas('annonce.categorie', function ($query) {
    //             $query->where('id2', '!=', 200);
    //         })
    //         ->orderBy('id', 'asc')
    //         ->get();


    //         $boosterCount = count($annoncesBooster);
    //         for ($i=0;$i<4;$i++){
    //             // Ajouter une annonce booster après nombre random annonces
    //             if ($boosterCount > 0) { 
    //                 if($annoncesBoosterIndex >= $boosterCount){
    //                     // Initialise Annonce Booster Index
    //                     $annoncesBoosterIndex = 0;
    //                 }
    //                 // Ajouter l'annonce booster courante
    //                 $annonces_Boosters[] = $annoncesBooster[$annoncesBoosterIndex];
    //                 $annoncesBoosterIndex++;
    //             }

    //         }
    //         Cache::put('annonce_booster_index', $annoncesBoosterIndex, 60 * 24 * 3); // Le cache est valide pour 24 heures (60 *24)

    //         return response()->json(['status' => 200, 'data' => $annonces_Boosters]);
        
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    public function AllAnnoncesVideo(Request $request){ 
        $categorie = $request->query('categorie');
        $selectedSlug = $request->query('slug');
        try{
            $query = Annonce::select('id', 'type_annonce', 'contact_method', 'description', 'prix', 'images', 'video_1', 
            'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'reference', 'tele', 'date_order', 
            'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'marque_id', 'model_id', 'transmission', 'carburant_id', 
            'kilometrage', 'annee_id', 'dedounee')
            ->where('show_in_nade', 1)
            ->wherenot('video_1', null)
            ->with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
            ->orderBy('date_order', 'desc');

            // en cas de slug exists
            if($selectedSlug){
                $annonceWithSlug = Annonce::where('slug', $selectedSlug)->first();
                if($annonceWithSlug)
                $query->where('date_order', '<=', $annonceWithSlug->date_order)->orderBy('date_order', 'desc');

            }

            if ($categorie) {
                $CategorieObject = Categorie::where('slug', $categorie)->first();
                if($CategorieObject) {
                    
                    if($CategorieObject->id == 2){
                        $query->whereHas('categorie', function ($q) {
                            $q->whereIn('id2', [40, 50, 60, 70, 80, 90]);
                        });
                    } else {
                        
                        $query->whereHas('categorie', function ($q) use ($CategorieObject) {
                            $q->where('id2', $CategorieObject->id);
                        });
                    }
                }
            }
            $annoncesVideo = $query->get();

            // Current datetime
            $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');

            // Annonces Booster
            $annoncesBooster = Annonces_booster::
            where('date_fin', '>=', $currentDateTime)
            ->with([
                'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
                'annonce.user:id,first_name,last_name,avatar',
                'annonce.etablissement:id,nom,logo',
                'annonce.categorie:id,id2,name,slug',
                'annonce.region:id,name,slug',
                'annonce.ville:id,name',
                'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
                'etablissement:id,nom,logo',
                'user:id,first_name,last_name,avatar'
            ])->orderBy('created_at', 'desc')->get();

            // Publicites
            $publicites = publicite::
            where('date_fin', '>=', $currentDateTime)
            ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->get();

            $totalAnnoncesVideo = count($annoncesVideo);

            if($request->query('page')) {
                $page = (int)$request->query('page');
            } else $page = 1;
            

            $annoncesVideo_Boosters_Publicites = [];
            $annoncesVideo_Boosters_Publicites = 
            global_function($annoncesVideo,
                $annoncesBooster, "booster_index",
                $publicites, "publicite_index", 
                $page);


            // Pagination 
            $perPage = 6;
            $offset = ($page - 1) * $perPage;

            // Récupère toutes les annonces avec pagination
            $paginatedAnnonces = array_slice($annoncesVideo_Boosters_Publicites, $offset, $perPage);
            $total = count($annoncesVideo_Boosters_Publicites);
            $lastPage = (int) ceil($total / $perPage);

            return response()->json(['status' => 200, 'data' => ["current_page"=>$page, "perPage"=>$perPage,"annoncesVideo"=>$paginatedAnnonces, "total"=>$total,"totalAnnoncesVideo"=>$totalAnnoncesVideo, "last_page"=>$lastPage]]);

            return response()->json(['status' => 200, 'data'=>$annoncesVideo]);
        }catch (\Exception $e) {
            // Handle any errors that occur during the query
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }

    // public function AnnoncesVideoFilters(Request $request, $categorieId){ 
    //     // $categorie = $request->query('categorie');
    //     $slug = $request->query('slug');
    //     try{
            

    //         if($slug) {
    //             $query = Annonce::select('id', 'type_annonce', 'contact_method', 'description', 'prix', 'images', 'video_1', 
    //             'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'reference', 'tele', 'date_order', 
    //             'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'marque_id', 'model_id', 'transmission', 'carburant_id', 
    //             'kilometrage', 'annee_id', 'dedounee')
    //             ->where('show_in_nade', 1)
    //             ->where('cat_g_id', $categorieId)
    //             ->whereNotNull('video_1')
    //             ->where('slug', $slug)
    //             ->with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //             ->union(
    //                 Annonce::select('id', 'type_annonce', 'contact_method', 'description', 'prix', 'images', 'video_1', 
    //                         'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'reference', 'tele', 'date_order', 
    //                         'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'marque_id', 'model_id', 'transmission', 'carburant_id', 
    //                         'kilometrage', 'annee_id', 'dedounee')
    //                     ->where('show_in_nade', 1)
    //                     ->where('cat_g_id', $categorieId)
    //                     ->whereNotNull('video_1')
    //                     ->where('slug', '!=', $slug)
    //                     ->with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //                     ->orderBy('date_order', 'desc')
    //             );
    //         } else {
    //             $query = Annonce::select('id', 'type_annonce', 'contact_method', 'description', 'prix', 'images', 'video_1', 
    //             'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'reference', 'tele', 'date_order', 
    //             'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'marque_id', 'model_id', 'transmission', 'carburant_id', 
    //             'kilometrage', 'annee_id', 'dedounee')
    //             ->where('show_in_nade', 1)
    //             ->whereHas('categorie', function ($query) use ($categorieId) {$query->where('id', $categorieId);})
    //             ->wherenot('video_1', null)
    //             ->with('user:id,first_name,last_name,avatar,email_verified_at', 'etablissement:id,nom,logo,numero_telephone', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'annee:id,name', 'carburant:id,name', 'candidats')
    //             ->orderBy('date_order', 'desc');
    //         }


    //         $queryFilter = filter($request, $query, "");

    //         // if ($categorie) {
    //         //     $CategorieObject = Categorie::where('slug', $categorie)->first();
    //         //     if($CategorieObject) {
                    
    //         //         if($CategorieObject->id == 2){
    //         //             $query->whereHas('categorie', function ($q) {
    //         //                 $q->whereIn('id2', [40, 50, 60, 70, 80, 90]);
    //         //             });
    //         //         } else {
                        
    //         //             $query->whereHas('categorie', function ($q) use ($CategorieObject) {
    //         //                 $q->where('id2', $CategorieObject->id);
    //         //             });
    //         //         }
    //         //     }
    //         // }
    //         $annoncesVideoFiletrs = $queryFilter[0];
    //         // return $annoncesVideoFiletrs;

    //         // $annoncesVideo = $query->get();

    //         // // Current datetime
    //         // $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');

    //         // // Annonces Booster
    //         // $annoncesBooster = Annonces_booster::
    //         // where('date_fin', '>=', $currentDateTime)
    //         // ->with([
    //         //     'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //         //     'annonce.user:id,first_name,last_name,avatar',
    //         //     'annonce.etablissement:id,nom,logo',
    //         //     'annonce.categorie:id,id2,name,slug',
    //         //     'annonce.region:id,name,slug',
    //         //     'annonce.ville:id,name',
    //         //     'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
    //         //     'etablissement:id,nom,logo',
    //         //     'user:id,first_name,last_name,avatar'
    //         // ])->orderBy('created_at', 'desc')->get();

    //         // // Publicites
    //         // $publicites = publicite::
    //         // where('date_fin', '>=', $currentDateTime)
    //         // ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->get();

    //         // $totalAnnoncesVideo = count($annoncesVideo);

    //         if($request->query('page')) $page = (int)$request->query('page');
    //         else $page = 1;


    //         // $annoncesVideo_Boosters_Publicites = [];
    //         // $annoncesVideo_Boosters_Publicites = 
    //         // global_function($annoncesVideo,
    //         //     $annoncesBooster, "booster_index", 
    //         //     $publicites, "publicite_index", 
    //         //     $page);


    //         // Pagination 
    //         $perPage = 2;
    //         $offset = ($page - 1) * $perPage;

    //         // Récupère toutes les annonces avec pagination
    //         $paginatedAnnonces = array_slice($annoncesVideoFiletrs->toArray(), $offset, $perPage);
    //         $total = count($annoncesVideoFiletrs);
    //         // $lastPage = (int) ceil($total / $perPage);

    //         // return response()->json(['status' => 200, 'data' => ["annoncesVideos"=>$annoncesVideoFiletrs]]);
    //         return response()->json(['status' => 200, 'data' => ["current_page"=>$page, "perPage"=>$perPage,"annoncesVideos"=>$paginatedAnnonces, "total"=>$total]]);

    //         return response()->json(['status' => 200, 'data'=>$annoncesVideo]);
    //     }catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function AnnonceDetails(Request $request){
    //     try{
    //         $annonceDetails = Annonce::where('slug', $request->slug)
    //         ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'model:id,name,slug',
    //         'marque:id,name,slug', 'carburant:id,name,slug', 'annee:id,name,slug', 'immatriculation:id,name,reference', 'fiscale:id,name,slug,nbre', 'candidats')
    //         ->first();

    //         if(!$annonceDetails || !$request->slug){
    //             return response()->json(['status' => 404, 'data'=>"Annonce not found"]);
    //         }
    //         $annonceDetails = collect($annonceDetails)->except(['annonce_parent', 'type_transaction', 'n_image', 'n_images', 'images_partage', 
    //         'certifie', 'recommande', 'son_order',
    //         'standard', 'prem', 'url_vedio', 'type', 'categorie_id', 
    //         'en_chorome', 'show_tele', 'partage', 'garantie', 'show_in_nade', 'param_int_1', 'param_int_2', 
    //         'param_int_3', 'param_int_4', 'param_int_5', 'param_int_6', 'param_int_7', 'param_var_1', 'param_var_2',
    //         'param_var_3', 'reel', 'vedio', 'img_reel', 'img_vedio', 'created_at', 'updated_at', 'deleted_at']);

    //         return response()->json(['status' => 200, 'data'=>$annonceDetails]);
    //     }catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }


    // public function AnnonceEmplois(Request $request){
    //     $selectedSlug = $request->query('slug');
    //     try{
    //         // Current datetime
    //         $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');

    //         $annoncesBoosterIds = Annonces_booster::where('date_fin', '>=', $currentDateTime)->pluck('annonce_id');
    //         // return $annoncesBoosterIds;
    //         $annoncesEmploi = Annonce::select('id', 'description', 'prix', 'images', 'video_1', 'img_video_1', 'nbre_favoris', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'date_order')
    //         ->where('show_in_nade', 1)
    //         ->whereNotIn('id', $annoncesBoosterIds)
    //         ->whereHas('categorie', function ($query) {
    //             $query->where('id2', 200);
    //         })
    //         ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name', 'candidats')
                     
    //         ;

    //         // en cas de slug exists
    //         if($selectedSlug){
    //             // $annonceWithSlug = Annonce::where('slug', $selectedSlug)->first();
    //             // if($annonceWithSlug)
    //             // $annoncesEmploi->where('date_order', '<=', $annonceWithSlug->date_order)->orderBy('date_order', 'desc');
    //             $annoncesEmploi->orderByRaw("CASE WHEN slug = ? THEN 1 ELSE 2 END", [$selectedSlug])  ;
    //         }

    //         $annoncesEmploi = $annoncesEmploi->orderBy('date_order', 'desc')->get();

    //         // Annonces Booster
    //         $annoncesBooster = Annonces_booster::
    //         where('date_fin', '>=', $currentDateTime)
    //         ->with([
    //             'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //             'annonce.user:id,first_name,last_name,avatar',
    //             'annonce.etablissement:id,nom,logo',
    //             'annonce.categorie:id,id2,name,slug',
    //             'annonce.region:id,name,slug',
    //             'annonce.ville:id,name',
    //             'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
    //             'etablissement:id,nom,logo',
    //             'user:id,first_name,last_name,avatar'
    //         ])->orderBy('created_at', 'desc')->get();

    //         // Publicites
    //         $publicites = publicite::
    //         where('date_fin', '>=', $currentDateTime)
    //         ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->get();

    //         // test
    //         $pubImage = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'image')
    //         ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
    //         $pubVideo = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'video')
    //         ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
    //         $pubCarosel = publicite::where('date_fin', '>=', $currentDateTime)->where('format_pub', '=', 'carosel')
    //         ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->first();
    //         $annoncesBooster1 = Annonces_booster::
    //         where('date_fin', '>=', $currentDateTime)
    //         ->with([
    //             'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //             'annonce.user:id,first_name,last_name,avatar',
    //             'annonce.etablissement:id,nom,logo',
    //             'annonce.categorie:id,id2,name,slug',
    //             'annonce.region:id,name,slug',
    //             'annonce.ville:id,name',
    //             'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
    //             'etablissement:id,nom,logo',
    //             'user:id,first_name,last_name,avatar'
    //         ])->orderBy('created_at', 'desc')->first();

    //         // return [$pubImage, $pubVideo, $pubCarosel];
    //         // return $annoncesEmploi;
    //         $page = (int)$request->query('page');
    //         $annoncesEmploi_Boosters_Publicites = [];

    //         $annoncesEmploi_Boosters_Publicites = global_function($annoncesEmploi,
    //                                                 $annoncesBooster, "booster_index", 
    //                                                 $publicites, "publicite_index", 
    //                                                 $page);

    //         // Pagination 
    //         $perPage = 24;
    //         $offset = ($page - 1) * $perPage;

    //         // Récupère toutes les annonces avec pagination
    //         $paginatedAnnoncesEmploi = array_slice($annoncesEmploi_Boosters_Publicites, $offset, $perPage);
    //         $totalAnnoncesEmploi = count($annoncesEmploi_Boosters_Publicites);
    //         $lastPage = ceil($totalAnnoncesEmploi / 24);

    //         return response()->json(['status' => 200, 'data'=>["current_paage"=>$page,"last_page"=>$lastPage , "emplois"=>$paginatedAnnoncesEmploi, "total"=>$totalAnnoncesEmploi]]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }
    
    // // caracteristiques of voiture
    // public function getMarques(){
    //     try{
    //         $marques = Marque::all();
    //         return response()->json(['status' => 201, 'data'=>$marques]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getModels($id){
    //     try{
    //         $models = Modeles::where('marque_id', $id)->get();
    //         return response()->json(['status' => 201, 'data'=>$models]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getAnnees(){
    //     try{
    //         $annees = Annee::all();
    //         return response()->json(['status' => 201, 'data'=>$annees]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getImmatriculations(){
    //     try{
    //         $immatriculations = Immatriculation::all();
    //         return response()->json(['status' => 201, 'data'=>$immatriculations]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getCarburants(){
    //     try{
    //         $carburants = Carburant::all();
    //         return response()->json(['status' => 201, 'data'=>$carburants]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getFiscales(){
    //     try{
    //         $fiscales = Fiscale::all();
    //         return response()->json(['status' => 201, 'data'=>$fiscales]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getCaractiristiques(){
    //     try{
    //         $caractiristiques = Caracteristiques::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$caractiristiques]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getSlugBoutiques(){
    //     try{
    //         $slugs = Etablissement::select('slug')->get();
    //         return response()->json(['status' => 201, 'data'=>$slugs]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getEmploiActivites(){
    //     try{
    //         $emplois_activites = EmploiActivite::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$emplois_activites]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getEmploiContrats(){
    //     try{
    //         $emplois_contrats = EmploiContrat::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$emplois_contrats]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getEmploiEtudes(){
    //     try{
    //         $emplois_etudes = EmploiEtude::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$emplois_etudes]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getEmploiExperiences(){
    //     try{
    //         $emplois_experiences = EmploiExperience::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$emplois_experiences]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getEmploiFonctions(){
    //     try{
    //         $emplois_fonctions = EmploiFonction::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$emplois_fonctions]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getEmploiSalaires(){
    //     try{
    //         $emplois_salaires = EmploiSalaire::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$emplois_salaires]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getEmploiTravails(){
    //     try{
    //         $emplois_travails = EmploiTravail::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$emplois_travails]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getImmoCaractiristique(){
    //     try{
    //         $immo_caractiristiques = ImmoCaractiristique::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$immo_caractiristiques]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getDomainFormation(){
    //     try{
    //         $domain_formations = DomainFormation::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$domain_formations]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getImpressions(Request $request){
    //     $champ = $request->champ;
    //     $id = $request->id;
    //     try{
    //         if($champ && $id){
    //             $impressions = Annonce::where($champ, $id)->sum('nbre_vue');
    //             return response()->json(['status' => 201, 'data'=>$impressions]);
    //         }
            
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // caracteristiques of tele
    // public function getTeleMarques(){
    //     try{
    //         $marques = teleMarque::all();
    //         return response()->json(['status' => 201, 'data'=>$marques]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getTeleModels($id){
    //     try{
    //         $models = teleModel::where('marque_id', $id)->get();
    //         return response()->json(['status' => 201, 'data'=>$models]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // vetement caracteristiques
    // public function getTypeVetements(){
    //     try{
    //         $vetementsType = TypeVetement::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$vetementsType]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getTailleVetements(){
    //     try{
    //         $vetementsTaille = vetementsTaille::where('status', 1)->get();
    //         return response()->json(['status' => 201, 'data'=>$vetementsTaille]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // annonces and products 
    // public function getAnnoncesProducts(){
    //     $boosterIds = Annonces_booster::where('date_fin', '>=', now())
    //     ->orderBy('created_at', 'desc')
    //     ->pluck('annonce_id')
    //     ->toArray(); // Convert the collection to an array

    //     try{
    //         $annonces_products = Annonce::where('show_in_nade', 1)
    //         ->with([
    //             'region:id,slug',
    //             'categorie:id,id2,slug,name',
    //             'annonces_booster'
    //         ])
    //         ->whereHas('categorie', function ($query) {
    //             $query->where('id2', '!=', 200);
    //         })
    //         ->whereNotIn('id', $boosterIds) // Exclude Annonces with active boosters
    //         ->select('id', 'titre', 'slug', 'description', 'image', 'prix', 'region_id', 'cat_g_id')
    //         ->orderBy('date_order', 'desc')
    //         ->limit(50)
    //         ->get();


    //         return response()->json(['status' => 201, 'data'=>$annonces_products]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // annonces and products 
    // public function getAnnoncesProductsBooster(){
    //     try{
    //         $annonces_products_booster = Annonces_booster::where('date_fin', '>=', now())
    //         ->whereHas('annonce', function($query) {
    //             $query->where('show_in_nade', 1);
    //         })
    //         ->with('annonce', 'annonce.region:id,slug', 'annonce.categorie:id,slug,name')
    //         ->orderBy('created_at', 'desc')
    //         ->get();
    //         return response()->json(['status' => 201, 'data'=>$annonces_products_booster]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // Test Ip adresse
    // public function getIpAdresse(Request $request){

    //     // First method
    //     // composer require stevebauman/location
    //     // use Stevebauman\Location\Facades\Location;

    //     $ip = $request->ip(); // Get the user's IP
    //     // $ip = $request->header('X-Forwarded-For') ?? $request->ip();
    //     $location = Location::get($ip);
    
    //     if ($location) {
    //         return [
    //             'ip' => $ip,
    //             'city' => $location->cityName,
    //             'country' => $location->countryName,
    //             'region' => $location->regionName,
    //         ];
    //     } else {
    //         return 'Unable to determine location.';
    //     }

    //     // Second method

    //     // $ip = $request->ip();
    //     // $ip = request()->header('X-Forwarded-For') ?? request()->ip();

    //     // $response = Http::get("http://ipinfo.io/{$ip}/json");

    //     // if ($response->successful()) {
    //     //     $data = $response->json();
    //     //     return [
    //     //         'ip' => $ip,
    //     //         'city' => $data['city'] ?? 'Unknown',
    //     //         'region' => $data['region'] ?? 'Unknown',
    //     //         'country' => $data['country'] ?? 'Unknown',
    //     //     ];
    //     // }

    //     // return 'Unable to determine location.';

    //     //     $latitude = $request->input('latitude');
    //     // $longitude = $request->input('longitude');

    //     // // Use a geocoding service like OpenStreetMap (Nominatim), Google Geocoding API, or Geocodio
    //     // $response = Http::get("https://nominatim.openstreetmap.org/reverse", [
    //     //     'lat' => $latitude,
    //     //     'lon' => $longitude,
    //     //     'format' => 'json'
    //     // ]);

    //     // return $response;
    //     // if ($response->successful()) {
    //     //     $data = $response->json();
    //     //     return response()->json([
    //     //         'city' => $data['address']['city'] ?? null,
    //     //         'region' => $data['address']['state'] ?? null,
    //     //         'country' => $data['address']['country'] ?? null
    //     //     ]);
    //     // }

    //     // return response()->json(['error' => 'Unable to determine location.'], 500);
    // }

    // // urls
    // public function getAnnoncesRegionsUrls() {
    //     try {
    //         // Fetch unique combinations of ville_id, region_id, and cat_g_id with their count
    //         $combinations = Annonce::selectRaw('ville_id, region_id, cat_g_id, COUNT(*) as total')
    //             ->where('show_in_nade', 1)
    //             ->distinct()  // Ensure uniqueness of combinations
    //             ->groupBy('region_id', 'ville_id', 'cat_g_id')
    //             ->with(['ville:id,slug', 'region:id,slug', 'categorie:id,slug']) // Load related data
    //             ->get();
    
    //         return response()->json([
    //             'status' => 201,
    //             'data' => $combinations
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getAnnoncesVillesUrls() {
    //     try {
    //         // Fetch unique combinations of ville_id, region_id, and cat_g_id with their count
    //         $combinations = Annonce::selectRaw('ville_id, cat_g_id, COUNT(*) as total')
    //             ->where('show_in_nade', 1)
    //             ->distinct()  // Ensure uniqueness of combinations
    //             ->groupBy('ville_id', 'cat_g_id')
    //             ->with(['ville:id,slug', 'categorie:id,slug']) // Load related data
    //             ->get();
    
    //         return response()->json([
    //             'status' => 201,
    //             'data' => $combinations
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getAnnoncesRegionsOnlysUrls() {
    //     try {
    //         // Fetch unique combinations of ville_id, region_id, and cat_g_id with their count
    //         $combinations = Annonce::selectRaw('ville_id, region_id, COUNT(*) as total')
    //         ->where('show_in_nade', 1)
    //         ->whereHas('region', function ($query) {
    //             $query->where('slug', '!=', 'toute-la-ville');
    //         })
    //         ->groupBy('ville_id', 'region_id')
    //         ->with(['region:id,slug', 'ville:id,slug'])
    //         ->get();
    
    //         return response()->json([
    //             'status' => 201,
    //             'data' => $combinations
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getTopTenMarketplaceSearch(Request $request) {
    //     $categorie = $request->categorie;
    //     $villes = $request->villes;
    //     $regions = $request->regions;
    //     try {
    //         $searchTerms = NadeSearch::get();
    
    //         return response()->json(['status' => 201,'data' => $searchTerms]);
    //     } catch (\Exception $e) {
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }
}
