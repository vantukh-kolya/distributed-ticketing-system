<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ShowSeatsResponse
{
    /** @param list<SeatResponse> $seats */
    public function __construct(
        public string $showId,
        public string $name,
        public array $seats,
    ) {
    }
}
