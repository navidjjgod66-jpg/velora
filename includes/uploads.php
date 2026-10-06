<?php
declare(strict_types=1);
/**
 * VELORA · Product-image upload & resolution helpers
 *
 * ─── Why this file exists ────────────────────────────────────────────────
 *
 * Four functions this project depends on were *called* and never *defined*:
 *
 *   product_upload_dir()        includes/api/admin.php  (upload + delete)
 *   is_safe_upload_filename()   includes/api/admin.php  (upload + delete)
 *   product_upload_url_base()   admin.php, includes/api/admin.php, index.php
 *   product_image_url()         includes/seo.php, sitemap.php  ← UNGUARDED
 *
 * The first three were wrapped in function_exists() at most of their call
 * sites, so the missing definition degraded into a silently blank admin
 * gallery and a storefront with no upload base published to the browser.
 * The fourth had exactly one unguarded call — sitemap.php:74 — so every
 * product that carried a gallery entry turned the sitemap into a fatal
 * "Call to undefined function product_image_url()". That is the whole sitemap,
 * which robots.txt points Google at.
 *
 * The codebase behaved as if an image-resizer/CDN module had been planned and
 * then deleted, leaving four call sites pointing at a file that was never
 * written. This file is that file, and it is the single home for every upload
 * helper from here on.
 *
 * ─── What "secure upload" means here, concretely ──────────────────────────
 *
 *   · One extension. The pipeline re-encodes every upload to WebP and names
 *     the result itself, so nothing the operator sent keeps its own suffix —
 *     which is what removes the entire "double extension" (.php.jpg) family.
 *   · One name generator. Random 64-bit suffix + the validated product id,
 *     so a filename is a function of the product and never of user input.
 *   · is_safe_upload_filename() is a *closed* grammar: [A-Za-z0-9_-]{1,64}
 *     followed by ".webp". Anything containing a slash, a backslash, a dot
 *     sequence, a null byte, a percent escape, a NUL, or any control
 *     character is rejected — that is the path-traversal and
 *     null-byte-truncation defence, and it is checked *before* the value is
 *     ever concatenated into a filesystem path.
 *   · product_upload_dir() returns a realpath()-canonicalised path that has
 *     been proven to sit inside STORAGE_DIR, so a caller that does
 * *     `$dir . '/' . $filename` cannot be walked out of by any filename
 *     validation bug in the future.
 *   · Deny-execute is enforced by .htaccess in this directory, not by PHP.
 *     Application-level checks cannot protect the file from being executed if
 *     it already landed on disk; only the web server can do that.
 *
 * Every function below is total: it accepts mixed input, never fatals, never
 * emits a warning, and returns a defined value for every input including
 * null, arrays, and malformed UTF-8.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/uploads.php requires config.php to be loaded first.');
}

/* The one place the upload storage layout is spelled. Relative to VELORA_ROOT
   so a sub-directory install and a document-root install both work, and
   derived from STORAGE_DIR rather than from __DIR__ so it cannot drift when
   this file moves. Declared with velora_define(), not `defined() or define()`,
   so a second definition carrying a different value is written to the error log
   instead of being silently ignored. */
velora_define('VELORA_UPLOAD_SUBDIR', 'storage/uploads/products');

/* The single allowed extension. Lower-case, no leading dot, because it is
   both compared against and concatenated into a filename. */
velora_define('VELORA_UPLOAD_EXT', 'webp');

/* The closed filename grammar: an id stem and a fixed suffix. See the header
   for why this is a whitelist rather than a blocklist. */
velora_define('VELORA_UPLOAD_NAME_RE', '/^[A-Za-z0-9_-]{1,64}\.webp$/');

/* The extension the browser is told to use for the image we hand back, and the
   MIME type a correct server should answer with. The pipeline re-encodes every
   upload to WebP, so this is a fact about the bytes on disk, not a guess. */
velora_define('VELORA_UPLOAD_MIME', 'image/webp');

/* ═══════════════════════════════════════════════════════════════════════════
   STORAGE LOCATION
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * The directory product uploads are written to, created if missing.
 *
 * Resolves to an absolute, canonicalised path, or '' when the directory does
 * not exist and could not be created. It never returns a relative path and
 * never returns a path outside STORAGE_DIR — the containment check at the end
 * is what makes that true even if VELORA_UPLOAD_SUBDIR is ever mis-edited to
 * something containing "..".
 *
 * @return string '' on failure; callers MUST treat '' as "cannot upload".
 */
