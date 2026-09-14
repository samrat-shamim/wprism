<?php
/**
 * The spec-v3 section of `spec/repo-format.md`, held against the tree it
 * describes — rule by rule, each against its own `Enforced today:` line.
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * WP-4.1 writes the v3 delta as prose. Prose is the half of a wire format that
 * rots: `tools/capability-doc.php` and `tools/wire-surface.php` exist because
 * this project has already decided that a document nobody re-derives becomes
 * folklore, and both are byte-compared under `make release-gate`. A v3 section
 * cannot be GENERATED — it is a design argument, and the argument is the
 * deliverable — so it gets the other available discipline instead: every
 * measurable claim it makes is re-measured here from the shipped tree, and
 * every rule it states is asserted ENFORCED or UNENFORCED to match its own
 * `Enforced today:` line. WP-4.1's scope boundary was spec text and schema
 * emission only, so every rule started unenforced; riders turn them over one
 * by one, and this file is where a rider's claim to have done that is checked
 * against the engine.
 *
 * The two halves are deliberately different in kind:
 *
 *   PART 1 — THE SCOPE BOUNDARY, rule by rule. Facts about the shipped engine
 *   that say, per rule, exactly how much of v3 is in force. Four rules are
 *   turned over so far and asserted as ENFORCED here: the acceptance window
 *   (§ v3.1) and the `engine_features` channel (§ v3.2, WP-4.2 — exercised in
 *   depth by regress_spec_window.php); § v3.5's environment narrowing
 *   (WP-4.6 — enforced for a `spec_version: 3` manifest, inert for v2); and
 *   § v3.7's authority record v2 (WP-4.8 — gated on the authorities document's
 *   own format, with the v1 grammar every document in the field uses asserted
 *   untouched beside it). The rest are still asserted UNENFORCED — the define
 *   is still 2, the validator still admits an invented section, the
 *   disposition monolith is still one file, the certification signature domain
 *   is still /v1, the statement is still five members, the platform trust root
 *   is still empty, and reviewed shipped manifests now declare v3 only when
 *   consuming gated generic primitives. A rider that lands
 *   enforcement without moving this suite's expectations is a rider that
 *   landed silently, which is the failure this half prevents.
 *
 *   PART 2 — THE DOCUMENT. Every number and name the section states is read
 *   back out of the engine, the manifests, and the platform boundary: the
 *   32-key partition and its three arms, the five compatibility axes, the
 *   disposition monolith's measured size, the namespace census, the reserved
 *   refusal texts (which must NOT yet appear in shipped code), and the rider id
 *   each subsection carries. A subsection that loses its rider line, a count
 *   that drifts from the library, or a "reserved" refusal that quietly became
 *   real code all fail here.
 *
 * Nothing in this file parses the whole document. It reads named subsections by
 * their headings and asks specific questions of each, so ordinary editing of the
 * prose is free and only the load-bearing facts are pinned.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$repo = dirname(__DIR__, 4);
$spec = (string) file_get_contents($repo . '/spec/repo-format.md');

/** One indented report row (indented so the offline diagnostics guard can never read it as a PHP notice). */
$report = static function (string $line): void {
    echo '  ' . $line . "\n";
};

/**
 * The text of one `### v3.N …` subsection, heading included, or '' when absent.
 *
 * Returned with every run of whitespace collapsed to one space. Prose in this
 * file is hard-wrapped, so a sentence a reader sees whole is two lines on disk
 * — a phrase assertion against the raw bytes would be an assertion about where
 * the wrap happens to fall, which is not a fact anybody meant to pin.
 */
$section = static function (string $id) use ($spec): string {
    $pattern = '/^### ' . preg_quote($id, '/') . ' .*?(?=^### |^## |\z)/ms';
    if (preg_match($pattern, $spec, $m) !== 1) {
        return '';
    }
    return (string) preg_replace('/\s+/', ' ', $m[0]);
};

echo "\nPART 1 — THE SCOPE BOUNDARY: which v3 rules are in force, rule by rule\n";

// ---------------------------------------------------------------------------
// The defines. WP-4.1 changes neither, and AGENTS.md rule 8 binds them to
// platform.json, so both are read rather than one.
// ---------------------------------------------------------------------------
$wprismSource = (string) file_get_contents($repo . '/agent/wprism.php');
preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $wprismSource, $m);
$specVersion = (int) ($m[1] ?? 0);
preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $wprismSource, $m);
$agentVersion = (string) ($m[1] ?? '');
require_once $repo . '/agent/src/Policy/AdapterLibrary.php';
$adapterLibrary = \WPrism\AdapterLibrary::fromSourceTree($repo);

// WP-4.12 — THE FLIP. This assertion was `wprism_check_same(2, ...)` and it was
// the pin that kept every rider honest: a rider that moved the define would
// have failed here, by name. Its job is done and the pin now points the other
// way — the flip landed, so this suite asserts the NEW number with the same
// force, and the invariant that used to be "nothing moved the define" is now
// "the define moved and the library did not" (asserted below against the
// current 18-subject library).
wprism_check_same(3, $specVersion, 'WPRISM_SPEC_VERSION is 3 — WP-4.12 flipped it, and this is the one package authorized to');
wprism_check_same('0.7.0', $agentVersion, 'and WPRISM_AGENT_VERSION moved with it, in the same commit (AGENTS.md rule 8)');

$platform = json_decode((string) file_get_contents($adapterLibrary->platformBoundaryPath()), true);
$platform = is_array($platform['platform'] ?? null) ? $platform['platform'] : [];
wprism_check_same(
    [$specVersion, $agentVersion],
    [$platform['spec_version'] ?? null, $platform['agent_version'] ?? null],
    'platform.json still restates both defines unchanged (AGENTS.md rule 8: they move together or not at all)'
);

// ---------------------------------------------------------------------------
// Each v3 rule, asserted against the shipped trees to match its own
// `Enforced today:` line — enforced for § v3.1/§ v3.2, unenforced for the rest.
// ---------------------------------------------------------------------------
require_once $repo . '/agent/src/Kernel/Canon.php';
require_once $repo . '/agent/src/Kernel/OptionState.php';
require_once $repo . '/agent/src/Policy/Policy.php';
require_once $repo . '/agent/src/Policy/ManifestValidator.php';
require_once $repo . '/agent/src/Adapter/AdapterCertification.php';

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', $specVersion);
}
if (!defined('WPRISM_AGENT_VERSION')) {
    define('WPRISM_AGENT_VERSION', $agentVersion);
}

use WPrism\AdapterCertification;
use WPrism\AdapterContractGrammar;
use WPrism\Canon;
use WPrism\ManifestValidator;
use WPrism\Policy;

