<?php
declare(strict_types=1);

/** Deterministic product-path proof for the explicit Docker PROCESS grant. */
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/DockerDatabaseSetup.php';

use WPrism\Orchestrator\DockerDatabaseSetup;
use WPrism\Orchestrator\DockerDatabaseSetupException;
use WPrism\Orchestrator\DockerTransport;
use WPrism\Orchestrator\HostProcess;

$driver = (new ReflectionClass(DockerTransport::class))->newInstanceWithoutConstructor();
$server = 'AbCdEfGhIjKlMnOpQrStUvWxYz0=';
$container = str_repeat('a', 64);
$binding = [
    'compose_project' => 'fixture_project',
    'config_hash' => str_repeat('b', 64),
    'container_hostname' => 'fixture-db-1',
    'container_id' => $container,
    'database_service' => 'db',
    'docker_context' => 'orbstack',
    'docker_endpoint' => 'unix:///var/run/docker.sock',
    'docker_tokens' => ['docker', '--context', 'orbstack'],
    'engine' => 'mariadb',
    'image_digest' => 'mariadb@sha256:' . str_repeat('c', 64),
    'image_id' => 'sha256:' . str_repeat('d', 64),
];
$proposal = ['unsupported' => [[
    'code' => DockerDatabaseSetup::BLOCKER,
    'extension' => 'wordpress-database',
]]];

/** @return array{0:callable,1:Closure():list<array{operation:string,request:array}>} */
$fixture = static function (
    bool $process = false,
    ?array $bindingOverride = null,
    ?array $wordpressOverride = null,
    ?array $adminFailure = null
) use ($binding, $server): array {
    $calls = [];
    $granted = $process;
    $bound = $bindingOverride ?? $binding;
    $wordpress = array_replace([
        'engine' => 'mariadb',
        'hostname' => 'fixture-db-1',
        'port' => '3306',
        'principal' => 'wordpress@%',
        'process' => $process,
        'schema' => 'wordpress',
        'server_identity' => $server,
        'version' => '11.8.3-MariaDB',
    ], $wordpressOverride ?? []);
    $runner = static function (string $operation, array $request) use (
        &$calls,
        &$granted,
        $bound,
        $wordpress,
        $adminFailure,
        $server
    ): array {
        $calls[] = ['operation' => $operation, 'request' => $request];
        if ($operation === 'binding') {
            return $bound;
        }
        if ($operation === 'wordpress') {
            $facts = $wordpress;
            $facts['process'] = $granted;
            return ['exit' => 0, 'stdout' => json_encode($facts, JSON_THROW_ON_ERROR) . "\n", 'stderr' => ''];
        }
        if ($operation === 'cleanup') {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($operation !== 'admin') {
            throw new LogicException('unexpected Docker database setup operation');
        }
        $argv = $request['argv'];
        $action = $argv[count($argv) - 6];
        if ($adminFailure !== null && ($adminFailure['action'] ?? null) === $action) {
            return $adminFailure['result'];
        }
        if ($action === 'grant') {
            $granted = true;
            return [
                'exit' => 0,
                'stdout' => "WPRISM_GRANT_ATTEMPT\nWPRISM_GRANT_APPLIED\n$server\tfixture-db-1\t1\t1\n",
                'stderr' => '',
            ];
        }
        return [
            'exit' => 0,
            'stdout' => "$server\tfixture-db-1\t1\t" . ($granted ? '1' : '0') . "\n",
            'stderr' => '',
        ];
    };
    return [$runner, static function () use (&$calls): array { return $calls; }];
};

[$runner, $calls] = $fixture();
$plan = DockerDatabaseSetup::plan($driver, 'db', $proposal, $runner);
wprism_check_same(true, $plan['required'], 'missing direct PROCESS produces an explicit digest-bound setup plan');
wprism_check_same('wordpress', $plan['account']['user'], 'the plan binds the exact authenticated WordPress user');
wprism_check_same('%', $plan['account']['host'], 'the plan binds the exact authenticated WordPress host pattern');
wprism_check_same('fixture_project', $plan['compose_project'], 'the operator plan names the selected Compose project');
wprism_check_same('wordpress', $plan['schema'], 'the operator plan names the connected schema');
wprism_check(preg_match('/^[a-f0-9]{64}$/D', (string) $plan['digest']) === 1,
    'the complete non-secret service and principal proof has a canonical digest');
$receipt = DockerDatabaseSetup::apply($driver, $plan, $proposal, $runner);
wprism_check_same(true, $receipt['changed'], 'confirmed setup applies the one missing privilege');
$adminCalls = array_values(array_filter($calls(), static fn(array $call): bool => $call['operation'] === 'admin'));
$grantCalls = array_values(array_filter($adminCalls, static function (array $call): bool {
    $argv = $call['request']['argv'];
    return $argv[count($argv) - 6] === 'grant';
}));
wprism_check_same(1, count($grantCalls), 'confirmation executes exactly one grant action');
$grantArgv = $grantCalls[0]['request']['argv'];
$script = $grantArgv[array_search('-c', $grantArgv, true) + 1];
wprism_check(str_contains($script, "GRANT PROCESS ON *.* TO '\$user'@'\$host';")
    && !str_contains($script, 'FLUSH PRIVILEGES')
    && !str_contains($script, 'GRANT OPTION')
    && !str_contains($script, 'CREATE USER')
    && !preg_match('/GRANT\s+ALL/i', $script),
    'the fixed admin program can grant only PROCESS and never broadens the account');
wprism_check(str_contains(
    $script,
    "BINARY GRANTEE = BINARY CONCAT(CHAR(39), '\$user', CHAR(39), '@', CHAR(39), '\$host', CHAR(39))"
) && !str_contains($script, "BINARY '\\''\$user"),
    'the rendered direct-GRANTEE query contains no shell backslashes that MySQL would parse as SQL');

$adminScriptTmp = dirname(__DIR__, 3) . '/tmp/docker-database-admin-script-' . bin2hex(random_bytes(8));
mkdir($adminScriptTmp, 0700);
$fakeClient = $adminScriptTmp . '/mysql';
$sqlLog = $adminScriptTmp . '/sql.log';
file_put_contents($fakeClient, <<<'SH'
#!/bin/sh
set -eu
query=
for argument in "$@"; do
  case "$argument" in --execute=*) query=${argument#--execute=} ;; esac
done
printf '%s\n' "$query" >>"$WPRISM_SQL_LOG"
case "$query" in
  'SELECT @@server_uuid, @@hostname, VERSION();')
    printf '%s\t%s\t%s\n' '11111111-2222-3333-4444-555555555555' 'fixture-db-1' '8.4.6'
    ;;
  *'FROM mysql.user'*) printf '1\n' ;;
  *'FROM information_schema.USER_PRIVILEGES'*)
    case "$query" in
      *"BINARY CONCAT(CHAR(39), 'wordpress', CHAR(39), '@', CHAR(39), '%', CHAR(39))"*) printf '0\n' ;;
      *) printf 'ERROR 1064: malformed direct-GRANTEE expression\n' >&2; exit 1 ;;
    esac
    ;;
  *) printf 'unexpected SQL: %s\n' "$query" >&2; exit 2 ;;
