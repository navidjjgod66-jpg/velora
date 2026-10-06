<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ── Escaping ──────────────────────────────────────────────────────────────
   esc() is esc() — it lives in includes/http.php, which config.php requires
   before this file can run at all. The fallback below was a second HTML
   escaping recipe with different flags (ENT_SUBSTITUTE without ENT_HTML5),
   which is precisely the drift one function exists to prevent, and it could
   only ever have been reached by a config.php that failed to load — a case
   that has already exited by the time this line runs. */
$h = static fn(string $s): string => esc($s);

/* Read a constant with a fallback. Every constant this file reads is defined
   by config.php before this line executes; the $const indirection exists only
   for the SIZE_MIN/SIZE_MAX pair, which has a documented default worth
   spelling next to its use. */
$const = static fn(string $name, $fallback) => defined($name) ? constant($name) : $fallback;

try { $nonce = base64_encode(random_bytes(16)); }
catch (\Exception $e) { $nonce = base64_encode(openssl_random_pseudo_bytes(16)); }

$nonceAttr = $h($nonce);

$clientIp = (string) get_client_ip();

if (!admin_ip_allowed($clientIp)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    echo "403 — دسترسی از این آی‌پی مجاز نیست\n";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
header('Cross-Origin-Resource-Policy: same-site');
header('Cross-Origin-Opener-Policy: same-origin');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
$csp = "default-src 'self'; "
     . "script-src 'self' 'nonce-$nonce'; "
     . "script-src-attr 'none'; "
     . "style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; "
     . "style-src-attr 'none'; "
     . "font-src 'self' https://fonts.gstatic.com; "
     . "img-src 'self' data: blob: https:; "
     . "connect-src 'self'; "
     . "frame-src 'none'; "
     . "object-src 'none'; "
     . "worker-src 'self'; "
     . "frame-ancestors 'none'; "
     . "base-uri 'self'; "
     . "form-action 'self';";
header('Content-Security-Policy: ' . $csp);

/* ── View data ────────────────────────────────────────────────────────────
   Every helper below is required by config.php before this file's first
   statement, so none of them can be missing here. The guards that used to wrap
   these five lines were reachable only on an install where config.php had
   failed to load — and config.php calls env_require_all(), which exits. They
   could not protect anything; all they did was make a missing bootstrap look
   like a working panel with empty fields. */
$isLoggedIn  = is_admin();
$csrfToken   = csrf_token();
$totpEnabled = admin_totp_enabled();
$adminLabel  = $h((string) ($_SESSION['admin_user'] ?? 'ADMIN'));
$csrfAttr    = $h($csrfToken);

/* velora_json_escape() is the project's single JSON encoder: JSON_HEX_TAG |
 * AMP | APOS | QUOT makes the payload safe inside both a <script> body and a
 * double-quoted attribute without a CDATA dance, and it returns 'null' instead
 * of false when encoding fails. The inline json_encode() this replaces also
 * needed a hand-written false-check below it, because an unguarded false
 * produces `const UPLOAD_BASE = ;` — a hard parse error that takes down the
 * whole panel, not just the gallery. Both the encoder and the check are now
 * one function. */
$uploadBase = product_upload_url_base();
$uploadBaseJson = velora_json_escape($uploadBase);
$uploadBaseHtml = $h($uploadBase);

$catalogVersion = velora_catalog_version();

$sizeMin = (int) $const('SIZE_MIN', 37);
$sizeMax = (int) $const('SIZE_MAX', 41);

/* Upload ceiling, resolved once, for both the label and the client-side guard.
   A Persian-digit label because the panel's numerals are Persian throughout —
   $h() is not applied here as the string is built server-side from a number. */
$uploadEffective = admin_upload_effective_max();
$uploadMb        = $uploadEffective / 1048576;
$uploadLimitLabel = ($uploadMb >= 1 && $uploadMb == floor($uploadMb))
    ? number_format($uploadMb, 0, '.', ',') . ' مگابایت'
    : rtrim(rtrim(number_format($uploadMb, 1, '.', ','), '0'), '.') . ' مگابایت';
/* True when the host's php.ini is tighter than the constant the code enforces —
   the case worth telling the operator about, because raising ADMIN_UPLOAD_MAX_BYTES
   will not fix it. */
$uploadConstrained = $uploadEffective < (int) ADMIN_UPLOAD_MAX_BYTES;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= $csrfAttr ?>">
<?php /* The ceiling in force, not the ceiling the code wishes for. The panel
       says ۱۰ مگابایت and PHP may say 2M; an operator who cannot see which one
       they hit has no way to act on it. See admin_upload_effective_max(). */ ?>
<meta name="upload-max" content="<?= (int) $uploadEffective ?>">
<meta name="upload-declared" content="<?= (int) ADMIN_UPLOAD_MAX_BYTES ?>">
<title>VELORA · Command Center</title>
<link rel="icon" type="image/png" href="/brand-icon-192.png">
<link rel="apple-touch-icon" href="/brand-icon-180.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style nonce="<?= $nonceAttr ?>">
:root{--bg:#080808;--bg2:#111;--bg3:#1a1a1a;--tx:#eee;--tx2:#a0a0a0;--tx3:#7a7a7a;--gold:#d4af37;--gold2:#f4cf67;--ok:#4caf50;--err:#f44336;--warn:#ff9800;--bd:#222;--r:10px;--f:'Vazirmatn',system-ui;--fm:'JetBrains Mono',monospace;--glass:rgba(17,17,17,.85);color-scheme:dark}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--f);background:var(--bg);color:var(--tx);min-height:100vh;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:var(--gold);text-decoration:none}
.hidden{display:none!important}
.noscript-banner{padding:2.5rem;text-align:center;color:var(--tx2);background:var(--bg2);border:1px solid var(--bd);border-radius:12px;margin:2rem auto;max-width:560px;font-size:.9375rem;line-height:1.9}
.login-wrap{min-height:100vh;display:grid;place-items:center;padding:2rem;background:radial-gradient(ellipse at 50% 30%,#1a1508 0%,var(--bg) 70%)}
.login-card{background:var(--glass);backdrop-filter:blur(20px);border:1px solid var(--bd);border-radius:16px;padding:3rem;max-width:400px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.5)}
.login-card h1{text-align:center;color:var(--gold);letter-spacing:.15em;margin-bottom:2rem;font-size:1.5rem}
.field{margin-bottom:1.25rem}
.field label{display:block;font-size:.8rem;color:var(--tx2);margin-bottom:.5rem;letter-spacing:.05em}
.field input,.field select,.field textarea{width:100%;padding:.875rem 1rem;background:var(--bg3);border:1px solid var(--bd);border-radius:var(--r);color:var(--tx);font-size:.9375rem;transition:border-color .2s;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--gold)}
.field textarea{resize:vertical;min-height:90px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.875rem 1.5rem;border-radius:var(--r);font-weight:600;font-size:.9375rem;cursor:pointer;border:none;font-family:inherit;transition:all .2s}
.btn--gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
.btn--gold:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn--ghost{background:transparent;border:1px solid var(--bd);color:var(--tx)}
.btn--ghost:hover{border-color:var(--tx3);background:var(--bg3)}
.btn--block{width:100%}
.btn--sm{padding:.5rem .875rem;font-size:.8125rem}
.app{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
.sidebar{background:var(--bg2);border-inline-end:1px solid var(--bd);padding:1.5rem 0;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.sidebar-logo{padding:0 1.5rem 1.5rem;border-bottom:1px solid var(--bd);margin-bottom:1rem}
.sidebar-logo h2{color:var(--gold);font-size:1.125rem;letter-spacing:.1em}
.sidebar-logo p{font-size:.65rem;color:var(--tx3);letter-spacing:.2em;text-transform:uppercase;margin-top:.25rem}
.nav{flex:1;padding:0 .75rem}
.nav-item{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-radius:var(--r);color:var(--tx2);cursor:pointer;margin-bottom:.25rem;font-size:.9375rem;transition:all .2s}
.nav-item:hover{background:var(--bg3);color:var(--tx)}
.nav-item.active{background:rgba(212,175,55,.08);color:var(--gold)}
.nav-item svg{width:20px;height:20px;flex:none;stroke:currentColor;fill:none;stroke-width:1.5}
.main{padding:2rem;overflow-x:hidden;background:radial-gradient(ellipse at 80% 0%,#12100a 0%,transparent 50%)}
.main-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid var(--bd);gap:1rem;flex-wrap:wrap}
.main-header h1{font-size:1.75rem;font-weight:600;letter-spacing:-.01em}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:2rem}
.stat-card{background:var(--bg2);border:1px solid var(--bd);border-radius:14px;padding:1.5rem;transition:border-color .2s}
.stat-card:hover{border-color:var(--tx3)}
.stat-card .label{font-size:.7rem;color:var(--tx3);letter-spacing:.12em;text-transform:uppercase;margin-bottom:.5rem}
.stat-card .value{font-size:2rem;font-weight:700;font-family:var(--fm);letter-spacing:-.02em}
.stat-card .value.sm{font-size:1.3rem}
.stat-card .sub{font-size:.8125rem;color:var(--tx2);margin-top:.25rem}
.panel{background:var(--bg2);border:1px solid var(--bd);border-radius:14px;overflow:hidden;margin-bottom:1.5rem}
.panel-head{padding:1.25rem 1.5rem;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
.panel-head h2{font-size:1.125rem;font-weight:500}
.tbl-wrap{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:1rem 1.25rem;text-align:right;border-bottom:1px solid var(--bd);font-size:.875rem}
.tbl th{background:var(--bg3);color:var(--tx2);font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;font-weight:500}
.tbl tr:hover td{background:rgba(255,255,255,.02)}
.mono{font-family:var(--fm);font-size:.8125rem;color:var(--tx2)}
.badge{display:inline-block;padding:.25rem .75rem;border-radius:6px;font-size:.75rem;font-weight:600}
.badge--ok{background:rgba(76,175,80,.12);color:var(--ok)}
.badge--warn{background:rgba(255,152,0,.12);color:var(--warn)}
.badge--err{background:rgba(244,67,54,.12);color:var(--err)}
.inv-block{border:1px solid var(--bd);border-radius:12px;padding:1.1rem;margin-block-end:1.1rem}
.inv-sub{margin-block-start:1rem}
.inv-sub>b{display:block;font-size:.8125rem;color:var(--tx2);margin-block-end:.55rem}
.inv-list{display:flex;flex-direction:column;gap:.5rem;margin-block-end:.6rem}
.inv-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) 34px;gap:.5rem;align-items:center}
.inv-row input{width:100%;padding:.5rem .65rem;border:1px solid var(--bd);border-radius:8px;background:var(--bg);color:var(--tx);font-size:.875rem;font-family:inherit}
.inv-row input:focus{outline:2px solid var(--gold);outline-offset:1px;border-color:transparent}
.inv-del{width:34px;height:34px;border-radius:8px;border:1px solid var(--bd);background:transparent;color:var(--tx2);font-size:1.15rem;line-height:1;cursor:pointer;transition:.2s}
.inv-del:hover{background:var(--err);border-color:var(--err);color:#fff}
.inv-block .help-block{margin-block-start:.9rem}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(8px);z-index:1000;display:grid;place-items:center;padding:2rem;opacity:0;visibility:hidden;transition:.3s}
.modal-backdrop.open{opacity:1;visibility:visible}
.modal{background:var(--bg2);border:1px solid var(--bd);border-radius:16px;max-width:720px;width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 32px 64px rgba(0,0,0,.5)}
.modal-head{padding:1.25rem 1.5rem;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:1.5rem;overflow-y:auto;flex:1}
.modal-foot{padding:1.25rem 1.5rem;border-top:1px solid var(--bd);display:flex;justify-content:flex-end;gap:.75rem}
.close-btn{width:32px;height:32px;border-radius:50%;border:1px solid var(--bd);color:var(--tx2);background:transparent;cursor:pointer;display:grid;place-items:center;transition:.2s}
.close-btn:hover{background:var(--err);color:#fff;border-color:var(--err)}
.toast-wrap{position:fixed;top:1.5rem;left:1.5rem;z-index:2000;display:flex;flex-direction:column;gap:.75rem;pointer-events:none}
.toast{padding:1rem 1.5rem;border-radius:var(--r);background:var(--bg2);border:1px solid var(--bd);box-shadow:0 12px 32px rgba(0,0,0,.4);animation:slideIn .3s ease-out;pointer-events:auto}
.toast.ok{border-color:var(--ok)}.toast.err{border-color:var(--err)}
@keyframes slideIn{from{transform:translateX(-100%);opacity:0}to{transform:none;opacity:1}}
.search-bar{position:relative;max-width:280px}
.search-bar input{width:100%;padding:.625rem 1rem;background:var(--bg3);border:1px solid var(--bd);border-radius:var(--r);color:var(--tx);font-size:.875rem}
.empty-state{padding:3rem;text-align:center;color:var(--tx3)}
/* Catalogue read failure. Classes, not style="" — this document's own CSP sets
   style-src-attr 'none', so an inline style attribute would be dropped and the
   diagnostic would render unstyled, i.e. the one message that must be legible
   would be the one thing the browser silently removes. */
.catalog-fault{text-align:right;line-height:1.8}
.catalog-fault__why{color:#e08a6a;font-weight:600;margin-bottom:.5rem}
.catalog-fault__path{font-size:.75rem;opacity:.7;word-break:break-all;direction:ltr;text-align:left}
@media(max-width:1024px){.app{grid-template-columns:1fr}.sidebar{position:fixed;inset-inline-start:-280px;top:0;bottom:0;width:260px;z-index:100;transition:.3s}.sidebar.open{inset-inline-start:0}}
.menu-toggle{display:none;position:fixed;top:1rem;inset-inline-start:1rem;z-index:99;width:44px;height:44px;border-radius:50%;background:var(--bg2);border:1px solid var(--bd);color:var(--gold);cursor:pointer;font-size:1.4rem}
@media(max-width:1024px){.menu-toggle{display:grid;place-items:center}}
.form-grid{display:grid;gap:1rem}
.form-grid--2{grid-template-columns:repeat(2,1fr)}
@media(max-width:640px){.form-grid--2{grid-template-columns:1fr}}
.status-sel{padding:.375rem .75rem;border:1px solid var(--bd);border-radius:6px;background:var(--bg3);color:var(--tx);font-size:.75rem;font-family:inherit}
.gallery-hint{font-size:.7rem;color:var(--tx3);margin-top:.4rem;line-height:1.75}
.gallery-hint b{color:var(--tx2);font-weight:500}
.gallery-preview{display:grid;grid-template-columns:repeat(auto-fill,minmax(82px,1fr));gap:.5rem;margin-top:.75rem;padding:.65rem;background:var(--bg3);border:1px dashed var(--bd);border-radius:var(--r);min-height:64px}
.gallery-preview:empty{display:none}
.gallery-item{position:relative;aspect-ratio:1;border-radius:6px;overflow:hidden}
.gallery-item img{width:100%;height:100%;object-fit:cover;background:var(--bg2);border:1px solid var(--bd);border-radius:6px;display:block}
.gallery-item img.is-broken{opacity:.25}
.gallery-item-actions{position:absolute;top:3px;inset-inline-end:3px;display:flex;gap:3px;opacity:0;transition:.18s;pointer-events:none}
.gallery-item:hover .gallery-item-actions,.gallery-item:focus-within .gallery-item-actions{opacity:1;pointer-events:auto}
.gallery-item-actions button{width:22px;height:22px;border-radius:50%;border:1px solid rgba(255,255,255,.22);background:rgba(0,0,0,.78);color:#fff;font-size:.78rem;cursor:pointer;display:grid;place-items:center;line-height:1;padding:0;font-family:inherit;transition:.15s}
.gallery-item-actions button:hover{background:var(--err);border-color:var(--err);color:#fff}
.gallery-item-actions button.replace-btn:hover{background:var(--gold);border-color:var(--gold);color:#000}
.upload-zone{border:2px dashed var(--bd);border-radius:var(--r);padding:1.4rem 1rem;text-align:center;cursor:pointer;transition:.2s;background:var(--bg3);user-select:none}
.upload-zone:hover,.upload-zone.dragover,.upload-zone:focus-visible{border-color:var(--gold);background:rgba(212,175,55,.06);outline:none}
.upload-zone input{display:none}
.upload-zone-text{color:var(--tx2);font-size:.875rem;line-height:1.7}
.upload-zone-text b{color:var(--gold);font-weight:600}
.upload-zone-text small{display:block;font-size:.7rem;color:var(--tx3);margin-top:.4rem;letter-spacing:.02em}
.upload-progress{margin-top:.6rem;display:flex;flex-direction:column;gap:.3rem}
.upload-progress .item{padding:.45rem .7rem;background:var(--bg3);border-radius:6px;font-size:.78rem;color:var(--tx2);display:flex;justify-content:space-between;align-items:center;border:1px solid var(--bd)}
.upload-progress .item.ok{color:var(--ok);border-color:rgba(76,175,80,.35)}
.upload-progress .item.err{color:var(--err);border-color:rgba(244,67,54,.35)}
.sec-foot{padding:1rem 1.5rem;border-top:1px solid var(--bd)}
.line-item{padding:.75rem 0;border-bottom:1px solid var(--bd)}
.meta-sm{font-size:.8125rem;color:var(--tx3)}
.meta-xs{font-size:.75rem;color:var(--tx3)}
.mt-xs{margin-top:.25rem}
.kv-grid{display:grid;gap:.4rem;font-size:.875rem;margin-bottom:1rem}
.sum-head{margin-top:1rem;padding-top:1rem;border-top:1px solid var(--bd);display:flex;justify-content:space-between;font-size:1.125rem;font-weight:600}
.gold{color:var(--gold)}
.thumb{width:44px;height:44px;object-fit:cover;border-radius:6px;background:var(--bg3);border:1px solid var(--bd);display:block}
.thumb--empty{width:44px;height:44px;border-radius:6px;background:var(--bg3);border:1px dashed var(--bd)}
.gallery-item--bad{border:1px dashed var(--err);display:grid;place-items:center;font-size:.55rem;color:var(--err);text-align:center;padding:2px}
.field--full{grid-column:1/-1;margin-top:.5rem;padding-top:1rem;border-top:1px solid var(--bd)}
.field label.lbl-gold{color:var(--gold);font-weight:600;letter-spacing:.06em}
.help-block{display:block;margin-top:1rem;font-size:.78rem;color:var(--tx2)}
.field textarea.mono-area{font-family:var(--fm);font-size:.75rem;text-align:left;direction:ltr;min-height:100px;width:100%;padding:.75rem;background:var(--bg3);border:1px solid var(--bd);border-radius:var(--r);color:var(--tx)}
.catalog-stamp{margin-block-start:.5rem;font-family:var(--fm,ui-monospace,SFMono-Regular,Menlo,Consolas,monospace);font-size:.6rem;line-height:1.5;letter-spacing:.06em;color:var(--tx3);opacity:.62}
@media (prefers-reduced-motion: reduce){*,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;scroll-behavior:auto!important}}
</style>
</head>
<body>
<noscript>
  <div class="noscript-banner">
    پنل مدیریت VELORA برای کار کردن به JavaScript نیاز دارد.
    لطفاً آن را در مرورگر فعال کنید و صفحه را دوباره بارگذاری کنید.
  </div>
</noscript>
<?php if (!$isLoggedIn): ?>
<div class="login-wrap">
<div class="login-card">
<h1>VELORA ADMIN</h1>
<form id="loginForm" novalidate>
<div class="field"><label for="username">نام کاربری</label>
  <input id="username" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" required></div>
<div class="field"><label for="password">رمز عبور</label>
  <input id="password" type="password" autocomplete="current-password" required></div>
<?php if ($totpEnabled): ?>
<div class="field"><label for="totpCode">کد تأیید (اپلیکیشن احراز هویت)</label>
  <input id="totpCode" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" placeholder="۱۲۳۴۵۶" required></div>
<?php endif; ?>
<button type="submit" class="btn btn--gold btn--block">ورود امن</button>
</form>
</div>
</div>
<script nonce="<?= $nonceAttr ?>">
document.getElementById('loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  const btn = e.target.querySelector('button');
  if (!btn) return;
  btn.disabled = true; btn.textContent = 'احراز هویت…';
  const meta = document.querySelector('meta[name="csrf-token"]');
  const attempt = async (csrf) => {
    const fd = new FormData();
    fd.append('action', 'admin_login');
    fd.append('username', document.getElementById('username').value);
    fd.append('password', document.getElementById('password').value);
    const totp = document.getElementById('totpCode');
    if (totp) fd.append('code', totp.value);
    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: fd,
    });
    let json = {};
    try { json = await res.json(); } catch {}
    return { res, json };
  };
  let succeeded = false;
  try {
    let { res, json } = await attempt(meta ? meta.content : '');
    if (res.status === 403 && json && json.token) {
      if (meta) meta.content = json.token;
      ({ res, json } = await attempt(json.token));
    }
    if (json && json.ok) { succeeded = true; location.reload(); return; }
    const detail = (json && (json.error || json.message))
      ? (json.error || json.message)
      : ('HTTP ' + res.status);
    alert('ورود ناموفق · ' + detail
      + (json && json.diag ? '\n\n' + JSON.stringify(json.diag, null, 2) : ''));
  } catch (err) {
    alert('خطای شبکه: ' + (err && err.message ? err.message : 'نامشخص'));
  } finally {
    if (!succeeded && btn.isConnected) {
      btn.disabled = false; btn.textContent = 'ورود امن';
    }
  }
});
</script>
<?php else: ?>
<button type="button" class="menu-toggle" id="menuToggle" aria-label="باز کردن منو" aria-controls="sidebar" aria-expanded="false">☰</button>
<div class="app">
<aside class="sidebar" id="sidebar" aria-label="منوی مدیریت">
<div class="sidebar-logo"><h2>VELORA</h2><p>Command Center</p>
  <p class="meta-xs catalog-stamp" id="catalogStamp"
     title="محتوای products.json روی سرور">catalog <?= $h($catalogVersion) ?></p>
</div>
<nav class="nav">
<div class="nav-item active" data-page="dashboard" role="button" tabindex="0" aria-current="page"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg><span>داشبورد</span></div>
<div class="nav-item" data-page="orders" role="button" tabindex="0"><svg viewBox="0 0 24 24"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/></svg><span>سفارشات</span></div>
<div class="nav-item" data-page="products" role="button" tabindex="0"><svg viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg><span>محصولات</span></div>
<div class="nav-item" data-page="users" role="button" tabindex="0"><svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/></svg><span>کاربران</span></div>
<div class="nav-item" data-page="appointments" role="button" tabindex="0"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><span>وقت‌ها</span></div>
</nav>
<div class="sec-foot"><button type="button" class="btn btn--ghost btn--block btn--sm" id="logoutBtn">خروج امن</button></div>
</aside>
<main class="main">
<header class="main-header">
<h1 id="pageTitle">داشبورد</h1>
<div class="mono"><?= $adminLabel ?></div>
</header>
<div id="pageContent"><div class="empty-state">در حال بارگذاری…</div></div>
</main>
</div>
<div class="modal-backdrop" id="modal"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
<div class="modal-head"><h2 id="modalTitle">عنوان</h2><button type="button" class="close-btn" data-close-modal aria-label="بستن">✕</button></div>
<div class="modal-body" id="modalBody"></div>
<div class="modal-foot" id="modalFoot"></div>
</div></div>
<div class="toast-wrap" id="toastWrap" aria-live="polite" aria-atomic="true"></div>

<script nonce="<?= $nonceAttr ?>">
'use strict';
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const UPLOAD_BASE = <?= $uploadBaseJson ?>;
/* The ceiling in force, published from admin_upload_effective_max() in config.php
   — the same number the zone's label shows, so the pre-flight check below and the
   text the operator reads cannot disagree. UPLOAD_LIMIT_LABEL is injected because
   this file's inline JS is written with \u escapes throughout: a literal Persian
   string here has broken the token stream before, and the parse error takes down
   the whole panel rather than one message. */
const UPLOAD_MAX_BYTES = <?= $uploadEffective ?>;
const UPLOAD_LIMIT_LABEL = <?= json_encode($uploadLimitLabel, JSON_UNESCAPED_UNICODE) ?>;
const INV_EU_MIN = <?= $sizeMin ?>;
const INV_EU_MAX = <?= $sizeMax ?>;
const INV_STOCK_MAX = 100000;
const INV_SWATCH_KEYS = ['champ','noir','ivory','suede','oxblood','cognac','pearl','satin','gold','patent'];
/* The category vocabulary, published from CATEGORY_SLUGS / CATEGORY_LABELS in
   config.php.

   The panel used to carry its own array of six slugs, and build its <select>
   from that. Two consequences, both real:

   · It was a second copy of a closed vocabulary, and nothing tied them
     together. Adding a category to the panel produced a product the storefront
     could not file, and api.php now rejects the save outright rather than
     accepting an unreachable product — so a stale panel copy is a refused save
     instead of a silent orphan.
   · The <option> text was the slug itself, so the operator choosing a category
     read `heel`, `flat`, `bridal` in a Persian panel. The customer sees
     «پاشنه بلند»; the person maintaining the catalogue should see the same word
     for the same thing, or they are reading a different vocabulary from the one
     the shop speaks. */
const CAT_SLUGS = <?= json_encode(array_values(CATEGORY_SLUGS), JSON_UNESCAPED_UNICODE) ?>;
const CAT_LABELS = <?= json_encode(CATEGORY_LABELS, JSON_UNESCAPED_UNICODE) ?>;

/* ── api() ─────────────────────────────────────────────────────────────── */
const api = async (action, data = {}) => {
  const fd = data instanceof FormData ? data : new FormData();
  if (!fd.has('action')) fd.append('action', action);
  if (!(data instanceof FormData)) {
    for (const [k, v] of Object.entries(data)) {
      if (v === null || v === undefined) { fd.append(k, ''); continue; }
      if (v instanceof File || v instanceof Blob) fd.append(k, v, v.name || 'file');
      else if (typeof v === 'object' && !(v instanceof Date)) fd.append(k, JSON.stringify(v));
      else fd.append(k, String(v));
    }
  }
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const doPost = async (csrf) => {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: fd,
    });
    let json = {};
    try {
      const parsed = await res.json();
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) json = parsed;
    } catch {}
    if (!res.ok && json.ok === undefined) json = { ok: false, error: 'HTTP_' + res.status };
    return { res, json };
  };
  try {
    let { res, json } = await doPost(csrfMeta ? csrfMeta.content : CSRF);
    if (res.status === 403 && json && json.token) {
      if (csrfMeta) csrfMeta.content = json.token;
      ({ res, json } = await doPost(json.token));
    }
    return json;
  } catch (err) {
    return { ok: false, error: 'NETWORK', detail: err && err.message ? err.message : '' };
  }
};

/* ── Helpers ──────────────────────────────────────────────────────────── */
const toast = (msg, type = 'ok') => {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  $('#toastWrap').appendChild(el);
  setTimeout(() => el.remove(), 4000);
};

const numFmt = new Intl.NumberFormat('fa-IR');
const fmt   = n => numFmt.format(typeof n === 'number' ? n : Number(n) || 0);
const money = n => fmt(n) + '\u0020\u062a\u0648\u0645\u0627\u0646';

const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));

