<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/health.php
   خودآزمایی نصب روی هاست اشتراکی (فقط با کلیدِ همان فایل config باز می‌شود)
   ─────────────────────────────────────────────────────────────────────
   روش:  https://example.com/api/health.php?key=<otp.service_key>
   گزارش: PHP، curl، mail()، نوشتن در api/data، تنظیمات ملی پیامک و زرین‌پال.
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

$cfg = ae_config();
$key = (string) ($_GET['key'] ?? '');
if ($cfg['otp']['service_key'] === '' || !hash_equals((string) $cfg['otp']['service_key'], $key)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'message' => 'کلید نامعتبر است — ?key=<service_key> را اضافه کنید.'], JSON_UNESCAPED_UNICODE));
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dataDir = ae_data_dir();
$out = [
    'ok'   => true,
    'php'  => PHP_VERSION,
    'curl' => function_exists('curl_init'),
    'json' => function_exists('json_encode'),
    'mb'   => function_exists('mb_strlen'),
    'mail' => function_exists('mail'),
    'writable' => is_writable($dataDir),
    'data_dir' => $dataDir,
    'config_loaded' => is_file(dirname(__DIR__) . '/config.php') || is_file(dirname(dirname(__DIR__)) . '/config.php'),
    'otp_service_key_set' => $cfg['otp']['service_key'] !== '',
    'zarinpal_merchant_set' => ($cfg['zarinpal']['merchant_id'] ?? '') !== '',
    'zarinpal_sandbox' => (bool) ($cfg['zarinpal']['sandbox'] ?? false),
    'concierge_email' => $cfg['concierge_email'],
    'callback_guess' => ae_zarinpal_callback_url(),
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? null,
];

/* آزمون اتصال به ملی پیامک (بدون ارسال واقعی؛ فقط DNS/TLS) */
$probe = @get_headers(rtrim($cfg['otp']['endpoint_base'], '/') . '/', false, stream_context_create([
    'http' => ['timeout' => 6, 'ignore_errors' => true],
]));
$out['meli_reachable'] = $probe !== false;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
