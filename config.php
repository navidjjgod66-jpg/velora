<?php
declare(strict_types=1);
/**
* VELORA · Maison de Chaussures
* Core Configuration · v9.5 Aetherion (Hardened & Optimized)
* PHP 8.4+ · MySQL 8 / MariaDB 10.6+ · strict_types
*/

if (defined('VELORA_CONFIG_LOADED')) {
    return;
}
define('VELORA_CONFIG_LOADED', true);

/* ═══════════════════════════════════════════════════════════════════════════
ERROR HANDLING
═══════════════════════════════════════════════════════════════════════════ */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('log_errors_max_len', '0');

foreach ([
    __DIR__ . '/storage/logs',
    __DIR__ . '/storage/cache',
    __DIR__ . '/storage/cache/rl',
    __DIR__ . '/storage/uploads',
    __DIR__ . '/storage/uploads/products',
] as $__veloraDir) {
    if (!is_dir($__veloraDir)) {
        @mkdir($__veloraDir, 0750, true);
    }
}
unset($__veloraDir);
ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');

/* ═══════════════════════════════════════════════════════════════════════════
ENV LOADER
═══════════════════════════════════════════════════════════════════════════ */
(static function (string $file): void {
    if (!is_readable($file)) return;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return;
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $invisible = ["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"];
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = str_replace($invisible, '', trim($k));
        $v = str_replace($invisible, '', trim($v));
        if ($k === '' || getenv($k) !== false) continue;
        $n = strlen($v);
        if ($n >= 2) {
            $f = $v[0]; $l = $v[$n - 1];
            if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                $v = substr($v, 1, -1);
            } else {
                $v = preg_replace('/\s+#.*$/', '', $v) ?? $v;
                $v = trim($v);
            }
        } else {
            $v = preg_replace('/\s+#.*$/', '', $v) ?? $v;
            $v = trim($v);
        }
        putenv("$k=$v");
        $_ENV[$k] = $_SERVER[$k] = $v;
    }
})(__DIR__ . '/.env');

/* ═══════════════════════════════════════════════════════════════════════════
ENV HELPERS
═══════════════════════════════════════════════════════════════════════════ */
function env(string $key, ?string $default = null): ?string {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === '') return $default;
    return is_string($v) ? trim($v) : $default;
}

function env_bool(string $key, bool $default = false): bool {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === '') return $default;
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Read a php.ini size directive as a byte count.
 *
 * php.ini accepts the shorthand `10M`, and ini_get() hands that shorthand
 * straight back — so `(int) ini_get('upload_max_filesize')` is 10, and a panel
 * comparing it against a 10 MB constant believes the real limit is ten bytes.
 * The same applies to the space form (`10 M`) and to `1G`, which is why the
 * suffix is matched rather than assumed.
 *
 * Returns 0 for a directive set to 0 or left empty, which in php.ini means
 * "unlimited" — the caller decides what to do with that, because for a size
 * ceiling "unlimited" is not a number to clamp with.
 */
function ini_bytes(string $key): int {
    $raw = trim((string) @ini_get($key));
    if ($raw === '') return 0;
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i', $raw, $m)) return 0;
    $mult = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824, '' => 1][strtoupper($m[2])] ?? 1;
    return (int) round((float) $m[1] * $mult);
}

/**
 * The upload ceiling actually in force right now.
 *
 * Three limits apply and the customer hits the smallest: the one our code
 * enforces (ADMIN_UPLOAD_MAX_BYTES), the one PHP enforces on the file, and the
 * one PHP enforces on the request body. The third matters as much as the second
 * — a file that fits under upload_max_filesize still vanishes if the POST that
 * carried it exceeded post_max_size, and it vanishes before any of our code
 * runs, which is why the resulting error is NO_FILE rather than FILE_TOO_LARGE.

 * A php.ini value of 0 means unlimited, so it is dropped from the minimum rather
 * than treated as a ceiling of zero bytes. The constant is always included, so
 * the return value is a real number and never 0.
 */
function admin_upload_effective_max(): int {
    $limits = [ADMIN_UPLOAD_MAX_BYTES];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $b = ini_bytes($key);
        if ($b > 0) $limits[] = $b;
    }
    return min($limits);
}

function is_placeholder_merchant(string $id): bool {
    $id = trim($id);
    if ($id === '') return true;
    $probe = strtolower($id);
    foreach (['change_me', 'changeme', 'xxxxxxxx', 'your-', 'your_', 'todo', 'placeholder', 'example'] as $needle) {
        if (str_contains($probe, $needle)) return true;
    }
    return false;
}

/* ═══════════════════════════════════════════════════════════════════════════
PRODUCT IMAGE RESOLUTION
═══════════════════════════════════════════════════════════════════════════ */
function product_brand_image(): string { return APP_URL . '/brand-icon-512.png'; }

function product_image_url(string $keyOrUrl, int $w = 1200, int $h = 0, int $q = 80): string {
    $k = trim($keyOrUrl);
    if ($k === '') return '';

    if (preg_match('#^https?://#i', $k)) {
        if (preg_match('#^http://#i', $k) && is_https()) return '';
        return $k;
    }

    if (preg_match('#^[a-zA-Z0-9_-]{1,80}\.webp$#', $k)) {
        return product_upload_url_base() . '/' . $k;
    }

    return '';
}

function product_upload_dir(): string { return __DIR__ . '/storage/uploads/products'; }
function product_upload_url_base(): string { return APP_URL . '/storage/uploads/products'; }
function is_safe_upload_filename(string $name): bool { return (bool) preg_match('/^[a-zA-Z0-9_-]{1,80}\.webp$/', $name); }

