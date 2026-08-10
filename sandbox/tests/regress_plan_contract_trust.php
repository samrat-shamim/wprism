<?php
// DUO-3384: incomplete agent plan envelopes fail closed at the promotion
// trust boundaries.
//
// PlanSummary::render() deliberately tolerates partial fixtures, so a valid
// JSON object such as `{}` renders as a CLEAN plan. Callers that turn
// render(...)['ok'] into durable evidence must therefore validate the
// complete envelope first. This suite drives `{}`, a plan missing one
// required bucket, and a valid complete plan through the two promotion
// reconciliation boundaries in cli/duo, then pins the validator's bucket
// list to what agent/src/Apply.php actually emits. The third boundary,
// branch-environment convergence, is exercised in
// regress_environment_materializer.php, which already owns the materializer
// harness that reaches it.
//
// Deliberately ordered so the trust-boundary behavior is asserted before any
// direct PlanContract reference: this suite must fail on BEHAVIOR against a
// parent commit that has no validator, not merely on a missing class.
declare(strict_types=1);

/**
 * Load the public host shell without entering its executable main() block —
 * the same idiom regress_frozen_materialization_promotion.php uses, so this
 * suite exercises the shipped functions rather than a copy of them.
 */
$duoSource = file_get_contents(__DIR__ . '/../../cli/duo');
if (!is_string($duoSource)) {
    fwrite(STDERR, "FAIL: could not read public duo shell\n");
    exit(1);
}
$duoMain = "\ntry {\n    exit(main(\$argv));";
$duoAt = strpos($duoSource, $duoMain);
if ($duoAt === false) {
    fwrite(STDERR, "FAIL: public duo shell main guard moved\n");
    exit(1);
}
$duoPhp = strpos($duoSource, '<?php');
if ($duoPhp === false || $duoPhp > $duoAt) {
    fwrite(STDERR, "FAIL: public duo shell PHP prologue moved\n");
    exit(1);
}
$duoSource = substr($duoSource, $duoPhp + 5, $duoAt - ($duoPhp + 5)); // strip shebang and `<?php`
$duoSource = str_replace('__DIR__', var_export(dirname(__DIR__, 2) . '/cli', true), $duoSource);
eval($duoSource);

use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\RollbackAuthority;
use Duo\Orchestrator\SshTransport;
use Duo\Recovery\RollbackControl;

function pct_fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function pct_ok(bool $condition, string $message): void {
    if (!$condition) pct_fail($message);
    echo "ok: $message\n";
}

/** Assert a refusal whose operator diagnostic names the violated contract. */
function pct_refuses(callable $call, string $needle, string $message): void {
    try {
        $call();
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), $needle)) {
            pct_fail("$message (unexpected diagnostic: {$e->getMessage()})");
        }
        pct_ok(true, $message);
        return;
    }
    pct_fail("$message (no refusal)");
}

/**
 * One complete `wp duo plan --format=json` envelope, written out literally
 * rather than derived from PlanContract: a fixture that asked the validator
 * what it wanted would prove nothing about the validator.
 *
 * @param array<string,list<mixed>> $overrides
 * @return array<string,list<mixed>>
 */
function pct_plan(array $overrides = []): array {
    return $overrides + [
        'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
        'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
        'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
        'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
        'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
        'skipped_user_meta' => [],
        'unchanged' => [], 'update' => [], 'uploads_inventory' => [], 'warnings' => [],
    ];
}

/** @return array<string,list<mixed>> */
function pct_plan_without(string $bucket): array {
    $plan = pct_plan();
    if (!array_key_exists($bucket, $plan)) pct_fail("fixture plan has no '$bucket' bucket to drop");
    unset($plan[$bucket]);
    return $plan;
}

