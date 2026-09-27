<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

/**
 * Published when hold could not be applied (e.g. seat not AVAILABLE).
 */
final readonly class SeatHoldRejected
{
    public function __construct(
        public string $reservationId,
        public string $showId,
        public string $correlationId,
        public string $reason,
    ) {
    }
}
