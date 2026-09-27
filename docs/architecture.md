# Architecture — Ticketing Reservation + Payment Saga

Service boundaries, transaction guarantees, and the reservation workflow.

## 1. Purpose

The system coordinates seat reservations and payment across separate databases. Its design addresses three concerns:

- Concurrent reservations must not sell the same seat twice.
- Repeated message delivery must not repeat a completed business operation.
- Payment failure must release held seats and cancel the reservation.

Hold expiry and recovery from an uncertain payment outcome remain open work. See [current state](current-state.md) for the implemented scenarios and test coverage.

## 2. System context

```text
Client
  → booking-service     (HTTP: create reservation, idempotency-key)
  → orchestrator-service (saga lifecycle, saga state API)
        ↓ commands (RabbitMQ)
  → inventory-service   (hold / release / confirm seats)
  → payment-service     (process payment)
        ↑ events (RabbitMQ)
  → orchestrator-service (process manager reacts, sends next command)
  → booking-service     (updates reservation status from events)
```

Each box is a separate deployable with its **own PostgreSQL database**.

## 3. Services and responsibilities

| Service | Owns | Must not |
|---------|------|----------|
| **booking-service** | `Reservation` user status, HTTP API, outbox `ReservationRequested` | Hold seats, charge cards, `Show` catalog |
| **inventory-service** | `Show` catalog, `Seat` states, `SeatHold`, hold/release/confirm | Payment, saga coordination |
| **payment-service** | `Payment` lifecycle, gateway adapter | Seat logic, saga coordination |
| **orchestrator-service** | `Saga` aggregate, transitions, saga query API | Business rules for seats or money |

### booking-service

- `POST /api/reservations` with a required `Idempotency-Key` header.
- Publishes `ReservationRequested` (via outbox).
- Consumes terminal events: `ReservationConfirmed`, `ReservationCancelled`.

### inventory-service

- Consumes `HoldSeats`, `ReleaseSeats`, `ConfirmSeats`.
- Publishes `SeatsHeld`, `SeatHoldRejected`, `SeatsReleased`, `SeatsConfirmed`.
- **Seat hold** uses ORM **pessimistic lock** (see §6). Conditional `UPDATE` documented as comparison alternative.

### payment-service

- Consumes `ProcessPayment`.
- Publishes `PaymentSucceeded` or `PaymentFailed`.
- `FakePaymentGateway` with configurable failures for reproducible local scenarios.

### orchestrator-service

- Consumes `ReservationRequested` and domain events from inventory/payment.
- Sends next command in the saga; runs compensations on failure.
- `GET /api/saga/{reservationId}` returns the current state.
- Expiry scheduling and transition history are pending.

**Implemented so far:** full happy-path coordinator (`ReservationRequested` → `HoldSeats` → … → `ReservationConfirmed`); current-state `GET /api/saga/{reservationId}` query (transition history is still pending); seat-hold rejection → `ReservationCancelled`; payment failure → release → cancellation. Cross-service runtime is wired through root `docker-compose.yml` + RabbitMQ, and automated happy-path and payment-failure runners verify terminal outcomes and duplicate redelivery — see `docs/current-state.md`.

## 4. Saga flow

### Happy path

```text
AWAITING_SEATS
  → AWAITING_PAYMENT
  → PAYMENT_PAID
  → CONFIRMED
```

Steps:

1. Client reserves → `ReservationRequested`.
2. Orchestrator → `HoldSeats`.
3. Inventory → `SeatsHeld`.
4. Orchestrator → `ProcessPayment`.
5. Orchestrator → `ConfirmSeats` → `ReservationConfirmed`.

### Compensation / terminal failure

```text
AWAITING_PAYMENT
  → COMPENSATING
  → CANCELLED
```

Examples:

- After `SeatsHeld`, payment fails → `ReleaseSeats` → `CANCELLED`.
- Seat-hold rejection transitions directly from `AWAITING_SEATS` to `CANCELLED`.

Compensation is a separate committed operation in each affected service. Expiry and late-payment handling are not yet implemented.

## 5. Cross-cutting concerns

### 5.1 Orchestration vs choreography

**Chosen: orchestration** (central process manager in `orchestrator-service`).

- Explicit saga state and easier recovery after crashes.
- Trade-off: orchestrator must stay thin; alternative (choreography) documented in ADR when relevant.

### 5.2 Transactional outbox

Pattern in **every** service that publishes events:

1. In one DB transaction: apply business change + insert into `outbox_messages`.
2. Separate relay/worker publishes to RabbitMQ after commit.
3. Consumers treat delivery as **at-least-once**.

Never publish to the broker in the same logical operation without outbox unless in a `sync` prototype with a clear TODO.

### 5.3 Idempotency

