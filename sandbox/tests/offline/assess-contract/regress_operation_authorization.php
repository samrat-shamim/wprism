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
    /** @var ?callable(string):void */
    public $beforeCapture = null;

    public function __construct(private string $repo) {}
    public function name(): string { return 'production'; }
    public function driverId(): string { return 'authorization-store-test'; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return 'authorization store fixture'; }
    public function captureRaw(string $script): array {
        if (is_callable($this->beforeCapture)) {
            ($this->beforeCapture)($script);
        }
        return authorization_run(['/bin/sh', '-c', $script]);
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
$targetId = TargetOperationStore::ensureIdentity($driver);
wprism_check_same($targetId, TargetOperationStore::ensureIdentity($driver), 'target identity establishment is idempotent');
wprism_check_same($targetId, TargetOperationStore::readIdentity($driver), 'read-only prepare can re-read the stable target identity');
$gitDirectory = trim(authorization_run(['git', '-C', $scratch, 'rev-parse', '--absolute-git-dir'])['stdout']);
$controlRoot = $gitDirectory . '/wprism-control';
$controlBeforeMissingRead = array_values(array_diff(scandir($controlRoot) ?: [], ['.', '..']));
wprism_check_refuses(
    static fn () => TargetOperationStore::readAuthorityPolicy($driver),
    'target_authority_policy_unavailable',
    'a missing target authority enrollment refuses read-only status'
);
wprism_check_same(
    $controlBeforeMissingRead,
    array_values(array_diff(scandir($controlRoot) ?: [], ['.', '..'])),
    'read-only authority status creates no lock or policy byte when enrollment is absent'
);
$policySync = TargetOperationStore::syncAuthorityPolicy($driver, $trust, 'absent');
wprism_check_same(false, $policySync['replayed'], 'the explicit first target policy enrollment wins once');
wprism_check_same(
    OperationAuthorization::trustDigest($trust),
    $policySync['policy_digest'],
    'target enrollment publishes the exact reviewed policy digest'
);
$policyReplay = TargetOperationStore::syncAuthorityPolicy($driver, $trust, 'absent');
wprism_check_same(true, $policyReplay['replayed'], 'an exact lost-response policy enrollment retry is idempotent');
wprism_check_same($trust, TargetOperationStore::readAuthorityPolicy($driver), 'target status reads the enrolled canonical policy');
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
$issuedEpoch = time() - 60;
$expiresEpoch = time() + 1800;
$verificationNow = gmdate('Y-m-d\TH:i:s\Z');
$statement = [
    'actor' => 'orbit:user:agency-owner',
    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresEpoch),
    'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $issuedEpoch),
    'key_id' => 'orbit-agency-1',
    'nonce' => 'nonce-0123456789abcdef',
    'operation' => 'release',
    'operation_id' => $subject['operation_id'],
    'presentation_digest' => $subject['presentation_digest'],
    'subject_digest' => $subject['subject_digest'],
    'target_id' => $targetId,
];
$envelope = OperationAuthorization::sign($statement, $secret);
$verified = OperationAuthorization::verify($envelope, $subject, $trust, $verificationNow);
wprism_check_same('orbit:user:agency-owner', $verified['actor'], 'the verified authority is bound to its enrolled actor');
wprism_check_same(
    OperationAuthorization::envelopeDigest($envelope),
    $verified['authorization_digest'],
    'the complete signed envelope has one content identity'
);

$wrongSubject = $subject;
$wrongSubject['subject_digest'] = 'sha256:' . str_repeat('3', 64);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($envelope, $wrongSubject, $trust, $verificationNow),
    'authorization_subject_mismatch',
    'a signed release cannot be replayed over another immutable subject'
);
$wrongTarget = $subject;
$wrongTarget['target_id'] = 'wprism-target:' . str_repeat('4', 64);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($envelope, $wrongTarget, $trust, $verificationNow),
    'authorization_subject_mismatch',
    'a signed release cannot be replayed onto another target'
);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($envelope, $subject, $trust, $statement['expires_at']),
    'authorization_expired',
    'expiry is rechecked at the exact mutation boundary and equality is expired'
);
$badSignature = $envelope;
$decodedSignature = base64_decode($badSignature['signature'], true);
$decodedSignature[0] = chr(ord($decodedSignature[0]) ^ 1);
$badSignature['signature'] = base64_encode($decodedSignature);
wprism_check_refuses(
    static fn () => OperationAuthorization::verify($badSignature, $subject, $trust, $verificationNow),
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
        $verificationNow
    ),
    'authorization_grant_missing',
    'an authenticated actor still needs every plan-required grant'
);

