<?php
/**
 * Offline characterization for the `duo-application-contract/v2` document
 * and the `.duo/contract/` store (round-3 MUP §3.1-§3.2, §2.6).
 *
 * The contract is the only place a human's reviewed declarations about a
 * site live, and `duo release` cites its digest inside a frozen
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

require_once __DIR__ . '/lib/check.php';

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../cli/src/Contract/ContractStore.php';
require_once __DIR__ . '/../../cli/src/Contract/ContractProposal.php';

use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\ContractProposal;
use Duo\Orchestrator\ContractStore;

$fixtures = __DIR__ . '/fixtures/contract';

/**
 * A throwaway site-repository skeleton: the two things a store needs to
 * exist inside (a repo root that looks like a site repo, and the `.duo/`
 * layout it publishes into). Generated rather than committed so the suite
 * never depends on directory bytes it cannot rebuild, and removed on exit
 * so a failing run leaves nothing behind.
 */
function duo_contract_fixture_repo(): string {
    $root = sys_get_temp_dir() . '/duo-contract-' . bin2hex(random_bytes(6));
    if (!mkdir($root . '/.duo/contract', 0777, true)) {
        throw new RuntimeException("cannot create fixture site repo at $root");
    }
    file_put_contents(
        $root . '/site.duo.json',
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
function duo_contract_fixture(string $path): array {
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
function duo_shuffle_keys(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('duo_shuffle_keys', $value);
    }

    return array_map('duo_shuffle_keys', array_reverse($value, true));
}

// ------------------------------------------------------------- the happy path
$unbound = duo_contract_fixture($fixtures . '/contract-unbound.json');
$contract = ApplicationContract::withDigest($unbound);
ApplicationContract::validate($contract);
duo_check(true, 'the reviewed fixture contract validates');
duo_check_same(
    'sha256:' . hash('sha256', Canon::encode($unbound)),
    $contract['contract_digest'],
    'contract_digest is sha256 over everything above it'
);

// ------------------------------------------------------------- canonical JSON
$encoded = ApplicationContract::encode($contract);
duo_check_same($encoded, ApplicationContract::encode($contract), 'encoding is byte-stable');
duo_check_same(
    $encoded,
    ApplicationContract::encode(duo_shuffle_keys($contract)),
    'encoding is independent of the key order the caller built'
);
duo_check(str_ends_with($encoded, "\n"), 'the canonical document ends in exactly one LF');
duo_check(!str_contains($encoded, "\r"), 'the canonical document contains no CR');
duo_check(str_contains($encoded, '"format": "duo-application-contract/v2"'), 'the canonical document is pretty-printed');
duo_check(
    strpos($encoded, '"attestation"') < strpos($encoded, '"contract_digest"'),
    'keys are sorted at the top level'
);
duo_check_json_equal($contract, Canon::decode($encoded), 'the canonical document round-trips');

// ------------------------------------------------------------ digest stability
duo_check_same(
    ApplicationContract::digest($contract),
    ApplicationContract::digest(duo_shuffle_keys($contract)),
    'the digest does not move with key order'
);
$moved = $contract;
$moved['declarations']['surfaces'][0]['handling'] = 'preserve local';
duo_check(
    ApplicationContract::digest($moved) !== ApplicationContract::digest($contract),
    'the digest moves when a declaration changes'
);
$stale = $contract;
$stale['site']['name'] = 'renamed-shop';
duo_check_refuses(
    static fn () => ApplicationContract::validate($stale),
    'contract_digest_mismatch',
    'a hand-edited contract whose digest was not recomputed refuses'
);

// --------------------------------------------------------------- closed keys
$unknown = $contract;
$unknown['declarations']['unknown_declaration'] = ['anything'];
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($unknown)),
    'contract_shape_invalid',
    'an unrecognised declarations key refuses'
);
$unknownTop = ApplicationContract::withDigest(array_merge($contract, ['notes' => 'hello']));
duo_check_refuses(
    static fn () => ApplicationContract::validate($unknownTop),
    'contract_shape_invalid',
    'an unrecognised top-level key refuses'
);
$unknownSurface = $contract;
$unknownSurface['declarations']['surfaces'][0]['owner'] = 'someone';
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($unknownSurface)),
    'contract_shape_invalid',
    'an unrecognised surface key refuses'
);
$missing = $contract;
unset($missing['declarations']['journeys']);
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($missing)),
    'contract_shape_invalid',
    'a missing required declarations key refuses'
);
$wrongFormat = ApplicationContract::withDigest(array_merge($contract, ['format' => 'duo-application-contract/v3']));
duo_check_refuses(
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
duo_check_refuses(
    static fn () => ApplicationContract::validate($priorFormat),
    'contract_format_invalid',
    'the superseded v1 generation refuses'
);
duo_check_throws(
    static fn () => ApplicationContract::validate($priorFormat),
    \Duo\CommandRefusalException::class,
    'the v1 refusal names re-proposal as v2 rather than reporting a generic format mismatch',
    're-proposed and re-accepted as duo-application-contract/v2'
);

// ------------------------------------------------------- the narrowed pins
// v2 pins two numbers and no third. Each of the four v1 keys is re-added
// individually below, because the closed-key check is what turns "this build
// ignores a pin it no longer understands" into a refusal: a document carrying
// `bundles[]` would otherwise parse, and an operator would read a per-subject
// pin list as reviewed when nothing re-checks it.
duo_check_same(
    ['registry_sha256', 'generated_from'],
    array_keys($contract['evidence_pins']),
    'a v2 contract pins the reviewed dispositions hash and its host-side provenance, and nothing else'
);
duo_check_same(
    ['dispositions_sha256'],
    array_keys($contract['evidence_pins']['generated_from']),
    'generated_from names the one document that still exists'
);
$withBundles = $contract;
$withBundles['evidence_pins']['bundles'] = [[
    'subject' => 'manifests.storefront-commerce',
    'bundle_digest' => 'sha256:' . str_repeat('7', 64),
    'bundle_schema' => 'duo-subject-certification-bundle/v1',
    'status' => 'current',
    'git_revision' => str_repeat('c', 40),
    'expires_with' => ['storefront-commerce'],
]];
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($withBundles)),
    'contract_shape_invalid',
    'a per-subject bundle pin list refuses as an unrecognised key rather than sitting inert'
);
foreach (['evidence_sha256', 'compatibility_sha256'] as $retiredInput) {
    $withInput = $contract;
    $withInput['evidence_pins']['generated_from'][$retiredInput] = 'sha256:' . str_repeat('9', 64);
    duo_check_refuses(
        static fn () => ApplicationContract::validate(ApplicationContract::withDigest($withInput)),
        'contract_shape_invalid',
        "a retired generator-input hash ($retiredInput) refuses as an unrecognised key"
    );
}
$missingPin = $contract;
unset($missingPin['evidence_pins']['generated_from']['dispositions_sha256']);
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($missingPin)),
    'contract_shape_invalid',
    'the surviving provenance hash stays required, so narrowing did not make it optional'
);