- **HTTP:** `Idempotency-Key` on `POST /reservations`.
- **Consumers:** inventory, payment and orchestrator use an `inbox_messages` table + Messenger middleware **before** handler. Booking uses idempotent reservation status updates without an inbox and retains the transaction middleware. Use `(consumer_name, message_id)` as the deduplication key when one service can have multiple logical consumers.
- On duplicate `HoldSeats` for the same reservation: second processing must be a no-op or return the same outcome.
- The outbox row id is propagated as the native AMQP `message_id`, so republishing the same outbox row retains the same identity. Consumers use the resulting Messenger `TransportMessageIdStamp` as the `inbox_messages` deduplication key.
- **Do not retry** unrecoverable business errors (`Seat unavailable`, `Payment declined`) — use `UnrecoverableMessageHandlingException` or zero retries for those types.

### Saga transition graph

Orchestrator uses Symfony Workflow with `type: state_machine`. `services/orchestrator-service/config/packages/workflow.yaml` defines the six named transitions of the current saga. Symfony’s standard `MethodMarkingStore` reads and writes the persisted `SagaState` enum through `getState()` / `setState()`, without Workflow dependencies in the entity. The legacy `SEATS_HELD` value remains recognized but has no transitions.

After acquiring the saga row lock, the coordinator checks `can()` and calls `apply()`. Unavailable transitions remain no-ops for repeated or late events. Command/event creation stays explicit in the coordinator, with no Workflow listeners for outbox writes. See [ADR 0010](adr/0010-saga-state-machine.md).

### Saga transition concurrency

For an existing saga, the coordinator starts or joins the handler transaction, loads the row through `SagaRepository::findForUpdateByReservationId` (`PESSIMISTIC_WRITE` + `Query::HINT_REFRESH`), then checks the expected state. The state change and next outbox record commit together. Different message IDs for the same reservation are serialized by the saga row lock, independently of inbox deduplication.

The first committed valid transition wins under the current state rules. This does not define recovery for a genuinely late successful payment after cancellation; that remains an expiry/reconciliation design concern. Creating a missing saga is still protected by the unique reservation key, not a row lock on an absent row.

The PostgreSQL integration runner uses two processes, preloaded stale ORM objects and `pg_blocking_pids` to verify actual lock contention. See [ADR 0009](adr/0009-saga-transition-locking.md).

### 5.4 Correlation

- `correlationId` (and optionally `sagaId`, `reservationId`) in:
  - HTTP headers
  - RabbitMQ message headers / stamps
  - structured logs (Monolog processor)

### 5.5 Observability (MVP)

- Structured logs: `correlationId`, `reservationId`, `sagaState` on each transition.
- Saga state HTTP endpoint.
- Failed messages → failure transport / DLQ; monitor queue depth.

## 6. Inventory: seat hold and concurrency

### Domain model (inventory DB)

- **`Show`** — ticketing show (concert, match). Table `shows`. Field **`showId`** in contracts/API.
- **`Seat`** — `ManyToOne` → `Show`; unique `(show_id, seat_code)`.
- **`SeatHold`** — one active hold per `reservationId`.

**Naming:** `Ticketing\Contracts\Event\*` = **Messenger/saga messages** (`ReservationRequested`, …). **`Show`** = **domain catalog**, not a message class.

### State machine (per seat)

```text
AVAILABLE → HELD → SOLD
           ↘ (release) → AVAILABLE
```

### Current implementation (`inventory-service`)

ORM transaction with **`LockMode::PESSIMISTIC_WRITE`** (`SeatRepository::findForUpdateByShowAndIds` + `SeatHoldService`):

1. Service sorts `seatIds` (stable lock order → fewer deadlocks).
2. Repository: `SELECT … FOR UPDATE` per seat id (persistence only), with `HINT_REFRESH` so a seat already managed by Doctrine reflects the state committed while the query waited for the lock.
3. Service: all ids found for `showId`, all `AVAILABLE` → `Seat::holdFor()` (entity enforces FSM).
4. Same transaction: `seat_holds` + outbox `SeatsHeld` or `SeatHoldRejected`.

Release mirrors via `tryReleaseSeats` + `Seat::releaseFor()`.

`tests/Service/SeatHoldConcurrencyTest.php` remains a sequential SQLite state/idempotency test. The PostgreSQL runner `tests/integration/inventory-concurrency.sh` uses two PHP processes with preloaded ORM objects and distinct reservation/message IDs. It confirms actual seat-lock contention with `pg_blocking_pids` and the waiting SQL, then verifies commit/rejection and rollback/success outcomes, one active hold, outbox payloads and inbox commit/rollback. It generates a readable report and cleans up its isolated schema. See `docs/dev-setup.md` for the command and scope; same-reservation business duplicates and multi-seat contention remain outside this test.

### Alternative hot path (flash-sale comparison)

Documented for benchmarks / ADR — **not** current default code:

