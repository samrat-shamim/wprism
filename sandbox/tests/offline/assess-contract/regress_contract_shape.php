<?php
/**
 * Offline characterization for the `wprism-application-contract/v2` document
 * and the `.wprism/contract/` store (round-3 MUP §3.1-§3.2, §2.6).
 *
 * The contract is the only place a human's reviewed declarations about a
 * site live, and `wprism release` cites its digest inside a frozen
 * authorization plan. Four properties therefore have to be gates rather than
 * conventions:
 *
 *  - **canonical JSON**, so two reviewers diff the same bytes and a
 *    re-serialization is not a change;
 *  - **closed key sets**, so a mistyped declaration refuses instead of
 *    sitting silently inert in a document that authorizes production;
 *  - **digest stability**, so `contract_digest` identifies content and not
 *    the order a generator happened to build an array in;
 *  - **compare-and-swap on write**, so accepting a contract across a human
 *    review step cannot discard a concurrent reviewer's work.
 *
 * Plus the enum that carries the round's headline honesty property:
 * `attestation.state` is `unsigned | signed` and this build only ever writes
 * `unsigned` (MUP §3.2, §7).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ContractStore.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ContractProposal.php';
require_once __DIR__ . '/../../../../cli/src/Assess/AssessReport.php';
require_once __DIR__ . '/../../../../cli/src/Command/AssessCommand.php';

use WPrism\Canon;
use WPrism\Orchestrator\ApplicationContract;
use WPrism\Orchestrator\AssessReport;
use WPrism\Orchestrator\AssessCommand;
use WPrism\Orchestrator\ContractProposal;
use WPrism\Orchestrator\ContractStore;

$fixtures = __DIR__ . '/../../fixtures/contract';

/**
 * A throwaway site-repository skeleton: the two things a store needs to
 * exist inside (a repo root that looks like a site repo, and the `.wprism/`
 * layout it publishes into). Generated rather than committed so the suite
 * never depends on directory bytes it cannot rebuild, and removed on exit
 * so a failing run leaves nothing behind.
 */
function wprism_contract_fixture_repo(): string {
    $root = sys_get_temp_dir() . '/wprism-contract-' . bin2hex(random_bytes(6));
    if (!mkdir($root . '/.wprism/contract', 0777, true)) {
        throw new RuntimeException("cannot create fixture site repo at $root");
    }
    file_put_contents(
        $root . '/site.wprism.json',
        Canon::encode(['envs' => ['production' => ['transport' => 'ssh', 'ssh' => 'deploy@prod']]])
    );
    register_shutdown_function(static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    });

    $resolved = realpath($root);

    return $resolved === false ? $root : $resolved;
}

/** @return array<string,mixed> */
function wprism_contract_fixture(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("fixture is not a JSON object: $path");
    }

    return $decoded;
}

/**
 * Reverse every associative level, so the array a caller built differs from
 * the canonical order in every nested object. Anything that survives this
 * unchanged is genuinely order-independent.
 *
 * @param mixed $value
 * @return mixed
 */
function wprism_shuffle_keys(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('wprism_shuffle_keys', $value);
    }

    return array_map('wprism_shuffle_keys', array_reverse($value, true));
}

// ------------------------------------------------------------- the happy path
$unbound = wprism_contract_fixture($fixtures . '/contract-unbound.json');
$contract = ApplicationContract::withDigest($unbound);
ApplicationContract::validate($contract);
wprism_check(true, 'the reviewed fixture contract validates');
wprism_check_same(
    'sha256:' . hash('sha256', Canon::encode($unbound)),
    $contract['contract_digest'],
    'contract_digest is sha256 over everything above it'
);

