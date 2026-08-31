<?php
/**
 * Direct characterization for DeleteGuardEvaluator (issue #3347): the indexed
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
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../../../../agent/src/Delete/DeleteGuardReferenceScanner.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ProtectedPostIdentity.php';

use WPrism\DeleteGuardEvaluator;
use WPrism\DeleteGuardReferenceScanner;
use WPrism\Db;
use WPrism\Policy;
use WPrism\ProtectedPostIdentity;

final class DeleteGuardEvaluatorFakeWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $terms = 'wp_terms';
    public string $termmeta = 'wp_termmeta';
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = [];
    /** @var array<string,string|null> */
    public array $tableEngines;
    public ?string $indexResultMode = null;
    public mixed $connectionId = '7001';
    public mixed $activeTransaction = '1';
    public bool $activeTransactionError = false;
    public bool $savepointExists = false;
    public bool $nextRepeatableRead = false;
    public bool $failSetTransaction = false;
    public bool $applySetTransaction = true;
    public bool $throwOnSetTransaction = false;
    public bool $setTransactionLeavesError = false;
    public int|false $setTransactionResult = 1;
    public int $startsWithoutOneShot = 0;
    public bool $replaceConnectionOnSetTransaction = false;
    public bool $applyStart = true;
    public int $falseStartsBeforeApply = 0;
    public int $throwStartsBeforeApply = 0;
    public bool $applyCommit = true;
    public bool $applyRollback = true;
    public int|false $startResult = 1;
    public int|false $commitResult = 1;
    public int|false $rollbackResult = 1;
    public bool $replaceConnectionOnCommit = false;
    public bool $throwOnStart = false;
    public bool $throwOnCommit = false;
    public bool $throwOnRollback = false;
    public bool $commitLeavesError = false;
    public ?int $replaceConnectionAtStateProbeStep = null;
    public int $stateProbeStep = 0;
    public mixed $replacementActiveTransaction = '0';
    public bool $protectedLockFixture = false;
    /** @var ?list<array<string,mixed>> */
    public ?array $topologyRowsOverride = null;
    public bool $topologyProbeError = false;
    public bool $replaceConnectionOnProtectedOwnerLock = false;
    public bool $replaceConnectionOnProtectedUpdate = false;
    public bool $replaceConnectionOnProtectedReadback = false;
    public bool $endTransactionBeforeProtectedUpdate = false;
    public int $protectedPasswordMutations = 0;
    public string $protectedPassword = 'old-password';
    public string $protectedTransactionPassword = 'old-password';
    public string $protectedConnectionId = '7001';
    public string $protectedUuid = '019200cc-0000-7000-8000-0000000000c7';

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

    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if ($sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
            if ($this->applySetTransaction) {
                $this->nextRepeatableRead = true;
            }
            if ($this->failSetTransaction || $this->setTransactionLeavesError) {
                $this->last_error = 'simulated SET TRANSACTION failure';
            }
            if ($this->throwOnSetTransaction) {
                throw new RuntimeException('simulated SET TRANSACTION driver exception');
            }
            if ($this->replaceConnectionOnSetTransaction) {
                $this->connectionId = (string) ((int) $this->connectionId + 1);
                $this->nextRepeatableRead = false;
            }
            return $this->failSetTransaction ? false : $this->setTransactionResult;
        }
        if (in_array($sql, [
            'START TRANSACTION',
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT',
        ], true)) {
            if ($this->falseStartsBeforeApply > 0) {
                $this->falseStartsBeforeApply--;
                return false;
            }
            if ($this->throwStartsBeforeApply > 0) {
                $this->throwStartsBeforeApply--;
                throw new RuntimeException('simulated START driver exception before apply');
            }
            if (!$this->nextRepeatableRead) {
                $this->startsWithoutOneShot++;
            }
            $this->nextRepeatableRead = false;
            if ($this->applyStart) {
                $this->activeTransaction = '1';
                $this->savepointExists = false;
            }
            if ($this->throwOnStart) {
                throw new RuntimeException('simulated START driver exception');
            }
            return $this->startResult;
        }
        if ($sql === 'COMMIT') {
            if ($this->applyCommit) {
                $this->activeTransaction = '0';
                $this->savepointExists = false;
            }
            if ($this->replaceConnectionOnCommit) {
                $this->connectionId = (string) ((int) $this->connectionId + 1);
            }
            if ($this->commitLeavesError) {
                $this->last_error = 'simulated COMMIT error after control';
            }
            if ($this->throwOnCommit) {
                throw new RuntimeException('simulated COMMIT driver exception');
            }
            return $this->commitResult;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->applyRollback) {
                $this->activeTransaction = '0';
                $this->savepointExists = false;
            }
            if ($this->throwOnRollback) {
                throw new RuntimeException('simulated ROLLBACK driver exception');
            }
            return $this->rollbackResult;
        }
        if (preg_match('/^SAVEPOINT `wprism_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            $this->savepointExists = true;
            return 1;
        }
        if (preg_match('/^RELEASE SAVEPOINT `wprism_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            if (!$this->savepointExists) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->savepointExists = false;
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE wp_posts SET post_password = ')) {
            if ($this->endTransactionBeforeProtectedUpdate) {
                $this->activeTransaction = '0';
                $this->savepointExists = false;
            }
            if ($this->replaceConnectionOnProtectedUpdate) {
                $this->connectionId = (string) ((int) $this->connectionId + 1);
                $this->activeTransaction = '0';
                $this->savepointExists = false;
                $this->protectedPassword = $this->protectedTransactionPassword;
            }
            if (preg_match("/SET post_password = '((?:''|[^'])*)'/D", $sql, $match) !== 1) {
                throw new RuntimeException('protected password update carried no bounded value');
            }
            $intended = str_replace("''", "'", $match[1]);
            $guarded = $this->activeTransaction === '1'
                && hash_equals($this->protectedConnectionId, (string) $this->connectionId)
                && str_contains($sql, "CONNECTION_ID() = '{$this->protectedConnectionId}'")
                && str_contains($sql, '@@in_transaction = 1')
                && str_contains($sql, "BINARY post_password = BINARY '"
                    . str_replace("'", "''", $this->protectedPassword) . "'")
                && str_contains($sql, "BINARY m.uuid = BINARY '{$this->protectedUuid}'")
                && str_contains($sql, "BINARY pm.meta_value = BINARY '{$this->protectedUuid}'");
            if (!$guarded || hash_equals($this->protectedPassword, $intended)) {
                return 0;
            }
            $this->protectedPassword = $intended;
            ++$this->protectedPasswordMutations;
            return 1;
        }
        throw new RuntimeException("unexpected mutation query: $sql");
    }

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $sql = (string) preg_replace_callback(
                '/%[sd]/',
                static fn(array $match): string => $match[0] === '%d'
                    ? (string) (int) $arg
                    : "'" . str_replace("'", "''", (string) $arg) . "'",
                $sql,
                1
            );
        }
        return $sql;
    }

    public function get_var(string $sql): int|string|null|false {
        $this->queries[] = $sql;
        if (in_array($sql, ['SELECT CONNECTION_ID()', 'SELECT @@in_transaction'], true)) {
            $this->stateProbeStep++;
            if ($this->replaceConnectionAtStateProbeStep === $this->stateProbeStep) {
                $this->connectionId = (string) ((int) $this->connectionId + 1);
                $this->activeTransaction = $this->replacementActiveTransaction;
            }
        }
        if ($sql === 'SELECT CONNECTION_ID()') {
            return $this->connectionId;
        }
        if ($sql === 'SELECT @@in_transaction') {
            if ($this->activeTransactionError) {
                $this->last_error = 'simulated transaction-state read failure';
            }
            return $this->activeTransaction;
        }
        if (str_starts_with($sql, 'SELECT post_password FROM wp_posts WHERE ')) {
            if ($this->replaceConnectionOnProtectedReadback) {
                $this->connectionId = (string) ((int) $this->connectionId + 1);
                $this->activeTransaction = '0';
                $this->savepointExists = false;
                $this->protectedPassword = $this->protectedTransactionPassword;
            }
            $guarded = $this->activeTransaction === '1'
                && hash_equals($this->protectedConnectionId, (string) $this->connectionId)
                && str_contains($sql, "CONNECTION_ID() = '{$this->protectedConnectionId}'")
                && str_contains($sql, '@@in_transaction = 1')
                && str_contains($sql, "BINARY post_password = BINARY '"
                    . str_replace("'", "''", $this->protectedPassword) . "'")
                && str_contains($sql, "BINARY m.uuid = BINARY '{$this->protectedUuid}'")
                && str_contains($sql, "BINARY pm.meta_value = BINARY '{$this->protectedUuid}'");
            return $guarded ? $this->protectedPassword : null;
        }
        if ($this->metadataProbeFails) {
            $this->last_error = 'simulated metadata probe failure';
            return false;
        }
        return 1;
    }

    public function get_results(string $sql, $format = null): mixed {
        $this->queries[] = $sql;
        if (str_contains($sql, 'information_schema.TABLES')) {
            if (str_contains($sql, 'SELECT TABLE_NAME FROM')) {
                if ($this->topologyProbeError) {
                    $this->last_error = 'simulated exact topology census failure';
                    return [];
                }
                if ($this->topologyRowsOverride !== null) {
                    return $this->topologyRowsOverride;
                }
            }
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
        if ($this->protectedLockFixture) {
            if (str_contains($sql, 'FROM `wp_wprism_map` FORCE INDEX')) {
                return [[
                    'uuid' => $this->protectedUuid,
                    'id_kind' => 'post',
                    'local_id' => '41',
                    'entity_type' => 'post',
                ]];
            }
            if (str_contains($sql, 'SELECT ID, post_type, post_password FROM wp_posts')) {
                return [[
                    'ID' => '41',
                    'post_type' => 'post',
                    'post_password' => $this->protectedPassword,
                ]];
            }
            if (str_contains($sql, 'SELECT meta_id, `post_id` AS owner_id')) {
                return [[
                    'meta_id' => '71',
                    'owner_id' => '41',
                    'meta_key' => '_wprism_uuid',
                    'meta_value_prefix' => $this->protectedUuid,
                    'meta_value_bytes' => '36',
                ]];
            }
            if (str_contains($sql, 'SELECT meta_id, `term_id` AS owner_id')) {
                return [];
            }
            if (str_contains($sql, 'SELECT `ID` AS owner_id FROM `wp_posts`')) {
                if ($this->replaceConnectionOnProtectedOwnerLock) {
                    $this->connectionId = (string) ((int) $this->connectionId + 1);
                    $this->activeTransaction = '0';
                    $this->savepointExists = false;
                }
                return [['owner_id' => '41']];
            }
        }
        if ($this->indexResultMode === 'false') return false;
        if ($this->indexResultMode === 'null') return null;
        if ($this->indexResultMode === 'error') {
            $this->last_error = 'simulated SHOW INDEX failure';
            return [];
        }
        if ($this->indexResultMode === 'associative') {
            return ['not-a-list' => $this->indexRows[0] ?? []];
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
$check(
    DeleteGuardEvaluator::lock_index([
        'column' => 'meta_value',
        'lock_column' => 'meta_key',
        'where' => ['meta_key' => '_product_id'],
    ], 'wp_woocommerce_order_itemmeta') === 'meta_key_value',
    'scalar reference guards may lock their complete indexed discriminator range'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'option_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null],
]);
$check(
    DeleteGuardEvaluator::lock_index(['option_name_ref' => true, 'column' => 'ignored'], 'wp_options') === 'option_name',
    'option-name guard locks the declared option-name range rather than its incidental column'
);

$absenceDb = new DeleteGuardEvaluatorFakeWpdb([]);
$GLOBALS['wpdb'] = $absenceDb;
$policyWithoutConstructor = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$scanner = new DeleteGuardReferenceScanner($policyWithoutConstructor);
$requiredAbsent = $scanner->count(
    ['table' => 'version_optional_refs', 'column' => 'product_id', 'id_kind' => 'post'],
    '00000000-0000-0000-0000-000000000001',
    [],
    []
);
$absenceEmpty = $scanner->count(
    [
        'table' => 'version_optional_refs', 'column' => 'product_id',
        'id_kind' => 'post', 'table_absence' => 'empty',
    ],
    '00000000-0000-0000-0000-000000000001',
    [],
    []
);
$check(
    $requiredAbsent === [
        'count' => 0,
        'error' => "required guard table 'version_optional_refs' is absent",
        'rows' => [],
    ] && $absenceEmpty['count'] === 0 && $absenceEmpty['error'] === null && $absenceEmpty['rows'] === []
        && ($absenceEmpty['witness'] ?? null) === hash('sha256', \WPrism\Canon::encode([
            'format' => 'wprism-delete-guard-witness/v2',
            'rows' => [],
            'state' => 'absent',
            'table' => 'wp_version_optional_refs',
        ]))
        && ($absenceEmpty['witness'] ?? null) !== hash('sha256', \WPrism\Canon::encode([
            'format' => 'wprism-delete-guard-witness/v2',
            'rows' => [],
            'state' => 'present',
            'table' => 'wp_version_optional_refs',
        ])),
    'only table_absence=empty accepts exact absence, and its witness cannot equal present-empty topology'
);
$absenceDb->topologyProbeError = true;
$probeFailure = $scanner->count(
    [
        'table' => 'version_optional_refs', 'column' => 'product_id',
        'id_kind' => 'post', 'table_absence' => 'empty',
    ],
    '00000000-0000-0000-0000-000000000001',
    [],
    []
);
$check(
    str_contains((string) $probeFailure['error'], 'exact guard-table topology census failed')
        && $probeFailure['rows'] === [],
    'an exact topology census error remains blocking rather than masquerading as absence'
);
$nearMatchDb = new DeleteGuardEvaluatorFakeWpdb([]);
$nearMatchDb->topologyRowsOverride = [['TABLE_NAME' => 'wpXversion_optional_refs']];
$GLOBALS['wpdb'] = $nearMatchDb;
$nearMatchFailure = $scanner->count(
    [
        'table' => 'version_optional_refs', 'column' => 'product_id',
        'id_kind' => 'post', 'table_absence' => 'empty',
    ],
    '00000000-0000-0000-0000-000000000001',
    [],
    []
);
$check(
    str_contains((string) $nearMatchFailure['error'], 'ambiguous table identity'),
    'a case-fold or wildcard-like near match never proves exact guard-table absence'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'unsafe-name!', 'Seq_in_index' => 1, 'Column_name' => 'target_id', 'Sub_part' => null],
    ['Key_name' => 'later_target', 'Seq_in_index' => 2, 'Column_name' => 'target_id', 'Sub_part' => null],
]);
$check(
    DeleteGuardEvaluator::lock_index(['column' => 'target_id'], 'wp_refs') === 'unsafename',
    'ordinary scalar guards use a sanitized first-column lock index'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'legal-index-🙂', 'Seq_in_index' => 1, 'Column_name' => 'legal.column 🙂', 'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE'],
    ['Key_name' => 'functional_extra', 'Seq_in_index' => 1, 'Column_name' => null, 'Expression' => 'lower(`unrelated`)', 'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE'],
    ['Key_name' => 'peculiar_unrelated', 'Seq_in_index' => 'not-an-ordinal', 'Column_name' => 'unrelated', 'Sub_part' => null],
    ['Key_name' => 'prefix_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => 191, 'Non_unique' => 0, 'Index_type' => 'BTREE'],
    ['Key_name' => 'hidden_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null, 'Non_unique' => 0, 'Visible' => 'NO', 'Index_type' => 'BTREE'],
    ['Key_name' => 'ignored_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null, 'Non_unique' => 0, 'Ignored' => 'YES', 'Index_type' => 'BTREE'],
    ['Key_name' => 'fulltext_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'FULLTEXT'],
    ['Key_name' => 'nonunique_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE'],
    ['Key_name' => 'unique_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null, 'Non_unique' => 0, 'Visible' => 'YES', 'Ignored' => 'NO', 'Index_type' => 'BTREE'],
]);
$check(
    DeleteGuardEvaluator::full_width_lock_index('wp_options', 'option_name', 'mixed option', true)
        === 'unique_name',
    'full-width lock proof ignores legal exotic, functional, and malformed-but-unrelated groups while rejecting unsafe candidate indexes'
);

foreach ([
    'MySQL invisible' => ['Visible' => 'NO', 'Index_type' => 'BTREE'],
    'MariaDB ignored' => ['Ignored' => 'YES', 'Index_type' => 'BTREE'],
    'non-BTREE' => ['Visible' => 'YES', 'Index_type' => 'FULLTEXT'],
] as $family => $extra) {
    $GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([array_merge([
        'Key_name' => 'option_name',
        'Seq_in_index' => 1,
        'Column_name' => 'option_name',
        'Sub_part' => null,
        'Non_unique' => 0,
    ], $extra)]);
    try {
        DeleteGuardEvaluator::full_width_lock_index('wp_options', 'option_name', 'mixed option', true);
        $familyRefused = false;
    } catch (RuntimeException $failure) {
        $familyRefused = str_contains($failure->getMessage(), 'visible full-width unique first-column index');
    }
    $check($familyRefused, "$family index metadata cannot prove a singleton next-key lock");
}

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([[
    'Key_name' => 'legacy_option_name',
    'Seq_in_index' => 1,
    'Column_name' => 'option_name',
    'Sub_part' => null,
    'Non_unique' => 0,
    'Index_type' => 'BTREE',
]]);
$check(
    DeleteGuardEvaluator::full_width_lock_index('wp_options', 'option_name', 'mixed option', true)
        === 'legacy_option_name',
    'older-server rows remain accepted when the family-specific visibility and ignored fields are absent'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => '1', 'Column_name' => 'uuid', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => '2', 'Column_name' => 'id_kind', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ['Key_name' => 'uuid_lookup', 'Seq_in_index' => '1', 'Column_name' => 'uuid', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
]);
$check(
    DeleteGuardEvaluator::full_width_composite_unique_lock_index(
        'wp_wprism_map',
        ['uuid', 'id_kind'],
        'protected post identity locking'
    ) === 'PRIMARY',
    'compound lock proof accepts the stock full-width unique map identity and ignores a nonunique UUID lookup'
);

foreach ([
    'nonunique pair' => [
        ['Key_name' => 'map_pair', 'Seq_in_index' => '1', 'Column_name' => 'uuid', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
        ['Key_name' => 'map_pair', 'Seq_in_index' => '2', 'Column_name' => 'id_kind', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
    ],
    'prefixed pair' => [
        ['Key_name' => 'map_pair', 'Seq_in_index' => '1', 'Column_name' => 'uuid', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
        ['Key_name' => 'map_pair', 'Seq_in_index' => '2', 'Column_name' => 'id_kind', 'Sub_part' => '16', 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ],
    'unique superset' => [
        ['Key_name' => 'map_triple', 'Seq_in_index' => '1', 'Column_name' => 'uuid', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
        ['Key_name' => 'map_triple', 'Seq_in_index' => '2', 'Column_name' => 'id_kind', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
        ['Key_name' => 'map_triple', 'Seq_in_index' => '3', 'Column_name' => 'local_id', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ],
] as $label => $rows) {
    $GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb($rows);
    try {
        DeleteGuardEvaluator::full_width_composite_unique_lock_index(
            'wp_wprism_map',
            ['uuid', 'id_kind'],
            'protected post identity locking'
        );
        $compoundRefused = false;
    } catch (RuntimeException $failure) {
        $compoundRefused = str_contains(
            $failure->getMessage(),
            'visible full-width unique ordered-columns index on (uuid, id_kind)'
        );
    }
    $check($compoundRefused, "$label cannot prove the compound map singleton lock");
}

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([[
    'Key_name' => 'meta_key',
    'Seq_in_index' => '1',
    'Column_name' => 'meta_key',
    'Sub_part' => '191',
    'Non_unique' => '1',
    'Index_type' => 'BTREE',
]]);
$check(
    DeleteGuardEvaluator::bounded_prefix_lock_index(
        'wp_postmeta',
        'meta_key',
        strlen('_wp_attached_file'),
        'attachment global attached-file authority'
    ) === 'meta_key',
    'strict prefix-index proof admits stock postmeta meta_key(191) for the complete attached-file literal'
);
$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([[
    'Key_name' => 'too_short_meta_key',
    'Seq_in_index' => '1',
    'Column_name' => 'meta_key',
    'Sub_part' => '16',
    'Non_unique' => '1',
    'Index_type' => 'BTREE',
]]);
try {
    DeleteGuardEvaluator::bounded_prefix_lock_index(
        'wp_postmeta',
        'meta_key',
        strlen('_wp_attached_file'),
        'attachment global attached-file authority'
    );
    $shortPrefixRefused = false;
} catch (RuntimeException $failure) {
    $shortPrefixRefused = str_contains($failure->getMessage(), 'at-least-17-character');
}
$check($shortPrefixRefused, 'strict prefix-index proof rejects a prefix shorter than the complete locking literal');

foreach ([
    'loose sequence' => ['Seq_in_index' => '1junk', 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    'loose uniqueness' => ['Seq_in_index' => '1', 'Non_unique' => '0junk', 'Index_type' => 'BTREE'],
    'missing index type' => ['Seq_in_index' => '1', 'Non_unique' => '0'],
    'unknown visibility' => ['Seq_in_index' => '1', 'Non_unique' => '0', 'Index_type' => 'BTREE', 'Visible' => 'MAYBE'],
    'unknown ignored state' => ['Seq_in_index' => '1', 'Non_unique' => '0', 'Index_type' => 'BTREE', 'Ignored' => 'MAYBE'],
] as $label => $fields) {
    $GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([array_merge([
        'Key_name' => 'option_name',
        'Column_name' => 'option_name',
        'Sub_part' => null,
    ], $fields)]);
    try {
        DeleteGuardEvaluator::full_width_lock_index('wp_options', 'option_name', 'mixed option', true);
        $malformedIndexRefused = false;
    } catch (RuntimeException $failure) {
        $malformedIndexRefused = str_contains($failure->getMessage(), 'malformed row');
    }
    $check($malformedIndexRefused, "$label SHOW INDEX metadata fails closed");
}

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'option_name', 'Seq_in_index' => '1', 'Column_name' => 'option_name', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ['Key_name' => 'option_name', 'Seq_in_index' => '1', 'Column_name' => 'option_name', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
]);
try {
    DeleteGuardEvaluator::full_width_lock_index('wp_options', 'option_name', 'mixed option', true);
    $duplicateIndexPositionRefused = false;
} catch (RuntimeException $failure) {
    $duplicateIndexPositionRefused = str_contains($failure->getMessage(), 'duplicate index positions');
}
$check($duplicateIndexPositionRefused, 'duplicate SHOW INDEX positions fail closed');

// Only the chosen index name is interpolated. A legal exotic later column in
// an otherwise usable owner-range index does not invalidate its exact first
// column or make the selected index name unsafe.
$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'user_id_meta_key', 'Seq_in_index' => '1', 'Column_name' => 'user_id', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
    ['Key_name' => 'user_id_meta_key', 'Seq_in_index' => '2', 'Column_name' => 'legal-column 🙂', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
]);
$check(
    DeleteGuardEvaluator::full_width_lock_index('wp_usermeta', 'user_id', 'owner range')
        === 'user_id_meta_key',
    'full-width proof accepts a harmless legal exotic later column without interpolating it'
);

foreach ([
    'loose later prefix' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => '191junk', 'Non_unique' => '1', 'Index_type' => 'BTREE'],
    'oversized later prefix' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => '65536', 'Non_unique' => '1', 'Index_type' => 'BTREE'],
    'missing later type' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => null, 'Non_unique' => '1'],
    'changed later type' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'FULLTEXT'],
    'changed later uniqueness' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    'changed later visibility' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE', 'Visible' => 'NO'],
    'missing later visibility field' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
    'changed later ignored state' => ['Seq_in_index' => '2', 'Column_name' => 'meta_key', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE', 'Ignored' => 'YES'],
] as $label => $later) {
    $first = [
        'Key_name' => 'user_id_meta_key',
        'Seq_in_index' => '1',
        'Column_name' => 'user_id',
        'Sub_part' => null,
        'Non_unique' => '1',
        'Index_type' => 'BTREE',
    ];
    if (str_contains($label, 'visibility')) {
        $first['Visible'] = 'YES';
    }
    if (str_contains($label, 'ignored')) {
        $first['Ignored'] = 'NO';
    }
    $later['Key_name'] = 'user_id_meta_key';
    $GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([$first, $later]);
    try {
        DeleteGuardEvaluator::full_width_lock_index('wp_usermeta', 'user_id', 'owner range');
        $laterRowRefused = false;
    } catch (RuntimeException $failure) {
        $laterRowRefused = str_contains($failure->getMessage(), 'malformed row')
            || str_contains($failure->getMessage(), 'inconsistent composite-index metadata');
    }
    $check($laterRowRefused, "$label composite SHOW INDEX row fails closed");
}

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'user_id_meta_key', 'Seq_in_index' => '1', 'Column_name' => 'user_id', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
    ['Key_name' => 'user_id_meta_key', 'Seq_in_index' => '3', 'Column_name' => 'meta_key', 'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'],
]);
try {
    DeleteGuardEvaluator::full_width_lock_index('wp_usermeta', 'user_id', 'owner range');
    $indexGapRefused = false;
} catch (RuntimeException $failure) {
    $indexGapRefused = str_contains($failure->getMessage(), 'noncontiguous index positions');
}
$check($indexGapRefused, 'noncontiguous composite SHOW INDEX positions fail closed');

try {
    DeleteGuardEvaluator::full_width_lock_index(str_repeat('t', 65), 'user_id', 'owner range');
    $oversizedTableRefused = false;
} catch (RuntimeException $failure) {
    $oversizedTableRefused = str_contains($failure->getMessage(), 'unsafe table/column name');
}
$check($oversizedTableRefused, 'lock-index proof enforces the MySQL 64-byte table identifier boundary');

foreach (['false', 'null', 'error', 'associative'] as $mode) {
    $indexWpdb = new DeleteGuardEvaluatorFakeWpdb([[
        'Key_name' => 'option_name',
        'Seq_in_index' => '1',
        'Column_name' => 'option_name',
        'Sub_part' => null,
        'Non_unique' => '0',
        'Index_type' => 'BTREE',
    ]]);
    $indexWpdb->indexResultMode = $mode;
    $GLOBALS['wpdb'] = $indexWpdb;
    try {
        DeleteGuardEvaluator::full_width_lock_index('wp_options', 'option_name', 'mixed option', true);
        $indexReadRefused = false;
    } catch (RuntimeException $failure) {
        $indexReadRefused = str_contains($failure->getMessage(), 'index introspection failed');
    }
    $check($indexReadRefused, "$mode SHOW INDEX result fails closed");
}

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'user_id', 'Seq_in_index' => 1, 'Column_name' => 'user_id', 'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE'],
]);
$check(
    DeleteGuardEvaluator::full_width_lock_index('wp_usermeta', 'user_id', 'user-meta owner range')
        === 'user_id',
    'full-width lock proof permits a nonunique complete owner-range index when singleton identity is not claimed'
);
try {
    DeleteGuardEvaluator::full_width_lock_index('wp_usermeta', 'user_id', 'user-meta singleton', true);
    $fullWidthUniqueRefused = false;
} catch (RuntimeException $failure) {
    $fullWidthUniqueRefused = str_contains($failure->getMessage(), 'visible full-width unique first-column index');
}
$check($fullWidthUniqueRefused, 'full-width lock proof loudly refuses a missing uniqueness guarantee');

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
    $metadataRefused = $e->getMessage()
        === 'wprism: deletion guard locking refused — unable to acquire metadata lock for guard table '
            . 'wp_postmeta: simulated metadata probe failure'
        && count($engineWpdb->queries) === 1;
}
$check($metadataRefused, 'metadata-lock failure refuses before information-schema introspection');

