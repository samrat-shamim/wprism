<?php
declare(strict_types=1);

namespace {
    $wprismRoot = dirname(__DIR__, 4);
    require_once $wprismRoot . '/sandbox/tests/lib/check.php';
    require_once $wprismRoot . '/sandbox/tests/lib/FakeWpdb.php';
    require_once $wprismRoot . '/sandbox/tests/support/wp_cli_child_process_fake.php';

    $GLOBALS['rank_math_test_modules'] = ['link-counter', 'redirections'];
    $GLOBALS['rank_math_test_command_calls'] = [];
    $GLOBALS['rank_math_test_command_result'] = null;
    $GLOBALS['rank_math_test_command_throw'] = null;
    $GLOBALS['rank_math_test_after_command'] = null;
    $GLOBALS['rank_math_test_installer_calls'] = [];
    $GLOBALS['rank_math_test_installer_omit'] = null;
    $GLOBALS['rank_math_test_installer_extra_column'] = false;
    $GLOBALS['rank_math_test_child_ids'] = [10];
    $GLOBALS['rank_math_test_old_targets'] = [999];
    $GLOBALS['rank_math_test_hooks'] = [];

    function get_option(string $name, mixed $default = false): mixed {
        return $name === 'rank_math_modules' ? $GLOBALS['rank_math_test_modules'] : $default;
    }

    function has_filter(string $hook): int|false {
        return isset($GLOBALS['rank_math_test_hooks'][$hook]) ? 10 : false;
    }

    final class WP_CLI {
        use \WPrismTest\WpCliChildRuntime;

        public static function runcommand(string $command, array $options): mixed {
            $GLOBALS['rank_math_test_command_calls'][] = [$command, $options];
            if (preg_match('/\$oldTargetIds = \[([0-9,]*)\];/', $command, $match) === 1) {
                $GLOBALS['rank_math_test_old_targets'] = $match[1] === ''
                    ? []
                    : array_values(array_map('intval', explode(',', $match[1])));
            }
            if ($GLOBALS['rank_math_test_command_throw'] instanceof \Throwable) {
                throw $GLOBALS['rank_math_test_command_throw'];
            }
            if (is_callable($GLOBALS['rank_math_test_after_command'])) {
                ($GLOBALS['rank_math_test_after_command'])();
            }
            $result = $GLOBALS['rank_math_test_command_result'];
            return is_callable($result) ? $result() : $result;
        }
    }
}

namespace RankMath {
    final class Installer {
        /** @param list<string> $modules */
        public static function create_tables(array $modules): void {
            $GLOBALS['rank_math_test_installer_calls'][] = $modules;
            $schema = \rank_math_test_schema();
            $wanted = [];
            if (in_array('link-counter', $modules, true)) {
                $wanted = array_merge($wanted, ['rank_math_internal_links', 'rank_math_internal_meta']);
            }
            if (in_array('redirections', $modules, true)) {
                $wanted = array_merge($wanted, ['rank_math_redirections', 'rank_math_redirections_cache']);
            }
            foreach ($wanted as $table) {
                $tableSchema = $schema[$table];
                $omit = $GLOBALS['rank_math_test_installer_omit'];
                if (is_string($omit)) {
                    $tableSchema['columns'] = array_values(array_filter(
                        $tableSchema['columns'],
                        static fn(array $row): bool => ($row['Field'] ?? null) !== $omit
                    ));
                }
                if ($GLOBALS['rank_math_test_installer_extra_column'] === true) {
                    $tableSchema['columns'][] = [
                        'Field' => 'hostile_required_value',
                        'Type' => 'varchar(32)',
                        'Collation' => 'utf8mb4_unicode_ci',
                        'Null' => 'NO',
                        'Key' => '',
                        'Default' => null,
                        'Extra' => '',
                    ];
                }
                $GLOBALS['wpdb']->setColumnDefinitions($table, $tableSchema['columns']);
                $GLOBALS['wpdb']->setIndexes($table, $tableSchema['indexes']);
            }
        }
    }
}

