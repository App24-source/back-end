<?php

namespace App\Http\Controllers\publication\create;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Publication;
use App\Models\Etablissement;
use App\Models\User;
use App\Models\Region;
use App\Models\Ville;
use App\Models\Tags;
use App\Jobs\ProcessVideoJob;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use App\Models\Telephone;

class CreatePublicationController extends Controller
{
    private const BASE_DIRECTORY = 'publication';
    private const VIDEO_DIRECTORY = 'videos';
    private const HLS_DIRECTORY = 'hls';
    private const MINIATURES_DIRECTORY = 'miniature';
    private const IMAGES_DIRECTORY = 'images';
    private const IMAGE_DIRECTORY = 'image';

    public function uploadChunk( Request $request )
    {
        try {
            $chunk = $request->file( 'video_chunk' );
            $chunkNumber = (int) $request->input( 'chunk_number' );
            $totalChunks = (int) $request->input( 'total_chunks' );
            $fileId = $request->input( 'file_id' );
            $originalName = $request->input( 'original_name' );

            $uploadPath = "temp/uploads/{$fileId}";

            if (!Storage::exists( $uploadPath )) {
                Storage::makeDirectory( $uploadPath );
            }

            $chunkPath = storage_path( "app/{$uploadPath}/chunk_{$chunkNumber}.part" );
            $chunk->move( storage_path( "app/{$uploadPath}" ), "chunk_{$chunkNumber}.part" );

            return response()->json( [
                'success' => true,
                'message' => "Chunk {$chunkNumber} received and processing started",
                'Log' => "Received chunk {$chunkNumber} of {$totalChunks} for file {$fileId}",
                'chunk_number' => $chunkNumber,
                'file_id' => $fileId
            ] );

        } catch (\Exception $e) {
            Log::error( "Error processing chunk upload: " . $e->getMessage() );
            return response()->json( [
                'success' => false,
                'message' => 'Failed to process chunk',
                'error' => $e->getMessage()
            ], 500 );
        }
    }

    /**
     * Finalize video processing by combining chunks and converting to HLS
     */
    public function finalizeVideo( Request $request )
    {
        try {
            $fileId = $request->input( 'video_file_id' );
            $originalName = $request->input( 'original_name' );
            $currentYear = date( 'Y' );

            // Define paths
            $tempChunksPath = "temp/uploads/{$fileId}";
            $tempOutputPath = "temp/combined/{$fileId}";

            // Create temp output dir
            Storage::makeDirectory( $tempOutputPath );

            // Get all chunk files and sort them
            $chunkFiles = collect( Storage::files( $tempChunksPath ) )
                ->filter( fn( $file ) => preg_match( '/chunk_(\d+)\.part$/', $file ) )
                ->sort( function ( $a, $b ) {
                    preg_match( '/chunk_(\d+)\.part$/', $a, $matchesA );
                    preg_match( '/chunk_(\d+)\.part$/', $b, $matchesB );
                    return (int) $matchesA[1] - (int) $matchesB[1];
                } );

            if ($chunkFiles->isEmpty()) {
                throw new \Exception( "No chunks found for file ID: {$fileId}" );
            }

            // Combine chunks
            $combinedFilePath = storage_path( "app/{$tempOutputPath}/combined.mp4" );
            file_put_contents( $combinedFilePath, '' ); // create empty
            foreach ($chunkFiles as $chunkFile) {
                file_put_contents( $combinedFilePath, file_get_contents( storage_path( "app/{$chunkFile}" ) ), FILE_APPEND );
            }

            if (!file_exists( $combinedFilePath ) || filesize( $combinedFilePath ) === 0) {
                throw new \Exception( "Failed to combine video chunks." );
            }

            // Dispatch job for processing
            ProcessVideoJob::dispatch(
                $fileId,
                $originalName,
                $combinedFilePath
            );


            // Return early success response
            return response()->json( [
                'success' => true,
                'message' => 'Video chunks merged. Processing started in background.',
                'file_id' => $fileId
            ] );
        } catch (\Exception $e) {
            Log::error( "Error in finalizeVideo: " . $e->getMessage() );

            // Cleanup on failure
            if (isset( $fileId )) {
                Storage::deleteDirectory( "temp/uploads/{$fileId}" );
                Storage::deleteDirectory( "temp/combined/{$fileId}" );
            }

            return response()->json( [
                'success' => false,
                'message' => 'Failed to finalize video: ' . $e->getMessage()
            ], 500 );
        }
    }


    /**
     * Store the publication with processed video
     */