/** null = the shipped contract grammar accepts it; a string = its refusal. */
$contractVerdict = static function (array $manifest): ?string {
    try {
        AdapterContractGrammar::validate_adapter_contract($manifest);
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

// v3.1 — ENFORCED (WP-4.2). N-1 loads, and the two integers around the window
// refuse naming it. This is the one rule of the twelve whose flip could not
// wait for the version bump: an engine that installs the window only on the
// day it needs it has already had the flag day.
wprism_check_same(
    null,
    $contractVerdict(['name' => 'v3-window-probe', 'spec_version' => $specVersion - 1]),
    'v3.1 ENFORCED: a manifest declaring N-1 loads — the acceptance window, and the behaviour WP-4.2 added'
);
wprism_check_same(
    null,
    $contractVerdict(['name' => 'v3-window-probe', 'spec_version' => $specVersion]),
    'v3.1: and N itself still loads, so the probe is measuring the window and not something else'
);
$outside = $contractVerdict(['name' => 'v3-window-probe', 'spec_version' => $specVersion - 2]);
wprism_check(
    is_string($outside)
        && str_contains($outside, 'accepts spec_version {' . ($specVersion - 1) . ', ' . $specVersion . '}'),
    'v3.1: N-2 refuses WHOLESALE, naming the window — the floor is exactly N-1 and never deeper'
);
wprism_check(
    is_string($absent = $contractVerdict(['name' => 'v3-window-probe']))
        && str_contains($absent, 'requires spec_version ' . $specVersion),
    'v3.1: and an ABSENT spec_version keeps its own older refusal, because it is not a version and so is not outside anything'
);

// v3.2 — ENFORCED (WP-4.2). The channel now has exactly one reader across the
// three product trees (`agent`, `cli`, `recovery`); Adopt.php:212-226 embeds the
// manifest library in agent/ and archives `agent recovery`. A SECOND reader is the alarm this
// assertion exists for: it would mean the feature vocabulary acquired a
// consumer that could disagree with the one definition.
//
// COMMENTS ARE STRIPPED BEFORE THE MATCH (WP-6.1). A docblock that explains why
// a section rides this channel is not a reader — it cannot disagree with the
// vocabulary, because it never reads it. The scan was a plain str_contains()
// while `engine_features` appeared in exactly one file's prose and code alike;
// the first section actually shipped through the channel put the phrase in the
// docblocks of the collaborators that stage through it, and a text match would
// then have reported four "readers" and named none of them wrongly except in
// the only sense that matters. Tokenizing keeps the assertion measuring the
// thing it was written to measure.
$featureReaders = [];
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
        // Comments stripped first (WP-6.4): a file whose DOCBLOCK explains that
        // it is not a reader is not a reader, and rule 10 guarantees this tree
        // has such files. Same carve-out, same reason, as
        // `regress_platform_move_gates.php:33-35`.
        $body = wprism_code_without_comments((string) file_get_contents($file->getPathname()));
        if (str_contains($body, 'engine_features')) {
            $featureReaders[] = substr($file->getPathname(), strlen($repo) + 1);
        }
    }
}
sort($featureReaders, SORT_STRING);
// WP-6.2 added the second reader and the second feature, and both moves are
// § v3.2's channel doing its job rather than leaking. AdapterContractGrammar
// still owns the vocabulary and is still the only file that refuses an
// unimplemented name; ManifestGrammar reads a manifest's declared list for the
// one narrower question its feature-gated `invalidate[]` verbs turn on, which
// it cannot delegate upward across the module ladder.
// WP-6.5 added the third gate reader and the first PUBLISHER, and the two are
// different roles. BodyRefGrammar is ManifestGrammar's shape exactly: it owns
// the name `structured-body-refs/v1` and reads a manifest's declared list for
// the one narrower question its feature-gated `json` body MODE turns on, which
// it cannot delegate upward across the module ladder either. ManifestValidate
// is not a gate at all — it publishes the channel in `--emit-schema`'s grammar
// document, which until WP-6.5 could not describe the channel it documents.
// The property being ratcheted is unchanged: exactly one file OWNS the
// vocabulary and can refuse an unimplemented name.
// Scalar reference intersection adds one lower-layer declaration reader;
// it consumes the manifest's feature but owns no implementation roster.
// `derived-post-body/v1` adds the next gate reader, and it is BodyRefGrammar's
// shape exactly: DerivedBodyGrammar owns that one name and reads a manifest's
// declared list for the single narrower question its gated `derived` body MODE
// turns on — whether this document may spend it — which it cannot delegate
// upward across the module ladder. It publishes no roster and refuses no
// unimplemented name, so the ratcheted property is unchanged: exactly one file
// OWNS the vocabulary.
wprism_check_same(
    [
        'agent/src/Adapter/ActionProviderGrammar.php',
        'agent/src/Adapter/AdapterContractGrammar.php',
        'agent/src/Grammar/BodyRefGrammar.php',
        'agent/src/Grammar/ColumnCodecGrammar.php',
        'agent/src/Grammar/DerivedBodyGrammar.php',
        'agent/src/Kernel/BlockContentGrammar.php',
        'agent/src/Kernel/BlockValueGrammar.php',
        'agent/src/Kernel/ReferenceShapeGrammar.php',
        'agent/src/Kernel/TableRowScope.php',
        'agent/src/Policy/ManifestGrammar.php',
        'agent/src/Policy/Policy.php',
        'cli/src/Adapter/ManifestValidate.php',
    ],
    $featureReaders,
    'v3.2 ENFORCED: the channel has one shipped OWNER beside ten gate readers of exact declarations or '
        . 'interpreter ownership, and one publisher that consumes none'
);
// WP-6.4 moved this from one name to two, and the second is the assertion
// worth having: `spec-window/v1` claims only the channel's own key, so with it
// alone "declared and implemented admits the claimed key" is an argument about
// admissibility. `structured-evidence/v1` claims `declaration_evidence`, a
// section that did not exist when v3 was cut and that shipped with
// WPRISM_SPEC_VERSION unmoved (§ v3.14) — so the channel is a walked path.
// WP-6.5 made it six; manifest-provider-runtime/v1 made it seven, Redirection's
// measured mixed container made it eight, the bounded post-kind selector made
// it nine, schema-settlement/v1 made it ten, plugin-incompatibility/v1 made it
// eleven while claiming `incompatible_plugins`, and the fixed manifest-provider
// child protocol made it twelve. Scalar reference intersection made thirteen;
// native value validation makes fourteen, both without a new top-level section.
// The three JSON-body refinements make seventeen; independent provider row
// observation and mutation API requirements make nineteen; native option input
// witnessing makes twenty; native post-type and permalink readers make
// twenty-two, still without keys.
// Policy checks the exact interpreter owner's enrollment, not another vocabulary.
// The sixth section-claiming
// name remains `body_refs`
// (§ v3.20) — another section that did not exist when v3 was cut, shipped with
// WPRISM_SPEC_VERSION unmoved.
wprism_check_same(
    [
        'attr-id-codecs/v1',
        'block-attribute-groups/v1',
        'block-attribute-values/v1',
        'block-content-codecs/v1',
        'block-media-derivatives/v1',
        'block-record-fields/v1',
        'block-value-contracts/v1',
        'body-pii-paths/v1',
        'body-ref-preserve-type/v1',
        'body-url-rebinding/v1',
        'column-field-labels/v1',
        'column-field-templates/v1',
        'column-input-files/v1',
        'column-record-fields/v1',
        'column-value-cases/v1',
        'conditional-json-refs/v1',
        'derived-post-body/v1',
        'encoded-text-values/v1',
        'invalidate-vocabulary/v1',
        'json-column-codecs/v1',
        'key-bound-strings/v1',
        'manifest-provider-fresh-process/v1',
        'manifest-provider-runtime/v1',
        'mixed-column-codecs/v1',
        'native-value-validation/v1',
        'object-record-fields/v1',
        'php-container-values/v1',
        'plugin-incompatibility/v1',
        'post-kind-action-trigger/v1',
        'post-meta-invalidation/v1',
        'provider-filesystem-file-snapshot/v1',
        'provider-native-option-inputs/v1',
        'provider-native-permalinks/v1',
        'provider-native-post-types/v1',
        'provider-physical-table-rows/v1',
        'provider-typed-row-mutations/v1',
        'scalar-option-constraints/v1',
        'scalar-reference-intersection/v1',
        'schema-settlement/v1',
        'spec-window/v1',
        'structured-body-refs/v1',
        'structured-evidence/v1',
        'table-row-scope-sets/v1',
        'table-row-scopes/v1',
        'typed-column-codecs/v1',
        'typed-column-values/v1',
    ],
    AdapterContractGrammar::implemented_features(),
    'v3.2: the vocabulary carries the exact reviewed feature names independently of the spec version'
);
// WP-4.12: the channel OPENED. At WPRISM_SPEC_VERSION 2 this probe refused by
// SECTION NAME, because the section's own version (3) sat outside the window;
// at 3 it is inside, so a declaration at the engine's version now reaches the
// vocabulary and refuses by FEATURE name instead. Both refusals are the
// channel working — the difference is which question the engine got far enough
// to ask, and that difference IS the flip.
$unimplemented = $contractVerdict([
    'name' => 'v3-feature-probe',
    'spec_version' => $specVersion,
    'engine_features' => ['acme-thing/v1'],
]);
wprism_check(
    is_string($unimplemented) && str_contains($unimplemented, "engine feature 'acme-thing/v1'")
        && str_contains($unimplemented, 'does not implement it'),
    'v3.2 DECLARABLE: at WPRISM_SPEC_VERSION ' . $specVersion . ' the section is inside the window, so an '
        . 'unimplemented NAME refuses by feature — the channel opened with the flip and needs no second bump'
);
$sectionStaged = $contractVerdict([
    'name' => 'v3-feature-probe',
    'spec_version' => $specVersion - 1,
    'engine_features' => ['acme-thing/v1'],
]);
wprism_check(
    is_string($sectionStaged) && str_contains($sectionStaged, "the section 'engine_features'"),
    '...while at ' . ($specVersion - 1) . ' — the compatibility version retained by v2 manifests — it still refuses '
        . 'by SECTION name, which is why the flip changed no shipped behaviour'
);

