<?php
declare(strict_types=1);

/**
 * VELORA · ZarinPal Payment Gateway
 * v2.2 Aetherion — hardened, retry-aware, circuit-broken, structured.
 *
 * Forensic patch scope:
 *   · F-1  Defensive constant check
 *   · F-3  Retry with exponential backoff + jitter
 *   · F-4  Amount mismatch machine-readable payload
 *   · F-5  Authority never logged raw
 *   · F-8  Atomic file locking for circuit breaker on shared hosting
 *   · F-9  cURL handle reuse & HTTP 500 last-attempt fix
 */

if (!defined('ZARINPAL_MERCHANT') || !defined('ZARINPAL_SANDBOX')) {
    throw new RuntimeException(
        'ZarinPal config missing — define ZARINPAL_MERCHANT / ZARINPAL_SANDBOX in config.php'
    );
}

define('ZARINPAL_API_BASE', ZARINPAL_SANDBOX
    ? 'https://sandbox.zarinpal.com/pg/v4/payment'
    : 'https://payment.zarinpal.com/pg/v4/payment');

define('ZARINPAL_START_BASE', ZARINPAL_SANDBOX
    ? 'https://sandbox.zarinpal.com/pg/StartPay/'
    : 'https://www.zarinpal.com/pg/StartPay/');

const ZP_BREAKER_FILE   = __DIR__ . '/storage/cache/zp.breaker';
const ZP_BREAKER_THRESH = 5;
const ZP_BREAKER_COOLDOWN = 300;

function zarinpal_error_message(int $code): string {
    return match ($code) {
        100  => 'عملیات با موفقیت انجام شد',
        101  => 'تراکنش قبلاً تأیید شده است',
        -9   => 'خطای اعتبارسنجی ورودی',
        -10  => 'مرچنت آی‌دی یا آی‌پی نامعتبر است',
        -11  => 'درخواست مورد نظر یافت نشد',
        -12  => 'امکان ویرایش درخواست وجود ندارد',
        -15  => 'مرچنت غیرفعال است',
        -16  => 'سطح تأیید مرچنت پایین‌تر از حد مجاز است',
        -17  => 'محدودیت پذیرنده در سطح آبی',
        -30  => 'مرچنت امکان دسترسی به این سرویس را ندارد',
        -31  => 'حساب کاربری مورد نظر مسدود است',
        -32  => 'مبلغ با مبلغ تراکنش همخوانی ندارد',
        -33  => 'مبلغ درخواست از حد مجاز بیشتر است',
        -34  => 'محدودیت تراکنش در سطح شاپرک',
        -40  => 'پارامترهای اضافی نامعتبر است',
        -50  => 'مبلغ پرداخت‌شده با مبلغ درخواست مغایرت دارد',
        -51  => 'شاپرک پرداخت را تأیید نکرده است',
        -52  => 'خطای غیرمنتظره در سمت شاپرک',
        -53  => 'پرداخت یافت نشد',
        -54  => 'درخواست مورد نظر آرشیو شده است',
        -60  => 'امکان درخواست تا ۳۰ دقیقه پس از پرداخت وجود ندارد',
        0    => 'خطای ناشناخته درگاه پرداخت',
        default => "خطای نامشخص زرین‌پال (کد: $code)",
    };
}

function zarinpal_breaker_state(): array {
    $f = ZP_BREAKER_FILE;
    if (!is_readable($f)) return ['fails' => 0, 'open_until' => 0];
    
    $fp = fopen($f, 'r');
    if (!$fp) return ['fails' => 0, 'open_until' => 0];
    
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    if ($raw === false || $raw === '') return ['fails' => 0, 'open_until' => 0];
    $d = json_decode($raw, true);
    return is_array($d) && isset($d['fails'], $d['open_until'])
        ? $d
        : ['fails' => 0, 'open_until' => 0];
}

function zarinpal_breaker_is_open(): bool {
    $s = zarinpal_breaker_state();
    return $s['open_until'] > time();
}

