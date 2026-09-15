<?php
// issue #3346: offline contract for the host-side environment-driver boundary.

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

use WPrism\Orchestrator\CodeDeploy;
use WPrism\Orchestrator\BoundedControlDriver;
use WPrism\Orchestrator\DockerTransport;
use WPrism\Orchestrator\Doctor;
use WPrism\Orchestrator\DriverCapability;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\EnvironmentCommandPreflight;
use WPrism\Orchestrator\LocalTransport;
use WPrism\Orchestrator\Refresh;
use WPrism\Orchestrator\SshTransport;
use WPrism\Orchestrator\ScopeCommand;
use WPrism\Orchestrator\Transport;

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

final class RecordingDriver implements BoundedControlDriver {
    public int $targetCalls = 0;
    public bool $frameVerified = true;
    /** @var ?array{exit:int,stdout:string,stderr:string} */
    public ?array $rawResult = null;

    public function name(): string { return 'recording'; }
    public function driverId(): string { return 'recording'; }
    public function repoPath(): string { return '/repo'; }
    public function describe(): string { return 'recording driver'; }
    public function captureRaw(string $script): array {
        $this->targetCalls++;
        return $this->rawResult
            ?? ['exit' => 97, 'stdout' => '', 'stderr' => 'unexpected target call'];
    }
    public function captureRawBounded(
        string $script,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        return $this->captureRaw($script);
    }
    public function captureRawFramed(
        string $phpTupleProgram,
        array $arguments,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        $raw = $this->captureRaw($phpTupleProgram);
        return [
            'verified' => $this->frameVerified,
            'exit' => $raw['exit'],
            'stdout' => $raw['stdout'],
            'stderr' => $raw['stderr'],
            'transport_exit' => 0,
            'transport_stderr' => '',
            'failure' => null,
        ];
    }
    public function captureWp(array $wpArgs): array {
        $this->targetCalls++;
        return ['exit' => 98, 'stdout' => '', 'stderr' => 'unexpected target call'];
    }
    public function captureWpBounded(
        array $wpArgs,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        return $this->captureWp($wpArgs);
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

/** The real frame parser with controllable outer-transport behavior. */
final class FramedTransportProbe extends Transport {
    public string $mode = 'clean';

    public function __construct() {
        parent::__construct('framed-probe', [
            'transport' => 'fixture',
            'repo_path' => '/fixture/repo',
        ]);
    }

    public function describe(): string { return 'framed transport probe'; }
    protected function wpCommand(array $wpArgs): string { return 'false'; }
    protected function rawCommand(string $script): string {
        if ($this->mode === 'outer-noise') {
            return $script
                . '; wprism_frame_status=$?; printf "compose lifecycle noise\\n" >&2; '
                . 'exit $wprism_frame_status';
        }
        if ($this->mode === 'outer-overflow') {
            return "php -r 'echo str_repeat(\"x\", 65536);'";
        }
        return $script;
    }

    public function captureRawBounded(
        string $script,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array {
        $result = parent::captureRawBounded(
            $script,
            $timeoutMilliseconds,
            $maxStdoutBytes,
            $maxStderrBytes
        );
        if ($this->mode === 'malformed') {
            $result['stdout'] = '{';
        } elseif ($this->mode === 'truncated') {
            $result['stdout'] = substr($result['stdout'], 0, 16);
        } elseif ($this->mode === 'duplicate') {
            $result['stdout'] .= $result['stdout'];
        } elseif ($this->mode === 'wrong-nonce') {
            $frame = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
            $frame['nonce'] = str_repeat('0', 32);
            $result['stdout'] = json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } elseif ($this->mode === 'outer-failure') {
            $result['exit'] = 9;
        }
        return $result;
    }
}

$local = new LocalTransport('local-proof', [
    'transport' => 'local', 'wp_path' => '/wordpress', 'repo_path' => '/repo',
]);
$docker = new DockerTransport('container-proof', [
    'transport' => 'docker', 'compose_file' => '/tmp/wprism-driver-compose.yml',
    'service' => 'cli', 'repo_path' => '/repo',
]);
// issue #3513: the opt-in `mode: "exec"` docker driver is a first-class
// EnvironmentDriver too -- it must share the exact same attach/promote/
// create capability contract as `run` mode, `local`, and `ssh`. The probe
// seam is fixed to "running" so capabilityReport() (which never touches the
// target) stays deterministic offline; regress_docker_exec_mode.php is the
// suite for the mode's own command-string and not-running behavior.
$dockerExec = new DockerTransport('container-exec-proof', [
    'transport' => 'docker', 'compose_file' => '/tmp/wprism-driver-compose.yml',
    'service' => 'cli', 'repo_path' => '/repo', 'mode' => 'exec',
], static fn(): bool => true);
$ssh = new SshTransport('ssh-proof', [
    'transport' => 'ssh', 'host' => 'fixture.invalid',
    'wp_path' => '/wordpress', 'repo_path' => '/repo',
]);
assert_true(
    str_starts_with($ssh->wpInstruction(['wprism', 'status']), 'ssh -T '),
    'SSH transport does not explicitly defeat a RequestTTY=force user configuration'
);

$expectedVocabulary = [
    'environment.attach', 'environment.bootstrap', 'environment.create', 'environment.destroy',
    'environment.ttl', 'control.wp_cli', 'control.raw', 'control.bounded', 'code.transfer', 'code.materialize',
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

$dockerCompose = json_encode([
    'services' => [
        'cli' => ['volumes' => [
            ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => false],
            ['type' => 'volume', 'source' => 'repository-data', 'target' => '/siterepo', 'read_only' => false],
        ]],
        'wordpress' => ['volumes' => [
            ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => false],
        ]],
    ],
], JSON_THROW_ON_ERROR);
$dockerControl = static function (string $command) use ($dockerCompose): array {
    return match (true) {
        str_contains($command, "'context' 'show'") => ['exit' => 0, 'stdout' => "default\n", 'stderr' => ''],
        str_contains($command, "'context' 'inspect'") => ['exit' => 0, 'stdout' => '"unix:///var/run/docker.sock"', 'stderr' => ''],
        str_contains($command, "'config' '--format' 'json'") => ['exit' => 0, 'stdout' => $dockerCompose, 'stderr' => ''],
        str_contains($command, "'ps' '--status=running' '--services'") => ['exit' => 0, 'stdout' => "wordpress\n", 'stderr' => ''],
        str_contains($command, "'ps' '-q' 'wordpress'") => ['exit' => 0, 'stdout' => str_repeat('a', 64), 'stderr' => ''],
        str_contains($command, "'config' '--hash' 'wordpress'") => ['exit' => 0, 'stdout' => 'wordpress ' . str_repeat('c', 64), 'stderr' => ''],
        str_contains($command, 'com.docker.compose.config-hash') => ['exit' => 0, 'stdout' => str_repeat('c', 64), 'stderr' => ''],
        str_contains($command, "'inspect' '--format' '{{json .Mounts}}'") => [
            'exit' => 0,
            'stdout' => '[{"Type":"volume","Name":"wordpress-data","Source":"/var/lib/docker/volumes/wordpress-data/_data","Destination":"/var/www/html","RW":true}]',
            'stderr' => '',
        ],
        default => ['exit' => 91, 'stdout' => '', 'stderr' => 'unexpected Docker control-plane command'],
    };
};
$priorDockerHost = getenv('DOCKER_HOST');
putenv('DOCKER_HOST');
$authorizedDocker = new DockerTransport('authorized-docker', [
    'transport' => 'docker',
    'compose_file' => '/tmp/wprism-driver-compose.yml',
    'service' => 'cli',
    'wordpress_service' => 'wordpress',
    'wp_path' => '/var/www/html',
    'repo_path' => '/siterepo',
    'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT],
    '_machine_local' => true,
], null, $dockerControl);
$authorizedDocker->assertLocalBootstrapControlPlane();
assert_true($authorizedDocker->capabilityReport('onboard')->ready(), 'authorized local Docker did not expose onboarding');
$dockerWpCommand = new ReflectionMethod(DockerTransport::class, 'wpCommand');
assert_true(
    str_contains((string) $dockerWpCommand->invoke($authorizedDocker, ['core', 'is-installed']), "'--no-deps' 'cli' 'wp' '--path=/var/www/html'"),
    'authorized Docker bootstrap may start an app dependency or lose its explicit WordPress root'
);
pass('authorized Docker onboarding is local, persistent, running-web-bound, and dependency-start-free');

$staleWebControl = static function (string $command) use ($dockerControl): array {
    if (str_contains($command, 'com.docker.compose.config-hash')) {
        return ['exit' => 0, 'stdout' => str_repeat('d', 64), 'stderr' => ''];
    }
    return $dockerControl($command);
};
$staleWebDocker = new DockerTransport('stale-web-docker', [
    'transport' => 'docker', 'compose_file' => '/tmp/wprism-driver-compose.yml',
    'service' => 'cli', 'wordpress_service' => 'wordpress', 'wp_path' => '/var/www/html',
    'repo_path' => '/siterepo', 'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT],
    '_machine_local' => true,
], null, $staleWebControl);
try {
    $staleWebDocker->assertLocalBootstrapControlPlane();
    fail('Docker bootstrap accepted a stale running WordPress configuration');
} catch (RuntimeException $error) {
    assert_true(str_contains($error->getMessage(), 'does not match the current Compose configuration'), 'stale web refusal lost its configuration reason');
}
pass('Docker bootstrap binds current Compose database/environment configuration to the running web container');

$dockerControlFor = static function (string $compose, array $actual): callable {
    return static function (string $command) use ($compose, $actual): array {
        return match (true) {
            str_contains($command, "'context' 'show'") => ['exit' => 0, 'stdout' => "default\n", 'stderr' => ''],
            str_contains($command, "'context' 'inspect'") => ['exit' => 0, 'stdout' => '"unix:///var/run/docker.sock"', 'stderr' => ''],
            str_contains($command, "'config' '--format' 'json'") => ['exit' => 0, 'stdout' => $compose, 'stderr' => ''],
            str_contains($command, "'ps' '--status=running' '--services'") => ['exit' => 0, 'stdout' => "wordpress\n", 'stderr' => ''],
            str_contains($command, "'ps' '-q' 'wordpress'") => ['exit' => 0, 'stdout' => str_repeat('a', 64), 'stderr' => ''],
            str_contains($command, "'config' '--hash' 'wordpress'") => ['exit' => 0, 'stdout' => 'wordpress ' . str_repeat('c', 64), 'stderr' => ''],
            str_contains($command, 'com.docker.compose.config-hash') => ['exit' => 0, 'stdout' => str_repeat('c', 64), 'stderr' => ''],
            str_contains($command, "'inspect' '--format' '{{json .Mounts}}'") => [
                'exit' => 0, 'stdout' => json_encode($actual, JSON_THROW_ON_ERROR), 'stderr' => '',
            ],
            default => ['exit' => 91, 'stdout' => '', 'stderr' => 'unexpected Docker control-plane command'],
        };
    };
};
$baseActual = [[
    'Type' => 'volume', 'Name' => 'wordpress-data',
    'Source' => '/var/lib/docker/volumes/wordpress-data/_data',
    'Destination' => '/var/www/html', 'RW' => true,
]];
$shadowCases = [
    'CLI nested override' => [
        'compose' => json_encode([
            'services' => [
                'cli' => ['volumes' => [
                    ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => false],
                    ['type' => 'volume', 'source' => 'shadow-content', 'target' => '/var/www/html/wp-content', 'read_only' => false],
                    ['type' => 'volume', 'source' => 'repository-data', 'target' => '/siterepo', 'read_only' => false],
                ]],
                'wordpress' => ['volumes' => [
                    ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => false],
                ]],
            ],
        ], JSON_THROW_ON_ERROR),
        'actual' => $baseActual,
    ],
    'WordPress read-only MU override' => [
        'compose' => json_encode([
            'services' => [
                'cli' => ['volumes' => [
                    ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => false],
                    ['type' => 'volume', 'source' => 'repository-data', 'target' => '/siterepo', 'read_only' => false],
                ]],
                'wordpress' => ['volumes' => [
                    ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => false],
                    ['type' => 'volume', 'source' => 'shadow-mu', 'target' => '/var/www/html/wp-content/mu-plugins', 'read_only' => true],
                ]],
            ],
        ], JSON_THROW_ON_ERROR),
        'actual' => $baseActual,
    ],
    'running duplicate root' => [
        'compose' => $dockerCompose,
        'actual' => array_merge($baseActual, [[
            'Type' => 'bind', 'Source' => '/different/webroot',
            'Destination' => '/var/www/html', 'RW' => true,
        ]]),
    ],
    'malformed read-only declaration' => [
        'compose' => json_encode([
            'services' => [
                'cli' => ['volumes' => [
                    ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => null],
                    ['type' => 'volume', 'source' => 'repository-data', 'target' => '/siterepo', 'read_only' => false],
                ]],
                'wordpress' => ['volumes' => [
                    ['type' => 'volume', 'source' => 'wordpress-data', 'target' => '/var/www/html', 'read_only' => false],
                ]],
            ],
        ], JSON_THROW_ON_ERROR),
        'actual' => $baseActual,
    ],
];
foreach ($shadowCases as $label => $case) {
    $shadowed = new DockerTransport('shadowed-docker', [
        'transport' => 'docker', 'compose_file' => '/tmp/wprism-driver-compose.yml',
        'service' => 'cli', 'wordpress_service' => 'wordpress', 'wp_path' => '/var/www/html',
        'repo_path' => '/siterepo', 'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT],
        '_machine_local' => true,
    ], null, $dockerControlFor($case['compose'], $case['actual']));
    try {
        $shadowed->assertLocalBootstrapControlPlane();
        fail("authorized Docker bootstrap accepted $label storage");
    } catch (RuntimeException $error) {
        assert_true(
            str_contains($error->getMessage(), 'descendant mount')
                || str_contains($error->getMessage(), 'does not expose')
                || str_contains($error->getMessage(), 'do not share'),
            "$label refusal lost its storage-boundary reason"
        );
    }
}
pass('Docker bootstrap refuses nested, read-only-shadowed, and duplicate effective WordPress mounts');

