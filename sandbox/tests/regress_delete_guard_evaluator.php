<?php
/**
 * Direct characterization for DeleteGuardEvaluator (DUO-3347): the indexed
 * lock-boundary proof used before deletion-guard FOR UPDATE reads.
 *
 * The broader target-path regression remains
 * regress_woocommerce_deletion_authority.php. This suite isolates the
 * schema-shape decision, including its prefix-index refusal boundary and the
 * storage-engine proof that must precede a destructive locking read.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
require_once __DIR__ . '/../../agent/src/DeleteGuardEvaluator.php';

use Duo\DeleteGuardEvaluator;

final class DeleteGuardEvaluatorFakeWpdb {
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = [];
    /** @var array<string,string|null> */
    public array $tableEngines;

    /** @param list<array<string,mixed>> $indexRows */
    public function __construct(
        private array $indexRows,
        ?array $tableEngines = null,
        private bool $metadataProbeFails = false,
        private bool $introspectionFails = false
    ) {
        $this->tableEngines = $tableEngines ?? [
            'wp_options' => 'InnoDB',
            'wp_postmeta' => 'InnoDB',
        ];
    }

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $sql = preg_replace('/%s/', "'" . str_replace("'", "''", (string) $arg) . "'", $sql, 1);
        }
        return $sql;
    }

    public function get_var(string $sql): int|false {
        $this->queries[] = $sql;
        if ($this->metadataProbeFails) {
            $this->last_error = 'simulated metadata probe failure';
            return false;
        }
        return 1;
    }

    public function get_results(string $sql, $format = null): array {
        $this->queries[] = $sql;
        if (str_contains($sql, 'information_schema.TABLES')) {
            if ($this->introspectionFails) {
                $this->last_error = 'simulated information_schema failure';
                return [];
            }
            $rows = [];
            foreach ($this->tableEngines as $table => $engine) {
                if (str_contains($sql, "'$table'")) {
                    $rows[] = ['TABLE_NAME' => $table, 'ENGINE' => $engine];
                }
            }
            return $rows;
        }
        return $this->indexRows;
    }
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'wrong_first', 'Seq_in_index' => 1, 'Column_name' => 'post_id', 'Sub_part' => null],
    ['Key_name' => 'meta_key_value', 'Seq_in_index' => 2, 'Column_name' => 'meta_value', 'Sub_part' => null],
    ['Key_name' => 'meta_key_value', 'Seq_in_index' => 1, 'Column_name' => 'meta_key', 'Sub_part' => 191],
]);
$check(
    DeleteGuardEvaluator::lock_index(['meta_key' => '_children'], 'wp_postmeta') === 'meta_key_value',
    'metadata guard accepts a first-column prefix index that covers its complete key'
);
$check(
    DeleteGuardEvaluator::lock_index(['meta_key' => str_repeat('x', 192)], 'wp_postmeta') === null,
    'metadata guard refuses a too-short prefix index that could miss a concurrent key'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'option_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null],
]);
$check(
    DeleteGuardEvaluator::lock_index(['option_name_ref' => true, 'column' => 'ignored'], 'wp_options') === 'option_name',
    'option-name guard locks the declared option-name range rather than its incidental column'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'unsafe-name!', 'Seq_in_index' => 1, 'Column_name' => 'target_id', 'Sub_part' => null],
    ['Key_name' => 'later_target', 'Seq_in_index' => 2, 'Column_name' => 'target_id', 'Sub_part' => null],
]);
$check(
    DeleteGuardEvaluator::lock_index(['column' => 'target_id'], 'wp_refs') === 'unsafename',
    'ordinary scalar guards use a sanitized first-column lock index'
);

// The lock boundary's engine proof is deliberately direct-callable. These
// checks would fail against the pre-extraction evaluator, which had no such
// contract, while the Woo product-path regression below keeps the complete
// transaction ordering covered.
$engineWpdb = new DeleteGuardEvaluatorFakeWpdb([], [
    'wp_options' => 'InnoDB',
    'wp_postmeta' => 'InnoDB',
]);
$GLOBALS['wpdb'] = $engineWpdb;
DeleteGuardEvaluator::assert_innodb_tables(['wp_postmeta', 'wp_options', 'wp_postmeta']);
$check(
    $engineWpdb->queries === [
        'SELECT 1 FROM `wp_options` LIMIT 1',
        'SELECT 1 FROM `wp_postmeta` LIMIT 1',
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES\n             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('wp_options','wp_postmeta')\n             ORDER BY TABLE_NAME ASC",
    ],
    'storage-engine proof sorts and de-duplicates the exact prefixed guard tables before locking'
);

