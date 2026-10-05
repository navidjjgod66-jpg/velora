<?php
declare(strict_types=1);
/** VELORA · API handlers — admin domain. Extracted verbatim from api.php’s
 * action switch; runs in the request’s global scope via includes/api-handlers.php.
 * jresp() ends the request, so a handler that answers simply returns. */

if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$aid    = req_int('id');
$status = req_str('status');
$allowed = ['pending','confirmed','cancelled'];
if (!in_array($status, $allowed, true) || $aid <= 0) {
    jresp(['ok' => false, 'error' => 'INVALID_STATUS'], 400);
}
$pdo->prepare("UPDATE velora_appointments SET status=? WHERE id=?")
    ->execute([$status, $aid]);
log_action('ADMIN_APPOINTMENT_STATUS', ['appointment_id' => $aid, 'status' => $status]);
jresp(['ok' => true, 'message' => 'وضعیت به‌روزرسانی شد']);
break;
