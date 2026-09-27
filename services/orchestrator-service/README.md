# orchestrator-service

Coordinates the reservation saga over RabbitMQ. Inventory owns seat state; payment owns payment processing. The orchestrator persists workflow state and records the next command or terminal event in its outbox.

## Workflow

```text
AWAITING_SEATS → AWAITING_PAYMENT → PAYMENT_PAID → CONFIRMED
       │                │
       │                └→ COMPENSATING → CANCELLED
       └→ CANCELLED
```

The transition graph is defined in `config/packages/workflow.yaml`. Each transition locks and refreshes the saga row before checking its state, then commits the state change and outbox record together. Inbox deduplication handles repeated delivery of the same message.

`GET /api/saga/{reservationId}` returns the current state. Hold expiry, late-payment reconciliation, and transition history remain open work.

## Layout

| Path | Purpose |
|------|---------|
| `src/Entity/Saga.php` | Persistence snapshot (one row per `reservationId`) |
| `src/Enum/SagaState.php` | Persisted states |
| `config/packages/workflow.yaml` | Allowed state transitions |
| `src/Saga/SagaCoordinator.php` | Transitions + outbound commands via outbox |
| `src/MessageHandler/*` | Thin adapters → coordinator |
| `src/Controller/SagaController.php` | Current-state query API |
| `src/Outbox/` + `app:outbox:relay` | Commands on `command.bus`, terminal reservation events on `event.bus` |

## Verification

From the repository root, with the Compose stack running:

```bash
./tests/integration/saga-concurrency.sh
./tests/e2e/happy-path-idempotency.sh
./tests/e2e/payment-failure.sh
```

The PostgreSQL runner checks the transition matrix and competing handlers with different message IDs, including rollback and stale ORM state. The E2E runners check terminal outcomes and duplicate delivery over RabbitMQ. Run E2E scripts sequentially; the failure script leaves the stack configured to decline payments.

## References

- [Architecture](../../docs/architecture.md)
- [Development setup and test details](../../docs/dev-setup.md)
- [Current state](../../docs/current-state.md)
