# Current state — September 2026

Implemented flows, runtime configuration, and remaining work.

## Runtime (Docker Compose)

Root `docker-compose.yml` runs the full dev stack:

| Component | Image / service | Notes |
|-----------|-----------------|-------|
| PostgreSQL 16 | `postgres` | One instance, 4 DBs via `ops/postgres/init/01-databases.sql` |
| RabbitMQ 3.13 + management | `rabbitmq` | Host AMQP `:5673`, UI `:15673`; containers use `:5672` |
| Booking HTTP | `booking` | `:8080` → built-in PHP server |
| Orchestrator HTTP | `orchestrator` | `:8081` → built-in PHP server |
| Inventory HTTP | `inventory` | Internal HTTP endpoint for show seats |
| Reservation Console | `e2e-ui` | `:8082` → seat selection, reservation creation, and live saga status |
| Workers | `*-worker` | `messenger:consume` per service queue |
| Outbox relay | `*-outbox-relay` | `app:outbox:relay` loop every 1s |
| Migration jobs | `*-migrate` | One-shot per-service migration before HTTP, workers and relays start |

**Queues (Symfony Messenger `auto_setup`):**

| Queue | Consumer |
|-------|----------|
| `orchestrator.queue` | orchestrator-worker |
| `inventory.queue` | inventory-worker |
| `payment.queue` | payment-worker |
| `booking.queue` | booking-worker |

**Not in compose yet:** DLQ / failed transport, volume mounts for live code, `ops/rabbitmq/` definitions.

## Databases

- **Docker:** PostgreSQL — `booking`, `inventory`, `payment`, `orchestrator` (user `ticketing` / `ticketing`).
- **Migrations:** written for **PostgreSQL only** (`JSON`, `TEXT`, `TIMESTAMP`). Local `.env` files may still point at SQLite for bare-metal dev; use Docker for cross-service E2E.

## Messaging

- **Docker:** `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f`
- **Bare-metal `.env`:** `sync://` (single-process only; does not connect services)
- Package: `symfony/amqp-messenger` in all four services
- Shared inbox implementation: Composer path package `ticketing/messenger-idempotency`; migrations and consumer names remain service-owned
- Orchestrator outbox relay dispatches commands on `command.bus` and terminal reservation events on `event.bus`, preserving the correct remote bus and inbox middleware through `BusNameStamp`

## Service implementation status

### booking-service — done (MVP)

- `POST /api/reservations` + `Idempotency-Key`
- Transactional outbox → `ReservationRequested`
- `ReservationConfirmed` / `ReservationCancelled` handlers use idempotent status updates without an inbox; the inbound bus retains `doctrine_transaction`.
- `ReservationConfirmedHandler` → updates reservation status
- `ReservationCancelledHandler` → updates reservation after compensation
- Removed temporary local `ReservationRequestedHandler` (was logging only)

### inventory-service — done (MVP)

- `HoldSeats`, `ReleaseSeats`, `ConfirmSeats` handlers
- Consumer inbox for `inventory_commands`; duplicate transport messages are skipped atomically
- Pessimistic lock hold
- PostgreSQL contention runner `tests/integration/inventory-concurrency.sh` passes commit/rejection and rollback/success scenarios with preloaded ORM objects, confirmed lock waiting and automatic reports
- `Show` catalog, `app:inventory:seed-seats`
- Outbox → `SeatsHeld`, `SeatHoldRejected`, etc.

### payment-service — done (MVP)

- `ProcessPayment` handler
- `ProcessPayment` consumer inbox: stable AMQP `message_id` → `inbox_messages` claim for consumer `process_payment` before handler
- `FakePaymentGateway` (`tok_decline` produces a controlled failure)
- States `PENDING`, `PAID`, `FAILED`
- Outbox → `PaymentSucceeded`, `PaymentFailed`

### orchestrator-service — happy path and payment-failure compensation proven by E2E

