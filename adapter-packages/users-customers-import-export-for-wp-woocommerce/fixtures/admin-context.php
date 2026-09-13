<?php
declare(strict_types=1);

// The locked plugin returns before registering anything when !is_admin()
// (its main file:20). WP-CLI --require runs before WordPress bootstrap.
define('WP_ADMIN', true);
