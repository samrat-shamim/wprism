<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap for the Duo drop-in — constants only, never product code.
 *
 * The product has no autoloader by design: agent/duo.php require_once's its
 * 92 files and agent/src's 224 flat `namespace Duo;` files require their own
 * dependencies. PHPStan discovers those symbols through `scanDirectories`, so
 * this file must NOT require any of them; doing so would execute drop-in code
 * inside the analyser and couple static analysis to load order.
 *
 * What analysis genuinely cannot recover on its own is the pair of constants
 * agent/duo.php defines at runtime via define(). They are parsed out of the
 * source with the exact same two regexes tools/capability-doc.php:108 uses, so
 * a rename that breaks the release gate breaks the analyser identically
 * instead of silently degrading to "constant not found".
 * Failing loudly here is the point: a silent fallback would let the version
 * boundary drift between the gate and the analyser.
 */

$repo = dirname(__DIR__);

/*
 * Mirrors tests/bootstrap.php. PHPUnit's bootstrap defines DUO_REPO_ROOT for
 * the tooling self-tests; PHPStan analyses those same test files but never
 * runs PHPUnit's bootstrap, so without this the constant reads as undefined in
 * every test that anchors a path. Defining it in both places keeps the analyser
 * and the runner describing the same world.
 */
if (!defined('DUO_REPO_ROOT')) {
    define('DUO_REPO_ROOT', $repo);
}

if (!defined('DUO_AGENT_VERSION') || !defined('DUO_SPEC_VERSION')) {
    $agentSource = (string) file_get_contents($repo . '/agent/duo.php');

    if (!defined('DUO_AGENT_VERSION')) {
        if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $agentSource, $m) !== 1) {
            fwrite(STDERR, "phpstan-bootstrap: could not resolve DUO_AGENT_VERSION\n");
            exit(1);
        }
        define('DUO_AGENT_VERSION', $m[1]);
    }

    if (!defined('DUO_SPEC_VERSION')) {
        if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $agentSource, $m) !== 1) {
            fwrite(STDERR, "phpstan-bootstrap: could not resolve DUO_SPEC_VERSION\n");
            exit(1);
        }
        define('DUO_SPEC_VERSION', (int) $m[1]);
    }
}

/*
 * WordPress host constants. The stubs packages (scanned as files below in
 * phpstan.neon.dist) declare WordPress' and WP-CLI's functions and classes but
 * not the host-defined path constants, which only exist inside a booted site.
 * Defining them here keeps `paths` files that read them from being reported as
 * undefined-constant errors without pretending any particular value is real —
 * these are placeholders for the analyser, never for the runtime.
 */
foreach ([
    'ABSPATH' => '/wordpress/',
    'WP_CONTENT_DIR' => '/wordpress/wp-content',
    'WP_PLUGIN_DIR' => '/wordpress/wp-content/plugins',
    'WPMU_PLUGIN_DIR' => '/wordpress/wp-content/mu-plugins',
    // wpdb result-shape constants. WordPress defines these with define() in
    // wp-includes/class-wpdb.php, which means php-stubs/wordpress-stubs — a
    // *declaration* dump — uses them 40 times but declares none of them.
    // Without these four, agent/src produces 50 constant.notFound errors that
    // describe the stub package's shape, not Duo's code.
    'OBJECT' => 'OBJECT',
    'OBJECT_K' => 'OBJECT_K',
    'ARRAY_A' => 'ARRAY_A',
    'ARRAY_N' => 'ARRAY_N',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
