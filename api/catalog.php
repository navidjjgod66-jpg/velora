<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/catalog.php
   لایهٔ همگام‌سازی ویترین با پنل مدیریت (عمومی، بدون ورود)
   ─────────────────────────────────────────────────────────────────────
   ویترین کاتالوگش را در js/data.js دارد؛ این endpoint فقط «لایهٔ
   مدیریت» را روی آن می‌گذارد: هر محصولی که مدیر ذخیره کرده، همان است
   که مشتری می‌بیند. اگر فایل انباره‌ای وجود نداشته باشد، پاسخ خالی
   است و ویترین دست‌نخورده می‌ماند.

   خروجی: { ok:true, updated, items:{id:{…}}, order:[id], hidden:[id],
             removed:[id], promo:{ code, pct } }
   محصولات `hidden` اصلاً در items نمی‌آیند تا هرگز به مرورگر نرسند؛
   `removed` هم شناسهٔ محصولات حذف‌شده را می‌گوید تا از data.js پاک شوند.
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

header('Vary: Origin');
$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : null;
if ($origin !== null && $origin !== '' && !ae_origin_allowed($origin)) {
    ae_err('منبع درخواست مجاز نیست.', 'origin', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    ae_err('این endpoint فقط با GET خوانده می‌شود.', 'method', 405);
}

$store = ae_catalog_store();
$items = $store['items'];
$promo = ae_settings();

$vis    = [];
$hidden = [];
foreach ($items as $id => $p) {
    $id = (string) preg_replace('/[^a-z0-9\-]/i', '', (string) $id);
    if ($id === '' || !is_array($p)) continue;
    if (!empty($p['hidden'])) { $hidden[] = $id; continue; }
    $vis[$id] = [
        'name'      => (string) ($p['name']   ?? $id),
        'sub'       => (string) ($p['sub']    ?? ''),
        'cat'       => (string) ($p['cat']    ?? ''),
        'family'    => (string) ($p['family'] ?? 'loafer'),
        'heel'      => (int)    ($p['heel']   ?? 0),
        'price'     => (int)    ($p['price']  ?? 0),
        'oldPrice'  => (int)    ($p['oldPrice'] ?? 0),
        'badge'     => (string) ($p['badge']  ?? ''),
        'isNew'     => !empty($p['isNew']),
        'stock'     => (int)    ($p['stock']  ?? 0),
        'rating'    => (float)  ($p['rating'] ?? 0),
        'reviews'   => (int)    ($p['reviews'] ?? 0),
        'img'       => (string) ($p['img']    ?? ''),
        'gallery'   => array_slice((array) ($p['gallery'] ?? []), 0, 24),
        'sw'        => array_slice((array) ($p['sw'] ?? []), 0, 12),
    ];
}

$order = array_values(array_filter((array) $store['order'], static fn($id) => isset($vis[(string) $id])));
$etag  = '"' . substr(hash('sha256', (string) $store['updated'] . '|' . count($vis) . '|' . $promo['promo_code']), 0, 24) . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache, must-revalidate');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

ae_ok([
    'updated' => (int) $store['updated'],
    'items'   => $vis,
    'order'   => $order,
    'hidden'  => $hidden,
    'removed' => array_values(array_filter(array_map('strval', (array) ($store['removed'] ?? [])))),
    'promo'   => ['code' => $promo['promo_code'], 'pct' => (int) $promo['promo_pct'], 'set' => (bool) $promo['set']],
]);