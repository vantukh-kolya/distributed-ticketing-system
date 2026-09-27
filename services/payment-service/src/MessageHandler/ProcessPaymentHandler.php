<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\PaymentService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ticketing\Contracts\Command\ProcessPayment;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ProcessPaymentHandler
{
    public function __construct(
        private PaymentService $paymentService,
    ) {
    }

    public function __invoke(ProcessPayment $command): void
    {
        $this->paymentService->process($command);
    }
}