esac
SH);
chmod($fakeClient, 0700);
register_shutdown_function(static function () use ($adminScriptTmp, $fakeClient, $sqlLog): void {
    if (is_file($sqlLog)) unlink($sqlLog);
    if (is_file($fakeClient)) unlink($fakeClient);
    if (is_dir($adminScriptTmp)) rmdir($adminScriptTmp);
});
$adminToken = bin2hex(random_bytes(12));
$executedAdmin = HostProcess::run([
    'sh', '-c', $script, 'wprism-db-admin-test', 'mysql', 'probe', 'wordpress', '%',
    '11111111-2222-3333-4444-555555555555', 'fixture-db-1', $adminToken,
], null, [
    'MYSQL_ROOT_PASSWORD' => 'fixture-root-secret',
    'PATH' => $adminScriptTmp . ':' . (string) getenv('PATH'),
    'WPRISM_SQL_LOG' => $sqlLog,
], false, 5000, 65536);
$executedSql = is_file($sqlLog) ? (string) file_get_contents($sqlLog) : '';
wprism_check_same(0, $executedAdmin['exit'],
    'the actual rendered admin program executes its MySQL 8.4 direct-GRANTEE probe successfully');
wprism_check(!file_exists('/tmp/.wprism-db-admin-' . $adminToken),
    'the actual rendered admin program removes its credential defaults file after the probe');
wprism_check(str_contains(
    $executedSql,
    "BINARY GRANTEE = BINARY CONCAT(CHAR(39), 'wordpress', CHAR(39), '@', CHAR(39), '%', CHAR(39))"
) && !str_contains($executedSql, "\\''wordpress"),
    'the executed MySQL query retains exact direct-GRANTEE semantics without the prior syntax error');
