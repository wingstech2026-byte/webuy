<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Image;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Seller;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(private ImageService $images) {}

    /**
     * Create or update a product with its price tiers and pictures.
     *
     * @param  array<string, mixed>  $data  validated product fields
     * @param  array<int, array{min_quantity: int|string, unit_price: string}>  $tiers  replaces the existing tiers
     * @param  array<int, UploadedFile>  $newImages
     * @param  array<int, int>  $removeImageIds
     *
     * @throws MarketplaceException
     */
    public function save(Seller $seller, array $data, array $tiers = [], array $newImages = [], array $removeImageIds = [], ?Product $product = null): Product
    {
        return DB::transaction(function () use ($seller, $data, $tiers, $newImages, $removeImageIds, $product) {
            $isNew = $product === null;
            $product ??= new Product(['seller_id' => $seller->id]);

            // Sellers choose draft or live. Only an admin can suspend or un-suspend.
            if (! $isNew && $product->status === ProductStatus::Suspended) {
                unset($data['status']);
            }

            $product->fill($data);

            if ($isNew) {
                $product->slug = Slug::unique(Product::class, $data['title']);
            }

            $product->save();

            $this->syncTiers($product, $tiers);
            $this->syncImages($product, $seller, $newImages, $removeImageIds);

            return $product->fresh(['tiers', 'images']);
        });
    }

    /** @param array<int, array{min_quantity: int|string, unit_price: string}> $tiers */
    private function syncTiers(Product $product, array $tiers): void
    {
        $product->tiers()->delete();

        foreach ($tiers as $tier) {
            PriceTier::create([
                'product_id' => $product->id,
                'min_quantity' => (int) $tier['min_quantity'],
                'unit_price' => $tier['unit_price'],
                'business_only' => (bool) ($tier['business_only'] ?? false),
            ]);
        }
    }

    /** @throws MarketplaceException */
    private function syncImages(Product $product, Seller $seller, array $newImages, array $removeImageIds): void
    {
        if ($removeImageIds) {
            $product->images()->whereIn('id', $removeImageIds)->get()->each(fn (Image $image) => $this->images->delete($image));
        }

        $kept = $product->images()->count();

        if ($kept + count($newImages) > config('wibuy.max_product_images')) {
            throw new MarketplaceException('A product can have up to '.config('wibuy.max_product_images').' pictures.');
        }

        $position = (int) $product->images()->max('position') + 1;

        foreach ($newImages as $file) {
            $this->images->attach($product, $file, 'gallery', $seller->user_id, $position++);
        }
    }

    public function delete(Product $product): void
    {
        $product->images()->get()->each(fn (Image $image) => $this->images->delete($image));
        $product->delete();
    }
}
