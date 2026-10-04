/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — checkout.js
   Three-step checkout flow · Validators · Promo · Order persistence
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, reduced, TIMERS, LS, K,
  faNum, moneyT, esc, toast, haptic, withLoad,
  dlStop, dlStart, backdropClose
} = window.AE;
const { CATALOG, PROMO, MAX_ORDERS } = window.AE_DATA;
const { state, itemsSum, discount, cartSum, persBag } = window.AE_STATE;

const cko = $('#cko');
const ckoStepsL = $$('#ckoSteps li');
const ckoProg = $('#ckoProg');
const ckoBack = $('#ckoBack'), ckoNext = $('#ckoNext');
const ckoNextT = $('#ckoNextT'), ckoDone = $('#ckoDone');
let ckoStep = 0;

/* ═══ Validators ═══ */
const ckoValidators = {
  ckMail: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),
  ckName: v => v.trim().length >= 2,
  ckAddr: v => v.trim().length >= 4,
  ckCity: v => v.trim().length >= 2,
  ckZip:  v => v.trim().length >= 3,
  ckHolder: v => v.trim().length >= 2,
  ckCard: v => v.replace(/\s/g, '').length === 16 && /^\d+$/.test(v.replace(/\s/g, '')),
  ckExp:  v => /^(0[1-9]|1[0-2])\/\d{2}$/.test(v),
  ckCvc:  v => /^\d{3,4}$/.test(v)
};
const STEP_FIELDS = [['ckMail','ckName'],['ckAddr','ckCity','ckZip'],['ckHolder','ckCard','ckExp','ckCvc']];

const ckCheck = id => {
  const el = $('#' + id);
  const ok = ckoValidators[id](el.value);
  el.closest('.field').classList.toggle('invalid', !ok);
  el.setAttribute('aria-invalid', String(!ok));
  return ok;
};

Object.keys(ckoValidators).forEach(id => {
  const el = $('#' + id); if (!el) return;
  el.addEventListener('input', () => {
    el.closest('.field').classList.remove('invalid');
    el.removeAttribute('aria-invalid');
    if (id === 'ckCard') {
      const d = el.value.replace(/\D/g, '').slice(0, 16);
      el.value = d.replace(/(\d{4})(?=\d)/g, '$1 ');
    }
    if (id === 'ckExp') {
      let d = el.value.replace(/\D/g, '').slice(0, 4);
      if (d.length > 2) d = d.slice(0, 2) + '/' + d.slice(2);
      el.value = d;
    }
  });
});

/* ═══ Paint / summary ═══ */
function ckoPaint() {
  ckoStepsL.forEach((li, i) => {
    li.classList.toggle('is-on', i === ckoStep);
    li.classList.toggle('is-done', i < ckoStep);
  });
  ckoProg.style.transform = `scaleX(${(ckoStep+1)/3})`;
  $$('.cko__step', cko).forEach(fs => fs.classList.toggle('is-on', +fs.dataset.step === ckoStep));
  ckoBack.disabled = ckoStep === 0;
  ckoNextT.textContent = ckoStep === 2 ? 'ثبت سفارش' : 'ادامه';
}
function ckoSummary() {
  const count = state.cart.reduce((s, i) => s + i.qty, 0);
  const sub = itemsSum(), disc = discount(), total = cartSum();
  $('#ckoCount').textContent = faNum(count);
  $('#ckoSub').textContent = moneyT(sub);
  $('#ckoVat').textContent = moneyT(Math.round(total - total/1.2));
  $('#ckoTotal').textContent = moneyT(total);
  const discRow = $('#ckoDiscRow');
  if (discRow) {
    discRow.hidden = disc <= 0;
    if (disc > 0) {
      $('#ckoDiscCode').textContent = state.promo.code;
      $('#ckoDisc').textContent = '−' + moneyT(disc);
    }
  }
  if (state.promo) $('#promoInput').value = state.promo.code;
}

