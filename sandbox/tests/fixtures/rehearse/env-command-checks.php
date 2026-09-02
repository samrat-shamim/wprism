<?php
/**
 * Offline checks for the two provider-contract properties `wprism rehearse`
 * inherits from the shipped lifecycle (round-3 MUP §2.2):
 *
 *  1. **Capability negotiation.** A provider advertising a subset of what a
 *     branch materialization needs makes the command REFUSE, naming the
 *     missing capability id, before any target or source mutation. Never
 *     emulation: the only provider action either side sees is `capabilities`.
 *  2. **`--reap` idempotence at the provider contract level.** A second reap
 *     of an already-reaped identity returns the same receipt from absence
 *     evidence without a second destructive provider call, and a reap whose
 *     provider identity has changed refuses instead of reaping a reused
 *     resource.
 *
 * Both are driven through `EnvironmentCommand::run()` — the same host verb
 * boundary `RehearseCommand` will compose — with a fake direct-argv provider,
 * exactly the way sandbox/tests/offline/environment/regress_environment_materializer.php drives
 * `EnvironmentMaterializer`. Offline: no docker, no WordPress, no network.
 *
 * Three collaborators are stubbed in the namespace BEFORE the product files
 * are required, which is legal here because none of the required files
 * declares them (`EnvironmentLifecycle.php` has no require lines at all and
 * calls them by name): `Refresh::rebase()` is the B/P/W resolver, whose real
 * behaviour is another module's regression; `CodeDeploy::compile()` needs a
 * target toolchain; `PlanSummary::render()` is the renderer whose own verdict
 * this suite does not exercise. `PlanContract` is deliberately NOT stubbed —
 * the complete-envelope check at the convergence boundary is product
 * behaviour (issue #3384) and this fixture's `wp` stub emits a complete plan.
 *
 * usage: php env-command-checks.php <scratch-dir>
 *
 * The negotiation refusals are written to STDERR by the command boundary
 * itself; sandbox/tests/offline/assess-contract/regress_rehearse_provider.sh captures this script's
 * STDERR and asserts the capability ids appear there. This script asserts
 * everything observable in-process: exit status, the provider action log, and
 * the reap receipts.
 */
declare(strict_types=1);

