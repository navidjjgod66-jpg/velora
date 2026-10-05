<?php
declare(strict_types=1);
/**
 * VELORA · Request input helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers the JSON-body reader and the req()/req_int()/req_str()/req_json()/req_bool() accessors every API handler reads through.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/input.php requires config.php to be loaded first.');
}

/**
 * VELORA · Request input helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers the JSON-body reader and the req()/req_int()/req_str()/req_json()/req_bool() accessors every API handler reads through.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/input.php requires config.php to be loaded first.');
}

REQUEST INPUT (JSON body → POST → GET)
═══════════════════════════════════════════════════════════════════════════ */
function req_parse_json(): array {
    static $json = null;
    if ($json === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $json = is_array($decoded) ? $decoded : [];
    }
    return $json;
}
function req(string $key, $default = null) {
    $json = req_parse_json();
    if (array_key_exists($key, $json))   return $json[$key];
    if (array_key_exists($key, $_POST))  return $_POST[$key];
    if (array_key_exists($key, $_GET))   return $_GET[$key];
    return $default;
}
function req_has(string $key): bool {
    $json = req_parse_json();
    return array_key_exists($key, $json) || array_key_exists($key, $_POST) || array_key_exists($key, $_GET);
}
function req_int(string $key, int $default = 0): int { $v = req($key, $default); return is_numeric($v) ? (int) $v : $default; }
function req_str(string $key, string $default = ''): string { $v = req($key, $default); return is_string($v) ? trim($v) : $default; }
function req_json(string $key, array $default = []): array {
    $v = req($key);
    if (is_array($v)) return $v;
    if (is_string($v) && $v !== '') { $decoded = json_decode($v, true); if (is_array($decoded)) return $decoded; }
    return $default;
}
function req_bool(string $key, bool $default = false): bool {
    if (!req_has($key)) return $default;
    $v = req($key);
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return $v != 0;
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if ($s === '') return false;
        return !in_array($s, ['0', 'false', 'no', 'off', 'null'], true);
    }
    return $default;
}
