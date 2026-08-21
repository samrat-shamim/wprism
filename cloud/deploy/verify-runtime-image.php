#!/usr/bin/env php
<?php
declare(strict_types=1);

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ContainerArgvProcessRunner;
use Duo\Cloud\ContainerCommandRunner;
use Duo\Cloud\ImmutableOciReference;
use Duo\Cloud\NativeContainerArgvProcessRunner;
use Duo\Cloud\WorkloadSecurityInspection;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__) . '/runtime/ContainerCommandRunner.php';

if (defined('DUO_RUNTIME_IMAGE_VERIFIER_LIBRARY_ONLY')) {
    return;
}

const PROOF_REGISTRY_IMAGE = 'registry@sha256:'
    . 'a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373';

$docker = null;
foreach (['/usr/local/bin/docker', '/usr/bin/docker', '/opt/homebrew/bin/docker'] as $candidate) {
    if (is_executable($candidate)) {
        $docker = $candidate;
        break;
    }
}
$image = 'duo-cloud-preview:local';
$build = false;
$stateRoot = null;
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || !array_is_list($arguments)) {
    fwrite(STDERR, "verify-runtime-image: refused\n");
    exit(64);
}
foreach ($arguments as $argument) {
    if (!is_string($argument)) {
        fwrite(STDERR, "verify-runtime-image: refused\n");
        exit(64);
    }
}
for ($index = 1; $index < count($arguments); $index++) {
    if ($arguments[$index] === '--build') {
        $build = true;
        continue;
    }
    if ($arguments[$index] === '--docker' && isset($arguments[$index + 1])) {
        $docker = $arguments[++$index];
        continue;
    }
    if ($arguments[$index] === '--image' && isset($arguments[$index + 1])) {
        $image = $arguments[++$index];
        continue;
    }
    if ($arguments[$index] === '--state-root' && isset($arguments[$index + 1])) {
        $stateRoot = $arguments[++$index];
        continue;
    }
    fwrite(STDERR, "verify-runtime-image: refused\n");
    exit(64);
}

