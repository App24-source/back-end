<?php
namespace App\Events;

use App\Models\NewMessage;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // Important
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Etablissement;

class Message implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $receiverId;
    public $typeReceiver; // Ajout de type_receiver
    public $file_src; // Ajout de type_receiver
    

    public function __construct(NewMessage $message, $receiverId, $typeReceiver,$file_src,$type) // Ajout de type_receiver dans le constructeur
    {
        $this->message = $message;
        $this->receiverId = $receiverId;
        $this->file_src = $file_src;
        $this->typeReceiver = $typeReceiver; // Initialisation de type_receiver
        $this->type = $type; // Initialisation de type_receiver

    }

    public function broadcastOn()
    {
        return new Channel('my-channel');
    }

    public function broadcastWith()
    {
        // Déterminez le nom de l'expéditeur en fonction du type de récepteur
        $senderName = $this->typeReceiver === 'etablissement'
            ? Etablissement::find($this->message->sender_id)->nom ?? 'Unknown Sender'
            : User::find($this->message->sender_id)->first_name ?? 'Unknown Sender';

        return [
            'message' => [
                'id' => $this->message->id,
                'body' => $this->message->body,
                'sender_id' => $this->message->sender_id,
                'sender_name' => $senderName, // Utilisation de senderName ici
                'created_at' => $this->message->created_at,
                'receiver_id' => $this->receiverId,
                'conversation_id' => $this->message->conversation_id,
                'file_src' => $this->file_src,              
                 'type_receiver' => $this->typeReceiver, // Utilisation de type_receiver ici
                 'type' => $this->type
            ]
        ];
    }

    public function broadcastAs()
    {
        return 'new-message';
    }
}