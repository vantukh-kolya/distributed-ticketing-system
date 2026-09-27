<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Saga;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Saga>
 */
final class SagaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Saga::class);
    }

    public function findByReservationId(string $reservationId): ?Saga
    {
        /** @var Saga|null $saga */
        $saga = $this->createQueryBuilder('s')
            ->andWhere('s.reservationId = :reservationId')
            ->setParameter('reservationId', $reservationId)
            ->getQuery()
            ->getOneOrNullResult();

        return $saga;
    }

    /**
     * Must run inside the transaction that persists the transition and its outbox.
     * Refresh managed state: another transaction may have advanced it while we waited.
     */
    public function findForUpdateByReservationId(string $reservationId): ?Saga
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.reservationId = :reservationId')
            ->setParameter('reservationId', $reservationId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}
