<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Ticketing\Outbox\Repository\OutboxMessageRepository;

#[AsCommand(
    name: 'app:outbox:relay',
    description: 'Publish unpublished outbox rows to event bus',
)]
final class OutboxRelayCommand extends Command
{
    public function __construct(
        private readonly OutboxMessageRepository $outboxRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SerializerInterface $serializer,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max rows per run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $messages = $this->outboxRepository->findUnpublished($limit);

        if ($messages === []) {
            $io->success('No unpublished outbox messages.');

            return Command::SUCCESS;
        }

        $published = 0;
        foreach ($messages as $outbox) {
            $message = $this->serializer->deserialize(
                $outbox->getPayload(),
                $outbox->getEventType(),
                'json',
            );
            $this->eventBus->dispatch($message, [
                new AmqpStamp(
                    routingKey: $outbox->getRoutingKey(),
                    attributes: [
                        'message_id' => $outbox->getId(),
                        'correlation_id' => $outbox->getCorrelationId(),
                    ],
                ),
            ]);
            $outbox->markPublished();
            ++$published;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Published %d outbox message(s).', $published));

        return Command::SUCCESS;
    }
}
