<?php
declare(strict_types=1);

function is_ssl(): bool {
    return (isset($_SERVER['HTTPS']) && (strtolower((string) $_SERVER['HTTPS']) === 'on' || $_SERVER['HTTPS'] === '1'))
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443');
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool { return false; }
}
function wp_using_ext_object_cache(): mixed { return $GLOBALS['native_option_external_cache'] ?? null; }
function wp_installing(): bool { return ($GLOBALS['native_option_installing'] ?? false) === true; }