$engineWpdb = new DeleteGuardEvaluatorFakeWpdb([]);
$GLOBALS['wpdb'] = $engineWpdb;
try {
    DeleteGuardEvaluator::assert_innodb_tables(['wp_termmeta` WHERE 1=0 --'], 'authored meta locking');
    $hostileTableRefused = false;
} catch (RuntimeException $e) {
    $hostileTableRefused = str_contains($e->getMessage(), 'unsafe table identifier')
        && $engineWpdb->queries === [];
}
$check($hostileTableRefused, 'hostile runtime table identifiers refuse before any SQL interpolation');

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

$isolationWpdb = new DeleteGuardEvaluatorFakeWpdb([]);
$GLOBALS['wpdb'] = $isolationWpdb;
$isolationWpdb->activeTransaction = '0';
Db::start_repeatable_read('fixture transaction start');
DeleteGuardEvaluator::begin_authored_transaction();
$check(
    array_slice($isolationWpdb->queries, 0, 11) === [
        'SELECT CONNECTION_ID()',
        'SELECT @@in_transaction',
        'SELECT CONNECTION_ID()',
        'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
        'SELECT CONNECTION_ID()',
        'SELECT @@in_transaction',
        'SELECT CONNECTION_ID()',
        'START TRANSACTION',
        'SELECT CONNECTION_ID()',
        'SELECT @@in_transaction',
        'SELECT CONNECTION_ID()',
    ],
    'authored transaction binds one connection and positively sets one-shot REPEATABLE READ immediately before START'
);
$isolationWpdb->queries = [];
DeleteGuardEvaluator::assert_transaction_isolation('owner-range locking');
$check(
    count($isolationWpdb->queries) === 3
        && $isolationWpdb->queries[0] === 'SELECT @@in_transaction'
        && str_starts_with($isolationWpdb->queries[1], 'RELEASE SAVEPOINT `wprism_authored_')
        && str_starts_with($isolationWpdb->queries[2], 'SAVEPOINT `wprism_authored_')
        && !array_filter(
            $isolationWpdb->queries,
            static fn(string $query): bool => str_contains($query, '@@transaction_isolation')
                || str_contains($query, '@@tx_isolation')
                || str_contains($query, 'innodb_trx')
        ),
    'each lock rechecks canonical activity and savepoint continuity without privileged/session-default introspection'
);
$isolationWpdb->savepointExists = false; // Simulates COMMIT followed by a same-isolation START TRANSACTION.
try {
    DeleteGuardEvaluator::assert_transaction_isolation('owner-range locking');
    $restartedTransactionRefused = false;
} catch (RuntimeException $e) {
    $restartedTransactionRefused = str_contains($e->getMessage(), 'lost authored transaction continuity');
}
$check($restartedTransactionRefused, 'same-isolation transaction restart cannot reuse the authored lock boundary');
DeleteGuardEvaluator::end_authored_transaction();
Db::rollback('fixture transaction cleanup');

