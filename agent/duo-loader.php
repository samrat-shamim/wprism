<?php
/**
 * Duo mu-plugin loader. WordPress only auto-loads top-level files in
 * wp-content/mu-plugins/, so this file sits there and pulls in the agent
 * from the duo/ directory beside it.
 */

// This top-level file is deliberately PHP 8.0 parse-safe. Never load the
// PHP 8.2 engine, register an autoloader, or attach a partial hook graph on an
// unsupported runtime.
if (PHP_VERSION_ID < 80200) {
    if (!defined('DUO_AGENT_RUNTIME_STATUS')) {
        define('DUO_AGENT_RUNTIME_STATUS', 'unsupported_php_' . PHP_MAJOR_VERSION . '_' . PHP_MINOR_VERSION);
    }
    return;
}

require_once __DIR__ . '/duo/duo.php';
