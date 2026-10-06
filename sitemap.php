<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = rtrim(APP_URL, '/');

/* ── Static routes ─────────────────────────────────────────────────────
   ONE entry. Not a style choice — a correctness fix.

   This used to list thirteen static URLs: six `?view=…` pages and six
   `?view=shop&cat=…` category pages, alongside `/`. index.php no longer reads
   `?view` at all; it says so explicitly at index.php:48-52 — "a parameter that
   selects nothing is not a parameter". So all twelve resolve to the same
   document as `/`, and that document's own canonical is `/` (index.php:157).

   That is the worst thing a sitemap can do: Google fetches twelve URLs,
   receives twelve byte-identical 200s, and logs a canonical violation against
   the canonical on every single crawl — reintroducing from the sitemap exactly
   the duplicate-page problem index.php was changed to eliminate.

   Category filtering is not a page. It is client-side state over one document
   (`state.fam` in state.js, written to #activePills), addressed by hash routes
   that a crawler never fetches. So there is nothing here to list.

   <lastmod> is the newest mtime among the files that actually determine what
   this document is: the template itself, the catalogue it inlines, and the
   stylesheet and scripts it references. A home page whose products changed but
   whose stamp did not is a page that crawlers are told to stop re-checking. */
$newest = 0;
foreach (['index.php', 'products.json', 'sw.js', 'css/aurelle-tokens.css'] as $probe) {
    $newest = max($newest, (int) @filemtime(__DIR__ . '/' . $probe));
}
$static = [
    ['loc' => $base . '/', 'cf' => 'daily', 'pri' => '1.0',
     'lm' => $newest ? gmdate('Y-m-d', $newest) : null],
];

/* <changefreq> and <priority> are kept in the emitter below for XML shape, but
   Google has stated since 2015 that it ignores both, and Yandex and Bing treat
   them as hints at best. `priority` in particular was asserting a hierarchy
   between URLs that were the same document. */

/* ── Products ────────────────────────────────────────────────────────────
   The catalogue is products.json, so there is no query to run and no 5000-row
   LIMIT to reason about: the whole active catalogue is already resident and
   bounded by what an operator has entered.

   <lastmod> is now the product's own drop_date. The column it replaced,
   velora_products.updated_at, advanced on every admin save — including saves
   that changed nothing a crawler can see — so it reported "changed" for a
   re-save of an unchanged product, which is the kind of noise that teaches a
   crawler to ignore the tag. A product's content genuinely changes when the
   maison re-drops it, and that is what drop_date records. An absent or
   unparseable date emits no <lastmod> at all, which is the honest answer.

   product_image_url() returns '' for a bare catalogue id, so a product with no
   uploaded photography contributes no <image:image> rather than a URL that
   cannot resolve. That is correct: the plate is drawn in the browser and has
   no address a crawler could fetch.

   That call used to be the only unguarded reference to product_image_url() in
   the project — every other one sat behind function_exists() — so on any
   catalogue containing a product with a gallery entry this file fataled with
   "Call to undefined function product_image_url()" and returned a 500 to the
   crawler. It is the one URL robots.txt points at. The function now exists, in
   includes/uploads.php, which config.php requires above this point, so the call
   is direct and the guard that hid the same bug at three other sites is gone. */
$products = [];
try {
    $all = velora_catalog_active();
    foreach ($all as $row) {
        $id   = (string) ($row['id'] ?? '');
        if ($id === '') continue;

        $images = [];
        foreach ((array) ($row['gallery'] ?? []) as $key) {
            if (!is_string($key)) continue;
            $key = trim($key);
            if ($key === '') continue;
            $img = product_image_url($key, 1200, 0, 80);
            if ($img !== '') $images[] = $img;
            if (count($images) >= 4) break; // cap per sitemap-image spec
        }

        $lm = (string) ($row['drop_date'] ?? '');
        $products[] = [
            'loc'    => $base . '/?product=' . rawurlencode($id),
            'cf'     => 'weekly',
            'pri'    => '0.7',
            'lm'     => ($lm !== '' && strtotime($lm) !== false) ? gmdate('Y-m-d', strtotime($lm)) : null,
            'images' => $images,
            'alt'    => (string) ($row['name'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    error_log('[VELORA sitemap] ' . $e->getMessage());
}

$all = array_merge($static, $products);

$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
      . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

foreach ($all as $u) {
    $xml .= "  <url>\n";
    $xml .= '    <loc>' . esc_xml($u['loc']) . "</loc>\n";
    /* <lastmod> was the one interpolated value on this page NOT escaped. It is
       safe today only because gmdate() can emit nothing but [0-9-] — every
       other field goes through esc_xml() now, so this was the single place a
       future format change would have become an XML injection. */
    if (!empty($u['lm'])) $xml .= '    <lastmod>' . esc_xml($u['lm']) . "</lastmod>\n";
    $xml .= '    <changefreq>' . esc_xml($u['cf']) . "</changefreq>\n";
    $xml .= '    <priority>' . esc_xml($u['pri']) . "</priority>\n";
    if (!empty($u['images'])) {
        foreach ($u['images'] as $img) {
            $xml .= "    <image:image>\n";
            $xml .= '      <image:loc>' . esc_xml($img) . "</image:loc>\n";
            if (!empty($u['alt'])) {
                $xml .= '      <image:title>' . esc_xml($u['alt']) . "</image:title>\n";
            }
            $xml .= "    </image:image>\n";
        }
    }
    $xml .= "  </url>\n";
}
$xml .= "</urlset>\n";
echo $xml;