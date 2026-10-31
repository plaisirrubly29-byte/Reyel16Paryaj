// Outils communs des tests de charge k6 (P8-03) — voir load/README.md.
import http from 'k6/http';
import { check } from 'k6';

/** Site testé : la pile de production (Caddy + php-fpm). Défaut : k6 dans le réseau du conteneur web. */
export const BASE_URL = __ENV.BASE_URL || 'https://localhost';
export const API = `${BASE_URL}/api/v1`;

/**
 * Adresse IP propre à chaque visiteur virtuel (X-Forwarded-For) : les limites de débit, comptées par IP, s'appliquent
 * à chaque visiteur comme en vrai, au lieu de bloquer tout le test (une seule IP). Prise en compte uniquement si le
 * serveur fait confiance au générateur de charge (TRUSTED_PROXIES de la pile de test).
 */
export function visitorIp(vu) {
  return `10.${(vu >> 16) & 255}.${(vu >> 8) & 255}.${vu & 255}`;
}

export function headers(vu, extra = {}) {
  return { Accept: 'application/json', 'X-Forwarded-For': visitorIp(vu), ...extra };
}

/** En-têtes d'une requête à effet : Origin first-party (session Sanctum) et jeton CSRF lu dans le cookie. */
export function writeHeaders(vu, extra = {}) {
  const jar = http.cookieJar();
  const xsrf = jar.cookiesForURL(BASE_URL)['XSRF-TOKEN'];
  return headers(vu, {
    'Content-Type': 'application/json',
    Origin: BASE_URL,
    Referer: `${BASE_URL}/`,
    'X-XSRF-TOKEN': xsrf && xsrf.length ? decodeURIComponent(xsrf[0]) : '',
    ...extra,
  });
}

export function csrf(vu) {
  http.get(`${BASE_URL}/sanctum/csrf-cookie`, { headers: headers(vu, { Origin: BASE_URL, Referer: `${BASE_URL}/` }), tags: { name: 'GET /sanctum/csrf-cookie' } });
}

/** Clé d'idempotence unique (une par pari). */
export function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export function expectStatus(res, status, name) {
  return check(res, { [`${name} → ${status}`]: (r) => r.status === status });
}
