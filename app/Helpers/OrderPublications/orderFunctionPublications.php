<?php
use App\Models\Tags;
use App\Models\Publication;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use App\Models\Region;
use App\Models\Pays;
use App\Models\Ville;
use App\Models\PublicationBooster;
use Illuminate\Support\Str;

if (!function_exists( 'orderFunctionPublications' )) {
    function getOrderedPublications( $publications, $sauvgardedPublications, $clickedPublications, $userTags )
    {
        // Convert arrays to collections for easier manipulation
        $publications = collect( $publications );
        $clickedPublications = collect( $clickedPublications );
        $sauvgardedPublications = collect( $sauvgardedPublications );
        $userTags = collect( $userTags );

        // Extract IDs for faster filtering
        $savedIds = $sauvgardedPublications->pluck( 'id' )->toArray();
        $clickedIds = $clickedPublications->pluck( 'id' )->toArray();
        $userTagIds = $userTags->pluck( 'id' )->toArray();

        // Get saved publications
        $saved = $publications->whereIn( 'id', $savedIds )->shuffle()->take( 4 );

        // Get clicked publications (excluding saved)
        $clicked = $publications->whereIn( 'id', $clickedIds )
            ->whereNotIn( 'id', $saved->pluck( 'id' )->toArray() )
            ->shuffle()
            ->take( 4 );

        // Get other relevant publications based on user tags
        $othersWithUserTags = $publications->whereNotIn( 'id', $saved->pluck( 'id' )->toArray() )
            ->whereNotIn( 'id', $clicked->pluck( 'id' )->toArray() )
            ->filter( function ( $publication ) use ( $userTagIds ) {
                return collect( $publication->tags )->pluck( 'id' )->intersect( $userTagIds )->isNotEmpty();
            } )
            ->shuffle()
            ->take( 4 );

        // Merge all selected publications
        $finalSelection = $saved->merge( $clicked )->merge( $othersWithUserTags );

        // Fill up to 12 publications if needed
        if ($finalSelection->count() < 12) {
            $remaining = $publications->whereNotIn( 'id', $finalSelection->pluck( 'id' )->toArray() )->shuffle();
            $finalSelection = $finalSelection->merge( $remaining )->take( 12 );
        }

        return $finalSelection->values();
    }
}

if (!function_exists( 'getTagsOrdrer' )) {
    function getTagsOrdrer( $AllPopular_tags, $AllSimilar_tags, $AllUser_tags, $search, $nombreTags, $tagsToExclude = null )
    {

        if ($AllPopular_tags && !$AllUser_tags && $AllSimilar_tags) {
            $popular_tags = $AllPopular_tags->take( $nombreTags / 2 );
            $similar_tags = $AllSimilar_tags->take( $nombreTags / 2 );
            // Merge all tags and remove duplicates
            $merged_tags = $popular_tags->merge( $similar_tags )->unique( 'id' );

            // If fewer than $nombreTags tags, fetch extra popular tags to complete the list
            if ($merged_tags->count() < $nombreTags) {
                $needed_tags = $nombreTags - $merged_tags->count();

                $extra_popular_tags = Tags::select( 'id', 'name', 'slug' )
                    ->where( 'status', 1 );
                if ($search)
                    $extra_popular_tags = $extra_popular_tags->whereNot( 'slug', $search );
                $extra_popular_tags = $extra_popular_tags->whereHas( 'publications', function ( $query ) {
                    $query->where( 'status', 1 );
                } )
                    ->whereNotIn( 'id', $merged_tags->pluck( 'id' ) ) // Avoid duplicates
                    ->orderByDesc( 'compteur_publications' )
                    ->limit( $needed_tags )
                    ->get();

                $merged_tags = $merged_tags->merge( $extra_popular_tags );
            }
        } else if ($AllPopular_tags && !$AllSimilar_tags && $AllUser_tags) {
            $popular_tags = $AllPopular_tags->take( $nombreTags / 2 );
            $user_tags = $AllUser_tags->take( $nombreTags / 2 );
            // Merge all tags and remove duplicates
            $merged_tags = $popular_tags->merge( $user_tags )->unique( 'id' );

            // If fewer than $nombreTags tags, fetch extra popular tags to complete the list
            if ($merged_tags->count() < $nombreTags) {
                $needed_tags = $nombreTags - $merged_tags->count();

                $extra_popular_tags = Tags::select( 'id', 'name', 'slug' )
                    ->where( 'status', 1 );
                if ($search)
                    $extra_popular_tags = $extra_popular_tags->whereNot( 'slug', $search );
                $extra_popular_tags = $extra_popular_tags->whereHas( 'publications', function ( $query ) {
                    $query->where( 'status', 1 );
                } )
                    ->whereNotIn( 'id', $merged_tags->pluck( 'id' ) ) // Avoid duplicates
                    ->orderByDesc( 'compteur_publications' )
                    ->limit( $needed_tags )
                    ->get();

                $merged_tags = $merged_tags->merge( $extra_popular_tags );
            }
        } else if ($AllPopular_tags && !$AllSimilar_tags && !$AllUser_tags) {
            return $AllPopular_tags->take( $nombreTags );
        } else if ($AllPopular_tags && $AllSimilar_tags && $AllUser_tags) {
            $popular_tags = $AllPopular_tags->take( $nombreTags / 3 );
            $similar_tags = $AllSimilar_tags->take( $nombreTags / 3 );
            $user_tags = $AllUser_tags->take( $nombreTags / 3 );
            // Merge all tags and remove duplicates
            $merged_tags = $popular_tags->merge( $similar_tags )->merge( $user_tags )->unique( 'id' );

            // If fewer than $nombreTags tags, fetch extra popular tags to complete the list
            if ($merged_tags->count() < $nombreTags) {
                $needed_tags = $nombreTags - $merged_tags->count();
                $extra_popular_tags = Tags::select( 'id', 'name', 'slug' )
                    ->where( 'status', 1 );
                if ($search)
                    $extra_popular_tags = $extra_popular_tags->whereNot( 'slug', $search );
                $extra_popular_tags = $extra_popular_tags->whereHas( 'publications', function ( $query ) {
                    $query->where( 'status', 1 );
                } )
                    ->whereNotIn( 'id', $merged_tags->pluck( 'id' ) ) // Avoid duplicates
                    ->orderByDesc( 'compteur_publications' )
                    ->limit( $needed_tags )
                    ->get();

                $merged_tags = $merged_tags->merge( $extra_popular_tags );
            }
        }
        return $merged_tags;
    }

}

if (!function_exists( 'getDynamicPublications' )) {
    function getDynamicPublications( $publications, $profileObject )
    {
        // Define default values for the variables in case profileObject is null
        $sauvgardedPublications = [];
        $clickedPublications = [];
        $userTags = [];
        $userFollowersUsers = [];
        $userFollowersEtablissements = [];

        if ($profileObject) {
            // Now, this block will only execute if profileObject is not null
            // Retrieve the user's tags (sorted by updated_at)
            $userTags = $profileObject->tags_filters()
                ->where( 'status', 1 )
                ->whereNot( 'slug', 'marketplace' )
                ->orderByPivot( 'updated_at', 'desc' )
                ->get()
                ->pluck( 'id' )
                ->unique()
                ->toArray();

            // Initialize a variable to store the final list of publications
            $finalPublicationsUserTagsFirst = collect();

            // Loop through tags and gather publications until we have 8 distinct ones
            foreach ($userTags as $tagId) {
                // Retrieve publications related to the current tag
                $publicationsTag = $publications->filter( function ( $publication ) use ( $tagId ) {
                    return !empty( array_intersect( $publication->tags->pluck( 'id' )->toArray(), [$tagId] ) );
                } );

                // Add these publications to the final list and keep unique ones by ID
                $finalPublicationsUserTagsFirst = $finalPublicationsUserTagsFirst->merge( $publicationsTag );

                // Stop once we have at least 8 distinct publications
                if ($finalPublicationsUserTagsFirst->unique( 'id' )->count() >= 8) {
                    break;
                }
            }
            // Ensure that you take exactly 8 distinct publications
            $publicationsUserTags = $finalPublicationsUserTagsFirst->unique( 'id' )->sortByDesc( 'score' )->take( 8 )->values();

            $sauvgardedPublications = $profileObject->publications_sauvgarde()->orderByPivot( 'updated_at', 'desc' )->get()->pluck( 'id' )->toArray();
            $clickedPublications = $profileObject->clicked_publications()->orderByPivot( 'updated_at', 'desc' )->get()->pluck( 'id' )->toArray();

            $userFollowersUsers = $profileObject->followingUsers->pluck( 'id' )->toArray();
            $userFollowersEtablissements = $profileObject->followingEtablissements->pluck( 'id' )->toArray();
        }

        // Define a minimum score to prevent division by zero
        $minScore = 0.1;

        // Sort the publications by preference (sauvgarde, clicked, tags) based on their score
        $preferredPublications = $publications->filter( function ( $publication ) use ( $sauvgardedPublications, $clickedPublications, $userTags, $publicationsUserTags ) {
            return ( in_array( $publication->id, $sauvgardedPublications ) ||
                in_array( $publication->id, $clickedPublications ) ||
                !empty( array_intersect( $publication->tags->pluck( 'id' )->toArray(), $userTags ) ) ) && !in_array( $publication->id, $publicationsUserTags->pluck( 'id' )->toArray() );
        } )->sortByDesc( 'score' ); // Sort by score, high score first

        // Sort the publications by followers (users and establishments) based on their score
        $followersPublications = $publications->filter( function ( $publication ) use ( $userFollowersUsers, $userFollowersEtablissements, $publicationsUserTags ) {
            return ( in_array( $publication->user_id, $userFollowersUsers ) || in_array( $publication->etab_id, $userFollowersEtablissements ) ) &&
                !in_array( $publication->id, $publicationsUserTags->pluck( 'id' )->toArray() );
        } )->sortByDesc( 'score' ); // Sort by score, high score first

        // Get the remaining publications (chance) based on their score
        $chancePublications = $publications->reject( function ( $publication ) use ( $publicationsUserTags, $preferredPublications, $followersPublications ) {
            return in_array( $publication->id, $publicationsUserTags->pluck( 'id' )->toArray() ) ||
                in_array( $publication->id, $preferredPublications->pluck( 'id' )->toArray() ) ||
                in_array( $publication->etab_id, $followersPublications->pluck( 'id' )->toArray() );
        } )->sortByDesc( 'score' ); // Sort by score, high score first

        // Function to shuffle publications with a bias towards low scores occasionally
        $shuffleWithBias = function ( $publications ) {
            return $publications->map( function ( $publication ) {
                // Generate a random number, and slightly decrease the chances of higher scored publications appearing first
                $biasedScore = rand( 1, 100 ) / max( $publication->score, 0.1 ); // Avoid dividing by 0
                $publication->random_weight = $biasedScore;
                return $publication;
            } )->sortBy( 'random_weight' );
        };

        // Shuffle within each category to introduce some randomness
        $publicationsUserTags = $shuffleWithBias( $publicationsUserTags );
        $preferredPublications = $shuffleWithBias( $preferredPublications );
        $followersPublications = $shuffleWithBias( $followersPublications );
        $chancePublications = $shuffleWithBias( $chancePublications );

        // Combine the publications: 8 from preferences, 8 from followers, 8 from chance
        $orderedPublications = collect();
        $orderedPublications = $orderedPublications->merge( $publicationsUserTags );

        // return $orderedPublications;

        // Initialize the number of publications to take from each category
        $remainingPreferred = $preferredPublications;
        $remainingFollowers = $followersPublications;
        $remainingChance = $chancePublications;

        while ($remainingPreferred->isNotEmpty() || $remainingFollowers->isNotEmpty() || $remainingChance->isNotEmpty()) {
            // Add 4 from preferences if available
            if ($remainingPreferred->isNotEmpty()) {
                $orderedPublications = $orderedPublications->merge( $remainingPreferred->take( 4 ) );
                $remainingPreferred = $remainingPreferred->slice( 4 );
            }

            // Add 4 from followers if available
            if ($remainingFollowers->isNotEmpty()) {
                $orderedPublications = $orderedPublications->merge( $remainingFollowers->take( 4 ) );
                $remainingFollowers = $remainingFollowers->slice( 4 );
            }

            // Add 8 from chance if available
            if ($remainingChance->isNotEmpty()) {
                $orderedPublications = $orderedPublications->merge( $remainingChance->take( 8 ) );
                $remainingChance = $remainingChance->slice( 8 );
            }
        }

        // Ensure no consecutive publications from the same user or establishment
        $finalPublications = collect();
        $lastUserId = null;
        $lastEtabId = null;

        foreach ($orderedPublications as $publication) {
            // Delay adding the publication if it belongs to the same user or establishment as the last added one
            if (( $publication->user_id == $lastUserId || $publication->etab_id == $lastEtabId ) && $finalPublications->count() > 0) {
                // Skip this publication until we can insert it after 2 to 4 other publications
                $nextPublication = $publication;
                $finalPublications->push( $nextPublication );
            } else {
                // Add the publication
                $finalPublications->push( $publication );
                $lastUserId = $publication->user_id;
                $lastEtabId = $publication->etab_id;
            }
        }

        return $finalPublications;
    }

}