// ------------------------------------------------------- fixture wire form
// issue #3488: these five fixtures teach the wire form of three fields whose real
// producers all emit BARE 64-hex — `adapter_digest` from
// `RepositoryCompiler::resolved_adapters()`
// (agent/src/Policy/ArtifactPolicyIdentity.php:147), `registry_sha256` from
// `ManifestDispositions::sha256()`
// (agent/src/Policy/ManifestDispositions.php:114) and, on the host side,
// `AssessCommand::registryProvenance()` (cli/src/Command/AssessCommand.php:716),
// which also emits `generated_from.dispositions_sha256`. The `sha256:` form
// belongs to `AdapterObservation`, which re-prefixes the number on its way into
// a different document (agent/src/Adapter/AdapterObservation.php:548).
//
// Nothing downstream enforces the form — every validator on this path is a
// non-empty-string check (ApplicationContract.php:385-389) — so before this
// assertion existed, reintroducing the prefix in a fixture AND in
// `regress_contract_projection.php`'s coupled `WPRISM_REGISTRY_SHA` left the whole
// corpus green while the fixtures taught a form no producer emits. That is the
// silent path this pins shut; the equality coupling next door only catches a
// one-sided change.
// A file that matches nothing is reported as an offender rather than skipped:
// silence is how a form check dies, and the one assertion below has to be able
// to say so.
$wireFormOffenders = [];
foreach ([
    __DIR__ . '/../../fixtures/contract/contract-unbound.json',
    __DIR__ . '/../../fixtures/contract/assess-report-unbound.json',
    __DIR__ . '/../../fixtures/contract/proposal-seed.json',
    __DIR__ . '/../../fixtures/release/contract-declared-unbound.json',
    __DIR__ . '/../../fixtures/release/contract-undeclared-unbound.json',
] as $ownedFixture) {
    $raw = (string) file_get_contents($ownedFixture);
    $matched = preg_match_all(
        '/"(adapter_digest|registry_sha256|dispositions_sha256)"\s*:\s*"([^"]*)"/',
        $raw,
        $found,
        PREG_SET_ORDER
    );
    if ($matched === 0) {
        $wireFormOffenders[] = basename($ownedFixture) . ': carries none of the three fields, so this check went vacuous';
        continue;
    }
    foreach ($found as [, $field, $value]) {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            $wireFormOffenders[] = basename($ownedFixture) . ": $field = $value";
        }
    }
}
wprism_check_same(
    [],
    $wireFormOffenders,
    'every fixture hash whose producer emits bare 64-hex is stored bare, never sha256:-prefixed'
);

// ------------------------------------------------------------- canonical JSON
$encoded = ApplicationContract::encode($contract);
wprism_check_same($encoded, ApplicationContract::encode($contract), 'encoding is byte-stable');
wprism_check_same(
    $encoded,
    ApplicationContract::encode(wprism_shuffle_keys($contract)),
    'encoding is independent of the key order the caller built'
);
wprism_check(str_ends_with($encoded, "\n"), 'the canonical document ends in exactly one LF');
wprism_check(!str_contains($encoded, "\r"), 'the canonical document contains no CR');
wprism_check(str_contains($encoded, '"format": "wprism-application-contract/v2"'), 'the canonical document is pretty-printed');
wprism_check(
    strpos($encoded, '"attestation"') < strpos($encoded, '"contract_digest"'),
    'keys are sorted at the top level'
);
wprism_check_json_equal($contract, Canon::decode($encoded), 'the canonical document round-trips');

// ------------------------------------------------------------ digest stability
wprism_check_same(
    ApplicationContract::digest($contract),
    ApplicationContract::digest(wprism_shuffle_keys($contract)),
    'the digest does not move with key order'
);
$moved = $contract;
$moved['declarations']['surfaces'][0]['handling'] = 'preserve local';
wprism_check(
    ApplicationContract::digest($moved) !== ApplicationContract::digest($contract),
    'the digest moves when a declaration changes'
);
$stale = $contract;
$stale['site']['name'] = 'renamed-shop';
wprism_check_refuses(
    static fn () => ApplicationContract::validate($stale),
    'contract_digest_mismatch',
    'a hand-edited contract whose digest was not recomputed refuses'
);

// --------------------------------------------------------------- closed keys
$unknown = $contract;
$unknown['declarations']['unknown_declaration'] = ['anything'];
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($unknown)),
    'contract_shape_invalid',
    'an unrecognised declarations key refuses'
);
$unknownTop = ApplicationContract::withDigest(array_merge($contract, ['notes' => 'hello']));
wprism_check_refuses(
    static fn () => ApplicationContract::validate($unknownTop),
    'contract_shape_invalid',
    'an unrecognised top-level key refuses'
);
$unknownSurface = $contract;
$unknownSurface['declarations']['surfaces'][0]['owner'] = 'someone';
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($unknownSurface)),
    'contract_shape_invalid',
    'an unrecognised surface key refuses'
);
$missing = $contract;
unset($missing['declarations']['journeys']);
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($missing)),
    'contract_shape_invalid',
    'a missing required declarations key refuses'
);
$wrongFormat = ApplicationContract::withDigest(array_merge($contract, ['format' => 'wprism-application-contract/v3']));
wprism_check_refuses(
    static fn () => ApplicationContract::validate($wrongFormat),
    'contract_format_invalid',
    'a future format version refuses rather than being read optimistically'
);
// The superseded generation refuses too, but not with the same sentence: an
// operator holding an accepted v1 has to be told WHY a document that parsed
// yesterday does not parse now, or they diff two schemas to find out. The
// message is asserted here — not just the code — because the remediation is
// carried in the prose and nowhere else.
$priorFormat = ApplicationContract::withDigest(
    array_merge($contract, ['format' => ApplicationContract::PRIOR_FORMAT])
);
wprism_check_refuses(
    static fn () => ApplicationContract::validate($priorFormat),
    'contract_format_invalid',
    'the superseded v1 generation refuses'
);
wprism_check_throws(
    static fn () => ApplicationContract::validate($priorFormat),
    \WPrism\CommandRefusalException::class,
    'the v1 refusal names re-proposal as v2 rather than reporting a generic format mismatch',
    're-proposed and re-accepted as wprism-application-contract/v2'
);

