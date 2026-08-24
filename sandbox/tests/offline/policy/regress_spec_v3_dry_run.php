<?php
/**
 * The v3 static dry-run: what each candidate spec-v3 rule would refuse TODAY,
 * measured against the whole shipped library and a fixture estate, before any
 * rule is enabled.
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * The flag day (DUO_SPEC_VERSION 2 -> 3) turns on several rules at once, and
 * the failure mode nobody can recover from is discovering the in-repo break
 * list AFTER the defines move: `manifests/capabilities/platform.json` restates
 * both defines (AGENTS.md rule 8), so the bump is one atomic edit that every
 * deployed site sees. This suite produces that break list now, from the tree,
 * as ordinary offline evidence.
 *
 * WHAT KEEPS IT FROM ROTTING
 * --------------------------
 * Every candidate rule reads its inputs from the SHIPPED CONSTANTS the
 * eventual v3 code will consult, never from a list retyped here:
 *
 *   - the closed top-level key set is `AdapterCertification`'s own
 *     ENTITY_SECTIONS (5) + FIELD_SECTIONS (14) + NON_SURFACE_KEYS (13, since
 *     WP-4.6 added `environment`) partition, read by Reflection because they are private and
 *     private is the point — they are what the signer already enforces by name
 *     in `siteRatification()`'s classify-or-throw loop (:700-713), and a copy
 *     here would be the second definition the register discipline exists to
 *     forbid;
 *   - the "would refuse" verdict for that rule is not simulated: it is the
 *     REAL refusal, obtained by invoking `siteRatification()` on the fixture,
 *     so its wording is the shipped wording;
 *   - the disposition entry set comes from `ManifestDispositions::load()`, the
 *     platform axes from `ManifestDispositions::platform_boundary()`, and the
 *     name grammar from `AdapterSources::assert_name()`;
 *   - where a candidate rule is already RULED ON rather than merely unmeasured,
 *     the verdict is read out of WP-0.5's irreversibility register
 *     (`docs/wire-surface.md`, generated and byte-checked by
 *     `tools/wire-surface.php` under `make release-gate`) instead of argued
 *     here. R-17 is the case that matters: `id_kind` prefixing can never become
 *     a rule, because captured state and `duo_map` rows embed the bare kind, so
 *     V3-NS's 18 shipped id_kinds are a permanent floor and not a break list.
 *
 * So the durable asset is the FIXTURE ESTATE plus the measurements, not the
 * prediction. When WP-4.3 makes `ManifestValidator` consult the same partition,
 * these same fixtures flip from "the signer would refuse, the validator admits"
 * to "both refuse", and exactly one assertion in this file moves: the one that
 * pins how many shipped call sites read the partition today (measured: one).
 *
 * THE ESTATE
 * ----------
 * 16 shipped manifests + 7 constructed fixtures + the 3 on-disk synthetic
 * manifests, the last DISCOVERED by shape (string `name`, int `spec_version`,
 * a `plugin` or `theme` subject) under `sandbox/`, minus gitignored scratch.
 * Discovery rather than a list because `sandbox/fixtures/acme-catalog/` is the
 * tree's only out-of-tree adapter and out-of-tree adapters are the population
 * the flag day actually hits: a fourth fixture must be measured here, not
 * silently missed, so the estate assertion names all three and fails on a new
 * one.
 *
 * THE MEASUREMENT THIS SUITE OWES ITS CALLER
 * ------------------------------------------
 * WP-1.6 requires the union of top-level keys actually in use across
 * `manifests/*.json` to be MEASURED against the 32-key signer partition and
 * the difference ENUMERATED, never assumed. It is measured below and the
 * difference is three findings, each asserted rather than reconciled:
 *
 *   F1  30 of the 32 partition keys are in use across the 16 shipped
 *       manifests, and the union contains NOTHING the partition does not know.
 *       So rule V3-KEYS would refuse zero shipped adapters — the flag day is
 *       clean for this rule, which is the fact the program was assuming. The
 *       32nd key is WP-4.6's `environment` narrowing channel, admitted in the
 *       change that reads it (§ v3.5) and declared by none of the 16.
 *   F2  The other unused partition key is `theme`, and it is UNUSABLE as
 *       shipped: `AdapterContractGrammar::validate_adapter_contract()` (:45)
 *       demands a `theme_version_range` beside every `theme`, and
 *       `ArtifactPolicyIdentity` folds that key into the adapter identity row
 *       (:152-153) — yet `theme_version_range` is in NO arm of the partition.
 *       A theme adapter therefore validates and is then unsignable. The signer
 *       partition is incomplete against the shipped grammar by exactly one key.
 *   F3  A live in-tree producer emits a top-level key the partition does not
 *       know: `cli/src/Adapter/AdapterDraft.php:379` writes `_draft` into the
 *       manifest it hands the author. `duo adapter-draft` output is therefore
 *       unsignable today and would be refused by rule V3-KEYS on the flag day.
 *
 * F2 and F3 are reported, not fixed here: teaching the signer a key changes
 * what a certificate covers, which is a reviewed decision with its own work
 * package (AGENTS.md, "out-of-scope discoveries are reported ... never fixed
 * silently").
 */
declare(strict_types=1);

