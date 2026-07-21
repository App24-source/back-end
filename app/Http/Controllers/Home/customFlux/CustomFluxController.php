<?php

namespace App\Http\Controllers\Home\customFlux;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CustomFlux;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Tags;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Home\customFlux\parts\CustomizeProfileFlux;
use Illuminate\Support\Str;

class CustomFluxController extends Controller
{
    private $currentProfileSlug = '';
    private $currentProfileType = '';

    public function create( Request $request )
    {
        try {
            $validator = Validator::make( $request->all(), [
                'name' => 'required|string|min:3|max:25',
                'profile' => 'required|string',
                'profileType' => 'required|string|in:user,etablissement',
                'tags_list' => 'nullable|array',
                'tags_list.*' => 'integer',
                'profiles_list' => 'nullable|array',
                'profiles_list.*.id' => 'required|integer',
                'profiles_list.*.type' => 'required|string|in:user,etablissement'
            ] );

            if ($validator->fails()) {
                return response()->json( ['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400 );
            }

            $profileId = $this->getProfileId( $request->input( 'profile' ), $request->input( 'profileType' ) );
            if (!$profileId) {
                return response()->json( ['status' => 404, 'message' => 'Profile not found'], 404 );
            }

            // Enforce maximum of 10 custom fluxes per profile (user or etablissement)
            $countForProfile = CustomFlux::where(
                $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id',
                $profileId
            )->count();
            if ($countForProfile >= 10) {
                return response()->json( [
                    'status' => 403,
                    'message' => 'Maximum custom flux reached (10). Delete one to create new ones.'
                ], 403 );
            }

            $name = trim( $request->input( 'name' ) );
            $slug = Str::slug( $name, '_' );
            if ($slug === '') {
                return response()->json( ['status' => 422, 'message' => 'Nom invalide. Utilisez des lettres/chiffres (latin) et espaces.'], 422 );
            }
            // Reject only if both name (case-insensitive) and base slug match an existing one for this profile
            $existingSameNameAndSlug = CustomFlux::where( $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id', $profileId )
                ->whereRaw( 'LOWER(name) = ?', [strtolower( $name )] )
                ->where( 'slug', $slug )
                ->exists();
            if ($existingSameNameAndSlug) {
                return response()->json( ['status' => 409, 'message' => 'Un flux avec ce nom existe déjà'], 409 );
            }
            // If slug collides for this profile, auto-suffix: slug_1, slug_2, ...
            if ($this->slugExistsForProfile( $slug, $profileId, $request->input( 'profileType' ) )) {
                $slug = $this->generateUniqueSlug( $slug, $profileId, $request->input( 'profileType' ) );
            }

            $customFlux = CustomFlux::create( [
                'name' => $name,
                $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id' => $profileId,
                'tags_list' => $request->input( 'tags_list', [] ),
                'profiles_list' => $request->input( 'profiles_list', [] ),
                'slug' => $slug
            ] );

            // Fetch publications as in index()
            $profileType = $request->input( 'profileType' );
            $profileSlug = $this->getProfileSlug( $profileId, $profileType );
            $page = 1; // always first page right after creation
            $result = \App\Http\Controllers\Home\customFlux\parts\CustomizeProfileFlux::getFilteredPublications(
                $profileSlug,
                $customFlux->tags_list,
                $customFlux->profiles_list,
                4, // default per page
                $page
            );

            return response()->json( [
                'status' => 201,
                'message' => 'Custom flux created successfully',
                'data' => [
                    'id' => $customFlux->id,
                    'name' => $customFlux->name,
                    'slug' => $customFlux->slug,
                    'tags_list' => $customFlux->tags_list,
                    'profiles_list' => $customFlux->profiles_list,
                    'created_at' => $customFlux->created_at
                ],
                'publications' => $result['data']['publications'] ?? null
            ], 201 );
        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'message' => 'Error creating custom flux', 'error' => $e->getMessage()], 500 );
        }
    }

    public function list( Request $request )
    {
        try {
            $validator = Validator::make( $request->all(), [
                'profile' => 'required|string',
                'profileType' => 'required|string|in:user,etablissement'
            ] );

            if ($validator->fails()) {
                return response()->json( ['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400 );
            }

            $profileId = $this->getProfileId( $request->input( 'profile' ), $request->input( 'profileType' ) );
            if (!$profileId) {
                return response()->json( ['status' => 404, 'message' => 'Profile not found'], 404 );
            }

            $customFluxes = CustomFlux::where( $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id', $profileId )
                ->orderBy( 'created_at', 'desc' )
                ->get();

            return response()->json( ['status' => 200, 'data' => ['custom_fluxes' => $customFluxes]] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'message' => 'Error fetching custom fluxes', 'error' => $e->getMessage()], 500 );
        }
    }

    public function getAvailableData( Request $request )
    {
        try {
            $profileId = $this->getProfileId( $request->input( 'profile' ), $request->input( 'profileType' ) );
            if (!$profileId) {
                return response()->json( ['status' => 404, 'message' => 'Profile not found'], 404 );
            }

            $topTags = $this->getTopTags( $profileId, $request->input( 'profileType' ) );
            $smartProfiles = $this->getSmartProfiles( $request->input( 'profile' ), $request->input( 'profileType' ) );

            // If a flux_slug is provided, return ONLY the flux's exact tags and profiles
            $fluxSlug = $request->input( 'flux_slug' );
            if (!empty( $fluxSlug )) {
                $customFlux = CustomFlux::where( 'slug', $fluxSlug )
                    ->where( $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id', $profileId )
                    ->first();

                if ($customFlux) {
                    // Hydrate selected tags (exact list)
                    $selectedTagIds = is_array( $customFlux->tags_list ) ? $customFlux->tags_list : [];
                    $selectedTags = [];
                    if (!empty( $selectedTagIds )) {
                        $orderExpr = "FIELD(id, " . implode( ',', array_map( 'intval', $selectedTagIds ) ) . ")";
                        $selectedTags = Tags::select( 'id', 'name', 'slug' )
                            ->whereIn( 'id', $selectedTagIds )
                            ->orderByRaw( $orderExpr )
                            ->get()
                            ->map( fn( $item ) => [
                                'id' => $item->id,
                                'name' => $item->name,
                                'slug' => $item->slug,
                                'compteur' => null
                            ] )
                            ->toArray();
                    }
                    $topTags = $selectedTags; // override with exact list

                    // Hydrate selected profiles (exact list)
                    $selectedProfilesList = is_array( $customFlux->profiles_list ) ? $customFlux->profiles_list : [];
                    $hydrated = [];
                    foreach ($selectedProfilesList as $p) {
                        if (!isset( $p['id'], $p['type'] )) {
                            continue;
                        }
                        if ($p['type'] === 'etablissement') {
                            $etab = Etablissement::select( 'id', 'nom', 'logo', 'slug', 'is_verified' )->find( $p['id'] );
                            if ($etab) {
                                $hydrated[] = [
                                    'id' => $etab->id,
                                    'nom' => $etab->nom ?? '',
                                    'logo' => $etab->logo ?? null,
                                    'slug' => $etab->slug ?? null,
                                    'is_verified' => $etab->is_verified ?? null,
                                    'type' => 'etablissement'
                                ];
                            }
                        } else {
                            $user = User::select( 'id', 'first_name', 'last_name', 'avatar', 'slug', 'is_verified' )->find( $p['id'] );
                            if ($user) {
                                $hydrated[] = [
                                    'id' => $user->id,
                                    'nom' => trim( ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? '' ) ),
                                    'logo' => $user->avatar ?? null,
                                    'slug' => $user->slug ?? null,
                                    'is_verified' => $user->is_verified ?? null,
                                    'type' => 'user'
                                ];
                            }
                        }
                    }
                    $smartProfiles = $hydrated; // override with exact list
                }
            }

            return response()->json( [
                'status' => 200,
                'data' => [
                    'tags' => $topTags,
                    'profiles' => $smartProfiles
                ]
            ] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'message' => 'Error fetching available data', 'error' => $e->getMessage()], 500 );
        }
    }

    private function getProfileId( $profileSlug, $profileType )
    {
        return $profileType === 'user'
            ? User::where( 'slug', $profileSlug )->value( 'id' )
            : Etablissement::where( 'slug', $profileSlug )->value( 'id' );
    }

    private function getProfileSlug( $profileId, $profileType )
    {
        return $profileType === 'user'
            ? User::where( 'id', $profileId )->value( 'slug' )
            : Etablissement::where( 'id', $profileId )->value( 'slug' );
    }

    private function isNameUnique( $name, $profileId, $profileType )
    {
        // Kept for backward compatibility; not used in create/update anymore
        return !CustomFlux::whereRaw( 'LOWER(name) = ?', [strtolower( $name )] )
            ->where( $profileType === 'user' ? 'user_id' : 'etab_id', $profileId )
            ->exists();
    }

    private function slugExistsForProfile( $slug, $profileId, $profileType, $excludeId = null )
    {
        $query = CustomFlux::where( 'slug', $slug )
            ->where( $profileType === 'user' ? 'user_id' : 'etab_id', $profileId );
        if ($excludeId) {
            $query->where( 'id', '!=', $excludeId );
        }
        return $query->exists();
    }

    private function generateUniqueSlug( $baseSlug, $profileId, $profileType, $excludeId = null )
    {
        $suffix = 1;
        $candidate = $baseSlug . '_' . $suffix;
        while ($this->slugExistsForProfile( $candidate, $profileId, $profileType, $excludeId )) {
            $suffix++;
            $candidate = $baseSlug . '_' . $suffix;
        }
        return $candidate;
    }

    private function getTopTags( $profileId, $profileType )
    {
        return \DB::table( 'user_tags_filters_flink as utf' )
            ->join( ( new Tags )->getTable() . ' as t', 'utf.tag_id', '=', 't.id' )
            ->select( 't.id', 't.name', 't.slug', \DB::raw( 'SUM(utf.compteur) as total_compteur' ) )
            ->where( 'utf.' . ( $profileType === 'user' ? 'user_id' : 'etab_id' ), $profileId )
            ->groupBy( 't.id', 't.name', 't.slug' )
            ->orderBy( 'total_compteur', 'desc' )
            ->limit( 5 )
            ->get()
            ->map( fn( $item ) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'compteur' => $item->total_compteur
            ] )
            ->toArray();
    }

    private function getSmartProfiles( $profileSlug, $profileType )
    {
        $profiles = [];
        $conversationProfiles = $this->getConversationProfiles( $profileSlug, $profileType );
        $followingProfiles = $this->getFollowingProfiles( $profileSlug, $profileType );

        // Strategy: 2 random from conversations + 3 random from following = 5 total
        $conversationCount = min( 2, count( $conversationProfiles ) );
        $followingCount = min( 3, count( $followingProfiles ) );

        // If we have conversations, take up to 2 random ones
        if ($conversationCount > 0) {
            shuffle( $conversationProfiles ); // Randomize conversations
            $profiles = array_merge( $profiles, array_slice( $conversationProfiles, 0, $conversationCount ) );
        }

        // If we have following, take up to 3 random ones
        if ($followingCount > 0) {
            shuffle( $followingProfiles ); // Randomize following
            $profiles = array_merge( $profiles, array_slice( $followingProfiles, 0, $followingCount ) );
        }

        // If we still need more profiles (less than 5), get suggestions
        if (count( $profiles ) < 5) {
            $suggestionProfiles = $this->getSuggestionProfiles( $profileSlug, $profileType );
            $needed = 5 - count( $profiles );
            $profiles = array_merge( $profiles, array_slice( $suggestionProfiles, 0, $needed ) );
        }

        return array_slice( $profiles, 0, 5 );
    }

    private function getConversationProfiles( $profileSlug, $profileType )
    {
        $entity = $profileType === 'user'
            ? User::where( 'slug', $profileSlug )->first()
            : Etablissement::where( 'slug', $profileSlug )->first();

        if (!$entity)
            return [];

        $currentId = $entity->id;
        $conversations = \App\Models\Conversation::where( function ( $q ) use ( $currentId ) {
            $q->where( 'sender_id', $currentId )->where( 'receiver_id', '!=', $currentId );
        } )
            ->orWhere( function ( $q ) use ( $currentId ) {
                $q->where( 'receiver_id', $currentId )->where( 'sender_id', '!=', $currentId );
            } )
            ->with( [
                'messages' => function ( $q ) {
                    $q->orderBy( 'created_at', 'desc' );
                }
            ] )
            ->orderBy( 'updated_at', 'desc' )
            ->limit( 50 )
            ->get();

        $conversationProfiles = [];
        $seenOtherIds = [];

        foreach ($conversations as $conv) {
            $otherId = ( $conv->sender_id == $currentId ) ? $conv->receiver_id : $conv->sender_id;
            if (isset( $seenOtherIds[$otherId] ))
                continue;

            $latest = $conv->messages->first();
            $otherType = $latest ?
                ( ( $latest->sender_id == $currentId ) ? $latest->type_receiver : $latest->type_sender ) :
                ( ( $conv->sender_id == $currentId ) ? $conv->type_receiver : $conv->type_sender );

            $other = $otherType === 'etablissement'
                ? Etablissement::find( $otherId )
                : User::find( $otherId );

            if (!$other)
                continue;

            $name = $otherType === 'etablissement'
                ? ( $other->nom ?? '' )
                : trim( ( $other->first_name ?? '' ) . ' ' . ( $other->last_name ?? '' ) );
            $avatar = $otherType === 'etablissement'
                ? ( $other->logo ?? null )
                : ( $other->avatar ?? null );

            $conversationProfiles[] = [
                'id' => $other->id,
                'nom' => $name,
                'logo' => $avatar,
                'slug' => $other->slug ?? null,
                'is_verified' => $other->is_verified ?? null,
                'type' => $otherType
            ];
            $seenOtherIds[$other->id] = true;
        }

        return $conversationProfiles;
    }

    private function getFollowingProfiles( $profileSlug, $profileType )
    {
        $entity = $profileType === 'user'
            ? User::where( 'slug', $profileSlug )->first()
            : Etablissement::where( 'slug', $profileSlug )->first();

        if (!$entity)
            return [];

        $followingUsers = $entity->followingUsers()->limit( 25 )->get();
        $followingEtablissements = $entity->followingEtablissements()->limit( 25 )->get();
        $following = $followingEtablissements->merge( $followingUsers );

        $followingProfiles = [];
        foreach ($following as $f) {
            $fType = ( $f instanceof Etablissement ) ? 'etablissement' : 'user';
            $name = $fType === 'etablissement'
                ? ( $f->nom ?? '' )
                : trim( ( $f->first_name ?? '' ) . ' ' . ( $f->last_name ?? '' ) );
            $avatar = $fType === 'etablissement'
                ? ( $f->logo ?? null )
                : ( $f->avatar ?? null );

            $followingProfiles[] = [
                'id' => $f->id,
                'nom' => $name,
                'logo' => $avatar,
                'slug' => $f->slug ?? null,
                'is_verified' => $f->is_verified ?? null,
                'type' => $fType
            ];
        }

        return $followingProfiles;
    }

    private function getSuggestionProfiles( $profileSlug, $profileType )
    {
        $entity = $profileType === 'user'
            ? User::where( 'slug', $profileSlug )->first()
            : Etablissement::where( 'slug', $profileSlug )->first();

        if (!$entity)
            return [];

        $usersQuery = User::select( 'id', 'first_name', 'last_name', 'avatar', 'slug', 'is_verified' )
            ->whereNot( 'slug', $profileSlug )
            ->whereDoesntHave( 'followersUsers', fn( $q ) => $q->where( 'users.id', $entity->id ) )
            ->whereDoesntHave( 'followersEtablissements', fn( $q ) => $q->where( 'etablissements.id', $entity->id ) )
            ->limit( 10 );

        $etablissementsQuery = Etablissement::select( 'id', 'nom', 'logo', 'slug', 'is_verified' )
            ->whereNot( 'slug', $profileSlug )
            ->whereDoesntHave( 'followersUsers', fn( $q ) => $q->where( 'users.id', $entity->id ) )
            ->whereDoesntHave( 'followersEtablissements', fn( $q ) => $q->where( 'etablissements.id', $entity->id ) )
            ->limit( 10 );

        $users = $usersQuery->get()->map( fn( $item ) => [
            'id' => $item->id,
            'nom' => trim( $item->first_name . ' ' . $item->last_name ),
            'logo' => $item->avatar,
            'slug' => $item->slug,
            'is_verified' => $item->is_verified,
            'type' => 'user'
        ] );

        $etablissements = $etablissementsQuery->get()->map( fn( $item ) => [
            'id' => $item->id,
            'nom' => $item->nom,
            'logo' => $item->logo,
            'slug' => $item->slug,
            'is_verified' => $item->is_verified,
            'type' => 'etablissement'
        ] );

        return $users->merge( $etablissements )->toArray();
    }

    /**
     * Search for tags and profiles by terms
     */
    public function termsSearch( Request $request )
    {
        $request->validate( [
            'query' => 'required|string|min:1|max:100',
            'profile' => 'required|string',
            'profileType' => 'required|string|in:user,etablissement'
        ] );

        $query = trim( $request->input( 'query' ) );
        $profileSlug = $request->input( 'profile' );
        $profileType = $request->input( 'profileType' );

        // Get profile ID
        $profileId = $this->getProfileId( $profileSlug, $profileType );
        if (!$profileId) {
            return response()->json( [
                'status' => 404,
                'message' => 'Profile not found'
            ], 404 );
        }

        // Split search terms
        $searchTerms = array_filter( array_map( 'trim', explode( ' ', $query ) ) );
        if (empty( $searchTerms )) {
            return response()->json( [
                'status' => 200,
                'data' => [
                    'tags' => [],
                    'profiles' => []
                ]
            ] );
        }

        // Store current profile info for sorting
        $this->currentProfileSlug = $profileSlug;
        $this->currentProfileType = $profileType;

        // Search tags (limit 4)
        $tags = $this->searchTags( $searchTerms, $profileId, $profileType );

        // Search profiles (limit 10)
        $profiles = $this->searchProfiles( $searchTerms );

        return response()->json( [
            'status' => 200,
            'data' => [
                'tags' => $tags,
                'profiles' => $profiles
            ]
        ] );
    }

    /**
     * Search tags by terms
     */
    private function searchTags( $searchTerms, $profileId, $profileType )
    {
        $tagsQuery = \DB::table( 'user_tags_filters_flink as utf' )
            ->join( ( new Tags )->getTable() . ' as t', 'utf.tag_id', '=', 't.id' )
            ->select( 't.id', 't.name', 't.slug', 't.compteur_publications' )
            ->where( 'utf.' . ( $profileType === 'user' ? 'user_id' : 'etab_id' ), $profileId );

        // Build search conditions for tags
        $tagsQuery->where( function ( $q ) use ( $searchTerms ) {
            foreach ($searchTerms as $term) {
                $q->orWhere( 't.name', 'LIKE', "%{$term}%" )
                    ->orWhere( 't.slug', 'LIKE', "%{$term}%" );

                // Split slug by dashes and search individual parts
                $slugParts = explode( '-', $term );
                foreach ($slugParts as $part) {
                    if (strlen( $part ) > 2) {
                        $q->orWhere( 't.slug', 'LIKE', "%{$part}%" );
                    }
                }
            }
        } );

        $results = $tagsQuery->groupBy( 't.id', 't.name', 't.slug', 't.compteur_publications' )
            ->orderBy( 't.compteur_publications', 'desc' )
            ->orderBy( 't.name' )
            ->limit( 10 ) // Get more results to sort properly
            ->get()
            ->map( fn( $item ) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug
            ] )
            ->toArray();

        // Sort results to prioritize exact matches
        $sortedResults = $this->sortTagsByExactMatch( $results, $searchTerms );

        // Return only top 4 results
        return array_slice( $sortedResults, 0, 4 );
    }

