<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Dto\ReservationResponse;
use App\Entity\Reservation;

final class ReservationMapper
{
    public function toResponse(Reservation $reservation): ReservationResponse
    {
        return new ReservationResponse(
            reservationId: $reservation->getId(),
            correlationId: $reservation->getCorrelationId(),
            status: $reservation->getStatus()->value,
            showId: $reservation->getShowId(),
            seatIds: $reservation->getSeatIds(),
            buyerName: $reservation->getBuyerName(),
            buyerEmail: $reservation->getBuyerEmail(),
        );
    }
}
