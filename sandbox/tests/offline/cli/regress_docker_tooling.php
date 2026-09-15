<?php
/** Offline product-path proof for explicit managed Docker Compose tooling. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/DockerTooling.php';

use WPrism\Orchestrator\DockerTooling;

$tmp = sys_get_temp_dir() . '/wprism-docker-tooling-' . bin2hex(random_bytes(8));
$project = $tmp . '/project with spaces';
mkdir($project, 0700, true);
$compose = $project . '/compose.yml';
$envFile = $project . '/compose env';
file_put_contents($compose, "services:\n  wordpress:\n    image: wordpress\n");
file_put_contents($envFile, "DB_PASSWORD=not-for-the-overlay\n");
$priorDockerHost = getenv('DOCKER_HOST');
putenv('DOCKER_HOST');

/** @param list<array{0:list<string>,1:?string,2:int}> $calls */
$runner = static function (
    array &$calls,
    bool $staleHash = false,
    bool $overlayFailure = false,
    bool $rollbackData = false,
    bool $nestedWebroot = false,
    bool $malformedReadOnly = false,
    string $dockerContext = 'orbstack',
    string $composeProject = 'fixture_project'
) use ($compose): callable {
    $dockerfile = dirname(__DIR__, 4) . '/cli/resources/docker-tooling/Dockerfile';
    $toolchainHash = (string) hash_file('sha256', $dockerfile);
    $imageId = 'sha256:' . str_repeat('a', 64);
    $configHash = str_repeat('b', 64);
    $environmentIdentity = hash(
        'sha256',
        'wprism-managed-docker-tooling/v1' . "\0local-site\0" . $dockerContext
            . "\0unix:///var/run/docker.sock\0" . $composeProject . "\0"
            . (string) realpath($compose) . "\0wordpress"
    );
    $managedService = 'wprism-managed-' . substr($environmentIdentity, 0, 16);
    $volumeCreated = false;
    $wordpressVolumes = [[
        'type' => 'volume',
        'source' => 'wordpress_data',
        'target' => '/var/www/html',
    ]];
    if ($nestedWebroot) {
        $wordpressVolumes[] = [
            'type' => 'volume',
            'source' => 'wordpress_content',
            'target' => '/var/www/html/wp-content',
            'read_only' => true,
        ];
    }
    if ($malformedReadOnly) {
        $wordpressVolumes[0]['read_only'] = null;
    }
    $config = json_encode([
        'services' => [
            'wordpress' => [
                'image' => 'wordpress:php8.3-apache',
                'environment' => ['DB_PASSWORD' => 'not-for-the-overlay'],
                'volumes' => $wordpressVolumes,
            ],
        ],
        'volumes' => [
            'wordpress_data' => ['name' => 'fixture_wordpress_data'],
            'wordpress_content' => ['name' => 'fixture_wordpress_content'],
        ],
    ], JSON_THROW_ON_ERROR);
    $mounts = json_encode([[
        'Type' => 'volume',
        'Name' => 'fixture_wordpress_data',
        'Source' => '/var/lib/docker/volumes/fixture_wordpress_data/_data',
        'Destination' => '/var/www/html',
        'RW' => true,
    ]], JSON_THROW_ON_ERROR);
    $imageInspection = json_encode($imageId, JSON_THROW_ON_ERROR) . '|' . json_encode([
        'io.wprism.managed' => 'docker-tooling',
        'io.wprism.identity' => $toolchainHash,
    ], JSON_THROW_ON_ERROR);

    return static function (array $argv, ?string $cwd, int $timeout) use (
        &$calls,
        $config,
        $mounts,
        $configHash,
        $staleHash,
        $overlayFailure,
        $rollbackData,
        $imageInspection,
        $managedService,
        $environmentIdentity,
        $dockerContext,
        $composeProject,
        &$volumeCreated
    ): array {
        $calls[] = [$argv, $cwd, $timeout];
        $joined = implode("\0", $argv);
        $ok = static fn(string $stdout = ''): array => ['exit' => 0, 'stdout' => $stdout, 'stderr' => ''];
        if ($argv === ['docker', 'context', 'show']) {
            return $ok($dockerContext . "\n");
        }
        if (str_contains($joined, "context\0inspect")) {
            return $ok(json_encode('unix:///var/run/docker.sock', JSON_THROW_ON_ERROR));
        }
        if (str_contains($joined, "config\0--format\0json")) {
            return $ok($config);
        }
        if (str_contains($joined, "ps\0--status\0running\0-q\0wordpress")) {
            return $ok(str_repeat('1', 64) . "\n");
        }
        if (str_contains($joined, "config\0--hash\0wordpress")) {
            return $ok('wordpress ' . $configHash . "\n");
        }
        if (str_contains($joined, 'com.docker.compose.project')) {
            return $ok($composeProject . "\n");
        }
        if (str_contains($joined, 'com.docker.compose.config-hash')) {
            return $ok(($staleHash ? str_repeat('c', 64) : $configHash) . "\n");
        }
        if (str_contains($joined, '{{json .Mounts}}')) {
            return $ok($mounts);
        }
        if (str_contains($joined, "\0id\0-u\0www-data")) {
            return $ok("33\n");
        }
        if (str_contains($joined, "\0id\0-g\0www-data")) {
            return $ok("33\n");
        }
        if (str_contains($joined, "image\0inspect")) {
            return $ok($imageInspection);
        }
        if (str_contains($joined, "--mount\0type=volume,src=") && str_contains($joined, 'dst=/wprism-check,readonly')) {
            return $rollbackData
                ? ['exit' => 1, 'stdout' => '', 'stderr' => 'not empty']
                : $ok('');
        }
        if (str_contains($joined, "run\0--rm\0--entrypoint\0sh")) {
            return $ok('');
        }
        if (str_contains($joined, "volume\0inspect")) {
            if ($volumeCreated) {
                return $ok(json_encode([
                    'io.wprism.managed' => 'docker-tooling-repository',
                    'io.wprism.identity' => $environmentIdentity,
                ], JSON_THROW_ON_ERROR));
            }
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'not found'];
        }
        if (str_contains($joined, "volume\0create")) {
            $volumeCreated = true;
            return $ok((string) end($argv) . "\n");
        }
        if (str_contains($joined, "ps\0-aq\0--filter\0volume=")) {
            return $ok('');
        }
        if (str_contains($joined, "config\0--services")) {
            return $overlayFailure
                ? ['exit' => 1, 'stdout' => '', 'stderr' => 'DB_PASSWORD=not-for-output']
                : $ok("wordpress\n$managedService\n");
        }
        if (str_contains($joined, "run\0--rm\0-T\0--no-deps")) {
            return $ok('');
        }
        if (str_contains($joined, "\0create\0--name\0wprism-managed-lease-")) {
            return $ok(str_repeat('2', 64) . "\n");
        }
        if (str_contains($joined, "container\0inspect\0--format\0{{json .Config.Labels}}\0wprism-managed-lease-")) {
            return $ok(json_encode([
                'io.wprism.managed' => 'docker-tooling-lease',
                'io.wprism.identity' => $environmentIdentity,
            ], JSON_THROW_ON_ERROR));
        }
        if (in_array('rm', $argv, true)) {
            return $ok('');
        }
        return ['exit' => 98, 'stdout' => '', 'stderr' => 'unexpected fixture command'];
    };
};