namespace {
    require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderSdk.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Kernel/WpCliChildProcess.php';
    require_once dirname(__DIR__, 4) . '/adapter-packages/rank-math/package/runtime/providers/rank-math-state.php';

    use WPrism\Providers\RankMathState;
    use WPrismTest\FakeWpdb;

    /** @return array<string,array<string,string>> */
    function rank_math_test_columns(): array {
        $names = [
            'rank_math_internal_links' => ['id', 'url', 'post_id', 'target_post_id', 'type'],
            'rank_math_internal_meta' => [
                'object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count',
            ],
            'rank_math_redirections' => [
                'id', 'sources', 'url_to', 'header_code', 'hits', 'status', 'created',
                'updated', 'last_accessed',
            ],
            'rank_math_redirections_cache' => [
                'id', 'from_url', 'redirection_id', 'object_id', 'object_type', 'is_redirected',
            ],
            'postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
        ];
        $columns = [];
        foreach ($names as $table => $tableNames) {
            foreach ($tableNames as $name) {
                $columns[$table][$name] = str_ends_with($name, '_id') || $name === 'id' ? 'bigint' : 'longtext';
            }
        }
        return $columns;
    }

    /** @return array<string,array{columns:list<array<string,mixed>>,indexes:list<array<string,mixed>>}> */
    function rank_math_test_schema(): array {
        $column = static function (
            string $field,
            string $type,
            string $null = 'NO',
            mixed $default = null,
            string $extra = '',
            ?string $collation = null
        ): array {
            return [
                'Field' => $field,
                'Type' => $type,
                'Collation' => $collation,
                'Null' => $null,
                'Key' => '',
                'Default' => $default,
                'Extra' => $extra,
            ];
        };
        $index = static fn(
            string $name,
            int $nonUnique,
            int $sequence,
            string $field
        ): array => [
            'Key_name' => $name,
            'Non_unique' => $nonUnique,
            'Seq_in_index' => $sequence,
            'Column_name' => $field,
            'Sub_part' => null,
            'Index_type' => 'BTREE',
        ];
        return [
            'rank_math_internal_links' => [
                'columns' => [
                    $column('id', 'bigint(20) unsigned', extra: 'auto_increment'),
                    $column('url', 'varchar(255)'),
                    $column('post_id', 'bigint(20) unsigned'),
                    $column('target_post_id', 'bigint(20) unsigned'),
                    $column('type', 'varchar(8)'),
                ],
                'indexes' => [
                    $index('PRIMARY', 0, 1, 'id'),
                    $index('link_direction', 1, 1, 'post_id'),
                    $index('link_direction', 1, 2, 'type'),
                    $index('target_post_id', 1, 1, 'target_post_id'),
                ],
            ],
            'rank_math_internal_meta' => [
                'columns' => [
                    $column('object_id', 'bigint(20) unsigned'),
                    $column('internal_link_count', 'int(10) unsigned', 'YES', '0'),
                    $column('external_link_count', 'int(10) unsigned', 'YES', '0'),
                    $column('incoming_link_count', 'int(10) unsigned', 'YES', '0'),
                ],
                'indexes' => [$index('PRIMARY', 0, 1, 'object_id')],
            ],
            'rank_math_redirections' => [
                'columns' => [
                    $column('id', 'bigint(20) unsigned', extra: 'auto_increment'),
                    $column('sources', 'longtext', collation: 'utf8mb4_bin'),
                    $column('url_to', 'text'),
                    $column('header_code', 'smallint(4) unsigned'),
                    $column('hits', 'bigint(20) unsigned', default: '0'),
                    $column('status', 'varchar(25)', default: 'active'),
                    $column('created', 'datetime', default: '0000-00-00 00:00:00'),
                    $column('updated', 'datetime', default: '0000-00-00 00:00:00'),
                    $column('last_accessed', 'datetime', default: '0000-00-00 00:00:00'),
                ],
                'indexes' => [
                    $index('PRIMARY', 0, 1, 'id'),
                    $index('status', 1, 1, 'status'),
                    $index('idx_rm_status_updated', 1, 1, 'status'),
                    $index('idx_rm_status_updated', 1, 2, 'updated'),
                ],
            ],
            'rank_math_redirections_cache' => [
                'columns' => [
                    $column('id', 'bigint(20) unsigned', extra: 'auto_increment'),
                    $column('from_url', 'text', collation: 'utf8mb4_bin'),
                    $column('redirection_id', 'bigint(20) unsigned'),
                    $column('object_id', 'bigint(20) unsigned', default: '0'),
                    $column('object_type', 'varchar(10)', default: 'post'),
                    $column('is_redirected', 'tinyint(1)', default: '0'),
                ],
                'indexes' => [
                    $index('PRIMARY', 0, 1, 'id'),
                    $index('redirection_id', 1, 1, 'redirection_id'),
                ],
            ],
        ];
    }

