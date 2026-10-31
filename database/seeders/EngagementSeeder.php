<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\FlashDeal;
use App\Models\Membership;
use App\Models\PriceCutCampaign;
use App\Models\Product;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Models\User;
use App\Services\CouponService;
use App\Services\FlashDealService;
use App\Services\PointsService;
use App\Services\RewardService;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Demo data for Part A: lightning deals today, price cuts, a free trial, a farm, points, coupons and a
 * WiBuy Pass member. Spin prizes, farm missions and farm prizes come from the migration.
 */
class EngagementSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::listed()->with('seller')->orderBy('id')->get();

        if ($products->isEmpty()) {
            return;
        }

        // Lightning deals: two running now, two later today.
        foreach ($products->take(4)->values() as $i => $product) {
            $start = $i < 2 ? now()->subMinutes(30) : now()->addHours(2 * $i);

            FlashDeal::firstOrCreate(['product_id' => $product->id, 'starts_at' => $start->copy()->startOfMinute()], [
                'deal_price' => Money::sub($product->price, Money::percentOf($product->price, 25)),
                'ends_at' => $start->copy()->startOfMinute()->addHours(2),
                'max_per_order' => 5,
                'quantity_limit' => 30,
            ]);
        }
        app(FlashDealService::class)->rotate(today()->addDay());

        // "Help me cut the price" on three products: friends cut Le 2-8 each down to 70% of the price.
        foreach ($products->slice(4, 3) as $product) {
            PriceCutCampaign::firstOrCreate(['product_id' => $product->id], [
                'floor_price' => Money::percentOf($product->price, 70),
                'hours' => 24,
                'min_cut' => '2.00',
                'max_cut' => '8.00',
                'is_active' => true,
            ]);
        }

        // A free trial with one applicant.
        $trialProduct = $products->get(7) ?? $products->last();
        $trial = TrialCampaign::firstOrCreate(['product_id' => $trialProduct->id], [
            'seller_id' => $trialProduct->seller_id,
            'trial_price' => '0.00',
            'slots' => 5,
            'apply_until' => now()->addDays(10),
        ]);

        $buyers = User::whereIn('phone', ['23276200001', '23276200002', '23276200003'])->get();
        $rewards = app(RewardService::class);

        foreach ($buyers as $buyer) {
            $rewards->ensureReferralCode($buyer);

            if (! $buyer->coupons()->exists()) {
                $rewards->grantWelcomePack($buyer);
            }
        }

        if ($first = $buyers->first()) {
            TrialApplication::firstOrCreate(['trial_campaign_id' => $trial->id, 'user_id' => $first->id], ['reason' => 'I want to try it before buying for my shop.']);

            Farm::firstOrCreate(['user_id' => $first->id], ['code' => 'demofarm', 'plant' => 'rice', 'water' => 180, 'target' => 300]);

            if ($first->points_balance === 0) {
                app(PointsService::class)->award($first, 650, 'Demo points');
                app(CouponService::class)->issue($first, ['title' => 'Spin prize', 'source' => 'spin', 'amount' => '10.00', 'min_spend' => '100.00', 'expires_at' => now()->addWeek()]);
            }
        }

        // One WiBuy Pass member.
        if (($member = $buyers->get(1)) && ! $member->hasPass()) {
            Membership::create([
                'user_id' => $member->id, 'price' => '25.00', 'status' => 'active', 'payer_phone' => $member->phone,
                'operator' => 'ORANGE', 'starts_at' => now()->subDays(3), 'ends_at' => now()->addDays(27),
            ]);
        }

        // Member prices on a few products (10% under the normal price).
        $products->take(6)->each(fn (Product $p) => $p->member_price ?? $p->update(['member_price' => Money::sub($p->price, Money::percentOf($p->price, 10))]));
    }
}
