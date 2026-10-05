/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE — main.js
   Grid · Routing · Action delegation · Command palette · Size guide ·
   Measure wizard · Touch · Luxe layer · Network & metrics · Boot

   ─── What this file is allowed to touch ──────────────────────────────────
   Every selector below was verified to exist in index.php before it was
   written. That rule is the whole reason this file is now 40% shorter than
   it was: it used to wire a large amount of behaviour to markup that does not
   exist, and one of those wirings threw on load.

   That throw was `backdropClose($('#contactSheet'))` — #contactSheet has no
   markup, contactSheet was null, and backdropClose has no null guard, so
   main.js died at line 409 of 1343. A classic script that throws executes
   nothing after the throw, so 70% of this file never ran: the [data-act]
   delegation, the dock, Ctrl-K, the command palette, the size guide, the
   measure wizard, the service-worker registration, the web-vitals observers,
   the offscreen animation pausing, the router bootstrap and the header search
   were all dead code that only LOOKED alive. The visible symptom was that the
   shop had no way to open its own basket: every openBag()/openWish() call site
   was below the throw, so the bag badge filled and led nowhere.

   Removed rather than null-guarded, because null-guarding a feature with no
   markup is keeping a feature with no markup:
     · #selRail / #selPrev / #selNext  selection rail (and its hard-coded list
       of six product ids, none of which exists in the catalogue)
     · #exitd + #exitX/#exitNo/#exitVault   exit-intent dialog
     · #contactSheet + #contactX           contact dialog
     · #cForm / #fName / #fMail / #fMsg   contact form
     · #nlForm / #nlEmail                 newsletter form (and its VELORA10
       code, which is not a code validate_voucher() knows)
     · #fitWiz / #fitStep / #fitProg / #dnaMiniSvg / #dnaChipTxt  DNA wizard
     · #cmpBar / #cmpThumbs / #cmpDialog / #cmpGrid / #cmpRadar  compare
     · #idxRow / #famChips / #vCount / #activePills / #vSearch /
       #vSort / #vPrice / #vPriceOut / #vEta / #recent / #recentChips
       filter toolbar — #grid survives, the toolbar around it does not
     · #profAdmin                          admin button in the profile dialog
     · the review renderer and the Reviews object: reachable only through an
       `ae:render-reviews` event that nothing dispatches. The PDP uses
       pdp-aurelle.js's own renderReviews, which is a near-duplicate of it.

   Kept and fixed:
     · the grid is no longer re-rendered on boot. index.php server-renders it
       from the same records the order endpoint prices from, with crawlable
       ?product= links, eager loading and fetchpriority on the LCP card — and
       this file threw all of that away on the first frame.
     · the per-card pointermove handler read getBoundingClientRect() once per
       card per mousemove. One rAF-batched pass now, one rect read per frame.
     · the offscreen animation observer disconnected itself on tab-hide and
       never reconnected, so every marquee and shimmer ran forever after.
     · `data-act="mode"` and `data-act="auth"` had no case in the delegation
       switch at all, which is why the Boutique/Atelier tabs and the footer
       sign-in button did nothing.
     · setRouteSilent allocated a fresh closure into the rAF queue on every
       call, and never dropped it.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, reduced, fine, coarse, RAF, TIMERS, LS, K,
  faNum, faPad, moneyT, esc, debounce, toast, dlStop, dlStart,
  backdropClose
} = window.AE;
const { CATALOG, PRODUCTS, ORDER, SIZES, catLabel } = window.AE_DATA;
const { state, getCustom, sanitiseCart, persBag } = window.AE_STATE;
const { wireImg, revealIO, setMode, setMnav, scrollToFilters } = window.AE_UI;
const { openBag, openWish, toggleWish, openSheet, renderBag, paintInBag } = window.AE_CART;
const { hydrate } = window.AE_PDP;
const { openCko } = window.AE_CKO;
const { openProfile } = window.AE_AUTH;
const { toggleConc } = window.AE_CONC;
const lowTier = window.AE.lowTier;

/* ═══════════════════════════════════════════════════════════════════════
   PRODUCT GRID

   cardHTML() is used only after `ae:catalog-sync`, i.e. only when the admin
   has changed something and the server catalogue is re-read. It deliberately
   reproduces the server's markup attribute for attribute:

     · href="?product=<id>"  — the canonical, crawlable URL form, which is what
       index.php emits. It used to emit "#/pdp/<id>" here, so the moment this
       ran the page lost every crawlable product link it had just been sent.
     · loading="eager" + fetchpriority="high" on the first card — the server
       marks the first card as the LCP element. Here it marked all eleven
       lazy and none high, so every view paid for eleven lazy images and gave
       the network no priority signal at all.
     · the size band comes from AE_DATA.SIZES, which is derived from the very
       constants api.php enforces. It was a hard-coded "۳۶ تا ۴۶" while the
       server band is 37–41 — a customer could read a size off the card that
       the order endpoint would reject with INVALID_SIZE.
   ═══════════════════════════════════════════════════════════════════════ */
const grid = $('#grid');
const SIZES_TXT = Array.isArray(SIZES) && SIZES.length ? SIZES.join(' تا ') : '۳۷ تا ۴۱';

/* O(1) id → form number. The old PRODUCTS.indexOf inside a map was O(n²). */
let FORM_NO = null;
const formNo = id => {
  if (!FORM_NO) FORM_NO = new Map(PRODUCTS.map((p, i) => [p.id, i + 1]));
  return FORM_NO.get(id) || 0;
};
/* O(1) id → position in ORDER, so the default comparator never calls indexOf. */
let RANK = null;
const rankOf = id => {
  if (!RANK) RANK = new Map(ORDER.map((v, i) => [v, i]));
  return RANK.has(id) ? RANK.get(id) : 1e9;
};

const cardHTML = (p, i) => `<article class="prod rv${i % 3 === 1 ? ' rv-d1' : i % 3 === 2 ? ' rv-d2' : ''}" data-id="${esc(p.id)}" role="listitem">
<a class="prod-media skl" href="?product=${encodeURIComponent(p.id)}" data-open-pdp aria-label="مشاهدهٔ جزئیات — ${esc(p.name)}">
<img src="${esc(p.img)}" alt="${esc(p.name)} — ${esc(p.sub)}" loading="${i < 2 ? 'eager' : 'lazy'}" decoding="async" width="640" height="800"${i === 0 ? ' fetchpriority="high"' : ''}>
${p.isNew ? '<span class="pl-badge hot">جدید</span>' : ''}
${p.stock <= 5 ? `<span class="pl-badge low">فقط ${faNum(p.stock)} مانده</span>` : ''}
<span class="pl-num">فرم ${faPad(formNo(p.id))}</span>
<span class="prod-line" aria-hidden="true"></span></a>
<button class="wish" type="button" aria-label="ذخیرهٔ ${esc(p.name)}" aria-pressed="false"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-7.5-4.7-10-9.3C.4 8.6 2.4 5 6 5c2.2 0 3.6 1.2 4.4 2.6h1.2C12.4 6.2 13.8 5 16 5c3.6 0 5.6 3.6 4 7.2C19.5 16.3 12 21 12 21z"/></svg></button>
<div class="prod-info">
<div class="p-top"><span class="p-cat">${esc(catLabel(p.cat))}</span><span class="p-price">${moneyT(p.price)}</span></div>
<h3 class="p-name"><a href="?product=${encodeURIComponent(p.id)}" data-open-pdp>${esc(p.name)}</a></h3>
<div class="p-meta"><span class="stock-pill${p.stock <= 5 ? ' low' : ''}"><span class="dot" aria-hidden="true"></span>${p.stock <= 5 ? `فقط ${faNum(p.stock)} مانده` : 'موجود'}</span></div>
<p class="p-sub">${esc(p.sub)}</p>
<div class="p-row"><div class="p-sw" aria-hidden="true">${(p.sw || []).map(s => `<span class="sw" style="--c:${esc(s.c)}"></span>`).join('')}</div><span class="mono" style="font-size:.56rem">${esc(SIZES_TXT)}</span></div>
<div class="p-acts">
<button class="quick qs" type="button" data-qs aria-label="خرید سریع — ${esc(p.name)}"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M13 2 4.5 13.5H11L9.5 22 19 10h-6l1-8z"/></svg><span class="quick-t">خرید سریع</span></button>
<a class="quick view" href="?product=${encodeURIComponent(p.id)}" data-open-pdp aria-label="مشاهدهٔ ${esc(p.name)}">مشاهده</a>
</div>
</div></article>`;

/* ─── Card wiring ─────────────────────────────────────────────────────────
   The spotlight used to be one pointermove listener PER CARD, each calling
   getBoundingClientRect() on every mousemove — up to eleven forced synchronous
   reflows while the pointer crossed the grid, each one reading layout right
   after the previous handler had written style.

   Now: one delegated pointermove, and the writes are batched into a single
   rAF job that reads each card's rect once per frame. At most one rect read
   per visible card per frame instead of per mousemove event, and no read/write
   interleaving inside the frame. */
const litCard = { el: null, x: 0, y: 0, queued: false };
function paintSpotlight() {
  litCard.queued = false;
  const el = litCard.el;
  if (!el || !el.isConnected) return;
  const r = el.getBoundingClientRect();
  if (!r.width || !r.height) return;
  el.style.setProperty('--mx', (((litCard.x - r.left) / r.width) * 100).toFixed(2) + '%');
  el.style.setProperty('--my', (((litCard.y - r.top) / r.height) * 100).toFixed(2) + '%');
}

