<?php
// Loaded through wp-cli's --require before each product-path command so the
// same public surfaces exist for seed, capture, pending, and cleanup.
\WP_CLI::add_hook('after_wp_load', static function (): void {
    register_post_type('wprism_book', [
        'label' => 'WPrism books',
        'public' => true,
        'show_ui' => true,
        'supports' => ['title','editor'],
    ]);
    register_taxonomy('wprism_genre', ['wprism_book'], [
        'label' => 'WPrism genres',
        'public' => true,
        'show_ui' => true,
    ]);
});
