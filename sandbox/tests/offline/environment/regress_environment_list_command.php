<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/EnvironmentListCommand.php';

use WPrism\Orchestrator\EnvironmentListCommand;

function fail_environment_list(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_environment_list(bool $condition, string $message): void {
    if (!$condition) fail_environment_list($message);
}

$tmp = sys_get_temp_dir() . '/wprism-env-list-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
$envs = $tmp . '/envs.json';
file_put_contents($envs, json_encode([
    'envs' => [
        'local' => [
            'transport' => 'local',
            'wp_path' => '/var/www/html',
            'repo_path' => '/var/www/html/repo',
        ],
        'docker' => [
            'transport' => 'docker',
            'compose_file' => '/tmp/compose.yml',
            'service' => 'cli',
            'repo_path' => '/repo',
        ],
        'ssh' => [
            'transport' => 'ssh',
            'host' => 'fixture.example',
            'wp_path' => '/srv/wp',
            'repo_path' => '/srv/repo',
        ],
        'broken' => [
            'transport' => 'invalid',
            'repo_path' => '/repo',
        ],
        '123' => [
            'transport' => 'local',
            'wp_path' => '/numeric/wp',
            'repo_path' => '/numeric/repo',
        ],
    ],
], JSON_UNESCAPED_SLASHES) . "\n");

ob_start();
$exit = EnvironmentListCommand::run($envs, $tmp);
$output = (string) ob_get_clean();
assert_environment_list($exit === 0, 'a configured registry exits successfully');
assert_environment_list(str_contains($output, 'local   local  wp_path=/var/www/html repo_path=/var/www/html/repo'), 'valid transport renders its description');
assert_environment_list(str_contains($output, 'docker  docker compose_file=/tmp/compose.yml service=cli repo_path=/repo'), 'docker transport renders without target contact');
assert_environment_list(str_contains($output, 'ssh     ssh    host=fixture.example wp_path=/srv/wp repo_path=/srv/repo'), 'ssh transport renders without target contact');
assert_environment_list(str_contains($output, 'broken  ERROR: env \'broken\': unknown transport'), 'invalid transport is rendered as an entry error');
assert_environment_list(str_contains($output, '123     local  wp_path=/numeric/wp repo_path=/numeric/repo'), 'numeric-only environment name renders normally');

$empty = $tmp . '/empty.json';
file_put_contents($empty, json_encode(['envs' => []]) . "\n");
ob_start();
$emptyExit = EnvironmentListCommand::run($empty, $tmp);
$emptyOutput = (string) ob_get_clean();
assert_environment_list($emptyExit === 1, 'empty registry refuses');
assert_environment_list($emptyOutput === '', 'empty registry emits no stdout');

@unlink($envs);
@unlink($empty);
@rmdir($tmp);
echo "PASS: environment list command\n";
