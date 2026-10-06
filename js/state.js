/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — state.js
   State · Persistence · Cart math · Smart score
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const { LS, K, clamp } = window.AE;
const { CATALOG, ORDER, PRODUCTS, PROMO, MAX_PER_LINE } = window.AE_DATA;

/* ═══ Personal profile (fit data) ═══ */
const P = Object.assign(
  { foot:null, use:null, arch:null, style:null, size:null,
    dna:{ minimal:50, classic:50, bold:50, romantic:50, casual:50 } },
  LS.get(K.dna, {})
);
const saveP = () => LS.set(K.dna, P);

/* ═══ Runtime state ═══ */
const savedPromo = LS.get(K.promo, null);
const state = {
  cart:   LS.get(K.bag, []).filter(i => CATALOG[i.id]),
  wish:   LS.get(K.wish, []).filter(id => CATALOG[id]),
  /* priceMax is null, which means "no ceiling".

     It was the literal 30000000, and that number was the catalogue's most
     expensive shoe on the day it was written. Nothing kept it true. The
     collection moved on and the constant did not, so every product priced above
     30,000,000 failed the test in main.js's apply() and was hidden — five of
     the eleven, including the four most expensive pieces the maison makes, at
     31.0M, 34.2M, 38.7M, 41.5M and 44.6M.

     Nothing in the interface reported it. There is no price control, no
     "showing 6 of 11", no empty state: the shop simply opened with the
     top half of its collection missing, and the most valuable pieces in a
     maison — the ones a customer arrived for — were the ones absent. It failed
     silently because `hidden` is silent, and it survived because the number
     was plausible: nobody reading 30000000 in a file of Toman prices suspects
     it is a stale snapshot of a business decision.

     null is the honest value for a filter nobody can change. A ceiling that no
     control moves must not be capable of hiding anything, and `!state.priceMax`
     short-circuits the comparison entirely, so there is no longer a number here
     that can disagree with the catalogue. If a real price filter is ever built,
     it sets this from a control and the test below already does the right thing. */
  /* The shop's filter state. One owner (main.js's toolbar), one consumer
     (apply()), and every field defaults to "no constraint" so a control that
     is never touched can hide nothing — the lesson of priceMax above: a
     filter default that excludes anything is a bug the interface cannot see.

       · cats    Set of category slugs ('heel','boot',…). Empty = all.
                 Multi-select, ANDed with the other facets.
       · heelMin/heelMax  Millimetre band, null = open end. Derived from the
                 live catalogue in main.js, never hard-coded.
       · colors  Set of colour keys. Empty = all.
       · sizes   Set of EU size strings. Empty = all. A product passes only if
                 it has stock in at least one selected size — the same rule
                 api.php enforces at order time, so a card that survives the
                 filter can always be bought in that size.
       · priceMin  null = no floor; otherwise keep price >= priceMin.
       · priceMax  null = no ceiling; otherwise keep price <= priceMax.
       · instock Only pieces ready in the atelier right now (stock > 0).
       · deals   Only marked-down pieces (a real oldPrice above price).
       · isNew   Only this season's reveals. */
  fam:'all', sort:'featured', q:'', priceMin:null, priceMax:null,
  cats:new Set(), heelMin:null, heelMax:null,
  colors:new Set(), sizes:new Set(),
  instock:false, deals:false, isNew:false,
  size:null, color:null, colorHex:'', pdpId:null, lastFocus:null,
  promo:  PROMO.valid(savedPromo) ? savedPromo : null,
  cmp:    []
};
if (!PROMO.valid(state.promo)) { state.promo = null; LS.set(K.promo, null); }

/* The per-line cap comes from the server (window.VELORA_MAX_LINE, published by
   index.php from MAX_LINE in config.php) because api.php clamps every order
   line to exactly that number. Taking the smaller of the two means the cart
   can never offer a quantity the server will reject.

   MAX_PER_LINE is the pre-boot / no-PHP fallback. It is validated rather than
   merely read: window.VELORA_MAX_LINE is reachable by another page on a shared
   host before our bundle parses, and a cap of 0 or a negative number silently
   produces a cart that cannot be filled. Declared before the persisted-cart
   sanitiser below, which needs it. */
const serverMax = Number(window.VELORA_MAX_LINE);
const MAX_LINE_UI = Number.isFinite(serverMax) && serverMax >= 1 && serverMax <= 999
  ? Math.floor(serverMax)
  : MAX_PER_LINE;

/* Stock helpers.

   Declared above the persisted-cart sanitiser because that sanitiser clamps to
   capFor(), and capFor is a const arrow — a const is in its temporal dead zone
   until its declaration is evaluated, so reaching for it from a block above
   throws a ReferenceError rather than returning undefined. Silently falling
   back to MAX_LINE_UI there would have looked like it worked. */
