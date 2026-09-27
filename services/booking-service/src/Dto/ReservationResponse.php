<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ReservationResponse
{
    /**
     * @param list<string> $seatIds
     */
    public function __construct(
        public string $reservationId,
        public string $correlationId,
        public string $status,
        public string $showId,
        public array $seatIds,
        public string $buyerName,
        public string $buyerEmail,
    ) {
    }
}
