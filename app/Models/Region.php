<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;

class Region extends Model
{
    use HasFactory;
    protected $table = 'regions';

    public function publications(){
        return $this->hasMany(Publication::class, 'region_id');
    }

    public function ville(){
        return $this->belongsTo(Ville::class, 'ville_id');
    }

    protected $guarded = [];
}