$runner = null;
$proofUid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
$proofId = substr(
    hash('sha256', "duo-cloud-runtime-image-verifier-authority/v1\0" . $proofUid),
    0,
    16
);
$tenantId = 'tenant-' . $proofId;
$siteId = 'site-' . $proofId;
$resourceId = 'cloud-slot-' . hash(
    'sha256',
    "duo-cloud-preview-physical-slot/v1\0{$tenantId}\0{$siteId}"
);
$resourceToken = substr($resourceId, strlen('cloud-slot-'));
$container = 'duo-preview-' . $resourceToken . '-g0000000001';
$databaseVolume = $container . '-database';
$filesystemVolume = $container . '-filesystem';
$scratch = sys_get_temp_dir() . '/duo-cloud-image-proof-' . $proofId;
$secretRoot = $scratch . '-secrets';
$registryContainer = 'duo-cloud-proof-registry-' . $proofId;
$helperContainer = 'duo-cloud-proof-helper-' . $proofId;
$proofRepository = null;
$proofTag = null;
$proofImageId = null;
$authority = null;
$journal = null;
$scratchOwned = false;
$secretRootOwned = false;
$runtimeImage = $image;
$seccompProfile = realpath(__DIR__ . '/seccomp-profile.json');
$proof = null;
$primary = null;
try {
    if (!is_string($docker) || !is_executable($docker) || $docker[0] !== '/'
        || !is_string($stateRoot) || !runtimeImageProofDurableStateRoot($stateRoot)
        || !is_string($seccompProfile) || !is_file($seccompProfile)
        || (!ImmutableOciReference::valid($image)
            && preg_match('/\A[a-z0-9][a-z0-9._\/-]*(?::[a-z0-9._-]+)?\z/D', $image) !== 1)) {
        throw new RuntimeException('Docker or image input is invalid');
    }
    $runner = new NativeContainerArgvProcessRunner(300);
    $authority = acquireRuntimeImageProofAuthority($stateRoot);
    recoverRuntimeImageProofJournal($runner, $authority['root']);
    assertNoUnjournaledRuntimeImageProofResources(
        $runner,
        $docker,
        $proofId,
        $container,
        $registryContainer,
        $helperContainer,
        $databaseVolume,
        $filesystemVolume,
        $scratch,
        $secretRoot
    );
    $repository = dirname(__DIR__, 2);
    if ($build) {
        mustRun($runner, [
            $docker, 'build', '--quiet', '--pull=false', '--tag', $image,
            '--file', $repository . '/cloud/runtime/image/Dockerfile', $repository,
        ], 'image build');
    }
    $inspection = jsonObject(mustRun($runner, [
        $docker, 'image', 'inspect', '--format', '{{json .}}', $image,
    ], 'image inspection')['stdout'], 'image inspection');
    if (($inspection['Config']['User'] ?? null) !== '10001:10001'
        || ($inspection['Config']['Entrypoint'] ?? null) !== ['/opt/duo/bin/duo-preview-runtime']
        || ($inspection['Config']['Volumes'] ?? null) !== null
        || ($inspection['Config']['ExposedPorts']['8080/tcp'] ?? null) === null
        || ($inspection['Config']['Labels']['duo.cloud.runtime-contract-sha256'] ?? null)
            !== hash_file('sha256', $repository . '/cloud/deploy/runtime-image-contract.json')) {
        throw new RuntimeException('image metadata differs from the reviewed no-implicit-volume contract');
    }
    $proofImageId = $inspection['Id'] ?? null;
    if (!is_string($proofImageId)
        || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $proofImageId) !== 1) {
        throw new RuntimeException('reviewed local image has no immutable image id');
    }
    $journal = runtimeImageProofJournal(
        $docker,
        $proofId,
        $proofImageId,
        $container,
        $registryContainer,
        $helperContainer,
        $databaseVolume,
        $filesystemVolume,
        $scratch,
        $secretRoot
    );
    publishRuntimeImageProofJournal($authority['root'], $journal);
    mustRun($runner, [
        $docker, 'run', '--rm', '--name', $helperContainer,
        '--label', 'duo.cloud.local-image-proof=' . $proofId,
        '--entrypoint', '/usr/local/bin/php', $image, '-r',
        '$h=["duo-preview-runtime","runtime-status","materialize-repository",'
            . '"restore-database","restore-media","url-rebind","duo-preview-command"];'
            . 'foreach($h as $n){$p="/opt/duo/bin/$n";if(!is_link($p)||!is_executable($p))exit(2);}'
            . 'if(!extension_loaded("mysqli")||!extension_loaded("pdo_mysql")'
            . '||!is_file("/usr/src/wordpress/wp-load.php")'
            . '||!is_link("/etc/apache2/mods-enabled/rewrite.load"))exit(3);echo "ok\n";',
    ], 'image helper behavior');

    if (!ImmutableOciReference::valid($image)) {
        mustRun($runner, [
            $docker, 'image', 'inspect', '--format', '{{.Id}}', PROOF_REGISTRY_IMAGE,
        ], 'pinned local proof registry availability');
        mustRun($runner, [
            $docker, 'run', '--detach', '--name', $registryContainer,
            '--label', 'duo.cloud.local-image-proof=' . $proofId,
            '--publish', '127.0.0.1::5000', PROOF_REGISTRY_IMAGE,
        ], 'local proof registry start');
        $registryPort = mustRun($runner, [
            $docker, 'container', 'port', $registryContainer, '5000/tcp',
        ], 'local proof registry port readback')['stdout'];
        if (preg_match('/\A127\.0\.0\.1:([1-9][0-9]{0,4})\n\z/D', $registryPort, $port) !== 1
            || (int) $port[1] > 65535) {
            throw new RuntimeException('local proof registry did not receive one loopback ephemeral port');
        }
        $proofRepository = '127.0.0.1:' . $port[1] . '/duo-cloud-preview-' . $proofId;
        $proofTag = $proofRepository . ':proof';
        $journal['proof_tag'] = $proofTag;
        publishRuntimeImageProofJournal($authority['root'], $journal);
        mustRun($runner, [$docker, 'image', 'tag', $image, $proofTag], 'local proof image tag');
        $pushed = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $push = $runner->run([$docker, 'image', 'push', '--quiet', $proofTag]);
            if ($push['exit'] === 0) {
                $pushed = true;
                break;
            }
            usleep(250000);
        }
        if (!$pushed) {
            throw new RuntimeException('local proof image could not be published immutably');
        }
        $digestInspection = json_decode(mustRun($runner, [
            $docker, 'image', 'inspect', '--format', '{{json .RepoDigests}}', $proofTag,
        ], 'local proof image digest inspection')['stdout'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($digestInspection) || !array_is_list($digestInspection)) {
            throw new RuntimeException('local proof image digest inspection is invalid');
        }
        $foundRuntimeImage = null;
        foreach ($digestInspection as $digestReference) {
            if (is_string($digestReference)
                && preg_match(
                    '/\A' . preg_quote($proofRepository, '/') . '@sha256:[a-f0-9]{64}\z/D',
                    $digestReference
                ) === 1) {
                $foundRuntimeImage = $digestReference;
                break;
            }
        }
        if (!is_string($foundRuntimeImage)) {
            throw new RuntimeException('local proof image has no exact immutable repository digest');
        }
        $runtimeImage = $foundRuntimeImage;
    }
    $immutableInspection = jsonObject(mustRun($runner, [
        $docker, 'image', 'inspect', '--format', '{{json .}}', $runtimeImage,
    ], 'immutable image inspection')['stdout'], 'immutable image inspection');
    if (($immutableInspection['Id'] ?? null) !== ($inspection['Id'] ?? null)) {
        throw new RuntimeException('immutable proof image differs from the reviewed local build');
    }

    foreach ([$databaseVolume, $filesystemVolume] as $volume) {
        mustRun($runner, [
            $docker, 'volume', 'create', '--label', 'duo.cloud.local-image-proof=' . $proofId,
            $volume,
        ], 'proof volume creation');
    }
    $volumeOwnership = mustRun($runner, [
        $docker, 'run', '--rm', '--name', $helperContainer,
        '--label', 'duo.cloud.local-image-proof=' . $proofId,
        '--user', '10001:10001', '--entrypoint', '/usr/bin/stat',
        '--mount', 'type=volume,source=' . $databaseVolume . ',target=/var/lib/duo/database',
        '--mount', 'type=volume,source=' . $filesystemVolume . ',target=/var/lib/duo/wordpress',
        $image, '--format=%u:%g:%a', '/var/lib/duo/database', '/var/lib/duo/wordpress',
    ], 'fresh volume ownership')['stdout'];
    if ($volumeOwnership !== "10001:10001:700\n10001:10001:700\n") {
        throw new RuntimeException('fresh named volumes did not inherit private workload ownership');
    }

    acquireRuntimeImageProofDirectory($scratch, $scratchOwned);
    acquireRuntimeImageProofDirectory($secretRoot, $secretRootOwned);
    foreach ([
        'database-password' => 32,
        'wordpress-auth-key' => 48,
        'wordpress-auth-salt' => 48,
    ] as $name => $length) {
        $secret = rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=') . "\n";
        if (file_put_contents($secretRoot . '/' . $name, $secret, LOCK_EX) !== strlen($secret)
            || !chmod($secretRoot . '/' . $name, 0600)) {
            throw new RuntimeException('proof secret could not be created');
        }
    }
    // This models a root service deployment. A uid=10001 service creates the
    // same ownership directly; the runtime refuses if it cannot seal it.
    mustRun($runner, [
        $docker, 'run', '--rm', '--name', $helperContainer,
        '--label', 'duo.cloud.local-image-proof=' . $proofId,
        '--user', '0:0', '--entrypoint', '/bin/chown',
        '--mount', 'type=bind,source=' . $secretRoot . ',target=/proof-secrets',
        $image, '-R', '10001:10001', '/proof-secrets',
    ], 'root credential ownership handoff');

    $configurationSha256 = hash('sha256', 'image-proof-configuration');
    $reviewedBaseSha256 = hash('sha256', 'image-proof-reviewed-base');
    mustRun($runner, [
        $docker, 'container', 'create', '--name', $container, '--user', '10001:10001',
        '--label', 'duo.cloud.local-image-proof=' . $proofId,
        '--label', 'duo.cloud.configuration-sha256=' . $configurationSha256,
        '--label', 'duo.cloud.lease-generation=1',
        '--label', 'duo.cloud.resource-id=' . $resourceId,
        '--label', 'duo.cloud.reviewed-base-sha256=' . $reviewedBaseSha256,
        '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges=true',
        '--security-opt', 'seccomp=' . $seccompProfile,
        '--network', 'none', '--pids-limit', '256', '--memory', '536870912',
        '--memory-swap', '536870912', '--shm-size', '67108864',
        '--ulimit', 'nofile=4096:4096',
        '--tmpfs', '/run:rw,noexec,nosuid,nodev,size=16777216,mode=0700,uid=10001,gid=10001',
        '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=67108864,mode=0700,uid=10001,gid=10001',
        '--mount', 'type=volume,source=' . $databaseVolume . ',target=/var/lib/duo/database',
        '--mount', 'type=volume,source=' . $filesystemVolume . ',target=/var/lib/duo/wordpress',
        '--mount', 'type=bind,source=' . $secretRoot . ',target=/run/secrets/duo,readonly',
        '--entrypoint', '/opt/duo/bin/duo-preview-runtime', $runtimeImage,
        'serve', '--clean-base', '--config-sha256', $configurationSha256,
        '--lease-generation', '1', '--reviewed-base-sha256', $reviewedBaseSha256,
    ], 'runtime proof container creation');
    mustRun($runner, [$docker, 'container', 'start', $container], 'runtime proof container start');

    $containmentInspection = jsonObject(mustRun($runner, [
        $docker, 'container', 'inspect', '--format', '{{json .}}', $container,
    ], 'runtime containment inspection')['stdout'], 'runtime containment inspection');
    $host = $containmentInspection['HostConfig'] ?? null;
    $seccompProfileBytes = file_get_contents($seccompProfile);
    if (!is_array($host) || !is_string($seccompProfileBytes)
        || !WorkloadSecurityInspection::matches($containmentInspection, $seccompProfileBytes)
        || ($host['Memory'] ?? null) !== 536870912
        || ($host['MemorySwap'] ?? null) !== 536870912
        || ($host['ShmSize'] ?? null) !== 67108864
        || ($host['Ulimits'] ?? null) !== [['Name' => 'nofile', 'Hard' => 4096, 'Soft' => 4096]]) {
        throw new RuntimeException('runtime containment inspect differs from pinned resource policy');
    }

    $status = null;
    for ($attempt = 0; $attempt < 240; $attempt++) {
        $candidate = $runner->run([
            $docker, 'container', 'exec', '--user', '10001:10001', $container,
            '/opt/duo/bin/runtime-status', '--format', 'duo-cloud-preview-runtime-status/v1',
            '--config-sha256', $configurationSha256, '--lease-generation', '1',
            '--reviewed-base-sha256', $reviewedBaseSha256,
        ]);
        if ($candidate['exit'] === 0 && $candidate['stderr'] === '') {
            $candidateStatus = jsonObject($candidate['stdout'], 'runtime status');
            if (($candidateStatus['ready'] ?? null) === true) {
                $status = $candidateStatus;
                break;
            }
        }
        usleep(250000);
    }
    if (!is_array($status) || ($status['ready'] ?? null) !== true
        || ($status['clean_base'] ?? null) !== true) {
        $logs = $runner->run([$docker, 'container', 'logs', $container]);
        $failedState = $runner->run([
            $docker, 'container', 'inspect', '--format', '{{json .State}}', $container,
        ]);
        $children = $runner->run([
            $docker, 'container', 'exec', '--user', '10001:10001', $container,
            '/usr/local/bin/php', '-r',
            '$state="/var/lib/duo/database/runtime-state.json";'
                . 'if(is_file($state))echo "state:",(string)file_get_contents($state);'
                . 'foreach(glob("/run/duo/*")?:[] as $p){if(is_file($p))echo basename($p),":",'
                . 'substr((string)file_get_contents($p),0,256),"\n";}'
                . 'foreach(glob("/proc/[0-9]*/cmdline")?:[] as $p){$s=(string)@file_get_contents($p);'
                . 'if($s!=="")echo "process:",str_replace("\0"," ",$s),"\n";}',
        ]);
        throw new RuntimeException(
            'runtime did not become ready: '
                . substr(
                    $logs['stdout'] . $logs['stderr'] . $failedState['stdout']
                        . $children['stdout'] . $children['stderr'],
                    0,
                    2048
                )
        );
    }
    $seccomp = mustRun($runner, [
        $docker, 'container', 'exec', '--user', '10001:10001', $container,
        '/bin/sh', '-ceu',
        <<<'SH'
grep -Eq '^CapEff:[[:space:]]+0+$' /proc/self/status
grep -Eq '^NoNewPrivs:[[:space:]]+1$' /proc/self/status
grep -Eq '^Seccomp:[[:space:]]+2$' /proc/self/status
/opt/duo/bin/containment-canary /tmp/duo-seccomp-canary
SH,
    ], 'runtime seccomp ioctl behavior')['stdout'];
    if ($seccomp !== "duo-cloud-seccomp-ioctl-canary/v1\n") {
        throw new RuntimeException('runtime seccomp ioctl canary output is invalid');
    }
    mustRun($runner, [
        $docker, 'container', 'exec', '--user', '10001:10001', $container,
        '/usr/local/bin/php', '-r',
        '$s=file_get_contents("/var/lib/duo/wordpress/wp-config.php");'
            . 'foreach(["define(\'DISABLE_WP_CRON\', true);","define(\'DISALLOW_FILE_MODS\', true);",'
            . '"define(\'WP_ENVIRONMENT_TYPE\', \'staging\');"] as $n){if(!str_contains($s,$n))exit(2);}'
            . 'foreach(["database-password","wordpress-auth-key","wordpress-auth-salt"] as $n)'
            . '{if(!is_readable("/run/secrets/duo/$n"))exit(3);}echo "ok\n";',
    ], 'runtime fixed WordPress and credential policy');
    $http = mustRun($runner, [
        $docker, 'container', 'exec', '--user', '10001:10001', $container,
        '/usr/local/bin/php', '-r',
        '$b=file_get_contents("http://127.0.0.1:8080/");if(!is_string($b)||$b==="")exit(2);echo "ok\n";',
    ], 'runtime loopback HTTP')['stdout'];
    if ($http !== "ok\n") {
        throw new RuntimeException('runtime HTTP proof output is invalid');
    }
    $health = mustRun($runner, [
        $docker, 'container', 'exec', '--user', '10001:10001', $container,
        '/usr/local/bin/php', '-r',
        '$b=file_get_contents("http://127.0.0.1:8080/__duo/health");'
            . 'if($b!=="duo-cloud-preview-runtime-health/v1\n")exit(2);echo $b;',
    ], 'immutable runtime health endpoint')['stdout'];
    if ($health !== "duo-cloud-preview-runtime-health/v1\n") {
        throw new RuntimeException('immutable runtime health endpoint output is invalid');
    }
    $operationId = 'operation-' . $proofId;
    $environmentIdentity = 'environment-' . $proofId;
    $leaseId = 'lease-' . $proofId;
    $mutationId = 'mutation-' . $proofId;
    $ownershipReceipt = hash('sha256', 'ownership-' . $proofId);
    $mutationReceipt = hash('sha256', 'mutation-' . $proofId);
    $target = [
        'environment_identity' => $environmentIdentity,
        'lease_generation' => 1,
        'lease_id' => $leaseId,
        'mutation_generation' => 1,
        'mutation_id' => $mutationId,
        'mutation_owner' => 'duo-env-materialize-' . $operationId,
        'mutation_receipt_sha256' => $mutationReceipt,
        'ownership_receipt_sha256' => $ownershipReceipt,
        'resource_id' => $resourceId,
    ];
    $manifest = [
        'configuration_sha256' => $configurationSha256,
        'container_name' => $container,
        'environment_identity' => $environmentIdentity,
        'execution_state' => 'running',
        'format' => 'duo-cloud-container-workload-state/v1',
        'image' => $runtimeImage,
        'last_mutation' => [
            'generation' => 1,
            'id' => $mutationId,
            'owner' => 'duo-env-materialize-' . $operationId,
            'receipt_sha256' => $mutationReceipt,
        ],
        'lease_generation' => 1,
        'lease_id' => $leaseId,
        'ownership_receipt_sha256' => $ownershipReceipt,
        'resource_id' => $resourceId,
        'reviewed_base_sha256' => $reviewedBaseSha256,
        'site_id' => $siteId,
        'state' => 'present',
        'tenant_id' => $tenantId,
    ];
    $slotsRoot = $scratch . '/slots';
    $slotRoot = $slotsRoot . '/' . $resourceToken;
    if (!mkdir($slotsRoot, 0700) || !chmod($slotsRoot, 0700)
        || !mkdir($slotRoot, 0700) || !chmod($slotRoot, 0700)) {
        throw new RuntimeException('command-runner proof state directories could not be sealed');
    }
    writePrivateFile($slotRoot . '/active.json', CanonicalJson::encode($manifest) . "\n");

    $beforeCommand = jsonObject(mustRun($runner, [
        $docker, 'container', 'inspect', '--format', '{{json .State}}', $container,
    ], 'pre-command process inspection')['stdout'], 'pre-command process inspection');
    $marker = '/var/lib/duo/wordpress/delayed-marker-' . $proofId;
    $pidFile = '/var/lib/duo/wordpress/orphan-pid-' . $proofId;
    $orphanIdentity = 'duo-orphan-' . $proofId;
    $backgroundScript = '/usr/bin/setsid /bin/sh -c '
        . escapeshellarg('sleep 3; touch ' . $marker) . ' ' . escapeshellarg($orphanIdentity)
        . " >/dev/null 2>&1 &\nprintf '%s\\n' \"\$!\" > " . $pidFile . "\necho returned\n";
    $request = [
        'action' => 'raw',
        'input' => ['script' => $backgroundScript],
        'operation_id' => $operationId,
        'request_id' => hash('sha256', $backgroundScript),
        'site_id' => $siteId,
        'target' => $target,
        'tenant_id' => $tenantId,
    ];
    $commandRunner = new ContainerCommandRunner(
        $runner,
        $docker,
        $runtimeImage,
        $configurationSha256,
        $reviewedBaseSha256,
        $scratch,
        300
    );
    $background = $commandRunner->run($request);
    if ($background !== ['exit' => 0, 'stderr' => '', 'stdout' => "returned\n"]) {
        throw new RuntimeException('real command-runner background-command result is invalid');
    }
    $afterCommand = jsonObject(mustRun($runner, [
        $docker, 'container', 'inspect', '--format', '{{json .State}}', $container,
    ], 'post-command process inspection')['stdout'], 'post-command process inspection');
    if (($afterCommand['Running'] ?? null) !== true
        || ($afterCommand['Pid'] ?? null) === ($beforeCommand['Pid'] ?? null)
        || ($afterCommand['StartedAt'] ?? null) === ($beforeCommand['StartedAt'] ?? null)) {
        throw new RuntimeException('real command runner did not establish a new process generation');
    }
    usleep(4000000);
    $orphanResult = $runner->run([
        $docker, 'container', 'exec', '--user', '10001:10001', $container,
        '/usr/local/bin/php', '-r',
        '$marker=' . var_export($marker, true) . ';$pidFile=' . var_export($pidFile, true)
            . ';$identity=' . var_export($orphanIdentity, true) . ';'
            . 'if(file_exists($marker))exit(2);$pid=trim((string)file_get_contents($pidFile));'
            . 'if(!ctype_digit($pid)||$pid==="0")exit(3);$self=(string)getmypid();'
            . 'foreach(glob("/proc/[0-9]*/cmdline")?:[] as $path)'
            . '{if(basename(dirname($path))===$self)continue;$bytes=(string)@file_get_contents($path);'
            . 'if(str_contains($bytes,$identity))exit(4);}'
            . 'echo "dead:",$pid,"\n";',
    ]);
    if ($orphanResult['exit'] !== 0 || $orphanResult['stderr'] !== '') {
        throw new RuntimeException(
            'real command-runner orphan PID and delayed-write absence failed at '
                . $orphanResult['exit']
        );
    }
    $orphanProof = $orphanResult['stdout'];
    if (preg_match('/\Adead:[1-9][0-9]*\n\z/D', $orphanProof) !== 1) {
        throw new RuntimeException('real command-runner orphan PID proof is invalid');
    }
    $proof = [
        'background_process_fence' => 'real-runner-kill-dead-restart-ready-no-orphan-pid-or-write',
        'format' => 'duo-cloud-runtime-image-proof/v1',
        'fresh_volume_ownership' => '10001:10001:0700',
        'helpers' => 'executable',
        'immutable_health' => 'duo-cloud-preview-runtime-health/v1',
        'image_id' => $inspection['Id'],
        'immutable_image' => $runtimeImage,
        'implicit_volumes' => 0,
        'runtime_ready' => true,
        'seccomp_ioctl_policy' => 'native-compat-project-mutation-denied-control-allowed',
        'seccomp_profile_sha256' => hash_file('sha256', $seccompProfile),
        'secrets_readable_as' => '10001:10001',
        'wordpress_policy' => 'staging-cron-and-file-mods-disabled',
    ];
} catch (Throwable $error) {
    $primary = $error;
}

