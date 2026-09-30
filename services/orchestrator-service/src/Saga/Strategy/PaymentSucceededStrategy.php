<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use App\Enum\SagaTransition;
use Ticketing\Contracts\Command\ConfirmSeats;
use Ticketing\Contracts\Event\PaymentSucceeded;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @implements SagaTransitionStrategyInterface<PaymentSucceeded> */
final readonly class PaymentSucceededStrategy implements SagaTransitionStrategyInterface
{
    public static function eventClass(): string
    {
        return PaymentSucceeded::class;
    }

    public function transition(): SagaTransition
    {
        return SagaTransition::PaymentSucceeded;
    }

    /** @param PaymentSucceeded $event */
    public function handle(Saga $saga, ReservationEventInterface $event): ConfirmSeats
    {
        return new ConfirmSeats(
            reservationId: $event->reservationId,
            showId: $saga->getShowId(),
            seatIds: $saga->getSeatIds(),
            correlationId: $event->correlationId,
        );
    }
}
