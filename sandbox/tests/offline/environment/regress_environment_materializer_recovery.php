<?php
// DUO-3324: offline, adversarial phase-exact materialization/reap recovery.
//
// This deliberately drives the public EnvironmentMaterializer/provider/journal
// contract. The provider persists a successful mutation before simulating a
// lost response, so retry evidence must prove operation-id/input reuse rather
// than merely retrying an in-memory fake that never changed the host.
declare(strict_types=1);

namespace Duo\Orchestrator {
    /** Minimal semantic materializer seam; EnvironmentMaterializer owns all host sequencing. */
    final class Refresh {
        public static function rebase(EnvironmentDriver $driver, string $production, string $branch, array $resolution = []): array {
            $root = trim((string) shell_exec('git rev-parse --show-toplevel'));
            $head = trim((string) shell_exec('git rev-parse HEAD'));
            if ($root === '' || $head === '') {
                throw new \RuntimeException('recovery fixture could not resolve its clean repository');
            }
            exec(
                'git -C ' . escapeshellarg($root) . ' update-ref '
                . escapeshellarg('refs/heads/' . $branch) . ' ' . escapeshellarg($head),
                $discard,
                $exit
            );
            if ($exit !== 0) {
                throw new \RuntimeException('recovery fixture could not publish candidate ref');
            }
            $path = $root . '/.git/recovery-refresh-' . hash('sha256', $branch) . '.json';
            file_put_contents($path, json_encode([
                'context' => ['production_snapshot_hash' => hash('sha256', 'semantic-production-truth')],
                'format' => 'duo-refresh-plan/v1',
                'plan_hash' => hash('sha256', 'semantic-branch-delta'),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return ['head' => $head, 'new_branch' => $branch, 'plan_path' => $path, 'run_id' => 'recovery-fixture'];
        }
    }

    /** The fixture freezes a deterministic compiled release without testing compilation itself. */
    final class CodeDeploy {
        public static function compile(EnvironmentDriver $driver, string $repo, string $artifact): array {
            $summary = [
                'artifact_hash' => hash('sha256', 'outer-artifact:' . $driver->name()),
                'revision_hash' => hash('sha256', 'state-release:' . $driver->name()),
            ];
            if (!($driver instanceof \RecoveryMaterializationDriver) || !$driver->stateOnly) {
                $summary['code'] = ['code_revision' => hash('sha256', 'code-release:' . $driver->name())];
            }
            return ['exit' => 0, 'stderr' => '', 'stdout' => '', 'summary' => $summary];
        }
    }

    /**
     * Convergence semantics, not the renderer, are what this fixture needs:
     * a plan with no drift or conflict is clean. PlanContract stays real —
     * the complete-envelope refusal at that boundary is product behavior
     * (DUO-3384), and rr_plan() below emits the envelope the agent emits.
     */
    final class PlanSummary {
        public static function render(array $plan): array {
            return ['lines' => [], 'ok' => ($plan['drift'] ?? []) === [] && ($plan['conflict'] ?? []) === []];
        }
    }
}

namespace {
    $rrRoot = dirname(__DIR__, 4);
    require_once $rrRoot . '/cli/src/Transport/EnvironmentDriver.php';
    require_once $rrRoot . '/cli/src/Plan/PlanContract.php';
    // A protocol-lane path is only a local validation aid while this test is
    // developed independently. The checked-in default is always the product
    // file, so CI exercises the same public contract after integration.
    $rrLifecycle = getenv('DUO_ENVIRONMENT_LIFECYCLE_PATH');
    if (!is_string($rrLifecycle) || $rrLifecycle === '') {
        $rrLifecycle = $rrRoot . '/cli/src/Environment/EnvironmentLifecycle.php';
    }
    if (!is_file($rrLifecycle)) {
        fwrite(STDERR, "FAIL: environment lifecycle source is unavailable: $rrLifecycle\n");
        exit(1);
    }
    require_once $rrLifecycle;

    use Duo\Orchestrator\CommandEnvironmentProvider;
    use Duo\Orchestrator\DriverCapability;
    use Duo\Orchestrator\DriverCapabilityReport;
    use Duo\Orchestrator\EnvironmentDriver;
    use Duo\Orchestrator\EnvironmentLifecycleCanon;
    use Duo\Orchestrator\EnvironmentLifecycleJournal;
    use Duo\Orchestrator\EnvironmentMaterializer;
    use Duo\Orchestrator\EnvironmentProviderCapabilityReport;
    use Duo\Orchestrator\EnvironmentProviderClient;

    function rr_fail(string $message): never {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }

    /**
     * One complete, clean `wp duo plan --format=json` envelope, spelled out
     * the way agent/src/Apply/Apply.php emits it. Branch convergence refuses
     * anything less (DUO-3384).
     */
    function rr_plan(): string {
        return (string) json_encode([
            'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
            'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
            'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
            'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
            'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
            'skipped_user_meta' => [],
            'unchanged' => [], 'update' => [], 'uploads_inventory' => [], 'warnings' => [],
        ], JSON_UNESCAPED_SLASHES);
    }

    function rr_ok(bool $condition, string $message): void {
        if (!$condition) rr_fail($message);
        echo "ok: $message\n";
    }

    function rr_throws(callable $call, string $needle, string $message): void {
        try {
            $call();
        } catch (Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), strtolower($needle))) {
                rr_fail($message . ' (unexpected diagnostic: ' . $e->getMessage() . ')');
            }
            rr_ok(true, $message . ' (diagnostic)');
            return;
        }
        rr_fail($message . ' (no refusal)');
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    function rr_run(array $command, ?string $cwd = null): array {
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) rr_fail('could not start recovery fixture command');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    function rr_must_run(array $command, ?string $cwd = null): string {
        $result = rr_run($command, $cwd);
        if ($result['exit'] !== 0) {
            rr_fail('fixture command failed: ' . implode(' ', $command) . "\n" . $result['stderr']);
        }
        return trim($result['stdout']);
    }

    function rr_remove(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') rr_remove($path . '/' . $name);
        }
        @rmdir($path);
    }

    /** @return list<array{action:string,environment:string,input:array<string,mixed>,operation_id:string,role:string}> */
    function rr_calls(string $path): array {
        if (!is_file($path)) return [];
        $calls = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !is_array($row['request'] ?? null)) rr_fail('provider call log is malformed');
            $request = $row['request'];
            $calls[] = [
                'action' => (string) ($request['action'] ?? ''),
                'environment' => (string) ($request['environment'] ?? ''),
                'input' => is_array($request['input'] ?? null) ? $request['input'] : [],
                'operation_id' => (string) ($request['operation_id'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
            ];
        }
        return $calls;
    }

    /** @param list<array{action:string,environment:string,input:array<string,mixed>,operation_id:string,role:string}> $calls @return list<array{action:string,environment:string,input:array<string,mixed>,operation_id:string,role:string}> */
    function rr_action_calls(array $calls, string $action): array {
        return array_values(array_filter($calls, static fn(array $call): bool => $call['action'] === $action));
    }

    /** @param list<array{action:string,environment:string,input:array<string,mixed>,operation_id:string,role:string}> $calls */
    function rr_assert_exact_duplicate(array $calls, string $action, string $message): void {
        $matches = rr_action_calls($calls, $action);
        rr_ok(count($matches) === 2
            && $matches[0]['operation_id'] === $matches[1]['operation_id']
            && EnvironmentLifecycleCanon::encode($matches[0]['input']) === EnvironmentLifecycleCanon::encode($matches[1]['input']),
            $message
        );
    }