const wireCards = root => {
  $$('img', root).forEach(wireImg);
  $$('.rv', root).forEach(el => revealIO.observe(el));
};
if (grid) wireCards(grid);

/* Delegated once for the whole grid — never re-bound per catalogue sync, which
   is what used to leak a listener set on every refresh. */
if (grid) {
  grid.addEventListener('click', e => {
    const w = e.target.closest('.wish');
    if (w && grid.contains(w)) {
      e.preventDefault(); e.stopPropagation();
      toggleWish(w.closest('.prod').dataset.id);
      return;
    }
    const q = e.target.closest('[data-qs]');
    if (q && grid.contains(q)) openSheet(q.closest('.prod').dataset.id);
  });

  if (fine && !lowTier()) {
    grid.addEventListener('pointermove', e => {
      const m = e.target.closest('.prod-media');
      if (!m || !grid.contains(m)) return;
      litCard.el = m; litCard.x = e.clientX; litCard.y = e.clientY;
      if (!litCard.queued) { litCard.queued = true; RAF.add(paintSpotlight); }
    }, { passive: true });
    grid.addEventListener('pointerleave', () => { litCard.el = null; }, { passive: true });
  }
}

/* ─── Filters ──────────────────────────────────────────────────────────────
   Only the state and the sort live here. The toolbar that used to drive them
   (#vSort, #vSearch, #vPrice, #activePills, #vCount) has no markup, so all of
   that wiring was writing to null and the pill row it built was never inserted
   anywhere. state.q is still set — by the header search, which does exist — so
   the filter itself keeps working.
   ═══════════════════════════════════════════════════════════════════════ */
function apply() {
  if (!grid) return;
  RANK = null;
  const cards = $$('.prod', grid), q = state.q.trim().toLowerCase();
  const wishSet = state.fam === 'wish' ? new Set(state.wish) : null;
  for (const el of cards) {
    const p = CATALOG[el.dataset.id];
    if (!p) { el.hidden = true; continue; }
    const famOK = state.fam === 'all' ? true : wishSet ? wishSet.has(p.id) : p.family === state.fam;
    const qOK = !q || (p.name + ' ' + p.sub + ' ' + p.cat).toLowerCase().includes(q);
    /* priceMax is null for "no ceiling" — see the note in state.js. The
       short-circuit matters as much as the comparison: with a number here, a
       stale one silently removes products from a shop that never says so. */
    const priceOK = !state.priceMax || p.price <= state.priceMax;
    el.hidden = !(famOK && qOK && priceOK);
  }

  /* Every card stays in the document. Filtered-out cards are `hidden`, which is
     a CSS-level removal: no layout box, no paint, no hit target.

     It used to be `grid.replaceChildren(...vis)` — the visible ones only — for
     the stated reason of one mutation and one layout instead of N. That is
     true and it is the wrong trade, because it makes filtering lossy: the
     hidden cards leave the DOM, so the next apply() enumerates only what
     survived, and a card once filtered out can never come back. The observable
     result was that the header search was a one-way door — type a word, and
     clearing the box left the grid filtered with nothing on screen to say why
     and no way to undo it short of reloading the page.

     A search that cannot be cleared is worse than no search, because the
     customer has no idea that the control is responsible.

     Sorting still reorders, and reordering is safe: append() moves an existing
     node rather than cloning it, so a single batched append gives one layout
     and loses nothing. */
  reportGrid();
  if (state.sort === 'featured') return;
  cards.sort((a, b) => {
    const pa = CATALOG[a.dataset.id], pb = CATALOG[b.dataset.id];
    if (state.sort === 'asc')  return pa.price - pb.price;
    if (state.sort === 'desc') return pb.price - pa.price;
    if (state.sort === 'name') return pa.name.localeCompare(pb.name, 'fa');
    return rankOf(pa.id) - rankOf(pb.id);
  });
  grid.append(...cards);
}

/* What the grid is showing, stated on the page.
   Called at the end of apply(), and once on boot, because the count is only
   interesting when it changes — a page that says "۱۱ اثر" in the markup and
   never updates it is worse than one that says nothing. */
function reportGrid() {
  const host = grid;
  if (!host) return;
  const all = $$('.prod', host);
  const shown = all.filter(el => !el.hidden).length;

  const count = $('#gridCount');
  if (count) {
    /* Only while something is actually being filtered. "۱۱ از ۱۱" on an
       unfiltered grid is noise, and it would be permanently on screen. */
    count.textContent = shown === all.length ? ''
      : `${faNum(shown)} اثر از ${faNum(all.length)}`;
    count.hidden = shown === all.length;
  }

  const empty = $('#gridEmpty');
  if (empty) {
    empty.hidden = shown !== 0;
    if (shown === 0) {
      /* The message names the filter that caused it and offers the way out of
         it, because a customer who has filtered to nothing needs both. When no
         filter is active the cause is the catalogue itself, which is a different
         sentence and a different problem. */
      const q = state.q.trim();
      empty.innerHTML = q
        ? `نتیجه‌ای برای «${esc(q)}» پیدا نشد.`
          + `<button class="btn btn--ghost btn--sm" type="button" data-act="clear-q">پاک کردن جست‌وجو</button>`
        : 'در حال حاضر اثری با این مشخصات موجود نیست.';
    }
  }
}
addEventListener('ae:apply', apply);

/* A catalogue sync is the only thing that re-renders the grid. It is NOT called
   on boot — the server already sent this markup. */
addEventListener('ae:catalog-sync', () => {
  if (!grid) return;
  FORM_NO = null;
  grid.innerHTML = PRODUCTS.map(cardHTML).join('');
  wireCards(grid);
  /* The catalogue this grid is about to render is not the one the cart was
     sanitised against. Re-run the sanitiser before the grid is painted, so a
     retired product's line is gone and an over-stock line is trimmed in the
     same frame the customer sees the new collection — rather than leaving a
     basket that api.php will refuse outright. See sanitiseCart() in state.js
     for what this prevents. */
  sanitiseCart();
  persBag();
  apply();
  renderBag();
  paintInBag();
  window.dispatchEvent(new CustomEvent('ae:render-wish'));
});

/* ═══════════════════════════════════════════════════════════════════════
   ROUTING
   ═══════════════════════════════════════════════════════════════════════ */
let suppressHash = false;
/* One stable rAF job, registered once. setRouteSilent used to allocate a fresh
   arrow on every call and never dropped it, so the rAF job set grew by one dead
   closure per route change and iterated all of them every frame. */
const releaseHash = () => { suppressHash = false; };
function setRouteSilent(url) {
  suppressHash = true;
  history.replaceState(null, '', url);
  RAF.add(releaseHash);
}

function openPDPByRoute(id) {
  const p = CATALOG[id];
  if (!p) return;
  const bag = $('#bag'), wishd = $('#wishd'), pdp = $('#pdp');
  if (bag.open) bag.close();
  if (wishd.open) wishd.close();
  state.lastFocus = document.activeElement;
  hydrate(p);
  if (!pdp.open) pdp.showModal();
  pdp.scrollTop = 0;
  dlStop();
  const backBtn = $('#pdpBack');
  if (backBtn) backBtn.focus({ preventScroll: true });
}

/* The 404 dialog: an unknown #/route, or a product id that is not in the
   catalogue. */
const nf = $('#nf');
if (nf) {
  nf._open = () => { nf._opener = document.activeElement; nf.showModal(); dlStop(); };
  const nfTop = $('#nfTop');
  if (nfTop) nfTop.addEventListener('click', () => nf.close());
  nf.addEventListener('close', () => {
    if (nf._opener && nf._opener.focus) nf._opener.focus({ preventScroll: true });
    nf._opener = null;
    dlStart();
    setRouteSilent('#/');
  });
}

let pendingAdminRoute = false;
function resolveAdminRoute() {
  if (!pendingAdminRoute) return;
  pendingAdminRoute = false;
  if (location.hash !== '#/admin') return;
  if (window.AE_AUTH && window.AE_AUTH.isAdmin()) {
    if (window.AE_ADMIN) window.AE_ADMIN.open(true);
  } else {
    toast('این بخش فقط برای مدیر است.', 'err');
    setRouteSilent('#/');
  }
}
window.addEventListener('ae:session-admin', resolveAdminRoute);

