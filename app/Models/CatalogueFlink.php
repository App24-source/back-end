<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogueFlink extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'catalogues_flink';

    protected $fillable = [
        'etab_id',
        'user_id',
        'catalogue_group',
        'source_file',
        'refresh_interval',
        'refresh_time',
        'next_update_at',
        'last_imported_at',
    ];

    protected $casts = [
        'next_update_at'   => 'datetime',
        'last_imported_at' => 'datetime',
    ];

    /**
     * Get the publications linked to this catalogue.
     */
    public function publications()
    {
        // Assuming your publication model is PublicationFlink or similar.
        // If it's different, the user can adjust this later.
        return $this->hasMany(Publication::class, 'catalogue_id');
    }
}
