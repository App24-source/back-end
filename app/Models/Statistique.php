<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Publication;

class Statistique extends Model
{
    use HasFactory;
    protected $table = 'statistiques_flink';
    
    public function publication(){
        return $this->belongsTo(Publication::class, 'publication_id');
    }

    // Allow all columns — no mass assignment restrictions needed for statistics
    protected $guarded = [];

}
