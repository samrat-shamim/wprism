<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/RefreshCommand.php';

use Duo\Canon;
use Duo\Orchestrator\CommandOutput;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\Refresh;
use Duo\Orchestrator\RefreshCommand;
use Duo\ScopeContract;

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

/** @return array<string,mixed> a structurally valid but source-unassociated option contract */
function option_scope_contract_for_refresh_command(): array {
    $hash = str_repeat('a', 64);
    $contract = [
        'code_diagnostic' => null,
        'eligible_surfaces' => ['option:blogname'],
        'exclusions' => [
            'code' => 'excluded from scope semantics',
            'lifecycle' => 'excluded from scope semantics',
            'mutation_execution' => 'deferred to the target operation',
            'target_guard_witnesses' => 'excluded from immutable evidence',
        ],
        'format' => ScopeContract::FORMAT,
        'live' => ['closure' => [], 'excluded' => [], 'inbound' => [], 'roots' => [[
            'entity' => 'options/core#blogname', 'entity_hash' => $hash, 'option' => 'blogname',
            'path' => 'options/core.json',
            'provenance' => ['kind' => 'root', 'selector' => 'option:blogname'],
            'source_hash' => $hash, 'type' => 'option',
        ]]],
        'media' => [], 'mutation_authority' => false, 'potential_actions' => [],
        'potential_effects' => [], 'potential_providers' => [],
        'purpose' => 'read-only scope evidence; never mutation authority', 'read_only_evidence' => true,
        'resolution' => ['live_root_entities' => ['options/core#blogname'], 'tombstone_uuids' => []],
        'selectors' => ['option:blogname'],
        'source' => ['artifact_hash' => $hash, 'manifest_hash' => $hash, 'state_revision_hash' => $hash],
        'tombstones' => [], 'uploads' => [],
    ];
    $contract['scope_hash'] = hash('sha256', Canon::encode($contract));
    return ScopeContract::from_array($contract);
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

// DUO merge-check slice, brief item (iii): `--format=json` WITHOUT
// `--field-diff` used to be refused at the argument gate
// (`($json && !$fieldDiff)`), so a CI job could never read the semantic plan
// as a document at all. It now reaches Refresh::refresh() and publishes the
// already-canonical duo-refresh-plan/v1. Against the prior defect this
// envelope said `invalid_arguments` and the planner was never entered; the
// reason code is the observable proof the gate moved.
$planJsonDriver = new RefreshCommandDriver();
ob_start();
$planJsonExit = RefreshCommand::run($planJsonDriver, ['--production-ref=v1', '--format=json']);
$planJson = (string) ob_get_clean();
$planPayload = json_decode($planJson, true);
assert_refresh_command($planJsonExit === 1, 'a refusal after the argument gate keeps the established JSON exit');
assert_refresh_command(is_array($planPayload)
    && ($planPayload['format'] ?? null) === 'duo-command-refusal/v1'
    && ($planPayload['command'] ?? null) === 'refresh'
    && ($planPayload['reason_code'] ?? null) === 'plan_unavailable',
    '--format=json without --field-diff passes the argument gate and names the artifact that was unavailable');
assert_refresh_command(!str_contains($planJson, 'invalid_arguments'),
    'valid arguments are no longer reported as invalid ones');

// The human path for the SAME inputs is deliberately untouched by that
// change (AGENTS.md rule 8): no envelope, diagnostics on stderr, exit 1.
$humanPlanDriver = new RefreshCommandDriver();
ob_start();
$humanPlanExit = RefreshCommand::run($humanPlanDriver, ['--production-ref=v1']);
$humanPlan = (string) ob_get_clean();
assert_refresh_command($humanPlanExit === 1 && $humanPlan === '',
    'human refresh keeps its stdout-clean, exit-1 refusal shape');

// And the field-diff combination keeps its own reason code, so the new branch
// did not annex an existing one.
$fieldJsonDriver = new RefreshCommandDriver();
ob_start();
RefreshCommand::run($fieldJsonDriver, ['--production-ref=v1', '--field-diff', '--format=json']);
$fieldJson = (string) ob_get_clean();
assert_refresh_command((json_decode($fieldJson, true)['reason_code'] ?? null) === 'field_level_unavailable',
    '--field-diff --format=json still reports the field-level artifact, not the plan');

$source = file_get_contents(__DIR__ . '/../../../../cli/duo');
assert_refresh_command(is_string($source)
    && str_contains($source, 'return RefreshCommand::run($t, $extra);')
    && !str_contains($source, 'function refresh_field_diff_refusal('),
    'cli/duo retains only the refresh compatibility facade');
assert_refresh_command((new ReflectionMethod(RefreshCommand::class, 'run'))->isStatic(),
    'refresh handler exposes a standalone static boundary');
assert_refresh_command(CommandOutput::wantsAgentRefusalJson('refresh', ['--format=json']),
    'refresh uses the shared host refusal-format detector');
$refreshSource = file_get_contents(__DIR__ . '/../../../../cli/src/Refresh/Refresh.php');
assert_refresh_command(is_string($refreshSource)
    && !str_contains($refreshSource, "assert_mutation_supported(\$scopeContract, 'scoped refresh')"),
    'public refresh reaches the record-aware scoped-refresh path instead of rejecting a valid option root at its host boundary');

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

// DUO-3494: the human field-diff renderer keeps its OWN closed vocabularies
// on purpose -- it must never echo a label the projector did not declare -- so
// a new field label that reaches the projector and not this list would silently
// render as `record`, which is a different and wrong statement about what the
// operator has to decide. This asserts the two lists moved together.
$renderFieldDiff = new ReflectionMethod(RefreshCommand::class, 'renderFieldDiff');
$bodyRecordSelector = str_repeat('a', 64);
$bodyFieldSelector = str_repeat('b', 64);
$bodyRelation = [
    'base' => 'present', 'branch' => 'present', 'branch_vs_base' => 'different',
    'branch_vs_production' => 'different', 'production' => 'present', 'production_vs_base' => 'same',
];
ob_start();
$renderFieldDiff->invoke(null, [
    'diff_hash' => str_repeat('c', 64),
    'records' => [[
        'entity' => 'post',
        'mode' => 'fields',
        'reason' => 'eligible_engine_fields',
        'record_selector_sha256' => $bodyRecordSelector,
        'changes' => [[
            'category' => 'branch-only',
            'field' => 'post.body.branch_blocks',
            'field_selector_sha256' => $bodyFieldSelector,
            'hash_status' => 'withheld',
            'record_selector_sha256' => $bodyRecordSelector,
            'relation' => $bodyRelation,
            'scope' => 'field',
        ]],
    ], [
        'entity' => 'post',
        'mode' => 'record',
        'reason' => 'body_block_overlap',
        'record_selector_sha256' => str_repeat('d', 64),
        'changes' => [[
            'category' => 'conflicting',
            'field' => 'record',
            'field_selector_sha256' => str_repeat('e', 64),
            'hash_status' => 'withheld',
            'reason' => 'body_block_overlap',
            'record_selector_sha256' => str_repeat('d', 64),
            'relation' => $bodyRelation,
            'scope' => 'record',
        ]],
    ]],
]);
$renderedFieldDiff = (string) ob_get_clean();
assert_refresh_command(
    str_contains($renderedFieldDiff, 'post.body.branch_blocks branch-only field eligible_engine_fields'),
    'the human field-diff renderer names a post-body partition instead of collapsing it to `record`'
);
assert_refresh_command(
    str_contains($renderedFieldDiff, ': body_block_overlap'),
    'the human field-diff renderer names the body-overlap refusal instead of collapsing it to `opaque_record_type`'
);

echo "PASS: refresh command\n";
