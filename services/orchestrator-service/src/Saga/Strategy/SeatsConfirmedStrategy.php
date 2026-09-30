<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use App\Enum\SagaTransition;
use Ticketing\Contracts\Event\ReservationConfirmed;
use Ticketing\Contracts\Event\SeatsConfirmed;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @implements SagaTransitionStrategyInterface<SeatsConfirmed> */
final readonly class SeatsConfirmedStrategy implements SagaTransitionStrategyInterface
{
    public static function eventClass(): string
    {
        return SeatsConfirmed::class;
    }

    public function transition(): SagaTransition
    {
        return SagaTransition::SeatsConfirmed;
    }

    /** @param SeatsConfirmed $event */
    public function handle(Saga $saga, ReservationEventInterface $event): ReservationConfirmed
    {
        return new ReservationConfirmed(
            reservationId: $event->reservationId,
            correlationId: $event->correlationId,
            confirmedAt: $event->confirmedAt,
        );
    }
}
