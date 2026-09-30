<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Saga\SagaCoordinator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\SeatsHeld;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class SeatsHeldHandler
{
    public function __construct(
        private SagaCoordinator $sagaCoordinator,
    ) {
    }

    public function __invoke(SeatsHeld $event): void
    {
        $this->sagaCoordinator->handle($event);
    }
}
