/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — atelier.js
   Atelier panel · Records carousel · CPW calculator · Lookbook rail ·
   Heartbeat (drop countdown + clocks + live visitors)
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, reduced, RAF, TIMERS, clamp, faNum, faPad, moneyT, esc
} = window.AE;
const {
  PRODUCTS, CATALOG, AT_ORDERS, AT_STATUS, AT_CLASS, AT_FEED, AT_PEOPLE,
  RECS
} = window.AE_DATA;
const { state } = window.AE_STATE;
const { shoeSVG } = window.AE_CONC;

/* ═══ Atelier feed ═══ */
const AT_FEED_LIVE = [...AT_FEED];
function logAtelier(text) {
  AT_FEED_LIVE.unshift({ ico:'◈', text, time:'همین حالا' });
  if (AT_FEED_LIVE.length > 8) AT_FEED_LIVE.pop();
  renderAtFeed();
}
function renderAtFeed() {
  const feed = $('#atFeed'); if (!feed) return;
  feed.innerHTML = AT_FEED_LIVE.slice(0, 6).map(f =>
    `<div class="at-item"><span class="at-ico">${f.ico}</span><p style="flex:1">${esc(f.text)}</p><time>${esc(f.time)}</time></div>`
  ).join('');
}
function atOrderRow(o) {
  return `<div class="at-item"><span class="at-no">${o.no}</span><div class="at-body"><b>${esc(o.item)}</b><small>${esc(o.name)} · ×${faNum(o.qty)} · ${moneyT(o.price)}</small></div><span class="at-status ${AT_CLASS[o.status]}">${AT_STATUS[o.status]}</span></div>`;
}

let atelierRendered = false;

/* ─── Bench cycle (state survives re-renders) ─── */
let benchState = null;
const rndPair = () => PRODUCTS[(Math.random()*PRODUCTS.length)|0].name;
function paintBench() {
  const benchGrid = $('#benchGrid'); if (!benchGrid) return;
  if (!benchState) benchState = AT_PEOPLE.map(b => ({ b, step:14 + ((Math.random()*44)|0), pair:rndPair() }));
  $$('.bench__c', benchGrid).forEach((el, i) => {
    const x = benchState[i]; if (!x) return;
    const bn = $('.bn', el); if (bn) bn.textContent = `${faNum(x.step)} / ۶۸`;
    const bar = $('.bench__bar i', el);
    if (bar) bar.style.width = clamp(x.step/68*100, 0, 100) + '%';
    const phase = x.step < 24 ? 'جفت روی قالب استراحت می‌کند.'
      : x.step < 45 ? 'نه بخیه در سانتی‌متر.'
      : x.step < 60 ? 'شیشه، موم زنبور، صبر.'
      : 'آمادهٔ قدم روی مرمر.';
    const pr = $('.bench__pair', el);
    if (pr) pr.textContent = `قالب‌گیری ${x.pair} — ${phase}`;
  });
}
function startBenchCycle() {
  const benchGrid = $('#benchGrid');
  if (!benchGrid || !$$('.bench__c', benchGrid).length) return;
  paintBench();
  if (reduced || TIMERS.has('at:bench')) return;
  TIMERS.set(() => {
    if (!benchState) return;
    benchState.forEach(x => { x.step += 1; if (x.step >= 68) { x.step = 1; x.pair = rndPair(); } });
    paintBench();
  }, 11000, 'at:bench');
}

