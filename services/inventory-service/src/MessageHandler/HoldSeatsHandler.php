<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\SeatHoldService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Command\HoldSeats;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class HoldSeatsHandler
{
    public function __construct(
        private SeatHoldService $seatHoldService,
    ) {
    }

    public function __invoke(HoldSeats $command): void
    {
        $this->seatHoldService->hold($command);
    }
}
