<?php

namespace App\Http\Controllers\annonce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Annonce;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateController extends Controller
{
    // public function store(Request $request) {
    //     $imagePaths = [];
    //     // Get the current year
    //     $currentYear = date('Y');
    //     // Define the directory path dynamically
    //     $directory = 'publication/images/' . $currentYear;

    //     // Retrieve the maximum reference and handle the new reference generation
    //     $maxReference = Annonce::max('reference');
        
    //     // Convert the max reference to an integer and increment by 1
    //     if ($maxReference) {
    //         $newReference = str_pad((int)$maxReference + 1, 5, '0', STR_PAD_LEFT);
    //     } else {
    //         // If there's no previous reference, start from "00001"
    //         $newReference = "00001";
    //     }

    //     // Generate a unique slug by combining 'titre' and 'reference'
    //     $titre = $request->get('titre');
    //     $slug = $this->generateSlug($titre, $newReference);

    //     // Handle image uploads if present
    //     if ($request->hasFile('images')) {
    //         foreach ($request->file('images') as $index => $image) {
    //             if ($image->isValid()) {
    //                 // Check if the directory exists, if not, create it
    //                 if (!Storage::disk('ftp')->exists($directory)) {
    //                     try {
    //                         Storage::disk('ftp')->makeDirectory($directory);
    //                     } catch (\Exception $e) {
    //                         return response()->json(['success' => false, 'message' => 'Failed to create directory.'], 500);
    //                     }
    //                 }

    //                 // Create a new image name with slug and index (starting from 1)
    //                 $imageName = $slug . '-' . ($index + 1) . '.' . $image->getClientOriginalExtension();

    //                 // Store the image with the new name
    //                 $path = $image->storeAs($directory, $imageName, 'ftp');
    //                 $imagePaths[] = $path;
    //             } 
    //         }
    //     }
    //     $imagesVariantsResponse = [];
    //     $infos = $request->get('infos');
    //     // Check if 'imagesVariants' exists and is an array
    //     if ($request->has('imagesVariants') && is_array($request->file('imagesVariants'))) {
    //         $imagesVariants = $request->file('imagesVariants');

    //         foreach ($imagesVariants as $index => $file) {
    //             if ($file && $file->isValid()) {
    //                 // Retrieve the corresponding value, defaulting to null if not found
    //                 $value = $request->input("images_values.$index", null);

    //                 // Create a unique name for the variant image
    //                 $variantImageName = $slug . '-variant-' . ($index + 1) . '-' . ($value ?? 'default') . '.' . $file->getClientOriginalExtension();

    //                 // Store the file
    //                 $path = $file->storeAs($directory, $variantImageName, 'ftp');

    //                 // Collect paths for the response
    //                 $imagesVariantsResponse[] = [
    //                     'path' => $path,
    //                     'value' => $value,
    //                 ];
    //             }
    //         }
    //     } else {
    //         // Optionally handle the case where no imagesVariants were provided
    //         $imagesVariantsResponse = []; // Initialize as an empty array
    //     }
    //     $infosString = json_encode([
    //         'infos' => $infos,
    //         'imagesVariants' => $imagesVariantsResponse,
    //     ]);
    
    //     $firstImagePath = $imagePaths[0] ?? null;
    //     $imagesString = json_encode($imagePaths); // Convert image paths to a JSON string
       
    //     $annonce = new Annonce;
    //     $annonce->titre = $request->get('titre');
    //     $annonce->tele = $request->get('tele');
    //     $annonce->description = $request->get('description');
    //     if ($request->get('typePartage') === "annonce" || $request->get('typePartage') === "produit") {
    //         $annonce->kilometrage = $request->get('kilometrage');
    //         $annonce->prix = (int) str_replace(',', '', $request->get('prix'));
    //         $prixPromo = (int) str_replace(',', '', $request->get('prixPromo'));
            
    //         // Replace prixPromo with prix if it's 0
    //         $annonce->prixPromo = $prixPromo > 0 ? $prixPromo : $annonce->prix;
    //         $annonce->apport = (int) str_replace(',', '', $request->get('apport'));
    //         $annonce->montant = (int) str_replace(',', '', $request->get('montant'));
    //         $annonce->type_transaction = $request->get('transactionType');
        
    //         if ($request->get('prixRemise') !== null) {
    //             $annonce->prixRemise = (int) str_replace(',', '', $request->get('prixRemise'));
    //         }
            
    //         $annonce->prixCredit = (int) str_replace(',', '', $request->get('prixCredit'));
    //         $annonce->nbMois = $request->get('nombreMois');

