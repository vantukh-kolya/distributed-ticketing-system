<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SeatHoldStatus;
use App\Repository\SeatHoldRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeatHoldRepository::class)]
#[ORM\Table(name: 'seat_holds')]
class SeatHold
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'reservation_id', length: 36, unique: true)]
    private string $reservationId;

    #[ORM\ManyToOne(targetEntity: Show::class, inversedBy: 'seatHolds')]
    #[ORM\JoinColumn(name: 'show_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Show $show;

    /** @var list<string> */
    #[ORM\Column(name: 'seat_ids', type: Types::JSON)]
    private array $seatIds;

    #[ORM\Column(length: 16, enumType: SeatHoldStatus::class)]
    private SeatHoldStatus $status;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $seatIds inventory seat UUIDs
     */
    public function __construct(
        string $id,
        string $reservationId,
        Show $show,
        array $seatIds,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = $id;
        $this->reservationId = $reservationId;
        $this->show = $show;
        $this->seatIds = array_values($seatIds);
        $this->status = SeatHoldStatus::Active;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getReservationId(): string
    {
        return $this->reservationId;
    }

    public function getShow(): Show
    {
        return $this->show;
    }

    public function getShowId(): string
    {
        return $this->show->getId();
    }

    /**
     * @return list<string>
     */
    public function getSeatIds(): array
    {
        return $this->seatIds;
    }

    public function getStatus(): SeatHoldStatus
    {
        return $this->status;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(): bool
    {
        return $this->status === SeatHoldStatus::Active;
    }

    public function markReleased(): void
    {
        $this->status = SeatHoldStatus::Released;
    }

    public function markConfirmed(): void
    {
        $this->status = SeatHoldStatus::Confirmed;
    }
}
