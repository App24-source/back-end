<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Annonce;

class Immatriculation extends Model
{
    use HasFactory;
    protected $table = 'immatriculations';

    public function annonces(){
        return $this->hasMany(Annonce::class, 'immatriculation_id');
    }
}
