<?php
/**
 * Run `duo adapter doctor --migration` at ONE agent state, as a child process.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A migration preflight's TARGET is the agent state the process runs at — the
 * verb's own docblock states why there is no `--target` flag, and
 * `spec_migration_estate.php`'s header states the underlying constraint: a
 * state is the pair (`agent/duo.php`'s two `define()`s, the manifest library),
 * and PHP can define `DUO_AGENT_VERSION` exactly once per process. The shipped
 * `cli/duo` executable resolves those defines from the checkout's own
 * `agent/duo.php` (`AdapterCatalog::boot()`), so it is always the CURRENT
 * agent. To drive the preflight against a POST-BUMP agent — which is the whole
 * point of the cross-check in `regress_migration_preflight.php` — the state has
 * to be established before the command boots, in its own process. That is all
 * this file does.
 *
 * The two versions are ARGUMENTS rather than derived here. `rehearsal_state_
 * versions()` (spec_migration_estate.php:124-143) is the one definition of what
 * state B is, and the suite reads the answer out of the estate's own observation
 * document and passes it in — so this driver cannot drift into a second opinion
 * about which agent the fleet is moving to.
 *
 * Everything after the three positional arguments is handed to
 * `AdapterCatalog::run()` verbatim, so what runs here is the product verb and
 * not a re-implementation of it.
 *
 * Deliberately NOT named regress_* : this is a helper the suite runs, and
 * `regress_suite_wiring.php` reserves the four class prefixes for files a
 * Makefile target runs directly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    fwrite(STDERR, "migration_preflight_state.php is a child entry point, not a library\n");
    exit(2);
}
if (($argc ?? 0) < 4) {
    fwrite(
        STDERR,
        'usage: php ' . basename(__FILE__) . " <agent-version> <spec-version> <source-or-archive-root> [preflight args...]\n"
    );
    exit(2);
}

define('DUO_AGENT_VERSION', $argv[1]);
define('DUO_SPEC_VERSION', (int) $argv[2]);
$libraryRoot = $argv[3];

// From offline/cli/: four hops to the repo root.
$preflightRoot = dirname(__DIR__, 4);
require_once $preflightRoot . '/cli/src/Adapter/AdapterCatalog.php';
require_once $preflightRoot . '/cli/src/Adapter/MigrationPreflight.php';

$args = array_slice($argv, 4);
$args[] = '--adapter-library=' . $libraryRoot;
exit(\Duo\Orchestrator\AdapterCatalog::run($args));
