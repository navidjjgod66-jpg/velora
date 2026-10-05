/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — cart.js
   Bag · Wishlist · Sheet · Fly-to-cart · Confirm pill
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, body, reduced, LS, K, fdate,
  faNum, faPad, moneyT, esc, toast, haptic, dlStop, wireDialog
} = window.AE;
const { CATALOG, SIZES, CONV, MAX_ORDERS } = window.AE_DATA;
const {
  state, persBag, persWish, capFor,
  itemsSum, discount, cartSum
} = window.AE_STATE;

/* ─── Default size for a one-tap add ──────────────────────────────────────
   The wishlist's quick-add used to submit a hard-coded '38'. That is a real
   bug with a real failure mode: the size band is a server constant (SIZE_BAND
   in config.php, enforced by api.php's INVALID_SIZE), and '38' happened to be
   inside today's 37–41 band. Move the band and every wishlist quick-add submits
   an out-of-range size.

   Worse, it ignored stock. Four products in the current catalogue are out of
   stock at size 41 and two of them at 38 — but more to the point, api.php
   throws INSUFFICIENT_STOCK from INSIDE the order transaction, so one
   out-of-stock line fails the ENTIRE order rather than that line. A customer
   who added two things from their wishlist would be refused the whole basket at
   the last request.

   So: pick from the product's own per-size stock when it has one, and only fall
   back to the middle of the house band when it does not. Returns null when the
   product has nothing in stock at any size, and the caller says so rather than
   adding a line the server will reject. */
function defaultSize(p) {
  if (!p) return null;
  const sizes = Array.isArray(p.sizes) ? p.sizes : [];
  const inStock = sizes
    .filter(s => s && Number(s.stock) > 0)
    .map(s => String(s.eu != null ? s.eu : s))
    .filter(Boolean);
  if (inStock.length) {
    /* The middle of what IS available, not of the band — so a product with one
       size left offers that one. */
    return inStock[Math.floor(inStock.length / 2)];
  }
  /* No per-size data: use the middle of the published band. */
  const band = Array.isArray(SIZES) && SIZES.length ? SIZES.map(String) : ['38'];
  if (Number(p.stock) <= 0) return null;
  return band[Math.floor(band.length / 2)];
}

/* ═══ Order record + checkout completion (single source of truth) ═══ */
function recordOrder(rec) {
  const orders = Array.isArray(LS.get(K.orders, [])) ? LS.get(K.orders, []) : [];
  if (!orders.some(o => o.ref === rec.ref)) {
    orders.unshift(rec);
    LS.set(K.orders, orders.slice(0, MAX_ORDERS));
  }
}
/* ترتیب ثابت و ایمن: خالی‌کردن state → persist → رندر → آپدیت بج.
   هر دو مسیر موفق checkout (محلی و بازگشت از درگاه) از همین‌جا عبور می‌کنند. */
function completeCheckout() {
  state.cart = [];
  persBag();
  renderBag();
  paintInBag();
  window.dispatchEvent(new CustomEvent('ae:apply'));
}

/* ═══ Bag ═══ */
const bag = $('#bag'), bagItems = $('#bagItems'), bagEmpty = $('#bagEmpty');
const bagN = $('#cartN'), dockN = $('#dockN'), bagCountD = $('#bagCountD'), bagLive = $('#bagLive');
const subTotal = $('#subTotal');
const etaLine = $('#etaLine');
if (etaLine) etaLine.textContent = `تحویل تقریبی ${fdate(Date.now() + 3 * 864e5)} · اکسپرس رایگان`;

