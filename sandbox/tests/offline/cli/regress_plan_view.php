<?php
/**
 * Offline regression for issue #3345's bounded plan-view slice.
 *
 * It drives the real agent projection and separately deployable host twin
 * against a deliberately reversed, secret-shaped complete plan. No
 * WordPress, database, provider, Docker, or live pair is involved.
 */
declare(strict_types=1);

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 2);
}
require_once __DIR__ . '/../../../../agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../../../../agent/src/Review/PlanExplanation.php';
require_once __DIR__ . '/../../../../agent/src/Review/PlanCategorySummary.php';
require_once __DIR__ . '/../../../../agent/src/Review/PlanView.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanContract.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanView.php';

use WPrism\CommandRefusalException;
use WPrism\PlanCategorySummary;
use WPrism\PlanView as AgentPlanView;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\DriverCapability;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\PlanContract;
use WPrism\Orchestrator\PlanSummary;
use WPrism\Orchestrator\PlanView as HostPlanView;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

/** @return array<string,list<mixed>> */
$emptyPlan = static function (): array {
    return array_fill_keys([
        'adapter_dispositions', 'adopt', 'code_drift', 'code_mismatch',
        'collision', 'conflict', 'create', 'delete', 'delete_conflict',
        'deleted', 'drift', 'effects_inventory', 'env_missing',
        'incomplete_apply', 'incomplete_lifecycle', 'missing_user',
        'provider_problems', 'regen_context', 'regen_pending', 'skipped_user_meta',
        'unchanged', 'update', 'uploads_inventory', 'warnings',
    ], []);
};

$plan = $emptyPlan();
$tree = [];
$deletions = [];

// Insert the ordinary create rows in reverse UUID order. The projection must
// select create-001/create-002 at limit=2 regardless of authoritative source
// order, while the detailed create bucket remains untouched.
for ($n = 205; $n >= 1; $n--) {
    $uuid = sprintf('create-%03d', $n);
    $isAttachment = $n % 2 === 0;
    $row = [
        'uuid' => $uuid,
        'type' => 'post',
        'path' => "posts/SECRET_PATH_$uuid.md",
    ];
    if ($n === 1) {
        $row['title'] = "SECRET_TITLE\x1b[31m\nINJECTED";
    }
    $plan['create'][] = $row;
    $tree[$uuid] = ['type' => 'post', 'data' => ['type' => $isAttachment ? 'attachment' : 'page']];
}

$plan['update'][] = [
    'uuid' => 'update-attachment', 'type' => 'post',
    'path' => 'posts/SECRET_UPDATE_ATTACHMENT.md', 'title' => 'SECRET_UPDATE_TITLE',
];
$tree['update-attachment'] = ['type' => 'post', 'data' => ['type' => 'attachment']];
$plan['update'][] = [
    'uuid' => 'update-table', 'type' => 'acme_records', 'path' => 'tables/SECRET_UPDATE.json',
];
$tree['update-table'] = ['type' => 'acme_records', 'data' => []];
$plan['adopt'][] = ['uuid' => 'adopt-term', 'type' => 'term', 'path' => 'terms/SECRET_TERM.json'];
$tree['adopt-term'] = ['type' => 'term', 'data' => ['taxonomy' => 'category']];
$plan['unchanged'][] = ['uuid' => 'unchanged-menu', 'type' => 'menu', 'path' => 'menus/SECRET_MENU.json'];
$tree['unchanged-menu'] = ['type' => 'menu', 'data' => []];

// Safety source order is deliberately the opposite of UUID order. This proves
// that projection order and selector binding do not depend on authoritative
// source positions.
$plan['drift'][] = ['uuid' => 'drift-z', 'type' => 'sidebar', 'path' => 'sidebars/SECRET_DRIFT_Z.json'];
$plan['drift'][] = ['uuid' => 'drift-a', 'type' => 'sidebar', 'path' => "sidebars/SECRET_DRIFT_A\x1b\nINJECT.json"];
$tree['drift-z'] = ['type' => 'sidebar', 'data' => []];
$tree['drift-a'] = ['type' => 'sidebar', 'data' => []];
$plan['conflict'][] = ['uuid' => 'conflict-options', 'type' => 'options', 'path' => 'options/SECRET_CONFLICT.json'];
$tree['conflict-options'] = ['type' => 'options', 'data' => []];
$plan['collision'][] = ['uuid' => 'collision-meta', 'type' => 'user-meta', 'path' => 'user-meta/SECRET_COLLISION.json', 'env_id' => 7];
$tree['collision-meta'] = ['type' => 'user-meta', 'data' => []];

