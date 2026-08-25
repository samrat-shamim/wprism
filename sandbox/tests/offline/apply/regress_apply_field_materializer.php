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
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/EntityAdopter.php';

use Duo\ApplyFieldMaterializer;

final class ApplyFieldMaterializerFakeWpdb {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';
    public string $term_taxonomy = 'wp_term_taxonomy';
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
    public array $termTaxonomyRows = [];
    /** @var list<array<string,mixed>> */
    public array $duoMapRows = [];
    /** @var list<array<string,mixed>> */
    public array $optionRows = [];
    /** @var list<string> */
    public array $mutations = [];
    public bool $failNextRepeatedInsert = false;
    public bool $retainNextMetaDelete = false;
    public bool $dropNextMetaInsert = false;
    public int $reorderAfterMetaInserts = 0;
    public ?string $mutateMetaAfterSize = null;
    public ?string $mutateMetaAfterHash = null;
    public ?string $mutateMetaTable = null;
    /** @var array<string,int> */
    public array $idByUuid = [];

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
        if (preg_match(
            "/^INSERT INTO wp_duo_map \\(uuid, entity_type, id_kind, local_id\\)\\s+"
                . "VALUES \\('([^']+)', '([^']+)', '([^']+)', ([0-9]+)\\)\\s+"
                . 'ON DUPLICATE KEY UPDATE entity_type = VALUES\\(entity_type\\)$/D',
            trim($sql),
            $match
        ) === 1) {
            [$uuid, $entityType, $idKind, $localId] = [$match[1], $match[2], $match[3], (int) $match[4]];
            foreach ($this->duoMapRows as &$row) {
                if ($row['uuid'] === $uuid && $row['id_kind'] === $idKind) {
                    $row['entity_type'] = $entityType;
                    return 1;
                }
            }
            unset($row);
            $this->duoMapRows[] = [
                'uuid' => $uuid,
                'entity_type' => $entityType,
                'id_kind' => $idKind,
                'local_id' => $localId,
            ];
            return 1;
        }
        throw new RuntimeException("unrecognized query: $sql");
    }

    public function get_row(string $sql, mixed $mode): ?array {
        $this->queries[] = $sql;
        if ($mode !== ARRAY_A) {
            throw new RuntimeException('fixture expected ARRAY_A');
        }
        if (preg_match(
            "/^SELECT entity_type, local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'$/D",
            trim($sql),
            $match
        ) === 1) {
            foreach ($this->duoMapRows as $row) {
                if ($row['uuid'] === $match[1] && $row['id_kind'] === $match[2]) {
                    return [
                        'entity_type' => (string) $row['entity_type'],
                        'local_id' => (string) $row['local_id'],
                    ];
                }
            }
            return null;
        }
        if (preg_match(
            "/^SELECT uuid, entity_type FROM wp_duo_map WHERE id_kind = '([^']+)' AND local_id = ([0-9]+)$/D",
            trim($sql),
            $match
        ) === 1) {
            foreach ($this->duoMapRows as $row) {
                if ($row['id_kind'] === $match[1] && (int) $row['local_id'] === (int) $match[2]) {
                    return [
                        'uuid' => (string) $row['uuid'],
                        'entity_type' => (string) $row['entity_type'],
                    ];
                }
            }
            return null;
        }
        throw new RuntimeException("unrecognized get_row query: $sql");
    }

    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (str_contains($sql, 'SELECT local_id FROM wp_duo_map')) {
            preg_match("/uuid = '([^']+)'/", $sql, $match);
            return isset($this->idByUuid[(string) ($match[1] ?? '')])
                ? (string) $this->idByUuid[(string) $match[1]]
                : null;
        }
        if (trim($sql) === 'SELECT @@in_transaction') {
            return $this->inTransaction ? '1' : '0';
        }
        if (trim($sql) === 'SELECT @@transaction_isolation') {
            return $this->isolation;
        }
        if (trim($sql) === 'SELECT 1 FROM `wp_termmeta` LIMIT 1') {
            return '1';
        }
        if (trim($sql) === 'SELECT 1 FROM `wp_term_taxonomy` LIMIT 1') {
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
                ['TABLE_NAME' => $this->postmeta, 'ENGINE' => 'InnoDB'],
                ['TABLE_NAME' => $this->termmeta, 'ENGINE' => 'InnoDB'],
                ['TABLE_NAME' => $this->term_taxonomy, 'ENGINE' => 'InnoDB'],
            ];
        }
        if (str_starts_with($sql, 'SHOW INDEX FROM `wp_postmeta`')) {
            return [[
                'Key_name' => 'post_id',
                'Seq_in_index' => '1',
                'Column_name' => 'post_id',
                'Sub_part' => null,
                'Non_unique' => '1',
                'Index_type' => 'BTREE',
            ]];
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
        if (str_starts_with($sql, 'SHOW INDEX FROM `wp_term_taxonomy`')) {
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
                    'autoload_bytes' => (string) strlen((string) $row['autoload']),
                ], $rows);
            }
            if (str_contains($sql, 'SHA2(option_value, 256)')) {
                return array_map(static fn(array $row): array => [
                    'option_name' => (string) $row['option_name'],
                    'option_value_sha256' => hash('sha256', (string) $row['option_value']),
                    'autoload_sha256' => hash('sha256', (string) $row['autoload']),
                ], $rows);
            }
            return array_map(static fn(array $row): array => [
                'option_name' => (string) $row['option_name'],
                'option_value' => (string) $row['option_value'],
                'autoload' => (string) $row['autoload'],
            ], $rows);
        }
        if (str_contains($sql, 'FROM wp_term_taxonomy FORCE INDEX (`term_id`)')) {
            preg_match('/WHERE term_id = ([0-9]+)/', $sql, $match);
            $termId = (int) ($match[1] ?? 0);
            $rows = array_values(array_filter(
                $this->termTaxonomyRows,
                static fn(array $row): bool => (int) $row['term_id'] === $termId
            ));
            usort($rows, static fn(array $a, array $b): int =>
                (int) $a['term_taxonomy_id'] <=> (int) $b['term_taxonomy_id']);
            return array_map(static fn(array $row): array => [
                'term_taxonomy_id' => (string) $row['term_taxonomy_id'],
                'taxonomy' => (string) $row['taxonomy'],
            ], array_slice($rows, 0, 1025));
        }
        if (str_contains($sql, 'FROM `wp_termmeta` FORCE INDEX (`term_id`)')
            || str_contains($sql, 'FROM `wp_postmeta` FORCE INDEX (`post_id`)')) {
            $isPostMeta = str_contains($sql, 'FROM `wp_postmeta`');
            $ownerColumn = $isPostMeta ? 'post_id' : 'term_id';
            $rowsProperty = $isPostMeta ? 'postMetaRows' : 'termMetaRows';
            if (str_contains($sql, 'AS meta_id, meta_key') && str_contains($sql, 'AND meta_key =')) {
                preg_match('/`' . $ownerColumn . '` = ([0-9]+)/', $sql, $ownerMatch);
                preg_match("/meta_key = '((?:''|[^'])*)'/", $sql, $keyMatch);
                $ownerId = (int) ($ownerMatch[1] ?? 0);
                $key = str_replace("''", "'", (string) ($keyMatch[1] ?? ''));
                $rows = array_values(array_filter(
                    $this->{$rowsProperty},
                    static fn(array $row): bool => (int) $row[$ownerColumn] === $ownerId
                        && strcasecmp((string) $row['meta_key'], $key) === 0
                ));
                usort($rows, static fn(array $a, array $b): int => (int) $a['meta_id'] <=> (int) $b['meta_id']);
                return array_map(static fn(array $row): array => [
                    'meta_id' => (string) $row['meta_id'],
                    'meta_key' => (string) $row['meta_key'],
                ], $rows);
            }
            $preflight = str_contains($sql, 'OCTET_LENGTH(meta_key)');
            $hashWitness = str_contains($sql, 'SHA2(meta_key, 256)');
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
                    ]);
                }
                if ($this->forcedMetaRead === 'oversized-value') {
                    return [[
                        'meta_id' => '1',
                        'meta_key_bytes' => '7',
                        'meta_value_bytes' => (string) (\Duo\MetaRows::MAX_META_VALUE_BYTES + 1),
                    ]];
                }
                if ($this->forcedMetaRead === 'aggregate-overflow') {
                    $aggregateRows = [];
                    for ($metaId = 1; $metaId <= 5; ++$metaId) {
                        $aggregateRows[] = [
                            'meta_id' => (string) $metaId,
                            'meta_key_bytes' => '1',
                            'meta_value_bytes' => (string) \Duo\MetaRows::MAX_META_VALUE_BYTES,
                        ];
                    }
                    return $aggregateRows;
                }
                return $this->forcedMetaRead;
            }
            preg_match('/`' . $ownerColumn . '` = ([0-9]+)/', $sql, $match);
            $ownerId = (int) ($match[1] ?? 0);
            $rows = array_values(array_filter(
                $this->{$rowsProperty},
                static fn(array $row): bool => (int) $row[$ownerColumn] === $ownerId
            ));
            usort($rows, static fn(array $a, array $b): int => (int) $a['meta_id'] <=> (int) $b['meta_id']);
            $projected = array_map(static fn(array $row): array => $preflight ? [
                'meta_id' => (string) $row['meta_id'],
                'meta_key_bytes' => (string) strlen((string) $row['meta_key']),
                'meta_value_bytes' => $row['meta_value'] === null
                    ? null
                    : (string) strlen((string) $row['meta_value']),
            ] : ($hashWitness ? [
                'meta_id' => (string) $row['meta_id'],
                'meta_key_sha256' => hash('sha256', (string) $row['meta_key']),
                'meta_value_sha256' => $row['meta_value'] === null
                    ? null
                    : hash('sha256', (string) $row['meta_value']),
            ] : [
                'meta_id' => (string) $row['meta_id'],
                'meta_key' => $row['meta_key'],
                'meta_value' => $row['meta_value'],
            ]), $rows);
            if ($preflight
                && $this->mutateMetaAfterSize !== null
                && ($this->mutateMetaTable === null || $this->mutateMetaTable === $rowsProperty)) {
                $this->mutateMetaRoster($rowsProperty, $ownerColumn, $ownerId, $this->mutateMetaAfterSize);
                $this->mutateMetaAfterSize = null;
                $this->mutateMetaTable = null;
            } elseif ($hashWitness
                && $this->mutateMetaAfterHash !== null
                && ($this->mutateMetaTable === null || $this->mutateMetaTable === $rowsProperty)) {
                $this->mutateMetaRoster($rowsProperty, $ownerColumn, $ownerId, $this->mutateMetaAfterHash);
                $this->mutateMetaAfterHash = null;
                $this->mutateMetaTable = null;
            }
            return $projected;
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
        $this->mutations[] = 'update:' . $table;
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

    public function insert(string $table, array $data, $format = null): int|false {
        if ($this->failNextRepeatedInsert && isset($data['meta_key'])) {
            $this->failNextRepeatedInsert = false;
            $this->mutations[] = 'failed-insert:' . $table;
            return false;
        }
        $this->mutations[] = 'insert:' . $table;
        if ($this->dropNextMetaInsert && str_contains($table, 'meta')) {
            $this->dropNextMetaInsert = false;
            return 1;
        }
        $rows =& $this->rowsFor($table);
        if (str_contains($table, 'meta')) {
            $ids = array_map('intval', array_column($rows, 'meta_id'));
            $this->insert_id = $ids ? max($ids) + 1 : 1;
            $rows[] = ['meta_id' => $this->insert_id] + $data;
            if ($this->reorderAfterMetaInserts > 0) {
                --$this->reorderAfterMetaInserts;
                if ($this->reorderAfterMetaInserts === 0 && count($rows) >= 2) {
                    $last = count($rows) - 1;
                    [$rows[$last - 1]['meta_value'], $rows[$last]['meta_value']] = [
                        $rows[$last]['meta_value'], $rows[$last - 1]['meta_value'],
                    ];
                }
            }
        } else {
            $ids = array_map('intval', array_column($rows, 'option_id'));
            $this->insert_id = $ids ? max($ids) + 1 : 1;
            $rows[] = ['option_id' => $this->insert_id] + $data;
        }
        return 1;
    }

    public function delete(string $table, array $where, $whereFormat = null): int {
        $this->mutations[] = 'delete:' . $table;
        if ($this->retainNextMetaDelete && str_contains($table, 'meta')) {
            $this->retainNextMetaDelete = false;
            return 1;
        }
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

    private function mutateMetaRoster(
        string $rowsProperty,
        string $ownerColumn,
        int $ownerId,
        string $mode
    ): void {
        $rows =& $this->{$rowsProperty};
        $indexes = [];
        foreach ($rows as $index => $row) {
            if ((int) $row[$ownerColumn] === $ownerId) {
                $indexes[] = $index;
            }
        }
        if ($mode === 'insert') {
            $ids = array_map('intval', array_column($rows, 'meta_id'));
            $rows[] = [
                'meta_id' => $ids === [] ? 1 : max($ids) + 1,
                $ownerColumn => $ownerId,
                'meta_key' => '_concurrent',
                'meta_value' => 'inserted',
            ];
            return;
        }
        if ($indexes === []) {
            return;
        }
        if ($mode === 'delete') {
            unset($rows[$indexes[0]]);
            $rows = array_values($rows);
            return;
        }
        if ($mode === 'update') {
            $index = $indexes[0];
            $value = (string) $rows[$index]['meta_value'];
            $rows[$index]['meta_value'] = $value === '' ? 'x' : strrev($value);
            return;
        }
        if ($mode === 'reorder' && count($indexes) >= 2) {
            [$first, $second] = [$indexes[0], $indexes[1]];
            [$rows[$first]['meta_value'], $rows[$second]['meta_value']] = [
                $rows[$second]['meta_value'], $rows[$first]['meta_value'],
            ];
        }
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
    )) === 3
        && count(array_filter(
            $wpdb->queries,
            static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(meta_key)')
                && str_contains($sql, '`term_id` = 31')
        )) === 1,
    'term-meta product path locks a bounded size roster, hash witness, full owner range, and terminal gap'
);
$check(
    $cacheEvents === [['term_meta', '31']],
    'term-meta reconciliation purges the same-process WordPress cache while accepting an already-absent cache key'
);

