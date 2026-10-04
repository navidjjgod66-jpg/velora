<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/bootstrap.php
   لایهٔ مشترک: پیکربندی · JSON · کوکی نشست · احراز مبدأ (Origin) ·
   گاری‌نوشت · ارسال پیامک ملی · درگاه زرین‌پال · ذخیرهٔ سفارش
   ─────────────────────────────────────────────────────────────────────
   هدف: اجرای کامل روی PHP هاست اشتراکی (PHP 7.4+) بدون Composer و framework،
        فقط curl + json + file-system.
   هیچ رمزی در این پروژه ساخته نمی‌شود؛ رمز را «ملی پیامک» تولید و پیامک
   می‌کند و ما فقط پاسخ او را برای ارزیابی کاربر در سرور نگه می‌داریم.
   ═══════════════════════════════════════════════════════════════════════ */

declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

/* ─── پیکربندی ─────────────────────────────────────────────────────────── */
function ae_config(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $root = dirname(__DIR__);            // ریشهٔ پروژه (جای index.html)
    $paths = [
        $root . '/config.php',                  // کنار index.html (حالت عادی هاست)
        dirname($root) . '/config.php',         // یک سطح بالاتر (خارج از public_html)
        $root . '/api/config.php',              // داخل پوشهٔ api
    ];
    $cfg = [];
    foreach ($paths as $p) {
        if (is_readable($p)) { $cfg = require $p; break; }
    }
    if (!is_array($cfg)) $cfg = [];

    $def = [
        'otp' => ['service_key' => '', 'endpoint_base' => 'https://console.melipayamak.com/api/send/otp/'],
        'zarinpal' => [
            'merchant_id' => '', 'sandbox' => false,
            'api_base' => 'https://api.zarinpal.com', 'webpage_url' => '',
            'toman_to_rial' => 10, 'description' => 'خانهٔ اُرِل — سفارش آنلاین',
        ],
        'concierge_email' => '', 'email_from' => '',
        'otp_ttl_seconds' => 180, 'otp_resend_after' => 60, 'otp_max_attempts' => 5,
        'otp_rate_window' => 3600, 'otp_rate_max' => 6,
        /* سقف هزینهٔ SMS: محدودیت هر IP (دور زدن با چرخش شماره) + سقف سراسری روزانه */
        'otp_ip_window' => 3600, 'otp_ip_max' => 12, 'otp_daily_total' => 300,
        'data_dir' => $root . '/api/data',

        /* ─── پنل مدیریت (admin) ────────────────────────────────────────────
           شماره‌های مجاز فقط در سمت سرور نگه داشته می‌شوند و هرگز در
           جاوااسکریپت یا HTML به مرورگر فرستاده نمی‌شوند. */
        'admin' => [
            'phones'       => ['09386130082'],
            'uploads_dir'  => '',            /* خالی = <root>/uploads */
            'max_upload_mb'=> 8,
            'max_products' => 120,
        ],
        /* کد آزمایشی ثابت — فقط برای تست محلی. روی هاست واقعی false بماند. */
        'dev_otp' => false,
    ];
    $cache = array_replace_recursive($def, $cfg);
    if (empty($cache['data_dir'])) $cache['data_dir'] = $root . '/api/data';
    return $cache;
}

/* ─── پوشهٔ داده + محافظ Apache ──────────────────────────────────────────── */
function ae_data_dir(string $sub = ''): string
{
    $base = rtrim((string) ae_config()['data_dir'], '/');
    if (!is_dir($base)) @mkdir($base, 0700, true);
    $guard = "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', $guard);
    if (!is_file($base . '/index.html')) @file_put_contents($base . '/index.html', '');
    if ($sub === '') return $base;
    $dir = $base . '/' . trim($sub, '/');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/* ─── JSON ورودی / خروجی ────────────────────────────────────────────────── */
function ae_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) $data = [];
    if (!$data && $_POST) $data = $_POST;
    return $data;
}

function ae_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ae_ok(array $extra = []): void { ae_out(array_merge(['ok' => true], $extra)); }

function ae_err(string $message, string $code = 'error', int $status = 400, array $extra = []): void
{
    ae_out(array_merge(['ok' => false, 'error' => $code, 'message' => $message], $extra), $status);
}

/* ─── Origin مجاز (سایت روی همان هاست) ─────────────────────────────────── */
function ae_origin_allowed(?string $origin): bool
{
    if ($origin === null || $origin === '' || $origin === 'null') return true; // same-origin / curl
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    if ($host === '') return false;
    $server = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($server === '') return false;
    if ($server === 'localhost' || substr($server, -strlen('.localhost')) === '.localhost') return true;
    if ($host === $server) return true;
    if (substr($server, -strlen('.' . $host)) === '.' . $host) return true;   // example.com ↔ www.example.com
    if (substr($host, -strlen('.' . $server)) === '.' . $server) return true;
    return false;
}

function ae_guard_request(): void
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : null;
    header('Vary: Origin');
    if (ae_origin_allowed($origin)) {
        if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') ae_err('این endpoint فقط با POST فراخوانی می‌شود.', 'method', 405);
    if (!$origin || !ae_origin_allowed($origin)) ae_err('منبع درخواست مجاز نیست.', 'origin', 403);
}

/* ─── کوکی نشست (HttpOnly · SameSite=Lax) ───────────────────────────────── */
function ae_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = ae_data_dir('tmp');
    if (is_dir($dir) && is_writable($dir)) @ini_set('session.save_path', $dir);
    @ini_set('session.use_strict_mode', '1');
    session_name('ae_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

function ae_uid(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    /* salt از کلید سرویس: سطل‌ها قابل پیش‌بینی/رشته‌بازی نمی‌مانند؛
       اگر کلید نبود از مسیر داده به‌عنوان آنتروپی بومی استفاده می‌شود. */
    $cfg = ae_config();
    $salt = (string) ($cfg['otp']['service_key'] ?? '');
    if ($salt === '') $salt = (string) ($cfg['data_dir'] ?? 'aurelle');
    return substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 32);
}