try {
    $proof = finalizeRuntimeImageProof(
        $proof,
        $primary,
        static function () use (
            $runner,
            $authority,
            $journal
        ): void {
            if ($runner instanceof NativeContainerArgvProcessRunner
                && is_array($authority) && is_array($journal)) {
                cleanupRuntimeImageProofJournal($runner, $journal);
                clearRuntimeImageProofJournal($authority['root']);
            }
        }
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'verify-runtime-image: ' . $error->getMessage() . "\n");
    exit(1);
}
if (is_array($authority) && is_resource($authority['lock'] ?? null)) {
    flock($authority['lock'], LOCK_UN);
    fclose($authority['lock']);
}
fwrite(STDOUT, CanonicalJson::encode($proof) . "\n");
exit(0);

/**
 * Cleanup is part of the proof, not a best-effort epilogue. A caller may
 * publish only the returned document; any verification or cleanup refusal
 * therefore produces no success receipt.
 *
 * @param array<string,mixed>|null $proof
 * @param callable():void $cleanup
 * @return array<string,mixed>
 */
function finalizeRuntimeImageProof(
    ?array $proof,
    ?Throwable $primary,
    callable $cleanup
): array {
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        throw new RuntimeException(
            'runtime image verifier could not prove exact cleanup',
            0,
            $cleanupError
        );
    }
    if ($primary !== null) {
        throw $primary;
    }
    if (!is_array($proof)) {
        throw new RuntimeException('runtime image verifier produced no proof evidence');
    }
    $proof['cleanup'] = 'exact';
    $proof['proof_receipt_sha256'] = hash(
        'sha256',
        "duo-cloud-runtime-image-proof-receipt/v1\0" . CanonicalJson::encode($proof)
    );
    return $proof;
}

