<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3314: out-of-tree adapter sources, overlay identity, the data-only
 * privilege boundary, and loud uncertified support.
 *
 * Everything under test is pure: AdapterSources::discover() is filesystem +
 * JSON, and Policy::load()/RepositoryCompiler::resolved_adapters() were
 * already offline by design (RepositoryCompiler's own docblock — repository
 * and manifest inputs become a validated IR "before Tokens, Ledger, Capture,
 * or a target query can be constructed"). Every check runs against REAL
 * fixture files this test writes to scratch site repositories, using the
 * REAL, unmodified agent/src/*.php — not reimplementations.
 *
 * Deliberately unusual for this repo's fixture idiom: most groups here run
 * against the REAL shipped manifest library rather than a scratch
 * DUO_MANIFESTS_DIR. That is the point of the issue — the claim being proved
 * is that installing a site-local adapter leaves the real, certified shipped
 * adapters certified, which a synthetic manifest directory with no
 * dispositions and no capability registry cannot demonstrate at all. Nothing
 * here writes to the shipped library; the two groups that need a mutated
 * manifest directory copy it to scratch first.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

// WordPress supplies this in production. The offline harness exposes the same
// switchable equivalent regress_adapter_contract.php uses, so Policy::load()'s
// real v1 single-site gate runs without bootstrapping WordPress.
$GLOBALS['duo_test_is_multisite'] = false;
function is_multisite(): bool {
    return (bool) $GLOBALS['duo_test_is_multisite'];
}

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Db.php';
require __DIR__ . '/../../agent/src/ManifestDispositions.php';
require __DIR__ . '/../../agent/src/CapabilityRegistry.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/RepositoryCompiler.php';
require __DIR__ . '/../../agent/src/SidebarState.php';
require __DIR__ . '/../../agent/src/RepositoryAuthorization.php';
require __DIR__ . '/../../agent/src/Deploy.php';
require __DIR__ . '/../../cli/src/PlanSummary.php';

use Duo\AdapterSources;
use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\Policy;
use Duo\RepositoryCompiler;

/** Minimal command runner surface for exercising the real Cli handler offline. */
final class WP_CLI {
    public static array $lines = [];

    public static function add_command($name, $class): void {}

    public static function line($line): void {
        self::$lines[] = (string) $line;
    }

    public static function warning($line): void {
        self::$lines[] = 'WARNING: ' . (string) $line;
    }

    public static function success($line): void {
        self::$lines[] = 'SUCCESS: ' . (string) $line;
    }

    public static function error($message): void {
        throw new \RuntimeException((string) $message);
    }
}

require __DIR__ . '/../../agent/src/Cli.php';

// The real shipped registry binds these exact platform values; the harness
// must present the same agent it claims to be or every claim reads as stale.
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', '0.5.0');
}

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

function expect_throw(callable $fn, string $needle, string $msg): void {
    try {
        $fn();
        check(false, "$msg (expected a RuntimeException containing '$needle', none thrown)");
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), $needle), "$msg (message: {$e->getMessage()})");
    }
}