if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', '0.5.0');
}
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterCertification.php';
require_once __DIR__ . '/manifest_fixtures.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\ManifestValidator;
use Duo\Policy;

$repo = dirname(__DIR__, 4);
$manifestDir = $repo . '/manifests';

/** One indented report row. Indented so it can never look like a PHP diagnostic to the guard. */
$report = static function (string $line): void {
    echo '  ' . $line . "\n";
};

/**
 * Every shipped PHP file naming any of these tokens, repo-relative and sorted.
 *
 * The three trees are exactly what `Adopt.php:147-150` tars to a managed site,
 * so "no reader" measured over them means no reader a deployment can have. A
 * source scan and not a Reflection walk because the question is which FILES
 * would have to change on the flag day, which the loaded class graph cannot
 * answer.
 *
 * @param list<string> $tokens
 * @return list<string>
 */
$shippedFilesNaming = static function (array $tokens) use ($repo): array {
    $hits = [];
    foreach (['agent/src', 'cli/src', 'recovery'] as $tree) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repo . '/' . $tree, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            foreach ($tokens as $token) {
                if (str_contains($body, $token)) {
                    $hits[substr($file->getPathname(), strlen($repo) + 1)] = true;
                    break;
                }
            }
        }
    }
    $hits = array_keys($hits);
    sort($hits, SORT_STRING);
    return $hits;
};

// ---------------------------------------------------------------------------
// The candidate rule inputs, read out of the engine rather than retyped.
// ---------------------------------------------------------------------------

$cert = new ReflectionClass(AdapterCertification::class);
$entitySections = (array) $cert->getConstant('ENTITY_SECTIONS');
$fieldSections = (array) $cert->getConstant('FIELD_SECTIONS');
$nonSurfaceKeys = (array) $cert->getConstant('NON_SURFACE_KEYS');
$closedSet = array_merge($entitySections, $fieldSections, $nonSurfaceKeys);
sort($closedSet, SORT_STRING);

// 13 non-surface keys, not 12, since WP-4.6: `environment` joined that arm with
// the narrowing rule that reads it (§ v3.5), because a top-level key in no arm
// makes its whole adapter unsignable — the same shape F2 measures below for
// `theme_version_range`. This is the growth rule of § v3.3 exercised once, and
// it is pinned here so the next arrival is a reviewed edit rather than a drift.
duo_check_same(
    [5, 14, 13],
    [count($entitySections), count($fieldSections), count($nonSurfaceKeys)],
    'the candidate closed key set is the signer partition, read by Reflection: 5 entity + 14 field + 13 non-surface'
);
duo_check_same(
    32,
    count(array_unique($closedSet)),
    'the three arms are disjoint, so the candidate set is exactly 32 keys'
);

// `siteRatification()` is private and stays private: this suite must exercise
// the REAL refusal, not a lookalike, or the wording it reports on the flag day
// is this file's wording rather than the engine's. No setAccessible() call —
// it has had no effect since PHP 8.1 and emits a deprecation under 8.5, and a
// deprecation is not green.
$ratify = $cert->getMethod('siteRatification');

