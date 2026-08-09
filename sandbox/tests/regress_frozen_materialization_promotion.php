<?php
// DUO-3324: materialization promotes one frozen artifact/owner/checkpoint.
declare(strict_types=1);

/**
 * Load the public host shell without entering its executable main() block.
 * This keeps the fixture on the same callable contract as env materialize.
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

function fmp_fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fmp_ok(bool $condition, string $message): void {
    if (!$condition) fmp_fail($message);
    echo "ok: $message\n";
}

/**
 * One complete `wp duo plan --format=json` envelope, spelled out the way the
 * agent emits it. Reconciliation only trusts a complete envelope (DUO-3384),
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
        'missing_user' => [], 'provider_problems' => [], 'regen_pending' => [], 'skipped_user_meta' => [],
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
            if (str_contains($path, '/.duo/artifacts/')) {
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
        $verb = (string) ($args[1] ?? '');
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
        if ($verb === 'apply') {
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
    'promotion_owner' => 'duo-env-promotion-' . $operation,
    'artifact_path' => '/target/repo/.duo/artifacts/materialize-' . $operation . '.json',
    'checkpoint_path' => '/target/repo/.duo/checkpoints/materialize-' . $operation . '.sql',
    'compiled_summary' => $summary,
];

$driver = new FrozenPromotionDriver('/target/repo');
$driver->artifactHash = $artifactHash;
$first = cmd_promote_frozen($driver, $context);
fmp_ok(is_array($first) && ($first['status'] ?? null) === 'completed', 'frozen promotion returns completed structured receipt');
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
$retryVerbs = array_values(array_map(static fn(array $call): string => $call['kind'] === 'wp' ? (string) ($call['args'][1] ?? '') : '', $retryTail));
fmp_ok(in_array('plan', $retryVerbs, true) && !in_array('promotion-begin', $retryVerbs, true) && !in_array('apply', $retryVerbs, true), 'same-owner retry never replays promotion');

$recovery = new FrozenPromotionDriver('/target/repo');
$recovery->artifactHash = $artifactHash;
$lost = cmd_promote_frozen($recovery, $context);
fmp_ok(is_array($lost) && ($lost['status'] ?? null) === 'completed', 'initial apply returns exact receipt before controller journaling');
$beforeRecoveryRetry = count($recovery->calls);
$recovered = cmd_promote_frozen($recovery, $context);
fmp_ok(is_array($recovered) && ($recovered['status'] ?? null) === 'completed' && $recovered === $lost, 'retry reconciles exact clean plan after receipt publication loss');
$tail = array_slice($recovery->calls, $beforeRecoveryRetry);
$verbs = array_values(array_map(static fn(array $call): string => $call['kind'] === 'wp' ? (string) ($call['args'][1] ?? '') : '', $tail));
fmp_ok(in_array('plan', $verbs, true) && !in_array('promotion-begin', $verbs, true) && !in_array('apply', $verbs, true), 'receipt-loss retry reconciles before any new mutation');

$invalid = new FrozenPromotionDriver('/target/repo');
$invalid->artifactHash = $artifactHash;
$invalid->files[$context['checkpoint_path']] = '';
$invalidResult = cmd_promote_frozen($invalid, $context);
fmp_ok($invalidResult === 1, 'empty checkpoint evidence refuses instead of being overwritten');
$invalidVerbs = array_values(array_map(static fn(array $call): string => $call['kind'] === 'wp' ? (string) ($call['args'][1] ?? '') : '', $invalid->calls));
fmp_ok(!in_array('promotion-begin', $invalidVerbs, true), 'truncated checkpoint refusal happens before target mutation');

$outside = $context;
$outside['artifact_path'] = '/target/outside/materialize.json';
try {
    cmd_promote_frozen(new FrozenPromotionDriver('/target/repo'), $outside);
    fmp_fail('out-of-scope artifact path was accepted');
} catch (InvalidArgumentException $e) {
    echo "ok: frozen promotion rejects paths outside target /.duo\n";
}

$nonCanonical = $context;
$nonCanonical['artifact_path'] = '/target/repo/.duo/artifacts/other.json';
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
