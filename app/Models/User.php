<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Annonce;
use App\Models\Annonces_booster;
use App\Models\Cv_User;
use App\Models\Region;
use App\Models\Ville;
use App\Models\Etablissement;
use App\Models\Coupon;
use App\Models\TransactionPoint;
use App\Models\Publication;
use App\Models\Tags;
use App\Models\Telephone;
use App\Models\Connexion;
use App\Models\ProfileStatistique;
use App\Models\NotificationToken;
use App\Models\CustomFlux;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $table = 'users';
    
    public function annonces(){
        return $this->hasMany(Annonce::class, 'user_id');
    }

    public function publications(){
        return $this->hasMany(Publication::class, 'user_id');
    }

    public function annonces_booster(){
        return $this->hasMany(Annonces_booster::class, 'user_id');
    }
    public function get_cv_users(){
        return $this->hasMany(Cv_User::class, 'user_id');
    }
    public function get_ville(){
        return $this->belongsTo(Ville::class, 'ville_id');
    }
    public function get_region(){
        return $this->belongsTo(Region::class, 'region_id');
    }
    public function etablissements(){
        return $this->belongsToMany(Etablissement::class, 'user_etab_roles', 'user_id', 'etab_id');
    }
    public function emplois(){
        return $this->belongsToMany(Annonce::class, 'postule', 'user_id', 'annonce_id')
        ->withPivot('email', 'phoneNumber', 'cv_postuler', 'lettre_motivation', 'postule_date');
    }
    public function coupons(){
        return $this->hasMany(Coupon::class, 'user_id');
    }

    public function transactions_points(){
        return $this->hasMany(TransactionPoint::class, 'user_id');
    }

    public function clicked_publications(){
        return $this->belongsToMany(Publication::class, 'user_clicked_publications_flink', 'user_id', 'publication_id')
        ->withPivot('compteur', 'created_at', 'updated_at');
    }

    public function tags_filters(){
        return $this->belongsToMany(Tags::class, 'user_tags_filters_flink', 'user_id', 'tag_id')
        ->withPivot('compteur', 'created_at', 'updated_at');
    }

    public function telephones(){
        return $this->belongsToMany(Telephone::class, 'telephones_users_flink', 'user_id', 'telephone_id')
                ->withTimestamps();
    }

    // public function telephones(){
    //     return $this->hasMany(Telephone::class, 'user_id');
    // }

    public function connexions(){
        return $this->hasMany(Connexion::class, 'user_id');
    }

    public function publications_sauvgarde() {
        return $this->belongsToMany(Publication::class, 'sauvgarde_publications', 'user_id', 'publication_id')
        ->withPivot('created_at', 'updated_at');
    }

    // followers
    // User can follow other users
    public function followingUsers()
    {
        return $this->belongsToMany(User::class, 'follows', 'user_follower_id', 'user_following_id')
        ->withPivot('created_at', 'updated_at');
    }

    // User can be followed by other users
    public function followersUsers()
    {
        return $this->belongsToMany(User::class, 'follows', 'user_following_id', 'user_follower_id')
        ->withPivot('created_at', 'updated_at');
    }

    // User can follow establishments
    public function followingEtablissements()
    {
        return $this->belongsToMany(Etablissement::class, 'follows', 'user_follower_id', 'etab_following_id')
        ->withPivot('created_at', 'updated_at');
    }

    // User can be followed by establishments
    public function followersEtablissements()
    {
        return $this->belongsToMany(Etablissement::class, 'follows', 'user_following_id', 'etab_follower_id')
        ->withPivot('created_at', 'updated_at');
    }

    public function profile_statistiques(){
        return $this->hasMany(ProfileStatistique::class, 'user_id');
    }

    public function recommendations() {
        return $this->belongsToMany(Publication::class, 'recommendations', 'user_id', 'publication_id')
        ->withPivot('count', 'score', 'created_at', 'updated_at');
    }
    

    public function notification_tokens() {
        return $this->hasMany(NotificationToken::class, 'user_id');
    }

    public function routeNotificationForFcm() {
        return $this->notification_tokens->pluck('token')->toArray();  // or whatever column stores device tokens
    }

    public function customFluxes() {
        return $this->hasMany(CustomFlux::class, 'user_id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    // protected $fillable = [
    //     'first_name',
    //     'last_name',
    //     'email',
    //     'tele',
    //     'tele_verifie',
    //     'password',
    //     'ville',
    //     'ville_id',
    //     'region_id',
    //     'type',
    //     'avatar',
    //     'banner',
    //     'titre',
    //     'adresse',
    //     'description',
    // ];
    protected $guarded = [];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'current_random' => 'integer',
    ];
}
