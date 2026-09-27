# ADR 0010: Symfony StateMachine for saga transitions

## Status

Accepted

## Context

The coordinator repeated expected-state checks and target-state assignments across six handlers. Keep a single explicit transition graph while retaining the existing concurrency and delivery guarantees.

## Decision

Use Symfony Workflow as a `state_machine` named `reservation_saga`, configured in `config/packages/workflow.yaml`. Its six transitions match the existing event handlers. The standard `MethodMarkingStore` maps between Workflow markings and the persisted `SagaState` enum through `getState()` / `setState()`. Symfony supports backed enums directly; no custom marking store is needed. The entity remains independent of Workflow and the database schema stays unchanged.

The coordinator obtains the existing pessimistic row lock, checks `can()`, then calls `apply()` and explicitly records the next command/event in the same transaction. An unavailable transition remains a no-op, preserving duplicate and late-event behavior. Failure reasons are only recorded after the transition is known to be available.

## Consequences

- Transition rules have one configuration source; coordinator retains orchestration and outbox writes.
- Workflow does not replace the row lock, inbox, transaction or outbox. No side effects move into Workflow listeners.
- The unused legacy `SEATS_HELD` enum value is recognized with no transitions, preserving existing no-op behavior if encountered.
- Initial saga creation still explicitly uses `AWAITING_SEATS` and emits `HoldSeats` in one transaction.
- No expiry states, timers or reconciliation policies are added.
- The integration runner checks the transition matrix and actual concurrent handlers; RabbitMQ delivery and compensation remain covered by the E2E runners.
