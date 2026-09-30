<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Ticketing\Contracts\Event\ReservationEventInterface;

/** @template TEvent of ReservationEventInterface */
#[AutoconfigureTag('app.saga_event_strategy')]
interface SagaEventStrategyInterface
{
    /** @return class-string<TEvent> */
    public static function eventClass(): string;

    /**
     * Return the outgoing message after the executor accepts this event.
     *
     * @param TEvent $event
     */
    public function handle(Saga $saga, ReservationEventInterface $event): object;
}
