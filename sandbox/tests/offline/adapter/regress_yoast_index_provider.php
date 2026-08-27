<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../support/wp_cli_child_process_fake.php';

    $GLOBALS['yi_enabled'] = true;
    $GLOBALS['yi_post_types'] = ['post', 'page', 'attachment'];
    $GLOBALS['yi_command_result'] = (object) ['return_code' => 0, 'stdout' => '', 'stderr' => ''];
    $GLOBALS['yi_command_throw'] = null;
    $GLOBALS['yi_command_calls'] = [];
    $GLOBALS['yi_after_command'] = null;

    function wp_get_environment_type(): string {
        return 'fixture-production';
    }

    function YoastSEO(): object {
        return (object) [
            'helpers' => (object) [
                'indexable' => new class {
                    public function should_index_indexables(): bool {
                        return (bool) $GLOBALS['yi_enabled'];
                    }
                },
                'post_type' => new class {
                    public function get_indexable_post_types(): mixed {
                        return $GLOBALS['yi_post_types'];
                    }
                },
            ],
        ];
    }

    final class WP_CLI {
        use \DuoTest\WpCliChildRuntime;

        public static function runcommand(string $command, array $options): mixed {
            $GLOBALS['yi_command_calls'][] = [$command, $options];
            if ($GLOBALS['yi_command_throw'] instanceof \Throwable) {
                throw $GLOBALS['yi_command_throw'];
            }
            if (is_callable($GLOBALS['yi_after_command'])) {
                ($GLOBALS['yi_after_command'])();
            }
            return $GLOBALS['yi_command_result'];
        }
    }

    final class YoastIndexWpdb {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $terms = 'wp_terms';
        public string $last_error = '';
        public ?string $missingTable = null;
        public ?string $missingColumnTable = null;
        public ?string $failSchemaTable = null;
        public ?string $failCountContext = null;

        /** @var array<string,list<string>> */
        public array $columns = [
            'wp_yoast_indexable' => [
                'id', 'object_id', 'object_type', 'object_sub_type', 'permalink', 'permalink_hash', 'link_count',
            ],
            'wp_yoast_indexable_hierarchy' => ['indexable_id', 'ancestor_id', 'depth'],
            'wp_yoast_primary_term' => ['post_id', 'term_id', 'taxonomy', 'blog_id'],
            'wp_yoast_seo_links' => [
                'post_id', 'indexable_id', 'target_indexable_id', 'target_post_id', 'type', 'url',
            ],
        ];

        /** @var array<string,int> */
        public array $metrics = [];

        public function __construct() {
            $this->makeStale();
        }

        public function makeStale(): void {
            $this->metrics = [
                'public_posts' => 4,
                'indexables' => 3,
                'indexed_public_posts' => 3,
                'hierarchy_rows' => 1,
                'invalid_hierarchy_rows' => 1,
                'expected_primary_terms' => 1,
                'primary_term_rows' => 0,
                'missing_primary_terms' => 1,
                'invalid_primary_terms' => 0,
                'seo_link_rows' => 1,
                'unindexed_public_post_links' => 1,
                'invalid_seo_link_rows' => 1,
            ];
        }

        public function makeValid(): void {
            $this->metrics = [
                'public_posts' => 4,
                'indexables' => 8,
                'indexed_public_posts' => 4,
                'hierarchy_rows' => 2,
                'invalid_hierarchy_rows' => 0,
                'expected_primary_terms' => 1,
                'primary_term_rows' => 1,
                'missing_primary_terms' => 0,
                'invalid_primary_terms' => 0,
                'seo_link_rows' => 3,
                'unindexed_public_post_links' => 0,
                'invalid_seo_link_rows' => 0,
            ];
        }

        public function prepare(string $query, mixed ...$args): string {
            foreach ($args as $arg) {
                $replacement = "'" . str_replace("'", "''", (string) $arg) . "'";
                $query = preg_replace('/%s/', $replacement, $query, 1) ?? $query;
            }
            return $query;
        }

        public function get_col(string $query): array|false {
            if (preg_match('/SHOW COLUMNS FROM `([^`]+)`/', $query, $match) !== 1) {
                $this->last_error = 'fixture unexpected schema query';
                return false;
            }
            $table = $match[1];
            if ($this->failSchemaTable === $table) {
                $this->last_error = 'fixture schema details must not escape';
                return false;
            }
            $columns = $this->columns[$table] ?? [];
            if ($this->missingColumnTable === $table) {
                $columns = array_values(array_filter($columns, static fn(string $column): bool => $column !== 'link_count'));
            }
            $this->last_error = '';
            return $columns;
        }

        public function get_var(string $query): string|int|null {
            $this->last_error = '';
            if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $match) === 1) {
                $table = str_replace("''", "'", $match[1]);
                return $this->missingTable === $table ? null : $table;
            }

            $metric = $this->metricForQuery($query);
            if ($this->failCountContext === $metric) {
                $this->last_error = 'fixture query details must not escape';
                return null;
            }
            return $this->metrics[$metric] ?? null;
        }

        private function metricForQuery(string $query): string {
            if (str_contains($query, 'COUNT(DISTINCT p.ID)')) {
                return 'indexed_public_posts';
            }
            if (str_contains($query, 'i.link_count IS NULL')) {
                return 'unindexed_public_post_links';
            }
            if (str_contains($query, 'FROM wp_posts WHERE')) {
                return 'public_posts';
            }
            if (str_contains($query, '`wp_yoast_indexable_hierarchy` h LEFT JOIN')) {
                return 'invalid_hierarchy_rows';
            }
            if (str_contains($query, 'FROM wp_postmeta pm') && str_contains($query, 'NOT EXISTS')) {
                return 'missing_primary_terms';
            }
            if (str_contains($query, 'FROM wp_postmeta pm')) {
                return 'expected_primary_terms';
            }
            if (str_contains($query, '`wp_yoast_primary_term` pt LEFT JOIN')) {
                return 'invalid_primary_terms';
            }
            if (str_contains($query, '`wp_yoast_seo_links` links LEFT JOIN')) {
                return 'invalid_seo_link_rows';
            }
            foreach ([
                'wp_yoast_indexable' => 'indexables',
                'wp_yoast_indexable_hierarchy' => 'hierarchy_rows',
                'wp_yoast_primary_term' => 'primary_term_rows',
                'wp_yoast_seo_links' => 'seo_link_rows',
            ] as $table => $metric) {
                if (str_contains($query, "FROM `$table`")) {
                    return $metric;
                }
            }
            return 'unexpected_query';
        }
    }

    $GLOBALS['wpdb'] = new YoastIndexWpdb();
}

