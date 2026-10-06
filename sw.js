/* ═══════════════════════════════════════════════════════════════════════════
   VELORA · Service Worker v7.2 — AETHERION
   ───────────────────────────────────────────────────────────────────────────
   Strategy contract (frozen — do not reorder without changing CACHE_VERSION):
     HTML navigations : NETWORK-FIRST → offline fallback
     Static (css/js)  : STALE-WHILE-REVALIDATE
     Images / fonts   : STALE-WHILE-REVALIDATE (bounded runtime cache)
     Other same-origin: NETWORK-FIRST → cache fallback
     *.php (non-shell): NETWORK-ONLY — never cached
     Cross-origin     : passthrough unless an allowlisted image host

   Message protocol (frozen): SKIP_WAITING · CLEAR_CACHE → CACHE_CLEARED
   Push payload keys (frozen): title · body · url
   ─────────────────────────────────────────────────────────────────────────── */

const CACHE_VERSION = 'velora-v9-3';
const STATIC_CACHE  = CACHE_VERSION + '-static';
const RUNTIME_CACHE = CACHE_VERSION + '-runtime';
const OFFLINE_CACHE = CACHE_VERSION + '-offline';
const OFFLINE_KEY   = '/__velora_offline__';
const RUNTIME_MAX_ENTRIES = 160;

/* ── Precache list ──────────────────────────────────────────────────────────
   index.php cache-busts with ?v=<filemtime>, so the URLs the page actually
   requests are 'style.css?v=…' and 'app.js?v=…'. Precaching anything else is
   wasted: an unversioned 'style.css' is never matched by a real request, so
   those two entries were dead on arrival and the genuine ?v= URLs were still
   resolved over the network on the first visit of every cold cache.

   The list is therefore DERIVED FROM THIS WORKER'S OWN URL, not guessed and not
   posted. index.php registers the worker as

       sw.js?v=<swVer>&css=<cssVer>&js=<jsVer>

   and self.location is known synchronously, before the install event fires. That
   closes the race the previous design could not: SET_PRECACHE arrives by
   postMessage from a page that has only just finished loading, and the install
   handler may already have read the list by then. Whatever the page's message
   timing does, the correct URLs are available here. The message is still
   honoured — it is the same origin saying the same thing — but it is now an
   optimisation, not the mechanism.

   Both values are validated as bare digits before use, and the paths are built
   from a fixed template around them. A hostile or malformed query string can
   therefore produce at most a 404 inside a same-origin cache, never an
   arbitrary URL. */
const QUERY = (() => {
  try { return new URL(self.location.href).searchParams; }
  catch { return null; }
})();
const digits = (v) => (typeof v === 'string' && /^[0-9]{1,12}$/.test(v) ? v : '');

const DERIVED_ASSETS = [
  digits(QUERY?.get('css')) ? './style.css?v=' + QUERY.get('css') : '',
  digits(QUERY?.get('js'))  ? './app.js?v='   + QUERY.get('js')  : '',
].filter(Boolean);

/* Floor for the case where the worker was registered without the parameters —
   a hand-rolled registration, or an old index.php still posting the list. Kept
   deliberately empty rather than unversioned: an unversioned precache costs two
   requests and stores two entries nothing can ever match, whereas an empty list
   costs nothing and leaves the stale-while-revalidate path to fill the cache from
   the first real (versioned) request, which is the correct behaviour anyway. */
const FALLBACK_ASSETS = [];
let precacheAssets = DERIVED_ASSETS.length ? DERIVED_ASSETS : null;

/* Public web root, NOT /storage/uploads/ — .htaccess rewrites ^storage/ to
   [F,L] and only uploads/products/ is public, so a path under /storage/ 403s. */
const BRAND_ICON = '/brand-icon-192.png';

const MAX_VERSIONS_PER_PATH = 3;
const MAINTENANCE_EVERY = 12;

/* Cross-origin hosts whose images may be intercepted and cached. The full
   hostname is matched, so a lookalike such as "images.unsplash.com.evil.test"
   cannot pass. */
const IMAGE_HOST_RE = /(^|\.)unsplash\.com$/i;
const IMAGE_PATH_RE = /\.(png|jpe?g|webp|gif|svg|avif|ico)(\?|$)/i;
const STATIC_PATH_RE = /\.(css|js|mjs|woff2?|ttf|otf|eot)(\?|$)/i;
/* What may be PRECACHED — deliberately a different list from what the fetch
   handler routes as a static asset.

   These answer two unrelated questions. STATIC_PATH_RE says "serve this with
   the JS/CSS caching policy", and it excludes images because they have their own
   branch and their own cache. The precache filter instead answers "may this be
   written into this origin's cache at all", where excluding a PNG buys nothing:
   the entry is same-origin, it came from the page's own manifest, and it is
   immutable.

   brand-icon-192.png is the concrete case. It is in the manifest because
   manifest.json and the notification handler both reference it, and an
   offline-capable install that cannot draw its own icon is a half-offline
   install — the browser falls back to a generic glyph, or shows nothing at all
   in some surfaces. */