// ------------------------------------------------------- the narrowed pins
// v2 pins two numbers and no third. Each of the four v1 keys is re-added
// individually below, because the closed-key check is what turns "this build
// ignores a pin it no longer understands" into a refusal: a document carrying
// `bundles[]` would otherwise parse, and an operator would read a per-subject
// pin list as reviewed when nothing re-checks it.
wprism_check_same(
    ['registry_sha256', 'generated_from'],
    array_keys($contract['evidence_pins']),
    'a v2 contract pins the reviewed dispositions hash and its host-side provenance, and nothing else'
);
wprism_check_same(
    ['dispositions_sha256'],
    array_keys($contract['evidence_pins']['generated_from']),
    'generated_from names the one document that still exists'
);
$withBundles = $contract;
$withBundles['evidence_pins']['bundles'] = [[
    'subject' => 'manifests.storefront-commerce',
    'bundle_digest' => 'sha256:' . str_repeat('7', 64),
    'bundle_schema' => 'wprism-subject-certification-bundle/v1',
    'status' => 'current',
    'git_revision' => str_repeat('c', 40),
    'expires_with' => ['storefront-commerce'],
]];
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($withBundles)),
    'contract_shape_invalid',
    'a per-subject bundle pin list refuses as an unrecognised key rather than sitting inert'
);
foreach (['evidence_sha256', 'compatibility_sha256'] as $retiredInput) {
    $withInput = $contract;
    $withInput['evidence_pins']['generated_from'][$retiredInput] = 'sha256:' . str_repeat('9', 64);
    wprism_check_refuses(
        static fn () => ApplicationContract::validate(ApplicationContract::withDigest($withInput)),
        'contract_shape_invalid',
        "a retired generator-input hash ($retiredInput) refuses as an unrecognised key"
    );
}
$missingPin = $contract;
unset($missingPin['evidence_pins']['generated_from']['dispositions_sha256']);
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($missingPin)),
    'contract_shape_invalid',
    'the surviving provenance hash stays required, so narrowing did not make it optional'
);

// ---------------------------------------------------------------- vocabulary
$badHandling = $contract;
$badHandling['declarations']['surfaces'][0]['handling'] = 'delete';
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($badHandling)),
    'contract_vocabulary_invalid',
    'a contract may not mint a handling word'
);
$sandboxed = $contract;
$sandboxed['declarations']['external_effects'][0]['containment'] = 'sandboxed';
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($sandboxed)),
    'contract_vocabulary_unsupported',
    'a contract may not declare sandboxed containment this profile cannot enforce'
);
$compensatable = $contract;
$compensatable['declarations']['external_effects'][0]['effect_recovery_semantics'] = 'compensatable';
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($compensatable)),
    'contract_vocabulary_unsupported',
    'a contract may not declare compensatable recovery this profile cannot enforce'
);

