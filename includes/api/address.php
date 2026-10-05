<?php
declare(strict_types=1);
/**
 * VELORA · API handlers — address
 *
 * Actions handled here: address_list, address_save, address_delete, address_set_default
 *
 * Procedural code extracted verbatim from api.php's action switch.
 * It runs in the request's global scope via velora_api_handler()
 * (includes/api-handlers.php), so $pdo, $_SESSION, req_*(), jresp()
 * and log_action() behave exactly as they did inside the switch.
 * Each case-terminating `break;` became `return;`; jresp() exits on
 * its own, so the return only matters where the original break was.
 */

/* ---- address_list ---- */
if ($action === 'address_list') {
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
require_addressbook();
if (!rate_limit('address_list', 60, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$st = $pdo->prepare("SELECT id, label, receiver, phone, province, city, district,
        line, plaque, unit, postal_code, note, is_default, updated_at
    FROM velora_addresses WHERE user_id=? ORDER BY is_default DESC, updated_at DESC");
$st->execute([current_user_id()]);
jresp(['ok' => true, 'addresses' => array_map('velora_address_out', $st->fetchAll())]);
    return;
}

/* ---- address_save ---- */
if ($action === 'address_save') {
csrf_check();
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
require_addressbook();
if (!rate_limit('address_save', 20, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$addr = velora_address_in();
$id   = req_int('id', 0);
$uid  = current_user_id();
$makeDefault = req_bool('is_default', false);

/* The lookup runs server-side too, and its verdict is recorded
   rather than enforced.
   The reason it is not enforced: a customer may legitimately deliver
   somewhere whose postal code belongs to a different building, a
   village with its own code inside a city that does not, or a large
   complex where one code covers the whole site. A disagreement
   between the form's province/city and the code's is a data-quality
   signal, not a validation failure. Blocking the save on it would
   trade a rare typo for a common false negative, and a blocked save
   in the middle of a payment flow is the worst possible moment to
   tell someone their address is wrong.
   What it does buy: a permanent record, so an operator chasing a
   returned parcel has the evidence that the code said one thing and
   the customer said another. The client already warned them; this is
   the audit trail behind the warning. */
if (POSTAL_ENABLED && $addr['postal_code'] !== '') {
    try {
        $lookup = velora_postal_lookup($addr['postal_code']);
        if (($lookup['ok'] ?? false)
            && ((string) ($lookup['province'] ?? '') !== $addr['province']
                || (string) ($lookup['city'] ?? '') !== $addr['city'])) {
            log_action('POSTAL_MISMATCH', [
                'user_id'   => $uid,
                'given'     => $addr['province'] . ' / ' . $addr['city'],
                'postal'    => $addr['postal_code'],
                'from_code' => (string) ($lookup['province'] ?? '') . ' / ' . (string) ($lookup['city'] ?? ''),
            ]);
        }
    } catch (Throwable $e) {
        /* A lookup failure must never fail the save. The address is
           already validated by this point; refusing it because a
           third party was slow is how an upstream outage becomes an
           address-book outage. */
        log_action('POSTAL_LOOKUP_ON_SAVE_FAIL', ['error' => $e->getMessage()]);
    }
}

/* Saving an address that is the only one, or explicitly asking for
   it, makes this the default. Otherwise it is, because a book where
   nothing is default forces the customer to choose at every checkout
   — which is the thing this feature exists to stop. */
$count = (int) $pdo->query("SELECT COUNT(*) FROM velora_addresses WHERE user_id=" . (int) $uid)->fetchColumn();
$isDefault = $makeDefault || $count === 0 || $id === 0;

$pdo->beginTransaction();
try {
    if ($isDefault) {
        $pdo->prepare("UPDATE velora_addresses SET is_default=0 WHERE user_id=?")->execute([$uid]);
    }
    if ($id > 0) {
        $own = $pdo->prepare("SELECT id FROM velora_addresses WHERE id=? AND user_id=?");
        $own->execute([$id, $uid]);
        if ($own->fetchColumn() === false) {
            throw new Exception('ADDRESS_NOT_FOUND');
        }
        $pdo->prepare("UPDATE velora_addresses SET
            label=?, receiver=?, phone=?, province=?, city=?, district=?, line=?,
            plaque=?, unit=?, postal_code=?, note=?, is_default=?
            WHERE id=? AND user_id=?")->execute([
            $addr['label'], $addr['receiver'], $addr['phone'], $addr['province'],
            $addr['city'], $addr['district'], $addr['line'], $addr['plaque'],
            $addr['unit'], $addr['postal_code'], $addr['note'], $isDefault ? 1 : 0,
            $id, $uid,
        ]);
        $savedId = $id;
    } else {
        $pdo->prepare("INSERT INTO velora_addresses
            (user_id, label, receiver, phone, province, city, district, line,
             plaque, unit, postal_code, note, is_default)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $uid, $addr['label'], $addr['receiver'], $addr['phone'],
            $addr['province'], $addr['city'], $addr['district'], $addr['line'],
            $addr['plaque'], $addr['unit'], $addr['postal_code'], $addr['note'],
            $isDefault ? 1 : 0,
        ]);
        $savedId = (int) $pdo->lastInsertId();
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getMessage() === 'ADDRESS_NOT_FOUND') {
        jresp(['ok' => false, 'error' => 'ADDRESS_NOT_FOUND', 'message' => 'نشانی یافت نشد'], 404);
    }
    throw $e;
}
$st = $pdo->prepare("SELECT id, label, receiver, phone, province, city, district,
        line, plaque, unit, postal_code, note, is_default, updated_at
    FROM velora_addresses WHERE id=? AND user_id=?");
$st->execute([$savedId, $uid]);
jresp(['ok' => true, 'address' => velora_address_out($st->fetch() ?: [])]);
    return;
}

/* ---- address_delete ---- */
if ($action === 'address_delete') {
csrf_check();
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
require_addressbook();
if (!rate_limit('address_delete', 30, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$id = req_int('id', 0);
$st = $pdo->prepare("DELETE FROM velora_addresses WHERE id=? AND user_id=?");
$st->execute([$id, current_user_id()]);
$deleted = $st->rowCount() > 0;
/* Deleting the default must promote another one, or the next
   checkout finds a book with no default and silently has to guess. */
if ($deleted) {
    $pdo->prepare("UPDATE velora_addresses SET is_default=0 WHERE user_id=? AND is_default=1")
        ->execute([current_user_id()]);
    $next = $pdo->prepare("SELECT id FROM velora_addresses WHERE user_id=? ORDER BY updated_at DESC LIMIT 1");
    $next->execute([current_user_id()]);
    if ($nextId = $next->fetchColumn()) {
        $pdo->prepare("UPDATE velora_addresses SET is_default=1 WHERE id=? AND user_id=?")
            ->execute([$nextId, current_user_id()]);
    }
}
jresp(['ok' => true, 'deleted' => $deleted]);
    return;
}

/* ---- address_set_default ---- */
if ($action === 'address_set_default') {
csrf_check();
if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
/* Rate-limited like its two siblings. It was the only write in the
   address book without a limit: address_save is 20/60 and
   address_delete is 30/60, and this one opens a transaction and
   issues two UPDATEs on every call while having no ceiling at all.

   The shape of the abuse is narrow — it needs a valid session and a
   valid CSRF token — but the cost is not: a client in a loop turns
   one intention into an unbounded stream of write transactions,
   and every one of them clears is_default across the account before
   setting it back, so it is a table-wide-per-user rewrite repeated
   for as long as the loop runs. */
if (!rate_limit('address_default', 20, 60, 'u' . current_user_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
require_addressbook();
$id = req_int('id', 0);
$uid = current_user_id();
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE velora_addresses SET is_default=0 WHERE user_id=?")->execute([$uid]);
    $pdo->prepare("UPDATE velora_addresses SET is_default=1 WHERE id=? AND user_id=?")
        ->execute([$id, $uid]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
jresp(['ok' => true]);
    return;
}

