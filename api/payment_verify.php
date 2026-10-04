<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/payment_verify.php
   صفحهٔ بازگشت (Callback) از درگاه زرین‌پال
   ─────────────────────────────────────────────────────────────────────
   درگاه با ?Authority=...&Status=OK&RefID=... به این آدرس برمی‌گردد.
   اینجا Status را باور نمی‌کنیم؛ با /pg/v4/payment/verify مبلغ و تراکنش
   را از خود درگاه تأیید می‌گیریم، سفارش را ثبت و ایمیل میزبان می‌فرستیم،
   سپس کاربر را به سایت برمی‌گردانیم:
        /#/checkout?status=success&ref=AE-…
        /#/checkout?status=failed&message=…
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

$authority = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['Authority'] ?? ''));
$status    = strtolower((string) ($_GET['Status'] ?? ''));
$refId     = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['RefID'] ?? $_GET['referrer_id'] ?? ''));

$back = static function (array $q): string {
    $root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')), '/');
    if ($root === '/' || $root === '.') $root = '';
    return ($root === '' ? '/' : substr($root, 0, -strlen('/api'))) . '/#/checkout?' . http_build_query($q);
};

if ($authority === '') {
    header('Location: ' . $back(['status' => 'failed', 'message' => 'شناسهٔ تراکنش در دسترس نیست.']));
    exit;
}

$tx = ae_tx_get($authority);
if (!is_array($tx)) {
    header('Location: ' . $back(['status' => 'failed', 'message' => 'تراکنش در سرور پیدا نشد.']));
    exit;
}

/* یک‌بار مصرف: اگر قبلاً تأیید شده، همان نتیجه را برگردان */
if (($tx['status'] ?? '') === 'paid') {
    header('Location: ' . $back(['status' => 'success', 'ref' => $tx['ref'], 'amount' => $tx['total']]));
    exit;
}

$page = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<title>خانهٔ اُرِل — بررسی تراکنش</title>'
      . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a0b12;'
      . 'color:#efe7d6;font:15px/2 Tahoma,system-ui,sans-serif;text-align:center}'
      . '.box{padding:2rem 1.5rem;max-width:30rem}.seal{font-size:2rem;color:#d4af37}'
       . 'a{color:#d4af37}</style></head><body><div class="box">';

$finish = static function (string $html) use ($page): void {
    echo $page . $html . '</div></body></html>';
    exit;
};

if ($status !== 'ok' || $refId === '') {
    $tx['status'] = 'canceled';
    ae_tx_save($tx);
    $msg = 'کاربر پرداخت را در درگاه تکمیل نکرد.';
    $href = $back(['status' => 'failed', 'message' => $msg]);
    $tx['redirect'] = $href; ae_tx_save($tx);
    $finish('<div class="seal">اُ</div><h2>پرداخت انجام نشد</h2><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
          . '</p><p><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">بازگشت به خانه</a></p>');
}

$verify = ae_zarinpal_verify($authority, $refId, (int) $tx['amount_rial']);
if (!$verify['ok']) {
    $tx['status'] = 'failed';
    ae_tx_save($tx);
    $href = $back(['status' => 'failed', 'message' => $verify['message']]);
    $tx['redirect'] = $href; ae_tx_save($tx);
    $finish('<div class="seal">اُ</div><h2>تراکنش تأیید نشد</h2><p>'
          . htmlspecialchars($verify['message'], ENT_QUOTES, 'UTF-8')
          . '</p><p><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">بازگشت به خانه</a></p>');
}

/* ─── پرداخت موفق: ثبت سفارش ─────────────────────────────────────────── */
$order = [
    'ref'        => $tx['ref'],
    'date'       => date('c'),
    'total'      => (int) $tx['total'],
    'subtotal'   => (int) ($tx['subtotal'] ?? $tx['total']),
    'discount'   => (int) ($tx['discount'] ?? 0),
    'count'      => (int) ($tx['count'] ?? 0),
    'items'      => array_slice((array) ($tx['items'] ?? []), 0, 12),
    'phone'      => (string) ($tx['contact']['phone'] ?? ''),
    'email'      => (string) ($tx['contact']['email'] ?? ''),
    'name'       => (string) ($tx['contact']['name'] ?? ''),
    'address'    => (string) trim(($tx['address']['line1'] ?? '') . '، ' . ($tx['address']['city'] ?? '')),
    'zip'        => (string) ($tx['address']['zip'] ?? ''),
    'country'    => (string) ($tx['address']['country'] ?? ''),
    'pay'        => ['gateway' => 'zarinpal', 'ref_id' => $refId, 'card_mask' => $verify['card_mask']],
    'status'     => 'paid',
];
ae_order_append($order);

/* ─── ایمیل به میزبان ──────────────────────────────────────────────────── */
$lines = [
    'سفارش تازه — ' . $order['ref'],
    'تاریخ: ' . date('Y-m-d H:i'),
    'مشتری: ' . ($order['name'] ?: '—') . ' | ' . ($order['phone'] ?: '—') . ' | ' . ($order['email'] ?: '—'),
    'آدرس: ' . ($order['address'] ?: '—') . ($order['zip'] ? ' | کد پستی: ' . $order['zip'] : '') . ($order['country'] ? ' | کشور: ' . $order['country'] : ''),
    'جمع جزء: ' . fa_money((int) $order['subtotal']),
];
if ((int) $order['discount'] > 0) $lines[] = 'تخفیف: −' . fa_money((int) $order['discount']);
$lines[] = 'پرداخت‌شده: ' . fa_money((int) $order['total']);
$lines[] = 'مرجع درگاه: ' . $refId . ($verify['card_mask'] ? ' | کارت: ' . $verify['card_mask'] : '');
$lines[] = '— اقلام —';
foreach ((array) $order['items'] as $it) {
    $lines[] = sprintf('• %s · سایز %s · %s ×%d — %s',
        $it['name'] ?? $it['id'], $it['size'] ?? '—', $it['color'] ?? '—', (int) ($it['qty'] ?? 1),
        fa_money((int) (($it['unit'] ?? 0) * ($it['qty'] ?? 1))));
}
ae_mail('سفارش ' . $order['ref'] . ' — پرداخت تأیید شد', implode("\n", $lines),
        $order['email'] !== '' ? $order['email'] : null, $order['name'] !== '' ? $order['name'] : null);

$tx['status']  = 'paid';
$tx['ref_id']  = $refId;
$tx['verified'] = time();
ae_tx_save($tx);

ae_session_start();
$_SESSION['ae_last_ref'] = $order['ref'];

$href = $back(['status' => 'success', 'ref' => $order['ref'], 'amount' => $order['total']]);
$tx['redirect'] = $href;
ae_tx_save($tx);

/* پرش نرم به سایت (با تگ noscript هم کار می‌کند) */
echo $page . '<div class="seal">اُ</div><h2>سفارش ثبت شد</h2><p>شمارهٔ پیگیری: <b>'
   . htmlspecialchars($order['ref'], ENT_QUOTES, 'UTF-8') . '</b></p>'
   . '<p>در حال بازگشت به خانه…</p>'
   . '<p><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">ورود به حساب</a></p>'
   . '<script>location.replace(' . json_encode($href) . ');</script>'
   . '</div></body></html>';
