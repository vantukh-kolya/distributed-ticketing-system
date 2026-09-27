<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\SeatConfirmNotAllowedException;
use App\Exception\SeatNotAvailableException;
use App\Exception\SeatReleaseNotAllowedException;
use App\Enum\SeatState;
use App\Repository\SeatRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeatRepository::class)]
#[ORM\Table(name: 'seats')]
#[ORM\UniqueConstraint(name: 'uniq_seat_show', columns: ['show_id', 'seat_code'])]
class Seat
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Show::class, inversedBy: 'seats')]
    #[ORM\JoinColumn(name: 'show_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Show $show;

    #[ORM\Column(name: 'seat_code', length: 32)]
    private string $seatCode;

    #[ORM\Column(length: 16, enumType: SeatState::class)]
    private SeatState $state;

    #[ORM\Column(name: 'held_by_reservation_id', length: 36, nullable: true)]
    private ?string $heldByReservationId = null;

    public function __construct(string $id, Show $show, string $seatCode)
    {
        $this->id = $id;
        $this->show = $show;
        $this->seatCode = $seatCode;
        $this->state = SeatState::Available;
    }

    public function isAvailable(): bool
    {
        return $this->state === SeatState::Available;
    }

    public function isHeldBy(string $reservationId): bool
    {
        return $this->state === SeatState::Held
            && $this->heldByReservationId === $reservationId;
    }

    public function holdFor(string $reservationId): void
    {
        if (!$this->isAvailable()) {
            throw new SeatNotAvailableException($this->id);
        }

        $this->state = SeatState::Held;
        $this->heldByReservationId = $reservationId;
    }

    public function releaseFor(string $reservationId): void
    {
        if (!$this->isHeldBy($reservationId)) {
            throw new SeatReleaseNotAllowedException($this->id, $reservationId);
        }

        $this->state = SeatState::Available;
        $this->heldByReservationId = null;
    }

    public function confirmFor(string $reservationId): void
    {
        if (!$this->isHeldBy($reservationId)) {
            throw new SeatConfirmNotAllowedException($this->id, $reservationId);
        }

        $this->state = SeatState::Sold;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShow(): Show
    {
        return $this->show;
    }

    public function getShowId(): string
    {
        return $this->show->getId();
    }

    public function getSeatCode(): string
    {
        return $this->seatCode;
    }

    public function getState(): SeatState
    {
        return $this->state;
    }

    public function getHeldByReservationId(): ?string
    {
        return $this->heldByReservationId;
    }
}
