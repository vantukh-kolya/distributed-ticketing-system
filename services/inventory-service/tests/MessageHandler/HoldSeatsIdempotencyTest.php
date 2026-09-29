<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Seat;
use App\Entity\SeatHold;
use App\Entity\Show;
use App\Enum\SeatState;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\HoldSeats;
use Ticketing\Outbox\Entity\OutboxMessage;

final class HoldSeatsIdempotencyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        $this->createInboxTable();
    }

    public function testDuplicateTransportMessageIsHandledOnce(): void
    {
        $show = new Show(Uuid::v7()->toRfc4122(), 'Inbox test show');
        $seat = new Seat(Uuid::v7()->toRfc4122(), $show, 'A1');
        $this->entityManager->persist($show);
        $this->entityManager->persist($seat);
        $this->entityManager->flush();

        $command = new HoldSeats(
            reservationId: Uuid::v7()->toRfc4122(),
            showId: $show->getId(),
            seatIds: [$seat->getId()],
            correlationId: Uuid::v7()->toRfc4122(),
        );
        $messageId = Uuid::v7()->toRfc4122();
        $envelope = new Envelope($command, [
            new ReceivedStamp('inbound'),
            new TransportMessageIdStamp($messageId),
        ]);

        /** @var MessageBusInterface $commandBus */
        $commandBus = static::getContainer()->get('command.bus');
        $commandBus->dispatch($envelope);
        $commandBus->dispatch($envelope);

        self::assertSame(SeatState::Held, $seat->getState());
        self::assertSame(1, $this->countEntities(SeatHold::class));
        self::assertSame(1, $this->countEntities(OutboxMessage::class));
        self::assertTrue($this->inboxMessageExists($messageId));
    }

    /** @param class-string $entityClass */
    private function countEntities(string $entityClass): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(entity)')
            ->from($entityClass, 'entity')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createInboxTable(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS inbox_messages');
        $connection->executeStatement(
            'CREATE TABLE inbox_messages (consumer_name VARCHAR(64) NOT NULL, message_id VARCHAR(36) NOT NULL, message_type VARCHAR(255) NOT NULL, processed_at TIMESTAMP NOT NULL, PRIMARY KEY (consumer_name, message_id))',
        );
    }

    private function inboxMessageExists(string $messageId): bool
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM inbox_messages WHERE consumer_name = :consumerName AND message_id = :messageId',
            [
                'consumerName' => 'inventory_commands',
                'messageId' => $messageId,
            ],
        ) !== false;
    }
}