    public function store( Request $request )
    {
        Log::info( "Publication creation started" );
        try {
            // Check for existing publication with the same video_file_id to prevent duplicates
            if ($request->has('video_file_id')) {
                $videoFileId = $request->input('video_file_id');
                $userId = $request->get('user_id');
                
                $existingPublication = Publication::where('user_id', $userId)
                    ->where('video_compresse', 'like', "%{$videoFileId}%")
                    ->first();
                
                if ($existingPublication) {
                    Log::info("Duplicate publication request found for video_file_id: {$videoFileId}. Returning existing publication.");
                    return response()->json([
                        'success' => true,
                        'publication' => $existingPublication->load(['region', 'ville', 'user', 'etablissement', 'tags', 'modes_laivraison']),
                        'video_compresse' => $existingPublication->video_compresse,
                        'processing_details' => [
                            'video_status' => 'processed'
                        ]
                    ]);
                }
            }

            $currentYear = date( 'Y' );
            $directories = [
                'hls' => self::BASE_DIRECTORY . '/' . self::VIDEO_DIRECTORY . "/{$currentYear}/" . self::HLS_DIRECTORY,
                'miniature' => self::BASE_DIRECTORY . '/' . self::MINIATURES_DIRECTORY . "/{$currentYear}",
                'Images' => self::BASE_DIRECTORY . '/' . self::IMAGES_DIRECTORY . "/{$currentYear}",
                'Image' => self::BASE_DIRECTORY . '/' . self::IMAGE_DIRECTORY . "/{$currentYear}",
                'ImageSquare' => self::BASE_DIRECTORY . '/' . self::IMAGE_DIRECTORY . "/{$currentYear}/square",
            ];

            // Create directories if they do not exist
            foreach ($directories as $directory) {
                if (!Storage::disk( 'ftp' )->exists( $directory )) {
                    Storage::disk( 'ftp' )->makeDirectory( $directory );
                }
            }

            // Generate a new reference
            $maxReference = Publication::max( 'reference' );
            $newReference = $maxReference
                ? str_pad( (int) $maxReference + 1, 5, '0', STR_PAD_LEFT )
                : "00001";

            // Add current date with time to the reference
            $dateTime = date( 'YmdHis' ); // Format: YYYYMMDDHHMMSS (no separators)

            // Add unique elements to ensure 100% uniqueness
            $randomString = substr( md5( uniqid( mt_rand(), true ) ), 0, 8 ); // 8-character random string
            $microtime = sprintf( '%06d', ( microtime( true ) * 1000000 ) % 1000000 ); // Microseconds

            // Combine all elements to create a truly unique reference without underscores
            $newImageReference = $newReference . $dateTime . $microtime . $randomString;

            // Create a new publication instance
            $publication = new Publication();
            $publication->reference = $newReference;
            $publication->status = 0;

            // Process images
            $imagePaths = [];
            if ($request->hasFile( 'images' )) {
                foreach ($request->file( 'images' ) as $index => $image) {
                    if ($image->isValid()) {
                        $imageName = $newImageReference . '-' . ( $index + 1 ) . '.' . $image->getClientOriginalExtension();
                        $path = $image->storeAs( $directories['Images'], $imageName, 'ftp' );
                        $imagePaths[] = $path;
                    }
                }
            }



            // if ($request->has('video_file_id')) {
            //     $videoFileId = $request->input('video_file_id');
            //     $ftpHlsPath = self::BASE_DIRECTORY . '/' . self::VIDEO_DIRECTORY . "/{$currentYear}/" . self::HLS_DIRECTORY . "/{$videoFileId}";
            //     $thumbnailPath = self::BASE_DIRECTORY . '/' . self::MINIATURES_DIRECTORY . "/{$currentYear}/{$videoFileId}.jpg";

            //     // نخزن المسارات مباشرة بدون تحقق من وجود الملفات فعليًا
            //     $publication->video_compresse = "{$ftpHlsPath}/playlist.m3u8";
            //     // $publication->miniature = $thumbnailPath;
            //     // $publication->image_default = $thumbnailPath;
            // }



            if ($request->has( 'video_file_id' )) {
                $videoFileId = $request->input( 'video_file_id' );
                $currentYear = date( 'Y' );
                $playlistPath = self::BASE_DIRECTORY . '/' . self::VIDEO_DIRECTORY . "/{$currentYear}/" . self::HLS_DIRECTORY . "/{$videoFileId}/playlist.m3u8";

                \Log::info( "Checking video processing for file ID: {$videoFileId}" );

                // ── Optimization: Check Cache First ──
                $cacheStatus = \Cache::get("video_status_{$videoFileId}");
                $videoResult = \Cache::get("video_result_{$videoFileId}");

                if ($cacheStatus === 'error') {
                    $errorMsg = \Cache::get("video_error_{$videoFileId}", 'Video processing failed');
                    return response()->json([
                        'success' => false,
                        'message' => "Erreur de traitement: {$errorMsg}",
                        'video_status' => 'error'
                    ], 500);
                }

                if ($cacheStatus === 'completed' && $videoResult) {
                    $publication->video_compresse = $videoResult['hls_playlist'];
                    $publication->miniature = $videoResult['thumbnail'];
                    $publication->image_default = $videoResult['thumbnail'];
                    \Log::info( "Video processing complete via Cache for: {$videoFileId}" );
                } else {
                    // ── Fallback: Check FTP ──
                    $playlistExists = Storage::disk( 'ftp' )->exists( $playlistPath );
                    
                    if (!$playlistExists) {
                        return response()->json( [
                            'success' => false,
                            'message' => 'Video is still being processed',
                            'video_status' => 'processing',
                            'video_file_id' => $videoFileId
                        ], 202 );
                    }

                    $publication->video_compresse = $playlistPath;
                    \Log::info( "Video processing complete via FTP check for: {$videoFileId}" );
                }
            }




            // Process other image files
            if ($request->hasFile( 'image_default' )) {
                $image = $request->file( 'image_default' );
                if ($image->isValid()) {
                    $imageName = $newImageReference . '-main.' . $image->getClientOriginalExtension();
                    $path = $image->storeAs( $directories['Image'], $imageName, 'ftp' );
                    $publication->image_default = $path;

                    // Create and store square version
                    $squareImageName = $newImageReference . '-square.' . $image->getClientOriginalExtension();
                    $squarePath = $directories['ImageSquare'] . '/' . $squareImageName;

                    // Create square directory if it doesn't exist
                    if (!Storage::disk( 'ftp' )->exists( $directories['ImageSquare'] )) {
                        Storage::disk( 'ftp' )->makeDirectory( $directories['ImageSquare'] );
                    }

                    // Create temporary local file for cropping
                    $tempLocalPath = storage_path( 'app/temp/' . $squareImageName );
                    $tempDir = dirname( $tempLocalPath );

                    // Ensure temp directory exists
                    if (!file_exists( $tempDir )) {
                        mkdir( $tempDir, 0755, true );
                    }

                    // Store original temporarily to crop it
                    $tempPath = $image->getRealPath();

                    // Crop to square and save to local temp file
                    if ($this->cropImageToSquare( $tempPath, $tempLocalPath )) {
                        // Upload the cropped image to FTP
                        $ftpContent = file_get_contents( $tempLocalPath );
                        if (Storage::disk( 'ftp' )->put( $squarePath, $ftpContent )) {
                            $publication->image_square = $squarePath;

                        }

                        // Clean up temp file
                        if (file_exists( $tempLocalPath )) {
                            unlink( $tempLocalPath );
                        }
                    }
                }
            }

            if ($request->hasFile( 'miniature' )) {
                $image = $request->file( 'miniature' );
                if ($image->isValid()) {
                    $imageName = $newImageReference . '-main.' . $image->getClientOriginalExtension();
                    $path = $image->storeAs( $directories['miniature'], $imageName, 'ftp' );
                    $pathImage = $image->storeAs( $directories['Image'], $imageName, 'ftp' );

                    $publication->miniature = $path;
                    $publication->image_default = $pathImage;

                    // Create and store square version
                    $squareImageName = $newImageReference . '-square.' . $image->getClientOriginalExtension();
                    $squarePath = $directories['ImageSquare'] . '/' . $squareImageName;

                    // Create square directory if it doesn't exist
                    if (!Storage::disk( 'ftp' )->exists( $directories['ImageSquare'] )) {
                        Storage::disk( 'ftp' )->makeDirectory( $directories['ImageSquare'] );
                    }

                    // Create temporary local file for cropping
                    $tempLocalPath = storage_path( 'app/temp/' . $squareImageName );
                    $tempDir = dirname( $tempLocalPath );

                    // Ensure temp directory exists
                    if (!file_exists( $tempDir )) {
                        mkdir( $tempDir, 0755, true );
                    }

                    // Store original temporarily to crop it
                    $tempPath = $image->getRealPath();

                    // Crop to square and save to local temp file
                    if ($this->cropImageToSquare( $tempPath, $tempLocalPath )) {
                        // Upload the cropped image to FTP
                        $ftpContent = file_get_contents( $tempLocalPath );
                        if (Storage::disk( 'ftp' )->put( $squarePath, $ftpContent )) {
                            $publication->image_square = $squarePath;

                        }

                        // Clean up temp file
                        if (file_exists( $tempLocalPath )) {
                            unlink( $tempLocalPath );
                        }
                    }
                }
            }

            // Process tags
            $tagIds = [];
            $tagNames = [];
            $receivedTagIds = [];

            if ($request->has( 'tag_id' ) && !empty( $request->get( 'tag_id' ) )) {
                $receivedTagIds = is_array( $request->get( 'tag_id' ) )
                    ? $request->get( 'tag_id' )
                    : explode( ',', $request->get( 'tag_id' ) );

                $validTagIds = array_filter( $receivedTagIds, function ( $id ) {
                    return $id !== "0" && $id !== 0;
                } );

                if (!empty( $validTagIds )) {
                    $existingTags = Tags::whereIn( 'id', $validTagIds )->get();
                    foreach ($existingTags as $tag) {
                        $tagIds[] = $tag->id;
                        $tagNames[] = $tag->name;
                    }
                }

                $hasZeros = in_array( "0", $receivedTagIds ) || in_array( 0, $receivedTagIds );
                if ($hasZeros && $request->has( 'tag_name' )) {
                    $newTagNames = $this->processTagNames( $request->get( 'tag_name' ) );
                    foreach ($newTagNames as $name) {
                        $this->processTagName( $name, $tagIds, $tagNames );
                    }
                }
            }

            // Process additional tag names
            if ($request->has( 'tag_name' ) && !empty( $request->get( 'tag_name' ) ) && !in_array( "0", $receivedTagIds )) {
                $newTagNames = $this->processTagNames( $request->get( 'tag_name' ) );
                foreach ($newTagNames as $name) {
                    $this->processTagName( $name, $tagIds, $tagNames );
                }
            }

            // Handle ville and region
            if ($request->has( 'ville_id' ) && $request->get( 'ville_id' ) !== "0") {
                $publication->ville_id = $request->get( 'ville_id' );
            }
            if ($request->has( 'region_id' ) && $request->get( 'region_id' ) !== "0") {
                $publication->region_id = $request->get( 'region_id' );
            }

            // Generate slug with content type
            $hasVideo = $request->has( 'video_file_id' );
            $hasImages = !empty( $imagePaths ) || $request->hasFile( 'image_default' ) || $request->hasFile( 'miniature' );

            $publication->slug = $this->generateSlugchamps(
                $tagNames,
                $publication->ville_id,
                $publication->region_id,
                $newReference,
                $hasVideo,
                $hasImages
            );

            // Fill other fields
            $publication->user_id = $request->get( 'user_id' );
            $publication->is_marketplace = $request->boolean( 'is_marketplace' );
            $publication->disponibilite = $request->get( 'disponibilite' );
            $publication->disponibilite_date = $request->get( 'disponibilite_date' );
            $publication->etab_id = $request->get( 'etab_id' );
            $publication->prix = $request->get( 'prix' );
            $publication->prixPromo = $request->get( 'prixPromo' );
            $publication->pays_id = $request->get( 'pays_id', 1 );
            $publication->tele = $request->get( 'tele' );
            $publication->tele_whatsapp = $request->get( 'tele_whatsapp' );
            $publication->message = $request->get( 'message' );
            $publication->contact = $request->get( 'contact', '0' );
            $publication->description = $request->get( 'description' );
            $publication->images = !empty( $imagePaths ) ? json_encode( $imagePaths ) : null;



            // Save the publication
            // Only save the publication if video processing is complete OR if there's no video
            if (!$request->has( 'video_file_id' ) || $publication->video_compresse) {
                // Save the publication
                $publication->save();

                // Attach delivery modes if provided
                if ($request->has( 'mode_laivraison_id' ) && !empty( $request->get( 'mode_laivraison_id' ) )) {
                    $modeLaivraisonId = $request->get( 'mode_laivraison_id' );
                    $modeLaivraisonId = is_string( $modeLaivraisonId ) ? explode( ',', $modeLaivraisonId ) : $modeLaivraisonId;
                    $modeLaivraisonId = array_map( 'strval', array_filter( $modeLaivraisonId ) );
                    if (!empty( $modeLaivraisonId )) {
                        $publication->modes_laivraison()->attach( $modeLaivraisonId );
                    }
                }

                // Attach tags
                if (!empty( $tagIds )) {
                    $publication->tags()->attach( array_unique( $tagIds ) );
                }

                // Load relationships
                $publication->load( ['region', 'ville', 'user', 'etablissement', 'tags', 'modes_laivraison'] );

                // Return success response
                return response()->json( [
                    'success' => true,
                    'publication' => $publication,
                    'video_compresse' => $publication->video_compresse ?? null,
                    'processing_details' => [
                        'video_status' => isset( $publication->video_compresse ) ? 'processed' : 'not_processed'
                    ]
                ] );
            } else {
                // Video is still processing, don't save publication yet
                return response()->json( [
                    'success' => false,
                    'message' => 'Video is still being processed',
                    'video_status' => 'processing',
                    'video_file_id' => $request->input( 'video_file_id' ),
                    'debug_info' => [
                        'playlist_path' => $playlistPath ?? null,
                        'timestamp' => now()->toISOString()
                    ]
                ], 202 );
            }

        } catch (\Exception $e) {
            Log::error( "Publication creation error: " . $e->getMessage() );
            return response()->json( [
                'success' => false,
                'message' => 'Failed to create publication: ' . $e->getMessage(),
            ], 500 );
        }
    }














