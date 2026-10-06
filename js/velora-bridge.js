/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · velora-bridge.js
   پل بین JS مرورگر MAISON (window.aeApi) و بک‌اند api.php VELORA
   ─────────────────────────────────────────────────────────────────────
   این فایل نقش window.aeApi را ایفا می‌کند. تمام متدهای MAISON که
   قبلاً به otp_send.php / payment_request.php / orders.php در سرور
   flat-file حرف می‌زدند، اکنون به api.php واحد VELORA می‌روند.

   هیچ تغییری در cart.js, pdp.js, checkout.js, auth.js, admin.js
   لازم نیست. فقط این فایل را قبل از آن‌ها بار کنید.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* index.php publishes VELORA_APP_URL, which is what makes a sub-directory
     install work. Before, 'api.php' was a bare relative literal — which
     happens to work at the document root and nowhere else. */
  const BASE = (() => {
    const u = String(window.VELORA_APP_URL || '');
    if (!u) return location.pathname.replace(/[^/]*$/, '');
    try {
      const p = new URL(u);
      return p.pathname.replace(/[^/]*$/, '');
    } catch (_) { return location.pathname.replace(/[^/]*$/, ''); }
  })();
  const API = BASE + 'api.php';

  /* The CSRF token is in a <meta> that never changes for the life of the
     document. Reading it with querySelector on every single API call meant a
     full selector match per request for a value that is constant. Resolved
     once, lazily, and re-read only if it was somehow absent. */
  let csrfToken = null;
  const CSRF_META = () => {
    const m = document.querySelector('meta[name="csrf-token"]');
    if (m && m.content) csrfToken = m.content;
    return csrfToken;
  };

  /* ─── Core request ─────────────────────────────────────────────────── */
  async function call(action, payload = {}, { timeout = 25000, raw = false } = {}) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeout);

    const body = new FormData();
    body.append('action', action);
    for (const [k, v] of Object.entries(payload)) {
      if (v === null || v === undefined) continue;
      if (typeof v === 'object' && !(v instanceof File) && !(v instanceof Blob)) {
        body.append(k, JSON.stringify(v));
      } else {
        body.append(k, v);
      }
    }

    const csrf = CSRF_META() || '';
    if (csrf) body.append('csrf', csrf);

    try {
      const res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrf,
          'Accept': 'application/json',
        },
        body,
        signal: ctrl.signal,
      });

      if (raw) return res;

      let data = null;
      try { data = await res.json(); } catch {}

      if (!data || typeof data !== 'object') {
        throw Object.assign(new Error('server-unreachable'), { status: res.status });
      }

      /* CSRF می‌تواند منقضی شود؛ سرور می‌گوید و ما یک‌بار صفحه را تازه می‌کنیم */
      if (res.status === 403 && (data.error === 'CSRF_INVALID' || data.error === 'CSRF_ORIGIN_INVALID')) {
        const ae = document.activeElement;
        const typing = ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName) && (ae.value || '').length > 0;
        if (!typing && !window.__aeReloadQueued) {
          window.__aeReloadQueued = true;
          setTimeout(() => location.reload(), 300);
        }
        throw Object.assign(new Error('csrf-expired'), { code: data.error, reload: true });
      }

      return data;
    } finally {
      clearTimeout(t);
    }
  }

  /* ─── API surface (MAISON compatible) ─────────────────────────────── */

  /* session */
  async function sessionGet(force = false) {
    const r = await call('me');
    return {
      available: true,
      auth: !!r.logged,
      phone: r.user?.phone || null,
      user: r.user || null,
    };
  }
  async function logout() {
    return call('logout');
  }

  /* OTP */
  async function otpSend(to) {
    return call('send_otp', { phone: to });
  }
  async function otpVerify(to, code, intent) {
    const payload = { phone: to, code };
    if (intent) payload.intent = intent;
    return call('verify_otp', payload);
  }

  /* Catalog — از products.json روی سرور خوانده می‌شود.
     The answer is cached against the ETag the caller last saw, so a freshness
     question that comes back "unchanged" costs no parsing at all on the second
     and subsequent calls within the same page view.

     Three fixes here, all to do with this one function being wrong in a way
     that only showed up as wasted bandwidth:

     1. ETag QUOTING. catalog.php sends `ETag: "<16 hex>"` — a quoted
        strong validator, which is the correct form for a strong ETag. This
        function seeded catalogEtag from window.VELORA_CATALOG_VERSION, which
        is the BARE hash, and sent that as If-None-Match. A bare hash can never
        equal a quoted ETag, so the very first poll of every page view missed,
        downloaded the entire catalogue and JSON.parse'd it — defeating the
        entire point. Only from the second poll onward, once catalogEtag held
        the quoted form the server had actually sent, did 304s work.
        Fixed by quoting on the way in, and by trusting the server's own ETag
        header whenever it sends one.

     2. NO IN-FLIGHT DEDUPE. main.js fired AE_SYNC.run(false) during parse,
        sync-aurelle.js fired run(true) on `load`, and it fired again on every
        `visibilitychange`. Three of them could be in flight at once against one
        shared module variable, and applyServerCatalog() could run two or three
        times. There is now one promise, and concurrent callers get it.

     3. HARD-CODED PATHS. 'api.php' and 'catalog.php' were string literals, so
        a sub-directory install broke. index.php already publishes
        VELORA_CATALOG_URL; the API path is derived from it. */
  const CATALOG_URL = window.VELORA_CATALOG_URL ||
    ((location.pathname.replace(/[^/]*$/, '') || '') + 'catalog.php');
  let catalogEtag = window.VELORA_CATALOG_VERSION ? '"' + window.VELORA_CATALOG_VERSION + '"' : '';
  let catalogInflight = null;
  function catalogFetch(force = false) {
    if (catalogInflight) return catalogInflight;
    catalogInflight = (async () => {
      const etag = force ? '' : catalogEtag;
      const headers = { 'Accept': 'application/json' };
      if (etag) headers['If-None-Match'] = etag;
      const res = await fetch(CATALOG_URL, {
        credentials: 'same-origin',
        cache: force ? 'no-store' : 'default',
        headers,
      });
      const got = res.headers.get('ETag') || '';
      if (res.status === 304) return { changed: false, etag: got || etag };
      if (!res.ok) throw new Error('catalog-http-' + res.status);
      const data = await res.json();
      /* Prefer the server's own quoted validator; fall back to quoting the bare
         version it put in the body. Never store a bare hash. */
      catalogEtag = got || (data && data.version ? '"' + data.version + '"' : catalogEtag);
      return { changed: true, data, etag: catalogEtag };
    })();
    /* Clear on settle so a later poll can go to the network again; a rejected
       fetch must not leave a permanently poisoned promise behind. */
    catalogInflight = catalogInflight.then(
      r => { catalogInflight = null; return r; },
      e => { catalogInflight = null; throw e; }
    );
    return catalogInflight;
  }

  /* ─── Checkout adapter ─────────────────────────────────────────────────
     This is the seam where two different minds meet.

     checkout.js speaks MAISON: items keyed `id`, an address of
     { line1, city, zip }. api.php speaks VELORA: items keyed `pid`, and an
     address of flat top-level fields — receiver, province, city, district,
     line, plaque, unit, postal_code — each one read straight off the request
     by req_str().

     Sent raw, every order failed three separate ways at once, each of which
     looked like a different bug:
       · `id` is not `pid`, so api.php threw INVALID_ITEM before pricing;
       · the address object was nested under `address`, and req_str('city')
         only ever looks at the top level, so province and city both read as
         empty and the pair failed GEO_INVALID;
       · district and plaque do not exist in the MAISON form at all, so even a
         correct city could not have satisfied DISTRICT_INVALID/PLAQUE_INVALID.

     So the mapping lives here, once, rather than in every caller. Each field
     is read from every name it could plausibly arrive under, so a caller that
     has already been migrated to the structured shape does not have to change
     again. Nothing is invented: `plaque` is never defaulted to a placeholder
     number, because a fabricated plaque is an address the courier cannot
     deliver to, and a server that accepts one is worse than a server that
     refuses. Missing fields stay empty and are rejected by the server's own
     validation, which is the honest outcome. */
  /* Persian and Arabic-Indic digits to ASCII.

     This cannot be /\D/g. JavaScript's \D means "not [0-9]" and nothing more,
     so a Persian digit — which the form itself displays and the customer
     therefore types — is not a digit to it and gets deleted along with the
     punctuation. The result is that a perfectly good postal code arrives at
     the server as an empty string.

     An empty postal code is the worst possible outcome precisely because it is
     not an error: velora_address_in() treats a missing postal code as optional
     and lets the order through, so the parcel ships with no code at all and
     nothing anywhere reports a problem.

     The implementation now lives once, in core.js as AE.asciiDigits. It had
     two copies here and in checkout.js plus a THIRD, broken, copy in auth.js —
     see the note on foldDigits in core.js. See also normalize_digits() in
     config.php for the same trap on the other side of the wire. */
  const toAsciiDigits = window.AE.asciiDigits;

  function flattenAddress(payload) {
    const a = (payload && payload.address) || {};
    const c = (payload && payload.contact) || {};
    const first = (keys) => {
      for (const k of keys) {
        const v = a[k];
        if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim();
      }
      return '';
    };
    return {
      receiver:    first(['receiver', 'name']) || (c.name ? String(c.name).trim() : ''),
      province:    first(['province']),
      city:        first(['city']),
      district:    first(['district', 'neighborhood']),
      line:        first(['line', 'line1', 'street', 'address']),
      /* Digits, not just strings: a plaque typed as ۱۲ must reach the server as
         12, or the stored address reads "۱۲" and no system that expects ASCII
         can match it. */
      plaque:      toAsciiDigits(first(['plaque', 'number', 'no'])),
      unit:        toAsciiDigits(first(['unit', 'floor'])),
      postal_code: toAsciiDigits(first(['postal_code', 'postalCode', 'zip'])),
      note:        first(['note']),
    };
  }

  /* api.php reads `$it['pid']`. Sending `id` is not a near miss — it is a
     different field name, and the server answers INVALID_ITEM. */
  function mapItems(items) {
    if (!Array.isArray(items)) return [];
    return items
      .filter(it => it && typeof it === 'object')
      .map(it => ({
        pid:   String(it.pid != null ? it.pid : (it.id != null ? it.id : '')),
        size:  Number(it.size != null ? it.size : (it.eu != null ? it.eu : 0)),
        color: String(it.color != null ? it.color : (it.hex != null ? it.hex : '')),
        qty:   Number(it.qty != null ? it.qty : 1),
      }))
      .filter(it => it.pid !== '');
  }

  function checkoutBody(payload) {
    const addr = flattenAddress(payload);
    const contact = (payload && payload.contact) || {};
    return Object.assign({
      items:   mapItems(payload && payload.items),
      voucher: (payload && payload.promo) || '',
      name:    String(contact.name || addr.receiver || ''),
      phone:   String(contact.phone || ''),
      note:    String((payload && payload.note) || addr.note || ''),
    }, addr);
  }

  /* Payment */
  async function paymentStart(payload) {
    /* Server recomputes totals from products.json; nothing here is trusted. */
    const r = await call('checkout', checkoutBody(payload));
    if (!r.ok) throw Object.assign(new Error(r.message || 'checkout-failed'), { code: r.error });
    return {
      ok: true,
      url: r.pay_url,
      authority: r.order_id,
      ref: r.order_id,
      total: r.total,
    };
  }
  function paymentGo(url) {
    window.location.assign(url);
    return true;
  }
  function paymentReturn() {
    const q = new URLSearchParams(location.search);
    const payment = q.get('payment');
    if (!payment) return null;
    /* Four states, not two, and the fourth is the one that used to be missing.

       `confirming` means the order is real, the stock is committed, and the
       gateway could not be reached to confirm — the customer may well have
       been charged. The old ternary folded it into `failed`, because it was
       written when api.php could only redirect to success or failed, and it
       still is not what that redirect means. Folding it in told a customer
       whose payment was in flight that it had failed, cleared nothing, and
       invited a retry that would become a second order.

       The states are matched by name rather than by a two-way split, so a
       future state is visible here instead of being silently absorbed. */
    const map = {
      success: 'success',
      failed: 'failed',
      cancelled: 'cancelled',
      confirming: 'confirming',
    };
    return {
      status: map[payment] || 'failed',
      ref: q.get('order') || '',
      amount: parseInt(q.get('amount') || '0', 10),
      message: q.get('reason') || '',
    };
  }

  /* The order's own account of itself, for an order whose payment could not be
     confirmed at the gateway.

     `payment_status` has been a live server action with no caller since it was
     written, and the state it reports is precisely the one nothing could act
     on. api.php now distinguishes "declined" from "the gateway did not answer",
     and only the second can leave an order genuinely undecided — so this is the
     call that turns a pending promise into an answer the customer can see.

     Ownership is enforced server-side against the session user, so passing an
     order id belonging to someone else returns FORBIDDEN rather than data. */
  async function paymentStatus(order_id) {
    const r = await call('payment_status', { order_id });
    if (!r.ok) throw Object.assign(new Error(r.message || 'payment-status-failed'), { code: r.error });
    return {
      isPaid: !!r.is_paid,
      paymentStatus: r.payment_status || '',
      paymentRef: r.payment_ref || '',
      orderStatus: r.order_status || '',
    };
  }

  /* Orders */
  async function ordersList() {
    const r = await call('my_orders');
    if (!r.ok) throw Object.assign(new Error(r.message || 'orders-failed'), { code: r.error });
    return r.orders || [];
  }
  /* Geo & postal */
  async function geoRegions() {
    return call('geo_regions');
  }
  async function postalLookup(postal_code) {
    return call('postal_lookup', { postal_code });
  }

  /* ─── Public API ───────────────────────────────────────────────────────
     This list is exactly what the storefront calls. It used to export sixteen
     more functions — orderCreate, contactSend, addressList/Save/Delete/
     SetDefault, reviewsList, reviewSubmit, bookAppointment, notifyRestock,
     adminPost, adminUpload, otpSession, checkServer — none of which is invoked
     anywhere in js/.

     Each is a real request builder, so the dead ones were dead code that
     still had to be read, still had to be reasoned about as a contract, and
     still broke if api.php's parameter names moved. api.php's actions remain
     reachable through _call() until a feature actually needs them.

     `sessionGet(force)` keeps its parameter because both callers pass true, but
     it is documented as ignored: there is no cache here, so every call is
     already a fresh round trip. `get available()` returning a literal true is
     likewise honest now — every caller that wanted to know whether a server was
     reachable wanted sessionGet(), which throws on failure and is caught.

     `_call` is the escape hatch: it is the one entry that makes the removed
     functions recoverable without editing this file. */
  window.aeApi = {
    /* meta */
    get BASE() { return API; },
    get CATALOG_URL() { return CATALOG_URL; },
    get available() { return true; },

    /* session — auth.js and checkout.js both call sessionGet(true) */
    sessionGet, logout,

    /* otp — auth.js */
    otpSend, otpVerify,

    /* catalog — data.js / sync-aurelle.js */
    catalogFetch,

    /* payment — checkout.js */
    paymentStart, paymentGo, paymentReturn, paymentStatus,

    /* orders — auth.js */
    ordersList,

    /* geo / postal — checkout.js */
    geoRegions, postalLookup,

    /* raw call, for anything not yet wired */
    _call: call,
  };

  /* Signal that bridge is ready — MAISON's boot code waits on this */
  window.dispatchEvent(new CustomEvent('ae:bridge-ready'));
})();