<?php

declare(strict_types=1);

namespace App\Enum;

enum ReservationStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Cancelled = 'CANCELLED';
}