/* ─── قفل فایل (نوشتن امن روی هاست اشتراکی) ─────────────────────────────── */
function ae_lock(string $name, float $timeout = 8.0)
{
    $file = ae_data_dir('locks') . '/' . (string) preg_replace('/[^a-z0-9_\-]/i', '', $name) . '.lock';
    $fp = @fopen($file, 'c+');
    if (!$fp) return null;
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        if (@flock($fp, LOCK_EX | LOCK_NB)) return $fp;
        usleep(60000);
    }
    fclose($fp);
    return null;
}
function ae_unlock($fp): void { if ($fp) { @flock($fp, LOCK_UN); @fclose($fp); } }

function ae_read_json(string $file): ?array
{
    if (!is_file($file)) return null;
    $fp = @fopen($file, 'r');
    if (!$fp) return null;
    @flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    @flock($fp, LOCK_UN); @fclose($fp);
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : null;
}

function ae_write_json(string $file, array $data): bool
{
    clearstatcache(true, $file);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return @file_put_contents($file, $json, LOCK_EX) !== false;
}

/* ─── پاک‌سازی فایل‌های کهنه ─────────────────────────────────────────────── */
function ae_gc(int $olderThanSeconds = 259200): void
{
    foreach (['otp', 'tx', 'msgs'] as $sub) {
        $it = @glob(ae_data_dir($sub) . '/*.json');
        if (!$it) continue;
        $cut = time() - $olderThanSeconds;
        foreach ($it as $f) { if (@filemtime($f) < $cut) @unlink($f); }
    }
}

/* ─── گاری‌نوشت ساده (فرم تماس) ──────────────────────────────────────────── */
function ae_throttle(string $bucket, int $max, int $window): bool
{
    $id  = (string) preg_replace('/[^a-z0-9]/i', '', $bucket);
    $key = ae_uid() . '-' . $id;
    $file = ae_data_dir('msgs') . '/' . substr(hash('sha256', $key), 0, 32) . '.json';
    $fp = ae_lock('th-' . substr(hash('sha256', $key), 0, 24));
    $st = ae_read_json($file) ?: ['n' => 0, 'first' => time()];
    if (time() - (int) $st['first'] > $window) $st = ['n' => 0, 'first' => time()];
    $st['n']++;
    $allow = $st['n'] <= $max;
    if ($allow) ae_write_json($file, $st);
    ae_unlock($fp);
    return $allow;
}

/* ─── نرمال‌سازی شمارهٔ موبایل ایران ─────────────────────────────────────── */
function ae_norm_mobile(string $raw): ?string
{
    $d = '';
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) { if ($raw[$i] >= '0' && $raw[$i] <= '9') $d .= $raw[$i]; }
    if (strlen($d) === 11 && strpos($d, '989') === 0)      $d = '0' . substr($d, 2);
    elseif (strlen($d) === 10 && $d[0] === '9')            $d = '0' . $d;
    elseif (strlen($d) === 13 && strpos($d, '0098') === 0) $d = '0' . substr($d, 4);
    return preg_match('/^09\d{9}$/', $d) ? $d : null;
}

/* ─── درخواست HTTP (curl با fallback به stream) ─────────────────────────── */
function ae_http(string $url, ?string $body = null, array $headers = [], int $timeout = 20): array
{
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $body === null ? 'GET' : 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);   /* گواهی TLS بررسی می‌شود */
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) $err = curl_error($ch);
        curl_close($ch);
        if ($res !== false) return ['status' => $code, 'body' => (string) $res, 'error' => $err];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => $body === null ? 'GET' : 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ((array) ($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) { $code = (int) $m[1]; break; }
    }
    if ($res === false) $err = 'transport-unavailable';
    return ['status' => $code, 'body' => (string) $res, 'error' => $err];
}

