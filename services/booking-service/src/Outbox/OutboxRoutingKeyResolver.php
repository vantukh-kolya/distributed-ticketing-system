<?php

declare(strict_types=1);

namespace App\Outbox;

use Ticketing\Contracts\Event\ReservationRequested;
use Ticketing\Outbox\RoutingKeyResolverInterface;

final class OutboxRoutingKeyResolver implements RoutingKeyResolverInterface
{
    public function resolve(object $message): string
    {
        return match ($message::class) {
            ReservationRequested::class => 'reservation.requested',
            default => throw new \InvalidArgumentException(sprintf(
                'No outbox routing key configured for message "%s".',
                $message::class,
            )),
        };
    }
}