$execStorageControl = static function (string $command) use ($dockerCompose, $baseActual): array {
    return match (true) {
        str_contains($command, "'context' 'show'") => ['exit' => 0, 'stdout' => "default\n", 'stderr' => ''],
        str_contains($command, "'context' 'inspect'") => ['exit' => 0, 'stdout' => '"unix:///var/run/docker.sock"', 'stderr' => ''],
        str_contains($command, "'config' '--format' 'json'") => ['exit' => 0, 'stdout' => $dockerCompose, 'stderr' => ''],
        str_contains($command, "'ps' '--status=running' '--services'") => ['exit' => 0, 'stdout' => "wordpress\ncli\n", 'stderr' => ''],
        str_contains($command, "'ps' '-q' 'wordpress'") => ['exit' => 0, 'stdout' => str_repeat('a', 64), 'stderr' => ''],
        str_contains($command, "'config' '--hash' 'wordpress'") => ['exit' => 0, 'stdout' => 'wordpress ' . str_repeat('c', 64), 'stderr' => ''],
        str_contains($command, 'com.docker.compose.config-hash') => ['exit' => 0, 'stdout' => str_repeat('c', 64), 'stderr' => ''],
        str_contains($command, "'ps' '-q' 'cli'") => ['exit' => 0, 'stdout' => str_repeat('b', 64), 'stderr' => ''],
        str_contains($command, str_repeat('b', 64)) => [
            'exit' => 0,
            'stdout' => json_encode(array_merge($baseActual, [[
                'Type' => 'volume', 'Name' => 'stale-repository-data',
                'Source' => '/var/lib/docker/volumes/stale-repository-data/_data',
                'Destination' => '/siterepo', 'RW' => true,
            ]]), JSON_THROW_ON_ERROR),
            'stderr' => '',
        ],
        str_contains($command, str_repeat('a', 64)) => [
            'exit' => 0, 'stdout' => json_encode($baseActual, JSON_THROW_ON_ERROR), 'stderr' => '',
        ],
        default => ['exit' => 91, 'stdout' => '', 'stderr' => 'unexpected Docker control-plane command'],
    };
};
$staleExecDocker = new DockerTransport('stale-exec-docker', [
    'transport' => 'docker', 'compose_file' => '/tmp/wprism-driver-compose.yml',
    'service' => 'cli', 'wordpress_service' => 'wordpress', 'wp_path' => '/var/www/html',
    'repo_path' => '/siterepo', 'mode' => 'exec',
    'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT], '_machine_local' => true,
], static fn(): bool => true, $execStorageControl);
try {
    $staleExecDocker->assertLocalBootstrapControlPlane();
    fail('Docker exec bootstrap accepted stale running CLI storage');
} catch (RuntimeException $error) {
    assert_true(str_contains($error->getMessage(), 'running Docker CLI container storage'), 'exec storage refusal lost its running-container reason');
}
pass('Docker exec bootstrap inspects the resident CLI container instead of trusting current Compose storage');

