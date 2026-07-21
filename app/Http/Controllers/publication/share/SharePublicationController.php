<?php

namespace App\Http\Controllers\Publication\Share;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Etablissement;
use App\Models\Conversation;
use App\Models\NewMessage;
use App\Models\PublicationsReport;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;
use App\Http\Controllers\statistiques\StatistiquesController;

class SharePublicationController extends Controller
{




    public function downloadHlsAsMp4( Request $request )
    {
        $request->validate( [
            'hls' => 'required|url',
            'name' => 'nullable|string',
        ] );

        $hls = $request->query( 'hls' );
        $name = $request->query( 'name', 'flink-video' );
        $safeName = preg_replace( '/[^a-zA-Z0-9-_\.]/', '_', $name ) ?: 'flink-video';

        $ua = 'FlinkDownloader/1.0 (+https://flink.ma)';
        $ref = 'https://flink.ma/';

        // Temp file paths
        $tmpDir = storage_path( 'app/tmp' );
        if (!is_dir( $tmpDir )) {
            @mkdir( $tmpDir, 0775, true );
        }
        $tmpFile = $tmpDir . '/' . Str::uuid() . '.mp4';

        try {
            // Process HLS stream directly with FFmpeg (no need to download first)
            $this->processHlsWithWatermark( $hls, $tmpFile, $ua, $ref );

            if (!file_exists( $tmpFile ) || filesize( $tmpFile ) === 0) {
                throw new \Exception( 'Failed to process video with watermark' );
            }

            // Serve the watermarked video
            return response()->download(
                $tmpFile,
                $safeName . '.mp4',
                [
                    'Content-Type' => 'video/mp4',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Access-Control-Expose-Headers' => 'Content-Length, Content-Type, Content-Disposition',
                ]
            )->deleteFileAfterSend( true );

        } catch (\Exception $e) {
            Log::error( '[HLS->MP4] Processing failed', ['error' => $e->getMessage()] );

            // Clean up files
            @unlink( $tmpFile );

            return response()->json( ['error' => 'Video processing failed', 'detail' => $e->getMessage()], 500 );
        }
    }

    public function startVideoProcessing( Request $request )
    {
        $request->validate( [
            'hls' => 'required|url',
            'name' => 'nullable|string',
            'profile_slug' => 'nullable|string',
            'skip_watermark' => 'nullable|boolean',
            'watermark_image' => 'nullable|string',
        ] );

        // Accept from body like image API; fallback to query
        $hls = $request->input( 'hls', $request->query( 'hls' ) );
        $name = $request->input( 'name', $request->query( 'name', 'flink-video' ) );
        $profileSlug = $request->input( 'profile_slug', $request->query( 'profile_slug' ) );
        $skipWatermark = (bool) $request->input( 'skip_watermark', $request->query( 'skip_watermark', false ) );
        $watermarkImage = $request->input( 'watermark_image', $request->query( 'watermark_image' ) );

        // Log incoming request for debugging
        Log::info( '[Video Processing] Request received', [
            'hls' => $hls,
            'name' => $name,
            'profile_slug' => $profileSlug,
            'skip_watermark' => $skipWatermark,
            'has_watermark_image' => !empty( $watermarkImage ),
            'watermark_image_length' => $watermarkImage ? strlen( $watermarkImage ) : 0,
            'watermark_image_preview' => $watermarkImage ? substr( $watermarkImage, 0, 100 ) . '...' : 'null'
        ] );

        // If skip_watermark is true, clear watermark_image (owner downloading own post)
        // Otherwise, use watermark_image if provided (same logic as disconnected)
        if ($skipWatermark) {
            Log::info( '[Video Processing] Skipping watermark (owner downloading own post)' );
            $watermarkImage = null;
        } else {
            if ($watermarkImage) {
                Log::info( '[Video Processing] Watermark image will be used', [
                    'watermark_length' => strlen( $watermarkImage )
                ] );
            } else {
                Log::warning( '[Video Processing] No watermark image provided but skip_watermark is false' );
            }
        }

        $safeName = preg_replace( '/[^a-zA-Z0-9-_\.]/', '_', $name ) ?: 'flink-video';

        // removed non-error logs

        // Generate unique job ID
        $jobId = Str::uuid();

        // Store job status in cache
        cache()->put( "video_job_{$jobId}", [
            'status' => 'queued',
            'progress' => 0,
            'message' => 'Video queued for processing...',
            'hls' => $hls,
            'name' => $safeName,
            'profile_slug' => $profileSlug,
            'skip_watermark' => $skipWatermark,
            'watermark_image' => $watermarkImage,
            'created_at' => now()
        ], 3600 ); // 1 hour TTL

        // Use a simple approach - process immediately but with timeout protection
        try {
            // Start processing with timeout
            $this->processVideoWithTimeout( $jobId, $hls, $safeName, $watermarkImage );
        } catch (\Exception $e) {
            cache()->put( "video_job_{$jobId}", [
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Processing failed: ' . $e->getMessage(),
                'hls' => $hls,
                'name' => $safeName,
                'created_at' => now(),
                'error' => $e->getMessage()
            ], 3600 );
        }

        return response()->json( [
            'job_id' => $jobId,
            'status' => 'processing',
            'message' => 'Video processing started'
        ] );
    }

    public function getVideoProcessingStatus( Request $request )
    {
        $jobId = $request->input( 'job_id', $request->query( 'job_id' ) );

        if (!$jobId) {
            return response()->json( ['error' => 'Job ID required'], 400 );
        }

        $status = cache()->get( "video_job_{$jobId}" );

        if (!$status) {
            return response()->json( ['error' => 'Job not found'], 404 );
        }

        return response()->json( $status );
    }

