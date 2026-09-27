<?php

declare(strict_types=1);

namespace App\Enum;

enum SeatState: string
{
    case Available = 'AVAILABLE';
    case Held = 'HELD';
    case Sold = 'SOLD';
}
