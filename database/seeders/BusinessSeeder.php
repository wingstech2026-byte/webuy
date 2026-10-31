<?php

namespace Database\Seeders;

use App\Models\BusinessProfile;
use App\Models\ImportProduct;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Models\Seller;
use App\Models\User;
use App\Services\ReliabilityService;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Demo data for Part B: a verified business buyer, business-only prices, lead times and samples, an RFQ
 * with two quotes, an inspection agent, WiBuy Global products and reliability scores.
 */
class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::listed()->with('seller', 'tiers')->orderBy('id')->get();

        if ($products->isEmpty()) {
            return;
        }

        // Buyer 076 200 003 runs a verified business.
        if ($business = User::where('phone', '23276200003')->first()) {
            BusinessProfile::updateOrCreate(['user_id' => $business->id], [
                'business_name' => 'Conteh Building Supplies', 'registration_no' => 'SL-BRN-2024-0117',
                'district' => 'Bo', 'address' => 'Dambara Road, Bo', 'status' => 'verified', 'verified_at' => now(),
            ]);
        }

        // Business-only wholesale tiers, made-to-order lead times and samples on a few products.
        foreach ($products->take(5) as $product) {
            if (! $product->tiers->contains('business_only', true)) {
                PriceTier::firstOrCreate(
                    ['product_id' => $product->id, 'min_quantity' => max(50, ($product->tiers->max('min_quantity') ?? 0) + 10)],
                    ['unit_price' => Money::percentOf($product->price, 80), 'business_only' => true],
                );
            }
        }
        $products->slice(5, 3)->each(fn (Product $p) => $p->update(['lead_time_days' => 7, 'sample_price' => Money::percentOf($p->price, 120)]));

        // An open request for quotes with two quotes.
        if ($business) {
            $rfq = Rfq::firstOrCreate(['buyer_id' => $business->id, 'title' => '50 bags of cement'], [
                'description' => 'For a building site in Bo. Delivery next week.', 'quantity' => 50, 'unit' => 'bag',
                'district' => 'Bo', 'target_price' => '600.00', 'needed_by' => today()->addWeek(), 'status' => 'open',
            ]);

            foreach (Seller::approved()->with('products')->take(2)->get() as $i => $seller) {
                if ($product = $seller->products->first()) {
                    RfqQuote::firstOrCreate(['rfq_id' => $rfq->id, 'seller_id' => $seller->id], [
                        'product_id' => $product->id, 'unit_price' => $i === 0 ? '580.00' : '560.00', 'quantity' => 50,
                        'lead_time_days' => $i === 0 ? 2 : 5, 'note' => $i === 0 ? 'Leocem, delivered in 2 days.' : 'Price includes loading.',
                        'valid_until' => now()->addDays(14),
                    ]);
                }
            }
        }

        // An inspection agent (log in as 076 900 001).
        User::updateOrCreate(['phone' => '23276900001'], ['name' => 'Ibrahim Sesay (WiBuy agent)'])->forceFill(['is_agent' => true])->save();

        // WiBuy Global products.
        ImportProduct::firstOrCreate(['title' => 'Solar panel 300W (monocrystalline)'], [
            'description' => 'Factory in Guangzhou. Landed price in Freetown including shipping and duties.',
            'unit' => 'panel', 'min_quantity' => 10, 'sea_price' => '1450.00', 'sea_days' => 45, 'air_price' => '1900.00', 'air_days' => 12, 'deposit_percent' => 30,
        ]);
        ImportProduct::firstOrCreate(['title' => 'Motorbike tyres 2.75-17'], [
            'unit' => 'tyre', 'min_quantity' => 20, 'sea_price' => '210.00', 'sea_days' => 40, 'deposit_percent' => 40,
        ]);

        app(ReliabilityService::class)->refreshAll();
    }
}