/** null = the signer would classify every key; a string = the shipped refusal, verbatim. */
$signerVerdict = static function (string $name, array $manifest) use ($ratify): ?string {
    try {
        $ratify->invoke(null, $name, $manifest, 'v3 dry run');
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

// The vocabulary the validator gets on the production path, taken from the one
// producer of it — `Policy::manifest_validator_vocabulary()` (Policy.php:323),
// the private method `Policy::load()` calls at :448 and :531. Reflected rather
// than reassembled from the published `closed_vocabularies()` projection: a
// hand-mapping between the two key spellings is a second definition, and this
// suite's whole claim is that its rule inputs have exactly one.
$vocabulary = (array) (new ReflectionMethod(Policy::class, 'manifest_validator_vocabulary'))->invoke(null);
duo_check_same(
    ['casts', 'classes', 'derivable_field_columns', 'field_classes', 'menu_derivable_fields', 'menu_field_classes', 'missing_user_modes'],
    (static function (array $v): array {
        $keys = array_keys($v);
        sort($keys, SORT_STRING);
        return $keys;
    })($vocabulary),
    'the validator vocabulary is the shipped one Policy::load() passes, reflected out of Policy rather than reassembled here'
);

/** null = today's shipped validator pipeline admits the manifest; a string = its refusal. */
$validatorVerdict = static function (array $manifest) use ($vocabulary): ?string {
    try {
        ManifestValidator::validate_manifest($manifest, "manifest '" . ($manifest['name'] ?? '?') . "'", $vocabulary);
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

// ---------------------------------------------------------------------------
// The estate: 16 shipped manifests + the representative fixtures.
// ---------------------------------------------------------------------------

$shipped = [];
foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name === 'dispositions') {
        continue;
    }
    $shipped[$name] = Canon::decode(Canon::read_file($file));
}
ksort($shipped, SORT_STRING);

duo_check_same(16, count($shipped), 'the shipped library under test is all 16 adapter manifests');

// Every fixture is a shape a candidate rule has an opinion about. manifest_a()
// and manifest_b() are the corpus-wide pair (rule 5): a rule that refuses THEM
// refuses the shape every other offline suite calls well-formed.
$themeAdapter = [
    'name' => 'acme-theme',
    'spec_version' => DUO_SPEC_VERSION,
    'theme' => 'acme',
    'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'options' => ['acme_theme_setting' => ['class' => 'authored', 'autoload' => 'yes']],
];
$fixtures = [
    'fixture:manifest-a' => manifest_a(),
    'fixture:manifest-b' => manifest_b(),
    // WP-4.3's named case: a section nobody declared and one transposed letter
    // in `options`. Both are inert today — the whole plugin's authored rows
    // simply never reach capture, with no diagnostic anywhere.
    'fixture:typo-and-invented-section' => manifest_a([
        'name' => 'typo',
        'totally_made_up_section' => ['acme_thing' => ['class' => 'authored']],
        'optoins' => ['acme_a_setting' => ['class' => 'authored', 'autoload' => 'yes']],
    ]),
    'fixture:theme-adapter' => $themeAdapter,
    // What `duo adapter-draft` actually hands an author (AdapterDraft.php:379).
    'fixture:adapter-draft-output' => manifest_a([
        'name' => 'drafted',
        '_draft' => ['proposals' => [], 'evidence' => []],
    ]),
    'fixture:engine-features' => manifest_a([
        'name' => 'featureful',
        'engine_features' => ['spec-window/v1'],
    ]),
    'fixture:vendor-prefixed' => manifest_a(['name' => 'acme-widgets']),
];

// The on-disk synthetic manifests are the other half of "every synthetic
// manifest fixture", and they matter more than the constructed ones: an
// out-of-tree adapter authored against v2 is the population the flag day
// actually hits, and `sandbox/fixtures/acme-catalog/duo-adapter.json` is the
// only one in the tree. DISCOVERED, not listed, by the shape that makes a JSON
// document an adapter manifest — a string `name`, an int `spec_version`, and a
// `plugin` or `theme` subject — so a fixture added later is measured instead of
// quietly missed. The two gitignored scratch roots (.gitignore:3-4,
// `sandbox/siterepo/` and `sandbox/tmp/`) are skipped, and skipping siterepo is
// not tidiness: a pair run leaves a whole site repository there, adapters
// included, so a walk that read it would give a different estate on a machine
// that has run `pair.sh` than on one that has not.
$scratchRoots = ['sandbox/siterepo/', 'sandbox/tmp/'];
$discovered = [];
$walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo . '/sandbox', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);
foreach ($walk as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'json') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($repo) + 1);
    foreach ($scratchRoots as $scratchRoot) {
        if (str_starts_with($relative, $scratchRoot)) {
            continue 2;
        }
    }
    $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
    if (!is_array($decoded)
        || !is_string($decoded['name'] ?? null)
        || !is_int($decoded['spec_version'] ?? null)
        || (!array_key_exists('plugin', $decoded) && !array_key_exists('theme', $decoded))) {
        continue;
    }
    $discovered[$relative] = $decoded;
}
ksort($discovered, SORT_STRING);

duo_check_same(
    [
        'sandbox/fixtures/acme-catalog/duo-adapter.json',
        'sandbox/tests/fixtures/duo-sidecar-refs/manifest.json',
        'sandbox/tests/fixtures/duo-taxonomy-keyspace/manifest.json',
    ],
    array_keys($discovered),
    'the on-disk synthetic manifest estate is three documents; a fourth must be considered by this dry run, not silently added'
);

$estate = [];
foreach ($shipped as $name => $manifest) {
    $estate['shipped:' . $name] = [$name, $manifest];
}
foreach ($fixtures as $label => $manifest) {
    $estate[$label] = [(string) ($manifest['name'] ?? '?'), $manifest];
}
foreach ($discovered as $path => $manifest) {
    $estate['synthetic:' . basename(dirname($path))] = [(string) $manifest['name'], $manifest];
}

// ===========================================================================
// RULE V3-KEYS — a closed top-level key set (flag-day rider R3 / WP-4.3)
// ===========================================================================

echo "\nRULE V3-KEYS: closed top-level manifest key set, single-sourced with the signer partition\n";

// The measurement WP-1.6 owes: the union in use, against the 31.
$union = [];
foreach ($shipped as $name => $manifest) {
    foreach (array_keys($manifest) as $key) {
        $union[(string) $key][] = $name;
    }
}
ksort($union, SORT_STRING);
$unionKeys = array_keys($union);

$unknownInUse = array_values(array_diff($unionKeys, $closedSet));
$knownUnused = array_values(array_diff($closedSet, $unionKeys));

$report(sprintf(
    'in-use top-level keys across the 16 shipped manifests: %d; signer partition: %d',
    count($unionKeys),
    count($closedSet)
));
foreach ($unionKeys as $key) {
    $report(sprintf('  %-20s declared by %2d/16%s', $key, count($union[$key]), in_array($key, $closedSet, true) ? '' : '   <-- UNKNOWN TO THE SIGNER'));
}
$report('partition keys no shipped manifest declares: ' . ($knownUnused === [] ? '(none)' : implode(', ', $knownUnused)));

