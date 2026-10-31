<?php

namespace App\Console\Commands;

use App\Services\ReliabilityService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:reliability', description: 'Recalculate every shop\'s delivery reliability score (last 90 days)')]
class RefreshReliability extends Command
{
    public function handle(ReliabilityService $reliability): int
    {
        $count = $reliability->refreshAll();

        $this->info("Updated the reliability score of {$count} shop(s).");

        return self::SUCCESS;
    }
}
