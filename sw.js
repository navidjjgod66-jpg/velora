/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — sw.js
   Service Worker v4.0.0 · Modular Build
   ─────────────────────────────────────────────────────────────────────
   سیاست کش (لایه‌بندی‌شده بر اساس نوع درخواست):
     • پوستهٔ برنامه (HTML/CSS/JS/آیکون)  → Cache-first پس از نصب اولیه
       با به‌روزرسانی در پس‌زمینه (stale-while-revalidate برای فایل‌های
       پوسته، Network-first برای HTML اصلی).
     • فونت باینری (fonts.gstatic.com)    → Cache-first (تغییرناپذیر).
     • فونت CSS (fonts.googleapis.com)    → از HTTP cache مرورگر.
     • تصاویر Unsplash                    → Stale-while-revalidate + FIFO.
     • سایر درخواست‌ها                     → Network-first با fallback.
   ─────────────────────────────────────────────────────────────────────
   اصول:
     • هیچ respondWith هرگز یک promise رد‌شده برنمی‌گرداند.
     • نصب حتی با فایل گم‌شده هم موفق است (addAll اتمی نیست).
     • نسخه‌های قبلی در activate پاک می‌شوند.
     • پیام SKIP_WAITING برای به‌روزرسانی فوری.
   ═══════════════════════════════════════════════════════════════════════ */
'use strict';

/* ═══ نسخه و نام کش‌ها ═══ */
const VERSION        = 'aurelle-v4.1.0';
const SHELL_CACHE    = VERSION + '-shell';
const FONT_CACHE     = VERSION + '-fonts';
const IMAGE_CACHE    = VERSION + '-images';
const OFFLINE_CACHE  = VERSION + '-offline';

/* ═══ محدودیت‌ها ═══ */
const MAX_IMAGE_ENTRIES = 60;
const MAX_FONT_ENTRIES  = 30;

/* ═══ میزبان‌ها ═══ */
const HOST_FONT_CSS  = 'fonts.googleapis.com';
const HOST_FONT_FILE = 'fonts.gstatic.com';
const HOST_IMAGE     = 'images.unsplash.com';

/* ═══════════════════════════════════════════════════════════════════════
   فهرست پوسته — تمام فایل‌های لازم برای اجرای آفلاین برنامه
   ═══════════════════════════════════════════════════════════════════════ */
const SHELL_ASSETS = [
  /* HTML + متادیتا */
  './',
  './index.html',
  './offline.html',
  './manifest.json',

  /* CSS — ترتیب مهم برای بارگذاری درست */
  './css/variables.css',
  './css/base.css',
  './css/components.css',
  './css/sections.css',
  './css/dialogs.css',
  './css/animations.css',
  './css/admin.css',

  /* JS — ترتیب مهم برای بارگذاری درست */
  './js/core.js',
  './js/api.js',
  './js/data.js',
  './js/sync.js',
  './js/state.js',
  './js/ui.js',
  './js/cart.js',
  './js/pdp.js',
  './js/checkout.js',
  './js/auth.js',
  './js/concierge.js',
  './js/atelier.js',
  './js/admin.js',
  './js/main.js',

  /* آیکون‌ها (مسیر جدید در assets/) */
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/icons/icon-maskable-512.png'
];

/* ═══════════════════════════════════════════════════════════════════════
   نصب — کش کردن پوسته
   ═══════════════════════════════════════════════════════════════════════
   نکته: addAll اتمی است — اگر یک فایل گم باشد کل نصب شکست می‌خورد.
   پس هر فایل جداگانه با try/catch اضافه می‌شود تا نصب همیشه موفق شود.
   ═══════════════════════════════════════════════════════════════════════ */
self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    await Promise.all(SHELL_ASSETS.map(async url => {
      try {
        const req = new Request(url, { cache: 'reload' });
        const res = await fetch(req);
        if (res && (res.ok || res.type === 'opaque')) {
          await cache.put(url, res.clone());
        }
      } catch (_) {
        /* فایل در دسترس نیست — نصب ادامه می‌یابد */
        console.warn('[Aurelle SW] فایل در دسترس نبود:', url);
      }
    }));
    /* پیش‌کش کردن offline.html در کش اختصاصی */
    try {
      const offCache = await caches.open(OFFLINE_CACHE);
      const offRes = await fetch(new Request('./offline.html', { cache: 'reload' }));
      if (offRes && offRes.ok) await offCache.put('./offline.html', offRes.clone());
    } catch (_) {}

    await self.skipWaiting();
  })());
});

