#!/usr/bin/env bash

set -Eeuo pipefail

# Shared implementation; use the happy-path or payment-failure entry points.
readonly SCENARIO="${1:-happy-path}"
case "${SCENARIO}" in
    happy-path)
        PAYMENT_TOKEN=tok_fake_visa
        TERMINAL_STATUS=CONFIRMED
        SEAT_STATE=SOLD
        HOLD_STATUS=CONFIRMED
        PAYMENT_STATUS=PAID
        INVENTORY_ACTION=confirm
        INVENTORY_EVENT=confirmed
        PAYMENT_EVENT=succeeded
        BOOKING_EVENT=confirmed
        ;;
    payment-failure)
        PAYMENT_TOKEN=tok_decline
        TERMINAL_STATUS=CANCELLED
        SEAT_STATE=AVAILABLE
        HOLD_STATUS=RELEASED
        PAYMENT_STATUS=FAILED
        INVENTORY_ACTION=release
        INVENTORY_EVENT=released
        PAYMENT_EVENT=failed
        BOOKING_EVENT=cancelled
        ;;
    *)
        echo "Unknown E2E scenario: ${SCENARIO}" >&2
        exit 2
        ;;
esac
readonly PAYMENT_TOKEN TERMINAL_STATUS SEAT_STATE HOLD_STATUS PAYMENT_STATUS
readonly INVENTORY_ACTION INVENTORY_EVENT PAYMENT_EVENT BOOKING_EVENT

readonly TIMEOUT_SECONDS="${E2E_TIMEOUT_SECONDS:-90}"
readonly BOOKING_BASE_URL="${E2E_BOOKING_BASE_URL:-http://localhost:8080}"
readonly SKIP_STACK_START="${E2E_SKIP_STACK_START:-0}"

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
readonly TEMP_DIR="$(mktemp -d)"
readonly RESPONSE_FILE="${TEMP_DIR}/reservation-response.json"

compose=(docker compose)

cleanup()
{
    rm -rf "${TEMP_DIR}"
}

diagnostics()
{
    set +e
    echo >&2
    echo 'Docker Compose status:' >&2
    "${compose[@]}" ps >&2
    echo >&2
    echo 'Recent saga worker/relay logs:' >&2
    "${compose[@]}" logs --tail=80 \
        booking-worker booking-outbox-relay \
        orchestrator-worker orchestrator-outbox-relay \
        inventory-worker inventory-outbox-relay \
        payment-worker payment-outbox-relay >&2
}

fail()
{
    echo "E2E FAILED: $*" >&2
    diagnostics
    exit 1
}

on_error()
{
    local exit_code="$1"
    local line="$2"

    trap - ERR
    echo "E2E FAILED: unexpected command failure at line ${line}." >&2
    diagnostics
    exit "${exit_code}"
}

log()
{
    echo "[e2e] $*"
}

sql_query()
{
    local database="$1"
    local query="$2"

    "${compose[@]}" exec -T postgres \
        psql -X -qAt -v ON_ERROR_STOP=1 -U ticketing -d "${database}" -c "${query}" \
        | tr -d '\r'
}

sql_scalar()
{
    local database="$1"
    local query="$2"

    sql_query "${database}" "${query}" | tail -n 1
}

assert_equals()
{
    local expected="$1"
    local actual="$2"
    local description="$3"

    if [[ "${actual}" != "${expected}" ]]; then
        fail "${description}: expected '${expected}', got '${actual}'."
    fi
}

assert_sql()
{
    local database="$1"
    local query="$2"
    local expected="$3"
    local description="$4"
    local actual

    actual="$(sql_scalar "${database}" "${query}")"
    assert_equals "${expected}" "${actual}" "${description}"
}

wait_for_http()
{
    local url="$1"
    local deadline=$((SECONDS + TIMEOUT_SECONDS))

    while ((SECONDS < deadline)); do
        if curl --silent --show-error --output /dev/null "${url}"; then
            return
        fi

        sleep 1
    done

    fail "HTTP endpoint did not become reachable: ${url}"
}

wait_for_inventory_worker()
{
    local deadline=$((SECONDS + TIMEOUT_SECONDS))

    while ((SECONDS < deadline)); do
        if "${compose[@]}" exec -T inventory-worker \
            php bin/console about --no-interaction >/dev/null 2>&1; then
            return
        fi

        sleep 1
    done

    fail 'inventory-worker did not become ready.'
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
        if actual="$(sql_scalar "${database}" "${query}" 2>/dev/null)"; then
            if [[ "${actual}" == "${expected}" ]]; then
                return
            fi
        fi

        sleep 1
    done

    fail "${description}: expected '${expected}', last value '${actual}'."
}

