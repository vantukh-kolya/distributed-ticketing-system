<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use App\Enum\SagaTransition;
use Ticketing\Contracts\Event\ReservationCancelled;
use Ticketing\Contracts\Event\SeatHoldRejected;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @implements SagaTransitionStrategyInterface<SeatHoldRejected> */
final readonly class SeatHoldRejectedStrategy implements SagaTransitionStrategyInterface
{
    public static function eventClass(): string
    {
        return SeatHoldRejected::class;
    }

    public function transition(): SagaTransition
    {
        return SagaTransition::SeatHoldRejected;
    }

    /** @param SeatHoldRejected $event */
    public function handle(Saga $saga, ReservationEventInterface $event): ReservationCancelled
    {
        $saga->recordFailure($event->reason);

        return new ReservationCancelled(
            reservationId: $event->reservationId,
            correlationId: $event->correlationId,
            cancelledAt: new \DateTimeImmutable(),
        );
    }
}