// v3.3 — ENFORCED (WP-4.3), and the two halves that make it flag-day-safe. The
// v2 era is asserted UNCHANGED rather than merely assumed: the rule is gated at
// spec_version 3, so a v2 manifest declaring an invented section still loads,
// which is what keeps the behavior of every v2 manifest and its adapter digest
// unchanged.
$vocabulary = (array) (new ReflectionMethod(Policy::class, 'manifest_validator_vocabulary'))->invoke(null);
// WP-4.12: stamped at N-1, the version the whole shipped library declares.
// The rule is gated at spec_version 3 and the engine now IS 3, so the "still
// admits" measurement has to be taken where the library actually sits — that
// is the claim it supports (a v2 manifest changes no behaviour by a byte),
// and at the engine's own version the same bytes are correctly refused.
$invented = [
    'name' => 'v3-keys-probe',
    'spec_version' => $specVersion - 1,
    'options' => ['acme_probe_option' => ['class' => 'authored', 'autoload' => 'yes']],
    'totally_made_up_section' => ['acme_thing' => ['class' => 'authored']],
];
$manifestVerdict = static function (array $manifest) use ($vocabulary): ?string {
    try {
        ManifestValidator::validate_manifest($manifest, "manifest 'v3-keys-probe'", $vocabulary);
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
wprism_check(
    str_contains((string) $manifestVerdict($invented), "'totally_made_up_section'"),
    'v3.3 CLOSED at v' . ($specVersion - 1) . ': the manifest validator refuses a top-level section in no arm of the partition by name'
);
// WP-4.12: the v3 half IS walkable in this process now. Before the flip the
// window refused a spec_version 3 manifest one step before the key rule, so
// this suite could only assert that the refusal was the WINDOW's; the rule
// itself needed the synthetic N+1 engine in regress_closed_top_level_keys.php.
// The engine is now AT the gate, so the same bytes one version up refuse on the
// KEY, by name, through the shipped validator.
$inventedV3 = $invented;
$inventedV3['spec_version'] = $specVersion;
wprism_check(
    str_contains((string) $manifestVerdict($inventedV3), "'totally_made_up_section'"),
    'v3.3 LIVE at v' . $specVersion . ': the identical bytes one version up refuse on the KEY SET, naming the '
        . 'key — the flip made the rule reachable through the product path, and nothing else about it moved'
);
wprism_check(
    str_contains(
        (string) file_get_contents($repo . '/agent/src/Adapter/AdapterContractGrammar.php'),
        'private static function assert_top_level_keys('
    ),
    'v3.3 ENFORCED: the contract grammar carries the closed-key-set refusal for every accepted manifest version'
);
$ratify = (new ReflectionClass(AdapterCertification::class))->getMethod('siteRatification');
$signerVerdict = null;
try {
    $ratify->invoke(null, 'v3-keys-probe', $invented, 'v3 document suite');
} catch (\Throwable $e) {
    $signerVerdict = $e->getMessage();
}
wprism_check(
    is_string($signerVerdict) && str_contains($signerVerdict, 'which this signer cannot classify'),
    'v3.3: ...and the SIGNER still refuses the same manifest by name, at every version — the arm it cannot assign is what a certificate would have to cover'
);

// ONE DEFINITION, NOT TWO. WP-4.1 gave the partition a public accessor so
// `--emit-schema` could name the set; WP-4.3 made the contract grammar enforce
// through that same accessor. Two facts, measured separately: the three private
// constants must stay in ONE file (the definition), while the accessor is what
// every other file reads.
//
// Comments stripped first (WP-6.4), for the reason the identical carve-out in
// `regress_platform_move_gates.php:33-35` gives: a reader is a file whose CODE
// names the token, and rule 10 makes this tree full of files whose docblocks
// name it to explain why they are NOT one — `StructuredEvidence.php` says in
// prose that it deliberately takes no arm in this partition, and was counted
// here for saying so.
$namingFiles = static function (array $tokens) use ($repo): array {
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
            $body = wprism_code_without_comments((string) file_get_contents($file->getPathname()));
            foreach ($tokens as $token) {
                if (str_contains($body, $token)) {
                    $hits[] = substr($file->getPathname(), strlen($repo) + 1);
                    break;
                }
            }
        }
    }
    sort($hits, SORT_STRING);
    return $hits;
};
wprism_check_same(
    ['agent/src/Adapter/AdapterCertification.php'],
    $namingFiles(['ENTITY_SECTIONS', 'FIELD_SECTIONS', 'NON_SURFACE_KEYS']),
    'v3.3: the partition constants are declared in exactly one shipped file — enforcing the set did not copy it'
);
wprism_check_same(
    [
        'agent/src/Adapter/AdapterCertification.php',
        'agent/src/Adapter/AdapterContractGrammar.php',
        'cli/src/Adapter/ManifestValidate.php',
    ],
    $namingFiles(['topLevelKeyPartition']),
    'v3.3: and the accessor has three readers — the owner, the enforcing validator, and the emitter that publishes it'
);

