/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — ui.js
   Reveals · Scroll chrome · Theme · Mode · Mnav · Sheet · Size guide ·
   Measure wizard · Recent · Compare · Command palette · Image loader
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, clamp, reduced, lowTier, RAF, TIMERS, LS, K,
  faNum, faPad,
  toast, dlStop, dlStart, scrollToEl
} = window.AE;
const {
  PRODUCTS, FAM, IDX_CATS, TICKER_ITEMS
} = window.AE_DATA;
const { state } = window.AE_STATE;

/* ═══ Preloader ═══ */
const pl = $('#pl'), plNum = $('#plNum'), plBar = $('#plBar');
let plProg = 0, plDone = false;
function finishPL() {
  if (plDone) return; plDone = true; plProg = 100;
  if (plNum) plNum.textContent = '۱۰۰٪';
  if (plBar) plBar.style.transform = 'scaleX(1)';
  setTimeout(() => {
    pl && pl.classList.add('done');
    document.body.classList.add('loaded');
    setTimeout(() => pl && pl.remove(), 1600);
    initSplitReveal(); initHeroParallax();
  }, 300);
}
const tickPL = () => {
  if (plDone) return;
  plProg = Math.min(100, plProg + Math.random()*5 + 3);
  if (plNum) plNum.textContent = faNum(Math.floor(plProg)) + '٪';
  if (plBar) plBar.style.transform = `scaleX(${plProg/100})`;
  if (plProg < 100) RAF.add(tickPL); else TIMERS.once(finishPL, 180, 'pl:done');
};
if (reduced || !pl) finishPL();
else { RAF.add(tickPL); TIMERS.once(finishPL, 2100, 'pl:cap'); }
addEventListener('load', () => {
  TIMERS.once(finishPL, 400, 'pl:load');
}, { once:true });

/* ═══ Announcement ═══ */
(() => {
  const bar = $('#annonce'); if (!bar) return;
  const msgs = $$('.annonce-msg', bar), close = $('#annonceX');
  if (!msgs.length) return;
  if (LS.get(K.ann, false)) { bar.removeAttribute('data-open'); body.classList.remove('has-ann'); return; }
  let i = 0;
  const show = n => {
    msgs[i].classList.remove('is-live');
    i = (n + msgs.length) % msgs.length;
    msgs[i].classList.add('is-live');
  };
  const arm = () => { if (reduced || document.hidden) return; TIMERS.set(() => show(i+1), 6000, 'ann'); };
  const pause = () => TIMERS.clear('ann');
  bar.addEventListener('mouseenter', pause);
  bar.addEventListener('mouseleave', arm);
  bar.addEventListener('touchstart', pause, { passive:true });
  bar.addEventListener('touchend', arm, { passive:true });
  bar.addEventListener('touchcancel', arm, { passive:true });
  document.addEventListener('visibilitychange', () => document.hidden ? pause() : arm());
  arm();
  close && close.addEventListener('click', () => {
    bar.removeAttribute('data-open'); body.classList.remove('has-ann');
    pause(); LS.set(K.ann, true);
  });
})();

/* ═══ Scroll chrome ═══ */
const prog = $('#prog'), toTop = $('#toTop'), progRing = $('#toTop .prog-ring'), dock = $('#dock');
let lastY = 0, sTk = false;
let docMax = 0;
const measureDoc = () => { docMax = Math.max(0, html.scrollHeight - innerHeight); };
measureDoc();
if ('ResizeObserver' in window) new ResizeObserver(() => { measureDoc(); RAF.add(onScroll); }).observe(body);

let openDialogCount = 0;
const dialogOpen = () => openDialogCount > 0;
const bumpDialogs = () => { openDialogCount = $$('dialog[open]').length; };
try {
  new MutationObserver(bumpDialogs).observe(document.body, { subtree:true, attributes:true, attributeFilter:['open'] });
} catch(_){}
$$('dialog').forEach(d => d.addEventListener('close', bumpDialogs));
bumpDialogs();

let lastMoveTs = 0;
const SCROLL_IDLE = 140;

