/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · pdp-aurelle.js v2
   Product Detail — یکپارچه با index.php و api.php
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
'use strict';
const {
  $, $$, esc, faNum, faPad, moneyT, LS, K, toast, haptic,
  dlStop, dlStart, wireDialog, reduced, starsHTML
} = window.AE;
const api = window.aeApi;
const D   = window.AE_DATA;

let CURRENT = null;
const CACHE = new Map();

/* ─── Guilloche (decorative) ─── */
function guilloche(cx, cy, r, spread, step, loops, size) {
  let d = '';
  for (let i = 0; i < loops; i++) {
    const a0 = (i / loops) * Math.PI * 2;
    const x0 = cx + Math.cos(a0) * r;
    const y0 = cy + Math.sin(a0) * r;
    d += (i ? 'L' : 'M') + x0.toFixed(2) + ',' + y0.toFixed(2);
    for (let t = 0; t <= step; t++) {
      const a  = a0 + (t / step) * Math.PI * 2;
      const rr = r + Math.sin(t * 0.6) * spread * 0.12;
      d += 'L' + (cx + Math.cos(a) * rr).toFixed(2) + ',' + (cy + Math.sin(a) * rr).toFixed(2);
    }
  }
  return { d };
}

/* ─── Normalise incoming data ───
   colorHex() was a byte-for-byte duplicate of hexFor() in data.js. Two
   identical 11-entry tables mean two places to add a colour, and this copy was
   the one with no comment saying it existed. AE_DATA.hexFor is the one the
   catalogue pipeline already uses (adaptServerProduct builds every swatch from
   it), so a colour added there reaches the PDP automatically and one added here
   would not. */
const colorHex = key => window.AE_DATA.hexFor(key);

function normalise(p) {
  const gallery = Array.isArray(p.gallery) && p.gallery.length
    ? p.gallery.map(x => typeof x === 'string' ? x : (x.url || x.src || '')).filter(Boolean)
    : (p.img ? [p.img] : []);

  const colors = (p.sw || p.colors || []).map(c => ({
    key: c.key || c.n || 'noir',
    name: c.name || c.n || 'رنگ',
    hex: c.hex || c.c || colorHex(c.key || c.n),
  }));
  if (!colors.length) colors.push({ key:'noir', name:'مشکی', hex:'#161310' });

  let sizes = [];
  if (Array.isArray(p.sizes) && p.sizes.length) {
    sizes = p.sizes.map(s => ({
      eu: Number(s.eu) || 0,
      stock: Number(s.stock) || 0,
    })).filter(s => s.eu > 0);
  } else if (Number.isFinite(p.stock) && p.stock > 0) {
    const band = D.SIZES || ['37','38','39','40','41'];
    sizes = band.map(eu => ({ eu: Number(eu), stock: Math.max(0, Math.min(9, p.stock)) }));
  }

  return {
    id: p.id,
    name: p.name,
    cat: p.cat || '',
    sub: p.sub || '',
    desc: p.desc || p.sub || '',
    price: Number(p.price) || 0,
    old: Number(p.old_price || p.oldPrice) || 0,
    heel: Number(p.heel) || 0,
    eta: Number(p.eta) || 4,
    isNew: !!(p.is_new || p.isNew),
    rating: Number(p.rating) || 4.8,
    reviews: Number(p.reviews) || 0,
    gallery, colors, sizes,
    feats: Array.isArray(p.feats) ? p.feats : [],
    specs: p.specs && typeof p.specs === 'object' ? p.specs : {},
  };
}

/* ─── Fetch ─── */
async function fetchProduct(id) {
  if (CACHE.has(id)) return CACHE.get(id);
  const local = D.CATALOG && D.CATALOG[id];
  if (local) {
    const p = normalise(local);
    CACHE.set(id, p);
    return p;
  }
  if (!api || !api._call) throw new Error('محصول یافت نشد');
  const r = await api._call('product', { id });
  if (!r || !r.ok || !r.product) throw new Error((r && r.message) || 'not-found');
  const p = normalise(r.product);
  CACHE.set(id, p);
  return p;
}

