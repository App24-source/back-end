<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationToken extends Model
{
    use HasFactory;
    protected $table = 'notifications_tokens';

    protected $fillable = ['user_id', 'token', 'browser_key', 'device_type', 'device_name', 'created_at', 'updated_at'];
    
}