const stockOf = id => { const p = CATALOG[id]; return p ? Math.max(0, p.stock | 0) : 0; };
const capFor  = id => {
  const s = stockOf(id);
  if (s <= 0) return 0;
  return Math.min(MAX_LINE_UI, s);
};

/* Sanitize persisted cart.

   Clamped to capFor(), not merely to MAX_LINE_UI: stock can have fallen since
   the cart was saved, and an order line above what is left makes api.php
   reject the ENTIRE order with INSUFFICIENT_STOCK. One stale line then costs
   the customer every other line in their basket, and the only remedy the UI
   offers is to remove the offending item by hand. Trimming it here is the
   difference between an order that goes through and a support ticket. */
/* Sanitize the cart against the current catalogue.

   Exported as a function rather than written once as an assignment, because it
   has to run again every time the catalogue is replaced — see below. */
function sanitiseCart() {
  state.cart = state.cart
    .filter(i => i && CATALOG[i.id] && typeof i.size === 'string')
    .map(i => ({ ...i, qty: clamp(parseInt(i.qty, 10) || 1, 1, capFor(i.id) || 1) }));
  state.wish = state.wish.filter(id => CATALOG[id]);
  return state.cart;
}

/* This ran once, at boot, and the one time it mattered most it was too late.

   sync-aurelle.js replaces CATALOG in place when the content hash changes —
   an admin retires a product or its stock falls while somebody has the shop
   open — and the boot-time sanitiser was never repeated. So the cart kept
   lines the new catalogue could not support:

   · a retired product stayed as a line, and itemsSum() prices an unknown id at
     0, so the bag showed the customer a 0-Toman row and api.php then refused
     the WHOLE order with INVALID_ITEM — one stale line costing every other
     line in the basket;
   · a product whose stock fell kept its quantity above the new cap. The
     stepper's "+" was correctly disabled, which is the tell: the UI knew the
     line was over the limit while the line itself was left over the limit.

   So the same two operations are re-run whenever the catalogue changes, from
   the listener below. It is deliberately a re-clamp and not a rebuild: the
   cart keeps its lines and its order, and only quantities are corrected to
   what the maison can actually make. Silently emptying somebody's basket
   because a stock figure moved would be worse than trimming one line. */
sanitiseCart();

const persBag  = () => LS.set(K.bag, state.cart);
const persWish = () => LS.set(K.wish, state.wish);
const getCustom = () => LS.get(K.custom, null);

/* ═══ Cart math ═══ */
const itemsSum = () => state.cart.reduce((s, it) => {
  const p = CATALOG[it.id];
  return s + ((p ? p.price : 0) || 0) * it.qty;
}, 0);

/* The discount honours the voucher's CAP.
   This used to be an uncapped `itemsSum() * pct / 100`, so on any basket above
   the cap it displayed a larger discount than the server would grant: VEL10
   stops at 5,000,000 and WELCOME10 at 3,000,000, and a two-pair order in this
   catalogue passes 18,000,000. The customer was shown the difference on the
   checkout summary and then charged the capped amount.
   The cap is published from velora_voucher_map(), so this arithmetic is now the
   arithmetic at the till rather than an optimistic guess at it. */
const discount = () => {
  const p = state.promo;
  if (!p || !p.pct) return 0;
  const raw = itemsSum() * p.pct / 100;
  const cap = Number(p.cap) || 0;
  return Math.round(cap > 0 ? Math.min(raw, cap) : raw);
};
const cartSum   = () => Math.max(0, itemsSum() - discount());

/* ═══ Smart score (personal recommendations) ═══ */
function smartScore(p) {
  let s = p.rating * 20 + Math.log(p.reviews + 1) * 6;
  if (P.use === 'daily'   && ['loafer','maryjane','lowheel'].includes(p.family)) s += 30;
  if (P.use === 'formal'  && ['highheel','loafer','maryjane'].includes(p.family)) s += 30;
  if (P.use === 'evening' && ['highheel','sandal'].includes(p.family)) s += 30;
  if (state.wish.includes(p.id)) s += 15;
  const dna = P.dna || {};
  const catBias = {
    maryjane:(dna.classic||50)/100, loafer:(dna.minimal||50)/100, highheel:(dna.bold||50)/100,
    boot:(dna.classic||50)/100, sandal:(dna.romantic||50)/100,
    ballet:(dna.minimal||50)/100, lowheel:(dna.classic||50)/100
  };
  s += (catBias[p.family] || 0) * 22;
  return s;
}

window.AE_STATE = {
  P, saveP, state,
  persBag, persWish, getCustom,
  itemsSum, discount, cartSum,
  stockOf, capFor, smartScore, sanitiseCart, MAX_LINE_UI
};
})();