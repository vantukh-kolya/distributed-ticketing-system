# Orchestrator service

Owns reservation workflow state and records the next command or terminal event. See [system architecture](../../docs/architecture.md#4-distributed-workflow-and-failure-recovery) for coordination decisions and recovery boundaries.

| Code | Responsibility |
|------|----------------|
| [SagaCoordinator](src/Saga/SagaCoordinator.php) | Handle outcomes and record outbound work |
| [workflow.yaml](config/packages/workflow.yaml) | Allowed transitions |
| [SagaRepository](src/Repository/SagaRepository.php) | Lock and refresh an existing saga before transition checks |
| [Saga](src/Entity/Saga.php) / [SagaState](src/Enum/SagaState.php) | Persisted workflow state |
| [Message handlers](src/MessageHandler/) | Inbound event adapters |
| [SagaController](src/Controller/SagaController.php) | `GET /api/saga/{reservationId}` |
| [Outbox relay](src/Command/OutboxRelayCommand.php) | Publish commands and terminal events on their respective buses |

[Verification commands and coverage](../../docs/dev-setup.md#verification) · [Project scope](../../README.md#scope-and-limitations)