// F1 — the difference, enumerated in both directions.
duo_check_same([], $unknownInUse, 'F1: no shipped manifest declares a top-level key the signer partition does not know');
duo_check_same(30, count($unionKeys), 'F1: the in-use union is 30 keys');
// Two keys the partition admits and no shipped adapter declares, and they are
// there for opposite reasons: `theme` predates the library's plugin-only
// contents, while `environment` is WP-4.6's narrowing channel — admitted in the
// change that reads it precisely so an adapter that uses it stays signable
// (rule V3-AXIS below measures that none of the 16 uses it yet).
duo_check_same(
    ['environment', 'theme'],
    $knownUnused,
    'F1: the partition/union difference is exactly two keys — `environment` and `theme`, declared by no shipped adapter'
);

// The same measurement over the on-disk synthetic estate, because the flag day
// hits out-of-tree adapters first and `acme-catalog` is the only one the tree
// carries. Measured separately: an unknown key here would be a fixture to fix,
// not a finding against the library.
$syntheticUnion = [];
foreach ($discovered as $path => $manifest) {
    foreach (array_keys($manifest) as $key) {
        $syntheticUnion[(string) $key][] = $path;
    }
}
ksort($syntheticUnion, SORT_STRING);
$syntheticUnknown = array_values(array_diff(array_keys($syntheticUnion), $closedSet));
$report(sprintf(
    'in-use top-level keys across the %d on-disk synthetic manifests: %d, of which %d unknown to the signer',
    count($discovered),
    count($syntheticUnion),
    count($syntheticUnknown)
));
duo_check_same([], $syntheticUnknown, 'F1: the on-disk synthetic manifests declare no top-level key the signer partition does not know either');

// The flag-day break list for this rule.
$keysBreakList = [];
foreach ($estate as $label => [$name, $manifest]) {
    $verdict = $signerVerdict($name, $manifest);
    if ($verdict !== null) {
        $keysBreakList[$label] = $verdict;
    }
}
$report('would-refuse under V3-KEYS: ' . count($keysBreakList) . ' of ' . count($estate) . ' estate members');
foreach ($keysBreakList as $label => $verdict) {
    $report('  ' . $label . ' -> ' . $verdict);
}

$shippedBreaks = array_values(array_filter(array_keys($keysBreakList), static fn(string $l): bool => str_starts_with($l, 'shipped:')));
duo_check_same([], $shippedBreaks, 'V3-KEYS refuses zero shipped adapters: the closed key set is digest-neutral for the library');
$syntheticBreaks = array_values(array_filter(array_keys($keysBreakList), static fn(string $l): bool => str_starts_with($l, 'synthetic:')));
duo_check_same([], $syntheticBreaks, 'V3-KEYS refuses none of the on-disk synthetic manifests either — including acme-catalog, the tree\'s only out-of-tree adapter');
duo_check_same(
    [],
    array_values(array_intersect(array_keys($keysBreakList), ['fixture:manifest-a', 'fixture:manifest-b'])),
    'V3-KEYS admits the corpus-wide manifest_a()/manifest_b() pair, so it does not condemn the shape every other suite calls valid'
);

// F2 — `theme` is admitted, its mandatory companion is not.
duo_check(
    in_array('theme', $closedSet, true) && !in_array('theme_version_range', $closedSet, true),
    'F2: the partition knows `theme` but not `theme_version_range`'
);
duo_check_same(
    null,
    $validatorVerdict($themeAdapter),
    'F2: a theme adapter passes the shipped validator pipeline (AdapterContractGrammar accepts theme + theme_version_range)'
);
$themeVerdict = $signerVerdict('acme-theme', $themeAdapter);
duo_check(
    is_string($themeVerdict) && str_contains($themeVerdict, "declares 'theme_version_range'")
        && str_contains($themeVerdict, 'teach the signer this section'),
    'F2: ...and is then unsignable — the signer refuses the companion key the grammar made mandatory'
);
duo_check_detail('F2 refusal: ' . (string) $themeVerdict);

// F3 — a shipped producer emits a key the partition does not know.
$draftSource = (string) file_get_contents($repo . '/cli/src/Adapter/AdapterDraft.php');
duo_check(
    str_contains($draftSource, "\$manifest['_draft'] = self::build_draft("),
    'F3: `duo adapter-draft` writes a top-level `_draft` into the manifest it emits (AdapterDraft.php:379)'
);
duo_check(!in_array('_draft', $closedSet, true), 'F3: `_draft` is in no arm of the signer partition');
$draftVerdict = $signerVerdict('drafted', $fixtures['fixture:adapter-draft-output']);
duo_check(
    is_string($draftVerdict) && str_contains($draftVerdict, "declares '_draft'"),
    'F3: so adapter-draft output is unsignable today, and V3-KEYS would refuse it by name on the flag day'
);
duo_check_detail('F3 refusal: ' . (string) $draftVerdict);

// The seam that flips. Today the partition has exactly one reader in the
// shipped trees — the signer. WP-4.3 adds ManifestValidator/ManifestGrammar as
// the second, and THIS assertion is the single line that must move; the
// fixtures above do not.
$partitionReaders = $shippedFilesNaming(['ENTITY_SECTIONS', 'FIELD_SECTIONS', 'NON_SURFACE_KEYS']);
duo_check_same(
    ['agent/src/Adapter/AdapterCertification.php'],
    $partitionReaders,
    'THE FLIP: the closed key set has exactly one shipped reader today (the signer); WP-4.3 makes the validator the second'
);
duo_check_same(
    null,
    $validatorVerdict($fixtures['fixture:typo-and-invented-section']),
    "WP-4.3's named case: `totally_made_up_section` and a typo'd `optoins` are admitted by the validator pipeline today"
);
$typoVerdict = $signerVerdict('typo', $fixtures['fixture:typo-and-invented-section']);
duo_check(
    is_string($typoVerdict) && str_contains($typoVerdict, 'which this signer cannot classify'),
    '...while the signer already refuses that same manifest by name — the disagreement WP-4.3 closes'
);
duo_check_detail('V3-KEYS typo refusal: ' . (string) $typoVerdict);

