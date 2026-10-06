<?php
declare(strict_types=1);
/**
 * VELORA · Environment layer
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers the .env loader, the env
 * readers (env/env_bool), the php.ini size parser, the upload ceiling that is
 * actually in force, the gateway-merchant placeholder probe, and the
 * fail-fast check for required keys.
 *
 * This file must be loaded before any constant derived from the environment
 * (APP_URL, DB_*, ZARINPAL_*) is defined, because those definitions call the
 * helpers below. It carries no dependency on config.php itself.
 */

/* ═══════════════════════════════════════════════════════════════════════════
ENV LOADER
═══════════════════════════════════════════════════════════════════════════ */
(static function (string $file): void {
    if (!is_readable($file)) return;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return;
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $invisible = ["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"];
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = str_replace($invisible, '', trim($k));
        $v = str_replace($invisible, '', trim($v));
        if ($k === '' || getenv($k) !== false) continue;
        $n = strlen($v);
        if ($n >= 2) {
            $f = $v[0]; $l = $v[$n - 1];
            if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                $v = substr($v, 1, -1);
            } else {
                $v = preg_replace('/\s+#.*$/', '', $v) ?? $v;
                $v = trim($v);
            }
        } else {
            $v = preg_replace('/\s+#.*$/', '', $v) ?? $v;
            $v = trim($v);
        }
        putenv("$k=$v");
        $_ENV[$k] = $_SERVER[$k] = $v;
    }
})(dirname(__DIR__) . '/.env');

/* ═══════════════════════════════════════════════════════════════════════════
ENV HELPERS
═══════════════════════════════════════════════════════════════════════════ */
function env(string $key, ?string $default = null): ?string {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === '') return $default;
    return is_string($v) ? trim($v) : $default;
}

function env_bool(string $key, bool $default = false): bool {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === '') return $default;
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Read a php.ini size directive as a byte count.
 *
 * php.ini accepts the shorthand `10M`, and ini_get() hands that shorthand
 * straight back — so `(int) ini_get('upload_max_filesize')` is 10, and a panel
 * comparing it against a 10 MB constant believes the real limit is ten bytes.
 * The same applies to the space form (`10 M`) and to `1G`, which is why the
 * suffix is matched rather than assumed.
 *
 * Returns 0 for a directive set to 0 or left empty, which in php.ini means
 * "unlimited" — the caller decides what to do with that, because for a size
 * ceiling "unlimited" is not a number to clamp with.
 */
function ini_bytes(string $key): int {
    $raw = trim((string) @ini_get($key));
    if ($raw === '') return 0;
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i', $raw, $m)) return 0;
    $mult = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824, '' => 1][strtoupper($m[2])] ?? 1;
    return (int) round((float) $m[1] * $mult);
}

/**
 * The upload ceiling actually in force right now.
 *
 * Three limits apply and the customer hits the smallest: the one our code
 * enforces (ADMIN_UPLOAD_MAX_BYTES), the one PHP enforces on the file, and the
 * one PHP enforces on the request body. The third matters as much as the second
 * — a file that fits under upload_max_filesize still vanishes if the POST that
 * carried it exceeded post_max_size, and it vanishes before any of our code
 * runs, which is why the resulting error is NO_FILE rather than FILE_TOO_LARGE.

 * A php.ini value of 0 means unlimited, so it is dropped from the minimum rather
 * than treated as a ceiling of zero bytes. The constant is always included, so
 * the return value is a real number and never 0.
 */
function admin_upload_effective_max(): int {
    $limits = [ADMIN_UPLOAD_MAX_BYTES];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $b = ini_bytes($key);
        if ($b > 0) $limits[] = $b;
    }
    return min($limits);
}

function is_placeholder_merchant(string $id): bool {
    $id = trim($id);
    if ($id === '') return true;
    $probe = strtolower($id);
    foreach (['change_me', 'changeme', 'xxxxxxxx', 'your-', 'your_', 'todo', 'placeholder', 'example'] as $needle) {
        if (str_contains($probe, $needle)) return true;
    }
    return false;
}

function env_require_all(array $keys): array {
    $missing = []; $out = [];
    foreach ($keys as $k) {
        $v = env($k);
        if ($v === null || $v === '') $missing[] = $k; else $out[$k] = $v;
    }
    if ($missing) {
        /* The names only. These are the same keys printed in .env.example,
           which is committed, so nothing here is a secret — and without them
           the client can show nothing but a bare 500, which reads as a crash
           rather than as a file nobody has filled in. The values are what must
           never travel; they are not touched here. */
        error_log('[VELORA CONFIG] Missing required env: ' . implode(', ', $missing));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        exit(json_encode(['ok' => false, 'error' => 'CONFIG_INCOMPLETE',
                          'message' => 'تنظیم کامل نیست — در فایل .env مقدار زیرها خالی است: '
                                       . implode(', ', $missing)], JSON_UNESCAPED_UNICODE));
    }
    return $out;
}