namespace Duo {
    final class Policy {}

    final class Providers {
        public const SCOPED_OPERATION_FORMAT = 'duo-scoped-effect-operation/v1';
    }
}

namespace {
    require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once dirname(__DIR__, 4) . '/manifests/providers/yoast-index.php';

    use Duo\Providers\YoastIndex;

    function yi_reset(): YoastIndex {
        $GLOBALS['yi_enabled'] = true;
        $GLOBALS['yi_post_types'] = ['post', 'page', 'attachment'];
        $GLOBALS['yi_command_result'] = (object) ['return_code' => 0, 'stdout' => '', 'stderr' => ''];
        $GLOBALS['yi_command_throw'] = null;
        $GLOBALS['yi_command_calls'] = [];
        $GLOBALS['duo_wp_cli_child_fake_stderr_first'] = false;
        $GLOBALS['wpdb'] = new YoastIndexWpdb();
        $GLOBALS['yi_after_command'] = static function (): void {
            $GLOBALS['wpdb']->makeValid();
        };
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/manifests/yoast.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return new YoastIndex($manifest['providers'][0]);
    }

    function yi_operation(): array {
        return [
            'format' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
            'authority_hash' => str_repeat('a', 64),
            'lease_session_id' => 'fixture-session',
            'operation_id' => 'fixture-operation',
            'input_hash' => str_repeat('b', 64),
            'effect_hash' => str_repeat('c', 64),
        ];
    }

