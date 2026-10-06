<?php
declare(strict_types=1);
/**
 * VELORA · Catalogue Feed
 *
 * ─── Why this endpoint exists ──────────────────────────────────────────────
 * The catalogue used to reach the browser in exactly one way: inlined into the
 * HTML shell. That made its freshness inseparable from the shell's, and the
 * operator who hand-edits products.json had no way to make an edit visible
 * other than a full document round-trip. Worse, the failure had no signal: if
 * any layer held that HTML — a service worker from an older build still
 * controlling a long-lived tab, an intermediary, a full-page cache — the edit
 * simply never appeared, and the page looked healthy while serving stale
 * prices and stale stock.
 *
 * This endpoint breaks the coupling. It serves the same client feed the shell
 * inlines, keyed by the file's own content hash, so:
 *
 *   · a change to products.json changes the ETag, which changes the answer to
 *     a conditional request, which is the only question a cache ever asks;
 *   · the browser can confirm what the server is actually serving without
 *     re-downloading the document;
 *   · app.js can notice a newer catalogue at idle and swap it in, instead of
 *     the operator pressing Ctrl-F5 and wondering.
 *
 * ─── Caching ──────────────────────────────────────────────────────────────
 * `no-cache` + ETag, not `no-store`. The feed is public, identical for every
 * visitor, and carries no session state — nothing here is per-user, so the
 * old "a cached shell leaks one visitor's CSRF token to the next" objection
 * does not apply. `no-cache` means "always ask, and accept 304 when nothing
 * changed", so a repeat visit costs a 304 (a few dozen bytes) instead of 9 KB,
 * and a stale copy is impossible by construction rather than by policy.
 *
 * A catalog is public product data, so it is deliberately NOT gated behind a
 * login or an admin check: that would make the storefront's own boot sequence
 * depend on an authorisation check, and would make the endpoint useless for
 * the very thing it exists to do.
 *
 * ─── Access-Control-Allow-Origin: * · a deliberate decision ────────────────
 * This is the ONE permissive header in the project, and it was worth deciding
 * rather than leaving in place by accident. api.php restricts itself to
 * APP_ORIGIN; this endpoint does not. The reasoning, so the next person to see
 * the asterisk can either agree or change it knowingly:
 *
 * 1. It cannot be meaningfully narrowed here. This file requires
 *    includes/catalog.php and deliberately NOT config.php — see the "No
 *    database" note above. APP_ORIGIN is computed in config.php, and the only
 *    ways to get it here are to load config.php (which would make the
 *    freshness probe depend on the database, reintroducing exactly the failure
 *    mode this endpoint exists to avoid: during a DB outage the storefront
 *    concludes its catalogue is current precisely when it cannot know) or to
 *    duplicate the .env parser here. Neither is worth the price of the header.
 *
 * 2. The data is public anyway. products.json sits in the document root and is
 *    fetched by the storefront's own boot. Anyone who wants the catalogue has
 *    it; the CORS header only decides whether they need a server-side proxy to
 *    read it from a page on another origin. Narrowing the header therefore
 *    closes no door that a one-line `curl` does not already open.
 *
 * 3. What it does cost: live stock levels and prices, readable cross-origin, by
 *    anyone who cares to look. For a single-boutique Iranian maison that is not
 *    a competitive disclosure worth engineering around — but if this house ever
 *    resells stock levels to partners, or publishes to a marketplace, that
 *    argument stops holding and the header should go.
 *
 * TO NARROW IT LATER, do it in index.php by emitting the header there and
 * having this endpoint read the value from a header the shell sets — not by
 * requiring config.php here. And when you do, add `Vary: Origin` in the same
 * commit, or a shared cache will serve one origin's response to another.
 *
 * ─── No database ──────────────────────────────────────────────────────────
 * This endpoint requires includes/catalog.php directly, not config.php.
 *
 * The catalogue is one JSON file. Nothing in the answer below touches MySQL, so
 * routing this through config.php would have bought nothing and cost the one
 * property that matters for a freshness check: the check must be answerable
 * whenever the *file* is readable, and only then. Coupling it to the DB block
 * means the storefront's "is my catalogue current?" probe starts failing
 * during a database outage, so the page concludes it is up to date precisely
 * when it cannot know — and the operator gets a silent no-op instead of a
 * visible failure. Availability follows the file, which is the only dependency
 * that actually exists.
 *
 * includes/catalog.php is self-contained and guards on VELORA_CATALOG_LOADED,
 * so a later require of config.php in the same request is still correct.
 */
