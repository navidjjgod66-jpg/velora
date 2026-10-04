<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/session.php
   وضعیت نشست کاربر (برای رفرش صفحه) + خروج
   ─────────────────────────────────────────────────────────────────────
   ورودی : { "action": "get" | "logout" }
   خروجی : { ok:true, auth:true, phone:"0912…", admin:true }  یا  { ok:true, auth:false }
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();

$in = ae_input();
$action = (string) ($in['action'] ?? 'get');

ae_session_start();
$phone = ae_auth_phone();

if ($action === 'logout') {
    unset($_SESSION['ae_phone'], $_SESSION['ae_authat'], $_SESSION['ae_pending_phone']);
    @session_destroy();
    ae_ok(['auth' => false]);
}

$lastRef = isset($_SESSION['ae_last_ref']) ? (string) $_SESSION['ae_last_ref'] : null;
ae_ok([
    'auth'      => $phone !== null,
    'phone'     => $phone,
    /* فقط یک پرچم بولی — شمارهٔ مدیر هرگز به مرورگر نمی‌رود */
    'admin'     => ae_is_admin(),
    'last_ref'  => $lastRef,
    'gateway'   => ['otp' => ae_config()['otp']['service_key'] !== '',
                    'pay' => ae_zarinpal_merchant() !== ''],
]);
