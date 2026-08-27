<?php
/**
 * WP-4.4: the reviewed claim source is one document per subject, and NOT ONE
 * ADAPTER DIGEST MOVED (spec/repo-format.md § v3.4).
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * `manifests/dispositions.json` was 302 lines, 37,707 bytes and 16 entries in
 * one file; it is now 17 documents under `manifests/dispositions/`. That is a
 * relocation of bytes AGENTS.md rule 2 calls adapter identity:
 * `ArtifactPolicyIdentity::manifest_rows()` folds each manifest's own
 * disposition into that adapter's row and the row hashed IS its `digest`, so a
 * one-byte canonical difference in one document would move that adapter's
 * digest, every `site.duo.json` content pin naming it, and — through
 * `manifest_hash` — every compiled artifact in the field. On a flag day whose
 * entire premise is that no shipped digest moves, that is the failure this
 * whole package had to make impossible rather than unlikely.
 *
 * THE GATE ASSERTION, AND WHY IT IS NOT A TAUTOLOGY
 * ------------------------------------------------
 * PART 1 pins all 16 shipped digests, `manifest_hash` and `registry_sha256` as
 * LITERALS captured from the tree BEFORE the split, through the product path a
 * deployed site uses. Recomputing both sides of an equality would prove
 * nothing — it would hold whatever the split did to the bytes — so the
 * expected values are frozen text in this file and the comparison is against
 * the engine.
 *
 * PART 2 is what makes that comparison a measurement. It enumerates the
 * canonical-encoding hazards a relocation of JSON can introduce and measures
 * each one through the same product path, in three verdicts rather than one:
 *
 *   - a nested LIST re-ordered and a UTF-8 prose `reason` re-composed each MOVE
 *     a digest, so PART 1 would have named the adapter a splitter corrupted
 *     that way;
 *   - map KEY order at every nesting level moves nothing, which is the one
 *     property that makes lifting an entry out of a document admissible at all
 *     (Canon sorts keys everywhere, Canon.php:44,58);
 *   - the int/float round trip is the hazard the digest CANNOT catch — Canon
 *     erases it — so its guard is the census beside it: no shipped reviewed
 *     member is a number, and that assertion is a tripwire, not trivia.
 *
 * A suite that reported three passes here would be hiding the third answer,
 * which is the one a future reviewer needs.
 *
 * PART 3 is the refusals. Every per-entry rule, the frozen root rule and the
 * profile rules fire from the split form in their existing wording, the
 * coverage refusal for a PINNED subject with no document is byte-identical to
 * the monolith's, and the three rules the DIRECTORY adds refuse by name.
 */
declare(strict_types=1);

// WP-4.12: derived from agent/duo.php, not retyped. This suite reads the
// SHIPPED platform.json (through Policy::load -> AdapterRegistry), and that
// document restates both defines — so a literal here disagrees with the tree
// the moment the defines move and the suite dies on "platform version
// disagrees with the loaded agent" instead of reporting anything about
// dispositions. See sandbox/tests/lib/agent_version.php.
require_once __DIR__ . '/../../lib/agent_version.php';
duo_test_define_agent_versions();
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterRegistry.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';

use Duo\ArtifactPolicyIdentity;
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Policy;

$repo = dirname(__DIR__, 4);
$manifestDir = $repo . '/manifests';
$subjectDir = $manifestDir . '/' . ManifestDispositions::DIRECTORY;

/**
 * One stable scratch root under sandbox/tmp (AGENTS.md rule 3), cleared before
 * use: nothing else writes this path, and a per-pid name would leave a fixture
 * behind on every red run.
 */
$scratchRoot = $repo . '/sandbox/tmp/disposition-split';
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeTree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
};
$removeTree($scratchRoot);
register_shutdown_function(static function () use ($removeTree, $scratchRoot): void {
    if (duo_check_failed() === 0) {
        $removeTree($scratchRoot);
    }
});

