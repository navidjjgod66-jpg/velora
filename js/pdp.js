/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — pdp.js
   Product Detail Page · Lightbox · Passport · Reviews · Custom size
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, clamp, reduced, fine, RAF, TIMERS, LS, K,
  faNum, faPad, moneyT, esc, PHI, PHI2, memoize, wait, toast, haptic,
  dlStop, dlStart, starsHTML, backdropClose
} = window.AE;
const { CATALOG, PRODUCTS, SIZES, CONV, ARTISANS, CARE } = window.AE_DATA;
const { state, getCustom, persBag } = window.AE_STATE;
const { wireImg } = window.AE_UI;
const { renderBag, paintInBag } = window.AE_CART;

/* ═══ Element refs ═══ */
const pdp = $('#pdp'), pdpImg = $('#pdpImg'), pdpStage = $('#pdpStage'), pdpSticky = $('#pdpSticky'), psPrice = $('#psPrice');
const DOC_TITLE = document.title;
let pdpViews = [], pdpViewIdx = 0;

/* ═══ Material / construction copy ═══ */
const matFor = p => {
  const c = (p.sw[0].n || '').toLowerCase();
  const m = c.includes('ساتن') || c.includes('ابریشم')
    ? 'دوشس ابریشم بافته‌شده در کومو'
    : c.includes('نپا')
    ? 'نپای نیمه‌شب نرم‌شده در دهنه'
    : c.includes('برنز')
    ? 'چرم گوسالهٔ متالیک شامپاینی'
    : 'چرم گوسالهٔ ایتالیایی از دو دباغخانهٔ توسکانی';
  return `${p.name} از ${m} برش خورده، در امتداد فشرده‌ترین بافت کلیک شده و با یراق‌آلات برنج خالص با آبکاری طلای شامپاینی ۱۸ عیار پرداخت شده است.`;
};
const conFor = p => `روی قالب آرشیوی خانه در شصت و هشت مرحله ساخته شده: رویه کشیده و سه هفته استراحت داده شده، زیره دوخته شده — نه چسبانده — به زیرهٔ چرمی گیاهی دباغی‌شده، پاشنه در ${p.heel} میلی‌متر سوار و تا میلی‌متر بالانس شده.`;