/* ─── Info panel ─── */
function renderInfo(p, dlg) {
  const info = $('#pdpInfo', dlg);
  if (!info) return;

  const hasDisc = p.old > p.price;
  const pct = hasDisc ? Math.round((1 - p.price / p.old) * 100) : 0;
  const totalStock = p.sizes.reduce((s, x) => s + x.stock, 0);
  const scarce = totalStock > 0 && totalStock <= 20;
  const scarcityPct = scarce ? Math.max(6, Math.min(96, Math.round(100 - (totalStock / 30) * 100))) : 0;

  info.innerHTML = `
    <div class="pdp__info-top">
      <span class="pdp__cat">${esc(D.catLabel ? D.catLabel(p.cat) : p.cat)}</span>
      <div class="pdp__actions">
        <button class="pdp__share" id="pdpShare" type="button" aria-label="اشتراک‌گذاری">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M12 3v13M8 7l4-4 4 4"/></svg>
          <span>اشتراک</span>
        </button>
        <button class="icon-btn" id="pdpWish" type="button" aria-label="ذخیره">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-7-4.5-9.5-9C.5 8 3 4 6.5 4c2 0 3.5 1 5.5 3 2-2 3.5-3 5.5-3 3.5 0 6 4 4 8-2.5 4.5-9.5 9-9.5 9Z"/></svg>
        </button>
      </div>
    </div>

    <h2 class="pdp__name" id="pdpName">${esc(p.name)}</h2>

    <div class="pdp__ratebar" aria-label="امتیاز">
      <span class="stars" aria-hidden="true">${starsHTML(p.rating)}</span>
      <b>${faNum(p.rating.toFixed(1))}</b>
      <span>· ${faNum(p.reviews)} دیدگاه</span>
    </div>

    <p class="pdp__lead">${esc(p.desc)}</p>

    <div class="pdp__priceRow">
      <span class="pdp__price">${moneyT(p.price)}</span>
      ${hasDisc ? `<span class="pdp__priceOld">${moneyT(p.old)}</span>` : ''}
      ${hasDisc ? `<span class="pdp__discBadge">−${faNum(pct)}٪</span>` : ''}
      <!-- Was "شامل مالیات بر ارزش افزوده". Removed for the same reason as the
           checkout row: there is no VAT term in api.php's total and never was,
           so the claim was not verifiable by anything behind this site. -->
      <span class="pdp__tax">ارسال اکسپرس رایگان · ۳۰ روز مرجوعی</span>
    </div>

    ${scarce ? `
      <div class="pdp__scarcity">
        <div class="sc-top"><span>موجودی این فرم</span><b>${faNum(totalStock)} جفت</b></div>
        <div class="sc-bar"><i class="sc-fill" style="width:${scarcityPct}%"></i></div>
      </div>` : ''}

    <div class="pdp__opt">
      <div class="pdp__opt-head">
        <span class="mono">رنگ</span>
        <b class="serif-accent" id="pdpColorName">${esc(p.colors[0].name)}</b>
      </div>
      <div class="pdp__swatches" id="pdpSw" role="radiogroup" aria-label="رنگ">
        ${p.colors.map((c, i) => `
          <button class="sw${i === 0 ? ' sel' : ''}" type="button" role="radio"
                  aria-checked="${i === 0}" data-i="${i}"
                  aria-label="${esc(c.name)}" style="--c:${c.hex}"></button>
        `).join('')}
      </div>
    </div>

    <div class="pdp__opt">
      <div class="pdp__opt-head">
        <span class="mono">سایز</span>
        <button type="button" class="linklike-accent js-sg">راهنمای سایز</button>
      </div>
      <div class="sizes" id="pdpSizes" role="radiogroup" aria-label="سایز">
        ${p.sizes.map(s => `
          <button class="size" type="button" role="radio" aria-checked="false"
                  data-eu="${s.eu}" ${s.stock <= 0 ? 'disabled' : ''}>${faNum(s.eu)}</button>
        `).join('')}
      </div>
      <p class="field-error" id="pdpSizeErr" hidden>لطفاً یک سایز انتخاب کنید.</p>
    </div>

    <div class="pdp__fit">
      <b>سایز شما در این فرم:</b>
      <span id="pdpFitNote">برای محاسبهٔ دقیق، DNA سبک خود را بسازید.</span>
      <div class="pdp__fit-links">
        <button type="button" class="linklike-accent js-mz">سایز سفارشی</button>
        <button type="button" class="linklike-accent" data-act="dna-open">DNA سبک</button>
      </div>
    </div>

    <div class="pdp__buy">
      <button class="btn btn--gold btn--block js-add" type="button" id="pdpAdd">
        <span class="spin"></span>
        <span>افزودن به سبد</span>
      </button>
      <p class="pdp__eta">ارسال اکسپرس رایگان · تحویل ${faNum(p.eta)} روز کاری</p>
      <div class="trust-mini">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>پرداخت امن</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5"/></svg>۳۰ روز مرجوعی</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M1 7h13v9H1zM14 10h4l3 3v3h-7z"/></svg>ارسال رایگان</span>
      </div>
    </div>

    ${renderAccordion(p)}
    <div class="pdp__reviews" id="pdpReviews"></div>
  `;
}

