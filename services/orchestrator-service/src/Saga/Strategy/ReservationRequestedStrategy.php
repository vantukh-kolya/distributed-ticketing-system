<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use App\Enum\SagaState;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\HoldSeats;
use Ticketing\Contracts\Event\ReservationRequested;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @implements SagaCreationStrategyInterface<ReservationRequested> */
final readonly class ReservationRequestedStrategy implements SagaCreationStrategyInterface
{
    public static function eventClass(): string
    {
        return ReservationRequested::class;
    }

    /** @param ReservationRequested $event */
    public function create(ReservationEventInterface $event): Saga
    {
        return new Saga(
            id: Uuid::v7()->toRfc4122(),
            reservationId: $event->reservationId,
            correlationId: $event->correlationId,
            showId: $event->showId,
            seatIds: $event->seatIds,
            state: SagaState::AwaitingSeats,
        );
    }

    /** @param ReservationRequested $event */
    public function handle(Saga $saga, ReservationEventInterface $event): HoldSeats
    {
        return new HoldSeats(
            reservationId: $event->reservationId,
            showId: $event->showId,
            seatIds: $event->seatIds,
            correlationId: $event->correlationId,
        );
    }
}
