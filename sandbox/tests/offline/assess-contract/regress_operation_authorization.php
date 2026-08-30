<?php
/** Actor-bound, expiring, one-time release/recovery authorization. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../../../../cli/src/Authority/OperationAuthorization.php';
require_once __DIR__ . '/../../../../cli/src/Authority/TargetOperationStore.php';

use WPrism\Canon;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\OperationAuthorization;
use WPrism\Orchestrator\TargetOperationStore;

/** Minimal local target: product code still crosses captureRaw(). */
final class AuthorizationStoreDriver implements EnvironmentDriver {
    /** @param ?Closure(string):string $transform */
    public function __construct(private string $repo, private ?Closure $transform = null) {}
    public function name(): string { return 'production'; }
    public function driverId(): string { return 'authorization-store-test'; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return 'authorization store fixture'; }
    public function captureRaw(string $script): array {
        return authorization_run(['/bin/sh', '-c', $this->transform === null ? $script : ($this->transform)($script)]);
    }
    public function captureWp(array $wpArgs): array { throw new LogicException('WP must not be contacted'); }
    public function streamWp(array $wpArgs): int { throw new LogicException('WP must not be contacted'); }
    public function wpInstruction(array $wpArgs): string { return 'wp'; }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        throw new LogicException('capability negotiation is outside this target store fixture');
    }
}

/** @param list<string> $argv @return array{exit:int,stdout:string,stderr:string} */
function authorization_run(array $argv): array {
    $pipes = [];
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start authorization fixture process');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function authorization_remove(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

$scratch = __DIR__ . '/../../../tmp/operation-authorization-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);
register_shutdown_function(static fn () => authorization_remove($scratch));
wprism_check_same(0, authorization_run(['git', 'init', '-q', $scratch])['exit'], 'the target fixture is a Git checkout');

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$trust = [
    'format' => OperationAuthorization::TRUST_FORMAT,
    'keys' => [
        'orbit-agency-1' => [
            'actor' => 'orbit:user:agency-owner',
            'algorithm' => 'ed25519',
            'grants' => ['business_owner', 'operator_confirmation'],
            'operations' => ['recovery', 'release'],
            'public_key' => base64_encode($public),
            'status' => 'trusted',
        ],
    ],
    'max_clock_skew_seconds' => 30,
    'max_ttl_seconds' => 3600,
];
$authorityDirectory = $scratch . '/.wprism/authority';
mkdir($authorityDirectory, 0777, true);
file_put_contents($authorityDirectory . '/authorities.json', Canon::encode($trust));
$loadedTrust = OperationAuthorization::trust($scratch);
wprism_check_same($trust, $loadedTrust, 'the site operation authority policy is canonical and closed');

$driver = new AuthorizationStoreDriver($scratch);
$partialControlRoot = trim(authorization_run(['git', '-C', $scratch, 'rev-parse', '--absolute-git-dir'])['stdout'])
    . '/wprism-control';
mkdir($partialControlRoot, 0700);
wprism_check_refuses(
    static fn () => TargetOperationStore::status($driver, 'sha256:' . str_repeat('0', 64)),
    'authorization_consumption_uncertain',
    'authorization status refuses a partially initialized target'
);
wprism_check(
    !file_exists($partialControlRoot . '/identity.lock'),
    'authorization status creates no identity lock while refusing a partially initialized target'
);
@unlink($partialControlRoot . '/identity.lock');
$partialSubject = [
    'operation' => 'release',
    'operation_id' => 'release:partial-target-status',
    'presentation_digest' => 'sha256:' . str_repeat('1', 64),
    'subject_digest' => 'sha256:' . str_repeat('2', 64),
    'target_id' => 'wprism-target:' . str_repeat('3', 64),
];
wprism_check_refuses(
    static fn () => TargetOperationStore::statusForSubject($driver, $partialSubject),
    'authorized_operation_status_invalid',
    'operation-subject status refuses a partially initialized target'
);
wprism_check(
    !file_exists($partialControlRoot . '/identity.lock'),
    'operation-subject status creates no identity lock while refusing a partially initialized target'
);
rmdir($partialControlRoot);
$targetId = TargetOperationStore::ensureIdentity($driver);
wprism_check_same($targetId, TargetOperationStore::ensureIdentity($driver), 'target identity establishment is idempotent');
wprism_check_same($targetId, TargetOperationStore::readIdentity($driver), 'read-only prepare can re-read the stable target identity');
TargetOperationStore::syncAuthorityPolicy($driver, $trust, 'absent');
$status = authorization_run(['git', '-C', $scratch, 'status', '--porcelain=v1', '--untracked-files=no']);
wprism_check_same('', trim($status['stdout']), 'target identity lives in private Git control storage, not the worktree');

$subject = [
    'authority_policy_digest' => OperationAuthorization::trustDigest($trust),
    'operation' => 'release',
    'operation_id' => 'release:0123456789abcdef',
    'presentation_digest' => 'sha256:' . str_repeat('2', 64),
    'required_grants' => ['business_owner', 'operator_confirmation'],
    'subject_digest' => 'sha256:' . str_repeat('1', 64),
    'target_id' => $targetId,
];
$authorizationNow = gmdate('Y-m-d\TH:i:s\Z');
$authorizationIssued = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
$authorizationExpires = gmdate('Y-m-d\TH:i:s\Z', time() + 1800);
$statement = [
    'actor' => 'orbit:user:agency-owner',
    'expires_at' => $authorizationExpires,
    'issued_at' => $authorizationIssued,
    'key_id' => 'orbit-agency-1',
    'nonce' => 'nonce-0123456789abcdef',
    'operation' => 'release',
    'operation_id' => $subject['operation_id'],
    'presentation_digest' => $subject['presentation_digest'],
    'subject_digest' => $subject['subject_digest'],
    'target_id' => $targetId,
];
$envelope = OperationAuthorization::sign($statement, $secret);
$verified = OperationAuthorization::verify($envelope, $subject, $trust, $authorizationNow);
wprism_check_same('orbit:user:agency-owner', $verified['actor'], 'the verified authority is bound to its enrolled actor');
wprism_check_same(
    OperationAuthorization::envelopeDigest($envelope),
    $verified['authorization_digest'],
    'the complete signed envelope has one content identity'
);

$wrongSubject = $subject;
$wrongSubject['subject_digest'] = 'sha256:' . str_repeat('3', 64);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($envelope, $wrongSubject, $trust, $authorizationNow),
    'authorization_subject_mismatch',
    'a signed release cannot be replayed over another immutable subject'
);
$wrongTarget = $subject;
$wrongTarget['target_id'] = 'wprism-target:' . str_repeat('4', 64);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($envelope, $wrongTarget, $trust, $authorizationNow),
    'authorization_subject_mismatch',
    'a signed release cannot be replayed onto another target'
);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($envelope, $subject, $trust, $authorizationExpires),
    'authorization_expired',
    'expiry is rechecked at the exact mutation boundary and equality is expired'
);
$badSignature = $envelope;
$decodedSignature = base64_decode($badSignature['signature'], true);
$decodedSignature[0] = chr(ord($decodedSignature[0]) ^ 1);
$badSignature['signature'] = base64_encode($decodedSignature);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($badSignature, $subject, $trust, $authorizationNow),
    'authorization_signature_invalid',
    'a one-byte signature change is never authority'
);
$missingGrantTrust = $trust;
$missingGrantTrust['keys']['orbit-agency-1']['grants'] = ['operator_confirmation'];
$missingGrantSubject = $subject;
$missingGrantSubject['authority_policy_digest'] = OperationAuthorization::trustDigest($missingGrantTrust);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify(
        $envelope,
        $missingGrantSubject,
        $missingGrantTrust,
        $authorizationNow
    ),
    'authorization_grant_missing',
    'an authenticated actor still needs every plan-required grant'
);

