/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — main.js
   Routing · Product grid · Actions · Shortcuts · Reviews · Fit wizard ·
   Compare · Cmdk · Luxe layer · Phase 2 (PWA/network/image) · Boot sync
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, clamp, reduced, fine, coarse, RAF, TIMERS, LS, K,
  faNum, faPad, moneyT, esc, debounce, toast, haptic, withLoad, memoize, wait,
  dlStop, dlStart, scrollToEl, backdropClose, overlayShow, overlayHide, trapFocus,
  starsHTML
} = window.AE;
const {
  CATALOG, PRODUCTS, ORDER, FAM, R_NAMES, R_TEXTS, FIT_STEPS
} = window.AE_DATA;
const {
  P, saveP, state, persBag, persWish, getCustom, smartScore
} = window.AE_STATE;
const { wireImg, revealIO, setTheme, setMode, setMnav, scrollToFilters } = window.AE_UI;
const { openBag, openWish, toggleWish, openSheet } = window.AE_CART;
const { hydrate } = window.AE_PDP;
const { openCko } = window.AE_CKO;
const { openProfile } = window.AE_AUTH;
const { toggleConc } = window.AE_CONC;
const { renderAtelier } = window.AE_ATELIER;

/* ═══ Product grid ═══ */
const grid = $('#grid');
const cardHTML = (p, i) => `<article class="prod rv${i%3===1?' rv-d1':i%3===2?' rv-d2':''}" data-id="${p.id}" role="listitem">
<a class="prod-media skl" href="#/pdp/${p.id}" data-open-pdp aria-label="مشاهدهٔ جزئیات — ${esc(p.name)}">
<img src="${p.img}" alt="${esc(p.name)} — ${esc(p.sub)}" loading="lazy" decoding="async" width="640" height="800">
${p.badge ? `<span class="pl-badge${p.badge==='جدید'||p.badge==='رونمایی هفته'?' hot':''}">${esc(p.badge)}</span>` : ''}
${p.stock <= 3 ? `<span class="pl-badge low">فقط ${faNum(p.stock)} مانده</span>` : ''}
<span class="pl-num">فرم ${faPad(PRODUCTS.indexOf(p)+1)}</span>
<span class="prod-line" aria-hidden="true"></span></a>
<button class="wish" type="button" aria-label="ذخیرهٔ ${esc(p.name)}" aria-pressed="false"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-7.5-4.7-10-9.3C.4 8.6 2.4 5 6 5c2.2 0 3.6 1.2 4.4 2.6h1.2C12.4 6.2 13.8 5 16 5c3.6 0 5.6 3.6 4 6.7C19.5 16.3 12 21 12 21z"/></svg></button>
<button class="cmp-toggle" type="button" data-act="cmp-add" data-id="${p.id}" aria-label="افزودن ${esc(p.name)} به مقایسه" aria-pressed="false"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="16" rx="1"/><rect x="13" y="4" width="7" height="16" rx="1"/></svg></button>
<div class="prod-info">
<div class="p-top"><span class="p-cat">${esc(p.cat)}</span><span class="p-price">${moneyT(p.price)}</span></div>
<h3 class="p-name"><button type="button" data-open-pdp data-id="${p.id}">${esc(p.name)}</button></h3>
<div class="p-meta"><span class="rate-row"><span class="stars" aria-hidden="true">${starsHTML(p.rating)}</span><b>${faNum(p.rating.toFixed(1))}</b><span>(${faNum(p.reviews)})</span></span>
<span class="stock-pill${p.stock<=5?' low':''}"><span class="dot" aria-hidden="true"></span>${p.stock<=5?`فقط ${faNum(p.stock)} مانده`:'در آتلیه'}</span></div>
<p class="p-sub">${esc(p.sub)}</p>
<div class="p-row"><div class="p-sw" aria-hidden="true">${p.sw.map(s => `<span class="sw" style="--c:${s.c}"></span>`).join('')}</div><span class="mono" style="font-size:.56rem">۳۶ تا ۴۶</span></div>
<button class="quick" type="button" data-quick data-cursor="سایز"><span class="quick-t">انتخاب سایز</span></button>
</div></article>`;
function renderGrid() {
  if (grid) grid.innerHTML = PRODUCTS.map(cardHTML).join('');
}
renderGrid();

/* ═══ Selection rail ═══ */
(() => {
  const rail = $('#selRail'); if (!rail) return;
  const picks = ['termeh','roza','golnar','azin','niloofar','yas'];
  rail.innerHTML = picks.map(id => {
    const p = CATALOG[id]; if (!p) return '';
    return `<button class="selcard" type="button" data-open-pdp data-id="${p.id}">
      <span class="selcard__m skl"><img src="${p.img}" alt="${esc(p.name)}" loading="lazy" decoding="async" width="300" height="375"></span>
      <span class="selcard__i">
        <span class="selcard__cat">${esc(p.cat)}</span>
        <span class="selcard__name">${esc(p.name)}</span>
        <span class="selcard__price">${moneyT(p.price)}</span>
      </span>
    </button>`;
  }).join('');
  $$('#selRail img').forEach(window.AE_UI.wireImg);

  const step = () => Math.max(220, Math.min(380, rail.clientWidth * 0.7));
  const dir = html.getAttribute('dir') === 'rtl' ? -1 : 1;
  const sp = $('#selPrev'), sn = $('#selNext');
  /* RTL: scrollLeft صفر در سمت راست (شروع) است و با حرکت به سمت پایان منفی می‌شود */
  sp && sp.addEventListener('click', () => rail.scrollBy({ left: -step() * dir, behavior: reduced ? 'auto' : 'smooth' }));
  sn && sn.addEventListener('click', () => rail.scrollBy({ left: step() * dir, behavior: reduced ? 'auto' : 'smooth' }));
})();

const wireCards = root => {
  $$('img', root).forEach(wireImg);
  $$('.rv', root).forEach(el => revealIO.observe(el));
  $$('.wish', root).forEach(w => w.addEventListener('click', e => {
    e.preventDefault(); e.stopPropagation();
    toggleWish(w.closest('.prod').dataset.id);
  }));
  $$('[data-quick]', root).forEach(b => b.addEventListener('click', () => openSheet(b.closest('.prod').dataset.id)));
  if (fine) $$('.prod-media', root).forEach(m => {
    m.addEventListener('pointermove', e => {
      const r = m.getBoundingClientRect();
      m.style.setProperty('--mx', ((e.clientX - r.left)/r.width*100) + '%');
      m.style.setProperty('--my', ((e.clientY - r.top)/r.height*100) + '%');
    }, { passive:true });
  });
};
if (grid) wireCards(grid);

/* لایهٔ مدیریت روی ویترین نشست — شبکه و شمارنده‌ها باید دوباره ساخته شوند */
addEventListener('ae:catalog-sync', () => {
  renderGrid();
  if (grid) wireCards(grid);
  window.AE_UI.renderFamChips && window.AE_UI.renderFamChips();
  apply();
});

