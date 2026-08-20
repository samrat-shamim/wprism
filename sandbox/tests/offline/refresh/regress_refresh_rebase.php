<?php
declare(strict_types=1);

define('DUO_SPEC_VERSION', 2);
$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/cli/src/Refresh/RefreshPlan.php';

use Duo\Canon;
use Duo\OptionState;
use Duo\Orchestrator\RefreshPlan;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) $failures++;
};
$refuses = static function (callable $operation, string $message) use ($check): void {
    try {
        $operation();
        $check(false, "$message (did not refuse)");
    } catch (Throwable $e) {
        $check(true, $message);
    }
};

$record = static function (string $identity, string $type, string $value, ?string $semantic = null): array {
    return [
        'identity' => $identity,
        'type' => $type,
        'path' => $type === 'post' ? "posts/post/$identity.json" : "$type/$identity.json",
        'hash' => hash('sha256', $semantic ?? $value),
        'content' => $value,
    ];
};
$options = static function (array $values): array {
    $records = [];
    foreach ($values as $name => $value) $records[$name] = OptionState::present($value, 'yes');
    $content = Canon::encode(OptionState::document($records));
    return [
        'identity' => 'options/core', 'type' => 'options', 'path' => 'options/core.json',
        'hash' => hash('sha256', $content), 'content' => $content,
    ];
};
$snapshot = static function (array $records, string $revision): array {
    ksort($records, SORT_STRING);
    return [
        'format' => 'duo-refresh-git/v1',
        'records' => $records,
        'deletions' => [],
        'media' => [],
        'policy' => [
            'site_hash' => str_repeat('a', 64),
            'manifest_hash' => str_repeat('b', 64),
            'resolved_adapters' => [],
        ],
        'completed_code' => null,
        'repository' => [
            'artifact_hash' => str_repeat('c', 64),
            'revision_hash' => $revision,
            'code_revision' => null,
        ],
    ];
};

$base = $snapshot([
    'u' => $record('u', 'post', '{"title":"same"}'),
    'p' => $record('p', 'post', '{"title":"base"}'),
    'w' => $record('w', 'post', '{"title":"base"}'),
    'c' => $record('c', 'post', '{"title":"base"}'),
    'x' => $record('x', 'post', '{"title":"base"}'),
    'g' => $record('g', 'post', '{"modified":"base"}', 'derived-stable'),
    'options/core' => $options([
        'opprod' => 'base', 'opbranch' => 'base', 'opcompat' => 'base', 'opconflict' => 'base',
    ]),
], str_repeat('1', 64));
$production = $snapshot([
    'u' => $record('u', 'post', '{"title":"same"}'),
    'p' => $record('p', 'post', '{"title":"production"}'),
    'w' => $record('w', 'post', '{"title":"base"}'),
    'c' => $record('c', 'post', '{"title":"same-result"}'),
    'x' => $record('x', 'post', '{"title":"production"}'),
    'g' => $record('g', 'post', '{"modified":"production"}', 'derived-stable'),
    'options/core' => $options([
        'opprod' => 'production', 'opbranch' => 'base', 'opcompat' => 'same-result', 'opconflict' => 'production',
    ]),
], str_repeat('2', 64));
$branch = $snapshot([
    'u' => $record('u', 'post', '{"title":"same"}'),
    'p' => $record('p', 'post', '{"title":"base"}'),
    'w' => $record('w', 'post', '{"title":"branch"}'),
    'c' => $record('c', 'post', '{"title":"same-result"}'),
    'x' => $record('x', 'post', '{"title":"branch"}'),
    'g' => $record('g', 'post', '{"modified":"branch"}', 'derived-stable'),
    'options/core' => $options([
        'opprod' => 'base', 'opbranch' => 'branch', 'opcompat' => 'same-result', 'opconflict' => 'branch',
    ]),
], str_repeat('3', 64));

$context = [
    'base_commit' => str_repeat('1', 40),
    'production_commit' => str_repeat('2', 40),
    'branch_commit' => str_repeat('3', 40),
    'production_snapshot_hash' => str_repeat('4', 64),
];
$plan = RefreshPlan::plan($base, $production, $branch, $context);
$check($plan['format'] === 'duo-refresh-plan/v1' && preg_match('/^[a-f0-9]{64}$/', $plan['plan_hash']) === 1,
    'planner emits one content-addressed deterministic plan');
$check($plan['counts'] === [
    'unchanged' => 2, 'production-only' => 2, 'branch-only' => 2, 'compatible' => 2, 'conflicting' => 2,
], 'semantic planner covers all five categories and splits options by name');
$check($plan['unresolved'] === ['option:opconflict', 'post:x'], 'conflicts have stable, sorted record identities');

$byId = [];
foreach ($plan['entries'] as $entry) $byId[$entry['id']] = $entry;
$check($byId['option:opprod']['category'] === 'production-only'
    && $byId['option:opbranch']['category'] === 'branch-only',
    'unrelated option records do not whole-file conflict');
$check($byId['post:g']['category'] === 'unchanged',
    'raw generated/derived byte changes do not conflict when semantic hashes match');
$check($byId['post:x']['reason'] === 'production_and_branch_changed_differently',
    'divergent authored conflicts carry an explicit explanation');

$branchDeletesX = $branch;
unset($branchDeletesX['records']['x']);
$branchDeletesX['deletions']['x'] = $record('x', 'deletion', '{"format":"duo-deletion/v1"}');
$deletionConflict = RefreshPlan::plan($base, $production, $branchDeletesX, $context);
$deletionIds = array_column(array_filter(
    $deletionConflict['entries'],
    static fn(array $entry): bool => ($entry['identity'] ?? null) === 'x'
), 'id');
$check($deletionIds === ['post:x'], 'a tombstone preserves the entity-kind stable conflict id');

