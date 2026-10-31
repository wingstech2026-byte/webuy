<?php

namespace App\Console\Commands;

use App\Services\ReturnService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:returns-tick', description: 'Send returns the seller did not answer or confirm in time to the WiBuy team')]
class EscalateOverdueReturns extends Command
{
    public function handle(ReturnService $returns): int
    {
        $this->info('Escalated '.$returns->escalateOverdue().' return(s).');

        return self::SUCCESS;
    }
}
