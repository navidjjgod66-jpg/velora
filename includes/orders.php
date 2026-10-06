<?php
declare(strict_types=1);
/**
 * VELORA · Voucher & order-number helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers the voucher map shared with the browser, validate_voucher(), secure_token() and generate_order_id().
 */
if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/orders.php requires config.php to be loaded first.');
}

/**
 * The voucher table — the single source of truth for both validation and the
 * browser.
 *
 * This map used to live inline inside validate_voucher(), which meant the
 * browser could not know what a code was worth, so data.js invented one:
 * `VELORA10`, a 10% code that appears nowhere on the server. A customer was
 * shown a 10% discount in the bag and in the checkout summary, and
 * validate_voucher() returned INVALID_CODE and charged the full price. The
 * page also advertised WELCOME10 in the announcement bar, so the shop named two
 * different codes.
 *
 * index.php now publishes this exact map as window.VELORA_VOUCHERS, which is
 * what PROMO in data.js is built from. A new code is one edit here.
 *
 * `cap` matters as much as `value`: the client used to apply an uncapped
 * percentage, so on a basket above the cap it would show a larger discount
 * than the server charges. The cap is published for exactly that reason.
 *
 * Every code is a percentage. The 'ship' type and the SHIP0 code went away with
 * the shipping charge: a voucher whose only effect was to waive 680,000 that
 * nobody is charged any more is a code that takes money at the till and gives
 * nothing back, and it would still have validated.
 */
function velora_voucher_map(): array {
    return [
        'VEL10'     => ['type' => 'pct', 'value' => 10, 'cap' => 5_000_000,  'min' => 0],
        'WELCOME10' => ['type' => 'pct', 'value' => 10, 'cap' => 3_000_000,  'min' => 0, 'first_only' => true],
        'VIP20'     => ['type' => 'pct', 'value' => 20, 'cap' => 12_000_000, 'min' => 80_000_000],
    ];
}

function validate_voucher(string $code, int $subtotal, ?int $userId = null): array {
    $code = strtoupper(trim($code));
    $map = velora_voucher_map();
    if (!isset($map[$code])) return ['valid' => false, 'error' => 'INVALID_CODE'];
    $v = $map[$code];
    if ($subtotal < $v['min']) return ['valid' => false, 'error' => 'MIN_NOT_MET'];
    if (!empty($v['first_only']) && $userId) {
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return ['valid' => false, 'error' => 'DB_UNAVAILABLE'];
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM velora_orders
            WHERE user_id = ? AND payment_status = 'paid'");
        $st->execute([$userId]);
        if ((int) $st->fetchColumn() > 0) return ['valid' => false, 'error' => 'FIRST_ONLY'];
    }
    $discount = (int) min($subtotal * $v['value'] / 100, $v['cap']);
    return ['valid' => true, 'code' => $code, 'discount' => $discount, 'label' => $v['value'] . '٪ تخفیف'];
}

/* Cryptographically-strong hex token.
 *
 * Nothing calls this yet: order secrets, when they exist, are carried by the
 * gateway's own Authority value and by velora_orders' columns, so this helper
 * is a leftover from the pre-ZarinPal callback scheme. It is kept rather than
 * deleted because it is a one-liner with no dependencies and it is the obvious
 * building block for a "share my order" link, which is the next feature anyone
 * will ask for. Marked so its absence is not rediscovered as a bug.
 *
 * generate_order_id() below IS used, by the checkout handlers.
 */
// TODO: verify dead code — not called anywhere in this repository.
function secure_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function generate_order_id(): string { return 'VL-' . strtoupper(bin2hex(random_bytes(6))); }
