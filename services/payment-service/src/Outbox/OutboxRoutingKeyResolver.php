<?php

declare(strict_types=1);

namespace App\Outbox;

use Ticketing\Contracts\Event\PaymentFailed;
use Ticketing\Contracts\Event\PaymentSucceeded;
use Ticketing\Outbox\RoutingKeyResolverInterface;

final class OutboxRoutingKeyResolver implements RoutingKeyResolverInterface
{
    public function resolve(object $message): string
    {
        return match ($message::class) {
            PaymentSucceeded::class => 'payment.succeeded',
            PaymentFailed::class => 'payment.failed',
            default => throw new \InvalidArgumentException(sprintf(
                'No outbox routing key configured for message "%s".',
                $message::class,
            )),
        };
    }
}