function handleRoute() {
  const h = location.hash;
  /* Gateway return: #/checkout?… — owned by checkout.js, ignore it here. */
  if (/^#\/checkout\?/.test(h)) return;
  const pm = h.match(/^#\/pdp\/([a-z0-9_-]+)/i);
  if (pm) { if (CATALOG[pm[1]]) openPDPByRoute(pm[1]); else if (nf) nf._open(); return; }
  if (h === '#/profile') { openProfile(true); return; }
  if (h === '#/admin') {
    /* Never open on a local flag — wait for the server's real answer. */
    if (window.AE_ADMIN && window.AE_AUTH && window.AE_AUTH.isAdmin()) { window.AE_ADMIN.open(true); return; }
    pendingAdminRoute = true;
    if (window.AE_AUTH) window.AE_AUTH.reloadSession();
    else { toast('این بخش فقط برای مدیر است.', 'err'); setRouteSilent('#/'); }
    return;
  }
  const pdp = $('#pdp');
  if (h === '' || h === '#' || h === '#/') { if (pdp.open) pdp.close(); return; }
  if (h.startsWith('#/')) { if (nf) nf._open(); return; }
  if (pdp.open) pdp.close();
}
addEventListener('hashchange', () => { if (suppressHash) return; handleRoute(); });

/* ─── ?product= is the canonical product URL ───────────────────────────────
   index.php renders the product's SEO head, JSON-LD and canonical for
   ?product=<id>, and the sitemap, the JSON-LD breadcrumb and every server-side
   card link all use that form. But handleRoute() only ever read location.hash,
   so opening a shared ?product= link showed the storefront shell with the
   product's title and description and nothing on the page. Now the two
   entrances agree: the query opens the dialog, and the hash is rewritten to
   match without adding a history entry. */
const bootProduct = window.VELORA_ROUTE && window.VELORA_ROUTE.product;
if (bootProduct && CATALOG[bootProduct]) {
  /* setRouteSilent, not a bare replaceState. This path set suppressHash = true
     and then returned, and nothing was ever scheduled to clear it — only
     setRouteSilent pairs the flag with RAF.add(releaseHash). So arriving on any
     ?product= URL latched the flag for the life of the session and every
     subsequent hashchange returned early: back out of a product, open another,
     go to the profile, anything routed by hash simply stopped working.

     The flag is also pointless here, which is the second half of the fix.
     history.replaceState does not fire hashchange — that is the specification,
     not an implementation detail — so there is no event for the flag to
     suppress. What it *was* guarding against is the browser restoring the
     scroll position or re-dispatching on a same-document navigation, and
     replaceState explicitly opts out of both. Setting it bought nothing and
     cost the router. */
  history.replaceState(null, '', location.pathname + location.search + '#/pdp/' + encodeURIComponent(bootProduct));
}

/* ─── PDP links ──────────────────────────────────────────────────────────
   One delegated handler for every [data-open-pdp] in the document, so a card
   re-rendered by a catalogue sync needs no new binding. */
document.addEventListener('click', e => {
  const t = e.target.closest('[data-open-pdp]');
  if (!t) return;
  e.preventDefault();
  const host = t.closest('.prod');
  const id = t.dataset.id || (host ? host.dataset.id : null);
  if (!id) return;
  location.hash = '#/pdp/' + id;
});
document.addEventListener('keydown', e => {
  const t = e.target;
  if (t && t.matches && t.matches('[data-open-pdp][role="button"]') &&
      (e.key === 'Enter' || e.key === ' ')) {
    e.preventDefault();
    t.click();
  }
});

const checkoutBtn = $('#checkout');
if (checkoutBtn) checkoutBtn.addEventListener('click', () => {
  if (!state.cart.length) { toast('سبد شما خالی است.', 'err'); return; }
  openCko();
});

/* ═══════════════════════════════════════════════════════════════════════
   ACTION DELEGATION

   Every data-act the server emits is handled here. Two were not, which is why
   the Boutique/Atelier tab switcher and the footer sign-in button were inert:
   `mode` and `auth` had no case at all, and both of them only had dead
   callers behind them.
   ═══════════════════════════════════════════════════════════════════════ */
document.addEventListener('click', e => {
  const t = e.target.closest('[data-act]');
  if (!t) return;
  const act = t.dataset.act;
  /* A real link keeps its own navigation; only buttons are intercepted. */
  if (t.tagName === 'A' && t.getAttribute('href') && act !== 'home') return;
  switch (act) {
    case 'menu':
      setMnav(!body.classList.contains('mnav-on'));
      break;
    case 'mode':
      setMode(t.dataset.mode || 'boutique');
      break;
    case 'auth':
    case 'prof-open':
      if (body.classList.contains('mnav-on')) setMnav(false);
      openProfile();
      break;
    case 'home':
      e.preventDefault();
      if (html.getAttribute('data-mode') !== 'boutique') setMode('boutique');
      else scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
      break;
    case 'cart-open':
      if (body.classList.contains('mnav-on')) setMnav(false);
      openBag();
      break;
    case 'wish-open':
      openWish();
      break;
    case 'cmd':
      cmdkOpen();
      break;
    case 'clear-q':
      /* The way out of a filter that matched nothing. It clears the input as
         well as the state, because leaving the box full while the grid empties
         is the state that made this worth adding a control for. */
      state.q = '';
      if (hdrQ) hdrQ.value = '';
      apply();
      if (hdrQ) hdrQ.focus();
      break;
    case 'conc-toggle':
      toggleConc();
      break;
    case 'conc-close':
      toggleConc(false);
      break;
    /* conc-send is owned by concierge-aurelle.js, which delegates on
       document for the same target. Handling it here too would double-send. */
  }
});

/* ─── Dock ────────────────────────────────────────────────────────────────
   #dockMenu has no markup; the other three do. `wire` was already idempotent
   via dataset.wired, which is kept because setMnav's own listeners and these
   could otherwise both be reached if the dock is ever re-rendered. */
(() => {
  const wire = (id, fn) => {
    const b = document.getElementById(id);
    if (!b || b.dataset.wired) return;
    b.dataset.wired = '1';
    b.addEventListener('click', ev => { ev.preventDefault(); fn(); });
  };
  wire('dockWish', openWish);
  wire('dockBag', openBag);
  wire('dockProf', () => openProfile());
})();

/* ═══════════════════════════════════════════════════════════════════════
   COMMAND PALETTE
   ═══════════════════════════════════════════════════════════════════════ */
const cmdk = $('#cmdk'), cmdkInput = $('#cmdkInput'), cmdkList = $('#cmdkList');
let cmdkIdx = 0, cmdkItems = [];
function cmdkOpen() {
  if (!cmdk) return;
  cmdk._opener = document.activeElement;
  cmdk.showModal();
  dlStop();
  cmdkInput.value = '';
  cmdkIdx = 0;
  cmdkRender('');
}
function cmdkClose() {
  cmdk.close();
  dlStart();
  if (cmdk._opener && cmdk._opener.focus) cmdk._opener.focus({ preventScroll: true });
}
function cmdkRender(q) {
  q = String(q || '').trim().toLowerCase();
  cmdkItems = [];
  /* Every entry here opens something that exists. The palette used to offer
     "تماس با میزبان", which ran `$('#contactSheet').showModal()` on a dialog
     with no markup — a second throw, on a keyboard shortcut. */
  const cmds = [
    { l: 'بازگشت به خانه',   ic: '🏠', act: () => { location.hash = '#/'; scrollToFilters(200); } },
    { l: 'سبد خرید',          ic: '🛒', act: openBag },
    { l: 'علاقه‌مندی‌ها',      ic: '❤', act: openWish },
    { l: 'حساب کاربری',       ic: '👤', act: () => openProfile(true) },
    { l: 'راهنمای سایز',      ic: '📏', act: openSG },
    { l: 'سایز سفارشی',       ic: '✂', act: openMZ },
    { l: 'تغییر پوسته',       ic: '🎨', act: () => { const b = $('#themeT'); if (b) b.click(); } },
    { l: 'کنسیژ اُتا',        ic: '💬', act: () => toggleConc(true) },
  ];
  const prods = PRODUCTS.filter(p => !q || (p.name + ' ' + p.sub + ' ' + p.cat).toLowerCase().includes(q));
  cmdkItems = cmds.filter(c => !q || c.l.toLowerCase().includes(q)).map(c => ({ type: 'cmd', ...c }));
  cmdkItems.push(...prods.slice(0, 8).map(p => ({ type: 'prod', p, k: p.id, l: p.name, sub: catLabel(p.cat), img: p.img })));

  /* Options, not buttons.

     The container is role="listbox", so its children have to be role="option".
     They were <button class="cmdk__item">, which carries an implicit role of
     `button` — a role that is not allowed as a listbox child at all, so the
     whole listbox was invalid and a screen reader announced an empty list. The
     markup also had no aria-selected and the input had no aria-activedescendant,
     so even with the right roles there would have been no way to say which of
     the eight commands was currently highlighted.

     This is the standard combobox shape, and it is worth the small rewrite
     because the palette is a keyboard-first control: the pointer is a
     convenience here, the arrow keys are the point.

     Clicks are handled by one delegated listener on the list (below) rather than
     one per row. Rows are rebuilt on every keystroke and every arrow press, so
     per-row listeners were re-bound dozens of times a minute of use — and each
     rebuild destroyed the focused element, which for a keyboard user meant the
     selection highlight and the focus ring disagreed. */
  cmdkList.innerHTML = cmdkItems.length
    ? cmdkItems.map((it, i) => `<li class="cmdk__item${i === cmdkIdx ? ' sel' : ''}" role="option"
        id="cmdkItem-${i}" aria-selected="${i === cmdkIdx}" data-i="${i}">
      <span class="ckl">${it.img ? `<img class="ckimg" src="${esc(it.img)}" alt="" width="38" height="47" loading="lazy" decoding="async">` : `<span style="font-size:1.2rem">${it.ic || '◆'}</span>`}<b>${esc(it.l)}</b></span>
      ${it.sub ? `<span class="m">${esc(it.sub)}</span>` : ''}
      </li>`).join('')
    : '<li class="cmdk__empty" role="presentation">نتیجه‌ای یافت نشد.</li>';

  /* The input owns the selection; the list owns the options. Pointing
     aria-activedescendant at the highlighted row is what lets focus stay in the
     text field — so the customer keeps typing — while assistive technology
     still knows which option is current. It is only set when there is something
     to point at, because an activedescendant naming a non-existent id is
     ignored, and pointing at nothing would leave the input claiming a selection
     it does not have. */
  if (cmdkInput) {
    cmdkInput.setAttribute('aria-expanded', String(cmdkItems.length > 0));
    const cur = cmdkItems[cmdkIdx];
    if (cur) cmdkInput.setAttribute('aria-activedescendant', 'cmdkItem-' + cmdkIdx);
    else cmdkInput.removeAttribute('aria-activedescendant');
    /* Keep the highlighted row inside the scroll box. max-height is 50vh and
       there can be sixteen rows, so without this the selection walks off the
       bottom and the palette looks stuck. */
    const sel = cmdkList.querySelector('.cmdk__item.sel');
    if (sel && sel.scrollIntoView) sel.scrollIntoView({ block: 'nearest' });
  }
}
function cmdkExec(i) {
  const it = cmdkItems[i];
  if (!it) return;
  cmdkClose();
  if (it.type === 'cmd') it.act();
  else location.hash = '#/pdp/' + it.k;
}
/* One delegated listener for the whole list. Registered once, so rebuilding the
   rows on every keystroke cannot orphan a handler or destroy the focused node. */
if (cmdkList) {
  cmdkList.addEventListener('click', e => {
    const row = e.target.closest('.cmdk__item');
    if (row) cmdkExec(+row.dataset.i);
  });
}

if (cmdkInput) {
  cmdkInput.addEventListener('input', e => { cmdkIdx = 0; cmdkRender(e.target.value); });
  cmdkInput.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); cmdkIdx = Math.min(cmdkIdx + 1, cmdkItems.length - 1); cmdkRender(cmdkInput.value); }
    if (e.key === 'ArrowUp')   { e.preventDefault(); cmdkIdx = Math.max(cmdkIdx - 1, 0); cmdkRender(cmdkInput.value); }
    if (e.key === 'Enter')     { e.preventDefault(); cmdkExec(cmdkIdx); }
    if (e.key === 'Home' && cmdkItems.length) { e.preventDefault(); cmdkIdx = 0; cmdkRender(cmdkInput.value); }
    if (e.key === 'End' && cmdkItems.length)  { e.preventDefault(); cmdkIdx = cmdkItems.length - 1; cmdkRender(cmdkInput.value); }
  });
}
if (cmdk) cmdk.addEventListener('close', () => {
  if (cmdk._opener && cmdk._opener.focus) cmdk._opener.focus({ preventScroll: true });
  cmdk._opener = null;
  dlStart();
});

