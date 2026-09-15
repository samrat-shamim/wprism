<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$scenarioRoot = dirname(__DIR__, 2);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterIntegrationScenarios.php';

use WPrism\Tooling\AdapterIntegrationScenarios;

$catalog = AdapterIntegrationScenarios::discover($root);
$scenario = $catalog['scenarios']['qi-blocks-visual-portfolio'] ?? null;
wprism_check_same(['qi-blocks', 'visual-portfolio'], $scenario['participants'] ?? null,
    'the scenario declares its two exact adapter owners in canonical order');
wprism_check_same([
    'integration-scenarios/qi-blocks-visual-portfolio/tests/live/regress_qi_blocks_visual_portfolio.sh',
    'integration-scenarios/qi-blocks-visual-portfolio/tests/offline/regress_qi_blocks_visual_portfolio.php',
], array_column($scenario['gates'] ?? [], 'path'), 'the participant record discovers both executable evidence gates');

$entry = json_decode((string) file_get_contents($scenarioRoot . '/fixtures/conformance-entry.json'), true, 64, JSON_THROW_ON_ERROR);
wprism_check_same('qi-blocks-visual-portfolio', $entry['manifest'] ?? null,
    'the conformance entry names the integration scenario rather than either adapter');
wprism_check_same('agent-apply-roundtrip', $entry['entry']['mode'] ?? null,
    'the scenario preserves the host-provider deployment boundary');
wprism_check_same(['core', 'qi-blocks', 'visual-portfolio'], $entry['entry']['pin'] ?? null,
    'the shared repository pins core and exactly its declared participants');
wprism_check_same([
    ['slug' => 'qi-blocks', 'version' => '1.5.2'],
    ['slug' => 'visual-portfolio', 'version' => '3.8.1'],
], $entry['entry']['plugins'] ?? null, 'the scenario installs both exact exercised artifacts');
wprism_check_same(['terms', 'posts'], $entry['entry']['adopt_by_slug'] ?? null,
    'the scenario declares its complete fixture adoption intent');
wprism_check_same([
    'seed' => 'fixtures/seed.sh',
    'capture-check' => 'fixtures/capture-check.sh',
    'postdeploy' => 'fixtures/postdeploy.sh',
    'postapply' => 'fixtures/postapply.sh',
    'check' => 'fixtures/check.sh',
], $entry['entry']['hooks'] ?? null, 'all five hook phases remain scenario-owned');

$runner = (string) file_get_contents($root . '/sandbox/conformance/run.sh');
wprism_check(str_contains($runner, '--scenario=') && str_contains($runner, 'artifact_library_scenario_participants')
    && str_contains($runner, 'conformance pins disagree with its participant record'),
    'the generic runner derives scenario hook and artifact authority from the checked participant record');
wprism_check(!str_contains($runner, 'qi-blocks-visual-portfolio'),
    'the generic runner has no scenario-specific executable path');

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
wprism_check(str_contains($composer['scripts']['lint'] ?? '', 'sandbox integration-scenarios'),
    'the repository syntax gate reaches participant-owned evidence programs');
wprism_check(str_contains($composer['scripts']['cs'] ?? '', 'sandbox/*|integration-scenarios/*'),
    'the changed-file formatter reaches new scenario PHP without touching digest-bound package bytes');

$scripts = [];
foreach (['seed', 'capture-check', 'postdeploy', 'postapply', 'check'] as $phase) {
    $path = $scenarioRoot . '/fixtures/' . $phase . '.sh';
    $scripts[$phase] = (string) file_get_contents($path);
    wprism_check(str_contains($scripts[$phase], 'set -euo pipefail'), "$phase hook fails closed");
}
wprism_check(strpos($scripts['seed'], 'qi-blocks/tests/conformance/seed.sh')
    < strpos($scripts['seed'], 'visual-portfolio/tests/conformance/seed.sh'),
    'the source composes Qi authoring before Visual Portfolio adds its independent roles');
wprism_check(strpos($scripts['postdeploy'], 'qi-blocks/tests/conformance/postdeploy.sh')
    < strpos($scripts['postdeploy'], 'visual-portfolio/tests/conformance/postdeploy.sh')
    && str_contains($scripts['postdeploy'], 'combined complete target preimage'),
    'target setup captures one complete preimage after both package lifecycle fixtures');
wprism_check(str_contains($scripts['postapply'], 'visual-portfolio/tests/conformance/postapply.sh')
    && str_contains($scripts['postapply'], 'evidence.php" --native'),
    'post-Apply evidence runs Visual Portfolio native Save/reopen and the combined oracle');
