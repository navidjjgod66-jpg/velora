<?php
declare(strict_types=1);
/**
 * VELORA · API handlers — catalog
 *
 * Actions handled here: products, product, reviews, submit_review
 *
 * Procedural code extracted verbatim from api.php's action switch.
 * It runs in the request's global scope via velora_api_handler()
 * (includes/api-handlers.php), so $pdo, $_SESSION, req_*(), jresp()
 * and log_action() behave exactly as they did inside the switch.
 * Each case-terminating `break;` became `return;`; jresp() exits on
 * its own, so the return only matters where the original break was.
 */

/* ---- products ---- */
if ($action === 'products') {
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
    return;
}

/* ---- product ---- */
if ($action === 'product') {
if (!rate_limit('browse_product', 120, 60)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$id = req_str('id');
if ($id === '' || strlen($id) > 64) jresp(['ok' => false, 'error' => 'ID_REQUIRED'], 400);
$row = velora_catalog_product($id);
if ($row === null || empty($row['active'])) jresp(['ok' => false, 'error' => 'NOT_FOUND'], 404);
jresp(['ok' => true, 'product' => velora_catalog_client_shape($row)], 200, true);
    return;
}

/* ---- reviews ---- */
if ($action === 'reviews') {
if (!rate_limit('browse_reviews', 100, 60)) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$pid = req_str('product_id');
if ($pid === '' || strlen($pid) > 64) jresp(['ok' => false, 'error' => 'PRODUCT_ID_REQUIRED'], 400);
$st = $pdo->prepare("SELECT user_name, rating, text, created_at
    FROM velora_reviews WHERE product_id=? ORDER BY created_at DESC LIMIT 10");
$st->execute([$pid]);
jresp(['ok' => true, 'reviews' => $st->fetchAll()], 200, true);
    return;
}

/* ---- submit_review ---- */
if ($action === 'submit_review') {
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
    return;
}

