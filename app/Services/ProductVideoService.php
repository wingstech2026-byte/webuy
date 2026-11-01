<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Image;
use App\Models\Product;
use App\Support\VideoLength;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A short video of a product, made by the seller with their phone (MP4 or MOV, up to a minute). Stored on the
 * same public disk as product photos. Pages never load it until the buyer presses play.
 */
class ProductVideoService
{
    public const MAX_SECONDS = 60;

    public const MAX_KB = 30720; // 30 MB

    /** @throws MarketplaceException */
    public function attach(Product $product, UploadedFile $video): Product
    {
        $seconds = VideoLength::seconds($video->getRealPath());

        if ($seconds === null) {
            throw new MarketplaceException('That video could not be read. Record it with your phone camera (MP4 or MOV) and try again.');
        }

        if ($seconds > self::MAX_SECONDS + 0.5) {
            throw new MarketplaceException('The video is '.(int) round($seconds).' seconds long. Please keep it to '.self::MAX_SECONDS.' seconds or less.');
        }

        // Saved with our own ending (never the uploaded name), so it is always served as a video.
        $extension = strtolower($video->getClientOriginalExtension()) === 'mov' ? 'mov' : 'mp4';
        $path = 'videos/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk(Image::DISK)->putFileAs(dirname($path), $video, basename($path));

        $old = $product->video_path;
        $product->forceFill(['video_path' => $path, 'video_seconds' => max(1, (int) round($seconds))])->save();

        if ($old) {
            Storage::disk(Image::DISK)->delete($old);
        }

        return $product;
    }

    public function remove(Product $product): Product
    {
        if ($product->video_path) {
            Storage::disk(Image::DISK)->delete($product->video_path);
            $product->forceFill(['video_path' => null, 'video_seconds' => null])->save();
        }

        return $product;
    }
}
