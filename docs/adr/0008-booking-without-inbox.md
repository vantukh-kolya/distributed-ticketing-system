# ADR 0008: Booking status handlers without an inbox

## Status

Accepted. Narrows the service scope of ADR 0007.

## Context

Booking consumes ReservationConfirmed and ReservationCancelled. Their handlers only update reservation status and do not create outgoing messages or external side effects. Repeated delivery of the same event is safe without storing its transport ID.

## Decision

Remove booking's inbox middleware, package dependency and table. Keep doctrine_transaction on event.bus and keep HTTP reservation idempotency unchanged. Inventory, payment and orchestrator continue using the shared inbox package.

Preserve the historical table-creation migration and add a forward migration to drop the table, supporting both existing databases and fresh installations. Rolling back recreates an empty table; historical claims are not restored.

## Consequences

- Booking no longer records or deduplicates incoming transport message IDs.
- E2E redelivery continues to check booking status and outbox count, without expecting booking inbox claims.
- Conflicting terminal events and concurrent status transitions remain a separate concern; this change does not strengthen their ordering or transition rules.
- Reassess idempotency if these handlers gain outgoing messages or external side effects.
- Stop old booking workers before applying the drop migration, then start workers with the updated configuration.
