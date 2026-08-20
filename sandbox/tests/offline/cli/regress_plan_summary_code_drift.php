<?php
/**
 * Offline regression: orchestrator status must not hide agent-side
 * code_drift. This needs neither WordPress nor Docker; it pins the exact
 * JSON-to-human summary boundary which previously returned ok=true for a
 * non-empty code_drift array.
 */

require_once __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';

use Duo\Orchestrator\PlanSummary;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$empty = array_fill_keys([
    'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
    'collision', 'delete', 'delete_conflict', 'deleted',
], []);
$empty['code_mismatch'] = [];

$complete = $empty + array_fill_keys([
    'code_drift', 'incomplete_apply', 'incomplete_lifecycle',
    'regen_context', 'regen_pending', 'env_missing', 'missing_user',
    'provider_problems', 'skipped_user_meta',
    'uploads_inventory', 'effects_inventory', 'adapter_dispositions', 'warnings',
], []);
try {
    PlanSummary::assertContract($complete);
    $check(true, 'the complete agent plan envelope is accepted');
} catch (RuntimeException $error) {
    $check(false, 'the complete agent plan envelope is accepted: ' . $error->getMessage());
}
foreach ([
    'empty JSON object' => [],
    'missing required bucket' => array_diff_key($complete, ['warnings' => true]),
    'missing regen-context bucket' => array_diff_key($complete, ['regen_context' => true]),
    'missing provider-problems bucket' => array_diff_key($complete, ['provider_problems' => true]),
    'non-list bucket' => array_replace($complete, ['create' => ['uuid' => 'not-a-list']]),
    'non-string warning' => array_replace($complete, ['warnings' => [['message' => 'not-a-string']]]),
] as $label => $invalid) {
    try {
        PlanSummary::assertContract($invalid);
        $check(false, "$label must fail the plan contract");
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'incomplete plan contract'), "$label fails closed");
    }
}

$drifted = $empty;
$drifted['code_drift'] = [[
    'issue' => 'code_drift',
    'kind' => 'plugin',
    'plugin' => 'example/example.php',
    'installed_version' => '2.0.0',
    'recorded_version' => '1.0.0',
    'message' => 'example/example.php is 2.0.0 on this environment, but the last successful deploy recorded 1.0.0',
]];
$rendered = PlanSummary::render($drifted);
$lines = implode("\n", $rendered['lines']);

$check($rendered['ok'] === false, 'code_drift alone must make status not safe to promote');
$check(str_contains($rendered['lines'][0] ?? '', '1 code_drift'), 'summary must count code_drift');
$check(str_contains($lines, 'CODE_DRIFT'), 'summary must render a CODE_DRIFT section');
$check(str_contains($lines, 'example/example.php'), 'summary must name the drifted component');
$check(
    str_contains($lines, '--force-code-drift'),
    'summary must explain the same code_drift escape hatch as apply'
);

$clean = PlanSummary::render($complete);
$check($clean['ok'] === true, 'a clean plan must remain safe to promote');
$check(str_contains($clean['lines'][0] ?? '', '0 code_drift'), 'clean summary must report zero code_drift');

$incomplete = $empty;
$incomplete['incomplete_lifecycle'] = [[
    'owner' => 'first-sync-owner',
    'artifact_hash' => str_repeat('b', 64),
    'entity' => 'options/core',
    'phase' => 'activate',
    'before_hash' => str_repeat('c', 64),
    'reason' => 'unresolved lifecycle hook attempt; restore the exact pre-lifecycle database checkpoint before retrying',
]];
$incompleteRendered = PlanSummary::render($incomplete);
$incompleteLines = implode("\n", $incompleteRendered['lines']);
$check($incompleteRendered['ok'] === false, 'incomplete_lifecycle alone must make status not safe to promote');
$check(str_contains($incompleteRendered['lines'][0] ?? '', '1 incomplete_lifecycle'), 'summary must count incomplete_lifecycle');
$check(str_contains($incompleteLines, 'INCOMPLETE_LIFECYCLE'), 'summary must render an incomplete lifecycle section');
$check(str_contains($incompleteLines, 'activate options/core'), 'summary must identify the ambiguous phase/entity');
$check(str_contains($incompleteLines, 'exact pre-lifecycle database checkpoint'), 'summary must direct exact checkpoint recovery');
$check(str_contains($incompleteLines, 'force flags cannot bypass'), 'summary must state that lifecycle ambiguity is non-forceable');

// code_revision_stale is deliberately a different code_mismatch member: it
// remains visible and blocks status, but must direct the host deploy workflow
// rather than advertising --force-code-mismatch as an escape hatch.
$stale = $empty;
$staleRevision = str_repeat('a', 64);
$stale['code_mismatch'] = [[
    'issue' => 'code_revision_stale',
    'kind' => 'code',
    'expected_revision' => $staleRevision,
    'completed_revision' => null,
    'message' => 'compiled code payload has not been finalized here',
]];
$staleRendered = PlanSummary::render($stale);
$staleLines = implode("\n", $staleRendered['lines']);
$check($staleRendered['ok'] === false, 'code_revision_stale alone must make status not safe to promote');
$check(str_contains($staleLines, 'CODE_REVISION_STALE'), 'stale code revision must have its own visible section');
$check(str_contains($staleLines, $staleRevision), 'stale code revision must name the expected revision');
$check(str_contains($staleLines, 'duo deploy <env>'), 'stale code revision must direct host deploy recovery');
$check(str_contains($staleLines, 'cannot be bypassed by force flags'), 'stale code revision must state non-forceable ordering');
$check(!str_contains($staleLines, '--force-code-mismatch'), 'stale-only status must not advertise a force-code-mismatch bypass');

$runtimeBlocked = $empty;
$runtimeBlocked['code_mismatch'] = [[
    'issue' => 'code_source_requires_php_incompatible',
    'kind' => 'plugin',
    'plugin' => 'inactive/inactive.php',
    'path' => 'plugins/inactive/inactive.php',
    'code_revision' => str_repeat('d', 64),
    'component_sha256' => str_repeat('e', 64),
    'required_version' => '8.4',
    'target_version' => '8.3.0',
    'non_forceable' => true,
    'message' => "plugin 'inactive/inactive.php' requires PHP >=8.4, but target PHP is 8.3.0",
]];
$runtimeRendered = PlanSummary::render($runtimeBlocked);
$runtimeLines = implode("\n", $runtimeRendered['lines']);
$check($runtimeRendered['ok'] === false, 'runtime incompatibility alone must make status not safe to promote');
$check(str_contains($runtimeLines, 'CODE_RUNTIME_INCOMPATIBLE'), 'runtime incompatibility has its own visible section');
$check(str_contains($runtimeLines, 'inactive/inactive.php'), 'runtime incompatibility names the frozen component');
$check(str_contains($runtimeLines, '8.4') && str_contains($runtimeLines, '8.3.0'), 'runtime incompatibility reports requirement and target values');
$check(str_contains($runtimeLines, 'non-forceable'), 'runtime incompatibility states its non-forceable safety contract');
$check(!str_contains($runtimeLines, '--force-code-mismatch'), 'runtime incompatibility never advertises the lifecycle mismatch bypass');

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ok: PlanSummary blocks code drift, runtime incompatibility, stale revisions, and incomplete lifecycle receipts\n";
