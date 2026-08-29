<?php
declare(strict_types=1);

/**
 * The single topology gate: one mechanism, every pre-mutation door.
 *
 * Before this suite's subject existed, `Policy::assert_single_site()` threw a
 * bare \RuntimeException that only Policy-building verbs could reach, and
 * `Cli::halt_json_failure()` collapsed it to `<command>_failed` /
 * "refused at an unclassified safety gate" with `details_redacted: true`
 * (agent/src/Command/Cli.php:84-85, :98, :397-399) — so a `--format=json`
 * caller never saw the word "multisite", while journal-reset, the four
 * promotion-lease verbs and `classify --set` had no gate at all.
 *
 * What is pinned here:
 *   1. `SiteTopology::assert_single_site()` answers with a typed refusal when
 *      ONLY agent/src/Kernel/SiteTopology.php has been required — the property
 *      the Policy-free verbs depend on.
 *   2. The human sentence is byte-identical to the one Policy.php printed
 *      before the move (rule 8; the live suite greps exactly these bytes).
 *   3. The public half is machine-readable and value-free.
 *   4. Topology is answered FIRST, before the platform gate, even against a
 *      hostile database probe.
 *   5. `Journal::boot()` DECLINES on a network instead of refusing — the one
 *      door with no operator to hear a refusal.
 *   6. The reason code never enters the host's registry-blocker vocabulary.
 *   7. `AdapterRegistry::report()` reports topology ONCE, at report level,
 *      without contaminating any per-adapter verdict.
 *
 * TOPOLOGY FIXTURE GLOBAL. `$GLOBALS['wprism_topology_multisite']` is this
 * suite's name for the answer `is_multisite()` gives, and the same name the
 * cli/adapter extensions use. It is deliberately a THIRD name beside
 * regress_platform_compatibility.php's `$GLOBALS['platform_multisite']` and
 * regress_adapter_contract.php's `$GLOBALS['wprism_test_is_multisite']`: each of
 * those is private to a suite that declares its own `is_multisite()` for its
 * own subject, and none of them is shared infrastructure. Renaming them would
 * be a reformat of two unrelated suites; sharing one of them would imply an
 * interchangeability that does not exist.
 *
 * The declaration below MUST come before lib/wp_stubs.php: that file hard-codes
 * `is_multisite(): false` behind `function_exists` (sandbox/tests/lib/wp_stubs.php:846-855),
 * so a suite that requires it first can never observe a network. Same shape as
 * regress_platform_compatibility.php:30-37.
 */

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);

$GLOBALS['wprism_topology_multisite'] = false;
function is_multisite(): bool {
    return (bool) $GLOBALS['wprism_topology_multisite'];
}

require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

// ---------------------------------------------------------------------------
// 1-3. The Kernel gate, alone.
// ---------------------------------------------------------------------------
require_once $root . '/agent/src/Kernel/SiteTopology.php';

use WPrism\CommandRefusalException;
use WPrism\SiteTopology;

// The exact bytes Policy.php threw before the gate moved to Kernel, and the
// exact bytes sandbox/tests/live/regress_multisite_refusal.sh:63,65 grep for.
$sentence = 'wprism: multisite is unsupported by the certified v1 contract; '
    . 'this command is single-site only and refuses before loading policy or mutating state';

SiteTopology::assert_single_site();
wprism_check(true, 'a single-site target passes the Kernel topology gate without a Policy in the process');

$GLOBALS['wprism_topology_multisite'] = true;

