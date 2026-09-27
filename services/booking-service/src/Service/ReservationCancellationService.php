<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ticketing\Contracts\Event\ReservationCancelled;

final readonly class ReservationCancellationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservationRepository $reservationRepository,
    ) {
    }

    public function onReservationCancelled(ReservationCancelled $event): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->cancelInCurrentTransaction($event);

            return;
        }

        $this->entityManager->wrapInTransaction(
            fn () => $this->cancelInCurrentTransaction($event),
        );
    }

    private function cancelInCurrentTransaction(ReservationCancelled $event): void
    {
        $reservation = $this->reservationRepository->find($event->reservationId);
        if ($reservation === null || $reservation->getStatus() !== ReservationStatus::Pending) {
            return;
        }

        $reservation->markCancelled();
    }
}
