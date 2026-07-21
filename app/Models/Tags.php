<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;
use App\Models\User;

class Tags extends Model
{
    use HasFactory;
    protected $table = 'tags_flink';

    public function publications(){
        return $this->belongsToMany(Publication::class, 'publications_tags_flink', 'tag_id', 'publication_id');
        // ->withPivot('quantity', 'variante');
    }

    public function users(){
        return $this->belongsToMany(User::class, 'user_tags_filters_flink', 'tag_id', 'user_id');
        // ->withPivot('quantity', 'variante');
    }
    public function etablissements(){
        return $this->belongsToMany(User::class, 'user_tags_filters_flink', 'tag_id', 'etab_id');
        // ->withPivot('quantity', 'variante');
    }

    // public function categories(){
    //     return $this->belongsToMany(CategorieFlink::class, 'categories_tags_flink', 'tag_id', 'categorie_id')
    //     ->withPivot('status');
    // }

    protected static function boot() {
        parent::boot();

        static::deleting(function ($tag) {
            // $tag->statistiques()->delete();// Delete hasMany relations
            // $tag->publications_booster()->delete();
            $tag->publications()->detach(); // Detach belongsToMany relations
            $tag->users()->detach();
            $tag->etablissements()->detach();
            // $tag->tags()->detach();
            // $tag->user_sauvgardes()->detach();
            // $tag->etab_sauvgardes()->detach();
        });
    }

    protected $guarded = [];
}
