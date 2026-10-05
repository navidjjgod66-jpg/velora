<?php
declare(strict_types=1);
/** VELORA · API handlers — account domain. Extracted verbatim from api.php’s
 * action switch; runs in the request’s global scope via includes/api-handlers.php.
 * jresp() ends the request, so a handler that answers simply returns. */

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
break;
