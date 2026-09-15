<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once "$root/sandbox/tests/lib/check.php";
require_once "$root/cli/src/Transport/CodeDeploy.php";
$run = static function (array $command): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('claim probe could not start');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
[$exit, $json, $stderr] = $run([PHP_BINARY, "$root/sandbox/tests/lib/capture_plan_claims.php", $root, '["core","visual-portfolio"]']);
wprism_check($exit === 0 && $stderr === '', 'real shipped claim projection succeeds cleanly');
$claims = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
$probe = 'fail() { printf "%s\n" "$*" >&2; exit 1; }; . "$1"; assert_agent_apply_roundtrip_refusal "$2" 1 "wprism: deploy: refusing before promotion-begin; only certified adapters may enter deployment"';
[$exit, $stdout, $stderr] = $run(['bash', '-c', $probe, '_', "$root/sandbox/conformance/asserts.sh", $json]);
wprism_check($exit === 0 && $stdout === '' && $stderr === '', 'shipped claims admit experimental agent Apply roundtrip without bypassing either deployment guard');
$summary = ['resolved_adapters' => []];
foreach ($claims as $claim) $summary['resolved_adapters'][] = ['name' => $claim['name'], 'disposition' => ['reason' => 'fixture claim'], 'capability' => $claim];
$blockers = WPrism\Orchestrator\CodeDeploy::dispositionBlockers($summary);
wprism_check_same(['visual-portfolio'], array_column($blockers, 'name'), 'real host deployment boundary still blocks this adapter');
wprism_check_same(['experimental'], array_column($blockers, 'status'), 'deployment operation does not promote experimental status');
$withDeploy = $claims;
foreach ($withDeploy as &$claim) if ($claim['name'] === 'visual-portfolio') $claim['operations'][] = 'deploy';
unset($claim);
[$exit, $stdout, $stderr] = $run(['bash', '-c', $probe, '_', "$root/sandbox/conformance/asserts.sh", json_encode($withDeploy, JSON_THROW_ON_ERROR)]);
wprism_check($exit !== 0 && str_contains($stderr, 'at least one host-provider participant without deploy'),
    'a hollow experimental deployment claim remains a loud refusal');
wprism_check_summary('Visual Portfolio experimental Apply roundtrip claims');
