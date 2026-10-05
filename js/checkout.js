/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — checkout.js
   Three-step checkout flow · Validators · Promo · Order persistence
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, reduced, TIMERS, LS, K,
  faNum, moneyT, esc, toast, haptic,
  dlStop, dlStart, wireDialog
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
/* True from the moment the final step's request leaves until it settles. See the
   note at the guard: the button is re-enabled by the request's own outcome and
   by nothing else. */
let ckoSubmitting = false;

/* ═══ Validators ═══ */
/* Persian and Arabic-Indic digits fold to ASCII before any length or pattern
   test. Otherwise `ckZip` counts two bytes per Persian digit, a 10-digit code
   reads as 20 "characters" and the shape check is meaningless — which is how
   an invalid code gets stored and a parcel comes back.

   This was a second copy of the same helper; velora-bridge.js had a third.
   Both now call the one in core.js, because a fourth, broken, copy in auth.js
   used /\D/g and made OTP login impossible to complete on a Persian keyboard.
   See the foldDigits note in core.js. */
const asciiDigits = window.AE.asciiDigits;

const ckoValidators = {
  ckMail: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),
  ckName: v => v.trim().length >= 2,
  /* api.php normalizes then requires a real mobile: 09 + 9 digits. The client
     check exists to avoid a round trip, so it mirrors the server's rule
     rather than guessing at one. */
  ckPhone: v => /^09\d{9}$/.test(asciiDigits(v)),
  ckAddr: v => v.trim().length >= 5,
  ckCity: v => v.trim().length >= 2,
  ckProvince: v => v.trim().length >= 2,
  ckDistrict: v => v.trim().length >= 2,
  ckPlaque: v => asciiDigits(v).length >= 1,
  /* Ten digits. A checksum would be wrong here: velora_address_in() enforces
     the shape only, because the post office's own lookup is better evidence
     than our arithmetic — so the client must not be stricter than the server
     it is talking to, or it refuses codes the server would have accepted. */
  ckZip:  v => asciiDigits(v).length === 10,
  ckHolder: v => v.trim().length >= 2,
  ckCard: v => v.replace(/\s/g, '').length === 16 && /^\d+$/.test(v.replace(/\s/g, '')),
  ckExp:  v => /^(0[1-9]|1[0-2])\/\d{2}$/.test(v),
  ckCvc:  v => /^\d{3,4}$/.test(v)
};
const STEP_FIELDS = [
  ['ckName', 'ckPhone', 'ckMail'],
  ['ckProvince', 'ckCity', 'ckDistrict', 'ckAddr', 'ckPlaque', 'ckZip'],
  ['ckHolder', 'ckCard', 'ckExp', 'ckCvc']
];

/* Validate one field and say what is wrong with it, in words.

   It used to set `.invalid` and `aria-invalid` and stop there, which produced a
   1px red underline and nothing else. The form rendered no error element for any
   field, so there was no text to reveal and no aria-describedby to point at one:
   a customer who failed validation was told *that* a field was wrong and never
   *what* about it, and a screen-reader customer heard "invalid" with no
   explanation attached.

   The message itself lives in the markup, next to the input, rather than in this
   map. That is deliberate — a message written here is a message that has to be
   translated, escaped, kept in step with the validator above it, and delivered
   through a `hidden` attribute that can only be cleared from here. Putting the
   Persian sentence in the DOM means it is read by the same template that renders
   the label, and it is available to assistive technology without JavaScript
   having run at all. */
const ckCheck = id => {
  const el = $('#' + id);
  const ok = ckoValidators[id](el.value);
  const field = el.closest('.field');
  field.classList.toggle('invalid', !ok);
  el.setAttribute('aria-invalid', String(!ok));
  const msg = $('#' + id + 'Err');
  if (msg) msg.hidden = ok;
  return ok;
};

/* Labels for the server's per-field rejection codes, so a refusal lands on the
   input that caused it. api.php returns `field` alongside every message; the
   ids below are the ones that currently exist in the form. */