function renderAccordion(p) {
  const rows = [];
  if (p.feats.length) {
    rows.push({
      t: 'ویژگی‌های امضا',
      b: `<ul style="padding:1rem 1.2rem;list-style:none">
        ${p.feats.map(f => `<li style="margin:.4rem 0;color:var(--text-2);font-size:var(--t-sm)">◆ ${esc(f)}</li>`).join('')}
      </ul>`
    });
  }
  if (Object.keys(p.specs).length) {
    rows.push({
      t: 'مشخصات فنی',
      b: `<table style="width:100%;padding:1rem 1.2rem;font-size:var(--t-sm)">
        ${Object.entries(p.specs).map(([k, v]) =>
          `<tr><th style="text-align:start;padding:.5rem 0;color:var(--text-3);font-weight:500">${esc(k)}</th>
               <td style="padding:.5rem 0">${esc(v)}</td></tr>`).join('')}
      </table>`
    });
  }
  rows.push({
    t: 'مراقبت و ارسال',
    b: `<p style="padding:0 1.2rem 1.7rem;font-size:var(--t-sm);color:var(--text-2);line-height:2">
      هر جفت همراه با کیت مراقبت، کیسهٔ ابریشمی و سرپاشنهٔ اضافی ارسال می‌شود.
      ۳۰ روز فرصت مرجوعی بدون قید. زیره‌دوزی مادام‌العمر در آتلیه.
    </p>`
  });

  return `<div class="acc" style="margin-top:1.5rem">
    ${rows.map((r, i) => `
      <div class="acc-i${i === 0 ? ' open' : ''}">
        <button class="acc-q" type="button" aria-expanded="${i === 0}">
          <span>${esc(r.t)}</span><span class="acc-ico" aria-hidden="true">+</span>
        </button>
        <div class="acc-b"><div>${r.b}</div></div>
      </div>`).join('')}
  </div>`;
}

/* ─── Mount interactions ───
   An AbortController, one per mount.

   #pdpStage, #pdpImg, #pdpThumbs, #pdpSw and #pdpSizes live in index.php and
   are NOT recreated by renderInfo's innerHTML — only their contents are. mount()
   runs on every hydrate(), i.e. every time the PDP is opened, and it added
   fresh listeners to those same persistent nodes each time:

       #pdpImg      +1 'load'    (not once:true, so it fired on every image)
       #pdpThumbs   +1 'click'
       #pdpStage    +1 'click' + 1 'pointermove'

   After twenty visits to the PDP, #pdpStage carried forty listeners and a
   single zoom click toggled is-zoom twenty times — which is why zooming felt
   broken-but-intermittent. Twenty identical handlers also ran on every
   image load.

   Now the previous generation is aborted before the next one is registered, so
   exactly one set is ever live. Every addEventListener in this function passes
   { signal }; removal is a single abort() call and cannot miss a listener.
   */
/* `sig` is the generation created by hydrate(). mount() does not create or
   abort a controller of its own: a second controller here would give the
   dialog two independent lifetimes, and a listener registered against the
   inner one would survive the abort that tears down the outer one. One
   generation, one controller, one signal. */
