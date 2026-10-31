<?php

namespace App\Console\Commands;

use App\Services\CouponService;
use App\Services\FlashDealService;
use App\Services\PriceCutService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:rewards-tick', description: 'Lightning deal "Remind me" SMS, and expire old price cuts and coupons')]
class SendDealReminders extends Command
{
    public function handle(FlashDealService $deals, PriceCutService $cuts, CouponService $coupons): int
    {
        $sent = $deals->sendReminders();
        $expiredCuts = $cuts->expireOld();
        $expiredCoupons = $coupons->expireOld();

        $this->info("Sent {$sent} deal reminder(s). Expired {$expiredCuts} price cut(s) and {$expiredCoupons} coupon(s).");

        return self::SUCCESS;
    }
}
