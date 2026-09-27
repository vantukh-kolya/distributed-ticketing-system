<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReservationResponse;
use App\Exception\ReservationNotFoundException;
use App\Mapper\ReservationMapper;
use App\Repository\ReservationRepository;

final readonly class ReservationQueryService
{
    public function __construct(
        private ReservationRepository $reservationRepository,
        private ReservationMapper $reservationMapper,
    ) {
    }

    public function get(string $reservationId): ReservationResponse
    {
        $reservation = $this->reservationRepository->find($reservationId);
        if ($reservation === null) {
            throw new ReservationNotFoundException($reservationId);
        }

        return $this->reservationMapper->toResponse($reservation);
    }
}
