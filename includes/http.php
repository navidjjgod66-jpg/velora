<?php
declare(strict_types=1);
/**
 * VELORA · HTTP & response helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers jresp(), the CSP/security-header emitter, HTTPS detection, the same-origin local path used by the catalogue probe, canonical URI building, the esc() helper and the 404/410/503 error pages.
 */if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/http.php requires config.php to be loaded first.');
}

function jresp(array $data, int $code = 200, bool $cache = false): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header($cache ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!$cache) { header('Pragma: no-cache'); header('Expires: 0'); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

HTTP · security headers · HTTPS detection · canonical URI
═══════════════════════════════════════════════════════════════════════════ */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')    return true;
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)              return true;
    return false;
}

function emit_security_headers(string $csp, bool $allowFraming = true): void {
    if (headers_sent()) return;

    $directives = [$csp];
    if (is_https()) $directives[] = 'upgrade-insecure-requests';

    header('Content-Security-Policy: ' . implode('; ', $directives));

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: ' . ($allowFraming ? 'SAMEORIGIN' : 'DENY'));
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), '
         . 'payment=(self "https://payment.zarinpal.com")');
    header('Cross-Origin-Resource-Policy: same-site');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

/**
 * A same-origin path to one of this installation's own endpoints.
 *
 * Used for the URLs a *running page* fetches — the catalogue feed — as opposed
 * to the URLs a *crawler* reads, which is what APP_URL is for. Conflating the
 * two is what pointed the browser's catalogue probe at the canonical host: the
 * probe then violated the storefront's own `connect-src 'self'`, and the
 * synchronisation feature silently did nothing on any host but that one.
 *
 * Derived from SCRIPT_NAME rather than from APP_URL, so it is correct at the
 * document root ('catalog.php') and in a sub-directory install
 * ('/shop/catalog.php') without either being configured, and it is always on
 * the origin that actually served the request.
 *
 * $file is a literal from this codebase, never user input; it is not escaped
 * because there is nothing to escape, and a value that needed escaping would
 * mean this function was being handed something it should not accept.
 */
function velora_local_path(string $file): string {
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $dir = rtrim($dir, '/');
    /* dirname('/') is '/', and on Windows SCRIPT_NAME can be a backslash
       path; both collapse to the empty base, which is the document root. */
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }
    return $dir . '/' . ltrim($file, '/');
}

function canonical_uri(): string {
    $raw   = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($raw) ?: [];
    $path  = $parts['path'] ?? '/';
    $path  = preg_replace('#/index\.php$#', '/', $path) ?: '/';
    $qs    = [];
    if (isset($parts['query'])) parse_str($parts['query'], $qs);
    $whitelist = ['view', 'product', 'cat', 'sort', 'sale', 'stock', 'q', 'max'];
    $keep = [];
    foreach ($whitelist as $k) {
        if (!isset($qs[$k]) || !is_scalar($qs[$k]) || $qs[$k] === '') continue;
        if ($k === 'sort' && $qs[$k] === 'featured') continue;
        $keep[$k] = (string) $qs[$k];
    }
    if (!empty($keep['product'])) $keep = ['product' => $keep['product']];
    elseif (($keep['view'] ?? '') === 'shop') unset($keep['sort'], $keep['q'], $keep['max']);
    ksort($keep);
    $q = $keep ? '?' . http_build_query($keep, '', '&', PHP_QUERY_RFC3986) : '';
    return APP_URL . $path . $q;
}

function http_404(string $title = 'صفحه یافت نشد | VELORA', string $nonce = ''): void {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store');
    }
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
    $n = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
    echo <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>{$t}</title>
<style{$n}>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#060504;color:#eee;font-family:Vazirmatn,system-ui,sans-serif;text-align:center;padding:2rem}
h1{color:#d9b98a;font-weight:400;letter-spacing:.2em;font-size:2.4rem;margin:0 0 1rem}
p{color:#999;line-height:2;margin:.4rem 0}
a{color:#d9b98a;text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px}</style>
</head><body><div><h1>۴۰۴</h1>
<p>صفحه‌ای که دنبال آن بودید یافت نشد.</p>
<p><a href="/">بازگشت به خانه</a></p></div></body></html>
HTML;
    exit;
}

function http_410(string $title = 'محصول بازنشسته شده | VELORA', string $nonce = ''): void {
    if (!headers_sent()) {
        http_response_code(410);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store');
    }
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
    $n = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
    echo <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>{$t}</title>
<style{$n}>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#060504;color:#eee;font-family:Vazirmatn,system-ui,sans-serif;text-align:center;padding:2rem}
h1{color:#d9b98a;font-weight:400;letter-spacing:.2em;font-size:2.4rem;margin:0 0 1rem}
p{color:#999;line-height:2;margin:.4rem 0}
a{color:#d9b98a;text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px}</style>
</head><body><div><h1>۴۱۰</h1>
<p>این محصول دیگر در کلکسیون ارائه نمی‌شود.</p>
<p><a href="/">بازگشت به خانه</a></p></div></body></html>
HTML;
    exit;
}

/**
 * 503 — the maison is there but cannot be assembled.
 *
 * Distinct from http_404 and http_410 because the crawler's reading differs:
 * a 503 says "ask again later", so a temporary catalogue failure is not
 * deindexed. There are exactly two ways to reach it — products.json unreadable,
 * or the catalogue reader itself missing — and both are operator problems
 * that the startup audit has already written to the log.
 */
function http_503(string $nonce = ''): void {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Retry-After: 120');
        header('Cache-Control: no-store');
    }
    $n = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
    echo <<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>ویترین موقتاً در دسترس نیست | VELORA</title>
<style{$n}>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#060504;color:#eee;font-family:Vazirmatn,system-ui,sans-serif;text-align:center;padding:2rem}
 h1{color:#d9b98a;font-weight:400;letter-spacing:.2em;font-size:2.2rem;margin:0 0 1rem}
 p{color:#999;line-height:2;margin:.4rem 0}
 a{color:#d9b98a;text-decoration:none;border-bottom:1px solid currentColor;padding-bottom:2px}</style>
</head><body><div><h1>۵۰۳</h1>
<p>ویترین موقتاً در دسترس نیست.</p>
<p>چند لحظه بعد دوباره سر بزنید.</p>
<p><a href="/">بازگشت به خانه</a></p></div></body></html>
HTML;
    exit;
}

function esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true);
}

function esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true);
}

