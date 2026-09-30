<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

final readonly class PaymentFailed implements ReservationEventInterface
{
    public function __construct(
        public string $paymentId,
        public string $reservationId,
        public string $correlationId,
        public string $reason,
        public \DateTimeImmutable $failedAt,
    ) {
    }
}