// v3.4 — the LAYOUT is enforced (WP-4.4); the ADDRESSING is not (WP-4.5).
// Split into these two assertions on purpose: § v3.4 carries two riders, and a
// subsection whose "Enforced today:" line says "yes" about one half must not be
// readable as a claim about the other.
wprism_check(
    $adapterLibrary->packages() !== []
        && !file_exists($repo . '/manifests')
        && array_reduce(
            $adapterLibrary->packages(),
            static fn(bool $present, \WPrism\AdapterPackage $package): bool => $present && is_file($package->dispositionPath()),
            true
        ),
    'v3.4 layout ENFORCED (WP-4.4): each adapter package owns its reviewed disposition, the platform owns '
        . 'profiles, and the retired flat manifests tree is gone'
);
// Measured, not read: `registry_sha256` is still ONE hash over the WHOLE
// reassembled document, so editing any subject moves the number a contract
// pins even when it pins no adapter at all. WP-4.5 is the rider that narrows
// it to per-subject addressing.
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
$wholeRegistry = ['format' => \WPrism\ManifestDispositions::FORMAT, 'manifests' => [], 'profiles' => []];
foreach ($adapterLibrary->packages() as $package) {
    $wholeRegistry['manifests'][$package->name()] = (array) json_decode(
        (string) file_get_contents($package->dispositionPath()),
        true
    );
}
$wholeRegistry['profiles'] = (array) json_decode((string) file_get_contents($adapterLibrary->profilesPath()), true);
ksort($wholeRegistry['manifests'], SORT_STRING);
wprism_check(
    \WPrism\ManifestDispositions::load_library($adapterLibrary)?->sha256()
        === hash('sha256', Canon::encode($wholeRegistry)),
    'v3.4 addressing NOT enforced (WP-4.5): registry_sha256 is still one hash over the WHOLE reviewed document, so '
    . 'an edit to any subject still moves what a contract pinning no adapter observes'
);

// v3.5 — the one rule with shipped enforcement, and the two halves that make
// it flag-day-safe. WP-4.6 is why this suite's PART 1 is no longer uniformly
// "not enforced": the narrowing is live for a v3 manifest, and INERT for a v2
// one, so no shipped claim moved.
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
$narrowingPlatform = \WPrism\ManifestDispositions::platform_boundary_library($adapterLibrary);
$classicEditorPackage = $adapterLibrary->package('classic-editor');
$narrowingSubject = Canon::decode(Canon::read_file($classicEditorPackage?->manifestPath() ?? ''));
$narrowingEntry = (array) json_decode(
    (string) file_get_contents($classicEditorPackage?->dispositionPath() ?? ''),
    true
);
$narrowingEnvironment = static function (array $manifest) use ($narrowingPlatform, $narrowingEntry): array {
    return (array) \WPrism\ManifestDispositions::claim_from_disposition(
        $manifest,
        $narrowingEntry,
        (array) $narrowingEntry['evidence'],
        $narrowingPlatform
    )['environment_assumptions'];
};
$wholeBoundaryBytes = Canon::encode($narrowingEnvironment($narrowingSubject));
$v3Narrowing = $narrowingSubject;
$v3Narrowing['spec_version'] = 3;
$v3Narrowing['environment'] = ['php' => [array_key_first($narrowingPlatform['compatibility']['php']['verified'])]];
wprism_check(
    Canon::encode($narrowingEnvironment($v3Narrowing)) !== $wholeBoundaryBytes,
    'v3.5 ENFORCED: a spec_version 3 manifest declaring `environment` narrows its own claim (WP-4.6)'
);
// WP-4.12: N-1, not "this engine's own spec version". Those were the same
// number before the flip; the engine now sits AT the narrowing gate, so the
// inert arm has to be read where the shipped library sits — which is the
// population the claim is about.
$v2Narrowing = $v3Narrowing;
$v2Narrowing['spec_version'] = $specVersion - 1;
wprism_check_same(
    $wholeBoundaryBytes,
    Canon::encode($narrowingEnvironment($v2Narrowing)),
    'v3.5 INERT at v' . ($specVersion - 1) . ': the same declaration at the retained compatibility version projects the whole boundary, byte for byte'
);
wprism_check(
    in_array('environment', AdapterCertification::topLevelKeyPartition()['non_surface_keys'], true),
    'v3.5 x v3.3: the narrowing channel joined the partition as a non-surface key, in the change that reads it'
);

// v3.6/v3.7 — the two subsections whose enforcement has landed on the
// certificate wire. Their assertions flip with the riders rather than staying
// silently true; what must remain true is the SHAPE of each exception.
$cert = new ReflectionClass(AdapterCertification::class);
wprism_check_same(
    "wprism-site-adapter-certification-signature/v2\0",
    (string) $cert->getConstant('SIGNATURE_DOMAIN'),
    'v3.6 ENFORCED (WP-4.7): SIGNATURE_DOMAIN is /v2, because the binding semantics changed — a new domain is a '
    . 'NEW statement type verified beside the old one (register row R-01), never an edit of it'
);
wprism_check_same(
    2,
    $cert->getConstant('STATEMENT_VERSION'),
    'and the generation is stated INSIDE the signed statement, so the next wire change is refused BY VERSION '
    . 'rather than read as corruption (R-24)'
);
// v3.7 IS enforced (WP-4.8) — the one exception, and the assertions flip with
// it rather than staying silently true. What must remain true is the SHAPE of
// the exception: v2 is a second format verified beside v1, gated on the
// authorities document rather than on `spec_version`, so nothing a v1 document
// says is read differently than it is today.
wprism_check_same(
    'wprism-adapter-authorities/v1',
    (string) $cert->getConstant('AUTHORITIES_FORMAT'),
    'v3.7 ENFORCED, and v1 is untouched: the v1 envelope constant is still wprism-adapter-authorities/v1, so '
    . 'every document in the field keeps today\'s grammar'
);
wprism_check_same(
    'wprism-adapter-authorities/v2',
    (string) $cert->getConstant('AUTHORITIES_FORMAT_V2'),
    'and v2 is a SECOND format verified beside it, never a widened v1 — the extension channel R-10 names'
);
wprism_check_same(
    "wprism-adapter-authorities-signature/v1\0",
    (string) $cert->getConstant('SIGNATURE_DOMAIN_AUTHORITIES'),
    'the v2 envelope signature is its own domain-separated statement type, NUL-terminated like both others '
    . '(register rows R-01/R-04), never an arm inside the certification verifier'
);
$v37Body = $section('v3.7');
wprism_check(
    str_contains($v37Body, 'Enforced today: YES'),
    'and § v3.7 says so in its own Enforced-today line: a rider that landed enforcement without moving this '
    . 'line would have landed silently, which is exactly what PART 1 exists to prevent'
);
$certSource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterCertification.php');
wprism_check(
    str_contains($certSource, 'private static function assertAuthorityWindow(')
        && str_contains($certSource, '$now ?? time()')
        && str_contains($certSource, 'refuses rather than resurrecting an expired record'),
    'v3.7 change (b) is shipped code: a window judged against the host\'s own `$now ?? time()`, with the '
    . 'implausible-clock refusal the section states'
);
wprism_check(
    !str_contains($certSource, 'hash_equals(Canon::encode($authority), Canon::encode($embedded'),
    'v3.7 change (e) is shipped: the whole-record platform binding is gone, so both roots bind the key '
    . 'identity and a growing trust root no longer invalidates its own earlier certificates'
);
wprism_check_same(
    ['adapter', 'authority', 'bundle', 'platform', 'ratification', 'version'],
    (array) $cert->getConstant('STATEMENT_KEYS'),
    'v3.6 ENFORCED: the signed statement grew the ONE member v1 could not grow — `version` — and R-06 closes the '
    . 'six in both directions'
);
wprism_check(
    !str_contains($certSource, 'hash_equals(Canon::encode($platform), Canon::encode($statementTyped->platform))')
        && str_contains($certSource, 'private static function assertPlatformBinding('),
    'v3.6 ENFORCED: the whole-platform byte comparison is GONE, replaced by a binding over the exercised '
    . 'compatibility cells — the change that stops every agent release from withdrawing every certificate'
);
wprism_check_same(
    [],
    array_values(array_intersect(
        ['code_digest', 'delegated_authority'],
        (array) $cert->getConstant('STATEMENT_KEYS')
    )),
    'v3.10 ENFORCED WITHOUT MOVING THE WIRE (WP-4.11): the two reserved statement members are refusals that '
    . 'NAME the member and its gate, never admitted keys — so STATEMENT_KEYS is still the six R-06 closes, '
    . 'every statement already signed keeps its exact canonical bytes, and no certificate in the field moves'
);
$authorities = json_decode((string) file_get_contents($adapterLibrary->authoritiesPath()), true);
wprism_check_same(
    ['format' => 'wprism-adapter-authorities/v1', 'keys' => []],
    is_array($authorities) ? $authorities : [],
    'v3.7: the platform trust root is still EMPTY — the flag day\'s standing precondition, re-checked here on every run'
);