```sql
UPDATE seats
SET state = 'HELD', held_by_reservation_id = :reservationId
WHERE show_id = :showId AND id IN (:ids) AND state = 'AVAILABLE';
```

- If `affected_rows !== count(seatIds)` → reject entire hold.
- Avoids lock queues vs pessimistic `FOR UPDATE` on contested rows; see trade-offs in reviews.

### Approaches to avoid

| Approach | Why risky or out of scope |
|----------|---------------------------|
| Naive read → modify → flush without lock or conditional `WHERE` | Oversell risk |
| ORM optimistic `@Version` + retry storms on hot rows | Poor p99 under contention |
| `PESSIMISTIC_READ` (`FOR SHARE`) when you immediately write | Irrelevant |

### Local ACID vs distributed consistency

- Hold + `seat_holds` + outbox row = **one transaction** in inventory DB (ACID).
- Between services: **eventual consistency** until saga completes — acceptable if states are explicit.

## 7. Shared contracts package

Path: `contracts/`

- Contains command/event DTOs, with no business logic.
- No Symfony, no Doctrine, no handlers.
- Services depend on it via Composer path repository.

Sharing PHP classes simplifies message exchange in this monorepo, but couples producers and consumers to class names and constructor shapes. A schema-only contract would support other languages and require a separate serialization and compatibility policy.

Serialization: Symfony Messenger default serializer uses **FQCN** in message headers — acceptable for this PHP-only monorepo.

### Shared Messenger infrastructure

Path: `packages/messenger-idempotency/`

- Contains the reusable Symfony Messenger middleware and DBAL inbox claim store.
- Does not own database schema: each inbox-enabled service keeps its own `inbox_messages` migration and table.
- Consumer identity and bus placement remain local service configuration.
- Must not be merged into `contracts/`, which remains framework-independent.

## 8. Symfony layering (per service)

Services use a flat Symfony layout. Entities own persistence state; controllers, request/response DTOs and mappers define the HTTP boundary. [ADR 0006](adr/0006-clean-entity-api-separation.md) records the decision.

### 8.1 Folder layout

```
services/{name}-service/src/
  Controller/       # HTTP entry only
  Dto/              # API request/response contracts (readonly classes)
  Mapper/           # Entity ↔ Response DTO (no HTTP)
  Entity/           # Doctrine persistence model only
  Repository/       # DB access; inventory: seat lock/hold queries here
  Service/          # Application use-cases (no HTTP types)
  Exception/        # Domain exceptions (not HttpException)
  MessageHandler/   # Async consumers
  Outbox/           # Transactional outbox
  Saga/             # orchestrator only
config/packages/
  framework.yaml    # Map domain exceptions → HTTP status codes
```

### 8.2 Layer boundaries

| Layer | Responsibility | Must not |
|-------|----------------|----------|
| **Controller** | Route, read headers (`Idempotency-Key`), `MapRequestPayload`, return `JsonResponse` | Business rules, `EntityManager`, duplicate idempotency logic |
| **Dto (request)** | Incoming JSON shape + Symfony Validator attributes | Doctrine, database |
| **Dto (response)** | Outgoing JSON shape (public readonly properties) | Logic, ORM |
| **Mapper** | `toResponse(Entity): *Response` (and later `fromRequest` if needed) | HTTP, transactions, idempotency rules |
| **Entity** | Table mapping, constructors, getters, status constants | `toResponse()`, `matchesRequest()`, JSON, HTTP, Messenger |
| **Repository** | Queries, persistence helpers | API mapping, HTTP |
| **Service** | Orchestrate use-case, `wrapInTransaction`, idempotency, call Mapper | `JsonResponse`, `*HttpException`, return raw arrays for API |
| **Exception** | `DomainException` subclasses for business violations | Extend Symfony `HttpException` |
| **framework.yaml** | `framework.exceptions.<Class>.status_code` | — |

### 8.3 Request/response flow (booking reference)

```text
POST /api/reservations
  Controller
    → MapRequestPayload → Dto/CreateReservationRequest
    → ReservationService::create($request, $idempotencyKey)
         → Repository (find by idempotency key)
         → Entity persist OR resolveDuplicate
         → Mapper::toResponse($entity)
    → return $this->json(ReservationResponse, 202)

IdempotencyPayloadMismatchException
  → framework.yaml maps to HTTP 409 (not thrown in Controller)
```

### 8.4 booking-service (implemented patterns)

- **Input:** `Dto/CreateReservationRequest` — `showId`, `seatIds[]`, `buyerName`, `buyerEmail`.
- **Output:** `Dto/ReservationResponse` — API field names (`reservationId` maps from Entity `id`).
- **Mapper:** `Mapper/ReservationMapper::toResponse()`.
- **Idempotency:** `ReservationService::payloadMatches()` + unique `idempotency_key`; duplicate same payload → same response; different payload → `IdempotencyPayloadMismatchException` (409).
- **Entity:** `Entity/Reservation` — getters only, no API methods.

