<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;
use App\Models\Ville;
use App\Models\Pays;
use App\Models\Region;
use App\Models\Tags;
use App\Models\StatistiqueBooster;

class PublicationBooster extends Model
{
    use HasFactory;
    protected $table = "publications_booster_flink";

    public function publication(){
        return $this->belongsTo(Publication::class, 'publication_id');
    }

    public function tags(){
        return $this->belongsToMany(Tags::class, 'publications_booster_tags_flink', 'publication_booster_id', 'tag_id');
    }

    public function pays(){
        return $this->belongsToMany(Pays::class, 'publications_booster_pays_flink', 'publication_booster_id', 'pays_id');
    }

    public function villes(){
        return $this->belongsToMany(Ville::class, 'publications_booster_villes_flink', 'publication_booster_id', 'ville_id');
    }

    public function regions(){
        return $this->belongsToMany(Region::class, 'publications_booster_regions_flink', 'publication_booster_id', 'region_id');
    }

    public function shopping_publications(){
        return $this->belongsToMany(Publication::class, 'shopping_flink', 'boost_id', 'publication_id');
    }

    public function statistiques(){
        return $this->hasMany(StatistiqueBooster::class, 'boost_id');
    }

    protected static function boot() {
        parent::boot();

        static::deleting(function ($booster) {
            
            $booster->tags()->detach(); // Detach belongsToMany relations
            $booster->pays()->detach();
            $booster->villes()->detach();
            $booster->regions()->detach();
            $booster->shopping_publications()->detach();
            $booster->statistiques()->delete();// Delete hasMany relations
            
        });
    }

    protected $guarded = [];
}
