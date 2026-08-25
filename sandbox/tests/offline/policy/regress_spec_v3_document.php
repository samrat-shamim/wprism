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
 *   is still empty, and no shipped manifest declares v3. A rider that lands
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
$manifestDir = $repo . '/manifests';

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
$duoSource = (string) file_get_contents($repo . '/agent/duo.php');
preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $duoSource, $m);
$specVersion = (int) ($m[1] ?? 0);
preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $duoSource, $m);
$agentVersion = (string) ($m[1] ?? '');

duo_check_same(2, $specVersion, 'DUO_SPEC_VERSION is still 2 — the window and the channel ride ahead of the bump, and the flip is WP-4.12 alone');

$platform = json_decode((string) file_get_contents($manifestDir . '/capabilities/platform.json'), true);
$platform = is_array($platform['platform'] ?? null) ? $platform['platform'] : [];
duo_check_same(
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

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', $specVersion);
}
if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', $agentVersion);
}

use Duo\AdapterCertification;
use Duo\AdapterContractGrammar;
use Duo\Canon;
use Duo\ManifestValidator;
use Duo\Policy;

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
duo_check_same(
    null,
    $contractVerdict(['name' => 'v3-window-probe', 'spec_version' => $specVersion - 1]),
    'v3.1 ENFORCED: a manifest declaring N-1 loads — the acceptance window, and the behaviour WP-4.2 added'
);
duo_check_same(
    null,
    $contractVerdict(['name' => 'v3-window-probe', 'spec_version' => $specVersion]),
    'v3.1: and N itself still loads, so the probe is measuring the window and not something else'
);
$outside = $contractVerdict(['name' => 'v3-window-probe', 'spec_version' => $specVersion - 2]);
duo_check(
    is_string($outside)
        && str_contains($outside, 'accepts spec_version {' . ($specVersion - 1) . ', ' . $specVersion . '}'),
    'v3.1: N-2 refuses WHOLESALE, naming the window — the floor is exactly N-1 and never deeper'
);
duo_check(
    is_string($absent = $contractVerdict(['name' => 'v3-window-probe']))
        && str_contains($absent, 'requires spec_version ' . $specVersion),
    'v3.1: and an ABSENT spec_version keeps its own older refusal, because it is not a version and so is not outside anything'
);

// v3.2 — ENFORCED (WP-4.2). The channel now has exactly one reader across the
// three trees `Adopt.php` tars (`agent manifests recovery`), which is the
// grammar that implements it. A SECOND reader appearing here is the alarm this
// assertion exists for: it would mean the feature vocabulary acquired a
// consumer that could disagree with the one definition.
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
        if (str_contains((string) file_get_contents($file->getPathname()), 'engine_features')) {
            $featureReaders[] = substr($file->getPathname(), strlen($repo) + 1);
        }
    }
}
sort($featureReaders, SORT_STRING);
duo_check_same(
    ['agent/src/Adapter/AdapterContractGrammar.php'],
    $featureReaders,
    'v3.2 ENFORCED: the channel has exactly one shipped reader — the grammar that owns the feature vocabulary'
);
duo_check_same(
    ['spec-window/v1'],
    AdapterContractGrammar::implemented_features(),
    'v3.2: and the vocabulary carries one IMPLEMENTED feature, so "declared and implemented admits" is a path something walks'
);
$unimplemented = $contractVerdict([
    'name' => 'v3-feature-probe',
    'spec_version' => $specVersion,
    'engine_features' => ['acme-thing/v1'],
]);
duo_check(
    is_string($unimplemented) && str_contains($unimplemented, "the section 'engine_features'"),
    'v3.2 x v3.1: at DUO_SPEC_VERSION ' . $specVersion . ' the key is a v3-only SECTION, so declaring it refuses by section name — the channel opens with the flip'
);

