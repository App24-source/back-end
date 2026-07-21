<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Etablissement;
use App\Models\User;
use App\Models\Annonce;
use App\Models\TypeNotification;

class Notification extends Model
{
    use HasFactory;
    protected $table = 'notifications';

    protected $keyType = 'string'; // Prevents auto-increment behavior
    public $incrementing = false; // Disables numeric casting

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
    public function notifiable_user(){
        return $this->belongsTo(User::class, 'notifiable_user_id');
    }
    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
    public function notifiable_etablissement(){
        return $this->belongsTo(Etablissement::class, 'notifiable_etab_id');
    }
    public function annonce(){
        return $this->belongsTo(Annonce::class, 'annonce_id');
    }
    public function type(){
        return $this->belongsTo(TypeNotification::class, 'type_id');
    }


    protected $guarded = [];
}
