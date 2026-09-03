<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;
use WPrism\WpCliChildProcess;

if (!class_exists(WpCliChildProcess::class, false)) {
    $wprismLayoutRoot = dirname(__DIR__, 5);
    $wprismAgentRoot = is_dir($wprismLayoutRoot . '/agent/src')
        ? $wprismLayoutRoot . '/agent'
        : (basename($wprismLayoutRoot) === 'agent' && is_dir($wprismLayoutRoot . '/src') ? $wprismLayoutRoot : null);
    if ($wprismAgentRoot === null) {
        throw new \RuntimeException('wprism: Rank Math provider cannot resolve the explicit source or embedded agent layout');
    }
    require_once $wprismAgentRoot . '/src/Kernel/WpCliChildProcess.php';
    unset($wprismLayoutRoot, $wprismAgentRoot);
}

/** Rank Math 1.0.277.x schema preparation and native internal-link repair. */
final class RankMathState extends ManifestProviderRuntime {
    private const CHILD_FORMAT = 'wprism-rank-math-link-rebuild/v1';

    private const FILTERS = [
        'rank_math/excluded_post_types',
        'rank_math/links/content',
        'rank_math/links/extract',
        'rank_math/links/is_external',
        'rank_math/links/link_type',
        'rank_math/links/process_post',
        'rank_math/links/save_links',
    ];

    private const SCHEMA_HOOKS = [
        'dbdelta_create_queries',
        'dbdelta_insert_queries',
        'dbdelta_queries',
        'rank_math/admin/after_create_tables',
        'rank_math/admin/create_tables',
    ];

    private const LINK_TABLES = [
        'rank_math_internal_links' => ['id', 'url', 'post_id', 'target_post_id', 'type'],
        'rank_math_internal_meta' => [
            'object_id',
            'internal_link_count',
            'external_link_count',
            'incoming_link_count',
        ],
    ];

    private const REQUIRED_TABLES = [
        'rank_math_internal_links' => [
            'columns' => [
                ['name' => 'id', 'type' => 'bigint unsigned', 'null' => false, 'default' => null, 'extra' => 'auto_increment'],
                ['name' => 'url', 'type' => 'varchar(255)', 'null' => false, 'default' => null, 'extra' => ''],
                ['name' => 'post_id', 'type' => 'bigint unsigned', 'null' => false, 'default' => null, 'extra' => ''],
                ['name' => 'target_post_id', 'type' => 'bigint unsigned', 'null' => false, 'default' => null, 'extra' => ''],
                ['name' => 'type', 'type' => 'varchar(8)', 'null' => false, 'default' => null, 'extra' => ''],
            ],
            'indexes' => [
                ['name' => 'PRIMARY', 'unique' => true, 'columns' => [['name' => 'id', 'sub_part' => null]]],
                ['name' => 'link_direction', 'unique' => false, 'columns' => [['name' => 'post_id', 'sub_part' => null], ['name' => 'type', 'sub_part' => null]]],
                ['name' => 'target_post_id', 'unique' => false, 'columns' => [['name' => 'target_post_id', 'sub_part' => null]]],
            ],
        ],
        'rank_math_internal_meta' => [
            'columns' => [
                ['name' => 'object_id', 'type' => 'bigint unsigned', 'null' => false, 'default' => null, 'extra' => ''],
                ['name' => 'internal_link_count', 'type' => 'int unsigned', 'null' => true, 'default' => '0', 'extra' => ''],
                ['name' => 'external_link_count', 'type' => 'int unsigned', 'null' => true, 'default' => '0', 'extra' => ''],
                ['name' => 'incoming_link_count', 'type' => 'int unsigned', 'null' => true, 'default' => '0', 'extra' => ''],
            ],
            'indexes' => [
                ['name' => 'PRIMARY', 'unique' => true, 'columns' => [['name' => 'object_id', 'sub_part' => null]]],
            ],
        ],
        'rank_math_redirections_cache' => [
            'columns' => [
                ['name' => 'id', 'type' => 'bigint unsigned', 'null' => false, 'default' => null, 'extra' => 'auto_increment'],
                ['name' => 'from_url', 'type' => 'text', 'null' => false, 'default' => null, 'extra' => '', 'binary_collation' => true],
                ['name' => 'redirection_id', 'type' => 'bigint unsigned', 'null' => false, 'default' => null, 'extra' => ''],
                ['name' => 'object_id', 'type' => 'bigint unsigned', 'null' => false, 'default' => '0', 'extra' => ''],
                ['name' => 'object_type', 'type' => 'varchar(10)', 'null' => false, 'default' => 'post', 'extra' => ''],
                ['name' => 'is_redirected', 'type' => 'tinyint', 'null' => false, 'default' => '0', 'extra' => ''],
            ],
            'indexes' => [
                ['name' => 'PRIMARY', 'unique' => true, 'columns' => [['name' => 'id', 'sub_part' => null]]],
                ['name' => 'redirection_id', 'unique' => false, 'columns' => [['name' => 'redirection_id', 'sub_part' => null]]],
            ],
        ],
        'rank_math_redirections' => [
            'columns' => [
                ['name' => 'id', 'type' => 'bigint unsigned', 'null' => false, 'default' => null, 'extra' => 'auto_increment'],
                ['name' => 'sources', 'type' => 'longtext', 'null' => false, 'default' => null, 'extra' => '', 'binary_collation' => true],
                ['name' => 'url_to', 'type' => 'text', 'null' => false, 'default' => null, 'extra' => ''],
                ['name' => 'header_code', 'type' => 'smallint unsigned', 'null' => false, 'default' => null, 'extra' => ''],
                ['name' => 'hits', 'type' => 'bigint unsigned', 'null' => false, 'default' => '0', 'extra' => ''],
                ['name' => 'status', 'type' => 'varchar(25)', 'null' => false, 'default' => 'active', 'extra' => ''],
                ['name' => 'created', 'type' => 'datetime', 'null' => false, 'default' => '0000-00-00 00:00:00', 'extra' => ''],
                ['name' => 'updated', 'type' => 'datetime', 'null' => false, 'default' => '0000-00-00 00:00:00', 'extra' => ''],
                ['name' => 'last_accessed', 'type' => 'datetime', 'null' => false, 'default' => '0000-00-00 00:00:00', 'extra' => ''],
            ],
            'indexes' => [
                ['name' => 'PRIMARY', 'unique' => true, 'columns' => [['name' => 'id', 'sub_part' => null]]],
                ['name' => 'idx_rm_status_updated', 'unique' => false, 'columns' => [['name' => 'status', 'sub_part' => null], ['name' => 'updated', 'sub_part' => null]]],
                ['name' => 'status', 'unique' => false, 'columns' => [['name' => 'status', 'sub_part' => null]]],
            ],
        ],
    ];

    private const MAX_PROJECTION_ROWS = 200000;
    private const MAX_PROJECTION_RAW_BYTES = 33554432;
    private const MAX_PROJECTION_ROW_RAW_BYTES = 1048576;
    private const MAX_PROJECTION_SERIALIZED_BYTES = 67108864;
    private const MAX_PROJECTION_ROW_SERIALIZED_BYTES = 2097152;
    private const MAX_PROJECTION_PAGE_RAW_BYTES = 8388608;
    private const MAX_PROJECTION_CHUNK_ROWS = 1024;
    private const MAX_SOURCE_POST_ROWS = 25000;
    private const MAX_ROUTE_IDENTITY_ROWS = 50000;
    private const MAX_VERIFICATION_IDENTITIES = 50000;
    private const MAX_NATIVE_RESOLUTION_LINKS = 200000;
    private const MAX_REGISTERED_POST_TYPES = 256;
    private const MAX_PUBLIC_QUERY_VARS = 2048;

