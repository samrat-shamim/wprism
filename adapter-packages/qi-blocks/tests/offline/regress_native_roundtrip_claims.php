<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once "$root/sandbox/tests/lib/check.php";
require_once "$root/cli/src/Transport/CodeDeploy.php";

$run = static function (array $command): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Qi claim probe could not start');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};

[$exit, $json, $stderr] = $run([PHP_BINARY, "$root/sandbox/tests/lib/capture_plan_claims.php", $root, '["core","qi-blocks"]']);
wprism_check($exit === 0 && $stderr === '', 'real shipped Qi claim projection succeeds cleanly');
$claims = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
$probe = 'fail() { printf "%s\n" "$*" >&2; exit 1; }; . "$1"; assert_agent_roundtrip_refusal "$2" 1 "wprism: deploy: refusing before promotion-begin; only certified adapters may enter deployment"';
[$exit, $stdout, $stderr] = $run(['bash', '-c', $probe, '_', "$root/sandbox/conformance/asserts.sh", $json]);
wprism_check($exit === 0 && $stdout === '' && $stderr === '', 'shipped claims admit the experimental Qi agent roundtrip while preserving host refusal');
$summary = ['resolved_adapters' => []];
foreach ($claims as $claim) $summary['resolved_adapters'][] = [
    'name' => $claim['name'], 'disposition' => ['reason' => 'fixture claim'], 'capability' => $claim,
];
$blockers = WPrism\Orchestrator\CodeDeploy::dispositionBlockers($summary);
wprism_check_same(['qi-blocks'], array_column($blockers, 'name'), 'real host deployment boundary still blocks Qi');
wprism_check_same(['experimental'], array_column($blockers, 'status'), 'experimental deploy evidence does not grant production promotion');
$withoutDeploy = $claims;
foreach ($withoutDeploy as &$claim) if ($claim['name'] === 'qi-blocks') {
    $claim['operations'] = array_values(array_diff($claim['operations'], ['deploy']));
}
unset($claim);
[$exit, $stdout, $stderr] = $run(['bash', '-c', $probe, '_', "$root/sandbox/conformance/asserts.sh", json_encode($withoutDeploy, JSON_THROW_ON_ERROR)]);
wprism_check($exit !== 0 && str_contains($stderr, 'requires independently declared experimental deploy/apply claims'),
    'agent roundtrip refuses a declaration that omits its lifecycle operation');
wprism_check_summary('Qi experimental roundtrip claims');