function onScroll() {
  const y = scrollY, k = docMax > 0 ? clamp(y/docMax, 0, 1) : 0;
  if (prog) prog.style.transform = `scaleX(${k})`;
  if (progRing) progRing.style.setProperty('--rot', `${k*360}deg`);
  const now = performance.now();
  const moved = Math.abs(y - lastY) > 1;
  body.classList.toggle('scrolled', y > 40);
  if (!body.classList.contains('mnav-on')) {
    if (y > lastY + 10 && y > 240) body.classList.add('hdr-hide');
    else if (y < lastY - 10 || y <= 240) body.classList.remove('hdr-hide');
  }
  toTop && toTop.classList.toggle('show', y > 700);
  /* focus-within استثنا: تا وقتی کاربر داخل دک‌هاست (کیبورد/صفحه‌کلید)،
     هرگز پنهان نشود — حتی در حال اسکرول نزولی. */
  if (dock) dock.classList.toggle('is-hidden',
    ((y > lastY + 6 && y > 240) || dialogOpen()) && !dock.matches(':focus-within'));
  if (moved) {
    lastMoveTs = now;
    if (!html.classList.contains('scrolling')) html.classList.add('scrolling');
  } else if (html.classList.contains('scrolling') && now - lastMoveTs > SCROLL_IDLE) {
    html.classList.remove('scrolling');
  }
  lastY = y; sTk = false;
}
addEventListener('scroll', () => { if (!sTk) { sTk = true; RAF.add(onScroll); } }, { passive:true });
addEventListener('resize', () => { measureDoc(); RAF.add(onScroll); }, { passive:true });
onScroll();

toTop && toTop.addEventListener('click', () => {
  if (window.AE.lenis()) window.AE.lenis().scrollTo(0, { duration:1.2 });
  else scrollTo({ top:0, behavior: reduced ? 'auto' : 'smooth' });
});

/* ═══ Scroll spy ═══ */
const spy = new IntersectionObserver(es => es.forEach(e => {
  if (!e.isIntersecting) return;
  const id = '#' + e.target.id;
  $$('.nav-links a').forEach(a => a.getAttribute('href') === id ? a.setAttribute('aria-current','page') : a.removeAttribute('aria-current'));
  $$('.rail a').forEach(a => a.getAttribute('href') === id ? a.setAttribute('aria-current','true') : a.getAttribute('href') !== '#top' && a.removeAttribute('aria-current'));
  $$('.dock a[href]').forEach(a => a.getAttribute('href') === id ? a.setAttribute('aria-current','true') : a.removeAttribute('aria-current'));
}), { rootMargin:'-40% 0px -55% 0px' });
$$('main section[id]').forEach(s => spy.observe(s));

/* ═══ Ticker ═══ */
(() => {
  const g = TICKER_ITEMS.map(t => `<span class="tk-t">${t}</span><i class="dm"></i>`).join('');
  const track = $('#tkTrack');
  if (track) track.innerHTML = `<div class="tk-g">${g}</div><div class="tk-g" aria-hidden="true">${g}</div>`;
})();

/* ═══ Image loader ═══ */
const wireImg = img => {
  const host = img.parentElement; if (!host) return;
  const done = () => { img.classList.add('ld','is-on'); host.classList.remove('skl'); };
  const fail = () => {
    host.classList.remove('skl');
    host.classList.add('img-err');
    img.style.display = 'none';
  };
  if (img.complete) { if (img.naturalWidth) done(); else fail(); return; }
  img.addEventListener('load', done, { once:true });
  img.addEventListener('error', fail, { once:true });
};

/* ═══ Reveal observer ═══ */
const revealIO = new IntersectionObserver(es => es.forEach(e => {
  if (e.isIntersecting) { e.target.classList.add('in'); revealIO.unobserve(e.target); }
}), { threshold:0, rootMargin:'0px 0px -70px 0px' });
$$('.rv').forEach(el => revealIO.observe(el));