// v3.8 IS enforced (WP-4.9), and it is gated on TWO DOCUMENTS THAT DO NOT
// EXIST rather than on `spec_version` — so what has to stay true is the shape
// of that gate: both documents absent, both statement kinds domain-separated,
// and the certification wire untouched by either.
$v38Body = $section('v3.8');
wprism_check(
    str_contains($v38Body, 'Enforced today: YES'),
    'and § v3.8 says so in its own Enforced-today line: a rider that landed a certificate authority without '
    . 'moving this line would have landed silently, which is exactly what PART 1 exists to prevent'
);
wprism_check_same(
    "wprism-adapter-authority-delegation-signature/v1\0",
    (string) $cert->getConstant('SIGNATURE_DOMAIN_DELEGATION'),
    'v3.8 ENFORCED: a delegation is its own NUL-terminated domain-separated statement kind (register rows '
    . 'R-01/R-04), never a new arm inside the certification verifier'
);
wprism_check_same(
    "wprism-adapter-authority-revocation-signature/v1\0",
    (string) $cert->getConstant('SIGNATURE_DOMAIN_REVOCATION'),
    'and a revocation is a THIRD kind, because its subject set is larger than a delegation\'s — it can name a '
    . 'key nobody delegated'
);
$v38Domains = [
    (string) $cert->getConstant('SIGNATURE_DOMAIN'),
    (string) $cert->getConstant('SIGNATURE_DOMAIN_AUTHORITIES'),
    (string) $cert->getConstant('SIGNATURE_DOMAIN_DELEGATION'),
    (string) $cert->getConstant('SIGNATURE_DOMAIN_REVOCATION'),
];
$v38Prefixes = [];
foreach ($v38Domains as $one) {
    foreach ($v38Domains as $other) {
        if ($one !== $other && str_starts_with($other, rtrim($one, "\0"))) {
            $v38Prefixes[] = $one;
        }
    }
}
wprism_check_same(
    [],
    $v38Prefixes,
    'and no one of the four adapter-side domains is a prefix of another: the property that stops a delegation '
    . 'being replayed as the authority half of a certificate'
);
wprism_check(
    !file_exists($adapterLibrary->revocationsPath())
        && array_values(array_filter(
            $adapterLibrary->scanFiles(),
            static fn(string $path): bool => basename($path) === 'delegations.json'
        )) === [],
    'v3.8: BOTH gating documents are absent from the shipped library — the out-of-band revocation record and '
    . 'the delegation document — which is why an agent that meets neither behaves exactly as it does today'
);
wprism_check(
    str_contains($certSource, 'private static function delegatedKeys(')
        && str_contains($certSource, 'private const DELEGATION_DEPTH = 1;')
        && str_contains($certSource, 'which is itself a delegate')
        && str_contains($certSource, ' level and a delegate may not delegate'),
    'v3.8 depth-1 is shipped code, and the bound is in the VERIFIER as a constant rather than a policy: a '
    . 'delegator that is itself a delegate is refused by name rather than by failing to resolve'
);
wprism_check(
    str_contains($certSource, 'private static function assertNotRevoked(')
        && str_contains($certSource, 'This channel reaches the frozen path, which a status flip in the operator'),
    'v3.8 typed revocation is shipped code, and its refusal STATES THE DISTINCTION the section promises — an '
    . 'operator can tell which of the two revocation mechanisms answered'
);
$v38Register = (string) file_get_contents($repo . '/docs/wire-surface.md');
wprism_check(
    str_contains($v38Register, '### R-25 — Delegation is depth-1')
        && str_contains($v38Register, '### R-26 — Typed revocation')
        && str_contains($v38Body, 'Register rows R-25 and R-26'),
    'and both register rows exist and are the ones § v3.8 names: a new signed surface without a register row '
    . 'is the exact failure docs/wire-surface.md is generated to prevent'
);

// The no-BULK-restamp rule made the bump digest-neutral. Later, reviewed
// per-adapter migrations each pay their own digest change for capability gained
// and leave unrelated manifests at the pre-flip version.
$declaredVersions = [];
foreach ($adapterLibrary->packages() as $package) {
    $decoded = Canon::decode(Canon::read_file($package->manifestPath()));
    $declaredVersions[$package->name()] = $decoded['spec_version'] ?? null;
}
ksort($declaredVersions, SORT_STRING);
$currentVersionPackages = array_keys(array_filter(
    $declaredVersions,
    static fn($version): bool => $version === $specVersion
));
$priorVersionPackages = array_keys(array_filter(
    $declaredVersions,
    static fn($version): bool => $version === $specVersion - 1
));
wprism_check(
    $currentVersionPackages !== []
        && $priorVersionPackages !== []
        && count($currentVersionPackages) + count($priorVersionPackages) === count($declaredVersions),
    'every discovered manifest stays inside the two-version window, while both the current feature format and '
        . 'the digest-neutral prior format remain exercised without a central adapter-name registry'
);

echo "\nPART 2 — THE DOCUMENT: every measurable claim re-measured from the tree\n";

// ---------------------------------------------------------------------------
// Structure: the section, its subsections, and the rider each one names.
// ---------------------------------------------------------------------------
wprism_check(
    str_contains($spec, '## Spec v3 — the windowed format (IN FORCE)'),
    'the spec carries the v3 section, and its heading states the status in the heading itself — WP-4.12 '
        . 'ended the "NOT YET IN FORCE" era where it was accurate to'
);

$subsections = ['v3.1', 'v3.2', 'v3.3', 'v3.4', 'v3.5', 'v3.6', 'v3.7', 'v3.8', 'v3.9', 'v3.10', 'v3.11', 'v3.12'];
$missing = array_values(array_filter($subsections, static fn(string $id): bool => $section($id) === ''));
wprism_check_same([], $missing, 'all twelve v3 subsections are present');

