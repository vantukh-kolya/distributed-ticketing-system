#!/usr/bin/env bash

set -Eeuo pipefail

readonly TIMEOUT_SECONDS="${E2E_TIMEOUT_SECONDS:-90}"
readonly BOOKING_BASE_URL="${E2E_BOOKING_BASE_URL:-http://localhost:8080}"
readonly SKIP_STACK_START="${E2E_SKIP_STACK_START:-0}"

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

compose=(docker compose)

log()
{
    echo "[e2e] $*"
}

fail()
{
    echo "E2E FAILED: $*" >&2
    exit 1
}

sql_scalar()
{
    local database="$1"
    local query="$2"

    "${compose[@]}" exec -T postgres \
        psql -X -qAt -v ON_ERROR_STOP=1 -U ticketing -d "${database}" -c "${query}" \
        | tr -d '\r' \
        | tail -n 1
}

wait_for_http()
{
    local url="$1"
    local deadline=$((SECONDS + TIMEOUT_SECONDS))

    while ((SECONDS < deadline)); do
        if curl --silent --output /dev/null "${url}"; then
            return
        fi

        sleep 1
    done

    fail "HTTP endpoint did not become reachable: ${url}"
}

wait_for_sql()
{
    local database="$1"
    local query="$2"
    local expected="$3"
    local description="$4"
    local deadline=$((SECONDS + TIMEOUT_SECONDS))
    local actual=''

    while ((SECONDS < deadline)); do
        actual="$(sql_scalar "${database}" "${query}" 2>/dev/null || true)"
        if [[ "${actual}" == "${expected}" ]]; then
            log "OK: ${description} = ${expected}"
            return
        fi

        sleep 1
    done

    fail "${description}: expected '${expected}', last value '${actual}'."
}

cd "${REPO_ROOT}"

[[ "${TIMEOUT_SECONDS}" =~ ^[1-9][0-9]*$ ]] || fail 'E2E_TIMEOUT_SECONDS must be a positive integer.'
command -v docker >/dev/null 2>&1 || fail 'docker is required.'
command -v curl >/dev/null 2>&1 || fail 'curl is required.'

if [[ "${SKIP_STACK_START}" != '1' ]]; then
    log 'Starting the Docker Compose stack.'
    PAYMENT_METHOD_TOKEN=tok_fake_visa "${compose[@]}" up -d --build
fi

log 'Waiting for booking-service.'
wait_for_http "${BOOKING_BASE_URL}/api/reservations/e2e-readiness"

run_id="$(date +%s)-${RANDOM}"
show_id="e2e-${run_id}"
idempotency_key="e2e-happy-${run_id}"

log "Creating show ${show_id} with seat A1."
"${compose[@]}" exec -T inventory-worker \
    php bin/console app:inventory:seed-seats "${show_id}" A1 --name='E2E Happy Path' >/dev/null

seat_id="$(sql_scalar inventory \
    "SELECT id FROM seats WHERE show_id = '${show_id}' AND seat_code = 'A1'")"
[[ "${seat_id}" =~ ^[0-9a-f-]{36}$ ]] || fail "Seat UUID was not found; got '${seat_id}'."

log 'Creating a reservation through booking-service.'
http_status="$(curl --silent --show-error \
    --output /dev/null \
    --write-out '%{http_code}' \
    --request POST "${BOOKING_BASE_URL}/api/reservations" \
    --header 'Content-Type: application/json' \
    --header "Idempotency-Key: ${idempotency_key}" \
    --data "{\"showId\":\"${show_id}\",\"seatIds\":[\"${seat_id}\"],\"buyerName\":\"E2E Test\",\"buyerEmail\":\"e2e@example.com\"}")"
[[ "${http_status}" == '202' ]] || fail "Reservation request returned HTTP ${http_status}."

reservation_id="$(sql_scalar booking \
    "SELECT id FROM reservations WHERE idempotency_key = '${idempotency_key}'")"
[[ "${reservation_id}" =~ ^[0-9a-f-]{36}$ ]] || fail "Reservation UUID was not found; got '${reservation_id}'."

log "Waiting for reservation ${reservation_id} to complete."
wait_for_sql orchestrator \
    "SELECT state FROM sagas WHERE reservation_id = '${reservation_id}'" \
    'CONFIRMED' \
    'saga state'
wait_for_sql booking \
    "SELECT status FROM reservations WHERE id = '${reservation_id}'" \
    'CONFIRMED' \
    'reservation status'
wait_for_sql inventory \
    "SELECT state FROM seats WHERE id = '${seat_id}'" \
    'SOLD' \
    'seat state'
wait_for_sql payment \
    "SELECT status FROM payments WHERE reservation_id = '${reservation_id}'" \
    'PAID' \
    'payment status'

log "PASS: happy path completed for reservation ${reservation_id}."
