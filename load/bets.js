// Charge de POST /bets (P8-03) : joueurs connectés qui placent des paris simples au coup d'envoi.
// Objectif (décision P8-03) : 30 paris/s, p95 < 500 ms, erreurs < 0,5 %.
//
// Préparation (setup, hors mesure) : PLAYERS comptes créés par l'API (crédit de bienvenue fictif, l'inscription ouvre
// la session) et sélections ouvertes lues dans le catalogue. Chaque visiteur virtuel reprend la session (cookies) d'un
// joueur, puis enchaîne les paris : la mesure ne porte que sur POST /bets, pas sur 200 connexions simultanées.
import http from 'k6/http';
import exec from 'k6/execution';
import { API, BASE_URL, csrf, expectStatus, headers, uuid, writeHeaders } from './lib.js';

const RATE = Number(__ENV.RATE || 30);
const PLAYERS = Number(__ENV.PLAYERS || 200);
const PASSWORD = 'Charge-P8-03-2026';

export const options = {
  insecureSkipTLSVerify: __ENV.INSECURE_TLS === '1',
  setupTimeout: '10m',
  // Un joueur garde sa session (cookies) d'un pari à l'autre, comme un navigateur.
  noCookiesReset: true,
  scenarios: {
    bets: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: __ENV.DURATION || '3m',
      preAllocatedVUs: PLAYERS,
      maxVUs: PLAYERS,
    },
  },
  thresholds: {
    'http_req_duration{name:POST /bets}': ['p(95)<500'],
    'http_req_failed{name:POST /bets}': ['rate<0.005'],
    // Un passage sans aucun pari ne peut pas réussir.
    checks: ['rate>0.995'],
    'iterations': ['count>0'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

/** Téléphone de test du joueur n (8 chiffres, propre à ce passage). */
const phoneOf = (run, n) => `4${run}${String(n).padStart(4, '0')}`;

export function setup() {
  const run = String(Date.now()).slice(-3);
  // Comptes joueurs : inscription par l'API, une IP par joueur (limite de 5 inscriptions/min par IP).
  const sessions = [];
  for (let n = 1; n <= PLAYERS; n++) {
    http.cookieJar().clear(BASE_URL);
    csrf(100000 + n);
    const res = http.post(`${API}/auth/register`, JSON.stringify({
      name: `Joueur charge ${n}`, phone: phoneOf(run, n), birth_date: '1990-01-01', country: 'HT',
      password: PASSWORD, password_confirmation: PASSWORD,
    }), { headers: writeHeaders(100000 + n), tags: { name: 'setup register' } });
    if (res.status !== 201) throw new Error(`Inscription du joueur ${n} : ${res.status} ${res.body}`);
    const cookies = http.cookieJar().cookiesForURL(BASE_URL);
    sessions.push(Object.fromEntries(Object.entries(cookies).map(([name, values]) => [name, values[0]])));
  }

  // Sélections pariables : 1X2 des matchs à venir, avec leur cote actuelle.
  const events = http.get(`${API}/events?per_page=50`, { headers: headers(1) }).json('data') || [];
  const selections = [];
  for (const event of events) {
    for (const market of event.markets || []) {
      if (!market.bettable) continue;
      for (const s of market.selections || []) selections.push({ id: Number(s.id), odds: s.odds });
    }
  }
  if (selections.length === 0) throw new Error('Aucune sélection pariable : synchroniser le catalogue (sports-data:sync).');
  return { sessions, selections };
}

let session = false;

export default function (data) {
  const vu = exec.vu.idInTest;
  if (!session) {
    const jar = http.cookieJar();
    for (const [name, value] of Object.entries(data.sessions[(vu - 1) % data.sessions.length])) jar.set(BASE_URL, name, value);
    session = true;
  }

  const pick = data.selections[Math.floor(Math.random() * data.selections.length)];
  const res = http.post(`${API}/bets`, JSON.stringify({
    stake: { minor: 1000, currency: 'HTG' },
    selections: [{ selection_id: pick.id, odds: pick.odds }],
  }), { headers: writeHeaders(vu, { 'Idempotency-Key': uuid() }), tags: { name: 'POST /bets' } });
  if (!expectStatus(res, 201, 'POST /bets') && __ENV.DEBUG) console.warn(`POST /bets ${res.status} ${res.body}`);
}

