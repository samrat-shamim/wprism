<?php
/**
 * Offline regression for issue #3349's live typed-table schema seam.
 *
 * TableSchema is loaded directly before Policy, Ledger, or Snapshot. A tiny
 * wpdb fake drives only SHOW TABLES/SHOW COLUMNS so the suite can pin live
 * reconciliation, refusal order, BIT formats, and Snapshot's compatibility
 * facades without WordPress, Docker, or a target pair.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../../../../agent/src/Kernel/TableSchema.php';

use WPrism\Ledger;
use WPrism\Policy;
use WPrism\Snapshot;
use WPrism\CoreCaptureSchemaException;
use WPrism\TableGraph;
use WPrism\TableSchema;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};
$message = static function (callable $run): ?string {
    try {
        $run();
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
$throws = static function (callable $run, string $fragment, string $label) use ($check, $message): void {
    $actual = $message($run);
    $check($actual !== null && str_contains($actual, $fragment), $label);
};

/** Minimal fake for the two live introspection statements TableSchema owns. */
final class TableSchemaWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $termmeta = 'wp_termmeta';
    public string $options = 'wp_options';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $last_error = '';
    /** @var array<string,array<string,string>> */
    public array $tables = [];
    public int $reads = 0;

    public function prepare(string $sql, mixed ...$args): array {
        return ['sql' => $sql, 'args' => $args];
    }

    public function get_var(array $prepared): ?string {
        $this->reads++;
        $prefixed = (string) ($prepared['args'][0] ?? '');
        $table = str_starts_with($prefixed, $this->prefix)
            ? substr($prefixed, strlen($this->prefix))
            : $prefixed;
        return isset($this->tables[$table]) ? $prefixed : null;
    }

    /** @return list<array{Field:string,Type:string}> */
    public function get_results(array|string $sql, string $mode): array {
        $this->reads++;
        if (is_array($sql) && str_contains((string) ($sql['sql'] ?? ''), 'information_schema.COLUMNS')) {
            $out = [];
            foreach ((array) ($sql['args'] ?? []) as $prefixed) {
                $table = str_starts_with((string) $prefixed, $this->prefix)
                    ? substr((string) $prefixed, strlen($this->prefix))
                    : (string) $prefixed;
                foreach ($this->tables[$table] ?? [] as $field => $_type) {
                    $out[] = ['TABLE_NAME' => (string) $prefixed, 'COLUMN_NAME' => $field];
                }
            }
            return $out;
        }
        if ($mode !== ARRAY_A || !preg_match('/`([^`]+)`/', $sql, $match)) {
            throw new \RuntimeException('unexpected fake wpdb SHOW COLUMNS shape');
        }
        $prefixed = $match[1];
        $table = str_starts_with($prefixed, $this->prefix)
            ? substr($prefixed, strlen($this->prefix))
            : $prefixed;
        $out = [];
        foreach ($this->tables[$table] ?? [] as $field => $type) {
            $out[] = ['Field' => $field, 'Type' => $type];
        }
        return $out;
    }
}

$wpdb = new TableSchemaWpdb();
$GLOBALS['wpdb'] = $wpdb;

$check(class_exists(TableSchema::class, false), 'TableSchema loads as a direct offline boundary');
$check(class_exists(TableGraph::class, false), 'TableSchema loads only its pure declaration-graph collaborator');
$check(!class_exists(Policy::class, false), 'TableSchema does not pull in Policy');
$check(!class_exists(Ledger::class, false), 'TableSchema does not pull in Ledger');
$check(!class_exists(Snapshot::class, false), 'TableSchema does not pull in Snapshot');

$wpdb->tables['typed_row'] = [
    'id' => 'bigint(20) unsigned',
    'parent_id' => 'bigint(20) unsigned',
    'title' => 'varchar(255)',
    'enabled' => 'BIT(1)',
];
$check(TableSchema::live_column_types('typed_row!') === $wpdb->tables['typed_row'],
    'live introspection sanitizes the declaration name and preserves column/type order');
$check(TableSchema::live_columns('typed_row') === ['id', 'parent_id', 'title', 'enabled'],
    'column-name introspection derives from the same live type map');
$check(TableSchema::live_column_types('absent') === null,
    'an absent live table remains distinguishable from an empty table');

