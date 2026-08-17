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
 * Deliberately unusual for this repo's fixture idiom: the groups here run
 * against the REAL shipped manifest library — every manifest, disposition,
 * interpreter, provider, and regenerator byte for byte. That is the point of
 * the issue — the claim being proved is that installing a site-local adapter
 * leaves the real, certified shipped adapters certified, which a synthetic
 * manifest directory with no dispositions and no capability registry cannot
 * demonstrate at all.
 *
 * What it does NOT depend on (DUO-3379) is where the repository happens to
 * sit in its certification cycle. Those real bytes are served from a scratch
 * copy whose certification evidence certified_library() re-seals against the
 * working tree, because the checked-in attestation legitimately expires on
 * any branch that edits a certification-bound input and takes every certified
 * claim with it until the affected subject records are imported. See that
 * function for the full rationale, the fixture group for the proof that the
 * re-seal is genuinely current for these bytes, and the fail-closed group for
 * the proof that expired, stale, and malformed evidence still refuse.
 * Nothing here writes to the shipped library; every mutated directory is a
 * scratch copy.
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

require __DIR__ . '/../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../agent/src/Policy/ManifestDispositions.php';
require __DIR__ . '/../../agent/src/Adapter/CapabilityRegistry.php';
require __DIR__ . '/../../agent/src/Policy/Policy.php';
require __DIR__ . '/../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../agent/src/Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../../agent/src/Repository/SidebarState.php';
require __DIR__ . '/../../agent/src/Repository/RepositoryAuthorization.php';
require __DIR__ . '/../../agent/src/Promotion/Deploy.php';
require __DIR__ . '/../../cli/src/Plan/PlanSummary.php';
// The shared certification fixture (DUO-3379's re-seal, extracted by DUO-3421
// so sandbox/tests/regress_duo_init.sh can mount the identical library into a
// live pair). certified_library() below is this suite's scratch-root wrapper.
require __DIR__ . '/certification_fixture.php';

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

require __DIR__ . '/../../agent/src/Command/Cli.php';

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

