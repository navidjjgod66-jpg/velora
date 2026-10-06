<?php
declare(strict_types=1);
/**
 * VELORA · HTTP & response helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers jresp(), the CSP/security-header emitter, HTTPS detection, the same-origin local path used by the catalogue probe, canonical URI building, the esc() helper and the 404/410/503 error pages.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/http.php requires config.php to be loaded first.');
}

function jresp(array $data, int $code = 200, bool $cache = false): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header($cache ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!$cache) { header('Pragma: no-cache'); header('Expires: 0'); }

    /* JSON_INVALID_UTF8_SUBSTITUTE is what makes this total. A product name or
       an address pasted from a phone can carry bytes that are not valid UTF-8,
       and json_encode() answers such input with `false` — not with an error, not
       with an exception, with the boolean false, which concatenated into echo is
       an empty string. The client then sees a 200 with a zero-length body,
       parses nothing, and reports a generic failure while the server log says
       nothing at all.

       JSON_PARTIAL_OUTPUT_ON_ERROR closes the remaining gap (INF/NAN, a
       recursion limit, a non-encodable value): it emits what it can instead of
       discarding the whole payload. The two together mean the only way to get
       a bodyless JSON response is total failure, which the final echo covers.

       Note the flags are deliberately NOT velora_json_escape()'s: this body is
       a complete HTTP response, never interpolated into HTML, so JSON_HEX_*
       would only make Persian text and slashes unreadable to the browser. The
       escaping flags belong to the HTML-interpolation callers, not here. */
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
           | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $body = json_encode($data, $flags);
    if ($body === false) {
        /* Both flags were on and it still could not be encoded: recursion depth
           or an unsupported value. A valid JSON literal with the error code
           inside is the only thing a client can be relied on to parse. */
        error_log('[VELORA HTTP] json_encode failed for a ' . $code . ' response');
        $body = json_encode(['ok' => false, 'error' => 'ENCODE_FAILED'], $flags);
    }
    echo $body === false ? '{"ok":false,"error":"ENCODE_FAILED"}' : $body;
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
RESPONSE HELPERS · HTTPS DETECTION · SECURITY HEADERS
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

/**
 * Build the canonical URL for the current request.
 *
 * REMOVED — and this note is the reason it was, so nobody re-adds it.
 *
 * This function was dead: nothing called it. index.php builds its own
 * canonical at line ~319, and it builds a different one — a two-form answer
 * (`APP_URL . '/?product=' . id` on a product page, `APP_URL . '/'` otherwise),
 * which is what the sitemap, the JSON-LD and the address bar all agree on.
 *
 * Keeping a second canonical builder alive next to the live one is worse than
 * having none. Its whitelist named `view`, `cat`, `sort`, `sale`, `stock`, `q`
 * and `max` — parameters index.php stopped reading when `?view=` was deleted
 * years ago — while the parameters index.php actually honours are `f_q`,
 * `f_sort`, `f_cat`, `f_size`, `f_color`, `f_heel_min`, `f_heel_max`, `f_price`,
 * `f_instock`, `f_deals` and `f_new`. So it was not merely unused: it was a
 * plausible-looking answer to "what is this page's canonical?" that would have
 * produced a different URL from the real one, and it is exactly the kind of
 * thing someone reaches for when debugging a canonical-URL complaint.
 *
 * If a general canonical builder is ever genuinely needed, it should be built
 * from the filter vocabulary in one place that index.php, sitemap.php and
 * seo.php all read — not resurrected from this list.
 */

/**
 * The 404 page.
 *
 * Unused by application code, and deliberately so: index.php sends 410 for a
 * retired product id (http_410) because admin_product_delete only clears
 * `active` and the condition is permanent, and .htaccess's
 * `ErrorDocument 404 /index.php` routes every unmatched path to the storefront
 * rather than to a page of its own.
 *
 * Kept because it is part of this module's public surface and because an
 * operator adding a new endpoint will reach for it; deleting it would only
 * move the code somewhere less honest. Marked so nobody re-discovers its
 * absence from scratch.
 */
