<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\OutboxMessage;
use App\Gateway\GatewayPaymentRequest;
use App\Gateway\GatewayPaymentResult;
use App\Gateway\PaymentGatewayInterface;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\ProcessPayment;

final class ProcessPaymentIdempotencyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        $this->createInboxTable();
    }

    public function testDuplicateTransportMessageIsHandledOnce(): void
    {
        $gateway = new class implements PaymentGatewayInterface {
            public int $calls = 0;

            public function process(GatewayPaymentRequest $request): GatewayPaymentResult
            {
                ++$this->calls;

                return GatewayPaymentResult::succeeded('pay_test_1');
            }
        };

        $container = static::getContainer();
        $container->set(PaymentGatewayInterface::class, $gateway);

        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('command.bus');
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $container->get(PaymentRepository::class);

        $command = new ProcessPayment(
            reservationId: Uuid::v7()->toRfc4122(),
            showId: 'show-1',
            correlationId: Uuid::v7()->toRfc4122(),
            amountMinor: 5000,
            currency: 'UAH',
            paymentMethodToken: 'tok_fake_visa',
        );
        $messageId = Uuid::v7()->toRfc4122();

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $commandBus->dispatch(new Envelope($command, [
                new ReceivedStamp('inbound'),
                new TransportMessageIdStamp($messageId),
            ]));
        }

        self::assertSame(1, $gateway->calls);
        self::assertNotNull($paymentRepository->findByReservationId($command->reservationId));
        self::assertTrue($this->inboxMessageExists($messageId));
        self::assertSame(1, (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(OutboxMessage::class, 'o')
            ->getQuery()
            ->getSingleScalarResult());
    }

    public function testInboxClaimRollsBackWhenHandlerFails(): void
    {
        $gateway = new class implements PaymentGatewayInterface {
            public int $calls = 0;

            public function process(GatewayPaymentRequest $request): GatewayPaymentResult
            {
                ++$this->calls;
                if ($this->calls === 1) {
                    throw new \RuntimeException('Temporary gateway failure');
                }

                return GatewayPaymentResult::succeeded('pay_test_retry');
            }
        };

        $container = static::getContainer();
        $container->set(PaymentGatewayInterface::class, $gateway);

        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('command.bus');
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $container->get(PaymentRepository::class);

        $command = new ProcessPayment(
            reservationId: Uuid::v7()->toRfc4122(),
            showId: 'show-1',
            correlationId: Uuid::v7()->toRfc4122(),
            amountMinor: 5000,
            currency: 'UAH',
            paymentMethodToken: 'tok_fake_visa',
        );
        $messageId = Uuid::v7()->toRfc4122();
        $envelope = new Envelope($command, [
            new ReceivedStamp('inbound'),
            new TransportMessageIdStamp($messageId),
        ]);

        try {
            $commandBus->dispatch($envelope);
            self::fail('The first gateway attempt must fail.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }

        $this->entityManager->clear();

        self::assertFalse($this->inboxMessageExists($messageId));
        self::assertNull($paymentRepository->findByReservationId($command->reservationId));

        $commandBus->dispatch($envelope);

        self::assertSame(2, $gateway->calls);
        self::assertTrue($this->inboxMessageExists($messageId));
        self::assertNotNull($paymentRepository->findByReservationId($command->reservationId));
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
                'consumerName' => 'process_payment',
                'messageId' => $messageId,
            ],
        ) !== false;
    }
}