function env_require_all(array $keys): array {
    $missing = []; $out = [];
    foreach ($keys as $k) {
        $v = env($k);
        if ($v === null || $v === '') $missing[] = $k; else $out[$k] = $v;
    }
    if ($missing) {
        /* The names only. These are the same keys printed in .env.example,
           which is committed, so nothing here is a secret — and without them
           the client can show nothing but a bare 500, which reads as a crash
           rather than as a file nobody has filled in. The values are what must
           never travel; they are not touched here. */
        error_log('[VELORA CONFIG] Missing required env: ' . implode(', ', $missing));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        exit(json_encode(['ok' => false, 'error' => 'CONFIG_INCOMPLETE',
                          'message' => 'تنظیم کامل نیست — در فایل .env مقدار زیرها خالی است: '
                                       . implode(', ', $missing)], JSON_UNESCAPED_UNICODE));
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════════
APPLICATION CONSTANTS
═══════════════════════════════════════════════════════════════════════════ */
define('APP_ENV',   strtolower(env('VELORA_ENV', 'production')));
define('APP_DEBUG', APP_ENV !== 'production');
define('APP_URL',   rtrim(env('VELORA_APP_URL', 'https://velora.maison'), '/'));
$__app_url_parts = parse_url(APP_URL) ?: [];
define('APP_HOST',   $__app_url_parts['host'] ?? 'localhost');
define('APP_ORIGIN', ($__app_url_parts['scheme'] ?? 'https') . '://' . ($__app_url_parts['host'] ?? 'localhost') . (isset($__app_url_parts['port']) ? ':' . $__app_url_parts['port'] : ''));
unset($__app_url_parts);

define('ADMIN_UPLOAD_MAX_BYTES', 10 * 1024 * 1024);
define('ADMIN_UPLOAD_MAX_LABEL', '۱۰ مگابایت');

/* The ceiling the code enforces, and the ceiling the interpreter enforces, are
   two different numbers, and only one of them is ours.

   ADMIN_UPLOAD_MAX_BYTES is checked in api.php. But PHP itself refuses a
   larger body before any of our code runs, and its limit comes from php.ini.
   On a shared host where that file says upload_max_filesize = 2M, the admin
   panel advertises ۱۰ مگابایت, the operator picks a 6 MB photo, and PHP
   discards the request body without ever populating $_FILES — so the failure
   arrives as NO_FILE, which reads as "the upload did not happen" and points at
   the endpoint rather than at the limit.

   So the two are reconciled here, in the only place that can still influence
   it. ini_set() is honoured for these directives when they are PHP_INI_ALL,
   which is the case under mod_php; under PHP-FPM it is PHP_INI_PERDIR and the
   call is correctly a no-op, so it is wrapped rather than assumed.

   What this cannot do is raise a limit the host operator has deliberately set
   lower. That is why admin.php also reads the effective values back at runtime
   and shows them, rather than assuming this block succeeded: an operator who
   sees the real number can act on it, and one who sees the panel's number has
   no way to know which ceiling they actually hit. */
if (function_exists('ini_set')) {
    /* post_max_size must exceed upload_max_filesize, or the whole request body
       is truncated at the smaller of the two and no file ever arrives. */
    @ini_set('upload_max_filesize', (string) ADMIN_UPLOAD_MAX_BYTES);
    @ini_set('post_max_size', (string) (ADMIN_UPLOAD_MAX_BYTES + (1 << 20)));
}

defined('SIZE_MIN')  or define('SIZE_MIN', 37);
defined('SIZE_MAX')  or define('SIZE_MAX', 41);
/* The single list, used everywhere the band is needed.
   It used to be `range(SIZE_MIN, SIZE_MAX)` in three places, which is three
   chances to spell the bounds differently. Raising the range is now one edit,
   on these two lines, and the browser learns about it on the next page render
   (index.php publishes this to data.js, which builds its size sheet from it).

   That publication is what removed a real order-blocking bug: the client
   offered '36'…'46' from a hand-written array while api.php rejected anything
   outside SIZE_MIN…SIZE_MAX with INVALID_SIZE, failing the whole order at the
   last request after three completed checkout steps. */
defined('SIZE_BAND') or define('SIZE_BAND', range(SIZE_MIN, SIZE_MAX));

/* ─── Category vocabulary ───────────────────────────────────────────────────
   The slugs products.json stores, and the Persian names the maison shows for
   them. Two lists because they answer two different questions and conflating
   them is how the wrong one gets used.

   CATEGORY_SLUGS is the closed vocabulary: the admin's category <select> is
   built from it, api.php validates against it, and a product whose `cat` is not
   in it cannot be reached by any filter. It is a whitelist, and a whitelist that
   only exists in one place is a whitelist; it used to be a hand-written <option>
   list in admin.php, which is a second place nothing kept in step.

   CATEGORY_LABELS is the display name. This is the list that should not have
   needed to exist: without it, every surface that shows a product's category
   shows the slug, and the slugs are English machine identifiers. So the shop
   opened onto eleven cards labelled `heel`, `boot`, `sandal`, `flat`, `loafer`,
   `bridal` — on a Persian storefront, in the most prominent position on the
   card, directly above the price. The product page's breadcrumb did the same.

   That is not a translation gap, it is unfinished work: the Persian names were
   already written and already in use. data.js holds them, in FAM, keyed by the
   *family* token the filters use (`highheel`, `ballet`) rather than by the
   catalogue slug (`heel`, `flat`) — a second vocabulary layered on the first,
   and the display path happened to read the raw slug from neither.

   The maison's own register is used, matching the wording its product copy
   already uses: «باله تخت» rather than «تخت», «پاشنه بلند» rather than «پاشنه‌دار». */
defined('CATEGORY_SLUGS') or define('CATEGORY_SLUGS', [
    'heel', 'flat', 'boot', 'sandal', 'loafer', 'bridal',
]);
defined('CATEGORY_LABELS') or define('CATEGORY_LABELS', [
    'heel'   => 'پاشنه بلند',
    'flat'   => 'باله تخت',
    'boot'   => 'بوت',
    'sandal' => 'صندل',
    'loafer' => 'لوفر',
    'bridal' => 'عروس',
]);

/** The Persian name for a category slug, or the slug itself if the vocabulary
     grew on disk — an unrecognised key should be visible as a key rather than
    silently folded into a category it is not in. */
function velora_category_label(string $slug): string {
    return CATEGORY_LABELS[$slug] ?? $slug;
}

date_default_timezone_set(env('VELORA_TZ', 'Asia/Tehran'));

$__req = env_require_all([
    'VELORA_DB_NAME',
    'VELORA_DB_USER',
    'VELORA_DB_PASS',
    'VELORA_ADMIN_USER',
    'VELORA_ADMIN_PASS',
]);
define('DB_NAME',    $__req['VELORA_DB_NAME']);
define('DB_USER',    $__req['VELORA_DB_USER']);
define('DB_PASS',    $__req['VELORA_DB_PASS']);
define('ADMIN_USER', $__req['VELORA_ADMIN_USER']);
define('ADMIN_PASS_HASH', (string) $__req['VELORA_ADMIN_PASS']);
unset($__req);

define('DB_HOST',    env('VELORA_DB_HOST', '127.0.0.1'));
define('DB_PORT',    (int) env('VELORA_DB_PORT', '3306'));
define('DB_CHARSET', 'utf8mb4');
define('ADMIN_SESSION_TTL', (int) env('VELORA_ADMIN_SESSION_TTL', '7200'));
define('MELI_API',  env('VELORA_MELI_API', ''));
define('MELI_FROM', env('VELORA_MELI_FROM', '50002220022'));
define('OTP_TTL',   (int) env('VELORA_OTP_TTL', '120'));
define('OTP_DEBUG', env_bool('VELORA_OTP_DEBUG', false));
define('ZARINPAL_MERCHANT', trim((string) env('VELORA_ZARINPAL_MERCHANT', '')));
define('ZARINPAL_SANDBOX',  env_bool('VELORA_ZARINPAL_SANDBOX', false));

/* ZarinPal is the only gateway.
   There used to be two (ZarinPal + Zibal) behind ACTIVE_GATEWAY, which meant
   three things a single-gateway shop does not need: a switch to be
   misconfigured, a second code path through checkout and through the callback
   that had to be kept correct in parallel, and a merchant id in .env that
   nothing read. Zibal is gone — payment.php deleted, its functions and
   constants gone, its branch removed from the callback — so there is now one
   gateway, one path, one merchant id, and no switch that can be left pointing
   at a gateway the code no longer contains.

   The switch itself is retained as a constant so that a deployment with
   VELORA_ACTIVE_GATEWAY still set in .env reads correctly and says something
   useful if it names anything but zarinpal, rather than silently changing the
   behaviour of a shop that never asked for it. */
define('ACTIVE_GATEWAY', 'zarinpal');
define('INSTALL_TOKEN', env('VELORA_INSTALL_TOKEN', ''));
/* The per-line quantity ceiling, as one number the client and the server both
   read.

   api.php clamps every line to MAX_LINE (api.php, canonicalisation step). The
   cart used to allow MAX_PER_LINE = 20 in data.js while the server allowed 5,
   so a customer could build a cart of six of one design, watch the UI accept
   it, fill in the whole checkout, and be refused at the last request with
   INVALID_ITEM — with nothing on screen to say why.

   The mismatch was possible because the number lived twice, in PHP and in
   JavaScript, and nothing kept them together. There is no second constant
   here now: MAX_PER_LINE in data.js is a local-only fallback for when no
   server value has been published, and index.php emits this one to the
   browser, so the cap the cart enforces is the cap the server enforces.

   Raising it is one edit, in this line, and takes effect on both sides at
   once. */
define('MAX_LINE',              5);
define('SESSION_TTL',       7_200);
define('APPOINTMENT_HORIZON_DAYS', 90);

/* Shipping is a house promise, not a threshold: every order ships free, in
   every province, with no minimum. The charge used to be SHIP_FEE 680,000
   toman waived only above FREE_SHIP_MIN 50,000,000, which meant a real cart
   quietly gained 680,000 at the till and the cart sheet had to sell a
   progress bar to make the waiver feel earned. There is nothing left to
   earn, so both constants are gone and every quote is simply zero.

   velora_orders.shipping stays in the schema and is still written, as 0. It
   is a historical record of what was charged on the day: a 2024 order must
   keep saying 680,000 even though today's carts never produce that number. */
define('SHIPPING_FLAT',        0);

/* ═══════════════════════════════════════════════════════════════════════════
POSTAL CODE LOOKUP (s.api.ir)
═══════════════════════════════════════════════════════════════════════════
   The token lives in .env and never leaves the server. It is not in app.js, not
   in index.php, not in any response body, and not in a log line. api.php's
   postal_lookup action reads the constants below and returns province, city,
   district, line and address — a parsed subset, never the raw upstream body,
   which would carry the Authorization header's sibling fields along with it.

   With no token, POSTAL_ENABLED is false and the address form simply skips the
   verification step. A customer can still save an address, the server still
   validates province/city and the postal checksum, and the only thing missing
   is the confirmation. That is deliberate: the lookup is a convenience that
   raises accuracy, not a gate. A lookup outage must not become an address book
   outage, and a form that cannot be submitted because a third party is slow is
   worse than a form that trusts its own customer. */
define('POSTAL_API_URL', (string) env('VELORA_POSTAL_API_URL', 'https://s.api.ir/api/sw1/PostalCodeInfo'));
define('POSTAL_API_TOKEN', trim((string) env('VELORA_POSTAL_API_TOKEN', '')));
define('POSTAL_ENABLED', POSTAL_API_TOKEN !== '');
/* Seven days. Long enough to absorb the repeat lookups a real storefront
   generates from one popular building, short enough that a correction at the
   post office is picked up inside a working week. */
define('POSTAL_CACHE_TTL', 7 * 24 * 3600);
/* Seconds. Eight is past the point where the customer has already decided the
   field is broken; a slower answer is not an answer. */
define('POSTAL_HTTP_TIMEOUT', 8);
define('POSTAL_HTTP_CONNECT_TIMEOUT', 5);

/* ═══════════════════════════════════════════════════════════════════════════
PASSWORD VERIFICATION
═══════════════════════════════════════════════════════════════════════════ */
function is_password_hash(string $stored): bool {
    $info = password_get_info($stored);
    return !empty($info['algo']) && $info['algo'] !== 'unknown';
}

function verify_password(string $plain, string $stored): bool {
    if ($stored === '') return false;
    if (!is_password_hash($stored)) {
        error_log('[VELORA AUTH] Rejected login attempt: stored credential is not a valid password hash.');
        return false;
    }
    return password_verify($plain, $stored);
}

define('ADMIN_HASH_CONFIGURED', is_password_hash(ADMIN_PASS_HASH));

if (!ADMIN_HASH_CONFIGURED) {
    error_log(
        '[VELORA CONFIG] VELORA_ADMIN_PASS is not a valid password hash — the admin '
        . 'panel will reject every login. Generate one with: '
        . 'php -r "echo password_hash(\'YOUR_NEW_PASSWORD\', PASSWORD_DEFAULT), PHP_EOL;" '
        . 'and paste the output into VELORA_ADMIN_PASS in .env'
    );
}

/* ═══════════════════════════════════════════════════════════════════════════
SESSION HARDENING
═══════════════════════════════════════════════════════════════════════════ */
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $https ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) SESSION_TTL);
    if (PHP_VERSION_ID < 80400) {
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');
    }
    session_name('VELORA_SID');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();

    /* There was a periodic re-key here: every 30 minutes, regenerate the id
       and delete the old session file. It looked like defence in depth and was
       in fact a data-loss bug.

       session_regenerate_id(true) deletes the old file. The storefront fires
       requests in parallel — the account view alone issues account_profile,
       my_orders and address_list at the same moment — so a burst arriving on
       the other side of the re-key does this: the first request regenerates
       and destroys the file the second request is still holding a cookie for,
       use_strict_mode mints that second request a fresh empty session, it sees
       no user_id, and answers 401. Worse, each response carries a different
       Set-Cookie, so the browser keeps whichever arrived last — an empty
       session. The customer is signed out permanently, while the user object in
       localStorage still claims they are signed in, and every later request
       keeps 401ing against that stale belief.

       The re-key that matters already happens where privilege changes:
       api.php regenerates immediately after a verified OTP, before
       user_id is written. That is the moment a fixated id could be promoted
       to an authenticated one, so it is the only moment worth paying for.
       OWASP's own guidance agrees — regenerate on authentication, not on a
       timer — and it is the version that cannot race with itself. */
}

