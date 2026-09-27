<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Seat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Seat>
 */
final class SeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seat::class);
    }

    /** @return list<Seat> */
    public function findByShowOrdered(string $showId): array
    {
        /** @var list<Seat> $seats */
        $seats = $this->createQueryBuilder('s')
            ->andWhere('IDENTITY(s.show) = :showId')
            ->setParameter('showId', $showId)
            ->orderBy('s.seatCode', 'ASC')
            ->getQuery()
            ->getResult();

        return $seats;
    }

    /**
     * Locks seats in stable id order (caller must pass sorted unique ids).
     *
     * @param non-empty-list<string> $sortedSeatIds
     *
     * @return list<Seat> one row per found id, in the same order as $sortedSeatIds
     */
    public function findForUpdateByShowAndIds(string $showId, array $sortedSeatIds): array
    {
        $seats = [];
        foreach ($sortedSeatIds as $seatId) {
            $seat = $this->findOneForUpdate($showId, $seatId);
            if ($seat !== null) {
                $seats[] = $seat;
            }
        }

        return $seats;
    }

    private function findOneForUpdate(string $showId, string $seatId): ?Seat
    {
        /** @var Seat|null $seat */
        $seat = $this->createQueryBuilder('s')
            ->andWhere('s.id = :seatId')
            ->andWhere('IDENTITY(s.show) = :showId')
            ->setParameter('seatId', $seatId)
            ->setParameter('showId', $showId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $seat;
    }
}
