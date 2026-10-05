<?php
declare(strict_types=1);
/** VELORA · API handlers — checkout domain. Extracted verbatim from api.php’s
 * action switch; runs in the request’s global scope via includes/api-handlers.php.
 * jresp() ends the request, so a handler that answers simply returns. */

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
break;
