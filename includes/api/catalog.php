<?php
declare(strict_types=1);
/** VELORA · API handlers — catalog domain. Extracted verbatim from api.php’s
 * action switch; runs in the request’s global scope via includes/api-handlers.php.
 * jresp() ends the request, so a handler that answers simply returns. */

if (!rate_limit('browse_product', 120, 60)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$id = req_str('id');
if ($id === '' || strlen($id) > 64) jresp(['ok' => false, 'error' => 'ID_REQUIRED'], 400);
$row = velora_catalog_product($id);
if ($row === null || empty($row['active'])) jresp(['ok' => false, 'error' => 'NOT_FOUND'], 404);
jresp(['ok' => true, 'product' => velora_catalog_client_shape($row)], 200, true);
break;