function renderBag() {
  if (!bag) return;
  const count = state.cart.reduce((s, i) => s + i.qty, 0);
  bagN.textContent = faNum(count);
  bagN.classList.toggle('none', count === 0);
  if (dockN) { dockN.textContent = faNum(count); dockN.hidden = count === 0; }
  const mn = $('#mnavCartN'); if (mn) mn.textContent = faNum(count);
  bagCountD.textContent = faPad(count);
  if (bagLive) bagLive.textContent = `${faNum(count)} مورد در سبد`;
  bagEmpty.style.display = count ? 'none' : 'flex';
  /* Remember what had focus, so it can be put back.

     The list is rebuilt wholesale on every interaction — a quantity change, a
     removal, a promo — because the row markup carries the quantity in three
     places (the stepper's minus/plus disabled state, the <output>, and the line
     price), and reconciling those in place is more state than the whole row is
     worth. Rebuilding is the right call.

     What was wrong is what the rebuild did to focus: the button the customer had
     just pressed was destroyed, so focus fell to <body>. A keyboard customer
     pressing "+" three times then tabbing onward was tabbing from the top of the
     document, not from the bag. And the <output aria-live="polite"> that is the
     only thing announcing the new quantity was itself destroyed and recreated,
     which is not a reliable way to make a live region announce anything.

     So the focused row and control are identified before the rebuild and
     restored after. Restoring by *what the control is* rather than by its index
     means it survives the list also changing length underneath, which it does
     whenever a quantity step disables the "+" at the cap. */
  const act = document.activeElement;
  const focusRow = act && act.closest && act.closest('#bagItems .bag-item');
  const focusKey = focusRow ? focusRow.dataset.key : null;
  const focusQ = act && act.dataset && act.dataset.q ? act.dataset.q : null;
  const focusWasRemove = !!(act && act.dataset && 'x' in act.dataset);

  bagItems.style.display = count ? 'flex' : 'none';
  bagItems.innerHTML = state.cart.map(it => {
    const p = CATALOG[it.id]; if (!p) return '';
    const price = it.price || p.price, name = it.label || p.name;
    /* Every interpolated value is escaped, including the two that were not.

       `src="${p.img}"` was raw. p.img comes from IMG() in data.js, which is an
       allowlist — an https URL, a vetted storage path, or an encodeURIComponent'd
       data URI — so today it cannot break out of the attribute. But it is the
       only sink on the page whose safety depends entirely on a filter three files
       away, and `it.hex` likewise reached the DOM unescaped from a
       localStorage-persisted cart line. A cart line is attacker-influenceable the
       moment anything writes to storage under the same origin, so neither is a
       place where "it can't happen today" is a good enough reason.

       width/height are set because .bag-item is a flex row: without them the
       lazy image reserves nothing and the row jumps when it lands, which is the
       whole visible cost of an optimisation the image already gets. */
    return `<div class="bag-item" data-key="${esc(it.id + '|' + it.size + '|' + it.color + '|' + (it.price || 0))}">
      <img src="${esc(p.img)}" alt="${esc(name)}" loading="lazy" decoding="async" width="72" height="90">
      <div style="min-width:0"><div class="bag-item__name">${esc(name)}</div>
      <div class="bag-item__meta"><span class="bag-sw" style="--c:${esc(it.hex || '#c8a24a')}" aria-hidden="true"></span>${esc(it.sizeL || 'سایز ' + faNum(it.size))} · ${esc(it.color)}</div>
      <div class="stepper">
        <button type="button" data-q="-1" aria-label="کاهش" ${it.qty <= 1 ? 'disabled' : ''}>−</button>
        <output aria-live="polite">${faNum(it.qty)}</output>
        <button type="button" data-q="1" aria-label="افزایش" ${it.qty >= capFor(it.id) ? 'disabled' : ''}>+</button>
      </div></div>
      <div class="bag-item__end">
        <span class="bag-item__price">${moneyT(price * it.qty)}</span>
        <button class="bag-item__rm" data-x type="button" aria-label="حذف"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
      </div></div>`;
  }).join('');
  $$('#bagItems img').forEach(window.AE_UI.wireImg);

  /* Put focus back on the same control, if it still exists and is still
     focusable. A control that has just become disabled — "+" at the stock cap —
     cannot hold focus, and stealing it elsewhere would be worse than dropping
     it, so the row's own remaining control is used instead. */
  if (focusKey) {
    const row = bagItems.querySelector(`.bag-item[data-key="${CSS.escape(focusKey)}"]`);
    if (row) {
      const want = focusWasRemove
        ? row.querySelector('[data-x]')
        : row.querySelector(`[data-q="${focusQ}"]`);
      const target = (want && !want.disabled) ? want : row.querySelector('button:not([disabled])');
      target?.focus?.({ preventScroll: true });
    }
  }

  const sub = itemsSum(), disc = discount(), total = cartSum();
  subTotal.textContent = moneyT(sub);

  let discRow = $('#bagDiscRow');
  if (disc > 0 && state.promo) {
    if (!discRow) {
      discRow = document.createElement('div');
      discRow.className = 'bag__row';
      discRow.id = 'bagDiscRow';
      const l = document.createElement('span');
      l.className = 'bag__row-l';
      l.append('تخفیف · ');
      const code = document.createElement('b');
      code.className = 'accent-txt'; code.id = 'bagDiscCode';
      code.textContent = state.promo.code;
      l.appendChild(code);
      const v = document.createElement('span');
      v.className = 'bag__row-s bag__row-s--free';
      v.id = 'bagDiscV';
      discRow.append(l, v);
      const anchor = subTotal.closest('.bag__row');
      if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(discRow, anchor.nextSibling);
    }
    discRow.hidden = false;
    const dc = $('#bagDiscCode'); if (dc) dc.textContent = state.promo.code;
    const dv = $('#bagDiscV'); if (dv) dv.textContent = '−' + moneyT(disc);
  } else if (discRow) {
    discRow.hidden = true;
  }
}

