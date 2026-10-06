<?php
declare(strict_types=1);
/**
 * VELORA · API action-handler loader
 *
 * api.php's former 2.6k-line switch is now one file per action under
 * includes/api/. Each handler is plain procedural code that runs in the
 * request's global scope (the same scope the switch bodies ran in, so $pdo,
 * $_SESSION, req_*() and jresp() all behave exactly as before). A handler
 * answers the request by calling jresp(), which ends execution — every
 * original `break;` at the end of a case body therefore becomes `return;`.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/api-handlers.php requires config.php to be loaded first.');
}

/* api.php's former 2.6k-line switch was split into includes/api/<domain>.php
   during the monolith decomposition, but the dispatcher at the bottom of
   api.php never required this loader — it only called velora_api_handler().
   The function existed on disk and nothing pulled it in, so every request
   reached the dispatcher, hit "call to undefined function", fell into the
   Throwable arm, and answered a valid action with a bodyless HTTP_500.
   Requiring the loader here, from the file that defines it, means the
   dependency travels with the code that needs it rather than living in the
   memory of whoever edits api.php. */
/* The single import point for the helpers every handler under includes/api/
   calls. Plain `require` is safe because api-handlers.php itself is pulled in
   with require_once, so this file — and these two requires — run exactly once
   per request; checkout.php and addresses.php are referenced nowhere else. */
require __DIR__ . '/checkout.php';
require __DIR__ . '/addresses.php';

/**
 * Resolve an action name to its handler file.
 *
 * The allow-list is generated from the directory itself, so an action can never
 * address a path the dispatcher did not publish, and a new handler is reachable
 * the moment it exists on disk.
 *
 * ─── Why the map is built from what each file DECLARES, not only its name ──
 *
 * This used to key the map on `basename($file, '.php')` alone, which works
 * exactly as long as one file handles exactly one action and that action is
 * named after the file. Two of the six files here do not:
 *
 *   includes/api/geo.php  declares `geo_regions` and `postal_lookup`
 *
 * so neither was in the map at all. Both were reachable from the browser —
 * js/velora-bridge.js calls geo_regions() on the checkout's province picker and
 * postal_lookup() for the s.api.ir verification step, and includes/postal.php
 * documents the lookup as a feature — and both answered UNKNOWN_ACTION 400 on
 * every request. The whole postal-verification feature was dead in production,
 * and the one action named in VELORA_DB_INDEPENDENT_ACTIONS, the one that exists
 * so the checkout's province picker survives a database outage, was the one that
 * could never be reached. A dead endpoint is the worst kind of bug here: the
 * code is present, documented, and correct, and nothing about it looks wrong.
 *
 * So the map is now built from two sources, unioned:
 *
 *   1. the filename  — keeps every currently-working action working, and keeps
 *      the one-file-per-action convention the other four files follow;
 *   2. the literal `$action === '…'` comparisons each file contains — so a file
 *      that legitimately handles several actions is reachable for all of them.
 *
 * Both sources are read from inside includes/api/ and nothing else, so the
 * security property is unchanged: an action still cannot address a path that is
 * not a published handler file. The scan uses the tokeniser, not a regex, so a
 * commented-out comparison or the string inside a log message cannot register an
 * action that the file does not actually handle.
 *
 * A name claimed by two files is a real conflict and is written to the error log
 * on first resolution; the earlier registration wins, so the behaviour does not
 * depend on glob()'s ordering.
 */
function velora_api_handler(string $action): ?string {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (glob(__DIR__ . '/api/*.php') ?: [] as $file) {
            $map[basename($file, '.php')] = $file;

            $tokens = @token_get_all((string) @file_get_contents($file));
            if (!is_array($tokens)) continue;
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$action') continue;
                /* Expect exactly:  $action  ===  'literal'  */
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_IS_IDENTICAL) continue;
                $j++;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
                $name = trim($tokens[$j][1], "'\"");
                if ($name === '' || !preg_match('/^[a-z][a-z0-9_]{1,48}$/', $name)) continue;
                if (isset($map[$name]) && $map[$name] !== $file) {
                    error_log('[VELORA API] Action ' . $name . ' is claimed by both '
                        . basename($map[$name]) . ' and ' . basename($file)
                        . '. The first registration wins — resolve this before deploying.');
                    continue;
                }
                $map[$name] = $file;
            }
        }
    }
    return $map[$action] ?? null;
}
