<?php
/**
 * Offline characterization for DUO-3347's shared authored-field layer.
 *
 * The low-level meta/option writes are driven through the extracted
 * ApplyFieldMaterializer against a deterministic fake wpdb. The source checks
 * keep Apply's compatibility methods as delegates, while the existing
 * regress_term_meta.php continues to exercise policy-aware reconciliation
 * through the real Apply facade.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!function_exists('maybe_serialize')) {
    function maybe_serialize($value) {
        return is_array($value) || is_object($value) || $value === null || is_bool($value)
            ? serialize($value)
            : $value;
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value): string { return rtrim((string) $value, '/\\'); }
}

$cacheEvents = [];
$cacheDeleteResult = true;
function wp_cache_delete($key, $group = ''): bool {
    global $cacheEvents, $cacheDeleteResult;
    $cacheEvents[] = [(string) $group, (string) $key];
    return $cacheDeleteResult;
}

require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';

use Duo\ApplyFieldMaterializer;

final class ApplyFieldMaterializerFakeWpdb {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';
    public string $options = 'wp_options';
    public string $last_error = '';
    public int $insert_id = 0;
    public bool $inTransaction = true;
    public string $isolation = 'REPEATABLE-READ';
    public bool $savepointExists = false;
    public mixed $forcedMetaRead = null;
    public bool $forceMetaIdentity = false;
    public mixed $forcedMetaIdentity = null;
    public bool $metaLookupError = false;
    /** @var list<string> */
    public array $queries = [];
    /** @var list<array<string,mixed>> */
    public array $postMetaRows = [];
    /** @var list<array<string,mixed>> */
    public array $termMetaRows = [];
    /** @var list<array<string,mixed>> */
    public array $optionRows = [];

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if (preg_match('/^SAVEPOINT `duo_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            $this->savepointExists = true;
            return 1;
        }
        if (preg_match('/^RELEASE SAVEPOINT `duo_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            if (!$this->savepointExists) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->savepointExists = false;
            return 1;
        }
        throw new RuntimeException("unrecognized query: $sql");
    }

    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (trim($sql) === 'SELECT @@in_transaction') {
            return $this->inTransaction ? '1' : '0';
        }
        if (trim($sql) === 'SELECT @@transaction_isolation') {
            return $this->isolation;
        }
        if (trim($sql) === 'SELECT 1 FROM `wp_termmeta` LIMIT 1') {
            return '1';
        }
        if (trim($sql) === 'SELECT 1 FROM `wp_options` LIMIT 1') {
            return '1';
        }
        if (str_contains($sql, 'meta_key =') && $this->metaLookupError) {
            $this->last_error = 'simulated meta identity lookup failure';
            return null;
        }
        if (str_contains($sql, 'meta_key =') && $this->forceMetaIdentity) {
            return $this->forcedMetaIdentity;
        }
        if (str_contains($sql, 'FROM `wp_postmeta`')) {
            return $this->findMetaId($this->postMetaRows, $sql, 'post_id');
        }
        if (str_contains($sql, 'FROM `wp_termmeta`')) {
            return $this->findMetaId($this->termMetaRows, $sql, 'term_id');
        }
        if (str_contains($sql, 'FROM wp_options')) {
            preg_match("/option_name = '((?:''|[^'])*)'/", $sql, $match);
            $name = str_replace("''", "'", (string) ($match[1] ?? ''));
            foreach ($this->optionRows as $row) {
                if ((string) $row['option_name'] === $name) {
                    return $row['option_id'];
                }
            }
            return null;
        }
        throw new RuntimeException("unrecognized get_var query: $sql");
    }

    public function get_results(string $sql, mixed $mode): mixed {
        $this->queries[] = $sql;
        if ($mode !== ARRAY_A) {
            throw new RuntimeException('fixture expected ARRAY_A');
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            return [
                ['TABLE_NAME' => $this->options, 'ENGINE' => 'InnoDB'],
                ['TABLE_NAME' => $this->termmeta, 'ENGINE' => 'InnoDB'],
            ];
        }
        if (str_starts_with($sql, 'SHOW INDEX FROM `wp_termmeta`')) {
            return [[
                'Key_name' => 'term_id',
                'Seq_in_index' => '1',
                'Column_name' => 'term_id',
                'Sub_part' => null,
                'Non_unique' => '1',
                'Index_type' => 'BTREE',
            ]];
        }
        if (str_starts_with($sql, 'SHOW INDEX FROM `wp_options`')) {
            return [[
                'Key_name' => 'option_name',
                'Seq_in_index' => '1',
                'Column_name' => 'option_name',
                'Sub_part' => null,
                'Non_unique' => '0',
                'Index_type' => 'BTREE',
            ]];
        }
        if (str_contains($sql, 'FROM wp_options FORCE INDEX (`option_name`)')) {
            preg_match("/option_name = '((?:''|[^'])*)'/", $sql, $match);
            $name = str_replace("''", "'", (string) ($match[1] ?? ''));
            $rows = array_values(array_filter(
                $this->optionRows,
                static fn(array $row): bool => strcasecmp((string) $row['option_name'], $name) === 0
            ));
            usort($rows, static fn(array $a, array $b): int => (int) $a['option_id'] <=> (int) $b['option_id']);
            $rows = array_slice($rows, 0, 2);
            if (str_contains($sql, 'option_value_bytes')) {
                return array_map(static fn(array $row): array => [
                    'option_name' => (string) $row['option_name'],
                    'option_value_bytes' => (string) strlen((string) $row['option_value']),
                    'option_value_sha256' => hash('sha256', (string) $row['option_value']),
                    'autoload_bytes' => (string) strlen((string) $row['autoload']),
                    'autoload_sha256' => hash('sha256', (string) $row['autoload']),
                ], $rows);
            }
            return array_map(static fn(array $row): array => [
                'option_name' => (string) $row['option_name'],
                'option_value' => (string) $row['option_value'],
                'autoload' => (string) $row['autoload'],
            ], $rows);
        }
        if (str_contains($sql, 'FROM `wp_termmeta` FORCE INDEX (`term_id`)')) {
            if (str_contains($sql, 'AS meta_id, meta_key') && str_contains($sql, 'AND meta_key =')) {
                preg_match('/`term_id` = ([0-9]+)/', $sql, $ownerMatch);
                preg_match("/meta_key = '((?:''|[^'])*)'/", $sql, $keyMatch);
                $termId = (int) ($ownerMatch[1] ?? 0);
                $key = str_replace("''", "'", (string) ($keyMatch[1] ?? ''));
                $rows = array_values(array_filter(
                    $this->termMetaRows,
                    static fn(array $row): bool => (int) $row['term_id'] === $termId
                        && strcasecmp((string) $row['meta_key'], $key) === 0
                ));
                usort($rows, static fn(array $a, array $b): int => (int) $a['meta_id'] <=> (int) $b['meta_id']);
                return array_map(static fn(array $row): array => [
                    'meta_id' => (string) $row['meta_id'],
                    'meta_key' => (string) $row['meta_key'],
                ], $rows);
            }
            $preflight = str_contains($sql, 'OCTET_LENGTH(meta_key)');
            if ($this->forcedMetaRead !== null) {
                if ($this->forcedMetaRead === 'false') return false;
                if ($this->forcedMetaRead === 'null') return null;
                if ($this->forcedMetaRead === 'error') {
                    $this->last_error = 'simulated locked term-meta read failure';
                    return [];
                }
                if ($this->forcedMetaRead === 'oversize') {
                    return array_fill(0, \Duo\MetaRows::MAX_OWNER_ROWS + 1, [
                        'meta_id' => '1', 'meta_key_bytes' => '7', 'meta_value_bytes' => '5',
                        'meta_key_sha256' => hash('sha256', 'catalog'),
                        'meta_value_sha256' => hash('sha256', 'value'),
                    ]);
                }
                if ($this->forcedMetaRead === 'oversized-value') {
                    return [[
                        'meta_id' => '1',
                        'meta_key_bytes' => '7',
                        'meta_value_bytes' => (string) (\Duo\MetaRows::MAX_META_VALUE_BYTES + 1),
                        'meta_key_sha256' => hash('sha256', 'catalog'),
                        'meta_value_sha256' => hash('sha256', 'value'),
                    ]];
                }
                return $this->forcedMetaRead;
            }
            preg_match('/`term_id` = ([0-9]+)/', $sql, $match);
            $termId = (int) ($match[1] ?? 0);
            $rows = array_values(array_filter(
                $this->termMetaRows,
                static fn(array $row): bool => (int) $row['term_id'] === $termId
            ));
            usort($rows, static fn(array $a, array $b): int => (int) $a['meta_id'] <=> (int) $b['meta_id']);
            return array_map(static fn(array $row): array => $preflight ? [
                'meta_id' => (string) $row['meta_id'],
                'meta_key_bytes' => (string) strlen((string) $row['meta_key']),
                'meta_value_bytes' => $row['meta_value'] === null
                    ? null
                    : (string) strlen((string) $row['meta_value']),
                'meta_key_sha256' => hash('sha256', (string) $row['meta_key']),
                'meta_value_sha256' => $row['meta_value'] === null
                    ? null
                    : hash('sha256', (string) $row['meta_value']),
            ] : [
                'meta_id' => (string) $row['meta_id'],
                'meta_key' => $row['meta_key'],
                'meta_value' => $row['meta_value'],
            ], $rows);
        }
        throw new RuntimeException("unrecognized get_results query: $sql");
    }

    private function findMetaId(array $rows, string $sql, string $foreignKey): ?string {
        preg_match("/`?$foreignKey`? = ([0-9]+)/", $sql, $foreignMatch);
        preg_match("/meta_key = '((?:''|[^'])*)'/", $sql, $keyMatch);
        $foreignId = (int) ($foreignMatch[1] ?? 0);
        $key = str_replace("''", "'", (string) ($keyMatch[1] ?? ''));
        foreach ($rows as $row) {
            if ((int) $row[$foreignKey] === $foreignId && strcasecmp((string) $row['meta_key'], $key) === 0) {
                return (string) $row['meta_id'];
            }
        }
        return null;
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null): int {
        $rows =& $this->rowsFor($table);
        foreach ($rows as &$row) {
            $matches = true;
            foreach ($where as $key => $value) {
                if (($row[$key] ?? null) != $value) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $row = array_merge($row, $data);
                return 1;
            }
        }
        return 0;
    }

    public function insert(string $table, array $data, $format = null): int {
        $rows =& $this->rowsFor($table);
        if (str_contains($table, 'meta')) {
            $ids = array_map('intval', array_column($rows, 'meta_id'));
            $this->insert_id = $ids ? max($ids) + 1 : 1;
            $rows[] = ['meta_id' => $this->insert_id] + $data;
        } else {
            $ids = array_map('intval', array_column($rows, 'option_id'));
            $this->insert_id = $ids ? max($ids) + 1 : 1;
            $rows[] = ['option_id' => $this->insert_id] + $data;
        }
        return 1;
    }

    public function delete(string $table, array $where, $whereFormat = null): int {
        $rows =& $this->rowsFor($table);
        $before = count($rows);
        $rows = array_values(array_filter($rows, static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) {
                if (($row[$key] ?? null) != $value) {
                    return true;
                }
            }
            return false;
        }));
        return $before - count($rows);
    }

    /** @return list<array<string,mixed>> */
    private function &rowsFor(string $table): array {
        if ($table === $this->postmeta) {
            return $this->postMetaRows;
        }
        if ($table === $this->termmeta) {
            return $this->termMetaRows;
        }
        if ($table === $this->options) {
            return $this->optionRows;
        }
        throw new RuntimeException("unrecognized table: $table");
    }
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$applySource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/Apply.php');
$materializerSource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php');
$check($applySource !== false && $materializerSource !== false, 'source files are readable');
$check(str_contains($materializerSource, 'final class ApplyFieldMaterializer'), 'new collaborator owns the field layer');
$check(substr_count($materializerSource, 'function reconcile_authored_meta(') === 1,
    'postmeta reconciliation has one implementation in the collaborator');
