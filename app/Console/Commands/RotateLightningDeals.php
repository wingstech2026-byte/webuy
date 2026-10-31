<?php

namespace App\Console\Commands;

use App\Services\FlashDealService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:rotate-deals', description: "Fill tomorrow's lightning deals from products marked as deals")]
class RotateLightningDeals extends Command
{
    protected $signature = 'wibuy:rotate-deals {--today : Fill today instead of tomorrow}';

    public function handle(FlashDealService $deals): int
    {
        $day = $this->option('today') ? today() : today()->addDay();
        $count = $deals->rotate($day);

        $this->info("Added {$count} lightning deal(s) for {$day->toDateString()}.");

        return self::SUCCESS;
    }
}