function addToCart(id, size, sizeL, color, hex, price = 0, label = '', srcImg = null) {
  const p = CATALOG[id]; if (!p) return false;
  const k = state.cart.findIndex(i => i.id === id && i.size === size && i.color === color && (i.price || 0) === (price || 0));
  const cap = capFor(id);
  const current = k > -1 ? state.cart[k].qty : 0;
  if (current >= cap) {
    haptic('warn');
    toast(cap > 0 ? `فقط ${faNum(cap)} جفت از «${p.name}» در آتلیه مانده است.` : `«${p.name}» تمام شد.`, 'err');
    return false;
  }
  if (k > -1) state.cart[k].qty++;
  else state.cart.push({ id, size, sizeL, color, hex, qty:1, price, label });
  renderBag(); persBag(); paintInBag();
  bagN.classList.remove('pop'); void bagN.offsetWidth; bagN.classList.add('pop');
  if (srcImg) flyToCart(srcImg);
  return true;
}

/* Bag interactions */
bagItems && bagItems.addEventListener('click', e => {
  const q = e.target.closest('[data-q]'), x = e.target.closest('[data-x]');
  if (!q && !x) return;
  const parts = e.target.closest('.bag-item').dataset.key.split('|');
  const it = state.cart.find(i => i.id === parts[0] && i.size === parts[1] && i.color === parts[2] && (i.price || 0) === +parts[3]);
  if (!it) return;
  if (x) {
    const idx = state.cart.indexOf(it);
    state.cart.splice(idx, 1);
    renderBag(); persBag(); paintInBag();
    haptic('remove');
    toast(`${it.label || CATALOG[it.id].name} حذف شد.`, 'err', { label:'واگرد', fn:() => {
      state.cart.splice(Math.min(idx, state.cart.length), 0, it);
      renderBag(); persBag(); paintInBag();
    }});
  } else {
    const cap = capFor(it.id);
    const next = it.qty + +q.dataset.q;
    if (next > cap) {
      haptic('warn');
      toast(cap > 0 ? `سقف موجودی «${CATALOG[it.id].name}» ${faNum(cap)} جفت است.` : 'این فرم تمام شد.', 'err');
      renderBag(); return;
    }
    it.qty = next;
    if (it.qty < 1) state.cart = state.cart.filter(i => i !== it);
    renderBag(); persBag();
  }
});

function openBag() {
  if (!bag) return;
  bag._opener = document.activeElement;
  renderBag();
  /* Guarded. showModal() on an already-modal dialog throws InvalidStateError,
     and openBag() is reachable from the bag button, the empty-bag CTA, the
     confirm pill and the add-to-cart fly animation — several of which can fire
     while the drawer is already up. */
  if (!bag.open) bag.showModal();
  dlStop();
}
wireDialog(bag);

/* The drawer header's own ✕.

   #bagClose has been in the markup since the drawer was written and has never
   had a listener in any file. It carries an aria-label and is the control a
   keyboard or screen-reader customer reaches for first, and it silently did
   nothing — the backdrop and Escape still worked, which is why a dead
   visible control in the header of the primary conversion surface survived.

   A close affordance that is visible and inert is worse than no affordance: it
   advertises an exit that does not exist, and it is the first thing a customer
   tries when they change their mind. */