// TODO: verify dead code — not called anywhere in this repository.
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

/* The JSON encoder for anything that is interpolated into HTML.
 *
 * This is now the project's ONE JSON encoder, not one of four. index.php
 * carried a private $encodeLd closure (and a second name, $encodeJs, for the
 * same closure), includes/seo.php had seo_json(), admin.php re-spelled the flags
 * inline and then needed a hand-written false-check underneath it, and
 * includes/net.php had a fourth copy for log_action(). All of them wanted the
 * same guarantee:
 *
 * JSON_HEX_TAG|AMP|APOS|QUOT makes the payload safe inside <script> AND inside
 * a double-quoted attribute without a CDATA dance, so a product name containing
 * "</script>" is emitted escaped and cannot close the element.
 *
 * The false return is the other half of the contract. json_encode() returns
 * false on malformed UTF-8 — and a phone-form address field is exactly where
 * malformed UTF-8 arrives from — and every caller here is string interpolation,
 * where an unguarded false yields `const X = ;`: a parse error that takes down
 * the entire document rather than one broken value. 'null' is a valid JSON
 * literal that never breaks a parse, which is why it and not '' is the fallback.
 * JSON_INVALID_UTF8_SUBSTITUTE makes the common case emit a substituted value
 * instead of failing at all.
 *
 * WHY IT LIVES HERE, next to esc() and esc_xml() and not in presentation.php:
 * this is the output-encoding module, and net.php's log_action() needs it too.
 * net.php is required BEFORE presentation.php, so an encoder kept in
 * presentation.php would have made net.php's load order a precondition — the
 * exact class of bug this file exists to end. The three escapers (HTML, XML,
 * JSON) now have one home, and one home loads first.
 */
function velora_json_escape(mixed $data): string {
    $j = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return $j === false ? 'null' : (string) $j;
}

/**
 * Escape a value for HTML text or attribute context.
 *
 * The one HTML-escaping recipe in the project. Every emitter (index.php,
 * admin.php, includes/seo.php, the error pages above) used to spell its own
 * htmlspecialchars() call with its own flag combination — ENT_QUOTES here,
 * ENT_SUBSTITUTE there, ENT_HTML5 somewhere else. The flags are not a style
 * choice: ENT_SUBSTITUTE is what turns malformed UTF-8 into U+FFFD instead of
 * silently returning an empty string, and without it a broken product name
 * erases the attribute that carries it. One function, one flag set, no way to
 * get it wrong on the next emitter.
 */
function esc(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true);
}

/* The XML twin of esc(), for the three places that emit XML rather than HTML:
   the sitemap (sitemap.php) and the inline-SVG monogram plate
   (includes/presentation.php). Those two had their own inline
   htmlspecialchars(..., ENT_XML1 | ENT_QUOTES) calls — same idea as esc(), a
   different document type, so a different doctype flag and (crucially) no
   ENT_HTML5.

   Keeping it here rather than inlined at each site is the point of the whole
   exercise: the flag set is not a style choice, and ENT_XML1 vs ENT_HTML5
   changes which single and named entities are legal. Three files spelling
   their own is three chances to pick the wrong one, and in XML an unrecognised
   entity is a parse error in the crawler's parser rather than a cosmetic
   difference. */
function esc_xml(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8', true);
}

/* A CSP nonce for one document.
 *
 * index.php and admin.php each minted their own inline try/catch, and they
 * disagreed about the fallback: admin.php caught \Exception while random_bytes
 * throws RandomError/Error under PHP 8's Throwable hierarchy, so the weaker
 * openssl_random_pseudo_bytes() arm was unreachable there. Both arms now live
 * here, guarded by Throwable, so every document gets the same nonce policy
 * from one place. */
function velora_nonce(): string {
    try {
        return base64_encode(random_bytes(16));
    } catch (Throwable $e) {
        return base64_encode(openssl_random_pseudo_bytes(16));
    }
}

