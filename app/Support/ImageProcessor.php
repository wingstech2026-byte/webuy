<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shrinks uploaded pictures for slow, expensive mobile data. The upload is decoded and re-encoded
 * (so it must really be an image), rotated upright using EXIF, scaled so the longest side fits,
 * and saved as JPEG. Re-encoding also strips EXIF data such as GPS location from the stored copy.
 */
class ImageProcessor
{
    /**
     * @return array{path: string, thumb: string} paths on $disk
     *
     * @throws RuntimeException when the file is not a usable image
     */
    public function process(
        string $absolutePath,
        string $disk,
        string $directory,
        int $maxSide,
        int $thumbSide,
        int $quality = 72,
        int $thumbQuality = 68,
    ): array {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The PHP GD extension is required for images.');
        }

        $raw = @file_get_contents($absolutePath);
        $info = $raw === false ? false : @getimagesizefromstring($raw);

        if ($info === false || $info[0] < 1 || $info[1] < 1 || ($info[0] * $info[1]) > config('wibuy.images.max_pixels')) {
            throw new RuntimeException('That image is not valid or is too large.');
        }

        $source = @imagecreatefromstring($raw);

        if ($source === false) {
            throw new RuntimeException('That image could not be read.');
        }

        $source = $this->upright($source, $absolutePath, $info[2] ?? null);

        $name = (string) Str::uuid();
        $directory = trim($directory, '/').'/'.now()->format('Y/m');
        $storage = Storage::disk($disk);

        // No per-file visibility: the disk decides (Cloud buckets reject per-file ACLs).
        $storage->put("{$directory}/{$name}.jpg", $this->jpeg($this->fit($source, $maxSide), $quality));
        $storage->put("{$directory}/{$name}-thumb.jpg", $this->jpeg($this->fit($source, $thumbSide), $thumbQuality));

        return ['path' => "{$directory}/{$name}.jpg", 'thumb' => "{$directory}/{$name}-thumb.jpg"];
    }

    /** Scale down so the longest side is at most $max. Never scales up. */
    private function fit(\GdImage $image, int $max): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($nw, $nh);
        // JPEG has no transparency: put PNG/WebP transparency on white.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $canvas;
    }

    private function jpeg(\GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** Phone cameras store rotation in EXIF. Apply it so photos are not sideways. */
    private function upright(\GdImage $image, string $path, ?int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