require __DIR__ . '/includes/catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, no-cache, must-revalidate');
/* Deliberate, and argued at length in the file header. Do not "tidy" this into
   APP_ORIGIN without reading that note first. */
header('Access-Control-Allow-Origin: *');
/* Not needed for a wildcard, and the cache-poisoning risk it would create is
   real: with `*` in play, `Vary: Origin` splits the cache per origin for no
   benefit. Added here as a comment rather than a header on purpose. */

$version = velora_catalog_version();
$etag    = '"' . $version . '"';
header('ETag: ' . $etag);
header('X-Velora-Catalog-Version: ' . $version);

/* Conditional request. If the client already has this exact catalogue, the
   correct answer is 304 and no body at all. */
$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '') {
    foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
        $candidate = trim($candidate, " \t\n\r\0\x0B");
        if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
            http_response_code(304);
            exit;
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    http_response_code(200);
    exit;
}

/* Two headers for the operator, not for the browser.

   X-Velora-Catalog-Version above is a content hash: it answers "are these the
   same bytes", which is the right question for a cache and the wrong question
   for a person. When a catalogue edit does not appear, the useful question is
   which FILE the server is reading and WHEN it was last written — because the
   two common causes are a wrong path (an old products.json in a subdirectory,
   a stale deploy) and a write that never landed (an editor that buffered, a
   failed upload). Both are visible here and neither is visible in a hash. */
/* velora_catalog_path() is defined by includes/catalog.php, which this file
   required three lines above — the guard around it was unreachable, and it
   covered the one file where the function genuinely might be absent (a broken
   include), where the correct behaviour is to fail loudly rather than to emit
   two headers that quietly describe nothing. */
$__catalogPath = velora_catalog_path();
header('X-Catalog-File: ' . basename($__catalogPath));
header('X-Catalog-Mtime: ' . (string) (is_file($__catalogPath) ? filemtime($__catalogPath) : 0));

$feed = velora_catalog_client_feed();

/* A bad file must not 500 the storefront's boot path. An empty catalogue with
   a version of "missing" is the truthful answer, and index.php's own error
   log already carries the reason. */
if ($version === 'missing' && !$feed) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo json_encode(
        ['ok' => false, 'error' => 'CATALOG_UNREADABLE', 'version' => $version],
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

/* Serialised form of the answer, and the one place it is built.

   json_encode over a large catalogue is the bulk of this endpoint's CPU, and the
   result is identical for every visitor within a catalogue version — it is public
   product data with no per-session field, which is the same reasoning as the
   `no-cache` + ETag decision in the file header, applied one level down.

   APCu is used when the extension is present and skipped silently when it is not,
   so this is a cache and never a dependency. The key is the catalogue's own
   version hash: any edit to products.json produces a different hash and therefore
   a different key, so a stale entry cannot be served — it simply stops being
   reached. No invalidation logic, and nothing to clear by hand.

   The failure mode is deliberate and total rather than partial: if APCu is
   unavailable the code falls through to encoding inline, so the endpoint is
   exactly as correct as it was, just slower. If encoding itself fails (malformed
   UTF-8 survives JSON_INVALID_UTF8_SUBSTITUTE, but a pathological input could
   still do it), nothing is cached and nothing is served. */
$body = json_encode(
    ['ok' => true, 'version' => $version, 'count' => count($feed), 'products' => $feed],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);

if ($body === false) {
    /* Never cache a failure, and never emit one: an empty catalogue with a
       truthful error is more use to the storefront than a truncated body. */
    error_log('[VELORA CATALOG] json_encode failed for version ' . $version);
    http_response_code(500);
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => 'CATALOG_ENCODE_FAILED', 'version' => $version]);
    exit;
}

/* apcu_enabled('apc.enabled') is the honest check: the functions exist in some
   builds where the cache is switched off, and calling them then returns false
   every time — which would turn this into a store on every request, i.e. slower
   than not caching at all. */
if (function_exists('apcu_fetch') && function_exists('apcu_store')
    && function_exists('apcu_enabled') && apcu_enabled('apc.enabled')
) {
    $key = 'velora:catalog:' . $version;
    $hit = apcu_fetch($key, $ok);
    if ($ok === true && is_string($hit) && $hit !== '') {
        echo $hit;
        exit;
    }
    apcu_store($key, $body, 3600);
}

echo $body;