    //         $annonce->marque_id = $request->get('marque_id');
    //         $annonce->model_id = $request->get('model_id');
    //         $annonce->annee_id = $request->get('annee_id');
    //         $annonce->immatriculation_id = $request->get('immatriculation_id');
    //         $annonce->fiscale_id = $request->get('fiscale_id');
    //         $annonce->carburant_id = $request->get('carburant_id');
    //         $annonce->etat = $request->get('etat');
    //         $annonce->nbre_port = $request->get('nbre_port');
    //         $annonce->premier_main = $request->get('premier_main');
    //         $annonce->transmission = $request->get('transmission');
    //         $annonce->dedounee = $request->get('dedounee');
    //         $annonce->caractiristique = $request->get('caracteristique');
    //     }
    //     $annonce->prix = $request->get('prix');
    //     $annonce->prixPromo = $request->get('prixPromo');
    //     $annonce->image = $firstImagePath;
    //     $annonce->images = $imagesString;
    //     // $annonce->infos = $request->get('infos');
    //     $annonce->infos = $infosString; // Save JSON string of infos
    //     $annonce->contact_method = $request->get('contact_method');
    //     $annonce->cat_g_id = $request->get('cat_g_id');
    //     $annonce->user_id = $request->get('user_id');
    //     $annonce->etab_id = $request->get('etab_id');
    //     $annonce->ville_id = $request->get('ville_id');
    //     $annonce->region_id = $request->get('region_id');
    //     $annonce->type_annonce = $request->get('typePartage');
    //     $annonce->show_in_nade = 2;
    //     $annonce->reference = $newReference; // Save the new reference
    //     $annonce->slug = $slug; // Save the generated slug
    //     $annonce->type_transaction = $request->get('transactionType');


    //     $annonce->param_int_1 = $request->get('param_int_1');
    //     $annonce->param_int_2 = $request->get('param_int_2');
    //     $annonce->param_int_3 = $request->get('param_int_3');
    //     $annonce->param_int_4 = $request->get('param_int_4');
    //     $annonce->param_int_5 = $request->get('param_int_5');
    //     $annonce->param_int_6 = $request->get('param_int_6');
    //     $annonce->param_int_7 = $request->get('param_int_7');

    //     $annonce->param_var_1 = $request->get('param_var_1');
    //     $annonce->param_var_2 = $request->get('param_var_2');
    //     $annonce->param_var_3 = $request->get('param_var_3');

    //     $annonce->save();

    //     $annonce = $annonce->load(['region', 'ville', 'user', 'categorie', 'etablissement']);

    //     // Return success response with the created annonce data
        
    //     return response()->json([
    //         'success' => true,
    //         'imagesVariantsResponse' => $imagesVariantsResponse,
    //         'infos' =>  $annonce->infos,
    //         'annonce' =>  $annonce

    //     ]);
    //     // return "true";
    // }


    // public function update(Request $request, $id) {
    //     // Retrieve the existing annonce by ID
    //     $annonce = Annonce::findOrFail($id);
    //     $imagePaths = [];
    //     // Get the current year
    //     $currentYear = date('Y');
    //     // Define the directory path dynamically
    //     $directory = 'publication/images/' . $currentYear;
    
    //     // Generate a unique slug by combining 'titre' and 'reference'
    //     $titre = $request->get('titre');
    //     $slug = $this->generateSlug($titre, $annonce->reference);
    //     // $oldImages = json_decode($annonce->images, true) ?? [];
    
    //     if ($request->hasFile('images')) {
    //         // Decode old images from JSON
    //         $oldImages = json_decode($annonce->images, true);
        
    //         // Vérifiez si oldImages est un tableau, sinon initialisez-le comme un tableau vide
    //         if (!is_array($oldImages)) {
    //             $oldImages = [];
    //         }
        
    //         // Remove duplicates from old images
    //         $oldImages = array_unique($oldImages);
        
    //         // Delete all old images
    //         foreach ($oldImages as $oldImagePath) {
    //             if (Storage::disk('ftp')->exists($oldImagePath)) {
    //                 if (!Storage::disk('ftp')->delete($oldImagePath)) {
    //                     // Log or handle the error
    //                     \Log::error("Failed to delete image: " . $oldImagePath);
    //                 } else {
    //                     \Log::info("Deleted image: " . $oldImagePath);
    //                 }
    //             } else {
    //                 \Log::warning("Image not found for deletion: " . $oldImagePath);
    //             }
    //         }
        
    //         // Clear the image paths array
    //         $imagePaths = [];  // Initialisez le tableau ici
    //         $currentYear = date('Y');
    //         $directory = 'publication/images/' . $currentYear;
        