const dtFmt = new Intl.DateTimeFormat('fa-IR');
const dt = s => {
  if (!s) return '\u2014';
  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? '\u2014' : dtFmt.format(d);
};

/* Turn an API refusal into something the operator can act on.
   A 403 with the code CSRF_ORIGIN_INVALID is not an attack, it is a
   configuration fact: the browser is on one hostname and VELORA_APP_URL names
   another. Showing "403" — or worse, an empty table — costs an hour of
   guessing. api.php returns the host it saw, the hosts it expected and the
   configured APP_URL precisely so this line can name the value to change. */
const apiErrorText = (r) => {
  const code = (r && r.error) || '';
  if (code === 'CSRF_ORIGIN_INVALID') {
    const expected = Array.isArray(r.expected) && r.expected.length
      ? r.expected.join(' / ')
      : (r.app_url || '—');
    return 'دامنه رد شد — مرورگر روی «' + (r.host || '?') + '» است اما VELORA_APP_URL روی «'
      + expected + '» تنظیم شده. همان مقدار را در .env اصلاح کنید.';
  }
  if (code === 'CSRF_INVALID') {
    return 'توکن امنیتی منقضی شده — صفحه را دوباره بارگذاری کنید.';
  }
  if (code === 'RATE_LIMIT') return 'تعداد درخواست‌ها زیاد است — کمی بعد دوباره تلاش کنید.';
  if (code === 'NETWORK' || code === 'TIMEOUT') return 'ارتباط با سرور برقرار نشد.';
  if (code === 'UNAUTHORIZED') return 'نشست شما پایان یافته — دوباره وارد شوید.';
  const base = (r && r.message) || 'خطا';
  return code ? base + ' · ' + code : base;
};