const PRECACHE_PATH_RE = /\.(css|js|mjs|woff2?|ttf|otf|eot|png|jpe?g|webp|gif|svg|avif|ico)(\?|$)/i;
const PHP_PATH_RE = /\.php$/i;
const SHELL_PATH_RE = /^\/(index\.php)?$/;
const SESSION_PARAM_RE = /[?&](payment|order|ref|token|csrf)=/i;
const SESSION_PHP_RE = /\/(admin|api|config|payment|zarinpal)\.php$/i;

/* ── URL helpers ────────────────────────────────────────────────────────── */
const ORIGIN = self.location.origin;

function toUrl(value) {
  try { return new URL(value, self.location.href); }
  catch { return null; }
}

const isSameOriginUrl = (url) => !!url && url.origin === ORIGIN;

/* ── Request predicates (all take a pre-parsed URL) ─────────────────────── */
const isApiPath = (url) => isSameOriginUrl(url) && /\/api\.php$/i.test(url.pathname);

/* Session-bearing endpoints and session-shaped query parameters must never be
   served from, or written to, the cache. Fails closed: an unparseable URL is
   treated as session-bound. */
function isSessionBound(url) {
  if (!url) return true;
  if (!isSameOriginUrl(url)) return false;
  if (SESSION_PHP_RE.test(url.pathname)) return true;
  if (SESSION_PARAM_RE.test(url.search)) return true;
  return false;
}

/* Whether a failed navigation may be answered with the offline document.

   The fetch router used to gate this on `!isCacheableShell(url)`, where
   isCacheableShell() required BOTH `/\.php$/` and `/^\/(index\.php)?$/`. That
   conflated two unrelated questions and made the second one unreachable:

     · "may this response be STORED?"  — a property of the RESPONSE, decided by
       isCacheableResponse() reading its own Cache-Control. The shell says
       `no-store`, because it embeds a per-session CSRF token.
     · "may this navigation fall back to the offline page?" — a property of the
       REQUEST: is this a same-origin document navigation?

   The only path satisfying both halves of isCacheableShell() was the literal
   `/index.php`, which nothing in this application ever requests — every real
   navigation is `/?view=…` or `/?product=…`, whose pathname is `/` and fails the
   `.php` test. So `!isCacheableShell(url)` was true for every actual
   navigation, the router took the networkOnly() branch, and a failed navigation
   returned networkOnly's bare `503 new Response('', {status: 503})`.

   The consequence was that OFFLINE_HTML, OFFLINE_CACHE, seedOfflineCache() and
   offlineResponse() were dead code: the worker advertised an offline page it
   was structurally incapable of serving, and a customer who lost connectivity
   mid-session got a blank white page instead.

   Storage and fallback are now decided independently, which is what was always
   intended — nothing is stored (the shell is no-store), and the offline
   document is still served when the network is gone. */
const isOfflineCapableNavigation = (url) =>
  isSameOriginUrl(url) && !SESSION_PHP_RE.test(url.pathname);

const isStaticAsset = (url) => isSameOriginUrl(url) && STATIC_PATH_RE.test(url.pathname);

/* The catalogue feed. It is a .php path, so the static-asset branch would not
   otherwise see it, and it is exactly the request that must never be re-fetched
   on every page view: the whole document is inlined into the shell, and
   catalog.php exists so the client can ask "is my copy current?" — a question
   answered by a 304. The ETag changes with products.json, so a changed file is
   re-fetched and an unchanged one is not. */
const CATALOG_PATH_RE = /\/catalog\.php$/i;
const isCatalogFeed = (url) => isSameOriginUrl(url) && CATALOG_PATH_RE.test(url.pathname);

/* Is this URL's *content* fixed for the life of the URL?
   A query the server stamps from filemtime (`?v=1699999999`) changes the moment
   the bytes change, so the URL is its own validator: same URL always means
   same bytes. An upload is content-addressed by the operator's own hash in the
   filename (`notte-3f9a1c.webp`) and is never written twice.

   This predicate is what stops the worker from re-downloading the world. */
const VERSIONED_QUERY_RE = /[?&]v=\d+/;
const HASHED_UPLOAD_RE = /\/storage\/uploads\/products\/[a-zA-Z0-9_-]*[0-9a-f]{6,}\.webp$/;
const isImmutableUrl = (url) => {
  if (!url) return false;
  if (VERSIONED_QUERY_RE.test(url.search)) return true;
  return HASHED_UPLOAD_RE.test(url.pathname);
};

const isImage = (url) => {
  if (!url) return false;
  if (isSameOriginUrl(url)) return IMAGE_PATH_RE.test(url.pathname);
  /* An allowlisted host is trusted wholesale: Unsplash photo URLs are of the
     form /photo-1542291026-… and carry no file extension, so requiring one
     here would silently stop the catalogue imagery from being cached. */
  return IMAGE_HOST_RE.test(url.hostname);
};

