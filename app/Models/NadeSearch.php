<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Categorie;
use App\Models\Region;
use App\Models\Ville;
use App\Models\Categorie_google;

class NadeSearch extends Model
{
    use HasFactory;
    protected $table = 'nade_searchs';

    public function ville(){
        return $this->belongsTo(Ville::class, 'ville_id');
    }
    public function region(){
        return $this->belongsTo(Region::class, 'region_id');
    }
    public function categorie_generale(){
        return $this->belongsTo(Categorie::class, 'cat_g_id');
    }
    // public function categorieGoogle(){
    //     return $this->belongsTo(Categorie_google::class, 'cat_g_google_id');
    // }
}