// Simulate a process loss after durable tuple election but before consumption
// publication. Neither the elected envelope nor a newly signed replacement is
// allowed to "repair" that ambiguity by creating the missing record.
$crashSubject = $subject;
$crashSubject['operation_id'] = 'release:crash-before-consumption';
$crashStatementA = $statement;
$crashStatementA['operation_id'] = $crashSubject['operation_id'];
$crashStatementA['nonce'] = 'nonce-crash-a-0123456789';
$crashEnvelopeA = OperationAuthorization::sign($crashStatementA, $secret);
$crashVerifiedA = OperationAuthorization::verify(
    $crashEnvelopeA,
    $crashSubject,
    $trust,
    $authorizationNow
);
$crashStatementB = $crashStatementA;
$crashStatementB['nonce'] = 'nonce-crash-b-0123456789';
$crashEnvelopeB = OperationAuthorization::sign($crashStatementB, $secret);
$crashVerifiedB = OperationAuthorization::verify(
    $crashEnvelopeB,
    $crashSubject,
    $trust,
    $authorizationNow
);
$crashTuple = [
    'operation' => $crashSubject['operation'],
    'operation_id' => $crashSubject['operation_id'],
    'presentation_digest' => $crashSubject['presentation_digest'],
    'subject_digest' => $crashSubject['subject_digest'],
    'target_id' => $crashSubject['target_id'],
];
$crashTupleDigest = 'sha256:' . hash('sha256', Canon::encode($crashTuple));
$crashElection = $crashTuple + [
    'authorization_digest' => $crashVerifiedA['authorization_digest'],
    'format' => TargetOperationStore::ELECTION_FORMAT,
    'precondition_digest' => null,
    'tuple_digest' => $crashTupleDigest,
];
$crashElection['election_digest'] = 'sha256:' . hash('sha256', Canon::encode($crashElection));
$gitDirectory = trim(authorization_run(['git', '-C', $scratch, 'rev-parse', '--absolute-git-dir'])['stdout']);
$crashElectionDirectory = $gitDirectory . '/wprism-control/authorizations/operations/'
    . substr($crashTupleDigest, 7);
