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
const subTotal = $('#subTotal'), vatLine = $('#vatLine');
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
  bagItems.style.display = count ? 'flex' : 'none';
  bagItems.innerHTML = state.cart.map(it => {
    const p = CATALOG[it.id]; if (!p) return '';
    const price = it.price || p.price, name = it.label || p.name;
    return `<div class="bag-item" data-key="${esc(it.id + '|' + it.size + '|' + it.color + '|' + (it.price || 0))}">
      <img src="${p.img}" alt="${esc(name)}" loading="lazy" decoding="async">
      <div style="min-width:0"><div class="bag-item__name">${esc(name)}</div>
      <div class="bag-item__meta"><span class="bag-sw" style="--c:${it.hex || '#c8a24a'}" aria-hidden="true"></span>${esc(it.sizeL || 'سایز ' + faNum(it.size))} · ${esc(it.color)}</div>
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
  vatLine.textContent = moneyT(Math.round(total - total / 1.2));
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
  bag._opener = document.activeElement;
  renderBag();
  bag.showModal();
  dlStop();
}
wireDialog(bag);

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
    const ok = addToCart(id, '38', 'سایز ۳۸', p.sw[0].n, p.sw[0].c);
    if (!ok) return;
    haptic('add');
    showConfirmPill('به سبد اضافه شد · سایز ۳۸');
    toast(`${p.name} · سایز ۳۸ — به سبد اضافه شد.`);
  }
});
wishAll && wishAll.addEventListener('click', () => {
  state.wish.forEach(id => {
    const p = CATALOG[id];
    addToCart(id, '38', 'سایز ۳۸', p.sw[0].n, p.sw[0].c);
  });
  toast('علاقه‌مندی‌ها به سبد پیوستند.');
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
    const t = $('.quick', c); if (t) t.textContent = on ? 'در سبد ✓' : 'انتخاب سایز';
  });
}

/* ═══ Quick-size sheet ═══ */
const sheet = $('#sheet'), sheetSizes = $('#sheetSizes'), sheetConv = $('#sheetConv'), sheetT = $('#sheetT');
let sheetId = null;
function openSheet(id) {
  sheetId = id;
  const p = CATALOG[id]; if (!p) return;
  sheetT.textContent = p.name + ' · انتخاب سایز';
  sheetSizes.setAttribute('role', 'radiogroup');
  sheetSizes.innerHTML = SIZES.map(s => `<button class="size" type="button" role="radio" aria-checked="false" data-s="${s}">${faNum(s)}</button>`).join('');
  $$('.size', sheetSizes).forEach(b => b.addEventListener('click', () => {
    const ok = addToCart(sheetId, b.dataset.s, 'سایز ' + faNum(b.dataset.s), p.sw[0].n, p.sw[0].c);
    sheet.close();
    if (ok) {
      haptic('add');
      showConfirmPill(`به سبد اضافه شد · سایز ${faNum(b.dataset.s)}`);
      toast(`${p.name} · سایز ${faNum(b.dataset.s)} — به سبد اضافه شد.`);
    }
  }));
  sheetConv.textContent = '';
  sheet._opener = document.activeElement;
  sheet.showModal();
  dlStop();
}
sheetSizes && sheetSizes.addEventListener('mouseover', e => {
  const b = e.target.closest('.size');
  sheetConv.textContent = b ? CONV[b.dataset.s] || '' : '';
});
$('#sheetX') && $('#sheetX').addEventListener('click', () => sheet.close());
wireDialog(sheet);

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