const toInt = (v, fallback = 0) => {
  const n = Number.parseInt(v, 10);
  return Number.isFinite(n) ? n : fallback;
};

const invNum = v => {
  const t = String(v).trim();
  if (t === '') return NaN;
  const n = Number(t);
  return Number.isFinite(n) ? n : NaN;
};

const invJson = v => {
  if (v == null) return null;
  if (typeof v !== 'string') return v;
  try { return JSON.parse(v); } catch { return null; }
};

let ordersCache = [], productsCache = [], usersCache = [];

/* ── Modal ────────────────────────────────────────────────────────────── */
let _lastFocusedElement = null;
const openModal = (t, b, f = '') => {
  $('#modalTitle').textContent = t;
  $('#modalBody').innerHTML = b;
  $('#modalFoot').innerHTML = f;
  $('#modal').classList.add('open');
  _lastFocusedElement = document.activeElement;
  const focusable = Array.from($('#modal').querySelectorAll(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )).filter(el => el.offsetParent !== null || el.getClientRects().length > 0);
  if (focusable.length) focusable[0].focus();
};
const closeModal = () => {
  $('#modal').classList.remove('open');
  if (_lastFocusedElement) { _lastFocusedElement.focus(); _lastFocusedElement = null; }
};
$('#modal').addEventListener('click', e => { if (e.target === $('#modal')) closeModal(); });
$('#modal').addEventListener('keydown', e => {
  if (e.key !== 'Tab') return;
  const focusable = Array.from($('#modal').querySelectorAll(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  )).filter(el => el.offsetParent !== null || el.getClientRects().length > 0);
  if (!focusable.length) return;
  const first = focusable[0], last = focusable[focusable.length - 1];
  if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
  else { if (document.activeElement === last) { e.preventDefault(); first.focus(); } }
});

/* ── Nav ──────────────────────────────────────────────────────────────── */
document.addEventListener('click', e => {
  const close = e.target.closest('[data-close-modal]');
  if (close) { closeModal(); return; }
  const item = e.target.closest('.nav-item[data-page]');
  if (!item) return;
  $$('.nav-item').forEach(i => { i.classList.remove('active'); i.removeAttribute('aria-current'); });
  item.classList.add('active');
  item.setAttribute('aria-current', 'page');
  loadPage(item.dataset.page);
});
document.addEventListener('keydown', e => {
  const item = e.target.closest('.nav-item[data-page]');
  if (item && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); item.click(); }
  if (e.key === 'Escape' && $('#modal').classList.contains('open')) closeModal();
});
$('#menuToggle')?.addEventListener('click', () => {
  const sidebar = $('#sidebar');
  if (!sidebar) return;
  const open = sidebar.classList.toggle('open');
  $('#menuToggle').setAttribute('aria-expanded', String(open));
});
$('#logoutBtn').addEventListener('click', async () => {
  if (!confirm('\u062e\u0631\u0648\u062c \u0627\u0632 \u062d\u0633\u0627\u0628\u061f')) return;
  await api('admin_logout');
  location.reload();
});

/* ── Page routing ─────────────────────────────────────────────────────── */
const titles = {
  dashboard:    '\u062f\u0627\u0634\u0628\u0648\u0631\u062f',
  orders:       '\u0633\u0641\u0627\u0631\u0634\u0627\u062a',
  products:     '\u0645\u062d\u0635\u0648\u0644\u0627\u062a',
  users:        '\u06a9\u0627\u0631\u0628\u0631\u0627\u0646',
  appointments: '\u0648\u0642\u062a\u200c\u0647\u0627'
};
let currentPage = '';
let pageRenderVersion = 0;

async function loadPage(page) {
  currentPage = page;
  const v = ++pageRenderVersion;
  $('#pageTitle').textContent = titles[page] || page;
  $('#pageContent').innerHTML = '<div class="empty-state">\u062f\u0631 \u062d\u0627\u0644 \u0628\u0627\u0631\u06af\u0630\u0627\u0631\u06cc\u2026</div>';
  const fn = {
    dashboard: renderDashboard,
    orders: renderOrders,
    products: renderProducts,
    users: renderUsers,
    appointments: renderAppointments
  }[page];
  if (fn) await fn(v);
}

/* ── Dashboard ────────────────────────────────────────────────────────── */
async function renderDashboard(version = pageRenderVersion) {
  const [r, or] = await Promise.all([
    api('admin_stats'),
    api('admin_orders', { limit: 8 })
  ]);
  if (version !== pageRenderVersion) return;
  if (!r.ok) { toast(apiErrorText(r), 'err'); return; }
  const s = r.stats || {};
  $('#pageContent').innerHTML = `<div class="stats-grid">
  <div class="stat-card"><div class="label">کل سفارشات</div><div class="value">${fmt(s.total_orders)}</div><div class="sub">${fmt(s.today_orders)} امروز</div></div>
  <div class="stat-card"><div class="label">پرداخت‌شده</div><div class="value">${fmt(s.paid_orders)}</div><div class="sub">${fmt(s.pending_orders)} در انتظار پردازش</div></div>
  <div class="stat-card"><div class="label">درآمد</div><div class="value sm">${money(s.revenue)}</div></div>
  <div class="stat-card"><div class="label">محصولات</div><div class="value">${fmt(s.total_products)}</div><div class="sub">${fmt(s.low_stock)} کم‌موجود</div></div>
  <div class="stat-card"><div class="label">کاربران</div><div class="value">${fmt(s.total_users)}</div></div>
  </div>
  <div class="panel"><div class="panel-head"><h2>آخرین سفارشات</h2></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">کد</th><th scope="col">مشتری</th><th scope="col">مبلغ</th><th scope="col">پرداخت</th><th scope="col">وضعیت</th><th scope="col">تاریخ</th></tr></thead><tbody id="recentOrders"></tbody></table></div>
  </div>`;
  const tbody = $('#recentOrders');
  if (or.ok) {
    const orders = Array.isArray(or.orders) ? or.orders : [];
    tbody.innerHTML = orders.map(o => `<tr>
    <td class="mono">${esc(o.id)}</td><td>${esc(o.guest_name || '\u2014')}</td>
    <td class="mono">${money(o.total)}</td>
    <td>${payBadge(o.payment_status)}</td>
    <td><span class="badge">${esc(o.status)}</span></td>
    <td class="mono">${dt(o.created_at)}</td>
    </tr>`).join('') || '<tr><td colspan="6" class="empty-state">\u0633\u0641\u0627\u0631\u0634\u06cc \u0646\u06cc\u0633\u062a</td></tr>';
  } else {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-state">\u062e\u0637\u0627 \u062f\u0631 \u0628\u0627\u0631\u06af\u0630\u0627\u0631\u06cc</td></tr>';
  }
}