/* ═══════════════════════════════════════════════════════════════════════
   SIZE GUIDE
   ═══════════════════════════════════════════════════════════════════════ */
const sg = $('#sg');
function openSG() {
  if (!sg) return;
  sg._opener = document.activeElement;
  sg.showModal();
  dlStop();
}
document.addEventListener('click', e => {
  const b = e.target.closest('.js-sg');
  if (b) { e.preventDefault(); openSG(); }
});
const sgX = $('#sgX');
if (sgX) sgX.addEventListener('click', () => sg.close());
backdropClose(sg);
if (sg) sg.addEventListener('close', () => {
  if (sg._opener && sg._opener.focus) sg._opener.focus({ preventScroll: true });
  sg._opener = null;
  dlStart();
});

/* ═══════════════════════════════════════════════════════════════════════
   MEASURE WIZARD
   ═══════════════════════════════════════════════════════════════════════ */
const mz = $('#mz');
let mzStep = 0, mzCalc = null;
const fieldOf = el => (el ? el.closest('.field') : null);

function openMZ() {
  if (!mz) return;
  mz._opener = document.activeElement;
  mzStep = 0;
  mzPaint();
  const c = getCustom();
  const lenEl = $('#mzLen'), widEl = $('#mzWid');
  if (c && lenEl) lenEl.value = c.L;
  if (c && widEl) widEl.value = c.W;
  fieldOf(lenEl)?.classList.remove('invalid');
  fieldOf(widEl)?.classList.remove('invalid');
  mz.showModal();
  dlStop();
  requestAnimationFrame(() => lenEl && lenEl.focus());
}
document.addEventListener('click', e => {
  const b = e.target.closest('.js-mz');
  if (b) { e.preventDefault(); openMZ(); }
});
const mzX = $('#mzX');
if (mzX) mzX.addEventListener('click', () => mz.close());
backdropClose(mz);
if (mz) mz.addEventListener('close', () => {
  if (mz._opener && mz._opener.focus) mz._opener.focus({ preventScroll: true });
  mz._opener = null;
  dlStart();
});

function mzPaint() {
  $$('.mz__steps li', mz).forEach((li, i) => {
    li.classList.toggle('is-on', i === mzStep);
    li.classList.toggle('is-done', i < mzStep);
  });
  const prog = $('#mzProg');
  if (prog) prog.style.transform = `scaleX(${(mzStep + 1) / 3})`;
  $$('.mz__step', mz).forEach(s => s.classList.toggle('is-on', +s.dataset.mstep === mzStep));
}

const mzNext1 = $('#mzNext1');
if (mzNext1) mzNext1.addEventListener('click', () => {
  const lenEl = $('#mzLen');
  const L = parseFloat(lenEl.value);
  const bad = !(L >= 20 && L <= 30);
  fieldOf(lenEl)?.classList.toggle('invalid', bad);
  if (bad) { toast('طول پا باید بین ۲۰ تا ۳۰ سانتی‌متر باشد.', 'err'); return; }
  mzStep = 1;
  mzPaint();
  const w = $('#mzWid');
  if (w) w.focus();
});

const mzNext2 = $('#mzNext2');
if (mzNext2) mzNext2.addEventListener('click', () => {
  const lenEl = $('#mzLen'), widEl = $('#mzWid');
  const L = parseFloat(lenEl.value), W = parseFloat(widEl.value);
  const badW = !(W >= 6 && W <= 13);
  fieldOf(widEl)?.classList.toggle('invalid', badW);
  if (badW) { toast('عرض پنجه باید بین ۶ تا ۱۳ سانتی‌متر باشد.', 'err'); return; }
  /* Golden-ratio foot geometry: house width = length / φ², and the EU size
     follows the atelier's own fit curve. */
  let eu = Math.round((L * 10 + 12) / 6.667) - 14;
  const houseW = L / (1.618033988749895 * 1.618033988749895);
  const ratio = W / houseW;
  const wide = ratio > 1.06, narrow = ratio < 0.94;
  if (wide) eu += 0.5;
  eu = Math.max(36, Math.min(46, eu));
  mzCalc = { L, W, eu, wide, narrow, houseW };
  const out = $('#mzEU'), conv = $('#mzNote');
  if (out) out.textContent = `${faNum(eu)} سفارشی`;
  if (conv) conv.innerHTML =
      (wide
        ? `پنجهٔ شما <b style="color:var(--accent)">${faNum(Math.round((ratio - 1) * 100))}٪ عریض‌تر</b> از پهنای خانه است — نیم‌سایز اضافه شد.`
        : narrow
        ? `پنجهٔ شما <b style="color:var(--accent)">${faNum(Math.round((1 - ratio) * 100))}٪ باریک‌تر</b> از پهنای خانه است — کفی نیم‌سایز رایگان.`
        : `پای شما دیقاً با تناسب خانه هم‌خوان است.`) +
      `<span class="mono" style="display:block;margin-top:.5rem;font-size:.62rem">طول ${faNum(L)} · عرض ${faNum(W)} سانتی‌متر · پهنای خانه ${faNum(Number(houseW.toFixed(2)))} سانتی‌متر</span>`;
  mzStep = 2;
  mzPaint();
});

/* The renderers emit a back button, which dispatches this rather than being
   bound directly, so the step state stays in one place. */
window.addEventListener('ae:mz-back', e => {
  mzStep = Math.max(0, Math.min(2, +e.detail.to || 0));
  mzPaint();
});

const mzSave = $('#mzSave');
if (mzSave) mzSave.addEventListener('click', () => {
  if (!mzCalc) return;
  LS.set(K.custom, { L: mzCalc.L, W: mzCalc.W, eu: mzCalc.eu, wide: mzCalc.wide, at: Date.now() });
  mz.close();
  if (window.AE_PDP.renderCustomPanel) window.AE_PDP.renderCustomPanel();
  toast(`سایز ${faNum(mzCalc.eu)} سفارشی — ثبت و ذخیره شد.`);
});

/* ═══════════════════════════════════════════════════════════════════════
   BOOT SYNC

   The pre-paint inline block in index.php writes the theme/scene/mode to
   localStorage with a raw setItem, which double-quotes and JSON-escapes it, so
   LS.get() (which parses) and LS.getRaw() disagree. This runs after it, so it
   uses getRaw and repaints the chips.
   ═══════════════════════════════════════════════════════════════════════ */
function bootSync() {
  const curT = LS.getRaw(K.theme, null) || html.getAttribute('data-theme') || 'nuit';
  const curS = LS.getRaw(K.scene, null) || html.getAttribute('data-scene') || 'night';
  $$('[data-theme-set]').forEach(b => b.classList.toggle('on', b.dataset.themeSet === curT));
  $$('[data-scene-set]').forEach(b => b.classList.toggle('on', b.dataset.sceneSet === curS));
  const meta = document.querySelector('meta[name=theme-color]');
  if (meta) meta.setAttribute('content', curT === 'ivoire' ? '#F6F2E8' : curT === 'emeraude' ? '#0e2020' : '#0a0b12');

  const savedMode = LS.getRaw(K.mode, 'boutique');
  if (savedMode !== 'atelier') return;
  html.setAttribute('data-mode', 'atelier');
  body.dataset.mode = 'atelier';
  const home = $('#view-home'), atl = $('#view-atelier');
  if (home) { home.hidden = true; home.setAttribute('inert', ''); }
  if (atl) { atl.hidden = false; atl.removeAttribute('inert'); }
  $$('[data-act="mode"]').forEach(b => {
    const on = b.dataset.mode === 'atelier';
    b.classList.toggle('on', on);
    b.setAttribute('aria-selected', String(on));
  });
  window.dispatchEvent(new CustomEvent('ae:render-atelier'));
  window.dispatchEvent(new CustomEvent('ae:mode-change', { detail: { mode: 'atelier' } }));
}
bootSync();

