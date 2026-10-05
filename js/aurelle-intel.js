/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · aurelle-intel.js — هوش Auric شخصیسازی (ویژگی ۳)
   ─────────────────────────────────────────────────────────────────────────
   · localStorage «ae.intel.v1»: تعداد بازدید، اولین بازدید، عمق اسکرول،
     بخش‌های دیده‌شده، زمان ماندگاری (dwell).
   · getGreeting(): پیام ساعتیِ تهران + تاریخچهٔ بازدید — متن #greeting را
     فقط با textContent جایگزین می‌کند (ساختار سرور دست‌نخورده).
   · AI Oracle: پس از ۱۲ ثانیه ماندگاری، یک‌بار پیشنهاد هوشمند (toast).
   · track(type, data) / getState() برای بقیهٔ ماژول‌ها روی window.AE_INTEL.
   · احترام کامل به reduced-motion (oracle فقط در حالت عادی) و LS کوته‌آمد.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const AE = window.AE; if (!AE) return;
const { $, $$, LS, toast, reduced, faNum } = AE;
const KEY = 'ae.intel.v1';

let s = Object.assign({ visits: 0, first: 0, depth: 0, seen: {}, dwell: 0 }, LS.get(KEY, null) || {});
s.visits = (typeof s.visits === 'number' ? s.visits : 0) + 1;
if (!s.first) s.first = Date.now();
LS.set(KEY, s);

const persist = () => LS.set(KEY, s);
const tStart = performance.now();

/* ساعت تهران، حتی از هر منطقهٔ زمانی */
const tehranHour = () => {
  try {
    return Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Tehran', hour: 'numeric', hour12: false }).format(new Date()));
  } catch (_) { return new Date().getHours(); }
};

function getGreeting() {
  const h = tehranHour(), v = s.visits;
  const part = h < 12 ? 'صبح بخیر' : h < 17 ? 'روز بخیر' : h < 21 ? 'عصر بخیر' : 'شب بخیر';
  if (v <= 1) return part + ' — خوش آمدید به ولورا اورِل؛ نخستین گام شما.';
  if (v <= 3) return part + ` — خوش برگشتید (${faNum(v)} بازدید).`;
  return part + ` — مهمان همیشگی ما، ${faNum(v)} بار در خانهٔ طلایی.`;
}

function getRecommendation() {
  /* ساده اما واقعی: پرترددترین بخش دیده‌شده راهنمای پیشنهاد است. */
  const hot = Object.keys(s.seen || {})
    .filter(k => k.startsWith('section:'))
    .sort((a, b) => s.seen[b] - s.seen[a])[0];
  const map = {
    'section:boutique': 'مجموعهٔ پاییزی — تازه‌ترین جفت‌ها منتظر شما هستند.',
    'section:craft':    'صناعت ما — ببینید یک کفش چگونه متولد می‌شود.',
    'section:lookbook': 'نگارخانه — روایت تصویری این فصل.',
    'section:archive':  'تاریخچه — سی سال قالب‌سازی در فلورانس.',
    'section:appoint':  'وقت خصوصی در آتلیه — اندازهٔ انگشتان شما، مال خودتان.'
  };
  return map[hot] || 'دیدن مجموعهٔ پاییز/زمستان ۲۶';
}

function track(type, data) {
  s.seen[type] = (s.seen[type] || 0) + 1;
  if (data && typeof data.depth === 'number' && data.depth > s.depth) s.depth = data.depth;
  persist();
  document.dispatchEvent(new CustomEvent('ae:intel', { detail: { type, data } }));
}

function getState() { return Object.assign({}, s); }

addEventListener('load', () => {
  /* greeting — فقط متن، بدون دست‌زدن به ساختار */
  const g = $('#greeting');
  if (g) g.textContent = getGreeting();

  /* عمق اسکرول + بخش‌های دیده‌شده */
  try {
    const io = new IntersectionObserver(es => es.forEach(e => {
      if (e.isIntersecting && e.target.id) track('section:' + e.target.id);
    }), { threshold: 0.3 });
    $$('section[id]').forEach(x => io.observe(x));
  } catch (_) {}

  addEventListener('scroll', () => {
    const max = document.body.scrollHeight - innerHeight || 1;
    const d = Math.min(100, Math.round(scrollY / max * 100));
    if (d > s.depth) track('depth', { depth: d });
  }, { passive: true });

  /* dwell هنگام ترک صفحه */
  addEventListener('pagehide', () => {
    s.dwell += Math.round(performance.now() - tStart);
    persist();
  });

  /* AI Oracle — یک‌بار در ۷ روز، پس از ۱۲ ثانیه، نه در reduced-motion */
  if (!reduced) setTimeout(() => {
    const last = LS.get('ae.oracle.at', 0);
    if (Date.now() - last < 7 * 864e5) return;
    LS.set('ae.oracle.at', Date.now());
    const rec = getRecommendation();
    toast('اُتاکی پیشنهاد می‌کند: ' + rec, 'ok', { label: 'رفتن', fn: () => {
      const id = (/مجموعه/.test(rec) ? '#boutique' : /صناعت/.test(rec) ? '#craft'
        : /نگار/.test(rec) ? '#lookbook' : /تاریخ/.test(rec) ? '#archive'
        : /آتلیه|وقت/.test(rec) ? '#appoint' : '#top');
      AE.scrollToEl(id);
    } }, 9000);
  }, 12000);
});

window.AE_INTEL = { getGreeting, getRecommendation, track, getState };
})();
