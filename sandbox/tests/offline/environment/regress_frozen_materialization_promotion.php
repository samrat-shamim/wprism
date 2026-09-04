<?php
// issue #3324: materialization promotes one frozen artifact/owner/checkpoint.
declare(strict_types=1);

/**
 * Load the public host shell without entering its executable main() block.
 * This keeps the fixture on the same callable contract as env materialize.
 */
$wprismSource = file_get_contents(__DIR__ . '/../../../../cli/wprism');
if (!is_string($wprismSource)) {
    fwrite(STDERR, "FAIL: could not read public wprism shell\n");
    exit(1);
}
$wprismMain = "\ntry {\n    exit(main(\$argv));";
$wprismAt = strpos($wprismSource, $wprismMain);
if ($wprismAt === false) {
    fwrite(STDERR, "FAIL: public wprism shell main guard moved\n");
    exit(1);
}
$wprismPhp = strpos($wprismSource, '<?php');
if ($wprismPhp === false || $wprismPhp > $wprismAt) {
    fwrite(STDERR, "FAIL: public wprism shell PHP prologue moved\n");
    exit(1);
}
$wprismSource = substr($wprismSource, $wprismPhp + 5, $wprismAt - ($wprismPhp + 5)); // strip shebang and `<?php`
$wprismSource = str_replace('__DIR__', var_export(dirname(__DIR__, 4) . '/cli', true), $wprismSource);
eval($wprismSource);

use WPrism\Orchestrator\DriverCapability;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;

function fmp_fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fmp_ok(bool $condition, string $message): void {
    if (!$condition) fmp_fail($message);
    echo "ok: $message\n";
}

/** @param array<int,string> $args */
function fmp_wp_verb(array $args): string {
    $wprismAt = array_search('wprism', $args, true);
    if (is_int($wprismAt)) {
        return (string) ($args[$wprismAt + 1] ?? '');
    }
    return (string) ($args[1] ?? '');
}

/**
 * One complete `wp wprism plan --format=json` envelope, spelled out the way the
 * agent emits it. Reconciliation only trusts a complete envelope (issue #3384),
 * so a fixture that stops at the buckets it cares about would be refused
 * before render() ever sees it — the contract's own suite,
 * regress_plan_contract_trust.php, owns the incomplete cases.
 *
 * @param array<string,list<array<string,mixed>>> $overrides
 * @return array<string,list<mixed>>
 */