    $provider = yi_reset();
    duo_check_same(
        ['id' => 'yoast-index', 'plugin' => 'wordpress-seo/wp-seo.php', 'version' => '2.0.0'],
        $provider->identity(),
        'provider identity makes the four-table contract fleet-visible'
    );
    $capability = $provider->capabilities()['reindex'] ?? null;
    duo_check_same(
        [
            'table:postmeta',
            'table:posts',
            'table:term_taxonomy',
            'table:terms',
            'table:yoast_indexable',
            'table:yoast_indexable_hierarchy',
            'table:yoast_primary_term',
            'table:yoast_seo_links',
        ],
        $capability['reads'] ?? null,
        'provider declares every authored input and derived projection it reads'
    );
    duo_check_same(
        [
            'table:yoast_indexable',
            'table:yoast_indexable_hierarchy',
            'table:yoast_primary_term',
            'table:yoast_seo_links',
        ],
        $capability['writes'] ?? null,
        'provider declares all four tables written by the exact plugin command'
    );
    duo_check_same('site', $capability['scope'] ?? null, 'reindex is bounded to one WordPress site');
    duo_check(($capability['idempotent'] ?? false) === true, 'reindex explicitly permits recovery retry');
    duo_check_same(600, $capability['timeout_seconds'] ?? null, 'provider retains the measured large-site timeout');
    duo_check_same(
        \Duo\Providers::SCOPED_OPERATION_FORMAT,
        $capability['scoped']['operation_envelope'] ?? null,
        'provider negotiates durable scoped recovery'
    );

    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 4) . '/manifests/yoast.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $manifestProvider = $manifest['providers'][0] ?? [];
    duo_check_same('2.0.0', $manifestProvider['version'] ?? null, 'manifest negotiates the exact provider identity');
    duo_check_same(
        ['WP_CLI', 'Yoast\\WP\\SEO\\Helpers\\Indexable_Helper', 'Yoast\\WP\\SEO\\Helpers\\Post_Type_Helper'],
        $manifestProvider['requires']['classes'] ?? null,
        'manifest preflights the exact plugin and command APIs'
    );
    $action = $manifest['actions'][0] ?? [];
    duo_check_same(
        [
            'option:wpseo', 'option:wpseo_llmstxt', 'option:wpseo_social', 'option:wpseo_taxonomy_meta',
            'option:wpseo_titles', 'option:woocommerce_permalinks', 'post:attachment', 'post:page', 'post:post',
            'term:category', 'term:post_tag',
        ],
        $action['triggers'] ?? null,
        'action runs only when an indexable authored input or the exact Woo permalink input changed'
    );
    duo_check_same(
        ['options', 'yoast_indexable', 'yoast_indexable_hierarchy', 'yoast_primary_term', 'yoast_seo_links'],
        array_map(static fn(array $effect): string => (string) ($effect['selector']['value'] ?? ''), $action['effects'] ?? []),
        'action checkpoints its option mutation and all four derived tables'
    );
    duo_check(
        count(array_filter(
            $action['effects'] ?? [],
            static fn(array $effect): bool => ($effect['mode'] ?? null) === 'restorable'
                && ($effect['selector']['scope'] ?? null) === 'database_checkpoint'
        )) === 5,
        'every command effect is explicitly recoverable'
    );

    $receipt = $provider->invoke('reindex', []);
    duo_check_same(1, count($GLOBALS['yi_command_calls']), 'provider invokes one bounded fresh process');
    [$yoastCommand, $yoastOptions] = $GLOBALS['yi_command_calls'][0];
    duo_check(
        str_starts_with($yoastCommand, 'exec ')
            && str_contains($yoastCommand, 'yoast index --reindex --skip-confirmation'),
        'bounded launch preserves the exact non-interactive plugin-owned command'
    );
    duo_check_same(
        ['launch' => true, 'return' => 'all', 'exit_error' => false],
        $yoastOptions,
        'the fake command boundary observes the isolated launch contract'
    );
    duo_check(($receipt['verified'] ?? false) === true, 'success is emitted only after relational readback');
    duo_check_same('fixture-production', $receipt['after']['environment_type'] ?? null, 'receipt records plugin execution context');
    duo_check_same(4, $receipt['after']['indexed_public_posts'] ?? null, 'every public post has an indexable');
    duo_check_same(1, $receipt['after']['primary_term_rows'] ?? null, 'primary-term projection is covered');
    duo_check_same(2, $receipt['after']['hierarchy_rows'] ?? null, 'hierarchy projection is covered');
    duo_check_same(3, $receipt['after']['seo_link_rows'] ?? null, 'SEO-link projection is covered');
    duo_check_same(0, $receipt['after']['invalid_seo_link_rows'] ?? null, 'SEO links bind back to source indexables');
    duo_check_same('reindexed', $receipt['after']['outcome'] ?? null, 'enabled branch is named without payload data');
    $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    duo_check(
        is_string($encoded)
            && !str_contains($encoded, 'fixture query details')
            && !str_contains($encoded, 'fixture schema details'),
        'successful receipt contains bounded counts rather than content or database diagnostics'
    );

    $again = $provider->invoke('reindex', []);
    duo_check_same($receipt['after'], $again['after'] ?? null, 'retry is idempotent and readback-stable');

    $operation = yi_operation();
    $scoped = $provider->invoke_scoped('reindex', [], $operation);
    duo_check_same($operation, $scoped['operation'] ?? null, 'scoped invocation echoes exact recovery authority');
    duo_check(($scoped['verified'] ?? false) === true, 'scoped invocation retains verified postcondition');
    $reconciled = $provider->reconcile_scoped('reindex', [], $operation);
    duo_check_same($operation, $reconciled['operation'] ?? null, 'reconcile binds its observation to the same authority');
    duo_check_same(4, $reconciled['after']['indexed_public_posts'] ?? null, 'reconcile independently rechecks durable state');

    $provider = yi_reset();
    $GLOBALS['yi_enabled'] = false;
    $GLOBALS['yi_after_command'] = null;
    $disabled = $provider->invoke('reindex', []);
    duo_check_same(
        'no-op (Yoast indexables are disabled by the plugin on this request)',
        $disabled['after']['outcome'] ?? null,
        'plugin-owned disabled policy is an explicit verified no-op'
    );
    duo_check_same(3, $disabled['after']['indexed_public_posts'] ?? null, 'disabled branch does not pretend stale cache was rebuilt');

    $provider = yi_reset();
    $GLOBALS['yi_command_result'] = (object) [
        'return_code' => 23,
        'stdout' => 'index command context',
        'stderr' => 'exact failure evidence',
    ];
    duo_check_throws(
        static fn() => $provider->invoke('reindex', []),
        \RuntimeException::class,
        'non-zero plugin command is a hard apply failure',
        'exited 23'
    );

    $provider = yi_reset();
    $GLOBALS['yi_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => '',
        'stderr' => str_repeat('credential-shaped-warning-', 4000),
    ];
    $GLOBALS['duo_wp_cli_child_fake_stderr_first'] = true;
    $stderrFirstMessage = '';
    try {
        $provider->invoke('reindex', []);
    } catch (RuntimeException $failure) {
        $stderrFirstMessage = $failure->getMessage();
    }
    duo_check(
        str_contains($stderrFirstMessage, 'emitted stderr despite exit 0')
            && !str_contains($stderrFirstMessage, 'credential-shaped'),
        'stderr-first output larger than a pipe reaches Yoast warning policy without leaking bytes'
    );

    $provider = yi_reset();
    $GLOBALS['yi_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => str_repeat('credential-shaped-boot-output-', 20000),
        'stderr' => '',
    ];
    $overflowMessage = '';
    try {
        $provider->invoke('reindex', []);
    } catch (RuntimeException $failure) {
        $overflowMessage = $failure->getMessage();
    }
    duo_check(
        str_contains($overflowMessage, "Yoast 'yoast index --reindex --skip-confirmation' could not start")
            && !str_contains($overflowMessage, 'credential-shaped'),
        'Yoast wraps helper overflow in its stable command failure without a verified receipt or output leak'
    );

    foreach ([null, (object) [], (object) ['return_code' => '0']] as $malformed) {
        $provider = yi_reset();
        $GLOBALS['yi_command_result'] = $malformed;
        duo_check_throws(
            static fn() => $provider->invoke('reindex', []),
            \RuntimeException::class,
            'malformed process result cannot false-green (' . get_debug_type($malformed) . ')',
            'could not start'
        );
    }

    $provider = yi_reset();
    $GLOBALS['yi_command_throw'] = new \RuntimeException('secret-shaped launch detail');
    duo_check_throws(
        static fn() => $provider->invoke('reindex', []),
        \RuntimeException::class,
        'command launch failure has an engine-authored public message',
        'could not start'
    );

    foreach ([
        'wp_yoast_indexable',
        'wp_yoast_indexable_hierarchy',
        'wp_yoast_primary_term',
        'wp_yoast_seo_links',
    ] as $table) {
        $provider = yi_reset();
        $GLOBALS['wpdb']->missingTable = $table;
        duo_check_throws(
            static fn() => $provider->invoke('reindex', []),
            \RuntimeException::class,
            "$table absence refuses before destructive --reindex",
            "$table is missing"
        );
        duo_check_same([], $GLOBALS['yi_command_calls'], "$table refusal precedes command launch");
    }

    $provider = yi_reset();
    $GLOBALS['wpdb']->missingColumnTable = 'wp_yoast_indexable';
    duo_check_throws(
        static fn() => $provider->invoke('reindex', []),
        \RuntimeException::class,
        'missing required schema column refuses before mutation',
        'missing required column(s): link_count'
    );
    duo_check_same([], $GLOBALS['yi_command_calls'], 'column drift refusal precedes command launch');

    $provider = yi_reset();
    $GLOBALS['wpdb']->failSchemaTable = 'wp_yoast_seo_links';
    duo_check_throws(
        static fn() => $provider->invoke('reindex', []),
        \RuntimeException::class,
        'schema probe diagnostics collapse to an engine-authored refusal',
        'schema probe failed for wp_yoast_seo_links'
    );

    foreach ([
        'indexed_public_posts' => ['indexed_public_posts' => 3, 'needle' => 'covers 3 of 4'],
        'invalid_hierarchy_rows' => ['invalid_hierarchy_rows' => 1, 'needle' => '1 invalid_hierarchy_rows'],
        'missing_primary_terms' => ['missing_primary_terms' => 1, 'needle' => '1 missing_primary_terms'],
        'invalid_primary_terms' => ['invalid_primary_terms' => 1, 'needle' => '1 invalid_primary_terms'],
        'unindexed_public_post_links' => ['unindexed_public_post_links' => 1, 'needle' => '1 unindexed_public_post_links'],
        'invalid_seo_link_rows' => ['invalid_seo_link_rows' => 1, 'needle' => '1 invalid_seo_link_rows'],
    ] as $name => $failure) {
        $provider = yi_reset();
        $GLOBALS['yi_after_command'] = static function () use ($failure): void {
            $GLOBALS['wpdb']->makeValid();
            foreach ($failure as $metric => $value) {
                if ($metric !== 'needle') {
                    $GLOBALS['wpdb']->metrics[$metric] = $value;
                }
            }
        };
        duo_check_throws(
            static fn() => $provider->invoke('reindex', []),
            \RuntimeException::class,
            "$name postcondition cannot false-green exit zero",
            $failure['needle']
        );
    }

    $provider = yi_reset();
    $GLOBALS['yi_after_command'] = static function (): void {
        $GLOBALS['wpdb']->makeValid();
        $GLOBALS['yi_enabled'] = false;
    };
    duo_check_throws(
        static fn() => $provider->invoke('reindex', []),
        \RuntimeException::class,
        'indexability policy change during command requires recovery',
        'indexability changed during reindex'
    );

    foreach ([[], ['post', 'bad/type'], 'post'] as $types) {
        $provider = yi_reset();
        $GLOBALS['yi_post_types'] = $types;
        duo_check_throws(
            static fn() => $provider->invoke('reindex', []),
            \RuntimeException::class,
            'malformed plugin post-type API refuses before mutation (' . get_debug_type($types) . ')',
            is_array($types) && $types !== [] ? 'invalid post type' : 'no indexable post types'
        );
        duo_check_same([], $GLOBALS['yi_command_calls'], 'post-type API refusal precedes command launch');
    }

    $provider = yi_reset();
    $GLOBALS['wpdb']->failCountContext = 'invalid_seo_link_rows';
    duo_check_throws(
        static fn() => $provider->invoke('reindex', []),
        \RuntimeException::class,
        'database read failure refuses without leaking driver diagnostics',
        'invalid SEO-link count query failed'
    );

    $provider = yi_reset();
    duo_check_throws(
        static fn() => $provider->invoke('unknown', []),
        \RuntimeException::class,
        'closed capability vocabulary refuses unknown work',
        'does not implement capability'
    );
    duo_check_throws(
        static fn() => $provider->reconcile_scoped('unknown', [], yi_operation()),
        \RuntimeException::class,
        'recovery path refuses unknown work identically',
        'does not implement capability'
    );

    duo_check_summary('Yoast index provider');
}
