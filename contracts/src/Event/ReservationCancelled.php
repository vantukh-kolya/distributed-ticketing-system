<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

final readonly class ReservationCancelled implements ReservationEventInterface
{
    public function __construct(
        public string $reservationId,
        public string $correlationId,
        public \DateTimeImmutable $cancelledAt,
    ) {
    }
}