$remoteControl = static function (string $command) use ($dockerControl): array {
    if (str_contains($command, "'context' 'inspect'")) {
        return ['exit' => 0, 'stdout' => '"ssh://remote.example.test"', 'stderr' => ''];
    }
    return $dockerControl($command);
};
$remoteDocker = new DockerTransport('remote-docker', [
    'transport' => 'docker', 'compose_file' => '/tmp/wprism-driver-compose.yml',
    'service' => 'cli', 'wordpress_service' => 'wordpress', 'wp_path' => '/var/www/html',
    'repo_path' => '/siterepo', 'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT],
    '_machine_local' => true,
], null, $remoteControl);
try {
    $remoteDocker->assertLocalBootstrapControlPlane();
    fail('authorized Docker bootstrap accepted a remote daemon context');
} catch (RuntimeException $error) {
    assert_true(str_contains($error->getMessage(), 'remote Docker contexts'), 'remote-daemon refusal lost its authority reason');
}
pass('remote Docker shell access never becomes local bootstrap authority');
is_string($priorDockerHost) ? putenv('DOCKER_HOST=' . $priorDockerHost) : putenv('DOCKER_HOST');

$rawOnly = [
    DriverCapability::ATTACH => true,
    DriverCapability::BOUNDED_CONTROL => true,
    DriverCapability::RAW_CONTROL => true,
];
$releaseStatus = DriverCapabilityReport::forDriver(
    'raw-only',
    'raw-only',
    'release-status',
    $rawOnly
);
$releasePrepare = DriverCapabilityReport::forDriver(
    'raw-only',
    'raw-only',
    'release-prepare',
    $rawOnly
);
assert_true($releaseStatus->ready(), 'release status incorrectly requires a reachable WordPress control path');
assert_true(!$releasePrepare->ready(), 'release prepare no longer requires its WordPress planning surface');
assert_true(
    required_capabilities($releaseStatus) === ['control.bounded', 'control.raw', 'environment.attach'],
    'release status does not demand exactly attach plus framed raw target control'
);
pass('release status remains available over framed raw control while release prepare still requires WordPress');

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