// THE DEFERRED-ROWS DISCIPLINE APPLIED TO A SPEC: every rule-bearing subsection
// says which work package implements it and what the engine does today, so a
// reader can tell a kept contract from a promised one without leaving the page.
$riders = [
    'v3.1' => 'WP-4.2',
    'v3.2' => 'WP-4.2',
    'v3.3' => 'WP-4.3',
    'v3.4' => 'WP-4.4',
    'v3.5' => 'WP-4.6',
    'v3.6' => 'WP-4.7',
    'v3.7' => 'WP-4.8',
    'v3.8' => 'WP-4.9',
    'v3.9' => 'WP-4.10',
    'v3.10' => 'WP-4.11',
];
$riderGaps = [];
foreach ($riders as $id => $rider) {
    $body = $section($id);
    // `Rider:` heads a subsection with one implementer; `Riders:` heads v3.4,
    // whose layout and addressing halves are two work packages that must land
    // together. Both spellings are the same claim: this rule names who builds
    // it.
    $named = str_contains($body, '**Rider: ' . $rider) || str_contains($body, '**Riders: ' . $rider);
    if (!$named || !str_contains($body, 'Enforced today:')) {
        $riderGaps[$id] = $rider;
    }
}
wprism_check_same([], $riderGaps, 'every rule-bearing subsection carries its implementing rider id and an "Enforced today:" line');
wprism_check(
    str_contains($section('v3.4'), 'WP-4.5') && str_contains($section('v3.12'), 'WP-4.12')
        && str_contains($section('v3.11'), 'WP-7.1'),
    'the three riders that do not head their own subsection are named where they belong (WP-4.5 with the registry pins, WP-4.12 with the flip, WP-7.1 with the shut lane)'
);
$v3Text = preg_match('/^## Spec v3 .*?(?=^## )/ms', $spec, $m) === 1 ? $m[0] : '';
$namedRiders = [];
if (preg_match_all('/WP-4\.\d+/', $v3Text, $found) > 0) {
    $namedRiders = array_values(array_unique($found[0]));
}
sort($namedRiders, SORT_NATURAL);
wprism_check_same(
    ['WP-4.10', 'WP-4.11', 'WP-4.12', 'WP-4.2', 'WP-4.3', 'WP-4.4', 'WP-4.5', 'WP-4.6', 'WP-4.7', 'WP-4.8', 'WP-4.9'],
    (static function (array $ids): array {
        sort($ids, SORT_STRING);
        return $ids;
    })($namedRiders),
    'all eleven riders WP-4.2 … WP-4.12 are named in the section — none of the flag day is specified anonymously'
);

// ---------------------------------------------------------------------------
// v3.3 — the partition, name for name, against the engine constant.
// ---------------------------------------------------------------------------
$partition = AdapterCertification::topLevelKeyPartition();
$keysBody = $section('v3.3');
$arms = [
    'entity sections' => ['count' => count($partition['entity_sections']), 'keys' => $partition['entity_sections']],
    'field sections' => ['count' => count($partition['field_sections']), 'keys' => $partition['field_sections']],
    'non-surface keys' => ['count' => count($partition['non_surface_keys']), 'keys' => $partition['non_surface_keys']],
];
$armGaps = [];
foreach ($arms as $label => $arm) {
    if (!str_contains($keysBody, '**' . $label . '** (' . $arm['count'])) {
        $armGaps[] = $label;
    }
    foreach ($arm['keys'] as $key) {
        if (!str_contains($keysBody, '`' . $key . '`')) {
            $armGaps[] = $label . '.' . $key;
        }
    }
}
wprism_check_same(
    [],
    $armGaps,
    'v3.3 lists all ' . count(array_merge(...array_values($partition)))
        . ' partition keys under their own arms, with each arm\'s count matching the engine constant'
);
$report(sprintf(
    'partition as published: %d entity + %d field + %d non-surface = %d keys',
    count($partition['entity_sections']),
    count($partition['field_sections']),
    count($partition['non_surface_keys']),
    count($partition['entity_sections']) + count($partition['field_sections']) + count($partition['non_surface_keys'])
));

// The two reviewed gap decisions still have live subjects: the spec resolves
// them, so if either subject disappeared the resolution would be describing
// nothing.
wprism_check(
    str_contains($keysBody, '`theme_version_range` JOINS the partition')
        && in_array('theme_version_range', array_merge(...array_values($partition)), true),
    'v3.3 resolves the `theme_version_range` gap, and the key is indeed IN the shipped partition now (WP-4.3, resolution 1)'
);
$grammarSource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterContractGrammar.php');
wprism_check(
    str_contains($grammarSource, "['theme', 'theme_version_range']"),
    'and the shipped grammar still makes `theme_version_range` mandatory beside `theme`, which is the constraint the resolution reconciled the partition with'
);
wprism_check(
    str_contains($keysBody, '`_draft` is REFUSED at v3')
        && str_contains((string) file_get_contents($repo . '/cli/src/Adapter/AdapterDraft.php'), "\$manifest['_draft'] = self::build_draft("),
    'v3.3 resolves the `_draft` gap as a refusal, and `wprism adapter-draft` does still emit that key'
);

// ---------------------------------------------------------------------------
// v3.5 — the one subsection whose "Enforced today:" line says yes, and the
// three facts a reader must be able to check without leaving the page: which
// axes are narrowable, that v2 is inert, and that the load-time gate is
// untouched. Asserted as text because the flip of that line is the deliverable
// half of WP-4.6 that no engine constant can hold.
// ---------------------------------------------------------------------------
$narrowingBody = $section('v3.5');
wprism_check(
    str_contains($narrowingBody, 'Enforced today: yes, for a `spec_version: 3` manifest; inert for v2.'),
    'v3.5 states its enforcement precisely — live at v3, inert at v2 — rather than a bare "yes"'
);
$narrowableGaps = array_values(array_filter(
    ['database', 'php', 'site_mode', 'wordpress'],
    static fn(string $axis): bool => !str_contains($narrowingBody, '`' . $axis . '`')
));
wprism_check_same([], $narrowableGaps, 'v3.5 names all four narrowable axes, which are exactly the four members a claim states');
wprism_check(
    str_contains($narrowingBody, '`filesystem` and `process` are load-time profiles that no claim states'),
    'and says why the other two compatibility axes are NOT narrowable, so their absence is a decision rather than an omission'
);
wprism_check(
    str_contains($narrowingBody, 'JOINS § v3.3\'s partition as a non-surface key'),
    'v3.5 records that the channel joined the closed key set in the same change, which is what keeps a narrowing adapter signable'
);

// ---------------------------------------------------------------------------
// v3.4 — the split topology follows package discovery. Source byte totals
// change with ordinary capsule edits and are not another adapter inventory.
// ---------------------------------------------------------------------------
$dispositionDocuments = array_map(
    static fn(\WPrism\AdapterPackage $package): string => $package->dispositionPath(),
    $adapterLibrary->packages()
);
$dispositionDocuments[] = $adapterLibrary->profilesPath();
sort($dispositionDocuments, SORT_STRING);
$documentCount = count($dispositionDocuments);
$entryCount = 0;
$profileNames = [];
foreach ($dispositionDocuments as $document) {
    $raw = (string) file_get_contents($document);
    if ($document === $adapterLibrary->profilesPath()) {
        $profileNames = array_keys((array) json_decode($raw, true));
        continue;
    }
    $entryCount++;
}
sort($profileNames, SORT_STRING);
$dispositionBody = $section('v3.4');
wprism_check(
    str_contains($dispositionBody, 'one disposition per adapter, plus the profiles document')
        && str_contains($dispositionBody, 'adapter-packages/<name>/package/disposition.json')
        && str_contains($dispositionBody, 'platform/adapter-library/core/disposition.json'),
    'v3.4 describes the discovered capsule/core disposition topology'
);
wprism_check_same(count($adapterLibrary->packages()), $entryCount, 'every discovered subject contributes one disposition');
wprism_check_same($entryCount + 1, $documentCount, 'the profile map is the only additional document');
wprism_check_same(['fse'], $profileNames, 'and `profiles` is the one row the split gave its own document');

