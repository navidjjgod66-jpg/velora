/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · concierge-aurelle.js
   چت Ota — کنسیژ ابدی، روی بک‌اند VELORA
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  const { $, esc, moneyT, toast, dlStop, dlStart, TIMERS, LS, K } = window.AE;
  const api = window.aeApi;

  let concOpen = false;
  let CATALOG_CACHE = null;

  async function loadCatalog() {
    if (CATALOG_CACHE) return CATALOG_CACHE;
    try {
      const r = await api.catalogFetch(true);
      CATALOG_CACHE = r.data?.products || [];
      return CATALOG_CACHE;
    } catch {
      return [];
    }
  }

  function toggleConc(open) {
    concOpen = open === undefined ? !concOpen : open;
    $('#conc')?.classList.toggle('on', concOpen);
    if (concOpen) {
      const body = $('#concBody');
      if (body && !body.children.length) {
        msg('bot', 'درود، من <b>اُتا</b> هستم — کنسیژ ابدی خانهٔ اُرِل. سایز، DNA سبک، هزینهٔ پوشش، ارسال، مراقبت… هر چه بخواهید می‌دانم.');
        renderChips();
      }
      $('#concInput')?.focus();
      dlStop();
    } else dlStart();
  }

  function msg(who, html) {
    const body = $('#concBody');
    if (!body) return;
    const d = document.createElement('div');
    d.className = 'cmsg ' + who;
    d.innerHTML = html;
    body.appendChild(d);
    body.scrollTop = body.scrollHeight;
  }

  function typing() {
    const body = $('#concBody');
    if (!body) return;
    const d = document.createElement('div');
    d.className = 'cmsg bot typing';
    d.id = 'cTyping';
    d.innerHTML = '<i></i><i></i><i></i>';
    body.appendChild(d);
    body.scrollTop = body.scrollHeight;
  }
  function untyping() { $('#cTyping')?.remove(); }

  function renderChips() {
    const chips = ['سایز من چنده؟','هزینه هر پوشش','بهترین برای روزمره','بهترین برای مجلسی','ارسال چند روزه؟','مراقبت چرم'];
    $('#concChips').innerHTML = chips.map(c =>
      `<button type="button" data-concq="${esc(c)}">${esc(c)}</button>`
    ).join('');
  }

  async function ask(q) {
    msg('user', esc(q));
    typing();
    const t = setTimeout(async () => {
      untyping();
      const lower = q.toLowerCase();
      const catalog = await loadCatalog();

      if (/سایز|فیت/.test(q)) {
        const c = LS.get(window.AE.K.custom, null);
        if (c) {
          msg('bot', `سایز سفارشی شما: <b style="color:var(--accent)">${window.AE.faNum(c.eu)}</b><br>طول ${window.AE.faNum(c.L)} · عرض ${window.AE.faNum(c.W)} سانتی‌متر<br><button class="linklike" data-concmz style="color:var(--accent)">اندازه‌گیری مجدد</button>`);
        } else {
          msg('bot', `هنوز سایز سفارشی ندارید.<br><button class="linklike" data-concmz style="color:var(--accent)">شروع اندازه‌گیری سه‌گام</button>`);
        }
      }
      else if (/پوشش|هزینه/.test(q)) {
        const p = catalog[0];
        if (p) {
          const cpw = Math.round(p.price / 2080);
          msg('bot', `محاسبهٔ هوشمند: ${esc(p.name)} در ۱۰ سال، فقط <b style="color:var(--accent)">${moneyT(cpw)}</b> در هر پوشش.`);
        }
      }
      else if (/ارسال|مرجوع/.test(q)) {
        msg('bot', 'ارسال اکسپرس رایگان به سراسر ایران — همیشه. ۳۰ روز فرصت مرجوعی بدون قید. ✦');
      }
      else if (/مراقبت|واکس|چرم/.test(q)) {
        msg('bot', 'پس از هر پوشش، با پارچهٔ نرم خشک کنید. واکس مخصوص هر ۲ هفته. قالب سدر در کشو. زیره‌دوزی مادام‌العمر رایگان.');
      }
      else if (/روزمره|راحت/.test(q)) {
        const rec = catalog.filter(p => ['loafer','lowheel','flat'].includes(p.cat)).slice(0, 2);
        if (rec.length) {
          msg('bot', 'پیشنهاد من برای استفادهٔ روزمره:<br>' + rec.map(p =>
            `<div class="prod-mini" data-concp="${p.id}"><span><b>${esc(p.name)}</b><small>${moneyT(p.price)}</small></span></div>`
          ).join(''));
        }
      }
      else if (/مجلسی|شیک|عروس/.test(q)) {
        const rec = catalog.filter(p => ['heel','bridal','sandal'].includes(p.cat)).slice(0, 2);
        if (rec.length) {
          msg('bot', 'برای مجالس:<br>' + rec.map(p =>
            `<div class="prod-mini" data-concp="${p.id}"><span><b>${esc(p.name)}</b><small>${moneyT(p.price)}</small></span></div>`
          ).join(''));
        }
      }
      else if (/تخفیف|کد/.test(q)) {
        msg('bot', `کد فعال: <b dir="ltr">WELCOME10</b> — ۱۰٪ تخفیف نخستین خرید. ✦`);
      }
      else {
        msg('bot', 'دربارهٔ <b>سایز</b>، <b>هزینهٔ پوشش</b>، <b>ارسال</b>، <b>مراقبت</b>، یا <b>پیشنهاد فرم</b> بپرسید.');
      }
      $('#concBody').scrollTop = $('#concBody').scrollHeight;
    }, window.AE.reduced ? 100 : 600);
  }

  function send() {
    const inp = $('#concInput');
    const v = (inp?.value || '').trim();
    if (!v) return;
    inp.value = '';
    ask(v);
  }

  /* ─── Bind ─── */
  document.addEventListener('click', e => {
    if (e.target.closest('#concFab')) { toggleConc(); return; }
    if (e.target.closest('[data-act="conc-open"]')) { toggleConc(true); return; }
    if (e.target.closest('[data-act="conc-close"]')) { toggleConc(false); return; }
    if (e.target.closest('[data-act="conc-send"]')) { send(); return; }
    const c = e.target.closest('[data-concq]');
    if (c) { ask(c.dataset.concq); return; }
    const p = e.target.closest('[data-concp]');
    if (p) {
      toggleConc(false);
      setTimeout(() => window.AE_PDP?.openPDP(p.dataset.concp), 200);
      return;
    }
    const mz = e.target.closest('[data-concmz]');
    if (mz) {
      toggleConc(false);
      document.querySelector('.js-mz')?.click();
    }
  });

  document.addEventListener('keydown', e => {
    if (e.target?.id === 'concInput' && e.key === 'Enter') {
      e.preventDefault();
      send();
    }
  });

  window.AE_CONC = { toggleConc, ask };
})();