    private const POST_WITNESS_COLUMNS = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'post_password',
        'post_name',
        'post_modified',
        'post_modified_gmt',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
    ];

    private const ROUTE_POST_COLUMNS = [
        'ID',
        'post_date',
        'post_date_gmt',
        'post_status',
        'post_name',
        'post_modified_gmt',
        'post_parent',
        'post_type',
    ];

    private const ROUTE_OPTION_NAMES = [
        'category_base',
        'close_comments_days_old',
        'close_comments_for_old_posts',
        'comments_per_page',
        'default_category',
        'home',
        'page_for_posts',
        'page_on_front',
        'permalink_structure',
        'polylang',
        'posts_per_page',
        'posts_per_rss',
        'rank-math-options-general',
        'rank_math_modules',
        'rewrite_rules',
        'show_on_front',
        'siteurl',
        'sticky_posts',
        'tag_base',
        'woocommerce_permalinks',
        'wp_page_for_privacy_policy',
    ];

    private const COMMENT_WITNESS_COLUMNS = [
        'comment_ID',
        'comment_post_ID',
        'comment_author',
        'comment_author_email',
        'comment_author_url',
        'comment_author_IP',
        'comment_date',
        'comment_date_gmt',
        'comment_content',
        'comment_karma',
        'comment_approved',
        'comment_agent',
        'comment_type',
        'comment_parent',
        'user_id',
    ];

    private const USER_WITNESS_COLUMNS = [
        'ID',
        'user_login',
        'user_pass',
        'user_nicename',
        'user_email',
        'user_url',
        'user_registered',
        'user_activation_key',
        'user_status',
        'display_name',
    ];

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_inspect_schema(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: Rank Math schema readiness accepts no arguments');
        }
        $projection = $this->schema_projection(false, null, true);
        return ['before' => $projection, 'after' => $projection, 'verified' => true];
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_prepare_schema(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: Rank Math schema preparation accepts no arguments');
        }
        foreach (self::SCHEMA_HOOKS as $hook) {
            if (has_filter($hook) !== false) {
                throw new \RuntimeException('wprism: Rank Math schema preparation refuses an unreviewed schema callback');
            }
        }
        $before = $this->schema_projection(false, null, true, true);
        \RankMath\Installer::create_tables(['link-counter', 'redirections']);
        $after = $this->schema_projection(true, null, false, true);
        foreach ($before as $table => $row) {
            if (($row['present'] ?? false) && $row !== ($after[$table] ?? null)) {
                throw new \RuntimeException(
                    "wprism: Rank Math schema preparation changed rows or structure in existing table '$table'"
                );
            }
        }

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_rebuild_all_link_state(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: Rank Math full link repair accepts no arguments');
        }
        $this->assert_link_schema();
        $before = $this->link_projection();
        $child = $this->run_child(570);
        $after = $this->link_projection();
        $this->assert_child_matches($child, $after);

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array<string,mixed> */
    protected function reconcile_rebuild_all_link_state(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: Rank Math full link repair accepts no arguments');
        }
        $this->assert_link_schema();
        return $this->scoped_link_projection();
    }

    /** @return array<string,int|string> */
    protected function project_rebuild_all_link_state(array $value): array {
        $projection = [];
        foreach (['link', 'meta', 'marker'] as $surface) {
            $count = $value[$surface . '_count'] ?? null;
            $hash = $value[$surface . '_hash'] ?? null;
            if (!is_int($count)
                || $count < 0
                || !is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException(
                    'wprism: Rank Math scoped link projection is malformed; recovery_required'
                );
            }
            $projection[$surface . '_count'] = $count;
            $projection[$surface . '_hash'] = $hash;
        }
        return $projection;
    }

    /** @return array<string,mixed> */
    /** @param ?list<string> $suffixes */
    private function schema_projection(
        bool $requireComplete,
        ?array $suffixes = null,
        bool $validatePresent = false,
        bool $includeRows = false
    ): array {
        global $wpdb;
        $snapshotStarted = false;
        if ($includeRows) {
            self::snapshot_control(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                'could not establish repeatable-read schema evidence'
            );
            self::snapshot_control(
                'START TRANSACTION WITH CONSISTENT SNAPSHOT',
                'could not start the schema evidence snapshot'
            );
            $snapshotStarted = true;
        }
        $projection = [];
        try {
            foreach (self::REQUIRED_TABLES as $suffix => $required) {
                if ($suffixes !== null && !in_array($suffix, $suffixes, true)) {
                    continue;
                }
                $table = $wpdb->prefix . $suffix;
                $found = ProviderSdk::checked_get_var(
                    $wpdb->prepare('SHOW TABLES LIKE %s', $table),
                    'Rank Math schema table discovery',
                    $wpdb
                );
                if (!is_string($found) || $found !== $table) {
                    if ($requireComplete) {
                        throw new \RuntimeException('wprism: Rank Math schema preparation did not create every required table');
                    }
                    $projection[$suffix] = ['present' => false, 'schema_hash' => null];
                    if ($includeRows) {
                        $projection[$suffix]['row_count'] = 0;
                        $projection[$suffix]['rows_sha256'] = null;
                    }
                    continue;
                }
                $columns = $this->normalized_columns(ProviderSdk::checked_get_results(
                    "SHOW FULL COLUMNS FROM `$table`",
                    'Rank Math schema column readback',
                    $wpdb
                ));
                $indexes = $this->normalized_indexes(ProviderSdk::checked_get_results(
                    "SHOW INDEX FROM `$table`",
                    'Rank Math schema index readback',
                    $wpdb
                ));
                $observed = ['columns' => $columns, 'indexes' => $indexes];
                if (($requireComplete || $validatePresent) && $observed !== $required) {
                    throw new \RuntimeException('wprism: Rank Math schema disagrees with the audited column/index contract');
                }
                $projection[$suffix] = [
                    'present' => true,
                    'schema_hash' => hash('sha256', serialize($observed)),
                ];
                if ($includeRows) {
                    $status = ProviderSdk::checked_get_results(
                        $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table),
                        'Rank Math schema storage-engine readback',
                        $wpdb
                    );
                    if (count($status) !== 1
                        || !is_string($status[0]['Engine'] ?? null)
                        || strcasecmp((string) $status[0]['Engine'], 'InnoDB') !== 0) {
                        throw new \RuntimeException(
                            "wprism: Rank Math schema content witness requires InnoDB for '$suffix'"
                        );
                    }
                    $projection[$suffix] += $this->schema_row_projection($suffix, $table, $required);
                }
            }
            if ($snapshotStarted) {
                self::snapshot_control('COMMIT', 'could not commit the schema evidence snapshot');
                $snapshotStarted = false;
            }
            return $projection;
        } catch (\Throwable $failure) {
            if ($snapshotStarted) {
                try {
                    self::snapshot_control('ROLLBACK', 'could not roll back the schema evidence snapshot');
                } catch (\Throwable $_rollbackFailure) {
                    // Preserve the first refusal. Provider debt remains bound to
                    // the exact checkpoint when transaction cleanup is uncertain.
                }
            }
            throw $failure;
        }
    }

    private static function snapshot_control(string $sql, string $failure): void {
        global $wpdb;
        $wpdb->last_error = '';
        $result = $wpdb->query($sql);
        if ($result === false || $wpdb->last_error !== '') {
            throw new \RuntimeException("wprism: Rank Math $failure");
        }
    }

    /**
     * Complete, primary-key-ordered content witness for a table the native
     * installer is allowed to inspect but never to seed or rewrite.
     *
     * @param array<string,mixed> $required
     * @return array{row_count:int,rows_sha256:string}
     */
    private function schema_row_projection(string $suffix, string $table, array $required): array {
        global $wpdb;
        $primary = [];
        foreach ((array) ($required['indexes'] ?? []) as $index) {
            if (($index['name'] ?? null) !== 'PRIMARY') {
                continue;
            }
            foreach ((array) ($index['columns'] ?? []) as $column) {
                $name = (string) ($column['name'] ?? '');
                if (preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $name) !== 1) {
                    throw new \RuntimeException(
                        "wprism: Rank Math schema content witness has no safe primary key for '$suffix'"
                    );
                }
                $primary[] = $name;
            }
        }
        if (count($primary) !== 1) {
            throw new \RuntimeException(
                "wprism: Rank Math schema content witness requires one audited primary key for '$suffix'"
            );
        }
        $columns = [];
        foreach ((array) ($required['columns'] ?? []) as $column) {
            $name = (string) ($column['name'] ?? '');
            if (preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $name) !== 1) {
                throw new \RuntimeException(
                    "wprism: Rank Math schema content witness has an unsafe column for '$suffix'"
                );
            }
            $columns[] = $name;
        }
        if ($columns === []) {
            throw new \RuntimeException(
                "wprism: Rank Math schema content witness has no audited columns for '$suffix'"
            );
        }
        $rowBytes = implode(' + ', array_map(
            static fn(string $column): string => "COALESCE(OCTET_LENGTH(`$column`), 0)",
            $columns
        ));
        $boundsRows = ProviderSdk::checked_get_results(
            "SELECT COUNT(*) AS row_count, "
                . "COALESCE(SUM($rowBytes), 0) AS total_bytes, "
                . "COALESCE(MAX($rowBytes), 0) AS max_row_bytes FROM `$table`",
            'Rank Math schema content-bound readback',
            $wpdb
        );
        $bounds = count($boundsRows) === 1 ? $boundsRows[0] : null;
        $count = is_array($bounds) ? ($bounds['row_count'] ?? null) : null;
        $totalBytes = is_array($bounds) ? ($bounds['total_bytes'] ?? null) : null;
        $maxRowBytes = is_array($bounds) ? ($bounds['max_row_bytes'] ?? null) : null;
        if (!self::bounded_decimal($count, self::MAX_PROJECTION_ROWS)) {
            throw new \RuntimeException(
                "wprism: Rank Math schema content witness exceeds the bounded row projection for '$suffix'"
            );
        }
        if (!self::bounded_decimal($totalBytes, self::MAX_PROJECTION_RAW_BYTES)
            || !self::bounded_decimal($maxRowBytes, self::MAX_PROJECTION_ROW_RAW_BYTES)) {
            throw new \RuntimeException(
                "wprism: Rank Math schema content witness exceeds the bounded byte projection for '$suffix'"
            );
        }
        $expectedRows = (int) $count;
        $largestRowBytes = max(1, (int) $maxRowBytes);
        $chunkRows = min(
            self::MAX_PROJECTION_CHUNK_ROWS,
            max(1, intdiv(self::MAX_PROJECTION_PAGE_RAW_BYTES, $largestRowBytes))
        );
        $primaryColumn = $primary[0];
        $select = implode(', ', array_map(
            static fn(string $column): string => "`$column`",
            $columns
        ));
        $digest = hash_init('sha256');
        $observedRows = 0;
        $serializedBytes = 0;
        $lastPrimary = null;
        while ($observedRows < $expectedRows) {
            // The snapshot census makes the page size data-dependent: tiny
            // rows reach 1,024/query while a 1 MiB row keeps the transfer at
            // eight/query. Reapply the row bound in SQL so even a non-MVCC
            // regression cannot move oversized bytes into the provider heap.
            $where = ' WHERE (' . $rowBytes . ') <= ' . self::MAX_PROJECTION_ROW_RAW_BYTES;
            if ($lastPrimary !== null) {
                $where .= $wpdb->prepare(" AND `$primaryColumn` > %s", $lastPrimary);
            }
            $rows = ProviderSdk::checked_get_results(
                "SELECT $select FROM `$table`$where ORDER BY `$primaryColumn` "
                    . 'LIMIT ' . $chunkRows,
                'Rank Math schema row-chunk readback',
                $wpdb
            );
            if ($rows === [] || count($rows) > $chunkRows) {
                throw new \RuntimeException(
                    "wprism: Rank Math schema content changed during the bounded witness for '$suffix'"
                );
            }
            foreach ($rows as $row) {
                $primaryValue = $row[$primaryColumn] ?? null;
                if (!is_string($primaryValue)
                    || $primaryValue === ''
                    || ($lastPrimary !== null && hash_equals($lastPrimary, $primaryValue))) {
                    throw new \RuntimeException(
                        "wprism: Rank Math schema content witness saw an invalid primary key for '$suffix'"
                    );
                }
                $encoded = serialize($row);
                $rowSerializedBytes = strlen($encoded);
                $serializedBytes += $rowSerializedBytes;
                if ($rowSerializedBytes > self::MAX_PROJECTION_ROW_SERIALIZED_BYTES
                    || $serializedBytes > self::MAX_PROJECTION_SERIALIZED_BYTES) {
                    throw new \RuntimeException(
                        "wprism: Rank Math schema content witness exceeds the bounded serialized projection for '$suffix'"
                    );
                }
                hash_update($digest, $encoded);
                $lastPrimary = $primaryValue;
                $observedRows++;
                if ($observedRows > $expectedRows) {
                    throw new \RuntimeException(
                        "wprism: Rank Math schema content changed during the bounded witness for '$suffix'"
                    );
                }
            }
        }
        if ($observedRows !== $expectedRows) {
            throw new \RuntimeException(
                "wprism: Rank Math schema content changed during the bounded witness for '$suffix'"
            );
        }
        return [
            'row_count' => $observedRows,
            'rows_sha256' => hash_final($digest),
        ];
    }

    private static function bounded_decimal(mixed $value, int $maximum): bool {
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return false;
        }
        $bound = (string) $maximum;
        return strlen($value) < strlen($bound)
            || (strlen($value) === strlen($bound) && strcmp($value, $bound) <= 0);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalized_columns(array $rows): array {
        $columns = [];
        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['Type'] ?? '')));
            $type = (string) preg_replace('/\b(bigint|int|smallint|tinyint)\(\d+\)/', '$1', $type);
            $type = (string) preg_replace('/\s+/', ' ', $type);
            $column = [
                'name' => (string) ($row['Field'] ?? ''),
                'type' => $type,
                'null' => strtoupper((string) ($row['Null'] ?? '')) === 'YES',
                'default' => ($row['Default'] ?? null) === null ? null : (string) $row['Default'],
                'extra' => strtolower(trim((string) ($row['Extra'] ?? ''))),
            ];
            if (str_ends_with(strtolower((string) ($row['Collation'] ?? '')), '_bin')) {
                $column['binary_collation'] = true;
            }
            $columns[] = $column;
        }
        return $columns;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalized_indexes(array $rows): array {
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            $column = (string) ($row['Column_name'] ?? '');
            $sequence = (int) ($row['Seq_in_index'] ?? 0);
            if ($name === '' || $column === '' || $sequence <= 0
                || strtoupper((string) ($row['Index_type'] ?? 'BTREE')) !== 'BTREE') {
                throw new \RuntimeException('wprism: Rank Math schema exposes an unsupported index shape');
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                    'columns' => [],
                ];
            }
            if ($indexes[$name]['unique'] !== ((int) ($row['Non_unique'] ?? 1) === 0)
                || isset($indexes[$name]['columns'][$sequence])) {
                throw new \RuntimeException('wprism: Rank Math schema exposes an inconsistent index definition');
            }
            $subPart = $row['Sub_part'] ?? null;
            $indexes[$name]['columns'][$sequence] = [
                'name' => $column,
                'sub_part' => $subPart === null ? null : (int) $subPart,
            ];
        }
        ksort($indexes, SORT_STRING);
        foreach ($indexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);
        return array_values($indexes);
    }

    private function assert_link_schema(): void {
        $projection = $this->schema_projection(true, array_keys(self::LINK_TABLES));
        foreach (array_keys(self::LINK_TABLES) as $table) {
            if (($projection[$table]['present'] ?? null) !== true) {
                throw new \RuntimeException('wprism: Rank Math link-counter schema is unavailable');
            }
        }
    }

    /** @return array{object,string} */
    private function audited_native_exclusion_callback(): array {
        $native = null;
        foreach (self::FILTERS as $filter) {
            if ($filter !== 'rank_math/excluded_post_types') {
                if (has_filter($filter) !== false) {
                    throw new \RuntimeException('wprism: Rank Math link repair refuses an unreviewed link-processing callback');
                }
                continue;
            }
            $registry = $GLOBALS['wp_filter'] ?? null;
            if (!is_array($registry)) {
                throw new \RuntimeException(
                    'wprism: Rank Math link repair requires exactly its audited native exclusion callback'
                );
            }
            if (!array_key_exists($filter, $registry)) {
                // rank-math.php:291-299 returns before Common constructs
                // Defaults on a virgin pre-apply target. Host lifecycle
                // settlement necessarily precedes authored option apply, so
                // initialize only this exact native class when the hook is
                // wholly absent; the strict shape audit below still refuses
                // every existing or constructor-produced substitution.
                new \RankMath\Defaults();
            }
            $hook = $GLOBALS['wp_filter'][$filter] ?? null;
            $callbacks = $hook instanceof \WP_Hook ? $hook->callbacks : null;
            $priority = is_array($callbacks) && array_keys($callbacks) === [10]
                ? ($callbacks[10] ?? null)
                : null;
            $entry = is_array($priority) && count($priority) === 1 ? reset($priority) : null;
            $entryKeys = is_array($entry) ? array_keys($entry) : [];
            sort($entryKeys, SORT_STRING);
            $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
            if (!is_array($entry)
                || $entryKeys !== ['accepted_args', 'function']
                || ($entry['accepted_args'] ?? null) !== 1
                || !is_array($callback)
                || count($callback) !== 2
                || !is_object($callback[0] ?? null)
                || get_class($callback[0]) !== \RankMath\Defaults::class
                || ($callback[1] ?? null) !== 'excluded_post_types') {
                throw new \RuntimeException(
                    'wprism: Rank Math link repair requires exactly its audited native exclusion callback'
                );
            }
            $native = [$callback[0], 'excluded_post_types'];
        }
        if ($native === null) {
            throw new \RuntimeException('wprism: Rank Math native exclusion callback is unavailable');
        }
        return $native;
    }

    /** @param array{object,string} $native @return array<string,string> */
    private function accessible_post_types(array $native): array {
        if (!class_exists('RankMath\\Helper')
            || !is_callable(['RankMath\\Helper', 'get_accessible_post_types'])) {
            throw new \RuntimeException('wprism: Rank Math accessible post-type API is unavailable');
        }
        $helperTypes = \RankMath\Helper::get_accessible_post_types();
        $derivedTypes = get_post_types(['public' => true]);
        if (!is_array($helperTypes) || !is_array($derivedTypes)) {
            throw new \RuntimeException('wprism: Rank Math accessible post-type inventory is malformed');
        }
        $derivedTypes = array_filter($derivedTypes, 'is_post_type_viewable');
        $derivedTypes = call_user_func($native, $derivedTypes);
        if (!is_array($derivedTypes)) {
            throw new \RuntimeException('wprism: Rank Math native post-type exclusion returned malformed state');
        }
        ksort($helperTypes, SORT_STRING);
        ksort($derivedTypes, SORT_STRING);
        if ($helperTypes !== $derivedTypes) {
            throw new \RuntimeException(
                'wprism: Rank Math cached accessible post types disagree with the current audited topology'
            );
        }
        unset($derivedTypes['attachment']);
        foreach ($derivedTypes as $name => $value) {
            if (!is_string($name)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/D', $name) !== 1
                || $value !== $name) {
                throw new \RuntimeException('wprism: Rank Math accessible post-type inventory is malformed');
            }
        }
        return $derivedTypes;
    }

    /** @param list<string> $tables */
    private function assert_innodb_projection_tables(array $tables): void {
        global $wpdb;
        foreach ($tables as $table) {
            $status = ProviderSdk::checked_get_results(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table),
                'Rank Math projection storage-engine readback',
                $wpdb
            );
            if (count($status) !== 1
                || !is_string($status[0]['Engine'] ?? null)
                || strcasecmp((string) $status[0]['Engine'], 'InnoDB') !== 0) {
                throw new \RuntimeException(
                    "wprism: Rank Math complete link projection requires InnoDB for '$table'"
                );
            }
        }
    }

    private function native_content_processor(): object {
        $class = 'RankMath\\Links\\ContentProcessor';
        if (!class_exists($class)) {
            throw new \RuntimeException('wprism: Rank Math native content-processor API is unavailable');
        }
        $processor = new $class();
        if (!is_callable([$processor, 'extract'])
            || !is_callable([$processor, 'normalize_link'])
            || !is_callable([$processor, 'is_valid_link_type'])
            || !is_callable([$processor, 'maybe_product_id'])) {
            throw new \RuntimeException('wprism: Rank Math native content-processor API is unavailable');
        }
        return $processor;
    }

    /**
     * Stream a complete repeatable-read projection in bounded pages. No call
     * retains a table-sized row array; the only durable value is its digest.
     *
     * @param list<string> $columns
     * @param null|callable(array<string,mixed>):void $visitor
     * @return array{row_count:int,rows_sha256:string}
     */
    private function stream_projection(
        string $table,
        array $columns,
        string $where,
        string $order,
        string $label,
        int $maximumRows = self::MAX_PROJECTION_ROWS,
        ?callable $visitor = null
    ): array {
        global $wpdb;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1 || $columns === []) {
            throw new \RuntimeException('wprism: Rank Math bounded projection has an unsafe table contract');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
                throw new \RuntimeException('wprism: Rank Math bounded projection has an unsafe column contract');
            }
        }
        $rowBytes = implode(' + ', array_map(
            static fn(string $column): string => "COALESCE(OCTET_LENGTH(`$column`), 0)",
            $columns
        ));
        $from = "FROM `$table`" . ($where === '' ? '' : " $where");
        $boundsRows = ProviderSdk::checked_get_results(
            "SELECT COUNT(*) AS row_count, COALESCE(SUM($rowBytes), 0) AS total_bytes, "
                . "COALESCE(MAX($rowBytes), 0) AS max_row_bytes $from",
            "$label bound readback",
            $wpdb
        );
        $bounds = count($boundsRows) === 1 ? $boundsRows[0] : null;
        $count = is_array($bounds) ? ($bounds['row_count'] ?? null) : null;
        $totalBytes = is_array($bounds) ? ($bounds['total_bytes'] ?? null) : null;
        $maxRowBytes = is_array($bounds) ? ($bounds['max_row_bytes'] ?? null) : null;
        if (!self::bounded_decimal($count, $maximumRows)) {
            throw new \RuntimeException("wprism: $label exceeds its reviewed row bound");
        }
        if (!self::bounded_decimal($totalBytes, self::MAX_PROJECTION_RAW_BYTES)
            || !self::bounded_decimal($maxRowBytes, self::MAX_PROJECTION_ROW_RAW_BYTES)) {
            throw new \RuntimeException("wprism: $label exceeds its reviewed byte bound");
        }
        $select = implode(', ', array_map(
            static fn(string $column): string => "`$column`",
            $columns
        ));
        $expectedRows = (int) $count;
        $largestRowBytes = max(1, (int) $maxRowBytes);
        $chunkRows = min(
            self::MAX_PROJECTION_CHUNK_ROWS,
            max(1, intdiv(self::MAX_PROJECTION_PAGE_RAW_BYTES, $largestRowBytes))
        );
        $digest = hash_init('sha256');
        $observedRows = 0;
        $serializedBytes = 0;
        while ($observedRows < $expectedRows) {
            $valueWhere = $where === '' ? 'WHERE' : "$where AND";
            $rows = ProviderSdk::checked_get_results(
                "SELECT $select FROM `$table` $valueWhere ($rowBytes) <= "
                    . self::MAX_PROJECTION_ROW_RAW_BYTES . " ORDER BY $order LIMIT $chunkRows OFFSET $observedRows",
                $label,
                $wpdb
            );
            if ($rows === [] || count($rows) > $chunkRows) {
                throw new \RuntimeException("wprism: $label changed during its bounded projection");
            }
            foreach ($rows as $row) {
                $encoded = serialize($row);
                $serializedRowBytes = strlen($encoded);
                $serializedBytes += $serializedRowBytes;
                if ($serializedRowBytes > self::MAX_PROJECTION_ROW_SERIALIZED_BYTES
                    || $serializedBytes > self::MAX_PROJECTION_SERIALIZED_BYTES) {
                    throw new \RuntimeException("wprism: $label exceeds its reviewed serialized bound");
                }
                hash_update($digest, $encoded);
                if ($visitor !== null) {
                    $visitor($row);
                }
                $observedRows++;
                if ($observedRows > $expectedRows) {
                    throw new \RuntimeException("wprism: $label changed during its bounded projection");
                }
            }
        }
        if ($observedRows !== $expectedRows) {
            throw new \RuntimeException("wprism: $label changed during its bounded projection");
        }
        return ['row_count' => $observedRows, 'rows_sha256' => hash_final($digest)];
    }

    /**
     * Bind the effective core resolution that Rank Math consumes, rather than
     * assuming its permalink inputs stop at the posts table. Core can consult
     * author, category, rewrite and registered-query-var state, while plugins
     * can filter the resulting routes; parent readback repeats these outputs.
     *
     * @param array<string,string> $types
     * @return array{
     *   authors:list<int>,
     *   projection:array{row_count:int,rows_sha256:string},
     *   resolution:array{link_count:int,resolution_sha256:string}
     * }
     */
    private function source_dependency_projection(array $types): array {
        global $wpdb;
        $authors = [];
        $resolution = hash_init('sha256');
        $linkCount = 0;
        $candidateCount = 0;
        if ($types === []) {
            return [
                'authors' => [],
                'projection' => [
                    'row_count' => 0,
                    'rows_sha256' => hash('sha256', ''),
                ],
                'resolution' => [
                    'link_count' => 0,
                    'resolution_sha256' => hash_final($resolution),
                ],
            ];
        }
        if (!class_exists('RankMath\\Links\\Links')
            || !is_callable(['RankMath\\Links\\Links', 'is_post_processable'])) {
            throw new \RuntimeException('wprism: Rank Math link-counter API is unavailable');
        }
        $processor = $this->native_content_processor();
        $quoted = array_map(
            static fn(string $type): string => "'" . esc_sql($type) . "'",
            array_keys($types)
        );
        $projection = $this->stream_projection(
            $wpdb->posts,
            self::POST_WITNESS_COLUMNS,
            'WHERE post_type IN (' . implode(',', $quoted) . ')',
            'ID',
            'Rank Math accessible-post projection',
            self::MAX_SOURCE_POST_ROWS,
            static function (array $row) use (
                &$authors,
                &$resolution,
                &$linkCount,
                &$candidateCount,
                $processor
            ): void {
                $id = (int) ($row['ID'] ?? 0);
                $author = (int) ($row['post_author'] ?? 0);
                if ($id <= 0 || $author < 0) {
                    throw new \RuntimeException(
                        'wprism: Rank Math accessible-post projection contains an invalid identity'
                    );
                }
                if ($author > 0) {
                    $authors[$author] = true;
                }
                $post = new \WP_Post((object) $row);
                if (!\RankMath\Links\Links::is_post_processable($post)) {
                    return;
                }
                $content = $row['post_content'] ?? null;
                $permalink = get_permalink($id);
                if (!is_string($content)
                    || !is_string($permalink)
                    || $permalink === '') {
                    throw new \RuntimeException('wprism: Rank Math native link expectation has malformed source state');
                }
                $links = $processor->extract(str_replace(']]>', ']]&gt;', $content));
                $postPermalink = $processor->normalize_link($permalink);
                if (!is_array($links) || !is_string($postPermalink)) {
                    throw new \RuntimeException('wprism: Rank Math native link expectation is malformed');
                }
                $postEdgeCount = 0;
                foreach ($links as $link) {
                    $candidateCount++;
                    if ($candidateCount > self::MAX_NATIVE_RESOLUTION_LINKS) {
                        throw new \RuntimeException(
                            'wprism: Rank Math native link expectation exceeds its reviewed identity bound'
                        );
                    }
                    if (!is_string($link)) {
                        throw new \RuntimeException('wprism: Rank Math native link expectation is malformed');
                    }
                    $normalized = $processor->normalize_link($link);
                    if (!is_string($normalized)) {
                        throw new \RuntimeException('wprism: Rank Math native link expectation is malformed');
                    }
                    if ($postPermalink === $normalized) {
                        continue;
                    }
                    $type = $processor->is_valid_link_type($link);
                    if (empty($type)) {
                        continue;
                    }
                    if ($type !== 'internal' && $type !== 'external') {
                        throw new \RuntimeException('wprism: Rank Math native link expectation has an invalid type');
                    }
                    $target = 0;
                    if ($type === 'internal') {
                        $target = (int) url_to_postid($link);
                        if ($target === 0) {
                            $target = (int) $processor->maybe_product_id($link);
                        }
                        if ($target < 0) {
                            throw new \RuntimeException('wprism: Rank Math native link expectation has an invalid target');
                        }
                    }
                    $linkCount++;
                    $postEdgeCount++;
                    if ($linkCount > self::MAX_NATIVE_RESOLUTION_LINKS) {
                        throw new \RuntimeException(
                            'wprism: Rank Math native link expectation exceeds its reviewed identity bound'
                        );
                    }
                    hash_update($resolution, serialize([
                        'url' => $link,
                        'post_id' => $id,
                        'target_post_id' => $target,
                        'type' => $type,
                    ]));
                }
                hash_update($resolution, serialize(['post_id' => $id, 'edge_count' => $postEdgeCount]));
            }
        );
        $authorIds = array_map('intval', array_keys($authors));
        sort($authorIds, SORT_NUMERIC);
        return [
            'authors' => $authorIds,
            'projection' => $projection,
            'resolution' => [
                'link_count' => $linkCount,
                'resolution_sha256' => hash_final($resolution),
            ],
        ];
    }

    /** @param array<string,string> $types @return array<string,mixed> */
    private function route_runtime_projection(array $types): array {
        global $wp, $wp_post_types, $wp_rewrite;
        if (!is_array($wp_post_types)
            || count($wp_post_types) > self::MAX_REGISTERED_POST_TYPES) {
            throw new \RuntimeException('wprism: Rank Math registered post-type topology is malformed or unbounded');
        }
        $postTypes = [];
        $registeredPostTypes = $wp_post_types;
        ksort($registeredPostTypes, SORT_STRING);
        foreach ($registeredPostTypes as $name => $object) {
            if (!is_string($name)
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/D', $name) !== 1
                || !is_object($object)) {
                throw new \RuntimeException('wprism: Rank Math registered post-type topology is malformed or unbounded');
            }
            $rewrite = $object->rewrite ?? false;
            if (!is_bool($rewrite) && !is_array($rewrite)) {
                throw new \RuntimeException("wprism: Rank Math route topology for '$name' is malformed");
            }
            $postTypes[$name] = [
                'has_archive' => $object->has_archive ?? false,
                'hierarchical' => (bool) ($object->hierarchical ?? false),
                'publicly_queryable' => (bool) ($object->publicly_queryable ?? false),
                'query_var' => $object->query_var ?? false,
                'rewrite' => $rewrite,
            ];
        }
        foreach (array_keys($types) as $name) {
            if (!isset($postTypes[$name])) {
                throw new \RuntimeException("wprism: Rank Math route topology lost post type '$name'");
            }
        }
        $publicQueryVars = is_object($wp) ? ($wp->public_query_vars ?? null) : null;
        if (!is_array($publicQueryVars) || count($publicQueryVars) > self::MAX_PUBLIC_QUERY_VARS) {
            throw new \RuntimeException('wprism: Rank Math public query-var topology is malformed or unbounded');
        }
        foreach ($publicQueryVars as $queryVar) {
            if (!is_string($queryVar)
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $queryVar) !== 1) {
                throw new \RuntimeException('wprism: Rank Math public query-var topology is malformed or unbounded');
            }
        }
        $permalinkStructure = get_option('permalink_structure', '');
        $storedRules = get_option('rewrite_rules', null);
        $runtimeRules = is_object($wp_rewrite) ? ($wp_rewrite->rules ?? null) : null;
        if (!is_string($permalinkStructure)) {
            throw new \RuntimeException('wprism: Rank Math permalink structure is malformed');
        }
        if ($permalinkStructure !== ''
            && is_array($storedRules)
            && $storedRules !== []
            && $runtimeRules === null) {
            // A virgin WP-CLI request can hold WordPress's exact non-empty
            // effective option while its public lazy cache is still null.
            // Copy only those same bytes into the exact core object: this is
            // request-local and hook-free, so it cannot regenerate or persist
            // rules; empty, malformed, extended and divergent states retain
            // the strict refusal below.
            if (!is_object($wp_rewrite) || get_class($wp_rewrite) !== \WP_Rewrite::class) {
                throw new \RuntimeException(
                    'wprism: Rank Math pretty-permalink repair requires the exact core rewrite runtime'
                );
            }
            $wp_rewrite->rules = $storedRules;
            $runtimeRules = $wp_rewrite->rules;
        }
        if ($permalinkStructure !== ''
            && (!is_array($runtimeRules)
                || $runtimeRules === []
                || !is_array($storedRules)
                || $storedRules === []
                || $storedRules !== $runtimeRules)) {
            throw new \RuntimeException(
                'wprism: Rank Math pretty-permalink repair requires exact non-empty stored and loaded rewrite rules'
            );
        }
        if (!is_array($runtimeRules) && $runtimeRules !== null && $runtimeRules !== '') {
            throw new \RuntimeException('wprism: Rank Math loaded rewrite-rule topology is malformed');
        }
        $home = home_url();
        if (!is_string($home) || $home === '') {
            throw new \RuntimeException('wprism: Rank Math filtered home URL is malformed');
        }
        $removeProductBase = null;
        if (is_callable(['RankMath\\Helper', 'get_settings'])) {
            $removeProductBase = (bool) \RankMath\Helper::get_settings('general.wc_remove_product_base');
        }
        $runtime = [
            'home_url' => $home,
            'permalink_structure' => $permalinkStructure,
            'post_types' => $postTypes,
            'public_query_vars' => array_values($publicQueryVars),
            'remove_product_base' => $removeProductBase,
            'rewrite_rules_count' => is_array($runtimeRules) ? count($runtimeRules) : 0,
            'rewrite_rules_sha256' => hash('sha256', serialize($runtimeRules)),
        ];
        if (strlen(serialize($runtime)) > self::MAX_PROJECTION_SERIALIZED_BYTES) {
            throw new \RuntimeException('wprism: Rank Math route runtime projection exceeds its reviewed bound');
        }
        return $runtime;
    }

    /**
     * @param array<string,string> $types
     * @param array{
     *   authors:list<int>,
     *   projection:array{row_count:int,rows_sha256:string},
     *   resolution:array{link_count:int,resolution_sha256:string}
     * } $source
     * @return array{dependency_sha256:string}
     */
    private function route_dependency_projection(array $types, array $source, array $runtime): array {
        global $wpdb;
        $quotedOptions = array_map(
            static fn(string $name): string => "'" . esc_sql($name) . "'",
            self::ROUTE_OPTION_NAMES
        );
        $authorIds = $source['authors'];
        $authorWhere = $authorIds === []
            ? 'WHERE 1 = 0'
            : 'WHERE ID IN (' . implode(',', $authorIds) . ')';
        $authorMetaWhere = $authorIds === []
            ? 'WHERE 1 = 0'
            : 'WHERE user_id IN (' . implode(',', $authorIds) . ')';
        $parts = [
            'options' => $this->stream_projection(
                $wpdb->options,
                ['option_name', 'option_value'],
                'WHERE option_name IN (' . implode(',', $quotedOptions) . ')',
                'option_name',
                'Rank Math route-option projection',
                count(self::ROUTE_OPTION_NAMES)
            ),
            'comments' => $this->stream_projection(
                $wpdb->comments,
                self::COMMENT_WITNESS_COLUMNS,
                '',
                'comment_ID',
                'Rank Math route-comment projection',
                self::MAX_ROUTE_IDENTITY_ROWS
            ),
            'route_posts' => $this->stream_projection(
                $wpdb->posts,
                self::ROUTE_POST_COLUMNS,
                '',
                'ID',
                'Rank Math route-post projection',
                self::MAX_ROUTE_IDENTITY_ROWS
            ),
            'source_posts' => $source['projection'],
            'native_resolution' => $source['resolution'],
            'term_relationships' => $this->stream_projection(
                $wpdb->term_relationships,
                ['object_id', 'term_taxonomy_id', 'term_order'],
                '',
                'object_id, term_taxonomy_id',
                'Rank Math route-term-relationship projection',
                self::MAX_ROUTE_IDENTITY_ROWS
            ),
            'term_taxonomy' => $this->stream_projection(
                $wpdb->term_taxonomy,
                ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'],
                '',
                'term_taxonomy_id',
                'Rank Math route-term-taxonomy projection',
                self::MAX_ROUTE_IDENTITY_ROWS
            ),
            'terms' => $this->stream_projection(
                $wpdb->terms,
                ['term_id', 'name', 'slug', 'term_group'],
                '',
                'term_id',
                'Rank Math route-term projection',
                self::MAX_ROUTE_IDENTITY_ROWS
            ),
            'users' => $this->stream_projection(
                $wpdb->users,
                self::USER_WITNESS_COLUMNS,
                $authorWhere,
                'ID',
                'Rank Math route-author projection',
                max(1, count($authorIds))
            ),
            'usermeta' => $this->stream_projection(
                $wpdb->usermeta,
                ['umeta_id', 'user_id', 'meta_key', 'meta_value'],
                $authorMetaWhere,
                'user_id, umeta_id',
                'Rank Math route-author-meta projection'
            ),
            'types' => array_keys($types),
            'runtime' => $runtime,
        ];
        return ['dependency_sha256' => hash('sha256', serialize($parts))];
    }

    /**
     * Recovery may run after the scoped receipt already exists, so this path
     * reads only the three database surfaces mutated by the provider. Calling
     * get_permalink() or url_to_postid() here would re-enter participant hooks
     * after the effect boundary and violate scoped recovery's no-reinvoke rule.
     *
     * @return array<string,int|string>
     */
    private function scoped_link_projection(): array {
        global $wpdb;
        $links = $wpdb->prefix . 'rank_math_internal_links';
        $meta = $wpdb->prefix . 'rank_math_internal_meta';
        $postmeta = $wpdb->postmeta;
        $this->assert_innodb_projection_tables([$links, $meta, $postmeta]);
        self::snapshot_control(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'could not establish repeatable-read scoped link evidence'
        );
        self::snapshot_control(
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            'could not start the scoped link-evidence snapshot'
        );
        $snapshotStarted = true;
        try {
            $linkProjection = $this->stream_projection(
                $links,
                ['url', 'post_id', 'target_post_id', 'type'],
                '',
                'post_id, url, target_post_id, type, id',
                'Rank Math scoped link projection'
            );
            $metaProjection = $this->stream_projection(
                $meta,
                ['object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count'],
                '',
                'object_id',
                'Rank Math scoped link-count projection'
            );
            $markerProjection = $this->stream_projection(
                $postmeta,
                ['post_id', 'meta_value'],
                "WHERE meta_key = 'rank_math_internal_links_processed'",
                'post_id, meta_id',
                'Rank Math scoped processed-marker projection'
            );
            self::snapshot_control('COMMIT', 'could not commit the scoped link-evidence snapshot');
            $snapshotStarted = false;
        } catch (\Throwable $failure) {
            if ($snapshotStarted) {
                try {
                    self::snapshot_control('ROLLBACK', 'could not roll back the scoped link-evidence snapshot');
                } catch (\Throwable $_rollbackFailure) {
                    // The original read failure is the actionable recovery debt.
                }
            }
            throw $failure;
        }

        return [
            'link_count' => $linkProjection['row_count'],
            'link_hash' => $linkProjection['rows_sha256'],
            'meta_count' => $metaProjection['row_count'],
            'meta_hash' => $metaProjection['rows_sha256'],
            'marker_count' => $markerProjection['row_count'],
            'marker_hash' => $markerProjection['rows_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private function link_projection(): array {
        global $wpdb;
        $links = $wpdb->prefix . 'rank_math_internal_links';
        $meta = $wpdb->prefix . 'rank_math_internal_meta';
        $postmeta = $wpdb->postmeta;
        $modules = get_option('rank_math_modules', []);
        $enabled = is_array($modules) && in_array('link-counter', $modules, true);
        $native = $this->audited_native_exclusion_callback();
        $types = $enabled ? $this->accessible_post_types($native) : [];
        $tables = [$links, $meta, $postmeta];
        if ($enabled) {
            array_push(
                $tables,
                $wpdb->posts,
                $wpdb->options,
                $wpdb->comments,
                $wpdb->term_relationships,
                $wpdb->term_taxonomy,
                $wpdb->terms,
                $wpdb->users,
                $wpdb->usermeta
            );
        }
        $this->assert_innodb_projection_tables($tables);
        self::snapshot_control(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'could not establish repeatable-read link evidence'
        );
        self::snapshot_control(
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            'could not start the complete link-evidence snapshot'
        );
        $snapshotStarted = true;
        try {
            $runtime = $enabled ? $this->route_runtime_projection($types) : [];
            $source = $this->source_dependency_projection($types);
            if ($enabled && $runtime !== $this->route_runtime_projection($types)) {
                throw new \RuntimeException(
                    'wprism: Rank Math route runtime changed during its complete projection'
                );
            }
            $dependencyProjection = $enabled
                ? $this->route_dependency_projection($types, $source, $runtime)
                : ['dependency_sha256' => hash('sha256', serialize(['enabled' => false]))];
            $linkProjection = $this->stream_projection(
                $links,
                ['url', 'post_id', 'target_post_id', 'type'],
                '',
                'post_id, url, target_post_id, type, id',
                'Rank Math link projection'
            );
            $metaProjection = $this->stream_projection(
                $meta,
                ['object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count'],
                '',
                'object_id',
                'Rank Math link-count projection'
            );
            $markerProjection = $this->stream_projection(
                $postmeta,
                ['post_id', 'meta_value'],
                "WHERE meta_key = 'rank_math_internal_links_processed'",
                'post_id, meta_id',
                'Rank Math processed-marker projection'
            );
            self::snapshot_control('COMMIT', 'could not commit the complete link-evidence snapshot');
            $snapshotStarted = false;
        } catch (\Throwable $failure) {
            if ($snapshotStarted) {
                try {
                    self::snapshot_control('ROLLBACK', 'could not roll back the complete link-evidence snapshot');
                } catch (\Throwable $_rollbackFailure) {
                    // The provider receipt cannot claim a coherent read after
                    // an ambiguous snapshot cleanup, so preserve first failure.
                }
            }
            throw $failure;
        }

        return [
            'enabled' => $enabled,
            'post_count' => $source['projection']['row_count'],
            'post_hash' => hash('sha256', serialize([
                'types' => array_keys($types),
                'projection' => $source['projection'],
            ])),
            'dependency_hash' => $dependencyProjection['dependency_sha256'],
            'link_count' => $linkProjection['row_count'],
            'link_hash' => $linkProjection['rows_sha256'],
            'meta_count' => $metaProjection['row_count'],
            'meta_hash' => $metaProjection['rows_sha256'],
            'marker_count' => $markerProjection['row_count'],
            'marker_hash' => $markerProjection['rows_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private function run_child(int $timeout): array {
        try {
            $result = WpCliChildProcess::capture(
                'eval ' . escapeshellarg(self::child_payload()),
                $timeout,
                524288,
                262144
            );
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: Rank Math native link repair could not run in a bounded fresh process', 0, $failure);
        }
        if ($result['return_code'] !== 0 || trim($result['stderr']) !== '') {
            throw new \RuntimeException('wprism: Rank Math native link repair did not complete cleanly; recovery_required');
        }
        try {
            $receipt = json_decode(trim($result['stdout']), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: Rank Math native link repair returned malformed evidence; recovery_required');
        }
        if (!is_array($receipt)
            || array_keys($receipt) !== ['format', 'projection', 'verified']
            || ($receipt['format'] ?? null) !== self::CHILD_FORMAT
            || !is_array($receipt['projection'] ?? null)
            || ($receipt['verified'] ?? null) !== true) {
            throw new \RuntimeException('wprism: Rank Math native link repair returned invalid evidence; recovery_required');
        }
        return $receipt;
    }

    /** @param array<string,mixed> $child @param array<string,mixed> $after */
    private function assert_child_matches(array $child, array $after): void {
        if ($child['projection'] !== $after) {
            throw new \RuntimeException('wprism: Rank Math child repair evidence disagrees with checked parent readback; recovery_required');
        }
    }

    private static function child_payload(): string {
        $filters = var_export(self::FILTERS, true);
        $format = self::CHILD_FORMAT;
        $template = <<<'PHP'
if (is_multisite()) {
    throw new RuntimeException('Rank Math link repair refuses multisite');
}
$modules = get_option('rank_math_modules', []);
$enabled = is_array($modules) && in_array('link-counter', $modules, true);
if ($enabled
    && (!class_exists('RankMath\\Links\\Links')
        || !is_callable(['RankMath\\Links\\Links', 'process_post_links'])
        || !is_callable(['RankMath\\Links\\Links', 'is_post_processable'])
        || !class_exists('RankMath\\Links\\ContentProcessor')
        || !is_callable(['RankMath\\Links\\ContentProcessor', 'get'])
        || !class_exists('RankMath\\Helper')
        || !is_callable(['RankMath\\Helper', 'get_accessible_post_types'])
        || !function_exists('clean_post_cache'))) {
    throw new RuntimeException('Rank Math link-counter API is unavailable while its module is enabled');
}
$filters = __FILTERS__;
$nativeExclusion = null;
foreach ($filters as $filter) {
    if ($filter === 'rank_math/excluded_post_types') {
        $registry = $GLOBALS['wp_filter'] ?? null;
        if (!is_array($registry)) {
            throw new RuntimeException('Rank Math link repair requires exactly its audited native exclusion callback');
        }
        if (!array_key_exists($filter, $registry)) {
            // The host invokes this fresh process before canonical authored
            // options are applied. Rank Math's registration gate can therefore
            // return before Common constructs Defaults; initialize only that
            // audited native class, then prove the exact hook shape below.
            new RankMath\Defaults();
        }
        $hook = $GLOBALS['wp_filter'][$filter] ?? null;
        $callbacks = $hook instanceof WP_Hook ? $hook->callbacks : null;
        $priority = is_array($callbacks) && array_keys($callbacks) === [10]
            ? ($callbacks[10] ?? null)
            : null;
        $entry = is_array($priority) && count($priority) === 1 ? reset($priority) : null;
        $entryKeys = is_array($entry) ? array_keys($entry) : [];
        sort($entryKeys, SORT_STRING);
        $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
        if (!is_array($entry)
            || $entryKeys !== ['accepted_args', 'function']
            || ($entry['accepted_args'] ?? null) !== 1
            || !is_array($callback)
            || count($callback) !== 2
            || !is_object($callback[0] ?? null)
            || get_class($callback[0]) !== RankMath\Defaults::class
            || ($callback[1] ?? null) !== 'excluded_post_types') {
            throw new RuntimeException('Rank Math link repair requires exactly its audited native exclusion callback');
        }
        $nativeExclusion = [$callback[0], 'excluded_post_types'];
        continue;
    }
    if (has_filter($filter) !== false) {
        throw new RuntimeException('Rank Math link repair refuses an unreviewed link-processing callback');
    }
}
if ($nativeExclusion === null) {
    throw new RuntimeException('Rank Math native exclusion callback is unavailable');
}
global $wpdb;
$links = $wpdb->prefix . 'rank_math_internal_links';
$meta = $wpdb->prefix . 'rank_math_internal_meta';
$control = static function (string $sql, string $failure) use ($wpdb): void {
    $wpdb->last_error = '';
    $result = $wpdb->query($sql);
    if ($result === false || (string) $wpdb->last_error !== '') {
        throw new RuntimeException($failure);
    }
};
$boundedDecimal = static function ($value, int $maximum): bool {
    if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
        return false;
    }
    $bound = (string) $maximum;
    return strlen($value) < strlen($bound)
        || (strlen($value) === strlen($bound) && strcmp($value, $bound) <= 0);
};
$streamProjection = static function (
    string $table,
    array $columns,
    string $where,
    string $order,
    string $label,
    int $maximumRows = __MAX_ROWS__,
    ?callable $visitor = null
) use ($wpdb, $boundedDecimal): array {
    if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1 || $columns === []) {
        throw new RuntimeException('Rank Math bounded projection has an unsafe table contract');
    }
    foreach ($columns as $column) {
        if (!is_string($column) || preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
            throw new RuntimeException('Rank Math bounded projection has an unsafe column contract');
        }
    }
    $rowBytes = implode(' + ', array_map(
        static fn(string $column): string => "COALESCE(OCTET_LENGTH(`$column`), 0)",
        $columns
    ));
    $from = "FROM `$table`" . ($where === '' ? '' : " $where");
    $wpdb->last_error = '';
    $boundsRows = $wpdb->get_results(
        "SELECT COUNT(*) AS row_count, COALESCE(SUM($rowBytes), 0) AS total_bytes, "
            . "COALESCE(MAX($rowBytes), 0) AS max_row_bytes $from",
        ARRAY_A
    );
    $bounds = is_array($boundsRows) && count($boundsRows) === 1 ? $boundsRows[0] : null;
    $count = is_array($bounds) ? ($bounds['row_count'] ?? null) : null;
    $totalBytes = is_array($bounds) ? ($bounds['total_bytes'] ?? null) : null;
    $maxRowBytes = is_array($bounds) ? ($bounds['max_row_bytes'] ?? null) : null;
    if ((string) $wpdb->last_error !== '' || !$boundedDecimal($count, $maximumRows)) {
        throw new RuntimeException("$label failed or exceeded its reviewed row bound");
    }
    if (!$boundedDecimal($totalBytes, __MAX_RAW_BYTES__)
        || !$boundedDecimal($maxRowBytes, __MAX_ROW_RAW_BYTES__)) {
        throw new RuntimeException("$label exceeded its reviewed byte bound");
    }
    $select = implode(', ', array_map(static fn(string $column): string => "`$column`", $columns));
    $expectedRows = (int) $count;
    $largestRowBytes = max(1, (int) $maxRowBytes);
    $chunkRows = min(
        __MAX_CHUNK_ROWS__,
        max(1, intdiv(__MAX_PAGE_RAW_BYTES__, $largestRowBytes))
    );
    $digest = hash_init('sha256');
    $observedRows = 0;
    $serializedBytes = 0;
    while ($observedRows < $expectedRows) {
        $valueWhere = $where === '' ? 'WHERE' : "$where AND";
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            "SELECT $select FROM `$table` $valueWhere ($rowBytes) <= __MAX_ROW_RAW_BYTES__ "
                . "ORDER BY $order LIMIT $chunkRows OFFSET $observedRows",
            ARRAY_A
        );
        if (!is_array($rows)
            || (string) $wpdb->last_error !== ''
            || $rows === []
            || count($rows) > $chunkRows) {
            throw new RuntimeException("$label changed during its bounded projection");
        }
        foreach ($rows as $row) {
            $encoded = serialize($row);
            $serializedRowBytes = strlen($encoded);
            $serializedBytes += $serializedRowBytes;
            if ($serializedRowBytes > __MAX_ROW_SERIALIZED_BYTES__
                || $serializedBytes > __MAX_SERIALIZED_BYTES__) {
                throw new RuntimeException("$label exceeded its reviewed serialized bound");
            }
            hash_update($digest, $encoded);
            if ($visitor !== null) {
                $visitor($row);
            }
            $observedRows++;
            if ($observedRows > $expectedRows) {
                throw new RuntimeException("$label changed during its bounded projection");
            }
        }
    }
    if ($observedRows !== $expectedRows) {
        throw new RuntimeException("$label changed during its bounded projection");
    }
    return ['row_count' => $observedRows, 'rows_sha256' => hash_final($digest)];
};
$assertInnoDb = static function (array $tables) use ($wpdb): void {
    foreach ($tables as $table) {
        $wpdb->last_error = '';
        $status = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), ARRAY_A);
        if (!is_array($status)
            || count($status) !== 1
            || (string) $wpdb->last_error !== ''
            || !is_string($status[0]['Engine'] ?? null)
            || strcasecmp((string) $status[0]['Engine'], 'InnoDB') !== 0) {
            throw new RuntimeException("Rank Math complete link projection requires InnoDB for '$table'");
        }
    }
};
$accessibleTypes = static function () use ($nativeExclusion): array {
    $helperTypes = RankMath\Helper::get_accessible_post_types();
    $derivedTypes = get_post_types(['public' => true]);
    if (!is_array($helperTypes) || !is_array($derivedTypes)) {
        throw new RuntimeException('Rank Math accessible post-type inventory is malformed');
    }
    $derivedTypes = array_filter($derivedTypes, 'is_post_type_viewable');
    $derivedTypes = call_user_func($nativeExclusion, $derivedTypes);
    if (!is_array($derivedTypes)) {
        throw new RuntimeException('Rank Math native post-type exclusion returned malformed state');
    }
    ksort($helperTypes, SORT_STRING);
    ksort($derivedTypes, SORT_STRING);
    if ($helperTypes !== $derivedTypes) {
        throw new RuntimeException(
            'Rank Math cached accessible post types disagree with the current audited topology'
        );
    }
    unset($derivedTypes['attachment']);
    foreach ($derivedTypes as $name => $value) {
        if (!is_string($name)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/D', $name) !== 1
            || $value !== $name) {
            throw new RuntimeException('Rank Math accessible post-type inventory is malformed');
        }
    }
    return $derivedTypes;
};
$postColumns = __POST_COLUMNS__;
$routePostColumns = __ROUTE_POST_COLUMNS__;
$routeOptionNames = __ROUTE_OPTION_NAMES__;
$commentColumns = __COMMENT_COLUMNS__;
$userColumns = __USER_COLUMNS__;
$emptyProjection = static fn(): array => [
    'row_count' => 0,
    'rows_sha256' => hash('sha256', ''),
];
$sourceProjection = static function (
    array $types,
    ?callable $visitor = null,
    ?callable $edgeVisitor = null
) use (
    $wpdb,
    $streamProjection,
    $postColumns,
    $emptyProjection
): array {
    $authors = [];
    $resolution = hash_init('sha256');
    $linkCount = 0;
    $candidateCount = 0;
    if ($types === []) {
        return [
            'authors' => [],
            'projection' => $emptyProjection(),
            'resolution' => [
                'link_count' => 0,
                'resolution_sha256' => hash_final($resolution),
            ],
        ];
    }
    $processor = new RankMath\Links\ContentProcessor();
    if (!is_callable([$processor, 'extract'])
        || !is_callable([$processor, 'normalize_link'])
        || !is_callable([$processor, 'is_valid_link_type'])
        || !is_callable([$processor, 'maybe_product_id'])) {
        throw new RuntimeException('Rank Math native content-processor API is unavailable');
    }
    $quoted = array_map(
        static fn(string $type): string => "'" . esc_sql($type) . "'",
        array_keys($types)
    );
    $projection = $streamProjection(
        $wpdb->posts,
        $postColumns,
        'WHERE post_type IN (' . implode(',', $quoted) . ')',
        'ID',
        'Rank Math accessible-post projection',
        __MAX_SOURCE_ROWS__,
        static function (array $row) use (
            &$authors,
            &$resolution,
            &$linkCount,
            &$candidateCount,
            $processor,
            $postColumns,
            $edgeVisitor,
            $visitor
        ): void {
            $id = (int) ($row['ID'] ?? 0);
            $author = (int) ($row['post_author'] ?? 0);
            if ($id <= 0 || $author < 0) {
                throw new RuntimeException('Rank Math accessible-post projection contains an invalid identity');
            }
            if ($author > 0) {
                $authors[$author] = true;
            }
            clean_post_cache($id);
            $post = get_post($id);
            foreach ($postColumns as $column) {
                if (!$post || (string) ($post->$column ?? '') !== (string) ($row[$column] ?? '')) {
                    throw new RuntimeException(
                        'Rank Math post object cache disagrees with its bounded database witness'
                    );
                }
            }
            $processable = RankMath\Links\Links::is_post_processable($post);
            if ($processable) {
                $content = $row['post_content'] ?? null;
                $permalink = get_permalink($id);
                if (!is_string($content)
                    || !is_string($permalink)
                    || $permalink === '') {
                    throw new RuntimeException('Rank Math native link expectation has malformed source state');
                }
                $links = $processor->extract(str_replace(']]>', ']]&gt;', $content));
                $postPermalink = $processor->normalize_link($permalink);
                if (!is_array($links) || !is_string($postPermalink)) {
                    throw new RuntimeException('Rank Math native link expectation is malformed');
                }
                $postEdgeCount = 0;
                foreach ($links as $link) {
                    $candidateCount++;
                    if ($candidateCount > __MAX_RESOLUTION_LINKS__) {
                        throw new RuntimeException(
                            'Rank Math native link expectation exceeds its reviewed identity bound'
                        );
                    }
                    if (!is_string($link)) {
                        throw new RuntimeException('Rank Math native link expectation is malformed');
                    }
                    $normalized = $processor->normalize_link($link);
                    if (!is_string($normalized)) {
                        throw new RuntimeException('Rank Math native link expectation is malformed');
                    }
                    if ($postPermalink === $normalized) {
                        continue;
                    }
                    $type = $processor->is_valid_link_type($link);
                    if (empty($type)) {
                        continue;
                    }
                    if ($type !== 'internal' && $type !== 'external') {
                        throw new RuntimeException('Rank Math native link expectation has an invalid type');
                    }
                    $target = 0;
                    if ($type === 'internal') {
                        $target = (int) url_to_postid($link);
                        if ($target === 0) {
                            $target = (int) $processor->maybe_product_id($link);
                        }
                        if ($target < 0) {
                            throw new RuntimeException('Rank Math native link expectation has an invalid target');
                        }
                    }
                    $edge = [
                        'url' => $link,
                        'post_id' => $id,
                        'target_post_id' => $target,
                        'type' => $type,
                    ];
                    $linkCount++;
                    $postEdgeCount++;
                    if ($linkCount > __MAX_RESOLUTION_LINKS__) {
                        throw new RuntimeException(
                            'Rank Math native link expectation exceeds its reviewed identity bound'
                        );
                    }
                    hash_update($resolution, serialize($edge));
                    if ($edgeVisitor !== null) {
                        $edgeVisitor($edge);
                    }
                }
                hash_update($resolution, serialize(['post_id' => $id, 'edge_count' => $postEdgeCount]));
            }
            if ($visitor !== null) {
                $visitor($row, $processable);
            }
        }
    );
    $authorIds = array_map('intval', array_keys($authors));
    sort($authorIds, SORT_NUMERIC);
    return [
        'authors' => $authorIds,
        'projection' => $projection,
        'resolution' => [
            'link_count' => $linkCount,
            'resolution_sha256' => hash_final($resolution),
        ],
    ];
};
$routeRuntimeProjection = static function (array $types): array {
    global $wp, $wp_post_types, $wp_rewrite;
    if (!is_array($wp_post_types) || count($wp_post_types) > __MAX_POST_TYPES__) {
        throw new RuntimeException('Rank Math registered post-type topology is malformed or unbounded');
    }
    $postTypes = [];
    $registeredPostTypes = $wp_post_types;
    ksort($registeredPostTypes, SORT_STRING);
    foreach ($registeredPostTypes as $name => $object) {
        if (!is_string($name)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/D', $name) !== 1
            || !is_object($object)) {
            throw new RuntimeException('Rank Math registered post-type topology is malformed or unbounded');
        }
        $rewrite = $object->rewrite ?? false;
        if (!is_bool($rewrite) && !is_array($rewrite)) {
            throw new RuntimeException("Rank Math route topology for '$name' is malformed");
        }
        $postTypes[$name] = [
            'has_archive' => $object->has_archive ?? false,
            'hierarchical' => (bool) ($object->hierarchical ?? false),
            'publicly_queryable' => (bool) ($object->publicly_queryable ?? false),
            'query_var' => $object->query_var ?? false,
            'rewrite' => $rewrite,
        ];
    }
    foreach (array_keys($types) as $name) {
        if (!isset($postTypes[$name])) {
            throw new RuntimeException("Rank Math route topology lost post type '$name'");
        }
    }
    $publicQueryVars = is_object($wp) ? ($wp->public_query_vars ?? null) : null;
    if (!is_array($publicQueryVars) || count($publicQueryVars) > __MAX_QUERY_VARS__) {
        throw new RuntimeException('Rank Math public query-var topology is malformed or unbounded');
    }
    foreach ($publicQueryVars as $queryVar) {
        if (!is_string($queryVar)
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $queryVar) !== 1) {
            throw new RuntimeException('Rank Math public query-var topology is malformed or unbounded');
        }
    }
    $permalinkStructure = get_option('permalink_structure', '');
    $storedRules = get_option('rewrite_rules', null);
    $runtimeRules = is_object($wp_rewrite) ? ($wp_rewrite->rules ?? null) : null;
    if (!is_string($permalinkStructure)) {
        throw new RuntimeException('Rank Math permalink structure is malformed');
    }
    if ($permalinkStructure !== ''
        && is_array($storedRules)
        && $storedRules !== []
        && $runtimeRules === null) {
        // The bounded child is an independent virgin WP-CLI request. Hydrate
        // only the exact core object's request-local lazy cache from the exact
        // non-empty effective option; never enter native rule regeneration.
        if (!is_object($wp_rewrite) || get_class($wp_rewrite) !== WP_Rewrite::class) {
            throw new RuntimeException(
                'Rank Math pretty-permalink repair requires the exact core rewrite runtime'
            );
        }
        $wp_rewrite->rules = $storedRules;
        $runtimeRules = $wp_rewrite->rules;
    }
    if ($permalinkStructure !== ''
        && (!is_array($runtimeRules)
            || $runtimeRules === []
            || !is_array($storedRules)
            || $storedRules === []
            || $storedRules !== $runtimeRules)) {
        throw new RuntimeException(
            'Rank Math pretty-permalink repair requires exact non-empty stored and loaded rewrite rules'
        );
    }
    if (!is_array($runtimeRules) && $runtimeRules !== null && $runtimeRules !== '') {
        throw new RuntimeException('Rank Math loaded rewrite-rule topology is malformed');
    }
    $home = home_url();
    if (!is_string($home) || $home === '') {
        throw new RuntimeException('Rank Math filtered home URL is malformed');
    }
    $removeProductBase = null;
    if (is_callable(['RankMath\\Helper', 'get_settings'])) {
        $removeProductBase = (bool) RankMath\Helper::get_settings('general.wc_remove_product_base');
    }
    $runtime = [
        'home_url' => $home,
        'permalink_structure' => $permalinkStructure,
        'post_types' => $postTypes,
        'public_query_vars' => array_values($publicQueryVars),
        'remove_product_base' => $removeProductBase,
        'rewrite_rules_count' => is_array($runtimeRules) ? count($runtimeRules) : 0,
        'rewrite_rules_sha256' => hash('sha256', serialize($runtimeRules)),
    ];
    if (strlen(serialize($runtime)) > __MAX_SERIALIZED_BYTES__) {
        throw new RuntimeException('Rank Math route runtime projection exceeds its reviewed bound');
    }
    return $runtime;
};
$routeDependencyProjection = static function (
    array $types,
    array $source,
    array $runtime,
    ?callable $routePostVisitor = null
) use (
    $wpdb,
    $enabled,
    $streamProjection,
    $routePostColumns,
    $routeOptionNames,
    $commentColumns,
    $userColumns
): array {
    if (!$enabled) {
        return ['dependency_sha256' => hash('sha256', serialize(['enabled' => false]))];
    }
    $quotedOptions = array_map(
        static fn(string $name): string => "'" . esc_sql($name) . "'",
        $routeOptionNames
    );
    $authorIds = $source['authors'];
    $authorWhere = $authorIds === []
        ? 'WHERE 1 = 0'
        : 'WHERE ID IN (' . implode(',', $authorIds) . ')';
    $authorMetaWhere = $authorIds === []
        ? 'WHERE 1 = 0'
        : 'WHERE user_id IN (' . implode(',', $authorIds) . ')';
    $parts = [
        'options' => $streamProjection(
            $wpdb->options,
            ['option_name', 'option_value'],
            'WHERE option_name IN (' . implode(',', $quotedOptions) . ')',
            'option_name',
            'Rank Math route-option projection',
            count($routeOptionNames)
        ),
        'comments' => $streamProjection(
            $wpdb->comments,
            $commentColumns,
            '',
            'comment_ID',
            'Rank Math route-comment projection',
            __MAX_ROUTE_ROWS__
        ),
        'route_posts' => $streamProjection(
            $wpdb->posts,
            $routePostColumns,
            '',
            'ID',
            'Rank Math route-post projection',
            __MAX_ROUTE_ROWS__,
            $routePostVisitor
        ),
        'source_posts' => $source['projection'],
        'native_resolution' => $source['resolution'],
        'term_relationships' => $streamProjection(
            $wpdb->term_relationships,
            ['object_id', 'term_taxonomy_id', 'term_order'],
            '',
            'object_id, term_taxonomy_id',
            'Rank Math route-term-relationship projection',
            __MAX_ROUTE_ROWS__
        ),
        'term_taxonomy' => $streamProjection(
            $wpdb->term_taxonomy,
            ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'],
            '',
            'term_taxonomy_id',
            'Rank Math route-term-taxonomy projection',
            __MAX_ROUTE_ROWS__
        ),
        'terms' => $streamProjection(
            $wpdb->terms,
            ['term_id', 'name', 'slug', 'term_group'],
            '',
            'term_id',
            'Rank Math route-term projection',
            __MAX_ROUTE_ROWS__
        ),
        'users' => $streamProjection(
            $wpdb->users,
            $userColumns,
            $authorWhere,
            'ID',
            'Rank Math route-author projection',
            max(1, count($authorIds))
        ),
        'usermeta' => $streamProjection(
            $wpdb->usermeta,
            ['umeta_id', 'user_id', 'meta_key', 'meta_value'],
            $authorMetaWhere,
            'user_id, umeta_id',
            'Rank Math route-author-meta projection'
        ),
        'types' => array_keys($types),
        'runtime' => $runtime,
    ];
    return ['dependency_sha256' => hash('sha256', serialize($parts))];
};
$routeTables = [
    $wpdb->posts,
    $wpdb->options,
    $wpdb->comments,
    $wpdb->term_relationships,
    $wpdb->term_taxonomy,
    $wpdb->terms,
    $wpdb->users,
    $wpdb->usermeta,
];
$dependencyStateWithinSnapshot = static function (
    ?callable $sourceVisitor = null,
    ?callable $routePostVisitor = null,
    ?callable $edgeVisitor = null
) use (
    $enabled,
    $accessibleTypes,
    $sourceProjection,
    $routeDependencyProjection,
    $routeRuntimeProjection
): array {
    $types = $enabled ? $accessibleTypes() : [];
    $runtime = $enabled ? $routeRuntimeProjection($types) : [];
    $source = $sourceProjection($types, $sourceVisitor, $edgeVisitor);
    if ($enabled && $runtime !== $routeRuntimeProjection($types)) {
        throw new RuntimeException('Rank Math route runtime changed during its complete projection');
    }
    $dependency = $routeDependencyProjection($types, $source, $runtime, $routePostVisitor);
    return [
        'types' => array_keys($types),
        'source_projection' => $source['projection'],
        'dependency_sha256' => $dependency['dependency_sha256'],
    ];
};
$readDependencyState = static function () use (
    $enabled,
    $routeTables,
    $assertInnoDb,
    $control,
    $dependencyStateWithinSnapshot
): array {
    if ($enabled) {
        $assertInnoDb($routeTables);
    }
    $control('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
        'Rank Math could not establish repeatable-read dependency evidence');
    $control('START TRANSACTION WITH CONSISTENT SNAPSHOT',
        'Rank Math could not start the dependency-evidence snapshot');
    $started = true;
    try {
        $state = $dependencyStateWithinSnapshot();
        $control('COMMIT', 'Rank Math could not commit the dependency-evidence snapshot');
        $started = false;
        return $state;
    } catch (Throwable $failure) {
        if ($started) {
            try {
                $control('ROLLBACK', 'Rank Math could not roll back the post-evidence snapshot');
            } catch (Throwable $_rollbackFailure) {
            }
        }
        throw $failure;
    }
};
$initialDependencyState = $readDependencyState();
$mutationTables = [$links, $meta, $wpdb->postmeta];
if ($enabled) {
    array_push($mutationTables, ...$routeTables);
}
$assertInnoDb(array_values(array_unique($mutationTables)));
$clear = static function () use ($wpdb, $links, $meta): void {
    foreach ([
        "DELETE FROM `$links`",
        "DELETE FROM `$meta`",
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_internal_links_processed'",
    ] as $sql) {
        $wpdb->last_error = '';
        $result = $wpdb->query($sql);
        if ($result === false || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('Rank Math derived link-state reset failed');
        }
    }
};
$projection = static function () use (
    $wpdb,
    $links,
    $meta,
    $enabled,
    $routeTables,
    $assertInnoDb,
    $control,
    $dependencyStateWithinSnapshot,
    $streamProjection
): array {
    $tables = [$links, $meta, $wpdb->postmeta];
    if ($enabled) {
        array_push($tables, ...$routeTables);
    }
    $assertInnoDb(array_values(array_unique($tables)));
    $control('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
        'Rank Math could not establish repeatable-read link evidence');
    $control('START TRANSACTION WITH CONSISTENT SNAPSHOT',
        'Rank Math could not start the complete link-evidence snapshot');
    $started = true;
    try {
        $state = $dependencyStateWithinSnapshot();
        $linkProjection = $streamProjection(
            $links,
            ['url', 'post_id', 'target_post_id', 'type'],
            '',
            'post_id, url, target_post_id, type, id',
            'Rank Math link projection'
        );
        $metaProjection = $streamProjection(
            $meta,
            ['object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count'],
            '',
            'object_id',
            'Rank Math link-count projection'
        );
        $markerProjection = $streamProjection(
            $wpdb->postmeta,
            ['post_id', 'meta_value'],
            "WHERE meta_key = 'rank_math_internal_links_processed'",
            'post_id, meta_id',
            'Rank Math processed-marker projection'
        );
        $control('COMMIT', 'Rank Math could not commit the complete link-evidence snapshot');
        $started = false;
    } catch (Throwable $failure) {
        if ($started) {
            try {
                $control('ROLLBACK', 'Rank Math could not roll back the complete link-evidence snapshot');
            } catch (Throwable $_rollbackFailure) {
            }
        }
        throw $failure;
    }
    return [
        'enabled' => $enabled,
        'post_count' => $state['source_projection']['row_count'],
        'post_hash' => hash('sha256', serialize([
            'types' => $state['types'],
            'projection' => $state['source_projection'],
        ])),
        'dependency_hash' => $state['dependency_sha256'],
        'link_count' => $linkProjection['row_count'],
        'link_hash' => $linkProjection['rows_sha256'],
        'meta_count' => $metaProjection['row_count'],
        'meta_hash' => $metaProjection['rows_sha256'],
        'marker_count' => $markerProjection['row_count'],
        'marker_hash' => $markerProjection['rows_sha256'],
    ];
};
$verify = static function () use (
    $wpdb,
    $links,
    $meta,
    $enabled,
    $routeTables,
    $streamProjection,
    $dependencyStateWithinSnapshot,
    $initialDependencyState,
    $assertInnoDb,
    $control
): void {
    $tables = [$links, $meta, $wpdb->postmeta];
    if ($enabled) {
        array_push($tables, ...$routeTables);
    }
    $assertInnoDb(array_values(array_unique($tables)));
    $control('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
        'Rank Math could not establish repeatable-read verification evidence');
    $control('START TRANSACTION WITH CONSISTENT SNAPSHOT',
        'Rank Math could not start the verification snapshot');
    $started = true;
    try {
    $sourceSet = [];
    $routeSet = [];
    $expectedEdges = [];
    $expectedEdgeCount = 0;
    $expectedTargets = [];
    $counts = [];
    $incomingCounts = [];
    $affected = [];
    $lastSourceId = 0;
    $lastRouteId = 0;
    $sourceVisitor = static function (array $row, bool $processable) use (
        &$sourceSet,
        &$affected,
        &$lastSourceId
    ): void {
        $id = (int) ($row['ID'] ?? 0);
        if ($id <= $lastSourceId) {
            throw new RuntimeException('Rank Math accessible-post projection contains an invalid identity');
        }
        $lastSourceId = $id;
        if ($processable) {
            $sourceSet[$id] = true;
            $affected[$id] = true;
        }
    };
    $edgeVisitor = static function (array $edge) use (
        &$expectedEdges,
        &$expectedEdgeCount,
        &$expectedTargets,
        &$counts,
        &$incomingCounts,
        &$affected
    ): void {
        $source = (int) ($edge['post_id'] ?? 0);
        $target = (int) ($edge['target_post_id'] ?? 0);
        $type = (string) ($edge['type'] ?? '');
        $edgeKey = hash('sha256', serialize($edge));
        $expectedEdges[$edgeKey] = ($expectedEdges[$edgeKey] ?? 0) + 1;
        $expectedEdgeCount++;
        if ($expectedEdgeCount > __MAX_ROWS__) {
            throw new RuntimeException('Rank Math native link expectation exceeds its reviewed row bound');
        }
        $counts[$source][$type] = ($counts[$source][$type] ?? 0) + 1;
        if ($target > 0) {
            $expectedTargets[$target] = true;
            $affected[$target] = true;
            if (count($affected) > __MAX_VERIFY_IDENTITIES__) {
                throw new RuntimeException('Rank Math link verification exceeds its reviewed identity bound');
            }
            $incomingCounts[$target] = ($incomingCounts[$target] ?? 0) + 1;
        }
    };
    $routeVisitor = static function (array $row) use (&$routeSet, &$lastRouteId): void {
        $id = (int) ($row['ID'] ?? 0);
        if ($id <= $lastRouteId) {
            throw new RuntimeException('Rank Math route-post projection contains an invalid identity');
        }
        $lastRouteId = $id;
        $routeSet[$id] = true;
    };
    $dependencyState = $dependencyStateWithinSnapshot($sourceVisitor, $routeVisitor, $edgeVisitor);
    if ($dependencyState !== $initialDependencyState) {
        throw new RuntimeException('Rank Math route dependencies changed during native repair');
    }
    foreach (array_keys($expectedTargets) as $target) {
        if (!isset($routeSet[$target])) {
            throw new RuntimeException('Rank Math native link expectation resolved outside its route witness');
        }
    }
    $linkVisitor = static function (array $row) use (
        $sourceSet,
        &$expectedEdges
    ): void {
        $url = $row['url'] ?? null;
        $source = (int) ($row['post_id'] ?? 0);
        $target = (int) ($row['target_post_id'] ?? 0);
        $type = (string) ($row['type'] ?? '');
        if (!is_string($url) || !isset($sourceSet[$source])) {
            throw new RuntimeException('Rank Math link projection contains a stale or invalid native row');
        }
        $edgeKey = hash('sha256', serialize([
            'url' => $url,
            'post_id' => $source,
            'target_post_id' => $target,
            'type' => $type,
        ]));
        if (($expectedEdges[$edgeKey] ?? 0) < 1) {
            throw new RuntimeException(
                'Rank Math stored links did not converge with current native resolution'
            );
        }
        $expectedEdges[$edgeKey]--;
    };
    $linkProjection = $streamProjection(
        $links,
        ['url', 'post_id', 'target_post_id', 'type'],
        '',
        'post_id, url, target_post_id, type, id',
        'Rank Math verification link projection',
        __MAX_ROWS__,
        $linkVisitor
    );
    if ($linkProjection['row_count'] !== $expectedEdgeCount
        || array_sum($expectedEdges) !== 0) {
        throw new RuntimeException('Rank Math stored links did not converge with current native resolution');
    }
    if (count($affected) > __MAX_VERIFY_IDENTITIES__) {
        throw new RuntimeException('Rank Math link verification exceeds its reviewed identity bound');
    }
    $metaSeen = [];
    $metaVisitor = static function (array $row) use (
        $affected,
        $counts,
        $incomingCounts,
        &$metaSeen
    ): void {
        $id = (int) ($row['object_id'] ?? 0);
        if ($id <= 0 || !isset($affected[$id]) || isset($metaSeen[$id])) {
            throw new RuntimeException('Rank Math link-count projection contains a stale or duplicate object');
        }
        if ((int) ($row['internal_link_count'] ?? -1) !== (int) ($counts[$id]['internal'] ?? 0)
            || (int) ($row['external_link_count'] ?? -1) !== (int) ($counts[$id]['external'] ?? 0)
            || (int) ($row['incoming_link_count'] ?? -1) !== (int) ($incomingCounts[$id] ?? 0)) {
            throw new RuntimeException('Rank Math link counts did not converge');
        }
        $metaSeen[$id] = true;
    };
    $metaProjection = $streamProjection(
        $meta,
        ['object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count'],
        '',
        'object_id',
        'Rank Math verification count projection',
        __MAX_ROWS__,
        $metaVisitor
    );
    if (count($metaSeen) !== count($affected)) {
        throw new RuntimeException('Rank Math link-count identity set did not converge');
    }
    $markerIds = [];
    $markerVisitor = static function (array $marker) use ($sourceSet, &$markerIds): void {
        $id = (int) ($marker['post_id'] ?? 0);
        if (!isset($sourceSet[$id])
            || isset($markerIds[$id])
            || (string) ($marker['meta_value'] ?? '') === '') {
            throw new RuntimeException('Rank Math processed-marker projection contains a stale or duplicate object');
        }
        $markerIds[$id] = true;
    };
    $markerProjection = $streamProjection(
        $wpdb->postmeta,
        ['post_id', 'meta_value'],
        "WHERE meta_key = 'rank_math_internal_links_processed'",
        'post_id, meta_id',
        'Rank Math processed-marker verification',
        __MAX_ROWS__,
        $markerVisitor
    );
    if (!$enabled && ($linkProjection['row_count'] !== 0
        || $metaProjection['row_count'] !== 0
        || $markerProjection['row_count'] !== 0)) {
        throw new RuntimeException('Rank Math disabled link-counter retained derived state');
    }
    if (count($markerIds) !== count($sourceSet)) {
        throw new RuntimeException('Rank Math processed-marker identity set did not converge');
    }
        $control('COMMIT', 'Rank Math could not commit the verification snapshot');
        $started = false;
    } catch (Throwable $failure) {
        if ($started) {
            try {
                $control('ROLLBACK', 'Rank Math could not roll back the verification snapshot');
            } catch (Throwable $_rollbackFailure) {
            }
        }
        throw $failure;
    }
};
$assertDependencyState = static function () use ($readDependencyState, $initialDependencyState): void {
    if ($readDependencyState() !== $initialDependencyState) {
        throw new RuntimeException('Rank Math route dependencies changed during native repair');
    }
};
$processPass = static function () use (
    $enabled,
    $routeTables,
    $postColumns,
    $assertInnoDb,
    $control,
    $dependencyStateWithinSnapshot,
    $initialDependencyState,
    $assertDependencyState
): void {
    $assertDependencyState();
    if (!$enabled) {
        $assertDependencyState();
        return;
    }
    $assertInnoDb($routeTables);
    $control('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
        'Rank Math could not establish repeatable-read native-work evidence');
    $control('START TRANSACTION WITH CONSISTENT SNAPSHOT',
        'Rank Math could not start the native-work snapshot');
    $started = true;
    try {
        $lastId = 0;
        $visitor = static function (array $row, bool $processable) use (&$lastId, $postColumns): void {
            $id = (int) ($row['ID'] ?? 0);
            if ($id <= $lastId) {
                throw new RuntimeException('Rank Math accessible-post projection contains an invalid identity');
            }
            $lastId = $id;
            clean_post_cache($id);
            $post = get_post($id);
            foreach ($postColumns as $column) {
                if (!$post
                    || (string) ($post->$column ?? '') !== (string) ($row[$column] ?? '')) {
                    throw new RuntimeException(
                        'Rank Math post object changed after its bounded precondition witness'
                    );
                }
            }
            $currentProcessable = RankMath\Links\Links::is_post_processable($post);
            if ($currentProcessable !== $processable) {
                throw new RuntimeException('Rank Math processability changed during native repair');
            }
            if ($processable) {
                RankMath\Links\Links::process_post_links($id, $post);
            }
        };
        $state = $dependencyStateWithinSnapshot($visitor);
        if ($state !== $initialDependencyState) {
            throw new RuntimeException('Rank Math route dependencies changed during native repair');
        }
        $control('COMMIT', 'Rank Math could not commit the native-work snapshot');
        $started = false;
    } catch (Throwable $failure) {
        if ($started) {
            try {
                $control('ROLLBACK', 'Rank Math could not roll back the native-work snapshot');
            } catch (Throwable $_rollbackFailure) {
            }
        }
        throw $failure;
    }
    $assertDependencyState();
};
$clear();
$processPass();
$verify();
$first = $projection();
if ($enabled) {
    $processPass();
} else {
    $clear();
    $assertDependencyState();
}
$verify();
$second = $projection();
if ($first !== $second) {
    throw new RuntimeException('Rank Math native link repair is not idempotent');
}
echo wp_json_encode([
    'format' => '__FORMAT__',
    'projection' => $second,
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP;
        return str_replace(
            [
                '__FILTERS__',
                '__FORMAT__',
                '__MAX_ROWS__',
                '__MAX_RAW_BYTES__',
                '__MAX_ROW_RAW_BYTES__',
                '__MAX_SERIALIZED_BYTES__',
                '__MAX_ROW_SERIALIZED_BYTES__',
                '__MAX_PAGE_RAW_BYTES__',
                '__MAX_CHUNK_ROWS__',
                '__MAX_SOURCE_ROWS__',
                '__MAX_ROUTE_ROWS__',
                '__MAX_VERIFY_IDENTITIES__',
                '__MAX_RESOLUTION_LINKS__',
                '__MAX_POST_TYPES__',
                '__MAX_QUERY_VARS__',
                '__POST_COLUMNS__',
                '__ROUTE_POST_COLUMNS__',
                '__ROUTE_OPTION_NAMES__',
                '__COMMENT_COLUMNS__',
                '__USER_COLUMNS__',
            ],
            [
                $filters,
                $format,
                (string) self::MAX_PROJECTION_ROWS,
                (string) self::MAX_PROJECTION_RAW_BYTES,
                (string) self::MAX_PROJECTION_ROW_RAW_BYTES,
                (string) self::MAX_PROJECTION_SERIALIZED_BYTES,
                (string) self::MAX_PROJECTION_ROW_SERIALIZED_BYTES,
                (string) self::MAX_PROJECTION_PAGE_RAW_BYTES,
                (string) self::MAX_PROJECTION_CHUNK_ROWS,
                (string) self::MAX_SOURCE_POST_ROWS,
                (string) self::MAX_ROUTE_IDENTITY_ROWS,
                (string) self::MAX_VERIFICATION_IDENTITIES,
                (string) self::MAX_NATIVE_RESOLUTION_LINKS,
                (string) self::MAX_REGISTERED_POST_TYPES,
                (string) self::MAX_PUBLIC_QUERY_VARS,
                var_export(self::POST_WITNESS_COLUMNS, true),
                var_export(self::ROUTE_POST_COLUMNS, true),
                var_export(self::ROUTE_OPTION_NAMES, true),
                var_export(self::COMMENT_WITNESS_COLUMNS, true),
                var_export(self::USER_WITNESS_COLUMNS, true),
            ],
            $template
        );
    }
}
