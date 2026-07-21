<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Etablissement;
use App\Models\User;
use App\Models\Pays;
use App\Models\Ville;
use App\Models\Region;
use App\Models\Tags;
use App\Models\Categorie;
use App\Models\Statistique;
use App\Models\PublicationBooster;
use App\Models\ModeLaivraison;


class Publication extends Model
{
    use HasFactory;
    protected $table = 'publications_flink';

    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pays(){
        return $this->belongsTo(Pays::class, 'pays_id');
    }

    public function ville(){
        return $this->belongsTo(Ville::class, 'ville_id');
    }

    public function region(){
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function categorie(){
        return $this->belongsTo(CategorieFlink::class, 'cat_g_id');
    }

    public function modes_laivraison(){
        return $this->belongsToMany(ModeLaivraison::class, 'modes_laivraison_Publications_flink', 'publication_id', 'mode_laivraison_id');
    }

    public function tags(){
        return $this->belongsToMany(Tags::class, 'publications_tags_flink', 'publication_id', 'tag_id');
    }

    public function statistiques(){
        return $this->hasMany(Statistique::class, 'publication_id');
    }

    public function publications_booster(){
        return $this->hasMany(PublicationBooster::class, 'publication_id');
    }

    public function user_sauvgardes(){
        return $this->belongsToMany(User::class, 'sauvgarde_publications', 'publication_id', 'user_id');
    }

    public function etab_sauvgardes(){
        return $this->belongsToMany(Etablissement::class, 'sauvgarde_publications', 'publication_id', 'etab_id');
    }
    public function clicked_users(){
        return $this->belongsToMany(User::class, 'user_clicked_publications_flink', 'publication_id', 'user_id')
        ->withPivot('compteur', 'created_at', 'updated_at');
    }

    public function shopping_booster(){
        return $this->belongsToMany(PublicationBooster::class, 'shopping_flink', 'publication_id', 'boost_id');
    }

    public function recommendations_users() {
        return $this->belongsToMany(User::class, 'recommendations', 'publication_id', 'user_id')
        ->withPivot('count', 'score', 'created_at', 'updated_at');
    }

    public function recommendations_etabs() {
        return $this->belongsToMany(Etablissement::class, 'recommendations', 'publication_id', 'etab_id')
        ->withPivot('count', 'score', 'created_at', 'updated_at');
    }


    protected static function boot() {
        parent::boot();

        static::deleting(function ($publication) {
            $publication->publications_booster()->each(function($booster) {
                $booster->delete(); // This will trigger PublicationBooster's deleting event
            });
            $publication->statistiques()->delete();// Delete hasMany relations
            
            $publication->modes_laivraison()->detach(); // Detach belongsToMany relations
            $publication->tags()->detach();
            $publication->user_sauvgardes()->detach();
            $publication->etab_sauvgardes()->detach();
            $publication->clicked_users()->detach();
            $publication->shopping_booster()->detach();
            $publication->recommendations_users()->detach();
            $publication->recommendations_etabs()->detach();
        });
    }

    protected $guarded = [];

}
