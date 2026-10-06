/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · renderers-aurelle.js
   محتوای دیالوگ‌های پوستهٔ index.php
     #sgInner  · راهنمای سایز
     #mzInner  · جادوگر اندازه‌گیری
     #profInner· پروفایل + OTP
     #ckoInner · پرداخت سه‌گام
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
'use strict';
const { $, esc, faNum } = window.AE;
const D = window.AE_DATA;

/* ═══════════════════════════════════════════════
   SIZE GUIDE
   ═══════════════════════════════════════════════ */
function renderSG() {
  const host = $('#sgInner');
  if (!host || host.dataset.ready === '1') return;
  host.dataset.ready = '1';

  const sizes = D.SIZES || ['37','38','39','40','41'];
  const conv  = D.CONV  || {};

  host.innerHTML = `
    <button class="close-btn sg__close" id="sgX" type="button" aria-label="بستن">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <span class="mono accent-txt">راهنمای سایز</span>
    <h2 class="sg-title">سایز دقیق پای خود</h2>
    <p class="sg-sub">قالب‌های خانه بر پایهٔ اندازه‌گیری طول و عرض پا ساخته می‌شوند.</p>

    <table class="sg-table">
      <thead>
        <tr>
          <th scope="col">سایز اروپایی</th>
          <th scope="col">طول پا</th>
          <th scope="col">توضیح</th>
        </tr>
      </thead>
      <tbody>
        ${sizes.map(s => `
          <tr>
            <td><b>${faNum(s)}</b></td>
            <td>${esc(conv[s] || '—')}</td>
            <td class="mono" style="font-size:.6rem;color:var(--text-4)">استاندارد خانه</td>
          </tr>`).join('')}
      </tbody>
    </table>

    <div class="tip">
      <span class="ico" aria-hidden="true">اُ</span>
      <p>اندازه‌گیری در پایان روز — که پا کمی بازتر است — دقیق‌ترین نتیجه را می‌دهد.
      اگر میان دو سایز هستید، سایز بزرگ‌تر را انتخاب کنید و کفی نیم‌سایز رایگان را درخواست دهید.</p>
    </div>

    <div style="margin-top:1.2rem;display:flex;gap:.6rem;flex-wrap:wrap">
      <button class="btn btn--gold btn--sm js-mz" type="button">اندازه‌گیری سفارشی</button>
      <button class="btn btn--ghost btn--sm js-sg-close" type="button">بستن</button>
    </div>`;

  /* A delegated close, not an inline attribute.

     This button carried onclick="this.closest('dialog').close()". The document's
     Content-Security-Policy sets `script-src-attr 'none'` — deliberately, and
     correctly, since an inline attribute is exactly the XSS sink that policy
     exists to remove. So the attribute was refused by the browser, no error was
     reported anywhere, and the one control in the size guide that says "بستن"
     did nothing. The dialog could still be dismissed by its corner ✕, by
     Escape and by the backdrop, which is exactly why it survived: every other
     exit worked.

     That is the failure mode worth naming. A CSP-blocked handler is
     indistinguishable from a handler that was never written, and this one sits
     beside a working ✕ on the same panel. An inline attribute anywhere in this
     codebase is now a bug, not a style. */
  host.querySelectorAll('.js-sg-close').forEach(b =>
    b.addEventListener('click', () => {
      ($('#sg') || b.closest('dialog'))?.close();
    }));

  host.querySelectorAll('.js-mz').forEach(b =>
    b.addEventListener('click', () => {
      $('#sg')?.close();
      document.querySelector('.js-mz:not(.js-sg)')?.click();
    }));
}

/* ═══════════════════════════════════════════════
   MEASURE WIZARD
   ═══════════════════════════════════════════════ */