const CKO_FIELD_HINT = {
  GEO_INVALID:        ['ckProvince', 'استان و شهر را از فهرست انتخاب کنید.'],
  DISTRICT_INVALID:   ['ckDistrict',  'محله را وارد کنید.'],
  PLAQUE_INVALID:     ['ckPlaque',    'شمارهٔ پلاک را وارد کنید.'],
  LINE_INVALID:       ['ckAddr',      'نشانی را کامل‌تر بنویسید.'],
  POSTAL_INVALID:     ['ckZip',       'کدپستی باید ۱۰ رقم باشد.'],
  RECEIVER_INVALID:   ['ckName',      'نام گیرنده را کامل بنویسید.'],
  PHONE_INVALID:      ['ckPhone',     'شمارهٔ همراه معتبر نیست.'],
  PHONE_UNVERIFIED:   ['ckPhone',     'این شماره هنوز تأیید نشده است.'],
  INVALID_SIZE:       null,
  PRODUCT_NOT_FOUND:  null,
};

Object.keys(ckoValidators).forEach(id => {
  const el = $('#' + id); if (!el) return;
  el.addEventListener('input', () => {
    el.closest('.field').classList.remove('invalid');
    el.removeAttribute('aria-invalid');
    /* The message goes with the red underline. Leaving it up after the customer
       has started fixing the field is the worst of both: it asserts something
       about the current value that is no longer true, and it pushes the rest of
       the form down by a line while they are typing into this one. */
    const msg = $('#' + id + 'Err');
    if (msg) msg.hidden = true;
    if (id === 'ckCard') {
      const d = el.value.replace(/\D/g, '').slice(0, 16);
      el.value = d.replace(/(\d{4})(?=\d)/g, '$1 ');
    }
    if (id === 'ckExp') {
      let d = el.value.replace(/\D/g, '').slice(0, 4);
      if (d.length > 2) d = d.slice(0, 2) + '/' + d.slice(2);
      el.value = d;
    }
    /* Live-normalize the numeric fields as they are typed, so what is
       submitted is what the server's own digit normalizer would produce.
       Applying it on input rather than on submit means the length check runs
       against the same string the server will see. */
    if (id === 'ckZip') {
      const d = asciiDigits(el.value).slice(0, 10);
      if (d !== el.value) el.value = d;
      const msg = $('#ckZipMsg');
      if (msg) { msg.hidden = d.length === 10; msg.textContent = `کدپستی باید ۱۰ رقم باشد (${faNum(d.length)} از ۱۰).`; }
    }
    if (id === 'ckPhone' || id === 'ckPlaque') {
      const d = asciiDigits(el.value);
      if (d !== el.value) el.value = d;
    }
  });
});

/* ─── Geo: province → city ─────────────────────────────────────────────
     Both lists come from api.php's geo_regions action, which is the same
     table velora_geo_valid() checks against on the server. The picker and the
     validator therefore cannot disagree about which city belongs to which
     province, which is the failure free-text entry allows: "تهران، اصفهان"
     passes any two independent length checks and names a city in the wrong
     province. */
let ckoGeo = null;   /* { province: [city, ...] } */
let ckoGeoTried = false;

/* Marks a select as unchosen so it renders in the muted placeholder colour —
   see the `select[data-unset]` rule in components.css. Cleared as soon as a
   real value is selected, which is what makes the field look filled in. */
function ckoMarkSet(sel, hasValue) {
  if (!sel) return;
  if (hasValue) sel.removeAttribute('data-unset');
  else sel.setAttribute('data-unset', '');
}

async function ckoLoadGeo() {
  if (ckoGeo) return ckoGeo;
  /* One attempt per dialog-open. Without the flag, every call to openCko()
     re-requests the map on a network failure, which for a customer on a flaky
     connection means the province list is never usable. */
  if (ckoGeoTried) return null;
  ckoGeoTried = true;
  const sel = $('#ckProvince');
  if (!sel) return null;
  try {
    const r = api ? await api.geoRegions() : null;
    const map = r && r.map;
    if (!map || typeof map !== 'object') throw new Error('no-map');
    ckoGeo = map;
    const frag = document.createDocumentFragment();
    Object.keys(map).forEach(prov => {
      const o = document.createElement('option');
      o.value = prov; o.textContent = prov;
      frag.appendChild(o);
    });
    sel.appendChild(frag);
    ckoMarkSet(sel, !!sel.value);
  } catch (_) {
    ckoGeo = null;
    toast('فهرست استان‌ها بارگذاری نشد.', 'err');
  }
  return ckoGeo;
}