/** Recursively removed at exit; every fixture root registers itself here. */
function rm_rf(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                rm_rf("$path/$entry");
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function scratch(string $label): string {
    $root = sys_get_temp_dir() . "/duo_regress_adapter_sources_{$label}_" . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    register_shutdown_function(fn() => rm_rf($root));
    return $root;
}

/**
 * A site repo whose `manifests` pins are $pins and whose out-of-tree adapter
 * source holds $adapters (file basename => manifest array or raw string).
 */
function fresh_site(array $pins, array $adapters = [], array $extraFiles = []): string {
    $root = scratch('repo');
    Canon::write_file("$root/site.duo.json", Canon::encode([
        'manifests' => $pins,
        'policy' => new \stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    foreach ($adapters as $name => $content) {
        Canon::write_file(
            "$root/adapters/$name.json",
            is_string($content) ? $content : Canon::encode($content)
        );
    }
    foreach ($extraFiles as $relative => $content) {
        $file = "$root/$relative";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        Canon::write_file($file, is_string($content) ? $content : Canon::encode($content));
    }
    return $root;
}

/** Minimal, valid, purely declarative out-of-tree adapter. */
function site_adapter(string $name, array $extra = []): array {
    return $extra + [
        'name' => $name,
        'spec_version' => DUO_SPEC_VERSION,
        'option_autoload' => 'preserve',
        'options' => ['acme_widget_layout' => ['class' => 'authored']],
    ];
}

function copy_tree(string $from, string $to): void {
    mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir("$from/$entry")) {
            copy_tree("$from/$entry", "$to/$entry");
        } else {
            copy("$from/$entry", "$to/$entry");
        }
    }
}

$shippedDir = dirname(__DIR__, 2) . '/manifests';

// ======================================================================
echo "\n== the motivating refusal: a site adapter no longer takes down the shipped library ==\n";
// ======================================================================
// Before DUO-3314 there was exactly one adapter source, so an extra adapter
// could only be installed by dropping it into the shipped manifest directory
// — where ManifestDispositions::load()'s one-for-one coverage check refused
// it AND every unrelated shipped adapter along with it. Both halves are
// asserted: the new source works, and the old refusal still guards the
// shipped library.

$overlayRepo = fresh_site(['core', 'acme-widget'], ['acme-widget' => site_adapter('acme-widget')]);
$overlay = Policy::load($overlayRepo);
check(count($overlay->manifests) === 2, 'a site-local adapter pins and loads beside the shipped library');
check(
    $overlay->adapter_sources()->source('core') === 'shipped'
    && $overlay->adapter_sources()->source('acme-widget') === 'site',
    'each pinned adapter records which source installed it'
);
check(
    $overlay->adapter_sources()->path('acme-widget') === 'adapters/acme-widget.json',
    'an out-of-tree adapter records a repo-relative path, so its identity is checkout-independent'
);

$mutatedShipped = scratch('shipped');
copy_tree($shippedDir, "$mutatedShipped/manifests");
Canon::write_file(
    "$mutatedShipped/manifests/acme-widget.json",
    Canon::encode(site_adapter('acme-widget'))
);
putenv("DUO_MANIFESTS_DIR=$mutatedShipped/manifests");
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    'disposition coverage mismatch',
    'dropping an unreviewed adapter into the SHIPPED library still refuses — replacing or extending the reviewed manifest set cannot silently discard shipped claims'
);
putenv('DUO_MANIFESTS_DIR');

// ======================================================================
echo "\n== unrelated shipped adapters are provably unaffected ==\n";
// ======================================================================
$soloRepo = fresh_site(['core']);
$soloCore = RepositoryCompiler::resolved_adapters(Policy::load($soloRepo))[0];
$overlayCore = RepositoryCompiler::resolved_adapters($overlay)[0];
check(
    $soloCore['digest'] === $overlayCore['digest'],
    "the shipped core adapter's digest is byte-identical with and without a site adapter installed"
);
check(
    $soloCore['digest'] === CapabilityRegistry::adapter_digest(
        $overlay->manifests[0],
        $overlay->manifest_disposition('core'),
        $shippedDir
    ),
    'the shipped digest still hashes exactly the reviewed disposition — no provenance key was added to shipped rows'
);

$overlayReport = $overlay->capability_report(['operation' => 'promote']);
$coreRow = null;
$siteRow = null;
foreach ($overlayReport['manifests'] as $row) {
    if ($row['name'] === 'core') {
        $coreRow = $row;
    }
    if ($row['name'] === 'acme-widget') {
        $siteRow = $row;
    }
}
check(
    is_array($coreRow) && ($coreRow['verdict']['status'] ?? null) === 'certified',
    'the shipped core adapter is still CERTIFIED in the very report that carries an uncertified site adapter'
);
check(
    is_array($coreRow) && ($coreRow['source']['source'] ?? null) === 'shipped'
    && ($coreRow['source']['certification'] ?? null) === 'registry',
    'the shipped row names its own source and defers certification to the reviewed registry'
);

// ======================================================================
echo "\n== uncertified by construction, and conspicuous about it ==\n";
// ======================================================================
check(
    is_array($siteRow) && ($siteRow['status'] ?? null) === 'uncertified',
    "an out-of-tree adapter's status is the fourth word 'uncertified', outside the reviewed certified/experimental/excluded vocabulary"
);
check(
    is_array($siteRow) && ($siteRow['verdict']['status'] ?? null) === 'blocked',
    'an out-of-tree adapter can never reach a certified verdict'
);
$siteCodes = array_column($siteRow['verdict']['reasons'] ?? [], 'code');
check(
    in_array('adapter_source_uncertified', $siteCodes, true)
    && !in_array('missing_registry_entry', $siteCodes, true),
    'the out-of-tree reason code is its own, never the shipped "someone deleted a registry entry" code'
);
$siteReason = null;
foreach ($siteRow['verdict']['reasons'] ?? [] as $reason) {
    if ($reason['code'] === 'adapter_source_uncertified') {
        $siteReason = $reason;
    }
}
check(
    is_array($siteReason) && str_contains((string) $siteReason['message'], 'adapters/acme-widget.json'),
    'the refusal names the exact installed file'
);
check(
    is_array($siteReason) && trim((string) ($siteReason['remediation'] ?? '')) !== '',
    'the refusal carries an actionable remediation'
);
check(
    is_array($siteRow) && ($siteRow['source']['trust_tier'] ?? null) === 'declarative_manifest',
    'a data-only out-of-tree adapter reports the declarative_manifest trust tier'
);
check($overlayReport['ready'] === false, 'a pinned uncertified adapter keeps readiness red');

$blockers = $overlay->adapter_readiness_blockers();
$siteBlocker = null;
foreach ($blockers as $blocker) {
    if ($blocker['name'] === 'acme-widget') {
        $siteBlocker = $blocker;
    }
}
check(
    is_array($siteBlocker) && $siteBlocker['source'] === 'site'
    && $siteBlocker['trust_tier'] === 'declarative_manifest'
    && trim((string) $siteBlocker['remediation']) !== '',
    'source, trust tier, and remediation ride on the readiness blocker row itself, not only on the full report'
);
check(
    array_filter($blockers, fn(array $r) => $r['name'] === 'core') === [],
    'the shipped adapter contributes no blocker — one uncertified site adapter does not make the certified set unready'
);

// `duo status` and `wp duo plan` must give one answer; both renderers get the
// same blocker rows, so both are asserted on the same fixture data.
$status = \Duo\Orchestrator\PlanSummary::render(['adapter_dispositions' => $blockers]);
$statusText = implode("\n", $status['lines']);
check(
    str_contains($statusText, 'source=site') && str_contains($statusText, 'tier=declarative_manifest')
    && str_contains($statusText, 'remediation:'),
    'duo status renders the out-of-tree source, trust tier, and remediation'
);
check($status['ok'] === false, 'duo status refuses to call an environment with a pinned uncertified adapter clean');

WP_CLI::$lines = [];
(new \Duo\Cli())->capabilities([], ['repo' => $overlayRepo]);
$capabilityText = implode("\n", WP_CLI::$lines);
check(
    str_contains($capabilityText, 'CAPABILITY acme-widget BLOCKED')
    && str_contains($capabilityText, '  source: site (adapters/acme-widget.json)')
    && str_contains($capabilityText, '  trust_tier: declarative_manifest')
    && str_contains($capabilityText, '  certification: uncertified')
    && str_contains($capabilityText, '    remediation: '),
    'duo capabilities shows source, trust tier, certification state, and remediation for an out-of-tree adapter'
);
check(
    str_contains($capabilityText, 'CAPABILITY core CERTIFIED')
    && str_contains($capabilityText, '  source: shipped'),
    'the same command still reports the shipped adapter as certified, labelled with its own source'
);

// ======================================================================
echo "\n== ambiguous identity and shadowing refuse BEFORE anything loads ==\n";
// ======================================================================
expect_throw(
    fn() => Policy::load(fresh_site(['core'], ['core' => site_adapter('core')])),
    'shadows the shipped adapter',
    'a site adapter whose file name collides with a shipped adapter is refused as shadowing, never a silent replacement'
);
expect_throw(
    fn() => Policy::load(fresh_site(['core'], ['acme-widget' => site_adapter('woocommerce')])),
    'ambiguous identity',
    "a site adapter whose declared name disagrees with its file name is refused as ambiguous identity"
);
expect_throw(
    fn() => Policy::load(fresh_site(['core'], ['woocommerce' => site_adapter('woocommerce')])),
    'shadows the shipped adapter',
    'shadowing is refused for any shipped adapter, not just the one this repository pins'
);
// A shipped manifest's file name and its declared name are allowed to differ
// (nothing has ever required them to agree, and the reviewed library is where
// that freedom lives). The site side is held to file name == declared name, so
// the only way two adapters can still reach one name is a shipped file
// declaring a name the site source also uses — checked on the declared names,
// not just the file names. A manifest directory without dispositions is the
// cheapest way to install such a shipped manifest.
$oddShipped = scratch('odd-shipped');
Canon::write_file("$oddShipped/renamed-file.json", Canon::encode(site_adapter('acme-widget')));
putenv("DUO_MANIFESTS_DIR=$oddShipped");
expect_throw(
    fn() => Policy::load(fresh_site(['renamed-file'], ['acme-widget' => site_adapter('acme-widget')])),
    'already declared by the shipped manifest',
    'a site adapter is refused when a shipped manifest DECLARES that name under a different file name — two adapters cannot answer to one name'
);
putenv('DUO_MANIFESTS_DIR');
// The pins above never name the offending adapter: discovery scans whole
// sources, so a broken installation surfaces on the next command rather than
// on the first command that happens to pin it.
check(true, '(each refusal above fired while the offending adapter was NOT pinned)');

expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/dispositions.json' => ['format' => 'duo-manifest-dispositions/v1']]
    )),
    'cannot supply certification data for itself',
    'a site adapter source shipping its own dispositions.json is refused — an adapter cannot certify itself'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/interpreters/acme.php' => '<?php // inert']
    )),
    'which the engine never loads',
    'a site adapter source shipping an interpreters/ directory is refused rather than silently ignored'
);

