<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Saga\SagaCoordinator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\SeatHoldRejected;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class SeatHoldRejectedHandler
{
    public function __construct(
        private SagaCoordinator $sagaCoordinator,
    )
    {
    }

    public function __invoke(SeatHoldRejected $event): void
    {
        $this->sagaCoordinator->onSeatHoldRejected($event);
    }
}
