<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;

class ModeLaivraison extends Model
{
    use HasFactory;
    protected $table = 'modes_laivraison_flink';

    public function publications(){
        return $this->belongsToMany(Publication::class, 'modes_laivraison_Publications_flink', 'publication_id', 'mode_laivraison_id');
    }

}
