<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class SeatResponse
{
    public function __construct(
        public string $id,
        public string $code,
        public string $state,
        public ?string $heldByReservationId,
    ) {
    }
}