/* ═══════════════════════════════════════════════════════════════════════════
DATABASE
═══════════════════════════════════════════════════════════════════════════ */
/* Both are always defined: $pdo on failure is null rather than unset, so a
   `global $pdo` in a page cannot fatal, and $velora_db_error is how the rest
   of the application asks *why* without re-trying the connection. */
$pdo = null;
$velora_db_error = null;
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone='+03:30', sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
    ]);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
} catch (PDOException $e) {
    error_log('[VELORA DB] ' . $e->getMessage());
    /* Used to end the request here, with a JSON 500. That made a database
       outage blank the *storefront* — a page that reads products.json and
       never touches $pdo — so a dead database took the window down with it,
       and the customer saw an empty white page instead of the catalogue they
       came for. The connection is not the page.

       So the failure is recorded and the include continues. The three
       consumers each decide for themselves: the storefront prints one banner
       and renders, api.php refuses every action with the sentence below, and
       the startup audit writes the cause to the log. Nothing re-tries the
       connection, because nothing here can fix it. */
    $pdo = null;
    $velora_db_error = [
        'error'   => 'DB_FAIL',
        'message' => velora_db_fail_message($e),
    ];
}

/**
 * Why the database is not there, or null when it is.
 *
 * Returns the array rather than a boolean because the whole point of this is
 * the sentence — "check VELORA_DB_HOST and VELORA_DB_PORT" and "import db.sql"
 * are different afternoons, and a bare true cannot carry either.
 */
function velora_db_down(): ?array {
    global $velora_db_error;
    return is_array($velora_db_error) ? $velora_db_error : null;
}

/**
 * What to tell the customer, given a database failure.
 *
 * Covers both kinds: the connection that never opened (config.php's catch) and
 * the query that ran against it (api.php's catch, where a table that was never
 * imported is the common case and is reported as a bare 500 "خطای سرور" in
 * production).
 *
 * Deliberately does not include the exception's own message: a PDO exception
 * carries the username and sometimes the password, and this leaves the server.
 * It carries only a classification, and the classification is what decides the
 * fix — which is also the whole point: none of these four is "try again".
 */
function velora_db_fail_message(PDOException $e): string {
    $m   = $e->getMessage();
    $sql = (int) ($e->getCode() ?: 0);
    $driver = is_array($e->errorInfo ?? null) ? (int) ($e->errorInfo[1] ?? 0) : 0;

    /* ── Connection ── */
    if (stripos($m, 'getaddrinfo') !== false || stripos($m, 'Name or service not known') !== false
        || stripos($m, 'php_network_getaddresses') !== false) {
        return 'دامنهٔ سرور پایگاه داده پیدا نشد — مقدار VELORA_DB_HOST را بررسی کنید.';
    }
    if ($sql === 1045 || $driver === 1045 || stripos($m, 'Access denied') !== false) {
        return 'نام کاربر یا گذرواژهٔ پایگاه داده درست نیست — مقدار VELORA_DB_USER و VELORA_DB_PASS را بررسی کنید.';
    }
    if ($sql === 1049 || $driver === 1049 || stripos($m, 'Unknown database') !== false) {
        return 'پایگاه داده ساخته نشده یا نام آن با VELORA_DB_NAME نمی‌خواند.';
    }
    if ($sql === 1040 || $driver === 1040 || stripos($m, 'Too many connections') !== false) {
        return 'اتصال‌های سرور پر است؛ چند لحظه بعد دوباره تلاش کنید.';
    }
    /* Nothing is listening. This is the failure a local or freshly provisioned
       server actually produces, and its text is "No connection could be made
       because the target machine actively refused it" — which contains neither
       "Connection refused" nor any word the branches above look for, so it used
       to fall through to the neutral fallback and tell the operator nothing.
       2002 is also what MySQL reports when VELORA_DB_HOST says localhost but the
       socket path is wrong, which is a different fix from a bad password. */
    if ($sql === 2002 || $driver === 2002 || $sql === 2003 || $driver === 2003
        || stripos($m, 'actively refused') !== false
        || stripos($m, 'No connection could be made') !== false
        || stripos($m, 'Connection refused') !== false) {
        return 'به سرور پایگاه داده وصل نشد — مقدار VELORA_DB_HOST و VELORA_DB_PORT را بررسی کنید.';
    }

    /* ── Query ── */

    /* The one that matters: the schema was never imported, so every write and
       most reads fail with "table doesn't exist" and are reported in production
       as a generic 500. That is indistinguishable from a bug in the save, and
       it is exactly what a half-installed site looks like. The fix is a single
       import, so the message says that and names the table. */
    if ($sql === 42 || $driver === 42 || $driver === 1146 || stripos($m, "doesn't exist") !== false
        || stripos($m, 'Base table or view not found') !== false) {
        $table = preg_match('/(?:Table or view[^`]*\s|[`\x27])([A-Za-z0-9_]+)\.(?:[`\x27])?([A-Za-z0-9_]+)/',
                            $m, $tm) ? $tm[0] : '';
        $table = preg_replace('/^[`\x27]|[`\x27]$/', '', $table);
        $table = preg_replace('/^[A-Za-z0-9_]+\./', '', $table);
        return 'جدولی از جدول‌های سایت ساخته نشده'
             . ($table !== '' ? ' (جدول «' . $table . '»)' : '')
             . ' — فایل db.sql را در phpMyAdmin وارد کنید.';
    }
    if ($driver === 1062) return 'این رکورد قبلاً ثبت شده است.';
    if ($driver === 1451 || $driver === 1452) {
        return 'رکورد وابسته به آن وجود ندارد یا حذف شده است — صفحه را تازه کنید.';
    }
    if ($driver === 1213 || $driver === 1205) {
        return 'همزمان‌یک نوشته دیگر در حال ثبت است؛ چند لحظه بعد دوباره تلاش کنید.';
    }
    if (stripos($m, 'unable to open') !== false || stripos($m, 'server has gone away') !== false
        || stripos($m, 'Connection refused') !== false) {
        return 'سرور پایگاه داده پاسخ نمی‌دهد؛ چند لحظه بعد دوباره تلاش کنید.';
    }
    return 'خطا در ارتباط با پایگاه داده.';
}

