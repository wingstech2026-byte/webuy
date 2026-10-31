<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;

/**
 * Free trials: people apply, the seller picks some, they get the product free (or almost) with a
 * coupon and must post an honest review with photos within a few days of receiving it.
 * Those reviews are marked "Free trial" for other buyers.
 */
class TrialService
{
    public function __construct(private CouponService $coupons) {}

    /** @throws MarketplaceException */
    public function apply(User $user, TrialCampaign $campaign, ?string $reason): TrialApplication
    {
        if (! $campaign->isOpen()) {
            throw new MarketplaceException('This free trial is closed.');
        }

        if ($campaign->seller->user_id === $user->id) {
            throw new MarketplaceException('You cannot apply for your own free trial.');
        }

        if (RewardSettings::bool('trial_block_overdue') && TrialApplication::where('user_id', $user->id)->where('status', 'overdue')->exists()) {
            throw new MarketplaceException('You cannot join new free trials because a trial review was never written.');
        }

        try {
            return TrialApplication::create(['trial_campaign_id' => $campaign->id, 'user_id' => $user->id, 'reason' => $reason]);
        } catch (UniqueConstraintViolationException) {
            throw new MarketplaceException('You already applied for this free trial.');
        }
    }

    /** The seller (or an admin) picks this person. They get a coupon that makes one unit cost the trial price. */
    public function select(TrialApplication $application): void
    {
        $campaign = $application->campaign;

        if ($application->status !== 'applied') {
            throw new MarketplaceException('This application was already handled.');
        }

        if ($campaign->selectedCount() >= $campaign->slots) {
            throw new MarketplaceException("All {$campaign->slots} places are taken.");
        }

        $coupon = $this->coupons->issue($application->user, [
            'title' => 'Free trial: '.$campaign->product->title,
            'source' => 'trial',
            'amount' => Money::sub($campaign->product->price, $campaign->trial_price),
            'product_id' => $campaign->product_id,
            'expires_at' => now()->addDays(14),
        ]);

        $application->forceFill(['status' => 'selected', 'selected_at' => now(), 'coupon_id' => $coupon->id])->save();
    }

    public function reject(TrialApplication $application): void
    {
        if ($application->status === 'applied') {
            $application->forceFill(['status' => 'rejected'])->save();
        }
    }

    /** A review was written for a product: mark it as a free trial review when it is one. */
    public function onReview(Review $review): void
    {
        $application = TrialApplication::where('user_id', $review->buyer_id)->whereIn('status', ['selected', 'overdue'])
            ->whereHas('campaign', fn ($q) => $q->where('product_id', $review->product_id))->first();

        if (! $application) {
            return;
        }

        $review->forceFill(['is_free_trial' => true])->save();

        if ($review->images()->exists()) {
            $application->forceFill(['status' => 'reviewed', 'review_id' => $review->id])->save();
        }
    }

    /**
     * Daily: start the review clock when the trial product arrived, and mark trials whose review
     * never came as overdue.
     */
    public function tick(): int
    {
        $days = RewardSettings::int('trial_review_days');

        TrialApplication::where('status', 'selected')->whereNull('review_due_at')->with('campaign')->each(function (TrialApplication $application) use ($days) {
            $received = OrderItem::where('product_id', $application->campaign->product_id)
                ->whereHas('order', fn ($q) => $q->where('buyer_id', $application->user_id)
                    ->whereIn('status', [OrderStatus::Delivered, OrderStatus::Completed])
                    ->where('created_at', '>=', $application->selected_at))
                ->with('order')->first();

            if ($received) {
                $at = $received->order->delivered_at ?? $received->order->completed_at ?? now();
                $application->forceFill(['review_due_at' => $at->copy()->addDays($days)])->save();
            }
        });

        return TrialApplication::where('status', 'selected')->whereNotNull('review_due_at')
            ->where('review_due_at', '<', now())->update(['status' => 'overdue']);
    }

    /**
     * The tester's honest review with photos (website and app).
     *
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws MarketplaceException
     */
    public function writeReview(User $user, TrialCampaign $campaign, int $rating, string $comment, array $photos): Review
    {
        $application = TrialApplication::where('trial_campaign_id', $campaign->id)->where('user_id', $user->id)
            ->whereIn('status', ['selected', 'overdue'])->first();

        if (! $application) {
            throw new MarketplaceException('You were not picked for this free trial, or your review is already posted.');
        }

        $item = OrderItem::where('product_id', $campaign->product_id)
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $user->id)->where('status', OrderStatus::Completed))
            ->whereDoesntHave('review')->with('order')->latest('id')->first();

        if (! $item) {
            throw new MarketplaceException('You can write the review once your trial order is completed.');
        }

        app(ReviewService::class)->submit($item->order, $user, null, null, [$item->id => ['rating' => $rating, 'comment' => $comment]]);
        $review = Review::where('order_item_id', $item->id)->firstOrFail();

        foreach ($photos as $position => $photo) {
            app(ImageService::class)->attach($review, $photo, 'review', $user->id, $position);
        }

        $this->onReview($review);

        return $review;
    }
}
