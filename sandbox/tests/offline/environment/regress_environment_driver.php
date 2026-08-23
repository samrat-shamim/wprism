<?php
// DUO-3346: offline contract for the host-side environment-driver boundary.

declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Transport/LocalTransport.php';
require_once __DIR__ . '/../../../../cli/src/Transport/DockerTransport.php';
require_once __DIR__ . '/../../../../cli/src/Transport/SshTransport.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/Doctor.php';
require_once __DIR__ . '/../../../../cli/src/Transport/CodeDeploy.php';
require_once __DIR__ . '/../../../../cli/src/Command/ScopeCommand.php';
require_once __DIR__ . '/../../../../cli/src/Refresh/Refresh.php';
require_once __DIR__ . '/../../../../cli/src/Command/EnvironmentCommandPreflight.php';

use Duo\Orchestrator\CodeDeploy;
use Duo\Orchestrator\DockerTransport;
use Duo\Orchestrator\Doctor;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\EnvironmentCommandPreflight;
use Duo\Orchestrator\LocalTransport;
use Duo\Orchestrator\Refresh;
use Duo\Orchestrator\SshTransport;
use Duo\Orchestrator\ScopeCommand;

function fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function pass(string $message): void {
    echo "ok: $message\n";
}

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        fail($message);
    }
}

/** @return list<string> */
function required_capabilities(DriverCapabilityReport $report): array {
    return array_map(
        static fn(array $row): string => (string) $row['capability'],
        $report->toArray()['requirements']
    );
}

final class RecordingDriver implements EnvironmentDriver {
    public int $targetCalls = 0;

    public function name(): string { return 'recording'; }
    public function driverId(): string { return 'recording'; }
    public function repoPath(): string { return '/repo'; }
    public function describe(): string { return 'recording driver'; }
    public function captureRaw(string $script): array {
        $this->targetCalls++;
        return ['exit' => 97, 'stdout' => '', 'stderr' => 'unexpected target call'];
    }
    public function captureWp(array $wpArgs): array {
        $this->targetCalls++;
        return ['exit' => 98, 'stdout' => '', 'stderr' => 'unexpected target call'];
    }
    public function streamWp(array $wpArgs): int {
        $this->targetCalls++;
        return 99;
    }
    public function wpInstruction(array $wpArgs): string {
        $this->targetCalls++;
        return 'unexpected';
    }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver(
            $this->name(),
            $this->driverId(),
            $operation,
            [DriverCapability::ATTACH => true, DriverCapability::WP_CONTROL => true]
        );
    }
}

$local = new LocalTransport('local-proof', [
    'transport' => 'local', 'wp_path' => '/wordpress', 'repo_path' => '/repo',
]);
$docker = new DockerTransport('container-proof', [
    'transport' => 'docker', 'compose_file' => '/tmp/duo-driver-compose.yml',
    'service' => 'cli', 'repo_path' => '/repo',
]);
// DUO-3513: the opt-in `mode: "exec"` docker driver is a first-class
// EnvironmentDriver too -- it must share the exact same attach/promote/
// create capability contract as `run` mode, `local`, and `ssh`. The probe
// seam is fixed to "running" so capabilityReport() (which never touches the
// target) stays deterministic offline; regress_docker_exec_mode.php is the
// suite for the mode's own command-string and not-running behavior.
$dockerExec = new DockerTransport('container-exec-proof', [
    'transport' => 'docker', 'compose_file' => '/tmp/duo-driver-compose.yml',
    'service' => 'cli', 'repo_path' => '/repo', 'mode' => 'exec',
], static fn(): bool => true);
$ssh = new SshTransport('ssh-proof', [
    'transport' => 'ssh', 'host' => 'fixture.invalid',
    'wp_path' => '/wordpress', 'repo_path' => '/repo',
]);
assert_true(
    str_starts_with($ssh->wpInstruction(['duo', 'status']), 'ssh -T '),
    'SSH transport does not explicitly defeat a RequestTTY=force user configuration'
);

