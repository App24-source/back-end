<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;
use App\Models\Pays;

class Ville extends Model
{
    use HasFactory;
    protected $table = 'villes';

    public function publications(){
        return $this->hasMany(Publication::class, 'ville_id');
    }

    public function regions(){
        return $this->hasMany(Region::class, 'ville_id');
    }

    public function pays(){
        return $this->belongsTo(Pays::class, 'pays_id');
    }

    protected $guarded = [];
}