$permutedBase = $base;
$permutedBase['records'] = array_reverse($permutedBase['records'], true);
$check(RefreshPlan::plan($permutedBase, $production, $branch, $context)['plan_hash'] === $plan['plan_hash'],
    'plan identity is stable under input ordering');

$resolvedContext = $context + ['resolution' => [
    'strategy' => 'manual',
    'records' => ['post:x' => 'ours', 'option:opconflict' => 'theirs'],
]];
$resolved = RefreshPlan::plan($base, $production, $branch, $resolvedContext);
$resolvedById = [];
foreach ($resolved['entries'] as $entry) $resolvedById[$entry['id']] = $entry;
$check($resolved['unresolved'] === []
    && $resolvedById['post:x']['selected_source'] === 'branch'
    && $resolvedById['option:opconflict']['selected_source'] === 'production',
    'per-record ours/theirs choices resolve stable conflict IDs');
$allOurs = RefreshPlan::plan($base, $production, $branch, $context + [
    'resolution' => ['strategy' => 'ours', 'records' => []],
]);
$check($allOurs['unresolved'] === [], 'chosen global strategy resolves every conflict');
$refuses(static fn() => RefreshPlan::plan($base, $production, $branch, $context + [
    'resolution' => ['strategy' => 'manual', 'records' => ['post:stale' => 'ours']],
]), 'stale/unknown per-record resolution refuses');

$tampered = $plan;
$tampered['entries'][0]['category'] = 'conflicting';
$refuses(static fn() => RefreshPlan::normalizePlan($tampered), 'plan hash detects persisted-plan tampering');

$absentProduction = $production;
unset($absentProduction['records']['p']);
$absencePlan = RefreshPlan::plan($base, $absentProduction, $branch, $context);
$absence = array_values(array_filter($absencePlan['entries'], static fn(array $e): bool => $e['id'] === 'post:p'))[0];
$check($absence['category'] === 'conflicting' && $absence['reason'] === 'absence_without_tombstone',
    'absence alone never becomes production deletion authority');
$refuses(static fn() => RefreshPlan::plan($base, $absentProduction, $branch, $context + [
    'resolution' => ['strategy' => 'theirs', 'records' => []],
]), 'strategy cannot select production absence without a tombstone');

$rawProduction = $production;
$rawProduction['format'] = 'duo-refresh-production/v1';
$basis = $rawProduction;
$rawProduction['snapshot_hash'] = hash('sha256', Canon::encode($basis));
$normalized = RefreshPlan::normalizeProductionSnapshot($rawProduction);
$check($normalized['snapshot_hash'] === $rawProduction['snapshot_hash'], 'live production snapshot hash verifies');
$badProduction = $rawProduction;
$badProduction['records']['p']['content'] = 'tampered';
$refuses(static fn() => RefreshPlan::normalizeProductionSnapshot($badProduction), 'tampered live production envelope refuses');

$codeProduction = $rawProduction;
$codeRef = $production;
$descriptor = ['format' => 1, 'code_revision' => str_repeat('d', 64)];
$codeProduction['completed_code'] = ['revision' => str_repeat('d', 64), 'descriptor' => $descriptor];
$codeRef['completed_code'] = ['revision' => str_repeat('d', 64), 'descriptor' => $descriptor];
RefreshPlan::assertProductionCodeMatches($codeProduction, $codeRef);
$codeRef['completed_code']['revision'] = str_repeat('e', 64);
$refuses(static fn() => RefreshPlan::assertProductionCodeMatches($codeProduction, $codeRef),
    'separate completed code identity mismatch refuses');

$tmp = sys_get_temp_dir() . '/duo-refresh-rebase-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
file_put_contents($tmp . '/.git', "gitdir: disposable\n");
file_put_contents($tmp . '/site.duo.json', "untouched\n");
mkdir($tmp . '/state', 0700, true);
file_put_contents($tmp . '/state/stale.json', "stale\n");
try {
    $receipt = RefreshPlan::materialize($resolved, $tmp);
    $check(($receipt['resolved'] ?? false) === true && is_file($tmp . '/state/posts/post/x.json'),
        'resolved plan materializes selected semantic state in the disposable worktree');
    $check(!is_file($tmp . '/state/stale.json') && is_file($tmp . '/state/options/core.json'),
        'materialization replaces stale state and reassembles options/core');
    $check(file_get_contents($tmp . '/site.duo.json') === "untouched\n",
        'semantic materialization cannot change code/policy paths');
    $refuses(static fn() => RefreshPlan::materialize($plan, $tmp), 'unresolved plan cannot materialize');
    $runtimeReceipt = RefreshPlan::materialize($plan, $tmp, [
        'strategy' => 'manual',
        'records' => ['post:x' => 'ours', 'option:opconflict' => 'theirs'],
    ]);
    $check(($runtimeReceipt['plan_hash'] ?? null) === $plan['plan_hash']
        && preg_match('/^[a-f0-9]{64}$/', (string) ($runtimeReceipt['resolution_hash'] ?? '')) === 1,
        'run-time resolutions materialize while preserving the immutable plan identity');
    $refuses(static fn() => RefreshPlan::materialize($plan, $tmp, [
        'strategy' => 'manual', 'records' => ['post:stale' => 'ours'],
    ]), 'materialization refuses stale run-time conflict choices');
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $child) if ($child !== '.' && $child !== '..') $remove($path . '/' . $child);
        @rmdir($path);
    };
    $remove($tmp);
}

echo $failures === 0 ? "PASS: refresh/rebase semantic planner\n" : "FAIL: $failures refresh/rebase assertion(s)\n";
exit($failures === 0 ? 0 : 1);