if (!function_exists( 'getDynamicPublicationsWithoutPagination' )) {
    function getDynamicPublicationsWithoutPagination( $publications, $profileObject )
    {
        if ($profileObject) {
            $sauvgardedPublications = $profileObject->publications_sauvgarde()->orderByPivot( 'updated_at', 'desc' )->get()->pluck( 'id' )->toArray();
            $clickedPublications = $profileObject->clicked_publications()->orderByPivot( 'updated_at', 'desc' )->get()->pluck( 'id' )->toArray();
            $userTags = $profileObject->tags_filters()->where( 'status', 1 )->orderByPivot( 'updated_at', 'desc' )->limit( 8 )->get()->pluck( 'id' )->toArray();

            $userFollowersUsers = $profileObject->followersUsers->pluck( 'id' )->toArray();
            $userFollowersEtablissements = $profileObject->followersEtablissements->pluck( 'id' )->toArray();
        }

        // Define a minimum score to prevent division by zero
        $minScore = 0.1;

        // Assign a random weight based on the score
        $shuffledPublications = $publications->map( function ( $publication ) use ( $minScore ) {
            $effectiveScore = max( $publication->score, $minScore );
            $publication->random_weight = rand( 1, 100 ) / $effectiveScore;
            return $publication;
        } );

        // // Assign a random weight based on the score
        // $shuffledPublications = $publications->map(function ($publication) use ($minScore, $profileObject, $sauvgardedPublications, $clickedPublications, $userTags, $userFollowersUsers, $userFollowersEtablissements) {
        //     // Ensure the score is at least the minimum value
        //     $effectiveScore = max($publication->score, $minScore);

        //     if($profileObject) {
        //         // $maxRand = 100;
        //         $weightPreferences = 100;
        //         if(
        //             in_array($publication->id, $sauvgardedPublications) 
        //             || 
        //             in_array($publication->id, $clickedPublications) 
        //             // || 
        //             // array_intersect($publication->tags->pluck('id')->toArray(), $userTags)
        //         ) {
        //             $weightPreferences = 0.1;
        //         }
        //         // if(in_array($publication->id, $sauvgardedPublications)) $maxRand -= 24;
        //         // if(in_array($publication->id, $clickedPublications)) $maxRand -= 20;
        //         // if(array_intersect($publication->tags()->pluck('id')->toArray(), $userTags)) $maxRand -= 16;

        //         if(in_array($publication->user_id, $userFollowersUsers) || in_array($publication->etab_id, $userFollowersEtablissements)) $weightPreferences = 0.2;

        //         if($weightPreferences != 100) {
        //             // Generate a weighted random value (lower values appear first)
        //             $publication->random_weight = $weightPreferences;
        //         } else {
        //             // Generate a weighted random value (lower values appear first)
        //             $publication->random_weight = rand(1, 100) / $effectiveScore;
        //             // $publication->random_weight = 100;
        //         }


        //     } else {
        //         // Generate a weighted random value (lower values appear first)
        //         $publication->random_weight = rand(1, 100) / $effectiveScore;
        //     }



        //     return $publication;
        // });

        // Sort dynamically
        $orderedPublications = $shuffledPublications->sortBy( 'random_weight' )->values();

        return $orderedPublications;
    }


}


if (!function_exists( 'getDynamicPublicationsOptimized' )) {
    function getDynamicPublicationsOptimized( $publications, $profileObject )
    {
        // Define default values for the variables in case profileObject is null
        $sauvgardedPublications = [];
        $clickedPublications = [];
        $userTags = [];
        $userFollowersUsers = [];
        $userFollowersEtablissements = [];
        $publicationsUserTags = collect();
        $lastPublicationVisited = collect();

        $getFirst8UniquePublications = function ( $publications ) {
            $selectedPublications = collect();  // To store the selected publications
            $usedUserIds = collect();  // To track used user_ids
            $usedEtabIds = collect();  // To track used etab_ids

            foreach ($publications as $publication) {
                // Check if this publication has a unique combination of user_id and etab_id
                if ($publication->etab_id && !$usedEtabIds->contains( $publication->etab_id )) {
                    // If it's unique, add it to the selected list
                    $selectedPublications->push( $publication );

                    // Mark this user_id and etab_id as used
                    // $usedUserIds->push($publication->user_id);
                    $usedEtabIds->push( $publication->etab_id );
                } else if (!$publication->etab_id && !$usedUserIds->contains( $publication->user_id )) {
                    // If it's unique, add it to the selected list
                    $selectedPublications->push( $publication );

                    // Mark this user_id and etab_id as used
                    $usedUserIds->push( $publication->user_id );
                    // $usedEtabIds->push($publication->etab_id);
                }

                // Stop if we've selected 8 publications
                if ($selectedPublications->count() == 8) {
                    break;
                }
            }

            // Return the selected publications (up to 8)
            return $selectedPublications;
        };

        if ($profileObject) {
            // Now, this block will only execute if profileObject is not null
            // Retrieve the user's tags (sorted by updated_at)
            $userTags = $profileObject->tags_filters()
                ->where( 'status', 1 )
                ->whereNot( 'slug', 'marketplace' )
                ->orderByPivot( 'updated_at', 'desc' )
                ->get()
                ->pluck( 'id' )
                ->unique()
                ->toArray();

            // return $userTags;


            // Initialize a variable to store the final list of publications
            $finalPublicationsUserTagsFirst = collect();

            // Loop through tags and gather publications until we have 8 distinct ones
            foreach ($userTags as $tagId) {
                // Retrieve publications related to the current tag
                $publicationsTag = $publications->filter( function ( $publication ) use ( $tagId ) {
                    return !empty( array_intersect( $publication->tags->pluck( 'id' )->toArray(), [$tagId] ) );
                } );

                // Add these publications to the final list and keep unique ones by ID
                $finalPublicationsUserTagsFirst = $finalPublicationsUserTagsFirst->merge( $publicationsTag )->unique( 'id' );
                // $finalPublicationsUserTagsFirst = $getFirst8UniquePublications($finalPublicationsUserTagsFirst);

                // Stop once we have at least 8 distinct publications
                if ($finalPublicationsUserTagsFirst->count() >= 8) {
                    break;
                }
            }
            // return $finalPublicationsUserTagsFirst->count();
            // Ensure that you take exactly 8 distinct publications
            $publicationsUserTags = $finalPublicationsUserTagsFirst->unique( 'id' )->sortByDesc( 'score' )->take( 8 )->values();
            // $publicationsUserTags = $getFirst8UniquePublications($finalPublicationsUserTagsFirst->unique('id')->sortByDesc('score')->values());

            $sauvgardedPublications = $profileObject->publications_sauvgarde()->orderByPivot( 'updated_at', 'desc' )->get()->pluck( 'id' )->toArray();
            $clickedPublications = $profileObject->clicked_publications()->orderByPivot( 'updated_at', 'desc' )->get()->pluck( 'id' )->toArray();

            $userFollowersUsers = $profileObject->followingUsers->pluck( 'id' )->toArray();
            $userFollowersEtablissements = $profileObject->followingEtablissements->pluck( 'id' )->toArray();

            $lastPublicationVisited = $profileObject->clicked_publications()->select( 'publication_id', 'slug', 'publications_flink.user_id', 'publications_flink.etab_id' )
                ->orderByPivot( 'updated_at', 'desc' )->with( 'user:id,slug', 'etablissement:id,slug' )->first();
        }

        // Define a minimum score to prevent division by zero
        $minScore = 0.1;

        if ($profileObject) {
            $chancePublications = $publications->reject( function ( $publication ) use ( $publicationsUserTags ) {
                return in_array( $publication->id, $publicationsUserTags->pluck( 'id' )->toArray() );
            } )->sortByDesc( 'score' ); // Sort by score, high score first
        } else {
            $chancePublications = $publications;
        }

        // Function to shuffle publications with a bias towards low scores occasionally
        $shuffleWithBias = function ( $publications ) {
            return $publications->map( function ( $publication ) {
                // Generate a random number, and slightly decrease the chances of higher scored publications appearing first
                $biasedScore = rand( 1, 100 ) / max( $publication->score, 0.1 ); // Avoid dividing by 0
                $publication->random_weight = $biasedScore;
                return $publication;
            } )->sortBy( 'random_weight' );
        };




        // $shuffleWithBias = function ($publications) {
        //     // First, apply the bias and generate a random weight for each publication
        //     $publications = $publications->map(function ($publication) {
        //         $biasedScore = rand(1, 100) / max($publication->score, 0.1); // Avoid dividing by 0
        //         $publication->random_weight = $biasedScore;
        //         return $publication;
        //     })->sortBy('random_weight'); // Sort by biased score

        //     // Now, we need to ensure no consecutive publications from the same user or establishment
        //     $finalPublications = collect();
        //     $lastUserId = null;
        //     $lastEtabId = null;

        //     while ($publications->isNotEmpty()) {
        //         // Find the next publication that doesn't have the same user_id or etab_id as the last one
        //         $nextPublication = $publications->first(function ($publication) use ($lastUserId, $lastEtabId) {
        //             return ($publication->user_id !== $lastUserId && $publication->etab_id !== $lastEtabId);
        //         });

        //         // If we find a publication that doesn't break the rule, add it to the final list
        //         if ($nextPublication) {
        //             $finalPublications->push($nextPublication);
        //             // Update the last user and etab id
        //             $lastUserId = $nextPublication->user_id;
        //             $lastEtabId = $nextPublication->etab_id;

        //             // Remove the selected publication from the original list
        //             $publications = $publications->filter(function ($publication) use ($nextPublication) {
        //                 return $publication !== $nextPublication;
        //             });
        //         } else {
        //             // If we can't find a valid publication, break the loop
        //             break;
        //         }
        //     }

        //     // If any publications remain, add them (these will be publications from different users or establishments)
        //     $finalPublications = $finalPublications->merge($publications);

        //     return $finalPublications;
        // };


        $chancePublications = $publications->map( function ( $publication ) use ( $sauvgardedPublications, $clickedPublications, $userTags, $userFollowersUsers, $userFollowersEtablissements, $publicationsUserTags, $profileObject, $lastPublicationVisited ) {
            $createdAt = Carbon::parse( $publication->created_at );
            $daysSinceCreation = $createdAt->diffInDays( Carbon::now() );

            if ($profileObject) {
                // Check if the publication should be included or excluded based on various conditions
                if (
                    ( in_array( $publication->id, $sauvgardedPublications ) ||
                        in_array( $publication->id, $clickedPublications ) ||
                        !empty( array_intersect( $publication->tags->pluck( 'id' )->toArray(), $userTags ) ) ) &&
                    !in_array( $publication->id, $publicationsUserTags->pluck( 'id' )->toArray() )
                ) {
                    $biasedScore = rand( 3, 15 ) / max( $publication->score, 0.1 );
                } else if (
                    ( in_array( $publication->user_id, $userFollowersUsers ) || in_array( $publication->etab_id, $userFollowersEtablissements ) ) &&
                    !in_array( $publication->id, $publicationsUserTags->pluck( 'id' )->toArray() )
                ) {
                    $biasedScore = rand( 7, 20 ) / max( $publication->score, 0.1 );
                }

                if ($lastPublicationVisited && $lastPublicationVisited->etablissement && $publication->etab_id == $lastPublicationVisited->etablissement->id) {
                    $biasedScore = rand( 1, 10 ) / max( $publication->score, 0.1 );
                    // $biasedScore = 0.01;
                } else if ($lastPublicationVisited && $lastPublicationVisited->user && $publication->user_id == $lastPublicationVisited->user->id) {
                    $biasedScore = rand( 1, 10 ) / max( $publication->score, 0.1 );
                    // $biasedScore = 0.01;
                }
            }

            if ($daysSinceCreation <= 7) {
                $biasedScore = rand( 15, 30 ) / max( $publication->score, 0.1 );
            } else if ($daysSinceCreation > 7 && $daysSinceCreation <= 30) {
                $biasedScore = rand( 25, 50 ) / max( $publication->score, 0.1 );
            } else if ($daysSinceCreation > 30 && $daysSinceCreation <= 180) {
                $biasedScore = rand( 40, 60 ) / max( $publication->score, 0.1 );
            } else {
                // Generate a random number, and slightly decrease the chances of higher scored publications appearing first
                $biasedScore = rand( 50, 100 ) / max( $publication->score, 0.1 ); // Avoid dividing by 0
            }

            // Exclude the publications that are in $publicationsUserTags
            if (in_array( $publication->id, $publicationsUserTags->pluck( 'id' )->toArray() )) {
                return null; // Skip this publication
            }

            $publication->random_weight = $biasedScore;
            return $publication;
        } )->filter()->sortBy( 'random_weight' ); // Using filter to exclude null values

        // Shuffle within each category to introduce some randomness
        // $publicationsUserTags = $shuffleWithBias($publicationsUserTags);

        // Combine the publications: 8 from preferences, 8 from followers, 8 from chance
        $orderedPublications = collect();
        $orderedPublications = $orderedPublications->merge( $publicationsUserTags );
        $orderedPublications = $orderedPublications->merge( $chancePublications );

        // $finalPublications = collect();
        // $lastUserId = null;
        // $lastEtabId = null;
        // $buffer = collect(); // Buffer to hold publications that need to be rearranged

        // foreach ($chancePublications as $publication) {
        //     // Check if the publication is part of the same establishment
        //     if ($publication->etab_id !== null && $publication->etab_id == $lastEtabId) {
        //         // If it belongs to the same establishment, buffer it
        //         $buffer->push($publication);
        //         continue;  // Skip the current iteration and move to the next publication
        //     }

        //     // Check if the publication is from the same user
        //     if ($publication->user_id == $lastUserId) {
        //         // If it belongs to the same user, buffer it
        //         $buffer->push($publication);
        //         continue;  // Skip the current iteration and move to the next publication
        //     }

        //     // Add the valid publication to the final list
        //     $finalPublications->push($publication);

        //     // Update lastUserId and lastEtabId for the next iteration
        //     $lastUserId = $publication->user_id;
        //     $lastEtabId = $publication->etab_id;

        //     // If there are any publications in the buffer, move one to the final list
        //     if ($buffer->isNotEmpty()) {
        //         $bufferedPublication = $buffer->shift();  // Get one publication from the buffer
        //         $finalPublications->push($bufferedPublication);  // Add it to the final list

        //         // After inserting the buffered publication, update lastUserId and lastEtabId
        //         $lastUserId = $bufferedPublication->user_id;
        //         $lastEtabId = $bufferedPublication->etab_id;
        //     }
        // }

        // // If there are any remaining publications in the buffer, add them to the final list
        // while ($buffer->isNotEmpty()) {
        //     $bufferedPublication = $buffer->shift();
        //     $finalPublications->push($bufferedPublication);

        //     // Update lastUserId and lastEtabId for the last buffered publication
        //     $lastUserId = $bufferedPublication->user_id;
        //     $lastEtabId = $bufferedPublication->etab_id;
        // }

        // $orderedPublications = $orderedPublications->merge($finalPublications);


        return $orderedPublications;
    }

}