function ae_log(string $line): void
{
    $file = ae_data_dir('logs') . '/api.log';
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/* ═══ ملی پیامک — ارسال OTP ═══════════════════════════════════════════════
   POST https://console.melipayamak.com/api/send/otp/<service_key>
   body: {"to":"09123456789"}   →   response: {"code":"...","status":"..."}
   رمز توسط سرویس ساخته می‌شود؛ ما آن را به‌صورت hash در سرور نگه می‌داریم. */
/**
 * دو گارد هزینه‌ای SMS قبل از هر تماس با سرویس پولی:
 *  1) سقف هر IP در ساعت — جلوی SMS-pumping با چرخش شماره‌ها؛
 *  2) سقف سراسری روزانه — حتی اگر همه گاردها دور زده شوند، خرج کنترل می‌ماند.
 * فقط وقتی واقعاً درخواست ارسال ثبت شود شمارنده بالا می‌رود.
 */
function ae_sms_cap(): ?array
{
    $cfg = ae_config();
    $ipMax   = (int) ($cfg['otp_ip_max'] ?? 12);
    $ipWin   = (int) ($cfg['otp_ip_window'] ?? 3600);
    $dayMax  = (int) ($cfg['otp_daily_total'] ?? 300);

    if ($ipMax > 0 && !ae_throttle('sms-ip', $ipMax, $ipWin)) {
        return ['ok' => false, 'error' => 'rate_limited', 'retry_in' => $ipWin,
                'message' => 'از این اتصال، درخواست پیامک بیش از حد مجاز شده است — کمی دیگر تلاش کنید.'];
    }

    if ($dayMax > 0) {
        $file = ae_data_dir('msgs') . '/sms-daily.json';
        $fp = ae_lock('sms-daily');
        $st = ae_read_json($file) ?: ['d' => date('Ymd'), 'n' => 0];
        if ((string) ($st['d'] ?? '') !== date('Ymd')) $st = ['d' => date('Ymd'), 'n' => 0];
        if ((int) $st['n'] >= $dayMax) { ae_unlock($fp);
            return ['ok' => false, 'error' => 'daily_cap', 'retry_in' => 3600,
                    'message' => 'سقف روزانهٔ پیامک پر شده است — لطفاً بعداً تلاش کنید یا با واتساپ خانه تماس بگیرید.'];
        }
        $st['n']++;
        ae_write_json($file, $st);
        ae_unlock($fp);
    }
    return null; /* آزاد */
}

function ae_meli_send_otp(string $to): array
{
    $cfg = ae_config();
    $key = trim((string) ($cfg['otp']['service_key'] ?? ''));
    if ($key === '') {
        return ['ok' => false, 'error' => 'not_configured',
                'message' => 'کلید سرویس ملی پیامک در config.php تنظیم نشده است.'];
    }

    /* حالت آزمایشی — فقط وقتی کلید دقیقاً همین باشد.
       بدون کلید سرویس، هیچ راهی برای آزمودن جریان ورود نیست؛ کد ثابت
       ۱۲۳۴ همان چیزی است که رابط کاربری هم در حالت نمایشی نشان می‌دهد.
       روی هاست واقعی این کلید را نگذارید. */
    if (!empty($cfg['dev_otp'])) {
        if ($key !== 'TEST') {
            ae_log('dev_otp on but service_key is not TEST — real SMS path used');
        } else {
            return ['ok' => true, 'code' => '1234'];
        }
    }

    $url = rtrim((string) ($cfg['otp']['endpoint_base'] ?? 'https://console.melipayamak.com/api/send/otp/'), '/')
           . '/' . rawurlencode($key);
    $payload = json_encode(['to' => $to], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = ae_http($url, $payload, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
        'Accept: application/json',
    ], 20);

    $j = json_decode($res['body'], true);
    if (!is_array($j)) {
        ae_log('meli parse fail: http=' . $res['status'] . ' err=' . $res['error'] . ' body=' . substr($res['body'], 0, 300));
        return ['ok' => false, 'error' => 'sms_unreachable',
                'message' => 'اتصال به سرویس پیامک برقرار نشد — کمی دیگر تلاش کنید.'];
    }
    $digits = (string) preg_replace('/\D/', '', (string) ($j['code'] ?? ''));
    if (strlen($digits) < 4) {
        $status = trim((string) ($j['status'] ?? ''));
        ae_log('meli otp empty: http=' . $res['status'] . ' status=' . $status . ' body=' . substr($res['body'], 0, 300));
        return ['ok' => false, 'error' => 'sms_rejected',
                'message' => $status !== '' ? 'پیامک ارسال نشد: ' . $status : 'سرویس پیامک کدی بازنگرداند — دوباره تلاش کنید.'];
    }
    return ['ok' => true, 'code' => $digits];
}

/* ═══ نشست OTP در سرور ═══════════════════════════════════════════════════ */
function ae_otp_file(string $mobile): string
{
    return ae_data_dir('otp') . '/' . hash('sha256', 'otp|' . $mobile) . '.json';
}

function ae_otp_issue(string $mobile): array
{
    $cfg = ae_config();
    $fp   = ae_lock('otp-' . hash('sha256', $mobile));
    $file = ae_otp_file($mobile);
    $cur  = ae_read_json($file);
    $now  = time();

    if (is_array($cur) && !empty($cur['created'])) {
        $since = $now - (int) $cur['created'];
        if ($since < (int) $cfg['otp_resend_after']) {
            ae_unlock($fp);
            return ['ok' => false, 'error' => 'too_fast',
                    'message' => 'برای هر شماره کمی صبر لازم است.',
                    'retry_in' => (int) $cfg['otp_resend_after'] - $since];
        }
    }

    $winFile = ae_data_dir('otp') . '/win-' . hash('sha256', 'win|' . $mobile) . '.json';
    $win = ae_read_json($winFile) ?: ['n' => 0, 'first' => $now];
    if ($now - (int) $win['first'] > (int) $cfg['otp_rate_window']) $win = ['n' => 0, 'first' => $now];
    if ((int) $win['n'] >= (int) $cfg['otp_rate_max']) {
        ae_unlock($fp);
        return ['ok' => false, 'error' => 'rate_limited',
                'message' => 'تعداد درخواست‌ها بیش از حد مجاز است — بعداً دوباره تلاش کنید.'];
    }

    /* حالت آزمایشی (dev_otp + کلید TEST): بدون تماس با سرویس پولی،
       پس گارد هزینه لازم نیست. */
    if (!(ae_config()['dev_otp'] ?? false) || trim((string) (ae_config()['otp']['service_key'] ?? '')) === 'TEST') {
        /* گارد هزینه‌ای: سقف IP + سقف روزانهٔ سراسری — قبل از هر خرج واقعی */
        $cap = ae_sms_cap();
        if ($cap !== null) { ae_unlock($fp); return $cap; }
    }

    $sent = ae_meli_send_otp($mobile);
    if (!$sent['ok']) { ae_unlock($fp); return $sent; }

    $win['n'] = (int) $win['n'] + 1;
    ae_write_json($winFile, $win);

    ae_write_json($file, [
        'v'        => 1,
        'hash'     => hash('sha256', $mobile . '|' . $sent['code']),
        'len'      => strlen($sent['code']),
        'created'  => $now,
        'expires'  => $now + (int) $cfg['otp_ttl_seconds'],
        'attempts' => 0,
        'sends'    => (int) $win['n'],
    ]);
    ae_unlock($fp);
    return ['ok' => true, 'ttl' => (int) $cfg['otp_ttl_seconds'], 'resend' => (int) $cfg['otp_resend_after']];
}

function ae_otp_verify(string $mobile, string $code): array
{
    $cfg  = ae_config();
    $fp   = ae_lock('otp-' . hash('sha256', $mobile));
    $file = ae_otp_file($mobile);
    $st   = ae_read_json($file);
    if (!is_array($st)) { ae_unlock($fp); return ['ok' => false, 'error' => 'no_code', 'message' => 'ابتدا کد را دریافت کنید.']; }
    $now = time();
    if ($now > (int) $st['expires']) {
        @unlink($file); ae_unlock($fp);
        return ['ok' => false, 'error' => 'expired', 'message' => 'کد منقضی شده است — دوباره دریافتش کنید.'];
    }
    if ((int) $st['attempts'] >= (int) $cfg['otp_max_attempts']) {
        @unlink($file); ae_unlock($fp);
        return ['ok' => false, 'error' => 'too_many', 'message' => 'تلاش بیش از حد مجاز — کد جدید بگیرید.'];
    }
    $st['attempts'] = (int) $st['attempts'] + 1;
    ae_write_json($file, $st);
    $good = hash_equals((string) $st['hash'], hash('sha256', $mobile . '|' . $code));
    if (!$good) { ae_unlock($fp); return ['ok' => false, 'error' => 'bad_code', 'message' => 'کد درست نیست.']; }
    @unlink($file);
    ae_unlock($fp);
    return ['ok' => true];
}

/* ═══ زرین‌پال (Payment V4) ══════════════════════════════════════════════ */
function ae_zarinpal_base(): string
{
    $zp = ae_config()['zarinpal'];
    if (!empty($zp['sandbox'])) return 'https://sandbox.zarinpal.com';
    return rtrim((string) ($zp['api_base'] ?? 'https://api.zarinpal.com'), '/');
}

function ae_zarinpal_merchant(): string
{
    return trim((string) (ae_config()['zarinpal']['merchant_id'] ?? ''));
}

function ae_zarinpal_callback_url(): string
{
    $zp = ae_config()['zarinpal'];
    if (!empty($zp['webpage_url'])) return (string) $zp['webpage_url'];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $path  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')), '/');
    return ($https ? 'https://' : 'http://') . $host . $path . '/payment_verify.php';
}

function ae_zarinpal_request_payment(int $amountRial, string $mobile, string $email, string $desc): array
{
    $zp = ae_config()['zarinpal'];
    $merchant = ae_zarinpal_merchant();
    if ($merchant === '') {
        return ['ok' => false, 'error' => 'not_configured', 'message' => 'WebID زرین‌پال در config.php تنظیم نشده است.'];
    }
    $url  = ae_zarinpal_base() . '/pg/v4/payment/request/' . rawurlencode($merchant);
    $body = [
        'amount'      => $amountRial,
        'callback'    => ae_zarinpal_callback_url(),
        'description' => $desc !== '' ? $desc : (string) ($zp['description'] ?? 'پرداخت'),
        'purpose'     => 'payment',
    ];
    if (!empty($zp['webpage_url'])) $body['webpage_url'] = $zp['webpage_url'];
    $payer = [];
    if ($mobile !== '') $payer['mobile'] = $mobile;
    if ($email !== '')  $payer['email']  = $email;
    if ($payer) $body['payer'] = $payer;

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = ae_http($url, $payload, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Length: ' . strlen($payload),
    ], 20);
    $j = json_decode($res['body'], true);
    if (!is_array($j)) {
        ae_log('zp request parse fail: http=' . $res['status'] . ' err=' . $res['error'] . ' body=' . substr($res['body'], 0, 300));
        return ['ok' => false, 'error' => 'gateway_unreachable', 'message' => 'ارتباط با درگاه پرداخت برقرار نشد.'];
    }
    if ($res['status'] !== 200 || empty($j['data']['authority'])) {
        ae_log('zp request error: http=' . $res['status'] . ' body=' . substr($res['body'], 0, 300));
        return ['ok' => false, 'error' => 'gateway_rejected',
                'message' => (string) ($j['errors']['message'] ?? 'درگاه پرداخت درخواست را نپذیرفت.')];
    }
    $auth = (string) $j['data']['authority'];
    return ['ok' => true, 'authority' => $auth,
            'url' => ae_zarinpal_base() . '/pg/v4/payment/start/' . rawurlencode($merchant) . '?Authority=' . rawurlencode($auth)];
}

