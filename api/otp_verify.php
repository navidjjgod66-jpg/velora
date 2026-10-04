<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/otp_verify.php
   ارزیابی رمز یکبار مصرف (مقایسه با پاسخی که ملی پیامک برگرداند)
   ─────────────────────────────────────────────────────────────────────
   ورودی : { "to": "09123456789", "code": "3741437414" }
   خروجی : { ok:true }            → نشست تأیید می‌شود (کوکی HttpOnly)
             { ok:false, message } → کد نادرست / منقضی / سقف تلاش
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();

$in     = ae_input();
$mobile = ae_norm_mobile((string) ($in['to'] ?? $in['phone'] ?? ''));
if ($mobile === null) ae_err('شمارهٔ موبایل معتبر نیست.', 'bad_phone', 422);

$code = (string) preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
if ($code === '') ae_err('کد را وارد کنید.', 'no_code', 422);

$res = ae_otp_verify($mobile, $code);
if (!$res['ok']) {
    $status = $res['error'] === 'expired' || $res['error'] === 'no_code' ? 410 : 401;
    if ($res['error'] === 'too_many') $status = 429;
    ae_err($res['message'], $res['error'], $status);
}

ae_session_start();
session_regenerate_id(true);
$_SESSION['ae_phone']  = $mobile;
$_SESSION['ae_authat'] = time();
unset($_SESSION['ae_pending_phone']);

ae_ok(['phone' => $mobile]);
