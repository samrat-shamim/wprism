<?php
/**
 * Product-path proof for controller -> isolated WP-CLI -> shipped origin
 * connector -> signed loopback authority. No external network or cloud exists.
 *
 * usage: php origin-connector-command-checks.php <scratch-dir>
 */
declare(strict_types=1);

use Duo\Canon;
use Duo\OriginStore;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\OriginAuthority;
use Duo\Cloud\OriginFileBlobStore;

require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 4) . '/agent/src/Cloud/OriginStore.php';
require_once dirname(__DIR__, 4) . '/cloud/src/OriginAuthority.php';
require_once dirname(__DIR__, 4) . '/cloud/src/OriginFileBlobStore.php';
require_once dirname(__DIR__, 2) . '/lib/check.php';

$scratch = $argv[1] ?? '';
if (!is_string($scratch) || $scratch === '' || !is_dir($scratch)) {
    fwrite(STDERR, "usage: php origin-connector-command-checks.php <scratch-dir>\n");
    exit(2);
}
$scratch = realpath($scratch);
if (!is_string($scratch) || $scratch === '/') {
    throw new RuntimeException('origin connector command scratch must resolve below root');
}
if (!chmod($scratch, 0700)) {
    throw new RuntimeException('origin connector command scratch could not be made process-private');
}
$root = dirname(__DIR__, 4);

function occ_write(string $path, string $bytes, int $mode = 0600): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException("could not create origin connector fixture directory '$parent'");
    }
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($path, $mode)) {
        throw new RuntimeException("could not publish origin connector fixture '$path'");
    }
}

/** @param list<string> $command @return array{exit:int,stderr:string,stdout:string} */
function occ_process(array $command, string $cwd, array $environment, ?string $stdin = null): array {
    $descriptors = [
        0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start origin connector fixture process');
    }
    if ($stdin !== null) {
        if (fwrite($pipes[0], $stdin) !== strlen($stdin)) {
            throw new RuntimeException('could not write origin connector fixture stdin');
        }
        fclose($pipes[0]);
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stderr' => $stderr, 'stdout' => $stdout];
}

/** @param list<string> $command */
function occ_run(array $command, string $cwd, array $environment): string {
    $result = occ_process($command, $cwd, $environment);
    if ($result['exit'] !== 0) {
        throw new RuntimeException(
            'origin connector fixture command failed: ' . implode(' ', $command)
                . "\nstdout: {$result['stdout']}\nstderr: {$result['stderr']}"
        );
    }
    return trim($result['stdout']);
}

/** @param list<string> $needles */
function occ_tree_contains(string $root, array $needles): bool {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $bytes = (string) file_get_contents($entry->getPathname());
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($bytes, $needle)) {
                return true;
            }
        }
    }
    return false;
}

/** @return array<string,mixed> */
function occ_origin_command(
    string $root,
    string $overlay,
    string $repo,
    array $environment,
    string $operation,
    ?string $stdin = null,
    bool $poll = false
): array {
    $command = [
        PHP_BINARY,
        $root . '/cli/duo',
        '--envs-file=' . $overlay,
        'origin',
        $operation,
        'production',
    ];
    if ($poll) {
        $command[] = '--poll';
    }
    $command[] = '--format=json';
    $result = occ_process($command, $repo, $environment, $stdin);
    if ($result['exit'] !== 0 || $result['stderr'] !== '') {
        $privateError = isset($environment['DUO_OCC_WP_ERROR_LOG'])
            ? (string) @file_get_contents((string) $environment['DUO_OCC_WP_ERROR_LOG'])
            : '';
        throw new RuntimeException(
            "public duo origin $operation failed\nstdout: {$result['stdout']}\n"
                . "stderr: {$result['stderr']}\nprivate target evidence: $privateError"
        );
    }
    $document = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($document) || ($document['format'] ?? null) !== 'duo-cloud-origin-command/v1'
        || ($document['operation'] ?? null) !== $operation || !is_array($document['result'] ?? null)) {
        throw new RuntimeException("public duo origin $operation returned a malformed document");
    }
    return $document;
}