function ae_zarinpal_verify(string $authority, string $refId, int $amountRial): array
{
    $merchant = ae_zarinpal_merchant();
    if ($merchant === '') return ['ok' => false, 'error' => 'not_configured', 'message' => 'WebID زرین‌پال تنظیم نشده است.'];
    $url = ae_zarinpal_base() . '/pg/v4/payment/verify/' . rawurlencode($merchant);
    $payload = json_encode([
        'authority'   => $authority,
        'referrer_id' => $refId,
        'amount'      => $amountRial,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = ae_http($url, $payload, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Length: ' . strlen($payload),
    ], 25);
    $j = json_decode($res['body'], true);
    if (!is_array($j)) {
        ae_log('zp verify parse fail: http=' . $res['status'] . ' body=' . substr($res['body'], 0, 300));
        return ['ok' => false, 'error' => 'gateway_unreachable', 'message' => 'اعتبارسنجی تراکنش ممکن نشد.'];
    }
    if ($res['status'] !== 200 || (int) ($j['data']['code'] ?? 0) !== 100) {
        ae_log('zp verify failed: http=' . $res['status'] . ' body=' . substr($res['body'], 0, 300));
        return ['ok' => false, 'error' => 'verify_failed',
                'message' => (string) ($j['errors']['message'] ?? 'تراکنش تأیید نشد.')];
    }
    return ['ok' => true, 'card_mask' => (string) ($j['data']['card_masked'] ?? '')];
}

/* ═══ تراکنش‌ها ══════════════════════════════════════════════════════════ */
function ae_tx_file(string $authority): string
{
    return ae_data_dir('tx') . '/' . (string) preg_replace('/[^A-Za-z0-9]/', '', $authority) . '.json';
}
function ae_tx_save(array $tx): bool { return ae_write_json(ae_tx_file((string) $tx['authority']), $tx); }
function ae_tx_get(string $authority): ?array { return ae_read_json(ae_tx_file($authority)); }

/* ═══ سفارش‌ها ═══════════════════════════════════════════════════════════ */
function ae_orders_file(): string { return ae_data_dir('orders') . '/orders.json'; }

function ae_order_append(array $order): bool
{
    $fp   = ae_lock('orders');
    $file = ae_orders_file();
    $list = ae_read_json($file);
    if (!is_array($list)) $list = [];
    $list[] = $order;
    if (count($list) > 500) $list = array_slice($list, -500);
    $done = ae_write_json($file, $list);
    ae_unlock($fp);
    return $done;
}

/* ═══ محاسبهٔ مبلغ سمت سرور ══════════════════════════════════════════════ */
function ae_catalog(): array
{
    static $cat = null;
    if ($cat !== null) return $cat;
    $cat = [];
    $js = @file_get_contents(dirname(__DIR__) . '/js/data.js');
    if ($js) {
        preg_match_all("/\{\s*id:'([a-zA-Z]+)',\s*name:'([^']*)'[^\n]*?price:(\d+)/u", $js, $m, PREG_SET_ORDER);
        foreach ($m as $row) $cat[$row[1]] = ['name' => $row[2], 'price' => (int) $row[3]];
    }
    /* لایهٔ مدیریت: نام و قیمت ویرایش‌شده همان چیزی است که مرورگر می‌بیند.
       محصول پنهان هم قیمت خود را نگه می‌دارد تا سبدِ کهنه به مبلغ صفر نرسد. */
    foreach (ae_catalog_store()['items'] as $id => $p) {
        if (!is_array($p)) continue;
        $id = (string) preg_replace('/[^a-z0-9\-]/i', '', (string) $id);
        if ($id === '') continue;
        if (!isset($cat[$id])) $cat[$id] = ['name' => (string) ($p['name'] ?? $id), 'price' => 0];
        if (isset($p['name'])  && $p['name'] !== '')  $cat[$id]['name'] = (string) $p['name'];
        if (isset($p['price']) && (int) $p['price'] > 0) $cat[$id]['price'] = (int) $p['price'];
    }
    return $cat;
}

function ae_promo(): array
{
    $s = ae_settings();
    return ['code' => (string) $s['promo_code'], 'pct' => (int) $s['promo_pct']];
}

function ae_normalize_items($raw): array
{
    $out = [];
    if (!is_array($raw)) return $out;
    foreach ($raw as $it) {
        if (!is_array($it)) continue;
        $id = (string) preg_replace('/[^a-z]/', '', strtolower((string) ($it['id'] ?? '')));
        if ($id === '') continue;
        $out[] = [
            'id'    => $id,
            'size'  => substr((string) ($it['size'] ?? ''), 0, 8),
            'color' => substr((string) ($it['color'] ?? ''), 0, 24),
            'qty'   => max(1, min(20, (int) ($it['qty'] ?? 1))),
        ];
    }
    return array_slice($out, 0, 40);
}

/** مبلغ نهایی (تومان) فقط از کاتالوگ سمت سرور ساخته می‌شود. */
function ae_price_cart(array $items, ?string $promoCode): array
{
    $catalog  = ae_catalog();
    $subtotal = 0;
    foreach ($items as $k => $it) {
        $p = $catalog[$it['id']] ?? null;
        $price = $p ? (int) $p['price'] : 0;
        $items[$k]['unit'] = $price;
        $items[$k]['name'] = $p ? $p['name'] : $it['id'];
        $subtotal += $price * $it['qty'];
    }
    $promo    = ae_promo();
    $discount = 0;
    if ($promoCode !== null && strtoupper(trim($promoCode)) === $promo['code']) {
        $discount = (int) round($subtotal * $promo['pct'] / 100);
    }
    return ['items' => $items, 'subtotal' => $subtotal, 'discount' => $discount,
            'total' => max(0, $subtotal - $discount)];
}

/* ═══ ایمیل میزبان (mail() هاست) ══════════════════════════════════════════ */
function ae_mail(string $subject, string $text, ?string $replyEmail = null, ?string $replyName = null): bool
{
    $cfg = ae_config();
    $to  = (string) $cfg['concierge_email'];
    if ($to === '') return false;
    $from     = (string) ($cfg['email_from'] ?: $to);
    $boundary = 'ae_' . bin2hex(random_bytes(8));
    $htmlBody = '<!doctype html><meta charset="utf-8">'
              . '<div style="direction:rtl;font-family:Tahoma,sans-serif;font-size:13px;line-height:2;color:#1b1b1b">'
              . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
    $mime = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
          . $text . "\r\n\r\n--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
          . $htmlBody . "\r\n--{$boundary}--\r\n";
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: =?UTF-8?B?' . base64_encode('خانهٔ اُرِل') . '?= <' . $from . '>',
        'Reply-To: ' . ($replyEmail !== null && $replyEmail !== ''
            ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode((string) ($replyName ?: $replyEmail)), $replyEmail)
            : $from),
        'X-Mailer: Aurelle-SharedHost',
    ];
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $mime, implode("\r\n", $headers));
    if (!$ok) ae_log('mail() failed for: ' . $subject);
    return (bool) $ok;
}

