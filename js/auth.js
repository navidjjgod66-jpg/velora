/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — auth.js
   Profile · OTP flow · Orders display
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const {
  $, $$, html, body, TIMERS, LS, K,
  faNum, moneyT, esc, wait, toast, haptic, withLoad,
  dlStop, dlStart, backdropClose
} = window.AE;
const { DEMO_OTP } = window.AE_DATA;
const { getCustom } = window.AE_STATE;

const profile = $('#profile');
let profPhone = '', otpLeft = 0;
const OTP_TTL = 60;

const getProfile = () => LS.get(K.profile, null);
const setProfile = p => LS.set(K.profile, p);

async function requestOtpValidation(code) {
  await wait(500);
  return { ok: code === DEMO_OTP, demo: true };
}

function otpTick() {
  otpLeft--;
  const t = $('#otpTimer'); if (t) t.textContent = faNum(Math.max(0, otpLeft));
  if (otpLeft <= 0) {
    stopOtpTimer();
    const b = $('#resendOtp'); if (b) b.disabled = false;
  }
}
function startOtpTimer() {
  TIMERS.clear('otp');
  otpLeft = OTP_TTL;
  const t = $('#otpTimer'); if (t) t.textContent = faNum(otpLeft);
  const b = $('#resendOtp'); if (b) b.disabled = true;
  TIMERS.set(otpTick, 1000, 'otp');
}
const stopOtpTimer = () => {
  TIMERS.clear('otp');
  const b = $('#resendOtp'); if (b) b.disabled = true;
};

function openProfile(fromRoute) {
  profile._opener = document.activeElement;
  renderProfile();
  profile.showModal(); dlStop();
  if (!fromRoute && !location.hash.startsWith('#/profile')) {
    history.replaceState(null, '', '#/profile');
  }
}
$('#profX') && $('#profX').addEventListener('click', () => profile.close());
backdropClose(profile);

profile && profile.addEventListener('close', () => {
  stopOtpTimer();
  profile._opener && profile._opener.focus && profile._opener.focus({ preventScroll:true });
  profile._opener = null; dlStart();
  if (location.hash === '#/profile') history.replaceState(null, '', '#/');
});

function renderProfile() {
  const prof = getProfile();
  $('#profAuth').hidden = !!prof;
  $('#profDash').hidden = !prof;
  if (prof) {
    $('#profPhoneShow').textContent = prof.phone;
    $('#profName').value = prof.name || '';
    $('#profAv').textContent = ((prof.name || 'اُ').trim().charAt(0)) || 'اُ';
    $('#profSize').value = prof.size || '';
    const c = getCustom();
    $('#profCst').innerHTML = c
      ? `<div class="pv">${faNum(c.eu)} سفارشی</div><p class="ps2">طول ${faNum(c.L)} · عرض ${faNum(c.W)} سانتی‌متر${c.wide ? ' · قالب عریض' : ''}</p>`
      : `<p class="ps2">هنوز ثبت نشده — از دکمهٔ زیر شروع کنید.</p>`;
    const orders = LS.get(K.orders, []);
    $('#profOrders').innerHTML = orders.length
      ? orders.map(o => {
          const n = o.count || (Array.isArray(o.items) ? o.items.length : 0);
          return `<div class="order-i"><div><span class="oref">${esc(o.ref)}</span><div class="od">${new Intl.DateTimeFormat('fa-IR', { dateStyle:'long' }).format(new Date(o.date))} · ${faNum(n)} جفت</div></div><span class="op">${moneyT(o.total)}</span></div>`;
        }).join('')
      : '<p class="ps2">هنوز سفارشی ثبت نشده است.</p>';
  }
}

$('#profName') && $('#profName').addEventListener('change', e => {
  const p = getProfile();
  if (p) { p.name = e.target.value.trim(); setProfile(p); renderProfile(); }
});
$('#profSize') && $('#profSize').addEventListener('change', e => {
  const p = getProfile();
  if (p) { p.size = e.target.value; setProfile(p); }
});
$('#profOut') && $('#profOut').addEventListener('click', () => {
  localStorage.removeItem(K.profile);
  renderProfile();
  toast('از حساب خارج شدید.');
});

/* Send OTP */
$('#sendOtp') && $('#sendOtp').addEventListener('click', () => {
  const el = $('#profPhone'), v = el.value.replace(/\D/g, '');
  const ok = /^09\d{9}$/.test(v);
  el.closest('.field').classList.toggle('invalid', !ok);
  if (!ok) { toast('شمارهٔ موبایل معتبر نیست.', 'err'); haptic('warn'); return; }
  profPhone = v;
  withLoad($('#sendOtp'), 550);
  TIMERS.once(() => {
    $$('#profAuth [data-ps]').forEach(s => s.hidden = s.dataset.ps !== 'otp');
    $('#otpPhone').textContent = v;
    $$('.otp-box').forEach(b => b.value = '');
    $('.otp-box').focus();
    $('#otpErr').hidden = true;
    startOtpTimer();
  }, 500, 'otp:send');
});
$('#backPhone') && $('#backPhone').addEventListener('click', () => {
  stopOtpTimer();
  $$('#profAuth [data-ps]').forEach(s => s.hidden = s.dataset.ps !== 'phone');
  $('#profPhone').focus();
});
$('#resendOtp') && $('#resendOtp').addEventListener('click', () => {
  toast('کد دوباره ارسال شد — در حالت آزمایشی همان ۱۲۳۴.');
  startOtpTimer();
});

/* OTP boxes */
const otpBoxes = $$('.otp-box');
otpBoxes.forEach((b, i) => {
  b.addEventListener('input', () => {
    b.value = b.value.replace(/\D/g, '').slice(-1);
    if (b.value && i < 3) otpBoxes[i+1].focus();
    if (i === 3 && b.value) verifyOTPFlow();
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
  const btn = $('#verifyOtp'); btn.disabled = true;
  let res;
  try { res = await requestOtpValidation(code); }
  catch {
    btn.disabled = false;
    toast('بررسی کد ممکن نشد — دوباره تلاش کنید.', 'err');
    return;
  }
  btn.disabled = false;
  if (!res || !res.ok) {
    haptic('warn');
    $('#otpErr').hidden = false;
    otpBoxes.forEach(b => { b.classList.add('bad'); setTimeout(() => b.classList.remove('bad'), 600); });
    otpBoxes[0].focus();
    otpBoxes.forEach(b => b.value = '');
    toast(res && res.demo ? 'کد درست نیست — در حالت آزمایشی: ۱۲۳۴' : 'کد درست نیست.', 'err');
    return;
  }
  haptic('success');
  stopOtpTimer();
  const prev = getProfile() || {};
  setProfile({ phone:profPhone, name:prev.name || '', size:prev.size || '', authAt:Date.now() });
  renderProfile();
  toast('به خانه خوش آمدید.');
}
$('#verifyOtp') && $('#verifyOtp').addEventListener('click', verifyOTPFlow);

window.AE_AUTH = { openProfile, renderProfile, getProfile };
})();