foreach (['false', 'null', 'error', 'oversize', 'oversized-value', 'aggregate-overflow'] as $failureMode) {
    $beforeRows = $wpdb->termMetaRows;
    $beforeFullReads = count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'meta_key, meta_value')
    ));
    $beforeHashReads = count(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'SHA2(meta_key, 256)')
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
                'aggregate-overflow' => 'bounded owner-byte limit',
                default => 'checked metadata size preflight failed',
            }
        );
    }
    $check(
        $lockedReadRefused && $wpdb->termMetaRows === $beforeRows,
        "term-meta $failureMode locked-read failure performs no mutation"
    );
    if (in_array($failureMode, ['oversized-value', 'aggregate-overflow'], true)) {
        $check(
            count(array_filter(
                $wpdb->queries,
                static fn(string $sql): bool => str_contains($sql, 'meta_key, meta_value')
            )) === $beforeFullReads,
            "term-meta $failureMode refuses before a full-value transfer"
        );
        $check(
            count(array_filter(
                $wpdb->queries,
                static fn(string $sql): bool => str_contains($sql, 'SHA2(meta_key, 256)')
            )) === $beforeHashReads,
            "term-meta $failureMode refuses before database hashing"
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

$repeatedRows = [
    'cardinality' => 'one_or_more',
    'duplicates' => 'forbid',
    'order' => 'preserve',
];
$repeatedPolicy = new \Duo\Policy();
$repeatedPolicy->site = ['policy' => [
    'post_meta' => [
        '_organizers' => ['class' => 'authored', 'ref' => 'post', 'repeated_rows' => $repeatedRows],
        '_scalar' => ['class' => 'authored'],
        '_old_owned' => ['class' => 'authored'],
        '_runtime' => ['class' => 'runtime'],
    ],
    'term_meta' => [
        '_related_terms' => ['class' => 'authored', 'ref' => 'term', 'repeated_rows' => $repeatedRows],
        '_term_runtime' => ['class' => 'runtime'],
    ],
]];
$repeatedTokens = new \Duo\Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$repeatedMaterializer = new ApplyFieldMaterializer($repeatedPolicy, $repeatedTokens);
$repeatedMaterializer->begin_authored_transaction();
$postUuids = [
    '00000000-0000-4000-8000-000000000001',
    '00000000-0000-4000-8000-000000000002',
    '00000000-0000-4000-8000-000000000003',
];
$termUuids = [
    '00000000-0000-4000-8000-000000000011',
    '00000000-0000-4000-8000-000000000012',
];
$wpdb->idByUuid = [
    $postUuids[0] => 900000001,
    $postUuids[1] => 37,
    $postUuids[2] => 800000003,
    $termUuids[0] => 700000001,
    $termUuids[1] => 23,
];
$wpdb->postMetaRows = [
    ['meta_id' => 101, 'post_id' => 91, 'meta_key' => '_organizers', 'meta_value' => '999'],
    ['meta_id' => 102, 'post_id' => 91, 'meta_key' => '_organizers', 'meta_value' => '37'],
    ['meta_id' => 103, 'post_id' => 91, 'meta_key' => '_organizers', 'meta_value' => 'stale-extra'],
    ['meta_id' => 104, 'post_id' => 91, 'meta_key' => '_ORGANIZERS', 'meta_value' => 'alias-preserved'],
    ['meta_id' => 105, 'post_id' => 91, 'meta_key' => '_runtime', 'meta_value' => "runtime\0bytes"],
    ['meta_id' => 106, 'post_id' => 91, 'meta_key' => '_old_owned', 'meta_value' => 'delete-me'],
    ['meta_id' => 107, 'post_id' => 91, 'meta_key' => '_scalar', 'meta_value' => 'same'],
    ['meta_id' => 108, 'post_id' => 91, 'meta_key' => '_scalar', 'meta_value' => 'duplicate'],
];
$postCanonical = array_map(static fn(string $uuid): string => "{{post:$uuid}}", $postUuids);
$malformedRepeatedCases = [
    [[], 'non-empty canonical list'],
    [$postCanonical[0], 'non-empty canonical list'],
    [[[$postCanonical[0]]], 'requires one scalar value per row'],
    [['a:1:{i:0;s:5:"value";}'], 'requires canonical decoded scalar values'],
    [[$postCanonical[0], $postCanonical[0]], 'contains a duplicate value'],
];
foreach ($malformedRepeatedCases as [$malformedValue, $expectedFailure]) {
    $beforeQueries = count($wpdb->queries);
    $beforeMutations = count($wpdb->mutations);
    try {
        $repeatedMaterializer->reconcile_authored_meta(
            91,
            ['_organizers' => $malformedValue, '_scalar' => 'same'],
            'post 91'
        );
        $malformedRepeatedRefused = false;
    } catch (Throwable $failure) {
        $malformedRepeatedRefused = str_contains($failure->getMessage(), $expectedFailure);
    }
    $check(
        $malformedRepeatedRefused
            && count($wpdb->queries) === $beforeQueries
            && count($wpdb->mutations) === $beforeMutations,
        "direct apply rejects malformed repeated-row value ($expectedFailure) before lock or mutation"
    );
}

$contextPolicy = new \Duo\Policy();
$contextPolicy->site = ['policy' => ['post_meta' => [
    '_context_old' => ['class' => 'authored'],
    '_context_mode' => ['class' => 'runtime'],
]]];
$contextInterpreter = new class($repeatedRows) {
    public function __construct(private array $repeatedRows) {}
    public function post_meta_rule(string $key, array $flat): ?array {
        if ($key !== '_context_repeated') {
            return null;
        }
        return ($flat['_context_mode'] ?? null) === 'source'
            ? ['class' => 'authored', 'ref' => 'post', 'repeated_rows' => $this->repeatedRows]
            : ['class' => 'runtime'];
    }
};
$contextInterpreterInstances = new ReflectionProperty(\Duo\Policy::class, 'interpreterInstances');
$contextInterpreterInstances->setValue($contextPolicy, ['repeated-context-fixture' => $contextInterpreter]);
$contextMaterializer = new ApplyFieldMaterializer($contextPolicy, $repeatedTokens);
$contextMaterializer->begin_authored_transaction();
$wpdb->postMetaRows[] = [
    'meta_id' => 109,
    'post_id' => 92,
    'meta_key' => '_context_mode',
    'meta_value' => 'target',
];
$wpdb->postMetaRows[] = [
    'meta_id' => 110,
    'post_id' => 92,
    'meta_key' => '_context_old',
    'meta_value' => 'must-survive-refusal',
];
$beforeContextRows = $wpdb->postMetaRows;
$beforeContextMutations = count($wpdb->mutations);
try {
    $contextMaterializer->reconcile_authored_meta(92, [
        '_context_mode' => 'source',
        '_context_repeated' => [$postCanonical[0]],
    ], 'post 92');
    $targetContextRefused = false;
} catch (Throwable $failure) {
    $targetContextRefused = str_contains($failure->getMessage(), 'disagrees with the locked target context');
}
$check(
    $targetContextRefused
        && $wpdb->postMetaRows === $beforeContextRows
        && count($wpdb->mutations) === $beforeContextMutations,
    'locked target-context disagreement refuses before deleting another absent authored key'
);

$cacheEvents = [];
$mutationStart = count($wpdb->mutations);
$queryStart = count($wpdb->queries);
$repeatedMaterializer->reconcile_authored_meta(
    91,
    ['_organizers' => $postCanonical, '_scalar' => 'same'],
    'post 91'
);
$postOrganizerRows = array_values(array_filter(
    $wpdb->postMetaRows,
    static fn(array $row): bool => $row['meta_key'] === '_organizers'
));
$check(array_column($postOrganizerRows, 'meta_value') === ['900000001', '37', '800000003'],
    'post apply replaces every byte-exact repeated row in declared order after target ref rebasing');
$check(count($postOrganizerRows) === 3
    && count(array_filter($wpdb->postMetaRows,
        static fn(array $row): bool => $row['meta_key'] === '_scalar')) === 1,
    'repeated replacement deletes stale exact rows while ordinary scalar duplicate collapse remains first-row-wins');
$postByKey = [];
foreach ($wpdb->postMetaRows as $row) {
    $postByKey[$row['meta_key']][] = $row['meta_value'];
}
$check(($postByKey['_runtime'][0] ?? null) === "runtime\0bytes"
    && ($postByKey['_ORGANIZERS'][0] ?? null) === 'alias-preserved'
    && !isset($postByKey['_old_owned']),
    'post reconciliation preserves runtime bytes and a collation-equal key alias while deleting absent exact authored keys');
$check(count(array_filter(
    array_slice($wpdb->queries, $queryStart),
    static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(meta_key)')
        && str_contains($sql, '`post_id` = 91')
)) === 2,
    'post scalar and repeated reconciliation share one lock descriptor and perform initial plus terminal checked owner-range reads');
$check(count(array_filter(
    array_slice($wpdb->queries, $queryStart),
    static fn(string $sql): bool => str_contains($sql, 'meta_key =')
)) === 0,
    'post repeated reconciliation never performs a collation-sensitive key lookup');
$firstMutationCount = count($wpdb->mutations);
$firstCacheCount = count($cacheEvents);
$repeatedMaterializer->reconcile_authored_meta(
    91,
    ['_organizers' => $postCanonical, '_scalar' => 'same'],
    'post 91'
);
$check(count($wpdb->mutations) === $firstMutationCount,
    'an exact ordered repeated-row retry is a database no-op');
$check(count($cacheEvents) === $firstCacheCount + 1,
    'an exact database retry still purges potentially stale same-process metadata cache state');
$check($firstMutationCount > $mutationStart && $firstCacheCount > 0,
    'a divergent ordered set performs checked mutations and invalidates the owner cache');

$beforeFailure = $wpdb->postMetaRows;
$wpdb->failNextRepeatedInsert = true;
try {
    $repeatedMaterializer->reconcile_authored_meta(
        91,
        ['_organizers' => [$postCanonical[2], $postCanonical[0], $postCanonical[1]], '_scalar' => 'same'],
        'post 91'
    );
    $failedInsertRefused = false;
} catch (\Duo\DatabaseMutationException $failure) {
    $failedInsertRefused = $failure->mutationContext === 'apply insert repeated post 91 meta';
}
$check($failedInsertRefused,
    'a repeated-row insert failure is loud and carries only its bounded operation context');
$wpdb->postMetaRows = $beforeFailure; // AuthoredTransactionExecutor rolls this exact unit back in production.
$repeatedMaterializer->reconcile_authored_meta(
    91,
    ['_organizers' => [$postCanonical[2], $postCanonical[0], $postCanonical[1]], '_scalar' => 'same'],
    'post 91'
);
$postOrganizerRows = array_values(array_filter(
    $wpdb->postMetaRows,
    static fn(array $row): bool => $row['meta_key'] === '_organizers'
));
$check(array_column($postOrganizerRows, 'meta_value') === ['800000003', '900000001', '37'],
    'after transaction rollback, retry deterministically materializes a reorder-only change');

$wireAliasMutationCount = count($wpdb->mutations);
$wpdb->idByUuid[$postUuids[1]] = $wpdb->idByUuid[$postUuids[0]];
try {
    $repeatedMaterializer->reconcile_authored_meta(
        91,
        ['_organizers' => [$postCanonical[0], $postCanonical[1]], '_scalar' => 'same'],
        'post 91'
    );
    $wireAliasRefused = false;
} catch (RuntimeException $failure) {
    $wireAliasRefused = $failure->getMessage()
        === "duo: repeated-row authored post 91 meta '_organizers' resolves to a duplicate target wire value";
}
$check($wireAliasRefused,
    'distinct canonical rows that resolve to one target wire value refuse before mutation');
$check(count($wpdb->mutations) === $wireAliasMutationCount,
    'target-wire alias refusal leaves the locked physical roster byte-identical');
$wpdb->idByUuid[$postUuids[1]] = 37;

foreach (['drop-insert', 'reorder'] as $successShapeMode) {
    $beforeSuccessShapeRows = $wpdb->postMetaRows;
    $beforeSuccessShapeCache = count($cacheEvents);
    $successShapeDesired = $successShapeMode === 'drop-insert'
        ? $postCanonical
        : [$postCanonical[2], $postCanonical[0], $postCanonical[1]];
    $successShapeExpected = $successShapeMode === 'drop-insert'
        ? ['900000001', '37', '800000003']
        : ['800000003', '900000001', '37'];
    if ($successShapeMode === 'drop-insert') {
        $wpdb->dropNextMetaInsert = true;
    } else {
        $wpdb->reorderAfterMetaInserts = 3;
    }
    try {
        $repeatedMaterializer->reconcile_authored_meta(
            91,
            ['_organizers' => $successShapeDesired, '_scalar' => 'same'],
            'post 91'
        );
        $successShapeReadbackRefused = false;
    } catch (RuntimeException $failure) {
        $successShapeReadbackRefused = str_contains($failure->getMessage(), 'failed exact locked readback');
    }
    $check(
        $successShapeReadbackRefused && count($cacheEvents) === $beforeSuccessShapeCache,
        "a success-shaped repeated-row $successShapeMode is caught by terminal locked readback before cache receipt"
    );
    $wpdb->postMetaRows = $beforeSuccessShapeRows; // Model the authored transaction rollback.
    $repeatedMaterializer->reconcile_authored_meta(
        91,
        ['_organizers' => $successShapeDesired, '_scalar' => 'same'],
        'post 91'
    );
    $postOrganizerRows = array_values(array_filter(
        $wpdb->postMetaRows,
        static fn(array $row): bool => $row['meta_key'] === '_organizers'
    ));
    $check(
        array_column($postOrganizerRows, 'meta_value') === $successShapeExpected,
        "rollback followed by retry converges after a success-shaped repeated-row $successShapeMode"
    );
}

$wpdb->postMetaRows[] = [
    'meta_id' => 150,
    'post_id' => 93,
    'meta_key' => '_organizers',
    'meta_value' => '900000001',
];
$wpdb->postMetaRows[] = [
    'meta_id' => 151,
    'post_id' => 93,
    'meta_key' => '_ORGANIZERS',
    'meta_value' => 'alias-survives',
];
$beforeRetainedDeleteRows = $wpdb->postMetaRows;
$beforeRetainedDeleteCache = count($cacheEvents);
$wpdb->retainNextMetaDelete = true;
try {
    $repeatedMaterializer->reconcile_authored_meta(93, [], 'post 93');
    $retainedDeleteReadbackRefused = false;
} catch (RuntimeException $failure) {
    $retainedDeleteReadbackRefused = str_contains($failure->getMessage(), 'failed exact locked readback');
}
$check(
    $retainedDeleteReadbackRefused && count($cacheEvents) === $beforeRetainedDeleteCache,
    'a success-shaped retained delete cannot bless an omitted repeated key as zero rows'
);
$wpdb->postMetaRows = $beforeRetainedDeleteRows; // Model the authored transaction rollback.
$repeatedMaterializer->reconcile_authored_meta(93, [], 'post 93');
$check(
    count(array_filter(
        $wpdb->postMetaRows,
        static fn(array $row): bool => (int) ($row['post_id'] ?? 0) === 93
            && $row['meta_key'] === '_organizers'
    )) === 0
        && count(array_filter(
            $wpdb->postMetaRows,
            static fn(array $row): bool => (int) ($row['post_id'] ?? 0) === 93
                && $row['meta_key'] === '_ORGANIZERS'
        )) === 1,
    'omitted repeated-row retry proves zero byte-exact rows while preserving a key alias'
);

$wpdb->termMetaRows = [
    ['meta_id' => 201, 'term_id' => 71, 'meta_key' => '_related_terms', 'meta_value' => 'old'],
    ['meta_id' => 202, 'term_id' => 71, 'meta_key' => '_RELATED_TERMS', 'meta_value' => 'alias-preserved'],
    ['meta_id' => 203, 'term_id' => 71, 'meta_key' => '_term_runtime', 'meta_value' => 'preserve'],
];
$termCanonical = array_map(static fn(string $uuid): string => "{{term:$uuid}}", $termUuids);
$termRepeatedQueryStart = count($wpdb->queries);
$repeatedMaterializer->reconcile_authored_term_meta(71, ['_related_terms' => $termCanonical]);
$termRelated = array_values(array_filter(
    $wpdb->termMetaRows,
    static fn(array $row): bool => $row['meta_key'] === '_related_terms'
));
$check(array_column($termRelated, 'meta_value') === ['700000001', '23']
    && count(array_filter($wpdb->termMetaRows,
        static fn(array $row): bool => $row['meta_key'] === '_term_runtime')) === 1
    && count(array_filter($wpdb->termMetaRows,
        static fn(array $row): bool => $row['meta_key'] === '_RELATED_TERMS')) === 1,
    'term metadata shares ordered repeated-row apply while preserving runtime and key-alias rows');
$check(
    count(array_filter(
        array_slice($wpdb->queries, $termRepeatedQueryStart),
        static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(meta_key)')
            && str_contains($sql, '`term_id` = 71')
    )) === 2,
    'term repeated-row apply performs the same locked initial and terminal owner-range proofs'
);

$postRowsBeforeConcurrency = $wpdb->postMetaRows;
$termRowsBeforeConcurrency = $wpdb->termMetaRows;
foreach (['insert', 'update', 'delete', 'reorder'] as $mode) {
    foreach ([
        ['postMetaRows', 'post_id', 191, false],
        ['termMetaRows', 'term_id', 171, true],
    ] as [$rowsProperty, $ownerColumn, $ownerId, $termMeta]) {
        $wpdb->{$rowsProperty} = [[
            'meta_id' => 301,
            $ownerColumn => $ownerId,
            'meta_key' => $termMeta ? '_related_terms' : '_organizers',
            'meta_value' => '900000001',
        ], [
            'meta_id' => 302,
            $ownerColumn => $ownerId,
            'meta_key' => $termMeta ? '_related_terms' : '_organizers',
            'meta_value' => '37',
        ]];
        if (in_array($mode, ['update', 'reorder'], true)) {
            $wpdb->mutateMetaAfterHash = $mode;
        } else {
            $wpdb->mutateMetaAfterSize = $mode;
        }
        $wpdb->mutateMetaTable = $rowsProperty;
        $beforeDuoMutations = count($wpdb->mutations);
        try {
            if ($termMeta) {
                $repeatedMaterializer->reconcile_authored_term_meta($ownerId, [
                    '_related_terms' => $termCanonical,
                ]);
            } else {
                $repeatedMaterializer->reconcile_authored_meta($ownerId, [
                    '_organizers' => [$postCanonical[0], $postCanonical[1]],
                ], "post $ownerId");
            }
            $concurrentRefused = false;
        } catch (Throwable $failure) {
            $concurrentRefused = str_contains($failure->getMessage(), 'hash witness failed or changed')
                || str_contains($failure->getMessage(), 'malformed or changed row')
                || str_contains($failure->getMessage(), 'value read disagrees with the bounded size preflight');
        }
        $check($concurrentRefused && count($wpdb->mutations) === $beforeDuoMutations,
            ($termMeta ? 'term' : 'post') . " repeated-row $mode drift between compact/hash reads refuses before Duo mutation");
    }
}
$wpdb->postMetaRows = $postRowsBeforeConcurrency;
$wpdb->termMetaRows = $termRowsBeforeConcurrency;

$repeatedMaterializer->reconcile_authored_meta(91, ['_scalar' => 'same'], 'post 91');
$check(count(array_filter(
    $wpdb->postMetaRows,
    static fn(array $row): bool => (int) ($row['post_id'] ?? 0) === 91 && $row['meta_key'] === '_organizers'
)) === 0,
    'omitting a repeated key deletes every byte-exact owned row and represents zero cardinality as absence');
$check(count(array_filter(
    $wpdb->postMetaRows,
    static fn(array $row): bool => (int) ($row['post_id'] ?? 0) === 91 && $row['meta_key'] === '_ORGANIZERS'
)) === 1,
    'deleting an absent repeated key still preserves a collation-equal non-byte-exact alias');

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

$adopterPolicy = new \Duo\Policy();
$adopterMaterializer = new ApplyFieldMaterializer($adopterPolicy, $termTokens);
$adopter = new \Duo\EntityAdopter($adopterPolicy, [], $adopterMaterializer);
$adoptionUuid = '00000000-0000-4000-8000-000000000099';
$adoptionPath = 'terms/category/portable-source.json';
$wpdb->termTaxonomyRows = [[
    'term_taxonomy_id' => 71,
    'term_id' => 41,
    'taxonomy' => 'category',
]];
$wpdb->termMetaRows = [];
$wpdb->duoMapRows = [];
$adoptionWarnings = [];
$adopterMaterializer->begin_authored_transaction();
$adopter->adopt(
    ['env_id' => 41, 'uuid' => $adoptionUuid, 'path' => $adoptionPath],
    ['type' => 'term', 'data' => ['taxonomy' => 'category']],
    $adoptionWarnings
);
$adopterMaterializer->end_authored_transaction();
$check(
    $adoptionWarnings === ["adopted env term 41 as $adoptionUuid ($adoptionPath)"],
    'term adoption reports the plan-approved canonical path after inspecting physical taxonomy rows'
);
$check(
    count(array_filter(
        $wpdb->termMetaRows,
        static fn(array $row): bool => (int) $row['term_id'] === 41
            && $row['meta_key'] === '_duo_uuid'
            && $row['meta_value'] === $adoptionUuid
    )) === 1
        && count($wpdb->duoMapRows) === 2,
    'term adoption installs one exact UUID sidecar and both term ledger identities'
);
$menuAdoptionUuid = '00000000-0000-4000-8000-000000000098';
$wpdb->termTaxonomyRows = [[
    'term_taxonomy_id' => 72,
    'term_id' => 42,
    'taxonomy' => 'nav_menu',
]];
$wpdb->termMetaRows = [];
$wpdb->duoMapRows = [];
$menuAdoptionWarnings = [];
$adopterMaterializer->begin_authored_transaction();
$adopter->adopt(
    ['env_id' => 42, 'uuid' => $menuAdoptionUuid, 'path' => 'menus/portable-source.json'],
    ['type' => 'menu', 'data' => ['items' => []]],
    $menuAdoptionWarnings
);
$adopterMaterializer->end_authored_transaction();
$check(
    $menuAdoptionWarnings === ["adopted env term 42 as $menuAdoptionUuid (menus/portable-source.json)"]
        && count($wpdb->duoMapRows) === 2,
    'menu adoption uses the canonical nav_menu taxonomy instead of treating a menu as a malformed term'
);

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