    /** @param list<string> $tables */
    function rank_math_test_install_tables(array $tables): void {
        $schema = rank_math_test_schema();
        foreach ($tables as $table) {
            $GLOBALS['wpdb']->setColumnDefinitions($table, $schema[$table]['columns']);
            $GLOBALS['wpdb']->setIndexes($table, $schema[$table]['indexes']);
        }
    }

    /** @return list<array<string,mixed>> */
    function rank_math_test_desired_links(): array {
        return [
            ['id' => 101, 'url' => '/target-20', 'post_id' => 10, 'target_post_id' => 20, 'type' => 'internal'],
            ['id' => 102, 'url' => 'https://external.example.test/مرحباً', 'post_id' => 10, 'target_post_id' => 0, 'type' => 'external'],
            ['id' => 103, 'url' => '/target-10', 'post_id' => 20, 'target_post_id' => 10, 'type' => 'internal'],
        ];
    }

    /** @return list<array<string,mixed>> */
    function rank_math_test_desired_meta(): array {
        return [
            ['object_id' => 10, 'internal_link_count' => 1, 'external_link_count' => 1, 'incoming_link_count' => 1],
            ['object_id' => 20, 'internal_link_count' => 1, 'external_link_count' => 0, 'incoming_link_count' => 1],
            ['object_id' => 999, 'internal_link_count' => 0, 'external_link_count' => 0, 'incoming_link_count' => 0],
        ];
    }

    /** @param ?list<int> $ids */
    function rank_math_test_rebuild(?array $ids): void {
        /** @var FakeWpdb $db */
        $db = $GLOBALS['wpdb'];
        if (!in_array('link-counter', $GLOBALS['rank_math_test_modules'], true)) {
            return;
        }
        $desiredLinks = rank_math_test_desired_links();
        $desiredMeta = rank_math_test_desired_meta();
        if ($ids === null) {
            $db->seedTable('rank_math_internal_links', $desiredLinks);
            $db->seedTable('rank_math_internal_meta', $desiredMeta);
            return;
        }

        $idSet = array_fill_keys($ids, true);
        $existingLinks = $db->rows('rank_math_internal_links');
        $oldTargets = [];
        foreach ($existingLinks as $row) {
            if (isset($idSet[(int) $row['post_id']]) && (int) $row['target_post_id'] > 0) {
                $oldTargets[(int) $row['target_post_id']] = true;
            }
        }
        $links = array_values(array_filter(
            $existingLinks,
            static fn(array $row): bool => !isset($idSet[(int) $row['post_id']])
        ));
        foreach ($desiredLinks as $row) {
            if (isset($idSet[(int) $row['post_id']])) {
                $links[] = $row;
            }
        }
        $affected = $idSet + $oldTargets;
        foreach ($links as $row) {
            if (isset($idSet[(int) $row['post_id']]) && (int) $row['target_post_id'] > 0) {
                $affected[(int) $row['target_post_id']] = true;
            }
        }
        $meta = array_values(array_filter(
            $db->rows('rank_math_internal_meta'),
            static fn(array $row): bool => !isset($affected[(int) $row['object_id']])
        ));
        foreach ($desiredMeta as $row) {
            if (isset($affected[(int) $row['object_id']])) {
                $meta[] = $row;
            }
        }
        $markers = array_values(array_filter(
            $db->rows('postmeta'),
            static fn(array $row): bool => (string) $row['meta_key'] !== 'rank_math_internal_links_processed'
                || !isset($idSet[(int) $row['post_id']])
        ));
        $nextMetaId = 900;
        foreach ($ids as $id) {
            $markers[] = [
                'meta_id' => $nextMetaId++,
                'post_id' => $id,
                'meta_key' => 'rank_math_internal_links_processed',
                'meta_value' => '1',
            ];
        }
        $db->seedTable('rank_math_internal_links', $links);
        $db->seedTable('rank_math_internal_meta', $meta);
        $db->seedTable('postmeta', $markers);
    }

