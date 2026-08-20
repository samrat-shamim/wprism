<?php
/**
 * Offline regression for DUO-3349's typed-table identity-pruning seam.
 *
 * SnapshotPruner is directly executable without loading Snapshot or its
 * runtime graph. The fake target pins preservation and pruning scopes;
 * Snapshot's established public methods remain thin compatibility facades.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../../../../agent/src/Repository/SnapshotPruner.php';

use Duo\Ledger;
use Duo\OptionState;
use Duo\Policy;
use Duo\Snapshot;
use Duo\SnapshotPruner;
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

$check(class_exists(SnapshotPruner::class, false), 'SnapshotPruner loads as a direct offline boundary');
$check(!class_exists(Snapshot::class, false), 'SnapshotPruner does not pull in Snapshot');
$check(!class_exists(Policy::class, false), 'SnapshotPruner does not pull in Policy');
$check(!class_exists(Ledger::class, false), 'SnapshotPruner does not pull in Ledger');
$check(!class_exists(OptionState::class, false), 'SnapshotPruner does not pull in OptionState');

require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/TableGraph.php';

final class SnapshotPrunerFakeWpdb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var list<array{option_name:string}> */
    public array $optionRows = [];
    /** @var array<string,int> */
    public array $uuidIds = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> */
    public array $reads = [];
    public bool $optionScanFails = false;

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg)
                ? (string) $arg
                : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = (string) preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function get_results(string $sql, string $output): array|false {
        $this->reads[] = $sql;
        if (str_contains($sql, 'SELECT `option_name` FROM')) {
            if ($this->optionScanFails) {
                $this->last_error = 'fixture option scan failure';
                return false;
            }
            return $this->optionRows;
        }
        return [];
    }

    public function get_var(string $sql): mixed {
        $this->reads[] = $sql;
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
            return 'present';
        }
        if (preg_match("/SELECT local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $sql, $m)) {
            return $this->uuidIds[$m[1] . '|' . $m[2]] ?? null;
        }
        return null;
    }

    public function query(string $sql): int {
        $this->queries[] = $sql;
        return 1;
    }
}

$wpdb = new SnapshotPrunerFakeWpdb();
$uuid = '11111111-1111-5111-8111-111111111111';
$wpdb->uuidIds["$uuid|thing"] = 7;
$wpdb->optionRows = [
    ['option_name' => 'plugin_9_settings'],
    ['option_name' => 'plugin_7_settings'],
];

$row = static function (string $kind, string $mode = 'mapped'): array {
    return [
        'class' => 'authored_snapshot',
        'id_kind' => $kind,
        'pk' => 'id',
        'identity' => ['mode' => $mode],
        'columns' => [],
        'refs' => [],
    ];
};
$policy = new Policy();
$policy->manifests = [[
    'name' => 'snapshot-pruner-fixture',
    'option_name_refs' => [[
        'match' => '^plugin_(?<id>[1-9][0-9]*)_settings$',
        'id_kind' => 'thing',
        'class' => 'authored',
    ]],
    'tables' => [
        'things' => $row('thing'),
        'others' => $row('other'),
        'joins' => $row('join', 'composite_ref'),
    ],
]];
$rowTables = $policy->declared_tables();
$canonical = OptionState::document([
    "plugin_{{thing:$uuid}}_settings" => OptionState::deleted(OptionState::present(['title' => 'old'], 'yes')),
]);

$makePruner = static fn(): SnapshotPruner => new SnapshotPruner(
    $policy,
    static fn(array $document): array => OptionState::records($document),
    static fn(string $wantedUuid, string $kind): ?int => Ledger::id_for($wantedUuid, $kind),
    static fn($value): ?int => Policy::strict_positive_local_id($value),
    static function (array $tables, array $preserved): void {
        Ledger::prune_dead_table_map($tables, $preserved);
    },
    static fn(array $decl): bool => TableGraph::is_composite_ref($decl)
);
$pruner = $makePruner();

$preserved = $pruner->option_name_ref_preserved_ids($canonical);
$check($preserved === ['thing' => [7, 9]],
    'live and canonical witnesses deduplicate into sorted exact positive local ids');

$unrelated = OptionState::document([
    "unrelated_{{thing:$uuid}}_settings" => OptionState::deleted(OptionState::present('old', 'yes')),
]);
$throws(
    static fn() => $pruner->option_name_ref_preserved_ids($unrelated),
    'not owned by exactly one authored option_name_refs rule',
    'a known-kind token in an unrelated canonical name cannot pin an identity'
);

$wpdb->optionScanFails = true;
$throws(
    static fn() => $pruner->option_name_ref_preserved_ids(),
    'wp_options scan failed',
    'a failed live option scan refuses before pruning'
);
$wpdb->optionScanFails = false;
$wpdb->last_error = '';

$wpdb->queries = [];
$pruner->prune_dead_map($rowTables, $canonical);
$check(count($wpdb->queries) === 2,
    'full pruning covers ordinary mapped and natural-key tables only');