$engineWpdb = new DeleteGuardEvaluatorFakeWpdb([], ['wp_postmeta' => 'MyISAM']);
$GLOBALS['wpdb'] = $engineWpdb;
$unsupportedRefused = false;
try {
    DeleteGuardEvaluator::assert_innodb_tables(['wp_postmeta']);
} catch (RuntimeException $e) {
    $unsupportedRefused = str_contains($e->getMessage(), 'wp_postmeta (engine: MYISAM)')
        && str_contains($e->getMessage(), 'InnoDB required');
}
$check(
    $unsupportedRefused,
    'storage-engine proof refuses a visible non-InnoDB table before a guard locking read'
);

$engineWpdb = new DeleteGuardEvaluatorFakeWpdb([], ['wp_postmeta' => null]);
$GLOBALS['wpdb'] = $engineWpdb;
$unknownRefused = false;
try {
    DeleteGuardEvaluator::assert_innodb_tables(['wp_postmeta']);
} catch (RuntimeException $e) {
    $unknownRefused = str_contains($e->getMessage(), 'wp_postmeta (engine: NULL/unknown)');
}
$check($unknownRefused, 'storage-engine proof refuses a null/unknown engine deterministically');

$engineWpdb = new DeleteGuardEvaluatorFakeWpdb([], [], true);
$GLOBALS['wpdb'] = $engineWpdb;
$metadataRefused = false;
try {
    DeleteGuardEvaluator::assert_innodb_tables(['wp_postmeta']);
} catch (RuntimeException $e) {
    $metadataRefused = str_contains($e->getMessage(), 'unable to acquire metadata lock')
        && str_contains($e->getMessage(), 'simulated metadata probe failure')
        && count($engineWpdb->queries) === 1;
}
$check($metadataRefused, 'metadata-lock failure refuses before information-schema introspection');

$engineWpdb = new DeleteGuardEvaluatorFakeWpdb([], ['wp_postmeta' => 'InnoDB'], false, true);
$GLOBALS['wpdb'] = $engineWpdb;
$introspectionRefused = false;
try {
    DeleteGuardEvaluator::assert_innodb_tables(['wp_postmeta']);
} catch (RuntimeException $e) {
    $introspectionRefused = str_contains($e->getMessage(), 'storage-engine introspection failed')
        && str_contains($e->getMessage(), 'simulated information_schema failure');
}
$check($introspectionRefused, 'information-schema failure remains a fail-closed deletion refusal');

$evaluator = new ReflectionClass(DeleteGuardEvaluator::class);
$check(
    (new ReflectionMethod(DeleteGuardEvaluator::class, 'lock_index'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'lock_index'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_innodb_tables'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_innodb_tables'))->isStatic()
        && $evaluator->getConstructor() === null,
    'evaluator exposes dependency-free static index and storage-engine lock-boundary contracts'
);

$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$engineFacade = substr(
    $applySource,
    strpos($applySource, 'private function assert_delete_guard_engines('),
    strpos($applySource, 'private function assert_delete_lock_isolation(')
        - strpos($applySource, 'private function assert_delete_guard_engines(')
);
$check(
    str_contains($applySource, "require_once __DIR__ . '/DeleteGuardEvaluator.php';")
        && substr_count($applySource, 'DeleteGuardEvaluator::lock_index(') === 3
        && str_contains($engineFacade, 'DeleteGuardEvaluator::assert_innodb_tables(array_keys($tables));')
        && !str_contains($engineFacade, 'information_schema.TABLES'),
    'Apply delegates every deletion-guard index and storage-engine decision to the evaluator'
);
$check(
    !str_contains($applySource, 'private function guard_lock_index('),
    'Apply retains no duplicate lock-boundary evaluator'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

echo "\nall DeleteGuardEvaluator checks passed\n";