/** The refusal message itself, for a check that compares two refusals' wording. */
function message_of(callable $fn): string {
    try {
        $fn();
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
    return '<no refusal thrown>';
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
    duo_cert_copy_tree($from, $to);
}

/**
 * The re-seal hash basis of a certification bundle — see
 * certification_fixture.php's duo_cert_bundle_digest(), which the bundle
 * builder, the importer, and both re-sealing fixtures now share. The first
 * check of the fixture group below pins that agreement by reproducing the
 * SHIPPED bundle's own recorded digest through it.
 */
function bundle_digest(array $bundle): string {
    return duo_cert_bundle_digest($bundle);
}

/**
 * The REAL shipped manifest library — every manifest, disposition,
 * interpreter, provider, and regenerator byte for byte — under a scratch
 * directory whose certification evidence has been RE-SEALED against the
 * working tree this suite is running on. Returns the manifest directory.
 *
 * Why this exists (DUO-3379). The checked-in evidence attestation binds the
 * exact bytes of every certification-bound repository input, so a branch that
 * legitimately edits one — engine source, the Makefile, a shipped manifest —
 * carries EXPIRED evidence until that subject is certified and imported, and
 * the regenerated registry reads `candidate` until then. The
 * runtime then attaches the evidence_not_current blocker to every claim,
 * certified ones included. Assertions here about a certified shipped adapter
 * would therefore pass or fail on where in the certification cycle the branch
 * happens to sit rather than on the overlay behavior under test, which is the
 * coupling this fixture removes.
 *
 * It removes the coupling without inventing a synthetic library, because a
 * synthetic one cannot demonstrate the claim at all (see the header): the
 * reviewed facts stay the real generated ones — dispositions, statuses,
 * adapter digests, operations, surfaces, profiles — and ONLY the attestation
 * is re-derived, exactly as re-certifying this tree would derive it. The
 * fixture group below proves both halves of that: that the re-seal really is
 * current for these bytes, and that expired, stale, or malformed evidence
 * still refuses to certify anything.
 *
 * The re-seal itself moved to sandbox/tests/certification_fixture.php in
 * DUO-3421, unchanged, because the live init suite needs the identical library
 * mounted into a Docker pair and a second implementation of the bundle-identity
 * basis is a third notion of bundle identity waiting to drift. This function
 * keeps the scratch-root ownership (and therefore this suite's shutdown
 * cleanup) and the memoization; the fixture group below is still where the
 * re-seal's currency is PROVED, for both callers.
 */
function certified_library(): string {
    static $manifestDir = null;
    if ($manifestDir !== null) {
        return $manifestDir;
    }
    return $manifestDir = duo_cert_seal_library(dirname(__DIR__, 2), scratch('certified-library'));
}

/** A throwaway copy of the certification fixture, mutated by $mutate. */
function library_variant(callable $mutate): string {
    $fixture = certified_library();
    $root = scratch('library-variant');
    copy_tree($fixture, "$root/manifests");
    copy_tree(dirname($fixture) . '/docs', "$root/docs");
    $mutate("$root/manifests");
    return "$root/manifests";
}

/** Rewrite one JSON file of a variant library through $edit, via Canon. */
function edit_json(string $file, callable $edit): void {
    Canon::write_file($file, Canon::encode($edit(Canon::decode(Canon::read_file($file)))));
}

$realManifests = dirname(__DIR__, 2) . '/manifests';
$shippedDir = certified_library();
// Every group below resolves the shipped library through this, including the
// ones that install their own directory and restore it afterwards.
putenv("DUO_MANIFESTS_DIR=$shippedDir");

// ======================================================================
echo "\n== the certification fixture is the shipped library, re-sealed against this tree ==\n";
// ======================================================================
$fixtureEvidence = Canon::decode(Canon::read_file("$shippedDir/capabilities/evidence.json"));
$shippedBundle = $fixtureEvidence['records']['manifests.acf']['bundle'] ?? null;
check(
    is_array($shippedBundle)
        && hash_equals((string) $shippedBundle['bundle_digest'], bundle_digest($shippedBundle)),
    "the harness re-seals subject records on the production hash basis — recomputing one record's identity reproduces its digest"
);
// Everything the library is, except the capabilities/ attestation the fixture
// exists to re-seal: manifests, the reviewed dispositions, and every file the
// adapter digest reaches for.
$libraryBytes = function (string $dir): array {
    $out = [];
    foreach (glob("$dir/*.json") ?: [] as $file) {
        $out[basename($file)] = hash_file('sha256', $file);
    }
    foreach (['interpreters', 'providers', 'regenerators'] as $sub) {
        foreach (glob("$dir/$sub/*") ?: [] as $file) {
            $out["$sub/" . basename($file)] = hash_file('sha256', $file);
        }
    }
    ksort($out, SORT_STRING);
    return $out;
};
$fixtureBytes = $libraryBytes($shippedDir);
check(
    $fixtureBytes === $libraryBytes($realManifests) && $fixtureBytes !== [],
    'every manifest, disposition, interpreter, provider, and regenerator under test is the shipped file byte for byte ('
    . count($fixtureBytes) . ' files)'
);
$reviewedColumns = fn(array $registry): array => array_map(
    fn(array $claim): array => [
        'status' => $claim['status'],
        'adapter_digest' => $claim['adapter_digest'],
        'operations' => $claim['operations'],
        'surfaces' => $claim['surfaces'],
        'unsupported' => $claim['unsupported'],
    ],
    $registry['manifests']
);
$fixtureRegistry = Canon::decode(Canon::read_file("$shippedDir/capabilities/registry.json"));
$realRegistry = Canon::decode(Canon::read_file("$realManifests/capabilities/registry.json"));
check(
    $reviewedColumns($fixtureRegistry) === $reviewedColumns($realRegistry)
    && $fixtureRegistry['generated_from']['dispositions_sha256']
        === $realRegistry['generated_from']['dispositions_sha256'],
    'the re-seal touches only the attestation: every reviewed status, adapter digest, operation, surface, and unsupported boundary is the shipped generated one'
);
// The whole point of re-sealing rather than flipping a flag: run the release
// gate's own expiry predicate over the fixture attestation and require it to
// find nothing. If a future edit reduced this fixture to "declare it current",
// this check is what fails.
$fixtureRecords = Canon::decode(Canon::read_file("$shippedDir/capabilities/evidence.json"))['records'] ?? [];
$fixtureBound = [];
foreach ($fixtureRecords as $record) {
    foreach (($record['bundle']['closure']['inputs'] ?? []) as $input) {
        $fixtureBound[(string) ($input['path'] ?? '')] = $input;
    }
}
$expiredInputs = [];
foreach ($fixtureBound as $input) {
    $file = dirname(__DIR__, 2) . '/' . (string) $input['path'];
    if (!is_file($file)
        || !hash_equals((string) $input['sha256'], (string) hash_file('sha256', $file))
        || (int) $input['size'] !== filesize($file)) {
        $expiredInputs[] = (string) $input['path'];
    }
}
check(
    $expiredInputs === [] && count($fixtureBound) > 1,
    'the fixture subject records bind ' . count($fixtureBound)
    . ' unique repository inputs and every one matches this working tree byte for byte'
    . ($expiredInputs === [] ? '' : ' (expired: ' . implode(', ', $expiredInputs) . ')')
);

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
putenv("DUO_MANIFESTS_DIR=$shippedDir");

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
check(
    ($overlayReport['evidence_scope'] ?? null) === 'per_subject'
    && $overlayReport['evidence'] === null
    && $overlayReport['platform'] === null
    && ($overlayReport['query']['revision'] ?? null) === null,
    'a mixed-source report exposes no misleading aggregate evidence/platform/revision authority'
);
check(
    ($coreRow['evidence_scope'] ?? null) === 'subject_record'
    && ($siteRow['evidence_scope'] ?? null) === 'none'
    && is_array($coreRow['evidence'] ?? null)
    && ($siteRow['evidence'] ?? null) === [],
    'mixed-source rows identify their own evidence authority; an unsigned site row inherits no shipped evidence'
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
check(
    str_contains($capabilityText, 'evidence: per subject (see each manifest/profile)')
    && !str_contains($capabilityText, "\nevidence bundle:"),
    'mixed-source text output points to per-adapter evidence instead of printing the shipped bundle as a global footer'
);

// ======================================================================
echo "\n== the certified verdict above is earned: expired, stale, or malformed evidence still refuses ==\n";
// ======================================================================
// The counterweight to the fixture. Everything above reads a library whose
// certification evidence the harness re-sealed, so this group takes that same
// library and breaks its evidence in each way it can genuinely break —
// proving the fixture removed a coupling, not a gate. Each variant is a
// throwaway copy; the shipped library is never touched.

$expiredLibrary = library_variant(function (string $dir): void {
    edit_json("$dir/capabilities/registry.json", function (array $registry): array {
        foreach (['manifests', 'profiles'] as $section) {
            foreach ($registry[$section] as $name => $claim) {
                $claim['evidence']['bundle_digest'] = null;
                $claim['evidence']['closure_digest'] = null;
                $claim['evidence']['git_revision'] = null;
                $claim['evidence']['status'] = 'candidate';
                $claim['evidence']['subject_digest'] = null;
                $registry[$section][$name] = $claim;
            }
        }
        return $registry;
    });
});
putenv("DUO_MANIFESTS_DIR=$expiredLibrary");
$expiredPolicy = Policy::load(fresh_site(['core', 'acme-widget'], ['acme-widget' => site_adapter('acme-widget')]));
$expiredReport = $expiredPolicy->capability_report(['operation' => 'promote']);
$expiredCore = null;
foreach ($expiredReport['manifests'] as $row) {
    if ($row['name'] === 'core') {
        $expiredCore = $row;
    }
}
check(
    is_array($expiredCore) && ($expiredCore['verdict']['status'] ?? null) === 'blocked'
    && in_array('evidence_not_current', array_column($expiredCore['verdict']['reasons'] ?? [], 'code'), true),
    'with expired certification evidence the shipped core adapter is NOT certified — the same reviewed disposition, the same digest, and a blocked verdict'
);
check(
    $expiredReport['ready'] === false
    && array_filter($expiredPolicy->adapter_readiness_blockers(), fn(array $r) => $r['name'] === 'core') !== [],
    'expired evidence makes the shipped library itself a readiness blocker, however certified its dispositions read'
);
putenv("DUO_MANIFESTS_DIR=$shippedDir");

putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    file_put_contents("$dir/capabilities/evidence.json", "\n", FILE_APPEND);
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    'stale against its certification evidence attestation',
    'a registry generated against a different attestation than the one on disk is refused — a re-sealed attestation cannot be dropped beside an unregenerated registry'
);
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    file_put_contents("$dir/dispositions.json", "\n", FILE_APPEND);
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    'stale against dispositions.json',
    'a registry generated against different reviewed dispositions than the ones on disk is refused'
);
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    unlink("$dir/capabilities/registry.json");
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    'no generated capability registry',
    'a reviewed library with no generated registry at all refuses rather than falling back to an uncertified reading'
);
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    edit_json("$dir/capabilities/registry.json", function (array $registry): array {
        $registry['manifests']['core']['evidence']['status'] = 'ratified';
        return $registry;
    });
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    "evidence binding for 'core' is malformed",
    'a subject evidence status outside current/candidate is refused, not read as a third kind of currency'
);
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    edit_json("$dir/core.json", function (array $manifest): array {
        $manifest['options']['duo_regress_bound_input_marker'] = ['class' => 'authored'];
        return $manifest;
    });
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    "adapter digest for 'core' is stale",
    'editing a certification-bound shipped manifest refuses until the registry is regenerated — the harness re-seals the attestation, never a reviewed claim'
);
putenv("DUO_MANIFESTS_DIR=$shippedDir");

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
// DUO-3314 checked the cross-source collision on DECLARED names rather than
// file names because the shipped side was not yet held to the rule the site
// side was: a shipped file could declare a name that was not its own, and the
// site source could then reach that name from a differently-named file. That
// check still has this job — the scan reads the whole library whether or not a
// pin names the offending file, and an unpinned shipped manifest is refused by
// nothing else. A manifest directory without dispositions is the cheapest way
// to install such a shipped manifest.
$oddShipped = scratch('odd-shipped');
Canon::write_file("$oddShipped/renamed-file.json", Canon::encode(site_adapter('acme-widget')));
putenv("DUO_MANIFESTS_DIR=$oddShipped");
expect_throw(
    fn() => Policy::load(fresh_site(['renamed-file'], ['acme-widget' => site_adapter('acme-widget')])),
    'already declared by the shipped manifest',
    'a site adapter is refused when a shipped manifest DECLARES that name under a different file name — two adapters cannot answer to one name'
);
// DUO-3387: #167 collapsed the DUO-3153 two-branch (site-vs-site / site-vs-
// shipped) collision into this single site-vs-shipped check, so "shipped" is
// now structurally always correct rather than a fallible hardcoded word. What
// still needs pinning is that the refusal names the ACTUAL colliding file,
// looked up from the shipped declared-name index ($shippedNames[$name]),
// NOT a hardcoded or wrong filename — a regression that dropped the lookup or
// named the site file would keep the static phrase above but move this needle.
$collisionMsg = message_of(fn() => Policy::load(
    fresh_site(['renamed-file'], ['acme-widget' => site_adapter('acme-widget')])
));
check(
    str_contains($collisionMsg, "already declared by the shipped manifest 'renamed-file'")
        && str_contains($collisionMsg, "claims the name 'acme-widget'"),
    "the cross-source collision refusal names the SPECIFIC shipped file it collides with, "
    . "derived from the shipped declared-name index, not a hardcoded origin ($collisionMsg)"
);
putenv("DUO_MANIFESTS_DIR=$shippedDir");
// The pins above never name the offending adapter: discovery scans whole
// sources, so a broken installation surfaces on the next command rather than
// on the first command that happens to pin it.
check(true, '(each refusal above fired while the offending adapter was NOT pinned)');

