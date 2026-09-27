<?php

declare(strict_types=1);

namespace App\Outbox;

use Ticketing\Contracts\Event\PaymentFailed;
use Ticketing\Contracts\Event\PaymentSucceeded;

final class OutboxRoutingKeyResolver
{
    public function resolve(object $event): string
    {
        return match ($event::class) {
            PaymentSucceeded::class => 'payment.succeeded',
            PaymentFailed::class => 'payment.failed',
            default => throw new \InvalidArgumentException(sprintf(
                'No outbox routing key configured for message "%s".',
                $event::class,
            )),
        };
    }
}
