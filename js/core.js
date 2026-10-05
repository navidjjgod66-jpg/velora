/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — core.js
   ابزارهای پایه · زمان‌بند مشترک · Storage · Toast · Haptics · Environment
   این فایل هیچ وابستگی‌ای ندارد جز DOM. بارگذاری: قبل از همه.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';

/* ═══ Utilities ═══ */
const $  = (s, c = document) => c.querySelector(s);
const $$ = (s, c = document) => [...c.querySelectorAll(s)];
const html = document.documentElement;
const body = document.body;

const clamp   = (v, a, b) => Math.min(Math.max(v, a), b);
const lerp    = (a, b, t) => a + (b - a) * t;
const PHI     = 1.618033988749895;
const PHI2    = PHI * PHI;
const wait    = ms => new Promise(r => setTimeout(r, ms));
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
const fine    = matchMedia('(pointer: fine)').matches;
const coarse  = matchMedia('(pointer: coarse)').matches;
const FA      = '۰۱۲۳۴۵۶۷۸۹';

/* ═══ Performance tier (sync both naming schemes) ═══ */
const TIER = { level: 'high' };
(() => {
  const k = ['low', 'mid', 'high'].find(v => html.classList.contains('perf-' + v));
  if (k) { TIER.level = k; html.classList.add('p2-' + k); }
})();
const lowTier = () => TIER.level === 'low';

/* ═══ Shared RAF scheduler ═══
   One rAF loop for the whole page.

   Two bugs it used to have, both of which grew without bound:

   1. DOUBLE-SCHEDULING. `id` was cleared at the TOP of frame(), before the
      jobs ran. Any job that called RAF.add() during its own frame therefore
      reached wake() with id === null and scheduled a callback — and then the
      bottom of frame() scheduled a second one. ui.js's preloader tick does
      exactly that (RAF.add(tickPL)), so the loop forked into two permanent
      concurrent frame callbacks within the first second, each allocating
      Array.from(jobs) per frame from then on. `id` is now assigned at the
      BOTTOM, so a re-entrant add() during the frame finds id already set.

   2. THE JOBS SET NEVER SHRANK. RAF.add(fn) adds a function identity, and a
      freshly-allocated closure is a new identity every time, so a handler that
      registered a new arrow per event leaked one entry per event. The loop then
      called every one of those dead closures, inside a try/catch, on every
      frame, forever. Both call sites below now hoist their job into a named
      function and register that instead. */
const RAF = (() => {
  const jobs = new Set();
  let id = null;
  const frame = () => {
    for (const fn of Array.from(jobs)) {
      if (document.hidden) break;
      try { fn(); } catch(_) {}
    }
    /* Assigned last: a job that re-added itself during this frame must find id
       already non-null, or wake() would schedule a second loop. */
    if (jobs.size && !document.hidden) id = requestAnimationFrame(frame);
    else id = null;
  };
  const wake = () => { if (id === null && jobs.size && !document.hidden) id = requestAnimationFrame(frame); };
  addEventListener('visibilitychange', wake);
  return {
    add(fn) { jobs.add(fn); wake(); return fn; },
    drop(fn) { jobs.delete(fn); },
    has: fn => jobs.has(fn),
    size: () => jobs.size
  };
})();

/* ═══ Formats ═══ */
const faNum  = n => Number(n).toLocaleString('fa-IR');
const faPad  = (n, l = 2) => String(n).padStart(l, '0').replace(/\d/g, d => FA[d]);
const moneyT = n => faNum(Math.round(n)) + ' تومان';

