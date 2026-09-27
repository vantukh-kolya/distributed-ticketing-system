<?php

declare(strict_types=1);

namespace App\Exception;

final class ReservationNotFoundException extends \DomainException
{
    public function __construct(string $reservationId)
    {
        parent::__construct(sprintf('Reservation "%s" was not found.', $reservationId));
    }
}
