<?php

namespace App\Jobs;

use App\Models\Image;
use App\Services\ImageService;
use App\Support\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Turns a private upload into a web-ready picture (max 1080px) and a thumbnail. */
class ProcessImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $imageId)
    {
        $this->afterCommit();
    }

    public function handle(ImageProcessor $processor): void
    {
        $image = Image::find($this->imageId);

        if (! $image || $image->status === 'ready' || ! $image->original_path) {
            return;
        }

        // The upload is on the default disk (a bucket in production): work on a temporary copy.
        $uploads = Storage::disk();
        $contents = $uploads->get($image->original_path);

        if ($contents === null) {
            $this->markFailed($image);

            return;
        }

        $sizes = ImageService::sizes($image->collection);
        $temporary = tempnam(sys_get_temp_dir(), 'wibuy-img');
        file_put_contents($temporary, $contents);

        try {
            $stored = $processor->process(
                $temporary,
                Image::DISK,
                'images/'.$image->collection,
                $sizes['max'],
                $sizes['thumb'],
                config('wibuy.images.quality'),
                config('wibuy.images.thumb_quality'),
            );
        } catch (Throwable $e) {
            $this->markFailed($image);
            report($e);

            return;
        } finally {
            @unlink($temporary);
        }

        $uploads->delete($image->original_path);

        $image->forceFill([
            'path' => $stored['path'],
            'thumb_path' => $stored['thumb'],
            'original_path' => null,
            'status' => 'ready',
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        if ($image = Image::find($this->imageId)) {
            $this->markFailed($image);
        }
    }

    private function markFailed(Image $image): void
    {
        if ($image->original_path) {
            Storage::disk()->delete($image->original_path);
        }

        $image->forceFill(['status' => 'failed', 'original_path' => null])->save();
    }
}
