<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\UniqueConstraint(name: 'uniq_payment_reservation', columns: ['reservation_id'])]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'reservation_id', length: 36)]
    private string $reservationId;

    #[ORM\Column(name: 'correlation_id', length: 36)]
    private string $correlationId;

    #[ORM\Column(name: 'show_id', length: 36)]
    private string $showId;

    #[ORM\Column(name: 'amount_minor')]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 32, enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\Column(length: 32)]
    private string $gateway;

    #[ORM\Column(name: 'gateway_payment_id', length: 64, nullable: true)]
    private ?string $gatewayPaymentId = null;

    #[ORM\Column(name: 'payment_method_token', length: 128)]
    private string $paymentMethodToken;

    #[ORM\Column(name: 'failure_reason', length: 64, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(name: 'paid_at', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'failed_at', nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $reservationId,
        string $correlationId,
        string $showId,
        int $amountMinor,
        string $currency,
        string $gateway,
        string $paymentMethodToken,
    ) {
        $this->id = $id;
        $this->reservationId = $reservationId;
        $this->correlationId = $correlationId;
        $this->showId = $showId;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->gateway = $gateway;
        $this->paymentMethodToken = $paymentMethodToken;
        $this->status = PaymentStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getReservationId(): string
    {
        return $this->reservationId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getShowId(): string
    {
        return $this->showId;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function markPaid(string $gatewayPaymentId, \DateTimeImmutable $at): void
    {
        $this->status = PaymentStatus::Paid;
        $this->gatewayPaymentId = $gatewayPaymentId;
        $this->paidAt = $at;
        $this->updatedAt = $at;
    }

    public function markFailed(string $reason, \DateTimeImmutable $at): void
    {
        $this->status = PaymentStatus::Failed;
        $this->failureReason = $reason;
        $this->failedAt = $at;
        $this->updatedAt = $at;
    }
}