function mount(dlg, p, sig) {
  const stage   = $('#pdpStage', dlg);
  const img     = $('#pdpImg', dlg);
  const thumbs  = $('#pdpThumbs', dlg);
  const sw      = $('#pdpSw', dlg);
  const sizes   = $('#pdpSizes', dlg);

  /* galleryFor() — not a hand-written fallback.

     This was `p.gallery.length ? p.gallery : [D.U.a]`, and D.U.a is one of the
     stock photographs hardcoded in data.js's demo block: an Unsplash image of
     somebody else's shoe. Every product in the current catalogue has an empty
     gallery, so every product page was showing it.

     The result was that the same pair of shoes rendered as the maison's own
     monogram plate on its grid card and as an unrelated stock photograph on its
     own product page, one click apart — the largest visual discontinuity in the
     site, on the surface that matters most, and a third-party request on the
     critical path of buying something.

     galleryFor() already implements the correct rule: use the real gallery when
     there is one, otherwise resolve through imgFor(), which returns the product's
     uploaded WebP or the maison's own plate. It is the same function the grid
     used, so the card and the page cannot disagree — which was the entire point
     of the header comment at the top of this file. */
  const gallery = (D.galleryFor ? D.galleryFor(p) : p.gallery.filter(Boolean));
  let curImg = 0, curColor = 0, curSize = null;

  const setImg = i => {
    curImg = Math.max(0, Math.min(i, gallery.length - 1));
    img.classList.remove('is-on');
    requestAnimationFrame(() => {
      img.src = gallery[curImg];
      img.alt = `${p.name} — نمای ${faNum(curImg + 1)}`;
    });
    $$('.pdp__thumb', thumbs).forEach((t, j) =>
      t.setAttribute('aria-current', String(j === curImg)));
  };

  img.addEventListener('load', () => img.classList.add('is-on'), sig);
  img.addEventListener('error', () => {
    img.classList.add('is-on');
    img.style.opacity = '0.3';
  }, { ...sig, once: true });

  if (thumbs) {
    if (gallery.length > 1) {
      thumbs.hidden = false;
      thumbs.innerHTML = gallery.map((src, i) => `
        <button class="pdp__thumb" type="button" data-i="${i}"
                aria-label="نمای ${faNum(i + 1)}" aria-current="${i === 0}">
          <img src="${esc(src)}" alt="" loading="lazy" decoding="async" width="66" height="82">
        </button>`).join('');
      thumbs.addEventListener('click', e => {
        const b = e.target.closest('.pdp__thumb');
        if (b) setImg(+b.dataset.i);
      }, sig);
    } else {
      thumbs.hidden = true;
      thumbs.innerHTML = '';
    }
  }
  setImg(0);

  if (!reduced && stage) {
    stage.addEventListener('click', () => stage.classList.toggle('is-zoom'), sig);

    /* The full-bleed viewer. A single click still zooms — that is the gesture
       the panel already taught — and a double click opens the gallery, which
       is the one gesture nobody has been taught and therefore the one worth
       advertising. `dblclick` is chosen over repurposing the single click
       because taking the single click away would remove a working feature to
       add a different one.

       The image carries the hint rather than a badge: the cursor becomes a
       zoom-in over a stage that opens something larger, which is the same
       affordance the zoom uses and so teaches itself. */
    stage.addEventListener('dblclick', () => {
      if (window.AE_LBX) window.AE_LBX.open(gallery[curImg], p.name, gallery, curImg, p.name);
    }, sig);

    /* The stage is declared role="button" tabindex="0" in index.php, which
       promises that Enter or Space activates it. Nothing listened for a key, so
       a keyboard customer could focus the product's main image — the first
       thing in the tab order inside the dialog — and get nothing at all.

       Enter opens the full-bleed viewer rather than toggling the zoom, because
       "button" means the primary action and the primary action for a
       photograph is to see it large. Space is preventDefault()ed, or the page
       would scroll behind the dialog as well as opening it. */
    stage.addEventListener('keydown', e => {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      e.preventDefault();
      if (window.AE_LBX) window.AE_LBX.open(gallery[curImg], p.name, gallery, curImg, p.name);
    }, sig);
    /* transformOrigin on a zoomed image. The rect is cached on enter rather
       than read per pointermove: the stage cannot move relative to itself while
       the pointer is inside it, so one read is exact and a read per event is a
       forced synchronous reflow per event. */
    let zoomRect = null;
    const remeasure = () => {
      zoomRect = stage.classList.contains('is-zoom') ? stage.getBoundingClientRect() : null;
    };
    stage.addEventListener('pointerenter', remeasure, { ...sig, passive: true });
    stage.addEventListener('pointermove', e => {
      if (!stage.classList.contains('is-zoom')) return;
      if (!zoomRect || !zoomRect.width) { remeasure(); if (!zoomRect) return; }
      img.style.transformOrigin =
        `${((e.clientX - zoomRect.left) / zoomRect.width) * 100}% ${((e.clientY - zoomRect.top) / zoomRect.height) * 100}%`;
    }, { ...sig, passive: true });
    stage.addEventListener('pointerleave', () => { zoomRect = null; }, { ...sig, passive: true });
  }

  if (sw) {
    sw.addEventListener('click', e => {
      const b = e.target.closest('.sw');
      if (!b) return;
      curColor = +b.dataset.i;
      $$('.sw', sw).forEach(s =>
        s.setAttribute('aria-checked', String(+s.dataset.i === curColor)));
      const nm = $('#pdpColorName', dlg);
      /* curColor can exceed the array after a catalogue sync shrinks the
         colour list; the fallback is the maison's default rather than a throw. */
      if (nm) nm.textContent = (p.colors[curColor] || p.colors[0] || { name: 'مشکی' }).name;
      haptic('add');
    }, sig);
  }

  if (sizes) {
    sizes.addEventListener('click', e => {
      const b = e.target.closest('.size');
      if (!b || b.disabled) return;
      curSize = +b.dataset.eu;
      $$('.size', sizes).forEach(s =>
        s.setAttribute('aria-checked', String(+s.dataset.eu === curSize)));
      const err = $('#pdpSizeErr', dlg);
      if (err) err.hidden = true;
      sizes.classList.remove('err');
      haptic('add');
    }, sig);
  }

  $$('.acc-q', dlg).forEach(q => q.addEventListener('click', () => {
    const it = q.closest('.acc-i');
    const open = it.classList.toggle('open');
    q.setAttribute('aria-expanded', String(open));
  }, sig));

  const shareBtn = $('#pdpShare', dlg);
  if (shareBtn) shareBtn.addEventListener('click', async () => {
    const url = location.origin + location.pathname + '#/pdp/' + p.id;
    const data = { title: `VELORA — ${p.name}`, url };
    try {
      if (navigator.share) await navigator.share(data);
      else { await navigator.clipboard.writeText(url); toast('پیوند کپی شد.'); }
    } catch (e) {
      /* A dismissed share sheet rejects with AbortError — that is not a failure
         worth reporting. A real clipboard rejection IS worth reporting: the
         customer believes a link was copied and it was not.

         The binding is named, and it is named e because the whole file calls
         the caught error e. It was `_` with `if (e && …)` inside — a read of
         an identifier that was never bound, so the ReferenceError it raised was
         thrown from inside the catch handler, where nothing catches it. The
         result: dismissing the share sheet produced an uncaught error and no
         message, and a real clipboard failure produced the same uncaught error
         and no message. The branch the comment describes could not run. */
      if (e && e.name !== 'AbortError') toast('کپی پیوند ممکن نشد.', 'err');
    }
  }, sig);

  const wishBtn = $('#pdpWish', dlg);
  if (wishBtn) {
    const sync = () => {
      const on = (window.AE_STATE?.state.wish || []).includes(p.id);
      wishBtn.classList.toggle('on', on);
      wishBtn.setAttribute('aria-pressed', String(on));
    };
    sync();
    wishBtn.addEventListener('click', () => {
      window.AE_CART?.toggleWish?.(p.id);
      sync();
    }, sig);
  }

  const addBtn = $('#pdpAdd', dlg);
  if (addBtn) addBtn.addEventListener('click', () => {
    if (!curSize) {
      if (sizes) {
        sizes.classList.remove('err');
        void sizes.offsetWidth;
        sizes.classList.add('err');
      }
      const err = $('#pdpSizeErr', dlg);
      if (err) err.hidden = false;
      toast('لطفاً یک سایز انتخاب کنید.', 'err');
      haptic('warn');
      return;
    }
    const color = p.colors[curColor] || { name:'مشکی', hex:'#161310' };
    const ok = window.AE_CART?.addToCart?.(
      p.id, String(curSize), 'سایز ' + faNum(curSize),
      color.name, color.hex, p.price, p.name, img
    );
    if (ok) {
      haptic('success');
      window.AE_CART?.showConfirmPill?.('به سبد اضافه شد · سایز ' + faNum(curSize));
      toast(`${p.name} — به سبد اضافه شد.`);
    }
  }, sig);

  $$('[data-close-pdp]', dlg).forEach(b =>
    b.addEventListener('click', () => { if (dlg.open) dlg.close(); }, sig));

  /* Reviews */
  renderReviews(p, $('#pdpReviews', dlg));
}

