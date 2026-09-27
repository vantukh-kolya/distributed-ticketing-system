<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\ReservationCancellationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\ReservationCancelled;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class ReservationCancelledHandler
{
    public function __construct(
        private ReservationCancellationService $reservationCancellationService,
    ) {
    }

    public function __invoke(ReservationCancelled $event): void
    {
        $this->reservationCancellationService->onReservationCancelled($event);
    }
}