    // public function store(Request $request) {
    //     try {
    //         $currentYear = date('Y');
    //         $directories = [
    //             'hls' => self::BASE_DIRECTORY . '/' . self::VIDEO_DIRECTORY . "/{$currentYear}/" . self::HLS_DIRECTORY,
    //             'miniature' => self::BASE_DIRECTORY . '/' . self::MINIATURES_DIRECTORY . "/{$currentYear}",
    //             'Images' => self::BASE_DIRECTORY . '/' . self::IMAGES_DIRECTORY . "/{$currentYear}",
    //             'Image' => self::BASE_DIRECTORY . '/' . self::IMAGE_DIRECTORY . "/{$currentYear}",
    //         ];

    //         // Create directories if they do not exist
    //         foreach ($directories as $directory) {
    //             if (!Storage::disk('ftp')->exists($directory)) {
    //                 Storage::disk('ftp')->makeDirectory($directory);
    //             }
    //         }

    //         // Generate a new reference
    //         $maxReference = Publication::max('reference');
    //         $newReference = $maxReference
    //             ? str_pad((int)$maxReference + 1, 5, '0', STR_PAD_LEFT)
    //             : "00001";

    //         // Add current date with time to the reference
    //         $dateTime = date('YmdHis'); // Format: YYYYMMDDHHMMSS (no separators)

    //         // Add unique elements to ensure 100% uniqueness
    //         $randomString = substr(md5(uniqid(mt_rand(), true)), 0, 8); // 8-character random string
    //         $microtime = sprintf('%06d', (microtime(true) * 1000000) % 1000000); // Microseconds