mkdir($crashElectionDirectory, 0700, true);
file_put_contents($crashElectionDirectory . '/election.json', Canon::encode($crashElection));
$crashStatus = TargetOperationStore::statusForSubject($driver, $crashSubject);
wprism_check_same(
    [
        'authorization_digest' => $crashVerifiedA['authorization_digest'],
        'completion' => null,
        'consumption' => null,
    ],
    $crashStatus,
    'status exposes a durable election before its winner directory exists as explicit nonterminal evidence'
);
$crashAuthorizationDirectory = $gitDirectory . '/wprism-control/authorizations/'
    . substr($crashVerifiedA['authorization_digest'], 7);
mkdir($crashAuthorizationDirectory, 0700);
wprism_check_same(
    $crashStatus,
    TargetOperationStore::statusForSubject($driver, $crashSubject),
    'status keeps the normal post-mkdir pre-consumption crash at the same explicit election sequence'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $crashVerifiedA, $crashEnvelopeA, $crashSubject),
    'authorization_consumption_uncertain',
    'the elected authorization cannot repair a crash before its consumption publication'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $crashVerifiedB, $crashEnvelopeB, $crashSubject),
    'authorization_consumption_uncertain',
    'a different authorization cannot repair a partial tuple election'
);

$first = TargetOperationStore::consume($driver, $verified, $envelope, $subject);
wprism_check_same(false, $first['replayed'], 'the first valid envelope is durably consumed before mutation');
$replay = TargetOperationStore::consume($driver, $verified, $envelope, $subject);
wprism_check_same(true, $replay['replayed'], 'the exact same-operation replay returns the existing consumption');
wprism_check_same(
    $first['consumption'],
    $replay['consumption'],
    'same-operation replay is byte-stable rather than a second authority'
);
$replacementStatement = $statement;
$replacementStatement['nonce'] = 'nonce-fedcba9876543210';
$replacementEnvelope = OperationAuthorization::sign($replacementStatement, $secret);
$replacement = OperationAuthorization::verify(
    $replacementEnvelope,
    $subject,
    $trust,
    $authorizationNow
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $replacement, $replacementEnvelope, $subject),
    'authorized_operation_reconciliation_required',
    'a fresh envelope cannot repair a consumed operation tuple whose outcome is absent'
);
wprism_check_same(
    null,
    TargetOperationStore::status($driver, $replacement['authorization_digest']),
    'the losing replacement authorization remains unconsumed'
);

$tuple = [
    'operation' => $subject['operation'],
    'operation_id' => $subject['operation_id'],
    'presentation_digest' => $subject['presentation_digest'],
    'subject_digest' => $subject['subject_digest'],
    'target_id' => $subject['target_id'],
];
$tupleDigest = 'sha256:' . hash('sha256', Canon::encode($tuple));
$electionPath = $gitDirectory . '/wprism-control/authorizations/operations/'
    . substr($tupleDigest, 7) . '/election.json';
