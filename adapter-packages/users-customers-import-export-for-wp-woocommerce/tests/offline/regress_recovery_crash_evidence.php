<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4); $package = dirname(__DIR__, 2);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $package . '/fixtures/recovery-crash-evidence.php';

$pair = 'importercrashoffline'; $command = 'crash-authored'; $revision = str_repeat('c', 40);
$nonce = bin2hex(random_bytes(3));
$sink = $root . '/sandbox/tmp/importer-crash-evidence-' . $nonce;
$private = $root . '/sandbox/tmp/wprism-conformance-apply.' . $pair . '.' . $nonce;
foreach ([$sink, $private] as $directory) mkdir($directory, 0700);
register_shutdown_function(static function () use ($sink, $private): void {
    foreach ([$sink, $private] as $directory) {
        foreach (new DirectoryIterator($directory) as $file) if (!$file->isDot()) unlink($file->getPathname());
        rmdir($directory);
    }
});
$stream = static function (string $directory, string $label, string $bytes, int $exit = 0, string $stderr = ''): void {
    foreach (['stdout' => $bytes, 'stderr' => $stderr, 'exit' => $exit . "\n"] as $suffix => $value) {
        file_put_contents($directory . '/' . $label . '.' . $suffix, $value);
        chmod($directory . '/' . $label . '.' . $suffix, 0600);
    }
};
$json = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR) . "\n";
$name = 'wprism-' . $pair . '-cli2-run-abcdef123456';
$transport = ' Container ' . $name . " Creating \n Container " . $name . " Created \n";
$window = ['before' => 1700000000, 'after' => 1700000010];
$diagnostic = ['format' => 'wprism-private-refusal-diagnostic/v1', 'purpose' => 'diagnostic_only', 'verified' => false,
    'command' => 'apply', 'new_records' => 0, 'records' => []];
$process = ['Id' => str_repeat('d', 64), 'Name' => '/' . $name, 'RestartCount' => 0,
    'Config' => ['Cmd' => ['wp', 'wprism', 'apply', '--repo=/siterepo', '--revision=' . $revision, '--default-author=admin', '--format=json'],
        'Labels' => ['com.docker.compose.project' => 'wprism-' . $pair, 'com.docker.compose.service' => 'cli2', 'com.docker.compose.oneoff' => 'True'],
        'Env' => ['WPRISM_TEST_MODE=1', 'WPRISM_TEST_FAIL_DB_CONTEXT=apply transaction commit', 'WPRISM_TEST_DB_FAULT_MODE=kill', 'WPRISM_TEST_PROMOTION_TTL=20']],
    'State' => ['Status' => 'exited', 'ExitCode' => 137, 'OOMKilled' => false, 'Running' => false, 'Restarting' => false,
        'Dead' => false, 'Error' => '', 'Pid' => 0, 'StartedAt' => '2023-11-14T22:13:21Z', 'FinishedAt' => '2023-11-14T22:13:24Z']];
