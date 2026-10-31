<?php

namespace App\Services;

use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Image;
use App\Models\Inspection;
use App\Models\Order;
use App\Models\OrderMilestone;
use App\Models\Payment;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Http\UploadedFile;

/**
 * Big orders paid in parts, and the optional pre-delivery inspection: a WiBuy agent checks the goods at
 * the seller before the buyer pays the last part. If the seller does not deliver (or the goods fail),
 * the buyer reports a problem and the dispute process refunds what was paid.
 */
class MilestoneService
{
    public function __construct(private ChargeService $charges, private ImageService $images, private SmsService $sms) {}

    /** @throws MarketplaceException */
    public function pay(User $buyer, OrderMilestone $milestone, Operator $operator, string $phone): Payment
    {
        $order = $milestone->order;

        if ($order->buyer_id !== $buyer->id) {
            throw new MarketplaceException('This is not your order.');
        }

        if ($milestone->isPaid()) {
            throw new MarketplaceException('This part is already paid.');
        }

        if ($order->milestones()->where('position', '<', $milestone->position)->where('status', 'pending')->exists()) {
            throw new MarketplaceException('Pay the earlier part first.');
        }

        if ($order->status !== OrderStatus::Accepted) {
            throw new MarketplaceException('You can pay the balance once the seller has accepted the order.');
        }

        $isLast = ! $order->milestones()->where('position', '>', $milestone->position)->exists();
        $inspection = $order->inspection;

        if ($isLast && $inspection && ! $inspection->allowsFinalPayment()) {
            throw new MarketplaceException($inspection->status === 'failed'
                ? 'The inspection failed. Do not pay: report a problem so your deposit is refunded.'
                : 'Wait for the inspection report before paying the balance.');
        }

        return $this->charges->create($milestone, (string) $milestone->amount, $operator, $phone, $order->id);
    }

    /** @throws MarketplaceException */
    public function requestInspection(User $buyer, Order $order, Operator $operator, string $phone): Inspection
    {
        if ($order->buyer_id !== $buyer->id) {
            throw new MarketplaceException('This is not your order.');
        }

        if (! $order->milestones()->exists() || ! $order->hasUnpaidMilestones()) {
            throw new MarketplaceException('Inspection is for large orders paid in parts, before the balance is paid.');
        }

        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Accepted], true)) {
            throw new MarketplaceException('This order can no longer be inspected.');
        }

        $inspection = Inspection::firstOrCreate(['order_id' => $order->id], ['fee' => RewardSettings::money('inspection_fee'), 'status' => 'pending_payment']);

        if ($inspection->status !== 'pending_payment') {
            throw new MarketplaceException('An inspection is already booked for this order.');
        }

        if (Money::isZero($inspection->fee)) {
            $inspection->forceFill(['status' => 'waiting', 'paid_at' => now()])->save();
        } else {
            $this->charges->create($inspection, (string) $inspection->fee, $operator, $phone, $order->id);
        }

        return $inspection->refresh();
    }

    public function assign(Inspection $inspection, User $agent): void
    {
        if (! $agent->is_agent) {
            throw new MarketplaceException('Choose a WiBuy inspection agent.');
        }

        $inspection->forceFill(['agent_id' => $agent->id, 'status' => 'assigned'])->save();
    }

    /**
     * The agent's pass/fail report with photos.
     *
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws MarketplaceException
     */
    public function report(User $agent, Inspection $inspection, bool $passed, string $report, array $photos): Inspection
    {
        if ($inspection->agent_id !== $agent->id || $inspection->status !== 'assigned') {
            throw new MarketplaceException('This inspection is not assigned to you.');
        }

        foreach ($photos as $position => $photo) {
            $this->images->attach($inspection, $photo, 'inspection', $agent->id, $position);
        }

        $inspection->forceFill(['status' => $passed ? 'passed' : 'failed', 'report' => $report, 'reported_at' => now()])->save();
        $inspection->load('order.buyer');

        $this->sms->send($inspection->order->buyer->phone, SmsMessages::inspectionResult($inspection));

        return $inspection;
    }

    /** @return array<int, Image> */
    public function photos(Inspection $inspection): array
    {
        return $inspection->photos()->where('status', 'ready')->get()->all();
    }
}