function product_upload_dir(): string {
    static $resolved = null;
    if ($resolved !== null) return $resolved;

    $dir = rtrim(STORAGE_DIR, '/\\') . '/' . VELORA_UPLOAD_SUBDIR;

    if (!is_dir($dir)) {
        /* 0755 rather than 0777: the web user needs to write, and the files
           it writes are served as static assets by the web server running as
           the same user, so nothing else needs write access. */
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[VELORA UPLOAD] cannot create ' . $dir);
            $resolved = '';
            return $resolved;
        }
    }

    /* realpath() collapses "..", resolves symlinks and — most importantly —
       returns false when the path does not exist, which is the one answer
       that must never be concatenated into a write. */
    $real = realpath($dir);
    if ($real === false) {
        error_log('[VELORA UPLOAD] realpath() failed for ' . $dir);
        $resolved = '';
        return $resolved;
    }

    /* Containment. The uploads directory must live under STORAGE_DIR. If an
       operator ever points VELORA_UPLOAD_SUBDIR at a symlink that escapes,
       this is the check that notices — the filesystem is the last place the
       truth is available. */
    $root = realpath(STORAGE_DIR);
    if ($root === false || !str_starts_with($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)) {
        error_log('[VELORA UPLOAD] ' . $real . ' escapes ' . STORAGE_DIR);
        $resolved = '';
        return $resolved;
    }

    $resolved = $real;
    return $resolved;
}

/**
 * The public URL prefix under which product uploads are served.
 *
 * Absolute, no trailing slash, so callers can concatenate '/' . $filename.
 * Derived from APP_URL (the canonical origin a crawler reads) rather than from
 * the request, because this value is published to the browser in a <script>
 * and in the admin panel's UPLOAD_BASE — a value that changed per request
 * would be a same-origin violation on any host that is not APP_URL.
 */
function product_upload_url_base(): string {
    return APP_URL . '/' . VELORA_UPLOAD_SUBDIR;
}

/* ═══════════════════════════════════════════════════════════════════════════
   FILENAME VALIDATION
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Is this string exactly a filename this project created?
 *
 * The grammar is [A-Za-z0-9_-]{1,64}.webp and nothing else. That single
 * regular expression is the whole defence, and it is a whitelist on purpose:
 *
 *   · no "/" or "\"            → cannot traverse to another directory
 *   · no "." inside the stem   → cannot produce "name.php.webp" or ".."
 *   · no leading "."           → cannot produce ".htaccess" or ".env"
 *   · no null byte or control char → cannot truncate a C-level path check
 *   · no "%" or "#"            → cannot smuggle an encoded or fragment form
 *   · fixed ".webp" suffix     → the web server has nothing to execute even
 *                                if this check were bypassed entirely
 *
 * Accepts mixed and returns false for anything that is not a string, so a
 * caller can pass a raw request value straight in. It never throws.
 */
function is_safe_upload_filename(mixed $name): bool {
    if (!is_string($name) || $name === '') return false;
    if (strlen($name) > 80) return false;          /* cheap bound before the regex */
    /* Belt and braces: the regex already rejects these, but a control byte or
       a null reaching a filesystem call is worth refusing on sight even if the
       grammar above is ever widened. */
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) return false;
    if (str_contains($name, '..')) return false;
    if (str_contains($name, '/') || str_contains($name, '\\')) return false;
    return (bool) preg_match(VELORA_UPLOAD_NAME_RE, $name);
}

/**
 * Build a collision-free, traversal-free filename for a new upload.
 *
 * The product id is the readable part; 16 random hex characters are the part
 * that makes it unique. The id is *reduced* to the allowed alphabet before use
 * rather than validated, because a product id that is too long or has a stray
 * character should still produce a usable name — the reduction cannot make it
 * unsafe, it can only make it boring.
 *
 * @throws InvalidArgumentException if $productId reduces to nothing.
 */
function product_upload_filename(string $productId): string {
    $stem = preg_replace('/[^A-Za-z0-9_-]/', '', $productId) ?? '';
    if ($stem === '') {
        throw new InvalidArgumentException('product id reduces to an empty upload stem');
    }
    $unique = bin2hex(random_bytes(8));            /* 64 bits */
    /* Total must stay inside the 64-character stem budget the grammar allows:
       64 stem + 1 dash + 16 suffix + 5 ".webp" = 86 > 80. The stem is
       therefore cut to 64 - 1 - 16 = 47. */
    $stem = substr($stem, 0, 47);
    if ($stem === '') $stem = 'p';
    $name = $stem . '-' . $unique . '.' . VELORA_UPLOAD_EXT;
    if (!is_safe_upload_filename($name)) {
        throw new RuntimeException('generated upload filename failed validation');
    }
    return $name;
}

