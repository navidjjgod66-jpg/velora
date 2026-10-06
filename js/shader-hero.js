/* ═══════════════════════════════════════════════════════════════════════
   VELORA AURELLE · shader-hero.js — پسزمینهٔ WebGL2 هیرو (ویژگی ۱)
   ─────────────────────────────────────────────────────────────────────────
   · شیدر fragment: FBM noise + curl noise + iridescent veins
   · واکنش به mouse (specular lighting) و scroll velocity
   · رنگ از CSS variables (--accent / --accent-2) خوانده می‌شود؛
     با رویداد ae:theme-change (که ui.js منتشر می‌کند) بازخوانی می‌شود.
   · Fallback به گرادیان ساده اگر WebGL2 نبود (.hero-shader-fallback).
   · Quality-tier aware: فقط روی .perf-high اجرا می‌شود (CSS هم پنهانش
     می‌کند تا در mid/low حتی یک فریم رندر نشود).
   · توقف کامل در document.hidden.
   · DPR از window.AE_QUALITY؛ هر بار که Governor DPR را کم کند، بومِ
     canvas کوچک‌تر و رندر ارزان‌تر می‌شود.
   · هیچ inline handler؛ همه listener ها با AbortController مشترک نسل خود.
   مکان درج: اولین فرزند .hero — یعنی پیش از .hero-grid-bg (z-index:0).
   ═══════════════════════════════════════════════════════════════════════ */
(() => {
'use strict';
const AE = window.AE; if (!AE) return;
const { $, reduced } = AE;

/* فقط perf-high — تصمیم pre-paint/index.php مرجع است. */
if (!document.documentElement.classList.contains('perf-high')) return;
if (reduced) return;

const hero = $('.hero');
if (!hero) return;

const canvas = document.createElement('canvas');
canvas.className = 'hero-shader';
canvas.setAttribute('aria-hidden', 'true');
hero.insertBefore(canvas, hero.firstChild);

const gl = canvas.getContext('webgl2', { alpha: true, antialias: false, powerPreference: 'low-power' });
if (!gl) {
  canvas.remove();
  hero.classList.add('hero-shader-fallback');
  return;
}

/* ── Shaders ── */
const VS = `#version 300 es
in vec2 aP; out vec2 vUv;
void main(){ vUv = aP*.5+.5; gl_Position = vec4(aP,0.,1.); }`;

const FS = `#version 300 es
precision highp float;
in vec2 vUv; out vec4 o;
uniform float uT; uniform vec2 uR, uM; uniform float uSV;
uniform vec3 uC1, uC2;
float h(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}
float n(vec2 p){vec2 i=floor(p),f=fract(p);f=f*f*(3.-2.*f);
 return mix(mix(h(i),h(i+vec2(1,0)),f.x),mix(h(i+vec2(0,1)),h(i+vec2(1,1)),f.x),f.y);}
float fbm(vec2 p){float a=.5,s=0.;for(int i=0;i<5;i++){s+=a*n(p);p*=2.03;a*=.5;}return s;}
vec2 curl(vec2 p){float e=.02;
 float n1=fbm(p+vec2(0,e)),n2=fbm(p-vec2(0,e));
 float n3=fbm(p+vec2(e,0)),n4=fbm(p-vec2(e,0));
 return vec2(n1-n2,n4-n3)/(2.*e);}
void main(){
 vec2 uv=vUv; uv.x*=uR.x/max(uR.y,1.);
 vec2 q=curl(uv*2.4+uT*.05);
 float f=fbm(uv*3.+q*1.2+uT*.08);
 float vein=smoothstep(.62,.9,f+length(q)*.12);
 vec3 col=mix(uC1*.06,uC2*vein*(.5+.5*sin(uT*.3+f*6.283)),vein);
 vec2 md=uv-uM; float d=length(md);
 col+=uC2*exp(-d*d*18.)*(.35+uSV);      /* specular موش + انرژی اسکرول */
 col*=1.-.35*length(uv-.5);             /* vignette */
 o=vec4(col,vein*.55+.06);
}`;

function sh(type, src) {
  const s = gl.createShader(type);
  gl.shaderSource(s, src); gl.compileShader(s);
  if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) { gl.deleteShader(s); throw new Error('shader'); }
  return s;
}

let prog;
try {
  prog = gl.createProgram();
  gl.attachShader(prog, sh(gl.VERTEX_SHADER, VS));
  gl.attachShader(prog, sh(gl.FRAGMENT_SHADER, FS));
  gl.linkProgram(prog);
  if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) throw new Error('link');
} catch (_) {
  canvas.remove();
  hero.classList.add('hero-shader-fallback');
  return;
}

