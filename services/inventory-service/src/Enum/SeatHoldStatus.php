<?php

declare(strict_types=1);

namespace App\Enum;

enum SeatHoldStatus: string
{
    case Active = 'ACTIVE';
    case Released = 'RELEASED';
    case Confirmed = 'CONFIRMED';
}