const bagClose = $('#bagClose');
if (bagClose && bag) {
  bagClose.addEventListener('click', () => {
    bag.close();
    /* wireDialog() restores focus to _opener on close, but only when the close
       came from a path it recognises. Doing it here as well is idempotent and
       means Escape and ✕ agree about where focus lands. */
    bag._opener?.focus?.({ preventScroll: true });
  });
}

/* ═══ Wishlist ═══ */
const wishN = $('#wishN'), wishd = $('#wishd'), wishItems = $('#wishItems'), wishEmpty = $('#wishEmpty');
const wishCountD = $('#wishCountD'), wishAll = $('#wishAll');
let wishFocus = null;

function paintWish() {
  wishN.textContent = faNum(state.wish.length);
  wishN.classList.toggle('none', state.wish.length === 0);
  $$('.wish').forEach(w => {
    const prod = w.closest('.prod'); if (!prod) return;
    const on = state.wish.includes(prod.dataset.id);
    w.classList.toggle('on', on);
    w.setAttribute('aria-pressed', String(on));
  });
}
function toggleWish(id) {
  const on = !state.wish.includes(id);
  haptic(on ? 'add' : 'remove');
  if (on) state.wish.push(id); else state.wish = state.wish.filter(x => x !== id);
  persWish();
  window.dispatchEvent(new CustomEvent('ae:render-fam'));
  paintWish(); renderWishDrawer();
  window.dispatchEvent(new CustomEvent('ae:apply'));
  wishN.classList.remove('pop'); void wishN.offsetWidth; wishN.classList.add('pop');
  toast(on ? `${CATALOG[id].name} — به علاقه‌مندی‌ها اضافه شد.` : `${CATALOG[id].name} — حذف شد.`);
}
function renderWishDrawer() {
  if (!wishd) return;
  wishCountD.textContent = faPad(state.wish.length);
  wishEmpty.style.display = state.wish.length ? 'none' : 'flex';
  wishItems.style.display = state.wish.length ? 'flex' : 'none';
  wishAll.disabled = state.wish.length === 0;
  wishItems.innerHTML = state.wish.map(id => {
    const p = CATALOG[id]; if (!p) return '';
    return `<div class="bag-item" data-id="${p.id}">
      <img src="${p.img}" alt="${esc(p.name)}" loading="lazy" decoding="async">
      <div style="min-width:0"><div class="bag-item__name">${esc(p.name)}</div>
      <div class="bag-item__meta">${esc(p.cat)}</div>
      <div class="bag-item__price">${moneyT(p.price)}</div></div>
      <div class="bag-item__end">
        <button class="bag-item__rm" data-wrm type="button" aria-label="حذف"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        <button class="chip" data-wadd type="button">افزودن · سایز ۳۸</button>
      </div></div>`;
  }).join('');
  $$('#wishItems img').forEach(window.AE_UI.wireImg);
}
wishItems && wishItems.addEventListener('click', e => {
  const x = e.target.closest('[data-wrm]'), add = e.target.closest('[data-wadd]');
  if (!x && !add) return;
  const id = e.target.closest('.bag-item').dataset.id;
  if (x) {
    const idx = state.wish.indexOf(id);
    state.wish.splice(idx, 1); persWish(); haptic('remove');
    renderWishDrawer(); paintWish();
    window.dispatchEvent(new CustomEvent('ae:render-fam'));
    window.dispatchEvent(new CustomEvent('ae:apply'));
    toast(`${CATALOG[id].name} حذف شد.`, 'err', { label:'واگرد', fn:() => {
      state.wish.splice(idx, 0, id); persWish();
      renderWishDrawer(); paintWish();
      window.dispatchEvent(new CustomEvent('ae:render-fam'));
      window.dispatchEvent(new CustomEvent('ae:apply'));
    }});
  }
  if (add) {
    const p = CATALOG[id];
    const sz = defaultSize(p);
    if (!sz) return;
    const sw0 = (p.sw && p.sw[0]) || { n: 'مشکی', c: '#161310' };
    if (!addToCart(id, sz, 'سایز ' + faNum(sz), sw0.n, sw0.c)) return;
    haptic('add');
    showConfirmPill('به سبد اضافه شد · سایز ' + faNum(sz));
    toast(`${p.name} · سایز ${faNum(sz)} — به سبد اضافه شد.`);
  }
});
wishAll && wishAll.addEventListener('click', () => {
  let added = 0, skipped = 0;
  for (const id of state.wish) {
    const p = CATALOG[id];
    const sz = defaultSize(p);
    const sw0 = (p.sw && p.sw[0]) || { n: 'مشکی', c: '#161310' };
    if (sz && addToCart(id, sz, 'سایز ' + faNum(sz), sw0.n, sw0.c)) added++;
    else skipped++;
  }
  toast(skipped
    ? `${faNum(added)} فرم به سبد پیوست، ${faNum(skipped)} فرم به دلیل نبود سایز موجود رد شد.`
    : 'علاقه‌مندی‌ها به سبد پیوستند.');
});
function openWish() {
  wishFocus = document.activeElement;
  renderWishDrawer();
  wishd.showModal();
  dlStop();
}
$('#wishClose') && $('#wishClose').addEventListener('click', () => wishd.close());
$('#wishEmptyShop') && $('#wishEmptyShop').addEventListener('click', () => wishd.close());
if (wishd) {
  wireDialog(wishd);
  wishd.addEventListener('close', () => {
    wishFocus && wishFocus.focus && wishFocus.focus({ preventScroll:true });
    wishFocus = null;
  });
}