function zarinpal_breaker_record(bool $ok): void {
    $dir = dirname(ZP_BREAKER_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $fp = fopen(ZP_BREAKER_FILE, 'c+');
    if (!$fp) return;

    flock($fp, LOCK_EX);
    
    $raw = stream_get_contents($fp);
    $s = ['fails' => 0, 'open_until' => 0];
    if ($raw !== false && $raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d) && isset($d['fails'], $d['open_until'])) {
            $s = $d;
        }
    }

    if ($ok) {
        $s = ['fails' => 0, 'open_until' => 0];
    } else {
        $s['fails'] = ($s['fails'] ?? 0) + 1;
        if ($s['fails'] >= ZP_BREAKER_THRESH) {
            $s['open_until'] = time() + ZP_BREAKER_COOLDOWN;
            $s['fails']      = 0;
        }
    }
    
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function zarinpal_redact(string $authority): string {
    if ($authority === '') return '';
    /* The head is for human recognition only — "was it the same Authority as
       last time" — so a truncated prefix, and a hash for everything else.
       log_safe() on the head is load-bearing, not decoration: $authority arrives
       from the callback query string, and the previous version put its first
       eight raw bytes into two error_log() lines. Eight bytes is enough for a
       CRLF, which is enough to forge a log line that reads like a successful
       verification sitting next to a real failure. */
    $head = substr(log_safe($authority, 16), 0, 8);
    return $head . '.' . substr(hash('sha256', $authority), 0, 8);
}

function zarinpal_http(string $path, array $payload, int $timeout = 12, int $maxAttempts = 3): array {
    if (zarinpal_breaker_is_open()) {
        if (function_exists('log_action')) {
            log_action('ZP_BREAKER_OPEN', ['path' => $path]);
        }
        return ['ok' => false, 'error' => 'GATEWAY_UNAVAILABLE', 'detail' => 'circuit-open'];
    }

    $url  = ZARINPAL_API_BASE . $path;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return ['ok' => false, 'error' => 'JSON_ENCODE_FAIL'];
    }

    $traceId  = bin2hex(random_bytes(8));
    $lastErr  = '';
    $lastHttp = 0;
    $lastRaw  = '';

    $ch = curl_init();
    if (!$ch) {
        return ['ok' => false, 'error' => 'CURL_INIT'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Language: fa-IR,fa;q=0.9,en;q=0.6',
            'Content-Length: ' . strlen($body),
            'User-Agent: VELORA-ZarinPal/2.2 (+https://kafshevelora.ir)',
            'X-Client-Trace: ' . $traceId,
        ],
    ]);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        curl_setopt($ch, CURLOPT_URL, $url);
        
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false || $http === 0) {
            $lastErr  = $err;
            $lastHttp = $http;
            if ($attempt < $maxAttempts) {
                usleep((int) ((250 * (2 ** ($attempt - 1))) * 1000) + random_int(0, 500_000));
                continue;
            }
            curl_close($ch);
            zarinpal_breaker_record(false);
            if (function_exists('log_action')) {
                log_action('ZP_TRANSPORT_FAIL', [
                    'path'     => $path,
                    'trace'    => $traceId,
                    'attempts' => $attempt,
                    'http'     => $http,
                    'err'      => $err,
                ]);
            }
            return ['ok' => false, 'error' => 'CURL_FAIL', 'detail' => $err, 'attempts' => $attempt];
        }

        $lastRaw = $raw;
        $lastHttp = $http;

        if ($http >= 500) {
            if ($attempt < $maxAttempts) {
                usleep((int) ((500 * (2 ** ($attempt - 1))) * 1000) + random_int(0, 500_000));
                continue;
            }
            curl_close($ch);
            zarinpal_breaker_record(false);
            if (function_exists('log_action')) {
                log_action('ZP_HTTP_ERROR', [
                    'path'     => $path,
                    'trace'    => $traceId,
                    'attempts' => $attempt,
                    'http'     => $http,
                    'body'     => substr((string) $raw, 0, 200),
                ]);
            }
            return ['ok' => false, 'error' => 'HTTP_ERROR', 'http' => $http, 'attempts' => $attempt, 'raw' => substr((string) $raw, 0, 200)];
        }

        break;
    }

    curl_close($ch);

    $data = json_decode($lastRaw, true);
    if (!is_array($data)) {
        zarinpal_breaker_record(false);
        return ['ok' => false, 'error' => 'INVALID_JSON', 'raw' => substr($lastRaw, 0, 200)];
    }

    zarinpal_breaker_record(true);
    return ['ok' => true, 'http' => $lastHttp, 'data' => $data, 'attempts' => $attempt, 'trace' => $traceId];
}