function renderAtelier(force = false) {
  if (atelierRendered && !force) return;
  atelierRendered = true;

  const list = $('#atOrdersList');
  if (list) list.innerHTML = AT_ORDERS.slice(0, 4).map(atOrderRow).join('');
  renderAtFeed();

  const bench = $('#liveBench');
  if (bench) bench.innerHTML = shoeSVG({ name:'اتلیه', sw:[{ c:'#b8942a' }] });

  const ring = $('#atRingFg');
  if (ring) {
    const C = 2 * Math.PI * 52;
    ring.style.strokeDasharray = C;
    ring.style.strokeDashoffset = C;
    TIMERS.once(() => { ring.style.strokeDashoffset = C * (1 - 0.8); }, 200, 'at:ring');
    const pct = $('#atRingPct');
    if (pct && window.gsap && !reduced) {
      const o = { v:0 };
      gsap.to(o, { v:80, duration:1.6, ease:'power3.out', onUpdate:() => { pct.textContent = faNum(Math.round(o.v)) + '٪'; } });
    } else if (pct) pct.textContent = '۸۰٪';
  }

  const benchGrid = $('#benchGrid');
  if (benchGrid) {
    benchGrid.innerHTML = AT_PEOPLE.map(p => `<div class="bench__c">
      <div class="bench__top">
        <span class="bench__av" aria-hidden="true">${esc(p.a)}</span>
        <div class="bench__who"><b>${esc(p.n)}</b><span>${esc(p.role)}</span></div>
      </div>
      <p class="bench__pair"></p>
      <div class="bench__step"><span>مرحله</span><b class="bn">—</b></div>
      <div class="bench__bar"><i style="width:0%"></i></div></div>`).join('');
    paintBench();
  }
}
addEventListener('ae:render-atelier', () => renderAtelier(true));
addEventListener('ae:log-atelier', e => logAtelier(e.detail.text));
renderAtelier();
/* listener یکتا — دیگر تایمرهای سراسری سایت را پاک نمی‌کند */
addEventListener('ae:mode-change', e => {
  const on = e.detail && e.detail.mode === 'atelier';
  if (on) startBenchCycle();
  else TIMERS.clear('at:bench');
});
/* ═══ Records carousel ═══ */
let recCur = 0;
function renderRecs() {
  const stage = $('#recStage'); if (!stage) return;
  stage.innerHTML = RECS.map((r, i) => `<div class="rec-item${i === recCur ? ' act' : ''}" data-i="${i}">
    <q class="rec-q">${esc(r.q)}</q>
    <div class="rec-meta">
      <div class="rec-stats">${r.stats.map(s => `<div><b>${esc(s[0])}</b><span>${esc(s[1])}</span></div>`).join('')}</div>
      <div class="rec-who"><span class="av" aria-hidden="true">${esc(r.ini)}</span>
      <div>${esc(r.who)}<small>${esc(r.loc)} · ✓ سفارش <span class="ltr">${esc(r.order)}</span></small></div></div>
    </div></div>`).join('');
  const idx = $('#recIdx');
  if (idx) idx.innerHTML = `<b>${faPad(recCur+1)}</b> / ${faPad(RECS.length)}`;
  $$('#recDots button').forEach((b, i) => b.classList.toggle('act', i === recCur));
}
$('#recDots').innerHTML = RECS.map((_, i) => `<button type="button" aria-label="رکورد ${faNum(i+1)}"><i></i></button>`).join('');
$$('#recDots button').forEach((b, i) => b.addEventListener('click', () => { recCur = i; renderRecs(); }));
$('#recPrev') && $('#recPrev').addEventListener('click', () => { recCur = (recCur - 1 + RECS.length) % RECS.length; renderRecs(); });
$('#recNext') && $('#recNext').addEventListener('click', () => { recCur = (recCur + 1) % RECS.length; renderRecs(); });
renderRecs();
if (!reduced) TIMERS.set(() => { recCur = (recCur + 1) % RECS.length; renderRecs(); }, 7000, 'recs');

/* ═══ CPW calculator ═══ */
function renderArPicker() {
  const s = $('#arSel'); if (!s) return;
  s.innerHTML = PRODUCTS.slice().sort((a, b) => a.price - b.price)
    .map(p => `<option value="${p.id}">${esc(p.name)} — ${moneyT(p.price)}</option>`).join('');
  s.value = 'termeh';
}
const setRangeFill = el => {
  const min = +el.min || 0, max = +el.max || 100;
  el.style.setProperty('--p', ((+el.value - min)/(max - min))*100 + '%');
};
function updateCPW() {
  const p = CATALOG[$('#arSel').value] || PRODUCTS[0];
  const yrs = +$('#arY').value, wpw = +$('#arW').value;
  const wears = yrs*52*wpw, cpw = p.price/wears;
  $('#arYV').textContent = faNum(yrs);
  $('#arWV').textContent = faNum(wpw);
  $('#arOut').textContent = cpw.toLocaleString('fa-IR', { minimumFractionDigits:2, maximumFractionDigits:2 }) + ' تومان';
  $('#arSub').textContent = `در ${faNum(yrs)} سال و ${faNum(wears)} پوشش از ${p.name}.`;
  const theirs = Math.ceil(yrs*12/14)*120*100000;
  $('#arAlt').textContent = `همین ${faNum(yrs)} سال با کفش ۱۲۰ یورویی مد سریع: ${moneyT(theirs)}.`;
  const mx = Math.max(p.price, theirs);
  $('#barO').style.width = Math.max(4, p.price/mx*100) + '%';
  $('#barT').style.width = Math.max(4, theirs/mx*100) + '%';
  $('#barOV').textContent = moneyT(p.price);
  $('#barTV').textContent = moneyT(theirs);
}
renderArPicker();
[$('#arY'), $('#arW')].forEach(el => {
  if (!el) return;
  el.addEventListener('input', () => { setRangeFill(el); updateCPW(); });
  setRangeFill(el);
});
$('#arSel') && $('#arSel').addEventListener('change', updateCPW);
updateCPW();