$electionBytes = (string) file_get_contents($electionPath);
$electionDocument = json_decode($electionBytes, true);
$outcome = ['format' => 'fixture-release-outcome/v1', 'status' => 'released'];
wprism_check_same($first['consumption']['authorization_digest'], TargetOperationStore::statusForSubject($driver, $subject)['authorization_digest'] ?? null, 'status elects the durable tuple winner');
$extraElection = $electionDocument;
$extraElection['extra'] = true;
file_put_contents($electionPath, Canon::encode($extraElection));
wprism_check_refuses(
    static fn () => TargetOperationStore::statusForSubject($driver, $subject),
    'authorized_operation_status_invalid',
    'status rejects an election with an extra field'
);
$badDigestElection = $electionDocument;
$badDigestElection['election_digest'] = 'sha256:' . str_repeat('f', 64);
file_put_contents($electionPath, Canon::encode($badDigestElection));
wprism_check_refuses(
    static fn () => TargetOperationStore::statusForSubject($driver, $subject),
    'authorized_operation_status_invalid',
    'status rejects an election with a bad election digest'
);
$noncanonicalElection = json_encode(
    array_reverse($electionDocument, true),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
file_put_contents($electionPath, $noncanonicalElection);
wprism_check_refuses(
    static fn () => TargetOperationStore::statusForSubject($driver, $subject),
    'authorized_operation_status_invalid',
    'status rejects a noncanonical election encoding'
);
file_put_contents($electionPath, $electionBytes);

$authFor = static function (string $target, string $operationId, string $nonce) use ($subject, $statement, $secret, $trust, $authorizationNow): array {
    $nextSubject = $subject;
    $nextSubject['operation_id'] = $operationId;
    $nextSubject['target_id'] = $target;
    $nextStatement = $statement;
    $nextStatement['operation_id'] = $operationId;
    $nextStatement['target_id'] = $target;
    $nextStatement['nonce'] = $nonce;
    $nextEnvelope = OperationAuthorization::sign($nextStatement, $secret);
    return [
        'envelope' => $nextEnvelope,
        'subject' => $nextSubject,
        'verified' => OperationAuthorization::verify(
        $nextEnvelope,
        $nextSubject,
        $trust,
        $authorizationNow
    )];
};
$hardeningScratch = __DIR__ . '/../../../tmp/operation-hardening-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($hardeningScratch, 0777, true);
register_shutdown_function(static fn () => authorization_remove($hardeningScratch));
wprism_check_same(0, authorization_run(['git', 'init', '-q', $hardeningScratch])['exit'], 'hardening fixture is a Git checkout');
$hardeningDriver = new AuthorizationStoreDriver($hardeningScratch);
$hardeningTarget = TargetOperationStore::ensureIdentity($hardeningDriver);
TargetOperationStore::syncAuthorityPolicy($hardeningDriver, $trust, 'absent');
$hardeningGit = trim(authorization_run(['git', '-C', $hardeningScratch, 'rev-parse', '--absolute-git-dir'])['stdout']);
$hardeningRoot = $hardeningGit . '/wprism-control';
$realHardeningRoot = $hardeningRoot . '.real';
wprism_check_same(0, rename($hardeningRoot, $realHardeningRoot) ? 0 : 1, 'hardening fixture can stage a root replacement');
wprism_check_same(0, symlink($realHardeningRoot, $hardeningRoot) ? 0 : 1, 'hardening fixture can stage a root symlink');
wprism_check_refuses(
    static fn () => TargetOperationStore::readIdentity($hardeningDriver),
    'target_identity_unavailable',
    'read identity rejects a symlinked target control root'
);
unlink($hardeningRoot);
rename($realHardeningRoot, $hardeningRoot);
$hardeningAuth = $hardeningRoot . '/authorizations';
mkdir($hardeningAuth, 0700, true);
$redirectedOperations = $hardeningScratch . '/redirected-operations';
mkdir($redirectedOperations, 0700, true);
wprism_check_same(0, symlink($redirectedOperations, $hardeningAuth . '/operations') ? 0 : 1, 'hardening fixture can stage an operations symlink');
$symlinkAuthorization = $authFor($hardeningTarget, 'release:symlink-operations', 'nonce-symlink-operations');
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($hardeningDriver, $symlinkAuthorization['verified'], $symlinkAuthorization['envelope'], $symlinkAuthorization['subject']),
    'authorization_consumption_uncertain',
    'consumption refuses operations symlink redirection'
);
unlink($hardeningAuth . '/operations');
authorization_remove($redirectedOperations);
$lockSwapAuthorization = $authFor($hardeningTarget, 'release:lock-path-rebind', 'nonce-lock-path-rebind');
$lockSwapDriver = new AuthorizationStoreDriver(
    $hardeningScratch,
    static function (string $script): string {
        $needle = '$identityPath = $root . ';
        if (!str_contains($script, 'authorityPath') || !str_contains($script, 'operationLock')) return $script;
        $swap = '$heldLockPath=$operationLockPath . ".held"; $newLock=false;'
            . 'if(!@rename($operationLockPath,$heldLockPath)||!is_resource($newLock=@fopen($operationLockPath,"x"))'
            . "||!@fclose(\$newLock)){fwrite(STDERR,\"swap\\n\");exit(99);}\n";
        $changed = str_replace($needle, $swap . $needle, $script, $count);
        if ($count !== 1) throw new RuntimeException('lock rebind fixture did not reach the acquired operation lock');
        return $changed;
    }
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($lockSwapDriver, $lockSwapAuthorization['verified'], $lockSwapAuthorization['envelope'], $lockSwapAuthorization['subject']),
    'authorization_consumption_uncertain',
    'a worker whose acquired identity lock is renamed refuses before it can publish an operation'
);
wprism_check_same(
    null,
    TargetOperationStore::status($hardeningDriver, $lockSwapAuthorization['verified']['authorization_digest']),
    'the swapped-lock worker leaves no operation for a second worker to cross or replay'
);
$lockSwapSuccess = TargetOperationStore::consume(
    $hardeningDriver,
    $lockSwapAuthorization['verified'],
    $lockSwapAuthorization['envelope'],
    $lockSwapAuthorization['subject']
);
wprism_check_same(false, $lockSwapSuccess['replayed'], 'the canonical replacement lock admits one later first worker, never a split publication');
$hardeningPolicyPath = $hardeningRoot . '/authority-policy.json';
$policySwapAuthorization = $authFor(
    $hardeningTarget,
    'release:authority-policy-path-rebind',
    'nonce-authority-policy-path-rebind'
);
$policySwapDriver = new AuthorizationStoreDriver(
    $hardeningScratch,
    static function (string $script): string {
        $needle = '$policy = is_string($policyBytes)';
        if (!str_contains($script, 'authorityPath') || !str_contains($script, $needle)) return $script;
        $swap = '$activePolicyPath=$root . "/authority-policy.json";'
            . '$heldPolicyPath=$activePolicyPath . ".held";'
            . 'if(!@rename($activePolicyPath,$heldPolicyPath)||!@copy($heldPolicyPath,$activePolicyPath))'
            . '{fwrite(STDERR,"policy-swap\n");exit(99);}' . "\n";
        $changed = str_replace($needle, $swap . $needle, $script, $count);
        if ($count !== 1) throw new RuntimeException('policy rebind fixture did not reach the completed policy read');
        return $changed;
    }
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume(
        $policySwapDriver,
        $policySwapAuthorization['verified'],
        $policySwapAuthorization['envelope'],
        $policySwapAuthorization['subject']
    ),
    'authorization_authority_policy_changed',
    'consumption refuses an authority-policy inode replaced after its bytes were read'
);
unlink($hardeningPolicyPath);
rename($hardeningPolicyPath . '.held', $hardeningPolicyPath);
wprism_check_same(
    null,
    TargetOperationStore::status($hardeningDriver, $policySwapAuthorization['verified']['authorization_digest']),
    'the authority-policy replacement leaves its authorization unconsumed'
);
$hardeningIdentityPath = $hardeningRoot . '/target-id';
$identitySwapAuthorization = $authFor(
    $hardeningTarget,
    'release:target-identity-path-rebind',
    'nonce-target-identity-path-rebind'
);
$identitySwapDriver = new AuthorizationStoreDriver(
    $hardeningScratch,
    static function (string $script): string {
        $needle = 'if (!hash_equals($targetId, $identity))';
        if (!str_contains($script, 'authorityPath') || !str_contains($script, $needle)) return $script;
        $swap = '$heldIdentityPath=$identityPath . ".held";'
            . 'if(!@rename($identityPath,$heldIdentityPath)'
            . '||@file_put_contents($identityPath,"wprism-target:" . str_repeat("f",64) . "\\n")===false)'
            . '{fwrite(STDERR,"identity-swap\n");exit(99);}' . "\n";
        $changed = str_replace($needle, $swap . $needle, $script, $count);
        if ($count !== 1) throw new RuntimeException('identity rebind fixture did not reach the completed identity read');
        return $changed;
    }
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume(
        $identitySwapDriver,
        $identitySwapAuthorization['verified'],
        $identitySwapAuthorization['envelope'],
        $identitySwapAuthorization['subject']
    ),
    'authorization_target_mismatch',
    'consumption refuses a target-identity inode replaced after its bytes were read'
);
unlink($hardeningIdentityPath);
rename($hardeningIdentityPath . '.held', $hardeningIdentityPath);
wprism_check_same(
    null,
    TargetOperationStore::status($hardeningDriver, $identitySwapAuthorization['verified']['authorization_digest']),
    'the target-identity replacement leaves its authorization unconsumed'
);
$completionSwapAuthorization = $authFor(
    $hardeningTarget,
    'release:completion-target-identity-path-rebind',
    'nonce-completion-target-identity-path-rebind'
);
$completionSwapConsumption = TargetOperationStore::consume(
    $hardeningDriver,
    $completionSwapAuthorization['verified'],
    $completionSwapAuthorization['envelope'],
    $completionSwapAuthorization['subject']
);
$completionSwapDriver = new AuthorizationStoreDriver(
    $hardeningScratch,
    static function (string $script): string {
        $needle = '$directory = $root . ';
        if (!str_contains($script, 'expectedConsumption') || !str_contains($script, $needle)) return $script;
        $swap = '$heldIdentityPath=$identityPath . ".held";'
            . 'if(!@rename($identityPath,$heldIdentityPath)'
            . '||@file_put_contents($identityPath,"wprism-target:" . str_repeat("f",64) . "\\n")===false)'
            . '{fwrite(STDERR,"identity-swap\n");exit(99);}' . "\n";
        $changed = str_replace($needle, $swap . $needle, $script, $count);
        if ($count !== 1) throw new RuntimeException('completion identity rebind fixture did not reach the completed identity read');
        return $changed;
    }
);
wprism_check_refuses(
    static fn () => TargetOperationStore::complete(
        $completionSwapDriver,
        $completionSwapConsumption['consumption'],
        ['format' => 'fixture-release-outcome/v1', 'status' => 'complete']
    ),
    'authorized_operation_outcome_uncertain',
    'completion refuses a target-identity inode replaced after its bytes were read'
);
unlink($hardeningIdentityPath);
rename($hardeningIdentityPath . '.held', $hardeningIdentityPath);
$completionSwapStatus = TargetOperationStore::status(
    $hardeningDriver,
    $completionSwapAuthorization['verified']['authorization_digest']
);
wprism_check_same(
    null,
    $completionSwapStatus['completion'] ?? null,
    'the target-identity replacement publishes no terminal outcome'
);
$latePolicySwapAuthorization = $authFor(
    $hardeningTarget,
    'release:late-authority-policy-path-rebind',
    'nonce-late-authority-policy-path-rebind'
);
$latePolicySwapDriver = new AuthorizationStoreDriver(
    $hardeningScratch,
    static function (string $script): string {
        $needle = 'if (!@mkdir($directory, 0700))';
        if (!str_contains($script, 'authorityPath') || !str_contains($script, $needle)) return $script;
        $swap = '$activePolicyPath=$root . "/authority-policy.json";'
            . '$heldPolicyPath=$activePolicyPath . ".held";'
            . 'if(!@rename($activePolicyPath,$heldPolicyPath)||!@copy($heldPolicyPath,$activePolicyPath))'
            . '{fwrite(STDERR,"policy-swap\n");exit(99);}' . "\n";
        $changed = str_replace($needle, $swap . $needle, $script, $count);
        if ($count !== 1) throw new RuntimeException('late policy rebind fixture did not cross durable election');
        return $changed;
    }
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume(
        $latePolicySwapDriver,
        $latePolicySwapAuthorization['verified'],
        $latePolicySwapAuthorization['envelope'],
        $latePolicySwapAuthorization['subject']
    ),
    'authorization_authority_policy_changed',
    'consumption rebinds authority policy after election and before consumption publication'
);
unlink($hardeningPolicyPath);
rename($hardeningPolicyPath . '.held', $hardeningPolicyPath);
$latePolicyHex = substr($latePolicySwapAuthorization['verified']['authorization_digest'], 7);
wprism_check(
    !file_exists($hardeningRoot . '/authorizations/' . $latePolicyHex . '/consumption.json'),
    'a late authority-policy replacement publishes no consumption record'
);
$lateCompletionAuthorization = $authFor(
    $hardeningTarget,
    'release:late-completion-target-identity-path-rebind',
    'nonce-late-completion-target-identity-path-rebind'
);
$lateCompletionConsumption = TargetOperationStore::consume(
    $hardeningDriver,
    $lateCompletionAuthorization['verified'],
    $lateCompletionAuthorization['envelope'],
    $lateCompletionAuthorization['subject']
);
$lateCompletionDriver = new AuthorizationStoreDriver(
    $hardeningScratch,
    static function (string $script): string {
        $needle = '$temporary = @tempnam($publication, ';
        if (!str_contains($script, 'expectedConsumption') || !str_contains($script, $needle)) return $script;
        $swap = '$heldIdentityPath=$identityPath . ".held";'
            . 'if(!@rename($identityPath,$heldIdentityPath)'
            . '||@file_put_contents($identityPath,"wprism-target:" . str_repeat("f",64) . "\\n")===false)'
            . '{fwrite(STDERR,"identity-swap\n");exit(99);}' . "\n";
        $changed = str_replace($needle, $swap . $needle, $script, $count);
        if ($count !== 1) throw new RuntimeException('late completion identity rebind fixture did not cross directory publication');
        return $changed;
    }
);
wprism_check_refuses(
    static fn () => TargetOperationStore::complete(
        $lateCompletionDriver,
        $lateCompletionConsumption['consumption'],
        ['format' => 'fixture-release-outcome/v1', 'status' => 'complete']
    ),
    'authorized_operation_outcome_uncertain',
    'completion rebinds target identity immediately before outcome publication'
);
unlink($hardeningIdentityPath);
rename($hardeningIdentityPath . '.held', $hardeningIdentityPath);
$lateCompletionHex = substr($lateCompletionAuthorization['verified']['authorization_digest'], 7);
wprism_check(
    !file_exists($hardeningRoot . '/authorizations/' . $lateCompletionHex . '/completion/outcome.json'),
    'a late target-identity replacement publishes no terminal outcome'
);
$preconditionParent = $hardeningScratch . '/precondition-parent';
mkdir($preconditionParent, 0700, true);
file_put_contents($preconditionParent . '/data.txt', "parent-data\n");
file_put_contents($preconditionParent . '/lock', "lock\n");
exec('git -C ' . escapeshellarg($hardeningScratch) . ' add precondition-parent && git -C '
    . escapeshellarg($hardeningScratch) . ' -c user.name=Hardening -c user.email=hardening@example.invalid commit -qm preconditions', $gitOutput, $gitExit);
