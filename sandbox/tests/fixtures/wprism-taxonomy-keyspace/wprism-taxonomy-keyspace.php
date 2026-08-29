<?php
/**
 * Plugin Name: WPrism Taxonomy Keyspace Fixture
 * Version: 1.0.0
 * Description: Neutral regression fixture for declared post/term relationship ownership.
 */

add_action('init', static function (): void {
    register_post_type('dks_article', [
        'public' => false,
        'show_ui' => false,
        'supports' => ['title', 'editor'],
    ]);
    register_taxonomy('dks_post_rel', ['dks_article'], [
        'public' => false,
        'show_ui' => false,
    ]);
    // The opaque WordPress object type is deliberate: no engine may infer
    // the term identity keyspace from a plugin-specific sentinel.
    register_taxonomy('dks_term_rel', ['dks_term_owner'], [
        'public' => false,
        'show_ui' => false,
    ]);
});