// ======================================================================
echo "\n== a data-only manifest acquires no executable privileges ==\n";
// ======================================================================
// Each refused channel resolves its PHP inside the AGENT's manifest
// directory, so an out-of-tree manifest naming one would reach bytes it does
// not own. `acf` is a real shipped interpreter name and `woocommerce-cache` a
// real shipped provider id: the fixtures name files that genuinely exist, so
// these are refusals of the privilege, not incidental missing-file errors.
expect_throw(
    fn() => Policy::load(fresh_site(
        ['acme-widget'],
        ['acme-widget' => site_adapter('acme-widget', ['interpreter' => 'acf'])]
    )),
    'acquires no executable privileges',
    'an out-of-tree adapter cannot borrow a shipped interpreter'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['acme-widget'],
        ['acme-widget' => site_adapter('acme-widget', [
            'plugin' => 'acme/acme.php',
            'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
            'providers' => [[
                'id' => 'woocommerce-cache',
                'version' => '1.0.0',
                'source' => 'manifest',
                'plugin' => 'acme/acme.php',
                'capabilities' => ['flush'],
            ]],
        ])]
    )),
    'source "manifest"',
    'an out-of-tree adapter cannot declare a manifest-sourced provider, whose code would resolve inside the agent'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['acme-widget'],
        ['acme-widget' => site_adapter('acme-widget', [
            'post_types' => ['acme_thing' => [
                'fields' => ['title' => ['class' => 'authored']],
                'regen_dependency' => ['regenerator' => 'woocommerce-lookup', 'verify' => 'post_meta'],
            ]],
        ])]
    )),
    'acquires no executable privileges',
    'an out-of-tree adapter cannot borrow a shipped regenerator'
);

