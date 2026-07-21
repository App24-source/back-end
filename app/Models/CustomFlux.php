<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomFlux extends Model
{
    use HasFactory;

    protected $table = 'custom_flux';

    protected $fillable = [
        'name',
        'slug',
        'user_id',
        'etab_id',
        'tags_list',
        'profiles_list'
    ];

    protected $casts = [
        'tags_list' => 'array',
        'profiles_list' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relationship with Etablissement
    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class, 'etab_id');
    }

    // Generate slug from name
    public static function generateSlug($name)
    {
        // Replace spaces and special characters with underscores
        $slug = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);
        $slug = preg_replace('/\s+/', '_', $slug);
        $slug = strtolower($slug);
        
        return $slug;
    }

    // Check if name/slug is unique for a specific user/etablissement
    public static function isNameUnique($name, $userId = null, $etabId = null)
    {
        $slug = self::generateSlug($name);
        
        $query = self::where('slug', $slug);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if ($etabId) {
            $query->where('etab_id', $etabId);
        }
        
        return $query->count() === 0;
    }

    // Boot method to auto-generate slug
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = self::generateSlug($model->name);
            }
        });
        
        static::updating(function ($model) {
            if ($model->isDirty('name')) {
                $model->slug = self::generateSlug($model->name);
            }
        });
    }
}
