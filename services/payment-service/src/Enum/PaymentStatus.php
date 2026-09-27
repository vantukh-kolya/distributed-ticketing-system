<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
}
