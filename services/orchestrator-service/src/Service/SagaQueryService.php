<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SagaResponse;
use App\Exception\SagaNotFoundException;
use App\Mapper\SagaMapper;
use App\Repository\SagaRepository;

final readonly class SagaQueryService
{
    public function __construct(
        private SagaRepository $sagaRepository,
        private SagaMapper $sagaMapper,
    ) {
    }

    public function get(string $reservationId): SagaResponse
    {
        $saga = $this->sagaRepository->findByReservationId($reservationId);
        if ($saga === null) {
            throw new SagaNotFoundException($reservationId);
        }

        return $this->sagaMapper->toResponse($saga);
    }
}