function zarinpal_request(
    int    $amount_toman,
    string $orderId,
    string $description = '',
    string $mobile = '',
    string $email = ''
): array {
    if (ZARINPAL_MERCHANT === '') {
        return ['ok' => false, 'error' => 'MERCHANT_MISSING'];
    }
    if ($amount_toman < 1_000) {
        return ['ok' => false, 'error' => 'AMOUNT_TOO_LOW', 'min' => 1_000];
    }
    if ($orderId === '' || strlen($orderId) > 32) {
        return ['ok' => false, 'error' => 'ORDER_ID_REQUIRED'];
    }

    $amount_rial = $amount_toman * 10;

    $metadata = [];
    if ($mobile !== '' && preg_match('/^09\d{9}$/', $mobile)) {
        $metadata['mobile'] = $mobile;
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $metadata['email'] = $email;
    }

    $payload = [
        'merchant_id'  => ZARINPAL_MERCHANT,
        'amount'       => $amount_rial,
        'callback_url' => APP_URL . '/api.php?action=payment_callback&gateway=zarinpal&order_id=' . urlencode($orderId),
        'description'  => $description !== '' ? mb_substr($description, 0, 255) : 'خرید از مزون ولورا',
    ];
    if ($metadata) $payload['metadata'] = $metadata;

    $r = zarinpal_http('/request.json', $payload);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'], 'detail' => $r['detail'] ?? null];
    }

    $d    = $r['data'];
    $code = (int) ($d['data']['code'] ?? 0);

    if ($code !== 100) {
        $msg = $d['errors']['message'] ?? ($d['data']['message'] ?? zarinpal_error_message($code));
        if (function_exists('log_action')) {
            log_action('ZP_REQUEST_REJECTED', [
                'order_id' => $orderId,
                'code'     => $code,
                'trace'    => $r['trace'] ?? null,
            ]);
        }
        return [
            'ok'      => false,
            'error'   => 'ZARINPAL_ERROR',
            'code'    => $code,
            'message' => $msg,
        ];
    }

    $authority = (string) ($d['data']['authority'] ?? '');
    if ($authority === '' || strlen($authority) > 64) {
        return ['ok' => false, 'error' => 'NO_AUTHORITY'];
    }

    return [
        'ok'          => true,
        'authority'   => $authority,
        'pay_url'     => ZARINPAL_START_BASE . $authority,
        'amount_rial' => $amount_rial,
    ];
}