$protectedIndexes = [
    ['Key_name' => 'map_pair', 'Seq_in_index' => '1', 'Column_name' => 'uuid', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ['Key_name' => 'map_pair', 'Seq_in_index' => '2', 'Column_name' => 'id_kind', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ['Key_name' => 'post_id_unique', 'Seq_in_index' => '1', 'Column_name' => 'ID', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ['Key_name' => 'term_id_unique', 'Seq_in_index' => '1', 'Column_name' => 'term_id', 'Sub_part' => null, 'Non_unique' => '0', 'Index_type' => 'BTREE'],
    ['Key_name' => 'meta_key', 'Seq_in_index' => '1', 'Column_name' => 'meta_key', 'Sub_part' => '191', 'Non_unique' => '1', 'Index_type' => 'BTREE'],
];
$protectedEngines = [
    'wp_wprism_map' => 'InnoDB',
    'wp_posts' => 'InnoDB',
    'wp_postmeta' => 'InnoDB',
    'wp_terms' => 'InnoDB',
    'wp_termmeta' => 'InnoDB',
];
$reconnectedLock = new DeleteGuardEvaluatorFakeWpdb($protectedIndexes, $protectedEngines);
$reconnectedLock->activeTransaction = '0';
$reconnectedLock->protectedLockFixture = true;
$reconnectedLock->replaceConnectionOnProtectedOwnerLock = true;
$GLOBALS['wpdb'] = $reconnectedLock;
Db::forget_transaction_tracking();
Db::start_repeatable_read('protected reconnect-at-lock start');
DeleteGuardEvaluator::begin_authored_transaction();
try {
    ProtectedPostIdentity::lock($reconnectedLock->protectedUuid, 'post');
    $reconnectedLockRefused = false;
} catch (Throwable $failure) {
    $reconnectedLockRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$check(
    $reconnectedLockRefused
        && $reconnectedLock->protectedPassword === 'old-password'
        && !array_filter(
            $reconnectedLock->queries,
            static fn(string $query): bool => str_starts_with($query, 'UPDATE wp_posts SET post_password')
        ),
    'reconnect while replaying the final owner lock fails post-lock continuity before any secret mutation'
);
DeleteGuardEvaluator::end_authored_transaction();
Db::forget_transaction_tracking();

/**
 * @return array{result:?bool,failure:?Throwable,password:string,mutations:int,queries:list<string>}
 */
$exerciseProtectedPasswordUpdate = static function (
    string $oldValue,
    string $newValue,
    ?callable $configure = null
): array {
    $fixture = new DeleteGuardEvaluatorFakeWpdb([]);
    $fixture->activeTransaction = '0';
    $fixture->protectedPassword = $oldValue;
    $fixture->protectedTransactionPassword = $oldValue;
    $GLOBALS['wpdb'] = $fixture;
    Db::forget_transaction_tracking();
    Db::start_repeatable_read('protected password fixture start');
    DeleteGuardEvaluator::begin_authored_transaction();
    $connectionId = Db::transaction_connection_id('protected password fixture token');
    if ($configure !== null) {
        $configure($fixture);
    }
    $result = null;
    $failure = null;
    try {
        $result = ProtectedPostIdentity::update_password(
            $fixture->protectedUuid,
            41,
            'post',
            $oldValue,
            $newValue,
            $connectionId
        );
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    $outcome = [
        'result' => $result,
        'failure' => $failure,
        'password' => $fixture->protectedPassword,
        'mutations' => $fixture->protectedPasswordMutations,
        'queries' => $fixture->queries,
    ];
    try {
        if ((string) $fixture->connectionId === $connectionId && $fixture->activeTransaction === '1') {
            Db::rollback('protected password fixture rollback');
        } else {
            Db::forget_transaction_tracking();
        }
    } finally {
        DeleteGuardEvaluator::end_authored_transaction();
    }
    return $outcome;
};

$ordinaryProtectedUpdate = $exerciseProtectedPasswordUpdate('old-password', 'new-password');
$check(
    $ordinaryProtectedUpdate['result'] === true
        && $ordinaryProtectedUpdate['failure'] === null
        && $ordinaryProtectedUpdate['password'] === 'new-password'
        && $ordinaryProtectedUpdate['mutations'] === 1,
    'protected password CAS changes one row on the verified active session and confirms its guarded readback'
);
$idempotentProtectedUpdate = $exerciseProtectedPasswordUpdate('same-password', 'same-password');
$check(
    $idempotentProtectedUpdate['result'] === true
        && $idempotentProtectedUpdate['failure'] === null
        && $idempotentProtectedUpdate['password'] === 'same-password'
        && $idempotentProtectedUpdate['mutations'] === 0,
    'idempotent protected password CAS accepts zero affected rows only after guarded same-session readback'
);
$reconnectedProtectedUpdate = $exerciseProtectedPasswordUpdate(
    'old-password',
    'must-not-autocommit',
    static function (DeleteGuardEvaluatorFakeWpdb $fixture): void {
        $fixture->replaceConnectionOnProtectedUpdate = true;
    }
);
$check(
    $reconnectedProtectedUpdate['failure'] instanceof \WPrism\DatabaseTransactionOutcomeException
        && $reconnectedProtectedUpdate['password'] === 'old-password'
        && $reconnectedProtectedUpdate['mutations'] === 0,
    'wpdb reconnect-and-replay at protected UPDATE matches zero on the replacement and cannot publish the secret'
);
$inactiveProtectedUpdate = $exerciseProtectedPasswordUpdate(
    'old-password',
    'must-not-autocommit',
    static function (DeleteGuardEvaluatorFakeWpdb $fixture): void {
        $fixture->endTransactionBeforeProtectedUpdate = true;
    }
);
$check(
    $inactiveProtectedUpdate['failure'] instanceof \WPrism\DatabaseTransactionOutcomeException
        && $inactiveProtectedUpdate['password'] === 'old-password'
        && $inactiveProtectedUpdate['mutations'] === 0,
    'same-session transaction loss at protected UPDATE matches zero under @@in_transaction and publishes no secret'
);
$reconnectedProtectedReadback = $exerciseProtectedPasswordUpdate(
    'old-password',
    'must-roll-back',
    static function (DeleteGuardEvaluatorFakeWpdb $fixture): void {
        $fixture->replaceConnectionOnProtectedReadback = true;
    }
);
$check(
    $reconnectedProtectedReadback['failure'] instanceof \WPrism\DatabaseTransactionOutcomeException
        && $reconnectedProtectedReadback['password'] === 'old-password',
    'reconnect at guarded password readback rolls back the original transaction and refuses before commit'
);

$setFailureWpdb = new DeleteGuardEvaluatorFakeWpdb([]);
$setFailureWpdb->activeTransaction = '0';
$setFailureWpdb->failSetTransaction = true;
$setFailureWpdb->applySetTransaction = false;
$GLOBALS['wpdb'] = $setFailureWpdb;
try {
    Db::start_repeatable_read('fixture transaction start');
    $setFailureRefused = false;
} catch (Throwable $failure) {
    $setFailureRefused = $failure instanceof \WPrism\DatabaseMutationException;
}
$check(
    $setFailureRefused
        && $setFailureWpdb->startsWithoutOneShot === 1
        && $setFailureWpdb->activeTransaction === '0'
        && in_array('ROLLBACK', $setFailureWpdb->queries, true),
    'failed non-applied one-shot isolation is consumed by a bounded transaction before refusal'
);
$setFailureWpdb->failSetTransaction = false;
$setFailureWpdb->applySetTransaction = true;
Db::start_repeatable_read('transaction after failed isolation cleanup');
$check(
    Db::transaction_active('transaction after failed isolation cleanup verification'),
    'a transaction after failed isolation cleanup starts from a fresh one-shot control'
);
Db::rollback('transaction after failed isolation cleanup rollback');

$ambiguousIsolationCases = [
    'false-after-apply' => static function (DeleteGuardEvaluatorFakeWpdb $wpdb): void {
        $wpdb->setTransactionResult = false;
    },
    'throw-after-apply' => static function (DeleteGuardEvaluatorFakeWpdb $wpdb): void {
        $wpdb->throwOnSetTransaction = true;
    },
    'truthy-plus-error' => static function (DeleteGuardEvaluatorFakeWpdb $wpdb): void {
        $wpdb->setTransactionLeavesError = true;
    },
];
foreach ($ambiguousIsolationCases as $case => $configure) {
    $ambiguousSet = new DeleteGuardEvaluatorFakeWpdb([]);
    $ambiguousSet->activeTransaction = '0';
    $configure($ambiguousSet);
    $GLOBALS['wpdb'] = $ambiguousSet;
    Db::forget_transaction_tracking();
    try {
        Db::start_repeatable_read("$case isolation");
        $ambiguousSetRefused = false;
    } catch (Throwable $failure) {
        $ambiguousSetRefused = $failure instanceof \WPrism\DatabaseMutationException;
    }
    $consumed = $ambiguousSet->activeTransaction === '0'
        && $ambiguousSet->nextRepeatableRead === false
        && in_array('ROLLBACK', $ambiguousSet->queries, true);
    $ambiguousSet->setTransactionResult = 1;
    $ambiguousSet->throwOnSetTransaction = false;
    $ambiguousSet->setTransactionLeavesError = false;
    Db::start_repeatable_read("$case isolation subsequent transaction");
    $fresh = Db::transaction_active("$case isolation subsequent verification");
    Db::rollback("$case isolation subsequent rollback");
    $check(
        $ambiguousSetRefused && $consumed && $fresh,
        "$case one-shot isolation ambiguity is consumed and cannot contaminate the next transaction"
    );
}

$reconnectedSet = new DeleteGuardEvaluatorFakeWpdb([]);
$reconnectedSet->activeTransaction = '0';
$reconnectedSet->replaceConnectionOnSetTransaction = true;
$GLOBALS['wpdb'] = $reconnectedSet;
Db::forget_transaction_tracking();
try {
    Db::start_repeatable_read('reconnected isolation');
    $reconnectedSetRefused = false;
} catch (Throwable $failure) {
    $reconnectedSetRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$check(
    $reconnectedSetRefused && $reconnectedSet->activeTransaction === '0',
    'connection replacement across one-shot isolation refuses without applying control to the replacement'
);
Db::forget_transaction_tracking();

foreach ([2, 3] as $probeStep) {
    $reconnectedPreflight = new DeleteGuardEvaluatorFakeWpdb([]);
    $reconnectedPreflight->activeTransaction = '0';
    $reconnectedPreflight->replaceConnectionAtStateProbeStep = $probeStep;
    $GLOBALS['wpdb'] = $reconnectedPreflight;
    try {
        Db::start("reconnected preflight probe $probeStep");
        $preflightProbeRefused = false;
    } catch (Throwable $failure) {
        $preflightProbeRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
    }
    $check(
        $preflightProbeRefused
            && !in_array('START TRANSACTION', $reconnectedPreflight->queries, true),
        "connection replacement between preflight state probes $probeStep refuses before START"
    );
    Db::forget_transaction_tracking();
}

foreach ([4, 5, 6] as $probeStep) {
    $reconnectedIsolationProof = new DeleteGuardEvaluatorFakeWpdb([]);
    $reconnectedIsolationProof->activeTransaction = '0';
    $reconnectedIsolationProof->replaceConnectionAtStateProbeStep = $probeStep;
    $GLOBALS['wpdb'] = $reconnectedIsolationProof;
    try {
        Db::start_repeatable_read("reconnected isolation proof $probeStep");
        $isolationProbeRefused = false;
    } catch (Throwable $failure) {
        $isolationProbeRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
    }
    $check(
        $isolationProbeRefused,
        "connection replacement at SET outcome-state probe $probeStep cannot strand an authorized override"
    );
    Db::forget_transaction_tracking();
}

foreach ([7, 8, 9] as $probeStep) {
    $reconnectedStartProof = new DeleteGuardEvaluatorFakeWpdb([]);
    $reconnectedStartProof->activeTransaction = '0';
    $reconnectedStartProof->replaceConnectionAtStateProbeStep = $probeStep;
    $GLOBALS['wpdb'] = $reconnectedStartProof;
    try {
        Db::start_repeatable_read("reconnected START proof $probeStep");
        $startProbeRefused = false;
    } catch (Throwable $failure) {
        $startProbeRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
    }
    $check(
        $startProbeRefused,
        "connection replacement at START outcome-state probe $probeStep cannot authorize a hybrid transaction"
    );
    Db::forget_transaction_tracking();
}

$readOnlySnapshot = new DeleteGuardEvaluatorFakeWpdb([]);
$readOnlySnapshot->activeTransaction = '0';
$GLOBALS['wpdb'] = $readOnlySnapshot;
Db::forget_transaction_tracking();
Db::start_read_only_consistent_snapshot('read-only snapshot regression');
$check(
    in_array('START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT', $readOnlySnapshot->queries, true)
        && Db::transaction_active('read-only snapshot verification'),
    'read-only snapshot uses the one-shot isolation and exact connection/outcome proof'
);
Db::rollback('read-only snapshot cleanup');

// wpdb can report false after the server applied a control statement. Bind
// the exact connection plus @@in_transaction transition so callers neither
// retry an applied COMMIT nor run rollback callbacks through autocommit.
$appliedFalseStart = new DeleteGuardEvaluatorFakeWpdb([]);
$appliedFalseStart->activeTransaction = '0';
$appliedFalseStart->startResult = false;
$GLOBALS['wpdb'] = $appliedFalseStart;
Db::forget_transaction_tracking();
Db::start_repeatable_read('applied-false start');
$check(
    Db::transaction_active('applied-false start verification'),
    'START returning false is accepted only when the same connection proves the transaction active'
);
Db::rollback('applied-false start cleanup');

$notAppliedStart = new DeleteGuardEvaluatorFakeWpdb([]);
$notAppliedStart->activeTransaction = '0';
$notAppliedStart->falseStartsBeforeApply = 1;
$GLOBALS['wpdb'] = $notAppliedStart;
Db::forget_transaction_tracking();
try {
    Db::start_repeatable_read('not-applied start');
    $notAppliedStartRefused = false;
} catch (Throwable $failure) {
    $notAppliedStartRefused = $failure instanceof \WPrism\DatabaseMutationException;
}
$check(
    $notAppliedStartRefused
        && $notAppliedStart->activeTransaction === '0'
        && $notAppliedStart->nextRepeatableRead === false
        && in_array('ROLLBACK', $notAppliedStart->queries, true),
    'START returning false before application consumes its pending one-shot isolation before refusal'
);

$appliedThrowStart = new DeleteGuardEvaluatorFakeWpdb([]);
$appliedThrowStart->activeTransaction = '0';
$appliedThrowStart->throwOnStart = true;
$GLOBALS['wpdb'] = $appliedThrowStart;
Db::forget_transaction_tracking();
Db::start_repeatable_read('applied-throw start');
$check(
    Db::transaction_active('applied-throw start verification'),
    'START throwing after the server transition is classified from the same-connection active state'
);
Db::rollback('applied-throw start cleanup');

$notAppliedThrowStart = new DeleteGuardEvaluatorFakeWpdb([]);
$notAppliedThrowStart->activeTransaction = '0';
$notAppliedThrowStart->throwStartsBeforeApply = 1;
$GLOBALS['wpdb'] = $notAppliedThrowStart;
Db::forget_transaction_tracking();
try {
    Db::start_repeatable_read('not-applied throw start');
    $notAppliedThrowStartRefused = false;
} catch (Throwable $failure) {
    $notAppliedThrowStartRefused = $failure instanceof \WPrism\DatabaseMutationException;
}
$check(
    $notAppliedThrowStartRefused
        && $notAppliedThrowStart->activeTransaction === '0'
        && $notAppliedThrowStart->nextRepeatableRead === false
        && in_array('ROLLBACK', $notAppliedThrowStart->queries, true),
    'START throwing before application consumes its pending one-shot isolation before refusal'
);

$unsettledStart = new DeleteGuardEvaluatorFakeWpdb([]);
$unsettledStart->activeTransaction = '0';
$unsettledStart->applyStart = false;
$unsettledStart->startResult = false;
$GLOBALS['wpdb'] = $unsettledStart;
Db::forget_transaction_tracking();
try {
    Db::start_repeatable_read('unsettled one-shot start');
    $unsettledStartRefused = false;
} catch (Throwable $failure) {
    $unsettledStartRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
try {
    Db::start('unrelated start while isolation is unresolved');
    $unrelatedBlocked = false;
} catch (Throwable $failure) {
    $unrelatedBlocked = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$unsettledStart->connectionId = '7002';
$unsettledStart->applyStart = true;
$unsettledStart->startResult = 1;
Db::start('replacement-connection transaction');
$replacementFresh = Db::transaction_active('replacement-connection verification');
Db::rollback('replacement-connection rollback');
$check(
    $unsettledStartRefused && $unrelatedBlocked && $replacementFresh,
    'an unconsumed one-shot isolation blocks the same session and cannot contaminate a replacement connection'
);

$appliedFalseCommit = new DeleteGuardEvaluatorFakeWpdb([]);
$appliedFalseCommit->activeTransaction = '0';
$appliedFalseCommit->commitResult = false;
$GLOBALS['wpdb'] = $appliedFalseCommit;
Db::forget_transaction_tracking();
Db::start_repeatable_read('applied-false commit start');
try {
    Db::commit('applied-false commit');
    $appliedFalseCommitRefused = false;
} catch (Throwable $failure) {
    $appliedFalseCommitRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$unresolvedCommitBlocksStart = false;
try {
    Db::start_repeatable_read('start after unresolved commit');
} catch (Throwable $failure) {
    $unresolvedCommitBlocksStart = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$check(
    $appliedFalseCommitRefused
        && $appliedFalseCommit->activeTransaction === '0'
        && $unresolvedCommitBlocksStart,
    'COMMIT false plus inactive is outcome-uncertain and its retained witness blocks a new transaction'
);
Db::forget_transaction_tracking();

$appliedThrowCommit = new DeleteGuardEvaluatorFakeWpdb([]);
$appliedThrowCommit->activeTransaction = '0';
$appliedThrowCommit->throwOnCommit = true;
$GLOBALS['wpdb'] = $appliedThrowCommit;
Db::start_repeatable_read('applied-throw commit start');
try {
    Db::commit('applied-throw commit');
    $appliedThrowCommitRefused = false;
} catch (Throwable $failure) {
    $appliedThrowCommitRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$check(
    $appliedThrowCommitRefused && $appliedThrowCommit->activeTransaction === '0',
    'COMMIT throwing after active-to-inactive remains outcome-uncertain instead of guessed committed'
);
Db::forget_transaction_tracking();

$notAppliedThrowCommit = new DeleteGuardEvaluatorFakeWpdb([]);
$notAppliedThrowCommit->activeTransaction = '0';
$notAppliedThrowCommit->applyCommit = false;
$notAppliedThrowCommit->throwOnCommit = true;
$GLOBALS['wpdb'] = $notAppliedThrowCommit;
Db::start_repeatable_read('not-applied throw commit start');
try {
    Db::commit('not-applied throw commit');
    $notAppliedThrowCommitRefused = false;
} catch (Throwable $failure) {
    $notAppliedThrowCommitRefused = $failure instanceof \WPrism\DatabaseMutationException;
}
$check(
    $notAppliedThrowCommitRefused && Db::transaction_active('not-applied throw commit remains active'),
    'COMMIT throwing while the original transaction remains active is rollback-recoverable'
);
Db::rollback('not-applied throw commit cleanup');

$truthyErrorCommit = new DeleteGuardEvaluatorFakeWpdb([]);
$truthyErrorCommit->activeTransaction = '0';
$truthyErrorCommit->commitLeavesError = true;
$GLOBALS['wpdb'] = $truthyErrorCommit;
Db::start_repeatable_read('truthy-error commit start');
try {
    Db::commit('truthy-error commit');
    $truthyErrorCommitRefused = false;
} catch (Throwable $failure) {
    $truthyErrorCommitRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$check(
    $truthyErrorCommitRefused && $truthyErrorCommit->activeTransaction === '0',
    'COMMIT truthy result plus driver error is not accepted from inactive state'
);
Db::forget_transaction_tracking();

$notAppliedCommit = new DeleteGuardEvaluatorFakeWpdb([]);
$notAppliedCommit->activeTransaction = '0';
$notAppliedCommit->commitResult = false;
$notAppliedCommit->applyCommit = false;
$GLOBALS['wpdb'] = $notAppliedCommit;
Db::forget_transaction_tracking();
Db::start_repeatable_read('not-applied commit start');
try {
    Db::commit('not-applied commit');
    $notAppliedCommitRefused = false;
} catch (Throwable $failure) {
    $notAppliedCommitRefused = $failure instanceof \WPrism\DatabaseMutationException;
}
$check(
    $notAppliedCommitRefused && Db::transaction_active('not-applied commit remains recoverable'),
    'COMMIT returning false while the original transaction remains active is rollback-recoverable'
);
Db::rollback('not-applied commit cleanup');

$appliedFalseRollback = new DeleteGuardEvaluatorFakeWpdb([]);
$appliedFalseRollback->activeTransaction = '0';
$appliedFalseRollback->rollbackResult = false;
$GLOBALS['wpdb'] = $appliedFalseRollback;
Db::forget_transaction_tracking();
Db::start_repeatable_read('applied-false rollback start');
Db::rollback('applied-false rollback');
$check(
    $appliedFalseRollback->activeTransaction === '0',
    'ROLLBACK returning false is terminal success only when the same connection proves inactive'
);

$appliedThrowRollback = new DeleteGuardEvaluatorFakeWpdb([]);
$appliedThrowRollback->activeTransaction = '0';
$appliedThrowRollback->throwOnRollback = true;
$GLOBALS['wpdb'] = $appliedThrowRollback;
Db::forget_transaction_tracking();
Db::start_repeatable_read('applied-throw rollback start');
Db::rollback('applied-throw rollback');
$check(
    $appliedThrowRollback->activeTransaction === '0',
    'ROLLBACK throwing after the same-connection inactive transition is still a confirmed rollback outcome'
);

$notAppliedRollback = new DeleteGuardEvaluatorFakeWpdb([]);
$notAppliedRollback->activeTransaction = '0';
$notAppliedRollback->rollbackResult = false;
$notAppliedRollback->applyRollback = false;
$GLOBALS['wpdb'] = $notAppliedRollback;
Db::forget_transaction_tracking();
Db::start_repeatable_read('not-applied rollback start');
try {
    Db::rollback('not-applied rollback');
    $notAppliedRollbackRefused = false;
} catch (Throwable $failure) {
    $notAppliedRollbackRefused = $failure instanceof \WPrism\DatabaseMutationException;
}
$check(
    $notAppliedRollbackRefused && $notAppliedRollback->activeTransaction === '1',
    'ROLLBACK returning false while still active remains a loud recovery failure'
);
Db::forget_transaction_tracking();

$reconnectedCommit = new DeleteGuardEvaluatorFakeWpdb([]);
$reconnectedCommit->activeTransaction = '0';
$reconnectedCommit->commitResult = false;
$reconnectedCommit->replaceConnectionOnCommit = true;
$GLOBALS['wpdb'] = $reconnectedCommit;
Db::start_repeatable_read('reconnected commit start');
try {
    Db::commit('reconnected commit');
    $reconnectedCommitRefused = false;
} catch (Throwable $failure) {
    $reconnectedCommitRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$check(
    $reconnectedCommitRefused,
    'connection replacement across COMMIT is recovery_required instead of guessed committed or rolled back'
);
Db::forget_transaction_tracking();

foreach ([1, 2, 3] as $probeStep) {
    $reconnectedState = new DeleteGuardEvaluatorFakeWpdb([]);
    $reconnectedState->activeTransaction = '0';
    $GLOBALS['wpdb'] = $reconnectedState;
    Db::start_repeatable_read("state-probe-$probeStep commit start");
    $reconnectedState->applyCommit = false;
    $reconnectedState->stateProbeStep = 0;
    $reconnectedState->replaceConnectionAtStateProbeStep = $probeStep;
    try {
        Db::commit("state-probe-$probeStep commit");
        $stateProbeRefused = false;
    } catch (Throwable $failure) {
        $stateProbeRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
    }
    $check(
        $stateProbeRefused,
        "connection replacement at transaction-state probe $probeStep cannot bless a truthy unapplied COMMIT"
    );
    Db::forget_transaction_tracking();
}

$prematureCommit = new DeleteGuardEvaluatorFakeWpdb([]);
$prematureCommit->activeTransaction = '0';
$GLOBALS['wpdb'] = $prematureCommit;
Db::start_repeatable_read('premature commit start');
$prematureCommit->activeTransaction = '0';
try {
    Db::commit('premature commit');
    $prematureCommitRefused = false;
} catch (Throwable $failure) {
    $prematureCommitRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$check(
    $prematureCommitRefused,
    'an intervening native COMMIT is detected before a second COMMIT or rollback participant can run'
);
Db::forget_transaction_tracking();

foreach (['0', '01', '1.0', '1junk', 1, false, null] as $activeValue) {
    $activeWpdb = new DeleteGuardEvaluatorFakeWpdb([]);
    $activeWpdb->activeTransaction = $activeValue;
    $GLOBALS['wpdb'] = $activeWpdb;
    try {
        DeleteGuardEvaluator::assert_active_transaction('owner-range locking');
        $activeShapeRefused = false;
    } catch (RuntimeException $e) {
        $activeShapeRefused = str_contains($e->getMessage(), 'requires an active transaction');
    }
    $check($activeShapeRefused, 'noncanonical transaction-state value ' . json_encode($activeValue) . ' fails closed');
}
$activeWpdb = new DeleteGuardEvaluatorFakeWpdb([]);
$activeWpdb->activeTransactionError = true;
$GLOBALS['wpdb'] = $activeWpdb;
try {
    DeleteGuardEvaluator::assert_active_transaction('owner-range locking');
    $activeErrorRefused = false;
} catch (RuntimeException $e) {
    $activeErrorRefused = str_contains($e->getMessage(), 'requires an active transaction');
}
$check($activeErrorRefused, 'canonical transaction-state value plus a driver error fails closed');

$referenceCalls = [];
$findings = DeleteGuardEvaluator::reference_findings(
    [
        [
            'table' => 'wp_postmeta',
            'column' => 'post_id',
            'reason' => 'declared post reference',
            'option_name_ref' => false,
        ],
        [
            'table' => 'wp_options',
            'column' => 'option_name',
            'option_name_ref' => true,
        ],
    ],
    static function (array $guard, bool $forUpdate) use (&$referenceCalls): array {
        $referenceCalls[] = [(string) $guard['table'], $forUpdate];
        if ($guard['table'] === 'wp_postmeta') {
            return [
                'count' => 2,
                'error' => null,
                'rows' => ['wp_postmeta.post_id=7', 'wp_postmeta.post_id=8'],
                'witness' => 'postmeta-witness',
            ];
        }
        return ['count' => 0, 'error' => null, 'rows' => [], 'witness' => 'options-witness'];
    },
    static fn(string $table): bool => $table === 'wp_postmeta',
    true
);
$check(
    $referenceCalls === [['wp_postmeta', true], ['wp_options', true]]
        && $findings['blocks'] === ['declared post reference — 2 row(s)']
        && $findings['guard_refs'] === [[
            'table' => 'wp_postmeta',
            'rows' => ['wp_postmeta.post_id=7', 'wp_postmeta.post_id=8'],
            'repairable' => true,
            'option_name_ref' => false,
        ]]
        && $findings['guard_witnesses'] === [
            '0' => 'postmeta-witness',
            '1' => 'options-witness',
        ]
        && $findings['non_forceable_blocks'] === [],
    'reference evaluator collects deterministic blocks and repair witnesses through narrow callbacks'
);

$errorFindings = DeleteGuardEvaluator::reference_findings(
    [['table' => 'wp_postmeta', 'column' => 'post_id']],
    static fn(array $guard, bool $forUpdate): array => [
        'count' => 0,
        'error' => 'simulated reference query failure',
        'rows' => [],
    ],
    static fn(string $table): bool => true
);
$check(
    $errorFindings === [
        'blocks' => ['simulated reference query failure'],
        'non_forceable_blocks' => [],
        'guard_refs' => [],
        'guard_witnesses' => ['0' => ''],
    ],
    'reference evaluator preserves fail-closed query errors and empty witnesses without inventing warning rows'
);

$nonForceableErrorFindings = DeleteGuardEvaluator::reference_findings(
    [[
        'table' => 'wp_wc_reserved_stock',
        'column' => 'product_id',
        'forceable' => false,
    ]],
    static fn(array $guard, bool $forUpdate): array => [
        'count' => 0,
        'error' => 'simulated unreadable Woo runtime guard',
        'rows' => [],
    ],
    static fn(string $table): bool => false
);
$check(
    $nonForceableErrorFindings['blocks'] === ['simulated unreadable Woo runtime guard']
        && $nonForceableErrorFindings['non_forceable_blocks'] === ['simulated unreadable Woo runtime guard'],
    'a failed non-forceable guard remains non-forceable instead of becoming force-authorizable'
);

$planGuardCalls = [];
$annotatedPlan = DeleteGuardEvaluator::annotate_plan_guard_findings(
    [
        'delete' => [['uuid' => 'delete-target']],
        'delete_conflict' => [[
            'uuid' => 'conflict-target',
            'conflict_view' => [
                'choices' => [
                    ['id' => 'reconcile_in_repository'],
                    ['id' => 'apply_repository'],
                ],
            ],
        ]],
    ],
    [
        'delete-target' => ['guards' => [['table' => 'wp_postmeta', 'column' => 'post_id']]],
        'conflict-target' => ['guards' => [['table' => 'wp_options', 'column' => 'option_name']]],
    ],
    static function (array $guard, string $targetUuid, bool $forUpdate) use (&$planGuardCalls): array {
        $planGuardCalls[] = [$targetUuid, $guard['table'], $forUpdate];
        if ($targetUuid === 'delete-target') {
            return [
                'count' => 1,
                'error' => null,
                'rows' => ['wp_postmeta.post_id=7'],
                'witness' => 'delete-witness',
            ];
        }
        return [
            'count' => 0,
            'error' => 'simulated plan guard failure',
            'rows' => [],
            'witness' => 'conflict-witness',
        ];
    },
    static fn(string $table): bool => $table === 'wp_postmeta',
    'empty-witness'
);
$check(
    $planGuardCalls === [
        ['delete-target', 'wp_postmeta', false],
        ['conflict-target', 'wp_options', false],
    ]
        && $annotatedPlan['delete'][0]['blocked'] === 'referenced by wp_postmeta.post_id — 1 row(s)'
        && $annotatedPlan['delete'][0]['guard_refs'] === [[
            'table' => 'wp_postmeta',
            'rows' => ['wp_postmeta.post_id=7'],
            'repairable' => true,
            'option_name_ref' => false,
        ]]
        && $annotatedPlan['delete'][0]['guard_witnesses'] === ['0' => 'delete-witness']
        && $annotatedPlan['delete_conflict'][0]['blocked'] === 'simulated plan guard failure'
        && $annotatedPlan['delete_conflict'][0]['conflict_view']['choices'] === [
            ['id' => 'reconcile_in_repository'],
        ]
        && $annotatedPlan['delete_conflict'][0]['guard_witnesses'] === ['0' => 'conflict-witness'],
    'plan guard evaluator annotates both delete buckets and suppresses unsafe conflict choices'
);

$revalidationCalls = [];
DeleteGuardEvaluator::assert_revalidated_witnesses(
    [[
        'type' => 'post',
        'uuid' => 'locked-target',
        'guard_witnesses' => ['0' => 'locked-witness'],
    ]],
    static fn(array $row): array => [['table' => 'wp_postmeta', 'column' => 'post_id']],
    static function (array $guard, string $targetUuid, bool $forUpdate) use (&$revalidationCalls): array {
        $revalidationCalls[] = [$targetUuid, $guard['table'], $forUpdate];
        return ['error' => null, 'witness' => 'locked-witness'];
    }
);
$check(
    $revalidationCalls === [['locked-target', 'wp_postmeta', true]],
    'locked witness evaluator re-reads every guard with the FOR UPDATE callback contract'
);

$lockFailureRefused = false;
try {
    DeleteGuardEvaluator::assert_revalidated_witnesses(
        [['type' => 'post', 'uuid' => 'locked-target', 'guard_witnesses' => ['0' => 'locked-witness']]],
        static fn(array $row): array => [['table' => 'wp_postmeta', 'column' => 'post_id']],
        static fn(array $guard, string $targetUuid, bool $forUpdate): array => [
            'error' => 'simulated lock query failure',
            'witness' => '',
        ]
    );
} catch (RuntimeException $e) {
    $lockFailureRefused = str_contains($e->getMessage(), 'deletion guard lock refused for post locked-target')
        && str_contains($e->getMessage(), 'simulated lock query failure');
}
$check($lockFailureRefused, 'locked witness evaluator fails closed on a reference query error');

$changedWitnessRefused = false;
try {
    DeleteGuardEvaluator::assert_revalidated_witnesses(
        [['type' => 'post', 'uuid' => 'locked-target', 'guard_witnesses' => ['0' => 'locked-witness']]],
        static fn(array $row): array => [['table' => 'wp_postmeta', 'column' => 'post_id']],
        static fn(array $guard, string $targetUuid, bool $forUpdate): array => [
            'error' => null,
            'witness' => 'changed-witness',
        ]
    );
} catch (RuntimeException $e) {
    $changedWitnessRefused = str_contains($e->getMessage(), 'witness changed after planning')
        && str_contains($e->getMessage(), 'no mutation attempted');
}
$check($changedWitnessRefused, 'locked witness evaluator refuses a changed or stale witness before mutation');

$finalRecheckCalls = [];
$finalRecheckFindings = DeleteGuardEvaluator::final_recheck_findings(
    ['type' => 'post', 'uuid' => 'final-target'],
    [['table' => 'wp_postmeta', 'column' => 'post_id', 'reason' => 'grouped child reference']],
    static function (array $guard, bool $forUpdate) use (&$finalRecheckCalls): array {
        $finalRecheckCalls[] = $forUpdate;
        return [
            'count' => 1,
            'error' => null,
            'rows' => ['wp_postmeta.meta_id=7'],
            'witness' => 'final-witness',
        ];
    },
    static fn(string $table): bool => $table === 'wp_postmeta',
    true,
    true
);
$check(
    $finalRecheckCalls === [true]
        && $finalRecheckFindings['blocks'] === ['grouped child reference — 1 row(s)']
        && $finalRecheckFindings['guard_refs'] === [[
            'table' => 'wp_postmeta',
            'rows' => ['wp_postmeta.meta_id=7'],
            'repairable' => true,
            'option_name_ref' => false,
        ]],
    'final recheck evaluator preserves locked callback mode, block reason, and forced-warning witnesses'
);

$finalRecheckRefused = false;
try {
    DeleteGuardEvaluator::final_recheck_findings(
        ['type' => 'post', 'uuid' => 'final-target'],
        [['table' => 'wp_postmeta', 'column' => 'post_id']],
        static fn(array $guard, bool $forUpdate): array => [
            'count' => 1,
            'error' => null,
            'rows' => ['wp_postmeta.meta_id=7'],
        ],
        static fn(string $table): bool => false,
        false,
        true
    );
} catch (RuntimeException $e) {
    $finalRecheckRefused = str_contains($e->getMessage(), 'delete guard changed before mutation for post final-target')
        && str_contains($e->getMessage(), '1 row(s)');
}
$check($finalRecheckRefused, 'final recheck evaluator refuses an unforced changed guard before mutation');

$forcedNonForceableErrorRefused = false;
try {
    DeleteGuardEvaluator::final_recheck_findings(
        ['type' => 'post', 'uuid' => 'final-runtime-target'],
        [[
            'table' => 'wp_wc_reserved_stock',
            'column' => 'product_id',
            'forceable' => false,
        ]],
        static fn(array $guard, bool $forUpdate): array => [
            'count' => 0,
            'error' => 'simulated locked Woo runtime guard read failure',
            'rows' => [],
        ],
        static fn(string $table): bool => false,
        true,
        true
    );
} catch (RuntimeException $e) {
    $forcedNonForceableErrorRefused = str_contains($e->getMessage(), 'simulated locked Woo runtime guard read failure')
        && str_contains($e->getMessage(), 'not forceable');
}
$check($forcedNonForceableErrorRefused,
    'force cannot cross a failed non-forceable guard at the final locked recheck');

$cleanFinalRecheck = DeleteGuardEvaluator::final_recheck_findings(
    ['type' => 'post', 'uuid' => 'clean-target'],
    [['table' => 'wp_postmeta', 'column' => 'post_id']],
    static fn(array $guard, bool $forUpdate): array => [
        'count' => 0,
        'error' => null,
        'rows' => [],
    ],
    static fn(string $table): bool => true,
    false,
    true
);
$check(
    $cleanFinalRecheck['blocks'] === [] && $cleanFinalRecheck['guard_refs'] === [],
    'final recheck evaluator leaves a clean locked guard unblocked'
);

$evaluator = new ReflectionClass(DeleteGuardEvaluator::class);
$check(
    (new ReflectionMethod(DeleteGuardEvaluator::class, 'guard_table_topology'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'guard_table_topology'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'lock_index'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'lock_index'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'full_width_lock_index'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'full_width_lock_index'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_innodb_tables'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_innodb_tables'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_transaction_isolation'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_transaction_isolation'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'reference_findings'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'reference_findings'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'annotate_plan_guard_findings'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'annotate_plan_guard_findings'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_revalidated_witnesses'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'assert_revalidated_witnesses'))->isStatic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'final_recheck_findings'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'final_recheck_findings'))->isStatic()
        && $evaluator->getConstructor() === null,
    'evaluator exposes dependency-free static topology, index, storage-engine, isolation, reference, plan, witness, and recheck contracts'
);

$applySource = file_get_contents(__DIR__ . '/../../../../agent/src/Delete/DeleteGuardLockCoordinator.php');
$planBuilderSource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/ApplyPlanBuilder.php');
$scannerSource = file_get_contents(__DIR__ . '/../../../../agent/src/Delete/DeleteGuardReferenceScanner.php');
$engineFacade = substr(
    $applySource,
    strpos($applySource, 'public function assert_guard_engines('),
    strpos($applySource, 'public function assert_lock_isolation(')
        - strpos($applySource, 'public function assert_guard_engines(')
);
$check(
    str_contains($applySource, "require_once __DIR__ . '/DeleteGuardEvaluator.php';")
        && str_contains($applySource, "require_once __DIR__ . '/DeleteGuardReferenceScanner.php';")
        && str_contains($scannerSource, "require_once __DIR__ . '/DeleteGuardEvaluator.php';")
        && substr_count($scannerSource, 'DeleteGuardEvaluator::lock_index(') === 3
        && str_contains($engineFacade, 'DeleteGuardEvaluator::guard_table_topology(')
        && str_contains($engineFacade, 'DeleteGuardEvaluator::assert_innodb_tables($presentTables);')
        && !str_contains($engineFacade, 'information_schema.TABLES'),
    'the scanner and lock coordinator delegate exact topology, index, and storage-engine decisions to the evaluator'
);
$commitBoundary = substr(
    $applySource,
    strpos($applySource, 'public function assert_writer_exclusion_commit_boundary('),
    strpos($applySource, 'public function end_writer_exclusion_transaction(')
        - strpos($applySource, 'public function assert_writer_exclusion_commit_boundary(')
);
$check(
    str_contains($commitBoundary, 'DeleteGuardEvaluator::guard_table_topology(')
        && str_contains($commitBoundary, "if (\$state !== 'absent')")
        && strpos($commitBoundary, 'guard_table_topology(')
            < strpos($commitBoundary, '$this->writerExclusion->assert_commit_boundary();'),
    'the final pre-COMMIT boundary re-censuses every absence-means-empty table before accepting writer exclusion'
);
$check(
    !str_contains($applySource, 'private function guard_lock_index(')
        && !str_contains($scannerSource, 'private function guard_lock_index('),
    'the lock boundary retains no duplicate index evaluator'
);
$isolationFacade = substr(
    $applySource,
    strpos($applySource, 'public function assert_lock_isolation('),
    strpos($applySource, 'public function recheck(')
        - strpos($applySource, 'public function assert_lock_isolation(')
);
$check(
    str_contains($isolationFacade, 'DeleteGuardEvaluator::assert_transaction_isolation();')
        && !str_contains($isolationFacade, 'SELECT @@transaction_isolation')
        && !str_contains($isolationFacade, 'SELECT @@tx_isolation'),
    'DeleteGuardLockCoordinator keeps a thin isolation boundary and no duplicate server-variable proof'
);
$recheckFacade = substr(
    $applySource,
    strpos($applySource, 'public function recheck('),
    strpos($applySource, 'public static function append_forced_warnings(')
        - strpos($applySource, 'public function recheck(')
);
$check(
    str_contains($recheckFacade, 'DeleteGuardEvaluator::final_recheck_findings(')
        && str_contains($recheckFacade, 'self::append_forced_warnings(')
        && !str_contains($recheckFacade, 'DeleteGuardEvaluator::reference_findings(')
        && !str_contains($recheckFacade, 'foreach ($capability[\'guards\']'),
    'lock coordinator delegates final guard findings and retains policy, SQL, and warning orchestration'
);
$lockFacade = substr(
    $applySource,
    strpos($applySource, 'public function lock_and_revalidate('),
    strpos($applySource, 'public function assert_guard_engines(')
        - strpos($applySource, 'public function lock_and_revalidate(')
);
$check(
    str_contains($lockFacade, 'DeleteGuardEvaluator::assert_revalidated_witnesses(')
        && !str_contains($lockFacade, 'foreach ($deleteWork'),
    'DeleteGuardLockCoordinator keeps target-fact callbacks while the evaluator owns witness revalidation'
);
$planGuardSection = substr(
    $planBuilderSource,
    strpos($planBuilderSource, '// Runtime reverse references are target facts'),
    strpos($planBuilderSource, '// docs/code-half.md')
        - strpos($planBuilderSource, '// Runtime reverse references are target facts')
);
$check(
    str_contains($planGuardSection, 'DeleteGuardEvaluator::annotate_plan_guard_findings(')
        && !str_contains($planGuardSection, 'foreach ($deletionCaps')
        && !str_contains($planGuardSection, '$blocks = [];')
        && !str_contains($planGuardSection, '$guardRefs = [];'),
    'Apply delegates plan-time guard annotation and retains only the target-fact callback boundary'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

echo "\nall DeleteGuardEvaluator checks passed\n";
