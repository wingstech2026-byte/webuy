<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppClient;
use App\Services\WhatsApp\WhatsAppShopping;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Answer one WhatsApp message off the web request (Meta wants the webhook to answer within seconds). */
class HandleWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @param array{from: string, type: string, text?: string, image_id?: string} $message */
    public function __construct(public array $message) {}

    public function handle(WhatsAppShopping $shopping, WhatsAppClient $whatsapp): void
    {
        $whatsapp->sendText($this->message['from'], $shopping->reply($this->message));
    }
}
