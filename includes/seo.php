<?php
declare(strict_types=1);

/**
 * VELORA · Centralised Technical-SEO module.
 * Single source of truth for meta tags, OpenGraph, Twitter cards and JSON-LD.
 * Every function returns a *string* of already-escaped, CSP-safe markup.
 *
 * Contract:
 *   · Never reads user input directly — receives a validated context array.
 *   · All HTML escaping goes through esc() in includes/http.php. seo_esc() was
 *     this module's private copy of it with a weaker flag set (no
 *     ENT_SUBSTITUTE, so one malformed byte returned an empty string and erased
 *     the attribute carrying it); it is gone and esc() is called directly.
 *   · All JSON-LD goes through velora_json_escape() in
 *     includes/presentation.php, via the seo_json() alias below. Same four
 *     JSON_HEX_* flags, and a 'null' fallback instead of false.
 *   · og:image always absolute; falls back to a brand image.
 */

const SEO_BRAND      = 'VELORA';
const SEO_LOCALE     = 'fa_IR';

/* The date every offer in this catalogue is claimed to be good until.
 *
 * It used to be `date('Y-m-d', strtotime('+1 year'))`, evaluated inside the
 * function on every request. That made the value advance by a day for as long
 * as the site stayed up, which is not a fact about the offer — it is an
 * artefact of when the crawler happened to arrive. A stability signal that
 * drifts cannot be used as one: a validator comparing two crawls a month apart
 * sees a changed offer and has to assume the price moved.
 *
 * A season is a fixed thing, so the bound is fixed too. Change it when the
 * collection changes, which is the event it is supposed to describe. */
const SEO_PRICE_VALID_UNTIL = '2027-03-31';

/* ─── Helpers ────────────────────────────────────────────────────────────── */

/**
 * JSON-LD encoder — an alias, not a second implementation.
 *
 * This body was byte-for-byte the same json_encode() call, with the same four
 * JSON_HEX_* flags and the same false→'' problem, that index.php's $encodeLd
 * closure and admin.php's UPLOAD_BASE block each spelled out. Three copies of
 * one flag set is three chances to drop JSON_HEX_APOS, and the copy that drops
 * it is the one that closes the <script> element.
 *
 * velora_json_escape() in includes/presentation.php is the single home, and it
 * additionally returns 'null' instead of false on failure — which matters here,
 * because a false concatenated into a <script> body is an empty string, and an
 * empty JSON-LD block is silently ignored by every consumer instead of raising
 * an error. SEO_PRICE_VALID_UNTIL and the other constants stay here: they are
 * facts about this module, not application configuration.
 *
 * No caller today — the JSON-LD graph is emitted by index.php through
 * velora_json_escape() directly. Kept as this module's named entry point so a
 * future SEO emitter reaches for one obvious function instead of open-coding
 * the flags a second time.
// TODO: verify dead code — kept as the module's documented encoder alias.
 */
function seo_json(array $data): string {
    return velora_json_escape($data);
}

function seo_truncate(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    if (mb_strlen($s) <= $max) return $s;
    return rtrim(mb_substr($s, 0, $max - 1)) . '…';
}

/* Resolution for any slot that needs an absolute image URL.

   product_image_url() returns a real URL for an admin upload or an explicitly
   pasted absolute URL, and '' for a catalogue id that has no uploaded
   photography behind it. The storefront renders those as a generated SVG plate
   in the browser; there is no server-side equivalent, so the honest answer here
   is the maison's own local brand mark rather than a borrowed stock photograph
   of somebody else's shoe.

   Declared dimensions in seo_render_head() are 512×512 to match that file
   exactly. Claiming 1200×630 for a 512×512 PNG is a Rich Result warning and
   would misrepresent the asset.

   Both functions below were guarded by function_exists() against functions
   that were never defined anywhere in the repository — so on every install
   they always took the fallback branch, and product_image_url() was dead code
   by construction. Both now live in includes/uploads.php, which config.php
   requires before this file, and the guards are gone. */

function seo_brand_image(): string {
    return product_brand_image();
}

function seo_image_url(string $keyOrUrl, int $w = 1200, int $h = 630): string {
    $k = trim($keyOrUrl);
    if ($k === '') return seo_brand_image();
    $resolved = product_image_url($k, $w, $h);
    return $resolved !== '' ? $resolved : seo_brand_image();
}

/* ─── Head emitters ──────────────────────────────────────────────────────── */