foreach (TableSchema::core_capture_required_columns() as $property => $columns) {
    $table = str_starts_with($wpdb->$property, $wpdb->prefix)
        ? substr($wpdb->$property, strlen($wpdb->prefix))
        : $wpdb->$property;
    $wpdb->tables[$table] = array_fill_keys($columns, 'fixture');
}
TableSchema::assert_core_capture_schema();
$check(true, 'the complete WordPress core capture read schema passes one batched live inventory');
unset($wpdb->tables['posts']['post_excerpt']);
$schemaFailure = null;
try {
    TableSchema::assert_core_capture_schema();
} catch (Throwable $failure) {
    $schemaFailure = $failure;
}
$check(
    $schemaFailure instanceof CoreCaptureSchemaException
        && $schemaFailure->missing === [['table' => 'posts', 'column' => 'post_excerpt']],
    'core schema refusal carries one stable logical table/column location without exposing the physical prefix'
);
$throws(
    static fn() => TableSchema::assert_core_capture_schema(),
    'wp_posts.post_excerpt',
    'a renamed SELECT-star post property refuses before PHP can coerce it to empty canonical state'
);
$wpdb->tables['posts']['post_excerpt'] = 'text';
$wpdb->tables['posts']['plugin_extra_column'] = 'longtext';
TableSchema::assert_core_capture_schema();
$check(true, 'plugin-added core-table columns do not widen adapter ownership or trigger a false schema refusal');
unset($wpdb->tables['posts']['plugin_extra_column']);

$check(TableSchema::is_bit_column('bit(1)') && TableSchema::is_bit_column('BIT(8)'),
    'BIT detection is case-insensitive');
$check(!TableSchema::is_bit_column('tinyint(1)'), 'non-BIT numeric types retain ordinary formatting');
[$formattedData, $formats] = TableSchema::write_format(
    ['enabled' => '0', 'nullable_bit' => null, 'title' => 'hello'],
    ['enabled' => 'bit(1)', 'nullable_bit' => 'BIT(1)', 'title' => 'varchar(255)']
);
$check($formattedData === ['enabled' => 0, 'nullable_bit' => null, 'title' => 'hello'],
    'BIT values become integer literals while null and non-BIT values retain their value');
$check($formats === ['%d', '%d', '%s'],
    'wpdb write formats remain positional and use numeric literals only for BIT columns');

$grammarCalls = [];
$grammar = static function (string $table, array $decl) use (&$grammarCalls): void {
    $grammarCalls[] = [$table, $decl];
};
$rowDecl = [
    'class' => TableGraph::CLASS_ROW,
    'id_kind' => 'typed_row',
    'pk' => 'id',
    'refs' => [['column' => 'parent_id', 'kind' => 'post']],
    'columns' => [
        'title' => ['class' => 'authored'],
        'enabled' => ['class' => 'authored'],
    ],
];

$grammarCalls = [];
TableSchema::assert_row_schema('typed_row', $rowDecl, 32, $grammar);
$check(count($grammarCalls) === 1, 'ordinary row reconciliation runs injected grammar exactly once');

$wpdb->reads = 0;
$grammarRefusal = static function (): void {
    throw new \RuntimeException('grammar-first');
};
$throws(
    static fn() => TableSchema::assert_row_schema('typed_row', $rowDecl, 32, $grammarRefusal),
    'grammar-first',
    'declaration grammar refusal is preserved before live reconciliation'
);
$check($wpdb->reads === 0, 'grammar refusal performs no database read');

$grammarCalls = [];
$wpdb->reads = 0;
$throws(
    static fn() => TableSchema::assert_row_schema(str_repeat('t', 65), $rowDecl, 32, $grammar),
    'name is 65 chars',
    'entity_type width refuses before declaration grammar or target contact'
);
$check($grammarCalls === [] && $wpdb->reads === 0,
    'entity_type width remains the first row-schema check');

$grammarCalls = [];
$wpdb->reads = 0;
$wideKind = $rowDecl;
$wideKind['id_kind'] = 'toolong';
$throws(
    static fn() => TableSchema::assert_row_schema('typed_row', $wideKind, 4, $grammar),
    "id_kind 'toolong' — must be 1-4 chars",
    'injected ledger width refuses an oversized id_kind'
);
$check(count($grammarCalls) === 1 && $wpdb->reads === 0,
    'id_kind width remains after pure grammar and before target contact');

$wpdb->tables['typed_row_extra'] = $wpdb->tables['typed_row'] + ['zeta' => 'text', 'alpha' => 'text'];
$throws(
    static fn() => TableSchema::assert_row_schema('typed_row_extra', $rowDecl, 32, $grammar),
    'undeclared column(s): alpha, zeta',
    'undeclared live columns retain deterministic sorted diagnostics'
);
$wpdb->tables['typed_row_missing'] = ['id' => 'bigint(20)'];
$throws(
    static fn() => TableSchema::assert_row_schema('typed_row_missing', $rowDecl, 32, $grammar),
    'absent from this environment: enabled, parent_id, title',
    'declared-but-missing columns retain deterministic sorted diagnostics'
);
$throws(
    static fn() => TableSchema::assert_row_schema('not_installed', $rowDecl, 32, $grammar),
    "declared table 'not_installed' does not exist",
    'missing row table retains the established stale-manifest refusal'
);