// ---------------------------------------------------------------------------
echo "\nPART 1 — THE GATE: every shipped digest is the digest it was before the split\n";
// ---------------------------------------------------------------------------
// Captured from the pre-split tree (adapter-program @ ed852db6) by loading all
// 16 shipped manifests and reading ArtifactPolicyIdentity::resolved_adapters().
// These are the numbers a deployed site holds in `site.duo.json` and in every
// compiled artifact, so a moved one here is a fleet-wide
// `compiled_artifact_manifest_mismatch` and not a test failure.
$frozenDigests = [
    'acf' => '59bcfb5c04958c9e4b340f2c47772b245f4afee15e358eef692c0887365450c3',
    'advanced-editor-tools' => 'f053a9a1974869ae957795357282250ef664194ed3be192d97222789a4b755c3',
    'classic-editor' => '33ba7b66daadcf52f0103d7a6abd2594d98067ba22f82e0c2751ac25e106bd29',
    'code-snippets' => 'b65ed9be8c7ddbe4436bf98bbaf0bb1dbf412b67817f1884082fe1df56c1851b',
    'contact-form-7' => '8b85b02e7cc816799be570b1e86e0fa16502628771abd66dbe74415e85f34553',
    'core' => '9c07275d02d726336a2270ce4260403f40dc406c3353470adf714cf753905aa2',
    'duo-agency-cpt' => '77ba41d17579c97ee27cd30a3eac67224c66f9244d74e349b29035cd3ae95362',
    'elementor' => '80df69cc238bf8859b02635519d562e069442b5948091fe666bfaab81d4bf42b',
    'ninja-forms' => '54a069f27fb1518f7a7dee825fb004cf1cdecb95024f83f6b51fd87e7d32352c',
    'paid-memberships-pro' => 'ec1109615042d839f4958319bfe1b52be98f95a3024748c91f92f6ce5d27bf72',
    'polylang' => '99a32be4ecbd016f548f3e9352855281a8a9a6d3775322c5f71b599f0f95c07d',
    'the-events-calendar' => '8e9bc8095835916f70866c903e520610c18fd760ed246e4b755389e281e2cf61',
    'woocommerce' => 'fe4edd96fed1ab7dc54a204bf270792cb01f7f434c51897beab27055c0133b0b',
    'wps-hide-login' => 'd98e5643027fdf941239a32c4e95bbd2bac68a29b326e0b4fbb5a9a3395e060e',
    'yoast' => '652bff48b6f28beb40e667da31f498a7d0d7dde68693e3c2f92977affe6cdbaa',
    'yoast-duplicate-post' => '123547fbbbdcba321dbc99d6d16482e443f3eed5edb0d2ac7965690a23144fa3',
];
const SPLIT_FROZEN_MANIFEST_HASH = '937450d86d3ac3e216979c204e3952c543572ce194da5bc34b2a0c9c3e891393';
const SPLIT_FROZEN_REGISTRY_SHA = '8d6c35cfe4c5f21193e83cc4707a11359680df8e95e48e087387fe00ef491744';
const SPLIT_FROZEN_SNAPSHOT_SHA = 'c9ef88ac0f92ba04411de26738b974deca77600c8e79947b53e927703cf93bbc';

/**
 * The reviewed post-split changes that have moved shipped identities since
 * that capture: #561 rewrote manifests/the-events-calendar.json and promoted
 * its disposition experimental -> certified, and widened manifests/core.json's
 * native rewrite action to declare TEC's rewrite-listener effects. The
 * reviewed Polylang production-readiness port then rewrote its manifest,
 * interpreter/provider set, and per-subject disposition. PMPro's reviewed
 * engine-absorption move later replaced its provider with the generic
 * invalidate declaration and re-stamped that manifest at v3. Rule 2 makes all
 * four fleet-visible BY DESIGN.
 *
 * The 12 frozen digests above are NOT regenerated — this is an overlay, and
 * PART 1 asserts the moved set is exactly these four. A fifth adapter is a
 * tripwire failure, not a re-pin. That keeps the split's
 * own invariant ("relocating the reviewed source moved no identity") measured
 * against numbers captured before the relocation, on every adapter the
 * reviewed changes did not touch. Re-freezing all 16 to absorb 4 would have
 * retired the evidence for the other 12 to fix a red run.
 */
