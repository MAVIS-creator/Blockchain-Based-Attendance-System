import http from 'k6/http';
import { check } from 'k6';
import { parseHTML } from 'k6/html';

const BASE_URL = (__ENV.BASE_URL || 'https://attendancev2app123.azurewebsites.net').replace(/\/+$/, '');
const INDEX_URL = `${BASE_URL}/index.php`;
const SUBMIT_URL = `${BASE_URL}/submit.php`;
const GEO_LAT = (__ENV.GEO_LAT || '').trim();
const GEO_LNG = (__ENV.GEO_LNG || '').trim();

export const options = {
  scenarios: {
    submit_burst: {
      executor: 'per-vu-iterations',
      vus: 200,
      iterations: 1,
      maxDuration: '2m',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.20'],
    http_req_duration: ['p(95)<5000', 'p(99)<8000'],
  },
};

function extractHiddenValue(doc, name) {
  const node = doc.find(`input[name="${name}"]`).first();
  return node ? String(node.attr('value') || '').trim() : '';
}

function uniqueId() {
  return `${__VU}_${__ITER}_${Date.now()}_${Math.floor(Math.random() * 100000)}`;
}

export function setup() {
  const res = http.get(INDEX_URL, { tags: { name: 'load_index' } });
  check(res, {
    'index reachable': r => r.status === 200,
  });

  const doc = parseHTML(res.body || '');
  const action = extractHiddenValue(doc, 'action');
  const course = extractHiddenValue(doc, 'course') || 'General';

  if (!action) {
    throw new Error('Failed to discover hidden action value from index.php');
  }

  return { action, course };
}

export default function (data) {
  const id = uniqueId();
  const matric = `LT${String(__VU).padStart(3, '0')}${String(__ITER).padStart(3, '0')}${String(Math.floor(Math.random() * 1000)).padStart(3, '0')}`.slice(0, 10);
  const payload = {
    name: `Load Test ${id}`,
    matric,
    fingerprint: `loadtest_${id}`,
    action: data.action,
    course: data.course,
  };

  if (GEO_LAT !== '' && GEO_LNG !== '') {
    payload.lat = GEO_LAT;
    payload.lng = GEO_LNG;
  }

  const res = http.post(SUBMIT_URL, payload, {
    tags: { name: 'submit_attendance' },
    headers: { Accept: 'application/json' },
  });

  let body = null;
  try {
    body = res.json();
  } catch (e) {
    body = null;
  }

  check(res, {
    'submit returned json-ish response': r => r.status === 200 || r.status === 400 || r.status === 403,
    'submit response parsed': () => body !== null,
  });

  if (body && body.ok === false) {
    console.log(`VU ${__VU} rejected: ${body.code || 'no_code'} :: ${body.message || 'no_message'}`);
  }
}
