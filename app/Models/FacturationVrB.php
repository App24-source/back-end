<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Produit;
use App\Models\Etablissement;
use App\Models\User;

class FacturationVrB extends Model
{
    use HasFactory;
    protected $table = 'facturation_vrb';

    public function product(){
        return $this->belongsTo(Produit::class, 'product_id');
    }
    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $guarded = [];
}
