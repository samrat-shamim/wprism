<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/StatusCommand.php';

use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\PlanContract;
use Duo\Orchestrator\PlanSummary;
use Duo\Orchestrator\StatusCommand;

function fail_status_command(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function assert_status_command(bool $condition, string $message): void { if (!$condition) fail_status_command($message); }

final class StatusCommandDriver implements EnvironmentDriver {
    public int $calls = 0;
    public function __construct(private string $mode) {}
    public function name(): string { return 'status-fixture'; }
    public function driverId(): string { return 'status-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'status fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array {
        $this->calls++;
        if ($this->mode === 'failure') return ['exit' => 9, 'stdout' => '', 'stderr' => 'plan unavailable'];
        if ($this->mode === 'malformed') return ['exit' => 0, 'stdout' => '{}', 'stderr' => ''];
        $plan = [];
        foreach (PlanContract::requiredBuckets() as $bucket) $plan[$bucket] = [];
        if ($this->mode === 'env-missing') {
            $name = 'outfitters_catalog_gateway_secret';
            $plan['env_missing'] = [['name' => $name, 'required' => true]];
            $plan['warnings'] = [
                "env_missing: option '$name' is required and not yet provisioned on "
                    . "this environment — see 'wp duo env-set --name=$name --stdin'",
                'env_missing: separate diagnostic must remain visible',
            ];
        }
        if ($this->mode === 'unsafe-env-missing') {
            $plan['env_missing'] = [['name' => "unsafe\0option", 'required' => true]];
        }
        // DUO-3502: a tombstone this environment still holds. An ordinary
        // apply performs no deletion at all without --with-deletes
        // (agent/src/Apply/ApplyPreparationCoordinator.php:167-169 leaves
        // $executeDeletes false and AuthoredTransactionExecutor.php:222-253
        // skips its whole delete block), yet the revision is still recorded
        // as applied — so the row survives every promote until somebody
        // authorizes it, and readiness must say so.
        if ($this->mode === 'pending-delete') {
            $plan['delete'] = [[
                'uuid' => '9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29',
                'type' => 'post',
                'path' => 'state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json',
            ]];
        }
        return ['exit' => 0, 'stdout' => json_encode($plan) . "\n", 'stderr' => ''];
    }
    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('status-fixture', 'status-fixture', $operation, []);
    }
}

$plan = new StatusCommandDriver('plan');
$authorityCalls = 0;
ob_start();
$planExit = StatusCommand::run(
    $plan,
    [],
    static function (string $code, string $message, string $remediation): void { echo "[$code] $message\n"; },
    static function (array $refusal): void { echo "refused\n"; },
    static function (EnvironmentDriver $driver) use (&$authorityCalls): bool { $authorityCalls++; return true; }
);
$planOutput = (string) ob_get_clean();
assert_status_command($planExit === 0, 'complete clean plan exits successfully');
assert_status_command($plan->calls === 1 && $authorityCalls === 1, 'status performs one plan read and authority check');
assert_status_command(str_contains($planOutput, 'plan:'), 'status renders the canonical plan summary');

$envMissing = new StatusCommandDriver('env-missing');
ob_start();
$envMissingExit = StatusCommand::run(
    $envMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
$envMissingOutput = (string) ob_get_clean();
assert_status_command($envMissingExit === 1, 'required env value keeps status non-ready');
assert_status_command(
    str_contains(
        $envMissingOutput,
        'run: `duo env-set status-fixture --name=outfitters_catalog_gateway_secret --stdin`'
    ),
    'host status renders the exact environment-bound secret remediation'
);
assert_status_command(
    !str_contains($envMissingOutput, 'wp duo env-set'),
    'host status does not mix target-side env-set advice into host remediation'
);
assert_status_command(
    str_contains($envMissingOutput, 'WARNING: env_missing: separate diagnostic must remain visible'),
    'host status suppresses only the exact duplicate target warning'
);

$customRegistryMissing = new StatusCommandDriver('env-missing');
ob_start();
$customRegistryExit = StatusCommand::run(
    $customRegistryMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true,
    '/tmp/custom registry.json'
);
$customRegistryOutput = (string) ob_get_clean();
assert_status_command($customRegistryExit === 1, 'custom-registry env value keeps status non-ready');
assert_status_command(
    str_contains(
        $customRegistryOutput,
        "run: `duo '--envs-file=/tmp/custom registry.json' env-set status-fixture "
            . '--name=outfitters_catalog_gateway_secret --stdin`'
    ),
    'status remediation preserves the exact operator-selected registry binding'
);

$unsafeRegistryMissing = new StatusCommandDriver('env-missing');
ob_start();
$unsafeRegistryExit = StatusCommand::run(
    $unsafeRegistryMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true,
    "/tmp/registry\nINJECT"
);
$unsafeRegistryOutput = (string) ob_get_clean();
assert_status_command(
    $unsafeRegistryExit === 1
        && str_contains($unsafeRegistryOutput, 'correct the selected environment registry path')
        && !str_contains($unsafeRegistryOutput, 'INJECT')
        && !str_contains($unsafeRegistryOutput, "\x1b"),
    'unsafe selected registry produces bounded one-line remediation without reproducing it'
);

$hostSource = (string) file_get_contents(__DIR__ . '/../../../../cli/duo');
assert_status_command(
    str_contains($hostSource, "'status' => cmd_status(\$transport, \$extra, \$envsFileOverride)")
        && str_contains($hostSource, 'cmd_status($driver, [], $envsFileOverride)'),
    'public status and init follow-up both preserve the selected registry through the thin facade'
);

$unsafeEnvMissing = new StatusCommandDriver('unsafe-env-missing');
ob_start();
$unsafeEnvMissingExit = StatusCommand::run(
    $unsafeEnvMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
$unsafeEnvMissingOutput = (string) ob_get_clean();
assert_status_command($unsafeEnvMissingExit === 1, 'control-bearing option name remains non-ready');
assert_status_command(
    str_contains($unsafeEnvMissingOutput, '<invalid-name>')
        && str_contains($unsafeEnvMissingOutput, 'cannot render a safe command')
        && !str_contains($unsafeEnvMissingOutput, "unsafe\0option"),
    'control-bearing option name produces bounded one-line remediation instead of throwing'
);

$unsafeEnvironmentPlan = [];
foreach (PlanContract::requiredBuckets() as $bucket) $unsafeEnvironmentPlan[$bucket] = [];
$unsafeEnvironmentPlan['env_missing'] = [['name' => 'secret_name', 'required' => true]];
$unsafeEnvironmentLines = implode("\n", PlanSummary::render(
    $unsafeEnvironmentPlan,
    [],
    '--envs-file=/tmp/other'
)['lines']);
assert_status_command(
    str_contains($unsafeEnvironmentLines, 'cannot render a safe command')
        && !str_contains($unsafeEnvironmentLines, 'duo env-set --envs-file='),
    'option-looking environment never renders as a positional command token'
);

$pendingDelete = new StatusCommandDriver('pending-delete');
ob_start();
$pendingDeleteExit = StatusCommand::run(
    $pendingDelete,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
$pendingDeleteOutput = (string) ob_get_clean();
assert_status_command(
    $pendingDeleteExit === 1,
    'a planned deletion this environment still holds keeps status non-ready'
);
assert_status_command(
    str_contains(
        $pendingDeleteOutput,
        "pending deletes (the repository authored these deletions and this environment still holds them):\n"
            . '  - state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json'
    ),
    'status itemizes the pending deletion the way it itemizes a blocked one, not as a bare count'
);
assert_status_command(
    str_contains(
        $pendingDeleteOutput,
        'planned deletions are not authorized — an ordinary promote performs none of them; '
            . 'rerun with `duo promote status-fixture --with-deletes` once these are the deletions you intend'
    ),
    'host status renders the exact authorizing promote command for this environment'
);

// --with-deletes is destructive authority, so the rendered command must carry
// the operator-selected registry for the same reason the env-set remediation
// above does: a same-named environment resolving through an auto-discovered
// registry would authorize deletions against another site.
$pendingDeleteRegistry = new StatusCommandDriver('pending-delete');
ob_start();
$pendingDeleteRegistryExit = StatusCommand::run(
    $pendingDeleteRegistry,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true,
    '/tmp/custom registry.json'
);
$pendingDeleteRegistryOutput = (string) ob_get_clean();
assert_status_command(
    $pendingDeleteRegistryExit === 1
        && str_contains(
            $pendingDeleteRegistryOutput,
            "rerun with `duo '--envs-file=/tmp/custom registry.json' promote status-fixture --with-deletes`"
        ),
    'the deletion authorization command preserves the exact operator-selected registry binding'
);

// No environment is owned by the caller (`wp duo plan` read directly, or an
// unsafe environment token): the target-side command takes neither a positional
// environment nor a registry, so it is always exact.
$targetSidePlan = [];
foreach (PlanContract::requiredBuckets() as $bucket) $targetSidePlan[$bucket] = [];
$targetSidePlan['delete'] = [['uuid' => 'options/core', 'type' => 'options']];
$targetSideLines = implode("\n", PlanSummary::render($targetSidePlan)['lines']);
assert_status_command(
    str_contains($targetSideLines, "pending deletes (") && str_contains($targetSideLines, '  - options options/core'),
    'a caller with no environment registry still itemizes every pending deletion'
);
assert_status_command(
    str_contains(
        $targetSideLines,
        'planned deletions are not authorized — an ordinary apply performs none of them; '
            . 'rerun with `wp duo apply --with-deletes` once these are the deletions you intend'
    ),
    'target-side readiness renders the target-side authorization command'
);
assert_status_command(
    PlanSummary::render($targetSidePlan)['ok'] === false,
    'a pending deletion alone makes readiness false — apply will not perform it and the revision advances anyway'
);

// `duo release` owns the deletion-authorization decision itself: it refuses a
// pending deletion by name with `release_deletes_not_authorized` and no gap
// action (cli/src/Release/AuthorizationPlan.php:613-625). Counting the bucket
// in `ok` for that caller shadowed the reviewed refusal behind the generic
// `release_target_not_clean` one (ReleaseCommand.php:271-293), whose remedy —
// capture/refresh/rebase — is the wrong answer for a plan that needs a flag.
// The opt-out drops that one term and its remedy line, and nothing else.
$releasePlan = [];
foreach (PlanContract::requiredBuckets() as $bucket) $releasePlan[$bucket] = [];
$releasePlan['delete'] = [[
    'uuid' => '9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29',
    'type' => 'post',
    'path' => 'state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json',
]];
$releaseRendered = PlanSummary::render($releasePlan, [], 'status-fixture', null, [], true);
assert_status_command(
    $releaseRendered['ok'] === true,
    'a caller owning deletion authority is not blocked by the pending deletion its own gate refuses'
);
assert_status_command(
    PlanSummary::render($releasePlan, [], 'status-fixture')['ok'] === false,
    'the same plan is still not ready for an ordinary promote — the opt-out is per caller, not global'
);
$releaseLines = implode("\n", $releaseRendered['lines']);
assert_status_command(
    str_contains($releaseLines, '  - state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json')
        && !str_contains($releaseLines, 'planned deletions are not authorized'),
    'the rows stay itemized while the host-status remedy gives way to the caller own refusal text'
);

// The opt-out is exactly one term wide: a referential guard still makes the
// same plan not-clean, so release still refuses before it authorizes anything.
$releaseBlocked = $releasePlan;
$releaseBlocked['delete'][0]['blocked'] = 'comments reference this post — 1 row(s)';
assert_status_command(
    PlanSummary::render($releaseBlocked, [], 'status-fixture', null, [], true)['ok'] === false,
    'a guard-blocked deletion still blocks a caller that owns ordinary deletion authority'
);
$releaseConflict = $releasePlan;
$releaseConflict['delete'] = [];
$releaseConflict['delete_conflict'] = [[
    'uuid' => '1d5b7e40-88c2-4f6a-9e33-70a1c2b3d4e5',
    'type' => 'post',
    'path' => 'state/deletions/1d5b7e40-88c2-4f6a-9e33-70a1c2b3d4e5.json',
    'reason' => 'target entity changed locally since the tombstone base',
]];
assert_status_command(
    PlanSummary::render($releaseConflict, [], 'status-fixture', null, [], true)['ok'] === false,
    'a deletion conflict still blocks that caller — only the ordinary pending bucket is its own to authorize'
);

$malformed = new StatusCommandDriver('malformed');
ob_start();
$malformedExit = StatusCommand::run(
    $malformed,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
$malformedOutput = (string) ob_get_clean();
assert_status_command($malformedExit === 1 && !str_contains($malformedOutput, 'plan:'), 'malformed plan refuses before readiness rendering');

$failure = new StatusCommandDriver('failure');
$failureExit = StatusCommand::run(
    $failure,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
assert_status_command($failureExit === 9, 'status preserves target plan failure exit');

echo "PASS: status command\n";
