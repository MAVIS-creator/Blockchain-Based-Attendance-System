import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = (__ENV.BASE_URL || 'https://smart-attendance-samex.me').replace(/\/+$/, '');

export const options = {
  scenarios: {
    public_view_burst: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 50 },
        { duration: '30s', target: 100 },
        { duration: '60s', target: 150 },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<3000', 'p(99)<5000'],
  },
};

function uniqueFingerprint() {
  return `view_${__VU}_${__ITER}_${Date.now()}_${Math.floor(Math.random() * 100000)}`;
}

export default function () {
  const fingerprint = uniqueFingerprint();

  const responses = http.batch([
    ['GET', `${BASE_URL}/index.php`, null, { tags: { name: 'view_index' } }],
    ['GET', `${BASE_URL}/status_api.php`, null, { tags: { name: 'view_status_api' }, headers: { Accept: 'application/json' } }],
    ['GET', `${BASE_URL}/get_announcement.php?fingerprint=${encodeURIComponent(fingerprint)}`, null, { tags: { name: 'view_announcement' }, headers: { Accept: 'application/json' } }],
  ]);

  check(responses[0], {
    'index status 200': r => r.status === 200,
  });

  check(responses[1], {
    'status api reachable': r => r.status === 200,
  });

  check(responses[2], {
    'announcement api reachable': r => r.status === 200,
  });

  sleep(1);
}