HTTP endpoints across services follow this structure.

### 8.5 Entity mapping

Entities carry Doctrine mapping attributes, associations and persistence lifecycle hooks. HTTP serialization and request validation belong to DTOs.

### 8.6 Excluded dependencies

- `Entity::toResponse()` or `Entity::toArray()` for API
- `Entity::matchesRequest()` or comparison with Request DTO
- `throw new ConflictHttpException()` inside Service
- Returning `array<string, mixed>` from Service for JSON responses
- Serializer `#[Groups]` on Entity (use Groups on Response DTO if needed later)
- API Platform entities as public REST resources (out of scope)

### 8.7 Message handlers

Async operations follow `MessageHandler` → `Service` → `Repository` → `Outbox`. Commands and events use `contracts/` DTOs. Payment is worker-only; inventory also exposes catalog read endpoints.

## 9. Infrastructure

- **PostgreSQL:** one instance in Compose, four logical databases (`booking`, `inventory`, `payment`, `orchestrator`) — `ops/postgres/init/01-databases.sql`.
- **RabbitMQ:** single broker in Compose; queues created via Messenger `auto_setup` (`orchestrator.queue`, `inventory.queue`, `payment.queue`, `booking.queue`). Formal `ops/rabbitmq/` definitions and DLQ — not yet.
- **Docker Compose:** root `docker-compose.yml` — HTTP (booking, orchestrator), workers, outbox relay loops. Shared image: `docker/php/Dockerfile` + `SERVICE_PATH` build arg.
- **Migrations:** PostgreSQL dialect only (`JSON`, `TEXT`, `TIMESTAMP`). One-shot per-service `*-migrate` containers complete before the corresponding runtime containers start, avoiding concurrent migration races.

Each service has its **own** `composer.json` and image build. Dev setup: `docs/dev-setup.md`.

## 10. Reproducible scenarios

| Scenario | Expected behavior |
|----------|-------------------|
| Payment fails after hold | Seats released, reservation cancelled |
| Client disappears | Planned: expiry releases seats if payment is still pending |
| Two users, one seat | Exactly one hold succeeds |
| Duplicate reserve click | One reservation (idempotency) |
| Outbox republish | No second hold |
| Process crash mid-saga | Current state is queryable; crash-recovery scenarios are not yet tested |

## 11. Implementation phases

| Phase | Deliverable | Status |
|-------|-------------|--------|
| 1 | Inventory hold/release + `Show` catalog | **done**; single-seat PostgreSQL contention test passes (commit + rollback) |
| 1b | Booking POST + idempotency + outbox | **done** |
| 2 | Orchestrator: saga coordinator + happy-path handlers | **done** |
| 3 | End-to-end happy path over RabbitMQ (Docker) | **done** — automated happy-path + duplicate-redelivery runner passes |
| 4 | Payment failure → compensation | **done — automated payment-failure + duplicate-redelivery E2E passes** |
| 5 | Consumer idempotency (`inbox_messages`) | **done** — inbox on inventory, payment and orchestrator; booking uses idempotent status updates |
| 6 | Expiry | pending |
| 7 | `GET /saga` + DLQ + load tests | partial (state API and HTTP acceptance load test implemented; transition history, DLQ and saga throughput tests pending) |

## 12. Explicit non-goals (MVP)

- Full UI / SSE / aggressive polling
- Event sourcing
- Multi-region deployment
- Real payment provider integration
- DDD / Deptrac layer enforcement
- Schema-only cross-service contracts (documented as alternative only)

## 13. Documentation map

| Path | Content |
|------|---------|
| `docs/architecture.md` | This file |
| `docs/current-state.md` | Implementation snapshot (done / pending) |
| `docs/dev-setup.md` | Docker Compose quick start |
| `docs/adr/` | Architecture decision records (incl. `0006-clean-entity-api-separation.md`, `0007-shared-messenger-idempotency-package.md`) |
| `docs/diagrams/reservation-container.mmd` | C4-style container and RabbitMQ routing view for the reservation saga |
| `docs/diagrams/` | Container and sequence diagrams |
| `tests/load/README.md` | HTTP load-test commands and measurement scope |

## 14. Glossary

| Term | Meaning |
|------|---------|
| Show | Ticketing show (concert); inventory catalog entity; `showId` in API/contracts |
| Hold | Temporary reservation of seat(s), `HELD`, with `expiresAt` |
| Compensation | Semantic undo (release, void), not DB rollback |
| Saga | Long-running process across services with explicit states |
| Outbox | DB table written in same TX as business data, then relayed to broker |
| Conditional UPDATE | Single-statement hold: `UPDATE ... WHERE state = 'AVAILABLE'` |