/* ═══ Count-up ═══ */
const cio = new IntersectionObserver(es => es.forEach(e => {
  if (!e.isIntersecting) return;
  cio.unobserve(e.target);
  const el = e.target, end = +el.dataset.count, t0 = performance.now();
  const run = t => {
    const k = Math.min(1, (t - t0)/2000), ez = 1 - Math.pow(1 - k, 4);
    el.textContent = faNum(Math.round(ez * end));
    if (k < 1) RAF.add(run); else RAF.drop(run);
  };
  RAF.add(run);
}), { threshold:.5 });
$$('[data-count]').forEach(el => cio.observe(el));

/* ═══ Decode effect ═══ */
const GLYPHS = 'اُ◆·—ابپتثجچحخدذرزسشصطعغفقکگلمنوهی۰۱۲۳۴۵۶۷۸۹';
let decSeq = 0;
const decode = el => {
  const target = el.textContent, total = Math.max(14, target.length + 8);
  let f = 0;
  const key = 'dec:' + (++decSeq);
  TIMERS.set(() => {
    f++;
    const lock = Math.floor((f/total) * target.length);
    el.textContent = target.split('').map((ch, i) =>
      ch === ' ' || ch === '·' || ch === '—' ? ch : i < lock ? ch : GLYPHS[(Math.random()*GLYPHS.length)|0]
    ).join('');
    if (f >= total) { el.textContent = target; TIMERS.clear(key); }
  }, 26, key);
};
const decodeIO = new IntersectionObserver(es => es.forEach(e => {
  if (e.isIntersecting) { decode(e.target); decodeIO.unobserve(e.target); }
}), { threshold:.6 });
if (!reduced) $$('[data-decode]').forEach(el => decodeIO.observe(el));

/* ═══ Hero ═══ */
const heroStars = $('#heroStars'); if (heroStars) heroStars.innerHTML = window.AE.starsHTML(4.9);

/* ─── Hero title reveal — CSS transitions only ─────────────────────────────
   This used to branch on window.gsap with a timeline path and a CSS-transition
   fallback. GSAP was removed from the page (see the SCRIPTS note in index.php),
   so the gsap branch could never run; it is gone and the fallback is the
   implementation. The section-scroll reveals that lived next to it were also
   written against ScrollTrigger, which was never loaded even when GSAP was —
   they never animated anything and have been deleted with it. */
function initSplitReveal() {
  const h1 = $('#heroTitle'); if (!h1) return;
  const lines = $$('.split-line-inner', h1);
  if (!lines.length) return;
  const lh = parseFloat(getComputedStyle(h1).lineHeight);
  if (lh && !isNaN(lh)) h1.style.minHeight = (lh * 2) + 'px';
  if (reduced) return;
  lines.forEach((el, i) => {
    el.style.transform = 'translateY(115%)';
    el.style.transition = 'transform .9s cubic-bezier(.16,1,.3,1)';
    setTimeout(() => { el.style.transform = 'translateY(0)'; }, 300 + i*150);
  });
}
let heroParallaxBuilt = false;
/* ─── Hero parallax — vanilla rewrite ──────────────────────────────────────
   Two bugs in one line: this function targeted #heroVisual, an id that has
   never existed in index.php (the hero image is .hero-plate), and it gated on
   window.gsap/ScrollTrigger, which were removed from the page (see the note
   near the script block in index.php). The result: the whole function was
   dead code — no element matched AND no library loaded.

   Rewritten without GSAP. The old `scrub` values are reproduced as per-frame
   lerp factors (a scrub of n seconds ≈ smoothing over n*60 frames at 60fps),
   with the same displacement targets: plate slides up / shrinks / fades,
   ghost falls faster, seal turns half a revolution across the hero's exit.
   Transforms only (compositor-owned), stops when hidden, respects reduced
   motion and perf-low, and writes nothing the CSS does not already allow. */
