<?php
/** Docker adoption keeps transfer/install/rollback on shared durable storage. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Transport/DockerTransport.php';
require_once __DIR__ . '/../../../../recovery/rollback-control.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/BootstrapEligibility.php';
require_once __DIR__ . '/../../../../cli/src/Transport/CodeDeploy.php';
require_once __DIR__ . '/../../../../cli/src/Onboarding/Adopt.php';

use WPrism\Orchestrator\Adopt;
use WPrism\Orchestrator\BootstrapEligibilityReport;
use WPrism\Orchestrator\DockerTransport;

$physicalTemp = realpath(sys_get_temp_dir());
if (!is_string($physicalTemp) || $physicalTemp === '' || $physicalTemp === '/') {
    throw new RuntimeException('could not resolve test temporary directory');
}
$root = $physicalTemp . '/wprism-docker-bootstrap-' . bin2hex(random_bytes(8));
$bin = $root . '/bin';
$source = dirname(__DIR__, 4);
$oldPath = (string) getenv('PATH');
$oldDockerHost = getenv('DOCKER_HOST');
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    }
    @rmdir($path);
};

try {
    mkdir($bin, 0700, true);
    $versionSource = file_get_contents($source . '/agent/wprism.php');
    if (!is_string($versionSource)
        || preg_match("/define\\(\\s*'WPRISM_AGENT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $versionSource, $match) !== 1) {
        throw new RuntimeException('could not resolve fixture agent version');
    }
    file_put_contents($bin . '/docker', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$WPRISM_DOCKER_BOOTSTRAP_LOG"
last=''; is_wp=0
for arg do last=$arg; [ "$arg" = wp ] && is_wp=1; done
if [ "$is_wp" -eq 1 ]; then exec "$WPRISM_DOCKER_BOOTSTRAP_WP" "$@"; fi
exec /bin/bash -c "$last"
SH
    , LOCK_EX);
    file_put_contents($bin . '/wp', <<<'SH'
#!/bin/sh
set -eu
case " $* " in
  *" core is-installed "*) exit 0 ;;
  *wprism-single-site*)
    printf '%s' 'wprism-single-site'
    [ "${WPRISM_DOCKER_BOOTSTRAP_TOPOLOGY_WARNING:-0}" = 0 ] || printf '%s' 'target WordPress warning' >&2
    exit 0
    ;;
  *WPRISM_BOOTSTRAP_WPMU_PLUGIN_DIR*) printf '%s' "$WPRISM_DOCKER_BOOTSTRAP_MU"; exit 0 ;;
  *WPRISM_AGENT_VERSION*) printf '%s' "$WPRISM_DOCKER_BOOTSTRAP_VERSION"; exit 0 ;;
  *wprism-policy-ok*) printf '%s' 'wprism-policy-ok'; exit 0 ;;
esac
printf '%s\n' "unexpected fake wp invocation: $*" >&2
exit 91
SH
    , LOCK_EX);
    chmod($bin . '/docker', 0700);
    chmod($bin . '/wp', 0700);
    putenv('PATH=' . $bin . ':' . $oldPath);
    putenv('DOCKER_HOST');
    putenv('WPRISM_DOCKER_BOOTSTRAP_WP=' . $bin . '/wp');
    putenv('WPRISM_DOCKER_BOOTSTRAP_VERSION=' . $match[1]);
    putenv('WPRISM_DOCKER_BOOTSTRAP_LOG=' . $root . '/docker.log');

    $run = static function (string $label, bool $doctorOk, bool $topologyWarning = false) use ($root, $source): array {
        $case = $root . '/' . $label;
        $wpRoot = $case . '/wordpress';
        $mu = $wpRoot . '/wp-content/mu-plugins';
        $repo = $case . '/repository-volume';
        mkdir($wpRoot . '/wp-content', 0700, true);
        mkdir($repo, 0700, true);
        file_put_contents($case . '/compose.yml', "services: {}\n");
        putenv('WPRISM_DOCKER_BOOTSTRAP_MU=' . $mu);
        putenv('WPRISM_DOCKER_BOOTSTRAP_TOPOLOGY_WARNING=' . ($topologyWarning ? '1' : '0'));
        $config = json_encode(['services' => [
            'cli' => ['volumes' => [
                ['type' => 'bind', 'source' => $wpRoot, 'target' => $wpRoot, 'read_only' => false],
                ['type' => 'bind', 'source' => $repo, 'target' => $repo, 'read_only' => false],
            ]],
            'wordpress' => ['volumes' => [
                ['type' => 'bind', 'source' => $wpRoot, 'target' => $wpRoot, 'read_only' => false],
            ]],
        ]], JSON_THROW_ON_ERROR);
        $control = static function (string $command) use ($config, $wpRoot): array {
            return match (true) {
                str_contains($command, "'context' 'inspect'") => ['exit' => 0, 'stdout' => '"unix:///var/run/docker.sock"', 'stderr' => ''],
                str_contains($command, "'config' '--format' 'json'") => ['exit' => 0, 'stdout' => $config, 'stderr' => ''],
                str_contains($command, "'ps' '--status=running' '--services'") => ['exit' => 0, 'stdout' => "wordpress\n", 'stderr' => ''],
                str_contains($command, "'ps' '-q' 'wordpress'") => ['exit' => 0, 'stdout' => str_repeat('b', 64), 'stderr' => ''],
                str_contains($command, "'config' '--hash' 'wordpress'") => ['exit' => 0, 'stdout' => 'wordpress ' . str_repeat('c', 64), 'stderr' => ''],
                str_contains($command, 'com.docker.compose.config-hash') => ['exit' => 0, 'stdout' => str_repeat('c', 64), 'stderr' => ''],
                str_contains($command, "'inspect' '--format' '{{json .Mounts}}'") => [
                    'exit' => 0,
                    'stdout' => json_encode([['Type' => 'bind', 'Source' => $wpRoot, 'Destination' => $wpRoot, 'RW' => true]], JSON_THROW_ON_ERROR),
                    'stderr' => '',
                ],
                default => ['exit' => 92, 'stdout' => '', 'stderr' => 'unexpected control-plane command'],
            };
        };
        $transport = new DockerTransport('docker-' . $label, [
            'transport' => 'docker', 'compose_file' => $case . '/compose.yml',
            'service' => 'cli', 'wordpress_service' => 'wordpress',
            'wp_path' => $wpRoot, 'repo_path' => $repo,
            'bootstrap' => ['format' => DockerTransport::BOOTSTRAP_FORMAT],
            'docker_context' => 'default', 'docker_endpoint' => 'unix:///var/run/docker.sock',
            '_machine_local' => true,
        ], null, $control);
        $authority = BootstrapEligibilityReport::initialRecoveryAuthority($transport, $source);
        if ($topologyWarning) {
            return compact('authority', 'repo', 'mu');
        }
        wprism_check($authority?->ready() === true, "$label empty mounted repository obtains explicit initial authority");
        wprism_check($authority?->initialRepositoryIdentity() !== null, "$label authority binds the pre-existing mount-root identity");
        $result = Adopt::install($transport, $source, null, null, null, $authority, static fn(): bool => $doctorOk);
        return compact('result', 'repo', 'mu');
    };

    $success = $run('success', true);
    wprism_check_same(0, $success['result']['exit'], 'Docker durable upload reaches the real adoption commit path');
    wprism_check(is_file($success['mu'] . '/wprism/wprism.php'), 'Docker adoption publishes the assembled agent on shared WordPress storage');
    wprism_check(is_file($success['repo'] . '/site.wprism.json'), 'Docker adoption seeds the durable mounted repository');
    wprism_check(glob($success['repo'] . '/.wprism-adopt-upload-*') === [], 'committed Docker adoption removes its identity-bound archive');

    $rollback = $run('rollback', false);
    wprism_check($rollback['result']['exit'] !== 0, 'post-swap verification failure refuses Docker adoption');
    wprism_check(is_dir($rollback['repo']), 'Docker rollback retains the pre-existing mounted repository root');
    wprism_check((scandir($rollback['repo']) ?: []) === ['.', '..'], 'Docker rollback restores the mounted repository to exact emptiness');
    wprism_check(!file_exists($rollback['mu']), 'Docker rollback removes the newly created control-plane root');
    wprism_check(glob($rollback['repo'] . '/.wprism-adopt-upload-*') === [], 'Docker rollback removes only its owned persistent archive');

    $warning = $run('topology-warning', true, true);
    wprism_check_same(null, $warning['authority'], 'authorized Docker topology still refuses target WordPress stderr');
    wprism_check((scandir($warning['repo']) ?: []) === ['.', '..'], 'topology warning refusal preserves the empty repository mount');
    wprism_check(!file_exists($warning['mu']), 'topology warning refusal publishes no control-plane bytes');
    $dockerLog = file_get_contents($root . '/docker.log');
    wprism_check(
        is_string($dockerLog) && str_contains($dockerLog, 'compose --progress quiet'),
        'authorized Docker commands suppress only Compose lifecycle progress at its source'
    );
} finally {
    putenv('PATH=' . $oldPath);
    is_string($oldDockerHost) ? putenv('DOCKER_HOST=' . $oldDockerHost) : putenv('DOCKER_HOST');
    putenv('WPRISM_DOCKER_BOOTSTRAP_WP');
    putenv('WPRISM_DOCKER_BOOTSTRAP_MU');
    putenv('WPRISM_DOCKER_BOOTSTRAP_VERSION');
    putenv('WPRISM_DOCKER_BOOTSTRAP_LOG');
    putenv('WPRISM_DOCKER_BOOTSTRAP_TOPOLOGY_WARNING');
    $remove($root);
}

wprism_check_summary('docker-bootstrap');
