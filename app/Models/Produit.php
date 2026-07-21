<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\FacturationVrB;

class Produit extends Model
{
    use HasFactory;
    protected $table = 'products';

    public function facturations(){
        return $this->hasMany(FacturationVrB::class, 'product_id');
    }

    protected $guarded = [];
}