/** @param list<array{0:list<string>,1:?string,2:int}> $calls */
$hasPrefix = static function (array $calls, array $prefix): bool {
    foreach ($calls as [$command]) {
        if (($command[1] ?? null) === '--context') {
            array_splice($command, 1, 2);
        }
        if (array_slice($command, 0, count($prefix)) === $prefix) {
            return true;
        }
    }
    return false;
};

/** @param list<array{0:list<string>,1:?string,2:int}> $calls */
$hasSequence = static function (array $calls, array $sequence): bool {
    foreach ($calls as [$command]) {
        for ($offset = 0; $offset <= count($command) - count($sequence); ++$offset) {
            if (array_slice($command, $offset, count($sequence)) === $sequence) {
                return true;
            }
        }
    }
    return false;
};

$calls = [];
$fixture = $runner($calls);
$receipt = DockerTooling::prepare(
    'local-site',
    $compose,
    $envFile,
    'development',
    'wordpress',
    $fixture,
    $tmp . '/private state'
);
wprism_check_same('wordpress', $receipt['wordpress_service'], 'preparation remains bound to the selected WordPress service');
wprism_check_same('/var/www/html', $receipt['wp_path'], 'the standard official WordPress webroot is explicit');
wprism_check_same('/wprism-repository/site', $receipt['repo_path'], 'repository path is an absent child, not the volume root');
wprism_check(
    is_file($receipt['compose_overlay_file']),
    'normalized Compose storage with omitted read_only is treated as writable while explicit true still refuses'
);
wprism_check_same('fixture_project', $receipt['compose_project'], 'the running container binds the exact Compose project identity');
wprism_check((bool) preg_match('/^sha256:[a-f0-9]{64}$/D', $receipt['image_id']), 'the receipt returns an immutable built image ID');
wprism_check_same(0600, fileperms($receipt['compose_overlay_file']) & 0777, 'private overlay is mode 0600');
$overlayBytes = (string) file_get_contents($receipt['compose_overlay_file']);
$overlay = json_decode($overlayBytes, true, flags: JSON_THROW_ON_ERROR);
$managed = $overlay['services'][$receipt['service']];
wprism_check_same(realpath($compose), $managed['extends']['file'], 'overlay extends the exact application Compose file');
wprism_check_same([], $managed['entrypoint'], 'application entrypoint is explicitly suppressed');
wprism_check_same('33:33', $managed['user'], 'helper uses the running target www-data identity');
wprism_check_same($receipt['image_id'], $managed['image'], 'overlay consumes the immutable image ID, not its mutable build tag');
wprism_check(!str_contains($overlayBytes, 'DB_PASSWORD') && !str_contains($overlayBytes, 'not-for-the-overlay'), 'resolved application secrets never enter the overlay');
wprism_check($hasSequence($calls, ['run', '--rm', '-T', '--no-deps', '--user', '0:0']), 'owned volume initialization is isolated with --no-deps and no application entrypoint');
$applicationMutation = false;
foreach ($calls as [$command]) {
    $applicationMutation = $applicationMutation
        || in_array('up', $command, true)
        || in_array('down', $command, true)
        || in_array('restart', $command, true);
}
wprism_check(!$applicationMutation, 'preparation never starts, stops or recreates application services');
$unbound = array_values(array_filter(array_slice($calls, 2), static fn(array $call): bool =>
    ($call[0][1] ?? null) !== '--context' || ($call[0][2] ?? null) !== 'orbstack'
));
wprism_check_same([], $unbound, 'every Docker action after discovery is pinned to the selected context');
wprism_check($hasSequence($calls, ['compose', '--project-name', 'fixture_project']), 'all post-discovery Compose actions pin the running project name');
DockerTooling::release($receipt, $fixture);
wprism_check($hasPrefix($calls, ['docker', 'container', 'rm']), 'owned stopped lease is removed after the operation');
DockerTooling::rollback($receipt, $fixture);
wprism_check($hasPrefix($calls, ['docker', 'volume', 'rm']), 'a later connection-publication failure can roll back its newly created repository volume');
wprism_check(!is_file($receipt['compose_overlay_file']), 'connection rollback removes its byte- and mode-verified new overlay');
wprism_check(!$hasPrefix($calls, ['docker', 'image', 'rm']), 'connection rollback preserves the helper image when preparation reused it');

