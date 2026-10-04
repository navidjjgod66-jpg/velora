/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — data.js
   کاتالوگ محصولات · اندازه‌ها · خانواده‌ها · کد تخفیف · داده‌های آتلیه
   صرفاً داده است — هیچ منطق اجرایی ندارد.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const { PHI, PHI2, faNum, faPad, esc } = window.AE;

/* ═══ Image URLs ═══ */
/* فقط HTTPS و مسیرهای نسبی uploads/ (آپلود ادمین) — هر اسکیم دیگری رد می‌شود */
const IMG = u => {
  const s = String(u || '');
  return (/^https:\/\//i.test(s) || /^(?:\.\/)?uploads\//i.test(s)) ? s : '';
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
const CATALOG = {};
PRODUCTS.forEach(p => CATALOG[p.id] = p);
const ORDER = PRODUCTS.map(p => p.id);

/* ═══ Sizes ═══ */
const SIZES = ['36','37','38','39','40','41','42','43','44','45','46'];
const CONV = {
  '36':'طول پا ۲۲.۷ سانتی‌متر','37':'طول پا ۲۳.۳ سانتی‌متر','38':'طول پا ۲۴.۰ سانتی‌متر',
  '39':'طول پا ۲۴.۷ سانتی‌متر','40':'طول پا ۲۵.۳ سانتی‌متر','41':'طول پا ۲۶.۰ سانتی‌متر',
  '42':'طول پا ۲۶.۶ سانتی‌متر','43':'طول پا ۲۷.۳ سانتی‌متر','44':'طول پا ۲۸.۰ سانتی‌متر',
  '45':'طول پا ۲۸.۷ سانتی‌متر','46':'طول پا ۲۹.۳ سانتی‌متر'
};
const FAM = { all:'همه', maryjane:'مری جین', loafer:'لوفر', highheel:'پاشنه بلند', lowheel:'پاشنه کوتاه', ballet:'باله تخت', boot:'بوت', sandal:'صندل', wish:'ذخیره‌شده' };
const ARTISANS = ['سی. بومون','ام. روسی','آ. تاناکا','ال. مارشان','ای. کلر'];
const CARE = 'هر جفت همراه با کیت مراقبت، کیسهٔ ابریشمی و سرپاشنهٔ اضافی ارسال می‌شود. ۶۰ روز مرجوعی، بدون استفاده، بدون سؤال — و زیره‌دوزی مجدد مادام‌العمر در آتلیه.';

/* ═══ Promo ═══ */
const PROMO = {
  code: 'VELORA10',
  pct: 10,
  normalize(raw) {
    return String(raw == null ? '' : raw).replace(/[\s\u00A0_-]+/g, '').toUpperCase();
  },
  match(raw) { return PROMO.normalize(raw) === PROMO.code; },
  valid(saved) {
    return !!saved && typeof saved === 'object' &&
           typeof saved.pct === 'number' && Number.isFinite(saved.pct) &&
           saved.pct > 0 && saved.pct <= 100 &&
           typeof saved.code === 'string' && saved.code.length > 0 && saved.code.length < 32;
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

/* ═══ Atelier data ═══ */
const AT_ORDERS = [
  { no:'AE-40218', name:'ایزابل مورو', item:'رزا · مشکی',  status:'prog', qty:1, price:18500000 },
  { no:'AE-31190', name:'سوفیا مارکتی', item:'ترمه · برنز', status:'new',  qty:2, price:45400000 },
  { no:'AE-52877', name:'یوکی تاناکا', item:'آذین · مشکی',  status:'prog', qty:1, price:21400000 },
  { no:'AE-61404', name:'لوسین بلان',  item:'روشا · کنیاک', status:'hold', qty:1, price:16900000 },
  { no:'AE-11903', name:'ماریا روسی',  item:'آذین · کنیاک', status:'new',  qty:1, price:21400000 }
];
const AT_STATUS = { new:'جدید', prog:'در حال دوخت', done:'کامل', hold:'در انتظار' };
const AT_CLASS  = { new:'st-new', prog:'st-prog', done:'st-done', hold:'st-hold' };
const AT_FEED = [
  { ico:'✎', text:'نمره‌گذاری رویهٔ طلایی در میز ۰۴ آغاز شد', time:'۵ دقیقه پیش' },
  { ico:'✂', text:'پوست توسکانی برش خورد — ۹ جفت به سالن رفت', time:'۲۰ دقیقه پیش' },
  { ico:'✓', text:'گلنار بسته‌بندی شد', time:'۱ ساعت پیش' },
  { ico:'◆', text:'زیره‌دوزی دوم ساعتِ طلایی کامل شد', time:'۲ ساعت پیش' }
];
const AT_PEOPLE = [
  { a:'س‌ب', n:'سی. بومون', role:'قالب‌ساز ارشد · پاریس' },
  { a:'م‌ر', n:'ام. روسی',  role:'دوزنده · فلورانس' },
  { a:'آ‌ت', n:'آ. تاناکا', role:'پرداخت‌کار · توکیو' }
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

/* ═══ Order limits ═══ */
const MAX_PER_LINE = 20;
const MAX_ORDERS = 20;
const DEMO_OTP = '1234';

/* ═══ Exports ═══ */
window.AE_DATA = {
  U, PRODUCTS, CATALOG, ORDER,
  SIZES, CONV, FAM, ARTISANS, CARE,
  PROMO, R_NAMES, R_TEXTS,
  AT_ORDERS, AT_STATUS, AT_CLASS, AT_FEED, AT_PEOPLE,
  RECS, TICKER_ITEMS, FIT_STEPS, IDX_CATS,
  MAX_PER_LINE, MAX_ORDERS, DEMO_OTP
};
})();