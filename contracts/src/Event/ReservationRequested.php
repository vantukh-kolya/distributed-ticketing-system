<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

/**
 * Published when a new reservation is persisted and the saga should start.
 */
final readonly class ReservationRequested
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