/* ═══════════════════════════════════════════════════════════════════════
   TOUCH — drag-to-dismiss sheets, PDP gallery swipe, double-tap wishlist

   The drag paint loop is rAF-driven and writes only `transform` on the
   dialog, so a finger drag composites and never lays out.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
  if (!coarse) return;

  /* A grab handle for the sheets that have no header of their own. */
  for (const id of ['bag', 'wishd', 'sg', 'mz', 'profile', 'passport', 'cko', 'sheet']) {
    const d = document.getElementById(id);
    if (d && !$('.lux-grab', d)) {
      const h = document.createElement('div');
      h.className = 'lux-grab';
      d.prepend(h);
    }
  }

  const HANDLE = '.lux-grab,.bag__head,.sheet__head,.pdp__bar';
  for (const d of $$('dialog.bag,dialog.wishd,dialog.sg,dialog.mz,dialog.profile,dialog.passport,dialog.cko,dialog.sheet')) {
    let y0 = 0, dy = 0, drag = false, vy = 0, ly = 0, lt = 0, raf = 0;
    const paint = () => {
      /* Rubber-band past the threshold so the sheet never feels unbounded. */
      const shown = dy <= 180 ? dy : 180 + (dy - 180) * 0.32;
      d.style.transform = `translate3d(0,${shown.toFixed(1)}px,0)`;
    };
    const loop = () => { raf = 0; if (drag) { paint(); raf = requestAnimationFrame(loop); } };
    const start = e => {
      if (!e.target.closest(HANDLE)) return;
      drag = true; y0 = e.clientY; dy = 0; vy = 0; ly = y0; lt = performance.now();
      d.classList.add('lux-dragging');
      try { d.setPointerCapture(e.pointerId); } catch (_) {}
      if (!raf) raf = requestAnimationFrame(loop);
    };
    /* No rect read here — only the deltas the events already carry. */
    const move = e => {
      if (!drag) return;
      const now = performance.now();
      vy = (e.clientY - ly) / Math.max(1, now - lt);
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
        try { d.close(); } catch (_) {}
        dy = 0; vy = 0;
      }
    };
    d.addEventListener('pointerdown', start);
    d.addEventListener('pointermove', move, { passive: true });
    d.addEventListener('pointerup', end);
    d.addEventListener('pointercancel', end);
    d.addEventListener('close', () => {
      drag = false;
      cancelAnimationFrame(raf); raf = 0;
      d.classList.remove('lux-dragging');
      d.style.transform = '';
    });
  }

  /* Gallery swipe. Pure clientX deltas — no layout read on the touch path. */
  const stage = $('#pdpStage');
  if (stage) {
    let sx = 0, sy = 0, t0 = 0, sup = false;
    stage.addEventListener('touchstart', e => {
      sx = e.touches[0].clientX; sy = e.touches[0].clientY;
      t0 = Date.now(); sup = false;
    }, { passive: true });
    stage.addEventListener('touchend', e => {
      const dx = e.changedTouches[0].clientX - sx;
      const dyv = e.changedTouches[0].clientY - sy;
      if (Math.abs(dx) > 42 && Math.abs(dx) > Math.abs(dyv) * 1.25) sup = true;
      if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dyv) * 1.4 && Date.now() - t0 < 600) {
        const th = $$('#pdpThumbs .pdp__thumb');
        if (!th.length) return;
        const cur = th.findIndex(t => t.getAttribute('aria-current') === 'true');
        const nx = (dx < 0 ? cur + 1 : cur - 1 + th.length) % th.length;
        if (th[nx]) { th[nx].click(); window.AE.buzz(6); }
      }
    }, { passive: true });
    /* Swallow the click that a swipe synthesises, so the PDP does not zoom. */
    stage.addEventListener('click', e => {
      if (sup) { e.preventDefault(); e.stopPropagation(); sup = false; }
    }, true);
  }

  /* Double-tap to wishlist. */
  let lastTap = 0, lastEl = null;
  document.addEventListener('touchend', e => {
    const m = e.target.closest('.prod-media');
    if (!m) return;
    const now = Date.now();
    if (now - lastTap < 320 && lastEl === m) {
      const w = m.closest('.prod')?.querySelector('.wish');
      if (w && !w.classList.contains('on')) { w.click(); window.AE.buzz([8, 40, 14]); }
      lastTap = 0;
    } else { lastTap = now; lastEl = m; }
  }, { passive: true });
})();

/* ═══════════════════════════════════════════════════════════════════════
   THEME SETTLE

   Published before the LUXE layer and outside its gate, because ui.js's
   setTheme / setMode call it and they run on every device. The gate lives
   inside the function instead, so a caller never has to know whether the
   effect is available — it always is, and sometimes it decides to do nothing.
   ═══════════════════════════════════════════════════════════════════════ */
window.AE_SETTLE = (function () {
  let armed = true;
  return function (el) {
    if (!armed || !el || typeof el.animate !== 'function') return;
    if (reduced || lowTier() || document.hidden) return;
    /* One animation at a time. Rapid theme toggling would otherwise stack
       several on the same element and the last one to start wins, which reads
       as the fade stuttering. */
    armed = false;
    const a = el.animate({ opacity: [0.86, 1] },
      { duration: 520, easing: 'cubic-bezier(.33,1,.68,1)' });
    const done = () => { armed = true; };
    if (a && typeof a.finished?.then === 'function') a.finished.then(done, done);
    else setTimeout(done, 560);
  };
})();

