<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/check.php';

// Exercise the dispatcher, real SSH driver, native frame codec and read-only
// filesystem programs. Only SSH/WordPress and the first upload are replaced;
// reaching the deliberately refused upload proves bootstrap was authorized,
// not that a fake reported a completed adoption.
$sourceRoot = getenv('WPRISM_TEST_ADOPT_SOURCE_ROOT') ?: dirname(__DIR__, 3);
$scratch = sys_get_temp_dir() . '/wprism-adopt-frontdoor-' . bin2hex(random_bytes(8));
if (!mkdir($scratch, 0700) || ($scratch = realpath($scratch)) === false) {
    throw new RuntimeException('could not allocate adoption frontdoor fixture');
}
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $child) {
            $remove($child->getPathname());
        }
        rmdir($path);
    } else {
        unlink($path);
    }
};
register_shutdown_function(static fn() => $remove($scratch));
$bin = $scratch . '/bin';
mkdir($bin, 0700);
file_put_contents($bin . '/ssh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
remote="${!#}"
case "$remote" in
  *wprism-raw-control-frame/v1*)
    printf 'FRAME\n' >>"$WPRISM_BOOTSTRAP_TRACE"
    frame_count=$(grep -c '^FRAME$' "$WPRISM_BOOTSTRAP_TRACE")
    if [ "${WPRISM_BOOTSTRAP_FRAME_MODE:-normal}" = malformed ]; then
      printf '{invalid frame}\n'
      exit 0
    fi
    if [ "$frame_count" -eq 2 ]; then
      case "${WPRISM_BOOTSTRAP_FRAME_MODE:-normal}" in
        malformed-proof) printf '{invalid proof}\n'; exit 0 ;;
        over-bound-proof) head -c 8192 /dev/zero; exit 0 ;;
      esac
    fi
    exec /bin/sh -c "$remote"
    ;;
  'echo wprism-reachable'|*' && wp '*|'set -u'*|'php -r '*)
    printf 'READ\n' >>"$WPRISM_BOOTSTRAP_TRACE"
    exec /bin/sh -c "$remote"
    ;;
  *) printf 'UNEXPECTED\n' >>"$WPRISM_BOOTSTRAP_TRACE"; exit 91 ;;
esac
SH);
file_put_contents($bin . '/wp', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
case "$*" in
  *'core is-installed') printf 'WP_ISOLATED\n' >>"$WPRISM_BOOTSTRAP_TRACE" ;;
  *'echo WPRISM_BOOTSTRAP_WPMU_PLUGIN_DIR;'*) printf '%s\n' "$WPRISM_BOOTSTRAP_MU" ;;
  'eval echo WPMU_PLUGIN_DIR;') printf '%s\n' "$WPRISM_BOOTSTRAP_MU" ;;
  *'echo is_multisite() ? "wprism-multisite" : "wprism-single-site";'*) printf 'wprism-single-site\n' ;;
  *) printf 'UNEXPECTED_WP\n' >>"$WPRISM_BOOTSTRAP_TRACE"; exit 92 ;;
esac
SH);
file_put_contents($bin . '/scp', <<<'SH'
#!/usr/bin/env bash
printf 'FIRST_UPLOAD\n' >>"$WPRISM_BOOTSTRAP_TRACE"
exit 73
SH);
foreach (['ssh', 'wp', 'scp'] as $executable) {
    chmod($bin . '/' . $executable, 0700);
}

$unreadable = 'wprism: the database-external recovery fence could not be read safely; '
    . 'repair the adopted control directory boundary, then rerun wprism doctor';
