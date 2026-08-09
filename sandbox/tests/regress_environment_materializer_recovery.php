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

    final class PlanSummary {
        public static function render(array $plan): array {
            return ['lines' => [], 'ok' => $plan === []];
        }
    }
}

namespace {
    $rrRoot = dirname(__DIR__, 2);
    require_once $rrRoot . '/cli/src/EnvironmentDriver.php';
    // A protocol-lane path is only a local validation aid while this test is
    // developed independently. The checked-in default is always the product
    // file, so CI exercises the same public contract after integration.
    $rrLifecycle = getenv('DUO_ENVIRONMENT_LIFECYCLE_PATH');
    if (!is_string($rrLifecycle) || $rrLifecycle === '') {
        $rrLifecycle = $rrRoot . '/cli/src/EnvironmentLifecycle.php';
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

    function rr_fail(string $message): never {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
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
        fclose($pipes[1]); fclose($pipes[2]);
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
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
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
                return ['exit' => 0, 'stdout' => "[]\n", 'stderr' => ''];
            }
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        public function streamWp(array $args): int { $this->calls[] = ['kind' => 'stream', 'args' => $args]; return 0; }
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
    function rr_materialize(array $fixture, callable $promote, int $ttl = 60): array {
        $old = getcwd();
        chdir((string) $fixture['repo']);
        try {
            return EnvironmentMaterializer::materialize(
                $fixture['source'], $fixture['target'], $fixture['source_provider'], $fixture['target_provider'],
                $fixture['journal'], rr_options($fixture, $ttl), $promote
            );
        } finally {
            if ($old !== false) chdir($old);
        }
    }

    /** @param array<string,mixed> $fixture */
    function rr_reap(array $fixture, CommandEnvironmentProvider $targetProvider): array {
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
$state += ['faulted' => [], 'fences' => [], 'records' => [], 'target_present' => true];
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
$mutating = in_array($action, ['snapshot-prepare','snapshot-create','snapshot-abort','attach','create','mutation-acquire','mutation-release','snapshot-restore','repository-materialize','url-set','ttl-set','destroy','detach'], true);
if (isset($state['records'][$key])) {
    if (($state['records'][$key]['input'] ?? null) !== $inputBytes) { fwrite(STDERR, "idempotency input changed\n"); exit(88); }
    $result = $state['records'][$key]['result'];
} else {
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
    if ($action === 'mutation-acquire') $state['fences'][] = array_diff_key($result, array_flip(array_keys($identity)));
    if (in_array($action, ['destroy','detach'], true)) $state['target_present'] = false;
    if ($mutating) $state['records'][$key] = ['input' => $inputBytes, 'result' => $result];
}
save($statePath, $state);
$faultKey = $role . ':' . $action;
if ($mutating && $fault === $faultKey && !($state['faulted'][$faultKey] ?? false)) {
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
        if ($materializeMethod->getNumberOfParameters() !== 7) {
            rr_fail('phase-exact recovery fixture requires the frozen-context materialize callback API; integrate the DUO-3324 protocol lane first');
        }

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
        $reapDetaches = rr_action_calls($reapCalls, 'detach');
        $reapOperationId = (string) $reapIntent['reap_operation_id'];
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

        echo "PASS: phase-exact environment materialization recovery regression\n";
    } finally {
        rr_remove($tmp);
    }
}
