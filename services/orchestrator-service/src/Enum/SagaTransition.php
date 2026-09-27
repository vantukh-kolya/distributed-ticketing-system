<?php

declare(strict_types=1);

namespace App\Enum;

enum SagaTransition: string
{
    case SeatsHeld = 'seats_held';
    case SeatHoldRejected = 'seat_hold_rejected';
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case SeatsReleased = 'seats_released';
    case SeatsConfirmed = 'seats_confirmed';
}
