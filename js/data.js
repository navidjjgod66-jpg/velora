/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — data.js
   کاتالوگ محصولات · اندازه‌ها · خانواده‌ها · کد تخفیف · داده‌های آتلیه
   صرفاً داده است — هیچ منطق اجرایی ندارد.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const { PHI, PHI2, faNum, faPad, esc } = window.AE;

/* ═══ Image resolution ═══
   A gallery key resolves in exactly two ways, matching product_image_url() in
   config.php: an absolute https URL (passed through) or a local admin-uploaded
   WebP filename under /storage/uploads/products/.

   Anything else is a bare catalogue id — every product in products.json
   currently has an empty gallery, so `img` is the id itself. Before this
   function existed, that string went straight into <img src="notte">, which
   resolved to a same-origin path /notte and 404'd. Every product card on the
   site was broken the moment the catalogue sync landed. A product with no
   photography now renders the generated SVG plate below: zero requests,
   cannot 404, and it is replaced automatically the moment an admin uploads a
   real photograph. */
const UPLOAD_BASE = (window.VELORA_UPLOADS || '/storage/uploads/products').replace(/\/+$/, '');

const IMG = raw => {
  const s = String(raw == null ? '' : raw).trim();
  if (s === '') return '';
  if (/^https:\/\//i.test(s)) return s;
  if (/^\/storage\/uploads\/products\/[a-zA-Z0-9_-]{1,80}\.webp$/.test(s)) return s;
  if (/^[a-zA-Z0-9_-]{1,80}\.webp$/.test(s)) return UPLOAD_BASE + '/' + s;
  return '';
};

/* The maison's monogrammed plate, drawn inline as a data URI.
   A gold diamond around the form's initial on the maison's own ground. It is
   deterministic per id, so a card does not change appearance between renders,
   and it carries the product's name in `alt` so the grid is never a row of
   unlabelled boxes for a screen reader.

   The monogram is escaped with the esc imported from core.js. This function used
   to declare its own local esc — a near-duplicate that was missing the backtick
   escape, which is precisely the character that would matter if this output were
   ever interpolated into an attribute rather than into element text.

   velora_product_plate() in index.php is the deliberate PHP twin of this: same
   viewBox, same colours, same monogram. It exists so a server-rendered card and
   a client re-render produce identical bytes, which is what removes the 50 KB
   brand PNG from the first paint. The two must be changed together. */
const PLATE_FG = '#d9b98a', PLATE_BG = '#0b0a09';
const plate = (id, label) => {
  const ch = String(label || id || '·').trim().charAt(0) || '·';
  const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 800">' +
    '<rect width="640" height="800" fill="' + PLATE_BG + '"/>' +
    '<rect x="26" y="26" width="588" height="748" fill="none" stroke="' + PLATE_FG +
    '" stroke-opacity=".34" stroke-width="1.5"/>' +
    '<path d="M320 236 404 320 320 404 236 320Z" fill="none" stroke="' + PLATE_FG +
    '" stroke-opacity=".8" stroke-width="2"/>' +
    '<circle cx="320" cy="320" r="86" fill="none" stroke="' + PLATE_FG +
    '" stroke-opacity=".3" stroke-width="1"/>' +
    '<text x="320" y="352" text-anchor="middle" font-family="Georgia,serif" font-size="104" ' +
    'fill="' + PLATE_FG + '" fill-opacity=".92">' + esc(ch) + '</text>' +
    '<text x="320" y="500" text-anchor="middle" font-family="Georgia,serif" font-size="30" ' +
    'letter-spacing="8" fill="' + PLATE_FG + '" fill-opacity=".62">VELORA</text>' +
    '</svg>';
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
};

/* The single entry point every renderer uses. Never returns an empty string:
   a product without photography gets its plate, so no <img> can be born with
   a src that resolves to a 404. */
const imgFor = (p) => {
  if (!p) return plate('', '·');
  const direct = IMG(p.img);
  if (direct) return direct;
  const first = Array.isArray(p.gallery) ? p.gallery.map(IMG).find(Boolean) : '';
  return first || plate(p.id, p.name);
};
const galleryFor = (p) => {
  const list = (Array.isArray(p?.gallery) ? p.gallery : []).map(IMG).filter(Boolean);
  return list.length ? list : [imgFor(p)];
};
const U = {
  a:'https://images.unsplash.com/photo-1543163521-1bf539c55dd6?auto=format&fit=crop&w=1200&q=82',
  b:'https://images.unsplash.com/photo-1579783483458-83d559840e52?auto=format&fit=crop&w=1200&q=82',
  c:'https://images.unsplash.com/photo-1595950653106-6c9ebd614d3a?auto=format&fit=crop&w=1200&q=82',
  d:'https://images.unsplash.com/photo-1600185365404-274280b2fdeb?auto=format&fit=crop&w=1200&q=82',
  e:'https://images.unsplash.com/photo-1596703263926-eb0762ee17e4?auto=format&fit=crop&w=1200&q=82',
  f:'https://images.unsplash.com/photo-1605812860427-4024433a70fd?auto=format&fit=crop&w=1200&q=82',
  g:'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1200&q=82'
};

/* ═══ Products ═══ */
const PRODUCTS = [
  { id:'roza',     name:'رزا',     sub:'مری جین چرم نپا با بند سگک‌دار و یراق‌آلات طلایی', family:'maryjane', cat:'مری جین · ۶۰ میلی‌متر', heel:60,  price:18500000, badge:'پرفروش',      isNew:false, stock:6,  rating:4.8, reviews:132, img:U.a, sw:[{n:'مشکی',c:'#161310',img:U.a},{n:'عاجی',c:'#efe6d8',img:U.c}] },
  { id:'rosha',    name:'روشا',    sub:'لوفر چرم گوساله با دوخت دستی و زیره چرمی',        family:'loafer',   cat:'لوفر · تخت',          heel:15,  price:16900000, badge:'نمادین',      isNew:false, stock:9,  rating:4.7, reviews:118, img:U.b, sw:[{n:'کنیاک',c:'#6b3a1e',img:U.b},{n:'مشکی',c:'#161310',img:U.a}] },
  { id:'termeh',   name:'ترمه',    sub:'پاشنه بلند ساتن ابریشم کومو با پاشنه تراشیده',    family:'highheel', cat:'پاشنه بلند · ۱۰۰ میلی‌متر', heel:100, price:22700000, oldPrice:32400000, badge:'رونمایی هفته', isNew:true, stock:7, rating:5.0, reviews:48, img:U.e, sw:[{n:'برنز',c:'#b8942a',img:U.e},{n:'عاجی',c:'#efe6d8',img:U.c}] },
  { id:'mahur',    name:'ماهور',   sub:'پاشنه کوتاه کالج با چرم نرم و کفی طبی',           family:'lowheel',  cat:'پاشنه کوتاه · ۴۰ میلی‌متر', heel:40,  price:14200000, badge:'جدید',        isNew:true, stock:11, rating:4.6, reviews:87,  img:U.g, sw:[{n:'مشکی',c:'#161310',img:U.g},{n:'بوردو',c:'#5a1f26',img:U.f}] },
  { id:'niloofar', name:'نیلوفر',  sub:'باله تخت ابریشمی با پاپیون دست‌ساز',              family:'ballet',   cat:'باله تخت · ۱۲ میلی‌متر',    heel:12,  price:12500000, badge:'ضروری',       isNew:true, stock:12, rating:4.7, reviews:96,  img:U.c, sw:[{n:'عاجی',c:'#efe6d8',img:U.c},{n:'رز',c:'#d9a8a0',img:U.d}] },
  { id:'azin',     name:'آذین',    sub:'بوت چرم گوساله با پاشنه تراشیده و زیپ مخفی',      family:'boot',     cat:'بوت · ۷۵ میلی‌متر',        heel:75,  price:21400000, badge:'نمادین',      isNew:false, stock:8,  rating:4.8, reviews:154, img:U.f, sw:[{n:'مشکی',c:'#161310',img:U.f},{n:'کنیاک',c:'#6b3a1e',img:U.d}] },
  { id:'yas',      name:'یاس',     sub:'صندل مجلسی با بندهای ظریف و پرداخت طلایی',       family:'sandal',   cat:'صندل · ۹۵ میلی‌متر',       heel:95,  price:11800000, badge:'جدید',        isNew:true, stock:10, rating:4.5, reviews:77,  img:U.c, sw:[{n:'طلایی',c:'#d4af37',img:U.c},{n:'عاجی',c:'#efe6d8',img:U.b}] },
  { id:'golnar',   name:'گلنار',   sub:'پاشنه بلند عروس با ساتن مرواریددوزی‌شده',          family:'highheel', cat:'عروس · ۸۵ میلی‌متر',       heel:85,  price:26900000, badge:'سفارشی',      isNew:false, stock:5,  rating:4.9, reviews:58,  img:U.d, sw:[{n:'عاجی',c:'#efe6d8',img:U.d},{n:'مروارید',c:'#e8e4da',img:U.c}] },
  { id:'sayna',    name:'ساینا',   sub:'مری جین پاشنه کوتاه با بند ضربدری و چرم نرم',     family:'maryjane', cat:'مری جین · ۵۰ میلی‌متر',  heel:50,  price:15600000, badge:'جدید',        isNew:true, stock:9,  rating:4.6, reviews:64,  img:U.g, sw:[{n:'مشکی',c:'#141110',img:U.g},{n:'کنیاک',c:'#6b3a1e',img:U.f}] },
  { id:'toranj',   name:'ترنج',    sub:'لوفر کالج چرم کنیاک با یراق‌آلات برنجی',          family:'loafer',   cat:'لوفر · تخت',              heel:18,  price:17800000, badge:'آتلیه',       isNew:true, stock:7,  rating:4.8, reviews:89,  img:U.f, sw:[{n:'کنیاک',c:'#6b3a1e',img:U.f},{n:'مشکی',c:'#161310',img:U.g}] }
];
/* ═══ Catalogue ═══
   The catalogue has exactly one owner: the server. index.php reads
   products.json through velora_catalog_client_feed() and inlines the result as
   window.VELORA_CATALOG before this file runs, so the first paint is drawn
   from the same records the order endpoint prices from — and the page does not
   have to spend a round trip finding out what it is selling.

   The PRODUCTS array above is only the fallback for a page opened with no PHP
   behind it. It used to be the primary source: the grid rendered ten demo
   products (roza, rosha, termeh…) whose ids matched NOTHING in products.json
   (notte, aurelia, notturno…), and the idle catalogue sync then replaced them
   with the eleven real ones. Every customer therefore saw one shop on first
   paint and a different shop a second or two later — different names,
   different prices, different stock — and a link like #/pdp/roza resolved
   before the sync and 404'd after it.

   One catalogue, one render. The demo set is used only when there is genuinely
   no catalogue to read, and `usingFallbackCatalogue` says so rather than
   letting it pass for the real thing. */
const SERVER_CATALOG = Array.isArray(window.VELORA_CATALOG) ? window.VELORA_CATALOG : null;
const usingFallbackCatalogue = !(SERVER_CATALOG && SERVER_CATALOG.length);

/* Catalogue category slug → the family token the filters and smart-score use.
   Identical to the mapping sync-aurelle.js applies, so a catalogue arriving by
   either path filters the same way. */
function mapFamily(cat) {
  const m = {
    heel:'highheel', flat:'ballet', boot:'boot', sandal:'sandal',
    bridal:'highheel', loafer:'loafer',
  };
  return m[String(cat)] || String(cat) || 'loafer';
}

/* Colour key → hex. The admin picks from a fixed vocabulary, so an unmapped key
   means the vocabulary grew on the server; neutral grey is the honest answer,
   and the swatch's accessible name still comes from the colour's name rather
   than from this value.

   THE owner of this table. index.php carries a third copy in $VELORA_HEX,
   because PHP cannot import a browser module — that one is commented as such
   and is justified. pdp-aurelle.js carried a fourth, byte-identical and
   uncommented, which was pure duplication: a colour added here reached the grid
   but not the PDP. Both of those now point here. */
function hexFor(key) {
  const m = {
    champ:'#b8942a', noir:'#161310', ivory:'#efe6d8', suede:'#a8825c',
    oxblood:'#5a1f26', cognac:'#6b3a1e', pearl:'#e8e4da', satin:'#161310',
    gold:'#d4af37', patent:'#0b0b0e',
  };
  return m[key] || '#8a8a8a';
}

/* server row → the shape every renderer already expects.
   velora_catalog_client_shape() has already coerced and defaulted every field,
   so this is a rename plus the derived ones (stock total, swatches, resolved
   image). */
function adaptServerProduct(row) {
  if (!row || typeof row !== 'object') return null;
  const id = String(row.id || '');
  if (!id) return null;

  const sizes = Array.isArray(row.sizes) ? row.sizes : [];
  let stock = 0;
  for (const s of sizes) stock += Math.max(0, Number(s && s.stock) || 0);

  const colors = (Array.isArray(row.colors) ? row.colors : [])
    .map(c => ({ key: String((c && c.key) || ''), name: String((c && c.name) || '') }))
    .filter(c => c.name);

  const sw = colors.map(c => ({ n: c.name, c: hexFor(c.key) }));
  /* Several renderers index sw by position against colors, so a product with
     colours but no swatch rows must still carry one per colour — or
     pdp-aurelle reads sw[curColor] as undefined and paints no swatch. */
  while (sw.length < colors.length) sw.push({ n: 'مشکی', c: '#161310' });

  const p = {
    id,
    name:    String(row.name || id),
    sub:     String(row.sub || ''),
    desc:    String(row.desc || row.sub || ''),
    cat:     String(row.cat || ''),
    family:  mapFamily(row.cat),
    heel:    Number(row.heel) || 0,
    price:   Number(row.price) || 0,
    oldPrice:Number(row.old) || 0,
    badge:   row.isNew ? 'جدید' : '',
    isNew:   !!row.isNew,
    stock,
    rating:  4.8,
    reviews: 0,
    sizes,
    colors,
    sw,
    gallery: Array.isArray(row.gallery) ? row.gallery.slice()
             : (Array.isArray(row.g) ? row.g.slice() : []),
    feats:   Array.isArray(row.feats) ? row.feats.slice() : [],
    specs:   (row.specs && typeof row.specs === 'object') ? row.specs : {},
    eta:     Number(row.eta) || 4,
    sold:    Number(row.sold) || 0,
  };
  p.img = imgFor(p);
  return p;
}

const CATALOG = {};
const ORDER = [];

if (usingFallbackCatalogue) {
  PRODUCTS.forEach(p => {
    p.img     = imgFor(p);
    p.gallery = Array.isArray(p.gallery) && p.gallery.length ? p.gallery : [p.img];
    CATALOG[p.id] = p;
    ORDER.push(p.id);
  });
} else {
  /* PRODUCTS was missing from this branch, so it kept pointing at the ten demo
     products while CATALOG and ORDER were correctly rebuilt from the server.

     That is the exact class of bug the note above this block describes and then
     half-fixed: the grid is drawn from server HTML and reads CATALOG, so the shop
     looked right, and the split stayed invisible. But PRODUCTS is what the
     command palette searches, what sync-aurelle.js compares against, and what
     the atelier reads — so ⌘K offered eight products the maison does not sell
     (رزا، روشا، ترمه، ماهور، نیلوفر، آذین، یاس، گلنار), every one of them from
     the demo set, and choosing one navigated to #/pdp/roza, which is not in
     CATALOG, so the route handler answered with the not-found dialog.

     A search that offers products that cannot be bought is worse than no search:
     it is the maison confidently listing stock it does not have.

     PRODUCTS is a `const` array, so it is emptied and refilled in place rather
     than reassigned — every module holds this exact reference, which is the same
     constraint applyServerCatalog() exists to respect. */
  PRODUCTS.length = 0;
  SERVER_CATALOG.forEach(row => {
    const p = adaptServerProduct(row);
    if (!p) return;
    CATALOG[p.id] = p;
    ORDER.push(p.id);
    PRODUCTS.push(p);
  });
}

/* Whether the grid on screen came from the server. sync-aurelle.js reads this
   to decide whether an idle re-check can change anything. */
window.VELORA_CATALOG_SYNCED = !usingFallbackCatalogue;

/* Swap in a catalogue that arrived after boot.
   One place owns the swap, because getting it wrong is how the storefront used
   to break: PRODUCTS is exported by reference and every renderer holds that
   same array, so replacing it would leave half the page reading the old one.
   Mutating the two live structures in place — CATALOG's keys and PRODUCTS'
   length — keeps every existing reference valid, then one event tells the
   renderers to redraw.

   Returns false rather than half-applying when the payload is unusable, so a
   bad response can never leave a customer looking at an empty grid. */
function applyServerCatalog(rows) {
  if (!Array.isArray(rows) || !rows.length) return false;

  const cat = {}, order = [], list = [];
  for (const row of rows) {
    const p = adaptServerProduct(row);
    if (!p) continue;
    cat[p.id] = p;
    order.push(p.id);
    list.push(p);
  }
  if (!list.length) return false;

  Object.keys(CATALOG).forEach(k => delete CATALOG[k]);
  Object.assign(CATALOG, cat);
  PRODUCTS.length = 0;
  PRODUCTS.push(...list);
  ORDER.length = 0;
  ORDER.push(...order);

  window.VELORA_CATALOG_SYNCED = true;
  window.dispatchEvent(new CustomEvent('ae:catalog-sync'));
  window.dispatchEvent(new CustomEvent('ae:render-recent'));
  return true;
}

/* ═══ Sizes ═══
   This list used to be a hand-written '36'…'46'. The server does not accept
   it: api.php's checkout action rejects any line whose eu_size falls outside
   SIZE_MIN…SIZE_MAX (37…41, defined once in config.php), throwing INVALID_SIZE
   and failing the ENTIRE order. A customer could therefore pick 36 or 42–46
   from the size sheet, fill in all three checkout steps, and be refused at the
   last request with nothing on screen naming the size.

   The band is now read from the server, which index.php publishes from the
   same two constants the order endpoint enforces, and this table is only the
   fallback for a page opened with no PHP behind it. Raising the range is one
   edit in config.php and now takes effect on both sides at once. */
const SERVER_SIZE_BAND = (() => {
  const raw = Array.isArray(window.VELORA_SIZE_BAND) ? window.VELORA_SIZE_BAND : null;
  if (!raw || !raw.length) return null;
  const out = [];
  for (const v of raw) {
    const n = Math.floor(Number(v));
    if (Number.isFinite(n) && n > 0 && n <= 99 && !out.includes(n)) out.push(n);
  }
  return out.length ? out : null;
})();
const SIZE_BAND = SERVER_SIZE_BAND || [37, 38, 39, 40, 41];
const SIZES = SIZE_BAND.map(String);

/* Foot length in cm, EU→cm. The maison's own measurement table (made on the
   archive last), not a formula: the grade is fixed at φ-based intervals and
   interpolating between two points gives a number the atelier does not use.
   The table covers the band above plus the neighbours the guide mentions, so a
   size shown here always has a reading. */
const CONV = {
  '36':'طول پا ۲۲.۷ سانتی‌متر','37':'طول پا ۲۳.۳ سانتی‌متر','38':'طول پا ۲۴.۰ سانتی‌متر',
  '39':'طول پا ۲۴.۷ سانتی‌متر','40':'طول پا ۲۵.۳ سانتی‌متر','41':'طول پا ۲۶.۰ سانتی‌متر',
  '42':'طول پا ۲۶.۶ سانتی‌متر','43':'طول پا ۲۷.۳ سانتی‌متر','44':'طول پا ۲۸.۰ سانتی‌متر',
  '45':'طول پا ۲۸.۷ سانتی‌متر','46':'طول پا ۲۹.۳ سانتی‌متر'
};
const FAM = { all:'همه', maryjane:'مری جین', loafer:'لوفر', highheel:'پاشنه بلند', lowheel:'پاشنه کوتاه', ballet:'باله تخت', boot:'بوت', sandal:'صندل', wish:'ذخیره‌شده' };

/* Catalogue slug → the name the maison shows.

   index.php publishes VELORA_CATEGORIES from CATEGORY_LABELS in config.php, the
   same constants that render the server-side card, so the card and the product
   page cannot disagree about what a product is called.

   FAM above is a *different* map and cannot be substituted: it is keyed by the
   family token the filters use (highheel, ballet) rather than by the catalogue
   slug (heel, flat), and it has no entry for bridal at all. Reaching for it here
   is how a product ends up labelled by the filter it happens to fall into
   instead of by what it is — which is how a bridal shoe would be introduced as
   «پاشنه بلند».

   An unmapped slug falls through to the slug itself. A key that is visible is a
   key someone will report; a key silently folded into the nearest category is a
   mislabelled product nobody will ever notice. */
const CAT_LABELS = Object.assign({}, window.VELORA_CATEGORIES || {});
const catLabel = cat => CAT_LABELS[String(cat)] || String(cat || '');
const ARTISANS = ['سی. بومون','ام. روسی','آ. تاناکا','ال. مارشان','ای. کلر'];
const CARE = 'هر جفت همراه با کیت مراقبت، کیسهٔ ابریشمی و سرپاشنهٔ اضافی ارسال می‌شود. ۶۰ روز مرجوعی، بدون استفاده، بدون سؤال — و زیره‌دوزی مجدد مادام‌العمر در آتلیه.';

/* ═══ Promo ═══
   Built from window.VELORA_VOUCHERS, which index.php publishes from
   velora_voucher_map() in config.php — the same array validate_voucher() reads.

   It used to be a literal: `code: 'VELORA10', pct: 10`, and `match()` compared
   against that. VELORA10 is not a code the server knows; the real ones are
   VEL10, WELCOME10 and VIP20. So a customer who entered the code the interface
   itself advertised got INVALID_CODE from the server and paid full price, while
   the bag and the checkout summary showed a 10% discount. index.php's
   announcement bar advertises a third name, WELCOME10.

   The percentage was also uncapped. VEL10 stops at 5,000,000 and WELCOME10 at
   3,000,000, so above those thresholds the client showed a bigger discount than
   the till would grant. `cap` and `min` are now part of the object the client
   stores and state.js applies, so the two sides cannot disagree.

   The default code is the one with no minimum and no first-purchase condition,
   because that is the only one that is valid for every customer at every
   basket size. */
const VOUCHERS = (window.VELORA_VOUCHERS && typeof window.VELORA_VOUCHERS === 'object' && !Array.isArray(window.VELORA_VOUCHERS))
  ? window.VELORA_VOUCHERS
  : { VEL10: { type: 'pct', value: 10, cap: 5_000_000, min: 0 } };

const PROMO = {
  table: VOUCHERS,
  /* The house code the interface offers when none has been typed. VEL10 is the
     only one of the three with no minimum subtotal and no first-purchase
     condition, so it is the only one valid for every customer at every basket
     size. */
  code: 'VEL10',
  normalize(raw) {
    return String(raw == null ? '' : raw).replace(/[\s\u00A0_-]+/g, '').toUpperCase();
  },
  /* Resolve a typed code to its full definition, or null. The caller still has
     to check `min` against the subtotal and `first_only` against the session —
     both are enforced by the server, and a client that guesses at them can only
     disagree with it. */
  lookup(raw) {
    const k = PROMO.normalize(raw);
    const v = PROMO.table[k];
    if (!v || v.type !== 'pct') return null;
    return { code: k, pct: Number(v.value) || 0, cap: Number(v.cap) || 0, min: Number(v.min) || 0 };
  },
  match(raw) { return !!PROMO.lookup(raw); },
  valid(saved) {
    /* Re-validate against the live table, not just the shape: a code that has
       since been removed from config.php must stop applying in the browser at
       the same moment it stops applying at the till. */
    if (!saved || typeof saved !== 'object') return false;
    const v = PROMO.lookup(saved.code);
    if (!v) return false;
    const pct = Number(saved.pct);
    return Number.isFinite(pct) && pct > 0 && pct <= 100 && saved.code === v.code;
  }
};

/* ═══ Reviews data ═══ */
const R_NAMES = ['نازنین ک.','پریسا م.','آیدا ر.','غزل ح.','مهسا ت.','یلدا ش.','نگار ف.','ساناز ق.','رها د.','مینا ص.'];
const R_TEXTS = [
  'کیفیت چرم فوق‌العاده است، دقیقاً همان چیزی که انتظار داشتم.',
  'قالب بسیار راحت است، حتی در استفادهٔ طولانی.',
  'دوخت دستی و جزئیات واقعاً چشمگیر است.',
  'رنگ و پرداخت دقیقاً مثل تصاویر است.',
  'سایزبندی دقیق و راهنمای سایز کاربردی بود.',
  'بسته‌بندی و ارسال بسیار حرفه‌ای و سریع بود.'
];

/* ═══ Records ═══ */
const RECS = [
  { stats:[['۹','سال پوشش'],['۲','زیره‌دوزی'],['۴۱','شهر']], q:'دو بار زیرهٔ ساعتِ طلایی را عوض کرده‌ام و حالا بهتر از روز اول شده.', who:'ایزابل مورو', loc:'پاریس', order:'AE-40218', ini:'ای' },
  { stats:[['۶','سال پوشش'],['۱','زیره‌دوزی'],['۱٬۹۰۰','کیلومتر']], q:'مینو را از مراسم تا سه شب پوشیدم — زیباترین کفشی که دارم.', who:'سوفیا مارکتی', loc:'فلورانس', order:'AE-31190', ini:'سو' },
  { stats:[['۴','سال پوشش'],['۰','زیره‌دوزی'],['۳','زمستان']], q:'بوت‌های مخمل سه زمستان توکیو را دیده‌اند.', who:'یوکی تاناکا', loc:'توکیو', order:'AE-52877', ini:'یو' }
];

/* ═══ Ticker items ═══ */
const TICKER_ITEMS = [
  'ارسال رایگان اروپا بالای ۱۸۰ یورو','دوخت گودیر در فلورانس',
  'زیره‌دوزی مادام‌العمر','DNA سبک هوشمند',
  'نسخهٔ ابدی — دویست جفت در فصل','از ۱۹۶۲ — هنوز آرام',
  '۶۸ مرحله. ۲۴۰ کوک. ۱ زیره.','آیین‌های نگهداری مادام‌العمر'
];

/* ═══ Fit wizard steps ═══ */
const FIT_STEPS = [
  { key:'foot', title:'فرم پای شما چگونه است؟', sub:'پروفایل پایه', opts:[
    { v:'narrow', ic:'▪', t:'باریک', s:'پنجهٔ جمع‌وجور' },
    { v:'normal', ic:'◆', t:'معمولی', s:'استاندارد' },
    { v:'wide',   ic:'⬢', t:'پهن',   s:'پنجهٔ باز' }]},
  { key:'use', title:'بیشترین کاربرد؟', sub:'هوش اُرِل بر این اساس امتیاز می‌دهد', opts:[
    { v:'daily',   ic:'👟', t:'روزمره', s:'اسنیکر، لوفر، داربی' },
    { v:'formal',  ic:'🥿', t:'رسمی',   s:'داربی، پامپ، اسلینگ‌بک' },
    { v:'evening', ic:'👠', t:'مجلسی',  s:'پامپ، کیتن، عروس' }]},
  { key:'arch', title:'قوس پا؟', sub:'برای انتخاب کفی مناسب', opts:[
    { v:'low',  ic:'▬', t:'صاف',    s:'نیاز به حمایت بیشتر' },
    { v:'mid',  ic:'◗', t:'معمولی', s:'کفی استاندارد' },
    { v:'high', ic:'◠', t:'بلند',   s:'کفی نرم و انعطافی' }]},
  { key:'style', title:'سبک شما؟', sub:'DNA سبک شخصی می‌سازیم', opts:[
    { v:'minimal',  ic:'□', t:'مینیمال', s:'خط ساده، رنگ خاموش' },
    { v:'classic',  ic:'◆', t:'کلاسیک',  s:'فرم‌های ابدی' },
    { v:'bold',     ic:'▲', t:'جسور',    s:'حجم و رنگ' },
    { v:'romantic', ic:'◈', t:'رمانتیک', s:'پاشنه، ساتن، نرمی' }]},
  { key:'size', title:'سایز فعلی شما؟', sub:'سایز اروپایی', opts: SIZES.map(s => ({ v:s, t:faNum(s) })) }
];

/* ═══ Idx cats ═══ */
const IDX_CATS = ['maryjane','loafer','highheel','lowheel','ballet','boot','sandal'];

/* ═══ Order limits ═══
   MAX_PER_LINE is the FALLBACK cap only. index.php publishes the server's real
   ceiling as window.VELORA_MAX_LINE (from MAX_LINE in config.php), and
   state.js prefers that. This number exists so the cart still has a limit
   before the boot script has run, and for the no-PHP case — it is not a second
   source of truth, and it is deliberately lower than the old value of 20 so
   that a cart built before the value arrives can never exceed what the server
   will accept. */
const MAX_PER_LINE = 5;
const MAX_ORDERS = 20;
const DEMO_OTP = '1234';

/* ═══ Exports ═══ */
window.AE_DATA = {
  U, PRODUCTS, CATALOG, ORDER,
  SIZES, SIZE_BAND, CONV, FAM, CAT_LABELS, catLabel, ARTISANS, CARE,
  PROMO, R_NAMES, R_TEXTS,
  RECS, TICKER_ITEMS, FIT_STEPS, IDX_CATS,
  MAX_PER_LINE, MAX_ORDERS, DEMO_OTP,
  /* Image resolution, shared so no renderer invents its own rule. */
  IMG, imgFor, galleryFor, plate, hexFor,
  usingFallbackCatalogue, applyServerCatalog
};
})();