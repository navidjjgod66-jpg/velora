<?php
declare(strict_types=1);
/**
 * VELORA · Checkout & order-integrity helpers
 *
 * Extracted verbatim from api.php during the monolith split — same code, same
 * behaviour, one home per concern. Covers the checkout idempotency claim
 * machine (velora_checkout_attempts), the abandoned-order sweep, stock and
 * sold-counter restoration, the payment audit log, and the gateway-callback
 * field extractor.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/checkout.php requires config.php to be loaded first.');
}

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