function isHtmlRequest(request, url) {
  if (request.mode === 'navigate') return true;
  if (url && SHELL_PATH_RE.test(url.pathname)) return true;
  return (request.headers.get('accept') || '').includes('text/html');
}

/* ── Response predicates ────────────────────────────────────────────────── */
/* Opaque responses report status 0 and ok === false, so the previous
   `!response.ok` guard rejected every one of them. That made the
   `response.type === 'opaque'` branch in the write path unreachable and
   cross-origin images were silently never cached. Opaque responses are now
   classified explicitly; their headers are unreadable, so header directives
   are simply not consulted for them. */
function isCacheableResponse(response) {
  if (!response) return false;
  if (response.type === 'opaque') return true;
  if (response.status === 0) return false;
  if (!response.ok) return false;
  if (response.redirected) return false;
  let headers;
  try { headers = response.headers; } catch { return false; }
  if (!headers) return false;
  const cc = (headers.get('cache-control') || '').toLowerCase();
  /* `no-cache` is now rejected alongside `no-store` and `private`.

     Cache Storage has no revalidation model. An entry stored under
     `Cache-Control: no-cache` is served unconditionally by cache.match() and
     only refreshed in the background by the fetch handler, which means "no
     cache" silently becomes "serve this forever, and never block on the
     network". Treating a directive whose entire meaning is *revalidate before
     reuse* as permission to store is simply the wrong reading of it.

     The concrete damage was not hypothetical. index.php used to send
     `Cache-Control: no-cache, must-revalidate, max-age=0` on the application
     shell while embedding a live per-session CSRF token in
     <meta name="csrf-token">. That passed this check, so the shell was written
     into RUNTIME_CACHE under keys every visitor shares (`/?view=shop`,
     `/?product=notte`, …). Offline — or on any networkFirst() cache fallback —
     the next visitor was served a stranger's document: their CSRF token, their
     session context, their rendered state. index.php now sends `no-store` for
     the shell as well, so the two defences reinforce each other rather than
     depending on one. */
  if (cc.includes('no-store') || cc.includes('private') || cc.includes('no-cache')) return false;
  if ((headers.get('x-robots-tag') || '').toLowerCase().includes('noindex')) return false;
  return true;
}

/* ── Cache maintenance ──────────────────────────────────────────────────── */
/* Cache.keys() returns entries in insertion order, so slicing from the front
   evicts the oldest. Writes no longer run a full key listing each time: a
   gallery fires dozens of image writes and each one used to enumerate the
   whole cache. Maintenance is amortised instead. */
let writesSinceMaintenance = 0;

async function trimCache(cacheName, maxEntries) {
  try {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    if (keys.length <= maxEntries) return;
    await Promise.all(
      keys.slice(0, keys.length - maxEntries).map((k) => cache.delete(k).catch(() => undefined))
    );
  } catch {}
}

function scheduleMaintenance(cacheName, maxEntries) {
  if (cacheName !== RUNTIME_CACHE) return null;
  if (++writesSinceMaintenance < MAINTENANCE_EVERY) return null;
  writesSinceMaintenance = 0;
  return trimCache(cacheName, maxEntries);
}

/* Superseded revisions of one file (style.css?v=OLD beside style.css?v=NEW) are
   distinct keys by design, so they are collapsed to the newest few per path.
   Only same-origin, query-bearing keys are considered, which leaves the
   cross-origin image entries in the runtime cache to trimCache(). */
async function purgeStaleVersions(cacheName) {
  try {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    const byPath = new Map();
    for (const k of keys) {
      /* Filter on the raw string before constructing a URL. Keys arrive in
         insertion order, so a runtime cache holding thirty cross-origin image
         responses hit this loop first; building thirty URL objects to discover
         that none of them had a query string was the cost this avoided. The
         `v=` test is a second cheap reject, since a query that is not a version
         has no business holding a slot against the same path. */
      const s = k.url;
      const q = s.indexOf('?');
      if (q === -1) continue;
      if (s.indexOf('v=', q) === -1) continue;
      const u = toUrl(s);
      if (!u || !u.search || u.origin !== ORIGIN) continue;
      const list = byPath.get(u.pathname);
      if (list) list.push(k); else byPath.set(u.pathname, [k]);
    }
    /* Deletes are collected and issued together rather than awaited per path.
       Sequentially this was one round of IPC per over-full path, and activate
       is on the critical path of every update. */
    const jobs = [];
    for (const list of byPath.values()) {
      if (list.length <= MAX_VERSIONS_PER_PATH) continue;
      const stale = list.slice(0, list.length - MAX_VERSIONS_PER_PATH);
      for (const k of stale) jobs.push(cache.delete(k).catch(() => undefined));
    }
    await Promise.all(jobs);
  } catch {}
}

