<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Dto\SeatResponse;
use App\Entity\Seat;

final class SeatMapper
{
    public function toResponse(Seat $seat): SeatResponse
    {
        return new SeatResponse(
            id: $seat->getId(),
            code: $seat->getSeatCode(),
            state: $seat->getState()->value,
            heldByReservationId: $seat->getHeldByReservationId(),
        );
    }
}
