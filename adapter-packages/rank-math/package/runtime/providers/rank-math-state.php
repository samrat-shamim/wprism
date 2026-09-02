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
        'rank_math/links/link_type',
        'rank_math/links/process_post',
        'rank_math/links/save_links',
    ];

    private const SCHEMA_HOOKS = [
        'rank_math/admin/create_tables',
        'rank_math/admin/after_create_tables',
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

    private const MAX_ENTITY_BATCH = 10000;
    private const MAX_PROJECTION_ROWS = 200000;

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
        $before = $this->schema_projection(false);
        \RankMath\Installer::create_tables(['link-counter', 'redirections']);
        $after = $this->schema_projection(true);

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_rebuild_all_link_state(array $args): array {
        if ($args !== []) {
            throw new \RuntimeException('wprism: Rank Math full link repair accepts no arguments');
        }
        $this->assert_link_schema();
        $before = $this->link_projection(null);
        $child = $this->run_child(null, [], 570);
        $after = $this->link_projection(null);
        $this->assert_child_matches($child, $after);

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_rebuild_link_state(array $args): array {
        $ids = $this->entity_ids($args);
        $this->assert_link_schema();
        $oldTargets = $this->link_target_ids($ids);
        $before = $this->link_projection($ids, $oldTargets);
        $child = $this->run_child($ids, $oldTargets, 270);
        $after = $this->link_projection($ids, $oldTargets);
        $this->assert_child_matches($child, $after);

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return list<int> */
    private function entity_ids(array $args): array {
        if (array_keys($args) !== ['entities'] || !is_array($args['entities']) || !array_is_list($args['entities'])) {
            throw new \RuntimeException('wprism: Rank Math entity repair requires exactly the engine entity batch');
        }
        if ($args['entities'] === [] || count($args['entities']) > self::MAX_ENTITY_BATCH) {
            throw new \RuntimeException('wprism: Rank Math entity repair batch is empty or exceeds its reviewed bound');
        }
        $ids = [];
        foreach ($args['entities'] as $entity) {
            if (!is_array($entity)
                || array_keys($entity) !== ['kind', 'id']
                || !is_string($entity['kind'] ?? null)
                || preg_match('/^post:[a-z0-9][a-z0-9._-]{0,127}$/D', $entity['kind']) !== 1
                || !is_int($entity['id'] ?? null)
                || $entity['id'] <= 0) {
                throw new \RuntimeException('wprism: Rank Math entity repair received an invalid post identity');
            }
            $ids[$entity['id']] = $entity['id'];
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<string,mixed> */
    /** @param ?list<string> $suffixes */
    private function schema_projection(bool $requireComplete, ?array $suffixes = null): array {
        global $wpdb;
        $projection = [];
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
            if ($requireComplete && $observed !== $required) {
                throw new \RuntimeException('wprism: Rank Math schema disagrees with the audited column/index contract');
            }
            $projection[$suffix] = [
                'present' => true,
                'schema_hash' => hash('sha256', serialize($observed)),
            ];
        }
        return $projection;
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

    /** @param list<int> $ids @return list<int> */
    private function link_target_ids(array $ids): array {
        global $wpdb;
        $links = $wpdb->prefix . 'rank_math_internal_links';
        $rows = ProviderSdk::checked_get_col(
            "SELECT target_post_id FROM `$links` WHERE post_id IN (" . implode(',', $ids)
                . ') AND target_post_id > 0 ORDER BY target_post_id',
            'Rank Math previous link-target projection',
            $wpdb
        );
        if (count($rows) > self::MAX_PROJECTION_ROWS) {
            throw new \RuntimeException('wprism: Rank Math previous link-target projection exceeds its reviewed row bound');
        }
        $targets = [];
        foreach ($rows as $target) {
            $target = (int) $target;
            if ($target > 0) {
                $targets[$target] = $target;
            }
        }
        $targets = array_values($targets);
        sort($targets, SORT_NUMERIC);
        return $targets;
    }

    /** @param ?list<int> $ids @param list<int> $additionalTargets @return array<string,mixed> */
    private function link_projection(?array $ids, array $additionalTargets = []): array {
        global $wpdb;
        $links = $wpdb->prefix . 'rank_math_internal_links';
        $meta = $wpdb->prefix . 'rank_math_internal_meta';
        $postmeta = $wpdb->postmeta;
        $where = $ids === null ? '' : ' WHERE post_id IN (' . implode(',', $ids) . ')';
        $linkRows = ProviderSdk::checked_get_results(
            "SELECT url, post_id, target_post_id, type FROM `$links`$where ORDER BY post_id, url, target_post_id, type",
            'Rank Math link projection',
            $wpdb
        );
        if (count($linkRows) > self::MAX_PROJECTION_ROWS) {
            throw new \RuntimeException('wprism: Rank Math link projection exceeds its reviewed row bound');
        }
        $affected = $ids === null ? null : array_fill_keys(array_merge($ids, $additionalTargets), true);
        if (is_array($affected)) {
            foreach ($linkRows as $row) {
                $target = (int) ($row['target_post_id'] ?? 0);
                if ($target > 0) {
                    $affected[$target] = true;
                }
            }
        }
        $metaWhere = $affected === null ? '' : ' WHERE object_id IN (' . implode(',', array_keys($affected)) . ')';
        $metaRows = ProviderSdk::checked_get_results(
            "SELECT object_id, internal_link_count, external_link_count, incoming_link_count FROM `$meta`$metaWhere "
                . 'ORDER BY object_id',
            'Rank Math link-count projection',
            $wpdb
        );
        if (count($metaRows) > self::MAX_PROJECTION_ROWS) {
            throw new \RuntimeException('wprism: Rank Math link-count projection exceeds its reviewed row bound');
        }
        $markerRows = [];
        if ($ids !== null) {
            $markerRows = ProviderSdk::checked_get_results(
                "SELECT post_id, meta_value FROM `$postmeta` WHERE meta_key = 'rank_math_internal_links_processed' "
                    . 'AND post_id IN (' . implode(',', $ids) . ') ORDER BY post_id, meta_id',
                'Rank Math processed-marker projection',
                $wpdb
            );
        }
        $modules = get_option('rank_math_modules', []);
        $enabled = is_array($modules) && in_array('link-counter', $modules, true);

        return [
            'enabled' => $enabled,
            'post_count' => $ids === null ? null : count($ids),
            'link_count' => count($linkRows),
            'link_hash' => hash('sha256', serialize($linkRows)),
            'meta_count' => count($metaRows),
            'meta_hash' => hash('sha256', serialize($metaRows)),
            'marker_count' => count($markerRows),
            'marker_hash' => hash('sha256', serialize($markerRows)),
        ];
    }

    /** @param ?list<int> $ids @param list<int> $oldTargets @return array<string,mixed> */
    private function run_child(?array $ids, array $oldTargets, int $timeout): array {
        $literal = $ids === null ? 'null' : '[' . implode(',', $ids) . ']';
        $oldTargetLiteral = '[' . implode(',', $oldTargets) . ']';
        try {
            $result = WpCliChildProcess::capture(
                'eval ' . escapeshellarg(self::child_payload($literal, $oldTargetLiteral)),
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

    private static function child_payload(string $idsLiteral, string $oldTargetLiteral): string {
        $filters = var_export(self::FILTERS, true);
        $format = self::CHILD_FORMAT;
        $maxRows = self::MAX_PROJECTION_ROWS;
        $template = <<<'PHP'
if (is_multisite()) {
    throw new RuntimeException('Rank Math link repair refuses multisite');
}
if (!class_exists('RankMath\\Links\\Links') || !is_callable(['RankMath\\Links\\Links', 'process_post_links'])
    || !is_callable(['RankMath\\Links\\Links', 'is_post_processable'])) {
    $modules = get_option('rank_math_modules', []);
    if (is_array($modules) && in_array('link-counter', $modules, true)) {
        throw new RuntimeException('Rank Math link-counter API is unavailable while its module is enabled');
    }
}
$filters = __FILTERS__;
foreach ($filters as $filter) {
    if (has_filter($filter) !== false) {
        throw new RuntimeException('Rank Math link repair refuses an unreviewed link-processing callback');
    }
}
global $wpdb;
$links = $wpdb->prefix . 'rank_math_internal_links';
$meta = $wpdb->prefix . 'rank_math_internal_meta';
$ids = __IDS__;
$oldTargetIds = __OLD_TARGET_IDS__;
$full = $ids === null;
$modules = get_option('rank_math_modules', []);
$enabled = is_array($modules) && in_array('link-counter', $modules, true);
if ($ids === null && $enabled) {
    if (!class_exists('RankMath\\Helper') || !is_callable(['RankMath\\Helper', 'get_accessible_post_types'])) {
        throw new RuntimeException('Rank Math post-type inventory API is unavailable while its module is enabled');
    }
    $types = RankMath\Helper::get_accessible_post_types();
    unset($types['attachment']);
    $quoted = array_map(static fn($type) => "'" . esc_sql((string) $type) . "'", array_keys($types));
    $wpdb->last_error = '';
    $ids = $quoted === [] ? [] : $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (" . implode(',', $quoted) . ') ORDER BY ID'
    );
    if (!is_array($ids) || (string) $wpdb->last_error !== '') {
        throw new RuntimeException('Rank Math full post inventory query failed');
    }
    $ids = array_values(array_map('intval', $ids));
}
if ($ids === null) {
    $ids = [];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
sort($ids, SORT_NUMERIC);
$projection = static function (array $sourceIds) use ($wpdb, $links, $meta, $enabled, $full, $oldTargetIds): array {
    $where = $full ? '' : ($sourceIds === [] ? ' WHERE 1=0' : ' WHERE post_id IN (' . implode(',', $sourceIds) . ')');
    $wpdb->last_error = '';
    $linkRows = $wpdb->get_results(
        "SELECT url, post_id, target_post_id, type FROM `$links`$where ORDER BY post_id, url, target_post_id, type",
        ARRAY_A
    );
    if (!is_array($linkRows) || (string) $wpdb->last_error !== '' || count($linkRows) > __MAX_ROWS__) {
        throw new RuntimeException('Rank Math link projection query failed or exceeded its row bound');
    }
    $affected = $full ? null : array_fill_keys(array_merge($sourceIds, $oldTargetIds), true);
    if (is_array($affected)) {
        foreach ($linkRows as $row) {
            $target = (int) ($row['target_post_id'] ?? 0);
            if ($target > 0) {
                $affected[$target] = true;
            }
        }
    }
    $metaWhere = $affected === null ? '' : ($affected === [] ? ' WHERE 1=0' : ' WHERE object_id IN (' . implode(',', array_keys($affected)) . ')');
    $wpdb->last_error = '';
    $metaRows = $wpdb->get_results(
        "SELECT object_id, internal_link_count, external_link_count, incoming_link_count FROM `$meta`$metaWhere ORDER BY object_id",
        ARRAY_A
    );
    if (!is_array($metaRows) || (string) $wpdb->last_error !== '' || count($metaRows) > __MAX_ROWS__) {
        throw new RuntimeException('Rank Math link-count projection query failed or exceeded its row bound');
    }
    $markerRows = [];
    if (!$full && $sourceIds !== []) {
        $wpdb->last_error = '';
        $markerRows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_internal_links_processed' "
                . 'AND post_id IN (' . implode(',', $sourceIds) . ') ORDER BY post_id, meta_id',
            ARRAY_A
        );
        if (!is_array($markerRows) || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('Rank Math processed-marker projection query failed');
        }
    }
    return [
        'enabled' => $enabled,
        'post_count' => $full ? null : count($sourceIds),
        'link_count' => count($linkRows),
        'link_hash' => hash('sha256', serialize($linkRows)),
        'meta_count' => count($metaRows),
        'meta_hash' => hash('sha256', serialize($metaRows)),
        'marker_count' => count($markerRows),
        'marker_hash' => hash('sha256', serialize($markerRows)),
    ];
};
$verify = static function (array $sourceIds) use ($wpdb, $links, $meta, $oldTargetIds): void {
    if ($sourceIds === []) {
        return;
    }
    $where = implode(',', $sourceIds);
    $wpdb->last_error = '';
    $rows = $wpdb->get_results(
        "SELECT post_id, target_post_id, type FROM `$links` WHERE post_id IN ($where) ORDER BY post_id, id",
        ARRAY_A
    );
    if (!is_array($rows) || (string) $wpdb->last_error !== '') {
        throw new RuntimeException('Rank Math verification link query failed');
    }
    $counts = [];
    $affected = array_fill_keys(array_merge($sourceIds, $oldTargetIds), true);
    foreach ($rows as $row) {
        $source = (int) ($row['post_id'] ?? 0);
        $target = (int) ($row['target_post_id'] ?? 0);
        $type = (string) ($row['type'] ?? '');
        if (!in_array($type, ['internal', 'external'], true) || $source <= 0) {
            throw new RuntimeException('Rank Math link projection contains an invalid native row');
        }
        $counts[$source][$type] = ($counts[$source][$type] ?? 0) + 1;
        if ($target > 0) {
            $affected[$target] = true;
        }
    }
    $metaRows = $wpdb->get_results(
        "SELECT object_id, internal_link_count, external_link_count, incoming_link_count FROM `$meta` "
            . 'WHERE object_id IN (' . implode(',', array_keys($affected)) . ') ORDER BY object_id',
        ARRAY_A
    );
    if (!is_array($metaRows) || (string) $wpdb->last_error !== '') {
        throw new RuntimeException('Rank Math verification count query failed');
    }
    $byId = [];
    foreach ($metaRows as $row) {
        $byId[(int) $row['object_id']] = $row;
    }
    foreach ($sourceIds as $id) {
        $post = get_post($id);
        if (!$post || !RankMath\Links\Links::is_post_processable($post)) {
            continue;
        }
        $row = $byId[$id] ?? null;
        if (!is_array($row)
            || (int) $row['internal_link_count'] !== (int) ($counts[$id]['internal'] ?? 0)
            || (int) $row['external_link_count'] !== (int) ($counts[$id]['external'] ?? 0)
            || !get_post_meta($id, 'rank_math_internal_links_processed', true)) {
            throw new RuntimeException('Rank Math source link counts or processed marker did not converge');
        }
    }
    foreach (array_keys($affected) as $id) {
        $wpdb->last_error = '';
        $incoming = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM `$links` WHERE target_post_id = %d AND type = 'internal'", $id)
        );
        if ($incoming === null || (string) $wpdb->last_error !== '') {
            throw new RuntimeException('Rank Math incoming-link count query failed');
        }
        $row = $byId[$id] ?? null;
        if ((!is_array($row) && (int) $incoming !== 0)
            || (is_array($row) && (int) $row['incoming_link_count'] !== (int) $incoming)) {
            throw new RuntimeException('Rank Math incoming-link counts did not converge');
        }
    }
};
if ($enabled) {
    foreach ($ids as $id) {
        $post = get_post($id);
        if ($post) {
            RankMath\Links\Links::process_post_links($id, $post);
        }
    }
    $verify($ids);
    $first = $projection($ids);
    foreach ($ids as $id) {
        $post = get_post($id);
        if ($post) {
            RankMath\Links\Links::process_post_links($id, $post);
        }
    }
    $verify($ids);
    $second = $projection($ids);
    if ($first !== $second) {
        throw new RuntimeException('Rank Math native link repair is not idempotent');
    }
}
echo wp_json_encode([
    'format' => '__FORMAT__',
    'projection' => $projection($ids),
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP;
        return str_replace(
            ['__FILTERS__', '__FORMAT__', '__IDS__', '__OLD_TARGET_IDS__', '__MAX_ROWS__'],
            [$filters, $format, $idsLiteral, $oldTargetLiteral, (string) $maxRows],
            $template
        );
    }
}
