<?php
declare(strict_types=1);
/**
 * VELORA · API handlers — auth
 *
 * Actions handled here: send_otp, verify_otp, logout, me
 *
 * Procedural code extracted verbatim from api.php's action switch.
 * It runs in the request's global scope via velora_api_handler()
 * (includes/api-handlers.php), so $pdo, $_SESSION, req_*(), jresp()
 * and log_action() behave exactly as they did inside the switch.
 * Each case-terminating `break;` became `return;`; jresp() exits on
 * its own, so the return only matters where the original break was.
 */

/* ---- send_otp ---- */
if ($action === 'send_otp') {
csrf_check();
if (!rate_limit('otp', 3, 60)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT', 'message' => 'لطفاً ۶۰ ثانیه صبر کنید'], 429);
}
$phone = normalize_phone(req_str('phone'));
if (!$phone) {
    jresp(['ok' => false, 'error' => 'PHONE_INVALID', 'message' => 'شماره معتبر نیست'], 400);
}

if (!rate_limit('otp_phone_hour', 5, 3600, $phone)) {
    log_action('OTP_PHONE_HOURLY_CAP', ['phone' => $phone]);
    jresp([
        'ok'      => false,
        'error'   => 'RATE_LIMIT',
        'message' => 'تعداد درخواست‌های این شماره زیاد است — بعداً تلاش کنید',
    ], 429);
}

$st = $pdo->prepare("SELECT COUNT(*) FROM velora_otp WHERE phone=? AND created_at > NOW() - INTERVAL 60 SECOND");
$st->execute([$phone]);
if ((int) $st->fetchColumn() > 0) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT', 'message' => 'کد قبلاً ارسال شده است'], 429);
}

/* MeliPayamak's /api/send/otp/{token} endpoint generates the OTP
   itself and returns it in the response `code` field. There is no
   local code to compute — the customer receives whatever the panel
   decided, and we store exactly that value. */
$sent = meli_send_otp($phone);
$code = (string) ($sent['otp'] ?? '');

if (!$sent['ok']) {
    if (OTP_DEBUG) {
        log_line('[OTP_DEBUG] send failed for ' . log_safe($phone)
            . ': ' . log_safe((string) json_encode($sent, JSON_UNESCAPED_UNICODE)));
    }
    log_action('OTP_SEND_FAIL', [
        'phone'   => $phone,
        'error'   => $sent['error'] ?? 'unknown',
        'http'    => $sent['http'] ?? null,
        'status'  => $sent['status'] ?? null,
        'gateway' => $sent['gateway'] ?? null,
    ]);
    $why = 'ارسال پیامک ناموفق بود';
    if (!empty($sent['status'])) {
        $why .= ' · ' . $sent['status'];
    }
    jresp(['ok' => false, 'error' => 'SMS_FAIL', 'message' => $why], 502);
}

if ($code === '') {
    log_action('OTP_EMPTY_CODE', ['phone' => $phone, 'gateway' => $sent['gateway'] ?? null]);
    jresp(['ok' => false, 'error' => 'SMS_FAIL', 'message' => 'ارسال پیامک ناموفق بود'], 502);
}

log_action('OTP_SENT', [
    'phone'    => $phone,
    'status'   => $sent['status'] ?? null,
    'code_len' => strlen($code),
]);

$pdo->prepare("UPDATE velora_otp SET used=1 WHERE phone=? AND used=0")->execute([$phone]);

$st = $pdo->prepare("INSERT INTO velora_otp (phone, code, ip, expires_at) VALUES (?, ?, ?, ?)");
$st->execute([
    $phone,
    otp_digest($phone, $code),
    get_client_ip(),
    time() + OTP_TTL,
]);
$resp = [
    'ok'      => true,
    'message' => 'کد تأیید ارسال شد',
    'ttl'     => OTP_TTL,
];
if (OTP_DEBUG) $resp['debug_code'] = $code;
jresp($resp);
    return;
}

/* ---- verify_otp ---- */
if ($action === 'verify_otp') {
csrf_check();
if (!rate_limit('verify', 8, 60)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$phone = normalize_phone(req_str('phone'));
$code  = trim(req_str('code'));
if (!$phone || !preg_match('/^\d{4,10}$/', $code)) {
    jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
}

if (!rate_limit('otp_attempt', 5, 900, $phone)) {
    log_action('OTP_PHONE_LOCKED', ['phone' => $phone]);
    jresp([
        'ok'      => false,
        'error'   => 'PHONE_LOCKED',
        'message' => 'تلاش بیش از حد برای این شماره — ۱۵ دقیقه صبر کنید',
    ], 429);
}

$st = $pdo->prepare("SELECT id, code FROM velora_otp WHERE phone=? AND used=0 AND expires_at > ? ORDER BY id DESC LIMIT 1");
$st->execute([$phone, time()]);
$matched = $st->fetch();

if (!$matched || !otp_matches($phone, $code, (string) $matched['code'])) {
    log_action('OTP_INVALID', ['phone' => $phone]);
    jresp(['ok' => false, 'error' => 'OTP_INVALID', 'message' => 'کد نامعتبر یا منقضی شده'], 401);
}
rate_limit_reset('otp_attempt', $phone);
$pdo->prepare("UPDATE velora_otp SET used=1 WHERE id=?")->execute([(int) $matched['id']]);

/* ── Two different things share this one OTP challenge ────────────
   Signing in, and adding a second number to an account that is
   already signed in. They look identical from here — a phone and a
   code — and they are told apart by `intent`, which is why the
   client must send it. Getting this wrong is the bug the whole
   phone feature exists to prevent: without the branch, verifying a
   code for someone else's number while signed in would silently log
   you in as them. */
$intent = req_str('intent');

if ($intent === 'add_phone') {
    if (!is_user()) {
        jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED',
               'message' => 'برای افزودن شماره باید وارد حساب خود شوید'], 401);
    }
    require_addressbook();
    if ($phone === (current_user_phone() ?? '')) {
        jresp(['ok' => false, 'error' => 'PHONE_ALREADY_PRIMARY',
               'message' => 'این همان شمارهٔ اصلی حساب شماست'], 400);
    }
    $st = $pdo->prepare("SELECT id FROM velora_users WHERE phone=? LIMIT 1");
    $st->execute([$phone]);
    if ($st->fetchColumn() !== false) {
        /* The number is somebody's *login*. Registering it here
           would make this account able to deliver to, and be
           reached as, an identity that already exists elsewhere. */
        jresp(['ok' => false, 'error' => 'PHONE_TAKEN',
               'message' => 'این شماره به حساب دیگری تعلق دارد'], 409);
    }
    user_phone_remember(current_user_id(), $phone);
    $pdo->prepare("UPDATE velora_user_phones SET last_used_at=NOW() WHERE user_id=? AND phone=?")
        ->execute([current_user_id(), $phone]);
    log_action('PHONE_ADDED', ['user_id' => current_user_id(), 'phone' => $phone]);
    jresp([
        'ok'      => true,
        'message' => 'شمارهٔ همراه تأیید و ذخیره شد',
        'phone'   => $phone,
        'intent'  => 'add_phone',
    ]);
    return;
}