// v3.3 — the closed key set still refuses in exactly one place. The validator
// admitting an invented section is the measured defect the rule closes.
$vocabulary = (array) (new ReflectionMethod(Policy::class, 'manifest_validator_vocabulary'))->invoke(null);
$invented = [
    'name' => 'v3-keys-probe',
    'spec_version' => $specVersion,
    'options' => ['acme_probe_option' => ['class' => 'authored', 'autoload' => 'yes']],
    'totally_made_up_section' => ['acme_thing' => ['class' => 'authored']],
];
$validatorVerdict = null;
try {
    ManifestValidator::validate_manifest($invented, "manifest 'v3-keys-probe'", $vocabulary);
} catch (\Throwable $e) {
    $validatorVerdict = $e->getMessage();
}
duo_check_same(
    null,
    $validatorVerdict,
    'v3.3 NOT enforced: the manifest validator still admits a top-level section in no arm of the signer partition'
);
$ratify = (new ReflectionClass(AdapterCertification::class))->getMethod('siteRatification');
$signerVerdict = null;
try {
    $ratify->invoke(null, 'v3-keys-probe', $invented, 'v3 document suite');
} catch (\Throwable $e) {
    $signerVerdict = $e->getMessage();
}
duo_check(
    is_string($signerVerdict) && str_contains($signerVerdict, 'which this signer cannot classify'),
    'v3.3: ...while the SIGNER refuses the same manifest by name — the one-sided enforcement § v3.3 describes'
);

// Publication is not enforcement: WP-4.1 gave the partition a public accessor
// so `--emit-schema` can name the set, and that must not have taught any
// validator to consult it. Measured as "the constants still have one reader".
$partitionReaders = [];
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
        foreach (['ENTITY_SECTIONS', 'FIELD_SECTIONS', 'NON_SURFACE_KEYS'] as $token) {
            if (str_contains($body, $token)) {
                $partitionReaders[] = substr($file->getPathname(), strlen($repo) + 1);
                break;
            }
        }
    }
}
sort($partitionReaders, SORT_STRING);
duo_check_same(
    ['agent/src/Adapter/AdapterCertification.php'],
    $partitionReaders,
    'v3.3: the partition constants still have exactly one shipped reader — publishing them through topLevelKeyPartition() enforced nothing'
);

// v3.4 — one monolith, one whole-document hash.
duo_check(
    is_file($manifestDir . '/dispositions.json') && !is_dir($manifestDir . '/dispositions'),
    'v3.4 NOT enforced: the reviewed claim source is still the single manifests/dispositions.json, with no per-adapter directory'
);