// ======================================================================
echo "\n== T6 §3.3: an explicit site pin OVERRIDES a shipped adapter ==\n";
// ======================================================================
// The refusals above are what an operator hits when they say nothing. This is
// what they get when they say it: the same colliding file, plus a pin that
// names the source, selects the site copy — and the shipped copy is reported
// rather than silently losing.
$overrideRepo = fresh_site(
    [['name' => 'core'], ['name' => 'woocommerce', 'source' => 'site']],
    ['woocommerce' => site_adapter('woocommerce')]
);
$overridePolicy = Policy::load($overrideRepo);
$overrideSources = $overridePolicy->adapter_sources();
check(
    $overrideSources->source('woocommerce') === AdapterSources::SITE
        && $overrideSources->path('woocommerce') === 'adapters/woocommerce.json',
    'the explicit {name, source:"site"} pin selects the site copy for a SHIPPED name — got '
    . $overrideSources->source('woocommerce') . ' at ' . var_export($overrideSources->path('woocommerce'), true)
);
$overrideNames = array_map(
    static fn(array $m): string => (string) ($m['name'] ?? ''),
    $overridePolicy->manifests
);
check(
    count(array_keys($overrideNames, 'woocommerce', true)) === 1,
    'exactly ONE definition answers to the overridden name in the loaded set, so CrossManifestGuards see no '
    . 'manufactured conflict between the shipped and site copies'
);
check(
    ($overrideSources->diagnostics($overridePolicy->manifests)['woocommerce']['certification'] ?? null)
        === 'uncertified',
    'the site copy carries the SITE\'s own certification words — an override never inherits the shipped '
    . "adapter's reviewed registry claim"
);
$overrideSurvey = AdapterSources::survey($overrideRepo);
$shadowRow = null;
foreach ($overrideSurvey['not_installed'] as $row) {
    if (($row['name'] ?? null) === 'woocommerce') {
        $shadowRow = $row;
    }
}
check(
    is_array($shadowRow)
        && $shadowRow['reason_code'] === AdapterSources::CERTIFICATION_SHADOWED_BY_SITE
        && $shadowRow['source'] === AdapterSources::SHIPPED
        && ($shadowRow['winner']['source'] ?? null) === AdapterSources::SITE
        && ($shadowRow['winner']['path'] ?? null) === 'adapters/woocommerce.json',
    'the displaced shipped copy is reported shadowed_by_site, naming the site copy that won — never silently '
    . 'absent (' . var_export($shadowRow['reason_code'] ?? null, true) . ')'
);
$overrideCatalogNames = array_column($overrideSurvey['adapters'], 'source', 'name');
check(
    ($overrideCatalogNames['woocommerce'] ?? null) === AdapterSources::SITE,
    'the catalog lists the site copy once, under the overridden name'
);
check(
    $overrideSurvey['refusals'] === [],
    'an explicitly pinned override raises NO refusal — got '
    . implode(',', array_column($overrideSurvey['refusals'], 'code'))
);
// The three ways an override must NOT be available, each a separate fail-safe.
expect_throw(
    fn() => Policy::load(fresh_site(
        [['name' => 'core'], 'woocommerce'],
        ['woocommerce' => site_adapter('woocommerce')]
    )),
    'shadows the shipped adapter',
    'a NAME-ONLY pin is not an override: precedence stays shipped > site > plugin and the refusal stands'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        [['name' => 'core'], ['name' => 'woocommerce', 'source' => 'shipped']],
        ['woocommerce' => site_adapter('woocommerce')]
    )),
    'shadows the shipped adapter',
    'a pin that names the SHIPPED source for a shadowed name is not an override either'
);
$brokenPolicyRepo = fresh_site(
    [['name' => 'core'], ['name' => 'woocommerce', 'source' => 'site']],
    ['woocommerce' => site_adapter('woocommerce')]
);
file_put_contents($brokenPolicyRepo . '/site.duo.json', "{ not json");
expect_throw(
    fn() => Policy::load($brokenPolicyRepo),
    'invalid JSON',
    'an unreadable site.duo.json yields NO overrides — the override reader fails closed, so a broken policy '
    . 'file can never silently swap which definition is in force'
);