/* ═══ Apply filters ═══ */
function apply() {
  if (!grid) return;
  const cards = $$('.prod', grid), q = state.q.trim().toLowerCase();
  cards.forEach(el => {
    const p = CATALOG[el.dataset.id]; if (!p) return;
    const famOK = state.fam === 'all' ? true : state.fam === 'wish' ? state.wish.includes(p.id) : p.family === state.fam;
    const qOK = !q || (p.name + ' ' + p.sub + ' ' + p.cat).toLowerCase().includes(q);
    const priceOK = p.price <= state.priceMax;
    el.hidden = !(famOK && qOK && priceOK);
  });
  const vis = cards.filter(el => !el.hidden);
  vis.sort((a, b) => {
    const pa = CATALOG[a.dataset.id], pb = CATALOG[b.dataset.id];
    if (state.sort === 'asc')    return pa.price - pb.price;
    if (state.sort === 'desc')   return pb.price - pa.price;
    if (state.sort === 'name')   return pa.name.localeCompare(pb.name, 'fa');
    if (state.sort === 'rating') return pb.rating - pa.rating || pb.reviews - pa.reviews;
    if (state.sort === 'heel')   return pa.heel - pb.heel;
    if (state.sort === 'smart')  return smartScore(pb) - smartScore(pa);
    return ORDER.indexOf(pa.id) - ORDER.indexOf(pb.id);
  });
  vis.forEach(el => grid.appendChild(el));
  const vc = $('#vCount'); if (vc) vc.textContent = `${faNum(vis.length)} فرم`;
  renderPills();
}
function renderPills() {
  const p = [];
  if (state.fam !== 'all') p.push({ k:'fam', l:FAM[state.fam] });
  if (state.q.trim()) p.push({ k:'q', l:'«' + state.q.trim() + '»' });
  if (state.priceMax < 30000000) p.push({ k:'price', l:'≤ ' + moneyT(state.priceMax) });
  if (state.sort !== 'featured') p.push({ k:'sort', l:'مرتب‌شده · ' + ($('#vSort') && $('#vSort').selectedOptions[0] ? $('#vSort').selectedOptions[0].textContent : '') });
  const box = $('#activePills'); if (!box) return;
  box.innerHTML = p.length
    ? `<span class="mono" style="color:var(--accent)">فعال</span>` + p.map(x =>
        `<button class="chip" type="button" data-pill="${x.k}" aria-label="حذف فیلتر ${esc(x.l)}">${esc(x.l)} <span style="opacity:.6">×</span></button>`
      ).join('')
    : '';
}
$('#activePills') && $('#activePills').addEventListener('click', e => {
  const b = e.target.closest('[data-pill]'); if (!b) return;
  const k = b.dataset.pill;
  if (k === 'fam')   { state.fam = 'all'; window.AE_UI.renderFamChips(); }
  if (k === 'q')     { state.q = ''; const vi = $('#vSearch'); if (vi) vi.value = ''; const hq = $('#hdrQ'); if (hq) hq.value = ''; }
  if (k === 'price') { state.priceMax = 30000000; const vp = $('#vPrice'); if (vp) vp.value = '30000000'; const po = $('#vPriceOut'); if (po) po.textContent = moneyT(30000000); }
  if (k === 'sort')  { state.sort = 'featured'; const vs = $('#vSort'); if (vs) vs.value = 'featured'; }
  apply();
});
$('#vSort') && $('#vSort').addEventListener('change', e => { state.sort = e.target.value; apply(); });
$('#vSearch') && $('#vSearch').addEventListener('input', e => {
  const v = e.target.value;
  const hq = $('#hdrQ'); if (hq && hq.value !== v) hq.value = v;
  TIMERS.once(() => { state.q = v; RAF.add(apply); }, 150, 'vsearch');
});
$('#vSearchForm') && $('#vSearchForm').addEventListener('submit', e => e.preventDefault());
const vPrice = $('#vPrice');
vPrice && vPrice.addEventListener('input', () => {
  const v = +vPrice.value;
  const po = $('#vPriceOut'); if (po) po.textContent = moneyT(v);
  vPrice.setAttribute('aria-valuetext', moneyT(v));
  state.priceMax = v; apply();
});
const vEta = $('#vEta'); if (vEta) vEta.textContent = `تحویل تقریبی ${window.AE.eta()}`;
addEventListener('ae:apply', apply);

/* ═══ Recent ═══ */
function renderRecent() {
  const wrap = $('#recent'), box = $('#recentChips'); if (!wrap || !box) return;
  const list = (LS.get(K.recent, [])).filter(id => CATALOG[id]);
  if (!list.length) { wrap.hidden = true; return; }
  wrap.hidden = false;
  box.innerHTML = list.map(id => `<button class="chip" type="button" data-r="${esc(id)}">${esc(CATALOG[id].name)}</button>`).join('');
}
$('#recentChips') && $('#recentChips').addEventListener('click', e => {
  const b = e.target.closest('[data-r]'); if (b) location.hash = '#/pdp/' + b.dataset.r;
});
addEventListener('ae:render-recent', renderRecent);
renderRecent();

/* ═══ Reviews ═══ */
function renderReviews(p, host) {
  if (!host) return;
  const revs = Reviews.list(p.id);
  const avg = revs.length ? revs.reduce((s, r) => s + r.rating, 0)/revs.length : p.rating;
  const dist = [5,4,3,2,1].map(st => revs.filter(r => r.rating === st).length);
  const max = Math.max(...dist, 1);
  const can = Reviews.canReview(p.id);
  host.innerHTML = `<div class="rev-top"><span class="mono" style="color:var(--accent)">دیدگاه‌های خریداران</span>
    <span class="rate-row"><span class="stars" aria-hidden="true">${starsHTML(avg)}</span><b>${faNum(avg.toFixed(1))}</b><span>· ${faNum(revs.length)} دیدگاه</span></span></div>
    <div class="rev-sum">
      <div class="rev-big"><span class="n">${faNum(avg.toFixed(1))}</span><span class="stars" aria-hidden="true">${starsHTML(avg)}</span><span class="c">${faNum(revs.length)} دیدگاه تأییدشده</span></div>
      <div class="rev-bars" aria-hidden="true">${[5,4,3,2,1].map((st, i) => `<div class="rev-bar"><span>${faNum(st)}★</span><span class="t"><i style="width:${(dist[i]/max*100).toFixed(0)}%"></i></span><span>${faNum(dist[i])}</span></div>`).join('')}</div>
      <span class="sr-only">توزیع امتیازها از پنج تا یک ستاره</span></div>
    ${can ? `<form class="rev-form" id="revForm" novalidate>
      <span class="mono" style="color:var(--accent);display:block;margin-bottom:.8rem">دیدگاه شما — خریدار تأییدشده</span>
      <div class="star-input" id="revStars" role="radiogroup" aria-label="امتیاز شما">${[1,2,3,4,5].map(i => `<button type="button" data-v="${i}" aria-label="${i} ستاره"><svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg></button>`).join('')}</div>
      <div class="field" style="margin:.9rem 0 0"><label for="revTxt">متن دیدگاه</label><textarea id="revTxt" placeholder="تجربهٔ خود را بنویسید…" required style="min-height:90px"></textarea></div>
      <button class="btn btn-solid btn-sm" type="submit" style="margin-top:.6rem"><span>ثبت دیدگاه</span></button></form>`
    : `<div class="rev-lock"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><span>فقط خریداران این محصول می‌توانند دیدگاه ثبت کنند.</span></div>`}
    <div class="rev-list">${revs.map(r => `<article class="rev-item">
      <div class="rev-head"><div class="rev-who"><span class="rev-av" aria-hidden="true">${esc(r.name.charAt(0))}</span>
      <div><div class="rev-name">${esc(r.name)}</div>${r.verified ? '<span class="rev-vrf">✓ خریدار تأییدشده</span>' : ''}</div></div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.2rem"><span class="stars" aria-hidden="true">${starsHTML(r.rating)}</span><span class="rev-date">${new Intl.DateTimeFormat('fa-IR', { dateStyle:'long' }).format(new Date(r.date))}</span></div></div>
      <p class="rev-txt">${esc(r.text)}</p></article>`).join('')}</div>`;
  const form = $('#revForm', host);
  if (form) {
    let rating = 0;
    const stars = $$('#revStars button', form);
    const paint = () => stars.forEach(b => b.classList.toggle('on', +b.dataset.v <= rating));
    stars.forEach(b => {
      b.addEventListener('click', () => { rating = +b.dataset.v; paint(); });
      b.addEventListener('mouseenter', () => stars.forEach(x => x.classList.toggle('on', +x.dataset.v <= +b.dataset.v)));
      b.addEventListener('mouseleave', paint);
    });
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const txt = $('#revTxt', form).value.trim();
      if (!rating) { toast('لطفاً امتیاز ستاره‌ای را انتخاب کنید.', 'err'); return; }
      if (txt.length < 5) { toast('متن دیدگاه را کامل کنید.', 'err'); return; }
      const btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      await wait(650);
      const all = LS.get(K.urevs, {});
      (all[p.id] = all[p.id] || []).unshift({
        name: (LS.get(K.profile, {})).name || 'شما',
        date: new Date().toISOString(), rating, text:txt, verified:true
      });
      LS.set(K.urevs, all);
      btn.disabled = false;
      toast('دیدگاه شما ثبت شد — سپاس.');
      renderReviews(p, host);
    });
  }
}
const Reviews = {
  list(pid) {
    const p = CATALOG[pid]; if (!p) return [];
    const base = p.id.charCodeAt(0);
    const n = Math.min(3, Math.max(2, p.reviews % 3 + 2));
    const out = [];
    for (let i = 0; i < n; i++) out.push({
      name: R_NAMES[(base + i*3) % R_NAMES.length],
      date: new Date(Date.now() - (i+1)*9*864e5).toISOString(),
      rating: i === 0 ? 5 : (p.rating >= 4.7 ? 5 : 4),
      text: R_TEXTS[(base + i*5) % R_TEXTS.length],
      verified: true
    });
    return [...((LS.get(K.urevs, {}))[pid] || []), ...out];
  },
  canReview(pid) {
    const prof = LS.get(K.profile, null); if (!prof) return false;
    return LS.get(K.orders, []).some(o => (o.items || []).some(it => it.id === pid));
  }
};
addEventListener('ae:render-reviews', e => {
  const { pid, host } = e.detail;
  const p = CATALOG[pid]; if (p) renderReviews(p, host);
});