$kernelRefusal = null;
try {
    SiteTopology::assert_single_site();
} catch (CommandRefusalException $refusal) {
    $kernelRefusal = $refusal;
}
wprism_check(
    $kernelRefusal instanceof CommandRefusalException,
    'requiring ONLY agent/src/Kernel/SiteTopology.php is enough to refuse a network — the property '
        . 'journal-reset, the promotion-lease verbs and classify depend on, none of which builds a Policy'
);
wprism_check_same('multisite_unsupported', $kernelRefusal?->reasonCode, 'the refusal carries the reused whole-target reason code');
wprism_check_same($sentence, $kernelRefusal?->getMessage(), 'the operator sentence is byte-identical to the one Policy.php printed');
wprism_check(
    $kernelRefusal !== null
        && str_contains($kernelRefusal->publicMessage, 'multisite is unsupported by the certified v1 contract')
        && str_contains($kernelRefusal->publicMessage, 'single-site only'),
    'the PUBLIC message carries both sentences the live suite greps, so JSON and human mode say the same thing'
);
wprism_check(
    $kernelRefusal !== null && !str_starts_with($kernelRefusal->publicMessage, 'wprism: '),
    'and drops the `wprism: ` prefix, which Cli.php calls a human-rendering convention only'
);
wprism_check(
    $kernelRefusal !== null
        && !$kernelRefusal->detailsRedacted
        && !CommandRefusalException::containsSensitivePublicDetail($kernelRefusal->payload())
        && !array_key_exists('details_redacted', $kernelRefusal->payload()),
    'the payload survives the sensitivity screen intact: no path, no value, so a machine caller gets a named reason'
);
wprism_check_same(
    'multisite_unsupported',
    $kernelRefusal?->payload()['error'] ?? null,
    'and publishes that reason as the envelope error, not as an unclassified <command>_failed'
);

// ---------------------------------------------------------------------------
// 4. Ordering through the product entry point.
// ---------------------------------------------------------------------------

/**
 * A $wpdb whose version probe throws, which is what
 * PlatformCompatibility::current_facts() turns into
 * probe_refusal('database'). If topology were answered second, a network with
 * an unreadable `SELECT VERSION()` would be told about its database instead.
 */
final class TopologyGateHostileWpdb {
    public string $last_error = '';
    public int $reads = 0;

    public function get_var(string $query): string|false|null {
        $this->reads++;
        throw new RuntimeException('MySQL server has gone away');
    }
}

$GLOBALS['wpdb'] = new TopologyGateHostileWpdb();
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
wprism_wp_store()->version = '7.0.3';

require_once $root . '/agent/src/Policy/Policy.php';

$policyRefusal = null;
try {
    WPrism\Policy::load('/definitely-missing-topology-gate-repository');
} catch (Throwable $failure) {
    $policyRefusal = $failure;
}
wprism_check(
    $policyRefusal instanceof CommandRefusalException,
    'Policy::load() on a network throws the TYPED refusal — a bare RuntimeException is not in '
        . 'Cli::PUBLIC_REFUSAL_CLASSES and was published as <command>_failed with details_redacted'
);
wprism_check_same('multisite_unsupported', $policyRefusal instanceof CommandRefusalException ? $policyRefusal->reasonCode : null, 'Policy::load() delegates to the one Kernel gate');
wprism_check_same($sentence, $policyRefusal?->getMessage(), 'and human mode still prints the identical sentence through WP_CLI::error($t->getMessage())');
wprism_check(
    $policyRefusal !== null && !str_contains($policyRefusal->getMessage(), 'site.wprism.json'),
    'the topology refusal precedes the first repository read'
);
wprism_check_same(
    0,
    $GLOBALS['wpdb']->reads,
    'and precedes the platform gate: a hostile database probe is never reached, so a network is never told '
        . 'about its database instead of its topology (the ordering regress_platform_compatibility.php:192-203 pins)'
);

// ---------------------------------------------------------------------------
// 5. The boot path declines, silently.
// ---------------------------------------------------------------------------
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Repository/Journal.php';

$journalWpdb = new \WPrismTest\FakeWpdb();
$GLOBALS['wpdb'] = $journalWpdb;

$booted = new ReflectionProperty(WPrism\Journal::class, 'booted');
$booted->setValue(null, false);

WPrism\Journal::boot();
wprism_check_same(
    [],
    wprism_wp_store()->sortedHooks('query'),
    'Journal::boot() on a network registers no `query` observer: the shutdown flush reaches Ledger::ensure() '
        . "and its four CREATE TABLEs against the SERVING blog's prefix, residue no rollback removes"
);
wprism_check_same([], wprism_wp_store()->sortedHooks('shutdown'), 'and registers no `shutdown` flush');
wprism_check_same([], $journalWpdb->queryLog(), 'and issues zero queries — declining, not refusing: boot() runs in every request of every blog');