// DUO-3371: PINNING that same shipped manifest is now itself a refusal. Until
// this issue the shipped side kept the freedom DUO-3314 removed from the site
// side, so one adapter answered to its file name in the disposition registry's
// coverage and to its declared name in every digest, disposition lookup, and
// capability claim. Both sources now speak one sentence, so the pair of checks
// below pins that it really is one sentence and not two that happen to rhyme.
putenv("DUO_MANIFESTS_DIR=$oddShipped");
$shippedIdentity = message_of(fn() => Policy::load(fresh_site(['renamed-file'])));
check(
    str_contains($shippedIdentity, "duo: shipped adapter '$oddShipped/renamed-file.json' declares name"),
    "a shipped manifest whose declared name disagrees with its file name is refused at load, naming the file ($shippedIdentity)"
);
// The site half of the pair needs a library that does NOT hold `renamed-file`,
// or the site file shadows the shipped one and refuses for that reason first.
putenv("DUO_MANIFESTS_DIR=$shippedDir");
$siteIdentity = message_of(fn() => Policy::load(
    fresh_site(['core'], ['renamed-file' => site_adapter('acme-widget')])
));
$tail = "declares name 'acme-widget' but its file name is 'renamed-file' — a pin names the file while every "
    . 'downstream identity (dispositions, digests, diagnostics) keys off the declared name, so the two '
    . 'disagreeing is ambiguous identity. Make the declared name match the file name';
check(
    str_ends_with($shippedIdentity, $tail) && str_ends_with($siteIdentity, $tail),
    'the shipped and site refusals are one sentence differing only in which source installed the file'
);
// The rule is agreement, not a ban on the file name: the same library with the
// file named as it declares itself loads.
$evenShipped = scratch('even-shipped');
Canon::write_file("$evenShipped/acme-widget.json", Canon::encode(site_adapter('acme-widget')));
putenv("DUO_MANIFESTS_DIR=$evenShipped");
check(
    (Policy::load(fresh_site(['acme-widget']))->manifests[0]['name'] ?? null) === 'acme-widget',
    'the same shipped manifest, named as it declares itself, loads unchanged'
);
putenv("DUO_MANIFESTS_DIR=$shippedDir");

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
// A regular FILE by one of those names is the same misunderstanding as a
// directory; an is_dir()-keyed guard would wave it through into the exact
// "silently ignored" bucket the guard exists to close.
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/providers' => '<?php // inert']
    )),
    'which the engine never loads',
    'a regular FILE named providers is refused too, not only a providers/ directory'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/vendor/nested.json' => site_adapter('nested')]
    )),
    'nested adapter',
    'a *.json nested under a subdirectory is refused — discovery is top-level only, so it would never be loaded'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/Legacy.JSON' => site_adapter('Legacy')]
    )),
    "extension is not exactly '.json'",
    'an extension near-miss (.JSON) is refused — it would load on a case-insensitive filesystem and vanish on a case-sensitive one'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['.hidden' => site_adapter('.hidden')]
    )),
    'canonical lowercase ASCII slugs',
    'a hidden top-level *.json adapter is refused rather than silently skipped by glob discovery'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/certifications/.acme-widget.json' => new \stdClass()]
    )),
    'canonical lowercase ASCII slugs',
    'a hidden authority-bearing certificate is refused rather than silently skipped by glob discovery'
);
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/certifications/acme-widget.JSON' => new \stdClass()]
    )),
    'every entry must be an exact lowercase',
    'a certificate extension near-miss is refused before authority verification'
);

// A symlink sources bytes from OUTSIDE the repository while every downstream
// record still reads `adapters/<name>.json`, which would make the repo-relative
// provenance a lie and break the checkout-independence the digest rests on.
$linkRepo = fresh_site(['core'], ['real-widget' => site_adapter('real-widget')]);
$outside = scratch('outside');
Canon::write_file("$outside/escape.json", Canon::encode(site_adapter('escape')));
symlink("$outside/escape.json", "$linkRepo/adapters/escape.json");
expect_throw(
    fn() => Policy::load($linkRepo),
    'symbolic link',
    'a symlinked site adapter is refused rather than silently sourced from outside the repository'
);
unlink("$linkRepo/adapters/escape.json");
symlink($outside, "$linkRepo/adapters/linked-dir");
expect_throw(
    fn() => Policy::load($linkRepo),
    'symbolic link',
    'a symlinked DIRECTORY inside the adapter source is refused too, and is never followed'
);
unlink("$linkRepo/adapters/linked-dir");
// A symlinked *.json one level down is judged by name and never followed, so
// it is refused as a nested adapter instead of being silently ignored —
// neither refused nor loaded is the one outcome this scan exists to prevent.
mkdir("$linkRepo/adapters/docs");
symlink("$outside/escape.json", "$linkRepo/adapters/docs/nested.json");
expect_throw(
    fn() => Policy::load($linkRepo),
    'nested adapter',
    'a SYMLINKED *.json nested one level down is refused as a nested adapter, not silently skipped as a link'
);
rm_rf("$linkRepo/adapters/docs");
check(
    count(Policy::load($linkRepo)->manifests) === 1,
    'removing the links leaves the real out-of-tree adapter loading normally'
);