gl.useProgram(prog);
const buf = gl.createBuffer();
gl.bindBuffer(gl.ARRAY_BUFFER, buf);
gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1, 3,-1, -1,3]), gl.STATIC_DRAW);
const loc = gl.getAttribLocation(prog, 'aP');
gl.enableVertexAttribArray(loc);
gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);

const U = {
  t:  gl.getUniformLocation(prog, 'uT'),
  r:  gl.getUniformLocation(prog, 'uR'),
  m:  gl.getUniformLocation(prog, 'uM'),
  sv: gl.getUniformLocation(prog, 'uSV'),
  c1: gl.getUniformLocation(prog, 'uC1'),
  c2: gl.getUniformLocation(prog, 'uC2')
};

/* ── رنگ از CSS variables — محاسبه با المان موقت (color-mix-safe) ── */
let c1 = [0.83, 0.69, 0.22], c2 = [0.55, 0.80, 0.65];
function parseColor(cssColor) {
  const d = document.createElement('div');
  d.style.cssText = 'position:absolute;visibility:hidden;color:' + cssColor;
  document.body.appendChild(d);
  const m = getComputedStyle(d).color.match(/[\d.]+/g) || [212, 175, 55];
  d.remove();
  return m.slice(0, 3).map(v => Math.min(1, +v / 255));
}
function readColors() {
  try {
    const cs = getComputedStyle(document.documentElement);
    const a = (cs.getPropertyValue('--accent') || '').trim();
    const b = (cs.getPropertyValue('--accent-2') || '').trim();
    if (a) c1 = parseColor(a);
    if (b) c2 = parseColor(b);
  } catch (_) {}
}
readColors();

/* ── Listenerها — تک AbortController برای این نسل ماژول ── */
const ac = new AbortController();
const sig = { signal: ac.signal };

addEventListener('ae:theme-change', readColors, sig);

const mx = { x: 0.5, y: 0.5 };
hero.addEventListener('pointermove', e => {
  const r = hero.getBoundingClientRect();
  if (!r.width || !r.height) return;
  mx.x = (e.clientX - r.left) / r.width;
  mx.y = 1 - (e.clientY - r.top) / r.height;
}, { passive: true, signal: ac.signal });

/* scroll velocity → انرژی specular */
let sv = 0, lastY = window.scrollY;
addEventListener('scroll', () => {
  sv = Math.min(1, sv + Math.abs(window.scrollY - lastY) / 900);
  lastY = window.scrollY;
}, { passive: true, signal: ac.signal });

/* ── اندازه با DPR حاکم کیفیت ── */
function resize() {
  const q = (window.AE_QUALITY && window.AE_QUALITY.dpr) || Math.min(devicePixelRatio || 1, 2);
  const w = Math.round(hero.clientWidth * q), hh = Math.round(hero.clientHeight * q);
  if (w < 2 || hh < 2) return;
  if (canvas.width !== w || canvas.height !== hh) {
    canvas.width = w; canvas.height = hh;
    gl.viewport(0, 0, w, hh);
  }
}
try { new ResizeObserver(resize).observe(hero); } catch (_) { addEventListener('resize', resize, sig); }
resize();

/* ── حلقهٔ رندر — متوقف در تب پنهان ── */
let raf = null; const t0 = performance.now();
function draw(t) {
  sv *= 0.94;
  gl.uniform1f(U.t, (t - t0) / 1000);
  gl.uniform2f(U.r, canvas.width, canvas.height);
  gl.uniform2f(U.m, mx.x, mx.y);
  gl.uniform1f(U.sv, sv);
  gl.uniform3fv(U.c1, c1);
  gl.uniform3fv(U.c2, c2);
  gl.clearColor(0, 0, 0, 0);
  gl.clear(gl.COLOR_BUFFER_BIT);
  gl.drawArrays(gl.TRIANGLES, 0, 3);
  raf = requestAnimationFrame(draw);
}
function start() { if (!raf && !document.hidden) raf = requestAnimationFrame(draw); }
function stop()  { if (raf) { cancelAnimationFrame(raf); raf = null; } }
document.addEventListener('visibilitychange', () => document.hidden ? stop() : start(), sig);

/* Governor: افت FPS → DPR کمتر → بوم کوچک‌تر → رندر ارزان‌تر */
if (window.AE_QUALITY) window.AE_QUALITY.onChange(() => resize());

start();

/* اگر بعداً class از perf-high خارج شد (تشدید Governor)، ماژول خاموش شود. */
new MutationObserver(() => {
  if (!document.documentElement.classList.contains('perf-high')) {
    stop(); ac.abort(); canvas.remove();
  }
}).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
})();