function initHeroParallax() {
  if (heroParallaxBuilt || reduced || lowTier()) return;
  const hero = $('.hero');
  const plate = $('.hero-plate'), ghost = $('#heroGhost'), seal = $('.hero .seal');
  if (!hero || (!plate && !ghost && !seal)) return;
  heroParallaxBuilt = true;

  let cur = 0, target = 0, rafId = null;
  const frame = () => {
    /* lerp → رفتار scrub؛ آستانهٔ ۰.۳ پیکسل از لرزش sub-pixel جلوگیری می‌کند */
    cur += (target - cur) * 0.12;
    if (Math.abs(target - cur) < 0.3) cur = target;
    const p = Math.max(0, Math.min(1, -cur / (hero.offsetHeight || 1))); // 0..1 در حین خروج هیرو
    if (plate) {
      plate.style.transform = `translate3d(0,${(-60 * p).toFixed(2)}px,0) scale(${(1 - 0.06 * p).toFixed(4)})`;
      plate.style.opacity = String(1 - 0.25 * p);
    }
    if (ghost) ghost.style.transform = `translate3d(0,${(190 * p).toFixed(2)}px,0)`;
    if (seal)  seal.style.transform  = `rotate(${(180 * p).toFixed(2)}deg)`;
    rafId = (cur !== target && !document.hidden) ? requestAnimationFrame(frame) : null;
  };
  const kick = () => { if (!rafId && !document.hidden) rafId = requestAnimationFrame(frame); };
  addEventListener('scroll', () => { target = -window.scrollY; kick(); }, { passive: true });
  document.addEventListener('visibilitychange', kick);
  kick();
}
/* ═══ Index row ═══ */
const idxRow = $('#idxRow');
if (idxRow) idxRow.innerHTML = IDX_CATS.map((c, i) =>
  `<button data-act="idx" data-cat="${c}" type="button">
    <span class="idx-n">${faPad(i+1)}</span>
    <span class="idx-f">${FAM[c]}</span>
    <span class="idx-x">${faNum(PRODUCTS.filter(p => p.family === c).length)} فرم</span>
  </button>`
).join('');

/* ═══ Family chips ═══ */
function renderFamChips() {
  const counts = { all:PRODUCTS.length, wish:state.wish.length };
  PRODUCTS.forEach(p => counts[p.family] = (counts[p.family] || 0) + 1);
  const box = $('#famChips'); if (!box) return;
  box.innerHTML = Object.keys(FAM).map(k =>
    `<button class="chip" type="button" data-fam="${k}" aria-pressed="${k === state.fam}">${FAM[k]} <small>${faNum(counts[k] || 0)}</small></button>`
  ).join('');
}
renderFamChips();
$('#famChips') && $('#famChips').addEventListener('click', e => {
  const c = e.target.closest('.chip'); if (!c) return;
  state.fam = c.dataset.fam;
  renderFamChips();
  window.dispatchEvent(new CustomEvent('ae:apply'));
});

/* ═══ Theme / Scene / Mode ═══ */
const themeMenu = $('#themeMenu');
$('#themeT') && $('#themeT').addEventListener('click', e => { e.stopPropagation(); themeMenu.classList.toggle('on'); });
document.addEventListener('click', e => {
  if (!e.target.closest('#themeMenu') && !e.target.closest('#themeT')) themeMenu.classList.remove('on');
});
function setTheme(t) {
  if (t === html.getAttribute('data-theme')) return;
  html.setAttribute('data-theme', t);
  LS.set(K.theme, t);
  /* shader-hero.js رنگ‌های CSS را در شیدر کش کرده است؛ با هر تغییر پوسته
     باید دوباره خوانده شوند. رویداد جدید، بدون تغییر رفتار بقیهٔ مصرف‌کننده‌ها. */
  window.dispatchEvent(new CustomEvent('ae:theme-change', { detail: { theme: t } }));
  $$('[data-theme-set]').forEach(b => b.classList.toggle('on', b.dataset.themeSet === t));
  document.querySelector('meta[name=theme-color]')?.setAttribute('content', t === 'ivoire' ? '#F6F2E8' : '#08090F');
  /* The settle is a property animation on the document element, published by
     main.js and a no-op where the device cannot afford it. Called after the
     attribute is written, not before, so it animates the new palette. */
  window.AE_SETTLE?.(html);
}
$$('[data-theme-set]').forEach(b => b.addEventListener('click', () => { setTheme(b.dataset.themeSet); themeMenu.classList.remove('on'); }));
$$('[data-scene-set]').forEach(b => b.addEventListener('click', () => {
  const s = b.dataset.sceneSet;
  if (s === html.getAttribute('data-scene')) return;
  html.setAttribute('data-scene', s);
  LS.set(K.scene, s);
  $$('[data-scene-set]').forEach(x => x.classList.toggle('on', x.dataset.sceneSet === s));
  window.AE_SETTLE?.(html);
}));

