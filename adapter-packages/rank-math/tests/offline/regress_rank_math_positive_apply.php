<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';

use WPrismTest\ShellProbe;

$root = dirname(__DIR__, 4);
// An explicit alternate checkout supports a read-only old-source counterfactual.
$sourceRoot = $argv[1] ?? $root;
$source = (string) file_get_contents($sourceRoot . '/adapter-packages/rank-math/tests/conformance/check.sh');
$receipt = ['source' => 'provider:rank-math-state/rebuild_all_link_state', 'verified' => true, 'after' => ['link_count' => 2]];
$answer = ['warnings' => ['native action fired: fixture (verified)'], 'canary' => 'clean',
    'verification' => ['result' => 'pass'], 'applied' => 1,
    'plan' => ['conflict' => 1, 'update' => 1, 'env_missing' => 1], 'actions' => [$receipt]];
$cases = [
    ['ZERO_APPLY', "pass 'zero-change", array_replace($answer, ['actions' => [], 'applied' => 0])],
    ['RETRY', "pass 'unreviewed callback", $answer],
    ['FORCED', '[ "$(wp_conf2 post meta get', $answer],
    ['SCHEMA_BASELINE_APPLY', 'wp_conf2 plugin deactivate seo-by-rank-math', $answer],
    ['RESTORE', '[ "$(wp_conf2 option get wprism_rank_math_target_neighbor)', $answer],
    ['REINSTALL_APPLY', 'REINSTALLED=$(observe_rank_math', $answer],
];
foreach ($cases as [$variable, $end, $payload]) {
    $block = ShellProbe::captureBlock($source, $variable, $end);
    ShellProbe::positiveApply($root, $block, $payload, 'Rank Math ' . $variable);
}
$scoped = array_replace($answer, ['format' => 'wprism-scoped-apply-result/v1', 'scoped_receipt' => ['phase' => 'complete'],
    'actions' => [array_merge(array_fill_keys(['capability_digest', 'operation_hash', 'receipt_hash', 'source_hash'], str_repeat('a', 64)),
        ['format' => 'wprism-scoped-effect-receipt/v1', 'kind' => 'provider', 'status' => 'verified', 'verified' => true])]]);
ShellProbe::positiveApply($root, ShellProbe::captureBlock($source, 'RANK_SCOPED_APPLY', 'RANK_SCOPED_TARGET_POST='),
    $scoped, 'Rank Math scoped Apply');
$replay = array_replace($answer, ['format' => 'wprism-scoped-apply-result/v1', 'replayed' => true,
    'applied' => 0, 'actions' => [], 'verification' => null]);
ShellProbe::positiveApply($root, ShellProbe::captureBlock($source, 'RANK_SCOPED_REPLAY', 'rm -f "$RANK_SCOPE_PATH"'),
    $replay, 'Rank Math terminal replay', '', true);

$matrix = (string) file_get_contents($sourceRoot . '/adapter-packages/rank-math/tests/certify/version-matrix.sh');
$driver = (string) file_get_contents($root . '/sandbox/tests/certify/certify_version_matrix.sh');
preg_match('/^assert_version_matrix_apply_ready\(\).*?^\}/ms', $driver, $matrixGuard);
preg_match('/^assert_no_php_diagnostics\(\).*?^\}/ms', $driver, $phpGuard);
wprism_check(isset($matrixGuard[0], $phpGuard[0]), 'matrix acceptance uses both actual shared definitions');
$offset = 0;
foreach (['check_rank_math_boundary_content', 'UPGRADE_TARGET_MODULES=', 'RANK_MATH_VERSION=1.0.277.1'] as $phase => $end) {
    $block = ShellProbe::captureBlock($matrix, 'RANK_MATH_BOUNDARY_APPLY_JSON', $end, $offset);
    $start = strpos($matrix, $block, $offset);
    $offset = $start + strlen($block);
    ShellProbe::positiveApply($root, $block, $answer, 'Rank Math exact-version phase ' . $phase,
        ($matrixGuard[0] ?? '') . "\n" . ($phpGuard[0] ?? ''));
}

$start = strpos($source, 'for result in A B; do');
$end = $start === false ? false : strpos($source, 'rm -f "$CONCURRENT_A" "$CONCURRENT_B"', $start);
wprism_check(is_int($start) && is_int($end), 'competing applies retain their actual per-exit acceptance block');
$competing = $start === false || $end === false ? '' : substr($source, $start, $end - $start);
$preamble = <<<'SH'
set -euo pipefail
fail() { printf '%s\n' "$*" >&2; exit 1; }
. "$1/sandbox/conformance/asserts.sh"
probe_dir=$(mktemp -d "${TMPDIR:-/tmp}/wprism-competing-apply.XXXXXX")
trap 'rm -rf -- "$probe_dir"' EXIT
RC_A=0 RC_B=1
CONCURRENT_A="$probe_dir/a.log" CONCURRENT_B="$probe_dir/b.log"
printf '%s\n' "$2" > "$CONCURRENT_A"
printf 'Error: wprism another apply in progress\n' > "$CONCURRENT_B"
SH;
$human = 'Success: applied 1 entities (canary clean)';
foreach (['ready' => $human, 'lifecycle-receipt' => "Warning: native action fired: fixture (verified)\n" . $human,
    'php' => "PHP Warning: fixture in /fixture.php on line 1\n" . $human,
    'required' => "Warning: env_missing: fixture intent\n" . $human,
    'no-canary' => 'Success: applied 1 entities'] as $mutation => $output) {
    [$status, $stdout] = ShellProbe::run($preamble . "\n" . $competing . "\nprintf 'COMPETING_READY\\n'\n", [$root, $output], $root);
    $healthy = in_array($mutation, ['ready', 'lifecycle-receipt'], true);
    wprism_check($healthy ? $status === 0 && str_contains($stdout, 'COMPETING_READY')
        : $status !== 0 && !str_contains($stdout, 'COMPETING_READY'),
        'Rank Math actual concurrent acceptance classifies ' . $mutation);
}
wprism_check_summary('regress_rank_math_positive_apply');