$cases = [
    'fresh-adopt' => ['adopt'],
    'fresh-onboard' => ['onboard'],
    'existing-clear-adopt' => ['adopt'],
    'missing-apply' => ['apply'],
    'missing-capture' => ['capture'],
    'missing-plan' => ['plan'],
    'missing-handoff' => ['onboard', '--handoff-only'],
    'missing-onboard-status' => ['onboard', 'status'],
    'mu-symlink' => ['adopt'],
    'lost-repo-agent' => ['adopt'],
    'lost-repo-loader' => ['adopt'],
    'lost-repo-control' => ['adopt'],
    'retained-transaction' => ['adopt'],
    'repo-file' => ['adopt'],
    'repo-symlink' => ['adopt'],
    'repo-dangling' => ['adopt'],
    'unsafe-parent' => ['adopt'],
    'checkpoint-debt' => ['adopt'],
    'provider-debt' => ['adopt'],
    'malformed-transport' => ['adopt'],
    'malformed-proof' => ['adopt'],
    'over-bound-proof' => ['adopt'],
];
foreach ($cases as $name => $arguments) {
    $case = $scratch . '/' . $name;
    $wp = $case . '/wordpress';
    $mu = $wp . '/wp-content/mu-plugins';
    $repo = $case . '/repository';
    mkdir($mu, 0700, true);
    // Guided onboarding requires its controller workspace before adopting
    // the remote target; it is not the absent target repository under test.
    $workspace = $case . '/controller';
    mkdir($workspace, 0700);
    file_put_contents($workspace . '/site.wprism.json', "{}\n");
    $trace = $case . '/trace';
    file_put_contents($trace, '');
    $expectedMessage = $unreadable;
    switch ($name) {
        case 'existing-clear-adopt':
            mkdir($repo, 0700);
            break;
        case 'mu-symlink':
            mkdir($mu . '/control-real', 0700);
            file_put_contents($mu . '/control-real/sentinel', 'preserve');
            symlink('control-real', $mu . '/wprism-control');
            break;
        case 'lost-repo-agent':
            mkdir($mu . '/wprism', 0700);
            break;
        case 'lost-repo-loader':
            file_put_contents($mu . '/wprism-loader.php', '<?php /* prior authority */');
            break;
        case 'lost-repo-control':
            mkdir($mu . '/wprism-control', 0700);
            break;
        case 'retained-transaction':
            mkdir($mu . '/.wprism-adopt-txn-prior', 0700);
            break;
        case 'repo-file':
            file_put_contents($repo, 'preserve');
            break;
        case 'repo-symlink':
            mkdir($case . '/real-repo', 0700);
            symlink('real-repo', $repo);
            // The generic fence intentionally resolves repository aliases.
            // Make its authority unsafe so bootstrap must not reinterpret it.
            symlink('missing-control', $case . '/real-repo/.wprism');
            break;
        case 'repo-dangling':
            symlink('missing-repo', $repo);
            break;
        case 'unsafe-parent':
            mkdir($case . '/real-parent', 0700);
            symlink('real-parent', $case . '/linked-parent');
            $repo = $case . '/linked-parent/repository';
            break;
        case 'checkpoint-debt':
        case 'provider-debt':
            mkdir($repo . '/.wprism/control', 0700, true);
            $debt = $name === 'checkpoint-debt' ? 'checkpoint-recovery' : 'provider-settlement';
            file_put_contents($repo . '/.wprism/control/' . $debt . '-intent.json', '{}');
            $expectedMessage = $name === 'checkpoint-debt'
                ? 'wprism: an incomplete database recovery blocks this command; resume the exact retained checkpoint with wprism recover before observing or mutating this environment'
                : 'wprism: an incomplete adapter provider settlement blocks this command; resume the exact retained checkpoint with wprism recover before observing or mutating this environment';
            break;
    }
    $witness = static function () use ($case, $trace): array {
        $rows = [];
        $walk = static function (string $path) use (&$walk, &$rows, $case, $trace): void {
            if ($path === $trace || $path === $case . '/envs.json') return;
            $relative = substr($path, strlen($case));
            if (is_link($path)) {
                $rows[$relative] = ['link', readlink($path)];
            } elseif (is_dir($path)) {
                $rows[$relative] = ['directory'];
                foreach (new FilesystemIterator($path) as $entry) $walk($entry->getPathname());
            } else {
                $rows[$relative] = ['file', hash_file('sha256', $path)];
            }
        };
        $walk($case);
        ksort($rows);
        return $rows;
    };
    $before = $witness();
    $registry = $case . '/envs.json';
    file_put_contents($registry, json_encode(['envs' => ['target' => [
        'transport' => 'ssh', 'host' => 'bootstrap-offline-fixture', 'wp_path' => $wp, 'repo_path' => $repo,
    ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $verb = array_shift($arguments);
    $environment = getenv();
    $environment['PATH'] = $bin . ':' . (getenv('PATH') ?: '');
    $environment['WPRISM_BOOTSTRAP_TRACE'] = $trace;
    $environment['WPRISM_BOOTSTRAP_MU'] = $mu;
    $environment['WPRISM_BOOTSTRAP_FRAME_MODE'] = match ($name) {
        'malformed-transport' => 'malformed',
        'malformed-proof', 'over-bound-proof' => $name,
        default => 'normal',
    };
    $process = proc_open(
        [PHP_BINARY, $sourceRoot . '/cli/wprism', '--envs-file=' . $registry, $verb, 'target', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $workspace, $environment
    );
    if (!is_resource($process)) throw new RuntimeException('public adoption fixture did not launch');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $events = (string) file_get_contents($trace);
    $admitted = in_array($name, ['fresh-adopt', 'fresh-onboard', 'existing-clear-adopt'], true);
    $valid = $admitted
        ? $exit === 73 && str_contains($stdout, 'adopt phase: staged install + transactional doctor')
            && str_contains($stderr, 'adopt failed during archive upload')
            && str_contains($events, "FIRST_UPLOAD\n")
        : $exit === 1 && trim($stderr) === $expectedMessage
            && !str_contains($events, "FIRST_UPLOAD\n")
            && !str_contains($stdout, 'adopt phase:');
    wprism_check($valid, 'public bootstrap preflight classifies ' . $name);
    if (!$valid) wprism_check_detail(json_encode([$exit, $stdout, $stderr, $events], JSON_THROW_ON_ERROR));
    wprism_check($before === $witness(), $name . ' changes no target filesystem byte or node');
    if (str_starts_with($name, 'missing-') || str_ends_with($name, '-debt')) {
        wprism_check(!str_contains($events, 'WP_'), $name . ' refuses without asking WordPress');
    }
}

// A driver can throw before it can return a frame (spawn/transport boundary).
// The initial proof must preserve the generic unsafe result on either read,
// never propagate its raw exception or continue to a target write.
require_once $sourceRoot . '/cli/src/Command/AdoptCommand.php';
if (!method_exists(\WPrism\Orchestrator\BootstrapEligibilityReport::class, 'initialRecoveryAuthority')) {
    wprism_check_summary('public adoption bootstrap preflight');
}
final class AdoptProofExceptionTransport extends \WPrism\Orchestrator\Transport implements \WPrism\Orchestrator\AdoptionTransport {
    public int $frames = 0;
    public function __construct(private int $throwOnFrame) {
        parent::__construct('proof-exception', ['repo_path' => '/fixture/repo']);
    }
    public function describe(): string { return 'proof-exception'; }
    public function wpPath(): string { return '/fixture/wp'; }
    public function bootstrapCapability(): array { return ['supported' => true, 'reason' => 'fixture', 'remediation' => '']; }
    protected function wpCommand(array $wpArgs): string { throw new RuntimeException('unexpected WP process'); }
    protected function rawCommand(string $script): string { throw new RuntimeException('unexpected raw process'); }
    public function uploadFile(string $localPath, string $remotePath): array { throw new RuntimeException('unexpected upload'); }
    public function captureRaw(string $script): array {
        return ['exit' => 0, 'stdout' => $script === 'echo wprism-reachable' ? 'wprism-reachable' : 'safe', 'stderr' => ''];
    }
    public function captureWp(array $wpArgs): array {
        return ['exit' => 0, 'stdout' => '/fixture/wp/wp-content/mu-plugins', 'stderr' => ''];
    }
    public function captureRawFramed(string $phpTupleProgram, array $arguments, int $timeoutMilliseconds, int $maxStdoutBytes, int $maxStderrBytes): array {
        if (++$this->frames === $this->throwOnFrame) throw new RuntimeException('private transport exception');
        return ['verified' => true, 'exit' => 0, 'stdout' => 'initial-adoption-absent', 'stderr' => '',
            'transport_exit' => 0, 'transport_stderr' => '', 'failure' => null];
    }
}
foreach ([1, 2] as $throwOnFrame) {
    $exceptionTransport = new AdoptProofExceptionTransport($throwOnFrame);
    $proof = \WPrism\Orchestrator\BootstrapEligibilityReport::initialRecoveryAuthority($exceptionTransport, $sourceRoot);
    wprism_check($proof === null && $exceptionTransport->frames === $throwOnFrame,
        'initial proof converts transport exception at framed read ' . $throwOnFrame . ' into absent authority');
}
wprism_check_summary('public adoption bootstrap preflight');