// Target election validates the entire enrolled policy, not merely the key
// selected by this envelope. A malformed unrelated record therefore cannot
// become trusted target state through an out-of-band byte replacement.
$malformedTargetTrust = $trust;
$malformedTargetTrust['keys']['unrelated-invalid-key'] = [
    'actor' => 'orbit:user:unrelated',
    'algorithm' => 'ed25519',
    'grants' => ['operator_confirmation', 'business_owner'],
    'operations' => ['release'],
    'public_key' => base64_encode($public),
    'status' => 'trusted',
];
$malformedTargetBytes = Canon::encode($malformedTargetTrust);
$malformedTargetSubject = $subject;
$malformedTargetSubject['authority_policy_digest'] = 'sha256:' . hash('sha256', $malformedTargetBytes);
file_put_contents($controlRoot . '/authority-policy.json', $malformedTargetBytes);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $verified, $envelope, $malformedTargetSubject),
    'authorization_authority_policy_changed',
    'target election validates every canonical policy record and sorted string set before consumption'
);
file_put_contents($controlRoot . '/authority-policy.json', Canon::encode($trust));
wprism_check_same(
    null,
    TargetOperationStore::status($driver, $verified['authorization_digest']),
    'an invalid unrelated target policy record creates no consumption evidence'
);

// The host-side identity read is only an early admission check. Swap the
// target-id after that read but before the target-side consume script starts;
// the identity lock and re-read inside that same script must stop winner
// election before an authorization directory exists.
$raceStatement = $statement;
$raceStatement['nonce'] = 'nonce-target-race-01234567';
$raceEnvelope = OperationAuthorization::sign($raceStatement, $secret);
$raceVerified = OperationAuthorization::verify($raceEnvelope, $subject, $trust, $verificationNow);
$targetIdentityPath = $gitDirectory . '/wprism-control/target-id';
$raceArmed = true;
$driver->beforeCapture = static function (string $script) use (&$raceArmed, $targetIdentityPath): void {
    if (!$raceArmed || !str_contains($script, 'target-mismatch')) {
        return;
    }
    $raceArmed = false;
    file_put_contents($targetIdentityPath, 'wprism-target:' . str_repeat('9', 64) . "\n");
};
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $raceVerified, $raceEnvelope, $subject),
    'authorization_target_mismatch',
    'consumption rechecks target identity inside the locked target-side winner election'
);
$driver->beforeCapture = null;
wprism_check_same(
    null,
    TargetOperationStore::status($driver, $raceVerified['authorization_digest']),
    'a target-identity swap between precheck and election creates no consumption record'
);
file_put_contents($targetIdentityPath, $targetId . "\n");

$revokedTrust = $trust;
$revokedTrust['keys']['orbit-agency-1']['status'] = 'revoked';
$revokedDigest = OperationAuthorization::trustDigest($revokedTrust);
TargetOperationStore::syncAuthorityPolicy($driver, $revokedTrust, OperationAuthorization::trustDigest($trust));
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $verified, $envelope, $subject),
    'authorization_authority_policy_changed',
    'target-side election refuses a policy revocation after controller verification'
);
wprism_check_same(
    null,
    TargetOperationStore::status($driver, $verified['authorization_digest']),
    'post-verification target revocation creates no consumption record'
);
TargetOperationStore::syncAuthorityPolicy($driver, $trust, $revokedDigest);

