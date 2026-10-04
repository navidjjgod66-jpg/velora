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

/* ═══ Shared RAF scheduler ═══ */
const RAF = (() => {
  const jobs = new Set();
  let id = null;
  const frame = () => {
    id = null;
    for (const fn of Array.from(jobs)) {
      if (document.hidden) break;
      try { fn(); } catch(_) {}
    }
    if (jobs.size && !document.hidden) id = requestAnimationFrame(frame);
    else id = null;
  };
  const wake = () => { if (id === null && jobs.size && !document.hidden) id = requestAnimationFrame(frame); };
  addEventListener('visibilitychange', wake);
  return {
    add(fn) { jobs.add(fn); wake(); return fn; },
    drop(fn) { jobs.delete(fn); },
    has: fn => jobs.has(fn)
  };
})();

/* ═══ Formats ═══ */
const faNum  = n => Number(n).toLocaleString('fa-IR');
const faPad  = (n, l = 2) => String(n).padStart(l, '0').replace(/\d/g, d => FA[d]);
const moneyT = n => faNum(Math.round(n)) + ' تومان';
const esc    = s => String(s).replace(/[&<>"'`]/g, c =>
  ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;', '`':'&#96;' }[c]));
const eta    = () => new Intl.DateTimeFormat('fa-IR', { weekday:'long', month:'long', day:'numeric' })
                     .format(new Date(Date.now() + 3 * 864e5));
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
  orders:'ae.orders.v2', urevs:'ae.urevs.v2', dna:'ae.dna.v2'
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

/* ═══ Toast ═══ */
let toastT = null;
function toast(msg, kind = 'ok', action = null) {
  const el = $('#toasts'); if (!el) return;
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
    b.onclick = () => { action.fn(); el.classList.remove('show'); clearTimeout(toastT); };
    el.appendChild(b);
  }
  el.classList.add('show');
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove('show'), action ? 5200 : 4200);
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
  addEventListener('resize', () => { resize(); seed(); }, { passive:true });
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
  let dotQueued = false;
  addEventListener('pointermove', e => {
    mx = e.clientX; my = e.clientY;
    if (dotQueued || !curDot) return;
    dotQueued = true;
    RAF.add(() => {
      dotQueued = false;
      curDot.style.transform = `translate(${mx}px,${my}px) translate(-50%,-50%)`;
      curDot.style.opacity = curRing.style.opacity = '1';
    });
  }, { passive:true });
  const lerpJob = () => {
    const dx = mx - rx, dy = my - ry;
    if (dx*dx + dy*dy < 0.04) { rx = mx; ry = my; return; }
    rx = lerp(rx, mx, .16); ry = lerp(ry, my, .16);
    curRing.style.transform = `translate(${rx}px,${ry}px) translate(-50%,-50%)`;
  };
  RAF.add(lerpJob);
  document.addEventListener('pointerover', e => {
    const add = e.target.closest('.btn-solid,.js-add');
    const custom = e.target.closest('[data-cursor]');
    const view = e.target.closest('.f-med,.lb-item figure,.prod-media,.pdp__stage,.selcard__m');
    const hot = e.target.closest('a,button,input,select,textarea,label');
    curRing.classList.remove('hover','view','add');
    if (add) { curLab.textContent = 'افزودن'; curRing.classList.add('add'); }
    else if (custom) { curLab.textContent = custom.dataset.cursor; curRing.classList.add('hover'); }
    else if (view) { curLab.textContent = 'نمایش'; curRing.classList.add('view'); }
    else if (hot) curRing.classList.add('hover');
  });
  document.addEventListener('pointerleave', () => { curDot.style.opacity = curRing.style.opacity = '0'; });
  let lastSp = 0;
  addEventListener('pointermove', e => {
    const now = performance.now(); if (now - lastSp < 80) return;
    lastSp = now;
    const s = document.createElement('span'); s.className = 'spark';
    s.style.left = e.clientX + 'px'; s.style.top = e.clientY + 'px';
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
  faNum, faPad, moneyT, esc, eta, buzz, debounce,
  memoize,
  STAR, starsHTML,
  backdropClose, withLoad,
  HAPTIC, haptic, toast,
  trapFocus, overlayShow, overlayHide, overlayOpen, FOCUSABLE,
  lenis: () => lenis, dlStop, dlStart, scrollToEl
};
})();