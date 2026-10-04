/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — checkout.js
   Three-step checkout flow · Validators · Promo · Order persistence
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, reduced, TIMERS, LS, K,
  faNum, moneyT, esc, toast, haptic, withLoad,
  dlStop, wireDialog
} = window.AE;
const { CATALOG, PROMO } = window.AE_DATA;
const { state, itemsSum, discount, cartSum } = window.AE_STATE;
const { recordOrder, completeCheckout } = window.AE_CART;
const api = window.aeApi || null;

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
wireDialog(cko);
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
  /* ─── گام آخر: پرداخت سمت سرور (زرین‌پال) با fallback رزرو ───
     مبلغ هرگز از مرورگر باور نمی‌شود؛ payment_request.php آن را از
     کاتالوگ سرور محاسبه می‌کند. اگر درگاه تنظیم نبود یا PHP در دسترس
     نبود، سفارش «رزرو» روی سرور ثبت می‌شود و در نهایت فقط localStorage. */
  const profile = LS.get(K.profile, {}) || {};
  const payload = {
    items: state.cart.map(i => ({ id:i.id, size:i.size, color:i.color, qty:i.qty })),
    promo: state.promo ? state.promo.code : null,
    contact: {
      name:  $('#ckName').value.trim(),
      email: $('#ckMail').value.trim(),
      phone: profile.phone || ''
    },
    address: {
      line1: $('#ckAddr').value.trim(),
      city:  $('#ckCity').value.trim(),
      zip:   $('#ckZip').value.trim()
    }
  };

  const finishLocal = (ref, total) => {
    ckoBack.disabled = false;
    $('#ckoRef').textContent = ref;
    recordOrder({
      ref, total, count, items:itemsSnap.slice(0, 12),
      date:new Date().toISOString(),
      phone:profile.phone || ''
    });
    $('#ckoForm').style.display = 'none';
    $('.cko__sum', cko).style.display = 'none';
    $('.cko__nav', cko).style.display = 'none';
    ckoDone.hidden = false;
    completeCheckout();
    $('#ckoDoneX').focus();
    window.dispatchEvent(new CustomEvent('ae:log-atelier', { detail:{ text:`سفارش جدید ${ref} به صف تولید پیوست` } }));
    toast('سفارش ثبت شد — میزبان ظرف ۲۴ ساعت تأیید می‌کند.');
  };

  (async () => {
    if (api) {
      try {
        const r = await api.paymentStart(payload);
        if (r && r.url) {
          /* نشانهٔ تراکنش در-flight — برای تشخیص بازگشت مبهم از درگاه */
          LS.set(K.payPend, { ref:r.ref, at:Date.now() });
          api.paymentGo(r.url);
          return; /* صفحه به درگاه می‌رود؛ اینجا کاری نداریم */
        }
      } catch (e) {
        /* not_configured / gateway_* / unreachable → رزرو مستقیم روی سرور */
        if (String(e && e.message) !== 'server-unreachable') {
          try {
            const r2 = await api.orderCreate(payload);
            if (r2 && r2.ref) { finishLocal(r2.ref, r2.total || paidTotal); return; }
          } catch (_) { /* سرور در دسترس نیست → حالت محلی */ }
        }
      }
    }
    finishLocal('V-' + Date.now().toString(36).toUpperCase().slice(-6), paidTotal);
  })();
});

/* ═══ بازگشت از درگاه: #/checkout?status=success|failed&ref=…&open=1 ═══
   payment_verify.php پس از تأیید زرین‌پال کاربر را همین‌جا می‌آورد.
   این هندلر دیالوگ نتیجه را باز می‌کند، سبد را خالی می‌کند و رکورد
   سفارش را در لیست محلی می‌گذارد تا با پنل «حساب من» هم‌خوان بماند.
   اعتبار: فقط وقتی نشانهٔ in-flight (K.payPend) تازه باشد نتیجه پذیرفته
   می‌شود — لینک bookmark شدهٔ قدیمی دیگر سفارش جعلی نمی‌سازد. */
let payReturnSeen = false;
const PAY_PEND_TTL = 30 * 60 * 1000;
function ckoHandlePaymentReturn() {
  if (!api || payReturnSeen) return false;
  const ret = api.paymentReturn();
  if (!ret) return false;
  payReturnSeen = true;
  const pend = LS.get(K.payPend, null);
  LS.del(K.payPend); /* یک‌بار مصرف — هرگز دوباره پردازش نشود */
  const pendFresh = !!(pend && pend.at && (Date.now() - Number(pend.at)) < PAY_PEND_TTL);
  const openDlg = (location.hash.match(/[?&]open=1/) !== null);
  if (!pendFresh) {
    /* بازگشت بدون درخواست in-flight → پیام هشدار، بدون تغییر سبد/سفارش */
    toast('بازگشت مبهم از درگاه — سفارشی ثبت نشد.', 'err');
    ckoClearHash();
    return true;
  }
  if (ret.status === 'success') {
    const ref = ret.ref || '';
    recordOrder({ ref, total: ret.amount || 0, count: 0, items: [],
                  date: new Date().toISOString(), phone: '', paid: true });
    completeCheckout();
    if (openDlg || ref) {
      $('#ckoRef').textContent = ref || '—';
      $('#ckoForm').style.display = 'none';
      $('.cko__sum', cko).style.display = 'none';
      $('.cko__nav', cko).style.display = 'none';
      ckoDone.hidden = false;
      cko.showModal(); dlStop();
      requestAnimationFrame(() => $('#ckoDoneX').focus());
    }
    toast('پرداخت تأیید شد — سفارش ' + (ref || '') + ' ثبت شد.');
  } else {
    toast(ret.message || 'پرداخت تکمیل نشد. سبد شما حفظ شده است.', 'err');
  }
  /* پاک کردن query از آدرس تا refresh دوباره هندل را اجرا نکند */
  ckoClearHash();
  return true;
}
function ckoClearHash(){ try{ history.replaceState(null,'','#/'); }catch(_){} }

/* اگر کاربر با URL بازگشت لند کرد (بعد از boot main.js) */
addEventListener('load', () => setTimeout(ckoHandlePaymentReturn, 300));
/* اگر hashchange در همان نشست رخ داد */
addEventListener('hashchange', () => { setTimeout(ckoHandlePaymentReturn, 60); });

$('#ckoDoneX') && $('#ckoDoneX').addEventListener('click', () => cko.close());

/* ═══ Exports ═══ */
window.AE_CKO = { openCko };
})();