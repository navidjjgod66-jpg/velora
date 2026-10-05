/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — auth.js
   Profile · OTP flow (سرور) · Orders · پرچم مدیریت
   ─────────────────────────────────────────────────────────────────────
   اگر لایهٔ PHP در دسترس باشد کد واقعاً از سرور تأیید می‌شود؛ اگر نبود
   (اجرای محلی بدون PHP) به کد نمایشی برمی‌گردیم تا UI قابل تست بماند.
   پرچم `admin` همان چیزی است که سرور می‌گوید؛ شمارهٔ مدیر هرگز نمی‌آید.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, TIMERS, LS, K,
  faNum, moneyT, esc, toast, haptic, asciiDigits,
  dlStop, dlStart, backdropClose
} = window.AE;
/* DEMO_OTP فقط در حالت توسعه (localhost) مجاز است — هرگز روی پروداکشن */
const AE_HOSTS = ['localhost', '127.0.0.1'];
const IS_DEV   = AE_HOSTS.indexOf(location.hostname) !== -1;
const DEMO_OTP = IS_DEV && window.AE_DATA ? window.AE_DATA.DEMO_OTP : null;
const { getCustom } = window.AE_STATE;

const api  = window.aeApi || null;          /* اگر سرور نیست، ورود فقط در حالت توسعه باز می‌ماند */
const profile = $('#profile');

let profPhone = '', otpLeft = 0, otpDemo = !api;
/* Fallback only. The server publishes its own window as `ttl` on the
   otp_send response (api.php, from OTP_TTL in config.php), and that is what
   the countdown has to use: this constant was 60 while the server's is 120, so
   the timer hit zero and offered a resend while the code the customer was
   typing had already been valid for another 30 seconds — and the server then
   rejected it with OTP_INVALID and a message that says the code is expired.
   The customer's timer was telling them the truth about a code that was not. */
const OTP_TTL = 120;

/* وضعیت مدیریت — فقط یک پرچم بولی، هرگز شماره */
const adminState = { isAdmin: false };

const getProfile = () => LS.get(K.profile, null);
const setProfile = p => LS.set(K.profile, p);

const serverOn = () => !!(api && api.available);

/* ─── نشست سرور ─────────────────────────────────────────────────────────── */
async function loadSession() {
  if (!api) return null;
  try {
    const s = await api.sessionGet(true);
    if (s && s.available && s.auth) {
      const prev = getProfile() || {};
      setProfile({ phone: s.phone || prev.phone || '', name: prev.name || '', size: prev.size || '', authAt: Date.now() });
    }
    adminState.isAdmin = !!(s && s.admin);
  } catch (_) {}
  window.dispatchEvent(new CustomEvent('ae:session-admin'));
  return adminState.isAdmin;
}

async function requestOtpSend() {
  if (!serverOn()) {
    /* بدون سرور: کد ثابت فقط در حالت توسعه؛ روی پروداکشن ورود بسته است */
    if (DEMO_OTP) { otpDemo = true; return { demo: true, resend: OTP_TTL }; }
    throw Object.assign(new Error('سرور در دسترس نیست — ورود با پیامک موقتاً ممکن نیست.'), { code: 'server-unreachable' });
  }
  const r = await api.otpSend(profPhone);
  otpDemo = false;
  return r;
}

async function requestOtpValidation(code) {
  if (!serverOn()) {
    if (DEMO_OTP) return { ok: code === DEMO_OTP, demo: true };
    throw Object.assign(new Error('سرور در دسترس نیست — تأیید کد ممکن نیست.'), { code: 'server-unreachable' });
  }
  return api.otpVerify(profPhone, code);
}

