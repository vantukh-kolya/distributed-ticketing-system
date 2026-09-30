<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

/**
 * Published when all requested seats were held for the reservation.
 */
final readonly class SeatsHeld implements ReservationEventInterface
{
    /**
     * @param list<string> $seatIds inventory seat UUIDs (not display codes)
     */
    public function __construct(
        public string $reservationId,
        public string $showId,
        public array $seatIds,
        public string $correlationId,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
