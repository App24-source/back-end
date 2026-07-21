<?php

namespace App\Http\Controllers\annonce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Annonce;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostulerEmploiController extends Controller
{
    // public function store(Request $request) {
    //     // Validate the incoming request data
    //     $request->validate([
    //         'user_id' => 'required|integer', // User ID must be provided and must be an integer
    //         'annonce_id' => 'required|integer', // Annonce (job) ID is required and must be an integer
    //         'email' => 'required|string', // Email is required and must be a string
    //         'tele' => 'required|string', // Phone number is required and must be a string
    //         'resume' => 'required', // Resume is required, could be a file or path
    //         'letter_motivation' => 'nullable|string', // Motivation letter is optional
    //     ]);

    //     // Initialize variable to store the resume path
    //     $resumePath = "";

    //     // Get the current year for directory structure
    //     $currentYear = date('Y');

    //     // Define the directory path dynamically based on the current year
    //     $directory = 'user/emploi/cv/' . $currentYear;

    //     // Check if the resume comes from the database or is an uploaded file
    //     if ($request->resume["source"] == "database") {
    //         // Use the existing file from the database
    //         $resumePath = $request->resume["file"];
    //     } else {
    //         // If the resume is a file upload, check if the directory exists on the FTP server
    //         if (!Storage::disk('ftp')->exists($directory)) {
    //             try {
    //                 // Create the directory if it doesn't exist
    //                 Storage::disk('ftp')->makeDirectory($directory);
    //             } catch (\Exception $e) {
    //                 // Handle directory creation failure
    //                 return response()->json(['success' => false, 'message' => 'Failed to create directory.'], 500);
    //             }
    //         }

    //         // Generate a new unique name for the resume
    //         $extension = $request->resume["file"]->getClientOriginalExtension(); // Get the file extension
    //         $newFileName = $request->resume["name"] . '_' . Str::uuid() . '.' . $extension; // Generate a unique name with the extension

    //         // Store the uploaded resume in the specified directory with the new name
    //         $resumePath = $request->resume["file"]->storeAs($directory, $newFileName, 'ftp');
    //     }

    //     // Find the user by ID
    //     $user = User::find($request->user_id);
    //     // Find the annonce (job post) by ID
    //     $annonce = Annonce::find($request->annonce_id);

    //     // Attach the annonce to the user's emplois (job applications) with additional data
    //     $postuler = $user->emplois()->attach($annonce->id, [
    //         'email' => $request->email,
    //         'phoneNumber' => $request->tele,
    //         'cv_postuler' => $resumePath, // Store the resume path
    //         'lettre_motivation' => $request->letter_motivation, // Store the motivation letter if provided
    //     ]);

    //     // Return success response indicating the job application was submitted
    //     return response()->json(['success' => true, 'message' => 'Job application submitted successfully']);
    // }
}