$frameProgram = <<<'PHP'
return ['exit' => 0, 'stderr' => '', 'stdout' => 'clear'];
PHP;
$frameProbe = new FramedTransportProbe();
$frameProbe->mode = 'outer-noise';
$framed = $frameProbe->captureRawFramed($frameProgram, [], 30000, 32, 128);
assert_true(
    $framed['verified'] === true
        && $framed['exit'] === 0
        && $framed['stdout'] === 'clear'
        && $framed['stderr'] === ''
        && $framed['transport_exit'] === 0
        && $framed['transport_stderr'] === "compose lifecycle noise\n",
    'framed raw control did not separate exact target bytes from successful outer transport diagnostics'
);

foreach (['malformed', 'truncated', 'duplicate', 'wrong-nonce', 'outer-failure', 'outer-overflow'] as $mode) {
    $frameProbe->mode = $mode;
    $rejected = $frameProbe->captureRawFramed($frameProgram, [], 30000, 32, 128);
    assert_true(
        $rejected['verified'] === false
            && $rejected['exit'] === 255
            && in_array($rejected['failure'], ['invalid_frame', 'outer_failure'], true),
        "$mode framed raw control response was not rejected"
    );
}
$frameProbe->mode = 'clean';
$diagnostic = $frameProbe->captureRawFramed(
    'trigger_error("target warning", E_USER_WARNING); ' . $frameProgram,
    [],
    30000,
    32,
    128
);
assert_true(
    $diagnostic['verified'] === true
        && $diagnostic['exit'] === 255
        && $diagnostic['stdout'] === ''
        && $diagnostic['stderr'] === 'framed raw control program failed'
        && $diagnostic['transport_stderr'] === '',
    'a target PHP diagnostic escaped into the accepted outer transport channel'
);
$directStderr = $frameProbe->captureRawFramed(
    'fwrite(STDERR, "target stderr\\n"); ' . $frameProgram,
    [],
    30000,
    32,
    128
);
assert_true(
    $directStderr['verified'] === false
        && $directStderr['exit'] === 255
        && $directStderr['transport_stderr'] === ''
        && $directStderr['failure'] === 'invalid_frame',
    'target-authored stderr was mistaken for ignorable outer transport diagnostics'
);