// ---------------------------------------------------------------------------
// v3.6 — the compatibility axes a v3 certificate would bind.
// ---------------------------------------------------------------------------
$axes = array_keys((array) ($platform['compatibility'] ?? []));
sort($axes, SORT_STRING);
$axisBody = $section('v3.6');
$axisGaps = array_values(array_filter($axes, static fn(string $axis): bool => !str_contains($axisBody, '`' . $axis . '`')));
wprism_check_same([], $axisGaps, 'v3.6 names every compatibility axis the boundary declares (' . implode(', ', $axes) . ')');
wprism_check(
    str_contains($axisBody, 'five as of #560'),
    'and states how many there are, so a sixth axis is a reviewed sentence here rather than a silent widening'
);

// ---------------------------------------------------------------------------
// v3.9 — the namespace census, re-counted.
// ---------------------------------------------------------------------------
$names = [];
$idKinds = [];
$providerIds = [];
foreach ($adapterLibrary->packages() as $package) {
    $manifest = Canon::decode(Canon::read_file($package->manifestPath()));
    $names[] = $package->name();
    foreach ((array) ($manifest['tables'] ?? []) as $rule) {
        if (is_array($rule) && is_string($rule['id_kind'] ?? null)) {
            $idKinds[$rule['id_kind']] = true;
        }
    }
    foreach ((array) ($manifest['providers'] ?? []) as $provider) {
        if (is_array($provider) && is_string($provider['id'] ?? null)) {
            $providerIds[$provider['id']] = true;
        }
    }
}
sort($names, SORT_STRING);
$hyphenShaped = static fn(string $value): bool => preg_match('/^[a-z0-9]+-[a-z0-9-]+$/D', $value) === 1;
$unprefixedNames = array_values(array_filter($names, static fn(string $n): bool => !$hyphenShaped($n)));
$unprefixedKinds = array_values(array_filter(array_keys($idKinds), static fn(string $k): bool => !$hyphenShaped($k)));
$identityTotal = count($names) + count($idKinds) + count($providerIds);
$breakList = count($unprefixedNames) + count($unprefixedKinds);
$report(sprintf(
    'identity census: %d adapter names + %d id_kinds + %d provider ids = %d; bare-refusal break list = %d',
    count($names),
    count($idKinds),
    count($providerIds),
    $identityTotal,
    $breakList
));
$nsBody = $section('v3.9');
wprism_check(
    str_contains($nsBody, 'generated wire register')
        && str_contains($nsBody, 'regress_spec_v3_dry_run.php'),
    'v3.9 delegates the evolving census to discovery and the generated reservation inventory'
);
$generatedRegister = (string) file_get_contents($repo . '/docs/wire-surface.md');
foreach ($unprefixedNames as $name) {
    wprism_check(str_contains($generatedRegister, '`' . $name . '`'), "$name appears in the generated reservation register");
}
wprism_check(
    str_contains($nsBody, 'R-17'),
    'v3.9 defers to the irreversibility register\'s R-17 rather than re-deciding `id_kind` prefixing'
);
$register = (string) file_get_contents($repo . '/docs/wire-surface.md');
wprism_check(
    str_contains($register, '### R-17 — `id_kind` is a flat, unprefixed namespace'),
    'and R-17 is still the register row it defers to (a renamed row would leave this rule resting on nothing)'
);

