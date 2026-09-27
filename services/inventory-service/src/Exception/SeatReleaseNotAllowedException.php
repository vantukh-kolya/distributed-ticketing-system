<?php

declare(strict_types=1);

namespace App\Exception;

final class SeatReleaseNotAllowedException extends \DomainException
{
    public function __construct(string $seatId, string $reservationId)
    {
        parent::__construct(sprintf(
            'Seat %s cannot be released for reservation %s.',
            $seatId,
            $reservationId,
        ));
    }
}
