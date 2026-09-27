<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Seat;
use App\Entity\Show;
use App\Service\ShowCatalogService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ShowCatalogServiceTest extends KernelTestCase
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
    }

    public function testListsShowsWithTotalAndAvailableSeatCounts(): void
    {
        $show = new Show('show-catalog-' . Uuid::v7()->toRfc4122(), 'Catalog test show');
        $availableSeat = new Seat(Uuid::v7()->toRfc4122(), $show, 'A1');
        $heldSeat = new Seat(Uuid::v7()->toRfc4122(), $show, 'A2');
        $heldSeat->holdFor(Uuid::v7()->toRfc4122());

        $this->entityManager->persist($show);
        $this->entityManager->persist($availableSeat);
        $this->entityManager->persist($heldSeat);
        $this->entityManager->flush();

        /** @var ShowCatalogService $catalog */
        $catalog = static::getContainer()->get(ShowCatalogService::class);
        $result = $catalog->list();

        self::assertCount(1, $result);
        self::assertSame($show->getId(), $result[0]->showId);
        self::assertSame('Catalog test show', $result[0]->name);
        self::assertSame(2, $result[0]->totalSeats);
        self::assertSame(1, $result[0]->availableSeats);
    }
}