wait_for_queues_idle()
{
    local deadline=$((SECONDS + TIMEOUT_SECONDS))
    local idle_samples=0
    local queue_stats=''
    local busy_messages=''

    while ((SECONDS < deadline)); do
        if queue_stats="$("${compose[@]}" exec -T rabbitmq \
            rabbitmqctl -q list_queues name messages_ready messages_unacknowledged 2>/dev/null)"; then
            busy_messages="$(printf '%s\n' "${queue_stats}" | awk 'NF >= 3 { total += $2 + $3 } END { print total + 0 }')"

            if [[ "${busy_messages}" == '0' ]]; then
                idle_samples=$((idle_samples + 1))
                if ((idle_samples >= 2)); then
                    return
                fi
            else
                idle_samples=0
            fi
        fi

        sleep 1
    done

    fail "RabbitMQ queues did not become idle. Last stats: ${queue_stats}"
}

wait_for_outbox_publication()
{
    local database="$1"
    local reservation_id="$2"

    wait_for_sql \
        "${database}" \
        "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}' AND published_at IS NULL" \
        '0' \
        "${database} outbox publication"
}

assert_outbox_claimed()
{
    local source_database="$1"
    local route_predicate="$2"
    local destination_database="$3"
    local consumer_name="$4"
    local reservation_id="$5"
    local message_ids
    local message_id
    local claim_count

    message_ids="$(sql_query "${source_database}" \
        "SELECT id FROM outbox_messages WHERE reservation_id = '${reservation_id}' AND (${route_predicate}) ORDER BY id")"

    if [[ -z "${message_ids}" ]]; then
        fail "No ${source_database} outbox messages matched route predicate: ${route_predicate}"
    fi

    while IFS= read -r message_id; do
        claim_count="$(sql_scalar "${destination_database}" \
            "SELECT COUNT(*) FROM inbox_messages WHERE consumer_name = '${consumer_name}' AND message_id = '${message_id}'")"
        assert_equals \
            '1' \
            "${claim_count}" \
            "Inbox claim ${destination_database}.${consumer_name} for message ${message_id}"
    done <<<"${message_ids}"
}

assert_all_claims()
{
    local reservation_id="$1"

    assert_outbox_claimed booking 'TRUE' orchestrator orchestrator_events "${reservation_id}"
    assert_outbox_claimed inventory 'TRUE' orchestrator orchestrator_events "${reservation_id}"
    assert_outbox_claimed payment 'TRUE' orchestrator orchestrator_events "${reservation_id}"
    assert_outbox_claimed orchestrator "routing_key IN ('seats.hold', 'seats.${INVENTORY_ACTION}')" inventory inventory_commands "${reservation_id}"
    assert_outbox_claimed orchestrator "routing_key = 'payment.process'" payment process_payment "${reservation_id}"
}

assert_scenario_outcome()
{
    assert_sql inventory \
        "SELECT status FROM seat_holds WHERE reservation_id = '${reservation_id}'" \
        "${HOLD_STATUS}" 'Hold terminal status'
    assert_sql booking "SELECT to_regclass('public.inbox_messages') IS NULL" 't' 'Booking has no inbox table'

    assert_sql booking \
        "SELECT string_agg(routing_key, ',' ORDER BY routing_key) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
        'reservation.requested' 'Booking event chain'
    assert_sql inventory \
        "SELECT string_agg(routing_key, ',' ORDER BY routing_key) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
        "$(printf '%s\n' seats.held "seats.${INVENTORY_EVENT}" | LC_ALL=C sort | paste -sd, -)" 'Inventory event chain'
    assert_sql payment \
        "SELECT string_agg(routing_key, ',' ORDER BY routing_key) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
        "payment.${PAYMENT_EVENT}" 'Payment event chain'
    assert_sql orchestrator \
        "SELECT string_agg(routing_key, ',' ORDER BY routing_key) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
        "$(printf '%s\n' payment.process "reservation.${BOOKING_EVENT}" seats.hold "seats.${INVENTORY_ACTION}" | LC_ALL=C sort | paste -sd, -)" 'Orchestrator command/event chain'

    if [[ "${SCENARIO}" == 'payment-failure' ]]; then
        assert_sql inventory \
            "SELECT held_by_reservation_id IS NULL FROM seats WHERE id = '${seat_id}'" \
            't' 'Released seat has no owner'
        assert_sql payment \
            "SELECT failure_reason FROM payments WHERE reservation_id = '${reservation_id}'" \
            'PAYMENT_DECLINED' 'Payment decline reason'
        assert_sql payment \
            "SELECT paid_at IS NULL AND gateway_payment_id IS NULL AND failed_at IS NOT NULL FROM payments WHERE reservation_id = '${reservation_id}'" \
            't' 'Declined payment has no successful payment result'
        assert_sql orchestrator \
            "SELECT failure_reason FROM sagas WHERE reservation_id = '${reservation_id}'" \
            'PAYMENT_DECLINED' 'Saga compensation reason'
    fi
}