function ckoFillCities(province) {
  const city = $('#ckCity');
  if (!city) return;
  city.innerHTML = province
    ? '<option value="">انتخاب کنید…</option>'
    : '<option value="">ابتدا استان</option>';
  city.disabled = !province;
  ckoMarkSet(city, false);
  if (!province || !ckoGeo || !ckoGeo[province]) return;
  const frag = document.createDocumentFragment();
  ckoGeo[province].forEach(c => {
    const o = document.createElement('option');
    o.value = c; o.textContent = c;
    frag.appendChild(o);
  });
  city.appendChild(frag);
}

$('#ckProvince') && $('#ckProvince').addEventListener('change', e => {
  ckoFillCities(e.target.value);
  ckoMarkSet(e.target, !!e.target.value);
  const city = $('#ckCity');
  if (city) { city.value = ''; city.closest('.field').classList.remove('invalid'); }
});
$('#ckCity') && $('#ckCity').addEventListener('change', e => ckoMarkSet(e.target, !!e.target.value));

/* ─── Postal lookup ────────────────────────────────────────────────────
     Optional convenience, never a gate. If the operator has configured a token
     the post office fills province, city and district in for them; with no
     token the field is skipped entirely. A lookup failure must never become a
     checkout failure, so nothing here blocks the next step. */
let ckoPostalTimer = null;
$('#ckZip') && $('#ckZip').addEventListener('input', e => {
  clearTimeout(ckoPostalTimer);
  const code = asciiDigits(e.target.value);
  if (code.length !== 10 || !api || !api.postalLookup) return;
  ckoPostalTimer = setTimeout(async () => {
    try {
      const r = await api.postalLookup(code);
      if (!r || !r.ok || !r.province) return;
      await ckoLoadGeo();
      const prov = $('#ckProvince'), city = $('#ckCity'), dist = $('#ckDistrict');
      if (prov && ckoGeo && ckoGeo[r.province]) {
        prov.value = r.province;
        ckoMarkSet(prov, true);
        ckoFillCities(r.province);
        if (city && (r.city || '')) {
          city.value = r.city;
          ckoMarkSet(city, true);
        }
      }
      /* Only fill the district when it is empty — a customer who has already
         typed a more specific name than the post office returns keeps theirs. */
      if (dist && !dist.value.trim() && r.district) dist.value = r.district;
    } catch (_) { /* convenience only */ }
  }, 500);
});

/* ─── Auth state ────────────────────────────────────────────────────────
     api.php's checkout action requires a signed-in user with a verified phone,
     and refuses anything else with LOGIN_REQUIRED or PHONE_UNVERIFIED. The
     client never checked either, so an anonymous customer filled in three
     steps and was told the order failed at the very last moment. The gate is
     moved to the front of the flow, where it can be acted on. */
let ckoSession = { auth: false, phone: '' };
async function ckoRefreshSession() {
  if (!api) return ckoSession;
  try {
    const s = await api.sessionGet(true);
    ckoSession = { auth: !!(s && s.auth), phone: (s && s.phone) || '' };
  } catch (_) { ckoSession = { auth: false, phone: '' }; }
  const note = $('#ckoAuthNote');
  const tel  = $('#ckPhone');
  if (note) {
    if (!ckoSession.auth) {
      note.hidden = false;
      note.textContent = 'برای ثبت سفارش باید وارد حساب خود شوید.';
    } else if (!ckoSession.phone) {
      note.hidden = false;
      note.textContent = 'حساب شما شمارهٔ تأییدشده ندارد — برای ارسال سفارش باید شمارهٔ همراه خود را تأیید کنید.';
    } else {
      note.hidden = true;
    }
  }
  if (tel && ckoSession.phone && !tel.value.trim()) tel.value = ckoSession.phone;
  return ckoSession;
}
addEventListener('ae:profile-close', () => { ckoRefreshSession(); });

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
  requestAnimationFrame(() => $('#ckName').focus());
  /* Geo and session are both network reads. Fire them after the dialog is on
     screen so the sheet paints immediately instead of waiting on either. */
  ckoLoadGeo();
  ckoRefreshSession();
}
$('#ckoX') && $('#ckoX').addEventListener('click', () => cko.close());
wireDialog(cko);
ckoBack && ckoBack.addEventListener('click', () => { if (ckoStep > 0) { ckoStep--; ckoPaint(); } });

