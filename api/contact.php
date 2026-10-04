<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/contact.php
   فرم «تماس با میزبان» → ایمیل سمت سرور با mail() هاست اشتراکی
   ─────────────────────────────────────────────────────────────────────
   ورودی : { name, email, phone, topic, message }
   خروجی : { ok:true } یا پیام خطا
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();

$in = ae_input();
$name    = ae_clean($in['name'] ?? '', 80);
$email   = filter_var(ae_clean($in['email'] ?? '', 120), FILTER_VALIDATE_EMAIL) ?: '';
$phone   = ae_norm_mobile((string) ($in['phone'] ?? ''));
$topic   = ae_clean($in['topic'] ?? 'کنسیژ', 60);
$message = ae_clean($in['message'] ?? '', 4000);

if ($name === '' || mb_strlen($message, 'UTF-8') < 5) {
    ae_err('نام و پیام را کامل کنید.', 'bad_input', 422);
}
if (!ae_throttle('contact', 5, 3600)) {
    ae_err('درخواست‌های زیاد — کمی بعد دوباره بنویسید.', 'rate_limited', 429);
}

$text = implode("\n", [
    'پیام از سایت — ' . $topic,
    'نام: ' . $name,
    'ایمیل: ' . ($email ?: '—'),
    'موبایل: ' . ($phone ?: '—'),
    'زمان: ' . date('Y-m-d H:i'),
    'IP: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    '— پیام —',
    $message,
]);

if (!ae_config()['concierge_email']) {
    /* اگر ایمیل در config تنظیم نشده، لاگ می‌شود تا پیام گم نشود */
    ae_log('contact (no email configured): ' . str_replace("\n", ' | ', $text));
    ae_ok(['sent' => 'logged']);
}
$sent = ae_mail('پیام تازه از سایت — ' . $name, $text, $email ?: null, $name);
if (!$sent) ae_err('ارسال پیام ممکن نشد — از واتساپ یا ایمیل مستقیم استفاده کنید.', 'mail_failed', 502);
ae_ok();