/* ═══ احراز هویت نشست ════════════════════════════════════════════════════ */
function ae_auth_phone(): ?string
{
    ae_session_start();
    $p = $_SESSION['ae_phone'] ?? null;
    return (is_string($p) && preg_match('/^09\d{9}$/', $p)) ? $p : null;
}

/* ═══════════════════════════════════════════════════════════════════════
   پنل مدیریت — دروازهٔ دسترسی، پوشهٔ رسانه، انبارهٔ محصولات
   ───────────────────────────────────────────────────────────────────────
   • تنها راه ورود: نشست معتبرِ همان شماره‌ای که در config.php آمده است.
   • شمارهٔ مدیر هرگز به مرورگر داده نمی‌شود (فقط یک پرچم boolean).
   • رسانه‌ها در پوشهٔ عمومی uploads/ می‌نشینند تا <img> بتواند آن‌ها را
     بخواند؛ آن پوشه اجرای اسکریپت را قفل می‌کند.
   ═══════════════════════════════════════════════════════════════════════ */

/** شماره‌های مجاز پنل — نرمال‌شده و یکتا. */
function ae_admin_phones(): array
{
    static $out = null;
    if ($out !== null) return $out;
    $cfg   = ae_config()['admin'] ?? [];
    $raw   = is_array($cfg['phones'] ?? null) ? $cfg['phones'] : [$cfg['phones'] ?? ''];
    $out   = [];
    foreach ($raw as $p) {
        $n = ae_norm_mobile((string) $p);
        if ($n !== null) $out[$n] = $n;
    }
    return $out;
}

