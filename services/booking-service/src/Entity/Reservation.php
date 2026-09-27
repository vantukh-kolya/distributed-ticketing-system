<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservations')]
#[ORM\UniqueConstraint(name: 'uniq_reservation_idempotency_key', columns: ['idempotency_key'])]
#[ORM\HasLifecycleCallbacks]
class Reservation
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'idempotency_key', length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'correlation_id', length: 36)]
    private string $correlationId;

    #[ORM\Column(name: 'show_id', length: 36)]
    private string $showId;

    /** @var list<string> */
    #[ORM\Column(name: 'seat_ids', type: Types::JSON)]
    private array $seatIds;

    #[ORM\Column(name: 'buyer_name', length: 255)]
    private string $buyerName;

    #[ORM\Column(name: 'buyer_email', length: 255)]
    private string $buyerEmail;

    #[ORM\Column(length: 32, enumType: ReservationStatus::class)]
    private ReservationStatus $status = ReservationStatus::Pending;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $seatIds
     */
    public function __construct(
        string $id,
        string $idempotencyKey,
        string $correlationId,
        string $showId,
        array $seatIds,
        string $buyerName,
        string $buyerEmail,
    ) {
        $this->id = $id;
        $this->idempotencyKey = $idempotencyKey;
        $this->correlationId = $correlationId;
        $this->showId = $showId;
        $this->seatIds = array_values(array_unique($seatIds));
        $this->buyerName = $buyerName;
        $this->buyerEmail = $buyerEmail;
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getShowId(): string
    {
        return $this->showId;
    }

    /** @return list<string> */
    public function getSeatIds(): array
    {
        return $this->seatIds;
    }

    public function getBuyerName(): string
    {
        return $this->buyerName;
    }

    public function getBuyerEmail(): string
    {
        return $this->buyerEmail;
    }

    public function getStatus(): ReservationStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markConfirmed(): void
    {
        $this->status = ReservationStatus::Confirmed;
    }

    public function markCancelled(): void
    {
        $this->status = ReservationStatus::Cancelled;
    }
}