/* ═══════════════════════════════════════════════════════════════════════════
PRODUCT CATALOGUE · products.json
═══════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/catalog.php';

/* ═══════════════════════════════════════════════════════════════════════════
STARTUP CONFIG AUDIT
═══════════════════════════════════════════════════════════════════════════ */
/* The database argument is nullable: the audit runs on a server whose
   connection failed too, and it is precisely then that the gateway, catalogue
   and permission problems below are worth writing down. */
(static function (?PDO $pdo, ?array $dbError): void {
    if (APP_ENV !== 'production') return;

    $problems = [];

    if ($dbError !== null) {
        $problems[] = 'The database is unreachable: ' . $dbError['message']
                    . ' The storefront still renders from products.json, but sign-in, '
                    . 'addresses, orders and the admin panel cannot work until it connects.';
    }

    /* A leftover VELORA_ACTIVE_GATEWAY in .env is not an error any more —
       there is only one gateway — but it is worth saying out loud, because an
       operator who set it to "zibal" will otherwise wonder why their change
       had no effect. */
    $gwEnv = strtolower(trim((string) env('VELORA_ACTIVE_GATEWAY', '')));
    if ($gwEnv !== '' && $gwEnv !== ACTIVE_GATEWAY) {
        $problems[] = 'VELORA_ACTIVE_GATEWAY is "' . $gwEnv . '" but ZarinPal is the only gateway '
                    . 'this build supports; the setting is ignored. Remove it from .env.';
    }
    if (is_placeholder_merchant(ZARINPAL_MERCHANT)) {
        $problems[] = 'VELORA_ZARINPAL_MERCHANT is still a placeholder ("'
                    . substr(ZARINPAL_MERCHANT, 0, 24) . '"). EVERY checkout will fail with '
                    . 'PAYMENT_INIT_FAILED. This is a hard revenue outage, not a degraded mode.';
    }

    if (OTP_DEBUG) {
        $problems[] = 'VELORA_OTP_DEBUG is enabled in production. Every one-time login code is being '
                    . 'returned in the API response and written to the error log. Set it to 0.';
    }

    if (INSTALL_TOKEN !== '') {
        $problems[] = 'VELORA_INSTALL_TOKEN is still set. Clear it (set to "") once installation is '
                    . 'finished — it is a secret sitting in the document root.';
    }

    if (ADMIN_HASH_CONFIGURED) {
        $info = password_get_info(ADMIN_PASS_HASH);
        $cost = (int) ($info['options']['cost'] ?? 0);
        if ($cost > 0 && $cost < 12) {
            $problems[] = 'VELORA_ADMIN_PASS uses bcrypt cost ' . $cost . '; 12 or higher is required. '
                        . 'Re-hash with password_hash($pw, PASSWORD_DEFAULT).';
        }
    }

    if (!is_https()) {
        $problems[] = 'Request did not arrive over HTTPS. The session cookie is being issued without the '
                    . 'Secure flag and no HSTS is sent. Check the TLS terminator and APP_URL.';
    }

    $catalogFile = velora_catalog_path();
    if (!is_file($catalogFile)) {
        $problems[] = 'products.json not found at ' . $catalogFile
                    . '. The storefront will render an empty catalogue and every checkout will fail. '
                    . 'Restore the file from version control.';
    } elseif (!is_writable($catalogFile)) {
        $problems[] = 'products.json at ' . $catalogFile . ' is NOT writable by the web user. Every checkout, '
                    . 'order cancellation and admin save will fail with CATALOG_LOCK_FAIL. Fix the owner/'
                    . 'group and the mode (0640 for the file, 0750 for its directory) on the file, and on '
                    . dirname($catalogFile) . ' for the temp-file rename path.';
    } elseif (!is_writable(dirname($catalogFile))) {
        $problems[] = 'The directory holding products.json (' . dirname($catalogFile) . ') is not writable by '
                    . 'the web user, so the atomic temp-file + rename used by velora_catalog_commit() cannot '
                    . 'complete, and products.json.lock cannot be created. The data file itself is writable, '
                    . 'which is why the storefront still reads and checkout can still lock — but every write '
                    . 'will fail, and checkout degrades to session-only idempotency. Adjust permissions on '
                    . 'the directory.';
    }

    /* Skipped when the connection failed — $dbError above already says so, and
       asking a null handle for information_schema would fatal the include and
       take the storefront back down with it. */
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $st->execute(['velora_checkout_attempts']);
            if ($st->fetchColumn() === false) {
                $problems[] = 'Table velora_checkout_attempts does not exist. Checkout is still correct, but '
                            . 'duplicate-submit protection has fallen back to the session-only path, which cannot '
                            . 'see a second device. Apply the CREATE TABLE in db_schema.sql (SECTION 1).';
            }
            unset($st);
        } catch (Throwable $e) {
            $problems[] = 'Could not verify velora_checkout_attempts: ' . $e->getMessage()
                        . ' The storefront is unaffected; checkout falls back to session-only idempotency.';
        }
    }

    foreach ($problems as $p) {
        error_log('[VELORA CONFIG AUDIT] ' . $p);
    }
})($pdo, $velora_db_error);


/* ═══════════════════════════════════════════════════════════════════════════
RESPONSE HELPERS
═══════════════════════════════════════════════════════════════════════════ */
function jresp(array $data, int $code = 200, bool $cache = false): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header($cache ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!$cache) { header('Pragma: no-cache'); header('Expires: 0'); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
HTTP · security headers · HTTPS detection · canonical URI
═══════════════════════════════════════════════════════════════════════════ */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')    return true;
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)              return true;
    return false;
}

function emit_security_headers(string $csp, bool $allowFraming = true): void {
    if (headers_sent()) return;

    $directives = [$csp];
    if (is_https()) $directives[] = 'upgrade-insecure-requests';

    header('Content-Security-Policy: ' . implode('; ', $directives));

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: ' . ($allowFraming ? 'SAMEORIGIN' : 'DENY'));
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), '
         . 'payment=(self "https://payment.zarinpal.com")');
    header('Cross-Origin-Resource-Policy: same-site');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

/**
 * A same-origin path to one of this installation's own endpoints.
 *
 * Used for the URLs a *running page* fetches — the catalogue feed — as opposed
 * to the URLs a *crawler* reads, which is what APP_URL is for. Conflating the
 * two is what pointed the browser's catalogue probe at the canonical host: the
 * probe then violated the storefront's own `connect-src 'self'`, and the
 * synchronisation feature silently did nothing on any host but that one.
 *
 * Derived from SCRIPT_NAME rather than from APP_URL, so it is correct at the
 * document root ('catalog.php') and in a sub-directory install
 * ('/shop/catalog.php') without either being configured, and it is always on
 * the origin that actually served the request.
 *
 * $file is a literal from this codebase, never user input; it is not escaped
 * because there is nothing to escape, and a value that needed escaping would
 * mean this function was being handed something it should not accept.
 */
function velora_local_path(string $file): string {
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $dir = rtrim($dir, '/');
    /* dirname('/') is '/', and on Windows SCRIPT_NAME can be a backslash
       path; both collapse to the empty base, which is the document root. */
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }
    return $dir . '/' . ltrim($file, '/');
}

