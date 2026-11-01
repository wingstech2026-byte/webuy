<?php

namespace App\Services;

use App\Enums\Operator;
use App\Enums\PayoutStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Payout;
use App\Models\Seller;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Seller and rider payouts. Asking for a payout takes the money off the balance straight away (so it cannot be
 * asked for twice); an admin approves it and WiBuy sends it with pawaPay; if it is rejected or fails
 * the money goes back on the balance.
 */
class PayoutService
{
    public function __construct(
        private LedgerService $ledger,
        private PaymentService $payments,
        private SmsService $sms,
    ) {}

    /** @throws MarketplaceException */
    public function request(Seller $seller, string $amount): Payout
    {
        return DB::transaction(function () use ($seller, $amount) {
            // Lock the seller so two quick requests cannot both spend the same balance.
            $seller = Seller::lockForUpdate()->findOrFail($seller->id);

            if (! $seller->isApproved()) {
                throw new MarketplaceException('Your shop is not approved to receive payouts.');
            }

            $amount = Money::from($amount);
            $min = SettingsService::minPayout();

            if (Money::cmp($amount, $min) < 0) {
                throw new MarketplaceException('The smallest payout is '.Money::format($min).'.');
            }

            $available = $this->ledger->available($seller);

            if (Money::gt($amount, $available)) {
                throw new MarketplaceException('You only have '.Money::format($available).' available.');
            }

            $payout = Payout::create([
                'seller_id' => $seller->id,
                'amount' => $amount,
                'status' => PayoutStatus::Requested,
                'operator' => $seller->payout_operator,
                'phone' => $seller->payout_phone,
            ]);

            $this->ledger->debitPayout($payout);

            return $payout;
        });
    }

    /**
     * A rider withdraws delivery fees to the mobile money number they choose. Someone who is no longer
     * a rider can still withdraw what they earned.
     *
     * @throws MarketplaceException
     */
    public function requestForRider(User $rider, string $amount, Operator $operator, string $phone): Payout
    {
        return DB::transaction(function () use ($rider, $amount, $operator, $phone) {
            // Lock the rider so two quick requests cannot both spend the same balance.
            $rider = User::lockForUpdate()->findOrFail($rider->id);

            $amount = Money::from($amount);
            $min = SettingsService::minPayout();

            if (Money::cmp($amount, $min) < 0) {
                throw new MarketplaceException('The smallest payout is '.Money::format($min).'.');
            }

            $available = $this->ledger->riderAvailable($rider);

            if (Money::gt($amount, $available)) {
                throw new MarketplaceException('You only have '.Money::format($available).' available.');
            }

            $payout = Payout::create([
                'rider_id' => $rider->id,
                'amount' => $amount,
                'status' => PayoutStatus::Requested,
                'operator' => $operator,
                'phone' => $phone,
            ]);

            $this->ledger->debitPayout($payout);

            return $payout;
        });
    }

    /** Admin approves: WiBuy sends the money. */
    public function approve(Payout $payout, User $admin): Payout
    {
        return DB::transaction(function () use ($payout, $admin) {
            $payout = Payout::lockForUpdate()->findOrFail($payout->id);

            if ($payout->status !== PayoutStatus::Requested) {
                throw new MarketplaceException("This payout is already \"{$payout->status->label()}\".");
            }

            $payout->forceFill([
                'status' => PayoutStatus::Processing,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->payments->createPayout($payout);

            return $payout;
        });
    }

    /** Admin says no: the money goes back on the seller's balance. */
    public function reject(Payout $payout, User $admin, ?string $reason = null): Payout
    {
        return DB::transaction(function () use ($payout, $admin, $reason) {
            $payout = Payout::lockForUpdate()->findOrFail($payout->id);

            if ($payout->status !== PayoutStatus::Requested) {
                throw new MarketplaceException("This payout is already \"{$payout->status->label()}\".");
            }

            $payout->forceFill([
                'status' => PayoutStatus::Rejected,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->ledger->reversePayout($payout, 'was rejected');
            $this->sms->send($payout->recipient()->phone, SmsMessages::payoutRejected($payout));

            return $payout;
        });
    }

    /** pawaPay confirmed the money was sent. Called by PaymentService with the payout locked. */
    public function markPaid(Payout $payout): void
    {
        if ($payout->status === PayoutStatus::Paid) {
            return;
        }

        $payout->forceFill(['status' => PayoutStatus::Paid, 'completed_at' => now()])->save();
        $this->sms->send($payout->recipient()->phone, SmsMessages::payoutSent($payout));
    }

    /** pawaPay could not send it: the money goes back on the balance so the seller can ask again. */
    public function markFailed(Payout $payout): void
    {
        if ($payout->status !== PayoutStatus::Processing) {
            return;
        }

        $payout->forceFill(['status' => PayoutStatus::Failed])->save();
        $this->ledger->reversePayout($payout, 'failed');
    }
}