// The blocker case the two entry-level tests above do NOT reach: `adapters`
// ITSELF checked in as a symlink. is_dir() follows it, so without resolving
// the directory every adapter would come from outside the repository while
// each provenance record still read `adapters/<name>.json`.
$linkedSourceRepo = fresh_site(['core']);
$externalSource = scratch('external-source');
Canon::write_file("$externalSource/smuggled.json", Canon::encode(site_adapter('smuggled')));
symlink($externalSource, "$linkedSourceRepo/adapters");
expect_throw(
    fn() => Policy::load($linkedSourceRepo),
    'must be a real directory inside the site repository',
    'the adapters DIRECTORY itself checked in as a symlink is refused — the source is resolved before anything inside it is trusted'
);
unlink("$linkedSourceRepo/adapters");

// Case and normalization variants never enter identity comparison: a strict
// lowercase ASCII file-name grammar makes the accepted byte set identical on
// case-folding and Unicode-normalizing filesystems.
expect_throw(
    fn() => Policy::load(fresh_site(['core'], ['CORE' => site_adapter('CORE')])),
    'canonical lowercase ASCII slugs',
    'an uppercase site adapter name is refused before filesystem case folding can change its identity'
);
$unicodeName = "caf\u{e9}";
expect_throw(
    fn() => Policy::load(fresh_site(['core'], [$unicodeName => site_adapter($unicodeName)])),
    'canonical lowercase ASCII slugs',
    'a Unicode site adapter identity is refused rather than depending on filesystem normalization'
);
expect_throw(
    fn() => Policy::load(fresh_site(['core'], ['123' => site_adapter('123')])),
    'numeric-only identities',
    'a numeric-only site adapter name is refused before PHP can coerce its source-map key'
);
$nfd = "caf\u{65}\u{301}"; // 'cafe' + combining acute — renders as 'café'
expect_throw(
    fn() => Policy::load(fresh_site(['core'], ['cafe-widget' => site_adapter($nfd)])),
    'hex ',
    'a name mismatch that renders identically (NFC vs NFD) is reported with hex bytes, so it is actionable'
);

// ======================================================================
echo "\n== a data-only manifest acquires no executable privileges ==\n";
// ======================================================================
foreach ([
    'adapter_certificate', 'authority', 'authority_id', 'certificate', 'certification',
    'certification_authority', 'disposition', 'evidence', 'key_id', 'public_key', 'signature', 'trust_tier',
] as $reservedAuthorityField) {
    expect_throw(
        fn() => Policy::load(fresh_site(
            ['acme-widget'],
            ['acme-widget' => site_adapter('acme-widget', [$reservedAuthorityField => 'self-asserted'])]
        )),
        "reserved authority field '$reservedAuthorityField'",
        "an unsigned site manifest cannot carry inert/self-asserted authority field '$reservedAuthorityField'"
    );
}
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
    'the installed adapter sources are "shipped", "site", and "plugin"',
    'an unknown pin source is refused and ALL THREE real sources are named (DUO-3339/B2 added the third; a pin '
    . 'vocabulary that lagged the scan would refuse a source the engine actually installs from)'
);
// The pin source vocabulary is a PHP literal inside
// PinResolver::normalize_manifest_pins(), never published data — which is why
// DUO-3339/B2 added a third source word without moving one shipped manifest
// byte or one release-gate byte comparison. Asserted rather than assumed,
// because the day it IS published, adding a source becomes a shipped-artifact
// change and this suite is where that has to be noticed.
// Checked on the ADAPTER-source-specific word. `provider_sources` legitimately
// publishes "plugin", and it is a different vocabulary about a different
// decision (which tree a PROVIDER's code loads from); "shipped" is the word
// only the adapter-source list has, so it is the one that proves the list is
// not published.
$publishedVocabularies = json_encode(Policy::closed_vocabularies(), JSON_UNESCAPED_SLASHES);
check(
    !str_contains((string) $publishedVocabularies, '"' . AdapterSources::SHIPPED . '"'),
    'the adapter SOURCE vocabulary is engine code rather than a published closed vocabulary, so growing it moves '
    . 'no shipped bytes'
);
expect_throw(
    fn() => Policy::load(fresh_site([['name' => 'core', 'registry' => 'internal']])),
    'unknown pin key',
    'an unknown pin key is refused rather than silently ignored'
);
foreach (['../core', 'foo/../core', 'foo\\core', '.', '..', '.core', 'core.', 'CORE', '123'] as $unsafePin) {
    expect_throw(
        fn() => Policy::load(fresh_site([$unsafePin])),
        'canonical lowercase ASCII slugs',
        "a non-canonical/path-like string pin '$unsafePin' is refused rather than rewritten with basename()"
    );
    expect_throw(
        fn() => Policy::load(fresh_site([['name' => $unsafePin, 'source' => 'shipped']])),
        'canonical lowercase ASCII slugs',
        "a non-canonical/path-like object pin '$unsafePin' is refused with the same identity rule"
    );
}
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

$numericFrozenPin = $snapshot;
$numericFrozenPin['site']['manifests'][1] = '123';
expect_throw(
    fn() => Policy::from_snapshot($numericFrozenPin),
    'numeric-only identities',
    'a frozen policy revalidates and rejects a numeric-only adapter pin before map lookup'
);
$numericFrozenRecord = $snapshot;
$numericRecord = $numericFrozenRecord['adapter_sources']['out_of_tree']['acme-widget'];
unset($numericFrozenRecord['adapter_sources']['out_of_tree']['acme-widget']);
$numericFrozenRecord['adapter_sources']['out_of_tree']['123'] = $numericRecord;
expect_throw(
    fn() => Policy::from_snapshot($numericFrozenRecord),
    'numeric-only identities',
    'a frozen adapter-source map rejects a numeric-only key instead of accepting PHP\'s coerced integer key'
);

$legacySnapshot = $snapshot;
$legacySnapshot['format'] = 'duo-policy-snapshot/v4';
$legacySnapshot['adapter_sources']['format'] = AdapterSources::LEGACY_FORMAT;
unset($legacySnapshot['adapter_sources']['certificates']);
$legacySnapshot['adapter_sources']['out_of_tree']['acme-widget']['provenance']['format'] =
    AdapterSources::LEGACY_FORMAT;