function fmp_plan(array $overrides = []): array {
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

final class FrozenPromotionDriver implements EnvironmentDriver {
    /** @var array<string,string> */
    public array $files = [];
    /** @var array<string,bool> */
    public array $symlinks = [];
    /** @var list<array{kind:string,args:mixed}> */
    public array $calls = [];
    public bool $cleanPlan = true;
    public bool $emptyExport = false;
    public string $artifactHash = '';
    /** Non-zero drives the post-checkpoint failure that renders recovery guidance. */
    public int $applyExit = 0;

    public function __construct(private string $repo) {}
    public function name(): string { return 'branch'; }
    public function driverId(): string { return 'fixture-branch'; }
    public function repoPath(): string { return $this->repo; }
    public function describe(): string { return 'fixture'; }

    public function captureRaw(string $script): array {
        $this->calls[] = ['kind' => 'raw', 'args' => $script];
        if (str_starts_with($script, 'mkdir -p ')) return $this->ok();
        if (str_starts_with($script, 'php -r ')) {
            $marker = " -- '";
            $start = strrpos($script, $marker);
            if ($start === false) return $this->fail('malformed PHP hash fixture');
            $path = substr($script, $start + strlen($marker), -1);
            if (str_contains($path, '/.wprism/artifacts/')) {
                return $this->ok(($this->artifactHash !== '' ? $this->artifactHash : hash('sha256', 'frozen-artifact')) . "\n");
            }
            if (!isset($this->files[$path])) return $this->fail('missing file');
            return $this->ok(hash('sha256', $this->files[$path]) . "\n");
        }
        if (str_starts_with($script, 'test -L ')) {
            if (preg_match("/^test -L '([^']+)'$/D", $script, $m) !== 1) {
                return $this->fail('malformed symlink fixture');
            }
            return isset($this->symlinks[$m[1]]) ? $this->ok() : $this->fail('not a symlink');
        }
        if (str_starts_with($script, 'test ')) {
            if (preg_match("/^test (-[efs]) '([^']+)'$/D", $script, $m) !== 1) {
                return $this->fail('malformed test fixture');
            }
            $path = $m[2];
            $present = isset($this->files[$path]);
            $nonEmpty = $present && $this->files[$path] !== '';
            $ok = match ($m[1]) {
                '-e', '-f' => $present,
                '-s' => $nonEmpty,
                default => false,
            };
            return $ok ? $this->ok() : $this->fail('absent');
        }
        if (str_starts_with($script, 'rm -f -- ')) {
            $path = $this->singleQuotedAfter($script, 'rm -f -- ');
            unset($this->files[$path]);
            return $this->ok();
        }
        if (str_starts_with($script, 'mv -f -- ')) {
            if (preg_match("/^mv -f -- '([^']+)' '([^']+)'$/D", $script, $m) !== 1) {
                return $this->fail('malformed move fixture');
            }
            if (!isset($this->files[$m[1]])) return $this->fail('missing move source');
            $this->files[$m[2]] = $this->files[$m[1]];
            unset($this->files[$m[1]]);
            return $this->ok();
        }
        return $this->ok();
    }

    public function captureWp(array $args): array {
        $this->calls[] = ['kind' => 'wp', 'args' => $args];
        $verb = fmp_wp_verb($args);
        if (($args[0] ?? '') === 'db' && ($args[1] ?? '') === 'export') {
            $path = (string) ($args[2] ?? '');
            $this->files[$path] = $this->emptyExport ? '' : "-- frozen checkpoint\n";
            return $this->ok();
        }
        if ($verb === 'plan') {
            return $this->ok(json_encode($this->cleanPlan
                ? fmp_plan()
                : fmp_plan(['drift' => [['path' => 'state/options/core.json']]])) . "\n");
        }
        if ($verb === 'code-preflight') {
            return $this->ok(json_encode([
                'format' => 'wprism-code-runtime/v1',
                'enabled' => true,
                'change_required' => true,
                'compatible' => true,
                'code_revision' => hash('sha256', 'code-release'),
                'target' => [
                    'php' => '8.3.0',
                    'wordpress' => '6.8.2',
                    'source' => 'target-control-plane',
                ],
                'requirements' => [],
                'diagnostics' => [],
            ], JSON_UNESCAPED_SLASHES) . "\n");
        }
        if ($verb === 'lifecycle-status') {
            return $this->ok(json_encode([
                'baseline_state' => 'exact',
                'code_boundary_sha256' => str_repeat('c', 64),
                'code_drift' => [],
                'findings_sha256' => str_repeat('d', 64),
                'format' => 'wprism-lifecycle-status/v2',
                'observation_sha256' => str_repeat('e', 64),
                'reasons' => [],
                'required' => false,
                'warnings' => [],
            ], JSON_UNESCAPED_SLASHES) . "\n");
        }
        if ($verb === 'checkpoint-target') {
            return $this->ok(json_encode([
                'database_target_sha256' => str_repeat('d', 64),
                'format' => 'wprism-database-target/v1',
            ], JSON_UNESCAPED_SLASHES) . "\n");
        }
        if ($verb === 'apply') {
            if ($this->applyExit !== 0) {
                return ['exit' => $this->applyExit, 'stdout' => '', 'stderr' => "apply refused\n"];
            }
            $artifact = '';
            foreach ($args as $arg) if (str_starts_with($arg, '--artifact-hash=')) $artifact = substr($arg, 16);
            $revision = hash('sha256', 'state-release');
            return $this->ok(json_encode([
                'artifact' => ['hash' => $artifact, 'manifests' => hash('sha256', 'manifests'), 'revision' => $revision],
                'applied' => 1,
                'canary' => 'clean',
                'plan' => [],
                'verification' => ['status' => 'clean'],
                'warnings' => [],
            ], JSON_UNESCAPED_SLASHES) . "\n");
        }
        return $this->ok();
    }

    /** @param list<string> $producer @param list<string> $consumer */
    public function captureWpPipeline(array $producer, array $consumer): array {
        $this->calls[] = ['kind' => 'wp', 'args' => $producer];
        $this->calls[] = ['kind' => 'wp', 'args' => $consumer];
        if (array_slice($producer, -3) !== ['db', 'export', '-']
            || count(array_filter(
                $producer,
                static fn(string $arg): bool => str_contains($arg, 'DatabaseTargetIdentity::fromWordPressConfig')
                    && str_contains($arg, 'require_recovery_intent')
            )) !== 1
            || fmp_wp_verb($consumer) !== 'checkpoint-seal') {
            return $this->fail('unexpected pipeline fixture');
        }
        $output = '';
        $databaseTarget = '';
        foreach ($consumer as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $output = substr($arg, strlen('--output='));
            }
            if (str_starts_with($arg, '--database-target-sha256=')) {
                $databaseTarget = substr($arg, strlen('--database-target-sha256='));
            }
        }
        if ($output === '' || $databaseTarget !== str_repeat('d', 64)) {
            return $this->fail('checkpoint seal omitted output');
        }
        $this->files[$output] = $this->emptyExport ? '' : "-- frozen checkpoint\n";

        return $this->ok();
    }

    public function streamWp(array $args): int {
        $this->calls[] = ['kind' => 'stream', 'args' => $args];
        return 0;
    }
    public function wpInstruction(array $args): string { return 'fixture'; }
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

    private function singleQuotedAfter(string $script, string $prefix): string {
        $tail = substr($script, strlen($prefix));
        if (!is_string($tail) || !str_starts_with($tail, "'")) fmp_fail('fixture command path is not quoted');
        $end = strpos($tail, "'", 1);
        if ($end === false) fmp_fail('fixture command path quote is incomplete');
        return substr($tail, 1, $end - 1);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function ok(string $stdout = ''): array { return ['exit' => 0, 'stdout' => $stdout, 'stderr' => '']; }
    /** @return array{exit:int,stdout:string,stderr:string} */
    private function fail(string $stderr): array { return ['exit' => 1, 'stdout' => '', 'stderr' => $stderr]; }
}

$artifactHash = hash('sha256', 'frozen-artifact');
$stateRevision = hash('sha256', 'state-release');
$summary = [
    'artifact_hash' => $artifactHash,
    'code' => ['code_revision' => hash('sha256', 'code-release')],
    'manifest_hash' => hash('sha256', 'manifests'),
    'revision_hash' => $stateRevision,
];
$operation = '20260809-123456-' . str_repeat('a', 24);
$context = [
    'operation_id' => $operation,
    'promotion_owner' => 'wprism-env-promotion-' . $operation,
    'artifact_path' => '/target/repo/.wprism/artifacts/materialize-' . $operation . '.json',
    'checkpoint_path' => '/target/repo/.wprism/checkpoints/materialize-' . $operation . '.sql.enc',
    'compiled_summary' => $summary,
];

// issue #3525 — the materialize branch of print_promotion_recovery().
//
// A frozen materialization's checkpoint is `materialize-<operation_id>.sql.enc`
// (cli/wprism:2632), and `RetainedCheckpoints::ID_PREFIXES` is a CLOSED set of
// `promote-` / `deploy-` (cli/src/Recovery/RetainedCheckpoints.php:88-100)
// that excludes it on purpose, so `wprism recover --restore=<id>` would refuse
// this id. A post-checkpoint failure here must therefore print a NAMED
// REFUSAL identifying the required operator authority — never that verb, and
// never the raw abort/begin/import/abort recipe the product stopped emitting
// (regress_mup_leak_audit.sh part (c) forbids it in every guide; issue #3525
// removed the product's own copy).
//
// The guidance is written with fwrite(STDERR, ...), which no in-process
// buffer intercepts, so the failing promotion runs as a child re-exec of THIS
// file: same source, same fixture driver, same cmd_promote_frozen() entry.
if (($argv[1] ?? '') === '--recovery-view') {
    $failing = new FrozenPromotionDriver('/target/repo');
    $failing->artifactHash = $artifactHash;
    $failing->applyExit = 1;
    cmd_promote_frozen($failing, $context);
    exit(0);
}
$viewFile = tempnam(sys_get_temp_dir(), 'fmp-recovery-view');
if (!is_string($viewFile)) fmp_fail('could not stage the recovery-view capture file');
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --recovery-view'
        . ' >/dev/null 2>' . escapeshellarg($viewFile),
    $fmpIgnored,
    $fmpChildExit
);
$recoveryView = (string) file_get_contents($viewFile);
@unlink($viewFile);
fmp_ok(
    str_contains($recoveryView, 'this checkpoint contains its temporary promotion lease row'),
    'a failed frozen promotion still explains the checkpoint lease row'
);
fmp_ok(
    str_contains(
        $recoveryView,
        'this checkpoint is not a retained release checkpoint, so no wprism verb restores it; '
            . "recovery requires the operator authority that owns this target's database backups."
    ),
    'a materialize checkpoint gets the named operator-authority refusal, not a verb'
);
fmp_ok(
    !str_contains($recoveryView, 'wprism recover '),
    'the refusal names no wprism recover command for a checkpoint that verb cannot list'
);
foreach (['wp wprism promotion-abort', 'wp wprism promotion-begin', 'wp db import', 'rollback-control.php'] as $retired) {
    fmp_ok(
        !str_contains($recoveryView, $retired),
        "the failure view publishes no retired raw-recovery step ($retired)"
    );
}
fmp_ok(
    !str_contains($recoveryView, $artifactHash),
    'the failure view keeps the artifact hash out of the human view (MUP §5.2)'
);

