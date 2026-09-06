<?php
declare(strict_types=1);

namespace {
    $wprismRoot = dirname(__DIR__, 4);
    $runtimeRoot = isset($argv[1]) ? realpath($argv[1]) : $wprismRoot;
    if (!is_string($runtimeRoot)
        || !is_file($runtimeRoot . '/agent/src/Adapter/ProviderSdk.php')
        || !is_file($runtimeRoot . '/adapter-packages/rank-math/package/runtime/providers/rank-math-state.php')) {
        throw new RuntimeException('fixture needs one complete Rank Math runtime tree');
    }
    define('RANK_MATH_TEST_RUNTIME_ROOT', $runtimeRoot);
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
    $GLOBALS['rank_math_test_installer_mutate_table'] = null;
    $GLOBALS['rank_math_test_hooks'] = [];
    $GLOBALS['rank_math_test_types'] = ['page' => 'page', 'post' => 'post'];
    $GLOBALS['rank_math_test_helper_types'] = ['page' => 'page', 'post' => 'post'];
    $GLOBALS['rank_math_test_options'] = [];
    $GLOBALS['rank_math_test_clean_post_cache_calls'] = 0;
    $GLOBALS['rank_math_test_permalink_calls'] = 0;
    $GLOBALS['rank_math_test_url_to_postid_calls'] = 0;
    $GLOBALS['rank_math_test_url_to_postid_override'] = null;
    $GLOBALS['rank_math_test_in_native_process'] = false;
    $GLOBALS['rank_math_test_rebuild_links_override'] = null;
    $GLOBALS['rank_math_test_process_calls'] = 0;

    final class WP_Hook {
        /** @var array<int,array<string,array{accepted_args:int,function:array{object,string}}>> */
        public array $callbacks;

        /** @param array<int,array<string,array{accepted_args:int,function:array{object,string}}>> $callbacks */
        public function __construct(array $callbacks) { $this->callbacks = $callbacks; }
    }

    class WP_Rewrite {
        public mixed $rules;

        public function __construct(mixed $rules) { $this->rules = $rules; }
    }

    final class RankMathExtendedRewrite extends WP_Rewrite {}

    #[\AllowDynamicProperties]
    class WP_Post {
        public function __construct(object $row) {
            foreach (get_object_vars($row) as $name => $value) {
                $this->$name = $value;
            }
        }
    }

    function get_option(string $name, mixed $default = false): mixed {
        if ($name === 'rank_math_modules') {
            return $GLOBALS['rank_math_test_modules'];
        }
        return $GLOBALS['rank_math_test_options'][$name] ?? $default;
    }

    function wp_cache_flush(): bool {
        $GLOBALS['rank_math_test_cache_flush_calls']++;
        return true;
    }

    function get_permalink(int|object $post): string|false {
        $GLOBALS['rank_math_test_permalink_calls']++;
        $post = is_object($post) ? $post : get_post($post);
        if (!is_object($post)) {
            return false;
        }
        $slug = (string) ($post->post_name ?? '');
        $structure = (string) get_option('permalink_structure', '');
        if (str_contains($structure, '%author%')) {
            $author = 'unknown';
            foreach ($GLOBALS['wpdb']->rows('users') as $row) {
                if ((int) ($row['ID'] ?? 0) === (int) ($post->post_author ?? 0)) {
                    $author = (string) ($row['user_nicename'] ?? 'unknown');
                }
            }
            $slug = $author . '/' . $slug;
        }
        if (str_contains($structure, '%category%')) {
            $slug = 'category-' . (string) get_option('default_category', 1) . '/' . $slug;
        }
        return home_url('/' . $slug . '/');
    }

    function url_to_postid(string $url): int {
        $GLOBALS['rank_math_test_url_to_postid_calls']++;
        if (($GLOBALS['rank_math_test_cold_postmeta_cache'] ?? false) === true) {
            // WordPress's pretty-permalink WP_Query primes post metadata even
            // when Rank Math only asks for the resolved post id.
            $database = $GLOBALS['wpdb'];
            $database->get_results(
                "SELECT post_id, meta_key, meta_value FROM {$database->postmeta} "
                    . 'WHERE post_id IN (10,20) ORDER BY meta_id ASC',
                ARRAY_A
            );
            $GLOBALS['rank_math_test_postmeta_cache_reads']++;
        }
        $override = $GLOBALS['rank_math_test_url_to_postid_override'] ?? null;
        if (is_callable($override)) {
            $resolved = $override($url, (bool) ($GLOBALS['rank_math_test_in_native_process'] ?? false));
            if (is_int($resolved)) {
                return $resolved;
            }
        }
        if (str_contains($url, '/wp-core-page/')) {
            foreach ([
                'close_comments_days_old', 'close_comments_for_old_posts', 'comments_per_page',
                'page_for_posts', 'posts_per_page', 'posts_per_rss', 'sticky_posts',
                'wp_page_for_privacy_policy',
            ] as $option) {
                get_option($option);
            }
            return (int) get_option('posts_per_page', 10) === 10
                && (int) get_option('close_comments_days_old', 14) === 14 ? 20 : 10;
        }
        if (str_contains($url, '/comments/feed/')) {
            $comments = $GLOBALS['wpdb']->rows('comments');
            return ($comments[0]['comment_approved'] ?? null) === '1' ? 20 : 10;
        }
        if (str_contains($url, 'target-20') || str_contains($url, '/twenty')) {
            return 20;
        }
        return str_contains($url, '/ten') ? 10 : 0;
    }

    function home_url(string $path = ''): string {
        return 'https://source.example.test' . $path;
    }

    function get_post_type_object(string $name): ?object {
        if (!isset($GLOBALS['rank_math_test_types'][$name])) {
            return null;
        }
        return (object) [
            'has_archive' => false,
            'hierarchical' => $name === 'page',
            'publicly_queryable' => true,
            'query_var' => $name,
            'rewrite' => ['slug' => $name],
        ];
    }

    function has_filter(string $hook): int|false {
        return isset($GLOBALS['rank_math_test_hooks'][$hook]) ? 10 : false;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        $gate = is_array($GLOBALS['wp_filter'] ?? null)
            ? ($GLOBALS['wp_filter'][$hook] ?? null)
            : null;
        if (is_object($gate) && method_exists($gate, 'apply_filters')) {
            return $gate->apply_filters($value, $args);
        }
        return $value;
    }

    /** @return array<string,string> */
    function get_post_types(array $args = []): array {
        return $GLOBALS['rank_math_test_types'];
    }

    function is_post_type_viewable(string $type): bool { return isset($GLOBALS['rank_math_test_types'][$type]); }

    function esc_sql(string $value): string { return str_replace("'", "''", $value); }

    function is_multisite(): bool { return false; }

    function clean_post_cache(int $id): void { $GLOBALS['rank_math_test_clean_post_cache_calls']++; }

    function get_post(int $id): ?object {
        foreach ($GLOBALS['wpdb']->rows('posts') as $row) {
            if ((int) ($row['ID'] ?? 0) === $id) {
                return (object) $row;
            }
        }
        return null;
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false {
        return json_encode($value, $flags);
    }

    final class WP_CLI {
        use \WPrismTest\WpCliChildRuntime;

        public static function runcommand(string $command, array $options): mixed {
            $GLOBALS['rank_math_test_command_calls'][] = [$command, $options];
            if (class_exists(\WPrism\ProviderOperationProcess::class, false)) {
                $pendingIdentity = new \ReflectionMethod(
                    \WPrism\ProviderOperationProcess::class,
                    'pending_identity'
                );
                $GLOBALS['rank_math_test_pending_identity'] = $pendingIdentity->invoke(null);
            } else {
                $GLOBALS['rank_math_test_pending_identity'] = null;
            }
            if ($GLOBALS['rank_math_test_command_throw'] instanceof \Throwable) {
                throw $GLOBALS['rank_math_test_command_throw'];
            }
            if (is_callable($GLOBALS['rank_math_test_after_command'])) {
                ($GLOBALS['rank_math_test_after_command'])();
            }
            $result = $GLOBALS['rank_math_test_command_result'];
            $result = is_callable($result) ? $result() : $result;
            if (is_callable($GLOBALS['rank_math_test_after_result'] ?? null)) {
                ($GLOBALS['rank_math_test_after_result'])();
            }
            return $result;
        }
    }
}

namespace RankMath\Links {
    final class ContentProcessor {
        private static ?self $instance = null;

        public static function get(): self {
            self::$instance ??= new self();
            return self::$instance;
        }

        /** @return list<string> */
        public function extract(string $content): array {
            $matches = [];
            $matched = preg_match_all(
                '/<a\s[^>]*href=("??)([^" >]*?)\\1[^>]*>/iU',
                $content,
                $matches,
                PREG_SET_ORDER
            );
            if ($matched === false) {
                throw new \RuntimeException('fixture link extraction failed');
            }
            return array_map(
                static fn(array $match): string => trim((string) ($match[2] ?? ''), "'"),
                $matches
            );
        }

        public function normalize_link(string $link): string {
            return rtrim(str_replace(\home_url(), '', explode('#', $link)[0]), '/\\');
        }

        public function is_valid_link_type(string $link): string|false {
            if ($link === '' || $link[0] === '#') {
                return false;
            }
            $parts = parse_url($link);
            $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
            $host = is_array($parts) ? ($parts['host'] ?? null) : null;
            $type = ($scheme !== null
                && (!in_array($scheme, ['http', 'https'], true) || $host !== 'source.example.test'))
                ? 'external'
                : 'internal';
            if ($type === 'internal' && preg_match('/\.(jpg|jpeg|png|gif|bmp|pdf|mp3|zip)$/i', $link) === 1) {
                return false;
            }
            return $type;
        }

        public function maybe_product_id(string $link): int { return 0; }
    }

    final class Links {
        public static function is_post_processable(object $post): bool {
            return !in_array((string) ($post->post_status ?? ''), ['auto-draft', 'trash'], true);
        }

        public static function process_post_links(int $id, object $post): void {
            $GLOBALS['rank_math_test_process_calls']++;
            $GLOBALS['rank_math_test_in_native_process'] = true;
            try {
                \rank_math_test_rebuild();
            } finally {
                $GLOBALS['rank_math_test_in_native_process'] = false;
            }
        }
    }
}

namespace RankMath {
    final class Defaults {
        public function __construct() {
            $registry = $GLOBALS['wp_filter'] ?? null;
            if (!is_array($registry)
                || array_key_exists('rank_math/excluded_post_types', $registry)) {
                return;
            }
            $GLOBALS['wp_filter']['rank_math/excluded_post_types'] = new \WP_Hook([
                10 => [
                    'rank-math-native' => [
                        'accepted_args' => 1,
                        'function' => [$this, 'excluded_post_types'],
                    ],
                ],
            ]);
        }

        /** @param array<string,string> $types @return array<string,string> */
        public function excluded_post_types(array $types): array {
            unset($types['elementor_library']);
            return $types;
        }
    }

    final class Helper {
        /** @return array<string,string> */
        public static function get_accessible_post_types(): array {
            return $GLOBALS['rank_math_test_helper_types'];
        }

