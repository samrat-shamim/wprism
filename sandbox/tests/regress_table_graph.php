<?php
/**
 * Offline regression for DUO-3349's typed-table declaration-graph seam.
 *
 * TableGraph must be directly usable without loading Snapshot, while
 * Snapshot's established public/private entry points remain thin behavior-
 * compatible facades. The fixture exercises the graph's refusal boundaries
 * and deterministic ordering without WordPress or a database.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Kernel/TableGraph.php';

use Duo\Policy;
use Duo\Snapshot;
use Duo\TableGraph;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};
$throws = static function (callable $run, string $fragment, string $message) use ($check): void {
    try {
        $run();
        $check(false, $message);
    } catch (\Throwable $e) {
        $check(str_contains($e->getMessage(), $fragment), $message);
    }
};

$check(class_exists(TableGraph::class, false), 'TableGraph loads as a direct offline boundary');
$check(!class_exists(Snapshot::class, false), 'TableGraph does not pull in the Snapshot runtime');
$check(!class_exists(Policy::class, false), 'TableGraph does not pull in the Policy runtime');

require_once __DIR__ . '/../../agent/src/Policy/Policy.php';

$row = static function (string $kind, array $refs = [], string $mode = 'mapped'): array {
    return [
        'class' => TableGraph::CLASS_ROW,
        'id_kind' => $kind,
        'pk' => 'id',
        'identity' => ['mode' => $mode],
        'refs' => $refs,
        'columns' => [],
    ];
};
$policy = new Policy();
$policy->manifests = [['tables' => [
    'independent' => $row('independent'),
    'child' => $row('child', [['column' => 'parent_id', 'kind' => 'parent']]),
    'parent' => $row('parent'),
    'child_meta' => [
        'class' => TableGraph::CLASS_META,
        'attached_to' => ['table' => 'child', 'column' => 'parent_id'],
    ],
    'intent_only' => ['class' => 'authored_typed_snapshot_post_v1'],
]]];

$declared = $policy->declared_tables();
$rows = TableGraph::row_tables($declared);
$meta = TableGraph::meta_tables($declared);
$check(array_keys($rows) === ['independent', 'child', 'parent'],
    'row roster filters declaration classes without reordering rows');
$check(array_keys($meta) === ['child_meta'],
    'attached-meta roster is separate from row and intent-only declarations');
$check(TableGraph::meta_tables_by_owner($rows, $meta) === ['child' => ['child_meta' => $meta['child_meta']]],
    'attached-meta declarations group under their exact row owner');
$check(TableGraph::topo_order($rows) === ['independent', 'parent', 'child'],
    'topological order is parent-first and stable for unconstrained rows');
$check(TableGraph::phase2_rank($rows, 'parent') < TableGraph::phase2_rank($rows, 'child'),
    'phase-2 rank derives from the same parent-first graph');
$check(TableGraph::phase2_rank($rows, 'not_declared') === 0,
    'unknown table retains the historical zero rank');
$check(TableGraph::is_composite_ref($row('join', [], 'composite_ref')),
    'composite-ref identity is classified at the graph boundary');
$check(!TableGraph::is_composite_ref($row('ordinary')),
    'missing or mapped identity remains non-composite');

$duplicate = new Policy();
$duplicate->manifests = [['tables' => [
    'first' => $row('shared'),
    'second' => $row('shared'),
]]];
$throws(
    static fn() => TableGraph::row_tables($duplicate->declared_tables()),
    "id_kind 'shared' is declared by both 'first' and 'second'",
    'duplicate ledger id_kind refuses with the established diagnostic'
);

$reserved = new Policy();
$reserved->manifests = [['tables' => ['post' => $row('custom_post')]]];
$throws(
    static fn() => TableGraph::row_tables($reserved->declared_tables()),
    'collides with a reserved entity type name (post/term/menu/options)',
    'ordinary entity-type collision refuses before target contact'
);

$danglingMeta = ['dangling_meta' => [
    'class' => TableGraph::CLASS_META,
    'attached_to' => ['table' => 'absent'],
]];
$throws(
    static fn() => TableGraph::meta_tables_by_owner($rows, $danglingMeta),
    "attached_to.table='absent'",
    'attached-meta ownership refuses a missing row declaration'
);

$compositeRows = ['join_table' => $row('join', [], 'composite_ref')];
$compositeMeta = ['join_meta' => [
    'class' => TableGraph::CLASS_META,
    'attached_to' => ['table' => 'join_table'],
]];
$throws(
    static fn() => TableGraph::meta_tables_by_owner($compositeRows, $compositeMeta),
    'pure join table has no scalar row identity',
    'attached-meta ownership refuses a composite-ref row'
);

$cycle = [
    'a' => $row('a_kind', [['column' => 'b_id', 'kind' => 'b_kind']]),
    'b' => $row('b_kind', [['column' => 'a_id', 'kind' => 'a_kind']]),
];
$throws(
    static fn() => TableGraph::topo_order($cycle),
    'cyclic ref dependency among declared tables: a, b',
    'cyclic row dependencies retain the established deterministic refusal'
);

// Snapshot has a Ledger-backed class constant, so load that narrow runtime
// dependency only after proving TableGraph stands alone.
require_once __DIR__ . '/../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../agent/src/Repository/Snapshot.php';

$check(Snapshot::CLASS_ROW === TableGraph::CLASS_ROW && Snapshot::CLASS_META === TableGraph::CLASS_META,
    'Snapshot compatibility constants share TableGraph vocabulary');
$check(Snapshot::row_tables($policy) === $rows,
    'Snapshot row_tables facade preserves exact roster bytes');
$check(Snapshot::meta_tables($policy) === $meta,
    'Snapshot meta_tables facade preserves exact roster bytes');
$check(Snapshot::topo_order($rows) === TableGraph::topo_order($rows),
    'Snapshot topo_order facade preserves exact graph ordering');
$check(Snapshot::phase2_rank($policy, 'child') === TableGraph::phase2_rank($rows, 'child'),
    'Snapshot phase2_rank facade preserves exact rank');

$snapshotLines = file(__DIR__ . '/../../agent/src/Repository/Snapshot.php');
$methodSource = static function (string $name) use ($snapshotLines): string {
    $method = new \ReflectionMethod(Snapshot::class, $name);
    return implode('', array_slice(
        $snapshotLines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
};
$delegates = [
    'is_composite_ref' => 'TableGraph::is_composite_ref',
    'row_tables' => 'TableGraph::row_tables',
    'meta_tables' => 'TableGraph::meta_tables',
    'meta_tables_by_owner' => 'TableGraph::meta_tables_by_owner',
    'topo_order' => 'TableGraph::topo_order',
    'phase2_rank' => 'TableGraph::phase2_rank',
];
foreach ($delegates as $method => $call) {
    $source = $methodSource($method);
    $check(str_contains($source, $call) && !str_contains($source, 'foreach') && !str_contains($source, 'while'),
        "Snapshot::$method remains a thin TableGraph compatibility facade");
}
$check(str_contains((string) file_get_contents(__DIR__ . '/../../agent/src/Repository/Snapshot.php'),
    "require_once __DIR__ . '/../Kernel/TableGraph.php';"),
    'Snapshot directly requires its graph collaborator');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " table-graph regression(s) failed\n");
    exit(1);
}

echo "ALL PASSED\n";