$check(substr_count($materializerSource, 'function reconcile_authored_term_meta(') === 1,
    'termmeta reconciliation has one implementation in the collaborator');
// reconcile_authored_meta()'s only caller, finalize_post(), itself moved to
// PostMaterializer in DUO-3347 slice 11 -- the new
// PostMaterializer::finalize_post() calls
// ApplyFieldMaterializer::reconcile_authored_meta() directly (calling back
// through Apply's own facade would be circular), so Apply's own facade is
// now genuinely dead code and was removed entirely rather than kept, the
// same "no other caller, no facade needed" treatment TermMaterializer's
// encode_description()/reconcile_term_relationships() already established
// (slice 6).
$check(!str_contains($applySource, 'private function reconcile_authored_meta('),
    'Apply no longer defines reconcile_authored_meta() at all (moved to PostMaterializer\'s own call site, no facade needed -- it had no other caller)');
$check(!str_contains($applySource, 'function reconcile_authored_term_meta('),
    'Apply no longer keeps a dead termmeta compatibility facade');

$wpdb = new ApplyFieldMaterializerFakeWpdb();
$materializer = (new ReflectionClass(ApplyFieldMaterializer::class))->newInstanceWithoutConstructor();

$wpdb->postMetaRows = [[
    'meta_id' => 7, 'post_id' => 19, 'meta_key' => 'owned', 'meta_value' => 'before',
]];
$materializer->upsert_meta($wpdb->postmeta, 'post_id', 19, 'owned', 'after', 'test update');
$check($wpdb->postMetaRows[0]['meta_value'] === 'after', 'upsert_meta updates the existing row');
$materializer->upsert_meta($wpdb->postmeta, 'post_id', 19, 'nullable', null, 'test insert');
$inserted = array_values(array_filter($wpdb->postMetaRows, static fn(array $row): bool => $row['meta_key'] === 'nullable'));
$check(count($inserted) === 1 && $inserted[0]['meta_value'] === null, 'upsert_meta preserves a real SQL NULL on insert');

