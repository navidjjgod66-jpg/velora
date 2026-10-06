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

require_once __DIR__ . '/includes/env.php';

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
SPLIT-INCLUDE HELPERS · one home per concern
═══════════════════════════════════════════════════════════════════════════
   Every file below guards on VELORA_CONFIG_LOADED, which is defined above, so
   they can rely on the constants and the PDO handle while still being loadable
   exactly once. Order matters only where a helper calls another helper at load
   time: net.php (logging/IP) precedes the files that use it, and each of them
   precedes the API handlers api.php dispatches into. */
require_once __DIR__ . '/includes/http.php';        // jresp · CSP headers · esc · 404/410/503
require_once __DIR__ . '/includes/input.php';       // req_*() request accessors
require_once __DIR__ . '/includes/formatting.php';  // fa_num · fa_pad · digit/phone normalizers
require_once __DIR__ . '/includes/net.php';         // client IP · admin allow-list · log_action
require_once __DIR__ . '/includes/auth.php';        // session identity helpers
require_once __DIR__ . '/includes/csrf.php';        // token + origin checks
require_once __DIR__ . '/includes/orders.php';      // vouchers · order ids · secure tokens
require_once __DIR__ . '/includes/otp.php';         // OTP digest/HMAC helpers
require_once __DIR__ . '/includes/sms.php';         // MeliPayamak OTP sender
require_once __DIR__ . '/includes/totp.php';        // admin TOTP (encrypted secret)