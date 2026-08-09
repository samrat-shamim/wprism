<?php
// DUO-3346: offline contract for the host-side environment-driver boundary.

declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/EnvironmentDriver.php';
require_once __DIR__ . '/../../cli/src/Transport.php';
require_once __DIR__ . '/../../cli/src/LocalTransport.php';
require_once __DIR__ . '/../../cli/src/DockerTransport.php';
require_once __DIR__ . '/../../cli/src/SshTransport.php';
require_once __DIR__ . '/../../cli/src/Doctor.php';
require_once __DIR__ . '/../../cli/src/CodeDeploy.php';
require_once __DIR__ . '/../../cli/src/Refresh.php';

use Duo\Orchestrator\CodeDeploy;
use Duo\Orchestrator\DockerTransport;
use Duo\Orchestrator\Doctor;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\LocalTransport;
use Duo\Orchestrator\Refresh;
use Duo\Orchestrator\SshTransport;

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
$ssh = new SshTransport('ssh-proof', [
    'transport' => 'ssh', 'host' => 'fixture.invalid',
    'wp_path' => '/wordpress', 'repo_path' => '/repo',
]);

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
foreach ([$local, $docker, $ssh] as $driver) {
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
pass('local, container, and SSH drivers share one proof contract while attach remains distinct from create');

assert_true(!$local->capabilityReport('adopt')->ready(), 'local driver fabricated bootstrap support');
assert_true(!$docker->capabilityReport('adopt')->ready(), 'container driver fabricated bootstrap support');
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
    $command = array_merge([PHP_BINARY, __DIR__ . '/../../cli/duo'], $args);
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

$deniedCli = invoke_cli(['--envs-file=' . $envsFile, 'adopt', 'local-proof']);
assert_true($deniedCli['exit'] !== 0, 'local adopt silently emulated SSH bootstrap');
assert_true(
    str_contains($deniedCli['stderr'], "driver preflight blocked 'adopt'")
        && str_contains($deniedCli['stderr'], DriverCapability::BOOTSTRAP),
    'public refusal did not name the exact missing bootstrap capability'
);
assert_true(!file_exists($tmp . '/repo'), 'denied public workflow mutated its target path');
unlink($envsFile);
rmdir($tmp);
pass('public JSON/human paths share the report and refuse before target mutation');

echo "REGRESS_ENVIRONMENT_DRIVER PASSED\n";