function pct_json(array $plan): string {
    return (string) json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Content-address one compiled artifact exactly the way the target verifies it. */
function pct_artifact(string $revisionHash): array {
    $artifact = [
        'deletions' => [], 'effects_inventory' => [], 'format' => 'duo-compiled-repository/v1',
        'revision_hash' => $revisionHash, 'tree' => [], 'uploads_inventory' => [],
    ];
    $artifact['artifact_hash'] = hash('sha256', json_encode(
        $artifact,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
    return $artifact;
}

function pct_write(string $path, string $contents): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        pct_fail('could not create fixture directory ' . dirname($path));
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) pct_fail("could not write $path");
}

function pct_remove(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') pct_remove($path . '/' . $name);
    @rmdir($path);
}

/**
 * In-memory frozen-promotion target. It answers only the read-only evidence
 * calls reconciliation makes, plus the mutating verbs whose ABSENCE is the
 * proof that a refusal happened before any target mutation.
 */
final class PlanContractPromotionDriver implements EnvironmentDriver {
    /** @var array<string,string> */
    public array $files = [];
    /** @var list<array{kind:string,args:mixed}> */
    public array $calls = [];

    public function __construct(
        private string $repo,
        public string $artifactHash,
        public string $planJson
    ) {}

    public function name(): string { return 'branch'; }
    public function driverId(): string { return 'plan-contract-branch'; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return 'plan contract fixture'; }

    public function captureRaw(string $script): array {
        $this->calls[] = ['kind' => 'raw', 'args' => $script];
        if (str_starts_with($script, 'mkdir -p ')) return $this->ok();
        if (str_starts_with($script, 'php -r ')) {
            $marker = " -- '";
            $start = strrpos($script, $marker);
            if ($start === false) return $this->fail('malformed PHP hash fixture');
            $path = substr($script, $start + strlen($marker), -1);
            if (str_contains($path, '/.duo/artifacts/')) return $this->ok($this->artifactHash . "\n");
            if (!isset($this->files[$path])) return $this->fail('missing file');
            return $this->ok(hash('sha256', $this->files[$path]) . "\n");
        }
        if (str_starts_with($script, 'test -L ')) return $this->fail('not a symlink');
        if (str_starts_with($script, 'test ')) {
            if (preg_match("/^test (-[efs]) '([^']+)'$/D", $script, $m) !== 1) {
                return $this->fail('malformed test fixture');
            }
            $present = isset($this->files[$m[2]]);
            $ok = match ($m[1]) {
                '-e', '-f' => $present,
                '-s' => $present && $this->files[$m[2]] !== '',
                default => false,
            };
            return $ok ? $this->ok() : $this->fail('absent');
        }
        return $this->ok();
    }

    public function captureWp(array $args): array {
        $this->calls[] = ['kind' => 'wp', 'args' => $args];
        if (($args[0] ?? '') === 'db' && ($args[1] ?? '') === 'export') {
            $this->files[(string) ($args[2] ?? '')] = "-- frozen checkpoint\n";
            return $this->ok();
        }
        if (($args[1] ?? '') === 'plan') return $this->ok($this->planJson . "\n");
        return $this->ok();
    }

    public function streamWp(array $args): int { $this->calls[] = ['kind' => 'stream', 'args' => $args]; return 0; }
    public function wpInstruction(array $args): string { return 'plan contract fixture'; }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver($this->name(), $this->driverId(), $operation, [
            DriverCapability::ATTACH => true,
            DriverCapability::CODE_MATERIALIZE => true,
            DriverCapability::DB_SNAPSHOT_CREATE => true,
            DriverCapability::DB_SNAPSHOT_RESTORE => true,
            DriverCapability::RAW_CONTROL => true,
            DriverCapability::WP_CONTROL => true,
        ]);
    }

    /** @return list<string> */
    public function wpVerbs(int $from = 0): array {
        $verbs = [];
        foreach (array_slice($this->calls, $from) as $call) {
            if ($call['kind'] === 'wp') $verbs[] = (string) ($call['args'][1] ?? '');
        }
        return $verbs;
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function ok(string $stdout = ''): array { return ['exit' => 0, 'stdout' => $stdout, 'stderr' => '']; }
    /** @return array{exit:int,stdout:string,stderr:string} */
    private function fail(string $stderr): array { return ['exit' => 1, 'stdout' => '', 'stderr' => $stderr]; }
}

$repoPath = '/target/repo';
$operation = '20260809-123456-' . str_repeat('b', 24);
$stateRevision = hash('sha256', 'plan-contract-state');
$artifact = pct_artifact($stateRevision);
$artifactHash = (string) $artifact['artifact_hash'];
$summary = [
    'artifact_hash' => $artifactHash,
    'code' => ['code_revision' => hash('sha256', 'plan-contract-code')],
    'manifest_hash' => hash('sha256', 'plan-contract-manifests'),
    'revision_hash' => $stateRevision,
];
$context = [
    'operation_id' => $operation,
    'promotion_owner' => 'duo-env-promotion-' . $operation,
    'artifact_path' => $repoPath . '/.duo/artifacts/materialize-' . $operation . '.json',
    'checkpoint_path' => $repoPath . '/.duo/checkpoints/materialize-' . $operation . '.sql',
    'compiled_summary' => $summary,
];

$incomplete = [
    'an empty plan object' => '{}',
    'a plan missing one required bucket' => pct_json(pct_plan_without('conflict')),
    'a plan whose required bucket is not a list' => pct_json(pct_plan(['conflict' => ['blocked' => true]])),
    // DUO-3388: a COMPLETE envelope (every required bucket present and a list)
    // whose one populated bucket carries a NON-ARRAY row. requireComplete()'s
    // predecessor stopped at "exists and is a list", so this reached
    // PlanSummary::label(array $r) and raised an uncaught TypeError at every
    // trust boundary. The row-shape floor turns it into the same house-style
    // refusal — proven end to end, and named by bucket/index, further below.
    'a plan whose populated bucket carries a non-array row' => pct_json(pct_plan(['conflict' => [true]])),
];

// ------------------------------------------- boundary: frozen reconciliation
//
// The checkpoint is already complete and the artifact already matches, which
// is exactly the state in which a lost controller response is reconciled into
// a receipt from the target's own plan.
foreach ($incomplete as $label => $planJson) {
    $driver = new PlanContractPromotionDriver($repoPath, $artifactHash, $planJson);
    $driver->files[$context['checkpoint_path']] = "-- frozen checkpoint\n";
    $reconcileContext = $context;
    pct_refuses(
        static function () use ($driver, $repoPath, &$reconcileContext): void {
            frozen_promotion_reconcile($driver, $repoPath, $reconcileContext);
        },
        'frozen promotion reconciliation: incomplete agent plan envelope',
        "$label refuses frozen promotion reconciliation"
    );
    pct_ok(!array_key_exists('_checkpoint_hash', $reconcileContext),
        "$label synthesizes no receipt evidence from frozen promotion reconciliation");

    $promoted = new PlanContractPromotionDriver($repoPath, $artifactHash, $planJson);
    $promoted->files[$context['checkpoint_path']] = "-- frozen checkpoint\n";
    $result = cmd_promote_frozen($promoted, $context);
    pct_ok($result === 1, "$label refuses the whole frozen promotion instead of returning a receipt");
    $verbs = $promoted->wpVerbs();
    pct_ok(!in_array('promotion-begin', $verbs, true) && !in_array('apply', $verbs, true),
        "$label refuses before any target mutation");
}

// DUO-3388: the row-shape refusal is house-style AND names the bucket and the
// offending index, not a bare TypeError. pct_refuses catches Throwable, so a
// reverted floor would let render()'s "must be of type array, bool given"
// TypeError through here — but its message does NOT contain this needle, so
// this assertion is exactly what the floor's mutation test trips on.
$rowFloorDriver = new PlanContractPromotionDriver($repoPath, $artifactHash, pct_json(pct_plan(['conflict' => [true]])));
$rowFloorDriver->files[$context['checkpoint_path']] = "-- frozen checkpoint\n";
$rowFloorContext = $context;
pct_refuses(
    static function () use ($rowFloorDriver, $repoPath, &$rowFloorContext): void {
        frozen_promotion_reconcile($rowFloorDriver, $repoPath, $rowFloorContext);
    },
    'frozen promotion reconciliation: incomplete agent plan envelope (conflict row 0 is not a JSON object)',
    'a complete envelope with a non-array row refuses house-style, naming the bucket and offending index'
);

$cleanDriver = new PlanContractPromotionDriver($repoPath, $artifactHash, pct_json(pct_plan()));
$cleanDriver->files[$context['checkpoint_path']] = "-- frozen checkpoint\n";
$cleanContext = $context;
$receipt = frozen_promotion_reconcile($cleanDriver, $repoPath, $cleanContext);
pct_ok(is_array($receipt)
    && ($receipt['status'] ?? null) === 'completed'
    && ($receipt['artifact_hash'] ?? null) === $artifactHash
    && ($receipt['owner'] ?? null) === $context['promotion_owner']
    && ($receipt['state_revision'] ?? null) === $stateRevision,
    'a complete clean plan still reconciles the exact frozen promotion receipt');

$driftDriver = new PlanContractPromotionDriver(
    $repoPath,
    $artifactHash,
    pct_json(pct_plan(['drift' => [['path' => 'state/options/core.json', 'type' => 'option', 'uuid' => 'options/core']]]))
);
$driftDriver->files[$context['checkpoint_path']] = "-- frozen checkpoint\n";
$driftContext = $context;
pct_ok(frozen_promotion_reconcile($driftDriver, $repoPath, $driftContext) === null,
    'a complete but unclean plan still declines reconciliation without refusing the operation');

// --------------------------------- boundary: verified frozen reconciliation
//
// The verified path reads its checkpoint identity from the external rollback
// authority, so this drives a real SshTransport against a real committed
// control root through a fake ssh/wp pair on PATH.
$tmp = sys_get_temp_dir() . '/duo-plan-contract-' . bin2hex(random_bytes(8));
$oldPath = (string) getenv('PATH');
try {
    $sshRepo = $tmp . '/target-repo';
    $wpPath = $tmp . '/wordpress';
    $planFile = $tmp . '/plan.json';
    $bin = $tmp . '/bin';
    if (!mkdir($wpPath, 0700, true) || !mkdir($bin, 0700, true)) pct_fail('could not create ssh fixture root');
    $sshArtifactPath = $sshRepo . '/.duo/artifacts/materialize-' . $operation . '.json';
    pct_write($sshArtifactPath, json_encode(
        $artifact,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
    pct_write($sshRepo . '/.duo/checkpoints/materialize-' . $operation . '.sql', "-- frozen checkpoint\n");

    pct_write($bin . '/ssh', "#!/usr/bin/env bash\nset -euo pipefail\nremote=\"\${!#}\"\nexec /bin/sh -c \"\$remote\"\n");
    chmod($bin . '/ssh', 0700);
    pct_write($bin . '/wp', <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$args = $argv;
array_shift($args);
if (($args[0] ?? '') === 'duo' && ($args[1] ?? '') === 'plan') {
    echo file_get_contents((string) getenv('DUO_PLAN_CONTRACT_PLAN'));
    exit(0);
}
exit(0);
PHP);
    chmod($bin . '/wp', 0700);
    putenv('PATH=' . $bin . ':' . $oldPath);

    // A committed generation whose receipt binds this exact artifact, owner,
    // and checkpoint digest — the only preconditions the verified path checks
    // before it trusts the target's plan.
    $control = $sshRepo . '/.duo/control';
    // The adopted runtime is the whole recovery directory, not one file:
    // rollback-control.php require_once()s its executor/bundle siblings.
    foreach (glob(dirname(__DIR__, 2) . '/recovery/*.php') ?: [] as $recoverySource) {
        pct_write($control . '/recovery-runtime/' . basename($recoverySource), (string) file_get_contents($recoverySource));
    }
    $keyId = 'plan-contract-key';
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $initial = RollbackControl::initialize($control);
    RollbackControl::installPublicKey($control, $keyId, base64_encode(sodium_crypto_sign_publickey($keypair)));
    $checkpointDigest = hash('sha256', 'plan-contract-checkpoint');
    $hash = static fn(string $value): string => hash('sha256', $value);
    $authorityReceipt = [
        'adapter_versions_sha256' => $hash('adapters'),
        'artifact_hash' => $artifactHash,
        'checkpoint_sha256' => $checkpointDigest,
        'claim_ttl_seconds' => 30,
        'code_release_metadata_sha256' => $hash('code-release'),
        'created_at' => '2026-08-01T00:00:00Z',
        'encryption_key_id' => 'kms-plan-contract',
        'exclusion_token_sha256' => $hash('exclusion'),
        'format' => RollbackControl::RECEIPT_FORMAT,
        'generation' => 1,
        'ledger_session_sha256' => $hash('ledger'),
        'lifecycle_receipts_sha256' => $hash('lifecycle'),
        'owner' => $context['promotion_owner'],
        'prior_code_descriptor_sha256' => $hash('prior-code'),
        'prior_verifier_inputs_sha256' => $hash('prior-verifiers'),
        'receipt_id' => str_repeat('c', 48),
        'resources_inventory_sha256' => $hash('resources'),
        'retention_until' => '2027-12-31T00:00:00Z',
        'runtime_fingerprints_sha256' => $hash('runtime'),
        'signing_key_id' => $keyId,
        'target_id' => (string) $initial['target_id'],
        'uploads_inventory_sha256' => $hash('uploads'),
    ];
    $authorityEvent = static function (array $status, string $state, string $timestamp) use ($authorityReceipt): array {
        return [
            'artifact_hash' => $authorityReceipt['artifact_hash'],
            'attempt' => 1,
            'claim_epoch' => 1,
            'claim_expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($timestamp) + 30),
            'claimant' => 'plan-contract-worker',
            'format' => RollbackControl::EVENT_FORMAT,
            'generation' => 1,
            'input_sha256' => hash('sha256', 'input-' . $state),
            'operation_id' => 'advance',
            'operation_status' => 'state_transition',
            'owner' => $authorityReceipt['owner'],
            'previous_event_sha256' => (string) ($status['head_event_sha256'] ?? str_repeat('0', 64)),
            'receipt_id' => $authorityReceipt['receipt_id'],
            'result_sha256' => hash('sha256', 'result-' . $state),
            'sequence' => (int) ($status['sequence'] ?? 0) + 1,
            'signing_key_id' => $authorityReceipt['signing_key_id'],
            'state' => $state,
            'target_id' => $authorityReceipt['target_id'],
            'timestamp' => $timestamp,
        ];
    };
    $submit = static function (array $request) use ($control): array {
        $path = tempnam(sys_get_temp_dir(), 'duo-plan-contract-request-');
        if ($path === false) pct_fail('could not allocate authority request');
        pct_write($path, RollbackControl::canonical($request) . "\n");
        try {
            return RollbackControl::handleRequest($control, $path);
        } finally {
            @unlink($path);
        }
    };
    $status = $submit([
        'action' => 'claim',
        'event' => RollbackControl::sign(
            $authorityEvent(['sequence' => 0, 'head_event_sha256' => str_repeat('0', 64)], 'prepared', '2026-08-01T00:00:00Z'),
            $keyId,
            $secret
        ),
        'receipt' => RollbackControl::sign($authorityReceipt, $keyId, $secret),
    ]);
    foreach (['promoting' => '2026-08-01T00:00:01Z', 'verifying_new' => '2026-08-01T00:00:02Z', 'committed' => '2026-08-01T00:00:03Z'] as $state => $at) {
        $status = $submit([
            'action' => 'append',
            'event' => RollbackControl::sign($authorityEvent($status, $state, $at), $keyId, $secret),
            'receipt' => null,
        ]);
    }
    pct_ok(($status['state'] ?? null) === 'committed', 'fixture rollback authority reaches a committed generation');

    $transport = new SshTransport('plan-contract-target', [
        'host' => 'fixture-host',
        'repo_path' => $sshRepo,
        'transport' => 'ssh',
        'wp_path' => $wpPath,
    ]);
    $authorityStatus = RollbackAuthority::status($transport);
    pct_ok(($authorityStatus['state'] ?? null) === 'committed'
        && ($authorityStatus['artifact_hash'] ?? null) === $artifactHash
        && ($authorityStatus['owner'] ?? null) === $context['promotion_owner'],
        'fixture target publishes the verified committed evidence the reconciler reads');

    $sshContextBase = [
        'operation_id' => $operation,
        'promotion_owner' => $context['promotion_owner'],
        'artifact_path' => $sshArtifactPath,
        'checkpoint_path' => $sshRepo . '/.duo/checkpoints/materialize-' . $operation . '.sql',
        'compiled_summary' => $summary,
    ];
    foreach ($incomplete as $label => $planJson) {
        pct_write($planFile, $planJson . "\n");
        putenv('DUO_PLAN_CONTRACT_PLAN=' . $planFile);
        $verifiedContext = $sshContextBase;
        pct_refuses(
            static function () use ($transport, $sshRepo, &$verifiedContext): void {
                frozen_promotion_verified_reconcile($transport, $sshRepo, $verifiedContext);
            },
            'verified frozen promotion reconciliation: incomplete agent plan envelope',
            "$label refuses verified frozen promotion reconciliation"
        );
        pct_ok(!array_key_exists('_checkpoint_hash', $verifiedContext),
            "$label synthesizes no receipt evidence from verified reconciliation");
    }

    pct_write($planFile, pct_json(pct_plan()) . "\n");
    putenv('DUO_PLAN_CONTRACT_PLAN=' . $planFile);
    $verifiedContext = $sshContextBase;
    $verifiedReceipt = frozen_promotion_verified_reconcile($transport, $sshRepo, $verifiedContext);
    pct_ok(is_array($verifiedReceipt)
        && ($verifiedReceipt['status'] ?? null) === 'completed'
        && ($verifiedReceipt['artifact_hash'] ?? null) === $artifactHash
        && ($verifiedReceipt['checkpoint_identity'] ?? null) === $checkpointDigest,
        'a complete clean plan still reconciles the verified provider commit');

    pct_write($planFile, pct_json(pct_plan(['conflict' => [['path' => 'state/posts/a.json', 'type' => 'post', 'uuid' => 'a']]])) . "\n");
    putenv('DUO_PLAN_CONTRACT_PLAN=' . $planFile);
    $verifiedContext = $sshContextBase;
    pct_ok(frozen_promotion_verified_reconcile($transport, $sshRepo, $verifiedContext) === null,
        'a complete but unclean plan still declines the verified reconciliation');
} finally {
    putenv('PATH=' . $oldPath);
    putenv('DUO_PLAN_CONTRACT_PLAN');
    pct_remove($tmp);
}

// ------------------------------------------------------ the contract itself
$contract = \Duo\Orchestrator\PlanContract::class;
pct_ok($contract::violations(pct_plan()) === [], 'a complete envelope has no contract violations');
pct_ok($contract::violations([]) === array_map(
    static fn(string $bucket): string => "missing $bucket",
    $contract::requiredBuckets()
), 'an empty plan object names every missing bucket rather than rendering clean');
// DUO-3342's bucket, pinned like `warnings` above because it carries the same
// hazard: PlanSummary::render() defaults it (`$plan['regen_context'] ?? []`),
// so an envelope that simply omits it renders as though no derived-state
// receipt were outstanding — and `ok` is what the convergence and frozen-
// promotion reconciliation boundaries turn into a receipt. Requiring the
// bucket is what makes those `ok` reads trustworthy for it.
pct_ok($contract::violations(pct_plan_without('regen_context')) === ['missing regen_context'],
    'an envelope omitting the outstanding-receipt bucket is incomplete, so a caller cannot read a clean `ok` '
    . 'off a document that never carried it');
pct_ok(\Duo\Orchestrator\PlanSummary::render(pct_plan_without('regen_context'))['ok'] === true
    && \Duo\Orchestrator\PlanSummary::render(pct_plan([
        'regen_context' => [['uuid' => 'u', 'type' => 'post', 'post_type' => 'p', 'kind' => 'delete']],
    ]))['ok'] === false,
    'and that is exactly the fail-open it closes: the renderer calls the omitting document clean while the '
    . 'same document carrying the row is not — the difference the contract now refuses to let through');
pct_ok($contract::violations(pct_plan_without('warnings')) === ['missing warnings'],
    'one absent bucket is named exactly once');
pct_ok($contract::violations(pct_plan(['drift' => ['state/options/core.json' => []]])) === ['drift is not a list'],
    'a bucket that is an object rather than a list fails closed');
pct_ok($contract::violations(pct_plan(['create' => 'none'])) === ['create is not a list'],
    'a bucket that is a scalar fails closed');
// DUO-3388 row-shape floor. A complete envelope whose bucket IS a list but
// carries a non-array row is named by bucket and offending index — the cheap
// floor beneath the deliberately-unvalidated row field shapes.
pct_ok($contract::violations(pct_plan(['conflict' => [true]])) === ['conflict row 0 is not a JSON object'],
    'a non-array row in a required object bucket is named by bucket and index');
pct_ok($contract::violations(pct_plan([
        'conflict' => [['path' => 'state/posts/a.json', 'type' => 'post', 'uuid' => 'a'], false],
    ])) === ['conflict row 1 is not a JSON object'],
    'the floor names the FIRST offending index and stays bounded to one violation per bucket');
pct_ok($contract::violations(pct_plan([
        'conflict' => [['path' => 'state/posts/a.json', 'type' => 'post', 'uuid' => 'a']],
    ])) === [],
    'a well-formed object row clears the floor, so no valid envelope regresses');
// `warnings` is the one required bucket whose rows are legitimately not
// objects (a plain list<string>); the object-row floor must exempt it or it
// would refuse every real plan that carries a warning line.
pct_ok($contract::violations(pct_plan(['warnings' => ['previous apply did not complete required rebuilds']])) === [],
    'the object-row floor exempts warnings, the sole list<string> bucket');
// The floor is beneath is-a-list, not a replacement: a non-list bucket is
// still named "is not a list" and the floor never runs on it.
pct_ok($contract::violations(pct_plan(['conflict' => ['blocked' => true]])) === ['conflict is not a list'],
    'a bucket that is a map is refused as a non-list before the row floor is reached');
pct_ok($contract::violations(null) === ['plan is not a JSON object']
    && $contract::violations([['uuid' => 'a']]) === ['plan is not a JSON object'],
    'a non-object document fails closed before bucket inspection');
pct_refuses(
    static fn() => $contract::requireComplete(['create' => []], 'unit surface'),
    'unit surface: incomplete agent plan envelope (missing adapter_dispositions',
    'requireComplete refuses under the surface name its caller supplied'
);
pct_ok($contract::requireComplete(pct_plan(), 'unit surface') === pct_plan(),
    'requireComplete returns the same plan once it is trustworthy');

// ------------------------------------------- emitter/validator drift (pin)
//
// The required list is not a taste judgement: it is what agent/src/Apply.php
// emits. build_plan()'s initializer plus every whole-bucket assignment or
// append in that file, plus plan()'s own `warnings`, must equal the contract
// exactly — an emitter that grows a bucket without teaching the validator
// about it would silently widen what a promotion receipt is allowed to trust.
$applySource = file_get_contents(dirname(__DIR__, 2) . '/agent/src/Apply.php');
if (!is_string($applySource)) pct_fail('could not read the plan emitter');
// The derivation is bounded to plan() + build_plan(): those two methods are
// the wire emitter. A $plan local elsewhere in the file (e.g. run() holds
// build_plan()'s result and could grow decorations of its own) must NOT
// teach the contract a bucket plan() never emits — a contract widened that
// way would refuse every real envelope at the trust boundaries.
preg_match_all('/^    (?:public|private|protected) (?:static )?function ([A-Za-z_]+)\(/m', $applySource, $methodDecls, PREG_OFFSET_CAPTURE);
$methodNames = array_column($methodDecls[1], 0);
$planIdx = array_search('plan', $methodNames, true);
$buildIdx = array_search('build_plan', $methodNames, true);
if ($planIdx === false || $buildIdx === false || $buildIdx !== $planIdx + 1) {
    pct_fail('plan()/build_plan() moved or separated; the drift pin cannot bound the emitter region');
}
$emitStart = $methodDecls[0][$planIdx][1];
$emitEnd = isset($methodDecls[0][$buildIdx + 1]) ? $methodDecls[0][$buildIdx + 1][1] : strlen($applySource);
$emitterRegion = substr($applySource, $emitStart, $emitEnd - $emitStart);
$initializerAt = strpos($emitterRegion, '$plan = [');
$initializerEnd = $initializerAt === false ? false : strpos($emitterRegion, "\n        ];", $initializerAt);
if ($initializerAt === false || $initializerEnd === false) {
    pct_fail('plan emitter initializer moved; the drift pin cannot derive its bucket list');
}
preg_match_all(
    "/'([a-z_]+)'\s*=>/",
    substr($emitterRegion, $initializerAt, $initializerEnd - $initializerAt),
    $initializerKeys
);
preg_match_all("/\\\$plan\['([a-z_]+)'\](?:\[\])?\s*=(?!=)/", $emitterRegion, $assignedKeys);
// Bucket-growth idioms the assignment regex cannot read must go loud here,
// not silently widen the wire envelope past the contract: whole-array
// merges/unions, and variable-key writes beyond the known retry rewrite of
// an already-literal bucket.
preg_match_all('/\$plan\[\$([A-Za-z_]+)\]\s*=(?!=)/', $emitterRegion, $variableKeyWrites);
$unreadableWrites = array_values(array_diff(array_unique($variableKeyWrites[1]), ['retryKind']));
pct_ok(
    strpos($emitterRegion, 'array_merge($plan') === false
        && !preg_match('/\$plan\s*\+=/', $emitterRegion)
        && $unreadableWrites === [],
    'the emitter only grows buckets through idioms the drift pin can read'
    . ($unreadableWrites === [] ? '' : ' (unreadable variable-key write: $' . implode(', $', $unreadableWrites) . ')')
);
$emitted = array_values(array_unique(array_merge($initializerKeys[1], $assignedKeys[1])));
sort($emitted, SORT_STRING);
pct_ok(count($initializerKeys[1]) > 0 && count($assignedKeys[1]) > 0, 'the drift pin actually read the emitter');
$required = array_merge($contract::requiredBuckets(), $contract::optionalProjections());
sort($required, SORT_STRING);
$drift = array_merge(
    array_map(static fn(string $b): string => "emitted but not required: $b", array_values(array_diff($emitted, $required))),
    array_map(static fn(string $b): string => "required but not emitted: $b", array_values(array_diff($required, $emitted)))
);
$pctLabel = 'the contract requires detailed buckets plus explicitly allowed optional projections emitted by Apply';
pct_ok($emitted === $required, $pctLabel
    . ($drift === [] ? '' : ' (' . implode('; ', $drift) . ')'));

echo "PASS: plan contract trust boundary regression\n";
