<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
$scratch = sys_get_temp_dir() . '/wprism-help-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700);
// A help request must succeed even when normal registry discovery would fail.
file_put_contents($scratch . '/site.wprism.json', '{broken registry');
file_put_contents($scratch . '/overlay.json', '{broken overlay');

function help_cli(array $arguments, string $root, string $scratch): array {
    $stdout = tempnam($scratch, 'help-out-');
    $stderr = tempnam($scratch, 'help-err-');
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $root . '/cli/wprism'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
        $pipes,
        $scratch
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not run CLI help');
    }
    fclose($pipes[0]);
    // The prior CLI writes its 92-KiB reference to stderr on an unknown verb.
    // File-backed streams let the negative control finish without pipe deadlock.
    $exit = proc_close($process);
    $out = file_get_contents($stdout);
    $err = file_get_contents($stderr);
    unlink($stdout);
    unlink($stderr);
    return ['exit' => $exit, 'out' => $out, 'err' => $err];
}

try {
    $overview = help_cli(['--help'], $root, $scratch);
    wprism_check_same(0, $overview['exit'], 'introductory help succeeds without a valid site');
    wprism_check_same('', $overview['err'], 'help produces no diagnostics');
    wprism_check(count(explode("\n", trim($overview['out']))) <= 50,
        'the first help screen fits within 50 lines instead of the former 1269-line reference');
    foreach (['demo start', 'help <command>', 'help all', 'assess <env>', 'release <env> --plan-only'] as $entry) {
        wprism_check(str_contains($overview['out'], $entry), 'overview exposes ' . $entry);
    }
    wprism_check_same($overview['out'], help_cli(['help'], $root, $scratch)['out'], 'help and --help agree');
    $empty = help_cli([], $root, $scratch);
    wprism_check_same(1, $empty['exit'], 'missing command retains its nonzero status');
    wprism_check_same($overview['out'], $empty['out'], 'missing command shows the short entry point');

    $all = help_cli(['help', 'all'], $root, $scratch);
    wprism_check_same(0, $all['exit'], 'full reference remains available');
    wprism_check_same($all['out'], help_cli(['--help=all'], $root, $scratch)['out'], 'full-help forms agree');
    wprism_check(str_contains($all['out'], 'nested registry/overlay is refused'), 'full reference retains registry constraints');
    preg_match_all('/^  wprism ([a-z][a-z0-9-]*)(?:\s|$)/m', $all['out'], $matches);
    foreach (array_unique($matches[1]) as $topic) {
        $help = help_cli(['help', $topic], $root, $scratch);
        wprism_check_same(0, $help['exit'], "$topic has usable command help");
        wprism_check(str_contains($help['out'], 'wprism ' . $topic), "$topic help retains its synopsis");
    }

    $assess = help_cli(['help', 'assess'], $root, $scratch);
    wprism_check(str_contains($assess['out'], '[--cursor=<token>]'), 'assess help preserves continuation flags');
    wprism_check(!str_contains($assess['out'], 'wprism adapter certify'), 'assess help excludes unrelated adapter commands');
    wprism_check(str_contains($assess['out'], 'unknown queue.'), 'assess help preserves its detailed behavior');
    $release = help_cli(['release', 'production', '--envs-file=' . $scratch . '/overlay.json', '--help'], $root, $scratch);
    wprism_check_same(0, $release['exit'], 'release help bypasses an invalid explicit registry before target contact');
    wprism_check_same(help_cli(['help', 'release'], $root, $scratch)['out'], $release['out'],
        'command-local --help and help command select the same reference');
    wprism_check(str_contains($release['out'], 'external signature'), 'release help retains external authorization requirements');
    wprism_check_same(1, help_cli(['help', 'not-a-command'], $root, $scratch)['exit'], 'unknown help topics refuse');
    wprism_check_same(1, help_cli(['help', 'release', 'unexpected'], $root, $scratch)['exit'], 'extra help arguments refuse');
    wprism_check_same(['.', '..', 'overlay.json', 'site.wprism.json'], scandir($scratch), 'help creates no workspace artifacts');
    wprism_check_same('{broken registry', file_get_contents($scratch . '/site.wprism.json'), 'help leaves the registry untouched');
} finally {
    unlink($scratch . '/site.wprism.json');
    unlink($scratch . '/overlay.json');
    rmdir($scratch);
}
wprism_check_summary('CLI introductory and command help');
