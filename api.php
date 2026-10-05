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
    switch ($action) {
        case 'send_otp':
            csrf_check();
            if (!rate_limit('otp', 3, 60)) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT', 'message' => 'لطفاً ۶۰ ثانیه صبر کنید'], 429);
            }
            $phone = normalize_phone(req_str('phone'));
            if (!$phone) {
                jresp(['ok' => false, 'error' => 'PHONE_INVALID', 'message' => 'شماره معتبر نیست'], 400);
            }

            if (!rate_limit('otp_phone_hour', 5, 3600, $phone)) {
                log_action('OTP_PHONE_HOURLY_CAP', ['phone' => $phone]);
                jresp([
                    'ok'      => false,
                    'error'   => 'RATE_LIMIT',
                    'message' => 'تعداد درخواست‌های این شماره زیاد است — بعداً تلاش کنید',
                ], 429);
            }

            $st = $pdo->prepare("SELECT COUNT(*) FROM velora_otp WHERE phone=? AND created_at > NOW() - INTERVAL 60 SECOND");
            $st->execute([$phone]);
            if ((int) $st->fetchColumn() > 0) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT', 'message' => 'کد قبلاً ارسال شده است'], 429);
            }

            /* MeliPayamak's /api/send/otp/{token} endpoint generates the OTP
               itself and returns it in the response `code` field. There is no
               local code to compute — the customer receives whatever the panel
               decided, and we store exactly that value. */
            $sent = meli_send_otp($phone);
            $code = (string) ($sent['otp'] ?? '');

            if (!$sent['ok']) {
                if (OTP_DEBUG) {
                    log_line('[OTP_DEBUG] send failed for ' . log_safe($phone)
                        . ': ' . log_safe((string) json_encode($sent, JSON_UNESCAPED_UNICODE)));
                }
                log_action('OTP_SEND_FAIL', [
                    'phone'   => $phone,
                    'error'   => $sent['error'] ?? 'unknown',
                    'http'    => $sent['http'] ?? null,
                    'status'  => $sent['status'] ?? null,
                    'gateway' => $sent['gateway'] ?? null,
                ]);
                $why = 'ارسال پیامک ناموفق بود';
                if (!empty($sent['status'])) {
                    $why .= ' · ' . $sent['status'];
                }
                jresp(['ok' => false, 'error' => 'SMS_FAIL', 'message' => $why], 502);
            }

            if ($code === '') {
                log_action('OTP_EMPTY_CODE', ['phone' => $phone, 'gateway' => $sent['gateway'] ?? null]);
                jresp(['ok' => false, 'error' => 'SMS_FAIL', 'message' => 'ارسال پیامک ناموفق بود'], 502);
            }

            log_action('OTP_SENT', [
                'phone'    => $phone,
                'status'   => $sent['status'] ?? null,
                'code_len' => strlen($code),
            ]);

            $pdo->prepare("UPDATE velora_otp SET used=1 WHERE phone=? AND used=0")->execute([$phone]);

            $st = $pdo->prepare("INSERT INTO velora_otp (phone, code, ip, expires_at) VALUES (?, ?, ?, ?)");
            $st->execute([
                $phone,
                otp_digest($phone, $code),
                get_client_ip(),
                time() + OTP_TTL,
            ]);
            $resp = [
                'ok'      => true,
                'message' => 'کد تأیید ارسال شد',
                'ttl'     => OTP_TTL,
            ];
            if (OTP_DEBUG) $resp['debug_code'] = $code;
            jresp($resp);
            break;

        case 'verify_otp':
            csrf_check();
            if (!rate_limit('verify', 8, 60)) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $phone = normalize_phone(req_str('phone'));
            $code  = trim(req_str('code'));
            if (!$phone || !preg_match('/^\d{4,10}$/', $code)) {
                jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
            }

            if (!rate_limit('otp_attempt', 5, 900, $phone)) {
                log_action('OTP_PHONE_LOCKED', ['phone' => $phone]);
                jresp([
                    'ok'      => false,
                    'error'   => 'PHONE_LOCKED',
                    'message' => 'تلاش بیش از حد برای این شماره — ۱۵ دقیقه صبر کنید',
                ], 429);
            }

            $st = $pdo->prepare("SELECT id, code FROM velora_otp WHERE phone=? AND used=0 AND expires_at > ? ORDER BY id DESC LIMIT 1");
            $st->execute([$phone, time()]);
            $matched = $st->fetch();

            if (!$matched || !otp_matches($phone, $code, (string) $matched['code'])) {
                log_action('OTP_INVALID', ['phone' => $phone]);
                jresp(['ok' => false, 'error' => 'OTP_INVALID', 'message' => 'کد نامعتبر یا منقضی شده'], 401);
            }
            rate_limit_reset('otp_attempt', $phone);
            $pdo->prepare("UPDATE velora_otp SET used=1 WHERE id=?")->execute([(int) $matched['id']]);

            /* ── Two different things share this one OTP challenge ────────────
               Signing in, and adding a second number to an account that is
               already signed in. They look identical from here — a phone and a
               code — and they are told apart by `intent`, which is why the
               client must send it. Getting this wrong is the bug the whole
               phone feature exists to prevent: without the branch, verifying a
               code for someone else's number while signed in would silently log
               you in as them. */
            $intent = req_str('intent');

            if ($intent === 'add_phone') {
                if (!is_user()) {
                    jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED',
                           'message' => 'برای افزودن شماره باید وارد حساب خود شوید'], 401);
                }
                require_addressbook();
                if ($phone === (current_user_phone() ?? '')) {
                    jresp(['ok' => false, 'error' => 'PHONE_ALREADY_PRIMARY',
                           'message' => 'این همان شمارهٔ اصلی حساب شماست'], 400);
                }
                $st = $pdo->prepare("SELECT id FROM velora_users WHERE phone=? LIMIT 1");
                $st->execute([$phone]);
                if ($st->fetchColumn() !== false) {
                    /* The number is somebody's *login*. Registering it here
                       would make this account able to deliver to, and be
                       reached as, an identity that already exists elsewhere. */
                    jresp(['ok' => false, 'error' => 'PHONE_TAKEN',
                           'message' => 'این شماره به حساب دیگری تعلق دارد'], 409);
                }
                user_phone_remember(current_user_id(), $phone);
                $pdo->prepare("UPDATE velora_user_phones SET last_used_at=NOW() WHERE user_id=? AND phone=?")
                    ->execute([current_user_id(), $phone]);
                log_action('PHONE_ADDED', ['user_id' => current_user_id(), 'phone' => $phone]);
                jresp([
                    'ok'      => true,
                    'message' => 'شمارهٔ همراه تأیید و ذخیره شد',
                    'phone'   => $phone,
                    'intent'  => 'add_phone',
                ]);
                break;
            }

            $st = $pdo->prepare("SELECT id, name FROM velora_users WHERE phone=? LIMIT 1");
            $st->execute([$phone]);
            $user = $st->fetch();
            if (!$user) {
                $pdo->prepare("INSERT INTO velora_users (phone) VALUES (?)")->execute([$phone]);
                $uid = (int) $pdo->lastInsertId();
                $uname = null;
            } else {
                $uid = (int) $user['id'];
                $uname = $user['name'] ?? null;
                $pdo->prepare("UPDATE velora_users SET last_seen=NOW() WHERE id=?")->execute([$uid]);
            }

            session_regenerate_id(true);
            $_SESSION['csrf']    = bin2hex(random_bytes(32));
            $_SESSION['csrf_at'] = time();

            $_SESSION['user_id']     = $uid;
            $_SESSION['user_phone']  = $phone;
            $_SESSION['user_at']     = time();
            /* The login number is proven-owned by definition — the customer
               just entered a code sent to it — so it becomes the primary entry
               in the verified set. Without this, an account that has only ever
               signed in would have an empty phone list and checkout would have
               nothing to prefill.

               Both writes are guarded. They run *after* the session is already
               established, but an uncaught exception here would still reach the
               client as a 500 and the customer would believe the sign-in failed
               when it in fact succeeded — then they would tap the code again and
               the OTP would already be spent. A booking write must never be able
               to contradict a login that worked. */
            user_phone_remember($uid, $phone);
            if (velora_addressbook_ready()) {
                try {
                    $pdo->prepare("UPDATE velora_user_phones SET is_primary=1, last_used_at=NOW()
                                   WHERE user_id=? AND phone=?")->execute([$uid, $phone]);
                } catch (Throwable $e) {
                    log_action('PHONE_PRIMARY_FAIL', ['user_id' => $uid, 'error' => $e->getMessage()]);
                }
            }
            log_action('USER_LOGIN', ['user_id' => $uid, 'phone' => $phone]);
            jresp([
                'ok'      => true,
                'message' => 'ورود موفق',
                'user_id' => $uid,
                'phone'   => $phone,
                'name'    => $uname,
                'csrf'    => $_SESSION['csrf'],
            ]);
            break;

        case 'logout':
            if (empty($_SESSION['user_id'])) {
                jresp(['ok' => true, 'message' => 'خروج موفق', 'already' => true]);
            }
            csrf_check();
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            jresp(['ok' => true, 'message' => 'خروج موفق']);
            break;

        case 'me':
            if (!is_user()) {
                jresp(['ok' => false, 'logged' => false, 'error' => 'LOGIN_REQUIRED'], 401);
            }
            $st = $pdo->prepare("SELECT id, phone, name, email, created_at FROM velora_users WHERE id=?");
            $st->execute([current_user_id()]);
            $user = $st->fetch();
            if (!$user) jresp(['ok' => false, 'error' => 'USER_NOT_FOUND'], 404);
            jresp(['ok' => true, 'logged' => true, 'user' => $user]);
            break;

        case 'products':
            if (!rate_limit('browse_products', 60, 60)) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $cat    = req_str('cat');
            $sale   = req_str('sale') === '1';
            $stockF = req_str('stock') === '1';
            $q      = req_str('q');
            $sort   = req_str('sort', 'featured');
            $max    = max(1, min(999_999_999, req_int('max', 999_999_999)));
            $page   = max(1, req_int('page', 1));
            $limit  = min(48, max(12, req_int('limit', 24)));
            $offset = ($page - 1) * $limit;

            $rows = velora_catalog_active();

            if ($cat !== '') $rows = array_values(array_filter($rows, static fn(array $p): bool => (string) ($p['cat'] ?? '') === $cat));
            if ($sale)       $rows = array_values(array_filter($rows, static fn(array $p): bool => (int) ($p['old_price'] ?? 0) > 0 && (int) ($p['old_price'] ?? 0) > (int) ($p['price'] ?? 0)));
            if ($stockF)     $rows = array_values(array_filter($rows, static function (array $p): bool {
                $total = 0;
                foreach ((array) ($p['sizes'] ?? []) as $s) $total += (int) ($s['stock'] ?? 0);
                return $total > 0;
            }));
            if ($q !== '') {
                $needle = mb_strtolower($q, 'UTF-8');
                $rows = array_values(array_filter($rows, static function (array $p) use ($needle): bool {
                    $hay = mb_strtolower(
                        ($p['name'] ?? '') . ' ' . ($p['sub'] ?? '') . ' ' . ($p['cat'] ?? '') . ' ' . ($p['desc'] ?? ''),
                        'UTF-8'
                    );
                    return mb_strpos($hay, $needle) !== false;
                }));
            }
            $rows = array_values(array_filter($rows, static fn(array $p): bool => (int) ($p['price'] ?? 0) <= $max));

            usort($rows, static function (array $a, array $b) use ($sort): int {
                return match ($sort) {
                    'new'        => ((int) ($b['is_new'] ?? 0)) <=> ((int) ($a['is_new'] ?? 0)),
                    'sold'       => ((int) ($b['sold'] ?? 0))   <=> ((int) ($a['sold'] ?? 0)),
                    'price-asc'  => ((int) ($a['price'] ?? 0))  <=> ((int) ($b['price'] ?? 0)),
                    'price-desc' => ((int) ($b['price'] ?? 0))  <=> ((int) ($a['price'] ?? 0)),
                    'discount'   => (((int) ($b['old_price'] ?? 0) - (int) ($b['price'] ?? 0))) <=> (((int) ($a['old_price'] ?? 0) - (int) ($a['price'] ?? 0))),
                    default      => ((int) ($b['is_new'] ?? 0)) <=> ((int) ($a['is_new'] ?? 0))
                                 ?: ((int) ($b['sold'] ?? 0))   <=> ((int) ($a['sold'] ?? 0)),
                };
            });

            $slice = array_slice($rows, $offset, $limit);
            $out   = array_map('velora_catalog_client_shape', $slice);
            jresp([
                'ok'       => true,
                'products' => $out,
                'page'     => $page,
                'limit'    => $limit,
                'has_more' => count($rows) > $offset + $limit,
            ], 200, true);
            break;

        case 'product':
            if (!rate_limit('browse_product', 120, 60)) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $id = req_str('id');
            if ($id === '' || strlen($id) > 64) jresp(['ok' => false, 'error' => 'ID_REQUIRED'], 400);
            $row = velora_catalog_product($id);
            if ($row === null || empty($row['active'])) jresp(['ok' => false, 'error' => 'NOT_FOUND'], 404);
            jresp(['ok' => true, 'product' => velora_catalog_client_shape($row)], 200, true);
            break;

        case 'checkout':
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
            break;

        case 'payment_callback':
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

        case 'payment_status':
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

        case 'geo_regions':
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
            break;

        case 'postal_lookup':
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
            break;

        case 'address_list':
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
            break;

        case 'address_save':
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
            break;

        case 'address_delete':
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
            break;

        case 'address_set_default':
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
            break;

        case 'account_update':
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
            break;

        case 'account_profile':
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
            break;

        case 'my_orders':
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
            break;

        case 'reviews':
            if (!rate_limit('browse_reviews', 100, 60)) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $pid = req_str('product_id');
            if ($pid === '' || strlen($pid) > 64) jresp(['ok' => false, 'error' => 'PRODUCT_ID_REQUIRED'], 400);
            $st = $pdo->prepare("SELECT user_name, rating, text, created_at
                FROM velora_reviews WHERE product_id=? ORDER BY created_at DESC LIMIT 10");
            $st->execute([$pid]);
            jresp(['ok' => true, 'reviews' => $st->fetchAll()], 200, true);
            break;

        case 'submit_review':
            csrf_check();
            if (!is_user()) jresp(['ok' => false, 'error' => 'LOGIN_REQUIRED'], 401);
            if (!rate_limit('review_submit', 3, 3600)) {
                jresp([
                    'ok'      => false,
                    'error'   => 'RATE_LIMIT',
                    'message' => 'حداکثر ۳ نظر در هر ساعت — لطفاً بعداً تلاش کنید',
                ], 429);
            }
            $pid    = req_str('product_id');
            $rating = req_int('rating');
            $text   = req_str('text');
            $name   = req_str('name');

            if ($pid === '' || strlen($pid) > 64
                || $rating < 1 || $rating > 5
                || mb_strlen($text) < 5  || mb_strlen($text) > 2000
                || mb_strlen($name) < 2  || mb_strlen($name) > 80) {
                jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
            }
            $st = $pdo->prepare("SELECT 1 FROM velora_order_items oi
                JOIN velora_orders o ON oi.order_id = o.id
                WHERE oi.product_id=? AND o.user_id=? AND o.payment_status='paid' LIMIT 1");
            $st->execute([$pid, current_user_id()]);
            if (!$st->fetch()) {
                jresp(['ok' => false, 'error' => 'PURCHASE_REQUIRED', 'message' => 'برای ثبت نظر باید این محصول را خریده باشید'], 403);
            }
            $pdo->prepare("INSERT INTO velora_reviews (product_id, user_name, rating, text) VALUES (?, ?, ?, ?)")
                ->execute([$pid, $name, $rating, $text]);
            log_action('REVIEW_SUBMIT', ['product_id' => $pid, 'rating' => $rating]);
            jresp(['ok' => true, 'message' => 'نظر شما ثبت شد']);
            break;

        case 'book_appointment':
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
            break;

        case 'notify_restock':
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
            break;

        case 'admin_product_reserve_id':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            csrf_check();
            /* An allocation per call, with no ceiling.

               velora_catalog_reserve_id() takes the catalogue's write lock,
               reads every product id, and picks a fresh 80-bit one — so each
               call is a locked read of the whole file to produce a string an
               operator is going to use once. Loop it and you have turned an
               admin-only endpoint into the most expensive read on the site, and
               the ids it burns are permanently consumed from the namespace.

               30 per hour is far more than one operator needs: the panel
               reserves an id when a product is created, and an operator
               creating thirty products an hour is not a pattern worth
               supporting. Scoped per admin session, so one tab cannot exhaust
               another's allowance. */
            if (!rate_limit('admin_reserve_id', 30, 3600, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            try {
                jresp(['ok' => true, 'id' => velora_catalog_reserve_id()]);
            } catch (Throwable $e) {
                jresp(['ok' => false, 'error' => 'ID_ALLOC_FAILED'], 500);
            }
            break;

        case 'admin_upload_image':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            csrf_check();
            if (!rate_limit('admin_upload', 30, 300, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT',
                       'message' => 'تعداد آپلودها زیاد است — کمی بعد دوباره تلاش کنید'], 429);
            }

            if (!extension_loaded('gd')) {
                jresp(['ok' => false, 'error' => 'GD_MISSING',
                       'message' => 'افزونه GD روی سرور فعال نیست'], 500);
            }
            if (!function_exists('imagewebp')) {
                jresp(['ok' => false, 'error' => 'WEBP_UNSUPPORTED',
                       'message' => 'پشتیبانی WebP در GD فعال نیست'], 500);
            }

            $productId = req_str('product_id');
            if ($productId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $productId)) {
                jresp(['ok' => false, 'error' => 'INVALID_PRODUCT_ID'], 400);
            }

            if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
                jresp(['ok' => false, 'error' => 'NO_FILE'], 400);
            }
    $file = $_FILES['image'];
    /* UPLOAD_ERR_INI_SIZE is the failure this branch exists to explain.

       Under mod_php the ceiling is PHP's own upload_max_filesize /
       post_max_size, and exceeding it does NOT reliably arrive as
       UPLOAD_ERR_INI_SIZE: with no file in the request at all, PHP can discard
       the body and populate nothing, so $_FILES['image'] is simply absent and
       the honest answer is NO_FILE. Under PHP-FPM the same overflow is
       reported as UPLOAD_ERR_INI_SIZE with an empty tmp_name.

       Both look identical in the browser — "the upload did not happen" — and
       they have opposite causes, one of them fixed in .htaccess and the other
       not. So the code is passed back verbatim rather than collapsed to a
       generic failure, and the panel's ceiling constant is checked against the
       live ini values at runtime (see ADMIN_UPLOAD_MAX_BYTES) so the operator
       is told which limit they actually hit. Collapsing this to "upload
       failed" is what made an ini/htaccess mismatch look like a broken
       endpoint. */
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jresp(['ok' => false, 'error' => 'UPLOAD_ERROR',
            'code' => (int) ($file['error'] ?? 0)], 400);
    }
            if (!is_uploaded_file($file['tmp_name'])) {
                jresp(['ok' => false, 'error' => 'INVALID_UPLOAD'], 400);
            }
            $maxBytes = ADMIN_UPLOAD_MAX_BYTES;
            if (($file['size'] ?? 0) > $maxBytes) {
                jresp(['ok' => false, 'error' => 'FILE_TOO_LARGE',
                       'max' => $maxBytes, 'message' => 'حداکثر ' . ADMIN_UPLOAD_MAX_LABEL], 413);
            }
            $iniLimit = static function (string $key): int {
                $v = trim((string) ini_get($key));
                if ($v === '') return 0;
                $unit = strtolower(substr($v, -1));
                $n = (int) $v;
                return $unit === 'm' ? $n * 1048576
                     : ($unit === 'g' ? $n * 1073741824
                     : ($unit === 'k' ? $n * 1024 : $n));
            };
            $iniCaps = array_values(array_filter([
                $iniLimit('upload_max_filesize'),
                $iniLimit('post_max_size'),
            ]));
            $iniMax = $iniCaps ? min($iniCaps) : PHP_INT_MAX;
            if ($iniMax !== PHP_INT_MAX && ($file['size'] ?? 0) > $iniMax) {
                jresp(['ok' => false, 'error' => 'FILE_TOO_LARGE',
                       'max' => $iniMax, 'php_ini_limit' => true,
                       'message' => 'حجم فایل از سقف تنظیم‌شده روی سرور بیشتر است. '
                                  . 'مقدار upload_max_filesize و post_max_size در php.ini '
                                  . 'باید حداقل ' . ADMIN_UPLOAD_MAX_LABEL . ' باشد.'], 413);
            }

            $info = @getimagesize($file['tmp_name']);
            if (!$info) jresp(['ok' => false, 'error' => 'INVALID_IMAGE'], 400);

            $typeMap = [
                IMAGETYPE_JPEG => 'imagecreatefromjpeg',
                IMAGETYPE_PNG  => 'imagecreatefrompng',
                IMAGETYPE_GIF  => 'imagecreatefromgif',
            ];
            if (function_exists('imagecreatefromwebp')) {
                $typeMap[IMAGETYPE_WEBP] = 'imagecreatefromwebp';
            }
            if (!isset($typeMap[$info[2]])) {
                jresp(['ok' => false, 'error' => 'UNSUPPORTED_FORMAT'], 400);
            }

            $srcW = (int) $info[0];
            $srcH = (int) $info[1];
            if ($srcW < 1 || $srcH < 1) {
                jresp(['ok' => false, 'error' => 'ZERO_DIM'], 400);
            }
            $maxEdge   = 2400;
            $maxPixels = 4_000_000;
            if ($srcW > $maxEdge || $srcH > $maxEdge) {
                jresp(['ok' => false, 'error' => 'IMAGE_TOO_LARGE',
                       'max' => $maxEdge, 'message' => 'ابعاد تصویر بیش از حد بزرگ است'], 400);
            }
            if (($srcW * $srcH) > $maxPixels) {
                jresp(['ok' => false, 'error' => 'IMAGE_TOO_LARGE',
                       'max_pixels' => $maxPixels, 'message' => 'ابعاد تصویر بیش از حد بزرگ است'], 400);
            }

            $src = @$typeMap[$info[2]]($file['tmp_name']);
            if (!$src) jresp(['ok' => false, 'error' => 'DECODE_FAILED'], 500);

            $side  = min($srcW, $srcH);
            $cropX = (int) (($srcW - $side) / 2);
            $cropY = (int) (($srcH - $side) / 2);

            $dst = imagecreatetruecolor(1024, 1024);
            if (!$dst) { imagedestroy($src); jresp(['ok' => false, 'error' => 'CANVAS_FAILED'], 500); }

            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, 1023, 1023, $transparent);
            imagealphablending($dst, true);

            if (!imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, 1024, 1024, $side, $side)) {
                imagedestroy($src); imagedestroy($dst);
                jresp(['ok' => false, 'error' => 'RESIZE_FAILED'], 500);
            }
            imagedestroy($src);

            $dir = product_upload_dir();
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (!is_dir($dir) || !is_writable($dir)) {
                imagedestroy($dst);
                jresp(['ok' => false, 'error' => 'DIR_NOT_WRITABLE'], 500);
            }

            $unique   = bin2hex(random_bytes(8));
            $stemBase = substr($productId, 0, 80 - 1 - strlen($unique));
            $filename = $stemBase . '-' . $unique . '.webp';
            $filepath = $dir . '/' . $filename;

            if (!is_safe_upload_filename($filename)) {
                imagedestroy($dst);
                jresp(['ok' => false, 'error' => 'FILENAME_TOO_LONG',
                       'message' => 'نام شناسهٔ محصول برای ساخت نام فایل معتبر بسیار طولانی است'], 400);
            }

            if (!imagewebp($dst, $filepath, 86)) {
                imagedestroy($dst);
                jresp(['ok' => false, 'error' => 'WEBP_SAVE_FAILED'], 500);
            }
            imagedestroy($dst);
            @chmod($filepath, 0644);

            $replaced = false;
            $replaceName = req_str('replace_filename');
            if ($replaceName !== '' && is_safe_upload_filename($replaceName)) {
                $oldPath = $dir . '/' . $replaceName;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                    $replaced = true;
                }
            }

            $fileBytes = (int) @filesize($filepath);

            try {
                $stale = ($replaced ? $replaceName : null);
                velora_catalog_transaction(function(array $data, array &$out) use ($productId, $filename, $stale): bool {
                    foreach ($out['products'] as &$p) {
                        if (($p['id'] ?? '') !== $productId) continue;
                        $gal = is_array($p['gallery'] ?? null) ? $p['gallery'] : [];
                        if ($stale !== null && $stale !== '') {
                            $gal = array_values(array_filter(
                                $gal, static fn($k): bool => $k !== $stale
                            ));
                        }
                        if (!in_array($filename, $gal, true)) {
                            $gal[] = $filename;
                        }
                        $p['gallery'] = $gal;
                        break;
                    }
                    unset($p);
                    return true;
                });
            } catch (Throwable $e) {
                log_action('ADMIN_UPLOAD_CATALOG_WRITE_FAIL', ['error' => $e->getMessage(), 'product_id' => $productId]);
            }

            log_action('ADMIN_IMAGE_UPLOAD', [
                'product_id' => $productId,
                'filename'   => $filename,
                'replaced'   => $replaced ? $replaceName : null,
                'bytes'      => $fileBytes,
            ]);

            jresp([
                'ok'       => true,
                'url'      => product_upload_url_base() . '/' . $filename,
                'filename' => $filename,
                'width'    => 1024,
                'height'   => 1024,
                'bytes'    => $fileBytes,
                'replaced' => $replaced,
            ]);
            break;

        case 'contact':
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

        case 'admin_login':
            csrf_check();
            if (!rate_limit('admin_login', 5, 300)) jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);

            if (!ADMIN_HASH_CONFIGURED) {
                log_action('ADMIN_LOGIN_MISCONFIGURED', ['ip' => get_client_ip()]);
                jresp([
                    'ok'      => false,
                    'error'   => 'ADMIN_PASSWORD_NOT_HASHED',
                    'message' => 'پیکربندی سرور ناقص است: رمز مدیر به‌صورت هش ذخیره نشده است. با مدیر سرور تماس بگیرید.',
                ], 503);
            }

            $user = req_str('username');
            $pass = (string) req('password');

            $userOk = hash_equals(ADMIN_USER, $user);
            $passMode = is_password_hash(ADMIN_PASS_HASH) ? 'hash' : 'plain';

            $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            $effectiveHash = ($userOk && is_password_hash(ADMIN_PASS_HASH))
                ? ADMIN_PASS_HASH
                : $dummyHash;
            $passOk = password_verify($pass, $effectiveHash) && $userOk;

            if (!$userOk || !$passOk) {
                log_action('ADMIN_LOGIN_FAIL', [
                    'username_sent'     => $user,
                    'username_sent_len' => strlen($user),
                    'username_cfg_len'  => strlen(ADMIN_USER),
                    'username_ok'       => $userOk,
                    'password_sent_len' => strlen($pass),
                    'password_cfg_len'  => strlen(ADMIN_PASS_HASH),
                    'password_mode'     => $passMode,
                    'password_ok'       => $passOk,
                    'ip'                => get_client_ip(),
                ]);
                jresp([
                    'ok'      => false,
                    'error'   => 'INVALID_CREDENTIALS',
                    'message' => 'نام کاربری یا رمز عبور اشتباه است',
                ], 401);
            }

            if (admin_totp_enabled()) {
                if (!rate_limit('admin_totp', 5, 300, 'a' . session_id())) {
                    log_action('ADMIN_TOTP_RATE_LIMIT', ['ip' => get_client_ip()]);
                    jresp(['ok' => false, 'error' => 'RATE_LIMIT',
                           'message' => 'تلاش‌های ناموفق زیاد بود. کمی بعد دوباره تلاش کنید.'], 429);
                }
                $secret = admin_totp_secret();
                $code   = req_str('code');
                if ($secret === '') {
                    log_action('ADMIN_TOTP_SECRET_UNREADABLE', ['ip' => get_client_ip()]);
                    jresp([
                        'ok'      => false,
                        'error'   => 'TOTP_NOT_CONFIGURED',
                        'message' => 'کلید تأیید دوم‌مرحله‌ای قابل خواندن نیست. با مدیر سرور تماس بگیرید.',
                    ], 503);
                }
                if (!admin_totp_verify($secret, $code)) {
                    log_action('ADMIN_LOGIN_FAIL_2FA', [
                        'code_len' => strlen($code),
                        'ip'       => get_client_ip(),
                    ]);
                    jresp([
                        'ok'      => false,
                        'error'   => 'TOTP_INVALID',
                        'message' => 'کد تأیید نامعتبر است یا منقضی شده',
                    ], 401);
                }
            }

            session_regenerate_id(true);
            $_SESSION['csrf']           = bin2hex(random_bytes(32));
            $_SESSION['csrf_at']        = time();
            $_SESSION['admin']          = true;
            $_SESSION['admin_user']     = ADMIN_USER;
            $_SESSION['admin_login_at'] = time();
            log_action('ADMIN_LOGIN', [
                'username' => ADMIN_USER,
                'ip'       => get_client_ip(),
                'two_factor' => admin_totp_enabled() ? 'totp' : 'none',
            ]);
            jresp(['ok' => true, 'message' => 'ورود موفق', 'csrf' => $_SESSION['csrf']]);
            break;

        case 'admin_logout':
            if (!is_admin()) jresp(['ok' => false], 401);
            csrf_check();
            log_action('ADMIN_LOGOUT', ['username' => $_SESSION['admin_user'] ?? 'unknown']);
            unset($_SESSION['admin'], $_SESSION['admin_user'], $_SESSION['admin_login_at']);
            jresp(['ok' => true]);
            break;

        case 'admin_stats':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            if (!rate_limit('admin_read', 120, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $active = velora_catalog_active();
            $lowStock = 0;
            foreach ($active as $p) {
                $total = 0;
                foreach ((array) ($p['sizes'] ?? []) as $s) $total += (int) ($s['stock'] ?? 0);
                if ($total < 5) $lowStock++;
            }
            $stats = [
                'total_orders'   => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders")->fetchColumn(),
                'paid_orders'    => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders WHERE payment_status='paid'")->fetchColumn(),
                'revenue'        => (int) $pdo->query("SELECT COALESCE(SUM(total),0) FROM velora_orders WHERE payment_status='paid'")->fetchColumn(),
                'pending_orders' => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders WHERE status='pending' AND payment_status='paid'")->fetchColumn(),
                'total_products' => count($active),
                'low_stock'      => $lowStock,
                'total_users'    => (int) $pdo->query("SELECT COUNT(*) FROM velora_users")->fetchColumn(),
                'today_orders'   => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY")->fetchColumn(),
            ];
            jresp(['ok' => true, 'stats' => $stats]);
            break;

        case 'admin_orders':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            if (!rate_limit('admin_read', 120, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $status = req_str('status');
            $page   = max(1, req_int('page', 1));
            $limit  = min(200, max(1, req_int('limit', 50)));
            $offset = ($page - 1) * $limit;
            $where = "1=1";
            $params = [];
            $allowed = ['pending','processing','shipped','delivered','cancelled'];
            if ($status !== '' && in_array($status, $allowed, true)) {
                $where .= " AND o.status=?";
                $params[] = $status;
            }
            $sql = "SELECT o.*, u.name AS user_name, u.phone AS user_phone
                FROM velora_orders o
                LEFT JOIN velora_users u ON o.user_id = u.id
                WHERE $where
                ORDER BY o.created_at DESC, o.id DESC
                LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $st = $pdo->prepare($sql);
            $st->execute($params);
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
                $o['items'] = $itemsByOrder[$o['id']] ?? [];
                /* address_snapshot is a JSON column, so PDO hands it over as a
                   string. Decoding it here rather than in admin.php means the
                   modal can read the parts directly, and an operator sees the
                   province/city/plaque the customer actually typed instead of
                   re-parsing a composed line by eye.
                   Decoded defensively: a snapshot written before the column
                   existed is NULL, and a hand-edited one must not be able to
                   take the whole order list down. */
                $snap = null;
                if (isset($o['address_snapshot']) && is_string($o['address_snapshot']) && $o['address_snapshot'] !== '') {
                    $decoded = json_decode($o['address_snapshot'], true);
                    if (is_array($decoded)) $snap = $decoded;
                }
                unset($o['address_snapshot']);
                $o['address_snapshot'] = $snap;
                /* The postal code, formatted once, for the same reason the
                   storefront formats it: one place decides how a code reads. */
                $o['postal_display'] = $snap && !empty($snap['postal_code'])
                    ? velora_postal_format((string) $snap['postal_code'])
                    : '';
            }
            unset($o);
            jresp(['ok' => true, 'orders' => $orders, 'count' => count($orders), 'limit' => $limit]);
            break;

        case 'admin_order_status':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            csrf_check();
            if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $oid    = req_str('id');
            $status = req_str('status');
            $allowed = ['pending','processing','shipped','delivered','cancelled'];
            if (!in_array($status, $allowed, true) || $oid === '' || strlen($oid) > 32) {
                jresp(['ok' => false, 'error' => 'INVALID_STATUS'], 400);
            }
            $pdo->beginTransaction();
            try {
                $sel = $pdo->prepare("SELECT id, status, payment_status FROM velora_orders WHERE id=? FOR UPDATE");
                $sel->execute([$oid]);
                $order = $sel->fetch();
                if (!$order) {
                    $pdo->rollBack();
                    jresp(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'سفارش یافت نشد'], 404);
                }
                $prev = (string) $order['status'];
                if ($prev === $status) {
                    $pdo->commit();
                    jresp(['ok' => true, 'message' => 'وضعیت تغییری نکرد', 'unchanged' => true]);
                }

                $FULFILMENT = ['processing', 'shipped', 'delivered'];
                $wasPaid    = ((string) $order['payment_status']) === 'paid';
                if (in_array($status, $FULFILMENT, true) && !$wasPaid) {
                    $pdo->rollBack();
                    log_action('ADMIN_ORDER_STATUS_BLOCKED', [
                        'order_id' => $oid,
                        'from'     => $prev,
                        'to'       => $status,
                        'payment'  => (string) $order['payment_status'],
                    ]);
                    jresp([
                        'ok'      => false,
                        'error'   => 'PAYMENT_REQUIRED',
                        'message' => 'این سفارش پرداخت نشده است — ابتدا باید پرداخت آن تأیید شود',
                    ], 409);
                }

                $pdo->prepare("UPDATE velora_orders SET status=?, updated_at=NOW() WHERE id=?")
                    ->execute([$status, $oid]);

                $stockInfo = ['items' => 0, 'restored' => 0, 'short' => 0];
                if ($status === 'cancelled' && $prev !== 'cancelled') {
                    $stockInfo = adjust_order_stock($pdo, $oid, +1, $wasPaid);
                } elseif ($prev === 'cancelled' && $status !== 'cancelled') {
                    $stockInfo = adjust_order_stock($pdo, $oid, -1, $wasPaid);
                    if ($stockInfo['short'] > 0) {
                        throw new Exception('STOCK_SHORT:' . $stockInfo['short']);
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (str_starts_with($e->getMessage(), 'STOCK_SHORT:')) {
                    jresp([
                        'ok'      => false,
                        'error'   => 'INSUFFICIENT_STOCK',
                        'message' => 'موجودی کافی نیست تا این سفارش دوباره فعال شود',
                    ], 409);
                }
                log_action('ADMIN_ORDER_STATUS_FAIL', ['order_id' => $oid, 'error' => $e->getMessage()]);
                jresp(['ok' => false, 'error' => 'STATUS_FAIL', 'message' => 'خطا در به‌روزرسانی وضعیت'], 500);
            }

            log_action('ADMIN_ORDER_STATUS', [
                'order_id' => $oid,
                'from'     => $prev,
                'status'   => $status,
                'stock'    => $stockInfo,
            ]);
            jresp([
                'ok'      => true,
                'message' => 'وضعیت به‌روزرسانی شد',
                'stock'   => $stockInfo,
            ]);
            break;

        case 'admin_products':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            if (!rate_limit('admin_read', 60, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $products = [];
            foreach (velora_catalog_products() as $p) {
                $c = velora_catalog_client_shape($p);
                $total = 0;
                foreach ($c['sizes'] as $s) $total += (int) $s['stock'];
                $products[] = [
                    'id'          => $c['id'],
                    'name'        => $c['name'],
                    'cat'         => $c['cat'],
                    'sub'         => $c['sub'],
                    'desc'        => $c['desc'],
                    'price'       => $c['price'],
                    'old_price'   => $c['old'],
                    'heel'        => $c['heel'],
                    'eta'         => $c['eta'],
                    'is_new'      => $c['isNew'],
                    'sold'        => $c['sold'],
                    'active'      => !empty($p['active']) ? 1 : 0,
                    'feats'       => $c['feats'],
                    'specs'       => $c['specs'],
                    'colors'      => array_map(static fn(array $c): array => ['key' => $c[0], 'name' => $c[1]], $c['colors']),
                    'sizes'       => $c['sizes'],
                    'gallery'     => implode('|', $c['gallery']),
                    'total_stock' => $total,
                ];
            }
            jresp(['ok' => true, 'products' => $products, 'catalog' => function_exists('velora_catalog_health') ? velora_catalog_health() : null]);
            break;

        case 'admin_product_save':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            csrf_check();
            if (!rate_limit('admin_write', 60, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }

            $id    = req_str('id');
            $name  = req_str('name');
            $cat   = req_str('cat');
            $sub   = req_str('sub');
            $desc  = req_str('desc');
            $price = req_int('price');
            $old   = req_int('old_price');
            $heel  = req_int('heel');
            $isNew = min(1, max(0, req_int('is_new')));
            $eta   = req_int('eta', 4);
            $mode  = req_str('mode', 'update') === 'create' ? 'create' : 'update';

            if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) jresp(['ok' => false, 'error' => 'ID_INVALID'], 400);

            if ($mode === 'create' && !preg_match('/^p-[0-9a-f]{20}$/', $id)) {
                jresp(['ok' => false, 'error' => 'ID_NOT_SERVER_ISSUED', 'message' => 'شناسه باید توسط سرور تولید شده باشد'], 400);
            }
            if ($name === '' || mb_strlen($name) > 255
                || mb_strlen($sub)  > 512
                || mb_strlen($desc) > 8000
                || $price < 0 || $price > 2_000_000_000
                || $old   < 0 || $old   > 2_000_000_000
                || $heel  < 0 || $heel  > 300
                || $eta   < 0 || $eta   > 90) {
                jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
            }

            /* The category is a closed vocabulary, not a free string.

               It was validated only by length — `strlen($cat) > 64` — so any 64
               characters were accepted and written to products.json. The only
               thing stopping a typo was the admin panel's <select>, and a panel
               is a UI, not a constraint: a stale tab, a restored session, a
               hand-edited request, or a category added to the panel and not to
               the validator all put an unreachable product into the catalogue.

               Unreachable is the right word for the failure. Nothing filters by
               an unknown category, so the product renders on a card, opens on
               its own page, and can be bought — and it can never be found
               again by browsing, because no category chip matches it. A product
               that exists and cannot be located is worse than a save that is
               refused, so the vocabulary is enforced where the write happens,
               against the same CATEGORY_SLUGS the panel builds its <select>
               from.

               The rejection names the allowed values. "INVALID_INPUT" would tell
               an operator their save failed and nothing about why. */
            if (!in_array($cat, CATEGORY_SLUGS, true)) {
                jresp([
                    'ok'      => false,
                    'error'   => 'CATEGORY_INVALID',
                    'message' => 'دستهٔ نامعتبر است — یکی از این‌ها را انتخاب کنید: '
                                 . implode('، ', CATEGORY_LABELS),
                    'allowed' => array_values(CATEGORY_SLUGS),
                ], 400);
            }

            if ($old > 0 && $old <= $price) {
                jresp(['ok' => false, 'error' => 'OLD_PRICE_INVALID', 'message' => 'قیمت پیش از تخفیف باید بیشتر از قیمت فعلی باشد'], 400);
            }

            $hasColors  = req_has('colors');
            $hasSizes   = req_has('sizes');
            $hasGallery = req_has('gallery');
            $hasFeats   = req_has('feats');
            $hasSpecs   = req_has('specs');

            $existingWidth = null;
            $existingSole  = null;
            $existing = velora_catalog_product($id);
            if ($existing !== null) {
                $existingWidth = $existing['width'] ?? 'r';
                $existingSole  = $existing['sole']  ?? 'leather';
            }
            $width = req_has('width') ? req_str('width', 'r') : ($existingWidth ?? 'r');
            $sole  = req_has('sole')  ? req_str('sole', 'leather') : ($existingSole ?? 'leather');
            if (!in_array($width, ['n','r','w'], true)) $width = $existingWidth ?? 'r';
            if (!in_array($sole,  ['leather','rubber'], true)) $sole = $existingSole ?? 'leather';

            $sizesLockedBy = null;
            if ($hasSizes) {
                $holdSt = $pdo->prepare(
                    "SELECT o.id
                       FROM velora_orders o
                       JOIN velora_order_items oi ON oi.order_id = o.id
                      WHERE oi.product_id = ?
                        AND o.status = 'pending'
                        AND o.payment_status IN ('unpaid','pending')
                      ORDER BY o.created_at ASC
                      LIMIT 1"
                );
                $holdSt->execute([$id]);
                $held = $holdSt->fetch();
                if ($held) $sizesLockedBy = (string) $held['id'];
            }

            $colorsPayload  = $hasColors  ? req_json('colors')  : null;
            $sizesPayload   = $hasSizes   ? req_json('sizes')   : null;
            $galleryPayload = $hasGallery ? req_json('gallery') : null;
            $featsPayload   = $hasFeats   ? req_json('feats')   : null;
            $specsPayload   = $hasSpecs   ? req_json('specs')   : null;

            try {
                velora_catalog_transaction(function(array $data, array &$out) use (
                    $mode, $id, $name, $cat, $sub, $desc, $price, $old, $heel, $width, $sole,
                    $isNew, $eta, $colorsPayload, $sizesPayload, $galleryPayload,
                    $featsPayload, $specsPayload,
                    $sizesLockedBy, $hasColors, $hasSizes, $hasGallery, $hasFeats, $hasSpecs
                ): bool {
                    $idx = null;
                    foreach ($out['products'] as $i => $prod) {
                        if (($prod['id'] ?? '') === $id) { $idx = $i; break; }
                    }
                    if ($mode === 'create' && $idx !== null) {
                        throw new RuntimeException('PRODUCT_EXISTS');
                    }
                    if ($mode === 'update' && $idx === null) {
                        throw new RuntimeException('PRODUCT_NOT_FOUND');
                    }

                    $existing = $idx !== null ? $out['products'][$idx] : [];
                    $record = [
                        'id'         => $id,
                        'name'       => $name,
                        'cat'        => $cat,
                        'sub'        => $sub,
                        'desc'       => $desc,
                        'price'      => $price,
                        'old_price'  => $old,
                        'heel'       => $heel,
                        'width'      => $width,
                        'sole'       => $sole,
                        'is_new'     => $isNew === 1,
                        'sold'       => (int) ($existing['sold'] ?? 0),
                        'eta'        => $eta,
                        'bias'       => (float) ($existing['bias'] ?? 0),
                        'active'     => true,
                        'sort_order' => (int) ($existing['sort_order'] ?? (count($out['products']))),
                        'drop_date'  => (string) ($existing['drop_date'] ?? date('Y-m-d')),
                        'feats'      => $existing['feats']   ?? [],
                        'specs'      => $existing['specs']   ?? [],
                        'colors'     => $existing['colors']  ?? [],
                        'sizes'      => $existing['sizes']   ?? [],
                        'gallery'    => $existing['gallery'] ?? [],
                    ];

                    if ($hasFeats && is_array($featsPayload)) {
                        $record['feats'] = array_values(array_filter(array_map(
                            static fn($v): string => trim((string) $v), $featsPayload
                        ), 'strlen'));
                    }
                    if ($hasSpecs && is_array($specsPayload)) {
                        $spec = [];
                        foreach ($specsPayload as $k => $v) {
                            $k = trim((string) $k);
                            if ($k === '') continue;
                            if (is_scalar($v) || $v === null) $spec[$k] = (string) $v;
                        }
                        $record['specs'] = $spec;
                    }
                    if ($hasColors && is_array($colorsPayload)) {
                        $clean = [];
                        foreach ($colorsPayload as $c) {
                            if (!is_array($c)) continue;
                            $k = trim((string) ($c['key']  ?? ''));
                            $n = trim((string) ($c['name'] ?? ''));
                            if ($k === '' || $n === '') continue;
                            $clean[] = ['key' => mb_substr($k, 0, 64), 'name' => mb_substr($n, 0, 128)];
                            if (count($clean) >= 32) break;
                        }
                        $record['colors'] = $clean;
                    }
                    if ($hasSizes && is_array($sizesPayload) && $sizesLockedBy === null) {
                        $clean = [];
                        $seen  = [];
                        foreach ($sizesPayload as $s) {
                            if (!is_array($s)) continue;
                            $eu = (int) ($s['eu']    ?? 0);
                            $st = (int) ($s['stock'] ?? 0);
                            if ($eu < SIZE_MIN || $eu > SIZE_MAX) continue;
                            if ($st < 0 || $st > 100000) continue;
                            if (isset($seen[$eu])) continue;
                            $seen[$eu] = true;
                            $clean[] = ['eu' => $eu, 'stock' => $st];
                        }
                        usort($clean, static fn(array $a, array $b): int => $a['eu'] <=> $b['eu']);
                        $record['sizes'] = $clean;
                    }
                    if ($hasGallery && is_array($galleryPayload)) {
                        $clean = [];
                        foreach ($galleryPayload as $g) {
                            if (!is_string($g)) continue;
                            $key = sanitize_image_key($g);
                            if ($key !== '') $clean[] = $key;
                            if (count($clean) >= 32) break;
                        }
                        $record['gallery'] = $clean;
                    }

                    if ($idx !== null) $out['products'][$idx] = $record;
                    else               $out['products'][]       = $record;
                    return true;
                });

                log_action('ADMIN_PRODUCT_SAVE', [
                    'product_id'     => $id,
                    'mode'           => $mode,
                    'sizes_skipped'  => $sizesLockedBy,
                    'colors_replaced'=> $hasColors,
                    'gallery_replaced'=> $hasGallery,
                ]);
                $saved = ['ok' => true, 'message' => 'محصول ذخیره شد'];
                if ($sizesLockedBy !== null) {
                    $saved['sizes_skipped']    = true;
                    $saved['blocked_by_order'] = $sizesLockedBy;
                    $saved['message']          = 'محصول ذخیره شد — اما موجودی سایزها تغییر نکرد';
                    $saved['warning']          = 'سفارش ' . $sizesLockedBy . ' هنوز در انتظار پرداخت این محصول است. '
                                       . 'موجودی سایزها برای جلوگیری از فروش مضاعف تغییر داده نشد؛ چند دقیقه دیگر دوباره تلاش کنید.';
                }
                jresp($saved);
            } catch (Throwable $e) {
                if ($e->getMessage() === 'PRODUCT_EXISTS') {
                    jresp(['ok' => false, 'error' => 'PRODUCT_EXISTS', 'message' => 'این شناسه قبلاً استفاده شده است'], 409);
                }
                if ($e->getMessage() === 'PRODUCT_NOT_FOUND') {
                    jresp(['ok' => false, 'error' => 'PRODUCT_NOT_FOUND', 'message' => 'محصول یافت نشد؛ ممکن است حذف شده باشد'], 404);
                }
                log_action('ADMIN_PRODUCT_SAVE_FAIL', ['error' => $e->getMessage(), 'product_id' => $id]);
                jresp(['ok' => false, 'error' => 'SAVE_FAILED', 'message' => APP_DEBUG ? $e->getMessage() : 'خطای ذخیره‌سازی'], 500);
            }
            break;

        case 'admin_product_rename':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            csrf_check();
            if (!rate_limit('admin_write', 30, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $from = req_str('from');
            $to   = req_str('to');

            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $from)) {
                jresp(['ok' => false, 'error' => 'ID_INVALID'], 400);
            }
            if (!preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,62}[a-z0-9])?$/i', $to)) {
                jresp(['ok' => false, 'error' => 'ID_INVALID', 'message' => 'شناسه باید با حرف یا رقم شروع و تمام شود و فقط شامل a-z 0-9 - _ باشد'], 400);
            }
            if ($from === $to) {
                jresp(['ok' => true, 'id' => $to, 'message' => 'شناسه تغییری نکرد']);
            }

            try {
                $probe = $pdo->prepare('SELECT 1 FROM velora_order_items WHERE product_id = ? LIMIT 1');
                $probe->execute([$from]);
                if ($probe->fetchColumn() !== false) {
                    jresp(['ok' => false, 'error' => 'ID_IN_HISTORY', 'message' => 'این شناسه در سفارش‌های ثبت‌شده استفاده شده و قابل تغییر نیست — محصول جدید بسازید و قبلی را غیرفعال کنید'], 409);
                }
            } catch (Throwable $e) {
                log_action('ADMIN_RENAME_PROBE_FAIL', ['product_id' => $from, 'error' => $e->getMessage()]);
                jresp(['ok' => false, 'error' => 'HISTORY_UNAVAILABLE', 'message' => 'بررسی تاریخچهٔ سفارش‌ها ممکن نشد؛ شناسه تغییر نکرد'], 503);
            }

            $renamed = false;
            $taken   = false;
            velora_catalog_transaction(function (array $data, array &$out) use ($from, $to, &$renamed, &$taken): bool {
                $found = false;
                foreach ($out['products'] as $p) {
                    $pid = (string) ($p['id'] ?? '');
                    if ($pid === $to)   { $taken = true; }
                    if ($pid === $from) { $found = true; }
                }
                if (!$found || $taken) { return true; }
                foreach ($out['products'] as &$p) {
                    if ((string) ($p['id'] ?? '') === $from) { $p['id'] = $to; $renamed = true; break; }
                }
                unset($p);
                return true;
            });

            if ($taken) {
                jresp(['ok' => false, 'error' => 'ID_TAKEN', 'message' => 'شناسهٔ جدید قبلاً به محصول دیگری اختصاص دارد'], 409);
            }
            if (!$renamed) {
                jresp(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'محصول با این شناسه پیدا نشد'], 404);
            }

            log_action('ADMIN_PRODUCT_RENAME', ['from' => $from, 'to' => $to]);
            jresp(['ok' => true, 'id' => $to, 'message' => 'شناسهٔ محصول تغییر کرد']);
            break;

        case 'admin_product_image_delete':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            csrf_check();
            if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $productId = req_str('product_id');
            $filename  = req_str('filename');
            if ($productId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $productId)) {
                jresp(['ok' => false, 'error' => 'ID_INVALID'], 400);
            }
            if (!is_safe_upload_filename($filename)) {
                jresp(['ok' => false, 'error' => 'FILENAME_INVALID', 'message' => 'فقط عکس‌های آپلودشدهٔ همین سایت قابل حذف هستند'], 400);
            }

            $entryGone = false;
            $shared    = false;
            velora_catalog_transaction(function (array $data, array &$out) use ($productId, $filename, &$entryGone, &$shared): bool {
                foreach ($out['products'] as &$p) {
                    if ((string) ($p['id'] ?? '') !== $productId) { continue; }
                    $gal = is_array($p['gallery'] ?? null) ? $p['gallery'] : [];
                    if (in_array($filename, $gal, true)) {
                        $p['gallery'] = array_values(array_filter(
                            $gal, static fn($k): bool => $k !== $filename
                        ));
                        $entryGone = true;
                    }
                    break;
                }
                unset($p);
                foreach ($out['products'] as $p) {
                    foreach ((array) ($p['gallery'] ?? []) as $k) {
                        if ($k === $filename) { $shared = true; break 2; }
                    }
                }
                return true;
            });

            if ($shared) {
                jresp(['ok' => false, 'error' => 'IMAGE_SHARED', 'message' => 'این عکس در گالری محصول دیگری هم استفاده شده — فقط ارجاع آن حذف شد'], 409);
            }

            $fileGone = false;
            $path = product_upload_dir() . '/' . $filename;
            if (is_file($path)) { $fileGone = @unlink($path); }

            log_action('ADMIN_IMAGE_DELETE', [
                'product_id'   => $productId,
                'filename'     => $filename,
                'entry_removed'=> $entryGone ? 1 : 0,
                'file_deleted' => $fileGone ? 1 : 0,
            ]);
            jresp([
                'ok'            => true,
                'entry_removed' => $entryGone,
                'file_deleted'  => $fileGone,
                'message'       => $fileGone ? 'عکس حذف شد' : 'ارجاع عکس حذف شد',
            ]);
            break;

        case 'admin_product_delete':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            csrf_check();
            if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $id = req_str('id');
            if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) jresp(['ok' => false, 'error' => 'ID_REQUIRED'], 400);
            velora_catalog_transaction(function(array $data, array &$out) use ($id): bool {
                foreach ($out['products'] as &$p) {
                    if (($p['id'] ?? '') === $id) { $p['active'] = false; break; }
                }
                unset($p);
                return true;
            });
            log_action('ADMIN_PRODUCT_DELETE', ['product_id' => $id]);
            jresp(['ok' => true, 'message' => 'محصول غیرفعال شد']);
            break;

        case 'admin_users':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            if (!rate_limit('admin_read', 60, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $page   = max(1, req_int('page', 1));
            $limit  = 100;
            $offset = ($page - 1) * $limit;
            $st = $pdo->prepare("SELECT u.id, u.phone, u.name, u.email, u.created_at,
                COUNT(o.id) AS order_count,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS total_spent
                FROM velora_users u
                LEFT JOIN velora_orders o ON u.id = o.user_id
                GROUP BY u.id, u.phone, u.name, u.email, u.created_at
                ORDER BY u.created_at DESC
                LIMIT ? OFFSET ?");
            $st->execute([$limit, $offset]);
            jresp(['ok' => true, 'users' => $st->fetchAll()]);
            break;

        case 'admin_appointments':
            if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
            if (!rate_limit('admin_read', 60, 60, 'a' . session_id())) {
                jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
            }
            $st = $pdo->query("SELECT * FROM velora_appointments ORDER BY date DESC, time ASC LIMIT 100");
            jresp(['ok' => true, 'appointments' => $st->fetchAll()]);
            break;

        case 'admin_appointment_status':
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

        default:
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