function canonical_uri(): string {
    $raw   = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($raw) ?: [];
    $path  = $parts['path'] ?? '/';
    $path  = preg_replace('#/index\.php$#', '/', $path) ?: '/';
    $qs    = [];
    if (isset($parts['query'])) parse_str($parts['query'], $qs);
    $whitelist = ['view', 'product', 'cat', 'sort', 'sale', 'stock', 'q', 'max'];
    $keep = [];
    foreach ($whitelist as $k) {
        if (!isset($qs[$k]) || !is_scalar($qs[$k]) || $qs[$k] === '') continue;
        if ($k === 'sort' && $qs[$k] === 'featured') continue;
        $keep[$k] = (string) $qs[$k];
    }
    if (!empty($keep['product'])) $keep = ['product' => $keep['product']];
    elseif (($keep['view'] ?? '') === 'shop') unset($keep['sort'], $keep['q'], $keep['max']);
    ksort($keep);
    $q = $keep ? '?' . http_build_query($keep, '', '&', PHP_QUERY_RFC3986) : '';
    return APP_URL . $path . $q;
}

function http_404(string $title = 'صفحه یافت نشد | VELORA', string $nonce = ''): void {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store');
    }
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
    $n = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
    echo <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>{$t}</title>
<style{$n}>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#060504;color:#eee;font-family:Vazirmatn,system-ui,sans-serif;text-align:center;padding:2rem}
h1{color:#d9b98a;font-weight:400;letter-spacing:.2em;font-size:2.4rem;margin:0 0 1rem}
p{color:#999;line-height:2;margin:.4rem 0}
a{color:#d9b98a;text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px}</style>
</head><body><div><h1>۴۰۴</h1>
<p>صفحه‌ای که دنبال آن بودید یافت نشد.</p>
<p><a href="/">بازگشت به خانه</a></p></div></body></html>
HTML;
    exit;
}

function http_410(string $title = 'محصول بازنشسته شده | VELORA', string $nonce = ''): void {
    if (!headers_sent()) {
        http_response_code(410);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store');
    }
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
    $n = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
    echo <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>{$t}</title>
<style{$n}>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#060504;color:#eee;font-family:Vazirmatn,system-ui,sans-serif;text-align:center;padding:2rem}
h1{color:#d9b98a;font-weight:400;letter-spacing:.2em;font-size:2.4rem;margin:0 0 1rem}
p{color:#999;line-height:2;margin:.4rem 0}
a{color:#d9b98a;text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px}</style>
</head><body><div><h1>۴۱۰</h1>
<p>این محصول دیگر در کلکسیون ارائه نمی‌شود.</p>
<p><a href="/">بازگشت به خانه</a></p></div></body></html>
HTML;
    exit;
}

/**
 * 503 — the maison is there but cannot be assembled.
 *
 * Distinct from http_404 and http_410 because the crawler's reading differs:
 * a 503 says "ask again later", so a temporary catalogue failure is not
 * deindexed. There are exactly two ways to reach it — products.json unreadable,
 * or the catalogue reader itself missing — and both are operator problems
 * that the startup audit has already written to the log.
 */
function http_503(string $nonce = ''): void {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Retry-After: 120');
        header('Cache-Control: no-store');
    }
    $n = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
    echo <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>ویترین موقتاً در دسترس نیست | VELORA</title>
<style{$n}>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#060504;color:#eee;font-family:Vazirmatn,system-ui,sans-serif;text-align:center;padding:2rem}
 h1{color:#d9b98a;font-weight:400;letter-spacing:.2em;font-size:2.2rem;margin:0 0 1rem}
 p{color:#999;line-height:2;margin:.4rem 0}
 a{color:#d9b98a;text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px}</style>
</head><body><div><h1>۵۰۳</h1>
<p>ویترین موقتاً در دسترس نیست.</p>
<p>چند لحظه بعد دوباره سر بزنید.</p>
<p><a href="/">بازگشت به خانه</a></p></div></body></html>
HTML;
    exit;
}

function esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true);
}

/* ═══════════════════════════════════════════════════════════════════════════
PERSIAN NUMERALS
═══════════════════════════════════════════════════════════════════════════
   fa_num() is byte-for-byte what `(n) => Number(n).toLocaleString('fa-IR')`
   produces in core.js. That is a stricter requirement than it looks, and the
   looser version was in place.

   The server formatted numbers with number_format(), which emits ASCII digits
   and an ASCII comma. The browser formats them with toLocaleString('fa-IR'),
   which emits Persian digits (U+06F0..U+06F9) and U+066C ARABIC THOUSANDS
   SEPARATOR. A comment claimed the two were "one formatter, shared with the
   browser, so the two cannot drift" — they were two formatters and they did
   drift, on the first paint, for every number on the page: the hero read
   ۳۱٬۰۰۰٬۰۰۰ in the markup and 31,000,000 in Latin on screen. On a Persian
   maison the first thing a visitor sees was the wrong script, and it swapped
   the instant any renderer touched the DOM.

   The mapping, taken from the runtime rather than from memory:
       0      → ۰            1000   → ۱٬۰۰۰
       999    → ۹۹۹          1234567890 → ۱٬۲۳۴٬۵۶۷٬۸۹۰
       1500   → ۱٬۵۰۰       0.5     → ۰٫۵
       -1500  → ‎−۱٬۵۰۰   (U+200E LEFT-TO-RIGHT MARK, then U+2212 MINUS)
   The LRM before the minus is not decoration: in an RTL paragraph a bare
   Unicode minus binds to the number on its right and the minus jumps to the
   wrong end of the string.

   All three codepoints are written as escapes rather than as literals. An
   invisible mark is invisible in a diff, in a review, and in a copy-paste;
   \u200E survives all three. */
function fa_num(int|float|string $n): string {
    if (is_string($n)) {
        $n = is_numeric($n) ? $n + 0 : 0;
    }
    $isFloat = is_float($n) && floor($n) !== $n;
    $neg  = $n < 0;
    $abs  = $neg ? -$n : $n;

    /* Grouping is the runtime's, not number_format()'s: fa-IR groups in
       threes from the right with U+066C and takes a fractional part only when
       there is one. Rounding before formatting keeps 26949999.6 from printing
       a digit the browser would not have printed.

       Two earlier versions of this were wrong in a way worth recording,
       because both looked right:
         · strrev(implode(sep, str_split(strrev(s), 3))) — strrev() is
           byte-oriented, so reversing the *joined* string turned "310٬000٬000"
           into eight broken bytes and every digit after the first separator
           stopped matching, printing an empty price for anything over 999.
         · The same idiom without the outer strrev mis-grouped any length that
           is not a multiple of three, because str_split() pads from the left:
           "1500" reversed to "0051", split to ["005","1"], and reassembled as
           ۱٬۰۰۵.

       The separator is therefore anchored from the right by the lookahead
       itself — \B so a leading separator can never be produced, (?!\d) so a
       partial trailing group is never counted — and every byte operation in
       the function stays on the ASCII string, before any digit is mapped. */
    $intPart = (string) (int) floor($abs);
    if (strlen($intPart) > 3) {
        $intPart = preg_replace('/\B(?=(\d{3})+(?!\d))/u', "\u{066C}", $intPart) ?? $intPart;
    }
    $out = $isFloat
        ? $intPart . "\u{066B}" . rtrim(rtrim(substr(sprintf('%.3f', $abs - floor($abs)), 2), '0'), '.')
        : $intPart;

    $out = strtr($out, [
        '0' => "\u{06F0}", '1' => "\u{06F1}", '2' => "\u{06F2}", '3' => "\u{06F3}",
        '4' => "\u{06F4}", '5' => "\u{06F5}", '6' => "\u{06F6}", '7' => "\u{06F7}",
        '8' => "\u{06F8}", '9' => "\u{06F9}",
    ]);
    return $neg ? "\u{200E}\u{2212}" . $out : $out;
}

/** Zero-padded Persian numerals — the counterpart of core.js faPad(). */
function fa_pad(int|string $n, int $width = 2): string {
    $s = (string) max(0, (int) $n);
    if (strlen($s) < $width) {
        $s = str_pad($s, $width, '0', STR_PAD_LEFT);
    }
    return strtr($s, [
        '0' => "\u{06F0}", '1' => "\u{06F1}", '2' => "\u{06F2}", '3' => "\u{06F3}",
        '4' => "\u{06F4}", '5' => "\u{06F5}", '6' => "\u{06F6}", '7' => "\u{06F7}",
        '8' => "\u{06F8}", '9' => "\u{06F9}",
    ]);
}

/* ═══════════════════════════════════════════════════════════════════════════
REQUEST INPUT (JSON body → POST → GET)
═══════════════════════════════════════════════════════════════════════════ */
function req_parse_json(): array {
    static $json = null;
    if ($json === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $json = is_array($decoded) ? $decoded : [];
    }
    return $json;
}
function req(string $key, $default = null) {
    $json = req_parse_json();
    if (array_key_exists($key, $json))   return $json[$key];
    if (array_key_exists($key, $_POST))  return $_POST[$key];
    if (array_key_exists($key, $_GET))   return $_GET[$key];
    return $default;
}
function req_has(string $key): bool {
    $json = req_parse_json();
    return array_key_exists($key, $json) || array_key_exists($key, $_POST) || array_key_exists($key, $_GET);
}
function req_int(string $key, int $default = 0): int { $v = req($key, $default); return is_numeric($v) ? (int) $v : $default; }
function req_str(string $key, string $default = ''): string { $v = req($key, $default); return is_string($v) ? trim($v) : $default; }
function req_json(string $key, array $default = []): array {
    $v = req($key);
    if (is_array($v)) return $v;
    if (is_string($v) && $v !== '') { $decoded = json_decode($v, true); if (is_array($decoded)) return $decoded; }
    return $default;
}
function req_bool(string $key, bool $default = false): bool {
    if (!req_has($key)) return $default;
    $v = req($key);
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return $v != 0;
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if ($s === '') return false;
        return !in_array($s, ['0', 'false', 'no', 'off', 'null'], true);
    }
    return $default;
}

/* ═══════════════════════════════════════════════════════════════════════════
AUTH
═══════════════════════════════════════════════════════════════════════════ */
function is_admin(): bool {
    return !empty($_SESSION['admin']) && $_SESSION['admin'] === true && !empty($_SESSION['admin_login_at']) && (time() - (int) $_SESSION['admin_login_at']) < ADMIN_SESSION_TTL;
}
function is_user(): bool { return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0; }
function current_user_id(): int { return (int) ($_SESSION['user_id'] ?? 0); }
function current_user_phone(): ?string { return $_SESSION['user_phone'] ?? null; }

/* ═══════════════════════════════════════════════════════════════════════════
DIGIT NORMALIZATION
═══════════════════════════════════════════════════════════════════════════ */
/**
 * Persian and Arabic-Indic digits to ASCII, leaving everything else alone.
 *
 * Every field the customer can type a number into needs this, and the reason
 * is not pedantry: preg_replace('/\D/', '', $s) operates on bytes, and a
 * Persian digit is two of them, neither of which is in [0-9]. So a postal code
 * typed in the digits the form itself displays came out the other side as the
 * empty string — not an error, a silence. The field looked accepted, the
 * checksum never ran, and the parcel went out with no postal code at all.
 *
 * normalize_phone() below does this and more, because a phone number also has
 * to be told apart from +98 and 0098. This one is for the fields where the
 * only question is which glyph the digit is drawn with.
 */
function normalize_digits(string $raw): string {
    return strtr($raw, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}

/* ═══════════════════════════════════════════════════════════════════════════
PHONE NORMALIZATION
═══════════════════════════════════════════════════════════════════════════ */
function normalize_phone(string $raw): ?string {
    $raw = normalize_digits($raw);
    $p = preg_replace('/\D+/', '', $raw);
    if ($p === '' || $p === null) return null;
    if (str_starts_with($p, '0098'))      $p = '0' . substr($p, 4);
    elseif (str_starts_with($p, '98') && strlen($p) === 12) $p = '0' . substr($p, 2);
    elseif (str_starts_with($p, '9') && strlen($p) === 10)  $p = '0' . $p;
    if (!preg_match('/^09\d{9}$/', $p)) return null;
    return $p;
}

/* ═══════════════════════════════════════════════════════════════════════════
SMS · MELIPAYAMAK

MeliPayamak's /api/send/otp/{token} endpoint GENERATES the OTP itself and
returns it in the response `code` field. We POST only the phone number — no
text, no sender — because the panel owns both the code and the message
template. Per the vendor documentation:

    «محتوای code را در سامانه خود جهت ارزیابی کاربر ذخیره کنید»

So the value we persist is the one the panel returned, never one we invented.
Response shape on success:
    {"code":"3741437414","status":"..."}
On failure, `code` is empty/absent and `status` carries a Persian message.
═══════════════════════════════════════════════════════════════════════════ */
function meli_send_otp(string $phone): array {
    if (MELI_API === '') {
        error_log('[MELI] API URL not configured');
        return ['ok' => false, 'error' => 'MELI_NOT_CONFIGURED'];
    }

    $payload = json_encode(['to' => $phone], JSON_UNESCAPED_UNICODE);
    if ($payload === false) return ['ok' => false, 'error' => 'JSON_ENCODE'];

    $ch = curl_init(MELI_API);
    if (!$ch) return ['ok' => false, 'error' => 'CURL_INIT'];

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'User-Agent: VELORA-API/9.5',
        ],
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        error_log('[MELI] cURL: ' . $err);
        return ['ok' => false, 'error' => 'CURL_FAIL', 'detail' => $err];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'INVALID_RESPONSE', 'raw' => substr($raw, 0, 200)];
    }

    $otp    = isset($data['code']) && is_string($data['code']) ? trim($data['code']) : '';
    $status = isset($data['status']) && is_string($data['status']) ? trim($data['status']) : '';

    if ($otp === '' || preg_match('/^\d{4,10}$/', $otp) !== 1) {
        return [
            'ok'      => false,
            'error'   => 'GATEWAY_REJECTED',
            'http'    => $http,
            'status'  => $status,
            'gateway' => $data,
        ];
    }

    return [
        'ok'      => true,
        'otp'     => $otp,
        'http'    => $http,
        'status'  => $status,
        'gateway' => $data,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
CSRF
═══════════════════════════════════════════════════════════════════════════ */
function csrf_token(): string {
    if (empty($_SESSION['csrf']) || (int) ($_SESSION['csrf_at'] ?? 0) < time() - 3600) {
        $_SESSION['csrf']    = bin2hex(random_bytes(32));
        $_SESSION['csrf_at'] = time();
    }
    return $_SESSION['csrf'];
}

function csrf_allowed_hosts(): array {
    static $hosts = null;
    if ($hosts !== null) return $hosts;

    $hosts = [strtolower(APP_HOST)];
    if (str_starts_with(APP_HOST, 'www.')) {
        $hosts[] = substr(APP_HOST, 4);
    } else {
        $hosts[] = 'www.' . APP_HOST;
    }

    if (velora_server_is_loopback()) {
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';
        $hosts[] = '[::1]';
    } elseif (APP_ENV !== 'production') {
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';
        $hosts[] = '[::1]';
    }
    return array_values(array_unique($hosts));
}

function velora_server_is_loopback(): bool {
    $addr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    if ($addr === '') {
        return false;
    }
    if ($addr === '::1' || strtolower($addr) === '0:0:0:0:0:0:0:1') return true;
    if (str_starts_with($addr, '127.')) {
        return (bool) preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $addr);
    }
    return false;
}

function csrf_check(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin !== '') {
        $parsed = parse_url((string) $origin);
        $host = strtolower((string) ($parsed['host'] ?? ''));

        if ($host === '' || !in_array($host, csrf_allowed_hosts(), true)) {
            log_action('CSRF_ORIGIN_MISMATCH', [
                'origin'    => $origin,
                'host'      => $host,
                'expected'  => implode(',', csrf_allowed_hosts()),
                'app_url'   => APP_URL,
                'server'    => (string) ($_SERVER['SERVER_ADDR'] ?? ''),
                'loopback'  => velora_server_is_loopback() ? 1 : 0,
                'env'       => APP_ENV,
                'ip'        => get_client_ip(),
            ]);
            jresp([
                'ok'        => false,
                'error'     => 'CSRF_ORIGIN_INVALID',
                'token'     => csrf_token(),
                'host'      => $host,
                'expected'  => csrf_allowed_hosts(),
                'app_url'   => APP_URL,
            ], 403);
        }
    }

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($sent) || $sent === '') {
        $bodyToken = array_key_exists('csrf', $_POST) ? $_POST['csrf'] : (req_parse_json()['csrf'] ?? null);
        $sent = is_string($bodyToken) ? $bodyToken : '';
    }
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        log_action('CSRF_REJECTED', ['ip' => get_client_ip(), 'origin' => $origin]);
        jresp(['ok' => false, 'error' => 'CSRF_INVALID', 'token' => csrf_token()], 403);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
RATE LIMIT & CLIENT IP & LOGGING
═══════════════════════════════════════════════════════════════════════════ */
function rate_limit(string $action, int $max = 5, int $window = 60, ?string $scope = null): bool {
    $safeAction = preg_replace('/[^A-Za-z0-9_-]+/', '_', $action) ?: 'rl';
    $bucketId   = ($scope !== null && $scope !== '') ? $scope : get_client_ip();
    $key        = $safeAction . ':' . hash('xxh3', $bucketId);
    $dir = __DIR__ . '/storage/cache/rl';
    /* The directory is created once, at boot, by the block that also creates
       storage/cache and storage/logs. This called @mkdir() on every single
       rate-limited request — a stat plus a syscall per call, on a path that
       already exists — and rate_limit() is on nearly every action in api.php.

       It was defensive: the directory could be deleted by a deploy, a cron
       tmpfiles policy, or a misconfigured shared host, and mkdir is cheap
       insurance against that. But the cost was paid unconditionally rather than
       when it was needed, and the boot block plus velora_ratelimit_sweep() is a
       better place for it: once per request at most, and only when a counter is
       actually about to be written. */
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('[VELORA RATE LIMIT] counter directory unavailable: ' . $dir);
        log_action('RATE_LIMIT_STORAGE_FAIL', ['action' => $action, 'fail_closed' => true]);
        return false;
    }
    $file = $dir . '/' . $key . '.json';
    $fp = @fopen($file, 'c+');

    $failOpenActions = [
        'browse_products'   => true,
        'browse_product'    => true,
        'browse_reviews'    => true,
        'pay_status'        => true,
        'my_orders'         => true,
        'pay_cb'            => true,
        'admin_read'        => true,
    ];
    $failClosed = !isset($failOpenActions[$action]);
    if (!$fp) {
        error_log('[VELORA RATE LIMIT] counter unavailable, action=' . $action);
        log_action('RATE_LIMIT_STORAGE_FAIL', ['action' => $action, 'fail_closed' => $failClosed]);
        return !$failClosed;
    }
    $bucket = ['count' => 0, 'reset' => time() + $window];
    if (flock($fp, LOCK_EX)) {
        $now = time();
        $r = fread($fp, 8192);
        if ($r !== false && $r !== '') {
            $x = json_decode($r, true);
            if (is_array($x) && isset($x['reset'], $x['count'])) $bucket = $x;
        }
        if (($bucket['reset'] ?? 0) < $now) $bucket = ['count' => 0, 'reset' => $now + $window];
        $bucket['count']++;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($bucket)); fflush($fp); flock($fp, LOCK_UN);
    }
    fclose($fp);
    if (random_int(1, 100) === 1) {
        velora_ratelimit_sweep($dir);
    }
    return $bucket['count'] <= $max;
}

