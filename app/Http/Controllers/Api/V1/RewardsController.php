<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CouponResource;
use App\Http\Resources\Api\V1\FarmResource;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Http\Resources\Api\V1\PriceCutResource;
use App\Models\Coupon;
use App\Models\PointTransaction;
use App\Models\PriceCut;
use App\Services\CheckInService;
use App\Services\CouponService;
use App\Services\FarmService;
use App\Services\PointsService;
use App\Services\RewardService;
use App\Services\ShareService;
use App\Services\SpinService;
use App\Support\RewardSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The app's Rewards tab: points, check-in, spins, farm, price cuts, coupons and WiBuy Pass in one place. */
class RewardsController extends Controller
{
    public function hub(Request $request, CheckInService $checkIns, SpinService $spins, FarmService $farms, CouponService $coupons): JsonResponse
    {
        $user = $request->user();
        $farm = $user->farm;
        $pass = $user->activeMembership();

        return response()->json([
            'points' => $user->points_balance,
            'points_per_coupon' => RewardSettings::int('points_per_coupon'),
            'points_coupon_amount' => RewardSettings::money('points_coupon_amount'),
            'check_in' => [
                'done_today' => $checkIns->today($user) !== null,
                'streak' => $checkIns->currentStreak($user),
            ],
            'spins' => $spins->allowance($user),
            'farm' => $farm ? (new FarmResource($farm))->resolve($request) : null,
            'farm_missions' => $farms->missionsFor($user),
            'price_cuts' => PriceCutResource::collection(
                PriceCut::where('user_id', $user->id)->where('expires_at', '>', now()->subDay())
                    ->with('campaign.product.seller', 'campaign.product.images', 'helpers.helper', 'user')->latest('id')->limit(10)->get()
            )->resolve($request),
            'coupons' => CouponResource::collection($coupons->usable($user, 'app'))->resolve($request),
            'locked_coupons' => CouponResource::collection(Coupon::where('user_id', $user->id)->where('status', Coupon::LOCKED)->get())->resolve($request),
            'pass' => $pass ? (new MembershipResource($pass))->resolve($request) : null,
            'referral_code' => app(RewardService::class)->ensureReferralCode($user)->referral_code,
        ]);
    }

    public function checkIn(Request $request, CheckInService $checkIns): JsonResponse
    {
        $checkIn = $checkIns->checkIn($request->user());

        return response()->json([
            'message' => "Checked in! +{$checkIn->points} points (day {$checkIn->streak}).",
            'points_earned' => $checkIn->points,
            'streak' => $checkIn->streak,
            'points' => $request->user()->refresh()->points_balance,
        ]);
    }

    public function coupons(Request $request): AnonymousResourceCollection
    {
        return CouponResource::collection(
            Coupon::where('user_id', $request->user()->id)->with('product')->latest('id')->limit(100)->get()
        );
    }

    public function points(Request $request): JsonResponse
    {
        return response()->json([
            'points' => $request->user()->points_balance,
            'history' => PointTransaction::where('user_id', $request->user()->id)->latest('id')->limit(50)->get(['points', 'reason', 'created_at']),
        ]);
    }

    public function exchange(Request $request, PointsService $points): CouponResource
    {
        return new CouponResource($points->exchangeForCoupon($request->user()));
    }

    /** Make a link to share on WhatsApp. It counts once someone else opens it. */
    public function share(Request $request, ShareService $shares): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ShareService::KINDS)],
            'target' => ['nullable', 'string', 'max:120'],
        ]);

        $share = $shares->create($request->user(), $data['kind'], $data['target'] ?? null);

        $text = match ($share->kind) {
            'price_cut' => 'Help me cut the price on WiBuy! Tap to cut:',
            'farm' => 'Water my plant on WiBuy Farm:',
            'product' => 'Look at this on WiBuy:',
            default => 'Join me on WiBuy. '.config('wibuy.tagline'),
        };

        return response()->json([
            'url' => $share->url,
            'text' => $text.' '.$share->url,
            'whatsapp_url' => 'https://wa.me/?text='.rawurlencode($text.' '.$share->url),
        ], 201);
    }
}
