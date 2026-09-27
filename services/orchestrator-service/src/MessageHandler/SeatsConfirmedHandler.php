<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Saga\SagaCoordinator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\SeatsConfirmed;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class SeatsConfirmedHandler
{
    public function __construct(
        private SagaCoordinator $sagaCoordinator,
    ) {
    }

    public function __invoke(SeatsConfirmed $event): void
    {
        $this->sagaCoordinator->onSeatsConfirmed($event);
    }
}