$plan['delete'][] = [
    'uuid' => 'delete-blocked-attachment', 'type' => 'post',
    'path' => 'deletions/SECRET_BLOCKED.json', 'blocked' => 'SECRET_BLOCKED_REASON',
];
$deletions['delete-blocked-attachment'] = ['data' => ['kind' => 'post', 'type' => 'attachment']];
$plan['delete'][] = ['uuid' => 'delete-menu', 'type' => 'menu', 'path' => 'deletions/SECRET_MENU.json'];
$deletions['delete-menu'] = ['data' => ['kind' => 'menu', 'type' => 'nav_menu']];
$plan['delete_conflict'][] = ['uuid' => 'delete-conflict-table', 'type' => 'acme_records', 'path' => 'deletions/SECRET_TABLE.json'];
$deletions['delete-conflict-table'] = ['data' => ['kind' => 'table', 'type' => 'acme_records']];
$plan['deleted'][] = ['uuid' => 'deleted-post', 'type' => 'post', 'path' => 'deletions/SECRET_DELETED.json'];
$deletions['deleted-post'] = ['data' => ['kind' => 'post', 'type' => 'page']];

// Global diagnostics must remain full-plan counts/readiness evidence even
// when every requested entity row is from create/update.
$plan['code_mismatch'][] = ['issue' => 'missing_in_code', 'kind' => 'plugin', 'plugin' => 'SECRET_PLUGIN'];
$plan['code_drift'][] = ['issue' => 'code_drift', 'kind' => 'theme', 'theme' => 'SECRET_THEME'];
$plan['incomplete_apply'][] = ['reason' => 'SECRET_APPLY'];
$plan['incomplete_lifecycle'][] = ['phase' => 'activate', 'entity' => 'SECRET_LIFECYCLE'];
$plan['regen_pending'][] = ['uuid' => 'SECRET_REGEN', 'type' => 'post'];
$plan['regen_context'][] = ['uuid' => 'SECRET_CONTEXT', 'type' => 'post'];
$plan['env_missing'][] = ['name' => 'SECRET_REQUIRED_ENV', 'required' => true];
$plan['missing_user'][] = ['login' => 'SECRET_LOGIN'];
$plan['skipped_user_meta'][] = ['login' => 'SECRET_SKIPPED_LOGIN'];
$plan['adapter_dispositions'][] = ['name' => 'SECRET_CAPABILITY'];
$plan['provider_problems'][] = ['provider' => 'SECRET_PROVIDER'];
$plan['uploads_inventory'][] = ['original_path' => 'SECRET_UPLOAD'];
$plan['effects_inventory'][] = ['phase' => 'rebuild', 'effect' => ['id' => 'SECRET_EFFECT']];
$plan['warnings'][] = 'SECRET_WARNING';

$summaryContext = [
    'selected_native_actions' => 0,
    'selected_provider_actions' => 0,
    'certification_source_blockers' => 0,
    'selected_provider_blockers' => 0,
    'nested_menu_item_delete_candidates' => 0,
    'nested_widget_delete_candidates' => 0,
    'nested_option_delete_candidates' => 0,
];
$categorySummary = PlanCategorySummary::build($plan, $tree, $deletions, $summaryContext);
$check(is_array($categorySummary), 'fixture has a same-snapshot valid category summary for category filters');
if (!is_array($categorySummary)) {
    exit(1);
}
$plan['category_summary'] = $categorySummary;

$request = AgentPlanView::requestFromAssoc([
    'category' => 'media,authored_state,media',
    'action' => 'update,create',
    'entity' => 'attachment,post',
    'limit' => '2',
]);
$check($request === [
    'category' => ['authored_state', 'media'],
    'action' => ['create', 'update'],
    'entity' => ['post', 'attachment'],
    'cursor' => null,
    'limit' => 2,
], 'agent parser canonicalizes/dedupes closed CSV dimensions and a canonical limit');
if ($request === null) {
    fwrite(STDERR, "FAIL: explicit view request was absent\n");
    exit(1);
}
$check(AgentPlanView::requestFromAssoc([]) === null,
    'no view flags preserve the no-projection attachment path');
