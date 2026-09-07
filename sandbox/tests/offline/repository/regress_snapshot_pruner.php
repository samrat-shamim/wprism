<?php
/**
 * Offline regression for issue #3349's typed-table identity-pruning seam.
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

use WPrism\Ledger;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\Db;
use WPrism\Snapshot;
use WPrism\SnapshotPruner;
use WPrism\TableGraph;
use WPrismTest\FakeWpdb;

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

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Repository/SnapshotIdentity.php';
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
    /** @var list<string> */
    public array $missingTables = [];
    public bool $optionScanFails = false;

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg)
                ? (string) $arg
                : "'" . addslashes((string) $arg) . "'";
            $sql = (string) preg_replace_callback(
                '/%[ds]/',
                static fn(): string => $replacement,
                $sql,
                1
            );
        }
        return $sql;
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
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
            preg_match("/^SHOW TABLES LIKE '((?:\\\\.|[^'])*)'$/D", $sql, $match);
            $probed = stripslashes((string) ($match[1] ?? ''));
            $probed = strtr($probed, ['\\_' => '_', '\\%' => '%', '\\\\' => '\\']);
            foreach ($this->missingTables as $missingTable) {
                if ($probed === $missingTable) {
                    return null;
                }
            }
            return 'present';
        }
        if (preg_match("/SELECT local_id FROM wp_wprism_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $sql, $m)) {
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
        'identity' => $mode === 'composite_ref'
            ? ['mode' => $mode, 'columns' => ['left_id', 'right_id']]
            : ['mode' => $mode],
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
    static function (array $tables): void {
        Ledger::prune_dead_composite_table_map(
            $tables,
            \WPrism\SnapshotIdentity::compositeComponentBits()
        );
    },
    static fn(array $decl): bool => TableGraph::is_composite_ref($decl)
);
$pruner = $makePruner();

$preserved = $pruner->option_name_ref_preserved_ids($canonical);
$check($preserved === ['thing' => [7, 9]],
    'live and canonical witnesses deduplicate into sorted exact positive local ids');
$canonicalObservations = 0;
$scopedPreserved = $pruner->option_name_ref_preserved_ids($canonical, ['plugin_11_settings'],
    static function (Closure $observe) use (&$canonicalObservations): void {
        $canonicalObservations++;
        $observe();
    });
$check($scopedPreserved === ['thing' => [7, 11]] && $canonicalObservations === 1,
    'each complete canonical name enters the supplied observation boundary exactly once');
$throws(static fn() => $pruner->option_name_ref_preserved_ids($canonical, [], static function (Closure $observe): void {
    throw new RuntimeException('fixture canonical observation refused');
}), 'fixture canonical observation refused', 'a canonical observation boundary refusal propagates without pruning');

$unrelated = OptionState::document([
    "unrelated_{{thing:$uuid}}_settings" => OptionState::deleted(OptionState::present('old', 'yes')),
]);
$throws(
    static fn() => $pruner->option_name_ref_preserved_ids($unrelated),
    'not owned by exactly one authored option_name_refs rule',
    'a known-kind token in an unrelated canonical name cannot pin an identity'
);

$wpdb->optionScanFails = true;
$readsBeforeSuppliedNames = count($wpdb->reads);
$check($pruner->option_name_ref_preserved_ids($canonical, ['plugin_11_settings', 'plugin_11_settings'])
    === ['thing' => [7, 11]],
    'a supplied bounded namespace replaces the live scan but retains exact canonical witnesses');
$check($pruner->option_name_ref_preserved_ids(null, []) === [],
    'an explicitly empty observation does not fall back to a later database scan');
$check(!in_array('SELECT `option_name` FROM `wp_options`', array_slice($wpdb->reads, $readsBeforeSuppliedNames), true),
    'supplied names need no namespace query, including when the database scan would fail');
foreach ([[7], ['named' => 'plugin_7_settings']] as $badNames) {
    $throws(
        static fn() => $pruner->option_name_ref_preserved_ids(null, $badNames),
        'requires a list of names',
        'malformed caller-supplied namespace names refuse without pruning'
    );
}
$check($pruner->option_name_ref_preserved_ids(null, ['plugin_0007_settings']) === [],
    'a spelling outside the authored matcher cannot preserve a local identity');
$throws(
    static fn() => $pruner->option_name_ref_preserved_ids(),
    'wp_options scan failed',
    'a failed live option scan refuses before pruning'
);
$wpdb->optionScanFails = false;
$wpdb->last_error = '';

$snapshotWpdb = $wpdb;
Db::forget_transaction_tracking();
$pruneWpdb = FakeWpdb::install()
    ->seedTable('wp_wprism_map', [[
        'uuid' => $uuid,
        'entity_type' => 'things',
        'id_kind' => 'thing',
        'local_id' => 7,
    ]])
    ->setTableEngine('wp_wprism_map', 'InnoDB')
    ->seedTable('wp_options', [
        ['option_name' => 'plugin_9_settings'],
        ['option_name' => 'plugin_7_settings'],
    ])
    ->setTableEngine('wp_options', 'InnoDB')
    ->seedTable('wp_things', [])
    ->setTableEngine('wp_things', 'InnoDB')
    ->seedTable('wp_others', [])
    ->setTableEngine('wp_others', 'InnoDB')
    ->seedTable('wp_joins', [])
    ->setTableEngine('wp_joins', 'InnoDB')
    ->enableInformationSchema()
    ->acknowledgeNextQueryWithoutExecution('NOT EXISTS', 3);
$pruner->prune_dead_map($rowTables, $canonical);
$deleteQueries = array_values(array_filter(
    $pruneWpdb->queries(),
    static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM `wp_wprism_map`')
));
$check(count($deleteQueries) === 3,
    'full pruning covers ordinary, natural-key, and composite-ref tables');
$check(str_contains($deleteQueries[0], "id_kind = 'thing'")
    && str_contains($deleteQueries[0], 'local_id NOT IN (7,9)')
    && str_contains($deleteQueries[0], 'NOT EXISTS (SELECT 1 FROM `wp_things` src')
    && in_array('SHOW CREATE TABLE `wp_things`', $pruneWpdb->queries(), true),
    'full pruning carries the exact option-name preservation set into the ledger DELETE');
$check(str_contains($deleteQueries[1], "id_kind = 'other'")
    && str_contains($deleteQueries[1], 'NOT EXISTS (SELECT 1 FROM `wp_others` src')
    && !str_contains($deleteQueries[1], 'NOT IN')
    && in_array('SHOW CREATE TABLE `wp_others`', $pruneWpdb->queries(), true),
    'full pruning includes unrelated ordinary table kinds without widening preservation');
$check(str_contains($deleteQueries[2], "id_kind = 'join'")
    && str_contains($deleteQueries[2], 'NOT EXISTS (SELECT 1 FROM `wp_joins` src')
    && str_contains($deleteQueries[2], 'src.`left_id` = (`wp_wprism_map`.`local_id` >> 31)')
    && str_contains($deleteQueries[2], 'src.`right_id` = (`wp_wprism_map`.`local_id` % 2147483648)')
    && in_array('SHOW CREATE TABLE `wp_joins`', $pruneWpdb->queries(), true),
    'full pruning proves packed composite identities against their exact live tuple');
$check(
    array_filter(
        $deleteQueries,
        static fn(string $sql): bool => str_contains($sql, 'DELETE m FROM')
            || str_contains($sql, 'LEFT JOIN')
    ) === [],
    'identity pruning never relies on MySQL multi-table DELETE grammar'
);

$throws(
    static fn() => Ledger::prune_dead_composite_table_map([], 0),
    'invalid composite identity component width',
    'composite pruning rejects an invalid packing width before SQL'
);
$throws(
    static fn() => Ledger::prune_dead_composite_table_map([
        'join' => ['table' => 'joins` malicious', 'columns' => ['left_id', 'right_id']],
    ], 31),
    'invalid composite identity table declaration',
    'composite pruning rejects a hostile identifier instead of sanitizing it into SQL'
);
$wpdb = $snapshotWpdb;
$wpdb->queries = [];
$wpdb->missingTables = ['wp_missing_joins'];
Ledger::prune_dead_composite_table_map([
    'missing_join' => ['table' => 'missing_joins', 'columns' => ['left_id', 'right_id']],
], 31);
$check($wpdb->queries === [],
    'composite pruning skips an absent lifecycle-owned table without mutating its ledger');
$wpdb->missingTables = [];

$noRefsPolicy = new Policy();
$noRefsPolicy->manifests = [['name' => 'no-option-name-refs']];
$noRefsPruner = new SnapshotPruner(
    $noRefsPolicy,
    static fn(array $document): array => OptionState::records($document),
    static fn(string $wantedUuid, string $kind): ?int => Ledger::id_for($wantedUuid, $kind),
    static fn($value): ?int => Policy::strict_positive_local_id($value),
    static function (array $tables, array $preserved): void {
        Ledger::prune_dead_table_map($tables, $preserved);
    },
    static function (array $tables): void {
        Ledger::prune_dead_composite_table_map(
            $tables,
            \WPrism\SnapshotIdentity::compositeComponentBits()
        );
    },
    static fn(array $decl): bool => TableGraph::is_composite_ref($decl)
);
$check($noRefsPruner->option_name_ref_preserved_ids(null, []) === [],
    'the preservation witness is empty without option-name reference rules');

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
require_once __DIR__ . '/../../../../agent/src/Capture/LifecycleReferenceView.php';
new \WPrism\LifecycleReferenceView($invalidNoRefsPolicy, null);
$check(true,
    'lifecycle references preserve the no-rules short circuit before unrelated graph validation');

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
    'Ledger::prune_dead_table_map', 'Ledger::prune_dead_composite_table_map',
    'SnapshotIdentity::compositeComponentBits', 'self::is_composite_ref'] as $collaborator) {
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