/* ═══ Fly to cart ═══ */
function flyToCart(srcImg) {
  if (reduced || !srcImg || !srcImg.getBoundingClientRect) return;
  const target = ($('#bagBtn') && $('#bagBtn').offsetParent !== null ? $('#bagBtn') : null) ||
                 ($('#dockBag') && $('#dockBag').offsetParent !== null ? $('#dockBag') : null);
  if (!target) return;
  const s = srcImg.getBoundingClientRect(), t = target.getBoundingClientRect();
  if (!s.width || !s.height || !t.width) return;
  const particle = document.createElement('div');
  particle.className = 'fly-particle';
  particle.style.left = s.left + s.width/2 + 'px';
  particle.style.top  = s.top  + s.height/2 + 'px';
  document.body.appendChild(particle);
  const dx = (t.left + t.width/2) - (s.left + s.width/2);
  const dy = (t.top  + t.height/2) - (s.top  + s.height/2);
  particle.animate([
    { transform:'translate3d(0,0,0) scale(1)', opacity:1 },
    { transform:`translate3d(${dx.toFixed(1)}px,${dy.toFixed(1)}px,0) scale(.28)`, opacity:0 }
  ], { duration: 720, easing:'cubic-bezier(.4,0,.2,1)' }).onfinish = () => particle.remove();
}

/* ═══ Confirm pill (thumb feedback) ═══ */
let confirmPill = null, confirmT = null;
function showConfirmPill(text) {
  if (reduced) return;
  const clear = () => { clearTimeout(confirmT); confirmT = null; if (confirmPill) { confirmPill.remove(); confirmPill = null; } };
  clear();
  const dock = document.getElementById('dock');
  let dockH = 0;
  if (dock) {
    const r = dock.getBoundingClientRect();
    const cs = getComputedStyle(dock);
    if (cs.display !== 'none' && cs.visibility !== 'hidden' &&
        r.height > 0 && !dock.classList.contains('is-hidden')) {
      dockH = r.height + 12;
    }
  }
  const p = document.createElement('div');
  p.className = 'confirm-pill';
  p.setAttribute('role', 'status');
  const ic = document.createElement('i');
  ic.setAttribute('aria-hidden', 'true');
  const tx = document.createElement('span');
  tx.textContent = text;
  p.append(ic, tx);
  p.style.setProperty('--pill-bottom', `calc(${dockH + 16}px + env(safe-area-inset-bottom, 0px))`);
  body.appendChild(p);
  confirmPill = p;
  confirmT = setTimeout(clear, 2400);
}

/* ═══ In-bag state on cards ═══ */
function paintInBag() {
  const inBag = new Set(state.cart.map(i => i.id));
  $$('.prod').forEach(c => {
    const on = inBag.has(c.dataset.id);
    c.classList.toggle('inbag', on);
    /* The quick-buy button lives inside .p-acts beside "مشاهده". Its label is
       written with textContent on the inner span only — never the whole
       button — so the lightning icon survives every re-paint. */
    const t = $('.quick.qs .quick-t', c);
    if (t) t.textContent = on ? 'در سبد ✓' : 'خرید سریع';
    /* The button itself carries the state class so CSS can gild it. */
    const q = $('.quick.qs', c);
    if (q) q.classList.toggle('in-bag', on);
  });
}

