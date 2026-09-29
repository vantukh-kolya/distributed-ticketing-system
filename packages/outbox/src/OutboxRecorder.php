<?php

declare(strict_types=1);

namespace Ticketing\Outbox;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Ticketing\Outbox\Entity\OutboxMessage;

/**
 * Records outgoing commands and events in the caller's transaction.
 */
final readonly class OutboxRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private RoutingKeyResolverInterface $routingKeyResolver,
    ) {
    }

    public function record(object $message, string $reservationId, string $correlationId): void
    {
        $this->entityManager->persist(new OutboxMessage(
            id: Uuid::v7()->toRfc4122(),
            eventType: $message::class,
            payload: $this->serializer->serialize($message, 'json'),
            reservationId: $reservationId,
            correlationId: $correlationId,
            routingKey: $this->routingKeyResolver->resolve($message),
        ));
    }
}
