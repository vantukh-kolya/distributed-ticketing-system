<?php

declare(strict_types=1);

namespace App\Exception;

final class SeatNotAvailableException extends \DomainException
{
    public function __construct(string $seatId)
    {
        parent::__construct(sprintf('Seat %s is not AVAILABLE.', $seatId));
    }
}
