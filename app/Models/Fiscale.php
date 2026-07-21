<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Annonce;

class Fiscale extends Model
{
    use HasFactory;
    protected $table = 'fiscales';

    public function annonces(){
        return $this->hasMany(Annonce::class, 'fiscale_id');
    }
}
