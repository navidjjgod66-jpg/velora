<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
/* ZarinPal is the only gateway. payment.php (Zibal) has been deleted along with
   its branch through checkout and through payment_callback, so there is one
   code path to keep correct rather than two kept correct in parallel. */
require __DIR__ . '/zarinpal.php';
require __DIR__ . '/includes/geo.php';
/* The postal wrapper needs velora_postal_valid() from geo.php, so it is loaded
   after it rather than beside it. Loading it before would fatal on the
   checksum not existing yet. */
require __DIR__ . '/includes/postal.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '' && $o !== APP_ORIGIN) { http_response_code(403); exit; }
    header('Access-Control-Allow-Origin: ' . APP_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
    header('Vary: Origin');
    http_response_code(204);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   ORIGIN GATE — same-site only. CSRF token remains the primary guarantee.
   ═══════════════════════════════════════════════════════════════════════════ */
$__req_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$__req_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($__req_origin !== '' && $__req_method === 'POST') {
    $__proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $__proto = 'https';
    $__host_origin = $__proto . '://' . ($_SERVER['HTTP_HOST'] ?? '');

    if ($__req_origin !== APP_ORIGIN && $__req_origin !== $__host_origin) {
        log_action('ORIGIN_DENIED', [
            'sent'         => $__req_origin,
            'expected'     => APP_ORIGIN,
            'host_derived' => $__host_origin,
        ]);
        jresp(['ok' => false, 'error' => 'ORIGIN_DENIED'], 403);
    }
}
unset($__req_origin, $__req_method, $__proto, $__host_origin);

/* ═══════════════════════════════════════════════════════════════════════════
   IMAGE KEY SANITIZING
   ═══════════════════════════════════════════════════════════════════════════ */
function sanitize_image_key(string $input): string {
    $input = trim($input);
    if ($input === '' || strlen($input) > 512) return '';
    if (preg_match('#^https?://#i', $input)) {
        if (preg_match('/["\'<>\s|]/', $input)) return '';
        if (preg_match('#^http://#i', $input) && is_https()) return '';
        return filter_var($input, FILTER_VALIDATE_URL) ? $input : '';
    }
    if (preg_match('#^[a-zA-Z0-9_-]{1,80}\.webp$#', $input)) return $input;
    return '';
}

/* ═══════════════════════════════════════════════════════════════════════════
   CHECKOUT IDEMPOTENCY
   ═══════════════════════════════════════════════════════════════════════════ */
const CKO_REPLAY_TTL = 90;
const CKO_CLAIM_TTL  = 300;
const CKO_RETENTION_HOURS = 24;

function &cko_claim_slot(): array {
    static $slot = ['id' => 0, 'token' => '', 'settled' => false];
    return $slot;
}

function cko_claim(PDO $pdo, int $userId, string $fingerprint): array {
    $slot = &cko_claim_slot();
    $slot = ['id' => 0, 'token' => '', 'settled' => false];

    $token = bin2hex(random_bytes(16));

    try {
        $steal = "((state = 'pending' AND created_at < NOW() - INTERVAL " . CKO_CLAIM_TTL . " SECOND)"
               . " OR (state = 'settled' AND created_at < NOW() - INTERVAL " . CKO_REPLAY_TTL . " SECOND))";

        $stmt = $pdo->prepare(
            "INSERT INTO velora_checkout_attempts
                (user_id, fingerprint, minute_bucket, state, claim_token, order_id, pay_url, total, gateway)
             VALUES (?, ?, FLOOR(UNIX_TIMESTAMP(NOW()) / 60), 'pending', ?, '', '', 0, '')
             ON DUPLICATE KEY UPDATE
                minute_bucket = IF($steal, VALUES(minute_bucket), minute_bucket),
                state         = IF($steal, 'pending', state),
                claim_token   = IF($steal, VALUES(claim_token), claim_token),
                order_id      = IF($steal, '', order_id),
                pay_url       = IF($steal, '', pay_url),
                total         = IF($steal, 0, total),
                gateway       = IF($steal, '', gateway),
                created_at    = IF($steal, NOW(), created_at),
                id            = LAST_INSERT_ID(id)"
        );
        $stmt->execute([$userId, $fingerprint, $token]);
        $claimId = (int) $pdo->lastInsertId();
        if ($claimId <= 0) return ['ok' => false, 'reason' => 'unavailable'];

        $row = $pdo->prepare(
            "SELECT state, claim_token, pay_url, order_id, total, gateway,
                    UNIX_TIMESTAMP(NOW() - created_at) AS age_s
               FROM velora_checkout_attempts WHERE id = ? LIMIT 1"
        );
        $row->execute([$claimId]);
        $r = $row->fetch();
        if (!is_array($r)) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        $slot['id'] = $claimId;

        $owned = (string) $r['state'] === 'pending' && (string) $r['claim_token'] === $token;
        if ($owned) {
            $slot['token'] = $token;
            return ['ok' => true];
        }

        $age = (int) ($r['age_s'] ?? 0);
        if ((string) $r['state'] === 'settled' && (string) $r['pay_url'] !== '' && $age <= CKO_REPLAY_TTL) {
            return [
                'ok'       => false,
                'reason'   => 'settled',
                'order_id' => (string) $r['order_id'],
                'pay_url'  => (string) $r['pay_url'],
                'total'    => (int) $r['total'],
                'gateway'  => (string) $r['gateway'],
            ];
        }

        return ['ok' => false, 'reason' => 'in_flight'];
    } catch (Throwable $e) {
        log_action('CHECKOUT_IDEMPOTENCY_UNAVAILABLE', ['error' => $e->getMessage()]);
        $slot = ['id' => 0, 'token' => '', 'settled' => false];
        return ['ok' => false, 'reason' => 'unavailable'];
    }
}

function cko_settle(PDO $pdo, string $orderId, string $payUrl, int $total, string $gateway): void {
    $slot = &cko_claim_slot();
    if ($slot['id'] <= 0 || $slot['settled']) return;
    try {
        $pdo->prepare(
            "UPDATE velora_checkout_attempts
                SET state = 'settled', order_id = ?, pay_url = ?, total = ?, gateway = ?, settled_at = NOW()
              WHERE id = ? AND claim_token = ? AND state = 'pending'"
        )->execute([
            $orderId,
            mb_substr($payUrl, 0, 1024),
            $total,
            $gateway,
            $slot['id'],
            $slot['token'],
        ]);
        $slot['settled'] = true;
    } catch (Throwable $e) {
        log_action('CHECKOUT_IDEMPOTENCY_SETTLE_FAIL', [
            'order_id' => $orderId,
            'error'    => $e->getMessage(),
        ]);
    }
}

function cko_register_release_guard(PDO $pdo): void {
    $slot = &cko_claim_slot();
    register_shutdown_function(static function () use ($pdo, &$slot): void {
        if ($slot['id'] <= 0 || $slot['settled'] || $slot['token'] === '') return;
        try {
            $pdo->prepare(
                "DELETE FROM velora_checkout_attempts
                  WHERE id = ? AND claim_token = ? AND state = 'pending'"
            )->execute([$slot['id'], $slot['token']]);
        } catch (Throwable $e) {
        }
    });
}

function cko_drop_claim(PDO $pdo): void {
    $slot = &cko_claim_slot();
    if ($slot['id'] <= 0) return;
    try {
        $pdo->prepare("DELETE FROM velora_checkout_attempts WHERE id = ? AND state = 'settled'")
            ->execute([$slot['id']]);
    } catch (Throwable $e) {
        log_action('CHECKOUT_IDEMPOTENCY_DROP_FAIL', [
            'id'    => $slot['id'],
            'error' => $e->getMessage(),
        ]);
    }
    $slot = ['id' => 0, 'token' => '', 'settled' => false];
}

/* ═══════════════════════════════════════════════════════════════════════════
   ABANDONED-ORDER SWEEP
   ═══════════════════════════════════════════════════════════════════════════ */
function sweep_abandoned_orders(PDO $pdo): void {
    if (mt_rand(1, 20) !== 1) return;

    try {
        $pdo->prepare("DELETE FROM velora_checkout_attempts
                        WHERE created_at < NOW() - INTERVAL " . CKO_RETENTION_HOURS . " HOUR")
            ->execute();
    } catch (Throwable $e) {
    }

    try {
        $removed = velora_catalog_sweep_tmp();
        if ($removed > 0) log_action('CATALOG_TMP_SWEEP', ['removed' => $removed]);
    } catch (Throwable $e) {
    }

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare(
            "SELECT id FROM velora_orders
              WHERE payment_status IN ('unpaid','pending')
                AND status = 'pending'
                AND created_at < NOW() - INTERVAL 30 MINUTE
              ORDER BY id ASC
              LIMIT 25
              FOR UPDATE"
        );
        $st->execute();
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) { $pdo->commit(); return; }

        $markSt = $pdo->prepare("UPDATE velora_orders SET status='cancelled', payment_status='failed', updated_at=NOW()
                                     WHERE id=? AND payment_status IN ('unpaid','pending')");
        foreach ($ids as $oid) {
            $itemsSt = $pdo->prepare("SELECT product_id, eu_size, qty FROM velora_order_items WHERE order_id=?");
            $itemsSt->execute([$oid]);
            $restore = [];
            foreach ($itemsSt->fetchAll() as $row) {
                $eu = (int) $row['eu_size'];
                if ($eu <= 0) continue;
                $k = $row['product_id'] . '|' . $eu;
                $restore[$k] = ($restore[$k] ?? 0) + (int) $row['qty'];
            }
            if ($restore) {
                velora_catalog_transaction(function(array $data, array &$out) use ($restore): bool {
                    foreach ($out['products'] as &$prod) {
                        $pid = (string) ($prod['id'] ?? '');
                        foreach ($prod['sizes'] as &$sz) {
                            $k = $pid . '|' . (int) ($sz['eu'] ?? 0);
                            if (isset($restore[$k])) {
                                $sz['stock'] = max(0, (int) ($sz['stock'] ?? 0) + (int) $restore[$k]);
                            }
                        }
                        unset($sz);
                    }
                    unset($prod);
                    return true;
                });
            }
            $markSt->execute([$oid]);
        }
        $pdo->commit();
        log_action('SWEEP_ABANDONED', ['count' => count($ids)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_action('SWEEP_FAIL', ['error' => $e->getMessage()]);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   STOCK RESTORATION
   ═══════════════════════════════════════════════════════════════════════════ */
function restore_stock_for_order(PDO $pdo, string $orderId, string $gateway): bool {
    try {
        $pdo->beginTransaction();

        $mark = $pdo->prepare(
            "UPDATE velora_orders
                SET payment_status='failed', updated_at=NOW()
              WHERE id=? AND payment_status IN ('unpaid','pending')"
        );
        $mark->execute([$orderId]);
        if ($mark->rowCount() !== 1) {
            $pdo->commit();
            return false;
        }

        $itemsSt = $pdo->prepare("SELECT product_id, eu_size, qty FROM velora_order_items WHERE order_id=?");
        $itemsSt->execute([$orderId]);
        $rows = $itemsSt->fetchAll();

        $restore = [];
        foreach ($rows as $r) {
            $eu = (int) $r['eu_size'];
            if ($eu <= 0) continue;
            $key = $r['product_id'] . '|' . $eu;
            $restore[$key] = ($restore[$key] ?? 0) + (int) $r['qty'];
        }

        if ($restore) {
            velora_catalog_transaction(function(array $data, array &$out) use ($restore): bool {
                foreach ($out['products'] as &$prod) {
                    $pid = (string) ($prod['id'] ?? '');
                    if (!is_array($prod['sizes'] ?? null)) continue;
                    foreach ($prod['sizes'] as &$sz) {
                        $k = $pid . '|' . (int) ($sz['eu'] ?? 0);
                        if (isset($restore[$k])) {
                            $sz['stock'] = max(0, (int) ($sz['stock'] ?? 0) + (int) $restore[$k]);
                        }
                    }
                    unset($sz);
                }
                unset($prod);
                return true;
            });
        }

        $pdo->commit();
        log_action('STOCK_RESTORED', [
            'order_id' => $orderId,
            'gateway'  => $gateway,
            'reason'   => 'payment_verify_failed',
        ]);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_action('STOCK_RESTORE_FAIL', [
            'order_id' => $orderId,
            'gateway'  => $gateway,
            'error'    => $e->getMessage(),
        ]);
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   PAYMENT AUDIT LOG
   ═══════════════════════════════════════════════════════════════════════════ */
function log_payment(PDO $pdo, array $row): void {
    $orderId = (string) ($row['order_id'] ?? '');
    $gateway = (string) ($row['gateway'] ?? '');
    if ($orderId === '' || $gateway === '') return;

    /* Only log against an order that exists.

       payment_callback is reachable by anyone who can guess a well-formed
       order id, and it wrote a velora_payment_logs row on every path —
       including the rejection paths, which are the cheap ones to trigger. With
       a valid id and a wrong trackId, one GET produced a row; the rate limit
       bounds requests per minute but not rows per month, so the table grows
       from unauthenticated traffic alone.

       The trade: a genuine rejection of a real order is still recorded in full
       (this function returns early only when the order is absent), and the
       absence itself is written to the file log, which is append-only, is
       rotated at 8 MB, and is not something a visitor can grow by guessing. */
    static $known = [];
    if (!isset($known[$orderId])) {
        try {
            $probe = $pdo->prepare("SELECT 1 FROM velora_orders WHERE id = ? LIMIT 1");
            $probe->execute([$orderId]);
            $known[$orderId] = $probe->fetchColumn() !== false;
        } catch (Throwable $e) {
            /* If the probe itself fails, allow the write: a missing payment log
               on a real order is worse than an extra row on a bad one. */
            $known[$orderId] = true;
        }
    }
    if (!$known[$orderId]) {
        log_action('PAYMENT_LOG_UNKNOWN_ORDER', ['order_id' => $orderId, 'gateway' => $gateway]);
        return;
    }

    $amount = $row['amount'] ?? null;
    if ($amount === null) {
        try {
            $q = $pdo->prepare("SELECT total FROM velora_orders WHERE id=? LIMIT 1");
            $q->execute([$orderId]);
            $total = $q->fetchColumn();
            $amount = $total === false ? 0 : ((int) $total) * 10;
        } catch (Throwable $e) {
            $amount = 0;
        }
    }

    $response = $row['response'] ?? null;
    try {
        $pdo->prepare("INSERT INTO velora_payment_logs
            (order_id, gateway, authority, ref_id, amount, status, response, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $orderId,
                $gateway,
                $row['authority'] ?? null,
                $row['ref_id']     ?? null,
                (int) $amount,
                (string) ($row['status'] ?? 'unknown'),
                $response === null ? null : (is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE)),
                $row['ip'] ?? get_client_ip(),
            ]);
    } catch (Throwable $e) {
        log_action('PAYMENT_LOG_FAIL', [
            'order_id' => $orderId,
            'gateway'  => $gateway,
            'error'    => $e->getMessage(),
        ]);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   GATEWAY CALLBACK FIELD EXTRACTOR
   ═══════════════════════════════════════════════════════════════════════════ */
const CKO_CB_FIELDS_ZARINPAL = ['Status' => 128, 'Authority' => 128];
const CKO_CB_SEEN_KEYS_MAX   = 12;

function callback_response_fields(array $source, array $allow): array {
    $out  = [];
    $seen = [];

    foreach ($source as $k => $v) {
        $key = (string) $k;

        if (!isset($allow[$key])) {
            if (count($seen) < CKO_CB_SEEN_KEYS_MAX) {
                $trimmed = preg_replace('/[^\x20-\x7E]/', '', $key);
                if (is_string($trimmed) && $trimmed !== '') $seen[] = mb_substr($trimmed, 0, 64);
            }
            continue;
        }

        if (!is_scalar($v)) {
            $out[$key] = '[non-scalar]';
            continue;
        }
        $s = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        $out[$key] = mb_substr($s, 0, $allow[$key]);
    }

    if ($seen) $out['seen_keys'] = $seen;
    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════════
   ORDER CANCELLATION — stock + sold counter
   ═══════════════════════════════════════════════════════════════════════════ */
function adjust_order_stock(PDO $pdo, string $orderId, int $sign, bool $wasPaid): array {
    $itemsSt = $pdo->prepare("SELECT product_id, eu_size, qty FROM velora_order_items WHERE order_id=?");
    $itemsSt->execute([$orderId]);
    $rows = $itemsSt->fetchAll();
    if (!$rows) return ['items' => 0, 'restored' => 0, 'short' => 0];

    $demand = [];
    foreach ($rows as $r) {
        $eu = (int) $r['eu_size'];
        if ($eu <= 0) continue;
        $k = $r['product_id'] . '|' . $eu;
        $demand[$k] = ($demand[$k] ?? 0) + (int) $r['qty'];
    }

    $restored = 0;
    $short    = 0;
    $applied  = velora_catalog_transaction(function(array $data, array &$out) use ($sign, $demand, &$restored, &$short): bool {
        if ($sign < 0) {
            foreach ($out['products'] as $prod) {
                $pid = (string) ($prod['id'] ?? '');
                if (!is_array($prod['sizes'] ?? null)) continue;
                foreach ($prod['sizes'] as $sz) {
                    $k = $pid . '|' . (int) ($sz['eu'] ?? 0);
                    if (!isset($demand[$k])) continue;
                    if ((int) ($sz['stock'] ?? 0) < (int) $demand[$k]) $short += (int) $demand[$k];
                }
            }
            if ($short > 0) return true;
        }
        foreach ($out['products'] as &$prod) {
            $pid = (string) ($prod['id'] ?? '');
            if (!is_array($prod['sizes'] ?? null)) continue;
            foreach ($prod['sizes'] as &$sz) {
                $k = $pid . '|' . (int) ($sz['eu'] ?? 0);
                if (!isset($demand[$k])) continue;
                $want = (int) $demand[$k];
                $have = (int) ($sz['stock'] ?? 0);
                if ($sign > 0) {
                    $sz['stock'] = $have + $want;
                } else {
                    $sz['stock'] = $have - $want;
                }
                $restored += $want;
            }
            unset($sz);
        }
        unset($prod);
        return true;
    });

    if ($wasPaid && $short === 0) {
        velora_catalog_transaction(function(array $data, array &$out) use ($orderId, $sign, $pdo): bool {
            $itemsSt = $pdo->prepare("SELECT product_id, qty FROM velora_order_items WHERE order_id=?");
            $itemsSt->execute([$orderId]);
            $deltas = [];
            foreach ($itemsSt->fetchAll() as $r) {
                $deltas[$r['product_id']] = ($deltas[$r['product_id']] ?? 0) + (int) $r['qty'];
            }
            foreach ($out['products'] as &$prod) {
                $pid = (string) ($prod['id'] ?? '');
                if (!isset($deltas[$pid])) continue;
                if ($sign > 0) {
                    $prod['sold'] = max(0, (int) ($prod['sold'] ?? 0) - (int) $deltas[$pid]);
                } else {
                    $prod['sold'] = (int) ($prod['sold'] ?? 0) + (int) $deltas[$pid];
                }
            }
            unset($prod);
            return true;
        });
    }

    return ['items' => count($rows), 'restored' => $restored, 'short' => $short];
}

function bump_sold_for_order(PDO $pdo, string $orderId, int $sign): void {
    try {
        $st = $pdo->prepare("SELECT product_id, qty FROM velora_order_items WHERE order_id=?");
        $st->execute([$orderId]);
        $deltas = [];
        foreach ($st->fetchAll() as $r) {
            $deltas[(string) $r['product_id']] = ($deltas[(string) $r['product_id']] ?? 0) + (int) $r['qty'];
        }
        if (!$deltas) return;
        velora_catalog_transaction(function(array $data, array &$out) use ($deltas, $sign): bool {
            foreach ($out['products'] as &$prod) {
                $pid = (string) ($prod['id'] ?? '');
                if (!isset($deltas[$pid])) continue;
                $have = (int) ($prod['sold'] ?? 0);
                $prod['sold'] = $sign > 0
                    ? $have + $deltas[$pid]
                    : max(0, $have - $deltas[$pid]);
            }
            unset($prod);
            return true;
        });
    } catch (Throwable $e) {
        log_action('SOLD_COUNTER_FAIL', [
            'order_id' => $orderId,
            'sign'     => $sign,
            'error'    => $e->getMessage(),
        ]);
    }
}

/* ── Address book helpers ──────────────────────────────────────────────────── */

/**
* Validate and bound an address coming off the wire.
*
* Every field is truncated to its column width *before* it is compared, so a
* 4 KB "label" is shortened to 40 bytes and then rejected for length rather
* than being silently stored as a 4 KB label in a VARCHAR(40) — which MySQL
* would either truncate without a warning or reject with a rollback, depending
* on sql_mode. Neither is a behaviour worth depending on.
*
* Province and city are checked as a pair against includes/geo.php. Validating
* each independently is how you get "استان تهران، شهر اصفهان" past a form that
* believes it is validating both fields.
*/
function velora_address_in(): array {
    $receiver  = mb_substr(req_str('receiver'), 0, 120);
    $province  = mb_substr(req_str('province'), 0, 64);
    $city      = mb_substr(req_str('city'), 0, 64);
    $district  = mb_substr(req_str('district'), 0, 120);
    $line      = mb_substr(req_str('line'), 0, 400);
    /* normalize_digits first, then strip. The other way round is the bug this
       comment exists for: /[^\d]/ on Persian digits removes every byte of
       every digit, so the postal code arrives as '' and is stored as absent. */
    $plaque    = mb_substr(normalize_digits(req_str('plaque')), 0, 40);
    $unit      = mb_substr(normalize_digits(req_str('unit')), 0, 80);
    $note      = mb_substr(req_str('note'), 0, 400);
    $label     = mb_substr(normalize_digits(req_str('label')), 0, 40);
    $postal    = preg_replace('/\D/', '', normalize_digits(req_str('postal_code'))) ?? '';

    if ($label === '') $label = 'خانه';
    /* Every rejection names the field it is about.
       A validation error that says only "نشانی را کامل‌تر بنویسید" leaves the
       customer hunting through nine inputs for the one that is wrong, and the
       temptation is to retype the whole form — which is how a typo becomes a
       lost address. The client uses `field` to put the error on the input
       itself; it is a hint, not a contract, so a client that ignores it still
       gets a correct save. */
    $bad = static function (string $code, string $field, string $message, int $status = 400): void {
        jresp(['ok' => false, 'error' => $code, 'field' => $field, 'message' => $message], $status);
    };

    if (mb_strlen($label) > 40)     $bad('LABEL_INVALID',   'adLabel',    'عنوان نشانی بیش از حد بلند است');
    if (mb_strlen($receiver) < 2)   $bad('RECEIVER_INVALID','adReceiver', 'نام گیرنده را وارد کنید');
    if (mb_strlen($receiver) > 120) $bad('RECEIVER_INVALID','adReceiver', 'نام گیرنده بیش از حد بلند است');

    if (!velora_geo_valid($province, $city)) {
        $bad('GEO_INVALID', 'adGeo', 'استان و شهر باید از فهرست انتخاب شوند');
    }
    /* The street is the part a human actually reads, and the part that a
       courier needs to find the door. 400 characters is roughly three lines of
       a Persian address; anything longer is a paste of something else. */
    if (mb_strlen($line) < 5) {
        $bad('LINE_INVALID', 'adLine', 'نشانی را کامل‌تر بنویسید (خیابان، کوچه، پلاک)');
    }
    /* The district and the plaque are what turn a street into an address a
       courier can act on, so they are required here as well as in the form.
       Requiring them only in the client meant the server accepted addresses
       the form would not let anyone write. */
    if (mb_strlen($district) < 2) {
        $bad('DISTRICT_INVALID', 'adDistrict', 'محله یا منطقه را وارد کنید');
    }
    if (mb_strlen($plaque) < 1) {
        $bad('PLAQUE_INVALID', 'adPlaque', 'شمارهٔ پلاک را وارد کنید');
    }
    /* The postal code is optional, and when given it must be ten digits. The
       checksum is NOT enforced here.

       That is a change, and it is deliberate. The checksum catches a mistyped
       digit, which is useful — but it also rejects real codes, and a customer
       whose own code fails our arithmetic cannot save their address at all.
       The form already asks the post office, and the post office's answer is
       better evidence than ours: when a lookup for this exact code has come
       back with a province and a city, the code is real whatever our weighted
       sum says. So the hard failure is the shape — ten digits — and everything
       softer is left to the form, which has better evidence to offer.

       What is lost: a code with a single wrong digit is no longer refused on
       save, only flagged in the form. What is gained: a real address is never
       refused because of our arithmetic. A returned parcel is a worse outcome
       than a code that needed confirming. */
    if ($postal !== '' && strlen($postal) !== 10) {
        $bad('POSTAL_INVALID', 'adPostal', 'کدپستی باید ۱۰ رقم باشد');
    }

    /* The delivery number is optional on the address — the courier can call the
       account number — but if one is given it must be a real Iranian mobile
       and it must already be verified for this account, or the whole feature
       would become a way to send a parcel to a number you do not control. */
    $rawPhone = req_str('phone');
$phone    = $rawPhone !== '' ? normalize_phone($rawPhone) : null;

if ($rawPhone !== '' && $phone === null) {
    $bad('PHONE_INVALID', 'adPhone', 'شمارهٔ همراه معتبر نیست');
}
if ($phone !== null && !user_phone_verified($phone)) {
    $bad('PHONE_UNVERIFIED', 'adPhone', 'این شماره هنوز تأیید نشده است');
}

    return [
        'label'       => $label,
        'receiver'    => $receiver,
        'phone'       => $phone ?? '',
        'province'    => $province,
        'city'        => $city,
        'district'    => $district,
        'line'        => $line,
        'plaque'      => $plaque,
        'unit'        => $unit,
        'postal_code' => $postal,
        'note'        => $note,
    ];
}

/** Row → client shape, with the one-line form composed and the postal grouped. */
function velora_address_out(array $row): array {
    if (!$row) return [];
    $a = [
        'id'          => (int) ($row['id'] ?? 0),
        'label'       => (string) ($row['label'] ?? ''),
        'receiver'    => (string) ($row['receiver'] ?? ''),
        'phone'       => (string) ($row['phone'] ?? ''),
        'province'    => (string) ($row['province'] ?? ''),
        'city'        => (string) ($row['city'] ?? ''),
        'district'    => (string) ($row['district'] ?? ''),
        'line'        => (string) ($row['line'] ?? ''),
        'plaque'      => (string) ($row['plaque'] ?? ''),
        'unit'        => (string) ($row['unit'] ?? ''),
        'postal_code' => (string) ($row['postal_code'] ?? ''),
        'note'        => (string) ($row['note'] ?? ''),
        'is_default'  => (int) ($row['is_default'] ?? 0) === 1,
    ];
    $a['postal_display'] = velora_postal_format($a['postal_code']);
    $a['summary'] = velora_address_line($a);
    return $a;
}

/**
* The inbound validator, run against a stored row instead of the request.
*
* When checkout is given an address_id it must apply the *same* rules to what
* is already in the book, not trust the row. Rows predate the current rules
* (the table is new, but a row can be edited by an older build, and a
* hand-edited database is not a fiction), and re-validating costs one indexed
* read. Trusting the book here would mean a bad province/city pair saved once
* ships forever.
*/
function velora_address_in_from(array $row): array {
    $postal = preg_replace('/\D/', '', (string) ($row['postal_code'] ?? '')) ?? '';
    if (!velora_geo_valid((string) $row['province'], (string) $row['city'])) {
        jresp(['ok' => false, 'error' => 'GEO_INVALID',
               'message' => 'نشانی ذخیره‌شده معتبر نیست — لطفاً آن را اصلاح کنید'], 400);
    }
    if (mb_strlen((string) ($row['line'] ?? '')) < 5) {
        jresp(['ok' => false, 'error' => 'LINE_INVALID',
               'message' => 'نشانی ذخیره‌شده ناقص است — لطفاً آن را کامل کنید'], 400);
    }
    return [
        'label'       => (string) ($row['label'] ?? 'خانه'),
        'receiver'    => (string) ($row['receiver'] ?? ''),
        'phone'       => (string) ($row['phone'] ?? ''),
        'province'    => (string) $row['province'],
        'city'        => (string) $row['city'],
        'district'    => (string) ($row['district'] ?? ''),
        'line'        => (string) $row['line'],
        'plaque'      => (string) ($row['plaque'] ?? ''),
        'unit'        => (string) ($row['unit'] ?? ''),
        'postal_code' => $postal,
        'note'        => (string) ($row['note'] ?? ''),
    ];
}

/** The EU sizes the catalogue allows, as a list ready for in_array(). */
/**
 * The EU sizes the catalogue allows, as a list ready for in_array().
 *
 * SIZE_BAND, not a second `range()`. The function predates the constant and
 * had its own copy of the bounds; two copies of a rule is how a client and a
 * server end up disagreeing about which sizes exist.
 */
function velora_size_band(): array {
    return defined('SIZE_BAND') ? SIZE_BAND : range(SIZE_MIN, SIZE_MAX);
}

/**
 * Is the address-book schema present?
 *
 * Two tables and two columns arrived after the store was already running, and
 * the migration is applied by hand. A deployment can therefore be one release
 * behind, and when it is, every query against them throws. That is not a
 * hypothetical: it happened the first time this shipped, and it took sign-in
 * and checkout down with it — 500 on /api.php with no clue in the UI.
 *
 * So the new capability is detected once per request, and every caller that
 * needs it degrades instead of failing. Sign-in and checkout must work on a
 * database that has never heard of an address book; the address book is a
 * feature, not a prerequisite.
 *
 * The probe is a plain SHOW TABLES, once, and the answer is cached in a static
 * for the rest of the request. It costs one round trip on the first call and
 * nothing after.
 */
function velora_addressbook_ready(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    global $pdo;
    try {
        $ready = (bool) $pdo->query("SHOW TABLES LIKE 'velora_user_phones'")->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Has this customer proved they own this number?
 *
 * velora_otp.used says a code was entered for this number at some point; it
 * does not say *this customer* did it. The join through velora_user_phones is
 * what makes the answer "yes, you may have an order delivered here" rather than
 * "someone once typed a code for this number".
 *
 * Without the table there is no record to ask, so the answer falls back to the
 * session's own number — the one the customer proved by signing in with it. The
 * lock is therefore no wider than before this feature existed, which is
 * exactly right: a missing table must loosen nothing and break nothing.
 */
function user_phone_verified(string $phone): bool {
    if (!is_user() || $phone === '') return false;
    if (!velora_addressbook_ready()) {
        return $phone === (current_user_phone() ?? '');
    }
    global $pdo;
    $st = $pdo->prepare("SELECT 1 FROM velora_user_phones WHERE user_id=? AND phone=? LIMIT 1");
    $st->execute([current_user_id(), $phone]);
    return $st->fetchColumn() !== false;
}

/**
 * Record a verified number against the account.
 *
 * Called on every successful OTP. This is what makes the row set grow: the login
 * number is inserted at first sign-in, and every later change phone adds a
 * second row. is_primary follows velora_users.phone, which stays the login
 * identity — so "change my login number" and "add a delivery number" are two
 * different operations and this function is only used for the second.
 *
 * Never throws. It runs on the sign-in path, and a bookkeeping write is not a
 * reason to refuse someone their login; the failure is logged so the gap is
 * visible rather than silent.
 */
function user_phone_remember(int $userId, string $phone): void {
    if ($userId <= 0 || $phone === '') return;
    if (!velora_addressbook_ready()) return;
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO velora_user_phones (user_id, phone, is_primary, verified_at)
                       VALUES (?,?,0,NOW())
                       ON DUPLICATE KEY UPDATE verified_at = NOW()")
            ->execute([$userId, $phone]);
    } catch (Throwable $e) {
        log_action('PHONE_MEMORY_FAIL', ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

/**
 * Refuse an address-book action on a database that has no address book.
 *
 * A 500 tells the customer nothing and the log tells the operator a stack
 * trace about a table they may not know is missing. This says exactly which
 * migration is outstanding.
 */
function require_addressbook(): void {
    if (velora_addressbook_ready()) return;
    log_action('ADDRESSBOOK_MISSING', ['ip' => get_client_ip()]);
    jresp([
        'ok'      => false,
        'error'   => 'ADDRESSBOOK_UNAVAILABLE',
        'message' => 'دفترچهٔ نشانی روی این سرور هنوز راه‌اندازی نشده است',
    ], 503);
}

$action = req_str('action');

/* ── Admin IP allow-list ────────────────────────────────────────────────────── */
if ($action !== '' && str_starts_with($action, 'admin_') && !admin_ip_allowed(get_client_ip())) {
    log_action('ADMIN_IP_DENIED', [
        'action' => $action,
        'ip'     => get_client_ip(),
    ]);
    jresp([
        'ok'      => false,
        'error'   => 'ADMIN_IP_DENIED',
        'message' => 'دسترسی از این آی‌پی مجاز نیست',
    ], 403);
}

/* ═══════════════════════════════════════════════════════════════════════════
   NO DATABASE, NO ACTION
   ═══════════════════════════════════════════════════════════════════════════
   config.php no longer ends the request when the connection fails — the
   storefront has to be able to render without one. Every action in the switch
   below does need one, and reaching it with a null handle would fatal into an
   HTML error page, which the client cannot read and reports as a bodyless
   HTTP_500 with no message.

   So the outage is answered once, here, in the same shape the client already
   understands: DB_FAIL with the classified sentence, which is what puts
   "check VELORA_DB_HOST and VELORA_DB_PORT" in the banner instead of a blank
   page. Nothing retries; a retry cannot succeed until someone fixes the
   server. */
/* Actions that are genuinely independent of the database.

   The gate below refuses *every* action when there is no connection, which is
   right for the twenty-odd actions that read or write rows and wrong for the
   few that answer entirely from this codebase's own constants.

   geo_regions is the one that matters. Its data is a literal in includes/geo.php
   — thirty-one provinces and about three hundred and fifty cities — and it is
   the source velora_geo_valid() validates an address against, so the server can
   and does answer it with the database unplugged. Refusing it meant that a
   database outage emptied the checkout's province picker: the customer reached
   step two of three, chose a province, and found no cities, with an error that
   said "database" rather than anything about addresses.

   The storefront is explicitly designed to survive a database outage —
   velora_catalog_health() exists to explain an empty catalogue, the audit writes
   ten deployment hazards to the log, and index.php renders from products.json —
   so an API endpoint that still hard-fails with no database is contradicting the
   rest of the design.

   membership() is a Set lookup over a literal, so it costs nothing to keep it
   answerable too — but there is no `membership` action in this file, so naming
   it would have been a promise to an endpoint that does not exist.

   Only actions that provably touch no table belong here. Anything added to this
   list is a promise that it will not quietly start depending on a query, and it
   is why the list is short and named rather than a pattern. */
const VELORA_DB_INDEPENDENT_ACTIONS = ['geo_regions'];

if (!$pdo instanceof PDO && !in_array($action, VELORA_DB_INDEPENDENT_ACTIONS, true)) {
    $down = velora_db_down() ?? [
        'error'   => 'DB_FAIL',
        'message' => 'اتصال به پایگاه داده برقرار نشد.',
    ];
    log_action('API_NO_DATABASE', ['action' => $action]);
    jresp(['ok' => false, 'error' => $down['error'], 'message' => $down['message']], 500);
}

try {
    /* The former 2.6k-line switch lives in includes/api/<domain>.php, one
       file per domain, resolved through an allow-list built from the
       directory itself (includes/api-handlers.php). Handlers run in this
       same global scope; they answer via jresp() (which exits) or return. */
    $__handler = velora_api_handler($action);
    if ($__handler !== null) {
        include $__handler;
    } else {
        $diag = [
            'method'         => $_SERVER['REQUEST_METHOD'] ?? '?',
            'content_type'   => $_SERVER['CONTENT_TYPE']   ?? '',
            'content_length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
            'post_keys'      => array_keys($_POST),
            'json_keys'      => array_keys(req_parse_json()),
            'get_keys'       => array_keys($_GET),
        ];
        log_action('UNKNOWN_ACTION', $diag + ['action_raw' => $action]);

        $unknown = ['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action];
        if (APP_DEBUG) $unknown['diag'] = $diag;
        jresp($unknown, 400);
    }
} catch (PDOException $e) {
    /* A query that failed is a different animal from a query that was wrong.
       In production the message below was the only thing the customer saw, and
       "خطای سرور" says nothing about whether they should retry, fix their form,
       or fix the server. velora_db_fail_message() classifies without leaking the
       driver's text (it carries the username), and the classification is what
       the customer acts on — chiefly the missing table, which is what a site
       looks like when db.sql was never imported and is indistinguishable from a
       bug in the save otherwise. */
    log_action('API_DB_ERROR', [
        'action' => $action,
        'sqlstate' => (string) $e->getCode(),
        'driver'   => (int) ($e->errorInfo[1] ?? 0),
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
    ]);
    $missing = $e->errorInfo[1] ?? null;
    $missingTable = ((int) ($missing ?? 0) === 1146)
        || (string) $e->getCode() === '42S02'
        || stripos($e->getMessage(), "doesn't exist") !== false;
    jresp([
        'ok'      => false,
        'error'   => $missingTable ? 'TABLE_MISSING' : 'DB_QUERY_FAIL',
        'message' => velora_db_fail_message($e),
        /* Field the inline form error reads first, so a 500 on the address form
           names the table instead of the generic "نشانی ذخیره نشد". */
        'detail'  => velora_db_fail_message($e),
    ], 500);
} catch (Throwable $e) {
    log_action('API_ERROR', [
        'action' => $action,
        'error'  => $e->getMessage(),
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
    ]);
    jresp([
        'ok'      => false,
        'error'   => 'INTERNAL_ERROR',
        'message' => APP_DEBUG ? $e->getMessage() : 'خطای سرور',
    ], 500);
}