/* ═══ Quick-buy sheet — size AND colour, one professional surface ═══
   The fast path off a product card: pick a colour, pick a size, it is in the
   bag. Everything here has to agree with the PDP, because this is the other
   way a customer can reach add-to-cart and there is no second source of truth
   for a size list or a colour list.

   Four things were wrong once and they compounded; the fixes still hold:

   1. It read `p.sw[0].n` unguarded. `sw` is empty for any product that
      arrives from the catalogue sync rather than from adaptServerProduct(),
      so the sheet threw a TypeError on the first size a customer tapped.
      Every read below goes through a guarded default.

   2. It listed the whole house band and let every size be tapped, ignoring
      stock. The PDP disables a size with nothing in it; the sheet must too —
      api.php fails the WHOLE order on one out-of-stock line, so an offered
      size the server refuses costs the customer the entire basket.

   3. The foot-length hint appeared on `mouseover` only. Now `focusin` +
      `pointerover`, so keyboard and touch get the guidance as well.

   4. One listener per size button, rebuilt on every open. Everything below
      is delegated once, on the containers that now live permanently in the
      sheet markup (index.php), and selection state lives in qsPick.

   What is new: the colour rail. A quick-buy that silently added "the first
   colour" was a shortcut that guessed — two swatches look identical in the
   bag line until the order email says otherwise. The sheet now shows the
   same swatches the PDP shows, requires an explicit choice alongside the
   size, and the add button stays disabled until both are made. */
const sheet = $('#sheet'), sheetSizes = $('#sheetSizes'), sheetConv = $('#sheetConv'), sheetT = $('#sheetT');
const sheetProd = $('#sheetProd'), sheetImg = $('#sheetImg'), sheetName = $('#sheetName');
const sheetSub = $('#sheetSub'), sheetPrice = $('#sheetPrice');
const sheetColorWrap = $('#sheetColorWrap'), sheetColors = $('#sheetColors'), sheetColorName = $('#sheetColorName');
const sheetAdd = $('#sheetAdd'), sheetAddT = $('#sheetAddT'), sheetMore = $('#sheetMore');
let sheetId = null;
/* The one piece of transient state the sheet owns: what has been picked.
   Reset on every open so a stale size from another product can never ride
   into the next add. */
const qsPick = { size: null, color: -1 };

/* Stock for one size, or null when the product publishes no per-size stock at
   all — which is different from zero, and the difference decides whether the
   size can be offered. */
function stockOfSize(p, size) {
  const sizes = Array.isArray(p && p.sizes) ? p.sizes : [];
  const row = sizes.find(s => s && String(s.eu != null ? s.eu : s) === String(size));
  if (!row) return null;
  return Math.max(0, Number(row.stock) || 0);
}

/* Swatch rows for the sheet, normalised from either shape the catalogue can
   arrive in: demo PRODUCTS carry {n,c,img}; adaptServerProduct builds sw[]
   from colors[]. Never indexed unguarded. */
function swOf(p) {
  const sw = Array.isArray(p && p.sw) ? p.sw : [];
  return sw.filter(s => s && (s.n || s.c));
}

function qsSyncAddState() {
  if (!sheetAdd) return;
  const p = CATALOG[sheetId];
  if (!p) return;
  const sw = swOf(p);
  const ready = qsPick.size !== null && (sw.length === 0 || qsPick.color >= 0);
  sheetAdd.disabled = !ready;
  if (sheetAddT) {
    sheetAddT.textContent = ready
      ? 'افزودن به سبد · سایز ' + faNum(qsPick.size)
      : (qsPick.size === null ? 'ابتدا سایز را انتخاب کنید' : 'رنگ را انتخاب کنید');
  }
}

