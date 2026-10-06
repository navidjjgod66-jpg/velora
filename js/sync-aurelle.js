/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · sync-aurelle.js
   کاتالوگ سرور (products.json) → کاتالوگ زندهٔ مرورگر

   ─── چرا این فایل هنوز لازم است ──────────────────────────────────────
   index.php حالا کاتالوگ را داخل خود صفحه inline می‌کند، پس دیگر برای
   «دیدن» کاتالوگ به یک رفت‌وبرگشت نیاز نیست. چیزی که باقی می‌ماند و
   ارزشش را دارد این است: اگر مدیر وسط بازدید مشتری products.json را
   عوض کند، صفحهٔ باز باید بتواند خودش را تازه کند.

   آن هم فقط وقتی که ارزش داشته باشد. کاتالوگ inline‌شده با یک هش محتوا
   مهر خورده است؛ اگر هشی که سرور تازه می‌گوید همان باشد، هیچ کاری نمی‌کنیم
   — نه درخواستی، نه بازنویسی DOM، نه پرش قیمت زیر چشم مشتری.
   ─────────────────────────────────────────────────────────────────────── */
(function () {
'use strict';
const { LS, toast } = window.AE;
const api = window.aeApi;

const STORE_KEY = 'ae.catalog.sync.v1';

/* The hash index.php stamped onto the catalogue it inlined. Compared against
   the ETag catalog.php reports; identical means the file on disk has not
   moved since this page was rendered. */
let syncedVersion = String(window.VELORA_CATALOG_VERSION || '');

/* How often to look, and only while the tab is visible.
   Five minutes of an open, focused tab is frequent enough that an operator
   watching the admin panel sees the storefront change within one coffee, and
   rare enough that it is a few hundred bytes an hour per visitor. */
const REFRESH_MS = 5 * 60 * 1000;
let timer = null;

function setStoredVersion(v) { try { LS.set(STORE_KEY, v); } catch {} }

function readVersion() {
  const r = LS.get(STORE_KEY, null);
  return typeof r === 'string' ? r : '';
}

/* A lightweight HEAD-shaped freshness question.
   catalog.php answers a conditional request with 304 and no body, so the
   common case — nothing changed — costs a few dozen bytes rather than the
   whole catalogue. Only a 200 carries a body we then have to parse. */
async function askServer() {
  if (!api || typeof api.catalogFetch !== 'function') return null;
  try {
    const r = await api.catalogFetch(false);
    if (!r) return null;
    if (r.changed === false) return { changed: false, version: r.etag || syncedVersion };
    return { changed: true, data: r.data || {}, version: (r.data && r.data.version) || r.etag || '' };
  } catch (_) {
    /* A network failure here is not an error the customer should ever see.
       The page is already showing the catalogue; the next tick tries again. */
    return null;
  }
}

async function run(silent) {
  if (!api || !api.available) {
    if (!silent) toast('سرور در دسترس نیست — کاتالوگ فعلی باقی می‌ماند.', 'err');
    return false;
  }

  const answer = await askServer();
  if (!answer || !answer.changed) return false;

  /* Same answer we already have: a 200 whose hash matches, which happens when
     the file was rewritten with identical bytes. Nothing to apply. */
  const next = String(answer.version || '').replace(/^"|"$/g, '');
  if (next && next === syncedVersion) return false;

  const rows = Array.isArray(answer.data.products) ? answer.data.products : [];
  if (!rows.length) return false;

  const applied = window.AE_DATA.applyServerCatalog(rows);
  if (!applied) return false;

  syncedVersion = next;
  setStoredVersion(next);
  if (!silent) toast(`کاتالوگ تازه شد — ${rows.length} فرم.`);
  return true;
}

function schedule() {
  if (timer !== null) return;
  timer = setInterval(() => {
    /* A background tab is not a customer. Deferring to visibilitychange keeps
       the timer from waking every five minutes for a window nobody is looking
       at — which, on a shared host, is most of them. */
    if (!document.hidden) run(true);
  }, REFRESH_MS);
}

addEventListener('visibilitychange', () => {
  if (!document.hidden) run(true);
});

function boot() {
  if (!window.VELORA_CATALOG_SYNCED) {
    /* The page is running on the fallback catalogue. There is nothing to
       refresh against, so no timer is created at all — an idle poll that can
       never succeed is pure cost. */
    return;
  }
  const stored = readVersion();
  if (stored && stored !== syncedVersion) {
    /* This browser saw a different catalogue hash on an earlier visit, which
       means the file changed since. Ask once soon, not on the five-minute
       tick, so a returning customer is not shown a stale grid for minutes. */
    const idle = 'requestIdleCallback' in window
      ? cb => requestIdleCallback(cb, { timeout: 2500 })
      : cb => setTimeout(cb, 1800);
    idle(() => run(true));
  }
  schedule();
}

if (document.readyState === 'complete') boot();
else addEventListener('load', boot, { once: true });

window.AE_SYNC = {
  run,
  syncNow: () => run(false),
  version: () => syncedVersion,
  getStoredVersion: readVersion,
};
})();