/** @param list<callable():mixed> $operations */
function runRuntimeImageCleanupOperations(array $operations): void {
    $first = null;
    foreach ($operations as $operation) {
        try {
            $operation();
        } catch (Throwable $error) {
            $first ??= $error;
        }
    }
    if ($first instanceof Throwable) {
        throw new RuntimeException('runtime image verifier cleanup operations refused', 0, $first);
    }
}

/**
 * Ownership becomes cleanup authority at mkdir success, before sealing can
 * refuse. The optional callbacks exist only for the deterministic prior-defect test.
 *
 * @param callable(string,int):bool|null $make
 * @param callable(string,int):bool|null $seal
 */
function acquireRuntimeImageProofDirectory(
    string $path,
    bool &$owned,
    ?callable $make = null,
    ?callable $seal = null
): void {
    $make ??= static fn (string $candidate, int $mode): bool => mkdir($candidate, $mode);
    $seal ??= static fn (string $candidate, int $mode): bool => chmod($candidate, $mode);
    if (!$make($path, 0700)) {
        throw new RuntimeException('proof private directories could not be created');
    }
    $owned = true;
    if (!$seal($path, 0700)) {
        throw new RuntimeException('proof private directories could not be created');
    }
}

function runtimeImageProofDurableStateRoot(string $path): bool {
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
        || str_contains($path, '//') || str_ends_with($path, '/')
        || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
        || basename($path) !== 'runtime-image-verifier') {
        return false;
    }
    $canonicalParent = realpath(dirname($path));
    if (!is_string($canonicalParent)) {
        return false;
    }
    $canonicalPath = rtrim($canonicalParent, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . basename($path);
    if ($canonicalPath !== $path) {
        return false;
    }
    $ephemeral = [
        '/run', '/tmp', '/var/run', '/var/tmp',
        '/private/tmp', '/private/var/run', '/private/var/tmp',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
    ];
    $forbidden = [];
    foreach ($ephemeral as $root) {
        $forbidden[] = $root;
        $canonicalRoot = realpath($root);
        if (is_string($canonicalRoot)) {
            $forbidden[] = rtrim($canonicalRoot, DIRECTORY_SEPARATOR);
        }
    }
    foreach (array_unique($forbidden) as $root) {
        if ($canonicalPath === $root || str_starts_with($canonicalPath, $root . '/')) {
            return false;
        }
    }
    return true;
}

