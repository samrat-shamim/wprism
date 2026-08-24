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

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Repository/SidebarState.php';
require __DIR__ . '/../../../../agent/src/Repository/RepositoryAuthorization.php';
require __DIR__ . '/../../../../agent/src/Promotion/Deploy.php';
require __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';
// The shared hermetic-library fixture (extracted by DUO-3421 so
// sandbox/tests/live/regress_duo_init.sh can mount the identical library into a
// live pair). shipped_library() below is this suite's scratch-root wrapper.
require __DIR__ . '/certification_fixture.php';

use Duo\AdapterSources;
use Duo\Canon;
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

require __DIR__ . '/../../../../agent/src/Command/Cli.php';

// The real shipped registry binds these exact platform values; the harness
// must present the same agent it claims to be or every claim reads as stale.
require_once __DIR__ . '/../../lib/agent_version.php';
duo_test_define_agent_versions();

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
 * The REAL shipped manifest library — every manifest, disposition,
 * interpreter, provider, regenerator, the platform boundary and the shipped
 * trust root, byte for byte — under a scratch directory this suite owns.
 * Returns the manifest directory.
 *
 * Why a copy at all, now that there is nothing to re-derive. DUO-3379 built
 * this to re-seal certification evidence, because the checked-in attestation
 * bound the exact bytes of every certification-bound repository input and was
 * therefore EXPIRED on any branch that edited one — which put
 * `evidence_not_current` on every certified claim and made this suite's
 * verdict depend on where the branch sat in the certification cycle rather
 * than on the overlay behaviour under test. That apparatus is gone: the
 * reviewed dispositions are the whole authored claim source and no branch
 * state can expire them.
 *
 * What survives is the other half of the reason: the variant groups below
 * MUTATE a manifest library — deleting its dispositions, breaking its platform
 * boundary — and the shipped one is not theirs to break. So this is a hermetic
 * copy, asserted byte-identical and loadable by the shared fixture before any
 * group reads it, and every mutation happens in a throwaway copy of it. The
 * copy lives in sandbox/tests/offline/adapter/certification_fixture.php because the live init
 * suite mounts the identical library into a Docker pair; this function keeps
 * the scratch-root ownership (and therefore this suite's shutdown cleanup) and
 * the memoization.
 */
function shipped_library(): string {
    static $manifestDir = null;
    if ($manifestDir !== null) {
        return $manifestDir;
    }
    return $manifestDir = duo_cert_hermetic_library(dirname(__DIR__, 4), scratch('shipped-library'));
}

/** A throwaway copy of the shipped library, mutated by $mutate. */
function library_variant(callable $mutate): string {
    $root = scratch('library-variant');
    copy_tree(shipped_library(), "$root/manifests");
    $mutate("$root/manifests");
    return "$root/manifests";
}

/**
 * Strip a variant library's reviewed claim source entirely — the state a custom
 * or test manifest directory really reaches. One `unlink()` did this while the
 * source was one document; WP-4.4 made it a directory (spec/repo-format.md
 * § v3.4), so the whole directory goes.
 */
function remove_library_dispositions(string $dir): void {
    foreach (glob("$dir/dispositions/*.json") ?: [] as $document) {
        unlink($document);
    }
    @rmdir("$dir/dispositions");
}

/** Rewrite one JSON file of a variant library through $edit, via Canon. */
function edit_json(string $file, callable $edit): void {
    Canon::write_file($file, Canon::encode($edit(Canon::decode(Canon::read_file($file)))));
}

$realManifests = dirname(__DIR__, 4) . '/manifests';
$shippedDir = shipped_library();
// Every group below resolves the shipped library through this, including the
// ones that install their own directory and restore it afterwards.
putenv("DUO_MANIFESTS_DIR=$shippedDir");

// ======================================================================
echo "\n== the fixture library IS the shipped library, whole ==\n";
// ======================================================================
// The comparison covers capabilities/ too. While the generated attestation
// existed the fixture was entitled to differ there — it re-derived those bytes
// — and the check had to exclude the one directory it could not vouch for.
// Nothing is derived any more, so the strongest available statement is also
// the true one: not a byte differs anywhere.
$fixtureBytes = duo_cert_library_bytes($shippedDir);
check(
    $fixtureBytes === duo_cert_library_bytes($realManifests) && $fixtureBytes !== [],
    'every manifest, disposition, interpreter, provider, regenerator, the platform boundary and the shipped trust '
    . 'root under test is the shipped file byte for byte (' . count($fixtureBytes) . ' files)'
);
check(
    array_key_exists('capabilities/platform.json', $fixtureBytes)
    && array_key_exists('capabilities/adapter-authorities.json', $fixtureBytes)
    && array_key_exists('dispositions/core.json', $fixtureBytes)
    && array_key_exists('dispositions/profiles.json', $fixtureBytes)
    && $fixtureBytes === array_filter(
        $fixtureBytes,
        fn(string $relative): bool => !str_starts_with($relative, 'capabilities/scoped/')
            && $relative !== 'capabilities/registry.json'
            && $relative !== 'capabilities/evidence.json',
        ARRAY_FILTER_USE_KEY
    ),
    'the shipped capabilities/ directory is the platform boundary and the trust root and nothing else — no '
    . 'generated registry, no evidence attestation, no per-subject bundle tree'
);
// The reviewed bytes ARE the claim source now, so their content address is the
// number a host contract pins. Both readings of one file must agree; a second
// definition of "which review decided this" is the whole failure the retired
// generated registry was.
$fixtureDispositions = \Duo\ManifestDispositions::load($shippedDir);
$shippedRegistry = ['format' => \Duo\ManifestDispositions::FORMAT, 'manifests' => [], 'profiles' => []];
foreach (glob("$realManifests/dispositions/*.json") ?: [] as $document) {
    $subject = basename($document, '.json');
    $decoded = Canon::decode(Canon::read_file($document));
    if ($subject === 'profiles') {
        $shippedRegistry['profiles'] = $decoded;
        continue;
    }
    $shippedRegistry['manifests'][$subject] = $decoded;
}
ksort($shippedRegistry['manifests'], SORT_STRING);
check(
    $fixtureDispositions !== null
    && hash_equals($fixtureDispositions->sha256(), hash('sha256', Canon::encode($shippedRegistry))),
    'registry_sha256 addresses exactly the shipped manifests/dispositions/ bytes reassembled, read through the real '
    . 'loader — the number did not move when WP-4.4 split the document'
);

// ======================================================================
echo "\n== the motivating refusal: a site adapter no longer takes down the shipped library ==\n";
// ======================================================================
// Before DUO-3314 there was exactly one adapter source, so an extra adapter
// could only be installed by dropping it into the shipped manifest directory
// — where ManifestDispositions::load()'s one-for-one coverage check refused
// it AND every unrelated shipped adapter along with it. Both halves are
// asserted: the new source works, and an unreviewed adapter still cannot be
// USED.
//
// The second half moved with WP-1.2 and the move is the subject of the two
// checks below: coverage is proved against the PINNED shipped subset
// (ManifestDispositions::assert_covers(), called from Policy::load() with
// AdapterSources::shipped_manifests()), so the unrelated pin now loads and the
// pin that names the unreviewed adapter refuses with the same sentence. The
// directory-wide "every file is reviewed AND every review has a file"
// property is an authoring rule enforced by `make release-gate`
// (tools/capability-doc.php's capdoc_cross_check()) and by
// regress_manifest_dispositions.php over the real library.

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
check(
    count(Policy::load(fresh_site(['core']))->manifests) === 1,
    'dropping an unreviewed adapter into the SHIPPED library no longer refuses an unrelated pin — one uncovered '
    . 'file used to take every reviewed adapter beside it down, which is the same failure shape one directory over'
);
check(
    message_of(fn() => Policy::load(fresh_site(['core', 'acme-widget'])))
        === 'duo: manifest disposition coverage mismatch; missing=[acme-widget], extra=[]',
    'and PINNING it still refuses, in the sentence the whole-directory check emitted, byte for byte — replacing or '
    . 'extending the reviewed manifest set cannot silently discard shipped claims'
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
// There is one derivation of adapter identity now: the manifest_rows() row,
// hashed. CapabilityRegistry::adapter_digest() used to mirror that row so it
// could hash a manifest without loading a compiler, and the mirror is what
// could drift; this asserts the surviving single definition still produces the
// digest the compiler binds, and that a shipped row's disposition slot really
// does carry the reviewed entry rather than a provenance record.
$coreIdentityRow = null;
foreach (\Duo\ArtifactPolicyIdentity::manifest_rows($overlay) as $identityRow) {
    if ($identityRow['name'] === 'core') {
        $coreIdentityRow = $identityRow;
    }
}
check(
    is_array($coreIdentityRow)
    && $soloCore['digest'] === hash('sha256', Canon::encode($coreIdentityRow))
    && $coreIdentityRow['disposition'] === $overlay->manifest_disposition('core')
    && ($coreIdentityRow['disposition']['status'] ?? null) === 'certified',
    'the shipped digest is exactly its identity row hashed, and that row still binds the reviewed disposition — no '
    . 'provenance key was added to shipped rows'
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
    && !array_key_exists('revision', $overlayReport['query'] ?? []),
    'a mixed-source report exposes no misleading aggregate evidence/platform authority, and no longer offers a '
    . 'revision selector at all — an evidence-bound platform revision is a thing nothing records now'
);
check(
    ($coreRow['evidence_scope'] ?? null) === 'authored_disposition'
    && ($siteRow['evidence_scope'] ?? null) === 'none'
    && ($coreRow['evidence'] ?? null)
        === (Canon::decode(Canon::read_file("$shippedDir/dispositions/core.json"))['evidence'] ?? null)
    && ($siteRow['evidence'] ?? null) === [],
    'a shipped row cites the reviewed disposition VERBATIM — the authored bundle schema and named tests, with no '
    . 'status this code decided — and an unsigned site row inherits none of it'
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
    && !in_array('missing_disposition_entry', $siteCodes, true),
    'the out-of-tree reason code is its own, never the shipped "someone deleted a disposition entry" code'
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
echo "\n== the certified verdict above is earned: a broken review still refuses ==\n";
// ======================================================================
// The counterweight to the fixture. Everything above reads the shipped
// library, so this group takes that same library and breaks the ONE document
// that now decides a claim, in each way it can genuinely break. Each variant
// is a throwaway copy; the shipped library is never touched.
//
// The list is shorter than it was, and the deletions are the point rather than
// a relaxation: `evidence_not_current`, `revision_not_certified`, and the
// stale-attestation/stale-adapter-digest refusals all guarded a GENERATED
// registry's agreement with a GENERATED attestation. Neither document exists,
// so every one of those refusals now has nothing to refuse — asserting them
// would be asserting a mechanism, not a boundary. What is left is what was
// always the actual gate: the reviewed bytes, and the platform they describe.

putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    edit_json("$dir/dispositions/core.json", function (array $entry): array {
        unset($entry['evidence']);
        return $entry;
    });
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    "certified manifest disposition 'core' lacks current bundle evidence",
    'a certified claim with no named evidence is refused — the citation is the whole difference between a review '
    . 'and an assertion, and nothing else vouches for it now'
);
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    edit_json("$dir/dispositions/core.json", function (array $entry): array {
        $entry['evidence']['tests'] = [];
        return $entry;
    });
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    "certified manifest disposition 'core' lacks current bundle evidence",
    'and an empty test list is the same refusal — a citation naming nothing cites nothing'
);
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    edit_json("$dir/dispositions/core.json", function (array $entry): array {
        $entry['status'] = 'ratified';
        return $entry;
    });
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    "manifest disposition 'core' has a malformed required field",
    'a reviewed status outside certified/experimental/excluded is refused, not read as a fourth kind of support'
);
// The synthesized runtime status is not a status a review may DECLARE: reading
// it back would let a library launder "nobody reviewed this" into a reviewed
// answer about itself.
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    edit_json("$dir/dispositions/core.json", function (array $entry): array {
        $entry['status'] = \Duo\ManifestDispositions::STATUS_UNCOVERED;
        return $entry;
    });
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core'])),
    "manifest disposition 'core' has a malformed required field",
    "'uncovered' is a synthesized runtime status only; a disposition that declares it is refused"
);

