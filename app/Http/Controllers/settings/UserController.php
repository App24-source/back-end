<?php

namespace App\Http\Controllers\settings;

use App\Models\User;
use App\Models\Cv_User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // public function insert(Request $request, $id)
    // {
    //     $user = User::find($id);

    //     // Vérifier si l'utilisateur existe
    //     if (!$user) {
    //         return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
    //     }

    //     // Validation des données
    //     $validatedData = $request->validate([
    //         'first_name' => 'string|max:255|nullable',
    //         'last_name' => 'string|max:255|nullable',
    //         'email' => 'string|email|max:255|unique:users,email,' . $user->id,
    //         'tele' => 'string|max:255|nullable',
    //         'tele_verifie' => 'integer|nullable',
    //         'ville' => 'string|max:255|nullable',
    //         'ville_id' => 'integer|nullable',
    //         'region_id' => 'integer|nullable',
    //         'type' => 'string|max:255|nullable',
    //         'adresse' => 'string|nullable',
    //         'description' => 'string|max:500|nullable',
    //         'titre' => 'string|max:255|nullable',
    //         'password' => 'nullable|string|min:8|confirmed',
    //     ]);

    //     // Gestion des fichiers
    //     $currentYear = date('Y');
    //     $directory = 'UserImages/' . $currentYear;

    //     // Gérer l'upload de l'avatar
    //     if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
    //         $avatarName = time() . '-' . $request->file('avatar')->getClientOriginalName();

    //         // Créer le répertoire s'il n'existe pas déjà
    //         Storage::disk('ftp')->makeDirectory($directory, 0755, true, true);

    //         // Supprimer l'ancien avatar s'il existe
    //         if ($user->avatar) {
    //             Storage::disk('ftp')->delete($user->avatar);
    //         }

    //         // Essayer de stocker le nouvel avatar
    //         $avatarPath = $request->file('avatar')->storeAs($directory, $avatarName, 'ftp');
    //         $user->avatar = $avatarPath;
    //     }

    //     // Gérer l'upload de la bannière
    //     if ($request->hasFile('banner') ) {
    //         $file = $request->file('banner');
    
    //         // Informations sur le fichier
    //         $originalName = $file->getClientOriginalName();
    //         $mimeType = $file->getClientMimeType();
    //         $size = $file->getSize();
        
    //         // Répertoire de stockage
    //         $bannerDirectory = 'user_banners/' . $user->id;
        
    //         // Créer le répertoire s'il n'existe pas déjà
    //         if (!Storage::disk('ftp')->exists($bannerDirectory)) {
    //             Storage::disk('ftp')->makeDirectory($bannerDirectory);
    //         }
        
    //         // Stocker le fichier sur le disque FTP
    //         $bannerPath = $file->storeAs($bannerDirectory, time() . '-' . $originalName, 'ftp');
        
    //         // Mettre à jour le chemin dans l'utilisateur
    //         $user->banner = $bannerPath;
    //         $user->save();

    //         // // Créer le répertoire s'il n'existe pas déjà
    //         // Storage::disk('ftp')->makeDirectory($bannerDirectory, 0755, true, true);

    //         // // Supprimer la bannière précédente s'il existe
    //         // if ($user->banner && Storage::disk('ftp')->exists($user->banner)) {
    //         //     Storage::disk('ftp')->delete($user->banner);
    //         // }

    //         // // Stocker la nouvelle bannière
    //         // $bannerName = time() . '-' . $request->file('banner')->getClientOriginalName();
    //         // $bannerPath = $request->file('banner')->storeAs($bannerDirectory, $bannerName, 'ftp');
    //         return response()->json([
    //             'file_name' => $originalName,
    //             'mime_type' => $mimeType,
    //             'size' => $size,
    //             'stored_path' => $bannerPath,
    //             'user'=>$user,
    //         ]);
    //     }

    //     // Gérer l'upload du CV
    //     if ($request->hasFile('cv')) {
    //         $file = $request->file('cv');

    //         // Get file information
    //         $originalName = $file->getClientOriginalName();
    //         $mimeType = $file->getClientMimeType();
    //         $size = $file->getSize();

    //         // Store the file (in 'storage/app/public/uploads' for example)
    //         $path = $file->store('uploads', 'public');

    //         // Return a response with the file info or path
    //         return response()->json([
    //             'file_name' => $originalName,
    //             'mime_type' => $mimeType,
    //             'size' => $size,
    //             'stored_path' => $path,
    //         ]);
    //     } else {
    //         return response()->json(['error' => 'No file uploaded'], 400);
    //     }

    //     // Mise à jour de l'utilisateur
    //     $user->update($validatedData);
    //     $user->save();

    //     // Retourner une réponse
    //     return response()->json(['message' => 'Utilisateur mis à jour avec succès!', 'user' => $request], 200);
    // }

    
    // public function insert(Request $request, $id) {
    //     $user = User::find($id);
    
    //     // Vérifier si l'utilisateur existe
    //     if (!$user) {
    //         return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
    //     }
    
    //     // Validation des données
    //     $validatedData = $request->validate([
    //         'first_name' => 'string|max:255|nullable',
    //         'last_name' => 'string|max:255|nullable',
    //         'email' => 'string|email|max:255|unique:users,email,' . $user->id,
    //         'tele' => 'string|max:255|nullable',
    //         'tele_verifie' => 'integer|nullable',
    //         'ville' => 'string|max:255|nullable',
    //         'ville_id' => 'integer|nullable',
    //         'region_id' => 'integer|nullable',
    //         'type' => 'string|max:255|nullable',
    //         'adresse' => 'string|nullable',
    //         'description' => 'string|max:500|nullable',
    //         'titre' => 'string|max:255|nullable',
    //         'password' => 'nullable|string|min:8|confirmed',
    //     ]);
    
    //     // Gestion des fichiers
    //     $currentYear = date('Y');
    
    //     // Gérer l'upload de l'avatar
    //     if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
    //         $avatarName = time() . '-' . $request->file('avatar')->getClientOriginalName();
    //         $bannerDirectory = 'user/avatar/'. $currentYear. $user->id . '/';
    
    //         // Créer le répertoire s'il n'existe pas déjà
    //         if (!Storage::disk('ftp')->exists($avatarDirectory)) {
    //             Storage::disk('ftp')->makeDirectory($avatarDirectory);
    //         }
    
    //         // Supprimer l'ancien avatar s'il existe
    //         if ($user->avatar) {
    //             Storage::disk('ftp')->delete($user->avatar);
    //         }
    
    //         // Stocker le nouvel avatar
    //         $avatarPath = $request->file('avatar')->storeAs($avatarDirectory, $avatarName, 'ftp');
    //         $user->avatar = $avatarPath;
    //     }
    
    //     // Gérer l'upload de la bannière
    //     if ($request->hasFile('banner') && $request->file('banner')->isValid()) {
    //         $file = $request->file('banner');
    //         $bannerName = time() . '-' . $file->getClientOriginalName();
    //         $bannerDirectory = 'user/banner/'. $currentYear. $user->id . '/';
    
    //         // Créer le répertoire s'il n'existe pas déjà
    //         if (!Storage::disk('ftp')->exists($bannerDirectory)) {
    //             Storage::disk('ftp')->makeDirectory($bannerDirectory);
    //         }
    
    //         // Supprimer la bannière précédente s'il existe
    //         if ($user->banner) {
    //             Storage::disk('ftp')->delete($user->banner);
    //         }
    
    //         // Stocker la nouvelle bannière
    //         $bannerPath = $file->storeAs($bannerDirectory, $bannerName, 'ftp');
    //         $user->banner = $bannerPath;
    //     }
    
    //     // Gérer l'upload des CV
    //     // if ($request->hasFile('cv')) {
    //     //     // Récupérer les CV existants de l'utilisateur pour les supprimer
    //     //     $existingCvs = Cv_User::where('user_id', $user->id)->get();
    //     //     $files = $request->file('cv'); // Récupère les fichiers dans un tableau
    //     //     $selectedCvs = $request->input('selected'); // Récupère les valeurs de 'selected'

    //     //     $fileDetails = [];
    //     //     if (count($files) > 4 && count($selectedCvs) > 4) {
    //     //         return response()->json(['error' => 'Vous ne pouvez pas télécharger plus de 4 CV.'], 400);
    //     //     }
    //     //     // Supprimer les fichiers du stockage
    //     //     foreach ($existingCvs as $cv) {
    //     //         Storage::disk('ftp')->delete($cv->file); // Supprime le fichier du stockage
    //     //     }
            
    //     //     // Supprimer les enregistrements de la base de données
    //     //     Cv_User::where('user_id', $user->id)->delete();
            
         
        
    //     //     // Vérifier si c'est un tableau (plusieurs fichiers)
    //     //     if (is_array($files)) {
    //     //         foreach ($files as $index => $file) {
    //     //             $originalName = $file->getClientOriginalName();
    //     //             $mimeType = $file->getClientMimeType();
    //     //             $size = $file->getSize();
    //     //             $isDefault = isset($selectedCvs[$index]) && $selectedCvs[$index] === '1'; // Vérifier si le fichier est sélectionné
        
    //     //             // Stocker le fichier dans le répertoire des CVs
    //     //             user/cv/2024/userid/
    //     //             $cvDirectory = 'user/cv'. $currentYear. $user->id . '/' ;
    //     //             $cvPath = $file->storeAs($cvDirectory, $originalName, 'ftp');
        
    //     //             // Enregistrer les détails du fichier dans la base de données
    //     //             Cv_User::create([
    //     //                 'user_id' => $user->id,
    //     //                 'file' => $cvPath,
    //     //                 'name' => $originalName,
    //     //                 'extension' => $mimeType,
    //     //                 'default_cv' => $isDefault, // Définir si c'est le CV par défaut
    //     //                 'created_at' => now(),
    //     //                 'updated_at' => now(),
    //     //             ]);
        
    //     //             // Ajouter les détails de chaque fichier dans le tableau
    //     //             $fileDetails[] = [
    //     //                 'file_name' => $originalName,
    //     //                 'mime_type' => $mimeType,
    //     //                 'size' => $size,
    //     //                 'stored_path' => $cvPath,
    //     //                 "defaults"=>$isDefault,
    //     //             ];
    //     //         }
        
    //     //         // Retourner une réponse avec les infos de tous les fichiers
    //     //         return response()->json(['files' => $fileDetails], 200);
    //     //     }
    //     //     // ... (Le reste de ton code pour un seul fichier)
    //     // }
        
    //     if ($request->hasFile('cv')) {
    //         // Récupérer les CV existants de l'utilisateur pour les supprimer
    //         $existingCvs = Cv_User::where('user_id', $user->id)->get();
    //         $files = $request->file('cv'); // Récupère les fichiers dans un tableau
    //         $selectedCvs = $request->input('selected'); // Récupère les valeurs de 'selected'
        
    //         $fileDetails = [];
            
    //         // Vérifier si le nombre de fichiers et de fichiers sélectionnés ne dépasse pas 4
    //         if (count($files) > 4) {
    //             return response()->json(['error' => 'Vous ne pouvez pas télécharger plus de 4 CV.'], 400);
    //         }
        
    //         // Supprimer les fichiers du stockage
    //         foreach ($existingCvs as $cv) {
    //             Storage::disk('ftp')->delete($cv->file); // Supprime le fichier du stockage
    //         }
        
    //         // Supprimer les enregistrements de la base de données
    //         Cv_User::where('user_id', $user->id)->delete();
        
    //         // Vérifier si c'est un tableau (plusieurs fichiers)
    //         if (is_array($files)) {
    //             foreach ($files as $index => $file) {
    //                 $originalName = $file->getClientOriginalName();
    //                 $mimeType = $file->getClientMimeType();
    //                 $size = $file->getSize();
    //                 $isDefault = isset($selectedCvs[$index]) && $selectedCvs[$index] === '1'; // Vérifier si le fichier est sélectionné
        
    //                 // Créer un nom de fichier unique
    //                 $uniqueName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        
    //                 // Stocker le fichier dans le répertoire des CVs
    //                 $cvDirectory = 'user/cv/' . $currentYear . '/' . $user->id . '/';
    //                 $cvPath = $file->storeAs($cvDirectory, $uniqueName, 'ftp');
        
    //                 // Enregistrer les détails du fichier dans la base de données
    //                 Cv_User::create([
    //                     'user_id' => $user->id,
    //                     'file' => $cvPath,
    //                     'name' => $uniqueName,
    //                     'extension' => $mimeType,
    //                     'default_cv' => $isDefault, // Définir si c'est le CV par défaut
    //                     'created_at' => now(),
    //                     'updated_at' => now(),
    //                 ]);
        
    //                 // Ajouter les détails de chaque fichier dans le tableau
    //                 $fileDetails[] = [
    //                     'file_name' => $uniqueName,
    //                     'mime_type' => $mimeType,
    //                     'size' => $size,
    //                     'stored_path' => $cvPath,
    //                     'defaults' => $isDefault,
    //                 ];
    //             }
        
    //             // Retourner une réponse avec les infos de tous les fichiers
    //             return response()->json(['files' => $fileDetails], 200);
    //         }
    //         // ... (Le reste de ton code pour un seul fichier)
    //     }
        
    //     // Mise à jour de l'utilisateur
    //     $user->update($validatedData);
    //     $user->save();
    
    //     // Retourner une réponse
    //     return response()->json(['message' => 'Utilisateur mis à jour avec succès!', 'user' => $user], 200);
    // }
    

}

    
