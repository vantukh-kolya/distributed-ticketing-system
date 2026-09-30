<?php

declare(strict_types=1);

namespace App\Saga\Strategy;

use App\Enum\SagaTransition;
use Ticketing\Contracts\Event\ReservationEventInterface;

/**
 * @template TEvent of ReservationEventInterface
 * @extends SagaEventStrategyInterface<TEvent>
 */
interface SagaTransitionStrategyInterface extends SagaEventStrategyInterface
{
    public function transition(): SagaTransition;
}
