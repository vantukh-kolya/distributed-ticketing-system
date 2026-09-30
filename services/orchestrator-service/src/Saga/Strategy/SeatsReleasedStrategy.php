<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use App\Enum\SagaTransition;
use Ticketing\Contracts\Event\ReservationCancelled;
use Ticketing\Contracts\Event\SeatsReleased;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @implements SagaTransitionStrategyInterface<SeatsReleased> */
final readonly class SeatsReleasedStrategy implements SagaTransitionStrategyInterface
{
    public static function eventClass(): string
    {
        return SeatsReleased::class;
    }

    public function transition(): SagaTransition
    {
        return SagaTransition::SeatsReleased;
    }

    /** @param SeatsReleased $event */
    public function handle(Saga $saga, ReservationEventInterface $event): ReservationCancelled
    {
        return new ReservationCancelled(
            reservationId: $event->reservationId,
            correlationId: $event->correlationId,
            cancelledAt: new \DateTimeImmutable(),
        );
    }
}
