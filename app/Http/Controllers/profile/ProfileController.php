<?php
namespace App\Http\Controllers\profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Tags;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\PublicationReposted;
use Illuminate\Support\Str;


class ProfileController extends Controller
{



    public function editeProfile( Request $request )
    {
        // Log the request immediately when method is called
        try {
            $logData = [
                'timestamp' => now()->toDateTimeString(),
                'request_method' => $request->method(),
                'request_url' => $request->fullUrl(),
                'request_all' => $request->all(),
                'request_has_ville_id' => $request->has( 'ville_id' ),
                'request_has_tag_id' => $request->has( 'tag_id' ),
                'ville_id_value' => $request->input( 'ville_id' ),
                'tag_id_value' => $request->input( 'tag_id' ),
                'profileType' => $request->input( 'profileType' ),
                'id' => $request->input( 'id' ),
            ];

            // Use multiple logging methods to ensure it's captured
            Log::info( '========== EditProfile Method Called ==========', $logData );
            Log::channel( 'daily' )->info( '========== EditProfile Method Called ==========', $logData );
            error_log( 'EditProfile Called: ' . json_encode( $logData, JSON_PRETTY_PRINT ) );
        } catch (\Exception $e) {
            error_log( 'Failed to log EditProfile request: ' . $e->getMessage() );
        }

        // Validation des données de la requête
        try {
            $request->validate( [
                'id' => 'required|integer',
                'profileType' => 'required|in:user,etablissement',
                'firstName' => 'required_if:profileType,user|string|max:255',
                'lastName' => 'nullable|string|max:255',
                'bio' => 'nullable|string',
                'nom' => 'required_if:profileType,etablissement|string|max:255',
                'slug' => 'required_if:profileType,user|string|max:255',
                'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'siteweb' => 'nullable|string|max:255',
                'defaultPhoneNumber' => 'nullable|string|max:50',
                'ville_id' => 'nullable|string', // Accept string, will convert to integer
                'region_id' => 'nullable|string', // Accept string, will convert to integer
                'tag_id' => 'nullable|string', // Accept string, will convert to integer
                'tag_name' => 'nullable|string|max:255', // Accept tag name for custom tags
            ] );
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error( 'EditProfile - Validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all(),
            ] );
            throw $e;
        } catch (\Exception $e) {
            Log::error( 'EditProfile - Validation error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
            throw $e;
        }

        // Get current year for folder structure
        $currentYear = date( 'Y' );

        if ($request->profileType === 'user') {
            $user = User::findOrFail( $request->id );

            // Mise à jour des infos utilisateur
            $user->first_name = $request->firstName;
            $user->last_name = $request->lastName;
            // Handle bio - set to null if empty string, otherwise save the value
            $bio = $request->bio;
            if ($bio === '' || $bio === null || ( is_string( $bio ) && trim( $bio ) === '' )) {
                $user->bio = null;
                // Force update directly in database as backup
                DB::table( 'users' )
                    ->where( 'id', $user->id )
                    ->update( ['bio' => null] );
                Log::info( 'EditProfile - Setting user bio to null (empty value)' );
            } else {
                $user->bio = $bio;
                // Force update directly in database as backup
                DB::table( 'users' )
                    ->where( 'id', $user->id )
                    ->update( ['bio' => $bio] );
                Log::info( 'EditProfile - Updating user bio', [
                    'value' => $bio,
                    'saved_value' => $user->bio,
                ] );
            }
            $user->slug = $request->slug;

            // Gérer le téléchargement de l'avatar
            if ($request->hasFile( 'avatar' )) {
                $avatar = $request->file( 'avatar' );

                if ($avatar->isValid()) {
                    // Supprimer l'ancien avatar
                    if (!empty( $user->avatar ) && Storage::disk( 'ftp' )->exists( $user->avatar )) {
                        Storage::disk( 'ftp' )->delete( $user->avatar );
                    }

                    // Créer le dossier si nécessaire avec l'année
                    $baseFolder = "logos/users/{$currentYear}";

                    // Générer un nom unique et crypté pour le nouvel avatar
                    $encryptedName = md5( uniqid() . time() );
                    $extension = $avatar->getClientOriginalExtension();
                    $avatarName = "{$user->id}-{$encryptedName}.{$extension}";

                    // Stocker le nouvel avatar
                    $path = Storage::disk( 'ftp' )->putFileAs( $baseFolder, $avatar, $avatarName );

                    // Mettre à jour le chemin dans la BDD
                    $user->avatar = $path;
                }
            }

            $user->save();

            return response()->json( [
                'message' => 'Profile updated successfully!',
                'avatarUrl' => Storage::disk( 'ftp' )->url( $user->avatar ),
                'data' => $user
            ] );
        } elseif ($request->profileType !== 'user') {
            try {
                try {
                    $etablissement = Etablissement::findOrFail( $request->id );
                } catch (\Exception $e) {
                    Log::error( 'EditProfile - Etablissement not found', [
                        'id' => $request->id,
                        'error' => $e->getMessage(),
                    ] );
                    throw $e;
                }

                // Log incoming request data
                Log::info( 'EditProfile - Etablissement Update Request', [
                    'etablissement_id' => $request->id,
                    'ville_id_request' => $request->ville_id,
                    'tag_id_request' => $request->tag_id,
                    'ville_id_type' => gettype( $request->ville_id ),
                    'tag_id_type' => gettype( $request->tag_id ),
                    'has_ville_id' => $request->has( 'ville_id' ),
                    'has_tag_id' => $request->has( 'tag_id' ),
                    'all_request_data' => $request->all(),
                ] );

                // Log current values before update
                Log::info( 'EditProfile - Current Etablissement Values', [
                    'etablissement_id' => $etablissement->id,
                    'current_ville_id' => $etablissement->ville_id,
                    'current_tag_id' => $etablissement->tag_id,
                ] );

                // Mise à jour des infos établissement
                // Update nom field using same pattern as other fields to ensure it's saved
                $nomValue = $request->nom;
                Log::info( 'EditProfile - Processing nom', [
                    'raw_value' => $nomValue,
                    'type' => gettype( $nomValue ),
                    'is_empty' => empty( $nomValue ),
                    'is_null' => is_null( $nomValue ),
                    'trimmed' => is_string( $nomValue ) ? trim( $nomValue ) : $nomValue,
                ] );
                if ($nomValue === '' || $nomValue === null || ( is_string( $nomValue ) && trim( $nomValue ) === '' )) {
                    // Don't allow empty nom - keep existing value
                    Log::warning( 'EditProfile - nom is empty, keeping existing value' );
                } else {
                    $oldNom = $etablissement->nom;
                    // Force set the attribute directly
                    $etablissement->setAttribute( 'nom', trim( $nomValue ) );
                    // Also try fill method
                    $etablissement->fill( ['nom' => trim( $nomValue )] );
                    // Force update directly in database as backup
                    DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->update( ['nom' => trim( $nomValue )] );
                    // Refresh to get updated value
                    $etablissement->refresh();
                    Log::info( 'EditProfile - Updating nom', [
                        'old_value' => $oldNom,
                        'request_value' => $nomValue,
                        'converted_value' => $etablissement->nom,
                        'getAttribute' => $etablissement->getAttribute( 'nom' ),
                        'validation' => 'passed',
                        'db_updated' => true,
                    ] );
                }
                // Handle bio - set to null if empty string, otherwise save the value
                $bio = $request->bio;
                Log::info( 'EditProfile - Processing bio', [
                    'raw_value' => $bio,
                    'type' => gettype( $bio ),
                    'is_empty' => empty( $bio ),
                    'is_null' => is_null( $bio ),
                    'trimmed' => is_string( $bio ) ? trim( $bio ) : $bio,
                ] );
                if ($bio === '' || $bio === null || ( is_string( $bio ) && trim( $bio ) === '' )) {
                    $etablissement->bio = null;
                    // Force update directly in database as backup
                    DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->update( ['bio' => null] );
                    // Refresh to get updated value
                    $etablissement->refresh();
                    Log::info( 'EditProfile - Setting bio to null (empty value)' );
                } else {
                    // Save the bio value as-is
                    $etablissement->bio = $bio;
                    // Force update directly in database as backup
                    DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->update( ['bio' => $bio] );
                    // Refresh to get updated value
                    $etablissement->refresh();
                    Log::info( 'EditProfile - Updating bio', [
                        'value' => $bio,
                        'saved_value' => $etablissement->bio,
                    ] );
                }
                $etablissement->slug = $request->slug;
                // Handle website - set to null if empty string
                $website = $request->siteweb;
                if ($website === '' || $website === null || ( is_string( $website ) && trim( $website ) === '' )) {
                    $etablissement->siteweb = null;
                    // Force update directly in database as backup
                    DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->update( ['siteweb' => null] );
                    Log::info( 'EditProfile - Setting siteweb to null (empty value)' );
                } else {
                    $etablissement->siteweb = $website;
                    // Force update directly in database as backup
                    DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->update( ['siteweb' => $website] );
                    // Refresh to get updated value
                    $etablissement->refresh();
                    Log::info( 'EditProfile - Updating siteweb', [
                        'value' => $website,
                        'saved_value' => $etablissement->siteweb,
                    ] );
                }
                // Handle phone number - set to null if empty string, otherwise save the value
                $phoneNumber = $request->defaultPhoneNumber;
                Log::info( 'EditProfile - Processing defaultPhoneNumber', [
                    'raw_value' => $phoneNumber,
                    'type' => gettype( $phoneNumber ),
                    'is_empty' => empty( $phoneNumber ),
                    'is_null' => is_null( $phoneNumber ),
                    'trimmed' => is_string( $phoneNumber ) ? trim( $phoneNumber ) : $phoneNumber,
                ] );
                if ($phoneNumber === '' || $phoneNumber === null || ( is_string( $phoneNumber ) && trim( $phoneNumber ) === '' )) {
                    $etablissement->default_phone_number = null;
                    // Force update directly in database as backup
                    DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->update( ['default_phone_number' => null] );
                    Log::info( 'EditProfile - Setting default_phone_number to null (empty value)' );
                } else {
                    // Save the phone number value as-is
                    $etablissement->default_phone_number = $phoneNumber;
                    // Force update directly in database as backup
                    DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->update( ['default_phone_number' => $phoneNumber] );
                    // Refresh to get updated value
                    $etablissement->refresh();
                    Log::info( 'EditProfile - Updating default_phone_number', [
                        'value' => $phoneNumber,
                        'saved_value' => $etablissement->default_phone_number,
                    ] );
                }

                // Mise à jour des informations de localisation (only ville_id)
                // Always check for ville_id in request (FormData always sends it, even if empty)
                try {
                    if ($request->has( 'ville_id' )) {
                        $villeIdValue = $request->input( 'ville_id' );
                        Log::info( 'EditProfile - Processing ville_id', [
                            'raw_value' => $villeIdValue,
                            'type' => gettype( $villeIdValue ),
                            'is_empty' => empty( $villeIdValue ),
                            'is_null' => is_null( $villeIdValue ),
                            'trimmed' => is_string( $villeIdValue ) ? trim( $villeIdValue ) : $villeIdValue,
                        ] );

                        // Convert empty string to null, otherwise convert to integer and validate
                        if ($villeIdValue === '' || $villeIdValue === null || ( is_string( $villeIdValue ) && trim( $villeIdValue ) === '' )) {
                            $etablissement->setAttribute( 'ville_id', null );
                            $etablissement->fill( ['ville_id' => null] );
                            // Force update directly in database as backup
                            DB::table( 'etablissements' )
                                ->where( 'id', $etablissement->id )
                                ->update( ['ville_id' => null] );
                            // Refresh to get updated value
                            $etablissement->refresh();
                            Log::info( 'EditProfile - Setting ville_id to null (empty value)' );
                        } else {
                            $villeIdInt = (int) $villeIdValue;
                            // Validate that the ville exists
                            $villeExists = DB::table( 'villes' )->where( 'id', $villeIdInt )->exists();
                            Log::info( 'EditProfile - Checking ville existence', [
                                'ville_id' => $villeIdInt,
                                'exists' => $villeExists,
                            ] );

                            if ($villeExists) {
                                $oldVilleId = $etablissement->ville_id;
                                // Force set the attribute directly
                                $etablissement->setAttribute( 'ville_id', $villeIdInt );
                                // Also try fill method
                                $etablissement->fill( ['ville_id' => $villeIdInt] );
                                // Force update directly in database as backup
                                DB::table( 'etablissements' )
                                    ->where( 'id', $etablissement->id )
                                    ->update( ['ville_id' => $villeIdInt] );
                                // Refresh to get updated value
                                $etablissement->refresh();
                                Log::info( 'EditProfile - Updating ville_id', [
                                    'old_value' => $oldVilleId,
                                    'request_value' => $villeIdValue,
                                    'request_value_type' => gettype( $villeIdValue ),
                                    'converted_value' => $etablissement->ville_id,
                                    'getAttribute' => $etablissement->getAttribute( 'ville_id' ),
                                    'validation' => 'passed',
                                    'db_updated' => true,
                                ] );
                            } else {
                                Log::warning( 'EditProfile - ville_id does not exist in database', [
                                    'requested_id' => $villeIdInt,
                                ] );
                                // Don't update if ville doesn't exist
                            }
                        }
                    } else {
                        Log::info( 'EditProfile - ville_id key not found in request' );
                    }
                } catch (\Exception $e) {
                    Log::error( 'EditProfile - Error processing ville_id', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ] );
                }

                // Mise à jour du tag principal (tag_id) - similar to CreatePublicationController
                // Process tags like CreatePublicationController: handle existing tags, custom tags, and tag creation
                try {
                    $tagIds = [];
                    $tagNames = [];
                    $receivedTagIds = [];

                    // IMPORTANT: Check if tag_id exists (not empty check, because "0" is considered empty in PHP)
                    // Use same logic as CreatePublicationController
                    if ($request->has( 'tag_id' ) && $request->get( 'tag_id' ) !== null && $request->get( 'tag_id' ) !== '') {
                        $tagIdValue = $request->input( 'tag_id' );
                        Log::info( 'EditProfile - Processing tag_id', [
                            'raw_value' => $tagIdValue,
                            'type' => gettype( $tagIdValue ),
                            'is_empty' => empty( $tagIdValue ),
                            'is_null' => is_null( $tagIdValue ),
                            'trimmed' => is_string( $tagIdValue ) ? trim( $tagIdValue ) : $tagIdValue,
                            'has_tag_name' => $request->has( 'tag_name' ),
                            'tag_name' => $request->input( 'tag_name' ),
                        ] );

                        // Convert to array format like CreatePublicationController
                        $receivedTagIds = is_array( $tagIdValue )
                            ? $tagIdValue
                            : explode( ',', $tagIdValue );

                        Log::info( 'EditProfile - Received tag IDs', [
                            'received_tag_ids' => $receivedTagIds,
                            'has_zeros' => in_array( "0", $receivedTagIds ) || in_array( 0, $receivedTagIds ),
                        ] );

                        // Filter out invalid IDs (0 or "0")
                        $validTagIds = array_filter( $receivedTagIds, function ( $id ) {
                            return $id !== "0" && $id !== 0;
                        } );

                        Log::info( 'EditProfile - Valid tag IDs after filter', [
                            'valid_tag_ids' => $validTagIds,
                        ] );

                        // Process existing tags (valid IDs)
                        if (!empty( $validTagIds )) {
                            $existingTags = Tags::whereIn( 'id', $validTagIds )->get();
                            Log::info( 'EditProfile - Found existing tags', [
                                'count' => $existingTags->count(),
                                'tags' => $existingTags->pluck( 'id', 'name' )->toArray(),
                            ] );
                            foreach ($existingTags as $tag) {
                                $tag->compteur_publications += 1;
                                $tag->save();
                                $tagIds[] = $tag->id;
                                $tagNames[] = $tag->name;
                            }
                        }

                        // Handle custom tags (tag_id = "0" with tag_name) - like CreatePublicationController
                        $hasZeros = in_array( "0", $receivedTagIds ) || in_array( 0, $receivedTagIds );
                        Log::info( 'EditProfile - Checking custom tags', [
                            'has_zeros' => $hasZeros,
                            'has_tag_name' => $request->has( 'tag_name' ),
                            'tag_name_value' => $request->input( 'tag_name' ),
                            'tag_name_not_empty' => $request->has( 'tag_name' ) && !empty( $request->get( 'tag_name' ) ),
                        ] );

                        if ($hasZeros && $request->has( 'tag_name' ) && !empty( $request->get( 'tag_name' ) )) {
                            $tagNameValue = $request->get( 'tag_name' );
                            Log::info( 'EditProfile - Processing custom tag name', [
                                'tag_name' => $tagNameValue,
                            ] );
                            $newTagNames = $this->processTagNames( $tagNameValue );
                            Log::info( 'EditProfile - Processed tag names', [
                                'new_tag_names' => $newTagNames,
                            ] );
                            foreach ($newTagNames as $name) {
                                Log::info( 'EditProfile - Processing individual tag name', [
                                    'name' => $name,
                                ] );
                                $this->processTagName( $name, $tagIds, $tagNames );
                                Log::info( 'EditProfile - After processTagName', [
                                    'tag_ids' => $tagIds,
                                    'tag_names' => $tagNames,
                                ] );
                            }
                        }

                        // Process additional tag names if provided (without "0" in received IDs)
                        if ($request->has( 'tag_name' ) && !empty( $request->get( 'tag_name' ) ) && !in_array( "0", $receivedTagIds )) {
                            $tagNameValue = $request->get( 'tag_name' );
                            Log::info( 'EditProfile - Processing additional tag name (no zero)', [
                                'tag_name' => $tagNameValue,
                            ] );
                            $newTagNames = $this->processTagNames( $tagNameValue );
                            foreach ($newTagNames as $name) {
                                $this->processTagName( $name, $tagIds, $tagNames );
                            }
                        }

                        Log::info( 'EditProfile - Final tag processing result', [
                            'tag_ids' => $tagIds,
                            'tag_names' => $tagNames,
                            'tag_ids_count' => count( $tagIds ),
                        ] );

                        // Update etablissement with the final tag_id (use first tag if multiple, or null if empty)
                        if (!empty( $tagIds )) {
                            $finalTagId = $tagIds[0]; // Use first tag (main tag)
                            $oldTagId = $etablissement->tag_id;
                            Log::info( 'EditProfile - Setting tag_id to etablissement', [
                                'old_tag_id' => $oldTagId,
                                'final_tag_id' => $finalTagId,
                                'tag_ids' => $tagIds,
                                'tag_names' => $tagNames,
                            ] );
                            // Force set the attribute directly
                            $etablissement->setAttribute( 'tag_id', $finalTagId );
                            // Also try fill method
                            $etablissement->fill( ['tag_id' => $finalTagId] );
                            // Force update directly in database as backup
                            DB::table( 'etablissements' )
                                ->where( 'id', $etablissement->id )
                                ->update( ['tag_id' => $finalTagId] );
                            // Refresh to get updated value
                            $etablissement->refresh();
                            Log::info( 'EditProfile - Successfully updated tag_id', [
                                'old_value' => $oldTagId,
                                'final_tag_id' => $finalTagId,
                                'tag_ids' => $tagIds,
                                'tag_names' => $tagNames,
                                'validation' => 'passed',
                                'db_updated' => true,
                                'etablissement_tag_id' => $etablissement->tag_id,
                            ] );
                        } else {
                            // No valid tags - set to null
                            Log::info( 'EditProfile - No valid tags found, setting to null', [
                                'tag_ids' => $tagIds,
                                'tag_names' => $tagNames,
                            ] );
                            $etablissement->setAttribute( 'tag_id', null );
                            $etablissement->fill( ['tag_id' => null] );
                            // Force update directly in database as backup
                            DB::table( 'etablissements' )
                                ->where( 'id', $etablissement->id )
                                ->update( ['tag_id' => null] );
                            // Refresh to get updated value
                            $etablissement->refresh();
                            Log::info( 'EditProfile - Setting tag_id to null (no valid tags)' );
                        }
                    } else {
                        // Empty tag_id - set to null
                        Log::info( 'EditProfile - tag_id is empty or not provided, setting to null', [
                            'has_tag_id' => $request->has( 'tag_id' ),
                            'tag_id_value' => $request->input( 'tag_id' ),
                        ] );
                        $etablissement->setAttribute( 'tag_id', null );
                        $etablissement->fill( ['tag_id' => null] );
                        // Force update directly in database as backup
                        DB::table( 'etablissements' )
                            ->where( 'id', $etablissement->id )
                            ->update( ['tag_id' => null] );
                        // Refresh to get updated value
                        $etablissement->refresh();
                        Log::info( 'EditProfile - Setting tag_id to null (empty value)' );
                    }
                } catch (\Exception $e) {
                    Log::error( 'EditProfile - Error processing tag_id', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ] );
                    // Re-throw to see the error
                    throw $e;
                }

                // Mise à jour de la région (region_id)
                // Always check for region_id in request (FormData always sends it, even if empty)
                try {
                    if ($request->has( 'region_id' )) {
                        $regionIdValue = $request->input( 'region_id' );
                        Log::info( 'EditProfile - Processing region_id', [
                            'raw_value' => $regionIdValue,
                            'type' => gettype( $regionIdValue ),
                            'is_empty' => empty( $regionIdValue ),
                            'is_null' => is_null( $regionIdValue ),
                            'trimmed' => is_string( $regionIdValue ) ? trim( $regionIdValue ) : $regionIdValue,
                        ] );

                        // Convert empty string to null, otherwise convert to integer and validate
                        if ($regionIdValue === '' || $regionIdValue === null || ( is_string( $regionIdValue ) && trim( $regionIdValue ) === '' )) {
                            $etablissement->setAttribute( 'region_id', null );
                            $etablissement->fill( ['region_id' => null] );
                            // Force update directly in database as backup
                            DB::table( 'etablissements' )
                                ->where( 'id', $etablissement->id )
                                ->update( ['region_id' => null] );
                            // Refresh to get updated value
                            $etablissement->refresh();
                            Log::info( 'EditProfile - Setting region_id to null (empty value)' );
                        } else {
                            $regionIdInt = (int) $regionIdValue;
                            // Validate that the region exists
                            $regionExists = DB::table( 'regions' )->where( 'id', $regionIdInt )->exists();
                            Log::info( 'EditProfile - Checking region existence', [
                                'region_id' => $regionIdInt,
                                'exists' => $regionExists,
                            ] );

                            if ($regionExists) {
                                $oldRegionId = $etablissement->region_id;
                                // Force set the attribute directly
                                $etablissement->setAttribute( 'region_id', $regionIdInt );
                                // Also try fill method
                                $etablissement->fill( ['region_id' => $regionIdInt] );
                                // Force update directly in database as backup
                                DB::table( 'etablissements' )
                                    ->where( 'id', $etablissement->id )
                                    ->update( ['region_id' => $regionIdInt] );
                                // Refresh to get updated value
                                $etablissement->refresh();
                                Log::info( 'EditProfile - Updating region_id', [
                                    'old_value' => $oldRegionId,
                                    'request_value' => $regionIdValue,
                                    'request_value_type' => gettype( $regionIdValue ),
                                    'converted_value' => $etablissement->region_id,
                                    'getAttribute' => $etablissement->getAttribute( 'region_id' ),
                                    'validation' => 'passed',
                                    'db_updated' => true,
                                ] );
                            } else {
                                Log::warning( 'EditProfile - region_id does not exist in database', [
                                    'requested_id' => $regionIdInt,
                                ] );
                                // Don't update if region doesn't exist
                            }
                        }
                    } else {
                        Log::info( 'EditProfile - region_id key not found in request' );
                    }
                } catch (\Exception $e) {
                    Log::error( 'EditProfile - Error processing region_id', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ] );
                }

