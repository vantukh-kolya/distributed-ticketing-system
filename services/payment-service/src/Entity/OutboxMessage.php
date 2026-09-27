<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OutboxMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OutboxMessageRepository::class)]
#[ORM\Table(name: 'outbox_messages')]
#[ORM\Index(name: 'idx_outbox_unpublished', columns: ['published_at', 'created_at'])]
class OutboxMessage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'event_type', length: 255)]
    private string $eventType;

    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    #[ORM\Column(name: 'correlation_id', length: 36)]
    private string $correlationId;

    #[ORM\Column(name: 'reservation_id', length: 36)]
    private string $reservationId;

    #[ORM\Column(name: 'routing_key', length: 255)]
    private string $routingKey;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'published_at', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct(
        string $id,
        string $eventType,
        string $payload,
        string $correlationId,
        string $reservationId,
        string $routingKey,
    ) {
        $this->id = $id;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->correlationId = $correlationId;
        $this->reservationId = $reservationId;
        $this->routingKey = $routingKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getReservationId(): string
    {
        return $this->reservationId;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function markPublished(\DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $this->publishedAt = $at;
    }
}