/** @return array{lock:resource,root:string} */
function acquireRuntimeImageProofAuthority(string $authorityRoot): array {
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    if (!is_int($uid) || $uid < 0) {
        throw new RuntimeException('runtime image proof authority owner is invalid');
    }
    if (!runtimeImageProofDurableStateRoot($authorityRoot)) {
        throw new RuntimeException('runtime image proof durable authority path is invalid');
    }
    $parent = dirname($authorityRoot);
    $canonicalParent = realpath($parent);
    if (!is_string($canonicalParent) || $canonicalParent !== $parent) {
        throw new RuntimeException('runtime image proof authority parent is not canonical');
    }
    runtimeImageProofAssertPrivateDirectory($parent);
    clearstatcache(true, $authorityRoot);
    $acquired = false;
    if (@lstat($authorityRoot) === false) {
        $previousUmask = umask(0077);
        try {
            $created = mkdir($authorityRoot, 0700);
        } finally {
            umask($previousUmask);
        }
        if (!$created) {
            throw new RuntimeException('runtime image proof authority root could not be created');
        }
        $acquired = true;
    }
    if ($acquired && !chmod($authorityRoot, 0700)) {
        throw new RuntimeException('runtime image proof authority root could not be sealed');
    }
    runtimeImageProofAssertPrivateDirectory($authorityRoot);
    $canonicalRoot = realpath($authorityRoot);
    if (!is_string($canonicalRoot) || $canonicalRoot !== $authorityRoot) {
        throw new RuntimeException('runtime image proof authority root is not canonical');
    }
    $lockPath = $canonicalRoot . '/authority.lock';
    clearstatcache(true, $lockPath);
    if (@lstat($lockPath) !== false) {
        runtimeImageProofAssertPrivateFile($lockPath, 'authority lock');
    }
    $previousUmask = umask(0077);
    try {
        $lock = @fopen($lockPath, 'c+b');
    } finally {
        umask($previousUmask);
    }
    if (!is_resource($lock) || !@chmod($lockPath, 0600)
        || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('runtime image proof authority is busy');
    }
    runtimeImageProofAssertPrivateFile($lockPath, 'authority lock', $lock);
    return ['lock' => $lock, 'root' => $canonicalRoot];
}

function assertNoUnjournaledRuntimeImageProofResources(
    ContainerArgvProcessRunner $runner,
    string $docker,
    string $proofId,
    string $container,
    string $registryContainer,
    string $helperContainer,
    string $databaseVolume,
    string $filesystemVolume,
    string $scratch,
    string $secretRoot
): void {
    proofDockerIdentity($docker, $proofId);
    foreach ([$container, $registryContainer, $helperContainer] as $name) {
        if (proofDockerList($runner, [
            $docker, 'container', 'ls', '--all', '--filter', 'name=^/' . $name . '$',
            '--format', '{{.Names}}',
        ], 'unjournaled runtime image proof container readback') !== []) {
            throw new RuntimeException('runtime image verifier found unjournaled Docker state');
        }
    }
    foreach ([$databaseVolume, $filesystemVolume] as $name) {
        if (proofDockerList($runner, [
            $docker, 'volume', 'ls', '--filter', 'name=^' . $name . '$',
            '--format', '{{.Name}}',
        ], 'unjournaled runtime image proof volume readback') !== []) {
            throw new RuntimeException('runtime image verifier found unjournaled Docker state');
        }
    }
    foreach ([$scratch, $secretRoot] as $path) {
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            throw new RuntimeException('runtime image verifier found unjournaled private state');
        }
    }
}

/** @return array<string,mixed> */
function runtimeImageProofJournal(
    string $docker,
    string $proofId,
    string $proofImageId,
    string $container,
    string $registryContainer,
    string $helperContainer,
    string $databaseVolume,
    string $filesystemVolume,
    string $scratch,
    string $secretRoot
): array {
    $journal = [
        'container' => $container,
        'database_volume' => $databaseVolume,
        'docker' => $docker,
        'filesystem_volume' => $filesystemVolume,
        'format' => 'duo-cloud-runtime-image-verifier-journal/v1',
        'helper_container' => $helperContainer,
        'proof_id' => $proofId,
        'proof_image_id' => $proofImageId,
        'proof_tag' => null,
        'registry_container' => $registryContainer,
        'scratch' => $scratch,
        'secret_root' => $secretRoot,
    ];
    validateRuntimeImageProofJournal($journal);
    return $journal;
}

