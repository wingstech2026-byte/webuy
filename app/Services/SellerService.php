<?php

namespace App\Services;

use App\Enums\SellerStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Seller;
use App\Models\SellerDeliveryArea;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SellerService
{
    public function __construct(private ImageService $images, private SmsService $sms) {}

    /**
     * A buyer applies to become a seller. The shop waits for an admin.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws MarketplaceException
     */
    public function apply(User $user, array $data, ?UploadedFile $logo = null): Seller
    {
        if ($existing = Seller::where('user_id', $user->id)->first()) {
            throw new MarketplaceException('You already applied. Your application is "'.$existing->status->label().'".');
        }

        return DB::transaction(function () use ($user, $data, $logo) {
            $seller = Seller::create($data + [
                'user_id' => $user->id,
                'slug' => Slug::unique(Seller::class, $data['shop_name']),
                'status' => SellerStatus::Pending,
            ]);

            if ($logo) {
                $this->images->attach($seller, $logo, 'logo', $user->id);
            }

            return $seller;
        });
    }

    /** Re-apply after a rejection. */
    public function reapply(Seller $seller, array $data, ?UploadedFile $logo = null): Seller
    {
        if ($seller->status !== SellerStatus::Rejected) {
            throw new MarketplaceException('You can only change your application after it was rejected.');
        }

        $seller->forceFill($data + ['status' => SellerStatus::Pending, 'rejection_reason' => null])->save();

        if ($logo) {
            $this->images->replace($seller, $logo, 'logo', $seller->user_id);
        }

        return $seller;
    }

    /** Update the shop profile (approved sellers). */
    public function updateProfile(Seller $seller, array $data, ?UploadedFile $logo = null): Seller
    {
        $seller->fill($data)->save();

        if ($logo) {
            $this->images->replace($seller, $logo, 'logo', $seller->user_id);
        }

        return $seller;
    }

    /**
     * Set what the seller charges to deliver to each district. Districts left out are not delivered to.
     *
     * @param  array<string, string|null>  $fees  district => fee (blank or null = no delivery there)
     */
    public function syncDeliveryAreas(Seller $seller, array $fees): void
    {
        DB::transaction(function () use ($seller, $fees) {
            $seller->deliveryAreas()->delete();

            foreach ($fees as $district => $fee) {
                if ($fee === null || $fee === '') {
                    continue;
                }

                SellerDeliveryArea::create(['seller_id' => $seller->id, 'district' => $district, 'fee' => $fee]);
            }
        });
    }

    /** @throws MarketplaceException when the owner has not sent their national ID card yet */
    public function approve(Seller $seller, bool $verified = false): Seller
    {
        if (! $seller->hasOwnerIdentity()) {
            throw new MarketplaceException('This shop cannot be approved yet: the owner has not sent their identity document (ID card or passport, number and photo).');
        }

        $seller->forceFill([
            'status' => SellerStatus::Approved,
            'approved_at' => $seller->approved_at ?? now(),
            'rejection_reason' => null,
            'is_verified' => $verified || $seller->is_verified,
        ])->save();

        $seller->load('user');
        $this->sms->send($seller->user->phone, SmsMessages::sellerApproved($seller));

        return $seller;
    }

    public function reject(Seller $seller, ?string $reason = null): Seller
    {
        $seller->forceFill(['status' => SellerStatus::Rejected, 'rejection_reason' => $reason, 'is_verified' => false])->save();

        $seller->load('user');
        $this->sms->send($seller->user->phone, SmsMessages::sellerRejected($seller));

        return $seller;
    }

    public function suspend(Seller $seller): Seller
    {
        $seller->forceFill(['status' => SellerStatus::Suspended])->save();

        return $seller;
    }

    public function toggleVerified(Seller $seller): Seller
    {
        $seller->forceFill(['is_verified' => ! $seller->is_verified])->save();

        return $seller;
    }
}