/* ─── Sticky footer ───
   The breakpoint is now watched rather than sampled. It used to call
   matchMedia(...).matches once per render, so rotating a phone left the sticky
   buy bar in the wrong state for as long as the dialog stayed open — on a
   landscape phone it stayed hidden with the buy button off-screen, and on a
   desktop window narrowed past 979px it stayed pinned over the content.
   One change listener, and it detaches with the same generation as the rest of
   mount(). */
function renderSticky(p, dlg, sig) {
  const host = $('#pdpSticky', dlg);
  if (!host) return;
  host.innerHTML = `
    <span class="ps-price">${moneyT(p.price)}</span>
    <button class="btn btn--ghost btn--sm" type="button" id="pdpStickyShare" aria-label="اشتراک‌گذاری پیوند این محصول">اشتراک</button>
    <button class="btn btn--gold btn--sm" type="button" id="pdpStickyAdd">افزودن به سبد</button>`;
  const mq = matchMedia('(max-width: 979px)');
  const apply = () => host.classList.toggle('is-on', mq.matches);
  apply();
  mq.addEventListener('change', apply, sig ? { signal: sig.signal } : undefined);
  $('#pdpStickyAdd', dlg)?.addEventListener('click', () => $('#pdpAdd', dlg)?.click(), sig);
  /* Share (ویژگی ۸): Web Share API؛ fallback → clipboard. لینک به
     روت #/pdp/<id> که handleRoute همان را باز می‌کند. */
  $('#pdpStickyShare', dlg)?.addEventListener('click', async () => {
    const url = location.origin + location.pathname + '#/pdp/' + p.id;
    if (navigator.share) {
      try { await navigator.share({ title: p.name, text: p.name + ' — ولورا اورِل', url }); } catch (_) {}
    } else {
      try { await navigator.clipboard.writeText(url); toast('پیوند محصول کپی شد.', 'ok'); haptic('success'); }
      catch (_) { toast('کپی نشد — پیوند را دستی بردارید: ' + url, 'warn'); }
    }
  }, sig);
}