/* ═══════════════════════════════════════════════════════════════════════
   LUXE LAYER

   Every one of these is a pointer-driven effect, and every one of them is
   gated behind `fine && !reduced && !lowTier()` — which means none of it
   executes on a touch device or a budget Android at all. That is the single
   biggest reason the desktop stays smooth: the effects are not "cheaper on
   mobile", they are absent.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
  html.setAttribute('data-luxe', '3');
  const luxe = fine && !reduced && !lowTier();
  if (!luxe) return;

  /* Theme, scene and mode changes get a soft cross-fade.

     The previous implementation intercepted the click in the capture phase,
     stopped it, and re-dispatched an inner click from inside
     startViewTransition's callback. It looked like the right shape for a
     same-document view transition and it was wrong twice over.

     First, it destroyed the click unconditionally and then handed the whole
     action to a callback the browser may never invoke: a same-document view
     transition is skipped whenever the document is not being rendered — a
     background tab, a bfcache restore, a prerendered page, a browser that
     exposes the API but declines to animate, or a browser-level
     prefers-reduced-motion that is not the same signal as the `reduced` sample
     this file reads at parse time. In every one of those cases the original
     click had already been stopped and the inner one never fired, so the theme
     and mode controls were simply dead. No error, nothing in the console.

     Second, when the transition *did* start it made the response to a click
     asynchronous — the state did not change until the browser got round to the
     callback, which is up to a frame later and unbounded if the transition
     stalls. A control that acknowledges a click late reads as lag.

     So the transition is gone and the effect is what was actually wanted: a
     brief settle on the element after the state has changed. It is driven from
     the state change rather than from the click, so it cannot swallow anything,
     it cannot delay anything, and it costs one property animation on a single
     element. */
  /* Spotlight: one delegated move, rAF-batched, one rect read per frame.
     The list is now only the selectors that actually exist — the old list
     named .selcard, .bench__c, .cpw-calc, .frame and .cert, none of which
     are in any markup, so five of thirteen lookups could never match. */
  const SPOT = '.rev-item,.bag-item,.prof-card,.tip,.at-card,.l-card,.form';
  const spot = { el: null, x: 0, y: 0, queued: false };
  const paint = () => {
    spot.queued = false;
    const el = spot.el;
    if (!el || !el.isConnected) return;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) return;
    el.style.setProperty('--mx', (((spot.x - r.left) / r.width) * 100).toFixed(2) + '%');
    el.style.setProperty('--my', (((spot.y - r.top) / r.height) * 100).toFixed(2) + '%');
  };
  document.addEventListener('pointermove', e => {
    const el = e.target.closest(SPOT);
    if (!el) return;
    spot.el = el; spot.x = e.clientX; spot.y = e.clientY;
    if (!spot.queued) { spot.queued = true; RAF.add(paint); }
  }, { passive: true });

  /* Magnetic buttons. The rect is read once on enter and reused for the whole
     hover, instead of once per pointermove — a hover can be hundreds of
     events, and the button cannot move relative to itself. */
  document.addEventListener('pointerover', e => {
    const b = e.target.closest('.btn-solid,.btn--gold');
    if (!b || b.__luxmag || b.disabled) return;
    b.__luxmag = true;
    const r = b.getBoundingClientRect();
    if (!r.width || !r.height) { b.__luxmag = false; return; }
    const move = ev => {
      const dx = (ev.clientX - r.left - r.width / 2) / r.width;
      const dy = (ev.clientY - r.top - r.height / 2) / r.height;
      if (dx * dx + dy * dy >= 0.36) return;
      b.style.transform = `translate(${(dx * 8).toFixed(2)}px,${(dy * 6).toFixed(2)}px)`;
    };
    const leave = () => {
      b.style.transition = 'transform .65s cubic-bezier(.34,1.56,.64,1)';
      b.style.transform = '';
      TIMERS.once(() => { b.style.transition = ''; }, 660, 'luxmag');
      b.removeEventListener('pointermove', move);
      b.removeEventListener('pointerleave', leave);
      b.__luxmag = false;
    };
    b.addEventListener('pointermove', move, { passive: true });
    b.addEventListener('pointerleave', leave);
  }, { passive: true });

  /* Golden burst on wish / add. One rect read, at the click. */
  const burst = (x, y, n) => {
    for (let i = 0; i < n; i++) {
      const s = document.createElement('span');
      s.className = 'lux-spark';
      s.style.left = x + 'px';
      s.style.top = y + 'px';
      const a = Math.random() * Math.PI * 2;
      const d = 26 + Math.random() * 46;
      s.style.setProperty('--sx', (Math.cos(a) * d).toFixed(1) + 'px');
      s.style.setProperty('--sy', (Math.sin(a) * d - 24).toFixed(1) + 'px');
      document.body.appendChild(s);
      TIMERS.once(() => s.remove(), 850, 'spark');
    }
  };
  document.addEventListener('click', e => {
    const w = e.target.closest('.wish');
    if (w && w.classList.contains('on')) {
      const r = w.getBoundingClientRect();
      burst(r.left + r.width / 2, r.top + r.height / 2, 12);
      return;
    }
    const add = e.target.closest('.js-add');
    if (add && !add.disabled) {
      const r = add.getBoundingClientRect();
      burst(r.left + r.width / 2, r.top + r.height / 2, 14);
    }
  }, { passive: true });

  /* Hero guilloche, deferred to idle. guilloche() builds two ~600-point
     spirograph paths and concatenates their `d` attributes — real main-thread
     work. It is pure ornament at opacity .08, so there is nothing to gain by
     having it before the first frame. */
  const heroSection = $('.hero');
  if (heroSection) {
    const draw = () => {
      if (!heroSection.isConnected) return;
      const { guilloche } = window.AE_PDP;
      if (typeof guilloche !== 'function') return;
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 1200 800');
      svg.setAttribute('aria-hidden', 'true');
      svg.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;opacity:.08;z-index:0;';
      const sets = [[600, 400, 380, 147, 180, 7, 600], [600, 400, 280, 93, 140, 5, 500]];
      svg.innerHTML = sets.map(s => {
        const g = guilloche(s[0], s[1], s[2], s[3], s[4], s[5], s[6]);
        return `<path d="${g.d}" fill="none" stroke="var(--accent)" stroke-width=".6" opacity=".7"/>`;
      }).join('');
      heroSection.insertBefore(svg, heroSection.firstChild);
    };
    if ('requestIdleCallback' in window) requestIdleCallback(draw, { timeout: 2000 });
    else setTimeout(draw, 600);
  }

  const fab = $('#concFab');
  if (fab) fab.addEventListener('click', () => fab.classList.add('seen'), { once: true });
})();

/* ═══════════════════════════════════════════════════════════════════════
   NETWORK · IMAGES · METRICS

   The tier decision belongs to core.js and index.php's pre-paint block. It used
   to be re-derived here from navigator.connection with a second class
   vocabulary (p2-low / p2-mid / p2-high), so the page could hold two
   contradictory opinions about the same device. There is now one.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
  const safeSession = (k, v) => {
    try {
      if (v === undefined) return sessionStorage.getItem(k);
      sessionStorage.setItem(k, v);
    } catch (_) { return null; }
    return v;
  };

  /* ─── Long-task watchdog ─────────────────────────────────────────────────
     The reset used to be a permanent 2.2s interval that fired for the life of
     the page just to zero one number. It is now reset inside the observer
     itself, so it only runs when there is something to reset. */
  let longMs = 0, degraded = false;
  const degrade = () => {
    if (degraded) return;
    degraded = true;
    html.classList.add('perf-low');
    /* The CSS hook withdraws thirteen infinite animations and the vignette,
       the edge band and the plate grain. Removing #dust/.motes/.grain from the
       DOM as well releases the <canvas> backing store, which no CSS rule can
       do — the canvas keeps its pixels allocated for the life of the document. */
    const dust = document.getElementById('dust');
    if (dust) dust.remove();
    document.querySelector('.motes')?.remove();
  };
  try {
    if ('PerformanceObserver' in window && PerformanceObserver.supportedEntryTypes &&
        PerformanceObserver.supportedEntryTypes.indexOf('longtask') !== -1) {
      let resetAt = 0;
      new PerformanceObserver(list => {
        const now = performance.now();
        if (now - resetAt > 2500) longMs = 0;
        resetAt = now;
        for (const e of list.getEntries()) longMs += e.duration;
        if (longMs > 420) degrade();
      }).observe({ entryTypes: ['longtask'] });
    }
  } catch (_) {}
  try {
    const conn = navigator.connection;
    if (conn && conn.addEventListener) {
      conn.addEventListener('change', () => { if (conn.saveData) degrade(); });
    }
  } catch (_) {}

  /* ─── Connectivity toast ───────────────────────────────────────────────
     Kept separate from AE.toast(): this one has to survive independent of the
     toast queue, because the queue's own timers are what stop running while
     the main thread is blocked, and an offline notice that arrives late is
     worse than no notice. */
  const p2 = $('#p2toast');
  let p2T = 0;
  const p2toast = (text, kind = '') => {
    if (!p2) return;
    const tx = $('.p2tx', p2);
    if (tx) tx.textContent = text;
    p2.className = kind;
    p2.classList.add('on');
    clearTimeout(p2T);
    p2T = setTimeout(() => p2.classList.remove('on'), kind === 'off' ? 6000 : 3600);
  };
  addEventListener('offline', () => {
    html.classList.add('p2-off');
    p2toast('شما آفلاین هستید — سبد و علاقه‌مندی‌های شما محفوظ است.', 'off');
  }, { passive: true });
  addEventListener('online', () => {
    html.classList.remove('p2-off');
    p2toast('دوباره آنلاین شدید.', 'ok');
  }, { passive: true });
  if (!navigator.onLine) html.classList.add('p2-off');
  const conn0 = navigator.connection || {};
  if (conn0.saveData) {
    html.classList.add('perf-low');
    degrade();
    TIMERS.once(() => p2toast('حالت کم‌داده روشن است — انیمیشن‌های تزئینی غیرفعال شدند.', ''), 2600, 'p2:sd');
  }

  /* ─── Service worker ────────────────────────────────────────────────────
     Registered after `load` so it never competes with first paint for
     bandwidth. The controllerchange handler is guarded by a sessionStorage
     flag: on the first ever claim there is no controller, and after any later
     claim the page reloads exactly once. That guard is what stops the
     reload-claim-reload loop. */
  if ('serviceWorker' in navigator) {
    const hadController = !!navigator.serviceWorker.controller;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (!hadController) return;
      if (safeSession('ae.reloaded')) return;
      safeSession('ae.reloaded', '1');
      location.reload();
    });
    /* Hand the worker the list of assets this document actually loaded.

       The worker cannot work it out for itself. It used to try, from `?css=`
       and `?js=` on its own registration URL, through a fixed template naming
       ./style.css and ./app.js — neither of which exists here — so the list was
       always empty, the precache never ran, and the install handler sat out a
       1.5 s grace period waiting for a message that could not help.

       index.php now publishes VELORA_PRECACHE: the exact `?v=<filemtime>` URLs
       of the six stylesheets and fifteen scripts this page referenced, plus the
       icon. Those URLs are immutable by construction, so caching them hard
       cannot serve a stale file under a matching name.

       `navigator.serviceWorker.ready` is the only correct moment to send it, and
       the reason is worth recording because both obvious alternatives are wrong:

         · on register() resolving — on a first visit the worker is still
           installing, so `reg.active` is null and there is no controller yet.
           Nothing to send to, and the message is silently dropped. This is
           exactly what happened when this was wired to the registration
           promise: 22 URLs published, 0 written.
         · on the controllerchange event — that fires only on a *subsequent*
           visit, when a new worker takes over an old one. It never fires on the
           first visit at all, which is the visit that most needs it.

       `ready` resolves once a worker is active, on the first visit and every
       one after, and a worker that is active is one that will accept a message
       and keep the cache write alive. If registration fails entirely, `ready`
       rejects, and that rejection is swallowed: a service worker that cannot
       precache still serves a correct page from the network, and there is
       nothing useful to tell somebody who did not ask. */
    function precacheAssets() {
      const urls = window.VELORA_PRECACHE;
      if (!Array.isArray(urls) || !urls.length) return;
      navigator.serviceWorker.ready
        .then(reg => { reg && reg.active && reg.active.postMessage({ type: 'SET_PRECACHE', assets: urls }); })
        .catch(() => { /* no worker — the network path is unaffected */ });
    }

    const regSW = () => {
      navigator.serviceWorker.register('./sw.js', { scope: './' })
        .then(precacheAssets)
        .catch(err => console.warn('[VELORA] SW register failed', err));
    };
    if (document.readyState === 'complete') regSW();
    else addEventListener('load', regSW, { once: true });
  }

  /* ─── Adaptive images ───────────────────────────────────────────────────
     Only ever applies to remote (Unsplash) sources. Every product in the
     current catalogue has an empty gallery, so p.img is the maison's inline
     SVG plate — a data URI that this function skips on the first line. So in
     production today this is a no-op, and it stays only because an operator
     can paste an Unsplash URL into the admin at any time.

     The MutationObserver used to be `subtree: true` on document.body, so every
     toast, ripple, cursor spark and burst node in the document ran a
     querySelectorAll('img') over itself. It is now scoped to the containers
     that can actually hold a remote image. */
  const UNS = 'images.unsplash.com';
  const presets = img => {
    if (img.closest('.pdp__stage')) return { w: [480, 800, 1120], q: 80, sizes: '(max-width:979px) 96vw, 55vw' };
    if (img.closest('.prod-media'))  return { w: [420, 680, 980],  q: 78, sizes: '(max-width:760px) 94vw, 340px' };
    if (img.closest('#pdpThumbs'))   return { w: [132, 198],       q: 70, sizes: '66px' };
    if (img.closest('.bag-item'))    return { w: [160, 240],       q: 70, sizes: '76px' };
    if (img.classList.contains('ckimg')) return { w: [76, 152],    q: 65, sizes: '38px' };
    return { w: [420, 800], q: 76, sizes: '(max-width:760px) 94vw, 480px' };
  };
  function upgrade(img) {
    if (!img || img.dataset.p2img) return;
    const src = img.currentSrc || img.getAttribute('src') || '';
    if (src.indexOf(UNS) === -1) return;
    img.dataset.p2img = '1';
    const base = src.split('?')[0];
    const p = (degraded || html.classList.contains('perf-low'))
      ? { w: [520], q: 45, sizes: '94vw' }
      : presets(img);
    img.srcset = p.w.map(w => `${base}?auto=format&fit=crop&w=${w}&q=${p.q} ${w}w`).join(', ');
    img.sizes = p.sizes;
  }
  /* Scoped to the four containers that can hold a remote image. */
  for (const sel of ['#grid', '#pdpImg', '#pdpThumbs', '#bagItems', '#toasts', '.cmdk__item']) {
    const host = $(sel);
    if (host) $$('img', host).forEach(upgrade);
  }
  const pdpImg = document.getElementById('pdpImg');
  if (pdpImg) {
    new MutationObserver(() => {
      delete pdpImg.dataset.p2img;
      upgrade(pdpImg);
    }).observe(pdpImg, { attributes: true, attributeFilter: ['src'] });
  }

  /* ─── Web vitals + FPS HUD ────────────────────────────────────────────── */
  try {
    if ('PerformanceObserver' in window) {
      const vitals = { lcp: 0, cls: 0, inp: 0 };
      try {
        new PerformanceObserver(l => {
          const es = l.getEntries(), e = es[es.length - 1];
          vitals.lcp = Math.round(e.startTime);
        }).observe({ type: 'largest-contentful-paint', buffered: true });
      } catch (_) {}
      try {
        new PerformanceObserver(l => {
          for (const e of l.getEntries()) if (!e.hadRecentInput) vitals.cls += e.value;
        }).observe({ type: 'layout-shift', buffered: true });
      } catch (_) {}

      /* ?fps=1 — a live frame counter for verifying the 60fps target on real
         hardware. The rAF job stops itself when the tab is hidden. */
      if (/[?&]fps=1/.test(location.search)) {
        const hud = document.createElement('div');
        hud.id = 'fpsHud';
        hud.style.cssText = 'position:fixed;z-index:2147483647;inset-block-start:calc(env(safe-area-inset-top,0px) + 4px);inset-inline-end:4px;padding:6px 10px;border-radius:10px;background:#000c;color:#0f0;font:600 12px/1.5 ui-monospace,monospace;pointer-events:none;white-space:pre;text-align:end';
        document.body.appendChild(hud);
        let frames = 0, longFrames = 0, last = performance.now(), winStart = last;
        const loop = t => {
          const dt = t - last;
          last = t;
          if (dt > 0) { frames++; if (dt > 20) longFrames++; }
          if (t - winStart >= 1000) {
            hud.textContent = `${faNum(Math.round(frames * 1000 / (t - winStart)))} fps\nافت: ${faNum(longFrames)}\n${faNum(Math.round(longFrames / Math.max(1, frames) * 100))}٪`;
            frames = 0; longFrames = 0; winStart = t;
          }
          if (!document.hidden) RAF.add(loop);
        };
        RAF.add(loop);
      }

      addEventListener('load', () => {
        const phi = $('.f-phi');
        if (!phi) return;
        const nav = performance.getEntriesByType('navigation')[0];
        const ms = Math.round((nav && nav.loadEventEnd) || performance.now());
        const t = document.createElement('span');
        t.className = 'phi-perf';
        t.textContent = `بارگذاری ${faNum(ms)} میلی‌ثانیه · LCP ${faNum(vitals.lcp)}`;
        phi.appendChild(t);
      }, { once: true });
    }
  } catch (_) {}
})();

