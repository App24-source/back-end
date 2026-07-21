<?php

namespace App\Http\Controllers\annonce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Annonce;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;

class AddUpdateVideoController extends Controller
{
    // Constantes pour les chemins de répertoires
    private const BASE_DIRECTORY = 'publication';
    private const VIDEO_DIRECTORY = 'videos';
    private const HLS_DIRECTORY = 'hls';
    private const MINIATURES_DIRECTORY = 'miniatures';
    private const REELS_DIRECTORY = 'reels';
    private const REELS_HLS_DIRECTORY = 'hls';

    /**
     * Crée les répertoires s'ils n'existent pas.
     */
    private function createDirectoryIfNotExists(string $disk, array $directories): void {
        foreach ($directories as $directory) {
            if (!Storage::disk($disk)->exists($directory)) {
                Storage::disk($disk)->makeDirectory($directory);
            }
        }
    }

    /**
     * Supprime un fichier ou un répertoire spécifique.
     */
    private function deleteFileOrDirectory(string $path): void {
        if (Storage::disk('ftp')->exists($path)) {
            if (is_dir(Storage::disk('ftp')->path($path))) {
                Storage::disk('ftp')->deleteDirectory($path); // Supprime un répertoire
            } else {
                Storage::disk('ftp')->delete($path); // Supprime un fichier
            }
        }
    }

    /**
     * Traite et enregistre les fichiers multimédias.
     */
    // public function store(Request $request) {
    //     set_time_limit(500);

    //     $currentYear = date('Y');
    //     $directories = [
    //         'videos' => self::BASE_DIRECTORY . '/' . self::VIDEO_DIRECTORY . "/{$currentYear}",
    //         'hls' => self::BASE_DIRECTORY . '/' . self::VIDEO_DIRECTORY . "/{$currentYear}/" . self::HLS_DIRECTORY,
    //         'miniatures' => self::BASE_DIRECTORY . '/' . self::MINIATURES_DIRECTORY . "/{$currentYear}",
    //         'reels' => self::BASE_DIRECTORY . '/' . self::REELS_DIRECTORY . "/{$currentYear}",
    //         'reels_hls' => self::BASE_DIRECTORY . '/' . self::REELS_DIRECTORY . "/{$currentYear}/" . self::REELS_HLS_DIRECTORY,
    //     ];

    //     // Validation des fichiers
    //     $request->validate([
    //         'video' => 'nullable|file|mimes:mp4,mov,avi,wmv|max:153600',
    //         'poster' => 'nullable|file|mimes:png,jpeg,jpg,webp|max:51200',
    //         'Reel' => 'nullable|file|mimes:mp4,mov,avi,wmv|max:153600',
    //         'posterReels' => 'nullable|file|mimes:png,jpeg,jpg,webp|max:51200',
    //     ]);

    //     if (($request->hasFile('video') || $request->hasFile('Reel')) && $request->selectedSlug) {
    //         $video = $request->file('video');
    //         $poster = $request->file('poster');
    //         $reel = $request->file('Reel');
    //         $reelPoster = $request->file('posterReels');
    //         $slug = $request->selectedSlug;

    //         // Créer les répertoires s'ils n'existent pas
    //         foreach ($directories as $directory) {
    //             $this->createDirectoryIfNotExists('ftp', [$directory]);
    //         }

    //         $annonce = Annonce::where('slug', $slug)->first();

    //         if (!$annonce) {
    //             return response()->json(['error' => 'Publication not found'], 404);
    //         }

    //         try {
    //             Log::info("Starting video processing for slug: {$slug}");

    //             // Traitement de la vidéo principale
    //             if ($video && $video->isValid()) {
    //                 // Supprimer l'ancienne vidéo et son répertoire HLS si elles existent
    //                 if ($annonce->video) {
    //                     $this->deleteFileOrDirectory($annonce->video);
    //                 }
    //                 if ($annonce->video_1) {
    //                     $this->deleteFileOrDirectory(dirname($annonce->video_1));
    //                 }
    //                 $this->processVideo($video, "{$annonce->slug}-video", $directories['videos'], $directories['hls'], $annonce, 'video');
    //             }

    //             // Traitement du reel
    //             if ($reel && $reel->isValid()) {
    //                 // Supprimer l'ancien reel et son répertoire HLS s'ils existent
    //                 if ($annonce->reel) {
    //                     $this->deleteFileOrDirectory($annonce->reel);
    //                 }
    //                 if ($annonce->reel_1) {
    //                     $this->deleteFileOrDirectory(dirname($annonce->reel_1));
    //                 }
    //                 $this->processVideo($reel, "{$annonce->slug}-reel", $directories['reels'], $directories['reels_hls'], $annonce, 'reel');
    //             }