function zarinpal_verify(string $authority, int $amount_toman): array {
    if ($authority === '' || strlen($authority) > 64) {
        return ['ok' => false, 'error' => 'AUTHORITY_REQUIRED'];
    }
    if (ZARINPAL_MERCHANT === '') {
        return ['ok' => false, 'error' => 'MERCHANT_MISSING'];
    }

    $amount_rial = $amount_toman * 10;

    $r = zarinpal_http('/verify.json', [
        'merchant_id' => ZARINPAL_MERCHANT,
        'authority'   => $authority,
        'amount'      => $amount_rial,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'], 'detail' => $r['detail'] ?? null];
    }

    $d    = $r['data'];
    $code = (int) ($d['data']['code'] ?? 0);

    if ($code !== 100 && $code !== 101) {
        $msg = $d['errors']['message'] ?? ($d['data']['message'] ?? zarinpal_error_message($code));
        if (function_exists('log_action')) {
            log_action('ZP_VERIFY_REJECTED', [
                'authority' => zarinpal_redact($authority),
                'code'      => $code,
                'trace'     => $r['trace'] ?? null,
            ]);
        }
        return [
            'ok'      => false,
            'error'   => 'PAYMENT_FAILED',
            'code'    => $code,
            'message' => $msg,
        ];
    }

    $paid = (int) ($d['data']['amount'] ?? 0);
    if ($paid !== $amount_rial) {
        error_log(sprintf(
            '[ZARINPAL] AMOUNT MISMATCH authority=%s expected=%d paid=%d',
            zarinpal_redact($authority), $amount_rial, $paid
        ));
        if (function_exists('log_action')) {
            log_action('ZP_AMOUNT_MISMATCH', [
                'authority' => zarinpal_redact($authority),
                'expected'  => $amount_rial,
                'paid'      => $paid,
            ]);
        }
        return [
            'ok'       => false,
            'error'    => 'AMOUNT_MISMATCH',
            'expected' => $amount_rial,
            'paid'     => $paid,
        ];
    }

    $panRaw = (string) ($d['data']['card_pan'] ?? '');
    $pan    = $panRaw !== '' ? zarinpal_mask_pan($panRaw) : '';

    return [
        'ok'        => true,
        'ref_id'    => (string) ($d['data']['ref_id'] ?? $authority),
        'authority' => $authority,
        'amount'    => $paid,
        'card_pan'  => $pan,
    ];
}

/**
 * Did the gateway actually give a verdict?
 *
 * A verification that comes back `ok => false` is not one thing. It is either
 * the gateway saying "this customer did not pay" — a decision, and the only
 * kind that may release stock and mark an order failed — or it being
 * unreachable, timing out, rate-limiting us, or us being misconfigured, which
 * is silence. Silence is not a no.
 *
 * This distinction was absent, and its absence destroyed paid orders. Every
 * failure fell through one `else` in api.php that called
 * restore_stock_for_order() and redirected to ?payment=failed. So:
 *
 *   · Five transport failures anywhere on the site open the circuit breaker for
 *     300 seconds (zarinpal_breaker). A shopper who completed payment inside
 *     that window got their callback, the breaker refused the verify, and their
 *     order was marked failed and their stock returned — while their money was
 *     already debited. They were shown a failure page for a purchase they had
 *     paid for.
 *   · A single 12-second timeout on the verify leg did the same, and
 *     zarinpal_http retries three times, so each verification had a ~36-second
 *     window in which a transient fault was indistinguishable from a decline.
 *
 * The rule below is deliberately conservative: only the two codes that mean
 * "the gateway looked at this transaction and rejected it" count as a
 * decision. Everything else — including codes this function has never seen, and
 * anything added to zarinpal_http later — is indeterminate, because the cost
 * of each mistake is asymmetric. Failing a paid order costs a customer their
 * money and the maison a sale; holding an order pending for a few minutes
 * costs nothing but an entry in a reconciliation list.
 *
 * AMOUNT_MISMATCH is on the decided side deliberately, and it is worth saying
 * why, because it is the one that looks like silence. The gateway answered,
 * it answered 100/101, and the amount it confirmed did not match what we
 * asked for. That is a real, final, unambiguous answer about the transaction.
 * Treating an underpayment as "unknown" would let an order sit pending
 * forever over a discrepancy we have already been told about.
 */
function zarinpal_verdict_is_final(array $result): bool {
    return isset($result['error'])
        && in_array($result['error'], ['PAYMENT_FAILED', 'AMOUNT_MISMATCH'], true);
}

/**
 * Ask the gateway what it thinks happened to an authority we could not verify.
 *
 * This existed and had no caller, which is the whole story: the code to
 * reconcile an indeterminate payment was written and then never wired to the
 * one place that needed it. It is what turns `unknown` from a state a human has
 * to notice in a log into one the system resolves on its own.
 *
 * Returns:
 *   'paid'      — the gateway confirms the money arrived. Promote the order.
 *   'unpaid'    — the gateway confirms it did not. Now the order may be failed.
 *   'unknown'   — still silence. Leave the order pending and try again later.
 */
function zarinpal_reconcile(string $authority, int $amount_toman): array {
    $q = zarinpal_inquiry($authority);
    if (!$q['ok']) {
        return ['state' => 'unknown', 'error' => $q['error'] ?? 'UNKNOWN'];
    }
    /* ZarinPal's inquiry reports a settled, verified payment as code 100 (or
       101 for a second, already-verified transaction). Both mean the money is
       in. Anything else means it is not. */
    if (in_array((int) $q['code'], [100, 101], true)) {
        if ($amount_toman > 0 && (int) $q['amount'] !== $amount_toman * 10) {
            /* Paid, but not the amount we asked for. That is a real answer and
               it is not ours to resolve automatically — it needs a human, and
               it must not be quietly promoted to a correct-looking paid order. */
            return ['state' => 'unknown', 'error' => 'AMOUNT_MISMATCH', 'paid' => (int) $q['amount']];
        }
        return ['state' => 'paid', 'ref_id' => $authority, 'amount' => (int) $q['amount']];
    }
    return ['state' => 'unpaid', 'code' => (int) $q['code'], 'status' => $q['status'] ?? 'unknown'];
}

function zarinpal_inquiry(string $authority): array {
    if ($authority === '' || strlen($authority) > 64) {
        return ['ok' => false, 'error' => 'AUTHORITY_REQUIRED'];
    }
    if (ZARINPAL_MERCHANT === '') {
        return ['ok' => false, 'error' => 'MERCHANT_MISSING'];
    }

    $r = zarinpal_http('/inquiry.json', [
        'merchant_id' => ZARINPAL_MERCHANT,
        'authority'   => $authority,
    ], 15);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'], 'detail' => $r['detail'] ?? null];
    }
    $d = $r['data'];
    return [
        'ok'     => true,
        'code'   => (int) ($d['data']['code'] ?? 0),
        'status' => (string) ($d['data']['status'] ?? 'unknown'),
        'amount' => (int) ($d['data']['amount'] ?? 0),
    ];
}

function zarinpal_mask_pan(string $pan): string {
    $digits = preg_replace('/\D/', '', $pan) ?? '';
    if (strlen($digits) < 10) return '';
    return substr($digits, 0, 6) . '****' . substr($digits, -4);
}