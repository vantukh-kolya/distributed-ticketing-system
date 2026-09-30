<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

/**
 * Terminal saga event: reservation is fully confirmed (seats sold + payment paid).
 */
final readonly class ReservationConfirmed implements ReservationEventInterface
{
    public function __construct(
        public string $reservationId,
        public string $correlationId,
        public \DateTimeImmutable $confirmedAt,
    ) {
    }
}