wprism_check(str_contains($script, 'umask 077') && str_contains($script, 'chmod 0600 "$defaults"')
    && str_contains($script, 'trap cleanup EXIT HUP INT TERM') && str_contains($script, 'set -C; : >"$defaults"'),
    'the selected-container password crosses only an exclusively-created mode-0600 defaults file with trap cleanup');
$cleanupCalls = array_values(array_filter($calls(), static fn(array $call): bool => $call['operation'] === 'cleanup'));
wprism_check(count($cleanupCalls) === count($adminCalls),
    'every admin probe receives an independent exact-path credential cleanup attempt');
$cleanupScript = $cleanupCalls[0]['request']['argv'][array_search('-c', $cleanupCalls[0]['request']['argv'], true) + 1];
wprism_check(str_contains($cleanupScript, 'stat -c %u -- "$defaults"')
    && str_contains($cleanupScript, 'rm -f -- "$defaults"'),
    'post-probe cleanup removes only the root-owned random credential file');
wprism_check(!str_contains(implode("\0", $grantArgv), 'fixture-root-secret')
    && !str_contains(implode("\0", $grantArgv), 'MYSQL_ROOT_PASSWORD='),
    'the host and container argv contain no database credential value');

[$sufficientRunner, $sufficientCalls] = $fixture(true);
$sufficient = DockerDatabaseSetup::plan($driver, 'db', ['unsupported' => []], $sufficientRunner);
wprism_check_same(false, $sufficient['required'], 'an already-sufficient WordPress account plans no mutation');
wprism_check_same(false, DockerDatabaseSetup::apply(
    $driver,
    $sufficient,
    ['unsupported' => []],
    $sufficientRunner
)['changed'], 'repeated explicit setup is a proven no-op');
wprism_check_same([], array_values(array_filter($sufficientCalls(), static function (array $call): bool {
    if ($call['operation'] !== 'admin') return false;
    $argv = $call['request']['argv'];
    return $argv[count($argv) - 6] === 'grant';
})), 'already-sufficient setup never invokes the grant action');

foreach ([
    'wrong database hostname' => [['hostname' => 'other-db'], 'different database hostname'],
    'wrong database server' => [['server_identity' => 'ZbCdEfGhIjKlMnOpQrStUvWxYz0='], 'different database server'],
    'unsafe principal' => [['principal' => "wordpress'@%"], 'bounded grant grammar'],
    'MariaDB without server_uid' => [['version' => '11.4.2-MariaDB'], 'requires immutable server_uid'],
    'MariaDB 11.6.0 before server_uid' => [['version' => '11.6.0-MariaDB'], 'requires immutable server_uid'],
] as $label => [$override, $message]) {
    [$badRunner] = $fixture(false, null, $override);
    wprism_check_throws(
        static fn() => DockerDatabaseSetup::plan($driver, 'db', $proposal, $badRunner),
        RuntimeException::class,
        "$label refuses before a database mutation",
        $message
    );
}
[$serverUidRunner] = $fixture(false, null, ['version' => '11.6.1-MariaDB']);
wprism_check_same(true, DockerDatabaseSetup::plan($driver, 'db', $proposal, $serverUidRunner)['required'],
    'MariaDB 11.6.1 enters the immutable server_uid setup boundary');

[$unsupportedRunner] = $fixture();
wprism_check_throws(static fn() => DockerDatabaseSetup::plan($driver, 'db', ['unsupported' => [[
    'code' => 'platform_database_version_unsupported',
]]], $unsupportedRunner), RuntimeException::class,
    'a platform-unsupported database refuses before privilege provisioning', 'outside the exercised platform contract');

[$missingAdminRunner] = $fixture(false, null, null, [
    'action' => 'probe',
    'result' => ['exit' => 68, 'stdout' => '', 'stderr' => 'selected database service provides no supported root credential'],
]);
wprism_check_throws(static fn() => DockerDatabaseSetup::plan($driver, 'db', $proposal, $missingAdminRunner),
    RuntimeException::class, 'missing selected-service admin credentials refuse before mutation', 'no supported root credential');

[$uncertainRunner] = $fixture(false, null, null, [
    'action' => 'grant',
    'result' => ['exit' => 76, 'stdout' => "WPRISM_GRANT_ATTEMPT\nWPRISM_GRANT_APPLIED\n", 'stderr' => 'post-grant proof failed'],
]);
$uncertainPlan = DockerDatabaseSetup::plan($driver, 'db', $proposal, $uncertainRunner);
try {
    DockerDatabaseSetup::apply($driver, $uncertainPlan, $proposal, $uncertainRunner);
    wprism_check(false, 'an applied grant with failed proof cannot report success');
} catch (DockerDatabaseSetupException $error) {
    wprism_check($error->grantMayHaveSucceeded(),
        'an applied grant with failed proof carries explicit possibly-durable mutation evidence');
}

