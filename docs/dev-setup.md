# Dev setup — Docker Compose

## Prerequisites

- Docker + Docker Compose v2
- Ports free: `5433`, `5673`, `15673`, `8080`, `8081`, `8082`

## Start stack

```bash
docker compose up -d --build
```

First run builds four PHP images (one per service). Postgres init creates databases from `ops/postgres/init/01-databases.sql`. Four one-shot `*-migrate` services apply migrations before the corresponding HTTP processes, workers and relays start.

**Reset everything (wipe DB):**

```bash
docker compose down -v
docker compose up -d --build
```

## Endpoints

| URL | Purpose |
|-----|---------|
| http://localhost:8080 | booking-service HTTP |
| http://localhost:8081 | orchestrator-service HTTP |
| http://localhost:8082 | Reservation Console |
| localhost:5673 | RabbitMQ AMQP (host access; containers use `rabbitmq:5672`) |
| http://localhost:15673 | RabbitMQ management (`guest` / `guest`) |
| localhost:5433 | PostgreSQL (`ticketing` / `ticketing`, DBs: `booking`, `inventory`, `payment`, `orchestrator`) |

## Seed inventory

```bash
docker compose exec inventory-worker php bin/console app:inventory:seed-seats sample-show A1 A2 B1 --name="Sample Show"
```

Use **seat UUIDs** (not `A1`) in `POST /api/reservations`. List from DB:

```bash
docker compose exec postgres psql -U ticketing -d inventory -c "SELECT id, seat_code FROM seats WHERE show_id = 'sample-show';"
```

## Reservation Console

Open http://localhost:8082 after the stack starts. Select an event from the catalog,
choose available seats, and create a reservation. The page polls the booking,
inventory, and orchestrator read APIs and visualizes the happy-path or compensation
state changes. The reservation ID is kept in the page URL, so the same status can be
reopened later from the link or by entering the ID in the status panel. Terminal
failures include their cancellation reason. An idempotency key is generated
automatically for every new reservation and remains available under technical
details. RabbitMQ is available separately at http://localhost:15673 (`guest` /
`guest`).

## Create reservation

```bash
curl -X POST http://localhost:8080/api/reservations \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: my-key-001' \
  -d '{
    "showId": "sample-show",
    "seatIds": ["<seat-uuid>"],
    "buyerName": "Alice",
    "buyerEmail": "alice@example.com"
  }'
```

## Automated E2E tests

Start with the basic happy-path test from the repository root:

```bash
./tests/e2e/happy-path.sh
```

The basic runner intentionally checks only the business result:

1. Builds and starts the Compose stack with a successful fake payment token.
2. Seeds one uniquely named show and seat.
3. Creates a reservation through the booking HTTP API.
4. Waits for the asynchronous saga to finish.
5. Checks `CONFIRMED` in orchestrator and booking, `SOLD` in inventory, and `PAID` in payment.

The longer advanced runner additionally republishes the saga outbox rows and
verifies consumer inbox claims and duplicate-delivery safety:

```bash
./tests/e2e/happy-path-idempotency.sh
```

The advanced runner:

1. Builds and starts the Compose stack with `tok_fake_visa`.
2. Seeds a uniquely named show and seat.
3. Creates a reservation over HTTP and waits for `CONFIRMED` / `SOLD` / `PAID`.
4. Verifies inbox claims for messages consumed by inventory, payment and orchestrator. Booking is verified through its reservation status and unchanged outbox count after redelivery.
5. Marks only this reservation's outbox rows unpublished, causing the relays to publish them again with their original AMQP `message_id` values.
6. Waits for RabbitMQ queues to drain and proves that states, row counts, outbox counts, PostgreSQL seat row revision, and saga timestamp did not change.

Payment failure and compensation, including duplicate redelivery:

```bash
./tests/e2e/payment-failure.sh
```

This runner starts the stack with `tok_decline` and verifies:

1. The reservation reaches `CANCELLED` in both booking and orchestrator.
2. The payment is `FAILED` with `PAYMENT_DECLINED`, no gateway payment ID or paid timestamp, and a failure timestamp.
3. The hold is `RELEASED`; the seat is `AVAILABLE` with no reservation owner.
4. Outbox records prove the exact flow: `HoldSeats` → `SeatsHeld` → `ProcessPayment` → `PaymentFailed` → `ReleaseSeats` → `SeatsReleased` → `ReservationCancelled`, with no confirmation/success messages.
5. Republishing all of this reservation's outbox messages leaves states, record counts, payment/hold records, outbox counts, PostgreSQL seat row revision and saga timestamp unchanged.
6. Inbox claims exist in inventory, payment and orchestrator; booking works without an inbox table.

The two advanced entry points share `tests/e2e/saga-idempotency.sh`. Run them sequentially: the selected payment token is global to the orchestrator worker. These are local integration tests, not concurrent-load or crash-recovery tests.

All scripts preserve the stack and their records for inspection. The failure runner leaves the stack configured to decline payments. Run `./tests/e2e/happy-path-idempotency.sh` afterwards to restore successful payments and verify the happy path. The advanced runners stop booking processes before startup so the inbox-removal migration cannot race with old workers. To reuse an
already running stack without rebuilding it:

```bash
E2E_SKIP_STACK_START=1 ./tests/e2e/happy-path.sh
```

