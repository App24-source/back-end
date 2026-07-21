<?php

namespace App\Http\Controllers\statistiques;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class InterestController extends Controller
{
    /**
     * Increment interests counter for a user/etablissement and tag
     */
    public function incrementInterest(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'userId' => 'nullable|integer',
                'etabId' => 'nullable|integer',
                'tagId' => 'required|integer',
                'action' => 'required|string|in:save,view_more,share'
            ]);

            $userId = $request->input('userId');
            $etabId = $request->input('etabId');
            $tagId = $request->input('tagId');
            $action = $request->input('action');

            // Ensure at least one of user_id or etab_id is provided
            if (!$userId && !$etabId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either user_id or etab_id must be provided'
                ], 400);
            }

            // Check if record exists
            $existingRecord = DB::table('user_tags_filters_flink')
                ->where('tag_id', $tagId)
                ->where(function($query) use ($userId, $etabId) {
                    if ($userId) {
                        $query->where('user_id', $userId);
                    }
                    if ($etabId) {
                        $query->where('etab_id', $etabId);
                    }
                })
                ->first();

            if ($existingRecord) {
                // Update existing record using interestcompteur field
                DB::table('user_tags_filters_flink')
                    ->where('id', $existingRecord->id)
                    ->increment('interestcompteur', 1);
                
                $newCount = ($existingRecord->interestcompteur ?? 0) + 1;
            } else {
                // Create new record using interestcompteur field
                $insertData = [
                    'tag_id' => $tagId,
                    'compteur' => 0,
                    'interestcompteur' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                if ($userId) {
                    $insertData['user_id'] = $userId;
                }
                if ($etabId) {
                    $insertData['etab_id'] = $etabId;
                }

                DB::table('user_tags_filters_flink')->insert($insertData);
                $newCount = 1;
            }

            return response()->json([
                'success' => true,
                'message' => 'Interest counter updated successfully',
                'action' => $action,
                'tagId' => $tagId,
                'interests_compteur' => $newCount
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating interest counter: ' . $e->getMessage());
            Log::error('Request data: ' . json_encode($request->all()));
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update interest counter',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
