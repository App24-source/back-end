<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Annonce;

class Marque extends Model
{
    use HasFactory;
    protected $table = 'marques';

    public function annonces(){
        return $this->hasMany(Annonce::class, 'marque_id');
    }
}