$repo = $scratch . '/repository';
$wpPath = $scratch . '/wordpress';
$bin = $scratch . '/bin';
$blobs = $scratch . '/origin-blobs';
foreach ([$repo, $wpPath, $bin, $blobs] as $directory) {
    if (!mkdir($directory, 0700) || !chmod($directory, 0700)) {
        throw new RuntimeException("could not create origin connector fixture directory '$directory'");
    }
}
$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
occ_run(['git', 'init'], $repo, $environment);
occ_write($repo . '/README.md', "origin connector command fixture\n", 0600);
occ_run(['git', 'add', 'README.md'], $repo, $environment);
occ_run([
    'git', '-c', 'user.name=Duo Fixture', '-c', 'user.email=duo@example.test',
    'commit', '-m', 'fixture',
], $repo, $environment);
$productionCommit = occ_run(['git', 'rev-parse', '--verify', 'HEAD'], $repo, $environment);
if (preg_match('/^[a-f0-9]{40}$/D', $productionCommit) !== 1) {
    throw new RuntimeException('origin connector fixture Git commit is malformed');
}
if (!mkdir($repo . '/.duo', 0700) || !mkdir($repo . '/.duo/control', 0700)) {
    throw new RuntimeException('could not create protected origin connector state roots');
}

$servicePair = sodium_crypto_sign_seed_keypair(hash('sha256', 'origin-command-service', true));
$serviceSecret = sodium_crypto_sign_secretkey($servicePair);
$servicePublic = sodium_crypto_sign_publickey($servicePair);
$deviceDigest = hash('sha256', 'origin-command-device-digest', true);
$serviceKeyId = 'origin-command-service-key-v1';
$serviceSecretPath = $scratch . '/service-secret.key';
$deviceDigestPath = $scratch . '/device-digest.key';
$statePath = $scratch . '/origin-authority.json';
occ_write($serviceSecretPath, base64_encode($serviceSecret) . "\n");
occ_write($deviceDigestPath, base64_encode($deviceDigest) . "\n");
$authority = new OriginAuthority(
    new FileAuthorityStore($statePath),
    new OriginFileBlobStore($blobs),
    $serviceKeyId,
    $serviceSecret,
    $deviceDigest
);
$firstCode = $authority->issueDeviceCode('tenant-command', 'site-command');

$probe = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
if (!is_resource($probe)) {
    throw new RuntimeException("could not reserve loopback endpoint: $socketMessage ($socketError)");
}
$probeName = stream_socket_get_name($probe, false);
fclose($probe);
if (!is_string($probeName) || preg_match('/^127\.0\.0\.1:([0-9]+)$/D', $probeName, $portMatch) !== 1) {
    throw new RuntimeException('could not resolve loopback endpoint port');
}
$port = (int) $portMatch[1];
$endpoint = "http://127.0.0.1:$port";
$serverEnvironment = $environment + [];
$serverEnvironment['DUO_OCC_ROOT'] = $root;
$serverEnvironment['DUO_OCC_STATE'] = $statePath;
$serverEnvironment['DUO_OCC_BLOBS'] = $blobs;
$serverEnvironment['DUO_OCC_SERVICE_KEY_ID'] = $serviceKeyId;
$serverEnvironment['DUO_OCC_SERVICE_SECRET_PATH'] = $serviceSecretPath;
$serverEnvironment['DUO_OCC_DEVICE_DIGEST_PATH'] = $deviceDigestPath;
$serverOut = $scratch . '/endpoint.out';
$serverErr = $scratch . '/endpoint.err';
$server = proc_open(
    [
        PHP_BINARY,
        '-S',
        "127.0.0.1:$port",
        __DIR__ . '/origin-connector-command-endpoint.php',
    ],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverOut, 'a'], 2 => ['file', $serverErr, 'a']],
    $serverPipes,
    $scratch,
    $serverEnvironment,
    ['bypass_shell' => true]
);
if (!is_resource($server)) {
    throw new RuntimeException('could not start origin connector loopback authority');
}
register_shutdown_function(static function () use (&$server): void {
    if (is_resource($server)) {
        @proc_terminate($server);
        @proc_close($server);
        $server = null;
    }
});
$ready = false;
for ($attempt = 0; $attempt < 150; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
    if (is_resource($socket)) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(20000);
}
if (!$ready) {
    throw new RuntimeException(
        'origin connector loopback authority did not become ready: ' . (string) @file_get_contents($serverErr)
    );
}