$changedBinding = $binding;
$changedBinding['container_id'] = str_repeat('e', 64);
$bindingCalls = 0;
[$ordinaryRunner] = $fixture();
$driftRunner = static function (string $operation, array $request) use (
    &$bindingCalls,
    $binding,
    $changedBinding,
    $ordinaryRunner
): array {
    if ($operation === 'binding') {
        ++$bindingCalls;
        return $bindingCalls < 3 ? $binding : $changedBinding;
    }
    return $ordinaryRunner($operation, $request);
};
$driftPlan = DockerDatabaseSetup::plan($driver, 'db', $proposal, $driftRunner);
wprism_check_throws(static fn() => DockerDatabaseSetup::apply($driver, $driftPlan, $proposal, $driftRunner),
    RuntimeException::class, 'container drift immediately before grant refuses without mutation', 'changed immediately before');

$tmp = sys_get_temp_dir() . '/wprism-docker-database-binding-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
$compose = $tmp . '/compose file.yml';
file_put_contents($compose, "services:\n  wordpress:\n    image: wordpress\n");
register_shutdown_function(static function () use ($compose, $tmp): void {
    if (is_file($compose)) unlink($compose);
    if (is_dir($tmp)) rmdir($tmp);
});
$webContainer = str_repeat('1', 64);
$dbContainer = str_repeat('2', 64);
$webHash = str_repeat('3', 64);
$dbHash = str_repeat('4', 64);
$dbImageId = 'sha256:' . str_repeat('5', 64);
$composeConfig = json_encode([
    'services' => [
        'wordpress' => ['volumes' => [[
            'type' => 'volume', 'source' => 'wp', 'target' => '/var/www/html', 'read_only' => false,
        ]]],
        'cli' => ['volumes' => [
            ['type' => 'volume', 'source' => 'wp', 'target' => '/var/www/html', 'read_only' => false],
            ['type' => 'volume', 'source' => 'repo', 'target' => '/wprism-repository', 'read_only' => false],
        ]],
        'db' => ['image' => 'mariadb:11.8'],
    ],
    'volumes' => [
        'wp' => ['name' => 'fixture_wp'],
        'repo' => ['name' => 'fixture_repo'],
    ],
], JSON_THROW_ON_ERROR);
$webMounts = json_encode([[
    'Type' => 'volume', 'Name' => 'fixture_wp', 'Source' => '/docker/fixture_wp',
    'Destination' => '/var/www/html', 'RW' => true,
]], JSON_THROW_ON_ERROR);
$controlCalls = [];
$controlPlane = static function (string $command) use (
    &$controlCalls,
    $composeConfig,
    $webContainer,
    $dbContainer,
    $webHash,
    $dbHash,
    $dbImageId,
    $webMounts
): array {
    $controlCalls[] = $command;
    $ok = static fn(string $stdout = ''): array => ['exit' => 0, 'stdout' => $stdout, 'stderr' => ''];
    if (str_contains($command, "'context' 'inspect'")) {
        return $ok(json_encode('unix:///var/run/docker.sock', JSON_THROW_ON_ERROR));
    }
    if (str_contains($command, 'config') && str_contains($command, '--format') && str_contains($command, 'json')) {
        return $ok($composeConfig);
    }
    if (str_contains($command, '{{json .Id}}') && str_contains($command, '{{json .Config.Hostname}}')) {
        return $ok(implode('|', [
            json_encode($dbContainer, JSON_THROW_ON_ERROR),
            json_encode($dbImageId, JSON_THROW_ON_ERROR),
            json_encode('mariadb:11.8', JSON_THROW_ON_ERROR),
            json_encode('fixture-db-1', JSON_THROW_ON_ERROR),
            json_encode('', JSON_THROW_ON_ERROR),
            json_encode(['docker-entrypoint.sh'], JSON_THROW_ON_ERROR),
            json_encode(['mariadbd'], JSON_THROW_ON_ERROR),
            json_encode([
                'com.docker.compose.project' => 'fixture_project',
                'com.docker.compose.service' => 'db',
                'com.docker.compose.config-hash' => $dbHash,
            ], JSON_THROW_ON_ERROR),
            'true',
        ]) . "\n");
    }
    if (str_contains($command, '{{json .RepoDigests}}')) {
        return $ok(json_encode($dbImageId, JSON_THROW_ON_ERROR) . '|'
            . json_encode(['mariadb@sha256:' . str_repeat('6', 64)], JSON_THROW_ON_ERROR) . "\n");
    }
    if (str_contains($command, "'config' '--hash' 'wordpress'")) {
        return $ok("wordpress $webHash\n");
    }
    if (str_contains($command, "'config' '--hash' 'db'")) {
        return $ok("db $dbHash\n");
    }
    if (str_contains($command, 'com.docker.compose.config-hash')) {
        return $ok("$webHash\n");
    }
    if (str_contains($command, '{{json .Mounts}}')) {
        return $ok($webMounts . "\n");
    }
    if (str_contains($command, '--status=running') && str_contains($command, '--services')) {
        return $ok("wordpress\ndb\n");
    }
    if (str_contains($command, '--status=running') && str_contains($command, '-q') && str_contains($command, 'db')) {
        return $ok("$dbContainer\n");
    }
    if (str_contains($command, 'ps') && str_contains($command, '-q') && str_contains($command, 'wordpress')) {
        return $ok("$webContainer\n");
    }
    return ['exit' => 98, 'stdout' => '', 'stderr' => 'unexpected control-plane fixture command'];
};
$transport = new DockerTransport('fixture', [
    'transport' => 'docker', '_dir' => $tmp, '_machine_local' => true,
    'compose_file' => $compose, 'compose_project' => 'fixture_project',
    'service' => 'cli', 'wordpress_service' => 'wordpress',
    'wp_path' => '/var/www/html', 'repo_path' => '/wprism-repository/site',
    'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT],
    'docker_context' => 'orbstack', 'docker_endpoint' => 'unix:///var/run/docker.sock',
], null, $controlPlane);
$actualBinding = $transport->localDatabaseServiceBinding('db');
wprism_check_same('fixture-db-1', $actualBinding['container_hostname'],
    'the real transport binds the inspected database container hostname');