/** @param array<string,mixed> $journal */
function publishRuntimeImageProofJournal(string $authorityRoot, array $journal): void {
    runtimeImageProofAssertPrivateDirectory($authorityRoot);
    validateRuntimeImageProofJournal($journal);
    $path = $authorityRoot . '/active.json';
    $temporary = $path . '.tmp';
    clearstatcache(true, $path);
    if (@lstat($path) !== false) {
        runtimeImageProofAssertPrivateFile($path, 'active journal');
    }
    clearstatcache(true, $temporary);
    if (@lstat($temporary) !== false) {
        throw new RuntimeException('runtime image proof journal temporary file is unreconciled');
    }
    $bytes = CanonicalJson::encode($journal) . "\n";
    $previousUmask = umask(0077);
    try {
        $handle = @fopen($temporary, 'x+b');
    } finally {
        umask($previousUmask);
    }
    try {
        if (!is_resource($handle) || !@chmod($temporary, 0600)) {
            throw new RuntimeException('runtime image proof journal temporary file could not be created');
        }
        runtimeImageProofAssertPrivateFile($temporary, 'journal temporary file', $handle);
        runtimeImageProofWriteAll($handle, $bytes);
        if (!@fflush($handle) || (function_exists('fsync') && !@fsync($handle))
            || !fclose($handle)) {
            $handle = null;
            throw new RuntimeException('runtime image proof journal temporary file could not be synchronized');
        }
        $handle = null;
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('runtime image proof journal could not be published');
        }
        runtimeImageProofSyncDirectory($authorityRoot);
    } catch (Throwable $error) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw $error;
    }
}

function recoverRuntimeImageProofJournal(
    ContainerArgvProcessRunner $runner,
    string $authorityRoot
): void {
    runtimeImageProofAssertPrivateDirectory($authorityRoot);
    $entries = scandir($authorityRoot);
    if (!is_array($entries)) {
        throw new RuntimeException('runtime image proof authority namespace could not be read');
    }
    foreach ($entries as $entry) {
        if (!in_array($entry, ['.', '..', 'active.json', 'active.json.tmp', 'authority.lock'], true)) {
            throw new RuntimeException('runtime image proof authority namespace contains unknown state');
        }
    }
    $temporary = $authorityRoot . '/active.json.tmp';
    clearstatcache(true, $temporary);
    if (@lstat($temporary) !== false) {
        runtimeImageProofAssertPrivateFile($temporary, 'stale journal temporary file');
        if (!@unlink($temporary)) {
            throw new RuntimeException('stale runtime image proof journal could not be removed');
        }
        runtimeImageProofSyncDirectory($authorityRoot);
    }
    $path = $authorityRoot . '/active.json';
    clearstatcache(true, $path);
    if (@lstat($path) === false) {
        return;
    }
    $journal = readRuntimeImageProofJournal($path);
    cleanupRuntimeImageProofJournal($runner, $journal);
    clearRuntimeImageProofJournal($authorityRoot);
}

/** @param array<string,mixed> $journal */
function cleanupRuntimeImageProofJournal(
    ContainerArgvProcessRunner $runner,
    array $journal
): void {
    validateRuntimeImageProofJournal($journal);
    $docker = $journal['docker'];
    $proofId = $journal['proof_id'];
    $operations = [
        static fn (): mixed => removeProofContainer(
            $runner,
            $docker,
            $journal['container'],
            $proofId
        ),
        static fn (): mixed => removeProofContainer(
            $runner,
            $docker,
            $journal['helper_container'],
            $proofId
        ),
        static fn (): mixed => removeProofVolume(
            $runner,
            $docker,
            $journal['database_volume'],
            $proofId
        ),
        static fn (): mixed => removeProofVolume(
            $runner,
            $docker,
            $journal['filesystem_volume'],
            $proofId
        ),
    ];
    if (is_string($journal['proof_tag'])) {
        $operations[] = static fn (): mixed => removeProofImageTag(
            $runner,
            $docker,
            $journal['proof_tag'],
            $journal['proof_image_id']
        );
    }
    // The tag must be absent before its ephemeral registry identity can be
    // removed; the durable journal still retains both if any later step fails.
    $operations[] = static fn (): mixed => removeProofContainer(
        $runner,
        $docker,
        $journal['registry_container'],
        $proofId
    );
    $operations[] = static fn (): mixed => removeProofDirectory($journal['scratch']);
    $operations[] = static fn (): mixed => removeProofDirectory($journal['secret_root']);
    runRuntimeImageCleanupOperations($operations);
}

function clearRuntimeImageProofJournal(string $authorityRoot): void {
    runtimeImageProofAssertPrivateDirectory($authorityRoot);
    $path = $authorityRoot . '/active.json';
    clearstatcache(true, $path);
    if (@lstat($path) === false) {
        return;
    }
    runtimeImageProofAssertPrivateFile($path, 'active journal');
    if (!@unlink($path)) {
        throw new RuntimeException('runtime image proof journal could not be retired');
    }
    clearstatcache(true, $path);
    if (@lstat($path) !== false) {
        throw new RuntimeException('runtime image proof journal remained after cleanup');
    }
    runtimeImageProofSyncDirectory($authorityRoot);
}

/** @return array<string,mixed> */
function readRuntimeImageProofJournal(string $path): array {
    runtimeImageProofAssertPrivateFile($path, 'active journal');
    $before = @lstat($path);
    $bytes = @file_get_contents($path);
    clearstatcache(true, $path);
    $after = @lstat($path);
    if (!is_array($before) || !is_array($after) || !runtimeImageProofSameFile($before, $after)
        || !is_string($bytes) || $bytes === '' || strlen($bytes) > 16384
        || !str_ends_with($bytes, "\n")) {
        throw new RuntimeException('runtime image proof journal could not be read exactly');
    }
    $journal = CanonicalJson::decodeObject($bytes, 16384);
    if ($bytes !== CanonicalJson::encode($journal) . "\n") {
        throw new RuntimeException('runtime image proof journal is not canonical');
    }
    validateRuntimeImageProofJournal($journal);
    return $journal;
}

