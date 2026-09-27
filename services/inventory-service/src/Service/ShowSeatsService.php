<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ShowSeatsResponse;
use App\Exception\ShowNotFoundException;
use App\Mapper\SeatMapper;
use App\Repository\SeatRepository;
use App\Repository\ShowRepository;

final readonly class ShowSeatsService
{
    public function __construct(
        private ShowRepository $showRepository,
        private SeatRepository $seatRepository,
        private SeatMapper $seatMapper,
    ) {
    }

    public function get(string $showId): ShowSeatsResponse
    {
        $show = $this->showRepository->find($showId);
        if ($show === null) {
            throw new ShowNotFoundException($showId);
        }

        return new ShowSeatsResponse(
            showId: $show->getId(),
            name: $show->getName(),
            seats: array_map($this->seatMapper->toResponse(...), $this->seatRepository->findByShowOrdered($showId)),
        );
    }
}
