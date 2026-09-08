<?php
/**
 * The v3 static dry-run: what each candidate spec-v3 rule would refuse TODAY,
 * measured against the whole shipped library and a fixture estate, before any
 * rule is enabled.
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * The flag day (WPRISM_SPEC_VERSION 2 -> 3) turns on several rules at once, and
 * the failure mode nobody can recover from is discovering the in-repo break
 * list AFTER the defines move: `platform/adapter-library/capabilities/platform.json` restates
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
 *     ENTITY_SECTIONS (5) + FIELD_SECTIONS (14) + NON_SURFACE_KEYS (14, since
 *     WP-4.6 added `environment` and WP-4.3 added `theme_version_range`)
 *     partition, read by Reflection because they are private and
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
 *     a rule, because captured state and `wprism_map` rows embed the bare kind, so
 *     V3-NS's shipped id_kinds are a permanent floor and not a break list.
 *
 * So the durable asset is the FIXTURE ESTATE plus the measurements, not the
 * prediction. WP-4.3 has since made the contract grammar consult the same
 * partition, and the estate is untouched: what moved is the reader count (one
 * shipped reader, now two) and the two assertions that recorded F2 as open. The
 * fixtures' verdicts AT THIS ENGINE did not move at all, because the rule is
 * gated at `spec_version: 3` and every fixture here declares WPRISM_SPEC_VERSION.
 * The shipped library now straddles v2/v3: reviewed post-flag consumers opt in
 * per adapter, so the measurements below distinguish the
 * partition from feature-roster classification instead of assuming every
 * shipped manifest predates the flag.
 * `sandbox/tests/offline/policy/regress_closed_top_level_keys.php` drives the
 * same shapes at a synthetic N+1 engine, where they refuse by name.
 *
 * THE ESTATE
 * ----------
 * Every shipped manifest + 7 constructed fixtures + the 5 on-disk synthetic
 * manifests, the last DISCOVERED by shape (string `name`, int `spec_version`,
 * a `plugin` or `theme` subject) under shared and capsule-owned fixture roots,
 * minus gitignored scratch.
 * Discovery rather than a hand-maintained input because out-of-tree adapters
 * are the population the flag day actually hits: the estate assertion names
 * every discovered path and fails when a new fixture is not reviewed here.
 *
 * THE MEASUREMENT THIS SUITE OWES ITS CALLER
 * ------------------------------------------
 * WP-1.6 requires the union of top-level keys actually in use across
 * the source package manifests (`adapter-packages/<slug>/package/manifest.json`
 * plus `platform/adapter-library/core/manifest.json`) to be MEASURED against the 33-key signer partition and
 * the difference ENUMERATED, never assumed. It is measured below and the
 * difference is asserted rather than reconciled:
 *
 *   F1  All 33 signer-partition keys are in use across the shipped
 *       manifests. Feature-bearing adapters also declare four keys carried by
 *       the feature roster rather than that partition; their signer verdicts
 *       stay clean because § v3.21 classifies those keys with their features. Two of
 *       the three previously unused partition keys were channels admitted in
 *       the change that reads them:
 *       WP-4.6's `environment` (§ v3.5) and WP-4.3's `theme_version_range`
 *       (§ v3.3 resolution 1), declared by none of them.
 *   F2  RESOLVED by WP-4.3. The finding was that the third unused partition key
 *       is `theme`, and that it was UNUSABLE as shipped:
 *       `AdapterContractGrammar::validate_adapter_contract()` (:45) demands a
 *       `theme_version_range` beside every `theme`, and `ArtifactPolicyIdentity`
 *       folds that key into the adapter identity row (:152-153) — yet
 *       `theme_version_range` was in NO arm of the partition, so a theme adapter
 *       validated and was then unsignable. § v3.3's reviewed resolution 1 gave
 *       it `version_range`'s arm; the fixture below is unchanged and the verdict
 *       is now `null` on both halves.
 *   F3  A live in-tree producer emits a top-level key the partition does not
 *       know: `cli/src/Adapter/AdapterDraft.php:379` writes `_draft` into the
 *       manifest it hands the author. `wprism adapter-draft` output is therefore
 *       unsignable, and § v3.3's reviewed resolution 2 KEEPS that refusal on the
 *       merits and refuses the key at v3 too, with "strip it" as the remedy.
 *
 * F2 and F3 were reported rather than fixed when this suite was written, since
 * teaching the signer a key changes what a certificate covers; § v3.3 is where
 * both were reviewed and WP-4.3 is the rider that implemented the reviewed
 * answers.
 *
 * F1's OTHER HALF, CLOSED BY WP-6.6 (§ v3.21). The SHIPPED library was always
 * clean for this rule; the on-disk synthetic estate was not, and the reason was
 * F2's shape repeating rather than a fixture defect. A key admitted by § v3.2's
 * channel is admitted at LOAD and classified by nothing, so the tree's first
 * spec-3 adapter — four feature-claimed keys — loaded on every site and was
 * refused by BOTH signing profiles. § v3.21 moved the arm into the feature's own
 * roster row, so the classification is now part of shipping a feature rather
 * than a patch remembered afterwards, and the V3-KEYS break list below is empty
 * in both populations. The assertions that measured the wall are the ones that
 * moved; the estate is untouched, which is this suite's whole discipline. What
 * did NOT move: a key no implemented feature claims still meets the signer's
 * unclassifiable-section refusal verbatim, asserted beside the empty break list
 * with a control manifest.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/ShippedIdentityInventory.php';
require_once __DIR__ . '/../../../../agent/src/Policy/AdapterLibrary.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestValidator.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterCertification.php';
require_once __DIR__ . '/../../../../tools/src/AdapterPackageProjection.php';
require_once __DIR__ . '/manifest_fixtures.php';

use WPrism\AdapterCertification;
use WPrism\AdapterContractGrammar;
use WPrism\AdapterLibrary;
use WPrism\AdapterSources;
use WPrism\Canon;
use WPrism\ManifestDispositions;
use WPrism\ManifestValidator;
use WPrism\Policy;
use WPrism\ShippedIdentityInventory;

$repo = dirname(__DIR__, 4);
$adapterLibrary = AdapterLibrary::fromSourceTree($repo);
$embeddedPhpFiles = [];
foreach (\WPrism\Tooling\AdapterPackageProjection::plan($repo) as $source => $destination) {
    if (str_ends_with($destination, '.php')) {
        $embeddedPhpFiles[] = $source;
    }
}

/** One indented report row. Indented so it can never look like a PHP diagnostic to the guard. */
$report = static function (string $line): void {
    echo '  ' . $line . "\n";
};

