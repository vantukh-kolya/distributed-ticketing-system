<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Command;

/**
 * Saga command: atomically hold seats for a reservation.
 */
final readonly class HoldSeats
{
    /**
     * @param list<string> $seatIds inventory seat UUIDs (not display codes)
     */
    public function __construct(
        public string $reservationId,
        public string $showId,
        public array $seatIds,
        public string $correlationId,
    ) {
    }
}
