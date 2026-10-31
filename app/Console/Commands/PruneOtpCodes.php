<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:prune-otp', description: 'Delete login codes older than a day')]
class PruneOtpCodes extends Command
{
    public function handle(): int
    {
        $deleted = OtpCode::where('created_at', '<', now()->subDay())->delete();

        $this->info("Deleted {$deleted} old login code(s).");

        return self::SUCCESS;
    }
}
