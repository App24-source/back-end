<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ville;
use App\Models\Categorie;
use App\Models\Annonce;
use App\Models\Region;
use App\Models\Categorie_google;
use App\Models\publicite;
use App\Models\Annonces_booster;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Services\BuilderService;
use App\Models\NadeSearch;


class FilterController extends Controller
{
    // public function getVilles(){
    //     $villes = Ville::where('status', 1)->select('id', 'name', 'slug')->with('regions:id,ville_id,slug', 'regions.annonces:id,slug,region_id')->get();
    //     return response()->json(['status' => 200, 'data'=>$villes]);
    // }

    // public function getCategories(){
    //     $categories = Categorie::where('status', 1)
    //     ->select('id', 'id2', 'name', 'slug', 'meta_title', 'meta_description', 'h1', 'h2', 'h3')
    //     ->with('annonces:id,slug,cat_g_id')
    //     ->get();
    //     return response()->json(['status' => 200, 'data'=>$categories]);
    // }

    // public function getRegions(Request $request){
    //     $id = $request->query('id');
    //     $regions = Region::where('status', 1)
    //     ->where('ville_id', $id)
    //     ->select('id', 'ville_id', 'name', 'slug')
    //     ->get();
    //     return response()->json(['status' => 200, 'data'=>$regions]);
    // }

    // public function getVille($ville){
    //     $villeExist = Ville::where('status', 1)->select('id', 'name', 'slug')->where('slug', $ville)->first();
    //     if($villeExist) return response()->json(['ok'=> true, 'status' => 201, 'data'=>$villeExist]);
    //     else return response()->json(['ok'=> false, 'status' => 404, 'data'=>"ville not found"]);
    // }

    // public function getCategorie($categorie){
    //     $categorieExist = Categorie::where('status', 1)
    //     ->select('id', 'id2', 'name', 'slug')
    //     ->where('slug', $categorie)
    //     ->first();
    //     if($categorieExist) 
    //         return response()->json(['ok'=> true, 'status' => 201, 'data'=>$categorieExist]);
    //     else return response()->json(['ok'=> false, 'status' => 404, 'data'=>"categorie not found"]);
    // }

    // public function getRegion($ville, $region){
    //     $villeExist = Ville::where('slug', $ville)->first();
    //     if($villeExist)
    //         $regionExist = Region::where('status', 1)
    //         ->where('ville_id', $villeExist->id)
    //         ->where('slug', $region)
    //         ->select('id', 'ville_id', 'name', 'slug')
    //         ->first();
    //     else return response()->json(['ok'=> false, 'status' => 404, 'data'=>"region not found"]);
    //     if($regionExist && $villeExist) return response()->json(['ok'=> true, 'status' => 201, 'data'=>$regionExist]);
    //     else return response()->json(['ok'=> false, 'status' => 404, 'data'=>"region not found"]);
    // }

    // public function AnnonceByCategoryMobile(Request $request, $id) {
    //     $query = Annonce::select('id', 'description', 'images', 'video_1', 'img_video_1', 
    //     'nbre_favoris', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'cat_g_id', 
    //     'region_id', 'ville_id', 'infos')
    //     ->where('show_in_nade', 1)
    //     ->whereHas('categorie', function ($query) use ($id) {$query->where('id', $id);})
    //     ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug',
    //     'region:id,name,slug', 'ville:id,slug,name', 'annonces_booster')
    //     ->orderBy('date_order','desc');

    //     $queryFilter = filter($request, $query, "");
    //     // $queryTest = filter($request, $query);
    //     // $query = $queryTest[0];
    //     // return $queryTest[1];

    //     $annonces = $queryFilter[0];

    //     // Current datetime
    //     $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');

    //     // Annonces Booster
    //     $annoncesBooster = Annonces_booster::
    //     where('date_fin', '>=', $currentDateTime)
    //     ->with([
    //         'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //         'annonce.user:id,first_name,last_name,avatar',
    //         'annonce.etablissement:id,nom,logo',
    //         'annonce.categorie:id,id2,name,slug',
    //         'annonce.region:id,name,slug',
    //         'annonce.ville:id,name',
    //         'type_booste:id,id2,name,nbre_jours,prix,prix_apres_vent',
    //         'etablissement:id,nom,logo',
    //         'user:id,first_name,last_name,avatar'
    //     ])->orderBy('created_at', 'desc')->get();

    //     // Publicites
    //     $publicites = publicite::
    //     where('date_fin', '>=', $currentDateTime)
    //     ->with('etablissement:id,nom,logo')->orderBy('created_at', 'desc')->get();

    //     $page = (int)$request->query('page');
    //     $annonces_Boosters_Publicites = [];

    //     $annonces_Boosters_Publicites = 
    //     global_function($annonces,
    //         $annoncesBooster, "booster_index", 
    //         $publicites, "publicite_index", 
    //         $page);
        
    //     // Pagination 
    //     $perPage = 6;
    //     $offset = ($page - 1) * $perPage;

    //     // Récupère toutes les annonces avec pagination
    //     $paginatedAnnonces = array_slice($annonces_Boosters_Publicites, $offset, $perPage);
    //     $totalAnnonces = count($annonces_Boosters_Publicites);

    //     // return response()->json(['status' => 200, 'data'=>$annonces]);
    //     return response()->json(['status' => 200, 'data' => ["current_page"=>$page, "perPage"=>$perPage,"annonces"=>$paginatedAnnonces, "total"=>$totalAnnonces]]);
    // }

    // public function AnnonceByCategoryDesktop(Request $request, $id){
    //     $previewMode = $request->query('previewMode');