const SPLIT_REVIEWED_MOVED_ADAPTERS = ['core', 'paid-memberships-pro', 'polylang', 'the-events-calendar'];
const SPLIT_REVIEWED_MOVED_DIGESTS = [
    'core' => '2d72608ff976c3b050062c126128549f0711a84203ef28f17d594728afb18858',
    'paid-memberships-pro' => '59e95f6f2089cb7b37787920ae62a9adbc83f6f7b4c673f611aa62fdb8fe2880',
    'polylang' => '99d82ecc6402fda3a8d651d56ca07ae4a73836947ed5f2ecca11c0df4472e9a4',
    'the-events-calendar' => 'ae74bedeab559531758ac7a9268cad15ef37568471ceae5cab9353b93519cd78',
];
const SPLIT_REVIEWED_MANIFEST_HASH = '09f07e92b2f1d0ec9ccc1229d61e5d61682608e228de06e0285ab84cac951f44';
const SPLIT_REVIEWED_REGISTRY_SHA = 'f98de94d550375201697cd2e8c507f7b2f0941b9e92dab4bc85d77635ec10746';
const SPLIT_REVIEWED_SNAPSHOT_SHA = '03b0e963bff8444709d8f43db0873929983f5ab0eff7ee94b51846f135bed37a';

putenv('DUO_MANIFESTS_DIR=' . $manifestDir);
$shippedRegistry = ManifestDispositions::load($manifestDir);
duo_check(
    $shippedRegistry instanceof ManifestDispositions,
    'the shipped library loads its reviewed claim source from the per-subject directory'
);
$shippedNames = array_keys($frozenDigests);
$shippedPolicy = Policy::load(null, $shippedNames);
$observed = [];
foreach (ArtifactPolicyIdentity::resolved_adapters($shippedPolicy) as $row) {
    $observed[(string) $row['name']] = (string) $row['digest'];
}
ksort($observed, SORT_STRING);
$expectedDigests = $frozenDigests;
foreach (SPLIT_REVIEWED_MOVED_DIGESTS as $movedName => $movedDigest) {
    $expectedDigests[$movedName] = $movedDigest;
}
ksort($expectedDigests, SORT_STRING);
duo_check_same(
    4,
    count(SPLIT_REVIEWED_MOVED_ADAPTERS),
    'the reviewed overlay names exactly four post-split adapters; a fifth identity move is a new reviewed re-pin, never a fixture refresh'
);
duo_check_same(
    $expectedDigests,
    $observed,
    '12 OF THE 16 SHIPPED ADAPTER DIGESTS ARE BYTE-IDENTICAL to the pre-split tree — the invariant the flag day '
    . 'rests on; the other four carry reviewed #561/Polylang/PMPro edits and are re-pinned above'
);
$movedNames = [];
foreach ($observed as $name => $digest) {
    if (($frozenDigests[$name] ?? '') !== $digest) {
        $movedNames[] = $name;
    }
}
duo_check_same(
    SPLIT_REVIEWED_MOVED_ADAPTERS,
    $movedNames,
    'and the moved set is EXACTLY the reviewed one — the split relocated the reviewed claim source and moved no '
    . 'identity, which is still measured here against pre-relocation numbers on every other adapter'
);
duo_check_same(
    SPLIT_REVIEWED_MANIFEST_HASH,
    ArtifactPolicyIdentity::manifest_hash($shippedPolicy),
    'and manifest_hash over all 16 pins — the number a compiled artifact binds — moved only with the four '
    . 'reviewed manifests: a 16-pin site recompiles for #561/Polylang/PMPro, not for the split'
);
duo_check(
    SPLIT_REVIEWED_MANIFEST_HASH !== SPLIT_FROZEN_MANIFEST_HASH
        && SPLIT_REVIEWED_REGISTRY_SHA !== SPLIT_FROZEN_REGISTRY_SHA
        && SPLIT_REVIEWED_SNAPSHOT_SHA !== SPLIT_FROZEN_SNAPSHOT_SHA,
    '...and all three re-pinned numbers really differ from their frozen originals, so the three assertions '
    . 'around them are re-pins a reviewer must read rather than restatements of the frozen constants'
);
duo_check_same(
    SPLIT_REVIEWED_REGISTRY_SHA,
    $shippedRegistry->sha256(),
    'and registry_sha256, the content address a host contract pins, reassembles from the per-subject documents '
    . 'to exactly one document — carrying #561\'s TEC promotion and Polylang\'s reviewed certification, not the '
    . 'split itself (WP-4.5 is the rider that narrows this to per-subject addressing)'
);
duo_check_same(
    SPLIT_REVIEWED_SNAPSHOT_SHA,
    hash('sha256', Canon::encode($shippedPolicy->export_snapshot())),
    'and the frozen policy snapshot — which carries the whole registry as `dispositions` — moves with the '
    . 'reviewed claims it embeds and with nothing else, so the split alone never invalidated a compiled artifact'
);
// The relocation must also be invisible in the other direction: bytes frozen
// before it still reconstruct a policy, through the validator that reads them.
// Taken over a ONE-PIN policy because a snapshot's manifest list is checked
// against the site's own pins (Policy.php:653-655), and the 16-pin policy above
// was loaded without a site file to state them.
$corePolicy = Policy::load(null, ['core']);
duo_check_same(
    ArtifactPolicyIdentity::manifest_hash($corePolicy),
    ArtifactPolicyIdentity::manifest_hash(Policy::from_snapshot($corePolicy->export_snapshot())),
    'and the v6 snapshot round trip reproduces manifest_hash, so from_snapshot() reads the reassembled document the '
    . 'way it read the monolith'
);