/* ─── تایمر ───────────────────────────────────────────────────────────────
   The resend button's enabled state is derived from otpLeft in exactly one
   place, and nothing else writes it.

   It used to be written in two places that disagreed. startOtpTimer set
   `disabled = otpLeft > 0`, which is right, and stopOtpTimer set
   `disabled = true`, which is right only if the timer is being stopped because
   the dialog closed. But otpTick calls stopOtpTimer() when the countdown
   *reaches zero* — so the countdown's own completion disabled the resend
   button, permanently, for the rest of the session.

   That is the worst possible moment to disable it. The customer has just been
   told to wait 120 seconds, has waited, and is now entitled to ask for another
   code — and the one control that lets them is greyed out. The only way forward
   was to close the account panel and reopen it, which re-runs startOtpTimer
   and starts the whole 120 seconds again. A customer whose code expired and
   whose SMS did not arrive was stuck in a loop with no exit.

   So: one function owns the button, and the countdown reaching zero is the
   normal end of a countdown rather than a teardown. */
function paintOtpState() {
  const b = $('#resendOtp');
  if (b) b.disabled = otpLeft > 0;
  const hint = $('#otpResendHint');
  if (!hint) return;
  hint.textContent = otpLeft > 0
    ? `${faNum(otpLeft)} ثانیه دیگر می‌توانید کد تازه بخواهید.`
    : 'اگر کد را دریافت نکردید، می‌توانید دوباره درخواست دهید.';
  hint.classList.toggle('is-ready', otpLeft === 0);
}
function otpTick() {
  if (otpLeft > 0) otpLeft--;
  if (otpLeft <= 0) otpLeft = 0;
  paintOtpState();
  if (otpLeft === 0) {
    /* Reaching zero is the end of a countdown, not a teardown. The button is
       now available and the line above it says so. */
    stopOtpTimer();
    return;
  }
}
function startOtpTimer(sec) {
  TIMERS.clear('otp');
  otpLeft = sec || OTP_TTL;
  paintOtpState();
  if (otpLeft > 0) TIMERS.set(otpTick, 1000, 'otp');
}
const stopOtpTimer = () => {
  TIMERS.clear('otp');
  paintOtpState();
};

/* ─── باز و بسته شدن ────────────────────────────────────────────────────── */
function openProfile(fromRoute) {
  if (!profile) return;
  profile._opener = document.activeElement;
  renderProfile();
  if (!profile.open) profile.showModal();
  dlStop();
  /* فقط وقتی از ناوبری هش باز می‌کنیم آدرس را به‌روزرسانی کنیم؛
     در غیر این صورت history.replaceState باعث می‌شود hashchange
     برای بستن دیالوگ (که به '#/' برمی‌گردد) هرگز شلیک نشود. */
  if (!fromRoute && !location.hash.startsWith('#/profile')) {
    location.hash = '#/profile';
  }
}
$('#profX') && $('#profX').addEventListener('click', () => profile && profile.close());
profile && backdropClose(profile);

profile && profile.addEventListener('close', () => {
  stopOtpTimer();
  profile._opener && profile._opener.focus && profile._opener.focus({ preventScroll:true });
  profile._opener = null; dlStart();
  if (location.hash === '#/profile') history.replaceState(null, '', '#/');
  window.dispatchEvent(new CustomEvent('ae:profile-close'));
});

/* ─── رندر ────────────────────────────────────────────────────────────────
   faDate used to construct an Intl.DateTimeFormat on every call — once per
   order row. core.js already memoises the same formatter (AE.fdate) and it is
   one fewer Intl object per row on a list that can be 24 long. */
const faDate = window.AE.fdate;

function ordersInto(host, list) {
  if (!host) return;
  host.innerHTML = list.length
    ? list.map(o => {
        /* Two shapes arrive here, and they did not agree.

           LOCAL records, written by cart.js recordOrder():
             { ref, total, count, items:[{id,…}], date, paid }
           SERVER records, from api.php's my_orders:
             { id, created_at, item_count, items:[{product_id,…}], total, … }

           This renderer read only the local names, so a server order printed
           the literal string "undefined" as its tracking number, an empty date
           (new Date(undefined) is Invalid Date), and a count of zero — for
           every order the customer had actually placed. Each name is now
           resolved through both shapes.

           The second bug was worse than the cosmetics. This used to do
           `LS.set(K.orders, list)` with the server list, overwriting the local
           records wholesale. recordOrder() is what makes a customer a VERIFIED
           BUYER for the review form, and it writes into that same key — so
           opening the profile once destroyed the local order evidence, and
           after that every product showed "only verified buyers" to the person
           who had bought it. The merge below keeps both. */
        const ref  = o.ref  || o.id  || '';
        const date = o.date || o.created_at || '';
        const n    = o.count ?? o.item_count
                  ?? (Array.isArray(o.items) ? o.items.reduce((s, it) => s + (Number(it.qty) || 1), 0) : 0);
        const st   = o.status ? `<span class="mono ost">${esc(o.status)}</span>` : '';
        return `<div class="order-i"><div><span class="oref">${esc(ref)}</span><div class="od">${faDate(date, 'long') || '—'} · ${faNum(n)} جفت ${st}</div></div><span class="op">${moneyT(o.total)}</span></div>`;
      }).join('')
    : '<p class="ps2">هنوز سفارشی ثبت نشده است.</p>';
}