function openSheet(id) {
  if (!sheet || !sheetSizes) return;
  const p = CATALOG[id];
  if (!p) return;
  sheetId = id;
  qsPick.size = null;
  qsPick.color = -1;

  /* Header block: thumb, name, category line, price. textContent everywhere —
     nothing from the catalogue reaches innerHTML. */
  if (sheetProd && sheetImg && sheetName && sheetSub && sheetPrice) {
    sheetProd.hidden = false;
    sheetImg.src = p.img || '';
    sheetImg.alt = p.name;
    sheetName.textContent = p.name;
    sheetSub.textContent = p.cat || p.sub || '';
    sheetPrice.textContent = moneyT(p.price);
  }
  if (sheetMore) sheetMore.href = '?product=' + encodeURIComponent(id);

  /* Colour rail — only when the product actually carries colours. */
  const sw = swOf(p);
  if (sheetColorWrap && sheetColors) {
    if (sw.length > 0) {
      sheetColorWrap.hidden = false;
      sheetColors.innerHTML = sw.map((s, i) =>
        '<button class="sw qs-color" type="button" role="radio" aria-checked="false"'
        + ' style="--c:' + esc(String(s.c || '#c8a24a')) + '"'
        + ' data-ci="' + i + '" title="' + esc(String(s.n || '')) + '"'
        + ' aria-label="' + esc('رنگ ' + (s.n || '')) + '"></button>'
      ).join('');
      /* A single colour is pre-picked visibly — the customer still sees what
         they are buying, but is not asked to choose between one option. */
      if (sw.length === 1) {
        qsPick.color = 0;
        const b0 = sheetColors.firstElementChild;
        if (b0) { b0.setAttribute('aria-checked', 'true'); b0.classList.add('sel'); }
        if (sheetColorName) sheetColorName.textContent = '· ' + (sw[0].n || '');
      } else if (sheetColorName) {
        sheetColorName.textContent = '';
      }
    } else {
      sheetColorWrap.hidden = true;
      sheetColors.innerHTML = '';
      qsPick.color = 0; /* nothing to choose — treat as chosen */
    }
  }

  const hasStockRows = Array.isArray(p.sizes) && p.sizes.length > 0;
  const inStockCount = hasStockRows
    ? SIZES.filter(s => (stockOfSize(p, s) || 0) > 0).length
    : SIZES.length;

  sheetT.textContent = 'خرید سریع · ' + p.name;

  /* If nothing at all is available, say so instead of presenting a rail the
     customer can pick from and be refused. */
  if (hasStockRows && inStockCount === 0) {
    sheetSizes.innerHTML = '<p class="sheet-empty">در حال حاضر موجود نیست.</p>';
    sheetConv.textContent = '';
    if (sheetAdd) sheetAdd.disabled = true;
    sheet._opener = document.activeElement;
    if (!sheet.open) sheet.showModal();
    dlStop();
    return;
  }

  sheetSizes.innerHTML = SIZES.map(s => {
    const st = stockOfSize(p, s);
    const soldOut = st !== null && st <= 0;
    /* The depth is the useful half of the information. The sheet already
       refuses a size with nothing left — but between "available" and "not",
       "two pairs left" is what a customer deciding right now actually wants,
       and it is the number the atelier builds against. */
    const scarce = st !== null && st > 0 && st <= 4;
    return '<button class="size' + (soldOut ? ' is-out' : '') + '" type="button" role="radio"'
         + ' aria-checked="false"' + (soldOut ? ' aria-disabled="true" disabled' : '')
         + ' data-s="' + s + '"'
         + (st !== null ? ' data-stock="' + st + '"' + (scarce ? ' data-scarce="1"' : '') : '')
         + '>' + faNum(s)
         + (st !== null ? '<span class="siz-stock">' + (soldOut ? '—' : faNum(st) + ' عدد') + '</span>' : '')
         + '</button>';
  }).join('');
  sheetConv.textContent = '';
  qsSyncAddState();

  sheet._opener = document.activeElement;
  if (!sheet.open) sheet.showModal();
  dlStop();
  /* Move focus to the first control that can actually be chosen — the colour
     rail when there is more than one to choose, otherwise the first live
     size — so the keyboard path starts inside the group instead of on the
     dialog. */
  const first = (sheetColors && !sheetColorWrap.hidden && qsPick.color < 0)
    ? sheetColors.querySelector('.qs-color:not([disabled])')
    : sheetSizes.querySelector('.size:not([disabled])');
  if (first) first.focus({ preventScroll: true });
}

