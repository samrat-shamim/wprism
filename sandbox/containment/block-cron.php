<?php
/**
 * A contained preview has no asynchronous worker authority. DISABLE_WP_CRON
 * prevents normal spawn requests; this guard closes direct Apache/CLI entry to
 * wp-cron.php before a restored callback can execute.
 */
declare(strict_types=1);

if (defined('DOING_CRON') && DOING_CRON) {
    http_response_code(404);
    exit;
}
