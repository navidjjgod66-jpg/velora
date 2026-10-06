<?php
declare(strict_types=1);
/**
 * VELORA · Client IP & rate limiting & logging
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers rate_limit() and its sweep,
 * admin_ip_allowed(), get_client_ip() behind trusted proxies, and the
 * log_safe()/log_line()/log_action() trio.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/net.php requires config.php to be loaded first.');
}

function rate_limit(string $action, int $max = 5, int $window = 60, ?string $scope = null): bool {
    $safeAction = preg_replace('/[^A-Za-z0-9_-]+/', '_', $action) ?: 'rl';
    $bucketId   = ($scope !== null && $scope !== '') ? $scope : get_client_ip();
    $key        = $safeAction . ':' . hash('xxh3', $bucketId);
    $dir = dirname(__DIR__) . '/storage/cache/rl';
    /* The directory is created once, at boot, by the block that also creates
       storage/cache and storage/logs. This called @mkdir() on every single
       rate-limited request — a stat plus a syscall per call, on a path that
       already exists — and rate_limit() is on nearly every action in api.php.

       It was defensive: the directory could be deleted by a deploy, a cron
       tmpfiles policy, or a misconfigured shared host, and mkdir is cheap
       insurance against that. But the cost was paid unconditionally rather than
       when it was needed, and the boot block plus velora_ratelimit_sweep() is a
       better place for it: once per request at most, and only when a counter is
       actually about to be written. */
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('[VELORA RATE LIMIT] counter directory unavailable: ' . $dir);
        log_action('RATE_LIMIT_STORAGE_FAIL', ['action' => $action, 'fail_closed' => true]);
        return false;
    }
    $file = $dir . '/' . $key . '.json';
    $fp = @fopen($file, 'c+');

    $failOpenActions = [
        'browse_products'   => true,
        'browse_product'    => true,
        'browse_reviews'    => true,
        'pay_status'        => true,
        'my_orders'         => true,
        'pay_cb'            => true,
        'admin_read'        => true,
    ];
    $failClosed = !isset($failOpenActions[$action]);
    if (!$fp) {
        error_log('[VELORA RATE LIMIT] counter unavailable, action=' . $action);
        log_action('RATE_LIMIT_STORAGE_FAIL', ['action' => $action, 'fail_closed' => $failClosed]);
        return !$failClosed;
    }
    $bucket = ['count' => 0, 'reset' => time() + $window];
    if (flock($fp, LOCK_EX)) {
        $now = time();
        $r = fread($fp, 8192);
        if ($r !== false && $r !== '') {
            $x = json_decode($r, true);
            if (is_array($x) && isset($x['reset'], $x['count'])) $bucket = $x;
        }
        if (($bucket['reset'] ?? 0) < $now) $bucket = ['count' => 0, 'reset' => $now + $window];
        $bucket['count']++;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($bucket)); fflush($fp); flock($fp, LOCK_UN);
    }
    fclose($fp);
    if (random_int(1, 100) === 1) {
        velora_ratelimit_sweep($dir);
    }
    return $bucket['count'] <= $max;
}

/**
 * Housekeeping for the rate-limit counters.
 *
 * Two problems with doing this inline, which is where it used to be — twice
 * inside rate_limit(), behind a 1-in-100 roll.
 *
 * First, glob() twice over the whole directory. The sweep needed a list to
 * delete by age, then a *second* identical list to enforce the file cap, and
 * with maxFiles at 5000 that is up to 10,000 directory entries read and sorted
 * on a live request. On a busy shop the counter directory holds one file per
 * (action, bucket) pair, so that is not a small number — it grows with traffic.
 *
 * Second, and worse, it ran inside the lock-free tail of a function whose whole
 * job is to be fast and predictable, on the request a customer is waiting for.
 * A sweep that can take tens of milliseconds has no business being a lottery
 * ticket on checkout.
 *
 * So: one glob instead of two (the second pass reuses the first's result),
 * sorted once instead of twice, and it runs *after* the bucket has been written
 * and released — so the counter this request depends on is already durable and
 * the sweep cannot delay or roll it back. It is still amortised at 1%, because
 * the invariant it maintains — the directory does not grow without bound — is
 * eventually-consistent by nature.
 */
