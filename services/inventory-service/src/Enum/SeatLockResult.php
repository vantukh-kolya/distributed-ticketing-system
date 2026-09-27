<?php

declare(strict_types=1);

namespace App\Enum;

enum SeatLockResult
{
    case Success;
    case SeatsNotFound;
    case NotAllAvailable;
    case NotAllHeldByReservation;
}