    //         // Check if the directory exists, if not, create it
    //         if (!Storage::disk('ftp')->exists($directory)) {
    //             try {
    //                 Storage::disk('ftp')->makeDirectory($directory);
    //             } catch (\Exception $e) {
    //                 return response()->json(['success' => false, 'message' => 'Failed to create directory.'], 500);
    //             }
    //         }
        
    //         // Store new images
    //         foreach ($request->file('images') as $index => $image) {
    //             if ($image->isValid()) {
    //                 // Create a new image name with slug and index (starting from 1)
    //                 $imageName = $slug . '-edit-' . ($index + 1) . '.' . $image->getClientOriginalExtension();
    //                 // Store the image with the new name in the same directory
    //                 $path = $image->storeAs($directory, $imageName, 'ftp');
    //                 $imagePaths[] = $path; // Add the new image path to the array
    //             } 
    //         }
        
    //         // Update the annonce images field with new images
    //         $annonce->images = json_encode($imagePaths);
    //         $annonce->image = $imagePaths[0] ?? null; // Mise à jour du champ image
        
    //         $annonce->save(); // Save the changes to the annonce
    //     }
    //     // Handle image variants
    //     $imagesVariantsResponse = [];
    //     if ($request->has('imagesVariants') && is_array($request->file('imagesVariants'))) {
    //         $imagesVariants = $request->file('imagesVariants');
    
    //         foreach ($imagesVariants as $index => $file) {
    //             if ($file && $file->isValid()) {
    //                 // Retrieve the corresponding value, defaulting to null if not found
    //                 $value = $request->input("images_values.$index", null);
    
    //                 // Create a unique name for the variant image
    //                 $variantImageName = $slug . '-variant-' . ($index + 1) . '-' . ($value ?? 'default') . '.' . $file->getClientOriginalExtension();
    
    //                 // Store the file
    //                 $path = $file->storeAs($directory, $variantImageName, 'ftp');
    
    //                 // Collect paths for the response
    //                 $imagesVariantsResponse[] = [
    //                     'path' => $path,
    //                     'value' => $value,
    //                 ];
    //             }
    //         }
    //     }
    
    //     $infosString = json_encode([
    //         'infos' => $request->get('infos'),
    //         'imagesVariants' => $imagesVariantsResponse,
    //     ]);
        
    //     // Update the annonce fields
    //     if ($request->get('titre') !== null) {
    //         $annonce->titre = $request->get('titre');
    //     }
    //     $annonce->tele = $request->get('tele');
    //     $annonce->description = $request->get('description');
    //     $annonce->kilometrage = $request->get('kilometrage');
    //     if ($request->get('typePartage') === "annonce" || $request->get('typePartage') === "produit") {
    //         $annonce->kilometrage = $request->get('kilometrage');
    //         $annonce->prix = (int) str_replace(',', '', $request->get('prix'));
    //         $prixPromo = (int) str_replace(',', '', $request->get('prixPromo'));
            
    //         // Replace prixPromo with prix if it's 0
    //         $annonce->prixPromo = $prixPromo > 0 ? $prixPromo : $annonce->prix;
    //         $annonce->apport = (int) str_replace(',', '', $request->get('apport'));
    //         $annonce->montant = (int) str_replace(',', '', $request->get('montant'));
        
    //         if ($request->get('prixRemise') !== null) {
    //             $annonce->prixRemise = (int) str_replace(',', '', $request->get('prixRemise'));
    //         }
            
    //         $annonce->prixCredit = (int) str_replace(',', '', $request->get('prixCredit'));
    //         $annonce->nbMois = $request->get('nombreMois');
    //         $annonce->type_transaction = $request->get('transactionType');

    //         $annonce->marque_id = $request->get('marque_id');
    //         $annonce->model_id = $request->get('model_id');
    //         $annonce->annee_id = $request->get('annee_id');
    //         $annonce->immatriculation_id = $request->get('immatriculation_id');
    //         $annonce->fiscale_id = $request->get('fiscale_id');
    //         $annonce->carburant_id = $request->get('carburant_id');
    //         $annonce->etat = $request->get('etat');
    //         $annonce->nbre_port = $request->get('nbre_port');
    //         $annonce->premier_main = $request->get('premier_main');
    //         $annonce->transmission = $request->get('transmission');
    //         $annonce->dedounee = $request->get('dedounee');
    //         $annonce->caractiristique = $request->get('caracteristique');
    //     }
       