/* ═══ Promo ═══
   The resolved definition is what gets stored — code, percentage, cap and
   minimum together — so state.js can compute the same number the server will.

   Two bugs here. `state.promo = { code: PROMO.code, ... }` stored the HOUSE
   code, not the one that was typed: entering VIP20 and being offered a 20%
   discount produced a basket priced with VEL10's 10%. And `PROMO.pct` was read
   as though PROMO were a flat object, which it is not — it is a table with a
   lookup, so the success message read "undefined٪ تخفیف".

   `min` is checked here too, so a customer is told the threshold is unmet
   before they fill in an address, rather than at the last request. It is still
   enforced server-side; this is a courtesy, not a gate. */
$('#promoApply') && $('#promoApply').addEventListener('click', () => {
  const inp = $('#promoInput'), msg = $('#promoMsg');
  const v = PROMO.lookup(inp.value);
  const sub = itemsSum();
  if (v && sub >= v.min) {
    state.promo = v;
    LS.set(K.promo, v);
    inp.value = v.code;
    msg.className = 'promo-msg ok';
    const capped = v.cap > 0 && Math.round(sub * v.pct / 100) > v.cap;
    msg.textContent = capped
      ? `${v.code} اعمال شد — ${faNum(v.pct)}٪ تا سقف ${moneyT(v.cap)}.`
      : `${v.code} اعمال شد — ${faNum(v.pct)}٪ تخفیف روی جمع کل.`;
    haptic('add');
  } else if (v && sub < v.min) {
    msg.className = 'promo-msg err';
    msg.textContent = `این کد از ${moneyT(v.min)} به بالا معتبر است.`;
    haptic('warn');
  } else {
    state.promo = null;
    LS.set(K.promo, null);
    msg.className = 'promo-msg err';
    msg.textContent = 'این کد معتبر نیست. کد خانه: ' + PROMO.code;
    haptic('warn');
  }
  ckoSummary();
});
$('#promoInput') && $('#promoInput').addEventListener('input', () => {
  const m = $('#promoMsg'); if (m) m.textContent = '';
});
$('#promoInput') && $('#promoInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); $('#promoApply').click(); }
});

/* ═══ Next / Submit ═══ */
/* Puts the error on the field the server named, so a refusal is actionable
   instead of a toast that disappears. Returns true if the error was shown. */
function ckoShowServerError(e) {
  const code = (e && e.code) || '';
  const hint = CKO_FIELD_HINT[code];
  if (hint) {
    const [id, text] = hint;
    const el = $('#' + id);
    if (el) {
      el.closest('.field').classList.add('invalid');
      el.setAttribute('aria-invalid', 'true');
      /* Put the server's own words on the field, not only in a toast.

         A toast is a transient, screen-edge message that a screen reader may not
         announce at all, and it disappears. The server refused an order and this
         is the sentence explaining why — it belongs on the field that caused it,
         where the customer is looking and where it stays until they fix it. The
         static message under the input is replaced rather than shown, because
         the server's is the more specific of the two. */
      const msg = $('#' + id + 'Err');
      if (msg) { msg.textContent = text; msg.hidden = false; }
      if (ckoStep !== STEP_FIELDS.findIndex(step => step.includes(id))) {
        const want = STEP_FIELDS.findIndex(step => step.includes(id));
        if (want >= 0) { ckoStep = want; ckoPaint(); }
      }
      el.focus();
      toast(text, 'err');
      return true;
    }
  }
  return false;
}