wprism_check_same('mariadb@sha256:' . str_repeat('6', 64), $actualBinding['image_digest'],
    'the real transport binds one official immutable database image digest');
wprism_check(count(array_filter($controlCalls, static fn(string $command): bool =>
    str_contains($command, "'--context' 'orbstack'"))) === count($controlCalls),
    'every database service control-plane call stays pinned to the connected local context');
wprism_check_throws(static fn() => $transport->localDatabaseServiceBinding('cache'), RuntimeException::class,
    'an unselected or absent Compose service is never guessed as the database target', 'official mysql or mariadb');
$remotePlane = static function (string $command, int $timeout, int $stdout, int $stderr) use ($controlPlane): array {
    if (str_contains($command, "'context' 'inspect'")) {
        return ['exit' => 0, 'stdout' => json_encode('tcp://remote.example.test:2376', JSON_THROW_ON_ERROR), 'stderr' => ''];
    }
    return $controlPlane($command);
};
$remoteTransport = new DockerTransport('fixture', [
    'transport' => 'docker', '_dir' => $tmp, '_machine_local' => true,
    'compose_file' => $compose, 'compose_project' => 'fixture_project',
    'service' => 'cli', 'wordpress_service' => 'wordpress',
    'wp_path' => '/var/www/html', 'repo_path' => '/wprism-repository/site',
    'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT],
    'docker_context' => 'orbstack', 'docker_endpoint' => 'unix:///var/run/docker.sock',
], null, $remotePlane);
wprism_check_throws(static fn() => $remoteTransport->localDatabaseServiceBinding('db'), RuntimeException::class,
    'a changed or remote Docker context refuses before database admin access', 'remote Docker contexts are not authorized');

$initSource = (string) file_get_contents(__DIR__ . '/../../../../cli/src/Command/InitCommand.php');
wprism_check(str_contains($initSource, '$e instanceof DockerDatabaseSetupException')
    && str_contains($initSource, '$e->grantMayHaveSucceeded()')
    && str_contains($initSource, 'self::renderDurableDatabaseGrantNotice($databaseGrantAccount, $databaseGrantUncertain);'),
    'an uncertain post-GRANT failure reaches the durable-grant operator notice');
wprism_check(substr_count($initSource, 'self::renderDurableDatabaseGrantNotice($databaseGrantAccount);') >= 5,
    'classification, blocked proposal, cancellation, and confirmation failures all preserve the confirmed grant notice');

echo "PASS: Docker database setup\n";