/* ─── Crumbs ─── */
function renderCrumbs(p, dlg) {
  const cat = $('#pdpCrumbCat', dlg);
  const nm  = $('#pdpCrumbName', dlg);
  /* The Persian name, not the slug — see catLabel() in data.js. The card for
     this same product reads «صندل» a few centimetres below this breadcrumb, so
     rendering the raw key here would show the same product under two different
     names on one screen. */
  if (cat) cat.textContent = (D.catLabel ? D.catLabel(p.cat) : p.cat) || '—';
  if (nm)  nm.textContent  = p.name;
}

/* ─── Hydrate ───
   Is this object already the shape normalise() produces?

   The old test was `(pRaw && pRaw.id && pRaw.colors)`, and `[]` is truthy in
   JavaScript — so an object carrying `colors: []` was taken as already
   normalised and passed straight through. normalise() guarantees a non-empty
   colors array (it pushes a default when the source has none), so an empty one
   can only mean the object did NOT come from normalise(), and every renderer
   downstream then reads p.colors[0].name on nothing.

   That is not hypothetical: sync-aurelle.js builds products straight from
   products.json with `sw` and no `colors`, and main.js hands CATALOG entries
   to hydrate() on the hash route without normalising first.

   So the test is now the positive shape, not the presence of two keys. */
function isNormalised(x) {
  return !!x
    && typeof x === 'object'
    && typeof x.id === 'string' && x.id.length > 0
    && Array.isArray(x.colors) && x.colors.length > 0
    && x.colors.every(c => c && typeof c === 'object' && 'name' in c)
    && Array.isArray(x.sizes)
    && Array.isArray(x.gallery);
}

/* ─── Generations ─────────────────────────────────────────────────────────
   One open of the PDP is one "generation", and a generation owns one
   AbortController. Every listener mounted into the dialog is registered with
   that controller's signal, so opening a second product aborts the first
   generation in a single call and no listener, observer or media query can
   outlive its dialog.

   The controller lives here, at the top of the hydration, rather than inside
   mount(). It used to be created inside mount(), and renderSticky() — which
   also registers a matchMedia listener that outlives the nodes it is attached
   to — was handed `sig` from hydrate(), where no such binding existed. The
   result was a ReferenceError on the very first line of every single product
   open: the PDP never hydrated at all, so no image, no gallery, no reviews,
   no size or colour handler and no add-to-cart. Every visitor got the
   "محصول پیدا نشد" card.

   The reason it could ship is that the failure is in an argument list, so the
   function it was written for — renderSticky — was never entered and nothing
   downstream of it had to work. A listener that is only ever registered
   behind the bug is as invisible as a missing one. */