/* Keep whatever local records exist alongside whatever the server returned,
   newest first, de-duplicated by order reference. The server list wins on a
   collision because it carries the real status and the real total. */
function mergeOrders(local, remote) {
  const byRef = new Map();
  for (const o of (Array.isArray(local) ? local : [])) {
    const k = o.ref || o.id;
    if (k) byRef.set(k, o);
  }
  for (const o of (Array.isArray(remote) ? remote : [])) {
    const k = o.ref || o.id;
    if (k) byRef.set(k, { ...byRef.get(k), ...o });
  }
  return [...byRef.values()]
    .sort((a, b) => String(b.date || b.created_at || '').localeCompare(String(a.date || a.created_at || '')));
}

function renderProfile() {
  const prof = getProfile();
  const dash = $('#profDash'), authBox = $('#profAuth');
  if (authBox) authBox.hidden = !!prof;
  if (dash) dash.hidden = !prof;

  /* نشان پنل مدیریت فقط برای مدیر واقعی */
  const admBtn = $('#profAdmin');
  if (admBtn) {
    admBtn.hidden = !adminState.isAdmin;
    if (adminState.isAdmin && !admBtn.dataset.wired) {
      admBtn.dataset.wired = '1';
      admBtn.addEventListener('click', () => {
        if (profile) profile.close();
        window.dispatchEvent(new CustomEvent('ae:open-admin'));
      });
    }
  }
  if (!prof) return;

  const nm = $('#profName'), sz = $('#profSize'), av = $('#profAv'), ph = $('#profPhoneShow');
  nm && (nm.value = prof.name || '');
  sz && (sz.value = prof.size || '');
  av && (av.textContent = ((prof.name || 'اُ').trim().charAt(0)) || 'اُ');
  ph && (ph.textContent = prof.phone || '');

  const c = getCustom(), host = $('#profCst');
  if (host) {
    host.innerHTML = c
      ? `<div class="pv">${faNum(c.eu)} سفارشی</div><p class="ps2">طول ${faNum(c.L)} · عرض ${faNum(c.W)} سانتی‌متر${c.wide ? ' · قالب عریض' : ''}</p>`
      : '<p class="ps2">هنوز ثبت نشده — از دکمهٔ زیر شروع کنید.</p>';
  }

  const cache = LS.get(K.orders, []);
  ordersInto($('#profOrders'), cache);
  if (serverOn()) {
    api.ordersList()
      .then(list => {
        const merged = mergeOrders(cache, list);
        LS.set(K.orders, merged);
        ordersInto($('#profOrders'), merged);
      })
      .catch(() => {});
  }
}

/* ─── ویرایش پروفایل ────────────────────────────────────────────────────── */
$('#profName') && $('#profName').addEventListener('change', e => {
  const p = getProfile();
  if (p) { p.name = e.target.value.trim(); setProfile(p); renderProfile(); }
});
$('#profSize') && $('#profSize').addEventListener('change', e => {
  const p = getProfile();
  if (p) { p.size = e.target.value; setProfile(p); }
});
$('#profOut') && $('#profOut').addEventListener('click', async () => {
  if (api) { try { await api.logout(); } catch (_) {} }
  LS.del(K.profile); LS.del(K.orders); LS.del(K.session);
  adminState.isAdmin = false;
  renderProfile();
  toast('از حساب خارج شدید.');
});

