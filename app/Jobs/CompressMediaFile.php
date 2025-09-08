<?php

namespace App\Jobs;

use App\Models\MediaFile;
use App\Services\MediaCompressor;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class CompressMediaFile implements ShouldQueue
{
    use Queueable;

    public $mediaFileId;

    /**
     * Create a new job instance.
     */
    public function __construct($id)
    {
        $this->mediaFileId = $id;
        $this->onQueue('encoder');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $mediaFile = MediaFile::find($this->mediaFileId);

        if (!$mediaFile || $mediaFile->is_processed) {
            return;
        }

        $result = MediaCompressor::compress($mediaFile->path, $mediaFile->path);

        if ($result === false) {
            echo "Ошибка при сжатии";
        } else {
            echo "Сжато и сохранено: " . $result;
            $mediaFile->update(['is_processed' => true, 'real_size' => $result]);
        }
    }
}
