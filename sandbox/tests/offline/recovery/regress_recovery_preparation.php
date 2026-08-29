<?php
/** Frozen, read-only preparation and pre-mutation recovery drift gates. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../recovery/rollback-control.php';
require_once __DIR__ . '/../../../../cli/src/Command/RecoverCommand.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RecoveryOutcome.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\OperationAuthorization;
use WPrism\Orchestrator\RecoverCommand;
use WPrism\Orchestrator\RecoveryClaim;
use WPrism\Orchestrator\RecoveryOutcome;
use WPrism\Orchestrator\RecoveryPlan;
use WPrism\Orchestrator\RecoveryTransport;
use WPrism\Orchestrator\TargetOperationStore;
use WPrism\Orchestrator\VerifiedRollbackProfile;
use WPrism\Recovery\RollbackControl;

const RECOVERY_PREP_NOW = '2030-01-01T00:30:00Z';
const RECOVERY_PREP_RECEIPT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/** A target fixture whose post-setup command vocabulary contains reads only. */
final class RecoveryPreparationDriver implements RecoveryTransport {
    /** @var list<string> */
    public array $reads = [];
    /** @var list<list<string>> */
    public array $wpReads = [];
    public int $generationOffset = 0;
    public bool $allowSetupWrites = true;

    public function __construct(private string $repo, private string $authorityRoot) {}

    public function name(): string { return 'production'; }
    public function driverId(): string { return 'recovery-preparation-fixture'; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return 'read-only recovery preparation fixture'; }

