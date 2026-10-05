<?php
declare(strict_types=1);
/**
 * VELORA AURELLE · index.php
 * The single storefront document. Serves the whole maison — home, collection,
 * product and atelier — as one page, and is the only HTML file a visitor loads.
 *
* ─── Files that must sit beside this one ─────────────────────────────────
 *   Backend   config.php · api.php · zarinpal.php · admin.php · catalog.php
 *             sitemap.php · db_schema.sql · products.json
 *   Assets    css/{aurelle-tokens,base,components,sections,dialogs,animations}.css
 *   Script    js/{core,data,velora-bridge,renderers-aurelle,state,ui,cart,
 *                pdp-aurelle,lbxaurelle,checkout,auth,concierge-aurelle,
 *                atelier-aurelle,sync-aurelle,main}.js
 *             sw.js · manifest.json · brand-icon-{180,192,512}.png · .htaccess
 *
 *   The script list is declared once, in $VELORA_JS below, and the tags and the
 *   service worker's precache manifest are both generated from it. They used to
 *   be two hand-maintained lists, and the precache one named ./style.css and
 *   ./app.js — files this project has never had — so the worker's precache was
 *   empty on every install. Adding a file to one list and not the other is the
 *   failure mode a single list exists to make impossible.
 *
 *   Removed rather than merely unused: payment.php (the Zibal gateway),
 *   css/aurelle-bridge.css (its selectors were already covered), js/admin.js
 *   (the admin panel is admin.php, served on its own), offline.html and
 *   404.html (sw.js and index.php answer both cases already).
 *
 * ─── What this document decides ──────────────────────────────────────────
 *   · The catalogue is rendered here, from products.json, so the shop exists
 *     before a single byte of JavaScript runs.
 *   · Every asset URL carries ?v=<filemtime>, which is what lets .htaccess
 *     serve them immutable and lets sw.js trust them.
 *   · <head> comes from includes/seo.php, so meta, OpenGraph and JSON-LD have
 *     exactly one owner.
 */

require __DIR__ . '/config.php';

/* ─── Request ──────────────────────────────────────────────────────────────
   Three things are decided before anything else: the nonce, the CSRF token,
   and which product this document is about.

   The nonce and the CSRF token come first because every header below them
   quotes the nonce — script-src and style-src both carry it — and a page that
   renders its own CSP after the fact has no valid policy at all.

   `?product=<id>` selects the product this document is *about*: it changes the
   title, the description, the canonical, the OpenGraph type and the JSON-LD,
   and nothing else, because the product itself is presented in a dialog over
   the collection (pdp-aurelle.js). The id is reduced to [A-Za-z0-9_-] before
   it is used, so it can only ever name a catalogue key — never a path, a
   query, or a quote.

   `?view=` used to be read into a variable and then never branched on: five
   values were accepted, all five produced the identical document, and the
   sitemap advertised all five as separate pages. A parameter that selects
   nothing is not a parameter, so it is gone from the request rather than left
   behind as a variable that lies about what it does. */
try {
    $nonce = base64_encode(random_bytes(16));
} catch (Throwable $e) {
    $nonce = base64_encode(openssl_random_pseudo_bytes(16));
}
$csrf = csrf_token();

/* ─── Product id ───────────────────────────────────────────────────────────
   Read here, sanitised here, reduced to '' if absent — and that last step is
   the one that matters. `$productId` is consumed three times below (the
   404/410 branch, velora_catalog_product(), the boot payload), and
   velora_catalog_product() declares `string $id` in a strict_types file. A
   null that is only *sometimes* short-circuited by `!== ''` does not survive
   that: `null !== ''` is true in PHP 8, so the guard fell through to the
   call and raised a TypeError — an uncaught fatal, display_errors off, so a
   500 with an empty body for every visitor including the bare homepage.

   The sanitiser itself is the part the header comment above already promised
   and the code did not do. [A-Za-z0-9_-] is exactly the id grammar
   velora_catalog_product() stores, so after this reduction the string can
   only ever name a catalogue key — never a path, a query, or a quote — and
   the length cap keeps it inside the 64-byte id width the catalogue uses.

   mb_substr is not used here on purpose: the id is ASCII by construction,
   and stripping the bytes that make up anything else is the whole job. */
$productId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['product'] ?? ''));
if ($productId === null) {
    $productId = '';
}
$productId = substr($productId, 0, 64);

/* ─── Product ──────────────────────────────────────────────────────────────
   A product id that names a retired product is a 410, not a 404 and not a
   200. The distinction is what tells a crawler the page is gone for good
   rather than temporarily unavailable, and http_410() already existed for it
   — unused, because nothing ever called it.

   admin_product_delete only clears a product's `active` flag rather than
   removing the row, so "not found" and "retired" are the same condition here
   and both are permanent. A 200 with a thin body would be a soft 404 on a URL
   the sitemap used to list, which is the expensive kind of duplicate. */
$product = null;
if ($productId !== '') {
    if (!function_exists('velora_catalog_product')) {
        /* No catalogue reader means no shop at all — not a missing product.
           Saying so is better than serving a page that looks like a shop. */
        http_503($nonce);
    }
    $row = velora_catalog_product($productId);
    if (is_array($row) && !empty($row['active'])) {
        $product = velora_catalog_client_shape($row);
    } else {
        /* The 410 the comment above describes, actually sent.

           This branch set a flag and then carried on rendering, so a retired
           or misspelt id produced a 200 carrying the *home page's* title,
           description, canonical and JSON-LD — a full, valid, indexable
           document at a URL the sitemap lists. That is the expensive kind of
           duplicate: it is not thin enough to look like an error page, and it
           tells the crawler the product exists and is canonical elsewhere.

           The flag is gone rather than kept, because a boolean that two
           hundred lines downstream nothing reads is the same defect as the
           missing call: it says a thing is handled that is not. */
        http_410($nonce);
    }
}

/* ─── Catalogue for this render ───────────────────────────────────────────
   Read once, used three ways: the server-rendered collection, the JSON-LD, and
   the boot payload the browser starts from. velora_catalog_client_feed() is
   the same shaping api.php and catalog.php use, so the price a visitor sees,
   the price a crawler records and the price an order is charged are one
   number, read from one place. */
