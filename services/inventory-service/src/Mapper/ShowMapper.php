<?php

declare(strict_types=1);

namespace App\Mapper;

use App\Dto\ShowSummaryResponse;

final class ShowMapper
{
    /**
     * @param array{id: string, name: string, totalSeats: int|string, availableSeats: int|string} $row
     */
    public function toSummary(array $row): ShowSummaryResponse
    {
        return new ShowSummaryResponse(
            showId: $row['id'],
            name: $row['name'],
            totalSeats: (int) $row['totalSeats'],
            availableSeats: (int) $row['availableSeats'],
        );
    }
}
