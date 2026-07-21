<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Etablissement;

class TransactionPoint extends Model
{
    use HasFactory;
    protected $table = 'transaction_points';

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
    public function etablissement(){
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
    public function etablissement_destination(){
        return $this->belongsTo(Etablissement::class, 'etab_destination');
    }

    protected $guarded = [];
}
