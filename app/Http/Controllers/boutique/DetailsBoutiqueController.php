<?php

namespace App\Http\Controllers\boutique;

use App\Http\Controllers\Controller;
use App\Models\Etablissement; 
use App\Models\User; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Categorie_google; 
use Illuminate\Validation\ValidationException;
use App\Models\publicite; 
use App\Models\Annonces_booster; 
use Carbon\Carbon;
use App\Models\Annonce;
use App\Models\Ville;
use App\Models\Region;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DetailsBoutiqueController extends Controller
{

    private static function generateUsername($name) {
        $slug = Str::slug($name, '_');  // Generate slug from names

        // Ensure the slug is unique by checking the database
        $slugBase = $slug;
        $count = 1;

        while (User::where('slug', $slug)->exists() || Etablissement::where('slug', $slug)->exists()) {
            $slug = $slugBase . '_' . $count;  // Append a number to make it unique
            $count++;
        }

        return $slug;
    }


    public function store(Request $request) {
        // Valider les données de la requête avec des messages personnalisés
        $validatedData = $request->validate([
            'userId' => 'required',
            'nom' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'bio' => 'string|max:255',
            // 'ville_id' => 'required|integer',
            // 'region_id' => 'required|integer',
            // 'siteweb' => 'nullable|url',
            // 'email' => 'required|email',
            // 'numero_telephone' => 'required|string',
            // 'adresse' => 'nullable|string',
            // 'google_map_id' => 'nullable|string',
            // 'latitude' => 'required|numeric',
            // 'longitude' => 'required|numeric',
        ], [
            // Messages d'erreur personnalisés
        ]);
    
        try {
            // Générer le slug à partir du nom et de la référence
            $slug = $this->generateUsername($validatedData['nom']);
    
            // Vérifier si le slug existe déjà
            $existingEtablissement = Etablissement::where('slug', $slug)->first();
            if ($existingEtablissement) {
                // Le slug existe déjà, renvoyer une erreur
                return response()->json([
                    'status' => 400,
                    'error' => 'Le slug "' . $slug . '" existe déjà.',
                ], 400);
            }
    
            // Créer un nouvel établissement avec les données validées et le slug généré
            $etablissement = Etablissement::create(array_merge($validatedData, ['slug' => $slug]));
    
            // Gérer le téléchargement du logo si présent
            if ($request->hasFile('logo')) {
                $logoName = time() . '-' . $request->file('logo')->getClientOriginalName();
                $logoDirectory = 'entreprise/logo/' . date('Y') . '/' . $etablissement->id;
    
                // Créer le répertoire et stocker le nouveau logo
                Storage::disk('ftp')->makeDirectory($logoDirectory);
                $logoPath = $request->file('logo')->storeAs($logoDirectory, $logoName, 'ftp');
                $etablissement->logo = $logoPath;
                $etablissement->save(); // Enregistrer le chemin du logo
            }
    
            // Enregistrer le tableau des catégories
            // if ($request->has('categories')) {
            //     $etablissement->categories = $request->categories;
            //     $etablissement->save(); // Enregistrer les catégories
            // }
    
            // Attacher un utilisateur à l'établissement
            $etablissement->users()->attach($request->userId);
    
            // Retourner une réponse avec le chemin du logo inclus
            return response()->json([
                'message' => 'Etablissement créé avec succès!',
                'etablissement' =>  $etablissement,
            ], 200);
        } catch (ValidationException $e) {
            // Gérer les erreurs de validation
            return response()->json([
                'status' => 400,
                'error' => $e->validator->errors(),
            ], 400);
        } catch (\Exception $e) {
            // Gérer toute autre erreur
            return response()->json([
                'status' => 500,
                'error' => 'Une erreur est survenue: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    private function generateSlug($name) {
        $slug = Str::slug($name, '_');  // Generate slug from names

        // Ensure the slug is unique by checking the database
        $slugBase = $slug;
        $count = 1;

        while (User::where('slug', $slug)->exists() || Etablissement::where('slug', $slug)->exists()) {
            $slug = $slugBase . '_' . $count;  // Append a number to make it unique
            $count++;
        }

        return $slug;
    }
    
    private function ensureUniqueSlug($slug) {
        // Boucle pour vérifier si le slug existe, ajouter un compteur si nécessaire
        $originalSlug = $slug;
        $counter = 1;
    
        while (Etablissement::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }
    
        return $slug;
    }
    // public function getSlugBoutiques() {
    //     try {
    //         $slugs = Etablissement::select('slug')->get();
    //         return response()->json(['status' => 201, 'data' => $slugs]);
    //     } catch (\Exception $e) {
    //         // Gérer les erreurs qui surviennent durant la requête
    //         return response()->json(['status' => 500, 'error' => 'Une erreur est survenue: ' . $e->getMessage()]);
    //     }
    // }

    // public function getAllBoutiques(Request $request) {

    //     // Obtenir les paramètres de la requête
    //     // $city = $request->query('city');
    //     // $villes = $request->query('villes');
    //     // $regions = $request->query('regions');
    //     // $categorie = $request->query('categorie');

    //     // if ($villes) {
    //     //     $villes = json_decode($villes, true);
    //     // }
    //     // if ($regions) {
    //     //     $regions = json_decode($regions, true);
    //     // }
       
    //     // try {
    //         $initialQuery = Etablissement::where('status', 1)->with('users')->orderBy('created_at', 'desc');
    //         $i = 0;
    //         $filters = ['', 'region',  'city', ]; // List of filter conditions to try

    //         do {
    //             $query = clone $initialQuery;

    //             $queryFilter = RechercheAnnuaireFilters($query, $request, $filters[$i]);
    //             // return $queryFilter;

    //             $boutiques = $queryFilter[0];  // Update $annonces with the new filter result
    //             $count = count($boutiques);    // Recheck count after applying the filter
    //             $i++;
    //             // If no results, reset the next filter
    //         } while ($count == 0 && $i < count($filters));

    //         $boutiques = $queryFilter[0];
    //         $resetFilter = $queryFilter[1];
    //         $ttestt = $queryFilter[2];

    //         // Current datetime
    //         $currentDateTime = Carbon::now()->addHour()->format('Y-m-d H:i:s');

    //         // Annonces Booster
    //         $annoncesBooster = Annonces_booster::
    //         where('date_fin', '>=', $currentDateTime)
    //         ->with([
    //             'annonce:id,description,prix,images,video_1,img_video_1,nbre_favoris,nbre_vue,etab_id,user_id,reference,tele,date_order,titre,slug,cat_g_id,region_id,ville_id,infos',
    //             'annonce.user:id,first_name,last_name,avatar,is_verified',
    //             'annonce.etablissement:id,nom,logo,is_verified',
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
    //         ->with('etablissement:id,nom,logo,is_verified')->orderBy('created_at', 'desc')->get();
    //         // return $publicites;
    //         //jobs
    //         $annoncesEmploi = Annonce::select('id', 'description', 'prix', 'images', 'video_1', 'img_video_1', 'nbre_favoris', 'nbre_vue', 'vue_detail', 'etab_id', 'user_id', 'tele', 'date_order', 'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id', 'infos', 'date_order')
    //         ->where('show_in_nade', 1)
    //         ->whereHas('categorie', function ($query) {
    //             $query->where('id2', 200);
    //         })
    //         ->with('user:id,first_name,last_name,avatar,is_verified', 'etablissement:id,nom,logo,is_verified', 'categorie:id,id2,name,slug', 'region:id,name,slug', 'ville:id,name,slug', 'candidats')
    //         ->orderBy('date_order', 'desc')
    //         ->get();

    //         $page = (int)$request->query('page');
            
    //         $boutiques_Boosters_Publicites = 
    //         global_function($boutiques,
    //             $annoncesBooster, "booster_index", 
    //             $publicites, "publicite_index", 
    //             $page);
            
    //         $RandomJobs = Cache::get('random_jobs' , 15);
    //         if($page == 1){
    //             // $RandomJobs = rand(55, 59);
    //             $RandomJobs = 15;
    //         }
    //         $jobsCount = count($annoncesEmploi);
    //         $annonceCount = 0;

    //         $boutiques_Boosters_Publicites_emplois = [];

    //         foreach ($boutiques_Boosters_Publicites as $annonce) {
                
    //             $boutiques_Boosters_Publicites_emplois[] = $annonce;
    //             $annonceCount++;

    //             // Ajouter jobs après chaque nombre random annonces
    //             if ($annonceCount % $RandomJobs == 0 && $jobsCount > 0) {
    //                 // Ajouter jobs courante
    //                 $selectedJobs = [$annoncesEmploi[rand(0, $jobsCount-1)], $annoncesEmploi[rand(0, $jobsCount-1)], $annoncesEmploi[rand(0, $jobsCount-1)]];
    //                 $boutiques_Boosters_Publicites_emplois[] = $selectedJobs;
    //             }
    //         }

            
    //         // Pagination 
    //         $perPage = 6;
    //         $offset = ($page - 1) * $perPage;

    //         // Récupérer toutes les boutiques avec pagination
    //         $paginatedBoutiques = array_slice($boutiques_Boosters_Publicites_emplois, $offset, $perPage);
    //         $totalBoutiques = count($boutiques_Boosters_Publicites_emplois);
    //         $countBoutiques = count($boutiques);

    //         return response()->json(['status' => 201, 'data' => ["current_page" => $page, "boutiques" => $paginatedBoutiques, "total" => $totalBoutiques, "resetFilter"=>$resetFilter, "totalBoutiques" => $countBoutiques], "teestt"=>$ttestt]);
    //     // } catch (\Exception $e) {
    //     //     // Gérer les erreurs qui surviennent durant la requête
    //     //     return response()->json(['status' => 500, 'error' => 'Une erreur est survenue: ' . $e->getMessage()]);
    //     // }
    // }

    // Etablissement
    // public function getBoutiques() {
    //     try {
    //         $boutiques = Etablissement::select('id', 'nom', 'slug', 'logo')->get();
    //         return response()->json(['status' => 200, 'data' => $boutiques]);
    //     } catch (\Exception $e) {
    //         // Gérer les erreurs qui surviennent durant la requête
    //         return response()->json(['status' => 500, 'error' => 'Une erreur est survenue: ' . $e->getMessage()]);
    //     }
    // }

    // public function getFilterUrls() {
    //     // $etablissements = Etablissement::where('status', 1)->get();

    //     $combinations = Etablissement::select('ville_id', 'region_id', 'categories')
    //     ->where('status', 1)
    //     ->wherenot('ville_id', null)
    //     ->wherenot('region_id', null)
    //     ->wherenot('categories', null)
    //     ->distinct()
    //     ->with('ville:id,slug', 'region:id,slug')
    //     ->get();


    //     return response()->json($combinations);
    // }
}