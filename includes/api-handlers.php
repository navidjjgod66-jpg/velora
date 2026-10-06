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
 * The allow-list is generated from the directory itself, so an action can
 * never address a path the dispatcher did not publish, and a new handler is
 * reachable the moment it exists on disk.
 */
function velora_api_handler(string $action): ?string {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (glob(__DIR__ . '/api/*.php') ?: [] as $file) {
            $map[basename($file, '.php')] = $file;
        }
    }
    return $map[$action] ?? null;
}
