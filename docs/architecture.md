# Architecture

This system coordinates seat reservations and payment across four service-owned databases. This guide records the boundaries, decisions and their trade-offs. See the [README](../README.md#scope-and-limitations) for project scope and [development setup](dev-setup.md#verification) for commands and verification coverage.

## 1. Service boundaries and data ownership

| Service | Owns | Implementation entry point |
|---------|------|----------------------------|
| Booking | Reservation API, buyer details, idempotency key, user-visible status | [ReservationService](../services/booking-service/src/Service/ReservationService.php) |
| Inventory | Show catalog, seat state and reservation holds | [SeatHoldService](../services/inventory-service/src/Service/SeatHoldService.php) |
| Payment | Payment record and gateway result | [PaymentService](../services/payment-service/src/Service/PaymentService.php) |
| Orchestrator | Workflow state and the next command or terminal event | [SagaCoordinator](../services/orchestrator-service/src/Saga/SagaCoordinator.php) |

No service reads or writes another service's tables. Compose hosts four logical databases on one PostgreSQL instance; see [database bootstrap](../ops/postgres/init/01-databases.sql). Inventory decides seat availability and ownership; Payment decides payment outcomes. Orchestrator coordinates these decisions without implementing their business rules.

The [container diagram](diagrams/reservation-container.mmd) shows components and RabbitMQ routing. The development [Reservation Console](../ops/e2e-ui/) proxies HTTP reads and reservation creation to the services.

## 2. Communication boundaries

HTTP handles reservation creation and reads: [Booking API](../services/booking-service/src/Controller/ReservationController.php), [catalog](../services/inventory-service/src/Controller/ShowController.php), [show seats](../services/inventory-service/src/Controller/ShowSeatsController.php), and [saga state](../services/orchestrator-service/src/Controller/SagaController.php). A new reservation returns `202` after its local transaction commits; it does not wait for seat availability or payment.

Cross-service writes use commands and events through the RabbitMQ `ticketing` direct exchange:

| Destination queue | Messages | Routing configuration |
|-------------------|----------|-----------------------|
| `orchestrator.queue` | `ReservationRequested`, inventory outcomes, payment outcomes | [Orchestrator Messenger](../services/orchestrator-service/config/packages/messenger.yaml) |
| `inventory.queue` | `HoldSeats`, `ConfirmSeats`, `ReleaseSeats` | [Inventory Messenger](../services/inventory-service/config/packages/messenger.yaml) |
| `payment.queue` | `ProcessPayment` | [Payment Messenger](../services/payment-service/config/packages/messenger.yaml) |
| `booking.queue` | `ReservationConfirmed`, `ReservationCancelled` | [Booking Messenger](../services/booking-service/config/packages/messenger.yaml) |

Compose runs a worker and an outbox relay for each service. Messenger declares queues through `auto_setup`. Bare-metal `sync://` configuration only supports single-process work; it does not connect services.

## 3. Transaction boundaries

| Operation | Writes committed together in one service database |
|-----------|---------------------------------------------------|
| Create reservation | `reservations` + outbox `ReservationRequested` |
| Hold / confirm / release seats | Inbox claim + seat and hold changes + inventory outcome in outbox |
| Reject seat hold | Inbox claim + outbox `SeatHoldRejected`; no seats acquired |
| Process payment | Inbox claim + payment result + payment outcome in outbox |
| Advance workflow | Inbox claim + saga change + next command or terminal event in outbox |
| Update Booking status | Reservation status only; inbound bus retains `doctrine_transaction` |

Inbound transaction middleware encloses the inbox and handler. Services start a transaction when called directly or join the handler's active transaction. The [shared outbox recorder](../packages/outbox/src/OutboxRecorder.php) persists the package's technical `OutboxMessage` entity using the same service-local EntityManager as the business write. The recorder does not flush, commit or publish; the caller owns the transaction.

There is no transaction spanning PostgreSQL databases or RabbitMQ. Booking can remain `PENDING` while Inventory has already held seats or Payment has completed. Confirmation is reported only after the seat-confirmation outcome reaches Orchestrator and then Booking.

The current payment gateway is a local fake called inside the payment transaction. Its request uses `reservationId` as the gateway idempotency key. An external charge could survive a local rollback, so this boundary and its tests do not establish real-provider duplicate-charge safety.

## 4. Distributed workflow and failure recovery

The [reservation-creation sequence](diagrams/reservation-flow-sequence.svg) traces HTTP handling, idempotency and the local transaction. The [saga sequence](diagrams/reservation-saga-sequence.svg) traces confirmation, seat rejection and payment-failure compensation. PlantUML sources: [creation](diagrams/reservation-flow-sequence.puml), [saga](diagrams/reservation-saga-sequence.puml).

Orchestrator gives checkout progress and compensation one inspectable state. Choreography would distribute that coordination across services; a synchronous service chain would couple HTTP latency and availability to downstream work while still needing partial-failure recovery. The cost of the current choice is another service/database and a dependency on Orchestrator for checkout progress.

[workflow.yaml](../services/orchestrator-service/config/packages/workflow.yaml) centralizes the six transitions instead of repeating the graph in handlers. Symfony's method marking store reads/writes the persisted `SagaState` enum without a Workflow dependency in the entity.

Messenger handlers delegate to [SagaCoordinator](../services/orchestrator-service/src/Saga/SagaCoordinator.php), which selects an event-specific [strategy](../services/orchestrator-service/src/Saga/Strategy/) by exact event class and passes it with the event to `SagaExecutor`. Implementing `SagaTransitionStrategyInterface` or `SagaCreationStrategyInterface` inherits automatic tagging through `SagaEventStrategyInterface`; duplicate registrations and unsupported event classes fail explicitly. Adding a reaction does not require editing the coordinator, though inbound routing/handlers and any new workflow transitions still need configuration. Strategies declare their transition and implement `handle(Saga, event)` to update failure details when needed and return the outgoing message. Only `SeatsHeldStrategy` needs configured payment defaults.

[SagaExecutor](../services/orchestrator-service/src/Saga/SagaExecutor.php) owns transaction boundaries, saga locking, `can()` / `apply()` checks and outbox recording. It calls the strategy only after accepting the transition, then records its returned message in the same transaction. The framework-independent [ReservationEventInterface](../contracts/src/Event/ReservationEventInterface.php) exposes the existing `reservationId` and `correlationId` properties so the executor reads metadata directly from the event; constructors and serialized payloads are unchanged. Strategies depend on neither the executor nor the recorder and contain no execution callbacks. Creation uses a separate strategy interface with `create(event)` because no saga row exists to lock. No Workflow listeners perform side effects. The extra interfaces distinguish creation from transitions while keeping transaction and recording rules in one place.

Different message IDs can compete to advance one reservation. [SagaRepository](../services/orchestrator-service/src/Repository/SagaRepository.php) therefore locks and refreshes the existing saga before the state check, with the transition and outgoing message committed together. Different saga rows can advance independently. Conditional updates would require reconciling DBAL writes with ORM state; optimistic version checks would require conflict retries. Keep network calls outside the locked section.

A payment decline moves the saga into `COMPENSATING`. Cancellation follows `SeatsReleased`; a rejected initial hold cancels directly. Release is a new Inventory transaction. Previously committed transactions in other services remain committed.

The first committed valid transition wins. Missing sagas and disallowed transitions are no-ops, including events for the legacy `SEATS_HELD` state. This does not recover a late successful payment. Initial creation has no existing row to lock: it inserts `AWAITING_SEATS` and `HoldSeats` under a unique reservation constraint, relying on transport retry for concurrent insert conflicts.

Inventory confirmation/release returns without an outcome when a hold is absent/inactive or seat ownership checks fail. The saga can then remain `PAYMENT_PAID` or `COMPENSATING`. `GET /api/saga/{reservationId}` exposes current state and failure reason, without transition history or automatic reconciliation.

[Saga concurrency tests](../tests/integration/saga-concurrency.php) verify competing transitions; [payment-failure E2E](../tests/e2e/payment-failure.sh) verifies compensation over RabbitMQ. Their [coverage limits](dev-setup.md#verification) distinguish these checks from untested creation races and crash recovery.

## 5. Reliable delivery and duplicate requests

### Publishing committed work

Each publishing service writes outgoing messages with its business changes. The [Booking relay](../services/booking-service/src/Command/OutboxRelayCommand.php) shows the publication path: read committed rows, dispatch them, then flush publication markers for the batch. Failure before that flush can republish messages already sent. Relays do not claim rows for parallel publishers.

Publishing directly after commit would leave a failure window that loses the next step; publishing before commit could expose work that later rolls back. The outbox retains publication intent at the cost of polling latency, message storage and duplicate delivery. Database change capture would replace polling with connector infrastructure and additional operational state.

The outbox UUID becomes the AMQP `message_id`; republishing the same row retains that ID. `correlationId` travels in message payloads and the AMQP `correlation_id` attribute. Orchestrator's [relay](../services/orchestrator-service/src/Command/OutboxRelayCommand.php) selects `command.bus` for commands and `event.bus` for terminal events, preserving the receiving bus through Messenger's `BusNameStamp`.

### Handling repeated delivery

Inventory, Payment and Orchestrator place `doctrine_transaction` before [inbox middleware](../packages/messenger-idempotency/src/IdempotencyMiddleware.php). The [claim store](../packages/messenger-idempotency/src/InboxMessageStore.php) inserts `(consumer_name, message_id)` with `ON CONFLICT DO NOTHING`; duplicate claims skip the handler. A failed handler rolls back its claim with the business and outbox writes. A separate transaction or external cache could not make these writes atomic.

Claims cost storage and one write per received message. The middleware only checks transport deliveries and rejects missing stable IDs as unrecoverable. Consumer names are `inventory_commands`, `process_payment` and `orchestrator_events`. Deduplication does not serialize different message IDs for the same reservation; the saga and seat locks address that separately.

Booking's terminal handlers only update status, so they retain transaction middleware and status guards without an inbox. Reassess that choice if they gain outgoing messages or external calls. Their guards are asymmetric: confirmation can replace cancellation; cancellation only accepts `PENDING`. Identical-event replay does not establish safe ordering of conflicting terminal events.

Seat unavailability and payment decline produce outcome events, completing the handler normally. They do not trigger technical retries. Transport failures and thrown technical errors use the currently configured Messenger behavior.

[Payment consumer tests](../services/payment-service/tests/MessageHandler/ProcessPaymentIdempotencyTest.php) count gateway calls and check claim rollback; [Inventory consumer tests](../services/inventory-service/tests/MessageHandler/HoldSeatsIdempotencyTest.php) deliver one envelope twice. [Redelivery E2E](../tests/e2e/saga-idempotency.sh) checks unchanged business state and message counts after republishing.

### Duplicate HTTP requests

For HTTP creation, [ReservationService](../services/booking-service/src/Service/ReservationService.php) compares `showId`, normalized seat IDs and buyer fields against the stored `Idempotency-Key`. Matching requests return the same reservation with its current status; a changed payload maps to HTTP `409`. A missing/empty key returns `400`; request constraint violations return `422`.

The database has a unique key constraint. The concurrent-insert catch clears but does not reset the EntityManager closed by a failed `wrapInTransaction`; recovery of concurrent HTTP duplicates is unverified.

## 6. Concurrency: acquiring seats

Inventory owns `Show`, `Seat` and `SeatHold`. A show has unique seat codes; API and message `seatIds` are seat UUIDs, not display codes such as `A12`. `showId` identifies the catalog show; classes under `Ticketing\Contracts\Event` are messages.

[SeatLockingService](../services/inventory-service/src/Service/SeatLockingService.php) deduplicates and sorts requested IDs. [SeatRepository::findForUpdateByShowAndIds()](../services/inventory-service/src/Repository/SeatRepository.php) locks each row in that order with `PESSIMISTIC_WRITE` and refreshes managed state with `HINT_REFRESH`. Only after all requested rows belong to the show and are available does the service modify any seat. Confirmation and release use the same ordering and check reservation ownership.

Seat transitions are `AVAILABLE → HELD → SOLD`, or `HELD → AVAILABLE` on release. [SeatHoldService](../services/inventory-service/src/Service/SeatHoldService.php) records the hold and outcome within the transaction in §3.

Individual row acquisition adds a query per seat but makes the acquisition order explicit. The trade-off assumes small seat sets. Contended requests wait, so keep the transaction short and free of network calls. Sorted acquisition reduces inconsistent ordering without proving all surrounding operations deadlock-free.

A conditional `UPDATE ... WHERE state = 'AVAILABLE'` is an alternative when the decision fits one atomic transition. Multi-seat acquisition would need an affected-row check against the normalized request count and rollback of partial acquisition before recording rejection. It still contends on rows; that implementation and benchmark do not exist here. Optimistic version checks would instead require conflict retries on popular seats; an unlocked read followed by a write cannot enforce the hold decision.

The [PostgreSQL contention runner](../tests/integration/inventory-concurrency.php) observes real lock waits with two processes and verifies both commit/rejection and rollback/success. Its scope is one seat and different reservations; it does not establish multi-seat deadlock freedom or flash-sale capacity.

## 7. Shared code boundaries

- [contracts/](../contracts/src/) contains framework-independent command/event DTOs. Composer path dependencies and PHP class names simplify this monorepo but couple producers and consumers to constructor and serialization shapes. A schema-only contract would require a separate compatibility policy.
- [packages/messenger-idempotency/](../packages/messenger-idempotency/src/) contains Symfony Messenger middleware and a DBAL claim store. Sharing this algorithm keeps infrastructure fixes consistent without adding framework dependencies to `contracts/`. Each consuming service owns its migrations, table, consumer name and bus configuration. There is no shared ORM entity; schema changes require coordinated package and service migrations.
- [packages/outbox/](../packages/outbox/src/) owns the recorder, technical `OutboxMessage` ORM entity, repository and `RoutingKeyResolverInterface`. The identical outbox schema is an explicit exception to keeping entities and repositories local: sharing its mapping and persistence removes four copies of infrastructure code. Each service registers the package's ORM mapping and supplies its own EntityManager and routing map. Each database retains its own `outbox_messages` table and migrations; schema changes in the package require coordinated service migrations. Relay commands remain local, including Orchestrator's command/event bus selection. The package retains ticketing-specific reservation metadata, existing table/column names and stored payload format; extraction does not change delivery guarantees. [Outbox transaction tests](dev-setup.md#outbox-transaction-tests) verify schema compatibility, recording and relay repository behavior in all four services against PostgreSQL.

Business entities, repositories and services remain local to their owning service. The shared outbox entity and repository are technical infrastructure; sharing their code does not share database rows or transactions between services.

## 8. Symfony layering

Services use flat Symfony folders. Separate request/response DTOs and mappers let the HTTP contract change independently of Doctrine mappings, at the cost of additional files per endpoint. No `Domain/` or `Application/` hierarchy is needed for these boundaries.

| Folder / configuration | Responsibility | Excluded dependencies |
|------------------------|----------------|-----------------------|
| `Controller/` | Routes, headers, `MapRequestPayload`, JSON responses | Business rules, Doctrine access |
| `Dto/` | Readonly request/response contracts; input validation | Doctrine and persistence logic |
| `Mapper/` | Entity → response DTO | HTTP, transactions, business decisions |
| `Entity/` | ORM mapping, persistence state and state transitions | API shape, `toResponse()`, `matchesRequest()`, Request DTOs, HTTP exceptions, Messenger |
| `Repository/` | Queries and persistence helpers | HTTP and API mapping |
| `Service/` | Use cases, transactions and idempotency | `JsonResponse`, HTTP exceptions, raw arrays as API responses |
| `Exception/` | Business errors extending `DomainException` | Extending `HttpException` |
| `config/packages/framework.yaml` | Map domain errors to HTTP status codes | Business decisions |
| `MessageHandler/` | Adapt a command/event to a service or coordinator | HTTP concerns |
| `Outbox/` | Record outgoing messages in the caller's transaction | Broker publication before commit |
| `Saga/` | Orchestrator workflow coordination | Seat or payment business rules |

Reference path: [ReservationController](../services/booking-service/src/Controller/ReservationController.php) → [CreateReservationRequest](../services/booking-service/src/Dto/CreateReservationRequest.php) → [ReservationService](../services/booking-service/src/Service/ReservationService.php) → [ReservationMapper](../services/booking-service/src/Mapper/ReservationMapper.php) → [ReservationResponse](../services/booking-service/src/Dto/ReservationResponse.php). [framework.yaml](../services/booking-service/config/packages/framework.yaml) maps the idempotency mismatch to `409`.

Keep API serialization groups on response DTOs if needed, not entities. API Platform, event sourcing, a full client application and multi-region deployment are outside this project's scope.
