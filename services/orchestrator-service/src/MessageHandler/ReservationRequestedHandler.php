<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Saga\SagaCoordinator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\ReservationRequested;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class ReservationRequestedHandler
{
    public function __construct(public SagaCoordinator $sagaCoordinator)
    {
    }

    public function __invoke(ReservationRequested $event): void
    {
        $this->sagaCoordinator->onReservationRequested($event);
    }
}
