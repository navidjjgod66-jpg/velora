<?php
declare(strict_types=1);
/**
 * VELORA · API handlers — checkout
 *
 * Actions handled here: checkout, payment_callback, payment_status
 *
 * Procedural code extracted verbatim from api.php's action switch.
 * It runs in the request's global scope via velora_api_handler()
 * (includes/api-handlers.php), so $pdo, $_SESSION, req_*(), jresp()
 * and log_action() behave exactly as they did inside the switch.
 * Each case-terminating `break;` became `return;`; jresp() exits on
 * its own, so the return only matters where the original break was.
 */

/* ---- checkout ---- */
if ($action === 'checkout') {
csrf_check();
if (!is_user()) {
    jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED', 'message' => 'لطفاً ابتدا وارد شوید'], 401);
}
if (!rate_limit('checkout', 10, 300)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
sweep_abandoned_orders($pdo);

$name    = req_str('name');
$phone   = normalize_phone(req_str('phone'));
$note    = req_str('note');
$items   = req_json('items');
$vcode   = strtoupper(req_str('voucher'));
if (mb_strlen($name) < 3 || mb_strlen($name) > 120) jresp(['ok' => false, 'error' => 'NAME_INVALID', 'message' => 'نام حداقل ۳ نویسه'], 400);
if (!$phone)                  jresp(['ok' => false, 'error' => 'PHONE_INVALID', 'message' => 'شماره معتبر نیست'], 400);
if (!is_array($items) || count($items) === 0) jresp(['ok' => false, 'error' => 'CART_EMPTY', 'message' => 'سبد خالی است'], 400);
if (count($items) > 20) jresp(['ok' => false, 'error' => 'CART_TOO_LARGE'], 400);

/* ── The delivery number must be one this account has proven ──────
   The form used to accept any well-formed number, which meant a
   signed-in customer could send a parcel to a number nobody had
   verified, and admin reads guest_phone rather than the account, so
   the courier would call it. Locking the field in the UI is a
   courtesy; this is the guarantee. To use a different number the
   customer has to complete an OTP for it first, which adds it to
   velora_user_phones. */
if (!user_phone_verified($phone)) {
    log_action('CHECKOUT_PHONE_UNVERIFIED', [
        'user_id' => current_user_id(),
        'phone'   => $phone,
    ]);
    jresp([
        'ok'      => false,
        'error'   => 'PHONE_UNVERIFIED',
        'message' => 'این شمارهٔ همراه هنوز تأیید نشده است — برای ادامه کد تأیید را وارد کنید',
    ], 403);
}

/* ── The address is now structured, not a blob ───────────────────
   Two paths reach the same place. An address_id names a book entry
   and is loaded by id — never accepted as free text, so a client
   cannot claim book entry 7 while describing somewhere else. With
   no id, the parts are validated and composed here, and the order
   keeps the composed line plus a frozen snapshot of the parts.

   Either way the snapshot is what lands on the order. The book is
   live and editable; an order is a record of where a parcel went
   on a particular day, and those two must not share a row. */
$addressId = req_int('address_id', 0);
$addrRow = null;
if ($addressId > 0) {
    $ast = $pdo->prepare("SELECT * FROM velora_addresses WHERE id=? AND user_id=? LIMIT 1");
    $ast->execute([$addressId, current_user_id()]);
    $addrRow = $ast->fetch();
    if (!$addrRow) {
        jresp(['ok' => false, 'error' => 'ADDRESS_NOT_FOUND',
               'message' => 'نشانی انتخاب‌شده یافت نشد'], 404);
    }
    $addr = velora_address_in_from($addrRow);
} else {
    $addr = velora_address_in();
    $addressId = 0;
}
$address = velora_address_line($addr);
if (mb_strlen($address) < 15 || mb_strlen($address) > 1000) {
    jresp(['ok' => false, 'error' => 'ADDR_INVALID',
           'message' => 'نشانی را کامل‌تر بنویسید'], 400);
}

$canonicalItems = [];
foreach ($items as $it) {
    if (!is_array($it)) throw new Exception('INVALID_ITEM');
    $pid = (string) ($it['pid'] ?? '');
    if ($pid === '' || strlen($pid) > 64) throw new Exception('INVALID_ITEM');
    $size = (int) ($it['size'] ?? 0);
    if ($size < SIZE_MIN || $size > SIZE_MAX) throw new Exception('INVALID_SIZE');
    $canonicalItems[] = [
        'pid'   => $pid,
        'qty'   => max(1, min(MAX_LINE, (int) ($it['qty'] ?? 1))),
        'size'  => $size,
        'color' => substr((string) ($it['color'] ?? ''), 0, 128),
    ];
}
usort($canonicalItems, static fn(array $a, array $b): int =>
    strcmp(
        $a['pid'] . '|' . $a['size'] . '|' . $a['color'],
        $b['pid'] . '|' . $b['size'] . '|' . $b['color']
    )
);
$fingerprint = hash(
    'sha256',
    current_user_id() . '|' . $phone . '|' . $address . '|'
    . json_encode($canonicalItems) . '|' . $vcode
);

$last = $_SESSION['_cko_last'] ?? null;
if (is_array($last)
    && ($last['fingerprint'] ?? '') === $fingerprint
    && (int) ($last['at'] ?? 0) > time() - CKO_REPLAY_TTL
    && !empty($last['pay_url'])
) {
    log_action('CHECKOUT_IDEMPOTENT_HIT', [
        'order_id'    => $last['order_id'] ?? null,
        'fingerprint' => substr($fingerprint, 0, 16),
        'tier'        => 'session',
    ]);
    jresp([
        'ok'        => true,
        'order_id'  => (string) $last['order_id'],
        'total'     => (int) ($last['total'] ?? 0),
        'pay_url'   => (string) $last['pay_url'],
        'gateway'   => (string) ($last['gateway'] ?? ACTIVE_GATEWAY),
        'redirect'  => true,
        'duplicate' => true,
    ]);
}

$claim = cko_claim($pdo, current_user_id(), $fingerprint);

if (!$claim['ok'] && $claim['reason'] === 'settled') {
    $replay = $pdo->prepare(
        "SELECT payment_status, status FROM velora_orders WHERE id = ? LIMIT 1"
    );
    $replay->execute([$claim['order_id']]);
    $ro = $replay->fetch();
    if (is_array($ro) && $ro['payment_status'] !== 'paid' && (string) $ro['status'] !== 'cancelled') {
        log_action('CHECKOUT_IDEMPOTENT_HIT', [
            'order_id'    => $claim['order_id'],
            'fingerprint' => substr($fingerprint, 0, 16),
            'tier'        => 'db',
        ]);
        jresp([
            'ok'        => true,
            'order_id'  => (string) $claim['order_id'],
            'total'     => (int) $claim['total'],
            'pay_url'   => (string) $claim['pay_url'],
            'gateway'   => (string) ($claim['gateway'] ?: ACTIVE_GATEWAY),
            'redirect'  => true,
            'duplicate' => true,
        ]);
    }
    /* The settled claim was for an order that has since been paid or
       cancelled, so it cannot be replayed. Drop the row and fall
       through to build a new order — but a claim has to be taken
       again first.

       cko_drop_claim() and cko_claim() are separate round trips, so
       the window between them is real: a parallel request for the
       same fingerprint can take the claim in that gap, and this
       request then proceeds to insert a second order for a basket
       that is already being ordered. The window is small and needs
       two clicks, but it is exactly the duplicate this whole
       mechanism exists to prevent, and re-claiming closes it for the
       cost of one indexed read.

       The re-claim is allowed to fail loudly: if the claim is
       genuinely in flight at that moment, the honest answer is the
       409 below, not a second order. */
    cko_drop_claim($pdo);
    $claim = cko_claim($pdo, current_user_id(), $fingerprint);
    if (!$claim['ok']) {
        log_action('CHECKOUT_RECLAIM_REFUSED', [
            'fingerprint' => substr($fingerprint, 0, 16),
            'reason'      => (string) ($claim['reason'] ?? 'unknown'),
        ]);
        jresp([
            'ok'      => false,
            'error'   => 'CHECKOUT_IN_PROGRESS',
            'message' => 'همین سفارش در حال ثبت است. چند لحظه بعد دوباره تلاش کنید.',
        ], 409);
    }
} elseif (!$claim['ok'] && $claim['reason'] === 'in_flight') {
    log_action('CHECKOUT_IN_FLIGHT', [
        'fingerprint' => substr($fingerprint, 0, 16),
    ]);
    jresp([
        'ok'      => false,
        'error'   => 'CHECKOUT_IN_PROGRESS',
        'message' => 'همین سفارش در حال ثبت است. چند لحظه بعد دوباره تلاش کنید.',
    ], 409);
}

cko_register_release_guard($pdo);

$pdo->beginTransaction();
try {
    $subtotal = 0;
    $demand   = [];
    $validated = [];

    foreach ($canonicalItems as $it) {
        $prod = velora_catalog_product($it['pid']);
        if ($prod === null || empty($prod['active'])) throw new Exception('PRODUCT_NOT_FOUND:' . $it['pid']);
        $subtotal += (int) $prod['price'] * $it['qty'];
        $validated[] = [
            'pid'   => $prod['id'],
            'name'  => (string) $prod['name'],
            'qty'   => $it['qty'],
            'price' => (int) $prod['price'],
            'color' => $it['color'],
            'size'  => $it['size'],
        ];
        $demand[$it['pid']][$it['size']] = ($demand[$it['pid']][$it['size']] ?? 0) + $it['qty'];
    }

    $discount = 0; $voucher = null;
    if ($vcode !== '') {
        $v = validate_voucher($vcode, $subtotal, current_user_id());
        if ($v['valid']) {
            $discount = $v['discount'] === -1 ? 0 : $v['discount'];
            $voucher  = $v['code'];
        }
    }
    $after   = max(0, $subtotal - $discount);
    $ship    = SHIPPING_FLAT;
    $total   = $after + $ship;
    $oid     = generate_order_id();

    velora_catalog_transaction(function(array $data, array &$out) use (
        $demand, $oid, $phone, $name, $address, $note,
        $subtotal, $discount, $ship, $total, $voucher,
        $addressId, $addr, $validated, $pdo
    ): bool {
        $labels = [];
        foreach ($out['products'] as $prod) {
            $pid = (string) ($prod['id'] ?? '');
            $labels[$pid] = (string) ($prod['name'] ?? $pid);
            if (!isset($demand[$pid])) continue;
            $byEu = [];
            foreach ((array) ($prod['sizes'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $byEu[(int) ($s['eu'] ?? 0)] = (int) ($s['stock'] ?? 0);
            }
            foreach ($demand[$pid] as $eu => $want) {
                if (($byEu[(int) $eu] ?? 0) < $want) {
                    throw new Exception('INSUFFICIENT_STOCK:' . $labels[$pid] . ' EU' . $eu);
                }
            }
        }
        foreach ($out['products'] as &$prod) {
            $pid = (string) ($prod['id'] ?? '');
            if (!isset($demand[$pid])) continue;
            foreach ($prod['sizes'] as &$sz) {
                $eu = (int) ($sz['eu'] ?? 0);
                if (isset($demand[$pid][$eu])) {
                    $sz['stock'] = max(0, (int) ($sz['stock'] ?? 0) - (int) $demand[$pid][$eu]);
                }
            }
            unset($sz);
        }
        unset($prod);

        $pdo->prepare("INSERT INTO velora_orders
            (id, user_id, guest_phone, guest_name, address, note, subtotal, discount, shipping, gift_wrap, total, voucher, status, payment_status, ip_address, user_agent, address_id, address_snapshot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid', ?, ?, ?, ?)")
            ->execute([
                $oid, current_user_id(), $phone, $name, $address, $note,
                $subtotal, $discount, $ship, 0, $total, $voucher,
                get_client_ip(),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                $addressId ?: null,
                json_encode($addr, JSON_UNESCAPED_UNICODE),
            ]);
        $ist = $pdo->prepare("INSERT INTO velora_order_items
            (order_id, product_id, product_name, color, eu_size, qty, unit_price)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($validated as $it) {
            $ist->execute([$oid, $it['pid'], $it['name'], $it['color'], $it['size'], $it['qty'], $it['price']]);
        }
        return true;
    });
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_action('CHECKOUT_FAIL', ['error' => $e->getMessage()]);
    $msg = 'خطای ذخیره‌سازی';
    if (str_starts_with($e->getMessage(), 'INSUFFICIENT_STOCK:')) {
        $msg = 'موجودی کافی نیست · ' . substr($e->getMessage(), 19);
    } elseif (str_starts_with($e->getMessage(), 'PRODUCT_NOT_FOUND:')) {
        $msg = 'یکی از محصولات دیگر موجود نیست';
    } elseif ($e->getMessage() === 'INVALID_ITEM') {
        $msg = 'یکی از اقلام سبد نامعتبر است';
    } elseif ($e->getMessage() === 'INVALID_SIZE') {
        $msg = 'سایز نامعتبر است';
    } elseif (APP_DEBUG) {
        $msg = $e->getMessage();
    }
    jresp(['ok' => false, 'error' => 'CHECKOUT_FAIL', 'message' => $msg], 409);
}

/* One gateway, so no branch. The merchant-id check is not a
   formality: it is the difference between a customer reaching a
   payment page and every checkout on the site failing with
   PAYMENT_INIT_FAILED, and it is answered here rather than by
   letting the gateway return an opaque error to the browser. */
$gatewayName = ACTIVE_GATEWAY;

$pay = null;
$payError = null;

if (is_placeholder_merchant(ZARINPAL_MERCHANT)) {
    log_action('PAYMENT_CONFIG_ERROR', [
        'order_id' => $oid,
        'gateway'  => 'zarinpal',
        'error'    => 'MERCHANT_ID_NOT_CONFIGURED',
    ]);
    $payError = 'PAYMENT_CONFIG_ERROR';
} else {
    $pay = zarinpal_request($total, $oid, 'خرید ولورا — ' . $oid, $phone);
    if (!$pay['ok']) {
        $payError = $pay['error'] ?? 'PAYMENT_INIT_FAILED';
    }
}

if ($payError !== null || $pay === null || ($pay['ok'] ?? false) !== true) {
    log_action('PAYMENT_REQUEST_FAIL', [
        'order_id' => $oid,
        'gateway'  => $gatewayName,
        'error'    => $payError ?? ($pay['error'] ?? 'unknown'),
    ]);
    restore_stock_for_order($pdo, $oid, $gatewayName);
    log_payment($pdo, [
        'order_id' => $oid,
        'gateway'  => $gatewayName,
        'amount'   => $total * 10,
        'status'   => 'request_failed',
        'response' => $pay,
    ]);
    jresp([
        'ok'       => false,
        'error'    => 'PAYMENT_INIT_FAILED',
        'message'  => 'اتصال به درگاه پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.',
        'redirect' => false,
    ], 502);
}

$authority = (string) $pay['authority'];
$pdo->prepare("UPDATE velora_orders SET payment_status='pending', payment_authority=?, payment_gateway='zarinpal', payment_amount=? WHERE id=?")
    ->execute([$authority, $total * 10, $oid]);
$pdo->prepare("INSERT INTO velora_payment_logs (order_id, gateway, authority, amount, status, response, ip_address)
    VALUES (?, 'zarinpal', ?, ?, 'requested', ?, ?)")
    ->execute([
        $oid,
        $authority,
        $total * 10,
        json_encode($pay, JSON_UNESCAPED_UNICODE),
        get_client_ip(),
    ]);

$_SESSION['_cko_last'] = [
    'fingerprint' => $fingerprint,
    'order_id'    => $oid,
    'pay_url'     => (string) $pay['pay_url'],
    'total'       => $total,
    'gateway'     => $gatewayName,
    'at'          => time(),
];

cko_settle($pdo, $oid, (string) $pay['pay_url'], $total, $gatewayName);

jresp([
    'ok'       => true,
    'order_id' => $oid,
    'total'    => $total,
    'pay_url'  => $pay['pay_url'],
    'gateway'  => $gatewayName,
    'redirect' => true,
]);
    return;
}

/* ---- payment_callback ---- */
if ($action === 'payment_callback') {
if (!rate_limit('pay_cb', 120, 60)) {
    header('Location: ' . APP_URL . '/?payment=failed&reason=rate');
    exit;
}

/* ── Gateway allow-list ───────────────────────────────────────
   A callback is a server-to-server request from the acquirer, not
   from the shopper. Nothing about it requires a session, a CSRF
   token, or a browser, which is exactly why it is the one endpoint
   here that anyone on the internet can call freely — and the
   reason the order lookup below is the only thing standing between
   a stranger and a table of made-up payment records.

   VELORA_GATEWAY_CB_IPS is the real control: an empty list (the
   default) keeps the endpoint working, so a deployment that has not
   been configured is not broken by the check being present. Set it
   and the allow-list becomes authoritative.

   Both gateways redirect the *browser* here after the shopper pays,
   so a stricter reading of "who may call this" would break payments
   outright — which is why this is a CIDR list the operator opts
   into, and why the failure mode is a redirect rather than a 403. */
$cbAllow = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) (env('VELORA_GATEWAY_CB_IPS', '') ?? ''))
), static fn(string $s): bool => $s !== ''));
if ($cbAllow) {
    $cbIp = function_exists('get_client_ip') ? get_client_ip() : '0.0.0.0';
    $cbOk = false;
    foreach ($cbAllow as $cidr) {
        if (function_exists('ip_in_cidr') && ip_in_cidr($cbIp, $cidr)) { $cbOk = true; break; }
    }
    if (!$cbOk) {
        log_action('PAYMENT_CALLBACK_IP_DENIED', ['ip' => $cbIp]);
        header('Location: ' . APP_URL . '/?payment=invalid');
        exit;
    }
}

/* The gateway is no longer a parameter, it is a fact. `gateway` is still read
   so that an old ZarinPal callback URL carrying it is accepted (the
   order row already carries payment_gateway, so the value here is
   informational), but it can no longer select a code path — there
   is only one, and a callback that names anything else is refused
   rather than silently handled as if it were ours. */
$gateway = ACTIVE_GATEWAY;
$orderId = req_str('order_id');

if ($orderId === '' || strlen($orderId) > 32 || !preg_match('/^VL-[A-F0-9]{6,16}$/', $orderId)) {
    header('Location: ' . APP_URL . '/?payment=failed');
    exit;
}

$claimed = req_str('gateway');
if ($claimed !== '' && $claimed !== 'zarinpal') {
    log_action('PAYMENT_CALLBACK_UNKNOWN_GATEWAY', [
        'order_id' => $orderId,
        'gateway'  => $claimed,
    ]);
    header('Location: ' . APP_URL . '/?payment=invalid');
    exit;
}

$cbFields = callback_response_fields($_GET, CKO_CB_FIELDS_ZARINPAL);

$authority = req_str('Authority');
$status    = req_str('Status');

if ($authority === '') {
    log_payment($pdo, [
        'order_id' => $orderId,
        'gateway'  => 'zarinpal',
        'status'   => 'callback_rejected_no_authority',
        'response' => $cbFields,
    ]);
    header('Location: ' . APP_URL . '/?payment=invalid');
    exit;
}

$st = $pdo->prepare("SELECT id, total, status, payment_status, payment_authority FROM velora_orders WHERE id=? AND payment_gateway='zarinpal' LIMIT 1");
$st->execute([$orderId]);
$order = $st->fetch();

if (!$order) {
    log_payment($pdo, [
        'order_id' => $orderId,
        'gateway'  => 'zarinpal',
        'status'   => 'callback_rejected_unknown_order',
        'response' => $cbFields,
    ]);
    header('Location: ' . APP_URL . '/?payment=invalid');
    exit;
}

if (!hash_equals((string) $order['payment_authority'], $authority)) {
    log_payment($pdo, [
        'order_id' => $orderId,
        'gateway'  => 'zarinpal',
        'status'   => 'authority_mismatch',
        'response' => $cbFields,
    ]);
    log_action('PAYMENT_AUTHORITY_MISMATCH', [
        'order_id' => $orderId,
        'expected' => zarinpal_redact((string) $order['payment_authority']),
        'got'      => zarinpal_redact($authority),
        'gateway'  => 'zarinpal',
    ]);
    header('Location: ' . APP_URL . '/?payment=invalid');
    exit;
}

log_payment($pdo, [
    'order_id' => $orderId,
    'gateway'  => 'zarinpal',
    'authority' => $authority,
    'amount'   => (int) $order['total'] * 10,
    'status'   => $status === 'OK' ? 'callback_received' : 'callback_failed',
    'response' => $cbFields,
]);

if ($order['payment_status'] === 'paid') {
    header('Location: ' . APP_URL . '/?payment=success&order=' . urlencode($orderId));
    exit;
}

if ((string) $order['status'] === 'cancelled') {
    log_action('PAYMENT_ON_CANCELLED_ORDER', [
        'order_id' => $orderId,
        'gateway'  => 'zarinpal',
        'ref_id'   => $authority,
        'total'    => (int) $order['total'] * 10,
    ]);
    header('Location: ' . APP_URL . '/?payment=cancelled&order=' . urlencode($orderId));
    exit;
}

if ($status !== 'OK') {
    restore_stock_for_order($pdo, $orderId, 'zarinpal');
    header('Location: ' . APP_URL . '/?payment=failed&order=' . urlencode($orderId));
    exit;
}

$v = zarinpal_verify($authority, (int) $order['total']);

if ($v['ok']) {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE velora_orders
            SET payment_status='paid', payment_ref=?, status='processing', updated_at=NOW()
            WHERE id=? AND payment_status <> 'paid'");
        $st->execute([$v['ref_id'], $orderId]);

        /* rowCount() is the concurrency guard, and it was missing.

           Two callbacks for one order are ordinary, not exotic: the
           gateway retries, and a shopper double-clicks "return to
           the maison" while also hitting Back. The session is
           READ COMMITTED (config.php), so both requests read the
           order as `pending`, both verify successfully, and the
           `payment_status <> 'paid'` predicate lets exactly one
           UPDATE through — but bump_sold_for_order() used to run
           unconditionally, so both requests incremented `sold`.
           The counter drifted upward with no error anywhere, and
           `sold` is the number the maison shows as social proof.

           The UPDATE is the serialisation point. Counting rows
           changed is what makes "exactly one caller may proceed"
           enforceable in one statement instead of a lock. */
        $won = $st->rowCount() === 1;
        if ($won) {
            bump_sold_for_order($pdo, $orderId, 1);
        }
        $pdo->prepare("INSERT INTO velora_payment_logs (order_id, gateway, authority, ref_id, amount, status, response, ip_address)
            VALUES (?, 'zarinpal', ?, ?, ?, ?, ?, ?)")
            ->execute([
                $orderId,
                $authority,
                $v['ref_id'],
                $v['amount'],
                $won ? 'verified' : 'duplicate_verified',
                json_encode($v, JSON_UNESCAPED_UNICODE),
                get_client_ip(),
            ]);
        $pdo->commit();
        if (!$won) {
            /* Not an error: the order is already paid and this was
               the second callback for it. Logged so the duplicate is
               visible in the audit trail rather than silent. */
            log_action('PAYMENT_DUPLICATE_CALLBACK', [
                'order_id' => $orderId,
                'gateway'  => 'zarinpal',
                'ref_id'   => $v['ref_id'],
            ]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_action('PAYMENT_COMMIT_FAIL', [
            'order_id' => $orderId,
            'gateway'  => 'zarinpal',
            'error'    => $e->getMessage(),
        ]);
    }
    if (($_SESSION['_cko_last']['order_id'] ?? '') === $orderId) {
        unset($_SESSION['_cko_last']);
    }
    log_action('PAYMENT_SUCCESS', [
        'order_id' => $orderId,
        'gateway'  => 'zarinpal',
        'ref_id'   => $v['ref_id'],
    ]);
    header('Location: ' . APP_URL . '/?payment=success&order=' . urlencode($orderId) . '&ref=' . urlencode($v['ref_id']));
    exit;
}

/* ── Not verified: is that a verdict, or silence? ──────────────
   This branch used to be a single `else` that did the same three
   things for every kind of no: restore the stock, mark the order
   failed, and send the customer to a failure page. That is correct
   for a decline and catastrophic for everything else, because
   "we could not reach the gateway" is not "the customer did not
   pay". Under a 300-second open circuit breaker, or a single
   12-second timeout inside the 3-attempt verify, an order that had
   been paid was destroyed and its customer told it had failed.

   So the failure is now split by whether the gateway actually
   answered. See zarinpal_verdict_is_final(). */
if (!zarinpal_verdict_is_final($v)) {
    /* Indeterminate. The order stays `pending`, the stock stays
       committed, and the customer is told the truth: we have their
       money in flight and we are confirming it.

       Reconciling here rather than in a background job is
       deliberate. The gateway is usually reachable a moment later —
       the outage that caused this is usually a blip — so one
       attempt converts almost every indeterminate into a definite
       answer inside the same request, and the customer waits a few
       hundred milliseconds rather than being told to check back
       later. Whatever is still unknown after that is logged with
       everything needed to settle it by hand. */
    $r = zarinpal_reconcile($authority, (int) $order['total']);

    if ($r['state'] === 'paid') {
        /* The money is there. Promote through exactly the same
           guarded path as a successful verify, so a reconciled order
           and a verified order are indistinguishable downstream —
           including the rowCount() guard, so a reconciliation racing
           a third callback still cannot double-count `sold`. */
        $pdo->beginTransaction();
        try {
            $st2 = $pdo->prepare("UPDATE velora_orders
                SET payment_status='paid', payment_ref=?, status='processing', updated_at=NOW()
                WHERE id=? AND payment_status <> 'paid'");
            $st2->execute([$r['ref_id'] ?? $authority, $orderId]);
            $won2 = $st2->rowCount() === 1;
            if ($won2) {
                bump_sold_for_order($pdo, $orderId, 1);
            }
            $pdo->prepare("INSERT INTO velora_payment_logs (order_id, gateway, authority, ref_id, amount, status, response, ip_address)
                VALUES (?, 'zarinpal', ?, ?, ?, 'reconciled', ?, ?)")
                ->execute([
                    $orderId,
                    $authority,
                    $r['ref_id'] ?? $authority,
                    $r['amount'] ?? ((int) $order['total'] * 10),
                    json_encode(['reconcile' => $r], JSON_UNESCAPED_UNICODE),
                    get_client_ip(),
                ]);
            $pdo->commit();
        } catch (Throwable $e2) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_action('PAYMENT_RECONCILE_COMMIT_FAIL', [
                'order_id' => $orderId, 'error' => $e2->getMessage(),
            ]);
        }
        log_action('PAYMENT_RECONCILED_PAID', [
            'order_id' => $orderId, 'ref_id' => $r['ref_id'] ?? $authority,
        ]);
        header('Location: ' . APP_URL . '/?payment=success&order=' . urlencode($orderId)
            . '&ref=' . urlencode($r['ref_id'] ?? $authority));
        exit;
    }

    if ($r['state'] === 'unpaid') {
        /* Now the gateway has said so, so this is the real thing:
           release the stock and let the customer know. */
        $restored = restore_stock_for_order($pdo, $orderId, 'zarinpal');
        log_action('PAYMENT_RECONCILED_UNPAID', [
            'order_id' => $orderId, 'code' => $r['code'] ?? null,
            'stock_restored' => $restored,
        ]);
        if (($_SESSION['_cko_last']['order_id'] ?? '') === $orderId) {
            unset($_SESSION['_cko_last']);
        }
        header('Location: ' . APP_URL . '/?payment=failed&order=' . urlencode($orderId));
        exit;
    }

    /* Still unknown. The order and its stock are left exactly as
       they are, and the whole picture is written down: the gateway
       error, the authority (redacted — see zarinpal_redact), the
       order, and the amount we are owed. This is the row an operator
       reconciles from. */
    log_payment($pdo, [
        'order_id'  => $orderId,
        'gateway'   => 'zarinpal',
        'authority' => $authority,
        'ref_id'    => $v['ref_id'] ?? null,
        'amount'    => $v['amount'] ?? ((int) $order['total'] * 10),
        'status'    => 'indeterminate',
        'response'  => $v,
    ]);
    log_action('PAYMENT_INDETERMINATE', [
        'order_id' => $orderId,
        'gateway'  => 'zarinpal',
        'error'    => $v['error'] ?? 'unknown',
        'detail'   => $v['detail'] ?? null,
        'authority'=> zarinpal_redact($authority),
        'amount'   => (int) $order['total'] * 10,
        'action'   => 'left pending — needs inquiry/manual review',
    ]);
    /* "confirming", not "failed". The order is still live, the
       customer has not lost anything, and they are told to give us
       a moment rather than to try again — a retry would create a
       second order for a purchase they have already made. */
    header('Location: ' . APP_URL . '/?payment=confirming&order=' . urlencode($orderId));
    exit;
}

/* A real verdict: the gateway looked at this and rejected it. */
$restored = restore_stock_for_order($pdo, $orderId, 'zarinpal');
log_payment($pdo, [
    'order_id' => $orderId,
    'gateway'  => 'zarinpal',
    'authority' => $authority,
    'ref_id'   => $v['ref_id'] ?? null,
    'amount'   => $v['amount'] ?? ((int) $order['total'] * 10),
    'status'   => 'failed',
    'response' => $v,
]);
log_action('PAYMENT_FAIL', [
    'order_id' => $orderId,
    'gateway'  => 'zarinpal',
    'error'    => $v['error'] ?? 'unknown',
    'stock_restored' => $restored,
]);
if (($_SESSION['_cko_last']['order_id'] ?? '') === $orderId) {
    unset($_SESSION['_cko_last']);
}
header('Location: ' . APP_URL . '/?payment=failed&order=' . urlencode($orderId));
exit;

    return;
}

/* ---- payment_status ---- */
if ($action === 'payment_status') {
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
if (!rate_limit('pay_status', 30, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$oid = req_str('order_id');
if ($oid === '' || strlen($oid) > 32) jresp(['ok' => false], 400);
$st = $pdo->prepare("SELECT user_id, payment_status, payment_ref, status FROM velora_orders WHERE id=? LIMIT 1");
$st->execute([$oid]);
$o = $st->fetch();
if (!$o) jresp(['ok' => false, 'error' => 'NOT_FOUND'], 404);
if ((int) $o['user_id'] !== current_user_id()) {
    jresp(['ok' => false, 'error' => 'FORBIDDEN'], 403);
}
jresp([
    'ok'             => true,
    'payment_status' => $o['payment_status'],
    'payment_ref'    => $o['payment_ref'],
    'order_status'   => $o['status'],
    'is_paid'        => $o['payment_status'] === 'paid',
]);
    return;
}

