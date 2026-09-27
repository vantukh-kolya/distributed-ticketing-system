<?php

declare(strict_types=1);

namespace App\Gateway;

final readonly class GatewayPaymentResult
{
    private function __construct(
        public bool $succeeded,
        public ?string $paymentId,
        public ?string $failureReason,
    ) {
    }

    public static function succeeded(string $paymentId): self
    {
        return new self(true, $paymentId, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