    /** @param ?list<int> $ids @param list<int> $additionalTargets @return array<string,mixed> */
    function rank_math_test_projection(?array $ids, array $additionalTargets = []): array {
        /** @var FakeWpdb $db */
        $db = $GLOBALS['wpdb'];
        $links = $db->prefix . 'rank_math_internal_links';
        $meta = $db->prefix . 'rank_math_internal_meta';
        $where = $ids === null ? '' : ' WHERE post_id IN (' . implode(',', $ids) . ')';
        $linkRows = $db->get_results(
            "SELECT url, post_id, target_post_id, type FROM `$links`$where ORDER BY post_id, url, target_post_id, type",
            ARRAY_A
        );
        if (!is_array($linkRows)) {
            throw new RuntimeException('fixture link projection failed');
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
        $metaRows = $db->get_results(
            "SELECT object_id, internal_link_count, external_link_count, incoming_link_count FROM `$meta`$metaWhere ORDER BY object_id",
            ARRAY_A
        );
        $markerRows = [];
        if ($ids !== null) {
            $markerRows = $db->get_results(
                "SELECT post_id, meta_value FROM `{$db->postmeta}` WHERE meta_key = 'rank_math_internal_links_processed' "
                    . 'AND post_id IN (' . implode(',', $ids) . ') ORDER BY post_id, meta_id',
                ARRAY_A
            );
        }
        if (!is_array($metaRows) || !is_array($markerRows)) {
            throw new RuntimeException('fixture count projection failed');
        }
        return [
            'enabled' => in_array('link-counter', $GLOBALS['rank_math_test_modules'], true),
            'post_count' => $ids === null ? null : count($ids),
            'link_count' => count($linkRows),
            'link_hash' => hash('sha256', serialize($linkRows)),
            'meta_count' => count($metaRows),
            'meta_hash' => hash('sha256', serialize($metaRows)),
            'marker_count' => count($markerRows),
            'marker_hash' => hash('sha256', serialize($markerRows)),
        ];
    }

    /** @param ?list<int> $ids */
    function rank_math_test_result(?array $ids, ?array $projection = null): object {
        return (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'wprism-rank-math-link-rebuild/v1',
                'projection' => $projection ?? rank_math_test_projection(
                    $ids,
                    $ids === null ? [] : $GLOBALS['rank_math_test_old_targets']
                ),
                'verified' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'stderr' => '',
        ];
    }

    function rank_math_test_reset(string $schema = 'full'): RankMathState {
        $GLOBALS['rank_math_test_modules'] = ['link-counter', 'redirections'];
        $GLOBALS['rank_math_test_command_calls'] = [];
        $GLOBALS['rank_math_test_command_throw'] = null;
        $GLOBALS['rank_math_test_installer_calls'] = [];
        $GLOBALS['rank_math_test_installer_omit'] = null;
        $GLOBALS['rank_math_test_installer_extra_column'] = false;
        $GLOBALS['rank_math_test_child_ids'] = [10];
        $GLOBALS['rank_math_test_old_targets'] = [999];
        $GLOBALS['rank_math_test_hooks'] = [];
        $GLOBALS['wprism_wp_cli_child_fake_stderr_first'] = false;

        $db = FakeWpdb::install();
        $db->setColumns('postmeta', rank_math_test_columns()['postmeta']);
        $db->seedTable('postmeta', [
            ['meta_id' => 1, 'post_id' => 10, 'meta_key' => 'rank_math_internal_links_processed', 'meta_value' => 'stale'],
            ['meta_id' => 2, 'post_id' => 77, 'meta_key' => 'target_only_runtime', 'meta_value' => 'preserve'],
        ]);
        if ($schema === 'full') {
            rank_math_test_install_tables([
                'rank_math_internal_links', 'rank_math_internal_meta',
                'rank_math_redirections', 'rank_math_redirections_cache',
            ]);
        } elseif ($schema === 'link') {
            rank_math_test_install_tables(['rank_math_internal_links', 'rank_math_internal_meta']);
        }
        if ($schema !== 'none') {
            $db->seedTable('rank_math_internal_links', [
                ['id' => 1, 'url' => '/stale', 'post_id' => 10, 'target_post_id' => 999, 'type' => 'internal'],
                ['id' => 2, 'url' => '/target-only', 'post_id' => 77, 'target_post_id' => 88, 'type' => 'internal'],
            ]);
            $db->seedTable('rank_math_internal_meta', [
                ['object_id' => 10, 'internal_link_count' => 99, 'external_link_count' => 99, 'incoming_link_count' => 99],
                ['object_id' => 77, 'internal_link_count' => 1, 'external_link_count' => 0, 'incoming_link_count' => 0],
                ['object_id' => 88, 'internal_link_count' => 0, 'external_link_count' => 0, 'incoming_link_count' => 1],
                ['object_id' => 999, 'internal_link_count' => 0, 'external_link_count' => 0, 'incoming_link_count' => 1],
            ]);
        }
        $GLOBALS['rank_math_test_after_command'] = static function (): void {
            rank_math_test_rebuild($GLOBALS['rank_math_test_child_ids']);
        };
        $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
            $GLOBALS['rank_math_test_child_ids']
        );

        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/adapter-packages/rank-math/package/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return new RankMathState($manifest['providers'][0]);
    }

