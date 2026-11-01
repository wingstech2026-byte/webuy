<?php

namespace App\Console\Commands;

use App\Services\BuyerProtectionService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:late-shipping', description: 'Give buyers a coupon (paid by the seller) when an accepted order is not shipped in time')]
class CompensateLateShipping extends Command
{
    public function handle(BuyerProtectionService $protection): int
    {
        $this->info('Compensated '.$protection->compensateLateShipping().' late order(s).');

        return self::SUCCESS;
    }
}
