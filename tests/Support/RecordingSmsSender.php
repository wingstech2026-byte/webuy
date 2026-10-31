<?php

namespace Tests\Support;

use App\Sms\SmsSender;

/** Collects SMS messages instead of sending them. */
class RecordingSmsSender implements SmsSender
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /** @return array<int, string> messages sent to one phone */
    public function messagesTo(string $phone): array
    {
        return array_values(array_map(
            fn ($sms) => $sms['message'],
            array_filter($this->sent, fn ($sms) => $sms['to'] === $phone),
        ));
    }

    public function lastTo(string $phone): ?string
    {
        $messages = $this->messagesTo($phone);

        return $messages ? end($messages) : null;
    }

    /** Pull the first run of digits of the right length out of the last message to this phone. */
    public function lastCodeTo(string $phone): ?string
    {
        preg_match('/\b(\d{6})\b/', (string) $this->lastTo($phone), $m);

        return $m[1] ?? null;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