/* ═══════════════════════════════════════════════════════════════════════
   فعال‌سازی — پاک کردن کش‌های نسخهٔ قدیم + فعال‌سازی navigation preload
   ═══════════════════════════════════════════════════════════════════════ */
self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keep = new Set([SHELL_CACHE, FONT_CACHE, IMAGE_CACHE, OFFLINE_CACHE]);
    const names = await caches.keys();
    await Promise.all(
      names
        .filter(n => !keep.has(n) && n.startsWith('aurelle-'))
        .map(n => caches.delete(n))
    );

    /* Navigation preload برای ناوبری‌های سریع‌تر */
    if ('navigationPreload' in self.registration) {
      try { await self.registration.navigationPreload.enable(); } catch (_) {}
    }

    await self.clients.claim();
  })());
});

/* ═══════════════════════════════════════════════════════════════════════
   ابزار: هرسِ کش (FIFO)
   ═══════════════════════════════════════════════════════════════════════
   Cache API کلیدها را به ترتیب درج برمی‌گرداند. LRU واقعی نیازمند
   IndexedDB است که سربارش برای ۶۰ ورودی توجیه‌پذیر نیست.
   ═══════════════════════════════════════════════════════════════════════ */
async function trimCache(cacheName, max) {
  try {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    if (keys.length <= max) return;
    const toDelete = keys.slice(0, keys.length - max);
    await Promise.all(toDelete.map(k => cache.delete(k)));
  } catch (_) {}
}

/* ═══════════════════════════════════════════════════════════════════════
   استراتژی ۱: Cache-First
   برای فایل‌های تغییرناپذیر: فونت‌های باینری، تصاویر پوسته، آیکون‌ها
   ═══════════════════════════════════════════════════════════════════════ */
async function cacheFirst(request, cacheName, maxEntries = 0) {
  try {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);
    if (hit) return hit;

    const res = await fetch(request);
    if (res && res.ok) {
      try {
        await cache.put(request, res.clone());
        if (maxEntries > 0) trimCache(cacheName, maxEntries);
      } catch (_) {}
    }
    return res;
  } catch (_) {
    /* آفلاین و در کش نیست — یک پاسخ معتبر برمی‌گردانیم، نه promise رد‌شده */
    return new Response('', { status: 504, statusText: 'Offline — not cached' });
  }
}

/* ═══════════════════════════════════════════════════════════════════════
   استراتژی ۲: Stale-While-Revalidate
   تصاویر و سایر منابعی که «کمی کهنه» مهم نیستند.
   ═══════════════════════════════════════════════════════════════════════ */
async function staleWhileRevalidate(request, cacheName, maxEntries = 0) {
  try {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);

    /* شروع fetch به‌طور موازی — منتظر آن نمی‌مانیم اگر hit داریم */
    const networkPromise = fetch(request)
      .then(async res => {
        if (res && res.ok && res.type !== 'opaque') {
          try {
            await cache.put(request, res.clone());
            if (maxEntries > 0) trimCache(cacheName, maxEntries);
          } catch (_) {}
        }
        return res;
      })
      .catch(() => null);

    if (hit) return hit;

    const network = await networkPromise;
    if (network) return network;

    /* نه کش داشتیم نه شبکه — ۵۰۴ امن */
    return new Response('', { status: 504, statusText: 'Offline — not cached' });
  } catch (_) {
    return new Response('', { status: 504 });
  }
}

/* ═══════════════════════════════════════════════════════════════════════
   استراتژی ۳: Network-First با fallback به کش
   برای HTML اصلی، دارایی‌های محلی که ممکن است تغییر کنند
   ═══════════════════════════════════════════════════════════════════════ */
