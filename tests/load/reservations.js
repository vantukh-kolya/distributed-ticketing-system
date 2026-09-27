import http from 'k6/http';
import { check } from 'k6';

const baseUrl = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const vus = Number(__ENV.VUS || 10);
const mode = __ENV.MODE || 'burst';
const rps = Number(__ENV.RPS || 10);
const preAllocatedVUs = Number(__ENV.PRE_ALLOCATED_VUS || 50);

if (!['burst', 'rate'].includes(mode)) {
  throw new Error('MODE must be burst or rate.');
}
if (!Number.isInteger(rps) || rps < 1 || !Number.isInteger(preAllocatedVUs) || preAllocatedVUs < 1) {
  throw new Error('RPS and PRE_ALLOCATED_VUS must be positive integers.');
}

if (!Number.isInteger(vus) || vus < 1) {
  throw new Error('VUS must be a positive integer.');
}

for (const name of ['SHOW_ID', 'SEAT_ID', 'RUN_ID']) {
  if (!__ENV[name] || !__ENV[name].trim()) {
    throw new Error(`${name} is required.`);
  }
}

export const options = {
  scenarios: {
    reserve: mode === 'rate' ? {
      executor: 'constant-arrival-rate',
      rate: rps,
      timeUnit: '1s',
      duration: __ENV.DURATION || '60s',
      preAllocatedVUs,
    } : {
      executor: 'per-vu-iterations',
      vus,
      iterations: 1,
      maxDuration: '30s',
    },
  },
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
    ...(mode === 'rate' ? { dropped_iterations: ['count==0'] } : {}),
  },
};

// Each buyer requests the same seat with a distinct idempotency key.
// HTTP 202 verifies acceptance only; final saga outcomes need a separate check.
export default function () {
  const response = http.post(
    `${baseUrl}/api/reservations`,
    JSON.stringify({
      showId: __ENV.SHOW_ID,
      seatIds: [__ENV.SEAT_ID],
      buyerName: `Buyer ${__VU}`,
      buyerEmail: `buyer${__VU}@example.com`,
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        'Idempotency-Key': `${__ENV.RUN_ID}-${__VU}-${__ITER}`,
      },
      timeout: '15s',
    },
  );

  check(response, {
    'reservation accepted': (r) => r.status === 202,
  });
}