/* ═══ Views / colors / sizes ═══ */
function paintChip() {
  const chip = $('#pdpColorChip'); if (!chip) return;
  chip.style.setProperty('--c', state.colorHex || '#c8a24a');
  $('#chipName').textContent = state.color || '—';
  chip.classList.remove('swap'); void chip.offsetWidth; chip.classList.add('swap');
}
function applyView(i) {
  pdpViewIdx = i; const v = pdpViews[i]; if (!v) return;
  pdpImg.classList.remove('is-on');
  pdpImg.src = v.src;
  pdpImg.alt = `${CATALOG[state.pdpId] ? CATALOG[state.pdpId].name : ''} — ${v.label}`;
  wireImg(pdpImg);
  $$('#pdpThumbs .pdp__thumb').forEach((t, idx) => t.setAttribute('aria-current', String(idx === i)));
}
function selectVariant(n, jump = true) {
  const p = CATALOG[state.pdpId]; if (!p) return;
  const v = p.sw.find(s => s.n === n);
  state.color = n; state.colorHex = v ? v.c : '';
  $('#pdpColorName').textContent = n;
  $$('#pdpSw .sw').forEach(s => {
    const on = s.dataset.color === n;
    s.classList.toggle('sel', on);
    s.setAttribute('aria-checked', String(on));
  });
  paintChip();
  if (jump) {
    const ti = pdpViews.findIndex(x => x.kind === 'variant' && x.label === n);
    if (ti > -1) applyView(ti);
  }
}
function setSize(s) {
  state.size = s;
  $$('.size', $('#pdpSizes')).forEach(b => {
    const on = b.dataset.s === s;
    b.classList.toggle('sel', on);
    b.setAttribute('aria-checked', String(on));
  });
  $('#pdpSizeErr').hidden = true;
  $('#pdpSizes').classList.remove('err');
  if (s) {
    const c = getCustom();
    $('#sizeLive').textContent = s.endsWith('c') && c
      ? `سایز ${faNum(c.eu)} سفارشی انتخاب شد`
      : `سایز ${faNum(s)} انتخاب شد — ${CONV[s] || ''}`;
  }
}
function animPrice(el) {
  const target = +el.dataset.v || 0;
  if (reduced) { el.textContent = moneyT(target); return; }
  const t0 = performance.now();
  const run = t => {
    const k = Math.min(1, (t - t0)/640), ez = 1 - Math.pow(1 - k, 3);
    el.textContent = moneyT(ez * target);
    if (k < 1) RAF.add(run); else RAF.drop(run);
  };
  RAF.add(run);
}
function paintScarcity(p) {
  const sold = 24 - p.stock;
  $('#scTxt').textContent = p.stock <= 0 ? 'تمام شد' : p.stock <= 3 ? 'آخرین جفت‌ها' : 'اکنون در آتلیه';
  $('#scLeft').textContent = p.stock <= 0 ? '۰ جفت' : `${faNum(p.stock)} از ۲۴ مانده`;
  $('#scFill').style.width = clamp(sold/24*100, 6, 100) + '%';
}
function renderRelated(p) {
  const rel = PRODUCTS.filter(x => x.id !== p.id).sort((a, b) => {
    const af = (a.family === p.family ? 2 : 0) + (a.isNew ? 1 : 0);
    const bf = (b.family === p.family ? 2 : 0) + (b.isNew ? 1 : 0);
    return bf - af || Math.abs(a.price - p.price) - Math.abs(b.price - p.price);
  }).slice(0, 3);
  $('#pdpRel').innerHTML = rel.map(r => `<button class="selcard" type="button" data-open-pdp data-id="${r.id}">
    <span class="selcard__m"><img src="${r.img}" alt="${esc(r.name)}" loading="lazy" decoding="async" width="300" height="375"></span>
    <span class="selcard__i"><span class="selcard__cat">${esc(r.cat)}</span>
    <span class="selcard__name" style="display:block">${esc(r.name)}</span>
    <span class="selcard__price" style="display:block;margin-top:.3rem">${moneyT(r.price)}</span></span></button>`).join('');
  $$('#pdpRel img').forEach(wireImg);
}

