<?php
/**
 * Offline characterization for the contract attestation signer — the mint, the
 * verifier, and above all the state every SHIPPED site is in: mechanism
 * complete, trust anchor empty, nothing mints.
 *
 * `duo adapter certify` has let a customer organization vouch for its own
 * adapter bytes since T6. The contract had no equivalent: every accepted
 * document carried `attestation.state: "unsigned"`, `ContractStore` refused
 * anything else with `attestation_signing_unsupported`, and the honest sentence
 * every assessment printed — `; contract attestation unsigned` — named a gap no
 * command could close. `ContractAttestation` closes it, and the properties
 * below are what make that closure honest rather than merely present:
 *
 *   1. **The default is inert.** With no `.duo/contract/authorities.json` the
 *      signer refuses `contract_attestation_unsigned_anchor` and writes
 *      nothing. No shipped site has that file; nothing but the attest verb
 *      creates it. This is the whole shipping posture and it is asserted first.
 *   2. **The old door still refuses.** `writeContract()` — the accept path —
 *      keeps refusing every signed document with the exact
 *      `attestation_signing_unsupported` envelope it always raised.
 *   3. **A signed contract round trips**, and `contract_digest` still means
 *      what it meant: the digest of the whole document minus that key. If the
 *      signature had bound `contract_digest` instead of `attested_digest`,
 *      writing it would have moved the very number `duo release` freezes into
 *      an authorization plan.
 *   4. **Tamper drops the claim, never degrades it.** One edited declaration
 *      byte refuses at `readContract()`, which is the single reader all four
 *      consumers go through.
 *   5. **Domain separation holds.** A statement signed under
 *      `AdapterCertification::SIGNATURE_DOMAIN` does not verify here — the
 *      property that file's docblock (:52) claims for itself in words.
 *   6. **Expiry and platform re-binding refuse by their own names**, so an
 *      operator is told to re-attest rather than told they were attacked.
 *   7. **`attest contract` is in the closed gap-action set and emitted by
 *      nothing**, exactly as `qualify in rehearsal` sits.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterCertification.php';
require_once __DIR__ . '/../../../../cli/src/Assess/GapActions.php';
require_once __DIR__ . '/../../../../cli/src/Command/ContractCommand.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ContractAttestation.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ContractStore.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ProjectionVocabulary.php';

use Duo\AdapterCertification;
use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\ContractAttestation;
use Duo\Orchestrator\ContractCommand;
use Duo\Orchestrator\ContractStore;
use Duo\Orchestrator\GapActions;
use Duo\Orchestrator\ProjectionVocabulary;

$root = dirname(__DIR__, 4);

/**
 * A throwaway directory, removed on exit so a failing run leaves nothing
 * behind. Same discipline as regress_contract_shape.php's fixture repo, and
 * deliberately not a committed tree: the trust root under test is a file the
 * product CREATES, so a committed one would be testing the wrong thing.
 */