    //         // Combine all elements to create a truly unique reference without underscores
    //         $newImageReference =  $newReference . $dateTime . $microtime . $randomString;

    //         // Create a new publication instance
    //         $publication = new Publication();
    //         $publication->reference = $newReference;
    //         $publication->status = 0;

    //         // Process images
    //         $imagePaths = [];
    //         if ($request->hasFile('images')) {
    //             foreach ($request->file('images') as $index => $image) {
    //                 if ($image->isValid()) {
    //                     $imageName = $newImageReference . '-' . ($index + 1) . '.' . $image->getClientOriginalExtension();
    //                     $path = $image->storeAs($directories['Images'], $imageName, 'ftp');
    //                     $imagePaths[] = $path;
    //                 }
    //             }
    //         }



    //         if ($request->has('video_file_id')) {
    //             $videoFileId = $request->input('video_file_id');
    //             $ftpHlsPath = self::BASE_DIRECTORY . '/' . self::VIDEO_DIRECTORY . "/{$currentYear}/" . self::HLS_DIRECTORY . "/{$videoFileId}";
    //             $thumbnailPath = self::BASE_DIRECTORY . '/' . self::MINIATURES_DIRECTORY . "/{$currentYear}/{$videoFileId}.jpg";

    //             // نخزن المسارات مباشرة بدون تحقق من وجود الملفات فعليًا
    //             $publication->video_compresse = "{$ftpHlsPath}/playlist.m3u8";
    //             // $publication->miniature = $thumbnailPath;
    //             // $publication->image_default = $thumbnailPath;
    //         }


    //         // Process other image files
    //         if ($request->hasFile('image_default')) {
    //             $image = $request->file('image_default');
    //             if ($image->isValid()) {
    //                 $imageName = $newImageReference . '-main.' . $image->getClientOriginalExtension();
    //                 $path = $image->storeAs($directories['Image'], $imageName, 'ftp');
    //                 $publication->image_default = $path;
    //             }
    //         }

    //         if ($request->hasFile('miniature')) {
    //             $image = $request->file('miniature');
    //             if ($image->isValid()) {
    //                 $imageName = $newImageReference . '-main.' . $image->getClientOriginalExtension();
    //                 $path = $image->storeAs($directories['miniature'], $imageName, 'ftp');
    //                 $pathImage = $image->storeAs($directories['Image'], $imageName, 'ftp');

    //                 $publication->miniature = $path;
    //                 $publication->image_default = $pathImage;
    //             }
    //         }

    //         // Process tags
    //         $tagIds = [];
    //         $tagNames = [];
    //         $receivedTagIds = [];

    //         if ($request->has('tag_id') && !empty($request->get('tag_id'))) {
    //             $receivedTagIds = is_array($request->get('tag_id'))
    //                 ? $request->get('tag_id')
    //                 : explode(',', $request->get('tag_id'));

    //             $validTagIds = array_filter($receivedTagIds, function ($id) {
    //                 return $id !== "0" && $id !== 0;
    //             });

    //             if (!empty($validTagIds)) {
    //                 $existingTags = Tags::whereIn('id', $validTagIds)->get();
    //                 foreach ($existingTags as $tag) {
    //                     $tag->compteur_publications += 1;
    //                     $tag->save();
    //                     $tagIds[] = $tag->id;
    //                     $tagNames[] = $tag->name;
    //                 }
    //             }

    //             $hasZeros = in_array("0", $receivedTagIds) || in_array(0, $receivedTagIds);
    //             if ($hasZeros && $request->has('tag_name')) {
    //                 $newTagNames = $this->processTagNames($request->get('tag_name'));
    //                 foreach ($newTagNames as $name) {
    //                     $this->processTagName($name, $tagIds, $tagNames);
    //                 }
    //             }
    //         }

    //         // Process additional tag names
    //         if ($request->has('tag_name') && !empty($request->get('tag_name')) && !in_array("0", $receivedTagIds)) {
    //             $newTagNames = $this->processTagNames($request->get('tag_name'));
    //             foreach ($newTagNames as $name) {
    //                 $this->processTagName($name, $tagIds, $tagNames);
    //             }
    //         }

    //         // Handle ville and region
    //         if ($request->has('ville_id') && $request->get('ville_id') !== "0") {
    //             $publication->ville_id = $request->get('ville_id');
    //         }
    //         if ($request->has('region_id') && $request->get('region_id') !== "0") {
    //             $publication->region_id = $request->get('region_id');
    //         }

    //         // Generate slug with content type
    //         $hasVideo = $request->has('video_file_id');
    //         $hasImages = !empty($imagePaths) || $request->hasFile('image_default') || $request->hasFile('miniature');

    //         $publication->slug = $this->generateSlugchamps(
    //             $tagNames, 
    //             $publication->ville_id, 
    //             $publication->region_id, 
    //             $newReference,
    //             $hasVideo,
    //             $hasImages
    //         );

    //         // Fill other fields
    //         $publication->user_id = $request->get('user_id');
    //         $publication->is_marketplace = $request->boolean('is_marketplace');
    //         $publication->disponibilite = $request->get('disponibilite');
    //         $publication->disponibilite_date = $request->get('disponibilite_date');
    //         $publication->etab_id = $request->get('etab_id');
    //         $publication->prix = $request->get('prix');
    //         $publication->prixPromo = $request->get('prixPromo');
    //         $publication->pays_id = $request->get('pays_id', 1);
    //         $publication->tele = $request->get('tele');
    //         $publication->tele_whatsapp = $request->get('tele_whatsapp');
    //         $publication->message = $request->get('message');
    //         $publication->contact = $request->get('contact', '0');
    //         $publication->description = $request->get('description');
    //         $publication->images = !empty($imagePaths) ? json_encode($imagePaths) : null;



    //         // Save the publication
    //         $publication->save();

    //         // Attach delivery modes if provided
    //         if ($request->has('mode_laivraison_id') && !empty($request->get('mode_laivraison_id'))) {
    //             $modeLaivraisonId = $request->get('mode_laivraison_id');
    //             $modeLaivraisonId = is_string($modeLaivraisonId) ? explode(',', $modeLaivraisonId) : $modeLaivraisonId;
    //             $modeLaivraisonId = array_map('strval', array_filter($modeLaivraisonId));
    //             if (!empty($modeLaivraisonId)) {
    //                 $publication->modes_laivraison()->attach($modeLaivraisonId);
    //                 $publication->mode_laivraison_id = $modeLaivraisonId;
    //             }
    //         }

    //         // Attach tags
    //         if (!empty($tagIds)) {
    //             $publication->tags()->attach(array_unique($tagIds));
    //         }

    //         // Load relationships
    //         $publication->load(['region', 'ville', 'user', 'etablissement', 'tags', 'modes_laivraison']);

