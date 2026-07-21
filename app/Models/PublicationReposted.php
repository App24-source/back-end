<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicationReposted extends Model
{
    use HasFactory;

    protected $table = 'publications_reposted';

    protected $fillable = [
        'publication_id',
        'user_id',
        'etab_id',
        'profile_type',
    ];

    public $timestamps = true;

    public function publication()
    {
        return $this->belongsTo(Publication::class, 'publication_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }
}