$seed = static function () use ($stream, $json, $sink, $private, $command, $transport, $diagnostic, $process, $name): void {
    $stream($private, 'baseline', $json(['command' => 'apply', 'baseline' => '[]']));
    $stream($private, 'private', $json($diagnostic));
    $stream($private, 'command', '', 137, $transport);
    $stream($sink, $command, '', 137, 'private command diagnostics (unverified): ' . $private . "\n" . $transport);
    $stream($sink, $command . '-container', $json(['name' => $name]));
    $stream($sink, $command . '-process', $json($process));
    $stream($sink, $command . '-removed', $name . "\n");
};
$verify = static fn() => ImporterRecoveryCrashEvidence::command($root, $sink, $pair, $command, 'authored-failure', $revision, $window);
$seed(); $verify(); wprism_check(true, 'crash receipt binds exact command and empty post-kill diagnostics');
ImporterRecoveryCrashEvidence::removed($sink, $command); wprism_check(true, 'owned stopped container removal is admitted');
foreach (['public-output', 'php-stderr', 'ordinary-exit', 'wrapper-mismatch', 'baseline', 'new-diagnostic',
    'wrong-container', 'wrong-phase', 'oom', 'wrong-revision', 'missing-inspection', 'late-container'] as $fault) {
    $seed();
    if ($fault === 'public-output') {
        $stream($private, 'command', '{}', 137, $transport);
        $stream($sink, $command, '{}', 137, 'private command diagnostics (unverified): ' . $private . "\n" . $transport);
    }
    if ($fault === 'php-stderr') $stream($private, 'command', '', 137, $transport . "PHP Warning: fixture\n");
    if ($fault === 'ordinary-exit') $stream($sink, $command, '', 1, 'private command diagnostics (unverified): ' . $private . "\n" . $transport);
    if ($fault === 'wrapper-mismatch') $stream($sink, $command, 'different', 137, 'private command diagnostics (unverified): ' . $private . "\n" . $transport);
    if ($fault === 'baseline') $stream($private, 'baseline', $json(['command' => 'capture', 'baseline' => '[]']));
    if ($fault === 'new-diagnostic') {
        $bad = $diagnostic; $bad['new_records'] = 1;
        $bad['records'] = [['name' => '20231114-221321-apply-' . str_repeat('e', 24) . '.json', 'bytes' => 2,
            'contents_base64' => base64_encode('{}'), 'sha256' => hash('sha256', '{}')]];
        $stream($private, 'private', $json($bad));
    }
    if ($fault === 'wrong-container') $stream($sink, $command . '-container', $json(['name' => 'wprism-' . $pair . '-cli2-run-000000000000']));
    if (in_array($fault, ['wrong-phase', 'oom', 'wrong-revision', 'late-container'], true)) {
        $bad = $process;
        if ($fault === 'wrong-phase') $bad['Config']['Env'][1] = 'WPRISM_TEST_FAIL_DB_CONTEXT=ledger transaction commit';
        if ($fault === 'oom') $bad['State']['OOMKilled'] = true;
        if ($fault === 'wrong-revision') $bad['Config']['Cmd'][4] = '--revision=' . str_repeat('a', 40);
        if ($fault === 'late-container') $bad['State']['FinishedAt'] = '2023-11-14T22:13:31Z';
        $stream($sink, $command . '-process', $json($bad));
    }
    if ($fault === 'missing-inspection') unlink($sink . '/' . $command . '-process.stdout');
    clearstatcache();
    wprism_check_throws($verify, RuntimeException::class, 'crash command rejects ' . $fault);
}
$seed(); $bad = $process; $bad['Config']['Env'][1] = 'WPRISM_TEST_FAIL_DB_CONTEXT=ledger transaction commit';
$stream($sink, $command . '-process', $json($bad));
ImporterRecoveryCrashEvidence::command($root, $sink, $pair, $command, 'ledger-failure', $revision, $window);
wprism_check(true, 'ledger crash binds its distinct context');
$stream($sink, $command . '-removed', "foreign\n");
wprism_check_throws(static fn() => ImporterRecoveryCrashEvidence::removed($sink, $command), RuntimeException::class, 'container removal cannot refer to another process');
foreach (['apply transaction commit', 'ledger transaction commit'] as $context) {
    [$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(<<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
export CONF_PAIR=crashtransport COMPOSE='docker compose -p wprism-crashtransport -f exact.yml -f overlay.yml'
export IMPORTER_RECOVERY_FAULT_MODE=kill IMPORTER_RECOVERY_CONTAINER=wprism-crashtransport-cli2-run-abcdef123456
docker() { printf '%s\n' "$@"; }
. "$1"
importer_recovery_fault "$2" wprism apply --format=json
SH, [$package . '/fixtures/recovery.sh', $context], $root . '/sandbox');
    $expected = ['compose', '-p', 'wprism-crashtransport', '-f', 'exact.yml', '-f', 'overlay.yml', 'run', '--name',
        'wprism-crashtransport-cli2-run-abcdef123456', '-T', '-e', 'WPRISM_TEST_MODE=1', '-e', 'WPRISM_TEST_FAIL_DB_CONTEXT=' . $context,
        '-e', 'WPRISM_TEST_DB_FAULT_MODE=kill', '-e', 'WPRISM_TEST_PROMOTION_TTL=20', 'cli2', 'wp', 'wprism', 'apply', '--format=json'];
    wprism_check($status === 0 && $stderr === '' && explode("\n", rtrim($stdout, "\n")) === $expected,
        'actual crash transport binds owned container, all fault switches and exact overlays: ' . $context);
}
wprism_check_summary('Importer crash evidence');