// ----------------------------------------------------------- attestation enum
wprism_check_same(['unsigned', 'signed'], ApplicationContract::ATTESTATION_STATES, 'the attestation enum is closed');
$badState = $contract;
$badState['attestation']['state'] = 'notarized';
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($badState)),
    'attestation_state_invalid',
    'an attestation state outside the enum refuses'
);
$halfSigned = $contract;
$halfSigned['attestation']['state'] = 'signed';
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($halfSigned)),
    'contract_shape_invalid',
    'a signed attestation with no principal or signature refuses'
);
$signed = $contract;
$signed['attestation'] = [
    'format' => 'wprism-contract-attestation/v1',
    'state' => 'signed',
    'reason' => 'approved under the customer policy',
    'approving_principal' => 'security@example.test',
    'policy_version' => '3',
    'signature' => 'ed25519:deadbeef',
    'expires_at' => '2027-01-01T00:00:00Z',
    // The three the signer added. They are OPTIONAL to the closed key set and
    // required only here, in the signed branch, which is the whole reason this
    // change is invisible to every contract already on disk. Their content is
    // checked by ContractAttestation, not by the schema: this document's
    // `signature` is still the placeholder it always was, and it still parses.
    'key_id' => 'contract-0123456789ab',
    'platform_sha256' => str_repeat('a', 64),
    'trust_root' => 'site',
];
$signed = ApplicationContract::withDigest($signed);
ApplicationContract::validate($signed);
wprism_check(true, 'a fully populated signed attestation still parses, so a future document is readable');
foreach (ApplicationContract::ATTESTATION_SIGNED_ONLY as $signedOnly) {
    $missing = $signed;
    unset($missing['attestation'][$signedOnly]);
    wprism_check_refuses(
        static fn () => ApplicationContract::validate(ApplicationContract::withDigest($missing)),
        'contract_shape_invalid',
        "a signed attestation without $signedOnly refuses: verification cannot be done without it"
    );
    $onUnsigned = $contract;
    $onUnsigned['attestation'][$signedOnly] = 'x';
    wprism_check_refuses(
        static fn () => ApplicationContract::validate(ApplicationContract::withDigest($onUnsigned)),
        'contract_shape_invalid',
        "an unsigned attestation may not carry $signedOnly, so no unsigned document gains a byte"
    );
}
// The property the whole optional-key design exists for: an unsigned contract's
// canonical bytes — and therefore its contract_digest, which wprism release
// freezes into an authorization plan — are exactly what they were before a
// signed branch existed.
foreach (ApplicationContract::ATTESTATION_SIGNED_ONLY as $signedOnly) {
    wprism_check(
        !str_contains(ApplicationContract::encode($contract), '"' . $signedOnly . '"'),
        "an unsigned contract's canonical bytes carry no $signedOnly key, so its contract_digest did not move"
    );
}
$unsignedWithPrincipal = $contract;
$unsignedWithPrincipal['attestation']['approving_principal'] = 'someone@example.test';
wprism_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($unsignedWithPrincipal)),
    'contract_shape_invalid',
    'an unsigned attestation may not carry a principal'
);

// ------------------------------------------- the unreviewed external effect
$unreviewed = $contract;
$unreviewed['declarations']['external_effects'][0]['decided_by'] = 'unresolved';
$unreviewed = ApplicationContract::withDigest($unreviewed);
wprism_check_refuses(
    static fn () => ApplicationContract::validate($unreviewed),
    'external_effect_unreviewed',
    'an unreviewed live-effect declaration cannot become authority'
);
ApplicationContract::validate($unreviewed, false);
wprism_check(true, 'the same document is legal as a proposal, which is what a human is about to edit');

// ------------------------------------------------------------- ContractStore
$repo = wprism_contract_fixture_repo();
$store = new ContractStore($repo);
wprism_check_same($repo . '/.wprism/contract', $store->directory(), 'the store publishes under .wprism/contract');
wprism_check_same(null, $store->readContract(), 'a fresh site repo carries no contract');
wprism_check_same(null, $store->currentDigest(), 'a fresh site repo has no CAS token');

$store->writeContract($contract, null);
wprism_check(is_file($store->contractPath()), 'the contract landed on disk');
wprism_check_same(
    ApplicationContract::encode($contract),
    (string) file_get_contents($store->contractPath()),
    'the bytes on disk are the canonical encoding'
);
wprism_check_json_equal($contract, $store->readContract(), 'the stored contract reads back identically');
wprism_check_same($contract['contract_digest'], $store->currentDigest(), 'the CAS token is the stored digest');

$leftovers = array_values(array_filter(
    (array) scandir($store->directory()),
    static fn ($name): bool => is_string($name) && str_starts_with($name, '.wprism-contract-')
));
wprism_check_same([], $leftovers, 'the write-then-rename commit leaves no temporary file behind');

// The compare-and-swap: a second writer that read nothing, and a writer whose
// token is the digest of a version that has since been replaced.
$second = ApplicationContract::withDigest(array_replace_recursive(
    $contract,
    ['site' => ['name' => 'second-writer']]
));
wprism_check_refuses(
    static fn () => $store->writeContract($second, null),
    'contract_digest_stale',
    'a writer that never read the stored contract refuses instead of clobbering it'
);
wprism_check_refuses(
    static fn () => $store->writeContract($second, 'sha256:' . str_repeat('0', 64)),
    'contract_digest_stale',
    'a writer holding a stale digest refuses'
);
wprism_check_json_equal($contract, $store->readContract(), 'the refused writes changed nothing on disk');

$store->writeContract($second, $contract['contract_digest']);
wprism_check_json_equal($second, $store->readContract(), 'a writer holding the current digest succeeds');
wprism_check_refuses(
    static fn () => $store->writeContract($contract, $contract['contract_digest']),
    'contract_digest_stale',
    'the superseded digest is no longer accepted'
);

wprism_check_refuses(
    static fn () => $store->writeContract($signed, $second['contract_digest']),
    'attestation_signing_unsupported',
    'this build refuses to write a signed attestation it cannot produce'
);