$legacyFrozen = Policy::from_snapshot($legacySnapshot);
check(
    $legacyFrozen->adapter_sources()->wire_format() === AdapterSources::LEGACY_FORMAT
    && $legacyFrozen->adapter_sources()->source('acme-widget') === 'site',
    'a legacy v1 unsigned adapter-source snapshot still reconstructs with its original wire generation'
);
$legacyCertificate = $legacySnapshot;
$legacyCertificate['adapter_sources']['certificates'] = [];
expect_throw(
    fn() => Policy::from_snapshot($legacyCertificate),
    'frozen adapter source record is malformed',
    'a legacy v1 adapter-source snapshot cannot smuggle even an empty certificate field'
);

$laundered = $snapshot;
unset($laundered['adapter_sources']['out_of_tree']['acme-widget']);
expect_throw(
    fn() => Policy::from_snapshot($laundered),
    'no shipped manifest exists',
    'dropping a v2 out-of-tree record cannot relabel a site adapter as shipped, even before registry validation'
);
$legacyLaundered = $legacySnapshot;
unset($legacyLaundered['adapter_sources']['out_of_tree']['acme-widget']);
expect_throw(
    fn() => Policy::from_snapshot($legacyLaundered),
    'no entry for manifest',
    'dropping a legacy v1 out-of-tree record from a registry-bound snapshot is still refused by its reviewed shipped coverage'
);
$legacyCustom = $legacyLaundered;
$legacyCustom['capabilities'] = null;
$legacyCustom['dispositions'] = null;
$legacyCustomPolicy = Policy::from_snapshot($legacyCustom);
check(
    $legacyCustomPolicy->adapter_sources()->source('acme-widget') === AdapterSources::SHIPPED,
    'legacy v1 custom snapshots retain their historical already-bound no-registry read compatibility; only new v2 exports require positive shipped bytes'
);
$shippedNameLaunder = $snapshot;
$shippedNameLaunder['site']['manifests'][1] = 'core';
$shippedNameLaunder['manifests'][1] = $shippedNameLaunder['manifests'][0];
$shippedNameLaunder['manifests'][1]['interpreter'] = 'acf';
unset($shippedNameLaunder['adapter_sources']['out_of_tree']['acme-widget']);
expect_throw(
    fn() => Policy::from_snapshot($shippedNameLaunder),
    'bytes do not match the trusted shipped manifest',
    'a forged frozen adapter using a real shipped name cannot acquire shipped executable authority unless every manifest byte matches the trusted library'
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

// The frozen path holds no file to reopen, so both meaningful fields are
// DERIVED here rather than pattern-matched. A "starts with adapters/" test
// would accept the traversal below.
$traversal = $snapshot;
$traversal['adapter_sources']['out_of_tree']['acme-widget']['provenance']['path'] =
    'adapters/../../../etc/passwd.json';
expect_throw(
    fn() => Policy::from_snapshot($traversal),
    'the only path this record can describe',
    'a frozen provenance path that escapes the adapter source is refused — the expected path is rebuilt from the record key, so traversal has nothing to express'
);
$renamedPath = $snapshot;
$renamedPath['adapter_sources']['out_of_tree']['acme-widget']['provenance']['path'] = 'adapters/other.json';
expect_throw(
    fn() => Policy::from_snapshot($renamedPath),
    'the only path this record can describe',
    'a frozen record pointing at a different file than its own name is refused'
);
$rehashed = $snapshot;
$rehashed['adapter_sources']['out_of_tree']['acme-widget']['provenance']['sha256'] = str_repeat('a', 64);
expect_throw(
    fn() => Policy::from_snapshot($rehashed),
    'does not describe its own manifest',
    'the recorded content hash is RECOMPUTED from the frozen manifest, so a record moved onto a different adapter is caught'
);
check(
    $snapshot['adapter_sources']['out_of_tree']['acme-widget']['provenance']['sha256']
        === hash('sha256', Canon::encode($overlay->manifests[1])),
    'the recorded provenance hash is the canonical manifest hash — recomputable on the frozen path, not an unverifiable breadcrumb'
);

// ======================================================================
echo "\n== a frozen PLUGIN-BUNDLED record re-derives its path from the manifest (DUO-3339/B2) ==\n";
// ======================================================================
// This process has no WP_PLUGIN_DIR and never will, which is exactly the
// point: a verification process reconstructs a bundled adapter WITHOUT
// reopening the plugin directory it came from. It can only do that because the
// anchor rule makes `plugin` mandatory, so `plugins/<dir>/duo-adapter.json` is
// a function of a claim the frozen manifest already carries — which is why
// B2 needed no new wire key and no snapshot format bump.
$bundledManifest = site_adapter('acme-widget', [
    'plugin' => 'acme/acme.php',
    'version_range' => ['min' => '1.0.0', 'max' => '9.0.0'],
]);
$bundledSnapshot = $snapshot;
$bundledSnapshot['manifests'][1] = $bundledManifest;
$bundledSnapshot['adapter_sources']['out_of_tree']['acme-widget'] = [
    'certification' => 'uncertified',
    'provenance' => [
        'format' => AdapterSources::FORMAT,
        'path' => 'plugins/acme/duo-adapter.json',
        'sha256' => hash('sha256', Canon::encode($bundledManifest)),
        'source' => AdapterSources::PLUGIN,
    ],
    'reason' => "adapter 'acme-widget' is bundled by the active plugin 'acme/acme.php' "
        . '(plugins/acme/duo-adapter.json) and carries no reviewed certification evidence; a bundled adapter '
        . 'cannot be certified in place — certification is a repository-scoped signed companion at '
        . 'adapters/certifications/acme-widget.json.',
    'status' => 'uncertified',
    'trust_tier' => AdapterSources::TIER_DECLARATIVE,
];
$bundledFrozen = Policy::from_snapshot($bundledSnapshot);
check(
    $bundledFrozen->adapter_sources()->source('acme-widget') === AdapterSources::PLUGIN
    && $bundledFrozen->adapter_sources()->path('acme-widget') === 'plugins/acme/duo-adapter.json'
    && $bundledFrozen->adapter_sources()->is_out_of_tree('acme-widget'),
    'a frozen plugin-bundled record reconstructs with its own source and path in a process with no plugin directory at all'
);
check(
    $bundledFrozen->adapter_sources()->sources() === []
    && $bundledFrozen->adapter_sources()->not_installed() === []
    && $bundledFrozen->adapter_sources()->plugin_refusals() === [],
    'and reports honestly that it scanned nothing — a reconstructed policy reopened no source, so it knows nothing about this machine'
);
$bundledTamper = $bundledSnapshot;
$bundledTamper['adapter_sources']['out_of_tree']['acme-widget']['provenance']['path'] =
    'plugins/other/duo-adapter.json';
expect_throw(
    fn() => Policy::from_snapshot($bundledTamper),
    'the only path this record can describe',
    'a frozen bundled record naming a different plugin directory than its own manifest claims is refused — the expected path is DERIVED from the manifest, so relabeling has nothing to express'
);
$bundledTraversal = $bundledSnapshot;
$bundledTraversal['manifests'][1]['plugin'] = '../../etc/x.php';
$bundledTraversal['adapter_sources']['out_of_tree']['acme-widget']['provenance']['sha256'] =
    hash('sha256', Canon::encode($bundledTraversal['manifests'][1]));
expect_throw(
    fn() => Policy::from_snapshot($bundledTraversal),
    'never one containing a ".." segment',
    'and the derivation runs through assert_plugin_basename(), so a traversing plugin claim cannot produce a path at all'
);
$bundledNoPlugin = $bundledSnapshot;
unset($bundledNoPlugin['manifests'][1]['plugin'], $bundledNoPlugin['manifests'][1]['version_range']);
$bundledNoPlugin['adapter_sources']['out_of_tree']['acme-widget']['provenance']['sha256'] =
    hash('sha256', Canon::encode($bundledNoPlugin['manifests'][1]));
expect_throw(
    fn() => Policy::from_snapshot($bundledNoPlugin),
    'plugin basename',
    'a frozen bundled record whose manifest declares no owning plugin has no derivable path and is refused'
);
$bundledSingleFile = $bundledSnapshot;
$bundledSingleFile['manifests'][1]['plugin'] = 'acme.php';
$bundledSingleFile['adapter_sources']['out_of_tree']['acme-widget']['provenance']['sha256'] =
    hash('sha256', Canon::encode($bundledSingleFile['manifests'][1]));
expect_throw(
    fn() => Policy::from_snapshot($bundledSingleFile),
    'no directory of its own to bundle an adapter in',
    'and a single-file plugin, which has no directory, cannot be the anchor of a frozen bundled record either'
);
$bundledRelabelledSite = $bundledSnapshot;
$bundledRelabelledSite['adapter_sources']['out_of_tree']['acme-widget']['provenance']['source'] =
    AdapterSources::SITE;
expect_throw(
    fn() => Policy::from_snapshot($bundledRelabelledSite),
    'the only path this record can describe',
    'flipping a frozen bundled record to the site source is refused: the two sources derive different paths, so the label and the path cannot both be believed'
);
$bundledShim = $bundledSnapshot;
$bundledShim['adapter_sources']['out_of_tree']['acme-widget']['trust_tier'] =
    AdapterSources::TIER_COMPATIBILITY_SHIM;
expect_throw(
    fn() => Policy::from_snapshot($bundledShim),
    'is malformed',
    'and the tier whitelist is the same one: a bundled record cannot claim a trust tier no out-of-tree adapter can hold'
);
// The privilege boundary is the SAME check for both out-of-tree sources, and
// only its noun changes. That noun is not cosmetic: it is the directory an
// operator is being sent to go fix, and "site adapter
// 'plugins/acme/duo-adapter.json'" names one that does not hold the file.
$bundledInterpreter = $bundledSnapshot;
$bundledInterpreter['manifests'][1]['interpreter'] = 'acf';
$bundledInterpreter['adapter_sources']['out_of_tree']['acme-widget']['provenance']['sha256'] =
    hash('sha256', Canon::encode($bundledInterpreter['manifests'][1]));
expect_throw(
    fn() => Policy::from_snapshot($bundledInterpreter),
    "duo: plugin adapter 'plugins/acme/duo-adapter.json' declares interpreter",
    'a frozen bundled record reaching for executable privilege is refused by the SAME contract the site source '
    . 'uses, and the message names the PLUGIN adapter and its bundled path rather than a site directory that does '
    . 'not hold the file'
);

$bundledLegacy = $bundledSnapshot;
$bundledLegacy['format'] = 'duo-policy-snapshot/v4';
$bundledLegacy['adapter_sources']['format'] = AdapterSources::LEGACY_FORMAT;
unset($bundledLegacy['adapter_sources']['certificates']);
$bundledLegacy['adapter_sources']['out_of_tree']['acme-widget']['provenance']['format'] =
    AdapterSources::LEGACY_FORMAT;
expect_throw(
    fn() => Policy::from_snapshot($bundledLegacy),
    'is malformed',
    'a legacy v1 snapshot cannot carry a bundled record at all — v1 predates the plugin source, so such a snapshot is one no version of this engine ever wrote'
);

// ======================================================================
echo "\n== site-controlled identifier fields cannot carry filesystem paths ==\n";
// ======================================================================
// `plugin`/`theme` became site-controlled the moment adapters could be
// installed out-of-tree, and three code paths concatenate them into
// filesystem paths. Held to a shape at load, for every source.
$shapeDir = scratch('shape');
putenv("DUO_MANIFESTS_DIR=$shapeDir");
$writeShipped = function (string $name, array $extra) use ($shapeDir): void {
    Canon::write_file("$shapeDir/$name.json", Canon::encode(site_adapter($name, $extra)));
};
$writeShipped('traversal', [
    'plugin' => '../../../etc/passwd',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
]);
expect_throw(
    fn() => Policy::load(null, ['traversal']),
    'never one containing a ".." segment',
    'a plugin identifier containing a traversal segment is refused at load, before any consumer concatenates it into a path'
);
$writeShipped('absolute', [
    'plugin' => '/etc/passwd',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
]);
expect_throw(
    fn() => Policy::load(null, ['absolute']),
    'never an absolute path',
    'an absolute plugin identifier is refused'
);
$writeShipped('deep', [
    'plugin' => 'a/b/c.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
]);
expect_throw(
    fn() => Policy::load(null, ['deep']),
    "'<directory>/<file>.php'",
    'a plugin identifier deeper than <directory>/<file>.php is refused'
);
$writeShipped('themepath', [
    'theme' => 'themes/../evil',
    'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
]);
expect_throw(
    fn() => Policy::load(null, ['themepath']),
    'a bare directory slug',
    'a theme identifier is a bare slug — a path is refused'
);
$writeShipped('plain', [
    'plugin' => 'acme/acme.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
]);
$writeShipped('bare', [
    'plugin' => 'hello.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
]);
check(
    count(Policy::load(null, ['plain'])->manifests) === 1
    && count(Policy::load(null, ['bare'])->manifests) === 1,
    'the two real-world plugin shapes (<dir>/<file>.php and a bare <file>.php) still load'
);

// A non-string interpreter used to slip past every is_string()-keyed guard and
// fail late inside preg_match(). It is a load-time refusal now, for every
// source — the shipped library reaches this validator on the same path.
foreach ([['int', 7], ['bool', true], ['list', ['acf']], ['empty', '']] as [$label, $value]) {
    Canon::write_file(
        "$shapeDir/interp-$label.json",
        Canon::encode(site_adapter("interp-$label", ['interpreter' => $value]))
    );
    expect_throw(
        fn() => Policy::load(null, ["interp-$label"]),
        'an interpreter name must be a non-empty string',
        "a non-string interpreter ($label) is refused at manifest validation, for every adapter source"
    );
}
putenv("DUO_MANIFESTS_DIR=$shippedDir");
// The out-of-tree privilege refusal is keyed on PRESENCE, so a malformed
// declaration cannot dodge it by being unrecognizable.
expect_throw(
    fn() => Policy::load(fresh_site(
        ['acme-widget'],
        ['acme-widget' => site_adapter('acme-widget', ['interpreter' => ['acf']])]
    )),
    'acquires no executable privileges',
    'an out-of-tree adapter declaring a MALFORMED interpreter is still refused as a privilege request, not merely as bad data'
);
check(
    AdapterSources::trust_tier(['name' => 'x', 'interpreter' => 7]) === 'compatibility_shim',
    'a malformed interpreter still reports the compatibility_shim tier — it can never report a lower tier than the privilege it reaches for'
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
$plainReport = $plain->capability_report(['operation' => 'promote']);
$plainRows = [];
foreach ($plainReport['manifests'] ?? [] as $row) {
    if (is_array($row) && is_string($row['name'] ?? null)) {
        $plainRows[$row['name']] = $row;
    }
}
check(
    ($plainReport['evidence_scope'] ?? null) === 'per_subject'
    && ($plainReport['evidence'] ?? null) === null
    && is_array($plainReport['platform'] ?? null)
    && ($plainRows['core']['evidence_scope'] ?? null) === 'subject_record'
    && ($plainRows['woocommerce']['evidence_scope'] ?? null) === 'subject_record'
    && (($plainRows['woocommerce']['evidence']['status'] ?? null) === 'current'),
    'a shipped-only report exposes each claim authority when its Woo evidence is scoped'
);

// ======================================================================
echo "\n== the shipped library view reports its own tiers, from the same scan (DUO-3339) ==\n";
// ======================================================================
// `wp duo capabilities --all` used to hand report() NO $sources, so every row
// fell through to the absent-sources default. The default derived the same
// tier — but by a second path, and it could not name the file a row came from
// at all. Two code paths agreeing today is not one code path.
WP_CLI::$lines = [];
(new \Duo\Cli())->capabilities([], ['all' => true]);
$allText = implode("\n", WP_CLI::$lines);
check(
    str_contains($allText, 'CAPABILITY acf ')
    && preg_match('/CAPABILITY acf [A-Z]+\n  source: shipped \(' . preg_quote($shippedDir, '/') . '\/acf\.json\)\n'
        . '  trust_tier: compatibility_shim\n/', $allText) === 1,
    'the whole-library view names each row\'s own file and its derived tier — a shipped compatibility shim SAYS '
    . 'compatibility_shim, where the doctrine requires an executable shim to be named in capability diagnostics'
);
check(
    substr_count($allText, '  trust_tier: ') === substr_count($allText, 'CAPABILITY '),
    'and every row carries a trust tier, not just the shim'
);
$allTiers = [];
if (preg_match_all('/  trust_tier: ([a-z_]+)\n/', $allText, $tierMatches) > 0) {
    $allTiers = array_values(array_unique($tierMatches[1]));
    sort($allTiers, SORT_STRING);
}
check(
    $allTiers === ['compatibility_shim', 'declarative_manifest', 'plugin_provider'],
    'the shipped library really does span three tiers, so "every row says shipped/declarative" would be a visibly '
    . 'wrong answer here (found: ' . implode(', ', $allTiers) . ')'
);

// ======================================================================
echo "\n== the registry-absent blocker row carries what its renderers print (DUO-3339) ==\n";
// ======================================================================
// This branch of adapter_readiness_blockers() is a FAIL-CLOSED BACKSTOP with
// no reachable product caller: both Policy::load() and Policy::from_snapshot()
// refuse a library that has dispositions and no generated registry, so the row
// can only be produced by constructing that state directly. It is asserted all
// the same, because the two blocker renderers print `source` and `trust_tier`
// for every row and used to INVENT them for this one from a `??` default.
$backstop = new \ReflectionClass(Policy::class);
$backstopPolicy = $backstop->newInstanceWithoutConstructor();
$dispositionsProperty = $backstop->getProperty('manifestDispositions');
$dispositionsProperty->setValue($backstopPolicy, \Duo\ManifestDispositions::load($shippedDir));
$backstopRows = $backstopPolicy->adapter_readiness_blockers();
check(
    count($backstopRows) === 1 && $backstopRows[0]['code'] === 'missing_capability_registry',
    'dispositions with no generated registry produce the one backstop blocker row'
);
check(
    ($backstopRows[0]['source'] ?? null) === 'shipped'
    && ($backstopRows[0]['trust_tier'] ?? null) === 'unknown'
    && trim((string) ($backstopRows[0]['remediation'] ?? '')) !== '',
    'and it now STATES its source, states that it has no trust tier to report, and carries a remediation — the '
    . 'renderers no longer fill those in for it'
);
$backstopStatus = \Duo\Orchestrator\PlanSummary::render(['adapter_dispositions' => $backstopRows]);
$backstopText = implode("\n", $backstopStatus['lines']);
check(
    str_contains($backstopText, 'source=shipped') && str_contains($backstopText, 'tier=unknown')
    && str_contains($backstopText, '    remediation: '),
    'duo status renders the row\'s own words rather than its own defaults'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\nFAIL: $failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
