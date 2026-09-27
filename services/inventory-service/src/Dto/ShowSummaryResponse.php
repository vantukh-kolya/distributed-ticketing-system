<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ShowSummaryResponse
{
    public function __construct(
        public string $showId,
        public string $name,
        public int $totalSeats,
        public int $availableSeats,
    ) {
    }
}