    /** @param list<array{action:string,environment:string,input:array<string,mixed>,operation_id:string,role:string}> $calls */
    function rr_assert_no_completed_replay(array $calls, string $faultRole, string $faultAction, string $message): void {
        $counts = [];
        foreach ($calls as $call) {
            $key = $call['role'] . ':' . $call['action'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        foreach ($counts as $key => $count) {
            [$role, $action] = explode(':', $key, 2);
            // Capabilities and mutation-read are deliberately renewed live;
            // neither is a completed journaled provider phase. Every other
            // phase below has durable completion evidence and must not replay.
            if (!in_array($action, ['capabilities', 'mutation-read'], true)
                && !($role === $faultRole && $action === $faultAction) && $count > 1) {
                rr_fail($message . ": completed '$key' was replayed $count times");
            }
        }
        echo "ok: $message\n";
    }

    /** @param list<array<string,mixed>> $events */
    function rr_event_data(array $events, string $name): ?array {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (($events[$i]['event'] ?? null) === $name && is_array($events[$i]['data'] ?? null)) {
                return $events[$i]['data'];
            }
        }
        return null;
    }

    final class RecoveryMaterializationDriver implements EnvironmentDriver {
        /** @var list<array{kind:string,args:mixed}> */
        public array $calls = [];
        public function __construct(
            private string $name,
            private string $repo,
            private string $productionCommit = '',
            public bool $stateOnly = false
        ) {}
        public function name(): string { return $this->name; }
        public function driverId(): string { return 'recovery-fixture-' . $this->name; }
        public function repoPath(): string { return $this->repo; }
        public function describe(): string { return 'phase-exact recovery fixture'; }
        public function captureRaw(string $script): array {
            $this->calls[] = ['kind' => 'raw', 'args' => $script];
            if (str_contains($script, 'rev-parse --verify HEAD^{commit}')) {
                return ['exit' => 0, 'stdout' => $this->productionCommit . "\n", 'stderr' => ''];
            }
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        public function captureWp(array $args): array {
            $this->calls[] = ['kind' => 'wp', 'args' => $args];
            if (($args[0] ?? null) === 'duo' && ($args[1] ?? null) === 'plan') {
                return ['exit' => 0, 'stdout' => rr_plan() . "\n", 'stderr' => ''];
            }
            // The materializer reads the source URL binding (home + uploads)
            // to rebind a rehearsal target off its restored snapshot.
            if (($args[0] ?? null) === 'eval' && str_contains((string) ($args[1] ?? ''), 'get_option')) {
                return ['exit' => 0, 'stdout' => "http://source.example:9600\nhttp://source.example:9600/wp-content/uploads\n", 'stderr' => ''];
            }
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        public function streamWp(array $args): int {
            $this->calls[] = ['kind' => 'stream', 'args' => $args];
            return 0;
        }
        public function wpInstruction(array $args): string { return 'recovery fixture'; }
        public function capabilityReport(string $operation): DriverCapabilityReport {
            return DriverCapabilityReport::forDriver($this->name, $this->driverId(), $operation, [
                DriverCapability::ATTACH => true,
                DriverCapability::CODE_MATERIALIZE => true,
                DriverCapability::DB_SNAPSHOT_CREATE => true,
                DriverCapability::DB_SNAPSHOT_RESTORE => true,
                DriverCapability::RAW_CONTROL => true,
                DriverCapability::WP_CONTROL => true,
            ]);
        }
    }

    /** Fast in-process refusal lane for the >128 retry bound regression. */
    final class BoundedReapRefusalProvider implements EnvironmentProviderClient {
        /** @var list<array{input:array<string,mixed>,operation_id:string}> */
        public array $terminalCalls = [];

        /** @param array<string,mixed> $ttl @param array<string,mixed> $released */
        public function __construct(
            private string $environment,
            private array $ttl,
            private array $released
        ) {}

        public function environment(): string { return $this->environment; }

        public function capabilities(string $operationId): EnvironmentProviderCapabilityReport {
            return new EnvironmentProviderCapabilityReport(
                $this->environment,
                'recovery-provider-target',
                1,
                [
                    'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach',
                    'environment.inspect', 'environment.mutation.acquire', 'environment.mutation.read',
                    'environment.mutation.release', 'environment.ttl', 'environment.ttl.read',
                    'environment.url.discover', 'environment.url.set', 'operation.receipts',
                    'repository.materialize', 'snapshot.set.abort', 'snapshot.set.create',
                    'snapshot.set.prepare', 'snapshot.set.read', 'snapshot.set.restore',
                ]
            );
        }

        public function perform(string $action, string $operationId, array $input): array {
            if ($action === 'destroy') {
                $this->terminalCalls[] = ['input' => $input, 'operation_id' => $operationId];
                throw new RuntimeException('environment provider failed; provider output is redacted');
            }
            if ($action === 'ttl-read') return $this->ttl;
            if ($action === 'mutation-read') return $this->released;
            if ($action === 'mutation-acquire') {
                throw new RuntimeException('environment provider failed; provider output is redacted');
            }
            throw new RuntimeException("unexpected bounded-reap provider action '$action'");
        }
    }

    /** @return array{callback:callable,calls:list<array<string,mixed>>,mutations:int,receipt:?array<string,mixed>} */
    function rr_promoter(bool $loseReceipt = false): array {
        $state = ['calls' => [], 'lose' => $loseReceipt, 'mutations' => 0, 'receipt' => null];
        $callback = static function (EnvironmentDriver $driver, array $context) use (&$state): array {
            $state['calls'][] = $context;
            if ($state['receipt'] !== null) {
                if (EnvironmentLifecycleCanon::encode($state['calls'][0]) !== EnvironmentLifecycleCanon::encode($context)) {
                    throw new RuntimeException('promotion recovery changed the frozen owner/context');
                }
                return $state['receipt'];
            }
            $summary = $context['compiled_summary'] ?? null;
            if (!is_array($summary)) throw new RuntimeException('fixture promotion has no compiled summary');
            $state['mutations']++;
            $body = [
                'artifact_hash' => (string) $summary['artifact_hash'],
                'checkpoint_identity' => hash('sha256', 'checkpoint:' . (string) $context['operation_id']),
                'code_revision' => isset($summary['code']) && is_array($summary['code']) ? ($summary['code']['code_revision'] ?? null) : null,
                'format' => 'duo-branch-environment-promotion-receipt/v1',
                'operation_id' => (string) $context['operation_id'],
                'owner' => (string) $context['promotion_owner'],
                'state_revision' => (string) $summary['revision_hash'],
                'status' => 'completed',
            ];
            $body['receipt_sha256'] = hash('sha256', EnvironmentLifecycleCanon::encode($body));
            $state['receipt'] = $body;
            if ($state['lose']) {
                $state['lose'] = false;
                throw new RuntimeException('promotion completed but controller response was lost');
            }
            return $body;
        };
        // Return state by reference through a small object so closures and
        // assertions see one durable promotion receipt.
        return ['callback' => $callback, 'calls' => &$state['calls'], 'mutations' => &$state['mutations'], 'receipt' => &$state['receipt']];
    }

    /**
     * A read-only materialization observation with process-restart semantics.
     * Fresh execution derives one canonical object from the exact driver;
     * replay only accepts and returns the journaled object without target I/O.
     *
     * @return array{callback:callable,fresh:int,replays:int,evidence:?array<string,mixed>,replayed:?array<string,mixed>,driver_ids:list<int>}
     */
    function rr_observer(): array {
        $state = [
            'driver_ids' => [],
            'evidence' => null,
            'fresh' => 0,
            'replayed' => null,
            'replays' => 0,
        ];
        $callback = static function (EnvironmentDriver $driver, ?array $replayed) use (&$state): array {
            $state['driver_ids'][] = spl_object_id($driver);
            if ($replayed !== null) {
                $state['replays']++;
                $state['replayed'] = $replayed;
                return $replayed;
            }
            $state['fresh']++;
            $state['evidence'] = [
                'format' => 'duo-recovery-observation/v1',
                'target' => $driver->name(),
                'witness_sha256' => hash('sha256', 'observed:' . $driver->name()),
            ];
            return $state['evidence'];
        };
        return [
            'callback' => $callback,
            'driver_ids' => &$state['driver_ids'],
            'evidence' => &$state['evidence'],
            'fresh' => &$state['fresh'],
            'replayed' => &$state['replayed'],
            'replays' => &$state['replays'],
        ];
    }

    /** @return array<string,mixed> */
    function rr_fixture(string $root, string $name, string $fault = '', string $behavior = 'normal', bool $stateOnly = false, bool $create = false): array {
        $dir = $root . '/' . $name;
        $repo = $dir . '/repo';
        if (!mkdir($repo, 0700, true) && !is_dir($repo)) rr_fail('could not create fixture repository');
        rr_must_run(['git', 'init', '-b', 'feature'], $repo);
        rr_must_run(['git', 'config', 'user.email', 'recovery@example.invalid'], $repo);
        rr_must_run(['git', 'config', 'user.name', 'Recovery Fixture'], $repo);
        file_put_contents($repo . '/tracked.txt', "feature\n");
        rr_must_run(['git', 'add', 'tracked.txt'], $repo);
        rr_must_run(['git', 'commit', '-m', 'fixture'], $repo);
        $commit = rr_must_run(['git', 'rev-parse', 'HEAD'], $repo);
        $provider = $root . '/provider.php';
        $state = $dir . '/provider-state.json';
        $sourceLog = $dir . '/source.log';
        $targetLog = $dir . '/target.log';
        $cfg = static function (string $role, string $log, ?string $providerFault = null, ?string $providerBehavior = null) use ($provider, $state, $fault, $behavior): array {
            $effectiveFault = $providerFault ?? $fault;
            if ($effectiveFault === '') $effectiveFault = 'none';
            $effectiveBehavior = $providerBehavior ?? $behavior;
            return [
                '_machine_local' => true,
                'environment_provider' => [
                    'command' => [PHP_BINARY, $provider, $role, $state, $log, $effectiveFault, $effectiveBehavior],
                    'timeout_seconds' => 5,
                ],
            ];
        };
        $source = new RecoveryMaterializationDriver('production-' . $name, '/production/' . $name, $commit);
        $target = new RecoveryMaterializationDriver('branch-' . $name, '/branch/' . $name, '', $stateOnly);
        $journal = new EnvironmentLifecycleJournal($repo . '/.git/duo-environments');
        return [
            'cfg' => $cfg,
            'commit' => $commit,
            'create' => $create,
            'dir' => $dir,
            'journal' => $journal,
            'repo' => $repo,
            'source' => $source,
            'source_log' => $sourceLog,
            'source_provider' => CommandEnvironmentProvider::fromEnvironment($source->name(), $cfg('source', $sourceLog)),
            'state' => $state,
            'target' => $target,
            'target_log' => $targetLog,
            'target_provider' => CommandEnvironmentProvider::fromEnvironment($target->name(), $cfg('target', $targetLog)),
        ];
    }

    /** @param array<string,mixed> $fixture @return array{branch:string,create:bool,ttl_seconds:int} */
    function rr_options(array $fixture, int $ttl = 60): array {
        return ['branch' => 'feature', 'create' => (bool) $fixture['create'], 'ttl_seconds' => $ttl];
    }

    /** @param array<string,mixed> $fixture */
    function rr_materialize(
        array $fixture,
        callable $promote,
        int $ttl = 60,
        ?callable $observeBeforeRelease = null
    ): array {
        $old = getcwd();
        chdir((string) $fixture['repo']);
        try {
            return EnvironmentMaterializer::materialize(
                $fixture['source'], $fixture['target'], $fixture['source_provider'], $fixture['target_provider'],
                $fixture['journal'], rr_options($fixture, $ttl), $promote, $observeBeforeRelease
            );
        } finally {
            if ($old !== false) chdir($old);
        }
    }

    /** @param array<string,mixed> $fixture */
    function rr_reap(array $fixture, EnvironmentProviderClient $targetProvider): array {
        $old = getcwd();
        chdir((string) $fixture['repo']);
        try {
            return EnvironmentMaterializer::reap(
                $fixture['target'], $targetProvider, $fixture['journal'], $fixture['source_provider']
            );
        } finally {
            if ($old !== false) chdir($old);
        }
    }

    $tmp = sys_get_temp_dir() . '/duo-materializer-recovery-' . bin2hex(random_bytes(8));
    if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) rr_fail('could not create recovery fixture root');
    try {
        $providerScript = $tmp . '/provider.php';
        file_put_contents($providerScript, <<<'PHP'
<?php
declare(strict_types=1);
$role = (string) ($argv[1] ?? '');
$statePath = (string) ($argv[2] ?? '');
$log = (string) ($argv[3] ?? '');
$fault = (string) ($argv[4] ?? '');
$behavior = (string) ($argv[5] ?? 'normal');
$raw = (string) stream_get_contents(STDIN);
$request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
function c(mixed $value): string {
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = json_decode(c($child), true, 512, JSON_THROW_ON_ERROR);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function h(string $value): string { return hash('sha256', $value); }
function save(string $path, array $state): void { file_put_contents($path, c($state), LOCK_EX); }
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : [];
$state += ['cleanup_mutations' => 0, 'faulted' => [], 'fences' => [], 'records' => [], 'target_present' => true];
file_put_contents($log, c(['request' => $request, 'role' => $role]) . "\n", FILE_APPEND | LOCK_EX);
$action = (string) ($request['action'] ?? '');
$operation = (string) ($request['operation_id'] ?? '');
$input = is_array($request['input'] ?? null) ? $request['input'] : [];
$key = $role . '|' . $action . '|' . $operation;
$inputBytes = c($input);
$targetIdentity = [
    'environment_identity' => 'target-environment-0001', 'lease_generation' => 3,
    'lease_id' => 'target-lease-0001', 'ownership_receipt_sha256' => h('target-owner'),
    'resource_id' => 'target-resource-0001', 'url' => 'https://branch.example.test',
];
$sourceResourceIdentity = [
    'environment_identity' => 'source-environment-0001', 'lease_generation' => 3,
    'lease_id' => 'source-resource-lease-0001', 'ownership_receipt_sha256' => h('source-owner'),
    'resource_id' => 'source-resource-0001', 'url' => 'https://production.example.test',
];
$identity = $role === 'source' ? $sourceResourceIdentity : $targetIdentity;
$sourceIdentity = 'source-environment-0001';
$sourceLease = ['lease_generation' => 1, 'lease_id' => 'source-lease-0001', 'lease_receipt_sha256' => h('source-lease')];
$receiptBearing = $action === 'inspect' || in_array($action, ['snapshot-prepare','snapshot-create','snapshot-abort','attach','create','mutation-acquire','mutation-release','snapshot-restore','repository-materialize','url-set','ttl-set','destroy','detach'], true);
if (isset($state['records'][$key])) {
    if (($state['records'][$key]['input'] ?? null) !== $inputBytes) { fwrite(STDERR, "idempotency input changed\n"); exit(88); }
    $result = $state['records'][$key]['result'];
} else {
    if ($role === 'target' && $action === 'mutation-acquire' && !$state['target_present']) {
        fwrite(STDERR, "cannot acquire a mutation fence for an absent generation\n");
        exit(76);
    }
    if ($role === 'target' && $action === 'mutation-acquire'
        && $behavior === 'reap-acquire-refusal'
        && str_starts_with((string) ($input['mutation_owner'] ?? ''), 'duo-env-reap-')) {
        fwrite(STDERR, "reap mutation acquire refused before provider state change\n");
        exit(76);
    }
    if ($role === 'target' && in_array($action, ['destroy', 'detach'], true) && $state['target_present']) {
        $authorized = false;
        foreach ($state['fences'] as $fence) {
            if (($fence['state'] ?? null) === 'held'
                && ($fence['mutation_generation'] ?? null) === ($input['expected_mutation_generation'] ?? null)
                && ($fence['mutation_id'] ?? null) === ($input['expected_mutation_id'] ?? null)
                && ($fence['mutation_owner'] ?? null) === ($input['expected_mutation_owner'] ?? null)
                && ($fence['mutation_receipt_sha256'] ?? null) === ($input['expected_mutation_receipt_sha256'] ?? null)) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            fwrite(STDERR, "compare-and-reap has no exact held mutation fence\n");
            exit(76);
        }
    }
    $result = match ($action) {
        'capabilities' => ['capabilities' => [
            'environment.attach','environment.create','environment.destroy','environment.detach','environment.inspect',
            'environment.mutation.acquire','environment.mutation.read','environment.mutation.release','environment.ttl','environment.ttl.read',
            'environment.url.discover','environment.url.set','operation.receipts','repository.materialize',
            'snapshot.set.abort','snapshot.set.create','snapshot.set.prepare','snapshot.set.read','snapshot.set.restore',
        ]],
        'inspect' => $identity + ['presence' => $role === 'target' && !$state['target_present'] ? 'absent' : 'present'],
        'snapshot-prepare' => $sourceLease + [
            'snapshot_session_id' => (string) ($input['snapshot_session_id'] ?? ''), 'source_identity' => $sourceIdentity,
        ],
        'snapshot-create' => [
            'database_sha256' => h('snapshot-db'),
            'lease_generation' => $behavior === 'source-mutates' ? 2 : (int) ($input['expected_source_lease_generation'] ?? 1),
            'lease_id' => (string) ($input['expected_source_lease_id'] ?? $sourceLease['lease_id']),
            'lease_receipt_sha256' => (string) ($input['expected_source_lease_receipt_sha256'] ?? $sourceLease['lease_receipt_sha256']),
            'media_sha256' => h('snapshot-media'), 'retention_receipt_sha256' => h('snapshot-retention'),
            'semantic_snapshot_sha256' => (string) ($input['expected_semantic_snapshot_sha256'] ?? ''),
            'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? ''),
            'snapshot_set_id' => 'snapshot-set-0001', 'snapshot_set_receipt_sha256' => h('snapshot-receipt'),
            'source_identity' => (string) ($input['expected_source_identity'] ?? $sourceIdentity),
        ],
        'snapshot-read' => [
            'database_sha256' => h('snapshot-db'), 'immutable' => true,
            'lease_generation' => (int) ($input['expected_source_lease_generation'] ?? 1),
            'lease_id' => (string) ($input['expected_source_lease_id'] ?? $sourceLease['lease_id']),
            'lease_receipt_sha256' => (string) ($input['expected_source_lease_receipt_sha256'] ?? $sourceLease['lease_receipt_sha256']),
            'media_sha256' => h('snapshot-media'), 'retention_receipt_sha256' => h('snapshot-retention'),
            'semantic_snapshot_sha256' => h('semantic-production-truth'),
            'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? ''),
            'snapshot_set_id' => (string) ($input['expected_snapshot_set_id'] ?? 'snapshot-set-0001'),
            'snapshot_set_receipt_sha256' => (string) ($input['expected_snapshot_set_receipt_sha256'] ?? h('snapshot-receipt')),
            'source_identity' => (string) ($input['expected_source_identity'] ?? $sourceIdentity),
        ],
        'snapshot-abort' => [
            'disposition' => 'aborted',
            'lease_generation' => (int) ($input['expected_source_lease_generation'] ?? 1),
            'lease_id' => (string) ($input['expected_source_lease_id'] ?? $sourceLease['lease_id']),
            'lease_receipt_sha256' => (string) ($input['expected_source_lease_receipt_sha256'] ?? $sourceLease['lease_receipt_sha256']),
            'snapshot_session_id' => (string) ($input['expected_snapshot_session_id'] ?? ''),
            'source_identity' => (string) ($input['expected_source_identity'] ?? $sourceIdentity),
        ],
        'attach', 'create' => $identity + ['presence' => 'present'],
        'mutation-acquire' => $identity + [
            'mutation_generation' => 1, 'mutation_id' => 'mutation-' . $operation,
            'mutation_owner' => (string) ($input['mutation_owner'] ?? ''),
            'mutation_receipt_sha256' => h('held:' . $operation), 'state' => 'held',
        ],
        'mutation-read' => (function () use (&$state, $input, $identity, $behavior): array {
            $id = (string) ($input['expected_mutation_id'] ?? '');
            foreach ($state['fences'] as $fence) {
                if (($fence['mutation_id'] ?? null) === $id) {
                    $read = $fence;
                    if (($read['state'] ?? null) === 'held' && $behavior === 'held-receipt-drift') {
                        $read['mutation_receipt_sha256'] = h('unexpected-held-receipt');
                    }
                    if (($read['state'] ?? null) === 'released' && $behavior === 'released-receipt-drift') {
                        $read['mutation_receipt_sha256'] = h('unexpected-released-receipt');
                    }
                    return $identity + $read;
                }
            }
            return $identity + ['mutation_generation' => 1, 'mutation_id' => $id, 'mutation_owner' => (string) ($input['expected_mutation_owner'] ?? ''), 'mutation_receipt_sha256' => (string) ($input['expected_mutation_receipt_sha256'] ?? h('unknown')), 'state' => 'held'];
        })(),
        'mutation-release' => (function () use (&$state, $input, $identity): array {
            $id = (string) ($input['expected_mutation_id'] ?? '');
            foreach ($state['fences'] as $index => $fence) {
                if (($fence['mutation_id'] ?? null) === $id) {
                    $fence['state'] = 'released';
                    $fence['mutation_receipt_sha256'] = h('released:' . $id);
                    $state['fences'][$index] = $fence;
                    return $identity + $fence;
                }
            }
            return $identity + ['mutation_generation' => 1, 'mutation_id' => $id, 'mutation_owner' => (string) ($input['expected_mutation_owner'] ?? ''), 'mutation_receipt_sha256' => h('released:' . $id), 'state' => 'released'];
        })(),
        'snapshot-restore' => $identity + ['snapshot_set_id' => (string) ($input['snapshot_set_id'] ?? '')],
        'repository-materialize' => $identity + ['branch_commit' => (string) ($input['branch_commit'] ?? ''), 'repository_receipt_sha256' => h('repository')],
        'url-set' => $identity,
        'ttl-set', 'ttl-read' => $identity + [
            'expires_at' => '2030-01-02T03:04:05Z', 'ttl_generation' => 1,
            'ttl_lease_id' => 'ttl-lease-0001', 'ttl_receipt_sha256' => h('ttl'), 'ttl_state' => 'active',
        ],
        'destroy', 'detach' => [
            'absence_proof_sha256' => h('absence:' . $operation), 'disposition' => $action === 'destroy' ? 'destroyed' : 'detached',
            'environment_identity' => $identity['environment_identity'], 'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'], 'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'resource_id' => $identity['resource_id'],
        ],
        default => [],
    };
    if (in_array($action, ['attach','create'], true)) $state['target_present'] = true;
    if ($action === 'mutation-acquire') $state['fences'][] = array_diff_key($result, array_flip(array_keys($identity)));
    if (in_array($action, ['destroy','detach'], true)) {
        if ($state['target_present']) $state['cleanup_mutations']++;
        $state['target_present'] = false;
    }
    if ($receiptBearing) $state['records'][$key] = ['input' => $inputBytes, 'result' => $result];
}
save($statePath, $state);
$faultKey = $role . ':' . $action;
if ($receiptBearing && $fault === $faultKey && !($state['faulted'][$faultKey] ?? false)) {
    $state['faulted'][$faultKey] = true;
    save($statePath, $state);
    fwrite(STDERR, "response lost after durable $faultKey\n");
    exit(75);
}
$response = [
    'action' => $action, 'environment' => (string) ($request['environment'] ?? ''),
    'format' => 'duo-branch-environment-provider-response/v1', 'operation_id' => $operation,
    'provider' => ['id' => 'recovery-provider-' . $role, 'protocol' => 1], 'result' => $result, 'status' => 'ok',
];
echo c($response) . "\n";
PHP);

        $materializeMethod = new ReflectionMethod(EnvironmentMaterializer::class, 'materialize');
        if ($materializeMethod->getNumberOfParameters() !== 8
            || $materializeMethod->getNumberOfRequiredParameters() !== 7) {
            rr_fail('phase-exact recovery fixture requires the optional replayable-observation materialize callback API');
        }

        // The callback receives the exact driver that converged under the held
        // provider fence. Its canonical result is journaled before release;
        // neither the public materialization receipt nor recovery has to infer
        // that a read happened merely because later mutation phases completed.
        $observed = rr_fixture($tmp, 'observed-materialization');
        $promotion = rr_promoter();
        $observer = rr_observer();
        $observedReceipt = rr_materialize($observed, $promotion['callback'], 60, $observer['callback']);
        $observedLatest = $observed['journal']->latestForTarget($observed['target']->name());
        $observedEvents = is_array($observedLatest) ? $observedLatest['events'] : [];
        $observedEvidence = rr_event_data($observedEvents, 'target-observed');
        $eventNames = array_column($observedEvents, 'event');
        $observationIndex = array_search('target-observed', $eventNames, true);
        $releaseIntentIndex = array_search('target-fence-release-intent', $eventNames, true);
        rr_ok(
            $observer['fresh'] === 1 && $observer['replays'] === 0
                && ($observer['driver_ids'][0] ?? null) === spl_object_id($observed['target']),
            'fresh observation runs once on the exact materialized driver instance'
        );
        rr_ok(
            is_array($observer['evidence']) && is_array($observedEvidence)
                && EnvironmentLifecycleCanon::encode($observer['evidence'])
                    === EnvironmentLifecycleCanon::encode($observedEvidence)
                && is_int($observationIndex) && is_int($releaseIntentIndex)
                && $observationIndex < $releaseIntentIndex,
            'canonical observation evidence is durable before mutation-fence release intent'
        );
        rr_ok(
            !array_key_exists('observation', $observedReceipt)
                && !array_key_exists('target_observation', $observedReceipt),
            'the ordinary public materialization receipt does not absorb private observation output'
        );

        // A complete-run retry has no held fence and must not contact the
        // target. It delivers the journaled object to the callback, whose
        // byte-identical return proves the caller captured exactly that result.
        $providerCallsBeforeCompleteReplay = count(rr_calls($observed['source_log']))
            + count(rr_calls($observed['target_log']));
        $completedReplay = rr_materialize($observed, $promotion['callback'], 60, $observer['callback']);
        $providerCallsAfterCompleteReplay = count(rr_calls($observed['source_log']))
            + count(rr_calls($observed['target_log']));
        rr_ok(
            ($completedReplay['resumed'] ?? false) === true
                && $observer['fresh'] === 1 && $observer['replays'] === 1
                && is_array($observer['evidence']) && is_array($observer['replayed'])
                && EnvironmentLifecycleCanon::encode($observer['evidence'])
                    === EnvironmentLifecycleCanon::encode($observer['replayed']),
            'complete-run resume replays the journaled canonical observation without re-executing it'
        );
        rr_ok(
            $providerCallsAfterCompleteReplay === $providerCallsBeforeCompleteReplay,
            'complete-run observation replay performs no provider action after fence release'
        );

        // The provider durably releases its fence and loses the response. The
        // exact release retry may run, but the target observation itself is
        // already durable and is replayed to the caller rather than re-run.
        $releaseLossObserved = rr_fixture(
            $tmp,
            'observed-mutation-release-loss',
            'target:mutation-release'
        );
        $promotion = rr_promoter();
        $observer = rr_observer();
        rr_throws(
            static fn() => rr_materialize(
                $releaseLossObserved,
                $promotion['callback'],
                60,
                $observer['callback']
            ),
            'provider failed',
            'lost mutation-release response stops after durable target observation'
        );
        $lossLatest = $releaseLossObserved['journal']->latestForTarget($releaseLossObserved['target']->name());
        $lossEvidence = is_array($lossLatest)
            ? rr_event_data($lossLatest['events'], 'target-observed')
            : null;
        rr_ok(
            $observer['fresh'] === 1 && $observer['replays'] === 0
                && is_array($lossEvidence),
            'lost mutation-release response retains one journaled observation before recovery'
        );
        $releaseLossReceipt = rr_materialize(
            $releaseLossObserved,
            $promotion['callback'],
            60,
            $observer['callback']
        );
        rr_ok(
            ($releaseLossReceipt['format'] ?? null) === 'duo-branch-environment-receipt/v1'
                && $observer['fresh'] === 1 && $observer['replays'] === 1
                && is_array($observer['replayed']) && is_array($lossEvidence)
                && EnvironmentLifecycleCanon::encode($observer['replayed'])
                    === EnvironmentLifecycleCanon::encode($lossEvidence),
            'lost mutation-release recovery returns the journaled observation without target re-execution'
        );
        rr_assert_exact_duplicate(
            rr_calls($releaseLossObserved['target_log']),
            'mutation-release',
            'observed mutation-release recovery reuses exact operation/idempotency input'
        );

        // A pre-observation controller cannot retrofit a rehearsal after its
        // target fence is already released. With no target-observed event there
        // is no safe result to replay and no held authority under which to read.
        $releaseLossUnobserved = rr_fixture(
            $tmp,
            'unobserved-mutation-release-loss',
            'target:mutation-release'
        );
        $promotion = rr_promoter();
        rr_throws(
            static fn() => rr_materialize($releaseLossUnobserved, $promotion['callback']),
            'provider failed',
            'lost unobserved mutation-release response leaves released recovery state'
        );
        $lateObserver = rr_observer();
        rr_throws(
            static fn() => rr_materialize(
                $releaseLossUnobserved,
                $promotion['callback'],
                60,
                $lateObserver['callback']
            ),
            'released without replayable observation evidence',
            'released fence without journaled observation refuses a late target read'
        );
        rr_ok(
            $lateObserver['fresh'] === 0 && $lateObserver['replays'] === 0,
            'missing post-release observation evidence refuses before invoking the target callback'
        );

        // Every provider mutation must survive a lost response with exactly one
        // durable host mutation and a retry that uses the same operation and
        // canonical idempotency input, including release: a durable release
        // intent authorizes only the same idempotent release retry.
        foreach ([
            ['source', 'snapshot-prepare', false],
            ['source', 'snapshot-create', false],
            ['target', 'attach', false],
            ['target', 'create', true],
            ['target', 'mutation-acquire', false],
            ['target', 'snapshot-restore', false],
            ['target', 'repository-materialize', false],
            ['target', 'url-set', false],
            ['target', 'ttl-set', false],
            ['target', 'mutation-release', false],
        ] as [$role, $action, $create]) {
            $name = 'loss-' . str_replace('-', '_', $action) . ($create ? '-create' : '');
            $fixture = rr_fixture($tmp, $name, $role . ':' . $action, 'normal', false, $create);
            $promotion = rr_promoter();
            rr_throws(static fn() => rr_materialize($fixture, $promotion['callback']), 'provider failed', "lost $action response stops the current materialization");
            $receipt = rr_materialize($fixture, $promotion['callback']);
            rr_ok(($receipt['format'] ?? null) === 'duo-branch-environment-receipt/v1', "lost $action response resumes to one materialization receipt");
            $roleCalls = rr_calls($role === 'source' ? $fixture['source_log'] : $fixture['target_log']);
            $allCalls = array_merge(rr_calls($fixture['source_log']), rr_calls($fixture['target_log']));
            rr_assert_exact_duplicate($roleCalls, $action, "lost $action response reuses exact operation/idempotency input");
            rr_assert_no_completed_replay($allCalls, $role, $action, "lost $action response never replays a completed provider phase");
            if ($action === 'snapshot-create') {
                rr_ok(rr_action_calls(rr_calls($fixture['source_log']), 'snapshot-abort') === [],
                    'lost snapshot-create response keeps its deterministic session for exact retry rather than aborting it'
                );
                rr_ok(rr_action_calls(rr_calls($fixture['source_log']), 'snapshot-read') !== [],
                    'lost snapshot-create response reaches immutable snapshot readback only after exact create retry'
                );
            }
        }

        // Production changed after the provider-held prepare/freeze. The
        // physical snapshot must be refused before attach/create/fence/restore
        // and the exact prepared source session must be aborted.
        $mutation = rr_fixture($tmp, 'production-mutation', '', 'source-mutates');
        $promotion = rr_promoter();
        rr_throws(static fn() => rr_materialize($mutation, $promotion['callback']), 'changed prepared', 'production mutation between prepare/create fails closed');
        $sourceActions = array_column(rr_calls($mutation['source_log']), 'action');
        $targetActions = array_column(rr_calls($mutation['target_log']), 'action');
        rr_ok(in_array('snapshot-abort', $sourceActions, true), 'production mutation aborts the exact prepared snapshot session');
        rr_ok($targetActions === ['capabilities'], 'production mutation reaches no target provider mutation');

        // Even the compensating source abort is an idempotent provider
        // mutation. A lost abort response is recovered during explicit reap,
        // without guessing that the source session was already released.
        $abortLoss = rr_fixture($tmp, 'snapshot-abort-loss', 'source:snapshot-abort', 'source-mutates');
        $promotion = rr_promoter();
        rr_throws(static fn() => rr_materialize($abortLoss, $promotion['callback']), 'changed prepared', 'lost snapshot-abort response preserves the original production-mutation refusal');
        $abortReap = rr_reap($abortLoss, $abortLoss['target_provider']);
        rr_ok(($abortReap['disposition'] ?? null) === 'aborted-no-target', 'lost snapshot-abort response reaps the pre-target run without target mutation');
        rr_assert_exact_duplicate(rr_calls($abortLoss['source_log']), 'snapshot-abort', 'lost snapshot-abort response reuses exact operation/idempotency input');
        rr_assert_no_completed_replay(
            array_merge(rr_calls($abortLoss['source_log']), rr_calls($abortLoss['target_log'])),
            'source',
            'snapshot-abort',
            'snapshot-abort recovery never replays completed provider phases'
        );

        // The earlier prepare is also a physical source mutation. If its
        // response is lost before any target acquisition, explicit reap must
        // first recover the deterministic session, then abort that exact
        // session. It must not infer that a missing acknowledgement means no
        // source write was held.
        $prepareLoss = rr_fixture($tmp, 'snapshot-prepare-pre-target-loss', 'source:snapshot-prepare');
        $promotion = rr_promoter();
        rr_throws(static fn() => rr_materialize($prepareLoss, $promotion['callback']), 'provider failed', 'lost snapshot-prepare response leaves deterministic pre-target recovery work');
        $prepareReap = rr_reap($prepareLoss, $prepareLoss['target_provider']);
        rr_ok(($prepareReap['disposition'] ?? null) === 'aborted-no-target', 'lost snapshot-prepare response reaps by aborting the recovered source session');
        $prepareCalls = rr_action_calls(rr_calls($prepareLoss['source_log']), 'snapshot-prepare');
        $abortCalls = rr_action_calls(rr_calls($prepareLoss['source_log']), 'snapshot-abort');
        rr_assert_exact_duplicate(rr_calls($prepareLoss['source_log']), 'snapshot-prepare', 'pre-target reap recovers snapshot-prepare with its exact operation/idempotency input');
        rr_ok(count($prepareCalls) === 2 && count($abortCalls) === 1
            && $abortCalls[0]['operation_id'] === $prepareCalls[0]['operation_id']
            && ($abortCalls[0]['input']['expected_snapshot_session_id'] ?? null) === ($prepareCalls[0]['input']['snapshot_session_id'] ?? null),
            'pre-target reap aborts the exact deterministically recovered snapshot session'
        );
        rr_ok(array_column(rr_calls($prepareLoss['target_log']), 'action') === ['capabilities'], 'lost snapshot-prepare reap reaches no target provider action');

        // The existing promotion path may have committed its own durable
        // receipt while the controller died before journal publication. Retry
        // is reconciliation under the same frozen owner/context, not a second
        // code/state promotion.
        $promotionLoss = rr_fixture($tmp, 'promotion-receipt-loss');
        $promotion = rr_promoter(true);
        rr_throws(static fn() => rr_materialize($promotionLoss, $promotion['callback']), 'response was lost', 'lost frozen-promotion receipt stops before journal publication');
        $resumed = rr_materialize($promotionLoss, $promotion['callback']);
        rr_ok(($resumed['promotion_receipt_sha256'] ?? null) === ($promotion['receipt']['receipt_sha256'] ?? null)
            && $promotion['mutations'] === 1 && count($promotion['calls']) === 2
            && EnvironmentLifecycleCanon::encode($promotion['calls'][0]) === EnvironmentLifecycleCanon::encode($promotion['calls'][1]),
            'lost frozen-promotion receipt reconciles one durable promotion under the same owner/context'
        );
        rr_assert_no_completed_replay(
            array_merge(rr_calls($promotionLoss['source_log']), rr_calls($promotionLoss['target_log'])),
            '',
            'not-a-provider-action',
            'promotion receipt recovery never replays completed provider phases'
        );

        // A state-only compiled release remains a valid exact release identity:
        // null is meaningful and must not become a guessed code revision.
        $stateOnly = rr_fixture($tmp, 'state-only', '', 'normal', true);
        $promotion = rr_promoter();
        $stateReceipt = rr_materialize($stateOnly, $promotion['callback'], 0);
        rr_ok(array_key_exists('code_revision', $stateReceipt) && $stateReceipt['code_revision'] === null
            && is_array($promotion['receipt']) && array_key_exists('code_revision', $promotion['receipt'])
            && $promotion['receipt']['code_revision'] === null,
            'state-only frozen release publishes code_revision null exactly'
        );

        // A response loss after attach/create leaves an acquisition INTENT but
        // not an acquired journal event. Reap must repeat that exact operation
        // and then perform the mode-correct cleanup; it may not treat the lack
        // of a completion event as proof of absence.
        $preTarget = rr_fixture($tmp, 'pre-target-acquire', 'target:attach');
        $promotion = rr_promoter();
        rr_throws(static fn() => rr_materialize($preTarget, $promotion['callback']), 'provider failed', 'lost target-acquire response leaves recovery work');
        $latest = $preTarget['journal']->latestForTarget($preTarget['target']->name());
        rr_ok(is_array($latest) && rr_event_data($latest['events'], 'target-acquire-intent') !== null
            && rr_event_data($latest['events'], 'target-acquired') === null,
            'pre-target crash records only exact target-acquire intent'
        );
        $preReap = rr_reap($preTarget, $preTarget['target_provider']);
        rr_ok(($preReap['disposition'] ?? null) === 'detached', 'pre-target target-acquire intent is recovered and detached safely');
        rr_assert_exact_duplicate(rr_calls($preTarget['target_log']), 'attach', 'pre-target reap reuses exact target-acquire operation/idempotency input');
        rr_ok(rr_action_calls(rr_calls($preTarget['source_log']), 'snapshot-abort') === [], 'pre-target reap does not abort an already immutable snapshot set');

        // Reap inspection is itself receipt-bearing. If an external janitor
        // removes the exact generation and the signed absent response is lost,
        // retry must recover the one durable inspect operation rather than
        // leaking an executing receipt under a fresh id.
        $inspectLoss = rr_fixture($tmp, 'reap-inspect-loss');
        $promotion = rr_promoter();
        $initialReceipt = rr_materialize($inspectLoss, $promotion['callback'], 0);
        $providerState = json_decode(
            (string) file_get_contents($inspectLoss['state']),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($providerState) || array_is_list($providerState)) {
            rr_fail('reap-inspect loss provider state is malformed');
        }
        $providerState['target_present'] = false;
        file_put_contents(
            $inspectLoss['state'],
            EnvironmentLifecycleCanon::encode($providerState),
            LOCK_EX
        );
        $faultyInspectProvider = CommandEnvironmentProvider::fromEnvironment(
            $inspectLoss['target']->name(),
            ($inspectLoss['cfg'])('target', $inspectLoss['target_log'], 'target:inspect')
        );
        rr_throws(
            static fn() => rr_reap($inspectLoss, $faultyInspectProvider),
            'provider failed',
            'lost reap-inspect response leaves its exact durable inspection intent'
        );
        $inspectLatest = $inspectLoss['journal']->latestForTarget($inspectLoss['target']->name());
        $inspectIntent = is_array($inspectLatest)
            ? rr_event_data($inspectLatest['events'], 'reap-inspect-intent')
            : null;
        rr_ok(
            is_array($inspectIntent)
                && is_string($inspectIntent['inspect_operation_id'] ?? null)
                && rr_event_data($inspectLatest['events'], 'reap-inspected') === null,
            'lost reap-inspect response preserves one operation id before contact recovery'
        );
        $inspectOperationId = (string) $inspectIntent['inspect_operation_id'];
        $recoveryInspectProvider = CommandEnvironmentProvider::fromEnvironment(
            $inspectLoss['target']->name(),
            ($inspectLoss['cfg'])('target', $inspectLoss['target_log'], 'target:inspect')
        );
        $inspectedReap = rr_reap($inspectLoss, $recoveryInspectProvider);
        rr_ok(
            ($inspectedReap['disposition'] ?? null) === 'already-absent',
            'lost reap-inspect response recovers the externally absent generation'
        );
        $reapInspects = array_values(array_filter(
            rr_action_calls(rr_calls($inspectLoss['target_log']), 'inspect'),
            static fn(array $call): bool => $call['operation_id'] === $inspectOperationId
        ));
        rr_ok(
            count($reapInspects) === 2
                && EnvironmentLifecycleCanon::encode($reapInspects[0]['input'])
                    === EnvironmentLifecycleCanon::encode($reapInspects[1]['input']),
            'lost reap-inspect response reuses its exact operation id and canonical input'
        );
        $inspectLatest = $inspectLoss['journal']->latestForTarget($inspectLoss['target']->name());
        $inspectEvents = is_array($inspectLatest) ? $inspectLatest['events'] : [];
        rr_ok(
            count(array_filter(
                $inspectEvents,
                static fn(array $event): bool => ($event['event'] ?? null) === 'reap-inspect-intent'
            )) === 1
                && rr_event_data($inspectEvents, 'reap-inspected') !== null,
            'reap inspection recovery journals one intent and one exact result'
        );
        $callsAfterInspectedReap = count(rr_calls($inspectLoss['target_log']));
        $replayedInspectedReap = rr_reap($inspectLoss, $recoveryInspectProvider);
        rr_ok(
            ($replayedInspectedReap['receipt_sha256'] ?? null)
                === ($inspectedReap['receipt_sha256'] ?? null)
                && count(rr_calls($inspectLoss['target_log'])) === $callsAfterInspectedReap,
            'completed inspected-absence reap replays without provider contact'
        );
        $nextPromotion = rr_promoter();
        $replacementReceipt = rr_materialize($inspectLoss, $nextPromotion['callback'], 0);
        rr_ok(
            ($replacementReceipt['format'] ?? null) === 'duo-branch-environment-receipt/v1'
                && ($replacementReceipt['operation_id'] ?? null)
                    !== ($initialReceipt['operation_id'] ?? null),
            'exact inspect recovery retires the old run so the next materialization can start'
        );

        // A cached present inspection can become stale after the service TTL
        // janitor wins but before the controller acquires its reap fence. The
        // recovery probe deliberately carries the old released fence: it must
        // refuse without physical cleanup while present, yet the same bounded
        // operation can recover the exact terminal generation after absence.
        $ttlRace = rr_fixture($tmp, 'ttl-after-reap-inspect', '', 'normal', false, true);
        $promotion = rr_promoter();
        rr_materialize($ttlRace, $promotion['callback'], 60);
        $refusingReapProvider = CommandEnvironmentProvider::fromEnvironment(
            $ttlRace['target']->name(),
            ($ttlRace['cfg'])('target', $ttlRace['target_log'], 'none', 'reap-acquire-refusal')
        );
        rr_throws(
            static fn() => rr_reap($ttlRace, $refusingReapProvider),
            'provider failed',
            'present generation refuses the released-fence terminal probe after cached inspect'
        );
        $ttlRaceLatest = $ttlRace['journal']->latestForTarget($ttlRace['target']->name());
        $ttlRaceEvents = is_array($ttlRaceLatest) ? $ttlRaceLatest['events'] : [];
        $boundedEventCount = count($ttlRaceEvents);
        $terminalProbeIntent = rr_event_data($ttlRaceEvents, 'reap-terminal-probe-intent');
        $cachedReapInspect = rr_event_data($ttlRaceEvents, 'reap-inspected');
        rr_ok(
            is_array($terminalProbeIntent)
                && ($terminalProbeIntent['action'] ?? null) === 'destroy'
                && is_string($terminalProbeIntent['probe_operation_id'] ?? null)
                && (($cachedReapInspect['result']['presence'] ?? null) === 'present'),
            'stale-present race durably chooses one terminal probe after its cached inspection'
        );
        $ttlEvidence = rr_event_data($ttlRaceEvents, 'ttl-set');
        $releasedEvidence = rr_event_data($ttlRaceEvents, 'target-fence-released');
        if (!is_array($ttlEvidence) || !is_array($releasedEvidence)) {
            rr_fail('bounded terminal-probe stress has no exact TTL/released-fence evidence');
        }
        $boundedRefusalProvider = new BoundedReapRefusalProvider(
            $ttlRace['target']->name(),
            $ttlEvidence,
            $releasedEvidence
        );
        for ($attempt = 0; $attempt < 160; $attempt++) {
            try {
                rr_reap($ttlRace, $boundedRefusalProvider);
                rr_fail('present terminal-probe stress unexpectedly reaped the generation');
            } catch (Throwable $failure) {
                if (!str_contains($failure->getMessage(), 'provider failed')) {
                    rr_fail('present terminal-probe stress changed its refusal diagnostic');
                }
            }
        }
        $ttlRaceLatest = $ttlRace['journal']->latestForTarget($ttlRace['target']->name());
        $ttlRaceEvents = is_array($ttlRaceLatest) ? $ttlRaceLatest['events'] : [];
        $terminalProbeIntents = array_values(array_filter(
            $ttlRaceEvents,
            static fn(array $event): bool => ($event['event'] ?? null) === 'reap-terminal-probe-intent'
        ));
        $ttlRaceState = json_decode(
            (string) file_get_contents($ttlRace['state']),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        rr_ok(
            count($ttlRaceEvents) === $boundedEventCount
                && count($terminalProbeIntents) === 1
                && rr_event_data($ttlRaceEvents, 'reap-provider-complete') === null,
            'more than the public 128-cycle bound adds no terminal-probe journal history'
        );
        rr_ok(
            is_array($ttlRaceState)
                && ($ttlRaceState['target_present'] ?? null) === true
                && ($ttlRaceState['cleanup_mutations'] ?? null) === 0,
            'released-fence terminal probes cannot clean up a generation that remains present'
        );
        $probeOperationId = (string) ($terminalProbeIntent['probe_operation_id'] ?? '');
        $presentProbeCalls = array_values(array_filter(
            rr_action_calls(rr_calls($ttlRace['target_log']), 'destroy'),
            static fn(array $call): bool => $call['operation_id'] === $probeOperationId
        ));
        $probeInput = $terminalProbeIntent['input'] ?? null;
        rr_ok(
            count($presentProbeCalls) === 1
                && count($boundedRefusalProvider->terminalCalls) === 160
                && is_array($probeInput)
                && array_reduce(
                    $boundedRefusalProvider->terminalCalls,
                    static fn(bool $same, array $call): bool => $same
                        && $call['operation_id'] === $probeOperationId
                        && EnvironmentLifecycleCanon::encode($call['input'])
                            === EnvironmentLifecycleCanon::encode($probeInput),
                    true
                ),
            'bounded present-state retries reuse one terminal operation id and canonical input'
        );

        if (!is_array($ttlRaceState) || array_is_list($ttlRaceState)) {
            rr_fail('TTL race provider state is malformed before external absence');
        }
        $ttlRaceState['target_present'] = false;
        file_put_contents(
            $ttlRace['state'],
            EnvironmentLifecycleCanon::encode($ttlRaceState),
            LOCK_EX
        );
        $lostTerminalProvider = CommandEnvironmentProvider::fromEnvironment(
            $ttlRace['target']->name(),
            ($ttlRace['cfg'])('target', $ttlRace['target_log'], 'target:destroy', 'normal')
        );
        rr_throws(
            static fn() => rr_reap($ttlRace, $lostTerminalProvider),
            'provider failed',
            'lost signed terminal-absence response retains the one bounded probe intent'
        );
        $recoveryTerminalProvider = CommandEnvironmentProvider::fromEnvironment(
            $ttlRace['target']->name(),
            ($ttlRace['cfg'])('target', $ttlRace['target_log'], 'none', 'normal')
        );
        $ttlRaceReceipt = rr_reap($ttlRace, $recoveryTerminalProvider);
        rr_ok(
            ($ttlRaceReceipt['disposition'] ?? null) === 'destroyed',
            'stale cached presence converges through the janitor terminal-absence receipt'
        );
        $terminalProbeCalls = array_values(array_filter(
            rr_action_calls(rr_calls($ttlRace['target_log']), 'destroy'),
            static fn(array $call): bool => $call['operation_id'] === $probeOperationId
        ));
        $lastProbeCalls = array_slice($terminalProbeCalls, -2);
        rr_ok(
            count($lastProbeCalls) === 2
                && EnvironmentLifecycleCanon::encode($lastProbeCalls[0])
                    === EnvironmentLifecycleCanon::encode($lastProbeCalls[1]),
            'lost terminal-absence response replays the exact operation and input before other contact'
        );
        $ttlRaceFinalState = json_decode(
            (string) file_get_contents($ttlRace['state']),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $ttlRaceLatest = $ttlRace['journal']->latestForTarget($ttlRace['target']->name());
        $ttlRaceEvents = is_array($ttlRaceLatest) ? $ttlRaceLatest['events'] : [];
        rr_ok(
            is_array($ttlRaceFinalState)
                && ($ttlRaceFinalState['cleanup_mutations'] ?? null) === 0
                && count(array_filter(
                    $ttlRaceEvents,
                    static fn(array $event): bool => ($event['event'] ?? null) === 'reap-terminal-probe-intent'
                )) === 1
                && rr_event_data($ttlRaceEvents, 'reap-fence-acquired') === null
                && rr_event_data($ttlRaceEvents, 'reap-destroy-intent') === null,
            'terminal absence completes without a second fence or unauthorized physical destroy'
        );

        // A lost destroy/detach response is the opposite ambiguity: the target
        // may now be absent. The durable reap intent is the authority, so retry
        // must replay that exact idempotent action before asking inspect for a
        // resource whose successful deletion has already made it absent.
        foreach ([['attach', 'detach', false], ['create', 'destroy', true]] as [$acquire, $cleanup, $create]) {
            $fixture = rr_fixture($tmp, 'lost-' . $cleanup, '', 'normal', false, $create);
            $promotion = rr_promoter();
            rr_materialize($fixture, $promotion['callback'], 0);
            $faulty = CommandEnvironmentProvider::fromEnvironment(
                $fixture['target']->name(), ($fixture['cfg'])('target', $fixture['target_log'], 'target:' . $cleanup)
            );
            rr_throws(static fn() => rr_reap($fixture, $faulty), 'provider failed', "lost $cleanup response leaves exact reap intent");
            $before = count(rr_calls($fixture['target_log']));
            $recoveryProvider = CommandEnvironmentProvider::fromEnvironment(
                $fixture['target']->name(), ($fixture['cfg'])('target', $fixture['target_log'], 'target:' . $cleanup)
            );
            $reaped = rr_reap($fixture, $recoveryProvider);
            rr_ok(($reaped['disposition'] ?? null) === ($cleanup === 'destroy' ? 'destroyed' : 'detached'), "lost $cleanup response recovers one absence receipt");
            $all = rr_calls($fixture['target_log']);
            rr_assert_exact_duplicate($all, $cleanup, "lost $cleanup response reuses exact reap operation/idempotency input");
            $retry = array_slice($all, $before);
            $retryActions = array_column($retry, 'action');
            $replayAt = array_search($cleanup, $retryActions, true);
            $inspectAt = array_search('inspect', $retryActions, true);
            rr_ok($replayAt !== false && ($inspectAt === false || $replayAt < $inspectAt),
                "lost $cleanup recovery reissues exact reap before a presence inspect"
            );
        }

        // Once materialization released its fence, reap owns a new fence with
        // a distinct operation id. A lost acquire response may not cause a
        // second reap fence to be minted: recovery must repeat the exact
        // reap-owned acquire and destroy under that same fence lineage.
        $reapAcquireLoss = rr_fixture($tmp, 'reap-owned-acquire-loss');
        $promotion = rr_promoter();
        rr_materialize($reapAcquireLoss, $promotion['callback'], 0);
        $latest = $reapAcquireLoss['journal']->latestForTarget($reapAcquireLoss['target']->name());
        rr_ok(is_array($latest) && rr_event_data($latest['events'], 'target-fence-released') !== null,
            'reap-owned acquire loss starts only after the materialization fence is released'
        );
        $beforeReap = count(rr_calls($reapAcquireLoss['target_log']));
        $faultyReapProvider = CommandEnvironmentProvider::fromEnvironment(
            $reapAcquireLoss['target']->name(), ($reapAcquireLoss['cfg'])('target', $reapAcquireLoss['target_log'], 'target:mutation-acquire')
        );
        rr_throws(static fn() => rr_reap($reapAcquireLoss, $faultyReapProvider), 'provider failed', 'lost reap-owned mutation-acquire response leaves exact reap fence intent');
        $latest = $reapAcquireLoss['journal']->latestForTarget($reapAcquireLoss['target']->name());
        $reapIntent = is_array($latest) ? rr_event_data($latest['events'], 'reap-fence-intent') : null;
        rr_ok(is_array($reapIntent) && ($reapIntent['fence_source'] ?? null) === 'reap-acquire'
            && is_string($reapIntent['reap_operation_id'] ?? null) && $reapIntent['reap_operation_id'] !== ''
            && is_array($reapIntent['input'] ?? null),
            'lost reap-owned mutation-acquire journals one owner and deterministic reap operation'
        );
        $recoveryReapProvider = CommandEnvironmentProvider::fromEnvironment(
            $reapAcquireLoss['target']->name(), ($reapAcquireLoss['cfg'])('target', $reapAcquireLoss['target_log'], 'target:mutation-acquire')
        );
        $reapAcquireReceipt = rr_reap($reapAcquireLoss, $recoveryReapProvider);
        rr_ok(($reapAcquireReceipt['disposition'] ?? null) === 'detached', 'lost reap-owned mutation-acquire recovers one terminal reap receipt');
        $reapCalls = array_slice(rr_calls($reapAcquireLoss['target_log']), $beforeReap);
        $reapAcquires = rr_action_calls($reapCalls, 'mutation-acquire');
        $reapOperationId = (string) $reapIntent['reap_operation_id'];
        $reapDetaches = array_values(array_filter(
            rr_action_calls($reapCalls, 'detach'),
            static fn(array $call): bool => $call['operation_id'] === $reapOperationId
        ));
        $reapInput = $reapIntent['input'];
        $latest = $reapAcquireLoss['journal']->latestForTarget($reapAcquireLoss['target']->name());
        $reapFence = is_array($latest) ? rr_event_data($latest['events'], 'reap-fence-acquired') : null;
        rr_ok(count($reapAcquires) === 2
            && $reapAcquires[0]['operation_id'] === $reapOperationId
            && $reapAcquires[1]['operation_id'] === $reapOperationId
            && EnvironmentLifecycleCanon::encode($reapAcquires[0]['input']) === EnvironmentLifecycleCanon::encode($reapInput)
            && EnvironmentLifecycleCanon::encode($reapAcquires[1]['input']) === EnvironmentLifecycleCanon::encode($reapInput),
            'reap-owned mutation-acquire retry reuses the journaled operation id and canonical owner/input'
        );
        rr_ok(is_array($reapFence) && ($reapFence['mutation_owner'] ?? null) === ($reapInput['mutation_owner'] ?? null)
            && count($reapDetaches) === 1 && $reapDetaches[0]['operation_id'] === $reapOperationId
            && ($reapDetaches[0]['input']['expected_mutation_owner'] ?? null) === ($reapInput['mutation_owner'] ?? null)
            && ($reapDetaches[0]['input']['expected_mutation_id'] ?? null) === ($reapFence['mutation_id'] ?? null)
            && ($reapDetaches[0]['input']['expected_mutation_receipt_sha256'] ?? null) === ($reapFence['mutation_receipt_sha256'] ?? null),
            'reap never destroys under a second fence after lost reap-owned acquisition response'
        );

        // A held read must authenticate the same held receipt. A release is
        // allowed to mint a new receipt for the same lineage, but accepting a
        // changed receipt while held would let a foreign fence replace the
        // authority before snapshot/repository mutation resumes.
        $held = rr_fixture($tmp, 'held-receipt', 'target:snapshot-restore');
        $promotion = rr_promoter();
        rr_throws(static fn() => rr_materialize($held, $promotion['callback']), 'provider failed', 'lost restore response leaves a held fence to reconcile');
        $held['target_provider'] = CommandEnvironmentProvider::fromEnvironment(
            $held['target']->name(), ($held['cfg'])('target', $held['target_log'], 'none', 'held-receipt-drift')
        );
        rr_throws(static fn() => rr_materialize($held, $promotion['callback']), 'rotated', 'held mutation-read with a changed receipt refuses before replaying target mutation');
        rr_ok(count(rr_action_calls(rr_calls($held['target_log']), 'snapshot-restore')) === 1,
            'changed held receipt prevents replay of the interrupted snapshot restore'
        );

        // Release is the sole receipt-mint transition. Once its exact
        // released acknowledgement is journaled, a released-state readback
        // must preserve that receipt before reap can acquire a new fence or
        // perform detach/destroy.
        $released = rr_fixture($tmp, 'released-receipt');
        $promotion = rr_promoter();
        rr_materialize($released, $promotion['callback'], 0);
        $beforeReleasedRead = count(rr_calls($released['target_log']));
        $releasedProvider = CommandEnvironmentProvider::fromEnvironment(
            $released['target']->name(), ($released['cfg'])('target', $released['target_log'], 'none', 'released-receipt-drift')
        );
        rr_throws(static fn() => rr_reap($released, $releasedProvider), 'rotated', 'released mutation-read with a changed receipt refuses stale reap authority');
        $releasedReapCalls = array_slice(rr_calls($released['target_log']), $beforeReleasedRead);
        rr_ok(count(rr_action_calls($releasedReapCalls, 'mutation-read')) === 1
            && rr_action_calls($releasedReapCalls, 'mutation-acquire') === []
            && rr_action_calls($releasedReapCalls, 'detach') === []
            && rr_action_calls($releasedReapCalls, 'destroy') === [],
            'changed released receipt refuses before a fresh reap fence or cleanup mutation'
        );

        echo "PASS: phase-exact environment materialization recovery regression\n";
    } finally {
        rr_remove($tmp);
    }
}