/* ── Orders ───────────────────────────────────────────────────────────── */
const PAY_LABEL = { paid: '\u067e\u0631\u062f\u0627\u062e\u062a\u200c\u0634\u062f\u0647', pending: '\u062f\u0631 \u0627\u0646\u062a\u0637\u0627\u0631', failed: '\u0646\u0627\u0645\u0648\u0641\u0642', refunded: '\u0628\u0627\u0632\u06af\u0634\u062a\u200c\u0634\u062f\u0647', unpaid: '\u067e\u0631\u062f\u0627\u062e\u062a\u200c\u0646\u0634\u062f\u0647' };
const PAY_BADGE = { paid: 'ok', pending: 'warn', failed: 'err', refunded: 'err', unpaid: 'warn' };
const payBadge = s => {
  const key = (typeof s === 'string') ? s : '';
  const label = Object.hasOwn(PAY_LABEL, key) ? PAY_LABEL[key] : (key || '\u2014');
  const tone  = Object.hasOwn(PAY_BADGE, key) ? PAY_BADGE[key] : 'warn';
  return '<span class="badge badge--' + tone + '">' + esc(label) + '</span>';
};

const ORDER_STATUS = [
  ['pending',    '\u062f\u0631 \u0627\u0646\u062a\u0637\u0627\u0631'],
  ['processing', '\u062f\u0631 \u062d\u0627\u0644 \u067e\u0631\u062f\u0627\u0632\u0634'],
  ['shipped',    '\u0627\u0631\u0633\u0627\u0644 \u0634\u062f\u0647'],
  ['delivered',  '\u062a\u062d\u0648\u06cc\u0644 \u062f\u0627\u062f\u0647 \u0634\u062f\u0647'],
  ['cancelled',  '\u0644\u063a\u0648 \u0634\u062f\u0647']
];
function statusOptions(current) {
  const known = ORDER_STATUS.map(([v, l]) =>
    '<option value="' + v + '"' + (current === v ? ' selected' : '') + '>' + l + '</option>'
  ).join('');
  if (current && !ORDER_STATUS.some(([v]) => v === current)) {
    return '<option value="' + esc(current) + '" selected>' + esc(current) + '</option>' + known;
  }
  return known;
}

async function renderOrders(version = pageRenderVersion) {
  const r = await api('admin_orders');
  if (version !== pageRenderVersion) return;
  if (!r.ok) { toast(apiErrorText(r), 'err'); return; }
  ordersCache = Array.isArray(r.orders) ? r.orders : [];
  $('#pageContent').innerHTML = `<div class="panel">
  <div class="panel-head"><h2>سفارشات (${fmt(ordersCache.length)})</h2>
  <div class="search-bar"><input type="search" id="orderSearch" placeholder="جست‌وجو…"></div></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">کد</th><th scope="col">مشتری</th><th scope="col">تلفن</th><th scope="col">مبلغ</th><th scope="col">پرداخت</th><th scope="col">وضعیت</th><th scope="col">عملیات</th></tr></thead><tbody id="orderBody"></tbody></table></div>
  </div>`;
  paintOrders(ordersCache);
  let t;
  $('#orderSearch').addEventListener('input', e => {
    clearTimeout(t);
    t = setTimeout(() => {
      const q = e.target.value.toLowerCase();
      paintOrders(ordersCache.filter(o =>
        String(o.id).toLowerCase().includes(q) ||
        String(o.guest_name || '').toLowerCase().includes(q) ||
        String(o.guest_phone || '').toLowerCase().includes(q)
      ));
    }, 150);
  });
}

function paintOrders(orders) {
  const tbody = $('#orderBody');
  if (!tbody) return;
  if (!orders.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-state">\u0633\u0641\u0627\u0631\u0634\u06cc \u0646\u06cc\u0633\u062a</td></tr>';
    return;
  }
  tbody.innerHTML = orders.map(o => '<tr>' +
    '<td class="mono">' + esc(o.id) + '</td>' +
    '<td>' + esc(o.guest_name || '\u2014') + '</td>' +
    '<td class="mono" dir="ltr">' + esc(o.guest_phone || '\u2014') + '</td>' +
    '<td class="mono">' + money(o.total) + '</td>' +
    '<td>' + payBadge(o.payment_status) + '</td>' +
    '<td><select class="status-sel" data-oid="' + esc(o.id) + '" aria-label="' +
      esc('\u062a\u063a\u06cc\u06cc\u0631 \u0648\u0636\u0639\u06cc\u062a \u0633\u0641\u0627\u0631\u0634 ' + o.id) + '">' +
      statusOptions(o.status) + '</select></td>' +
    '<td><button type="button" class="btn btn--ghost btn--sm" data-view-order="' +
      esc(o.id) + '">\u0645\u0634\u0627\u0647\u062f\u0647</button></td>' +
    '</tr>').join('');
}

document.addEventListener('click', e => {
  const v = e.target.closest('[data-view-order]');
  if (!v) return;
  const o = ordersCache.find(x => String(x.id) === String(v.dataset.viewOrder));
  if (!o) return;
  const items = (Array.isArray(o.items) ? o.items : []).map(i => '<div class="line-item">' +
    '<div><strong>' + esc(i.product_name) + '</strong></div>' +
    '<div class="meta-sm">\u0633\u0627\u06cc\u0632 ' + esc(i.eu_size || '\u2014') + ' \u00b7 ' +
      esc(i.color || '') + ' \u00b7 ' + fmt(i.qty) + '\u00d7</div>' +
    '<div class="mono mt-xs">' + money((Number(i.unit_price) || 0) * (Number(i.qty) || 0)) + '</div>' +
    '</div>').join('');
  const snap = o.address_snapshot || null;
  /* The postal code gets its own line because it is the one field a courier
     reads first and the one an operator checks when a parcel comes back — and
     it is not easy to find inside a composed one-line address. Orders placed
     before the snapshot existed have none, so the line is simply absent rather
     than an em dash pretending the customer left it blank. */
  const postalLine = o.postal_display
    ? '<div><strong>\u06a9\u062f \u067e\u0633\u062a\u06cc:</strong> <span dir="ltr" class="mono">' + esc(o.postal_display) + '</span></div>'
    : '';
  const districtLine = snap && snap.district
    ? '<div><strong>\u0645\u062d\u0644\u0647:</strong> ' + esc(snap.district) + '</div>'
    : '';
  openModal('\u0633\u0641\u0627\u0631\u0634 ' + esc(o.id),
    '<div class="kv-grid">' +
    '<div><strong>\u0646\u0627\u0645:</strong> ' + esc(o.guest_name || '\u2014') + '</div>' +
    '<div><strong>\u062a\u0644\u0641\u0646:</strong> <span dir="ltr">' + esc(o.guest_phone || '\u2014') + '</span></div>' +
    postalLine + districtLine +
    '<div><strong>\u0646\u0634\u0627\u0646\u06cc:</strong> ' + esc(o.address || '\u2014') + '</div>' +
    '</div>' + items +
    '<div class="sum-head">' +
    '<span>\u062c\u0645\u0639 \u06a9\u0644</span><span class="gold">' + money(o.total) + '</span></div>',
    '<button type="button" class="btn btn--ghost" data-close-modal>\u0628\u0633\u062a\u0646</button>');
});

/* ── Products ─────────────────────────────────────────────────────────── */
/* Why is the catalogue empty? The API now reports the read path's health, so
   an empty table can say whether the shop is genuinely empty or the file could
   not be read. Written with \u escapes like the rest of the literals in this
   file, so no byte-order or encoding accident can change what it says. */
const CATALOG_REASON = {
  MISSING:       'فایل کاتالوگ پیدا نشد',
  UNREADABLE:    'فایل کاتالوگ قابل خواندن نیست (دسترسی یا مالکیت)',
  EMPTY_FILE:    'فایل کاتالوگ خالی است',
  NO_VALID_ROWS: 'فایل خوانده شد ولی هیچ ردیف معتبری ندارد',
};

function catalogDiagnostic(r) {
  const c = (r && r.catalog) || null;
  if (!c || c.ok) return '';
  const why = CATALOG_REASON[c.reason] || c.reason || 'نامشخص';
  const detail = c.file ? c.file + '  (' + c.bytes + ' bytes)' : '';
  return '<tr><td colspan="7">'
    + '<div class="empty-state catalog-fault">'
    + '<div class="catalog-fault__why">'
    + 'کاتالوگ خوانده نشد — ' + esc(why) + '</div>'
    + '<div class="catalog-fault__path">' + esc(detail) + '</div>'
    + '</div></td></tr>';
}

async function renderProducts(version = pageRenderVersion) {
  const r = await api('admin_products');
  if (version !== pageRenderVersion) return;
  if (!r.ok) { toast(apiErrorText(r), 'err'); return; }
  productsCache = Array.isArray(r.products) ? r.products : [];
  $('#pageContent').innerHTML = `<div class="panel">
  <div class="panel-head"><h2>محصولات (${fmt(productsCache.length)})</h2>
  <button class="btn btn--gold btn--sm" id="addProductBtn">+ افزودن</button></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">پیش‌نمایش</th><th scope="col">نام</th><th scope="col">دسته</th><th scope="col">قیمت</th><th scope="col">موجودی</th><th scope="col">فروش</th><th scope="col">عملیات</th></tr></thead><tbody id="productBody"></tbody></table></div>
  </div>`;
  $('#productBody').innerHTML = productsCache.map(p => {
    const firstKey = p.gallery ? String(p.gallery).split(/[|\n,]+/).filter(Boolean)[0] || '' : '';
    const previewUrl = firstKey ? resolveImagePreview(firstKey) : '';
    const thumb = previewUrl
      ? '<img src="' + esc(previewUrl) + '" alt="' + esc('\u062a\u0635\u0648\u06cc\u0631 ' + p.name) + '" width="44" height="44" loading="lazy" class="thumb">'
      : '<div class="thumb thumb--empty" aria-hidden="true"></div>';
    return '<tr>' +
      '<td>' + thumb + '</td>' +
      '<td><strong>' + esc(p.name) + '</strong><div class="meta-xs">' + esc(p.sub) + '</div></td>' +
      '<td>' + esc(p.cat) + '</td>' +
      '<td class="mono">' + money(p.price) + '</td>' +
      '<td class="mono">' + fmt(p.total_stock || 0) + '</td>' +
      '<td class="mono">' + fmt(p.sold) + '</td>' +
      '<td><button type="button" class="btn btn--ghost btn--sm" data-edit-product="' + esc(p.id) + '">\u0648\u06cc\u0631\u0627\u06cc\u0634</button>' +
      '<button type="button" class="btn btn--ghost btn--sm" data-del-product="' + esc(p.id) + '">\u062d\u0630\u0641</button></td>' +
      '</tr>';
  }).join('') || '<tr><td colspan="7" class="empty-state">\u0645\u062d\u0635\u0648\u0644\u06cc \u0646\u06cc\u0633\u062a</td></tr>';
  if (!productsCache.length) {
    const diag = catalogDiagnostic(r);
    if (diag) $('#productBody').insertAdjacentHTML('afterbegin', diag);
  }
  $('#addProductBtn').addEventListener('click', () => editProduct());
}