// ---------------------------------------------------------------------------
echo "\nPART 2 — THE HAZARDS: each canonical-encoding difference the split could have introduced\n";
// ---------------------------------------------------------------------------
// A census of the shipped source first, because it says which hazards these
// bytes could even express. Both numbers are tripwires: a reviewed entry that
// starts carrying a number or a non-ASCII reason lands in a suite that already
// measures what that would cost.
$numberMembers = [];
$nonAsciiMembers = [];
$documentCount = 0;
$walk = static function ($value, string $path) use (&$walk, &$numberMembers, &$nonAsciiMembers): void {
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $walk($child, $path . '/' . $key);
        }
        return;
    }
    if (is_int($value) || is_float($value)) {
        $numberMembers[] = $path;
        return;
    }
    if (is_string($value) && preg_match('/[^\x20-\x7E\t\n]/', $value) === 1) {
        $nonAsciiMembers[] = $path;
    }
};
foreach (glob($subjectDir . '/*.json') ?: [] as $document) {
    $documentCount++;
    $walk(Canon::decode(Canon::read_file($document)), basename($document, '.json'));
}
duo_check_same(17, $documentCount, 'the reviewed source is 17 documents: 16 subjects and the profiles map');
duo_check_same(
    [],
    $numberMembers,
    'no shipped reviewed member is a number, so the int/float hazard below cannot arise from these bytes — but a '
    . 'reviewed entry that starts carrying one arrives in a suite that measures what it costs'
);
duo_check_same(
    [],
    $nonAsciiMembers,
    'and no shipped reason carries a non-ASCII character, so the UTF-8 composition hazard cannot arise from them '
    . 'either'
);

/**
 * A throwaway one-adapter library, digest-capable through the real product
 * path: a manifest, the shipped platform boundary verbatim (a claim cannot be
 * projected without one, and re-authoring it would describe a runtime nobody
 * runs), and one reviewed document.
 *
 * @param array<string,mixed> $manifest
 * @param array<string,mixed> $entry
 */
$probeLibrary = static function (string $label, array $manifest, array $entry) use ($scratchRoot, $manifestDir): string {
    $dir = $scratchRoot . '/' . $label . '/manifests';
    if (!is_dir($dir . '/capabilities') && !mkdir($dir . '/capabilities', 0777, true)) {
        throw new RuntimeException("cannot create the probe library at $dir");
    }
    if (!is_dir($dir . '/dispositions') && !mkdir($dir . '/dispositions', 0777, true)) {
        throw new RuntimeException("cannot create the probe disposition directory at $dir");
    }
    Canon::write_file($dir . '/' . $manifest['name'] . '.json', Canon::encode($manifest));
    Canon::write_file($dir . '/dispositions/' . $manifest['name'] . '.json', Canon::encode($entry));
    copy($manifestDir . '/capabilities/platform.json', $dir . '/capabilities/platform.json');
    return $dir;
};