/** @param array<string,mixed> $journal */
function validateRuntimeImageProofJournal(array $journal): void {
    $keys = array_keys($journal);
    sort($keys, SORT_STRING);
    if ($keys !== [
        'container', 'database_volume', 'docker', 'filesystem_volume', 'format',
        'helper_container', 'proof_id', 'proof_image_id', 'proof_tag',
        'registry_container', 'scratch', 'secret_root',
    ] || ($journal['format'] ?? null) !== 'duo-cloud-runtime-image-verifier-journal/v1') {
        throw new RuntimeException('runtime image proof journal schema is invalid');
    }
    $proofId = $journal['proof_id'] ?? null;
    if (!is_string($proofId) || preg_match('/\A[a-f0-9]{16}\z/D', $proofId) !== 1) {
        throw new RuntimeException('runtime image proof journal identity is invalid');
    }
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    $expectedProofId = substr(
        hash('sha256', "duo-cloud-runtime-image-verifier-authority/v1\0" . $uid),
        0,
        16
    );
    $tenantId = 'tenant-' . $proofId;
    $siteId = 'site-' . $proofId;
    $resourceId = 'cloud-slot-' . hash(
        'sha256',
        "duo-cloud-preview-physical-slot/v1\0{$tenantId}\0{$siteId}"
    );
    $container = 'duo-preview-' . substr($resourceId, strlen('cloud-slot-'))
        . '-g0000000001';
    $expected = [
        'container' => $container,
        'database_volume' => $container . '-database',
        'filesystem_volume' => $container . '-filesystem',
        'helper_container' => 'duo-cloud-proof-helper-' . $proofId,
        'registry_container' => 'duo-cloud-proof-registry-' . $proofId,
        'scratch' => sys_get_temp_dir() . '/duo-cloud-image-proof-' . $proofId,
        'secret_root' => sys_get_temp_dir() . '/duo-cloud-image-proof-' . $proofId . '-secrets',
    ];
    foreach ($expected as $key => $value) {
        if (($journal[$key] ?? null) !== $value) {
            throw new RuntimeException('runtime image proof journal resource identity is invalid');
        }
    }
    $docker = $journal['docker'] ?? null;
    $proofImageId = $journal['proof_image_id'] ?? null;
    $proofTag = $journal['proof_tag'] ?? null;
    if (!hash_equals($expectedProofId, $proofId)
        || !is_string($docker) || $docker === '' || $docker[0] !== '/'
        || str_contains($docker, "\0") || !is_file($docker) || !is_executable($docker)
        || !is_string($proofImageId)
        || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $proofImageId) !== 1
        || ($proofTag !== null && (!is_string($proofTag)
            || preg_match(
                '/\A127\.0\.0\.1:[1-9][0-9]{0,4}\/duo-cloud-preview-'
                    . preg_quote($proofId, '/') . ':proof\z/D',
                $proofTag
            ) !== 1))) {
        throw new RuntimeException('runtime image proof journal authority is invalid');
    }
}

function runtimeImageProofAssertPrivateDirectory(string $path): void {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
        || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
        || (int) ($stat['uid'] ?? -1) !== $uid) {
        throw new RuntimeException('runtime image proof authority root is not private');
    }
}

/** @param resource|null $handle */
function runtimeImageProofAssertPrivateFile(string $path, string $label, $handle = null): void {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    $opened = is_resource($handle) ? fstat($handle) : $stat;
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    if (!is_array($stat) || !is_array($opened) || is_link($path)
        || ($stat['mode'] & 0170000) !== 0100000
        || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0600)
        || (int) ($stat['uid'] ?? -1) !== $uid || (int) ($stat['nlink'] ?? -1) !== 1
        || !runtimeImageProofSameFile($stat, $opened)) {
        throw new RuntimeException("runtime image proof $label is not a private regular file");
    }
}

/** @param array<string,mixed> $left @param array<string,mixed> $right */
function runtimeImageProofSameFile(array $left, array $right): bool {
    return (int) ($left['dev'] ?? -1) === (int) ($right['dev'] ?? -2)
        && (int) ($left['ino'] ?? -1) === (int) ($right['ino'] ?? -2)
        && (int) ($left['size'] ?? -1) === (int) ($right['size'] ?? -2);
}

/** @param resource $handle */
function runtimeImageProofWriteAll($handle, string $bytes): void {
    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = fwrite($handle, substr($bytes, $offset));
        if (!is_int($written) || $written < 1) {
            throw new RuntimeException('runtime image proof journal write was incomplete');
        }
        $offset += $written;
    }
}

function runtimeImageProofSyncDirectory(string $directory): void {
    if (!function_exists('fsync')) {
        return;
    }
    $handle = @fopen($directory, 'rb');
    if (!is_resource($handle) || !@fsync($handle) || !fclose($handle)) {
        throw new RuntimeException('runtime image proof authority directory could not be synchronized');
    }
}

function removeProofContainer(
    ContainerArgvProcessRunner $runner,
    string $docker,
    string $name,
    string $proofId
): void {
    proofDockerIdentity($docker, $proofId);
    if (preg_match('/\Aduo-(?:preview-[a-f0-9]{64}-g[0-9]{10}|cloud-proof-(?:helper|registry)-[a-f0-9]{16})\z/D', $name) !== 1) {
        throw new RuntimeException('runtime image proof container cleanup identity is invalid');
    }
    $present = proofDockerList($runner, [
        $docker, 'container', 'ls', '--all', '--filter', 'name=^/' . $name . '$',
        '--format', '{{.Names}}',
    ], 'runtime image proof container cleanup readback');
    if ($present === []) {
        return;
    }
    if ($present !== [$name]) {
        throw new RuntimeException('runtime image proof container cleanup readback is ambiguous');
    }
    $inspection = proofDockerObject($runner, [
        $docker, 'container', 'inspect', '--format', '{{json .}}', $name,
    ], 'runtime image proof container cleanup inspection');
    if (($inspection['Name'] ?? null) !== '/' . $name
        || ($inspection['Config']['Labels']['duo.cloud.local-image-proof'] ?? null) !== $proofId) {
        throw new RuntimeException('runtime image proof container cleanup found foreign state');
    }
    $removed = $runner->run([$docker, 'container', 'rm', '--force', $name]);
    if ($removed['exit'] !== 0 || $removed['stderr'] !== ''
        || $removed['stdout'] !== $name . "\n") {
        throw new RuntimeException('runtime image proof container cleanup failed');
    }
    if (proofDockerList($runner, [
        $docker, 'container', 'ls', '--all', '--filter', 'name=^/' . $name . '$',
        '--format', '{{.Names}}',
    ], 'runtime image proof container terminal readback') !== []) {
        throw new RuntimeException('runtime image proof container remained after cleanup');
    }
}

