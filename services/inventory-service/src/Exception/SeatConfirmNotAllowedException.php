<?php

declare(strict_types=1);

namespace App\Exception;

final class SeatConfirmNotAllowedException extends \DomainException
{
    public function __construct(string $seatId, string $reservationId)
    {
        parent::__construct(sprintf(
            'Seat %s cannot be confirmed for reservation %s.',
            $seatId,
            $reservationId,
        ));
    }
}