function ae_is_admin(): bool
{
    $phone = ae_auth_phone();
    return $phone !== null && isset(ae_admin_phones()[$phone]);
}

/** نگهبان همهٔ endpointهای مدیریتی. */
function ae_require_admin(): string
{
    $phone = ae_auth_phone();
    if ($phone === null) ae_err('ابتدا وارد حساب شوید.', 'unauthorized', 401);
    if (!ae_is_admin()) ae_err('این بخش فقط برای مدیر است.', 'forbidden', 403);
    return $phone;
}

/* ─── پوشهٔ عمومی رسانه ─────────────────────────────────────────────────── */
function ae_uploads_dir(): string
{
    $cfg = ae_config()['admin'] ?? [];
    $dir = trim((string) ($cfg['uploads_dir'] ?? ''));
    if ($dir === '') $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    /* محافظ: فهرست‌گیری خاموش، اجرای اسکریپت ممنوع، فقط تصویر خوانده شود */
    $idx = "<?php http_response_code(404); ?>\n";
    if (!is_file($dir . '/index.php'))  @file_put_contents($dir . '/index.php', $idx);
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    if (!is_file($dir . '/.htaccess'))  @file_put_contents($dir . '/.htaccess', ae_uploads_guard());
    return $dir;
}

function ae_uploads_guard(): string
{
    return <<<'HT'
# MAISON AURELLE — پوشهٔ رسانه‌ها
# این پوشه فقط برای نگه‌داری تصویر است؛ اجرای هرگونه اسکریپت ممنوع.
Options -Indexes -ExecCGI
AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .pht .phps .cgi .pl .py .sh

<IfModule mod_php.c>
  php_flag engine off
</IfModule>
<IfModule mod_php7.c>
  php_flag engine off
</IfModule>

<FilesMatch "\.(?i:php|phtml|php3|php4|php5|php7|phps|phar|cgi|pl|py|sh|htaccess)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>
HT;
}

/** مسیر نسبی امن برای ذخیره/نمایش یک فایل رسانه. */
function ae_media_rel(string $file): string
{
    $file = ltrim(str_replace('\\', '/', $file), '/');
    return 'uploads/' . basename($file);
}
function ae_media_abs(string $rel): string
{
    return ae_uploads_dir() . '/' . basename((string) preg_replace('#^uploads/#', '', (string) $rel));
}

/* ─── انبارهٔ کاتالوگ (لایهٔ روی data.js) ────────────────────────────────── */
function ae_store_file(string $name): string
{
    return ae_data_dir('store') . '/' . (string) preg_replace('/[^a-z0-9_\-]/i', '', $name) . '.json';
}

/** خواندن انباره با قفل — همیشه ساختار سالم برمی‌گرداند. */
function ae_store_read(string $name, array $shape): array
{
    /* مویز درون‌درخواستی: هر فایل انباره تا پایان همین request فقط یک بار از
       دیسک خوانده می‌شود؛ ae_store_write آن را بی‌اعتبار می‌کند. روی هاست
       اشتراکی IO تکراری catalog/settings را حذف می‌کند. */
    if (isset($GLOBALS['ae_store_memo'][$name])) {
        $j = $GLOBALS['ae_store_memo'][$name];
    } else {
        $raw = ae_read_json(ae_store_file($name));
        $j = is_array($raw) ? $raw : [];
        $GLOBALS['ae_store_memo'][$name] = $j;
    }
    if (!is_array($j)) $j = [];
    foreach ($shape as $k => $default) {
        if (is_array($default) && (!isset($j[$k]) || !is_array($j[$k]))) $j[$k] = $default;
        elseif (!array_key_exists($k, $j)) $j[$k] = $default;
    }
    $j['v']       = isset($j['v']) ? (int) $j['v'] : 1;
    $j['updated'] = isset($j['updated']) ? (int) $j['updated'] : 0;
    return $j;
}

function ae_store_write(string $name, array $data): bool
{
    /* بی‌اعتبارسازی مویز ae_store_read در همین request */
    unset($GLOBALS['ae_store_memo'][$name]);
    $fp = ae_lock('store-' . $name);
    $data['v']       = 1;
    $data['updated'] = time();
    $ok = ae_write_json(ae_store_file($name), $data);
    ae_unlock($fp);
    return $ok;
}

/**
 * نگاشت محصولات برای مرورگر: همان کلیدهای data.js
 * ساختار: { seeded:bool, items:{ id => {fields} }, order:[id],
 *           settings:{promo_code,promo_pct}, removed:[id] }
 * `seeded` یعنی پنل یک‌بار کاتالوگ پایهٔ data.js را در خود کپی کرده است؛
 * از آن پس سرور مرجع کامل است و ویترین هر چه اینجا هست همان را می‌بیند.
 * `removed` سنگ قبر محصولات حذف‌شده است: ویترین باید آن‌ها را از data.js
 * هم پاک کند، وگرنه محصول حذف‌شده به سایت برمی‌گردد.
 */
function ae_catalog_store(): array
{
    return ae_store_read('catalog', [
        'seeded' => false, 'items' => [], 'order' => [], 'settings' => [], 'removed' => [],
    ]);
}
function ae_settings(): array
{
    $s   = ae_catalog_store()['settings'];
    $set = array_key_exists('promo_code', $s);
    if (!$set) {
        /* هنوز چیزی ذخیره نشده → همان پیش‌فرض data.js تا دو طرف هم‌خوان بمانند */
        return ['promo_code' => 'VELORA10', 'promo_pct' => 10, 'set' => false];
    }
    $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $s['promo_code']));
    $pct  = (int) ($s['promo_pct'] ?? 0);
    if ($code === '' || $pct < 1) return ['promo_code' => '', 'promo_pct' => 0, 'set' => true];
    return ['promo_code' => substr($code, 0, 24), 'promo_pct' => max(1, min(90, $pct)), 'set' => true];
}