foreach (['1' => 1, '200' => 200] as $rawLimit => $expectedLimit) {
    $rawLimit = (string) $rawLimit;
    $parsed = AgentPlanView::requestFromAssoc(['limit' => $rawLimit]);
    $check($parsed !== null && $parsed['limit'] === $expectedLimit,
        "canonical limit $rawLimit is accepted at the closed boundary");
}
foreach (['0', '201'] as $rawLimit) {
    try {
        AgentPlanView::requestFromAssoc(['limit' => $rawLimit]);
        $check(false, "out-of-range limit $rawLimit is rejected");
    } catch (CommandRefusalException $e) {
        $check($e->reasonCode === 'invalid_arguments',
            "out-of-range limit $rawLimit is typed-refused");
    }
}

$view = AgentPlanView::build($plan, $tree, $deletions, $request);
$check(array_keys($view) === [
    'format', 'authoritative', 'redaction', 'order', 'filters', 'counts', 'page', 'full_plan', 'rows',
], 'view has a closed ordered v2 envelope');
$check($view['format'] === AgentPlanView::FORMAT
    && $view['authoritative'] === false
    && $view['redaction'] === 'values_omitted'
    && $view['order'] === 'fixed_action_then_uuid_byte',
    'view explicitly declares value-free non-authority and deterministic ordering');
$check($view['counts'] === [
    'full' => 211,
    'matching' => 206,
    'offset' => 0,
    'shown' => 2,
    'remaining' => 204,
    'forced_safety' => 6,
], 'view carries exact ordinary/full/matching/page and forced-safety evidence');
$check(($view['page']['has_more'] ?? null) === true
    && is_string($view['page']['next_cursor'] ?? null)
    && strlen($view['page']['next_cursor']) === 48,
    'first page emits one opaque continuation cursor');
$check(($view['full_plan']['action_counts'] ?? null) === [
    'create' => 205, 'update' => 2, 'adopt' => 1, 'unchanged' => 1,
    'drift' => 2, 'conflict' => 1, 'collision' => 1, 'delete' => 2,
    'delete_conflict' => 1, 'deleted' => 1,
], 'full-plan action counters remain complete despite the view cap');
$check(($view['full_plan']['safety_counts'] ?? null) === [
    'drift' => 2, 'conflict' => 1, 'collision' => 1, 'delete_conflict' => 1, 'blocked_delete' => 1,
], 'forced safety includes drift/conflict/collision/delete-conflict and blocked delete');
$check(($view['full_plan']['global_counts']['regen_context'] ?? null) === 1
    && ($view['full_plan']['global_counts']['required_env_missing'] ?? null) === 1
    && ($view['full_plan']['readiness'] ?? null) === 'blocked',
    'regen_context and every global blocker remain full-plan readiness evidence');

$rows = $view['rows'];
$check(count($rows) === 8, 'only two matching ordinary rows plus every six forced safety rows are present');
$check(($rows[0]['selector'] ?? null) === 'create:sha256:' . hash('sha256', 'create-001')
    && ($rows[1]['selector'] ?? null) === 'create:sha256:' . hash('sha256', 'create-002'),
    'limit is applied after deterministic action/UUID ordering, not source order');
$check(array_keys($rows[0] ?? []) === ['bucket', 'selector', 'entity', 'categories', 'safety']
    && !array_key_exists('source_index', $rows[0] ?? []),
    'public refs bind by opaque selector without an authoritative source position');
$driftRefs = array_values(array_filter($rows, static fn(array $row): bool => ($row['bucket'] ?? null) === 'drift'));
$check(array_column($driftRefs, 'selector') === [
    'drift:sha256:' . hash('sha256', 'drift-a'),
    'drift:sha256:' . hash('sha256', 'drift-z'),
], 'reversed authoritative safety source rows are emitted in UUID order');
$blocked = array_values(array_filter($rows, static fn(array $row): bool => ($row['bucket'] ?? null) === 'delete'));
$check(count($blocked) === 1
    && $blocked[0]['categories'] === ['media', 'deletions']
    && $blocked[0]['entity'] === 'attachment'
    && $blocked[0]['safety'] === true,
    'row facets are explicit, overlapping, closed, and safety bypasses all filters');
$drift = $driftRefs[0] ?? [];
$check(($drift['categories'] ?? null) === ['authored_state', 'environment_state'],
    'drift has authored-state and environment-state facets without path inference');

