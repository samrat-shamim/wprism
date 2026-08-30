<?php
/**
 * A contained preview has no asynchronous worker authority. DISABLE_WP_CRON
 * prevents normal spawn requests; this guard closes direct Apache entry to
 * wp-cron.php and direct WP-CLI cron execution before a restored callback can
 * execute.
 */
declare(strict_types=1);

if (defined('WP_CLI') && WP_CLI) {
    $arguments = array_values(array_filter(
        is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [],
        static fn (mixed $argument): bool => is_string($argument)
    ));
    for ($index = 0, $count = count($arguments); $index + 2 < $count; $index++) {
        if (array_slice($arguments, $index, 3) === ['cron', 'event', 'run']) {
            fwrite(STDERR, "Error: contained preview refuses WP-CLI cron event execution.\n");
            exit(75);
        }
    }
}

if (defined('DOING_CRON') && DOING_CRON) {
    http_response_code(404);
    exit;
}