/* ─── Persian / Arabic-Indic digits → ASCII ─────────────────────────────────
   ONE implementation, here, because this exact helper existed three times and
   one of the three was broken.

   The bug: auth.js used `value.replace(/\D/g, '')`. JavaScript's \D means
   "not [0-9]" and nothing else, so a Persian digit — which is what the login
   field's own placeholder shows ("۰۹۱۲۳۴۵۶۷۸۹") and therefore exactly what a
   Persian customer types — is not a digit to it and is deleted along with the
   punctuation. Typing the number shown on screen produced an empty string, so
   the field reported "شمارهٔ موبایل معتبر نیست" while visibly containing eleven
   digits. OTP is the primary login path on this storefront, and it was
   unreachable for the audience it was built for.

   The two working copies were velora-bridge.js (folds, then strips) and
   checkout.js (folds only). Both now call this.

   Ranges, taken from the code points rather than a lookup table:
     U+06F0–U+06F9  Persian      ۰۱۲۳۴۵۶۷۸۹
     U+0660–U+0669  Arabic-Indic ٠١٢٣٤٥٦٧٨٩
   Stripping is what the two callers need for a phone or a postal code, but it
   is applied AFTER the fold so no Persian digit can be mistaken for junk. */
const FA_DIGIT_RANGE = /[۰-۹٠-٩]/g;
const foldDigits = s => String(s == null ? '' : s).replace(FA_DIGIT_RANGE, d => {
  const c = d.charCodeAt(0);
  return String(c >= 0x06F0 ? c - 0x06F0 : c - 0x0660);
});
/* Fold, then keep only digits. */
const asciiDigits = s => foldDigits(s).replace(/[^0-9]/g, '');

