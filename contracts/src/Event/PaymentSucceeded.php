<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

final readonly class PaymentSucceeded
{
    public function __construct(
        public string $paymentId,
        public string $reservationId,
        public string $correlationId,
        public int $amountMinor,
        public string $currency,
        public \DateTimeImmutable $paidAt,
    ) {
    }
}
