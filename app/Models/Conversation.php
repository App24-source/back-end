<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\NewMessage;

class Conversation extends Model
{
    use HasFactory;
    protected $table = 'conversations';

    public function messages(){
        return $this->hasMany(NewMessage::class, 'conversation_id');
    }

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'type_conv',
        'sender_type',
        'receiver_type',
        'last_time_message',
        'pinned',
        'deleted_by_sender',
        'deleted_by_receiver',
    ];
}
