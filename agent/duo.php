<?php
/**
 * Duo agent bootstrap. Loaded as an mu-plugin (via duo-loader.php next to the
 * duo/ directory) and by wp-cli. Dependency-free by design: a drop-in agent
 * must not vendor libraries.
 */

if (!defined('ABSPATH') && !(defined('WP_CLI') && WP_CLI)) {
    return;
}

require_once __DIR__ . '/src/Uuid.php';
require_once __DIR__ . '/src/Canon.php';
require_once __DIR__ . '/src/Policy.php';
require_once __DIR__ . '/src/Ledger.php';
require_once __DIR__ . '/src/Tokens.php';
require_once __DIR__ . '/src/Blocks.php';
require_once __DIR__ . '/src/Canary.php';
require_once __DIR__ . '/src/Capture.php';
require_once __DIR__ . '/src/Apply.php';
require_once __DIR__ . '/src/Journal.php';

// Provenance journal is opt-in: define('DUO_JOURNAL', true) in wp-config.php
// (or export DUO_JOURNAL=1 in the environment).
if ((defined('DUO_JOURNAL') && DUO_JOURNAL) || getenv('DUO_JOURNAL') === '1') {
    \Duo\Journal::boot();
}

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/src/Cli.php';
}
