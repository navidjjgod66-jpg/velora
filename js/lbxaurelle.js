/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · lbxaurelle.js
   نمایشگر تمام‌صفحهٔ گالری — the full-bleed gallery viewer

   ─── چرا این فایل وجود دارد ───────────────────────────────────────────
   #lbx، دکمهٔ بستنش و تمام CSS آن (‎.lbx‎, ‎.lbx__nav‎, ‎.lbx__prev‎,
   ‎.lbx__next‎, ‎.lbx__bar‎ و انیمیشن lbxIn) از قبل نوشته و منتشر شده بودند،
   اما هیچ‌جا آن را باز نمی‌کرد و ‎#lbxX‎ در هیچ فایلی شنونده نداشت.
   استایل‌ها منتظر همین المان بودند.

   یک مزون اول عکس را می‌فروشد و بعد کفش را، و چیدمان این سند دقیقاً برای
   همین ساخته شده که محصول روی مجموعه باز شود نه در صفحهٔ خودش — پس بزرگ‌ترین
   نمای ممکنِ عکس، همان تعاملی است که کل چیدمان برایش جا باز کرده.

   ─── قرارداد ──────────────────────────────────────────────────────────
   یک ماژول، یک پنجره، بدون حالت دائمی. open(src, alt, list, index) هر بار
   از نو شروع می‌کند؛ close() همیشه به همان عنصری برمی‌گرداند که بازش کرده بود.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
'use strict';
const { $, faNum, dlStop, dlStart } = window.AE;

const dlg  = $('#lbx');
const img  = $('#lbxImg');
const btnX = $('#lbxX');
const prev = $('#lbxPrev');
const next = $('#lbxNext');
const bar  = $('#lbxBar');
const cnt  = $('#lbxCount');
const nm   = $('#lbxName');

if (!dlg || !img) return;

/* RTL note: the maison is dir="rtl", and the CSS positions .lbx__prev with
   inset-inline-start. "Previous" in a right-to-left gallery is on the RIGHT,
   which inset-inline-start resolves to correctly — so the buttons are placed
   by the stylesheet and the only thing this module decides is which one each
   button *does*. Nothing here needs to know the direction. */
let list = [];
let at = 0;
let title = '';
let opener = null;

function paint() {
  const src = list[at];
  if (!src) return;
  /* The entrance animation is keyed to the element, and it is `both`, so
     replaying it is a matter of removing the class the animation runs on and
     forcing one layout read to restart it. The image is swapped in the same
     frame, so the fade lands on the new photograph rather than the old one. */
  img.style.animation = 'none';
  void img.offsetWidth;
  img.style.animation = '';
  img.src = src;
  img.alt = title ? `${title} — نمای ${faNum(at + 1)}` : `نمای ${faNum(at + 1)}`;

  const many = list.length > 1;
  if (prev) prev.hidden = !many;
  if (next) next.hidden = !many;
  if (bar) bar.hidden = false;
  if (cnt) cnt.textContent = many ? `${faNum(at + 1)} / ${faNum(list.length)}` : '';
  if (nm) nm.textContent = many ? title : '';
}

function step(d) {
  if (list.length < 2) return;
  at = (at + d + list.length) % list.length;
  paint();
  /* Warm the neighbour. A gallery you have to wait for is a slideshow; one
     that is already decoded is a browser. Two `new Image()` objects per move
     with no reference kept is the cheapest way to get there — the browser
     keeps the decoded entry in its own cache, so this is a hint, not a leak. */
  const near = list[(at + 1) % list.length];
  if (near) { const w = new Image(); w.src = near; }
  const far = list[(at - 1 + list.length) % list.length];
  if (far) { const w = new Image(); w.src = far; }
}

function open(src, alt, gallery, index, name) {
  if (!src) return;
  list = Array.isArray(gallery) && gallery.length ? gallery.filter(Boolean) : [src];
  at = Math.max(0, Math.min(Number(index) || 0, list.length - 1));
  title = name || alt || '';
  opener = document.activeElement;
  dlStop();
  paint();
  if (!dlg.open) dlg.showModal();
  /* Focus the close button, not the image: it is the only control that is
     always present, and a keyboard customer arriving at a full-bleed image
     should be able to leave without first finding the arrow keys. */
  (btnX || dlg).focus?.({ preventScroll: true });
}

