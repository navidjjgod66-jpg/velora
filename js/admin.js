/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — admin.js
   پنل مدیریت، داخل خود SPA (دیالوگ #admin، مسیر #/admin)
   ─────────────────────────────────────────────────────────────────────
   دروازهٔ ورود: نشست معتبر همان شماره‌ای که در config.php آمده. اینجا
   فقط یک پرچم بولی می‌بینیم (AE_AUTH.isAdmin)؛ شماره هرگز نمی‌آید.
   همهٔ نوشتن‌ها از راه api/admin.php انجام می‌شود و هر اکشن پیش از
   اجرا ae_require_admin() را در سرور رد می‌کند.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const { $, $$, faNum, esc, toast } = window.AE;
const api = window.aeApi || null;

const dlg  = $('#admin');
const pick = $('#admPick');
if (!dlg || !api) return;

const FAM_LABEL = {
  maryjane:'مری جین', loafer:'لوفر', highheel:'پاشنه بلند', lowheel:'پاشنه کوتاه',
  ballet:'باله تخت', boot:'بوت', sandal:'صندل'
};
const ST_LABEL = {
  paid:'پرداخت‌شده', reserved:'رزرو', canceled:'لغو شده',
  failed:'ناموفق', shipped:'ارسال‌شده', delivered:'تحویل‌شده'
};
const ST_KEYS = ['paid', 'reserved', 'shipped', 'delivered', 'canceled', 'failed'];

const S = { data:null, tab:'dash', editing:null, draft:null, picker:null, busy:false };

/* ─── ابزارهای کوچک ─────────────────────────────────────────────────────── */
const num  = v => { const n = parseInt(String(v).replace(/[^\d-]/g, ''), 10); return isNaN(n) ? 0 : n; };
const flt  = v => { const n = parseFloat(String(v).replace(/[^\d.]/g, '')); return isNaN(n) ? 0 : n; };
const kb   = b => b >= 1048576 ? (b / 1048576).toFixed(1) + ' مگابایت' : Math.max(1, Math.round(b / 1024)) + ' کیلوبایت';
const faDate = ts => {
  const d = new Date((ts || 0) * 1000);
  return ts ? new Intl.DateTimeFormat('fa-IR', { dateStyle:'medium', timeStyle:'short' }).format(d) : '—';
};
const ago = ts => {
  if (!ts) return '—';
  const s = Math.max(0, (Date.now() / 1000 - ts) | 0);
  if (s < 90) return 'همین حالا';
  if (s < 5400) return Math.round(s / 60) + ' دقیقه پیش';
  if (s < 172800) return Math.round(s / 3600) + ' ساعت پیش';
  return Math.round(s / 86400) + ' روز پیش';
};
const items  = () => S.data ? (S.data.catalog.items || {}) : {};
const order  = () => S.data ? (S.data.catalog.order || []) : [];
const media  = () => S.data ? (S.data.media || []) : [];
const stats  = () => (S.data && S.data.stats) || {};

/* نوار پیام پایین پنل */
let msgT = null;
function say(msg, kind) {
  const box = $('#admMsg');
  if (!box) return;
  box.textContent = msg || '';
  box.className = 'adm__msg' + (kind ? ' is-' + kind : '');
  clearTimeout(msgT);
  if (msg) msgT = setTimeout(() => {
    const b = $('#admMsg');
    if (b && b.className === 'adm__msg') b.textContent = '';
  }, 4000);
}

/* فراخوانی پنل با قفل همزمانی و نمایش خطای خوانا */
async function call(action, body) {
  if (S.busy) return null;
  S.busy = true;
  dlg.classList.add('is-busy');
  try {
    return await api.adminPost(action, body);
  } catch (e) {
    say((e && e.message) || 'درخواست ناموفق بود.', 'err');
    return null;
  } finally {
    S.busy = false;
    dlg.classList.remove('is-busy');
  }
}

/* ─── باز و بسته ────────────────────────────────────────────────────────── */
function open(fromRoute) {
  if (window.AE_AUTH && !window.AE_AUTH.isAdmin()) { toast('این بخش فقط برای مدیر است.', 'err'); return; }
  if (!dlg.open) dlg.showModal();
  dlg.classList.add('is-open');
  if (!fromRoute && location.hash !== '#/admin') location.hash = '#/admin';
  load();
}
function close() {
  dlg.close();
  dlg.classList.remove('is-open');
  if (location.hash === '#/admin') history.replaceState(null, '', '#/');
  window.dispatchEvent(new CustomEvent('ae:admin-close'));
}

$('#admX') && $('#admX').addEventListener('click', close);
dlg.addEventListener('close', () => {
  dlg.classList.remove('is-open');
  if (location.hash === '#/admin') history.replaceState(null, '', '#/');
  window.dispatchEvent(new CustomEvent('ae:admin-close'));
});
dlg.addEventListener('click', e => { if (e.target === dlg) close(); });
$('#admReload') && $('#admReload').addEventListener('click', () => load(true));

/* زبانه‌ها */
$('.adm__tabs') && $('.adm__tabs').addEventListener('click', e => {
  const b = e.target.closest('[data-atab]');
  if (b) setTab(b.dataset.atab);
});
function setTab(name) {
  S.tab = name;
  $$('.adm__tabs [data-atab]').forEach(b => {
    const on = b.dataset.atab === name;
    b.classList.toggle('on', on);
    b.setAttribute('aria-selected', String(on));
  });
  const map = { dash:'#admDash', prod:'#admProd', media:'#admMedia', orders:'#admOrders', set:'#admSet' };
  Object.keys(map).forEach(k => { const p = $(map[k]); if (p) p.hidden = k !== name; });
  if (S.editing && name !== 'prod') closeEditor();
  if (name === 'media' && !media().length) say('هنوز تصویری آپلود نشده است.');
  paint();
}

/* ─── بارگذاری وضعیت ────────────────────────────────────────────────────── */

/* پنل باید همهٔ فرم‌های سایت را ببیند. کاتالوگ پایه در js/data.js است و
   سرور آن را نمی‌خواند، پس یک‌بار از همین مرورگر به سرور می‌فرستیم تا
   لایهٔ مدیریت کامل شود. بعد از آن سرور مرجع است. */
async function seedIfNeeded() {
  const D = window.AE_DATA;
  if (!D || !Array.isArray(D.PRODUCTS) || !D.PRODUCTS.length) return;
  const items = D.PRODUCTS.map(p => ({
    id: p.id, name: p.name, sub: p.sub, cat: p.cat, family: p.family,
    heel: p.heel, price: p.price, oldPrice: p.oldPrice, badge: p.badge,
    isNew: p.isNew, stock: p.stock, rating: p.rating, reviews: p.reviews,
    img: p.img, gallery: p.gallery || [], sw: p.sw || [],
  }));
  say('در حال آماده‌سازی کاتالوگ…');
  const r = await call('catalog_seed', { items });
  if (r) say(r.skipped ? '' : `کاتالوگ پایه آپلود شد — ${faNum(r.count)} فرم.`);
}

async function load(force) {
  say('در حال خواندن وضعیت…');
  let r = await call('state', force ? { refresh: 1 } : {});
  if (!r) { say('ارتباط با سرور برقرار نشد — حالت آفلاین.', 'err'); return; }
  S.data = r;

  if (!S.data.catalog.seeded) {
    await seedIfNeeded();
    r = await call('state', {});
    if (!r) return;
    S.data = r;
  }
  /* ترتیب واقعی از خود سرور */
  S.data.catalog.order = (S.data.catalog.order && S.data.catalog.order.length)
    ? S.data.catalog.order : Object.keys(S.data.catalog.items || {});

  const st = stats();
  const stamp = $('#admStamp');
  if (stamp) stamp.textContent = 'کاتالوگ: ' + ago(st.catalog_updated);
  say('وضعیت تازه شد.');
  paint();
}

/* ─── داشبورد ───────────────────────────────────────────────────────────── */
function paintDash() {
  const st = stats();
  const p  = st.products || {}, o = st.orders || {}, m = st.media || {};
  const card = (l, v, sub) => `<div class="adm-stat"><span class="adm-stat__l">${esc(l)}</span><b class="adm-stat__v">${v}</b>${sub ? `<span class="adm-stat__s">${esc(sub)}</span>` : ''}</div>`;
  const low = Object.values(items()).filter(x => x && !x.hidden && (x.stock | 0) <= 3);

  $('#admDash').innerHTML = `
<div class="adm-stats">
${card('محصولات فعال', faNum(p.visible || 0), (p.hidden || 0) + ' پنهان')}
${card('ناموجود', faNum(p.out_of_stock || 0), 'موجودی صفر')}
${card('رو به اتمام', faNum(p.low_stock || 0), '۳ عدد یا کمتر')}
${card('سفارش‌ها', faNum(o.orders || 0), faNum(o.paid || 0) + ' پرداخت‌شده')}
${card('درآمد', faNum(o.revenue || 0) + ' <span class="u">تومان</span>', 'مجموع پرداخت‌شده‌ها')}
${card('رسانه', faNum(m.count || 0), kb(m.bytes || 0))}
</div>
<div class="adm-cols">
<section class="adm-box"><h4>نیازمند توجه</h4>
${low.length ? `<ul class="adm-list">${low.map(x => `<li><span>${esc(x.name)}</span><b class="${(x.stock|0) <= 0 ? 'bad' : 'warn'}">${(x.stock|0) <= 0 ? 'ناموجود' : faNum(x.stock) + ' عدد'}</b></li>`).join('')}</ul>`
  : '<p class="adm-note">همه‌چیز در وضعیت خوبی است.</p>'}
</section>
<section class="adm-box"><h4>آخرین سفارش‌ها</h4>
${(S.data.orders || []).length ? `<ul class="adm-list">${(S.data.orders || []).slice(0, 6).map(x =>
  `<li><span dir="ltr">${esc(x.ref)}</span><b>${faNum(x.total)} <span class="u">تومان</span></b></li>`).join('')}</ul>`
  : '<p class="adm-note">هنوز سفارشی ثبت نشده است.</p>'}
</section>
</div>`;
}

/* ─── محصولات ───────────────────────────────────────────────────────────── */
function paintProd() {
  const host = $('#admProd');
  const all = items(), ord = order();
  const max = (S.data.limits && S.data.limits.max_products) || 120;

  const head = `<div class="adm-bar">
<span class="adm-bar__c">${faNum(ord.length)} فرم در لایهٔ مدیریت${all && Object.keys(all).length >= max ? ' — سقف پر است' : ''}</span>
<button class="btn btn-solid btn-sm" id="admNew" type="button">محصول تازه</button>
</div>`;

  if (S.editing) { host.innerHTML = head + editorHTML(S.editing); return; }

  if (!ord.length) {
    host.innerHTML = head + `<p class="adm-note">لایهٔ مدیریت خالی است. با «محصول تازه» شروع کنید — هر چه اینجا ذخیره شود روی data.js ویترین می‌نشیند.</p>`;
    return;
  }
  host.innerHTML = head + `<ul class="adm-rows">${ord.map((id, i) => {
    const p = all[id] || {};
    const stock = p.stock | 0;
    return `<li class="adm-row" data-id="${esc(id)}">
<span class="adm-row__n">${faNum(i + 1)}</span>
<span class="adm-row__thumb">${p.img ? `<img src="${esc(p.img)}" alt="" loading="lazy" decoding="async">` : '<i>—</i>'}</span>
<span class="adm-row__b"><b>${esc(p.name || id)}</b><span class="mono">${esc(p.cat || '')}</span></span>
<span class="adm-row__meta">${faNum(p.price || 0)} <span class="u">تومان</span></span>
<span class="adm-row__meta">${stock <= 0 ? '<b class="bad">ناموجود</b>' : stock <= 3 ? `<b class="warn">${faNum(stock)} عدد</b>` : faNum(stock) + ' عدد'}</span>
<span class="adm-row__fl">${p.hidden ? '<b class="off">پنهان</b>' : '<b class="on-t">فعال</b>'}</span>
<span class="adm-row__act">
<button class="adm-ic" type="button" data-a="up" ${i === 0 ? 'disabled' : ''} aria-label="بالا">↑</button>
<button class="adm-ic" type="button" data-a="down" ${i === ord.length - 1 ? 'disabled' : ''} aria-label="پایین">↓</button>
<button class="adm-ic" type="button" data-a="edit" aria-label="ویرایش">✎</button>
</span></li>`;
  }).join('')}</ul>`;

  $('#admNew') && $('#admNew').addEventListener('click', () => { S.editing = blank(); paintProd(); });
}

/* رکورد خالی برای محصول تازه */
function blank() {
  return { id:'', name:'', sub:'', cat:'', family:'loafer', heel:0, price:0, oldPrice:0,
           badge:'', isNew:false, hidden:false, stock:0, rating:4.8, reviews:0, img:'', gallery:[], sw:[] };
}

/* ─── فرم محصول ─────────────────────────────────────────────────────────── */
function editorHTML(p) {
  const fams = (S.data.families || Object.keys(FAM_LABEL)).map(f =>
    `<option value="${esc(f)}"${f === p.family ? ' selected' : ''}>${esc(FAM_LABEL[f] || f)}</option>`).join('');

  const sw = (p.sw && p.sw.length ? p.sw : [{ n:'', c:'#161310', img:'' }]).map((s, i) => `
<div class="adm-sw" data-i="${i}">
<input type="text" class="input" data-f="sw.n" value="${esc(s.n || '')}" placeholder="نام رنگ" maxlength="24">
<input type="color" data-f="sw.c" value="${esc(/^#[0-9a-f]{6}$/i.test(s.c || '') ? s.c : '#161310')}" aria-label="کد رنگ">
<button class="adm-ic" type="button" data-sw="img" aria-label="انتخاب تصویر رنگ">▣</button>
<button class="adm-ic" type="button" data-sw="del" aria-label="حذف رنگ">✕</button>
</div>`).join('');

  const gal = (p.gallery || []).map((g, i) =>
    `<li><img src="${esc(g)}" alt="" loading="lazy" decoding="async"><button class="adm-x" type="button" data-gal="${i}" aria-label="برداشتن از گالری">✕</button></li>`).join('');

  return `<form class="adm-form" id="admForm" novalidate>
<div class="adm-form__top">
<button class="btn btn-ghost btn-sm" type="button" id="admCancel">بازگشت</button>
<b>${p.id ? 'ویرایش «' + esc(p.name || p.id) + '»' : 'محصول تازه'}</b>
<button class="btn btn-solid btn-sm" type="submit">ذخیره</button>
</div>

<div class="adm-form__grid">
<div class="field"><label for="fName">نام</label><input id="fName" class="input" data-f="name" value="${esc(p.name || '')}" maxlength="60" required></div>
<div class="field"><label for="fCat">دستهٔ نمایشی</label><input id="fCat" class="input" data-f="cat" value="${esc(p.cat || '')}" maxlength="60" placeholder="لوفر · تخت"></div>
<div class="field field--wide"><label for="fSub">توضیح کوتاه</label><input id="fSub" class="input" data-f="sub" value="${esc(p.sub || '')}" maxlength="220"></div>
<div class="field"><label for="fFam">خانواده</label><select id="fFam" class="select select--full" data-f="family">${fams}</select></div>
<div class="field"><label for="fPrice">قیمت (تومان)</label><input id="fPrice" class="input" data-f="price" type="number" min="0" step="1000" inputmode="numeric" value="${p.price | 0}"></div>
<div class="field"><label for="fOld">قیمت پیش از تخفیف</label><input id="fOld" class="input" data-f="oldPrice" type="number" min="0" step="1000" inputmode="numeric" value="${p.oldPrice | 0}"></div>
<div class="field"><label for="fHeel">ارتفاع پاشنه (میلی‌متر)</label><input id="fHeel" class="input" data-f="heel" type="number" min="0" max="300" inputmode="numeric" value="${p.heel | 0}"></div>
<div class="field"><label for="fStock">موجودی</label><input id="fStock" class="input" data-f="stock" type="number" min="0" max="9999" inputmode="numeric" value="${p.stock | 0}"></div>
<div class="field"><label for="fRating">امتیاز (۰ تا ۵)</label><input id="fRating" class="input" data-f="rating" type="number" min="0" max="5" step="0.1" value="${Number(p.rating || 0).toFixed(1)}"></div>
<div class="field"><label for="fReviews">تعداد نظر</label><input id="fReviews" class="input" data-f="reviews" type="number" min="0" inputmode="numeric" value="${p.reviews | 0}"></div>
<div class="field"><label for="fBadge">برچسب</label><input id="fBadge" class="input" data-f="badge" value="${esc(p.badge || '')}" maxlength="24" placeholder="پرفروش"></div>
<div class="field adm-flags">
<label class="adm-chk"><input type="checkbox" data-f="isNew"${p.isNew ? ' checked' : ''}> تازه</label>
<label class="adm-chk"><input type="checkbox" data-f="hidden"${p.hidden ? ' checked' : ''}> پنهان از ویترین</label>
</div>
</div>

<div class="adm-form__img">
<div class="adm-form__main">
<span class="adm-lab">تصویر اصلی</span>
<div class="adm-mainshot">${p.img ? `<img src="${esc(p.img)}" alt="" decoding="async">` : '<i>تصویری انتخاب نشده</i>'}</div>
<div class="adm-form__row">
<button class="btn btn-ghost btn-sm" type="button" data-pick="img">انتخاب از رسانه‌ها</button>
<input class="input" data-f="img" value="${esc(p.img || '')}" placeholder="https://… یا uploads/…">
</div>
</div>
<div class="adm-form__gal">
<span class="adm-lab">گالری (${(p.gallery || []).length})</span>
<ul class="adm-gals">${gal || '<li class="adm-galempty">خالی</li>'}</ul>
<div class="adm-form__row"><button class="btn btn-ghost btn-sm" type="button" data-pick="gal">افزودن به گالری</button></div>
</div>
</div>

<div class="adm-form__sw">
<span class="adm-lab">رنگ‌ها</span>
${sw}
<button class="btn btn-ghost btn-sm" type="button" id="admSwAdd">افزودن رنگ</button>
</div>

${p.id ? `<div class="adm-form__danger"><button class="btn btn-ghost btn-sm" type="button" id="admDel">حذف محصول</button>
<p class="adm-note">با حذف، این فرم از ویترین هم برداشته می‌شود.</p></div>` : ''}
</form>`;
}

function closeEditor() { S.editing = null; paintProd(); }

/* خواندن فرم به آبجکت */
function readForm(form) {
  const get = f => { const el = form.querySelector(`[data-f="${f}"]`); return el ? el : null; };
  const p = Object.assign({}, S.editing);
  p.name  = (get('name').value || '').trim();
  p.sub   = (get('sub').value || '').trim();
  p.cat   = (get('cat').value || '').trim();
  p.family= get('family').value;
  p.badge = (get('badge').value || '').trim();
  p.price = num(get('price').value);
  p.oldPrice = num(get('oldPrice').value);
  p.heel  = num(get('heel').value);
  p.stock = num(get('stock').value);
  p.rating= Math.max(0, Math.min(5, flt(get('rating').value)));
  p.reviews = num(get('reviews').value);
  p.isNew = get('isNew').checked;
  p.hidden= get('hidden').checked;
  p.img   = (get('img').value || '').trim();
  p.sw = $$('[data-f="sw.n"]', form).map((n, i) => {
    const row = n.closest('.adm-sw');
    return { n: (n.value || '').trim(),
             c: (row.querySelector('[data-f="sw.c"]') || {}).value || '#161310',
             img: row.dataset.img || p.img || '' };
  });
  return p;
}

async function saveProduct() {
  const form = $('#admForm');
  if (!form) return;
  const p = readForm(form);
  if (!p.name) { say('نام محصول لازم است.', 'err'); const n = $('#fName'); n && n.focus(); return; }
  say('در حال ذخیره…');
  const r = await call('product_save', { product: p });
  if (!r) return;
  S.editing = null;
  S.data.stats = r.stats;
  S.data.catalog.items = S.data.catalog.items || {};
  S.data.catalog.items[r.product.id] = r.product;
  if (!order().includes(r.product.id)) {
    S.data.catalog.order = order().concat([r.product.id]);
  }
  say(r.new ? 'محصول تازه ساخته شد.' : 'ذخیره شد.');
  paint();
  toast('محصول ذخیره شد.');
}

async function delProduct() {
  if (!S.editing || !S.editing.id) return;
  const id = S.editing.id;
  if (!confirm(`محصول «${S.editing.name || id}» حذف شود؟ این کار برگشت‌پذیر نیست.`)) return;
  say('در حال حذف…');
  const r = await call('product_delete', { id });
  if (!r) return;
  S.editing = null;
  if (S.data.stats) S.data.stats = r.stats;
  say('محصول حذف شد.');
  paint();
}

/* جابه‌جایی ترتیب */
async function move(id, dir) {
  const ord = order().slice();
  const i = ord.indexOf(id);
  const j = i + dir;
  if (i < 0 || j < 0 || j >= ord.length) return;
  ord.splice(j, 0, ord.splice(i, 1)[0]);
  S.data.catalog.order = ord;
  paintProd();
  const r = await call('product_order', { order: ord });
  if (r) say('ترتیب ذخیره شد.');
}

/* ─── رسانه ─────────────────────────────────────────────────────────────── */
function paintMedia() {
  const max = (S.data.limits && S.data.limits.max_upload_mb) || 8;
  const list = media();
  $('#admMedia').innerHTML = `
<div class="adm-bar">
<label class="btn btn-solid btn-sm" for="admFile">آپلود تصویر</label>
<input id="admFile" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" multiple hidden>
<span class="adm-bar__c">${faNum(list.length)} تصویر · حداکثر ${faNum(max)} مگابایت برای هر فایل</span>
</div>
<div class="adm-drop" id="admDrop">تصویرها را اینجا رها کنید یا از دکمهٔ بالا انتخاب کنید</div>
<div class="adm-bar__prog" id="admProg" hidden><span id="admProgBar"></span></div>
${list.length ? `<ul class="adm-media">${list.map(m => `
<li data-id="${esc(m.id)}">
<span class="adm-media__th"><img src="${esc(m.file)}" alt="${esc(m.alt || '')}" loading="lazy" decoding="async"></span>
<span class="adm-media__b">
<input class="input input--sm" data-mf="name" value="${esc(m.name || '')}" maxlength="80" aria-label="نام فایل">
<input class="input input--sm" data-mf="alt" value="${esc(m.alt || '')}" maxlength="140" placeholder="متن جایگزین" aria-label="متن جایگزین">
<span class="mono">${faNum(m.w || 0)}×${faNum(m.h || 0)} · ${kb(m.size || 0)}</span>
${(m.used || []).length ? `<span class="adm-media__use">در ${faNum(m.used.length)} جا به کار رفته</span>` : '<span class="adm-media__use">بی‌استفاده</span>'}
</span>
<span class="adm-media__act">
<button class="adm-ic" type="button" data-mf-act="copy" aria-label="کپی نشانی">⧉</button>
<button class="adm-ic" type="button" data-mf-act="save" aria-label="ذخیرهٔ نام">✓</button>
<button class="adm-ic adm-ic--bad" type="button" data-mf-act="del" aria-label="حذف">✕</button>
</span></li>`).join('')}</ul>` : '<p class="adm-note">هنوز تصویری آپلود نشده است.</p>'}`;

  const file = $('#admFile');
  file && file.addEventListener('change', () => { uploadFiles([...file.files]); file.value = ''; });

  const drop = $('#admDrop');
  if (drop) {
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', e => { const f = [...(e.dataTransfer.files || [])]; if (f.length) uploadFiles(f); });
  }
}

async function uploadFiles(files) {
  const max = ((S.data.limits && S.data.limits.max_upload_mb) || 8) * 1048576;
  for (const f of files) {
    if (!/^image\//.test(f.type)) { say(`«${f.name}» تصویر نیست.`, 'err'); continue; }
    const prog = $('#admProg'), bar = $('#admProgBar');
    if (prog) prog.hidden = false;
    try {
      const r = await api.adminUpload(f, k => { if (bar) bar.style.width = Math.round(k * 100) + '%'; });
      S.data.media = [r.media, ...media()];
      if (r.stats) S.data.stats = r.stats;
      say('«' + f.name + '» آپلود شد.');
      paintMedia();
    } catch (e) {
      say((e && e.message) || 'آپلود ناموفق بود.', 'err');
    } finally {
      if (prog) prog.hidden = true;
      if (bar) bar.style.width = '0%';
    }
  }
}

/* ─── سفارش‌ها ──────────────────────────────────────────────────────────── */
function paintOrders() {
  const list = S.data.orders || [];
  const sum = stats().orders || {};
  const rows = list.length ? list.map(o => `
<li class="adm-ord" data-ref="${esc(o.ref)}">
<button class="adm-ord__h" type="button" data-oa="toggle">
<span class="adm-ord__ref" dir="ltr">${esc(o.ref)}</span>
<span class="adm-ord__d">${faDate(o.ts)}</span>
<span class="adm-ord__c">${esc(o.name || '—')}<span class="mono" dir="ltr">${esc(o.phone || '')}</span></span>
<span class="adm-ord__t">${faNum(o.total)} <span class="u">تومان</span></span>
<span class="adm-ord__s s-${esc(o.status)}">${esc(ST_LABEL[o.status] || o.status)}</span>
</button>
<div class="adm-ord__b" hidden>
<div class="adm-ord__items">${(o.items || []).map(it =>
  `<div><span>${esc(it.name || it.id || '—')}${it.size ? ' · سایز ' + esc(String(it.size)) : ''}</span><b>${faNum(it.qty || 1)} × ${faNum(it.price || 0)}</b></div>`).join('') || '<p class="adm-note">جزئیات ثبت نشده</p>'}
${o.discount ? `<div class="adm-ord__sum"><span>تخفیف</span><b>${faNum(o.discount)}</b></div>` : ''}
${o.address ? `<div class="adm-ord__sum"><span>نشانی</span><b>${esc(o.address)}${o.zip ? ' · ' + esc(o.zip) : ''}</b></div>` : ''}
${o.email ? `<div class="adm-ord__sum"><span>رایانامه</span><b dir="ltr">${esc(o.email)}</b></div>` : ''}
${o.gateway ? `<div class="adm-ord__sum"><span>درگاه</span><b dir="ltr">${esc(o.gateway)}${o.card ? ' · ' + esc(o.card) : ''}</b></div>` : ''}
</div>
<label class="adm-chk adm-chk--st"><span>وضعیت</span>
<select class="select select--sm" data-oa="status">${ST_KEYS.map(k =>
  `<option value="${k}"${k === o.status ? ' selected' : ''}>${ST_LABEL[k]}</option>`).join('')}</select>
<button class="btn btn-ghost btn-sm" type="button" data-oa="save">ثبت</button>
</label>
</div></li>`).join('') : '<p class="adm-note">هنوز سفارشی ثبت نشده است.</p>';

  $('#admOrders').innerHTML = `
<div class="adm-bar"><span class="adm-bar__c">${faNum(sum.orders || 0)} سفارش · ${faNum(sum.paid || 0)} پرداخت‌شده · درآمد ${faNum(sum.revenue || 0)} تومان</span>
<button class="btn btn-ghost btn-sm" id="admOrdRe" type="button">تازه‌سازی</button></div>
<ul class="adm-ords">${rows}</ul>`;

  $('#admOrdRe') && $('#admOrdRe').addEventListener('click', () => load(true));
}

async function setOrderStatus(ref, st) {
  say('در حال ثبت…');
  const r = await call('order_status', { ref, status: st });
  if (!r) return;
  const o = (S.data.orders || []).find(x => x.ref === ref);
  if (o) o.status = r.status;
  say('وضعیت سفارش ' + ref + ' ثبت شد.');
  paintOrders();
}

/* ─── تنظیمات ───────────────────────────────────────────────────────────── */
function paintSet() {
  const s = (S.data.catalog && S.data.catalog.settings) || {};
  const lim = S.data.limits || {};
  $('#admSet').innerHTML = `
<form class="adm-form" id="admSetForm">
<div class="adm-form__top"><b>کد تخفیف خانه</b></div>
<p class="adm-note">این کد در سبد خرید و پیام‌های سایت دیده می‌شود؛ همان چیزی که مشتری می‌زند.</p>
<div class="adm-form__grid">
<div class="field"><label for="sCode">کد</label><input id="sCode" class="input" dir="ltr" value="${esc(s.promo_code || '')}" maxlength="24"></div>
<div class="field"><label for="sPct">درصد تخفیف</label><input id="sPct" class="input" type="number" min="1" max="90" inputmode="numeric" value="${s.promo_pct | 0}"></div>
</div>
<button class="btn btn-solid btn-sm" type="submit">ذخیرهٔ تنظیمات</button>
</form>
<div class="adm-box adm-box--wide">
<h4>وضعیت لایهٔ مدیریت</h4>
<ul class="adm-kv">
<li><span>محصولات در لایهٔ مدیریت</span><b>${faNum(Object.keys(items()).length)} از ${faNum(lim.max_products || 0)}</b></li>
<li><span>سقف آپلود هر فایل</span><b>${faNum(lim.max_upload_mb || 0)} مگابایت</b></li>
<li><span>آخرین ذخیرهٔ کاتالوگ</span><b>${ago(stats().catalog_updated)}</b></li>
<li><span>رسانه‌های بی‌استفاده</span><b>${faNum(media().filter(m => !(m.used || []).length).length)}</b></li>
</ul>
<p class="adm-note">هر چیزی که اینجا ذخیره کنید روی ویترین (<code>js/data.js</code>) می‌نشیند و تا بازخوانی بعدی باقی می‌ماند.</p>
</div>`;

  $('#admSetForm') && $('#admSetForm').addEventListener('submit', async e => {
    e.preventDefault();
    say('در حال ذخیره…');
    const r = await call('settings_save', { promo_code: $('#sCode').value, promo_pct: num($('#sPct').value) });
    if (!r) return;
    S.data.catalog.settings = r.settings;
    say('تنظیمات ذخیره شد.');
    paintSet();
  });
}

/* ─── انتخابگر رسانه ────────────────────────────────────────────────────── */
function openPicker(target) {
  S.picker = target;
  paintPicker();
  if (!pick.open) pick.showModal();
}
$('#admPickX') && $('#admPickX').addEventListener('click', () => pick.close());
pick && pick.addEventListener('close', () => { S.picker = null; });
pick && pick.addEventListener('click', e => { if (e.target === pick) pick.close(); });

function paintPicker() {
  const list = media();
  const mode = String(S.picker || '').replace(/[^\w-]/g, '');
  $('#admPickGrid').innerHTML = list.length
    ? list.map(m => `<button class="adm-pick__i adm-pick__i--${mode}" type="button" data-file="${esc(m.file)}"><img src="${esc(m.file)}" alt="${esc(m.alt || m.name || '')}" loading="lazy" decoding="async"><span>${esc(m.name || '')}</span></button>`).join('')
    : '<p class="adm-note">رسانه‌ای وجود ندارد — اول از زبانهٔ «رسانه» آپلود کنید.</p>';
}

/* ─── رنگ‌کردن صفحهٔ جاری ────────────────────────────────────────────────── */
function paint() {
  if (S.tab === 'dash')   paintDash();
  if (S.tab === 'prod')   paintProd();
  if (S.tab === 'media')  paintMedia();
  if (S.tab === 'orders') paintOrders();
  if (S.tab === 'set')    paintSet();
}

/* ─── رویدادهای سراسری (delegation) ─────────────────────────────────────── */
dlg.addEventListener('click', async e => {
  const t = e.target;

  /* انتخابگر رسانه */
  const pk = t.closest('[data-file]');
  if (pk && S.picker) {
    const url = pk.dataset.file;
    if (S.picker === 'img') {
      const el = $('[data-f="img"]'); if (el) el.value = url;
      const row = $('[data-f="img"]') && $('[data-f="img"]').closest('.adm-form__main');
      const shot = row && row.querySelector('.adm-mainshot');
      if (shot) shot.innerHTML = `<img src="${esc(url)}" alt="" decoding="async">`;
      $$('.adm-sw').forEach(r => { if (!r.dataset.img) r.dataset.img = url; });
    } else if (S.picker === 'gal') {
      const g = (S.editing.gallery || []).slice();
      if (g.length >= 24) { say('گالری حداکثر ۲۴ تصویر دارد.', 'err'); }
      else if (!g.includes(url)) { g.push(url); S.editing.gallery = g; paintProd(); }
    } else if (String(S.picker).startsWith('sw:')) {
      const i = +String(S.picker).split(':')[1];
      const row = $$('.adm-sw')[i];
      if (row) row.dataset.img = url;
    }
    pick.close();
    return;
  }

  /* باز کردن انتخابگر */
  const op = t.closest('[data-pick]');
  if (op) { openPicker(op.dataset.pick); return; }
  const osw = t.closest('[data-sw="img"]');
  if (osw) { openPicker('sw:' + osw.closest('.adm-sw').dataset.i); return; }
  const swAdd = t.closest('#admSwAdd');
  if (swAdd) { S.editing.sw = (S.editing.sw || []).concat([{ n:'', c:'#161310', img:S.editing.img || '' }]); paintProd(); return; }
  const swDel = t.closest('[data-sw="del"]');
  if (swDel) {
    const row = swDel.closest('.adm-sw');
    S.editing.sw = (S.editing.sw || []).filter((_, i) => i !== +row.dataset.i);
    paintProd(); return;
  }
  const gx = t.closest('[data-gal]');
  if (gx) { S.editing.gallery = (S.editing.gallery || []).filter((_, i) => i !== +gx.dataset.gal); paintProd(); return; }
  const cancel = t.closest('#admCancel');
  if (cancel) { closeEditor(); return; }
  const del = t.closest('#admDel');
  if (del) { delProduct(); return; }

  /* سطر محصول */
  const act = t.closest('[data-a]');
  if (act) {
    const id = act.closest('.adm-row').dataset.id;
    if (act.dataset.a === 'up')   return move(id, -1);
    if (act.dataset.a === 'down') return move(id, 1);
    if (act.dataset.a === 'edit') { S.editing = JSON.parse(JSON.stringify(items()[id] || blank())); paintProd(); }
    return;
  }

  /* رسانه */
  const ma = t.closest('[data-mf-act]');
  if (ma) {
    const li = ma.closest('[data-id]');
    const id = li.dataset.id;
    const rec = media().find(m => m.id === id) || {};
    if (ma.dataset.mfAct === 'copy') {
      const url = rec.file || '';
      if (navigator.clipboard) navigator.clipboard.writeText(url).then(() => say('نشانی کپی شد.'), () => say('کپی ممکن نشد.', 'err'));
      else say(url, 'ok');
      return;
    }
    if (ma.dataset.mfAct === 'save') {
      const body = {
        id,
        name: (li.querySelector('[data-mf="name"]') || {}).value || '',
        alt:  (li.querySelector('[data-mf="alt"]')  || {}).value || '',
      };
      say('در حال ذخیره…');
      call('media_update', body).then(r => { if (r) { Object.assign(rec, r.media); say('ذخیره شد.'); } });
      return;
    }
    if (ma.dataset.mfAct === 'del') {
      const used = (rec.used || []).length;
      const msg = used
        ? `«${rec.name || id}» در ${used} جا به کار رفته. حذف شود و از همهٔ محصولات آزاد گردد؟`
        : `«${rec.name || id}» حذف شود؟`;
      if (!confirm(msg)) return;
      say('در حال حذف…');
      const r = await call('media_delete', { id, detach: used ? 1 : 0 });
      if (r) {
        S.data.media = media().filter(m => m.id !== id);
        if (r.stats) S.data.stats = r.stats;
        say('تصویر حذف شد.');
        paint();
      }
    }
    return;
  }

  /* سفارش */
  const oa = t.closest('[data-oa]');
  if (oa) {
    const li = oa.closest('.adm-ord');
    if (oa.dataset.oa === 'toggle') { const b = li.querySelector('.adm-ord__b'); if (b) b.hidden = !b.hidden; return; }
    if (oa.dataset.oa === 'save') {
      const sel = li.querySelector('[data-oa="status"]');
      if (sel) setOrderStatus(li.dataset.ref, sel.value);
    }
  }
});

/* ذخیرهٔ فرم محصول */
dlg.addEventListener('submit', e => {
  if (e.target.id === 'admForm') { e.preventDefault(); saveProduct(); }
});

/* همگام‌سازی زنده: اگر تب رسانه باز و آپلودی شد، انتخابگر تازه شود */
addEventListener('ae:open-admin', () => open(true));

window.AE_ADMIN = { open, close, isOpen: () => dlg.open, reload: load };
})();