/* ═══ Hydrate PDP ═══ */
let lastPdpId = null;
function hydrate(p) {
  const sameProduct = lastPdpId === p.id;
  lastPdpId = p.id;
  state.pdpId = p.id;
  state.size = sameProduct ? state.size : null;
  document.title = `${p.name} — خانهٔ اُرِل`;
  pdpViews = [];
  p.sw.forEach((v, i) => pdpViews.push({ src:v.img, label:v.n, kind:'variant', vIdx:i }));
  pdpViews.push({ src:window.AE_DATA.U.b, label:'کمپین', kind:'shot' });
  pdpViews.push({ src:window.AE_DATA.U.g, label:'آتلیه', kind:'shot' });
  pdpViewIdx = 0;
  $('#pdpCrumbCat').textContent = p.cat.split('·')[0].trim();
  $('#pdpCrumbName').textContent = p.name;
  pdpImg.src = pdpViews[0].src;
  pdpImg.alt = `${p.name} — ${pdpViews[0].label}`;
  wireImg(pdpImg);
  pdpImg.classList.remove('is-on');
  $('#pdpBadge').innerHTML = p.badge ? `<span class="pl-badge${p.badge === 'جدید' || p.badge === 'رونمایی هفته' ? ' hot' : ''}" style="position:static">${esc(p.badge)}</span>` : '';
  $('#pdpThumbs').innerHTML = pdpViews.map((v, i) =>
    `<button class="pdp__thumb" type="button" data-v="${i}" aria-label="نمای ${esc(v.label)}" aria-current="${i === 0}"><img src="${v.src}" alt="" loading="lazy" decoding="async" width="66" height="82">${v.kind === 'variant' ? `<span class="td" style="--c:${p.sw[v.vIdx].c}" aria-hidden="true"></span>` : ''}</button>`
  ).join('');
  $$('#pdpThumbs img').forEach(wireImg);
  $('#pdpCat').textContent = p.cat + (p.badge ? ` — ${p.badge}` : '');
  $('#pdpName').textContent = p.name;
  $('#pdpRateRow').innerHTML = `<span class="rate-row"><span class="stars" aria-hidden="true">${starsHTML(p.rating)}</span><b>${faNum(p.rating.toFixed(1))}</b><span>· ${faNum(p.reviews)} مالک تأییدشده</span></span>`;
  const pr = $('#pdpPrice'); pr.dataset.v = p.price; animPrice(pr);
  psPrice.textContent = moneyT(p.price);
  const oldEl = $('#pdpPriceOld'), discEl = $('#pdpDisc');
  if (p.oldPrice) {
    oldEl.hidden = false; oldEl.textContent = moneyT(p.oldPrice);
    discEl.hidden = false; discEl.textContent = '−' + faNum(Math.round((1 - p.price/p.oldPrice)*100)) + '٪';
  } else { oldEl.hidden = true; discEl.hidden = true; }
  $('#pdpLead').textContent = `${p.sub}. قالب‌گیری دستی در تیراژ محدود، پرداخت با دست، پوشیده برای دهه‌ها.`;
  paintScarcity(p);
  const idx = PRODUCTS.indexOf(p);
  $('#certNo').innerHTML = `<span class="ltr" dir="ltr">V-${String(idx+1).padStart(3,'0')}/200</span>`;
  $('#certArt').textContent = ARTISANS[idx % ARTISANS.length];
  $('#certDate').textContent = 'پاییز/زمستان ۲۶ · نسخهٔ ابدی';
  $('#pdpFitNote').textContent = `پاشنهٔ ${p.heel} میلی‌متری. بین دو سایز: برای پاشنه‌بلند سایز کوچک‌تر، برای بوت سایز بزرگ‌تر.`;
  $('#pdpEta').textContent = `ارسال از آتلیه — تحویل تقریبی ${window.AE.eta()}`;
  $('#accMat').textContent = matFor(p);
  $('#accCon').textContent = conFor(p);
  $('#accCare').textContent = CARE;

  const sw = $('#pdpSw'); sw.innerHTML = '';
  p.sw.forEach(s => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'sw'; b.dataset.color = s.n;
    b.style.setProperty('--c', s.c);
    b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', 'false'); b.setAttribute('aria-label', s.n);
    b.addEventListener('click', () => selectVariant(s.n, true));
    sw.appendChild(b);
  });

  const sizes = $('#pdpSizes'); sizes.innerHTML = ''; sizes.classList.remove('err');
  $('#pdpSizeErr').hidden = true; $('#sizeLive').textContent = '';
  SIZES.forEach(s => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'size'; b.dataset.s = s; b.textContent = faNum(s);
    b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', 'false');
    b.addEventListener('click', () => setSize(s));
    sizes.appendChild(b);
  });

  $$('#pdpAcc .acc-i').forEach((it, i) => {
    it.classList.toggle('open', i === 0);
    $('.acc-q', it).setAttribute('aria-expanded', String(i === 0));
  });
  switchSizeTab('std');
  selectVariant(p.sw[0].n, false);
  paintChip();
  renderRelated(p);
  renderCustomPanel();
  if (sameProduct && state.size) {
    switchSizeTab(state.size.endsWith('c') ? 'cst' : 'std');
    setSize(state.size);
  }
  window.dispatchEvent(new CustomEvent('ae:render-reviews', { detail:{ pid:p.id, host:$('#pdpReviews') } }));
  pushRecent(p.id);
}
function pushRecent(id) {
  let list = LS.get(K.recent, []).filter(x => x !== id);
  list.unshift(id); list = list.slice(0, 6);
  LS.set(K.recent, list);
  window.dispatchEvent(new CustomEvent('ae:render-recent'));
}