// ===========================================================================
// RULE V3-FEAT — the `engine_features` declaration channel (R2 / WP-4.2)
// ===========================================================================

echo "\nRULE V3-FEAT: per-adapter `engine_features`, refused BY NAME when the engine lacks one\n";

$featureReaders = $shippedFilesNaming(['engine_features']);

$featureDeclarers = array_values(array_filter(
    array_keys($shipped),
    static fn(string $n): bool => array_key_exists('engine_features', $shipped[$n])
));

$report('shipped manifests declaring `engine_features`: ' . count($featureDeclarers));
$report('shipped code reading `engine_features`: ' . ($featureReaders === [] ? '(none)' : implode(', ', $featureReaders)));
$report('engine features this engine implements: '
    . implode(', ', \Duo\AdapterContractGrammar::implemented_features()));

duo_check_same([], $featureDeclarers, 'V3-FEAT: no shipped manifest declares `engine_features`, so the channel starts empty and moves no digest');
// THE FLIP (WP-4.2). This suite's header states that a rider landing a rule
// moves the assertion that measured its absence and NOT the fixtures. This is
// that assertion for V3-FEAT: the channel acquired exactly one shipped reader,
// the grammar that owns the vocabulary, and `fixture:engine-features` below is
// unchanged.
duo_check_same(
    ['agent/src/Adapter/AdapterContractGrammar.php'],
    $featureReaders,
    'V3-FEAT: the channel has exactly one shipped reader — the contract grammar, which owns the one definition of a feature name, its first spec_version and the keys it claims'
);
duo_check_same(
    ['spec-window/v1'],
    \Duo\AdapterContractGrammar::implemented_features(),
    'V3-FEAT: and the vocabulary is non-empty, so an engine that lacks a declared name has something to compare against'
);
$featureFixtureVerdict = $validatorVerdict($fixtures['fixture:engine-features']);
duo_check(
    is_string($featureFixtureVerdict) && str_contains($featureFixtureVerdict, "the section 'engine_features'")
        && str_contains($featureFixtureVerdict, 'implements only at spec_version 3'),
    'V3-FEAT: the same fixture that was INERT under the pre-window grammar now refuses BY SECTION NAME at spec_version '
        . DUO_SPEC_VERSION . ' — the silence WP-4.2 replaced'
);
duo_check_detail('V3-FEAT section refusal: ' . (string) $featureFixtureVerdict);

// The coupling WP-4.2 and WP-4.3 must land together or not at all: the channel
// is a new top-level key, and the closed key set does not know it.
duo_check(!in_array('engine_features', $closedSet, true), 'V3-FEAT x V3-KEYS: `engine_features` is in no arm of the partition');
$featureVerdict = $signerVerdict('featureful', $fixtures['fixture:engine-features']);
duo_check(
    is_string($featureVerdict) && str_contains($featureVerdict, "declares 'engine_features'"),
    'V3-FEAT x V3-KEYS: so any adapter using the channel is unsignable unless WP-4.3 teaches the signer the key in the SAME change'
);
duo_check_detail('V3-FEAT refusal: ' . (string) $featureVerdict);

// ===========================================================================
// RULE V3-DISP — per-adapter disposition documents (R4 / WP-4.4)
// ===========================================================================

echo "\nRULE V3-DISP: the dispositions monolith splits into one document per adapter\n";

// Caught, not called bare. `load()` throws "manifest disposition coverage
// mismatch; missing=[...]" (ManifestDispositions.php:70) — the very refusal
// this rule's break list is about — and a dry run whose job is to REPORT who
// would refuse must not itself die of the refusal: an uncaught throw here
// reaches the corpus as a `Fatal error` line, which the offline diagnostics
// guard fails on regardless of exit status (offline_diagnostics_guard.sh:28).
$dispositions = null;
$dispositionsRefusal = null;
try {
    $dispositions = ManifestDispositions::load($manifestDir);
} catch (\Throwable $e) {
    $dispositionsRefusal = $e->getMessage();
}
duo_check(
    $dispositions !== null,
    'V3-DISP: the shipped monolith loads, so the split has a well-formed source'
        . ($dispositionsRefusal === null ? '' : ' — refused: ' . $dispositionsRefusal)
);
$dispositionData = $dispositions === null ? ['manifests' => [], 'profiles' => []] : $dispositions->data();
$entryNames = array_keys($dispositionData['manifests']);
sort($entryNames, SORT_STRING);
$profileNames = array_keys((array) $dispositionData['profiles']);
sort($profileNames, SORT_STRING);

