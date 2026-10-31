<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:complete-orders', description: 'Complete orders shipped or delivered days ago with no dispute, and pay the sellers')]
class CompleteDeliveredOrders extends Command
{
    public function handle(OrderService $orders): int
    {
        $this->info('Completed '.$orders->autoComplete().' order(s).');

        return self::SUCCESS;
    }
}