trap cleanup EXIT
trap 'on_error $? $LINENO' ERR

cd "${REPO_ROOT}"

[[ "${TIMEOUT_SECONDS}" =~ ^[1-9][0-9]*$ ]] || fail 'E2E_TIMEOUT_SECONDS must be a positive integer.'
command -v docker >/dev/null 2>&1 || fail 'docker is required.'
command -v curl >/dev/null 2>&1 || fail 'curl is required.'
docker info >/dev/null 2>&1 || fail 'Docker daemon is not available.'

if [[ "${SKIP_STACK_START}" != '1' ]]; then
    log "Building and starting the Docker Compose stack for ${SCENARIO}."
    # Existing booking workers must stop before the inbox-removal migration.
    "${compose[@]}" stop booking booking-worker booking-outbox-relay
    PAYMENT_METHOD_TOKEN="${PAYMENT_TOKEN}" "${compose[@]}" up -d --build
fi

log 'Waiting for HTTP and inventory worker readiness.'
wait_for_http "${BOOKING_BASE_URL}/api/reservations/e2e-readiness"
wait_for_inventory_worker
actual_token="$("${compose[@]}" exec -T orchestrator-worker printenv PAYMENT_METHOD_TOKEN | tr -d '\r')"
assert_equals "${PAYMENT_TOKEN}" "${actual_token}" 'Orchestrator payment token (restart the stack for this scenario)'

run_token="$(date +%s)-${RANDOM}"
show_id="e2e-${run_token}"
idempotency_key="e2e-${SCENARIO}-${run_token}"

log "Seeding one seat for show ${show_id}."
"${compose[@]}" exec -T inventory-worker \
    php bin/console app:inventory:seed-seats "${show_id}" A1 --name="E2E ${SCENARIO}" >/dev/null

seat_id="$(sql_scalar inventory \
    "SELECT id FROM seats WHERE show_id = '${show_id}' AND seat_code = 'A1'")"
[[ "${seat_id}" =~ ^[0-9a-f-]{36}$ ]] || fail "Could not resolve seeded seat UUID; got '${seat_id}'."

printf -v request_payload \
    '{"showId":"%s","seatIds":["%s"],"buyerName":"E2E Test","buyerEmail":"e2e@example.com"}' \
    "${show_id}" \
    "${seat_id}"

log 'Creating reservation through booking-service.'
http_status="$(curl --silent --show-error \
    --output "${RESPONSE_FILE}" \
    --write-out '%{http_code}' \
    --request POST "${BOOKING_BASE_URL}/api/reservations" \
    --header 'Content-Type: application/json' \
    --header "Idempotency-Key: ${idempotency_key}" \
    --data "${request_payload}")"

if [[ "${http_status}" != '202' ]]; then
    fail "Reservation request returned HTTP ${http_status}: $(<"${RESPONSE_FILE}")"
fi

reservation_id="$(sql_scalar booking \
    "SELECT id FROM reservations WHERE idempotency_key = '${idempotency_key}'")"
[[ "${reservation_id}" =~ ^[0-9a-f-]{36}$ ]] || fail "Could not resolve reservation UUID; got '${reservation_id}'."

log "Waiting for reservation ${reservation_id} to reach the ${SCENARIO} terminal state."
wait_for_sql orchestrator \
    "SELECT state FROM sagas WHERE reservation_id = '${reservation_id}'" \
    "${TERMINAL_STATUS}" \
    'Saga terminal state'
wait_for_sql booking \
    "SELECT status FROM reservations WHERE id = '${reservation_id}'" \
    "${TERMINAL_STATUS}" \
    'Booking reservation status'
wait_for_sql inventory \
    "SELECT state FROM seats WHERE id = '${seat_id}'" \
    "${SEAT_STATE}" \
    'Inventory seat state'
wait_for_sql inventory \
    "SELECT status FROM seat_holds WHERE reservation_id = '${reservation_id}'" \
    "${HOLD_STATUS}" \
    'Inventory hold status'
wait_for_sql payment \
    "SELECT status FROM payments WHERE reservation_id = '${reservation_id}'" \
    "${PAYMENT_STATUS}" \
    'Payment status'

for database in booking inventory payment orchestrator; do
    wait_for_outbox_publication "${database}" "${reservation_id}"
done
wait_for_queues_idle

log 'Checking the initial business records, outbox rows, and inbox claims.'
assert_sql booking \
    "SELECT COUNT(*) FROM reservations WHERE id = '${reservation_id}'" \
    '1' \
    'Booking reservation count'
assert_sql inventory \
    "SELECT COUNT(*) FROM seat_holds WHERE reservation_id = '${reservation_id}'" \
    '1' \
    'Inventory hold count'