        public static function get_settings(string $name): bool { return false; }
    }

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
                $GLOBALS['wpdb']->setTableEngine($table, 'InnoDB');
                if (!$GLOBALS['wpdb']->hasTable($GLOBALS['wpdb']->prefix . $table)) {
                    $GLOBALS['wpdb']->seedTable($table, []);
                }
            }
            $mutate = $GLOBALS['rank_math_test_installer_mutate_table'] ?? null;
            if (is_string($mutate)) {
                $rows = $GLOBALS['wpdb']->rows($mutate);
                if (isset($rows[0]) && is_array($rows[0])) {
                    $first = array_key_first($rows[0]);
                    if (is_string($first)) {
                        $rows[0][$first] = (string) $rows[0][$first] . '-installer-mutation';
                    }
                }
                $GLOBALS['wpdb']->seedTable($mutate, $rows);
            }
        }
    }
}

namespace {
    require_once RANK_MATH_TEST_RUNTIME_ROOT . '/agent/src/Adapter/ProviderSdk.php';
    require_once RANK_MATH_TEST_RUNTIME_ROOT . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once RANK_MATH_TEST_RUNTIME_ROOT . '/agent/src/Adapter/ProviderOperationProcess.php';
    require_once RANK_MATH_TEST_RUNTIME_ROOT . '/agent/src/Adapter/Providers.php';
    require_once RANK_MATH_TEST_RUNTIME_ROOT . '/agent/src/Kernel/PrivateRefusalEvidence.php';
    require_once RANK_MATH_TEST_RUNTIME_ROOT . '/adapter-packages/rank-math/package/runtime/providers/rank-math-state.php';

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
            'posts' => [
                'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
                'post_excerpt', 'post_status', 'post_password', 'post_name', 'post_modified',
                'post_modified_gmt', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type',
            ],
            'options' => ['option_id', 'option_name', 'option_value', 'autoload'],
            'comments' => [
                'comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email',
                'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_date_gmt',
                'comment_content', 'comment_karma', 'comment_approved', 'comment_agent',
                'comment_type', 'comment_parent', 'user_id',
            ],
            'term_relationships' => ['object_id', 'term_taxonomy_id', 'term_order'],
            'term_taxonomy' => [
                'term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count',
            ],
            'terms' => ['term_id', 'name', 'slug', 'term_group'],
            'users' => [
                'ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url',
                'user_registered', 'user_activation_key', 'user_status', 'display_name',
            ],
            'usermeta' => ['umeta_id', 'user_id', 'meta_key', 'meta_value'],
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
            $GLOBALS['wpdb']->setTableEngine($table, 'InnoDB');
        }
    }

    /** @return list<array<string,mixed>> */
    function rank_math_test_desired_links(): array {
        return [
            ['id' => 101, 'url' => '/target-20', 'post_id' => 10, 'target_post_id' => 20, 'type' => 'internal'],
            ['id' => 102, 'url' => 'https://external.example.test/مرحباً', 'post_id' => 10, 'target_post_id' => 0, 'type' => 'external'],
            ['id' => 104, 'url' => '/archive/', 'post_id' => 10, 'target_post_id' => 0, 'type' => 'internal'],
            ['id' => 103, 'url' => '/ten', 'post_id' => 20, 'target_post_id' => 10, 'type' => 'internal'],
        ];
    }

    /** @return list<array<string,mixed>> */
    function rank_math_test_desired_meta(): array {
        return rank_math_test_meta_for_links(rank_math_test_desired_links());
    }

    /** @param list<array<string,mixed>> $links @return list<array<string,int>> */
    function rank_math_test_meta_for_links(array $links): array {
        $identities = [10 => true, 20 => true];
        $outgoing = [];
        $incoming = [];
        foreach ($links as $link) {
            $source = (int) ($link['post_id'] ?? 0);
            $target = (int) ($link['target_post_id'] ?? 0);
            $type = (string) ($link['type'] ?? '');
            $identities[$source] = true;
            $outgoing[$source][$type] = ($outgoing[$source][$type] ?? 0) + 1;
            if ($target > 0) {
                $identities[$target] = true;
                $incoming[$target] = ($incoming[$target] ?? 0) + 1;
            }
        }
        ksort($identities, SORT_NUMERIC);
        $rows = [];
        foreach (array_keys($identities) as $id) {
            $rows[] = [
                'object_id' => $id,
                'internal_link_count' => (int) ($outgoing[$id]['internal'] ?? 0),
                'external_link_count' => (int) ($outgoing[$id]['external'] ?? 0),
                'incoming_link_count' => (int) ($incoming[$id] ?? 0),
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    function rank_math_test_native_links(): array {
        $override = $GLOBALS['rank_math_test_rebuild_links_override'] ?? null;
        if (is_array($override)) {
            return $override;
        }
        $links = rank_math_test_desired_links();
        foreach ($links as &$link) {
            if (($link['type'] ?? null) === 'internal') {
                $link['target_post_id'] = url_to_postid((string) $link['url']);
            }
        }
        unset($link);
        return $links;
    }

    /** @return list<array<string,mixed>> */
    function rank_math_test_posts(): array {
        $base = [
            'post_author' => 1,
            'post_date' => '2026-08-27 00:00:00',
            'post_date_gmt' => '2026-08-26 18:00:00',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_password' => '',
            'post_modified' => '2026-08-27 00:00:00',
            'post_modified_gmt' => '2026-08-26 18:00:00',
            'post_parent' => 0,
            'menu_order' => 0,
            'post_mime_type' => '',
        ];
        return [
            [
                'ID' => 10,
                'post_content' => '<a href="/target-20">Twenty</a>'
                    . '<a href="https://external.example.test/مرحباً">External</a>'
                    . '<a href="/archive/">Archive without a post identity</a>',
                'post_title' => 'Ten',
                'post_name' => 'ten', 'guid' => 'https://source.example.test/?p=10', 'post_type' => 'post'] + $base,
            ['ID' => 20, 'post_content' => '<a href="/ten">Ten</a>', 'post_title' => 'Twenty',
                'post_name' => 'twenty', 'guid' => 'https://source.example.test/?p=20', 'post_type' => 'page'] + $base,
        ];
    }

    function rank_math_test_rebuild(): void {
        /** @var FakeWpdb $db */
        $db = $GLOBALS['wpdb'];
        $markers = array_values(array_filter(
            $db->rows('postmeta'),
            static fn(array $row): bool => (string) $row['meta_key'] !== 'rank_math_internal_links_processed'
        ));
        if (in_array('link-counter', $GLOBALS['rank_math_test_modules'], true)) {
            foreach ([10, 20] as $index => $id) {
                $markers[] = [
                    'meta_id' => 900 + $index,
                    'post_id' => $id,
                    'meta_key' => 'rank_math_internal_links_processed',
                    'meta_value' => '1',
                ];
            }
            $links = rank_math_test_native_links();
            $db->seedTable('rank_math_internal_links', $links);
            $db->seedTable('rank_math_internal_meta', rank_math_test_meta_for_links($links));
        } else {
            $db->seedTable('rank_math_internal_links', []);
            $db->seedTable('rank_math_internal_meta', []);
        }
        $db->seedTable('postmeta', $markers);
    }

    /** Run a fixture-only projection through the runtime's exact contract slot. */
    function rank_math_test_with_contract(callable $operation): mixed {
        $provider = $GLOBALS['rank_math_test_provider'] ?? null;
        if (!$provider instanceof RankMathState) {
            throw new RuntimeException('fixture provider contract is unavailable');
        }
        $contract = $provider->capabilities()['rebuild_all_link_state'] ?? null;
        if (!is_array($contract)) {
            throw new RuntimeException('fixture provider capability contract is unavailable');
        }
        $begin = new ReflectionMethod(\WPrism\ManifestProviderRuntime::class, 'beginContractInvocation');
        $end = new ReflectionMethod(\WPrism\ManifestProviderRuntime::class, 'endContractInvocation');
        $begin->invoke($provider, 'rebuild_all_link_state', $contract);
        try {
            return $operation($provider);
        } finally {
            $end->invoke($provider);
        }
    }

    /** @return array<string,mixed> */
    function rank_math_test_projection(): array {
        $projection = rank_math_test_with_contract(static function (RankMathState $provider): mixed {
            $method = new ReflectionMethod(RankMathState::class, 'link_projection');
            return $method->invoke($provider);
        });
        if (!is_array($projection)) {
            throw new RuntimeException('fixture provider projection failed');
        }
        return $projection;
    }

    /** @param ?array<string,mixed> $projection */
    function rank_math_test_result(?array $projection = null): object {
        $pendingIdentity = new ReflectionMethod(\WPrism\ProviderOperationProcess::class, 'pending_identity');
        $pending = $pendingIdentity->invoke(null);
        $provider = $GLOBALS['rank_math_test_provider'] ?? null;
        if (!is_array($pending) || !$provider instanceof RankMathState) {
            throw new RuntimeException('fixture provider-operation identity is unavailable');
        }
        $operation = $pending['operation'] ?? null;
        if ($projection === null && $operation === 'invoke') {
            $method = new ReflectionMethod(\WPrism\ManifestProviderRuntime::class, 'invokeDirect');
            $receipt = $method->invoke($provider, 'rebuild_all_link_state', []);
            if (!is_array($receipt)) {
                throw new RuntimeException('fixture provider operation returned no receipt');
            }
            $project = new ReflectionMethod(RankMathState::class, 'project_fresh_postimage_rebuild_all_link_state');
            $postimage = $project->invoke($provider, $receipt['after']);
        } elseif ($projection !== null) {
            $receipt = ['before' => $projection, 'after' => $projection, 'verified' => true];
            $postimage = $projection;
        } else {
            $postimage = rank_math_test_with_contract(
                static fn(RankMathState $provider): mixed => \WPrism\ProviderSdk::database_read_contract_snapshot(
                    'Rank Math fixture fresh observation',
                    static function () use ($provider): mixed {
                        $observe = new ReflectionMethod(
                            RankMathState::class,
                            'observe_fresh_postimage_rebuild_all_link_state'
                        );
                        $project = new ReflectionMethod(
                            RankMathState::class,
                            'project_fresh_postimage_rebuild_all_link_state'
                        );
                        $observed = $observe->invoke($provider, []);
                        return $project->invoke($provider, $observed);
                    }
                )
            );
            $receipt = null;
        }
        if ($operation === 'invoke') {
            $GLOBALS['rank_math_test_last_child_receipt'] = $receipt;
        }
        $result = $operation === 'invoke'
            ? ['postimage' => $postimage, 'receipt' => $receipt]
            : ['postimage' => $postimage];
        return (object) [
            'return_code' => 0,
            'stdout' => \WPrism\Canon::encode([
                'adapter' => $pending['adapter'],
                'adapter_digest' => $pending['adapter_digest'],
                'capability' => $pending['capability'],
                'format' => \WPrism\ProviderOperationProcess::RECEIPT_FORMAT,
                'operation' => $operation,
                'provider' => $pending['provider'],
                'request_sha256' => $pending['request_sha256'],
                'result' => $result,
            ]),
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
        $GLOBALS['rank_math_test_installer_mutate_table'] = null;
        $GLOBALS['rank_math_test_hooks'] = [];
        $GLOBALS['rank_math_test_types'] = ['page' => 'page', 'post' => 'post'];
        $GLOBALS['rank_math_test_helper_types'] = ['page' => 'page', 'post' => 'post'];
        $GLOBALS['rank_math_test_options'] = [
            'close_comments_days_old' => 14,
            'close_comments_for_old_posts' => 1,
            'comments_per_page' => 50,
            'default_category' => 1,
            'page_for_posts' => 0,
            'page_on_front' => 0,
            'permalink_structure' => '',
            'posts_per_page' => 10,
            'posts_per_rss' => 10,
            'rewrite_rules' => [],
            'sticky_posts' => [],
            'wp_page_for_privacy_policy' => 0,
        ];
        $GLOBALS['rank_math_test_clean_post_cache_calls'] = 0;
        $GLOBALS['rank_math_test_cache_flush_calls'] = 0;
        $GLOBALS['rank_math_test_permalink_calls'] = 0;
        $GLOBALS['rank_math_test_url_to_postid_calls'] = 0;
        $GLOBALS['rank_math_test_url_to_postid_override'] = null;
        $GLOBALS['rank_math_test_in_native_process'] = false;
        $GLOBALS['rank_math_test_rebuild_links_override'] = null;
        $GLOBALS['rank_math_test_process_calls'] = 0;
        $GLOBALS['rank_math_test_pending_identity'] = null;
        $GLOBALS['rank_math_test_after_result'] = null;
        $GLOBALS['rank_math_test_last_child_receipt'] = null;
        $GLOBALS['wprism_wp_cli_child_fake_stderr_first'] = false;
        $GLOBALS['wp_filter'] = [
            'rank_math/excluded_post_types' => new WP_Hook([
                10 => [
                    'rank-math-native' => [
                        'accepted_args' => 1,
                        'function' => [new \RankMath\Defaults(), 'excluded_post_types'],
                    ],
                ],
            ]),
        ];
        $GLOBALS['wp_rewrite'] = new WP_Rewrite([]);
        $GLOBALS['wp'] = (object) ['public_query_vars' => ['p', 'page_id', 'name', 'post_type']];
        $GLOBALS['wp_post_types'] = [
            'post' => get_post_type_object('post'),
            'page' => get_post_type_object('page'),
        ];

        $db = FakeWpdb::install();
        foreach ([
            'postmeta', 'posts', 'options', 'comments', 'term_relationships', 'term_taxonomy', 'terms',
            'users', 'usermeta',
        ] as $table) {
            $db->setColumns($table, rank_math_test_columns()[$table]);
            $db->setTableEngine($table, 'InnoDB');
        }
        $db->seedTable('postmeta', [
            ['meta_id' => 1, 'post_id' => 10, 'meta_key' => 'rank_math_internal_links_processed', 'meta_value' => 'stale'],
            ['meta_id' => 2, 'post_id' => 77, 'meta_key' => 'target_only_runtime', 'meta_value' => 'preserve'],
        ]);
        $db->seedTable('posts', rank_math_test_posts());
        $db->seedTable('options', [
            [
                'option_id' => 1,
                'option_name' => 'default_category',
                'option_value' => '1',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 2,
                'option_name' => 'permalink_structure',
                'option_value' => '',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 3,
                'option_name' => 'rank_math_modules',
                'option_value' => serialize(['link-counter', 'redirections']),
                'autoload' => 'yes',
            ],
            [
                'option_id' => 4,
                'option_name' => 'close_comments_days_old',
                'option_value' => '14',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 5,
                'option_name' => 'close_comments_for_old_posts',
                'option_value' => '1',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 6,
                'option_name' => 'comments_per_page',
                'option_value' => '50',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 7,
                'option_name' => 'page_for_posts',
                'option_value' => '0',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 8,
                'option_name' => 'page_on_front',
                'option_value' => '0',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 9,
                'option_name' => 'posts_per_page',
                'option_value' => '10',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 10,
                'option_name' => 'posts_per_rss',
                'option_value' => '10',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 11,
                'option_name' => 'rewrite_rules',
                'option_value' => 'a:0:{}',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 12,
                'option_name' => 'sticky_posts',
                'option_value' => 'a:0:{}',
                'autoload' => 'yes',
            ],
            [
                'option_id' => 13,
                'option_name' => 'wp_page_for_privacy_policy',
                'option_value' => '0',
                'autoload' => 'yes',
            ],
        ]);
        $db->seedTable('comments', [[
            'comment_ID' => 1,
            'comment_post_ID' => 20,
            'comment_author' => 'Fixture Reader',
            'comment_author_email' => 'reader@example.test',
            'comment_author_url' => '',
            'comment_author_IP' => '192.0.2.10',
            'comment_date' => '2026-08-27 01:00:00',
            'comment_date_gmt' => '2026-08-26 19:00:00',
            'comment_content' => 'fixture comment',
            'comment_karma' => 0,
            'comment_approved' => '1',
            'comment_agent' => 'wprism-test',
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
        ]]);
        $db->seedTable('term_relationships', []);
        $db->seedTable('term_taxonomy', []);
        $db->seedTable('terms', []);
        $db->seedTable('users', [[
            'ID' => 1,
            'user_login' => 'admin',
            'user_pass' => 'hash',
            'user_nicename' => 'admin',
            'user_email' => 'admin@example.test',
            'user_url' => '',
            'user_registered' => '2026-01-01 00:00:00',
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Admin',
        ]]);
        $db->seedTable('usermeta', [[
            'umeta_id' => 1,
            'user_id' => 1,
            'meta_key' => 'wp_capabilities',
            'meta_value' => 'a:1:{s:13:"administrator";b:1;}',
        ]]);
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
        $GLOBALS['rank_math_test_after_command'] = null;
        $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result();

        $manifest = json_decode(
            (string) file_get_contents(RANK_MATH_TEST_RUNTIME_ROOT . '/adapter-packages/rank-math/package/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $providerFile = realpath(
            RANK_MATH_TEST_RUNTIME_ROOT . '/adapter-packages/rank-math/package/runtime/providers/rank-math-state.php'
        );
        if ($providerFile === false) {
            throw new RuntimeException('fixture provider source is unavailable');
        }
        $declaration = $manifest['providers'][0];
        $declaration['manifest'] = 'rank-math';
        $declaration['_wprism_adapter_digest'] = str_repeat('a', 64);
        $declaration['_wprism_adapter_library_root'] = RANK_MATH_TEST_RUNTIME_ROOT;
        $declaration['_wprism_execution_bound'] = true;
        $declaration['_wprism_execution_identity'] = [
            'artifact_hash' => str_repeat('b', 64),
            'manifest_hash' => str_repeat('c', 64),
            'resolved_adapters_sha256' => str_repeat('d', 64),
            'site_hash' => str_repeat('e', 64),
        ];
        $declaration['_wprism_plugin_runtime'] = [
            'active' => true,
            'installed' => true,
            'version' => '1.0.238',
        ];
        $declaration['_wprism_policy_snapshot'] = ['fixture' => 'rank-math-provider'];
        $declaration['_wprism_provider_file'] = $providerFile;
        $declaration['_wprism_provider_sha256'] = hash_file('sha256', $providerFile);
        $GLOBALS['rank_math_test_provider_declaration'] = $declaration;
        $provider = new RankMathState($declaration);
        // Production binds only the exact object returned by the private
        // digest/provenance loader. This capsule-level provider fixture crosses
        // that private join so the semantic tests retain the same SDK authority
        // without adding a public test arm to shipped engine code.
        $bind = new ReflectionMethod(\WPrism\Providers::class, 'bind_manifest_runtime_contracts');
        $bind->invoke(null, $provider, $provider->capabilities());
        $GLOBALS['rank_math_test_provider'] = $provider;
        return $provider;
    }

    function rank_math_test_set_option(string $name, mixed $value): void {
        $GLOBALS['rank_math_test_options'][$name] = $value;
        $rows = $GLOBALS['wpdb']->rows('options');
        $found = false;
        foreach ($rows as &$row) {
            if (($row['option_name'] ?? null) !== $name) {
                continue;
            }
            $row['option_value'] = is_array($value) ? serialize($value) : (string) $value;
            $found = true;
        }
        unset($row);
        if (!$found) {
            $rows[] = [
                'option_id' => count($rows) + 1,
                'option_name' => $name,
                'option_value' => is_array($value) ? serialize($value) : (string) $value,
                'autoload' => 'yes',
            ];
        }
        $GLOBALS['wpdb']->seedTable('options', $rows);
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

    /** @return array<string,mixed> */
    function rank_math_test_execute_child(): array {
        $provider = $GLOBALS['rank_math_test_provider'] ?? null;
        if (!$provider instanceof RankMathState) {
            throw new RuntimeException('fixture provider-operation provider is unavailable');
        }
        $invoke = new ReflectionMethod(\WPrism\ManifestProviderRuntime::class, 'invokeDirect');
        $receipt = $invoke->invoke($provider, 'rebuild_all_link_state', []);
        if (!is_array($receipt)) {
            throw new RuntimeException('fixture provider operation returned no receipt');
        }
        return $receipt;
    }

    $provider = rank_math_test_reset('none');
    wprism_check_same(
        ['id' => 'rank-math-state', 'plugin' => 'seo-by-rank-math/rank-math.php', 'version' => '1.0.1'],
        $provider->identity(),
        'provider identity makes schema and link-repair behavior digest-visible'
    );
    $readiness = $provider->invoke('inspect_schema', []);
    wprism_check_same($readiness['before'], $readiness['after'],
        'read-only readiness returns one stable exact schema projection');
    wprism_check_same([false, false, false, false], array_column($readiness['after'], 'present'),
        'read-only readiness distinguishes a virgin target without creating tables');
    wprism_check_same([], $GLOBALS['rank_math_test_installer_calls'],
        'read-only readiness never enters the native installer');
    $schemaReceipt = $provider->invoke('prepare_schema', []);
    wprism_check_same(
        [['link-counter', 'redirections']],
        $GLOBALS['rank_math_test_installer_calls'],
        'schema settlement asks the native installer for both reviewed module schemas'
    );
    foreach (array_keys(rank_math_test_schema()) as $table) {
        wprism_check_same(false, $schemaReceipt['before'][$table]['present'] ?? null, "$table starts absent on a clean target");
        wprism_check_same(true, $schemaReceipt['after'][$table]['present'] ?? null, "$table is present after native schema settlement");
    }
    $schemaRetry = $provider->invoke('prepare_schema', []);
    wprism_check_same(
        $schemaRetry['before'],
        $schemaRetry['after'],
        'repeated schema settlement is an exact no-op at the checked schema projection'
    );
    $ready = $provider->invoke('inspect_schema', []);
    wprism_check_same([true, true, true, true], array_column($ready['after'], 'present'),
        'readiness certifies every audited schema after native preparation');

    $provider = rank_math_test_reset('link');
    $existingLinkRows = $GLOBALS['wpdb']->rows('rank_math_internal_links');
    $mixedSchemaReceipt = $provider->invoke('prepare_schema', []);
    wprism_check_same(
        $mixedSchemaReceipt['before']['rank_math_internal_links'],
        $mixedSchemaReceipt['after']['rank_math_internal_links'],
        'mixed schema preparation carries a complete unchanged witness for an existing table'
    );
    wprism_check_same(
        $existingLinkRows,
        $GLOBALS['wpdb']->rows('rank_math_internal_links'),
        'native schema preparation preserves every existing link row byte'
    );
    wprism_check_same(
        0,
        $mixedSchemaReceipt['after']['rank_math_redirections']['row_count'] ?? null,
        'a newly created schema table is proved virgin rather than silently seeded'
    );

    $provider = rank_math_test_reset('link');
    $chunkedRows = [];
    for ($id = 1; $id <= 1100; $id++) {
        $chunkedRows[] = [
            'id' => $id,
            'url' => '/chunk-' . $id,
            'post_id' => $id,
            'target_post_id' => $id + 1,
            'type' => 'internal',
        ];
    }
    $GLOBALS['wpdb']->seedTable('rank_math_internal_links', $chunkedRows);
    $GLOBALS['wpdb']->resetLog();
    $chunkedReceipt = $provider->invoke('prepare_schema', []);
    wprism_check_same(
        $chunkedReceipt['before']['rank_math_internal_links'],
        $chunkedReceipt['after']['rank_math_internal_links'],
        'a multi-chunk existing table has one invariant incremental witness'
    );
    $projectionQueries = $GLOBALS['wpdb']->queries();
    $chunkQueries = array_values(array_filter(
        $projectionQueries,
        static fn(string $sql): bool => str_contains($sql, 'FROM `wp_rank_math_internal_links`')
            && str_contains($sql, 'ORDER BY `id` LIMIT 1024')
    ));
    wprism_check(
        count($chunkQueries) >= 2,
        'the row witness transfers a bounded 1,024-row page and primary-key continuation page'
    );
    $snapshotStarts = array_keys(array_filter(
        $projectionQueries,
        static fn(string $sql): bool => $sql === 'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT'
    ));
    $snapshotCloses = array_keys(array_filter(
        $projectionQueries,
        static fn(string $sql): bool => $sql === 'ROLLBACK AND NO CHAIN NO RELEASE'
    ));
    $rowBounds = [];
    foreach ($projectionQueries as $queryIndex => $sql) {
        if (str_contains($sql, 'COUNT(*) AS row_count')
            && str_contains($sql, 'FROM `wp_rank_math_internal_links`')) {
            $rowBounds[] = $queryIndex;
        }
    }
    wprism_check(
        count($snapshotStarts) === 4
            && count($snapshotCloses) === 4
            && count($rowBounds) === 2
            && $snapshotStarts[1] < $rowBounds[0]
            && $rowBounds[0] < $snapshotCloses[1]
            && $snapshotStarts[3] < $rowBounds[1]
            && $rowBounds[1] < $snapshotCloses[3],
        'each before/after row witness follows isolated presence discovery in one profiled read-only snapshot'
    );
    $firstChunkHash = $chunkedReceipt['after']['rank_math_internal_links']['rows_sha256'] ?? null;
    $chunkedRows[1080]['url'] = '/chunk-1081-mutated';
    $GLOBALS['wpdb']->seedTable('rank_math_internal_links', $chunkedRows);
    $changedChunkReceipt = $provider->invoke('prepare_schema', []);
    wprism_check(
        is_string($firstChunkHash)
            && $firstChunkHash !== ($changedChunkReceipt['after']['rank_math_internal_links']['rows_sha256'] ?? null),
        'a value change beyond the first chunk changes the complete table witness'
    );
    wprism_check_same(
        $changedChunkReceipt['before']['rank_math_internal_links'],
        $changedChunkReceipt['after']['rank_math_internal_links'],
        'the changed multi-chunk witness remains invariant when the installer preserves it'
    );

    $provider = rank_math_test_reset('full');
    $GLOBALS['wpdb']->seedTable('rank_math_redirections', [[
        'id' => 1,
        'sources' => str_repeat('x', 1048577),
        'url_to' => '/bounded',
        'header_code' => 302,
        'hits' => 0,
        'status' => 'active',
        'created' => '2026-09-02 00:00:00',
        'updated' => '2026-09-02 00:00:00',
        'last_accessed' => '2026-09-02 00:00:00',
    ]]);
    $GLOBALS['wpdb']->resetLog();
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', []),
        RuntimeException::class,
        'one oversized LONGTEXT row refuses before PHP row materialization',
        'exceeds the bounded byte projection'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_installer_calls'],
        'oversized content refuses before the native installer can mutate schema');
    wprism_check_same(
        [],
        array_values(array_filter(
            $GLOBALS['wpdb']->queries(),
            static fn(string $sql): bool => str_starts_with($sql, 'SELECT `id`, `sources`')
        )),
        'the database-side byte bound refuses the oversized row before a value-bearing SELECT'
    );

    $provider = rank_math_test_reset('link');
    $raceInjected = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$raceInjected): null {
        if (!$raceInjected
            && $method === 'get_results'
            && str_starts_with($sql, 'SELECT `id`, `url`')
            && str_contains($sql, 'ORDER BY `id` LIMIT 1024')) {
            $rows = $db->rows('rank_math_internal_links');
            $rows[0]['url'] = str_repeat('x', 1048577);
            $db->seedTable('rank_math_internal_links', $rows);
            $raceInjected = true;
        }
        return null;
    });
    $GLOBALS['wpdb']->resetLog();
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', []),
        RuntimeException::class,
        'a row that grows after the aggregate census refuses without entering the provider heap',
        'changed during the bounded witness'
    );
    wprism_check_same(true, $raceInjected,
        'the concurrency regression mutates the LONGTEXT row between bounds and value read');
    $racedValueQueries = array_values(array_filter(
        $GLOBALS['wpdb']->queries(),
        static fn(string $sql): bool => str_starts_with($sql, 'SELECT `id`, `url`')
    ));
    $allRacedValueQueriesBounded = $racedValueQueries !== [];
    foreach ($racedValueQueries as $sql) {
        if (!str_contains($sql, 'COALESCE(OCTET_LENGTH(`url`), 0)')
            || !str_contains($sql, '<= 1048576')) {
            $allRacedValueQueriesBounded = false;
            break;
        }
    }
    wprism_check(
        $allRacedValueQueriesBounded,
        'every value-bearing keyset page enforces the raw-row cap in its SQL predicate'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_installer_calls'],
        'the raced oversized row refuses before the native installer can mutate schema');

    $provider = rank_math_test_reset('link');
    $appendInjected = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$appendInjected): null {
        if (!$appendInjected
            && $method === 'get_results'
            && str_starts_with($sql, 'SELECT `id`, `url`')
            && str_contains($sql, 'ORDER BY `id` LIMIT 1024')) {
            $rows = $db->rows('rank_math_internal_links');
            $rows[] = [
                'id' => 3,
                'url' => '/concurrent-append',
                'post_id' => 3,
                'target_post_id' => 4,
                'type' => 'internal',
            ];
            $db->seedTable('rank_math_internal_links', $rows);
            $appendInjected = true;
        }
        return null;
    });
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', []),
        RuntimeException::class,
        'a row appended after the aggregate census cannot be silently omitted from a success-shaped witness',
        'changed during the bounded witness'
    );
    wprism_check_same(true, $appendInjected,
        'the append regression enters exactly between the bounded census and keyset transfer');

    $provider = rank_math_test_reset('link');
    $GLOBALS['wpdb']->setTableEngine('rank_math_internal_links', 'MyISAM');
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', []),
        RuntimeException::class,
        'a non-MVCC table cannot claim a coherent complete row witness',
        'unsupported engine (InnoDB required): wp_rank_math_internal_links'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_installer_calls'],
        'storage-engine refusal precedes the native schema installer');

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_installer_mutate_table'] = 'rank_math_internal_links';
    wprism_check_throws(
        static fn(): array => $provider->invoke('prepare_schema', []),
        RuntimeException::class,
        'schema preparation cannot hide row mutation behind an unchanged column/index hash',
        'changed rows or structure in existing table'
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
    $provider = rank_math_test_reset('full');
    $malformed = rank_math_test_schema()['rank_math_internal_links']['columns'];
    $malformed[] = [
        'Field' => 'legacy_column',
        'Type' => 'varchar(32)',
        'Collation' => 'utf8mb4_unicode_ci',
        'Null' => 'NO',
        'Key' => '',
        'Default' => null,
        'Extra' => '',
    ];
    $GLOBALS['wpdb']->setColumnDefinitions('rank_math_internal_links', $malformed);
    wprism_check_throws(
        static fn(): array => $provider->invoke('inspect_schema', []),
        RuntimeException::class,
        'presence cannot certify a malformed pre-existing schema',
        'disagrees with the audited column/index contract'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_installer_calls'],
        'malformed readiness refuses without attempting dbDelta repair');
    // The combined live schema-settle control enters through Providers, not
    // this suite's convenient direct runtime calls. Its exact private graph
    // must remain compatible with the target-identity evidence verifier.
    foreach ([false, true] as $extraColumn) {
        $provider = rank_math_test_reset('full');
        if ($extraColumn) {
            $columns = rank_math_test_schema()['rank_math_internal_links']['columns'];
            $columns[] = [
                'Field' => 'wprism_hostile_schema', 'Type' => 'varchar(12)',
                'Collation' => 'utf8mb4_unicode_ci', 'Null' => 'YES',
                'Key' => '', 'Default' => null, 'Extra' => '',
            ];
            $GLOBALS['wpdb']->setColumnDefinitions('rank_math_internal_links', $columns);
        }
        $beforeRows = [];
        foreach (array_keys(rank_math_test_schema()) as $table) {
            $beforeRows[$table] = $GLOBALS['wpdb']->rows($table);
        }
        $GLOBALS['wpdb']->resetLog();
        $readiness = null;
        $wrapped = null;
        try {
            $readiness = \WPrism\Providers::invoke(
                $provider,
                ['provider' => 'rank-math-state', 'capability' => 'inspect_schema', 'args' => []],
                $provider->capabilities()['inspect_schema'],
                []
            );
        } catch (Throwable $caught) {
            $wrapped = $caught;
        }
        if ($extraColumn) {
            $graph = $wrapped === null ? [] : \WPrism\PrivateRefusalEvidence::graph($wrapped);
            wprism_check(
                $wrapped instanceof \WPrism\PrivateEvidenceException && $wrapped->getPrevious() === null
                    && $wrapped->getMessage() === "wprism: provider 'rank-math-state' capability 'inspect_schema' failed"
                    && count($graph['throwable'] ?? []) === 2
                    && ($graph['throwable'][1]['parent_index'] ?? null) === 0
                    && ($graph['throwable'][1]['relation'] ?? null) === 'private_evidence'
                    && ($graph['throwable'][1]['class'] ?? null) === RuntimeException::class
                    && ($graph['throwable'][1]['message'] ?? null)
                        === 'wprism: Rank Math schema disagrees with the audited column/index contract',
                'the exact scenario extra column refuses only behind the real public provider/private cause boundary'
            );
        } else {
            wprism_check($wrapped === null && ($readiness['verified'] ?? null) === true,
                'the same public provider path accepts an unchanged audited schema');
        }
        $afterRows = [];
        foreach (array_keys(rank_math_test_schema()) as $table) {
            $afterRows[$table] = $GLOBALS['wpdb']->rows($table);
        }
        wprism_check_same($beforeRows, $afterRows,
            'public schema inspection preserves every native Rank Math row for extra-column=' . (int) $extraColumn);
        wprism_check_same([], array_values(array_filter($GLOBALS['wpdb']->queries(),
            static fn(string $query): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\\b/i', $query) === 1)),
            'public schema inspection performs no DDL/DML for extra-column=' . (int) $extraColumn);
        wprism_check_same([], $GLOBALS['rank_math_test_installer_calls'],
            'public schema inspection never invokes the installer for extra-column=' . (int) $extraColumn);
        wprism_check_same(false, \WPrism\Db::connection_transaction_active('Rank Math schema receipt regression'),
            'public schema inspection settles its read-only snapshot for extra-column=' . (int) $extraColumn);
    }
    foreach ([
        'dbdelta_create_queries',
        'dbdelta_insert_queries',
        'dbdelta_queries',
        'rank_math/admin/after_create_tables',
        'rank_math/admin/create_tables',
    ] as $hook) {
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
    $GLOBALS['wpdb']->failNextQuery('schema secret sk_rank_math_schema', 'SELECT 1 FROM');
    $schemaFailure = rank_math_test_throw_message(static fn(): array => $provider->invoke('prepare_schema', []));
    wprism_check(
        str_contains($schemaFailure, 'provider database read failed')
            && !str_contains($schemaFailure, 'sk_rank_math_schema'),
        'schema discovery failure is loud and value-redacted'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_cold_postmeta_cache'] = true;
    $GLOBALS['rank_math_test_postmeta_cache_reads'] = 0;
    $receipt = $provider->invoke('rebuild_all_link_state', []);
    $GLOBALS['rank_math_test_cold_postmeta_cache'] = false;
    wprism_check($GLOBALS['rank_math_test_postmeta_cache_reads'] > 0,
        'fresh native URL resolution admits WordPress post-meta cache reads inside the dependency snapshot');
    wprism_check_same(true, $receipt['verified'] ?? null, 'site link repair returns a verified value-level receipt');
    wprism_check_same(2, $receipt['after']['post_count'] ?? null,
        'site repair binds every accessible source post in the complete projection');
    wprism_check(
        preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['post_hash'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['dependency_hash'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['dependency_state_hash'] ?? '')) === 1,
        'site repair receipt binds source content plus full and stable route dependencies without exposing values'
    );
    wprism_check_same(4, $receipt['after']['link_count'] ?? null,
        'site repair replaces internal, external and unresolved-internal edge projections');
    wprism_check_same(2, $receipt['after']['meta_count'] ?? null,
        'site repair removes counts owned by deleted or inaccessible posts');
    wprism_check_same(2, $receipt['after']['marker_count'] ?? null,
        'site repair proves the exact processed-marker identity set');
    foreach ([77, 88, 999] as $staleId) {
        wprism_check_same(
            [],
            array_values(array_filter(
                $GLOBALS['wpdb']->rows('rank_math_internal_meta'),
                static fn(array $row): bool => (int) $row['object_id'] === $staleId
            )),
            "site repair removes stale Rank Math count identity $staleId"
        );
    }
    wprism_check_same(
        [10, 20],
        array_values(array_map(
            static fn(array $row): int => (int) $row['post_id'],
            array_filter(
                $GLOBALS['wpdb']->rows('postmeta'),
                static fn(array $row): bool => $row['meta_key'] === 'rank_math_internal_links_processed'
            )
        )),
        'site repair removes deleted-source markers and leaves one marker per accessible post'
    );
    wprism_check_same('preserve', $GLOBALS['wpdb']->rows('postmeta')[0]['meta_value'] ?? null,
        'site repair preserves unrelated post metadata');
    wprism_check_same(2, count($GLOBALS['rank_math_test_command_calls']),
        'site repair launches one mutation child and one independent readback child');
    [$command, $options] = $GLOBALS['rank_math_test_command_calls'][0];
    $providerSource = file_get_contents(
        RANK_MATH_TEST_RUNTIME_ROOT . '/adapter-packages/rank-math/package/runtime/providers/rank-math-state.php'
    );
    wprism_check(
        str_contains($command, 'ProviderOperationProcess::child_main')
            && !str_contains($command, 'rank_math_internal_links_processed')
            && !str_contains($command, 'process_post_links'),
        'fresh repair uses the engine fixed command without embedding adapter executable bytes'
    );
    wprism_check(
        is_string($providerSource)
            && str_contains($providerSource, 'clear_derived_link_state')
            && str_contains($providerSource, 'process_post_links')
            && substr_count($providerSource, '$this->repair_link_state_pass();') === 2,
        'provider methods retain the complete native clear/process/idempotence semantics'
    );
    wprism_check(
        is_string($providerSource)
            && !str_contains($providerSource, 'WpCliChildProcess')
            && !str_contains($providerSource, 'child_payload')
            && preg_match('/\\b(?:START\\s+TRANSACTION|COMMIT|ROLLBACK|SET\\s+TRANSACTION)\\b/i', $providerSource) !== 1,
        'adapter runtime contains no child-process or transaction-control machinery'
    );
    $pendingIdentity = $GLOBALS['rank_math_test_pending_identity'] ?? null;
    wprism_check(
        is_array($pendingIdentity)
            && array_keys($pendingIdentity) === [
                'request_sha256', 'adapter', 'adapter_digest', 'provider', 'capability', 'operation',
            ]
            && preg_match('/^[a-f0-9]{64}$/D', (string) $pendingIdentity['request_sha256']) === 1
            && $pendingIdentity['adapter'] === 'rank-math'
            && $pendingIdentity['provider'] === 'rank-math-state'
            && $pendingIdentity['capability'] === 'rebuild_all_link_state'
            && $pendingIdentity['operation'] === 'observe',
        'engine transport exposes only the digest-bound request identity to its process fake'
    );
    foreach ([
        'rank_math/links/content', 'rank_math/links/extract', 'rank_math/links/is_external',
        'rank_math/links/link_type', 'rank_math/links/process_post', 'rank_math/links/save_links',
    ] as $filter) {
        $filteredProvider = rank_math_test_reset('link');
        $GLOBALS['rank_math_test_hooks'][$filter] = true;
        wprism_check_throws(
            static fn(): array => rank_math_test_execute_child(),
            RuntimeException::class,
            "a callback on $filter refuses before native link mutation",
            'refuses an unreviewed link-processing callback'
        );
        wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
            "$filter refusal launches no child process");
    }

    // Native query-variable values are opaque route dependencies. The exact
    // Woo 11.0.1 endpoint below is registered by TransientFilesEngine.php:434
    // through WP_Rewrite::add_endpoint(), not a fixture-only permissive path.
    foreach ([
        'native commerce endpoint' => ['p', 'wc/file/transient', 'lang'],
        'non-ASCII endpoint' => ['p', 'مرحباً/ملف', 'lang'],
        'punctuation and whitespace' => ['a.b', 'a b', 'a[b]', 'a%2Fb'],
        'empty and numeric strings' => ['', '0', '123'],
        'binary strings' => ["nul\0name", "non-utf8-\xff", "line\nbreak"],
        'old identifier length boundary' => [str_repeat('x', 64), str_repeat('x', 65)],
        'reviewed byte boundary' => [str_repeat('x', 4096)],
        'sparse ordered duplicate names' => [2 => 'lang', 5 => 'wc/file/transient', 9 => 'lang'],
        'reviewed count boundary' => array_fill(0, 2048, 'wc/file/transient'),
    ] as $label => $queryVars) {
        $provider = rank_math_test_reset('link');
        $GLOBALS['wp']->public_query_vars = $queryVars;
        $optionsBefore = $GLOBALS['wpdb']->rows('options');
        $queryReceipt = null;
        try {
            $queryReceipt = $provider->invoke('rebuild_all_link_state', []);
        } catch (Throwable $failure) {
            wprism_check(false, "$label unexpectedly refused: " . $failure->getMessage());
        }
        wprism_check_same(true, $queryReceipt['verified'] ?? null,
            "$label survives the provider invoke and independent observation path");
        wprism_check($GLOBALS['rank_math_test_process_calls'] > 0,
            "$label reaches native repair rather than receiving a vacuous success");
        wprism_check_same($queryVars, $GLOBALS['wp']->public_query_vars,
            "$label preserves native bytes, keys, order and duplicates");
        wprism_check_same($optionsBefore, $GLOBALS['wpdb']->rows('options'),
            "$label never rewrites stored route options to satisfy observation");
    }

    foreach ([
        'missing registry' => null,
        'object registry' => (object) ['name' => 'p'],
        'scalar registry' => 'p',
        'integer name' => ['p', 1],
        'boolean name' => ['p', false],
        'array name' => ['p', ['wc/file/transient']],
        'object name' => ['p', (object) ['name' => 'wc/file/transient']],
        'oversized name' => [str_repeat('x', 4097)],
        'oversized registry' => array_fill(0, 2049, 'p'),
    ] as $label => $queryVars) {
        $provider = rank_math_test_reset('link');
        $GLOBALS['wp']->public_query_vars = $queryVars;
        $tables = ['rank_math_internal_links', 'rank_math_internal_meta', 'postmeta', 'options'];
        $before = array_map(static fn(string $table): array => $GLOBALS['wpdb']->rows($table), $tables);
        wprism_check_throws(
            static fn(): array => rank_math_test_execute_child(),
            RuntimeException::class,
            "$label refuses through the provider's operation boundary",
            'public query-var topology is malformed or unbounded'
        );
        wprism_check_same(0, $GLOBALS['rank_math_test_process_calls'],
            "$label refuses before any native link mutation");
        wprism_check_same($before,
            array_map(static fn(string $table): array => $GLOBALS['wpdb']->rows($table), $tables),
            "$label preserves every link, count, marker and option row");
    }

    $queryBase = ['p', 'wc/file/transient', 'a.b', 'lang', 'lang'];
    foreach ([
        'slash to underscore' => ['p', 'wc_file_transient', 'a.b', 'lang', 'lang'],
        'dot to underscore' => ['p', 'wc/file/transient', 'a_b', 'lang', 'lang'],
        'order change' => ['p', 'a.b', 'wc/file/transient', 'lang', 'lang'],
        'duplicate removal' => ['p', 'wc/file/transient', 'a.b', 'lang'],
        'binary suffix' => ['p', "wc/file/transient\0", 'a.b', 'lang', 'lang'],
    ] as $label => $queryVars) {
        $provider = rank_math_test_reset('link');
        try {
            $GLOBALS['wp']->public_query_vars = $queryBase;
            $before = rank_math_test_projection();
            $GLOBALS['wp']->public_query_vars = $queryVars;
            $after = rank_math_test_projection();
            wprism_check($before['dependency_hash'] !== $after['dependency_hash'],
                "$label remains visible to exact runtime dependency comparison");
            wprism_check_same($before['dependency_state_hash'], $after['dependency_state_hash'],
                "$label changes only request-local topology, not the durable-state witness");
        } catch (Throwable $failure) {
            wprism_check(false, "$label witness unexpectedly refused: " . $failure->getMessage());
        }
    }

    foreach ([
        'native endpoint replacement' => ['p', 'wc/file/changed', 'lang', 'lang'],
        'native endpoint reordering' => ['wc/file/transient', 'p', 'lang', 'lang'],
        'native duplicate removal' => ['p', 'wc/file/transient', 'lang'],
    ] as $label => $queryVars) {
        $provider = rank_math_test_reset('link');
        $GLOBALS['wp']->public_query_vars = ['p', 'wc/file/transient', 'lang', 'lang'];
        $tables = ['rank_math_internal_links', 'rank_math_internal_meta', 'postmeta', 'options'];
        $before = array_map(static fn(string $table): array => $GLOBALS['wpdb']->rows($table), $tables);
        $GLOBALS['rank_math_test_url_to_postid_override'] = static function (
            string $_url,
            bool $insideNativeProcess
        ) use ($queryVars): ?int {
            if ($insideNativeProcess) {
                $GLOBALS['wp']->public_query_vars = $queryVars;
            }
            return null;
        };
        wprism_check_throws(
            static fn(): array => rank_math_test_execute_child(),
            RuntimeException::class,
            "$label during native repair cannot receive verified success",
            'route runtime changed during its complete projection'
        );
        wprism_check($GLOBALS['rank_math_test_process_calls'] > 0,
            "$label control reaches the actual native mutation callback");
        wprism_check_same($before,
            array_map(static fn(string $table): array => $GLOBALS['wpdb']->rows($table), $tables),
            "$label rolls back exact link, count, marker and option rows");
    }

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    $GLOBALS['wp_rewrite']->rules = null;
    $emptyStoredRules = $GLOBALS['rank_math_test_options']['rewrite_rules'];
    $emptyStoredRows = $GLOBALS['wpdb']->rows('options');
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'pretty-permalink repair refuses empty stored and loaded rewrite rules before native resolution can regenerate them',
        'requires exact non-empty stored and loaded rewrite rules'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
        'empty pretty-permalink rules refuse before the native child can mutate link state');
    wprism_check_same(null, $GLOBALS['wp_rewrite']->rules,
        'an empty durable rule set is never promoted into the request-local runtime');
    wprism_check_same($emptyStoredRules, $GLOBALS['rank_math_test_options']['rewrite_rules'],
        'the empty-rule refusal cannot regenerate or persist rewrite state');
    wprism_check_same($emptyStoredRows, $GLOBALS['wpdb']->rows('options'),
        'the empty-rule refusal leaves the durable options table byte-for-byte unchanged');

    $provider = rank_math_test_reset('link');
    $lazyRules = ['([^/]+)/?$' => 'index.php?name=$matches[1]'];
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $lazyRules);
    $GLOBALS['wp_rewrite']->rules = false;
    $malformedRuntimeRows = $GLOBALS['wpdb']->rows('options');
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'a present malformed rewrite cache is never replaced by otherwise-valid stored rules',
        'requires exact non-empty stored and loaded rewrite rules'
    );
    wprism_check_same(false, $GLOBALS['wp_rewrite']->rules,
        'present malformed runtime rules remain untouched at refusal');
    wprism_check_same($malformedRuntimeRows, $GLOBALS['wpdb']->rows('options'),
        'present malformed runtime refusal leaves the durable options table byte-for-byte unchanged');
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
        'present malformed runtime refusal launches no child process');

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', 'malformed-stored-rules');
    $GLOBALS['wp_rewrite']->rules = null;
    $malformedStoredRows = $GLOBALS['wpdb']->rows('options');
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'malformed stored rewrite rules are never promoted into an empty request-local cache',
        'requires exact non-empty stored and loaded rewrite rules'
    );
    wprism_check_same(null, $GLOBALS['wp_rewrite']->rules,
        'malformed stored rules leave the null request-local cache untouched');
    wprism_check_same($malformedStoredRows, $GLOBALS['wpdb']->rows('options'),
        'malformed stored-rule refusal leaves the durable options table byte-for-byte unchanged');
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
        'malformed stored-rule refusal launches no child process');

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $lazyRules);
    $GLOBALS['wp_rewrite']->rules = null;
    $lazyStoredRules = $GLOBALS['rank_math_test_options']['rewrite_rules'];
    $lazyStoredRows = $GLOBALS['wpdb']->rows('options');
    $lazyReceipt = $provider->invoke('rebuild_all_link_state', []);
    wprism_check_same(true, $lazyReceipt['verified'] ?? null,
        'a virgin parent request hydrates its null WordPress rewrite cache from exact non-empty stored rules');
    wprism_check_same($lazyRules, $GLOBALS['wp_rewrite']->rules,
        'parent hydration publishes exactly the effective stored array into the request-local cache');
    wprism_check_same($lazyStoredRules, $GLOBALS['rank_math_test_options']['rewrite_rules'],
        'parent rewrite-cache hydration never regenerates or persists durable rules');
    wprism_check_same($lazyStoredRows, $GLOBALS['wpdb']->rows('options'),
        'parent rewrite-cache hydration leaves the durable options table byte-for-byte unchanged');
    wprism_check_same(2, count($GLOBALS['rank_math_test_command_calls']),
        'the hydrated parent launches bounded mutation and readback processes');

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $lazyRules);
    $GLOBALS['wp_rewrite'] = new RankMathExtendedRewrite(null);
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'a null rewrite cache on an extended runtime refuses before request-local initialization',
        'requires the exact core rewrite runtime'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
        'extended rewrite-runtime refusal launches no child process');

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', ['stored/?$' => 'index.php?pagename=stored']);
    $GLOBALS['wp_rewrite']->rules = ['loaded/?$' => 'index.php?pagename=loaded'];
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'pretty-permalink repair refuses stored/loaded rewrite-rule drift before URL resolution',
        'requires exact non-empty stored and loaded rewrite rules'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
        'rewrite-rule drift refuses before the native child can mutate link state');

    $provider = rank_math_test_reset('link');
    unset($GLOBALS['wp_filter']['rank_math/excluded_post_types']);
    $firstDeployReceipt = $provider->invoke('rebuild_all_link_state', []);
    $firstDeployHook = $GLOBALS['wp_filter']['rank_math/excluded_post_types'] ?? null;
    $firstDeployEntries = $firstDeployHook instanceof WP_Hook
        ? ($firstDeployHook->callbacks[10] ?? [])
        : [];
    $firstDeployEntry = count($firstDeployEntries) === 1 ? reset($firstDeployEntries) : null;
    $firstDeployCallback = is_array($firstDeployEntry) ? ($firstDeployEntry['function'] ?? null) : null;
    wprism_check_same(true, $firstDeployReceipt['verified'] ?? null,
        'virgin pre-apply settlement hydrates the native Rank Math default instead of requiring authored registration state');
    wprism_check(
        is_array($firstDeployCallback)
            && is_object($firstDeployCallback[0] ?? null)
            && get_class($firstDeployCallback[0]) === \RankMath\Defaults::class
            && ($firstDeployCallback[1] ?? null) === 'excluded_post_types',
        'the missing-hook bootstrap produces exactly the audited native callback'
    );
    wprism_check_same(2, count($GLOBALS['rank_math_test_command_calls']),
        'the virgin pre-apply path still launches bounded mutation and readback processes');

    $provider = rank_math_test_reset('link');
    $receipt = rank_math_test_execute_child();
    wprism_check_same(true, $receipt['verified'] ?? null,
        'the engine dispatcher grants one child authority to the exact native provider operation');
    foreach (['substitute', 'extension', 'method'] as $mode) {
        $provider = rank_math_test_reset('link');
        $entries = $GLOBALS['wp_filter']['rank_math/excluded_post_types']->callbacks[10];
        if ($mode === 'substitute') {
            $substitute = new class() {
                public function excluded_post_types(array $types): array { return $types; }
            };
            $entries['rank-math-native']['function'] = [$substitute, 'excluded_post_types'];
        } elseif ($mode === 'extension') {
            $entries['extension'] = [
                'accepted_args' => 1,
                'function' => [new \RankMath\Defaults(), 'excluded_post_types'],
            ];
        } else {
            $entries['rank-math-native']['function'][1] = 'other_method';
        }
        $GLOBALS['wp_filter']['rank_math/excluded_post_types'] = new WP_Hook([10 => $entries]);
        wprism_check_throws(
            static fn(): array => rank_math_test_execute_child(),
            RuntimeException::class,
            "engine-dispatched fresh operation refuses $mode substitution at the native exclusion hook",
            'requires exactly its audited native exclusion callback'
        );
    }
    $provider = rank_math_test_reset('link');
    $receipt = $provider->invoke('rebuild_all_link_state', []);
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
    $retry = $provider->invoke('rebuild_all_link_state', []);
    wprism_check_same($retry['before'], $retry['after'],
        'a complete native retry is idempotent at its exact current projection');

    $operation = ['format' => 'wprism-scoped-effect-operation/v1', 'id' => 'rank-math-fixture'];
    $scoped = $provider->invoke_scoped('rebuild_all_link_state', [], $operation);
    wprism_check_same(true, $scoped['verified'] ?? null,
        'scoped post application executes the same complete native repair under an operation envelope');
    $callsAfterScopedInvoke = count($GLOBALS['rank_math_test_command_calls']);
    $callbackCallsAfterScopedInvoke = [
        $GLOBALS['rank_math_test_permalink_calls'],
        $GLOBALS['rank_math_test_url_to_postid_calls'],
    ];
    $GLOBALS['rank_math_test_hooks']['rank_math/links/is_external'] = true;
    $reconciled = $provider->reconcile_scoped('rebuild_all_link_state', [], $operation);
    wprism_check_same($scoped['after'] ?? null, $reconciled['after'] ?? null,
        'scoped recovery re-reads the exact durable link projection');
    wprism_check_same($callsAfterScopedInvoke, count($GLOBALS['rank_math_test_command_calls']),
        'scoped recovery readback never repeats the native mutation');
    wprism_check_same(
        $callbackCallsAfterScopedInvoke,
        [$GLOBALS['rank_math_test_permalink_calls'], $GLOBALS['rank_math_test_url_to_postid_calls']],
        'scoped recovery reads only derived database state and never re-enters permalink or query callbacks'
    );
    $rows = $GLOBALS['wpdb']->rows('postmeta');
    foreach ($rows as &$row) {
        if ((int) ($row['post_id'] ?? 0) === 20
            && ($row['meta_key'] ?? null) === 'rank_math_internal_links_processed') {
            $row['meta_value'] = 'scoped-recovery-race';
        }
    }
    unset($row);
    $GLOBALS['wpdb']->seedTable('postmeta', $rows);
    $divergentReconcile = $provider->reconcile_scoped('rebuild_all_link_state', [], $operation);
    wprism_check(
        ($divergentReconcile['after'] ?? null) !== ($scoped['after'] ?? null),
        'scoped recovery exposes a same-count competing projection for the engine receipt-hash fence'
    );

    $provider = rank_math_test_reset('link');
    $scopedRaceInjected = false;
    $GLOBALS['rank_math_test_after_result'] = static function () use (&$scopedRaceInjected): void {
        $db = $GLOBALS['wpdb'];
        $rows = $db->rows('postmeta');
        foreach ($rows as &$row) {
            if ((int) ($row['post_id'] ?? 0) === 20
                && ($row['meta_key'] ?? null) === 'rank_math_internal_links_processed') {
                $row['meta_value'] = 'after-semantic-proof';
            }
        }
        unset($row);
        $db->seedTable('postmeta', $rows);
        $scopedRaceInjected = true;
    };
    wprism_check_throws(
        static fn(): array => $provider->invoke_scoped('rebuild_all_link_state', [], $operation),
        RuntimeException::class,
        'fresh-process parent proof refuses a competing write after child semantic verification',
        'fresh-process receipt disagrees with independent parent readback'
    );
    wprism_check_same(true, $scopedRaceInjected,
        'scoped receipt regression injects a competing write after semantic verification');
    $racedCurrent = $provider->reconcile_scoped('rebuild_all_link_state', [], $operation);
    $childReceipt = $GLOBALS['rank_math_test_last_child_receipt'] ?? null;
    $childAfter = is_array($childReceipt) ? ($childReceipt['after'] ?? null) : null;
    $project = new ReflectionMethod(RankMathState::class, 'project_rebuild_all_link_state');
    $childScoped = is_array($childAfter) ? $project->invoke($provider, $childAfter) : null;
    wprism_check_same(
        ['link_count', 'link_hash', 'meta_count', 'meta_hash', 'marker_count', 'marker_hash'],
        array_keys(is_array($childScoped) ? $childScoped : []),
        'scoped receipt projection closes over only the three verified derived-state surfaces'
    );
    wprism_check(
        $childScoped !== ($racedCurrent['after'] ?? null),
        'the refused child receipt remains distinct from the independently observed raced postimage'
    );

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('rank_math_modules', []);
    $disabled = $provider->invoke('rebuild_all_link_state', []);
    wprism_check_same(
        ['link-counter', 'redirections'],
        $GLOBALS['rank_math_test_modules'],
        'durable module state wins even when a process-external option cache remains stale'
    );
    wprism_check_same(false, $disabled['after']['enabled'] ?? null, 'receipt binds the disabled module decision');
    wprism_check_same([0, 0, 0], [
        $disabled['after']['link_count'] ?? null,
        $disabled['after']['meta_count'] ?? null,
        $disabled['after']['marker_count'] ?? null,
    ], 'disabling link-counter removes every plugin-owned derived row and marker');
    wprism_check_same('preserve', $GLOBALS['wpdb']->rows('postmeta')[0]['meta_value'] ?? null,
        'disabled-module cleanup preserves unrelated post metadata');

    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_link_state', ['entities' => []]),
        RuntimeException::class,
        'the retired entity capability cannot bypass site-wide deletion convergence',
        "does not implement capability 'rebuild_link_state'"
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['wpdb']->failNextQuery('projection secret sk_rank_math_projection', 'SELECT `url`');
    $readFailure = rank_math_test_throw_message(
        static fn(): array => rank_math_test_execute_child()
    );
    wprism_check(
        str_contains($readFailure, 'provider checked read failed: Rank Math link projection')
            && !str_contains($readFailure, 'sk_rank_math_projection'),
        'engine-dispatched pre-mutation projection failure is loud and redacted'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'], 'failed precondition read launches no child process');

    $hostile = 'child token sk_rank_math_child_must_not_escape';
    $cases = [
        'nonzero exit' => (object) ['return_code' => 9, 'stdout' => '', 'stderr' => $hostile],
        'stderr on success' => (object) ['return_code' => 0, 'stdout' => '{}', 'stderr' => $hostile],
        'malformed json' => (object) ['return_code' => 0, 'stdout' => '{' . $hostile, 'stderr' => ''],
        'extra receipt authority' => static function () use ($hostile): object {
            $pendingIdentity = new ReflectionMethod(\WPrism\ProviderOperationProcess::class, 'pending_identity');
            $pending = $pendingIdentity->invoke(null);
            return (object) [
                'return_code' => 0,
                'stdout' => \WPrism\Canon::encode([
                    'format' => \WPrism\ProviderOperationProcess::RECEIPT_FORMAT,
                    'request_sha256' => $pending['request_sha256'] ?? '',
                    'adapter' => $pending['adapter'] ?? '',
                    'adapter_digest' => $pending['adapter_digest'] ?? '',
                    'provider' => $pending['provider'] ?? '',
                    'capability' => $pending['capability'] ?? '',
                    'receipt' => ['before' => [], 'after' => [], 'verified' => true],
                    'secret' => $hostile,
                ]),
                'stderr' => '',
            ];
        },
    ];
    foreach ($cases as $label => $result) {
        $provider = rank_math_test_reset('link');
        $GLOBALS['rank_math_test_command_result'] = $result;
        $message = rank_math_test_throw_message(
            static fn(): array => $provider->invoke('rebuild_all_link_state', [])
        );
        wprism_check(
            str_contains($message, 'recovery_required') && !str_contains($message, 'sk_rank_math_child'),
            "$label refuses with recovery debt and no child-output leak"
        );
    }

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_command_throw'] = new RuntimeException($hostile);
    $launchFailure = rank_math_test_throw_message(
        static fn(): array => $provider->invoke('rebuild_all_link_state', [])
    );
    wprism_check(
        str_contains($launchFailure, 'transport outcome is unknown') && !str_contains($launchFailure, 'sk_rank_math_child'),
        'child launch exception is wrapped without exposing its value'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $projection = rank_math_test_projection();
        $alternate = str_repeat('b', 64);
        if (($projection['dependency_hash'] ?? null) === $alternate) {
            $alternate = str_repeat('c', 64);
        }
        $projection['dependency_hash'] = $alternate;
        $GLOBALS['rank_math_test_proved_projection'] = $projection;
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        $GLOBALS['rank_math_test_proved_projection']
    );
    $freshTopologyReceipt = $provider->invoke('rebuild_all_link_state', []);
    $staleParentProjection = rank_math_test_projection();
    $freshTopologyProjection = $freshTopologyReceipt['after'];
    $staleParentWithoutDependency = $staleParentProjection;
    $freshWithoutDependency = $freshTopologyProjection;
    unset($staleParentWithoutDependency['dependency_hash'], $freshWithoutDependency['dependency_hash']);
    ksort($staleParentWithoutDependency, SORT_STRING);
    ksort($freshWithoutDependency, SORT_STRING);
    wprism_check_same(true, $freshTopologyReceipt['verified'] ?? null,
        'stable post-apply parent evidence publishes verified repair despite stale request-local topology');
    wprism_check_same(2, count($GLOBALS['rank_math_test_command_calls']),
        'post-apply topology proof uses independent fresh mutation and readback processes');
    wprism_check_same($staleParentWithoutDependency, $freshWithoutDependency,
        'the prior live defect fixture differs from the applying parent only at request-local route dependencies');
    wprism_check_same(
        $staleParentProjection['dependency_state_hash'] ?? null,
        $freshTopologyProjection['dependency_state_hash'] ?? null,
        'the stable dependency hash retains exact equality across the request-local topology difference'
    );
    wprism_check(
        ($staleParentProjection['dependency_hash'] ?? null) !== ($freshTopologyProjection['dependency_hash'] ?? null),
        'the prior live defect would fail a stale applying-parent projection comparison'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_command_result'] = static function (): object {
        $projection = rank_math_test_projection();
        if (count($GLOBALS['rank_math_test_command_calls']) === 1) {
            $projection['link_hash'] = str_repeat('d', 64);
        }
        return rank_math_test_result($projection);
    };
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'success-shaped child receipt with a divergent exact projection refuses',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $rows = $GLOBALS['wpdb']->rows('rank_math_internal_meta');
        foreach ($rows as &$row) {
            if ((int) $row['object_id'] === 20) {
                $row['incoming_link_count'] = 31337;
            }
        }
        unset($row);
        $GLOBALS['wpdb']->seedTable('rank_math_internal_meta', $rows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'a competing count write after child proof refuses verified success',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $rows = $GLOBALS['wpdb']->rows('postmeta');
        foreach ($rows as &$row) {
            if ((int) $row['post_id'] === 20
                && $row['meta_key'] === 'rank_math_internal_links_processed') {
                $row['meta_value'] = 'competing-write';
            }
        }
        unset($row);
        $GLOBALS['wpdb']->seedTable('postmeta', $rows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'same-count competing marker write after child proof refuses recovery debt',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $rows = $GLOBALS['wpdb']->rows('posts');
        $rows[0]['post_content'] = '<a href="/twenty">same identities, different authored route</a>';
        $GLOBALS['wpdb']->seedTable('posts', $rows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'same-count authored post change after child proof refuses recovery debt',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $rules = ['(.+?)/?$' => 'index.php?name=$matches[1]'];
    rank_math_test_set_option('permalink_structure', '/%author%/%category%/%postname%/');
    rank_math_test_set_option('rewrite_rules', $rules);
    $GLOBALS['wp_rewrite']->rules = $rules;
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $rows = $GLOBALS['wpdb']->rows('users');
        $rows[0]['user_nicename'] = 'changed-author-route';
        $GLOBALS['wpdb']->seedTable('users', $rows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'author nicename change after a %author% proof invalidates native resolution',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $rules = ['(.+?)/?$' => 'index.php?name=$matches[1]'];
    rank_math_test_set_option('permalink_structure', '/%category%/%postname%/');
    rank_math_test_set_option('rewrite_rules', $rules);
    $GLOBALS['wp_rewrite']->rules = $rules;
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $GLOBALS['rank_math_test_options']['default_category'] = 2;
        $rows = $GLOBALS['wpdb']->rows('options');
        foreach ($rows as &$row) {
            if (($row['option_name'] ?? null) === 'default_category') {
                $row['option_value'] = '2';
            }
        }
        unset($row);
        $GLOBALS['wpdb']->seedTable('options', $rows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'default-category change after a no-term %category% proof invalidates native resolution',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $rules = ['(.+?)/?$' => 'index.php?name=$matches[1]'];
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $rules);
    $GLOBALS['wp_rewrite']->rules = $rules;
    $rows = $GLOBALS['wpdb']->rows('posts');
    $rows[0]['post_content'] .= '<a href="/wp-core-page/">Pretty page</a>';
    $GLOBALS['wpdb']->seedTable('posts', $rows);
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $GLOBALS['rank_math_test_options']['posts_per_page'] = 11;
        $optionRows = $GLOBALS['wpdb']->rows('options');
        foreach ($optionRows as &$optionRow) {
            if (($optionRow['option_name'] ?? null) === 'posts_per_page') {
                $optionRow['option_value'] = '11';
            }
        }
        unset($optionRow);
        $GLOBALS['wpdb']->seedTable('options', $optionRows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'pretty-page query-option change after child proof invalidates native resolution',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $rules = ['(.+?)/?$' => 'index.php?name=$matches[1]'];
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $rules);
    $GLOBALS['wp_rewrite']->rules = $rules;
    $rows = $GLOBALS['wpdb']->rows('posts');
    $rows[0]['post_content'] .= '<a href="/wp-core-page/">Old-comment closure</a>';
    $GLOBALS['wpdb']->seedTable('posts', $rows);
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        rank_math_test_set_option('close_comments_days_old', 21);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'old-comment closure option change after child proof invalidates singular native resolution',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $rules = ['comments/feed/?$' => 'index.php?feed=comments-rss2'];
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $rules);
    $GLOBALS['wp_rewrite']->rules = $rules;
    $rows = $GLOBALS['wpdb']->rows('posts');
    $rows[0]['post_content'] .= '<a href="/comments/feed/">Comment feed</a>';
    $GLOBALS['wpdb']->seedTable('posts', $rows);
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $commentRows = $GLOBALS['wpdb']->rows('comments');
        $commentRows[0]['comment_approved'] = '0';
        $GLOBALS['wpdb']->seedTable('comments', $commentRows);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'comment-feed row change after child proof invalidates native resolution',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_after_command'] = static function (): void {
        if (count($GLOBALS['rank_math_test_command_calls']) !== 1) {
            return;
        }
        rank_math_test_rebuild();
        $GLOBALS['rank_math_test_proved_projection'] = rank_math_test_projection();
        $GLOBALS['wpdb']->seedTable('terms', [[
            'term_id' => 7, 'name' => 'Changed Route', 'slug' => 'changed-route', 'term_group' => 0,
        ]]);
        $GLOBALS['wpdb']->seedTable('term_taxonomy', [[
            'term_taxonomy_id' => 7, 'term_id' => 7, 'taxonomy' => 'category',
            'description' => '', 'parent' => 0, 'count' => 1,
        ]]);
        $GLOBALS['wpdb']->seedTable('term_relationships', [[
            'object_id' => 10, 'term_taxonomy_id' => 7, 'term_order' => 0,
        ]]);
    };
    $GLOBALS['rank_math_test_command_result'] = static fn(): object => rank_math_test_result(
        count($GLOBALS['rank_math_test_command_calls']) === 1
            ? $GLOBALS['rank_math_test_proved_projection']
            : null
    );
    wprism_check_throws(
        static fn(): array => $provider->invoke('rebuild_all_link_state', []),
        RuntimeException::class,
        'term route change after child proof refuses recovery debt',
        'fresh-process receipt disagrees with independent parent readback'
    );

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_helper_types'] = ['post' => 'post'];
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'stale Rank Math accessible-type cache refuses before native repair',
        'disagree with the current audited topology'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
        'stale accessible-type cache launches no child process');

    $provider = rank_math_test_reset('link');
    $GLOBALS['wpdb']->setTableEngine('terms', 'MyISAM');
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'non-transactional route dependency refuses before native repair',
        'InnoDB required): wp_terms'
    );
    wprism_check_same([], $GLOBALS['rank_math_test_command_calls'],
        'route storage-engine refusal launches no child process');

    $provider = rank_math_test_reset('link');
    $GLOBALS['rank_math_test_url_to_postid_override'] = static function (
        string $url,
        bool $insideNativeProcess
    ): ?int {
        return $insideNativeProcess && str_contains($url, '/target-20') ? 10 : null;
    };
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'a resolver that returns B only during native mutation cannot hide behind A-before/A-after witnesses',
        'stored links did not converge with current native resolution'
    );
    wprism_check(
        $GLOBALS['rank_math_test_process_calls'] > 0,
        'the stateful resolver regression reaches native mutation before exact post-proof refusal'
    );

    $baseLinks = rank_math_test_desired_links();
    $wrongUrl = $baseLinks;
    $wrongUrl[0]['url'] = '/twenty';
    $wrongTarget = $baseLinks;
    $wrongTarget[0]['target_post_id'] = 10;
    $wrongType = $baseLinks;
    $wrongType[1]['type'] = 'internal';
    $wrongType[1]['target_post_id'] = 20;
    $duplicate = $baseLinks;
    $duplicate[] = ['id' => 105] + $baseLinks[0];
    foreach ([
        'missing edge' => array_values(array_slice($baseLinks, 1)),
        'wrong URL' => $wrongUrl,
        'wrong existing target' => $wrongTarget,
        'wrong type' => $wrongType,
        'extra duplicate' => $duplicate,
    ] as $label => $storedLinks) {
        $provider = rank_math_test_reset('link');
        $GLOBALS['rank_math_test_rebuild_links_override'] = $storedLinks;
        wprism_check_throws(
            static fn(): array => rank_math_test_execute_child(),
            RuntimeException::class,
            "$label cannot satisfy exact native edge convergence with matching aggregate counts",
            'stored links did not converge with current native resolution'
        );
    }

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $lazyRules);
    $GLOBALS['wp_rewrite'] = new RankMathExtendedRewrite(null);
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'the fresh child refuses an extended rewrite runtime before its first derived-state reset',
        'requires the exact core rewrite runtime'
    );
    wprism_check_same(0, $GLOBALS['rank_math_test_process_calls'],
        'fresh-child rewrite-runtime refusal precedes every native post callback');
    wprism_check_same(2, count($GLOBALS['wpdb']->rows('rank_math_internal_links')),
        'fresh-child rewrite-runtime refusal precedes every destructive link query');

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $lazyRules);
    $GLOBALS['wp_rewrite']->rules = false;
    $malformedChildRuntimeRows = $GLOBALS['wpdb']->rows('options');
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'the fresh child refuses a present malformed rewrite cache before derived-state mutation',
        'requires exact non-empty stored and loaded rewrite rules'
    );
    wprism_check_same(false, $GLOBALS['wp_rewrite']->rules,
        'fresh-child malformed runtime refusal leaves the request-local value untouched');
    wprism_check_same($malformedChildRuntimeRows, $GLOBALS['wpdb']->rows('options'),
        'fresh-child malformed runtime refusal leaves the durable options table byte-for-byte unchanged');
    wprism_check_same(0, $GLOBALS['rank_math_test_process_calls'],
        'fresh-child malformed runtime refusal precedes every native post callback');
    wprism_check_same(2, count($GLOBALS['wpdb']->rows('rank_math_internal_links')),
        'fresh-child malformed runtime refusal precedes every destructive link query');

    $provider = rank_math_test_reset('link');
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', 'malformed-stored-rules');
    $GLOBALS['wp_rewrite']->rules = null;
    $malformedChildStoredRows = $GLOBALS['wpdb']->rows('options');
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'the fresh child refuses malformed stored rules before request-local initialization or mutation',
        'requires exact non-empty stored and loaded rewrite rules'
    );
    wprism_check_same(null, $GLOBALS['wp_rewrite']->rules,
        'fresh-child malformed stored rules leave the null request-local cache untouched');
    wprism_check_same($malformedChildStoredRows, $GLOBALS['wpdb']->rows('options'),
        'fresh-child malformed stored-rule refusal leaves the durable options table byte-for-byte unchanged');
    wprism_check_same(0, $GLOBALS['rank_math_test_process_calls'],
        'fresh-child malformed stored-rule refusal precedes every native post callback');
    wprism_check_same(2, count($GLOBALS['wpdb']->rows('rank_math_internal_links')),
        'fresh-child malformed stored-rule refusal precedes every destructive link query');

    $provider = rank_math_test_reset('link');
    $childLazyRules = ['([^/]+)/?$' => 'index.php?name=$matches[1]'];
    rank_math_test_set_option('permalink_structure', '/%postname%/');
    rank_math_test_set_option('rewrite_rules', $childLazyRules);
    $GLOBALS['wp_rewrite']->rules = null;
    unset($GLOBALS['wp_filter']['rank_math/excluded_post_types']);
    $childStoredRules = $GLOBALS['rank_math_test_options']['rewrite_rules'];
    $childStoredRows = $GLOBALS['wpdb']->rows('options');
    $registeredOrder = array_keys($GLOBALS['wp_post_types']);
    $GLOBALS['wpdb']->resetLog();
    $actualChild = rank_math_test_execute_child();
    wprism_check_same(true, $actualChild['verified'] ?? null,
        'the engine-dispatched fresh operation executes its complete enabled-module path offline');
    wprism_check_same($childLazyRules, $GLOBALS['wp_rewrite']->rules,
        'the fresh child hydrates its independent null rewrite cache from exact non-empty stored rules');
    wprism_check_same($childStoredRules, $GLOBALS['rank_math_test_options']['rewrite_rules'],
        'fresh-child rewrite-cache hydration never regenerates or persists durable rules');
    wprism_check_same($childStoredRows, $GLOBALS['wpdb']->rows('options'),
        'fresh-child rewrite-cache hydration leaves the durable options table byte-for-byte unchanged');
    wprism_check(
        ($GLOBALS['wp_filter']['rank_math/excluded_post_types'] ?? null) instanceof WP_Hook,
        'one virgin dispatched-child execution establishes both audited request-local native premises'
    );
    $parentProjection = rank_math_test_projection();
    wprism_check_same(
        \WPrism\Canon::encode($parentProjection),
        \WPrism\Canon::encode($actualChild['after'] ?? null),
        'an unchanged runtime gives the shared parent projector the same complete projection');
    wprism_check_same(1, count(array_filter(
        $GLOBALS['wpdb']->rows('rank_math_internal_links'),
        static fn(array $row): bool => ($row['type'] ?? null) === 'internal'
            && (int) ($row['target_post_id'] ?? -1) === 0
    )), 'exact edge verification admits a native unresolved same-site link with target zero');
    wprism_check_same($registeredOrder, array_keys($GLOBALS['wp_post_types']),
        'route observation preserves the registered post-type global order');
    $valueQueries = array_values(array_filter(
        $GLOBALS['wpdb']->queries(),
        static fn(string $sql): bool => str_starts_with($sql, 'SELECT `')
            && str_contains($sql, ' FROM `')
    ));
    $allValueQueriesBounded = $valueQueries !== [];
    foreach ($valueQueries as $sql) {
        if (!str_contains($sql, 'OCTET_LENGTH(')
            || !str_contains($sql, ' LIMIT ')
            || !str_contains($sql, ' OFFSET ')
            || preg_match('/ LIMIT ([0-9]+)/D', $sql, $limit) !== 1
            || (int) $limit[1] > 1024) {
            $allValueQueriesBounded = false;
            break;
        }
    }
    wprism_check($allValueQueriesBounded,
        'every complete value projection is raw-byte-filtered and transferred one bounded page at a time');

    $provider = rank_math_test_reset('link');
    $GLOBALS['wpdb']->setTableEngine('rank_math_internal_links', 'MyISAM');
    $GLOBALS['wpdb']->resetLog();
    wprism_check_throws(
        static fn(): array => rank_math_test_execute_child(),
        RuntimeException::class,
        'fresh child refuses a non-transactional written table before its first reset',
        'InnoDB required): wp_rank_math_internal_links'
    );
    wprism_check_same(0, $GLOBALS['rank_math_test_process_calls'],
        'written-table storage refusal precedes every native process callback');
    wprism_check_same([], array_values(array_filter(
        $GLOBALS['wpdb']->queries(),
        static fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM')
    )), 'written-table storage refusal precedes every destructive child query');

    wprism_check_summary('regress_rank_math_provider');
}
