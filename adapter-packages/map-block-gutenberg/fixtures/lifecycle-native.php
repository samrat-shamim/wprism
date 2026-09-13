<?php
declare(strict_types=1);

require_once __DIR__ . '/lifecycle-evidence.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
$mode = $args[0] ?? '';
if ($mode === 'archive') {
    if (count($args) !== 2 || preg_match('~^/artifacts-cache/plugin-map-block-gutenberg-1\.35-[a-f0-9]{64}\.zip$~D', $args[1]) !== 1) {
        throw new RuntimeException('native archive probe requires the exact fixture cache path');
    }
    echo json_encode(['sha256' => is_file($args[1]) ? hash_file('sha256', $args[1]) : null], JSON_THROW_ON_ERROR);
    return;
}
$entry = WP_PLUGIN_DIR . '/' . MapLifecycleEvidence::PLUGIN;
$wrong = WP_PLUGIN_DIR . '/map-block-gutenberg/map-fixture-wrong.php';
$backup = __DIR__ . '/entry.original';
if ($mode === 'backup') {
    if (is_file($backup) || hash_file('sha256', $entry) !== MapLifecycleEvidence::ENTRY_SHA
        || file_put_contents($backup, file_get_contents($entry), LOCK_EX) === false) {
        throw new RuntimeException('exact native entry backup failed');
    }
} elseif (in_array($mode, ['minimum-header', 'maximum-header', 'unreadable-header', 'restore-header'], true)) {
    if (!is_file($backup) || hash_file('sha256', $backup) !== MapLifecycleEvidence::ENTRY_SHA || !is_file($entry)) {
        throw new RuntimeException('exact native entry backup unavailable');
    }
    $bytes = (string) file_get_contents($backup);
    if ($mode !== 'restore-header') {
        $version = match ($mode) { 'minimum-header' => '1.34', 'maximum-header' => '1.35.1', default => '' };
        $bytes = str_replace("Version: 1.35\n", 'Version: ' . $version . "\n", $bytes, $count);
        if ($count !== 1) throw new RuntimeException('native header fault premise failed');
    }
    if (file_put_contents($entry, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('native header fault write failed');
} elseif ($mode === 'wrong-basename') {
    if (!is_file($entry) || is_file($wrong) || hash_file('sha256', $entry) !== MapLifecycleEvidence::ENTRY_SHA) {
        throw new RuntimeException('native basename fault premise failed');
    }
    deactivate_plugins(MapLifecycleEvidence::PLUGIN);
    if (!rename($entry, $wrong)) throw new RuntimeException('native basename fault failed');
    wp_clean_plugins_cache();
} elseif ($mode === 'activate-wrong') {
    if (is_file($entry) || !is_file($wrong) || hash_file('sha256', $wrong) !== MapLifecycleEvidence::ENTRY_SHA) {
        throw new RuntimeException('native wrong-basename activation premise failed');
    }
    $activated = activate_plugin('map-block-gutenberg/map-fixture-wrong.php');
    if (is_wp_error($activated)) throw new RuntimeException('native wrong-basename activation failed');
} elseif ($mode === 'restore-basename') {
    if (is_file($entry) || !is_file($wrong) || hash_file('sha256', $wrong) !== MapLifecycleEvidence::ENTRY_SHA) {
        throw new RuntimeException('native basename restoration premise failed');
    }
    deactivate_plugins('map-block-gutenberg/map-fixture-wrong.php');
    if (!rename($wrong, $entry)) throw new RuntimeException('native basename restoration failed');
    wp_clean_plugins_cache();
} elseif ($mode !== 'observe') {
    throw new RuntimeException('unknown native lifecycle operation');
}

global $wpdb;
if (preg_match('/^[a-zA-Z0-9_]+$/D', $wpdb->prefix) !== 1) throw new RuntimeException('native prefix is not bounded');
$queries = [
    'posts' => "SELECT * FROM {$wpdb->posts} ORDER BY ID LIMIT 4097",
    'postmeta' => "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id LIMIT 4097",
    'map' => "SELECT * FROM {$wpdb->prefix}wprism_map ORDER BY uuid,id_kind LIMIT 4097",
    'state' => "SELECT * FROM {$wpdb->prefix}wprism_state ORDER BY uuid LIMIT 4097",
    'options' => "SELECT * FROM {$wpdb->options} WHERE option_name IN ('gmw-map-block-key','_transient_wprism_map_fixture') ORDER BY option_name LIMIT 4097",
];
$tables = [];
foreach ($queries as $name => $query) {
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($query, ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) throw new RuntimeException('native lifecycle observation read failed');
    $tables[$name] = $rows;
}
$post = get_page_by_path('map-boundary-fixture', OBJECT, 'page');
$created = get_page_by_path('map-created-fixture', OBJECT, 'page');
$pluginData = is_file($entry) ? get_plugin_data($entry, false, false) : [];
echo json_encode([
    'format' => 'wprism-map-lifecycle-observation/v1',
    'post' => (int) ($post->ID ?? 0), 'created' => (int) ($created->ID ?? 0),
    'key_preserved' => get_option('gmw-map-block-key') === 'map-fixture-target-key',
    'runtime_preserved' => get_option('_transient_wprism_map_fixture') === 'target-only-runtime',
    'maps_bound' => $post && $created && substr_count($post->post_content, 'map-fixture-target-key') === 2
        && substr_count($created->post_content, 'map-fixture-target-key') === 2,
    'installed' => ($pluginData['Version'] ?? '') !== '' ? $pluginData['Version'] : null,
    'active' => is_plugin_active(MapLifecycleEvidence::PLUGIN),
    'wrong_active' => is_plugin_active('map-block-gutenberg/map-fixture-wrong.php'),
    'native_loaded' => class_exists('wf_map_block', false),
    'state' => MapLifecycleEvidence::witness($tables),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
