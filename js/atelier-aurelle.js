/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · atelier-aurelle.js
   نمای آتلیه — the workshop, as a view of the collection

   ─── چرا این نسخه با آنچه قبلاً بود فرق دارد ────────────────────────────
   نسخهٔ قبل یک داشبورد بود: پنج سفارش با نام و شمارهٔ ساختگی، چهار گزارش
   ساختگی از فعالیت کارگاه، و سه کارمند ساختگی — همه از ثابت‌های نمایشی در
   data.js، و همه با عنوان «کارگاه امروز، زنده».

   آن داشبورد هرگز به صفحه وصل نشده بود: `ae:render-atelier` منتشر می‌شد و
   هیچ‌کس گوش نمی‌داد، پس #view-atelier فقط یک سرتیتر خالی نشان می‌داد. این
   یک تصادف خوش‌یمن بود.

   اگر وصلش می‌کردیم، اُرِل به مشتری‌های واقعی درآمد ساختگی و نام مشتری‌های
   ساختگی و «۵ دقیقه پیش»ِ ساختگی نشان می‌داد. برای یک مزون، عدد دروغین
   بدترین چیزی است که می‌شود گفت: بقیهٔ صفحه دربارهٔ کیفیت و صداقت است.

   پس این نسخه هر عددی را که نشان می‌دهد از کاتالوگ واقعی می‌گیرد: تعداد
   فرم‌های موجود، کمترین موجودی هر فرم، و روزهای تحویلی که خودِ کاتالوگ
   اعلام می‌کند. چیزی ساخته نشده، چیزی ادعا نشده.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
'use strict';
const { $, esc, faNum, faPad, moneyT } = window.AE;
const { PRODUCTS, catLabel } = window.AE_DATA;

/* A Persian date, because the maison's copy is Persian and an ISO string in the
   middle of a sentence about the collection is the kind of thing that makes a
   page feel translated rather than written. Uses Intl rather than a month table
   so it cannot drift from the runtime's calendar. */
const FA_DATE = new Intl.DateTimeFormat('fa-IR', {
  year: 'numeric', month: 'long', day: 'numeric',
});
const faToday = () => FA_DATE.format(new Date());

/* Stock for one product, summed from its per-size rows — the same derivation
   velora_catalog_client_shape() makes on the server, so the number in the atelier
   is the number on the card. */
const stockOf = p => (Array.isArray(p.sizes) ? p.sizes : [])
  .reduce((s, r) => s + Math.max(0, Number(r && r.stock) || 0), 0);

/* The sizes that are actually buildable right now, for one product. This is the
   honest version of "the workshop is working on this": not a fabricated job
   card, but the real size run of a real pair. */
const buildable = p => (Array.isArray(p.sizes) ? p.sizes : [])
  .filter(r => (Number(r && r.stock) || 0) > 0)
  .map(r => faNum(r.eu));

/* Sold-out sizes, which is the number a workshop actually plans around.

   The first version of this measured a product's *total* stock, and every one of
   the eleven is above the threshold — so the panel read "کمترین موجودی: —" and
   "موجودی پایدار" across the top of a maison whose sizes are sold out. That is
   the wrong unit entirely: a pair is not available if the customer's size is not,
   and a product with four pairs in stock and nothing in 41 is unavailable to
   exactly the customer who wants it.

   So the constraint is counted in (product, size) pairs, which is the same thing
   api.php's stock check works in. */
const sizesOf = p => (Array.isArray(p.sizes) ? p.sizes : [])
  .map(r => ({ eu: Number(r && r.eu) || 0, stock: Math.max(0, Number(r && r.stock) || 0) }))
  .filter(r => r.eu > 0);

const soldOutSizes = p => sizesOf(p).filter(r => r.stock === 0).length;
const totalSoldOut = PRODUCTS.reduce((s, p) => s + soldOutSizes(p), 0);

/* Pieces with a gap in their size run: the ones a workshop has to re-cut, and
   the ones worth a customer knowing before they choose a size. */
const gapped = PRODUCTS
  .filter(p => soldOutSizes(p) > 0)
  .sort((a, b) => (a.sizes.length - soldOutSizes(a)) - (b.sizes.length - soldOutSizes(b)));

