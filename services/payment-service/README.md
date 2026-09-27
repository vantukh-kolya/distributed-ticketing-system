# payment-service

Worker-only service with a deliberately small payment model: `PENDING → PAID | FAILED`.

## Scope (MVP)

| Command | Result |
|---------|--------|
| `ProcessPayment` | `PaymentSucceeded` |
| `ProcessPayment` with `tok_decline` | `PaymentFailed` |

Authorization, capture, void, refund, and a real provider integration are intentionally out of scope.

## Commands

```bash
composer install
php bin/console doctrine:migrations:migrate
php bin/console app:outbox:relay
```