$catalogFeed = [];
if (function_exists('velora_catalog_active') && function_exists('velora_catalog_client_shape')) {
    try {
        $rows = velora_catalog_active();
        usort($rows, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            return $so !== 0 ? $so : strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
        $catalogFeed = array_map('velora_catalog_client_shape', $rows);
    } catch (Throwable $e) {
        error_log('[VELORA INDEX] catalogue unavailable: ' . $e->getMessage());
        $catalogFeed = [];
    }
}
/* Keep the untouched feed beside the filtered one: the boot payload hands it
   to the client engine so a facet change filters all the pieces, not only the
   ones this request's URL already narrowed. */
$catalogFeedAll = $catalogFeed;

/* ─── The shop's filter state, read from the query string ────────────────
   The collection's filter bar is a plain GET form, so it works with no
   JavaScript at all: every facet below has a server-side twin here, and the
   grid is rendered already filtered. main.js then takes the same controls
   over for instant client-side filtering — one markup, two engines, and the
   no-JS engine is not a stub.

   Every value is validated against the live catalogue, never trusted as
   received: an unknown category slug or an out-of-band size simply does not
   match anything rather than reaching the SQL-shaped world of the feed. A
   facet that cannot be honoured must not be able to distort the page. */
$qf = [
    'q'        => trim((string) ($_GET['f_q'] ?? '')),
    'sort'     => (string) ($_GET['f_sort'] ?? 'featured'),
    'cats'     => [],
    'sizes'    => [],
    'colors'   => [],
    'heel_min' => null,
    'heel_max' => null,
    'price_max'=> null,
    'instock'  => !empty($_GET['f_instock']),
    'deals'    => !empty($_GET['f_deals']),
    'new'      => !empty($_GET['f_new']),
];
if (!in_array($qf['sort'], ['featured','new','asc','desc','name','stock'], true)) {
    $qf['sort'] = 'featured';
}
$rawCats = (array) ($_GET['f_cat'] ?? []);
foreach ($rawCats as $c) {
    $c = (string) $c;
    if ($c !== '' && strlen($c) <= 32 && preg_match('/^[a-z]+$/', $c)) $qf['cats'][] = $c;
}
$rawSizes = (array) ($_GET['f_size'] ?? []);
foreach ($rawSizes as $s) {
    $s = (int) $s;
    if ($s >= SIZE_MIN && $s <= SIZE_MAX && !in_array((string) $s, $qf['sizes'], true)) {
        $qf['sizes'][] = (string) $s;
    }
}
$rawColors = (array) ($_GET['f_color'] ?? []);
foreach ($rawColors as $k) {
    $k = (string) $k;
    if ($k !== '' && strlen($k) <= 32 && preg_match('/^[a-z]+$/', $k) && !in_array($k, $qf['colors'], true)) {
        $qf['colors'][] = $k;
    }
}
if (isset($_GET['f_heel_min']) && is_numeric($_GET['f_heel_min'])) $qf['heel_min'] = max(0, (int) $_GET['f_heel_min']);
if (isset($_GET['f_heel_max']) && is_numeric($_GET['f_heel_max'])) $qf['heel_max'] = max(0, (int) $_GET['f_heel_max']);
if (isset($_GET['f_price'])    && is_numeric($_GET['f_price']))    $qf['price_max'] = max(0, (int) $_GET['f_price']);

/* Facets derived from the catalogue itself — the vocabulary of the shop has
   exactly one source. Counts are computed BEFORE filtering, so a chip always
   says how many pieces the maison carries in that facet, and a selection
   never makes its own option vanish from the bar. */
$facetCounts = ['cat' => [], 'color' => []];
$facetSizesSeen = [];
$heelLo = null; $heelHi = null; $priceLo = null; $priceHi = null;
foreach ($catalogFeed as $p) {
    $slug = (string) ($p['cat'] ?? '');
    if ($slug !== '') $facetCounts['cat'][$slug] = ($facetCounts['cat'][$slug] ?? 0) + 1;
    foreach ((array) ($p['colors'] ?? []) as $c) {
        $k = (string) ($c['key'] ?? '');
        if ($k !== '') { $facetCounts['color'][$k] = ($facetCounts['color'][$k] ?? 0) + 1; }
    }
    foreach ((array) ($p['sizes'] ?? []) as $s) {
        if (((int) ($s['stock'] ?? 0)) > 0) $facetSizesSeen[(int) ($s['eu'] ?? 0)] = true;
    }
    $h = (int) ($p['heel'] ?? 0);
    if ($h > 0) { $heelLo = $heelLo === null ? $h : min($heelLo, $h); $heelHi = $heelHi === null ? $h : max($heelHi, $h); }
    $pr = (int) ($p['price'] ?? 0);
    if ($pr > 0) { $priceLo = $priceLo === null ? $pr : min($priceLo, $pr); $priceHi = $priceHi === null ? $pr : max($priceHi, $pr); }
}
ksort($facetSizesSeen);
$facetSizes = array_keys($facetSizesSeen);

/* Order the colour chips by first appearance in CATEGORY-independent catalogue
   order, showing each key's Persian name from the product records themselves. */
$facetColors = [];
foreach ($catalogFeed as $p) {
    foreach ((array) ($p['colors'] ?? []) as $c) {
        $k = (string) ($c['key'] ?? '');
        $n = (string) ($c['name'] ?? '');
        if ($k !== '' && $n !== '' && !isset($facetColors[$k])) $facetColors[$k] = $n;
    }
}
$facetCats = [];
foreach ($facetCounts['cat'] as $slug => $_n) {
    $facetCats[$slug] = function_exists('velora_category_label') ? velora_category_label($slug) : $slug;
}

$heelBounds  = $heelLo  !== null ? [max(0, (int) floor($heelLo / 5) * 5), (int) ceil($heelHi / 5) * 5] : null;
$priceBounds = $priceLo !== null ? [(int) floor($priceLo / 100000) * 100000, (int) ceil($priceHi / 100000) * 100000] : null;

/* Slider positions default to the full band, so an unfiltered request renders
   handles at the ends and a filtered one round-trips its own bounds. */
if ($qf['heel_min'] === null || $qf['heel_max'] === null) {
    $qf['heel_min'] = $heelBounds[0] ?? 0;
    $qf['heel_max'] = $heelBounds[1] ?? 0;
}
if ($qf['heel_min'] > $qf['heel_max']) { $t = $qf['heel_min']; $qf['heel_min'] = $qf['heel_max']; $qf['heel_max'] = $t; }
if ($qf['price_max'] === null) $qf['price_max'] = $priceBounds[1] ?? 0;

$filtersActive = $qf['q'] !== '' || $qf['cats'] || $qf['sizes'] || $qf['colors']
              || $qf['instock'] || $qf['deals'] || $qf['new']
              || ($heelBounds && ($qf['heel_min'] > $heelBounds[0] || $qf['heel_max'] < $heelBounds[1]))
              || ($priceBounds && $qf['price_max'] < $priceBounds[1])
              || $qf['sort'] !== 'featured';

/* The server-side pass of the same predicate main.js's apply() runs. Kept
   beside each other in meaning: category OR within a facet, AND across
   facets; a size matches only when that size actually has stock. */
if ($filtersActive && $catalogFeed) {
    $catalogFeed = array_values(array_filter($catalogFeed, static function (array $p) use ($qf): bool {
        if ($qf['q'] !== ''
            && mb_stripos(($p['name'] ?? '') . ' ' . ($p['sub'] ?? '') . ' ' . ($p['desc'] ?? ''), $qf['q']) === false) {
            return false;
        }
        if ($qf['cats'] && !in_array((string) ($p['cat'] ?? ''), $qf['cats'], true)) return false;
        if ($qf['colors']) {
            $have = array_map(static fn($c): string => (string) ($c['key'] ?? ''), (array) ($p['colors'] ?? []));
            if (!array_intersect($qf['colors'], $have)) return false;
        }
        if ($qf['sizes']) {
            $ok = false;
            foreach ((array) ($p['sizes'] ?? []) as $s) {
                if (((int) ($s['stock'] ?? 0)) > 0 && in_array((string) (int) ($s['eu'] ?? 0), $qf['sizes'], true)) { $ok = true; break; }
            }
            if (!$ok) return false;
        }
        $h = (int) ($p['heel'] ?? 0);
        if ($h < (int) $qf['heel_min'] || $h > (int) $qf['heel_max']) return false;
        if ($qf['price_max'] > 0 && (int) ($p['price'] ?? 0) > (int) $qf['price_max']) return false;
        if ($qf['instock'] && (int) ($p['stock'] ?? 0) <= 0) return false;
        if ($qf['deals'] && (int) ($p['old'] ?? 0) <= (int) ($p['price'] ?? 0)) return false;
        if ($qf['new'] && empty($p['isNew'])) return false;
        return true;
    }));

    switch ($qf['sort']) {
        case 'asc':  usort($catalogFeed, static fn(array $a, array $b): int => (int) $a['price'] <=> (int) $b['price']); break;
        case 'desc': usort($catalogFeed, static fn(array $a, array $b): int => (int) $b['price'] <=> (int) $a['price']); break;
        case 'name': usort($catalogFeed, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name'])); break;
        case 'new':  usort($catalogFeed, static fn(array $a, array $b): int => ((int) !empty($b['isNew'])) <=> ((int) !empty($a['isNew']))
                                                                    ?: strcmp((string) $b['drop'], (string) $a['drop'])); break;
        case 'stock':usort($catalogFeed, static fn(array $a, array $b): int => (int) $b['stock'] <=> (int) $a['stock']); break;
    }
}

/* ─── SEO ─────────────────────────────────────────────────────────────────
   includes/seo.php is now the single source of truth for meta, OpenGraph,
   Twitter and JSON-LD. It was 261 lines of correct, documented, CSP-safe
   emitters that nothing ever required — index.php had its own weaker inline
   copy instead, missing robots directives, og:site_name, image dimensions,
   the SearchAction, the shipping and return policy, and the availability that
   reflects real stock.

   Requiring it removes the duplication rather than adding a second opinion:
   there is now exactly one place that knows what a product page says about
   itself, and it is the module that was written to be that place. */
require_once __DIR__ . '/includes/seo.php';

$isProductPage = is_array($product) && $product !== [];

/* The canonical product URL, in the one form the whole project uses:
   /?product=<id>. The storefront routes it through the hash (#/pdp/<id>) for
   instant navigation, but the address bar, the sitemap, canonical and JSON-LD
   all agree on the query form, so a shared link and a crawler see the same
   thing. */
$canonicalUrl = $isProductPage
    ? APP_URL . '/?product=' . rawurlencode((string) $product['id'])
    : APP_URL . '/';

$title = $isProductPage
    ? $product['name'] . ' | ' . SEO_BRAND
    : SEO_BRAND . ' · Maison de Chaussures — مزون کفش دست‌ساز';

$desc = $isProductPage
    ? (string) ($product['desc'] ?: $product['sub'])
    : 'مزون خصوصی کفش‌های دست‌ساز — چرم ایتالیایی، دوخت فلورانسی، دویست جفت در فصل.';

/* og:image is the product's own first photograph, and falls back to the maison
   mark. Every product currently has an empty gallery, so in practice this is
   the mark — which is real, same-origin and 512×512, rather than the dead URL
   it used to be. */
$ogImage = $isProductPage
    ? seo_image_url((string) ($product['img'] ?? ''), 1200, 630)
    : seo_brand_image();

$seoCtx = [
    'title'          => $title,
    'description'    => $desc,
    'canonical'      => $canonicalUrl,
    'og_image'       => $ogImage,
    'og_image_alt'   => $isProductPage ? (string) $product['name'] : SEO_BRAND . ' · Maison de Chaussures',
    'og_type'        => $isProductPage ? 'product' : 'website',
    /* The shop view is the same document as the home page — the maison has one
       page with sections, not a set of routes. Saying so is the honest signal,
       and it stops the crawler from treating ?view=shop as a second page. */
    'robots'         => 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
    'site_name'      => SEO_BRAND,
];

/* Structured data: Organization + WebSite always, Product on a product page,
   BreadcrumbList on a product page. Assembled as one @graph so the nodes can
   reference each other by @id instead of repeating themselves. */
$ldGraph = [
    '@context' => 'https://schema.org',
    '@graph'   => [seo_jsonld_organization(), seo_jsonld_website()],
];
if ($isProductPage) {
    $productLd = seo_jsonld_product([
        'id'      => $product['id'],
        'name'    => $product['name'],
        'desc'    => $product['desc'],
        'sub'     => $product['sub'],
        'cat'     => $product['cat'],
        'price'   => $product['price'],
        'old'     => $product['old'],
        'stock'   => $product['stock'] ?? 0,
        'gallery' => $product['gallery'] ?? [],
        /* client_shape() emits colors as ['key'=>..,'name'=>..], so the name is
           read by name. It used to be a positional $c[1], which only matched the
           shape client_shape() used to emit — the two were written together, so
           neither was obviously wrong and the coupling was invisible until the
           shape was corrected. Mapping an associative row to a positional read is
           exactly the kind of translation that makes a later change look like a
           break. */
        'colors'  => array_map(
            static fn(array $c): array => ['name' => (string) ($c['name'] ?? '')],
            $product['colors'] ?? []
        ),
        'sizes'   => $product['sizes'] ?? [],
    ]);
    $ldGraph['@graph'][] = $productLd;
    $ldGraph['@graph'][] = seo_jsonld_breadcrumb([
        ['name' => 'خانه', 'url' => APP_URL . '/'],
        ['name' => (string) $product['name'], 'url' => $canonicalUrl],
    ]);
    /* The middle crumb used to be 'فروشگاه' pointing at APP_URL . '/?view=shop'.
       index.php does not read ?view, so that URL is the same document as the
       first crumb — a BreadcrumbList whose own steps resolve to the same page,
       which is a structured-data error as well as a dead link. The maison is
       one document with sections, so the trail is Home → Product and nothing
       else. sitemap.php had the same twelve dead URLs and now has one entry. */
}

/* ─── Presentation helpers shared with the browser ──────────────────────────
   `$esc` is bound to config.php's esc() as a local closure, the same way
   $hexForColor and $product_img below are bound to their data.

   The card renderer below captures `$esc` rather than calling esc() fifteen
   times, which is the more readable of the two spellings — but the capture
   named a variable that did not exist. `use ($esc, …)` on an undefined name
   binds null, silently: no warning at the point of the mistake, only
   "Value of type null is not callable" from inside the closure at render time,
   one file and one function away from where the omission was. Aliasing the
   function here, next to the sibling helpers, keeps the binding and the thing
   it binds in the same place. */
$esc = static fn(?string $s): string => esc($s);

/* A colour key means nothing to a browser; it needs a hex. This is the same
   table data.js holds, and it is duplicated rather than imported because the
   server cannot run the client's module before it has sent HTML. The two must
   agree, so the table is short, fixed, and commented as the single place to
   change. */
$VELORA_HEX = [
    'champ' => '#b8942a', 'noir' => '#161310', 'ivory' => '#efe6d8',
    'suede' => '#a8825c', 'oxblood' => '#5a1f26', 'cognac' => '#6b3a1e',
    'pearl' => '#e8e4da', 'satin' => '#161310', 'gold' => '#d4af37',
    'patent' => '#0b0b0e',
];
$hexForColor = static function (string $key) use ($VELORA_HEX): string {
    return $VELORA_HEX[$key] ?? '#8a8a8a';
};

/* Resolve a product's primary image the same way the browser will.
 *
   Two steps, and the order matters:
 *   1. an uploaded WebP or an absolute https URL — a real photograph;
 *   2. otherwise the maison's inline SVG monogram plate.
 *
 * Step 2 used to return product_brand_image(), a 50 KB 512×512 PNG, used as the
 * product photograph for all eleven cards. That is one request, but it is a
 * 50 KB PNG on the critical path of a page whose whole point is that the
 * collection is server-rendered — and then main.js threw it away on the first
 * frame and substituted the data-URI plate that data.js draws, so the browser
 * decoded 50 KB of PNG to display something nobody kept.
 *
 * velora_product_plate() is a deliberate twin of plate() in js/data.js: same
 * viewBox, same colours, same deterministic monogram, same 640×800 (= exactly
 * the 4/5 aspect-ratio the card declares). Because both sides draw the same
 * picture from the same id, the server's card and the client's re-render are
 * byte-identical — which is what makes the claim at index.php:787 ("a hydration
 * mismatch is impossible") actually true rather than aspirational. */
if (!function_exists('velora_product_plate')) {
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
          . $fg . '" fill-opacity=".92">' . htmlspecialchars($ch, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text>'
          . '<text x="320" y="500" text-anchor="middle" font-family="Georgia,serif" font-size="30" letter-spacing="8" fill="'
          . $fg . '" fill-opacity=".62">VELORA</text>'
          . '</svg>';
        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
    }
}

$product_img = static function (array $p): string {
    $key = (string) ($p['img'] ?? '');
    if ($key !== '' && function_exists('product_image_url')) {
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
            if (function_exists('product_image_url')) {
                $u = product_image_url($g, 1200, 0, 80);
                if ($u !== '') return $u;
            }
            if (preg_match('#^https://#i', $g)) return $g;
        }
    }
    return velora_product_plate((string) ($p['id'] ?? ''), (string) ($p['name'] ?? ''));
};

/* The nonce and the CSRF token were minted at the top of this file, before
   the headers below quote the nonce. */
$isAdmin = is_admin();

/* ─── CSP ──────────────────────────────────────────────────────────────────
   script-src is 'self' plus this document's nonce, and nothing else. It used
   to whitelist cdn.jsdelivr.net and cdnjs.cloudflare.com for Lenis and GSAP;
   with both libraries removed, the two third-party origins are gone from the
   policy as well as from the page. No hash allowlist is needed because no
   inline script exists outside the nonce'd boot blocks below.

   style-src keeps a nonce for the single inline <style> in the 404 path, and
   style-src-attr stays 'unsafe-inline' because the renderers set element
   styles as they build (transform, --pill-bottom, the reveal offsets). That
   is deliberate and is the one genuinely permissive directive in the policy.

   connect-src is 'self': the storefront talks to api.php and catalog.php and
   nowhere else. img-src allows https: because product imagery may be an
   absolute URL entered by an operator. */
$csp = "default-src 'self'; "
     . "script-src 'self' 'nonce-$nonce'; "
     . "script-src-attr 'none'; "
     . "style-src 'self' 'nonce-$nonce' https://fonts.googleapis.com; "
     . "style-src-attr 'unsafe-inline'; "
     . "font-src 'self' https://fonts.gstatic.com data:; "
     . "img-src 'self' data: blob: https:; "
     . "connect-src 'self'; "
     . "frame-src 'none'; "
     . "object-src 'none'; "
     . "worker-src 'self' blob:; "
     . "manifest-src 'self'; "
     . "frame-ancestors 'none'; "
     . "base-uri 'self'; "
     . "form-action 'self';";

if (function_exists('emit_security_headers')) {
    emit_security_headers($csp, false);
} else {
    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ─── Encoders ─────────────────────────────────────────────────────────
   Every value that reaches a double-quoted attribute or a <script> body goes
   through one of these. JSON_HEX_TAG|AMP|APOS|QUOT is what makes a JSON-LD
   payload safe inside <script> without a CDATA dance: a product name
   containing "</script>" is emitted escaped and cannot close the element. */
$encodeLd = static function ($data): string {
    $j = json_encode($data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $j === false ? 'null' : $j;
};
$encodeJs = $encodeLd;   /* identical guarantees; two names for two intents */
?>
<!doctype html>
<html lang="fa" dir="rtl" data-theme="nuit" data-scene="night" data-mode="boutique">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0a0b12" id="metaTheme">
<meta name="color-scheme" content="dark light">
<meta name="format-detection" content="telephone=no">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<?php /* Title, description, canonical, robots, OpenGraph and Twitter cards.
        includes/seo.php was 261 lines of correct, documented, CSP-safe
        emitters that nothing ever required, while this file carried a weaker
        inline duplicate — no robots directive, no og:site_name, no image
        dimensions, no SearchAction, no shipping or return policy, and an
        availability that did not reflect real stock. Requiring it removed the
        duplication instead of adding a second opinion. */
echo seo_render_head($seoCtx); ?>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' fill='%23060504'/%3E%3Cpath d='M32 8L56 32L32 56L8 32Z' fill='none' stroke='%23d9b98a' stroke-width='2'/%3E%3C/svg%3E">
<link rel="icon" type="image/png" sizes="192x192" href="brand-icon-192.png">
<link rel="apple-touch-icon" sizes="180x180" href="brand-icon-180.png">
<link rel="manifest" href="manifest.json">
<?php /* The icons above were referenced for years before the files existed, so
        /favicon requests 404'd and every og:image and JSON-LD logo resolved to
        a dead URL. product_brand_image() is what the crawler reads. */ ?>

<!-- ═══════════ FONTS ═══════════
     Two families, one request.
     It used to be four — Cormorant Garamond, Markazi Text, Vazirmatn (100..900)
     and JetBrains Mono — which is four separate font files before a single
     glyph of body copy could be laid out. Two of the four were near-duplicates
     of each other in role, and the mono face is used for roughly forty small
     labels in the whole document.

     Markazi is gone: --serif resolves to it, and Cormorant — the display face
     the brand is actually set in — is listed first in that stack, so every
     rule that asked for "the serif" already got Cormorant whenever it loaded.
     It contributed nothing but a second download.

     JetBrains Mono is replaced by the platform stack already written into
     --mono, which is what --font-mono resolves to for the ~40 labels that use
     it. A monospace system face is indistinguishable at 9–12 px and costs
     zero bytes over the network.

     That is one request, two families, ~90 KB instead of ~215 KB. `swap` is
     already on, so text paints immediately in the fallback rather than
     waiting; `preconnect` is kept because it is a real saving on the
     stylesheet round trip. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300..700;1,400&family=Vazirmatn:wght@300..800&display=swap">

<?php /* ─── CSS ────────────────────────────────────────────────────────────
   Six render-blocking stylesheets, in cascade order:
     1. aurelle-tokens.css  design tokens (colours, type scale, spacing)
     2. base.css             reset + typography
     3. components.css       buttons, cards, forms, header, footer
     4. sections.css         the page's own sections
     5. dialogs.css          dialogs
     6. animations.css       keyframes

   Every URL carries ?v=<filemtime>. That is what lets .htaccess serve them
   `immutable, max-age=31536000` and lets sw.js treat a ?v= URL as a content
   validator — a changed file gets a new URL, so a stale cached copy is never
   even consulted. Without it the whole tree revalidates on every view. The
   tag costs one stat() per file per render and removes 460 KB of
   retransfer from the second visit onward. */
$assetV = static function (string $rel): string {
    $abs = __DIR__ . '/' . $rel;
    $m = @filemtime($abs);
    return $rel . ($m ? '?v=' . (int) $m : '');
};

/* ─── The asset list ────────────────────────────────────────────────────────
   One list of paths, two consumers: the <link>/<script> tags that load them and
   the precache manifest the service worker is handed. A file cannot be added to
   one and forgotten in the other, because there is only one list to add it to.

   Both orders are load-bearing and are not interchangeable:

   · CSS is emitted in cascade order, and `aurelle-tokens.css` first — it
     defines the custom properties every later file reads.
   · The scripts are in dependency order, because each is an IIFE that
     destructures its dependencies off `window` while it parses. core.js
     publishes AE, data.js reads AE, the bridge reads both, and so on. Moving
     one line here reorders two files' worth of globals and breaks boot.

   That is why the manifest is declared here rather than accumulated as the tags
   are written: the boot script that publishes it sits ABOVE the script tags, so
   anything accumulated below had not happened yet when it was emitted — and the
   first version of this did exactly that, publishing seven stylesheets and not
   one script. */
$VELORA_CSS = [
    'css/aurelle-tokens.css',
    'css/base.css',
    'css/components.css',
    'css/sections.css',
    'css/dialogs.css',
    'css/animations.css',
];
$VELORA_JS = [
    'js/core.js',
    'js/data.js',
    'js/velora-bridge.js',
    'js/renderers-aurelle.js',
    'js/state.js',
    'js/ui.js',
    'js/cart.js',
    'js/pdp-aurelle.js',
    'js/lbxaurelle.js',
    'js/checkout.js',
    'js/auth.js',
    'js/concierge-aurelle.js',
    'js/atelier-aurelle.js',
    'js/sync-aurelle.js',
    'js/main.js',
];

/* The manifest itself: the same URLs the tags use, plus the icon. The icon is
   here because manifest.json and the notification handler reference it rather
   than this document's markup, and an offline-capable install that cannot draw
   its own icon is a half-offline install. */
$precacheAssets = array_merge(
    array_map($assetV, $VELORA_CSS),
    array_map($assetV, $VELORA_JS),
    ['brand-icon-192.png']
);
?>
<link rel="preload" as="style" href="<?= $assetV($VELORA_CSS[0]) ?>">
<?php foreach ($VELORA_CSS as $__css): ?>
<link rel="stylesheet" href="<?= $assetV($__css) ?>">
<?php endforeach; ?>

<!-- ═══════════ PRE-PAINT ═══════════
     قبل از اولین paint، تم و صحنه را از localStorage بخوان تا صفحه
     از همان فریم اول درست باشد (بدون FOUC).
-->
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
(function(){
  var d = document.documentElement;
  d.classList.add('js');
  var rm = matchMedia('(prefers-reduced-motion:reduce)').matches;
  var fine = matchMedia('(pointer:fine)').matches;
  d.classList.add(rm ? 'rm' : 'mo', fine ? 'fine' : 'coarse');

  var c = navigator.connection || {};
  var mem = navigator.deviceMemory || 8;
  var cores = navigator.hardwareConcurrency || 8;
  var tier = (c.saveData || mem <= 2 || cores <= 3) ? 'low'
           : (mem <= 4 || cores <= 5) ? 'mid' : 'high';
  d.classList.add('perf-' + tier);

  function raw(k) {
    try {
      var v = localStorage.getItem(k);
      if (v === null) return null;
      if (v.charAt(0) === '"' || v.charAt(0) === '{') {
        try { v = JSON.parse(v); } catch(e) {}
      }
      return v;
    } catch(e) { return null; }
  }

  var t = raw('ae.theme.v2') || 'nuit';
  var s = raw('ae.scene.v2') || 'night';
  var m = raw('ae.mode.v2')  || 'boutique';

  d.setAttribute('data-theme', t);
  d.setAttribute('data-scene', s);
  d.setAttribute('data-mode',  m);

  var meta = document.getElementById('metaTheme');
  if (meta) {
    meta.content = t === 'ivoire' ? '#F6F2E8'
                 : t === 'emeraude' ? '#0e2020'
                 : '#0a0b12';
  }

  d.setAttribute('data-luxe', '3');
})();
</script>

<!-- ═══════════ STRUCTURED DATA ═══════════ -->
<script type="application/ld+json" nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>"><?= $encodeLd($ldGraph) ?></script>
</head>

<body class="has-ann" data-mode="boutique">
<a class="skip-link" href="#main">پرش به محتوا</a>

<?php /* ═══ THE BENT PAGE ══════════════════════════════════════════════════
   One fixed element, two pseudo-elements, one composited layer.

   It is here rather than in CSS because the edges must sit UNDER the
   dialogs and the dock but OVER the scrolling document, and there is no
   z-index band that expresses that on its own: every dialog in this document
   is a sibling of <body>'s children, and any of them could be given a higher
   stacking context later. Declaring the layer once, immediately after the
   skip link, fixes its position in the paint order for the whole page.

   It is fixed and it is two gradients and an SVG hairline. It never
   re-rasterises: there is no scroll handler, no transform that changes, and
   no property that would move it out of its own layer. The whole cost is one
   full-viewport quad that the compositor holds and never touches again, which
   is what makes an always-present effect affordable at all. */
?>
<div class="bend" aria-hidden="true"></div>

<!-- ═══════════════════════════════════════════════════════════════════
     ATMOSPHERE — لایه‌های پس‌زمینه (خالص تزئینی)
     ═══════════════════════════════════════════════════════════════════ -->
<canvas id="dust" aria-hidden="true"></canvas>
<div class="aurora" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
<div class="vig" aria-hidden="true"></div>
<div class="grain" aria-hidden="true"></div>
<div class="motes" aria-hidden="true"></div>

<!-- ═══════════════════════════════════════════════════════════════════
     CURSOR — فقط روی دستگاه‌های fine pointer
     ═══════════════════════════════════════════════════════════════════ -->
<div class="cursor" id="cursor" aria-hidden="true"><span class="lbl" id="curLab"></span></div>
<div class="cursor-dot" id="cursorDot" aria-hidden="true"></div>

<!-- ═══════════════════════════════════════════════════════════════════
     PRELOADER
     ═══════════════════════════════════════════════════════════════════ -->
<div id="pl" aria-hidden="true">
  <div class="pl-in">
    <div class="pl-seal">
      <svg viewBox="0 0 140 140" aria-hidden="true">
        <defs><path id="plPath" d="M70,70 m-50,0 a50,50 0 1,1 100,0 a50,50 0 1,1 -100,0"/></defs>
        <text class="pl-seal-txt"><textPath href="#plPath">VELORA · MAISON DE CHAUSSURES · MMXXVI ·</textPath></text>
      </svg>
      <span class="pl-core"></span>
    </div>
    <div class="pl-brand">VELORA</div>
    <div class="pl-tag">نسخهٔ ابدی — پاریس · فلورانس · توکیو</div>
    <div class="pl-bar"><i id="plBar"></i></div>
    <div class="pl-row"><span>آماده‌سازی آتلیه</span><span id="plNum">۰٪</span></div>
  </div>
</div>

<div class="prog-wrap" aria-hidden="true"><div class="prog" id="prog"></div></div>

<!-- ═══════════════════════════════════════════════════════════════════
     ANNOUNCEMENT BAR
     ═══════════════════════════════════════════════════════════════════ -->
<div class="annonce" id="annonce" data-open role="region" aria-label="اعلان‌ها">
  <div class="annonce-in">
    <span class="annonce-glyph" aria-hidden="true">◆</span>
    <div class="annonce-vp" aria-live="polite">
      <p class="annonce-msg is-live">ارسال اکسپرس رایگان به سراسر ایران — <b>همیشه</b></p>
      <p class="annonce-msg">کد <b>WELCOME10</b> — ٪۱۰ تخفیف نخستین خرید</p>
      <p class="annonce-msg">سایز سفارشی بر پایهٔ هندسهٔ <b>φ</b></p>
      <p class="annonce-msg">نسخهٔ محدود — <b>دویست جفت در فصل</b></p>
    </div>
    <button class="annonce-x" id="annonceX" type="button" aria-label="بستن">
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     SIDE RAIL — ناوبری عمودی دسکتاپ
     ═══════════════════════════════════════════════════════════════════ -->
<nav class="rail" aria-label="بخش‌ها">
  <a href="#top" data-t="خانه" aria-current="true">اُ</a>
  <a href="#boutique" data-t="فروشگاه">۰۱</a>
  <a href="#craft" data-t="صناعت">۰۲</a>
  <a href="#lookbook" data-t="نگارخانه">۰۳</a>
  <a href="#archive" data-t="تاریخچه">۰۴</a>
  <a href="#appoint" data-t="تماس">۰۵</a>
</nav>

<!-- ═══════════════════════════════════════════════════════════════════
     HEADER
     ═══════════════════════════════════════════════════════════════════ -->
<header class="hdr">
  <div class="ticker" aria-hidden="true"><div class="tk" id="tkTrack"></div></div>
  <div class="bar">
    <div class="bar-in">
      <a class="brand" href="#/" data-act="home" aria-label="VELORA">
        <i class="brand-sigil" aria-hidden="true"></i>
        <b class="brand-text">VELORA</b>
      </a>

      <div class="mode-switch" role="tablist" aria-label="حالت نمایش">
        <button class="on" data-act="mode" data-mode="boutique" role="tab" aria-selected="true" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 7h12l1.2 12H4.8L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
          <span>بوتیک</span>
        </button>
        <button data-act="mode" data-mode="atelier" role="tab" aria-selected="false" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/></svg>
          <span>آتلیه</span>
        </button>
      </div>

      <nav class="nav-links" aria-label="ناوبری اصلی">
        <a href="#boutique">مجموعه</a>
        <a href="#craft">صناعت</a>
        <a href="#lookbook">نگارخانه</a>
        <a href="#archive">تاریخچه</a>
      </nav>

      <div class="hdr-r">
        <div class="hdr-search" id="hdrSearch">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
          <label class="sr-only" for="hdrQ">جست‌وجو</label>
          <input id="hdrQ" type="search" placeholder="جست‌وجو…" autocomplete="off">
        </div>

        <button class="icon-btn" data-act="cmd" type="button" aria-label="فرمان‌پالت">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M9 3a3 3 0 1 0 0 6h12M9 3v18M15 21a3 3 0 1 1 0-6H3M15 21V3"/></svg>
        </button>

        <button class="icon-btn" id="themeT" type="button" aria-label="تغییر پوسته">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/></svg>
        </button>

        <button class="icon-btn" id="wishBtn" data-act="wish-open" type="button" aria-label="علاقه‌مندی‌ها">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 21s-7-4.5-9.5-9C.5 8 3 4 6.5 4c2 0 3.5 1 5.5 3 2-2 3.5-3 5.5-3 3.5 0 6 4 4 8-2.5 4.5-9.5 9-9.5 9Z"/></svg>
          <span class="badge none" id="wishN"></span>
        </button>

        <button class="icon-btn" id="bagBtn" data-act="cart-open" type="button" aria-label="سبد">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 7h12l1.2 12H4.8L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
          <span class="badge none" id="cartN"></span>
        </button>

        <button class="icon-btn menu-btn" data-act="menu" type="button" aria-label="منو" aria-expanded="false"><i></i><i></i></button>
      </div>
    </div>
  </div>
</header>

<!-- ═══════════════════════════════════════════════════════════════════
     THEME MENU
     ═══════════════════════════════════════════════════════════════════ -->
<div class="theme-menu" id="themeMenu" role="menu" aria-label="پوسته و صحنه">
  <div class="menu-lbl">پوسته</div>
  <button class="theme-opt" data-theme-set="nuit" role="menuitem" type="button"><span class="theme-swatch swatch-nuit"></span>شبِ طلایی</button>
  <button class="theme-opt" data-theme-set="ivoire" role="menuitem" type="button"><span class="theme-swatch swatch-ivoire"></span>عاجِ روشن</button>
  <button class="theme-opt" data-theme-set="emeraude" role="menuitem" type="button"><span class="theme-swatch swatch-emeraude"></span>زمردِ شبانه</button>
  <div class="menu-sep"></div>
  <div class="menu-lbl">صحنه</div>
  <div class="scene-row" role="group" aria-label="صحنهٔ نوری">
    <button class="scene-btn on" data-scene-set="night" type="button">شب</button>
    <button class="scene-btn" data-scene-set="dusk" type="button">عصر</button>
    <button class="scene-btn" data-scene-set="dawn" type="button">سپیده</button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     MOBILE NAV
     ═══════════════════════════════════════════════════════════════════ -->
<nav class="mnav" id="mnav" aria-label="منوی موبایل" inert>
  <a href="#top"><span class="mn-no">۰۱</span>خانه <span class="lat">HOME</span></a>
  <a href="#boutique"><span class="mn-no">۰۲</span>مجموعه <span class="lat">VAULT</span></a>
  <a href="#craft"><span class="mn-no">۰۳</span>صناعت <span class="lat">CRAFT</span></a>
  <a href="#lookbook"><span class="mn-no">۰۴</span>نگارخانه <span class="lat">LOOKBOOK</span></a>
  <a href="#archive"><span class="mn-no">۰۵</span>تاریخچه <span class="lat">ARCHIVE</span></a>
  <a href="#appoint"><span class="mn-no">۰۶</span>تماس <span class="lat">CONTACT</span></a>
  <div class="mnav-foot">
    <button class="btn btn--ghost btn--sm" data-act="cart-open" type="button">سبد <span class="mono" id="mnavCartN">۰</span></button>
    <button class="btn btn--ghost btn--sm" data-act="wish-open" type="button">علاقه‌مندی</button>
    <button class="btn btn--ghost btn--sm" data-act="mode" data-mode="atelier" type="button">آتلیه</button>
  </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════════════
     MAIN — JS محتوای view-home را در اولین فریم پر می‌کند
     ═══════════════════════════════════════════════════════════════════ -->
<main id="main">
  <!-- HOME VIEW -->
  <div class="view" id="view-home">
    <section class="hero" id="top" aria-label="صفحهٔ نخست">
      <div class="hero-grid-bg" aria-hidden="true"></div>
      <div class="hero-ghost" id="heroGhost" aria-hidden="true">φ</div>
      <div class="hero-rail" aria-hidden="true">پاییز/زمستان ۲۶ — پاریس · فلورانس · توکیو</div>

      <div class="container">
        <div class="hero-grid">
          <div class="hero-copy">
            <div class="hero-ey">
              <span class="num">مجموعهٔ ۰۱</span>
              <span class="line" aria-hidden="true"></span>
              <span>خانهٔ کفش · نسخهٔ ابدی</span>
            </div>
            <h1 id="heroTitle" aria-label="هنرِ گامِ طلایی.">
              <span class="split-line"><span class="split-line-inner">هنرِ گامِ</span></span><br>
              <span class="split-line"><span class="split-line-inner"><em>طلایی.</em></span></span>
            </h1>
            <p class="hero-sub">چرمِ کامل‌دانهٔ ایتالیایی، ابریشمِ کومو، قالبِ دست‌کشیده در فلورانس. دویست جفت در هر فصل. نه یکی بیشتر.</p>
            <div class="hero-cta">
              <a href="#boutique" class="btn btn--gold" data-cursor="ورود">
                <span>دیدن مجموعه</span>
                <svg class="arr" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
              </a>
            </div>

            <?php /* ── THE ATELIER LINE ───────────────────────────────────────
               Three figures the maison can defend out loud, set in the
               display face with tabular figures so they do not shift as they
               count up. They were absent, and their absence is why the hero
               was pure atmosphere with nothing in it a customer could hold on
               to. */
            ?>
            <dl class="hero-ledger" aria-label="خانه در یک نگاه">
              <div class="hero-ledger__i">
                <dt>دوخت دستی</dt>
                <dd><span class="num" data-count="240">۰</span> <small>کوک</small></dd>
              </div>
              <div class="hero-ledger__i">
                <dt>مهر طلایی</dt>
                <dd><span class="num" data-count="18">۰</span> <small>عیار</small></dd>
              </div>
              <div class="hero-ledger__i">
                <dt>تیراژ هر فصل</dt>
                <dd><span class="num" data-count="200">۰</span> <small>جفت</small></dd>
              </div>
            </dl>
          </div>

          <?php /* ── THE PLATE ────────────────────────────────────────────────
             A marble stage holding one shoe, with the maison's seal turning
             slowly over its shoulder.

             The shoe is an inline SVG rather than a photograph for three
             reasons that all matter here: it costs no request and cannot 404,
             it is the same vector at every density so the hero is identical on
             a 120 Hz phone and a 5K display, and it can be tinted by the theme
             so the dark maison and the ivory one are both correct.

             No product id is hard-coded. The stage draws the first active
             design the catalogue offers, resolved on the server from the same
             records the grid is rendered from — so if an operator retires that
             design, the next render shows the next one, with no code change. */
          $heroId = $catalogFeed[0]['id'] ?? '';
          $heroName = $catalogFeed[0]['name'] ?? 'ولورا';
          ?>
          <div class="hero-plate-wrap">
            <div class="hero-plate" role="img"
                 aria-label="نمایشگر آتلیه — <?= esc($heroName) ?>">
              <div class="hero-plate__grain" aria-hidden="true"></div>
              <div class="hero-plate__shoe" id="heroShoe" aria-hidden="true"></div>
              <div class="hero-plate__foot" aria-hidden="true">
                <span class="mono">ATELIER <?= esc(str_pad((string) (($catalogFeed ? array_search($heroId, array_column($catalogFeed, 'id'), true) : 0) + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                <span class="mono" dir="ltr"><?= esc($heroId !== '' ? strtoupper($heroId) : 'VELORA') ?></span>
              </div>
            </div>

            <?php /* The seal. A circular textPath on a single path element, with
               the maison's own mark at its centre. The rotation is a CSS
               animation on the <svg> — a transform the compositor owns, on a
               layer of its own, so a 28-second turn costs nothing per frame
               and nothing at all when the tab is hidden.

               prefers-reduced-motion stops it dead rather than slowing it: a
               continuously moving element is exactly what that preference is
               asking us not to ship, and a slow crawl is still motion. The seal
               itself remains, which is the point of it. */
            ?>
            <div class="seal" aria-hidden="true">
              <?php /* The ring is pinned to dir="ltr" on purpose.

                 This page is an RTL document, and an inline <svg> inherits
                 `direction` from its host — which changes how a browser lays
                 out the <text><textPath> inside it: bidi reordering flips the
                 Latin run, the letter-spacing lands on the wrong side of each
                 glyph, and the circular caption visibly breaks apart whenever
                 the house language is Persian. It "worked" before only because
                 the block happened to render in an LTR context.

                 Forcing ltr on the svg element itself makes the vector render
                 byte-for-byte the same in both languages, while the rotation
                 stays exactly one compositor transform on the <svg>. */ ?>
              <svg viewBox="0 0 160 160" class="seal__ring" dir="ltr" xml:lang="en" lang="en">
                <defs>
                  <path id="veloraSealPath"
                        d="M80 80 m-62 0 a62 62 0 1 1 124 0 a62 62 0 1 1 -124 0"/>
                </defs>
                <circle class="seal__disc" cx="80" cy="80" r="40"/>
                <text class="seal__type" direction="ltr" unicode-bidi="isolate"><textPath href="#veloraSealPath" startOffset="0" side="right">VELORA COLLECTION · MAISON DE CHAUSSURES · VELORA COLLECTION · </textPath></text>
                <path class="seal__mark" d="M80 62 96 80 80 98 64 80Z"/>
              </svg>
            </div>
          </div>
        </div>
      </div>

      <div class="scroll-hint" aria-hidden="true">
        <span class="mono">اسکرول</span>
        <span class="sh-line"></span>
      </div>
    </section>

    <!-- TICKER BAR -->
    <div class="ticker-bar" aria-hidden="true">
      <div class="ticker__track" id="tickerTrack">
        <div class="ticker__group">
          <span>نسخهٔ ابدی — پاییز/زمستان ۲۶</span><i>◆</i>
          <span>کد WELCOME10 — ٪۱۰ تخفیف</span><i>◆</i>
          <span>قالب‌گیری دستی در فلورانس</span><i>◆</i>
          <span>دویست جفت در فصل</span><i>◆</i>
        </div>
        <div class="ticker__group" aria-hidden="true">
          <span>نسخهٔ ابدی — پاییز/زمستان ۲۶</span><i>◆</i>
          <span>کد WELCOME10 — ٪۱۰ تخفیف</span><i>◆</i>
          <span>قالب‌گیری دستی در فلورانس</span><i>◆</i>
          <span>دویست جفت در فصل</span><i>◆</i>
        </div>
      </div>
    </div>

    <!-- TRUST BAR -->
    <div class="trust" aria-label="تضمین‌ها">
      <div class="container trust__in">
        <div class="trust__i">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M1 7h13v9H1zM14 10h4l3 3v3h-7zM5.5 19a1.8 1.8 0 1 0 0-3.6 1.8 1.8 0 0 0 0 3.6zM17.5 19a1.8 1.8 0 1 0 0-3.6 1.8 1.8 0 0 0 0 3.6z"/></svg>
          <div><b>ارسال اکسپرس جهانی</b><span>همیشه رایگان</span></div>
        </div>
        <div class="trust__i">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5"/></svg>
          <div><b>۳۰ روز فرصت مرجوعی</b><span>بدون استفاده</span></div>
        </div>
        <div class="trust__i">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7z"/><path d="M9 12l2 2 4-4"/></svg>
          <div><b>زیره‌دوزی مادام‌العمر</b><span>بازگشت نو</span></div>
        </div>
        <div class="trust__i">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
          <div><b>پرداخت امن</b><span>رمزنگاری‌شده</span></div>
        </div>
      </div>
    </div>

    <!-- BOUTIQUE — JS محتوای grid را پر می‌کند -->
    <section id="boutique" aria-labelledby="vaultTitle">
      <div class="container">
        <div class="shead">
          <div class="l">
            <div class="eyebrow rv"><span class="num">فروشگاه —</span><span>مجموعهٔ خانه</span></div>
            <h2 class="rv rv-d1" id="vaultTitle">فرم‌های محدود.<br><span class="gold-foil">تعداد اندک.</span></h2>
          </div>
          <div class="r">
            <p class="lede rv rv-d2">برش در تیراژ محدود و پرداخت کاملاً دستی. هر محصول یک داستان دارد.</p>
          </div>
        </div>
        <?php /* ─── THE COLLECTION ────────────────────────────────────────────
          Server-rendered from the same records the order endpoint prices
          from. This used to be an empty <div> that JavaScript filled on the
          first frame, which meant:

            · a crawler received a page whose entire commercial content was
              eleven empty divs — the catalogue existed only in a script the
              index has to choose to run;
            · the Largest Contentful Paint could not happen until 257 KB of
              JavaScript had parsed and executed, because the largest thing on
              the page did not exist before then;
            · with JavaScript disabled, or on a crawler that does not render,
              the shop was simply absent rather than merely unstyled.

          Rendering it here costs one loop over records already in memory and
          ~1 KB of HTML. main.js still owns interactivity, filtering and the
          catalogue refresh — it just no longer owns the content.

          Each card is a real <a> to ?product=<id>, so it is crawlable,
          middle-clickable, open-in-new-tab-able and keyboard-reachable without
          a single line of script. ?product= is already the canonical URL form
          (canonical_uri(), the sitemap and the JSON-LD all use it).

          The markup is deliberately the same shape cardHTML() in main.js
          produces, so a hydration mismatch is impossible: the renderer
          replaces the container wholesale on boot and the result is identical
          to what the server sent. */ ?>
        <div class="shop-grid" id="grid" role="list" aria-label="محصولات">
<?php
/* The two formatters are config.php's fa_num() and fa_pad(), which are the
   exact counterparts of core.js faNum() and faPad().

   This block used to declare a local $faNum = number_format(…) and a comment
   claiming it was "one formatter, shared with the browser, so the two cannot
   drift". It was the opposite: number_format() emits ASCII digits, the
   browser's toLocaleString('fa-IR') emits Persian ones, so every price and
   count on the first paint was in the wrong script and changed the moment a
   renderer touched the DOM. The formatters now live beside esc() in config.php
   and are verified against the runtime — see the note there. */
$cardServer = static function (array $p, int $i) use ($esc, $product_img, $hexForColor): string {
    $img = $product_img($p);
    $lowStock = ($i === 0) || ($p['stock'] <= 5);
    $badge = $p['isNew'] ? 'جدید' : ($lowStock ? 'فقط ' . fa_num($p['stock']) . ' مانده' : '');
    $sw = '';
    foreach ($p['colors'] as $c) {
        $sw .= '<span class="sw" style="--c:' . $esc($hexForColor((string) $c['key'])) . '"></span>';
    }
    return '<article class="prod rv' . ($i % 3 === 1 ? ' rv-d1' : ($i % 3 === 2 ? ' rv-d2' : '')) . '"'
         . ' data-id="' . $esc($p['id']) . '" role="listitem">'
         . '<a class="prod-media skl" href="?product=' . rawurlencode($p['id']) . '"'
         . ' data-open-pdp aria-label="' . $esc('مشاهدهٔ جزئیات — ' . $p['name']) . '">'
         . '<img src="' . $esc($img) . '" alt="' . $esc($p['name'] . ' — ' . $p['sub']) . '"'
         . ' loading="' . ($i < 2 ? 'eager' : 'lazy') . '" decoding="async"'
         . ' width="640" height="800"'
         /* The first card is the LCP element. fetchpriority tells the network
            stack to spend its first round trip on it rather than on the other
            nine images, which is the single largest LCP lever available
            without changing the design. */
         . ($i === 0 ? ' fetchpriority="high"' : '') . '>'
         . ($badge !== '' ? '<span class="pl-badge' . ($p['isNew'] ? ' hot' : ' low') . '">' . $esc($badge) . '</span>' : '')
         . '<span class="pl-num">فرم ' . fa_pad($i + 1) . '</span>'
         . '<span class="prod-line" aria-hidden="true"></span></a>'
         . '<button class="wish" type="button" aria-label="' . $esc('ذخیرهٔ ' . $p['name']) . '" aria-pressed="false"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-7.5-4.7-10-9.3C.4 8.6 2.4 5 6 5c2.2 0 3.6 1.2 4.4 2.6h1.2C12.4 6.2 13.8 5 16 5c3.6 0 5.6 3.6 4 7.2C19.5 16.3 12 21 12 21z"/></svg></button>'
         /* The compare button used to sit here. It was removed rather than
            fixed: its entire UI — #cmpBar, #cmpThumbs, #cmpDialog, #cmpGrid,
            #cmpRadar — has no markup anywhere in the document, so the button
            was a visible control that did nothing. Shipping a dead control on
            all eleven cards is worse than shipping no control. The CSS is gone
            too (.cmp-toggle in components.css, .cmp-* in dialogs.css). */
         . '<div class="prod-info">'
         . '<div class="p-top"><span class="p-cat">' . $esc(velora_category_label((string) $p['cat'])) . '</span>'
         . '<span class="p-price">' . $esc(fa_num($p['price'])) . ' تومان</span></div>'
         . '<h3 class="p-name"><a href="?product=' . rawurlencode($p['id']) . '" data-open-pdp>' . $esc($p['name']) . '</a></h3>'
         . '<div class="p-meta"><span class="rate-row"><span class="stars" aria-hidden="true">'
         . str_repeat('<span class=""><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg></span>', 5)
         . '</span></span>'
         . '<span class="stock-pill' . ($p['stock'] <= 5 ? ' low' : '') . '"><span class="dot" aria-hidden="true"></span>'
         . ($p['stock'] <= 5 ? 'فقط ' . $esc(fa_num($p['stock'])) . ' مانده' : 'موجود') . '</span></div>'
         . '<p class="p-sub">' . $esc($p['sub']) . '</p>'
         . '<div class="p-row"><div class="p-sw" aria-hidden="true">' . $sw . '</div>'
         . '<span class="mono" style="font-size:.56rem">' . $esc(implode(' تا ', SIZE_BAND)) . '</span></div>'
         /* Two actions per card, one job each:
            · quick-buy opens the size & colour sheet — the whole purchase
              path without leaving the grid (js: cart.js openQuickBuy);
            · view is a real anchor to the product page — crawlable and
              middle-clickable, exactly like the media tile above it. */
         . '<div class="p-acts">'
         . '<button class="quick qs" type="button" data-qs aria-label="' . $esc('خرید سریع — ' . $p['name']) . '">'
         . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M13 2 4.5 13.5H11L9.5 22 19 10h-6l1-8z"/></svg>'
         . '<span class="quick-t">خرید سریع</span></button>'
         . '<a class="quick view" href="?product=' . rawurlencode($p['id']) . '" data-open-pdp aria-label="' . $esc('مشاهدهٔ ' . $p['name']) . '">مشاهده</a>'
         . '</div>'
         . '</div></article>';
};
if ($catalogFeed) {
    foreach ($catalogFeed as $i => $p) {
        echo $cardServer($p, $i);
    }
    /* ─── The empty result ────────────────────────────────────────────────
       A filter that matches nothing has to say so.

       This is the state the grid reaches most often and it used to reach it
       silently: a header search for a word that matches no shoe hid all eleven
       cards and left the customer looking at an empty boutique with no
       indication that anything had been done. The house has no products, and
       the maison has eleven. Those are very different facts and the page
       conflated them.

       It is server-rendered rather than built by apply() so that the first
       paint of an unfiltered grid is not carrying an element that is only ever
       shown later, and so the message is in the document for a crawler and for
       a customer who never runs any of the scripts.

     The count line is not decoration either: "how many of how many" is the
     question a customer asks when a filter surprises them, and answering it
     removes the guesswork from every filtered view rather than only the empty
     one. Both are filled in by main.js, which is the only thing that knows how
     many cards are currently visible; the server can only say how many exist. */
    echo '<p class="grid-empty" id="gridEmpty" hidden role="status"></p>';
    echo '<p class="grid-count" id="gridCount" aria-live="polite"></p>';
} else {
    /* A catalogue that cannot be read is an operator problem, and it must not
       look like an empty boutique. This is stated plainly on the page rather
       than being hidden behind a spinner. The reason is in the error log. */
    echo '<p class="p-lede" style="grid-column:1/-1">ویترین موقتاً در دسترس نیست. لطفاً کمی بعد سر بزنید.</p>';
}
?>
        <?php /* ─── THE FILTER BAR ────────────────────────────────────────────
          Server-rendered facets, derived from the very records the grid above
          is drawn from — never a hand-written option list. This is the same
          rule CATEGORY_SLUGS follows in config.php: the vocabulary of the shop
          has exactly one source, and it is the catalogue itself. A facet built
          from a literal array is a facet that silently offers colours and
          sizes the maison stopped carrying.

          The bar is progressively enhanced by main.js (js/filters-aurelle.js):
          with no JavaScript every control degrades to a plain GET submission
          (?f_cat / ?f_size …) and index.php filters $catalogFeed with it
          server-side before the cards are echoed — so the storefront's
          filtering works for a crawler and for a customer who never runs any
          of the scripts. With JavaScript the same controls re-draw the grid
          instantly, and the <noscript>-free submit button hides itself. */ ?>
        <div class="vault__tools" id="vTools" role="search" aria-label="فیلترهای مجموعه">
          <form class="vf" id="vForm" method="get" action="<?= $esc(APP_URL . '/' === substr(APP_URL, -1) ? APP_URL : APP_URL . '/') ?>">
            <div class="vf-row vf-row--main">
              <label class="vf-field">
                <span class="vf-lbl">مرتب‌سازی</span>
                <select class="vf-select" id="vSort" name="f_sort">
                  <option value="featured" <?= ($qf['sort'] ?? '') === 'featured' || !isset($qf['sort']) ? 'selected' : '' ?>>ویژهٔ خانه</option>
                  <option value="new"      <?= ($qf['sort'] ?? '') === 'new' ? 'selected' : '' ?>>جدیدترین</option>
                  <option value="asc"      <?= ($qf['sort'] ?? '') === 'asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
                  <option value="desc"     <?= ($qf['sort'] ?? '') === 'desc' ? 'selected' : '' ?>>گران‌ترین</option>
                  <option value="name"     <?= ($qf['sort'] ?? '') === 'name' ? 'selected' : '' ?>>نام (الفبا)</option>
                  <option value="stock"    <?= ($qf['sort'] ?? '') === 'stock' ? 'selected' : '' ?>>آمادهٔ ارسال</option>
                </select>
              </label>
              <label class="vf-field">
                <span class="vf-lbl">جست‌وجو در مجموعه</span>
                <input class="vf-input" id="vSearch" name="f_q" type="search" inputmode="search" autocomplete="off"
                       placeholder="نام، فرم یا جنس…" value="<?= $esc($qf['q'] ?? '') ?>">
              </label>
              <button class="btn btn--gold btn--sm vf-apply" type="submit">اعمال</button>
              <a class="vf-reset linklike" id="vReset" href="<?= $esc((string) parse_url(APP_URL, PHP_URL_PATH) ?: '/') ?>" <?= $filtersActive ? '' : 'hidden' ?>>پاک کردن فیلترها</a>
            </div>

            <?php if ($facetCats): ?>
            <fieldset class="vf-group">
              <legend class="vf-lbl">فرم</legend>
              <div class="vf-chips" id="vCats" role="group" aria-label="دسته‌بندی">
                <?php foreach ($facetCats as $slug => $label): ?>
                <label class="chip chip--check<?= in_array($slug, $qf['cats'] ?? [], true) ? ' on' : '' ?>"
                       data-cat="<?= $esc($slug) ?>">
                  <input type="checkbox" name="f_cat[]" value="<?= $esc($slug) ?>"
                         <?= in_array($slug, $qf['cats'] ?? [], true) ? 'checked' : '' ?>>
                  <span><?= $esc($label) ?></span><small data-count><?= fa_num($facetCounts['cat'][$slug] ?? 0) ?></small>
                </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <?php endif; ?>

            <?php if ($facetSizes): ?>
            <fieldset class="vf-group">
              <legend class="vf-lbl">سایز (موجود)</legend>
              <div class="vf-chips" id="vSizes" role="group" aria-label="سایز">
                <?php foreach ($facetSizes as $sz): ?>
                <label class="chip chip--check<?= in_array((string) $sz, $qf['sizes'] ?? [], true) ? ' on' : '' ?>"
                       data-size="<?= (int) $sz ?>">
                  <input type="checkbox" name="f_size[]" value="<?= (int) $sz ?>"
                         <?= in_array((string) $sz, $qf['sizes'] ?? [], true) ? 'checked' : '' ?>>
                  <span><?= fa_num($sz) ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <?php endif; ?>

            <?php if ($facetColors): ?>
            <fieldset class="vf-group">
              <legend class="vf-lbl">رنگ</legend>
              <div class="vf-chips" id="vColors" role="group" aria-label="رنگ">
                <?php foreach ($facetColors as $key => $cname): ?>
                <label class="chip chip--color<?= in_array($key, $qf['colors'] ?? [], true) ? ' on sel' : '' ?>"
                       data-color="<?= $esc($key) ?>" title="<?= $esc($cname) ?>">
                  <input type="checkbox" name="f_color[]" value="<?= $esc($key) ?>"
                         <?= in_array($key, $qf['colors'] ?? [], true) ? 'checked' : '' ?>>
                  <span class="sw" style="--c:<?= $esc($hexForColor($key)) ?>" aria-hidden="true"></span>
                  <span><?= $esc($cname) ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <?php endif; ?>

            <div class="vf-row vf-row--range">
              <?php if ($heelBounds !== null): ?>
              <label class="vf-field">
                <span class="vf-lbl">ارتفاع پاشنه <em class="mono" id="vHeelOut"><?= fa_num((int) $qf['heel_min']) ?>–<?= fa_num((int) $qf['heel_max']) ?> میلی‌متر</em></span>
                <span class="vf-range">
                  <input class="vf-slider" id="vHeelMin" name="f_heel_min" type="range"
                         min="<?= (int) $heelBounds[0] ?>" max="<?= (int) $heelBounds[1] ?>" step="5"
                         value="<?= (int) $qf['heel_min'] ?>" aria-label="حداقل ارتفاع پاشنه">
                  <input class="vf-slider" id="vHeelMax" name="f_heel_max" type="range"
                         min="<?= (int) $heelBounds[0] ?>" max="<?= (int) $heelBounds[1] ?>" step="5"
                         value="<?= (int) $qf['heel_max'] ?>" aria-label="حداکثر ارتفاع پاشنه">
                </span>
              </label>
              <?php endif; ?>
              <?php if ($priceBounds !== null): ?>
              <label class="vf-field">
                <span class="vf-lbl">سقف قیمت <em class="mono" id="vPriceOut"><?= fa_num((int) round($qf['price_max'] / 1000000)) ?> میلیون تومان</em></span>
                <input class="vf-slider vf-slider--wide" id="vPrice" name="f_price" type="range"
                       min="<?= (int) $priceBounds[0] ?>" max="<?= (int) $priceBounds[1] ?>" step="100000"
                       value="<?= (int) $qf['price_max'] ?>" aria-label="حداکثر قیمت">
              </label>
              <?php endif; ?>
              <span class="vf-switches">
                <label class="chip chip--check<?= !empty($qf['instock']) ? ' on' : '' ?>">
                  <input type="checkbox" name="f_instock" value="1" <?= !empty($qf['instock']) ? 'checked' : '' ?>>
                  <span>فقط موجود</span>
                </label>
                <label class="chip chip--check<?= !empty($qf['deals']) ? ' on' : '' ?>">
                  <input type="checkbox" name="f_deals" value="1" <?= !empty($qf['deals']) ? 'checked' : '' ?>>
                  <span>تخفیف‌دار</span>
                </label>
                <label class="chip chip--check<?= !empty($qf['new']) ? ' on' : '' ?>">
                  <input type="checkbox" name="f_new" value="1" <?= !empty($qf['new']) ? 'checked' : '' ?>>
                  <span>جدید این فصل</span>
                </label>
              </span>
            </div>
            <div class="vf-pills" id="activePills" aria-live="polite"></div>
          </form>
        </div>
      </div>
    </section>

    <!-- CRAFT -->
    <section id="craft" aria-labelledby="craftTitle">
      <div class="container">
        <span class="ghost" aria-hidden="true">۶۸</span>
        <div class="shead">
          <div class="l">
            <div class="eyebrow rv"><span class="num">صناعت —</span><span>پنج پردهٔ دست</span></div>
            <h2 class="rv rv-d1" id="craftTitle">از پوست خام<br>تا <span class="gold-foil">یادگار</span></h2>
          </div>
        </div>
        <div class="ledger-stack rv" role="list" aria-label="پنج پردهٔ دست">
          <article class="l-card" role="listitem">
            <span class="l-num" aria-hidden="true">I</span>
            <div class="l-body"><h3>برش</h3><span class="l-sub">یک طاق · یک تیغ</span><p>بافت چرم تصمیم می‌گیرد. هر طاق با دست در امتداد فشرده‌ترین خطش برش می‌خورد.</p></div>
          </article>
          <article class="l-card" role="listitem">
            <span class="l-num" aria-hidden="true">II</span>
            <div class="l-body"><h3>قالب‌گیری</h3><span class="l-sub">۲۱ روز استراحت</span><p>رویه روی قالب آرشیوی کشیده می‌شود و سه هفته استراحت می‌کند.</p></div>
          </article>
          <article class="l-card" role="listitem">
            <span class="l-num" aria-hidden="true">III</span>
            <div class="l-body"><h3>دوخت</h3><span class="l-sub">۹ بخیه در سانتی‌متر</span><p>زیره دوخته می‌شود، نه چسبانده، به زیرهٔ چرمی گیاهی دباغی‌شده.</p></div>
          </article>
          <article class="l-card" role="listitem">
            <span class="l-num" aria-hidden="true">IV</span>
            <div class="l-body"><h3>پرداخت</h3><span class="l-sub">شیشه و موم زنبور</span><p>شیشه، موم زنبور عسل، صبر. پاشنه سوار و تا میلی‌متر بالانس می‌شود.</p></div>
          </article>
          <article class="l-card" role="listitem">
            <span class="l-num" aria-hidden="true">V</span>
            <div class="l-body"><h3>قدم آخر</h3><span class="l-sub">یک بار روی مرمر</span><p>هر جفت تکمیل‌شده یک بار کف مرمرین آتلیه را طی می‌کند — در سکوت.</p></div>
          </article>
        </div>
      </div>
    </section>

    <!-- LOOKBOOK -->
    <section id="lookbook" aria-labelledby="lbTitle">
      <div class="container">
        <div class="shead">
          <div class="l">
            <div class="eyebrow rv"><span class="num">نگارخانه —</span><span>نگارخانه</span></div>
            <h2 class="rv rv-d1" id="lbTitle">قدم‌هایی در <span class="gold-foil">طلا</span></h2>
          </div>
        </div>
        <div class="lb-rail rv" id="lbRail" tabindex="0" aria-label="گالری نگارخانه"></div>
      </div>
    </section>

    <!-- ARCHIVE -->
    <section id="archive" aria-labelledby="arcTitle">
      <div class="container">
        <span class="ghost" aria-hidden="true">۲۰۲۴</span>
        <div class="shead">
          <div class="l">
            <div class="eyebrow rv"><span class="num">تاریخچه —</span><span>تاریخچهٔ خانه</span></div>
            <h2 class="rv rv-d1" id="arcTitle">یک مسیر،<br>یک <span class="gold-foil">خط</span></h2>
          </div>
        </div>
        <div class="arc">
          <div class="arc-i rv">
            <span class="arc-year">۲۰۲۴</span>
            <div class="arc-node"><i aria-hidden="true"></i></div>
            <div><h3 class="arc-t">اولین قالب</h3><p class="arc-d">پاریس، شمارهٔ ۷ خیابان دو لا پکس. قالب آرشیوی از چوب راش.</p></div>
          </div>
          <div class="arc-i rv">
            <span class="arc-year">۲۰۲۵</span>
            <div class="arc-node"><i aria-hidden="true"></i></div>
            <div><h3 class="arc-t">نخ طلایی</h3><p class="arc-d">برنج خالص با آبکاری طلای شامپاینی ۱۸ عیار، تنها فلز مجاز خانه.</p></div>
          </div>
          <div class="arc-i rv">
            <span class="arc-year">۲۰۲۶</span>
            <div class="arc-node"><i aria-hidden="true"></i></div>
            <div><h3 class="arc-t">گذرنامهٔ دیجیتال</h3><p class="arc-d">هر محصول صاحب گذرنامهٔ دیجیتال می‌شود — گواهی گیوشه با شمارهٔ محصول.</p></div>
          </div>
        </div>
      </div>
    </section>

    <!-- CONTACT -->
    <section class="contact-section" id="appoint" aria-labelledby="apTitle">
      <div class="container">
        <span class="ghost" aria-hidden="true">اُ</span>
        <div class="c-grid">
          <div>
            <div class="eyebrow rv"><span class="num">تماس —</span><span>راه‌های ارتباطی</span></div>
            <h2 class="c-big rv rv-d1" id="apTitle">قدمِ امضای<br><span class="gold-foil">خود</span> را پیدا کنید</h2>
            <a class="c-mail rv rv-d2" href="mailto:concierge@velora.maison">
              <span>concierge@velora.maison</span>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M7 17L17 7M9 7h8v8"/></svg>
            </a>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- ATELIER VIEW (hidden by default) -->
  <div class="view" id="view-atelier" hidden inert>
    <div class="container atelier-view">
      <div id="at-home">
        <article class="at-card rv">
          <div class="at-greet">
            <div>
              <p class="kicker accent-txt">آتلیه · پاریس · نسخهٔ ابدی</p>
              <h1 class="at-title">کارگاه امروز، <em class="gold-foil">زنده</em></h1>
            </div>
            <div class="at-ava" aria-hidden="true">اُ</div>
          </div>
        </article>
      </div>
    </div>
  </div>
</main>

<!-- ═══════════════════════════════════════════════════════════════════
     FOOTER
     ═══════════════════════════════════════════════════════════════════ -->
<footer id="mainFooter">
  <div class="container">
    <div class="f-top">
      <div class="f-col">
        <p class="f-tag">VELORA — مزون کفش دست‌کشیده در شمارگان کوچک. زمین نباید صرفاً روی آن راه رفت، بلکه باید با آن گفتگو کرد.</p>
        <a href="#top" class="f-mark" data-act="home">
          <span class="dot" aria-hidden="true"></span>
          <span class="name">VELORA</span>
        </a>
        <div class="f-phi">φ = ۱.۶۱۸۰۳۳۹۸۸۷۴۹۸۹۵ — تناسب خانه</div>
      </div>
      <nav class="f-col" aria-label="نقشهٔ سایت">
        <h4>نقشهٔ سایت</h4>
        <a href="#boutique">فروشگاه</a>
        <a href="#craft">مراحل ساخت</a>
        <a href="#lookbook">نگارخانه</a>
        <a href="#archive">تاریخچه</a>
        <a href="#appoint">تماس</a>
      </nav>
      <nav class="f-col" aria-label="حساب">
        <h4>حساب کاربری</h4>
        <button data-act="auth" type="button">ورود / ثبت‌نام</button>
        <button data-act="wish-open" type="button">علاقه‌مندی‌ها</button>
        <button data-act="cart-open" type="button">سبد خرید</button>
      </nav>
      <nav class="f-col" aria-label="مراقبت">
        <h4>مراقبت خانه</h4>
        <a href="#craft">صناعت</a>
        <a href="#appoint">تماس</a>
      </nav>
    </div>
    <div class="f-seals" aria-label="نشان‌های اعتماد">
      <span class="f-seal">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
        پرداخت امن SSL
      </span>
      <span class="f-seal">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 7h13v9H1zM14 10h4l3 3v3h-7z"/></svg>
        ارسال اکسپرس رایگان
      </span>
      <span class="f-seal">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5"/></svg>
        ۳۰ روز مرجوعی
      </span>
    </div>
    <div class="f-giant" aria-hidden="true">VELORA</div>
    <div class="f-bot">
      <span class="mono">© ۲۰۲۶ VELORA — Maison de Chaussures</span>
      <div class="f-legal">
        <a href="#top">حریم خصوصی</a>
        <a href="#top">شرایط</a>
      </div>
      <span class="mono">دست‌کشیده با دقت · پاریس / فلورانس / توکیو</span>
    </div>
  </div>
</footer>

<!-- ═══════════════════════════════════════════════════════════════════
     DIALOGS — کانتینرهای خالی، JS محتوا را پر می‌کند
     ═══════════════════════════════════════════════════════════════════ -->

<!-- PDP -->
<dialog class="pdp" id="pdp" aria-labelledby="pdpName" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="pdp__bar">
    <button class="pdp__back" id="pdpBack" type="button">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
      <span>بازگشت</span>
    </button>
    <button class="close-btn" id="pdpX" type="button" aria-label="بستن">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="container"><nav class="pdp__crumbs" aria-label="مسیر">
    <a id="crumbHome" href="#top">خانه</a><i class="sep">◆</i>
    <a id="crumbShop" href="#boutique">فروشگاه</a><i class="sep">◆</i>
    <span id="pdpCrumbCat">—</span><i class="sep">◆</i>
    <span id="pdpCrumbName" aria-current="page">—</span>
  </nav></div>
  <div class="pdp__grid container">
    <div>
      <div class="pdp__stage" id="pdpStage" tabindex="0" role="button" aria-label="تصویر محصول — برای بزرگ‌نمایی کلید Enter را بزنید">
        <img id="pdpImg" alt="" decoding="async" fetchpriority="high" width="800" height="1000">
        <span class="pdp-badge" id="pdpBadge"></span>
      </div>
      <div class="pdp__thumbs" id="pdpThumbs" role="group" aria-label="گالری"></div>
    </div>
    <div class="pdp__info" id="pdpInfo"></div>
  </div>
  <div class="pdp__sticky" id="pdpSticky"></div>
</dialog>

<!-- BAG -->
<dialog class="bag" id="bag" aria-labelledby="bagTitle" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="bag__head">
    <div class="bag__title">
      <span id="bagTitle">سبد شما</span>
      <span class="bag__count" id="bagCountD">۰۰</span>
    </div>
    <button class="close-btn" id="bagClose" type="button" aria-label="بستن">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="bag__items" id="bagItems"></div>
  <div class="bag__empty" id="bagEmpty">
    <i aria-hidden="true"></i>
    <p>سبد شما خالی است — فروشگاه منتظر است.</p>
    <a href="#boutique" class="btn btn--ghost" id="emptyShop"><span>مشاهدهٔ مجموعه</span></a>
  </div>
  <div class="bag__foot">
    <div class="bag__row"><span class="bag__row-l">جمع جزء</span><span class="bag__row-v" id="subTotal">۰ تومان</span></div>
    <!-- The "شامل مالیات" row is gone. api.php's total is
         ($subtotal - $discount) + SHIPPING_FLAT, and SHIPPING_FLAT is 0 — there
         is no VAT term in the price at all. The row used to divide the total by
         1.2, which implies a tax that is included and that no system in this
         project computes. A figure nobody can reconcile is not a reassurance. -->
    <div class="bag__row"><span class="bag__row-l">ارسال اکسپرس</span><span class="bag__row-s bag__row-s--free">رایگان</span></div>
    <p class="bag__eta" id="etaLine"></p>
    <button class="btn btn--gold btn--block" id="checkout" type="button">
      <span>ادامه به پرداخت</span>
    </button>
  </div>
</dialog>

<!-- WISHLIST -->
<dialog class="wishd" id="wishd" aria-labelledby="wishTitle" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="bag__head">
    <div class="bag__title">
      <span id="wishTitle">علاقه‌مندی‌های شما</span>
      <span class="bag__count" id="wishCountD">۰۰</span>
    </div>
    <button class="close-btn" id="wishClose" type="button" aria-label="بستن">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="bag__items" id="wishItems"></div>
  <div class="bag__empty" id="wishEmpty">
    <i aria-hidden="true"></i>
    <p>هنوز چیزی ذخیره نشده — قلب روی هر فرم را لمس کنید.</p>
    <a href="#boutique" class="btn btn--ghost" id="wishEmptyShop"><span>مشاهدهٔ مجموعه</span></a>
  </div>
  <div class="bag__foot">
    <button class="btn btn--gold btn--block" id="wishAll" type="button" disabled>
      <span>افزودن همه به سبد · سایز ۳۸</span>
    </button>
  </div>
</dialog>

<!-- SIZE GUIDE -->
<dialog class="sg" id="sg" aria-label="راهنمای سایز" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="sg__inner" id="sgInner"></div>
</dialog>

<!-- MEASURE WIZARD -->
<dialog class="mz" id="mz" aria-labelledby="mzT" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="mz__in" id="mzInner"></div>
</dialog>

<!-- PROFILE -->
<dialog class="profile" id="profile" aria-labelledby="profT" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="prof__in" id="profInner"></div>
</dialog>

<!-- CHECKOUT -->
<dialog class="cko" id="cko" aria-labelledby="ckoT" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="cko__in" id="ckoInner"></div>
</dialog>

<!-- COMMAND PALETTE -->
<dialog class="cmdk" id="cmdk" aria-label="فرمان‌پالت" aria-modal="true">
  <div class="cmdk__bar" role="search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    <!-- role="combobox" with aria-expanded / aria-controls / aria-autocomplete,
         and aria-activedescendant maintained by main.js.

         The wrapper said role="search" and the input said nothing, which left a
         listbox below it that no control owned: a screen reader had no way to
         know the list was driven by this field, how many options it held, or
         which one the arrow keys had reached. This is the standard combobox
         shape, and it is the whole of what makes the palette usable without a
         mouse.

         aria-autocomplete="list" is the honest value — this filters a list of
         commands and products, it does not complete a value inline. -->
    <input id="cmdkInput" type="text" placeholder="جست‌وجو یا فرمان…" autocomplete="off"
           role="combobox" aria-expanded="false" aria-controls="cmdkList"
           aria-autocomplete="list" aria-label="فرمان">
    <kbd class="cmdk-kbd">ESC</kbd>
  </div>
  <ul class="cmdk__list" id="cmdkList" role="listbox" aria-label="فرمان‌ها و محصولات"></ul>
</dialog>

<!-- LIGHTBOX
     The full-bleed gallery viewer. This dialog, its close button and its CSS
     (.lbx, .lbx__nav, .lbx__prev, .lbx__next, .lbx__bar and the lbxIn entrance
     animation) were all written and shipped; nothing ever opened it, and
     #lbxX had no listener in any file. The nav buttons and the counter that
     the stylesheet was already written for were not in the markup at all.

     A maison sells a photograph before it sells a shoe, and this document's
     own argument is that the product is presented over the collection rather
     than on a page of its own — which means the largest possible view of the
     photograph is the one interaction the whole layout was built to make room
     for. The styles exist; this is the element they were drawn for.

     Two views is the threshold at which the arrows and the counter earn their
     space. With one, both are hidden rather than disabled, so a product without
     photography shows a clean single image and a product with a gallery shows a
     viewer that says which image you are on. -->
<dialog class="lbx" id="lbx" aria-label="گالری تصاویر" aria-modal="true">
  <button class="close-btn lbx__x" id="lbxX" type="button" aria-label="بستن گالری">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <button class="lbx__nav lbx__prev" id="lbxPrev" type="button" aria-label="تصویر پیشین" hidden>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M15 5l-7 7 7 7"/></svg>
  </button>
  <button class="lbx__nav lbx__next" id="lbxNext" type="button" aria-label="تصویر بعدی" hidden>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M9 5l7 7-7 7"/></svg>
  </button>
  <img id="lbxImg" src="" alt="" decoding="async" width="1400" height="1750">
  <div class="lbx__bar" id="lbxBar" hidden>
    <span class="mono" id="lbxCount" aria-live="polite" aria-atomic="true"></span>
    <span class="mono" id="lbxName"></span>
  </div>
</dialog>

<!-- SHEET (quick-buy: size + colour picker) -->
<dialog class="sheet" id="sheet" aria-labelledby="sheetT" aria-modal="true">
  <div class="lux-grab" aria-hidden="true"></div>
  <div class="sheet__head">
    <span class="sheet__title" id="sheetT">خرید سریع</span>
    <button class="close-btn sheet__x" id="sheetX" type="button" aria-label="بستن">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="sheet__body">
    <!-- Thumb + name + price, filled by openQuickBuy() in cart.js. The row is
         static markup so the sheet never opens as an empty shell while the
         catalogue is still resolving. -->
    <div class="qs-prod" id="sheetProd" hidden>
      <img id="sheetImg" src="" alt="" width="72" height="90" decoding="async">
      <div class="qs-prod__meta">
        <b id="sheetName"></b>
        <span id="sheetSub" class="qs-sub"></span>
        <span id="sheetPrice" class="qs-price mono"></span>
      </div>
    </div>
    <div class="qs-group" id="sheetColorWrap" hidden>
      <p class="qs-lbl">رنگ <span class="qs-lbl-v" id="sheetColorName" aria-live="polite"></span></p>
      <div class="qs-colors" id="sheetColors" role="radiogroup" aria-label="انتخاب رنگ"></div>
    </div>
    <div class="qs-group">
      <p class="qs-lbl">سایز <span class="qs-lbl-hint">(EU)</span></p>
      <div class="sizes" id="sheetSizes" role="radiogroup" aria-label="انتخاب سایز"></div>
    </div>
    <p class="mono sheet__conv" id="sheetConv"></p>
    <div class="qs-foot">
      <button class="btn btn--gold qs-add" id="sheetAdd" type="button" disabled>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 7h12l1.2 12H4.8L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
        <span id="sheetAddT">افزودن به سبد</span>
      </button>
      <a class="linklike qs-more" id="sheetMore" href="#">جزئیات کامل محصول ↗</a>
    </div>
  </div>
</dialog>

<!-- NOT FOUND -->
<dialog class="nf" id="nf" aria-labelledby="nfT" aria-modal="true">
  <span class="nf__ae" aria-hidden="true">اُ</span>
  <h3 id="nfT">این راهرو وجود ندارد.</h3>
  <p>خانه فقط اتاق‌های شماره‌گذاری‌شده نگه می‌دارد.</p>
  <div class="nf__cta">
    <a class="btn btn--gold" href="#top" id="nfTop"><span>بازگشت به خانه</span></a>
    <a class="btn btn--ghost" href="#boutique"><span>ورود به فروشگاه</span></a>
  </div>
</dialog>

<!-- ═══════════════════════════════════════════════════════════════════
     FLOATING
     ═══════════════════════════════════════════════════════════════════ -->

<!-- Chat FAB -->
<button class="chat-fab" id="concFab" type="button" aria-label="کنسیژ">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 12a8 8 0 0 1-8 8H5l-2 2V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8z"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01"/></svg>
  <span class="chat-fab__pulse" aria-hidden="true"></span>
</button>

<!-- Concierge chat -->
<div class="conc" id="conc" role="dialog" aria-modal="false" aria-label="گفتگو با کنسیژ">
  <div class="conc-h">
    <div class="conc-av" aria-hidden="true">اُ</div>
    <div>
      <b>اُتا — کنسیژ ابدی</b>
      <small><span class="live-dot" aria-hidden="true"></span>آنلاین · هوش اختصاصی</small>
    </div>
    <button class="conc-x" data-act="conc-close" type="button" aria-label="بستن">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="conc-body" id="concBody"></div>
  <div class="cchips" id="concChips"></div>
  <div class="conc-in">
    <label class="sr-only" for="concInput">پیام</label>
    <input id="concInput" placeholder="از اُتا بپرسید…" autocomplete="off">
    <button data-act="conc-send" type="button" aria-label="ارسال">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </button>
  </div>
</div>

<!-- Back to top -->
<button id="toTop" type="button" aria-label="بازگشت به بالا">
  <div class="prog-ring" aria-hidden="true"></div>
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
</button>

<!-- Dock (mobile) -->
<nav class="dock" id="dock" aria-label="ناوبری موبایل">
  <a class="dock-a" href="#top" aria-label="خانه">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3z"/></svg>
  </a>
  <a class="dock-a" href="#boutique" aria-label="فروشگاه">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 7h16l-1.5 13h-13z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
  </a>
  <button class="dock-a" id="dockWish" type="button" aria-label="علاقه‌مندی">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-7.5-4.7-10-9.3C.4 8.6 2.4 5 6 5c2.2 0 3.6 1.2 4.4 2.6h1.2C12.4 6.2 13.8 5 16 5c3.6 0 5.6 3.6 4 6.7C19.5 16.3 12 21 12 21z"/></svg>
  </button>
  <button class="dock-a" id="dockBag" type="button" aria-label="سبد">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 7h12l1.5 13.5a1 1 0 0 1-1 1.1H5.5a1 1 0 0 1-1-1.1L6 7z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
    <span class="dock-n" id="dockN" hidden>۰</span>
  </button>
  <button class="dock-a" id="dockProf" type="button" aria-label="حساب">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>
  </button>
</nav>

<!-- Toasts -->
<div id="toasts" role="status" aria-live="polite" aria-atomic="true"></div>
<div id="p2toast" role="status" aria-live="polite">
  <i class="p2g" aria-hidden="true"></i>
  <span class="p2tx"></span>
</div>
<span class="sr-only" role="status" aria-live="polite" id="bagLive"></span>

<!-- ═══════════════════════════════════════════════════════════════════
     BOOT DATA — متغیرهای سرور برای مرورگر
     ═══════════════════════════════════════════════════════════════════ -->
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
/* ═══ BOOT DATA ═══════════════════════════════════════════════════════════
   Everything the browser needs that only the server knows, published here so
   the first paint is correct rather than provisional. Each line names the
   constant it comes from — none of these values is re-stated anywhere else in
   the codebase, which is what stops the client and the server from disagreeing
   about a rule.

   The catalogue is the important one. It used not to be published at all:
   data.js carried ten demo products, main.js painted them, and an idle request
   to catalog.php then replaced them with the eleven real ones a second or two
   later — so every customer saw one shop and then a different one, and a link
   to a demo id resolved before the swap and 404'd after it. Inlining the same
   records the order endpoint prices from removes the swap entirely, removes a
   round trip, and makes the crawler-visible HTML the shop the visitor sees. */
window.VELORA_APP_URL     = <?= $encodeLd(APP_URL) ?>;
/* The catalogue feed, as a PATH — never as an absolute URL.

   This used to be rtrim(APP_URL,'/') . '/catalog.php', and the browser's
   freshness probe was therefore aimed at the canonical host rather than at the
   host that served the page. The site's own Content-Security-Policy says
   connect-src 'self', so on any other origin the probe was refused outright:
   the catalogue never revalidated, and the five-minute poll in sync-aurelle.js
   silently did nothing. That is the whole of the synchronisation feature, and
   it only worked on the exact host APP_URL names — a staging preview, a second
   domain, or an IP for checking a deploy all lose it without a single error.

   The CSP was not the only thing in the way. catalogFetch() passes
   credentials:'same-origin', so a cross-origin request would have carried no
   session either, and the failure would have looked like a stock problem rather
   than a routing one.

   A relative path is correct for a different reason too, and it is the reason
   the file's own header comment gives for the sub-directory install: the page
   knows where it lives, and its own API is beside it. APP_URL still governs the
   canonical, the OpenGraph URLs and the JSON-LD — the things a crawler reads,
   which genuinely must name one canonical origin. It does not govern where a
   running page sends its own requests. */
window.VELORA_CATALOG_URL = <?= $encodeLd(velora_local_path('catalog.php')) ?>;
window.VELORA_UPLOADS     = <?= $encodeLd(function_exists('product_upload_url_base') ? product_upload_url_base() : APP_URL . '/storage/uploads/products') ?>;
window.VELORA_CATALOG     = <?= $encodeLd($catalogFeed) ?>;
window.VELORA_CATALOG_VERSION = <?= $encodeLd(function_exists('velora_catalog_version') ? velora_catalog_version() : '') ?>;
window.VELORA_PDP_PRODUCT = <?= $isProductPage ? $encodeLd($product) : 'null' ?>;
window.VELORA_IS_ADMIN    = <?= $isAdmin ? 'true' : 'false' ?>;
window.VELORA_ROUTE       = <?= $encodeLd(['product' => $productId]) ?>;
/* Server-side rules, published so the client cannot build a cart the order
   endpoint will refuse. MAX_LINE and SIZE_BAND are the very constants api.php
   enforces; raising either is one edit in config.php and reaches both sides at
   once. The size band closed a real order-blocking bug: the size sheet offered
   '36'…'46' from a hand-written array while the server accepted 37–41, so a
   customer could complete all three checkout steps and be refused at the last
   request with INVALID_SIZE naming no field. */
window.VELORA_MAX_LINE    = <?= (int) MAX_LINE ?>;
window.VELORA_SIZE_BAND   = <?= $encodeLd(array_values(SIZE_BAND)) ?>;
/* The Persian category names, published for the same reason the size band is:
   the client draws a category label and must not invent one. A product page
   that said `sandal` while its card said «صندل» would be the same slug rendered
   two ways on two surfaces of one product. */
window.VELORA_CATEGORIES  = <?= $encodeLd(CATEGORY_LABELS) ?>;
/* The filter bar's server truth: the facets it actually rendered (derived from
   the live catalogue above, never a literal list), the validated query state,
   and the full unfiltered feed. filters-aurelle.js re-derives chip counts and
   bounds against the *live* catalogue after an idle sync — so when an operator
   retires a colour or a size while a customer has the shop open, the bar sheds
   that option itself instead of offering a facet that matches nothing. The
   boot-time HTML is generated from exactly these structures; the two engines
   cannot drift because they start from the same bytes. */
window.VELORA_FILTER_BOOT = <?= $encodeLd([
    'cats'          => $facetCats,
    'colors'        => $facetColors,
    'sizes'         => $facetSizes,
    'heelBounds'    => $heelBounds,
    'priceBounds'   => $priceBounds,
    'active'        => $filtersActive,
    'q'             => $qf,
    /* The FULL feed, before this request's filters were applied — the client
       engine filters all eleven pieces in memory rather than only the ones
       the URL already narrowed. Without this, changing one facet on a
       filtered page would filter the already-filtered grid: a one-way door,
       the exact failure the header-search bug taught this house about. */
    'full'          => array_values($catalogFeedAll ?? []),
]) ?>;
/* The precache manifest: every versioned stylesheet and script this document
   loads, as the exact URLs the <link>/<script> tags use.

   sw.js derived its own precache list from `?css=<mtime>&js=<mtime>` on the
   registration URL, expanded through a fixed template naming ./style.css and
   ./app.js. Neither file exists here — the assets are six stylesheets and
   fifteen scripts under css/ and js/ — so the list was always empty, the
   install handler waited out its full 1500 ms grace period on every cold cache,
   and no asset was ever precached. The whole subsystem, and the sixty lines of
   commentary about integrity-checking what it never fetched, was unreachable.

   The page is the only place that knows the real list: it is per-document,
   version-stamped by $assetV(), and assembled as the markup is written. So the
   page publishes it and main.js hands it to the worker. The ?css=/?js=
   parameters are left in place for a single-bundle build that would match the
   template; they simply do not apply here.

   The values are already `?v=<filemtime>` URLs, which is what makes this safe
   to cache hard: a change to any file changes its URL, so a precache can never
   serve a stale stylesheet or script under a matching name. */
window.VELORA_PRECACHE = <?= $encodeLd($precacheAssets) ?>;
/* The voucher table, straight out of velora_voucher_map() in config.php.
   data.js used to hard-code `VELORA10`, a code validate_voucher() has never
   heard of, and apply an UNCAPPED percentage to the basket — so a customer was
   shown a discount the server would not honour, and above the real cap it was
   shown a larger one than the server would charge. Publishing the table makes
   the browser's arithmetic the same arithmetic the till uses, including the
   cap and the minimum subtotal. */
window.VELORA_VOUCHERS    = <?= $encodeLd(velora_voucher_map()) ?>;
</script>

<!-- ═══════════════════════════════════════════════════════════════════
     SCRIPTS

     No third-party runtime. The page used to load Lenis (30 KB) and GSAP
     (71 KB) from jsDelivr on every first visit. Both were removable rather
     than merely unused:

       · GSAP's scroll work — initHeroParallax, initSectionReveals — is written
         against ScrollTrigger, which was NEVER loaded. gsap.from({scrollTrigger:
         …}) without the plugin silently drops the trigger and runs the tween
         once on load, so the parallax had not been parallaxing; it was a
         one-shot fade that also cost a 71 KB download.
       · The one GSAP animation that did work — the hero line reveal — already
         had a CSS-transition path on the else branch of the same function,
         written for exactly the case where GSAP is absent.
       · Lenis only ever did smooth scrolling, and only on fine pointers
         (useLenis = !reduced && !coarse), so it was dead on every phone. It
         also cost a cross-origin DNS + TLS + round trip before first paint.

     net −101 KB, −2 third-party origins (one of them on a CDN with poor
     reachability from Iran, where it also delayed FCP), −1 supply-chain
     surface, and the CSP no longer needs cdn.jsdelivr.net or
     cdnjs.cloudflare.com at all.

     Order is load-bearing and is the dependency graph:
       core            no dependencies, defines window.AE
       data            needs AE; reads the inlined catalogue
       velora-bridge   needs nothing; installs window.aeApi
       renderers       defines the dialog markup builders
       state           needs AE + data
       ui              needs AE + data + state
       cart            needs AE + data + state + ui
       pdp             needs bridge + cart + ui
       checkout        needs bridge + cart + data + state
       auth            needs bridge + state
       concierge       needs bridge + AE
       atelier         needs AE + data
       sync            needs bridge; the idle freshness check
       main            needs all of the above; boots
     ═══════════════════════════════════════════════════════════════════ -->
<?php foreach ($VELORA_JS as $__js): ?>
<script src="<?= $assetV($__js) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>