/* ─── گام ۱: شماره ──────────────────────────────────────────────────────── */
const showStep = name => $$('#profAuth [data-ps]').forEach(s => { s.hidden = s.dataset.ps !== name; });

$('#sendOtp') && $('#sendOtp').addEventListener('click', async () => {
  /* asciiDigits, not /\D/g. \D means "not [0-9]", so it deletes Persian
     digits along with the punctuation — and this field's own placeholder is
     Persian ("۰۹۱۲۳۴۵۶۷۸۹"). A customer typing exactly the number shown was
     told it was invalid while eleven digits sat visibly in the box. OTP is the
     main login path here; this made it unusable for a Persian keyboard. */
  const el = $('#profPhone'), v = asciiDigits(el.value);
  const ok = /^09\d{9}$/.test(v);
  el.closest('.field').classList.toggle('invalid', !ok);
  if (!ok) { toast('شمارهٔ موبایل معتبر نیست.', 'err'); haptic('warn'); return; }

  profPhone = v;
  const btn = $('#sendOtp');
  btn.disabled = true;
  btn.classList.add('loading');
  try {
    const r = await requestOtpSend();
    showStep('otp');
    const phn = $('#otpPhone'); phn && (phn.textContent = v);
    $$('.otp-box').forEach(b => { b.value = ''; b.classList.remove('bad'); });
    const first = $('.otp-box'); first && first.focus();
    const err = $('#otpErr'); if (err) err.hidden = true;
    /* r.resend does not exist anywhere on the server. The key is `ttl`. */
    startOtpTimer((r && r.ttl) || OTP_TTL);
    const chip = $('#profAuth [data-ps="otp"] .devchip');
    if (chip) chip.hidden = !!(r && r.demo);
  } catch (e) {
    /* خطای سرور → بازگشت به گام شماره تا پیام خوانا دیده شود */
    showStep('phone');
    toast((e && e.message) || 'ارسال کد ممکن نشد.', 'err');
    haptic('warn');
  } finally {
    btn.disabled = false;
    btn.classList.remove('loading');
  }
});

$('#backPhone') && $('#backPhone').addEventListener('click', () => {
  stopOtpTimer();
  showStep('phone');
  const p = $('#profPhone'); p && p.focus();
});

/* Resending.

   Both the "busy" and the "failed" states are expressed through otpLeft and
   paintOtpState() rather than by writing `btn.disabled` directly, so there is
   still exactly one function that decides whether the resend is available.

   The busy state is a real one: a resend that has already started must not be
   started again, because each one costs an SMS and the server's own per-phone
   limit is 5 per hour. Clicking four times quickly is not a customer error, it
   is a customer who did not see the button react — and it burns four codes of
   a five-code allowance while the first one arrives and is immediately stale.

   On failure otpLeft is zeroed rather than the button being force-enabled, so
   the line below it reads "you can ask again" — which is exactly what is true,
   and which is what the customer needs to know after a failure. Leaving a
   greyed-out button with no explanation is how "broken" is concluded. */
$('#resendOtp') && $('#resendOtp').addEventListener('click', async () => {
  if (otpLeft > 0) return;
  otpLeft = 1;              /* busy: the hint and the button both say "wait" */
  paintOtpState();
  try {
    const r = await requestOtpSend();
    startOtpTimer((r && r.ttl) || OTP_TTL);
    toast('کد تازه ارسال شد.');
  } catch (e) {
    otpLeft = 0;
    paintOtpState();
    toast((e && e.message) || 'ارسال دوباره ممکن نشد.', 'err');
  }
});

/* ─── گام ۲: خانه‌های کد ────────────────────────────────────────────────── */
const otpBoxes = $$('.otp-box');
/* True from the moment a verification leaves until it settles. See the note in
   verifyOTPFlow — three entry points reach it and two of them fire unprompted. */