$otherProjectCalls = [];
$otherProjectFixture = $runner($otherProjectCalls, composeProject: 'fixture_other');
$otherProject = DockerTooling::prepare(
    'local-site', $compose, $envFile, 'development', 'wordpress',
    $otherProjectFixture, $tmp . '/private state'
);
wprism_check(
    $otherProject['repository_volume'] !== $receipt['repository_volume'],
    'a different running Compose project cannot reuse another site repository volume'
);
DockerTooling::rollback($otherProject, $otherProjectFixture);

$otherContextCalls = [];
$otherContextFixture = $runner($otherContextCalls, dockerContext: 'desktop-linux');
$otherContext = DockerTooling::prepare(
    'local-site', $compose, $envFile, 'development', 'wordpress',
    $otherContextFixture, $tmp . '/private state'
);
wprism_check(
    $otherContext['repository_volume'] !== $receipt['repository_volume'],
    'a different local Docker context cannot reuse another daemon repository identity'
);
DockerTooling::rollback($otherContext, $otherContextFixture);

$dataCalls = [];
$dataFixture = $runner($dataCalls, rollbackData: true);
$dataReceipt = DockerTooling::prepare(
    'local-site',
    $compose,
    $envFile,
    'development',
    'wordpress',
    $dataFixture,
    $tmp . '/private state'
);
try {
    DockerTooling::rollback($dataReceipt, $dataFixture);
    throw new RuntimeException('managed tooling deleted a repository volume after target data appeared');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'target data appeared'),
        'post-prepare target data retains the managed repository volume with an explicit diagnostic'
    );
}
wprism_check(!$hasPrefix($dataCalls, ['docker', 'volume', 'rm']), 'rollback never deletes a managed repository volume containing target data');