wprism_check_same(0, $gitExit, 'hardening precondition fixture has a repository head');
$hardeningHead = trim(authorization_run(['git', '-C', $hardeningScratch, 'rev-parse', 'HEAD'])['stdout']);
symlink($preconditionParent, $hardeningScratch . '/precondition-link');
$symlinkPrecondition = [
    'files' => [['bytes' => null, 'path' => 'precondition-link/data.txt', 'sha256' => hash('sha256', "parent-data\n")]],
    'format' => TargetOperationStore::PRECONDITION_FORMAT,
    'locks' => ['precondition-parent/lock'],
    'not_after' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
    'ordered_file_hashes' => [],
    'repository_head' => $hardeningHead,
];
$symlinkPreconditionAuthorization = $authFor($hardeningTarget, 'release:symlink-parent', 'nonce-symlink-parent');
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($hardeningDriver, $symlinkPreconditionAuthorization['verified'], $symlinkPreconditionAuthorization['envelope'], $symlinkPreconditionAuthorization['subject'], $symlinkPrecondition),
    'authorized_operation_precondition_changed',
    'preconditions reject a symlinked parent component'
);
unlink($hardeningScratch . '/precondition-link');
file_put_contents($hardeningScratch . '/replacement-data.txt', "replacement-data\n");
exec('git -C ' . escapeshellarg($hardeningScratch) . ' add replacement-data.txt && git -C '
    . escapeshellarg($hardeningScratch) . ' -c user.name=Hardening -c user.email=hardening@example.invalid commit -qm replacement', $gitOutput, $gitExit);
