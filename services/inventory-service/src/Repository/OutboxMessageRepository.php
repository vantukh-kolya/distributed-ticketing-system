<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OutboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboxMessage>
 */
final class OutboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboxMessage::class);
    }

    /**
     * @return list<OutboxMessage>
     */
    public function findUnpublished(int $limit = 100): array
    {
        /** @var list<OutboxMessage> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.publishedAt IS NULL')
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