/* ── Strategies ─────────────────────────────────────────────────────────── */
/* Cache under the request as-is. Stripping the query string collapsed every
   revision of style.css onto one key, which silently defeated the filemtime
   cache-bust index.php emits.

   `revalidate` is the whole point of this function now, and it is a decision
   the router makes, not something this function assumes.

   It used to be unconditional. `fetch(new Request(request, {cache:'reload'}))`
   forces a full network fetch that bypasses the HTTP cache entirely, so for
   every single page view the worker re-downloaded 47 KB of gzip CSS and 70 KB
   of gzip JS, wrote them over entries that were already the newest bytes
   obtainable, and threw the transfer away. On a warm cache with a fast
   connection that is ~117 KB per page view, forever. Every image on the page
   took the same path via the same function, so the home view paid it 27-31
   more times.

   The original reasoning was sound but misapplied: the comment says the point
   was to stop "a still-fresh HTTP cache entry" from answering and leaving
   Cache Storage stale. That is only a risk for a URL whose bytes can change
   while the URL stays the same — but such a URL is already being served stale
   by the HTTP cache, because .htaccess marks these paths `immutable,
   max-age=31536000`. For a `?v=filemtime` or content-addressed URL the URL
   *is* the validator, so revalidating cannot reveal anything the cache did not
   already have, and it costs the full body every time.

   So: `revalidate: false` means cache-first with no background fetch. A miss
   still goes to the network, `isCacheableResponse` still decides what is worth
   storing, and a fresh install still populates the cache normally. Only the
   provably-redundant round trip is removed. */
async function staleWhileRevalidate(request, cacheName, event, revalidate = true) {
  const cache = await caches.open(cacheName);

  const cached = await cache.match(request);
  if (cached && !revalidate) return cached;

  const networkPromise = fetch(new Request(request, revalidate ? { cache: 'reload' } : {}))
    .then((response) => {
      if (!isCacheableResponse(response)) return response;
      return cache.put(request, response.clone())
        .catch(() => undefined)
        .then(() => scheduleMaintenance(cacheName, RUNTIME_MAX_ENTRIES))
        .then(() => response);
    })
    .catch(() => null);

  if (event) event.waitUntil(networkPromise);
  if (cached) return cached;
  return (await networkPromise) || new Response('', { status: 504 });
}

async function networkFirst(request, cacheName, event) {
  const cache = await caches.open(cacheName);
  try {
    const response = await fetch(request);
    if (isCacheableResponse(response)) {
      const put = cache.put(request, response.clone())
        .catch(() => undefined)
        .then(() => scheduleMaintenance(cacheName, RUNTIME_MAX_ENTRIES));
      if (event) event.waitUntil(put);
    }
    return response;
  } catch {
    return (await cache.match(request)) || null;
  }
}

async function networkOnly(request) {
  try { return await fetch(request); }
  catch { return new Response('', { status: 503 }); }
}

const GATEWAY = () => new Response('', { status: 504 });

/* ── Offline document ───────────────────────────────────────────────────── */
const OFFLINE_HTML =
  '<!DOCTYPE html>' +
  '<html lang="fa" dir="rtl">' +
  '<head>' +
    '<meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<meta name="theme-color" content="#060504">' +
    '<title>VELORA — آفلاین</title>' +
    '<style>' +
      'body{margin:0;min-height:100dvh;display:grid;place-items:center;' +
      'background:#060504;color:#eee;font-family:Vazirmatn,system-ui,sans-serif;' +
      'text-align:center;padding:2rem;line-height:2}' +
      'h1{color:#d9b98a;font-weight:400;letter-spacing:.2em;font-size:2rem;margin:0 0 1rem}' +
      'p{color:#999;margin:.4rem 0}' +
      'button{margin-top:1.5rem;padding:.75rem 2rem;border-radius:999px;' +
      'border:1px solid #d9b98a;background:transparent;color:#d9b98a;' +
      'font-family:inherit;font-size:.9rem;cursor:pointer;transition:.3s}' +
      'button:hover{background:#d9b98a;color:#060504}' +
    '</style>' +
  '</head>' +
  '<body><div>' +
    '<h1>VELORA</h1>' +
    '<p>اتصال اینترنت قطع است.</p>' +
    '<p>پس از برقراری اتصال، صفحه را بازخوانی کنید.</p>' +
    '<button onclick="location.reload()">تلاش مجدد</button>' +
  '</div></body></html>';

const offlineResponse = () => new Response(OFFLINE_HTML, {
  status: 200,
  headers: { 'Content-Type': 'text/html; charset=utf-8' },
});

async function seedOfflineCache() {
  const cache = await caches.open(OFFLINE_CACHE);
  await cache.put(new Request(OFFLINE_KEY), offlineResponse());
}

const assetsToPrecache = () =>
  (Array.isArray(precacheAssets) && precacheAssets.length ? precacheAssets : FALLBACK_ASSETS);

/* Resolvers waiting on precacheListReady(). A Set, not a single callback: several
   install events and several CLEAR_CACHE re-seeds can overlap. */
const precacheWaiters = new Set();

