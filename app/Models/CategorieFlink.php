<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;
use App\Models\Tags;

class CategorieFlink extends Model
{
    use HasFactory;
    protected $table = 'categories_generale_flink';

    public function annonces(){
        return $this->hasMany(Publication::class, 'cat_g_id');
    }

    public function tags(){
        return $this->belongsToMany(Tags::class, 'categories_tags_flink', 'categorie_id', 'tag_id')
        ->withPivot('status');
    }

}