/**
 * Resolve a filename to its absolute path inside the uploads directory, or ''
 * if the name is not one of ours.
 *
 * This is the only function a delete should concatenate from. It re-checks
 * the grammar *and* re-verifies that the resolved real path is still inside
 * product_upload_dir(), so a symlink planted inside the directory cannot be
 * used to unlink a file elsewhere.
 */
function product_upload_path(string $name): string {
    if (!is_safe_upload_filename($name)) return '';
    $dir = product_upload_dir();
    if ($dir === '') return '';
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    $real = realpath($path);
    if ($real === false) return '';                /* not present — nothing to act on */
    if (!str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
        error_log('[VELORA UPLOAD] path escape refused: ' . $real);
        return '';
    }
    return $real;
}

/* ═══════════════════════════════════════════════════════════════════════════
   CATALOGUE IMAGE RESOLUTION
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Turn a catalogue image key into an absolute URL, or '' when there is none.
 *
 * An "image key" is whatever products.json stores in `img` / `gallery[]`. The
 * vocabulary is exactly two forms, and both are validated here:
 *
 *   1. a filename this project produced → /storage/uploads/products/<name>.webp
 *   2. an absolute https:// URL an operator pasted deliberately → returned as-is
 *
 * A bare catalogue id with no uploaded photography behind it returns ''. That
 * is a real answer, not a failure: the storefront draws a generated SVG plate
 * in the browser for it, and that plate has no address a crawler could fetch.
 * Returning the brand mark instead would be a lie about the product, and
 * returning a URL that 404s is worse.
 *
 * $w / $h / $q are accepted and ignored. They are part of the signature the
 * three existing call sites already use, and the site has no resizer — a
 * thumbnailer can be dropped in behind this function later without touching a
 * single caller. Silently accepting them is strictly better than a fatal on
 * an upgrade.
 */
function product_image_url(string $keyOrUrl, int $w = 1200, int $h = 630, int $q = 80): string {
    $key = trim($keyOrUrl);
    if ($key === '' || strlen($key) > 512) return '';

    /* An operator-pasted absolute URL. https only: the CSP's img-src allows
       https, and http:// under an https page is a mixed-content block anyway. */
    if (preg_match('#^https://#i', $key)) {
        if (preg_match('/["\'<>\s|\\\\]/', $key)) return '';
        return filter_var($key, FILTER_VALIDATE_URL) ? $key : '';
    }

    /* Our own upload. is_safe_upload_filename() is the same predicate the
       uploader used to create the name, so this can only ever name a file this
       site produced. */
    if (is_safe_upload_filename($key)) {
        return product_upload_url_base() . '/' . $key;
    }

    return '';
}

/**
 * The maison's own brand mark, as an absolute URL.
 *
 * Used by includes/seo.php for any og:image / JSON-LD slot that has no real
 * product photography behind it. brand-icon-512.png ships in the repository
 * and is referenced by .htaccess's own immutable-caching rule, so the URL
 * resolves on every install.
 */
function product_brand_image(): string {
    return APP_URL . '/brand-icon-512.png';
}

/**
 * The declared pixel dimensions of product_brand_image(), so the SEO emitters
 * can state them rather than guessing — claiming 1200×630 for a 512×512 PNG
 * is a Rich Results warning and misrepresents the asset.
 *
 * @return array{0:int,1:int}
 */
function product_brand_image_size(): array {
    return [512, 512];
}

/**
 * A one-line health summary for the startup audit: can this installation
 * accept an upload at all?
 *
 * Returns null when everything is in order, and a human sentence when it is
 * not. Answering "the panel will 500 on the first upload" at boot is worth
 * more than the same sentence in a 500 response body.
 */
function product_upload_health(): ?string {
    if (!extension_loaded('gd')) {
        return 'ext-gd is not loaded: every product-image upload will fail with GD_MISSING.';
    }
    if (!function_exists('imagewebp')) {
        return 'GD is loaded without WebP support: uploads will fail with WEBP_UNSUPPORTED.';
    }
    $dir = product_upload_dir();
    if ($dir === '') {
        return 'The product upload directory (' . VELORA_UPLOAD_SUBDIR
             . ') could not be created or resolved under STORAGE_DIR. Uploads will fail with DIR_NOT_WRITABLE.';
    }
    if (!is_writable($dir)) {
        return 'The product upload directory (' . $dir . ') is not writable by the web user. '
             . 'Uploads will fail with DIR_NOT_WRITABLE. Expected mode 0755.';
    }
    return null;
}