    private function processVideoWithTimeout( $jobId, $hlsUrl, $safeName, $watermarkImage = null )
    {
        $ua = 'FlinkDownloader/1.0 (+https://flink.ma)';
        $ref = 'https://flink.ma/';

        // Temp file paths
        $tmpDir = storage_path( 'app/tmp' );
        if (!is_dir( $tmpDir )) {
            @mkdir( $tmpDir, 0775, true );
        }
        $tmpFile = $tmpDir . '/' . Str::uuid() . '.mp4';
        $overlayFile = null;

        try {
            // Update status
            cache()->put( "video_job_{$jobId}", [
                'status' => 'processing',
                'progress' => 10,
                'message' => 'Processing video...',
                'hls' => $hlsUrl,
                'name' => $safeName,
                'created_at' => now()
            ], 3600 );

            // Always use watermark_image if provided (same logic as disconnected)
            // This ensures connected users get watermark when not owner, just like disconnected users
            Log::info( '[Video Processing] Processing video', [
                'job_id' => $jobId,
                'hls_url' => $hlsUrl,
                'has_watermark_image' => !empty( $watermarkImage ),
                'watermark_image_length' => $watermarkImage ? strlen( $watermarkImage ) : 0
            ] );

            if ($watermarkImage) {
                Log::info( '[Video Processing] Saving watermark data URL to PNG file' );
                $overlayFile = $this->saveDataUrlToPng( $watermarkImage );
                if ($overlayFile) {
                    Log::info( '[Video Processing] Watermark file created', [
                        'overlay_file' => $overlayFile,
                        'file_exists' => file_exists( $overlayFile ),
                        'file_size' => file_exists( $overlayFile ) ? filesize( $overlayFile ) : 0
                    ] );
                } else {
                    Log::warning( '[Video Processing] Failed to save watermark data URL to PNG' );
                }
            } else {
                Log::info( '[Video Processing] No watermark image provided, processing without watermark' );
            }

            // Process with provided overlay (top-right). If no overlay, fallback to text-only
            Log::info( '[Video Processing] Starting FFmpeg processing', [
                'has_overlay' => !empty( $overlayFile ),
                'overlay_file' => $overlayFile
            ] );
            $this->processHlsWithWatermark( $hlsUrl, $tmpFile, $ua, $ref, $overlayFile );

            if (!file_exists( $tmpFile ) || filesize( $tmpFile ) === 0) {
                throw new \Exception( 'Failed to process video with watermark' );
            }

            // Update status to completed
            cache()->put( "video_job_{$jobId}", [
                'status' => 'completed',
                'progress' => 100,
                'message' => 'Video ready for download!',
                // Use relative URL so frontend hits same-origin (Next.js rewrite) and avoids CSP/CORS
                'download_url' => '/api/publications/download-processed-video/' . $jobId,
                'hls' => $hlsUrl,
                'name' => $safeName,
                'watermark_image' => $watermarkImage,
                'created_at' => now(),
                'completed_at' => now(),
                'file_path' => $tmpFile
            ], 3600 );

        } catch (\Exception $e) {
            Log::error( '[HLS->MP4] Processing failed', ['error' => $e->getMessage(), 'job_id' => $jobId] );

            // Update status to failed
            cache()->put( "video_job_{$jobId}", [
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Video processing failed: ' . $e->getMessage(),
                'hls' => $hlsUrl,
                'name' => $safeName,
                'created_at' => now(),
                'error' => $e->getMessage()
            ], 3600 );

            // Clean up files
            @unlink( $tmpFile );
        }
    }