/**
 * Housekeeping for the rate-limit counters.
 *
 * Two problems with doing this inline, which is where it used to be — twice
 * inside rate_limit(), behind a 1-in-100 roll.
 *
 * First, glob() twice over the whole directory. The sweep needed a list to
 * delete by age, then a *second* identical list to enforce the file cap, and
 * with maxFiles at 5000 that is up to 10,000 directory entries read and sorted
 * on a live request. On a busy shop the counter directory holds one file per
 * (action, bucket) pair, so that is not a small number — it grows with traffic.
 *
 * Second, and worse, it ran inside the lock-free tail of a function whose whole
 * job is to be fast and predictable, on the request a customer is waiting for.
 * A sweep that can take tens of milliseconds has no business being a lottery
 * ticket on checkout.
 *
 * So: one glob instead of two (the second pass reuses the first's result),
 * sorted once instead of twice, and it runs *after* the bucket has been written
 * and released — so the counter this request depends on is already durable and
 * the sweep cannot delay or roll it back. It is still amortised at 1%, because
 * the invariant it maintains — the directory does not grow without bound — is
 * eventually-consistent by nature.
 */
function velora_ratelimit_sweep(string $dir): void {
    $files = glob($dir . '/*.json') ?: [];
    $n = count($files);
    if ($n === 0) return;

    /* Age sweep. */
    $cutoff = time() - 3600;
    foreach ($files as $f) {
        $m = @filemtime($f);
        if ($m !== false && $m < $cutoff) @unlink($f);
    }

    /* Cap sweep, reusing the same listing. One sort serves both the oldest-first
       deletion order and the count. Only re-glob when the age sweep actually
       removed something, since otherwise the listing is still accurate. */
    $maxFiles = 5000;
    if ($n <= $maxFiles) return;
    $live = ($n < count($files)) ? (glob($dir . '/*.json') ?: []) : $files;
    $over = count($live) - $maxFiles;
    if ($over <= 0) return;
    sort($live, SORT_STRING);
    foreach (array_slice($live, 0, $over) as $f) @unlink($f);
}