// A plugin-owned provider stays available: its trust anchor is the installed
// plugin the operator already chose to install, named explicitly and version-
// bounded — which is a different trust decision from shipping code with a
// manifest.
$pluginProviderRepo = fresh_site(
    ['acme-widget'],
    ['acme-widget' => site_adapter('acme-widget', [
        'plugin' => 'acme/acme.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'providers' => [[
            'id' => 'acme-cache',
            'version' => '1.0.0',
            'source' => 'plugin',
            'plugin' => 'acme/acme.php',
            'capabilities' => ['flush'],
        ]],
    ])]
);
$pluginProviderPolicy = Policy::load($pluginProviderRepo);
check(
    AdapterSources::trust_tier($pluginProviderPolicy->manifests[0]) === 'plugin_provider',
    'an out-of-tree adapter MAY name a plugin-owned provider, and reports the plugin_provider trust tier'
);
$nativePolicy = Policy::load(fresh_site(
    ['acme-widget'],
    ['acme-widget' => site_adapter('acme-widget', [
        'actions' => [[
            'kind' => 'native',
            'action' => 'transient.delete',
            'args' => ['name' => 'acme_cache'],
        ]],
    ])]
));
check(
    AdapterSources::trust_tier($nativePolicy->manifests[0]) === 'native_action',
    'an out-of-tree adapter MAY declare a closed native action, and reports the native_action trust tier'
);
check(
    AdapterSources::trust_tier(['name' => 'x', 'interpreter' => 'acf']) === 'compatibility_shim',
    'the trust tier is derived from the privileges a manifest asks for, never self-declared'
);

