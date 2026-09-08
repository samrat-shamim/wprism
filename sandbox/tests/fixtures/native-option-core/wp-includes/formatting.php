<?php
declare(strict_types=1);

function trailingslashit(string $value): string { return untrailingslashit($value) . '/'; }
function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
if (!function_exists('wp_parse_str')) {
    function wp_parse_str(string $value, mixed &$result): void {
        parse_str($value, $result);
        $result = apply_filters('wp_parse_str', $result);
    }
}