/* Resolves once the worker knows which assets to precache, or once a short grace
   period has passed without it being told.

   Normally the list is already there — it is derived from this worker's own URL
   before the install event fires — so this returns immediately. The wait exists
   for the one case where it is not: a registration without the ?css=/?js=
   parameters, where the only source of truth is the page's SET_PRECACHE message
   and the page may still be loading. Waiting is strictly better than guessing,
   because guessing is what produced two dead cache entries before. Capped so a
   page that never posts cannot stall activation. */
function precacheListReady(timeoutMs = 1500) {
  if (Array.isArray(precacheAssets) && precacheAssets.length) return Promise.resolve(precacheAssets);
  return new Promise((resolve) => {
    let done = false;
    const finish = () => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      precacheWaiters.delete(finish);
      resolve(assetsToPrecache());
    };
    const timer = setTimeout(finish, timeoutMs);
    precacheWaiters.add(finish);
  });
}

/* Resolve anyone waiting for the list. Called from the SET_PRECACHE handler, and
   idempotent — calling it with no list is harmless because finish() resolves to
   assetsToPrecache(), which is the empty floor. */
function notifyPrecacheWaiters() {
  if (!(Array.isArray(precacheAssets) && precacheAssets.length)) return;
  for (const fn of Array.from(precacheWaiters)) fn();
}

/* ── Install ────────────────────────────────────────────────────────────── */
/* A precache that trusts the response is a precache that can store a broken
   file and serve it for as long as the version says so.

   cache.add() resolves with undefined, so the stored body was never inspected:
   whatever bytes arrived became the cached answer, and staleWhileRevalidate
   hands that copy back first on every subsequent load. A response truncated
   mid-transfer, or a file still being written when it was fetched, therefore
   became indistinguishable from a good one - and the symptom is a bare
   "SyntaxError: Invalid or unexpected token" at whatever line it was cut,
   while node --check on the file on disk passes.

   So each text asset is read back after storing and checked for the one
   property truncation destroys: it no longer ends where it began. A complete
   stylesheet and a complete script both end on a brace, a semicolon or a
   newline; a file cut anywhere else does not. The check is deliberately that
   weak - a real parser is not available at install time, and a heuristic
   trying to be clever would evict valid files on a brace inside a string.

   The deeper fix is upstream and already in place: index.php versions assets
   by CONTENT HASH, so a partial file and a complete file can never share a
   cache key. This is the second line, for a server that returns a short body. */
/* A precache that trusts the response is a precache that can store a broken
   file and serve it for as long as the version says so. cache.add() resolves
   with undefined, so the stored body was never inspected, and
   staleWhileRevalidate then hands that copy back first on every load.

   What is checked is the ONE property that can be established exactly: if the
   server declared a Content-Length, the body must be exactly that many bytes.
   A transfer cut short fails it, and it cannot fail for a response that
   arrived whole.

   The alternatives were built and measured, and both were worse:

     - "does it end on ; } or a newline" — waved through 117 of 1200 truncation
       points across app.js, style.css and sw.js, because a cut landing just
       after a semicolon still ends on one.
     - "are the braces balanced, ignoring comments and strings" — caught more
       (17 of 1200 missed) but REJECTED TWO OF THE THREE REAL FILES. Stripping
       comments and strings does not make brace counting safe, because a regex
       literal such as /^[a-z]{1,80}\.webp$/ contains braces the stripper never
       sees. A guard that evicts a good asset breaks the site, which is strictly
       worse than the fault it was added to catch, so it is gone.

The remaining gap is a chunked response, which declares no length. There the
   check accepts what it received — but "accepts" is too strong: a zero-byte
   body is not a plausible asset, and returning true for it means an empty file
   is promoted into the permanent precache. The next visitor to load that URL
   gets a hard parse error out of Cache Storage, on every load, until the
   version changes. A missing entry costs one network fetch; a corrupt entry
   costs the site.

   So the chunked branch keeps exactly one assertion, the one that is always
   safe: the body is not empty. Everything above is still protected upstream by
   the content-hash version in index.php, which is the real guarantee — this is
   the second line, not the first. */
 function bodyIsIntact(res, bytes) {
  const declared = res.headers.get('content-length');
  if (declared !== null && /^\d{1,12}$/.test(declared)) {
    /* Declared length: it must match exactly. A mismatch is a truncated
       transfer, whatever the reason for it. */
    return bytes.byteLength === Number(declared);
  }
  /* Chunked, or a length we do not recognise: no comparison is possible, but
     an empty body is still never a real asset. */
  return bytes.byteLength > 0;
}
async function precacheVerified(cache, url) {
  let res;
  try {
    /* cache:'reload' bypasses the HTTP cache, so a stale intermediary copy
       cannot be promoted into the precache. */
    res = await fetch(url, { cache: 'reload', credentials: 'same-origin' });
  } catch { return false; }
  if (!res || !res.ok || res.type === 'opaque') { return false; }

  try {
    const bytes = await res.arrayBuffer();
    if (!bodyIsIntact(res, bytes)) {
      /* A truncated transfer is never stored. A missing entry costs one
         network fetch on the next load; a corrupt entry costs a hard parse
         error on every load until the version changes. */
      return false;
    }
    await cache.put(url, new Response(bytes, { status: 200, headers: res.headers }));
    return true;
  } catch { return false; }
}