/* ═══════════════════════════════════════════════════════════════════════
   PAUSE INFINITE ANIMATIONS OFFSCREEN

   The bug this fixes: visibilitychange disconnected the observer and thawed
   every element on the way back, but never re-observed. So after ONE tab
   switch the aurora, the grain, the motes, the header marquee and the
   lookbook marquee ran their infinite animations for the rest of the session,
   including while scrolled far offscreen and including while the tab was
   hidden. Re-observing is the whole fix; the observed list is kept so it can
   be replayed rather than re-queried.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
  const SELECTOR = '.aurora i, .grain, .motes i, .tk, .ticker__track, .live-dot, .chat-fab__pulse';
  const NEVER_FREEZE = '.hdr, .rail, #toTop, .dock, .annonce, #prog';
  const freeze = el => el && el.classList.add('anim-paused');
  const thaw = el => el && el.classList.remove('anim-paused');
  const isFrostable = el => el && !el.matches(NEVER_FREEZE);
  const hostOf = el => (el.classList.contains('motes') ? el : (el.parentElement || el));

  const observed = $$(SELECTOR)
    .filter(isFrostable)
    .map(el => {
      const host = el.parentElement && el.parentElement !== body ? el.parentElement : el;
      return { el, host };
    });

  let io = null;
  try {
    io = new IntersectionObserver(entries => {
      for (const e of entries) {
        const el = e.target;
        if (!isFrostable(el)) continue;
        if (e.isIntersecting) thaw(hostOf(el)); else freeze(hostOf(el));
      }
    }, { rootMargin: '120px 0px', threshold: 0 });
    for (const { host } of observed) io.observe(host);
  } catch (_) {}

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (io) io.disconnect();
      for (const { el } of observed) freeze(el);
    } else {
      for (const { el } of observed) thaw(el);
      /* Re-arm. Without this the observer is dead for the rest of the session
         and every decorative animation runs forever. */
      if (io) for (const { host } of observed) io.observe(host);
    }
  });
})();

/* ═══════════════════════════════════════════════════════════════════════
   BOOTSTRAP
   ═══════════════════════════════════════════════════════════════════════ */
handleRoute();
apply();

/* ─── Header search ───────────────────────────────────────────────────────
   The only filter control that has markup. #vSearch / #vSort / #vPrice do not,
   so state.q has exactly one writer and there is nothing to keep in sync.

   hdrQ is module-scope rather than local to the IIFE because the clear-q action
   in the delegation switch has to be able to empty the box, not just the state.
   An input that still reads "نوته" above an unfiltered grid is a control lying
   about the state of the page. */
let hdrQ = null;
(() => {
  hdrQ = $('#hdrQ');
  if (!hdrQ) return;
  const sync = debounce(() => {
    state.q = hdrQ.value;
    if (html.getAttribute('data-mode') !== 'boutique') setMode('boutique');
    apply();
  }, 200);
  hdrQ.addEventListener('input', sync);
  hdrQ.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); sync(); } });
})();

/* Paint the theme/scene/mode chips from the attributes the pre-paint block
   already set, so they are correct on the very first frame rather than after
   bootSync has run. */
$$('[data-theme-set]').forEach(b => b.classList.toggle('on', b.dataset.themeSet === html.getAttribute('data-theme')));
$$('[data-scene-set]').forEach(b => b.classList.toggle('on', b.dataset.sceneSet === html.getAttribute('data-scene')));
$$('[data-act="mode"]').forEach(b => b.classList.toggle('on', b.dataset.mode === html.getAttribute('data-mode')));

/* ─── Catalogue freshness ─────────────────────────────────────────────────
   Silent by design: if the server is unreachable or the catalogue is empty,
   nothing on the page changes. main.js used to call AE_SYNC.run(false)
   synchronously during parse, which meant a network request competing with
   first paint; sync-aurelle.js already runs on `load` and on idle. */
if (window.AE_SYNC) window.AE_SYNC.run(false);

/* ═══════════════════════════════════════════════════════════════════════
   SENSORY LAYER — ویژگی‌های ۵/۶/۸/۱۰ + Sparkline
   همه روی سلکتورهای موجود در index.php سوار می‌شوند؛ هیچ ساختار سروری
   بازنویسی نمی‌شود (append فقط در ناحیهٔ JS-ساخته یا region خالی).
   ═══════════════════════════════════════════════════════════════════════ */