function setMode(m) {
  if (m === html.getAttribute('data-mode')) return;
  html.setAttribute('data-mode', m);
  body.dataset.mode = m;
  LS.set(K.mode, m);
  $$('[data-act="mode"]').forEach(b => {
    const on = b.dataset.mode === m;
    b.classList.toggle('on', on);
    b.setAttribute('aria-selected', String(on));
  });
  const homeView = $('#view-home'), atView = $('#view-atelier');
  if (m === 'atelier') {
    homeView.hidden = true; homeView.setAttribute('inert', '');
    atView.hidden = false; atView.removeAttribute('inert');
    window.dispatchEvent(new CustomEvent('ae:render-atelier'));
    const lenis = window.AE.lenis();
    if (lenis) lenis.scrollTo(0, { immediate:true });
  } else {
    atView.hidden = true; atView.setAttribute('inert', '');
    homeView.hidden = false; homeView.removeAttribute('inert');
    const lenis = window.AE.lenis();
    if (lenis) lenis.scrollTo(0, { immediate:true });
  }
  window.AE_SETTLE?.(html);
  toast(m === 'atelier' ? 'آتلیه — پنل مدیریت کارگاه' : 'بوتیک — فروشگاه خانهٔ اُرِل');
 window.dispatchEvent(new CustomEvent('ae:mode-change', { detail: { mode: m } }));
}


/* ═══ Mobile nav ═══ */
const mnav = $('#mnav');
const menuBtns = $$('[data-act="menu"]');
function setMnav(on) {
  mnav.classList.toggle('on', on);
  mnav.toggleAttribute('inert', !on);
  body.classList.toggle('mnav-on', on);
  html.classList.toggle('lock', on);
  menuBtns.forEach(b => b.setAttribute('aria-expanded', String(!!on)));
  if (on) {
    dlStop();
    const first = $('a,button', mnav);
    first && first.focus({ preventScroll:true });
  } else dlStart();
}
menuBtns.forEach(b => b.setAttribute('aria-expanded', 'false'));
$$('a', mnav).forEach(a => a.addEventListener('click', () => setMnav(false)));

/* ═══ Scroll helper for filters ═══ */
function scrollToFilters(delay) {
  const run = () => {
    const tools = $('.vault__tools');
    const chromeH = (parseFloat(getComputedStyle(html).getPropertyValue('--nav-h')) || 72) +
                    (parseFloat(getComputedStyle(html).getPropertyValue('--ann')) || 0) + 28;
    const lenis = window.AE.lenis();
    if (lenis) {
      if (tools) lenis.scrollTo(tools, { offset: -chromeH, duration: 1.1 });
      else lenis.scrollTo('#boutique', { offset: -chromeH, duration: 1.1 });
    } else if (tools) {
      scrollTo({ top: scrollY + tools.getBoundingClientRect().top - chromeH,
                 behavior: reduced ? 'auto' : 'smooth' });
    } else scrollToEl('#boutique');
  };
  if (delay) TIMERS.once(run, delay, 'scroll:filters'); else run();
}

/* ═══ Exports (for main.js to wire) ═══ */
window.AE_UI = {
  wireImg, revealIO,
  renderFamChips, setTheme, setMode, setMnav, setMnavGet: () => body.classList.contains('mnav-on'),
  themeMenu, scrollToFilters, mnav, menuBtns
};
})();