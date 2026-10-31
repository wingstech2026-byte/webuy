<?php

namespace App\Services;

use App\Models\Share;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links people share on WhatsApp. A share is successful when someone else (another account and
 * another device) opens it. Successful shares give extra spins and WiBuy Farm water.
 */
class ShareService
{
    public const KINDS = ['app', 'product', 'price_cut', 'farm'];

    public function __construct(private FarmService $farms) {}

    public function create(User $user, string $kind, ?string $target = null): Share
    {
        $user = app(RewardService::class)->ensureReferralCode($user);

        return Share::create([
            'user_id' => $user->id,
            'token' => Str::lower(Str::random(10)),
            'kind' => $kind,
            'target' => $target,
        ]);
    }

    /** Where the link leads, with the sharer's referral code so a new sign-up is credited to them. */
    public function destination(Share $share): string
    {
        $ref = ['ref' => $share->user->referral_code];

        return match ($share->kind) {
            'product' => route('products.show', ['product' => $share->target] + $ref),
            'price_cut' => route('price-cuts.show', ['priceCut' => $share->target] + $ref),
            'farm' => route('farm.friend', ['code' => $share->target] + $ref),
            default => route('home', $ref),
        };
    }

    /**
     * Someone opened the link. Returns true when this was the first successful open (rewarded).
     */
    public function open(Share $share, ?User $viewer, ?string $deviceHash): bool
    {
        if ($viewer?->id === $share->user_id) {
            return false;
        }

        if ($deviceHash && UserDevice::where('device_hash', $deviceHash)->where('user_id', $share->user_id)->exists()) {
            return false; // the sharer opening their own link on another account or browser
        }

        $rewarded = DB::transaction(function () use ($share, $viewer, $deviceHash) {
            $locked = Share::lockForUpdate()->find($share->id);

            if ($locked->opened_at) {
                return false;
            }

            $locked->forceFill([
                'opened_at' => now(),
                'opened_by_id' => $viewer?->id,
                'opened_device_hash' => $deviceHash,
                'rewarded_at' => now(),
            ])->save();

            return true;
        });

        if ($rewarded) {
            $this->farms->mission($share->user, 'share');
        }

        return $rewarded;
    }

    /** Successful shares today, for extra spins. */
    public function successfulToday(User $user): int
    {
        return Share::where('user_id', $user->id)->whereNotNull('rewarded_at')
            ->where('rewarded_at', '>=', today())->count();
    }
}
