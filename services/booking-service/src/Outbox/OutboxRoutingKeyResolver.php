<?php

declare(strict_types=1);

namespace App\Outbox;

use Ticketing\Contracts\Event\ReservationRequested;

final class OutboxRoutingKeyResolver
{
    public function resolve(object $event): string
    {
        return match ($event::class) {
            ReservationRequested::class => 'reservation.requested',
            default => throw new \InvalidArgumentException(sprintf(
                'No outbox routing key configured for message "%s".',
                $event::class,
            )),
        };
    }
}