/* ═══ Routing ═══ */
let suppressHash = false;
function setRouteSilent(url) {
  suppressHash = true;
  history.replaceState(null, '', url);
  RAF.add(() => suppressHash = false);
}
function openPDPByRoute(id) {
  const p = CATALOG[id]; if (!p) return;
  const bag = $('#bag'), wishd = $('#wishd'), pdp = $('#pdp');
  if (bag.open) bag.close();
  if (wishd.open) wishd.close();
  if (pdp.open) { hydrate(p); pdp.scrollTop = 0; return; }
  state.lastFocus = document.activeElement;
  hydrate(p);
  pdp.showModal();
  pdp.scrollTop = 0;
  dlStop();
  const backBtn = $('#pdpBack');
  backBtn && backBtn.focus({ preventScroll:true });
}
const nf = $('#nf');
function openNf() { nf._opener = document.activeElement; nf.showModal(); dlStop(); }
$('#nfTop') && $('#nfTop').addEventListener('click', () => nf.close());
nf && nf.addEventListener('close', () => {
  nf._opener && nf._opener.focus && nf._opener.focus({ preventScroll:true });
  nf._opener = null; dlStart();
  setRouteSilent('#/');
});
function handleRoute() {
  const h = location.hash;
  /* بازگشت از درگاه: #/checkout?status=… — هندل در checkout.js؛ اینجا فقط رد کن */
  if (/^#\/checkout\?/.test(h)) return;
  const pm = h.match(/^#\/pdp\/([a-z0-9-]+)/i);
  if (pm) { if (CATALOG[pm[1]]) openPDPByRoute(pm[1]); else openNf(); return; }
  if (h === '#/profile') { openProfile(true); return; }
  if (h === '#/admin')   { if (window.AE_ADMIN) AE_ADMIN.open(true); return; }
  if (h === '' || h === '#' || h === '#/') { const pdp = $('#pdp'); if (pdp.open) pdp.close(); return; }
  if (h.startsWith('#/')) { openNf(); return; }
  const pdp = $('#pdp'); if (pdp.open) pdp.close();
}
addEventListener('hashchange', () => { if (suppressHash) return; handleRoute(); });
document.addEventListener('click', e => {
  const t = e.target.closest('[data-open-pdp]'); if (!t) return;
  e.preventDefault();
  const id = t.dataset.id || (t.closest('.prod') ? t.closest('.prod').dataset.id : null);
  if (!id) return;
  location.hash = '#/pdp/' + id;
});
document.addEventListener('keydown', e => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-open-pdp][role="button"]')) {
    e.preventDefault(); e.target.click();
  }
});

/* ═══ Reviews / order flow ═══ */
$('#checkout') && $('#checkout').addEventListener('click', () => {
  if (!state.cart.length) { toast('سبد شما خالی است.', 'err'); return; }
  openCko();
});

/* ═══ Exit intent ═══ */
const exitd = $('#exitd');
let exitShown = LS.get(K.exit, false);
function openExit() {
    if (!exitd || exitShown || exitd.open || state.cart.length > 0) return;
  exitShown = true; LS.set(K.exit, true);
  exitd.showModal(); dlStop();
}
$('#exitX') && $('#exitX').addEventListener('click', () => exitd.close());
$('#exitNo') && $('#exitNo').addEventListener('click', () => exitd.close());
$('#exitVault') && $('#exitVault').addEventListener('click', () => {
  exitd.close();
  scrollToFilters(0);
});
if (!reduced) document.addEventListener('mouseout', e => {
  if (document.hidden) return;
  if (!e.relatedTarget && e.clientY <= 0) openExit();
});
exitd && exitd.addEventListener('close', () => dlStart());

/* ═══ Contact sheet ═══ */
const contactSheet = $('#contactSheet');
$('#contactX') && $('#contactX').addEventListener('click', () => contactSheet.close());
contactSheet && contactSheet.addEventListener('close', () => dlStart());
backdropClose(contactSheet);

/* ═══ Command palette ═══ */
const cmdk = $('#cmdk'), cmdkInput = $('#cmdkInput'), cmdkList = $('#cmdkList');
let cmdkIdx = 0, cmdkItems = [];
function cmdkOpen() {
  cmdk._opener = document.activeElement;
  cmdk.showModal(); dlStop();
  cmdkInput.value = ''; cmdkIdx = 0; cmdkRender('');
}
function cmdkClose() {
  cmdk.close(); dlStart();
  cmdk._opener && cmdk._opener.focus && cmdk._opener.focus({ preventScroll:true });
}
function cmdkRender(q) {
  q = q.trim().toLowerCase();
  cmdkItems = [];
  const cmds = [
    { l:'بازگشت به خانه', ic:'🏠', act:() => { location.hash = '#/'; } },
    { l:'فروشگاه', ic:'🛍', act:() => { location.hash = '#/'; scrollToFilters(200); } },
    { l:'سبد خرید', ic:'🛒', act:openBag },
    { l:'علاقه‌مندی‌ها', ic:'❤', act:openWish },
    { l:'حساب کاربری', ic:'👤', act:() => openProfile(true) },
    { l:'DNA سبک', ic:'🧬', act:openFit },
    { l:'راهنمای سایز', ic:'📏', act:openSG },
    { l:'سایز سفارشی', ic:'✂', act:openMZ },
    { l:'تغییر پوسته', ic:'🎨', act:() => $('#themeT').click() },
    { l:'تماس با میزبان', ic:'✉', act:() => $('#contactSheet').showModal() },
    { l:'کنسیژ اُتا', ic:'💬', act:() => toggleConc(true) }
  ];
  const prods = PRODUCTS.filter(p => !q || (p.name + ' ' + p.sub + ' ' + p.cat).toLowerCase().includes(q));
  cmdkItems = cmds.filter(c => !q || c.l.toLowerCase().includes(q)).map(c => ({ type:'cmd', ...c }));
  cmdkItems.push(...prods.slice(0, 8).map(p => ({ type:'prod', p, k:p.id, l:p.name, sub:p.cat, img:p.img })));
  cmdkList.innerHTML = cmdkItems.length ? cmdkItems.map((it, i) => `<button class="cmdk__item${i === cmdkIdx ? ' sel' : ''}" type="button" data-i="${i}">
    <span class="ckl">${it.img ? `<img class="ckimg" src="${it.img}" alt="" width="38" height="47" loading="lazy">` : `<span style="font-size:1.2rem">${it.ic || '◆'}</span>`}<b>${esc(it.l)}</b></span>
    ${it.sub ? `<span class="m">${esc(it.sub)}</span>` : ''}
    </button>`).join('') : '<p class="cmdk__empty">نتیجه‌ای یافت نشد.</p>';
  $$('.cmdk__item', cmdkList).forEach(b => b.addEventListener('click', () => cmdkExec(+b.dataset.i)));
}
function cmdkExec(i) {
  const it = cmdkItems[i]; if (!it) return;
  cmdkClose();
  if (it.type === 'cmd') it.act();
  else if (it.type === 'prod') location.hash = '#/pdp/' + it.k;
}
cmdkInput && cmdkInput.addEventListener('input', e => { cmdkIdx = 0; cmdkRender(e.target.value); });
cmdkInput && cmdkInput.addEventListener('keydown', e => {
  if (e.key === 'ArrowDown') { e.preventDefault(); cmdkIdx = Math.min(cmdkIdx + 1, cmdkItems.length - 1); cmdkRender(cmdkInput.value); }
  if (e.key === 'ArrowUp')   { e.preventDefault(); cmdkIdx = Math.max(cmdkIdx - 1, 0); cmdkRender(cmdkInput.value); }
  if (e.key === 'Enter')     { e.preventDefault(); cmdkExec(cmdkIdx); }
});
cmdk && cmdk.addEventListener('close', () => {
  cmdk._opener && cmdk._opener.focus && cmdk._opener.focus({ preventScroll:true });
  cmdk._opener = null; dlStart();
});

/* ═══ Size guide / Measure wizard ═══ */
const sg = $('#sg');
function openSG() { sg._opener = document.activeElement; sg.showModal(); dlStop(); }
document.addEventListener('click', e => { const b = e.target.closest('.js-sg'); if (b) { e.preventDefault(); openSG(); } });
$('#sgX') && $('#sgX').addEventListener('click', () => sg.close());
backdropClose(sg);
sg && sg.addEventListener('close', () => {
  sg._opener && sg._opener.focus && sg._opener.focus({ preventScroll:true });
  sg._opener = null; dlStart();
});

