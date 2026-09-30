<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use App\Enum\SagaTransition;
use Ticketing\Contracts\Command\ProcessPayment;
use Ticketing\Contracts\Event\SeatsHeld;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @implements SagaTransitionStrategyInterface<SeatsHeld> */
final readonly class SeatsHeldStrategy implements SagaTransitionStrategyInterface
{
    public function __construct(
        private int $defaultAmountMinor,
        private string $defaultCurrency,
        private string $defaultPaymentMethodToken,
    ) {
    }

    public static function eventClass(): string
    {
        return SeatsHeld::class;
    }

    public function transition(): SagaTransition
    {
        return SagaTransition::SeatsHeld;
    }

    /** @param SeatsHeld $event */
    public function handle(Saga $saga, ReservationEventInterface $event): ProcessPayment
    {
        return new ProcessPayment(
            reservationId: $event->reservationId,
            showId: $event->showId,
            correlationId: $event->correlationId,
            amountMinor: $this->defaultAmountMinor,
            currency: $this->defaultCurrency,
            paymentMethodToken: $this->defaultPaymentMethodToken,
        );
    }
}
