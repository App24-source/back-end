<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Etablissement;

class Telephone extends Model
{
    use HasFactory;
    protected $table = 'telephones';

    public function users(){
        return $this->belongsToMany(User::class, 'telephones_users_flink', 'telephone_id', 'user_id')
                ->withTimestamps();
    }
    public function etablissements(){
        return $this->belongsToMany(Etablissement::class, 'telephones_users_flink', 'telephone_id', 'etab_id')
                ->withTimestamps();
    }
    protected $guarded = [];

}