if (!function_exists( 'getRegionOrdrer' )) {
    function getRegionOrdrer( $villeId )
    {
        $regionsOfVille = Region::where( 'status', 1 )->where( 'ville_id', $villeId )->with( 'ville' )->orderByDesc( 'compteur_publications' )->get()->shuffle()->take( 60 );
        if ($regionsOfVille->count() < 60)
            $regions = Region::where( 'status', 1 )->with( 'ville' )->orderByDesc( 'compteur_publications' )
                ->limit( 100 )->get()
                ->shuffle()
                ->take( 60 - $regionsOfVille->count() );
        else
            $regions = collect();
        return $regionsOfVille->merge( $regions );
    }
}

if (!function_exists( 'getOrderedPublicationsWithPreferences' )) {
    function getOrderedPublicationsWithPreferences( $publications, $profile )
    {
        $orderIndex = rand( 0, 1 );
        $orderArray = ["score", "updated_at"];
        if ($profile) {


            $recommendedPublications = $profile->recommendations()
                ->with( [
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'user:id,first_name,last_name,slug',
                    'etablissement:id,nom,slug',
                    'tags:id,name,slug',
                    'statistiques',
                    'publications_booster.shopping_publications',
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', Carbon::now() )->orderBy( 'id', 'desc' );
                    },
                    'recommendations_users',
                    'recommendations_etabs'
                ] )
                ->orderByPivot( $orderArray[$orderIndex], 'desc' )
                ->limit( 24 )
                ->get();

            // Keep as Collection (remove array_values and toArray)
            // $recommendedPublications = $publications->whereIn('id', $recommendedPublicationsIds);

            // Also keep as Collection
            $restOfPublications = $publications->whereNotIn( 'id', $recommendedPublications->pluck( 'id' )->toArray() );

            // Now merge will work since both are Collections
            return $recommendedPublications->merge( $restOfPublications );
        }

        // If no profile, return original publications (assuming it's already a Collection)
        return $publications;
    }
}

if (!function_exists( 'getOrderedPublicationsWithPreferencesTest' )) {
    function getOrderedPublicationsWithPreferencesTest( $publications, $profileRecommendations )
    {
        $orderIndex = rand( 0, 1 );
        $orderArrayPivot = ["score", "updated_at"];
        $orderArray = ["score", "created_at"];

        if ($profileRecommendations) {
            $recommendedPublicationsIds = $profileRecommendations
                ->orderByPivot( $orderArrayPivot[$orderIndex], 'desc' )
                ->limit( 24 )
                ->get()->pluck( 'id' )->filter()->values()->toArray();

            if (count( $recommendedPublicationsIds ) !== 0)
                return $publications->orderByRaw( "FIELD(id, " . implode( ',', $recommendedPublicationsIds ) . ") DESC" );
        }

        // If no profile, return original publications (assuming it's already a Collection)
        return $publications->orderByDesc( $orderArray[$orderIndex] );
    }
}

