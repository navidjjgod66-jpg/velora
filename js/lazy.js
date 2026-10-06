/* ═══════════════════════════════════════════════════════════════════════════
   THE DIALOG LAZY LOADER
   ═══════════════════════════════════════════════════════════════════════════
   Nine of the twenty scripts are dialog modules: they exist to populate and
   drive one overlay that is closed when the page arrives. Loading them with the
   first paint costs 54 KB of gzip that the customer cannot see, cannot use,
   and — on a connection where the interactive time is what they are judging —
   is waiting behind everything they can.

   The rule this module implements: a dialog's code is fetched the first time
   something asks for the dialog, and awaited before that dialog is opened.

   FOUR THINGS THIS DOES NOT DO, each of which would be a regression:

   · IT DOES NOT SWAP IN A STUB. `window.AE_PDP` stays undefined until the real
     module has evaluated. A stub that answers `openPDP()` with nothing is a
     button that does nothing, which is the fault this whole refactor exists to
     remove — and main.js already guards two of its own call sites for exactly
     that shape. An absent namespace is honest; a lying one is not.

   · IT DOES NOT RACE. Every call site goes through `when(...)`, which returns
     the same promise for concurrent callers, so three quick taps on "bag" issue
     one fetch rather than three. `script` elements are idempotent in most
     engines and race in others, and a module that evaluates twice registers
     its listeners twice — which is how a dialog ends up opening two.

   · IT DOES NOT RUN BEFORE THE DOCUMENT IS READY. Modules here read the DOM at
     evaluation time. A fetch resolving before DOMContentLoaded would execute
     them against a half-built page, and the failure would surface as a dialog
     with no close button.

   · IT DOES NOT PREFETCH ON IDLE for the ones that are a click away. It does,
     once, at requestIdleCallback — which is the difference between using the
     network the browser is already sitting on and competing with the thing the
     customer is reading.

   Why these nine and not the others: core, data, bridge, state, ui, cart,
   renderers and main are needed for the first paint or for the first
   interaction. quality-gov and shader-hero must be early — the governor's DPR
   decision is read by the hero. intel is cheap and improves the first render.
   That leaves exactly the modules whose only entry point is an overlay. */