/* ═══ Lookbook rail ═══ */
const lb = $('#lbRail'), lbP = $('#lbProg');
if (lb) {
  let down = false, sx = 0, sl = 0;
  $$('img', lb).forEach(window.AE_UI.wireImg);
  lb.addEventListener('pointerdown', e => { down = true; sx = e.clientX; sl = lb.scrollLeft; lb.classList.add('drag'); try { lb.setPointerCapture(e.pointerId); } catch(_){} });
  lb.addEventListener('pointermove', e => { if (!down) return; lb.scrollLeft = sl - (e.clientX - sx); });
  lb.addEventListener('pointerup', () => { down = false; lb.classList.remove('drag'); });
  lb.addEventListener('pointercancel', () => { down = false; lb.classList.remove('drag'); });
  lb.addEventListener('keydown', e => {
    if (e.key === 'ArrowLeft') { e.preventDefault(); lb.scrollBy({ left:-320, behavior: reduced ? 'auto' : 'smooth' }); }
    if (e.key === 'ArrowRight') { e.preventDefault(); lb.scrollBy({ left:320, behavior: reduced ? 'auto' : 'smooth' }); }
  });
  let lbW = 0;
  const measureLb = () => { lbW = lb.scrollWidth - lb.clientWidth; };
  let lbT = false;
  const upd = () => {
    if (!lbP) return;
    lbP.style.transform = `scaleX(${lbW > 0 ? clamp(Math.abs(lb.scrollLeft)/lbW, 0, 1) : 0})`;
  };
  lb.addEventListener('scroll', () => { if (!lbT) { lbT = true; RAF.add(() => { lbT = false; upd(); }); } }, { passive:true });
  if ('ResizeObserver' in window) new ResizeObserver(() => { measureLb(); RAF.add(upd); }).observe(lb);
  measureLb(); upd();
}

/* ═══ Drop stock ═══ */
(() => {
  const l = $('#dsLeft'), n = $('#dsNote'), f = $('#dsFill');
  let left = 7;
  if (l) {
    const paintDrop = () => {
      l.textContent = `${faNum(left)} جفت مانده`;
      n.textContent = 'از ۲۴ · شماره‌گذاری دستی';
      f.style.width = clamp((24 - left)/24*100, 4, 100) + '%';
    };
    paintDrop();
    if (!reduced) TIMERS.set(() => {
      if (left > 1 && Math.random() < .35) { left--; paintDrop(); }
    }, 31000, 'ds');
  }
})();

/* ═══ Heartbeat: countdown + clocks + visitors ═══ */
const cdD = $('#cdD'), cdH = $('#cdH'), cdM = $('#cdM'), cdS = $('#cdS');
const clockHosts = $$('[data-tz]');
const liveTs = $('#liveTs');
const heroLive = $('#heroLive');
let cdTarget = null, visitors = 14;

function nextDrop() {
  const d = new Date();
  let add = (7 - d.getDay()) % 7;
  if (add === 0 && d.getHours() >= 20) add = 7;
  const t = new Date(d);
  t.setDate(d.getDate() + add); t.setHours(20, 0, 0, 0);
  return t;
}
const TZ_FMT = clockHosts.map(c => {
  try {
    return new Intl.DateTimeFormat('en-GB', { hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false, timeZone:c.dataset.tz });
  } catch { return null; }
});

function heartbeat() {
  const now = new Date();
  if (cdD) {
    if (!cdTarget) cdTarget = nextDrop();
    let diff = cdTarget - now.getTime();
    if (diff < 0) { cdTarget = nextDrop(); diff = cdTarget - now.getTime(); }
    const pad = n => faPad(n);
    cdD.textContent = pad(Math.floor(diff/864e5));
    cdH.textContent = pad(Math.floor(diff/36e5)%24);
    cdM.textContent = pad(Math.floor(diff/6e4)%60);
    cdS.textContent = pad(Math.floor(diff/1e3)%60);
  }
  if (clockHosts.length) {
    clockHosts.forEach((c, i) => {
      const f = TZ_FMT[i];
      c.textContent = f ? f.format(now) : '--:--:--';
    });
  }
  if (liveTs) {
    const p2 = v => String(v).padStart(2, '0');
    liveTs.textContent = `${p2(now.getHours())}:${p2(now.getMinutes())}:${p2(now.getSeconds())}`;
  }
  if (heroLive) {
    visitors += Math.random() < .5 ? 1 : -1;
    visitors = clamp(visitors, 8, 22);
    heroLive.textContent = faNum(visitors);
  }
}
heartbeat();
TIMERS.set(heartbeat, 1000, 'hb');
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) heartbeat();
});
window.AE_ATELIER = { renderAtelier, logAtelier, renderRecs };
})();