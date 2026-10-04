/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — concierge.js
   Chat with "Ota" · Smart responses · Product mini-cards
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, reduced, faNum, moneyT, esc,
  dlStop, dlStart, TIMERS
} = window.AE;
const { CATALOG, PRODUCTS, PROMO } = window.AE_DATA;
const { P } = window.AE_STATE;

let concOpen = false;

function toggleConc(open) {
  concOpen = open === undefined ? !concOpen : open;
  $('#conc').classList.toggle('on', concOpen);
  if (concOpen) {
    if (!$('#concBody').children.length) {
      concMsg('bot', 'درود، من <b>اُتا</b> هستم — کنسیژ ابدی خانهٔ اُرِل. سایز، DNA سبک، هزینهٔ پوشش، ارسال، مراقبت… هر چه بخواهید می‌دانم.');
      renderConcChips();
    }
    $('#concInput').focus();
    dlStop();
  } else dlStart();
}

function concMsg(who, htmlTxt) {
  const d = document.createElement('div');
  d.className = 'cmsg ' + who;
  d.innerHTML = htmlTxt;
  $('#concBody').appendChild(d);
  $('#concBody').scrollTop = $('#concBody').scrollHeight;
}
function concTyping() {
  const d = document.createElement('div');
  d.className = 'cmsg bot typing'; d.id = 'cTyping';
  d.innerHTML = '<i></i><i></i><i></i>';
  $('#concBody').appendChild(d);
  $('#concBody').scrollTop = $('#concBody').scrollHeight;
}
function concUntyp() { const t = $('#cTyping'); if (t) t.remove(); }

/* concierge.js — renderConcChips را جایگزین کنید */
function renderConcChips() {
  $('#concChips').innerHTML = ['سایز من چنده؟','DNA سبک من','هزینه هر پوشش','بهترین برای روزمره','ارسال چند روزه؟','مراقبت چرم']
    .map(c => `<button data-concq="${c}" type="button">${c}</button>`).join('');
  /* ⚠️ لیسنر مستقیم حذف شد — delegation سراسری در انتهای فایل انجام می‌دهد */
}

/* Mini product card SVG */
const shoeSVG = window.AE.memoize(function (p, ci = 0) {
  const swv = (p.sw && p.sw[ci]) || { c:'#c8a24a' };
  const body = swv.c || '#c8a24a';
  return `<svg viewBox="0 0 560 360" role="img" aria-label="${esc(p.name || '')}" style="width:100%;height:auto;overflow:visible"><ellipse cx="286" cy="322" rx="214" ry="13" fill="#1a1208" opacity=".12"/><path d="M96 268 C84 234 86 186 110 158 C132 132 166 126 196 140 C224 153 246 177 268 199 C302 231 360 251 426 256 L492 250 Z" fill="${body}"/><path d="M54 268 L492 250 C518 248 538 258 534 274 C528 296 478 312 414 314 L106 314 C70 314 44 302 40 288 C37 276 44 269 54 268 Z" fill="#191410"/><path d="M212 158 L246 142" stroke="#f2e3b6" stroke-width="11" fill="none" stroke-linecap="round"/><path d="M230 176 L264 160" stroke="#f2e3b6" stroke-width="11" fill="none" stroke-linecap="round"/></svg>`;
});

