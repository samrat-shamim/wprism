<?php
declare(strict_types=1);

/**
 * Thread 3 characterization guard.
 *
 * The command contract entries are intentionally separate from Cli.php. This
 * regression keeps the two inventories honest while Cli is later reduced to
 * registration/composition/presentation. It is read-only and does not load
 * WordPress or the agent bootstrap.
 */

$root = dirname(__DIR__, 4);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        fwrite(STDOUT, "ok: $message\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "FAIL: $message\n");
};

$cli = file_get_contents($root . '/agent/src/Cli.php');
$check(is_string($cli), 'Cli.php is readable');
if (!is_string($cli)) {
    exit(1);
}

preg_match_all('/^    public function ([a-z_]+)\(/m', $cli, $methodMatches);
$methods = array_values(array_unique((array) ($methodMatches[1] ?? [])));
$check($methods !== [], 'Cli.php exposes a nonempty public command method inventory');

$entries = [];
foreach (glob($root . '/docs/contracts/commands/agent/*.json') ?: [] as $path) {
    $decoded = json_decode((string) file_get_contents($path), true);
    $check(is_array($decoded) && ($decoded['format'] ?? null) === 'duo-command-contract-set/v1',
        basename($path) . ' is a command-contract set');
    foreach ((array) ($decoded['entries'] ?? []) as $entry) {
        if (!is_array($entry) || !is_string($entry['command'] ?? null)) {
            continue;
        }
        $entries[] = $entry;
    }
}

$commands = array_values(array_unique(array_map(
    static fn(array $entry): string => (string) $entry['command'],
    $entries
)));
$check($entries !== [], 'agent command-contract corpus is nonempty');

foreach ($methods as $method) {
    $command = 'wp duo ' . str_replace('_', '-', $method);
    $check(in_array($command, $commands, true), "$method has a characterized command contract");
}

$byCommand = [];
foreach ($entries as $entry) {
    $byCommand[(string) $entry['command']][] = $entry;
}
$requiredFlags = [
    'wp duo capture' => ['--repo', '--scope-contract', '--format'],
    'wp duo init' => ['--repo', '--confirm', '--format'],
    'wp duo plan' => ['--repo', '--compiled', '--scope-contract', '--format'],
    'wp duo pending' => ['--repo', '--format'],
    'wp duo adapter-observe' => ['--repo', '--format'],
];
foreach ($requiredFlags as $command => $flags) {
    $present = [];
    foreach ($byCommand[$command] ?? [] as $entry) {
        foreach ((array) ($entry['flags'] ?? []) as $flag) {
            if (is_array($flag) && is_string($flag['name'] ?? null)) {
                $present[(string) $flag['name']] = true;
            }
        }
    }
    foreach ($flags as $flag) {
        $check(isset($present[$flag]), "$command characterizes $flag");
    }
}

$captureEntries = $byCommand['wp duo capture'] ?? [];
$check(
    array_reduce(
        $captureEntries,
        static fn(bool $found, array $entry): bool => $found
            || str_contains((string) ($entry['mutation_boundary'] ?? ''), 'before scope-file')
            || str_contains((string) ($entry['mutation_boundary'] ?? ''), 'quarantine'),
        false
    ),
    'capture contract records the pre-access safety boundary'
);

if ($failures !== []) {
    fwrite(STDERR, sprintf("REGRESS_AGENT_COMMAND_CONTRACT FAILED (%d failures)\n", count($failures)));
    exit(1);
}
fwrite(STDOUT, "REGRESS_AGENT_COMMAND_CONTRACT PASSED\n");
