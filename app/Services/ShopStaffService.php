<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Seller;
use App\Models\User;
use App\Support\PhoneNumber;

/**
 * Shop helpers: an owner adds people (by their WiBuy phone number) who accept, pack and ship orders, add products
 * and answer questions. They never see the money, payouts, shop settings or other helpers.
 */
class ShopStaffService
{
    public const MAX_HELPERS = 5;

    public function __construct(private NotificationService $notifications) {}

    /** @throws MarketplaceException */
    public function add(Seller $shop, string $phone): User
    {
        $helper = User::where('phone', PhoneNumber::normalize($phone))->first();

        if (! $helper) {
            throw new MarketplaceException('No WiBuy account has this number. Ask them to sign up first.');
        }

        if ($helper->id === $shop->user_id || $helper->ownShop() !== null) {
            throw new MarketplaceException('This person has their own shop, so they cannot be a helper.');
        }

        if ($helper->staffShops()->exists()) {
            throw new MarketplaceException('This person already helps in a shop.');
        }

        if ($shop->staff()->count() >= self::MAX_HELPERS) {
            throw new MarketplaceException('A shop can have up to '.self::MAX_HELPERS.' helpers.');
        }

        $shop->staff()->attach($helper);
        $this->notifications->notify($helper, '🧑‍🤝‍🧑 You are a shop helper', "{$shop->shop_name} added you as a helper. You can now manage its orders and products.", route('seller.dashboard'));

        return $helper;
    }

    public function remove(Seller $shop, User $helper): void
    {
        $shop->staff()->detach($helper);
    }
}