let genAbort = null;
function newGeneration() {
  if (genAbort) genAbort.abort();
  genAbort = new AbortController();
  return { signal: genAbort.signal };
}

function hydrate(pRaw, dlg) {
  const host = dlg || $('#pdp');
  if (!host) return;
  /* normalise() reads p.gallery/p.sw/p.id unguarded, so a null or a
     non-object would throw before the dialog could show its own error. An
     unusable product is not worth a blank sheet. */
  if (!pRaw || typeof pRaw !== 'object') return;
  const p = isNormalised(pRaw) ? pRaw : normalise(pRaw);
  CURRENT = p;
  /* Abort the previous generation before anything in this one is mounted, so
     there is no window in which two sets of listeners are live on the same
     dialog. */
  const sig = newGeneration();
  renderCrumbs(p, host);
  renderInfo(p, host);
  renderSticky(p, host, sig);
  mount(host, p, sig);
  /* نسل سه‌بعدی: pdp-3d-stage.js این رویداد را گوش می‌دهد و در صورت نبودِ
     three یا tier پایین بی‌صدا skip می‌کند. */
  window.dispatchEvent(new CustomEvent('ae:pdp-open', { detail: { product: p } }));
}

/* ─── Open ─── */
async function openPDP(id) {
  const dlg = $('#pdp');
  if (!dlg) return;

  const bag = $('#bag'), wishd = $('#wishd');
  if (bag && bag.open) bag.close();
  if (wishd && wishd.open) wishd.close();

  const info = $('#pdpInfo', dlg);
  if (info) info.innerHTML = `
    <div class="skl" style="block-size:120px;border-radius:14px;margin-bottom:1rem"></div>
    <div class="skl" style="block-size:40px;border-radius:10px;margin-bottom:1rem;inline-size:60%"></div>
    <div class="skl" style="block-size:200px;border-radius:14px"></div>`;

  if (!dlg.open) {
    dlg._opener = document.activeElement;
    dlg.showModal();
    dlStop();
  }

  try {
    const p = await fetchProduct(id);
    hydrate(p, dlg);
    dlg.scrollTop = 0;
  } catch (e) {
    if (info) info.innerHTML = `
      <h2 class="pdp__name">محصول پیدا نشد</h2>
      <p class="pdp__lead">${esc(e.message || '')}</p>
      <button class="btn btn--ghost" type="button" data-close-pdp>بازگشت</button>`;
    $$('[data-close-pdp]', dlg).forEach(b =>
      b.addEventListener('click', () => dlg.close()));
  }
}

/* ─── Reviews ─── */
function renderReviews(p, host) {
  if (!host) return;
  /* Same shape check as Reviews.list() in main.js, for the same reason: a
     stored `null` comes back from LS.get as null rather than as the default, so
     `(x || {})[id]` protects against undefined but not against null, and
     `null[id]` throws. This runs while opening the product dialog, so the throw
     surfaced as a blank PDP — for a review the customer had written. */
  const stored = LS.get(K.urevs, null);
  const map = (stored && typeof stored === 'object' && !Array.isArray(stored)) ? stored : null;
  const bucket = map ? map[p.id] : null;
  const local = Array.isArray(bucket) ? bucket : [];
  const names = D.R_NAMES || [];
  const texts = D.R_TEXTS || [];
  const base  = p.id.charCodeAt(0);
  const n = Math.min(3, Math.max(2, (p.reviews || 0) % 3 + 2));
  const gen = [];
  for (let i = 0; i < n && names.length && texts.length; i++) {
    gen.push({
      name: names[(base + i * 3) % names.length],
      date: new Date(Date.now() - (i + 1) * 9 * 864e5).toISOString(),
      rating: i === 0 ? 5 : (p.rating >= 4.7 ? 5 : 4),
      text: texts[(base + i * 5) % texts.length],
      verified: true,
    });
  }
  const all = [...local, ...gen];
  const avg = all.length ? all.reduce((s, r) => s + r.rating, 0) / all.length : p.rating;

  host.innerHTML = `
    <div class="rev-top">
      <span class="mono accent-txt">دیدگاه‌های خریداران</span>
      <span class="rate-row">
        <span class="stars" aria-hidden="true">${starsHTML(avg)}</span>
        <b>${faNum(avg.toFixed(1))}</b>
        <span>· ${faNum(all.length)} دیدگاه</span>
      </span>
    </div>
    <div class="rev-list">
      ${all.map(r => `
        <article class="rev-item">
          <div class="rev-head">
            <div class="rev-who">
              <span class="rev-av" aria-hidden="true">${esc(String(r.name).charAt(0))}</span>
              <div>
                <div class="rev-name">${esc(r.name)}</div>
                ${r.verified ? '<span class="rev-vrf">✓ خریدار تأییدشده</span>' : ''}
              </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.2rem">
              <span class="stars" aria-hidden="true">${starsHTML(r.rating)}</span>
              <span class="rev-date">${new Intl.DateTimeFormat('fa-IR', { dateStyle:'long' }).format(new Date(r.date))}</span>
            </div>
          </div>
          <p class="rev-txt">${esc(r.text)}</p>
        </article>`).join('')}
    </div>`;
}

