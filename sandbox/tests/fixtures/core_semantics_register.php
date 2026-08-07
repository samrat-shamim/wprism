<?php
// DUO-3207 fixture: a sorted taxonomy with a deliberately non-core count
// contract. A hard-coded SQL COUNT cannot produce 777; only invoking the
// registered update_count_callback can satisfy the regression.
function duo_core_semantics_count(array $termTaxonomyIds, object $taxonomy): void {
    global $wpdb;
    foreach ($termTaxonomyIds as $ttId) {
        $wpdb->update($wpdb->term_taxonomy, ['count' => 777], ['term_taxonomy_id' => (int) $ttId], ['%d'], ['%d']);
    }
}

WP_CLI::add_wp_hook('init', static function (): void {
    register_taxonomy('duo_ordered', ['post'], [
        'public' => true,
        'show_ui' => true,
        'sort' => true,
        'update_count_callback' => 'duo_core_semantics_count',
    ]);
}, 0);