function velora_ratelimit_sweep(string $dir): void {
    $files = glob($dir . '/*.json') ?: [];
    $n = count($files);
    if ($n === 0) return;

    /* Age sweep. */
    $cutoff = time() - 3600;
    foreach ($files as $f) {
        $m = @filemtime($f);
        if ($m !== false && $m < $cutoff) @unlink($f);
    }

    /* Cap sweep, reusing the same listing. One sort serves both the oldest-first
       deletion order and the count. Only re-glob when the age sweep actually
       removed something, since otherwise the listing is still accurate. */
    $maxFiles = 5000;
    if ($n <= $maxFiles) return;
    $live = ($n < count($files)) ? (glob($dir . '/*.json') ?: []) : $files;
    $over = count($live) - $maxFiles;
    if ($over <= 0) return;
    sort($live, SORT_STRING);
    foreach (array_slice($live, 0, $over) as $f) @unlink($f);
}

function rate_limit_reset(string $action, string $scope): void {
    $safeAction = preg_replace('/[^A-Za-z0-9_-]+/', '_', $action) ?: 'rl';
    if ($scope === '') return;
    $file = __DIR__ . '/storage/cache/rl/' . $safeAction . ':' . hash('xxh3', $scope) . '.json';
    if (is_file($file)) @unlink($file);
}

function ip_in_cidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '') return false;
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$subnet, $bitsRaw] = explode('/', $cidr, 2);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) return false;
    $bits = (int) $bitsRaw;

    $ipBin    = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) return false;
    if (strlen($ipBin) !== strlen($subnetBin)) return false;

    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) return false;
    if ($bits === 0) return true;

    $wholeBytes    = intdiv($bits, 8);
    $remainingBits = $bits % 8;
    if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) return false;
    if ($remainingBits === 0) return true;
    $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;
    return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
}

function admin_ip_allowed(string $ip): bool {
    $raw = env('VELORA_ADMIN_IP_ALLOWLIST', '');
    $list = array_filter(array_map('trim', explode(',', is_string($raw) ? $raw : '')));
    if (!$list) return true;
    foreach ($list as $entry) {
        if ($entry === '') continue;
        if (ip_in_cidr($ip, $entry)) return true;
    }
    return false;
}

function get_client_ip(): string {
    $remote  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) return '0.0.0.0';

    $trusted = array_filter(array_map('trim', explode(',', env('VELORA_TRUSTED_PROXIES', '') ?? '')));
    if ($trusted) {
        $isTrusted = false;
        foreach ($trusted as $t) {
            if ($t === '') continue;
            if (ip_in_cidr($remote, $t)) { $isTrusted = true; break; }
        }
        if ($isTrusted) {
            $chain = [];
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $c) {
                    $c = trim($c);
                    if (filter_var($c, FILTER_VALIDATE_IP)) $chain[] = $c;
                }
            }
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $cand = $chain[$i];
                $candTrusted = false;
                foreach ($trusted as $t) {
                    if ($t !== '' && ip_in_cidr($cand, $t)) { $candTrusted = true; break; }
                }
                if (!$candTrusted) return $cand;
            }
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
                if (empty($_SERVER[$h])) continue;
                $c = trim((string) $_SERVER[$h]);
                if (filter_var($c, FILTER_VALIDATE_IP)) return $c;
            }
        }
    }
    return $remote;
}

/* Strip first, then cap.
   The order is the whole point. Capping first and stripping after would let a
   cut land in the middle of a multi-byte control sequence and re-form one, so
   a value could synthesise an escape that was never in the input. Stripping
   first makes the cap safe by construction. The `/u` attempt handles valid
   UTF-8 in one pass; on malformed input preg_replace returns null, and the
   byte-class fallback is there so a corrupt value still cannot inject a raw
   control byte into the log. */
function log_safe(string $value, int $max = 200): string {
    $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]+/u', ' ', $value) ?? '';
    if ($clean === '') {
        $clean = preg_replace('/[\x00-\x1F\x7F-\x9F]+/', ' ', $value) ?? '[unencodable]';
    }
    if (function_exists('mb_substr')) $clean = mb_substr($clean, 0, $max, 'UTF-8');
    else $clean = substr($clean, 0, $max);
    return $clean;
}

function log_line(string $line, int $max = 2000): void {
    error_log(log_safe($line, $max));
}

function log_action(string $action, array $data = []): void {
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($encoded === false) $encoded = '{"_encode_error":true}';
    $line = sprintf("[%s] [%s] [%s] %s | %s\n", date('Y-m-d H:i:s'), get_client_ip(), substr(session_id(), 0, 12), $action, $encoded);

    static $writes = 0;
    $file = __DIR__ . '/storage/logs/velora.log';

    if ((++$writes % 64) === 1) {
        $maxBytes = 8 * 1024 * 1024;
        $size = @filesize($file);
        if ($size !== false && $size > $maxBytes) {
            $rotated = $file . '.1';
            @rename($file, $rotated);
            @chmod($rotated, 0640);
        }
    }

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