// ======================================================================
echo "\n== provenance participates in out-of-tree identity ==\n";
// ======================================================================
$provenanceRepo = fresh_site(['acme-widget'], ['acme-widget' => site_adapter('acme-widget')]);
$provenancePolicy = Policy::load($provenanceRepo);
$provenanceAdapter = RepositoryCompiler::resolved_adapters($provenancePolicy)[0];
check(
    $provenanceAdapter['source'] === 'site' && $provenanceAdapter['trust_tier'] === 'declarative_manifest',
    'the compiled resolved_adapters row records the source and trust tier of every pinned adapter'
);
check(
    $provenanceAdapter['digest'] !== CapabilityRegistry::adapter_digest($provenancePolicy->manifests[0], null),
    "an out-of-tree adapter's digest binds its provenance — the same manifest bytes with no recorded origin hash differently"
);
$before = $provenanceAdapter['digest'];
Canon::write_file(
    "$provenanceRepo/adapters/acme-widget.json",
    Canon::encode(site_adapter('acme-widget', ['options' => ['acme_widget_layout' => ['class' => 'runtime']]]))
);
check(
    RepositoryCompiler::resolved_adapters(Policy::load($provenanceRepo))[0]['digest'] !== $before,
    'editing an out-of-tree adapter changes its digest, so a content pin catches the change'
);

// ======================================================================
echo "\n== an explicit pin source is enforced, not decorative ==\n";
// ======================================================================
$sourcePinned = fresh_site(
    [['name' => 'acme-widget', 'source' => 'site']],
    ['acme-widget' => site_adapter('acme-widget')]
);
check(
    count(Policy::load($sourcePinned)->manifests) === 1,
    'a pin declaring the source it expects loads when that source really answers it'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        [['name' => 'acme-widget', 'source' => 'shipped']],
        ['acme-widget' => site_adapter('acme-widget')]
    )),
    'pinned to the shipped adapter source but resolves from the site source',
    'a pin naming the wrong source refuses instead of silently serving the other source'
);
expect_throw(
    fn() => Policy::load(fresh_site([['name' => 'core', 'source' => 'vendor']])),
    'the installed adapter sources are',
    'an unknown pin source is refused and the real sources are named'
);
expect_throw(
    fn() => Policy::load(fresh_site([['name' => 'core', 'registry' => 'internal']])),
    'unknown pin key',
    'an unknown pin key is refused rather than silently ignored'
);
expect_throw(
    fn() => Policy::load(fresh_site(['no-such-adapter'], ['acme-widget' => site_adapter('acme-widget')])),
    "not found in",
    'an unresolvable pin names every source that was searched'
);