$report('documents the split would create: ' . count($entryNames) . ' adapter + ' . count($profileNames) . ' profile');
duo_check_same(array_keys($shipped), $entryNames, 'V3-DISP: entry set and manifest set already agree, so the split writes one document per shipped adapter and none over');
duo_check_same(['fse'], $profileNames, 'V3-DISP: `profiles` is one row (`fse`) and becomes its own document, as WP-4.4 specifies');

// The split makes each entry KEY a filesystem path, which the monolith never
// did. That is a new constraint, so it is measured rather than assumed.
$unsafeNames = [];
foreach (array_merge($entryNames, $profileNames) as $entryName) {
    try {
        AdapterSources::assert_name((string) $entryName, 'v3 dry run disposition document');
    } catch (\Throwable $e) {
        $unsafeNames[(string) $entryName] = $e->getMessage();
    }
    if (basename((string) $entryName) !== (string) $entryName || $entryName === '') {
        $unsafeNames[(string) $entryName] = 'not a single path segment';
    }
}
duo_check_same([], $unsafeNames, 'V3-DISP: every entry and profile key is a safe single-segment basename, so no name blocks the split');

// Rule 2: the disposition array is folded into the adapter digest, so the move
// must preserve the DECODED array byte-for-byte through Canon. Round-tripping
// each entry proves the encoding is stable under a document boundary change.
$canonUnstable = [];
foreach ($entryNames as $entryName) {
    $entry = $dispositionData['manifests'][$entryName];
    $encoded = Canon::encode($entry);
    if (Canon::encode(Canon::decode($encoded)) !== $encoded) {
        $canonUnstable[] = (string) $entryName;
    }
}
duo_check_same([], $canonUnstable, 'V3-DISP: all 16 entries survive a Canon encode/decode round trip unchanged — the split moves no adapter digest');

// The would-refuse case: a pinned adapter whose document is missing. The
// monolith refuses this by coverage mismatch; the split must keep refusing.
$missingEntry = array_values(array_filter(
    array_keys($shipped),
    static fn(string $n): bool => !array_key_exists($n, $dispositionData['manifests'])
));
duo_check_same([], $missingEntry, 'V3-DISP: zero shipped adapters would be left without a document');
$report('would-refuse under V3-DISP: 0 of ' . count($entryNames) . ' shipped adapters');

// One entry carries no `evidence` member — the excluded regression fixture.
// Named here because the split turns "a member some entries omit" into "a
// document whose shape varies", and a reader of the new layout should know.
$noEvidence = array_values(array_filter(
    $entryNames,
    static fn(string $n): bool => !is_array($dispositionData['manifests'][$n]['evidence'] ?? null)
));
duo_check_same(
    ['duo-agency-cpt'],
    $noEvidence,
    'V3-DISP: exactly one entry omits `evidence` — duo-agency-cpt, the excluded fixture with no product claim'
);

// ===========================================================================
// RULE V3-AXIS — certificates bind exercised axes, adapters narrow (R6/R7)
// ===========================================================================

echo "\nRULE V3-AXIS: per-adapter environment narrowing, and certificates binding axes instead of platform bytes\n";

// Caught for the same reason `load()` is: `platform_boundary()` throws
// "platform version disagrees with the loaded agent" (ManifestDispositions.php
// :145-146) whenever platform.json and the defines part company, and this dry
// run must report that as a break-list row rather than as a Fatal error.
$platform = [];
$platformRefusal = null;
try {
    $platform = ManifestDispositions::platform_boundary($manifestDir);
} catch (\Throwable $e) {
    $platformRefusal = $e->getMessage();
}
duo_check(
    $platformRefusal === null,
    'V3-AXIS: the shipped platform boundary loads against this agent'
        . ($platformRefusal === null ? '' : ' — refused: ' . $platformRefusal)
);
$axes = array_keys((array) ($platform['compatibility'] ?? []));
sort($axes, SORT_STRING);
$report('compatibility axes a v3 certificate would bind: ' . implode(', ', $axes));
// #560 (24ca80aa) added the `process` axis — the WP-CLI child-process bound —
// so the boundary a v3 certificate binds is five axes now. This pin exists so
// a NEW axis is a reviewed sentence here rather than a silent widening of what
// WP-4.7's axis-bound certificates will sign over.
duo_check_same(['database', 'filesystem', 'php', 'process', 'wordpress'], $axes, 'V3-AXIS: the boundary declares five compatibility axes today');

// The measurement WP-4.6 inherited and must not disturb: every SHIPPED claim
// still carries the same environment, because none of the 16 declares the
// narrowing channel WP-4.6 added. Before that rider this was a property of the
// engine (one global copy, no way to narrow); it is now a property of the
// LIBRARY, and that is the whole flag-day claim for § v3.5 — the rule landed
// and moved no shipped claim, digest or certificate by a byte. What narrowing
// does when an adapter DOES declare it is
// sandbox/tests/offline/policy/regress_adapter_environment_narrowing.php.
$distinctEnvironments = [];
$claimRefusals = [];
foreach ($entryNames as $entryName) {
    $entry = $dispositionData['manifests'][$entryName];
    try {
        $claim = ManifestDispositions::claim_from_disposition(
            $shipped[$entryName],
            $entry,
            is_array($entry['evidence'] ?? null) ? $entry['evidence'] : [],
            $platform
        );
    } catch (\Throwable $e) {
        $claimRefusals[(string) $entryName] = $e->getMessage();
        continue;
    }
    $distinctEnvironments[Canon::encode($claim['environment_assumptions'])][] = (string) $entryName;
}
duo_check_same([], array_keys($claimRefusals), 'V3-AXIS: every shipped disposition still projects a claim against the boundary');
duo_check_same(
    1,
    count($distinctEnvironments),
    'V3-AXIS: all 16 claims carry byte-identical `environment_assumptions` — none of the shipped 16 narrows, so WP-4.6 moved no shipped claim'
);

