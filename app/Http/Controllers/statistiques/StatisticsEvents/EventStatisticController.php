<?php

namespace App\Http\Controllers\StatisticsEvents;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Annonce;
use App\Models\publicite;
use App\Models\Etablissement;

class EventStatisticController extends Controller
{

    // public function incrementViewCount(Request $request, $id) {
    //     try {
    //         // Validate the incoming request data
    //         $request->validate([
    //             'view_count' => 'required|integer|min:1',
    //             'annonce_type' => 'required',
    //         ]);

    //         $type = $request->input('annonce_type');

    //         // Find the Annonce by ID
    //         $annonce = Annonce::findOrFail($id);

    //         // Increment the view count
    //         $annonce->nbre_vue += $request->input('view_count');
    //         if ($type === "video") {
    //             $annonce->nbre_vue_video += $request->input('view_count');
    //         }
    //         $annonce->save();

    //         return response()->json(['message' => 'View count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update view count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementViewDetailsCount($id) {
    //     try {
    //         $annonce = Annonce::findOrFail($id);
    //         $annonce->vue_detail++;
    //         $annonce->save();

    //         return response()->json(['message' => 'View details count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update view details count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementClickSurTeleCount($id) {
    //     try {
    //         $annonce = Annonce::findOrFail($id);
    //         $annonce->nbre_click_sur_tele++;
    //         $annonce->save();

    //         return response()->json(['message' => 'Click on tele count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update click on tele count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementFavorisCount($id) {
    //     try {
    //         $annonce = Annonce::findOrFail($id);
    //         $annonce->nbre_favoris++;
    //         $annonce->save();

    //         return response()->json(['message' => 'Favoris count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update favoris count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function decrementFavorisCount($id) {
    //     try {
    //         $annonce = Annonce::findOrFail($id);
    //         $annonce->nbre_favoris--;
    //         $annonce->save();

    //         return response()->json(['message' => 'Favoris count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update favoris count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementClickSurMessageCount($id) {
    //     try {
    //         $annonce = Annonce::findOrFail($id);
    //         $annonce->nbre_click_sur_message++;
    //         $annonce->save();

    //         return response()->json(['message' => 'Click on message count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update click on message count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementVueVideoCount($id) {
    //     try {
    //         $annonce = Annonce::findOrFail($id);
    //         $annonce->nbre_vue_video++;
    //         $annonce->save();

    //         return response()->json(['message' => 'Video view count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update video view count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementClickSurWhatCount($id) {
    //     try {
    //         $annonce = Annonce::findOrFail($id);
    //         $annonce->nbre_click_sur_what++;
    //         $annonce->save();

    //         return response()->json(['message' => 'Click on WhatsApp count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update click on WhatsApp count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    /* ----------------------- Sponsored Model Methods --------------------------- */

    public function incrementSponsoredView(Request $request, $id) {
        try {
            // Validate the incoming request data
            $request->validate([
                'sponsored_view_count' => 'required|integer|min:1',
            ]);

            // Find the publicite by ID
            $publicite = publicite::findOrFail($id);

            // Increment the view count
            $publicite->nbre_vue += $request->input('sponsored_view_count');
            $publicite->save();

            return response()->json(['message' => 'Sponsored View count updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update sponsored view count', 'details' => $e->getMessage()], 500);
        }
    }

    public function incrementSponsoredClick($id) {
        try {
            $publicite = publicite::findOrFail($id);
            $publicite->nbre_clic++;
            $publicite->save();

            return response()->json(['message' => 'Sponsored Click count updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update Sponsored click ', 'details' => $e->getMessage()], 500);
        }
    }

    /* ----------------------- Etablissement Model Methods --------------------------- */

    // public function incrementViewCountBoutique(Request $request, $id) {
    //     try {
    //         // Validate the incoming request data
    //         $request->validate([
    //             'boutique_view_count' => 'required|integer|min:1',
    //         ]);

    //         // Find the etablissement by ID
    //         $etablissement = Etablissement::findOrFail($id);

    //         // Increment the view count
    //         $etablissement->nbre_vue += $request->input('boutique_view_count');
    //         $etablissement->save();

    //         return response()->json(['message' => 'Etablissement View count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update Etablissement view count', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementClickSurTeleCountBoutique($id) {
    //     try {
    //         $etablissement = Etablissement::findOrFail($id);
    //         $etablissement->nbre_click_sur_tele++;
    //         $etablissement->save();

    //         return response()->json(['message' => 'Boutique Click count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update Boutique click ', 'details' => $e->getMessage()], 500);
    //     }
    // }

    // public function incrementClickItineraireCountBoutique($id) {
    //     try {
    //         $etablissement = Etablissement::findOrFail($id);
    //         $etablissement->nbre_click_itineraire++;
    //         $etablissement->save();

    //         return response()->json(['message' => 'Boutique Click count updated successfully']);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'Failed to update Boutique click ', 'details' => $e->getMessage()], 500);
    //     }
    // }
}
