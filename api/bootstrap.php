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
        'data_dir' => $root . '/api/data',
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
    return substr(hash('sha256', $ip . '|' . $ua), 0, 32);
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
function ae_meli_send_otp(string $to): array
{
    $cfg = ae_config()['otp'];
    $key = trim((string) ($cfg['service_key'] ?? ''));
    if ($key === '') {
        return ['ok' => false, 'error' => 'not_configured',
                'message' => 'کلید سرویس ملی پیامک در config.php تنظیم نشده است.'];
    }
    $url = rtrim((string) ($cfg['endpoint_base'] ?? 'https://console.melipayamak.com/api/send/otp/'), '/')
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
    return $cat;
}

function ae_promo(): array { return ['code' => 'VELORA10', 'pct' => 10]; }

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