/* ─── Market Ticker (ویژگی ۵) ─────────────────────────────────────────────
   شبیه‌سازیِ زندهٔ XAU/BTC/ETH: نوسان ±۰.۲٪ هر ۵ ثانیه، رنگ تغییر بر اساس
   جهت. فقط ≥1200px و نه perf-low و نه حالت atelier. hover = pause+zoom(CSS).
   توقف کامل در تب مخفی. هیچ درخواست شبکه‌ای ندارد (دیتای واقعی نیاز به
   بکاند دارد؛ اینجا صادقانه «شبیه‌سازی» برچسب خورده است). */
(() => {
  const bar = $('#mkBar');
  if (!bar || lowTier()) return;
  const mqWide = matchMedia('(min-width: 1200px)');
  let paused = false;
  const base = { XAU: 2650, BTC: 67500, ETH: 3250 };
  const st = {}; Object.keys(base).forEach(k => st[k] = { v: base[k], p: base[k] });

  /* نمایش: فقط دسکتاپ عریض و نه در آتلیه. reduced-motion تیکر را متوقف
     نمی‌کند (عدد است نه حرکت) ولی نوسان با CSS transition نرم است. */
  const paint = () => { bar.hidden = !(mqWide.matches && html.getAttribute('data-mode') !== 'atelier'); };
  mqWide.addEventListener('change', paint);
  addEventListener('ae:mode-change', paint);
  bar.addEventListener('pointerenter', () => paused = true);
  bar.addEventListener('pointerleave', () => paused = false);

  setInterval(() => {
    if (paused || document.hidden || bar.hidden) return;
    bar.querySelectorAll('.mk-item').forEach(el => {
      const s = st[el.dataset.sym]; if (!s) return;
      s.v = s.v * (1 + (Math.random() - .5) * .004);
      const d = (s.v - s.p) / s.p * 100;
      el.querySelector('.mk-v').textContent = '$' + Math.round(s.v).toLocaleString('en-US');
      const de = el.querySelector('.mk-d');
      de.textContent = (d >= 0 ? '▲' : '▼') + Math.abs(d).toFixed(2) + '%';
      de.className = 'mk-d ' + (d >= 0 ? 'up' : 'down');
      if (Math.abs(d) >= .5) s.p = s.v;
    });
  }, 5000);
  paint();
})();

/* ─── Cinema Mode (ویژگی ۶) ───────────────────────────────────────────────
   از رویداد ae:cinema-open (lbxaurelle.js) لیست را می‌گیرد؛ letterbox و
   کنترل‌ها markup ثابت‌اند. autoplay ۶ ثانیه‌ای فقط وقتی reduced-motion
   نباشد؛ در غیر این صورت فریم ساکن + پیمایش دستی. ESC/backdrop با
   wireDialog بسته می‌شود. */
(() => {
  const dlg = $('#cinema');
  if (!dlg) return;
  AE.wireDialog(dlg);
  const img = $('#cinImg'), timeEl = $('#cinTime'), prog = $('#cinProg'),
        progWrap = $('#cinProgWrap'), btnPause = $('#cinPause');
  let list = [], at = 0, timer = null, tickT = null, startAt = 0, paused = false;

  const fa2 = n => String(n).padStart(2, '0').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const show = i => {
    at = ((i % list.length) + list.length) % list.length;
    img.src = list[at];
    img.alt = ''; /* تزئینی در سینما؛ نام محصول در تایمر نیست — SR با aria-label dialog هدایت می‌شود */
    const pct = list.length > 1 ? Math.round((at / (list.length - 1)) * 100) : 100;
    prog.style.width = pct + '%';
    progWrap.setAttribute('aria-valuenow', String(pct));
  };
  const clock = () => {
    if (paused) return;
    const sec = Math.floor((Date.now() - startAt) / 1000);
    timeEl.textContent = fa2(Math.floor(sec / 60)) + ':' + fa2(sec % 60);
  };
  const stopAll = () => { clearInterval(timer); clearTimeout(tickT); timer = tickT = null; };
  const play = () => {
    stopAll();
    if (reduced || list.length < 2) return;   /* still-frame */
    paused = false; btnPause.setAttribute('aria-pressed', 'false');
    startAt = Date.now();
    timer = setInterval(clock, 1000);
    tickT = setInterval(() => show(at + 1), 6000);
  };

  addEventListener('ae:cinema-open', e => {
    const d = e.detail || {};
    list = Array.isArray(d.list) && d.list.length ? d.list.slice() : [];
    if (!list.length) return;
    if (dlg.open) dlg.close(); /* lbx باید کنار برود تا focus trap دوگانه نشود */
    requestAnimationFrame(() => {
      dlg._opener = document.activeElement;
      dlg.showModal(); AE.dlStop();
      show(Number(d.at) || 0);
      timeEl.textContent = '۰۰:۰۰'; startAt = Date.now();
      play();
      $('#cinClose')?.focus({ preventScroll: true });
    });
  });

  $('#cinPrev')?.addEventListener('click', () => { show(at - 1); startAt = Date.now(); });
  $('#cinNext')?.addEventListener('click', () => { show(at + 1); startAt = Date.now(); });
  $('#cinClose')?.addEventListener('click', () => dlg.close());
  btnPause?.addEventListener('click', () => {
    paused = !paused;
    btnPause.setAttribute('aria-pressed', String(paused));
    btnPause.setAttribute('aria-label', paused ? 'ادامهٔ نمایش' : 'توقف نمایش');
    if (paused) { stopAll(); } else { play(); }
  });
  dlg.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight') { show(at + 1); }
    if (e.key === 'ArrowLeft')  { show(at - 1); }
  });
  dlg.addEventListener('close', () => { stopAll(); paused = false; });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && dlg.open) { paused = true; stopAll();
      btnPause?.setAttribute('aria-pressed', 'true'); }
    else if (!document.hidden && dlg.open && !reduced) { play(); }
  });
})();

/* ─── PWA Install Sheet (ویژگی ۸) ─────────────────────────────────────────
   سه شرط: beforeinstallprompt رخ داده، ≥۲۵ ثانیه ماندگاری، ≥۳۵٪ اسکرول.
   یک‌بار تصمیم (نصب یا رد) برای همیشه علامت می‌خورد. */
(() => {
  const sheet = $('#installSheet');
  if (!sheet || LS.get('ae.installed.v1', false)) return;
  let deferred = null;
  addEventListener('beforeinstallprompt', e => { e.preventDefault(); deferred = e; });
  const depth = () => Math.min(100, scrollY / (document.body.scrollHeight - innerHeight || 1) * 100);
  setTimeout(() => {
    if (!deferred || depth() < 35 || sheet.open || dialogOpenAny()) return;
    LS.set('ae.installed.v1', true); /* حتی اگر نادیده گرفته شد، تکرار نکند */
    sheet._opener = document.activeElement;
    sheet.showModal();
    $('#installGo')?.addEventListener('click', async () => {
      sheet.close();
      try { deferred.prompt(); await deferred.userChoice; } catch (_) {}
      deferred = null;
    }, { once: true });
    $('#installNo')?.addEventListener('click', () => sheet.close(), { once: true });
  }, 25000);
  function dialogOpenAny() {
    return $$('dialog[open]').some(d => d.id !== 'installSheet');
  }
})();

/* ─── Konami Code (ویژگی ۱۰) ──────────────────────────────────────────────
   ↑↑↓↓←→←→ba → hue-rotate معکوس برای ۸ ثانیه. در input/textarea فعال
   نیست تا تایپ کاربر هرگز گنج را منفجر نکند. */
(() => {
  const SEQ = ['ArrowUp','ArrowUp','ArrowDown','ArrowDown','ArrowLeft','ArrowRight','ArrowLeft','ArrowRight','b','a'];
  let i = 0;
  addEventListener('keydown', e => {
    if (e.target.closest('input,textarea,select,[contenteditable]')) { i = 0; return; }
    const k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
    i = (k === SEQ[i]) ? i + 1 : (k === SEQ[0] ? 1 : 0);
    if (i === SEQ.length) {
      i = 0;
      html.classList.add('konami');
      toast('🥚 گنجِ خانه: طیفِ وارونه — هشت ثانیه.', 'ok');
      setTimeout(() => html.classList.remove('konami'), 8000);
    }
  });
})();

/* ─── Sparklines در hero-ledger (ویژگی ۱۰) ────────────────────────────────
   SVG polyline قطعی (seeded) — همان خط‌ها برای همه؛ تزئینی و aria-hidden.
   append داخل dd است ولی محتوای متنی dt/dd دست‌نخورده می‌ماند. */
(() => {
  if (lowTier()) return;
  const items = $$('.hero-ledger__i dd');
  items.forEach((dd, idx) => {
    let x = 137 + idx * 89;
    const pts = [];
    for (let i = 0; i < 12; i++) {
      x = (x * 9301 + 49297) % 233280;
      pts.push(`${i * 5},${(24 - (6 + (x / 233280) * 14)).toFixed(1)}`);
    }
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'spark'); svg.setAttribute('viewBox', '0 0 55 24');
    svg.setAttribute('width', '55'); svg.setAttribute('height', '24');
    svg.setAttribute('aria-hidden', 'true');
    const pl = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    pl.setAttribute('points', pts.join(' '));
    pl.setAttribute('fill', 'none'); pl.setAttribute('stroke', 'var(--accent)');
    pl.setAttribute('stroke-width', '1.2');
    svg.append(pl); dd.append(svg);
  });
})();
})();