const esc    = s => String(s).replace(/[&<>"'`]/g, c =>
  ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;', '`':'&#96;' }[c]));
/* ═══ Persian date formatters (single source, memoized instances) ═══ */
const FDATE = {
  long:   new Intl.DateTimeFormat('fa-IR', { dateStyle:'long' }),
  medium: new Intl.DateTimeFormat('fa-IR', { dateStyle:'medium', timeStyle:'short' }),
  eta:    new Intl.DateTimeFormat('fa-IR', { weekday:'long', month:'long', day:'numeric' })
};
const fdate = (v, style = 'long') => {
  const d = v instanceof Date ? v : new Date(typeof v === 'number' ? v * 1000 : v);
  if (isNaN(d)) return '';
  try { return FDATE[style] ? FDATE[style].format(d) : FDATE.long.format(d); }
  catch (_) { return ''; }
};
const eta = () => FDATE.eta.format(new Date(Date.now() + 3 * 864e5));
const buzz   = p => { try { if (navigator.vibrate) navigator.vibrate(p); } catch(_){} };
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

/* ═══ Memoize ═══ */
function memoize(fn, max = 120) {
  const store = new Map();
  const keyOf = a =>
    (a === null || typeof a !== 'object') ? String(a)
    : (Array.isArray(a) ? '[' + a.map(keyOf).join(',') + ']'
                        : '{' + Object.keys(a).sort().map(k => k + ':' + keyOf(a[k])).join(',') + '}');
  return function (...args) {
    const key = args.map(keyOf).join('');
    if (store.has(key)) return store.get(key);
    const val = fn.apply(this, args);
    if (store.size >= max) store.delete(store.keys().next().value);
    store.set(key, val);
    return val;
  };
}

/* ═══ Central timer registry ═══ */
const TIMERS = {
  live: new Map(),
  set(fn, ms, key) {
    const k = key || fn;
    this.clear(k);
    const id = setInterval(() => { if (!document.hidden) fn(); }, ms);
    this.live.set(k, { id, kind: 'interval' });
    return id;
  },
  once(fn, ms, key) {
    const k = key || fn;
    this.clear(k);
    const id = setTimeout(() => {
      this.live.delete(k);
      try { fn(); } catch (e) { console.warn('[Aurelle timer]', k, e); }
    }, ms);
    this.live.set(k, { id, kind: 'timeout' });
    return id;
  },
  clear(k) {
    const entry = this.live.get(k);
    if (!entry) return;
    if (entry.kind === 'interval') clearInterval(entry.id);
    else clearTimeout(entry.id);
    this.live.delete(k);
  },
  has: k => this.live.has(k)
};

/* ═══ Storage with memory fallback ═══ */
const memory = new Map();
let storageWarned = false;
const warnQuota = k => {
  if (storageWarned) return;
  storageWarned = true;
  console.warn('[Aurelle] ذخیره‌سازی محلی در دسترس نیست', k);
};
const LS = {
  get(k, f) {
    try {
      const raw = localStorage.getItem(k);
      if (raw === null) return memory.has(k) ? memory.get(k) : f;
      return JSON.parse(raw) ?? f;
    } catch { return memory.has(k) ? memory.get(k) : f; }
  },
  /* مقدار خام رشته‌ای (براههٔ اسکریپت anti-FOUC در index.html با همین قالب ذخیره می‌کند) */
  getRaw(k, f) {
    try {
      let v = localStorage.getItem(k);
      if (v === null) return f;
      if (v.charAt(0) === '"' || v.charAt(0) === '{') { try { v = JSON.parse(v); } catch (_) {} }
      return v == null ? f : v;
    } catch { return f; }
  },
  set(k, v) {
    memory.set(k, v);
    try { localStorage.setItem(k, JSON.stringify(v)); return true; }
    catch (e) { warnQuota(k); if (v === null) memory.delete(k); return false; }
  },
  del(k) { memory.delete(k); try { localStorage.removeItem(k); } catch(_){} },
  clear() { memory.clear(); }
};

/* ═══ Storage keys (single source of truth) ═══ */
const K = {
  bag:'ae.bag.v5', wish:'ae.wish.v3', theme:'ae.theme.v2', mode:'ae.mode.v2',
  scene:'ae.scene.v2', ann:'ae.ann.v3', recent:'ae.recent.v3', exit:'ae.exit.v3',
  promo:'ae.promo.v3', custom:'ae.custom.v2', profile:'ae.profile.v2',
  orders:'ae.orders.v2', urevs:'ae.urevs.v2', dna:'ae.dna.v2',
  /* نشست سروری (PHP) — فقط نشانه‌ها، رمز در سرور می‌ماند */
  session:'ae.session.v1', otpSess:'ae.otpsess.v1', payPend:'ae.paypend.v1'
};

/* ═══ Stars ═══ */
const STAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>';
const starsHTML = r => {
  let s = '';
  for (let i = 1; i <= 5; i++) s += `<span class="${i <= Math.round(r) ? '' : 'off'}">${STAR}</span>`;
  return s;
};

/* ═══ Helpers ═══ */
function backdropClose(d) {
  d.addEventListener('click', e => {
    if (e.target !== d) return;
    /* ✅ به‌جای اولین فرزند، از bounds خود دیالوگ استفاده کن */
    const r = d.getBoundingClientRect();
    if (!r.width || !r.height) return;
    if (e.clientX < r.left || e.clientX > r.right ||
        e.clientY < r.top  || e.clientY > r.bottom) {
      d.close();
    }
  });
}
function withLoad(btn, ms) {
  if (!btn) return;
  btn.classList.add('loading'); btn.disabled = true;
  setTimeout(() => { btn.classList.remove('loading'); btn.disabled = false; }, ms);
}

/* ═══ Haptics ═══ */
const HAPTIC = { add: 8, remove: [3,40,3], success: [10,30,12], warn: [4,60,4] };
const haptic = kind => { if (!reduced) buzz(HAPTIC[kind] ?? HAPTIC.add); };

/* ═══ Toast ═══
   A single element is reused, so a new toast replaces whatever was on screen.
   That is the right behaviour — two stacked messages compete for the same
   space and the older one is stale — but the replacement has to be honest about
   what it discards.

   The problem is the action. When an "undone" toast is replaced, the button is
   removed with the message, and the undo it offered is silently withdrawn: the
   customer is told an item was removed, then told something else, and has no way
   to put it back even though they were given one. So an incoming message that
   carries no action of its own will not evict a message that does — the undoable
   one finishes its timer, and the new message is shown after it.

   The queue is one deep and holds only a single message, because holding more
   would mean a customer whose basket triggers three toasts waits through all
   three before seeing the last one. */
let toastT = null;
let toastPending = null;

/* Hands the queued message over once the one in front of it has finished.

   Driven from the outgoing toast's own timer rather than by polling: a poll
   would either wake every 100 ms for the whole life of the message or miss the
   window between the class being removed and the next one being added. The
   timer that hides the toast is the same event, so hooking it is exact. */
function toastSchedule(ms, action) {
  clearTimeout(toastT);
  toastT = setTimeout(() => {
    const el = $('#toasts');
    if (el) el.classList.remove('show');
    if (toastPending) {
      const next = toastPending;
      toastPending = null;
      /* Deferred by a tick so the outgoing toast is fully torn down before the
         next one rebuilds the same element's children. */
      setTimeout(() => toast(next.msg, next.kind, next.action), 60);
    }
  }, ms);
  return toastT;
}

/* `ms` is a fourth argument rather than a property of `action`, so that every
   one of the existing call sites keeps its meaning and an undoable toast and a
   long-lived notice are not forced to share one parameter.

   It exists because a message's lifetime is part of its content. The default
   4200ms is right for "added to bag" and wrong for "your payment is being
   confirmed and nothing is required from you" — a notice that needs a decision
   cannot be allowed to expire before it has been read, and the only way to give
   it time without freezing the whole channel is to say how long it needs. */
function toast(msg, kind = 'ok', action = null, ms = 0) {
  const el = $('#toasts'); if (!el) return;

  /* The current message has an undo button and the newcomer has none: hold the
     newcomer rather than destroying a capability the customer cannot get back.

     Only the most recent held message is kept. Three toasts in quick succession
     would otherwise make the customer read all three before seeing the last,
     which is the queueing behaviour this exists to avoid — and in a basket, the
     last one is the one that explains what actually happened. */
  const currentHasAction = !!(el.querySelector('.toast-act') && el.classList.contains('show'));
  if (currentHasAction && !action) {
    toastPending = { msg, kind, action };
    return;
  }
  /* The newcomer is itself undoable — showing it now is what the customer asked
     for by performing an undoable action. */
  toastPending = null;

  el.classList.toggle('err', kind === 'err');
  el.textContent = '';
  const dot = document.createElement('span');
  dot.className = 'tdm'; dot.setAttribute('aria-hidden', 'true');
  const bodyEl = document.createElement('span');
  bodyEl.className = 'tdx'; bodyEl.textContent = String(msg);
  el.append(dot, bodyEl);
  if (action) {
    const b = document.createElement('button');
    b.className = 'toast-act'; b.type = 'button';
    b.textContent = String(action.label || 'واگرد');
    b.onclick = () => {
      action.fn();
      /* Clear the button first, so the "does the current toast have an action"
         test in a later call() is looking at a message that has been acted on. */
      b.remove();
      if (toastPending) {
        const next = toastPending;
        toastPending = null;
        setTimeout(() => toast(next.msg, next.kind, next.action), 0);
      } else {
        el.classList.remove('show');
      }
      clearTimeout(toastT);
    };
    el.appendChild(b);
  }
  el.classList.add('show');
  toastSchedule(ms > 0 ? ms : (action ? 5200 : 4200), action);
}

/* ═══ Global interaction feedback (ripple + haptic) ═══ */
document.addEventListener('pointerdown', e => {
  const hit = e.target;
  if (hit.closest('.btn,.chip,.wish,.icon-btn,.size,.sw,.stepper button,.dock-a')) buzz(6);
  if (reduced) return;
  const el = hit.closest('.btn-solid,.btn-ghost,.chip,.icon-btn');
  if (!el) return;
  const r = el.getBoundingClientRect();
  const rip = document.createElement('span');
  const size = Math.max(r.width, r.height);
  rip.className = 'ripple';
  rip.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-r.left-size/2}px;top:${e.clientY-r.top-size/2}px`;
  if (!el.style.position) el.style.position = 'relative';
  if (!el.style.overflow)  el.style.overflow  = 'hidden';
  el.appendChild(rip);
  setTimeout(() => rip.remove(), 650);
}, { passive:true });

