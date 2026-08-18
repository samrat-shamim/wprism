<?php
/**
 * Product-path proof that the reusable reference slot composes through
 * EnvironmentCommand -> EnvironmentMaterializer -> CommandEnvironmentProvider.
 * Fake pair/docker/wp executables keep the complete lifecycle offline.
 *
 * usage: php reference-provider-command-checks.php <scratch-dir>
 */
declare(strict_types=1);

namespace Duo\Orchestrator {
    final class Refresh {
        /** @return array<string,mixed> */
        public static function rebase(EnvironmentDriver $driver, string $production, string $branch, array $resolution = []): array {
            $root = trim((string) shell_exec('git rev-parse --show-toplevel'));
            $head = trim((string) shell_exec('git rev-parse HEAD'));
            exec('git update-ref ' . escapeshellarg('refs/heads/' . $branch) . ' ' . escapeshellarg($head), $output, $exit);
            if ($exit !== 0) throw new \RuntimeException('fixture could not create the candidate ref');
            $path = $root . '/.git/reference-provider-plan-' . hash('sha256', $branch) . '.json';
            file_put_contents($path, json_encode([
                'context' => ['production_snapshot_hash' => hash('sha256', 'semantic-production')],
                'format' => 'duo-refresh-plan/v1',
                'plan_hash' => hash('sha256', 'semantic-plan-' . $branch),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return ['head' => $head, 'new_branch' => $branch, 'plan_path' => $path, 'run_id' => 'reference-provider-fixture'];
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

use Duo\Orchestrator\EnvironmentCommand;

$scratch = $argv[1] ?? '';
if ($scratch === '') {
    fwrite(STDERR, "usage: reference-provider-command-checks.php <scratch-dir>\n");
    exit(2);
}

/** @param list<string> $command */
function rpc_run(array $command, ?string $cwd = null): string {
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) throw new RuntimeException('could not start reference-provider command fixture process');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('fixture command failed: ' . implode(' ', $command) . " :: $stderr");
    return trim($stdout);
}

/** Restore owner write permission so the shell fixture can remove immutable snapshot evidence. */
function rpc_make_removable(string $path): void {
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0600);
        return;
    }
    if (!is_dir($path)) return;
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        rpc_make_removable($path . '/' . $entry);
    }
}

/** One complete plan envelope, as agent/src/Apply/Apply.php emits it. */
function rpc_plan(): array {
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

$root = dirname(__DIR__, 4);
$site = $scratch . '/site';
$compose = $scratch . '/compose';
$state = $scratch . '/state';
$bin = $scratch . '/bin';
$wpPath = $scratch . '/wp';
$sourceRepo = $compose . '/siterepo/mup1';
$targetRepo = $compose . '/siterepo/mup2';
$origin = $scratch . '/origin.git';
$started = $scratch . '/pair-started';
$physicalLog = $scratch . '/physical.log';
register_shutdown_function(static function () use ($state): void {
    rpc_make_removable($state);
});
foreach ([$site, $state, $bin, $wpPath, $compose . '/bin', $sourceRepo, $targetRepo] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException("could not create '$directory'");
}

rpc_run(['git', 'init', '-b', 'feature'], $site);
rpc_run(['git', 'config', 'user.email', 'test@example.invalid'], $site);
rpc_run(['git', 'config', 'user.name', 'Reference Provider Fixture'], $site);
file_put_contents($site . '/tracked.txt', "branch\n");
rpc_run(['git', 'add', 'tracked.txt'], $site);
rpc_run(['git', 'commit', '-m', 'feature'], $site);
rpc_run(['git', 'branch', 'feature-one'], $site);
rpc_run(['git', 'branch', 'feature-two'], $site);
rpc_run(['git', 'clone', '--bare', $site, $origin]);

$planPath = $scratch . '/plan.json';
file_put_contents($planPath, json_encode(rpc_plan(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$wp = "#!/bin/sh\ncat " . escapeshellarg($planPath) . "\n";
file_put_contents($bin . '/wp', $wp);
chmod($bin . '/wp', 0700);

$docker = <<<'SH'
#!/bin/sh
printf 'docker' >> "$RPC_PHYSICAL_LOG"
printf ' <%s>' "$@" >> "$RPC_PHYSICAL_LOG"
printf '\n' >> "$RPC_PHYSICAL_LOG"
if [ "${1:-}" = port ]; then
  [ -f "$RPC_PAIR_STARTED" ] || exit 33
  case "${2:-}" in
    *wp1*) printf '0.0.0.0:8181\n' ;;
    *)     printf '0.0.0.0:8182\n' ;;
  esac
fi
case " $* " in
  *" mariadb-dump "*) printf '%s\n' '-- deterministic empty fixture dump' ;;
esac
exit 0
SH;
$pair = <<<'SH'
#!/bin/sh
printf 'pair' >> "$RPC_PHYSICAL_LOG"
printf ' <%s>' "$@" >> "$RPC_PHYSICAL_LOG"
printf '\n' >> "$RPC_PHYSICAL_LOG"
if [ "${1:-}" = up ]; then : > "$RPC_PAIR_STARTED"; fi
exit 0
SH;
file_put_contents($bin . '/docker', $docker . "\n");
file_put_contents($compose . '/bin/pair.sh', $pair . "\n");
chmod($bin . '/docker', 0700);
chmod($compose . '/bin/pair.sh', 0700);
file_put_contents($compose . '/pair.yml', "services: {}\n");
file_put_contents($compose . '/pair.http.yml', "services: {}\n");
touch($started);
putenv('PATH=' . $bin . PATH_SEPARATOR . (string) getenv('PATH'));
putenv('RPC_PAIR_STARTED=' . $started);
putenv('RPC_PHYSICAL_LOG=' . $physicalLog);

$providerConfig = $scratch . '/provider.json';
file_put_contents($providerConfig, json_encode([
    'format' => 'duo-reference-env-provider-config/v1',
    'pair' => 'mup',
    'pair_script' => $compose . '/bin/pair.sh',
    'compose_dir' => $compose,
    'compose_files' => [$compose . '/pair.yml', $compose . '/pair.http.yml'],
    'controller_repo' => $origin,
    'db_container' => 'duo-shared-db',
    'state_root' => $state,
    'source_environment' => 'mup1',
    'destroy_scope' => 'side',
    'withheld_capabilities' => [],
    'environments' => [
        'mup1' => [
            'role' => 'source', 'side' => 1, 'port' => 8181,
            'container' => 'duo-mup-wp1-1', 'service' => 'cli1',
            'database' => 'wp_mup1', 'repo' => $sourceRepo,
        ],
        'mup2' => [
            'role' => 'target', 'side' => 2, 'port' => 8182,
            'container' => 'duo-mup-wp2-1', 'service' => 'cli2',
            'database' => 'wp_mup2', 'repo' => $targetRepo,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

$providerCommand = [PHP_BINARY, $root . '/tools/reference-env-provider.php', $providerConfig];
$envsPath = $scratch . '/envs.json';
file_put_contents($envsPath, json_encode(['envs' => [
    'mup1' => [
        'transport' => 'local', 'wp_path' => $wpPath, 'repo_path' => $site,
        'environment_provider' => ['command' => $providerCommand, 'timeout_seconds' => 20],
    ],
    'mup2' => [
        'transport' => 'local', 'wp_path' => $wpPath, 'repo_path' => $targetRepo,
        'environment_provider' => ['command' => $providerCommand, 'timeout_seconds' => 20],
    ],
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

$promotions = 0;
$promote = static function (\Duo\Orchestrator\EnvironmentDriver $driver, array $frozenContext) use (&$promotions): array {
    $promotions++;
    $summary = $frozenContext['compiled_summary'];
    $receipt = [
        'artifact_hash' => (string) $summary['artifact_hash'],
        'checkpoint_identity' => hash('sha256', 'checkpoint-' . $frozenContext['operation_id']),
        'code_revision' => (string) $summary['code']['code_revision'],
        'format' => 'duo-branch-environment-promotion-receipt/v1',
        'operation_id' => $frozenContext['operation_id'],
        'owner' => $frozenContext['promotion_owner'],
        'state_revision' => (string) $summary['revision_hash'],
        'status' => 'completed',
    ];
    $receipt['receipt_sha256'] = hash('sha256', \Duo\Orchestrator\EnvironmentLifecycleCanon::encode($receipt));
    return $receipt;
};

$previous = getcwd();
chdir($site);

/** @return array<string,mixed> */
$materialize = static function (string $branch) use ($envsPath, $promote): array {
    ob_start();
    $status = EnvironmentCommand::run(
        ['materialize', 'mup2', '--from', 'mup1', '--branch', $branch, '--create', '--format=json'],
        $envsPath,
        $promote
    );
    $output = (string) ob_get_clean();
    duo_check_same(0, $status, "the real EnvironmentCommand materializes '$branch' through the reusable provider");
    if ($status !== 0 || trim($output) === '') return [];
    $receipt = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    return is_array($receipt) ? $receipt : [];
};

/** @return array<string,mixed> */
$reap = static function () use ($envsPath, $promote): array {
    ob_start();
    $status = EnvironmentCommand::run(['reap', 'mup2', '--format=json'], $envsPath, $promote);
    $output = (string) ob_get_clean();
    duo_check_same(0, $status, 'the real EnvironmentCommand reaps the reusable provider target');
    if ($status !== 0 || trim($output) === '') return [];
    $receipt = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    return is_array($receipt) ? $receipt : [];
};

$first = $materialize('feature-one');
duo_check_same('create', $first['mode'] ?? null, 'the product path requests create rather than silently attaching');
duo_check_same(1, $first['lease_generation'] ?? null, 'the first product-path preview owns generation 1');
duo_check_same('duo-mup-wp2', $first['resource_id'] ?? null, 'the product receipt carries the stable physical slot id');
$firstReap = $reap();
duo_check_same('destroyed', $firstReap['disposition'] ?? null, 'the created product-path preview is destroyed through exact reap');

$second = $materialize('feature-two');
duo_check_same(2, $second['lease_generation'] ?? null, 'a second product-path preview rotates the same slot to generation 2');
duo_check_same($first['resource_id'] ?? null, $second['resource_id'] ?? null, 'product-path reuse retains the physical resource id');
duo_check(($first['lease_id'] ?? null) !== ($second['lease_id'] ?? null), 'product-path reuse rotates the lease id');
$secondReap = $reap();
duo_check_same('destroyed', $secondReap['disposition'] ?? null, 'the second product-path preview reaps normally');
duo_check_same(2, $promotions, 'each preview generation runs the product promotion callback exactly once');

$actions = array_map(
    static fn (string $line): string => (string) (json_decode($line, true, 512, JSON_THROW_ON_ERROR)['action'] ?? ''),
    file($state . '/actions.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
);
duo_check_same(2, count(array_filter($actions, static fn (string $action): bool => $action === 'create')), 'the product path acquires exactly two generations');
duo_check_same(2, count(array_filter($actions, static fn (string $action): bool => $action === 'destroy')), 'the product path physically reaps each generation exactly once');

// The immutable media snapshot is published 0555 and host-owned; `docker cp`
// carries that into the target, whose runtime is 33:33 (sandbox/pair.yml).
// grind_adoption A6: without a hand-back the rehearsal target's `duo apply`
// failed provider:elementor-css/regenerate_css with "Permission denied" under
// uploads/elementor/css. Every restore must therefore be followed by the
// ownership/mode hand-back, in that order.
$physical = file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$restores = array_keys(array_filter($physical, static fn (string $line): bool =>
    str_starts_with($line, 'docker <cp> <') && str_ends_with($line, '> <duo-mup-wp2-1:/var/www/html/wp-content/uploads>')));
duo_check_same(2, count($restores), 'each materialization restores the immutable media snapshot into the target once');
foreach ($restores as $index) {
    duo_check_same(
        'docker <exec> <duo-mup-wp2-1> <sh> <-c> <chown -R 33:33 /var/www/html/wp-content/uploads && chmod -R u+rwX,go+rX /var/www/html/wp-content/uploads>',
        $physical[$index + 1] ?? null,
        'a media restore is followed by handing the uploads tree back to the 33:33 site runtime, writable'
    );
}

chdir($previous ?: '/');
duo_check_summary('reference provider through EnvironmentCommand');
}