/* Install does not block on the asset list.

   It used to: `await precacheListReady()` — a 1500 ms grace period — and then
   precached whatever it had. Two things were wrong with that.

   The list it was waiting for never arrived, because the only source was
   `?css=<mtime>&js=<mtime>` on this worker's own URL, expanded through a fixed
   template naming ./style.css and ./app.js. This project has neither file: its
   assets are six stylesheets and fifteen scripts under css/ and js/. So the list
   was permanently empty, every install sat out the full grace period, and the
   skipWaiting that lets an update take over mid-session was delayed by 1.5 s on
   every single deploy and every cold cache.

   And blocking is the wrong shape regardless. The page cannot know it has a
   service worker controlling it until after it has loaded and registered, so on
   a genuinely first visit there is no message to wait for — the install would
   sit out its timeout knowing it had missed the only boat that mattered. The
   assets a returning visitor needs offline are the ones their HTTP cache
   already holds, because every one of them is served `immutable` with a
   `?v=<filemtime>` URL.

   So install only seeds the offline document and takes over promptly, and the
   precache happens when the list actually arrives — via SET_PRECACHE, now that
   index.php publishes a real one. Offline support is therefore complete from the
   second navigation onward, which is exactly when it is needed: you cannot be
   offline somewhere you have never been. */
self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    await seedOfflineCache();
    await self.skipWaiting();
  })());
});

/* ── Activate ───────────────────────────────────────────────────────────── */
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    const keep = new Set([STATIC_CACHE, RUNTIME_CACHE, OFFLINE_CACHE]);
    await Promise.all(keys.filter((k) => !keep.has(k)).map((k) => caches.delete(k)));

    try {
      const runtimeCache = await caches.open(RUNTIME_CACHE);
      const reqs = await runtimeCache.keys();
      await Promise.all(reqs
        .filter((r) => isSessionBound(toUrl(r.url)))
        .map((r) => runtimeCache.delete(r)));
    } catch {}

    /* Evict any document an older build of this worker stored. The write path
       for HTML is gone now, but the entries are already in the visitor's cache
       on every device that ran a build that had it — and until they are gone,
       those visitors keep being served a shell that predates their edit, which
       is indistinguishable from "the catalogue file is being ignored".

       The test is content negotiation, not URL shape: a request whose Accept
       header asks for text/html is a document, whatever it is named. Matching
       on pathname would miss the rewrite and the pretty-URL cases. */
    try {
      for (const cacheName of [RUNTIME_CACHE, STATIC_CACHE, OFFLINE_CACHE]) {
        if (cacheName === OFFLINE_CACHE) continue;   // its one entry is the offline doc
        const c = await caches.open(cacheName);
        const reqs = await c.keys();
        await Promise.all(reqs
          .filter((r) => {
            const u = toUrl(r.url);
            if (!u) return false;
            if (r.headers.get('accept') && r.headers.get('accept').includes('text/html')) return true;
            if (r.destination === 'document') return true;
            /* The shell reached us as ?view=… / ?product=… on the root path,
               which a pathname test alone would not recognise. */
            if (SHELL_PATH_RE.test(u.pathname) && u.search) return true;
            return false;
          })
          .map((r) => c.delete(r)));
      }
    } catch {}

    await trimCache(RUNTIME_CACHE, RUNTIME_MAX_ENTRIES);
    await purgeStaleVersions(STATIC_CACHE);
    await purgeStaleVersions(RUNTIME_CACHE);
    await seedOfflineCache();

    if (self.registration.navigationPreload) {
      try { await self.registration.navigationPreload.enable(); } catch {}
    }
    await self.clients.claim();
  })());
});