wprism_check_refuses(
    static fn () => new ContractStore($repo . '/does-not-exist'),
    'site_repo_missing',
    'a store over a missing site repository refuses'
);

// -------------------------------------------------- proposal, bind and accept
$report = wprism_contract_fixture($fixtures . '/assess-report-unbound.json');
$report['assess_digest'] = ContractProposal::assessDigest($report);
ContractProposal::validateAssessReport($report);
wprism_check(true, 'the fixture assess report validates once its digest is bound');

$seed = wprism_contract_fixture($fixtures . '/proposal-seed.json');
$proposal = ContractProposal::fromAssessReport($report, $seed);
wprism_check_same(
    Canon::encode($proposal),
    Canon::encode(ContractProposal::fromAssessReport($report, $seed)),
    'the same assess report proposes byte-identical bytes'
);
wprism_check_same('wprism-application-contract-proposal/v1', $proposal['format'], 'the proposal has its own format key');
wprism_check_same($report['assess_digest'], $proposal['assess_digest'], 'the proposal binds the assess digest');
wprism_check_same(
    ['capture', 'release'],
    $proposal['contract']['declarations']['surfaces'][0]['operations'],
    'only Ready operations are proposed'
);
wprism_check_same(
    [],
    $proposal['contract']['declarations']['surfaces'][1]['operations'],
    'an Unsupported surface proposes no operation'
);
// The fixture report hands this row `qualify in rehearsal` verbatim, and the
// proposal carries it through unchanged. T6 §3.6 stopped EMITTING that word
// while keeping it in the closed set for exactly this reason: a stored
// document written by an earlier build must still validate and still round
// trip, rather than becoming a schema error an operator cannot fix.
wprism_check_same(
    'qualify in rehearsal',
    $proposal['contract']['declarations']['surfaces'][2]['next_action'],
    'a retired-but-valid gap action from an older report round trips unchanged'
);
wprism_check_same(
    [['surface' => 'orders', 'operation' => 'capture',
        'reason' => 'exclude this surface from the operation; WPrism refuses it for a stated reason']],
    $proposal['contract']['declarations']['unsupported'],
    'Unsupported projections become unsupported declarations'
);
wprism_check_same('unsigned', $proposal['contract']['attestation']['state'], 'a proposal is always unsigned');
wprism_check_same(
    null,
    $proposal['contract']['declarations']['stack']['wordpress']['max'],
    'the proposal states the observed floor and leaves the ceiling for review'
);
wprism_check_same(
    '7.0.3',
    $proposal['contract']['declarations']['stack']['wordpress']['min'],
    'the proposal pins the observed WordPress version as the floor'
);
wprism_check(
    in_array('review and decide external effect code-lifecycle-window', $proposal['review_required'], true),
    'the proposal names the lifecycle-window declaration as a review item'
);
wprism_check_same(
    count($proposal['review_required']),
    $proposal['review_required_count'],
    'the bounded review list reports its own true count'
);

wprism_check_refuses(
    static fn () => ContractProposal::accept($proposal, $report['assess_digest']),
    'external_effect_unreviewed',
    'accepting an unreviewed proposal refuses: the review step is enforced, not requested'
);

$reviewed = $proposal;
$reviewed['contract']['declarations']['external_effects'][0]['decided_by'] = 'operator';
$reviewed['contract']['declarations']['external_effects'][0]['reason'] =
    'the installed set runs no mail, payment or webhook code on activation (reviewed 2026-08-17)';
$accepted = ContractProposal::accept($reviewed, $report['assess_digest']);
ApplicationContract::validate($accepted);
wprism_check(true, 'a reviewed proposal accepts and the resulting contract validates');
wprism_check_same(
    ApplicationContract::digest($accepted),
    $accepted['contract_digest'],
    'accept recomputes the digest over the reviewed bytes'
);

wprism_check_refuses(
    static fn () => ContractProposal::accept($reviewed, 'sha256:' . str_repeat('1', 64)),
    'assess_digest_stale',
    'accept refuses a stale proposal rather than reconciling it'
);

$movedReport = $report;
$movedReport['target']['php'] = '8.3.34';
$movedReport['assess_digest'] = ContractProposal::assessDigest($movedReport);
wprism_check(
    $movedReport['assess_digest'] !== $report['assess_digest'],
    'a site that moved produces a different assess digest'
);
wprism_check_refuses(
    static fn () => ContractProposal::accept($reviewed, $movedReport['assess_digest']),
    'assess_digest_stale',
    'a proposal reviewed against the old site refuses against the new one'
);