$driver = new FrozenPromotionDriver('/target/repo');
$driver->artifactHash = $artifactHash;
$first = cmd_promote_frozen($driver, $context);
fmp_ok(is_array($first) && ($first['status'] ?? null) === 'completed', 'frozen promotion returns completed structured receipt');
$initialVerbs = array_values(array_filter(array_map(
    static fn(array $call): string => $call['kind'] === 'wp' ? fmp_wp_verb($call['args']) : '',
    $driver->calls
)));
$preflightAt = array_search('code-preflight', $initialVerbs, true);
$beginAt = array_search('promotion-begin', $initialVerbs, true);
fmp_ok(is_int($preflightAt) && is_int($beginAt) && $preflightAt < $beginAt,
    'frozen promotion checks target runtime before acquiring a new promotion lease');
$receiptKeys = array_keys($first);
$expectedReceiptKeys = [
    'artifact_hash', 'checkpoint_identity', 'code_revision', 'format',
    'operation_id', 'owner', 'receipt_sha256', 'state_revision', 'status',
];
sort($receiptKeys, SORT_STRING);
sort($expectedReceiptKeys, SORT_STRING);
fmp_ok($receiptKeys === $expectedReceiptKeys
    && ($first['artifact_hash'] ?? null) === $artifactHash
    && ($first['owner'] ?? null) === $context['promotion_owner']
    && ($first['state_revision'] ?? null) === $stateRevision
    && ($first['code_revision'] ?? null) === $summary['code']['code_revision']
    && preg_match('/^[a-f0-9]{64}$/D', (string) ($first['checkpoint_identity'] ?? '')) === 1
    && preg_match('/^[a-f0-9]{64}$/D', (string) ($first['receipt_sha256'] ?? '')) === 1,
    'receipt binds exact locked artifact, owner, release, checkpoint, and digest');
