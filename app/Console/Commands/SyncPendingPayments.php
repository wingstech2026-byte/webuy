<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Jobs\SyncPaymentJob;
use App\Models\Payment;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:sync-payments', description: 'Re-check pending deposits and refunds older than 2 minutes (in case a webhook was missed)')]
class SyncPendingPayments extends Command
{
    public function handle(): int
    {
        $ids = Payment::where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes(2))
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        $ids->each(fn (int $id) => SyncPaymentJob::dispatch($id));

        $this->info("Queued {$ids->count()} payment check(s).");

        return self::SUCCESS;
    }
}
