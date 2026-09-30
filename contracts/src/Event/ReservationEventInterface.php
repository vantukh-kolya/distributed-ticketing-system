<?php

declare(strict_types=1);

namespace Ticketing\Contracts\Event;

/**
 * Common metadata exposed by reservation event DTOs.
 * Read-only requirements are satisfied by their existing promoted properties.
 */
interface ReservationEventInterface
{
    public string $reservationId { get; }

    public string $correlationId { get; }
}
