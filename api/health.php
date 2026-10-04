<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/health.php — خودآزمایی نصب (hardened v2)
   ─────────────────────────────────────────────────────────────────────
   تغییرات امنیتی:
     • کلید دیگر در query string نمی‌آید (لاگ وب‌سرور و Referer آن را نگه
       می‌داشت). روش جدید: وارد شدن به پنل مدیر، سپس
         POST /api/health.php   (با کوکی نشست مدیر)
   • هیچ مسیر مطلق، نسخهٔ کامل PHP، SERVER_SOFTWARE یا ایمیل تنظیم‌شده
     افشا نمی‌شود — فقط بولین/سطحی.
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();      // POST + بررسی Origin
ae_require_admin();      // فقط مدیر واردشده

$cfg     = ae_config();
$dataDir = ae_data_dir();

$out = [
    'ok'              => true,
    'php_major'       => substr(PHP_VERSION, 0, 3),
    'curl'            => function_exists('curl_init'),
    'mb'              => function_exists('mb_strlen'),
    'mail'            => function_exists('mail'),
    'writable'        => is_writable($dataDir),
    'config_loaded'   => $cfg['concierge_email'] !== '' || $cfg['otp']['service_key'] !== '',
    'otp_key_set'     => $cfg['otp']['service_key'] !== '',
    'zp_merchant_set' => ae_zarinpal_merchant() !== '',
    'zp_sandbox'      => (bool) ($cfg['zarinpal']['sandbox'] ?? false),
    'email_set'       => $cfg['concierge_email'] !== '',
];

/* آزمون اتصال به ملی پیامک (بدون ارسال واقعی؛ فقط DNS/TLS) */
$probe = @get_headers(rtrim((string) $cfg['otp']['endpoint_base'], '/') . '/', false,
    stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]));
$out['meli_reachable'] = $probe !== false;

ae_out($out);
