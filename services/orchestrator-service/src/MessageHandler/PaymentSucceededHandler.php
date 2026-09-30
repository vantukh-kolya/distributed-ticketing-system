<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Saga\SagaCoordinator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\PaymentSucceeded;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class PaymentSucceededHandler
{
    public function __construct(private SagaCoordinator $sagaCoordinator)
    {
    }

    public function __invoke(PaymentSucceeded $event): void
    {
        $this->sagaCoordinator->handle($event);
    }
}
