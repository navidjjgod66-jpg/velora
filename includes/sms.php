<?php
declare(strict_types=1);
/**
 * VELORA · SMS gateway · MeliPayamak
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers meli_send_otp(), the panel-generated OTP sender.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/sms.php requires config.php to be loaded first.');
}

/* ═══════════════════════════════════════════════════════════════════════════
OTP SENDER · MeliPayamak
═══════════════════════════════════════════════════════════════════════════

   MeliPayamak's /api/send/otp/{token} endpoint GENERATES the OTP itself and
returns it in the response `code` field. We POST only the phone number — no
text, no sender — because the panel owns both the code and the message
template. Per the vendor documentation:

    «محتوای code را در سامانه خود جهت ارزیابی کاربر ذخیره کنید»

So the value we persist is the one the panel returned, never one we invented.
Response shape on success:
    {"code":"3741437414","status":"..."}
On failure, `code` is empty/absent and `status` carries a Persian message.
*/
function meli_send_otp(string $phone): array {
    if (MELI_API === '') {
        error_log('[MELI] API URL not configured');
        return ['ok' => false, 'error' => 'MELI_NOT_CONFIGURED'];
    }

    $payload = json_encode(['to' => $phone], JSON_UNESCAPED_UNICODE);
    if ($payload === false) return ['ok' => false, 'error' => 'JSON_ENCODE'];

    $ch = curl_init(MELI_API);
    if (!$ch) return ['ok' => false, 'error' => 'CURL_INIT'];

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'User-Agent: VELORA-API/9.5',
        ],
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        error_log('[MELI] cURL: ' . $err);
        return ['ok' => false, 'error' => 'CURL_FAIL', 'detail' => $err];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'INVALID_RESPONSE', 'raw' => substr($raw, 0, 200)];
    }

    $otp    = isset($data['code']) && is_string($data['code']) ? trim($data['code']) : '';
    $status = isset($data['status']) && is_string($data['status']) ? trim($data['status']) : '';

    if ($otp === '' || preg_match('/^\d{4,10}$/', $otp) !== 1) {
        return [
            'ok'      => false,
            'error'   => 'GATEWAY_REJECTED',
            'http'    => $http,
            'status'  => $status,
            'gateway' => $data,
        ];
    }

    return [
        'ok'      => true,
        'otp'     => $otp,
        'http'    => $http,
        'status'  => $status,
        'gateway' => $data,
    ];
}
