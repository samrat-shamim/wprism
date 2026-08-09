<?php
/**
 * Plugin Name: Duo Sidecar Reference Fixture
 * Version: 1.0.0
 * Description: Neutral regression fixture for structured attached-meta references.
 */

register_activation_hook(__FILE__, static function (): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dks_entries ("
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'label VARCHAR(191) NOT NULL, '
        . 'PRIMARY KEY (id)'
        . ") $charset"
    );
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dks_entry_meta ("
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'entry_id BIGINT UNSIGNED NOT NULL, '
        . 'meta_key VARCHAR(191) NOT NULL, '
        . 'meta_value LONGTEXT NULL, '
        . 'PRIMARY KEY (id), '
        . 'UNIQUE KEY owner_key (entry_id, meta_key)'
        . ") $charset"
    );
});
