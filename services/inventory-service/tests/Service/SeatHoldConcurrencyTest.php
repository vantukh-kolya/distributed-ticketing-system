<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Seat;
use App\Entity\Show;
use App\Enum\SeatHoldStatus;
use App\Enum\SeatState;
use App\Repository\SeatHoldRepository;
use App\Service\SeatHoldService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\HoldSeats;

final class SeatHoldConcurrencyTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testOnlyOneHoldSucceedsForSingleSeat(): void
    {
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var SeatHoldService $holdService */
        $holdService = $container->get(SeatHoldService::class);
        /** @var SeatHoldRepository $holdRepository */
        $holdRepository = $container->get(SeatHoldRepository::class);

        $show = new Show('show-concurrency-' . Uuid::v7()->toRfc4122(), 'Concurrency test show');
        $seatId = Uuid::v7()->toRfc4122();
        $em->persist($show);
        $em->persist(new Seat(
            id: $seatId,
            show: $show,
            seatCode: 'A1',
        ));
        $em->flush();
        $showId = $show->getId();

        $attempts = 20;
        for ($i = 0; $i < $attempts; ++$i) {
            $holdService->hold(new HoldSeats(
                reservationId: Uuid::v7()->toRfc4122(),
                showId: $showId,
                seatIds: [$seatId],
                correlationId: Uuid::v7()->toRfc4122(),
            ));
        }

        $activeHolds = $holdRepository->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->andWhere('h.show = :show')
            ->andWhere('h.status = :active')
            ->setParameter('show', $show)
            ->setParameter('active', SeatHoldStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();

        $heldSeats = $em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Seat::class, 's')
            ->andWhere('s.show = :show')
            ->andWhere('s.state = :held')
            ->setParameter('show', $show)
            ->setParameter('held', SeatState::Held)
            ->getQuery()
            ->getSingleScalarResult();

        self::assertSame(1, (int) $activeHolds, 'Exactly one active seat hold row expected');
        self::assertSame(1, (int) $heldSeats, 'Exactly one seat must remain HELD');
    }
}
