<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/RefreshCommand.php';

use Duo\Orchestrator\CommandOutput;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\RefreshCommand;

function fail_refresh_command(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function assert_refresh_command(bool $ok, string $message): void { if (!$ok) fail_refresh_command($message); }

final class RefreshCommandDriver implements EnvironmentDriver {
    public int $rawCalls = 0;
    public int $wpCalls = 0;
    public function name(): string { return 'refresh-command-fixture'; }
    public function driverId(): string { return 'refresh-command-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'refresh fixture'; }
    public function captureRaw(string $script): array {
        $this->rawCalls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function streamWp(array $wpArgs): int { $this->wpCalls++; return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('refresh-command-fixture', 'refresh-command-fixture', $operation, []);
    }
}

$driver = new RefreshCommandDriver();
ob_start();
$humanExit = RefreshCommand::run($driver, []);
$human = (string) ob_get_clean();
assert_refresh_command($humanExit === 1, 'missing refresh arguments refuse with the established human exit');
assert_refresh_command($driver->rawCalls === 0 && $driver->wpCalls === 0, 'argument refusal occurs before target contact');
assert_refresh_command($human === '', 'human refusal keeps diagnostics on stderr');

$jsonDriver = new RefreshCommandDriver();
ob_start();
$jsonExit = RefreshCommand::run($jsonDriver, ['--field-diff', '--format=json']);
$json = (string) ob_get_clean();
$payload = json_decode($json, true);
assert_refresh_command($jsonExit === 1, 'missing production ref preserves JSON refusal exit');
assert_refresh_command($jsonDriver->rawCalls === 0 && $jsonDriver->wpCalls === 0, 'JSON argument refusal occurs before target contact');
assert_refresh_command(is_array($payload)
    && ($payload['format'] ?? null) === 'duo-command-refusal/v1'
    && ($payload['command'] ?? null) === 'refresh'
    && ($payload['reason_code'] ?? null) === 'invalid_arguments'
    && ($payload['ok'] ?? null) === false,
    'refresh command owns the stable machine refusal envelope');

$source = file_get_contents(__DIR__ . '/../../cli/duo');
assert_refresh_command(is_string($source)
    && str_contains($source, 'return RefreshCommand::run($t, $extra);')
    && !str_contains($source, 'function refresh_field_diff_refusal('),
    'cli/duo retains only the refresh compatibility facade');
assert_refresh_command((new ReflectionMethod(RefreshCommand::class, 'run'))->isStatic(),
    'refresh handler exposes a standalone static boundary');
assert_refresh_command(CommandOutput::wantsAgentRefusalJson('refresh', ['--format=json']),
    'refresh uses the shared host refusal-format detector');

$renderPlan = new ReflectionMethod(RefreshCommand::class, 'renderPlan');
$postLabelContent = "---\n" . json_encode(
    ['title' => "Canvas \"Weekender\"\nSale"],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
) . "\n---\n";
ob_start();
$renderPlan->invoke(null, [
    'plan_path' => '/tmp/refresh-plan.json',
    'context' => ['base_commit' => 'base', 'production_commit' => 'production', 'branch_commit' => 'branch'],
    'plan' => [
        'plan_hash' => 'plan-hash',
        'counts' => ['unchanged' => 0, 'production-only' => 0, 'branch-only' => 0, 'compatible' => 0, 'conflicting' => 3],
        'entries' => [
            [
                'id' => 'post:11111111-1111-4111-8111-111111111111',
                'category' => 'conflicting',
                'reason' => 'same field changed',
                'versions' => ['branch' => [
                    'type' => 'post',
                    'path' => 'posts/product/canvas-weekender.md',
                    'content' => $postLabelContent,
                ]],
            ],
            [
                'id' => 'term:22222222-2222-4222-8222-222222222222',
                'category' => 'conflicting',
                'versions' => ['branch' => ['path' => 'terms/product_cat/weekend.json']],
            ],
            ['id' => 'option:opaque', 'category' => 'conflicting'],
        ],
    ],
]);
$renderedPlan = (string) ob_get_clean();
assert_refresh_command(str_contains(
    $renderedPlan,
    'conflict post:11111111-1111-4111-8111-111111111111 "Canvas \\"Weekender\\" Sale": same field changed'
), 'ordinary conflict rendering quotes and sanitizes the WordPress label');
assert_refresh_command(str_contains(
    $renderedPlan,
    'conflict term:22222222-2222-4222-8222-222222222222 "path:terms/product_cat/weekend.json": semantic divergence'
), 'ordinary conflict rendering falls back to a safe local path');
assert_refresh_command(str_contains(
    $renderedPlan,
    'conflict option:opaque: semantic divergence'
), 'ordinary conflict rendering omits an unavailable label cleanly');

echo "PASS: refresh command\n";