$tampered = $report;
$tampered['unknown']['pending_count'] = 99;
wprism_check_refuses(
    static fn () => ContractProposal::validateAssessReport($tampered),
    'assess_digest_mismatch',
    'an assess report edited after it was digested refuses'
);
$unknownKey = $report;
$unknownKey['extra'] = true;
$unknownKey['assess_digest'] = ContractProposal::assessDigest($unknownKey);
wprism_check_refuses(
    static fn () => ContractProposal::validateAssessReport($unknownKey),
    'assess_report_invalid',
    'an unrecognised assess-report key refuses'
);

// --------------------------------------------- the reviewed-library comparison
// issue #3484. The report gained a `dispositions` block stating both content
// addresses — this checkout's copy of the reviewed dispositions and the one
// the target answered from — because `evidence()` held both numbers and never
// compared them.
//
// The block is a TOP-LEVEL sibling of `evidence` rather than a key inside it,
// and that placement is the wire-shape decision: `fromAssessReport()` copies
// `evidence` verbatim into `contract.evidence_pins`, whose key set
// `ApplicationContract::validateEvidencePins()` closes, so a key added inside
// `evidence` would have been a contract change. The pins assertion below is
// what holds that: adding the block moves no contract byte.
$noBlock = $report;
wprism_check(
    !array_key_exists('dispositions', $noBlock),
    'the committed fixture report predates the comparison, so it is the older-document case'
);
wprism_check(
    AssessReport::dispositionsAgree($noBlock),
    'a report with no comparison carries none that failed, so it does not gate'
);
AssessReport::requireDispositionsAgree($noBlock);
wprism_check(true, 'and the gate passes it rather than refusing a document an older build wrote');

$agreeing = $report;
$agreeing['dispositions'] = [
    'agree' => true,
    'host_registry_sha256' => $report['evidence']['registry_sha256'],
    'meaning' => ContractProposal::DISPOSITIONS_AGREE_MEANING,
    'target_registry_sha256' => $report['evidence']['registry_sha256'],
];
$agreeing['assess_digest'] = ContractProposal::assessDigest($agreeing);
ContractProposal::validateAssessReport($agreeing);
wprism_check(true, 'a report whose two reviewed libraries agree validates');
wprism_check_json_equal(
    ContractProposal::fromAssessReport($noBlock, $seed)['contract']['evidence_pins'],
    ContractProposal::fromAssessReport($agreeing, $seed)['contract']['evidence_pins'],
    'the block moves no contract byte: evidence_pins are identical with and without it'
);

$mismatched = $report;
$mismatched['dispositions'] = [
    'agree' => false,
    'host_registry_sha256' => str_repeat('7', 64),
    'meaning' => ContractProposal::DISPOSITIONS_MISMATCH_MEANING,
    'target_registry_sha256' => $report['evidence']['registry_sha256'],
];
$mismatched['assess_digest'] = ContractProposal::assessDigest($mismatched);
// It VALIDATES: assess must be able to emit and digest a mismatched
// assessment, because the mid-upgrade window is legitimate and diagnosis is
// not the unsafe act. What refuses is the attempt to pin it.
ContractProposal::validateAssessReport($mismatched);
wprism_check(true, 'a mismatched assessment is a legal document — the refusal is not at the schema');
wprism_check(
    !AssessReport::dispositionsAgree($mismatched),
    'and the report reads as disagreeing'
);
wprism_check_refuses(
    static fn () => AssessReport::requireDispositionsAgree($mismatched),
    'dispositions_mismatch',
    'pinning a mismatched assessment into a contract refuses by name'
);
try {
    AssessReport::requireDispositionsAgree($mismatched);
    wprism_check(false, 'the gate refused');
} catch (\WPrism\CommandRefusalException $refusal) {
    wprism_check(
        str_contains($refusal->publicMessage, substr((string) $mismatched['dispositions']['host_registry_sha256'], 0, 12))
        && str_contains($refusal->publicMessage, substr((string) $mismatched['dispositions']['target_registry_sha256'], 0, 12)),
        'the refusal names BOTH twelve-hex prefixes, so the operator can tell which side is which'
    );
    wprism_check(
        preg_match('/[0-9a-f]{32,}/D', $refusal->publicMessage . ' ' . $refusal->remediation) !== 1,
        'and neither prints in full: a refusal is a human surface (MUP §5.2)'
    );
    wprism_check(
        str_contains($refusal->remediation, 're-adopt') && str_contains($refusal->remediation, 'check out the revision'),
        'the remedy names both directions, because a sha256 gives no way to tell which side is ahead'
    );
}

