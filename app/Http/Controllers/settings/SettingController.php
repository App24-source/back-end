<?php

namespace App\Http\Controllers\settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\Cv_User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Exception;

class SettingController extends Controller
{
    // Update user information
    public function updateUserInfo(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'id' => 'required|integer|exists:users,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $request->id,
        ]);
        
        try {
            // Find the user
            $user = User::findOrFail($validated['id']);
            
            // Update user information
            $user->first_name = $validated['first_name'];
            $user->last_name = $validated['last_name'];
            $user->email = $validated['email'];
            $user->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Personal information updated successfully',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update personal information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Update user password
    public function updatePassword(Request $request): JsonResponse
    {
        // Validate the request data
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|different:current_password',
            'confirm_password' => 'required|string|same:new_password',
        ]);
        
        try {
            // Find the user
            $user = User::findOrFail($validated['user_id']);
            
            // Verify current password
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect',
                ], 400);
            }
            
            // Update password
            $user->password = Hash::make($validated['new_password']);
            $user->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Update user personal information
    public function updateUserPersonalInfo(Request $request): JsonResponse
    {
        try {
            // Validate the request data
            $validated = $request->validate([
                'id' => 'required|integer|exists:users,id',
                'ville_id' => 'required|integer',
                'region_id' => 'required|integer',
                'adresse' => 'nullable|string|max:100',
                'tele' => 'nullable|string|max:20',
            ]);
            
            // Additional validation for phone if provided
            if (!empty($validated['tele'])) {
                $phoneRegex = '/^\+?[0-9]{8,15}$/';
                if (!preg_match($phoneRegex, preg_replace('/\s+/', '', $validated['tele']))) {
                    return response()->json([
                        'success' => false,
                        'error' => ['tele' => ['Please enter a valid phone number']],
                    ], 422);
                }
            }
            
            // Find the user by ID
            $user = User::findOrFail($validated['id']);
           
            // Check if the authenticated user is updating their own data
            if (auth()->id() !== $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access',
                ], 403);
            }
            
            // Update the user information
            $user->ville_id = $validated['ville_id'];
            $user->region_id = $validated['region_id'];
            $user->adresse = $validated['adresse'] ?? '';
            $user->tele = $validated['tele'] ?? '';
           
            // Save the changes
            $user->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Personal information updated successfully',
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                ]
            ]);
           
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update personal information: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Update user CVs
    public function updateUserCVs(Request $request): JsonResponse
    {
        try {
            // Validate the request data
            $validated = $request->validate([
                'id' => 'required|integer|exists:users,id',
                'cvs' => 'array|max:4',
                'cvs.*' => 'file|mimes:pdf,doc,docx,txt,jpg,jpeg,png,webp|max:5120', // 5MB max file size
                'selected' => 'array',
            ]);

            // Find the user by ID
            $user = User::findOrFail($validated['id']);
        
            // Check if the authenticated user is updating their own data
            if (auth()->id() !== $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access',
                ], 403);
            }

            // Check if CVs are uploaded
            if (!$request->hasFile('cvs')) {
                return response()->json([
                    'success' => false,
                    'error' => 'No CV files uploaded',
                ], 400);
            }

            $files = $request->file('cvs');
            $selectedCvs = $request->input('selected', []);

            // Check total number of existing CVs
            $existingCvCount = Cv_User::where('user_id', $user->id)->count();
            $totalCvCount = $existingCvCount + count($files);

            // Validate number of files
            if ($totalCvCount > 4) {
                return response()->json([
                    'success' => false,
                    'error' => 'Maximum of 4 CVs allowed',
                ], 400);
            }

            $fileDetails = [];
            $currentYear = now()->year;

            // Process and store new CVs
            foreach ($files as $index => $file) {
                $originalName = $file->getClientOriginalName();
                $mimeType = $file->getClientMimeType();
                $size = $file->getSize();
                
                // Check if this CV is selected as default
                $isDefault = isset($selectedCvs[$index]) && $selectedCvs[$index] === '1';

                // Create unique filename
                $uniqueName = pathinfo($originalName, PATHINFO_FILENAME) . 
                    '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                // Store file
                $cvDirectory = 'user/cv/' . $currentYear . '/' . $user->id . '/';
                $cvPath = $file->storeAs($cvDirectory, $uniqueName, 'ftp');

                // Create CV record
                $cvRecord = Cv_User::create([
                    'user_id' => $user->id,
                    'file' => $cvPath,
                    'name' => $uniqueName,
                    'extension' => $mimeType,
                    'default_cv' => $isDefault,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Add file details
                $fileDetails[] = [
                    'id' => $cvRecord->id,
                    'file_name' => $uniqueName,
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'stored_path' => $cvPath,
                    'is_default' => $isDefault,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'CVs updated successfully',
                'cvs' => $fileDetails
            ]);
        
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update CVs: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Delete user CV
    public function deleteUserCV($userId, $cvId): JsonResponse
    {
        try {
            // Find the CV
            $cv = Cv_User::where('id', $cvId)
                ->where('user_id', $userId)
                ->firstOrFail();

            // Find the user by ID
            $user = User::findOrFail($userId);

            // Check if the authenticated user is deleting their own CV
            if (auth()->id() !== $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access',
                ], 403);
            }

            // Delete the file from storage
            if (Storage::disk('ftp')->exists($cv->file)) {
                Storage::disk('ftp')->delete($cv->file);
            }

            // Delete the CV record from database
            $cv->delete();

            return response()->json([
                'success' => true,
                'message' => 'CV deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete CV: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Update user about you
    public function updateAboutYou(Request $request): JsonResponse
    {
        try {
            // Validate the request data
            $validated = $request->validate([
                'id' => 'required|integer|exists:users,id',
                'a_propos' => 'nullable|string|max:700', // Adjust max length as needed
            ]);
            
            // Find the user by ID
            $user = User::findOrFail($validated['id']);
            
            // Check if the authenticated user is updating their own data
            if (auth()->id() !== $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access',
                ], 403);
            }
            
            // Update the about section
            $user->a_propos = $validated['a_propos'] ?? '';
            $user->save();
            
            return response()->json([
                'success' => true,
                'message' => 'About you section updated successfully',
                'user' => [
                    'id' => $user->id,
                    'a_propos' => $user->a_propos,
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update about you section: ' . $e->getMessage(),
            ], 500);
        }
    }
}