$nestedCalls = [];
try {
    DockerTooling::prepare(
        'local-site',
        $compose,
        $envFile,
        'development',
        'wordpress',
        $runner($nestedCalls, nestedWebroot: true),
        $tmp . '/private state'
    );
    throw new RuntimeException('managed tooling accepted a read-only nested wp-content mount');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'MU control path is not on writable persistent storage'),
        'managed tooling refuses nested webroot mounts before inheriting a shadowed control-plane path'
    );
}
wprism_check(
    !$hasSequence($nestedCalls, ['volume', 'create']),
    'nested webroot storage refuses before managed repository provisioning'
);

$malformedStorageCalls = [];
try {
    DockerTooling::prepare(
        'local-site',
        $compose,
        $envFile,
        'development',
        'wordpress',
        $runner($malformedStorageCalls, malformedReadOnly: true),
        $tmp . '/private state'
    );
    throw new RuntimeException('managed tooling accepted a null read_only value');
} catch (RuntimeException $error) {
    wprism_check(
        str_contains($error->getMessage(), 'no unambiguous writable persistent webroot'),
        'managed tooling treats only omitted or boolean-false read_only as writable'
    );
}
wprism_check(
    !$hasSequence($malformedStorageCalls, ['volume', 'create']),
    'malformed read_only storage refuses before managed repository provisioning'
);

$staleCalls = [];
$staleMessage = '';
try {
    DockerTooling::prepare('local-site', $compose, null, null, 'wordpress', $runner($staleCalls, true), $tmp . '/stale');
} catch (RuntimeException $error) {
    $staleMessage = $error->getMessage();
}
wprism_check(str_contains($staleMessage, 'does not match the current Compose configuration'), 'stale env/network configuration refuses against the running container hash');
wprism_check(!$hasPrefix($staleCalls, ['docker', 'image']) && !$hasPrefix($staleCalls, ['docker', 'volume']), 'stale runtime refusal occurs before provisioning');

$failureCalls = [];
$failureMessage = '';
try {
    DockerTooling::prepare('local-site', $compose, null, null, 'wordpress', $runner($failureCalls, false, true), $tmp . '/failure');
} catch (RuntimeException $error) {
    $failureMessage = $error->getMessage();
}
wprism_check(str_contains($failureMessage, 'overlay validation failed'), 'invalid generated overlay fails closed without replaying secret diagnostics');
wprism_check($hasPrefix($failureCalls, ['docker', 'volume', 'rm']), 'failed preparation removes the volume it created');
wprism_check(!$hasPrefix($failureCalls, ['docker', 'image', 'rm']), 'failed preparation preserves a pre-existing owned image');
wprism_check_same([], glob($tmp . '/failure/*/compose.json') ?: [], 'failed preparation removes only its newly published overlay');

putenv('DOCKER_HOST=tcp://builder.example:2376');
$remoteCalled = false;
$remoteMessage = '';
try {
    DockerTooling::prepare(
        'local-site',
        $compose,
        null,
        null,
        'wordpress',
        static function () use (&$remoteCalled): array {
            $remoteCalled = true;
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        },
        $tmp . '/remote'
    );
} catch (RuntimeException $error) {
    $remoteMessage = $error->getMessage();
}
wprism_check(str_contains($remoteMessage, 'unset DOCKER_HOST') && !$remoteCalled, 'daemon overrides refuse before Docker execution so a named context can be pinned');

putenv($priorDockerHost === false ? 'DOCKER_HOST' : 'DOCKER_HOST=' . $priorDockerHost);
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        is_dir($child) ? $removeTree($child) : unlink($child);
    }
    rmdir($path);
};
$removeTree($tmp);
unset($GLOBALS['compose']);

wprism_check_summary('managed Docker tooling');
