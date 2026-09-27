<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\SeatHoldService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Command\ReleaseSeats;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReleaseSeatsHandler
{
    public function __construct(
        private SeatHoldService $seatHoldService,
    ) {
    }

    public function __invoke(ReleaseSeats $command): void
    {
        $this->seatHoldService->release($command);
    }
}
