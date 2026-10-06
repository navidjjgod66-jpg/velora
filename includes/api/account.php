<?php
declare(strict_types=1);
/**
 * VELORA · API handlers — account
 *
 * Actions handled here: account_update, account_profile, my_orders, book_appointment, notify_restock, contact
 *
 * Procedural code extracted verbatim from api.php's action switch.
 * It runs in the request's global scope via velora_api_handler()
 * (includes/api-handlers.php), so $pdo, $_SESSION, req_*(), jresp()
 * and log_action() behave exactly as they did inside the switch.
 * Each case-terminating `break;` became `return;`; jresp() exits on
 * its own, so the return only matters where the original break was.
 */

/* ---- account_update ---- */
if ($action === 'account_update') {
/* The name typed during sign-in was only ever written to
   localStorage and re-sent with every order; velora_users.name has
   been NULL for every account created since launch. This is the
   first write to it. The phone is deliberately NOT editable here —
   changing it means proving you own the new one, which is
   `phone_change_otp`, not a text field. */
csrf_check();
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
if (!rate_limit('account_update', 20, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$name = req_str('name');
$fit  = req_json('fit_profile', []);
if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
    jresp(['ok' => false, 'error' => 'NAME_INVALID', 'message' => 'نام بین ۲ تا ۱۲۰ نویسه'], 400);
}
$fitJson = null;
if ($fit) {
    $clean = [
        'L'    => isset($fit['L'])    ? (float) $fit['L']    : null,
        'W'    => isset($fit['W'])    ? (float) $fit['W']    : null,
        'w'    => isset($fit['w']) && in_array($fit['w'], ['n', 'r', 'w'], true) ? $fit['w'] : null,
        'eu'   => isset($fit['eu'])   ? (int) $fit['eu']      : null,
        'conf' => isset($fit['conf']) ? (int) $fit['conf']    : null,
        'at'   => isset($fit['at'])   ? (int) $fit['at']      : null,
    ];
    if (!in_array($clean['eu'], velora_size_band(), true)) $clean['eu'] = null;
    if ($clean['L'] !== null) $clean['L'] = max(20.0, min(32.0, $clean['L']));
    if ($clean['W'] !== null) $clean['W'] = max(6.0, min(13.0, $clean['W']));
    if ($clean['conf'] !== null) $clean['conf'] = max(0, min(100, $clean['conf']));
    $fitJson = json_encode(array_filter($clean, static fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);
}
$pdo->prepare("UPDATE velora_users SET name=?, fit_profile=? WHERE id=?")
    ->execute([$name, $fitJson, current_user_id()]);
jresp(['ok' => true, 'name' => $name, 'fit_profile' => $fit ? json_decode((string) $fitJson, true) : null]);
    return;
}

/* ---- account_profile ---- */
if ($action === 'account_profile') {
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
/* Limited because it is not cheap and it is not a read that can be
   reasoned about from the outside: three to five queries per call,
   including the phone list and the address count, every time. It had
   no ceiling while its neighbours account_update (20/60) and
   my_orders (20/60) both did.

   30/minute is generous for a panel that loads once when it opens
   and once after a change, and it is per-user rather than per-IP so
   a shared address does not spend another customer's allowance. */
if (!rate_limit('account_profile', 30, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$st = $pdo->prepare("SELECT id, phone, name, email, fit_profile, created_at, last_seen
    FROM velora_users WHERE id=?");
$st->execute([current_user_id()]);
$u = $st->fetch();
if (!$u) jresp(['ok' => false, 'error' => 'NOT_FOUND'], 404);
/* The phone list and the address count both come from tables that
   postdate the store. Their absence is not an error: the profile
   still renders, the number still shows, and the customer sees an
   empty list where the second number would be. Failing the whole
   page would take the name field and the order history with it. */
$phones = [];
if (velora_addressbook_ready()) {
    $phones = $pdo->prepare("SELECT phone, is_primary, verified_at FROM velora_user_phones
        WHERE user_id=? ORDER BY is_primary DESC, verified_at DESC");
    $phones->execute([current_user_id()]);
    $phones = $phones->fetchAll();
} else {
    /* Mirror the session's own number so the UI has something to
       label as "the account number" rather than an empty gap. */
    $phones = [[
        'phone'       => $u['phone'],
        'is_primary'  => 1,
        'verified_at' => $u['created_at'],
    ]];
}
$agg = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN payment_status='paid' THEN total ELSE 0 END),0) spent
    FROM velora_orders WHERE user_id=?");
$agg->execute([current_user_id()]);
$sum = $agg->fetch() ?: ['n' => 0, 'spent' => 0];
$addrCount = 0;
if (velora_addressbook_ready()) {
    $addrCount = (int) $pdo->query("SELECT COUNT(*) FROM velora_addresses WHERE user_id=" . (int) current_user_id())->fetchColumn();
}
jresp(['ok' => true, 'user' => [
    'id'          => (int) $u['id'],
    'phone'       => $u['phone'],
    'name'        => $u['name'] ?? '',
    'email'       => $u['email'] ?? '',
    'fit_profile' => $u['fit_profile'] ? json_decode((string) $u['fit_profile'], true) : null,
    'created_at'  => $u['created_at'],
    'last_seen'   => $u['last_seen'],
    'phones'      => $phones,   
    'order_count' => (int) $sum['n'],
    'total_spent' => (int) $sum['spent'],
    'address_count' => $addrCount,
]]);
    return;
}

/* ---- my_orders ---- */
if ($action === 'my_orders') {
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
if (!rate_limit('my_orders', 20, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
/* The order list used to return id, total, status and a timestamp.
   That is enough to draw four rows and not enough for a receipt:
   the customer cannot see what they paid for, where it was going,
   or which code it shipped under. An order page that cannot answer
   "what did I order and where was it going?" is not an order page.
   The frozen snapshot is returned, not a join against the live
   address book — an address corrected today must not rewrite the
   destination of a parcel sent last month. */
$st = $pdo->prepare("SELECT id, total, subtotal, discount, shipping, voucher,
        status, payment_status, payment_gateway, payment_ref, created_at,
        guest_name, guest_phone, address, note, address_snapshot
    FROM velora_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 24");
$st->execute([current_user_id()]);
$orders = $st->fetchAll();

$itemsByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $ist = $pdo->prepare("SELECT order_id, product_id, product_name, qty, color, eu_size, unit_price
        FROM velora_order_items WHERE order_id IN ($in) ORDER BY id ASC");
    $ist->execute($ids);
    foreach ($ist->fetchAll() as $row) {
        $itemsByOrder[$row['order_id']][] = $row;
    }
}
foreach ($orders as &$o) {
    $o['items']   = $itemsByOrder[$o['id']] ?? [];
    $o['item_count'] = array_sum(array_column($o['items'], 'qty'));
    $o['address_snapshot'] = $o['address_snapshot']
        ? json_decode((string) $o['address_snapshot'], true) : null;
    foreach (['total', 'subtotal', 'discount', 'shipping'] as $k) {
        $o[$k] = (int) $o[$k];
    }
}
unset($o);
jresp(['ok' => true, 'orders' => $orders]);
    return;
}

/* ---- book_appointment ---- */
if ($action === 'book_appointment') {
csrf_check();
if (!rate_limit('appt', 5, 300)) jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
$date  = req_str('date');
$time  = req_str('time');
$name  = req_str('name');
$phone = normalize_phone(req_str('phone'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !preg_match('/^\d{2}:\d{2}$/', $time)
    || mb_strlen($name) < 2 || mb_strlen($name) > 80
    || !$phone) {
    jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
}
$now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
$slotDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $now->getTimezone());
if ($slotDate === false || $slotDate->format('Y-m-d') !== $date) {
    jresp(['ok' => false, 'error' => 'INVALID_DATE', 'message' => 'تاریخ معتبر نیست'], 400);
}
$slotTime = DateTimeImmutable::createFromFormat('!H:i', $time, $now->getTimezone());
if ($slotTime === false || $slotTime->format('H:i') !== $time) {
    jresp(['ok' => false, 'error' => 'INVALID_TIME', 'message' => 'ساعت معتبر نیست'], 400);
}
$slot = $slotDate->setTime((int) $slotTime->format('H'), (int) $slotTime->format('i'));
if ($slot < $now->modify('-1 minute')) {
    jresp(['ok' => false, 'error' => 'SLOT_PAST', 'message' => 'این زمان گذشته است'], 400);
}
if ($slot > $now->modify('+' . APPOINTMENT_HORIZON_DAYS . ' days')) {
    jresp(['ok' => false, 'error' => 'SLOT_TOO_FAR', 'message' => 'رزرو بیش از ' . APPOINTMENT_HORIZON_DAYS . ' روز آینده ممکن نیست'], 400);
}
$st = $pdo->prepare("SELECT COUNT(*) FROM velora_appointments WHERE date=? AND time=? AND status!='cancelled'");
$st->execute([$date, $time]);
if ((int) $st->fetchColumn() > 0) {
    jresp(['ok' => false, 'error' => 'TIME_TAKEN', 'message' => 'این زمان قبلاً رزرو شده است'], 409);
}
try {
    $pdo->prepare("INSERT INTO velora_appointments (date, time, name, phone, status)
        VALUES (?, ?, ?, ?, 'pending')")
        ->execute([$date, $time, $name, $phone]);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        jresp(['ok' => false, 'error' => 'TIME_TAKEN', 'message' => 'این زمان قبلاً رزرو شده است'], 409);
    }
    throw $e;
}
log_action('APPOINTMENT_BOOKED', ['date' => $date, 'time' => $time]);
jresp(['ok' => true, 'message' => 'وقت شما رزرو شد']);
    return;
}

/* ---- notify_restock ---- */
if ($action === 'notify_restock') {
csrf_check();
if (!rate_limit('restock', 5, 300)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT', 'message' => 'لطفاً کمی بعد تلاش کنید'], 429);
}
$pid   = req_str('product_id');
$eu    = req_int('size');
$email = req_str('email');
if ($pid === '' || strlen($pid) > 64 || $eu < SIZE_MIN || $eu > SIZE_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
}
try {
    $st = $pdo->prepare(
        "INSERT INTO velora_restock (product_id, eu_size, email, notified)
         VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE id = id"
    );
    $st->execute([$pid, $eu, $email]);
    if ($st->rowCount() === 0) {
        jresp(['ok' => true, 'message' => 'قبلاً ثبت‌نام کرده‌اید', 'already_registered' => true]);
    }
    jresp(['ok' => true, 'message' => 'هنگام موجود شدن اطلاع‌رسانی می‌شود']);
} catch (Throwable $e) {
    log_action('RESTOCK_FAIL', ['pid' => $pid, 'eu' => $eu, 'error' => $e->getMessage()]);
    jresp(['ok' => false, 'error' => 'RESTOCK_FAIL'], 500);
}
    return;
}

/* ---- contact ---- */
if ($action === 'contact') {
csrf_check();
if (!rate_limit('contact', 3, 300)) jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
$name    = req_str('name');
$email   = req_str('email');
$phone   = normalize_phone(req_str('phone'));
$message = req_str('message');
if (mb_strlen($name) < 2 || mb_strlen($name) > 120
    || mb_strlen($message) < 10 || mb_strlen($message) > 4000) {
    jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
}
log_action('CONTACT_FORM', [
    'name'    => $name,
    'email'   => $email,
    'phone'   => $phone,
    'message' => mb_substr($message, 0, 200),
]);
jresp(['ok' => true, 'message' => 'پیام شما دریافت شد']);
    return;
}

