<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ticketing\Contracts\Event\ReservationConfirmed;

final readonly class ReservationConfirmationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservationRepository $reservationRepository,
    ) {
    }

    public function onReservationConfirmed(ReservationConfirmed $event): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->confirmInCurrentTransaction($event);

            return;
        }

        $this->entityManager->wrapInTransaction(
            fn () => $this->confirmInCurrentTransaction($event),
        );
    }

    private function confirmInCurrentTransaction(ReservationConfirmed $event): void
    {
        $reservation = $this->reservationRepository->find($event->reservationId);
        if ($reservation === null || $reservation->getStatus() === ReservationStatus::Confirmed) {
            return;
        }

        $reservation->markConfirmed();
    }
}
