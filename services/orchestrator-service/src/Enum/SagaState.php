<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Persisted saga states. Allowed transitions are defined in workflow.yaml.
 *
 * @see docs/architecture.md §4
 */
enum SagaState: string
{
    case AwaitingSeats = 'AWAITING_SEATS';
    case SeatsHeld = 'SEATS_HELD';
    case AwaitingPayment = 'AWAITING_PAYMENT';
    case PaymentPaid = 'PAYMENT_PAID';
    case Compensating = 'COMPENSATING';
    case Cancelled = 'CANCELLED';
    case Confirmed = 'CONFIRMED';
}