In skip-start mode, images and migrations must already be current. The orchestrator worker must use `PAYMENT_METHOD_TOKEN=tok_fake_visa` for happy-path tests or `tok_decline` for the failure test. The advanced runners check that setting before creating a reservation.
Increase the default 90-second timeout when needed:

```bash
E2E_TIMEOUT_SECONDS=180 ./tests/e2e/happy-path.sh
```

## Concurrent saga transitions (PostgreSQL)

With the Compose stack already started:

```bash
./tests/integration/saga-concurrency.sh
```

The runner uses the existing orchestrator image/dependencies, mounts current orchestrator source and configuration, and creates an isolated PostgreSQL schema from the service migrations. It removes that schema afterwards without modifying application records. No PHPUnit installation or SQLite database is required.

The runner first verifies the configured state-machine transition matrix, including rejection of invalid transitions and terminal/legacy states. Ten concurrency cases exercise all six existing-saga transition handlers through the real inbound Messenger bus. Two PHP processes preload the same saga, receive distinct message IDs and contend on its row. The test verifies the wait with `pg_blocking_pids`, then checks one committed transition and exactly one matching outbox message. It includes payment success/failure in both orders, seat-held/rejected in both orders, duplicate business events and rollback of the first transaction. Inbox claims are checked for commit/rollback too.

This is a PostgreSQL/Messenger integration test; the separate E2E runners cover RabbitMQ delivery. It does not test concurrent initial saga creation or inventory seat contention.

## Concurrent inventory holds (PostgreSQL)

With PostgreSQL running and the inventory image already built:

```bash
./tests/integration/inventory-concurrency.sh
```

For a fresh setup, first run `docker compose up -d --wait postgres` and
`docker compose build inventory`. RabbitMQ and the other services are not needed
for this test; it dispatches directly through the real inbound Messenger bus.

The runner creates an isolated schema from current inventory migrations, prepares
a fresh seat per scenario, and removes its schema afterwards. Application records
are untouched. Current source, configuration and migrations are mounted into the
existing inventory image; rebuild that image when dependencies change.

Two PHP processes preload `AVAILABLE` into independent EntityManagers and process
`HoldSeats` with different reservation and message IDs. The first transaction stays
open until an observer connection sees its PID in `pg_blocking_pids(T2)` and confirms
that T2 is waiting on the seat `SELECT ... FOR UPDATE`, rather than on inbox deduplication.
Signals between processes control ordering; a timeout fails the test if the expected
wait never occurs. No arbitrary sleep is used to assume that contention happened.

The terminal report is generated from these observations and assertions:

- **T1 commit:** T2's original PHP object changes from `AVAILABLE` to `HELD/R1`;
  exactly one active hold belongs to R1; outbox contains `SeatsHeld` for R1 and
  `SeatHoldRejected` for R2 with the unavailable-seat reason.
- **T1 rollback:** T2 succeeds; only R2's hold, success event and inbox claim remain.
- An independent connection cannot see T1's uncommitted seat change, hold or outbox.

State reported by each worker is read from the preloaded ORM object **after the
handler returns**, without an extra refresh or locking query in the test.
Successful completion prints `PASS: both inventory concurrency scenarios; isolated test schema removed.`;
an assertion failure prints `FAIL` and exits with a nonzero status. PostgreSQL PIDs
vary on each run.

Scope: one seat, different reservations, `READ COMMITTED`. This does not verify
same-reservation business duplicates, multi-seat deadlocks, HTTP/RabbitMQ delivery
or load throughput. `tests/load/reservations.js` separately exercises HTTP acceptance.

## Observe saga

```bash
# Saga state
docker compose exec postgres psql -U ticketing -d orchestrator \
  -c "SELECT reservation_id, state, updated_at FROM sagas ORDER BY updated_at DESC LIMIT 5;"

# RabbitMQ queues
open http://localhost:15673
```

To exercise payment failure and seat-release compensation, start the stack with:

```bash
PAYMENT_METHOD_TOKEN=tok_decline docker compose up -d --build
```

The expected terminal state is `CANCELLED`, and the seat returns to `AVAILABLE`.

## Logs

```bash
docker compose logs -f orchestrator-worker booking-outbox-relay
```

## Bare-metal (without Docker)

Per-service `composer install` + local SQLite in `.env` + `sync://` messenger. **Does not connect services** — use only for single-service work or PHPUnit. Cross-service flow requires Docker stack or manual RabbitMQ setup matching `config/packages/messenger.yaml` queue names.

For `composer install` on host without `ext-amqp`:

```bash
composer install --ignore-platform-req=ext-amqp
```

## Compose layout (reference)

```text
e2e-ui (nginx proxy) ── booking / inventory / orchestrator HTTP

postgres ──┬── booking-migrate → booking (+ worker + outbox-relay)
           ├── orchestrator-migrate → orchestrator (+ worker + outbox-relay)
           ├── inventory-migrate → inventory (+ worker + outbox-relay)
           └── payment-migrate → payment worker + outbox-relay

rabbitmq ← all PHP services (AMQP)
```

See `docs/current-state.md` for implementation status and known gaps.

To override the RabbitMQ host ports:

```bash
RABBITMQ_AMQP_PORT=5674 RABBITMQ_MANAGEMENT_PORT=15674 docker compose up -d
```