$check(str_contains($wpdb->queries[0], "m.id_kind = 'thing'")
    && str_contains($wpdb->queries[0], 'm.local_id NOT IN (7,9)'),
    'full pruning carries the exact option-name preservation set into the ledger DELETE');
$check(str_contains($wpdb->queries[1], "m.id_kind = 'other'")
    && !str_contains($wpdb->queries[1], 'NOT IN'),
    'full pruning includes unrelated ordinary table kinds without widening preservation');
$check(!str_contains(implode("\n", $wpdb->queries), "m.id_kind = 'join'"),
    'full pruning excludes packed composite-ref identities');

$wpdb->queries = [];
$pruner->prune_option_name_ref_map(static fn(): array => $rowTables, $canonical);
$check(count($wpdb->queries) === 1 && str_contains($wpdb->queries[0], "m.id_kind = 'thing'"),
    'lifecycle pruning touches only the option-name-referenced row kind');
$check(!str_contains($wpdb->queries[0], "m.id_kind = 'other'")
    && !str_contains($wpdb->queries[0], "m.id_kind = 'join'"),
    'lifecycle pruning excludes unrelated and composite row kinds');

$noRefsPolicy = new Policy();
$noRefsPolicy->manifests = [['name' => 'no-option-name-refs']];
$noRefsRowsRead = false;
$noRefsPruner = new SnapshotPruner(
    $noRefsPolicy,
    static fn(array $document): array => OptionState::records($document),
    static fn(string $wantedUuid, string $kind): ?int => Ledger::id_for($wantedUuid, $kind),
    static fn($value): ?int => Policy::strict_positive_local_id($value),
    static function (array $tables, array $preserved): void {
        Ledger::prune_dead_table_map($tables, $preserved);
    },
    static fn(array $decl): bool => TableGraph::is_composite_ref($decl)
);
$noRefsPruner->prune_option_name_ref_map(static function () use (&$noRefsRowsRead): array {
    $noRefsRowsRead = true;
    throw new \RuntimeException('row-table discovery must stay lazy without option-name refs');
});
$check(!$noRefsRowsRead,
    'lifecycle pruning returns before row-table discovery when no option-name refs exist');

require_once __DIR__ . '/../../../../agent/src/Repository/Snapshot.php';
$check(Snapshot::option_name_ref_preserved_ids($policy, $canonical) === $preserved,
    'Snapshot preservation facade returns the extracted boundary result exactly');

$invalidNoRefsPolicy = new Policy();
$invalidNoRefsPolicy->manifests = [[
    'name' => 'invalid-unrelated-table-graph',
    'tables' => [
        'first' => $row('duplicate'),
        'second' => $row('duplicate'),
    ],
]];
Snapshot::prune_option_name_ref_map($invalidNoRefsPolicy);
$check(true,
    'Snapshot lifecycle facade preserves the no-rules short circuit before unrelated graph validation');

$snapshotLines = file(__DIR__ . '/../../../../agent/src/Repository/Snapshot.php');
$methodSource = static function (string $name) use ($snapshotLines): string {
    $method = new \ReflectionMethod(Snapshot::class, $name);
    return implode('', array_slice(
        $snapshotLines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
};
$delegates = [
    'option_name_ref_preserved_ids' => 'snapshot_pruner($policy)->option_name_ref_preserved_ids',
    'prune_dead_map' => 'snapshot_pruner($policy)->prune_dead_map',
    'prune_option_name_ref_map' => 'snapshot_pruner($policy)->prune_option_name_ref_map',
];
foreach ($delegates as $method => $call) {
    $source = $methodSource($method);
    $check(str_contains($source, $call)
        && !str_contains($source, 'foreach')
        && !str_contains($source, 'Ledger::prune_dead_table_map'),
        "Snapshot::$method remains a thin SnapshotPruner compatibility facade");
}
$factorySource = $methodSource('snapshot_pruner');
foreach (['OptionState::records', 'Ledger::id_for', 'Policy::strict_positive_local_id',
    'Ledger::prune_dead_table_map', 'self::is_composite_ref'] as $collaborator) {
    $check(str_contains($factorySource, $collaborator),
        "Snapshot pruning adapter injects $collaborator explicitly");
}
$snapshotSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/Snapshot.php');
$prunerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/SnapshotPruner.php');
$check(str_contains($snapshotSource, "require_once __DIR__ . '/SnapshotPruner.php';"),
    'Snapshot directly requires its pruning collaborator');
$check(!str_contains($snapshotSource, 'SELECT `option_name` FROM'),
    'live option-name preservation SQL has one owner outside Snapshot');
$check(!preg_match('/\b(?:Snapshot|Policy|Ledger|OptionState|TableGraph)::/', $prunerSource),
    'SnapshotPruner depends only on injected runtime capabilities');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " snapshot-pruner regression(s) failed\n");
    exit(1);
}

echo "ALL PASSED\n";