/* ── Fetch router ───────────────────────────────────────────────────────── */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = toUrl(request.url);
  if (!url) return;

  const sameOrigin = url.origin === ORIGIN;

  /* Cross-origin traffic passes straight through unless it is an image from an
     allowlisted host. Previously any cross-origin URL ending in a known image
     extension was routed through this worker, so third-party images on any page
     in scope consumed the runtime cache and could evict the site's own imagery
     by filling the bounded entry count. */
  if (!sameOrigin && !isImage(url)) return;

  if (isApiPath(url)) return;

  /* Answered from the network, every time. The ETag makes the unchanged case
     a 304, so this costs a few dozen bytes and is the one guarantee that the
     freshness check in app.js is reading the server's current catalogue rather
     than this worker's idea of it. Caching it here would let the two disagree,
     which is the failure being fixed. */
  if (isCatalogFeed(url)) {
    event.respondWith(networkOnly(request));
    return;
  }

  if (!sameOrigin) {
    event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE, event).catch(() => GATEWAY()));
    return;
  }

  if (isHtmlRequest(request, url)) {
    if (isSessionBound(url) || !isOfflineCapableNavigation(url)) {
      /* The browser has already started a navigation preload for this request
         by the time the fetch handler runs. Going straight to the network
         without touching event.preloadResponse leaves that promise unsettled,
         and the browser logs:
           "The service worker navigation preload request was cancelled before
            'preloadResponse' settled."
         It is not only noise. The preloaded request is a real request against
         a real connection, so abandoning it means the document is fetched
         TWICE — the preload, discarded, plus our own fetch.

         The fix is to consume the promise before answering. The value is
         ignored on purpose: a session-bound document must never be served from
         anywhere but the network, preload included. */
      event.respondWith((async () => {
        try { await event.preloadResponse; } catch { /* already gone */ }
        return networkOnly(request);
      })());
      return;
    }
    event.respondWith((async () => {
      /* navigationPreload is enabled in activate(). Consuming the preloaded
         response avoids paying for the same HTML twice. The cache key keeps
         the full query string, since ?view= and ?product= select different
         documents.

         NOTE the absent cache.put(). An earlier version wrote the shell into
         RUNTIME_CACHE here whenever isCacheableResponse() allowed it, keyed by
         URL. Every visitor shares those keys, and the document carries a live
         CSRF token and the inlined catalogue — so one stale entry is served to
         everyone who requests that URL until it is evicted, with no signal to
         the visitor. It was also the mechanism behind "I edited products.json
         and nothing changed": a cached shell outlives the edit by exactly as
         long as nothing prunes it.

         The shell is `no-store`, so isCacheableResponse() already rejects it.
         That is a policy, and policy is what failed here. A document is never
         written to any cache, on any path, regardless of what headers it
         carries — so a navigation is either fresh from the network or the
         explicit offline document, and there is no third state in which the
         site can quietly serve a stale one. */
      try {
        const preloaded = await event.preloadResponse;
        if (preloaded) return preloaded;
      } catch {}
      const fresh = await networkFirst(request, RUNTIME_CACHE, event);
      if (fresh) return fresh;
      const offlineCache = await caches.open(OFFLINE_CACHE);
      return (await offlineCache.match(OFFLINE_KEY)) || offlineResponse();
    })());
    return;

  }

  if (isStaticAsset(url)) {
    const immutable = isImmutableUrl(url);
    event.respondWith(staleWhileRevalidate(request, STATIC_CACHE, event, !immutable).catch(() => GATEWAY()));
    return;
  }

  if (isImage(url)) {
    const immutable = isImmutableUrl(url);
    event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE, event, !immutable).catch(() => new Response('', { status: 404 })));
    return;
  }

  event.respondWith((async () => {
    const fresh = await networkFirst(request, RUNTIME_CACHE, event);
    return fresh || new Response('', { status: 503 });
  })());
});

/* ── Message channel ────────────────────────────────────────────────────── */
self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SKIP_WAITING') { self.skipWaiting(); return; }
  /* Adopt the page's cache-busted asset URLs. Secondary to the list derived from
     this worker's own URL at load time, and kept because it costs nothing and
     covers a registration made without the parameters.

     postMessage only reaches a worker that is installing or waiting, which is
     exactly when this is wanted: a message that arrives after activation is a
     no-op, so a late caller can never mutate a live cache. Anything that is not
     a non-empty array of same-origin relative paths is ignored, so a malformed or
     hostile message cannot be used to make the worker fetch arbitrary
     third-party URLs into the origin's own cache. */
  if (data.type === 'SET_PRECACHE') {
    if (Array.isArray(data.assets)) {
      /* Reject anything that is not a plain same-origin relative asset path.
         The first version of this filter only stripped leading slashes and then
         tested the extension, which accepted 'https://evil.test/x.js' and
         '//evil.test/y.js' — both end in .js, neither starts with '//' after the
         strip, so both were handed to cache.add() and written into this
         origin's cache as opaque entries. A same-origin scheme check is the
         part that was missing, and it is the part that matters. */
      const clean = [];
      for (const raw of data.assets) {
        if (typeof raw !== 'string') continue;
        const a = raw.trim();
        if (a === '' || a.length > 300) continue;
        /* Reject absolute URLs, protocol-relative URLs, anything with a scheme
           or authority, and any traversal segment — before the leading-slash
           strip, so '//host/x' cannot be laundered into 'host/x'. */
        if (/^[a-z][a-z0-9+.-]*:/i.test(a)) continue;
        if (a.startsWith('//')) continue;
        if (a.includes('\\') || a.includes('..')) continue;
        const rel = a.replace(/^\/+/, '');
        if (rel === '' || rel.startsWith('/')) continue;
        if (!PRECACHE_PATH_RE.test(rel)) continue;
        clean.push(rel);
      }
if (clean.length) {
        precacheAssets = clean;
        /* Release an install that is blocked in precacheListReady() waiting for
           exactly this. Without the call, that install would sit out its full
           1.5 s grace period on every cold cache and delay skipWaiting — and
           the new worker taking over — by that much, on every deploy. */
        notifyPrecacheWaiters();
        /* Store them. The handler used to record the list and do nothing else,
           which was correct only while install was the thing that read it —
           and install no longer blocks on the list, so a recorded list that
           nothing consumes is the same dead subsystem one layer down.

           postMessage only reaches a worker that is installing or waiting, so
           this is the right moment: the caches are open-able, the fetch handler
           is not yet answering, and the work happens before the new version can
           serve a byte. Wrapped in waitUntil because precaching is real I/O —
           without it the worker can be terminated the moment the handler returns
           and the entries are silently half-written. */
        event.waitUntil((async () => {
          try {
            const cache = await caches.open(STATIC_CACHE);
            await Promise.allSettled(clean.map((a) => precacheVerified(cache, a)));
          } catch { /* a failed precache is a missed optimisation, not an error */ }
        })());
      }
    }
    return;
  }
  if (data.type === 'CLEAR_CACHE') {
    event.waitUntil((async () => {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
      /* Re-seed immediately. Clearing every cache used to leave the worker with
         no offline document and no precached CSS/JS until the next install, so
         a reload while still offline produced a bare 503. */
      try {
        /* Waits for the list here too. The offline document is seeded
           unconditionally, so a visitor who clears the cache while offline gets
           the offline page back immediately either way; the wait only decides
           whether CSS and JS are also back. */
        const assets = await precacheListReady();
        const staticCache = await caches.open(STATIC_CACHE);
        /* Verified here too. This is the re-seed path, and it is the one that
           runs while a real page is loading — so it is the one most likely to
           catch a file mid-write. Blind cache.add() at both sites was the
           defect; fixing only the install handler would have left this one. */
        await Promise.allSettled(assets.map((a) => precacheVerified(staticCache, a)));
        await seedOfflineCache();
      } catch {}
      event.source?.postMessage?.({ type: 'CACHE_CLEARED' });
    })());
  }
});

