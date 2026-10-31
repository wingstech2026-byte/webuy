<?php

namespace App\Support;

use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Validation shared by the seller website and app for group-buy offers. */
final class GroupBuyOfferRules
{
    /** @return array<string, mixed> attributes for GroupBuyCampaign */
    public static function validate(Request $request, Seller $seller): array
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:120'],
            'group_price' => ['required', 'numeric', 'min:0.01'],
            'min_group_size' => ['required', 'integer', 'min:2', 'max:500'],
            'max_group_size' => ['nullable', 'integer', 'gte:min_group_size', 'max:1000'],
            'max_quantity_each' => ['required', 'integer', 'min:1', 'max:1000'],
            'hours' => ['required', 'integer', 'min:1', 'max:168'],
            'eligible_districts' => ['nullable', 'array'],
            'eligible_districts.*' => [Rule::in(config('wibuy.districts'))],
            'collection_point_id' => ['nullable', 'integer'],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ]);

        $product = $seller->products()->where('slug', $data['product'])->first();

        if (! $product) {
            throw ValidationException::withMessages(['product' => 'Choose one of your products.']);
        }

        // A group price must be a real saving on today's price (no invented discounts).
        if (Money::gte(Money::from($data['group_price']), $product->price)) {
            throw ValidationException::withMessages(['group_price' => 'The group price must be lower than the normal price ('.Money::format($product->price).').']);
        }

        if (isset($data['collection_point_id']) && ! $seller->locations()->whereKey($data['collection_point_id'])->where('pickup_enabled', true)->exists()) {
            throw ValidationException::withMessages(['collection_point_id' => 'Choose one of your pickup points.']);
        }

        return [
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'group_price' => Money::from($data['group_price']),
            'min_group_size' => $data['min_group_size'],
            'max_group_size' => $data['max_group_size'] ?? null,
            'max_quantity_each' => $data['max_quantity_each'],
            'hours' => $data['hours'],
            'eligible_districts' => $data['eligible_districts'] ?? null,
            'collection_point_id' => $data['collection_point_id'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => true,
        ];
    }
}