$termPolicy = new \Duo\Policy();
$termPolicy->manifests = [[
    'name' => 'term-meta-fixture',
    'interpreter' => 'nullable-fixture',
    'term_meta' => [
        'catalog' => ['class' => 'authored', 'plain_data' => true],
        'new_catalog' => ['class' => 'authored'],
        'runtime_neighbor' => ['class' => 'runtime'],
    ],
]];
$nullableInterpreter = new class {
    public function post_meta_rule(string $key, array $flat): ?array { return null; }
    public function term_meta_rule(string $key, array $flat): ?array {
        if ($key !== 'nullable_owned') {
            return null;
        }
        return array_key_exists('nullable_marker', $flat) && $flat['nullable_marker'] === null
            ? ['class' => 'authored']
            : ['class' => 'runtime'];
    }
};
$interpreterInstances = new ReflectionProperty(\Duo\Policy::class, 'interpreterInstances');
$interpreterInstances->setValue($termPolicy, ['nullable-fixture' => $nullableInterpreter]);
$termTokens = new \Duo\Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$termMaterializer = new ApplyFieldMaterializer($termPolicy, $termTokens);
$termMaterializer->begin_authored_transaction();
\Duo\CacheInvalidationTransaction::begin();
$wpdb->termMetaRows = [[
    'meta_id' => 8,
    'term_id' => 31,
    'meta_key' => 'CATALOG',
    'meta_value' => 'target-owned-case-alias',
], [
    'meta_id' => 1,
    'term_id' => 31,
    'meta_key' => 'catalog',
    'meta_value' => serialize([['Hello', 'Stale']]),
], [
    'meta_id' => 2,
    'term_id' => 31,
    'meta_key' => 'catalog',
    'meta_value' => serialize([['Duplicate', 'Remove']]),
], [
    'meta_id' => 3,
    'term_id' => 31,
    'meta_key' => 'runtime_neighbor',
    'meta_value' => 'preserve',
], [
    'meta_id' => 4,
    'term_id' => 31,
    'meta_key' => 'nullable_marker',
    'meta_value' => null,
], [
    'meta_id' => 5,
    'term_id' => 31,
    'meta_key' => 'nullable_marker',
    'meta_value' => 'later-must-not-win',
], [
    'meta_id' => 6,
    'term_id' => 31,
    'meta_key' => 'nullable_owned',
    'meta_value' => 'remove-through-first-null-context',
], [
    'meta_id' => 7,
    'term_id' => 31,
    'meta_key' => 'NEW_CATALOG',
    'meta_value' => 'preserve-collation-alias',
], [
    'meta_id' => 20,
    'term_id' => 32,
    'meta_key' => '_duo_uuid',
    'meta_value' => '11111111-1111-7111-8111-111111111111',
], [
    'meta_id' => 21,
    'term_id' => 32,
    'meta_key' => '_DUO_UUID',
    'meta_value' => '11111111-1111-7111-8111-111111111111',
]];
$identityLock = $termMaterializer->meta_owner_range_lock(
    $wpdb->termmeta,
    'term_id',
    'exact identity alias regression'
);
try {
    $identityLock->exact_key_rows(32, '_duo_uuid');
    $lockedAliasRefused = false;
} catch (Throwable $failure) {
    $lockedAliasRefused = str_contains($failure->getMessage(), 'collation-equal non-byte-exact');
}
$check($lockedAliasRefused, 'locked metadata exact-key lookup refuses a collation-equal alias');
$wpdb->termMetaRows = array_values(array_filter(
    $wpdb->termMetaRows,
    static fn(array $row): bool => !((int) $row['term_id'] === 32 && $row['meta_key'] === '_DUO_UUID')
));
$exactIdentityRows = $identityLock->exact_key_rows(32, '_duo_uuid');
$check(
    count($exactIdentityRows) === 1
        && ($exactIdentityRows[0]['meta_value'] ?? null) === '11111111-1111-7111-8111-111111111111',
    'locked metadata exact-key lookup binds one byte-exact row to its complete owner-range witness'
);
$cacheEvents = [];
$cacheDeleteResult = false;
$termMaterializer->reconcile_authored_term_meta(31, [
    'catalog' => [['Hello', 'Bonjour']],
    'new_catalog' => 'exact-new',
]);
$catalogRows = array_values(array_filter(
    $wpdb->termMetaRows,
    static fn(array $row): bool => $row['meta_key'] === 'catalog'
));
$runtimeRows = array_values(array_filter(
    $wpdb->termMetaRows,
    static fn(array $row): bool => $row['meta_key'] === 'runtime_neighbor'
));
$nullableOwnedRows = array_values(array_filter(
    $wpdb->termMetaRows,
    static fn(array $row): bool => $row['meta_key'] === 'nullable_owned'
));
$aliasRows = array_values(array_filter(
    $wpdb->termMetaRows,
    static fn(array $row): bool => in_array($row['meta_key'], ['CATALOG', 'NEW_CATALOG'], true)
));
$newExactRows = array_values(array_filter(
    $wpdb->termMetaRows,
    static fn(array $row): bool => $row['meta_key'] === 'new_catalog'
));
$check(
    count($catalogRows) === 1
        && $catalogRows[0]['meta_value'] === serialize([['Hello', 'Bonjour']])
        && count($runtimeRows) === 1
        && $runtimeRows[0]['meta_value'] === 'preserve'
        && $nullableOwnedRows === []
        && array_column($aliasRows, 'meta_value') === [
            'target-owned-case-alias', 'preserve-collation-alias',
        ]
        && count($newExactRows) === 1
        && $newExactRows[0]['meta_value'] === 'exact-new',
    'term-meta product path deduplicates exact authored rows, preserves collation-equal aliases, inserts a missing exact key, and keeps SQL NULL as first context'
);
$check(
    count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'FORCE INDEX (`term_id`)')
            && str_contains($sql, '`term_id` = 31')
            && str_contains($sql, 'LIMIT 100001 FOR UPDATE')
    )) === 2
        && count(array_filter(
            $wpdb->queries,
            static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(meta_key)')
                && str_contains($sql, '`term_id` = 31')
        )) === 1,
    'term-meta product path locks a compact size witness then its full owner range and terminal gap'
);
$check(
    $cacheEvents === [['term_meta', '31']],
    'term-meta reconciliation purges the same-process WordPress cache while accepting an already-absent cache key'
);