wprism_check_same(0, $gitExit, 'hardening replacement fixture has an exact repository head');
$replacementHead = trim(authorization_run(['git', '-C', $hardeningScratch, 'rev-parse', 'HEAD'])['stdout']);
$replacementPrecondition = [
    'files' => [['bytes' => null, 'path' => 'replacement-data.txt', 'sha256' => hash('sha256', "replacement-data\n")]],
    'format' => TargetOperationStore::PRECONDITION_FORMAT,
    'locks' => ['precondition-parent/lock'],
    'not_after' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
    'ordered_file_hashes' => [],
    'repository_head' => $replacementHead,
];
unlink($hardeningScratch . '/replacement-data.txt');
symlink($preconditionParent . '/data.txt', $hardeningScratch . '/replacement-data.txt');
$replacementPreconditionAuthorization = $authFor($hardeningTarget, 'release:file-replacement', 'nonce-file-replacement');
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($hardeningDriver, $replacementPreconditionAuthorization['verified'], $replacementPreconditionAuthorization['envelope'], $replacementPreconditionAuthorization['subject'], $replacementPrecondition),
    'authorized_operation_precondition_changed',
    'preconditions reject final-file replacement by symlink'
);
unlink($hardeningScratch . '/replacement-data.txt');
file_put_contents($hardeningScratch . '/replacement-data.txt', "replacement-data\n");
$repositoryLockSwapAuthorization = $authFor(
    $hardeningTarget,
    'release:repository-lock-path-rebind',
    'nonce-repository-lock-path-rebind'
);
$repositoryLockSwapDriver = new AuthorizationStoreDriver(
    $hardeningScratch,
    static function (string $script): string {
        $needle = '$locks[] = $repositoryLock;';
        if (!str_contains($script, 'precondition-repository-lock') || !str_contains($script, $needle)) {
            return $script;
        }
        $swap = '$heldRepositoryLockPath=$repositoryLockPath . ".held"; $replacementRepositoryLock=false;'
            . 'if(!@rename($repositoryLockPath,$heldRepositoryLockPath)'
            . '||!is_resource($replacementRepositoryLock=@fopen($repositoryLockPath,"x"))'
            . "||!@fclose(\$replacementRepositoryLock)){fwrite(STDERR,\"repository-swap\\n\");exit(99);}\n";
        $changed = str_replace($needle, $swap . $needle, $script, $count);
        if ($count !== 1) throw new RuntimeException('repository-lock rebind fixture did not reach the acquired lock');
        return $changed;
    }
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume(
        $repositoryLockSwapDriver,
        $repositoryLockSwapAuthorization['verified'],
        $repositoryLockSwapAuthorization['envelope'],
        $repositoryLockSwapAuthorization['subject'],
        $replacementPrecondition
    ),
    'authorized_operation_precondition_changed',
    'a replaced repository-lock path refuses before recovery operation election'
);
wprism_check_same(
    null,
    TargetOperationStore::status(
        $hardeningDriver,
        $repositoryLockSwapAuthorization['verified']['authorization_digest']
    ),
    'the repository-lock replacement leaves its authorization unconsumed'
);
@unlink($hardeningRoot . '/repository.lock.held');
$badDigestElection = $electionDocument;
$badDigestElection['election_digest'] = 'sha256:' . str_repeat('f', 64);
file_put_contents($electionPath, Canon::encode($badDigestElection));
wprism_check_refuses(
    static fn () => TargetOperationStore::complete($driver, $first['consumption'], $outcome),
    'authorized_operation_outcome_uncertain',
    'completion refuses a tampered election digest'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $replacement, $replacementEnvelope, $subject),
    'authorization_consumption_uncertain',
    'consumption refuses a tampered election before tuple replay'
);
file_put_contents($electionPath, $electionBytes);