function renderMZ() {
  const host = $('#mzInner');
  if (!host || host.dataset.ready === '1') return;
  host.dataset.ready = '1';

  host.innerHTML = `
    <button class="close-btn mz__x" id="mzX" type="button" aria-label="بستن">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <span class="mono accent-txt">سایز سفارشی</span>
    <h2 class="mz-title">هندسهٔ پای شما</h2>

    <ol class="mz__steps" aria-label="مراحل">
      <li class="is-on"><i>۰۱</i><span>طول پا</span></li>
      <li><i>۰۲</i><span>عرض پنجه</span></li>
      <li><i>۰۳</i><span>نتیجه</span></li>
    </ol>

    <div class="mz__prog">
      <i id="mzProg" style="display:block;height:100%;background:var(--accent);transform:scaleX(0.33);transform-origin:right;transition:transform .5s ease"></i>
    </div>

    <section class="mz__step is-on" data-mstep="0">
      <div class="mz__fig" aria-hidden="true">
        <svg viewBox="0 0 300 180" fill="none" stroke="var(--accent)" stroke-width="1.2">
          <path d="M60 150 C60 60, 140 40, 200 60 C240 72, 250 120, 240 150 Z" opacity=".7"/>
          <line x1="40" y1="160" x2="260" y2="160" stroke-dasharray="4 4"/>
          <line x1="60" y1="150" x2="60" y2="170"/>
          <line x1="240" y1="150" x2="240" y2="170"/>
          <text x="150" y="178" fill="var(--text-3)" font-size="10" text-anchor="middle">طول پا</text>
        </svg>
      </div>
      <p class="mz-desc">پای خود را روی کاغذ بگذارید و از پاشنه تا نوک بلندترین انگشت را بر حسب سانتی‌متر اندازه بگیرید.</p>
      <div class="field mz-field">
        <label for="mzLen">طول پا (سانتی‌متر)</label>
        <input id="mzLen" type="number" inputmode="decimal" step="0.1" min="20" max="30" placeholder="24.0">
      </div>
      <div class="fit-nav">
        <button class="btn btn--solid btn-block" id="mzNext1" type="button">ادامه</button>
      </div>
    </section>

    <section class="mz__step" data-mstep="1">
      <div class="mz__fig" aria-hidden="true">
        <svg viewBox="0 0 300 180" fill="none" stroke="var(--accent)" stroke-width="1.2">
          <path d="M60 150 C60 60, 140 40, 200 60 C240 72, 250 120, 240 150 Z" opacity=".7"/>
          <line x1="80" y1="90" x2="220" y2="90" stroke-dasharray="4 4"/>
          <line x1="80" y1="80" x2="80" y2="100"/>
          <line x1="220" y1="80" x2="220" y2="100"/>
          <text x="150" y="82" fill="var(--text-3)" font-size="10" text-anchor="middle">عرض پنجه</text>
        </svg>
      </div>
      <p class="mz-desc">پهن‌ترین نقطهٔ پنجه را اندازه بگیرید — معمولاً روی مفصل شست.</p>
      <div class="field mz-field">
        <label for="mzWid">عرض پنجه (سانتی‌متر)</label>
        <input id="mzWid" type="number" inputmode="decimal" step="0.1" min="6" max="13" placeholder="9.0">
      </div>
      <div class="fit-nav">
        <button class="btn btn--ghost" type="button" data-mz-back="0">قبلی</button>
        <button class="btn btn--solid" id="mzNext2" type="button">محاسبه</button>
      </div>
    </section>

    <section class="mz__step" data-mstep="2">
      <div class="mz__res">
        <span class="mono">سایز سفارشی شما</span>
        <div class="mz__eu" id="mzEU">—</div>
        <p class="mz__conv" id="mzConv">—</p>
        <p class="mz__note" id="mzNote">—</p>
      </div>
      <div class="fit-nav">
        <button class="btn btn--ghost" type="button" data-mz-back="1">قبلی</button>
        <button class="btn btn--gold" id="mzSave" type="button">ثبت و ذخیره</button>
      </div>
    </section>`;

  /* Back buttons — main.js doesn't wire these, so we dispatch a custom event
     that main.js can listen to (see patch note below). Fallback: naively click
     nothing if main.js doesn't handle it, leaving step unchanged. */
  host.querySelectorAll('[data-mz-back]').forEach(b => {
    b.addEventListener('click', () => {
      window.dispatchEvent(new CustomEvent('ae:mz-back', {
        detail: { to: +b.dataset.mzBack }
      }));
    });
  });
}

/* ═══════════════════════════════════════════════
   PROFILE
   ═══════════════════════════════════════════════ */
