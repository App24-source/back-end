<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Annonce;

class Panier extends Model
{
    use HasFactory;
    protected $table = 'panier';

    public function annonces(){
        return $this->belongsToMany(Annonce::class, 'panier_items', 'panier_id', 'annonce_id')
        ->withPivot('quantity', 'variante');
    }
}