const mz = $('#mz'); let mzStep = 0, mzCalc = null;
function openMZ() {
  mz._opener = document.activeElement; mzStep = 0; mzPaint();
  const c = getCustom();
  if (c) { $('#mzLen').value = c.L; $('#mzWid').value = c.W; }
  $('#mzLen').closest('.field').classList.remove('invalid');
  $('#mzWid').closest('.field').classList.remove('invalid');
  mz.showModal(); dlStop();
  requestAnimationFrame(() => $('#mzLen').focus());
}
document.addEventListener('click', e => { const b = e.target.closest('.js-mz'); if (b) { e.preventDefault(); openMZ(); } });
$('#mzX') && $('#mzX').addEventListener('click', () => mz.close());
backdropClose(mz);
mz && mz.addEventListener('close', () => {
  mz._opener && mz._opener.focus && mz._opener.focus({ preventScroll:true });
  mz._opener = null; dlStart();
});
function mzPaint() {
  $$('.mz__steps li', mz).forEach((li, i) => {
    li.classList.toggle('is-on', i === mzStep);
    li.classList.toggle('is-done', i < mzStep);
  });
  $('#mzProg').style.transform = `scaleX(${(mzStep+1)/3})`;
  $$('.mz__step', mz).forEach(s => s.classList.toggle('is-on', +s.dataset.mstep === mzStep));
}
$('#mzNext1') && $('#mzNext1').addEventListener('click', () => {
  const L = parseFloat($('#mzLen').value);
  const bad = !(L >= 20 && L <= 30);
  $('#mzLen').closest('.field').classList.toggle('invalid', bad);
  if (bad) { toast('طول پا باید بین ۲۰ تا ۳۰ سانتی‌متر باشد.', 'err'); return; }
  mzStep = 1; mzPaint(); $('#mzWid').focus();
});
$('#mzNext2') && $('#mzNext2').addEventListener('click', () => {
  const L = parseFloat($('#mzLen').value), W = parseFloat($('#mzWid').value);
  const badW = !(W >= 6 && W <= 13);
  $('#mzWid').closest('.field').classList.toggle('invalid', badW);
  if (badW) { toast('عرض پنجه باید بین ۶ تا ۱۳ سانتی‌متر باشد.', 'err'); return; }
  let eu = Math.round((L * 10 + 12)/6.667) - 14;
  const houseW = L / (1.618033988749895 * 1.618033988749895);
  const ratio = W / houseW;
  const wide = ratio > 1.06, narrow = ratio < .94;
  if (wide) eu += 0.5;
  eu = clamp(eu, 36, 46);
  mzCalc = { L, W, eu, wide, narrow, houseW };
  $('#mzEU').textContent = `${faNum(eu)} سفارشی`;
  $('#mzConv').textContent = `طول ${faNum(L)} · عرض ${faNum(W)} سانتی‌متر · پهنای خانه ${faNum(houseW.toFixed(2))} سانتی‌متر`;
  $('#mzNote').innerHTML = wide
    ? `پنجهٔ شما <b style="color:var(--accent)">${faNum(Math.round((ratio-1)*100))}٪ عریض‌تر</b> از پهنای خانه است — نیم‌سایز اضافه شد.`
    : narrow
    ? `پنجهٔ شما <b style="color:var(--accent)">${faNum(Math.round((1-ratio)*100))}٪ باریک‌تر</b> از پهنای خانه است — کفی نیم‌سایز رایگان.`
    : `پای شما دقیقاً با تناسب خانه هم‌خوان است.`;
  mzStep = 2; mzPaint();
});
$('#mzSave') && $('#mzSave').addEventListener('click', () => {
  if (!mzCalc) return;
  LS.set(K.custom, { L:mzCalc.L, W:mzCalc.W, eu:mzCalc.eu, wide:mzCalc.wide, at:Date.now() });
  mz.close();
  window.AE_PDP.renderCustomPanel();
  toast(`سایز ${faNum(mzCalc.eu)} سفارشی — ثبت و ذخیره شد.`);
});

/* ═══ Fit Wizard (DNA) ═══ */
const fitWizEl = $('#fitWiz');
let fitI = 0, fitData = {};
let __radarId = 0;
const radarSVG = memoize(function (axes, vals, size = 240) {
  const gid = 'rg' + (++__radarId).toString(36);
  const cx = size/2, cy = size/2, R = size*.38, n = axes.length;
  const points = r => axes.map((_, i) => {
    const a = i/n*2*Math.PI - Math.PI/2;
    return `${cx + Math.cos(a)*r},${cy + Math.sin(a)*r}`;
  }).join(' ');
  const valPoints = vals.map((v, i) => {
    const a = i/n*2*Math.PI - Math.PI/2;
    const r = R * Math.max(.06, v/100);
    return `${cx + Math.cos(a)*r},${cy + Math.sin(a)*r}`;
  }).join(' ');
  const rings = [.25,.5,.75,1];
  return `<svg viewBox="0 0 ${size} ${size}" aria-hidden="true" focusable="false">
    ${rings.map(k => `<polygon points="${points(R*k)}" fill="none" stroke="var(--border-2)" stroke-width=".7"/>`).join('')}
    ${axes.map((_, i) => {
      const a = i/n*2*Math.PI - Math.PI/2;
      return `<line x1="${cx}" y1="${cy}" x2="${cx + Math.cos(a)*R}" y2="${cy + Math.sin(a)*R}" stroke="var(--border)" stroke-width=".7"/>`;
    }).join('')}
    <polygon points="${valPoints}" fill="url(#${gid})" stroke="var(--accent)" stroke-width="1.6" opacity=".85"/>
    ${axes.map((t, i) => {
      const a = i/n*2*Math.PI - Math.PI/2;
      const x = cx + Math.cos(a)*(R+18), y = cy + Math.sin(a)*(R+18);
      return `<text x="${x}" y="${y}" fill="var(--text-3)" font-size="9.5" text-anchor="middle" dominant-baseline="middle" font-family="Vazirmatn">${esc(t)}</text>`;
    }).join('')}
    <defs><radialGradient id="${gid}"><stop offset="0" stop-color="color-mix(in oklch,var(--accent) 35%,transparent)"/><stop offset="1" stop-color="color-mix(in oklch,var(--accent) 8%,transparent)"/></radialGradient></defs>
  </svg>`;
});
function openFit() {
  fitI = 0;
  fitData = { foot:P.foot, use:P.use, arch:P.arch, style:P.style, size:P.size };
  fitWizEl.classList.add('on');
  html.classList.add('lock'); dlStop(); renderFit();
  overlayShow(fitWizEl);
}
function closeFit() {
  overlayHide(fitWizEl);
  fitWizEl.classList.remove('on');
  html.classList.remove('lock'); dlStart();
}
function renderFit() {
  $('#fitProg').innerHTML = FIT_STEPS.map((_, i) => `<i class="${i <= fitI ? 'done' : ''}"></i>`).join('');
  const st = FIT_STEPS[fitI];
  if (fitI < FIT_STEPS.length) {
    $('#fitStep').innerHTML = `<div class="fit-step"><h3>${st.title}</h3><span class="mono">${st.sub}</span>
      <div class="fit-opts">${st.opts.map(o => `<button class="fit-opt ${fitData[st.key] === o.v ? 'sel' : ''}" data-fitv="${o.v}">
        <span class="fo-ic">${o.ic || '•'}</span><span><b>${o.t || ''}</b>${o.s ? `<small>${o.s}</small>` : ''}</span></button>`).join('')}</div>
      <div class="fit-nav">${fitI > 0 ? '<button class="btn btn-ghost" data-act="fit-prev">قبلی</button>' : ''}
      <button class="btn btn-solid" data-act="fit-next" ${fitData[st.key] ? '' : 'disabled'}>ادامه</button></div></div>`;
    $$('#fitStep .fit-opt').forEach(b => b.addEventListener('click', () => { fitData[st.key] = b.dataset.fitv; renderFit(); }));
  } else {
    P.foot = fitData.foot; P.use = fitData.use; P.arch = fitData.arch; P.style = fitData.style; P.size = fitData.size;
    const s = fitData.style;
    P.dna = {
      minimal:s === 'minimal' ? 92 : 40,
      classic:s === 'classic' ? 92 : 45,
      bold:s === 'bold' ? 92 : 35,
      romantic:s === 'romantic' ? 92 : 40,
      casual:fitData.use === 'daily' ? 90 : 40
    };
    saveP();
    updateDnaChip();
    const top = [...PRODUCTS].sort((a, b) => smartScore(b) - smartScore(a)).slice(0, 3);
    const dnaAxes = ['مینیمال','کلاسیک','جسور','رمانتیک','روزمره'];
    const dnaVals = [P.dna.minimal, P.dna.classic, P.dna.bold, P.dna.romantic, P.dna.casual];
    $('#fitStep').innerHTML = `<div class="fit-result">
      <span class="mono">DNA سبک شما ذخیره شد</span>
      <div class="big">سایز ${faNum(P.size)}</div>
      <div class="dna-radar">${radarSVG(dnaAxes, dnaVals, 240)}</div>
      <p class="note">هوش اُرِل اکنون مجموعه را با DNA سبک شما هماهنگ می‌کند و سایز دقیق هر فرم را پیشنهاد می‌دهد.</p>
      <p class="mono" style="margin-bottom:1rem">پیشنهاد ما برای شما:</p>
      <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">${top.map(p => `<button class="chip" data-fitgo="${p.id}">${esc(p.name)}</button>`).join('')}</div>
      <div class="fit-nav"><button class="btn btn-solid btn-block" data-act="fit-done">دیدن مجموعه با DNA من</button></div></div>`;
    $$('#fitStep [data-fitgo]').forEach(b => b.addEventListener('click', () => { closeFit(); location.hash = '#/pdp/' + b.dataset.fitgo; }));
  }
}
if (fitWizEl) {
  trapFocus(fitWizEl, closeFit);
  fitWizEl.addEventListener('click', e => { if (e.target === fitWizEl) closeFit(); });
}
function updateDnaChip() {
  const svg = $('#dnaMiniSvg'), txt = $('#dnaChipTxt'); if (!svg) return;
  const axes = [P.dna.minimal, P.dna.classic, P.dna.bold, P.dna.romantic, P.dna.casual];
  const cx = 30, cy = 30, R = 20, n = 5;
  const pts = axes.map((v, i) => {
    const a = i/n*2*Math.PI - Math.PI/2;
    const r = R * Math.max(.1, v/100);
    return `${cx + Math.cos(a)*r},${cy + Math.sin(a)*r}`;
  }).join(' ');
  const ring = axes.map((_, i) => {
    const a = i/n*2*Math.PI - Math.PI/2;
    return `${cx + Math.cos(a)*R},${cy + Math.sin(a)*R}`;
  }).join(' ');
  svg.innerHTML = `<polygon points="${ring}" fill="none" stroke="currentColor" stroke-width=".6" opacity=".4"/><polygon points="${pts}" fill="currentColor" fill-opacity=".3" stroke="currentColor" stroke-width="1"/><circle cx="${cx}" cy="${cy}" r="1.5" fill="currentColor"/>`;
  if (P.style && txt) {
    const names = { minimal:'مینیمال', classic:'کلاسیک', bold:'جسور', romantic:'رمانتیک' };
    txt.textContent = `DNA · ${names[P.style] || '—'}`;
  }
}
updateDnaChip();
addEventListener('ae:open-fit', openFit);

