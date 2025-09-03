<?php

namespace App\Services;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use UniSharp\LaravelFilemanager\LfmPath;
use Symfony\Component\HttpFoundation\Response;

class UserFilesService
{
    public static function checkFolderSize($file)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json([
                'error' => 'Unauthorized',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $incoming = $file->getSize() ?? 0;
        if ($incoming <= 0) {
            throw new Exception('File size must be greater than zero.');
        }

        $folder = static::userFolderPath();
        $current = static::calculateFolderSize($folder);

        $maxSize = static::getMaxSize();

        if ($current + $incoming > $maxSize) {
            $maxSizeMb = floor($maxSize / 1024 / 1024);
            $leftMb = floor(max(0, $maxSize - $current) / 1024 / 1024); // Convert to MB
            throw new Exception(
                "Размер папки пользователя превышен. Вы можете загрузить до {$maxSizeMb} мб, у вас осталось только {$leftMb} мб."
            );
            // TODO Ошибка не выводиться
        }
    }

    public static function getMaxSize(): int
    {
        return 1024 * 1024 * 1000;
    }

    public static function userFolderPath(): string
    {
        return app(LfmPath::class)->path();
    }

    public static function sizeToString(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' Кб';
        } elseif ($bytes < 1073741824) {
            return round($bytes / 1048576, 2) . ' Мб';
        } else {
            return round($bytes / 1073741824, 2) . ' Гб';
        }
    }

    public static function calculateFolderSize(string $folder): int
    {
        $size = 0;
        if (!Storage::disk('s3')->exists($folder)) {
            return 0;
        }
        foreach (Storage::disk('s3')->allFiles($folder) as $file) {
            try {
                $size += Storage::disk('s3')->size($file);
            } catch (\Throwable $e) {
                // на всякий случай — пропускаем файлы, которые не удалось прочитать
            }
        }
        return $size;
    }
}
