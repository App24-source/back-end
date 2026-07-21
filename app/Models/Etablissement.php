<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Annonce;
use App\Models\publicite;
use App\Models\Annonces_booster;
use App\Models\User;
use App\Models\Ville;
use App\Models\Region;
use App\Models\Coupon;
use App\Models\TransactionPoint;
use App\Models\Publication;
use App\Models\ProfileStatistique;
use App\Models\EtabDetail;
use Illuminate\Support\Facades\DB;
use App\Models\NotificationToken;
use App\Models\CustomFlux;
use Illuminate\Notifications\Notifiable;

class Etablissement extends Model
{
    use HasFactory;
    use Notifiable;
    protected $table = 'etablissements';

    // public function annonces(){
    //     return $this->hasMany(Annonce::class, 'etab_id');
    // }

    public function publications()
    {
        return $this->hasMany( Publication::class, 'etab_id' );
    }


    // public function publicites(){
    //     return $this->hasMany(publicite::class, 'etab_id');
    // }
    public function etab_details()
    {
        return $this->hasMany( EtabDetail::class, 'etab_id' );
    }
    public function users()
    {
        return $this->belongsToMany( User::class, 'user_etab_roles', 'etab_id', 'user_id' );
    }
    public function region()
    {
        return $this->belongsTo( Region::class, 'region_id' );
    }
    public function ville()
    {
        return $this->belongsTo( Ville::class, 'ville_id' );
    }
    public function tag()
    {
        return $this->belongsTo( Tags::class, 'tag_id' );
    }
    public function coupons()
    {
        return $this->hasMany( Coupon::class, 'etab_id' );
    }
    public function transactions_points()
    {
        return $this->hasMany( TransactionPoint::class, 'etab_id' );
    }

    public function clicked_publications()
    {
        return $this->belongsToMany( Publication::class, 'user_clicked_publications_flink', 'etab_id', 'publication_id' )
            ->withPivot( 'compteur', 'created_at', 'updated_at' );
    }

    public function tags_filters()
    {
        return $this->belongsToMany( Tags::class, 'user_tags_filters_flink', 'etab_id', 'tag_id' )
            ->withPivot( 'compteur', 'created_at', 'updated_at' );
    }

    public function publications_sauvgarde()
    {
        return $this->belongsToMany( Publication::class, 'sauvgarde_publications', 'etab_id', 'publication_id' )
            ->withPivot( 'created_at', 'updated_at' );
    }

    public function telephones()
    {
        return $this->belongsToMany( Telephone::class, 'telephones_users_flink', 'etab_id', 'telephone_id' );
    }

    // followers
    // Etablissement can follow users
    public function followingUsers()
    {
        return $this->belongsToMany( User::class, 'follows', 'etab_follower_id', 'user_following_id' )
            ->withPivot( 'created_at' );
    }

    // Etablissement can be followed by users
    public function followersUsers()
    {
        return $this->belongsToMany( User::class, 'follows', 'etab_following_id', 'user_follower_id' )
            ->withPivot( 'created_at' );
    }

    // Etablissement can follow other establishments
    public function followingEtablissements()
    {
        return $this->belongsToMany( Etablissement::class, 'follows', 'etab_follower_id', 'etab_following_id' )
            ->withPivot( 'created_at' );
    }

    // Etablissement can be followed by other establishments
    public function followersEtablissements()
    {
        return $this->belongsToMany( Etablissement::class, 'follows', 'etab_following_id', 'etab_follower_id' )
            ->withPivot( 'created_at' );
    }

    public function profile_statistiques()
    {
        return $this->hasMany( ProfileStatistique::class, 'etab_id' );
    }

    public function recommendations()
    {
        return $this->belongsToMany( Publication::class, 'recommendations', 'etab_id', 'publication_id' )
            ->withPivot( 'count', 'score', 'created_at', 'updated_at' );
    }

    public function notification_tokens()
    {
        return $this->hasMany( NotificationToken::class, 'etab_id' );
    }

    public function routeNotificationForFcm()
    {
        return $this->notification_tokens->pluck( 'token' )->toArray();  // or whatever column stores device tokens
    }

    public function customFluxes()
    {
        return $this->hasMany( CustomFlux::class, 'etab_id' );
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting( function ( $etablissement ) {
            // Start a transaction for safety
            DB::beginTransaction();

            try {
                // Delete all hasMany relationships
                $etablissement->publications()->each( function ( $publication ) {
                    // Delete from junction tables that reference publications
                    DB::table( 'publications_tags_flink' )
                        ->where( 'publication_id', $publication->id )
                        ->delete();

                    DB::table( 'user_clicked_publications_flink' )
                        ->where( 'publication_id', $publication->id )
                        ->delete();

                    DB::table( 'sauvgarde_publications' )
                        ->where( 'publication_id', $publication->id )
                        ->delete();

                    // Now delete the publication itself
                    $publication->delete();
                } );
                $etablissement->transactions_points()->delete();
                $etablissement->telephones()->delete();
                $etablissement->coupons()->delete();
                $etablissement->profile_statistiques()->delete();
                $etablissement->etab_details()->delete();
                $etablissement->notification_tokens()->delete();

                // Handle user_etab_roles specifically (the table causing your error)
                DB::table( 'user_etab_roles' )->where( 'etab_id', $etablissement->id )->delete();

                // Detach all many-to-many relationships
                $etablissement->clicked_publications()->detach();
                $etablissement->tags_filters()->detach();
                $etablissement->publications_sauvgarde()->detach();

                // Handle follows relationships
                DB::table( 'follows' )
                    ->where( 'etab_follower_id', $etablissement->id )
                    ->orWhere( 'etab_following_id', $etablissement->id )
                    ->delete();

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } );
    }

    protected $fillable = [
        'nom',
        'ville_id',
        'bio',
        'region_id',
        'tag_id',
        'siteweb',
        'email',
        'numero_telephone',
        'logo',
        'adresse',
        'google_map_id',
        'slug',
        'categories',
        'latitude',
        'longitude',
        'consommation_points',
        'email_status',
        'current_random'
    ];

    protected $casts = [
        'current_random' => 'integer',
    ];
}
