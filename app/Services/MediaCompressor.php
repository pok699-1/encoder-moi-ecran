<?php

namespace App\Services;

use App\Models\Stream;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Storage;

class MediaCompressor
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    public static function compress(string $sourcePath, ?string $targetPath = null)
    {
        $disk = Storage::disk(config('lfm.disk'));
        $targetPath = $targetPath ?? 'compressed/' . basename($sourcePath);

        echo "Обрабатываем $sourcePath\n";

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($ext, Stream::allowedFormats()['video']) && !in_array($ext, Stream::allowedFormats()['image'])) {
            Log::channel('video')->info("Файл {$sourcePath} имеет неподдерживаемый формат ({$ext}), сжатие пропущено");
            return $disk->size($sourcePath);
        }

        if (in_array($ext, Stream::allowedFormats()['video'])) {
            return static::compressVideo($sourcePath, $targetPath);
        } elseif (in_array($ext, Stream::allowedFormats()['image'])) {
            return static::compressImage($sourcePath, $targetPath);
        }
    }

    public static function compressVideo(string $sourcePath, ?string $targetPath = null): bool|int
    {
        $disk = Storage::disk(config('lfm.disk'));
        $targetPath = $targetPath ?? 'compressed/' . basename($sourcePath);
        $tempFiles = [];

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        echo "Обрабатываем $sourcePath\n";

        try {
            if (!$disk->exists($sourcePath)) {
                throw new \Exception("Файл {$sourcePath} не найден в хранилище");
            }

            $tmpSource = tempnam(sys_get_temp_dir(), 'video_src_') . '.' . $ext;
            $tmpTarget = tempnam(sys_get_temp_dir(), 'video_out_') . '.mp4';
            $tempFiles[] = $tmpSource;
            $tempFiles[] = $tmpTarget;

            $readStream = $disk->readStream($sourcePath);
            if ($readStream === false) {
                throw new \Exception("Не удалось открыть поток для $sourcePath");
            }
            $writeStream = fopen($tmpSource, 'w');
            stream_copy_to_stream($readStream, $writeStream);
            fclose($writeStream);
            fclose($readStream);

            $inputSize = filesize($tmpSource);
            $inputSizeMb = round($inputSize / 1024 / 1024, 2);
            if ($inputSizeMb < 10) {
                Log::channel('video')->info("Видео {$sourcePath} слишком маленькое ({$inputSizeMb} MB), сжатие пропущено");
                return $disk->size($sourcePath);
            }

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

            $outputSize = filesize($tmpTarget);
            $outputSizeMb = round($outputSize / 1024 / 1024, 2);

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

    public static function compressImage(string $sourcePath, ?string $targetPath = null, int $maxSizeKb = 500): bool|int
    {
        $disk = Storage::disk(config('lfm.disk'));
        $targetPath = $targetPath ?? 'compressed/' . basename($sourcePath);
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $tempFiles = [];

        try {
            if (!$disk->exists($sourcePath)) {
                throw new \Exception("Файл {$sourcePath} не найден в хранилище");
            }

            $tmpSource = tempnam(sys_get_temp_dir(), 'img_src_') . '.' . $ext;
            $tmpTarget = tempnam(sys_get_temp_dir(), 'img_out_') . '.' . $ext;
            $tempFiles = [$tmpSource, $tmpTarget];

            $readStream = $disk->readStream($sourcePath);
            if ($readStream === false) {
                throw new \Exception("Не удалось открыть поток для $sourcePath");
            }
            $writeStream = fopen($tmpSource, 'w');
            stream_copy_to_stream($readStream, $writeStream);
            fclose($writeStream);
            fclose($readStream);

            $inputSizeKb = (int) round(filesize($tmpSource) / 1024);
            if ($inputSizeKb <= $maxSizeKb) {
                Log::channel('video')->info("Изображение {$sourcePath} маленькое ({$inputSizeKb} KB), сжатие пропущено");
                return $disk->size($sourcePath);
            }

            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                case 'webp':
                    $quality = 90;
                    $compressed = false;
                    while ($quality > 30) {
                        $process = new Process([
                            'ffmpeg',
                            '-y',
                            '-i',
                            $tmpSource,
                            '-q:v',
                            (string) round((100 - $quality) / 5),
                            $tmpTarget
                        ]);
                        $process->mustRun();

                        $outputSizeKb = (int) round(filesize($tmpTarget) / 1024);
                        if ($outputSizeKb <= $maxSizeKb) {
                            $compressed = true;
                            break;
                        }
                        $quality -= 10;
                    }
                    if (!$compressed) {
                        copy($tmpSource, $tmpTarget);
                    }
                    break;

                case 'png':
                    $process = new Process([
                        'convert',
                        $tmpSource,
                        '-strip',
                        '-define',
                        'png:compression-level=9',
                        $tmpTarget
                    ]);
                    $process->mustRun();
                    break;

                default:
                    Log::channel('video')->info("Формат {$ext} не поддерживается для сжатия");
                    return $disk->size($sourcePath);
            }

            $disk->put($targetPath, fopen($tmpTarget, 'r'));

            $finalSizeKb = (int) round($disk->size($targetPath) / 1024);
            Log::channel('video')->info("Сжатие изображения {$sourcePath}: {$inputSizeKb} KB → {$finalSizeKb} KB");

            return $disk->size($targetPath);
        } catch (\Exception $e) {
            Log::channel('video')->error("Ошибка сжатия изображения {$sourcePath}: " . $e->getMessage());
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
