<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

/**
 * Published after held seats were released back to AVAILABLE.
 */
final readonly class SeatsReleased implements ReservationEventInterface
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