function concAsk(q) {
  concMsg('user', esc(q));
  concTyping();
  TIMERS.once(() => {
    concUntyp();
    const t = q.toLowerCase();
    if (t.includes('dna') || t.includes('سبک من')) {
      if (P.style) {
        const names = { minimal:'مینیمال', classic:'کلاسیک', bold:'جسور', romantic:'رمانتیک' };
        concMsg('bot', `DNA سبک شما: <b style="color:var(--accent)">${names[P.style] || '—'}</b> + کاربرد <b>${P.use === 'daily' ? 'روزمره' : P.use === 'formal' ? 'رسمی' : 'مجلسی'}</b>.<br><button class="linklike" data-concfit style="color:var(--accent)">به‌روزرسانی DNA</button>`);
      } else {
        concMsg('bot', `هنوز DNA سبک ندارید.<br><button class="linklike" data-concfit style="color:var(--accent)">شروع DNA سبک (۳۰ ثانیه)</button>`);
      }
    } else if (t.includes('پوشش') || t.includes('هزینه')) {
      const p = CATALOG['termeh'], cpw = Math.round(p.price/2080);
      concMsg('bot', `محاسبهٔ هوشمند: ترمه ۱۰۰ در ۱۰ سال، فقط <b style="color:var(--accent)">${faNum(cpw)} تومان در هر پوشش</b>.<br><button class="linklike" data-concgo="arithmetic" style="color:var(--accent)">دیدن محاسبه‌گر</button>`);
    } else if (t.includes('سایز') || t.includes('فیت')) {
      if (P.size) concMsg('bot', `بر اساس پروفایل، سایز <b style="color:var(--accent)">${faNum(P.size)}</b>.<br><button class="linklike" data-concfit style="color:var(--accent)">به‌روزرسانی فیت</button>`);
      else concMsg('bot', `هنوز پروفایل فیت ندارید.<br><button class="linklike" data-concfit style="color:var(--accent)">شروع فیتینگ هوشمند</button>`);
    } else if (t.includes('ارسال') || t.includes('مرجوع')) {
      concMsg('bot', 'ارسال اکسپرس رایگان به سراسر جهان — همیشه. ۶۰ روز فرصت آزمایش. ✦');
    } else if (t.includes('مراقبت') || t.includes('واکس')) {
      concMsg('bot', 'غبارگیری، واکس‌روغن ماهی یک‌بار، قالب سدر. زیره‌دوزی مادام‌العمر رایگان.');
    } else if (t.includes('روزمره') || t.includes('راحت')) {
      const rec = PRODUCTS.filter(p => ['loafer','lowheel'].includes(p.family)).sort((a, b) => b.rating - a.rating)[0];
      concMsg('bot', `پیشنهاد من: <div class="prod-mini" data-concp="${rec.id}"><span class="th">${shoeSVG(rec, 0)}</span><span><b class="ltr">${esc(rec.name)}</b><small>${moneyT(rec.price)}</small></span></div>`);
    } else if (t.includes('مجلسی') || t.includes('شیک')) {
      const rec = PRODUCTS.filter(p => ['highheel','sandal'].includes(p.family)).sort((a, b) => b.rating - a.rating)[0];
      concMsg('bot', `برای مجالس: <div class="prod-mini" data-concp="${rec.id}"><span class="th">${shoeSVG(rec, 0)}</span><span><b class="ltr">${esc(rec.name)}</b><small>${moneyT(rec.price)}</small></span></div>`);
    } else if (t.includes('تخفیف') || t.includes('کد')) {
      concMsg('bot', `کد: <b dir="ltr">${esc(String(PROMO.code))}</b> — ${faNum(PROMO.pct)}٪ تخفیف. ✦`);
    } else {
      concMsg('bot', 'دربارهٔ <b>سایز</b>، <b>DNA سبک</b>، <b>هزینهٔ پوشش</b>، <b>ارسال</b>، <b>مراقبت</b> یا <b>پیشنهاد فرم</b> بپرسید.');
    }
    $('#concBody').scrollTop = $('#concBody').scrollHeight;
  }, reduced ? 100 : 700, 'conc:reply');
}

function concSend() {
  const inp = $('#concInput');
  const v = inp.value.trim();
  if (!v) return;
  inp.value = '';
  concAsk(v);
}

$('#concInput') && $('#concInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') concSend();
});
$('#concFab') && $('#concFab').addEventListener('click', () => toggleConc());
$$('[data-act="conc-send"]').forEach(b => b.addEventListener('click', concSend));
$$('[data-act="conc-close"]').forEach(b => b.addEventListener('click', () => toggleConc(false)));
$$('[data-act="conc-open"]').forEach(b => b.addEventListener('click', () => toggleConc(true)));

document.addEventListener('click', e => {
  const cg = e.target.closest('[data-concgo]');
  if (cg) { toggleConc(false); window.AE.scrollToEl('#' + cg.dataset.concgo); return; }
  const cf = e.target.closest('[data-concfit]');
  if (cf) { toggleConc(false); window.dispatchEvent(new CustomEvent('ae:open-fit')); return; }
  const cp = e.target.closest('[data-concp]');
  if (cp) { toggleConc(false); location.hash = '#/pdp/' + cp.dataset.concp; return; }
  const cq = e.target.closest('[data-concq]');
  if (cq) { concAsk(cq.dataset.concq); return; }
});

window.AE_CONC = { toggleConc, concAsk, getOpen: () => concOpen, shoeSVG };
})();