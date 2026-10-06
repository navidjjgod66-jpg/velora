<?php
declare(strict_types=1);
/**
 * VELORA · OTP code storage
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers velora_mac_key(), otp_digest() and otp_matches() — the keyed-digest storage for one-time codes.
 */
if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/otp.php requires config.php to be loaded first.');
}

function velora_mac_key(string $purpose): string {
    $secret = env('VELORA_APP_KEY', '');
    if ($secret === null || $secret === '') {
        $secret = (string) DB_PASS;
    }
    return hash_hmac('sha256', 'velora:' . $purpose, $secret, true);
}

function otp_digest(string $phone, string $code): string {
    return hash_hmac('sha256', 'otp:' . $phone . ':' . $code, velora_mac_key('otp'));
}

function otp_matches(string $phone, string $code, string $storedDigest): bool {
    if ($storedDigest === '' || $code === '') return false;
    return hash_equals($storedDigest, otp_digest($phone, $code));
}