foreach (['false', 'null', 'error', 'oversize', 'oversized-value'] as $failureMode) {
    $beforeRows = $wpdb->termMetaRows;
    $beforeFullReads = count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'meta_key, meta_value')
    ));
    $wpdb->forcedMetaRead = $failureMode;
    $wpdb->last_error = 'stale prior driver error';
    try {
        $termMaterializer->reconcile_authored_term_meta(31, ['catalog' => [['New', 'Value']]]);
        $lockedReadRefused = false;
    } catch (Throwable $failure) {
        $lockedReadRefused = str_contains(
            $failure->getMessage(),
            match ($failureMode) {
                'oversize' => 'bounded owner-row limit',
                'oversized-value' => 'size preflight found an oversized value',
                default => 'checked metadata size preflight failed',
            }
        );
    }
    $check(
        $lockedReadRefused && $wpdb->termMetaRows === $beforeRows,
        "term-meta $failureMode locked-read failure performs no mutation"
    );
    if ($failureMode === 'oversized-value') {
        $check(
            count(array_filter(
                $wpdb->queries,
                static fn(string $sql): bool => str_contains($sql, 'meta_key, meta_value')
            )) === $beforeFullReads,
            'term-meta oversized LONGTEXT refuses before a full-value transfer'
        );
    }
}
$wpdb->forcedMetaRead = [[
    'meta_id' => '1',
    'meta_key' => ['malformed'],
    'meta_value' => 'value',
]];
$wpdb->last_error = '';
try {
    $termMaterializer->reconcile_authored_term_meta(31, ['catalog' => []]);
    $malformedTermMetaRefused = false;
} catch (Throwable $failure) {
    $malformedTermMetaRefused = str_contains($failure->getMessage(), 'size preflight returned a malformed row');
}
$check($malformedTermMetaRefused, 'term-meta malformed locked rows refuse before mutation');
$wpdb->forcedMetaRead = null;
$wpdb->last_error = 'stale prior driver error';
$termMaterializer->reconcile_authored_term_meta(31, ['catalog' => [['Retry', 'Converged']]]);
$check(
    $wpdb->last_error === ''
        && count(array_filter(
            $wpdb->termMetaRows,
            static fn(array $row): bool => $row['meta_key'] === 'catalog'
                && $row['meta_value'] === serialize([['Retry', 'Converged']])
        )) === 1,
    'term-meta same-process retry clears stale DB state and converges after a refused read'
);
$beforeWeakIsolation = $wpdb->termMetaRows;
$beforeWeakMutations = count($wpdb->queries);
$wpdb->savepointExists = false;
try {
    $termMaterializer->reconcile_authored_term_meta(31, ['catalog' => [['Unsafe', 'Isolation']]]);
    $weakIsolationRefused = false;
} catch (Throwable $failure) {
    $weakIsolationRefused = str_contains($failure->getMessage(), 'lost authored transaction continuity');
}
$check(
    $weakIsolationRefused && $wpdb->termMetaRows === $beforeWeakIsolation
        && count(array_filter(
            array_slice($wpdb->queries, $beforeWeakMutations),
            static fn(string $sql): bool => str_contains($sql, 'LIMIT 100001 FOR UPDATE')
        )) === 0,
    'cached term-meta descriptor refuses a restarted weaker-isolation transaction before owner bytes or mutation'
);
$termMaterializer->begin_authored_transaction();

