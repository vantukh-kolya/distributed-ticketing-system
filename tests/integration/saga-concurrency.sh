#!/usr/bin/env bash
set -Eeuo pipefail
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${REPO_ROOT}"
# Uses the existing PHP image/dependencies and PostgreSQL; mounts current source.
docker compose run --rm --no-deps \
    -v "${REPO_ROOT}/services/orchestrator-service/src:/app/services/orchestrator-service/src:ro" \
    -v "${REPO_ROOT}/services/orchestrator-service/config:/app/services/orchestrator-service/config:ro" \
    -v "${REPO_ROOT}/contracts/src:/app/contracts/src:ro" \
    -v "${SCRIPT_DIR}:/tests:ro" \
    --entrypoint php orchestrator /tests/saga-concurrency.php