$expectedVocabulary = [
    'environment.attach', 'environment.bootstrap', 'environment.create', 'environment.destroy',
    'environment.ttl', 'control.wp_cli', 'control.raw', 'code.transfer', 'code.materialize',
    'snapshot.database.create', 'snapshot.database.read', 'snapshot.database.restore',
    'snapshot.media.create', 'snapshot.media.read', 'snapshot.media.restore',
    'maintenance.enter', 'maintenance.exit', 'environment.url.discover', 'environment.url.set',
    'operation.receipts',
];
sort($expectedVocabulary, SORT_STRING);
assert_true(DriverCapability::all() === $expectedVocabulary, 'capability vocabulary is not closed and complete');
pass('closed vocabulary names lifecycle, control, code, snapshot, maintenance, URL, TTL, and receipts');

$proofRequirements = null;
foreach ([$local, $docker, $dockerExec, $ssh] as $driver) {
    assert_true($driver instanceof EnvironmentDriver, $driver->driverId() . ' does not implement EnvironmentDriver');
    $attach = $driver->capabilityReport('attach');
    assert_true($attach->ready(), $driver->driverId() . ' cannot attach to a pre-existing target');
    $promote = required_capabilities($driver->capabilityReport('promote'));
    $proofRequirements ??= $promote;
    assert_true($promote === $proofRequirements, $driver->driverId() . ' does not use the same promotion proof flow');
    $create = $driver->capabilityReport('create');
    assert_true(!$create->ready(), $driver->driverId() . ' silently inferred create from attach');
    assert_true(required_capabilities($create) === ['environment.attach', 'environment.create'], 'create requirements changed');
}
pass('local, container (run and exec mode), and SSH drivers share one proof contract while attach remains distinct from create');

assert_true(!$local->capabilityReport('adopt')->ready(), 'local driver fabricated bootstrap support without an opt-in');
assert_true(!$docker->capabilityReport('adopt')->ready(), 'container driver fabricated bootstrap support');
assert_true(!$dockerExec->capabilityReport('adopt')->ready(), 'container driver in exec mode still fabricated no bootstrap support');
assert_true($ssh->capabilityReport('adopt')->ready(), 'SSH adoption path did not declare its actual upload/bootstrap support');
pass('driver-specific bootstrap support is explicit and truthful');

$first = $local->capabilityReport('promote')->toArray();
$second = $local->capabilityReport('promote')->toArray();
assert_true($first === $second, 'identical capability reports are not byte-stable');
assert_true(
    preg_match('/^sha256:[a-f0-9]{64}$/', (string) $first['digest']) === 1,
    'capability report digest is missing or malformed'
);
assert_true($first['format'] === DriverCapabilityReport::FORMAT, 'capability report format is not versioned');
pass('canonical report and digest are stable for the same driver operation');

try {
    $local->capabilityReport('guess-and-destroy');
    fail('unknown operation was accepted');
} catch (RuntimeException $e) {
    assert_true(str_contains($e->getMessage(), 'unknown driver operation'), 'unknown-operation error lost its boundary');
}
pass('unknown operations fail closed');

$recording = new RecordingDriver();
$denied = $recording->capabilityReport('destroy');
assert_true(!$denied->ready(), 'destroy unexpectedly passed without destroy/receipt capabilities');
assert_true($recording->targetCalls === 0, 'denied destructive preflight contacted the target');
assert_true(
    required_capabilities($denied) === ['environment.destroy', 'operation.receipts'],
    'destroy is not bound to exact ownership/receipt requirements'
);
pass('unsupported destructive operation refuses before any driver target call');

$boundaries = [
    [new ReflectionMethod(Doctor::class, 'run'), 0],
    [new ReflectionMethod(CodeDeploy::class, 'compile'), 0],
    [new ReflectionMethod(Refresh::class, 'refresh'), 0],
    [new ReflectionMethod(Refresh::class, 'rebase'), 0],
];
foreach ($boundaries as [$method, $index]) {
    $type = $method->getParameters()[$index]->getType();
    assert_true($type instanceof ReflectionNamedType, $method->getName() . ' driver parameter is untyped');
    assert_true($type->getName() === EnvironmentDriver::class, $method->getName() . ' still depends on a concrete transport');
}
pass('core doctor, compile, refresh, and rebase workflows depend on the narrow driver interface');