function rate_limit_reset(string $action, string $scope): void {
    $safeAction = preg_replace('/[^A-Za-z0-9_-]+/', '_', $action) ?: 'rl';
    if ($scope === '') return;
    $file = __DIR__ . '/storage/cache/rl/' . $safeAction . ':' . hash('xxh3', $scope) . '.json';
    if (is_file($file)) @unlink($file);
}

function ip_in_cidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '') return false;
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$subnet, $bitsRaw] = explode('/', $cidr, 2);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) return false;
    $bits = (int) $bitsRaw;

    $ipBin    = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) return false;
    if (strlen($ipBin) !== strlen($subnetBin)) return false;

    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) return false;
    if ($bits === 0) return true;

    $wholeBytes    = intdiv($bits, 8);
    $remainingBits = $bits % 8;
    if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) return false;
    if ($remainingBits === 0) return true;
    $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;
    return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
}

function admin_ip_allowed(string $ip): bool {
    $raw = env('VELORA_ADMIN_IP_ALLOWLIST', '');
    $list = array_filter(array_map('trim', explode(',', is_string($raw) ? $raw : '')));
    if (!$list) return true;
    foreach ($list as $entry) {
        if ($entry === '') continue;
        if (ip_in_cidr($ip, $entry)) return true;
    }
    return false;
}

function get_client_ip(): string {
    $remote  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) return '0.0.0.0';

    $trusted = array_filter(array_map('trim', explode(',', env('VELORA_TRUSTED_PROXIES', '') ?? '')));
    if ($trusted) {
        $isTrusted = false;
        foreach ($trusted as $t) {
            if ($t === '') continue;
            if (ip_in_cidr($remote, $t)) { $isTrusted = true; break; }
        }
        if ($isTrusted) {
            $chain = [];
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $c) {
                    $c = trim($c);
                    if (filter_var($c, FILTER_VALIDATE_IP)) $chain[] = $c;
                }
            }
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $cand = $chain[$i];
                $candTrusted = false;
                foreach ($trusted as $t) {
                    if ($t !== '' && ip_in_cidr($cand, $t)) { $candTrusted = true; break; }
                }
                if (!$candTrusted) return $cand;
            }
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
                if (empty($_SERVER[$h])) continue;
                $c = trim((string) $_SERVER[$h]);
                if (filter_var($c, FILTER_VALIDATE_IP)) return $c;
            }
        }
    }
    return $remote;
}

/* Strip first, then cap.
   The order is the whole point. Capping first and stripping after would let a
   cut land in the middle of a multi-byte control sequence and re-form one, so
   a value could synthesise an escape that was never in the input. Stripping
   first makes the cap safe by construction. The `/u` attempt handles valid
   UTF-8 in one pass; on malformed input preg_replace returns null, and the
   byte-class fallback is there so a corrupt value still cannot inject a raw
   control byte into the log. */
function log_safe(string $value, int $max = 200): string {
    $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]+/u', ' ', $value) ?? '';
    if ($clean === '') {
        $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]+/', ' ', $value) ?? '[unencodable]';
    }
    if (function_exists('mb_substr')) $clean = mb_substr($clean, 0, $max, 'UTF-8');
    else $clean = substr($clean, 0, $max);
    return $clean;
}

function log_line(string $line, int $max = 2000): void {
    error_log(log_safe($line, $max));
}

