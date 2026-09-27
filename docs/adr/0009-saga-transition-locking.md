# ADR 0009: Serialize transitions of an existing saga

## Status

Accepted

## Context

Inbox deduplication serializes deliveries with the same message ID. Different messages for one reservation can both read the same saga state, then persist conflicting transitions and outbox commands. Repeating an unlocked read inside a transaction does not prevent this race.

## Decision

Use ORM `PESSIMISTIC_WRITE` when loading an existing saga for a transition. Start or join the transaction before reading; check the expected state only after acquiring the lock. Keep the transition and outbox insert in that same transaction.

Set `Query::HINT_REFRESH` on this query so Doctrine replaces previously managed state with the row read after waiting for the lock. Read-only API queries continue using the unlocked repository method.

## Consequences

- Competing transitions for the same saga wait; different saga rows can proceed independently.
- A second handler observes the first committed state and applies the existing state guard. If the first transaction rolls back, the waiting handler can proceed from the original state.
- No network calls should be added inside the locked transaction; outbound work remains in the outbox.
- Initial creation has no existing row to lock. Its unique reservation constraint and retry behavior remain unchanged.
- First committed valid transition wins. This does not solve late-payment reconciliation after compensation or expiry.
- Ten PostgreSQL integration cases cover all six transition handlers, distinct transport IDs, stale managed entities, observed lock waits and rollback. RabbitMQ flows remain covered separately by E2E tests.

## Alternatives

- Conditional updates can also ensure one transition wins, but would require coordinating DBAL updates with the current ORM-managed state.
- Optimistic version checks require explicit retry handling on contention.
- Symfony Workflow can validate a transition graph but does not replace database concurrency control.