/** @return array{exit:int,stdout:string,stderr:string} */
function invoke_cli(array $args): array {
    $command = array_merge([PHP_BINARY, __DIR__ . '/../../../../cli/duo'], $args);
    $proc = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        fail('could not start public duo CLI');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

$tmp = sys_get_temp_dir() . '/duo-driver-contract-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fail('could not create CLI fixture directory');
}
$envsFile = $tmp . '/envs.json';
$envs = [
    'envs' => [
        'local-proof' => [
            'transport' => 'local', 'wp_path' => $tmp . '/wordpress', 'repo_path' => $tmp . '/repo',
        ],
        'local-bootstrap' => [
            'transport' => 'local', 'wp_path' => $tmp . '/bootstrap-wordpress',
            'repo_path' => $tmp . '/bootstrap-repo',
            'bootstrap' => ['format' => LocalTransport::BOOTSTRAP_FORMAT],
        ],
        'ssh-proof' => [
            'transport' => 'ssh', 'host' => 'driver-proof.invalid',
            'wp_path' => '/wordpress', 'repo_path' => '/repo',
        ],
    ],
];
file_put_contents($envsFile, json_encode($envs, JSON_UNESCAPED_SLASHES));

$visible = invoke_cli([
    '--envs-file=' . $envsFile, 'driver-capabilities', 'local-proof',
    '--operation=promote', '--format=json',
]);
assert_true($visible['exit'] === 0, 'public supported capability report returned non-zero: ' . $visible['stderr']);
$visibleBody = json_decode($visible['stdout'], true);
assert_true(is_array($visibleBody) && $visibleBody['ready'] === true, 'public JSON report was not canonical/ready');
assert_true($visibleBody['format'] === DriverCapabilityReport::FORMAT, 'public JSON report used another format');

$unsupported = invoke_cli([
    '--envs-file=' . $envsFile, 'driver-capabilities', 'ssh-proof',
    '--operation=create', '--format=json',
]);
$unsupportedBody = json_decode($unsupported['stdout'], true);
assert_true($unsupported['exit'] !== 0, 'unsupported create report returned success');
assert_true(is_array($unsupportedBody) && $unsupportedBody['ready'] === false, 'unsupported create was not visible in JSON');

$authorizedLocal = invoke_cli([
    '--envs-file=' . $envsFile, 'driver-capabilities', 'local-bootstrap',
    '--operation=adopt', '--format=json',
]);
$authorizedLocalBody = json_decode($authorizedLocal['stdout'], true);
assert_true($authorizedLocal['exit'] === 0, 'machine-local bootstrap mechanism report returned non-zero');
assert_true(
    is_array($authorizedLocalBody)
        && ($authorizedLocalBody['ready'] ?? false) === true
        && !file_exists($tmp . '/bootstrap-wordpress')
        && !file_exists($tmp . '/bootstrap-repo'),
    'target-free authorized local capability reporting contacted or changed the target'
);

$deniedCli = invoke_cli(['--envs-file=' . $envsFile, 'adopt', 'local-proof']);
assert_true($deniedCli['exit'] !== 0, 'local adopt silently emulated SSH bootstrap');
assert_true(
    str_contains($deniedCli['stderr'], "driver preflight blocked 'adopt'")
        && str_contains($deniedCli['stderr'], DriverCapability::BOOTSTRAP),
    'public refusal did not name the exact missing bootstrap capability'
);
assert_true(!file_exists($tmp . '/repo'), 'denied public workflow mutated its target path');