/* ═══ Compare ═══ */
const cmpBar = $('#cmpBar'), cmpThumbs = $('#cmpThumbs'), cmpDialog = $('#cmpDialog'), cmpGrid = $('#cmpGrid'), cmpRadar = $('#cmpRadar');
function cmpPaint() {
  if (!cmpBar) return;
  if (state.cmp.length === 0) { cmpBar.classList.remove('show'); return; }
  cmpBar.classList.add('show');
  cmpThumbs.innerHTML = state.cmp.map(id => {
    const p = CATALOG[id]; if (!p) return '';
    const { shoeSVG } = window.AE_CONC;
    return `<span class="thm">${shoeSVG(p, 0)}</span>`;
  }).join('');
}
function cmpOpen() {
  if (!cmpDialog) return;
  cmpDialog.classList.add('on');
  html.classList.add('lock'); dlStop(); cmpRender();
  overlayShow(cmpDialog);
}
function cmpClose() {
  if (!cmpDialog || !cmpDialog.classList.contains('on')) return;
  overlayHide(cmpDialog);
  cmpDialog.classList.remove('on');
  html.classList.remove('lock'); dlStart();
}
if (cmpDialog) {
  trapFocus(cmpDialog, cmpClose);
  cmpDialog.addEventListener('click', e => { if (e.target === cmpDialog) cmpClose(); });
}
function cmpRender() {
  const prods = state.cmp.map(id => CATALOG[id]);
  const rows = [
    { k:'قیمت', v:p => moneyT(p.price) },
    { k:'دسته', v:p => p.cat.split('·')[0].trim() },
    { k:'ارتفاع', v:p => faNum(p.heel) + ' میلی‌متر' },
    { k:'امتیاز', v:p => faNum(p.rating.toFixed(1)) + ' ★' },
    { k:'دیدگاه', v:p => faNum(p.reviews) },
    { k:'موجودی', v:p => p.stock <= 3 ? `فقط ${faNum(p.stock)}` : 'در آتلیه' }
  ];
  cmpGrid.innerHTML = `<div class="cmp-cell h"><span class="lbl">محصول</span></div>` +
    prods.map(p => `<div class="cmp-cell"><img src="${p.img}" alt="${esc(p.name)}" width="120" height="150" loading="lazy"><b style="font-family:var(--serif-d);font-size:1.1rem;margin-top:.5rem">${esc(p.name)}</b></div>`).join('') +
    rows.map(r => `<div class="cmp-cell h"><span class="lbl">${r.k}</span></div>` +
      prods.map(p => `<div class="cmp-cell">${r.v(p)}</div>`).join('')).join('');
  cmpRadar.innerHTML = prods.map(p => `<div class="cmp-radar-card"><h4>${esc(p.name)}</h4><div class="radar-wrap" id="radar-${p.id}"></div></div>`).join('');
  prods.forEach(p => {
    const dna = {
      minimal:p.family === 'loafer' ? 70 : 30,
      classic:p.family === 'maryjane' ? 75 : 40,
      bold:p.heel >= 80 ? 80 : 35,
      romantic:p.badge === 'سفارشی' ? 90 : 45,
      casual:p.family === 'ballet' ? 85 : 50
    };
    const host = $(`#radar-${p.id}`);
    if (host) host.innerHTML = radarSVG(['مینیمال','کلاسیک','جسور','رمانتیک','روزمره'], [dna.minimal, dna.classic, dna.bold, dna.romantic, dna.casual], 220);
  });
}

/* ═══ Action delegation ═══ */
document.addEventListener('click', e => {
  const t = e.target.closest('[data-act]'); if (!t) return;
  const act = t.dataset.act;
  /* لینک‌های واقعی با href — رفتار پیش‌فرض ناوبری حفظ شود (دکمه‌های بدون href استثنا هستند) */
  if (t.tagName === 'A' && t.getAttribute('href') && !['home', 'idx'].includes(act)) return;
  switch (act) {
    case 'menu': setMnav(!body.classList.contains('mnav-on')); break;
    case 'home': {
      e.preventDefault();
      if (html.getAttribute('data-mode') !== 'boutique') setMode('boutique');
      else {
        const lenis = window.AE.lenis();
        if (lenis) lenis.scrollTo(0, { duration:1.2 });
        else scrollTo({ top:0, behavior: reduced ? 'auto' : 'smooth' });
      }
      break;
    }
    case 'cart-open': if (body.classList.contains('mnav-on')) setMnav(false); openBag(); break;
    case 'wish-open': openWish(); break;
    case 'prof-open': openProfile(); break;
    case 'cmd': cmdkOpen(); break;
    case 'dna-open': openFit(); break;
    case 'dna-close': closeFit(); break;
    case 'fit-prev': fitI = Math.max(0, fitI - 1); renderFit(); break;
    case 'fit-next': fitI++; renderFit(); break;
    case 'fit-done':
      closeFit();
      state.sort = 'smart';
      apply();
      scrollToFilters(120);
      toast('مجموعه با DNA شما مرتب شد');
      break;
    case 'conc-toggle': toggleConc(); break;
    case 'conc-close': toggleConc(false); break;
    case 'conc-send': break;   /* handled by concierge.js */
    case 'cmp-open':
      if (state.cmp.length < 2) { toast('حداقل ۲ محصول برای مقایسه لازم است.', 'err'); haptic('warn'); break; }
      cmpOpen(); break;
    case 'cmp-close': cmpClose(); break;
    case 'cmp-clear':
      state.cmp = [];
      $$('[data-act="cmp-add"]').forEach(b => { b.classList.remove('on'); b.setAttribute('aria-pressed', 'false'); });
      cmpPaint(); break;
    case 'idx': {
      const cat = t.dataset.cat;
      state.fam = cat;
      window.AE_UI.renderFamChips();
      apply();
      if (html.getAttribute('data-mode') !== 'boutique') setMode('boutique');
      scrollToFilters(80);
      break;
    }
  }
});

