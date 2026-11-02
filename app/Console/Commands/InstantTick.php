<?php

namespace App\Console\Commands;

use App\Services\InstantService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:instant-tick', description: 'Instant food: remind shops to cook scheduled orders, close office orders, forget old rider spots')]
class InstantTick extends Command
{
    public function handle(InstantService $instant): int
    {
        $done = $instant->tick();
        $this->info("Reminded {$done['reminded']} shop(s), closed {$done['closed']} office order(s).");

        return self::SUCCESS;
    }
}