foreach ([
    'argument count' => array_fill(0, 257, 'x'),
    'aggregate argument bytes' => [str_repeat('x', 32769), str_repeat('y', 32768)],
] as $label => $arguments) {
    try {
        $frameProbe->captureRawFramed($frameProgram, $arguments, 30000, 32, 128);
        fail("framed raw control accepted excessive $label");
    } catch (InvalidArgumentException $failure) {
        assert_true(
            str_contains($failure->getMessage(), 'reviewed envelope'),
            "framed raw control returned the wrong excessive-$label refusal"
        );
    }
}

$frameRepo = sys_get_temp_dir() . '/wprism-framed-fence-' . bin2hex(random_bytes(8));
if (!mkdir($frameRepo, 0700, true)) {
    fail('could not create framed recovery-fence fixture');
}
$frameProbe->mode = 'outer-noise';
$clearFence = CodeDeploy::externalRecoveryFence($frameProbe, $frameRepo);
assert_true(($clearFence['state'] ?? null) === 'clear', 'outer transport noise changed an exact clear fence');
if (!mkdir($frameRepo . '/.wprism/control', 0700, true)) {
    fail('could not create framed recovery control fixture');
}
file_put_contents($frameRepo . '/.wprism/control/checkpoint-recovery-intent.json', '{}');
$checkpointFence = CodeDeploy::externalRecoveryFence($frameProbe, $frameRepo);
assert_true(
    ($checkpointFence['state'] ?? null) === 'checkpoint_recovery',
    'outer transport noise changed an exact checkpoint-recovery fence'
);
unlink($frameRepo . '/.wprism/control/checkpoint-recovery-intent.json');
file_put_contents($frameRepo . '/.wprism/control/provider-settlement-intent.json', '{}');
$providerFence = CodeDeploy::externalRecoveryFence($frameProbe, $frameRepo);
assert_true(
    ($providerFence['state'] ?? null) === 'provider_settlement',
    'outer transport noise changed an exact provider-settlement fence'
);
unlink($frameRepo . '/.wprism/control/provider-settlement-intent.json');
rmdir($frameRepo . '/.wprism/control');
rmdir($frameRepo . '/.wprism');
rmdir($frameRepo);
pass('nonce-bound bounded frames separate outer diagnostics and reject malformed, duplicated, truncated, failed, noisy-target, and oversized results');

