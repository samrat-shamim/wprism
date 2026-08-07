<?php
/**
 * Plugin Name: Duo Promotion Probe
 * Version: 1.0.0
 *
 * Regression-only plugin for DUO-3216. Its activation hook attempts mail and
 * HTTP, while later filters short-circuit the actual escape. Canary's deploy
 * observer runs at an earlier priority and must report both attempts without
 * turning them into a promotion failure.
 */

add_filter('pre_wp_mail', static fn($short) => true, 10, 1);
add_filter(
    'pre_http_request',
    static fn($pre) => new WP_Error('duo_promotion_probe', 'probe request intentionally short-circuited'),
    10,
    1
);

register_activation_hook(__FILE__, static function (): void {
    wp_mail('duo@example.test', 'DUO promotion activation probe', 'probe');
    wp_remote_get('https://duo-promotion-probe.invalid/activation');
    update_option('duo_promotion_probe_activated', 'yes');
});