function log_action(string $action, array $data = []): void {
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($encoded === false) $encoded = '{"_encode_error":true}';
    $line = sprintf("[%s] [%s] [%s] %s | %s\n", date('Y-m-d H:i:s'), get_client_ip(), substr(session_id(), 0, 12), $action, $encoded);

    static $writes = 0;
    $file = __DIR__ . '/storage/logs/velora.log';

    if ((++$writes % 64) === 1) {
        $maxBytes = 8 * 1024 * 1024;
        $size = @filesize($file);
        if ($size !== false && $size > $maxBytes) {
            $rotated = $file . '.1';
            @rename($file, $rotated);
            @chmod($rotated, 0640);
        }
    }

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/* ═══════════════════════════════════════════════════════════════════════════
VOUCHER VALIDATION & HELPERS
═══════════════════════════════════════════════════════════════════════════ */
/**
 * The voucher table — the single source of truth for both validation and the
 * browser.
 *
 * This map used to live inline inside validate_voucher(), which meant the
 * browser could not know what a code was worth, so data.js invented one:
 * `VELORA10`, a 10% code that appears nowhere on the server. A customer was
 * shown a 10% discount in the bag and in the checkout summary, and
 * validate_voucher() returned INVALID_CODE and charged the full price. The
 * page also advertised WELCOME10 in the announcement bar, so the shop named two
 * different codes.
 *
 * index.php now publishes this exact map as window.VELORA_VOUCHERS, which is
 * what PROMO in data.js is built from. A new code is one edit here.
 *
 * `cap` matters as much as `value`: the client used to apply an uncapped
 * percentage, so on a basket above the cap it would show a larger discount
 * than the server charges. The cap is published for exactly that reason.
 *
 * Every code is a percentage. The 'ship' type and the SHIP0 code went away with
 * the shipping charge: a voucher whose only effect was to waive 680,000 that
 * nobody is charged any more is a code that takes money at the till and gives
 * nothing back, and it would still have validated.
 */
function velora_voucher_map(): array {
    return [
        'VEL10'     => ['type' => 'pct', 'value' => 10, 'cap' => 5_000_000,  'min' => 0],
        'WELCOME10' => ['type' => 'pct', 'value' => 10, 'cap' => 3_000_000,  'min' => 0, 'first_only' => true],
        'VIP20'     => ['type' => 'pct', 'value' => 20, 'cap' => 12_000_000, 'min' => 80_000_000],
    ];
}

function validate_voucher(string $code, int $subtotal, ?int $userId = null): array {
    $code = strtoupper(trim($code));
    $map = velora_voucher_map();
    if (!isset($map[$code])) return ['valid' => false, 'error' => 'INVALID_CODE'];
    $v = $map[$code];
    if ($subtotal < $v['min']) return ['valid' => false, 'error' => 'MIN_NOT_MET'];
    if (!empty($v['first_only']) && $userId) {
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return ['valid' => false, 'error' => 'DB_UNAVAILABLE'];
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM velora_orders
            WHERE user_id = ? AND payment_status = 'paid'");
        $st->execute([$userId]);
        if ((int) $st->fetchColumn() > 0) return ['valid' => false, 'error' => 'FIRST_ONLY'];
    }
    $discount = (int) min($subtotal * $v['value'] / 100, $v['cap']);
    return ['valid' => true, 'code' => $code, 'discount' => $discount, 'label' => $v['value'] . '٪ تخفیف'];
}

function secure_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function generate_order_id(): string { return 'VL-' . strtoupper(bin2hex(random_bytes(6))); }

/* ═══════════════════════════════════════════════════════════════════════════
OTP CODE STORAGE — keyed digest
═══════════════════════════════════════════════════════════════════════════ */
function velora_mac_key(string $purpose): string {
    $secret = env('VELORA_APP_KEY', '');
    if ($secret === null || $secret === '') {
        $secret = (string) DB_PASS;
    }
    return hash_hmac('sha256', 'velora:' . $purpose, $secret, true);
}

function otp_digest(string $phone, string $code): string {
    return hash_hmac('sha256', 'otp:' . $phone . ':' . $code, velora_mac_key('otp'));
}

function otp_matches(string $phone, string $code, string $storedDigest): bool {
    if ($storedDigest === '' || $code === '') return false;
    return hash_equals($storedDigest, otp_digest($phone, $code));
}

/* ═══════════════════════════════════════════════════════════════════════════
ADMIN TOTP SECOND FACTOR — RFC 6238
═══════════════════════════════════════════════════════════════════════════ */
defined('VELORA_TOTP_PERIOD') or define('VELORA_TOTP_PERIOD', 30);
defined('VELORA_TOTP_DIGITS') or define('VELORA_TOTP_DIGITS', 6);
defined('VELORA_TOTP_WINDOW') or define('VELORA_TOTP_WINDOW', 1);

function admin_totp_secret_ciphertext(): string {
    $v = env('VELORA_ADMIN_TOTP_SECRET', '');
    return is_string($v) ? trim($v) : '';
}

function admin_totp_enabled(): bool {
    return admin_totp_secret_ciphertext() !== '';
}

function velora_base32_decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[\s=-]+/', '', $b32) ?? '');
    if ($b32 === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) return '';
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function velora_base32_encode(string $bin): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $n = strlen($bits); $i + 5 <= $n; $i += 5) {
        $out .= $alphabet[bindec(substr($bits, $i, 5))];
    }
    if ($i < $n) {
        $out .= $alphabet[bindec(str_pad(substr($bits, $i), 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function admin_totp_key(): string {
    return hash_hmac('sha256', 'velora:totp:aead', velora_mac_key('totp'), true);
}

function admin_totp_decrypt(string $cipherB64): string {
    if ($cipherB64 === '' || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode($cipherB64, true);
    if ($raw === false || strlen($raw) <= 28) return '';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', admin_totp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function velora_admin_totp_encrypt(string $base32Secret): string {
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('ext-openssl is required for VELORA_ADMIN_TOTP_SECRET');
    }
    $raw = velora_base32_decode($base32Secret);
    if ($raw === '') {
        throw new RuntimeException('secret is not valid base32');
    }
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($raw, 'aes-256-gcm', admin_totp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false || strlen($tag) !== 16) {
        throw new RuntimeException('encryption failed');
    }
    return base64_encode($iv . $tag . $ct);
}

function admin_totp_code(string $rawSecret, int $counter): string {
    $binCounter = pack('J', $counter);
    $hmac  = hash_hmac('sha1', $binCounter, $rawSecret, true);
    $index = ord($hmac[19]) & 0x0F;
    $value = ((ord($hmac[$index]) & 0x7F) << 24)
           | ((ord($hmac[$index + 1]) & 0xFF) << 16)
           | ((ord($hmac[$index + 2]) & 0xFF) << 8)
           |  (ord($hmac[$index + 3]) & 0xFF);
    $modulo = 10 ** VELORA_TOTP_DIGITS;
    return str_pad((string) ($value % $modulo), VELORA_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

function admin_totp_verify(string $rawSecret, string $code, ?int $now = null): bool {
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== VELORA_TOTP_DIGITS) return false;
    $now   = $now ?? time();
    $step  = intdiv($now, VELORA_TOTP_PERIOD);
    $ok    = false;
    for ($d = -VELORA_TOTP_WINDOW; $d <= VELORA_TOTP_WINDOW; $d++) {
        if (hash_equals(admin_totp_code($rawSecret, $step + $d), $code)) $ok = true;
    }
    return $ok;
}

function admin_totp_secret(): string {
    static $cached = null;
    static $resolved = false;
    if ($resolved) return $cached;
    $resolved = true;
    $cached = admin_totp_decrypt(admin_totp_secret_ciphertext());
    if ($cached === '') {
        error_log('[VELORA TOTP] VELORA_ADMIN_TOTP_SECRET is set but could not be decrypted. '
            . 'Either VELORA_APP_KEY changed since it was generated, or the value is corrupt. '
            . 'Every admin login will now fail closed. Re-encrypt the secret or clear the key to disable.');
    }
    return $cached;
}

function admin_totp_provision_uri(string $rawSecret, string $account = 'admin'): string {
    $label = rawurlencode('VELORA:' . $account);
    return 'otpauth://totp/' . $label
        . '?secret=' . velora_base32_encode($rawSecret)
        . '&issuer=' . rawurlencode('VELORA')
        . '&algorithm=SHA1&digits=' . VELORA_TOTP_DIGITS . '&period=' . VELORA_TOTP_PERIOD;
}