async function networkFirst(request, cacheName, fallbackUrl = null) {
  try {
    const res = await fetch(request);
    if (res && res.ok) {
      const cache = await caches.open(cacheName);
      try { await cache.put(request, res.clone()); } catch (_) {}
    }
    return res;
  } catch (_) {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);
    if (hit) return hit;

    if (fallbackUrl) {
      const offCache = await caches.open(OFFLINE_CACHE);
      const offHit = await offCache.match(fallbackUrl);
      if (offHit) return offHit;
    }
    return new Response('', { status: 504 });
  }
}

/* ═══════════════════════════════════════════════════════════════════════
   استراتژی ۴: Stale-While-Revalidate برای پوسته
   HTML/CSS/JS با اولویت سرعت، اما در پس‌زمینه تازه می‌شوند.
   ═══════════════════════════════════════════════════════════════════════ */
async function shellSWR(request) {
  return staleWhileRevalidate(request, SHELL_CACHE, 0);
}

/* ═══════════════════════════════════════════════════════════════════════
   مسیریابی درخواست‌ها
   ═══════════════════════════════════════════════════════════════════════ */
self.addEventListener('fetch', event => {
  const req = event.request;

  /* فقط GET را مدیریت می‌کنیم */
  if (req.method !== 'GET') return;

  let url;
  try { url = new URL(req.url); } catch (_) { return; }

  const isFontCSS  = url.hostname === HOST_FONT_CSS;
  const isFontFile = url.hostname === HOST_FONT_FILE;
  const isImage    = url.hostname === HOST_IMAGE;
  const isSameOrigin = url.origin === self.location.origin;
  const isNav = req.mode === 'navigate';
  const isHTML = isSameOrigin && /\.html?$/i.test(url.pathname);

  /* ── 0. لایهٔ PHP (api/) و پوشهٔ رسانه ─────────────────────────
     هرگز کش نمی‌شوند: قیمت، موجودی، سفارش و وضعیت مدیریت باید همیشه
     تازه باشند. بدون respondWith یعنی مستقیم می‌رود سراغ شبکه. */
  if (isSameOrigin && (/\/api\//i.test(url.pathname) || /\/uploads\//i.test(url.pathname))) return;

  /* ── ۱. ناوبری (صفحه‌ها) ────────────────────────────────────────
     Network-first + Navigation Preload + fallback به index.html / offline.html */
  if (isNav) {
    event.respondWith((async () => {
      /* Preload اگر موجود باشد */
      try {
        const pre = await event.preloadResponse;
        if (pre) {
          const cache = await caches.open(SHELL_CACHE);
          cache.put('./index.html', pre.clone()).catch(() => {});
          return pre;
        }
      } catch (_) {}

      try {
        const res = await fetch(req);
        if (res && res.ok) {
          const cache = await caches.open(SHELL_CACHE);
          cache.put('./index.html', res.clone()).catch(() => {});
        }
        return res;
      } catch (_) {
        /* آفلاین: اول index.html، بعد offline.html */
        const cache = await caches.open(SHELL_CACHE);
        const shell = await cache.match('./index.html', { ignoreSearch: true });
        if (shell) return shell;

        const offCache = await caches.open(OFFLINE_CACHE);
        const off = await offCache.match('./offline.html');
        if (off) return off;

        /* آخرین راه‌حل: پاسخ HTML درون‌خطی */
        return new Response(
          '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="utf-8">' +
          '<title>آفلاین</title>' +
          '<body style="font-family:system-ui;padding:2rem;background:#0a0b12;' +
          'color:#f2eee4;text-align:center;">' +
          '<h1>شما آفلاین هستید</h1>' +
          '<p>ارتباط با خانهٔ اُرِل قطع شده است.</p></body></html>',
          { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
        );
      }
    })());
    return;
  }

  /* ── ۲. فونت CSS گوگل ────────────────────────────────────────
     خروجی وابسته به User-Agent است → سپردن به HTTP cache مرورگر */
  if (isFontCSS) {
    /* خروجی وابسته به User-Agent است → سپردن به HTTP cache مرورگر.
       فقط درخواست‌های no-cors را رد کن؛ درخواست‌های CORS-دار (براههٔ preload)
       باید از مسیر SW عبور کنند تا با هدرهای درست پاسخ داده شوند. */
    if (req.mode === 'no-cors') return;
    event.respondWith(
      fetch(req).catch(() => caches.match(req).then(r => r || new Response('', { status: 504 })))
    );
    return;
  }

  /* ── ۳. فونت باینری ──────────────────────────────────────────
     تغییرناپذیر → Cache-first */
  if (isFontFile) {
    event.respondWith(cacheFirst(req, FONT_CACHE, MAX_FONT_ENTRIES));
    return;
  }

  /* ── ۴. تصاویر Unsplash ──────────────────────────────────────
     SWR + FIFO با سقف ۶۰ ورودی */
  if (isImage) {
    event.respondWith(staleWhileRevalidate(req, IMAGE_CACHE, MAX_IMAGE_ENTRIES));
    return;
  }

  /* ── ۵. دارایی‌های محلی (CSS/JS/تصاویر/فونت) ─────────────────
     Cache-first چون پوسته در نصب کش شده و با VERSION مدیریت می‌شود */
  if (isSameOrigin && /\.(?:css|js|png|jpg|jpeg|svg|webp|woff2?|ico)$/i.test(url.pathname)) {
    event.respondWith(cacheFirst(req, SHELL_CACHE));
    return;
  }

  /* ── ۶. HTML محلی (اگر خارج از مسیر ناوبری بیاید) ──────────── */
  if (isHTML) {
    event.respondWith(networkFirst(req, SHELL_CACHE, './offline.html'));
    return;
  }

  /* ── ۷. سایر درخواست‌ها ───────────────────────────────────────
     Network-first با fallback به کش */
  event.respondWith((async () => {
    try {
      const res = await fetch(req);
      if (res && res.ok && res.type !== 'opaque') {
        const cache = await caches.open(SHELL_CACHE);
        cache.put(req, res.clone()).catch(() => {});
      }
      return res;
    } catch (_) {
      try {
        const cache = await caches.open(SHELL_CACHE);
        const hit = await cache.match(req);
        if (hit) return hit;
      } catch (_) {}
      return new Response('', { status: 504 });
    }
  })());
});

/* ═══════════════════════════════════════════════════════════════════════
   پیام‌ها — پشتیبانی از آپدیت فوری و پاک‌سازی دستی
   ═══════════════════════════════════════════════════════════════════════ */
self.addEventListener('message', event => {
  if (!event.data) return;

  switch (event.data.type) {
    case 'SKIP_WAITING':
      self.skipWaiting();
      break;

    case 'CLEAR_IMAGES':
      caches.delete(IMAGE_CACHE).then(() => {
        if (event.ports && event.ports[0]) event.ports[0].postMessage({ ok: true });
      });
      break;

    case 'CLEAR_ALL':
      caches.keys().then(names =>
        Promise.all(names.filter(n => n.startsWith('aurelle-')).map(n => caches.delete(n)))
      ).then(() => {
        if (event.ports && event.ports[0]) event.ports[0].postMessage({ ok: true });
      });
      break;

    case 'VERSION':
      if (event.ports && event.ports[0]) {
        event.ports[0].postMessage({ version: VERSION, cache: SHELL_CACHE });
      }
      break;
  }
});

/* ═══════════════════════════════════════════════════════════════════════
   رویداد sync — برای پس‌زمینه‌ای که ممکن است بعداً اضافه شود
   ═══════════════════════════════════════════════════════════════════════ */
self.addEventListener('sync', event => {
  if (event.tag === 'aurelle-sync') {
    event.waitUntil(Promise.resolve());
  }
});

/* ═══════════════════════════════════════════════════════════════════════
   پایان — لاگ نسخه برای دیباگ
   ═══════════════════════════════════════════════════════════════════════ */
console.log(
  '%c ◆ Aurelle Service Worker ' + VERSION + ' ◆ ',
  'background:linear-gradient(115deg,#f6e7ab,#d4af37,#875f10);' +
  'color:#080604;padding:.3rem .9rem;font-family:Georgia;letter-spacing:.1em'
);