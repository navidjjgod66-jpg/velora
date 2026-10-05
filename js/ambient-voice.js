/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · ambient-voice.js — صدای محیط + فرمان صوتی (ویژگی ۴)
   ─────────────────────────────────────────────────────────────────────────
   · Ambient: سه نوساز سینوسی (72 / 108 / 144 هرتز — فاصلهٔ پنجمِ سهموجی)
     روی یک فیلتر lowpass که خودش با LFO در ۰.۰۷ هرتز نفس می‌کشد.
     AudioContext فقط با اولین تعامل آگاهانهٔ کاربر ساخته می‌شود
     (کلیک روی #voiceBtn) — سیاست autoplay مرورگرها نقض نمی‌شود.
   · Voice: Web Speech API؛ نگه‌داشتن دکمه (یا Enter/Space) = گوش دادن.
     دستورات فارسی و انگلیسی؛ نتیجه با toast اعلام می‌شود تا صفحه‌خوان
     هم بشنود (role=status در لایهٔ toast).
   · هیچ inline handler نیست؛ همه چیز addEventListener (قانون ۱ و ۲ CSP).
   · reduced-motion: انیمیشن‌های بصری با CSS خاموش‌اند؛ صدا بی‌خطر است.
   · perf-low: حالت صوتی غیرفعال (سنگین نیست ولی ترافیک شناختی کم می‌شود).
   مکان سیم‌کشی: دکمهٔ #voiceBtn در header کنار #themeT (index.php).
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const AE = window.AE; if (!AE) return;
const { $, toast, haptic, reduced } = AE;

const btn = $('#voiceBtn');
if (!btn) return;

/* ═══ Ambient drone — 3 sine oscillators + LFO on a lowpass ═══ */
let actx = null;      /* ساخته‌شده با اولین کلیک؛ بعداً reuse می‌شود */
let amb  = null;      /* { oscs, gain, lfo } وقتی روشن است */

function startAmbient() {
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) { toast('مرورگر شما پخش صدای زنده ندارد.', 'err'); return; }
  actx = actx || new AC();
  if (actx.state === 'suspended') actx.resume();

  const filt = actx.createBiquadFilter();
  filt.type = 'lowpass'; filt.frequency.value = 320; filt.Q.value = 0.6;

  /* LFO 0.07Hz — خیلی آهسته‌تر از آنکه «حرکت» حس شود، درست‌تر از آنکه
     ساکن باشد. دامنهٔ ±140Hz دورِ فرکانس مرکزی، مثل دم‌وکشنِ یک اتاق بزرگ. */
  const lfo  = actx.createOscillator(); lfo.frequency.value = 0.07;
  const lfoG = actx.createGain();        lfoG.gain.value = 140;
  lfo.connect(lfoG); lfoG.connect(filt.frequency);

  /* 72 → 108 → 144: نسبت ۳:۲:۲ (پنجمِ پاک). سه‌گانه‌ای که نه ماژور است
     نه مینور — همان ابهامی که تم طلاییِ خانه را نگه می‌دارد. */
  const master = actx.createGain(); master.gain.value = 0;
  const oscs = [72, 108, 144].map((f, i) => {
    const o = actx.createOscillator();
    o.type = 'sine'; o.frequency.value = f;
    const g = actx.createGain(); g.gain.value = [0.5, 0.3, 0.2][i];
    o.connect(g); g.connect(filt); o.start();
    return o;
  });
  filt.connect(master); master.connect(actx.destination);
  master.gain.linearRampToValueAtTime(0.05, actx.currentTime + 1.6);
  lfo.start();

  amb = { oscs, gain: master, lfo };
  btn.classList.add('is-amb');
  btn.setAttribute('aria-pressed', 'true');
  toast('صدای محیط روشن شد — هم‌نوای آتلیه.', 'ok');
}

function stopAmbient() {
  if (!amb) return;
  const a = amb; amb = null;
  try {
    a.gain.gain.cancelScheduledValues(actx.currentTime);
    a.gain.gain.setValueAtTime(a.gain.gain.value, actx.currentTime);
    a.gain.gain.linearRampToValueAtTime(0, actx.currentTime + 0.4);
  } catch (_) {}
  setTimeout(() => { try { a.oscs.forEach(o => o.stop()); a.lfo.stop(); } catch (_) {} }, 500);
  btn.classList.remove('is-amb');
  btn.setAttribute('aria-pressed', 'false');
  toast('صدای محیط خاموش شد.', 'ok');
}

/* ═══ Voice commands — Web Speech API (fa-IR + English aliases) ═══ */
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
let rec = null, listening = false, holdT = null;