// DUO-3344 contract evidence is only truthful when the host path cannot boot
// arbitrary plugins/themes/ordinary MU code before the agent compiles its
// target-independent revision. Exercise the real public CLI with a fake wp
// binary and capture every forwarded argument; this is stronger than a source
// grep because a future dispatch refactor must still pass the actual control
// arguments through Transport::streamWp().
$fakeBin = $tmp . '/scope-bin';
mkdir($fakeBin, 0700, true);
$scopeArgs = $tmp . '/scope-args.txt';
$fakeWp = $fakeBin . '/wp';
file_put_contents(
    $fakeWp,
    '#!/usr/bin/env bash' . "\n"
        . 'printf \'%s\\n\' "$@" > "$DUO_SCOPE_ARGS"' . "\n"
        . 'for arg in "$@"; do' . "\n"
        . '  if [ "$arg" = lint ]; then echo LINT_STREAM_MARKER; exit 23; fi' . "\n"
        . 'done' . "\n"
);
chmod($fakeWp, 0700);
$oldPath = getenv('PATH') ?: '';
putenv('PATH=' . $fakeBin . ':' . $oldPath);
putenv('DUO_SCOPE_ARGS=' . $scopeArgs);
$scopeForward = invoke_cli([
    '--envs-file=' . $envsFile, 'scope', 'local-proof', '--roots=all', '--contract',
]);
assert_true($scopeForward['exit'] === 0, 'isolated public scope forwarding returned non-zero: ' . $scopeForward['stderr']);
$forwarded = is_file($scopeArgs) ? file($scopeArgs, FILE_IGNORE_NEW_LINES) : false;
assert_true(is_array($forwarded), 'scope forwarding did not invoke the transport wp command');
assert_true(
    is_array($forwarded)
        && count(array_filter($forwarded, static fn(string $arg): bool => str_starts_with($arg, '--exec='))) === 1
        && in_array('--skip-plugins', $forwarded, true)
        && in_array('--skip-themes', $forwarded, true)
        && in_array('duo', $forwarded, true)
        && in_array('scope', $forwarded, true)
        && in_array('--repo=' . $tmp . '/repo', $forwarded, true)
        && in_array('--roots=all', $forwarded, true)
        && in_array('--contract', $forwarded, true),
    'scope transport forwards control-plane --exec/skip flags and contract roots verbatim'
);
$captureForward = invoke_cli([
    '--envs-file=' . $envsFile, 'capture', 'local-proof', '--format=json',
]);
assert_true($captureForward['exit'] === 0, 'public capture forwarding returned non-zero: ' . $captureForward['stderr']);
$captureArgs = is_file($scopeArgs) ? file($scopeArgs, FILE_IGNORE_NEW_LINES) : false;
assert_true(
    is_array($captureArgs)
        && in_array('capture', $captureArgs, true)
        && in_array('--repo=' . $tmp . '/repo', $captureArgs, true)
        && !in_array('--orchestrator-environment=local-proof', $captureArgs, true)
        && !array_filter(
            $captureArgs,
            static fn(string $arg): bool => str_starts_with($arg, '--orchestrator-envs-file=')
        )
        && $captureForward['stderr'] === '',
    'public capture keeps the registry path host-local and does not guess an unobserved lint warning'
);
$lintForward = invoke_cli([
    '--envs-file=' . $envsFile, 'lint', 'local-proof', '--format=json',
]);
putenv('PATH=' . $oldPath);
putenv('DUO_SCOPE_ARGS');
assert_true($lintForward['exit'] === 23, 'public lint did not preserve the agent failure exit: ' . $lintForward['stderr']);
assert_true(
    str_contains($lintForward['stdout'], 'LINT_STREAM_MARKER'),
    'public lint did not stream the agent output marker'
);
$lintArgs = is_file($scopeArgs) ? file($scopeArgs, FILE_IGNORE_NEW_LINES) : false;
assert_true(
    is_array($lintArgs)
        && in_array('duo', $lintArgs, true)
        && in_array('lint', $lintArgs, true)
        && in_array('--repo=' . $tmp . '/repo', $lintArgs, true)
        && in_array('--format=json', $lintArgs, true),
    'public lint resolves the environment and forwards the bound repository plus user flags'
);
$beforeOverrideArgs = is_file($scopeArgs) ? file_get_contents($scopeArgs) : false;
$lintOverride = invoke_cli([
    '--envs-file=' . $envsFile, 'lint', 'local-proof', '--repo=/other/repository', '--format=json',
]);
$lintOverrideBody = json_decode(trim($lintOverride['stdout']), true);
assert_true(
    $lintOverride['exit'] === 1
        && is_array($lintOverrideBody)
        && ($lintOverrideBody['error'] ?? null) === 'invalid_arguments'
        && file_get_contents($scopeArgs) === $beforeOverrideArgs,
    'public lint rejects a caller repository override before target contact'
);
$controlArgs = CodeDeploy::controlArgs(['duo', 'scope']);
assert_true(
    str_contains($controlArgs[0], 'DUO_CONTROL_PLANE')
        && str_contains($controlArgs[0], 'WPMU_PLUGIN_DIR'),
    'scope control bootstrap isolates ordinary MU-plugin loading before the agent runs'
);
unlink($scopeArgs);
unlink($fakeWp);
rmdir($fakeBin);
unlink($envsFile);
rmdir($tmp);
pass('public JSON/human paths share the report and refuse before target mutation');

