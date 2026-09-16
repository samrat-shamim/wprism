<?php
declare(strict_types=1);

// Skip ordinary plugins for fault injection and observation. Loading a plugin
// before the baseline could hide writes performed by the protected Apply.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!current_user_can('manage_options')) throw new RuntimeException('Qi dependency evidence requires its owned administrator');
$plugin = 'qi-blocks/class-qi-blocks.php';
$wrongPlugin = 'qi-blocks/qi-fixture-wrong.php';
$entry = WP_PLUGIN_DIR . '/' . $plugin;
$wrongEntry = WP_PLUGIN_DIR . '/' . $wrongPlugin;
$backup = $entry . '.wprism-original';
$exactSha = '1e7c3ec1b77eb8d1663aaf177cfd8533a3794d96015d08236796872f25b77e5e';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Qi dependency evidence: ' . $why);
};
$mode = $args[0] ?? 'observe';
if ($mode === 'snapshot') {
    $trees = [];
    foreach (['plugins', 'uploads'] as $name) {
        $trees[$name] = WPrism\FilesystemTreeSnapshot::observe(WP_CONTENT_DIR, WP_CONTENT_DIR . '/' . $name,
            $name, 'Qi native dependency files', 'native dependency snapshot');
    }
    echo json_encode($trees, JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($mode === 'backup') {
    $check(!file_exists($backup) && is_file($entry) && hash_file('sha256', $entry) === $exactSha, 'exact entry backup premise');
    $bytes = (string) file_get_contents($entry);
    $check(file_put_contents($backup, $bytes, LOCK_EX) === strlen($bytes), 'complete entry backup');
} elseif (in_array($mode, ['maximum-header', 'unreadable-header', 'restore-header'], true)) {
    $check(is_file($backup) && hash_file('sha256', $backup) === $exactSha && is_file($entry), 'exact header backup');
    $bytes = (string) file_get_contents($backup);
    if ($mode !== 'restore-header') {
        $bytes = str_replace("Version: 1.5.2\n", 'Version: ' . ($mode === 'maximum-header' ? '1.5.3' : '') . "\n", $bytes, $count);
        $check($count === 1, 'one native Version header fault');
    }
    $check(file_put_contents($entry, $bytes, LOCK_EX) === strlen($bytes), 'complete header write');
} elseif ($mode === 'wrong-basename') {
    $check(is_file($backup) && hash_file('sha256', $backup) === $exactSha && is_file($entry)
        && hash_file('sha256', $entry) === $exactSha && !file_exists($wrongEntry), 'exact basename fault premise');
    deactivate_plugins($plugin);
    $check(rename($entry, $wrongEntry), 'native entry rename');
} elseif ($mode === 'activate-wrong' || $mode === 'activate-exact') {
    $file = $mode === 'activate-wrong' ? $wrongEntry : $entry;
    $name = $mode === 'activate-wrong' ? $wrongPlugin : $plugin;
    $check(is_file($file) && hash_file('sha256', $file) === $exactSha, 'exact activation bytes');
    if ($mode === 'activate-wrong') $check(!file_exists($entry), 'canonical entry absent');
    $check(!is_wp_error(activate_plugin($name)), 'native activation');
} elseif ($mode === 'restore-basename') {
    $check(!file_exists($entry) && is_file($wrongEntry) && hash_file('sha256', $wrongEntry) === $exactSha, 'exact basename restoration premise');
    deactivate_plugins($wrongPlugin);
    $check(rename($wrongEntry, $entry), 'native basename restoration');
} elseif ($mode === 'cleanup') {
    $check(is_file($entry) && hash_file('sha256', $entry) === $exactSha && is_file($backup)
        && hash_file('sha256', $backup) === $exactSha && !file_exists($wrongEntry), 'exact final boundary');
    $check(unlink($backup), 'owned backup cleanup');
} else {
    $check($mode === 'observe', 'declared mode');
}
wp_clean_plugins_cache();
$plugins = get_plugins();
echo json_encode(['version' => ($plugins[$plugin]['Version'] ?? '') !== '' ? $plugins[$plugin]['Version'] : null,
    'active' => is_plugin_active($plugin), 'loaded' => defined('QI_BLOCKS_VERSION'),
    'wrong_version' => ($plugins[$wrongPlugin]['Version'] ?? '') !== '' ? $plugins[$wrongPlugin]['Version'] : null,
    'wrong_active' => is_plugin_active($wrongPlugin),
    'entry_sha256' => is_file($entry) ? hash_file('sha256', $entry) : null,
    'wrong_sha256' => is_file($wrongEntry) ? hash_file('sha256', $wrongEntry) : null,
    'backup_sha256' => is_file($backup) ? hash_file('sha256', $backup) : null], JSON_THROW_ON_ERROR), "\n";