    //     if($previewMode && $previewMode == "video") {
    //         $query = Annonce::where('show_in_nade', 1)->wherenot('video_1', null)
    //         ->whereHas('categorie', function ($query) use ($id) {$query->where('id', $id);})
    //         ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name', 'model:id,name,slug',
    //             'marque:id,name,slug', 'carburant:id,name,slug', 'annee:id,name,slug', 'immatriculation:id,name,reference', 'fiscale:id,name,slug,nbre')
    //         ->orderBy('date_order','desc');
    //     } else {
    //         $query = Annonce::where('show_in_nade', 1)
    //         ->whereHas('categorie', function ($query) use ($id) {$query->where('id', $id);})
    //         ->with('user:id,first_name,last_name,avatar', 'etablissement:id,nom,logo', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name', 'model:id,name,slug',
    //             'marque:id,name,slug', 'carburant:id,name,slug', 'annee:id,name,slug', 'immatriculation:id,name,reference', 'fiscale:id,name,slug,nbre')
    //         ->orderBy('date_order','desc');
    //     }
       

    //     // $query = filter($request, $query);
    //     $queryFilter = filter($request, $query, "");

    //     $annonces = $queryFilter[0];

    //     $annonces = collect($annonces)->except(['annonce_parent', 'type_transaction', 'n_image', 'n_images', 'images_partage', 
    //     'certifie', 'recommande', 'son_order', 'nbre_vue', 'nbre_click_sur_tele', 'nbre_click_sur_message',
    //     'nbre_vue_video', 'nbre_click_sur_what', 'standard', 'prem', 'url_vedio', 'type', 'categorie_id', 
    //     'en_chorome', 'show_tele', 'partage', 'garantie', 'show_in_nade', 'param_int_1', 'param_int_2', 
    //     'param_int_3', 'param_int_4', 'param_int_5', 'param_int_6', 'param_int_7', 'param_var_1', 'param_var_2',
    //     'param_var_3', 'reel', 'vedio', 'img_reel', 'img_vedio', 'created_at', 'updated_at', 'deleted_at']);
        
    //     return response()->json(['status' => 200, 'data'=>$annonces]);
    // }

    // public function getLocations(){
    //     try{
    //         $locations = Region::with('ville')->get();;
    //         return response()->json(['status' => 200, 'data' => $locations]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getCategories_Google(){
    //     try{
    //         $categories_google = Categorie_google::all();
    //         return response()->json(['status' => 200, 'data' => $categories_google]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // public function getCategorie_Google($categorie){
    //     try{
    //         $categorie_google = Categorie_google::select('id','slug','name')->where('slug', $categorie)->first();
    //         if($categorie_google)
    //             return response()->json(['ok'=> true, 'status' => 201, 'data' => $categorie_google]);
    //         else return response()->json(['ok'=> false, 'status' => 404, 'data' => "categorie not found"]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // search keyword
    // public function getSearchKeyword(){
    //     try{
    //         $search_keyword = NadeSearch::where('status', 1)->with('ville:id,slug', 'region:id,slug', 'categorie_generale:id,slug')->get();
    //         return response()->json(['status' => 200, 'data' => $search_keyword]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // slug region
    // public function getSlugRegion(){
    //     try{
    //         $slug_region = Annonce::
    //         select('slug', 'region_id')
    //         ->with('region:id,slug')                      
    //         ->where('show_in_nade', 1)         
    //         ->get();
    //         return response()->json(['status' => 200, 'data' => $slug_region]);
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }

    // // Just for testing
    // public function TestMethod(Request $request){
    //     try{
    //         $dataCreateUserTest = ['name'=> 'Mohamed', 'email'=> 'mohamed@gmail.com', 'password'=> 'mohamed123', 'locale'=> 'fr'];
    //         $dataCreateProjectTest = [
    //             'templateId' => null,
    //             'funnelTemplateId' => null,
    //             'userId' => 587994,
    //             'customerFirstName' => 'test',
    //             'customerLastName' => '2024',
    //             'customerEmail' => 'test2024@gmail.com',
    //             'customerSubdomain' => 'domaine2024',
    //             'websiteName' => 'website2024',
    //         ];
    //         $dataCreateWebsiteTest = [
    //             'projectId' => 659143,
    //             'templateId' => null,
    //             'funnelTemplateId' => null,
    //             'customerSubdomain' => 'domaine2024',
    //         ];
    //         $projectId = 659143;
    //         $dataOpenSessionTest = [
    //             'userEmail' => 'test2024@gmail.com',
    //             'projectId' => 659143,
    //             'websiteId' => 1335552,
    //             'path' => '/me/store/orders',
    //         ];
    //         $builderService = new BuilderService();
    //         // $createUserTest = $builderService->createUser($request, $dataCreateUserTest);
    //         // $createProjectTest = $builderService->createAccount($dataCreateProjectTest);
    //         // $createWebsiteTest = $builderService->createWebsite($dataCreateWebsiteTest);
    //         // $websitesByProject = $builderService->getProjectWebsites($projectId);
    //         // $openSession = $builderService->openSession($dataOpenSessionTest);
    //         // $listPlans = $builderService->listPlans();
    //         // $projectDetails = $builderService->projectDetails(660937);
    //         // $checkSubscriptionPlan = $builderService->checkSubscriptionPlan(1339998, 63557);
    //         // $checkSubscriptionPlan = $builderService->checkSubscriptionPlan(1339998, 63557);
    //         return response()->json( $checkSubscriptionPlan );
    //     } catch (\Exception $e) {
    //         // Handle any errors that occur during the query
    //         return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
    //     }
    // }
}