let otpVerifying = false;
function releaseOtpBoxes() {
  otpVerifying = false;
  const btn = $('#verifyOtp');
  if (btn) btn.disabled = false;
  otpBoxes.forEach(b => { b.readOnly = false; });
}
otpBoxes.forEach((b, i) => {
  b.addEventListener('input', () => {
    b.value = asciiDigits(b.value).slice(-1);
    if (b.value && i < otpBoxes.length - 1) otpBoxes[i+1].focus();
    if (otpBoxes.every(x => x.value)) verifyOTPFlow();
  });
  b.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !b.value && i > 0) otpBoxes[i-1].focus();
  });
  b.addEventListener('paste', e => {
    e.preventDefault();
    const t = asciiDigits(e.clipboardData.getData('text') || '').slice(0, 4);
    t.split('').forEach((ch, idx) => { if (otpBoxes[idx]) otpBoxes[idx].value = ch; });
    otpBoxes[Math.min(t.length, 3)].focus();
    if (t.length === 4) verifyOTPFlow();
  });
});

async function verifyOTPFlow() {
  const code = otpBoxes.map(b => b.value).join('');
  if (code.length < 4) return;

  /* One verification at a time.

     verifyOTPFlow is reachable from three places — the fourth digit being typed,
     the paste handler, and the button — and the first two fire the instant the
     code is complete, with no wait for the customer. Typing a four-digit code
     quickly and then reaching for the button is an ordinary thing to do, and it
     produced two concurrent verify_otp requests.

     The loser was the damaging one. On success the winner clears every box and
     completes the sign-in; the loser then arrived at the same failure branch,
     played the error animation, cleared the boxes again and showed "کد درست
     نیست" — so a correct code was reported as wrong, on a panel the customer
     had just been signed into. The button was disabled during the request but
     never *checked*, and the digit path does not go through the button at all.

     The guard is the first statement, before the code is even read, because
     that is the only place it can be unconditional. */
  if (otpVerifying) return;
  otpVerifying = true;

  const btn = $('#verifyOtp');
  if (btn) btn.disabled = true;
  /* The boxes are locked too, not just the button: a customer who can keep
     typing while a verification is in flight changes the code under it. */
  otpBoxes.forEach(b => { b.readOnly = true; });

  let res;
  try { res = await requestOtpValidation(code); }
  catch (e) {
    releaseOtpBoxes();
    toast((e && e.message) || 'بررسی کد ممکن نشد — دوباره تلاش کنید.', 'err');
    return;
  }
  releaseOtpBoxes();

  if (!res || !res.ok) {
    haptic('warn');
    const err = $('#otpErr'); if (err) err.hidden = false;
    otpBoxes.forEach(b => { b.classList.add('bad'); setTimeout(() => b.classList.remove('bad'), 600); });
    otpBoxes[0].focus();
    otpBoxes.forEach(b => { b.value = ''; });
    toast(res && res.demo ? 'کد درست نیست — در حالت آزمایشی: ۱۲۳۴' : 'کد درست نیست.', 'err');
    return;
  }

  haptic('success');
  stopOtpTimer();
  const prev = getProfile() || {};
  setProfile({ phone: profPhone, name: prev.name || '', size: prev.size || '', authAt: Date.now() });
  /* کوکی نشست HttpOnly تنها منبع هویت است؛ کش محلی گمراه‌کننده را پاک کن */
  LS.del(K.session);
  await loadSession();
  const srv = getProfile();
  if (!(serverOn() && srv && srv.phone === profPhone) && !(DEMO_OTP && res && res.ok)) {
    /* سرور نشست را تأیید نکرد → ورود را نگه ندار */
    LS.del(K.profile);
    renderProfile();
    toast('تأیید نشست از سرور انجام نشد. دوباره تلاش کنید.', 'err');
    return;
  }
  renderProfile();
  toast('به خانه خوش آمدید.');
}
$('#verifyOtp') && $('#verifyOtp').addEventListener('click', verifyOTPFlow);

/* ─── راه‌اندازی: نشست سرور را بخوان و پرچم مدیریت را روشن/خاموش کن ─────── */
loadSession().then(() => renderProfile());

window.AE_AUTH = {
  openProfile, renderProfile, getProfile,
  isAdmin: () => adminState.isAdmin,
  reloadSession: loadSession,
};
})();