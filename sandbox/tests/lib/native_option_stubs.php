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
require_once __DIR__ . '/wp_stubs.php';

final class WP_Hook {
    public array $callbacks = [];
}

function wp_using_ext_object_cache(): mixed {
    return $GLOBALS['native_option_external_cache'] ?? null;
}

function wp_installing(): bool {
    return ($GLOBALS['native_option_installing'] ?? false) === true;
}