// v3.5 — the one rule with shipped enforcement, and the two halves that make
// it flag-day-safe. WP-4.6 is why this suite's PART 1 is no longer uniformly
// "not enforced": the narrowing is live for a v3 manifest, and INERT for a v2
// one, so no shipped claim moved.
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
$narrowingPlatform = \Duo\ManifestDispositions::platform_boundary($manifestDir);
$narrowingSubject = Canon::decode(Canon::read_file($manifestDir . '/classic-editor.json'));
$narrowingEntry = (array) ((array) json_decode(
    (string) file_get_contents($manifestDir . '/dispositions.json'),
    true
)['manifests']['classic-editor']);
$narrowingEnvironment = static function (array $manifest) use ($narrowingPlatform, $narrowingEntry): array {
    return (array) \Duo\ManifestDispositions::claim_from_disposition(
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
duo_check(
    Canon::encode($narrowingEnvironment($v3Narrowing)) !== $wholeBoundaryBytes,
    'v3.5 ENFORCED: a spec_version 3 manifest declaring `environment` narrows its own claim (WP-4.6)'
);
$v2Narrowing = $v3Narrowing;
$v2Narrowing['spec_version'] = $specVersion;
duo_check_same(
    $wholeBoundaryBytes,
    Canon::encode($narrowingEnvironment($v2Narrowing)),
    'v3.5 INERT at v2: the same declaration under this engine\'s own spec version projects the whole boundary, byte for byte'
);
duo_check(
    in_array('environment', AdapterCertification::topLevelKeyPartition()['non_surface_keys'], true),
    'v3.5 x v3.3: the narrowing channel joined the partition as a non-surface key, in the change that reads it'
);

// v3.6/v3.7 — the two subsections whose enforcement has landed on the
// certificate wire. Their assertions flip with the riders rather than staying
// silently true; what must remain true is the SHAPE of each exception.
$cert = new ReflectionClass(AdapterCertification::class);
duo_check_same(
    "duo-site-adapter-certification-signature/v2\0",
    (string) $cert->getConstant('SIGNATURE_DOMAIN'),
    'v3.6 ENFORCED (WP-4.7): SIGNATURE_DOMAIN is /v2, because the binding semantics changed — a new domain is a '
    . 'NEW statement type verified beside the old one (register row R-01), never an edit of it'
);
duo_check_same(
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
duo_check_same(
    'duo-adapter-authorities/v1',
    (string) $cert->getConstant('AUTHORITIES_FORMAT'),
    'v3.7 ENFORCED, and v1 is untouched: the v1 envelope constant is still duo-adapter-authorities/v1, so '
    . 'every document in the field keeps today\'s grammar'
);
duo_check_same(
    'duo-adapter-authorities/v2',
    (string) $cert->getConstant('AUTHORITIES_FORMAT_V2'),
    'and v2 is a SECOND format verified beside it, never a widened v1 — the extension channel R-10 names'
);
duo_check_same(
    "duo-adapter-authorities-signature/v1\0",
    (string) $cert->getConstant('SIGNATURE_DOMAIN_AUTHORITIES'),
    'the v2 envelope signature is its own domain-separated statement type, NUL-terminated like both others '
    . '(register rows R-01/R-04), never an arm inside the certification verifier'
);
$v37Body = $section('v3.7');
duo_check(
    str_contains($v37Body, 'Enforced today: YES'),
    'and § v3.7 says so in its own Enforced-today line: a rider that landed enforcement without moving this '
    . 'line would have landed silently, which is exactly what PART 1 exists to prevent'
);
$certSource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterCertification.php');
duo_check(
    str_contains($certSource, 'private static function assertAuthorityWindow(')
        && str_contains($certSource, '$now ?? time()')
        && str_contains($certSource, 'refuses rather than resurrecting an expired record'),
    'v3.7 change (b) is shipped code: a window judged against the host\'s own `$now ?? time()`, with the '
    . 'implausible-clock refusal the section states'
);
duo_check(
    !str_contains($certSource, 'hash_equals(Canon::encode($authority), Canon::encode($embedded'),
    'v3.7 change (e) is shipped: the whole-record platform binding is gone, so both roots bind the key '
    . 'identity and a growing trust root no longer invalidates its own earlier certificates'
);
duo_check_same(
    ['adapter', 'authority', 'bundle', 'platform', 'ratification', 'version'],
    (array) $cert->getConstant('STATEMENT_KEYS'),
    'v3.6 ENFORCED: the signed statement grew the ONE member v1 could not grow — `version` — and R-06 closes the '
    . 'six in both directions'
);
duo_check(
    !str_contains($certSource, 'hash_equals(Canon::encode($platform), Canon::encode($statementTyped->platform))')
        && str_contains($certSource, 'private static function assertPlatformBinding('),
    'v3.6 ENFORCED: the whole-platform byte comparison is GONE, replaced by a binding over the exercised '
    . 'compatibility cells — the change that stops every agent release from withdrawing every certificate'
);
duo_check_same(
    [],
    array_values(array_intersect(
        ['code_digest', 'delegated_authority'],
        (array) $cert->getConstant('STATEMENT_KEYS')
    )),
    'v3.10 still NOT enforced on the statement: WP-4.7 moved the statement wire — the one change that could have '
    . 'reserved a member there — and took neither slot, so both now wait on a further generation'
);
$authorities = json_decode((string) file_get_contents($manifestDir . '/capabilities/adapter-authorities.json'), true);
duo_check_same(
    ['format' => 'duo-adapter-authorities/v1', 'keys' => []],
    is_array($authorities) ? $authorities : [],
    'v3.7: the platform trust root is still EMPTY — the flag day\'s standing precondition, re-checked here on every run'
);

// The no-restamp rule, which is what makes the whole bump digest-neutral.
$declaredVersions = [];
foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name === 'dispositions') {
        continue;
    }
    $decoded = Canon::decode(Canon::read_file($file));
    $declaredVersions[$name] = $decoded['spec_version'] ?? null;
}
ksort($declaredVersions, SORT_STRING);
duo_check_same(
    [$specVersion],
    array_values(array_unique(array_values($declaredVersions))),
    'no shipped manifest is stamped to v3: all ' . count($declaredVersions) . ' declare spec_version ' . $specVersion
);

echo "\nPART 2 — THE DOCUMENT: every measurable claim re-measured from the tree\n";

// ---------------------------------------------------------------------------
// Structure: the section, its subsections, and the rider each one names.
// ---------------------------------------------------------------------------
duo_check(
    str_contains($spec, '## Spec v3 — the windowed format (SPECIFIED HERE; THE WINDOW IS IN FORCE, THE REST IS NOT)'),
    'the spec carries the v3 section, and its heading states the status in the heading itself'
);

$subsections = ['v3.1', 'v3.2', 'v3.3', 'v3.4', 'v3.5', 'v3.6', 'v3.7', 'v3.8', 'v3.9', 'v3.10', 'v3.11', 'v3.12'];
$missing = array_values(array_filter($subsections, static fn(string $id): bool => $section($id) === ''));
duo_check_same([], $missing, 'all twelve v3 subsections are present');

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
duo_check_same([], $riderGaps, 'every rule-bearing subsection carries its implementing rider id and an "Enforced today:" line');
duo_check(
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
duo_check_same(
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
duo_check_same(
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
duo_check(
    str_contains($keysBody, '`theme_version_range` JOINS the partition')
        && !in_array('theme_version_range', array_merge(...array_values($partition)), true),
    'v3.3 resolves the `theme_version_range` gap, and the key is indeed still absent from the shipped partition'
);
$grammarSource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterContractGrammar.php');
duo_check(
    str_contains($grammarSource, "['theme', 'theme_version_range']"),
    'and the shipped grammar still makes `theme_version_range` mandatory beside `theme`, which is what makes a theme adapter unsignable today'
);
duo_check(
    str_contains($keysBody, '`_draft` is REFUSED at v3')
        && str_contains((string) file_get_contents($repo . '/cli/src/Adapter/AdapterDraft.php'), "\$manifest['_draft'] = self::build_draft("),
    'v3.3 resolves the `_draft` gap as a refusal, and `duo adapter-draft` does still emit that key'
);

// ---------------------------------------------------------------------------
// v3.5 — the one subsection whose "Enforced today:" line says yes, and the
// three facts a reader must be able to check without leaving the page: which
// axes are narrowable, that v2 is inert, and that the load-time gate is
// untouched. Asserted as text because the flip of that line is the deliverable
// half of WP-4.6 that no engine constant can hold.
// ---------------------------------------------------------------------------
$narrowingBody = $section('v3.5');
duo_check(
    str_contains($narrowingBody, 'Enforced today: yes, for a `spec_version: 3` manifest; inert for v2.'),
    'v3.5 states its enforcement precisely — live at v3, inert at v2 — rather than a bare "yes"'
);
$narrowableGaps = array_values(array_filter(
    ['database', 'php', 'site_mode', 'wordpress'],
    static fn(string $axis): bool => !str_contains($narrowingBody, '`' . $axis . '`')
));
duo_check_same([], $narrowableGaps, 'v3.5 names all four narrowable axes, which are exactly the four members a claim states');
duo_check(
    str_contains($narrowingBody, '`filesystem` and `process` are load-time profiles that no claim states'),
    'and says why the other two compatibility axes are NOT narrowable, so their absence is a decision rather than an omission'
);
duo_check(
    str_contains($narrowingBody, 'JOINS § v3.3\'s partition as a non-surface key'),
    'v3.5 records that the channel joined the closed key set in the same change, which is what keeps a narrowing adapter signable'
);

// ---------------------------------------------------------------------------
// v3.4 — the monolith's measured size, as stated.
// ---------------------------------------------------------------------------
$dispositionsRaw = (string) file_get_contents($manifestDir . '/dispositions.json');
$dispositions = json_decode($dispositionsRaw, true);
$entryCount = count((array) ($dispositions['manifests'] ?? []));
$profileNames = array_keys((array) ($dispositions['profiles'] ?? []));
sort($profileNames, SORT_STRING);
$lineCount = substr_count($dispositionsRaw, "\n");
$byteCount = strlen($dispositionsRaw);
$dispositionBody = $section('v3.4');
duo_check(
    str_contains($dispositionBody, (string) $lineCount . ' lines')
        && str_contains($dispositionBody, number_format($byteCount) . ' bytes')
        && str_contains($dispositionBody, $entryCount . ' entries'),
    "v3.4's measurement of the monolith matches the file: $lineCount lines, " . number_format($byteCount) . " bytes, $entryCount entries"
);
duo_check_same(['fse'], $profileNames, 'and `profiles` is still the one row the split gives its own document');

// ---------------------------------------------------------------------------
// v3.6 — the compatibility axes a v3 certificate would bind.
// ---------------------------------------------------------------------------
$axes = array_keys((array) ($platform['compatibility'] ?? []));
sort($axes, SORT_STRING);
$axisBody = $section('v3.6');
$axisGaps = array_values(array_filter($axes, static fn(string $axis): bool => !str_contains($axisBody, '`' . $axis . '`')));
duo_check_same([], $axisGaps, 'v3.6 names every compatibility axis the boundary declares (' . implode(', ', $axes) . ')');
duo_check(
    str_contains($axisBody, 'five as of #560'),
    'and states how many there are, so a sixth axis is a reviewed sentence here rather than a silent widening'
);

// ---------------------------------------------------------------------------
// v3.9 — the namespace census, re-counted.
// ---------------------------------------------------------------------------
$names = [];
$idKinds = [];
$providerIds = [];
foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name === 'dispositions') {
        continue;
    }
    $manifest = Canon::decode(Canon::read_file($file));
    $names[] = $name;
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
duo_check(
    str_contains($nsBody, count($names) . ' adapter names, ' . count($idKinds) . ' `id_kind`s, ' . count($providerIds) . ' provider ids = ' . $identityTotal . ' identities')
        && str_contains($nsBody, 'break **' . $breakList . '** of them'),
    "v3.9's census matches the library: $identityTotal identities, $breakList of them refused by a bare shape rule"
);
$namedUnprefixed = array_values(array_filter($unprefixedNames, static fn(string $n): bool => str_contains($nsBody, '`' . $n . '`')));
duo_check_same(
    $unprefixedNames,
    $namedUnprefixed,
    'and it NAMES the ' . count($unprefixedNames) . ' unprefixed adapter names rather than only counting them, since those are the rows an operator has to grandfather'
);
duo_check(
    str_contains($nsBody, 'R-17'),
    'v3.9 defers to the irreversibility register\'s R-17 rather than re-deciding `id_kind` prefixing'
);
$register = (string) file_get_contents($repo . '/docs/wire-surface.md');
duo_check(
    str_contains($register, '### R-17 — `id_kind` is a flat, unprefixed namespace'),
    'and R-17 is still the register row it defers to (a renamed row would leave this rule resting on nothing)'
);

// ---------------------------------------------------------------------------
// v3.10 — the reserved slots. Each refusal text is pinned HERE and must not
// yet exist as shipped code: a reservation that quietly became behaviour would
// mean WP-4.11 landed inside a spec-only work package.
// ---------------------------------------------------------------------------
$reservationBody = $section('v3.10');
$reserved = [
    'the executable adapter lane is reserved and shut' => 'G5',
    'a signed binding over adapter code is reserved and shut' => 'G5',
    'delegated authority is verified through its own signed delegation document' => 'v3.8',
    'the reviewer tier opens at gate G4' => 'G4',
    'the reviewer evidence member is reserved' => 'G4',
];
$reservationGaps = [];
$leakedIntoCode = [];
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
    if (str_contains($shippedSource, $text)) {
        $leakedIntoCode[] = $text;
    }
}
duo_check_same([], $reservationGaps, 'v3.10 pins all five reserved refusal texts, each naming the gate that would open it');
duo_check_same([], $leakedIntoCode, 'and none of them is shipped code yet — WP-4.11 implements them, WP-4.1 only writes them down');
duo_check(
    str_contains($reservationBody, 'version_range_graduated')
        && str_contains($shippedSource, 'version_range_graduated'),
    'v3.10 records the graduated verdict as ALREADY DELIVERED (WP-2.8) rather than reserving it again, and the shipped word backs that up'
);

// ---------------------------------------------------------------------------
// v3.11 — the exec-lane gate's evidence contract, as spec text.
// ---------------------------------------------------------------------------
$laneBody = $section('v3.11');
$conditionNumbers = [];
if (preg_match_all('/(?:^|\s)(\d+)\. \*\*/', $laneBody, $numbered) > 0) {
    $conditionNumbers = array_map('intval', $numbered[1]);
}
duo_check_same(
    [1, 2, 3, 4, 5, 6, 7],
    $conditionNumbers,
    'v3.11 records exactly seven numbered conditions for gate G5, in order — the evidence contract lives in the spec so opening the lane is a reviewed change'
);
duo_check(
    str_contains($laneBody, 'The default answer is NO')
        && str_contains($laneBody, 'ALL SEVEN')
        && str_contains($laneBody, 'never a relaxed gate'),
    'and it states the default, the conjunction, and what happens when a condition is unmet'
);
duo_check(
    str_contains($laneBody, 'assert_out_of_tree_contract()')
        && str_contains($shippedSource, 'function assert_out_of_tree_contract('),
    'v3.11 names the shipped refusal that keeps the lane shut today, and that refusal exists'
);

// Condition (a)'s baseline is a threshold argument, so its numbers are the
// ones most likely to be quoted later and least likely to be re-measured. They
// are re-measured here: manifest-shipped hook code is exactly the code an
// executable lane would let a stranger add to.
$hookFiles = [];
foreach (['interpreters', 'providers', 'regenerators'] as $kind) {
    foreach (glob($manifestDir . '/' . $kind . '/*.php') ?: [] as $file) {
        $hookFiles[$file] = substr_count((string) file_get_contents($file), "\n");
    }
}
$hookAdapters = [];
foreach (glob($manifestDir . '/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name === 'dispositions') {
        continue;
    }
    $manifest = Canon::decode(Canon::read_file($file));
    $shipsCode = is_string($manifest['interpreter'] ?? null);
    foreach ((array) ($manifest['providers'] ?? []) as $provider) {
        $shipsCode = $shipsCode || (is_array($provider) && ($provider['source'] ?? null) === 'manifest');
    }
    foreach ((array) ($manifest['post_types'] ?? []) as $rule) {
        $shipsCode = $shipsCode
            || (is_array($rule) && is_string(($rule['regen_dependency']['regenerator'] ?? null)));
    }
    if ($shipsCode) {
        $hookAdapters[] = $name;
    }
}
$hookLines = array_sum($hookFiles);
$report(sprintf(
    'G5 condition (a) baseline: %d of %d adapters name manifest-shipped hook code; %d files, %s lines',
    count($hookAdapters),
    count($declaredVersions),
    count($hookFiles),
    number_format($hookLines)
));
duo_check(
    str_contains($laneBody, count($hookAdapters) . ' of the ' . count($declaredVersions) . ' adapters name manifest-shipped hook code')
        && str_contains($laneBody, count($hookFiles) . ' files totalling ' . number_format($hookLines) . ' lines'),
    'v3.11 condition (a) states the measured baseline the threshold is set against, and it matches the library'
);

duo_check_summary('spec v3 document');