function attest_tmpdir(string $prefix): string {
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
    if (!mkdir($path, 0777, true)) {
        throw new RuntimeException("cannot create temporary directory $path");
    }
    register_shutdown_function(static function () use ($path): void {
        if (!is_dir($path)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    });

    return realpath($path) ?: $path;
}

/**
 * One git invocation, argv-style with `bypass_shell`, exactly as
 * `ContractCommand::stage()` runs the `git add` under test.
 *
 * @param list<string> $args
 * @return list<string> stdout lines
 */
function attest_git(string $repo, array $args): array {
    $process = proc_open(
        array_merge(['git', '-C', $repo], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot run git');
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return array_values(array_filter(explode("\n", trim($out)), static fn (string $l): bool => $l !== ''));
}

function attest_site_repo(): string {
    $repo = attest_tmpdir('duo-attest');
    mkdir($repo . '/.duo/contract', 0777, true);
    file_put_contents(
        $repo . '/site.duo.json',
        Canon::encode(['envs' => ['production' => ['transport' => 'local']]])
    );
    // `duo init` requires this exact ignore line
    // (InitRepositoryBoundary::ensure_gitignore()), and it is what makes the
    // staging assertion below mean something: without it, `git add -f` and a
    // plain `git add` would be indistinguishable.
    file_put_contents($repo . '/.gitignore', "/.duo/\n");

    return $repo;
}

/** @return array<string,mixed> */
function attest_contract(string $root): array {
    $decoded = json_decode(
        (string) file_get_contents($root . '/sandbox/tests/fixtures/contract/contract-unbound.json'),
        true
    );
    if (!is_array($decoded)) {
        throw new RuntimeException('the contract fixture is not a JSON object');
    }
    // The fixture ships one deliberately unreviewed external effect so the
    // review gate has something to refuse; attestation is a question about a
    // contract that already passed review, so resolve it here exactly as a
    // reviewer would (ApplicationContract::UNREVIEWED_DECIDED_BY).
    foreach ($decoded['declarations']['external_effects'] as $index => $effect) {
        if (($effect['decided_by'] ?? null) === ApplicationContract::UNREVIEWED_DECIDED_BY) {
            $decoded['declarations']['external_effects'][$index]['decided_by'] = 'operator';
        }
    }

    return ApplicationContract::withDigest($decoded);
}

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$keyId = 'contract-' . substr(hash('sha256', $public), 0, 12);
$claim = [
    'approving_principal' => 'Acme Ops',
    'policy_version' => '2026-08',
    'expires_at' => '2099-01-01T00:00:00Z',
    'reason' => 'reviewed under the customer change policy',
];

// ------------------------------------------------- 1. the shipped default
$repo = attest_site_repo();
$store = new ContractStore($repo);
$contract = attest_contract($root);
ApplicationContract::validate($contract);
$store->writeContract($contract, null);

duo_check_same([], ContractAttestation::authorities($repo), 'a site with no provisioned key has no trust root');
duo_check(
    !is_file($repo . '/' . ContractAttestation::AUTHORITIES_RELATIVE),
    'and no file exists where one would be: the anchor is absent, not empty-but-present'
);
duo_check_refuses(
    static fn () => ContractAttestation::sign($contract, $repo, $keyId, $secret, $claim),
    'contract_attestation_unsigned_anchor',
    'with no trust root the signer refuses to mint — the state every shipped site is in'
);
duo_check_same(
    ApplicationContract::encode($contract),
    (string) file_get_contents($store->contractPath()),
    'and the refused mint changed no byte of the stored contract'
);

// --------------------------------------- 2. the accept path still refuses
$signedShape = $contract;
$signedShape['attestation'] = [
    'approving_principal' => 'Acme Ops',
    'expires_at' => '2099-01-01T00:00:00Z',
    'format' => ApplicationContract::ATTESTATION_FORMAT,
    'key_id' => $keyId,
    'platform_sha256' => str_repeat('a', 64),
    'policy_version' => '2026-08',
    'reason' => 'hand-written, not minted',
    'signature' => 'ed25519:deadbeef',
    'state' => 'signed',
    'trust_root' => 'site',
];
$signedShape = ApplicationContract::withDigest($signedShape);
duo_check_refuses(
    static fn () => $store->writeContract($signedShape, $contract['contract_digest']),
    'attestation_signing_unsupported',
    'the ordinary accept path keeps refusing a signed document, with the same reason code as before'
);

// ------------------------------------------------------ 3. the round trip
duo_check(
    ContractAttestation::registerAuthority($repo, $keyId, $public),
    'registering the first key writes the trust root'
);
duo_check_same(
    false,
    ContractAttestation::registerAuthority($repo, $keyId, $public),
    'registering the same key again is a no-op, so a re-attest does not churn the file'
);
$authorities = ContractAttestation::authorities($repo);
duo_check_same(
    ['algorithm' => 'ed25519', 'public_key' => base64_encode($public),
        'scope' => ContractAttestation::SCOPE, 'status' => 'trusted'],
    $authorities[$keyId] ?? [],
    'the record carries exactly the four fields the grammar defines'
);

$signed = ContractAttestation::sign($contract, $repo, $keyId, $secret, $claim);
ApplicationContract::validate($signed);
duo_check_same('signed', $signed['attestation']['state'], 'the signer produces a signed attestation');
duo_check_same('Acme Ops', $signed['attestation']['approving_principal'], 'which names the approving principal');
duo_check_same(
    ContractAttestation::TRUST_ROOT_SITE,
    $signed['attestation']['trust_root'],
    'under the site trust root, the only one this build admits'
);
duo_check_same(
    ContractAttestation::currentPlatformDigest(),
    $signed['attestation']['platform_sha256'],
    'and binds the live agent capability boundary'
);
// The property that keeps `duo release`'s frozen plan correct: the signature
// binds `attested_digest`, so `contract_digest` is still exactly what
// ApplicationContract says it is over the finished document.
duo_check_same(
    ApplicationContract::digest($signed),
    $signed['contract_digest'],
    'contract_digest is still the digest of the whole document minus itself'
);
duo_check(
    ApplicationContract::digest($signed) !== ContractAttestation::attestedDigest($signed),
    'and is a different number from attested_digest, which excludes the signature too'
);

$store->writeAttestedContract($signed, $contract['contract_digest']);
$readBack = $store->readContract();
duo_check_json_equal($signed, $readBack, 'the signed contract reads back identically through the verifying reader');
$verified = ContractAttestation::verify($signed, $repo);
duo_check_same('Acme Ops', $verified['principal'], 'verification reports who approved');
duo_check_same('site', $verified['trust_root'], 'and under which root');
duo_check_same($keyId, $verified['key_id'], 'and with which key');

// ------------------------------------------------------------- 4. tamper
$tampered = $signed;
$tampered['declarations']['journeys'] = [];
$tampered['declarations']['surfaces'][0]['operations'] = ['capture', 'merge', 'release', 'verify', 'delete'];
$tampered = ApplicationContract::withDigest($tampered);
ApplicationContract::validate($tampered);
duo_check(true, 'a tampered contract can still be made internally consistent — contract_digest is not a signature');
file_put_contents($store->contractPath(), ApplicationContract::encode($tampered));
duo_check_refuses(
    static fn () => $store->readContract(),
    'contract_attestation_signature_invalid',
    'but the single reader every consumer goes through refuses it: the claim DROPS, it does not degrade'
);
file_put_contents($store->contractPath(), ApplicationContract::encode($signed));
duo_check_json_equal($signed, $store->readContract(), 'and the untouched document still reads');

// A signature edited in place fails the same way, which is the other half of
// "the signature is not inside its own input": flipping it changes nothing the
// digest covers, so only the Ed25519 check can catch it.
$forged = $signed;
$raw = base64_decode((string) $signed['attestation']['signature'], true);
$raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";
$forged['attestation']['signature'] = base64_encode($raw);
$forged = ApplicationContract::withDigest($forged);
duo_check_refuses(
    static fn () => ContractAttestation::verify($forged, $repo),
    'contract_attestation_signature_invalid',
    'an edited signature refuses'
);

// ------------------------------------------------ 5. the key, and its status
$otherKey = $signed;
$otherKey['attestation']['key_id'] = 'contract-not-installed';
$otherKey = ApplicationContract::withDigest($otherKey);
duo_check_refuses(
    static fn () => ContractAttestation::verify($otherKey, $repo),
    'contract_attestation_key_unknown',
    'a key id no trust root carries refuses by name, before any signature arithmetic'
);
$revokedRepo = attest_site_repo();
ContractAttestation::registerAuthority($revokedRepo, $keyId, $public);
$revokedFile = ContractAttestation::authoritiesPath($revokedRepo);
$revokedDocument = json_decode((string) file_get_contents($revokedFile), true);
$revokedDocument['keys'][$keyId]['status'] = 'revoked';
file_put_contents($revokedFile, Canon::encode($revokedDocument));
duo_check_refuses(
    static fn () => ContractAttestation::verify($signed, $revokedRepo),
    'contract_attestation_key_revoked',
    'a revoked key cannot vouch for a contract, even for one it signed while trusted'
);
duo_check_refuses(
    static fn () => ContractAttestation::sign($contract, $revokedRepo, $keyId, $secret, $claim),
    'contract_attestation_key_revoked',
    'and cannot mint a new one'
);

$brokenRepo = attest_site_repo();
file_put_contents(ContractAttestation::authoritiesPath($brokenRepo), "{ not json\n");
duo_check_refuses(
    static fn () => ContractAttestation::authorities($brokenRepo),
    'contract_attestation_authorities_invalid',
    'an unreadable trust root is a broken root, never laundered into "no root"'
);
file_put_contents(
    ContractAttestation::authoritiesPath($brokenRepo),
    Canon::encode(['format' => ContractAttestation::AUTHORITIES_FORMAT, 'keys' => (object) [
        $keyId => ['algorithm' => 'ed25519', 'public_key' => base64_encode($public),
            'scope' => 'site_adapter_certification', 'status' => 'trusted'],
    ]])
);
duo_check_refuses(
    static fn () => ContractAttestation::authorities($brokenRepo),
    'contract_attestation_authorities_invalid',
    'and an adapter-scoped record in the contract trust root is refused, not silently honoured'
);

// ------------------------------------------------- 6. the trust root ruling
$platformRooted = $signed;
$platformRooted['attestation']['trust_root'] = 'platform';
$platformRooted = ApplicationContract::withDigest($platformRooted);
duo_check_refuses(
    static fn () => ContractAttestation::verify($platformRooted, $repo),
    'contract_attestation_trust_root_unsupported',
    'a platform-rooted contract attestation refuses BY NAME, so the ruling that opens it needs no schema change'
);

// ------------------------------------------------- 7. domain separation
$borrowed = $signed;
$statement = Canon::encode([
    'attested_digest' => ContractAttestation::attestedDigest($signed),
    'format' => ContractAttestation::STATEMENT_FORMAT,
]);
duo_check(
    ContractAttestation::SIGNATURE_DOMAIN !== AdapterCertification::SIGNATURE_DOMAIN,
    'the contract signature domain is not the adapter one'
);
$borrowed['attestation']['signature'] = base64_encode(sodium_crypto_sign_detached(
    AdapterCertification::SIGNATURE_DOMAIN . $statement,
    $secret
));
$borrowed = ApplicationContract::withDigest($borrowed);
duo_check_refuses(
    static fn () => ContractAttestation::verify($borrowed, $repo),
    'contract_attestation_signature_invalid',
    'and a statement signed under the adapter domain does not verify here — the independence that docblock claims'
);

// ------------------------------------------- 8. expiry and platform re-bind
$shortLived = ContractAttestation::sign($contract, $repo, $keyId, $secret, array_replace($claim, [
    'expires_at' => '2026-01-01T00:00:00Z',
]));
duo_check_refuses(
    static fn () => ContractAttestation::verify($shortLived, $repo, null, strtotime('2026-06-01T00:00:00Z')),
    'contract_attestation_expired',
    'an expired attestation REFUSES; it does not quietly become an unsigned one'
);
duo_check_same(
    ['expires_at' => '2026-01-01T00:00:00Z', 'key_id' => $keyId, 'policy_version' => '2026-08',
        'principal' => 'Acme Ops', 'trust_root' => 'site'],
    ContractAttestation::verify($shortLived, $repo, null, strtotime('2025-12-31T23:59:59Z')),
    'and the same document verifies one second before its expiry: the clock is the only difference'
);

// A manifest library whose platform boundary differs by one byte is exactly
// what an agent upgrade produces, and the refusal has to say so rather than
// reading as tampering.
$movedManifests = attest_tmpdir('duo-attest-manifests');
mkdir($movedManifests . '/capabilities', 0777, true);
$platform = json_decode((string) file_get_contents($root . '/manifests/capabilities/platform.json'));
$platform->platform->agent_version = '99.0.0';
file_put_contents($movedManifests . '/capabilities/platform.json', Canon::encode($platform));
duo_check(
    ContractAttestation::currentPlatformDigest($movedManifests) !== ContractAttestation::currentPlatformDigest(),
    'a moved platform boundary is a different digest'
);
duo_check_refuses(
    static fn () => ContractAttestation::verify($signed, $repo, $movedManifests),
    'contract_attestation_platform_moved',
    'and a signed contract read against it refuses by its own name, so the remedy reads as "re-attest"'
);

// -------------------------------------------------- 9. the shape boundary
$unsignedWithKeyId = $contract;
$unsignedWithKeyId['attestation']['key_id'] = $keyId;
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($unsignedWithKeyId)),
    'contract_shape_invalid',
    'an unsigned attestation may not carry a signed-only key, so no unsigned document gains a byte'
);
$signedWithoutRoot = $signed;
unset($signedWithoutRoot['attestation']['trust_root']);
duo_check_refuses(
    static fn () => ApplicationContract::validate(ApplicationContract::withDigest($signedWithoutRoot)),
    'contract_shape_invalid',
    'and a signed attestation missing one of them refuses, because verification cannot be done without it'
);
duo_check_refuses(
    static fn () => $store->writeAttestedContract($contract, $signed['contract_digest']),
    'contract_attestation_signature_invalid',
    'the attesting door refuses an unsigned document rather than falling back to the accept path'
);

// ------------------------------------------------- 10. the inert gap action
duo_check(
    in_array('attest contract', ProjectionVocabulary::GAP_ACTIONS, true),
    '`attest contract` is in the closed gap-action set'
);
duo_check(
    in_array('attest contract', GapActions::URGENCY, true),
    'and has an urgency rank, without which forSurfaceDetailed() would refuse the moment anything emitted it'
);
// Generalised from the line above rather than left as a one-word check: the
// only thing that reconciles the closed set with its ranking today is
// GapActions::forSurfaceDetailed() (:107-128) falling off the end of its
// URGENCY loop and refusing `assess_gap_action_invalid` at CALL time, so an
// unranked word is found by whichever operator's surface first emits it —
// never by a gate. Asserted both directions —
// a rank for a word that is not in the set is the same defect read backwards.
duo_check_same(
    [],
    array_values(array_diff(GapActions::ACTIONS, GapActions::URGENCY)),
    'every closed-set gap action has an urgency rank'
);
duo_check_same(
    [],
    array_values(array_diff(GapActions::URGENCY, GapActions::ACTIONS)),
    'and every urgency rank names a closed-set gap action'
);
duo_check_same(
    0,
    GapActions::summarise([], [])['attest contract'] ?? null,
    'and the roll-up prints it at zero, because the count is the signal'
);
foreach (GapActions::UNKNOWN_KINDS as $kind) {
    duo_check(
        GapActions::forUnknown($kind) !== 'attest contract',
        "no unknown-section finding of kind '$kind' reaches for it"
    );
}

// ------------------------------------------------------- 11. the verb wiring
duo_check(
    in_array('attest', ContractCommand::SUBCOMMANDS, true),
    'attest is a contract subcommand'
);
duo_check(
    str_contains(
        (string) file_get_contents($root . '/cli/duo'),
        'duo contract <env> show|propose|accept|attest'
    ),
    'and appears in the public usage text, so the verb is discoverable without reading the source'
);

// -------------------------------------------------- 12. the verb, end to end
//
// `ContractCommand::attest()` is private because `run()` is the only supported
// entry point, and a public shim existing only for a test would be a second
// entry point nothing else calls. Reflection is the same instrument
// AdapterCertification's own suite uses on its one test hook. What this covers
// that the class-level assertions above cannot: the closed option grammar, the
// git-worktree precondition, the secret-key file and its mode check, the
// register-then-sign ordering with its rollback, and the staging call.
$verb = new ReflectionMethod(ContractCommand::class, 'attest');
$verbRepo = attest_site_repo();
attest_git($verbRepo, ['init', '-q']);
$verbStore = new ContractStore($verbRepo);
$verbStore->writeContract(attest_contract($root), null);
$keyFile = attest_tmpdir('duo-attest-key') . '/contract.key';
file_put_contents($keyFile, base64_encode($secret));
chmod($keyFile, 0600);

duo_check_refuses(
    static fn () => $verb->invoke(null, $verbStore, $verbRepo, ['attest', '--principal=Acme Ops'], false),
    'invalid_arguments',
    'the verb refuses an attest that names no key file, rather than inventing one'
);
duo_check_refuses(
    static fn () => $verb->invoke(null, $verbStore, $verbRepo, ['attest', '--policy-versoin=2026-08'], false),
    'invalid_arguments',
    'and refuses a mistyped option instead of silently signing a statement nobody chose'
);
duo_check(
    !is_file(ContractAttestation::authoritiesPath($verbRepo)),
    'neither refusal left a trust root behind'
);

$loose = attest_tmpdir('duo-attest-loose') . '/contract.key';
file_put_contents($loose, base64_encode($secret));
chmod($loose, 0644);
duo_check_refuses(
    static fn () => $verb->invoke(null, $verbStore, $verbRepo, [
        'attest', '--secret-key-file=' . $loose, '--principal=Acme Ops', '--policy-version=2026-08',
    ], false),
    'contract_attestation_key_unreadable',
    'a group/world-readable private key is not a private key, and the verb says so'
);
duo_check(
    !is_file(ContractAttestation::authoritiesPath($verbRepo)),
    'and that refusal, too, left the repository exactly as it found it'
);

$lines = $verb->invoke(null, $verbStore, $verbRepo, [
    'attest',
    '--secret-key-file=' . $keyFile,
    '--principal=Acme Ops',
    '--policy-version=2026-08',
    '--expires=2099-01-01T00:00:00Z',
], false);
duo_check(
    str_starts_with($lines[1] ?? '', 'principal: Acme Ops (site trust root, key contract-'),
    'the verb reports the principal, the root and the derived key id'
);
$attested = $verbStore->readContract();
duo_check_same('signed', $attested['attestation']['state'] ?? null, 'and the stored contract is now signed');
duo_check(
    is_file(ContractAttestation::authoritiesPath($verbRepo)),
    'and the trust root it created is in the repository, beside the contract it authorizes'
);
duo_check_same(
    ['.duo/contract/authorities.json', '.duo/contract/contract.json'],
    attest_git($verbRepo, ['diff', '--cached', '--name-only']),
    'and both files are staged — force-added past the site repository\'s own /.duo/ ignore'
);

// The failure path leaves nothing behind. A key whose secret does not match a
// record already in the trust root is refused by sign(), after registerAuthority()
// has already run — which is exactly the window the rollback exists for.
$otherPair = sodium_crypto_sign_keypair();
$otherFile = attest_tmpdir('duo-attest-other') . '/contract.key';
file_put_contents($otherFile, base64_encode(sodium_crypto_sign_secretkey($otherPair)));
chmod($otherFile, 0600);
$rootBefore = (string) file_get_contents(ContractAttestation::authoritiesPath($verbRepo));
duo_check_refuses(
    static fn () => $verb->invoke(null, $verbStore, $verbRepo, [
        'attest', '--secret-key-file=' . $otherFile, '--principal=Acme Ops', '--policy-version=2026-08',
        '--key-id=' . $keyId,
    ], false),
    'contract_attestation_key_mismatch',
    'a second key claiming an installed key id refuses rather than invalidating everything it signed'
);
duo_check_same(
    $rootBefore,
    (string) file_get_contents(ContractAttestation::authoritiesPath($verbRepo)),
    'and the trust root is byte-identical afterwards: a failed attest leaves the repository as it found it'
);

duo_check_summary('REGRESS_CONTRACT_ATTESTATION');