    //     if ($request->get('typePartage') === "annonce" || $request->get('typePartage') === "produit") {
    //         $annonce->prix = (int) str_replace(',', '', $request->get('prix'));
    //         $annonce->prixPromo = (int) str_replace(',', '', $request->get('prixPromo'));
    //         $annonce->apport = (int) str_replace(',', '', $request->get('apport'));
    //         $annonce->montant = (int) str_replace(',', '', $request->get('montant'));
        
    //         if ($request->get('prixRemise') !== null) {
    //             $annonce->prixRemise = (int) str_replace(',', '', $request->get('prixRemise'));
    //         }
            
    //         $annonce->prixCredit = (int) str_replace(',', '', $request->get('prixCredit'));
    //         $annonce->nbMois = $request->get('nombreMois');
    //     }
    //     $annonce->type_transaction = $request->get('transactionType');

    //     $annonce->prix = $request->get('prix');
    //     $annonce->prixPromo = $request->get('prixPromo');
    //     $annonce->marque_id = $request->get('marque_id');
    //     $annonce->model_id = $request->get('model_id');
    //     $annonce->annee_id = $request->get('annee_id');
    //     $annonce->immatriculation_id = $request->get('immatriculation_id');
    //     $annonce->fiscale_id = $request->get('fiscale_id');
    //     $annonce->carburant_id = $request->get('carburant_id');
    //     $annonce->etat = $request->get('etat');
    //     $annonce->nbre_port = $request->get('nbre_port');
    //     $annonce->premier_main = $request->get('premier_main');
    //     $annonce->transmission = $request->get('transmission');
    //     $annonce->dedounee = $request->get('dedounee');
    //     $annonce->caractiristique = $request->get('caracteristique');
    //     $annonce->show_in_nade = 2;
    //     $annonce->infos = $infosString; // Save JSON string of infos
    //     if ($request->has('contact_method')) {
    //         $annonce->contact_method = $request->get('contact_method');

    //     }
     
    //     if ($request->has('ville_id')) {
    //         $annonce->ville_id = $request->get('ville_id');
    //     }
        
    //     if ($request->has('region_id')) {
    //         $annonce->region_id = $request->get('region_id');
    //     }
        
    //     if ($request->has('typePartage')) {
    //         $annonce->type_annonce = $request->get('typePartage');
    //     }
        
    //     $annonce->slug = $slug; // Update the slug
    
    //     // Update other parameters
    //     $annonce->param_int_1 = $request->get('param_int_1');
    //     $annonce->param_int_2 = $request->get('param_int_2');
    //     $annonce->param_int_3 = $request->get('param_int_3');
    //     $annonce->param_int_4 = $request->get('param_int_4');
    //     $annonce->param_int_5 = $request->get('param_int_5');
    //     $annonce->param_int_6 = $request->get('param_int_6');
    //     $annonce->param_int_7 = $request->get('param_int_7');
    
    //     $annonce->param_var_1 = $request->get('param_var_1');
    //     $annonce->param_var_2 = $request->get('param_var_2');
    //     $annonce->param_var_3 = $request->get('param_var_3');
    
    //     $annonce->save();
    
    //     // Reload the annonce with relations
    //     $annonce = $annonce->load(['region', 'ville', 'user', 'categorie', 'etablissement']);
    
    //     // Return success response with the updated annonce data
    //     return response()->json([
    //         'success' => true,
    //         'show_in_nade' => $annonce->show_in_nade,
    //         'annonce' => $annonce,
    //     ]);
    // }


    private function generateSlug($titre, $reference) {

        // convert titre to slug forme
        $titre = Str::slug($titre);

        // // Convert to lowercase
        // $titre = Str::lower($titre);

        // // Replace accented characters with non-accented versions
        // $titre = Str::ascii($titre);

        // // Replace any single quotes with hyphens
        // $titre = str_replace("'", '-', $titre);

        // // Replace any non-alphanumeric characters (except spaces and hyphens) with an empty string
        // // Keep hyphens in the title now by adding '-' to the regex
        // $titre = preg_replace('/[^a-z0-9\s-]/', '', $titre);

        // // Replace multiple spaces with a single space
        // $titre = preg_replace('/\s+/', ' ', $titre);

        // // Replace spaces with hyphens to create a slug-friendly format
        // $titre = str_replace(' ', '-', $titre);

        // Combine the cleaned 'titre' and 'reference' to create the final slug
        $slug = $titre . '-' . $reference;

        // Ensure the slug is unique (in case it's already in use in the database)
        $slug = $this->ensureUniqueSlug($slug);

        return $slug;
    }


    private function ensureUniqueSlug($slug) {
        // Loop to check if the slug exists, append a counter if needed
        $originalSlug = $slug;
        $counter = 1;

        while (Annonce::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
