<?php
declare(strict_types=1);

/**
 * Opt-in native get_option ordering over shared hooks/cache/wpdb. The ordinary
 * wp_stubs getter intentionally bypasses these surfaces. This is deterministic
 * mechanism evidence; actual core execution remains a separate live gate.
 */
define('ABSPATH', dirname(__DIR__) . '/fixtures/native-option-core/');
define('WPINC', 'wp-includes');
require_once ABSPATH . WPINC . '/class-wp-object-cache.php';
require_once ABSPATH . WPINC . '/cache.php';
require_once ABSPATH . WPINC . '/option.php';
require_once ABSPATH . WPINC . '/class-wp-hook.php';
require_once ABSPATH . WPINC . '/load.php';
if (defined('WPRISM_TEST_NATIVE_PERMALINKS')) {
    foreach (['class-wp-post.php', 'post.php', 'class-wp-post-type.php', 'class-wp-rewrite.php',
        'functions.php', 'plugin.php', 'formatting.php', 'link-template.php',
        'class-wp-user.php', 'pluggable.php', 'user.php', 'capabilities.php'] as $file) {
        require_once ABSPATH . WPINC . '/' . $file;
    }
}
require_once __DIR__ . '/wp_stubs.php';