function renderProf() {
  const host = $('#profInner');
  if (!host || host.dataset.ready === '1') return;
  host.dataset.ready = '1';

  const sizes = D.SIZES || ['37','38','39','40','41'];

  host.innerHTML = `
    <button class="close-btn" id="profX" type="button" aria-label="بستن" style="position:absolute;top:1rem;inset-inline-end:1rem;z-index:5">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>

    <div class="prof__seal" aria-hidden="true">اُ</div>
    <h2 class="prof-title">خانهٔ اُرِل</h2>
    <p class="prof-desc">با شمارهٔ همراه خود وارد شوید. یک کد یکبارمصرف برایتان ارسال می‌شود.</p>

    <!-- AUTH -->
    <div id="profAuth">
      <div data-ps="phone">
        <div class="field prof-field">
          <label for="profPhone">شمارهٔ همراه</label>
          <input id="profPhone" type="tel" inputmode="numeric" autocomplete="tel"
                 placeholder="۰۹۱۲۳۴۵۶۷۸۹" dir="ltr" maxlength="11">
        </div>
        <button class="btn btn--gold btn--block" id="sendOtp" type="button">
          <span class="spin"></span><span>ارسال کد</span>
        </button>
      </div>

      <div data-ps="otp" hidden>
        <p class="mono" style="text-align:center;margin:1rem 0">کد ارسال‌شده به <b id="otpPhone" dir="ltr"></b></p>

        <div class="otp-row" dir="ltr">
          <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="رقم ۱">
          <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="رقم ۲">
          <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="رقم ۳">
          <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="رقم ۴">
        </div>

        <p class="field-error" id="otpErr" hidden style="text-align:center;display:block;margin-top:.6rem">کد وارد شده درست نیست.</p>

        <div class="otp-actions">
          <button class="linklike-muted" id="backPhone" type="button">تغییر شماره</button>
          <button class="linklike-accent" id="resendOtp" type="button" disabled>ارسال دوباره</button>
        </div>

        <!-- The countdown lives here rather than inside the button.

             It used to read "ارسال دوباره · ۱۲۰" on a greyed-out button, which
             is a sentence about neither thing: it is not a button you can press
             and not a number of anything, and the number's unit was missing
             entirely. A disabled control whose label changes is also read by a
             screen reader as a control that exists and cannot be used, which is
             exactly backwards for a wait that is about to end.

             So the button says what it does and does one thing, and this line
             says what is happening and when it changes. It is a live region
             because the change from "waiting" to "available" is the single most
             important thing that happens on this step, and it happens with no
             other signal. -->
        <p class="otp-hint" id="otpResendHint" role="status"></p>

        <div class="devchip" hidden style="margin-top:1rem;display:flex;justify-content:center">
          حالت آزمایشی · کد <b>۱۲۳۴</b>
        </div>

        <button class="btn btn--gold btn--block" id="verifyOtp" type="button" style="margin-top:1rem">
          <span class="spin"></span><span>تأیید</span>
        </button>
      </div>
    </div>

    <!-- DASHBOARD -->
    <div id="profDash" hidden>
      <div class="prof-head">
        <span class="prof-av" id="profAv" aria-hidden="true">اُ</span>
        <div class="prof-head-body">
          <div class="field" style="margin-bottom:0">
            <label for="profName">نام شما</label>
            <input id="profName" type="text" autocomplete="name" placeholder="نام و نام خانوادگی">
          </div>
        </div>
      </div>

      <p class="mono" style="text-align:center;margin-top:.5rem;color:var(--text-4)" dir="ltr" id="profPhoneShow"></p>

      <div class="field prof-field" style="margin-top:1rem">
        <label for="profSize">سایز اروپایی</label>
        <select id="profSize" class="select select--full">
          <option value="">—</option>
          ${sizes.map(s => `<option value="${s}">${faNum(s)}</option>`).join('')}
        </select>
      </div>

      <div class="prof__cards">
        <div class="prof-card prof-card--full">
          <h5>سایز سفارشی</h5>
          <div id="profCst"><p class="ps2">هنوز ثبت نشده — از راهنمای سایز شروع کنید.</p></div>
        </div>
        <div class="prof-card prof-card--full">
          <h5>سفارش‌های شما</h5>
          <div id="profOrders"><p class="ps2">در حال بارگذاری…</p></div>
        </div>
      </div>

      <div class="fit-nav" style="margin-top:1.5rem">
        <button class="btn btn--ghost" id="profAdmin" type="button" hidden>پنل مدیریت</button>
        <button class="btn btn--ghost" id="profOut" type="button">خروج از حساب</button>
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════
   CHECKOUT
   ═══════════════════════════════════════════════ */
function renderCko() {
  const host = $('#ckoInner');
  if (!host || host.dataset.ready === '1') return;
  host.dataset.ready = '1';

  host.innerHTML = `
    <div class="cko__head">
      <h2 class="cko-title">پرداخت</h2>
      <button class="close-btn" id="ckoX" type="button" aria-label="بستن">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>

    <ol class="cko__steps" id="ckoSteps" aria-label="مراحل">
      <li class="is-on"><i>۰۱</i><span>اطلاعات تماس</span></li>
      <li><i>۰۲</i><span>نشانی</span></li>
      <li><i>۰۳</i><span>پرداخت</span></li>
    </ol>

    <div class="cko__prog"><i id="ckoProg"></i></div>

    <form id="ckoForm" autocomplete="on" novalidate style="margin-top:1.5rem">

      <fieldset class="cko__step is-on" data-step="0">
        <div class="cko__row">
          <div class="field">
            <label for="ckName">نام و نام خانوادگی</label>
            <input id="ckName" name="name" autocomplete="name" required aria-describedby="ckNameErr">
            <p class="f-errmsg" id="ckNameErr" hidden>نام و نام خانوادگی را کامل بنویسید.</p></div>
          <div class="field">
            <label for="ckPhone">شمارهٔ همراه</label>
            <input id="ckPhone" name="phone" type="tel" autocomplete="tel" inputmode="numeric"
                   placeholder="۰۹۱۲۳۴۵۶۷۸۹" required aria-describedby="ckPhoneErr">
            <p class="f-errmsg" id="ckPhoneErr" hidden>شمارهٔ همراه باید ۱۱ رقم و با ۰۹ آغاز شود.</p></div>
        </div>
        <div class="field">
          <label for="ckMail">رایانامه</label>
          <input id="ckMail" name="email" type="email" autocomplete="email" required aria-describedby="ckMailErr">
          <p class="f-errmsg" id="ckMailErr" hidden>رایانامه معتبر نیست — نمونه: name@example.com</p></div>
        <p class="cko-note" id="ckoAuthNote" hidden></p>
      </fieldset>

      <fieldset class="cko__step" data-step="1">
        <div class="cko__row">
          <div class="field">
            <label for="ckProvince">استان</label>
            <select id="ckProvince" name="province" autocomplete="address-level1" required data-unset aria-describedby="ckProvinceErr"><option value="">انتخاب کنید…</option>
            </select>
            <p class="f-errmsg" id="ckProvinceErr" hidden>استان را از فهرست انتخاب کنید.</p>
          </div>
          <div class="field">
            <label for="ckCity">شهر</label>
            <select id="ckCity" name="city" autocomplete="address-level2" required disabled data-unset aria-describedby="ckCityErr"><option value="">ابتدا استان</option>
            </select>
            <p class="f-errmsg" id="ckCityErr" hidden>شهر را از فهرست انتخاب کنید.</p>
          </div>
        </div>
        <div class="field">
          <label for="ckDistrict">محله</label>
          <input id="ckDistrict" name="district" autocomplete="address-level3" required aria-describedby="ckDistrictErr">
          <p class="f-errmsg" id="ckDistrictErr" hidden>محله را بنویسید.</p></div>
        <div class="field">
          <label for="ckAddr">نشانی</label>
<textarea id="ckAddr" name="address" autocomplete="street-address" required
                    placeholder="خیابان، کوچه، پلاک" aria-describedby="ckAddrErr"></textarea>
          <p class="f-errmsg" id="ckAddrErr" hidden>نشانی را کامل بنویسید — خیابان، کوچه و پلاک.</p>
        </div>
        <div class="cko__row">
          <div class="field">
            <label for="ckPlaque">پلاک</label>
            <input id="ckPlaque" name="plaque" inputmode="numeric" required aria-describedby="ckPlaqueErr">
            <p class="f-errmsg" id="ckPlaqueErr" hidden>شمارهٔ پلاک را بنویسید.</p></div>
          <div class="field">
            <label for="ckUnit">واحد</label>
            <input id="ckUnit" name="unit" inputmode="numeric" aria-describedby="ckUnitErr">
            <p class="f-errmsg" id="ckUnitErr" hidden>واحد، اختیاری است.</p></div>
        </div>
        <div class="field">
          <label for="ckZip">کد پستی</label>
          <input id="ckZip" name="postal-code" autocomplete="postal-code" inputmode="numeric"
                 maxlength="10" required>
          <p class="field-error" id="ckZipMsg" hidden></p>
        </div>
      </fieldset>

      <fieldset class="cko__step" data-step="2">
        <div class="paymock" role="note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/>
          </svg>
          <b>درگاه پرداخت امن</b>
          <span>اطلاعات کارت نزد درگاه باقی می‌ماند و هرگز در سرور ما ذخیره نمی‌شود.
            <span class="mocktag">آزمایشی</span></span>
        </div>

        <div class="field">
          <label for="ckHolder">نام دارندهٔ کارت</label>
          <input id="ckHolder" autocomplete="cc-name" required aria-describedby="ckHolderErr">
          <p class="f-errmsg" id="ckHolderErr" hidden>نام دارندهٔ کارت را بنویسید.</p></div>

        <div class="field">
          <label for="ckCard">شمارهٔ کارت</label>
          <input id="ckCard" autocomplete="cc-number" inputmode="numeric" dir="ltr"
                 placeholder="•••• •••• •••• ••••" maxlength="19" required aria-describedby="ckCardErr">
          <p class="f-errmsg" id="ckCardErr" hidden>شمارهٔ کارت باید ۱۶ رقم باشد.</p></div>

        <div class="cko__row">
          <div class="field">
            <label for="ckExp">تاریخ انقضا</label>
            <input id="ckExp" autocomplete="cc-exp" dir="ltr" placeholder="MM/YY" maxlength="5" required aria-describedby="ckExpErr">
            <p class="f-errmsg" id="ckExpErr" hidden>تاریخ انقضا به شکل MM/YY — نمونه: ۰۹/۲۹</p></div>
          <div class="field">
            <label for="ckCvc">CVV2</label>
            <input id="ckCvc" autocomplete="cc-csc" inputmode="numeric" dir="ltr"
                   placeholder="•••" maxlength="4" required aria-describedby="ckCvcErr">
            <p class="f-errmsg" id="ckCvcErr" hidden>رمز کارت ۳ یا ۴ رقم است.</p></div>
        </div>
      </fieldset>

      <div class="cko__sum">
        <div class="r"><span>تعداد اقلام</span><b id="ckoCount">۰</b></div>
        <div class="r"><span>جمع جزء</span><b id="ckoSub">۰ تومان</b></div>
        <div class="r disc" id="ckoDiscRow" hidden>
          <span>تخفیف · <b class="accent-txt" id="ckoDiscCode">—</b></span>
          <b class="accent-txt" id="ckoDisc">—</b>
        </div>
        <!-- The "شامل مالیات" row is gone. The server has no VAT term at all:
             api.php computes $total = ($subtotal - $discount) + SHIPPING_FLAT and
             SHIPPING_FLAT is 0. This row rendered a figure derived by dividing
             the total by 1.2 — arithmetic that implies the price is VAT-inclusive
             and that a tax exists inside it. Neither is true, so it was a number
             on screen that no system computed and nobody could reconcile. -->
        <div class="r"><span>هزینهٔ ارسال</span><b>رایگان</b></div>
        <div class="r"><span>ارسال اکسپرس</span><b style="color:var(--verd)">رایگان</b></div>
        <div class="r total"><span>پرداخت نهایی</span><b id="ckoTotal">۰ تومان</b></div>
      </div>

      <div class="promo">
        <label class="sr-only" for="promoInput">کد تخفیف</label>
        <input id="promoInput" type="text" dir="ltr" placeholder="کد تخفیف">
        <button class="btn btn--ghost" id="promoApply" type="button">اعمال</button>
      </div>
      <p class="promo-msg" id="promoMsg"></p>

      <div class="cko__nav">
        <button class="btn btn--ghost" id="ckoBack" type="button" disabled>قبلی</button>
        <button class="btn btn--gold" id="ckoNext" type="button">
          <span class="spin"></span>
          <span id="ckoNextT">ادامه</span>
        </button>
      </div>
    </form>

    <div class="cko__done" id="ckoDone" hidden style="text-align:center;padding:2rem 0">
      <div class="sealok">✓</div>
      <h3 style="font-family:var(--serif-d);font-size:var(--t-2xl);margin-bottom:.6rem">سفارش شما ثبت شد</h3>
      <p class="cko-done-txt">شمارهٔ پیگیری:</p>
      <b class="ref" id="ckoRef">—</b>
      <p class="cko-done-txt">میزبان ظرف ۲۴ ساعت تأیید می‌کند و سفارش به صف تولید می‌رود.</p>
        <p class="cko-done-local" id="ckoLocalWarn" hidden>
          <b>هشدار:</b> سرور در دسترس نبود، پس این سفارش فقط روی همین دستگاه ثبت شده است
          و به میزبان نرسیده. لطفاً با پشتیبانی تماس بگیرید یا بعداً دوباره تلاش کنید.
        </p>
      <div class="fit-nav" style="margin-top:1.5rem;justify-content:center">
        <button class="btn btn--solid" id="ckoDoneX" type="button">بازگشت به خانه</button>
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════
   Boot — همه را یک‌بار اجرا کن
   ═══════════════════════════════════════════════ */
function renderAll() {
  try { renderSG(); }   catch (e) { console.error('[renderSG]', e); }
  try { renderMZ(); }   catch (e) { console.error('[renderMZ]', e); }
  try { renderProf(); } catch (e) { console.error('[renderProf]', e); }
  try { renderCko(); }  catch (e) { console.error('[renderCko]', e); }
}

renderAll();

window.AE_RENDERERS = { renderAll, renderSG, renderMZ, renderProf, renderCko };
})();