/* الگو → کنش. هر کنش روی کنترل/مسیری موجود سوار می‌شود؛ هیچ DOM
   سروری بازنویسی نمی‌شود. data-act ها همان delegation موجود در main.js
   هستند — کلیک برنامه‌ای دقیقاً همان مسیر کاربر را طی می‌کند. */
const CMDS = [
  [/فروشگاه|مجموعه|boutique|shop|vault/i, () => { location.hash = '#boutique'; }],
  [/سبد|کیف|cart|bag\b/i,                () => $('[data-act="cart-open"]')?.click()],
  [/علاقه|wish|favorite/i,               () => $('#wishBtn')?.click()],
  [/حساب|کاربری|profile|account|auth/i,  () => $('[data-act="auth"]')?.click()],
  [/تم|پوسته|theme/i,                    () => $('#themeT')?.click()],
  [/صناعت|ساخت|craft|work/i,             () => { location.hash = '#craft'; }],
  [/نگار|lookbook/i,                     () => { location.hash = '#lookbook'; }],
  [/تاریخ|archive/i,                     () => { location.hash = '#archive'; }],
  [/تماس|قرار|appointment|contact/i,     () => { location.hash = '#appoint'; }],
  [/خانه|بالا|home|top/i,                () => { location.hash = '#top'; }],
  [/فرمان|پالت|command/i,                () => $('[data-act="cmd"]')?.click()],
];

function stopListening() {
  listening = false;
  btn.classList.remove('is-listen');
  try { rec && rec.abort(); } catch (_) {}
}

function startVoice() {
  if (!SR) { toast('این مرورگر شناخت گفتار ندارد — فرمان‌پالت جایگزین است.', 'err'); return; }
  if (listening) { stopListening(); return; }
  if (document.documentElement.classList.contains('perf-low')) {
    toast('در حالت سبک، فرمان صوتی غیرفعال است.', 'warn'); return;
  }
  rec = new SR();
  rec.lang = 'fa-IR'; rec.interimResults = false; rec.maxAlternatives = 2; rec.continuous = false;
  rec.onresult = e => {
    const alts = [...e.results[0]].map(x => x.transcript);
    for (const t of alts) {
      const hit = CMDS.find(([re]) => re.test(t));
      if (hit) {
        toast(`«${t}» — اجرا شد.`, 'ok');
        haptic('success');
        hit[1]();
        return;
      }
    }
    toast(`«${alts[0] || ''}» را نشناختم.`, 'warn');
  };
  rec.onerror  = ev => { if (ev.error !== 'aborted') toast('شناخت گفتار خطا داد.', 'err'); };
  rec.onend    = () => { listening = false; btn.classList.remove('is-listen'); };
  listening = true;
  btn.classList.add('is-listen');
  toast('گوش می‌دهم… بگویید: «فروشگاه»، «سبد»، «تم»', 'ok', null, 3000);
  try { rec.start(); } catch (_) { stopListening(); }
}

/* ═══ Wiring — نگه‌داشتن = گوش دادن؛ یک‌بار‌زدن = صدای محیط ═══
   pointerdown تایمر ۴۵۰ms می‌گذارد؛ اگر پیش از آن رها شود، click طبیعی
   روی دکمه می‌افتد و toggle صدا کار می‌کند. اگر نگه داشته شود، voice
   شروع می‌شود و click خنثی می‌گردد (suppressed flag). */
let suppressClick = false;

btn.addEventListener('pointerdown', () => {
  suppressClick = false;
  clearTimeout(holdT);
  holdT = setTimeout(() => { suppressClick = true; startVoice(); }, 450);
});
['pointerup', 'pointercancel', 'pointerleave'].forEach(ev =>
  btn.addEventListener(ev, () => clearTimeout(holdT))
);
btn.addEventListener('click', () => {
  if (suppressClick) { suppressClick = false; return; }
  if (amb) stopAmbient(); else startAmbient();
});
/* کیبورد: Enter/Space نگه‌داشته‌شده قابل تشخیص نیست → مستقیم voice.
   برای بستنِ حالت گوش دادن: دوباره Enter. صفحه‌خوان-friendly. */
btn.addEventListener('keydown', e => {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); startVoice(); }
});
btn.addEventListener('keyup', e => { if (e.key === 'Escape') stopListening(); });
addEventListener('keydown', e => { if (e.key === 'Escape' && listening) stopListening(); });

/* سکوتِ محترمانه: وقتی dialog دیگری باز است یا تب مخفی است، drone
   نباید پشتِ محتوای دیگر پخش شود. */
document.addEventListener('visibilitychange', () => {
  if (!actx) return;
  if (document.hidden) actx.suspend();
  else if (amb) actx.resume();
});
})();
