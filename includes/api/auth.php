<?php
declare(strict_types=1);
/** VELORA · API handlers — auth domain. Extracted verbatim from api.php’s
 * action switch; runs in the request’s global scope via includes/api-handlers.php.
 * jresp() ends the request, so a handler that answers simply returns. */

if (!is_user()) {
    jresp(['ok' => false, 'logged' => false, 'error' => 'LOGIN_REQUIRED'], 401);
}
$st = $pdo->prepare("SELECT id, phone, name, email, created_at FROM velora_users WHERE id=?");
$st->execute([current_user_id()]);
$user = $st->fetch();
if (!$user) jresp(['ok' => false, 'error' => 'USER_NOT_FOUND'], 404);
jresp(['ok' => true, 'logged' => true, 'user' => $user]);
break;