document.addEventListener('click', e => {
  if (e.target.closest('.js-add') && !e.target.disabled) haptic('success');
}, { passive:true });

/* ═══ Focus trap for non-native overlays ═══ */
const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),' +
                  'select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
function trapFocus(host, onEscape) {
  host.addEventListener('keydown', e => {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); onEscape(); return; }
    if (e.key !== 'Tab') return;
    const items = $$(FOCUSABLE, host).filter(el => el.offsetParent !== null || el === document.activeElement);
    if (!items.length) { e.preventDefault(); return; }
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (!host.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  });
}
const overlayOpen = new Set();
function overlayShow(host, onClose) {
  overlayOpen.add(host);
  host._luxOpener = document.activeElement;
  host.removeAttribute('inert');
  const first = $$(FOCUSABLE, host).find(el => el.offsetParent !== null);
  first && first.focus({ preventScroll:true });
  if (onClose) host._luxOnClose = onClose;
}
function overlayHide(host) {
  if (!overlayOpen.has(host)) return;
  overlayOpen.delete(host);
  const back = host._luxOpener;
  host._luxOpener = null;
  host._luxOnClose && host._luxOnClose();
  back && back.focus && back.focus({ preventScroll:true });
}

/* ═══ Shared dialog lifecycle (native <dialog>) — opener focus restore + lenis pause ═══ */
const dialogCloseH = d => {
  d._opener && d._opener.focus && d._opener.focus({ preventScroll:true });
  d._opener = null; dlStart();
};
const wireDialog = d => {
  if (!d || d.dataset.aeDlg) return d;
  d.dataset.aeDlg = '1';
  backdropClose(d);
  d.addEventListener('close', () => dialogCloseH(d));
  return d;
};