// The narrowing declaration is a top-level manifest key, so it could not land
// after V3-KEYS closed the set without also widening it: the two ride together,
// and WP-4.6 rode first. `environment` is now the ONE partition key naming an
// environment; a compatibility AXIS name appearing here would be a different
// rule — a per-axis top-level key nobody specified — so the probe stays.
$environmentish = array_values(array_intersect(
    $closedSet,
    ['compatibility', 'database', 'environment', 'environment_assumptions', 'filesystem', 'php', 'platform', 'site_mode', 'wordpress']
));
duo_check_same(
    ['environment'],
    $environmentish,
    'V3-AXIS x V3-KEYS: the partition names exactly one environment key — WP-4.6\'s narrowing channel — and no compatibility axis'
);
$report('shipped adapters that declare a narrower environment today: 0 of 16 (the channel exists and none uses it)');

// What today's certificate binds, and the reason R7 exists: verification is a
// byte-exact comparison of the WHOLE platform record — AdapterCertification.php
// :1043, `hash_equals(Canon::encode($platform), Canon::encode($statementTyped->
// platform))` — not of the axes the bundle exercised. So every field in that
// record is load-bearing for every certificate, and moving any one of them
// re-invalidates the fleet's certificates wholesale.
$certSource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterCertification.php');
duo_check(
    str_contains($certSource, 'hash_equals(Canon::encode($platform), Canon::encode($statementTyped->platform))'),
    'V3-AXIS: certificate verification compares the whole platform record byte-for-byte, so it binds every field and not just the exercised axes'
);
$platformFields = array_keys($platform);
sort($platformFields, SORT_STRING);
$report('platform record fields a certificate binds today: ' . implode(', ', $platformFields));
$report(sprintf(
    'of those, %d are the compatibility axes R7 would bind instead; %d are bound today for no exercised reason',
    count($axes),
    count($platformFields) - 1
));
duo_check(
    in_array('compatibility', $platformFields, true) && count($platformFields) > 1,
    'V3-AXIS: the axes R7 would bind are one field of a record with ' . count($platformFields) . ', which is the width R7 removes'
);

// ===========================================================================
// RULE V3-NS — namespace prefixing for the three flat identity spaces (R10)
// ===========================================================================

echo "\nRULE V3-NS: <vendor>-<name> prefixing for adapter names, table id_kinds and provider ids\n";

$idKinds = [];
$providerIds = [];
foreach ($shipped as $name => $manifest) {
    foreach ((array) ($manifest['tables'] ?? []) as $table => $rule) {
        if (is_array($rule) && is_string($rule['id_kind'] ?? null)) {
            $idKinds[$rule['id_kind']][] = (string) $name;
        }
    }
    foreach ((array) ($manifest['providers'] ?? []) as $provider) {
        if (is_array($provider) && is_string($provider['id'] ?? null)) {
            $providerIds[$provider['id']][] = (string) $name;
        }
    }
}
ksort($idKinds, SORT_STRING);
ksort($providerIds, SORT_STRING);

// The rule can only test the SHAPE `<vendor>-<name>`; it cannot know whether a
// first segment is a vendor. That limit is the finding, not a defect of the
// probe — see the assertion below it.
$hyphenShaped = static fn(string $value): bool => preg_match('/^[a-z0-9]+-[a-z0-9-]+$/D', $value) === 1;

$spaces = [
    'adapter name' => array_keys($shipped),
    'tables.*.id_kind' => array_keys($idKinds),
    'providers[].id' => array_keys($providerIds),
];
$unprefixed = [];
foreach ($spaces as $space => $values) {
    $unprefixed[$space] = array_values(array_filter($values, static fn($v): bool => !$hyphenShaped((string) $v)));
    $report(sprintf(
        '%-18s %2d values, %2d not <vendor>-<name> shaped',
        $space . ':',
        count($values),
        count($unprefixed[$space])
    ));
}
// "Who would refuse and why", named rather than counted — this is the list an
// operator has to grandfather, and a count does not tell them which rows move.
foreach ($spaces as $space => $values) {
    foreach ($unprefixed[$space] as $value) {
        $owners = $space === 'adapter name' ? [(string) $value] : ($space === 'tables.*.id_kind' ? $idKinds[$value] : $providerIds[$value]);
        $report(sprintf('  %-18s %-28s declared by %s', $space, (string) $value, implode(', ', array_unique($owners))));
    }
}

