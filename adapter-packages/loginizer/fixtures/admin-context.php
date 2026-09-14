<?php
declare(strict_types=1);

// WP-CLI --require runs before WordPress bootstrap; Loginizer includes its
// admin writers (main/admin.php) only when is_admin() (init.php:358-360).
// Guarded because a future WP-CLI that pre-defines WP_ADMIN for its own
// reasons must not fatal on a double define.
if (!defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}
