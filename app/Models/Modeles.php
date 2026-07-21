<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Modeles extends Model
{
    use HasFactory;
    protected $table = 'models';

    public function annonces(){
        return $this->hasMany(Annonce::class, 'model_id');
    }
}