/* Compare add/remove toggle */
document.addEventListener('click', e => {
  const add = e.target.closest('[data-act="cmp-add"]');
  if (!add) return;
  const id = add.dataset.id;
  if (state.cmp.includes(id)) {
    state.cmp = state.cmp.filter(x => x !== id);
    add.classList.remove('on');
    add.setAttribute('aria-pressed', 'false');
    toast('از مقایسه حذف شد.');
  } else if (state.cmp.length < 3) {
    state.cmp.push(id);
    add.classList.add('on');
    add.setAttribute('aria-pressed', 'true');
    toast(`${CATALOG[id].name} — به مقایسه اضافه شد.`);
  } else toast('حداکثر ۳ محصول قابل مقایسه است.', 'err');
  cmpPaint();
});

/* Dock */
(() => {
  const wire = (id, fn) => {
    const b = document.getElementById(id);
    if (!b || b.dataset.wired) return;
    b.dataset.wired = '1';
    b.addEventListener('click', e => { e.preventDefault(); fn(); });
  };
  wire('dockWish', openWish);
  wire('dockBag', openBag);
  wire('dockProf', () => openProfile());
  wire('dockMenu', () => setMnav(!body.classList.contains('mnav-on')));
})();

/* ═══ Shortcuts ═══ */
addEventListener('keydown', e => {
  if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
    e.preventDefault();
    if (cmdk.open) cmdkClose(); else cmdkOpen();
    return;
  }
  if (e.key === 'Escape') {
    if (body.classList.contains('mnav-on')) setMnav(false);
    const themeMenu = $('#themeMenu'); themeMenu.classList.remove('on');
    const cmpD = $('#cmpDialog');
    if (cmpD && cmpD.classList.contains('on')) { cmpClose(); return; }
    const fitWiz = $('#fitWiz');
    if (fitWiz && fitWiz.classList.contains('on')) { closeFit(); return; }
  }
});

/* ═══ Contact form ═══ */
$('#cForm') && $('#cForm').addEventListener('submit', e => {
  e.preventDefault();
  const f = e.target; let ok = true;
  [['fName', false], ['fMail', true], ['fMsg', false]].forEach(([id, isMail]) => {
    const el = f.querySelector('#' + id), field = el.closest('.field');
    const bad = isMail ? !el.checkValidity() : !el.value.trim();
    field.classList.toggle('invalid', bad);
    el.setAttribute('aria-invalid', String(bad));
    if (bad) ok = false;
  });
  if (!ok) { toast('چند فیلد هنوز منتظر شماست.', 'err'); return; }
  const btn = f.querySelector('button[type=submit]');
  withLoad(btn, 1100);
  setTimeout(() => { f.reset(); toast('درخواست دریافت شد — میزبان ظرف ۲۴ ساعت پاسخ می‌دهد.'); }, 1100);
});
$$('#cForm input,#cForm textarea').forEach(el => el.addEventListener('input', () => {
  el.closest('.field').classList.remove('invalid');
  el.removeAttribute('aria-invalid');
}));
$('#nlForm') && $('#nlForm').addEventListener('submit', e => {
  e.preventDefault();
  const i = $('#nlEmail');
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(i.value)) {
    toast('نامه دریافت شد. کد VELORA10 در اولین نامه.');
    i.value = '';
  } else toast('لطفاً یک ایمیل معتبر وارد کنید.', 'err');
});

/* ═══ Boot sync ═══ */
function bootSync() {
  /* ترمیم ناهمگامی ذخیره‌سازی: اسکریپت in-head با localStorage.setItem خام
     می‌نویسد و JSON.stringify دوبار کوئوت می‌کند؛ پس اینجا با getRaw بخوان. */
  const curT = LS.getRaw(K.theme, null) || html.getAttribute('data-theme') || 'nuit';
  const curS = LS.getRaw(K.scene, null) || html.getAttribute('data-scene') || 'night';
  $$('[data-theme-set]').forEach(b => b.classList.toggle('on', b.dataset.themeSet === curT));
  $$('[data-scene-set]').forEach(b => b.classList.toggle('on', b.dataset.sceneSet === curS));
  document.querySelector('meta[name=theme-color]')?.setAttribute('content', curT === 'ivoire' ? '#F6F2E8' : '#08090F');
  const savedMode = LS.getRaw(K.mode, 'boutique');
  if (savedMode === 'atelier') {
    html.setAttribute('data-mode', 'atelier');
    body.dataset.mode = 'atelier';
    $('#view-home').hidden = true; $('#view-home').setAttribute('inert', '');
    $('#view-atelier').hidden = false; $('#view-atelier').removeAttribute('inert');
    $$('[data-act="mode"]').forEach(b => {
      const on = b.dataset.mode === 'atelier';
      b.classList.toggle('on', on);
      b.setAttribute('aria-selected', String(on));
    });
    window.dispatchEvent(new CustomEvent('ae:render-atelier'));
    window.dispatchEvent(new CustomEvent('ae:mode-change', { detail: { mode: 'atelier' } }));
  }
}
bootSync();

/* ═══ Touch — drag sheets, swipe gallery, double-tap wish ═══ */
(() => {
  if (!coarse) return;
  ['bag','wishd','sg','mz','profile','passport','cko','sheet'].forEach(id => {
    const d = document.getElementById(id);
    if (d && !$('.lux-grab', d)) {
      const h = document.createElement('div');
      h.className = 'lux-grab';
      d.prepend(h);
    }
  });
  $$('dialog.bag,dialog.wishd,dialog.sg,dialog.mz,dialog.profile,dialog.passport,dialog.cko,dialog.sheet').forEach(d => {
    const HANDLE = '.lux-grab,.bag__head,.contact__head,.sheet__head,.pdp__bar';
    let y0 = 0, dy = 0, drag = false, vy = 0, ly = 0, lt = 0, raf = 0;
    const paint = () => {
      const shown = dy <= 180 ? dy : 180 + (dy - 180) * 0.32;
      d.style.transform = `translate3d(0,${shown.toFixed(1)}px,0)`;
    };
    const loop = () => { raf = 0; if (drag) { paint(); raf = requestAnimationFrame(loop); } };
    const start = e => {
      if (!e.target.closest(HANDLE)) return;
      drag = true; y0 = e.clientY; dy = 0; vy = 0; ly = y0; lt = performance.now();
      d.classList.add('lux-dragging');
      try { d.setPointerCapture(e.pointerId); } catch(_){}
      if (!raf) raf = requestAnimationFrame(loop);
    };
    const move = e => {
      if (!drag) return;
      const now = performance.now();
      vy = (e.clientY - ly)/Math.max(1, now - lt);
      ly = e.clientY; lt = now;
      dy = Math.max(0, e.clientY - y0);
    };
    const end = () => {
      if (!drag) return;
      drag = false;
      cancelAnimationFrame(raf); raf = 0;
      d.classList.remove('lux-dragging');
      d.style.transform = '';
      if (dy > 130 || vy > 0.55) {
        window.AE.buzz(10);
        try { d.close(); } catch(_){}
        dy = 0; vy = 0;
      }
    };
    d.addEventListener('pointerdown', start);
    d.addEventListener('pointermove', move, { passive:true });
    d.addEventListener('pointerup', end);
    d.addEventListener('pointercancel', end);
    d.addEventListener('close', () => { drag = false; cancelAnimationFrame(raf); raf = 0; d.classList.remove('lux-dragging'); });
  });

  const stage = $('#pdpStage');
  if (stage) {
    let sx = 0, sy = 0, t0 = 0, sup = false;
    stage.addEventListener('touchstart', e => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; t0 = Date.now(); sup = false; }, { passive:true });
    stage.addEventListener('touchend', e => {
      const dx = e.changedTouches[0].clientX - sx;
      const dyv = e.changedTouches[0].clientY - sy;
      if (Math.abs(dx) > 42 && Math.abs(dx) > Math.abs(dyv)*1.25) sup = true;
      if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dyv)*1.4 && Date.now() - t0 < 600) {
        const th = $$('#pdpThumbs .pdp__thumb');
        if (!th.length) return;
        const cur = th.findIndex(t => t.getAttribute('aria-current') === 'true');
        const nx = (dx < 0 ? cur + 1 : cur - 1 + th.length) % th.length;
        th[nx]?.click(); window.AE.buzz(6);
      }
    }, { passive:true });
    stage.addEventListener('click', e => { if (sup) { e.preventDefault(); e.stopPropagation(); sup = false; } }, true);
  }

  let lastTap = 0, lastEl = null;
  document.addEventListener('touchend', e => {
    const m = e.target.closest('.prod-media'); if (!m) return;
    const now = Date.now();
    if (now - lastTap < 320 && lastEl === m) {
      const w = m.closest('.prod')?.querySelector('.wish');
      if (w && !w.classList.contains('on')) { w.click(); window.AE.buzz([8,40,14]); }
      lastTap = 0;
    } else { lastTap = now; lastEl = m; }
  }, { passive:true });
})();