/** That library's one adapter digest, taken exactly as a repository pin takes it. */
$probeDigest = static function (string $dir, string $name): string {
    putenv("DUO_MANIFESTS_DIR=$dir");
    $rows = ArtifactPolicyIdentity::resolved_adapters(Policy::load(null, [$name]));
    return (string) $rows[0]['digest'];
};

$probeManifest = [
    'name' => 'split-probe',
    'option_autoload' => 'preserve',
    'options' => ['split_probe_layout' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
];
$probeEntry = [
    'capabilities' => [
        'deletion_semantics' => ['supported' => [], 'unsupported' => ['nothing is deletable here']],
        'entity_sections' => [],
        'field_sections' => ['options'],
        // A nested LIST, which is where element order is load-bearing.
        'lifecycle_phases' => ['retire', 'activate', 'verify'],
        'operations' => ['apply', 'capture'],
    ],
    'default_authored_keyspaces' => [],
    'reason' => 'Synthetic subject for the canonical-encoding hazard cases.',
    'status' => 'experimental',
    'supported_versions' => ['plugin' => 'split-probe/split-probe.php', 'range' => ['max' => '2.0.0', 'min' => '1.0.0']],
    'unsupported' => [[
        'operation' => 'delete',
        'reason' => 'the probe owns options only',
        'surface' => 'deletions.*',
    ]],
];
$baseline = $probeDigest($probeLibrary('baseline', $probeManifest, $probeEntry), 'split-probe');

// HAZARD 0 — the one difference that is NOT a hazard, and the reason the whole
// relocation is admissible: Canon ksorts every map at every nesting level, so a
// document authored with its keys in any order encodes identically. Measured
// with the key order reversed at every level, which is the strongest form of
// the claim a splitter could have needed.
$reverseKeys = static function ($value) use (&$reverseKeys) {
    if (!is_array($value) || array_is_list($value)) {
        // Lists are left alone deliberately: their order is the SEPARATE
        // hazard measured below, and reversing both at once would not say
        // which of the two moved the digest.
        return $value;
    }
    $out = [];
    foreach (array_reverse($value, true) as $key => $child) {
        $out[$key] = $reverseKeys($child);
    }
    return $out;
};
$keyReversedEntry = $reverseKeys($probeEntry);
duo_check_same(
    $baseline,
    $probeDigest($probeLibrary('key-order', $probeManifest, $keyReversedEntry), 'split-probe'),
    'MAP KEY ORDER is neutral at every nesting level (Canon.php:44,58) — which is exactly why one entry may be '
    . 'lifted out of a document and written as its own root without moving a digest'
);

// HAZARD 1 — nested LIST order. Canon leaves lists alone, deliberately (a
// consumer may read a value's order: Canon.php:15-36). A splitter that
// normalised `lifecycle_phases` while it was normalising key order would have
// moved this digest, and PART 1 would have named the adapter.
$listReorderedEntry = $probeEntry;
$listReorderedEntry['capabilities']['lifecycle_phases'] = array_reverse(
    $probeEntry['capabilities']['lifecycle_phases']
);
duo_check(
    $probeDigest($probeLibrary('list-order', $probeManifest, $listReorderedEntry), 'split-probe') !== $baseline,
    'HAZARD nested list order: reversing `capabilities.lifecycle_phases` MOVES the digest, so PART 1 would catch a '
    . 'splitter that re-ordered a list'
);

// HAZARD 2 — int/float round trip, and THE ONE HAZARD THE DIGEST DOES NOT
// CATCH. The split rewrote every entry through Canon::decode/encode, and that
// round trip is lossy for numbers in two directions, measured here rather than
// assumed:
//
//   - `3` and `3.0` are different PHP types and the SAME canonical bytes:
//     Canon::encode() does not pass JSON_PRESERVE_ZERO_FRACTION
//     (Canon.php:95-98), so a whole float re-encodes as an integer;
//   - a value past PHP's integer precision decodes to a float, so
//     10000000000000000001 and …002 are ONE value by the time a digest sees
//     them, and both re-encode as `1.0e+19` — bytes that are not what either
//     author wrote.
//
// So the guard against this hazard is not the digest. It is the census above:
// no shipped reviewed member is a number at all, which is why the rewrite was
// safe, and why that assertion is a tripwire rather than trivia.
$wholeFloat = Canon::encode(['reviewed_revision' => 3]) === Canon::encode(['reviewed_revision' => 3.0]);
$pastPrecision = Canon::encode(Canon::decode('{"reviewed_revision":10000000000000000001}'))
    === Canon::encode(Canon::decode('{"reviewed_revision":10000000000000000002}'));
duo_check(
    $wholeFloat && $pastPrecision,
    'HAZARD int/float is the one the digest CANNOT catch: Canon erases the int/float distinction for a whole '
    . 'number, and two distinct integers past PHP precision collide on one float — which is exactly why the census '
    . 'above asserts the reviewed source carries no number at all'
);

// HAZARD 3 — UTF-8 composition across the prose reasons. `reason` is free text
// a human wrote, and it is folded into the digest verbatim. Two spellings of
// the same visible character — precomposed U+00E9 and e + U+0301 — are equal on
// screen, different on the wire, and a tool that normalised one to the other
// while rewriting 16 files would move exactly the adapters whose reasons carry
// an accent.
$precomposedEntry = $probeEntry;
$precomposedEntry['reason'] = "Certified against the re\u{00E9}dited reviewed boundary.";
$decomposedEntry = $probeEntry;
$decomposedEntry['reason'] = "Certified against the ree\u{0301}dited reviewed boundary.";
duo_check(
    $precomposedEntry['reason'] !== $decomposedEntry['reason']
        && $probeDigest($probeLibrary('nfc', $probeManifest, $precomposedEntry), 'split-probe')
            !== $probeDigest($probeLibrary('nfd', $probeManifest, $decomposedEntry), 'split-probe'),
    'HAZARD UTF-8 composition: two spellings of one visible character in a prose `reason` MOVE the digest, so a '
    . 'normalising rewrite of the reviewed prose would be caught adapter by adapter'
);

// ---------------------------------------------------------------------------
echo "\nPART 3 — THE REFUSALS: every rule fires from the split form, in its own wording\n";
// ---------------------------------------------------------------------------
$coreManifest = Canon::decode(Canon::read_file($manifestDir . '/core.json'));
$coreEntry = Canon::decode(Canon::read_file($subjectDir . '/core.json'));

/** A one-subject library whose reviewed documents are written by $publish. */
$reviewedLibrary = static function (string $label, callable $publish) use ($scratchRoot, $manifestDir): string {
    $dir = $scratchRoot . '/' . $label . '/manifests';
    if (!is_dir($dir . '/capabilities') && !mkdir($dir . '/capabilities', 0777, true)) {
        throw new RuntimeException("cannot create the probe library at $dir");
    }
    if (!is_dir($dir . '/dispositions') && !mkdir($dir . '/dispositions', 0777, true)) {
        throw new RuntimeException("cannot create the probe disposition directory at $dir");
    }
    copy($manifestDir . '/core.json', $dir . '/core.json');
    copy($manifestDir . '/capabilities/platform.json', $dir . '/capabilities/platform.json');
    $publish($dir . '/dispositions');
    return $dir;
};

/** The refusal SENTENCE, whole: a wording that IS the contract (AGENTS.md rule 8). */
$refusal = static function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '<no refusal thrown>';
};

