<?php
declare(strict_types=1);
/**
 * VELORA · Session-auth helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers is_admin(), is_user(), current_user_id() and current_user_phone().
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/auth.php requires config.php to be loaded first.');
}

/**
 * VELORA · Session-auth helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers is_admin(), is_user(), current_user_id() and current_user_phone().
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/auth.php requires config.php to be loaded first.');
}

AUTH
═══════════════════════════════════════════════════════════════════════════ */
function is_admin(): bool {
    return !empty($_SESSION['admin']) && $_SESSION['admin'] === true && !empty($_SESSION['admin_login_at']) && (time() - (int) $_SESSION['admin_login_at']) < ADMIN_SESSION_TTL;
}
function is_user(): bool { return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0; }
function current_user_id(): int { return (int) ($_SESSION['user_id'] ?? 0); }
function current_user_phone(): ?string { return $_SESSION['user_phone'] ?? null; }
