<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Etablissement;
use App\Models\User;
use App\Models\Categorie;
use App\Models\Region;
use App\Models\Ville;
use App\Models\Annee;
use App\Models\Carburant;
use App\Models\Fiscale;
use App\Models\Immatriculation;
use App\Models\Marque;
use App\Models\Modeles;
use App\Models\Annonces_booster;
use App\Models\Panier;

class Annonce extends Model
{
    use HasFactory;
    protected $table = 'vehicules';

    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
    public function categorie(){
        return $this->belongsTo(Categorie::class, 'cat_g_id');
    }
    public function region(){
        return $this->belongsTo(Region::class, 'region_id');
    }
    public function ville(){
        return $this->belongsTo(Ville::class, 'ville_id');
    }

    public function annee(){
        return $this->belongsTo(Annee::class, 'annee_id');
    }
    public function carburant(){
        return $this->belongsTo(Carburant::class, 'carburant_id');
    }
    public function fiscale(){
        return $this->belongsTo(Fiscale::class, 'fiscale_id');
    }
    public function immatriculation(){
        return $this->belongsTo(Immatriculation::class, 'immatriculation_id');
    }
    public function marque(){
        return $this->belongsTo(Marque::class, 'marque_id');
    }
    public function model(){
        return $this->belongsTo(Modeles::class, 'model_id');
    }
    public function annonces_booster(){
        return $this->hasMany(Annonces_booster::class, 'annonce_id');
    }
    public function candidats(){
        return $this->belongsToMany(User::class, 'postule', 'annonce_id', 'user_id')
        ->withPivot('email', 'phoneNumber', 'cv_postuler', 'lettre_motivation', 'postule_date');
    }
    public function paniers(){
        return $this->belongsToMany(Panier::class, 'panier_items', 'panier_id', 'annonce_id')
        ->withPivot('quantity', 'variante');
    }

    protected $guarded = [];
}
