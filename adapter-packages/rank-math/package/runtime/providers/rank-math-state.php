<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;

/** Rank Math 1.0.277.x schema preparation and native internal-link repair. */
final class RankMathState extends ManifestProviderRuntime {
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
    private const MAX_PUBLIC_QUERY_VAR_BYTES = 4096;
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
        if (is_multisite()) {
            throw new \RuntimeException('wprism: Rank Math link repair refuses multisite');
        }
        $this->assert_link_schema();
        $before = $this->link_projection();
        $first = $this->repair_link_state_pass();
        $after = $this->repair_link_state_pass();
        if ($first !== $after) {
            throw new \RuntimeException('wprism: Rank Math native link repair is not idempotent');
        }

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

    /** @return array<string,mixed> */
    protected function observe_fresh_postimage_rebuild_all_link_state(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: Rank Math full link repair accepts no arguments');
        }
        $this->assert_link_schema();
        return $this->link_projection_within_snapshot();
    }

    /** @return array<string,int|string|bool> */
    protected function project_fresh_postimage_rebuild_all_link_state(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $expected = [
            'dependency_hash',
            'dependency_state_hash',
            'enabled',
            'link_count',
            'link_hash',
            'marker_count',
            'marker_hash',
            'meta_count',
            'meta_hash',
            'post_count',
            'post_hash',
        ];
        if ($keys !== $expected || !is_bool($value['enabled'] ?? null)) {
            throw new \RuntimeException(
                'wprism: Rank Math fresh-process link projection is malformed; recovery_required'
            );
        }
        foreach (['post', 'link', 'meta', 'marker'] as $surface) {
            $count = $value[$surface . '_count'] ?? null;
            $hash = $value[$surface . '_hash'] ?? null;
            if (!is_int($count)
                || $count < 0
                || !is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException(
                    'wprism: Rank Math fresh-process link projection is malformed; recovery_required'
                );
            }
        }
        foreach (['dependency_hash', 'dependency_state_hash'] as $hash) {
            if (!is_string($value[$hash] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $value[$hash]) !== 1) {
                throw new \RuntimeException(
                    'wprism: Rank Math fresh-process link projection is malformed; recovery_required'
                );
            }
        }
        return [
            'enabled' => $value['enabled'],
            'post_count' => $value['post_count'],
            'post_hash' => $value['post_hash'],
            'dependency_state_hash' => $value['dependency_state_hash'],
            'link_count' => $value['link_count'],
            'link_hash' => $value['link_hash'],
            'meta_count' => $value['meta_count'],
            'meta_hash' => $value['meta_hash'],
            'marker_count' => $value['marker_count'],
            'marker_hash' => $value['marker_hash'],
        ];
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

    /** @param ?list<string> $suffixes @return array<string,mixed> */
    private function schema_projection(
        bool $requireComplete,
        ?array $suffixes = null,
        bool $validatePresent = false,
        bool $includeRows = false
    ): array {
        global $wpdb;
        $tables = [];
        foreach (self::REQUIRED_TABLES as $suffix => $_required) {
            if ($suffixes !== null && !in_array($suffix, $suffixes, true)) {
                continue;
            }
            $tables[$suffix] = $wpdb->prefix . $suffix;
        }
        return ProviderSdk::database_schema_snapshot(
            'Rank Math schema evidence',
            array_values($tables),
            function (array $presence) use (
                $requireComplete,
                $validatePresent,
                $includeRows,
                $tables
            ): array {
                global $wpdb;
                $projection = [];
                foreach ($tables as $suffix => $table) {
                    $required = self::REQUIRED_TABLES[$suffix];
                    if (($presence[$table] ?? null) !== true) {
                        if ($requireComplete) {
                            throw new \RuntimeException(
                                'wprism: Rank Math schema preparation did not create every required table'
                            );
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
                        throw new \RuntimeException(
                            'wprism: Rank Math schema disagrees with the audited column/index contract'
                        );
                    }
                    $projection[$suffix] = [
                        'present' => true,
                        'schema_hash' => hash('sha256', serialize($observed)),
                    ];
                    if ($includeRows) {
                        $projection[$suffix] += $this->schema_row_projection($suffix, $table, $required);
                    }
                }
                return $projection;
            }
        );
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
            'SELECT COUNT(*) AS row_count, '
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
     * can filter the resulting routes. The full hash binds that request-local
     * runtime vector; dependency_state_hash excludes only that vector while
     * retaining its durable option inputs and resolved native-link witness, so
     * the checked parent can compare every stable route dependency after repair.
     *
     * @param array<string,string> $types
     * @param null|callable(array<string,mixed>,bool):void $visitor
     * @param null|callable(array<string,mixed>):void $edgeVisitor
     * @return array{
     *   authors:list<int>,
     *   projection:array{row_count:int,rows_sha256:string},
     *   resolution:array{link_count:int,resolution_sha256:string}
     * }
     */
    private function source_dependency_projection(
        array $types,
        ?callable $visitor = null,
        ?callable $edgeVisitor = null
    ): array {
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
                $processor,
                $visitor,
                $edgeVisitor
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
                clean_post_cache($id);
                $post = get_post($id);
                foreach (self::POST_WITNESS_COLUMNS as $column) {
                    if (!$post || (string) ($post->$column ?? '') !== (string) ($row[$column] ?? '')) {
                        throw new \RuntimeException(
                            'wprism: Rank Math post object cache disagrees with its bounded database witness'
                        );
                    }
                }
                $processable = \RankMath\Links\Links::is_post_processable($post);
                if ($processable) {
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
                        $edge = [
                            'url' => $link,
                            'post_id' => $id,
                            'target_post_id' => $target,
                            'type' => $type,
                        ];
                        $linkCount++;
                        $postEdgeCount++;
                        if ($linkCount > self::MAX_NATIVE_RESOLUTION_LINKS) {
                            throw new \RuntimeException(
                                'wprism: Rank Math native link expectation exceeds its reviewed identity bound'
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
            // WP_Rewrite::add_endpoint() preserves the endpoint name as its
            // query var: WooCommerce 11.0.1 registers "wc/file/transient".
            // This is a binary-safe serialized dependency witness, not an
            // identifier, URL or SQL input. Bound bytes without normalizing
            // names or dropping entries that must remain visible to drift.
            if (!is_string($queryVar)
                || strlen($queryVar) > self::MAX_PUBLIC_QUERY_VAR_BYTES) {
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
     * @param null|callable(array<string,mixed>):void $routePostVisitor
     * @return array{dependency_sha256:string,state_sha256:string}
     */
    private function route_dependency_projection(
        array $types,
        array $source,
        array $runtime,
        ?callable $routePostVisitor = null
    ): array {
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
                self::MAX_ROUTE_IDENTITY_ROWS,
                $routePostVisitor
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
        $state = $parts;
        unset($state['runtime']);
        return [
            'dependency_sha256' => hash('sha256', serialize($parts)),
            'state_sha256' => hash('sha256', serialize($state)),
        ];
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
        return ProviderSdk::database_read_snapshot(
            'Rank Math scoped link evidence',
            [$links, $meta, $postmeta],
            function () use ($links, $meta, $postmeta): array {
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
                return [
                    'link_count' => $linkProjection['row_count'],
                    'link_hash' => $linkProjection['rows_sha256'],
                    'meta_count' => $metaProjection['row_count'],
                    'meta_hash' => $metaProjection['rows_sha256'],
                    'marker_count' => $markerProjection['row_count'],
                    'marker_hash' => $markerProjection['rows_sha256'],
                ];
            }
        );
    }

    /** @return array<string,mixed> */
    private function link_projection(): array {
        $tables = $this->link_state_tables(true);
        return ProviderSdk::database_read_snapshot(
            'Rank Math complete link evidence',
            $tables,
            fn(): array => $this->link_projection_within_snapshot()
        );
    }

    /** @return array<string,mixed> */
    private function link_projection_within_snapshot(): array {
        $enabled = $this->durable_link_counter_enabled();
        $this->assert_native_link_runtime($enabled);
        $native = $this->audited_native_exclusion_callback();
        return $this->complete_link_projection_within_snapshot($enabled, $native);
    }

    private function durable_link_counter_enabled(): bool {
        $modules = ProviderSdk::checked_durable_option(
            'rank_math_modules',
            [],
            'Rank Math durable module state'
        );
        return is_array($modules) && in_array('link-counter', $modules, true);
    }

    private function assert_durable_link_counter_state(bool $expected): void {
        if ($this->durable_link_counter_enabled() !== $expected) {
            throw new \RuntimeException('wprism: Rank Math link-counter module changed during native repair');
        }
    }

    private function assert_native_link_runtime(bool $enabled): void {
        if (!$enabled) {
            return;
        }
        if (!class_exists('RankMath\\Links\\Links')
            || !is_callable(['RankMath\\Links\\Links', 'process_post_links'])
            || !is_callable(['RankMath\\Links\\Links', 'is_post_processable'])
            || !class_exists('RankMath\\Links\\ContentProcessor')
            || !is_callable(['RankMath\\Links\\ContentProcessor', 'get'])
            || !class_exists('RankMath\\Helper')
            || !is_callable(['RankMath\\Helper', 'get_accessible_post_types'])
            || !function_exists('clean_post_cache')) {
            throw new \RuntimeException(
                'wprism: Rank Math link-counter API is unavailable while its module is enabled'
            );
        }
    }

    /** @return list<string> */
    private function route_dependency_tables(): array {
        global $wpdb;
        return [
            $wpdb->posts,
            // url_to_postid() uses WP_Query, whose cold-cache path reads
            // postmeta while resolving a pretty permalink (post.php:8093).
            $wpdb->postmeta,
            $wpdb->options,
            $wpdb->comments,
            $wpdb->term_relationships,
            $wpdb->term_taxonomy,
            $wpdb->terms,
            $wpdb->users,
            $wpdb->usermeta,
        ];
    }

    /** @return list<string> */
    private function link_write_tables(): array {
        global $wpdb;
        return [
            $wpdb->prefix . 'rank_math_internal_links',
            $wpdb->prefix . 'rank_math_internal_meta',
            $wpdb->postmeta,
        ];
    }

    /** @return list<string> */
    private function link_state_tables(bool $enabled): array {
        global $wpdb;
        return array_values(array_unique(array_merge(
            $this->link_write_tables(),
            [$wpdb->options],
            $enabled ? $this->route_dependency_tables() : []
        )));
    }

    /**
     * @param array{object,string} $native
     * @param null|callable(array<string,mixed>,bool):void $sourceVisitor
     * @param null|callable(array<string,mixed>):void $routePostVisitor
     * @param null|callable(array<string,mixed>):void $edgeVisitor
     * @return array{
     *   types:list<string>,
     *   source_projection:array{row_count:int,rows_sha256:string},
     *   dependency_sha256:string,
     *   dependency_state_sha256:string
     * }
     */
    private function dependency_state_within_snapshot(
        bool $enabled,
        array $native,
        ?callable $sourceVisitor = null,
        ?callable $routePostVisitor = null,
        ?callable $edgeVisitor = null
    ): array {
        $types = $enabled ? $this->accessible_post_types($native) : [];
        $runtime = $enabled ? $this->route_runtime_projection($types) : [];
        $source = $this->source_dependency_projection($types, $sourceVisitor, $edgeVisitor);
        if ($enabled && $runtime !== $this->route_runtime_projection($types)) {
            throw new \RuntimeException(
                'wprism: Rank Math route runtime changed during its complete projection'
            );
        }
        $disabled = hash('sha256', serialize(['enabled' => false]));
        $dependency = $enabled
            ? $this->route_dependency_projection($types, $source, $runtime, $routePostVisitor)
            : ['dependency_sha256' => $disabled, 'state_sha256' => $disabled];
        return [
            'types' => array_keys($types),
            'source_projection' => $source['projection'],
            'dependency_sha256' => $dependency['dependency_sha256'],
            'dependency_state_sha256' => $dependency['state_sha256'],
        ];
    }

    /**
     * @param array{object,string} $native
     * @param list<string> $routeTables
     * @return array<string,mixed>
     */
    private function read_dependency_state(bool $enabled, array $native, array $routeTables): array {
        global $wpdb;
        return ProviderSdk::database_read_snapshot(
            'Rank Math dependency evidence',
            $enabled ? $routeTables : [$wpdb->options],
            function () use ($enabled, $native): array {
                $this->assert_durable_link_counter_state($enabled);
                return $this->dependency_state_within_snapshot($enabled, $native);
            }
        );
    }

    /** @param array{object,string} $native @return array<string,mixed> */
    private function complete_link_projection_within_snapshot(bool $enabled, array $native): array {
        $this->assert_durable_link_counter_state($enabled);
        [$links, $meta, $postmeta] = $this->link_write_tables();
        $state = $this->dependency_state_within_snapshot($enabled, $native);
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
        return [
            'enabled' => $enabled,
            'post_count' => $state['source_projection']['row_count'],
            'post_hash' => hash('sha256', serialize([
                'types' => $state['types'],
                'projection' => $state['source_projection'],
            ])),
            'dependency_hash' => $state['dependency_sha256'],
            'dependency_state_hash' => $state['dependency_state_sha256'],
            'link_count' => $linkProjection['row_count'],
            'link_hash' => $linkProjection['rows_sha256'],
            'meta_count' => $metaProjection['row_count'],
            'meta_hash' => $metaProjection['rows_sha256'],
            'marker_count' => $markerProjection['row_count'],
            'marker_hash' => $markerProjection['rows_sha256'],
        ];
    }

    /**
     * @param array{object,string} $native
     * @param list<string> $tables
     * @return array<string,mixed>
     */
    private function read_complete_link_projection(bool $enabled, array $native, array $tables): array {
        return ProviderSdk::database_read_snapshot(
            'Rank Math complete link evidence',
            $tables,
            fn(): array => $this->complete_link_projection_within_snapshot($enabled, $native)
        );
    }

    private function clear_derived_link_state(): void {
        [$links, $meta, $postmeta] = $this->link_write_tables();
        ProviderSdk::database_delete_all($links, 'Rank Math derived-link rows reset');
        ProviderSdk::database_delete_all($meta, 'Rank Math derived-link metadata reset');
        ProviderSdk::database_delete(
            $postmeta,
            ['meta_key' => 'rank_math_internal_links_processed'],
            'Rank Math processed-link markers reset',
            '%s'
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function process_source_post(array $row, bool $processable, int &$lastId): void {
        $id = (int) ($row['ID'] ?? 0);
        if ($id <= $lastId) {
            throw new \RuntimeException(
                'wprism: Rank Math accessible-post projection contains an invalid identity'
            );
        }
        $lastId = $id;
        clean_post_cache($id);
        $post = get_post($id);
        foreach (self::POST_WITNESS_COLUMNS as $column) {
            if (!$post || (string) ($post->$column ?? '') !== (string) ($row[$column] ?? '')) {
                throw new \RuntimeException(
                    'wprism: Rank Math post object changed after its bounded precondition witness'
                );
            }
        }
        $currentProcessable = \RankMath\Links\Links::is_post_processable($post);
        if ($currentProcessable !== $processable) {
            throw new \RuntimeException('wprism: Rank Math processability changed during native repair');
        }
        if ($processable) {
            \RankMath\Links\Links::process_post_links($id, $post);
        }
    }

    /**
     * Verify every native edge, per-object count and processed marker while the
     * engine still owns the exact snapshot/transaction used for the decision.
     *
     * @param array{object,string} $native
     * @param array<string,mixed> $initialDependencyState
     */
    private function verify_link_state_within_snapshot(
        bool $enabled,
        array $native,
        array $initialDependencyState
    ): void {
        $this->assert_durable_link_counter_state($enabled);
        global $wpdb;
        [$links, $meta, $postmeta] = $this->link_write_tables();
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
                throw new \RuntimeException(
                    'wprism: Rank Math accessible-post projection contains an invalid identity'
                );
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
            if ($expectedEdgeCount > self::MAX_PROJECTION_ROWS) {
                throw new \RuntimeException(
                    'wprism: Rank Math native link expectation exceeds its reviewed row bound'
                );
            }
            $counts[$source][$type] = ($counts[$source][$type] ?? 0) + 1;
            if ($target > 0) {
                $expectedTargets[$target] = true;
                $affected[$target] = true;
                if (count($affected) > self::MAX_VERIFICATION_IDENTITIES) {
                    throw new \RuntimeException(
                        'wprism: Rank Math link verification exceeds its reviewed identity bound'
                    );
                }
                $incomingCounts[$target] = ($incomingCounts[$target] ?? 0) + 1;
            }
        };
        $routeVisitor = static function (array $row) use (&$routeSet, &$lastRouteId): void {
            $id = (int) ($row['ID'] ?? 0);
            if ($id <= $lastRouteId) {
                throw new \RuntimeException(
                    'wprism: Rank Math route-post projection contains an invalid identity'
                );
            }
            $lastRouteId = $id;
            $routeSet[$id] = true;
        };
        $dependencyState = $this->dependency_state_within_snapshot(
            $enabled,
            $native,
            $sourceVisitor,
            $routeVisitor,
            $edgeVisitor
        );
        if ($dependencyState !== $initialDependencyState) {
            throw new \RuntimeException('wprism: Rank Math route dependencies changed during native repair');
        }
        foreach (array_keys($expectedTargets) as $target) {
            if (!isset($routeSet[$target])) {
                throw new \RuntimeException(
                    'wprism: Rank Math native link expectation resolved outside its route witness'
                );
            }
        }
        $linkVisitor = static function (array $row) use ($sourceSet, &$expectedEdges): void {
            $url = $row['url'] ?? null;
            $source = (int) ($row['post_id'] ?? 0);
            $target = (int) ($row['target_post_id'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            if (!is_string($url) || !isset($sourceSet[$source])) {
                throw new \RuntimeException(
                    'wprism: Rank Math link projection contains a stale or invalid native row'
                );
            }
            $edgeKey = hash('sha256', serialize([
                'url' => $url,
                'post_id' => $source,
                'target_post_id' => $target,
                'type' => $type,
            ]));
            if (($expectedEdges[$edgeKey] ?? 0) < 1) {
                throw new \RuntimeException(
                    'wprism: Rank Math stored links did not converge with current native resolution'
                );
            }
            $expectedEdges[$edgeKey]--;
        };
        $linkProjection = $this->stream_projection(
            $links,
            ['url', 'post_id', 'target_post_id', 'type'],
            '',
            'post_id, url, target_post_id, type, id',
            'Rank Math verification link projection',
            self::MAX_PROJECTION_ROWS,
            $linkVisitor
        );
        if ($linkProjection['row_count'] !== $expectedEdgeCount || array_sum($expectedEdges) !== 0) {
            throw new \RuntimeException(
                'wprism: Rank Math stored links did not converge with current native resolution'
            );
        }
        if (count($affected) > self::MAX_VERIFICATION_IDENTITIES) {
            throw new \RuntimeException(
                'wprism: Rank Math link verification exceeds its reviewed identity bound'
            );
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
                throw new \RuntimeException(
                    'wprism: Rank Math link-count projection contains a stale or duplicate object'
                );
            }
            if ((int) ($row['internal_link_count'] ?? -1) !== (int) ($counts[$id]['internal'] ?? 0)
                || (int) ($row['external_link_count'] ?? -1) !== (int) ($counts[$id]['external'] ?? 0)
                || (int) ($row['incoming_link_count'] ?? -1) !== (int) ($incomingCounts[$id] ?? 0)) {
                throw new \RuntimeException('wprism: Rank Math link counts did not converge');
            }
            $metaSeen[$id] = true;
        };
        $metaProjection = $this->stream_projection(
            $meta,
            ['object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count'],
            '',
            'object_id',
            'Rank Math verification count projection',
            self::MAX_PROJECTION_ROWS,
            $metaVisitor
        );
        if (count($metaSeen) !== count($affected)) {
            throw new \RuntimeException('wprism: Rank Math link-count identity set did not converge');
        }
        $markerIds = [];
        $markerVisitor = static function (array $marker) use ($sourceSet, &$markerIds): void {
            $id = (int) ($marker['post_id'] ?? 0);
            if (!isset($sourceSet[$id])
                || isset($markerIds[$id])
                || (string) ($marker['meta_value'] ?? '') === '') {
                throw new \RuntimeException(
                    'wprism: Rank Math processed-marker projection contains a stale or duplicate object'
                );
            }
            $markerIds[$id] = true;
        };
        $markerProjection = $this->stream_projection(
            $postmeta,
            ['post_id', 'meta_value'],
            "WHERE meta_key = 'rank_math_internal_links_processed'",
            'post_id, meta_id',
            'Rank Math processed-marker verification',
            self::MAX_PROJECTION_ROWS,
            $markerVisitor
        );
        if (!$enabled && ($linkProjection['row_count'] !== 0
            || $metaProjection['row_count'] !== 0
            || $markerProjection['row_count'] !== 0)) {
            throw new \RuntimeException('wprism: Rank Math disabled link-counter retained derived state');
        }
        if (count($markerIds) !== count($sourceSet)) {
            throw new \RuntimeException('wprism: Rank Math processed-marker identity set did not converge');
        }
    }

    /**
     * @param array{object,string} $native
     * @param list<string> $tables
     * @param array<string,mixed> $initialDependencyState
     */
    private function read_verified_link_state(
        bool $enabled,
        array $native,
        array $tables,
        array $initialDependencyState
    ): void {
        ProviderSdk::database_read_snapshot(
            'Rank Math verification evidence',
            $tables,
            fn(): mixed => $this->verify_link_state_within_snapshot(
                $enabled,
                $native,
                $initialDependencyState
            )
        );
    }

    /** @return array<string,mixed> */
    private function repair_link_state_pass(): array {
        global $wpdb;
        $enabled = ProviderSdk::database_read_snapshot(
            'Rank Math module-state evidence',
            [$wpdb->options],
            fn(): bool => $this->durable_link_counter_enabled()
        );
        $this->assert_native_link_runtime($enabled);
        $native = $this->audited_native_exclusion_callback();
        $routeTables = $this->route_dependency_tables();
        $tables = $this->link_state_tables($enabled);
        $initialDependencyState = $this->read_dependency_state($enabled, $native, $routeTables);
        if ($this->read_dependency_state($enabled, $native, $routeTables) !== $initialDependencyState) {
            throw new \RuntimeException('wprism: Rank Math route dependencies changed during native repair');
        }
        $before = $this->read_complete_link_projection($enabled, $native, $tables);
        ProviderSdk::database_write_contract_transaction(
            'Rank Math native link repair',
            function () use ($enabled, $native, $initialDependencyState): array {
                $this->assert_durable_link_counter_state($enabled);
                $this->clear_derived_link_state();
                $lastId = 0;
                $visitor = function (array $row, bool $processable) use (&$lastId): void {
                    $this->process_source_post($row, $processable, $lastId);
                };
                $state = $this->dependency_state_within_snapshot(
                    $enabled,
                    $native,
                    $enabled ? $visitor : null
                );
                if ($state !== $initialDependencyState) {
                    throw new \RuntimeException(
                        'wprism: Rank Math route dependencies changed during native repair'
                    );
                }
                $this->verify_link_state_within_snapshot($enabled, $native, $initialDependencyState);
                return $state;
            },
            function (mixed $_state) use (
                $enabled,
                $native,
                $initialDependencyState,
                $before
            ): string {
                try {
                    $this->verify_link_state_within_snapshot($enabled, $native, $initialDependencyState);
                    return ProviderSdk::DATABASE_POSTIMAGE_APPLIED;
                } catch (\Throwable) {
                }
                try {
                    $current = $this->complete_link_projection_within_snapshot($enabled, $native);
                } catch (\Throwable) {
                    return ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN;
                }
                return $current === $before
                    ? ProviderSdk::DATABASE_POSTIMAGE_NOT_APPLIED
                    : ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN;
            }
        );
        if ($this->read_dependency_state($enabled, $native, $routeTables) !== $initialDependencyState) {
            throw new \RuntimeException('wprism: Rank Math route dependencies changed during native repair');
        }
        $this->read_verified_link_state($enabled, $native, $tables, $initialDependencyState);
        return $this->read_complete_link_projection($enabled, $native, $tables);
    }
}