    //             // Traitement des posters
    //             if ($poster && $poster->isValid()) {
    //                 // Supprimer l'ancien poster s'il existe
    //                 if ($annonce->img_video_1) {
    //                     $this->deleteFileOrDirectory($annonce->img_video_1);
    //                 }
    //                 $this->processPoster($poster, $directories['miniatures'], $annonce, 'img_video_1', "{$annonce->slug}-{$annonce->reference}.webp");
    //             }

    //             if ($reelPoster && $reelPoster->isValid()) {
    //                 // Supprimer l'ancien poster s'il existe
    //                 if ($annonce->img_reel_1) {
    //                     $this->deleteFileOrDirectory($annonce->img_reel_1);
    //                 }
    //                 $this->processPoster($reelPoster, $directories['miniatures'], $annonce, 'img_reel_1', "{$annonce->slug}-reel-{$annonce->reference}.webp");
    //             }

    //             $annonce->save();
    //             Log::info("Media processing completed successfully");

    //             return response()->json([
    //                 'message' => 'Media files uploaded and processed successfully!',
    //                 'annonce' => $annonce
    //             ], 201);

    //         } catch (\Exception $e) {
    //             Log::error("Processing failed: " . $e->getMessage());
    //             return response()->json([
    //                 'error' => "Processing failed: {$e->getMessage()}"
    //             ], 500);
    //         }
    //     }

    //     return response()->json(['error' => 'No media files uploaded'], 400);
    // }

    /**
     * Traite une vidéo et la convertit en HLS.
     */
    private function processVideo($videoFile, string $baseName, string $originalDir, string $hlsDir, Annonce $annonce, string $type): void {
        Log::info("Starting optimized video processing for: {$baseName}");

        try {
            // Sauvegarder la vidéo originale
            $originalPath = $videoFile->storeAs($originalDir, "{$baseName}.mp4", 'ftp');

            // Configurer FFmpeg
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => '/usr/bin/ffmpeg',
                'ffprobe.binaries' => '/usr/bin/ffprobe',
                'timeout' => 7200,
                'ffmpeg.threads' => 12,
            ]);

            // Configurer le format HLS
            $format = new X264('aac', 'libx264');
            $format->setKiloBitrate(1024)
                ->setAudioKiloBitrate(128)
                ->setAdditionalParameters([
                    '-profile:v', 'baseline',
                    '-preset', 'ultrafast',
                    '-tune', 'fastdecode',
                    '-movflags', '+faststart',
                    '-sc_threshold', '0',
                    '-g', '48',
                    '-keyint_min', '48',
                    '-hls_time', '6',
                    '-hls_list_size', '0',
                    '-hls_flags', 'independent_segments+delete_segments',
                    '-f', 'hls'
                ]);

            // Créer un répertoire temporaire
            $tempDir = storage_path("temp/{$baseName}");
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            // Convertir la vidéo en HLS
            $video = $ffmpeg->open($videoFile->getPathname());
            $playlistPath = "{$tempDir}/playlist.m3u8";
            $video->save($format, $playlistPath);

            // Téléverser les fichiers HLS
            $hlsTargetDir = "{$hlsDir}/{$baseName}";
            Storage::disk('ftp')->makeDirectory($hlsTargetDir);

            foreach (glob("{$tempDir}/*.*") as $file) {
                Storage::disk('ftp')->put(
                    "{$hlsTargetDir}/" . basename($file),
                    file_get_contents($file)
                );
            }

            // Mettre à jour les chemins dans la base de données
            if ($type === 'video') {
                $annonce->video = $originalPath;
                $annonce->video_1 = "{$hlsTargetDir}/playlist.m3u8";
            } else {
                $annonce->reel = $originalPath;
                $annonce->reel_1 = "{$hlsTargetDir}/playlist.m3u8";
            }

            // Nettoyer les fichiers temporaires
            array_map('unlink', glob("{$tempDir}/*.*"));
            rmdir($tempDir);

        } catch (\Exception $e) {
            Log::error("Error in processVideo: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Traite et enregistre un poster.
     */
    private function processPoster($posterFile, string $directory, Annonce $annonce, string $field, string $fileName): void {
        Log::info("Processing poster: {$fileName}");

        try {
            // Enregistrer le nouveau poster
            $posterPath = $posterFile->storeAs($directory, $fileName, 'ftp');
            $annonce->$field = $posterPath;

        } catch (\Exception $e) {
            Log::error("Error in processPoster: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Récupère les publications d'un utilisateur.
     */
    public function getPublicationsByUser($id) {
        $userAnnonces = Annonce::select('id', 'description', 'prix', 'image', 'video_1', 'img_video_1', 'vedio', 'img_vedio', 'etab_id', 'user_id', 'titre', 'slug', 'cat_g_id', 'region_id', 'ville_id')
            ->where('user_id', $id)
            ->get();

        return response()->json(['message' => 'Success', "data" => $userAnnonces], 201);
    }
}