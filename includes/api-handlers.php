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