// The three derivations the block publishes are re-derived by the validator,
// so the gate above is reading a fact rather than a claim. Each of these is a
// hand-edited document that would otherwise gate the wrong way.
$lying = $agreeing;
$lying['dispositions']['host_registry_sha256'] = str_repeat('6', 64);
$lying['assess_digest'] = ContractProposal::assessDigest($lying);
wprism_check_refuses(
    static fn () => ContractProposal::validateAssessReport($lying),
    'assess_report_invalid',
    'agree: true beside two different hashes refuses instead of gating open'
);
$wrongSentence = $mismatched;
$wrongSentence['dispositions']['meaning'] = ContractProposal::DISPOSITIONS_AGREE_MEANING;
$wrongSentence['assess_digest'] = ContractProposal::assessDigest($wrongSentence);
wprism_check_refuses(
    static fn () => ContractProposal::validateAssessReport($wrongSentence),
    'assess_report_invalid',
    'a reassuring sentence over disagreeing hashes refuses'
);
$wrongTarget = $agreeing;
$wrongTarget['dispositions']['host_registry_sha256'] = str_repeat('5', 64);
$wrongTarget['dispositions']['target_registry_sha256'] = str_repeat('5', 64);
$wrongTarget['assess_digest'] = ContractProposal::assessDigest($wrongTarget);
wprism_check_refuses(
    static fn () => ContractProposal::validateAssessReport($wrongTarget),
    'assess_report_invalid',
    'a block that agrees about a library the evidence pins do not name refuses'
);
$extraKey = $agreeing;
$extraKey['dispositions']['direction'] = 'ahead';
$extraKey['assess_digest'] = ContractProposal::assessDigest($extraKey);
wprism_check_refuses(
    static fn () => ContractProposal::validateAssessReport($extraKey),
    'assess_report_invalid',
    'the block is a closed key set like every other block in this document'
);

// The producer, from the two documents assess actually holds.
$capabilityReports = ['plan' => ['registry_sha256' => str_repeat('a', 64)]];
wprism_check_json_equal(
    [
        'agree' => true,
        'host_registry_sha256' => str_repeat('a', 64),
        'meaning' => ContractProposal::DISPOSITIONS_AGREE_MEANING,
        'target_registry_sha256' => str_repeat('a', 64),
    ],
    AssessReport::dispositions($capabilityReports, ['registry_sha256' => str_repeat('a', 64)]),
    'the producer compares the target capability report against this checkout own provenance'
);
wprism_check_same(
    false,
    AssessReport::dispositions($capabilityReports, ['registry_sha256' => str_repeat('b', 64)])['agree'],
    'and a host holding different reviewed bytes is a mismatch'
);
wprism_check_refuses(
    static fn () => AssessReport::dispositions(['plan' => ['registry_sha256' => null]], ['registry_sha256' => str_repeat('a', 64)]),
    'assess_report_unbuildable',
    'a target reporting no reviewed-library hash is unbuildable, never silently agreeing'
);

// Assess pins both the canonical whole reviewed registry and the raw subject
// document set. The physical package move must preserve both addresses: the
// raw fold therefore sorts and writes each LOGICAL <subject>.json name even
// when its bytes now reside at package/disposition.json.
$provenanceFrom = static function (array $documents): array {
    $logical = [];
    foreach ($documents as $subject => $path) {
        $logical[$subject . '.json'] = $path;
    }
    ksort($logical, SORT_STRING);
    $decoded = ['format' => 'wprism-manifest-dispositions/v1', 'manifests' => [], 'profiles' => []];
    $raw = '';
    foreach ($logical as $logicalName => $path) {
        $bytes = (string) file_get_contents($path);
        $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $raw .= $logicalName . "\n" . $bytes;
        $subject = basename($logicalName, '.json');
        if ($subject === 'profiles') {
            $decoded['profiles'] = $document;
        } else {
            $decoded['manifests'][$subject] = $document;
        }
    }

    return [
        'registry_sha256' => hash('sha256', Canon::encode($decoded)),
        'generated_from' => ['dispositions_sha256' => hash('sha256', $raw)],
    ];
};
$provenanceMethod = new ReflectionMethod(AssessCommand::class, 'registryProvenance');
$shippedLibrary = \WPrism\Policy::shipped_adapter_library();
$shippedDocuments = ['profiles' => $shippedLibrary->profilesPath()];
foreach ($shippedLibrary->packages() as $package) {
    $shippedDocuments[$package->name()] = $package->dispositionPath();
}
wprism_check_same(
    $provenanceFrom($shippedDocuments),
    $provenanceMethod->invoke(null, []),
    'assess default provenance reads the closed shipped AdapterLibrary with legacy-stable logical filenames'
);