/* ═══ Luxe layer ═══ */
(() => {
  html.setAttribute('data-luxe', '3');

  /* View Transitions for theme changes */
  try {
    if ('startViewTransition' in document && !reduced) {
      document.addEventListener('click', e => {
        if (e.__luxvt || e.defaultPrevented) return;
        const t = e.target.closest('[data-theme-set],[data-scene-set],[data-act="mode"]');
        if (!t) return;
        e.preventDefault(); e.stopPropagation();
        const vt = document.startViewTransition(() => {
          const ev = new MouseEvent('click', { bubbles:true, cancelable:true });
          ev.__luxvt = true;
          t.dispatchEvent(ev);
        });
        vt.ready.then(() => {
          html.animate({ opacity:[.85, 1] }, { duration:600, easing:'cubic-bezier(.77,0,.175,1)' });
        });
      }, true);
    }
  } catch(_){}

  /* Spotlight on cards */
  try {
    const SPOT = '.selcard,.kpi,.bq,.bench__c,.rev-item,.bag-item,.prof-card,.tip,.at-card,.l-card,.form,.cpw-calc,.frame,.cert';
    if (fine && !reduced) {
      document.addEventListener('pointermove', e => {
        if (window.AE.lowTier()) return;
        const el = e.target.closest(SPOT); if (!el) return;
        const r = el.getBoundingClientRect();
        el.style.setProperty('--mx', (((e.clientX - r.left)/r.width)*100).toFixed(2) + '%');
        el.style.setProperty('--my', (((e.clientY - r.top)/r.height)*100).toFixed(2) + '%');
      }, { passive:true });
      document.addEventListener('pointerover', e => {
        const el = e.target.closest(SPOT);
        if (el) el.classList.add('lux-lit');
      }, { passive:true });
      document.addEventListener('pointerout', e => {
        const el = e.target.closest(SPOT);
        if (el && !el.contains(e.relatedTarget)) el.classList.remove('lux-lit');
      }, { passive:true });
    }
  } catch(_){}

  /* Magnetic buttons */
  try {
    if (fine && !reduced) {
      document.addEventListener('pointerover', e => {
        const b = e.target.closest('.btn-solid,.btn-royal');
        if (!b || b.__luxmag || b.disabled || window.AE.lowTier()) return;
        b.__luxmag = true;
        const move = ev => {
          const r = b.getBoundingClientRect();
          const dx = (ev.clientX - r.left - r.width/2)/r.width;
          const dy = (ev.clientY - r.top - r.height/2)/r.height;
          const dist = Math.sqrt(dx*dx + dy*dy);
          if (dist < 0.6) b.style.transform = `translate(${(dx*8).toFixed(2)}px,${(dy*6).toFixed(2)}px)`;
        };
        const leave = () => {
          b.style.transition = 'transform .65s cubic-bezier(.34,1.56,.64,1)';
          b.style.transform = '';
          setTimeout(() => { b.style.transition = ''; }, 660);
          b.removeEventListener('pointermove', move);
          b.removeEventListener('pointerleave', leave);
          b.__luxmag = false;
        };
        b.addEventListener('pointermove', move, { passive:true });
        b.addEventListener('pointerleave', leave);
      }, { passive:true });
    }
  } catch(_){}

  /* Golden burst */
  try {
    const burst = (x, y, n) => {
      if (reduced || window.AE.lowTier()) return;
      const count = Math.min(n, coarse ? 8 : n);
      for (let i = 0; i < count; i++) {
        const s = document.createElement('span');
        s.className = 'lux-spark';
        s.style.left = x + 'px'; s.style.top = y + 'px';
        const a = Math.random()*Math.PI*2;
        const d = 26 + Math.random()*46;
        s.style.setProperty('--sx', (Math.cos(a)*d).toFixed(1) + 'px');
        s.style.setProperty('--sy', (Math.sin(a)*d - 24).toFixed(1) + 'px');
        document.body.appendChild(s);
        setTimeout(() => s.remove(), 850);
      }
    };
    document.addEventListener('click', e => {
      const w = e.target.closest('.wish');
      if (w && w.classList.contains('on')) {
        const r = w.getBoundingClientRect();
        burst(r.left + r.width/2, r.top + r.height/2, 12);
        return;
      }
      const add = e.target.closest('.js-add');
      if (add && !add.disabled) {
        const r = add.getBoundingClientRect();
        burst(r.left + r.width/2, r.top + r.height/2, 14);
      }
    }, { passive:true });
  } catch(_){}

  /* Hero guilloche */
  try {
    const heroSection = $('.hero');
    if (heroSection && !reduced && !window.AE.lowTier()) {
      const { guilloche } = window.AE_PDP;
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 1200 800');
      svg.setAttribute('aria-hidden', 'true');
      svg.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;opacity:.08;z-index:0;';
      const sets = [[600,400,380,147,180,7,600],[600,400,280,93,140,5,500]];
      svg.innerHTML = sets.map(s => {
        const g = guilloche(s[0], s[1], s[2], s[3], s[4], s[5], s[6]);
        return `<path d="${g.d}" fill="none" stroke="var(--accent)" stroke-width=".6" opacity=".7"/>`;
      }).join('');
      heroSection.insertBefore(svg, heroSection.firstChild);
    }
  } catch(_){}

  /* FAB first-seen */
  try {
    const fab = $('#concFab');
    if (fab) fab.addEventListener('click', () => fab.classList.add('seen'), { once:true });
  } catch(_){}
})();