                // Log model attributes before save
                Log::info( 'EditProfile - Model attributes before save', [
                    'etablissement_id' => $etablissement->id,
                    'ville_id' => $etablissement->getAttribute( 'ville_id' ),
                    'tag_id' => $etablissement->getAttribute( 'tag_id' ),
                    'isDirty_ville_id' => $etablissement->isDirty( 'ville_id' ),
                    'isDirty_tag_id' => $etablissement->isDirty( 'tag_id' ),
                    'getDirty' => $etablissement->getDirty(),
                    'fillable' => $etablissement->getFillable(),
                ] );

                // Gérer le téléchargement du logo
                if ($request->hasFile( 'logo' )) {
                    $logo = $request->file( 'logo' );

                    if ($logo->isValid()) {
                        // Supprimer l'ancien logo
                        if (!empty( $etablissement->logo ) && Storage::disk( 'ftp' )->exists( $etablissement->logo )) {
                            Storage::disk( 'ftp' )->delete( $etablissement->logo );
                        }

                        // Créer le dossier si nécessaire avec l'année
                        $baseFolder = "logos/etablissements/{$currentYear}";

                        // Générer un nom unique et crypté pour le nouveau logo
                        $encryptedName = md5( uniqid() . time() );
                        $extension = $logo->getClientOriginalExtension();
                        $logoName = "{$etablissement->id}-{$encryptedName}.{$extension}";

                        // Stocker le logo
                        $path = Storage::disk( 'ftp' )->putFileAs( $baseFolder, $logo, $logoName );

                        // Mettre à jour le chemin dans la BDD
                        $etablissement->logo = $path;
                    }
                }

