# ADR 0007: Shared Messenger idempotency package

## Status

Accepted

Service scope narrowed by [ADR 0008](0008-booking-without-inbox.md): booking no longer uses an inbox; the package remains shared by inventory, payment and orchestrator.

## Context

All four services need the same inbox claim algorithm and Symfony Messenger middleware. Keeping a local entity, repository, and middleware in every service duplicates infrastructure code and makes fixes easy to apply inconsistently.

The existing `contracts/` package cannot own this code because it intentionally has no Symfony or Doctrine dependencies.

## Decision

Create the focused Composer path package `ticketing/messenger-idempotency` under `packages/messenger-idempotency/`.

The package owns:

1. `IdempotencyMiddleware`, including `ReceivedStamp` and stable transport message ID validation.
2. `InboxMessageStore`, which atomically claims `(consumer_name, message_id)` through Doctrine DBAL.

Each service continues to own:

1. Its `inbox_messages` migration and physical table.
2. Its stable logical consumer name.
3. Placement and ordering of middleware on its inbound Messenger bus.
4. The transaction containing inbox claim, business changes, and outbox writes.

The package uses DBAL directly and does not expose a shared ORM entity. Inbox rows are infrastructure records and do not require a domain model.

## Consequences

- Inbox behavior and missing-ID policy are implemented once.
- Services retain independent databases and migration histories.
- The Docker build context must copy the path package before running Composer.
- Tests using Doctrine `SchemaTool` must create the unmapped inbox table explicitly or run service migrations.
- Changing the inbox schema requires coordinated package and per-service migration changes.

## Alternatives considered

- Put the middleware in `contracts/` — rejected because it would add Symfony and Doctrine dependencies to message contracts.
- Keep four local implementations — rejected because the algorithm is identical infrastructure with no service-specific behavior.
- Share an ORM entity and repository — rejected because it couples Doctrine mapping and schema ownership across services.
- Create a broad `common/` package — rejected because its boundary would be unclear and likely accumulate unrelated code.