foreach (['01', '1junk', 1, 1.0, true, false, '0', '-1'] as $malformedIdentity) {
    $beforeRows = $wpdb->termMetaRows;
    $wpdb->forcedMetaRead = [[
        'meta_id' => $malformedIdentity,
        'meta_key_bytes' => '7',
        'meta_value_bytes' => '5',
    ]];
    try {
        $termMaterializer->reconcile_authored_term_meta(31, ['catalog' => [['Unsafe', 'Identity']]]);
        $malformedLookupRefused = false;
    } catch (Throwable $failure) {
        $malformedLookupRefused = str_contains($failure->getMessage(), 'malformed row');
    }
    $check(
        $malformedLookupRefused && $wpdb->termMetaRows === $beforeRows,
        'term-meta finalize rejects driver-impossible identity spelling '
            . json_encode($malformedIdentity) . ' before mutation'
    );
}
$wpdb->forcedMetaRead = null;
$check(
    count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'SELECT `meta_id` FROM `wp_termmeta`')
            && str_contains($sql, 'meta_key =')
    )) === 0,
    'term-meta authored upsert uses only the already locked exact-key rows and never a collation-equality lookup'
);
$wpdb->last_error = '';

foreach ([
    ['bad-table!', 'post_id', 'meta_id'],
    ['wp_postmeta', 'bad-column!', 'meta_id'],
    ['wp_postmeta', 'post_id', str_repeat('i', 65)],
] as [$unsafeTable, $unsafeForeignKey, $unsafeIdentity]) {
    try {
        $materializer->upsert_meta(
            $unsafeTable,
            $unsafeForeignKey,
            19,
            'owned',
            'after',
            'unsafe identifier probe',
            $unsafeIdentity
        );
        $unsafeIdentifierRefused = false;
    } catch (Throwable $failure) {
        $unsafeIdentifierRefused = str_contains($failure->getMessage(), 'unsafe SQL identifier');
    }
    $check($unsafeIdentifierRefused, 'meta upsert rejects unsafe or oversized SQL identifiers before a query');
}
try {
    $materializer->upsert_meta($wpdb->postmeta, 'post_id', 0, 'owned', 'after', 'owner probe');
    $nonpositiveOwnerRefused = false;
} catch (Throwable $failure) {
    $nonpositiveOwnerRefused = str_contains($failure->getMessage(), 'nonpositive owner identity');
}
$check($nonpositiveOwnerRefused, 'meta upsert rejects a nonpositive owner identity before a query');
$cacheDeleteResult = true;

