<?php

declare(strict_types=1);

namespace App\Saga;

use App\Saga\Strategy\SagaEventStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Ticketing\Contracts\Event\ReservationEventInterface;

final readonly class SagaCoordinator
{
    /** @var array<class-string, SagaEventStrategyInterface> */
    private array $strategies;

    /** @param iterable<SagaEventStrategyInterface> $strategies */
    public function __construct(
        private SagaExecutor $executor,
        #[AutowireIterator('app.saga_event_strategy')]
        iterable $strategies,
    ) {
        $byEvent = [];
        foreach ($strategies as $strategy) {
            $eventClass = $strategy::eventClass();
            if (isset($byEvent[$eventClass])) {
                throw new \LogicException(sprintf('Multiple saga strategies registered for "%s".', $eventClass));
            }

            $byEvent[$eventClass] = $strategy;
        }

        $this->strategies = $byEvent;
    }

    public function handle(ReservationEventInterface $event): void
    {
        $strategy = $this->strategies[$event::class] ?? throw new \InvalidArgumentException(sprintf(
            'No saga strategy registered for "%s".',
            $event::class,
        ));

        $this->executor->execute($event, $strategy);
    }
}
