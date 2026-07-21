<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Etablissement;
use App\Models\Annonce;
use App\Models\User;
use App\Models\types_booster;

class Annonces_booster extends Model
{
    use HasFactory;
    protected $table = 'annonces_booster';

    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
    public function annonce(){
        return $this->belongsTo(Annonce::class, 'annonce_id');
    }
    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
    public function type_booste(){
        return $this->belongsTo(types_booster::class, 'type_booste_id');
    }

    protected $fillable = [
        'montant',
        'nbre_jours',
        'annonce_id',
        'type_booste_id',
        'date_debut',
        'date_fin',
        'type_paiement',
        'plan',
        'etab_id',
        'user_id',
        'status',
        'ciblage_villes_id',
        'ciblage_regions_id',
        'ciblage_categories_id',
    ];
}
