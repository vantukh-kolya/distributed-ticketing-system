<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use App\Enum\PaymentStatus;
use App\Gateway\GatewayPaymentRequest;
use App\Gateway\PaymentGatewayInterface;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\ProcessPayment;
use Ticketing\Contracts\Event\PaymentFailed;
use Ticketing\Contracts\Event\PaymentSucceeded;
use Ticketing\Outbox\OutboxRecorder;

final readonly class PaymentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentRepository $paymentRepository,
        private PaymentGatewayInterface $paymentGateway,
        private OutboxRecorder $outboxRecorder,
        private string $gatewayName,
    ) {
    }

    public function process(ProcessPayment $command): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->processInCurrentTransaction($command);

            return;
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($command): void {
                $this->processInCurrentTransaction($command);
            });
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();
        }
    }

    private function processInCurrentTransaction(ProcessPayment $command): void
    {
        $payment = $this->paymentRepository->findByReservationId($command->reservationId);
        if ($payment !== null && $payment->getStatus() !== PaymentStatus::Pending) {
            return;
        }

        if ($payment === null) {
            $payment = new Payment(
                id: Uuid::v7()->toRfc4122(),
                reservationId: $command->reservationId,
                correlationId: $command->correlationId,
                showId: $command->showId,
                amountMinor: $command->amountMinor,
                currency: $command->currency,
                gateway: $this->gatewayName,
                paymentMethodToken: $command->paymentMethodToken,
            );
            $this->entityManager->persist($payment);
        }

        $result = $this->paymentGateway->process(new GatewayPaymentRequest(
            idempotencyKey: $command->reservationId,
            amountMinor: $command->amountMinor,
            currency: $command->currency,
            paymentMethodToken: $command->paymentMethodToken,
        ));

        $now = new \DateTimeImmutable();
        if ($result->succeeded) {
            $gatewayPaymentId = $result->paymentId
                ?? throw new \LogicException('Successful payment result must contain a payment id.');
            $payment->markPaid($gatewayPaymentId, $now);
            $event = new PaymentSucceeded(
                paymentId: $payment->getId(),
                reservationId: $command->reservationId,
                correlationId: $command->correlationId,
                amountMinor: $command->amountMinor,
                currency: $command->currency,
                paidAt: $now,
            );
        } else {
            $reason = $result->failureReason ?? 'PAYMENT_FAILED';
            $payment->markFailed($reason, $now);
            $event = new PaymentFailed(
                paymentId: $payment->getId(),
                reservationId: $command->reservationId,
                correlationId: $command->correlationId,
                reason: $reason,
                failedAt: $now,
            );
        }

        $this->outboxRecorder->record(
            $event,
            reservationId: $command->reservationId,
            correlationId: $command->correlationId,
        );
    }
}