// THE COVERAGE REFUSAL, byte for byte. This is the sentence the monolith
// produced for a pinned manifest with no reviewed entry, and it is quoted
// verbatim in docs/guides/adapter-authoring.md, so the split had to reach it
// through a MISSING FILE rather than a missing key without changing a
// character.
$noDocument = $reviewedLibrary('missing-document', static function (string $dir): void {
    // Nothing published: `core` is pinned and has no document.
    if (!is_dir($dir)) {
        throw new RuntimeException('the probe disposition directory was not created');
    }
});
duo_check_same(
    'duo: manifest disposition coverage mismatch; missing=[core], extra=[]',
    $refusal(static function () use ($noDocument, $coreManifest): void {
        ManifestDispositions::load($noDocument)?->assert_covers([$coreManifest]);
    }),
    'a document missing for a PINNED subject refuses by name, in the monolith coverage sentence to the byte'
);
duo_check_same(
    "duo: manifest disposition 'core' must be an object",
    $refusal(static function () use ($reviewedLibrary, $coreManifest): void {
        $dir = $reviewedLibrary('null-document', static function (string $dir): void {
            Canon::write_file($dir . '/core.json', "null\n");
        });
        ManifestDispositions::load($dir)?->assert_covers([$coreManifest]);
    }),
    'and a document that EXISTS and decodes to null is present-and-malformed, not missing — the distinction '
    . 'assert_covers() kept when a key became a file'
);