function close() {
  dlg.close();
}

if (btnX) btnX.addEventListener('click', close);
if (prev) prev.addEventListener('click', () => step(-1));
if (next) next.addEventListener('click', () => step(1));

/* Keyboard. Arrow keys and Home/End on the dialog itself, not on document, so
   the handler's lifetime is the dialog's and it cannot answer keys aimed at
   some other surface. RTL is handled by the browser: ArrowLeft/ArrowRight are
   physical keys, so "left" is always the previous image regardless of
   direction, which is what a customer pressing the left arrow key expects. */
dlg.addEventListener('keydown', e => {
  if (list.length < 2) return;
  switch (e.key) {
    case 'ArrowRight': step(1); break;
    case 'ArrowLeft':  step(-1); break;
    case 'Home':       at = 0; paint(); break;
    case 'End':        at = list.length - 1; paint(); break;
    default: return;
  }
  e.preventDefault();
});

/* Click the backdrop to dismiss — but not the image, and not the bar. The
   dialog is a grid with the image centred, so a click that lands on neither the
   image nor a control is by definition on the backdrop. Checking the target
   rather than offset-testing keeps this correct at any viewport size and under
   RTL, where "outside" is not "to the left". */
dlg.addEventListener('click', e => {
  if (e.target === dlg) close();
});

/* Touch: a horizontal swipe advances, and only past a threshold that a tap
   cannot produce, so a tap to dismiss still dismisses. `pointerdown` is
   passive because nothing here can preventDefault — the browser's own scroll
   decision must not be blocked. */
let downX = 0, downY = 0, tracking = false;
dlg.addEventListener('pointerdown', e => {
  if (e.pointerType === 'mouse' || list.length < 2) { tracking = false; return; }
  downX = e.clientX; downY = e.clientY; tracking = true;
}, { passive: true });
dlg.addEventListener('pointerup', e => {
  if (!tracking) return;
  tracking = false;
  const dx = e.clientX - downX, dy = e.clientY - downY;
  /* Horizontal intent: 48px of travel, and at least twice as much sideways as
     vertical, or a diagonal scroll-and-release becomes a gallery jump. */
  if (Math.abs(dx) < 48 || Math.abs(dx) < Math.abs(dy) * 2) return;
  step(dx > 0 ? -1 : 1);
}, { passive: true });

/* Focus is restored on the frame AFTER close, not inside the handler.

   A modal dialog's own focus restoration runs as part of the close
   bookkeeping, and doing it synchronously from the `close` listener loses the
   race: the observed result was focus landing on <body> every time, so a
   keyboard customer who opened the gallery with Enter and dismissed it with
   Escape was dropped at the top of the document instead of back on the image
   they came from. One frame is enough to be last, and it is the same reason
   wireDialog() in core.js defers nothing but the maison's own teardown does. */
dlg.addEventListener('close', () => {
  const back = opener;
  dlStart();
  opener = null;
  list = [];
  requestAnimationFrame(() => back?.focus?.({ preventScroll: true }));
});

window.AE_LBX = { open, close, step, isOpen: () => dlg.open };

/* ═══ Cinema entry (ویژگی ۶) — یک دکمه در نوار lightbox، منطق در main.js ═══
   این تنها چیزی است که lbx به سینما می‌دهد: لیست و موقعیت جاری را با یک
   رویداد منتقل می‌کند. main.js #cinema را باز/بسته و autoplay می‌کند.
   هیچ DOM سروری بازنویسی نمی‌شود؛ listener روی btnX/bar اضافه نمی‌شود. */
(() => {
  if (!bar) return;
  const b = document.createElement('button');
  b.className = 'icon-btn lbx__cinema'; b.id = 'lbxCinema'; b.type = 'button';
  b.setAttribute('aria-label', 'حالت سینمایی');
  b.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 6V4M17 6V4M3 10h18"/></svg>';
  b.addEventListener('click', () => {
    window.dispatchEvent(new CustomEvent('ae:cinema-open', {
      detail: { list: (typeof list !== 'undefined' ? list.slice() : []), at: (typeof at !== 'undefined' ? at : 0), name: (typeof title !== 'undefined' ? title : '') }
    }));
  });
  bar.append(b);
})();
})();
