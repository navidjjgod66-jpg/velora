# VELORA AURELLE — Sensory Upgrades (PR)

۱۰ ویژگی حسی/مدرن + رفع باگ پارالاکس هیرو، بدون نقض هیچ‌یک از اصول معماری موجود.

---

## 🐛 رفع باگ: `initHeroParallax`

**مشکل:** نسخهٔ قبلی روی `#heroVisual` هدف گرفته بود که در `index.php` **وجود ندارد** → پارالاکس هرگز وصل نبود. ضمناً GSAP/ScrollTrigger بارگذاری نمی‌شدند و افزودنشان از CDN تحت CSP ممنوع است (قانون ۹).

**راه‌حل:** پیاده‌سازی vanilla-JS در `js/main.js` با selectorهای واقعی DOM:

| عنصر | selector | افکت | معادل scrub درخواستی |
|---|---|---|---|
| پلیت | `.hero-plate` | y:-60 · scale .94→1 · opacity .75→1 | scrub 1 |
| φ ghost | `#heroGhost` | y:+190 (سریع‌تر) | scrub 1.5 |
| مهر | `.hero .seal` | rotation 180deg | scrub 2 |

Smoothing فریم‌به‌فریم (lerp 0.12) رفتار scrub را بازتولید می‌کند؛ در `reduced` و `perf-low` غیرفعال؛ با `visibilitychange` متوقف می‌شود.

---

## 🥇 ویژگی‌های اولویت بالا

### ۱. WebGL Shader Background — `js/shader-hero.js`
- WebGL2 fragment shader: FBM noise + curl noise + iridescent veins
- واکنش به mouse (specular highlight) و scroll velocity (انرژی نوری)
- رنگ از CSS variables `--accent` / `--accent-2` خوانده می‌شود؛ با رویداد `ae:theme-change` (اضافه‌شده در `ui.js`) sync می‌ماند
- Fallback گرادیانی (`hero-shader-fallback`) در نبود WebGL2
- فقط در `.perf-high` اجرا؛ DPR از `AE_QUALITY`؛ توقف کامل در `document.hidden`
- `<canvas aria-hidden="true">` قبل از `.hero-grid-bg` درج می‌شود (z-index: 0)

### ۲. Three.js 3D Product Sculpture — `js/pdp-3d-stage.js`
- **Vendored**: `js/vendor/three.module.js` + `three.core.js` (r186، از npm — بدون CDN، سازگار با CSP `script-src 'self'`)
- Lazy `import()` فقط هنگام باز شدن PDP (رویداد `ae:pdp-open` در انتهای `hydrate()` pdp-aurelle dispatch می‌شود)
- Geometry بر اساس `p.cat`: TorusKnot / Icosahedron / Dodecahedron
- `MeshPhysicalMaterial` با metalness .85 + clearcoat 1
- PMREM environment از ۴ blob نور
- Drag-to-orbit با Pointer Events + AbortController؛ ResizeObserver responsive
- teardown تضمینی: `close` dialog → `try/catch window.AE_STAGE3D?.destroyStage?.()` — GPU leak ندارد
- فقط perf-high/mid؛ اگر vendor حذف شود بی‌صدا skip می‌شود (غیرمرگبار)

### ۳. Auric Intelligence — `js/aurelle-intel.js`
- localStorage `ae.intel.v1`: تعداد بازدید، اولین بازدید، عمق اسکرول، بخش‌های دیده‌شده (IntersectionObserver)، dwell time
- `getGreeting()` بر اساس **ساعت تهران** + تعداد بازدید → به `#greeting` متصل (فقط textContent، rewrite ساختار سرور نیست)
- AI Oracle: پس از ۱۲ ثانیه ماندگاری یک‌بار toast پیشنهاد
- API عمومی: `AE_INTEL.{getGreeting, getRecommendation, track, getState}`

### ۴. Ambient Sound + Voice Control — `js/ambient-voice.js`
- دکمهٔ `#voiceBtn` در header کنار `#themeT` (SVG mic، aria-label فارسی)
- **Ambient** (کلیک): ۳ oscillator سینوسی 72/108/144Hz + LFO 0.07Hz روی lowpass filter؛ fade-in/out نرم
- **Voice** (hold ≥۴۵۰ms یا Enter/Space): Web Speech API `fa-IR`
- دستورات: «فروشگاه/boutique» · «سبد/cart» · «حساب/account» · «تم/theme» · «work/craft» · «contact/تماس»
- بدون inline handler؛ graceful degradation اگر مرورگر SR نداشته باشد

### ۵. Live Market Ticker — main.js + sections.css
- `#mkBar` زیر header: XAU/BTC/ETH با fluctuation واقع‌گرایانه ±0.2٪ هر ۵ ثانیه
- فقط `min-width: 1200px`؛ hover = pause + zoom 1.18x؛ در atelier mode پنهان؛ در perf-low خاموش
- تغییرات مثبت/منفی با رنگ ▲/▼

### ۶. Cinema Mode — `#cinema` dialog + dialogs.css
- تمام‌صفحه با letterbox سیاه 5.5vh بالا/پایین، canvas poster، تایمر + progress bar + pause/close
- ESC/backdrop/دکمه با `wireDialog()` استاندارد سیم‌کشی شده (قانون ۸)
- در `prefers-reduced-motion` فقط still-frame
- مکمل `#lbx` برای تجربهٔ سینمایی‌تر