// DUO-3344: every verb that reaches the driver preflight must be known to
// DriverCapabilityReport::requirements(). Registering a verb in cli/duo's
// dispatch and usage while forgetting this third table produces a command
// that parses, documents, and routes correctly and then dies in the
// preflight on EVERY transport before any work — which is exactly how
// `duo scope` shipped broken. The verb list and the exemptions are both
// read out of cli/duo rather than restated here, so this cannot pass by
// being updated in lockstep with the bug.
$duoSource = file_get_contents(__DIR__ . '/../../../../cli/duo');
assert_true(is_string($duoSource) && $duoSource !== '', 'could not read cli/duo');
assert_true(
    str_contains($duoSource, '`duo env-set <env>')
        && str_contains($duoSource, '[A-Za-z0-9][A-Za-z0-9._-]{0,63}'),
    'public help keeps the complete host env-set command and environment-name grammar'
);

// The vocabulary is a runtime collaborator, not a copied source anchor. This
// keeps the contract tied to the implementation that dispatches commands.
$verbsNeedingEnv = EnvironmentCommandPreflight::environmentVerbs();
assert_true(count($verbsNeedingEnv) >= 15, 'environment preflight vocabulary is implausibly short');
assert_true(in_array('scope', $verbsNeedingEnv, true), 'scope is not registered in $verbsNeedingEnv');
assert_true(
    str_contains($duoSource, "'scope' => cmd_scope(\$transport, \$extra)")
        && str_contains($duoSource, 'function cmd_scope(')
        && str_contains($duoSource, 'ScopeCommand::run($t, $extra)'),
    'scope dispatch is not registered through the extracted isolated control-plane handler'
);
assert_true(
    (new ReflectionMethod(ScopeCommand::class, 'run'))->isStatic(),
    'scope command handler does not expose its standalone static boundary'
);
assert_true(in_array('explain', $verbsNeedingEnv, true), 'explain is not registered in $verbsNeedingEnv');
assert_true(
    str_contains($duoSource, 'EnvironmentCommandPreflight::requiresEnvironment('),
    'cli/duo does not ask the preflight collaborator whether a command needs an environment'
);

// A verb may legitimately never reach the common preflight if main() returns
// for it first (driver-capabilities renders the report itself). Keep that
// single, explicit exception in the behavioral check.
assert_true(
    str_contains($duoSource, 'EnvironmentCommandPreflight::capabilityReport('),
    'cli/duo does not route driver capability checks through the preflight collaborator'
);

