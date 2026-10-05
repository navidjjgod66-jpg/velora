<?php
declare(strict_types=1);
/**
 * VELORA · API handlers — geo
 *
 * Actions handled here: geo_regions, postal_lookup
 *
 * Procedural code extracted verbatim from api.php's action switch.
 * It runs in the request's global scope via velora_api_handler()
 * (includes/api-handlers.php), so $pdo, $_SESSION, req_*(), jresp()
 * and log_action() behave exactly as they did inside the switch.
 * Each case-terminating `break;` became `return;`; jresp() exits on
 * its own, so the return only matters where the original break was.
 */

/* ---- geo_regions ---- */
if ($action === 'geo_regions') {
/* Public and cacheable: the list is identical for everyone and
   changes about once a decade, so it is the one thing in this file
   worth a shared cache. app.js draws its province/city picker from
   exactly this payload, which is why the client and the validator
   cannot disagree about which city belongs to which province. */
if (!rate_limit('geo_regions', 120, 60)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
jresp([
    'ok'    => true,
    'map'   => json_decode(velora_geo_json(), true),
    'codes' => [
        'postal' => 'mod11',
        /* Whether the client should offer the lookup at all. This is
           the only place the flag is published, and it is a boolean
           on purpose: the client needs to know whether to show the
           step, and it has no business knowing the URL or whether a
           token exists. */
        'lookup' => POSTAL_ENABLED,
    ],
], 200, true);
    return;
}

/* ---- postal_lookup ---- */
if ($action === 'postal_lookup') {
/* Deliberately not behind is_user(). The address form is reachable
   from the cart sheet before anyone signs in, and a customer
   checking a code is not asking for anything private — a postal code
   is a public fact about a building.
   CSRF is still required, because without it this endpoint is a free
   billed API anyone can drive with a victim's session. */
csrf_check();

/* Two limits, because they guard different things. The minute one
   stops one person hammering it; the hour one bounds what an
   attacker with a rotating address can cost, which is the number
   that actually appears on a bill. */
if (!rate_limit('postal_minute', 20, 60, get_client_ip())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT',
           'message' => 'تعداد استعلام زیاد است، کمی صبر کنید'], 429);
}
if (!rate_limit('postal_hour', 100, 3600, get_client_ip())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT',
           'message' => 'سقف استعلام ساعتی پر شد، بعداً تلاش کنید'], 429);
}

$postal = velora_postal_normalize(req_str('postal_code'));
if ($postal === '') {
    jresp(['ok' => false, 'error' => 'INVALID_FORMAT',
           'message' => 'کد پستی باید ۱۰ رقم باشد'], 400);
}

$result = velora_postal_lookup($postal);
if (!($result['ok'] ?? false)) {
    /* A Persian sentence for every code, and no stack trace, no
       upstream body and no URL on the way out. The mapping is
       exhaustive on purpose: an unlisted code falls through to a
       generic message rather than leaking the enum. */
    [$msg, $http] = match ((string) $result['error']) {
        'NOT_FOUND'             => ['این کد پستی در سامانهٔ پست یافت نشد', 404],
        'INVALID_CHECKSUM'      => ['کد پستی معتبر نیست', 400],
        'POSTAL_NOT_CONFIGURED' => ['سرویس استعلام کد پستی فعال نیست', 503],
        'POSTAL_AUTH_FAILED'    => ['سرویس استعلام موقتاً در دسترس نیست', 502],
        'RATE_LIMIT_UPSTREAM'   => ['سرویس پست موقتاً پاسخ نمی‌دهد', 502],
        'TIMEOUT'               => ['زمان استعلام به پایان رسید، دوباره تلاش کنید', 504],
        'NO_CURL'               => ['سرویس استعلام روی این سرور فعال نیست', 503],
        /* The upstream's account has run out of credit. This is an
           operator problem and it is reported as one — 502, so the
           front end raises it as a deployment fault — and the
           wording says "temporarily", because a recharge makes it
           true. It is deliberately NOT folded into NOT_FOUND: a
           valid code would be reported to the customer as not
           existing, and a customer told their address does not exist
           stops trying. */
        'POSTAL_NO_CREDIT'      => ['سرویس استعلام موقتاً در دسترس نیست', 502],
        'UPSTREAM_REFUSED'      => ['سرویس پست این کد را بررسی نکرد، کمی بعد دوباره تلاش کنید', 502],
        default                 => ['استعلام کد پستی ناموفق بود', 502],
    };
    jresp(['ok' => false, 'error' => (string) $result['error'], 'message' => $msg], $http);
}

/* Exactly the fields the form can use. The upstream returns a
   dozen; the rest is its bookkeeping. This array is the whole
   contract — a token, a raw body or an upstream URL appearing here
   would be a leak, and postal_lookup.php asserts they cannot.

   plaque and unit are the two numbers the upstream gives separately
   from the street, and the form has separate fields for them, so
   they travel separately too. Splitting them server-side rather
   than parsing the composed line in the browser means the client
   cannot get the boundaries wrong. */
jresp([
    'ok'             => true,
    'postal_code'    => $postal,
    'postal_display' => velora_postal_format($postal),
    'province'       => (string) ($result['province'] ?? ''),
    'city'           => (string) ($result['city'] ?? ''),
    'district'       => (string) ($result['district'] ?? ''),
    'line'           => (string) ($result['line'] ?? ''),
    'plaque'         => (string) ($result['number'] ?? ''),
    'unit'           => (string) ($result['unit'] ?? ''),
    'address'        => (string) ($result['address'] ?? ''),
]);
    return;
}