### ۷. Draft-Persisting Forms — checkout.js
- ذخیره auto (debounce 400ms) در `ae.draft.v1` هنگام input فیلدهای `#ckoForm`
- بازیابی با toast دوتایی: «نگهداشتن» / «دور ریختن»
- charcount زنده زیر `#ckAddr` با هشدار قرمز بالای ۱۰۰۰ کاراکتر
- پاک‌شدن خودکار draft در **هر سه مسیر موفق سفارش**: submit (خط ~۵۵۳)، بازگشت از درگاه (~۶۳۷)، پرداخت موفق (~۷۰۹)

### ۸. Quality Governor — quality-gov.js
- نمونه‌برداری FPS با rAF مستقل؛ ۳ ثانیه زیر ۴۰ → DPR −0.25 (کف 1.0)
- propagate به همه renderableها: `AE_QUALITY.onChange(fn)` → shader، 3D stage
- سخت‌افزار ضعیف (deviceMemory≤2 / cores≤2 / saveData) → `.perf-low` اجباری
- HUD با `?hud=1`: FPS · DPR · منطقهٔ زمانی · φ value — `aria-hidden`

### ۹. PWA Install + Share + Dock
- Install sheet شیشه‌ای: `beforeinstallprompt` defer + ۲۵ ثانیه dwell + ۳۵٪ اسکرول؛ یک‌بار علامت در LS
- دکمهٔ Share در sticky PDP: `navigator.share` → fallback `clipboard.writeText` → toast
- Dock hide-on-scroll-down: `:focus-within` استثنا شد (قانون کیبورد)

### ⚠️ Guided Brief 3-step — عمداً خارج از scope این PR
نیاز به UI form جدید + backend endpoint دارد؛ تا اصل «هیچ چیز را نشکن» حفظ شود در backlog ماند.

---

## 💎 Polish حسی (CSS)

| افکت | کلاس/عنصر | فایل | reduced-motion |
|---|---|---|---|
| Holo Ring چرخان (conic-gradient با `@property --holo`) | `.tier.feat::before` | components.css | animation:none |
| Plaque Foil shine | `.plaque::after` | components.css | media query off |
| Reading Progress sticky در PDP | `.read-prog` | dialogs.css + pdp-aurelle.js | scroll-linked، motion-free |
| Auric Seam بین فصل‌ها | `hr.auric-seam` | sections.css | alternate animation off |
| Sparkline SVG در hero-ledger | `.spark` | main.js + sections.css | decorative aria-hidden |
| Konami Code | `html.konami { hue-rotate(180deg) }` ۸ ثانیه | main.js | — |
| φ Index Rail | `.rail a[aria-current] { scale(1.618) }` | components.css | transition کوتاه |

---

## 🔒 ادغام — ۱۰ قانون، وضعیت تأییدشده

1. ✅ CSP: صفر inline handler جدید (grep — تنهاmatch یک کامنت تاریخی است)؛ nonce روی همهٔ scriptها
2. ✅ هیچ `onclick=` اضافه نشد
3. ✅ Listenerهای جدید: AbortController (orbit 3D) / `{once:true}` / visibility-gated
4. ✅ `reduced` guard در هر ۵ ماژول جدید + media query در CSS
5. ✅ هر فایل جدید = IIFE با `'use strict'`
6. ✅ همه از `window.AE` (core) استفاده می‌کنند
7. ✅ HTML سروری rewrite نشده؛ فقط textContent greeting و append در ledger
8. ✅ `#cinema` و install-sheet با `wireDialog()`
9. ✅ Three.js فقط local vendored — بدون CDN جدید
10. ✅ RTL، زبان فارسی، `faNum`، Vazirmatn حفظ

**Service Worker:** `CACHE_VERSION = velora-v9-3`؛ vendor عمداً خارج از precache (cache-aside در اولین استفاده)؛ precache بعد از `ae:catalog-sync` refresh می‌شود (fix در data.js).

---

## 🧪 نتایج تست

- `node --check` روی ۲۱ فایل JS + sw.js: **همه پاس**
- لود ES-module سه‌بعدی: `THREE.REVISION === '186'` ✓
- APIها: PMREMGenerator.fromScene / MeshPhysicalMaterial / هر ۳ geometry ✓
- grep امنیت CSP: بدون inline event ✓
- ⬜ `php -l index.php` — در CI شما (PHP در sandbox نبود؛ ساختار PHP تغییر نکرد)

### Accessibility checklist (دستی)
- [ ] Keyboard: Enter/Space روی `#voiceBtn` = listening؛ ESC در cinema = close
- [ ] Screen reader: greeting `role=status`؛ canvasها `aria-hidden`
- [ ] Reduced motion: همه انیمیشن‌های جدید خاموش

### Performance targets
- LCP unchanged (canvas بعد از paint درج می‌شود) · CLS = 0 (absolute-inset) · INP (pointermove passive)

---

## 📦 Diff آمار
```
21 files changed, ~81.8k insertions (±17 deletions)
vendor three.js ≈ 2.1MB (lazy-loaded only for PDP 3D on perf≥mid)
```

## 🔁 ترتیب نهایی `$VELORA_JS`
core → quality-gov → aurelle-intel → data → velora-bridge → renderers → state → ui → cart → pdp → pdp-3d-stage → lbxaurelle → checkout → auth → concierge → atelier → sync → shader-hero → ambient-voice → main