$completed = TargetOperationStore::complete($driver, $first['consumption'], $outcome);
wprism_check_same(false, $completed['replayed'], 'the first terminal outcome is published once');
$completedReplay = TargetOperationStore::complete($driver, $first['consumption'], $outcome);
wprism_check_same(true, $completedReplay['replayed'], 'terminal same-operation replay returns the exact prior outcome');
$stored = TargetOperationStore::status($driver, $verified['authorization_digest']);
wprism_check_same($first['consumption'], $stored['consumption'] ?? null, 'status returns the durable consumption evidence');
wprism_check_same($completed['completion'], $stored['completion'] ?? null, 'status returns the durable terminal outcome evidence');
$targetIdentityPath = $gitDirectory . '/wprism-control/target-id';
$storedTargetIdentity = (string) file_get_contents($targetIdentityPath);
file_put_contents($targetIdentityPath, 'wprism-target:' . str_repeat('f', 64) . "\n");
wprism_check_refuses(
    static fn () => TargetOperationStore::status($driver, $verified['authorization_digest']),
    'authorized_operation_status_invalid',
    'completed status cannot replay target A evidence after the canonical target identity becomes B'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::statusForSubject($driver, $subject),
    'authorized_operation_status_invalid',
    'subject status cannot elect target A evidence after the canonical target identity becomes B'
);
file_put_contents($targetIdentityPath, $storedTargetIdentity);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $replacement, $replacementEnvelope, $subject),
    'authorized_operation_already_completed',
    'a different envelope cannot claim an earlier authorization\'s completed operation as its replay'
);
wprism_check_same(
    null,
    TargetOperationStore::status($driver, $replacement['authorization_digest']),
    'completed tuple conflict still leaves the different authorization unconsumed'
);

