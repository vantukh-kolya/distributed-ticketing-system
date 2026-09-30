<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Entity\Saga;
use Ticketing\Contracts\Event\ReservationEventInterface;

/**
 * @template TEvent of ReservationEventInterface
 * @extends SagaEventStrategyInterface<TEvent>
 */
interface SagaCreationStrategyInterface extends SagaEventStrategyInterface
{
    /** @param TEvent $event */
    public function create(ReservationEventInterface $event): Saga;
}