$first = TargetOperationStore::consume($driver, $verified, $envelope, $subject);
wprism_check_same(false, $first['replayed'], 'the first valid envelope is durably consumed before mutation');
$replay = TargetOperationStore::consume($driver, $verified, $envelope, $subject);
wprism_check_same(true, $replay['replayed'], 'the exact same-operation replay returns the existing consumption');
wprism_check_same(
    $first['consumption'],
    $replay['consumption'],
    'same-operation replay is byte-stable rather than a second authority'
);

$outcome = ['format' => 'fixture-release-outcome/v1', 'status' => 'released'];
$completed = TargetOperationStore::complete($driver, $first['consumption'], $outcome);
wprism_check_same(false, $completed['replayed'], 'the first terminal outcome is published once');
$completedReplay = TargetOperationStore::complete($driver, $first['consumption'], $outcome);
wprism_check_same(true, $completedReplay['replayed'], 'terminal same-operation replay returns the exact prior outcome');
$stored = TargetOperationStore::status($driver, $verified['authorization_digest']);
wprism_check_same($first['consumption'], $stored['consumption'] ?? null, 'status returns the durable consumption evidence');
wprism_check_same($completed['completion'], $stored['completion'] ?? null, 'status returns the durable terminal outcome evidence');

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

$authorityLockPath = $controlRoot . '/authority.lock';
$authorityLockRegular = $controlRoot . '/authority.lock.regular';
rename($authorityLockPath, $authorityLockRegular);
symlink(basename($authorityLockRegular), $authorityLockPath);
wprism_check_refuses(
    static fn () => TargetOperationStore::readAuthorityPolicy($driver),
    'target_authority_policy_unavailable',
    'read-only authority status refuses a symlink lock instead of following it'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::syncAuthorityPolicy(
        $driver,
        $trust,
        OperationAuthorization::trustDigest($trust)
    ),
    'target_authority_policy_sync_uncertain',
    'target authority sync refuses a symlink lock before opening it'
);
unlink($authorityLockPath);
rename($authorityLockRegular, $authorityLockPath);

$identityLockPath = $controlRoot . '/identity.lock';
$identityLockRegular = $controlRoot . '/identity.lock.regular';
rename($identityLockPath, $identityLockRegular);
symlink(basename($identityLockRegular), $identityLockPath);
wprism_check_refuses(
    static fn () => TargetOperationStore::ensureIdentity($driver),
    'target_identity_unavailable',
    'target identity establishment refuses a symlink lock before opening it'
);
unlink($identityLockPath);
rename($identityLockRegular, $identityLockPath);

$realControlRoot = $gitDirectory . '/wprism-control.real';
$redirectedControlRoot = $gitDirectory . '/wprism-control.redirected';
rename($controlRoot, $realControlRoot);
mkdir($redirectedControlRoot, 0700);
symlink($redirectedControlRoot, $controlRoot);
wprism_check_refuses(
    static fn () => TargetOperationStore::readAuthorityPolicy($driver),
    'target_authority_policy_unavailable',
    'authority status refuses a symlink target control root'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::syncAuthorityPolicy($driver, $trust, 'absent'),
    'target_identity_unavailable',
    'authority sync refuses a symlink target control root before mutation'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::status($driver, $verified['authorization_digest']),
    'authorization_consumption_uncertain',
    'authorization status refuses a symlink target control root'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::consume($driver, $verified, $envelope, $subject),
    'target_identity_unavailable',
    'authorization consumption refuses a symlink target control root before election'
);
wprism_check_refuses(
    static fn () => TargetOperationStore::complete($driver, $first['consumption'], $outcome),
    'authorized_operation_outcome_uncertain',
    'authorized completion refuses a symlink target control root before publication'
);
wprism_check_same(
    [],
    array_values(array_diff(scandir($redirectedControlRoot) ?: [], ['.', '..'])),
    'status, sync and consume never follow a redirected control root or create bytes there'
);
unlink($controlRoot);
rename($realControlRoot, $controlRoot);
authorization_remove($redirectedControlRoot);

wprism_check_summary('regress_operation_authorization');
