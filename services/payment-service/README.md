# Payment service

Worker-only service that consumes `ProcessPayment` and records a payment outcome. Its stored lifecycle is `PENDING → PAID | FAILED`.

| Code | Responsibility |
|------|----------------|
| [PaymentService](src/Service/PaymentService.php) | Process a reservation payment and record its outcome |
| [PaymentGatewayInterface](src/Gateway/PaymentGatewayInterface.php) | Gateway request/result boundary |
| [FakePaymentGateway](src/Gateway/FakePaymentGateway.php) | `tok_decline` returns a declined result; other tokens succeed |
| [Consumer test](tests/MessageHandler/ProcessPaymentIdempotencyTest.php) | Duplicate delivery and claim rollback |

Use [development setup](../../docs/dev-setup.md) for the worker runtime and controlled failure scenario. [Project scope](../../README.md#scope-and-limitations) records provider and recovery limits; [transaction boundaries](../../docs/architecture.md#3-transaction-boundaries) describe the gateway call's placement.
