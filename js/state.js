/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — state.js
   State · Persistence · Cart math · Smart score
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const { LS, K, clamp, PHI, PHI2 } = window.AE;
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
  fam:'all', sort:'featured', q:'', priceMax:30000000,
  size:null, color:null, colorHex:'', pdpId:null, lastFocus:null,
  promo:  PROMO.valid(savedPromo) ? savedPromo : null,
  cmp:    [],
  recent: []
};
if (!PROMO.valid(state.promo)) { state.promo = null; LS.set(K.promo, null); }

/* Sanitize persisted cart */
state.cart = state.cart
  .filter(i => i && CATALOG[i.id] && typeof i.size === 'string')
  .map(i => ({ ...i, qty: clamp(parseInt(i.qty, 10) || 1, 1, 20) }));
state.wish = [...new Set(state.wish)];

const persBag  = () => LS.set(K.bag, state.cart);
const persWish = () => LS.set(K.wish, state.wish);
const getCustom = () => LS.get(K.custom, null);

/* ═══ Cart math ═══ */
const itemsSum = () => state.cart.reduce((s, it) => {
  const p = CATALOG[it.id];
  return s + ((p ? p.price : 0) || 0) * it.qty;
}, 0);
const discount  = () => state.promo ? Math.round(itemsSum() * state.promo.pct / 100) : 0;
const cartSum   = () => Math.max(0, itemsSum() - discount());

const stockOf = id => { const p = CATALOG[id]; return p ? Math.max(0, p.stock | 0) : 0; };
const capFor  = id => {
  const s = stockOf(id);
  if (s <= 0) return 0;
  return Math.min(MAX_PER_LINE, s);
};

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
  stockOf, capFor, smartScore
};
})();