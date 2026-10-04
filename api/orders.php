<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/orders.php
   سفارش‌های کاربر (سمت سرور) + ثبت سفارش دستی برای حالت بدون درگاه
   ─────────────────────────────────────────────────────────────────────
   ورودی : { "action": "list" | "create", … }
   list    → فقط با نشست تأییدشده؛ سفارش‌های همان شمارهٔ موبایل
   create  → سفارش «رزرو/بدون پرداخت» با مبلغ سمت سرور
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();
ae_gc();

$in     = ae_input();
$action = (string) ($in['action'] ?? 'list');
$phone  = ae_auth_phone();

if ($action === 'list') {
    if ($phone === null) ae_err('برای دیدن سفارش‌ها وارد حساب شوید.', 'unauthorized', 401);
    $list = ae_read_json(ae_orders_file());
    if (!is_array($list)) $list = [];
    $mine = [];
    foreach ($list as $o) {
        if (($o['phone'] ?? '') === $phone) {
            $mine[] = [
                'ref'    => (string) ($o['ref'] ?? ''),
                'date'   => (string) ($o['date'] ?? ''),
                'total'  => (int) ($o['total'] ?? 0),
                'count'  => (int) ($o['count'] ?? 0),
                'status' => (string) ($o['status'] ?? 'paid'),
                'items'  => array_slice((array) ($o['items'] ?? []), 0, 12),
            ];
        }
    }
    usort($mine, static function ($a, $b) { return strcmp($b['date'], $a['date']); });
    ae_ok(['orders' => array_slice($mine, 0, 30)]);
}

if ($action === 'create') {
    $items  = ae_normalize_items($in['items'] ?? null);
    if (!$items) ae_err('سبد خرید خالی است.', 'empty_cart', 422);
    $priced = ae_price_cart($items, isset($in['promo']) ? ae_clean($in['promo'], 32) : null);
    $contact = is_array($in['contact'] ?? null) ? $in['contact'] : [];
    $address = is_array($in['address'] ?? null) ? $in['address'] : [];
    $ref = 'AE-' . strtoupper(date('ymd')) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $order = [
        'ref'      => $ref,
        'date'     => date('c'),
        'total'    => (int) $priced['total'],
        'subtotal' => (int) $priced['subtotal'],
        'discount' => (int) $priced['discount'],
        'count'    => (int) array_sum(array_column($priced['items'], 'qty')),
        'items'    => $priced['items'],
        'phone'    => $phone ?? (ae_norm_mobile((string) ($contact['phone'] ?? '')) ?? ''),
        'email'    => filter_var(ae_clean($contact['email'] ?? '', 120), FILTER_VALIDATE_EMAIL) ?: '',
        'name'     => ae_clean($contact['name'] ?? '', 80),
        'address'  => trim(ae_clean($address['line1'] ?? $address['addr'] ?? '', 240) . '، ' . ae_clean($address['city'] ?? '', 60)),
        'zip'      => ae_clean($address['zip'] ?? '', 20),
        'country'  => ae_clean($address['country'] ?? '', 8),
        'pay'      => ['gateway' => 'reservation'],
        'status'   => 'reserved',
    ];
    if (!ae_order_append($order)) ae_err('ثبت سفارش در سرور ممکن نشد.', 'storage', 500);
    ae_mail('رزرو تازه — ' . $ref, implode("\n", [
        'سفارش رزرو (بدون پرداخت آنلاین) — ' . $ref,
        'مشتری: ' . ($order['name'] ?: '—') . ' | ' . ($order['phone'] ?: '—') . ' | ' . ($order['email'] ?: '—'),
        'آدرس: ' . $order['address'],
        'مبلغ برآوردی: ' . fa_money((int) $order['total']),
    ]), $order['email'] !== '' ? $order['email'] : null, $order['name'] !== '' ? $order['name'] : null);
    ae_ok(['ref' => $ref, 'total' => (int) $order['total'], 'status' => 'reserved']);
}

ae_err('اکشن نامعتبر است.', 'bad_action', 422);
