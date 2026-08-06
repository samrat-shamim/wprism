<?php
// Loaded through wp-cli's --require before each product-path command so the
// same public surfaces exist for seed, capture, pending, and cleanup.
\WP_CLI::add_hook('after_wp_load', static function (): void {
    register_post_type('duo_book', [
        'label' => 'Duo books',
        'public' => true,
        'show_ui' => true,
        'supports' => ['title','editor'],
    ]);
    register_taxonomy('duo_genre', ['duo_book'], [
        'label' => 'Duo genres',
        'public' => true,
        'show_ui' => true,
    ]);
});
