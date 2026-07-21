<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Notification;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\Etablissement;
use App\Services\NotificationService;
use App\Models\Publication;
use Illuminate\Support\Facades\Storage;


class NotificationController extends Controller
{


    public function updateToken(Request $request) {
        // return $request->device_type;
        $request->validate([
            'token' => 'required|string',
            'slug' => 'required|string',
            'device_type' => 'required|string',
            'langue' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

        $profile = Etablissement::where("slug", $request->slug)->first();
        if(!$profile) {
            $profile = User::where("slug", $request->slug)->first();
        }
        
        if (!$profile) {
            return response()->json(['status' => 'error', 'message' => 'Profile not found']);
        }

        $profile->langue = $request->langue;
        $profile->save();


        // Check if token already exists
        $existingToken = $profile->notification_tokens()
                            ->where('token', $request->token)
                            ->first();

        if (!$existingToken) {
            if($profile->notification_tokens()->where('device_type', $request->device_type)->count() >= 3) {
                // Delete the oldest token if there are already 3 tokens
                $oldestToken = $profile->notification_tokens()->where('device_type', $request->device_type)->orderBy('created_at', 'asc')->first();
                $oldestToken->delete();
            }

            if($request->device_type == 'web' && $request->device_id) {
                $existingDeviceId = $profile->notification_tokens()
                                ->where('browser_key', $request->device_id)
                                ->first();
                if($existingDeviceId) {
                    // Update the token if the device_id already exists
                    $existingToken->token = $request->token;
                    $existingToken->device_type = $request->device_type; // Update device type if needed
                    $existingToken->save();
                } else {
                    // Add new token
                    $profile->notification_tokens()->create([
                        'token' => $request->token,
                        'device_type' => $request->device_type, // Default to 'web' if not provided
                        'browser_key' => $request->device_id,
                    ]);
                }
            } else if($request->device_type == 'android') {
                // Add new token without device_id
                $profile->notification_tokens()->create([
                    'token' => $request->token,
                    'device_type' => $request->device_type, // Default to 'web' if not provided
                ]);
            }
            
            
        }

        return response()->json(['status' => 'success', 'message' => 'Token updated successfully', 'device_id' => $request->device_id]);
    }

    public function DeclancheNotifications(Request $request) {
        $data = $request->validate([
            'type' => 'required|string',
            'publication_id' => 'required|integer',
        ]);
        
        if($data['type'] == 'publication_approved') {
            $publication = Publication::findOrFail($data['publication_id']);
            app(NotificationService::class)->sendNewPublicationNotification($publication);
        } else if($data['type'] == 'publication_rejected') {
            $publication = Publication::findOrFail($data['publication_id']);
            app(NotificationService::class)->sendPublicationRejectedNotification($publication);
        } else if($data['type'] == 'publication_deleted') {
            $publication = Publication::findOrFail($data['publication_id']);
            app(NotificationService::class)->sendPublicationDeletedNotification($publication);
        }


        // // Trouver l'utilisateur concerné
        // $user = User::findOrFail($data['user_id']);
        
        // // Envoyer la notification
        // $user->notify(new PublicationApproved($data['message']));
        
        return response()->json(['status' => 'success']);
    }

    // Get All Notifications
    public function getAllNotifications(Request $request) {
        $slug = $request->slug;

        $profile = Etablissement::where("slug", $slug)->first();
        $type = "App\\Models\\Etablissement";
        if(!$profile) {
            $profile = User::where("slug", $slug)->first();
            $type = "App\\Models\\User";
        }
        try {
            // $notifications = Notification::where('notifiable_type', $type)->where('notifiable_id', $profile->id)->orderBy('created_at', 'desc')->get();
            $notifications = Notification::where('notifiable_type', $type)
                ->where('notifiable_id', $profile->id)
                ->orderBy('created_at', 'desc')
                ->paginate(30)
                ->through(function ($notification) {
                    $timeAgo = $notification->created_at->diffForHumans();
                    $timeParts = explode(' ', $timeAgo);
                    
                    $unitMap = [
                        'second' => 'sec',
                        'minute' => 'min',
                        'min' => 'min', // Handles "mins" → "min"
                        'hour' => 'hr',
                        'day' => 'day',
                        'week' => 'wk',
                        'month' => 'mo',
                        'year' => 'yr',
                    ];

                    $value = $timeParts[0] === 'a' || $timeParts[0] === 'an' ? 1 : (int)$timeParts[0];
                    $baseUnit = rtrim(strtolower($timeParts[1]), 's'); // "mins" → "min"
                    $unit = $unitMap[$baseUnit] ?? $baseUnit; // Fallback to the base unit if not mapped

                    return [
                        'id' => $notification->id,
                        'data' => $notification->data,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at,
                        'time_ago' => $value,
                        'time_ago_type' => $unit,
                    ];
                }
            );

            return response()->json($notifications);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        // $notifications = Notification::all();
        // return response()->json($notifications);
    }

    // Get only publications notifications
    public function getPublicationNotifications(Request $request) {
        $slug = $request->slug;

        $profile = Etablissement::where("slug", $slug)->first();
        $type = "App\\Models\\Etablissement";
        if(!$profile) {
            $profile = User::where("slug", $slug)->first();
            $type = "App\\Models\\User";
        }
        try {
            $filterType = [
                "App\\Notifications\\NewPublicationNotificationFollowers",
                "App\\Notifications\\NewPublicationNotificationUser",
                "App\\Notifications\\PublicationPartageNotification",
                "App\\Notifications\\PublicationSauvgardedNotification",
                "App\\Notifications\\PublicationViewMilestoneNotification",
            ];

            $notifications = Notification::where('notifiable_type', $type)
                ->where('notifiable_id', $profile->id)
                ->whereIn('type', $filterType)
                ->orderBy('created_at', 'desc')
                ->paginate(30)
                ->through(function ($notification) {
                    $timeAgo = $notification->created_at->diffForHumans();
                    $timeParts = explode(' ', $timeAgo);
                    
                    $unitMap = [
                        'second' => 'sec',
                        'minute' => 'min',
                        'min' => 'min', // Handles "mins" → "min"
                        'hour' => 'hr',
                        'day' => 'day',
                        'week' => 'wk',
                        'month' => 'mo',
                        'year' => 'yr',
                    ];

                    $value = $timeParts[0] === 'a' || $timeParts[0] === 'an' ? 1 : (int)$timeParts[0];
                    $baseUnit = rtrim(strtolower($timeParts[1]), 's'); // "mins" → "min"
                    $unit = $unitMap[$baseUnit] ?? $baseUnit; // Fallback to the base unit if not mapped

                    return [
                        'id' => $notification->id,
                        'data' => $notification->data,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at,
                        'time_ago' => $value,
                        'time_ago_type' => $unit,
                    ];
                }
            );

            return response()->json($notifications);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        // $notifications = Notification::all();
        // return response()->json($notifications);
    }

    // Get only followers notifications
    public function getFollowerNotifications(Request $request) {
        $slug = $request->slug;

        $profile = Etablissement::where("slug", $slug)->first();
        $type = "App\\Models\\Etablissement";
        if(!$profile) {
            $profile = User::where("slug", $slug)->first();
            $type = "App\\Models\\User";
        }
        try {
            $filterType = [
                "App\\Notifications\\NewFollowerNotification",
            ];

            $notifications = Notification::where('notifiable_type', $type)
                ->where('notifiable_id', $profile->id)
                ->whereIn('type', $filterType)
                ->orderBy('created_at', 'desc')
                ->paginate(30)
                ->through(function ($notification) {
                    $timeAgo = $notification->created_at->diffForHumans();
                    $timeParts = explode(' ', $timeAgo);
                    
                    $unitMap = [
                        'second' => 'sec',
                        'minute' => 'min',
                        'min' => 'min', // Handles "mins" → "min"
                        'hour' => 'hr',
                        'day' => 'day',
                        'week' => 'wk',
                        'month' => 'mo',
                        'year' => 'yr',
                    ];

                    $value = $timeParts[0] === 'a' || $timeParts[0] === 'an' ? 1 : (int)$timeParts[0];
                    $baseUnit = rtrim(strtolower($timeParts[1]), 's'); // "mins" → "min"
                    $unit = $unitMap[$baseUnit] ?? $baseUnit; // Fallback to the base unit if not mapped

                    return [
                        'id' => $notification->id,
                        'data' => $notification->data,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at,
                        'time_ago' => $value,
                        'time_ago_type' => $unit,
                    ];
                }
            );

            return response()->json($notifications);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        // $notifications = Notification::all();
        // return response()->json($notifications);
    }

    // Get only systems notifications
    public function getSystemNotifications(Request $request) {
        $slug = $request->slug;

        $profile = Etablissement::where("slug", $slug)->first();
        $type = "App\\Models\\Etablissement";
        if(!$profile) {
            $profile = User::where("slug", $slug)->first();
            $type = "App\\Models\\User";
        }
        try {
            $filterType = [
                "App\\Notifications\\AccountCreatedNotification",
                "App\\Notifications\\PublicationRejectedNotification",
                "App\\Notifications\\PublicationDeletedNotification",
            ];

            $notifications = Notification::where('notifiable_type', $type)
                ->where('notifiable_id', $profile->id)
                ->whereIn('type', $filterType)
                ->orderBy('created_at', 'desc')
                ->paginate(30)
                ->through(function ($notification) {
                    $timeAgo = $notification->created_at->diffForHumans();
                    $timeParts = explode(' ', $timeAgo);
                    
                    $unitMap = [
                        'second' => 'sec',
                        'minute' => 'min',
                        'min' => 'min', // Handles "mins" → "min"
                        'hour' => 'hr',
                        'day' => 'day',
                        'week' => 'wk',
                        'month' => 'mo',
                        'year' => 'yr',
                    ];

                    $value = $timeParts[0] === 'a' || $timeParts[0] === 'an' ? 1 : (int)$timeParts[0];
                    $baseUnit = rtrim(strtolower($timeParts[1]), 's'); // "mins" → "min"
                    $unit = $unitMap[$baseUnit] ?? $baseUnit; // Fallback to the base unit if not mapped

                    return [
                        'id' => $notification->id,
                        'data' => $notification->data,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at,
                        'time_ago' => $value,
                        'time_ago_type' => $unit,
                    ];
                }
            );

            return response()->json($notifications);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        // $notifications = Notification::all();
        // return response()->json($notifications);
    }

    public function markAsRead(Request $request) {
        $validated = $request->validate([
            'id' => 'required|exists:notifications,id',
        ]);
        try {
            $notification = Notification::find($validated['id']);
            if(!$notification) {
                return response()->json(['error' => 'Notification not found'], 404);
            }
            $notification->read_at = now();
            $notification->save();

            return response()->json($notification);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        
    }

    public function markAsReadAll(Request $request) {
        $validated = $request->validate([
            'profile' => 'required|string',
        ]);
        try {
            $profileObject = User::where('slug', $validated['profile'])->first();
            $type = "App\\Models\\User";
            if (!$profileObject) {
                $profileObject = Etablissement::where('slug', $validated['profile'])->first();
                $type = "App\\Models\\Etablissement";
            }
            if(!$profileObject) {
                return response()->json(['error' => 'Profile not found'], 404);
            }

            $notifications = Notification::where('notifiable_id', $profileObject->id)->where('notifiable_type', $type)->update(['read_at' => now()]);

            return response()->json(['success' => 'Notifications marked as read'], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        
    }

    public function numberOfUnreadNotifications(Request $request) {
        $profile = $request->profile;
        $profileObject = User::where('slug', $profile)->first();
        $type = "App\\Models\\User";
        if (!$profileObject) {
            $profileObject = Etablissement::where('slug', $profile)->first();
            $type = "App\\Models\\Etablissement";
        }
        try {
            if(!$profileObject) {
                return response()->json(['error' => 'Profile not found'], 404);
            }

            $notificationsCount = Notification::where('notifiable_id', $profileObject->id)->where('notifiable_type', $type)
            ->where('read_at', null)->count();

            
            return response()->json($notificationsCount);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        
    }

    public function storeSystemNotification(Request $request) {
        // return $request->all();
        $user = \App\Models\User::find(7991);
        try {
            $validated = $request->validate([
                'profile' => 'required|string',
                'type' => 'required|in:system,marketig',
                'send_type' => 'required|in:push,email,both',
                'action_type' => 'required|in:link,btns',

                'title' => 'required|array',
                'title.ar' => 'required|string',
                'title.fr' => 'required|string',
                'title.en' => 'required|string',

                'message' => 'required|array',
                'message.ar' => 'required|string',
                'message.fr' => 'required|string',
                'message.en' => 'required|string',

                'btn1' => 'nullable|array',
                'btn1.name_ar' => 'nullable|string',
                'btn1.name_fr' => 'nullable|string',
                'btn1.name_en' => 'nullable|string',
                'btn1.url' => 'nullable|url',

                'btn2' => 'nullable|array',
                'btn2.name_ar' => 'nullable|string',
                'btn2.name_fr' => 'nullable|string',
                'btn2.name_en' => 'nullable|string',
                'btn2.url' => 'nullable|url',

                'image' => 'nullable|image|max:2048', // 2MB max
                'link' => 'nullable|url',
            ]);

            $imagePath = null;
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $ftpPath = 'Flink Files/system notifications images/' . $fileName; // Custom path
                Storage::disk('ftp')->put($ftpPath, file_get_contents($file));
                $imagePath = $ftpPath;
            }

            $profileObject = \App\Models\User::where('slug', $validated['profile'])->first();
            if (!$profileObject) {
                $profileObject = \App\Models\Etablissement::where('slug', $validated['profile'])->first();
            }

            if (!$profileObject) {
                return response()->json(['error' => 'Profile not found'], 404);
            }

            $link = $validated['action_type'] == 'link' && $validated['link'] ? $validated['link'] : null;
            $btn1 = $validated['action_type'] == 'btns' && $validated['btn1'] ? $validated['btn1'] : null;
            $btn2 = $validated['action_type'] == 'btns' && $validated['btn2'] ? $validated['btn2'] : null;

            app(NotificationService::class)->sendSystemNotification($validated['send_type'], $profileObject, $validated['type'], $validated['title'], $validated['message'], $imagePath, $link, $btn1, $btn2);

            return response()->json([ 
                'received_data' => $request->all(),
                'test' => 'validation bypassed',
                'image Url' => $imagePath
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        
    }

    /**
     * Retrieve all notifications for a specific user or establishment.
     *
     * @param Request $request
     * @param int $userEtabId
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    // public function getNotifications(Request $request, $userEtabId, $type) {
    //     try {
    //         // Initialize the query with necessary relationships
    //         $query = Notification::with([
    //             'user',
    //             'etablissement',
    //             'notifiable_user',
    //             'notifiable_etablissement',
    //             'annonce',
    //             'type'
    //         ]);

    //         // Filter by type ('establishment' or 'user')
    //         if ($type === 'establishment') {
    //             $query->where('etab_id', $userEtabId);
    //         } elseif ($type === 'user') {
    //             $query->where('user_id', $userEtabId);
    //         } else {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'Invalid type. Allowed values are "establishment" or "user".'
    //             ], 400);
    //         }

    //         // Order notifications by creation date (newest first)
    //         $notifications = $query->orderBy('created_at', 'desc')->get();

    //         // Return success response with data
    //         return response()->json([
    //             'status' => 'success',
    //             'data' => $notifications
    //         ], 200);

    //     } catch (\Exception $e) {
    //         // Handle any unexpected exceptions
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'An error occurred while retrieving notifications.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Create a new notification.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    // public function createNotifications(Request $request) {
    //     try {
    //         // Validate incoming request data
    //         $validatedData = $request->validate([
    //             'userId' => 'required|integer',
    //             'etabId' => 'nullable|integer',
    //             'typeId' => 'required|integer',
    //         ]);

    //         // Create a new notification with the validated data
    //         $notification = Notification::create([
    //             'user_id' => $validatedData['userId'],
    //             'etab_id' => $validatedData['etabId'] ?? null,
    //             'type_id' => $validatedData['typeId'],
    //         ]);

    //         // Return success response
    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Notification created successfully.',
    //             'data' => $notification
    //         ], 201);

    //     } catch (ValidationException $e) {
    //         // Handle validation exceptions
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Validation failed.',
    //             'errors' => $e->errors()
    //         ], 422);

    //     } catch (\Exception $e) {
    //         // Handle unexpected exceptions
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'An error occurred while creating the notification.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
}
