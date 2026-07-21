<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Cv_User extends Model
{
    use HasFactory;
    protected $table = 'cv_users';
    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
    protected $fillable = ['id', 'user_id', 'file', 'name', 'extension', 'default_cv', 'last_send', 'created_at',
    'updated_at', 'deleted_at'];
    
    public $timestamps = true;
}