    //         // Return response with video processing details
    //         return response()->json([
    //             'success' => true,
    //             'publication' => $publication,
    //             'video_compresse' => $publication->video_compresse ?? null,
    //             'processing_details' => [
    //                 'video_status' => isset($publication->video_compresse) ? 'processed' : 'not_processed'
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         Log::error("Publication creation error: " . $e->getMessage());
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to create publication: ' . $e->getMessage(),
    //         ], 500);
    //     }
    // }














    /**
     * Process individual tag name
     */
    private function processTagName( $name, &$tagIds, &$tagNames )
    {
        $slug = $this->generateSlug( $name );
        $tag = Tags::where( 'name', $name )->first();

        if (!$tag) {
            $existingSlug = Tags::where( 'slug', $slug )->first();
            if ($existingSlug) {
                $slug = $this->generateUniqueSlug( $slug );
            }

            // Create new tag
            $tag = new Tags();
            $tag->name = $name;
            $tag->slug = $slug;
            $tag->save();
        }

        if (!in_array( $tag->id, $tagIds )) {
            $tagIds[] = $tag->id;
            $tagNames[] = $tag->name;
        }
    }

    /**
     * Process tag names array or string
     */
    private function processTagNames( $tagNames ): array
    {
        if (empty( $tagNames )) {
            return [];
        }
        if (is_array( $tagNames )) {
            return array_unique( array_filter( $tagNames ) );
        }
        $tags = explode( ',', $tagNames );
        return array_unique( array_map( 'trim', array_filter( $tags ) ) );
    }

    /**
     * Generate a slug from a string
     */
    private function generateSlug( $name ): string
    {
        return Str::slug( $name, '-' );
    }

    /**
     * Generate a unique slug for tags
     */
    private function generateUniqueSlug( $slug ): string
    {
        $originalSlug = $slug;
        $count = 1;

        while (Tags::where( 'slug', $slug )->exists()) {
            $slug = Str::slug( $originalSlug . '-' . $count );
            $count++;
        }

        return $slug;
    }

    /**
     * Generate a slug from the specified fields
     */
    private function generateSlugchamps( $tagNames, $villeId, $regionId, $reference, $hasVideo = false, $hasImages = false ): string
    {
        $slugParts = [];

        // Add content type prefix
        if ($hasVideo) {
            $slugParts[] = 'video';
        } elseif ($hasImages) {
            $slugParts[] = 'images';
        }

        // Process tags
        if (!empty( $tagNames )) {
            $tagSlug = implode( '-', array_map( function ( $tag ) {
                return Str::slug( $tag, '-' );
            }, $tagNames ) );
            $slugParts[] = $tagSlug;
        }

        // Add ville slug if exists
        if ($villeId) {
            $ville = Ville::find( $villeId );
            if ($ville) {
                $slugParts[] = Str::slug( $ville->name, '-' );
            }
        }

        // Add region slug if exists
        if ($regionId) {
            $region = Region::find( $regionId );
            if ($region) {
                $slugParts[] = Str::slug( $region->name, '-' );
            }
        }

        // Add reference
        $slugParts[] = $reference;

        // Combine all parts and ensure clean slug
        return Str::slug( implode( '-', array_filter( $slugParts ) ), '-' );
    }

    /**
     * Crop image to square format
     */
    private function cropImageToSquare( $imagePath, $outputPath, $size = 300 )
    {
        try {
            // Get image info
            $imageInfo = getimagesize( $imagePath );
            if (!$imageInfo) {
                return false;
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $type = $imageInfo[2];

            // Create image resource based on type
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg( $imagePath );
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng( $imagePath );
                    break;
                case IMAGETYPE_GIF:
                    $source = imagecreatefromgif( $imagePath );
                    break;
                case IMAGETYPE_WEBP:
                    $source = imagecreatefromwebp( $imagePath );
                    break;
                default:
                    return false;
            }

            if (!$source) {
                return false;
            }

            // Calculate crop dimensions (square from center)
            $cropSize = min( $width, $height );
            $cropX = ( $width - $cropSize ) / 2;
            $cropY = ( $height - $cropSize ) / 2;

            // Create square canvas
            $square = imagecreatetruecolor( $size, $size );

            // Preserve transparency for PNG and WebP
            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP) {
                imagealphablending( $square, false );
                imagesavealpha( $square, true );
                $transparent = imagecolorallocatealpha( $square, 255, 255, 255, 127 );
                imagefill( $square, 0, 0, $transparent );
            }

            // Resize and crop
            imagecopyresampled( $square, $source, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize );

            // Save the cropped image
            $success = false;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $success = imagejpeg( $square, $outputPath, 90 );
                    break;
                case IMAGETYPE_PNG:
                    $success = imagepng( $square, $outputPath, 9 );
                    break;
                case IMAGETYPE_GIF:
                    $success = imagegif( $square, $outputPath );
                    break;
                case IMAGETYPE_WEBP:
                    $success = imagewebp( $square, $outputPath, 90 );
                    break;
            }

            // Clean up
            imagedestroy( $source );
            imagedestroy( $square );