| Area | Status |
|------|--------|
| `Saga` + `SagaCoordinator` happy path | done |
| Symfony StateMachine transition graph | done — six named transitions; standard enum-aware method marking store; coordinator retains locking and outbox |
| Outbox → commands + `ReservationConfirmed` | done |
| Event handlers → coordinator | done |
| Concurrent transitions of an existing saga | done — row lock + state refresh; 10 PostgreSQL integration cases pass |
| Consumer inbox for `orchestrator_events` | done |
| `SeatHoldRejectedHandler` | done — transitions `AWAITING_SEATS` → `CANCELLED` and emits `ReservationCancelled` |
| `GET /api/saga/{reservationId}` | done (current state; transition history pending) |
| Payment failure → release seats → cancelled | done — automated payment-failure + duplicate-redelivery E2E passes |
| Expiry compensation | not implemented |

## Implementation phases

| Phase | Deliverable | Status |
|-------|-------------|--------|
| 1 | Inventory hold/release + catalog | **done**; single-seat PostgreSQL contention test passes (commit + rollback) |
| 1b | Booking POST + idempotency + outbox | **done** |
| 2 | Orchestrator saga coordinator + happy-path handlers | **done** |
| 3 | End-to-end happy path over RabbitMQ (Docker) | **done** — automated happy-path + duplicate-redelivery runner passes |
| 4 | Payment failure → `ReleaseSeats` / `CANCELLED` | **done — automated payment-failure + duplicate-redelivery E2E passes** |
| 5 | Consumer idempotency (`inbox_messages`) | **done** — inbox on inventory, payment and orchestrator; booking uses idempotent status updates |
| 6 | Expiry timer → release while payment is pending | pending |
| 7 | `GET /saga`, DLQ, load tests | partial (state API and HTTP acceptance load test implemented; transition history, DLQ and saga throughput tests pending) |

## Known gaps / tech debt

- Eleven long-running PHP containers plus four one-shot migration jobs use four near-identical images, increasing local build time and resource usage.
- `php -S` built-in server — dev only.
- Outbox relay swallows errors (`|| true` in relay loop).
- Outbox row ids are propagated as native AMQP `message_id` values and arrive as Messenger `TransportMessageIdStamp`.
- Inventory, payment and orchestrator have their own `inbox_messages` tables. Claims use `(consumer_name, message_id)` and share the handler transaction. Booking uses idempotent status updates without an inbox.
- Existing saga transitions acquire a PostgreSQL row lock before checking state; Doctrine refreshes managed state after acquiring the lock. `tests/integration/saga-concurrency.sh` verifies competing handlers with distinct message IDs, including rollback. Initial creation still relies on the unique reservation key and transport retries for concurrent inserts.
- No failure transport/DLQ or explicit retry policy per business/technical failure category.
- PHPUnit `.env.test` still targets SQLite — migrations will not apply there without change.
- `SeatHoldConcurrencyTest` remains sequential on SQLite. The separate PostgreSQL inventory runner covers contention between different reservations for one seat; same-reservation business duplicates and multi-seat contention are not covered.
- No CI or static-analysis quality gate is configured.
- `tests/e2e/happy-path.sh` is the small introductory happy-path check.
- `tests/e2e/happy-path-idempotency.sh` and `tests/e2e/payment-failure.sh` share the advanced runner. Payment-failure E2E passes against PostgreSQL + RabbitMQ, including duplicate publication of every saga outbox row and booking without an inbox.

## Quick smoke test (after `docker compose up`)

```bash
# 1. Seed seats
docker compose exec inventory-worker php bin/console app:inventory:seed-seats sample-show A1 A2 --name="Sample Show"

# 2. Get seat UUIDs from DB (or seed output), then:
curl -s -X POST http://localhost:8080/api/reservations \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: smoke-001' \
  -d '{"showId":"sample-show","seatIds":["<uuid>"],"buyerName":"Test","buyerEmail":"t@example.com"}'

# 3. Check saga (orchestrator DB)
docker compose exec postgres psql -U ticketing -d orchestrator -c "SELECT reservation_id, state FROM sagas;"
```

Expected happy path: saga reaches `CONFIRMED`, seat `SOLD`, booking reservation confirmed (may take a few seconds for relay + workers).
