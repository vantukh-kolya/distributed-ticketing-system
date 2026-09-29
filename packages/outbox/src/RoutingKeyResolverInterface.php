<?php

declare(strict_types=1);

namespace Ticketing\Outbox;

interface RoutingKeyResolverInterface
{
    /**
     * @throws \InvalidArgumentException when the message has no configured route
     */
    public function resolve(object $message): string;
}