// The per-entry rules, each reached through the same public entry point a site
// reaches them through. Every wording here predates the split.
$entryRules = [
    'a status outside the reviewed vocabulary' => [
        static function (array $entry): array {
            $entry['status'] = 'ratified';
            return $entry;
        },
        "duo: manifest disposition 'core' has a malformed required field",
    ],
    'the synthesized runtime status, DECLARED' => [
        static function (array $entry): array {
            $entry['status'] = ManifestDispositions::STATUS_UNCOVERED;
            return $entry;
        },
        "duo: manifest disposition 'core' has a malformed required field",
    ],
    'capabilities with a key the closed set does not carry' => [
        static function (array $entry): array {
            $entry['capabilities']['invented'] = [];
            return $entry;
        },
        "duo: manifest disposition 'core' capabilities are malformed",
    ],
    'deletion semantics missing an arm' => [
        static function (array $entry): array {
            unset($entry['capabilities']['deletion_semantics']['unsupported']);
            return $entry;
        },
        "duo: manifest disposition 'core' deletion semantics are malformed",
    ],
    'a declared section the manifest does not carry' => [
        static function (array $entry): array {
            $entry['capabilities']['field_sections'][] = 'invented_section';
            return $entry;
        },
        "duo: manifest disposition 'core' names absent manifest section 'invented_section'",
    ],
    'an unsupported row with no reason' => [
        static function (array $entry): array {
            $entry['unsupported'][0]['reason'] = '';
            return $entry;
        },
        "duo: manifest disposition 'core' unsupported[0] is malformed",
    ],
    'default-authored evidence for a keyspace the manifest never declares' => [
        static function (array $entry): array {
            $entry['default_authored_keyspaces'][] = [
                'reason' => 'synthetic evidence for a table core does not declare',
                'status' => 'justified',
                'table' => 'not_a_core_table',
            ];
            return $entry;
        },
        "duo: manifest disposition 'core' names non-default-authored keyspace 'not_a_core_table'",
    ],
    'a certified claim citing nothing' => [
        static function (array $entry): array {
            unset($entry['evidence']);
            return $entry;
        },
        "duo: certified manifest disposition 'core' lacks current bundle evidence",
    ],
];
foreach ($entryRules as $label => [$edit, $expected]) {
    $dir = $reviewedLibrary(
        'entry-rule-' . substr(hash('sha256', (string) $label), 0, 8),
        static function (string $documents) use ($edit, $coreEntry): void {
            Canon::write_file($documents . '/core.json', Canon::encode($edit($coreEntry)));
        }
    );
    $message = $refusal(static function () use ($dir, $coreManifest): void {
        ManifestDispositions::load($dir)?->assert_covers([$coreManifest]);
    });
    duo_check(
        str_contains($message, $expected),
        "per-entry rule fires from the split form, unchanged: $label ($message)"
    );
}