$booted->setValue(null, false);
$GLOBALS['wprism_topology_multisite'] = false;
WPrism\Journal::boot();
wprism_check(
    count(wprism_wp_store()->sortedHooks('query')) === 1 && count(wprism_wp_store()->sortedHooks('shutdown')) === 1,
    'while a single-site target still boots the journal exactly as before'
);
$GLOBALS['wprism_topology_multisite'] = true;

// ---------------------------------------------------------------------------
// 6. The negative vocabulary pin.
// ---------------------------------------------------------------------------
require_once $root . '/cli/src/Assess/SurfaceCatalog.php';

$vocabulary = new ReflectionClass(WPrism\Orchestrator\ProjectionVocabulary::class);
$blockerVocabulary = [];
foreach ($vocabulary->getConstants() as $name => $value) {
    if (!str_starts_with($name, 'BLOCKER')) {
        continue;
    }
    foreach ((array) $value as $code) {
        $blockerVocabulary[] = (string) $code;
    }
}
wprism_check(
    $blockerVocabulary !== [],
    'the registry blocker vocabulary was actually read (a rename of the BLOCKERS_* constants must not silently '
        . 'turn the two pins below into vacuous truths)'
);
foreach (['multisite_unsupported', 'site_mode_unsupported'] as $code) {
    wprism_check(
        !in_array($code, $blockerVocabulary, true),
        "'$code' is absent from every ProjectionVocabulary::BLOCKER* array — agent refusal reason codes and "
            . 'registry blocker codes are separate namespaces, and the host retired this one from the latter '
            . '(cli/src/Contract/ProjectionVocabulary.php:219-235)'
    );
    wprism_check(
        !in_array($code, WPrism\Orchestrator\SurfaceCatalog::CONDITION_CODES, true),
        "'$code' is not a SurfaceCatalog condition code either, so it can never be re-filed as a per-surface row"
    );
}

// ---------------------------------------------------------------------------
// 7. One whole-report blocker, zero per-adapter contamination.
// ---------------------------------------------------------------------------
$adapterLibrary = WPrism\AdapterLibrary::fromSourceTree($root);
$dispositions = WPrism\ManifestDispositions::load_library($adapterLibrary);
wprism_check($dispositions !== null, 'the shipped reviewed disposition registry loads');

$coreManifest = json_decode(
    (string) file_get_contents($adapterLibrary->package('core')?->manifestPath() ?? ''),
    true,
    flags: JSON_THROW_ON_ERROR
);
$target = static fn(bool $multisite): array => [
    'wordpress' => '7.0.3',
    'php' => PHP_VERSION,
    'database' => ['client' => '11.8.8', 'server' => '11.8.8-MariaDB', 'engine' => 'MariaDB'],
    'multisite' => $multisite,
    'active_plugins' => [],
    'active_theme' => ['template' => '', 'stylesheet' => ''],
    'plugins' => [],
    'themes' => [],
];

$singleSiteReport = WPrism\AdapterRegistry::report($dispositions, [$coreManifest], [], $target(false));
$multisiteReport = WPrism\AdapterRegistry::report($dispositions, [$coreManifest], [], $target(true));

wprism_check_json_equal(
    $singleSiteReport['manifests'],
    $multisiteReport['manifests'],
    'every manifest row — verdict status and reasons included — is byte-identical between the two runs: '
        . 'topology contaminates no per-adapter verdict'
);
wprism_check_same(
    count($singleSiteReport['blockers']) + 1,
    count($multisiteReport['blockers']),
    'a network adds exactly ONE blocker to wprism-capability-report/v1'
);
$added = array_values(array_filter(
    $multisiteReport['blockers'],
    static fn(array $row): bool => ($row['code'] ?? null) === 'site_mode_unsupported'
));
wprism_check_same('platform', $added[0]['name'] ?? null, "and it is reported at report level under name 'platform', never against a manifest or a profile: surface");
wprism_check_same('blocked', $added[0]['status'] ?? null, 'with the blocked status every other blocker row carries');
wprism_check_same(false, $multisiteReport['ready'], "`ready` is `\$blockers === []`, so it flips to false — which is the whole point of the row");
wprism_check_same(
    $singleSiteReport['ready'],
    WPrism\AdapterRegistry::report($dispositions, [$coreManifest], [], $target(false))['ready'],
    'and a single-site report is unchanged, readiness included'
);

wprism_check_summary('topology gate');
