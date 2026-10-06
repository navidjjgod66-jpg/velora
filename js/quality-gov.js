/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · quality-gov.js — حاکم کیفیت (ویژگی ۹)
   ─────────────────────────────────────────────────────────────────────────
   · FPS را با یک rAF مستقل نمونه‌برداری می‌کند.
   · اگر ۳ ثانیه پشت‌سرهم زیر ۴۰ fps بماند، DPR را ۰.۲۵ کم می‌کند
     (حداقل ۱.۰) و به همهٔ renderableها (shader / dust / 3d) اطلاع می‌دهد.
   · HUD: فقط با ?hud=1 — FPS، DPR، شهر لحظه، مقدار φ.
   · طبقهٔ سخت‌افزاری: deviceMemory≤2 / cores≤2 / saveData → perf-low.
     (index.php pre-paint همین تصمیم را zودتر گرفته؛ اینجا فقط تشدید است.)
   · reduced-motion: حلقهٔ FPS اجرا نمی‌شود؛ DPR ثابت می‌ماند.
   هیچ چیزی را بازنویسی نمی‌کند؛ فقط window.AE_QUALITY را منتشر می‌کند.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const AE = window.AE; if (!AE) return;
const { html, reduced } = AE;

const gov = {
  dpr: Math.min(window.devicePixelRatio || 1, 2),
  fps: 60,
  _subs: new Set(),
  onChange(fn) { this._subs.add(fn); return () => this._subs.delete(fn); },
  _emit() { this._subs.forEach(f => { try { f(this.dpr, this.fps); } catch (_) {} }); },
  setDpr(v) {
    v = Math.max(1, Math.min(2, Math.round(v * 100) / 100));
    if (v !== this.dpr) { this.dpr = v; this._emit(); }
  }
};
window.AE_QUALITY = gov;

/* ارتقای طبقه: اگر پیش‌نویس pre-paint خوش‌بینانه بود ولی دستگاه ضعیف‌تر از
   حدِ پایین‌تر است، همان کلاس perf-low را که index.php می‌شناسد تحمیل کن. */
const conn = navigator.connection || {};
if (conn.saveData || (navigator.deviceMemory || 8) <= 2 || (navigator.hardwareConcurrency || 8) <= 2) {
  html.classList.remove('perf-high', 'perf-mid');
  html.classList.add('perf-low');
  AE.TIER.level = 'low';
}

/* ── نمونه‌برداری FPS ── */
if (!reduced) {
  let frames = 0, winStart = performance.now(), lowSince = 0;
  const loop = t => {
    frames++;
    if (t - winStart >= 1000) {
      gov.fps = Math.round(frames * 1000 / (t - winStart));
      frames = 0; winStart = t;
      if (gov.fps < 40) {
        if (!lowSince) lowSince = t;
        else if (t - lowSince >= 3000) { gov.setDpr(gov.dpr - 0.25); lowSince = 0; }
      } else lowSince = 0;
    }
    /* وقتی تب پنهان است حلقه می‌خوابد؛ با visibility دوباره بیدار می‌شود. */
    if (!document.hidden) requestAnimationFrame(loop);
  };
  requestAnimationFrame(loop);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) { winStart = performance.now(); frames = 0; requestAnimationFrame(loop); }
  });
}

/* ── HUD — فقط ?hud=1 ── */
if (/[?&]hud=1/.test(location.search)) {
  const el = document.createElement('div');
  el.id = 'aeHud';
  el.setAttribute('aria-hidden', 'true');
  el.style.cssText = 'position:fixed;z-index:2147483647;top:calc(env(safe-area-inset-top,0px)+4px);right:4px;' +
    'padding:6px 10px;border-radius:10px;background:#000c;color:#4ade80;' +
    'font:600 12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;pointer-events:none;white-space:pre;text-align:end';
  document.body.appendChild(el);
  let city = '—';
  try { city = Intl.DateTimeFormat().resolvedOptions().timeZone || '—'; } catch (_) {}
  setInterval(() => {
    el.textContent = `${gov.fps} fps · DPR ${gov.dpr.toFixed(2)}\n${city} · φ 1.618`;
  }, 1000);
}
})();
