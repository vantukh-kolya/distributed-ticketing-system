<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\ReservationConfirmationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\ReservationConfirmed;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class ReservationConfirmedHandler
{
    public function __construct(
        private ReservationConfirmationService $reservationConfirmationService,
    ) {
    }

    public function __invoke(ReservationConfirmed $event): void
    {
        $this->reservationConfirmationService->onReservationConfirmed($event);
    }
}
