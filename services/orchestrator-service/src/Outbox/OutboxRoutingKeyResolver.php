<?php

declare(strict_types=1);

namespace App\Outbox;

use Ticketing\Contracts\Command\ConfirmSeats;
use Ticketing\Contracts\Command\HoldSeats;
use Ticketing\Contracts\Command\ProcessPayment;
use Ticketing\Contracts\Command\ReleaseSeats;
use Ticketing\Contracts\Event\ReservationCancelled;
use Ticketing\Contracts\Event\ReservationConfirmed;
use Ticketing\Outbox\RoutingKeyResolverInterface;

final class OutboxRoutingKeyResolver implements RoutingKeyResolverInterface
{
    public function resolve(object $message): string
    {
        return match ($message::class) {
            HoldSeats::class => 'seats.hold',
            ProcessPayment::class => 'payment.process',
            ReleaseSeats::class => 'seats.release',
            ConfirmSeats::class => 'seats.confirm',
            ReservationConfirmed::class => 'reservation.confirmed',
            ReservationCancelled::class => 'reservation.cancelled',
            default => throw new \InvalidArgumentException(sprintf(
                'No outbox routing key configured for message "%s".',
                $message::class,
            )),
        };
    }
}