ckoNext && ckoNext.addEventListener('click', async () => {
  /* The very first statement, before validation. A guard placed after the
     checks it is meant to prevent is a guard that does not prevent: Enter
     pressed twice on the phone field, or a click that lands while the first is
     still inside an await, arrives here identically, and anything the guard is
     protecting has already been requested. */
  if (ckoSubmitting) return;

  const fields = STEP_FIELDS[ckoStep]; let firstBad = null;
  fields.forEach(id => { if (!ckCheck(id) && !firstBad) firstBad = id; });
  if (firstBad) {
    $('#' + firstBad).focus();
    toast('یک فیلد هنوز منتظر شماست.', 'err');
    return;
  }

  /* Both of these are checked before the customer is asked for a card, not
     after. api.php rejects an anonymous session (LOGIN_REQUIRED) and a phone
     that this account has not verified by OTP (PHONE_UNVERIFIED); discovering
     that on the final step means the form has been filled in for nothing. */
  if (ckoStep === 0 && api) {
    await ckoRefreshSession();
    if (!ckoSession.auth) {
      toast('برای ثبت سفارش ابتدا وارد حساب خود شوید.', 'err');
      cko.close(); dlStart();
      if (window.AE_AUTH) AE_AUTH.openProfile(true);
      else toast('از منوی حساب کاربری وارد شوید.', 'err');
      return;
    }
    const typed = asciiDigits($('#ckPhone').value);
    const known = asciiDigits(ckoSession.phone || '');
    if (!known) {
      toast('حساب شما شمارهٔ تأییدشده ندارد.', 'err');
      cko.close(); dlStart();
      if (window.AE_AUTH) AE_AUTH.openProfile(true);
      return;
    }
    if (typed !== known) {
      const el = $('#ckPhone');
      el.closest('.field').classList.add('invalid');
      el.setAttribute('aria-invalid', 'true');
      el.focus();
      toast('این شماره برای حساب شما تأیید نشده است — همان شمارهٔ حساب را وارد کنید.', 'err');
      return;
    }
  }

  if (ckoStep < 2) {
    ckoStep++; ckoPaint();
    const next = $('#' + STEP_FIELDS[ckoStep][0]);
    if (next) next.focus();
    return;
  }
  /* An in-flight guard, in the client, for the one request in this application
     that creates something.

     withLoad(ckoNext, 1000) was the only protection, and it worked against
     itself: withLoad arms a 1000ms timer that re-enables the button whether or
     not the request has come back. On a connection slower than one second —
     which is the normal case for the request that talks to a payment gateway —
     the button came back while the first request was still running, and a
     second click produced a second order. The server does have fingerprint
     idempotency, but it keys on the session and the browser sends both
     requests on the same session, so the duplicate was caught at the *claim*
     layer only if the first had not yet settled; a genuine double-click that
     straddled the settle boundary placed two orders.

     Nothing re-enables the button while a submit is live. It is re-enabled by
     exactly two things: the request finishing (success or a real failure), and
     the flow leaving this step. Nothing else, and no timer. */
  ckoSubmitting = true;
  ckoNext.classList.add('loading');
  ckoNext.disabled = true;
  ckoBack.disabled = true;
  const paidTotal = cartSum();
  const count = state.cart.reduce((s, i) => s + i.qty, 0);
  const itemsSnap = state.cart.map(i => ({
    id:i.id, name:CATALOG[i.id] ? CATALOG[i.id].name : null,
    size:i.size, color:i.color, qty:i.qty
  }));
  /* ─── گام آخر: پرداخت سمت سرور ───
     مبلغ هرگز از مرورگر باور نمی‌شود؛ api.php آن را از کاتالوگ سرور
     محاسبه می‌کند. نگاشت فیلدها به شکلی که سرور انتظار دارد در
     velora-bridge.js انجام می‌شود، نه اینجا. */
  const profile = LS.get(K.profile, {}) || {};
  const payload = {
    items: state.cart.map(i => ({ id:i.id, size:i.size, color:i.color, qty:i.qty })),
    promo: state.promo ? state.promo.code : null,
    contact: {
      name:  $('#ckName').value.trim(),
      email: $('#ckMail').value.trim(),
      phone: asciiDigits($('#ckPhone').value)
    },
    address: {
      receiver:  $('#ckName').value.trim(),
      province:  $('#ckProvince').value,
      city:      $('#ckCity').value,
      district:  $('#ckDistrict').value.trim(),
      line:      $('#ckAddr').value.trim(),
      plaque:    asciiDigits($('#ckPlaque').value),
      unit:      asciiDigits($('#ckUnit').value),
      postal_code: asciiDigits($('#ckZip').value)
    }
  };

  const reEnable = () => {
    ckoSubmitting = false;
    ckoNext.classList.remove('loading');
    ckoNext.disabled = false;
    ckoBack.disabled = false;
  };

  /* Only reached when the server genuinely could not be reached at all — see
     the note at the call site. A local-only order is not a real order, so it is
     labelled as one on the confirmation screen rather than dressed up as a
     placed order. */
  const finishLocal = (ref, total) => {
    /* Terminal, but the flag is still cleared. Leaving ckoSubmitting latched
       because "the form is hidden now" is how a guard becomes a landmine the
       next time this dialog is reused. */
    ckoSubmitting = false;
    ckoNext.classList.remove('loading');
    ckoNext.disabled = true;
    ckoBack.disabled = false;
    $('#ckoRef').textContent = ref;
    const warn = $('#ckoLocalWarn');
    if (warn) warn.hidden = false;
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
    toast('ثبت نشد — سرور در دسترس نیست. سفارش شما نگه داشته شد.', 'err');
  };

  /* The one thing this flow must never do is answer "سفارش ثبت شد" when the
     server refused the order.

     It used to. Every throw from paymentStart was caught, and anything that
     did not come back with a `ref` fell through to finishLocal() with a
     locally-generated reference — so GEO_INVALID, LOGIN_REQUIRED,
     RATE_LIMIT, INSUFFICIENT_STOCK and CHECKOUT_FAIL all ended on the same
     green "order placed" screen, the cart emptied, and the order existed
     nowhere but in this browser's localStorage. The customer had paid nothing
     and been told the parcel was queued.

     So the two conditions are separated. A response we received is an answer,
     even when the answer is no: it is shown, on the field that caused it, and
     the cart is left alone. Only an absent server — no api bridge at all, or a
     network failure before any reply — falls back to a local record, and that
     screen says plainly that it was not sent. */
  const failWith = (e) => {
    reEnable();
    if (!ckoShowServerError(e)) {
      toast((e && e.message) || 'ثبت سفارش ممکن نشد — سبد شما حفظ شده است.', 'err');
    }
  };

  (async () => {
    if (!api) {
      finishLocal('V-' + Date.now().toString(36).toUpperCase().slice(-6), paidTotal);
      return;
    }
    try {
      const r = await api.paymentStart(payload);
      if (r && r.url) {
        /* نشانهٔ تراکنش در-flight — برای تشخیص بازگشت مبهم از درگاه */
        LS.set(K.payPend, { ref:r.ref, at:Date.now() });
        api.paymentGo(r.url);
        return; /* صفحه به درگاه می‌رود؛ اینجا کاری نداریم */
      }
      /* ok:true with no pay_url is not a success the client can act on, and it
         is not a rejection either. Treat it as an unanswered request. */
      failWith(Object.assign(new Error('پاسخ ناقص از سرور'), { code: 'NO_PAY_URL' }));
      return;
    } catch (e) {
      if (String(e && e.message) === 'server-unreachable') {
        finishLocal('V-' + Date.now().toString(36).toUpperCase().slice(-6), paidTotal);
        return;
      }
      failWith(e);
    }
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
  } else if (ret.status === 'confirming') {
    /* The order exists, its stock is held, and the gateway did not answer.
       This is the one state that must NOT clear the bag and must NOT invite a
       retry: the customer very likely has been charged, and a second attempt
       would be a second order for one pair of shoes.

       So: the bag is left exactly as it was, and the message says what is
       actually true — we have the order, we are confirming the payment, and
       nothing is required from them. No countdown, no "try again", because
       both would invite the retry.

       Then it resolves on its own. api.php already leaves the order
       `pending` and the operator already has a log row to reconcile from, but
       a log row is not an answer the customer can see — so the page asks the
       order what it thinks every few seconds and reports the verdict. Almost
       every indeterminate settles this way, because the cause is nearly always
       a blip rather than a declined card. */
    toast('سفارش شما ثبت شد و پرداخت در حال تأیید است. اگر مبلغی از حساب شما کسر شد، تا چند دقیقه دیگر وضعیت نهایی ثبت می\u200cشود. نیازی به اقدام نیست.', '', null, 9000);
    /* Deliberately not completeCheckout(), and deliberately no error toast. */
    watchPaymentOutcome(ret.ref);
  } else {
    toast(ret.message || 'پرداخت تکمیل نشد. سبد شما حفظ شده است.', 'err');
  }
  /* پاک کردن query از آدرس تا refresh دوباره هندل را اجرا نکند */
  ckoClearHash();
  return true;
}
function ckoClearHash(){ try{ history.replaceState(null,'','#/'); }catch(_){} }

/* ═══ انتظار نتیجهٔ پرداختِ نامعلوم ═══════════════════════════════════════
   وقتی درگاه پاسخ نداد، سفارش «در انتظار تأیید» می‌ماند. اینجا خودِ سفارش
   را می‌پرسیم تا مشتری نتیجه را ببیند، بدون اینکه مجبور شود پیگیری کند.

   چرا محدود و با فاصلهٔ فزاینده:
   · شروع از ۴ ثانیه و دو برابر شدن — تا اگر درگاه واقعاً برگشته، مشتری زود
     خبردار شود؛ و اگر برنگشته، هر ده ثانیه یک درخواست بی‌نتیجه نفرستیم.
   · سقف ۸ تلاش ⇒ حدود ۴ دقیقه. بیشتر از این، خبر دیگری است: نه «در حال
     تأیید»، بلکه «با ما تماس بگیرید» — و این را صریح می‌گوییم به‌جای اینکه
     بی‌صدا متوقف شویم.
   · با هر پاسخ ناموفق شمارنده یک واحد کم می‌شود و بازه کمی بلندتر می‌شود،
     پس یک شبکهٔ ضعیف هم به همان سقف می‌رسد بدون اینکه ۸ بار پشت‌سرهم
     درخواست بدهد.
   · اگر صفحه پنهان شود، تا برگشتنش صبر می‌کنیم. polling در پس‌زمینهٔ یک
     تب باز اما نامرئی فقط مصرف است.
   · اگر مشتری خودش همین سفارش را در «حساب من» ببیند و ببندد، باز هم resolve
     می‌شود — این فقط مکمل است، نه جایگزینِ آن صفحه. */
const PAY_WATCH_STEPS = [4000, 8000, 10000, 12000, 15000, 20000, 25000, 30000];
let payWatchTimer = null;

function watchPaymentOutcome(orderId) {
  if (!orderId || !api || typeof api.paymentStatus !== 'function') return;
  let step = 0;

  const stop = () => { if (payWatchTimer) { clearTimeout(payWatchTimer); payWatchTimer = null; } };

  const settle = (paid, ref) => {
    stop();
    if (paid) {
      recordOrder({ ref: ref || orderId, total: 0, count: 0, items: [],
                    date: new Date().toISOString(), phone: '', paid: true });
      completeCheckout();
      toast('پرداخت تأیید شد — سفارش ' + (ref || orderId) + ' ثبت شد.', '', null, 7000);
    } else {
      toast('پرداخت ناموفق بود. سبد شما حفظ شده است.', 'err');
    }
  };

  const tick = async () => {
    if (document.hidden) { payWatchTimer = setTimeout(tick, 4000); return; }
    try {
      const s = await api.paymentStatus(orderId);
      if (s.isPaid) { settle(true, s.paymentRef); return; }
      /* failed / cancelled are the only terminal non-paid states the order can
         hold. Anything else — unpaid, pending, unknown, or a status this build
         has never heard of — is still undecided, so it keeps waiting rather
         than being guessed at. */
      if (s.paymentStatus === 'failed' || s.paymentStatus === 'cancelled') { settle(false); return; }
    } catch (_) {
      /* یک تلاش ناموفق یعنی کمی زودتر؛ نه پایان کار. */
    }
    step++;
    if (step >= PAY_WATCH_STEPS.length) {
      stop();
      toast('تأیید پرداخت طول کشید. سفارش شما ثبت شده است — اگر تا یک ساعت دیگر وضعیت نهایی ثبت نشد، با ما تماس بگیرید و شمارهٔ سفارش را بگویید.', '', null, 12000);
      return;
    }
    payWatchTimer = setTimeout(tick, PAY_WATCH_STEPS[step]);
  };

  payWatchTimer = setTimeout(tick, PAY_WATCH_STEPS[0]);
  /* رفتن به تب دیگر و برگشتن، دوباره پرس‌وجو می‌کند — مشتری ممکن است همین
     لحظه برگشته باشد و نباید تا پایان بازه صبر کند. */
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && payWatchTimer) { clearTimeout(payWatchTimer); payWatchTimer = setTimeout(tick, 600); }
  }, { once: true });
}