function removeProofVolume(
    ContainerArgvProcessRunner $runner,
    string $docker,
    string $name,
    string $proofId
): void {
    proofDockerIdentity($docker, $proofId);
    if (preg_match('/\Aduo-preview-[a-f0-9]{64}-g[0-9]{10}-(?:database|filesystem)\z/D', $name) !== 1) {
        throw new RuntimeException('runtime image proof volume cleanup identity is invalid');
    }
    $present = proofDockerList($runner, [
        $docker, 'volume', 'ls', '--filter', 'name=^' . $name . '$', '--format', '{{.Name}}',
    ], 'runtime image proof volume cleanup readback');
    if ($present === []) {
        return;
    }
    if ($present !== [$name]) {
        throw new RuntimeException('runtime image proof volume cleanup readback is ambiguous');
    }
    $inspection = proofDockerObject($runner, [
        $docker, 'volume', 'inspect', '--format', '{{json .}}', $name,
    ], 'runtime image proof volume cleanup inspection');
    if (($inspection['Name'] ?? null) !== $name
        || ($inspection['Labels']['duo.cloud.local-image-proof'] ?? null) !== $proofId) {
        throw new RuntimeException('runtime image proof volume cleanup found foreign state');
    }
    $removed = $runner->run([$docker, 'volume', 'rm', $name]);
    if ($removed['exit'] !== 0 || $removed['stderr'] !== ''
        || $removed['stdout'] !== $name . "\n") {
        throw new RuntimeException('runtime image proof volume cleanup failed');
    }
    if (proofDockerList($runner, [
        $docker, 'volume', 'ls', '--filter', 'name=^' . $name . '$', '--format', '{{.Name}}',
    ], 'runtime image proof volume terminal readback') !== []) {
        throw new RuntimeException('runtime image proof volume remained after cleanup');
    }
}

function removeProofImageTag(
    ContainerArgvProcessRunner $runner,
    string $docker,
    string $tag,
    string $expectedImageId
): void {
    if ($docker === '' || $docker[0] !== '/' || str_contains($docker, "\0")
        || preg_match('/\A127\.0\.0\.1:[1-9][0-9]{0,4}\/duo-cloud-preview-[a-f0-9]{16}:proof\z/D', $tag) !== 1
        || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $expectedImageId) !== 1) {
        throw new RuntimeException('runtime image proof tag cleanup identity is invalid');
    }
    $present = proofDockerList($runner, [
        $docker, 'image', 'ls', '--no-trunc', '--filter', 'reference=' . $tag,
        '--format', '{{.Repository}}:{{.Tag}} {{.ID}}',
    ], 'runtime image proof tag cleanup readback');
    if ($present === []) {
        return;
    }
    if ($present !== [$tag . ' ' . $expectedImageId]) {
        throw new RuntimeException('runtime image proof tag cleanup found foreign state');
    }
    $removed = $runner->run([$docker, 'image', 'rm', $tag]);
    if ($removed['exit'] !== 0 || $removed['stderr'] !== '') {
        throw new RuntimeException('runtime image proof tag cleanup failed');
    }
    if (proofDockerList($runner, [
        $docker, 'image', 'ls', '--no-trunc', '--filter', 'reference=' . $tag,
        '--format', '{{.Repository}}:{{.Tag}} {{.ID}}',
    ], 'runtime image proof tag terminal readback') !== []) {
        throw new RuntimeException('runtime image proof tag remained after cleanup');
    }
}

function proofDockerIdentity(string $docker, string $proofId): void {
    if ($docker === '' || $docker[0] !== '/' || str_contains($docker, "\0")
        || preg_match('/\A[a-f0-9]{16}\z/D', $proofId) !== 1) {
        throw new RuntimeException('runtime image proof cleanup identity is invalid');
    }
}

/** @param non-empty-list<string> $argv @return list<string> */
function proofDockerList(
    ContainerArgvProcessRunner $runner,
    array $argv,
    string $label
): array {
    $result = $runner->run($argv);
    if ($result['exit'] !== 0 || $result['stderr'] !== '') {
        throw new RuntimeException($label . ' failed');
    }
    if ($result['stdout'] === '') {
        return [];
    }
    if (!str_ends_with($result['stdout'], "\n")) {
        throw new RuntimeException($label . ' is not line-terminated');
    }
    $lines = explode("\n", substr($result['stdout'], 0, -1));
    foreach ($lines as $line) {
        if ($line === '' || str_contains($line, "\0")) {
            throw new RuntimeException($label . ' is malformed');
        }
    }
    return $lines;
}

/** @param non-empty-list<string> $argv @return array<string,mixed> */
function proofDockerObject(
    ContainerArgvProcessRunner $runner,
    array $argv,
    string $label
): array {
    $result = $runner->run($argv);
    if ($result['exit'] !== 0 || $result['stderr'] !== ''
        || !str_ends_with($result['stdout'], "\n")) {
        throw new RuntimeException($label . ' failed');
    }
    try {
        $object = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException($label . ' is not JSON', 0, $error);
    }
    if (!is_array($object) || array_is_list($object)) {
        throw new RuntimeException($label . ' is not an object');
    }
    return $object;
}

/** @return array{exit:int,stderr:string,stdout:string} */
function mustRun(
    NativeContainerArgvProcessRunner $runner,
    array $argv,
    string $label,
    ?string $stdinFile = null
): array {
    $result = $runner->run($argv, $stdinFile);
    if ($result['exit'] !== 0 || $result['stderr'] !== '') {
        throw new RuntimeException($label . ' failed');
    }
    return $result;
}

/** @return array<string,mixed> */
function jsonObject(string $bytes, string $label): array {
    try {
        $decoded = json_decode(trim($bytes), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException($label . ' was not JSON', 0, $error);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException($label . ' did not return an object');
    }
    return $decoded;
}

function writePrivateFile(string $path, string $bytes): void {
    $handle = @fopen($path, 'x+b');
    if (!is_resource($handle)) {
        throw new RuntimeException('private proof file could not be created');
    }
    try {
        if (!chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes)
            || !fflush($handle) || function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('private proof file could not be synchronized');
        }
    } finally {
        fclose($handle);
    }
}

function removeProofDirectory(string $path): void {
    if (!str_starts_with($path, sys_get_temp_dir() . '/duo-cloud-image-proof-')
        || str_contains($path, "\0") || dirname($path) !== sys_get_temp_dir()) {
        throw new RuntimeException('runtime image proof directory cleanup identity is invalid');
    }
    clearstatcache(true, $path);
    if (@lstat($path) === false) {
        return;
    }
    if (!is_dir($path) || is_link($path)) {
        throw new RuntimeException('runtime image proof directory cleanup found foreign state');
    }
    removeProofEntry($path);
    clearstatcache(true, $path);
    if (@lstat($path) !== false) {
        throw new RuntimeException('runtime image proof directory remained after cleanup');
    }
}

function removeProofEntry(string $path): void {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) {
        return;
    }
    if (is_link($path) || (($stat['mode'] ?? 0) & 0170000) !== 0040000) {
        if (!@unlink($path)) {
            throw new RuntimeException('runtime image proof node could not be removed');
        }
        return;
    }
    $entries = @scandir($path);
    if (!is_array($entries)) {
        throw new RuntimeException('runtime image proof directory could not be read for cleanup');
    }
    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeProofEntry($path . '/' . $entry);
        }
    }
    if (!@rmdir($path)) {
        throw new RuntimeException('runtime image proof directory could not be removed');
    }
}