// An explicitly supplied object must be the actual source of the fold, not a
// decorative option beside a hidden checkout glob. Change one reviewed byte
// in a complete scratch library so falling back to this checkout is visible.
$selectedRoot = sys_get_temp_dir() . '/wprism-assess-provenance-library-' . bin2hex(random_bytes(6));
$repoRoot = dirname(__DIR__, 4);
$copySelectedTree = static function (string $sourceRoot, string $destinationRoot): void {
    $sourceFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($sourceFiles as $sourceFile) {
        $relative = substr($sourceFile->getPathname(), strlen($sourceRoot) + 1);
        $destination = $destinationRoot . '/' . $relative;
        if ($sourceFile->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0777, true);
            }
        } else {
            copy($sourceFile->getPathname(), $destination);
        }
    }
};
mkdir($selectedRoot . '/adapter-packages/acf', 0777, true);
mkdir($selectedRoot . '/platform/adapter-library', 0777, true);
$copySelectedTree($repoRoot . '/adapter-packages/acf', $selectedRoot . '/adapter-packages/acf');
$copySelectedTree($repoRoot . '/platform/adapter-library', $selectedRoot . '/platform/adapter-library');
$changedDisposition = $selectedRoot . '/adapter-packages/acf/package/disposition.json';
$changedDocument = json_decode((string) file_get_contents($changedDisposition), true, 512, JSON_THROW_ON_ERROR);
$changedDocument['reason'] .= ' Scratch provenance selector.';
file_put_contents($changedDisposition, Canon::encode($changedDocument));
register_shutdown_function(static function () use ($selectedRoot): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($selectedRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($selectedRoot);
});
$selectedLibrary = \WPrism\AdapterLibrary::fromSourcePackage($selectedRoot, 'acf');
$selectedDocuments = ['profiles' => $selectedLibrary->profilesPath()];
foreach ($selectedLibrary->packages() as $package) {
    $selectedDocuments[$package->name()] = $package->dispositionPath();
}
$selectedProvenance = $provenanceMethod->invoke(null, ['adapter_library' => $selectedLibrary]);
wprism_check_same(
    $provenanceFrom($selectedDocuments),
    $selectedProvenance,
    'an explicit AdapterLibrary object, not a checkout fallback, supplies every provenance document'
);
wprism_check(
    $selectedProvenance !== $provenanceFrom($shippedDocuments),
    'changing one selected-library disposition byte changes its provenance instead of reading the checkout'
);

// During the preparatory phase an explicit manifests_dir remains a sparse
// authoring-fixture seam. It deliberately does not construct AdapterLibrary,
// whose closed inventory would reject this two-subject fixture.
$sparseLibrary = sys_get_temp_dir() . '/wprism-assess-provenance-sparse-' . bin2hex(random_bytes(6));
mkdir($sparseLibrary . '/dispositions', 0777, true);
$sparseDocuments = [
    'fixture' => $sparseLibrary . '/dispositions/fixture.json',
    'profiles' => $sparseLibrary . '/dispositions/profiles.json',
];
file_put_contents($sparseDocuments['fixture'], Canon::encode(['status' => 'excluded', 'reason' => 'fixture']));
file_put_contents($sparseDocuments['profiles'], Canon::encode(['fixture' => ['operations' => []]]));
register_shutdown_function(static function () use ($sparseLibrary): void {
    @unlink($sparseLibrary . '/dispositions/fixture.json');
    @unlink($sparseLibrary . '/dispositions/profiles.json');
    @rmdir($sparseLibrary . '/dispositions');
    @rmdir($sparseLibrary);
});
wprism_check_same(
    $provenanceFrom($sparseDocuments),
    $provenanceMethod->invoke(null, ['manifests_dir' => $sparseLibrary]),
    'an explicit sparse legacy manifests_dir preserves the pre-package provenance semantics'
);

// The proposal is per environment; the store takes the segment as a REQUIRED
// argument so the pre-issue #3503 shared `.wprism/contract/proposed.json` slot
// cannot return by omission (ContractStore.php:21-36).
$store->writeProposal('fixture', $proposal);
wprism_check_same(
    $repo . '/.wprism/contract/fixture/proposed.json',
    $store->proposalPath('fixture'),
    'the proposal is published under .wprism/contract/<env>/'
);
wprism_check_same(
    '.wprism/contract/fixture/proposed.json',
    $store->proposalRelativePath('fixture'),
    'and the printed path is the same path, relative to the site repo'
);
wprism_check_json_equal(
    $proposal,
    $store->readProposal('fixture'),
    'the proposal round-trips through the store'
);
wprism_check_same(
    null,
    $store->readProposal('other'),
    'another environment reads its own empty slot, never this one'
);
// The value becomes a directory name, so it is checked before it is joined.
foreach (['../escape', '.hidden', 'has space', ''] as $illegal) {
    wprism_check_refuses(
        static fn () => $store->proposalPath($illegal),
        'contract_environment_invalid',
        "an environment name that is not a legal path segment is refused: '$illegal'"
    );
}

wprism_check_summary('regress_contract_shape');