document.addEventListener('click', e => {
  const eb = e.target.closest('[data-edit-product]');
  if (eb) { editProduct(eb.dataset.editProduct); return; }
  const db = e.target.closest('[data-del-product]');
  if (db) { deleteProduct(db.dataset.delProduct); return; }
});

document.addEventListener('change', async e => {
  const sel = e.target.closest('.status-sel');
  if (!sel) return;
  if (sel.matches('[data-oid]')) {
    const order = ordersCache.find(o => String(o.id) === String(sel.dataset.oid));
    const previous = order ? order.status : undefined;
    sel.disabled = true;
    const r = await api('admin_order_status', { id: sel.dataset.oid, status: sel.value });
    if (r.ok) { if (order) order.status = sel.value; }
    else if (previous !== undefined) { sel.value = previous; }
    if (sel.isConnected) sel.disabled = false;
    let msg;
    if (r.ok) msg = '\u0628\u0647\u200c\u0631\u0648\u0632 \u0634\u062f';
      else msg = apiErrorText(r);
    toast(msg, r.ok ? 'ok' : 'err');
    return;
  }
  if (sel.matches('[data-aid]')) {
    const previous = sel.value;
    sel.disabled = true;
    const r = await api('admin_appointment_status', { id: parseInt(sel.dataset.aid, 10), status: sel.value });
    if (!r.ok) sel.value = previous;
    if (sel.isConnected) sel.disabled = false;
    toast(r.ok ? '\u0628\u0647\u200c\u0631\u0648\u0632 \u0634\u062f' : apiErrorText(r), r.ok ? 'ok' : 'err');
  }
});

/* ── Image preview & uploads ──────────────────────────────────────────── */
function resolveImagePreview(key) {
  if (!key) return '';
  key = String(key).trim();
  if (!key) return '';
  if (/^https?:\/\//i.test(key)) return key;
  if (!/^[a-zA-Z0-9_-]{1,80}\.webp$/.test(key)) return '';
  /* A bare filename is resolved against UPLOAD_BASE, which is APP_URL-derived.
     When the panel is open on a different host than APP_URL names — a local
     install against a production APP_URL, a staging hostname, an IP — that
     base points somewhere else entirely and every preview 404s, with the
     console showing only the filename. Refuse to build a URL we know will not
     resolve, and say why, rather than emitting a broken <img> per product. */
  if (!UPLOAD_BASE) return '';
  try {
    const base = new URL(UPLOAD_BASE, location.href);
    if (base.origin !== location.origin) return '';
  } catch { return ''; }
  return UPLOAD_BASE + '/' + key;
}

async function uploadProductImage(file, productId, replaceFilename = '') {
  return api('admin_upload_image', {
    product_id: productId,
    image: file,
    replace_filename: replaceFilename
  });
}

function extractLocalUploadFilename(url) {
  if (!url || typeof url !== 'string') return '';
  let u, base;
  try {
    u = new URL(url, location.href);
    base = new URL(UPLOAD_BASE, location.href);
  } catch { return ''; }
  if (u.origin !== base.origin) return '';
  if (u.pathname.indexOf('/storage/uploads/products/') === -1) return '';
  const file = u.pathname.split('/').pop();
  return /^[a-zA-Z0-9_-]{1,80}\.webp$/.test(file) ? file : '';
}

/* renderGalleryPreview — built entirely with string concatenation. The
   previous template-literal form mixed Persian text with `${}` substitutions
   on adjacent lines; a stray invisible character in that Persian text (ZWNJ,
   RLM, or a Windows-1256 byte) broke the token stream and produced
   "Invalid or unexpected token" at the first ${esc(url)} substitution.
   Every non-ASCII glyph below is written as a \u escape so the file's byte
   encoding cannot affect parsing. */
function renderGalleryPreview(ta, prev) {
  if (!ta || !prev) return;
  const keys = ta.value.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
  prev.innerHTML = keys.map(function (k, i) {
    const url = resolveImagePreview(k);
    const idx = String(i);
    if (!url) {
      return '<div class="gallery-item gallery-item--bad" role="alert">URL<br>' +
        '\u0646\u0627\u0645\u0639\u062a\u0628\u0631</div>';
    }
    const isLocal = !!extractLocalUploadFilename(k);
    const replaceTitle = isLocal
      ? '\u062c\u0627\u06cc\u06af\u0632\u06cc\u0646\u06cc \u0627\u06cc\u0646 \u062a\u0635\u0648\u06cc\u0631'
      : '\u0627\u0641\u0632\u0648\u062f\u0646 \u062a\u0635\u0648\u06cc\u0631 \u062c\u062f\u06cc\u062f';
    const replaceAria = (isLocal
      ? '\u062c\u0627\u06cc\u06af\u0632\u06cc\u0646\u06cc \u062a\u0635\u0648\u06cc\u0631 \u0634\u0645\u0627\u0631\u0647 '
      : '\u0627\u0641\u0632\u0648\u062f\u0646 \u062a\u0635\u0648\u06cc\u0631 \u0634\u0645\u0627\u0631\u0647 ') + (i + 1);
    const removeAria = '\u062d\u0630\u0641 \u062a\u0635\u0648\u06cc\u0631 \u0634\u0645\u0627\u0631\u0647 ' + (i + 1);
    const removeTitle = '\u062d\u0630\u0641 \u0627\u0632 \u0641\u0647\u0631\u0633\u062a';
    return '<div class="gallery-item" data-idx="' + esc(idx) + '">' +
      '<img src="' + esc(url) + '" alt="" loading="lazy" data-gallery-preview-image>' +
      '<div class="gallery-item-actions">' +
        '<button type="button" class="replace-btn" data-gallery-replace="' + esc(idx) + '"' +
          ' title="' + esc(replaceTitle) + '"' +
          ' aria-label="' + esc(replaceAria) + '">' + '\u21BB' + '</button>' +
        '<button type="button" class="remove-btn" data-gallery-remove="' + esc(idx) + '"' +
          ' title="' + esc(removeTitle) + '"' +
          ' aria-label="' + esc(removeAria) + '">' + '\u00d7' + '</button>' +
      '</div>' +
    '</div>';
  }).join('');
}

/* ── Upload pre-flight ──────────────────────────────────────────────────
   Files over the ceiling are rejected here, in the browser, with a message
   that names the limit.

   The point is not to save bandwidth. It is that the alternative is invisible:
   PHP discards a body larger than post_max_size before any of our code runs,
   so $_FILES is simply absent and api.php can only answer NO_FILE — which
   reads as "the endpoint is broken" and sends the operator to debug the wrong
   layer. Checking the size against the same number the label shows turns a
   mystery into a sentence. */
function uploadTooLarge(file) {
  return typeof UPLOAD_MAX_BYTES === 'number' && UPLOAD_MAX_BYTES > 0
    && file && file.size > UPLOAD_MAX_BYTES;
}
const uploadSizeMB = b => (b / 1048576).toFixed(b % 1048576 ? 1 : 0);

/* Both of these are re-bound on every editProduct(), and the modal body is
   rewritten with innerHTML each time — so #uploadZone, #uploadInput and
   #pm_gallery_preview are fresh, unreferenced nodes on every open, and the
   listeners attached to the previous ones are unreachable and collectible.

   That is worth stating because the obvious reading of this code is a leak:
   listeners, added again and again, on what looks like the same element. It is
   not, and the guard below is deliberately NOT a dataset token. Such a guard
   would compare a token derived from the new productId against the fresh node
   that has never been marked, fail to match, and proceed — or worse, match a
   stale marker if the node were ever reused, leaving runUploads bound to the
   previous modal's ta/prev and uploading into the wrong product's gallery.

   A read-through cache on the container instead makes the idempotence explicit
   and cheap, and keeps the per-open state in one object rather than in a
   closure captured at bind time. */
let _uploadZoneBound = null;

function bindUploadZone(productId, ta, prev, progress) {
  const zone  = $('#uploadZone');
  const input = $('#uploadInput');
  if (!zone || !input) return;
  if (_uploadZoneBound && _uploadZoneBound.zone === zone) {
    /* Same live nodes: refresh the per-open state and skip the listeners. */
    _uploadZoneBound.ctx.productId = productId;
    _uploadZoneBound.ctx.ta = ta;
    _uploadZoneBound.ctx.prev = prev;
    _uploadZoneBound.ctx.progress = progress;
    return;
  }
  const ctx = { productId, ta, prev, progress };
  _uploadZoneBound = { zone, input, ctx };

  const runUploads = async (files) => {
    const c = _uploadZoneBound ? _uploadZoneBound.ctx : ctx;
    const ta = c.ta, prev = c.prev, progress = c.progress;
    files = files.filter(f => f && /^image\//.test(f.type));
    if (!files.length || !progress || !ta) return;
    const owned = [];
    for (const file of files) {
      const item = document.createElement('div');
      item.className = 'item';
      progress.appendChild(item);
      owned.push(item);
      if (uploadTooLarge(file)) {
        item.className = 'item err';
        item.textContent = '✗ ' + file.name + ' — ' + uploadSizeMB(file.size)
          + ' مگابایت، بیش از حد ' + uploadSizeMB(UPLOAD_MAX_BYTES) + ' مگابایت';
        continue;
      }
item.textContent = '⏳ ' + file.name;
      /* Re-read the context: the modal may have been closed and reopened for a
         different product while this file was still in flight, and the upload
         must land in the gallery that is on screen now, not the one it started
         from. */
      const live = _uploadZoneBound ? _uploadZoneBound.ctx : c;
      const r = await uploadProductImage(file, live.productId);
      if (r && r.ok && r.url) {
        item.className = 'item ok';
        item.textContent = '\u2713 ' + file.name + ' \u2192 WebP 1024\u00d71024';
        const target = live.ta;
        if (!target || !target.isConnected) continue;
        const cur = target.value.trim();
        target.value = cur ? (cur + '\n' + r.url) : r.url;
        renderGalleryPreview(target, live.prev);
      } else {
        item.className = 'item err';
        item.textContent = '\u2717 ' + file.name + ' \u2014 '
          + ((r && (r.message || r.error)) || '\u062e\u0637\u0627\u06cc \u0646\u0627\u0645\u0634\u062e\u0635');
      }
    }
    setTimeout(() => {
      for (const el of owned) { if (el.isConnected) el.remove(); }
    }, 4200);
  };
  zone.addEventListener('click', () => input.click());
  zone.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
  });
  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    runUploads(Array.from((e.dataTransfer && e.dataTransfer.files) || []));
  });
  input.addEventListener('change', () => {
    const files = Array.from(input.files || []);
    input.value = '';
    runUploads(files);
  });
}

/* Same reasoning as bindUploadZone: #pm_gallery_preview is recreated on every
   openModal, so there is nothing to unbind and nothing to leak. The guard is a
   node identity check for the same reason — a productId-keyed token would
   either always miss (fresh node) or, if the node were ever pooled, keep the
   previous modal's textarea. */
let _galleryBound = null;

