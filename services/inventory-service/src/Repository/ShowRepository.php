<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Show;
use App\Enum\SeatState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Show>
 */
final class ShowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Show::class);
    }

    /**
     * @return list<array{id: string, name: string, totalSeats: int|string, availableSeats: int|string}>
     */
    public function findCatalog(): array
    {
        /** @var list<array{id: string, name: string, totalSeats: int|string, availableSeats: int|string}> $rows */
        $rows = $this->createQueryBuilder('showEntity')
            ->select('showEntity.id', 'showEntity.name')
            ->addSelect('COUNT(seat.id) AS totalSeats')
            ->addSelect('SUM(CASE WHEN seat.state = :available THEN 1 ELSE 0 END) AS availableSeats')
            ->leftJoin('showEntity.seats', 'seat')
            ->setParameter('available', SeatState::Available)
            ->groupBy('showEntity.id', 'showEntity.name', 'showEntity.createdAt')
            ->orderBy('showEntity.createdAt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
