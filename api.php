<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
/* ZarinPal is the only gateway. payment.php (Zibal) has been deleted along with
   its branch through checkout and through payment_callback, so there is one
   code path to keep correct rather than two kept correct in parallel. */
require __DIR__ . '/zarinpal.php';
require __DIR__ . '/includes/geo.php';
/* The postal wrapper needs velora_postal_valid() from geo.php, so it is loaded
   after it rather than beside it. Loading it before would fatal on the
   checksum not existing yet. */
require __DIR__ . '/includes/postal.php';

/* The checkout-integrity helpers (idempotency claims, stock restoration,
   payment log) and the address-book validators were extracted from this file
   into includes/checkout.php and includes/addresses.php — one home per
   concern. They used to live here as well as there, byte-for-byte identical;
   two copies of the same claim machine is exactly the drift that costs an
   afternoon. The action handlers under includes/api/ call these functions, so
   they must be loaded before dispatch. */
require __DIR__ . '/includes/checkout.php';
require __DIR__ . '/includes/addresses.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '' && $o !== APP_ORIGIN) { http_response_code(403); exit; }
    header('Access-Control-Allow-Origin: ' . APP_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
    header('Vary: Origin');
    http_response_code(204);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   ORIGIN GATE — same-site only. CSRF token remains the primary guarantee.
   ═══════════════════════════════════════════════════════════════════════════ */
$__req_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$__req_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($__req_origin !== '' && $__req_method === 'POST') {
    $__proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $__proto = 'https';
    $__host_origin = $__proto . '://' . ($_SERVER['HTTP_HOST'] ?? '');

    if ($__req_origin !== APP_ORIGIN && $__req_origin !== $__host_origin) {
        log_action('ORIGIN_DENIED', [
            'sent'         => $__req_origin,
            'expected'     => APP_ORIGIN,
            'host_derived' => $__host_origin,
        ]);
        jresp(['ok' => false, 'error' => 'ORIGIN_DENIED'], 403);
    }
}
unset($__req_origin, $__req_method, $__proto, $__host_origin);

/* ── Address book helpers ──────────────────────────────────────────────────── */

$action = req_str('action');

/* ── Admin IP allow-list ────────────────────────────────────────────────────── */
if ($action !== '' && str_starts_with($action, 'admin_') && !admin_ip_allowed(get_client_ip())) {
    log_action('ADMIN_IP_DENIED', [
        'action' => $action,
        'ip'     => get_client_ip(),
    ]);
    jresp([
        'ok'      => false,
        'error'   => 'ADMIN_IP_DENIED',
        'message' => 'دسترسی از این آی‌پی مجاز نیست',
    ], 403);
}

/* ═══════════════════════════════════════════════════════════════════════════
   NO DATABASE, NO ACTION
   ═══════════════════════════════════════════════════════════════════════════
   config.php no longer ends the request when the connection fails — the
   storefront has to be able to render without one. Every action in the switch
   below does need one, and reaching it with a null handle would fatal into an
   HTML error page, which the client cannot read and reports as a bodyless
   HTTP_500 with no message.

   So the outage is answered once, here, in the same shape the client already
   understands: DB_FAIL with the classified sentence, which is what puts
   "check VELORA_DB_HOST and VELORA_DB_PORT" in the banner instead of a blank
   page. Nothing retries; a retry cannot succeed until someone fixes the
   server. */
/* Actions that are genuinely independent of the database.

   The gate below refuses *every* action when there is no connection, which is
   right for the twenty-odd actions that read or write rows and wrong for the
   few that answer entirely from this codebase's own constants.

   geo_regions is the one that matters. Its data is a literal in includes/geo.php
   — thirty-one provinces and about three hundred and fifty cities — and it is
   the source velora_geo_valid() validates an address against, so the server can
   and does answer it with the database unplugged. Refusing it meant that a
   database outage emptied the checkout's province picker: the customer reached
   step two of three, chose a province, and found no cities, with an error that
   said "database" rather than anything about addresses.

   The storefront is explicitly designed to survive a database outage —
   velora_catalog_health() exists to explain an empty catalogue, the audit writes
   ten deployment hazards to the log, and index.php renders from products.json —
   so an API endpoint that still hard-fails with no database is contradicting the
   rest of the design.

   membership() is a Set lookup over a literal, so it costs nothing to keep it
   answerable too — but there is no `membership` action in this file, so naming
   it would have been a promise to an endpoint that does not exist.

   Only actions that provably touch no table belong here. Anything added to this
   list is a promise that it will not quietly start depending on a query, and it
   is why the list is short and named rather than a pattern. */
const VELORA_DB_INDEPENDENT_ACTIONS = ['geo_regions'];

if (!$pdo instanceof PDO && !in_array($action, VELORA_DB_INDEPENDENT_ACTIONS, true)) {
    $down = velora_db_down() ?? [
        'error'   => 'DB_FAIL',
        'message' => 'اتصال به پایگاه داده برقرار نشد.',
    ];
    log_action('API_NO_DATABASE', ['action' => $action]);
    jresp(['ok' => false, 'error' => $down['error'], 'message' => $down['message']], 500);
}

try {
    /* The former 2.6k-line switch lives in includes/api/<domain>.php, one
       file per domain, resolved through an allow-list built from the
       directory itself (includes/api-handlers.php). Handlers run in this
       same global scope; they answer via jresp() (which exits) or return. */
        require_once __DIR__ . '/includes/api-handlers.php';
    $__handler = velora_api_handler($action);
    if ($__handler !== null) {
        include $__handler;
    } else {
        $diag = [
            'method'         => $_SERVER['REQUEST_METHOD'] ?? '?',
            'content_type'   => $_SERVER['CONTENT_TYPE']   ?? '',
            'content_length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
            'post_keys'      => array_keys($_POST),
            'json_keys'      => array_keys(req_parse_json()),
            'get_keys'       => array_keys($_GET),
        ];
        log_action('UNKNOWN_ACTION', $diag + ['action_raw' => $action]);

        $unknown = ['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action];
        if (APP_DEBUG) $unknown['diag'] = $diag;
        jresp($unknown, 400);
    }
} catch (PDOException $e) {
    /* A query that failed is a different animal from a query that was wrong.
       In production the message below was the only thing the customer saw, and
       "خطای سرور" says nothing about whether they should retry, fix their form,
       or fix the server. velora_db_fail_message() classifies without leaking the
       driver's text (it carries the username), and the classification is what
       the customer acts on — chiefly the missing table, which is what a site
       looks like when db.sql was never imported and is indistinguishable from a
       bug in the save otherwise. */
    log_action('API_DB_ERROR', [
        'action' => $action,
        'sqlstate' => (string) $e->getCode(),
        'driver'   => (int) ($e->errorInfo[1] ?? 0),
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
    ]);
    $missing = $e->errorInfo[1] ?? null;
    $missingTable = ((int) ($missing ?? 0) === 1146)
        || (string) $e->getCode() === '42S02'
        || stripos($e->getMessage(), "doesn't exist") !== false;
    jresp([
        'ok'      => false,
        'error'   => $missingTable ? 'TABLE_MISSING' : 'DB_QUERY_FAIL',
        'message' => velora_db_fail_message($e),
        /* Field the inline form error reads first, so a 500 on the address form
           names the table instead of the generic "نشانی ذخیره نشد". */
        'detail'  => velora_db_fail_message($e),
    ], 500);
} catch (Throwable $e) {
    log_action('API_ERROR', [
        'action' => $action,
        'error'  => $e->getMessage(),
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
    ]);
    jresp([
        'ok'      => false,
        'error'   => 'INTERNAL_ERROR',
        'message' => APP_DEBUG ? $e->getMessage() : 'خطای سرور',
    ], 500);
}