/* Commit whatever is currently selected. Both rails write through here so a
   tap on a size with the colour already settled (or vice-versa) completes the
   purchase in the same single gesture the old rail took — while a product
   with a real colour choice asks for it explicitly before anything lands in
   the bag. */
function qsCommit() {
  const p = CATALOG[sheetId];
  if (!p || !sheetAdd || sheetAdd.disabled) return;
  const sw = swOf(p);
  const col = sw.length > 0 ? sw[Math.max(0, qsPick.color)] : { n: 'مشکی', c: '#161310' };
  const sel = '.prod[data-id="' + (window.CSS && CSS.escape ? CSS.escape(sheetId) : sheetId) + '"] .prod-media img';
  const media = document.querySelector(sel);
  const ok = addToCart(sheetId, qsPick.size, 'سایز ' + faNum(qsPick.size), col.n || 'مشکی', col.c || '#161310', 0, '', media || null);
  sheet.close();
  if (ok) {
    haptic('add');
    showConfirmPill(`به سبد اضافه شد · ${col.n ? col.n + ' · ' : ''}سایز ${faNum(qsPick.size)}`);
    toast(`${p.name} · ${col.n ? col.n + ' · ' : ''}سایز ${faNum(qsPick.size)} — به سبد اضافه شد.`);
  }
}

/* One set of delegated listeners for the whole sheet, registered once. A
   rebuild of either rail can therefore never orphan a handler, and the
   selection is read from the event target at the moment of the click rather
   than captured. */
if (sheetSizes) {
  sheetSizes.addEventListener('click', e => {
    const b = e.target.closest('.size');
    if (!b || b.disabled) return;
    const p = CATALOG[sheetId];
    if (!p) return;
    qsPick.size = b.dataset.s;
    $$('.size', sheetSizes).forEach(x => x.setAttribute('aria-checked', String(x === b)));
    const sw = swOf(p);
    if (sw.length === 0 || qsPick.color >= 0) qsCommit();
    else qsSyncAddState();
  });

  /* The foot-length hint, on both the paths that can reach a size. `focusin`
     covers keyboard tabbing, `pointerover` covers a mouse, and pointer events
     are not emitted for a plain tap until after the fact — so this is the
     union of the two rather than one or the other. */
  const showConv = e => {
    const b = e.target.closest('.size');
    if (!b) { sheetConv.textContent = ''; return; }
    const st = b.dataset.stock;
    const hint = CONV[b.dataset.s] || '';
    sheetConv.textContent = hint + (st !== undefined ? ` · ${faNum(st)} عدد موجود` : '');
  };
  sheetSizes.addEventListener('focusin', showConv);
  sheetSizes.addEventListener('pointerover', showConv);
  sheetSizes.addEventListener('pointerleave', () => { sheetConv.textContent = ''; });
}

if (sheetColors) {
  sheetColors.addEventListener('click', e => {
    const b = e.target.closest('.qs-color');
    if (!b) return;
    const p = CATALOG[sheetId];
    if (!p) return;
    const sw = swOf(p);
    const i = Number(b.dataset.ci);
    if (!(i >= 0) || i >= sw.length) return;
    qsPick.color = i;
    $$('.qs-color', sheetColors).forEach(x => {
      const on = x === b;
      x.setAttribute('aria-checked', String(on));
      x.classList.toggle('sel', on);
    });
    if (sheetColorName) sheetColorName.textContent = '· ' + (sw[i].n || '');
    /* Colour picked and a size already chosen → commit, symmetric to above. */
    if (qsPick.size !== null) qsCommit();
    else qsSyncAddState();
  });
}

if (sheetAdd) sheetAdd.addEventListener('click', qsCommit);
$('#sheetX') && $('#sheetX').addEventListener('click', () => sheet && sheet.close());
sheet && wireDialog(sheet);

/* ═══ Initial render ═══ */
renderBag();
paintWish();
renderWishDrawer();
paintInBag();

/* ═══ Exports ═══ */
window.AE_CART = {
  renderBag, addToCart, paintInBag,
  openBag, openWish, toggleWish, paintWish, renderWishDrawer,
  openSheet, showConfirmPill, flyToCart,
  recordOrder, completeCheckout
};

/* Listen for updates from other modules */
addEventListener('ae:render-bag', renderBag);
addEventListener('ae:render-wish', paintWish);
addEventListener('ae:apply', paintInBag);
})();