namespace WPrism\Orchestrator {
    /** The B/P/W resolver: a deterministic candidate ref plus a semantic plan file. */
    final class Refresh {
        /** @return array<string,mixed> */
        public static function rebase(EnvironmentDriver $driver, string $production, string $branch, array $resolution = []): array {
            $root = trim((string) shell_exec('git rev-parse --show-toplevel'));
            $head = trim((string) shell_exec('git rev-parse HEAD'));
            exec('git update-ref ' . escapeshellarg('refs/heads/' . $branch) . ' ' . escapeshellarg($head), $out, $exit);
            if ($exit !== 0) {
                throw new \RuntimeException('fixture could not create the candidate ref');
            }
            $path = $root . '/.git/rehearse-plan-' . hash('sha256', $branch) . '.json';
            file_put_contents($path, json_encode([
                'context' => ['production_snapshot_hash' => hash('sha256', 'semantic-production')],
                'format' => 'wprism-refresh-plan/v1',
                'plan_hash' => hash('sha256', 'semantic-plan'),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return ['head' => $head, 'new_branch' => $branch, 'plan_path' => $path, 'run_id' => 'fixture'];
        }
    }

    final class CodeDeploy {
        /** @return array<string,mixed> */
        public static function compile(EnvironmentDriver $driver, string $repo, string $artifact): array {
            return [
                'exit' => 0, 'stdout' => '', 'stderr' => '',
                'summary' => [
                    'artifact_hash' => hash('sha256', 'outer-release'),
                    'code' => ['code_revision' => hash('sha256', 'code-release')],
                    'revision_hash' => hash('sha256', 'state-release'),
                ],
            ];
        }
    }

    final class PlanSummary {
        /** @return array<string,mixed> */
        public static function render(array $plan): array {
            return ['lines' => [], 'ok' => ($plan['drift'] ?? []) === [] && ($plan['conflict'] ?? []) === []];
        }
    }
}

namespace {

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/EnvironmentDriver.php';
require_once dirname(__DIR__, 4) . '/cli/src/Plan/PlanContract.php';
require_once dirname(__DIR__, 4) . '/cli/src/Command/EnvironmentCommand.php';

use WPrism\Orchestrator\EnvironmentCommand;

$scratch = $argv[1] ?? '';
if ($scratch === '') {
    fwrite(STDERR, "usage: env-command-checks.php <scratch-dir>\n");
    exit(2);
}

function ec_run(array $command, ?string $cwd = null): string {
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        fwrite(STDERR, "FAIL: could not start fixture command\n");
        exit(1);
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        fwrite(STDERR, 'FAIL: fixture command failed: ' . implode(' ', $command) . "\n$err\n");
        exit(1);
    }
    return trim($out);
}

/** @return list<string> the provider actions one side was asked to perform, in order */
function ec_actions(string $log): array {
    if (!is_file($log)) {
        return [];
    }
    $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_map(static fn (string $line): string => (string) (json_decode($line, true)['action'] ?? ''), $lines);
}

/** One complete `wp wprism plan --format=json` envelope, as agent/src/Apply/Apply.php emits it. */
function ec_plan(): array {
    return [
        'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
        'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
        'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
        'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
        'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
        'skipped_user_meta' => [], 'unchanged' => [], 'update' => [], 'uploads_inventory' => [],
        'warnings' => [],
    ];
}

// ------------------------------------------------------------------ fixture
$repo = $scratch . '/site';
$branchRepo = $scratch . '/branch-repo';
$bin = $scratch . '/bin';
foreach ([$repo, $branchRepo, $bin, $scratch . '/wp'] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        fwrite(STDERR, "FAIL: could not create $dir\n");
        exit(1);
    }
}
ec_run(['git', 'init', '-b', 'feature'], $repo);
ec_run(['git', 'config', 'user.email', 'test@example.invalid'], $repo);
ec_run(['git', 'config', 'user.name', 'Rehearse Fixture'], $repo);
file_put_contents($repo . '/tracked.txt', "branch\n");
ec_run(['git', 'add', 'tracked.txt'], $repo);
ec_run(['git', 'commit', '-m', 'feature'], $repo);

// A `wp` that answers branch convergence (`wprism plan --format=json`) and the
// source URL-binding read (`eval echo home … uploads …`) the materializer
// now performs to rebind a rehearsal target off its restored snapshot.
$wpShim = "#!/usr/bin/env bash\n"
    . 'for a in "$@"; do case "$a" in *get_option*home*) printf '
    . "'http://source.example:9600\\nhttp://source.example:9600/wp-content/uploads\\n'"
    . '; exit 0;; esac; done' . "\n"
    . "cat " . escapeshellarg($scratch . '/plan.json') . "\n";
file_put_contents($bin . '/wp', $wpShim);
chmod($bin . '/wp', 0755);
file_put_contents($scratch . '/plan.json', json_encode(ec_plan(), JSON_UNESCAPED_SLASHES) . "\n");
putenv('PATH=' . $bin . ':' . getenv('PATH'));

// The fake provider: the environment-materializer response shapes, with a capability-subset
// knob so negotiation can be exercised without emulating anything.
$providerScript = $scratch . '/provider.php';
file_put_contents($providerScript, <<<'PHP'
<?php
declare(strict_types=1);
$mode = $argv[1]; $log = $argv[2]; $state = $argv[3];
$raw = (string) stream_get_contents(STDIN);
$request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
file_put_contents($log, $raw . "\n", FILE_APPEND | LOCK_EX);
function c(mixed $v): string { if (is_array($v)) { if (!array_is_list($v)) ksort($v, SORT_STRING); foreach ($v as $k => $x) $v[$k] = json_decode(c($x), true); } return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
$h = static fn(string $v): string => hash('sha256', $v);
$fixtureState = is_file($state) ? trim((string) file_get_contents($state)) : 'current';
$stale = $fixtureState === 'stale';
$releaseSeen = false;
foreach (is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [] as $line) {
  if ((json_decode($line, true)['action'] ?? null) === 'mutation-release') $releaseSeen = true;
}
$identity = [
  'environment_identity' => 'environment-identity-0001',
  'lease_generation' => $stale ? 4 : 3,
  'lease_id' => $stale ? 'lease-identity-stale-0001' : 'lease-identity-0001',
  'ownership_receipt_sha256' => $h('owner'), 'resource_id' => 'resource-identity-0001',
  'url' => 'https://preview.example.test',
];
$a = $request['action']; $i = $request['input'] ?? [];
$caps = ['environment.attach','environment.create','environment.destroy','environment.detach','environment.inspect','environment.mutation.acquire','environment.mutation.read','environment.mutation.release','environment.ttl','environment.ttl.read','environment.url.discover','environment.url.set','operation.receipts','repository.materialize','snapshot.set.abort','snapshot.set.create','snapshot.set.prepare','snapshot.set.read','snapshot.set.restore'];
// A provider that advertises a subset must never be served the missing action.
if ($mode === 'no-repository-materialize') $caps = array_values(array_diff($caps, ['repository.materialize']));
if ($mode === 'no-snapshot-read') $caps = array_values(array_diff($caps, ['snapshot.set.read']));
$owner = (string) ($i['mutation_owner'] ?? $i['expected_mutation_owner'] ?? '');
$materialFence = str_contains($owner, 'wprism-env-materialize-');
$fenceId = $materialFence ? 'mutation-material-0001' : 'mutation-reap-0001';
$heldReceipt = $h($materialFence ? 'mutation-material-held' : 'mutation-reap-held');
$releasedReceipt = $h($materialFence ? 'mutation-material-released' : 'mutation-reap-released');
$mutation = static fn(string $s, string $receipt): array => $identity + [
  'mutation_generation'=>1, 'mutation_id'=>$fenceId, 'mutation_owner'=>$owner,
  'mutation_receipt_sha256'=>$receipt, 'state'=>$s,
];
$result = match ($a) {
 'capabilities' => ['capabilities' => $caps],
 'inspect','attach','create' => $identity + ['presence' => 'present'],
 'snapshot-prepare' => ['lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'snapshot_session_id'=>(string)$i['snapshot_session_id'],'source_identity'=>'environment-identity-0001'],
 'snapshot-create' => ['database_sha256'=>$h('db'),'lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'media_sha256'=>$h('media'),'retention_receipt_sha256'=>$h('retention'),'semantic_snapshot_sha256'=>(string)$i['expected_semantic_snapshot_sha256'],'snapshot_session_id'=>(string)$i['expected_snapshot_session_id'],'snapshot_set_id'=>'snapshot-set-0001','snapshot_set_receipt_sha256'=>$h('snapshot-set'),'source_identity'=>'environment-identity-0001'],
 'snapshot-read' => ['database_sha256'=>$h('db'),'immutable'=>true,'lease_generation'=>1,'lease_id'=>'snapshot-lease-0001','lease_receipt_sha256'=>$h('snapshot-lease'),'media_sha256'=>$h('media'),'retention_receipt_sha256'=>$h('retention'),'semantic_snapshot_sha256'=>$h('semantic-production'),'snapshot_session_id'=>(string)$i['expected_snapshot_session_id'],'snapshot_set_id'=>(string)$i['expected_snapshot_set_id'],'snapshot_set_receipt_sha256'=>(string)$i['expected_snapshot_set_receipt_sha256'],'source_identity'=>'environment-identity-0001'],
 'snapshot-abort' => ['disposition'=>'aborted','lease_generation'=>(int)$i['expected_source_lease_generation'],'lease_id'=>(string)$i['expected_source_lease_id'],'lease_receipt_sha256'=>(string)$i['expected_source_lease_receipt_sha256'],'snapshot_session_id'=>(string)$i['expected_snapshot_session_id'],'source_identity'=>(string)$i['expected_source_identity']],
 'snapshot-restore' => $identity + ['snapshot_set_id'=>(string)$i['snapshot_set_id']],
 'repository-materialize' => $identity + ['branch_commit'=>(string)$i['branch_commit'],'repository_receipt_sha256'=>$h('repo')],
 'url-set' => $identity,
 'mutation-acquire' => $mutation('held', $heldReceipt),
 'mutation-read' => $mutation($materialFence && $releaseSeen ? 'released' : 'held', $materialFence && $releaseSeen ? $releasedReceipt : $heldReceipt),
 'mutation-release' => $mutation('released', $releasedReceipt),
 'ttl-set','ttl-read' => $identity + ['expires_at'=>'2030-01-02T04:04:05Z','ttl_generation'=>1,'ttl_lease_id'=>'ttl-lease-identity-0001','ttl_receipt_sha256'=>$h('ttl'),'ttl_state'=>'active'],
 'destroy','detach' => ['absence_proof_sha256'=>$h('absence'),'disposition'=>$a === 'destroy' ? 'destroyed' : 'detached','environment_identity'=>'environment-identity-0001','lease_generation'=>3,'lease_id'=>'lease-identity-0001','ownership_receipt_sha256'=>$h('owner'),'resource_id'=>'resource-identity-0001'],
 default => [],
};
$response = ['action'=>$a,'environment'=>$request['environment'],'format'=>'wprism-branch-environment-provider-response/v1','operation_id'=>$request['operation_id'],'provider'=>['id'=>'rehearse-fixture-provider','protocol'=>1],'result'=>$result,'status'=>'ok'];
echo c($response) . "\n";
PHP);

$providerState = $scratch . '/provider-state';
file_put_contents($providerState, "current\n");

/**
 * @return string the envs-overlay path binding one source/target provider mode pair
 */
$writeEnvs = static function (string $suffix, string $sourceMode, string $targetMode) use ($scratch, $repo, $branchRepo, $providerScript, $providerState): string {
    $envs = [
        'envs' => [
            'production' => [
                'transport' => 'local',
                'wp_path' => $scratch . '/wp',
                'repo_path' => $repo,
                'environment_provider' => [
                    'command' => [PHP_BINARY, $providerScript, $sourceMode, $scratch . '/source-' . $suffix . '.log', $providerState],
                    'timeout_seconds' => 20,
                ],
            ],
            'preview' => [
                'transport' => 'local',
                'wp_path' => $scratch . '/wp',
                'repo_path' => $branchRepo,
                'environment_provider' => [
                    'command' => [PHP_BINARY, $providerScript, $targetMode, $scratch . '/target-' . $suffix . '.log', $providerState],
                    'timeout_seconds' => 20,
                ],
            ],
        ],
    ];
    $path = $scratch . '/envs-' . $suffix . '.json';
    file_put_contents($path, json_encode($envs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    return $path;
};

$promotions = 0;
$promote = static function (\WPrism\Orchestrator\EnvironmentDriver $driver, array $frozenContext) use (&$promotions): array {
    $promotions++;
    // The real frozen promotion prints human phase progress. A machine
    // materialization must contain it and publish only its final document.
    echo "nested promotion progress that must not enter machine stdout\n";
    $summary = $frozenContext['compiled_summary'];
    $body = [
        'artifact_hash' => (string) $summary['artifact_hash'],
        'checkpoint_identity' => hash('sha256', 'checkpoint-' . $frozenContext['operation_id']),
        'code_revision' => (string) $summary['code']['code_revision'],
        'format' => 'wprism-branch-environment-promotion-receipt/v1',
        'operation_id' => $frozenContext['operation_id'],
        'owner' => $frozenContext['promotion_owner'],
        'state_revision' => (string) $summary['revision_hash'],
        'status' => 'completed',
    ];
    $body['receipt_sha256'] = hash('sha256', \WPrism\Orchestrator\EnvironmentLifecycleCanon::encode($body));

    return $body;
};

$previous = getcwd();
chdir($repo);

// ------------------------------------------- 1. capability negotiation
echo "case: target advertises a subset\n";
$targetSubset = $writeEnvs('target-subset', 'ok', 'no-repository-materialize');
$status = EnvironmentCommand::run(
    ['materialize', 'preview', '--from', 'production', '--branch', 'feature'],
    $targetSubset,
    $promote
);
wprism_check_same(1, $status, 'a target provider missing repository.materialize refuses the materialization');
wprism_check_same(
    ['capabilities'],
    ec_actions($scratch . '/target-target-subset.log'),
    'the refusal makes no target mutation: negotiation is the only provider action'
);
wprism_check_same(
    ['capabilities'],
    ec_actions($scratch . '/source-target-subset.log'),
    'and no source snapshot session is opened either'
);
wprism_check_same(0, $promotions, 'a negotiation refusal never reaches promotion');

echo "case: source advertises a subset\n";
$sourceSubset = $writeEnvs('source-subset', 'no-snapshot-read', 'ok');
$status = EnvironmentCommand::run(
    ['materialize', 'preview', '--from', 'production', '--branch', 'feature'],
    $sourceSubset,
    $promote
);
wprism_check_same(1, $status, 'a source provider missing snapshot.set.read refuses the materialization');
wprism_check_same(
    ['capabilities'],
    ec_actions($scratch . '/source-source-subset.log'),
    'the source refusal happens at negotiation, before any snapshot is prepared'
);
wprism_check_same(
    [],
    ec_actions($scratch . '/target-source-subset.log'),
    'the target provider is never invoked once the source cannot serve the operation'
);

// --------------------------------------------- 2. materialize, then reap
echo "case: materialize then reap twice\n";
$ok = $writeEnvs('ok', 'ok', 'ok');
ob_start();
$status = EnvironmentCommand::run(
    ['materialize', 'preview', '--from', 'production', '--branch', 'feature', '--format=json'],
    $ok,
    $promote
);
$materializeOut = (string) ob_get_clean();
wprism_check_same(0, $status, 'a fully-capable provider pair materializes the rehearsal environment');
$receipt = json_decode($materializeOut, true);
wprism_check_same('attach', $receipt['mode'] ?? null, 'the rehearsal attaches its target by default');
wprism_check(
    !str_contains($materializeOut, 'nested promotion progress') && substr_count($materializeOut, '"format"') === 1,
    'machine materialization contains nested human promotion progress and emits exactly one JSON document'
);
wprism_check_same(1, $promotions, 'materialization uses the supplied promotion path exactly once');

// A reap whose provider identity has changed must refuse, not reap a reused
// resource. This is the exact compare `--reap` inherits.
file_put_contents($providerState, "stale\n");
$status = EnvironmentCommand::run(['reap', 'preview'], $ok, $promote);
wprism_check_same(1, $status, 'a changed provider lease refuses the reap');
wprism_check(
    !in_array('detach', ec_actions($scratch . '/target-ok.log'), true),
    'the stale-identity refusal makes no detach or destroy call'
);

file_put_contents($providerState, "current\n");
ob_start();
$status = EnvironmentCommand::run(['reap', 'preview', '--format=json'], $ok, $promote);
$firstReap = (string) ob_get_clean();
wprism_check_same(0, $status, 'the first reap succeeds');
$first = json_decode($firstReap, true);
wprism_check_same('detached', $first['disposition'] ?? null, 'an attached target is released only through detach');
wprism_check(
    !in_array('destroy', ec_actions($scratch . '/target-ok.log'), true),
    'an attached target is never destroyed'
);
$detachCalls = count(array_filter(ec_actions($scratch . '/target-ok.log'), static fn (string $a): bool => $a === 'detach'));

ob_start();
$status = EnvironmentCommand::run(['reap', 'preview', '--format=json'], $ok, $promote);
$secondReap = (string) ob_get_clean();
wprism_check_same(0, $status, 'a second reap of a reaped identity succeeds');
$second = json_decode($secondReap, true);
wprism_check_same(
    $first['receipt_sha256'] ?? 'first',
    $second['receipt_sha256'] ?? 'second',
    'the second reap returns the SAME receipt from absence evidence: --reap is idempotent'
);
wprism_check_same(true, $second['resumed'] ?? false, 'the repeated reap is reported as resumed, not as a new reap');
wprism_check_same(
    $detachCalls,
    count(array_filter(ec_actions($scratch . '/target-ok.log'), static fn (string $a): bool => $a === 'detach')),
    'the repeated reap makes no second destructive provider call'
);
wprism_check_same(1, $promotions, 'no reap replays the journaled promotion');

chdir($previous ?: '/');
wprism_check_summary('rehearsal provider contract through EnvironmentCommand');
}
