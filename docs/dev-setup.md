# Development setup

Run commands from the repository root unless a service directory is specified. Requirements: Docker, Docker Compose v2 and free ports `5433`, `5673`, `15673`, `8080`, `8081`, `8082`.

## Start the stack

```bash
docker compose up -d --build
```

The [Compose file](../docker-compose.yml) builds PHP images per service using the [shared Dockerfile](../docker/php/Dockerfile). PostgreSQL initialization creates four databases; one-shot `*-migrate` jobs complete before each service's HTTP process, worker and relay start. Rebuild after application changes because PHP source is copied into the images.

| Endpoint | Purpose |
|----------|---------|
| [localhost:8080](http://localhost:8080) | Booking HTTP API |
| [localhost:8081](http://localhost:8081) | Orchestrator HTTP API |
| [localhost:8082](http://localhost:8082) | Reservation Console; proxies internal Inventory HTTP reads |
| [localhost:15673](http://localhost:15673) | RabbitMQ management (`guest` / `guest`) |
| `localhost:5673` | Host AMQP access; containers use `rabbitmq:5672` |
| `localhost:5433` | PostgreSQL (`ticketing` / `ticketing`), databases `booking`, `inventory`, `payment`, `orchestrator` |

Override RabbitMQ host ports if needed:

```bash
RABBITMQ_AMQP_PORT=5674 RABBITMQ_MANAGEMENT_PORT=15674 docker compose up -d
```

## Create a reservation

Seed a show and seats:

```bash
docker compose exec inventory-worker php bin/console app:inventory:seed-seats sample-show A1 A2 B1 --name="Sample Show"
```

Open the [Reservation Console](http://localhost:8082), select seats and create a reservation. It generates an idempotency key and polls service read APIs. The reservation ID remains in the URL for reopening its status; terminal failures include the saga failure reason.

For a direct HTTP request, use seat UUIDs from the seed output or this query, not seat codes such as `A1`:

```bash
docker compose exec postgres psql -U ticketing -d inventory \
  -c "SELECT id, seat_code FROM seats WHERE show_id = 'sample-show';"

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

Use the returned `reservationId` to inspect the workflow:

```bash
curl 'http://localhost:8081/api/saga/<reservation-id>'
```

A newly accepted booking is `PENDING`; final confirmation follows asynchronously. The normal fake gateway succeeds. To reproduce a declined payment and seat release:

```bash
PAYMENT_METHOD_TOKEN=tok_decline docker compose up -d --build
```

Create a new reservation for available seats. Expected outcome: booking/saga `CANCELLED`, payment `FAILED`, seats `AVAILABLE`. Restore the default for subsequent successful checkouts:

```bash
PAYMENT_METHOD_TOKEN=tok_fake_visa docker compose up -d
```

## Verification

The checks below cover service behavior, database contention and message replay. They do not include automated crash/restart or broker-outage scenarios; no CI or static-analysis gate is configured.

### Service integration tests

On the host, install dependencies inside each service directory. PHP must meet that service's `composer.json` requirements and have SQLite support. If the host lacks `ext-amqp`, Composer can ignore that requirement for local single-service work:

```bash
cd services/inventory-service
composer install --ignore-platform-req=ext-amqp
php bin/phpunit
```

Run the same commands from `services/payment-service` for the Payment suite. PHPUnit uses the service's test environment and rebuilds its SQLite schema with `SchemaTool`; do not point these tests at application data. PostgreSQL migrations are not applied to that SQLite database. The inbox table is unmapped infrastructure, so the consumer tests create it explicitly.

The [Inventory suite](../services/inventory-service/tests/) covers catalog reads, sequential hold attempts and duplicate transport delivery. The [Payment suite](../services/payment-service/tests/) covers success/failure, repeat processing, gateway call counts and inbox rollback/redelivery. These are Symfony/Doctrine integration tests; there is no separate isolated unit-test suite. Despite its name, `SeatHoldConcurrencyTest` makes sequential attempts on SQLite and does not verify PostgreSQL locking.

### PostgreSQL concurrency tests

After starting the stack:

```bash
./tests/integration/inventory-concurrency.sh
./tests/integration/saga-concurrency.sh
```

These runners use service images and create isolated PostgreSQL schemas, then clean them up without modifying application records. They dispatch through the real inbound Messenger bus without RabbitMQ. The Inventory runner mounts source, configuration and migrations; the saga runner mounts source/configuration and uses migrations from its image. Rebuild when dependencies or unmounted files change. PHPUnit is not required.

For Inventory-only verification from a fresh checkout:

```bash
docker compose up -d --wait postgres
docker compose build inventory
./tests/integration/inventory-concurrency.sh
```

The [Inventory runner](../tests/integration/inventory-concurrency.php) uses two processes with preloaded seat objects and distinct reservation/message IDs. At `READ COMMITTED`, it observes `pg_blocking_pids` and the waiting seat query, checks that uncommitted writes remain invisible, then verifies commit/rejection and rollback/success outcomes for seats, holds, outbox rows and inbox claims. Scope: one seat; no multi-seat or same-reservation business-duplicate contention.

The runner prints observations and assertions, ending with `PASS: both inventory concurrency scenarios; isolated test schema removed.` on success. Failed assertions exit nonzero.

The [saga runner](../tests/integration/saga-concurrency.php) checks the transition matrix and ten competing-handler cases across six transitions, including success/failure events in both orders and rollback. Processes preload stale saga objects, use distinct message IDs and observe actual lock waits before checking committed state and outgoing work. Scope: existing sagas through the inbound bus; concurrent initial creation and HTTP idempotency-key races are not tested.

### RabbitMQ end-to-end tests

Run these sequentially:

```bash
./tests/e2e/happy-path.sh
./tests/e2e/happy-path-idempotency.sh
./tests/e2e/payment-failure.sh
```

The basic runner checks booking/saga `CONFIRMED`, seats `SOLD` and payment `PAID`. The other two use the [shared runner](../tests/e2e/saga-idempotency.sh), which republishes every outbox row for the reservation under its original message ID. It checks inbox claims in three services and unchanged states, row/outbox counts, payment/hold records, seat revision and saga timestamp. Booking is checked without an inbox table.

The failure case also checks `PAYMENT_DECLINED`, failure timestamps, released seat ownership and the absence of payment-success/confirmation messages. Replay covers identical deliveries, not competing business events with different IDs or conflicting terminal events. All runners seed unique records and preserve the stack/data for inspection.

The selected payment token is global to the Orchestrator worker. The failure runner leaves it set to `tok_decline`; run `./tests/e2e/happy-path-idempotency.sh` afterwards to restore successful payments and verify that path. Advanced runners stop old Booking processes before migrations so removing the historical inbox table cannot race with an old worker. For manual upgrades, follow the same order: [the forward migration](../services/booking-service/migrations/Version20260923120000.php) drops the table; rollback recreates it empty, without historical claims.

To reuse current images, migrations and an already running stack:

```bash
E2E_SKIP_STACK_START=1 ./tests/e2e/happy-path.sh
```

In skip-start mode, ensure the Orchestrator worker has `PAYMENT_METHOD_TOKEN=tok_fake_visa` for happy-path tests or `tok_decline` for failure tests. Advanced runners check that setting. Increase the default 90-second timeout when needed:

```bash
E2E_TIMEOUT_SECONDS=180 ./tests/e2e/happy-path.sh
```

### HTTP load tests

See [k6 commands and measurement scope](../tests/load/README.md).

## Inspect or reset local state

```bash
docker compose logs -f orchestrator-worker booking-outbox-relay

docker compose exec postgres psql -U ticketing -d orchestrator \
  -c "SELECT reservation_id, state, updated_at FROM sagas ORDER BY updated_at DESC LIMIT 5;"
```

Inspect queue depth in [RabbitMQ management](http://localhost:15673). If an E2E runner times out, use its recent worker/relay logs and `docker compose ps` to check startup, migrations and the configured payment token.

Compose uses `php -S`, one PostgreSQL instance and one RabbitMQ broker, with topology declared by Messenger. Relays poll every second and ignore nonzero exits with `|| true`, so a running relay container alone does not prove publication is succeeding. Correlation IDs are present in messages, but there is no application-wide logging/tracing pipeline or failed-message recovery workflow. See [delivery boundaries](architecture.md#5-reliable-delivery-and-duplicate-requests) when diagnosing stalled work.

To remove all local database data and recreate the stack:

```bash
docker compose down -v
docker compose up -d --build
```

## Bare-metal scope

Per-service SQLite and `sync://` support local single-service work only. Cross-service execution requires the Compose PostgreSQL/RabbitMQ setup or equivalent manual configuration matching each service's Messenger routing. See [architecture](architecture.md) for ownership and transaction boundaries.
