<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Command;

/**
 * Saga command: attempt a payment for a reservation.
 */
final readonly class ProcessPayment
{
    public function __construct(
        public string $reservationId,
        public string $showId,
        public string $correlationId,
        public int $amountMinor,
        public string $currency,
        public string $paymentMethodToken,
    ) {
    }
}
