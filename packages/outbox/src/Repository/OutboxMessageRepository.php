<?php

declare(strict_types=1);

namespace Ticketing\Outbox\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ticketing\Outbox\Entity\OutboxMessage;

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