    /**
     * Sort tags to prioritize exact matches
     */
    private function sortTagsByExactMatch( $tags, $searchTerms )
    {
        return collect( $tags )->sort( function ( $a, $b ) use ( $searchTerms ) {
            $aExactMatch = $this->hasExactMatch( $a, $searchTerms );
            $bExactMatch = $this->hasExactMatch( $b, $searchTerms );

            // If one has exact match and other doesn't, prioritize exact match
            if ($aExactMatch && !$bExactMatch)
                return -1;
            if (!$aExactMatch && $bExactMatch)
                return 1;

            // If both have exact matches or both don't, maintain original order
            return 0;
        } )->values()->toArray();
    }

    /**
     * Check if a tag has an exact match with any search term
     */
    private function hasExactMatch( $tag, $searchTerms )
    {
        $tagNameLower = strtolower( $tag['name'] );
        $tagSlugLower = strtolower( $tag['slug'] );

        foreach ($searchTerms as $term) {
            $termLower = strtolower( $term );

            // Check exact match with tag name
            if ($tagNameLower === $termLower) {
                return true;
            }

            // Check exact match with tag slug
            if ($tagSlugLower === $termLower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search profiles by terms
     */
    private function searchProfiles( $searchTerms )
    {
        $usersQuery = \DB::table( 'users as u' )
            ->select( 'u.id', 'u.first_name', 'u.last_name', 'u.avatar as logo', 'u.slug', 'u.is_verified' );

        $etablissementsQuery = \DB::table( 'etablissements as e' )
            ->select( 'e.id', 'e.nom as first_name', \DB::raw( "'' as last_name" ), 'e.logo', 'e.slug', 'e.is_verified' );

        // Build search conditions for users
        $usersQuery->where( function ( $q ) use ( $searchTerms ) {
            foreach ($searchTerms as $term) {
                // Search in names
                $q->orWhere( 'first_name', 'LIKE', "%{$term}%" )
                    ->orWhere( 'last_name', 'LIKE', "%{$term}%" )
                    ->orWhere( 'slug', 'LIKE', "%{$term}%" );

                // Split slug by underscores and search individual parts
                $slugParts = explode( '_', $term );
                foreach ($slugParts as $part) {
                    if (strlen( $part ) > 2) {
                        $q->orWhere( 'slug', 'LIKE', "%{$part}%" )
                            ->orWhere( 'first_name', 'LIKE', "%{$part}%" )
                            ->orWhere( 'last_name', 'LIKE', "%{$part}%" );
                    }
                }
            }
        } );

        // Build search conditions for establishments
        $etablissementsQuery->where( function ( $q ) use ( $searchTerms ) {
            foreach ($searchTerms as $term) {
                // Search in nom (establishment name)
                $q->orWhere( 'nom', 'LIKE', "%{$term}%" )
                    ->orWhere( 'slug', 'LIKE', "%{$term}%" );

                // Split slug by underscores and search individual parts
                $slugParts = explode( '_', $term );
                foreach ($slugParts as $part) {
                    if (strlen( $part ) > 2) {
                        $q->orWhere( 'slug', 'LIKE', "%{$part}%" )
                            ->orWhere( 'nom', 'LIKE', "%{$part}%" );
                    }
                }
            }
        } );

        $users = $usersQuery->get()->map( fn( $item ) => [
            'id' => $item->id,
            'nom' => trim( $item->first_name . ' ' . $item->last_name ),
            'logo' => $item->logo,
            'slug' => $item->slug,
            'is_verified' => $item->is_verified,
            'type' => 'user'
        ] );

        $etablissements = $etablissementsQuery->get()->map( fn( $item ) => [
            'id' => $item->id,
            'nom' => $item->first_name,
            'logo' => $item->logo,
            'slug' => $item->slug,
            'is_verified' => $item->is_verified,
            'type' => 'etablissement'
        ] );

        $allProfiles = $users->merge( $etablissements );

        // Smart sorting: conversations/following first, then suggestions
        $sortedProfiles = $this->sortProfilesByRelevance( $allProfiles );

        return $sortedProfiles->take( 10 )->values()->toArray();
    }

    /**
     * Sort profiles by relevance (conversations/following first, then suggestions with scoring)
     */
    private function sortProfilesByRelevance( $profiles )
    {
        // Get conversation and following profiles for the current user
        $conversationProfiles = $this->getConversationProfiles( $this->getCurrentProfileSlug(), $this->getCurrentProfileType() );
        $followingProfiles = $this->getFollowingProfiles( $this->getCurrentProfileSlug(), $this->getCurrentProfileType() );
        $suggestionProfiles = $this->getSuggestionProfiles( $this->getCurrentProfileSlug(), $this->getCurrentProfileType() );

        // Create arrays of IDs for quick lookup
        $conversationIds = array_column( $conversationProfiles, 'id' );
        $followingIds = array_column( $followingProfiles, 'id' );
        $suggestionIds = array_column( $suggestionProfiles, 'id' );

        return $profiles->sort( function ( $a, $b ) use ( $conversationIds, $followingIds, $suggestionIds ) {
            $aInConversations = in_array( $a['id'], $conversationIds );
            $aInFollowing = in_array( $a['id'], $followingIds );
            $aInSuggestions = in_array( $a['id'], $suggestionIds );
            $aIsVerified = !empty( $a['is_verified'] );

            $bInConversations = in_array( $b['id'], $conversationIds );
            $bInFollowing = in_array( $b['id'], $followingIds );
            $bInSuggestions = in_array( $b['id'], $suggestionIds );
            $bIsVerified = !empty( $b['is_verified'] );

            // Priority 1: Conversations
            if ($aInConversations && !$bInConversations)
                return -1;
            if (!$aInConversations && $bInConversations)
                return 1;

            // If both in conversations, prioritize verified
            if ($aInConversations && $bInConversations) {
                if ($aIsVerified && !$bIsVerified)
                    return -1;
                if (!$aIsVerified && $bIsVerified)
                    return 1;
                return strcmp( $a['nom'], $b['nom'] );
            }

            // Priority 2: Following
            if ($aInFollowing && !$bInFollowing && !$aInConversations && !$bInConversations)
                return -1;
            if (!$aInFollowing && $bInFollowing && !$aInConversations && !$bInConversations)
                return 1;

            // If both in following, prioritize verified
            if ($aInFollowing && $bInFollowing && !$aInConversations && !$bInConversations) {
                if ($aIsVerified && !$bIsVerified)
                    return -1;
                if (!$aIsVerified && $bIsVerified)
                    return 1;
                return strcmp( $a['nom'], $b['nom'] );
            }

            // Priority 3: Suggestions with scoring
            if ($aInSuggestions && !$bInSuggestions && !$aInConversations && !$bInConversations && !$aInFollowing && !$bInFollowing)
                return -1;
            if (!$aInSuggestions && $bInSuggestions && !$aInConversations && !$bInConversations && !$aInFollowing && !$bInFollowing)
                return 1;

            // If both in suggestions, use scoring system
            if ($aInSuggestions && $bInSuggestions && !$aInConversations && !$bInConversations && !$aInFollowing && !$bInFollowing) {
                $aScore = $this->calculateProfileScore( $a );
                $bScore = $this->calculateProfileScore( $b );

                if ($aScore !== $bScore) {
                    return $bScore - $aScore; // Higher score first
                }
                return strcmp( $a['nom'], $b['nom'] );
            }

            // Priority 4: Other profiles (not in conversations, following, or suggestions)
            if (!$aInConversations && !$bInConversations && !$aInFollowing && !$bInFollowing && !$aInSuggestions && !$bInSuggestions) {
                // Prioritize verified profiles
                if ($aIsVerified && !$bIsVerified)
                    return -1;
                if (!$aIsVerified && $bIsVerified)
                    return 1;
                return strcmp( $a['nom'], $b['nom'] );
            }

            // Default: Alphabetical by name
            return strcmp( $a['nom'], $b['nom'] );
        } );
    }

    /**
     * Calculate profile score for suggestions
     */
    private function calculateProfileScore( $profile )
    {
        $score = 0;

        // Verified profiles get highest priority
        if (!empty( $profile['is_verified'] )) {
            $score += 1000;
        }

        // Add other scoring factors here if needed
        // For example: follower count, activity level, etc.

        return $score;
    }

    /**
     * Get current profile slug and type for sorting
     */
    private function getCurrentProfileSlug()
    {
        // This should be passed from the calling method
        return $this->currentProfileSlug ?? '';
    }

    private function getCurrentProfileType()
    {
        // This should be passed from the calling method
        return $this->currentProfileType ?? '';
    }

    /**
     * Get filtered publications for a custom flux
     */
    public function index( Request $request )
    {
        try {
            $validator = Validator::make( $request->all(), [
                'profile_id' => 'required|integer',
                'profileType' => 'required|string|in:user,etablissement',
                'flux_slug' => 'required|string',
                'page' => 'nullable|integer|min:1'
            ] );

            if ($validator->fails()) {
                return response()->json( ['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400 );
            }

            $profileId = $request->input( 'profile_id' );
            $profileType = $request->input( 'profileType' );
            $fluxSlug = $request->input( 'flux_slug' );
            $page = $request->input( 'page', 1 );

            // Find the custom flux by slug and profile ID
            $customFlux = CustomFlux::where( 'slug', $fluxSlug )
                ->where( $profileType === 'user' ? 'user_id' : 'etab_id', $profileId )
                ->first();

            if (!$customFlux) {
                return response()->json( ['status' => 404, 'message' => 'Custom flux not found'], 404 );
            }

            // Get profile slug for the external function
            $profileSlug = $this->getProfileSlug( $profileId, $profileType );
            if (!$profileSlug) {
                return response()->json( ['status' => 404, 'message' => 'Profile not found'], 404 );
            }

            // Use the external function to get filtered publications
            $result = CustomizeProfileFlux::getFilteredPublications(
                $profileSlug,
                $customFlux->tags_list,
                $customFlux->profiles_list,
                4, // Default per page
                $page   // Use the page parameter
            );

            return response()->json( $result, $result['status'] );

        } catch (\Exception $e) {
            return response()->json( [
                'status' => 500,
                'message' => 'Error retrieving custom flux publications',
                'error' => $e->getMessage()
            ], 500 );
        }
    }

    /**
     * Update a custom flux
     */
    public function update( Request $request )
    {
        try {
            $validator = Validator::make( $request->all(), [
                'flux_slug' => 'required|string',
                'profile' => 'required|string',
                'profileType' => 'required|string|in:user,etablissement',
                'name' => 'required|string|min:3|max:25',
                'tags_list' => 'nullable|array',
                'tags_list.*' => 'integer',
                'profiles_list' => 'nullable|array',
                'profiles_list.*.id' => 'required|integer',
                'profiles_list.*.type' => 'required|string|in:user,etablissement'
            ] );

            if ($validator->fails()) {
                return response()->json( ['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400 );
            }

            $profileId = $this->getProfileId( $request->input( 'profile' ), $request->input( 'profileType' ) );
            if (!$profileId) {
                return response()->json( ['status' => 404, 'message' => 'Profile not found'], 404 );
            }

            $fluxSlug = $request->input( 'flux_slug' );
            $name = trim( $request->input( 'name' ) );
            $slug = Str::slug( $name, '_' );
            if ($slug === '') {
                return response()->json( ['status' => 422, 'message' => 'Nom invalide. Utilisez des lettres/chiffres (latin) et espaces.'], 422 );
            }

            // Find the custom flux
            $customFlux = CustomFlux::where( 'slug', $fluxSlug )
                ->where( $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id', $profileId )
                ->first();

            if (!$customFlux) {
                return response()->json( ['status' => 404, 'message' => 'Custom flux not found'], 404 );
            }

            // Reject only if both name (case-insensitive) and base slug match an existing one (excluding current)
            $existingSameNameAndSlug = CustomFlux::where( $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id', $profileId )
                ->whereRaw( 'LOWER(name) = ?', [strtolower( $name )] )
                ->where( 'slug', $slug )
                ->where( 'id', '!=', $customFlux->id )
                ->exists();
            if ($existingSameNameAndSlug) {
                return response()->json( ['status' => 409, 'message' => 'Un flux avec ce nom existe déjà'], 409 );
            }
            // If slug collides for this profile (excluding current), auto-suffix
            if ($this->slugExistsForProfile( $slug, $profileId, $request->input( 'profileType' ), $customFlux->id )) {
                $slug = $this->generateUniqueSlug( $slug, $profileId, $request->input( 'profileType' ), $customFlux->id );
            }

            // Update the custom flux
            $customFlux->update( [
                'name' => $name,
                'tags_list' => $request->input( 'tags_list', [] ),
                'profiles_list' => $request->input( 'profiles_list', [] ),
                'slug' => $slug
            ] );

            return response()->json( [
                'status' => 200,
                'message' => 'Custom flux updated successfully',
                'data' => [
                    'id' => $customFlux->id,
                    'name' => $customFlux->name,
                    'slug' => $customFlux->slug,
                    'tags_list' => $customFlux->tags_list,
                    'profiles_list' => $customFlux->profiles_list,
                    'updated_at' => $customFlux->updated_at
                ]
            ] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'message' => 'Error updating custom flux', 'error' => $e->getMessage()], 500 );
        }
    }

    /**
     * Delete a custom flux
     */
    public function delete( Request $request )
    {
        try {
            $validator = Validator::make( $request->all(), [
                'flux_slug' => 'required|string',
                'profile' => 'required|string',
                'profileType' => 'required|string|in:user,etablissement'
            ] );

            if ($validator->fails()) {
                return response()->json( ['status' => 400, 'message' => 'Validation failed', 'errors' => $validator->errors()], 400 );
            }

            $profileId = $this->getProfileId( $request->input( 'profile' ), $request->input( 'profileType' ) );
            if (!$profileId) {
                return response()->json( ['status' => 404, 'message' => 'Profile not found'], 404 );
            }

            $fluxSlug = $request->input( 'flux_slug' );

            // Find and delete the custom flux
            $customFlux = CustomFlux::where( 'slug', $fluxSlug )
                ->where( $request->input( 'profileType' ) === 'user' ? 'user_id' : 'etab_id', $profileId )
                ->first();

            if (!$customFlux) {
                return response()->json( ['status' => 404, 'message' => 'Custom flux not found'], 404 );
            }

            $customFlux->delete();

            return response()->json( [
                'status' => 200,
                'message' => 'Custom flux deleted successfully'
            ] );

        } catch (\Exception $e) {
            return response()->json( ['status' => 500, 'message' => 'Error deleting custom flux', 'error' => $e->getMessage()], 500 );
        }
    }
}