/* ═══ Lightbox ═══ */
const lbx = $('#lbx'), lbxImg = $('#lbxImg'), lbxCount = $('#lbxCount'), lbxLab = $('#lbxLab');
let lbxSet = [], lbxIdx = 0;
function lbxPaint() {
  const v = lbxSet[lbxIdx]; if (!v) return;
  lbxImg.src = v.src; lbxImg.alt = v.alt || '';
  lbxCount.textContent = `${faPad(lbxIdx+1)} / ${faPad(lbxSet.length)}`;
  lbxLab.textContent = v.label || '';
}
function openLbxSet(set, i) {
  lbxSet = set; lbxIdx = (i + set.length) % set.length;
  lbx._opener = document.activeElement;
  lbxPaint(); lbx.showModal(); dlStop();
}
pdpStage && pdpStage.addEventListener('click', () => {
  if (fine) return; /* دسکتاپ: فقط hover-zoom */
  openLbxSet(pdpViews, pdpViewIdx);
});
pdpStage && pdpStage.addEventListener('dblclick', () => {
  if (!fine) return;
  openLbxSet(pdpViews, pdpViewIdx);
});
pdpStage && pdpStage.addEventListener('keydown', e => {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLbxSet(pdpViews, pdpViewIdx); }
});
const lookSet = $$('.lb-item').map(it => {
  const img = $('img', it), b = $('.lb-cap b', it), s = $('.lb-cap span', it);
  return { src:(img.src || '').replace('w=800', 'w=1400'), label:(b ? b.textContent : '') + (s ? ' · ' + s.textContent : ''), alt:img.alt };
});
$$('.lb-item figure').forEach((f, i) => f.addEventListener('click', () => openLbxSet(lookSet, i)));
$('#lbxX') && $('#lbxX').addEventListener('click', () => lbx.close());
$('#lbxPrev') && $('#lbxPrev').addEventListener('click', () => { lbxIdx = (lbxIdx - 1 + lbxSet.length) % lbxSet.length; lbxPaint(); });
$('#lbxNext') && $('#lbxNext').addEventListener('click', () => { lbxIdx = (lbxIdx + 1) % lbxSet.length; lbxPaint(); });
lbx && lbx.addEventListener('keydown', e => {
  if (e.key === 'ArrowLeft')  { lbxIdx = (lbxIdx - 1 + lbxSet.length) % lbxSet.length; lbxPaint(); }
  if (e.key === 'ArrowRight') { lbxIdx = (lbxIdx + 1) % lbxSet.length; lbxPaint(); }
});
let lbxX0 = null;
lbx && lbx.addEventListener('pointerdown', e => lbxX0 = e.clientX);
lbx && lbx.addEventListener('pointerup', e => {
  if (lbxX0 === null) return;
  const dx = e.clientX - lbxX0; lbxX0 = null;
  if (Math.abs(dx) > 44) { lbxIdx = (lbxIdx + (dx < 0 ? 1 : -1) + lbxSet.length) % lbxSet.length; lbxPaint(); }
});
lbx && lbx.addEventListener('close', () => {
  lbx._opener && lbx._opener.focus && lbx._opener.focus({ preventScroll:true });
  lbx._opener = null; dlStart();
});

