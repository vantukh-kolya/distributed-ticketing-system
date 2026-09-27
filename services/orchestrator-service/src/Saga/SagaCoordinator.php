<?php

declare(strict_types=1);

namespace App\Saga;

use App\Entity\Saga;
use App\Enum\SagaState;
use App\Enum\SagaTransition;
use App\Outbox\OutboxRecorder;
use App\Repository\SagaRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;
use Ticketing\Contracts\Command\ConfirmSeats;
use Ticketing\Contracts\Command\HoldSeats;
use Ticketing\Contracts\Command\ProcessPayment;
use Ticketing\Contracts\Command\ReleaseSeats;
use Ticketing\Contracts\Event\PaymentFailed;
use Ticketing\Contracts\Event\PaymentSucceeded;
use Ticketing\Contracts\Event\ReservationCancelled;
use Ticketing\Contracts\Event\ReservationConfirmed;
use Ticketing\Contracts\Event\ReservationRequested;
use Ticketing\Contracts\Event\SeatHoldRejected;
use Ticketing\Contracts\Event\SeatsConfirmed;
use Ticketing\Contracts\Event\SeatsHeld;
use Ticketing\Contracts\Event\SeatsReleased;

final readonly class SagaCoordinator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SagaRepository         $sagaRepository,
        private OutboxRecorder         $outboxRecorder,
        private WorkflowInterface      $sagaStateMachine,
        private int                    $defaultAmountMinor,
        private string                 $defaultCurrency,
        private string                 $defaultPaymentMethodToken,
    )
    {
    }

    public function onReservationRequested(ReservationRequested $event): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->createSagaInCurrentTransaction($event);

            return;
        }

        try {
            $this->entityManager->wrapInTransaction(
                fn () => $this->createSagaInCurrentTransaction($event),
            );
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();
        }
    }

    public function onSeatsHeld(SeatsHeld $event): void
    {
        $this->executeInTransaction(function () use ($event): void {
            $saga = $this->sagaRepository->findForUpdateByReservationId($event->reservationId);
            if ($saga === null || !$this->sagaStateMachine->can($saga, SagaTransition::SeatsHeld->value)) {
                return;
            }

            $this->sagaStateMachine->apply($saga, SagaTransition::SeatsHeld->value);

            $this->outboxRecorder->record(
                new ProcessPayment(
                    reservationId: $event->reservationId,
                    showId: $event->showId,
                    correlationId: $event->correlationId,
                    amountMinor: $this->defaultAmountMinor,
                    currency: $this->defaultCurrency,
                    paymentMethodToken: $this->defaultPaymentMethodToken,
                ),
                reservationId: $event->reservationId,
                correlationId: $event->correlationId,
            );
        });
    }

    public function onPaymentSucceeded(PaymentSucceeded $event): void
    {
        $this->executeInTransaction(function () use ($event): void {
            $saga = $this->sagaRepository->findForUpdateByReservationId($event->reservationId);
            if ($saga === null || !$this->sagaStateMachine->can($saga, SagaTransition::PaymentSucceeded->value)) {
                return;
            }

            $this->sagaStateMachine->apply($saga, SagaTransition::PaymentSucceeded->value);

            $this->outboxRecorder->record(
                new ConfirmSeats(
                    reservationId: $event->reservationId,
                    showId: $saga->getShowId(),
                    seatIds: $saga->getSeatIds(),
                    correlationId: $event->correlationId,
                ),
                reservationId: $event->reservationId,
                correlationId: $event->correlationId,
            );
        });
    }

    public function onPaymentFailed(PaymentFailed $event): void
    {
        $this->executeInTransaction(function () use ($event): void {
            $saga = $this->sagaRepository->findForUpdateByReservationId($event->reservationId);
            if ($saga === null || !$this->sagaStateMachine->can($saga, SagaTransition::PaymentFailed->value)) {
                return;
            }

            $saga->recordFailure($event->reason);
            $this->sagaStateMachine->apply($saga, SagaTransition::PaymentFailed->value);

            $this->outboxRecorder->record(
                new ReleaseSeats(
                    reservationId: $event->reservationId,
                    showId: $saga->getShowId(),
                    seatIds: $saga->getSeatIds(),
                    correlationId: $event->correlationId,
                ),
                reservationId: $event->reservationId,
                correlationId: $event->correlationId,
            );
        });
    }

    public function onSeatsReleased(SeatsReleased $event): void
    {
        $this->executeInTransaction(function () use ($event): void {
            $saga = $this->sagaRepository->findForUpdateByReservationId($event->reservationId);
            if ($saga === null || !$this->sagaStateMachine->can($saga, SagaTransition::SeatsReleased->value)) {
                return;
            }

            $this->sagaStateMachine->apply($saga, SagaTransition::SeatsReleased->value);
            $this->outboxRecorder->record(
                new ReservationCancelled(
                    reservationId: $event->reservationId,
                    correlationId: $event->correlationId,
                    cancelledAt: new \DateTimeImmutable(),
                ),
                reservationId: $event->reservationId,
                correlationId: $event->correlationId,
            );
        });
    }

    public function onSeatsConfirmed(SeatsConfirmed $event): void
    {
        $this->executeInTransaction(function () use ($event): void {
            $saga = $this->sagaRepository->findForUpdateByReservationId($event->reservationId);
            if ($saga === null || !$this->sagaStateMachine->can($saga, SagaTransition::SeatsConfirmed->value)) {
                return;
            }

            $this->sagaStateMachine->apply($saga, SagaTransition::SeatsConfirmed->value);

            $confirmedAt = $event->confirmedAt;
            $this->outboxRecorder->record(
                new ReservationConfirmed(
                    reservationId: $event->reservationId,
                    correlationId: $event->correlationId,
                    confirmedAt: $confirmedAt,
                ),
                reservationId: $event->reservationId,
                correlationId: $event->correlationId,
            );
        });
    }

    public function onSeatHoldRejected(SeatHoldRejected $event): void
    {
        $this->executeInTransaction(function () use ($event): void {
            $saga = $this->sagaRepository->findForUpdateByReservationId($event->reservationId);
            if ($saga === null || !$this->sagaStateMachine->can($saga, SagaTransition::SeatHoldRejected->value)) {
                return;
            }

            $saga->recordFailure($event->reason);
            $this->sagaStateMachine->apply($saga, SagaTransition::SeatHoldRejected->value);

            $this->outboxRecorder->record(
                new ReservationCancelled(
                    reservationId: $event->reservationId,
                    correlationId: $event->correlationId,
                    cancelledAt: new \DateTimeImmutable(),
                ),
                reservationId: $event->reservationId,
                correlationId: $event->correlationId,
            );
        });
    }

    private function createSagaInCurrentTransaction(ReservationRequested $event): void
    {
        if ($this->sagaRepository->findByReservationId($event->reservationId) !== null) {
            return;
        }

        $saga = new Saga(
            id: Uuid::v7()->toRfc4122(),
            reservationId: $event->reservationId,
            correlationId: $event->correlationId,
            showId: $event->showId,
            seatIds: $event->seatIds,
            state: SagaState::AwaitingSeats,
        );

        $this->entityManager->persist($saga);

        $this->outboxRecorder->record(
            new HoldSeats(
                reservationId: $event->reservationId,
                showId: $event->showId,
                seatIds: $event->seatIds,
                correlationId: $event->correlationId,
            ),
            reservationId: $event->reservationId,
            correlationId: $event->correlationId,
        );
    }

    private function executeInTransaction(\Closure $operation): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $operation();

            return;
        }

        $this->entityManager->wrapInTransaction($operation);
    }
}
