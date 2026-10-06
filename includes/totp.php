<?php
declare(strict_types=1);
/**
 * VELORA · Admin TOTP second factor — RFC 6238
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers base32 codec, AES-GCM secret envelope, code generation/verification and the provisioning URI.
 */
if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/totp.php requires config.php to be loaded first.');
}

/* VELORA_TOTP_PERIOD / _DIGITS / _WINDOW are NOT declared here. They used to
   be, guarded by `defined() or define()`, which meant the TOTP parameters had
   two owners. They are application constants and live in config.php with
   everything else, so there is one place to change them. */

function admin_totp_secret_ciphertext(): string {
    $v = env('VELORA_ADMIN_TOTP_SECRET', '');
    return is_string($v) ? trim($v) : '';
}

function admin_totp_enabled(): bool {
    return admin_totp_secret_ciphertext() !== '';
}

function velora_base32_decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[\s=-]+/', '', $b32) ?? '');
    if ($b32 === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) return '';
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function velora_base32_encode(string $bin): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $n = strlen($bits); $i + 5 <= $n; $i += 5) {
        $out .= $alphabet[bindec(substr($bits, $i, 5))];
    }
    if ($i < $n) {
        $out .= $alphabet[bindec(str_pad(substr($bits, $i), 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function admin_totp_key(): string {
    return hash_hmac('sha256', 'velora:totp:aead', velora_mac_key('totp'), true);
}

function admin_totp_decrypt(string $cipherB64): string {
    if ($cipherB64 === '' || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode($cipherB64, true);
    if ($raw === false || strlen($raw) <= 28) return '';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', admin_totp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

/**
 * Seal a base32 secret into the AES-256-GCM envelope VELORA_ADMIN_TOTP_SECRET
 * expects.
 *
 * Not called by any handler, and that is correct: there is deliberately no
 * "set up 2FA" endpoint. A feature that mints an admin credential has no
 * business being reachable over HTTP. It is a provisioning tool, run once from
 * a shell by whoever holds the server:
 *
 *   php -r 'require "config.php";
 *     $raw = random_bytes(20);
 *     echo "secret : ", velora_base32_encode($raw), PHP_EOL,
 *          "uri    : ", admin_totp_provision_uri($raw), PHP_EOL,
 *          "cipher : ", velora_admin_totp_encrypt(velora_base32_encode($raw)), PHP_EOL;'
 *
 * The same recipe is in .env.example, because a TOTP setting that cannot be
 * reached from documentation is a TOTP setting nobody turns on.
 *
 * Throws RuntimeException if ext-openssl is missing or the input is not base32,
 * both of which are operator errors that must not fail silently.
 */
function velora_admin_totp_encrypt(string $base32Secret): string {
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('ext-openssl is required for VELORA_ADMIN_TOTP_SECRET');
    }
    $raw = velora_base32_decode($base32Secret);
    if ($raw === '') {
        throw new RuntimeException('secret is not valid base32');
    }
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($raw, 'aes-256-gcm', admin_totp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false || strlen($tag) !== 16) {
        throw new RuntimeException('encryption failed');
    }
    return base64_encode($iv . $tag . $ct);
}

function admin_totp_code(string $rawSecret, int $counter): string {
    $binCounter = pack('J', $counter);
    $hmac  = hash_hmac('sha1', $binCounter, $rawSecret, true);
    $index = ord($hmac[19]) & 0x0F;
    $value = ((ord($hmac[$index]) & 0x7F) << 24)
           | ((ord($hmac[$index + 1]) & 0xFF) << 16)
           | ((ord($hmac[$index + 2]) & 0xFF) << 8)
           |  (ord($hmac[$index + 3]) & 0xFF);
    $modulo = 10 ** VELORA_TOTP_DIGITS;
    return str_pad((string) ($value % $modulo), VELORA_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

function admin_totp_verify(string $rawSecret, string $code, ?int $now = null): bool {
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== VELORA_TOTP_DIGITS) return false;
    $now   = $now ?? time();
    $step  = intdiv($now, VELORA_TOTP_PERIOD);
    $ok    = false;
    for ($d = -VELORA_TOTP_WINDOW; $d <= VELORA_TOTP_WINDOW; $d++) {
        if (hash_equals(admin_totp_code($rawSecret, $step + $d), $code)) $ok = true;
    }
    return $ok;
}

function admin_totp_secret(): string {
    static $cached = null;
    static $resolved = false;
    if ($resolved) return $cached;
    $resolved = true;
    $cached = admin_totp_decrypt(admin_totp_secret_ciphertext());
    if ($cached === '') {
        error_log('[VELORA TOTP] VELORA_ADMIN_TOTP_SECRET is set but could not be decrypted. '
            . 'Either VELORA_APP_KEY changed since it was generated, or the value is corrupt. '
            . 'Every admin login will now fail closed. Re-encrypt the secret or clear the key to disable.');
    }
    return $cached;
}

/**
 * The otpauth:// URI an authenticator app scans.
 *
 * A provisioning tool, not a runtime path: paired with
 * velora_admin_totp_encrypt() in the one-shot shell command documented above
 * and in .env.example. See that function for why there is deliberately no
 * HTTP route to this.
 */
function admin_totp_provision_uri(string $rawSecret, string $account = 'admin'): string {
    $label = rawurlencode('VELORA:' . $account);
    return 'otpauth://totp/' . $label
        . '?secret=' . velora_base32_encode($rawSecret)
        . '&issuer=' . rawurlencode('VELORA')
        . '&algorithm=SHA1&digits=' . VELORA_TOTP_DIGITS . '&period=' . VELORA_TOTP_PERIOD;
}
