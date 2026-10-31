<?php

namespace App\Jobs;

use App\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $phone, public string $message)
    {
        // Never text about something that was rolled back.
        $this->afterCommit();
    }

    /** @return int[] */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SmsSender $sender): void
    {
        try {
            $sender->send($this->phone, $this->message);
        } catch (Throwable $e) {
            // On a real queue, rethrow so the worker retries. With QUEUE_CONNECTION=sync the job
            // runs inside the web request, where a texting problem must never break an order.
            if ($this->job?->getConnectionName() === 'sync') {
                report($e);

                return;
            }

            throw $e;
        }
    }
}