function renderAtelier() {
  const view = $('#view-atelier');
  if (!view) return;
  const host = $('#at-home', view);
  if (!host) return;

  const live = PRODUCTS.filter(p => stockOf(p) > 0);

  /* The three headline figures, all counted from products.json, so none of them
     can be wrong without the collection being wrong — which is the property a
     "live workshop" panel needs and did not have. */
  const kpis = [
    { k: 'فرم‌های مجموعه', v: faNum(PRODUCTS.length), d: `${faNum(live.length)} فرم آمادهٔ ارسال` },
    { k: 'سایزهای ناموجود', v: faNum(totalSoldOut), d: totalSoldOut ? 'قابل بازپرشدن در کارگاه' : 'همهٔ سایزها موجود' },
    { k: 'فرم‌های نیازمند تکمیل', v: faNum(gapped.length), d: gapped.length ? 'دست‌کم یک سایز خالی' : 'بدون نقص سایز' },
  ];

  /* What is on the bench: the pieces with the least complete size run. When
     nothing has a gap — a collection fully in stock — this falls back to the
     smallest remaining stock, so the panel always has something true to say
     rather than an empty list. */
  const ranked = gapped.length
    ? gapped.slice()
    : live.slice().sort((a, b) => stockOf(a) - stockOf(b));
  const bench = ranked.slice(0, 6);

  host.innerHTML = `
    <article class="at-card rv in">
      <div class="at-greet">
        <div>
          <p class="kicker accent-txt">آتلیه · ${esc(faToday())}</p>
          <h1 class="at-title">کارگاه امروز، <em class="gold-foil">زنده</em></h1>
          <p class="at-sub">هر عددی که می‌بینید از کاتالوگ همین صفحه خوانده شده است.</p>
        </div>
        <div class="at-ava" aria-hidden="true">اُ</div>
      </div>

      <div class="at-kpi">
        ${kpis.map(x => `<div class="kpi">
          <span class="k">${esc(x.k)}</span>
          <span class="v">${esc(x.v)}</span>
          <span class="d nu">${esc(x.d)}</span>
        </div>`).join('')}
      </div>
    </article>

    <article class="at-card at-card--mt rv in">
      <div class="at-card-head">
        <div>
          <h2 class="at-card-sub">روی میز کار</h2>
          <p class="at-card-sub">${gapped.length
            ? 'فرم‌هایی که دست‌کم یک سایزشان تمام شده است.'
            : 'تمام سایزهای مجموعه موجود است.'}</p>
        </div>
        <span class="mono" style="font-size:.58rem;color:var(--text-4)">${faNum(bench.length)} فرم</span>
      </div>
      <div class="at-bench">
        ${bench.map(p => `<div class="at-row">
          <div>
            <b>${esc(p.name)}</b>
            <span class="mono">${esc(catLabel(p.cat))}</span>
          </div>
          <span class="mono at-sizes">${esc(buildable(p).join(' · ') || '—')}</span>
          <span class="mono at-left ${stockOf(p) <= 4 ? 'is-low' : ''}">${faNum(stockOf(p))} جفت</span>
        </div>`).join('')}
      </div>
    </article>

    <article class="at-card at-card--mt rv in">
      <div class="at-card-head">
        <div>
          <h2 class="at-card-sub">تحویل</h2>
          <p class="at-card-sub">زمانی که خودِ کاتالوگ برای هر فرم اعلام می‌کند.</p>
        </div>
      </div>
      <div class="at-bench">
        ${PRODUCTS.slice(0, 6).map(p => `<div class="at-row">
          <div><b>${esc(p.name)}</b></div>
          <span class="mono at-left">${faNum(p.eta || 0)} روز کاری</span>
          <span class="mono">${esc(moneyT(p.price))}</span>
        </div>`).join('')}
      </div>
    </article>`;
}

window.AE_ATELIER = { renderAtelier };

/* The listener that was missing.

   `ae:render-atelier` has been dispatched from two places since before the
   atelier module was written, and nothing was listening, so switching to the
   atelier showed the static header and nothing else. Registering here rather
   than at the dispatch sites keeps one owner of "what the atelier view is". */
addEventListener('ae:render-atelier', () => {
  try { renderAtelier(); } catch (_) { /* never break the mode switch */ }
});
})();