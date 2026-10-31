<?php

namespace App\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Generic bulk-SMS HTTP API: POST JSON {from, to, message} with a bearer token. */
class HttpSender implements SmsSender
{
    public function __construct(
        private string $senderId,
        private ?string $url,
        private ?string $token,
        private int $timeout = 10,
    ) {}

    public function send(string $to, string $message): void
    {
        if (! $this->url) {
            throw new RuntimeException('SMS_HTTP_URL is not configured.');
        }

        Http::withToken((string) $this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->post($this->url, [
                'from' => $this->senderId,
                'to' => $to,
                'message' => $message,
            ])
            ->throw();
    }
}
