<?php

namespace App\Services;

use App\Enums\Operator;
use App\Exceptions\MarketplaceException;
use App\Models\Membership;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\RewardSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WiBuy Pass: one month at a time, paid by mobile money. Free delivery on eligible shop orders,
 * member prices and extra spins. An SMS reminds people before it ends; nothing is ever charged
 * automatically.
 */
class MembershipService
{
    public function __construct(private PaymentService $payments, private SmsService $sms) {}

    /** @throws MarketplaceException */
    public function buy(User $user, Operator $operator, string $payerPhone): Membership
    {
        if (Membership::where('user_id', $user->id)->where('status', 'pending')->where('created_at', '>', now()->subMinutes(15))->exists()) {
            throw new MarketplaceException('A WiBuy Pass payment is already waiting. Approve it on your phone or try again in a few minutes.');
        }

        return DB::transaction(function () use ($user, $operator, $payerPhone) {
            $membership = Membership::create([
                'user_id' => $user->id,
                'price' => RewardSettings::money('pass_price'),
                'status' => 'pending',
                'payer_phone' => $payerPhone,
                'operator' => $operator,
            ]);

            $this->payments->createMembershipPayment($membership);

            return $membership;
        });
    }

    /** The payment finished. A new month starts now, or when the current one ends. Called inside the payment lock. */
    public function settle(Membership $membership, bool $paid): void
    {
        if ($membership->status !== 'pending') {
            return;
        }

        if (! $paid) {
            $membership->forceFill(['status' => 'failed'])->save();

            return;
        }

        $current = Membership::where('user_id', $membership->user_id)->where('status', 'active')
            ->where('ends_at', '>', now())->max('ends_at');
        $starts = $current ? Carbon::parse($current) : now();

        $membership->forceFill([
            'status' => 'active',
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addDays(RewardSettings::int('pass_days')),
        ])->save();

        $this->sms->send($membership->user->phone, SmsMessages::passStarted($membership));
    }

    /** SMS people whose pass ends soon (and who have not renewed). */
    public function remindExpiring(): int
    {
        $soon = now()->addDays(RewardSettings::int('pass_reminder_days'));

        $memberships = Membership::where('status', 'active')->whereNull('reminded_at')
            ->whereBetween('ends_at', [now(), $soon])->with('user')->get()
            ->reject(fn (Membership $m) => Membership::where('user_id', $m->user_id)->where('status', 'active')->where('ends_at', '>', $m->ends_at)->exists());

        foreach ($memberships as $membership) {
            $membership->forceFill(['reminded_at' => now()])->save();
            $this->sms->send($membership->user->phone, SmsMessages::passEnding($membership));
        }

        return $memberships->count();
    }
}