(function () {
  'use strict';

  /* One map from index.php: path → filemtime. It is one map rather than a list
     plus a versions table because the loader needs both together to build a
     URL, and two parallel structures is how a path ends up in one and not the
     other. index.php is the only place that knows the version rule, so the
     value is already the number the loader has to append. */
  var MAP = window.VELORA_LAZY_MAP || {};

  var MODULES = {
    AE_PDP:     'js/pdp-aurelle.js',
    AE_LBX:     'js/lbxaurelle.js',
    AE_CKO:     'js/checkout.js',
    AE_AUTH:    'js/auth.js',
    AE_CONC:    'js/concierge-aurelle.js',
    AE_ATELIER: 'js/atelier-aurelle.js',
  };

  var started = null;
  var domReady = false;
  var waiting = [];

  function ready() {
    if (domReady) return Promise.resolve();
    return new Promise(function (res) {
      function go() { domReady = true; res(); }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go, { once: true });
      else go();
    });
  }

  function inject(src) {
    /* The cache-busting query, from the same source as every other asset. An
       unversioned lazy request is the one URL on the page the service worker has
       no way to revalidate, so a fixed module stays fixed for a year. */
    var v = MAP[src];
    var url = v ? src + '?v=' + v : src;
    var s = document.createElement('script');
    s.src = url;
    s.async = false;          // order within a batch must hold
    s.crossOrigin = 'anonymous';
    return new Promise(function (res, rej) {
      s.onload = function () { res(); };
      s.onerror = function () { rej(new Error('module failed: ' + url)); };
      document.head.appendChild(s);
    });
  }

  /* One fetch per file, ever. The promise is memoised on the map itself, so a
     second caller awaiting the same namespace gets the same in-flight promise
     and not a second <script>. */
  var inflight = {};

  function when(ns) {
    if (window[ns]) return Promise.resolve(window[ns]);
    var src = MODULES[ns];
    if (!src) return Promise.reject(new Error('no lazy module for ' + ns));

    if (!inflight[src]) {
      inflight[src] = ready()
        .then(function () { return inject(src); })
        /* The cache is cleared on failure so a later attempt can retry. Leaving
           a rejected promise in the map would make one transient network error
           permanent for the rest of the session. */
        .catch(function (err) { delete inflight[src]; throw err; });
    }
    return inflight[src].then(function () { return window[ns]; });
  }

  /* Fetch a batch, ignoring order. Used for the idle prefetch, where two dialogs
     are worth warming together and neither is waiting on the other. */
  function warm(list) {
    return Promise.all(list.map(function (ns) {
      return when(ns).catch(function () { return null; });
    }));
  }

  window.AE_LAZY = {
    when: when,
    warm: warm,
    has: function (ns) { return !!window[ns]; },
    /* Exposed for the four modules that attach behaviour with no namespace of
       their own. Nothing calls them before the customer does, and nothing on
       the first paint does either. */
    whenScript: function (src) {
      if (!inflight[src]) {
        inflight[src] = ready().then(function () { return inject(src); })
          .catch(function (err) { delete inflight[src]; throw err; });
      }
      return inflight[src];
    },
  };

  /* ── The idle prefetch ───────────────────────────────────────────────────
     One batch, at the browser's own idle moment, at low priority. The three
     named are the dialogs a customer opens by choice — the bag, the wishlist
     and their account — so they are the three most likely to be asked for. The
     PDP is deliberately NOT here: it is behind a click on a product card, which
     costs a page's worth of scrolling to reach, and its bytes are better spent
     on the first paint.

     requestIdleCallback with a timeout, because Safari has shipped it for years
     but it is still absent in enough places that its absence should degrade to
     a timeout rather than to nothing. */
  function prefetch() { warm(['AE_CKO', 'AE_AUTH', 'AE_LBX']); }

  if (typeof requestIdleCallback === 'function') {
    requestIdleCallback(prefetch, { timeout: 2500 });
  } else {
    setTimeout(prefetch, 2500);
  }

  /* The same batch, on a fast connection, as soon as the document is usable.
     requestIdleCallback above covers the desktop case where the main thread is
     busy; this covers the case where it is not and the idle callback fires
     after the customer has already clicked. */
  addEventListener('load', function () {
    if (navigator.connection && /4g|3g/.test(navigator.connection.effectiveType || '')) prefetch();
  }, { once: true });

  /* ── Wiring: any element can ask for a namespace ─────────────────────────
     `data-need="AE_CKO"` on a button, and the delegated click handler already in
     main.js asks AE_LAZY before routing. A button that cannot work yet must not
     look ready, so the attribute also marks it busy for the duration — the
     spinner is the honest state, not a dead button.

     The listener is on the document, so it survives every re-render. Binding per
     button is the fault this file exists partly to avoid: the facet list and the
     card grid are both rebuilt at runtime, and a per-button binding leaves any
     button added later permanently inert. */
  var pending = new WeakSet();

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-need]');
    if (!t || pending.has(t)) return;
    var ns = t.getAttribute('data-need');
    if (window[ns]) return;

    e.preventDefault();
    e.stopPropagation();
    pending.add(t);
    var label = t.getAttribute('aria-label') || t.textContent.trim();
    t.setAttribute('aria-busy', 'true');
    /* aria-disabled rather than disabled: a disabled button leaves the tab
       order, so a customer tabbing to it mid-fetch finds the next control and
       forgets about it. aria-disabled keeps it focusable and says the same
       thing. */
    t.setAttribute('aria-disabled', 'true');

    when(ns).then(function () {
      /* Re-dispatch, so the real handler runs with the module now present.
         Re-dispatching rather than calling a function directly is what keeps one
         code path: the action lives in main.js either way, and a call site that
         invokes a dialog function directly would be a second path to drift. */
      t.removeAttribute('aria-busy');
      t.removeAttribute('aria-disabled');
      t.click();
    }).catch(function () {
      t.removeAttribute('aria-busy');
      t.removeAttribute('aria-disabled');
      if (window.AE && AE.toast) AE.toast('این بخش بارگذاری نشد — اتصال را بررسی کنید.', 'err');
    });
  }, true);

  /* Keyboard activation on a non-button element does not produce a click, so the
     same path is opened for Enter and Space on anything with the attribute. A
     trigger that is a real <button> does not need this — the browser already
     fires a click — and double-firing is prevented by the `pending` set. */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var t = e.target.closest && e.target.closest('[data-need]');
    if (!t || t.tagName === 'BUTTON' || t.tagName === 'A') return;
    if (window[t.getAttribute('data-need')]) return;
    e.preventDefault();
    t.click();
  }, true);
})();
