<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Annonce;

class Categorie extends Model
{
    use HasFactory;
    protected $table = 'categories_generale';

    public function annonces(){
        return $this->hasMany(Annonce::class, 'cat_g_id');
    }
}