// The profile rules resolve against the SUBJECTS THE DIRECTORY DECLARES, which
// is the listing — the split's replacement for the monolith's `manifests` keys.
$badProfile = $reviewedLibrary('bad-profile', static function (string $documents) use ($coreEntry): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
    Canon::write_file($documents . '/profiles.json', Canon::encode([
        'orphan' => [
            'evidence' => ['bundle_schema' => ManifestDispositions::EVIDENCE_SCHEMA, 'tests' => ['conformance-core']],
            'manifest' => 'not-a-reviewed-subject',
            'reason' => 'names a subject this library does not review',
            'scope' => [],
            'status' => 'certified',
            'supported_versions' => ['range' => ['max' => '8.0', 'min' => '6.0']],
        ],
    ]));
});
duo_check_same(
    "duo: manifest disposition profile 'orphan' is malformed",
    $refusal(static function () use ($badProfile): void {
        ManifestDispositions::load($badProfile);
    }),
    'a profile naming a subject the directory does not declare refuses at load, in the profile rule wording'
);
$goodProfile = $reviewedLibrary('good-profile', static function (string $documents) use ($coreEntry, $repo): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
    copy($repo . '/manifests/dispositions/profiles.json', $documents . '/profiles.json');
});
duo_check_same(
    ['fse'],
    array_keys(ManifestDispositions::load($goodProfile)?->profiles() ?? []),
    'and the shipped profiles document resolves against a directory that declares its subject'
);

// The frozen root rule keeps its one live reader: a snapshot is still the WHOLE
// document, so validate_root() still refuses a malformed one.
duo_check(
    str_contains(
        $refusal(static function () use ($coreManifest): void {
            ManifestDispositions::from_snapshot(['format' => ManifestDispositions::FORMAT], [$coreManifest]);
        }),
        'frozen manifest disposition registry must contain exactly format, manifests, and profiles'
    ),
    'the root rule survives on the frozen path, which is where a root still exists'
);

// The three rules the DIRECTORY adds. A namespace can carry mistakes a key set
// could not, and each of them is inert bytes an operator believes in — the
// failure mode AdapterSources refuses everywhere else (:868-873).
$strayName = $reviewedLibrary('stray-name', static function (string $documents) use ($coreEntry): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
    // Numeric-only, so it fails the grammar's mandatory-lowercase rule and no
    // case-insensitive filesystem can fold it onto `core.json`.
    Canon::write_file($documents . '/2024.json', Canon::encode($coreEntry));
    // A profiles document forces the listing, which is where the name grammar
    // is enforced; without a profile there is nothing to resolve and no listing.
    Canon::write_file($documents . '/profiles.json', Canon::encode([]));
});
duo_check(
    str_contains(
        $refusal(static function () use ($strayName): void {
            ManifestDispositions::load($strayName)?->data();
        }),
        "is not named for a canonical adapter slug"
    ),
    'a document not named for a canonical adapter slug refuses rather than being skipped — reviewed bytes with no '
    . 'subject are the authoring half of the coverage rule'
);
duo_check(
    str_contains(
        $refusal(static function () use ($goodProfile): void {
            ManifestDispositions::load($goodProfile)?->entry('profiles');
        }),
        "is the reserved name of the profiles document"
    ),
    "'profiles' is reserved: an adapter of that name would be its own profiles map, so it refuses by name"
);
$staleMonolith = $reviewedLibrary('stale-monolith', static function (string $documents) use ($coreEntry): void {
    Canon::write_file($documents . '/core.json', Canon::encode($coreEntry));
});
Canon::write_file($staleMonolith . '/dispositions.json', Canon::encode([
    'format' => ManifestDispositions::FORMAT,
    'manifests' => ['core' => $coreEntry],
    'profiles' => [],
]));
duo_check(
    str_contains(
        $refusal(static function () use ($staleMonolith): void {
            ManifestDispositions::load($staleMonolith);
        }),
        'still carries the pre-split dispositions.json'
    ),
    'and a stale monolith beside the directory refuses the load: ratification bytes nothing reads are exactly what '
    . 'this library refuses everywhere else'
);

putenv('DUO_MANIFESTS_DIR=' . $manifestDir);
duo_check_summary('disposition split');