// The platform boundary is the second reviewed document, and the one a signed
// site certificate binds. An absent or disagreeing one must stop the claim
// being projected rather than be filled in from the running agent.
$noPlatform = library_variant(function (string $dir): void {
    unlink("$dir/capabilities/platform.json");
});
putenv("DUO_MANIFESTS_DIR=$noPlatform");
expect_throw(
    fn() => Policy::load(fresh_site(['core']))->capability_report(['operation' => 'promote']),
    'this manifest library declares no platform boundary',
    'a library with no platform boundary projects no capability claim at all — the environment half of every claim '
    . 'would otherwise be invented by the code reading it'
);
putenv('DUO_MANIFESTS_DIR=' . library_variant(function (string $dir): void {
    edit_json("$dir/capabilities/platform.json", function (array $platform): array {
        $platform['platform']['agent_version'] = '0.0.1-not-this-agent';
        return $platform;
    });
}));
expect_throw(
    fn() => Policy::load(fresh_site(['core']))->capability_report(['operation' => 'promote']),
    'platform version disagrees with the loaded agent',
    'a platform boundary describing a different agent than the one running is refused — a claim about a runtime '
    . 'nobody is running is worse than no claim'
);

// The coupling DUO-3379 built the re-seal to remove, asserted from the other
// side now that it is gone: editing a shipped manifest no longer refuses
// anything, because no generated document was pinned to its bytes. The edit is
// still not invisible — it moves the adapter digest, which is what a content
// pin binds.
$editedLibrary = library_variant(function (string $dir): void {
    edit_json("$dir/core.json", function (array $manifest): array {
        $manifest['options']['duo_regress_bound_input_marker'] = ['class' => 'authored'];
        return $manifest;
    });
});
putenv("DUO_MANIFESTS_DIR=$editedLibrary");
$editedCore = RepositoryCompiler::resolved_adapters(Policy::load(fresh_site(['core'])))[0];
check(
    $editedCore['digest'] !== $soloCore['digest'],
    'editing a shipped manifest loads without refusing — no attestation binds its bytes any more — but moves its '
    . 'adapter digest, so a repository pin still catches the change'
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
// T6 §3.3, as the walk's S4 met it: an override is a COPY of the shipped
// adapter with an edit, and the shipped woocommerce adapter declares
// executable grants (a manifest-sourced provider). The out-of-tree rule
// refuses those for an unrelated site adapter (below, "acquires no executable
// privileges"); for an override they are the shipped grant repeated, and the
// site copy inherits exactly them — no more.
$shippedWoo = json_decode((string) file_get_contents(Policy::manifests_dir() . '/woocommerce.json'), true);
$grants = AdapterSources::shipped_executable_grants(Policy::manifests_dir(), 'woocommerce');
check(
    is_array($grants)
        && array_keys($grants) === ['interpreter', 'regenerators', 'providers']
        && isset($grants['providers']['woocommerce-cache'])
        && $grants['providers']['woocommerce-cache'] === $shippedWoo['providers'][0],
    'shipped_executable_grants() returns the shipped interpreter / regenerators / providers by id, verbatim'
);
check(
    AdapterSources::shipped_executable_grants(Policy::manifests_dir(), 'acme-widget') === null,
    'and null for a name the library does not ship — an unrelated site adapter inherits nothing'
);
$overrideCopy = $shippedWoo;
$overrideCopy['options']['woocommerce_walk_banner'] = ['class' => 'authored'];
$inheritedRepo = fresh_site(
    [['name' => 'core'], ['name' => 'woocommerce', 'source' => 'site']],
    ['woocommerce' => $overrideCopy]
);
$inheritedPolicy = Policy::load($inheritedRepo);
$inheritedManifest = null;
foreach ($inheritedPolicy->manifests as $m) {
    if (($m['name'] ?? null) === 'woocommerce') {
        $inheritedManifest = $m;
    }
}
check(
    is_array($inheritedManifest)
        && ($inheritedManifest['providers'][0]['id'] ?? null) === 'woocommerce-cache'
        && ($inheritedManifest['providers'][0]['source'] ?? null) === 'manifest'
        && ($inheritedManifest['options']['woocommerce_walk_banner']['class'] ?? null) === 'authored',
    'an override that repeats the shipped provider declaration verbatim LOADS with it, plus its own edit'
);
check(
    ($inheritedPolicy->adapter_sources()->diagnostics($inheritedPolicy->manifests)['woocommerce']['trust_tier'] ?? null)
        === AdapterSources::TIER_COMPATIBILITY_SHIM,
    'and carries the shipped tier the inherited code implies — the site copy is not laundered into declarative'
);
$widenedCopy = $overrideCopy;
$widenedCopy['providers'][] = [
    'capabilities' => ['flush'],
    'id' => 'walk-rogue',
    'plugin' => 'woocommerce/woocommerce.php',
    'source' => 'manifest',
    'version' => '1.0.0',
];
expect_throw(
    fn() => Policy::load(fresh_site(
        [['name' => 'core'], ['name' => 'woocommerce', 'source' => 'site']],
        ['woocommerce' => $widenedCopy]
    )),
    'source "manifest"',
    'an override that ADDS a manifest-sourced provider the shipped adapter does not grant is refused — '
    . 'inheritance is the shipped grant, never a widening of it'
);
$editedGrant = $overrideCopy;
$editedGrant['providers'][0]['capabilities'][] = 'walk-extra';
expect_throw(
    fn() => Policy::load(fresh_site(
        [['name' => 'core'], ['name' => 'woocommerce', 'source' => 'site']],
        ['woocommerce' => $editedGrant]
    )),
    'source "manifest"',
    'an override that EDITS the inherited provider row (one more capability) is refused: identical or nothing'
);
$borrowedInterpreter = $overrideCopy;
$borrowedInterpreter['interpreter'] = 'acf';
expect_throw(
    fn() => Policy::load(fresh_site(
        [['name' => 'core'], ['name' => 'woocommerce', 'source' => 'site']],
        ['woocommerce' => $borrowedInterpreter]
    )),
    'acquires no executable privileges',
    'an override cannot borrow ANOTHER shipped adapter\'s interpreter under the shipped name it overrides'
);

// The uncertified override has NO capability claim: it never borrows the
// registry's entry for the shipped name it displaced (walk S4 read `Ready`
// beside `Uncertified` on post_type:product from exactly that borrowing).
check(
    $overridePolicy->capability_claim('woocommerce') === null,
    'an uncertified override answers with no capability claim — the shipped claim for the same name is not borrowed'
);
$overrideBlockers = array_column(
    $overridePolicy->capability_report(['operation' => 'promote'])['blockers'] ?? [],
    'code',
    'name'
);
check(
    ($overrideBlockers['woocommerce'] ?? null) === 'adapter_source_uncertified',
    'and the capability report blocks it as adapter_source_uncertified, not as the certified shipped adapter (got '
    . var_export($overrideBlockers['woocommerce'] ?? null, true) . ')'
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
// The SAME refusal for the split layout. WP-4.4 made the agent's own reviewed
// claim source a directory, so an operator copying that shape into their site
// source is making exactly the claim the refusal above exists for — and a
// directory the engine never reads is inert bytes they believe in, which is the
// failure mode this source refuses everywhere else. The retired file name stays
// covered beside it because an older copied library is what puts it there.
expect_throw(
    fn() => Policy::load(fresh_site(
        ['core'],
        ['acme-widget' => site_adapter('acme-widget')],
        ['adapters/dispositions/acme-widget.json' => ['status' => 'certified']]
    )),
    'cannot supply certification data for itself',
    'and a site adapter source shipping a dispositions/ DIRECTORY is refused in the same sentence — the split '
    . 'layout cannot be used to self-certify either'
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
            // WP-4.12: `acme-cache`, not `woocommerce-cache`. The id now sits
            // INSIDE this adapter's own vendor namespace on purpose — at
            // spec_version 3 the namespace rule (§ v3.9) refuses a foreign
            // provider id first, and this case is not about that rule. Naming
            // the provider legally is what makes the refusal below provably
            // about `source: "manifest"`: the adapter asks for code that would
            // resolve inside the agent, and is refused for that and nothing
            // else.
            'providers' => [[
                'id' => 'acme-cache',
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
// The identity row for the same manifest bytes with an EMPTY disposition slot:
// exactly what a shipped adapter carrying no reviewed entry would hash. The
// out-of-tree row fills that slot with its provenance record instead, which is
// the mechanism under test — so the two digests must differ.
$provenanceRow = \Duo\ArtifactPolicyIdentity::manifest_rows($provenancePolicy)[0];
$withoutProvenance = $provenanceRow;
$withoutProvenance['disposition'] = null;
check(
    $provenanceAdapter['digest'] === hash('sha256', Canon::encode($provenanceRow))
    && $provenanceAdapter['digest'] !== hash('sha256', Canon::encode($withoutProvenance))
    && is_array($provenanceRow['disposition'] ?? null),
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

// The v1 wire is retired along with duo-policy-snapshot/v4, the only envelope
// that ever carried it. This block used to prove v1 reconstructed with its own
// wire generation; that behaviour is what went, and for a reason this suite is
// the right place to record: under v1 a manifest absent from `out_of_tree` took
// SHIPPED authority with no proof, which is the fail-open half of the very
// laundering the `$laundered` case below pins v2 as closing.
$legacySnapshot = $snapshot;
$legacySnapshot['format'] = 'duo-policy-snapshot/v4';
$legacySnapshot['adapter_sources']['format'] = 'duo-adapter-sources/v1';
unset($legacySnapshot['adapter_sources']['certificates']);
$legacySnapshot['adapter_sources']['out_of_tree']['acme-widget']['provenance']['format'] =
    'duo-adapter-sources/v1';
expect_throw(
    fn() => Policy::from_snapshot($legacySnapshot),
    'duo-policy-snapshot/v4 is retired and is no longer read',
    'a legacy v1 unsigned adapter-source snapshot is refused by name rather than reconstructed'
);
// And a v1 record has no reader left of its own: Policy::from_snapshot() is the
// only caller of AdapterSources::from_snapshot(), so pairing the retired record
// with a CURRENT envelope is refused too. There is no remaining route onto the
// fail-open path — not through the old envelope, not through the new one.
$legacyRecordInCurrentEnvelope = $snapshot;
$legacyRecordInCurrentEnvelope['adapter_sources']['format'] = 'duo-adapter-sources/v1';
expect_throw(
    fn() => Policy::from_snapshot($legacyRecordInCurrentEnvelope),
    'disagrees with its adapter source record format',
    'the retired v1 record cannot ride a current envelope either'
);

$laundered = $snapshot;
unset($laundered['adapter_sources']['out_of_tree']['acme-widget']);
expect_throw(
    fn() => Policy::from_snapshot($laundered),
    'no shipped manifest exists',
    'dropping a v2 out-of-tree record cannot relabel a site adapter as shipped, even before registry validation'
);
// The same drop on the retired wire, and the reason the wire is retired. Under
// v1 this document did NOT refuse: with no dispositions to demand coverage, the
// dropped record left 'acme-widget' claiming SHIPPED authority and
// from_snapshot() agreed, relabelling a site adapter as agent-owned — the exact
// laundering the `$laundered` case above pins v2 as refusing outright. The
// compatibility being kept was compatibility with a hole, so both shapes now
// stop at the envelope.
$legacyLaundered = $legacySnapshot;
unset($legacyLaundered['adapter_sources']['out_of_tree']['acme-widget']);
expect_throw(
    fn() => Policy::from_snapshot($legacyLaundered),
    'duo-policy-snapshot/v4 is retired and is no longer read',
    'dropping a v1 out-of-tree record is refused at the envelope now, not left to the reviewed shipped coverage'
);
$legacyCustom = $legacyLaundered;
$legacyCustom['dispositions'] = null;
expect_throw(
    fn() => Policy::from_snapshot($legacyCustom),
    'duo-policy-snapshot/v4 is retired and is no longer read',
    'and the registry-free variant — the one v1 actually ACCEPTED, laundering a site adapter into a shipped one — '
    . 'is refused with it'
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
$bundledLegacy['adapter_sources']['format'] = 'duo-adapter-sources/v1';
unset($bundledLegacy['adapter_sources']['certificates']);
$bundledLegacy['adapter_sources']['out_of_tree']['acme-widget']['provenance']['format'] =
    'duo-adapter-sources/v1';
expect_throw(
    fn() => Policy::from_snapshot($bundledLegacy),
    'duo-policy-snapshot/v4 is retired and is no longer read',
    'a legacy v1 snapshot carrying a bundled record is refused with the rest of v4 — it was already a document no '
    . 'version of this engine ever wrote (v1 predates the plugin source), and the retirement makes that answer '
    . 'uniform instead of routing it through a per-field malformed check'
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
    'a provenance record pasted into a reviewed disposition is refused rather than accepted as a self-certification'
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
$plainReport = $plain->capability_report(['operation' => 'promote']);
$plainBlockers = $plainReport['blockers'] ?? null;
check(
    $plainReport['ready'] === false
    && is_array($plainBlockers)
    && count($plainBlockers) === 1
    && ($plainBlockers[0]['name'] ?? null) === 'woocommerce'
    && ($plainBlockers[0]['status'] ?? null) === 'blocked'
    && ($plainBlockers[0]['code'] ?? null) === 'authored_state_not_certified'
    && ($plainBlockers[0]['source'] ?? null) === 'shipped'
    && ($plainBlockers[0]['trust_tier'] ?? null) === 'compatibility_shim'
    && ($plainBlockers[0]['certification'] ?? null) === 'registry',
    'the shipped library reports WooCommerce as its exact single experimental blocker until final evidence certifies it'
);
$plainRows = [];
foreach ($plainReport['manifests'] ?? [] as $row) {
    if (is_array($row) && is_string($row['name'] ?? null)) {
        $plainRows[$row['name']] = $row;
    }
}
$shippedWooEntry = Canon::decode(Canon::read_file("$shippedDir/dispositions/woocommerce.json"));
check(
    ($plainReport['evidence_scope'] ?? null) === 'per_subject'
    && ($plainReport['evidence'] ?? null) === null
    && is_array($plainReport['platform'] ?? null)
    && ($plainRows['core']['evidence_scope'] ?? null) === 'authored_disposition'
    && ($plainRows['woocommerce']['evidence_scope'] ?? null) === 'authored_disposition'
    && ($plainRows['woocommerce']['evidence'] ?? null) === ($shippedWooEntry['evidence'] ?? null)
    && !array_key_exists('status', $plainRows['woocommerce']['evidence'] ?? []),
    'each shipped row names its own claim authority — the authored disposition, cited verbatim, with no synthesized '
    . 'currency status: a `current` here would be the agent vouching for itself'
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
    $allTiers === ['compatibility_shim', 'declarative_manifest', 'native_action', 'plugin_provider'],
    'the shipped library really does span four tiers, so "every row says shipped/declarative" would be a visibly '
    . 'wrong answer here (found: ' . implode(', ', $allTiers) . ')'
);

// ======================================================================
echo "\n== a library with no reviewed dispositions at all reports itself unreviewed ==\n";
// ======================================================================
// This replaced a backstop for "dispositions present, generated registry
// absent", which Policy::load() used to refuse outright and which cannot
// happen now — dispositions ALONE are a complete, valid state, so there is no
// second document whose absence could be a fault. The state that remains is
// the honest one a custom or test manifest directory really reaches: no
// reviewed document at all. It makes no product claim (spec/repo-format.md
// says so in as many words), and the report has to SAY that rather than read
// the absence as nothing to block on.
$unreviewedDir = library_variant(function (string $dir): void {
    remove_library_dispositions($dir);
});
putenv("DUO_MANIFESTS_DIR=$unreviewedDir");
$unreviewedPolicy = Policy::load(fresh_site(['core']));
$unreviewedReport = $unreviewedPolicy->capability_report(['operation' => 'promote']);
check(
    ($unreviewedReport['ready'] ?? null) === false
    && count($unreviewedReport['blockers'] ?? []) === 1
    && ($unreviewedReport['blockers'][0]['name'] ?? null) === 'registry'
    && ($unreviewedReport['blockers'][0]['status'] ?? null) === 'unreviewed'
    && array_key_exists('registry_sha256', $unreviewedReport)
    && $unreviewedReport['registry_sha256'] === null
    && ($unreviewedReport['manifests'] ?? null) === [],
    'a library with no reviewed dispositions directory loads, and answers with the one unreviewed blocker, a null '
    . 'content '
    . 'address, and no rows — it defers its certification to nothing, and says so instead of reading green'
);
$unreviewedSurvey = [];
foreach (AdapterSources::survey(null)['adapters'] as $surveyed) {
    $unreviewedSurvey[(string) $surveyed['name']] = $surveyed;
}
check(
    array_key_exists('core', $unreviewedSurvey)
    && $unreviewedSurvey['core']['certification'] === null
    && $unreviewedSurvey['core']['disposition_status'] === null,
    'and the surveyed shipped row reports a NULL certification state rather than naming a review nobody wrote'
);
// DUO-3486: the OTHER projection of that same fact. diagnostics() answers
// `wp duo capabilities` where survey() answers `wp duo adapter list`, and it
// used to hardcode `registry` for every shipped row — so one library described
// one adapter two ways, and the word that named a review nobody wrote was the
// one an operator chasing a promotion refusal would read.
$unreviewedDiagnostics = $unreviewedPolicy->adapter_sources()->diagnostics($unreviewedPolicy->manifests);
// Rendered through array_key_exists rather than `?? '(absent)'`, which would
// report the very null under test as a missing key.
$unreviewedWord = array_key_exists('certification', $unreviewedDiagnostics['core'] ?? [])
    ? var_export($unreviewedDiagnostics['core']['certification'], true)
    : '(absent)';
check(
    array_key_exists('core', $unreviewedDiagnostics)
    && array_key_exists('certification', $unreviewedDiagnostics['core'])
    && $unreviewedDiagnostics['core']['certification'] === null
    && $unreviewedDiagnostics['core']['certification'] === $unreviewedSurvey['core']['certification']
    && $unreviewedDiagnostics['core']['source'] === AdapterSources::SHIPPED
    && $unreviewedDiagnostics['core']['remediation'] === '',
    'the diagnostics projection answers the SAME null for that shipped row — the two surfaces cannot describe '
    . 'one adapter two ways (diagnostics: ' . $unreviewedWord . ', survey: '
    . var_export($unreviewedSurvey['core']['certification'], true) . ')'
);
// And the null has to TRAVEL. capability_report() emits no manifest rows for
// this library at all (checked above), so the one surface that can still print
// a diagnostics certification word here is the provider blocker — the row
// AdapterRegistry builds without any registry, from packaging facts alone. It
// is reached with a manifest whose shipped provider code is missing, which is
// also the honest shape of the situation: a hand-assembled library, incomplete
// in more than one way.
$brokenProviderDir = library_variant(function (string $dir): void {
    remove_library_dispositions($dir);
    unlink("$dir/providers/woocommerce-cache.php");
});
putenv("DUO_MANIFESTS_DIR=$brokenProviderDir");
$brokenPolicy = Policy::load(fresh_site(['core', 'woocommerce']));
$brokenBlockers = $brokenPolicy->adapter_readiness_blockers();
$brokenLines = \Duo\Orchestrator\PlanSummary::render(
    ['adapter_dispositions' => $brokenBlockers]
)['lines'];
$brokenWords = array_values(array_unique(array_column($brokenBlockers, 'certification')));
check(
    $brokenBlockers !== []
    && array_column($brokenBlockers, 'code') === ['provider_code_unavailable']
    && $brokenWords === ['unknown']
    && str_contains(implode("\n", $brokenLines), 'certification=unknown')
    && !str_contains(implode("\n", $brokenLines), 'certification=registry'),
    'the provider blocker a registry-less library CAN still raise renders `unknown` rather than minting '
    . '`registry` back out of the absence — the null reaches a renderer and stays honest (words: '
    . implode(', ', array_map(static fn($w): string => var_export($w, true), $brokenWords))
    . '; codes: ' . implode(', ', array_column($brokenBlockers, 'code')) . ')'
);
putenv("DUO_MANIFESTS_DIR=$shippedDir");

// ======================================================================
echo "\n== WP-1.3: one resolved library per survey, and the same verdicts as a load per row ==\n";
// ======================================================================
// survey() no longer re-runs discover() and the reviewed-registry read for
// every row; it resolves the library once behind AdapterScan and threads that
// handle into grammar_verdict(). The claim that matters is not the saving but
// the SAMENESS: every row's verdict must be exactly what an unmemoized
// Policy::load() of that one adapter produces, message included. That is what
// this group compares, over the REAL shipped library plus a site adapter, one
// row at a time.
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterScan.php';
$scanRepo = fresh_site(['core'], ['acme-widget' => site_adapter('acme-widget')]);
$scanSurvey = AdapterSources::survey($scanRepo);
$verdictMismatches = [];
foreach ($scanSurvey['adapters'] as $surveyed) {
    // The unmemoized verdict, taken exactly as grammar_verdict() took it
    // before WP-1.3: this repository, this one pin, the real loader.
    try {
        Policy::load($scanRepo, [(string) $surveyed['name']]);
        $unmemoized = ['message' => null, 'status' => AdapterSources::GRAMMAR_OK];
    } catch (\Throwable $t) {
        $unmemoized = ['message' => $t->getMessage(), 'status' => AdapterSources::GRAMMAR_ERROR];
    }
    if ($surveyed['grammar'] !== $unmemoized) {
        $verdictMismatches[] = (string) $surveyed['name'] . ': surveyed '
            . json_encode($surveyed['grammar']) . ' vs fresh ' . json_encode($unmemoized);
    }
}
check(
    $verdictMismatches === [] && count($scanSurvey['adapters']) > 10,
    'every one of the ' . count($scanSurvey['adapters']) . ' surveyed rows carries byte-identical grammar to a '
    . 'fresh per-row Policy::load() — the memo changed what the survey COSTS and nothing about what it says'
    . ($verdictMismatches === [] ? '' : ' (mismatches: ' . implode('; ', $verdictMismatches) . ')')
);

// Two loads through one handle are two INDEPENDENT policies. The finalizer
// binds each load's pins onto its own AdapterSources instance
// (PolicyLoadFinalizer.php:51), so a shared instance would carry row 1's
// explicit pins into row 2's certification elevation — which is why the
// resolved sources are cloned per load rather than handed out.
$handleDir = library_variant(function (string $dir): void {});
putenv("DUO_MANIFESTS_DIR=$handleDir");
$handle = \Duo\AdapterScan::open(null);
$firstLoad = $handle->load('core');
$secondLoad = $handle->load('classic-editor');
check(
    $firstLoad instanceof Policy
    && $secondLoad instanceof Policy
    && $firstLoad->adapter_sources() !== $secondLoad->adapter_sources()
    && array_column($firstLoad->manifests, 'name') === ['core']
    && array_column($secondLoad->manifests, 'name') === ['classic-editor'],
    'two pins loaded through one handle get two independent policies over two independent source instances — '
    . 'the memo is the SCAN, never the loaded policy'
);

// The file SET moves: a manifest appears beside the ones already resolved.
// The next reuse refuses rather than answering from a scan taken before it
// existed, and refuses TYPED so `--format=json` can name it.
file_put_contents(
    "$handleDir/zz-late-arrival.json",
    Canon::encode(['name' => 'zz-late-arrival', 'spec_version' => DUO_SPEC_VERSION])
);
$movedRefusal = null;
try {
    $handle->load('core');
} catch (\Duo\CommandRefusalException $refusal) {
    $movedRefusal = $refusal;
}
check(
    $movedRefusal instanceof \Duo\CommandRefusalException
    && $movedRefusal->reasonCode === \Duo\AdapterScan::REFUSAL_MOVED
    && $movedRefusal->payload()['error'] === \Duo\AdapterScan::REFUSAL_MOVED
    && $movedRefusal->detailsRedacted === false
    && str_contains($movedRefusal->getMessage(), 'moved mid-survey'),
    'a manifest that appears under an open handle REFUSES the reuse — typed, publishable, and named '
    . '(reason: ' . var_export($movedRefusal?->reasonCode, true) . ')'
);

// The other half. An in-place rewrite churns no directory entry, so the
// per-row shape witness cannot see it — the handle keeps answering, which is
// correct: each row re-reads its OWN manifest, so no row is answered from
// stale bytes. What must not happen is the SURVEY finishing as though it had
// read one library, and settle() is where that is refused.
$settleDir = library_variant(function (string $dir): void {});
putenv("DUO_MANIFESTS_DIR=$settleDir");
$settleHandle = \Duo\AdapterScan::open(null);
$settleHandle->load('core');
$rewritten = "$settleDir/classic-editor.json";
$rewrittenBefore = (string) file_get_contents($rewritten);
file_put_contents($rewritten, str_replace('classic-editor', 'classic-editoR', $rewrittenBefore));
$survivedShape = false;
try {
    $settleHandle->load('core');
    $survivedShape = true;
} catch (\Throwable $t) {
    $survivedShape = false;
}
$settleRefusal = null;
try {
    $settleHandle->settle();
} catch (\Duo\CommandRefusalException $refusal) {
    $settleRefusal = $refusal;
}
check(
    $survivedShape
    && $settleRefusal instanceof \Duo\CommandRefusalException
    && $settleRefusal->reasonCode === \Duo\AdapterScan::REFUSAL_MOVED
    && str_contains($settleRefusal->getMessage(), 'file content change'),
    'a manifest rewritten IN PLACE passes the per-row directory witness and is caught by the content witness at '
    . 'settle() — the survey refuses instead of publishing rows taken across two libraries '
    . '(shape survived: ' . var_export($survivedShape, true) . ', settle refused: '
    . var_export($settleRefusal?->reasonCode, true) . ')'
);
putenv("DUO_MANIFESTS_DIR=$shippedDir");

echo $failures === 0 ? "\nALL PASSED\n" : "\nFAIL: $failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
