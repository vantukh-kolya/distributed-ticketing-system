<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Seat;
use App\Entity\Show;
use App\Repository\ShowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:inventory:seed-seats',
    description: 'Insert Show (if missing) and AVAILABLE seats for local dev and tests',
)]
final class SeedSeatsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShowRepository $showRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('showId', InputArgument::REQUIRED, 'Show id')
            ->addArgument('seatCodes', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Seat codes (e.g. A1 A2)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Show display name', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $showId = (string) $input->getArgument('showId');
        /** @var list<string> $seatCodes */
        $seatCodes = $input->getArgument('seatCodes');
        $showName = $input->getOption('name') ?? $showId;

        $show = $this->showRepository->find($showId);
        if ($show === null) {
            $show = new Show($showId, $showName);
            $this->entityManager->persist($show);
            $io->note(sprintf('Created show %s (%s)', $showId, $showName));
        }

        foreach ($seatCodes as $seatCode) {
            $seat = new Seat(
                id: Uuid::v7()->toRfc4122(),
                show: $show,
                seatCode: $seatCode,
            );
            $this->entityManager->persist($seat);
            $io->writeln(sprintf('  %s  id=%s', $seatCode, $seat->getId()));
        }

        $this->entityManager->flush();

        $io->success(sprintf('Seeded %d seat(s) for show %s. Use printed ids in booking seatIds.', \count($seatCodes), $showId));

        return Command::SUCCESS;
    }
}
