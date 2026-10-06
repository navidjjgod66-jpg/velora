/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · pdp-3d-stage.js — مجسمهٔ سه‌بعدی محصول (ویژگی ۲)
   ─────────────────────────────────────────────────────────────────────────
   · Lazy-import three.js فقط هنگام باز شدن PDP (js/vendor/three.module.js
     از npm؛ میزبان محلی — CSP script-src 'self' را نمی‌شکند).
   · هندسه بر اساس p.cat: boot→TorusKnot · heel→Icosahedron · loafer/flat→
     Dodecahedron · sandal/bridal→TorusKnot باریک.
   · MeshPhysicalMaterial با metalness + clearcoat، رنگ از کاتالوگ.
   · محیط PMREM از ۴ blob نور (بدون HDR خارجی).
   · Drag-to-orbit با pointer events؛ ResizeObserver برای responsive.
   · destroyStage() هنگام close dialog؛ AbortController برای همه listenerها.
   · فقط perf-high / perf-mid؛ reduced-motion → چرخش خودکار خاموش.
   · نبودِ فایل three → skip بی‌صدا (سایت بدون این ویژگی هم کامل کار می‌کند).
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const AE = window.AE; if (!AE) return;
const { $, reduced } = AE;

if (!(document.documentElement.classList.contains('perf-high')
   || document.documentElement.classList.contains('perf-mid'))) return;

const THREE_URL = 'js/vendor/three.module.js';
let stage = null, loading = false;

async function buildStage(p) {
  if (stage || loading) return;
  const dlg = $('#pdp');
  /* اولین ستون grid، دقیقاً طبق قرارداد: .pdp__grid > div:first-child */
  const hostCell = dlg && $('.pdp__grid > div:first-child', dlg);
  const stageEl = $('#pdpStage', dlg);
  if (!hostCell || !stageEl) return;
  loading = true;

  let THREE;
  try { THREE = await import(THREE_URL); }
  catch (_) { loading = false; return; }   /* three موجود نیست → بی‌صدا skip */
  loading = false;
  if (stage || !dlg.open) return;          /* ممکن است بسته شده باشد حین load */

  const canvas = document.createElement('canvas');
  canvas.className = 'pdp-3d';
  canvas.setAttribute('aria-hidden', 'true');
  const hint = document.createElement('span');
  hint.className = 'pdp-3d-hint';
  hint.textContent = 'برای چرخاندن مجسمه بکشید';
  stageEl.insertAdjacentElement('afterend', canvas);
  canvas.insertAdjacentElement('afterend', hint);

  const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
  const govQ = (window.AE_QUALITY && window.AE_QUALITY.dpr) || Math.min(devicePixelRatio || 1, 2);
  renderer.setPixelRatio(Math.min(govQ, 1.75));

  const scene = new THREE.Scene();
  const cam = new THREE.PerspectiveCamera(38, 1, 0.1, 20);
  cam.position.z = 4.2;

  /* محیط PMREM از چهار blob نور — بازتاب طلایی روی بدنهٔ فلزی */
  const pm = new THREE.PMREMGenerator(renderer);
  const envScene = new THREE.Scene();
  [[0xffd27f, 2, 2, 0], [0x7fd4ff, -2, 1, 1], [0xffffff, 0, -2, 2], [0xe8b34a, 1, 2, -2]]
    .forEach(([c, x, y, z]) => {
      const m = new THREE.Mesh(new THREE.SphereGeometry(0.7, 16, 16),
        new THREE.MeshBasicMaterial({ color: c }));
      m.position.set(x, y, z); envScene.add(m);
    });
  const envRT = pm.fromScene(envScene, 0.04);
  scene.environment = envRT.texture;
  pm.dispose();

  const geos = {
    boot:   () => new THREE.TorusKnotGeometry(0.85, 0.30, 140, 26),
    heel:   () => new THREE.IcosahedronGeometry(1.15, 1),
    loafer: () => new THREE.DodecahedronGeometry(1.2, 0),
    flat:   () => new THREE.DodecahedronGeometry(1.2, 0),
    sandal: () => new THREE.TorusKnotGeometry(0.9, 0.18, 160, 20),
    bridal: () => new THREE.TorusKnotGeometry(0.9, 0.18, 160, 20)
  };
  const geo = (geos[p.cat] || geos.boot)();
  const hex = (p.colors && p.colors[0] && p.colors[0].hex) || '#c9a227';
  const mat = new THREE.MeshPhysicalMaterial({
    color: hex, metalness: 0.85, roughness: 0.22,
    clearcoat: 1, clearcoatRoughness: 0.18
  });
  const mesh = new THREE.Mesh(geo, mat);
  scene.add(mesh);

  /* ── drag-to-orbit — تک AbortController نسل ── */
  const ac = new AbortController();
  let dragging = false, px = 0, py = 0, vx = 0, vy = 0;
  canvas.addEventListener('pointerdown', e => {
    dragging = true; px = e.clientX; py = e.clientY;
    canvas.setPointerCapture(e.pointerId);
  }, { signal: ac.signal });
  canvas.addEventListener('pointermove', e => {
    if (!dragging) return;
    vy += (e.clientX - px) * 0.005;
    vx += (e.clientY - py) * 0.005;
    px = e.clientX; py = e.clientY;
  }, { passive: false, signal: ac.signal });
  ['pointerup', 'pointercancel'].forEach(ev =>
    canvas.addEventListener(ev, () => { dragging = false; }, { signal: ac.signal }));

  const ro = new ResizeObserver(() => {
    const r = canvas.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return;
    renderer.setSize(r.width, r.height, false);
    cam.aspect = r.width / r.height;
    cam.updateProjectionMatrix();
  });
  ro.observe(canvas);

  let raf = null;
  function loop() {
    if (!dragging) { vx *= 0.94; vy *= 0.94; }
    mesh.rotation.x += vx;
    mesh.rotation.y += vy + (reduced ? 0 : 0.002);
    renderer.render(scene, cam);
    raf = requestAnimationFrame(loop);
  }
  function start() { if (!raf && !document.hidden) raf = requestAnimationFrame(loop); }
  function stop()  { if (raf) { cancelAnimationFrame(raf); raf = null; } }
  document.addEventListener('visibilitychange', () => document.hidden ? stop() : start(), { signal: ac.signal });
  const offGov = window.AE_QUALITY && window.AE_QUALITY.onChange(q =>
    renderer.setPixelRatio(Math.min(q, 1.75)));
  start();

  stage = {
    dispose() {
      ac.abort(); ro.disconnect(); stop();
      if (offGov) offGov();
      geo.dispose(); mat.dispose(); envRT.dispose();
      renderer.dispose();
      canvas.remove(); hint.remove();
    }
  };
}

function destroyStage() {
  if (stage) { stage.dispose(); stage = null; }
}

/* pdp-aurelle.js رویداد ae:pdp-open را پس از hydrate موفق منتشر می‌کند؛
   dialog #pdp هم close رویداد بومی دارد — teardown تضمینی. */
addEventListener('ae:pdp-open', e => buildStage((e.detail && e.detail.product) || {}));
$('#pdp') && $('#pdp').addEventListener('close', destroyStage);

window.AE_STAGE3D = { destroyStage };
})();
