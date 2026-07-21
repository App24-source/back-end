<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicationsReport extends Model
{
    use HasFactory;

    protected $table = 'publications_reports';

    protected $fillable = [
        'publication_id',
        'user_id',
        'etablissement_id',
        'cause',
        'other_message',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relationships
     */

    // Each report belongs to a publication
    public function publication()
    {
        return $this->belongsTo(PublicationFlink::class, 'publication_id');
    }

    // Report may belong to a user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Report may belong to an etablissement
    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class, 'etablissement_id');
    }
}