                // Log values before save
                Log::info( 'EditProfile - Values before save', [
                    'etablissement_id' => $etablissement->id,
                    'ville_id' => $etablissement->ville_id,
                    'tag_id' => $etablissement->tag_id,
                    'isDirty' => $etablissement->isDirty(),
                    'getDirty' => $etablissement->getDirty(),
                    'fillable' => $etablissement->getFillable(),
                ] );

                try {
                    $saveResult = $etablissement->save();
                    Log::info( 'EditProfile - Save result', [
                        'save_result' => $saveResult,
                        'wasRecentlyCreated' => $etablissement->wasRecentlyCreated,
                    ] );
                } catch (\Exception $e) {
                    Log::error( 'EditProfile - Save failed', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'etablissement_id' => $etablissement->id,
                    ] );
                    throw $e;
                }

                // Log values after save
                try {
                    $etablissement->refresh();
                    Log::info( 'EditProfile - Values after save and refresh', [
                        'etablissement_id' => $etablissement->id,
                        'ville_id' => $etablissement->ville_id,
                        'tag_id' => $etablissement->tag_id,
                    ] );
                } catch (\Exception $e) {
                    Log::error( 'EditProfile - Refresh failed', [
                        'error' => $e->getMessage(),
                    ] );
                }

                // Double check from database directly
                try {
                    $dbCheck = DB::table( 'etablissements' )
                        ->where( 'id', $etablissement->id )
                        ->select( 'id', 'ville_id', 'tag_id' )
                        ->first();
                    Log::info( 'EditProfile - Direct database check', [
                        'db_values' => $dbCheck,
                        'model_ville_id' => $etablissement->ville_id,
                        'model_tag_id' => $etablissement->tag_id,
                        'match_ville_id' => $dbCheck && $dbCheck->ville_id == $etablissement->ville_id,
                        'match_tag_id' => $dbCheck && $dbCheck->tag_id == $etablissement->tag_id,
                    ] );
                } catch (\Exception $e) {
                    Log::error( 'EditProfile - Database check failed', [
                        'error' => $e->getMessage(),
                    ] );
                }

                Log::info( '========== EditProfile Method Completed Successfully ==========', [
                    'etablissement_id' => $etablissement->id,
                    'final_ville_id' => $etablissement->ville_id,
                    'final_tag_id' => $etablissement->tag_id,
                ] );

                // Load relationships for response
                $etablissement->load( ['ville:id,name,slug', 'tag:id,name,slug'] );
                // Load region relationship if it exists
                if (method_exists( $etablissement, 'region' )) {
                    $etablissement->load( ['region:id,name,slug,ville_id'] );
                } else {
                    // If relationship doesn't exist, manually load region
                    if ($etablissement->region_id) {
                        $region = DB::table( 'regions' )
                            ->where( 'id', $etablissement->region_id )
                            ->select( 'id', 'name', 'slug', 'ville_id' )
                            ->first();
                        if ($region) {
                            $etablissement->setRelation( 'region', (object) $region );
                        }
                    }
                }

                // Prepare response data without ville_id, region_id, and tag_id, but with ville, region, and tag objects
                $responseData = $etablissement->toArray();
                // Remove ville_id, region_id, and tag_id from response
                unset($responseData['ville_id']);
                unset($responseData['region_id']);
                unset($responseData['tag_id']);
                // Add ville object if it exists
                if ($etablissement->ville) {
                    $responseData['ville'] = [
                        'id' => $etablissement->ville->id,
                        'name' => $etablissement->ville->name,
                        'slug' => $etablissement->ville->slug,
                    ];
                } else {
                    $responseData['ville'] = null;
                }
                // Add region object if it exists
                if ($etablissement->region) {
                    $responseData['region'] = [
                        'id' => is_object( $etablissement->region ) ? $etablissement->region->id : $etablissement->region['id'],
                        'name' => is_object( $etablissement->region ) ? $etablissement->region->name : $etablissement->region['name'],
                        'slug' => is_object( $etablissement->region ) ? $etablissement->region->slug : $etablissement->region['slug'],
                    ];
                } else {
                    $responseData['region'] = null;
                }
                // Add tag object if it exists
                if ($etablissement->tag) {
                    $responseData['tag'] = [
                        'id' => $etablissement->tag->id,
                        'name' => $etablissement->tag->name,
                        'slug' => $etablissement->tag->slug,
                    ];
                } else {
                    $responseData['tag'] = null;
                }

                return response()->json( [
                    'message' => 'Profile updated successfully!',
                    'logoUrl' => Storage::disk( 'ftp' )->url( $etablissement->logo ),
                    'data' => $responseData
                ] );
            } catch (\Exception $e) {
                Log::error( '========== EditProfile Method Failed ==========', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request_data' => $request->all(),
                ] );
                throw $e;
            }
        }

        return response()->json( ['message' => 'Invalid profile type.'], 400 );
    }



    public function getStats( Request $request )
    {
        // Validate the incoming request
        $request->validate( [
            'id' => 'required|integer',
            'type' => 'required|in:user,etablissement',
        ] );

        $id = $request->id;
        $type = $request->type;
        $etablissement = null;

        if ($type === 'user') {
            $user = User::find( $id );

            if (!$user) {
                return response()->json( ['message' => 'User not found.'], 404 );
            }
            $entity = $user;
            $publicationsCount = $user->publications()->whereNull( 'etab_id' )->count();
            $ActifPublicationsCount = $user->publications()->whereNull( 'etab_id' )->where( 'status', '1' )->count();

        } else { // type === 'etablissement'
            $etablissement = Etablissement::find( $id );

            if (!$etablissement) {
                return response()->json( ['message' => 'Etablissement not found.'], 404 );
            }
            $entity = $etablissement;

            // Get publications count
            $publicationsCount = $etablissement->publications()->count();
            $ActifPublicationsCount = $etablissement->publications()->where( 'status', '1' )->count();
        }

        $countFollowers = $entity->followersUsers()->count() + $entity->followersEtablissements()->count();
        $countFollowing = $entity->followingUsers()->count() + $entity->followingEtablissements()->count();

        $response = [
            'followers' => [
                'count' => $countFollowers
            ],
            'following' => [
                'count' => $countFollowing
            ],
            'publications_count' => $publicationsCount,
            'actif_publications_count' => $ActifPublicationsCount,
        ];

        // Add siteweb, default_phone_number, ville, region, and tag for establishments only
        if ($type === 'etablissement' && $etablissement) {
            $response['siteweb'] = $etablissement->siteweb ?? null;
            $response['default_phone_number'] = $etablissement->default_phone_number ?? null;

            // Load ville, region, and tag relationships
            $etablissement->load( ['ville:id,name,slug', 'tag:id,name,slug'] );
            // Load region relationship if it exists
            if (method_exists( $etablissement, 'region' )) {
                $etablissement->load( ['region:id,name,slug,ville_id'] );
            } else {
                // If relationship doesn't exist, manually load region
                if ($etablissement->region_id) {
                    $region = DB::table( 'regions' )
                        ->where( 'id', $etablissement->region_id )
                        ->select( 'id', 'name', 'slug', 'ville_id' )
                        ->first();
                    if ($region) {
                        $etablissement->setRelation( 'region', (object) $region );
                    }
                }
            }

            // Add ville object if exists
            if ($etablissement->ville) {
                $response['ville'] = [
                    'id' => $etablissement->ville->id,
                    'name' => $etablissement->ville->name,
                    'slug' => $etablissement->ville->slug,
                ];
            } else {
                $response['ville'] = null;
            }

            // Add region object if exists
            if ($etablissement->region) {
                $response['region'] = [
                    'id' => is_object( $etablissement->region ) ? $etablissement->region->id : $etablissement->region['id'],
                    'name' => is_object( $etablissement->region ) ? $etablissement->region->name : $etablissement->region['name'],
                    'slug' => is_object( $etablissement->region ) ? $etablissement->region->slug : $etablissement->region['slug'],
                ];
            } else {
                $response['region'] = null;
            }

            // Add tag object if exists
            if ($etablissement->tag) {
                $response['tag'] = [
                    'id' => $etablissement->tag->id,
                    'name' => $etablissement->tag->name,
                    'slug' => $etablissement->tag->slug,
                ];
            } else {
                $response['tag'] = null;
            }
        }

        return response()->json( $response );
    }


    public function getPosts( Request $request )
    {
        $request->validate( [
            'id' => 'required|integer',
            'type' => 'required|in:user,etablissement',
            'page' => 'sometimes|integer|min:1',
            // 'isVisitor' => 'required|boolean',
            'filters' => 'sometimes|array',
            'filters.type.image' => 'sometimes|boolean',
            'filters.type.video' => 'sometimes|boolean',
            'filters.type.boosted' => 'sometimes|boolean',
            'filters.type.active' => 'sometimes|boolean',
            'filters.type.inactive' => 'sometimes|boolean',
            'filters.sort' => 'sometimes|in:recent,oldest,mostViewed,mostSaved,price_ascending,price_descending',
            'justActive' => 'sometimes|boolean', // New optional parameter
        ] );

        $id = $request->id;
        $type = $request->type;
        $isVisitor = $request->isVisitor;
        $filters = $request->filters ?? null;
        $justActive = $request->justActive ?? null; // Capture the optional justActive parameter
        $perPage = 20;

        if ($type === 'user') {
            $entity = User::find( $id );
            if (!$entity) {
                return response()->json( ['message' => 'User not found.'], 404 );
            }
            $query = $entity->publications()->whereNull( 'etab_id' );
            $query->where( function ( $q ) {
                $q->whereNull( 'is_marketplace' )->orWhere( 'is_marketplace', '!=', 1 );
            } );

            if ($isVisitor)
                $query = $query->where( 'status', 1 );
            else
                $query = $query->whereNot( 'status', 2 );


            $query = $query->with( [
                'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                'etablissement:id,nom,logo,slug,is_verified,created_at',
                'statistiques' => function ( $q ) {
                    $q->selectRaw( '
                publication_id,
                SUM(click_contact) as click_contact,
                SUM(click_tele) as click_tele,
                SUM(click_tele_whatsapp) as click_tele_whatsapp,
                SUM(click_sauvgarder) as click_sauvgarder,
                SUM(click_partage) as click_partage,
                SUM(click_detail) as click_detail,
                SUM(vues) as vues
            ' )->groupBy( 'publication_id' );
                }
            ] );
        } else {
            $entity = Etablissement::find( $id );
            if (!$entity) {
                return response()->json( ['message' => 'Etablissement not found.'], 404 );
            }
            $query = $entity->publications();
            $query->where( function ( $q ) {
                $q->whereNull( 'is_marketplace' )->orWhere( 'is_marketplace', '!=', 1 );
            } );

            if ($isVisitor)
                $query = $query->where( 'status', 1 );
            else
                $query = $query->whereNot( 'status', 2 );

            $query = $query->with( [
                'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                'etablissement:id,nom,logo,slug,is_verified,created_at',
                'statistiques' => function ( $q ) {
                    $q->selectRaw( '
                publication_id,
                SUM(click_contact) as click_contact,
                SUM(click_tele) as click_tele,
                SUM(click_tele_whatsapp) as click_tele_whatsapp,
                SUM(click_sauvgarder) as click_sauvgarder,
                SUM(click_partage) as click_partage,
                SUM(click_detail) as click_detail,
                SUM(vues) as vues
            ' )->groupBy( 'publication_id' );
                }
            ] );
        }

        // Apply justActive filter if passed
        if ($justActive) {
            $query->where( 'status', '1' );
        }

        // Apply filters if provided
        if ($filters) {
            if (isset( $filters['type'] )) {
                if (isset( $filters['type']['image'] ) && isset( $filters['type']['video'] )) {
                    if ($filters['type']['image'] && !$filters['type']['video']) {
                        $query->whereNull( 'video_compresse' );
                    } elseif (!$filters['type']['image'] && $filters['type']['video']) {
                        $query->whereNotNull( 'video_compresse' );
                    }
                }

                if (isset( $filters['type']['boosted'] ) && $filters['type']['boosted']) {
                    $query->where( 'status', '1' );
                }

                if (isset( $filters['type']['active'] ) && isset( $filters['type']['inactive'] )) {
                    if ($filters['type']['active'] && !$filters['type']['inactive']) {
                        $query->where( 'status', '1' );
                    } elseif (!$filters['type']['active'] && $filters['type']['inactive']) {
                        $query->where( 'status', '0' );
                    }
                }
            }

            if (isset( $filters['sort'] )) {
                switch ($filters['sort']) {
                    case 'recent':
                        $query->orderBy( 'updated_at', 'desc' )->orderBy( 'created_at', 'desc' );
                        break;
                    case 'oldest':
                        $query->orderBy( 'updated_at', 'asc' )->orderBy( 'created_at', 'asc' );
                        break;
                    case 'mostViewed':
                        $query->leftJoin( DB::raw( '(
                            SELECT publication_id, SUM(vues) as total_views
                            FROM statistiques
                            GROUP BY publication_id
                        ) as stats' ), function ( $join ) {
                            $join->on( 'publications_flink.id', '=', 'stats.publication_id' );
                        } )
                            ->orderByRaw( 'CAST(COALESCE(stats.total_views, 0) AS UNSIGNED) DESC' )
                            ->orderBy( 'publications_flink.updated_at', 'desc' );
                        break;
                    case 'mostSaved':
                        $query->orderBy( 'click_sauvgarder', 'desc' );
                        break;
                    case 'price_ascending':
                        $query->orderBy( 'prix', 'asc' )->orderByRaw( 'COALESCE(prixPromo, prix) ASC' );
                        break;
                    case 'price_descending':
                        $query->orderBy( 'prix', 'desc' )->orderByRaw( 'COALESCE(prixPromo, prix) DESC' );
                        break;
                }
            } else {
                $query->orderBy( 'updated_at', 'desc' )->orderBy( 'created_at', 'desc' );
            }
        } else {
            $query->orderBy( 'updated_at', 'desc' )->orderBy( 'created_at', 'desc' );
        }

        $query->select( 'publications_flink.*' );

        $query->whereIn( "status", [0, 1] )->with( [
            'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
            'etablissement:id,nom,logo,slug,is_verified,created_at',
            'statistiques' => function ( $q ) {
                $q->selectRaw( '
            publication_id,
            SUM(click_contact) as click_contact,
            SUM(click_tele) as click_tele,
            SUM(click_tele_whatsapp) as click_tele_whatsapp,
            SUM(click_sauvgarder) as click_sauvgarder,
            SUM(click_partage) as click_partage,
            SUM(click_detail) as click_detail,
            SUM(vues) as vues
        ' )->groupBy( 'publication_id' );
            }
        ] );

        $publications = $query->paginate( $perPage );
        $publications->setCollection(
            $publications->getCollection()->map( function ( $publication ) {
                if (method_exists( $publication, 'makeVisible' )) {
                    $publication->makeVisible( ['catalogue_id'] );
                }
                return $publication;
            } )
        );

        return response()->json( ['data' => $publications] );
    }





    public function getBoutiquePosts( Request $request )
    {
        // Log::info("Call to getBoutiquePosts ############# : ". $request->ip());
        $request->validate( [
            'id' => 'required|integer',
            'type' => 'required|in:user,etablissement',
            // 'isVisitor' => 'required|boolean',
            'page' => 'sometimes|integer|min:1',
            'filters' => 'sometimes|array',
            'filters.type.image' => 'sometimes|boolean',
            'filters.type.video' => 'sometimes|boolean',
            'filters.type.boosted' => 'sometimes|boolean',
            'filters.type.active' => 'sometimes|boolean',
            'filters.type.inactive' => 'sometimes|boolean',
            'filters.sort' => 'sometimes|in:recent,oldest,mostViewed,mostSaved,price_ascending,price_descending',
        ] );

        $id = $request->id;
        $type = $request->type;
        $isVisitor = $request->isVisitor;
        $filters = $request->filters ?? null;
        $perPage = 20;

        // Base query - ensure we're using the correct table name
        if ($type === 'user') {
            $entity = User::find( $id );
            if (!$entity) {
                return response()->json( ['message' => 'User not found.'], 404 );
            }
            // Note: Using the actual table name publications_flink
            $query = $entity->publications()->whereNull( 'etab_id' )->where( 'is_marketplace', 1 );

            if ($isVisitor)
                $query = $query->where( 'status', 1 );
            else
                $query = $query->whereNot( 'status', 2 );

            $query = $query->with( [
                'statistiques' => function ( $q ) {
                    $q->selectRaw( '
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
                }
            ] );

        } else {
            $entity = Etablissement::find( $id );
            if (!$entity) {
                return response()->json( ['message' => 'Etablissement not found.'], 404 );
            }
            // Using the relation as defined in your model
            $query = $entity->publications()->where( 'is_marketplace', 1 );

            if ($isVisitor)
                $query = $query->where( 'status', 1 );
            else
                $query = $query->whereNot( 'status', 2 );

            $query = $query->with( [
                'statistiques' => function ( $q ) {
                    $q->selectRaw( '
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
                }
            ] );
        }

        // Apply filters if provided
        if ($filters) {
            // Media type filters
            if (isset( $filters['type'] )) {
                // Image/Video filtering
                if (isset( $filters['type']['image'] ) && isset( $filters['type']['video'] )) {
                    if ($filters['type']['image'] && !$filters['type']['video']) {
                        $query->whereNull( 'video_compresse' ); // Only images
                    } elseif (!$filters['type']['image'] && $filters['type']['video']) {
                        $query->whereNotNull( 'video_compresse' ); // Only videos
                    }
                }

                // Boosted status filtering
                if (isset( $filters['type']['boosted'] ) && $filters['type']['boosted']) {
                    $query->where( 'status', '1' );
                }

                // Active/Inactive filtering
                if (isset( $filters['type']['active'] ) && isset( $filters['type']['inactive'] )) {
                    if ($filters['type']['active'] && !$filters['type']['inactive']) {
                        $query->where( 'status', '1' );
                    } elseif (!$filters['type']['active'] && $filters['type']['inactive']) {
                        $query->where( 'status', '0' );
                    }
                }
            }

            // Sorting
            if (isset( $filters['sort'] )) {
                switch ($filters['sort']) {
                    case 'recent':
                        $query->orderBy( 'updated_at', 'desc' )
                            ->orderBy( 'created_at', 'desc' );
                        break;

                    case 'oldest':
                        $query->orderBy( 'updated_at', 'asc' )
                            ->orderBy( 'created_at', 'asc' );
                        break;

                    case 'mostViewed':
                        $query->leftJoin( DB::raw( '(
                                SELECT publication_id, SUM(vues) as total_views
                                FROM statistiques
                                GROUP BY publication_id
                            ) as stats' ), function ( $join ) {
                            $join->on( 'publications_flink.id', '=', 'stats.publication_id' );
                        } )
                            ->orderByRaw( 'CAST(COALESCE(stats.total_views, 0) AS UNSIGNED) DESC' )
                            ->orderBy( 'publications_flink.updated_at', 'desc' );
                        break;

                    case 'mostSaved':
                        $query->orderBy( 'click_sauvgarder', 'desc' );
                        break;

                    case 'price_ascending':
                        $query->orderBy( 'prix', 'asc' )
                            ->orderByRaw( 'COALESCE(prixPromo, prix) ASC' );
                        break;

                    case 'price_descending':
                        $query->orderBy( 'prix', 'desc' )
                            ->orderByRaw( 'COALESCE(prixPromo, prix) DESC' );
                        break;
                }
            } else {
                // Default sorting
                $query->orderBy( 'updated_at', 'desc' )
                    ->orderBy( 'created_at', 'desc' );
            }
        } else {
            // Default sorting
            $query->orderBy( 'updated_at', 'desc' )
                ->orderBy( 'created_at', 'desc' );
        }

        // Select specific columns to prevent issues with JOIN
        $query->select( 'publications_flink.*' );

        // Load statistics relationship for all queries
        $query->with( [
            'statistiques' => function ( $q ) {
                $q->selectRaw( '
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
            }
        ] );

        // Paginate the results
        $publications = $query->paginate( $perPage );
        $publications->setCollection(
            $publications->getCollection()->map( function ( $publication ) {
                if (method_exists( $publication, 'makeVisible' )) {
                    $publication->makeVisible( ['catalogue_id'] );
                }
                return $publication;
            } )
        );

        return response()->json( ['data' => $publications] );
    }


    public function getSavedPosts( Request $request )
    {
        $request->validate( [
            'id' => 'required|integer',
            'type' => 'required|in:user,etablissement',
            'page' => 'sometimes|integer|min:1',
            'filters' => 'sometimes|array',
            'filters.type.image' => 'sometimes|boolean',
            'filters.type.video' => 'sometimes|boolean',
            'filters.type.boosted' => 'sometimes|boolean',
            'filters.type.active' => 'sometimes|boolean',
            'filters.type.inactive' => 'sometimes|boolean',
            'filters.sort' => 'sometimes|in:recent,oldest,mostViewed,mostSaved,price_ascending,price_descending',
        ] );

        $id = $request->id;
        $type = $request->type;
        $filters = $request->filters ?? null;
        $perPage = 20;

        $entity = ( $type === 'user' ) ? User::find( $id ) : Etablissement::find( $id );
        if (!$entity) {
            return response()->json( ['message' => ucfirst( $type ) . ' not found.'], 404 );
        }

        $query = $entity->publications_sauvgarde()->where( "status", "1" )->with( [
            'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
            'etablissement:id,nom,logo,slug,is_verified,created_at',
            'statistiques' => function ( $q ) {
                $q->selectRaw( '
            publication_id,
            SUM(click_contact) as click_contact,
            SUM(click_tele) as click_tele,
            SUM(click_tele_whatsapp) as click_tele_whatsapp,
            SUM(click_sauvgarder) as click_sauvgarder,
            SUM(click_partage) as click_partage,
            SUM(click_detail) as click_detail,
            SUM(vues) as vues
        ' )->groupBy( 'publication_id' );
            }
        ] );

        // Apply filters if provided
        if ($filters) {
            if (isset( $filters['type'] )) {
                if (isset( $filters['type']['image'] ) && isset( $filters['type']['video'] )) {
                    if ($filters['type']['image'] && !$filters['type']['video']) {
                        $query->whereNull( 'video_compresse' );
                    } elseif (!$filters['type']['image'] && $filters['type']['video']) {
                        $query->whereNotNull( 'video_compresse' );
                    }
                }
                if (isset( $filters['type']['boosted'] ) && $filters['type']['boosted']) {
                    $query->where( 'status', '1' );
                }
                if (isset( $filters['type']['active'] ) && isset( $filters['type']['inactive'] )) {
                    if ($filters['type']['active'] && !$filters['type']['inactive']) {
                        $query->where( 'status', '1' );
                    } elseif (!$filters['type']['active'] && $filters['type']['inactive']) {
                        $query->where( 'status', '0' );
                    }
                }
            }

            if (isset( $filters['sort'] )) {
                switch ($filters['sort']) {
                    case 'recent':
                        $query->orderBy( 'updated_at', 'desc' )->orderBy( 'created_at', 'desc' );
                        break;
                    case 'oldest':
                        $query->orderBy( 'updated_at', 'asc' )->orderBy( 'created_at', 'asc' );
                        break;
                    case 'mostViewed':
                        $query->leftJoin( DB::raw( '(
                            SELECT publication_id, SUM(vues) as total_views
                            FROM statistiques
                            GROUP BY publication_id
                        ) as stats' ), function ( $join ) {
                            $join->on( 'publications_flink.id', '=', 'stats.publication_id' );
                        } )
                            ->orderByRaw( 'CAST(COALESCE(stats.total_views, 0) AS UNSIGNED) DESC' )
                            ->orderBy( 'publications_flink.updated_at', 'desc' );
                        break;
                    case 'mostSaved':
                        $query->orderBy( 'click_sauvgarder', 'desc' );
                        break;
                    case 'price_ascending':
                        $query->orderBy( 'prix', 'asc' )->orderByRaw( 'COALESCE(prixPromo, prix) ASC' );
                        break;
                    case 'price_descending':
                        $query->orderBy( 'prix', 'desc' )->orderByRaw( 'COALESCE(prixPromo, prix) DESC' );
                        break;
                }
            } else {
                $query->orderBy( 'updated_at', 'desc' )->orderBy( 'created_at', 'desc' );
            }
        } else {
            $query->orderBy( 'updated_at', 'desc' )->orderBy( 'created_at', 'desc' );
        }

        $query->select( 'publications_flink.*' );

        $savedPosts = $query->paginate( $perPage );
        $savedPosts->setCollection(
            $savedPosts->getCollection()->map( function ( $publication ) {
                if (method_exists( $publication, 'makeVisible' )) {
                    $publication->makeVisible( ['catalogue_id'] );
                }
                return $publication;
            } )
        );
        return response()->json( ['data' => $savedPosts] );
    }




    public function getRepostedPosts( Request $request )
    {
        $request->validate( [
            'id' => 'required|integer',
            'type' => 'required|in:user,etablissement',
            'page' => 'sometimes|integer|min:1',
            'isVisitor' => 'sometimes|boolean',
        ] );

        $id = $request->id;
        $type = $request->type;
        $isVisitor = $request->isVisitor ?? true;
        $perPage = 20;

        // Build publications list by joining reposts to preserve order and paginate results
        $query = \App\Models\Publication::query()
            ->join( 'publications_reposted', 'publications_reposted.publication_id', '=', 'publications_flink.id' )
            ->when( $type === 'user', function ( $q ) use ( $id ) {
                $q->where( 'publications_reposted.user_id', $id );
            }, function ( $q ) use ( $id ) {
                $q->where( 'publications_reposted.etab_id', $id );
            } )
            ->when( $isVisitor, function ( $q ) {
                $q->where( 'publications_flink.status', 1 );
            } )
            ->select( 'publications_flink.*', 'publications_reposted.created_at as reposted_at' )
            ->orderByDesc( 'publications_reposted.created_at' )
            ->with( [
                'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                'etablissement:id,nom,logo,slug,is_verified,created_at',
                'statistiques' => function ( $q ) {
                    $q->selectRaw( '
                    publication_id,
                    SUM(click_contact) as click_contact,
                    SUM(click_tele) as click_tele,
                    SUM(click_tele_whatsapp) as click_tele_whatsapp,
                    SUM(click_sauvgarder) as click_sauvgarder,
                    SUM(click_partage) as click_partage,
                    SUM(click_detail) as click_detail,
                    SUM(vues) as vues
                ' )->groupBy( 'publication_id' );
                },
            ] );

        $paginated = $query->paginate( $perPage );
        $paginated->setCollection(
            $paginated->getCollection()->map( function ( $publication ) {
                if (method_exists( $publication, 'makeVisible' )) {
                    $publication->makeVisible( ['catalogue_id'] );
                }
                return $publication;
            } )
        );

        // Attach repost_user (the owner who did the repost)
        $paginated->setCollection(
            $paginated->getCollection()->map( function ( $publication ) use ( $type, $id ) {
                try {
                    if ($type === 'user') {
                        $u = User::select( 'id', 'slug', 'first_name', 'last_name', 'avatar' )->find( $id );
                        if ($u) {
                            $publication->repost_user = [
                                'type' => 'user',
                                'id' => $u->id,
                                'slug' => $u->slug,
                                'name' => trim( ( $u->first_name ?? '' ) . ' ' . ( $u->last_name ?? '' ) ),
                                'avatar' => $u->avatar,
                                'reposted_at' => (string) ( $publication->reposted_at ?? $publication->created_at ),
                            ];
                        }
                    } else {
                        $e = Etablissement::select( 'id', 'slug', 'nom', 'logo' )->find( $id );
                        if ($e) {
                            $publication->repost_user = [
                                'type' => 'etablissement',
                                'id' => $e->id,
                                'slug' => $e->slug,
                                'name' => $e->nom,
                                'logo' => $e->logo,
                                'reposted_at' => (string) ( $publication->reposted_at ?? $publication->created_at ),
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                }
                return $publication;
            } )
        );

        return response()->json( ['data' => $paginated] );
    }

    // DELETE POST 
    public function deletePublication( Request $request )
    {
        // Check if user is authenticated
        $user = auth()->user();

        if (!$user) {
            return response()->json( ['message' => 'Unauthorized.'], 401 );
        }

        // Validate request input
        $request->validate( [
            'id' => 'required|integer',
            'profileId' => 'required|integer',
            'type' => 'required|in:user,etablissement',
        ] );

        if ($request->type === 'user') {
            $entity = User::find( $request->profileId );
            $publication = $entity->publications()
                ->whereNull( 'etab_id' )
                ->where( 'id', $request->id )
                ->first();
        } elseif ($request->type === 'etablissement') {
            $entity = Etablissement::find( $request->profileId );
            $publication = $entity->publications()
                ->where( 'id', $request->id )
                ->first();
        }

        if (!$publication) {
            return response()->json( ['message' => 'Publication not found or you do not have permission to modify it.'], 404 );
        }

        // Update the publication status to 2
        $publication->update( ['status' => "2"] );

        return response()->json( [
            'message' => 'Publication status updated to 2 successfully.',
        ] );
    }















    // FOLLOWERS FOLLOWING


    public function getFollowers( Request $request )
    {
        $request->validate( [
            'id' => 'required|integer',
            'type' => 'required|in:user,etablissement',
            'page' => 'integer|min:1',
        ] );

        $id = $request->id;
        $type = $request->type;
        $perPage = 10;

        $followers = collect();

        if ($type === 'user') {
            $entity = User::findOrFail( $id );
            $followerUsers = $entity->followersUsers()->get();
            $followerEtablissements = $entity->followersEtablissements()->get();
        } else {
            $entity = Etablissement::findOrFail( $id );
            $followerUsers = $entity->followersUsers()->get();
            $followerEtablissements = $entity->followersEtablissements()->get();
        }

        $followers = $followers->merge( $followerUsers )->merge( $followerEtablissements );

        // Ensure sorting by pivot.created_at in descending order (most recent first)
        $followers = $followers->sortByDesc( fn( $item ) => Carbon::parse( $item->pivot->created_at ) );

        $page = request()->input( 'page', 1 );
        $total = $followers->count();
        $followersItems = $followers->slice( ( $page - 1 ) * $perPage, $perPage )->values();

        $followers = new LengthAwarePaginator(
            $followersItems,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return response()->json( [
            'followers' => $followers
        ] );
    }


    public function getFollowing( Request $request )
    {
        $request->validate( [
            'id' => 'required|integer',
            'type' => 'required|in:user,etablissement',
            'page' => 'integer|min:1',
        ] );

        $id = $request->id;
        $type = $request->type;
        $perPage = 10;

        $following = collect();

        if ($type === 'user') {
            $entity = User::findOrFail( $id );
            $followingUsers = $entity->followingUsers()->get();
            $followingEtablissements = $entity->followingEtablissements()->get();
        } else {
            $entity = Etablissement::findOrFail( $id );
            $followingUsers = $entity->followingUsers()->get();
            $followingEtablissements = $entity->followingEtablissements()->get();
        }

        $following = $following->merge( $followingEtablissements )->merge( $followingUsers );

        // Sort by pivot.created_at in descending order (most recent first)
        $following = $following->sortByDesc( fn( $item ) => Carbon::parse( $item->pivot->created_at ) );

        $page = request()->input( 'page', 1 );
        $total = $following->count();
        $followingItems = $following->slice( ( $page - 1 ) * $perPage, $perPage )->values();

        $following = new LengthAwarePaginator(
            $followingItems,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return response()->json( [
            'following' => $following
        ] );
    }

    public function getSuggestions( Request $request, $profileSlug )
    {
        $page = $request->get( 'page', 1 );
        $reloaData = $request->get( 'reloadata' );

        // Get profile data if needed with minimal data
        $authUser = null;
        $authUserTags = [];

        if ($profileSlug) {
            $authUser = Cache::remember( "profile_{$profileSlug}", now()->addHours( 1 ), function () use ($profileSlug) {
                return User::where( 'slug', $profileSlug )
                    ->select( 'id' )
                    ->with( [
                        'tags_filters' => function ( $q ) {
                            $q->where( 'status', 1 )
                                ->orderByPivot( 'updated_at', 'DESC' )
                                ->limit( 20 );
                        }
                    ] )
                    ->first() ??
                    Etablissement::where( 'slug', $profileSlug )
                        ->select( 'id' )
                        ->with( [
                            'tags_filters' => function ( $q ) {
                                $q->where( 'status', 1 )
                                    ->orderByPivot( 'updated_at', 'DESC' )
                                    ->limit( 20 );
                            }
                        ] )
                        ->first();
            } );

            if (!$authUser) {
                return response()->json( ['data' => [], 'message' => 'Profile not found'] );
            }

            $authUserTags = $authUser->tags_filters->pluck( 'id' )->toArray();
        }

        $usersQuery = User::select(
            'id',
            'first_name',
            'last_name',
            'avatar',
            'slug',
            'is_verified',
            'score',
            'bio',
            DB::raw( "'user' as type" )
        )
            ->whereNot( 'slug', $profileSlug )
            // User is NOT followed by $authUser in followersUsers
            ->whereDoesntHave( 'followersUsers', function ( $q ) use ( $authUser ) {
                $q->where( 'users.id', $authUser->id );
            } )
            // AND Etablissement is NOT followed by $authUser in followersEtablissements
            ->whereDoesntHave( 'followersEtablissements', function ( $q ) use ( $authUser ) {
                $q->where( 'etablissements.id', $authUser->id ); // Assuming the pivot table references users.id
            } )
            ->with( [
                'followersUsers:id,slug',
                'followersEtablissements:id,slug',
                'followingUsers:id,slug',
                'followingEtablissements:id,slug',
                'tags_filters:id,slug'
            ] );

        $etablissementsQuery = Etablissement::select(
            'id',
            'nom',
            'logo',
            'slug',
            'is_verified',
            'score',
            'bio',
            DB::raw( "'etablissement' as type" )
        )
            ->whereNot( 'slug', $profileSlug )
            // User is NOT followed by $authUser in followersUsers
            ->whereDoesntHave( 'followersUsers', function ( $q ) use ( $authUser ) {
                $q->where( 'users.id', $authUser->id );
            } )
            // AND Etablissement is NOT followed by $authUser in followersEtablissements
            ->whereDoesntHave( 'followersEtablissements', function ( $q ) use ( $authUser ) {
                $q->where( 'etablissements.id', $authUser->id ); // Assuming the pivot table references users.id
            } )
            ->with( [
                'followersEtablissements:id,slug',
                'followersUsers:id,slug',
                'followingUsers:id,slug',
                'followingEtablissements:id,slug',
                'tags_filters:id,slug'
            ] );

        // $results = $usersQuery->get();
        // return [
        //     "data" => $results,
        //     "total" => $results->count() // Counts the collection, no additional query
        // ];

        $users = $usersQuery->get();
        $etablissements = $etablissementsQuery->get();

        $combined = collect()
            ->merge( $users->map( function ( $item ) {
                $item->type = 'user';
                $item->name = $item->first_name . ' ' . $item->last_name;
                return $item;
            } ) )
            ->merge( $etablissements->map( function ( $item ) {
                $item->type = 'etablissement';
                $item->name = $item->nom;
                return $item;
            } ) );

        $authUserId = $authUser ? $authUser->id : null;
        $authUserType = $authUser ? ( $authUser instanceof User ? 'user' : 'etablissement' ) : null;

        $scoredResults = $combined->map( function ( $profile ) use ( $authUserId, $authUserType, $authUserTags ) {
            $isFollowingYou = false;
            $commonFollowers = 0;
            $commonTagsCount = 0;
            $priorityLabel = 'no_relationship';

            if ($authUserId) {
                $youAreFollowing = $profile->followersUsers->contains( 'id', $authUserId ) ||
                    $profile->followersEtablissements->contains( 'id', $authUserId );
                $isFollowingYou = $profile->followingUsers->contains( 'id', $authUserId ) ||
                    $profile->followingEtablissements->contains( 'id', $authUserId );

                if (!empty( $authUserTags ) && $profile->tags_filters->isNotEmpty()) {
                    $profileTags = $profile->tags_filters->pluck( 'id' )->toArray();
                    $commonTagsCount = count( array_intersect( $authUserTags, $profileTags ) );
                }

                if ($isFollowingYou && !$youAreFollowing) {
                    $priorityScore = 4;
                    $priorityLabel = 'follows_you';
                } elseif ($commonTagsCount > 0 && !$youAreFollowing) {
                    $priorityScore = 3;
                    $priorityLabel = 'common_tags';
                } elseif ($youAreFollowing) {
                    $priorityScore = 0;
                    $priorityLabel = 'following';
                } else {
                    $priorityScore = 1;
                }
            } else {
                $priorityScore = 1;
            }

            return [
                ...$profile->toArray(),
                'priority_score' => $priorityScore,
                'priority_label' => $priorityLabel,
                'is_following_you' => $isFollowingYou,
                'you_are_following' => $youAreFollowing,
                'common_tags_count' => $commonTagsCount,
                'score' => $profile->score
            ];
        } );

        // Sorting logic
        $orderOptions = ["priority_score", "common_tags_count"];

        // Use cache instead of session for API requests
        $cacheKey = "suggestions_sort_{$profileSlug}";

        if (( $reloaData || $reloaData == "true" ) && $page == 1 || !Cache::has( $cacheKey )) {
            $selectedOrder = $orderOptions[array_rand( $orderOptions )];
            Cache::put( $cacheKey, $selectedOrder, now()->addHours( 1 ) ); // Store for 1 hour
        } else {
            $selectedOrder = Cache::get( $cacheKey );
        }

        $sortedResults = $scoredResults->sortByDesc( function ( $item ) use ( $selectedOrder ) {
            return [$item[$selectedOrder], $item['score']];
        } )->values();

        $perPage = 10;

        $currentPageItems = $sortedResults
            ->forPage( $page, $perPage )
            ->values()
            ->all();

        $paginator = new LengthAwarePaginator(
            $currentPageItems,
            $sortedResults->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );

        return $paginator;
    }

    /**
     * Process individual tag name (similar to CreatePublicationController)
     */
    private function processTagName( $name, &$tagIds, &$tagNames )
    {
        try {
            Log::info( 'EditProfile - processTagName called', [
                'name' => $name,
                'current_tag_ids' => $tagIds,
                'current_tag_names' => $tagNames,
            ] );

            $slug = $this->generateSlug( $name );
            Log::info( 'EditProfile - Generated slug', [
                'name' => $name,
                'slug' => $slug,
            ] );

            $tag = Tags::where( 'name', $name )->first();
            Log::info( 'EditProfile - Tag lookup by name', [
                'name' => $name,
                'tag_found' => $tag !== null,
                'tag_id' => $tag ? $tag->id : null,
            ] );

            if (!$tag) {
                $existingSlug = Tags::where( 'slug', $slug )->first();
                Log::info( 'EditProfile - Checking existing slug', [
                    'slug' => $slug,
                    'existing_slug_found' => $existingSlug !== null,
                ] );
                if ($existingSlug) {
                    $slug = $this->generateUniqueSlug( $slug );
                    Log::info( 'EditProfile - Generated unique slug', [
                        'original_slug' => $this->generateSlug( $name ),
                        'unique_slug' => $slug,
                    ] );
                }

                // Create new tag
                $tag = new Tags();
                $tag->name = $name;
                $tag->slug = $slug;
                $tag->compteur_publications = 1;
                $tag->save();
                Log::info( 'EditProfile - Created new tag', [
                    'tag_id' => $tag->id,
                    'tag_name' => $tag->name,
                    'tag_slug' => $tag->slug,
                ] );
            } else {
                $tag->compteur_publications += 1;
                $tag->save();
                Log::info( 'EditProfile - Updated existing tag counter', [
                    'tag_id' => $tag->id,
                    'tag_name' => $tag->name,
                    'new_counter' => $tag->compteur_publications,
                ] );
            }

            if (!in_array( $tag->id, $tagIds )) {
                $tagIds[] = $tag->id;
                $tagNames[] = $tag->name;
                Log::info( 'EditProfile - Added tag to arrays', [
                    'tag_id' => $tag->id,
                    'tag_name' => $tag->name,
                    'updated_tag_ids' => $tagIds,
                    'updated_tag_names' => $tagNames,
                ] );
            } else {
                Log::info( 'EditProfile - Tag already in arrays', [
                    'tag_id' => $tag->id,
                ] );
            }
        } catch (\Exception $e) {
            Log::error( 'EditProfile - Error in processTagName', [
                'name' => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
            throw $e;
        }
    }

    /**
     * Process tag names array or string (similar to CreatePublicationController)
     */
    private function processTagNames( $tagNames ): array
    {
        if (empty( $tagNames )) {
            return [];
        }
        if (is_array( $tagNames )) {
            return array_unique( array_filter( $tagNames ) );
        }
        $tags = explode( ',', $tagNames );
        return array_unique( array_map( 'trim', array_filter( $tags ) ) );
    }

    /**
     * Generate a slug from a string (similar to CreatePublicationController)
     */
    private function generateSlug( $name ): string
    {
        return Str::slug( $name, '-' );
    }

    /**
     * Generate a unique slug for tags (similar to CreatePublicationController)
     */
    private function generateUniqueSlug( $slug ): string
    {
        $originalSlug = $slug;
        $count = 1;

        while (Tags::where( 'slug', $slug )->exists()) {
            $slug = Str::slug( $originalSlug . '-' . $count );
            $count++;
        }

        return $slug;
    }

}