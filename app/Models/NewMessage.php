<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Conversation;
use App\Models\Publication;

class NewMessage extends Model
{
    use HasFactory;
    protected $table = 'messages';

    public function conversation(){
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
    public function publication(){
        return $this->belongsTo(Publication::class, 'publication_id');
    }

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'body',
        'is_read',
        'is_recieved',
        'type',
        'file_src',
        'type_sender',
        'type_receiver',
        'conversation_id',
        'is_liked_sender ',
        'is_liked_receiver',
        'respond_message',
        'publication_id',
        'deleted_by_sender',
        'deleted_by_receiver',
        'deleted_badge_by_sender',
        'deleted_badge_by_receiver',
        'deleted_definitly'
    ];
}