function bindGalleryActions(prev, ta, productId, progress) {
  if (!prev || !ta) return;
  if (_galleryBound && _galleryBound.prev === prev) {
    const c = _galleryBound.ctx;
    c.productId = productId; c.ta = ta; c.progress = progress;
    return;
  }
  _galleryBound = { prev, ctx: { productId, ta, progress } };

  prev.addEventListener('click', async e => {
    const c = _galleryBound ? _galleryBound.ctx : { productId, ta, progress };
    const rp = e.target.closest('[data-gallery-replace]');
    if (rp) {
      e.preventDefault();
      const idx = +rp.dataset.galleryReplace;
      const lines = c.ta.value.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
      const oldUrl = lines[idx] || '';
      const oldFilename = extractLocalUploadFilename(oldUrl);
      const fi = document.createElement('input');
      fi.type = 'file';
      fi.accept = 'image/jpeg,image/png,image/gif,image/webp';
      fi.addEventListener('change', async () => {
        const file = fi.files && fi.files[0];
        if (!file) return;
        /* The modal may have been closed while the OS file dialog was open;
           write the progress row wherever the live context points, and drop it
           if that context is gone. */
        const live = _galleryBound ? _galleryBound.ctx : c;
        if (!live.progress) return;
        const item = document.createElement('div');
        item.className = 'item';
        item.textContent = '⏳ ' + file.name +
          (oldFilename ? ' (جایگزینی)' : '');
        live.progress.appendChild(item);
        if (uploadTooLarge(file)) {
          item.className = 'item err';
          item.textContent = '✗ ' + file.name + ' — ' + uploadSizeMB(file.size)
            + ' مگابایت، بیش از حد ' + uploadSizeMB(UPLOAD_MAX_BYTES) + ' مگابایت';
          setTimeout(() => { if (item.isConnected) item.remove(); }, 3800);
          return;
        }
        const r = await uploadProductImage(file, live.productId, oldFilename);
        if (!live.ta || !live.ta.isConnected) return;
        if (r && r.ok && r.url) {
          const cur = live.ta.value.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
          if (idx >= 0 && idx < cur.length) cur[idx] = r.url; else cur.push(r.url);
          live.ta.value = cur.join('\n');
          renderGalleryPreview(live.ta, prev);
          item.className = 'item ok';
          item.textContent = '✓ ' + file.name + ' جایگزین شد';
        } else {
          item.className = 'item err';
          item.textContent = '✗ ' + file.name + ' — '
            + ((r && (r.message || r.error)) || 'خطا');
        }
        setTimeout(() => { if (item.isConnected) item.remove(); }, 3800);
      });
fi.click();
      return;
    }
      const rm = e.target.closest('[data-gallery-remove]');
      if (rm) {
        e.preventDefault();
        const idx = +rm.dataset.galleryRemove;
        const lines = c.ta.value.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
        const removed = lines[idx] || '';
        lines.splice(idx, 1);
        c.ta.value = lines.join('\n');
        renderGalleryPreview(c.ta, prev);

        /* Removing the line only edited a text field. The file on disk is the
           actual cost — it is never collected, and a forgotten upload is a
           permanently orphaned WebP that the operator cannot see or reach from
           the UI. So a local upload is deleted for real here, and only a local
           upload: an absolute URL names a file on someone else's server, and
           the endpoint rejects it rather than pretending. */
        const localName = extractLocalUploadFilename(removed) || (/^[a-zA-Z0-9_-]{1,80}\.webp$/.test(removed) ? removed : '');
        if (!localName) return;
        const r = await api('admin_product_image_delete', { product_id: c.productId, filename: localName });
        if (r.ok) {
          if (r.file_deleted) toast(r.message || 'عکس حذف شد', 'ok');
          else toast('ارجاع عکس حذف شد؛ فایل روی سرور باقی ماند', 'ok');
        } else if (r.error === 'IMAGE_SHARED') {
          /* The server still dropped this product's reference; it only kept
             the file because another product is using it. */
          toast(r.message || 'این عکس در گالری محصول دیگری هم استفاده شده', 'err');
        } else {
          toast(apiErrorText(r), 'err');
        }
      }
  });
  prev.addEventListener('error', e => {
    const img = e.target.closest('[data-gallery-preview-image]');
    if (img) img.classList.add('is-broken');
  }, true);
}

/* ── Inventory editor ─────────────────────────────────────────────────── */
function addColorRow(key = '', name = '') {
  const list = $('#invColors');
  if (!list) return;
  const row = document.createElement('div');
  row.className = 'inv-row';
  row.innerHTML =
    '<input class="inv-key" dir="ltr" list="invSwatchList" placeholder="key" value="' + esc(key) + '" aria-label="' + esc('\u06a9\u0644\u06cc\u062f \u0631\u0646\u06af') + '">' +
    '<input class="inv-name" placeholder="' + esc('\u0646\u0627\u0645 \u0631\u0646\u06af') + '" value="' + esc(name) + '" aria-label="' + esc('\u0646\u0627\u0645 \u0631\u0646\u06af') + '">' +
    '<button type="button" class="inv-del" aria-label="' + esc('\u062d\u0630\u0641 \u0631\u0646\u06af') + '" title="' + esc('\u062d\u0630\u0641') + '">&times;</button>';
  row.querySelector('.inv-del').addEventListener('click', () => row.remove());
  list.appendChild(row);
}

function addSizeRow(eu = '', stock = '') {
  const list = $('#invSizes');
  if (!list) return;
  const row = document.createElement('div');
  row.className = 'inv-row';
  row.innerHTML =
    '<input class="inv-eu" type="number" inputmode="numeric" min="' + INV_EU_MIN + '" max="' + INV_EU_MAX + '" step="1" placeholder="' + esc('\u0633\u0627\u06cc\u0632') + '" value="' + esc(eu) + '" aria-label="' + esc('\u0633\u0627\u06cc\u0632') + '">' +
    '<input class="inv-stock" type="number" inputmode="numeric" min="0" max="' + INV_STOCK_MAX + '" step="1" placeholder="' + esc('\u0645\u0648\u062c\u0648\u062f\u06cc') + '" value="' + esc(stock) + '" aria-label="' + esc('\u0645\u0648\u062c\u0648\u062f\u06cc') + '">' +
    '<button type="button" class="inv-del" aria-label="' + esc('\u062d\u0630\u0641 \u0633\u0627\u06cc\u0632') + '" title="' + esc('\u062d\u0630\u0641') + '">&times;</button>';
  row.querySelector('.inv-del').addEventListener('click', () => row.remove());
  list.appendChild(row);
}

function readColors() {
  return Array.from(document.querySelectorAll('#invColors .inv-row')).map(r => ({
    key:  r.querySelector('.inv-key').value.trim(),
    name: r.querySelector('.inv-name').value.trim()
  })).filter(c => c.key || c.name);
}

function readSizes() {
  const out = [], seen = new Set();
  for (const r of document.querySelectorAll('#invSizes .inv-row')) {
    const euRaw = r.querySelector('.inv-eu').value;
    const stkRaw = r.querySelector('.inv-stock').value;
    if (euRaw.trim() === '' && stkRaw.trim() === '') continue;
    const eu = invNum(euRaw), stock = invNum(stkRaw);
    if (!Number.isInteger(eu) || eu < INV_EU_MIN || eu > INV_EU_MAX) {
      throw new Error('\u0633\u0627\u06cc\u0632 \u0628\u0627\u06cc\u062f \u0639\u062f\u062f \u0635\u062d\u06cc\u062d \u0628\u06cc\u0646 ' +
        INV_EU_MIN + ' \u062a\u0627 ' + INV_EU_MAX + ' \u0628\u0627\u0634\u062f (\u0645\u0642\u062f\u0627\u0631 \u0648\u0627\u0631\u062f\u0634\u062f\u0647: \u00ab' + euRaw.trim() + '\u00bb).');
    }
    if (seen.has(eu)) throw new Error('\u0633\u0627\u06cc\u0632 ' + eu + ' \u0628\u06cc\u0634 \u0627\u0632 \u06cc\u06a9 \u0628\u0627\u0631 \u0648\u0627\u0631\u062f \u0634\u062f\u0647 \u0627\u0633\u062a.');
    seen.add(eu);
    if (!Number.isInteger(stock) || stock < 0 || stock > INV_STOCK_MAX) {
      throw new Error('\u0645\u0648\u062c\u0648\u062f\u06cc \u0633\u0627\u06cc\u0632 ' + eu + ' \u0628\u0627\u06cc\u062f \u0639\u062f\u062f \u0635\u062d\u06cc\u062d \u0628\u06cc\u0646 \u06f0 \u062a\u0627 ' + INV_STOCK_MAX + ' \u0628\u0627\u0634\u062f.');
    }
    out.push({ eu, stock });
  }
  return out.sort((a, b) => a.eu - b.eu);
}

