<?php

namespace App\Services;

use App\Models\Stream;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class MediaService
{
    const THUMBNAIL_SIZE = 80;

    public static function getFileDuration(string $path, string $extension): string
    {
        try {
            if (!file_exists($path)) {
                throw new \Exception("Файл не найден: $path");
            }

            if (!in_array(strtolower($extension), Stream::allowedFormats()['video'])) {
                return '00:05:00';
            }

            $ffprobe = \FFMpeg\FFProbe::create();
            $duration = $ffprobe->format($path)->get('duration');

            return self::formatDuration($duration);
        } catch (\Exception $e) {
            Log::error('Ошибка при получении длительности видео: ' . $e->getMessage());
            return '00:05:00';
        }
    }

    public static function formatDuration($seconds)
    {
        if (!is_numeric($seconds)) {
            return '00:00:00';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    public static function generateVideoThumbnail(string $path, int $second = 1): string
    {
        try {
            $localThumbPath = tempnam(sys_get_temp_dir(), 'thumb_') . '.jpg';

            $ffmpeg = \FFMpeg\FFMpeg::create();
            $video = $ffmpeg->open($path);
            $frame = $video->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds($second));
            $frame->save($localThumbPath);

            $img = \Intervention\Image\ImageManagerStatic::make($localThumbPath)
                ->fit(self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE);
            $img->save($localThumbPath);

            return $localThumbPath;
        } catch (\Throwable $e) {
            Log::error("Ошибка генерации превью видео {$path}: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getRelativePathFromUrl(string $url): string
    {
        $relativePath = parse_url($url, PHP_URL_PATH);
        $relativePath = ltrim($relativePath, '/');
        return urldecode($relativePath);
    }

    public static function compress(string $sourcePath, ?string $targetPath = null): bool|int
    {
        $disk = Storage::disk(config('lfm.disk'));
        $targetPath = $targetPath ?? 'compressed/' . basename($sourcePath);
        $tempFiles = [];

        echo "Обрабатываем $sourcePath\n";

        // Поддерживаемые форматы
        $allowedFormats = ['mp4', 'webm', 'mov', 'avi'];
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedFormats)) {
            Log::channel('video')->info("Файл {$sourcePath} имеет неподдерживаемый формат ({$ext}), сжатие пропущено");
            return $disk->size($sourcePath);
        }

        try {
            if (!$disk->exists($sourcePath)) {
                throw new \Exception("Файл {$sourcePath} не найден в хранилище");
            }

            // --- Временные файлы ---
            $tmpSource = tempnam(sys_get_temp_dir(), 'video_src_') . '.' . $ext;
            $tmpTarget = tempnam(sys_get_temp_dir(), 'video_out_') . '.mp4';
            $tempFiles[] = $tmpSource;
            $tempFiles[] = $tmpTarget;

            // --- Потоковое копирование из S3 ---
            $readStream = $disk->readStream($sourcePath);
            if ($readStream === false) {
                throw new \Exception("Не удалось открыть поток для $sourcePath");
            }
            $writeStream = fopen($tmpSource, 'w');
            stream_copy_to_stream($readStream, $writeStream);
            fclose($writeStream);
            fclose($readStream);

            // --- Размер исходного видео ---
            $inputSize = filesize($tmpSource);
            $inputSizeMb = round($inputSize / 1024 / 1024, 2);
            if ($inputSizeMb < 10) {
                Log::channel('video')->info("Видео {$sourcePath} слишком маленькое ({$inputSizeMb} MB), сжатие пропущено");
                return $disk->size($sourcePath);
            }

            // --- Получаем разрешение и битрейт ---
            $probe = new Process([
                'ffprobe',
                '-v',
                'error',
                '-select_streams',
                'v:0',
                '-show_entries',
                'stream=bit_rate,width,height',
                '-of',
                'json',
                $tmpSource
            ]);
            $probe->mustRun();
            $info = json_decode($probe->getOutput(), true);
            $stream = $info['streams'][0] ?? [];
            $bitrate = isset($stream['bit_rate']) ? (int)$stream['bit_rate'] : 0;

            if ($bitrate > 0 && $bitrate / 1000 < 1100) {
                Log::channel('video')->info("Видео {$sourcePath} уже с низким битрейтом (" . round($bitrate / 1000) . " kbps), сжатие пропущено");
                return $disk->size($sourcePath);
            }

            $width = $stream['width'] ?? 0;
            $height = $stream['height'] ?? 0;
            $scaleArg = ($height > 1080) ? 'scale=-2:1080' : '';

            // --- ffmpeg процесс (потоковое) ---
            $ffmpegCmd = [
                'ffmpeg',
                '-y',
                '-i',
                $tmpSource,
                '-c:v',
                'libx264',
                '-crf',
                '28',
                '-preset',
                'fast',
                '-r',
                '30',
            ];

            if ($scaleArg) {
                $ffmpegCmd[] = '-vf';
                $ffmpegCmd[] = $scaleArg;
            }

            $ffmpegCmd = array_merge($ffmpegCmd, [
                '-c:a',
                'aac',
                '-b:a',
                '96k',
                $tmpTarget
            ]);

            $ffmpeg = new Process($ffmpegCmd);
            $ffmpeg->setTimeout(null);
            $ffmpeg->mustRun();

            // --- Размер после сжатия ---
            $outputSize = filesize($tmpTarget);
            $outputSizeMb = round($outputSize / 1024 / 1024, 2);

            // --- Потоковая загрузка в S3 ---
            $disk->put($targetPath, fopen($tmpTarget, 'r'));

            Log::channel('video')->info("Сжатие видео выполнено: {$sourcePath}, {$inputSizeMb} MB → {$outputSizeMb} MB");

            return $disk->size($targetPath);
        } catch (\Exception $e) {
            Log::channel('video')->error("Ошибка сжатия видео {$sourcePath}: " . $e->getMessage());
            return false;
        } finally {
            foreach ($tempFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
