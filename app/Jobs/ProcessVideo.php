<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $fileId;
    protected $originalName;
    protected $combinedPath;
    public $timeout = 3600;

    /**
     * Create a new job instance.
     *
     * @param string $fileId
     * @param string $originalName
     * @param string $combinedPath
     */
    public function __construct($fileId, $originalName, $combinedPath)
    {
        $this->fileId = $fileId;
        $this->originalName = $originalName;
        $this->combinedPath = $combinedPath;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $fileId = $this->fileId;
        $currentYear = date('Y');
        $combinedPath = $this->combinedPath;

        // Set status to processing
        \Cache::put("video_status_{$fileId}", 'processing', now()->addHours(24));
        Log::info("Video processing started for file ID {$fileId}");

        if (!file_exists($combinedPath)) {
            Log::error("Combined video not found: {$combinedPath}");
            \Cache::put("video_status_{$fileId}", 'error', now()->addHours(24));
            \Cache::put("video_error_{$fileId}", "Input file not found", now()->addHours(24));
            return;
        }

        $hlsOutputPath = storage_path("app/temp/combined/{$fileId}/hls");
        $ftpHlsPath = "publication/videos/{$currentYear}/hls/{$fileId}";
        $thumbnailPath = "publication/miniature/{$currentYear}/{$fileId}.jpg";

        // Create local directory
        if (!is_dir($hlsOutputPath)) {
            mkdir($hlsOutputPath, 0777, true);
        }

        // ── Thumbnail Strategy ──
        $localThumbnailPath = storage_path("app/temp/combined/{$fileId}/thumbnail.jpg");
        $ffmpegBin = env('FFMPEG_PATH', 'ffmpeg');
        $thumbCmd = "{$ffmpegBin} -ss 2 -i " . escapeshellarg($combinedPath) . " -vframes 1 -vf \"scale=640:-1\" -y " . escapeshellarg($localThumbnailPath) . " 2>&1";
        exec($thumbCmd, $tOut, $tCode);
        
        if ($tCode === 0 && file_exists($localThumbnailPath)) {
            Storage::disk('ftp')->put($thumbnailPath, file_get_contents($localThumbnailPath));
        }

        // ── FFmpeg ABR Multi-rendition HLS Strategy ──
        $hlsCommand = "{$ffmpegBin} -i " . escapeshellarg($combinedPath) . " " .
            "-filter_complex \"[0:v]split=2[v1][v2]; [v1]scale=-2:480,setsar=1,format=yuv420p[v480]; [v2]scale=-2:720,setsar=1,format=yuv420p[v720]\" " .
            "-map \"[v480]\" -c:v:0 libx264 -profile:v:0 main -preset:v:0 veryfast -b:v:0 1700k -maxrate:v:0 1900k -bufsize:v:0 2600k -g 60 -keyint_min 60 -sc_threshold 0 " .
            "-map \"[v720]\" -c:v:1 libx264 -profile:v:1 main -preset:v:1 veryfast -b:v:1 2200k -maxrate:v:1 2500k -bufsize:v:1 3500k -g 60 -keyint_min 60 -sc_threshold 0 " .
            "-map a:0 -c:a:0 aac -b:a:0 96k -ac:a:0 2 " .
            "-map a:0 -c:a:1 aac -b:a:1 128k -ac:a:1 2 " .
            "-f hls -hls_time 2 -hls_playlist_type vod -hls_flags independent_segments " .
            "-master_pl_name playlist.m3u8 " .
            "-var_stream_map \"v:0,a:0 v:1,a:1\" " .
            "-hls_segment_filename " . escapeshellarg("{$hlsOutputPath}/%v_seg_%03d.ts") . " " .
            escapeshellarg("{$hlsOutputPath}/v%v.m3u8") . " 2>&1";

        Log::info("Executing FFmpeg: {$hlsCommand}");
        exec($hlsCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("FFmpeg failed with return code: {$returnCode}");
            Log::error(implode("\n", $output));
            \Cache::put("video_status_{$fileId}", 'error', now()->addHours(24));
            return;
        }

        // Upload to FTP
        foreach (scandir($hlsOutputPath) as $file) {
            if (in_array($file, ['.', '..'])) continue;
            Storage::disk('ftp')->put("{$ftpHlsPath}/{$file}", file_get_contents("{$hlsOutputPath}/{$file}"));
        }

        // Cleanup
        try {
            if (is_dir(storage_path("app/temp/uploads/{$fileId}"))) {
                \File::deleteDirectory(storage_path("app/temp/uploads/{$fileId}"));
            }
            if (is_dir(storage_path("app/temp/combined/{$fileId}"))) {
                \File::deleteDirectory(storage_path("app/temp/combined/{$fileId}"));
            }
        } catch (\Exception $e) {
            Log::warning("Cleanup failed: " . $e->getMessage());
        }

        // Store results and set status to completed
        $result = [
            'success' => true,
            'hls_playlist' => "{$ftpHlsPath}/playlist.m3u8",
            'thumbnail' => $thumbnailPath,
            'file_id' => $fileId
        ];

        \Cache::put("video_result_{$fileId}", $result, now()->addDays(1));
        \Cache::put("video_status_{$fileId}", 'completed', now()->addHours(24));
        Log::info("Video processing finished successfully for file ID {$fileId}");
    }
}