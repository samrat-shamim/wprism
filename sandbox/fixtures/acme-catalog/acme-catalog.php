<?php
/**
 * Plugin Name: Acme Catalog
 * Description: Round-3 T6 adapter-walk fixture — the in-house plugin scenario
 *   S3 exists for: a custom plugin nobody published, carrying its own
 *   `wprism-adapter.json` (docs/guides/adapter-authoring.md, "Adapters a plugin
 *   bundles"). Its four surfaces are one of each kind the walk has to see the
 *   projection separate: an authored CPT, an authored taxonomy, an authored
 *   option and a runtime option, plus one custom table nothing declares.
 * Version: 1.0.0
 *
 * Why this fixture is not `wprism-agency-cpt` reused: that plugin's adapter is
 * SHIPPED (manifests/wprism-agency-cpt.json), which is the one source S3 must not
 * have. The whole scenario is "the operator wrote the adapter and no reviewed
 * library knows this plugin exists", so the fixture has to be a name the
 * shipped library has never heard of. It is also deliberately not `wprism-`
 * prefixed: that prefix means `synthetic-fixture` to the capability generator
 * (adapter-authoring.md, "Directory conventions"), and S3's subject is a
 * customer's real plugin, not a test double.
 *
 * Surfaces, and why each one is here:
 *
 *   post_type acme_item      authored. The CPT the site's operator edits; the
 *                            surface the walk creates a row on, releases, and
 *                            asserts arrived on the target.
 *   taxonomy  acme_kind      authored. A second authored surface at a
 *                            different grain, so `wprism init` has to widen
 *                            policy.taxonomies as well as policy.post_types
 *                            from the bundled manifest's declarations.
 *   option    acme_catalog_settings  authored. One operator-edited setting.
 *   option    acme_catalog_cache     runtime. A per-request counter nobody
 *                            authored — the control case that must stay on the
 *                            target and out of captured state.
 *   table     acme_catalog_index     created on activation and DELIBERATELY
 *                            undeclared by the bundled adapter. Coverage finds
 *                            it, assess mints its `table:acme_catalog_index`
 *                            row, and the DB checkpoint is what restores it —
 *                            the same boundary S1 proves for WPForms' tables,
 *                            reached here through a plugin the walk owns.
 *
 * The runtime counter is bumped on every front-end request rather than on a
 * hook WPrism's apply window fires, so it cannot be confused with an apply-time
 * write: apply runs hook-free, and this option only ever moves under real HTTP.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ACME_CATALOG_VERSION', '1.0.0');

/**
 * The index table's unprefixed logical name. WPrism's coverage reports the
 * logical name (the live name minus $wpdb->prefix), so the fixture and the
 * walk's assertions agree on one spelling.
 */
define('ACME_CATALOG_TABLE', 'acme_catalog_index');

add_action('init', function () {
    register_post_type('acme_item', [
        'label' => 'Acme Items',
        'labels' => ['singular_name' => 'Acme Item', 'name' => 'Acme Items'],
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'custom-fields'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'acme-items'],
    ]);

    register_taxonomy('acme_kind', ['acme_item'], [
        'label' => 'Acme Kinds',
        'labels' => ['singular_name' => 'Acme Kind', 'name' => 'Acme Kinds'],
        'public' => true,
        'show_in_rest' => true,
        'hierarchical' => false,
        'rewrite' => ['slug' => 'acme-kind'],
    ]);
});

/**
 * Activation: the authored setting, the runtime counter, and the undeclared
 * table.
 *
 * `dbDelta()` rather than a bare CREATE TABLE because that is what a real
 * plugin does and because it is idempotent — the walk activates this plugin
 * on both pair sides and reactivates it through the release's own lifecycle
 * phase, and a second activation must not fail.
 */
register_activation_hook(__FILE__, function () {
    global $wpdb;

    add_option('acme_catalog_settings', [
        'currency' => 'EUR',
        'display' => 'grid',
        'per_page' => 12,
    ]);
    add_option('acme_catalog_cache', ['hits' => 0, 'rebuilt_at' => '']);

    $table = $wpdb->prefix . ACME_CATALOG_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta(
        "CREATE TABLE $table (\n"
        . "  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
        . "  item_id bigint(20) unsigned NOT NULL,\n"
        . "  term_slug varchar(191) NOT NULL DEFAULT '',\n"
        . "  indexed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\n"
        . "  PRIMARY KEY  (id),\n"
        . "  KEY item_id (item_id)\n"
        . ") $charset;"
    );
});

/**
 * The runtime counter. Front-end only, and never on an admin or WP-CLI
 * request: apply runs in WP-CLI with hooks suppressed, so a counter that
 * moved there would blur the exact distinction S3 asserts.
 */
add_action('template_redirect', function () {
    if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    $cache = get_option('acme_catalog_cache', ['hits' => 0, 'rebuilt_at' => '']);
    if (!is_array($cache)) {
        $cache = ['hits' => 0, 'rebuilt_at' => ''];
    }
    $cache['hits'] = (int) ($cache['hits'] ?? 0) + 1;
    $cache['rebuilt_at'] = gmdate('c');
    update_option('acme_catalog_cache', $cache);
});

/**
 * Index maintenance on an authored save. This is what puts rows in the
 * undeclared table without the walk writing SQL by hand: the table fills up
 * as a side effect of authoring, exactly as a real plugin's index would, so
 * "the checkpoint restored it" is a claim about real rows.
 *
 * WPrism's own apply writes post rows with direct SQL and fires no hooks, so this
 * never runs during a release — which is the point: the target's index rows
 * are target-local state whose only restore path is the database checkpoint.
 */
add_action('save_post_acme_item', function ($postId, $post) {
    global $wpdb;
    if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
        return;
    }
    $terms = wp_get_object_terms($postId, 'acme_kind', ['fields' => 'slugs']);
    $slug = (is_array($terms) && $terms !== []) ? (string) $terms[0] : '';
    $table = $wpdb->prefix . ACME_CATALOG_TABLE;
    $wpdb->delete($table, ['item_id' => (int) $postId], ['%d']);
    $wpdb->insert(
        $table,
        [
            'item_id' => (int) $postId,
            'term_slug' => $slug,
            'indexed_at' => gmdate('Y-m-d H:i:s'),
        ],
        ['%d', '%s', '%s']
    );
}, 10, 2);
