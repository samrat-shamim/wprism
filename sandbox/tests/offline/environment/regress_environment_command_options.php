<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/EnvironmentCommandOptions.php';

use WPrism\Orchestrator\EnvironmentCommandOptions;

function fail_env_options(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assert_env_options(bool $condition, string $message): void {
    if (!$condition) fail_env_options($message);
}
function throws_env_options(callable $callback, string $needle, string $message): void {
    try {
        $callback();
    } catch (Throwable $error) {
        assert_env_options(str_contains($error->getMessage(), $needle), $message . ': wrong diagnostic');
        return;
    }
    fail_env_options($message . ': accepted invalid input');
}

$parsed = EnvironmentCommandOptions::materialize([
    '--from=production', '--branch', 'feature/demo', '--create', '--ttl=3600', '--format=json',
]);
assert_env_options($parsed === [
    'source' => 'production',
    'branch' => 'feature/demo',
    'containment_required' => false,
    'create' => true,
    'ttl_seconds' => 3600,
    'json' => true,
], 'materialize parser returns the exact typed options');

assert_env_options(EnvironmentCommandOptions::reap([]) === false, 'reap defaults to human output');
assert_env_options(EnvironmentCommandOptions::reap(['--format=json']) === true, 'reap accepts JSON output');

throws_env_options(
    static fn(): array => EnvironmentCommandOptions::materialize(['--from=prod']),
    'materialize requires exactly',
    'missing branch refuses'
);
throws_env_options(
    static fn(): array => EnvironmentCommandOptions::materialize(['--from=prod', '--from=other', '--branch=main']),
    'duplicate --from',
    'duplicate source refuses'
);
throws_env_options(
    static fn(): array => EnvironmentCommandOptions::materialize(['--from=prod', '--branch=main', '--ttl=59']),
    'between 60 and 2592000',
    'short TTL refuses'
);
throws_env_options(
    static fn(): array => EnvironmentCommandOptions::materialize(['--from=prod', '--branch=-bad']),
    'safe Git ref',
    'unsafe branch refuses'
);
throws_env_options(
    static fn(): bool => EnvironmentCommandOptions::reap(['--format=json', '--create']),
    'only optional --format=json',
    'extra reap flag refuses'
);

echo "PASS: environment command options\n";
