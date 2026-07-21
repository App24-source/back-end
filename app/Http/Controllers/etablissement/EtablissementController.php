<?php

namespace App\Http\Controllers\etablissement;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Etablissement;
use App\Models\User;

class EtablissementController extends Controller
{
    public function VerifySlug(Request $request, $type) {
        // $type = $request->query('type');
        $input = $request->get('input');
        // return $input;
        try{
            if($type == "name") {
                return $this->generateOrCheckSlug($input, false);
            } else if($type == "slug") {
                return $this->generateOrCheckSlug($input, true);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 500, 'error' => 'An error occurred: ' . $e->getMessage()]);
        }

    }


    private static function generateOrCheckSlug($input, $isSlug = false) {
        if (!$isSlug) {
            // Case: Input is a name, generate a unique slug
            $slug = Str::slug($input, '_');
            $slugBase = $slug;
            $count = 1;

            while (
                User::where('slug', $slug)->exists() || 
                Etablissement::where('slug', $slug)->exists()
            ) {
                $slug = $slugBase . '_' . $count;
                $count++;
            }

            // return ['slug' => $slug, 'exists' => false];
            return response()->json(['status' => 201, 'slug' => $slug]);
        } else {
            // Case: Input is a slug, check existence
            $exists = User::where('slug', $input)->exists() || Etablissement::where('slug', $input)->exists();

            if ($exists) {
                // Suggest alternative slugs
                $suggestions = [];
                $count = 1;
                $slugBase = $input;

                while (count($suggestions) < 3) { // Generate 3 suggestions
                    $newSlug = $slugBase . '_' . $count;
                    if (
                        !User::where('slug', $newSlug)->exists() && 
                        !Etablissement::where('slug', $newSlug)->exists()
                    ) {
                        $suggestions[] = $newSlug;
                    }
                    $count++;
                }

                // return ['slug' => $input, 'exists' => true, 'suggestions' => $suggestions];
                return response()->json(['status' => 201, 'isExist' => true, 'suggestions' => $suggestions]);
            }

            return response()->json(['status' => 201, 'isExist' => false, 'suggestions' => []]);
        }
    }
}
