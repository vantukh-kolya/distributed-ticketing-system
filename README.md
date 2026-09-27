# Ticketing

Ticket reservation across four services: hold seats, process payment, then confirm the booking or release the seats on failure.

Each service owns its database. An orchestrated saga coordinates the workflow over RabbitMQ, with transactional outboxes and idempotent consumers. PostgreSQL integration tests exercise competing seat holds and saga transitions; E2E tests cover successful bookings, payment failure, and repeated message delivery.

**Stack:** PHP · Symfony · PostgreSQL · RabbitMQ · Docker Compose

## Design

- Concurrent reservations, transaction boundaries, and database locking.
- Idempotent APIs and consumers under at-least-once delivery.
- Reliable messaging with transactional Outbox / Inbox patterns.
- Saga coordination, compensation, and eventual consistency.

## Architecture

Booking, Inventory, Payment, and Orchestrator each own a separate PostgreSQL database and communicate through RabbitMQ.

```mermaid
flowchart LR
    client["API Client<br/>[Person]"]

    subgraph system["Ticketing Reservation System"]
        booking["Booking Service<br/>[PHP · Symfony]<br/>Reservation API"]

        orchestrator["Orchestrator Service<br/>[PHP · Symfony Messenger]<br/>Saga coordinator"]

        inventory["Inventory Service<br/>[PHP · Symfony Messenger]<br/>Seat management"]

        payment["Payment Service<br/>[PHP · Symfony Messenger]<br/>Payment processing"]

        subgraph rabbit["RabbitMQ 3.13<br/>Direct exchange: ticketing"]
            orchestratorQueue["orchestrator.queue"]
            inventoryQueue["inventory.queue"]
            paymentQueue["payment.queue"]
            bookingQueue["booking.queue"]
        end
    end

    client -->|"POST /api/reservations<br/>HTTPS · JSON · Idempotency-Key<br/>Response: 202 Accepted"| booking

    booking -.->|"reservation.requested"| orchestratorQueue
    orchestratorQueue -.->|"consume"| orchestrator

    orchestrator -.->|"seats.hold<br/>seats.release<br/>seats.confirm"| inventoryQueue
    inventoryQueue -.->|"consume"| inventory

    inventory -.->|"seats.held<br/>seats.hold_rejected<br/>seats.released<br/>seats.confirmed"| orchestratorQueue

    orchestrator -.->|"payment.process"| paymentQueue
    paymentQueue -.->|"consume"| payment

    payment -.->|"payment.succeeded<br/>payment.failed"| orchestratorQueue

    orchestrator -.->|"reservation.confirmed<br/>reservation.cancelled"| bookingQueue
    bookingQueue -.->|"consume"| booking

    classDef person fill:#08427b,color:#ffffff,stroke:#052e56,stroke-width:2px;
    classDef service fill:#438dd5,color:#ffffff,stroke:#2e6295,stroke-width:2px;
    classDef queue fill:#f59e0b,color:#1f2937,stroke:#b45309,stroke-width:2px;

    class client person;
    class booking,orchestrator,inventory,payment service;
    class orchestratorQueue,inventoryQueue,paymentQueue,bookingQueue queue;

    style system fill:#f8fafc,stroke:#475569,stroke-width:2px
    style rabbit fill:#fff7ed,stroke:#b45309,stroke-width:2px

    linkStyle 0 stroke:#15803d,stroke-width:3px
    linkStyle 1,2,3,4,5,6,7,8,9,10 stroke:#d97706,stroke-width:2px,stroke-dasharray:6 4
```

Diagram source: [reservation-container.mmd](docs/diagrams/reservation-container.mmd).

## Reservation flow

The booking API accepts the request and records an outbox event. The saga then coordinates seat holding, payment, and confirmation or compensation.

<details>
<summary>Reservation creation: HTTP, idempotency, and outbox</summary>

![Reservation creation](docs/diagrams/reservation-flow-sequence.svg)

[Open full-size diagram](docs/diagrams/reservation-flow-sequence.svg) · [PlantUML source](docs/diagrams/reservation-flow-sequence.puml)

</details>

<details>
<summary>Saga: confirmation, seat rejection, and payment failure</summary>

![Reservation saga](docs/diagrams/reservation-saga-sequence.svg)

[Open full-size diagram](docs/diagrams/reservation-saga-sequence.svg) · [PlantUML source](docs/diagrams/reservation-saga-sequence.puml)

</details>

## Run and explore

Start with the [development setup](docs/dev-setup.md) for Docker Compose, the Reservation Console, and verification commands. See the [architecture guide](docs/architecture.md) for boundaries and trade-offs, and [current state](docs/current-state.md) for implemented scenarios and open work.

The payment gateway is fake; hold expiry, late-payment compensation, and a failed-message transport remain open work. Load testing is currently limited to HTTP acceptance.