wprism_check(substr_count($scripts['check'], 'visual-portfolio/tests/conformance/check.sh') === 2
    && str_contains($scripts['check'], 'vp-before-repeat.json')
    && str_contains($scripts['check'], 'vp-stable.json')
    && str_contains($scripts['check'], 'combined zero-write repeated Apply')
    && str_contains($scripts['check'], 'evidence.php" --fixed-point'),
    'final evidence consumes both frontends before and after the shared zero-write fixed point');
$live = (string) file_get_contents($scenarioRoot . '/tests/live/regress_qi_blocks_visual_portfolio.sh');
wprism_check(str_contains($live, 'dirname "${BASH_SOURCE[0]}"')
    && !str_contains($live, 'dirname "\${BASH_SOURCE[0]}"')
    && str_contains($live, 'WPRISM_EXPECTED_SOURCE_SHA:?exact clean source commit required')
    && str_contains($live, 'export WPRISM_SOURCE_ROOT="$REPOSITORY_ROOT" CONF_EXPECTED_SOURCE_SHA="$WPRISM_EXPECTED_SOURCE_SHA"')
    && str_contains($live, 'pair_live_ownership_prepare "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT"')
    && str_contains($live, 'pair_live_ownership_acquire "${WPRISM_DB_ENGINE:-mariadb}"')
    && str_contains($live, 'pair_live_ownership_complete')
    && str_contains($live, 'bash conformance/run.sh --scenario=qi-blocks-visual-portfolio'),
    'the live entrypoint binds its exact worktree and owns pair cleanup around shared conformance');

$claimsProcess = proc_open(
    [PHP_BINARY, $root . '/sandbox/tests/lib/capture_plan_claims.php', $root, '["core","qi-blocks","visual-portfolio"]'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $claimPipes
);
wprism_check(is_resource($claimsProcess), 'the exact shipped-claim projection starts');
$claims = is_resource($claimsProcess) ? stream_get_contents($claimPipes[1]) : '';
$claimErrors = is_resource($claimsProcess) ? stream_get_contents($claimPipes[2]) : '';
$claimsExit = is_resource($claimsProcess) ? proc_close($claimsProcess) : 1;
wprism_check_same(0, $claimsExit, 'the exact shipped-claim projection succeeds');
wprism_check_same('', $claimErrors, 'the exact shipped-claim projection emits no diagnostics');
$decodedClaims = json_decode($claims, true, 32, JSON_THROW_ON_ERROR);
wprism_check_same(['core', 'qi-blocks', 'visual-portfolio'], array_column($decodedClaims, 'name'),
    'the independent claim projection covers the exact scenario pin');

$probe = <<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1"
assert_agent_apply_roundtrip_refusal "$2" 1 "$3"
SH;
$refusal = 'wprism: deploy: refusing before promotion-begin; only certified adapters may enter deployment';
$runProbe = static function (string $claims) use ($probe, $root, $refusal): int {
    $process = proc_open(
        ['bash', '-c', $probe, 'scenario-probe', $root . '/sandbox/conformance/asserts.sh', $claims, $refusal],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return 127;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    return proc_close($process);
};
wprism_check_same(0, $runProbe(trim($claims)),
    'the real aggregate refusal admits one deployable and one host-provider experimental participant');
$allDeploy = array_map(static function (array $claim): array {
    if ($claim['status'] === 'experimental' && !in_array('deploy', $claim['operations'], true)) {
        $claim['operations'][] = 'deploy';
    }
    return $claim;
}, $decodedClaims);
wprism_check($runProbe(json_encode($allDeploy, JSON_THROW_ON_ERROR)) !== 0,
    'the aggregate provider profile refuses when no experimental participant leaves deploy to the host');

$evidence = (string) file_get_contents($scenarioRoot . '/fixtures/evidence.php');
foreach ([
    'count($beforeRows) === 42 && count($targetRows) === 56',
    "'Unmanaged Qi target ' => 8",
    "'Target identity padding ' => 32",
    "'revision' => 2",
    "'adapter_dispositions' => 3",
    "'unchanged' => 26",
    'count($sourceUploads) === 11',
    'count($targetUploads) === 10',
    'tmp-qi-image-500x333.png',
    'tmp-qi-image-800x533.png',
    '$targetUploads === $sourceUploads',
    '$visualPortfolioBefore === $visualPortfolioStable',
    'QiNativeApplyEvidence::css',
] as $boundary) {
    wprism_check(str_contains($evidence, $boundary), "combined oracle retains boundary: $boundary");
}

wprism_check_summary('Qi Blocks + Visual Portfolio integration contract');