$wp = $bin . '/wp';
if (!copy(__DIR__ . '/origin-connector-command-target.php', $wp) || !chmod($wp, 0700)) {
    throw new RuntimeException('could not install origin connector WP-CLI fixture');
}
$wpLog = $scratch . '/wp.log';
$wpErrorLog = $scratch . '/wp-error.log';
occ_write($wpLog, '');
occ_write($wpErrorLog, '');
$overlay = $scratch . '/envs.json';
occ_write($overlay, json_encode(['envs' => [
    'production' => [
        'repo_path' => $repo,
        'transport' => 'local',
        'wp_path' => $wpPath,
    ],
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$environment['PATH'] = $bin . ':' . ($environment['PATH'] ?? '');
$environment['DUO_TEST_MODE'] = '1';
$environment['DUO_OCC_ROOT'] = $root;
$environment['DUO_OCC_ENDPOINT'] = $endpoint;
$environment['DUO_OCC_SERVICE_KEY_ID'] = $serviceKeyId;
$environment['DUO_OCC_SERVICE_PUBLIC_KEY'] = base64_encode($servicePublic);
$environment['DUO_OCC_WP_LOG'] = $wpLog;
$environment['DUO_OCC_WP_ERROR_LOG'] = $wpErrorLog;

$begun = occ_origin_command(
    $root,
    $overlay,
    $repo,
    $environment,
    'pair',
    $firstCode['device_code'] . "\n"
);
duo_check(($begun['result']['phase'] ?? null) === 'pending', 'public origin pair executes the shipped connector begin path');
$paired = occ_origin_command($root, $overlay, $repo, $environment, 'pair', null, true);
duo_check(
    ($paired['result']['phase'] ?? null) === 'paired'
        && ($paired['result']['pairing']['origin_generation'] ?? null) === 1,
    'public origin pair --poll activates signed generation one'
);

$demand = $authority->requestPortableDemand(
    'tenant-command',
    'site-command',
    hash('sha256', 'origin-command-demand-one'),
    $productionCommit
);
$sessionId = hash(
    'sha256',
    "duo-cloud-origin-session/v1\0tenant-command\0site-command\0" . 1 . "\0"
        . $demand['demand_generation'] . "\0" . $demand['demand_id'] . "\0$productionCommit"
);
$repositoryIdentity = [
    'artifact_hash' => hash('sha256', 'origin-command-artifact'),
    'code_revision' => null,
    'revision_hash' => hash('sha256', 'origin-command-revision'),
];
$export = [
    'completed_code' => null,
    'deletions' => [],
    'format' => 'duo-refresh-production/v1',
    'media' => [],
    'policy' => [
        'manifest_hash' => hash('sha256', 'origin-command-manifest'),
        'resolved_adapters' => [],
        'site_hash' => hash('sha256', 'origin-command-site'),
    ],
    'records' => [
        'options/core' => [
            'content' => ['blogname' => 'Origin command fixture'],
            'hash' => hash('sha256', 'origin-command-options'),
            'identity' => 'options/core',
            'path' => 'options/core.json',
            'type' => 'options',
        ],
    ],
    'repository' => $repositoryIdentity,
    'warnings' => [],
];
$export['snapshot_hash'] = hash('sha256', Canon::encode($export));
$exportBytes = Canon::encode($export);
$store = OriginStore::open($repo);
$store->withExclusive(static function () use (
    $store,
    $sessionId,
    $productionCommit,
    $exportBytes,
    $export,
    $repositoryIdentity
): void {
    $store->publish($sessionId, $productionCommit, $exportBytes, [
        'format' => 'duo-refresh-production/v1',
        'repository' => $repositoryIdentity,
        'snapshot_hash' => $export['snapshot_hash'],
    ]);
});

$uploaded = occ_origin_command($root, $overlay, $repo, $environment, 'export');
$commit = $uploaded['result']['last_commit'] ?? null;
duo_check(
    ($uploaded['result']['remote_state'] ?? null) === 'committed'
        && is_array($commit)
        && ($commit['demand_generation'] ?? null) === 1,
    'public origin export uploads and commits the presealed production artifact'
);
$exportId = is_array($commit) ? ($commit['export_id'] ?? null) : null;
duo_check(
    is_string($exportId)
        && $authority->readCommittedChunk('tenant-command', 'site-command', $exportId, 0) === $exportBytes,
    'the signed authority retained the exact chunk sent through the WordPress connector'
);
duo_check(
    OriginStore::open($repo)->readSealed($sessionId, $productionCommit) === null,
    'the connector removed its local production spool only after the commit receipt'
);

$status = occ_origin_command($root, $overlay, $repo, $environment, 'status');
duo_check(
    ($status['result']['pairing']['phase'] ?? null) === 'paired'
        && ($status['result']['upload']['last_commit']['export_id'] ?? null) === $exportId,
    'public origin status reads the actual redacted pairing and upload journals'
);
$rotated = occ_origin_command($root, $overlay, $repo, $environment, 'rotate');
$rotationReplay = occ_origin_command($root, $overlay, $repo, $environment, 'rotate');
duo_check(
    ($rotated['result']['pairing']['origin_generation'] ?? null) === 2
        && Canon::encode($rotated) === Canon::encode($rotationReplay),
    'public origin rotate advances once and replays the exact completed rollover'
);
$uninstalled = occ_origin_command($root, $overlay, $repo, $environment, 'uninstall');
$uninstallReplay = occ_origin_command($root, $overlay, $repo, $environment, 'uninstall');
duo_check(
    ($uninstalled['result']['phase'] ?? null) === 'revoked'
        && Canon::encode($uninstalled) === Canon::encode($uninstallReplay),
    'public origin uninstall durably revokes with an exact lost-response replay'
);

$secondCode = $authority->issueDeviceCode('tenant-command', 'site-command');
$repairedBegin = occ_origin_command(
    $root,
    $overlay,
    $repo,
    $environment,
    'pair',
    $secondCode['device_code'] . "\n"
);
$repaired = occ_origin_command($root, $overlay, $repo, $environment, 'pair', null, true);
duo_check(
    ($repairedBegin['result']['phase'] ?? null) === 'pending'
        && ($repaired['result']['pairing']['origin_generation'] ?? null) === 3,
    'public origin pair replaces terminal authority without resetting generation'
);
$revoked = occ_origin_command($root, $overlay, $repo, $environment, 'revoke');
$revokeReplay = occ_origin_command($root, $overlay, $repo, $environment, 'revoke');
duo_check(
    ($revoked['result']['phase'] ?? null) === 'revoked'
        && Canon::encode($revoked) === Canon::encode($revokeReplay),
    'public origin revoke uses distinct administrator authority and exact replay'
);

$commands = array_map(
    static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
    array_values(array_filter(explode("\n", trim((string) file_get_contents($wpLog)))))
);
$verbs = [];
$isolated = true;
foreach ($commands as $command) {
    $duo = array_search('duo', $command, true);
    if (is_int($duo) && is_string($command[$duo + 1] ?? null)) {
        $verbs[] = $command[$duo + 1];
    }
    $isolated = $isolated
        && in_array('--skip-plugins', $command, true)
        && in_array('--skip-themes', $command, true)
        && count(array_filter(
            $command,
            static fn(mixed $argument): bool => is_string($argument) && str_starts_with($argument, '--exec=')
        )) === 1;
}
sort($verbs, SORT_STRING);
$expectedVerbs = [
    'origin-export', 'origin-pair', 'origin-pair', 'origin-pair', 'origin-pair',
    'origin-revoke', 'origin-revoke', 'origin-rotate', 'origin-rotate',
    'origin-status', 'origin-uninstall', 'origin-uninstall',
];
sort($expectedVerbs, SORT_STRING);
duo_check(
    $isolated && $verbs === $expectedVerbs,
    'every public lifecycle action reached the actual connector under the isolated WP-CLI control arguments'
);
$secretSentinels = [
    $firstCode['device_code'],
    $secondCode['device_code'],
    base64_encode($serviceSecret),
    base64_encode($deviceDigest),
];
$publicDocuments = Canon::encode([
    $begun, $paired, $uploaded, $status, $rotated, $rotationReplay,
    $uninstalled, $uninstallReplay, $repairedBegin, $repaired, $revoked, $revokeReplay,
]);
duo_check(
    !occ_tree_contains($repo, $secretSentinels)
        && !str_contains($publicDocuments . (string) file_get_contents($wpLog), $firstCode['device_code'])
        && !str_contains($publicDocuments . (string) file_get_contents($wpLog), $secondCode['device_code'])
        && !str_contains((string) file_get_contents($statePath), base64_encode($serviceSecret))
        && !str_contains((string) file_get_contents($statePath), base64_encode($deviceDigest)),
    'device codes and service secrets never enter command output, argv logs, connector state, or authority state'
);

proc_terminate($server);
proc_close($server);
$server = null;
sodium_memzero($serviceSecret);
sodium_memzero($deviceDigest);
