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
  faNum, moneyT, esc, toast, haptic,
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
const OTP_TTL = 60;

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

/* ─── تایمر ─────────────────────────────────────────────────────────────── */
function otpTick() {
  otpLeft--;
  const t = $('#otpTimer'); if (t) t.textContent = faNum(Math.max(0, otpLeft));
  if (otpLeft <= 0) { stopOtpTimer(); }
}
function startOtpTimer(sec) {
  TIMERS.clear('otp');
  otpLeft = sec || OTP_TTL;
  const t = $('#otpTimer'); if (t) t.textContent = faNum(otpLeft);
  const b = $('#resendOtp');
  if (b) b.disabled = otpLeft > 0;
  if (otpLeft > 0) TIMERS.set(otpTick, 1000, 'otp');
}
const stopOtpTimer = () => {
  TIMERS.clear('otp');
  const b = $('#resendOtp'); if (b) b.disabled = true;
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

/* ─── رندر ──────────────────────────────────────────────────────────────── */
const faDate = iso => {
  const d = new Date(iso);
  return isNaN(d) ? '' : new Intl.DateTimeFormat('fa-IR', { dateStyle:'long' }).format(d);
};

function ordersInto(host, list) {
  if (!host) return;
  host.innerHTML = list.length
    ? list.map(o => {
        const n = o.count || (Array.isArray(o.items) ? o.items.length : 0);
        return `<div class="order-i"><div><span class="oref">${esc(o.ref)}</span><div class="od">${faDate(o.date)} · ${faNum(n)} جفت</div></div><span class="op">${moneyT(o.total)}</span></div>`;
      }).join('')
    : '<p class="ps2">هنوز سفارشی ثبت نشده است.</p>';
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
      .then(list => { LS.set(K.orders, list); ordersInto($('#profOrders'), list); })
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
  const el = $('#profPhone'), v = (el.value || '').replace(/\D/g, '');
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
    startOtpTimer(r && r.resend ? r.resend : OTP_TTL);
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

$('#resendOtp') && $('#resendOtp').addEventListener('click', async () => {
  const btn = $('#resendOtp');
  btn.disabled = true;
  try {
    const r = await requestOtpSend();
    startOtpTimer(r && r.resend ? r.resend : OTP_TTL);
    toast('کد تازه ارسال شد.');
  } catch (e) {
    toast((e && e.message) || 'ارسال دوباره ممکن نشد.', 'err');
    btn.disabled = false;
  }
});

/* ─── گام ۲: خانه‌های کد ────────────────────────────────────────────────── */
const otpBoxes = $$('.otp-box');
otpBoxes.forEach((b, i) => {
  b.addEventListener('input', () => {
    b.value = b.value.replace(/\D/g, '').slice(-1);
    if (b.value && i < otpBoxes.length - 1) otpBoxes[i+1].focus();
    if (otpBoxes.every(x => x.value)) verifyOTPFlow();
  });
  b.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !b.value && i > 0) otpBoxes[i-1].focus();
  });
  b.addEventListener('paste', e => {
    e.preventDefault();
    const t = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 4);
    t.split('').forEach((ch, idx) => { if (otpBoxes[idx]) otpBoxes[idx].value = ch; });
    otpBoxes[Math.min(t.length, 3)].focus();
    if (t.length === 4) verifyOTPFlow();
  });
});

async function verifyOTPFlow() {
  const code = otpBoxes.map(b => b.value).join('');
  if (code.length < 4) return;
  const btn = $('#verifyOtp');
  btn && (btn.disabled = true);
  let res;
  try { res = await requestOtpValidation(code); }
  catch (e) {
    if (btn) btn.disabled = false;
    toast((e && e.message) || 'بررسی کد ممکن نشد — دوباره تلاش کنید.', 'err');
    return;
  }
  if (btn) btn.disabled = false;

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