/* ═══ Open ═══ */
function openCko() {
  const bag = $('#bag');
  if (bag && bag.open) bag.close();
  if (!state.cart.length) { toast('سبد شما خالی است.', 'err'); return; }
  ckoStep = 0; ckoPaint(); ckoSummary(); ckoDone.hidden = true;
  $('#ckoForm').style.display = '';
  $('.cko__sum', cko).style.display = '';
  $('.cko__nav', cko).style.display = '';
  cko._opener = document.activeElement;
  cko.showModal(); dlStop();
  requestAnimationFrame(() => $('#ckMail').focus());
}
$('#ckoX') && $('#ckoX').addEventListener('click', () => cko.close());
backdropClose(cko);
cko && cko.addEventListener('close', () => {
  cko._opener && cko._opener.focus && cko._opener.focus({ preventScroll:true });
  cko._opener = null; dlStart();
});
ckoBack && ckoBack.addEventListener('click', () => { if (ckoStep > 0) { ckoStep--; ckoPaint(); } });

/* ═══ Promo ═══ */
$('#promoApply') && $('#promoApply').addEventListener('click', () => {
  const inp = $('#promoInput'), msg = $('#promoMsg');
  const typed = PROMO.normalize(inp.value);
  if (PROMO.match(typed)) {
    state.promo = { code:PROMO.code, pct:PROMO.pct };
    LS.set(K.promo, state.promo);
    inp.value = PROMO.code;
    msg.className = 'promo-msg ok';
    msg.textContent = `${PROMO.code} اعمال شد — ${PROMO.pct}٪ تخفیف روی جمع کل.`;
    haptic('add');
    ckoSummary();
  } else {
    state.promo = null;
    LS.set(K.promo, null);
    msg.className = 'promo-msg err';
    msg.textContent = 'این کد معتبر نیست. کد خانه: ' + PROMO.code;
    haptic('warn');
    ckoSummary();
  }
});
$('#promoInput') && $('#promoInput').addEventListener('input', () => {
  const m = $('#promoMsg'); if (m) m.textContent = '';
});
$('#promoInput') && $('#promoInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); $('#promoApply').click(); }
});

/* ═══ Next / Submit ═══ */
ckoNext && ckoNext.addEventListener('click', () => {
  const fields = STEP_FIELDS[ckoStep]; let firstBad = null;
  fields.forEach(id => { if (!ckCheck(id) && !firstBad) firstBad = id; });
  if (firstBad) {
    $('#' + firstBad).focus();
    toast('یک فیلد هنوز منتظر شماست.', 'err');
    return;
  }
  if (ckoStep < 2) {
    ckoStep++; ckoPaint();
    $('#' + STEP_FIELDS[ckoStep][0]).focus();
    return;
  }
  withLoad(ckoNext, 1000);
  ckoNext.disabled = true;
  ckoBack.disabled = true;
  const paidTotal = cartSum();
  const count = state.cart.reduce((s, i) => s + i.qty, 0);
  const itemsSnap = state.cart.map(i => ({
    id:i.id, name:CATALOG[i.id] ? CATALOG[i.id].name : null,
    size:i.size, color:i.color, qty:i.qty
  }));
  TIMERS.once(() => {
    ckoBack.disabled = false;
    const ref = 'V-' + Date.now().toString(36).toUpperCase().slice(-6);
    $('#ckoRef').textContent = ref;
    const orders = Array.isArray(LS.get(K.orders, [])) ? LS.get(K.orders, []) : [];
    orders.unshift({
      ref, total:paidTotal, count, items:itemsSnap.slice(0, 12),
      date:new Date().toISOString(),
      phone:(LS.get(K.profile, {})).phone || ''
    });
    LS.set(K.orders, orders.slice(0, MAX_ORDERS));
    $('#ckoForm').style.display = 'none';
    $('.cko__sum', cko).style.display = 'none';
    $('.cko__nav', cko).style.display = 'none';
    ckoDone.hidden = false;
    state.cart = [];
    window.dispatchEvent(new CustomEvent('ae:render-bag'));
    persBag();
    window.dispatchEvent(new CustomEvent('ae:apply'));
    $('#ckoDoneX').focus();
    window.dispatchEvent(new CustomEvent('ae:log-atelier', { detail:{ text:`سفارش جدید ${ref} به صف تولید پیوست` } }));
    toast('سفارش رزرو شد — میزبان ظرف ۲۴ ساعت تأیید می‌کند.');
  }, 1000, 'cko:pay');
});
$('#ckoDoneX') && $('#ckoDoneX').addEventListener('click', () => cko.close());

/* ═══ Exports ═══ */
window.AE_CKO = { openCko };
})();