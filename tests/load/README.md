# Booking HTTP load tests

Run from the repository root with Docker Compose running. Set `SHOW_ID` and
`SEAT_ID` to an existing inventory show and seat UUID.

```bash
docker run --rm -i grafana/k6 run \
  -e MODE=rate \
  -e RPS=10 \
  -e DURATION=60s \
  -e BASE_URL=http://host.docker.internal:8080 \
  -e SHOW_ID=load-test-01 \
  -e SEAT_ID='<actual-seat-uuid>' \
  -e RUN_ID="$(uuidgen)" \
  - < tests/load/reservations.js
```

Rate mode schedules one POST per iteration, targeting `RPS` requests per second.
Start with 10, then try 25, 50 and 100 in separate runs after checking results
and allowing downstream queues to drain. Each run creates new reservations.
The default duration is 60 seconds; `PRE_ALLOCATED_VUS` defaults to 50.
If no virtual user is available, k6 drops the iteration and the test fails its
`dropped_iterations` threshold. Inspect latency and load-generator resources
before increasing the VU allocation.

Without `MODE=rate`, the script runs the original burst: `VUS` users (default 10)
each send one request. Every request uses a distinct idempotency key.

Checks require HTTP 202 and zero HTTP failures. Review p95 latency in the summary;
no latency SLO is imposed. This measures reservation acceptance, not completed
sagas. Reusing a single seat mainly exercises downstream rejection after it is
held or sold. Successful checkout throughput requires fresh seats per booking
and a separate check of terminal saga states.

Docker Desktop exposes the Mac host as `host.docker.internal`. Local k6 defaults
to `http://localhost:8080`. The current Compose HTTP service uses `php -S`, so
results describe that development configuration, not a production PHP-FPM setup.

Executor documentation:
https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/constant-arrival-rate/