/* اگر کاربر با URL بازگشت لند کرد (بعد از boot main.js) */
addEventListener('load', () => setTimeout(ckoHandlePaymentReturn, 300));
/* اگر hashchange در همان نشست رخ داد */
addEventListener('hashchange', () => { setTimeout(ckoHandlePaymentReturn, 60); });

$('#ckoDoneX') && $('#ckoDoneX').addEventListener('click', () => cko.close());

/* ═══ Draft-Persisting Forms (ویژگی ۷) — ae.draft.v1 ═══════════════════
   هر keystroke در فیلدهای مرحلهٔ ۱/۲ نشانیِ چک‌اوت، debounce ۴۰۰ms روی
   localStorage می‌نشیند. بازگشت کاربر (refesh/بستن dialog/رفتن و برگشتن)
   پیش‌نویس را با یک toast دوشاخه برمی‌گرداند: «بازیابی» مقدارها را می‌گذارد،
   «دور ریختن» کلید را پاک می‌کند. سفارش موفق → پاک‌شدن قطعی.

   textarea نشانی شمارندهٔ کاراکتر زنده هم می‌گیرد؛ بالای ۱۰۰۰ کاراکتر
   هشدار رنگی (مطابق سقف سرور در includes/addresses.php). */
const DRAFT_KEY = 'ae.draft.v1';
const DRAFT_IDS = ['ckName', 'ckPhone', 'ckMail', 'ckProvince', 'ckCity',
                   'ckDistrict', 'ckAddr', 'ckPlaque', 'ckUnit', 'ckZip'];
