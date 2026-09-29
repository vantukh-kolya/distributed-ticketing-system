<?php

declare(strict_types=1);

namespace App\Outbox;

use Ticketing\Contracts\Event\SeatHoldRejected;
use Ticketing\Contracts\Event\SeatsConfirmed;
use Ticketing\Contracts\Event\SeatsHeld;
use Ticketing\Contracts\Event\SeatsReleased;
use Ticketing\Outbox\RoutingKeyResolverInterface;

final class OutboxRoutingKeyResolver implements RoutingKeyResolverInterface
{
    public function resolve(object $message): string
    {
        return match ($message::class) {
            SeatsHeld::class => 'seats.held',
            SeatHoldRejected::class => 'seats.hold_rejected',
            SeatsReleased::class => 'seats.released',
            SeatsConfirmed::class => 'seats.confirmed',
            default => throw new \InvalidArgumentException(sprintf(
                'No outbox routing key configured for message "%s".',
                $message::class,
            )),
        };
    }
}