$compositeDecl = [
    'class' => TableGraph::CLASS_ROW,
    'id_kind' => 'join_row',
    'identity' => ['mode' => 'composite_ref', 'columns' => ['left_id', 'right_id']],
    'refs' => [
        ['column' => 'left_id', 'kind' => 'left'],
        ['column' => 'right_id', 'kind' => 'right'],
    ],
    'columns' => ['modified' => ['class' => 'runtime']],
];
$wpdb->tables['join_row'] = [
    'left_id' => 'bigint(20)',
    'right_id' => 'bigint(20)',
    'modified' => 'timestamp',
];
$grammarCalls = [];
TableSchema::assert_row_schema('join_row', $compositeDecl, 32, $grammar);
$check(count($grammarCalls) === 2,
    'composite dispatch preserves the historical row-plus-direct double grammar assertion');
$grammarCalls = [];
TableSchema::assert_composite_row_schema('join_row', $compositeDecl, 32, $grammar);
$check(count($grammarCalls) === 1, 'direct composite reconciliation runs grammar exactly once');

$metaDecl = [
    'class' => TableGraph::CLASS_META,
    'attached_to' => ['table' => 'typed_row', 'column' => 'row_id'],
    'id_column' => 'meta_id',
    'key_column' => 'meta_key',
    'value_column' => 'meta_value',
    'legacy_key_column' => 'legacy_key',
    'legacy_value_column' => 'legacy_value',
];
$wpdb->tables['typed_meta'] = [
    'meta_id' => 'bigint(20)',
    'row_id' => 'bigint(20)',
    'meta_key' => 'varchar(255)',
    'meta_value' => 'longtext',
    'legacy_key' => 'varchar(255)',
    'legacy_value' => 'longtext',
];
$grammarCalls = [];
TableSchema::assert_meta_schema('typed_meta', $metaDecl, $grammar);
$check(count($grammarCalls) === 1, 'attached-meta reconciliation runs injected grammar exactly once');
$wpdb->tables['typed_meta']['surprise'] = 'text';
$throws(
    static fn() => TableSchema::assert_meta_schema('typed_meta', $metaDecl, $grammar),
    'schema mismatch',
    'attached-meta reconciliation refuses any live schema drift'
);
unset($wpdb->tables['typed_meta']['surprise']);

// Load the narrow runtime only after proving the extracted boundary stands
// alone, then compare Snapshot's historical entry points against it.
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Snapshot.php';

Snapshot::assert_row_schema('typed_row', $rowDecl);
$wpdb->tables['typed_row']['surprise'] = 'text';
$directMessage = $message(static fn() => TableSchema::assert_row_schema(
    'typed_row',
    $rowDecl,
    Ledger::ID_KIND_WIDTH,
    static fn(string $table, array $decl): mixed => Policy::assert_table_grammar($table, $decl)
));
$snapshotMessage = $message(static fn() => Snapshot::assert_row_schema('typed_row', $rowDecl));
$check($directMessage !== null && $directMessage === $snapshotMessage,
    'Snapshot row-schema facade preserves the extracted boundary diagnostic exactly');
unset($wpdb->tables['typed_row']['surprise']);

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
    'live_column_types' => 'TableSchema::live_column_types',
    'live_columns' => 'TableSchema::live_columns',
    'is_bit_column' => 'TableSchema::is_bit_column',
    'write_format' => 'TableSchema::write_format',
    'assert_entity_type_width' => 'TableSchema::assert_entity_type_width',
    'assert_id_kind_width' => 'TableSchema::assert_id_kind_width',
    'assert_row_schema' => 'TableSchema::assert_row_schema',
    'assert_composite_row_schema' => 'TableSchema::assert_composite_row_schema',
    'assert_meta_schema' => 'TableSchema::assert_meta_schema',
];
foreach ($delegates as $method => $call) {
    $source = $methodSource($method);
    $check(str_contains($source, $call) && !str_contains($source, 'foreach') && !str_contains($source, 'global $wpdb'),
        "Snapshot::$method remains a thin TableSchema compatibility facade");
}
$snapshotSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/Snapshot.php');
$check(str_contains($snapshotSource, "require_once __DIR__ . '/../Kernel/TableSchema.php';"),
    'Snapshot directly requires its schema collaborator');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " table-schema regression(s) failed\n");
    exit(1);
}

echo "ALL PASSED\n";