if (!function_exists( 'filteredPublications' )) {
    // function filteredPublications($query, $search, $pays = null, $ville = null, $region = null) {
    //     $orderIndex = rand(0, 1);
    //     $orderArray = ["score", "created_at"];

    //     $query->where('publications_flink.status', 1);

    //     // Apply location filters (unchanged)
    //     $selectedPays = $pays ? Pays::where('slug', $pays)->first() : null;
    //     if ($pays && !$selectedPays) return $query->whereRaw('1 = 0');
    //     if ($selectedPays) $query->where('publications_flink.pays_id', $selectedPays->id);

    //     $selectedVille = ($ville && $selectedPays) 
    //         ? Ville::where('pays_id', $selectedPays->id)->where('slug', $ville)->first() 
    //         : null;
    //     if ($ville && !$selectedVille) return $query->whereRaw('1 = 0');
    //     if ($selectedVille) $query->where('publications_flink.ville_id', $selectedVille->id);

    //     $selectedRegion = ($region && $selectedVille) 
    //         ? Region::where('ville_id', $selectedVille->id)->where('slug', $region)->first() 
    //         : null;
    //     if ($region && !$selectedRegion) return $query->whereRaw('1 = 0');
    //     if ($selectedRegion) $query->where('publications_flink.region_id', $selectedRegion->id);

    //     if (!$search || $search === "marketplace") {
    //         return $query->orderByDesc("publications_flink.$orderArray[$orderIndex]");
    //     }

    //     $originalSearch = $search;
    //     $keywords = generateSlugCombinations($originalSearch);

    //     // 1. Find exact tag matches
    //     $exactTagIds = Tags::where('slug', $originalSearch)
    //                     ->pluck('id')
    //                     ->toArray();

    //     // Find partial tag matches
    //          $partialTagIds = Tags::where(function($q) use ($keywords) {
    //                 foreach ($keywords as $keyword) {
    //                     $q->orWhere('slug', 'like', '%'.$keyword.'%')
    //                     ->orWhere('slug', 'like', '%'.Str::singular($keyword).'%');
    //                 }
    //             })
    //             ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //             ->pluck('id')
    //             ->toArray();
    //     }

    //     // 3. find tags similaire of search
    //     $tagsSimilaires = Tags::select('id', 'name', 'slug')
    //         ->where('status', 1)
    //         ->whereHas('publications', function($query) use ($originalSearch) {
    //             $query->whereHas('tags', function($q) use ($originalSearch) {
    //                 $q->where('name', $originalSearch)
    //                 ->orWhere('slug', $originalSearch);
    //             });
    //         })
    //         ->where(function($query) use ($originalSearch) {
    //             $query->where('name', '!=', $originalSearch)
    //                 ->where('slug', '!=', $originalSearch);
    //         })
    //         ->orderByDesc('compteur_publications')
    //         ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //         ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
    //         ->get()
    //         ->pluck('id')
    //         ->toArray();

    //     // First try with exact, partial and similar tags
    //     $filteredQuery = clone $query;

    //     $hasAnyTagMatches = !empty($exactTagIds) || !empty($partialTagIds) || !empty($tagsSimilaires);
    //     if (!$hasAnyTagMatches) {
    //         return $query->whereRaw('1 = 0');
    //     }

    //     $filteredQuery->where(function($q) use ($exactTagIds, $partialTagIds, $tagsSimilaires) {
    //         if (!empty($exactTagIds)) {
    //             $q->whereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $exactTagIds));
    //         }

    //         if (!empty($partialTagIds)) {
    //             $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $partialTagIds));
    //         }

    //         if (!empty($tagsSimilaires)) {
    //             $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $tagsSimilaires));
    //         }
    //     });

    //     $filteredQuery->select([
    //             'publications_flink.*',
    //             \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
    //                     WHERE publications_flink.id = publications_booster_flink.publication_id 
    //                     AND date_fin > NOW()) as booster'),
    //             \DB::raw("CASE 
    //                 WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
    //                 ) THEN 4 
    //                 WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
    //                 ) THEN 3
    //                 WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
    //                 ) THEN 2
    //                 ELSE 0 END as match_priority")
    //         ])
    //         ->having('match_priority', '>', 0)
    //         ->orderByDesc('match_priority')
    //         ->orderByDesc("publications_flink.$orderArray[$orderIndex]");

    //     // If we get results from the first try, return them
    //     if ($filteredQuery->exists()) {
    //         return $filteredQuery;
    //     }

    //     // Otherwise try with truncated versions
    //     $searchVariations = [];
    //     foreach($keywords as $keyword) {
    //         $length = strlen($keyword);
    //         if ($length <= 3) continue; // Skip keywords that are 3 letters or shorter

    //         $maxToRemove = min(3, $length - 3); // Don't remove more than would leave us with <3 letters
    //         for ($i = 1; $i <= $maxToRemove; $i++) {
    //             $searchVariations[] = substr($keyword, 0, -$i);
    //         }
    //     }

    //     $truncatedTagIds = Tags::where(function($query) use ($searchVariations) {
    //                 foreach ($searchVariations as $variation) {
    //                     $query->orWhere('slug', 'like', "%{$variation}%");
    //                 }
    //             })
    //             ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //             ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
    //             ->when(!empty($tagsSimilaires), fn($q) => $q->whereNotIn('id', $tagsSimilaires))
    //             ->pluck('id')
    //             ->toArray();


    //     if (empty($truncatedTagIds)) {
    //         return $query->whereRaw('1 = 0');
    //     }

    //     $query->whereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $truncatedTagIds));

    //     $query->select([
    //             'publications_flink.*',
    //             \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
    //                     WHERE publications_flink.id = publications_booster_flink.publication_id 
    //                     AND date_fin > NOW()) as booster'),
    //             \DB::raw("CASE 
    //                 WHEN EXISTS (
    //                     SELECT 1 FROM publications_tags_flink 
    //                     WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                     AND publications_tags_flink.tag_id IN (".implode(',', $truncatedTagIds ?: [0]).")
    //                 ) THEN 1 
    //                 ELSE 0 END as match_priority")
    //         ])
    //         ->having('match_priority', '>', 0)
    //         ->orderByDesc('match_priority')
    //         ->orderByDesc("publications_flink.$orderArray[$orderIndex]");

    //     return $query;     $partialTagIds = [];
    //     if (!empty($keywords)) {

    // }


    // function filteredPublications($query, $search, $pays = [], $villeId = null, $villes = [], $regionId = null, $regions = []) {
    //     // return $pays;
    //     $orderIndex = rand(0, 1);
    //     $orderArray = ["score", "created_at"];

    //     $villesIds_50km = $villesIds_100km = $villesIds_150km = $villesIds_200km = $villesIds_250km = [];
    //     $regionsIds_50km = $regionsIds_100km = $regionsIds_150km = $regionsIds_200km = $regionsIds_250km = [];

    //     if($regionId) {
    //         $regionsIds_50km = getNearbyLocation($regionId, 50, "region");
    //         $regionsIds_100km = getNearbyLocation($regionId, 100, "region");
    //         $regionsIds_150km = getNearbyLocation($regionId, 150, "region");
    //         $regionsIds_200km = getNearbyLocation($regionId, 200, "region");
    //         $regionsIds_250km = getNearbyLocation($regionId, 250, "region");
    //     } else if($villeId) {
    //         $villesIds_50km = getNearbyLocation($villeId, 50, "ville");
    //         $villesIds_100km = getNearbyLocation($villeId, 100, "ville");
    //         $villesIds_150km = getNearbyLocation($villeId, 150, "ville");
    //         $villesIds_200km = getNearbyLocation($villeId, 200, "ville");
    //         $villesIds_250km = getNearbyLocation($villeId, 250, "ville");
    //     }

    //     $query->where('publications_flink.status', 1);

    //     // Apply location filters (unchanged)
    //     if (count($pays) > 0) $query->whereIn('publications_flink.pays_id', $pays);

    //     if (count($villes) > 0 ) $query->whereIn('publications_flink.ville_id', $villes);

    //     if (count($regions) > 0 ) $query->whereIn('publications_flink.region_id', $regions);

    //     if (!$search || $search === "marketplace") {
    //         return $query->orderByDesc("publications_flink.$orderArray[$orderIndex]");
    //     }

    //     $keywords = [];
    //     $originalSearch = $search;
    //     $partsSearch = generateSlugParts($originalSearch);
    //     foreach($partsSearch as $partSearch) {
    //         $keywords = array_unique(array_merge($keywords, generateSlugCombinations($partSearch)));
    //     }

    //     // Sort by length (longest first)
    //     usort($keywords, function($a, $b) {
    //         return strlen($b) - strlen($a);
    //     });

    //     // 1. Find exact tag matches
    //     $exactTagIds = Tags::where('slug', $originalSearch)
    //                     ->pluck('id')
    //                     ->toArray();

    //     // Store keyword matches for each publication
    //     $partialMatches = [];

    //     if (!empty($keywords)) {
    //         foreach ($keywords as $keyword) {
    //             $length = strlen($keyword);

    //             // Find tags that exactly match the keyword
    //             $tags = Tags::where('slug', $keyword)
    //                 ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //                 ->get();

    //             foreach ($tags as $tag) {
    //                 $partialMatches[$tag->id] = [
    //                     'type' => 'exact_slug_match',
    //                     'length' => $length,
    //                     'priority' => 3,
    //                     'keyword' => $keyword
    //                 ];
    //             }

    //             // Find tags that contain the keyword in their slug
    //             $tags = Tags::where('slug', 'like', '%'.$keyword.'%')
    //                 ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //                 ->when(!empty(array_keys($partialMatches)), fn($q) => $q->whereNotIn('id', array_keys($partialMatches)))
    //                 ->get();

    //             foreach ($tags as $tag) {
    //                 // Calculate how well the keyword matches within the slug
    //                 $slug = $tag->slug;
    //                 $position = strpos($slug, $keyword);
    //                 $isAtStart = $position === 0;
    //                 $isAtEnd = $position === (strlen($slug) - strlen($keyword));

    //                 $partialMatches[$tag->id] = [
    //                     'type' => $isAtStart ? 'slug_starts_with' : ($isAtEnd ? 'slug_ends_with' : 'slug_contains'),
    //                     'length' => $length,
    //                     'priority' => $isAtStart ? 2 : ($isAtEnd ? 1.5 : 1),
    //                     'keyword' => $keyword,
    //                     'position' => $position
    //                 ];
    //             }
    //         }
    //     }

    //     $partialTagIds = array_keys($partialMatches);

    //     // 3. find tags similaire of search
    //     $tagsSimilaires = Tags::select('id', 'name', 'slug')
    //         ->where('status', 1)
    //         ->whereHas('publications', fn($q) => $q->whereHas('tags', fn($q) => $q->where('name', $originalSearch)->orWhere('slug', $originalSearch)))
    //         ->where(fn($q) => $q->where('name', '!=', $originalSearch)->where('slug', '!=', $originalSearch))
    //         ->orderByDesc('compteur_publications')
    //         ->when(!empty($exactTagIds), fn($q) => $q->whereNotIn('id', $exactTagIds))
    //         ->when(!empty($partialTagIds), fn($q) => $q->whereNotIn('id', $partialTagIds))
    //         ->get()
    //         ->pluck('id')
    //         ->toArray();

    //     $filteredQuery = clone $query;

    //     $filteredQuery->where(function($q) use ($exactTagIds, $partialTagIds, $tagsSimilaires) {
    //         if (!empty($exactTagIds)) {
    //             $q->whereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $exactTagIds));
    //         }

    //         if (!empty($partialTagIds)) {
    //             $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $partialTagIds));
    //         }

    //         if (!empty($tagsSimilaires)) {
    //             $q->orWhereHas('tags', fn($q) => $q->whereIn('tags_flink.id', $tagsSimilaires));
    //         }
    //     });

    //     // Build the select clauses conditionally
    //     $selectClauses = [
    //         'publications_flink.*',
    //         \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
    //                 WHERE publications_flink.id = publications_booster_flink.publication_id 
    //                 AND date_fin > NOW()) as booster'),
    //         \DB::raw("CASE 
    //             WHEN EXISTS (
    //                 SELECT 1 FROM publications_tags_flink 
    //                 WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                 AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
    //             ) THEN 4 
    //             WHEN EXISTS (
    //                 SELECT 1 FROM publications_tags_flink 
    //                 WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                 AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
    //             ) THEN 3
    //             WHEN EXISTS (
    //                 SELECT 1 FROM publications_tags_flink 
    //                 WHERE publications_tags_flink.publication_id = publications_flink.id 
    //                 AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
    //             ) THEN 1
    //             ELSE 0 END as match_priority")
    //     ];

    //     // Only add these complex calculations if we have partial matches
    //     if (!empty($partialTagIds)) {
    //         $scoreSelects = array_map(function($id) use ($partialMatches) {
    //             $score = $partialMatches[$id]['priority'] * $partialMatches[$id]['length'];
    //             if (isset($partialMatches[$id]['position'])) {
    //                 $position = $partialMatches[$id]['position'];
    //                 if ($position === 0) {
    //                     $score *= 1.5;
    //                 } elseif ($position > 0) {
    //                     $score *= (1 - ($position * 0.01));
    //                 }
    //             }
    //             return "SELECT {$score} as match_score, {$partialMatches[$id]['priority']} as match_type, {$id} as tag_id";
    //         }, $partialTagIds);

    //         $typeSelects = array_map(function($id) use ($partialMatches) {
    //             return "SELECT {$partialMatches[$id]['priority']} as match_type, {$id} as tag_id";
    //         }, $partialTagIds);

    //         $selectClauses[] = \DB::raw("COALESCE(
    //             (SELECT MAX(match_score) FROM (
    //                 ".implode(' UNION ALL ', $scoreSelects)."
    //             ) matches
    //             JOIN publications_tags_flink pt ON pt.tag_id = matches.tag_id
    //             WHERE pt.publication_id = publications_flink.id
    //         ), 0) as keyword_match_score");

    //         $selectClauses[] = \DB::raw("COALESCE(
    //             (SELECT MAX(match_type) FROM (
    //                 ".implode(' UNION ALL ', $typeSelects)."
    //             ) matches
    //             JOIN publications_tags_flink pt ON pt.tag_id = matches.tag_id
    //             WHERE pt.publication_id = publications_flink.id
    //         ), 0) as keyword_match_type");
    //     } else {
    //         // Add default values when no partial matches exist
    //         $selectClauses[] = \DB::raw("0 as keyword_match_score");
    //         $selectClauses[] = \DB::raw("0 as keyword_match_type");
    //     }


    //     $filteredQuery->select($selectClauses)
    //         ->having('match_priority', '>', 0)
    //         ->orderByDesc('match_priority')
    //         ->orderByDesc('keyword_match_score')
    //         ->orderByDesc('keyword_match_type')
    //         ->orderByDesc("publications_flink.$orderArray[$orderIndex]");

    //     return $filteredQuery;
    // }


    function filteredPublications( $query, $search, $pays = [], $villeId = null, $villes = [], $regionId = null, $regions = [] )
    {
        $orderIndex = rand( 0, 1 );
        $orderArray = ["score", "created_at"];


        $villesIds_50km = $villesIds_100km = $villesIds_150km = $villesIds_200km = $villesIds_250km = [];
        // $regionsIds_50km = $regionsIds_100km = $regionsIds_150km = $regionsIds_200km = $regionsIds_250km = [];

        // if($regionId) {
        //     $regionsIds_50km = array_diff(getNearbyLocation($regionId, 50, "region"), [$regionId]);
        //     $regionsIds_100km = array_diff(getNearbyLocation($regionId, 100, "region"), $regionsIds_50km);
        //     $regionsIds_150km = array_diff(getNearbyLocation($regionId, 150, "region"), array_merge($regionsIds_50km, $regionsIds_100km));
        //     $regionsIds_200km = array_diff(getNearbyLocation($regionId, 200, "region"), array_merge($regionsIds_50km, $regionsIds_100km, $regionsIds_150km));
        //     $regionsIds_250km = array_diff(getNearbyLocation($regionId, 250, "region"), array_merge($regionsIds_50km, $regionsIds_100km, $regionsIds_150km, $regionsIds_200km));
        // } else 

        if ($villeId) {
            $villesIds_50km = array_diff( getNearbyLocation( $villeId, 50, "ville" ), [$villeId] );
            $villesIds_100km = array_diff( getNearbyLocation( $villeId, 100, "ville" ), $villesIds_50km );
            $villesIds_150km = array_diff( getNearbyLocation( $villeId, 150, "ville" ), array_merge( $villesIds_50km, $villesIds_100km ) );
            $villesIds_200km = array_diff( getNearbyLocation( $villeId, 200, "ville" ), array_merge( $villesIds_50km, $villesIds_100km, $villesIds_150km ) );
            $villesIds_250km = array_diff( getNearbyLocation( $villeId, 250, "ville" ), array_merge( $villesIds_50km, $villesIds_100km, $villesIds_150km, $villesIds_200km ) );
        }

        // return ["villesIds_50km"=> $villesIds_50km, "villesIds_100km"=> $villesIds_100km,"villesIds_150km"=> $villesIds_150km,"villesIds_200km"=> $villesIds_200km,
        //             "villesIds_250km"=> $villesIds_250km,];

        $query->where( 'publications_flink.status', 1 );

        // Apply location filters (unchanged)
        if (count( $pays ) > 0)
            $query->whereIn( 'publications_flink.pays_id', $pays );

        if (count( $villes ) > 0)
            $query->whereIn( 'publications_flink.ville_id', $villes );

        if (count( $regions ) > 0)
            $query->whereIn( 'publications_flink.region_id', $regions );

        if (!$search || $search === "marketplace") {
            // Add location-based ordering for marketplace case
            if ($villeId || $regionId) {
                $query->orderByRaw( "
                    CASE 
                        WHEN ville_id IN (" . implode( ',', $villesIds_50km ?: [0] ) . ") THEN 1
                        WHEN ville_id IN (" . implode( ',', $villesIds_100km ?: [0] ) . ") THEN 2
                        WHEN ville_id IN (" . implode( ',', $villesIds_150km ?: [0] ) . ") THEN 3
                        WHEN ville_id IN (" . implode( ',', $villesIds_200km ?: [0] ) . ") THEN 4
                        WHEN ville_id IN (" . implode( ',', $villesIds_250km ?: [0] ) . ") THEN 5
                        ELSE 6
                    END
                " );
            }
            return $query->orderByDesc( "publications_flink.$orderArray[$orderIndex]" );
        }

        $keywords = [];
        $originalSearch = $search;
        $partsSearch = generateSlugParts( $originalSearch );
        foreach ($partsSearch as $partSearch) {
            $keywords = array_unique( array_merge( $keywords, generateSlugCombinations( $partSearch ) ) );
        }

        // Sort by length (longest first)
        usort( $keywords, function ( $a, $b ) {
            return strlen( $b ) - strlen( $a );
        } );

        // 1. Find exact tag matches
        $exactTagIds = Tags::where( 'slug', $originalSearch )
            ->pluck( 'id' )
            ->toArray();
        $exactTagNames = Tags::where( 'slug', $originalSearch )
            ->pluck( 'name' )
            ->toArray();


        // Store keyword matches for each publication
        $partialMatches = [];

        if (!empty( $keywords )) {
            foreach ($keywords as $keyword) {
                $length = strlen( $keyword );

                // Find tags that exactly match the keyword
                $tags = Tags::where( 'slug', $keyword )
                    ->when( !empty( $exactTagIds ), fn( $q ) => $q->whereNotIn( 'id', $exactTagIds ) )
                    ->get();

                foreach ($tags as $tag) {
                    $partialMatches[$tag->id] = [
                        'type' => 'exact_slug_match',
                        'length' => $length,
                        'priority' => 3,
                        'keyword' => $keyword
                    ];
                }

                // Find tags that contain the keyword in their slug
                $tags = Tags::where( 'slug', 'like', '%' . $keyword . '%' )
                    ->when( !empty( $exactTagIds ), fn( $q ) => $q->whereNotIn( 'id', $exactTagIds ) )
                    ->when( !empty( array_keys( $partialMatches ) ), fn( $q ) => $q->whereNotIn( 'id', array_keys( $partialMatches ) ) )
                    ->get();

                foreach ($tags as $tag) {
                    // Calculate how well the keyword matches within the slug
                    $slug = $tag->slug;
                    $position = strpos( $slug, $keyword );
                    $isAtStart = $position === 0;
                    $isAtEnd = $position === ( strlen( $slug ) - strlen( $keyword ) );

                    $partialMatches[$tag->id] = [
                        'type' => $isAtStart ? 'slug_starts_with' : ( $isAtEnd ? 'slug_ends_with' : 'slug_contains' ),
                        'length' => $length,
                        'priority' => $isAtStart ? 2 : ( $isAtEnd ? 1.5 : 1 ),
                        'keyword' => $keyword,
                        'position' => $position
                    ];
                }
            }
        }

        $partialTagIds = array_keys( $partialMatches );
        $partialTagNames = Tags::where( 'slug', 'like', '%' . $keyword . '%' )
            ->when( !empty( $exactTagIds ), fn( $q ) => $q->whereNotIn( 'id', $exactTagIds ) )
            ->when( !empty( array_keys( $partialMatches ) ), fn( $q ) => $q->whereNotIn( 'id', array_keys( $partialMatches ) ) )
            ->pluck( 'name' )->toArray();


        // 3. find tags similaire of search
        $tagsSimilaires = Tags::select( 'id', 'name', 'slug' )
            ->where( 'status', 1 )
            ->whereHas( 'publications', fn( $q ) => $q->whereHas( 'tags', fn( $q ) => $q->where( 'name', $originalSearch )->orWhere( 'slug', $originalSearch ) ) )
            ->where( fn( $q ) => $q->where( 'name', '!=', $originalSearch )->where( 'slug', '!=', $originalSearch ) )
            ->orderByDesc( 'compteur_publications' )
            ->when( !empty( $exactTagIds ), fn( $q ) => $q->whereNotIn( 'id', $exactTagIds ) )
            ->when( !empty( $partialTagIds ), fn( $q ) => $q->whereNotIn( 'id', $partialTagIds ) )
            ->get()
            ->pluck( 'id' )
            ->toArray();
        $tagsSimilaireNames = Tags::select( 'id', 'name', 'slug' )
            ->where( 'status', 1 )
            ->whereHas( 'publications', fn( $q ) => $q->whereHas( 'tags', fn( $q ) => $q->where( 'name', $originalSearch )->orWhere( 'slug', $originalSearch ) ) )
            ->where( fn( $q ) => $q->where( 'name', '!=', $originalSearch )->where( 'slug', '!=', $originalSearch ) )
            ->orderByDesc( 'compteur_publications' )
            ->when( !empty( $exactTagIds ), fn( $q ) => $q->whereNotIn( 'id', $exactTagIds ) )
            ->when( !empty( $partialTagIds ), fn( $q ) => $q->whereNotIn( 'id', $partialTagIds ) )
            ->get()
            ->pluck( 'name' )
            ->toArray();

        $filteredQuery = clone $query;

        $filteredQuery->where( function ( $q ) use ( $exactTagIds, $partialTagIds, $tagsSimilaires, $exactTagNames, $partialTagNames, $tagsSimilaireNames ) {
            // Search by tag IDs (using slugs)
            if (!empty( $exactTagIds )) {
                $q->whereHas( 'tags', fn( $q ) => $q->whereIn( 'tags_flink.id', $exactTagIds ) );
            }

            if (!empty( $partialTagIds )) {
                $q->orWhereHas( 'tags', fn( $q ) => $q->whereIn( 'tags_flink.id', $partialTagIds ) );
            }

            if (!empty( $tagsSimilaires )) {
                $q->orWhereHas( 'tags', fn( $q ) => $q->whereIn( 'tags_flink.id', $tagsSimilaires ) );
            }

            // Search by tag names in description
            if (!empty( $exactTagNames )) {
                $q->orWhere( function ( $query ) use ( $exactTagNames ) {
                    foreach ($exactTagNames as $tag) {
                        $query->orWhere( 'description', 'like', '%' . $tag . '%' );
                    }
                } );
            }

            if (!empty( $partialTagNames )) {
                $q->orWhere( function ( $query ) use ( $partialTagNames ) {
                    foreach ($partialTagNames as $tag) {
                        $query->orWhere( 'description', 'like', '%' . $tag . '%' );
                    }
                } );
            }

            if (!empty( $tagsSimilaireNames )) {
                $q->orWhere( function ( $query ) use ( $tagsSimilaireNames ) {
                    foreach ($tagsSimilaireNames as $tag) {
                        $query->orWhere( 'description', 'like', '%' . $tag . '%' );
                    }
                } );
            }
        } );

        // Build the select clauses conditionally
        // $selectClauses = [
        //     'publications_flink.*',
        //     \DB::raw('(SELECT COUNT(*) FROM publications_booster_flink 
        //             WHERE publications_flink.id = publications_booster_flink.publication_id 
        //             AND date_fin > NOW()) as booster'),
        //     \DB::raw("CASE 
        //         WHEN EXISTS (
        //             SELECT 1 FROM publications_tags_flink 
        //             WHERE publications_tags_flink.publication_id = publications_flink.id 
        //             AND publications_tags_flink.tag_id IN (".implode(',', $exactTagIds ?: [0]).")
        //         ) THEN 4 
        //         WHEN EXISTS (
        //             SELECT 1 FROM publications_tags_flink 
        //             WHERE publications_tags_flink.publication_id = publications_flink.id 
        //             AND publications_tags_flink.tag_id IN (".implode(',', $partialTagIds ?: [0]).")
        //         ) THEN 3
        //         WHEN EXISTS (
        //             SELECT 1 FROM publications_tags_flink 
        //             WHERE publications_tags_flink.publication_id = publications_flink.id 
        //             AND publications_tags_flink.tag_id IN (".implode(',', $tagsSimilaires ?: [0]).")
        //         ) THEN 1
        //         ELSE 0 END as match_priority")
        // ];

        $selectClauses = [
            'publications_flink.*',
            \DB::raw( '(SELECT COUNT(*) FROM publications_booster_flink 
                    WHERE publications_flink.id = publications_booster_flink.publication_id 
                    AND date_fin > NOW()) as booster' ),
            \DB::raw( "CASE 
                WHEN EXISTS (
                    SELECT 1 FROM publications_tags_flink 
                    WHERE publications_tags_flink.publication_id = publications_flink.id 
                    AND publications_tags_flink.tag_id IN (" . implode( ',', $exactTagIds ?: [0] ) . ")
                ) THEN 6
                WHEN EXISTS (
                    SELECT 1 FROM publications_tags_flink 
                    WHERE publications_tags_flink.publication_id = publications_flink.id 
                    AND publications_tags_flink.tag_id IN (" . implode( ',', $partialTagIds ?: [0] ) . ")
                ) THEN 4
                WHEN EXISTS (
                    SELECT 1 FROM publications_tags_flink 
                    WHERE publications_tags_flink.publication_id = publications_flink.id 
                    AND publications_tags_flink.tag_id IN (" . implode( ',', $tagsSimilaires ?: [0] ) . ")
                ) THEN 2
                ELSE 0 END as tag_match_priority" ),
            \DB::raw( "CASE 
                " . ( $exactTagNames ? "WHEN (" . implode( " OR ", array_map( function ( $name ) {
                return "publications_flink.description LIKE '%" . addslashes( $name ) . "%'";
            }, $exactTagNames ) ) . ") THEN 5" : "" ) . "
                " . ( $partialTagNames ? "WHEN (" . implode( " OR ", array_map( function ( $name ) {
                return "publications_flink.description LIKE '%" . addslashes( $name ) . "%'";
            }, $partialTagNames ) ) . ") THEN 3" : "" ) . "
                " . ( $tagsSimilaireNames ? "WHEN (" . implode( " OR ", array_map( function ( $name ) {
                return "publications_flink.description LIKE '%" . addslashes( $name ) . "%'";
            }, $tagsSimilaireNames ) ) . ") THEN 1" : "" ) . "
                ELSE 0 END as description_match_priority" ),
            \DB::raw( "GREATEST(
                CASE WHEN EXISTS (
                    SELECT 1 FROM publications_tags_flink 
                    WHERE publications_tags_flink.publication_id = publications_flink.id 
                    AND publications_tags_flink.tag_id IN (" . implode( ',', $exactTagIds ?: [0] ) . ")
                ) THEN 6 ELSE 0 END,
                CASE WHEN (" . ( $exactTagNames ? implode( " OR ", array_map( function ( $name ) {
                return "publications_flink.description LIKE '%" . addslashes( $name ) . "%'";
            }, $exactTagNames ) ) : "FALSE" ) . ") THEN 5 ELSE 0 END,
                CASE WHEN EXISTS (
                    SELECT 1 FROM publications_tags_flink 
                    WHERE publications_tags_flink.publication_id = publications_flink.id 
                    AND publications_tags_flink.tag_id IN (" . implode( ',', $partialTagIds ?: [0] ) . ")
                ) THEN 4 ELSE 0 END,
                CASE WHEN (" . ( $partialTagNames ? implode( " OR ", array_map( function ( $name ) {
                return "publications_flink.description LIKE '%" . addslashes( $name ) . "%'";
            }, $partialTagNames ) ) : "FALSE" ) . ") THEN 3 ELSE 0 END,
                CASE WHEN EXISTS (
                    SELECT 1 FROM publications_tags_flink 
                    WHERE publications_tags_flink.publication_id = publications_flink.id 
                    AND publications_tags_flink.tag_id IN (" . implode( ',', $tagsSimilaires ?: [0] ) . ")
                ) THEN 2 ELSE 0 END,
                CASE WHEN (" . ( $tagsSimilaireNames ? implode( " OR ", array_map( function ( $name ) {
                return "publications_flink.description LIKE '%" . addslashes( $name ) . "%'";
            }, $tagsSimilaireNames ) ) : "FALSE" ) . ") THEN 1 ELSE 0 END
            ) as match_priority" )
        ];

        // Only add these complex calculations if we have partial matches
        if (!empty( $partialTagIds )) {
            $scoreSelects = array_map( function ( $id ) use ( $partialMatches ) {
                $score = $partialMatches[$id]['priority'] * $partialMatches[$id]['length'];
                if (isset( $partialMatches[$id]['position'] )) {
                    $position = $partialMatches[$id]['position'];
                    if ($position === 0) {
                        $score *= 1.5;
                    } elseif ($position > 0) {
                        $score *= ( 1 - ( $position * 0.01 ) );
                    }
                }
                return "SELECT {$score} as match_score, {$partialMatches[$id]['priority']} as match_type, {$id} as tag_id";
            }, $partialTagIds );

            $typeSelects = array_map( function ( $id ) use ( $partialMatches ) {
                return "SELECT {$partialMatches[$id]['priority']} as match_type, {$id} as tag_id";
            }, $partialTagIds );

            $selectClauses[] = \DB::raw( "COALESCE(
                (SELECT MAX(match_score) FROM (
                    " . implode( ' UNION ALL ', $scoreSelects ) . "
                ) matches
                JOIN publications_tags_flink pt ON pt.tag_id = matches.tag_id
                WHERE pt.publication_id = publications_flink.id
            ), 0) as keyword_match_score" );

            $selectClauses[] = \DB::raw( "COALESCE(
                (SELECT MAX(match_type) FROM (
                    " . implode( ' UNION ALL ', $typeSelects ) . "
                ) matches
                JOIN publications_tags_flink pt ON pt.tag_id = matches.tag_id
                WHERE pt.publication_id = publications_flink.id
            ), 0) as keyword_match_type" );
        } else {
            // Add default values when no partial matches exist
            $selectClauses[] = \DB::raw( "0 as keyword_match_score" );
            $selectClauses[] = \DB::raw( "0 as keyword_match_type" );
        }

        // // Add location priority to select clauses
        // $selectClauses[] = \DB::raw("
        //     CASE 
        //         " . ($villeId ? "WHEN ville_id = " . $villeId . " THEN 6" : "") . "
        //         " . ($regionId ? "WHEN region_id = " . $regionId . " THEN 7" : "") . "
        //         WHEN ville_id IN (".implode(',', $villesIds_50km ?: [0]).") OR region_id IN (".implode(',', $regionsIds_50km ?: [0]).") THEN 5
        //         WHEN ville_id IN (".implode(',', $villesIds_100km ?: [0]).") OR region_id IN (".implode(',', $regionsIds_100km ?: [0]).") THEN 4
        //         WHEN ville_id IN (".implode(',', $villesIds_150km ?: [0]).") OR region_id IN (".implode(',', $regionsIds_150km ?: [0]).") THEN 3
        //         WHEN ville_id IN (".implode(',', $villesIds_200km ?: [0]).") OR region_id IN (".implode(',', $regionsIds_200km ?: [0]).") THEN 2
        //         WHEN ville_id IN (".implode(',', $villesIds_250km ?: [0]).") OR region_id IN (".implode(',', $regionsIds_250km ?: [0]).") THEN 1
        //         ELSE 0
        //     END as location_priority
        // ");


        $selectClauses[] = \DB::raw( "
            CASE 
                " . ( $villeId ? "WHEN ville_id = " . $villeId . " THEN 6" : "" ) . "
                WHEN ville_id IN (" . implode( ',', $villesIds_50km ?: [0] ) . ") THEN 5
                WHEN ville_id IN (" . implode( ',', $villesIds_100km ?: [0] ) . ") THEN 4
                WHEN ville_id IN (" . implode( ',', $villesIds_150km ?: [0] ) . ") THEN 3
                WHEN ville_id IN (" . implode( ',', $villesIds_200km ?: [0] ) . ") THEN 2
                WHEN ville_id IN (" . implode( ',', $villesIds_250km ?: [0] ) . ") THEN 1
                ELSE 0
            END as location_priority_ville
        " );
        // $selectClauses[] = \DB::raw("
        //     CASE 
        //         " . ($regionId ? "WHEN region_id = " . $regionId . " THEN 6" : "") . "
        //         WHEN region_id IN (".implode(',', $regionsIds_50km ?: [0]).") THEN 5
        //         WHEN region_id IN (".implode(',', $regionsIds_100km ?: [0]).") THEN 4
        //         WHEN region_id IN (".implode(',', $regionsIds_150km ?: [0]).") THEN 3
        //         WHEN region_id IN (".implode(',', $regionsIds_200km ?: [0]).") THEN 2
        //         WHEN region_id IN (".implode(',', $regionsIds_250km ?: [0]).") THEN 1
        //         ELSE 0
        //     END as location_priority_region
        // ");

        $filteredQuery->select( $selectClauses )
            ->having( 'match_priority', '>', 0 )
            // ->orderByDesc('location_priority_region')
            ->orderByDesc( 'location_priority_ville' )

            ->orderByDesc( 'match_priority' )
            ->orderByDesc( 'keyword_match_score' )
            ->orderByDesc( 'keyword_match_type' )

            ->orderByDesc( "publications_flink.$orderArray[$orderIndex]" );

        return $filteredQuery;
    }
}

