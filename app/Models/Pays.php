<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;
use App\Models\Ville;

class Pays extends Model
{
    use HasFactory;
    protected $table = 'pays';

    public function villes(){
        return $this->hasMany(Ville::class, 'pays_id');
    }

    public function publications(){
        return $this->hasMany(Publication::class, 'pays_id');
    }

    protected $guarded = [];
}
