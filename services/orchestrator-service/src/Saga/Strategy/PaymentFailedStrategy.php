<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use App\Enum\SagaTransition;
use Ticketing\Contracts\Command\ReleaseSeats;
use Ticketing\Contracts\Event\PaymentFailed;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @implements SagaTransitionStrategyInterface<PaymentFailed> */
final readonly class PaymentFailedStrategy implements SagaTransitionStrategyInterface
{
    public static function eventClass(): string
    {
        return PaymentFailed::class;
    }

    public function transition(): SagaTransition
    {
        return SagaTransition::PaymentFailed;
    }

    /** @param PaymentFailed $event */
    public function handle(Saga $saga, ReservationEventInterface $event): ReleaseSeats
    {
        $saga->recordFailure($event->reason);

        return new ReleaseSeats(
            reservationId: $event->reservationId,
            showId: $saga->getShowId(),
            seatIds: $saga->getSeatIds(),
            correlationId: $event->correlationId,
        );
    }
}