/* ── editProduct ──────────────────────────────────────────────────────── */
async function editProduct(id = null) {
  const p = id ? productsCache.find(x => String(x.id) === String(id)) : null;
  if (id && !p) {
    toast('\u0627\u06cc\u0646 \u0645\u062d\u0635\u0648\u0644 \u062f\u0631 \u0641\u0647\u0631\u0633\u062a \u0641\u0639\u0644\u06cc \u0648\u062c\u0648\u062f \u0646\u062f\u0627\u0631\u062f\u061b \u0645\u0645\u06a9\u0646 \u0627\u0633\u062a \u062d\u0630\u0641 \u0634\u062f\u0647 \u0628\u0627\u0634\u062f', 'err');
    renderProducts();
    return;
  }
  const galleryContent = p && p.gallery
    ? String(p.gallery).split(/[|\n,]+/).filter(Boolean).join('\n')
    : '';
  const pFeats = invJson(p && p.feats);
  const pSpecs = invJson(p && p.specs);
  const featsContent = Array.isArray(pFeats) ? pFeats.join('\n') : '';
  const specsContent = (pSpecs && typeof pSpecs === 'object' && !Array.isArray(pSpecs))
    ? Object.entries(pSpecs).map(([k, v]) => k + ': ' + v).join('\n')
    : '';

  let productId = p ? p.id : null;
  if (!p) {
    const r = await api('admin_product_reserve_id', {});
    if (!r || !r.ok || !r.id) {
      toast((r && (r.message || r.error)) || '\u062f\u0631\u06cc\u0627\u0641\u062a \u0634\u0646\u0627\u0633\u0647\u0654 \u0645\u062d\u0635\u0648\u0644 \u0646\u0627\u0645\u0648\u0641\u0642 \u0628\u0648\u062f', 'err');
      return;
    }
    productId = r.id;
  }

  const catOptions = CAT_SLUGS
    .map(c => '<option value="' + c + '"' + (p && p.cat === c ? ' selected' : '') + '>' + esc(CAT_LABELS[c] || c) + '</option>')
    .join('');
  const sizeRangeLabel = '\u0633\u0627\u06cc\u0632\u0647\u0627 (' + INV_EU_MIN + ' \u062a\u0627 ' + INV_EU_MAX + ')';

  openModal(p ? '\u0648\u06cc\u0631\u0627\u06cc\u0634 \u0645\u062d\u0635\u0648\u0644' : '\u0627\u0641\u0632\u0648\u062f\u0646 \u0645\u062d\u0635\u0648\u0644',
  '<form id="productForm" class="form-grid">' +
  '<div class="form-grid--2">' +
    '<div class="field"><label for="pm_id">\u0634\u0646\u0627\u0633\u0647</label>' +
      '<input id="pm_id" value="' + esc(productId) + '" dir="ltr" maxlength="64" spellcheck="false" aria-describedby="pm_id_help">' +
      '<p class="help-block" id="pm_id_help">در نشانی صفحهٔ محصول استفاده می‌شود؛ حروف، رقم، خط تیره و زیرخط.</p></div>' +
    '<div class="field"><label for="pm_cat">\u062f\u0633\u062a\u0647</label>' +
      '<select id="pm_cat">' + catOptions + '</select></div>' +
  '</div>' +
  '<div class="field"><label for="pm_name">\u0646\u0627\u0645</label>' +
    '<input id="pm_name" value="' + (p ? esc(p.name) : '') + '" required></div>' +
  '<div class="field"><label for="pm_sub">\u062a\u0648\u0636\u06cc\u062d \u06a9\u0648\u062a\u0627\u0647</label>' +
    '<input id="pm_sub" value="' + (p ? esc(p.sub) : '') + '"></div>' +
  '<div class="field"><label for="pm_desc">\u062a\u0648\u0636\u06cc\u062d</label>' +
    '<textarea id="pm_desc" rows="3">' + (p ? esc(p.desc) : '') + '</textarea></div>' +
  '<div class="form-grid--2">' +
    '<div class="field"><label for="pm_price">\u0642\u06cc\u0645\u062a</label>' +
      '<input id="pm_price" type="number" value="' + (p ? esc(p.price == null ? '' : p.price) : '') + '" required></div>' +
    '<div class="field"><label for="pm_old">\u0642\u06cc\u0645\u062a \u0642\u062f\u06cc\u0645</label>' +
      '<input id="pm_old" type="number" value="' + (p ? esc(p.old_price == null ? 0 : p.old_price) : 0) + '"></div>' +
  '</div>' +
  '<div class="form-grid--2">' +
    '<div class="field"><label for="pm_heel">\u067e\u0627\u0634\u0646\u0647 mm</label>' +
      '<input id="pm_heel" type="number" value="' + (p ? esc(p.heel == null ? 0 : p.heel) : 0) + '"></div>' +
    '<div class="field"><label for="pm_eta">ETA \u0631\u0648\u0632</label>' +
      '<input id="pm_eta" type="number" value="' + (p ? esc(p.eta == null ? 4 : p.eta) : 4) + '"></div>' +
  '</div>' +
  '<div class="field"><label><input type="checkbox" id="pm_isnew"' +
    (p && Number(p.is_new) === 1 ? ' checked' : '') + '> \u0645\u062d\u0635\u0648\u0644 \u062c\u062f\u06cc\u062f</label></div>' +

  '<div class="field field--full inv-block">' +
    '<label class="lbl-gold">\u0645\u0648\u062c\u0648\u062f\u06cc \u0648 \u0631\u0646\u06af\u200c\u0628\u0646\u062f\u06cc</label>' +
    '<datalist id="invSwatchList">' + INV_SWATCH_KEYS.map(k => '<option value="' + k + '"></option>').join('') + '</datalist>' +
    '<div class="inv-sub"><b>\u0631\u0646\u06af\u200c\u0647\u0627</b>' +
      '<div id="invColors" class="inv-list"></div>' +
      '<button type="button" class="btn btn--ghost btn--sm" id="addColorRow">+ \u0627\u0641\u0632\u0648\u062f\u0646 \u0631\u0646\u06af</button></div>' +
    '<div class="inv-sub"><b>' + sizeRangeLabel + '</b>' +
      '<div id="invSizes" class="inv-list"></div>' +
      '<button type="button" class="btn btn--ghost btn--sm" id="addSizeRow">+ \u0627\u0641\u0632\u0648\u062f\u0646 \u0633\u0627\u06cc\u0632</button></div>' +
    '<p class="help-block">' +
      '\u0645\u0648\u062c\u0648\u062f\u06cc \u0628\u0631\u0627\u06cc \u0647\u0631 \u0633\u0627\u06cc\u0632 \u0645\u0633\u062a\u0642\u0644 \u0627\u0633\u062a. ' +
      '\u0633\u0627\u06cc\u0632\u06cc \u06a9\u0647 \u0645\u0648\u062c\u0648\u062f\u06cc \u0622\u0646 \u0635\u0641\u0631 \u06cc\u0627 \u062e\u0627\u0644\u06cc \u0628\u0645\u0627\u0646\u062f \u062f\u0631 \u0633\u0627\u06cc\u062a \u00ab\u0646\u0627\u0645\u0648\u062c\u0648\u062f\u00bb \u0646\u0645\u0627\u06cc\u0634 \u062f\u0627\u062f\u0647 \u0645\u06cc\u200c\u0634\u0648\u062f \u0648 \u0642\u0627\u0628\u0644 \u0633\u0641\u0627\u0631\u0634 \u0646\u06cc\u0633\u062a. ' +
      '\u06a9\u0644\u06cc\u062f \u0631\u0646\u06af \u0628\u0627\u06cc\u062f \u06cc\u06a9\u06cc \u0627\u0632 \u0631\u0646\u06af\u200c\u0647\u0627\u06cc \u067e\u0627\u0644\u062a \u0628\u0627\u0634\u062f (\u067e\u06cc\u0634\u0646\u0647\u0627\u062f \u0645\u06cc\u200c\u0634\u0648\u062f)\u060c \u0648\u06af\u0631\u0646\u0647 \u062f\u0631 \u0633\u0627\u06cc\u062a \u0628\u0647 \u0645\u0634\u06a9\u06cc \u0646\u0645\u0627\u06cc\u0634 \u062f\u0627\u062f\u0647 \u0645\u06cc\u200c\u0634\u0648\u062f.' +
    '</p>' +
  '</div>' +

  '<div class="field field--full">' +
    '<label class="lbl-gold">\u0648\u06cc\u0698\u06af\u06cc\u200c\u0647\u0627 \u0648 \u0645\u0634\u062e\u0635\u0627\u062a</label>' +
    '<div class="form-grid--2">' +
      '<div class="field"><label for="pm_feats">\u0648\u06cc\u0698\u06af\u06cc\u200c\u0647\u0627 (\u0647\u0631 \u062e\u0637 \u06cc\u06a9 \u0645\u0648\u0631\u062f)</label>' +
        '<textarea id="pm_feats" rows="4" placeholder="\u0686\u0631\u0645 \u0637\u0628\u06cc\u0639\u06cc \u0627\u06cc\u062a\u0627\u0644\u06cc\u0627\u06cc\u06cc&#10;\u062f\u0648\u062e\u062a \u062f\u0633\u062a\u200c\u062f\u0648\u0632">' + esc(featsContent) + '</textarea></div>' +
      '<div class="field"><label for="pm_specs">\u0645\u0634\u062e\u0635\u0627\u062a (\u0647\u0631 \u062e\u0637 \u06a9\u0644\u06cc\u062f: \u0645\u0642\u062f\u0627\u0631)</label>' +
        '<textarea id="pm_specs" rows="4" dir="ltr" spellcheck="false" class="mono-area" placeholder="Upper: leather&#10;heel height: 85 mm">' + esc(specsContent) + '</textarea></div>' +
    '</div>' +
    '<p class="help-block">\u0648\u06cc\u0698\u06af\u06cc\u200c\u0647\u0627 \u0648 \u0645\u0634\u062e\u0635\u0627\u062a \u062f\u0631 \u0635\u0641\u062d\u0647 \u0645\u062d\u0635\u0648\u0644 \u0648 \u062f\u0627\u062f\u0647\u200c\u0647\u0627\u06cc \u0633\u0627\u062e\u062a\u200c\u06cc\u0627\u0641\u062a\u0647 \u06af\u0648\u06af\u0644 \u0646\u0645\u0627\u06cc\u0634 \u062f\u0627\u062f\u0647 \u0645\u06cc\u200c\u0634\u0648\u0646\u062f. \u0627\u06af\u0631 \u062e\u0627\u0644\u06cc \u0628\u0645\u0627\u0646\u0646\u062f\u060c \u0645\u0642\u062f\u0627\u0631 \u0642\u0628\u0644\u06cc \u0645\u062d\u0635\u0648\u0644 \u062f\u0633\u062a\u200c\u0646\u062e\u0648\u0631\u062f\u0647 \u062d\u0641\u0637 \u0645\u06cc\u200c\u0634\u0648\u062f.</p>' +
  '</div>' +

  '<div class="field field--full">' +
    '<label class="lbl-gold">\u062a\u0635\u0627\u0648\u06cc\u0631 \u0645\u062d\u0635\u0648\u0644</label>' +
    '<div class="upload-zone" id="uploadZone" role="button" tabindex="0" aria-label="' + esc('\u0622\u067e\u0644\u0648\u062f \u062a\u0635\u0648\u06cc\u0631') + '">' +
      '<input type="file" id="uploadInput" accept="image/jpeg,image/png,image/gif,image/webp" multiple>' +
      '<div class="upload-zone-text">' +
        '<b>\u0622\u067e\u0644\u0648\u062f \u062a\u0635\u0648\u06cc\u0631</b> \u2014 ' +
        '\u06a9\u0644\u06cc\u06a9 \u06a9\u0646\u06cc\u062f \u06cc\u0627 \u0641\u0627\u06cc\u0644 \u0631\u0627 \u0627\u06cc\u0646\u062c\u0627 \u0631\u0647\u0627 \u06a9\u0646\u06cc\u062f' +
        '<small>JPG \u00b7 PNG \u00b7 GIF \u00b7 WebP \u2014 ' +
          /* The real ceiling, from admin_upload_effective_max(). When php.ini is
             tighter than the code's own constant this reads lower than ۱۰
             مگابایت, which is the point: the label has to name the limit the
             upload will actually hit, or the operator debugs the endpoint when
             the cause is the host configuration. */
          UPLOAD_LIMIT_LABEL + ' \u2014 \u062a\u0628\u062f\u06cc\u0644 \u062e\u0648\u062f\u06a9\u0627\u0631 \u0628\u0647 WebP 1024\u00d71024</small>' +
      '</div>' +
    '</div>' +
    '<div class="upload-progress" id="uploadProgress"></div>' +
    '<label for="pm_gallery" class="help-block">\u0641\u0647\u0631\u0633\u062a \u0622\u062f\u0631\u0633 \u062a\u0635\u0627\u0648\u06cc\u0631 (\u0647\u0631 \u062e\u0637 \u06cc\u06a9 \u062a\u0635\u0648\u06cc\u0631 \u2014 \u0627\u0648\u0644\u06cc\u0646 \u0645\u0648\u0631\u062f \u062a\u0635\u0648\u06cc\u0631 \u0627\u0635\u0644\u06cc \u06a9\u0627\u0631\u062a):</label>' +
    '<textarea id="pm_gallery" rows="4" dir="ltr" spellcheck="false" class="mono-area" placeholder="<?= $uploadBaseHtml ?>/notte-abc123.webp">' + esc(galleryContent) + '</textarea>' +
    '<div class="gallery-hint">\u0647\u0631 \u062e\u0637 \u06cc\u06a9 \u062a\u0635\u0648\u06cc\u0631 \u0622\u067e\u0644\u0648\u062f\u0634\u062f\u0647 \u06cc\u0627 \u06cc\u06a9 URL \u06a9\u0627\u0645\u0644 \u00b7 <b>\u0627\u0648\u0644\u06cc\u0646 \u0645\u0648\u0631\u062f</b> \u062a\u0635\u0648\u06cc\u0631 \u0627\u0635\u0644\u06cc \u06a9\u0627\u0631\u062a \u0645\u062d\u0635\u0648\u0644 \u0627\u0633\u062a.<br>\u0627\u06af\u0631 \u0647\u06cc\u0686 \u062a\u0635\u0648\u06cc\u0631\u06cc \u0622\u067e\u0644\u0648\u062f \u0646\u0634\u0648\u062f\u060c \u0633\u0627\u06cc\u062a \u0628\u0647\u200c\u0637\u0648\u0631 \u062e\u0648\u062f\u06a9\u0627\u0631 \u067e\u0644\u0627\u06a9 \u0637\u0644\u0627\u06cc\u06cc \u0645\u0632\u0648\u0646 \u0631\u0627 \u0628\u0627 silhouette \u062f\u0633\u062a\u0647\u0654 \u0645\u062d\u0635\u0648\u0644 \u0631\u0633\u0645 \u0645\u06cc\u200c\u06a9\u0646\u062f.</div>' +
    '<div class="gallery-preview" id="pm_gallery_preview"></div>' +
  '</div>' +
  '</form>',
  '<button type="button" class="btn btn--ghost" data-close-modal>\u0627\u0646\u0635\u0631\u0627\u0641</button>' +
  '<button type="button" class="btn btn--gold" id="saveProduct">\u0630\u062e\u06cc\u0631\u0647</button>');

  const ta   = $('#pm_gallery');
  const prev = $('#pm_gallery_preview');
  const prog = $('#uploadProgress');

  const pColors = invJson(p && p.colors);
  const pSizes  = invJson(p && p.sizes);
  (Array.isArray(pColors) ? pColors : []).forEach(c => addColorRow(c.key, c.name));
  (Array.isArray(pSizes) ? pSizes : []).forEach(s => addSizeRow(s.eu, s.stock));
  const addC = $('#addColorRow'), addS = $('#addSizeRow');
  if (addC) addC.addEventListener('click', () => addColorRow());
  if (addS) addS.addEventListener('click', () => addSizeRow());
  if (!(Array.isArray(pSizes) && pSizes.length) && $('#invSizes')) addSizeRow();

  if (ta && prev) {
    ta.addEventListener('input', () => renderGalleryPreview(ta, prev));
    renderGalleryPreview(ta, prev);
  }
  if (ta) {
    bindUploadZone(productId, ta, prev, prog);
    bindGalleryActions(prev, ta, productId, prog);
  }

  $('#saveProduct').addEventListener('click', async () => {
    const saveButton = $('#saveProduct');
    if (!saveButton) return;
    saveButton.disabled = true;

    const data = {
    id: $('#pm_id').value.trim(),
    mode: p ? 'update' : 'create',
      name: $('#pm_name').value,
      cat: $('#pm_cat').value,
      sub: $('#pm_sub').value,
      desc: $('#pm_desc').value,
      price: toInt($('#pm_price').value, 0),
      old_price: toInt($('#pm_old').value, 0),
      heel: toInt($('#pm_heel').value, 0),
      eta: toInt($('#pm_eta').value, 4),
      is_new: $('#pm_isnew').checked ? 1 : 0
    };

    const galleryRaw = ta ? ta.value.trim() : '';
    if (galleryRaw) {
      data.gallery = galleryRaw.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
    }

    let invColors, invSizes;
    try {
      invColors = readColors();
      invSizes  = readSizes();
    } catch (err) {
      toast(err.message, 'err');
      if (saveButton.isConnected) saveButton.disabled = false;
      return;
    }

    const hadColors = Array.isArray(pColors) && pColors.length > 0;
    const hadSizes  = Array.isArray(pSizes) && pSizes.length > 0;
    const dropColors = hadColors && invColors.length === 0;
    const dropSizes  = hadSizes && invSizes.length === 0;
    if (dropColors || dropSizes) {
      const what = dropColors && dropSizes
        ? '\u0631\u0646\u06af\u200c\u0647\u0627 \u0648 \u0633\u0627\u06cc\u0632\u200c\u0647\u0627'
        : (dropColors ? '\u0631\u0646\u06af\u200c\u0647\u0627' : '\u0633\u0627\u06cc\u0632\u200c\u0647\u0627');
      const msg = '\u0627\u06cc\u0646 \u0645\u062d\u0635\u0648\u0644 \u062f\u0631 \u062d\u0627\u0644 \u062d\u0627\u0636\u0631 ' + what +
        ' \u062f\u0627\u0631\u062f. \u0628\u0627 \u0630\u062e\u06cc\u0631\u0647\u060c \u0647\u0645\u0647 \u0622\u0646\u200c\u0647\u0627 \u062d\u0630\u0641 \u0645\u06cc\u200c\u0634\u0648\u0646\u062f \u0648 \u0645\u062d\u0635\u0648\u0644 \u062a\u0627 \u0632\u0645\u0627\u0646\u06cc \u06a9\u0647 \u062f\u0648\u0628\u0627\u0631\u0647 \u062a\u0639\u0631\u06cc\u0641 \u0646\u0634\u0648\u062f \u062f\u0631 \u0633\u0627\u06cc\u062a \u0646\u0627\u0645\u0648\u062c\u0648\u062f \u062e\u0648\u0627\u0647\u062f \u0628\u0648\u062f. \u0627\u062f\u0627\u0645\u0647 \u0645\u06cc\u200c\u062f\u0647\u06cc\u062f\u061f';
      if (!confirm(msg)) {
        if (saveButton.isConnected) saveButton.disabled = false;
        return;
      }
    }
    data.colors = invColors;
    data.sizes  = invSizes;

    const featsRaw = $('#pm_feats') ? $('#pm_feats').value : '';
    if (featsRaw.trim()) {
      data.feats = featsRaw.split('\n').map(s => s.trim()).filter(Boolean);
    }
    const specsRaw = $('#pm_specs') ? $('#pm_specs').value : '';
    if (specsRaw.trim()) {
      const specs = {};
      for (const line of specsRaw.split('\n')) {
        const t = line.trim();
        if (!t) continue;
        const i = t.indexOf(':');
        if (i < 1) continue;
        const k = t.slice(0, i).trim();
        if (k) specs[k] = t.slice(i + 1).trim();
      }
      if (Object.keys(specs).length) data.specs = specs;
    }

    try {
      /* A changed id is renamed first, as its own request.
         admin_product_save looks the record up BY id, so saving a form whose id
         was edited in place would not find it and would silently create a
         second product — the exact failure that made the field read-only. Two
         calls, each atomic on its own, and the rename is the one that can be
         refused for a reason the operator needs to read (the id is in order
         history, or the new id is taken). */
      const originalId = p ? String(p.id || '') : '';
      if (data.mode === 'update' && originalId && data.id !== originalId) {
        const rn = await api('admin_product_rename', { from: originalId, to: data.id });
        if (!rn.ok) {
          toast(apiErrorText(rn), 'err');
          if (saveButton.isConnected) saveButton.disabled = false;
          return;
        }
        /* The gallery and inventory editors were bound to the old id. Every
           later call in this session keys off `data.id`, so the rename is
           complete; nothing else needs rebinding. */
        if (p) p.id = data.id;
      }

      const r = await api('admin_product_save', data);
      if (r.ok) {
        toast(r.message || '\u0630\u062e\u06cc\u0631\u0647 \u0634\u062f', r.sizes_skipped ? 'err' : 'ok');
        if (r.sizes_skipped) {
          toast(r.warning || '\u0645\u0648\u062c\u0648\u062f\u06cc \u0633\u0627\u06cc\u0632\u0647\u0627 \u062a\u063a\u06cc\u06cc\u0631 \u0646\u06a9\u0631\u062f', 'err');
        }
        closeModal();
        renderProducts();
      } else {
        toast(apiErrorText(r), 'err');
      }
    } finally {
      if (saveButton.isConnected) saveButton.disabled = false;
    }
  });
}

