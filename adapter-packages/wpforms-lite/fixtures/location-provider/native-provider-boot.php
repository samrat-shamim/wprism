<?php
declare(strict_types=1);

// Disposable-site instrumentation, not a provider or subprocess runner. The
// real engine launches its unchanged WP-CLI children; this records each boot.
if (!defined('ABSPATH')) throw new RuntimeException('native WordPress boot required');
$proof = WP_CONTENT_DIR . '/wprism-wpforms-provider-proof';
if (!is_dir($proof) || is_link($proof)) throw new RuntimeException('owned native proof root missing');
$boot = ['pid' => getmypid(), 'boot' => bin2hex(random_bytes(16)),
    'child' => in_array('\\WPrism\\ProviderOperationProcess::child_main();', $GLOBALS['argv'] ?? [], true)];
$GLOBALS['wpforms_location_fixture_boot'] = $boot;
$bytes = json_encode($boot, JSON_THROW_ON_ERROR) . "\n";
$mask = umask(0077);
try {
    if (file_put_contents($proof . '/boots.jsonl', $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('cannot retain native boot identity');
    }
} finally { umask($mask); }
$register = static function (): void {
    foreach (['both' => [true, true], 'public' => [true, false], 'query' => [false, true], 'hidden' => [false, false]] as $name => [$public, $query]) {
        register_post_type('wpf_' . $name, ['public' => $public, 'publicly_queryable' => $query,
            'rewrite' => ['slug' => 'wpf-' . $name], 'label' => 'WPrism ' . $name]);
    }
};
if (did_action('init')) $register();
else add_action('init', $register);
$sidebars = static function (): void {
    register_sidebar(['id' => 'wprism-locator', 'name' => 'WPrism locator sidebar']);
};
if (did_action('widgets_init')) $sidebars();
else add_action('widgets_init', $sidebars);