            return $success;
        } catch (\Exception $e) {
            Log::error( "Image cropping error: " . $e->getMessage() );
            return false;
        }
    }

    /**
     * Compress and crop image to square with quality
     */
    private function compressAndCropImageToSquare( $imagePath, $outputPath, $size = 300, $quality = 30 )
    {
        try {
            $imageInfo = getimagesize( $imagePath );
            if (!$imageInfo) {
                return false;
            }
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $type = $imageInfo[2];

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg( $imagePath );
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng( $imagePath );
                    break;
                case IMAGETYPE_GIF:
                    $source = imagecreatefromgif( $imagePath );
                    break;
                case IMAGETYPE_WEBP:
                    $source = imagecreatefromwebp( $imagePath );
                    break;
                default:
                    return false;
            }
            if (!$source) {
                return false;
            }
            $cropSize = min( $width, $height );
            $cropX = ( $width - $cropSize ) / 2;
            $cropY = ( $height - $cropSize ) / 2;
            $square = imagecreatetruecolor( $size, $size );
            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP) {
                imagealphablending( $square, false );
                imagesavealpha( $square, true );
                $transparent = imagecolorallocatealpha( $square, 255, 255, 255, 127 );
                imagefill( $square, 0, 0, $transparent );
            }
            imagecopyresampled( $square, $source, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize );
            $success = false;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $success = imagejpeg( $square, $outputPath, $quality ); // compress
                    break;
                case IMAGETYPE_PNG:
                    $success = imagepng( $square, $outputPath, 9 ); // max compression
                    break;
                case IMAGETYPE_GIF:
                    $success = imagegif( $square, $outputPath );
                    break;
                case IMAGETYPE_WEBP:
                    $success = imagewebp( $square, $outputPath, $quality ); // compress
                    break;
            }
            imagedestroy( $source );
            imagedestroy( $square );
            return $success;
        } catch (\Exception $e) {
            \Log::error( "Image compress/crop error: " . $e->getMessage() );
            return false;
        }
    }

    private function compressAndCropImageToSquareToFtp( $imagePath, $ftpPath, $size = 300, $quality = 30 )
    {
        try {
            $imageInfo = getimagesize( $imagePath );
            if (!$imageInfo) {
                return false;
            }
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $type = $imageInfo[2];

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg( $imagePath );
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng( $imagePath );
                    break;
                case IMAGETYPE_GIF:
                    $source = imagecreatefromgif( $imagePath );
                    break;
                case IMAGETYPE_WEBP:
                    $source = imagecreatefromwebp( $imagePath );
                    break;
                default:
                    return false;
            }
            if (!$source) {
                return false;
            }
            $cropSize = min( $width, $height );
            $cropX = ( $width - $cropSize ) / 2;
            $cropY = ( $height - $cropSize ) / 2;
            $square = imagecreatetruecolor( $size, $size );
            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP) {
                imagealphablending( $square, false );
                imagesavealpha( $square, true );
                $transparent = imagecolorallocatealpha( $square, 255, 255, 255, 127 );
                imagefill( $square, 0, 0, $transparent );
            }
            imagecopyresampled( $square, $source, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize );

            // Write to memory stream
            $stream = fopen( 'php://temp', 'r+' );
            $success = false;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $success = imagejpeg( $square, $stream, $quality );
                    break;
                case IMAGETYPE_PNG:
                    $success = imagepng( $square, $stream, 9 );
                    break;
                case IMAGETYPE_GIF:
                    $success = imagegif( $square, $stream );
                    break;
                case IMAGETYPE_WEBP:
                    $success = imagewebp( $square, $stream, $quality );
                    break;
            }
            rewind( $stream );

            if ($success) {
                // Save directly to FTP
                \Storage::disk( 'ftp' )->put( $ftpPath, $stream );
            }
            fclose( $stream );
            imagedestroy( $square );
            imagedestroy( $source );
            return $success;
        } catch (\Exception $e) {
            \Log::error( "Image compress/crop error: " . $e->getMessage() );
            return false;
        }
    }

    /**
     * Process all marketplace publications to create square images
     */
    public function processAllSquareImages()
    {
        try {
            $baseUrl = 'https://media.flink.ma';
            $currentYear = date( 'Y' );
            $imageSquareDirectory = self::BASE_DIRECTORY . '/image/' . $currentYear . '/image-square';
            if (!\Storage::disk( 'ftp' )->exists( $imageSquareDirectory )) {
                \Storage::disk( 'ftp' )->makeDirectory( $imageSquareDirectory );
            }

            // Specific image paths to process
            $specificImagePaths = [
                'publication/image/2025/square/06542202507291413192129703c45913b-square.webp',
                'publication/image/2025/square/06267-square.webp',
                'publication/image/2025/square/06201-square.webp',
                'publication/image/2025/square/06199-square.webp',
                'publication/image/2025/square/06167-square.webp',
                'publication/image/2025/square/06161-square.webp'
            ];

            $processedCount = 0;
            $errorCount = 0;

            foreach ($specificImagePaths as $imagePath) {
                try {
                    $imageUrl = $baseUrl . '/' . $imagePath;
                    $extension = strtolower( pathinfo( $imagePath, PATHINFO_EXTENSION ) );
                    if (empty( $extension )) {
                        $extension = 'jpg';
                    }

                    // Extract reference from path
                    $pathParts = explode( '/', $imagePath );
                    $filename = end( $pathParts );
                    $reference = str_replace( '-square.' . $extension, '', $filename );

                    $newImageSquareName = $reference . '-image-square.' . $extension;
                    $newImageSquarePath = $imageSquareDirectory . '/' . $newImageSquareName;

                    // Download the image directly to memory
                    $imageContent = $this->downloadImageWithCurl( $imageUrl );
                    if ($imageContent === false) {
                        $imageContent = $this->downloadImageWithFileGetContents( $imageUrl );
                    }
                    if ($imageContent === false) {
                        \Log::error( 'Failed to download image', [
                            'path' => $imagePath,
                            'url' => $imageUrl
                        ] );
                        $errorCount++;
                        continue;
                    }

                    // Remove the size check entirely and just try to create the image resource
                    $imageResource = imagecreatefromstring( $imageContent );
                    if (!$imageResource) {
                        \Log::error( 'Failed to create image resource - invalid image', [
                            'path' => $imagePath,
                            'url' => $imageUrl,
                            'size' => strlen( $imageContent )
                        ] );
                        $errorCount++;
                        continue;
                    }

                    $width = imagesx( $imageResource );
                    $height = imagesy( $imageResource );
                    $cropSize = min( $width, $height );
                    $cropX = ( $width - $cropSize ) / 2;
                    $cropY = ( $height - $cropSize ) / 2;
                    $square = imagecreatetruecolor( 300, 300 );
                    imagealphablending( $square, false );
                    imagesavealpha( $square, true );
                    $transparent = imagecolorallocatealpha( $square, 255, 255, 255, 127 );
                    imagefill( $square, 0, 0, $transparent );
                    imagecopyresampled( $square, $imageResource, 0, 0, $cropX, $cropY, 300, 300, $cropSize, $cropSize );

                    // Write directly to memory stream (no temp file)
                    $stream = fopen( 'php://temp', 'r+' );
                    $success = false;
                    switch ($extension) {
                        case 'jpg':
                        case 'jpeg':
                            $success = imagejpeg( $square, $stream, 30 );
                            break;
                        case 'png':
                            $success = imagepng( $square, $stream, 9 );
                            break;
                        case 'gif':
                            $success = imagegif( $square, $stream );
                            break;
                        case 'webp':
                            $success = imagewebp( $square, $stream, 30 );
                            break;
                        default:
                            $success = imagejpeg( $square, $stream, 30 );
                            break;
                    }
                    rewind( $stream );

                    if ($success) {
                        if (\Storage::disk( 'ftp' )->put( $newImageSquarePath, $stream )) {
                            // Find and update the publication in database
                            $publication = Publication::where( 'image_square', $imagePath )->first();
                            if ($publication) {
                                $publication->image_square = $newImageSquarePath;
                                $publication->save();

                                \Log::info( 'Updated image_square path', [
                                    'publication_id' => $publication->id,
                                    'old_path' => $imagePath,
                                    'new_path' => $newImageSquarePath
                                ] );
                            }

                            $processedCount++;
                        } else {
                            \Log::error( 'Failed to upload to FTP', [
                                'path' => $imagePath,
                                'ftp_path' => $newImageSquarePath
                            ] );
                            $errorCount++;
                        }
                    } else {
                        \Log::error( 'Failed to compress/crop image', [
                            'path' => $imagePath,
                            'extension' => $extension
                        ] );
                        $errorCount++;
                    }

                    // Clean up memory
                    fclose( $stream );
                    imagedestroy( $square );
                    imagedestroy( $imageResource );

                } catch (\Exception $e) {
                    \Log::error( 'Error processing image', [
                        'path' => $imagePath,
                        'error' => $e->getMessage(),
                    ] );
                    $errorCount++;
                    continue;
                }
            }

            return response()->json( [
                'success' => true,
                'message' => "image-square optimization completed for specific images. Database fields updated.",
                'processed_count' => $processedCount,
                'error_count' => $errorCount,
                'total_images_processed' => count( $specificImagePaths )
            ] );
        } catch (\Exception $e) {
            return response()->json( [
                'success' => false,
                'message' => 'Failed to process image-square: ' . $e->getMessage(),
            ], 500 );
        }
    }

    /**
     * Download image using cURL (more reliable)
     */
    private function downloadImageWithCurl( $url )
    {
        if (!function_exists( 'curl_init' )) {
            return false;
        }

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, [
            'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ] );

        $content = curl_exec( $ch );
        $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );

        if ($content === false || $httpCode !== 200 || !empty( $error )) {
            return false;
        }

        return $content;
    }

    /**
     * Download image using file_get_contents (fallback)
     */
    private function downloadImageWithFileGetContents( $url )
    {
        $context = stream_context_create( [
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'header' => [
                    'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache'
                ]
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ] );

        $content = @file_get_contents( $url, false, $context );

        if ($content === false) {
            return false;
        }

        return $content;
    }

    // Keep other required methods for Publication management
    public function GetPublication( $id )
    {
        try {
            $publication = Publication::with( ['region', 'ville', 'user', 'etablissement', 'tags', 'modes_laivraison'] )->findOrFail( $id );
            return response()->json( $publication, 200 );
        } catch (ModelNotFoundException $e) {
            return response()->json( ['error' => 'Publication non trouvée'], 404 );
        } catch (\Exception $e) {
            return response()->json( ['error' => 'Erreur lors de la récupération de la publication'], 500 );
        }
    }

    public function edit( Request $request, $id )
    {
        try {
            $publication = Publication::with( "user", "etablissement" )->findOrFail( $id );


            // Handle ville and region logic


            $tagIds = [];
            $tagNames = [];
            $receivedTagIds = [];

            // Process tags
            // Handle ville and region logic
            // Handle ville creation/update logic
            if ($request->get( 'ville_id' ) === null || $request->get( 'ville_id' ) === "0" && $request->get( 'ville_name' ) === null) {
                // Do not create a region or establish a relationship
                // This could be changed to a more explicit handling if needed
            } elseif ($request->get( 'ville_id' ) === null || $request->get( 'ville_id' ) === "0") {
                $villeName = $request->get( 'ville_name' );

                // Validations for ville name
                if (empty( $villeName )) {
                    return response()->json( [
                        'success' => false,
                        'message' => 'City name cannot be empty',
                        'details' => $request->all()
                    ], 400 );
                }

                // Ensure villeName is a non-empty string with sufficient length
                $villeName = trim( $villeName );
                if (strlen( $villeName ) < 2) {
                    return response()->json( [
                        'success' => false,
                        'message' => 'City name must be at least 2 characters long',
                        'details' => ['ville_name' => $villeName]
                    ], 400 );
                }

                // Validate country ID
                $paysId = $request->get( 'pays_id', 1 );
                if (!is_numeric( $paysId ) || $paysId <= 0) {
                    return response()->json( [
                        'success' => false,
                        'message' => 'Invalid country ID',
                        'details' => ['pays_id' => $paysId]
                    ], 400 );
                }

                $villeSlug = $this->generateSlug( $villeName );

                try {
                    // Use a more robust method to handle city creation
                    $ville = Ville::firstOrNew(
                        ['slug' => $villeSlug, 'pays_id' => $paysId],
                        [
                            'name' => $villeName,
                            'compteur_publications' => 1,
                        ]
                    );

                    // Save the ville if it's new, otherwise increment counter
                    if (!$ville->exists) {
                        $ville->save();
                    } else {
                        $ville->increment( 'compteur_publications' );
                    }

                    // Ensure ville_id is set for the publication
                    $publication->ville_id = $ville->id;
                } catch (\Exception $e) {
                    \Log::error( 'City creation error', [
                        'message' => $e->getMessage(),
                        'ville_name' => $villeName,
                        'pays_id' => $paysId
                    ] );

                    return response()->json( [
                        'success' => false,
                        'message' => 'Error creating city',
                        'details' => [
                            'error' => $e->getMessage(),
                            'ville_name' => $villeName,
                            'pays_id' => $paysId
                        ]
                    ], 500 );
                }
            } else {
                // Use existing ville and increment counter
                $villeId = $request->get( 'ville_id' );

                // Additional validation for existing ville_id
                if (!is_numeric( $villeId ) || $villeId <= 0) {
                    return response()->json( [
                        'success' => false,
                        'message' => 'Invalid city ID',
                        'details' => ['ville_id' => $villeId]
                    ], 400 );
                }

                $ville = Ville::find( $villeId );

                if (!$ville) {
                    return response()->json( [
                        'success' => false,
                        'message' => 'City not found',
                        'details' => ['ville_id' => $villeId]
                    ], 404 );
                }

                $ville->increment( 'compteur_publications' );
                $publication->ville_id = $ville->id;
            }
            // Process tags during update
            $tagIds = [];
            $tagNames = [];
            $receivedTagIds = [];

            // Récupérer les tag IDs existants
            if ($request->has( 'tag_id' ) && !empty( $request->get( 'tag_id' ) )) {
                $receivedTagIds = is_array( $request->get( 'tag_id' ) )
                    ? $request->get( 'tag_id' )
                    : explode( ',', $request->get( 'tag_id' ) );

                $validTagIds = array_filter( $receivedTagIds, function ( $id ) {
                    return $id !== "0" && $id !== 0;
                } );

                // Gérer les tags existants
                if (!empty( $validTagIds )) {
                    $existingTags = Tags::whereIn( 'id', $validTagIds )->get();
                    foreach ($existingTags as $tag) {
                        $tagIds[] = $tag->id;
                        $tagNames[] = $tag->name;
                    }
                }

                // Gérer les nouveaux tags (avec ID 0)
                $hasZeros = in_array( "0", $receivedTagIds ) || in_array( 0, $receivedTagIds );
                if ($hasZeros && $request->has( 'tag_name' )) {
                    $newTagNames = $this->processTagNames( $request->get( 'tag_name' ) );
                    foreach ($newTagNames as $name) {
                        $slug = $this->generateSlug( $name );
                        $tag = Tags::where( 'name', $name )->first();

                        if (!$tag) {
                            $existingSlug = Tags::where( 'slug', $slug )->first();
                            if ($existingSlug) {
                                $slug = $this->generateUniqueSlug( $slug );
                            }

                            // Créer un nouveau tag
                            $tag = new Tags();
                            $tag->name = $name;
                            $tag->slug = $slug;
                            $tag->save();
                        }

                        if (!in_array( $tag->id, $tagIds )) {
                            $tagIds[] = $tag->id;
                            $tagNames[] = $tag->name;
                        }
                    }
                }
            }

            // Process additional tag names if provided
            if ($request->has( 'tag_name' ) && !empty( $request->get( 'tag_name' ) ) && !in_array( "0", $receivedTagIds )) {
                $newTagNames = $this->processTagNames( $request->get( 'tag_name' ) );
                foreach ($newTagNames as $name) {
                    $slug = $this->generateSlug( $name );
                    $tag = Tags::where( 'name', $name )->first();

                    if (!$tag) {
                        $existingSlug = Tags::where( 'slug', $slug )->first();
                        if ($existingSlug) {
                            $slug = $this->generateUniqueSlug( $slug );
                        }

                        // Create new tag
                        $tag = new Tags();
                        $tag->name = $name;
                        $tag->slug = $slug;
                        $tag->save();
                    }

                    if (!in_array( $tag->id, $tagIds )) {
                        $tagIds[] = $tag->id;
                        $tagNames[] = $tag->name;
                    }
                }
            }

            // Synchroniser les tags après avoir traité tous les tags (existants et nouveaux)
            if (!empty( $tagIds )) {
                $publication->tags()->sync( array_unique( $tagIds ) );
            }

            // Handle region creation/update
            if ($request->get( 'region_id' ) === null && $request->get( 'region_name' ) === null) {
                // Ne pas créer de région ni établir de relation
            } elseif ($request->get( 'region_id' ) === null || $request->get( 'region_id' ) === "0") {
                // Créer une nouvelle région
                $regionName = $request->get( 'region_name' );
                $regionSlug = $this->generateSlug( $regionName );
                $region = Region::firstOrCreate(
                    ['slug' => $regionSlug],
                    [
                        'name' => $regionName,
                        'ville_id' => $ville->id,
                        'compteur_publications' => 1, // Initialize counter for new region
                    ]
                );

                // If the region already existed, increment its counter
                if (!$region->wasRecentlyCreated) {
                    $region->increment( 'compteur_publications' );
                }

                $publication->region_id = $region->id;
            } elseif ($request->has( 'region_id' ) && $request->get( 'region_id' ) !== "0") {
                // Utiliser la région existante et increment counter
                $publication->region_id = $request->get( 'region_id' );
                $region = Region::find( $request->get( 'region_id' ) );
                if ($region) {
                    $region->increment( 'compteur_publications' );
                }
            }
            // Rest of your existing code
            $publication->is_marketplace = $request->boolean( 'is_marketplace' );
            $publication->disponibilite = $request->get( 'disponibilite' );
            $publication->disponibilite_date = $request->get( 'disponibilite_date' );
            $publication->prix = $request->get( 'prix' );
            $publication->prixPromo = $request->get( 'prixPromo' );
            $publication->pays_id = $request->get( 'pays_id', 1 );
            $publication->tele = $request->get( 'tele' );
            $publication->tele_whatsapp = $request->get( 'tele_whatsapp' );
            $publication->message = $request->get( 'message' );
            $publication->description = $request->get( 'description' );
            $publication->status = 0;


            if ($request->get( 'tele_whatsapp' ) || $request->get( 'tele' )) {
                if ($request->get( 'tele_whatsapp' )) {
                    $telePhone = Telephone::firstOrCreate( ['number' => $request->get( 'tele_whatsapp' )] );
                } else {
                    $telePhone = Telephone::firstOrCreate( ['number' => $request->get( 'tele' )] );
                }
                $user = $publication->user;
                if ($publication->etab_id) {
                    $etab = $publication->etablissement;
                    $etab->telephones()->syncWithoutDetaching( [$telePhone->id] ); // or attach()
                } elseif ($user) {
                    $user->telephones()->syncWithoutDetaching( [$telePhone->id] ); // or attach()
                }
            }

            // $publication->contact = $request->get('contact', '0');

            // Handle delivery modes
            $modeLaivraisonId = $request->get( 'mode_laivraison_id' );
            if ($modeLaivraisonId === null || empty( $modeLaivraisonId )) {
                $publication->modes_laivraison()->sync( [] );
            } else {
                $modeLaivraisonId = is_string( $modeLaivraisonId ) ? explode( ',', $modeLaivraisonId ) : $modeLaivraisonId;
                $modeLaivraisonIds = array_map( 'strval', array_filter( $modeLaivraisonId ) );
                if (!empty( $modeLaivraisonIds )) {
                    $publication->modes_laivraison()->sync( $modeLaivraisonIds );
                }
            }

            $publication->save();
            $publication->load( ['region', 'ville', 'user', 'etablissement', 'tags', 'modes_laivraison'] );

            return response()->json( [
                'success' => true,
                'publication' => $publication,
                'modeLaivraisonId' => $modeLaivraisonId,
            ] );

        } catch (\Exception $e) {
            Log::error( "Publication update error: " . $e->getMessage() );
            return response()->json( [
                'success' => false,
                'message' => 'Failed to update publication: ' . $e->getMessage(),
                "tags" => $request->get( 'tag_id' ),
                "ville" => $request->get( 'ville_id' ),
                "region" => $request->get( 'region_id' ),
                "ville_name" => $request->get( 'ville_name' ),
            ], 500 );
        }
    }


    // Handle phone number unlocking
    public function UnlockPhoneNumber( Request $request )
    {
        try {
            // Validate request data
            $validatedData = $request->validate( [
                'annonce_id' => 'required|integer',
                'userEtab_id' => 'required|integer',
                'type' => 'required|string',
                'amount' => 'required|numeric',
            ] );
            $publication = Publication::findOrFail( $validatedData['annonce_id'] );

            if ($validatedData['type'] === 'user') {
                // Handle user type unlocking
                $user = User::findOrFail( $validatedData['userEtab_id'] );
                $user->consommation_solde -= $validatedData['amount'];
                $publication->unlocked_number = 1;
                $user->save();
                $publication->save();
            } else {
                // Handle establishment type unlocking
                $etablissement = Etablissement::findOrFail( $validatedData['userEtab_id'] );
                $etablissement->consommation_solde -= $validatedData['amount'];
                $publication->unlocked_number = 1;
                $etablissement->save();
                $publication->save();

            }

            // Return success response
            return response()->json( [
                'success' => true,
                'message' => 'Phone number unlocked successfully',
                'publication' => $publication,
            ], 200 );
        } catch (ModelNotFoundException $e) {
            return response()->json( [
                'success' => false,
                'message' => 'Publication not found',
                'error' => $e->getMessage(),
            ], 404 );
        } catch (\Exception $e) {
            return response()->json( [
                'success' => false,
                'message' => 'Failed to unlock phone number',
                'error' => $e->getMessage(),
            ], 500 );
        }
    }

}