$cacheEvents = [];
$wpdb->optionRows = [[
    'option_id' => 3, 'option_name' => 'theme_mods_demo', 'option_value' => 'old', 'autoload' => 'yes',
]];
$materializer->upsert_option('theme_mods_demo', 'new', 'no');
$check($wpdb->optionRows[0]['option_value'] === 'new' && $wpdb->optionRows[0]['autoload'] === 'no',
    'upsert_option updates the existing option and autoload flag');
$materializer->upsert_option('new_option', 'value', 'yes');
$newOptions = array_values(array_filter($wpdb->optionRows, static fn(array $row): bool => $row['option_name'] === 'new_option'));
$check(count($newOptions) === 1 && $newOptions[0]['option_value'] === 'value', 'upsert_option inserts a missing option');
$check($cacheEvents === [
    ['options', 'theme_mods_demo'], ['options', 'alloptions'],
    ['options', 'notoptions'], ['options', 'new_option'],
    ['options', 'alloptions'], ['options', 'notoptions'],
], 'upsert_option invalidates the named/alloptions/notoptions composite for both paths');

$check($materializer->option_wire_value(null) === 'N;', 'option_wire_value preserves null');
$check($materializer->option_wire_value(false) === 'b:0;', 'option_wire_value preserves false');
$check($materializer->option_wire_value(['x' => 1]) === 'a:1:{s:1:"x";i:1;}',
    'option_wire_value retains WordPress serialized array bytes');
$check($materializer->option_wire_value('') === '', 'option_wire_value leaves an empty string empty');

if ($failures) {
    \Duo\CacheInvalidationTransaction::end();
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
\Duo\CacheInvalidationTransaction::end();
echo "\nall ApplyFieldMaterializer checks passed\n";
