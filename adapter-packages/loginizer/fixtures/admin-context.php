<?php
declare(strict_types=1);

// WP-CLI --require runs before WordPress bootstrap; Loginizer includes its
// admin writers (main/admin.php) only when is_admin() (init.php:358-360).
define('WP_ADMIN', true);
