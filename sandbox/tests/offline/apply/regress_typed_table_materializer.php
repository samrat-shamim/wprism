<?php
declare(strict_types=1);

namespace Duo {
    /** Narrow checked-write double; the product materializer calls this API. */
    final class Db {
        public static function query($sql, string $context) {
            global $wpdb;
            $result = $wpdb->query($sql);
            if ($result === false) {
                throw new \RuntimeException("db failure: $context");
            }
            return $result;
        }

        public static function insert(string $table, array $data, $format = null, ?string $context = null): int {
            global $wpdb;
            $result = $wpdb->insert($table, $data, $format);
            if ($result === false) {
                throw new \RuntimeException('db failure: ' . ($context ?? 'insert'));
            }
            return (int) $result;
        }

        public static function update(
            string $table,
            array $data,
            array $where,
            $format = null,
            $whereFormat = null,
            ?string $context = null
        ): int {
            global $wpdb;
            $result = $wpdb->update($table, $data, $where, $format, $whereFormat);
            if ($result === false) {
                throw new \RuntimeException('db failure: ' . ($context ?? 'update'));
            }
            return (int) $result;
        }

        public static function delete(string $table, array $where, $whereFormat = null, ?string $context = null): int {
            global $wpdb;
            $result = $wpdb->delete($table, $where, $whereFormat);
            if ($result === false) {
                throw new \RuntimeException('db failure: ' . ($context ?? 'delete'));
            }
            return (int) $result;
        }