/* ═══ Phase 2 — network + images + metrics ═══ */
(() => {
  const conn = navigator.connection || {};
  const saveData = !!conn.saveData || /(^|-)2g/.test(conn.effectiveType || '');
  let tier = html.classList.contains('p2-low') ? 'low' : html.classList.contains('p2-mid') ? 'mid' : 'high';
  let degraded = false;
  function degrade() {
    if (degraded || tier === 'low') return;
    degraded = true; tier = 'low';
    html.classList.remove('p2-mid','p2-high');
    html.classList.add('p2-low');
    document.getElementById('dust')?.remove();
    document.querySelector('.motes')?.remove();
    document.querySelector('.grain')?.remove();
  }
  try {
    let load = 0;
    TIMERS.set(() => { load = 0; }, 2200, 'longtask');
    new PerformanceObserver(list => {
      if (document.hidden) return;
      for (const e of list.getEntries()) load += e.duration;
      if (load > 420) degrade();
    }).observe({ entryTypes:['longtask'] });
  } catch(_){}
  try { conn.addEventListener && conn.addEventListener('change', () => { if (conn.saveData) degrade(); }); } catch(_){}

  /* Network toast */
  const p2 = $('#p2toast');
  let p2T = null;
  function p2toast(text, kind = '') {
    if (!p2) return;
    const tx = $('.p2tx', p2);
    if (tx) tx.textContent = text;
    p2.className = kind;
    p2.classList.add('on');
    clearTimeout(p2T);
    p2T = setTimeout(() => p2.classList.remove('on'), kind === 'off' ? 6000 : 3600);
  }
  addEventListener('offline', () => {
    html.classList.add('p2-off');
    p2toast('شما آفلاین هستید — سبد و علاقه‌مندی‌های شما محفوظ است.', 'off');
  }, { passive:true });
  addEventListener('online', () => {
    html.classList.remove('p2-off');
    p2toast('دوباره آنلاین شدید.', 'ok');
  }, { passive:true });
  if (!navigator.onLine) html.classList.add('p2-off');
  if (saveData) {
    html.classList.add('p2-low');
    degrade();
    TIMERS.once(() => p2toast('حالت کم‌داده روشن است — انیمیشن‌های تزئینی غیرفعال شدند.', ''), 2600, 'p2:sd');
  }

  /* Service Worker */
if ('serviceWorker' in navigator) {
  const hadController = !!navigator.serviceWorker.controller;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController) return;
    if (safeSession('ae.reloaded')) return;
    safeSession('ae.reloaded', '1');
    location.reload();
  });
  const regSW = () => {
    navigator.serviceWorker.register('./sw.js', { scope: './' })
      .catch(err => console.warn('[Aurelle] SW register failed', err));
  };
  if (document.readyState === 'complete') regSW();
  else addEventListener('load', regSW, { once: true });
}
  /* Adaptive images */
  const UNS = 'images.unsplash.com';
  const presets = img => {
    if (img.id === 'mirrorImg')      return { w:[640,960,1280], q:82, sizes:'(max-width:1000px) 92vw, 460px' };
    if (img.closest('.pdp__stage'))  return { w:[480,800,1120], q:80, sizes:'(max-width:979px) 96vw, 55vw' };
    if (img.closest('.prod-media'))  return { w:[420,680,980], q:78, sizes:'(max-width:760px) 94vw, 340px' };
    if (img.closest('.selcard__m'))  return { w:[360,560,820], q:78, sizes:'(max-width:760px) 72vw, 300px' };
    if (img.closest('.lb-item'))     return { w:[420,680,980], q:78, sizes:'(max-width:760px) 78vw, 400px' };
    if (img.closest('.frame'))       return { w:[480,800,1100], q:80, sizes:'(max-width:1000px) 92vw, 460px' };
    if (img.closest('#pdpRel'))      return { w:[320,520], q:75, sizes:'(max-width:640px) 94vw, 32vw' };
    if (img.closest('#pdpThumbs'))   return { w:[132,198], q:70, sizes:'66px' };
    if (img.closest('.bag-item'))    return { w:[160,240], q:70, sizes:'76px' };
    if (img.classList.contains('ckimg')) return { w:[76,152], q:65, sizes:'38px' };
    return { w:[420,800], q:76, sizes:'(max-width:760px) 94vw, 480px' };
  };
  function upgrade(img) {
    if (!img || img.dataset.p2img) return;
    const src = img.currentSrc || img.getAttribute('src') || '';
    if (src.indexOf(UNS) === -1) return;
    img.dataset.p2img = '1';
    const base = src.split('?')[0];
    let p = presets(img);
    if (saveData || tier === 'low') p = { w:[520], q:45, sizes:'94vw' };
    img.srcset = p.w.map(w => base + '?auto=format&fit=crop&w=' + w + '&q=' + p.q + ' ' + w + 'w').join(', ');
    img.sizes = p.sizes;
    if (!saveData && tier !== 'low' && !img.closest('.bag-item') && !img.classList.contains('ckimg')) {
      const lq = base + '?auto=format&fit=crop&w=28&q=10';
      const probe = new Image(); probe.src = lq;
      probe.addEventListener('load', () => {
        if (img.complete) return;
        img.style.backgroundImage = 'url("' + lq + '")';
        img.style.backgroundSize = 'cover';
        img.classList.add('p2-lqip');
      }, { once:true });
    }
  }
  const pdpImg = document.getElementById('pdpImg');
  if (pdpImg) {
    new MutationObserver(() => {
      delete pdpImg.dataset.p2img;
      upgrade(pdpImg);
    }).observe(pdpImg, { attributes:true, attributeFilter:['src'] });
  }
  document.querySelectorAll('img').forEach(upgrade);
  new MutationObserver(muts => {
    for (const m of muts) for (const n of m.addedNodes) {
      if (n.nodeType !== 1) continue;
      if (n.tagName === 'IMG') upgrade(n);
      else if (n.querySelectorAll) n.querySelectorAll('img').forEach(upgrade);
    }
  }).observe(document.body, { childList:true, subtree:true });

  /* Web vitals */
  try {
    if ('PerformanceObserver' in window) {
      const vitals = { lcp:0, cls:0, inp:0 };
      new PerformanceObserver(l => {
        const es = l.getEntries(); const e = es[es.length - 1];
        vitals.lcp = Math.round(e.startTime);
      }).observe({ type:'largest-contentful-paint', buffered:true });
      new PerformanceObserver(l => {
        for (const e of l.getEntries()) if (!e.hadRecentInput) vitals.cls += e.value;
      }).observe({ type:'layout-shift', buffered:true });
      new PerformanceObserver(l => {
        const es = l.getEntries(); const e = es[es.length - 1];
        vitals.inp = Math.round(e.duration);
      }).observe({ type:'event', buffered:true, durationThreshold: 40 });

      /* FPS HUD (?fps=1) */
      const showFps = /[?&]fps=1/.test(location.search);
      if (showFps) {
        const hud = document.createElement('div');
        hud.id = 'fpsHud';
        hud.style.cssText = 'position:fixed;z-index:2147483647;inset-block-start:calc(env(safe-area-inset-top,0px) + 4px);inset-inline-end:4px;padding:6px 10px;border-radius:10px;background:#000c;color:#0f0;font:600 12px/1.5 ui-monospace,monospace;pointer-events:none;white-space:pre;text-align:end';
        document.body.appendChild(hud);
        let frames = 0, longFrames = 0, last = performance.now(), winStart = last;
        const loop = t => {
          const dt = t - last; last = t;
          if (dt > 0) { frames++; if (dt > 20) longFrames++; }
          if (t - winStart >= 1000) {
            const fps = Math.round(frames * 1000 / (t - winStart));
            hud.textContent = `${faNum(fps)} fps\nافت: ${faNum(longFrames)}\n${faNum(Math.round(longFrames / Math.max(1, frames) * 100))}٪`;
            frames = 0; longFrames = 0; winStart = t;
          }
          RAF.add(loop);
        };
        RAF.add(loop);
      }

      addEventListener('load', () => {
        const nav = performance.getEntriesByType('navigation')[0];
        const phi = $('.f-phi');
        if (!phi || !nav) return;
        const ms = Math.round(nav.loadEventEnd || performance.now());
        const t = document.createElement('span');
        t.className = 'phi-perf';
        t.textContent = `بارگذاری ${faNum(ms)} میلی‌ثانیه · LCP ${faNum(vitals.lcp)}`;
        phi.appendChild(t);
      }, { once:true });
    }
  } catch(_){}
})();

/* ═══ Pause infinite animations offscreen ═══ */
(() => {
  const SELECTOR = '.aurora i, .grain, .motes i, .tk, .ticker__track, .press__track, .live-dot, .chat-fab__pulse';
  const NEVER_FREEZE = '.hdr, .rail, #toTop, .dock, .annonce, #prog';
  const freeze = el => el && el.classList.add('anim-paused');
  const thaw   = el => el && el.classList.remove('anim-paused');
  let io;
  try {
    io = new IntersectionObserver(entries => {
      for (const e of entries) {
        const el = e.target;
        if (el.matches(NEVER_FREEZE)) continue;
        const host = el.classList.contains('motes') ? el : (el.parentElement || el);
        if (e.isIntersecting) thaw(host); else freeze(host);
      }
    }, { rootMargin: '120px 0px', threshold: 0 });
    $$(SELECTOR).forEach(el => {
      if (el.matches(NEVER_FREEZE)) return;
      io.observe(el.parentElement && el.parentElement !== document.body ? el.parentElement : el);
    });
  } catch(_){}
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { io && io.disconnect(); $$(SELECTOR).forEach(freeze); }
    else { $$(SELECTOR).forEach(thaw); }
  });
})();
function safeSession(k, v) {
  try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); }
  catch (_) { return null; }
}
/* ═══ Bootstrap ═══ */
handleRoute();
apply();          /* ✅ شمارش و pills اولیه */
renderFamChips(); /* ✅ از window.AE_UI هم می‌آید ولی صریح بهتر است */

/* ═══ Header search ═══ */
(() => {
  const hdrQ = $('#hdrQ'); if (!hdrQ) return;
  const sync = debounce(() => {
    state.q = hdrQ.value;
    const vs = $('#vSearch'); if (vs) vs.value = hdrQ.value;
    if (html.getAttribute('data-mode') !== 'boutique') setMode('boutique');
    apply();
  }, 200);
  hdrQ.addEventListener('input', sync);
  hdrQ.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); sync(); } });
  hdrQ.addEventListener('focus', () => { if (innerWidth < 768) scrollToFilters(0); });
})();

$$('[data-theme-set]').forEach(b => b.classList.toggle('on', b.dataset.themeSet === html.getAttribute('data-theme')));
$$('[data-scene-set]').forEach(b => b.classList.toggle('on', b.dataset.sceneSet === html.getAttribute('data-scene')));
$$('[data-act="mode"]').forEach(b => b.classList.toggle('on', b.dataset.mode === html.getAttribute('data-mode')));

/* ═══ لایهٔ مدیریت ═══
   ویترین با data.js بالا آمده (بدون انتظار سرور). اگر پنل مدیریت چیزی
   ذخیره کرده باشد، این لایه روی همان کاتالوگ می‌نشیند. بی‌صدا: اگر سرور
   نبود یا انباره خالی بود، هیچ چیز تغییر نمی‌کند. */
if (window.AE_SYNC) AE_SYNC.run(false);

/* دسترسی به پنل: پرچم مدیریت که از سرور می‌آید */
window.addEventListener('ae:session-admin', () => {
  const btn = $('#profAdmin');
  if (btn) btn.hidden = !(window.AE_AUTH && AE_AUTH.isAdmin());
});

console.log('%c ◆ خانهٔ اُرِل — Modular Build v4.0 · Golden ◆ ',
  'background:linear-gradient(115deg,#f6e7ab,#d4af37,#875f10);color:#080604;padding:.5rem 1.2rem;font-family:Georgia;font-size:14px;letter-spacing:.1em');
})();