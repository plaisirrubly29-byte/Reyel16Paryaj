/*
 * Service worker de REYEL 16 PARYAJ (V2-E05) : application installable et plus rapide sur réseau lent.
 *
 * - Pages (navigation) : réseau d'abord, pour recevoir chaque mise à jour ; la dernière page reçue sert seulement
 *   quand le réseau coupe, et à défaut un court message « Pas de connexion ».
 * - Fichiers de l'application (/assets/…, noms avec empreinte, /icons/…) : gardés sur le téléphone ; les anciens
 *   fichiers sont supprimés quand une nouvelle version de la page les remplace.
 * - API (/api/…, /sanctum/…) et autres sites (polices Google) : jamais interceptés ni mis en cache. Soldes, paris et
 *   données personnelles viennent toujours du serveur, et rien de privé ne reste sur un appareil partagé.
 */
const VERSION = 'v1';
const SHELL_CACHE = `reyel16-shell-${VERSION}`;
const ASSET_CACHE = `reyel16-assets-${VERSION}`;
const SCOPE = new URL(self.registration.scope).pathname; // « / » ou le sous-dossier de publication

/** Fichiers à empreinte cités par la page (scripts, styles, préchargements). */
function assetsOf(html) {
  const urls = new Set();
  for (const m of html.matchAll(/(?:src|href)="([^"]*\/assets\/[^"]+)"/g)) urls.add(new URL(m[1], self.location.origin).pathname);
  return [...urls];
}

/** Garde la page reçue comme coquille hors ligne et supprime les fichiers d'anciennes versions. */
async function keepShell(response) {
  const html = await response.clone().text();
  const shell = await caches.open(SHELL_CACHE);
  await shell.put(SCOPE, response.clone());
  const current = new Set(assetsOf(html));
  if (current.size === 0) return;
  const assets = await caches.open(ASSET_CACHE);
  for (const request of await assets.keys()) {
    const path = new URL(request.url).pathname;
    if (path.includes('/assets/') && !current.has(path)) await assets.delete(request);
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const response = await fetch(SCOPE, { cache: 'no-cache' });
    if (response.ok) {
      const html = await response.clone().text();
      await (await caches.open(SHELL_CACHE)).put(SCOPE, response);
      await (await caches.open(ASSET_CACHE)).addAll(assetsOf(html));
    }
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keep = [SHELL_CACHE, ASSET_CACHE];
    for (const name of await caches.keys()) if (name.startsWith('reyel16-') && !keep.includes(name)) await caches.delete(name);
    await self.clients.claim();
  })());
});

const OFFLINE_PAGE = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pas de connexion — REYEL 16 PARYAJ</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#060d2b;color:#fff;font:16px/1.5 system-ui,sans-serif;text-align:center;padding:1.5rem}
.retry{display:inline-block;margin-top:1rem;padding:.8rem 1.4rem;border-radius:999px;background:#e3172f;color:#fff;font-weight:700;text-decoration:none}</style></head>
<body><main><h1>Pas de connexion</h1><p>Vérifiez votre connexion internet, puis réessayez.</p><a class="retry" href="">Réessayer</a></main></body></html>`;

async function page(request) {
  try {
    const response = await fetch(request);
    if (response.ok && (response.headers.get('content-type') || '').includes('text/html')) {
      keepShell(response.clone()).catch(() => {});
    }
    return response;
  } catch {
    const shell = await caches.match(SCOPE, { cacheName: SHELL_CACHE, ignoreVary: true });
    return shell || new Response(OFFLINE_PAGE, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }
}

async function asset(request) {
  // ignoreVary : fichiers à empreinte, identiques quelle que soit l'origine (certains serveurs ajoutent « Vary: Origin »).
  const cached = await caches.match(request, { cacheName: ASSET_CACHE, ignoreVary: true });
  if (cached) return cached;
  const response = await fetch(request);
  if (response.ok) (await caches.open(ASSET_CACHE)).put(request, response.clone()).catch(() => {});
  return response;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return; // polices Google, prestataires : le navigateur s'en charge
  if (url.pathname.includes('/api/') || url.pathname.includes('/sanctum/') || url.pathname.endsWith('/up')) return;

  if (request.mode === 'navigate') {
    event.respondWith(page(request));
  } else if (url.pathname.includes('/assets/') || url.pathname.includes('/icons/')) {
    event.respondWith(asset(request));
  }
});
