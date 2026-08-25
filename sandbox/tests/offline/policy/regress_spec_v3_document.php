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

// v3.3 — ENFORCED (WP-4.3), and the two halves that make it flag-day-safe. The
// v2 era is asserted UNCHANGED rather than merely assumed: the rule is gated at
// spec_version 3, so a v2 manifest declaring an invented section still loads,
// which is what keeps all 16 shipped manifests and every adapter digest still.
$vocabulary = (array) (new ReflectionMethod(Policy::class, 'manifest_validator_vocabulary'))->invoke(null);
$invented = [
    'name' => 'v3-keys-probe',
    'spec_version' => $specVersion,
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
duo_check_same(
    null,
    $manifestVerdict($invented),
    'v3.3 INERT at v2: the manifest validator still admits a top-level section in no arm of the partition, so no shipped manifest changed behaviour by a byte'
);
// The v3 half cannot be walked in this process — § v3.1 refuses spec_version 3
// wholesale at DUO_SPEC_VERSION 2, one step before the key rule — so what is
// asserted here is that the refusal is the WINDOW's and that the rule exists,
// with its own two-era suite named. regress_closed_top_level_keys.php drives
// the N+1 engine.
$inventedV3 = $invented;
$inventedV3['spec_version'] = $specVersion + 1;
duo_check(
    str_contains((string) $manifestVerdict($inventedV3), 'accepts spec_version'),
    'v3.3 x v3.1: at DUO_SPEC_VERSION ' . $specVersion . ' the rule is unreachable through the product path — the window refuses a spec_version ' . ($specVersion + 1) . ' manifest first'
);
duo_check(
    str_contains(
        (string) file_get_contents($repo . '/agent/src/Adapter/AdapterContractGrammar.php'),
        'private static function assert_top_level_keys('
    ),
    'v3.3 ENFORCED: the contract grammar carries the closed-key-set refusal, gated at spec_version 3 (WP-4.3)'
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
    'v3.3: ...and the SIGNER still refuses the same manifest by name, at every version — the arm it cannot assign is what a certificate would have to cover'
);

// ONE DEFINITION, NOT TWO. WP-4.1 gave the partition a public accessor so
// `--emit-schema` could name the set; WP-4.3 made the contract grammar enforce
// through that same accessor. Two facts, measured separately: the three private
// constants must stay in ONE file (the definition), while the accessor is what
// every other file reads.
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
            $body = (string) file_get_contents($file->getPathname());
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
duo_check_same(
    ['agent/src/Adapter/AdapterCertification.php'],
    $namingFiles(['ENTITY_SECTIONS', 'FIELD_SECTIONS', 'NON_SURFACE_KEYS']),
    'v3.3: the partition constants are declared in exactly one shipped file — enforcing the set did not copy it'
);
duo_check_same(
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
duo_check(
    is_dir($manifestDir . '/dispositions') && !is_file($manifestDir . '/dispositions.json'),
    'v3.4 layout ENFORCED (WP-4.4): the reviewed claim source is the per-subject directory manifests/dispositions/, '
    . 'and the monolith is gone'
);
// Measured, not read: `registry_sha256` is still ONE hash over the WHOLE
// reassembled document, so editing any subject moves the number a contract
// pins even when it pins no adapter at all. WP-4.5 is the rider that narrows
// it to per-subject addressing.
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
$wholeRegistry = ['format' => \Duo\ManifestDispositions::FORMAT, 'manifests' => [], 'profiles' => []];
foreach (glob($manifestDir . '/dispositions/*.json') ?: [] as $document) {
    $subject = basename($document, '.json');
    $decoded = (array) json_decode((string) file_get_contents($document), true);
    if ($subject === 'profiles') {
        $wholeRegistry['profiles'] = $decoded;
        continue;
    }
    $wholeRegistry['manifests'][$subject] = $decoded;
}
ksort($wholeRegistry['manifests'], SORT_STRING);
duo_check(
    \Duo\ManifestDispositions::load($manifestDir)?->sha256()
        === hash('sha256', Canon::encode($wholeRegistry)),
    'v3.4 addressing NOT enforced (WP-4.5): registry_sha256 is still one hash over the WHOLE reviewed document, so '
    . 'an edit to any subject still moves what a contract pinning no adapter observes'
);

// v3.5 — the one rule with shipped enforcement, and the two halves that make
// it flag-day-safe. WP-4.6 is why this suite's PART 1 is no longer uniformly
// "not enforced": the narrowing is live for a v3 manifest, and INERT for a v2
// one, so no shipped claim moved.
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
$narrowingPlatform = \Duo\ManifestDispositions::platform_boundary($manifestDir);
$narrowingSubject = Canon::decode(Canon::read_file($manifestDir . '/classic-editor.json'));
$narrowingEntry = (array) json_decode(
    (string) file_get_contents($manifestDir . '/dispositions/classic-editor.json'),
    true
);
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

// v3.8 IS enforced (WP-4.9), and it is gated on TWO DOCUMENTS THAT DO NOT
// EXIST rather than on `spec_version` — so what has to stay true is the shape
// of that gate: both documents absent, both statement kinds domain-separated,
// and the certification wire untouched by either.
$v38Body = $section('v3.8');
duo_check(
    str_contains($v38Body, 'Enforced today: YES'),
    'and § v3.8 says so in its own Enforced-today line: a rider that landed a certificate authority without '
    . 'moving this line would have landed silently, which is exactly what PART 1 exists to prevent'
);
duo_check_same(
    "duo-adapter-authority-delegation-signature/v1\0",
    (string) $cert->getConstant('SIGNATURE_DOMAIN_DELEGATION'),
    'v3.8 ENFORCED: a delegation is its own NUL-terminated domain-separated statement kind (register rows '
    . 'R-01/R-04), never a new arm inside the certification verifier'
);
duo_check_same(
    "duo-adapter-authority-revocation-signature/v1\0",
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
duo_check_same(
    [],
    $v38Prefixes,
    'and no one of the four adapter-side domains is a prefix of another: the property that stops a delegation '
    . 'being replayed as the authority half of a certificate'
);
duo_check(
    !file_exists($manifestDir . '/capabilities/adapter-revocations.json')
        && (glob($manifestDir . '/**/delegations.json') ?: []) === []
        && !file_exists($manifestDir . '/delegations.json'),
    'v3.8: BOTH gating documents are absent from the shipped library — the out-of-band revocation record and '
    . 'the delegation document — which is why an agent that meets neither behaves exactly as it does today'
);
duo_check(
    str_contains($certSource, 'private static function delegatedKeys(')
        && str_contains($certSource, "private const DELEGATION_DEPTH = 1;")
        && str_contains($certSource, 'which is itself a delegate')
        && str_contains($certSource, ' level and a delegate may not delegate'),
    'v3.8 depth-1 is shipped code, and the bound is in the VERIFIER as a constant rather than a policy: a '
    . 'delegator that is itself a delegate is refused by name rather than by failing to resolve'
);
duo_check(
    str_contains($certSource, 'private static function assertNotRevoked(')
        && str_contains($certSource, 'This channel reaches the frozen path, which a status flip in the operator'),
    'v3.8 typed revocation is shipped code, and its refusal STATES THE DISTINCTION the section promises — an '
    . 'operator can tell which of the two revocation mechanisms answered'
);
$v38Register = (string) file_get_contents($repo . '/docs/wire-surface.md');
duo_check(
    str_contains($v38Register, '### R-25 — Delegation is depth-1')
        && str_contains($v38Register, '### R-26 — Typed revocation')
        && str_contains($v38Body, 'Register rows R-25 and R-26'),
    'and both register rows exist and are the ones § v3.8 names: a new signed surface without a register row '
    . 'is the exact failure docs/wire-surface.md is generated to prevent'
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
        && in_array('theme_version_range', array_merge(...array_values($partition)), true),
    'v3.3 resolves the `theme_version_range` gap, and the key is indeed IN the shipped partition now (WP-4.3, resolution 1)'
);
$grammarSource = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterContractGrammar.php');
duo_check(
    str_contains($grammarSource, "['theme', 'theme_version_range']"),
    'and the shipped grammar still makes `theme_version_range` mandatory beside `theme`, which is the constraint the resolution reconciled the partition with'
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
// v3.4 — the SPLIT's measured size, as stated. The monolith's own numbers stay
// in the subsection as the history they now are, and are no longer measurable
// from the tree; what has to keep matching is the directory that replaced it.
// ---------------------------------------------------------------------------
$dispositionDocuments = glob($manifestDir . '/dispositions/*.json') ?: [];
sort($dispositionDocuments, SORT_STRING);
$documentCount = count($dispositionDocuments);
$lineCount = 0;
$byteCount = 0;
$entryCount = 0;
$profileNames = [];
foreach ($dispositionDocuments as $document) {
    $raw = (string) file_get_contents($document);
    $lineCount += substr_count($raw, "\n");
    $byteCount += strlen($raw);
    if (basename($document, '.json') === 'profiles') {
        $profileNames = array_keys((array) json_decode($raw, true));
        continue;
    }
    $entryCount++;
}
sort($profileNames, SORT_STRING);
$dispositionBody = $section('v3.4');
duo_check(
    str_contains($dispositionBody, $documentCount . ' documents')
        && str_contains($dispositionBody, number_format($lineCount) . ' lines')
        && str_contains($dispositionBody, number_format($byteCount) . ' bytes'),
    "v3.4's measurement of the split source matches the directory: $documentCount documents, "
    . number_format($lineCount) . ' lines, ' . number_format($byteCount) . ' bytes'
);
duo_check_same(
    16,
    $entryCount,
    'and the entry count the subsection states for the monolith it replaced is the number of subject documents now'
);
duo_check_same(['fse'], $profileNames, 'and `profiles` is the one row the split gave its own document');

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

// v3.9 — ENFORCED for names and provider ids (WP-4.10), and NEVER for
// `id_kind`. The subsection makes three claims with shipped consequences, and
// each is asserted against the engine rather than read back out of the prose.
require_once $repo . '/agent/src/Adapter/IdentityNamespaces.php';
duo_check(
    str_contains($nsBody, 'Enforced today: for names and provider ids, yes at `spec_version: 3`; inert at v2.')
        && str_contains($nsBody, 'For `id_kind`, never'),
    'v3.9\'s Enforced-today line states the split its rider actually landed: a rule for two of the three '
    . 'spaces and none for the third'
);
$nsVerdict = static function (array $manifest, string $name): ?string {
    try {
        \Duo\IdentityNamespaces::assert_out_of_tree_identity($manifest, $name, 'site adapter', "'adapters/$name.json'");
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
$nsFixture = static fn(string $name, ?int $spec, array $extra = []): array => $extra + array_filter([
    'name' => $name,
    'spec_version' => $spec,
], static fn($v): bool => $v !== null);
duo_check(
    $nsVerdict($nsFixture('cache', $specVersion), 'cache') === null
        && is_string($nsVerdict($nsFixture('cache', 3), 'cache')),
    'and the engine agrees on both halves of that line: an unprefixed out-of-tree name is inert at '
    . "spec_version $specVersion and refuses at 3, so this rule rode ahead of the flip without moving it"
);
duo_check(
    $nsVerdict($nsFixture('acme-cache', 3, [
        'tables' => ['acme_widget' => ['class' => 'authored_snapshot', 'id_kind' => 'acme_widget']],
    ]), 'acme-cache') === null,
    'while an unprefixed `id_kind` inside a v3 manifest is still accepted — R-17 reserves the convention and '
    . 'refuses the rule, and this is the assertion that fails if a later rider quietly overrules it'
);
$nsListed = \Duo\IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES;
duo_check_same(
    $names,
    $nsListed,
    'the closed grandfather list § v3.9 describes carries exactly the ' . count($names)
    . ' shipped adapter names — the enumeration the census above says a shape test cannot replace'
);
duo_check(
    str_starts_with(
        (string) realpath((string) (new ReflectionClass(\Duo\IdentityNamespaces::class))->getFileName()),
        (string) realpath($repo . '/agent/src')
    ),
    'and it lives in agent/src as the subsection requires, not under manifests/ where rule 2 would fold it '
    . 'into every adapter digest'
);
duo_check(
    str_contains($register, '### R-27 — The reserved `<vendor>-` form, and the closed grandfather list under it'),
    'R-27 is the register row that records the decision, so the closed list is reviewable beside the other '
    . 'irreversible ones rather than only in code'
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
