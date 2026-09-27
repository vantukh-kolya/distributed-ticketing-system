<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class SagaResponse
{
    /** @param list<string> $seatIds */
    public function __construct(
        public string $reservationId,
        public string $correlationId,
        public string $showId,
        public array $seatIds,
        public string $state,
        public ?string $failureReason,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
