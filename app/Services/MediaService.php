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
}