$encodedView = json_encode($view, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$reversedPlan = $plan;
foreach (PlanCategorySummary::actionBuckets() as $bucket) {
    $reversedPlan[$bucket] = array_reverse($reversedPlan[$bucket]);
}
$reversedView = AgentPlanView::build($reversedPlan, $tree, $deletions, $request);
$check(json_encode($reversedView, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) === $encodedView,
    'forward/reversed ordinary and safety source orders produce byte-identical view JSON');
foreach (['SECRET_PATH', 'SECRET_TITLE', 'SECRET_BLOCKED', 'SECRET_PLUGIN', 'SECRET_WARNING', 'create-001', "\x1b", 'INJECTED'] as $needle) {
    $check(!str_contains($encodedView, $needle), "view JSON omits raw detailed bytes matching $needle");
}
$check(str_contains((string) $plan['create'][204]['title'], "\x1b")
    && str_contains((string) $plan['create'][204]['title'], 'SECRET_TITLE'),
    'the authoritative detailed plan retains its pre-existing raw authored title');

$decodedView = json_decode($encodedView, true, 512, JSON_THROW_ON_ERROR);
$check(HostPlanView::violations($plan, $decodedView, $request) === [],
    'host accepts the same-snapshot agent view, including reversed source safety order');
$check(HostPlanView::agentArgs($request) === [
    '--category=authored_state,media', '--action=create,update', '--entity=post,attachment', '--limit=2',
], 'host forwards exactly the normalized request in its one plan call');
$check(HostPlanView::requestFromArgs([
    '--category=media,authored_state,media', '--action=update,create', '--entity=attachment,post', '--limit=2',
]) === $request, 'host status parser has agent-equivalent closed CSV normalization');

$pageRequest = $request;
$pagedSelectors = [];
$pageCount = 0;
do {
    $pageView = AgentPlanView::build($plan, $tree, $deletions, $pageRequest);
    $check(HostPlanView::violations($plan, json_decode(json_encode($pageView), true), $pageRequest) === [],
        'host accepts each emitted same-snapshot cursor page');
    foreach ($pageView['rows'] as $pageRow) {
        if (($pageRow['safety'] ?? true) === false) {
            $pagedSelectors[] = $pageRow['bucket'] . "\0" . $pageRow['selector'];
        }
    }
    $pageCount++;
    $pageRequest['cursor'] = $pageView['page']['next_cursor'];
} while ($pageRequest['cursor'] !== null);
$check($pageCount === 103
    && count($pagedSelectors) === 206
    && count(array_unique($pagedSelectors)) === 206,
    'following emitted cursors enumerates every matching ordinary row exactly once without full-plan ingestion');

$stalePlan = $plan;
$stalePlan['create'][] = ['uuid' => 'later-row', 'type' => 'post', 'path' => 'posts/later.md'];
$staleTree = $tree;
$staleTree['later-row'] = ['type' => 'post', 'data' => ['type' => 'page']];
$staleRequest = $request;
$staleRequest['cursor'] = $view['page']['next_cursor'];
try {
    AgentPlanView::build($stalePlan, $staleTree, $deletions, $staleRequest);
    $check(false, 'a cursor from an older plan must not index a changed plan');
} catch (CommandRefusalException $e) {
    $check($e->reasonCode === 'plan_view_cursor_stale',
        'a cursor from an older plan typed-refuses as stale');
}

$hostCategoryLines = PlanContract::categorySummaryHumanLines(
    json_decode(json_encode($categorySummary, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
    $request['category']
);
$check($hostCategoryLines === PlanCategorySummary::humanLinesForCategories($categorySummary, $request['category']),
    'host and agent render the requested canonical category-summary subset identically');
$check(count(array_filter($hostCategoryLines, static fn(string $line): bool => str_contains($line, 'code:'))) === 0
    && count(array_filter($hostCategoryLines, static fn(string $line): bool => str_contains($line, 'authored state:'))) === 1,
    'filtered category summary renders only requested category semantics');

$badSelector = $decodedView;
$badSelector['rows'][0]['selector'] = 'create:sha256:' . str_repeat('0', 64);
$check(HostPlanView::violations($plan, $badSelector, $request) !== [],
    'host rejects a selector not bound to its full-plan source row');
$badCounts = $decodedView;
$badCounts['counts']['shown'] = 1;
$badCounts['counts']['remaining'] = 205;
$check(HostPlanView::violations($plan, $badCounts, $request) !== [],
    'host rejects a view that omits arbitrary matching ordinary rows below the cap');
$actionPlan = $emptyPlan();
$actionTree = [
    'action-create-a' => ['type' => 'post', 'data' => ['type' => 'page']],
    'action-create-b' => ['type' => 'post', 'data' => ['type' => 'page']],
];
$actionPlan['create'] = [
    ['uuid' => 'action-create-b', 'type' => 'post', 'path' => 'posts/action-b.md'],
    ['uuid' => 'action-create-a', 'type' => 'post', 'path' => 'posts/action-a.md'],
];
$exactActionRequest = AgentPlanView::requestFromAssoc(['action' => 'create', 'limit' => '2']);
$exactActionView = AgentPlanView::build($actionPlan, $actionTree, [], $exactActionRequest);
$forgedActionView = $exactActionView;
array_pop($forgedActionView['rows']);
$forgedActionView['counts']['matching'] = 1;
$forgedActionView['counts']['shown'] = 1;
$forgedActionView['counts']['remaining'] = 0;
$forgedActionView['page'] = ['next_cursor' => null, 'has_more' => false];
$check(HostPlanView::violations($actionPlan, $forgedActionView, $exactActionRequest) !== [],
    'host recomputes action-only selection and rejects a consistently undercounted omitted row');
$missingSafety = $decodedView;
array_pop($missingSafety['rows']);
$missingSafety['counts']['forced_safety'] = 5;
$check(HostPlanView::violations($plan, $missingSafety, $request) !== [],
    'host rejects a view that hides a forced safety row');
$noSummaryPlan = $plan;
unset($noSummaryPlan['category_summary']);
$check(HostPlanView::violations($noSummaryPlan, $decodedView, $request) !== [],
    'category-filtered host status refuses a missing/malformed category summary');
$actionOnly = AgentPlanView::requestFromAssoc(['action' => 'create', 'limit' => '1']);
$actionOnlyView = AgentPlanView::build($noSummaryPlan, $tree, $deletions, $actionOnly);
$check(HostPlanView::violations($noSummaryPlan, json_decode(json_encode($actionOnlyView), true), $actionOnly) === [],
    'action/entity/limit-only view remains compatible without optional category summary');

foreach ([
    ['category' => 'unknown'],
    ['action' => ''],
    ['entity' => 'post,unknown'],
    ['cursor' => 'not-an-emitted-cursor'],
    ['limit' => '02'],
    ['limit' => '201'],
] as $bad) {
    try {
        AgentPlanView::requestFromAssoc($bad);
        $check(false, 'agent rejects every invalid/empty/noncanonical view token');
    } catch (CommandRefusalException $e) {
        $check($e->reasonCode === 'invalid_arguments' && !str_contains($e->publicMessage, 'unknown'),
            'agent typed-refuses invalid view input without echoing it');
    }
}
try {
    HostPlanView::requestFromArgs(['--category=unknown']);
    $check(false, 'host rejects unknown status view token');
} catch (\WPrism\Orchestrator\PlanViewException $e) {
    $check($e->reasonCode === 'invalid_arguments' && !str_contains($e->publicMessage, 'unknown'),
        'host typed-refuses invalid status input without echoing it');
}

$agentLabel = AgentPlanView::humanLabel($plan['create'][204]);
$hostOrdinary = HostPlanView::ordinaryHumanLines($plan, $decodedView);
$check(preg_match('/[\x00-\x1F\x7F]/', $agentLabel) !== 1
    && !str_contains(implode("\n", $hostOrdinary), "\x1b")
    && !str_contains(implode("\n", $hostOrdinary), "\nINJECTED"),
    'new filtered agent/host itemization strips C0/DEL controls from path/title labels');
$controlSummaryPlan = $plan;
$controlSummaryPlan['drift'][0]['title'] = "drift\x1b\nTITLE";
$controlLines = PlanSummary::render($controlSummaryPlan)['lines'];
$check(array_filter($controlLines, static fn(string $line): bool => preg_match('/[\x00-\x1F\x7F]/', $line) === 1) === [],
    'PlanSummary label hardening strips C0/DEL controls from existing safety itemization');

// Load the actual host shell without its executable main block, then drive
// cmd_status through a fake in-memory transport. This proves one canonical
// filtered request reaches the agent plan call and a legacy/missing view fails
// closed rather than making a second call or silently falling back.
$wprismSource = file_get_contents(__DIR__ . '/../../../../cli/wprism');
if (!is_string($wprismSource)) {
    fwrite(STDERR, "FAIL: could not read host shell\n");
    exit(1);
}
$wprismMain = "\ntry {\n    exit(main(\$argv));";
$wprismAt = strpos($wprismSource, $wprismMain);
$wprismPhp = strpos($wprismSource, '<?php');
if ($wprismAt === false || $wprismPhp === false) {
    fwrite(STDERR, "FAIL: host shell main guard moved\n");
    exit(1);
}
$wprismSource = substr($wprismSource, $wprismPhp + 5, $wprismAt - ($wprismPhp + 5));
$wprismSource = str_replace('__DIR__', var_export(dirname(__DIR__, 4) . '/cli', true), $wprismSource);
$wprismSource = (string) preg_replace('/^require /m', 'require_once ', $wprismSource);
eval($wprismSource);

final class PlanViewStatusDriver implements EnvironmentDriver {
    /** @var list<array<int,string>> */
    public array $calls = [];
    /** @param array<string,mixed> $plan */
    public function __construct(private array $plan, private ?string $rawPlan = null) {}
    public function name(): string { return 'fixture'; }
    public function driverId(): string { return 'plan-view-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'plan view fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array {
        $this->calls[] = $wpArgs;
        return [
            'exit' => 0,
            'stdout' => $this->rawPlan ?? json_encode($this->plan, JSON_THROW_ON_ERROR),
            'stderr' => '',
        ];
    }
    public function streamWp(array $wpArgs): int { return 0; }
    public function wpInstruction(array $wpArgs): string { return 'fixture'; }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('fixture', 'plan-view-fixture', $operation, [
            DriverCapability::ATTACH => true, DriverCapability::WP_CONTROL => true,
        ]);
    }
}

$statusPlan = $plan;
$statusPlan['plan_view'] = $decodedView;
$driver = new PlanViewStatusDriver($statusPlan);
ob_start();
$statusExit = cmd_status($driver, [
    '--category=media,authored_state,media', '--action=update,create', '--entity=attachment,post', '--limit=2',
]);
$statusOut = (string) ob_get_clean();
$check($statusExit === 1 && count($driver->calls) === 1
    && $driver->calls[0] === [
        'wprism', 'plan', '--repo=/fixture/repo', '--category=authored_state,media', '--action=create,update',
        '--entity=post,attachment', '--limit=2', '--format=json',
    ], 'filtered status forwards one normalized request and keeps full-plan blocked readiness');
$check(str_contains($statusOut, 'VIEW CREATE')
    && str_contains($statusOut, 'CONFLICT (repo and environment both changed')
    && !str_contains($statusOut, "\x1b"),
    'filtered status itemizes ordinary rows while retaining complete existing safety sections safely');
$cleanNoView = $emptyPlan();
$cleanDriver = new PlanViewStatusDriver($cleanNoView);
ob_start();
$cleanExit = cmd_status($cleanDriver);
ob_end_clean();
$check($cleanExit === 0
    && $cleanDriver->calls === [[
        'wprism', 'plan', '--repo=/fixture/repo', '--format=json',
    ]],
    'unfiltered legacy status remains clean and makes its one unchanged plan request');
$legacyDriver = new PlanViewStatusDriver($cleanNoView);
ob_start();
$legacyExit = cmd_status($legacyDriver, ['--action=create']);
ob_end_clean();
$check($legacyExit === 1 && count($legacyDriver->calls) === 1,
    'requested host view absent from a clean legacy/full plan fails closed without a second plan call');
$malformedViewPlan = $cleanNoView;
$malformedViewPlan['plan_view'] = ['format' => 'wprism-plan-view/v0'];
$malformedDriver = new PlanViewStatusDriver($malformedViewPlan);
ob_start();
$malformedExit = cmd_status($malformedDriver, ['--action=create']);
ob_end_clean();
$check($malformedExit === 1 && count($malformedDriver->calls) === 1,
    'requested host malformed view on a clean full plan fails closed without fallback');

$missingRequired = $cleanNoView;
unset($missingRequired['conflict']);
$nonListRequired = $cleanNoView;
$nonListRequired['conflict'] = ['not_a_list' => []];
foreach ([
    'valid empty JSON object' => new PlanViewStatusDriver([], '{}'),
    'one missing required bucket' => new PlanViewStatusDriver($missingRequired),
    'one required bucket that is not a list' => new PlanViewStatusDriver($nonListRequired),
] as $label => $incompleteDriver) {
    ob_start();
    $incompleteExit = cmd_status($incompleteDriver);
    $incompleteOut = (string) ob_get_clean();
    $check($incompleteExit === 1
        && count($incompleteDriver->calls) === 1
        && !str_contains($incompleteOut, 'plan:'),
        "$label is rejected by status before any false-clean summary");
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " plan-view regression assertion(s) failed\n");
    exit(1);
}
echo "PASS: plan views are bounded, deterministic, value-free, non-authorizing, and fail closed on host status\n";
