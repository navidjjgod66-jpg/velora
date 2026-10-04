<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/otp_send.php
   درخواست ارسال رمز یکبار مصرف
   ─────────────────────────────────────────────────────────────────────
   ورودی : { "to": "09123456789" }
   رفتار : فقط «to» را به سرویس ملی پیامک (OTP) می‌فرستد؛ خودمان هیچ رمزی
           نمی‌سازیم. پاسخ سرویس (فیلد code) در سرور هش و ذخیره می‌شود تا
           بعداً برای ارزیابی کاربر استفاده شود.
   خروجی : { ok:true, ttl:180, resend:60 }  یا  { ok:false, error, message }
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();
ae_gc();

$in     = ae_input();
$mobile = ae_norm_mobile((string) ($in['to'] ?? $in['phone'] ?? ''));
if ($mobile === null) ae_err('شمارهٔ موبایل معتبر نیست.', 'bad_phone', 422);

$res = ae_otp_issue($mobile);
if (!$res['ok']) {
    $status = in_array($res['error'], ['not_configured', 'sms_unreachable', 'gateway_rejected'], true) ? 502 : 429;
    if (in_array($res['error'], ['too_fast', 'rate_limited', 'daily_cap'], true)) $status = 429;
    ae_err($res['message'], $res['error'], $status, isset($res['retry_in']) ? ['retry_in' => $res['retry_in']] : []);
}

ae_session_start();
$_SESSION['ae_pending_phone'] = $mobile;

ae_ok(['ttl' => $res['ttl'], 'resend' => $res['resend'], 'masked' => substr($mobile, 0, 4) . '***' . substr($mobile, -4)]);
