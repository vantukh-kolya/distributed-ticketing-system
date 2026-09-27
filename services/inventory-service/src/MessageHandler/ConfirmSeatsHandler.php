<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\SeatHoldService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Command\ConfirmSeats;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ConfirmSeatsHandler
{
    public function __construct(
        private SeatHoldService $seatHoldService,
    ) {
    }

    public function __invoke(ConfirmSeats $command): void
    {
        $this->seatHoldService->confirm($command);
    }
}