    /** @return string */
    function rank_math_test_throw_message(callable $call): string {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure->getMessage();
        }
        throw new RuntimeException('expected Rank Math provider refusal');
    }

    $provider = rank_math_test_reset('none');
    wprism_check_same(
        ['id' => 'rank-math-state', 'plugin' => 'seo-by-rank-math/rank-math.php', 'version' => '1.0.0'],
        $provider->identity(),
        'provider identity makes schema and link-repair behavior digest-visible'
    );
    $schemaReceipt = $provider->invoke('prepare_schema', []);
    wprism_check_same(
        [['link-counter', 'redirections']],
        $GLOBALS['rank_math_test_installer_calls'],
        'lifecycle settlement asks the native installer for both reviewed module schemas'
    );
    foreach (array_keys(rank_math_test_columns()) as $table) {
        if ($table === 'postmeta') {
            continue;
        }
        wprism_check_same(false, $schemaReceipt['before'][$table]['present'] ?? null, "$table starts absent on a clean target");
        wprism_check_same(true, $schemaReceipt['after'][$table]['present'] ?? null, "$table is present after native lifecycle settlement");
    }
    $schemaRetry = $provider->invoke('prepare_schema', []);
    wprism_check_same(
        $schemaRetry['before'],
        $schemaRetry['after'],
        'repeated lifecycle settlement is an exact no-op at the checked schema projection'
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', ['unexpected' => true]),
        RuntimeException::class,
        'schema capability refuses undeclared arguments',
        'accepts no arguments'
    );

    $provider = rank_math_test_reset('none');
    $GLOBALS['rank_math_test_installer_omit'] = 'last_accessed';
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', []),
        RuntimeException::class,
        'native installer acknowledgement cannot hide an incomplete redirection schema',
        'disagrees with the audited column/index contract'
    );
    $provider = rank_math_test_reset('none');
    $GLOBALS['rank_math_test_installer_extra_column'] = true;
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', []),
        RuntimeException::class,
        'an undeclared required column cannot enter the provider rollback boundary under a success-shaped installer call',
        'disagrees with the audited column/index contract'
    );
    foreach (['rank_math/admin/create_tables', 'rank_math/admin/after_create_tables'] as $hook) {
        $provider = rank_math_test_reset('none');
        $GLOBALS['rank_math_test_hooks'][$hook] = true;
        wprism_check_throws(
            static fn(): array => $provider->invoke('prepare_schema', []),
            RuntimeException::class,
            "an extension callback on $hook refuses before native schema mutation",
            'refuses an unreviewed schema callback'
        );
        wprism_check_same([], $GLOBALS['rank_math_test_installer_calls'], "$hook refusal never calls the installer");
    }
    $provider = rank_math_test_reset('none');
    $GLOBALS['wpdb']->failNextQuery('schema secret sk_rank_math_schema', 'SHOW TABLES');
    $schemaFailure = rank_math_test_throw_message(static fn(): array => $provider->invoke('prepare_schema', []));
    wprism_check(
        str_contains($schemaFailure, 'checked read failed') && !str_contains($schemaFailure, 'sk_rank_math_schema'),
        'schema discovery failure is loud and value-redacted'
    );

    $provider = rank_math_test_reset('link');
    $receipt = $provider->invoke('rebuild_link_state', [
        'entities' => [
            ['kind' => 'post:post', 'id' => 10],
            ['kind' => 'post:post', 'id' => 10],
        ],
    ]);
    wprism_check_same(true, $receipt['verified'] ?? null, 'entity link repair returns a verified value-level receipt');
    wprism_check_same(1, $receipt['after']['post_count'] ?? null, 'duplicate trigger identities collapse before native repair');
    wprism_check_same(2, $receipt['after']['link_count'] ?? null, 'entity repair restores the exact internal and external source edges');
    wprism_check_same(3, $receipt['after']['meta_count'] ?? null,
        'entity repair covers source plus both previous and newly referenced target counts');
    $oldTargetRows = array_values(array_filter(
        $GLOBALS['wpdb']->rows('rank_math_internal_meta'),
        static fn(array $row): bool => (int) $row['object_id'] === 999
    ));
    wprism_check_same(0, $oldTargetRows[0]['incoming_link_count'] ?? null,
        'retargeting explicitly converges the previous target incoming count to zero');
    wprism_check_same(1, $receipt['after']['marker_count'] ?? null, 'entity repair proves the native processed marker');
    wprism_check_same(
        [['id' => 2, 'url' => '/target-only', 'post_id' => 77, 'target_post_id' => 88, 'type' => 'internal']],
        array_values(array_filter(
            $GLOBALS['wpdb']->rows('rank_math_internal_links'),
            static fn(array $row): bool => (int) $row['post_id'] === 77
        )),
        'entity repair preserves unrelated target-only derived rows'
    );
    wprism_check_same('preserve', $GLOBALS['wpdb']->rows('postmeta')[0]['meta_value'] ?? null,
        'entity repair preserves unrelated target-only post metadata');
    wprism_check_same(1, count($GLOBALS['rank_math_test_command_calls']), 'entity repair launches exactly one fresh process');
    [$command, $options] = $GLOBALS['rank_math_test_command_calls'][0];
    wprism_check(
        str_contains($command, '$ids = [10]')
            && str_contains($command, '$oldTargetIds = [999]')
            && str_contains($command, 'RankMath\\Links\\Links::process_post_links')
            && substr_count($command, 'process_post_links($id, $post)') === 2
            && str_contains($command, 'is_multisite()')
            && str_contains($command, 'RankMath\\Helper::get_accessible_post_types'),
        'fresh child carries the deduplicated batch, native API, idempotence pass and scope guard'
    );
    foreach ([
        'rank_math/excluded_post_types',
        'rank_math/links/content', 'rank_math/links/extract', 'rank_math/links/link_type',
        'rank_math/links/process_post', 'rank_math/links/save_links',
    ] as $filter) {
        wprism_check(str_contains($command, $filter), "fresh child refuses unreviewed callback authority at $filter");
    }
    wprism_check_same(
        ['launch' => true, 'return' => 'all', 'exit_error' => false],
        $options,
        'native repair runs through the bounded checked child-process transport'
    );
    $published = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    wprism_check(
        is_string($published)
            && !str_contains($published, '/target-20')
            && !str_contains($published, 'external.example.test')
            && !str_contains($published, 'مرحباً'),
        'provider receipt exposes only counts and hashes, never authored link values'
    );
    $retry = $provider->invoke('rebuild_link_state', [
        'entities' => [['kind' => 'post:book', 'id' => 10]],
    ]);
    wprism_check_same(2, $retry['before']['meta_count'] ?? null,
        'the next invocation narrows its witness to the current source/target union after the retired target was proved once');
    wprism_check_same($retry['before'], $retry['after'], 'native retry is idempotent at its exact current projection');
    wprism_check_same(0, $oldTargetRows[0]['incoming_link_count'] ?? null,
        'narrowing a later retry never reintroduces the already-settled previous-target count');

    $provider = rank_math_test_reset('full');
    $GLOBALS['rank_math_test_child_ids'] = null;
    $full = $provider->invoke('rebuild_all_link_state', []);
    wprism_check(array_key_exists('post_count', $full['after']) && $full['after']['post_count'] === null,
        'module transition rebuild binds the full site projection');
    wprism_check_same(3, $full['after']['link_count'] ?? null, 'full rebuild replaces the complete link projection');
    wprism_check(str_contains($GLOBALS['rank_math_test_command_calls'][0][0], '$ids = null'),
        'site-scope module transition inventories posts in the fresh process');

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_modules'] = [];
    $GLOBALS['rank_math_test_child_ids'] = null;
    $disabledBefore = rank_math_test_projection(null);
    $disabled = $provider->invoke('rebuild_all_link_state', []);
    wprism_check_same($disabledBefore, $disabled['after'], 'disabled link module is a verified non-mutating native no-op');
    wprism_check_same(false, $disabled['after']['enabled'] ?? null, 'receipt binds the disabled module decision');

    $invalidBatches = [
        'empty batch' => [],
        'wildcard identity' => [['kind' => 'post:*', 'id' => 10]],
        'term identity' => [['kind' => 'term:category', 'id' => 10]],
        'zero identity' => [['kind' => 'post:post', 'id' => 0]],
        'string identity' => [['kind' => 'post:post', 'id' => '10']],
        'extra entity member' => [['kind' => 'post:post', 'id' => 10, 'extra' => true]],
        'wrong entity member order' => [['id' => 10, 'kind' => 'post:post']],
    ];
    foreach ($invalidBatches as $label => $entities) {
        $provider = rank_math_test_reset('link');
        wprism_check_throws(
            static fn(): array => $provider->invoke('rebuild_link_state', ['entities' => $entities]),
            RuntimeException::class,
            "$label refuses before mutation",
            $entities === [] ? 'batch is empty' : 'invalid post identity'
        );
        wprism_check_same([], $GLOBALS['rank_math_test_command_calls'], "$label launches no child process");
    }
    $provider = rank_math_test_reset('link');
    $tooMany = [];
    for ($id = 1; $id <= 10001; $id++) {
        $tooMany[] = ['kind' => 'post:post', 'id' => $id];
    }
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_link_state', ['entities' => $tooMany]),
        RuntimeException::class,
        'entity batch above the reviewed bound refuses before mutation',
        'exceeds its reviewed bound'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['wpdb']->failNextQuery('projection secret sk_rank_math_projection', 'SELECT url');
    $readFailure = rank_math_test_throw_message(static fn(): array => $provider->invoke(
        'rebuild_link_state',
        ['entities' => [['kind' => 'post:post', 'id' => 10]]]
    ));
    wprism_check(
        str_contains($readFailure, 'checked read failed') && !str_contains($readFailure, 'sk_rank_math_projection'),
        'pre-mutation projection failure is loud, redacted and launches no child'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'], 'failed precondition read launches no child process');

    $hostile = 'child token sk_rank_math_child_must_not_escape';
    $cases = [
        'nonzero exit' => (object) ['return_code' => 9, 'stdout' => '', 'stderr' => $hostile],
        'stderr on success' => (object) ['return_code' => 0, 'stdout' => '{}', 'stderr' => $hostile],
        'malformed json' => (object) ['return_code' => 0, 'stdout' => '{' . $hostile, 'stderr' => ''],
        'extra receipt authority' => (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'wprism-rank-math-link-rebuild/v1',
                'projection' => [],
                'verified' => true,
                'secret' => $hostile,
            ]),
            'stderr' => '',
        ],
    ];
    foreach ($cases as $label => $result) {
        $provider = rank_math_test_reset('link');
        $GLOBALS['rank_math_test_command_result'] = $result;
        $message = rank_math_test_throw_message(static fn(): array => $provider->invoke(
            'rebuild_link_state',
            ['entities' => [['kind' => 'post:post', 'id' => 10]]]
        ));
        wprism_check(
            str_contains($message, 'recovery_required') && !str_contains($message, 'sk_rank_math_child'),
            "$label refuses with recovery debt and no child-output leak"
        );
    }

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_command_throw'] = new RuntimeException($hostile);
    $launchFailure = rank_math_test_throw_message(static fn(): array => $provider->invoke(
        'rebuild_link_state',
        ['entities' => [['kind' => 'post:post', 'id' => 10]]]
    ));
    wprism_check(
        str_contains($launchFailure, 'bounded fresh process') && !str_contains($launchFailure, 'sk_rank_math_child'),
        'child launch exception is wrapped without exposing its value'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_command_result'] = static function (): object {
        $projection = rank_math_test_projection([10]);
        $projection['link_hash'] = str_repeat('d', 64);
        return rank_math_test_result([10], $projection);
    };
    wprism_check_throws(
        static fn(): array => $provider->invoke(
            'rebuild_link_state',
            ['entities' => [['kind' => 'post:post', 'id' => 10]]]
        ),
        RuntimeException::class,
        'success-shaped child receipt with a divergent exact projection refuses',
        'disagrees with checked parent readback'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        rank_math_test_rebuild([10]);
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection([10], [999]);
        $rows = $GLOBALS['wpdb']->rows('rank_math_internal_meta');
        foreach ($rows as &$row) {
            if ((int) $row['object_id'] === 999) {
                $row['incoming_link_count'] = 1;
            }
        }
        unset($row);
        $GLOBALS['wpdb']->seedTable('rank_math_internal_meta', $rows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        [10],
        $GLOBALS['rank_math_test_proved_projection']
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke(
            'rebuild_link_state',
            ['entities' => [['kind' => 'post:book', 'id' => 10]]]
        ),
        RuntimeException::class,
        'a removed link whose previous target retains a stale incoming count refuses verified success',
        'disagrees with checked parent readback'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        rank_math_test_rebuild([10]);
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection([10], [999]);
        $rows = $GLOBALS['wpdb']->rows('rank_math_internal_meta');
        foreach ($rows as &$row) {
            if ((int) $row['object_id'] === 10) {
                $row['internal_link_count'] = 31337;
            }
        }
        unset($row);
        $GLOBALS['wpdb']->seedTable('rank_math_internal_meta', $rows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        [10],
        $GLOBALS['rank_math_test_proved_projection']
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke(
            'rebuild_link_state',
            ['entities' => [['kind' => 'post:post', 'id' => 10]]]
        ),
        RuntimeException::class,
        'same-count competing derived-state write after child proof refuses recovery debt',
        'disagrees with checked parent readback'
    );

    wprism_check_summary('regress_rank_math_provider');
}
