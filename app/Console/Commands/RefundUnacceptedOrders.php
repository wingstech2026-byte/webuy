<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:refund-unaccepted', description: 'Refund paid orders the seller did not accept in time')]
class RefundUnacceptedOrders extends Command
{
    public function handle(OrderService $orders): int
    {
        $this->info('Refunded '.$orders->refundUnaccepted().' unaccepted order(s).');

        return self::SUCCESS;
    }
}
