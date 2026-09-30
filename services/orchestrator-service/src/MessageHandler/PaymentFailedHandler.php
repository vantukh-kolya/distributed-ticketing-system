<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Saga\SagaCoordinator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Event\PaymentFailed;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class PaymentFailedHandler
{
    public function __construct(private SagaCoordinator $sagaCoordinator)
    {
    }

    public function __invoke(PaymentFailed $event): void
    {
        $this->sagaCoordinator->handle($event);
    }
}