// ---------------------------------------------------------------- vocabulary
$badHandling = $contract;
$badHandling['declarations']['surfaces'][0]['handling'] = 'delete';
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($badHandling)),
    'contract_vocabulary_invalid',
    'a contract may not mint a handling word'
);
$sandboxed = $contract;
$sandboxed['declarations']['external_effects'][0]['containment'] = 'sandboxed';
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($sandboxed)),
    'contract_vocabulary_unsupported',
    'a contract may not declare sandboxed containment this profile cannot enforce'
);
$compensatable = $contract;
$compensatable['declarations']['external_effects'][0]['effect_recovery_semantics'] = 'compensatable';
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($compensatable)),
    'contract_vocabulary_unsupported',
    'a contract may not declare compensatable recovery this profile cannot enforce'
);

// ----------------------------------------------------------- attestation enum
duo_check_same(['unsigned', 'signed'], ApplicationContract::ATTESTATION_STATES, 'the attestation enum is closed');
$badState = $contract;
$badState['attestation']['state'] = 'notarized';
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($badState)),
    'attestation_state_invalid',
    'an attestation state outside the enum refuses'
);
$halfSigned = $contract;
$halfSigned['attestation']['state'] = 'signed';
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($halfSigned)),
    'contract_shape_invalid',
    'a signed attestation with no principal or signature refuses'
);
$signed = $contract;
$signed['attestation'] = [
    'format' => 'duo-contract-attestation/v1',
    'state' => 'signed',
    'reason' => 'approved under the customer policy',
    'approving_principal' => 'security@example.test',
    'policy_version' => '3',
    'signature' => 'ed25519:deadbeef',
    'expires_at' => '2027-01-01T00:00:00Z',
];
$signed = ApplicationContract::withDigest($signed);
ApplicationContract::validate($signed);
duo_check(true, 'a fully populated signed attestation still parses, so a future document is readable');
$unsignedWithPrincipal = $contract;
$unsignedWithPrincipal['attestation']['approving_principal'] = 'someone@example.test';
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($unsignedWithPrincipal)),
    'contract_shape_invalid',
    'an unsigned attestation may not carry a principal'
);