if (!function_exists( 'addBoosterToPublicationsToMarketplace' )) {
    // function addBoosterToPublicationsToMarketplace($publications, $request, $perPage, $withAds=false) {
    //     // $perPage = 24;
    //     $regularPerPage = 5*$perPage/6; // 20 regular publications per page
    //     $boostedPerPage = $perPage/6;   // 4 boosted publications per page


    //     // First get all active boosted publications (not paginated)
    //     $boostedQuery = Publication::select(
    //         'id', 'slug', 'user_id', 'etab_id', 'ville_id', 'region_id', 
    //         'image_default', 'images', 'video_compresse', 'prix', 'prixPromo', 'score'
    //     )
    //     ->where('status', 1)
    //     ->whereNotNull('image_default')
    //     ->where('is_marketplace', 1)
    //     ->withCount([
    //         'publications_booster as booster' => function ($query) {
    //             $query->where('date_fin', '>', now());
    //         }
    //     ])
    //     ->having('booster', '>', 0)
    //     ->with([
    //         'ville:id,slug,name,pays_id',
    //         'region:id,slug,name,ville_id',
    //         'user:id,first_name,last_name,slug',
    //         'etablissement:id,nom,slug',
    //         'tags:id,name,slug',
    //         'publications_booster' => function($query) {
    //             $query->select('id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value','message', 'max_impressions', 'last_display')
    //                 ->where('date_fin', '>', now())
    //                 ->orderBy('last_display', 'asc') // Changed to asc to rotate properly
    //                 ->orderBy('id', 'desc');
    //         },
    //         'publications_booster.shopping_publications'
    //     ])
    //     ->orderBy(
    //         PublicationBooster::select('last_display')
    //             ->whereColumn('publication_id', 'publications_flink.id')
    //             ->where('date_fin', '>', now())
    //             ->orderBy('last_display', 'asc')
    //             ->limit(1)
    //     );

    //     // Get ALL boosted publications (not paginated)
    //     $allBoosted = $boostedQuery->get();
    //     $totalBoosted = $allBoosted->count();

    //     // Get paginated regular publications
    //     $regular = $publications->paginate($regularPerPage, ['*'], 'regular_page', $request->page ?? 1);
    //     $totalRegular = $regular->total();

    //     // Calculate how many pages we'll have (based on regular publications)
    //     $totalPages = ceil($totalRegular / $regularPerPage);

    //     // Create a collection for mixed results
    //     $mixedPublications = collect();

    //     // Get regular items for current page
    //     $regularItems = $regular->items();

    //     // Determine which boosted items to use for this page
    //     $boostedItems = collect();
    //     if ($totalBoosted > 0) {
    //         // Calculate starting index for boosted items (circular)
    //         $startIndex = (($request->page ?? 1) - 1) * ($boostedPerPage % $totalBoosted);

    //         // Get boosted items for this page (with circular loop)
    //         for ($i = 0; $i < $boostedPerPage; $i++) {
    //             if ($totalBoosted > 0) {
    //                 $index = ($startIndex + $i) % $totalBoosted;
    //                 $boostedItems->push($allBoosted[$index]);

    //                 // Update last_display for the boosted publication
    //                 if (isset($allBoosted[$index]->publications_booster[0])) {
    //                     $allBoosted[$index]->publications_booster[0]->update([
    //                         'last_display' => now()
    //                     ]);
    //                 }
    //             }
    //         }
    //     }

    //     // Merge regular and boosted items in 5:1 ratio
    //     $bIndex = 0;
    //     $rIndex = 0;
    //     $totalItems = min($perPage, count($regularItems) + $boostedItems->count());

    //     // Random starting point for boosted insertion (between 2-4 regular items before first boosted)
    //     $randomIndex = rand(2, 5);

    //     while ($mixedPublications->count() < $totalItems) {
    //         // Add regular publications
    //         for ($i = 0; $i < $randomIndex && $rIndex < count($regularItems) && $mixedPublications->count() < $totalItems; $i++) {
    //             $mixedPublications->push($regularItems[$rIndex++]);
    //             $randomIndex = rand(2, 5);
    //         }

    //         // Add boosted publication if available
    //         if ($bIndex < $boostedItems->count() && $mixedPublications->count() < $totalItems) {
    //             $mixedPublications->push($boostedItems[$bIndex++]);
    //             $randomIndex = rand(2, 4); // New random position for next boosted
    //         }

    //         // If no more boosted but still regular items, add them
    //         if ($bIndex >= $boostedItems->count() && $rIndex < count($regularItems) && $mixedPublications->count() < $totalItems) {
    //             $mixedPublications->push($regularItems[$rIndex++]);
    //         }
    //     }

    //     // Create a custom paginator
    //     $publicationsWithBooster = new LengthAwarePaginator(
    //         $mixedPublications,
    //         $totalRegular, // Total is based on regular publications
    //         $perPage,
    //         $request->page ?? 1,
    //         ['path' => $request->url(), 'query' => $request->query()]
    //     );

    //     return $publicationsWithBooster;
    // }

    function addBoosterToPublicationsToMarketplace( $publications, $request, $perPage )
    {
        $regularPerPage = 5 * $perPage / 6; // 20 regular publications per page
        $boostedPerPage = $perPage / 6;   // 4 boosted publications per page

        // First get all active boosted publications (not paginated)
        $boostedQuery = Publication::select(
            'id',
            'slug',
            'user_id',
            'etab_id',
            'ville_id',
            'region_id',
            'image_default',
            'image_square',
            'images',
            'video_compresse',
            'prix',
            'prixPromo',
            'score'
        )
            ->where( 'status', 1 )
            ->whereNotNull( 'image_default' )
            ->where( 'is_marketplace', 1 )
            ->withCount( [
                'publications_booster as booster' => function ( $query ) {
                    $query->where( 'date_fin', '>', now() );
                }
            ] )
            ->having( 'booster', '>', 0 )
            ->with( [
                'ville:id,slug,name,pays_id',
                'region:id,slug,name,ville_id',
                'user:id,first_name,last_name,slug',
                'etablissement:id,nom,slug',
                'tags:id,name,slug',
                'publications_booster' => function ( $query ) {
                    $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message', 'max_impressions', 'last_display' )
                        ->where( 'date_fin', '>', now() )
                        ->orderBy( 'last_display', 'asc' ) // Changed to asc to rotate properly
                        ->orderBy( 'id', 'desc' );
                },
                'publications_booster.shopping_publications'
            ] )
            ->orderBy(
                PublicationBooster::select( 'last_display' )
                    ->whereColumn( 'publication_id', 'publications_flink.id' )
                    ->where( 'date_fin', '>', now() )
                    ->orderBy( 'last_display', 'asc' )
                    ->limit( 1 )
            );

        // Get ALL boosted publications (not paginated)
        $allBoosted = $boostedQuery->get();
        $totalBoosted = $allBoosted->count();

        // Get paginated regular publications
        $regular = $publications->paginate( $regularPerPage, ['*'], 'regular_page', $request->page ?? 1 );
        $totalRegular = $regular->total();

        // Calculate how many pages we'll have (based on regular publications)
        $totalPages = ceil( $totalRegular / $regularPerPage );

        // Create a collection for mixed results
        $mixedPublications = collect();

        // Get regular items for current page
        $regularItems = $regular->items();

        // Determine which boosted items to use for this page
        $boostedItems = collect();
        if ($totalBoosted > 0) {
            // Calculate starting index for boosted items (circular)
            $startIndex = ( ( $request->page ?? 1 ) - 1 ) * ( $boostedPerPage % $totalBoosted );

            // Get boosted items for this page (with circular loop)
            for ($i = 0; $i < $boostedPerPage; $i++) {
                if ($totalBoosted > 0) {
                    $index = ( $startIndex + $i ) % $totalBoosted;
                    $boostedItems->push( $allBoosted[$index] );

                    // Update last_display for the boosted publication
                    if (isset( $allBoosted[$index]->publications_booster[0] )) {
                        $allBoosted[$index]->publications_booster[0]->update( [
                            'last_display' => now()
                        ] );
                    }
                }
            }
        }

        // Merge regular and boosted items in 5:1 ratio
        $bIndex = 0;
        $rIndex = 0;
        $totalItems = min( $perPage, count( $regularItems ) + $boostedItems->count() );

        // Random starting point for boosted insertion (between 2-4 regular items before first boosted)
        $randomIndex = rand( 2, 5 );

        // Determine ad positions if ads are enabled
        // $adPositions = [];
        // if ($withAds) {
        //     // First ad position between 2 and 8
        //     $adPositions[] = rand(2, 8);

        //     // Second ad position between 12 and 24, ensuring it's after the first ad
        //     do {
        //         $secondAdPos = rand(12, 24);
        //     } while ($secondAdPos <= $adPositions[0]);

        //     $adPositions[] = $secondAdPos;
        //     sort($adPositions); // Ensure they're in order
        // }

        $currentPosition = 0;
        $adIndex = 0;

        while ($mixedPublications->count() < $totalItems) {
            // Check if we should insert an ad at this position
            // if ($withAds && $adIndex < count($adPositions)) {
            //     if ($currentPosition >= $adPositions[$adIndex]) {
            //         $mixedPublications->push((object)['type' => 'ad']);
            //         $adIndex++;
            //         $currentPosition++;
            //         continue; // Skip to next iteration after adding ad
            //     }
            // }

            // Add regular publications
            for ($i = 0; $i < $randomIndex && $rIndex < count( $regularItems ) && $mixedPublications->count() < $totalItems; $i++) {
                $mixedPublications->push( $regularItems[$rIndex++] );
                $currentPosition++;
                $randomIndex = rand( 2, 5 );
            }

            // Add boosted publication if available
            if ($bIndex < $boostedItems->count() && $mixedPublications->count() < $totalItems) {
                $mixedPublications->push( $boostedItems[$bIndex++] );
                $currentPosition++;
                $randomIndex = rand( 2, 4 ); // New random position for next boosted
            }

            // If no more boosted but still regular items, add them
            if ($bIndex >= $boostedItems->count() && $rIndex < count( $regularItems ) && $mixedPublications->count() < $totalItems) {
                $mixedPublications->push( $regularItems[$rIndex++] );
                $currentPosition++;
            }
        }

        // Create a custom paginator
        $publicationsWithBooster = new LengthAwarePaginator(
            $mixedPublications,
            $totalRegular, // Total is based on regular publications
            $perPage,
            $request->page ?? 1,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $publicationsWithBooster;
    }
}

if (!function_exists( 'addBoosterToPublicationsToFlux' )) {
    function addBoosterToPublicationsToFlux( $publications, $request, $perPage = 4, $withAds = false )
    {
        // Configuration
        $regularPerPage = 4;
        $boostedPerPage = 1;
        $adsPerPage = $withAds ? 1 : 0;
        $currentPage = $request->page ?? 1;

        // First get paginated regular publications
        $regular = $publications->paginate( $regularPerPage, ['*'], 'regular_page', $currentPage );
        $totalRegular = $regular->total();
        $regularItems = $regular->items();

        // Optimized boosted publications query with chunking
        $boostedItems = collect();
        $totalBoosted = 0;

        // First get just the IDs of boosted publications for rotation
        $boostedIds = Publication::where( 'status', 1 )
            ->whereNotNull( 'image_default' )
            ->whereHas( 'publications_booster', function ( $query ) {
                $query->where( 'date_fin', '>', now() );
            } )
            ->with( [
                'publications_booster' => function ( $query ) {
                    $query->where( 'date_fin', '>', now() )->orderBy( 'last_display', 'asc' );
                }
            ] )
            ->get()
            ->sortBy( function ( $publication ) {
                return $publication->publications_booster->first()->last_display ?? now();
            } )
            ->pluck( 'id' )
            ->toArray();

        // return $boostedIds;

        $totalBoosted = count( $boostedIds );

        if ($totalBoosted > 0) {
            // Calculate which IDs we need for this page
            $startIndex = ( $currentPage - 1 ) % $totalBoosted;
            $neededIds = [];

            for ($i = 0; $i < $boostedPerPage; $i++) {
                $index = ( $startIndex + $i ) % $totalBoosted;
                $neededIds[] = $boostedIds[$index];
            }

            // Now fetch only the needed boosted publications with relationships
            $boostedItems = Publication::whereIn( 'id', $neededIds )
                ->select(
                    'id',
                    'slug',
                    'user_id',
                    'etab_id',
                    'ville_id',
                    'region_id',
                    'pays_id',
                    'image_default',
                    'image_square',
                    'images',
                    'video_compresse',
                    'prix',
                    'prixPromo',
                    'score',
                    'reference',
                    'cat_g_id',
                    'tele',
                    'tele_whatsapp',
                    'contact',
                    'description',
                    'disponibilite',
                    'disponibilite_date',
                    'created_at'
                )
                ->with( [
                    'pays:id,slug,name',
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'tags:id,name,slug',
                    'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                    'etablissement:id,nom,logo,slug,is_verified,created_at',
                    'modes_laivraison:id,name',
                    'statistiques' => function ( $query ) {
                        $query->selectRaw( '
                            publication_id,
                            SUM(click_contact) as click_contact,
                            SUM(click_tele) as click_tele,
                            SUM(click_tele_whatsapp) as click_tele_whatsapp,
                            SUM(click_partage) as click_partage,
                            SUM(click_detail) as click_detail,
                            SUM(vues) as vues
                        ' )->groupBy( 'publication_id' );
                    },
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', now() )
                            ->orderBy( 'last_display', 'asc' )
                            // ->orderBy('id', 'desc')
                            ->inRandomOrder()
                        ;
                    },
                    'publications_booster.shopping_publications',
                ] )
                ->get();

            // Update last_display for the fetched boosted publications
            foreach ($boostedItems as $item) {
                if ($item->publications_booster->isNotEmpty()) {
                    $item->publications_booster->first()->update( ['last_display' => now()] );
                }
                // Mark as boosted
                $item->booster = 1;
            }
        }

        // Prepare ad item if needed
        $adItem = null;
        if ($withAds && $currentPage % 2 !== 0) {
            $adItem = (object) [
                'type' => 'ad',
            ];
        }

        // Merge items with the same logic as original
        $mixedPublications = collect();
        $bIndex = 0;
        $rIndex = 0;
        $totalItems = min( $perPage, count( $regularItems ) + $boostedItems->count() + ( $adItem ? 1 : 0 ) );

        // Random positions for boosted and ad insertion (between 1-3)
        $boostPosition = rand( 1, 3 );
        $adPosition = rand( 1, 3 );

        // Ensure ad and boost positions don't collide
        if ($adItem && $boostedItems->count() > 0 && $adPosition === $boostPosition) {
            $adPosition = ( $adPosition % 3 ) + 1;
        }

        while ($mixedPublications->count() < $totalItems) {
            $currentPosition = $mixedPublications->count();

            // Add ad if available and at the right position
            if ($adItem && $currentPosition === $adPosition) {
                $mixedPublications->push( $adItem );
                continue;
            }

            // Add boosted publication if available and at the right position
            if ($bIndex < $boostedItems->count() && $currentPosition === $boostPosition) {
                $mixedPublications->push( $boostedItems[$bIndex++] );
                continue;
            }

            // Add regular publications if available
            if ($rIndex < count( $regularItems )) {
                $mixedPublications->push( $regularItems[$rIndex++] );
            }
        }

        // Make sure we don't exceed the perPage limit
        $mixedPublications = $mixedPublications->slice( 0, $perPage );

        // Create paginator
        return new LengthAwarePaginator(
            $mixedPublications,
            $totalRegular,
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }


    function addBoosterToPublicationsToFluxTest( $publications, $request, $perPage = 4, $withAds = false )
    {
        // Configuration
        $regularPerPage = 4;
        $boostedPerPage = 1;
        $adsPerPage = $withAds ? 1 : 0;
        $currentPage = $request->page ?? 1;

        // First get paginated regular publications
        $regular = $publications->paginate( $regularPerPage, ['*'], 'regular_page', $currentPage );
        $totalRegular = $regular->total();
        $regularItems = $regular->items();

        // Optimized boosted publications query with chunking
        $boostedItems = collect();
        $totalBoosted = 0;

        // First get just the IDs of boosted publications for rotation
        $boostedIds = Publication::where( 'status', 1 )
            ->whereNotNull( 'image_default' )
            ->whereHas( 'publications_booster', function ( $query ) {
                $query->where( 'date_fin', '>', now() );
            } )
            ->with( [
                'publications_booster' => function ( $query ) {
                    $query->where( 'date_fin', '>', now() )->orderBy( 'last_display', 'asc' );
                }
            ] )
            ->get()
            ->sortBy( function ( $publication ) {
                return $publication->publications_booster->first()->last_display ?? now();
            } )
            ->pluck( 'id' )
            ->toArray();

        // return $boostedIds;

        $totalBoosted = count( $boostedIds );

        if ($totalBoosted > 0) {
            // Calculate which IDs we need for this page
            $startIndex = ( $currentPage - 1 ) % $totalBoosted;
            $neededIds = [];

            for ($i = 0; $i < $boostedPerPage; $i++) {
                $index = ( $startIndex + $i ) % $totalBoosted;
                $neededIds[] = $boostedIds[$index];
            }

            // Now fetch only the needed boosted publications with relationships
            $boostedItems = Publication::whereIn( 'id', $neededIds )
                ->select(
                    'id',
                    'slug',
                    'user_id',
                    'etab_id',
                    'ville_id',
                    'region_id',
                    'pays_id',
                    'image_default',
                    'image_square',
                    'images',
                    'video_compresse',
                    'prix',
                    'prixPromo',
                    'score',
                    'reference',
                    'cat_g_id',
                    'tele',
                    'tele_whatsapp',
                    'contact',
                    'description',
                    'disponibilite',
                    'disponibilite_date',
                    'created_at'
                )
                ->with( [
                    'pays:id,slug,name',
                    'ville:id,slug,name,pays_id',
                    'region:id,slug,name,ville_id',
                    'tags:id,name,slug',
                    'user:id,first_name,last_name,avatar,slug,is_verified,created_at',
                    'etablissement:id,nom,logo,slug,is_verified,created_at',
                    'modes_laivraison:id,name',
                    'statistiques' => function ( $query ) {
                        $query->selectRaw( '
                            publication_id,
                            SUM(click_contact) as click_contact,
                            SUM(click_tele) as click_tele,
                            SUM(click_tele_whatsapp) as click_tele_whatsapp,
                            SUM(click_partage) as click_partage,
                            SUM(click_detail) as click_detail,
                            SUM(vues) as vues
                        ' )->groupBy( 'publication_id' );
                    },
                    'publications_booster' => function ( $query ) {
                        $query->select( 'id', 'publication_id', 'objectif_name', 'objectif_action', 'objectif_value', 'message' )
                            ->where( 'date_fin', '>', now() )
                            ->orderBy( 'last_display', 'asc' )
                            // ->orderBy('id', 'desc')
                            ->inRandomOrder()
                        ;
                    },
                    'publications_booster.shopping_publications',
                ] )
                ->get();

            // return $boostedItems;
            // Update last_display for the fetched boosted publications
            foreach ($boostedItems as $item) {
                if ($item->publications_booster->isNotEmpty()) {
                    $item->publications_booster->first()->update( ['last_display' => now()] );
                }
                // Mark as boosted
                $item->booster = 1;
            }
        }

        // Prepare ad item if needed
        $adItem = null;
        if ($withAds && $currentPage % 2 !== 0) {
            $adItem = (object) [
                'type' => 'ad',
            ];
        }

        // Merge items with the same logic as original
        $mixedPublications = collect();
        $bIndex = 0;
        $rIndex = 0;
        $totalItems = min( $perPage, count( $regularItems ) + $boostedItems->count() + ( $adItem ? 1 : 0 ) );

        // Random positions for boosted and ad insertion (between 1-3)
        $boostPosition = rand( 1, 3 );
        $adPosition = rand( 1, 3 );

        // Ensure ad and boost positions don't collide
        if ($adItem && $boostedItems->count() > 0 && $adPosition === $boostPosition) {
            $adPosition = ( $adPosition % 3 ) + 1;
        }

        while ($mixedPublications->count() < $totalItems) {
            $currentPosition = $mixedPublications->count();

            // Add ad if available and at the right position
            if ($adItem && $currentPosition === $adPosition) {
                $mixedPublications->push( $adItem );
                continue;
            }

            // Add boosted publication if available and at the right position
            if ($bIndex < $boostedItems->count() && $currentPosition === $boostPosition) {
                $mixedPublications->push( $boostedItems[$bIndex++] );
                continue;
            }

            // Add regular publications if available
            if ($rIndex < count( $regularItems )) {
                $mixedPublications->push( $regularItems[$rIndex++] );
            }
        }

        // Make sure we don't exceed the perPage limit
        $mixedPublications = $mixedPublications->slice( 0, $perPage );

        // Create paginator
        return new LengthAwarePaginator(
            $mixedPublications,
            $totalRegular,
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

}

if (!function_exists( 'generateSlugCombinations' )) {
    function generateSlugCombinations( $slug )
    {
        // Words to group with their following word
        // $groupWords = ['de', 'au', 'à', 'la', 'le', 'des', 'et', 'etc', 'du', 'a', 'pour', 'sur', 'en', '&'];
        $groupWords = [
            // French articles & contractions
            'de',
            'au',
            'à',
            'la',
            'le',
            'les',
            'des',
            'du',
            'un',
            'une',
            'l\'',
            'aux',
            'd\'',
            // French prepositions
            'pour',
            'sur',
            'en',
            'dans',
            'par',
            'avec',
            'sans',
            'sous',
            'vers',
            'chez',
            'entre',
            'pendant',
            'depuis',
            // French conjunctions & common terms
            'et',
            'ou',
            'mais',
            'donc',
            'or',
            'ni',
            'car',
            'que',
            'comme',
            'si',
            'lorsque',
            'quand',
            'etc',
            '&',

            // English articles
            'a',
            'an',
            'the',
            // English prepositions
            'of',
            'to',
            'in',
            'for',
            'on',
            'with',
            'at',
            'by',
            'from',
            'into',
            'about',
            'over',
            'under',
            'through',
            'before',
            'after',
            'between',
            // English conjunctions & common terms
            'and',
            'or',
            'but',
            'so',
            'yet',
            'for',
            'nor',
            'as',
            'if',
            'when',
            'while',
            'because',
            'since',
            'etc',
            '&'
        ];

        // First pass: Split and group words
        $parts = explode( '-', $slug );
        $keywords = [];
        $groupedIndices = [];

        for ($i = 0; $i < count( $parts ); $i++) {
            if (in_array( $parts[$i], $groupWords ) && isset( $parts[$i + 1] )) {
                $keywords[] = $parts[$i] . '-' . $parts[$i + 1];
                $groupedIndices[] = count( $keywords ) - 1;
                $i++; // Skip next word
            } else {
                $keywords[] = $parts[$i];
            }
        }

        // Generate all combinations
        $combinations = [];
        $n = count( $keywords );

        for ($mask = 1; $mask < ( 1 << $n ); $mask++) {
            $current = [];
            $originalOrderScore = 0;
            $position = 1;

            for ($i = 0; $i < $n; $i++) {
                if ($mask & ( 1 << $i )) {
                    $current[] = $keywords[$i];
                    $originalOrderScore += $position * ( 1 << ( $n - $i ) );
                    $position++;
                }
            }

            $combinations[] = [
                'slug' => implode( '-', $current ),
                'word_count' => count( $current ),
                'order_score' => $originalOrderScore
            ];
        }

        // Remove duplicates
        $unique = [];
        foreach ($combinations as $combo) {
            $unique[$combo['slug']] = $combo;
        }

        // Sort by word count (desc), then original order score (asc)
        usort( $unique, function ( $a, $b ) {
            return $b['word_count'] <=> $a['word_count'] ?: $a['order_score'] <=> $b['order_score'];
        } );

        return array_column( $unique, 'slug' );
    }
}


if (!function_exists( 'generateSlugParts' )) {
    function generateSlugParts( $search )
    {
        $minLength = 3;
        $searchTerm = $search;
        $parts = explode( '-', $searchTerm );
        $results = [];

        // Helper function to check if we should continue
        $shouldContinue = function ( $parts ) use ( $minLength ) {
            foreach ($parts as $part) {
                if (Str::length( $part ) > $minLength) {
                    return true;
                }
            }
            return false;
        };

        // Continue until any part reaches minimum length
        while ($shouldContinue( $parts )) {
            // Add current combination to results
            $current = implode( '-', array_filter( $parts ) );
            if (!in_array( $current, $results )) {
                $results[] = $current;
            }

            // Generate new versions by removing one character from each part
            foreach ($parts as $i => $part) {
                if (Str::length( $part ) > $minLength) {
                    $newParts = $parts;
                    $newParts[$i] = Str::substr( $part, 0, -1 );
                    $newCombination = implode( '-', array_filter( $newParts ) );

                    if (!in_array( $newCombination, $results )) {
                        $results[] = $newCombination;
                    }
                }
            }

            // Remove last character from all parts that are long enough
            $parts = array_map( function ( $part ) use ( $minLength ) {
                return ( Str::length( $part ) > $minLength ? Str::substr( $part, 0, -1 ) : $part );
            }, $parts );
        }

        // Add final version if not already present
        $final = implode( '-', array_filter( $parts ) );
        if (!in_array( $final, $results )) {
            $results[] = $final;
        }

        // Remove duplicates and empty terms, then re-index
        $results = array_unique( $results );
        $results = array_filter( $results, function ( $term ) {
            return trim( $term, '-' ) !== '';
        } );

        return array_values( $results );

    }
}

if (!function_exists( 'getNearbyLocation' )) {
    function getNearbyLocation( $locationId, $rayonKm = 50, $type = "ville" )
    {
        // 1. Trouver les coordonnées de la ville de référence
        if ($type === "ville") {
            $reference = Ville::find( $locationId );
            $locationsProches = Ville::query();
        } else {
            $reference = Region::find( $locationId );
            $locationsProches = Ville::query();
        }



        if (!$reference) {
            return collect();
        }

        // 2. Requête corrigée avec parenthèse fermante
        $locationsProches = $locationsProches->selectRaw(
            'id, name, latitude, longitude,
            (6371 * ACOS(
                COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                COS(RADIANS(longitude) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(latitude))
            )) AS distance', // <-- Parenthèse fermante ajoutée ici
            [
                $reference->latitude,
                $reference->longitude,
                $reference->latitude
            ]
        )
            ->having( 'distance', '<', $rayonKm )
            ->orderBy( 'distance' )
            ->get()->pluck( 'id' )->toArray();

        return $locationsProches;
    }
}