<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeatHold;
use App\Enum\SeatHoldStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeatHold>
 */
final class SeatHoldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeatHold::class);
    }

    public function findActiveByReservationId(string $reservationId): ?SeatHold
    {
        /** @var SeatHold|null $hold */
        $hold = $this->createQueryBuilder('h')
            ->andWhere('h.reservationId = :reservationId')
            ->andWhere('h.status = :active')
            ->setParameter('reservationId', $reservationId)
            ->setParameter('active', SeatHoldStatus::Active)
            ->getQuery()
            ->getOneOrNullResult();

        return $hold;
    }

    public function findByReservationId(string $reservationId): ?SeatHold
    {
        /** @var SeatHold|null $hold */
        $hold = $this->findOneBy(['reservationId' => $reservationId]);

        return $hold;
    }
}