foreach ([
    'clear' => [['exit' => 0, 'stdout' => 'clear', 'stderr' => ''], 'clear'],
    'checkpoint' => [[
        'exit' => 75,
        'stdout' => '',
        'stderr' => 'incomplete checkpoint recovery is active',
    ], 'checkpoint_recovery'],
    'provider' => [[
        'exit' => 75,
        'stdout' => '',
        'stderr' => 'incomplete provider settlement is active',
    ], 'provider_settlement'],
    'extra stdout' => [[
        'exit' => 75,
        'stdout' => "unexpected\n",
        'stderr' => 'incomplete provider settlement is active',
    ], 'unsafe'],
    'trailing newline' => [[
        'exit' => 0,
        'stdout' => "clear\n",
        'stderr' => '',
    ], 'unsafe'],
    'unknown exit' => [[
        'exit' => 76,
        'stdout' => '',
        'stderr' => 'incomplete checkpoint recovery is active',
    ], 'unsafe'],
] as $label => [$raw, $expectedState]) {
    $fenceDriver = new RecordingDriver();
    $fenceDriver->rawResult = $raw;
    $fence = CodeDeploy::externalRecoveryFence($fenceDriver, '/fixture/repo');
    assert_true(
        ($fence['state'] ?? null) === $expectedState && $fenceDriver->targetCalls === 1,
        "$label external-recovery tuple did not map through the closed fence vocabulary"
    );
}
$unverifiedDriver = new RecordingDriver();
$unverifiedDriver->rawResult = ['exit' => 0, 'stdout' => 'clear', 'stderr' => ''];
$unverifiedDriver->frameVerified = false;
assert_true(
    CodeDeploy::externalRecoveryFence($unverifiedDriver, '/fixture/repo')['state'] === 'unsafe',
    'an exact inner tuple without a verified frame was admitted'
);
pass('external recovery classification admits only exact clear/checkpoint/provider tuples');

