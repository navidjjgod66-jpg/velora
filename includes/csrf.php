<?php
declare(strict_types=1);
/**
 * VELORA · CSRF protection
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers csrf_token(), the allowed-host list, the loopback probe and csrf_check().
 */if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/csrf.php requires config.php to be loaded first.');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf']) || (int) ($_SESSION['csrf_at'] ?? 0) < time() - 3600) {
        $_SESSION['csrf']    = bin2hex(random_bytes(32));
        $_SESSION['csrf_at'] = time();
    }
    return $_SESSION['csrf'];
}

function csrf_allowed_hosts(): array {
    static $hosts = null;
    if ($hosts !== null) return $hosts;

    $hosts = [strtolower(APP_HOST)];
    if (str_starts_with(APP_HOST, 'www.')) {
        $hosts[] = substr(APP_HOST, 4);
    } else {
        $hosts[] = 'www.' . APP_HOST;
    }

    if (velora_server_is_loopback()) {
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';
        $hosts[] = '[::1]';
    } elseif (APP_ENV !== 'production') {
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';
        $hosts[] = '[::1]';
    }
    return array_values(array_unique($hosts));
}

function velora_server_is_loopback(): bool {
    $addr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    if ($addr === '') {
        return false;
    }
    if ($addr === '::1' || strtolower($addr) === '0:0:0:0:0:0:0:1') return true;
    if (str_starts_with($addr, '127.')) {
        return (bool) preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $addr);
    }
    return false;
}

function csrf_check(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin !== '') {
        $parsed = parse_url((string) $origin);
        $host = strtolower((string) ($parsed['host'] ?? ''));

        if ($host === '' || !in_array($host, csrf_allowed_hosts(), true)) {
            log_action('CSRF_ORIGIN_MISMATCH', [
                'origin'    => $origin,
                'host'      => $host,
                'expected'  => implode(',', csrf_allowed_hosts()),
                'app_url'   => APP_URL,
                'server'    => (string) ($_SERVER['SERVER_ADDR'] ?? ''),
                'loopback'  => velora_server_is_loopback() ? 1 : 0,
                'env'       => APP_ENV,
                'ip'        => get_client_ip(),
            ]);
            jresp([
                'ok'        => false,
                'error'     => 'CSRF_ORIGIN_INVALID',
                'token'     => csrf_token(),
                'host'      => $host,
                'expected'  => csrf_allowed_hosts(),
                'app_url'   => APP_URL,
            ], 403);
        }
    }

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($sent) || $sent === '') {
        $bodyToken = array_key_exists('csrf', $_POST) ? $_POST['csrf'] : (req_parse_json()['csrf'] ?? null);
        $sent = is_string($bodyToken) ? $bodyToken : '';
    }
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        log_action('CSRF_REJECTED', ['ip' => get_client_ip(), 'origin' => $origin]);
        jresp(['ok' => false, 'error' => 'CSRF_INVALID', 'token' => csrf_token()], 403);
    }
}
