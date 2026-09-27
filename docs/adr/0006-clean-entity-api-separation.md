# ADR 0006: Clean Entity — API separation in Symfony services

## Status

Accepted

## Context

We need consistent structure across microservices in this monorepo. Entities must remain persistence-focused. API contracts (JSON request/response) must not leak into Doctrine models.

## Decision

Per HTTP-enabled service (starting with `booking-service`):

1. **Entity** — Doctrine mapping + getters only. No `toResponse()`, no request matching, no HTTP exceptions.
2. **Dto/** — `*Request` (input + validation), `*Response` (output contract).
3. **Mapper/** — converts Entity → Response DTO.
4. **Service** — application logic; returns Response DTO or domain types; throws **domain** exceptions.
5. **Controller** — HTTP only; uses `MapRequestPayload`, `json()`.
6. **Exception/** — `DomainException` subclasses; HTTP status mapping in `config/packages/framework.yaml` under `framework.exceptions`.

Reference: `services/booking-service/src/` for `POST /api/reservations` with `Idempotency-Key`.

## Consequences

- Separate DTOs and mappers add files per endpoint, while allowing API contracts to change independently of Doctrine mappings.
- New code must follow the layer rules in `docs/architecture.md` §8.
- Idempotency comparison stays in Service (or future dedicated class), never on Entity.

## Alternatives considered

- `toResponse()` on Entity — rejected (couples API to persistence).
- `ConflictHttpException` in Service — rejected (couples HTTP to application layer).
- API Platform — rejected for this focused saga codebase.
