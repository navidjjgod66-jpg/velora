<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/payment_request.php
   ساخت تراکنش درگاه زرین‌پال و دریافت URL پرداخت
   ─────────────────────────────────────────────────────────────────────
   ورودی : { items:[{id,size,color,qty}], promo:"VELORA10",
             contact:{name,email,phone}, address:{...} }
   رفتار : مبلغ را از کاتالوگ سمت سرور محاسبه می‌کند (نه از مرورگر)،
           با زرین‌پال /pg/v4/payment/request مذاکره کرده و Authority را
           به‌همراه خلاصهٔ سفارش در سرور ذخیره می‌کند.
   خروجی : { ok:true, url:"https://www.zarinpal.com/pg/...", authority, total }
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();
ae_gc();

$in    = ae_input();
$items = ae_normalize_items($in['items'] ?? null);
if (!$items) ae_err('سبد خرید خالی است.', 'empty_cart', 422);

$priced = ae_price_cart($items, isset($in['promo']) ? ae_clean($in['promo'], 32) : null);
$total  = (int) $priced['total'];
if ($total <= 0) ae_err('مبلغ سفارش نامعتبر است.', 'bad_amount', 422);

$cfg      = ae_config();
$toRial   = max(1, (int) ($cfg['zarinpal']['toman_to_rial'] ?? 10));
$amount   = $total * $toRial;                      /* زرین‌پال ریال می‌خواهد */

$contact = is_array($in['contact'] ?? null) ? $in['contact'] : [];
$name    = ae_clean($contact['name'] ?? '', 80);
$email   = filter_var(ae_clean($contact['email'] ?? '', 120), FILTER_VALIDATE_EMAIL) ?: '';
$phone   = ae_norm_mobile((string) ($contact['phone'] ?? '')) ?? (ae_auth_phone() ?? '');

$address = is_array($in['address'] ?? null) ? $in['address'] : [];
$addr    = ae_clean($address['line1'] ?? $address['addr'] ?? '', 240);
$city    = ae_clean($address['city'] ?? '', 60);
$zip     = ae_clean($address['zip'] ?? '', 20);
$country = ae_clean($address['country'] ?? '', 8);

$ref = 'AE-' . strtoupper(date('ymd')) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

$zp = ae_zarinpal_request_payment(
    $amount,
    $phone,
    $email,
    sprintf('%s — %s', $cfg['zarinpal']['description'] ?? 'خانهٔ اُرِل', $ref)
);
if (!$zp['ok']) {
    $status = $zp['error'] === 'not_configured' ? 500 : 502;
    ae_err($zp['message'], $zp['error'], $status);
}

ae_session_start();
$tx = [
    'authority'  => $zp['authority'],
    'ref'        => $ref,
    'total'      => $total,
    'subtotal'   => $priced['subtotal'],
    'discount'   => $priced['discount'],
    'amount_rial'=> $amount,
    'items'      => $priced['items'],
    'count'      => array_sum(array_column($priced['items'], 'qty')),
    'contact'    => ['name' => $name, 'email' => $email, 'phone' => $phone],
    'address'    => ['line1' => $addr, 'city' => $city, 'zip' => $zip, 'country' => $country],
    'session'    => session_id(),
    'created'    => time(),
    'status'     => 'pending',
];
ae_tx_save($tx);

ae_ok([
    'url'       => $zp['url'],
    'authority' => $zp['authority'],
    'ref'       => $ref,
    'total'     => $total,
]);