/* ═══ Guilloche + Passport ═══ */
const passport = $('#passport');
const guilloche = memoize(function (cx, cy, R, r, d, turns, steps) {
  let p = '', hsh = 1;
  for (let i = 0; i <= steps; i++) {
    const t = i/steps * turns * 6.283185;
    const x = cx + (R-r)*Math.cos(t) + d*Math.cos(((R-r)/r)*t);
    const y = cy + (R-r)*Math.sin(t) - d*Math.sin(((R-r)/r)*t);
    p += (i ? 'L' : 'M') + x.toFixed(2) + ' ' + y.toFixed(2);
    hsh = (hsh*31 + i) >>> 0;
  }
  return { d:p + 'Z', h:hsh };
});
function paintGuilloche(seed) {
  const svg = $('#ppGuil'); if (!svg) return;
  const sets = [[300,140,118,47,64,7],[300,140,96,31,52,5],[560,760,132,53,70,9],[120,700,88,29,44,6]];
  svg.innerHTML = sets.map((s, i) => {
    const k = ((seed >>> (i*3)) % 7) + 1;
    const g = guilloche(s[0], s[1], s[2], s[3]-k, s[4], s[5], 760);
    return `<path d="${g.d}" opacity="${(.24 + i*.07).toFixed(2)}" stroke-width="${(.4 + i*.14).toFixed(2)}"/>`;
  }).join('');
}
function hashOf(str) { let h = 7; for (const c of str) h = (h*31 + c.charCodeAt(0)) >>> 0; return h; }
function openPassport(p) {
  const idx = PRODUCTS.indexOf(p), h = hashOf(p.id + 'aurelle');
  paintGuilloche(h);
  $('#ppNo').innerHTML = `<span class="ltr" dir="ltr">V-${String(idx+1).padStart(3,'0')}/200</span>`;
  $('#ppName').textContent = `${p.name} — ${p.cat}`;
  $('#ppArt').textContent = ARTISANS[idx % ARTISANS.length];
  $('#ppDate').textContent = 'پاییز/زمستان ۲۶ · نسخهٔ ابدی';
  $('#ppMat').textContent = (p.sw[0] && p.sw[0].n) || '—';
  $('#ppHeel').textContent = faNum(p.heel) + ' میلی‌متر';
  $('#ppSig').textContent = 'امضا ' + h.toString(16).toUpperCase().padStart(8, '0').slice(0, 8);
  const qr = $('#ppQr'); qr.innerHTML = '';
  let hh = h;
  for (let i = 0; i < 144; i++) {
    hh = (hh*1103515245 + 12345) >>> 0;
    const d = document.createElement('i');
    if (hh % 3 === 0) d.className = 'o';
    qr.appendChild(d);
  }
  passport._opener = document.activeElement;
  passport.showModal(); dlStop();
}
$('#pdpPassport') && $('#pdpPassport').addEventListener('click', () => {
  const p = CATALOG[state.pdpId];
  if (p) openPassport(p);
});
$('#ppX') && $('#ppX').addEventListener('click', () => passport.close());
$('#ppPrint') && $('#ppPrint').addEventListener('click', () => {
  const done = () => html.classList.remove('printing-passport');
  addEventListener('afterprint', done, { once:true });
  html.classList.add('printing-passport');
  window.print();
});
backdropClose(passport);
passport && passport.addEventListener('close', () => {
  passport._opener && passport._opener.focus && passport._opener.focus({ preventScroll:true });
  passport._opener = null; dlStart();
});

/* ═══ Custom size panel ═══ */
function ensureCustomSizeButton() {
  const c = getCustom(); if (!c || !state.pdpId) return;
  const val = c.eu + 'c';
  const box = $('#pdpSizes'); if (!box) return;
  if (!$(`[data-s="${val}"]`, box)) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'size custom'; b.dataset.s = val;
    b.textContent = `${faNum(c.eu)} سفارشی ★`;
    b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', 'false');
    b.addEventListener('click', () => setSize(val));
    box.appendChild(b);
  }
}
function renderCustomPanel() {
  const c = getCustom();
  const saved = $('#cstSaved'), empty = $('#cstEmpty'); if (!saved || !empty) return;
  if (c) {
    saved.hidden = false; empty.hidden = true;
    $('#cstEu').textContent = `${faNum(c.eu)} سفارشی`;
    $('#cstMeta').textContent = `طول ${faNum(c.L)} · عرض ${faNum(c.W)} سانتی‌متر${c.wide ? ' · قالب عریض' : ''}`;
    $('#cstUse').onclick = () => {
      ensureCustomSizeButton();
      setSize(c.eu + 'c');
      switchSizeTab('std');
      toast(`سایز ${faNum(c.eu)} سفارشی انتخاب شد.`);
    };
    ensureCustomSizeButton();
  } else { saved.hidden = true; empty.hidden = false; }
}
function switchSizeTab(t) {
  const std = t === 'std';
  $('#tabStd').setAttribute('aria-selected', String(std));
  $('#tabCst').setAttribute('aria-selected', String(!std));
  $('#panelStd').hidden = !std; $('#panelCst').hidden = std;
}
$('#tabStd') && $('#tabStd').addEventListener('click', () => switchSizeTab('std'));
$('#tabCst') && $('#tabCst').addEventListener('click', () => { renderCustomPanel(); switchSizeTab('cst'); });

