// Charge du catalogue public (P8-03) : visiteurs anonymes qui parcourent sports, matchs et cotes.
// Objectif (décision P8-03) : 1 000 visiteurs simultanés, p95 < 200 ms, erreurs < 0,5 %.
import http from 'k6/http';
import { sleep } from 'k6';
import { API, expectStatus, headers } from './lib.js';

const VISITORS = Number(__ENV.VISITORS || 1000);

export const options = {
  insecureSkipTLSVerify: __ENV.INSECURE_TLS === '1',
  scenarios: {
    catalogue: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: __ENV.RAMP || '1m', target: VISITORS },
        { duration: __ENV.HOLD || '3m', target: VISITORS },
        { duration: '20s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    'http_req_duration{scenario:catalogue}': ['p(95)<200'],
    http_req_failed: ['rate<0.005'],
    checks: ['rate>0.995'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

/** Un visiteur : accueil (sports et matchs avec leurs cotes), puis tous les marchés d'un match, avec un temps de lecture entre les pages. */
export default function () {
  const h = { headers: headers(__VU) };

  const sports = http.get(`${API}/sports`, { ...h, tags: { name: 'GET /sports' } });
  expectStatus(sports, 200, 'GET /sports');

  const events = http.get(`${API}/events?per_page=20`, { ...h, tags: { name: 'GET /events' } });
  expectStatus(events, 200, 'GET /events');
  sleep(1 + Math.random() * 2);

  const list = events.status === 200 ? events.json('data') || [] : [];
  if (list.length > 0) {
    const event = list[Math.floor(Math.random() * list.length)];
    const markets = http.get(`${API}/events/${event.id}/markets`, { ...h, tags: { name: 'GET /events/{id}/markets' } });
    expectStatus(markets, 200, 'GET /events/{id}/markets');
  }
  sleep(1 + Math.random() * 2);
}