/* ═══ Lenis motion (boot deferred) ═══ */
let lenis = null;
const useLenis = !reduced && !coarse;
function bootMotion() {
  if (!useLenis || lenis) return;
  if (typeof window.Lenis === 'undefined') return;
  if (window.gsap && window.ScrollTrigger) gsap.registerPlugin(ScrollTrigger);
  if (window.gsap && window.Flip) gsap.registerPlugin(Flip);
  lenis = new Lenis({
    duration: 1.1,
    easing: t => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    smoothWheel: true,
    touchMultiplier: 2
  });
  lenis.on('scroll', () => window.ScrollTrigger && ScrollTrigger.update());
  if (window.gsap) { gsap.ticker.add(t => lenis.raf(t * 1000)); gsap.ticker.lagSmoothing(0); }
  else { const raf = t => { lenis.raf(t); RAF.add(raf); }; RAF.add(raf); }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootMotion);
else bootMotion();

const dlStop  = () => { lenis && lenis.stop(); };
const dlStart = () => { lenis && lenis.start(); };
const scrollToEl = t => {
  if (lenis) lenis.scrollTo(t, { offset:-100, duration:1.2 });
  else {
    const el = typeof t === 'string' ? $(t) : t;
    el && el.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth' });
  }
};

/* ═══ Environment: dust canvas, motes, cursor ═══ */
const dust = $('#dust');
if (dust && !reduced && !lowTier()) {
  const ctx = dust.getContext('2d');
  let W=0,H=0,pts=[],pmx=-999,pmy=-999,accentCol=null;
  const resize = () => {
    const d = Math.min(devicePixelRatio || 1, coarse ? 1.25 : 1.5);
    W = innerWidth; H = innerHeight;
    dust.width = W * d; dust.height = H * d;
    ctx.setTransform(d, 0, 0, d, 0, 0);
  };
  const seed = () => {
    const n = coarse
      ? clamp(Math.round(W * H / 46000), 14, 34)
      : clamp(Math.round(W * H / 26000), 36, 96);
    pts = Array.from({ length:n }, (_, i) => ({
      x: Math.random()*W, y: Math.random()*H,
      r: .4 + ((i*PHI) % 1) * 1.5,
      vy: -.05 - Math.random()*.16, vx: (Math.random()-.5)*.08,
      a: .15 + Math.random()*.5, tw: Math.random()*6.283, ts: .008 + Math.random()*.02
    }));
  };
  /* Color probe: oklch() cannot be read raw by canvas. */
  const colorProbe = document.createElement('span');
  colorProbe.setAttribute('aria-hidden', 'true');
  colorProbe.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:0;height:0;pointer-events:none;visibility:hidden;color:var(--accent)';
  document.body.appendChild(colorProbe);
  const readAccent = () => {
    try {
      const c = getComputedStyle(colorProbe).color;
      accentCol = (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent' && c !== '') ? c : '#d4af37';
    } catch (_) { accentCol = '#d4af37'; }
  };
  readAccent();
  new MutationObserver(readAccent).observe(html, { attributes:true, attributeFilter:['data-theme','data-scene','data-mode'] });

  const BUCKETS = 4;
  let lastDust = -1e9;
  const dustJob = t => {
    if (t - lastDust < 31) return;      /* cap ~30fps */
    lastDust = t;
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = accentCol;
    for (let b = 0; b < BUCKETS; b++) {
      const lo = b / BUCKETS, hi = (b + 1) / BUCKETS;
      ctx.beginPath();
      let any = false;
      for (const p of pts) {
        p.tw += p.ts;
        const dx = p.x - pmx, dy = p.y - pmy, d2 = dx*dx + dy*dy;
        if (d2 < 26000 && d2 > 1) {
          const f = (1 - d2/26000) * .55;
          p.x += dx/Math.sqrt(d2) * f; p.y += dy/Math.sqrt(d2) * f;
        }
        p.x += p.vx; p.y += p.vy;
        if (p.y < -4) { p.y = H+4; p.x = Math.random()*W; }
        if (p.x < -4) p.x = W+4;
        if (p.x > W+4) p.x = -4;
        const twinkle = p.a * (.55 + .45 * Math.sin(p.tw));
        if (twinkle < lo || twinkle >= hi) continue;
        ctx.moveTo(p.x + p.r, p.y);
        ctx.arc(p.x, p.y, p.r, 0, 6.2832);
        any = true;
      }
      if (any) { ctx.globalAlpha = (lo + hi) / 2; ctx.fill(); }
    }
    ctx.globalAlpha = 1;
  };
  resize(); seed();
  RAF.add(dustJob);
  addEventListener('pointermove', e => { pmx = e.clientX; pmy = e.clientY; }, { passive:true });
  /* Resize used to call resize() + seed() on EVERY resize event, which
     reallocated the whole particle array (up to 96 objects, each with six
     random fields) for every intermediate width a window drag produces — a
     mobile browser fires these continuously while the address bar collapses.
     The canvas has to be resized immediately (it is sized in device pixels),
     but the seed can wait for the drag to settle. */
  let seedTimer = 0;
  addEventListener('resize', () => {
    resize();
    clearTimeout(seedTimer);
    seedTimer = setTimeout(seed, 180);
  }, { passive:true });
}
if (!reduced && !lowTier()) {
  const m = $('.motes');
  if (m) for (let i = 0; i < 18; i++) {
    const s = document.createElement('i');
    s.style.left = (Math.random()*100) + '%';
    s.style.animationDuration = (14 + Math.random()*22) + 's';
    s.style.animationDelay = (-Math.random()*20) + 's';
    s.style.width = s.style.height = (2 + Math.random()*3) + 'px';
    m.appendChild(s);
  }
}

const curDot = $('#cursorDot'), curRing = $('#cursor'), curLab = $('#curLab');
let mx = innerWidth/2, my = innerHeight/2, rx = mx, ry = my;
if (fine && !reduced && curRing) {
  /* Three named jobs, registered once each.

     The dot job used to be `RAF.add(() => { ... })` — a new arrow on every
     pointermove. RAF.add takes an identity, so the Set kept every arrow the
     pointer had ever produced and called all of them, in a try/catch, every
     frame, for the life of the page. A mouse move fires up to 120 times a
     second, so this was tens of thousands of dead closures within a minute of
     ordinary use. Same for the ring's lerp, which also never stopped: it
     returned early once converged but was still *registered*, so the rAF loop
     ran forever even with the cursor parked.

     The lerp now genuinely deregisters itself on convergence and re-registers
     on the next move, so a page with an idle mouse costs zero frames. */
  let dotQueued = false;
  const dotJob = () => {
    dotQueued = false;
    if (!curDot) return;
    curDot.style.transform = `translate(${mx}px,${my}px) translate(-50%,-50%)`;
    curDot.style.opacity = curRing.style.opacity = '1';
  };
  addEventListener('pointermove', e => {
    mx = e.clientX; my = e.clientY;
    if (dotQueued || !curDot) return;
    dotQueued = true;
    RAF.add(dotJob);
  }, { passive:true });

  let ringLive = false;
  const lerpJob = () => {
    const dx = mx - rx, dy = my - ry;
    /* Converged: stop. One style write to settle exactly on target, then drop
       out of the loop until the pointer moves again. */
    if (dx*dx + dy*dy < 0.04) {
      rx = mx; ry = my;
      curRing.style.transform = `translate(${rx}px,${ry}px) translate(-50%,-50%)`;
      ringLive = false;
      RAF.drop(lerpJob);
      return;
    }
    rx = lerp(rx, mx, .16); ry = lerp(ry, my, .16);
    curRing.style.transform = `translate(${rx}px,${ry}px) translate(-50%,-50%)`;
    RAF.add(lerpJob);
  };
  addEventListener('pointermove', () => {
    if (ringLive) return;
    ringLive = true;
    RAF.add(lerpJob);
  }, { passive:true });

  document.addEventListener('pointerover', e => {
    const add = e.target.closest('.btn-solid,.btn--gold,.js-add');
    const custom = e.target.closest('[data-cursor]');
    const view = e.target.closest('.prod-media,.pdp__stage,.lb-item figure');
    const hot = e.target.closest('a,button,input,select,textarea,label');
    curRing.classList.remove('hover','view','add');
    if (add) { curLab.textContent = 'افزودن'; curRing.classList.add('add'); }
    else if (custom) { curLab.textContent = custom.dataset.cursor; curRing.classList.add('hover'); }
    else if (view) { curLab.textContent = 'نمایش'; curRing.classList.add('view'); }
    else if (hot) curRing.classList.add('hover');
  });
  document.addEventListener('pointerleave', () => {
    curDot.style.opacity = curRing.style.opacity = '0';
  });
  /* Cursor sparks. Throttled by wall clock to ~12/s. The removal timer is a
     plain setTimeout rather than a TIMERS.once entry because these overlap by
     design — several sparks can be alive at once — and TIMERS.once is keyed, so
     a shared key would make each new spark cancel the previous one's removal
     and leave them all in the document forever. */
  let lastSp = 0;
  addEventListener('pointermove', e => {
    const now = performance.now();
    if (now - lastSp < 80) return;
    lastSp = now;
    const s = document.createElement('span');
    s.className = 'spark';
    s.style.left = e.clientX + 'px';
    s.style.top = e.clientY + 'px';
    s.style.setProperty('--sx', (Math.random()-.5)*30 + 'px');
    s.style.setProperty('--sy', (Math.random()-.5)*30 + 'px');
    document.body.appendChild(s);
    setTimeout(() => s.remove(), 700);
  }, { passive:true });
} else if (curDot) { curDot.remove(); curRing && curRing.remove(); }

/* ═══ Public API ═══ */
window.AE = {
  $, $$, html, body,
  clamp, lerp, PHI, PHI2, wait, reduced, fine, coarse, FA,
  TIER, lowTier,
  RAF, TIMERS, LS, K,
  faNum, faPad, moneyT, esc, eta, fdate, buzz, debounce,
  foldDigits, asciiDigits,
  memoize,
  STAR, starsHTML,
  backdropClose, withLoad, wireDialog, dialogCloseH,
  HAPTIC, haptic, toast,
  trapFocus, overlayShow, overlayHide, overlayOpen, FOCUSABLE,
  lenis: () => lenis, dlStop, dlStart, scrollToEl
};
})();