/* ═══ PDP interactions ═══ */
$('#pdpThumbs') && $('#pdpThumbs').addEventListener('click', e => {
  const b = e.target.closest('.pdp__thumb'); if (!b) return;
  const i = +b.dataset.v, v = pdpViews[i];
  applyView(i);
  if (v.kind === 'variant') selectVariant(v.label, false);
});
if (fine && !reduced) {
  pdpStage && pdpStage.addEventListener('pointerenter', () => pdpStage.classList.add('is-zoom'));
  pdpStage && pdpStage.addEventListener('pointerleave', () => {
    pdpStage.classList.remove('is-zoom');
    pdpImg.style.transformOrigin = '50% 40%';
  });
  pdpStage && pdpStage.addEventListener('pointermove', e => {
    const r = pdpStage.getBoundingClientRect();
    pdpImg.style.transformOrigin = `${((e.clientX - r.left)/r.width*100).toFixed(2)}% ${((e.clientY - r.top)/r.height*100).toFixed(2)}%`;
  });
}
pdp && pdp.addEventListener('scroll', () => {
  const addBtn = $('.pdp__info .js-add', pdp); if (!addBtn) return;
  const r = addBtn.getBoundingClientRect();
  pdpSticky.classList.toggle('is-on', r.bottom < 0 || r.top > innerHeight);
}, { passive:true });

$('#crumbHome') && $('#crumbHome').addEventListener('click', () => pdp.close());
$('#crumbShop') && $('#crumbShop').addEventListener('click', () => {
  pdp.close();
  setTimeout(() => window.AE.scrollToEl('#boutique'), reduced ? 0 : 250);
});
$('#pdpShare') && $('#pdpShare').addEventListener('click', async () => {
  const p = CATALOG[state.pdpId]; if (!p) return;
  const d = { title:`خانهٔ اُرِل — ${p.name}`, text:`${p.name} · ${p.cat} · ${moneyT(p.price)}`, url: location.origin + location.pathname + '#/pdp/' + p.id };
  try {
    if (navigator.share) { await navigator.share(d); toast('به اشتراک گذاشته شد.'); }
    else { await navigator.clipboard.writeText(`${d.title} · ${d.text} · ${d.url}`); toast('پیوند کپی شد.'); }
  } catch (err) { if (err && err.name !== 'AbortError') toast('اشتراک‌گذاری نشد.', 'err'); }
});
$('#pdpBack') && $('#pdpBack').addEventListener('click', () => pdp.close());
$('#pdpX') && $('#pdpX').addEventListener('click', () => pdp.close());

pdp && pdp.addEventListener('close', () => {
  pdpSticky.classList.remove('is-on');
  document.title = DOC_TITLE;
  if (location.hash.startsWith('#/pdp/')) {
    history.replaceState(null, '', '#/');
  }
  state.pdpId = null;
  state.lastFocus && state.lastFocus.focus && state.lastFocus.focus({ preventScroll:true });
  state.lastFocus = null;
  dlStart();
});

$$('#pdpAcc .acc-q').forEach(q => q.addEventListener('click', () => {
  const it = q.closest('.acc-i'), o = it.classList.toggle('open');
  q.setAttribute('aria-expanded', String(o));
}));

$$('.js-add', pdp).forEach(b => b.addEventListener('click', () => {
  const p = CATALOG[state.pdpId]; if (!p) return;
  if (!state.size) {
    $('#pdpSizes').classList.remove('err');
    void $('#pdpSizes').offsetWidth;
    $('#pdpSizes').classList.add('err');
    $('#pdpSizeErr').hidden = false;
    $('#sizeLive').textContent = 'لطفاً یک سایز انتخاب کنید.';
    toast('لطفاً اول یک سایز انتخاب کنید.', 'err');
    return;
  }
  const c = getCustom();
  const isCustom = state.size.endsWith('c');
  const sizeL = isCustom && c ? `سایز ${faNum(c.eu)} سفارشی` : 'سایز ' + faNum(state.size);
  const ok = window.AE_CART.addToCart(p.id, state.size, sizeL, state.color || p.sw[0].n, state.colorHex || p.sw[0].c, 0, '', $('#pdpImg'));
  if (!ok) return;
  haptic('add');
  window.AE_CART.showConfirmPill('به سبد اضافه شد');
  toast(`${p.name} · ${sizeL} · ${state.color || p.sw[0].n} — به سبد اضافه شد.`, 'ok', { label:'مشاهدهٔ سبد', fn:window.AE_CART.openBag });
  pdp.close();
}));

/* ═══ Exports ═══ */
window.AE_PDP = {
  hydrate, openPassport, openLbxSet,
  pdpGet: () => pdp,
  switchSizeTab, renderCustomPanel, setSize,
  hashOf, guilloche
};
})();