<?php

declare(strict_types=1);

namespace App\Gateway;

final readonly class GatewayPaymentRequest
{
    public function __construct(
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public string $paymentMethodToken,
    ) {
    }
}