// ------------------------------------------- the unreviewed external effect
$unreviewed = $contract;
$unreviewed['declarations']['external_effects'][0]['decided_by'] = 'unresolved';
$unreviewed = ApplicationContract::withDigest($unreviewed);
duo_check_refuses(
    static fn () => ApplicationContract::validate($unreviewed),
    'external_effect_unreviewed',
    'an unreviewed live-effect declaration cannot become authority'
);
ApplicationContract::validate($unreviewed, false);
duo_check(true, 'the same document is legal as a proposal, which is what a human is about to edit');

// ------------------------------------------------------------- ContractStore
$repo = duo_contract_fixture_repo();
$store = new ContractStore($repo);
duo_check_same($repo . '/.duo/contract', $store->directory(), 'the store publishes under .duo/contract');
duo_check_same(null, $store->readContract(), 'a fresh site repo carries no contract');
duo_check_same(null, $store->currentDigest(), 'a fresh site repo has no CAS token');

$store->writeContract($contract, null);
duo_check(is_file($store->contractPath()), 'the contract landed on disk');
duo_check_same(
    ApplicationContract::encode($contract),
    (string) file_get_contents($store->contractPath()),
    'the bytes on disk are the canonical encoding'
);
duo_check_json_equal($contract, $store->readContract(), 'the stored contract reads back identically');
duo_check_same($contract['contract_digest'], $store->currentDigest(), 'the CAS token is the stored digest');

$leftovers = array_values(array_filter(
    (array) scandir($store->directory()),
    static fn ($name): bool => is_string($name) && str_starts_with($name, '.duo-contract-')
));
duo_check_same([], $leftovers, 'the write-then-rename commit leaves no temporary file behind');

// The compare-and-swap: a second writer that read nothing, and a writer whose
// token is the digest of a version that has since been replaced.
$second = ApplicationContract::withDigest(array_replace_recursive(
    $contract,
    ['site' => ['name' => 'second-writer']]
));
duo_check_refuses(
    static fn () => $store->writeContract($second, null),
    'contract_digest_stale',
    'a writer that never read the stored contract refuses instead of clobbering it'
);
duo_check_refuses(
    static fn () => $store->writeContract($second, 'sha256:' . str_repeat('0', 64)),
    'contract_digest_stale',
    'a writer holding a stale digest refuses'
);
duo_check_json_equal($contract, $store->readContract(), 'the refused writes changed nothing on disk');

$store->writeContract($second, $contract['contract_digest']);
duo_check_json_equal($second, $store->readContract(), 'a writer holding the current digest succeeds');
duo_check_refuses(
    static fn () => $store->writeContract($contract, $contract['contract_digest']),
    'contract_digest_stale',
    'the superseded digest is no longer accepted'
);

duo_check_refuses(
    static fn () => $store->writeContract($signed, $second['contract_digest']),
    'attestation_signing_unsupported',
    'this build refuses to write a signed attestation it cannot produce'
);

duo_check_refuses(
    static fn () => new ContractStore($repo . '/does-not-exist'),
    'site_repo_missing',
    'a store over a missing site repository refuses'
);

// -------------------------------------------------- proposal, bind and accept
$report = duo_contract_fixture($fixtures . '/assess-report-unbound.json');
$report['assess_digest'] = ContractProposal::assessDigest($report);
ContractProposal::validateAssessReport($report);
duo_check(true, 'the fixture assess report validates once its digest is bound');

