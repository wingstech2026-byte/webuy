<?php

namespace App\Sms;

interface SmsSender
{
    /**
     * Send one SMS. Throw on failure so the queue can retry.
     *
     * @param  string  $to  normalised phone, e.g. 23276123456
     */
    public function send(string $to, string $message): void;
}
