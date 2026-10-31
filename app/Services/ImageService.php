<?php

namespace App\Services;

use App\Jobs\ProcessImageJob;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures are accepted fast (stored privately, row marked "processing") and resized on the
 * queue by ProcessImageJob, so a slow phone connection is never waiting on image processing.
 */
class ImageService
{
    /** Max longest side and thumbnail side per collection. */
    public static function sizes(string $collection): array
    {
        return match ($collection) {
            'logo' => ['max' => 512, 'thumb' => 160],
            default => ['max' => config('wibuy.images.max_side'), 'thumb' => config('wibuy.images.thumb_side')],
        };
    }

    public function attach(Model $owner, UploadedFile $file, string $collection, ?int $userId = null, int $position = 0): Image
    {
        // A random name and a fixed extension: the original is never served and never trusted.
        // It goes to the default (private) disk, which the queue worker can read too.
        $original = $file->storeAs('uploads', Str::uuid().'.upload');

        $image = Image::create([
            'imageable_type' => $owner->getMorphClass(),
            'imageable_id' => $owner->getKey(),
            'collection' => $collection,
            'original_path' => $original,
            'position' => $position,
            'status' => 'processing',
            'user_id' => $userId,
        ]);

        ProcessImageJob::dispatch($image->id);

        return $image;
    }

    /** Remove a picture and its files. */
    public function delete(Image $image): void
    {
        foreach ([$image->path, $image->thumb_path] as $path) {
            if ($path) {
                Storage::disk(Image::DISK)->delete($path);
            }
        }

        if ($image->original_path) {
            Storage::disk()->delete($image->original_path);
        }

        $image->delete();
    }

    /** Replace a single-picture slot (the shop logo). */
    public function replace(Model $owner, UploadedFile $file, string $collection, ?int $userId = null): Image
    {
        $old = Image::where('imageable_type', $owner->getMorphClass())
            ->where('imageable_id', $owner->getKey())->where('collection', $collection)->get();

        $new = $this->attach($owner, $file, $collection, $userId);

        $old->each(fn (Image $image) => $this->delete($image));

        return $new;
    }
}
