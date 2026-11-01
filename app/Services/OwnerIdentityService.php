<?php

namespace App\Services;

use App\Enums\IdentityDocument;
use App\Enums\SellerStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Seller;
use App\Support\ImageProcessor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The shop owner's identity document (Sierra Leoneans: national ID card or passport; others: passport, residence
 * permit, their own national ID card or another official ID): the number and a photo (front or passport page, back optional).
 * The photos are re-saved as plain JPEGs (no hidden GPS or camera data) on the PRIVATE default disk, never on
 * the public one; admins open them through a checked admin page. The number is encrypted in the database.
 */
class OwnerIdentityService
{
    public function __construct(private ImageProcessor $images) {}

    /** "SL 1234-5678" and "sl12345678" are the same card. */
    public static function normalize(string $number): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $number));
    }

    /** @throws MarketplaceException */
    public function store(Seller $seller, string $number, IdentityDocument $type, string $nationality, UploadedFile $front, ?UploadedFile $back = null): Seller
    {
        if ($seller->status === SellerStatus::Approved && $seller->hasOwnerIdentity()) {
            throw new MarketplaceException('Your ID is already checked. Contact WiBuy support to change it.');
        }

        if (! in_array($type, IdentityDocument::allowedFor($nationality), true)) {
            throw new MarketplaceException('Sierra Leoneans send their national ID card or passport.');
        }

        $number = self::normalize($number);
        $old = array_filter([$seller->owner_id_front_path, $seller->owner_id_back_path]);

        $seller->forceFill([
            'owner_id_number' => $number,
            'owner_id_number_hash' => Seller::idFingerprint($number),
            'owner_id_type' => $type->value,
            'owner_nationality' => $nationality,
            'owner_id_front_path' => $this->savePhoto("identity/{$seller->id}", $front),
            'owner_id_back_path' => $back ? $this->savePhoto("identity/{$seller->id}", $back) : null,
            'owner_id_submitted_at' => now(),
        ])->save();

        Storage::disk()->delete($old);

        return $seller;
    }

    /** Other shops that sent the same ID number (one person, several shops, or a borrowed card). */
    public function sameIdElsewhere(Seller $seller): Collection
    {
        return $seller->owner_id_number_hash
            ? Seller::where('owner_id_number_hash', $seller->owner_id_number_hash)->whereKeyNot($seller->id)->get(['id', 'shop_name', 'slug', 'status'])
            : new Collection;
    }

    /**
     * A photo of an identity document, re-saved as a plain JPEG on the private disk. Shared with buyer IDs.
     *
     * @throws MarketplaceException
     */
    public function savePhoto(string $folder, UploadedFile $photo): string
    {
        try {
            $jpeg = $this->images->shrink($photo->getRealPath(), 1600, 82);
        } catch (RuntimeException) {
            throw new MarketplaceException('That ID photo could not be read. Please take a clear photo of the card.');
        }

        $path = trim($folder, '/').'/'.Str::uuid().'.jpg';
        Storage::disk()->put($path, $jpeg); // default disk = private (never Storage::disk('public'))

        return $path;
    }
}
