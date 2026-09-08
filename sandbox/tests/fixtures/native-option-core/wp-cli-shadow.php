<?php
declare(strict_types=1);

namespace WP_CLI;

// Each fresh child shadows exactly one unqualified Runner call. Even the
// branch unreachable on this invocation must not borrow arbitrary PHP.
if ($sourceControl === '--cli-shadow-get_option') {
    function get_option($name) { $GLOBALS['foreign_native_calls']++; return 'http://foreign.invalid'; }
} elseif ($sourceControl === '--cli-shadow-is_multisite') {
    function is_multisite() { $GLOBALS['foreign_native_calls']++; return false; }
} elseif ($sourceControl === '--cli-shadow-switch_to_blog') {
    function switch_to_blog($id) { $GLOBALS['foreign_native_calls']++; }
} elseif ($sourceControl === '--cli-shadow-restore_current_blog') {
    function restore_current_blog() { $GLOBALS['foreign_native_calls']++; }
} elseif ($sourceControl === '--cli-shadow-is_string') {
    function is_string($value) { $GLOBALS['foreign_native_calls']++; return true; }
} elseif ($sourceControl === '--cli-shadow-ltrim') {
    function ltrim($value, $characters) { $GLOBALS['foreign_native_calls']++; return $value; }
}
