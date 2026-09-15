<?php
declare(strict_types=1);

// The caller first skips ordinary plugins: Importer main-file:119 can run
// activation on admin bootstrap, so loading it here would manufacture proof.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!current_user_can('manage_options')) throw new RuntimeException('Importer dependency probe requires its owned administrator');
$plugin = 'users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php';
$wrongPlugin = 'users-customers-import-export-for-wp-woocommerce/importer-fixture-wrong.php';
$entry = WP_PLUGIN_DIR . '/' . $plugin;
$wrongEntry = WP_PLUGIN_DIR . '/' . $wrongPlugin;
$backup = $entry . '.wprism-original';
$exactSha = '97006bae88c3a746cb3701da7138a4772d3a521553a3bdbe7ad67f5a75df40e1';
$mode = $args[0] ?? 'observe';
if ($mode === 'backup') {
    if (is_file($backup) || !is_file($entry) || hash_file('sha256', $entry) !== $exactSha) {
        throw new RuntimeException('exact Importer entry backup failed');
    }
    $bytes = (string) file_get_contents($entry);
    if (file_put_contents($backup, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('exact Importer entry backup failed');
    }
} elseif (in_array($mode, ['maximum-header', 'unreadable-header', 'restore-header'], true)) {
    if (!is_file($backup) || hash_file('sha256', $backup) !== $exactSha || !is_file($entry)) {
        throw new RuntimeException('exact Importer entry backup unavailable');
    }
    $bytes = (string) file_get_contents($backup);
    if ($mode !== 'restore-header') {
        $version = $mode === 'maximum-header' ? '2.7.6' : '';
        $bytes = str_replace("Version: 2.7.5\n", 'Version: ' . $version . "\n", $bytes, $count);
        if ($count !== 1) throw new RuntimeException('Importer header fault premise failed');
    }
    if (file_put_contents($entry, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('Importer header fault write failed');
    }
} elseif ($mode === 'wrong-basename') {
    if (!is_file($backup) || hash_file('sha256', $backup) !== $exactSha
        || !is_file($entry) || hash_file('sha256', $entry) !== $exactSha || is_file($wrongEntry)) {
        throw new RuntimeException('Importer basename fault premise failed');
    }
    deactivate_plugins($plugin);
    if (!rename($entry, $wrongEntry)) throw new RuntimeException('Importer basename fault failed');
} elseif ($mode === 'activate-wrong') {
    if (is_file($entry) || !is_file($wrongEntry) || hash_file('sha256', $wrongEntry) !== $exactSha) {
        throw new RuntimeException('Importer wrong-basename activation premise failed');
    }
    $activated = activate_plugin($wrongPlugin);
    if (is_wp_error($activated)) throw new RuntimeException('Importer wrong-basename activation failed');
} elseif ($mode === 'restore-basename') {
    if (is_file($entry) || !is_file($wrongEntry) || hash_file('sha256', $wrongEntry) !== $exactSha) {
        throw new RuntimeException('Importer basename restoration premise failed');
    }
    deactivate_plugins($wrongPlugin);
    if (!rename($wrongEntry, $entry)) throw new RuntimeException('Importer basename restoration failed');
} elseif ($mode === 'activate-exact') {
    if (!is_file($entry) || hash_file('sha256', $entry) !== $exactSha || is_file($wrongEntry)) {
        throw new RuntimeException('Importer exact activation premise failed');
    }
    $activated = activate_plugin($plugin);
    if (is_wp_error($activated)) throw new RuntimeException('Importer exact activation failed');
} elseif ($mode === 'cleanup') {
    if (!is_file($entry) || hash_file('sha256', $entry) !== $exactSha || is_file($wrongEntry)
        || !is_file($backup) || hash_file('sha256', $backup) !== $exactSha
        || !unlink($backup)) {
        throw new RuntimeException('Importer dependency fixture cleanup failed');
    }
} elseif (!in_array($mode, ['observe', 'boundary-observe'], true)) {
    throw new RuntimeException('unknown Importer dependency probe mode');
}
wp_clean_plugins_cache();
$plugins = get_plugins();
global $wpdb;
$tables = [];
foreach (['wt_iew_mapping_template', 'wt_iew_action_history'] as $suffix) {
    $name = $wpdb->prefix . $suffix;
    $wpdb->last_error = '';
    $found = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name)));
    if ($wpdb->last_error !== '' || !is_array($found) || count($found) > 1 || ($found !== [] && $found !== [$name])) {
        throw new RuntimeException('Importer dependency table observation is incomplete');
    }
    $tables[$suffix] = $found === [$name];
}
$observation = ['version' => ($plugins[$plugin]['Version'] ?? '') !== '' ? $plugins[$plugin]['Version'] : null,
    'active' => is_plugin_active($plugin), 'loaded' => defined('WT_U_IEW_VERSION'),
    'marker' => get_option('wt_u_iew_is_active', null), 'tables' => $tables];
if ($mode !== 'observe') {
    $observation += ['wrong_version' => ($plugins[$wrongPlugin]['Version'] ?? '') !== '' ? $plugins[$wrongPlugin]['Version'] : null,
        'wrong_active' => is_plugin_active($wrongPlugin),
        'entry_sha256' => is_file($entry) ? hash_file('sha256', $entry) : null,
        'wrong_sha256' => is_file($wrongEntry) ? hash_file('sha256', $wrongEntry) : null,
        'backup_sha256' => is_file($backup) ? hash_file('sha256', $backup) : null];
}
echo json_encode($observation, JSON_THROW_ON_ERROR), "\n";
