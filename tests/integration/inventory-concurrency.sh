#!/usr/bin/env bash
set -Eeuo pipefail
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${REPO_ROOT}"
# Requires running PostgreSQL and the existing inventory image/dependencies.
docker compose run --rm --no-deps \
    -v "${REPO_ROOT}/services/inventory-service/src:/app/services/inventory-service/src:ro" \
    -v "${REPO_ROOT}/services/inventory-service/config:/app/services/inventory-service/config:ro" \
    -v "${REPO_ROOT}/services/inventory-service/migrations:/app/services/inventory-service/migrations:ro" \
    -v "${SCRIPT_DIR}:/tests:ro" \
    --entrypoint php inventory /tests/inventory-concurrency.php
