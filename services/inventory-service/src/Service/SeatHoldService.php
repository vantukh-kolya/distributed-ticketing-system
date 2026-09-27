<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SeatHold;
use App\Enum\SeatHoldStatus;
use App\Enum\SeatLockResult;
use App\Outbox\OutboxRecorder;
use App\Repository\SeatHoldRepository;
use App\Repository\ShowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Command\ConfirmSeats;
use Ticketing\Contracts\Command\HoldSeats;
use Ticketing\Contracts\Command\ReleaseSeats;
use Ticketing\Contracts\Event\SeatHoldRejected;
use Ticketing\Contracts\Event\SeatsConfirmed;
use Ticketing\Contracts\Event\SeatsHeld;
use Ticketing\Contracts\Event\SeatsReleased;

/**
 * HoldSeats / ReleaseSeats use-cases: idempotency, SeatHold aggregate, outbox.
 */
final readonly class SeatHoldService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShowRepository $showRepository,
        private SeatHoldRepository $seatHoldRepository,
        private SeatLockingService $seatLockingService,
        private OutboxRecorder $outboxRecorder,
        private int $holdTtlMinutes = 15,
    ) {
    }

    public function hold(HoldSeats $command): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->holdInCurrentTransaction($command);

            return;
        }

        $this->entityManager->wrapInTransaction(
            fn () => $this->holdInCurrentTransaction($command),
        );
    }

    public function confirm(ConfirmSeats $command): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->confirmInCurrentTransaction($command);

            return;
        }

        $this->entityManager->wrapInTransaction(
            fn () => $this->confirmInCurrentTransaction($command),
        );
    }

    public function release(ReleaseSeats $command): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->releaseInCurrentTransaction($command);

            return;
        }

        $this->entityManager->wrapInTransaction(
            fn () => $this->releaseInCurrentTransaction($command),
        );
    }

    private function holdInCurrentTransaction(HoldSeats $command): void
    {
        if ($this->seatHoldRepository->findActiveByReservationId($command->reservationId) !== null) {
            return;
        }

        $seatIds = $this->seatLockingService->normalizeSeatIds($command->seatIds);
        if ($seatIds === []) {
            $this->recordHoldRejected($command, 'No seat ids provided');

            return;
        }

        $show = $this->showRepository->find($command->showId);
        if ($show === null) {
            $this->recordHoldRejected($command, 'Show not found in inventory catalog');

            return;
        }

        $lockResult = $this->seatLockingService->attemptHold(
            $command->showId,
            $command->reservationId,
            $seatIds,
        );

        if ($lockResult !== SeatLockResult::Success) {
            $this->recordHoldRejected($command, $this->holdRejectionReason($lockResult));

            return;
        }

        $expiresAt = new \DateTimeImmutable(sprintf('+%d minutes', $this->holdTtlMinutes));
        $this->entityManager->persist(new SeatHold(
            id: Uuid::v7()->toRfc4122(),
            reservationId: $command->reservationId,
            show: $show,
            seatIds: $seatIds,
            expiresAt: $expiresAt,
        ));

        $this->outboxRecorder->record(
            new SeatsHeld(
                reservationId: $command->reservationId,
                showId: $command->showId,
                seatIds: $seatIds,
                correlationId: $command->correlationId,
                expiresAt: $expiresAt,
            ),
            reservationId: $command->reservationId,
            correlationId: $command->correlationId,
        );
    }

    private function confirmInCurrentTransaction(ConfirmSeats $command): void
    {
        $hold = $this->seatHoldRepository->findByReservationId($command->reservationId);
        if ($hold === null || $hold->getStatus() === SeatHoldStatus::Confirmed || !$hold->isActive()) {
            return;
        }

        $seatIds = $this->seatLockingService->normalizeSeatIds($command->seatIds);
        if ($seatIds === [] || $this->showRepository->find($command->showId) === null) {
            return;
        }

        if ($this->seatLockingService->attemptConfirm(
            $command->showId,
            $command->reservationId,
            $seatIds,
        ) !== SeatLockResult::Success) {
            return;
        }

        $hold->markConfirmed();

        $this->outboxRecorder->record(
            new SeatsConfirmed(
                reservationId: $command->reservationId,
                showId: $command->showId,
                seatIds: $seatIds,
                correlationId: $command->correlationId,
                confirmedAt: new \DateTimeImmutable(),
            ),
            reservationId: $command->reservationId,
            correlationId: $command->correlationId,
        );
    }

    private function releaseInCurrentTransaction(ReleaseSeats $command): void
    {
        $hold = $this->seatHoldRepository->findActiveByReservationId($command->reservationId);
        if ($hold === null) {
            return;
        }

        $seatIds = $this->seatLockingService->normalizeSeatIds($command->seatIds);
        if ($seatIds === [] || $this->showRepository->find($command->showId) === null) {
            return;
        }

        if ($this->seatLockingService->attemptRelease(
            $command->showId,
            $command->reservationId,
            $seatIds,
        ) !== SeatLockResult::Success) {
            return;
        }

        $hold->markReleased();

        $this->outboxRecorder->record(
            new SeatsReleased(
                reservationId: $command->reservationId,
                showId: $command->showId,
                seatIds: $seatIds,
                correlationId: $command->correlationId,
            ),
            reservationId: $command->reservationId,
            correlationId: $command->correlationId,
        );
    }

    private function holdRejectionReason(SeatLockResult $result): string
    {
        return match ($result) {
            SeatLockResult::SeatsNotFound => 'One or more seats were not found for this show',
            SeatLockResult::NotAllAvailable => 'One or more seats are not AVAILABLE',
            default => throw new \LogicException(sprintf('Unexpected hold lock result: %s', $result->name)),
        };
    }

    private function recordHoldRejected(HoldSeats $command, string $reason): void
    {
        $this->outboxRecorder->record(
            new SeatHoldRejected(
                reservationId: $command->reservationId,
                showId: $command->showId,
                correlationId: $command->correlationId,
                reason: $reason,
            ),
            reservationId: $command->reservationId,
            correlationId: $command->correlationId,
        );
    }
}