// v3.9 — ENFORCED for names and provider ids (WP-4.10), and NEVER for
// `id_kind`. The subsection makes three claims with shipped consequences, and
// each is asserted against the engine rather than read back out of the prose.
require_once $repo . '/agent/src/Adapter/IdentityNamespaces.php';
wprism_check(
    str_contains($nsBody, 'Enforced today: for names and provider ids, yes at `spec_version: 3`; inert at v2.')
        && str_contains($nsBody, 'For `id_kind`, never'),
    'v3.9\'s Enforced-today line states the split its rider actually landed: a rule for two of the three '
    . 'spaces and none for the third'
);
$nsVerdict = static function (array $manifest, string $name): ?string {
    try {
        \WPrism\IdentityNamespaces::assert_out_of_tree_identity($manifest, $name, 'site adapter', "'adapters/$name.json'");
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
$nsFixture = static fn(string $name, ?int $spec, array $extra = []): array => $extra + array_filter([
    'name' => $name,
    'spec_version' => $spec,
], static fn($v): bool => $v !== null);
// WP-4.12: the probe pair is now (N-1, N) rather than (N, 3), because the flip
// put the engine ON the gate. Both halves still exist and both still matter —
// the inert half is where v2-stamped shipped manifests and every pre-flip
// out-of-tree adapter sit. PMPro deliberately reaches the live half only after
// its identity-moving migration was reviewed.
wprism_check(
    $nsVerdict($nsFixture('cache', $specVersion - 1), 'cache') === null
        && is_string($nsVerdict($nsFixture('cache', $specVersion), 'cache')),
    'and the engine agrees on both halves of that line: an unprefixed out-of-tree name is inert at '
    . 'spec_version ' . ($specVersion - 1) . ' and refuses at ' . $specVersion . ', so the rule reaches only '
    . 'documents that deliberately declare the new version'
);
wprism_check(
    $nsVerdict($nsFixture('acme-cache', 3, [
        'tables' => ['acme_widget' => ['class' => 'authored_snapshot', 'id_kind' => 'acme_widget']],
    ]), 'acme-cache') === null,
    'while an unprefixed `id_kind` inside a v3 manifest is still accepted — R-17 reserves the convention and '
    . 'refuses the rule, and this is the assertion that fails if a later rider quietly overrules it'
);
$nsListed = \WPrism\IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES;
wprism_check_same(
    $names,
    $nsListed,
    'the closed grandfather list § v3.9 describes carries exactly the ' . count($names)
    . ' shipped adapter names — the enumeration the census above says a shape test cannot replace'
);
wprism_check(
    str_starts_with(
        (string) realpath((string) (new ReflectionClass(\WPrism\IdentityNamespaces::class))->getFileName()),
        (string) realpath($repo . '/agent/src')
    ),
    'and it lives in agent/src as the subsection requires, not under an adapter package where rule 2 would fold it '
    . 'into every adapter digest'
);
wprism_check(
    str_contains($register, '### R-27 — The reserved `<vendor>-` form, and the closed grandfather list under it'),
    'R-27 is the register row that records the decision, so the closed list is reviewable beside the other '
    . 'irreversible ones rather than only in code'
);

// ---------------------------------------------------------------------------
// v3.10 — the reserved slots, now SHIPPED as refusals (WP-4.11). Each pinned
// text is asserted in both directions: the spec states it, and the shipped
// trees contain it. The direction flipped with the rider — WP-4.1 wrote the
// texts down and this suite proved they were NOT yet behaviour; WP-4.11 shipped
// them and the same list now proves they ARE. A text present in one place and
// absent from the other is the drift this pair exists to catch, and the exact
// refusals are driven end to end in
// sandbox/tests/offline/adapter/regress_v3_reservations.php.
// ---------------------------------------------------------------------------
$reservationBody = $section('v3.10');
// The three that still refuse. The two reviewer-tier texts left this list when
// WP-5.2 opened their slots (§ v3.16) and moved to $retired below: a sentence
// no shipped tree raises any more cannot be asserted as shipped refusal text,
// and asserting it anyway is how a "reserved" claim quietly becomes false.
$reserved = [
    'the executable adapter lane is reserved and shut' => 'G5',
    'a signed binding over adapter code is reserved and shut' => 'G5',
    'delegated authority is verified through its own signed delegation document' => 'v3.8',
];
$reservationGaps = [];
$missingFromCode = [];
$shippedSource = '';
foreach (['agent/src', 'cli/src', 'recovery'] as $tree) {
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repo . '/' . $tree, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($walk as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $shippedSource .= (string) file_get_contents($file->getPathname());
        }
    }
}
foreach ($reserved as $text => $gate) {
    if (!str_contains($reservationBody, $text)) {
        $reservationGaps[] = $text;
    }
    if (!str_contains($shippedSource, $text)) {
        $missingFromCode[] = $text;
    }
}
wprism_check_same([], $reservationGaps, 'v3.10 pins all three still-reserved refusal texts, each naming the gate that would open it');
wprism_check_same(
    [],
    $missingFromCode,
    'and every one of them is SHIPPED refusal text now (WP-4.11) — a pinned message the spec states and the '
    . 'engine cannot produce would be a reservation an author is told about and the engine never keeps'
);
// THE OPPOSITE DIRECTION, and it is the one WP-5.2 made necessary. The two
// retired sentences must still be PUBLISHED (a v3-era host says them, and an
// operator meeting one has to recognise the bytes) and must NOT be findable in
// any shipped tree (this engine admits both slots now). A section that kept the
// prose while the engine kept the refusal, or vice versa, is the drift this
// pair of checks exists to catch — in whichever direction it happens.
$retired = [
    'the reviewer tier opens at gate G4' => 'G4',
    'the reviewer evidence member is reserved' => 'G4',
];
$retiredGaps = [];
$stillRefusing = [];
foreach ($retired as $text => $gate) {
    if (!str_contains($reservationBody, $text)) {
        $retiredGaps[] = $text;
    }
    if (str_contains($shippedSource, $text)) {
        $stillRefusing[] = $text;
    }
}
wprism_check_same(
    [],
    $retiredGaps,
    'v3.10 still PUBLISHES the two sentences WP-5.2 retired, as what a v3-era reader answers — deleting them '
    . 'would leave live field bytes undocumented'
);
wprism_check_same(
    [],
    $stillRefusing,
    'and no shipped tree raises either of them any more: the slots are OPEN (§ v3.16), so a surviving copy '
    . 'would be an engine still refusing what the spec says it admits'
);
wprism_check(
    str_contains($reservationBody, 'Enforced today: YES')
        && str_contains($reservationBody, 'OPENED at gate G4 by WP-5.2'),
    'and § v3.10 says both halves in its own Enforced-today line: still enforced for what remains reserved, '
    . 'OPENED for what WP-5.2 flipped — a rider that moved either without moving this line would have landed '
    . 'silently, which is exactly what PART 1 exists to prevent'
);
// THE FLAG-DAY INVARIANT, re-measured on the one surface this rider touched
// that a stranger already holds bytes of. Reserving a statement member as a
// REFUSAL rather than as an admitted key is what keeps it true: the closed set
// is the same six, so `Canon::encode(statement)` — the exact preimage every
// deployed verifier recomputes — is unchanged for every certificate in the
// field. regress_v3_reservations.php pins the bytes and the signature.
wprism_check_same(
    ['adapter', 'authority', 'bundle', 'platform', 'ratification', 'version'],
    (array) $cert->getConstant('STATEMENT_KEYS'),
    'v3.10 costs the wire NOTHING: the signed statement is the same six members it was before the rider'
);
wprism_check(
    str_contains($reservationBody, 'version_range_graduated')
        && str_contains($shippedSource, 'version_range_graduated'),
    'v3.10 records the graduated verdict as ALREADY DELIVERED (WP-2.8) rather than reserving it again, and the shipped word backs that up'
);
// The plan WP-4.11 rode listed five reservations; WP-2.8 had already shipped
// the fifth. The subsection resolves that by pointing at the shipped word, and
// it still has to say so: WP-5.2 opened two SLOTS and changed nothing about
// which word was never a slot at all, so a section that quietly dropped this
// sentence would re-open the door to re-reserving a shipped verdict.
wprism_check(
    str_contains($reservationBody, 'reserved four slots and not five'),
    'and it still records WP-4.11\'s own count — four reserved slots, the fifth delivered as a word — which is '
    . 'a fact about that rider and is not changed by a later one opening two of them'
);
wprism_check(
    str_contains($register, '### R-28 — The reserved slots are REFUSALS, never admitted members'),
    'R-28 is the register row that records the decision, so a reservation on a signed surface is reviewable '
    . 'beside the other irreversible ones rather than only in a subsection nobody diffs'
);

// ---------------------------------------------------------------------------
// v3.11 — the exec-lane gate's evidence contract, as spec text.
// ---------------------------------------------------------------------------
$laneBody = $section('v3.11');
$conditionNumbers = [];
if (preg_match_all('/(?:^|\s)(\d+)\. \*\*/', $laneBody, $numbered) > 0) {
    $conditionNumbers = array_map('intval', $numbered[1]);
}
wprism_check_same(
    [1, 2, 3, 4, 5, 6, 7],
    $conditionNumbers,
    'v3.11 records exactly seven numbered conditions for gate G5, in order — the evidence contract lives in the spec so opening the lane is a reviewed change'
);
wprism_check(
    str_contains($laneBody, 'The default answer is NO')
        && str_contains($laneBody, 'ALL SEVEN')
        && str_contains($laneBody, 'never a relaxed gate'),
    'and it states the default, the conjunction, and what happens when a condition is unmet'
);
wprism_check(
    str_contains($laneBody, 'assert_out_of_tree_contract()')
        && str_contains($shippedSource, 'function assert_out_of_tree_contract('),
    'v3.11 names the shipped refusal that keeps the lane shut today, and that refusal exists'
);

// Manifest-shipped hook code is derived from the package library itself. The
// gate deliberately owns no checked-in per-adapter path or line-count mirror:
// adding or editing one adapter runtime must change only that capsule.
$hookFiles = [];
$hookAdapters = [];
foreach ($adapterLibrary->packages() as $package) {
    $shipsCode = false;
    foreach ($package->shippablePaths() as $file) {
        $relative = substr($file, strlen($package->root()) + 1);
        if (!str_starts_with($relative, 'runtime/') || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
            continue;
        }
        $shipsCode = true;
        $hookFiles[$file] = substr_count((string) file_get_contents($file), "\n");
    }
    if ($shipsCode) {
        $hookAdapters[] = $package->name();
    }
}
$hookLines = array_sum($hookFiles);
$report(sprintf(
    'G5 condition (a) live measurement: %d of %d adapters name manifest-shipped hook code; %d files, %s lines',
    count($hookAdapters),
    count($declaredVersions),
    count($hookFiles),
    number_format($hookLines)
));
wprism_check(
    $hookAdapters !== [] && $hookFiles !== [] && $hookLines > 0,
    'v3.11 condition (a) is measured directly from the current adapter library without a cross-adapter registry'
);

wprism_check_summary('spec v3 document');
