<?php
/**
 * Plugin Name: WPrism Taxonomy Keyspace Fixture
 * Description: Test-only taxonomies for manifest-declared relationship object keyspaces.
 * Version: 0.1.0
 */

add_action('init', static function (): void {
    register_taxonomy('wprism_keyspace_post_links', ['wprism_keyspace_post'], ['public' => false]);
    register_taxonomy('wprism_keyspace_legacy_post_links', ['wprism_keyspace_post'], ['public' => false]);

    // Deliberately not literal `term`: the manifest, not an engine sentinel,
    // declares that these relationship object_ids belong to term entities.
    register_taxonomy('wprism_keyspace_term_links', ['wprism_fixture_term_object'], ['public' => false]);
    register_taxonomy('wprism_keyspace_dynamic_term_links', ['wprism_fixture_dynamic_term_object'], ['public' => false]);

    // Negative controls: neither taxonomy has an object_keyspace manifest
    // declaration, so legacy post-only compatibility must not absorb a
    // runtime term or mixed object_type registration.
    register_taxonomy('wprism_keyspace_undeclared_term_links', ['term'], ['public' => false]);
    register_taxonomy('wprism_keyspace_undeclared_mixed_links', ['wprism_keyspace_post', 'term'], ['public' => false]);
});