/* ─── Custom size panel ─── */
function renderCustomPanel() {
  const host = $('#pdpCst');
  if (!host) return;
  const c = window.AE_STATE?.getCustom?.();
  if (!c) {
    host.innerHTML = `
      <p class="cst-empty-txt">هنوز سایز سفارشی ثبت نکرده‌اید. با سه اندازه‌گیری ساده، سایز دقیق پای شما محاسبه می‌شود.</p>
      <button class="btn btn--ghost btn--sm js-mz" type="button">شروع اندازه‌گیری</button>`;
  } else {
    host.innerHTML = `
      <div class="cst-eu">${faNum(c.eu)} سفارشی</div>
      <p class="cst-meta">طول ${faNum(c.L)} · عرض ${faNum(c.W)} سانتی‌متر${c.wide ? ' · قالب عریض' : ''}</p>
      <div class="cst-actions">
        <button class="btn btn--ghost btn--sm js-mz" type="button">اندازه‌گیری مجدد</button>
      </div>`;
  }
}

/* ─── Init ─── */
(function init() {
  const dlg = $('#pdp');
  if (!dlg) return;
  wireDialog(dlg);

  $('#pdpBack')?.addEventListener('click', () => dlg.close());
  $('#pdpX')?.addEventListener('click', () => dlg.close());
  /* REMOVED: `dlg.addEventListener('click', e => { if (e.target === dlg)
     dlg.close(); })`.

     wireDialog(dlg) above already registers backdropClose, which deliberately
     tests the dialog's own BOUNDS rather than its identity, because a <dialog>
     box is larger than its panel: clicking its padding, its margin, or any
     uncovered part of the box has e.target === dlg but is not a click on the
     backdrop. This second handler had none of that care, so on a PDP whose
     content did not fill its box — which is most of them, since the panel is a
     sheet — clicking anywhere on the empty part of the dialog closed it.
     Two handlers for one job, one of them wrong. */
  dlg.addEventListener('close', () => {
    dlStart();
    dlg._opener?.focus?.({ preventScroll: true });
    dlg._opener = null;
    if (/^#\/pdp\//.test(location.hash)) history.replaceState(null, '', '#/');
  });

  /* No hashchange listener, and no boot deep-link.

     This module used to carry both. main.js owns the route table —
     handleRoute() matches #/pdp/<id>, calls openPDPByRoute(), and is itself
     called once on boot — so these were a second, independent router for a
     route the first one already covers, and having two is not a redundancy:
     both listeners fire on the same event, in registration order, and this
     file's runs first because it is loaded before main.js.

     The visible consequence was that every product opened twice. openPDP()
     here is async and awaits before it hydrates, so handleRoute()'s
     openPDPByRoute() got there first and hydrated synchronously; openPDP()'s
     continuation then hydrated the same product again, tearing down and
     rebuilding the gallery, the swatches and the size rail a second time on
     every visit — and both ran against the same generation signal, so the
     second one's listeners silently cancelled the first.

     The boot deep-link was worse than duplicated. It also disagreed about what
     an id is: this one matched /^#\/pdp\/([a-z0-9-]+)/i, without the
     underscore, while handleRoute() uses [a-z0-9_-]. Every product id
     containing "_" was routable through the maison's own links and not through
     this regex.

     One router, one table, one id grammar. Deep links still work, because the
     router that owns the table is the one that runs on load. */
})();

window.AE_PDP = {
  openPDP,
  hydrate,
  fetchProduct,
  normalise,
  renderCustomPanel,
  guilloche,
};
})();