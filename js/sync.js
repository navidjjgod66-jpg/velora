/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — sync.js
   لایهٔ همگام‌سازی ویترین با پنل مدیریت
   ─────────────────────────────────────────────────────────────────────
   کاتالوگ پایه در js/data.js است تا سایت بی‌سرور هم کامل باشد. اگر مدیر
   چیزی ذخیره کرده باشد، api/catalog.php آن لایه را می‌دهد و اینجا روی
   همان آبجکت‌های موجود می‌نشیند (نه جایگزینی کامل) تا بقیهٔ ماژول‌ها
   که CATALOG/PRODUCTS را در زمان بارگذاری گرفته‌اند، همان‌ها را ببینند.

   اگر سرور در دسترس نبود یا انباره خالی بود، هیچ اتفاقی نمی‌افتد.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const D = window.AE_DATA;
if (!D || !window.aeApi) return;

const SET_FIELDS = ['name','sub','cat','family','heel','price','oldPrice','badge',
                    'isNew','stock','rating','reviews','img','gallery','sw'];

/** داده‌های تازه را روی AE_DATA می‌نشاند و ۱ اگر چیزی عوض شده باشد. */
function apply(d) {
  if (!d || typeof d !== 'object') return 0;
  const items   = d.items   || {};
  const order   = d.order   || [];
  const hidden  = d.hidden  || [];
  const removed = d.removed || [];
  let touched = 0;

  /* ۱) پاک‌سازی: پنهان‌ها و حذف‌شده‌ها از data.js هم می‌روند */
  const drop = new Set([...hidden, ...removed].map(String));
  for (let i = D.PRODUCTS.length - 1; i >= 0; i--) {
    const id = D.PRODUCTS[i] && D.PRODUCTS[i].id;
    if (id && drop.has(String(id))) {
      D.PRODUCTS.splice(i, 1);
      delete D.CATALOG[id];
      touched++;
    }
  }

  /* ۲) روی‌نشستن لایهٔ مدیریت روی فرم‌های موجود و ساختن فرم‌های تازه */
  for (const [id, rec] of Object.entries(items)) {
    if (!rec || typeof rec !== 'object') continue;
    const cur = D.CATALOG[id];
    if (cur) {
      for (const f of SET_FIELDS) {
        if (rec[f] === undefined) continue;
        if (Array.isArray(rec[f]) ? String(cur[f]) !== String(rec[f]) : cur[f] !== rec[f]) touched++;
        cur[f] = rec[f];
      }
    } else {
      const p = Object.assign({ id: String(id) }, rec);
      D.PRODUCTS.push(p);
      D.CATALOG[id] = p;
      touched++;
    }
  }

  /* ۳) ترتیب نمایش — آنچه سرور گفته جلو می‌آید، بقیه به همان ترتیب پایه */
  const alive = D.PRODUCTS.map(p => p.id);
  const rank  = new Map(order.map((id, i) => [String(id), i]));
  const fromServer = alive.filter(id => rank.has(String(id)))
                          .sort((a, b) => rank.get(String(a)) - rank.get(String(b)));
  const rest = alive.filter(id => !rank.has(String(id)));
  const final = fromServer.concat(rest);
  if (final.join('|') !== D.ORDER.join('|')) {
    D.ORDER.length = 0;
    final.forEach(id => D.ORDER.push(id));
    touched++;
  }

  /* ۴) کد تخفیف — همان آبجکت زنده، پس ماژول‌های دیگر هم می‌بینند */
  const p = d.promo;
  if (p && p.set && p.code && D.PROMO && D.PROMO.code !== p.code) {
    D.PROMO.code = p.code;
    D.PROMO.pct  = Number(p.pct) || 0;
    touched++;
  }

  /* ۵) سبد و علاقه‌مندی‌ها نباید به فرم حذف‌شده اشاره کنند */
  const st = window.AE_STATE;
  if (st && touched) {
    const keep = st.state.cart.filter(i => D.CATALOG[i.id]);
    const kw   = st.state.wish.filter(id => D.CATALOG[id]);
    if (keep.length !== st.state.cart.length) { st.state.cart = keep; st.persBag(); touched++; }
    if (kw.length !== st.state.wish.length)   { st.state.wish = kw;   st.persWish(); touched++; }
    if (st.state.promo && D.PROMO && !D.PROMO.valid(st.state.promo)) {
      st.state.promo = null;
      window.AE.LS.set(window.AE.K.promo, null);
    }
    /* اگر روی فرمی که حذف شده ایستاده بودیم، به خانه برگرد */
    if (st.state.pdpId && !D.CATALOG[st.state.pdpId]) st.state.pdpId = null;
  }
  return touched;
}

async function run(force) {
  try {
    const r = await window.aeApi.catalogFetch(force);
    if (!r || !r.changed) return 0;
    const n = apply(r.data);
    if (n) window.dispatchEvent(new CustomEvent('ae:catalog-sync', { detail: { at: r.data.updated } }));
    return n;
  } catch (_) {
    return 0;   /* بی‌صدا: ویترین همان data.js می‌ماند */
  }
}

/* بازخوانی پس از برگشت به تب (ممکن است مدیر در این فاصله ذخیره کرده باشد) */
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') run(false);
});

window.AE_SYNC = { apply, run, get ready() { return run(false); } };
})();