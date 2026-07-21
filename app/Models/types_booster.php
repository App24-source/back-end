<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Annonces_booster;

class types_booster extends Model
{
    use HasFactory;
    protected $table = 'types_booster';

    public function annonces_booster(){
        return $this->hasMany(Annonces_booster::class, 'type_booste_id');
    }
}
