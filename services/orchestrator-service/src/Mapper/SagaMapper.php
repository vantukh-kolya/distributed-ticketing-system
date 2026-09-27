<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Dto\SagaResponse;
use App\Entity\Saga;

final class SagaMapper
{
    public function toResponse(Saga $saga): SagaResponse
    {
        return new SagaResponse(
            reservationId: $saga->getReservationId(),
            correlationId: $saga->getCorrelationId(),
            showId: $saga->getShowId(),
            seatIds: $saga->getSeatIds(),
            state: $saga->getState()->value,
            failureReason: $saga->getFailureReason(),
            createdAt: $saga->getCreatedAt()->format(DATE_ATOM),
            updatedAt: $saga->getUpdatedAt()->format(DATE_ATOM),
        );
    }
}