    public function captureRaw(string $script): array {
        if (preg_match("/\\s'(active-evidence|authority-status|audit|status)'\\s/", $script, $match) === 1) {
            $this->reads[] = 'rollback:' . $match[1];
            $document = match ($match[1]) {
                'active-evidence' => RollbackControl::activeEvidence($this->authorityRoot),
                'audit' => RollbackControl::auditEvidence($this->authorityRoot),
                default => RollbackControl::status($this->authorityRoot),
            };
            $document = $this->withGenerationDrift($document, $match[1]);

            return ['exit' => 0, 'stdout' => RollbackControl::canonical($document) . "\n", 'stderr' => ''];
        }
        if (str_starts_with($script, 'd=') && str_contains($script, '/checkpoints/')) {
            $this->reads[] = 'retained-catalog';
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        if (str_contains($script, 'filesize') && str_contains($script, 'checkpoint')) {
            $this->reads[] = 'checkpoint-bytes';
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        if (str_contains($script, ' rev-parse HEAD')) {
            $this->reads[] = 'target-head';
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        if (str_contains($script, ' rev-parse --absolute-git-dir')) {
            $this->reads[] = 'target-git-dir';
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        if (str_contains($script, 'identity-missing') && str_contains($script, '/target-id')) {
            $this->reads[] = 'target-identity';
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        if (str_contains($script, 'authority-policy.json') && str_contains($script, 'authority.lock')) {
            $this->reads[] = 'target-authority-policy';
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        if (str_contains($script, 'record-uncertain') && str_contains($script, '/completion')) {
            $this->reads[] = 'operation-status';
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        if ($this->allowSetupWrites && (str_contains($script, 'identity-write')
            || str_contains($script, 'publish-uncertain')
            || str_contains($script, 'outcome-uncertain')
            || str_contains($script, 'policy-write'))) {
            return recovery_preparation_run(['/bin/sh', '-c', $script]);
        }
        throw new LogicException('prepare attempted an unclassified raw command');
    }

    public function captureWp(array $wpArgs): array {
        if (!in_array('eval', $wpArgs, true)) {
            throw new LogicException('prepare attempted a non-observational WordPress command');
        }
        $this->wpReads[] = array_values(array_map('strval', $wpArgs));
        return ['exit' => 0, 'stdout' => 'single-site', 'stderr' => ''];
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function withGenerationDrift(array $document, string $action): array {
        if ($this->generationOffset === 0) return $document;
        if ($action === 'active-evidence') {
            $document['receipt']['generation'] += $this->generationOffset;
            $document['status']['generation'] += $this->generationOffset;
        } else {
            $document['generation'] += $this->generationOffset;
        }
        return $document;
    }

    public function streamWp(array $wpArgs): int { throw new LogicException('prepare must not stream WP'); }
    public function wpInstruction(array $wpArgs): string { return 'wp'; }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        throw new LogicException('prepare does not negotiate transport capabilities');
    }
    public function carriesRollbackAuthority(): bool { return true; }
    public function rollbackConfigured(): bool { return false; }
    public function rollbackKeyId(): ?string { return null; }
    public function rollbackSigningKeyPath(): ?string { return null; }
    public function recoveryConfigured(): bool { return true; }
    public function checkpointConfigured(): bool { return true; }
    public function codeReleaseConfigured(): bool { return true; }
    public function uploadProviderConfigured(): bool { return true; }
    public function effectProviderConfigured(): bool { return true; }
    public function verifiedRollbackConfigured(): bool { return true; }
    public function verifiedRollbackConfig(): ?array {
        return ['claim_ttl_seconds' => 3600, 'encryption_key_id' => 'kms-recovery-1', 'retention_seconds' => 3600];
    }
    public function recoveryConfig(): ?array { return []; }
    public function allocateControlInput(string $label): string { throw new LogicException('prepare must not allocate input'); }
    public function putControlInput(string $localPath, string $targetPath): array {
        throw new LogicException('prepare must not upload input');
    }
    public function removeControlInput(string $targetPath): array {
        throw new LogicException('prepare must not remove input');
    }
}

/** @param list<string> $argv @return array{exit:int,stdout:string,stderr:string} */
function recovery_preparation_run(array $argv): array {
    $pipes = [];
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('could not start recovery preparation fixture process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function recovery_preparation_remove(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
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

/** @return array{exit:int,stdout:string} */
function recovery_preparation_command(RecoveryPreparationDriver $driver, array $arguments): array {
    ob_start();
    try {
        $exit = RecoverCommand::run($driver, $arguments, static fn (): string => RECOVERY_PREP_NOW);
    } finally {
        $stdout = (string) ob_get_clean();
    }
    return ['exit' => $exit, 'stdout' => $stdout];
}

/** @return array{reason:string,fields:list<string>} */
function recovery_preparation_drift(callable $gate, int &$steps): array {
    try {
        $gate();
        $steps++;
    } catch (CommandRefusalException $refusal) {
        return [
            'fields' => array_values((array) ($refusal->diagnostics[0]['changed_fields'] ?? [])),
            'reason' => $refusal->reasonCode,
        ];
    }
    return ['fields' => [], 'reason' => 'not_refused'];
}

/** @param array<string,mixed> $plan */
function recovery_preparation_reverify(
    RecoveryPreparationDriver $driver,
    array $plan,
    string $at
): array {
    $prior = getcwd();
    chdir($driver->repoPath());
    try {
        return RecoverCommand::reverifyPreparation($driver, $plan, static fn (): string => $at);
    } finally {
        chdir(is_string($prior) ? $prior : __DIR__);
    }
}

$scratch = __DIR__ . '/../../../tmp/recovery-preparation-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($scratch, 0700, true);
register_shutdown_function(static fn () => recovery_preparation_remove($scratch));
wprism_check_same(0, recovery_preparation_run(['git', 'init', '-q', $scratch])['exit'], 'fixture target is a Git checkout');

$operationKeypair = sodium_crypto_sign_keypair();
$trust = [
    'format' => OperationAuthorization::TRUST_FORMAT,
    'keys' => [
        'recovery-owner-1' => [
            'actor' => 'orbit:user:recovery-owner',
            'algorithm' => 'ed25519',
            'grants' => ['business_owner', 'operator_confirmation'],
            'operations' => ['recovery', 'release'],
            'public_key' => base64_encode(sodium_crypto_sign_publickey($operationKeypair)),
            'status' => 'trusted',
        ],
    ],
    'max_clock_skew_seconds' => 30,
    'max_ttl_seconds' => 3600,
];
mkdir($scratch . '/.wprism/authority', 0700, true);
file_put_contents($scratch . '/site.wprism.json', Canon::encode(['environments' => []]));
file_put_contents($scratch . '/.wprism/authority/authorities.json', Canon::encode($trust));
wprism_check_same(
    0,
    recovery_preparation_run([
        'git', '-C', $scratch, '-c', 'user.name=Recovery Test', '-c', 'user.email=recovery@example.invalid',
        'add', 'site.wprism.json', '.wprism/authority/authorities.json',
    ])['exit'],
    'fixture policy is staged'
);
wprism_check_same(
    0,
    recovery_preparation_run([
        'git', '-C', $scratch, '-c', 'user.name=Recovery Test', '-c', 'user.email=recovery@example.invalid',
        'commit', '-qm', 'fixture policy',
    ])['exit'],
    'fixture target has an exact code head'
);

$authorityRoot = $scratch . '/.wprism/control';
$rollbackTargetId = str_repeat('b', 32);
$initial = RollbackControl::initialize($authorityRoot, $rollbackTargetId);
$rollbackKeypair = sodium_crypto_sign_keypair();
$rollbackSecret = sodium_crypto_sign_secretkey($rollbackKeypair);
RollbackControl::installPublicKey(
    $authorityRoot,
    'rollback-owner-1',
    base64_encode(sodium_crypto_sign_publickey($rollbackKeypair))
);
$hash = static fn (string $label): string => hash('sha256', $label);
$receipt = [
    'adapter_versions_sha256' => $hash('adapter versions'),
    'artifact_hash' => $hash('artifact'),
    'checkpoint_sha256' => $hash('checkpoint metadata'),
    'claim_ttl_seconds' => 3600,
    'code_release_metadata_sha256' => $hash('code release'),
    'created_at' => '2030-01-01T00:00:00Z',
    'encryption_key_id' => 'kms-recovery-1',
    'exclusion_token_sha256' => $hash('exclusion token'),
    'format' => RollbackControl::RECEIPT_FORMAT,
    'generation' => 1,
    'ledger_session_sha256' => $hash('ledger session'),
    'lifecycle_receipts_sha256' => $hash('effect receipts'),
    'owner' => 'controller:release-1',
    'prior_code_descriptor_sha256' => $hash('prior code'),
    'prior_verifier_inputs_sha256' => $hash('prior verifier'),
    'receipt_id' => RECOVERY_PREP_RECEIPT,
    'resources_inventory_sha256' => $hash('resource inventory'),
    'retention_until' => '2031-01-01T00:00:00Z',
    'runtime_fingerprints_sha256' => $hash('runtime fingerprints'),
    'signing_key_id' => 'rollback-owner-1',
    'target_id' => $initial['target_id'],
    'uploads_inventory_sha256' => $hash('upload inventory'),
];
$event = [
    'artifact_hash' => $receipt['artifact_hash'],
    'attempt' => 1,
    'claim_epoch' => 1,
    'claim_expires_at' => '2030-01-01T01:00:00Z',
    'claimant' => 'worker:release-1',
    'format' => RollbackControl::EVENT_FORMAT,
    'generation' => 1,
    'input_sha256' => $hash('claim input'),
    'operation_id' => 'promotion-claim',
    'operation_status' => 'state_transition',
    'owner' => $receipt['owner'],
    'previous_event_sha256' => str_repeat('0', 64),
    'receipt_id' => $receipt['receipt_id'],
    'result_sha256' => $hash('claim result'),
    'sequence' => 1,
    'signing_key_id' => 'rollback-owner-1',
    'state' => 'prepared',
    'target_id' => $receipt['target_id'],
    'timestamp' => '2030-01-01T00:00:00Z',
];
$request = [
    'action' => 'claim',
    'event' => RollbackControl::sign($event, 'rollback-owner-1', $rollbackSecret),
    'receipt' => RollbackControl::sign($receipt, 'rollback-owner-1', $rollbackSecret),
];
$requestPath = $scratch . '/claim.json';
file_put_contents($requestPath, RollbackControl::canonical($request) . "\n");
RollbackControl::handleRequest($authorityRoot, $requestPath);
unlink($requestPath);
$checkpointPath = $scratch . '/.wprism/rollback/' . RECOVERY_PREP_RECEIPT . '/artifacts/checkpoint.enc';
mkdir(dirname($checkpointPath), 0700, true);
$checkpointBytes = "encrypted-checkpoint-v1\n";
file_put_contents($checkpointPath, $checkpointBytes);

$driver = new RecoveryPreparationDriver($scratch, $authorityRoot);
$operationTargetId = TargetOperationStore::ensureIdentity($driver);
TargetOperationStore::syncAuthorityPolicy($driver, $trust, 'absent');
$driver->allowSetupWrites = false;
$driver->reads = [];
$priorCwd = getcwd();
chdir($scratch);
try {
    $prepared = recovery_preparation_command($driver, [
        'prepare',
        '--restore=' . RECOVERY_PREP_RECEIPT,
        '--operation-id=recovery:incident-2030-01-01',
        '--format=json',
    ]);
} finally {
    chdir(is_string($priorCwd) ? $priorCwd : __DIR__);
}
wprism_check_same(0, $prepared['exit'], 'prepare emits a frozen plan successfully');
$plan = json_decode($prepared['stdout'], true);
wprism_check(is_array($plan), 'prepare output is JSON');
if (!is_array($plan)) wprism_check_summary('regress_recovery_preparation');
RecoveryPlan::validate($plan);
wprism_check_same(RecoveryPlan::FORMAT, $plan['format'], 'prepare emits the recovery plan v1 wire format');
wprism_check_same($operationTargetId, $plan['target']['operation_target_id'], 'plan reuses stable target operation identity');
wprism_check_same(
    OperationAuthorization::trustDigest($trust),
    $plan['authority_policy_digest'],
    'plan binds the shared operation-authority policy digest'
);
wprism_check_same(
    hash('sha256', $checkpointBytes),
    $plan['checkpoint']['sha256'],
    'plan binds the encrypted checkpoint bytes independently of receipt metadata'
);
wprism_check_same('recovery', RecoveryPlan::authorizationSubject($plan)['operation'], 'plan projects into shared actor authority');
wprism_check(RecoveryPlan::isGitObjectId(str_repeat('a', 40)), 'an exact SHA-1 Git object id is admitted');
wprism_check(RecoveryPlan::isGitObjectId(str_repeat('b', 64)), 'an exact SHA-256 Git object id is admitted');
wprism_check(!RecoveryPlan::isGitObjectId(str_repeat('c', 41)), 'a 41-character pseudo object id is refused');
wprism_check(!RecoveryPlan::isGitObjectId(str_repeat('d', 63)), 'a 63-character pseudo object id is refused');
$resumableGate = new ReflectionMethod(VerifiedRollbackProfile::class, 'assertResumableView');
wprism_check_throws(
    static fn () => $resumableGate->invoke(null, [
        'evidence' => [
            'completed_operation_history' => ['effects_inverse' => []],
            'completed_operations' => [],
            'open_operations' => [],
        ],
        'status' => ['state' => 'prepared'],
    ]),
    RuntimeException::class,
    'a prepared state with pre-existing resource recovery evidence refuses before authority consumption',
    'recovery evidence exists before its signed rollback state'
);
wprism_check_throws(
    static fn () => $resumableGate->invoke(null, [
        'evidence' => [
            'completed_operation_history' => [],
            'completed_operations' => ['prior_verify' => []],
            'open_operations' => [],
        ],
        'status' => ['state' => 'rollback_pending'],
    ]),
    RuntimeException::class,
    'rollback_pending with prior verification evidence refuses before authority consumption',
    'recovery evidence exists before its signed rollback state'
);
$invalidHeadInputs = $plan;
unset(
    $invalidHeadInputs['format'],
    $invalidHeadInputs['plan_digest'],
    $invalidHeadInputs['presentation_digest'],
    $invalidHeadInputs['subject_digest']
);
$invalidHeadInputs['target_head'] = str_repeat('a', 41);
wprism_check_refuses(
    static fn () => RecoveryPlan::build($invalidHeadInputs),
    'recovery_plan_shape_invalid',
    'a Git object id between the exact SHA-1 and SHA-256 lengths is refused'
);
wprism_check_same(
    ['rollback:status', 'rollback:audit', 'retained-catalog', 'rollback:active-evidence', 'rollback:audit',
        'checkpoint-bytes', 'target-head', 'target-git-dir', 'target-authority-policy', 'target-git-dir', 'target-identity'],
    $driver->reads,
    'prepare target access is the closed read-only catalog/evidence/byte/head/identity sequence'
);
wprism_check_same(1, count($driver->wpReads), 'prepare makes one read-only topology observation');

$steps = 0;
$driver->generationOffset = 1;
$generationDrift = recovery_preparation_drift(
    static fn () => recovery_preparation_reverify($driver, $plan, '2030-01-01T00:31:00Z'),
    $steps
);
$driver->generationOffset = 0;
wprism_check_same('recovery_plan_changed', $generationDrift['reason'], 'generation drift refuses the execute gate');
wprism_check(in_array('target.generation', $generationDrift['fields'], true), 'generation refusal names its changed field');
wprism_check_same(0, $steps, 'generation drift refuses before an execution step');

file_put_contents($checkpointPath, "encrypted-checkpoint-v2\n");
$checkpointDrift = recovery_preparation_drift(
    static fn () => recovery_preparation_reverify($driver, $plan, '2030-01-01T00:32:00Z'),
    $steps
);
file_put_contents($checkpointPath, $checkpointBytes);
wprism_check_same('recovery_plan_changed', $checkpointDrift['reason'], 'checkpoint byte drift refuses the execute gate');
wprism_check(in_array('checkpoint.sha256', $checkpointDrift['fields'], true), 'checkpoint refusal names its byte hash');
wprism_check_same(0, $steps, 'checkpoint drift refuses before an execution step');

$editedInputs = $plan;
unset(
    $editedInputs['format'],
    $editedInputs['plan_digest'],
    $editedInputs['presentation_digest'],
    $editedInputs['subject_digest']
);
$editedInputs['claim'] = RecoveryClaim::build([
    'additional_does_not_restore' => ['an actor-authored recovery claim is not product recovery semantics'],
    'covered_resources' => $plan['claim']['covered_resources'],
    'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
]);
$editedPlan = RecoveryPlan::build($editedInputs);
$claimDrift = recovery_preparation_drift(
    static fn () => recovery_preparation_reverify($driver, $editedPlan, '2030-01-01T00:32:30Z'),
    $steps
);
wprism_check_same('recovery_plan_changed', $claimDrift['reason'], 'current product claim evidence rejects an edited plan claim');
wprism_check(in_array('claim.claim_digest', $claimDrift['fields'], true), 'claim refusal names the freshly derived claim digest');
wprism_check_same(0, $steps, 'claim semantics drift refuses before an execution step');

wprism_check_refuses(
    static fn () => RecoveryPlan::reverify(
        $plan,
        RecoveryPlan::currentFacts($plan),
        '2030-01-01T01:00:00Z'
    ),
    'recovery_claim_expired',
    'claim lease expiry refuses re-verification even when every frozen byte is unchanged'
);
$expiredInputs = $plan;
unset(
    $expiredInputs['format'],
    $expiredInputs['plan_digest'],
    $expiredInputs['presentation_digest'],
    $expiredInputs['subject_digest']
);
$expiredInputs['prepared_at'] = '2030-01-01T01:00:00Z';
wprism_check_refuses(
    static fn () => RecoveryPlan::build($expiredInputs),
    'recovery_claim_expired',
    'prepare refuses a claimant lease that is already expired at equality'
);
wprism_check_same(0, $steps, 'claim expiry cannot burn authority on a first recovery step');

wprism_check_same(
    0,
    recovery_preparation_run([
        'git', '-C', $scratch, '-c', 'user.name=Recovery Test', '-c', 'user.email=recovery@example.invalid',
        'commit', '--allow-empty', '-qm', 'target head drift',
    ])['exit'],
    'fixture can advance the target head'
);
$headDrift = recovery_preparation_drift(
    static fn () => recovery_preparation_reverify($driver, $plan, '2030-01-01T00:33:00Z'),
    $steps
);
wprism_check_same('recovery_plan_changed', $headDrift['reason'], 'target head drift refuses the execute gate');
wprism_check(in_array('target_head', $headDrift['fields'], true), 'head refusal names the target code fact');
wprism_check_same(0, $steps, 'head drift refuses before an execution step');

$reverified = RecoveryPlan::reverify(
    $plan,
    RecoveryPlan::currentFacts($plan),
    '2030-01-01T00:31:00Z'
);
$authorizationSubject = RecoveryPlan::authorizationSubject($plan);
$authorizationStatement = [
    'actor' => 'orbit:user:recovery-owner',
    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
    'issued_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
    'key_id' => 'recovery-owner-1',
    'nonce' => 'recovery-nonce-0123456789abcdef',
    'operation' => 'recovery',
    'operation_id' => $plan['operation_id'],
    'presentation_digest' => $plan['presentation_digest'],
    'subject_digest' => $plan['subject_digest'],
    'target_id' => $operationTargetId,
];
$authorizationEnvelope = OperationAuthorization::sign(
    $authorizationStatement,
    sodium_crypto_sign_secretkey($operationKeypair)
);
$verifiedAuthorization = OperationAuthorization::verify(
    $authorizationEnvelope,
    $authorizationSubject,
    $trust,
    gmdate('Y-m-d\TH:i:s\Z')
);
$stepsEvidence = [];
foreach (RecoveryOutcome::RECOVERY_STEPS as $stepName) {
    $stepsEvidence[] = [
        'input_sha256' => $hash($stepName . ' input'),
        'result_sha256' => $hash($stepName . ' result'),
        'status' => 'completed',
        'step' => $stepName,
    ];
}
$targetAfter = [
    'artifact_hash' => $plan['target']['artifact_hash'],
    'event_chain_sha256' => $hash('terminal event chain'),
    'exclusion_state' => 'released',
    'generation' => $plan['target']['generation'],
    'head_event_sha256' => $hash('terminal event head'),
    'receipt_id' => $plan['target']['receipt_id'],
    'rollback_target_id' => $plan['target']['rollback_target_id'],
    'sequence' => 12,
    'state' => 'rolled_back',
    'target_record_sha256' => $hash('terminal target record'),
    'terminal' => true,
];
$outcome = RecoveryOutcome::build([
    'authorization_digest' => $verifiedAuthorization['authorization_digest'],
    'environment' => 'production',
    'failure' => null,
    'operation_id' => $plan['operation_id'],
    'plan_digest' => $plan['plan_digest'],
    'reverified' => $reverified,
    'status' => RecoveryOutcome::RECOVERED,
    'steps' => $stepsEvidence,
    'subject_digest' => $plan['subject_digest'],
    'target_after' => $targetAfter,
    'target_after_sha256' => hash('sha256', Canon::encode($targetAfter)),
    'verification_sha256' => $hash('verification'),
]);
RecoveryOutcome::validate($outcome);
wprism_check_same(true, $outcome['recovered'], 'validated outcome binds authorization, plan, reverify and step evidence');
$tamperedOutcome = $outcome;
$tamperedOutcome['steps'][0]['status'] = 'failed';
wprism_check_refuses(
    static fn () => RecoveryOutcome::validate($tamperedOutcome),
    'recovery_outcome_digest_mismatch',
    'an outcome step cannot change without invalidating its evidence digest'
);

$driver->allowSetupWrites = true;
$consumed = TargetOperationStore::consume(
    $driver,
    $verifiedAuthorization,
    $authorizationEnvelope,
    $authorizationSubject
);
$driver->allowSetupWrites = false;
wprism_check_refuses(
    static fn () => RecoverCommand::priorExecutionOutcome(
        $driver,
        $plan,
        $verifiedAuthorization['authorization_digest']
    ),
    'recovery_reconciliation_required',
    'a consumed authorization without completion refuses instead of starting another recovery'
);
$driver->allowSetupWrites = true;
TargetOperationStore::complete($driver, $consumed['consumption'], $outcome);
$driver->allowSetupWrites = false;
wprism_check_same(
    RecoveryOutcome::encode($outcome),
    RecoveryOutcome::encode(
        RecoverCommand::priorExecutionOutcome($driver, $plan, $verifiedAuthorization['authorization_digest'])
    ),
    'a completed exact replay is returned from target status without rechecking expired actor authority'
);

mkdir($scratch . '/.wprism/checkpoints', 0700, true);
mkdir($scratch . '/.wprism/artifacts', 0700, true);
file_put_contents($scratch . '/.wprism/checkpoints/promote-legacy.sql.enc', "legacy-checkpoint\n");
file_put_contents(
    $scratch . '/.wprism/artifacts/promote-legacy.json',
    Canon::encode(['artifact_hash' => $hash('legacy artifact')])
);
$driver->reads = [];
$driver->wpReads = [];
$priorCwd = getcwd();
chdir($scratch);
try {
    $legacy = recovery_preparation_command($driver, [
        'prepare', '--restore=promote-legacy', '--operation-id=recovery:legacy', '--format=json',
    ]);
} finally {
    chdir(is_string($priorCwd) ? $priorCwd : __DIR__);
}
$legacyRefusal = json_decode($legacy['stdout'], true);
wprism_check_same(1, $legacy['exit'], 'legacy retained identity refuses preparation');
wprism_check_same(
    'recovery_checkpoint_identity_incomplete',
    $legacyRefusal['reason_code'] ?? null,
    'legacy refusal does not invent a generation or signed receipt identity'
);
wprism_check_same([], $driver->wpReads, 'legacy identity refusal occurs before topology or recovery execution');
wprism_check(
    !in_array('rollback:active-evidence', $driver->reads, true),
    'legacy identity refusal occurs before selecting executable authority evidence'
);

wprism_check_summary('regress_recovery_preparation');
