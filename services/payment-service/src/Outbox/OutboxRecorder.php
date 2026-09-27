<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\OutboxMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class OutboxRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private OutboxRoutingKeyResolver $routingKeyResolver,
    ) {
    }

    public function record(object $event, string $reservationId, string $correlationId): void
    {
        $message = new OutboxMessage(
            id: Uuid::v7()->toRfc4122(),
            eventType: $event::class,
            payload: $this->serializer->serialize($event, 'json'),
            correlationId: $correlationId,
            reservationId: $reservationId,
            routingKey: $this->routingKeyResolver->resolve($event),
        );

        $this->entityManager->persist($message);
    }
}