    private function processHlsWithWatermarkSimple( $hlsUrl, $outputFile, $userAgent, $referer )
    {
        $ffmpeg = env( 'FFMPEG_PATH', 'ffmpeg' );

        // Skip logo download for speed - use text only
        $filterComplex = 'drawtext=text=\'flink.ma\':fontcolor=white:fontsize=30:alpha=0.9:x=15:y=h-text_h-15:box=1:boxcolor=black@0.4:boxborderw=3';

        // Optimized ultra-fast processing command
        $cmd = [
            $ffmpeg,
            '-y',
            '-nostdin',
            '-headers',
            "User-Agent: {$userAgent}\r\nReferer: {$referer}\r\n",
            '-protocol_whitelist',
            'file,http,https,tcp,tls,crypto',
            '-allowed_extensions',
            'ALL',
            '-analyzeduration',
            '0',
            '-probesize',
            '16M',
            '-user_agent',
            $userAgent,
            '-i',
            $hlsUrl,
            '-vf',
            $filterComplex,
            '-c:v',
            'libx264',
            '-preset',
            'ultrafast',
            '-crf',
            '32',
            '-c:a',
            'aac',
            '-b:a',
            '48k',
            '-ac',
            '2',
            '-movflags',
            '+faststart',
            '-pix_fmt',
            'yuv420p',
            '-profile:v',
            'baseline',
            '-level',
            '3.0',
            '-tune',
            'fastdecode',
            '-threads',
            '4',
            '-x264opts',
            'no-cabac:ref=1:subme=1:me=dia:analyse=none:trellis=0:no-fast-pskip:8x8dct=0:weightb=0',
            '-t',
            '60', // Limit to 60 seconds max
            $outputFile
        ];

        $process = new Process( $cmd );
        $process->setTimeout( 120 ); // 2 minutes max
        $process->setIdleTimeout( 120 );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception( 'FFmpeg processing failed: ' . $process->getErrorOutput() );
        }
    }

    public function downloadProcessedVideo( Request $request )
    {
        $jobId = $request->route( 'job_id' );
        $status = cache()->get( "video_job_{$jobId}" );

        if (!$status || $status['status'] !== 'completed') {
            return response()->json( ['error' => 'Video not ready'], 404 );
        }

        $tmpFile = $status['file_path'] ?? null;

        // remove non-error logs

        if (!$tmpFile || !file_exists( $tmpFile )) {
            // Try to regenerate the file if it's missing
            if ($tmpFile && !file_exists( $tmpFile )) {


                try {
                    // Regenerate the video
                    $this->processVideoWithTimeout( $jobId, $status['hls'], $status['name'] );

                    // Get updated status
                    $status = cache()->get( "video_job_{$jobId}" );
                    $tmpFile = $status['file_path'] ?? null;

                    if (!$tmpFile || !file_exists( $tmpFile )) {
                        throw new \Exception( 'Failed to regenerate video file' );
                    }
                } catch (\Exception $e) {
                    Log::error( '[Download] Failed to regenerate video', [
                        'job_id' => $jobId,
                        'error' => $e->getMessage()
                    ] );

                    // Fallback: process video directly and serve it
                    try {
                        $ua = 'FlinkDownloader/1.0 (+https://flink.ma)';
                        $ref = 'https://flink.ma/';
                        $tmpDir = storage_path( 'app/tmp' );
                        if (!is_dir( $tmpDir )) {
                            @mkdir( $tmpDir, 0775, true );
                        }
                        $fallbackFile = $tmpDir . '/' . Str::uuid() . '.mp4';

                        $this->processHlsWithWatermarkSimple( $status['hls'], $fallbackFile, $ua, $ref );

                        if (file_exists( $fallbackFile ) && filesize( $fallbackFile ) > 0) {
                            return response()->download(
                                $fallbackFile,
                                $status['name'] . '.mp4',
                                [
                                    'Content-Type' => 'video/mp4',
                                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                                    'Pragma' => 'no-cache',
                                ]
                            )->deleteFileAfterSend( true );
                        }
                    } catch (\Exception $fallbackError) {
                        Log::error( '[Download] Fallback processing also failed', [
                            'job_id' => $jobId,
                            'error' => $fallbackError->getMessage()
                        ] );
                    }

                    return response()->json( ['error' => 'Video file not found and could not be regenerated'], 404 );
                }
            } else {
                return response()->json( ['error' => 'Video file not found'], 404 );
            }
        }

        return response()->download(
            $tmpFile,
            $status['name'] . '.mp4',
            [
                'Content-Type' => 'video/mp4',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]
        )->deleteFileAfterSend( true );
    }

    public function startImageProcessing( Request $request )
    {
        $request->validate( [
            'images' => 'required|array',
            'images.*' => 'required|url',
            'name' => 'nullable|string',
            'watermark_image' => 'nullable|string',
        ] );

        $images = $request->input( 'images' );
        $name = $request->input( 'name', 'flink-images' );
        $watermarkImage = $request->input( 'watermark_image' );
        $skipWatermark = (bool) $request->input( 'skip_watermark', false );
        $profileSlug = $request->input( 'profile_slug', '' );
        $safeName = preg_replace( '/[^a-zA-Z0-9-_\.]/', '_', $name ) ?: 'flink-images';

        // Log incoming request for debugging
        Log::info( '[Image Processing] Request received', [
            'images_count' => count( $images ),
            'name' => $safeName,
            'profile_slug' => $profileSlug,
            'skip_watermark' => $skipWatermark,
            'has_watermark_image' => !empty( $watermarkImage ),
            'watermark_image_length' => $watermarkImage ? strlen( $watermarkImage ) : 0,
            'watermark_image_preview' => $watermarkImage ? substr( $watermarkImage, 0, 100 ) . '...' : 'null'
        ] );

        // If skip_watermark is true, clear watermark_image (owner downloading own post)
        // Otherwise, use watermark_image if provided (same logic as disconnected)
        if ($skipWatermark) {
            Log::info( '[Image Processing] Skipping watermark (owner downloading own post)' );
            $watermarkImage = null;
        } else {
            if ($watermarkImage) {
                Log::info( '[Image Processing] Watermark image will be used', [
                    'watermark_length' => strlen( $watermarkImage )
                ] );
            } else {
                Log::warning( '[Image Processing] No watermark image provided but skip_watermark is false' );
            }
        }

        // remove non-error logs

        // Generate unique job ID
        $jobId = Str::uuid();



        // Store job status in cache
        cache()->put( "image_job_{$jobId}", [
            'status' => 'queued',
            'progress' => 0,
            'message' => 'Images queued for processing...',
            'images' => $images,
            'name' => $safeName,
            'total_images' => count( $images ),
            'processed_images' => 0,
            'watermark_image' => $watermarkImage,
            'created_at' => now()
        ], 3600 ); // 1 hour TTL

        // Process images immediately
        try {
            $this->processImagesWithWatermark( $jobId, $images, $safeName, $watermarkImage );
        } catch (\Exception $e) {
            cache()->put( "image_job_{$jobId}", [
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Processing failed: ' . $e->getMessage(),
                'images' => $images,
                'name' => $safeName,
                'total_images' => count( $images ),
                'processed_images' => 0,
                'created_at' => now(),
                'error' => $e->getMessage()
            ], 3600 );
        }

        return response()->json( [
            'job_id' => $jobId,
            'status' => 'processing',
            'message' => 'Image processing started',
            'total_images' => count( $images )
        ] );
    }

    public function getImageProcessingStatus( Request $request )
    {
        $jobId = $request->query( 'job_id' );

        if (!$jobId) {
            return response()->json( ['error' => 'Job ID required'], 400 );
        }

        $status = cache()->get( "image_job_{$jobId}" );

        if (!$status) {
            return response()->json( ['error' => 'Job not found'], 404 );
        }

        return response()->json( $status );
    }

    public function downloadProcessedImage( Request $request )
    {
        $jobId = $request->route( 'job_id' );
        $imageIndex = (int) $request->route( 'image_index' );
        $status = cache()->get( "image_job_{$jobId}" );

        if (!$status || $status['status'] !== 'completed') {
            return response()->json( ['error' => 'Images not ready'], 404 );
        }

        $processedImages = $status['processed_images_data'] ?? [];

        if (!isset( $processedImages[$imageIndex] )) {
            return response()->json( ['error' => 'Image not found'], 404 );
        }

        $imageData = $processedImages[$imageIndex];
        $filePath = $imageData['file_path'];

        if (!file_exists( $filePath )) {
            return response()->json( ['error' => 'Image file not found'], 404 );
        }

        return response()->download(
            $filePath,
            $imageData['filename'],
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]
        )->deleteFileAfterSend( true );
    }

    private function processImagesWithWatermark( $jobId, $images, $safeName, $watermarkDataUrl = null )
    {
        $ua = 'FlinkDownloader/1.0 (+https://flink.ma)';
        $ref = 'https://flink.ma/';



        // Temp file paths
        $tmpDir = storage_path( 'app/tmp' );
        if (!is_dir( $tmpDir )) {
            @mkdir( $tmpDir, 0775, true );
        }

        $processedImages = [];
        $totalImages = count( $images );
        $overlayFile = null;

        try {
            // Always use watermark_image if provided (same logic as disconnected)
            // This ensures connected users get watermark when not owner, just like disconnected users
            Log::info( '[Image Processing] Processing images', [
                'job_id' => $jobId,
                'total_images' => $totalImages,
                'has_watermark_data_url' => !empty( $watermarkDataUrl ),
                'watermark_data_url_length' => $watermarkDataUrl ? strlen( $watermarkDataUrl ) : 0
            ] );

            if ($watermarkDataUrl) {
                Log::info( '[Image Processing] Saving watermark data URL to PNG file' );
                $overlayFile = $this->saveDataUrlToPng( $watermarkDataUrl );
                if ($overlayFile) {
                    Log::info( '[Image Processing] Watermark file created', [
                        'overlay_file' => $overlayFile,
                        'file_exists' => file_exists( $overlayFile ),
                        'file_size' => file_exists( $overlayFile ) ? filesize( $overlayFile ) : 0
                    ] );
                } else {
                    Log::warning( '[Image Processing] Failed to save watermark data URL to PNG' );
                }
            } else {
                Log::info( '[Image Processing] No watermark data URL provided, processing without watermark' );
            }

            foreach ($images as $index => $imageUrl) {


                // Update progress with counter format
                cache()->put( "image_job_{$jobId}", [
                    'status' => 'processing',
                    'progress' => $index + 1,
                    'message' => "Processing image " . ( $index + 1 ) . " of {$totalImages}...",
                    'images' => $images,
                    'name' => $safeName,
                    'total_images' => $totalImages,
                    'processed_images' => $index,
                    'created_at' => now()
                ], 3600 );

                // Process single image
                $outputFile = $tmpDir . '/' . Str::uuid() . '.png';
                // If no overlay supplied, output the original as PNG without any watermark
                if (!$overlayFile) {
                    Log::info( '[Image Processing] Processing image without watermark', [
                        'image_index' => $index + 1,
                        'image_url' => $imageUrl
                    ] );
                    $this->processSingleImageNoWatermark( $imageUrl, $outputFile, $ua, $ref );
                } else {
                    Log::info( '[Image Processing] Processing image with watermark', [
                        'image_index' => $index + 1,
                        'image_url' => $imageUrl,
                        'overlay_file' => $overlayFile
                    ] );
                    $this->processSingleImageWithWatermark( $imageUrl, $outputFile, $overlayFile, $ua, $ref );
                }

                if (file_exists( $outputFile ) && filesize( $outputFile ) > 0) {
                    $processedImages[] = [
                        'file_path' => $outputFile,
                        'filename' => $safeName . '-' . ( $index + 1 ) . '.png',
                        'original_url' => $imageUrl
                    ];

                    // Update status to show this image is ready for download
                    cache()->put( "image_job_{$jobId}", [
                        'status' => 'processing',
                        'progress' => $index + 1,
                        'message' => "Image " . ( $index + 1 ) . " ready for download...",
                        'images' => $images,
                        'name' => $safeName,
                        'total_images' => $totalImages,
                        'processed_images' => $index + 1,
                        'processed_images_data' => $processedImages,
                        'created_at' => now()
                    ], 3600 );
                }
            }

            // Update status to completed (progress equals number processed for counter-style UI)
            cache()->put( "image_job_{$jobId}", [
                'status' => 'completed',
                'progress' => $totalImages,
                'message' => 'All images ready for download!',
                'images' => $images,
                'name' => $safeName,
                'total_images' => $totalImages,
                'processed_images' => $totalImages,
                'processed_images_data' => $processedImages,
                'created_at' => now(),
                'completed_at' => now()
            ], 3600 );

        } catch (\Exception $e) {
            Log::error( '[Image Processing] Failed', ['error' => $e->getMessage(), 'job_id' => $jobId] );

            // Clean up processed files
            foreach ($processedImages as $image) {
                @unlink( $image['file_path'] );
            }

            // Update status to failed
            cache()->put( "image_job_{$jobId}", [
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Image processing failed: ' . $e->getMessage(),
                'images' => $images,
                'name' => $safeName,
                'total_images' => $totalImages,
                'processed_images' => 0,
                'created_at' => now(),
                'error' => $e->getMessage()
            ], 3600 );
        }
    }

    private function processSingleImageWithWatermark( $imageUrl, $outputFile, $overlayFile, $userAgent, $referer )
    {
        $ffmpeg = env( 'FFMPEG_PATH', 'ffmpeg' );

        // Overlay provided watermark image (kept aspect ratio), right aligned and vertically centered;
        // fallback to a small text if no overlay available
        if ($overlayFile && file_exists( $overlayFile )) {
            // Use a smaller fixed pixel height for the overlay, independent of base image dimensions.
            // Preserve the overlay's own aspect ratio (no stretching) and normalize SAR to avoid squeeze.
            $filterComplex = "[1:v]format=rgba,setsar=1,scale=w=-1:h=100:flags=lanczos[wm];"
                . "[0:v]setsar=1[base];"
                . "[base][wm]overlay=x=W-w-10:y=H*0.25-h/2";
        } else {
            $filterComplex = 'drawtext=text=\\\'Flink.ma\\\':fontcolor=white@0.40:fontsize=h*0.075:bordercolor=black@0.15:borderw=1:x=(w-text_w)/2:y=(h-text_h)/2';
        }

        $cmd = [
            $ffmpeg,
            '-y',
            '-nostdin',
            '-headers',
            "User-Agent: {$userAgent}\r\nReferer: {$referer}\r\n",
            '-protocol_whitelist',
            'file,http,https,tcp,tls,crypto',
            '-analyzeduration',
            '0',
            '-probesize',
            '16M',
            '-user_agent',
            $userAgent,
            '-i',
            $imageUrl,
        ];

        // Add overlay input if available
        if ($overlayFile && file_exists( $overlayFile )) {
            $cmd[] = '-i';
            $cmd[] = $overlayFile;
        }

        $cmd = array_merge( $cmd, [
            '-filter_complex',
            $filterComplex,
            '-c:v',
            'png',
            '-pix_fmt',
            'rgba',
            $outputFile
        ] );

        $process = new Process( $cmd );
        $process->setTimeout( 60 );
        $process->setIdleTimeout( 60 );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception( 'Image processing failed: ' . $process->getErrorOutput() );
        }

        // Verify output file is valid
        if (!file_exists( $outputFile ) || filesize( $outputFile ) === 0) {
            throw new \Exception( 'Output image file was not created or is empty' );
        }
    }

    private function processHlsWithWatermark( $hlsUrl, $outputFile, $userAgent, $referer, $overlayFile = null )
    {
        $ffmpeg = env( 'FFMPEG_PATH', 'ffmpeg' );

        // Process HLS stream directly with watermark
        $cmd = [
            $ffmpeg,
            '-y',
            '-nostdin',
            '-headers',
            "User-Agent: {$userAgent}\r\nReferer: {$referer}\r\n",
            '-protocol_whitelist',
            'file,http,https,tcp,tls,crypto',
            '-allowed_extensions',
            'ALL',
            '-analyzeduration',
            '0',
            '-probesize',
            '32M',
            '-user_agent',
            $userAgent,
            '-i',
            $hlsUrl,
        ];

        // Add overlay input if available
        if ($overlayFile && file_exists( $overlayFile )) {
            $cmd[] = '-i';
            $cmd[] = $overlayFile;
        }

        // Build pipeline
        if ($overlayFile && file_exists( $overlayFile )) {
            // Overlay present: build filter and map explicit video/audio
            // Cycle position every 3 seconds: 0-3s right-middle, 3-6s bottom-left (25% from bottom), 6-9s top-center, then repeat
            $filterComplex = '[1:v]format=rgba,setsar=1,scale=w=-1:h=140:flags=lanczos[wm];'
                . '[0:v]setsar=1[base];'
                . "[base][wm]overlay=x=if(lt(mod(t\\,9)\\,3)\\,W-w-15\\,if(lt(mod(t\\,9)\\,6)\\,15\\,(W-w)/2)):"
                . "y=if(lt(mod(t\\,9)\\,3)\\,(H-h)/2\\,if(lt(mod(t\\,9)\\,6)\\,H-h-H*0.25\\,15))[vout]";
            $cmd = array_merge( $cmd, [
                '-filter_complex',
                $filterComplex,
                '-map',
                '[vout]',
                '-map',
                '0:a?',
                '-c:v',
                'libx264',
                '-preset',
                'ultrafast',
                '-crf',
                '30',
                '-c:a',
                'aac',
                '-b:a',
                '64k',
                '-ac',
                '2',
                '-movflags',
                '+faststart',
                '-fflags',
                '+genpts',
                '-flush_packets',
                '1',
                '-pix_fmt',
                'yuv420p',
                '-profile:v',
                'baseline',
                '-level',
                '3.0',
                '-tune',
                'fastdecode',
                '-threads',
                '4',
                '-x264opts',
                'no-cabac:ref=1:subme=1:me=dia:analyse=none:trellis=0:no-fast-pskip:8x8dct=0:weightb=0',
                $outputFile
            ] );
        } else {
            // No overlay: avoid filter_complex; explicitly map streams and transcode video
            $cmd = array_merge( $cmd, [
                '-map',
                '0:v:0',
                '-map',
                '0:a?',
                '-c:v',
                'libx264',
                '-preset',
                'ultrafast',
                '-crf',
                '30',
                '-c:a',
                'aac',
                '-b:a',
                '64k',
                '-ac',
                '2',
                '-movflags',
                '+faststart',
                '-fflags',
                '+genpts',
                '-flush_packets',
                '1',
                '-pix_fmt',
                'yuv420p',
                '-profile:v',
                'baseline',
                '-level',
                '3.0',
                '-tune',
                'fastdecode',
                '-threads',
                '4',
                '-x264opts',
                'no-cabac:ref=1:subme=1:me=dia:analyse=none:trellis=0:no-fast-pskip:8x8dct=0:weightb=0',
                $outputFile
            ] );
        }

        $process = new Process( $cmd );
        $process->setTimeout( 3600 );
        $process->setIdleTimeout( 3600 );
        $process->run();

        // Clean up overlay temp
        if ($overlayFile && file_exists( $overlayFile )) {
            @unlink( $overlayFile );
        }



        if (!$process->isSuccessful()) {
            throw new \Exception( 'FFmpeg processing failed: ' . $process->getErrorOutput() );
        }

        // Verify output file is valid
        if (!file_exists( $outputFile ) || filesize( $outputFile ) === 0) {
            throw new \Exception( 'Output file was not created or is empty' );
        }
    }

    // Removed external logo download per request – no fallback logo is used.

    private function downloadAnimatedLogo( $userAgent, $referer )
    {
        $logoUrl = 'https://media.flink.ma/design-icons/flink-animated-gif.gif';
        $tmpDir = storage_path( 'app/tmp' );
        $logoFile = $tmpDir . '/flink-animated-logo-' . Str::uuid() . '.gif';

        try {
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $logoUrl );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
            curl_setopt( $ch, CURLOPT_USERAGENT, $userAgent );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, [
                'Referer: ' . $referer
            ] );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );

            $logoContent = curl_exec( $ch );
            $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            if ($logoContent && $httpCode === 200 && strlen( $logoContent ) > 100) {
                file_put_contents( $logoFile, $logoContent );
                return $logoFile;
            } else {
                return null;
            }
        } catch (\Exception $e) {

            return null;
        }
    }

    private function saveDataUrlToPng( $dataUrl )
    {
        try {
            Log::info( '[Watermark Save] Starting to save data URL', [
                'data_url_length' => strlen( $dataUrl ),
                'data_url_preview' => substr( $dataUrl, 0, 100 ) . '...',
                'starts_with_data_image' => strpos( $dataUrl, 'data:image' ) === 0
            ] );

            if (strpos( $dataUrl, 'data:image' ) !== 0) {
                Log::warning( '[Watermark Save] Data URL does not start with data:image', [
                    'prefix' => substr( $dataUrl, 0, 20 )
                ] );
                return null;
            }

            [$meta, $data] = explode( ',', $dataUrl, 2 );
            Log::info( '[Watermark Save] Extracted data from data URL', [
                'meta_length' => strlen( $meta ),
                'data_length' => strlen( $data )
            ] );

            $decoded = base64_decode( $data, true );
            if ($decoded === false) {
                Log::error( '[Watermark Save] Failed to decode base64 data' );
                return null;
            }

            Log::info( '[Watermark Save] Decoded data', [
                'decoded_length' => strlen( $decoded )
            ] );

            $tmpDir = storage_path( 'app/tmp' );
            if (!is_dir( $tmpDir )) {
                @mkdir( $tmpDir, 0775, true );
                Log::info( '[Watermark Save] Created tmp directory', ['dir' => $tmpDir] );
            }

            $file = $tmpDir . '/wm-' . Str::uuid() . '.png';
            $bytesWritten = file_put_contents( $file, $decoded );

            if ($bytesWritten === false) {
                Log::error( '[Watermark Save] Failed to write file', ['file' => $file] );
                return null;
            }

            Log::info( '[Watermark Save] Successfully saved watermark file', [
                'file' => $file,
                'bytes_written' => $bytesWritten,
                'file_exists' => file_exists( $file ),
                'file_size' => file_exists( $file ) ? filesize( $file ) : 0
            ] );

            return $file;
        } catch (\Exception $e) {
            Log::error( '[Watermark Save] Exception occurred', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ] );
            return null;
        }
    }

    private function processSingleImageNoWatermark( $imageUrl, $outputFile, $userAgent, $referer )
    {
        $ffmpeg = env( 'FFMPEG_PATH', 'ffmpeg' );
        $cmd = [
            $ffmpeg,
            '-y',
            '-nostdin',
            '-headers',
            "User-Agent: {$userAgent}\r\nReferer: {$referer}\r\n",
            '-protocol_whitelist',
            'file,http,https,tcp,tls,crypto',
            '-analyzeduration',
            '0',
            '-probesize',
            '16M',
            '-user_agent',
            $userAgent,
            '-i',
            $imageUrl,
            '-c:v',
            'png',
            '-pix_fmt',
            'rgba',
            $outputFile
        ];
        $process = new Process( $cmd );
        $process->setTimeout( 60 );
        $process->setIdleTimeout( 60 );
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \Exception( 'Image processing failed: ' . $process->getErrorOutput() );
        }
        if (!file_exists( $outputFile ) || filesize( $outputFile ) === 0) {
            throw new \Exception( 'Output image file was not created or is empty' );
        }
    }





    public function unrepostPublication( Request $request )
    {
        $validator = \Validator::make( $request->all(), [
            'publication_id' => 'required|integer|exists:publications_flink,id',
            'profile_id' => 'required|integer',
            'profile_type' => 'required|string|in:user,etablissement',
        ] );
        if ($validator->fails()) {
            return response()->json( ['error' => $validator->errors()->first()], 422 );
        }

        $publicationId = (int) $request->input( 'publication_id' );
        $profileId = (int) $request->input( 'profile_id' );
        $profileType = $request->input( 'profile_type' );

        $userId = $profileType === 'user' ? $profileId : null;
        $etabId = $profileType === 'etablissement' ? $profileId : null;

        $deleted = \DB::table( 'publications_reposted' )
            ->where( 'publication_id', $publicationId )
            ->when( $userId, fn( $q ) => $q->where( 'user_id', $userId )->whereNull( 'etab_id' ) )
            ->when( $etabId, fn( $q ) => $q->where( 'etab_id', $etabId )->whereNull( 'user_id' ) )
            ->delete();

        if ($deleted === 0) {
            return response()->json( ['error' => 'Repost not found'], 404 );
        }

        return response()->json( [
            'success' => true,
            'message' => 'Repost removed successfully',
        ] );
    }























    public function getContactsBySlug( Request $request )
    {
        $validator = Validator::make( $request->all(), [
            'slug' => 'required|string',
        ] );
        if ($validator->fails()) {
            return response()->json( ['error' => $validator->errors()->first()], 422 );
        }

        $slug = $request->slug;

        // 1) Resolve profile by slug (user or etablissement)
        $entity = Etablissement::where( 'slug', $slug )->first();
        $entityType = 'etablissement';
        if (!$entity) {
            $entity = User::where( 'slug', $slug )->first();
            $entityType = $entity ? 'user' : null;
        }
        if (!$entity || !$entityType) {
            return response()->json( ['error' => 'Profil introuvable'], 404 );
        }

        $currentId = $entity->id;

        // 2) Fetch conversations for that profile (latest-first)
        $conversations = Conversation::where( function ( $q ) use ( $currentId ) {
            $q->where( 'sender_id', $currentId )->where( 'receiver_id', '!=', $currentId );
        } )
            ->orWhere( function ( $q ) use ( $currentId ) {
                $q->where( 'receiver_id', $currentId )->where( 'sender_id', '!=', $currentId );
            } )
            ->with( [
                'messages' => function ( $q ) {
                    $q->orderBy( 'created_at', 'desc' )
                        ->with( [
                            'publication' => function ( $qq ) {
                                $qq->select( 'id', 'image_default', 'slug', 'description', 'reference' );
                            }
                        ] );
                }
            ] )
            ->orderBy( 'pinned', 'desc' )
            ->orderBy( 'updated_at', 'desc' )
            ->get();

        // Map: other party info from conversation/messages
        $conversationProfiles = [];
        $seenOtherIds = [];

        foreach ($conversations as $conv) {
            $otherId = ( $conv->sender_id == $currentId ) ? $conv->receiver_id : $conv->sender_id;

            if (isset( $seenOtherIds[$otherId] )) {
                continue;
            }

            // Determine other type from latest message if available, else from conversation
            $latest = $conv->messages->first();
            $otherType = null;
            if ($latest) {
                if ($latest->sender_id == $currentId) {
                    $otherType = $latest->type_receiver;
                } else {
                    $otherType = $latest->type_sender;
                }
            }
            if (!$otherType) {
                $otherType = ( $conv->sender_id == $currentId ) ? $conv->type_receiver : $conv->type_sender;
            }

            // Load the other entity
            $other = $otherType === 'etablissement'
                ? Etablissement::find( $otherId )
                : ( $otherType === 'user' ? User::find( $otherId ) : null );

            if (!$other) {
                continue;
            }

            // Compute unread count for this conversation
            $unreadCount = NewMessage::where( 'conversation_id', $conv->id )
                ->where( 'receiver_id', $currentId )
                ->where( 'is_read', 0 )
                ->count();

            // Normalize profile shape
            $name = $otherType === 'etablissement'
                ? ( $other->nom ?? '' )
                : trim( ( $other->first_name ?? '' ) . ' ' . ( $other->last_name ?? '' ) );
            $avatar = $otherType === 'etablissement'
                ? ( $other->logo ?? null )
                : ( $other->avatar ?? null );

            $conversationProfiles[] = [
                'id' => $other->id,
                'slug' => $other->slug ?? null,
                'type' => $otherType,                     // 'user' | 'etablissement'
                'name' => $name,                          // firstname+lastname or nom
                'avatar' => $avatar,                      // avatar or logo (raw path)
                'conversation_id' => $conv->id,           // conversation id when exists
                'unreadCount' => $unreadCount,
                'source' => 'conversation',
            ];
            $seenOtherIds[$other->id] = true;
        }

        // 3) Fetch following of this profile, normalize, exclude those already in conversations
        $followingUsers = collect();
        $followingEtablissements = collect();

        if ($entityType === 'user') {
            $followingUsers = $entity->followingUsers()->get();
            $followingEtablissements = $entity->followingEtablissements()->get();
        } else { // etablissement
            $followingUsers = $entity->followingUsers()->get();
            $followingEtablissements = $entity->followingEtablissements()->get();
        }

        $following = $followingEtablissements->merge( $followingUsers )->values();

        $followingProfiles = [];
        foreach ($following as $f) {
            $fType = ( $f instanceof Etablissement ) ? 'etablissement' : 'user';

            if (isset( $seenOtherIds[$f->id] )) {
                continue;
            }

            $name = $fType === 'etablissement'
                ? ( $f->nom ?? '' )
                : trim( ( $f->first_name ?? '' ) . ' ' . ( $f->last_name ?? '' ) );
            $avatar = $fType === 'etablissement'
                ? ( $f->logo ?? null )
                : ( $f->avatar ?? null );

            $followingProfiles[] = [
                'id' => $f->id,
                'slug' => $f->slug ?? null,
                'type' => $fType,
                'name' => $name,
                'avatar' => $avatar,
                'conversation_id' => null,
                'unreadCount' => 0,
                'source' => 'following',
            ];
        }

        // 4) Final list: conversations first, then following
        $combined = array_values( $conversationProfiles );
        foreach ($followingProfiles as $fp) {
            $combined[] = $fp;
        }

        return response()->json( [
            'count' => count( $combined ),
            'profiles' => $combined,
        ] );
    }

    public function reportPublication( Request $request )
    {
        // Log incoming request
        Log::info( '[Publication Report] Incoming request', [
            'request_data' => $request->all(),
            'headers' => $request->headers->all()
        ] );

        $validator = Validator::make( $request->all(), [
            'publication_id' => 'required|integer|exists:publications_flink,id',
            'profile_id' => 'nullable|integer',
            'profile_type' => 'nullable|string|in:user,etablissement',
            'cause' => 'required|string|in:spam,inappropriate_content,harassment_hate,misleading_scam,other',
            'other_message' => 'nullable|string|max:1000',
        ] );

        if ($validator->fails()) {
            Log::warning( '[Publication Report] Validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ] );
            return response()->json( ['error' => $validator->errors()->first()], 422 );
        }

        $publicationId = $request->input( 'publication_id' );
        $profileId = $request->input( 'profile_id' );
        $profileType = $request->input( 'profile_type' );
        $cause = $request->input( 'cause' );
        $otherMessage = $request->input( 'other_message' );

        Log::info( '[Publication Report] Processing report', [
            'publication_id' => $publicationId,
            'profile_id' => $profileId,
            'profile_type' => $profileType,
            'cause' => $cause,
            'other_message' => $otherMessage
        ] );

        // Validate profile only if provided
        if ($profileType === 'user' && $profileId) {
            $profile = User::find( $profileId );
            if (!$profile) {
                Log::warning( '[Publication Report] User not found', ['profile_id' => $profileId] );
                return response()->json( ['error' => 'User not found'], 404 );
            }
            Log::info( '[Publication Report] User found', ['user_id' => $profile->id, 'user_name' => $profile->first_name . ' ' . $profile->last_name] );
        } elseif ($profileType === 'etablissement' && $profileId) {
            $profile = Etablissement::find( $profileId );
            if (!$profile) {
                Log::warning( '[Publication Report] Etablissement not found', ['profile_id' => $profileId] );
                return response()->json( ['error' => 'Etablissement not found'], 404 );
            }
            Log::info( '[Publication Report] Etablissement found', ['etablissement_id' => $profile->id, 'etablissement_name' => $profile->nom] );
        }

        try {
            Log::info( '[Publication Report] Checking for existing reports', [
                'publication_id' => $publicationId,
                'profile_id' => $profileId,
                'profile_type' => $profileType
            ] );

            // Duplicate check considers profile scope when provided
            $query = PublicationsReport::where( 'publication_id', $publicationId );
            if ($profileType === 'user' && $profileId) {
                $query->where( 'user_id', $profileId )->whereNull( 'etablissement_id' );
            } elseif ($profileType === 'etablissement' && $profileId) {
                $query->where( 'etablissement_id', $profileId )->whereNull( 'user_id' );
            } else {
                // Anonymous report: both null
                $query->whereNull( 'user_id' )->whereNull( 'etablissement_id' );
            }

            $existingReport = $query->first();

            if ($existingReport) {
                Log::info( '[Publication Report] Duplicate report found', [
                    'existing_report_id' => $existingReport->id,
                    'publication_id' => $publicationId,
                    'profile_id' => $profileId
                ] );
                return response()->json( [
                    'error' => 'You have already reported this publication',
                    'already_reported' => true
                ], 409 );
            }

            Log::info( '[Publication Report] No existing report found, creating new one' );

            // Create new report
            $reportData = [
                'publication_id' => $publicationId,
                'cause' => $cause,
                'created_at' => now()
            ];

            // Add the appropriate profile field based on type; anonymous leaves both null
            if ($profileType === 'user' && $profileId) {
                $reportData['user_id'] = $profileId;
            } elseif ($profileType === 'etablissement' && $profileId) {
                $reportData['etablissement_id'] = $profileId;
            }

            // Add other_message if provided
            if ($otherMessage && !empty( trim( $otherMessage ) )) {
                $reportData['other_message'] = trim( $otherMessage );
                Log::info( '[Publication Report] Adding other_message to report data', [
                    'other_message' => $reportData['other_message'],
                    'original_other_message' => $otherMessage
                ] );
            } else {
                Log::info( '[Publication Report] No other_message provided or empty', [
                    'other_message' => $otherMessage,
                    'is_empty' => empty( trim( $otherMessage ?? '' ) )
                ] );
            }

            Log::info( '[Publication Report] Attempting to create report with data', $reportData );

            // Check columns exist (optional safeguard)
            try {
                $tableColumns = \DB::select( "SHOW COLUMNS FROM publications_reports" );
                $columnNames = array_column( $tableColumns, 'Field' );
                $hasOtherMessageColumn = in_array( 'other_message', $columnNames );
                Log::info( '[Publication Report] Table columns', [
                    'columns' => $columnNames,
                    'has_other_message' => $hasOtherMessageColumn
                ] );
                if (!$hasOtherMessageColumn) {
                    Log::info( '[Publication Report] Adding other_message column to table' );
                    \DB::statement( "ALTER TABLE publications_reports ADD COLUMN other_message TEXT NULL DEFAULT NULL AFTER cause" );
                }
            } catch (\Exception $e) {
                Log::warning( '[Publication Report] Could not check table columns', ['error' => $e->getMessage()] );
            }

            $report = PublicationsReport::create( $reportData );

            // Fetch the created report to verify what was actually saved
            $savedReport = PublicationsReport::find( $report->id );

            Log::info( '[Publication Report] New report created successfully', [
                'report_id' => $report->id,
                'publication_id' => $publicationId,
                'profile_id' => $profileId,
                'profile_type' => $profileType,
                'cause' => $cause,
                'other_message' => $otherMessage,
                'saved_other_message' => $savedReport->other_message ?? 'NULL',
                'all_saved_data' => $savedReport->toArray()
            ] );

            return response()->json( [
                'success' => true,
                'message' => 'Publication reported successfully',
                'report_id' => $report->id
            ], 201 );

        } catch (\Exception $e) {
            Log::error( '[Publication Report] Exception occurred', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'publication_id' => $publicationId,
                'profile_id' => $profileId,
                'profile_type' => $profileType,
                'cause' => $cause,
                'other_message' => $otherMessage,
                'stack_trace' => $e->getTraceAsString()
            ] );

            return response()->json( [
                'error' => 'Failed to report publication. Please try again.'
            ], 500 );
        }
    }

    public function repostPublication( Request $request )
    {
        $validator = Validator::make( $request->all(), [
            'publication_id' => 'required|integer|exists:publications_flink,id',
            'profile_id' => 'required|integer',
            'profile_type' => 'required|string|in:user,etablissement',
        ] );

        if ($validator->fails()) {
            return response()->json( ['error' => $validator->errors()->first()], 422 );
        }

        $publicationId = (int) $request->input( 'publication_id' );
        $profileId = (int) $request->input( 'profile_id' );
        $profileType = $request->input( 'profile_type' );

        $userId = null;
        $etabId = null;
        if ($profileType === 'user') {
            // Ensure user exists
            $exists = User::where( 'id', $profileId )->exists();
            if (!$exists) {
                return response()->json( ['error' => 'User not found'], 404 );
            }
            $userId = $profileId;
        } else {
            // Ensure etablissement exists
            $exists = Etablissement::where( 'id', $profileId )->exists();
            if (!$exists) {
                return response()->json( ['error' => 'Etablissement not found'], 404 );
            }
            $etabId = $profileId;
        }

        // Prevent duplicate repost by the same actor for the same publication
        $already = DB::table( 'publications_reposted' )
            ->where( 'publication_id', $publicationId )
            ->where( function ( $q ) use ( $userId, $etabId ) {
                if ($userId) {
                    $q->where( 'user_id', $userId )->whereNull( 'etab_id' );
                } else {
                    $q->where( 'etab_id', $etabId )->whereNull( 'user_id' );
                }
            } )
            ->exists();

        if ($already) {
            return response()->json( [
                'error' => 'Already reposted',
                'already_reposted' => true,
            ], 409 );
        }

        DB::table( 'publications_reposted' )->insert( [
            'publication_id' => $publicationId,
            'user_id' => $userId,
            'etab_id' => $etabId,
            'profile_type' => $profileType,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        return response()->json( [
            'success' => true,
            'message' => 'Publication reposted successfully',
        ], 201 );
    }
}