$st = $pdo->prepare("SELECT id, name FROM velora_users WHERE phone=? LIMIT 1");
$st->execute([$phone]);
$user = $st->fetch();
if (!$user) {
    $pdo->prepare("INSERT INTO velora_users (phone) VALUES (?)")->execute([$phone]);
    $uid = (int) $pdo->lastInsertId();
    $uname = null;
} else {
    $uid = (int) $user['id'];
    $uname = $user['name'] ?? null;
    $pdo->prepare("UPDATE velora_users SET last_seen=NOW() WHERE id=?")->execute([$uid]);
}

session_regenerate_id(true);
$_SESSION['csrf']    = bin2hex(random_bytes(32));
$_SESSION['csrf_at'] = time();

$_SESSION['user_id']     = $uid;
$_SESSION['user_phone']  = $phone;
$_SESSION['user_at']     = time();
/* The login number is proven-owned by definition — the customer
   just entered a code sent to it — so it becomes the primary entry
   in the verified set. Without this, an account that has only ever
   signed in would have an empty phone list and checkout would have
   nothing to prefill.

   Both writes are guarded. They run *after* the session is already
   established, but an uncaught exception here would still reach the
   client as a 500 and the customer would believe the sign-in failed
   when it in fact succeeded — then they would tap the code again and
   the OTP would already be spent. A booking write must never be able
   to contradict a login that worked. */
user_phone_remember($uid, $phone);
if (velora_addressbook_ready()) {
    try {
        $pdo->prepare("UPDATE velora_user_phones SET is_primary=1, last_used_at=NOW()
                       WHERE user_id=? AND phone=?")->execute([$uid, $phone]);
    } catch (Throwable $e) {
        log_action('PHONE_PRIMARY_FAIL', ['user_id' => $uid, 'error' => $e->getMessage()]);
    }
}
log_action('USER_LOGIN', ['user_id' => $uid, 'phone' => $phone]);
jresp([
    'ok'      => true,
    'message' => 'ورود موفق',
    'user_id' => $uid,
    'phone'   => $phone,
    'name'    => $uname,
    'csrf'    => $_SESSION['csrf'],
]);
    return;
}

/* ---- logout ---- */
if ($action === 'logout') {
if (empty($_SESSION['user_id'])) {
    jresp(['ok' => true, 'message' => 'خروج موفق', 'already' => true]);
}
csrf_check();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
jresp(['ok' => true, 'message' => 'خروج موفق']);
    return;
}

/* ---- me ----
   200, not 401, for an anonymous visitor.

   `me` is a session PROBE, not a protected resource: the question is "is there a
   signed-in user on this session?", and "no" is a complete and successful answer
   to it. 401 means "present credentials, and they were rejected or absent" —
   which is a statement about access to a resource, and there is no resource here
   that anyone is being kept out of.

   The 401 was visible on every anonymous page load: js/auth.js and
   js/checkout.js both call sessionGet() at boot, so every visitor who is not
   signed in — that is, every first visit — got a red 401 in the console from the
   one endpoint that was answering correctly. Any monitoring or session-replay
   tool reading the browser log counts it as a failed request.

   Nothing on the client branches on this status: sessionGet() reads r.logged,
   and checkout.js wraps the call in try/catch. So this is the same JSON, the
   same meaning, and one less false error.

   The endpoints that DO protect something — my_orders, address_*, checkout —
   keep their 401, because there the status is the answer. */
if ($action === 'me') {
if (!is_user()) {
    jresp(['ok' => true, 'logged' => false]);
}
$st = $pdo->prepare("SELECT id, phone, name, email, created_at FROM velora_users WHERE id=?");
$st->execute([current_user_id()]);
$user = $st->fetch();
/* A session that names a user_id the table does not contain is a stale session,
   not a missing product: answering 404 told the client the account was gone,
   which is a different and much more alarming claim than "that row is gone".
   Clearing the identity and answering "not signed in" lets auth.js fall back to
   the sign-in sheet instead of surfacing a not-found state nobody can act on. */
if (!$user) {
    unset($_SESSION['user_id'], $_SESSION['user_phone'], $_SESSION['user_phone_verified']);
    jresp(['ok' => true, 'logged' => false]);
}
jresp(['ok' => true, 'logged' => true, 'user' => $user]);
    return;
}

