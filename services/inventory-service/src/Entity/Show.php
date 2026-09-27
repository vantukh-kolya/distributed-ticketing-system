<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShowRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShowRepository::class)]
#[ORM\Table(name: 'shows')]
class Show
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Seat> */
    #[ORM\OneToMany(mappedBy: 'show', targetEntity: Seat::class)]
    private Collection $seats;

    /** @var Collection<int, SeatHold> */
    #[ORM\OneToMany(mappedBy: 'show', targetEntity: SeatHold::class)]
    private Collection $seatHolds;

    public function __construct(string $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->seats = new ArrayCollection();
        $this->seatHolds = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Seat>
     */
    public function getSeats(): Collection
    {
        return $this->seats;
    }

    /**
     * @return Collection<int, SeatHold>
     */
    public function getSeatHolds(): Collection
    {
        return $this->seatHolds;
    }
}