assert_sql payment \
    "SELECT COUNT(*) FROM payments WHERE reservation_id = '${reservation_id}'" \
    '1' \
    'Payment count'
assert_sql orchestrator \
    "SELECT COUNT(*) FROM sagas WHERE reservation_id = '${reservation_id}'" \
    '1' \
    'Saga count'

booking_outbox_before="$(sql_scalar booking \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'")"
inventory_outbox_before="$(sql_scalar inventory \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'")"
payment_outbox_before="$(sql_scalar payment \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'")"
orchestrator_outbox_before="$(sql_scalar orchestrator \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'")"
seat_row_revision_before="$(sql_scalar inventory "SELECT xmin::text FROM seats WHERE id = '${seat_id}'")"
saga_updated_before="$(sql_scalar orchestrator \
    "SELECT updated_at::text FROM sagas WHERE reservation_id = '${reservation_id}'")"

assert_equals '1' "${booking_outbox_before}" 'Booking outbox count'
assert_equals '2' "${inventory_outbox_before}" 'Inventory outbox count'
assert_equals '1' "${payment_outbox_before}" 'Payment outbox count'
assert_equals '4' "${orchestrator_outbox_before}" 'Orchestrator outbox count'
assert_all_claims "${reservation_id}"
assert_scenario_outcome

payment_before="$(sql_scalar payment "SELECT row_to_json(p)::text FROM payments p WHERE reservation_id = '${reservation_id}'")"
hold_before="$(sql_scalar inventory "SELECT row_to_json(h)::text FROM seat_holds h WHERE reservation_id = '${reservation_id}'")"

log 'Marking all saga outbox rows unpublished to force redelivery with the same AMQP message_id.'
for database in booking inventory payment orchestrator; do
    sql_query "${database}" \
        "UPDATE outbox_messages SET published_at = NULL WHERE reservation_id = '${reservation_id}'" >/dev/null
done

for database in booking inventory payment orchestrator; do
    wait_for_outbox_publication "${database}" "${reservation_id}"
done
wait_for_queues_idle

log 'Verifying that redelivery produced no additional side effects.'
assert_sql booking \
    "SELECT status FROM reservations WHERE id = '${reservation_id}'" \
    "${TERMINAL_STATUS}" \
    'Booking status after redelivery'
assert_sql inventory \
    "SELECT state FROM seats WHERE id = '${seat_id}'" \
    "${SEAT_STATE}" \
    'Seat state after redelivery'
assert_sql inventory \
    "SELECT xmin::text FROM seats WHERE id = '${seat_id}'" \
    "${seat_row_revision_before}" \
    'Seat row revision after redelivery'
assert_sql payment \
    "SELECT status FROM payments WHERE reservation_id = '${reservation_id}'" \
    "${PAYMENT_STATUS}" \
    'Payment status after redelivery'
assert_sql orchestrator \
    "SELECT state FROM sagas WHERE reservation_id = '${reservation_id}'" \
    "${TERMINAL_STATUS}" \
    'Saga state after redelivery'
assert_sql orchestrator \
    "SELECT updated_at::text FROM sagas WHERE reservation_id = '${reservation_id}'" \
    "${saga_updated_before}" \
    'Saga updated_at after redelivery'

assert_sql booking \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
    "${booking_outbox_before}" \
    'Booking outbox count after redelivery'
assert_sql inventory \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
    "${inventory_outbox_before}" \
    'Inventory outbox count after redelivery'
assert_sql payment \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
    "${payment_outbox_before}" \
    'Payment outbox count after redelivery'
assert_sql orchestrator \
    "SELECT COUNT(*) FROM outbox_messages WHERE reservation_id = '${reservation_id}'" \
    "${orchestrator_outbox_before}" \
    'Orchestrator outbox count after redelivery'

assert_sql inventory \
    "SELECT COUNT(*) FROM seat_holds WHERE reservation_id = '${reservation_id}'" \
    '1' \
    'Inventory hold count after redelivery'
assert_sql payment \
    "SELECT COUNT(*) FROM payments WHERE reservation_id = '${reservation_id}'" \
    '1' \
    'Payment count after redelivery'
assert_all_claims "${reservation_id}"
assert_scenario_outcome

assert_sql payment "SELECT row_to_json(p)::text FROM payments p WHERE reservation_id = '${reservation_id}'" "${payment_before}" 'Payment unchanged after redelivery'
assert_sql inventory "SELECT row_to_json(h)::text FROM seat_holds h WHERE reservation_id = '${reservation_id}'" "${hold_before}" 'Hold unchanged after redelivery'

log "PASS: ${SCENARIO} and duplicate redelivery are idempotent for reservation ${reservation_id}."