$callCount = count($driver->calls);
$second = cmd_promote_frozen($driver, $context);
fmp_ok(is_array($second) && $second === $first, 'completed retry verifies the same durable receipt');
fmp_ok(count($driver->calls) > $callCount, 'completed retry performs only read-only reconciliation calls');
$retryTail = array_slice($driver->calls, $callCount);
$retryVerbs = array_values(array_map(static fn(array $call): string => $call['kind'] === 'wp' ? fmp_wp_verb($call['args']) : '', $retryTail));
fmp_ok(in_array('plan', $retryVerbs, true) && !in_array('promotion-begin', $retryVerbs, true) && !in_array('apply', $retryVerbs, true), 'same-owner retry never replays promotion');

$recovery = new FrozenPromotionDriver('/target/repo');
$recovery->artifactHash = $artifactHash;
$lost = cmd_promote_frozen($recovery, $context);
fmp_ok(is_array($lost) && ($lost['status'] ?? null) === 'completed', 'initial apply returns exact receipt before controller journaling');
$beforeRecoveryRetry = count($recovery->calls);
$recovered = cmd_promote_frozen($recovery, $context);
fmp_ok(is_array($recovered) && ($recovered['status'] ?? null) === 'completed' && $recovered === $lost, 'retry reconciles exact clean plan after receipt publication loss');
$tail = array_slice($recovery->calls, $beforeRecoveryRetry);
$verbs = array_values(array_map(static fn(array $call): string => $call['kind'] === 'wp' ? fmp_wp_verb($call['args']) : '', $tail));
fmp_ok(in_array('plan', $verbs, true) && !in_array('promotion-begin', $verbs, true) && !in_array('apply', $verbs, true), 'receipt-loss retry reconciles before any new mutation');