duo_check_same(
    ['acf', 'core', 'elementor', 'polylang', 'woocommerce', 'yoast'],
    $unprefixed['adapter name'],
    'V3-NS: 6 of the 16 shipped adapter names carry no hyphen at all and can be read as <vendor>-<name> under no reading'
);
duo_check_same(
    18,
    count($unprefixed['tables.*.id_kind']),
    'V3-NS: ALL 18 shipped id_kinds are underscore-separated, so the hyphen form would refuse the entire shipped vocabulary'
);
duo_check_same(
    [],
    $unprefixed['providers[].id'],
    'V3-NS: all 10 provider ids are already hyphen-shaped with a plugin-slug first segment — the one space where the convention is de facto in force'
);

// The consequence for WP-4.10's design, measured rather than argued: a shape
// test cannot be the admission rule, because 10 of the 16 shipped names ARE
// hyphen-shaped without being vendor-prefixed (`the-events-calendar` is not
// vendor `the`). The reserved list must therefore enumerate all 16.
$hyphenButNotVendor = array_values(array_filter(
    array_keys($shipped),
    static fn(string $n): bool => $hyphenShaped($n)
));
duo_check_same(
    10,
    count($hyphenButNotVendor),
    'V3-NS: the other 10 names are hyphen-shaped but their first segment is not a vendor, so the closed reserved list WP-4.10 ships must enumerate all 16 names — a shape test admits the wrong ones'
);
$report('names the flag day must grandfather: all ' . count($shipped) . ' (shape alone cannot separate them)');

// This rule's ceiling is not measured here — it is REGISTERED. WP-0.5's
// irreversibility register (docs/wire-surface.md, generated and byte-checked by
// tools/wire-surface.php under `make release-gate`) rules on `id_kind` at R-17,
// and a v3 rule cannot overrule it. Read from the register rather than
// paraphrased, so the day R-17 is rewritten this suite's conclusion is
// re-examined instead of quietly outliving its source.
$register = (string) file_get_contents($repo . '/docs/wire-surface.md');
$r17 = '';
if (preg_match('/^### R-17 — .*?(?=^### |\z)/ms', $register, $m) === 1) {
    $r17 = $m[0];
}
duo_check(
    $r17 !== '' && str_contains($r17, '`id_kind` is a flat, unprefixed namespace'),
    'V3-NS: the irreversibility register still carries R-17, the row that rules on this rule'
);
duo_check(
    str_contains($r17, 'A convention (a vendor-shaped name) can be recommended to authors at any time; a RULE cannot be introduced'),
    'V3-NS: R-17 reserves the CONVENTION and refuses the RULE, so WP-4.10 can only grandfather — the ' . count($idKinds) . ' shipped id_kinds are not a break list, they are the permanent floor'
);
$report('id_kinds the flag day must grandfather: all ' . count($idKinds) . ' — wire-surface R-17: captured state and duo_map rows embed the BARE kind, so a prefix rule would have to rewrite the customer\'s branches');

// Every shipped name already passes the single shared identity grammar, so the
// namespace rule is additive over `assert_name()` rather than a replacement.
$grammarRefusals = [];
foreach (array_merge(array_keys($shipped), array_keys($idKinds), array_keys($providerIds)) as $identity) {
    try {
        AdapterSources::assert_name((string) $identity, 'v3 dry run identity');
    } catch (\Throwable $e) {
        $grammarRefusals[(string) $identity] = $e->getMessage();
    }
}
duo_check_same([], $grammarRefusals, 'V3-NS: all 44 shipped identities already pass AdapterSources::assert_name(), so the namespace rule layers over one grammar');

// id_kind collisions are the correctness reason the namespace exists at all.
$collisions = array_values(array_filter(array_keys($idKinds), static fn(string $k): bool => count(array_unique($idKinds[$k])) > 1));
duo_check_same([], $collisions, 'V3-NS: no id_kind is claimed by two shipped adapters today, so the rule grandfathers a collision-free set');

// ===========================================================================
// The flag-day break list, as one table.
// ===========================================================================

echo "\nFLAG-DAY BREAK LIST (shipped library only)\n";
$breakList = [
    'V3-KEYS  closed top-level key set' => count($shippedBreaks),
    'V3-FEAT  engine_features channel' => count($featureDeclarers),
    'V3-DISP  per-adapter dispositions' => count($missingEntry) + count($unsafeNames) + count($canonUnstable),
    'V3-AXIS  per-adapter environment narrowing' => 0,
    'V3-NS    namespace prefixing (as a REFUSAL)' => count($unprefixed['adapter name']) + count($unprefixed['tables.*.id_kind']),
];
foreach ($breakList as $rule => $count) {
    $report(sprintf('%-45s %2d would refuse', $rule, $count));
}
$report('open findings for the issue: F2 (theme_version_range absent from the partition), F3 (`_draft` emitted by adapter-draft)');

duo_check_same(
    0,
    $breakList['V3-KEYS  closed top-level key set']
        + $breakList['V3-FEAT  engine_features channel']
        + $breakList['V3-DISP  per-adapter dispositions']
        + $breakList['V3-AXIS  per-adapter environment narrowing'],
    'four of the five candidate rules would refuse nothing in the shipped library; only V3-NS breaks it, which is why WP-4.10 grandfathers rather than refuses'
);
duo_check_same(
    24,
    $breakList['V3-NS    namespace prefixing (as a REFUSAL)'],
    'V3-NS as a bare refusal would break 24 shipped identities (6 names + 18 id_kinds) — the measurement that forces the reserved closed list'
);

duo_check_summary('spec v3 static dry run');
