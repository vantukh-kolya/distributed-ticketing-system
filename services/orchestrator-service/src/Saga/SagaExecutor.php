<?php

declare(strict_types=1);

namespace App\Saga;

use App\Entity\Saga;
use App\Repository\SagaRepository;
use App\Saga\Strategy\SagaCreationStrategyInterface;
use App\Saga\Strategy\SagaEventStrategyInterface;
use App\Saga\Strategy\SagaTransitionStrategyInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Ticketing\Contracts\Event\ReservationEventInterface;
use Ticketing\Outbox\OutboxRecorder;

final readonly class SagaExecutor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SagaRepository $sagaRepository,
        private WorkflowInterface $sagaStateMachine,
        private OutboxRecorder $outboxRecorder,
    ) {
    }

    public function execute(ReservationEventInterface $event, SagaEventStrategyInterface $strategy): void
    {
        if ($strategy instanceof SagaCreationStrategyInterface) {
            $this->create($event, $strategy);

            return;
        }

        if (!$strategy instanceof SagaTransitionStrategyInterface) {
            throw new \LogicException('A saga strategy must implement creation or transition execution.');
        }

        $this->executeInTransaction(function () use ($event, $strategy): void {
            $saga = $this->sagaRepository->findForUpdateByReservationId($event->reservationId);
            $transition = $strategy->transition();
            if ($saga === null || !$this->sagaStateMachine->can($saga, $transition->value)) {
                return;
            }

            $this->sagaStateMachine->apply($saga, $transition->value);
            $this->applyStrategy($saga, $event, $strategy);
        });
    }

    private function create(ReservationEventInterface $event, SagaCreationStrategyInterface $strategy): void
    {
        $operation = function () use ($event, $strategy): void {
            if ($this->sagaRepository->findByReservationId($event->reservationId) !== null) {
                return;
            }

            $saga = $strategy->create($event);
            $this->entityManager->persist($saga);
            $this->applyStrategy($saga, $event, $strategy);
        };

        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $operation();

            return;
        }

        try {
            $this->entityManager->wrapInTransaction($operation);
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();
        }
    }

    private function applyStrategy(
        Saga $saga,
        ReservationEventInterface $event,
        SagaEventStrategyInterface $strategy,
    ): void {
        $this->outboxRecorder->record(
            $strategy->handle($saga, $event),
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