$forgedConsumption = $first['consumption'];
$forgedConsumption['operation_id'] = 'release:fedcba9876543210';
unset($forgedConsumption['consumption_digest']);
$forgedConsumption['consumption_digest'] = 'sha256:' . hash('sha256', Canon::encode($forgedConsumption));
wprism_check_refuses(
    static fn () => TargetOperationStore::complete($driver, $forgedConsumption, $outcome),
    'authorization_consumption_conflict',
    'completion rechecks the exact target-side consumption rather than trusting caller-supplied record bytes'
);

$differentOutcome = ['format' => 'fixture-release-outcome/v1', 'status' => 'failed'];
wprism_check_refuses(
    static fn () => TargetOperationStore::complete($driver, $first['consumption'], $differentOutcome),
    'authorized_operation_outcome_conflict',
    'one authorization cannot publish two terminal outcomes'
);

$compactPath = $scratch . '/compact-authorization.json';
file_put_contents($compactPath, json_encode($envelope, JSON_UNESCAPED_SLASHES));
wprism_check_refuses(
    static fn () => OperationAuthorization::readEnvelope($compactPath),
    'operation_authorization_noncanonical',
    'exact presented bytes are canonical rather than a loosely equivalent JSON object'
);

wprism_check_summary('regress_operation_authorization');