/** پاک‌سازی یک رکورد محصول: فقط کلیدهای شناخته‌شده، با سقف طول. */
function ae_clean_product(array $in, array $base = []): array
{
    $out = [];
    $txt = static function ($v, int $max): string { return ae_clean($v, $max); };

    $out['id']     = (string) preg_replace('/[^a-z0-9\-]/i', '', (string) ($in['id'] ?? $base['id'] ?? ''));
    if ($out['id'] === '') $out['id'] = 'p' . substr(bin2hex(random_bytes(3)), 0, 6);
    $out['name']   = $txt($in['name']   ?? $base['name']   ?? 'فرم تازه', 60);
    $out['sub']    = $txt($in['sub']    ?? $base['sub']    ?? '', 220);
    $out['cat']    = $txt($in['cat']    ?? $base['cat']    ?? '', 60);
    $out['family'] = (string) preg_replace('/[^a-z]/', '', strtolower((string) ($in['family'] ?? $base['family'] ?? 'loafer')));
    $out['heel']   = max(0, min(300, (int) ($in['heel']   ?? $base['heel']   ?? 0)));
    $out['price']  = max(0, min(9999999999, (int) ($in['price'] ?? $base['price'] ?? 0)));
    $old = (int) ($in['oldPrice'] ?? $base['oldPrice'] ?? 0);
    $out['oldPrice'] = ($old > $out['price']) ? min(9999999999, $old) : 0;
    $out['badge']  = $txt($in['badge']  ?? $base['badge']  ?? '', 24);
    $out['isNew']   = !empty($in['isNew'] ?? $base['isNew'] ?? false);
    $out['hidden']  = !empty($in['hidden'] ?? $base['hidden'] ?? false);
    $out['stock']   = max(0, min(9999, (int) ($in['stock'] ?? $base['stock'] ?? 0)));
    $out['rating']  = max(0, min(5, round((float) ($in['rating'] ?? $base['rating'] ?? 4.8), 1)));
    $out['reviews'] = max(0, min(999999, (int) ($in['reviews'] ?? $base['reviews'] ?? 0)));

    /* تصاویر: فقط مسیرهای داخلی رسانه یا لینک کامل https */
    $url = static function ($v, string $fallback = ''): string {
        $s = trim((string) $v);
        if ($s === '') return $fallback;
        if (preg_match('#^https://[^\s"\'<>]+$#i', $s)) return substr($s, 0, 600);
        if (preg_match('#^uploads/[A-Za-z0-9._\-]+$#', $s)) return $s;
        return $fallback;
    };
    $out['img'] = $url($in['img'] ?? $base['img'] ?? '', '');

    $gallery = [];
    foreach ((array) ($in['gallery'] ?? $base['gallery'] ?? []) as $g) {
        $u = $url($g, '');
        if ($u !== '' && !in_array($u, $gallery, true)) $gallery[] = $u;
        if (count($gallery) >= 24) break;
    }
    $out['gallery'] = $gallery;

    /* رنگ‌ها: نام + کد رنگ + تصویر */
    $swatches = [];
    foreach ((array) ($in['sw'] ?? $base['sw'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $c = (string) ($s['c'] ?? '#161310');
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) $c = '#161310';
        $swatches[] = [
            'n'   => $txt($s['n'] ?? '', 24),
            'c'   => strtolower($c),
            'img' => $url($s['img'] ?? '', $out['img']),
        ];
        if (count($swatches) >= 12) break;
    }
    if (!$swatches) {
        $swatches[] = ['n' => $out['name'] !== '' ? $out['name'] : 'رنگ پیش‌فرض',
                       'c' => '#161310', 'img' => $out['img']];
    }
    $out['sw'] = $swatches;

    return $out;
}

/* ─── انبارهٔ رسانه ──────────────────────────────────────────────────────── */
function ae_media_store(): array
{
    return ae_store_read('media', ['items' => []]);
}

/** MIMEهای مجاز → پسوند امن. کلید = نوع واقعی تشخیص‌داده‌شده، نه نام فایل. */
function ae_media_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/avif' => 'avif',
    ];
}

/** پاک‌سازی فهرست رسانه از فایل‌های گم‌شده و ساخت نقشهٔ استفاده. */
function ae_media_index(): array
{
    $store = ae_media_store();
    $items = [];
    foreach ($store['items'] as $it) {
        if (!is_array($it) || empty($it['file'])) continue;
        $abs = ae_media_abs((string) $it['file']);
        if (!is_file($abs)) continue;                       /* فایل نیست → رکورد پاک */
        $items[(string) $it['id']] = [
            'id'      => (string) $it['id'],
            'file'    => (string) $it['file'],
            'name'    => (string) ($it['name'] ?? basename((string) $it['file'])),
            'alt'     => (string) ($it['alt']  ?? ''),
            'w'       => (int) ($it['w'] ?? 0),
            'h'       => (int) ($it['h'] ?? 0),
            'size'    => (int) ($it['size'] ?? 0),
            'mime'    => (string) ($it['mime'] ?? ''),
            'created' => (int) ($it['created'] ?? 0),
        ];
    }
    /* نقشهٔ استفاده: هر رسانه، کجا در کاتالوگ دیده می‌شود */
    $cat = ae_catalog_store()['items'];
    $use = [];
    $mark = static function (string $url, string $pid, string $role) use (&$use): void {
        if ($url === '') return;
        if (!isset($use[$url])) $use[$url] = [];
        if (!in_array(['pid' => $pid, 'role' => $role], $use[$url], true)) {
            $use[$url][] = ['pid' => $pid, 'role' => $role];
        }
    };
    foreach ($cat as $pid => $p) {
        if (!is_array($p)) continue;
        $mark((string) ($p['img'] ?? ''), (string) $pid, 'main');
        foreach ((array) ($p['gallery'] ?? []) as $i => $g) $mark((string) $g, (string) $pid, 'gallery:' . ($i + 1));
        foreach ((array) ($p['sw'] ?? []) as $i => $s) {
            if (is_array($s)) $mark((string) ($s['img'] ?? ''), (string) $pid, 'swatch:' . ($i + 1));
        }
    }
    $out = [];
    foreach ($items as $id => $it) {
        $it['used'] = $use[$it['file']] ?? [];
        $out[] = $it;
    }
    usort($out, static fn($a, $b) => ($b['created'] <=> $a['created']) ?: strcmp($a['id'], $b['id']));
    return $out;
}

