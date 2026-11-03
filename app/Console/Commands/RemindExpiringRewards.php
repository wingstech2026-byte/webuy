<?php

namespace App\Console\Commands;

use App\Services\MyRewardsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:expiring-rewards', description: 'Tell people when their coupons end in 3 days')]
class RemindExpiringRewards extends Command
{
    public function handle(MyRewardsService $rewards): int
    {
        $this->info('Reminded '.$rewards->remindExpiring().' person(s).');

        return self::SUCCESS;
    }
}
