<?php

namespace App\Http\Controllers\publication\sauvgarder;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Publication;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Statistique;
use App\Services\NotificationService;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
 
class SauvgardeController extends Controller
{
    /**
     * Update Firebase Realtime Database with save count changes
     */
    private function updateFirebaseSaveCount($publicationId, $incrementBy) {
        try {
            // Check if Firebase service account file exists
            $serviceAccountPath = storage_path('app/firebase-service-account.json');
            if (!file_exists($serviceAccountPath)) {
                return null;
            }

            // Initialize Firebase
            $serviceAccount = ServiceAccount::fromJsonFile($serviceAccountPath);
            $firebase = (new Factory)
                ->withServiceAccount($serviceAccount)
                ->withDatabaseUri('https://notifications-fcm-30115-default-rtdb.firebaseio.com/')
                ->create();

            $database = $firebase->getDatabase();
            $reference = $database->getReference("publications/{$publicationId}/saveCount");
            
            // Get current count and update
            $currentCount = $reference->getValue() ?? 0;
            $newCount = $currentCount + $incrementBy;
            $reference->set($newCount);
            
            return $newCount;
        } catch (\Exception $e) {
            \Log::error('Firebase save count update failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Handles saving and unsaving publications for users and establishments.
     */
    public function Sauvgarde(Request $request) {
        try {
            // Removed non-critical info logs

            // Extract required inputs from the request
            $userId = $request->input('userId');
            $etabId = $request->input('etabId') ?? null;
            $publicationId = $request->input('publicationId');
            $mode = $request->input('mode');

            $publication = Publication::find($publicationId);

            // Validate input parameters - either userId or etabId must be present
            if (!$publication || (!$userId && !$etabId)) {
                \Log::error('Missing required parameters', [
                    'publication_found' => $publication ? 'yes' : 'no',
                    'userId' => $userId,
                    'etabId' => $etabId,
                    'publicationId' => $publicationId
                ]);
                return response()->json(['error' => 'Missing required parameters'], 400);
            }

            // Removed non-critical info logs

            if ($mode === "save") {
                // Removed non-critical info logs
                
                // Save the publication for the user or establishment
                if ($etabId && !empty($etabId)) {
                    $etablissement = Etablissement::find($etabId);

                    if (!$etablissement) {
                        \Log::error('Establishment not found', ['etabId' => $etabId]);
                        return response()->json(['error' => 'Establishment not found'], 404);
                    }
                    
                    // Removed non-critical info logs
                    try {
                    $etablissement->publications_sauvgarde()->attach($publicationId);
                    } catch (\Illuminate\Database\QueryException $e) {
                        if ($e->getCode() == 23000 && str_contains($e->getMessage(), 'Duplicate entry')) {
                            // Silently ignore duplicate save attempts
                        } else {
                            throw $e; // Re-throw if it's not a duplicate entry error
                        }
                    }

                    // // check if there is an existing notification for this publication and establishment
                    // $profile = $publication->etab_id ? $publication->etablissement : $publication->user;

                    // $profileType = $publication->etab_id ? "App\\Models\\Etablissement" : "App\\Models\\User";

                    // $twentyFourHoursAgo = now()->subHours(24);

                    // $notifications = Notification::where('type', 'App\\Notifications\\PublicationSauvgardedNotification')
                    //     ->where('notifiable_id', $profile->id)
                    //     ->where('notifiable_type', $profileType)
                    //     ->where('created_at', '>=', $twentyFourHoursAgo)
                    //     ->whereJsonContains('data->publication_id', $publicationId)
                    //     ->whereJsonContains('data->profile->slug', $etablissement->slug)
                    //     ->get();
                    
                
                    // if($notifications->isEmpty()) {
                    //     app(NotificationService::class)->sendPublicationSauvgardedNotification($publication, $etablissement);
                    // }

                    try {
                    app(NotificationService::class)->sendPublicationSauvgardedNotification($publication, $etablissement);
                    } catch (\Exception $e) {
                        \Log::error('Failed to send save notification to establishment', ['error' => $e->getMessage(), 'publicationId' => $publicationId, 'etabId' => $etabId]);
                        // Continue with the save operation even if notification fails
                    }
                } elseif ($userId) {
                    $user = User::find($userId);
                    if (!$user) {
                        \Log::error('User not found', ['userId' => $userId]);
                        return response()->json(['error' => 'User not found'], 404);
                    }
                    // Removed non-critical info logs
                    try {
                    $user->publications_sauvgarde()->attach($publicationId);
                    } catch (\Illuminate\Database\QueryException $e) {
                        if ($e->getCode() == 23000 && str_contains($e->getMessage(), 'Duplicate entry')) {
                            // Silently ignore duplicate save attempts
                        } else {
                            throw $e; // Re-throw if it's not a duplicate entry error
                        }
                    }

                    // // check if there is an existing notification for this publication and establishment
                    // $profile = $publication->etab_id ? $publication->etablissement : $publication->user;

                    // $profileType = $publication->etab_id ? "App\\Models\\Etablissement" : "App\\Models\\User";

                    // $twentyFourHoursAgo = now()->subHours(24);

                    // $notifications = Notification::where('type', 'App\\Notifications\\PublicationSauvgardedNotification')
                    //     ->where('notifiable_id', $profile->id)
                    //     ->where('notifiable_type', $profileType)
                    //     ->where('created_at', '>=', $twentyFourHoursAgo)
                    //     ->whereJsonContains('data->publication_id', $publicationId)
                    //     ->whereJsonContains('data->profile->slug', $user->slug)
                    //     ->get();
                    
                
                    // if(empty($notifications)) {
                    //     app(NotificationService::class)->sendPublicationSauvgardedNotification($publication, $user);
                    // }
                    
                    try {
                    app(NotificationService::class)->sendPublicationSauvgardedNotification($publication, $user);
                    } catch (\Exception $e) {
                        \Log::error('Failed to send save notification', ['error' => $e->getMessage(), 'publicationId' => $publicationId, 'userId' => $userId]);
                        // Continue with the save operation even if notification fails
                    }
                }
                
                // Statistics tracking removed - only modifying sauvgarde_publications table

                // Get current saved count for this publication
                $savedCount = DB::table('sauvgarde_publications')->where('publication_id', $publicationId)->count();
                // Removed non-critical info logs

                // Update Firebase Realtime Database
                $this->updateFirebaseSaveCount($publicationId, 1);

                return response()->json(['message' => 'Publication saved successfully', "user_id" => $userId, "etab_id" => $etabId, "saved_count" => $savedCount], 200);
            } elseif ($mode === "unsave") {
                // Removed non-critical info logs
                
                // Remove the publication from the user's or establishment's saved list
                if ($etabId && !empty($etabId)) {
                    $etablissement = Etablissement::findOrFail($etabId);
                    // Removed non-critical info logs
                    $etablissement->publications_sauvgarde()->detach($publicationId);
                } elseif ($userId) {
                    $user = User::find($userId);
                    if (!$user) {
                        \Log::error('User not found for unsave', ['userId' => $userId]);
                        return response()->json(['error' => 'User not found'], 404);
                    }
                    // Removed non-critical info logs
                    $user->publications_sauvgarde()->detach($publicationId);
                } 
                // .elseif ($etabId) {
                //     $etablissement = Etablissement::findOrFail($etabId);
                //     $etablissement->publications_sauvgarde()->detach($publicationId);
                // }
                // Statistics tracking removed - only modifying sauvgarde_publications table

                // Get current saved count for this publication
                $savedCount = DB::table('sauvgarde_publications')->where('publication_id', $publicationId)->count();
                // Removed non-critical info logs

                // Update Firebase Realtime Database
                $this->updateFirebaseSaveCount($publicationId, -1);

                return response()->json(['message' => 'Publication unsaved successfully', "saved_count" => $savedCount], 200);
            } else {
                // Handle invalid mode
                return response()->json(['error' => 'Invalid mode'], 400);
            }
        } catch (\Throwable $th) {
            // Log the error with full details
            \Log::error('Sauvgarde operation failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'userId' => $request->input('userId'),
                'etabId' => $request->input('etabId'),
                'publicationId' => $request->input('publicationId'),
                'mode' => $request->input('mode')
            ]);
            
            // Return error response in case of exception
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

}