let draftTimer = null, draftOffered = false;

function draftSave() {
  clearTimeout(draftTimer);
  draftTimer = setTimeout(() => {
    const d = {};
    DRAFT_IDS.forEach(id => { const el = $('#' + id); if (el && el.value) d[id] = el.value; });
    LS.set(DRAFT_KEY, d);
  }, 400);
}

function charCountPaint(ta) {
  let cc = $('#ckAddrCC');
  if (!cc) {
    cc = document.createElement('span');
    cc.id = 'ckAddrCC'; cc.className = 'charcount'; cc.setAttribute('aria-live', 'polite');
    ta.insertAdjacentElement('afterend', cc);
  }
  const n = [...ta.value].length; /* code-point نه UTF-16 */
  cc.textContent = faNum(n) + ' / ۱۰۰۰';
  cc.classList.toggle('is-warn', n > 1000);
}

function draftTryRestore() {
  if (draftOffered) return;
  const d = LS.get(DRAFT_KEY, null);
  if (!d || !Object.keys(d).length) return;
  draftOffered = true;
  toast('پیش‌نویس نشانی از بازدید پیشین یافت شد.', 'ok', {
    label: 'بازیابی',
    fn: () => {
      DRAFT_IDS.forEach(id => {
        const el = $('#' + id);
        if (el && d[id] != null) {
          el.value = d[id];
          /* selectهای وابسته (شهر بعد از استان) باید cascade شوند:
             تغییر برنامه‌ای change را خودکار نمی‌زند؛ dispatch می‌کنیم
             تا هندلر موجودِ province→city اجرا شود. */
          el.dispatchEvent(new Event('input',  { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
      const addr = $('#ckAddr'); if (addr) charCountPaint(addr);
      toast('پیش‌نویس بازیابی شد.', 'ok');
    }
  }, 12000);
  /* اگر دکمه فشرده نشود، toast منقضی می‌شود و پیش‌نویس دست‌نخورده می‌ماند؛
     دفعهٔ بعد دوباره پیشنهاد می‌شود چون offer فقط با بازیابی واقعی done است. */
  setTimeout(() => { if ($('#' + 'ckName')?.value === '') draftOffered = false; }, 13000);
}

cko.addEventListener('input', e => {
  if (!e.target.closest('#ckoForm')) return;
  if (e.target.matches('input,select,textarea')) {
    draftSave();
    if (e.target.id === 'ckAddr') charCountPaint(e.target);
  }
});
/* پیشنهاد در همان لحظهٔ باز شدن فرم — بعد از paint تا focus ندزدد.
   openCko یک function declaration است و hoist می‌شود؛ wrapper همین‌جا
   تعریف می‌شود و تنها export پایین، نسخهٔ بسته‌بندی‌شده را می‌برد. */
const _openCko = openCko;
function openCkoWithDraft() {
  _openCko();
  if (cko.open) requestAnimationFrame(draftTryRestore);
}

/* ═══ Exports ═══ */
window.AE_CKO = { openCko: openCkoWithDraft };
})();