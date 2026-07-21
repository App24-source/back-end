<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Etablissement;

class ProfileStatistique extends Model
{
    use HasFactory;
    protected $table = "profile_statistiques";

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }

    protected $guarded = [];
}