/** @return array{exit:int,stdout:string,stderr:string} */
function invoke_cli(array $args): array {
    $command = array_merge([PHP_BINARY, __DIR__ . '/../../../../cli/wprism'], $args);
    $proc = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        fail('could not start public wprism CLI');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

$tmp = sys_get_temp_dir() . '/wprism-driver-contract-' . bin2hex(random_bytes(8));
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

// The recovery fence owns a real adopted-target filesystem boundary even in
// this transport-only fixture. Create it only after the denied-adopt proof so
// the later successful forwarding cases exercise a truthful clear fence.
mkdir($tmp . '/repo', 0700, true);

// issue #3344 contract evidence is only truthful when the host path cannot boot
// arbitrary plugins/themes/ordinary MU code before the agent compiles its
// target-independent revision. Exercise the real public CLI with a fake wp
// binary and capture every forwarded argument; this is stronger than a source
// grep because a future dispatch refactor must still pass the actual control
// arguments through Transport::streamWp().
$fakeBin = $tmp . '/scope-bin';
mkdir($fakeBin, 0700, true);
$scopeArgs = $tmp . '/scope-args.txt';
$fakeWp = $fakeBin . '/wp';
$captureSummary = json_encode([
    'counts' => ['post' => 0],
    'media' => 0,
    'notes' => [],
    'warnings' => [],
    'revision_hash' => str_repeat('a', 64),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(
    $fakeWp,
    '#!/usr/bin/env bash' . "\n"
        . 'printf \'%s\\n\' "$@" > "$WPRISM_SCOPE_ARGS"' . "\n"
        . 'capture=0' . "\n"
        . 'for arg in "$@"; do' . "\n"
        . '  if [ "$arg" = lint ]; then echo LINT_STREAM_MARKER; exit 23; fi' . "\n"
        . '  if [ "$arg" = capture ]; then capture=1; fi' . "\n"
        . 'done' . "\n"
        . 'if [ "$capture" = 1 ]; then printf \'%s\\n\' ' . escapeshellarg($captureSummary) . '; fi' . "\n"
);
chmod($fakeWp, 0700);
$oldPath = getenv('PATH') ?: '';
putenv('PATH=' . $fakeBin . ':' . $oldPath);
putenv('WPRISM_SCOPE_ARGS=' . $scopeArgs);
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
        && in_array('wprism', $forwarded, true)
        && in_array('scope', $forwarded, true)
        && in_array('--repo=' . $tmp . '/repo', $forwarded, true)
        && in_array('--roots=all', $forwarded, true)
        && in_array('--contract', $forwarded, true),
    'scope transport forwards control-plane --exec/skip flags and contract roots verbatim'
);
$captureForward = invoke_cli([
    '--envs-file=' . $envsFile, 'capture', 'local-proof', '--format=json',
]);
assert_true(
    $captureForward['exit'] === 0,
    'public capture forwarding returned ' . $captureForward['exit']
        . '; stdout=' . trim($captureForward['stdout'])
        . '; stderr=' . trim($captureForward['stderr'])
);
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
putenv('WPRISM_SCOPE_ARGS');
assert_true($lintForward['exit'] === 23, 'public lint did not preserve the agent failure exit: ' . $lintForward['stderr']);
assert_true(
    str_contains($lintForward['stdout'], 'LINT_STREAM_MARKER'),
    'public lint did not stream the agent output marker'
);
$lintArgs = is_file($scopeArgs) ? file($scopeArgs, FILE_IGNORE_NEW_LINES) : false;
assert_true(
    is_array($lintArgs)
        && in_array('wprism', $lintArgs, true)
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
$controlArgs = CodeDeploy::controlArgs(['wprism', 'scope']);
assert_true(
    str_contains($controlArgs[0], 'WPRISM_CONTROL_PLANE')
        && str_contains($controlArgs[0], 'WPMU_PLUGIN_DIR'),
    'scope control bootstrap isolates ordinary MU-plugin loading before the agent runs'
);
unlink($scopeArgs);
unlink($fakeWp);
rmdir($fakeBin);
unlink($envsFile);
rmdir($tmp . '/repo');
rmdir($tmp);
pass('public JSON/human paths share the report and refuse before target mutation');

// issue #3344: every verb that reaches the driver preflight must be known to
// DriverCapabilityReport::requirements(). Registering a verb in cli/wprism's
// dispatch and usage while forgetting this third table produces a command
// that parses, documents, and routes correctly and then dies in the
// preflight on EVERY transport before any work — which is exactly how
// `wprism scope` shipped broken. The verb list and the exemptions are both
// read out of cli/wprism rather than restated here, so this cannot pass by
// being updated in lockstep with the bug.
$wprismSource = file_get_contents(__DIR__ . '/../../../../cli/wprism');
assert_true(is_string($wprismSource) && $wprismSource !== '', 'could not read cli/wprism');
assert_true(
    str_contains($wprismSource, '`wprism env-set <env>')
        && str_contains($wprismSource, '[A-Za-z0-9][A-Za-z0-9._-]{0,63}'),
    'public help keeps the complete host env-set command and environment-name grammar'
);

// The vocabulary is a runtime collaborator, not a copied source anchor. This
// keeps the contract tied to the implementation that dispatches commands.
$verbsNeedingEnv = EnvironmentCommandPreflight::environmentVerbs();
assert_true(count($verbsNeedingEnv) >= 15, 'environment preflight vocabulary is implausibly short');
assert_true(in_array('scope', $verbsNeedingEnv, true), 'scope is not registered in $verbsNeedingEnv');
assert_true(
    str_contains($wprismSource, "'scope' => cmd_scope(\$transport, \$extra)")
        && str_contains($wprismSource, 'function cmd_scope(')
        && str_contains($wprismSource, 'ScopeCommand::run($t, $extra)'),
    'scope dispatch is not registered through the extracted isolated control-plane handler'
);
assert_true(
    (new ReflectionMethod(ScopeCommand::class, 'run'))->isStatic(),
    'scope command handler does not expose its standalone static boundary'
);
assert_true(in_array('explain', $verbsNeedingEnv, true), 'explain is not registered in $verbsNeedingEnv');
assert_true(
    str_contains($wprismSource, 'EnvironmentCommandPreflight::requiresEnvironment('),
    'cli/wprism does not ask the preflight collaborator whether a command needs an environment'
);

// A verb may legitimately never reach the common preflight if main() returns
// for it first (driver-capabilities renders the report itself). Keep that
// single, explicit exception in the behavioral check.
assert_true(
    str_contains($wprismSource, 'EnvironmentCommandPreflight::capabilityReport('),
    'cli/wprism does not route driver capability checks through the preflight collaborator'
);
assert_true(
    str_contains($wprismSource, "(\$extra[0] ?? null) === 'status' => 'release-status'")
        && str_contains($wprismSource, "(\$extra[0] ?? null) === 'prepare' => 'release-prepare'"),
    'public release dispatch does not keep status raw-only and prepare WP-aware'
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
        $report = $local->capabilityReport($verb);
        $expectedRecoveryControl = !in_array($verb, ['doctor', 'status'], true);
        assert_true(
            $report->requiresRecoveryControl() === $expectedRecoveryControl,
            "$verb recovery-fence capability predicate drifted from public dispatch"
        );
        $required = required_capabilities($report);
        if ($expectedRecoveryControl) {
            assert_true(
                in_array(DriverCapability::RAW_CONTROL, $required, true)
                    && in_array(DriverCapability::BOUNDED_CONTROL, $required, true),
                "$verb can cross the recovery fence without framed raw control"
            );
        }
    } catch (\Throwable $t) {
        $unknown[] = $verb . ' (' . $t->getMessage() . ')';
    }
}
assert_true($reachedPreflight >= 14, 'derived exemptions swallowed nearly every verb — the check would prove nothing');
assert_true(
    $unknown === [],
    'these cli/wprism verbs reach the driver preflight but are unknown to requirements(): ' . implode('; ', $unknown)
);
assert_true(
    $requirements->invoke(null, 'scope') === $requirements->invoke(null, 'coverage'),
    'scope must demand exactly what the other read-only passthrough demands'
);
assert_true(
    $requirements->invoke(null, 'explain') === $requirements->invoke(null, 'plan'),
    'explain must demand exactly the same workflow and recovery control as plan'
);
assert_true(
    $requirements->invoke(null, 'lint') === $requirements->invoke(null, 'coverage'),
    'lint must demand exactly the same workflow and recovery control as other read-only scans'
);
pass('every cli/wprism verb reaching the driver preflight resolves through requirements()');

// WPRISM: `wprism envs` output is what an operator diffs when they wonder whether a
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
    $docker->describe() === 'docker compose_file=/tmp/wprism-driver-compose.yml service=cli repo_path=/repo',
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
    'rollback_signing_key' => '/tmp/wprism-envs-proof-signing.key',
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
pass('wprism envs is byte-identical for every environment that never opted in, and names the authority for those that did');

// Transport::runCapturing() must drain stdout and stderr concurrently. The
// sequential form (stream_get_contents(stdout) then stderr) deadlocked the
// moment a child filled the ~64KB stderr pipe buffer before closing stdout —
// measured 2026-08-24: the SSH rollback certification's adopt install script
// hung exactly there on two consecutive runs (idle sshd-session on the
// target, live mux client on the host, zero remote processes), and a
// 200KB-stderr child reproduces it in isolation. The capture runs in a child
// PHP process under a watchdog so a regression fails as a named timeout
// instead of hanging the offline corpus.
$captureProbe = <<<'PHP'
require $argv[1] . '/cli/src/Transport/EnvironmentDriver.php';
require $argv[1] . '/cli/src/Transport/Transport.php';
require $argv[1] . '/cli/src/Transport/LocalTransport.php';
$t = new WPrism\Orchestrator\LocalTransport('pipe-proof', [
    'transport' => 'local', 'wp_path' => '/wordpress', 'repo_path' => '/repo',
]);
$r = $t->captureRaw(
    'php -r ' . escapeshellarg(
        'fwrite(STDERR, str_repeat("e", 200000));'
        . ' fwrite(STDOUT, str_repeat("o", 200000));'
        . ' fwrite(STDERR, "!"); exit(7);'
    )
);
echo $r['exit'], ' ', strlen($r['stdout']), ' ', strlen($r['stderr']), "\n";
PHP;
$probeProc = proc_open(
    escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($captureProbe) . ' ' . escapeshellarg(dirname(__DIR__, 4)),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $probePipes
);
if (!is_resource($probeProc)) {
    fail('could not start the pipe-drain probe');
}
fclose($probePipes[0]);
stream_set_blocking($probePipes[1], false);
stream_set_blocking($probePipes[2], false);
$probeOut = '';
$deadline = microtime(true) + 20.0;
while (true) {
    $status = proc_get_status($probeProc);
    $probeOut .= (string) stream_get_contents($probePipes[1]);
    if (!$status['running']) {
        break;
    }
    if (microtime(true) > $deadline) {
        proc_terminate($probeProc, 9);
        fail('captureRaw deadlocked on a 200KB-stderr child: sequential pipe reads are back');
    }
    usleep(50000);
}
$probeOut .= (string) stream_get_contents($probePipes[1]);
fclose($probePipes[1]);
fclose($probePipes[2]);
proc_close($probeProc);
if (trim($probeOut) !== '7 200000 200001') {
    fail("captureRaw lost bytes or the exit code on a chatty child: got '" . trim($probeOut) . "', want '7 200000 200001'");
}
pass('captureRaw drains interleaved 200KB stdout/stderr without deadlock and loses neither bytes nor the exit code');

echo "REGRESS_ENVIRONMENT_DRIVER PASSED\n";
