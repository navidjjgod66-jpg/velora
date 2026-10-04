/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — js/api.js
   پلِ ارتباط مرورگر با لایهٔ PHP روی هاست اشتراکی
   ─────────────────────────────────────────────────────────────────────
   • OTP : رمز را «ملی پیامک» تولید و پیامک می‌کند؛ این اسکریپت فقط
           { to } را به api/otp_send.php می‌فرستد و پاسخ سرویس را برای
           ارزیابی در سرور می‌گذارد. هیچ کدی اینجا ساخته/نگه نمی‌شود.
   • Pay : api/payment_request.php → Authority زرین‌پال → هدایت کاربر به
           درگاه؛ بازگشت به api/payment_verify.php و سپس /#/checkout?...
   • اگر لایهٔ PHP در دسترس نباشد (مثلاً اجرای لوکال بدون سرور PHP)،
     aeApi.available = false می‌شود و UI پیام روشنی نشان می‌دهد.
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const { LS, K } = window.AE;

/* پایهٔ مسیر API: کنار index.html پوشهٔ api قرار دارد */
const BASE = (() => {
  try {
    const b = new URL('api/', document.baseURI);
    return b.href;
  } catch (_) { return 'api/'; }
})();

let probe = null;          // نتیجهٔ آخرین بررسی در دسترس بودن سرور
let lastError = null;

async function post(file, body = {}, { expectJson = true, timeout = 25000 } = {}) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeout);
  try {
    const res = await fetch(BASE + file, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json;charset=utf-8', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      signal: ctrl.signal,
    });
    let data = null;
    const text = await res.text().catch(() => '');
    if (expectJson) {
      try { data = JSON.parse(text); }
      catch (_) {
        /* HTML برگشته (سرور PHP نصب نیست یا redirect به لاگین هاست) */
        throw Object.assign(new Error('server-unreachable'), { status: res.status, html: text.slice(0, 120) });
      }
    } else data = text;
    if (!res.ok && !(data && data.ok === false)) {
      throw Object.assign(new Error((data && data.message) || ('http-' + res.status)), { status: res.status, data });
    }
    lastError = null;
    return data || {};
  } finally { clearTimeout(t); }
}

/* بررسی وضعیت اتصال به سرور (یک بار در هر نشست، کش می‌شود) */
async function checkServer(force = false) {
  if (probe && !force) return probe;
  try {
    const r = await post('session.php', { action: 'get' }, { timeout: 9000 });
    probe = { available: true, auth: !!r.auth, phone: r.phone || null, gateway: r.gateway || {}, last_ref: r.last_ref || null };
  } catch (e) {
    probe = { available: false, auth: false, phone: null, gateway: {}, error: String(e && e.message || e) };
  }
  return probe;
}

/* ─── OTP ──────────────────────────────────────────────────────────────── */
async function otpSend(to) {
  const r = await post('otp_send.php', { to });
  if (r.ok !== true) throw Object.assign(new Error(r.message || 'ارسال کد ممکن نشد.'), { code: r.error, retry_in: r.retry_in });
  LS.set(K.otpSess, { to, exp: Date.now() + (r.ttl || 180) * 1000, resend: Date.now() + (r.resend || 60) * 1000 });
  return r;
}

async function otpVerify(to, code) {
  const r = await post('otp_verify.php', { to, code });
  if (r.ok !== true) throw Object.assign(new Error(r.message || 'کد تأیید نشد.'), { code: r.error });
  LS.del(K.otpSess);
  LS.set(K.session, { auth: true, phone: r.phone || to, at: Date.now() });
  return r;
}

function otpSession() {
  const s = LS.get(K.otpSess, null);
  if (!s || !s.to) return null;
  const now = Date.now();
  return {
    to: s.to,
    expiresIn: Math.max(0, Math.ceil(((s.exp || 0) - now) / 1000)),
    waitLeft: Math.max(0, Math.ceil(((s.resend || 0) - now) / 1000)),
  };
}

async function sessionGet(force = false) {
  const p = await checkServer(force);
  if (p.available) LS.set(K.session, { auth: p.auth, phone: p.phone, at: Date.now() });
  else {
    const c = LS.get(K.session, null);
    return { available: false, auth: !!(c && c.auth), phone: (c && c.phone) || null, gateway: {} };
  }
  return p;
}

async function logout() {
  try { await post('session.php', { action: 'logout' }); } catch (_) {}
  LS.del(K.session); LS.del(K.otpSess);
  probe = { available: true, auth: false, phone: null, gateway: probe ? probe.gateway : {} };
}

/* ─── پرداخت زرین‌پال ──────────────────────────────────────────────────── */
async function paymentStart(payload) {
  const r = await post('payment_request.php', payload, { timeout: 30000 });
  if (r.ok !== true) throw Object.assign(new Error(r.message || 'درگاه پرداخت پاسخ نداد.'), { code: r.error });
  return r;   // { url, authority, ref, total }
}

function paymentGo(url) {
  /* پرش کامل به درگاه — دیگر هیچ کاری در صفحه انجام نمی‌شود */
  try { window.location.assign(url); return true; }
  catch (_) { window.top.location.href = url; return true; }
}

/* خواندن نتیجهٔ بازگشت از درگاه: #/checkout?status=success&ref=… */
function paymentReturn() {
  const h = location.hash || '';
  const qi = h.indexOf('?');
  if (qi < 0 || h.slice(0, qi) !== '#/checkout') return null;
  const q = new URLSearchParams(h.slice(qi + 1));
  const st = q.get('status');
  if (st !== 'success' && st !== 'failed') return null;
  return { status: st, ref: q.get('ref') || '', amount: +(q.get('amount') || 0), message: q.get('message') || '' };
}

/* ─── سفارش‌ها / تماس ──────────────────────────────────────────────────── */
async function ordersList() {
  const r = await post('orders.php', { action: 'list' });
  if (r.ok !== true) throw Object.assign(new Error(r.message || 'سفارش‌ها در دسترس نیستند.'), { code: r.error });
  return r.orders || [];
}
async function orderCreate(payload) {
  const r = await post('orders.php', Object.assign({ action: 'create' }, payload));
  if (r.ok !== true) throw Object.assign(new Error(r.message || 'ثبت سفارش ممکن نشد.'), { code: r.error });
  return r;
}
async function contactSend(payload) {
  const r = await post('contact.php', payload);
  if (r.ok !== true) throw Object.assign(new Error(r.message || 'ارسال پیام ممکن نشد.'), { code: r.error });
  return r;
}

window.aeApi = {
  BASE,
  get available() { return !!(probe && probe.available); },
  get lastError() { return lastError; },
  checkServer, sessionGet, logout,
  otpSend, otpVerify, otpSession,
  paymentStart, paymentGo, paymentReturn,
  ordersList, orderCreate, contactSend,
};
})();