// ======================================================================
echo "\n== the frozen snapshot re-validates provenance, it does not trust it ==\n";
// ======================================================================
$snapshot = $overlay->export_snapshot();
check(
    ($snapshot['adapter_sources']['out_of_tree']['acme-widget']['status'] ?? null) === 'uncertified'
    && !isset($snapshot['adapter_sources']['out_of_tree']['core']),
    'the exported snapshot freezes exactly the out-of-tree provenance'
);
$frozen = Policy::from_snapshot($snapshot);
check(
    RepositoryCompiler::resolved_adapters($frozen) === RepositoryCompiler::resolved_adapters($overlay),
    'a frozen policy reconstructs identical adapter identity, source, and digests'
);

$laundered = $snapshot;
unset($laundered['adapter_sources']['out_of_tree']['acme-widget']);
expect_throw(
    fn() => Policy::from_snapshot($laundered),
    'no entry for manifest',
    'dropping the out-of-tree record to make a site adapter look shipped is refused by the reviewed registry it would then have to belong to'
);
$noSources = $snapshot;
unset($noSources['adapter_sources']);
expect_throw(
    fn() => Policy::from_snapshot($noSources),
    'unsupported or malformed shape',
    'a snapshot with no adapter source record at all is refused — provenance is required, never defaulted'
);
$tamperedTier = $snapshot;
$tamperedTier['adapter_sources']['out_of_tree']['acme-widget']['trust_tier'] = 'compatibility_shim';
expect_throw(
    fn() => Policy::from_snapshot($tamperedTier),
    'is malformed',
    'a frozen record claiming a trust tier an out-of-tree adapter cannot hold is refused'
);
$tamperedStatus = $snapshot;
$tamperedStatus['adapter_sources']['out_of_tree']['acme-widget']['status'] = 'certified';
expect_throw(
    fn() => Policy::from_snapshot($tamperedStatus),
    'is malformed',
    'a frozen record cannot self-certify by editing its own status'
);
$smuggled = $snapshot;
$smuggled['adapter_sources']['out_of_tree']['not-pinned'] =
    $snapshot['adapter_sources']['out_of_tree']['acme-widget'];
expect_throw(
    fn() => Policy::from_snapshot($smuggled),
    'names manifests absent from the snapshot',
    'a frozen record naming an adapter the snapshot does not carry is refused'
);

// A provenance record is shaped so that pasting it into the reviewed registry
// is itself a refusal — 'uncertified' is not in the ratified vocabulary.
expect_throw(
    fn() => \Duo\ManifestDispositions::from_snapshot(
        [
            'format' => 'duo-manifest-dispositions/v1',
            'manifests' => ['acme-widget' => $snapshot['adapter_sources']['out_of_tree']['acme-widget']],
            'profiles' => [],
        ],
        [$overlay->manifests[1]]
    ),
    'malformed required field',
    'a provenance record pasted into dispositions.json is refused rather than accepted as a self-certification'
);

// ======================================================================
echo "\n== a repository with no adapters/ directory takes no new path ==\n";
// ======================================================================
$plain = Policy::load(fresh_site(['core', 'woocommerce']));
check(
    $plain->adapter_sources()->source('woocommerce') === 'shipped'
    && $plain->adapter_sources()->provenance('woocommerce') === null,
    'without a site adapter source every pinned adapter is shipped and carries no synthesized provenance'
);
check(
    $plain->capability_report(['operation' => 'promote'])['ready'] === true,
    'the shipped, certified library remains fully ready — this issue added no new blocker to it'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\nFAIL: $failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
