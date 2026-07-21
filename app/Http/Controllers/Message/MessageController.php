<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NewMessage;
use App\Events\Message;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Publication;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;


class MessageController extends Controller
{
    /** Message rows live in `messages` table (not new_messages). */
    private function messagesTable()
    {
        return DB::table('messages');
    }

    /**
     * Canonical DB values: "user" | "etablissement" only.
     * If the client sends "user" → user; anything else (establishment, etab, etablissement, typo…) → etablissement.
     */
    private function normalizeMessagingType(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'user';
        }
        $v = strtolower(trim($type));
        if ($v === 'user') {
            return 'user';
        }
        return 'etablissement';
    }

    public function sendMessage(Request $request)
{
    // Validate the request data
    $request->validate([
        'sender_id' => 'required|string',
        'receiver_id' => 'required|string',
        'body' => 'nullable|string', // body is now nullable
        'file_src' => 'nullable', // This ensures the file is an image
        'respond_message' => 'nullable|string',
        'type_sender' => 'required|string',
        'type' => 'required|string',
        'type_receiver' => 'required|string',
        'conversation_id' => 'nullable|string',
        'publication_id' => 'nullable|string',
    ]);

    $type_sender = $this->normalizeMessagingType($request->type_sender);
    $type_receiver = $this->normalizeMessagingType($request->type_receiver);

    // Resolve conversation: same thread only when sender/receiver ids AND profile types match (or reversed).
    $existingConversation = $this->findConversationByParticipants(
        $request->sender_id,
        $request->receiver_id,
        $type_sender,
        $type_receiver
    );

    if ($request->conversation_id) {
        $requested = Conversation::find($request->conversation_id);
        if (
            $requested &&
            $this->conversationMatchesParticipants(
                $requested,
                $request->sender_id,
                $request->receiver_id,
                $type_sender,
                $type_receiver
            )
        ) {
            $conversation_id = $requested->id;
        } elseif ($existingConversation) {
            $conversation_id = $existingConversation->id;
        } else {
            $conversation = Conversation::create([
                'sender_id' => $request->sender_id,
                'receiver_id' => $request->receiver_id,
                'type_conv' => 'chat',
                'type_sender' => $type_sender,
                'type_receiver' => $type_receiver,
                'last_time_message' => now(),
            ]);
            $conversation_id = $conversation->id;
        }
    } elseif ($existingConversation) {
        $conversation_id = $existingConversation->id;
    } else {
        $conversation = Conversation::create([
            'sender_id' => $request->sender_id,
            'receiver_id' => $request->receiver_id,
            'type_conv' => 'chat',
            'type_sender' => $type_sender,
            'type_receiver' => $type_receiver,
            'last_time_message' => now(),
        ]);
        $conversation_id = $conversation->id;
    }

    // DB::table — Conversation model may omit type_sender/type_receiver from $fillable
    $this->persistConversationParticipantTypes(
        $conversation_id,
        $request->sender_id,
        $request->receiver_id,
        $type_sender,
        $type_receiver
    );

    DB::table('conversations')->where('id', $conversation_id)->update([
        'last_time_message' => now(),
        'deleted_by_sender' => false,
        'deleted_by_receiver' => false,
    ]);

    // Handle file upload if provided
    $file_src = null;
    if ($request->hasFile('file_src')) {
        // Define the directory based on message type
        if ($request->type === 'audio') {
            // Audio files go to audio directory
            $hlsTargetDir = 'uploads/messages/audio/' . date('Y');
        } else {
            // Other files (images, videos, etc.) go to regular messages directory
            $hlsTargetDir = 'uploads/messages/' . date('Y');
        }
    
        // Create the directory on the FTP server
        Storage::disk('ftp')->makeDirectory($hlsTargetDir);
    
        // Initialize an array to hold file paths
        $file_src = [];
    
        // Check if file_src is an array (multiple files)
        if (is_array($request->file('file_src'))) {
            foreach ($request->file('file_src') as $file) {
                // Validate audio files specifically
                if ($request->type === 'audio') {
                    $request->validate([
                        'file_src.*' => 'required|file|mimes:mp3,wav,ogg,webm,m4a|max:10240', // 10MB max for audio
                    ]);
                }
                
                // Store each file and get the path
                $filePath = $file->store($hlsTargetDir, 'ftp');
                $file_src[] = $filePath; // Add the file path to the array
            }
            // Convert the array to a string (e.g., comma-separated)
            $file_src = implode(',', $file_src); // or json_encode($file_src) if you prefer JSON
        } else {
            // Handle single file upload
            if ($request->type === 'audio') {
                $request->validate([
                    'file_src' => 'required|file|mimes:mp3,wav,ogg,webm,m4a|max:10240', // 10MB max for audio
                ]);
            }
            
            $filePath = $request->file('file_src')->store($hlsTargetDir, 'ftp');
            $file_src = $filePath; // Store the single file path
        }
    }

    // Determine the value for filePath based on the body content and type
    if ($request->body === 'GIF') {
        $filePath = (string)$request->file_src;
    } elseif ($request->type === 'audio' && $request->hasFile('file_src')) {
        $filePath = $file_src; // Use the stored audio file path
    } else {
        $filePath = $file_src;
    }

    // Create the message - now including the conversation_id
    $message = NewMessage::create([
        'sender_id' => $request->sender_id,
        'receiver_id' => $request->receiver_id,
        'body' => $request->body,
        'file_src' => $filePath, // Store the file path here
        'type_sender' => $type_sender,
        'type_receiver' => $type_receiver,
        'type' => $request->type,
        'is_read' => 0,
        'is_recieved' => 0,
        'respond_message' => $request->respond_message,
        'conversation_id' => $conversation_id,
        'publication_id' => $request->publication_id,
    ]);

    // Get conversations for the response
    $cnv = $this->getConversations($request->sender_id);

    // Trigger the message event
    event(new Message($message, $request->receiver_id, $type_sender, $filePath, $message->type));

    // If the message is sent to the selected user, update the message read status
    if ($request->receiver_id === $request->sender_id) {
        // Update the message status to read
        $message->update(['is_read' => 1]);

        // Emit an event to notify the recipient that the message has been read
        event(new Message($message, $request->receiver_id, $type_sender, $filePath, $message->type));
    }

    if ($type_sender === 'user') {
        $senderProfile = User::find($request->sender_id);
    } else {
        $senderProfile = Etablissement::find($request->sender_id);
    }

    if ($type_receiver === 'user') {
        $receiverProfile = User::find($request->receiver_id);
    } else {
        $receiverProfile = Etablissement::find($request->receiver_id);
    }
    
    if($request->publication_id) $publication = Publication::find($request->publication_id);
    else $publication = null;

    app(NotificationService::class)->sendNewMessageNotification($senderProfile, $receiverProfile, $request->body, $publication);

    $savedConversationRow = DB::table('conversations')->where('id', $conversation_id)->first();

    return response()->json([
        'status' => 'Message sent successfully!',
        'message' => $message,
        'request' => $cnv,
        'receiver_id' => $request->receiver_id,
        'file_src' => $filePath,
        'conversation_id' => $conversation_id,
        'conversation_saved' => $savedConversationRow ? [
            'id' => $savedConversationRow->id,
            'sender_id' => $savedConversationRow->sender_id,
            'receiver_id' => $savedConversationRow->receiver_id,
            'type_sender' => $savedConversationRow->type_sender,
            'type_receiver' => $savedConversationRow->type_receiver,
        ] : null,
    ]);
}
    public function likeMessage(Request $request)
    {
        $request->validate([
            'message_id' => 'required|integer', // Ensure the ID is an integer
            'like_type' => 'required|in:sender,receiver', // Check if it's sender or receiver
        ]);
        
        // Find the message
        $message = NewMessage::find($request->message_id);
        
        if (!$message) {
            return response()->json(['status' => 'Message not found'], 404);
        }
        
        // Toggle the like status based on the type of user
        if ($request->like_type === 'sender') {
            $message->is_liked_sender = !$message->is_liked_sender; // Toggle the like status
        } elseif ($request->like_type === 'receiver') {
            $message->is_liked_receiver = !$message->is_liked_receiver; // Toggle the like status
        }
        
        // Save the updated message
        $message->save();
        
        return response()->json([
            'status' => 'Like status updated successfully', 
            'message' => $message,
            'like_type' => $request->like_type,
            'is_liked_sender' =>$request->is_liked_sender,

        ]);
    }
    public function markAsRead(Request $request)
    {
        $message = NewMessage::find($request->message_id);
        if ($message) {
            $message->update(['is_read' => 1]); // Correction : utiliser 'is_read'
            return response()->json(['status' => 'Message marked as read']);
        }
        return response()->json(['status' => 'Message not found'], 404);
    }
    
    public function markConversationAsRead(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|string',
            'user_id' => 'required|string',
        ]);
    
        $conversation_id = $request->conversation_id;
        $user_id = $request->user_id;
    
        try {
            // Mark all messages in this conversation as read
            NewMessage::where('conversation_id', $conversation_id)
                ->where('receiver_id', $user_id)
                ->update(['is_read' => 1]);
    
            // Update the unread message count for the user (if needed)
            // Example: User::where('id', $user_id)->decrement('unread_messages_count');
    
            return response()->json(['status' => 'Conversation marked as read']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while marking the conversation as read', 'details' => $e->getMessage()], 500);
        }
    }

    public function deleteMessage(Request $request)
    {
        $request->validate(['message_id' => 'required|integer', 'deleted_by_id' => 'required|integer', 'delete_type' => 'required|in:deleted_for_me,deleted_for_all,deleted_badge']);
        
        $message_id = $request->message_id;
        $deleted_by_id = $request->deleted_by_id;
        $delete_type = $request->delete_type;
        
        // Find the message
        $message = NewMessage::find($request->message_id);
        if ($message) {
            if($message->sender_id == $deleted_by_id) {
                if($message->deleted_by_receiver) $message->delete();
                else {
                    if($delete_type == 'deleted_for_me') {
                        $message->deleted_by_sender = true;
                    } elseif($delete_type == 'deleted_for_all') {
                        $message->deleted_definitly = true;
                    } elseif($delete_type == 'deleted_badge') {
                        if($message->deleted_badge_by_receiver) $message->delete();
                        else $message->deleted_badge_by_sender = true;
                    }
                    $message->save();
                }
            } elseif($message->receiver_id == $deleted_by_id) {
               if($message->deleted_by_sender) $message->delete();
                else {
                    if($delete_type == 'deleted_for_me') {
                        $message->deleted_by_receiver = true;
                    } elseif($delete_type == 'deleted_for_all') {
                        $message->deleted_definitly = true;
                    } elseif($delete_type == 'deleted_badge') {
                        if($message->deleted_badge_by_sender) $message->delete();
                        else $message->deleted_badge_by_receiver = true;
                    }
                    $message->save();
                }
            }
            return response()->json(['status' => 'Message deleted successfully']);
        }
        return response()->json(['status' => 'Message not found'], 404);
    }
    
    public function deleteConversation(Request $request)
    {
        $request->validate(['conversation_id' => 'required|integer', 'deleted_by_id' => 'required|integer']);

        $deleted_by_id = $request->deleted_by_id;
        
        $conversation = Conversation::find($request->conversation_id);
        if ($conversation) {
            if($conversation->sender_id == $deleted_by_id) {
                if($conversation->deleted_by_receiver) $this->deleteConversationById($conversation);
                else {
                    $conversation->deleted_by_sender = true;
                    $conversation->save();
                }
            } elseif($conversation->receiver_id == $deleted_by_id) {
               if($conversation->deleted_by_sender) $this->deleteConversationById($conversation);
                else {
                    $conversation->deleted_by_receiver = true;
                    $conversation->save();
                }
            }

            
            return response()->json(['status' => 'Conversation deleted successfully']);
        }
    
        return response()->json(['status' => 'Conversation not found'], 404);
    }

    private function deleteConversationById($conversation) {
        // Récupérer les messages associés à la conversation
        $messages = NewMessage::where('conversation_id', $conversation->id)->get();

        // Supprimer les fichiers du serveur FTP
        foreach ($messages as $message) {
            if ($message->file_src) {
                // Remplacez 'ftp' par votre disque FTP configuré
                Storage::disk('ftp')->delete($message->file_src);
            }
        }

        // Supprimer les messages de la base de données
        NewMessage::where('conversation_id', $conversation->id)->delete();
        
        // Supprimer la conversation
        $conversation->delete();
    }


    public function getConversations($user_id)
    {
        // Get all conversations for the user
        $conversations = Conversation::where('sender_id', $user_id)
            ->orWhere('receiver_id', $user_id)
            ->with('messages') // Make sure this relationship is defined in your Conversation model
            ->get();
        
        return $conversations;
    }

   
    public function getConversationId(Request $request)
    {
        $request->validate([
            'sender_id' => 'required|string',
            'receiver_id' => 'required|string',
            'type_sender' => 'nullable|string',
            'type_receiver' => 'nullable|string',
        ]);
    
        $sender_id = $request->sender_id;
        $receiver_id = $request->receiver_id;
        $type_sender = $this->normalizeMessagingType($request->type_sender ?? 'user');
        $type_receiver = $this->normalizeMessagingType($request->type_receiver ?? 'etablissement');

        $conversation = $this->findConversationByParticipants(
            $sender_id,
            $receiver_id,
            $type_sender,
            $type_receiver
        );

        if ($conversation) {
            $this->persistConversationParticipantTypes(
                $conversation->id,
                $sender_id,
                $receiver_id,
                $type_sender,
                $type_receiver
            );
            $conversation = Conversation::find($conversation->id);
            $conversation->load('messages');
        }
    
        return response()->json([
            'conversation' => $conversation,
            'status' => $conversation ? 'found' : 'not_found',
        ]);
    }
    
    public function getLastMessageView(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|string',
            'user_id' => 'required|string',
        ]);
        
        // Get the last message of the conversation efficiently
        $lastMessage = NewMessage::where('conversation_id', $request->conversation_id)
            ->orderBy('created_at', 'desc')
            ->with(['publication' => function($q) {
                $q->select('id', 'image_default', 'slug', 'description', 'reference');
            }])
            ->first();

        if (!$lastMessage) {
            return response()->json([
                'lastMessage' => null
            ]);
        }

        return response()->json([
            'lastMessage' => [
                'id' => $lastMessage->id,
                'senderId' => $lastMessage->sender_id,
                'content' => $lastMessage->body,
                'timestamp' => $lastMessage->created_at->toISOString(),
                'is_read' => (bool)$lastMessage->is_read,
                'sender' => $lastMessage->type_sender,
                'file_src' => $lastMessage->file_src,
                'is_liked_sender' => $lastMessage->is_liked_sender,
                'is_liked_receiver' => $lastMessage->is_liked_receiver,
                'respond_message' => $lastMessage->respond_message,
                'type' => $lastMessage->type,
                'publication_id' => $lastMessage->publication_id,
                'publication' => $lastMessage->publication ? [
                    'id' => $lastMessage->publication->id,
                    'image_default' => $lastMessage->publication->image_default,
                    'slug' => $lastMessage->publication->slug,
                    'description' => $lastMessage->publication->description,
                    'reference' => $lastMessage->publication->reference
                ] : null
            ]
        ]);
    }
   

    public function getUsersInConversations(Request $request)
    {
        $request->validate([
            'user_id' => 'required|string',
        ]);

        $user_id = (string) $request->input('user_id', $request->user_id);
        $user_type = $this->normalizeMessagingType(
            $request->input('user_type', $request->user_type ?? 'user')
        );

        $debug = [
            'user_id' => $user_id,
            'user_type' => $user_type,
            'profile_found' => false,
            'conversations_by_id' => 0,
            'conversations_matched_type' => 0,
            'skipped' => [],
        ];

        $currentUser = null;
        if ($user_type === 'user') {
            $currentUser = User::find($user_id);
        } else {
            $currentUser = Etablissement::find($user_id);
        }

        if (!$currentUser) {
            return response()->json([
                'error' => 'Utilisateur non trouvé',
                '_debug' => $debug,
            ], 404);
        }
        $debug['profile_found'] = true;

        // Fetch by numeric id, then enforce type on the side where this profile appears
        $conversations = Conversation::where('sender_id', $user_id)
            ->orWhere('receiver_id', $user_id)
            ->orderBy('pinned', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();

        $debug['conversations_by_id'] = $conversations->count();

        $users = [];
        $messages = [];
        $seenConversationIds = [];

        foreach ($conversations as $conversation) {
            if (isset($seenConversationIds[$conversation->id])) {
                continue;
            }

            $isSender = (string) $conversation->sender_id === $user_id;
            $isReceiver = (string) $conversation->receiver_id === $user_id;
            if (!$isSender && !$isReceiver) {
                continue;
            }

            $myTypeOnRow = $isSender
                ? $this->normalizeMessagingType($conversation->type_sender)
                : $this->normalizeMessagingType($conversation->type_receiver);

            if ($myTypeOnRow !== $user_type) {
                $debug['skipped'][] = [
                    'conversation_id' => $conversation->id,
                    'reason' => 'type_mismatch',
                    'expected' => $user_type,
                    'got' => $myTypeOnRow,
                    'sender_id' => $conversation->sender_id,
                    'type_sender' => $conversation->type_sender,
                    'receiver_id' => $conversation->receiver_id,
                    'type_receiver' => $conversation->type_receiver,
                ];
                continue;
            }

            if ($isSender && $conversation->deleted_by_sender) {
                $debug['skipped'][] = ['conversation_id' => $conversation->id, 'reason' => 'deleted_by_sender'];
                continue;
            }
            if ($isReceiver && $conversation->deleted_by_receiver) {
                $debug['skipped'][] = ['conversation_id' => $conversation->id, 'reason' => 'deleted_by_receiver'];
                continue;
            }

            $seenConversationIds[$conversation->id] = true;
            $debug['conversations_matched_type']++;

            $otherUserId = $isSender ? $conversation->receiver_id : $conversation->sender_id;
            $otherUserType = $this->normalizeMessagingType(
                $isSender ? $conversation->type_receiver : $conversation->type_sender
            );

            $otherUser = $otherUserType === 'etablissement'
                ? Etablissement::find($otherUserId)
                : User::find($otherUserId);

            if (!$otherUser) {
                $debug['skipped'][] = [
                    'conversation_id' => $conversation->id,
                    'reason' => 'other_user_not_found',
                    'other_user_id' => $otherUserId,
                    'other_user_type' => $otherUserType,
                ];
                continue;
            }

            $isFollowing = false;
            try {
                if ($user_type === 'user' && $otherUserType === 'user') {
                    $isFollowing = $currentUser->followingUsers()->where('user_following_id', $otherUserId)->exists();
                } elseif ($user_type === 'user' && $otherUserType === 'etablissement') {
                    $isFollowing = $currentUser->followingEtablissements()->where('etab_following_id', $otherUserId)->exists();
                } elseif ($user_type === 'etablissement' && $otherUserType === 'user') {
                    $isFollowing = $currentUser->followingUsers()->where('user_following_id', $otherUserId)->exists();
                } elseif ($user_type === 'etablissement' && $otherUserType === 'etablissement') {
                    $isFollowing = $currentUser->followingEtablissements()->where('etab_following_id', $otherUserId)->exists();
                }
            } catch (\Throwable $e) {
                $isFollowing = false;
            }

            $lastMessage = $this->messagesTable()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('created_at')
                ->first();

            if ($lastMessage) {
                if ($isSender && $lastMessage->deleted_by_sender) {
                    $debug['skipped'][] = ['conversation_id' => $conversation->id, 'reason' => 'last_message_deleted_by_sender'];
                    continue;
                }
                if ($isReceiver && $lastMessage->deleted_by_receiver) {
                    $debug['skipped'][] = ['conversation_id' => $conversation->id, 'reason' => 'last_message_deleted_by_receiver'];
                    continue;
                }
            }

            $unreadCount = $this->messagesTable()
                ->where('conversation_id', $conversation->id)
                ->where('receiver_id', $user_id)
                ->where('type_receiver', $user_type)
                ->where('is_read', 0)
                ->count();

            $name = $otherUserType === 'etablissement'
                ? $otherUser->nom
                : trim($otherUser->first_name . ' ' . $otherUser->last_name);
            $Images = $otherUserType === 'etablissement' ? $otherUser->logo : $otherUser->avatar;

            $users[] = [
                'id' => $otherUserId,
                'name' => $name,
                'username' => $otherUser->slug,
                'avatar' => $Images,
                'followers' => $otherUserType === 'user' ? $otherUser->followersUsers()->count() : $otherUser->followersEtablissements()->count(),
                'following' => $otherUserType === 'user' ? $otherUser->followingUsers()->count() : $otherUser->followingEtablissements()->count(),
                'lastMessage' => $lastMessage ? $lastMessage->body : '',
                'senderId' => $lastMessage ? $lastMessage->sender_id : $user_id,
                'typeMessages' => $lastMessage ? $lastMessage->type : 'message',
                'lastMessageTime' => $lastMessage
                    ? date('c', strtotime($lastMessage->created_at))
                    : date('c', strtotime($conversation->updated_at)),
                'isOnline' => false,
                'unreadCount' => $unreadCount,
                'type' => $otherUserType,
                'conversation_id' => $conversation->id,
                'pinned' => $conversation->pinned,
                'isFollowing' => $isFollowing,
                'conversation_sender_id' => $conversation->sender_id,
                'conversation_receiver_id' => $conversation->receiver_id,
                'conversation_type_sender' => $conversation->type_sender,
                'conversation_type_receiver' => $conversation->type_receiver,
                'my_participant_role' => $isSender ? 'sender' : 'receiver',
                'deleted_by_sender' => $conversation->deleted_by_sender,
                'deleted_by_receiver' => $conversation->deleted_by_receiver,
            ];

            $messages[$otherUserId] = $messages[$otherUserId] ?? [];
            if ($lastMessage) {
                $messages[$otherUserId][] = [
                    'id' => $lastMessage->id,
                    'senderId' => $lastMessage->sender_id,
                    'content' => $lastMessage->body,
                    'timestamp' => date('c', strtotime($lastMessage->created_at)),
                    'read' => (bool) $lastMessage->is_read,
                    'sender' => $lastMessage->type_sender,
                    'file_src' => $lastMessage->file_src,
                    'is_liked_sender' => $lastMessage->is_liked_sender,
                    'is_liked_receiver' => $lastMessage->is_liked_receiver,
                    'respond_message' => $lastMessage->respond_message,
                    'type' => $lastMessage->type,
                    'publication_id' => $lastMessage->publication_id,
                    'publication' => null,
                    'deleted_by_sender' => $lastMessage->deleted_by_sender,
                    'deleted_by_receiver' => $lastMessage->deleted_by_receiver,
                ];
            }
        }

        return response()->json([
            'users' => $users,
            'messages' => $messages,
            'count' => count($users),
            '_debug' => $debug,
        ]);
    }
private function checkFollowStatus($followerId, $followingId, $followerType, $followingType)
{
    try {
        $isFollowing = true;
        
        if ($followerType === 'user' && $followingType === 'user') {
            $follower = User::findOrFail($followerId);
            $isFollowing = $follower->followingUsers()->where('user_following_id', $followingId)->exists();
            
        } elseif ($followerType === 'user' && $followingType === 'etablissement') {
            $follower = User::findOrFail($followerId);
            $isFollowing = $follower->followingEtablissements()->where('etab_following_id', $followingId)->exists();
            
        } elseif ($followerType === 'etablissement' && $followingType === 'user') {
            $follower = Etablissement::findOrFail($followerId);
            $isFollowing = $follower->followingUsers()->where('user_following_id', $followingId)->exists();
            
        } elseif ($followerType === 'etablissement' && $followingType === 'etablissement') {
            $follower = Etablissement::findOrFail($followerId);
            $isFollowing = $follower->followingEtablissements()->where('etab_following_id', $followingId)->exists();
        }
        
        return $isFollowing;
        
    } catch (\Throwable $th) {
        // En cas d'erreur, retourne false par défaut
        return false;
    }
}
    private function checkUserOnlineStatus($userId, $userType)
    {
        // This is a placeholder - implement based on your online status tracking method
        // For example, you might check a 'last_activity' timestamp or use a presence system
        
        // Example implementation:
        if ($userType === 'user') {
            $user = User::find($userId);
            if ($user && $user->last_activity) {
                // Consider a user online if they've been active in the last 5 minutes
                return now()->diffInMinutes($user->last_activity) < 5;
            }
        }
        
        // Default to offline if no online status can be determined
        return false;
    }

    public function pinConversation(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|integer|exists:conversations,id' // Ajout de la vérification d'existence
        ]);
    
        $conversation = Conversation::find($request->conversation_id);
        if ($conversation) {
            $conversation->pinned = !$conversation->pinned; // Épingler ou désépingler la conversation
            $conversation->save();
        }
    
        return response()->json(['success' => true, 'pinned' => $conversation->pinned]);
    }

    // Count of anread message of a profile
    public function userMessageCount($profileSlug) {
        try {
            // Find user and/or etablissement
            $etablissement = Etablissement::select('id', 'nom', 'slug', 'bio', 'email', 'ville_id', 'region_id', 'logo', 'is_verified')
                ->where('slug', $profileSlug)
                ->first();

            $user = User::select('id', 'first_name', 'last_name', 'slug', 'bio', 'email', 'ville_id', 'region_id', 'avatar', 'is_verified')
                ->where('slug', $profileSlug)
                ->first();

            $unreadMessages = collect();

            if ($user) {
                $userMessages = NewMessage::where('receiver_id', $user->id)
                    ->where('type_receiver', 'user')
                    ->where('is_read', 0)
                    ->get();
                $unreadMessages = $unreadMessages->merge($userMessages);
            }

            if ($etablissement) {
                $etabMessages = NewMessage::where('receiver_id', $etablissement->id)
                    ->where('type_receiver', 'etablissement')
                    ->where('is_read', 0)
                    ->get();
                $unreadMessages = $unreadMessages->merge($etabMessages);
            }

            // Group by conversation_id
            $grouped = $unreadMessages->groupBy('conversation_id')->map(function ($msgs, $convId) {
                return [
                    'conversation_id' => $convId,
                    'count' => $msgs->count(),
                    'messages' => $msgs->values(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'messagesCount' => $unreadMessages->count(),
                    'conversations' => $grouped,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }
    }
        














    /**
     * Same conversation when both profile ids AND types match, or the exact reversed pair.
     */
    private function conversationMatchesParticipants(
        Conversation $conversation,
        $senderId,
        $receiverId,
        $typeSender,
        $typeReceiver
    ): bool {
        return (
            (string) $conversation->sender_id === (string) $senderId &&
            (string) $conversation->receiver_id === (string) $receiverId &&
            (string) $conversation->type_sender === (string) $typeSender &&
            (string) $conversation->type_receiver === (string) $typeReceiver
        ) || (
            (string) $conversation->sender_id === (string) $receiverId &&
            (string) $conversation->receiver_id === (string) $senderId &&
            (string) $conversation->type_sender === (string) $typeReceiver &&
            (string) $conversation->type_receiver === (string) $typeSender
        );
    }

    private function findConversationByParticipants(
        $senderId,
        $receiverId,
        $typeSender,
        $typeReceiver
    ): ?Conversation {
        $typeSender = $this->normalizeMessagingType($typeSender);
        $typeReceiver = $this->normalizeMessagingType($typeReceiver);

        $conversation = Conversation::where(function ($query) use ($senderId, $receiverId, $typeSender, $typeReceiver) {
            $query->where('sender_id', $senderId)
                ->where('receiver_id', $receiverId)
                ->where('type_sender', $typeSender)
                ->where('type_receiver', $typeReceiver);
        })->orWhere(function ($query) use ($senderId, $receiverId, $typeSender, $typeReceiver) {
            $query->where('sender_id', $receiverId)
                ->where('receiver_id', $senderId)
                ->where('type_sender', $typeReceiver)
                ->where('type_receiver', $typeSender);
        })->first();

        if ($conversation) {
            return $conversation;
        }

        // Legacy rows: same sender_id/receiver_id but type_sender/type_receiver saved as "user" by mistake
        $legacy = Conversation::where(function ($query) use ($senderId, $receiverId) {
            $query->where('sender_id', $senderId)->where('receiver_id', $receiverId);
        })->orWhere(function ($query) use ($senderId, $receiverId) {
            $query->where('sender_id', $receiverId)->where('receiver_id', $senderId);
        })->first();

        if ($legacy) {
            $this->persistConversationParticipantTypes(
                $legacy->id,
                $senderId,
                $receiverId,
                $typeSender,
                $typeReceiver
            );
            return Conversation::find($legacy->id);
        }

        return null;
    }

    /**
     * Write type_sender / type_receiver on conversations row.
     * Uses DB::table because Conversation::$fillable often omits these columns
     * (Eloquent update/create then silently keeps type_sender = "user").
     */
    private function persistConversationParticipantTypes(
        $conversationId,
        $senderId,
        $receiverId,
        $typeSender,
        $typeReceiver
    ): void {
        $typeSender = $this->normalizeMessagingType($typeSender);
        $typeReceiver = $this->normalizeMessagingType($typeReceiver);

        $conv = DB::table('conversations')->where('id', $conversationId)->first();
        if (!$conv) {
            return;
        }

        if (
            (string) $conv->sender_id === (string) $senderId &&
            (string) $conv->receiver_id === (string) $receiverId
        ) {
            DB::table('conversations')->where('id', $conversationId)->update([
                'type_sender' => $typeSender,
                'type_receiver' => $typeReceiver,
            ]);
            return;
        }

        if (
            (string) $conv->sender_id === (string) $receiverId &&
            (string) $conv->receiver_id === (string) $senderId
        ) {
            DB::table('conversations')->where('id', $conversationId)->update([
                'type_sender' => $typeReceiver,
                'type_receiver' => $typeSender,
            ]);
        }
    }

    public function getMessages(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|integer',
            'page' => 'nullable|integer|min:1',
        ]);
        $perPage = 10;
        $page = $request->page ?? 1;
    
        $conversation = Conversation::find($request->conversation_id);
        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }
    
        $messages = NewMessage::where('conversation_id', $conversation->id)
            ->with(['publication:id,image_default,slug'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    
        // Get all respond_message IDs that need to be fetched
        $respondMessageIds = $messages->filter(function($message) {
            return !is_null($message->respond_message);
        })->pluck('respond_message')->unique()->toArray();
    
        // Fetch all responded messages with their files and publications
        $respondedMessages = NewMessage::whereIn('id', $respondMessageIds)
            ->with(['publication:id,image_default,slug'])
            ->get(['id', 'sender_id', 'body', 'type', 'created_at', 'file_src', 'publication_id'])
            ->keyBy('id');
    
        $formattedMessages = $messages->map(function ($message) use ($respondedMessages) {
            $respondMessage = null;
            if ($message->respond_message && isset($respondedMessages[$message->respond_message])) {
                $respondedMessage = $respondedMessages[$message->respond_message];
                $respondMessage = [
                    'id' => $respondedMessage->id,
                    'sender_id' => $respondedMessage->sender_id,
                    'content' => $respondedMessage->body,
                    'timestamp' => $respondedMessage->created_at->toISOString(),
                    'type' => $respondedMessage->type,
                    'file' => $respondedMessage->file_src,
                    'publication' => $respondedMessage->publication,
                ];
            }

            $base = [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'content' => $message->body,
                'timestamp' => $message->created_at->toISOString(),
                'read' => (bool)$message->is_read,
                'type' => $message->type,
                'file' => $message->file_src,
                'publication' => $message->publication,
                'respond_message' => $respondMessage,
                'is_liked_sender' => $message->is_liked_sender,
                'is_liked_receiver' => $message->is_liked_receiver,
                'deleted_by_sender' => $message->deleted_by_sender,
                'deleted_by_receiver' => $message->deleted_by_receiver,
                'deleted_definitly' => $message->deleted_definitly,
                'deleted_badge_by_sender' => $message->deleted_badge_by_sender,
                'deleted_badge_by_receiver' => $message->deleted_badge_by_receiver,
            ];

            // Only add width/height for image messages
            if ($message->type === 'image' && $message->file_src) {
                try {
                    if (Storage::disk('ftp')->exists($message->file_src)) {
                        $imageContents = Storage::disk('ftp')->get($message->file_src);
                        if ($imageContents) {
                            $manager = new ImageManager(\Intervention\Image\Drivers\Gd\Driver::class);
                            $image = $manager->read($imageContents);

                            $base['width'] = $image->width();
                            $base['height'] = $image->height();

                            // Create a low-quality image (resize and encode as base64)
                            $lowQualityImage = $image->resize(20, 20)->toJpeg(30)->toString();
                            $base['low_quality_image'] = 'data:image/jpeg;base64,' . base64_encode($lowQualityImage);
                        } else {
                            $base['width'] = null;
                            $base['height'] = null;
                            $base['low_quality_image'] = null;
                            $base['image_error'] = 'Image contents empty';
                        }
                    } else {
                        $base['width'] = null;
                        $base['height'] = null;
                        $base['low_quality_image'] = null;
                        $base['image_error'] = 'File does not exist on FTP';
                    }
                } catch (\Exception $e) {
                    $base['width'] = null;
                    $base['height'] = null;
                    $base['low_quality_image'] = null;
                    $base['image_error'] = $e->getMessage(); // For debugging
                }
            }

            return $base;
        });
    
        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'pinned' => $conversation->pinned,
            ],
            'messages' => $formattedMessages,
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'total_pages' => $messages->lastPage(),
                'total_messages' => $messages->total(),
            ]
        ]);
    }
















    
}




