<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Registry.php';
require_once __DIR__ . '/../../cli/src/EnvironmentDriver.php';
require_once __DIR__ . '/../../cli/src/Transport.php';
require_once __DIR__ . '/../../cli/src/LocalTransport.php';
require_once __DIR__ . '/../../cli/src/EnvironmentCommandPreflight.php';

use Duo\Orchestrator\EnvironmentCommandPreflight;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) { echo "ok: $message\n"; return; }
    fwrite(STDERR, "FAIL: $message\n"); $failures++;
};
$expected = [
    'doctor', 'driver-capabilities', 'adopt', 'init', 'status', 'capabilities',
    'adapter-observe', 'capture', 'lint', 'plan', 'explain', 'apply', 'deploy', 'env-set',
    'promote', 'pending', 'classify', 'coverage', 'scope', 'refresh', 'rebase',
];
$check(EnvironmentCommandPreflight::environmentVerbs() === $expected, 'environment command vocabulary remains ordered and closed');
$check(EnvironmentCommandPreflight::requiresEnvironment('capture'), 'capture is environment-bound');
$check(EnvironmentCommandPreflight::requiresEnvironment('lint'), 'lint is environment-bound');
$check(!EnvironmentCommandPreflight::requiresEnvironment('envs'), 'offline envs command is not environment-bound');
$check(!EnvironmentCommandPreflight::requiresEnvironment('adapter'), 'offline adapter catalog is not environment-bound');

$tmp = sys_get_temp_dir() . '/duo-preflight-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
file_put_contents($tmp . '/envs.json', json_encode([
    'envs' => ['fixture' => ['transport' => 'local', 'wp_path' => '/wordpress', 'repo_path' => '/repo']],
], JSON_UNESCAPED_SLASHES));
$transport = EnvironmentCommandPreflight::resolveTransport($tmp . '/envs.json', $tmp, 'fixture');
$check($transport->name() === 'fixture' && $transport->repoPath() === '/repo', 'preflight resolves a trusted local transport without target contact');
$numericRegistry = $tmp . '/numeric-envs.json';
file_put_contents($numericRegistry, '{"envs":{"123":{"transport":"local","wp_path":"/wordpress","repo_path":"/repo"}}}');
$numericTransport = EnvironmentCommandPreflight::resolveTransport($numericRegistry, $tmp, '123');
$check($numericTransport->name() === '123', 'numeric-only environment names allowed by the public grammar resolve');
$listRegistry = $tmp . '/list-envs.json';
file_put_contents($listRegistry, '{"envs":[{"transport":"local","wp_path":"/wordpress","repo_path":"/repo"}]}');
try {
    EnvironmentCommandPreflight::resolveTransport($listRegistry, $tmp, '0');
    $check(false, 'a JSON list cannot masquerade as numeric environment 0');
} catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), "'envs' must be an object"), 'registry preserves the non-empty JSON object/list boundary');
}
$report = EnvironmentCommandPreflight::capabilityReport($transport, 'capture');
$check($report->ready() && $report->toArray()['operation'] === 'capture', 'preflight returns the driver-owned capability verdict');
try {
    EnvironmentCommandPreflight::resolveTransport($tmp . '/envs.json', $tmp, 'missing');
    $check(false, 'unknown environment refuses before transport creation');
} catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), "unknown environment 'missing'"), 'unknown environment keeps the existing closed diagnostic');
}
foreach (['--envs-file=/tmp/other', "bad\0name"] as $invalidName) {
    file_put_contents($tmp . '/invalid-envs.json', json_encode([
        'envs' => [$invalidName => ['transport' => 'local', 'wp_path' => '/wordpress', 'repo_path' => '/repo']],
    ], JSON_UNESCAPED_SLASHES));
    try {
        EnvironmentCommandPreflight::resolveTransport($tmp . '/invalid-envs.json', $tmp, $invalidName);
        $check(false, 'unsafe environment name refuses at registry load');
    } catch (RuntimeException $e) {
        $check(
            str_contains($e->getMessage(), 'environment names must match'),
            'option-looking/control-bearing environment name refuses with the closed grammar'
        );
    }
}
@unlink($tmp . '/invalid-envs.json');
@unlink($listRegistry); @unlink($numericRegistry); @unlink($tmp . '/envs.json'); @rmdir($tmp);
echo $failures === 0 ? "PASS: environment command preflight\n" : "FAIL: $failures environment preflight assertions\n";
exit($failures === 0 ? 0 : 1);