$invalid = new FrozenPromotionDriver('/target/repo');
$invalid->artifactHash = $artifactHash;
$invalid->files[$context['checkpoint_path']] = '';
$invalidResult = cmd_promote_frozen($invalid, $context);
fmp_ok($invalidResult === 1, 'empty checkpoint evidence refuses instead of being overwritten');
$invalidVerbs = array_values(array_map(static fn(array $call): string => $call['kind'] === 'wp' ? fmp_wp_verb($call['args']) : '', $invalid->calls));
fmp_ok(!in_array('promotion-begin', $invalidVerbs, true), 'truncated checkpoint refusal happens before target mutation');

$outside = $context;
$outside['artifact_path'] = '/target/outside/materialize.json';
try {
    cmd_promote_frozen(new FrozenPromotionDriver('/target/repo'), $outside);
    fmp_fail('out-of-scope artifact path was accepted');
} catch (InvalidArgumentException $e) {
    echo "ok: frozen promotion rejects paths outside target /.wprism\n";
}

$nonCanonical = $context;
$nonCanonical['artifact_path'] = '/target/repo/.wprism/artifacts/other.json';
try {
    cmd_promote_frozen(new FrozenPromotionDriver('/target/repo'), $nonCanonical);
    fmp_fail('non-canonical artifact path was accepted');
} catch (InvalidArgumentException $e) {
    echo "ok: frozen promotion requires the operation-canonical artifact path\n";
}

$alias = $context;
$alias['owner'] = $context['promotion_owner'];
try {
    cmd_promote_frozen(new FrozenPromotionDriver('/target/repo'), $alias);
    fmp_fail('frozen context alias key was accepted');
} catch (InvalidArgumentException $e) {
    echo "ok: frozen promotion rejects context alias keys\n";
}

$stateSummary = $summary;
unset($stateSummary['code']);
$stateContext = $context;
$stateContext['compiled_summary'] = $stateSummary;
$stateDriver = new FrozenPromotionDriver('/target/repo');
$stateDriver->artifactHash = $artifactHash;
$stateReceipt = cmd_promote_frozen($stateDriver, $stateContext);
fmp_ok(is_array($stateReceipt) && ($stateReceipt['code_revision'] ?? null) === null, 'state-only promotion returns nullable code revision');
$stateRetry = cmd_promote_frozen($stateDriver, $stateContext);
fmp_ok(is_array($stateRetry) && ($stateRetry['code_revision'] ?? null) === null && $stateRetry === $stateReceipt, 'state-only receipt recovery preserves code_revision=null');

$mismatchedArtifact = new FrozenPromotionDriver('/target/repo');
$mismatchedArtifact->artifactHash = hash('sha256', 'different-artifact');
$mismatchedArtifact->files[$context['checkpoint_path']] = "-- frozen checkpoint\n";
$mismatchResult = cmd_promote_frozen($mismatchedArtifact, $context);
fmp_ok($mismatchResult === 1, 'receipt recovery refuses a target artifact with the wrong content hash');

$symlinked = new FrozenPromotionDriver('/target/repo');
$symlinked->symlinks[$context['artifact_path']] = true;
$symlinkResult = cmd_promote_frozen($symlinked, $context);
fmp_ok($symlinkResult === 1, 'frozen promotion refuses symlinked artifact evidence');

$emptyExport = new FrozenPromotionDriver('/target/repo');
$emptyExport->emptyExport = true;
$emptyResult = cmd_promote_frozen($emptyExport, $context);
fmp_ok($emptyResult === 1, 'frozen promotion refuses an empty newly exported checkpoint');

echo "PASS: frozen materialization promotion regression\n";
