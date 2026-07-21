<?php

namespace App\Http\Controllers\users;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\ProfileStatistique;

class UsersController extends Controller
{
    public function getUserEtablissement($slug, $profileSlug = null)
    {
        try {
            // Chercher d'abord l'établissement
            $etablissement = Etablissement::select('id', 'nom', 'slug', 'bio', 'email', 'ville_id', 'region_id', 'logo', 'is_verified')
            ->where('slug', $slug)
            // ->with('followingEtablissements', 'followingUsers')
            ->first();

            $checkIsFollowedEtab = null;
            $checkIsFollowedUser = null;
            if($profileSlug) {
                $etablissementConnected = Etablissement::select('id', 'nom', 'slug', 'bio', 'email', 'ville_id', 'region_id', 'logo', 'is_verified')
                ->where('slug', $profileSlug)
                ->with('followingUsers', 'followingEtablissements')
                ->first();
                $userConnected = User::select('id', 'first_name', 'last_name', 'slug', 'bio', 'email', 'ville_id', 'region_id', 'avatar', 'is_verified')
                ->where('slug', $profileSlug)
                ->with('followingUsers', 'followingEtablissements')
                ->first();

                if($etablissementConnected) {
                    $ConnectedFollowingSlugs = array_merge($etablissementConnected->followingEtablissements->pluck('slug')->toArray(), $etablissementConnected->followingUsers->pluck('slug')->toArray());
                } else if($userConnected) {
                    $ConnectedFollowingSlugs = array_merge($userConnected->followingEtablissements->pluck('slug')->toArray(), $userConnected->followingUsers->pluck('slug')->toArray());
                }
            }
            
            // Si l'établissement existe, on le retourne
            if ($etablissement) {
                if($etablissement->slug !== $profileSlug) {
                    $stat = ProfileStatistique::firstOrCreate([
                        'etab_id' => $etablissement->id,
                        'date' => now()->toDateString(),
                    ]);
        
                    // Incrémentation du champ correspondant à l'action spécifiée
                    $stat->increment('profile_views');
                }
                if($profileSlug) $checkIsFollowedEtab = in_array($etablissement->slug, $ConnectedFollowingSlugs);
                return response()->json([
                    'success' => true,
                    'type' => 'etablissement',
                    'data' => $etablissement,
                    'isFollowed' => $checkIsFollowedEtab,
                    'profileSlug' => $profileSlug,
                ], 200);
            }

            // Si aucun établissement trouvé, chercher l'utilisateur
            $user = User::select('id', 'first_name', 'last_name', 'slug', 'bio', 'email', 'ville_id', 'region_id', 'avatar', 'is_verified')
            ->where('slug', $slug)
            // ->with('get_ville:id,name', 'get_region:id,name,', 'telephones:id,telephone')
            ->firstOrFail();

            if($profileSlug) $checkIsFollowedUser = in_array($user->slug, $ConnectedFollowingSlugs);
            if($user->slug !== $profileSlug) {
                $stat = ProfileStatistique::firstOrCreate([
                    'user_id' => $user->id,
                    'date' => now()->toDateString(),
                ]);
                // Incrémentation du champ correspondant à l'action spécifiée
                $stat->increment('profile_views');
            }

            return response()->json([
                'success' => true,
                'type' => 'user',
                'data' => $user,
                'isFollowed' => $checkIsFollowedUser,
                'profileSlug' => $profileSlug,
            ], 200);
        } 
        catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun utilisateur ou établissement trouvé'
            ], 404);
        } 
        catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