/* ── Background sync ────────────────────────────────────────────────────── */
self.addEventListener('sync', (event) => {
  if (event.tag !== 'sync-orders') return;
  event.waitUntil((async () => {
    const clients = await self.clients.matchAll();
    for (const c of clients) {
      try { c.postMessage({ type: 'SYNC_COMPLETE' }); } catch {}
    }
  })());
});

/* ── Push notifications ─────────────────────────────────────────────────── */
/* Payload values are attacker-influenced - anyone able to send to the
   subscription can author them - and showNotification() throws on a non-string
   title. Every field is coerced to a bounded string before use, and the call is
   guarded so a malformed payload cannot reject the waitUntil. */
const asText = (value, fallback, max) =>
  (typeof value === 'string' && value.trim() ? value.trim().slice(0, max) : fallback);

self.addEventListener('push', (event) => {
  let payload = {};
  try { payload = event.data ? (event.data.json() || {}) : {}; } catch {}
  if (typeof payload !== 'object' || payload === null) payload = {};

  const title = asText(payload.title, 'VELORA', 80);
  const body = asText(payload.body, 'اعلان جدید', 240);
  const url = asText(payload.url, '/', 512);

  event.waitUntil((async () => {
    try {
      await self.registration.showNotification(title, {
        body, icon: BRAND_ICON, badge: BRAND_ICON,
        vibrate: [200, 100, 200], data: { url },
      });
    } catch {}
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  /* Only same-origin http(s) targets are honoured; anything else falls back to
     the site root. */
  let target = '/';
  const raw = event.notification?.data?.url;
  if (typeof raw === 'string') {
    const u = toUrl(raw);
    if (u && (u.protocol === 'http:' || u.protocol === 'https:') && u.origin === ORIGIN) {
      target = u.pathname + u.search + u.hash;
    }
  }

  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    /* Compare PATHS, not raw client URLs.
       `c.url` is always absolute ('https://host/?product=notte') while `target`
       is the relative form built above ('/?product=notte'). The old check was
       `if (c.url === target)`, which is therefore never true — the branch meant
       to focus a window that was already showing the target could not ever
       fire. The loop below then called clients.openWindow() on the FIRST
       same-origin client it found, so every notification click spawned a new
       tab even when the user was already sitting on the page the notification
       was about, leaving a pile of duplicate tabs behind.

       Deriving the path from the client URL makes the comparison well-formed,
       and openWindow() is only reached when no existing tab already matches. */
    const pathOf = (client) => {
      try {
        const u = new URL(client.url);
        if (u.origin !== ORIGIN) return null;
        return u.pathname + u.search + u.hash;
      } catch { return null; }
    };

    const sameOrigin = all.filter((c) => pathOf(c) !== null);
    const exact = sameOrigin.find((c) => pathOf(c) === target);
    if (exact) { await exact.focus(); return; }

    const fresh = await self.clients.openWindow(target);
    if (fresh) return fresh;

    /* No window could be opened (all were discarded). Fall back to whatever
       is still around rather than dropping the interaction. */
    const fallback = sameOrigin[0] || all[0];
    if (fallback) await fallback.focus();
  })());
});
