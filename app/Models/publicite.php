<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class publicite extends Model
{
    use HasFactory;
    protected $table = 'publicites';

    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
}
