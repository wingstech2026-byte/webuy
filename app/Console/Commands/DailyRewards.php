<?php

namespace App\Console\Commands;

use App\Services\MembershipService;
use App\Services\TrialService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:daily-rewards', description: 'WiBuy Pass reminder SMS and free trial review deadlines')]
class DailyRewards extends Command
{
    public function handle(MembershipService $memberships, TrialService $trials): int
    {
        $reminded = $memberships->remindExpiring();
        $overdue = $trials->tick();

        $this->info("Sent {$reminded} WiBuy Pass reminder(s). {$overdue} trial review(s) became overdue.");

        return self::SUCCESS;
    }
}
