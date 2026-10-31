<?php

namespace Database\Seeders;

use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerLocation;
use App\Support\Money;
use Illuminate\Database\Seeder;

/** Demo data for maps and group buying: shop pins around Freetown, one pickup point and two group offers. */
class GeoGroupSeeder extends Seeder
{
    /** Demo points in Freetown: [name, area, latitude, longitude]. */
    private const PLACES = [
        ['Central shop', 'Siaka Stevens Street', 8.4844, -13.2299],
        ['Kissy shop', 'Kissy Road', 8.4746, -13.1985],
        ['Lumley shop', 'Lumley Beach Road', 8.4433, -13.2720],
        ['Wellington warehouse', 'Wellington Industrial Estate', 8.4565, -13.1640],
    ];

    public function run(): void
    {
        $sellers = Seller::approved()->orderBy('id')->get();

        foreach ($sellers->values() as $i => $seller) {
            [$name, $area, $lat, $lng] = self::PLACES[$i % count(self::PLACES)];

            SellerLocation::firstOrCreate(['seller_id' => $seller->id, 'public_name' => "{$seller->shop_name} ({$name})"], [
                'type' => str_contains($name, 'warehouse') ? 'warehouse' : 'shop',
                'public_address' => $area, 'district' => 'Western Area Urban', 'town' => 'Freetown',
                'latitude' => $lat, 'longitude' => $lng, 'delivery_enabled' => true, 'delivery_radius_km' => 8,
                'base_fee' => '10.00', 'per_km_fee' => '2.00', 'pickup_enabled' => $i === 0,
                'pickup_instructions' => $i === 0 ? 'Open 8am to 6pm. Show your order number at the counter.' : null,
                'delivery_window' => 'Same day if ordered before 12pm', 'is_active' => true,
            ]);
        }

        $products = Product::listed()->with('seller')->orderBy('id')->limit(2)->get();

        foreach ($products as $product) {
            GroupBuyCampaign::firstOrCreate(['product_id' => $product->id], [
                'seller_id' => $product->seller_id,
                // A real 10% saving on today's price.
                'group_price' => Money::sub($product->price, Money::percentOf($product->price, 10)),
                'min_group_size' => 3, 'max_quantity_each' => 5, 'hours' => 24, 'is_active' => true,
                'collection_point_id' => $product->seller->locations()->where('pickup_enabled', true)->value('id'),
            ]);
        }
    }
}