async function deleteProduct(id) {
  if (!confirm('\u063a\u06cc\u0631\u0641\u0639\u0627\u0644 \u0634\u0648\u062f\u061f')) return;
  const r = await api('admin_product_delete', { id });
  toast(r.ok ? '\u063a\u06cc\u0631\u0641\u0639\u0627\u0644 \u0634\u062f' : apiErrorText(r), r.ok ? 'ok' : 'err');
  if (r.ok) renderProducts();
}

/* ── Users & appointments ─────────────────────────────────────────────── */
async function renderUsers(version = pageRenderVersion) {
  const r = await api('admin_users');
  if (version !== pageRenderVersion) return;
  if (!r.ok) { toast(apiErrorText(r), 'err'); return; }
  usersCache = Array.isArray(r.users) ? r.users : [];
  $('#pageContent').innerHTML = '<div class="panel">' +
    '<div class="panel-head"><h2>\u06a9\u0627\u0631\u0628\u0631\u0627\u0646 (' + fmt(usersCache.length) + ')</h2></div>' +
    '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
    '<th scope="col">\u062a\u0644\u0641\u0646</th>' +
    '<th scope="col">\u0646\u0627\u0645</th>' +
    '<th scope="col">\u0633\u0641\u0627\u0631\u0634\u0627\u062a</th>' +
    '<th scope="col">\u062e\u0631\u06cc\u062f</th>' +
    '<th scope="col">\u0639\u0636\u0648\u06cc\u062a</th>' +
    '</tr></thead><tbody>' +
    (usersCache.map(u => '<tr>' +
      '<td class="mono" dir="ltr">' + esc(u.phone) + '</td>' +
      '<td>' + esc(u.name || '\u2014') + '</td>' +
      '<td class="mono">' + fmt(u.order_count || 0) + '</td>' +
      '<td class="mono">' + money(u.total_spent || 0) + '</td>' +
      '<td class="mono">' + dt(u.created_at) + '</td>' +
      '</tr>').join('') || '<tr><td colspan="5" class="empty-state">\u06a9\u0627\u0631\u0628\u0631\u06cc \u0646\u06cc\u0633\u062a</td></tr>') +
    '</tbody></table></div>' +
    '</div>';
}

async function renderAppointments(version = pageRenderVersion) {
  const r = await api('admin_appointments');
  if (version !== pageRenderVersion) return;
  if (!r.ok) { toast(apiErrorText(r), 'err'); return; }
  const list = Array.isArray(r.appointments) ? r.appointments : [];
  const statusOpts = ['pending', 'confirmed', 'cancelled']
    .map(s => '<option value="' + s + '"' + (s === 'pending' ? ' selected' : '') + '>' + s + '</option>')
    .join('');
  $('#pageContent').innerHTML = '<div class="panel">' +
    '<div class="panel-head"><h2>\u0648\u0642\u062a\u200c\u0647\u0627 (' + fmt(list.length) + ')</h2></div>' +
    '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
    '<th scope="col">\u062a\u0627\u0631\u06cc\u062e</th>' +
    '<th scope="col">\u0633\u0627\u0639\u062a</th>' +
    '<th scope="col">\u0646\u0627\u0645</th>' +
    '<th scope="col">\u062a\u0644\u0641\u0646</th>' +
    '<th scope="col">\u0648\u0636\u0639\u06cc\u062a</th>' +
    '</tr></thead><tbody>' +
    (list.map(a => {
      const opts = ['pending', 'confirmed', 'cancelled'].map(s =>
        '<option value="' + s + '"' + (a.status === s ? ' selected' : '') + '>' + s + '</option>'
      ).join('');
      return '<tr>' +
        '<td class="mono">' + dt(a.date) + '</td>' +
        '<td class="mono">' + esc(a.time) + '</td>' +
        '<td>' + esc(a.name) + '</td>' +
        '<td class="mono" dir="ltr">' + esc(a.phone) + '</td>' +
        '<td><select class="status-sel" data-aid="' + esc(a.id) + '" aria-label="' +
          esc('\u062a\u063a\u06cc\u06cc\u0631 \u0648\u0636\u0639\u06cc\u062a \u0648\u0642\u062a ' + a.id) + '">' +
          opts + '</select></td>' +
        '</tr>';
    }).join('') || '<tr><td colspan="5" class="empty-state">\u0648\u0642\u062a\u06cc \u0646\u06cc\u0633\u062a</td></tr>') +
    '</tbody></table></div>' +
    '</div>';
}

loadPage('dashboard');
</script>
<?php endif; ?>
</body></html>