function seo_render_head(array $ctx): string {
    $title  = esc((string) $ctx['title']);
    $desc   = esc(seo_truncate((string) $ctx['description'], 160));
    $url    = esc((string) $ctx['canonical']);
    $img    = esc((string) $ctx['og_image']);
    $imgAlt = esc((string) ($ctx['og_image_alt'] ?? $ctx['title']));
    $robots = (string) ($ctx['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1');
    $type   = (string) ($ctx['og_type'] ?? 'website');
    $site   = esc((string) ($ctx['site_name'] ?? SEO_BRAND));

    $out  = '<title>' . $title . '</title>' . "\n";
    $out .= '<meta name="description" content="' . $desc . '">' . "\n";
    $out .= '<meta name="robots" content="' . esc($robots) . '">' . "\n";
    $out .= '<link rel="canonical" href="' . $url . '">' . "\n";
    $out .= '<meta property="og:type" content="' . esc($type) . '">' . "\n";
    $out .= '<meta property="og:site_name" content="' . $site . '">' . "\n";
    $out .= '<meta property="og:locale" content="' . SEO_LOCALE . '">' . "\n";
    $out .= '<meta property="og:title" content="' . $title . '">' . "\n";
    $out .= '<meta property="og:description" content="' . $desc . '">' . "\n";
    $out .= '<meta property="og:url" content="' . $url . '">' . "\n";
    $out .= '<meta property="og:image" content="' . $img . '">' . "\n";
    $out .= '<meta property="og:image:secure_url" content="' . $img . '">' . "\n";
    $out .= '<meta property="og:image:alt" content="' . $imgAlt . '">' . "\n";
    /* Declared dimensions come from the asset, not from a literal here. The
       two were written as bare "512" strings while product_brand_image_size()
       held the same pair as an array — so swapping the brand mark for a
       differently-sized file would have left these two lines claiming the old
       size, which is a Rich Results warning and a misrepresentation of the
       asset. One source now. */
    [$imgW, $imgH] = product_brand_image_size();
    $out .= '<meta property="og:image:width" content="' . (int) $imgW . '">' . "\n";
    $out .= '<meta property="og:image:height" content="' . (int) $imgH . '">' . "\n";
    $out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    $out .= '<meta name="twitter:title" content="' . $title . '">' . "\n";
    $out .= '<meta name="twitter:description" content="' . $desc . '">' . "\n";
    $out .= '<meta name="twitter:image" content="' . $img . '">' . "\n";
    $out .= '<meta name="twitter:image:alt" content="' . $imgAlt . '">' . "\n";
    return $out;
}

/* ─── JSON-LD graphs ─────────────────────────────────────────────────────── */

function seo_jsonld_organization(): array {
    /* The mark's dimensions, from the asset rather than from a literal. See the
       note in seo_render_head(). */
    [$logoW, $logoH] = product_brand_image_size();

    return [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        '@id'      => rtrim(APP_URL, '/') . '/#organization',
        'name'     => 'VELORA',
        'alternateName' => 'ولورا',
        'url'      => APP_URL,
        'logo'     => [
            '@type'  => 'ImageObject',
            /* The maison's own local mark. It was previously a borrowed stock
               photograph, and before that '/icon-512.png', a filename that does
               not exist anywhere in the project — the Organization node has
               always resolved to something that was either wrong or a 404.
               brand-icon-512.png is a real 512×512 PNG in the document root, so
               this is same-origin, cannot 404, and the declared dimensions
               below match the file. */
            'url'    => seo_brand_image(),
            'width'  => (int) $logoW,
            'height' => (int) $logoH,
        ],
        'sameAs' => [
            'https://instagram.com/velora.maison',
            'https://pinterest.com/velora.maison',
        ],
        'contactPoint' => [[
            '@type'             => 'ContactPoint',
            'telephone'         => '+98-21-00001200',
            'contactType'       => 'customer service',
            'areaServed'        => 'IR',
            'availableLanguage' => ['fa', 'en'],
        ]],
    ];
}

function seo_jsonld_website(): array {
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        '@id'             => rtrim(APP_URL, '/') . '/#website',
        'url'             => APP_URL,
        'name'            => 'VELORA',
        'inLanguage'      => 'fa-IR',
        'publisher'       => ['@id' => rtrim(APP_URL, '/') . '/#organization'],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => rtrim(APP_URL, '/') . '/?view=shop&q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

function seo_jsonld_breadcrumb(array $trail): array {
    $list = [];
    foreach ($trail as $i => $item) {
        $list[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => (string) $item['name'],
            'item'     => (string) $item['url'],
        ];
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
}

function seo_jsonld_product(array $p): array {
    $url      = rtrim(APP_URL, '/') . '/?product=' . rawurlencode((string) $p['id']);
    $totalStk = 0;
    foreach (($p['sizes'] ?? []) as $s) {
        $totalStk += (int) ($s['stock'] ?? 0);
    }
    $inStock = $totalStk > 0;

    /* Deduplicate while preserving order. seo_image_url() collapses every
       catalogue id to the same local brand mark, so a product whose four
       gallery keys are all placeholders would otherwise emit four identical
       image URLs — which tells a crawler nothing and looks like a mistake.
       One URL is the truth: this product has no photography yet. */
    $images = [];
    foreach (($p['gallery'] ?? []) as $k) {
        $u = seo_image_url((string) $k, 1200, 1500);
        if ($u !== '' && !in_array($u, $images, true)) $images[] = $u;
    }
    if (!$images) $images[] = seo_brand_image();

    $graph = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        '@id'         => $url . '#product',
        'name'        => (string) $p['name'],
        'description' => seo_truncate((string) ($p['desc'] ?: $p['sub']), 300),
        'sku'         => (string) $p['id'],
        'url'         => $url,
        'image'       => $images,
        'brand'       => ['@type' => 'Brand', 'name' => 'VELORA'],
        /* The Persian name, not the slug. `category` in Schema.org is meant to
           be the name a shop would use for the category, and "bridal" is what
           the catalogue file calls it — not what the maison calls it. A
           Persian-language page with an English category in its structured
           data is also the kind of inconsistency a Rich Results validator
           reports on a page that is otherwise correct. */
        'category'    => velora_category_label((string) ($p['cat'] ?? '')),
        'color'       => implode(', ', array_map(static fn($c) => $c['name'] ?? '', $p['colors'] ?? [])),
        'offers'      => [
            '@type'         => 'Offer',
            'url'           => $url,
            'priceCurrency' => 'IRR',
            'price'         => (string) ((int) $p['price'] * 10),
            'priceValidUntil'=> (string) ($p['price_valid_until'] ?? SEO_PRICE_VALID_UNTIL),
            'availability'  => $inStock
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller'        => ['@id' => rtrim(APP_URL, '/') . '/#organization'],
            'shippingDetails' => [
                '@type'          => 'OfferShippingDetails',
                /* This is now the one place the two sides cannot drift: it
                   reads the same SHIPPING_FLAT the till uses, so a change to
                   the policy moves the structured data with it. It used to be
                   hardcoded to 0 while the storefront charged SHIP_FEE below
                   FREE_SHIP_MIN — a misrepresentation in structured data and a
                   source of Rich Result warnings. minValue and maxValue are
                   both the flat rate because there is no range any more. */
                'shippingRate'   => [
                    '@type'    => 'MonetaryAmount',
                    /* ×10 converts toman to IRR, the unit Schema.org requires.
                       SHIPPING_FLAT is defined unconditionally by config.php
                       before this file loads, so the `defined()` fallback that
                       used to wrap all three of these was unreachable — and it
                       was the kind of guard that lets a renamed constant ship
                       silently, emitting 0 for shipping because nobody noticed
                       the name had changed. */
                    'minValue' => (string) ((int) SHIPPING_FLAT * 10),
                    'maxValue' => (string) ((int) SHIPPING_FLAT * 10),
                    'value'    => (string) ((int) SHIPPING_FLAT * 10),
                    'currency' => 'IRR',
                ],
                'shippingDestination' => [
                    '@type'          => 'DefinedRegion',
                    'addressCountry' => 'IR',
                ],
                'deliveryTime' => [
                    '@type'        => 'ShippingDeliveryTime',
                    'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 2, 'unitCode' => 'DAY'],
                    'transitTime'  => ['@type' => 'QuantitativeValue', 'minValue' => 2, 'maxValue' => 7, 'unitCode' => 'DAY'],
                ],
            ],
            'hasMerchantReturnPolicy' => [
                '@type'                 => 'MerchantReturnPolicy',
                'applicableCountry'     => 'IR',
                'returnPolicyCategory'  => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays'    => 30,
                'returnMethod'          => 'https://schema.org/ReturnByMail',
                'returnFees'            => 'https://schema.org/FreeReturn',
            ],
        ],
    ];

    /* ─── Strike-through price ──────────────────────────────────────────────
       Both keys are read, and the reason is the bug this line used to hide.

       index.php passes the shaped record, whose key is `old` — client_shape()
       renamed old_price to `old` when it normalised the wire format. This
       function read `old_price`. The names disagreed, so the comparison was
       false on every product, and a price that was never compared was the
       only thing wrong: no ListPrice node was emitted for any product in the
       catalogue, including the two that are genuinely discounted.

       That is a silent failure in the worst direction. The JSON-LD stayed
       *valid* — a missing optional property is not a schema error, nothing
       logged, nothing warned — so the sale badge simply never appeared and
       there was no signal that the code written to earn it had never run. A
       key mismatch between a producer and a consumer cannot be caught by
       either side alone, which is why the consumer now accepts either name
       rather than assuming the producer is right. */
    $listPrice = (int) ($p['old'] ?? $p['old_price'] ?? 0);
    if ($listPrice > (int) $p['price']) {
        $graph['offers']['priceSpecification'] = [
            '@type'         => 'UnitPriceSpecification',
            'price'         => (string) ($listPrice * 10),
            'priceCurrency' => 'IRR',
            'priceType'     => 'https://schema.org/ListPrice',
            /* A struck-through price with no end date is an open-ended
               claim, and Google treats an unverifiable one as a misrepresentation
               rather than as an omission. The catalogue is a season, so the
               season's end is the honest bound. */
            'validThrough'  => (string) ($p['price_valid_until'] ?? SEO_PRICE_VALID_UNTIL),
        ];
    }

    return $graph;
}