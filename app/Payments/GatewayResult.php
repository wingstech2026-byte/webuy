<?php

namespace App\Payments;

use App\Enums\PaymentStatus;

/** What a gateway told us about a deposit or refund. */
final readonly class GatewayResult
{
    public function __construct(
        public PaymentStatus $status,
        public array $raw = [],
        public ?string $reason = null,
        public bool $notFound = false,
    ) {}

    public static function pending(array $raw = []): self
    {
        return new self(PaymentStatus::Pending, $raw);
    }

    public static function completed(array $raw = []): self
    {
        return new self(PaymentStatus::Completed, $raw);
    }

    public static function failed(?string $reason = null, array $raw = []): self
    {
        return new self(PaymentStatus::Failed, $raw, $reason);
    }

    public static function notFound(array $raw = []): self
    {
        return new self(PaymentStatus::Pending, $raw, null, true);
    }
}