$requirements = new ReflectionMethod(DriverCapabilityReport::class, 'requirements');
$unknown = [];
$reachedPreflight = 0;
foreach ($verbsNeedingEnv as $verb) {
    // driver-capabilities renders its report directly after transport setup;
    // all other environment verbs use the common driver preflight path.
    if ($verb === 'driver-capabilities') {
        continue;
    }
    $reachedPreflight++;
    try {
        $requirements->invoke(null, $verb);
    } catch (\Throwable $t) {
        $unknown[] = $verb . ' (' . $t->getMessage() . ')';
    }
}
assert_true($reachedPreflight >= 14, 'derived exemptions swallowed nearly every verb — the check would prove nothing');
assert_true(
    $unknown === [],
    'these cli/duo verbs reach the driver preflight but are unknown to requirements(): ' . implode('; ', $unknown)
);
assert_true(
    $requirements->invoke(null, 'scope') === $requirements->invoke(null, 'coverage'),
    'scope must demand exactly what the other read-only passthrough demands'
);
assert_true(
    $requirements->invoke(null, 'explain') === $requirements->invoke(null, 'plan'),
    'explain must demand exactly the attach + wp-cli control capabilities plan requires'
);
assert_true(
    $requirements->invoke(null, 'lint') === $requirements->invoke(null, 'coverage'),
    'lint must demand exactly the attach + wp-cli control capabilities of other read-only scans'
);
pass('every cli/duo verb reaching the driver preflight resolves through requirements()');

// DUO: `duo envs` output is what an operator diffs when they wonder whether a
// change reached their environments. Admitting LocalTransport to the recovery
// capability interface must not move one byte of it for an environment that
// never configured a rollback authority (AGENTS.md rule 8), so the three
// un-configured describe() lines are asserted byte-exactly here rather than
// left to a reviewer's eye.
assert_true(
    $local->describe() === 'local  wp_path=/wordpress repo_path=/repo',
    'the un-configured local describe line moved'
);
assert_true(
    $docker->describe() === 'docker compose_file=/tmp/duo-driver-compose.yml service=cli repo_path=/repo',
    'the un-configured docker describe line moved'
);
assert_true(
    $ssh->describe() === 'ssh    host=fixture.invalid wp_path=/wordpress repo_path=/repo',
    'the un-configured ssh describe line moved'
);

// And the opted-in form is the SSH suffix set, in the SSH order, because one
// RecoveryConfig renders it for every transport.
$providerArgv = ['/bin/true'];
$recoveryKeys = [
    'rollback_key_id' => 'envs-proof-key',
    'rollback_recovery' => [
        'adapters' => [
            'code_restore' => $providerArgv,
            'database_restore' => $providerArgv,
            'prior_verify' => $providerArgv,
            'storage_restore' => $providerArgv,
        ],
        'exclusion_provider' => $providerArgv,
        'timeout_seconds' => 5,
    ],
    'rollback_signing_key' => '/tmp/duo-envs-proof-signing.key',
    'verified_rollback' => [
        'claim_ttl_seconds' => 90,
        'encryption_key_id' => 'kms-envs-proof',
        'retention_seconds' => 3600,
    ],
];
$configuredLocal = new LocalTransport('local-recovery-proof', [
    '_machine_local' => true, 'transport' => 'local', 'wp_path' => '/wordpress', 'repo_path' => '/repo',
] + $recoveryKeys);
assert_true(
    $configuredLocal->describe()
        === 'local  wp_path=/wordpress repo_path=/repo'
        . ' rollback_key_id=envs-proof-key rollback_recovery=configured verified_rollback=configured',
    'the opted-in local describe line does not name its rollback authority the way ssh does'
);
$configuredSsh = new SshTransport('ssh-recovery-proof', [
    'transport' => 'ssh', 'host' => 'fixture.invalid', 'wp_path' => '/wordpress', 'repo_path' => '/repo',
] + $recoveryKeys);
assert_true(
    $configuredSsh->describe()
        === 'ssh    host=fixture.invalid wp_path=/wordpress repo_path=/repo'
        . ' rollback_key_id=envs-proof-key rollback_recovery=configured verified_rollback=configured',
    'the opted-in ssh describe line moved'
);
pass('duo envs is byte-identical for every environment that never opted in, and names the authority for those that did');

echo "REGRESS_ENVIRONMENT_DRIVER PASSED\n";