/** ذخیرهٔ یک فایل آپلودشده در uploads/ و برگرداندن رکورد رسانه. */
function ae_media_store_upload(array $file): array
{
    if (!isset($file['tmp_name']) || (int) ($file['error'] ?? 4) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? 4);
        $msg = [
            UPLOAD_ERR_INI_SIZE  => 'حجم فایل از حد مجاز سرور بیشتر است.',
            UPLOAD_ERR_FORM_SIZE => 'حجم فایل بیش از حد مجاز است.',
            UPLOAD_ERR_NO_FILE   => 'فایلی انتخاب نشده است.',
            UPLOAD_ERR_PARTIAL   => 'فایل به‌طور کامل آپلود نشد.',
            UPLOAD_ERR_NO_TMPDIR => 'پوشهٔ موقت سرور در دسترس نیست.',
            UPLOAD_ERR_CANT_WRITE=> 'نوشتن روی سرور ممکن نشد.',
            UPLOAD_ERR_EXTENSION => 'آپلود توسط افزونهٔ هاست متوقف شد.',
        ];
        ae_err($msg[$code] ?? 'آپلود فایل ناموفق بود.', 'upload_failed', 422);
    }

    $cfg   = ae_config()['admin'] ?? [];
    $maxMb = max(1, (int) ($cfg['max_upload_mb'] ?? 8));
    $size  = (int) @filesize($file['tmp_name']);
    if ($size <= 0)                     ae_err('فایل خالی است.', 'empty_file', 422);
    if ($size > $maxMb * 1024 * 1024)   ae_err('حجم فایل بیش از ' . $maxMb . ' مگابایت است.', 'too_large', 413);

    /* نوع واقعی از محتوای فایل — نه از نام یا هدر مرورگر */
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string) @finfo_file($fi, $file['tmp_name']); @finfo_close($fi); }
    }
    if ($mime === '') {
        $info = @getimagesize($file['tmp_name']);
        $mime = $info ? (string) ($info['mime'] ?? '') : '';
    }
    $types = ae_media_types();
    if (!isset($types[$mime])) {
        ae_err('فقط تصویرهای JPG، PNG، WebP، GIF و AVIF پذیرفته می‌شوند.', 'bad_type', 415);
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || (int) $info[0] < 1 || (int) $info[1] < 1) {
        ae_err('فایل تصویر معتبر نیست.', 'not_image', 415);
    }

    $ext  = $types[$mime];
    $name = (string) preg_replace('/[^A-Za-z0-9._\-]/', '-', (string) ($file['name'] ?? 'image'));
    $name = trim((string) preg_replace('/-+/', '-', $name), '-');
    if ($name === '') $name = 'image';
    if (strlen($name) > 80) $name = substr($name, 0, 80);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $base = substr($stem === '' ? 'media' : $stem, 0, 40);
    $hash = substr(bin2hex(random_bytes(10)), 0, 12);
    $file2 = $base . '-' . $hash . '.' . $ext;

    $dest = ae_uploads_dir() . '/' . $file2;
    $moved = is_uploaded_file($file['tmp_name'])
        ? @move_uploaded_file($file['tmp_name'], $dest)
        : @rename($file['tmp_name'], $dest);
    if (!$moved) ae_err('ذخیرهٔ فایل روی سرور ممکن نشد — پوشهٔ uploads نوشتنی نیست.', 'store_failed', 500);
    @chmod($dest, 0644);

    $id = 'm_' . $hash;
    $store = ae_media_store();
    $store['items'][$id] = [
        'id'      => $id,
        'file'    => ae_media_rel($file2),
        'name'    => $name,
        'alt'     => '',
        'w'       => (int) $info[0],
        'h'       => (int) $info[1],
        'size'    => $size,
        'mime'    => $mime,
        'created' => time(),
    ];
    ae_store_write('media', $store);

    $rec = $store['items'][$id];
    $rec['used'] = [];
    return $rec;
}

/** حذف رکورد رسانه (و در صورت نیاز آزادکردن ارجاع‌های کاتالوگ). */
function ae_media_delete(string $id, bool $detach): bool
{
    $store = ae_media_store();
    if (!isset($store['items'][$id])) return false;
    $rel  = (string) $store['items'][$id]['file'];

    if ($detach) ae_media_detach($rel);

    unset($store['items'][$id]);
    ae_store_write('media', $store);
    $abs = ae_media_abs($rel);
    if (is_file($abs)) @unlink($abs);
    return true;
}

/** پاک‌کردن هر ارجاعی به یک فایل از لایهٔ مدیریت محصولات. */
function ae_media_detach(string $rel): int
{
    $cat = ae_catalog_store();
    $n = 0;
    foreach ($cat['items'] as $pid => $p) {
        if (!is_array($p)) continue;
        $touched = false;
        if (($p['img'] ?? '') === $rel) { $p['img'] = ''; $touched = true; }
        if (isset($p['gallery']) && is_array($p['gallery'])) {
            $g = array_values(array_filter($p['gallery'], static fn($u) => (string) $u !== $rel));
            if (count($g) !== count($p['gallery'])) { $p['gallery'] = $g; $touched = true; }
        }
        if (isset($p['sw']) && is_array($p['sw'])) {
            foreach ($p['sw'] as $i => $s) {
                if (is_array($s) && (string) ($s['img'] ?? '') === $rel) {
                    /* تصویر رنگ باید بماند؛ به عکس اصلی محصول برمی‌گردد */
                    $s['img'] = (string) ($p['img'] ?? '');
                    $p['sw'][$i] = $s;
                    $touched = true;
                }
            }
        }
        if ($touched) { $cat['items'][$pid] = $p; $n++; }
    }
    if ($n) ae_store_write('catalog', $cat);
    return $n;
}

/* ═══ ابزار کمکی متن ═════════════════════════════════════════════════════ */
function ae_clean($v, int $max = 200): string
{
    $s = (string) $v;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

function fa_money(int $toman): string
{
    return number_format($toman) . ' تومان';
}