$seed = duo_contract_fixture($fixtures . '/proposal-seed.json');
$proposal = ContractProposal::fromAssessReport($report, $seed);
duo_check_same(
    Canon::encode($proposal),
    Canon::encode(ContractProposal::fromAssessReport($report, $seed)),
    'the same assess report proposes byte-identical bytes'
);
duo_check_same('duo-application-contract-proposal/v1', $proposal['format'], 'the proposal has its own format key');
duo_check_same($report['assess_digest'], $proposal['assess_digest'], 'the proposal binds the assess digest');
duo_check_same(
    ['capture', 'release'],
    $proposal['contract']['declarations']['surfaces'][0]['operations'],
    'only Ready operations are proposed'
);
duo_check_same(
    [],
    $proposal['contract']['declarations']['surfaces'][1]['operations'],
    'an Unsupported surface proposes no operation'
);
// The fixture report hands this row `qualify in rehearsal` verbatim, and the
// proposal carries it through unchanged. T6 §3.6 stopped EMITTING that word
// while keeping it in the closed set for exactly this reason: a stored
// document written by an earlier build must still validate and still round
// trip, rather than becoming a schema error an operator cannot fix.
duo_check_same(
    'qualify in rehearsal',
    $proposal['contract']['declarations']['surfaces'][2]['next_action'],
    'a retired-but-valid gap action from an older report round trips unchanged'
);
duo_check_same(
    [['surface' => 'orders', 'operation' => 'capture',
        'reason' => 'exclude this surface from the operation; Duo refuses it for a stated reason']],
    $proposal['contract']['declarations']['unsupported'],
    'Unsupported projections become unsupported declarations'
);
duo_check_same('unsigned', $proposal['contract']['attestation']['state'], 'a proposal is always unsigned');
duo_check_same(
    null,
    $proposal['contract']['declarations']['stack']['wordpress']['max'],
    'the proposal states the observed floor and leaves the ceiling for review'
);
duo_check_same(
    '7.0.3',
    $proposal['contract']['declarations']['stack']['wordpress']['min'],
    'the proposal pins the observed WordPress version as the floor'
);
duo_check(
    in_array('review and decide external effect code-lifecycle-window', $proposal['review_required'], true),
    'the proposal names the lifecycle-window declaration as a review item'
);
duo_check_same(
    count($proposal['review_required']),
    $proposal['review_required_count'],
    'the bounded review list reports its own true count'
);

duo_check_refuses(
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
duo_check(true, 'a reviewed proposal accepts and the resulting contract validates');
duo_check_same(
    ApplicationContract::digest($accepted),
    $accepted['contract_digest'],
    'accept recomputes the digest over the reviewed bytes'
);

duo_check_refuses(
    static fn () => ContractProposal::accept($reviewed, 'sha256:' . str_repeat('1', 64)),
    'assess_digest_stale',
    'accept refuses a stale proposal rather than reconciling it'
);

$movedReport = $report;
$movedReport['target']['php'] = '8.3.34';
$movedReport['assess_digest'] = ContractProposal::assessDigest($movedReport);
duo_check(
    $movedReport['assess_digest'] !== $report['assess_digest'],
    'a site that moved produces a different assess digest'
);
duo_check_refuses(
    static fn () => ContractProposal::accept($reviewed, $movedReport['assess_digest']),
    'assess_digest_stale',
    'a proposal reviewed against the old site refuses against the new one'
);

$tampered = $report;
$tampered['unknown']['pending_count'] = 99;
duo_check_refuses(
    static fn () => ContractProposal::validateAssessReport($tampered),
    'assess_digest_mismatch',
    'an assess report edited after it was digested refuses'
);
$unknownKey = $report;
$unknownKey['extra'] = true;
$unknownKey['assess_digest'] = ContractProposal::assessDigest($unknownKey);
duo_check_refuses(
    static fn () => ContractProposal::validateAssessReport($unknownKey),
    'assess_report_invalid',
    'an unrecognised assess-report key refuses'
);

$store->writeProposal($proposal);
duo_check_json_equal($proposal, $store->readProposal(), 'the proposal round-trips through the store');

duo_check_summary('regress_contract_shape');