/**
 * Every shipped PHP file naming any of these tokens, repo-relative and sorted.
 *
 * The checked-in agent, CLI, and recovery trees execute across a deployment.
 * Adopt also embeds every PHP member selected by AdapterPackageProjection
 * below the agent before archiving exactly `agent recovery`; reading the real
 * projection here means a future package runtime cannot evade the scan. Thus
 * "no reader" measured over this set means neither side can read the token. A
 * source scan and not a Reflection walk because the question is which FILES
 * would have to change on the flag day, which the loaded class graph cannot
 * answer.
 *
 * Comments and docblocks are stripped before the grep (WP-6.4). A reader is a
 * file whose CODE names the token; a file whose docblock explains why it is not
 * one is not one, and AGENTS.md rule 10 guarantees this tree has such files —
 * `agent/src/Adapter/StructuredEvidence.php` was the first, counted twice for
 * saying in prose that it holds neither the feature vocabulary nor a partition
 * arm. Same carve-out and same argument as `regress_platform_move_gates.php`
 * (`:33-35`): documentation is not a gate. Widening the expected sets instead
 * would have made both assertions weaker for the case they exist to catch.
 *
 * @param list<string> $tokens
 * @return list<string>
 */
$shippedFilesNaming = static function (array $tokens) use ($repo, $embeddedPhpFiles): array {
    $hits = [];
    $files = [$repo . '/cli/wprism' => true];
    foreach (['agent', 'cli', 'recovery'] as $tree) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repo . '/' . $tree, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files[$file->getPathname()] = true;
        }
    }
    foreach ($embeddedPhpFiles as $file) {
        $files[$file] = true;
    }
    foreach (array_keys($files) as $file) {
        // COMMENTS STRIPPED (WP-6.1): this helper answers "which shipped
        // files READ this name", and a docblock explaining why a section
        // rides a channel reads nothing. It was a plain text match while
        // `engine_features` appeared in one file's prose and code alike;
        // the first sections to actually ship through the channel put the
        // phrase in the docblocks of the collaborators that stage through
        // it, which a text match reports as four readers of a definition
        // three of them never consult.
        $body = wprism_code_without_comments((string) file_get_contents($file));
        foreach ($tokens as $token) {
            if (str_contains($body, $token)) {
                $hits[substr($file, strlen($repo) + 1)] = true;
                break;
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

// 14 non-surface keys, not 12, and both arrivals are the growth rule of § v3.3
// exercised once each: `environment` joined with the narrowing rule that reads
// it (WP-4.6, § v3.5) and `theme_version_range` with the closed set itself
// (WP-4.3, § v3.3 resolution 1 — F2 below, now resolved). Both because a
// top-level key in no arm makes its whole adapter unsignable. Pinned here so
// the next arrival is a reviewed edit rather than a drift.
wprism_check_same(
    [5, 14, 14],
    [count($entitySections), count($fieldSections), count($nonSurfaceKeys)],
    'the closed key set is the signer partition, read by Reflection: 5 entity + 14 field + 14 non-surface'
);
wprism_check_same(
    33,
    count(array_unique($closedSet)),
    'the three arms are disjoint, so the set is exactly 33 keys'
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
wprism_check_same(
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
// The estate: every shipped manifest + the representative fixtures.
// ---------------------------------------------------------------------------

$shipped = [];
foreach ($adapterLibrary->packages() as $package) {
    $shipped[$package->name()] = Canon::decode(Canon::read_file($package->manifestPath()));
}
ksort($shipped, SORT_STRING);

wprism_check_same(
    ShippedIdentityInventory::ADAPTER_NAMES,
    array_keys($shipped),
    'the shipped library under test exactly matches the generated runtime inventory'
);

// Every fixture is a shape a candidate rule has an opinion about. manifest_a()
// and manifest_b() are the corpus-wide pair (rule 5): a rule that refuses THEM
// refuses the shape every other offline suite calls well-formed.
$themeAdapter = [
    'name' => 'acme-theme',
    'spec_version' => WPRISM_SPEC_VERSION,
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
    // What `wprism adapter-draft` actually hands an author (AdapterDraft.php:379).
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
// actually hits, and `sandbox/fixtures/acme-catalog/wprism-adapter.json` was for a
// long time the only one in the tree. It is no longer alone:
// `sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json` is the tree's
// first out-of-tree adapter authored AT `spec_version` 3 and through § v3.2's
// feature channel; Rank Math's capsule-owned
// `adapter-packages/rank-math/fixtures/site-adapter-prepromotion.json` is the
// second, authored by a separate real user/agent exercise. Together with the
// v2 acme fixture they straddle the flag day, all discovered by the same walk.
// DISCOVERED, not selected, by the
// shape that makes a JSON document an adapter manifest — a string `name`, an
// int `spec_version`, and a `plugin` or `theme` subject — so a fixture added
// later is measured instead of quietly missed. The two gitignored scratch
// roots (.gitignore:3-4,
// `sandbox/siterepo/` and `sandbox/tmp/`) are skipped, and skipping siterepo is
// not tidiness: a pair run leaves a whole site repository there, adapters
// included, so a walk that read it would give a different estate on a machine
// that has run `pair.sh` than on one that has not.
$scratchRoots = ['sandbox/siterepo/', 'sandbox/tmp/'];
$discovered = [];
$fixtureRoots = [$repo . '/sandbox'];
foreach ([$repo . '/adapter-packages/*/fixtures', $repo . '/integration-scenarios/*/fixtures'] as $pattern) {
    foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $fixtureRoot) {
        $fixtureRoots[] = $fixtureRoot;
    }
}
foreach ($fixtureRoots as $fixtureRoot) {
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
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
}
ksort($discovered, SORT_STRING);

wprism_check_same(
    [
        'adapter-packages/rank-math/fixtures/site-adapter-prepromotion.json',
        'sandbox/fixtures/acme-catalog/wprism-adapter.json',
        'sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json',
        'sandbox/tests/fixtures/wprism-sidecar-refs/manifest.json',
        'sandbox/tests/fixtures/wprism-taxonomy-keyspace/manifest.json',
    ],
    array_keys($discovered),
    'the on-disk synthetic manifest estate is five documents; a sixth must be considered by this dry run, not silently added'
);

$estate = [];
foreach ($shipped as $name => $manifest) {
    $estate['shipped:' . $name] = [$name, $manifest];
}
foreach ($fixtures as $label => $manifest) {
    $estate[$label] = [(string) ($manifest['name'] ?? '?'), $manifest];
}
foreach ($discovered as $path => $manifest) {
    // Labelled by the manifest's DECLARED name and not by its directory. A
    // site-installed adapter lives at `<repo>/adapters/<name>.json` by
    // derivation (`AdapterCertification::certificatePath()`'s sibling rule), so
    // `basename(dirname($path))` labels every one of them `adapters` — one
    // label for every site adapter the tree ever carries, which would have
    // collapsed the estate silently rather than refusing. The declared name is
    // the identity every rule below actually judges.
    $estate['synthetic:' . (string) $manifest['name']] = [(string) $manifest['name'], $manifest];
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
    'in-use top-level keys across the %d shipped manifests: %d; signer partition: %d',
    count($shipped),
    count($unionKeys),
    count($closedSet)
));
foreach ($unionKeys as $key) {
    $report(sprintf(
        '  %-20s declared by %2d/%d%s',
        $key,
        count($union[$key]),
        count($shipped),
        in_array($key, $closedSet, true) ? '' : '   <-- CLASSIFIED BY FEATURE ROSTER'
    ));
}
$report('partition keys no shipped manifest declares: ' . ($knownUnused === [] ? '(none)' : implode(', ', $knownUnused)));

// F1 — the difference, enumerated in both directions. Redirection's and WPForms'
// feature-claimed keys deliberately sit in § v3.21's roster rather than
// duplicating the signer partition.
wprism_check_same(
    ['attr_id_codecs', 'body_refs', 'column_codecs', 'declaration_evidence', 'engine_features', 'incompatible_plugins'],
    $unknownInUse,
    'F1: every shipped key outside the signer partition belongs to an explicitly declared feature roster'
);
wprism_check_same(
    [],
    array_values(array_diff($unknownInUse, array_keys(AdapterContractGrammar::feature_key_arms()))),
    'F1: every shipped key outside the partition has a certificate arm in the feature roster'
);
wprism_check_same(36, count($unionKeys), 'F1: the in-use union is 36 keys');
// Three keys the partition admits and no shipped adapter declares, and they are
// there for different reasons: `theme` predates the library's plugin-only
// contents; `environment` is WP-4.6's narrowing channel and `theme_version_range`
// is WP-4.3's resolution of F2 — each admitted in the change that reads it,
// precisely so an adapter that uses it stays signable (rule V3-AXIS below
// measures that none of the 17 uses `environment` yet).
wprism_check_same(
    ['environment', 'theme', 'theme_version_range'],
    $knownUnused,
    'F1: the partition/union difference is exactly three keys — `environment`, `theme` and `theme_version_range`, declared by no shipped adapter'
);

// The same measurement over the on-disk synthetic estate, because the flag day
// hits out-of-tree adapters first. Measured separately: an unknown key here
// would be a fixture to fix, not a finding against the library.
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
// NOT empty any more, and the difference is § v3.3's growth rule rather than a
// defect. `sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json` declares
// four keys the signer's three arms do not carry — `engine_features` itself
// plus the three sections its declared features claim — because every one of
// them arrived through § v3.2's channel, which admits a top-level key at LOAD
// without adding it to the partition a SIGNER classifies. The two halves are
// therefore asserted separately: the exact set, so a fifth key is a reviewed
// edit here; and, one level stronger, that each member is claimed by a feature
// the declaring manifest declares and this engine implements — which is what
// distinguishes "shipped through the channel" from "a section nobody reads".
wprism_check_same(
    ['attr_id_codecs', 'body_refs', 'declaration_evidence', 'engine_features'],
    $syntheticUnknown,
    'F1: the on-disk synthetic keys the signer partition does not know are exactly the four the feature channel admits'
);
// DERIVED, NOT PINNED (WP-6.6). Every one of those four is in the roster and
// carries an arm, so "the partition does not know it" now means "another
// definition does" rather than "nobody does". Reading the arm out of
// `feature_key_arms()` rather than listing it here is the same discipline the
// partition itself gets above: this suite's rule inputs have exactly one
// definition, and a copy would pass on the day it was typed.
$rosterArms = AdapterContractGrammar::feature_key_arms();
wprism_check_same(
    [],
    array_values(array_diff($syntheticUnknown, array_keys($rosterArms))),
    'F1 RESOLVED by § v3.21: every key the partition does not know is classified by the roster instead, so '
        . '"unknown to the signer" no longer means "unsignable"'
);
wprism_check_same(
    [],
    array_values(array_intersect(array_keys($rosterArms), $closedSet)),
    'F1: and the two definitions are DISJOINT — a roster row may not name a key the partition already carries, '
        . 'which is the second-spelling failure the whole lift exists to prevent'
);
wprism_check_same(
    [],
    array_values(array_diff(array_unique(array_values($rosterArms)), AdapterCertification::certificateArms())),
    'F1: every arm the roster names is one of the signer\'s three, so a typo in a roster row refuses at the '
        . 'roster rather than reporting an author\'s manifest for the engine\'s mistake'
);
$unclaimed = [];
foreach ($syntheticUnknown as $key) {
    foreach ($syntheticUnion[$key] as $path) {
        if (!in_array($key, AdapterContractGrammar::admitted_top_level_keys($discovered[$path]), true)) {
            $unclaimed[] = "$path declares '$key'";
        }
    }
}
sort($unclaimed, SORT_STRING);
wprism_check_same(
    [],
    $unclaimed,
    'F1: and every one of them is admitted for the manifest that declares it by a feature that manifest declares — the growth rule, not an unread section'
);

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
wprism_check_same([], $shippedBreaks, 'V3-KEYS refuses zero shipped adapters: the closed key set is digest-neutral for the library');
$syntheticBreaks = array_values(array_filter(array_keys($keysBreakList), static fn(string $l): bool => str_starts_with($l, 'synthetic:')));
// ZERO, AND THAT IS THE CHANGE WP-6.6 LANDED. This assertion used to read
// `['synthetic:wpforms-lite']` and carried its own sentence about the price of
// the channel: the signer's partition had no arm for a feature-claimed key, so
// the tree's first spec-3 adapter loaded everywhere and could not be signed by
// either profile. § v3.21 moved the arm into the feature's own roster row, so
// the break list is empty in both populations — the digest-neutrality half AND
// the certifiability half. This is the suite doing its job: a rider that lands
// a rule moves the assertion that measured its absence, and not the estate.
wprism_check_same(
    [],
    $syntheticBreaks,
    'V3-KEYS refuses ZERO on-disk synthetic manifests: § v3.21 gave every feature-claimed key an arm, so the '
        . 'tree\'s first spec-3 adapter is signable and acme-catalog, the v2 one, still signs'
);
// The refusal did not go away; it went where it belongs. A control manifest
// declaring a section NO implemented feature claims still meets the signer's own
// unclassifiable-section sentence, word for word, which is what keeps "teach the
// signer this section" true for a genuine misspelling.
$controlAdapter = $discovered[array_key_first(array_filter(
    $discovered,
    static fn(array $m): bool => (string) ($m['name'] ?? '') === 'wpforms-lite'
))];
$controlAdapter['acme_invented_section'] = ['x' => 1];
$controlVerdict = (string) $signerVerdict('wpforms-lite', $controlAdapter);
wprism_check(
    str_contains($controlVerdict, "declares 'acme_invented_section'")
        && str_contains($controlVerdict, 'which this signer cannot classify as an entity or field surface')
        && str_contains($controlVerdict, 'teach the signer this section'),
    'V3-KEYS: and a key NO implemented feature claims still meets the unclassifiable-section verdict verbatim — '
        . 'the roster classified the four that arrived through the channel, not everything'
);
wprism_check_detail('V3-KEYS control refusal: ' . $controlVerdict);
wprism_check_same(
    [],
    array_values(array_intersect(array_keys($keysBreakList), ['fixture:manifest-a', 'fixture:manifest-b'])),
    'V3-KEYS admits the corpus-wide manifest_a()/manifest_b() pair, so it does not condemn the shape every other suite calls valid'
);

// F2, RESOLVED by WP-4.3 (§ v3.3 resolution 1). The finding was that `theme`
// was admitted and its mandatory companion was not, so a theme adapter
// validated and was then unsignable — the partition incomplete against the
// shipped grammar by exactly one key. The FIXTURE is unchanged; what moved is
// the verdict, which is this suite's whole discipline: a rider that lands a
// rule moves the assertion that measured its absence and not the estate.
wprism_check(
    in_array('theme', $closedSet, true) && in_array('theme_version_range', $closedSet, true),
    'F2 RESOLVED: the partition knows `theme_version_range` beside `theme`'
);
wprism_check_same(
    null,
    $validatorVerdict($themeAdapter),
    'F2: a theme adapter passes the shipped validator pipeline (AdapterContractGrammar accepts theme + theme_version_range)'
);
$themeVerdict = $signerVerdict('acme-theme', $themeAdapter);
wprism_check_same(
    null,
    $themeVerdict,
    'F2: ...and is now SIGNABLE too — the signer classifies the companion key the grammar already made mandatory'
);

// F3 — a shipped producer emits a key the partition does not know.
$draftSource = (string) file_get_contents($repo . '/cli/src/Adapter/AdapterDraft.php');
wprism_check(
    str_contains($draftSource, "\$manifest['_draft'] = self::build_draft("),
    'F3: `wprism adapter-draft` writes a top-level `_draft` into the manifest it emits (AdapterDraft.php:379)'
);
wprism_check(!in_array('_draft', $closedSet, true), 'F3: `_draft` is in no arm of the signer partition');
$draftVerdict = $signerVerdict('drafted', $fixtures['fixture:adapter-draft-output']);
wprism_check(
    is_string($draftVerdict) && str_contains($draftVerdict, "declares '_draft'"),
    'F3: so adapter-draft output is unsignable today, and V3-KEYS would refuse it by name on the flag day'
);
wprism_check_detail('F3 refusal: ' . (string) $draftVerdict);

// THE SEAM, FLIPPED. This suite's header states that a rider landing a rule
// moves the assertion that measured its absence and NOT the fixtures. This is
// that assertion for V3-KEYS: the partition had exactly one shipped reader (the
// signer) and WP-4.3 made the contract grammar the second, reading it through
// `AdapterCertification::topLevelKeyPartition()` so that the two readers share
// one definition — which `php tools/wire-surface.php --check` now asserts under
// `make release-gate` (register row R-21).
// Measured twice, because "one definition, two readers" is two facts. The
// three private consts are the DEFINITION and must stay in one file — that
// property is what the release gate protects. The public accessor is how every
// other file reaches them, so it is what "reader" means now that the set is
// enforced from outside the class that owns it.
$partitionDefiners = $shippedFilesNaming(['ENTITY_SECTIONS', 'FIELD_SECTIONS', 'NON_SURFACE_KEYS']);
wprism_check_same(
    ['agent/src/Adapter/AdapterCertification.php'],
    $partitionDefiners,
    'ONE DEFINITION: the three partition constants are declared in exactly one shipped file, and WP-4.3 did not copy them anywhere'
);
$partitionReaders = $shippedFilesNaming(['topLevelKeyPartition']);
wprism_check_same(
    [
        'agent/src/Adapter/AdapterCertification.php',
        'agent/src/Adapter/AdapterContractGrammar.php',
        'cli/src/Adapter/ManifestValidate.php',
    ],
    $partitionReaders,
    'THE FLIP: the accessor has a SECOND enforcing reader — the contract grammar, which refuses an unrecognised key at spec_version 3 — beside the class that owns it and the emitter that publishes it'
);
// THE FLIP (WP-4.12) LANDED ON THIS FIXTURE, and both halves are worth
// keeping. `manifest_a()` stamps WPRISM_SPEC_VERSION, which is now 3 — the gate
// itself — so the rule this suite dry-ran is LIVE against it. The era the
// shipped library still sits in is N-1, and that is where the open behaviour
// went: the same bytes, one version down, still admitted. Two verdicts on one
// fixture is the whole no-restamp argument in two assertions.
$typoAtEngine = $validatorVerdict($fixtures['fixture:typo-and-invented-section']);
wprism_check(
    is_string($typoAtEngine) && str_contains($typoAtEngine, "'optoins'")
        && str_contains($typoAtEngine, "'totally_made_up_section'"),
    "WP-4.3's named case is now REFUSED at this engine's own version: the flip put WPRISM_SPEC_VERSION on the "
        . 'closed-key-set gate, so the rule this suite dry-ran is live and names both keys'
);
$typoAtLibraryEra = $fixtures['fixture:typo-and-invented-section'];
$typoAtLibraryEra['spec_version'] = WPRISM_SPEC_VERSION - 1;
wprism_check(
    is_string($validatorVerdict($typoAtLibraryEra))
        && str_contains((string) $validatorVerdict($typoAtLibraryEra), "'optoins'")
        && str_contains((string) $validatorVerdict($typoAtLibraryEra), "'totally_made_up_section'"),
    '...and the IDENTICAL bytes at spec_version ' . (WPRISM_SPEC_VERSION - 1) . ' are refused too — legacy '
        . 'manifests keep their bytes and digests, but typos no longer become inert declarations'
);
$typoV3 = $fixtures['fixture:typo-and-invented-section'];
$typoV3['spec_version'] = WPRISM_SPEC_VERSION + 1;
$typoV3Verdict = $validatorVerdict($typoV3);
wprism_check(
    is_string($typoV3Verdict) && str_contains($typoV3Verdict, 'accepts spec_version'),
    '...and the same fixture at spec_version ' . (WPRISM_SPEC_VERSION + 1)
        . ' is refused by the WINDOW on this engine, which is why the key rule needs the N+1 probe process'
);
$typoVerdict = $signerVerdict('typo', $fixtures['fixture:typo-and-invented-section']);
wprism_check(
    is_string($typoVerdict) && str_contains($typoVerdict, 'which this signer cannot classify'),
    '...while the signer refuses that same manifest by name at every version — the one-sided enforcement is now one-sided only for v2'
);
wprism_check_detail('V3-KEYS typo refusal: ' . (string) $typoVerdict);

// ===========================================================================
// RULE V3-FEAT — the `engine_features` declaration channel (R2 / WP-4.2)
// ===========================================================================

echo "\nRULE V3-FEAT: per-adapter `engine_features`, refused BY NAME when the engine lacks one\n";

$featureReaders = $shippedFilesNaming(['engine_features']);

$featureDeclarers = array_values(array_filter(
    array_keys($shipped),
    static fn(string $n): bool => array_key_exists('engine_features', $shipped[$n])
));
$featureBreaks = array_values(array_filter(
    $featureDeclarers,
    static fn(string $n): bool => $validatorVerdict($shipped[$n]) !== null
));

$report('shipped manifests declaring `engine_features`: ' . count($featureDeclarers));
$report('would-refuse under V3-FEAT at their declared versions: ' . count($featureBreaks));
$report('shipped code reading `engine_features`: ' . ($featureReaders === [] ? '(none)' : implode(', ', $featureReaders)));
$report('engine features this engine implements: '
    . implode(', ', \WPrism\AdapterContractGrammar::implemented_features()));

wprism_check_same(
    [
        'change-wp-admin-login',
        'code-snippets',
        'elementor',
        'ninja-forms',
        'paid-memberships-pro',
        'polylang',
        'rank-math',
        'redirection',
        'the-events-calendar',
        'woocommerce',
        'wpforms-lite',
        'yoast',
        'yoast-duplicate-post',
    ],
    $featureDeclarers,
    'V3-FEAT: every feature consumer declares what it consumes and existing adapters pay only their own identity change'
);
wprism_check_same(
    [],
    $featureBreaks,
    'V3-FEAT: every shipped declarer is already at the feature channel version, so the current library has no channel refusal'
);
// THE FLIP (WP-4.2). This suite's header states that a rider landing a rule
// moves the assertion that measured its absence and NOT the fixtures. This is
// that assertion for V3-FEAT: the channel acquired shipped readers,
// the grammar that owns the vocabulary, and `fixture:engine-features` below is
// unchanged.
// WP-6.2 added the SECOND reader, and the pair is the shape the channel is
// meant to have rather than a leak. AdapterContractGrammar still owns the one
// VOCABULARY — which names exist, the first spec_version their sections live
// at, and the keys each claims — and it is the only file that can refuse an
// unimplemented name. ManifestGrammar reads a manifest's DECLARED list to
// answer one narrower question: may this document use the feature-gated
// `invalidate[]` verbs. It cannot delegate that to the contract grammar,
// because Policy sits below Adapter on tools/modules.json's ladder; what it
// does instead is own the feature NAME as a constant the contract grammar
// reads back, so there is still exactly one spelling of it in the tree.
// WP-6.5 added the THIRD gate reader and the FIRST publisher, and the two are
// different roles that this census now has to keep separate.
//
// BodyRefGrammar is the same shape as ManifestGrammar rather than a new one: it
// owns the name `structured-body-refs/v1` as a constant the contract grammar
// reads back, and reads a manifest's DECLARED list to answer one narrower
// question — may this document use the feature-gated `json` body MODE. Same
// reason it cannot delegate: Grammar sits below Adapter on
// tools/modules.json's ladder.
//
// cli/src/Adapter/ManifestValidate.php is not a gate at all. It PUBLISHES the
// channel in `wprism manifest-validate --emit-schema`'s grammar document, which
// until WP-6.5 could not describe the channel it was documenting: neither
// `engine_features` nor any of the sections a feature claims appeared anywhere
// in the emitted grammar, so an author had to read engine source to learn the
// features exist. It refuses nothing and decides nothing.
//
// The invariant the census is really protecting is unchanged and is what the
// assertion says: exactly ONE file owns the vocabulary and can refuse an
// unimplemented name.
// ReferenceShapeGrammar consumes scalar-reference-intersection/v1 and native
// value predicates at its declaration boundary. Policy verifies the exact
// interpreter owner's feature enrollment; neither duplicates the vocabulary.
wprism_check_same(
    [
        'agent/src/Adapter/ActionProviderGrammar.php',
        'agent/src/Adapter/AdapterContractGrammar.php',
        'agent/src/Grammar/BodyRefGrammar.php',
        'agent/src/Grammar/ColumnCodecGrammar.php',
        'agent/src/Kernel/ReferenceShapeGrammar.php',
        'agent/src/Policy/ManifestGrammar.php',
        'agent/src/Policy/Policy.php',
        'cli/src/Adapter/ManifestValidate.php',
    ],
    $featureReaders,
    'V3-FEAT: the channel has exactly one shipped OWNER — the contract grammar, which holds the vocabulary and '
        . 'refuses an unimplemented name — beside six gate readers (provider contracts, body mode, column framing, '
        . 'value predicates, invalidate verbs, interpreter ownership) that ask only '
        . 'whether THIS document declared the feature their gated declaration needs, and one publisher that '
        . 'refuses nothing'
);
// The admitted set includes all twenty-four implemented features. The explicit
// roster proves declarations remain negotiated without a spec-version bump;
// provider observation/input features and reference/container codecs coexist.
wprism_check_same(
    [
        'attr-id-codecs/v1',
        'block-attribute-groups/v1',
        'block-attribute-values/v1',
        'block-media-derivatives/v1',
        'body-pii-paths/v1',
        'body-ref-preserve-type/v1',
        'body-url-rebinding/v1',
        'conditional-json-refs/v1',
        'invalidate-vocabulary/v1',
        'key-bound-strings/v1',
        'manifest-provider-fresh-process/v1',
        'manifest-provider-runtime/v1',
        'mixed-column-codecs/v1',
        'native-value-validation/v1',
        'php-container-values/v1',
        'plugin-incompatibility/v1',
        'post-kind-action-trigger/v1',
        'provider-native-option-inputs/v1',
        'provider-native-permalinks/v1',
        'provider-native-post-types/v1',
        'provider-physical-table-rows/v1',
        'provider-typed-row-mutations/v1',
        'scalar-reference-intersection/v1',
        'schema-settlement/v1',
        'spec-window/v1',
        'structured-body-refs/v1',
        'structured-evidence/v1',
        'typed-column-codecs/v1',
    ],
    \WPrism\AdapterContractGrammar::implemented_features(),
    'V3-FEAT: the vocabulary carries the implemented names, so an engine that lacks a declared name has something to '
        . 'compare against and the comparison is against a SET rather than a single special case'
);
// THE FLIP (WP-4.12), the other direction. `engine_features` is implemented
// since spec_version 3, and WPRISM_SPEC_VERSION is now 3 — so the fixture that
// was refused by SECTION NAME at every accepted version is now ADMITTED at the
// engine's own. That is § v3.2's promise arriving: the declaration channel
// opens with the bump, and every later primitive rides it instead of the next
// one. The refusing half did not disappear; it moved to N-1, which is exactly
// where the shipped library and every out-of-tree adapter authored before the
// flip sit.
wprism_check_same(
    null,
    $validatorVerdict($fixtures['fixture:engine-features']),
    'V3-FEAT: the fixture that refused BY SECTION NAME at every version this engine used to accept is ADMITTED '
        . 'at spec_version ' . WPRISM_SPEC_VERSION . ' — the channel opened with the flip and needs no second bump'
);
$featureAtLibraryEra = $fixtures['fixture:engine-features'];
$featureAtLibraryEra['spec_version'] = WPRISM_SPEC_VERSION - 1;
$featureFixtureVerdict = $validatorVerdict($featureAtLibraryEra);
wprism_check(
    is_string($featureFixtureVerdict) && str_contains($featureFixtureVerdict, "the section 'engine_features'")
        && str_contains($featureFixtureVerdict, 'implements only at spec_version ' . WPRISM_SPEC_VERSION),
    '...and the same declaration at spec_version ' . (WPRISM_SPEC_VERSION - 1) . ' still refuses BY SECTION NAME — '
        . 'the silence WP-4.2 replaced, now aimed at the population that has not migrated'
);
wprism_check_detail('V3-FEAT section refusal: ' . (string) $featureFixtureVerdict);

// The coupling WP-4.2 and WP-4.3 must land together or not at all: the channel
// is a new top-level key, and the closed key set does not know it.
wprism_check(!in_array('engine_features', $closedSet, true), 'V3-FEAT x V3-KEYS: `engine_features` is in no arm of the partition');
// RESOLVED by § v3.21, and the resolution is not "add it to the partition". A
// partition arm would admit the key with no feature declared, deleting the
// staging property the channel exists for; the arm rides in `spec-window/v1`'s
// own roster row instead, and it is `non_surface` because the claim channel
// covers no state — the standing `spec_version` already has.
$featureVerdict = $signerVerdict('featureful', $fixtures['fixture:engine-features']);
wprism_check_same(
    null,
    $featureVerdict,
    'V3-FEAT x V3-KEYS: an adapter using the channel is SIGNABLE — WP-4.3 shipped without teaching the signer '
        . 'this key and WP-6.6 closed the gap for every feature-claimed key at once, not one at a time'
);
wprism_check_same(
    'non_surface',
    AdapterContractGrammar::feature_key_arms()['engine_features'] ?? null,
    'V3-FEAT x V3-KEYS: and the arm is `non_surface`, so declaring the channel adds nothing to the signed '
        . 'claim\'s `surfaces` list — a certificate says the same thing it would have said without it'
);

// ---------------------------------------------------------------------------
// RULE V3-ARM — the roster's rows, pinned; and its own self-check, driven
// (WP-6.6, § v3.21, register row R-31)
// ---------------------------------------------------------------------------

echo "\nRULE V3-ARM: every feature-claimed key carries a reviewed certificate arm\n";

// The ROWS, pinned whole. Every one of these eight is a permanent decision the
// register records (R-31): the arm reaches `claim_from_disposition()`, which
// builds the `surfaces` list inside a signed statement, so moving a key between
// arms invalidates every certificate already issued over an adapter declaring
// it. A new row, or a moved arm, is a reviewed edit here.
wprism_check_same(
    [
        'attr_id_codecs' => 'field',
        'block_media_derivatives' => 'field',
        'block_values' => 'field',
        'body_refs' => 'field',
        'column_codecs' => 'field',
        'declaration_evidence' => 'non_surface',
        'engine_features' => 'non_surface',
        'incompatible_plugins' => 'non_surface',
    ],
    AdapterContractGrammar::feature_key_arms(),
    'V3-ARM: the roster classifies eight keys — five value grammar sections as '
        . '`field`, and the claim channel, evidence records, and incompatibility list as `non_surface`'
);
$report('feature-claimed key arms: ' . json_encode(AdapterContractGrammar::feature_key_arms(), JSON_UNESCAPED_SLASHES));

// THE SELF-CHECK, DRIVEN. A gate that never bites is theatre, and this one
// cannot be reached from any manifest — a bad arm is an ENGINE typo, so the
// only way to measure the refusal is to hand the private assertion a value the
// shipped constant does not contain. Both directions of § v3.21's property (b)
// and (c) are exercised: an arm outside the vocabulary, and a key the signer's
// own partition already carries.
$assertArm = new ReflectionMethod(AdapterContractGrammar::class, 'assert_arm');
$armVerdict = static function (string $key, mixed $arm) use ($assertArm): ?string {
    try {
        $assertArm->invoke(null, 'acme-feature/v1', $key, $arm);
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
$badArm = (string) $armVerdict('acme_section', 'surface');
wprism_check(
    str_contains($badArm, "engine feature 'acme-feature/v1' classifies its top-level key 'acme_section'")
        && str_contains($badArm, "not one of the signer's certificate arms (entity, field, non_surface)"),
    'V3-ARM: an arm outside the vocabulary refuses AT THE ROSTER, naming the feature and the key — not at the '
        . 'author\'s manifest, which would report a stranger\'s document for this engine\'s typo'
);
$collidingArm = (string) $armVerdict('options', 'field');
wprism_check(
    str_contains($collidingArm, "classifies 'options', which the signer's own three-arm partition already carries"),
    'V3-ARM: and a roster row naming a key the partition already carries refuses too — that is two spellings '
        . 'of one arm, which is the exact failure the WP-5.3 lift and this rider both exist to prevent'
);
wprism_check_same(
    null,
    $armVerdict('acme_section', 'field'),
    'V3-ARM: while a key the partition does not carry, under a vocabulary arm, is accepted — so the two '
        . 'refusals above are the rule and not a blanket'
);
wprism_check_detail('V3-ARM bad-arm refusal: ' . $badArm);
wprism_check_detail('V3-ARM collision refusal: ' . $collidingArm);

// The published half. `--emit-schema` is where an author reads the arm, and
// `feature_section_grammars()` refuses a claimed key it cannot describe, so the
// document cannot go quiet about a section authors are expected to write.
$publishedSections = [];
foreach (AdapterContractGrammar::implemented_feature_rows() as $feature => $row) {
    foreach ($row['sections'] as $key => $section) {
        $publishedSections[(string) $key] = $section['arm'];
    }
}
ksort($publishedSections, SORT_STRING);
wprism_check_same(
    AdapterContractGrammar::feature_key_arms(),
    $publishedSections,
    'V3-ARM: and `implemented_feature_rows()` publishes the same arm beside each claimed key, so the document '
        . '`wprism manifest-validate --emit-schema` emits is the roster rather than a second reading of it'
);

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
    $dispositions = ManifestDispositions::load_library($adapterLibrary);
} catch (\Throwable $e) {
    $dispositionsRefusal = $e->getMessage();
}
wprism_check(
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
wprism_check_same(array_keys($shipped), $entryNames, 'V3-DISP: entry set and manifest set already agree, so the split writes one document per shipped adapter and none over');
wprism_check_same(['fse'], $profileNames, 'V3-DISP: `profiles` is one row (`fse`) and becomes its own document, as WP-4.4 specifies');

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
wprism_check_same([], $unsafeNames, 'V3-DISP: every entry and profile key is a safe single-segment basename, so no name blocks the split');

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
wprism_check_same([], $canonUnstable, 'V3-DISP: every entry survives a Canon encode/decode round trip unchanged — the split moves no adapter digest');

// The would-refuse case: a pinned adapter whose document is missing. The
// monolith refuses this by coverage mismatch; the split must keep refusing.
$missingEntry = array_values(array_filter(
    array_keys($shipped),
    static fn(string $n): bool => !array_key_exists($n, $dispositionData['manifests'])
));
wprism_check_same([], $missingEntry, 'V3-DISP: zero shipped adapters would be left without a document');
$report('would-refuse under V3-DISP: 0 of ' . count($entryNames) . ' shipped adapters');

// One entry carries no `evidence` member — the excluded regression fixture.
// Named here because the split turns "a member some entries omit" into "a
// document whose shape varies", and a reader of the new layout should know.
$noEvidence = array_values(array_filter(
    $entryNames,
    static fn(string $n): bool => !is_array($dispositionData['manifests'][$n]['evidence'] ?? null)
));
wprism_check_same(
    ['wprism-agency-cpt'],
    $noEvidence,
    'V3-DISP: exactly one entry omits `evidence` — wprism-agency-cpt, the excluded fixture with no product claim'
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
    $platform = ManifestDispositions::platform_boundary_library($adapterLibrary);
} catch (\Throwable $e) {
    $platformRefusal = $e->getMessage();
}
wprism_check(
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
wprism_check_same(['database', 'filesystem', 'php', 'process', 'wordpress'], $axes, 'V3-AXIS: the boundary declares five compatibility axes today');

// The measurement WP-4.6 inherited and must not disturb: every SHIPPED claim
// still carries the same environment, because none of the shipped adapters declares the
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
wprism_check_same([], array_keys($claimRefusals), 'V3-AXIS: every shipped disposition still projects a claim against the boundary');
wprism_check_same(
    1,
    count($distinctEnvironments),
    'V3-AXIS: every claim carries byte-identical `environment_assumptions` — no shipped adapter narrows, so WP-4.6 moved no shipped claim'
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
wprism_check_same(
    ['environment'],
    $environmentish,
    'V3-AXIS x V3-KEYS: the partition names exactly one environment key — WP-4.6\'s narrowing channel — and no compatibility axis'
);
$report('shipped adapters that declare a narrower environment today: 0 of ' . count($shipped) . ' (the channel exists and none uses it)');

// What today's certificate binds. This measurement is the one V3-AXIS row
// WP-4.7 LANDED: verification used to be a byte-exact comparison of the WHOLE
// platform record — `hash_equals(Canon::encode($platform),
// Canon::encode($statementTyped->platform))` — so every field in it was
// load-bearing for every certificate and moving any one of them re-invalidated
// the fleet wholesale. It is now assertPlatformBinding(), which re-binds the
// exercised cells and nothing else (spec/repo-format.md § v3.6). The dry run
// keeps measuring BOTH halves, because a dry run that stopped counting the
// record the moment the rule landed could not tell a reader how much width was
// actually removed.
$certSource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterCertification.php');
wprism_check(
    !str_contains($certSource, 'hash_equals(Canon::encode($platform), Canon::encode($statementTyped->platform))')
        && str_contains($certSource, 'private static function assertPlatformBinding('),
    'V3-AXIS LANDED (WP-4.7): certificate verification no longer compares the whole platform record byte-for-byte '
    . '— it binds the compatibility cells the certificate was exercised against'
);
$platformFields = array_keys($platform);
sort($platformFields, SORT_STRING);
$report('platform record fields a certificate bound BEFORE WP-4.7: ' . implode(', ', $platformFields));
$report(sprintf(
    'of those, %d are the compatibility axes it binds now; the other %d were bound for no exercised reason',
    count($axes),
    count($platformFields) - 1
));
$boundNow = (array) $cert->getConstant('STATEMENT_PLATFORM_KEYS');
wprism_check_same(
    ['agent_version', 'axes', 'site_mode', 'spec_version'],
    $boundNow,
    'V3-AXIS: and what it binds instead is four members — the axes, the two the boundary is GATED on, and '
    . '`agent_version`, which is recorded and deliberately not bound'
);
$report('platform members a certificate binds today: ' . implode(', ', $boundNow));

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

wprism_check_same(
    ['acf', 'core', 'elementor', 'polylang', 'redirection', 'woocommerce', 'yoast'],
    $unprefixed['adapter name'],
    'V3-NS: the seven unhyphenated shipped adapter names can be read as <vendor>-<name> under no reading'
);
wprism_check_same(
    count($idKinds),
    count($unprefixed['tables.*.id_kind']),
    'V3-NS: every shipped id_kind is underscore-separated, so the hyphen form would refuse the entire shipped vocabulary'
);
wprism_check_same(
    [],
    $unprefixed['providers[].id'],
    'V3-NS: every provider id is already hyphen-shaped with a plugin-slug first segment — the one space where the convention is de facto in force'
);

// The consequence for WP-4.10's design, measured rather than argued: a shape
// test cannot be the admission rule, because shipped names can be
// hyphen-shaped without being vendor-prefixed (`the-events-calendar` is not
// vendor `the`). The reserved list must therefore enumerate all of them.
$hyphenButNotVendor = array_values(array_filter(
    array_keys($shipped),
    static fn(string $n): bool => $hyphenShaped($n)
));
wprism_check_same(
    count($shipped) - count($unprefixed['adapter name']),
    count($hyphenButNotVendor),
    'V3-NS: every remaining name is hyphen-shaped but shape cannot prove its first segment is a vendor, so the closed reserved list must enumerate the whole shipped inventory'
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
wprism_check(
    $r17 !== '' && str_contains($r17, '`id_kind` is a flat, unprefixed namespace'),
    'V3-NS: the irreversibility register still carries R-17, the row that rules on this rule'
);
wprism_check(
    str_contains($r17, 'A convention (a vendor-shaped name) can be recommended to authors at any time; a RULE cannot be introduced'),
    'V3-NS: R-17 reserves the CONVENTION and refuses the RULE, so WP-4.10 can only grandfather — the ' . count($idKinds) . ' shipped id_kinds are not a break list, they are the permanent floor'
);
$report('id_kinds the flag day must grandfather: all ' . count($idKinds) . ' — wire-surface R-17: captured state and wprism_map rows embed the BARE kind, so a prefix rule would have to rewrite the customer\'s branches');

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
wprism_check_same([], $grammarRefusals, 'V3-NS: all 51 shipped identities already pass AdapterSources::assert_name(), so the namespace rule layers over one grammar');

// id_kind collisions are the correctness reason the namespace exists at all.
$collisions = array_values(array_filter(array_keys($idKinds), static fn(string $k): bool => count(array_unique($idKinds[$k])) > 1));
wprism_check_same([], $collisions, 'V3-NS: no id_kind is claimed by two shipped adapters today, so the rule grandfathers a collision-free set');

// ===========================================================================
// The flag-day break list, as one table.
// ===========================================================================

echo "\nFLAG-DAY BREAK LIST (shipped library only)\n";
$breakList = [
    'V3-KEYS  closed top-level key set' => count($shippedBreaks),
    'V3-FEAT  engine_features channel' => count($featureBreaks),
    'V3-DISP  per-adapter dispositions' => count($missingEntry) + count($unsafeNames) + count($canonUnstable),
    'V3-AXIS  per-adapter environment narrowing' => 0,
    'V3-NS    namespace prefixing (as a REFUSAL)' => count($unprefixed['adapter name']) + count($unprefixed['tables.*.id_kind']),
];
foreach ($breakList as $rule => $count) {
    $report(sprintf('%-45s %2d would refuse', $rule, $count));
}
$report('findings from this dry run, as resolved by § v3.3: F2 (theme_version_range) joined the partition; F3 (`_draft` emitted by adapter-draft) is refused at v3 on the merits, with "strip it" as the remedy');

wprism_check_same(
    0,
    $breakList['V3-KEYS  closed top-level key set']
        + $breakList['V3-FEAT  engine_features channel']
        + $breakList['V3-DISP  per-adapter dispositions']
        + $breakList['V3-AXIS  per-adapter environment narrowing'],
    'four of the five candidate rules refuse nothing in the shipped library; only V3-NS breaks it, which is why WP-4.10 grandfathers rather than refuses'
);
wprism_check_same(
    count($unprefixed['adapter name']) + count($idKinds),
    $breakList['V3-NS    namespace prefixing (as a REFUSAL)'],
    'V3-NS as a bare refusal would break every unhyphenated name and every id_kind — the measurement that forces the reserved closed list'
);

wprism_check_summary('spec v3 static dry run');