        public static function insert_id(string $context): int {
            global $wpdb;
            $id = (int) $wpdb->insert_id;
            if ($id <= 0) {
                throw new \RuntimeException("db failure: $context did not produce an id");
            }
            return $id;
        }
    }
}

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    final class MaterializerWpdb {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public int $insert_id = 0;
        public string $last_error = '';

        /** @var array<string,array{columns:array<string,string>,rows:list<array<string,mixed>>}> */
        public array $tables = [];

        public function prepare($sql, ...$args): array {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            return ['sql' => $sql, 'args' => $args];
        }

        public function get_var($query) {
            [$sql, $args] = $this->unwrap($query);
            if (str_contains($sql, 'SHOW TABLES LIKE')) {
                $table = $this->strip((string) ($args[0] ?? ''));
                return isset($this->tables[$table]) ? $this->prefix . $table : null;
            }
            if (preg_match('/^SELECT `([^`]+)` FROM `([^`]+)` WHERE `\1` = %d LIMIT 1$/', $sql, $match)) {
                foreach ($this->rows($match[2]) as $row) {
                    if ((int) ($row[$match[1]] ?? 0) === (int) $args[0]) {
                        return $row[$match[1]];
                    }
                }
                return null;
            }
            if (preg_match('/^SELECT 1 FROM `([^`]+)` WHERE `([^`]+)` = %d AND `([^`]+)` = %d LIMIT 1$/', $sql, $match)) {
                foreach ($this->rows($match[1]) as $row) {
                    if ((int) ($row[$match[2]] ?? 0) === (int) $args[0]
                        && (int) ($row[$match[3]] ?? 0) === (int) $args[1]) {
                        return 1;
                    }
                }
                return null;
            }
            if (str_starts_with($sql, 'SELECT option_id FROM ')) {
                foreach ($this->rows($this->options) as $row) {
                    if ((string) ($row['option_name'] ?? '') === (string) $args[0]) {
                        return $row['option_id'];
                    }
                }
                return null;
            }
            if (preg_match('/^SELECT COUNT\(\*\) FROM `([^`]+)` WHERE `([^`]+)` = %d(?: AND `([^`]+)` = %d)?$/', $sql, $match)) {
                $count = 0;
                foreach ($this->rows($match[1]) as $row) {
                    if ((int) ($row[$match[2]] ?? 0) !== (int) $args[0]) {
                        continue;
                    }
                    if (($match[3] ?? '') !== ''
                        && (int) ($row[$match[3]] ?? 0) !== (int) $args[1]) {
                        continue;
                    }
                    $count++;
                }
                return $count;
            }
            throw new RuntimeException("unrecognized get_var query: $sql");
        }

        public function get_results($query, $output = ARRAY_A): array {
            [$sql, $args] = $this->unwrap($query);
            if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $match)) {
                $out = [];
                foreach ($this->tables[$this->strip($match[1])]['columns'] ?? [] as $field => $type) {
                    $out[] = ['Field' => $field, 'Type' => $type];
                }
                return $out;
            }
            if (preg_match('/^SELECT `([^`]+)` AS id, `([^`]+)` AS k FROM `([^`]+)` WHERE `([^`]+)` = %d$/', $sql, $match)) {
                $out = [];
                foreach ($this->rows($match[3]) as $row) {
                    if ((int) ($row[$match[4]] ?? 0) === (int) $args[0]) {
                        $out[] = ['id' => $row[$match[1]], 'k' => $row[$match[2]]];
                    }
                }
                return $out;
            }
            throw new RuntimeException("unrecognized get_results query: $sql");
        }

        public function query($query): int {
            [$sql, $args] = $this->unwrap($query);
            if (preg_match('/^DELETE FROM `([^`]+)` WHERE `([^`]+)` = %d$/', $sql, $match)) {
                return $this->delete($match[1], [$match[2] => $args[0]]);
            }
            throw new RuntimeException("unrecognized query: $sql");
        }

        public function insert(string $table, array $data, $format = null): int {
            $name = $this->strip($table);
            if (isset($this->tables[$name]['columns']['id']) && !array_key_exists('id', $data)) {
                $ids = array_map(
                    static fn(array $row): int => (int) ($row['id'] ?? 0),
                    $this->tables[$name]['rows'] ?? []
                );
                $data['id'] = ($ids ? max($ids) : 0) + 1;
            }
            $this->tables[$name]['rows'][] = $data;
            $this->insert_id = isset($data['id']) ? (int) $data['id'] : count($this->tables[$name]['rows']);
            return 1;
        }

        public function update(string $table, array $data, array $where, $format = null, $whereFormat = null): int {
            $name = $this->strip($table);
            $count = 0;
            foreach ($this->tables[$name]['rows'] as &$row) {
                if (!$this->matches($row, $where)) {
                    continue;
                }
                $row = array_replace($row, $data);
                $count++;
            }
            unset($row);
            return $count;
        }

        public function delete(string $table, array $where, $format = null): int {
            $name = $this->strip($table);
            $before = count($this->tables[$name]['rows'] ?? []);
            $this->tables[$name]['rows'] = array_values(array_filter(
                $this->tables[$name]['rows'] ?? [],
                fn(array $row): bool => !$this->matches($row, $where)
            ));
            return $before - count($this->tables[$name]['rows']);
        }

        /** @return list<array<string,mixed>> */
        private function rows(string $table): array {
            return $this->tables[$this->strip($table)]['rows'] ?? [];
        }

        private function matches(array $row, array $where): bool {
            foreach ($where as $key => $value) {
                if ((string) ($row[$key] ?? null) !== (string) $value) {
                    return false;
                }
            }
            return true;
        }

        private function strip(string $table): string {
            return str_starts_with($table, $this->prefix)
                ? substr($table, strlen($this->prefix))
                : $table;
        }

        private function unwrap($query): array {
            return is_array($query) ? [$query['sql'], $query['args']] : [(string) $query, []];
        }
    }

    final class MaterializerTokens {
        /** @var array<string,int> */
        public array $ids = [];

        public function detokenize_text(string $value): string {
            return str_replace('{{site:url}}', 'https://target.test', $value);
        }

        public function token_to_id(string $token): int {
            if (!isset($this->ids[$token])) {
                throw new RuntimeException("unresolved token $token");
            }
            return $this->ids[$token];
        }

        public function struct_apply($value, array $refs, ?array $keyRefs = null) {
            return $value;
        }

        public function plain_data_apply($value) {
            if (is_string($value)) {
                return $this->detokenize_text($value);
            }
            if (!is_array($value)) {
                return $value;
            }
            foreach ($value as &$child) {
                $child = $this->plain_data_apply($child);
            }
            unset($child);
            return $value;
        }
    }

    require __DIR__ . '/../../../../agent/src/Apply/TypedTableMaterializer.php';

    use Duo\TypedTableMaterializer;

    $failures = 0;
    function materializer_check(bool $condition, string $message): void {
        global $failures;
        if ($condition) {
            fwrite(STDOUT, "ok: $message\n");
            return;
        }
        fwrite(STDERR, "FAIL: $message\n");
        $failures++;
    }

    function materializer_throws(callable $callable, string $needle, string $message): void {
        global $failures;
        try {
            $callable();
            fwrite(STDERR, "FAIL: $message (did not throw)\n");
            $failures++;
        } catch (Throwable $failure) {
            if (str_contains($failure->getMessage(), $needle)) {
                fwrite(STDOUT, "ok: $message\n");
                return;
            }
            fwrite(STDERR, "FAIL: $message (unexpected: {$failure->getMessage()})\n");
            $failures++;
        }
    }

    $rowDecl = [
        'class' => 'authored_snapshot',
        'id_kind' => 'form',
        'pk' => 'id',
        'columns' => [
            'label' => ['class' => 'authored'],
            'runtime_flag' => ['class' => 'runtime'],
        ],
        'refs' => [['column' => 'parent_id', 'kind' => 'post']],
        'invalidate' => [
            ['table' => 'form_cache', 'column' => 'owner_id'],
            ['option_pattern' => 'form_{id}_cache'],
        ],
    ];
    $metaDecl = [
        'class' => 'authored_snapshot_meta',
        'attached_to' => ['table' => 'forms', 'column' => 'form_id'],
        'id_column' => 'id',
        'key_column' => 'meta_key',
        'value_column' => 'meta_value',
        'legacy_key_column' => 'key',
        'legacy_value_column' => 'value',
        'default_class' => 'authored',
        'keys' => [
            'parent_id' => ['class' => 'authored', 'ref' => 'post'],
            'runtime_key' => ['class' => 'runtime'],
            'portable_data' => ['class' => 'authored', 'plain_data' => true],
        ],
    ];
    $compositeDecl = [
        'class' => 'authored_snapshot',
        'id_kind' => 'membership_page',
        'identity' => ['mode' => 'composite_ref', 'columns' => ['membership_id', 'page_id']],
        'refs' => [
            ['column' => 'membership_id', 'kind' => 'level'],
            ['column' => 'page_id', 'kind' => 'post'],
        ],
        'columns' => ['note' => ['class' => 'authored']],
    ];
    $rowTables = ['forms' => $rowDecl, 'membership_pages' => $compositeDecl];
    $metaTables = ['form_meta' => $metaDecl];
    $ledger = ['form-uuid|form' => 5];
    $ledgerWrites = [];
    $cacheDeletes = [];

    $wpdb = new MaterializerWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $wpdb->tables = [
        'forms' => [
            'columns' => ['id' => 'bigint', 'label' => 'varchar(255)', 'runtime_flag' => 'varchar(32)', 'parent_id' => 'bigint'],
            'rows' => [],
        ],
        'form_meta' => [
            'columns' => ['id' => 'bigint', 'form_id' => 'bigint', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext', 'key' => 'varchar(255)', 'value' => 'longtext'],
            'rows' => [
                ['id' => 101, 'form_id' => 5, 'meta_key' => 'stale', 'meta_value' => 'old'],
                ['id' => 102, 'form_id' => 5, 'meta_key' => 'runtime_key', 'meta_value' => 'keep-runtime'],
                ['id' => 103, 'form_id' => 5, 'meta_key' => 'kept', 'meta_value' => 'old-kept'],
            ],
        ],
        'form_cache' => [
            'columns' => ['owner_id' => 'bigint'],
            'rows' => [['owner_id' => 5]],
        ],
        'options' => [
            'columns' => ['option_id' => 'bigint', 'option_name' => 'varchar(255)'],
            'rows' => [['option_id' => 9, 'option_name' => 'form_5_cache']],
        ],
        'membership_pages' => [
            'columns' => ['membership_id' => 'bigint', 'page_id' => 'bigint', 'note' => 'varchar(255)'],
            'rows' => [],
        ],
    ];

    $materializer = new TypedTableMaterializer(
        static fn(): array => $rowTables,
        static fn(): array => $metaTables,
        static function (string $uuid, string $kind) use (&$ledger): ?int {
            return $ledger["$uuid|$kind"] ?? null;
        },
        static function (string $uuid, string $table, string $kind, int $localId) use (&$ledger, &$ledgerWrites): void {
            $ledger["$uuid|$kind"] = $localId;
            $ledgerWrites[] = [$uuid, $table, $kind, $localId];
        },
        static fn(string $table, array $columns): int => ($columns['membership_id'] * 1000) + $columns['page_id'],
        static fn(int $packed): array => [intdiv($packed, 1000), $packed % 1000],
        static fn(array $decl, string $key): bool => $key !== 'outside',
        static fn($value): string => serialize($value),
        static function (string $key, string $group) use (&$cacheDeletes): void {
            $cacheDeletes[] = "$group:$key";
        }
    );
    materializer_check(class_exists(TypedTableMaterializer::class, false), 'materializer loads standalone');
    materializer_check(!class_exists(Duo\Snapshot::class, false) && !class_exists(Duo\Policy::class, false),
        'standalone materializer does not pull in Snapshot or Policy');

    $tokens = new MaterializerTokens();
    $tokens->ids = [
        '{{post:parent}}' => 77,
        '{{post:new-parent}}' => 88,
        '{{level:one}}' => 9,
        '{{post:page}}' => 10,
    ];
    $ordinary = [
        'type' => 'forms',
        'data' => [
            'uuid' => 'form-uuid',
            'columns' => [
                'label' => 'Visit {{site:url}}',
                'runtime_flag' => 'must-not-write',
                'parent_id' => '{{post:parent}}',
            ],
            'meta' => [
                'kept' => 'new {{site:url}}',
                'parent_id' => '{{post:parent}}',
                'new_key' => 'new-value',
                'portable_data' => [
                    'url' => '{{site:url}}/nested?field=1',
                    'choices' => [['label' => 'Tokyo', 'selected' => false]],
                ],
            ],
        ],
    ];

    materializer_check($materializer->ensureRow($ordinary), 'phase 1 recreates a missing retained row');
    $phaseOne = $wpdb->tables['forms']['rows'][0] ?? [];
    materializer_check(
        ($phaseOne['id'] ?? null) === 5
            && ($phaseOne['label'] ?? null) === 'Visit {{site:url}}'
            && ($phaseOne['parent_id'] ?? null) === 0
            && !array_key_exists('runtime_flag', $phaseOne),
        'phase 1 writes exact authored bytes, zero refs, reused identity, and no runtime column'
    );
    materializer_check(!$materializer->ensureRow($ordinary), 'phase 1 is idempotent after the retained row exists');

    $materializer->finalizeRow($tokens, $ordinary);
    $finalRow = $wpdb->tables['forms']['rows'][0] ?? [];
    materializer_check(
        ($finalRow['label'] ?? null) === 'Visit https://target.test'
            && ($finalRow['parent_id'] ?? null) === 77,
        'phase 2 detokenizes authored text and resolves structural refs'
    );
    $metaByKey = [];
    foreach ($wpdb->tables['form_meta']['rows'] as $row) {
        $metaByKey[$row['meta_key']] = $row;
    }
    materializer_check(!isset($metaByKey['stale']) && isset($metaByKey['runtime_key']),
        'attached-meta reconciliation deletes stale authored keys and preserves runtime keys');
    materializer_check(
        ($metaByKey['kept']['meta_value'] ?? null) === 'new https://target.test'
            && ($metaByKey['kept']['value'] ?? null) === 'new https://target.test'
            && ($metaByKey['parent_id']['meta_value'] ?? null) === '77'
            && ($metaByKey['new_key']['meta_value'] ?? null) === 'new-value',
        'attached-meta reconciliation updates, inserts, resolves refs, and writes legacy mirrors'
    );
    $portableExpected = serialize([
        'url' => 'https://target.test/nested?field=1',
        'choices' => [['label' => 'Tokyo', 'selected' => false]],
    ]);
    materializer_check(
        ($metaByKey['portable_data']['meta_value'] ?? null) === $portableExpected
            && ($metaByKey['portable_data']['value'] ?? null) === $portableExpected,
        'attached plain data rebinds nested strings before canonical serialization and legacy mirroring'
    );
    materializer_check($wpdb->tables['form_cache']['rows'] === [] && $wpdb->tables['options']['rows'] === [],
        'declared table and option invalidations execute after row reconciliation');
    materializer_check($cacheDeletes === ['options:form_5_cache', 'options:alloptions'],
        'option invalidation clears exact and alloptions caches');

    materializer_throws(
        fn() => $materializer->finalizeRow($tokens, [
            'type' => 'forms',
            'data' => ['uuid' => 'form-uuid', 'columns' => $ordinary['data']['columns'], 'meta' => ['outside' => 'x']],
        ]),
        'outside its declared keyspace',
        'repository meta outside declared ownership refuses before it can be written'
    );

    $materializer->reparentLocalRow('forms', 5, 'parent_id', 88);
    $metaByKey = [];
    foreach ($wpdb->tables['form_meta']['rows'] as $row) {
        $metaByKey[$row['meta_key']] = $row;
    }
    materializer_check(
        ($wpdb->tables['forms']['rows'][0]['parent_id'] ?? null) === 88
            && ($metaByKey['parent_id']['meta_value'] ?? null) === '88'
            && ($metaByKey['parent_id']['value'] ?? null) === '88',
        'reparenting updates the structural row ref and its declared attached-meta mirror'
    );

    materializer_throws(
        fn() => $materializer->assertRowDeleted('forms', 5),
        'deletion verification failed',
        'delete verification refuses while the selected row remains'
    );
    $materializer->deleteRow('form-uuid', 'forms');
    $materializer->assertRowDeleted('forms', 5);
    materializer_check($wpdb->tables['forms']['rows'] === [] && $wpdb->tables['form_meta']['rows'] === [],
        'UUID deletion removes the ordinary row and every attached sidecar before verification passes');

    $composite = [
        'type' => 'membership_pages',
        'data' => [
            'uuid' => 'join-uuid',
            'columns' => [
                'membership_id' => '{{level:one}}',
                'page_id' => '{{post:page}}',
                'note' => 'Join {{site:url}}',
            ],
        ],
    ];
    materializer_check(!$materializer->ensureRow($composite), 'composite fact has no phase-1 placeholder');
    $materializer->finalizeRow($tokens, $composite);
    materializer_check(
        $wpdb->tables['membership_pages']['rows'] === [[
            'membership_id' => 9,
            'page_id' => 10,
            'note' => 'Join https://target.test',
        ]]
            && ($ledger['join-uuid|membership_page'] ?? null) === 9010,
        'composite phase 2 resolves target-local tuple ids, writes authored data, and records packed bookkeeping'
    );
    $composite['data']['columns']['note'] = 'Updated';
    $materializer->finalizeRow($tokens, $composite);
    materializer_check(
        count($wpdb->tables['membership_pages']['rows']) === 1
            && $wpdb->tables['membership_pages']['rows'][0]['note'] === 'Updated',
        'composite re-apply updates authored data without duplicating the fact'
    );
    $materializer->deleteRow('join-uuid', 'membership_pages');
    $materializer->assertRowDeleted('membership_pages', 9010);
    materializer_check($wpdb->tables['membership_pages']['rows'] === [],
        'composite deletion unpacks current-environment bookkeeping and verification passes');

    $snapshotSource = file_get_contents(__DIR__ . '/../../../../agent/src/Repository/Snapshot.php');
    materializer_check(is_string($snapshotSource)
        && str_contains($snapshotSource, "require_once __DIR__ . '/../Apply/TypedTableMaterializer.php';")
        && str_contains($snapshotSource, 'typed_table_materializer($policy)->ensureRow($entity)')
        && str_contains($snapshotSource, 'typed_table_materializer($policy)->finalizeRow($tokens, $entity)')
        && str_contains($snapshotSource, 'typed_table_materializer($policy)->deleteLocalRow($table, $localId)')
        && str_contains($snapshotSource, 'typed_table_materializer($policy)->reparentLocalRow($table, $localId, $column, $targetId)'),
        'Snapshot retains thin compatibility facades over the extracted write boundary');

    if ($failures !== 0) {
        fwrite(STDERR, "$failures typed-table materializer regression(s) failed\n");
        exit(1);
    }
    fwrite(STDOUT, "REGRESS_TYPED_TABLE_MATERIALIZER PASSED\n");
}
