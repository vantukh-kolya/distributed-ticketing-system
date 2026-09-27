<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SagaState;
use App\Repository\SagaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Process-manager state for one reservation saga (orchestrator DB only).
 */
#[ORM\Entity(repositoryClass: SagaRepository::class)]
#[ORM\Table(name: 'sagas')]
#[ORM\UniqueConstraint(name: 'uniq_saga_reservation', columns: ['reservation_id'])]
class Saga
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

    /** @var list<string> */
    #[ORM\Column(name: 'seat_ids', type: Types::JSON)]
    private array $seatIds;

    #[ORM\Column(length: 32, enumType: SagaState::class)]
    private SagaState $state;

    #[ORM\Column(name: 'failure_reason', length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $seatIds
     */
    public function __construct(
        string $id,
        string $reservationId,
        string $correlationId,
        string $showId,
        array $seatIds,
        SagaState $state,
    ) {
        $this->id = $id;
        $this->reservationId = $reservationId;
        $this->correlationId = $correlationId;
        $this->showId = $showId;
        $this->seatIds = array_values($seatIds);
        $this->state = $state;
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

    /**
     * @return list<string>
     */
    public function getSeatIds(): array
    {
        return $this->seatIds;
    }

    public function getState(): SagaState
    {
        return $this->state;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function recordFailure(string $reason): void
    {
        $this->failureReason = $reason;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setState(SagaState $newState): void
    {
        $this->state = $newState;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
