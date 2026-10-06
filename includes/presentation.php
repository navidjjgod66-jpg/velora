<?php
declare(strict_types=1);
/**
 * VELORA · Presentation helpers — the maison's visual vocabulary
 *
 * Extracted verbatim from index.php during the storefront split — same code,
 * same behaviour, one home per concern. Covers:
 *   · velora_color_hex()  — the colour-key → hex table (server twin of
 *     hexFor() in js/data.js; the client cannot import PHP and the server
 *     cannot run a browser module, so the table exists twice BY DESIGN and
 *     these two copies are the only ones left after the pdp-aurelle.js and
 *     inline-index.php duplicates were folded into their respective owners).
 *   · velora_product_plate() — the deterministic inline-SVG monogram plate,
 *     a deliberate byte-identical twin of plate() in js/data.js so the
 *     server-rendered card and the client re-render match exactly.
 *   · velora_product_image() — resolves a product's primary image the same
 *     way the browser will (upload → absolute https URL → gallery → plate).
 *
 * Every function here is pure over catalogue rows; nothing reads request
 * state, so the file is safe to load from any entry point that has loaded
 * config.php.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/presentation.php requires config.php to be loaded first.');
}

/* A colour key means nothing to a browser; it needs a hex. This is the same
   table data.js holds, and it is duplicated rather than imported because the
   server cannot run the client's module before it has sent HTML. The two must
   agree, so the table is short, fixed, and commented as the single place to
   change on the server side. */
const VELORA_COLOR_HEX = [
    'champ' => '#b8942a', 'noir' => '#161310', 'ivory' => '#efe6d8',
    'suede' => '#a8825c', 'oxblood' => '#5a1f26', 'cognac' => '#6b3a1e',
    'pearl' => '#e8e4da', 'satin' => '#161310', 'gold' => '#d4af37',
    'patent' => '#0b0b0e',
];

/**
 * Resolve a colour key to its hex, falling back to neutral grey.
 *
 * An unknown key means the vocabulary grew on the server and this table was
 * not told; grey is the honest answer, and the swatch's accessible name still
 * comes from the colour's own label rather than from this value.
 */
function velora_color_hex(string $key): string {
    return VELORA_COLOR_HEX[$key] ?? '#8a8a8a';
}

/* velora_json_escape() used to live here. It is an output encoder, not a
   presentation helper, and includes/net.php's log_action() needs it — net.php is
   required before this file, so keeping it here made net.php's load order a
   precondition. It now lives in includes/http.php beside esc() and esc_xml(),
   so all three escapers have one home and that home loads first.
   Reached here as velora_json_escape() by seo.php's seo_json() alias, by
   index.php's boot payload and JSON-LD, and by admin.php's UPLOAD_BASE. */

/**
 * The maison's inline-SVG monogram plate, as a data URI.
 *
 * Deliberate twin of plate() in js/data.js: same viewBox, same colours, same
 * deterministic monogram, same 640×800 (= exactly the 4/5 aspect-ratio the
 * card declares). Because both sides draw the same picture from the same id,
 * the server's card and the client's re-render are byte-identical — which is
 * what makes the "a hydration mismatch is impossible" claim in index.php
 * actually true rather than aspirational.
 */
function velora_product_plate(string $id, string $label = ''): string {
    $ch = mb_substr(trim($label !== '' ? $label : $id), 0, 1, 'UTF-8');
    if ($ch === '') $ch = '·';
    $fg = '#d9b98a';
    $bg = '#0b0a09';
    $svg =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 800">'
      . '<rect width="640" height="800" fill="' . $bg . '"/>'
      . '<rect x="26" y="26" width="588" height="748" fill="none" stroke="' . $fg
      . '" stroke-opacity=".34" stroke-width="1.5"/>'
      . '<path d="M320 236 404 320 320 404 236 320Z" fill="none" stroke="' . $fg
      . '" stroke-opacity=".8" stroke-width="2"/>'
      . '<circle cx="320" cy="320" r="86" fill="none" stroke="' . $fg
      . '" stroke-opacity=".3" stroke-width="1"/>'
      . '<text x="320" y="352" text-anchor="middle" font-family="Georgia,serif" font-size="104" fill="'
      . $fg . '" fill-opacity=".92">' . esc_xml($ch) . '</text>'
      . '<text x="320" y="500" text-anchor="middle" font-family="Georgia,serif" font-size="30" letter-spacing="8" fill="'
      . $fg . '" fill-opacity=".62">VELORA</text>'
      . '</svg>';
    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

/**
 * Resolve a product's primary image the same way the browser will.
 *
 * Two steps, and the order matters:
 *   1. an uploaded WebP or an absolute https URL — a real photograph;
 *   2. otherwise the maison's inline SVG monogram plate.
 *
 * It used to return product_brand_image(), a 50 KB 512×512 PNG, used as the
 * product photograph for every card — one request, but a 50 KB PNG on the
 * critical path of a page whose whole point is that the collection is
 * server-rendered, and then main.js threw it away on the first frame.
 *
 * product_image_url() is not reached behind a function_exists() guard any more.
 * It is defined in includes/uploads.php, which config.php requires before this
 * file, so the guard could only ever have hidden a broken bootstrap — and it
 * did exactly that: sitemap.php had the same call with no guard and turned
 * /sitemap.xml into a fatal for any product with a gallery entry. */
function velora_product_image(array $p): string {
    $key = (string) ($p['img'] ?? '');
    if ($key !== '') {
        $u = product_image_url($key, 1200, 0, 80);
        if ($u !== '') return $u;
    }
    /* An absolute https URL pasted by an operator wins outright. */
    if ($key !== '' && preg_match('#^https://#i', $key)) return $key;
    /* A gallery entry, if the product has one. */
    $gal = $p['gallery'] ?? [];
    if (is_array($gal)) {
        foreach ($gal as $g) {
            $g = trim((string) $g);
            if ($g === '') continue;
            $u = product_image_url($g, 1200, 0, 80);
            if ($u !== '') return $u;
            if (preg_match('#^https://#i', $g)) return $g;
        }
    }
    return velora_product_plate((string) ($p['id'] ?? ''), (string) ($p['name'] ?? ''));
}
