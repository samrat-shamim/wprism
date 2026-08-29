<?php
/** Direct behavioral regression for the extracted pending gate scanner. */
declare(strict_types=1);

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    function esc_sql(string $value): string {
        return str_replace("'", "''", $value);
    }

    function get_post_types(array $_args, string $_output): array {
        return ['page', 'book'];
    }

    function get_taxonomies(array $_args, string $_output): array {
        return ['category', 'genre'];
    }

    function get_option(string $name): string {
        return $name === 'home' ? 'https://scanner.example.test/' : '';
    }

    function wp_upload_dir(mixed $_time = null, bool $_create = true): array {
        return ['baseurl' => 'https://scanner.example.test/wp-content/uploads/'];
    }

    function untrailingslashit(string $value): string {
        return rtrim($value, '/\\');
    }

    function is_serialized($value, $strict = true): bool {
        if (!is_string($value)) {
            return false;
        }
        return preg_match('/^(?:N;|[aObisCdE]:)/', trim($value)) === 1;
    }
}

namespace WPrism {
    require __DIR__ . '/../../../../agent/src/Capture/CaptureGateScanner.php';

    function check(bool $condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: $message\n");
            exit(1);
        }
        echo "ok: $message\n";
    }

    final class CaptureGateScannerWpdbFixture {
        public string $options = 'wp_options';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $term_relationships = 'wp_term_relationships';
        public string $termmeta = 'wp_termmeta';
        public string $last_error = '';
        /** @var string[] */
        public array $events = [];

        public function prepare(string $query, ...$args): array {
            return ['query' => $query, 'args' => $args];
        }

        public function get_results(string|array $query, mixed $_mode = null): array {
            $args = is_array($query) ? $query['args'] : [];
            $sql = is_array($query) ? $query['query'] : $query;
            if (str_contains($sql, 'SELECT post_type, COUNT(*) AS entities')) {
                $this->events[] = 'query:post-gaps';
                return [
                    ['post_type' => 'page', 'entities' => '1'],
                    ['post_type' => 'book', 'entities' => '2'],
                ];
            }
            if (str_contains($sql, 'SELECT taxonomy, COUNT(*) AS entities')) {
                $this->events[] = 'query:taxonomy-gaps';
                return [
                    ['taxonomy' => 'category', 'entities' => '1'],
                    ['taxonomy' => 'genre', 'entities' => '3'],
                ];
            }
            if (str_contains($sql, 'SELECT option_name, option_value')) {
                $this->events[] = 'query:options';
                return [
                    ['option_name' => 'outside', 'option_value' => 'ignored'],
                    ['option_name' => 'owned_known', 'option_value' => 'classified'],
                    ['option_name' => 'owned_unknown', 'option_value' => serialize(['future' => true])],
                    ['option_name' => 'widget_future', 'option_value' => serialize([
                        2 => ['title' => 'one'],
                        7 => ['title' => 'two'],
                        '_multiwidget' => 1,
                    ])],
                    ['option_name' => 'widget_known', 'option_value' => serialize([
                        4 => ['title' => 'known'],
                        '_multiwidget' => 1,
                    ])],
                ];
            }
            if (str_contains($sql, 'SELECT COUNT(*) AS row_count')
                && str_contains($sql, 'FROM wp_posts')) {
                $this->events[] = 'query:post-size';
                return [['row_count' => '1', 'total_bytes' => '64', 'max_row_bytes' => '64']];
            }
            if (str_contains($sql, 'SELECT ID, post_author')) {
                $this->events[] = 'query:posts';
                return [(object) [
                    'ID' => '10',
                    'post_author' => '0',
                    'post_date' => '2026-01-01 00:00:00',
                    'post_date_gmt' => '2026-01-01 00:00:00',
                    'post_content' => '',
                    'post_title' => 'Fixture',
                    'post_excerpt' => '',
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'post_password' => '',
                    'post_name' => 'fixture',
                    'post_modified' => '2026-01-01 00:00:00',
                    'post_modified_gmt' => '2026-01-01 00:00:00',
                    'post_parent' => '0',
                    'menu_order' => '0',
                    'post_type' => 'page',
                    'post_mime_type' => '',
                ]];
            }
            if (str_contains($sql, 'FROM `wp_postmeta`')) {
                $postId = (int) ($args[0] ?? 0);
                $preflight = str_contains($sql, 'OCTET_LENGTH(meta_key)');
                $hashWitness = str_contains($sql, 'SHA2(meta_key, 256)');
                $this->events[] = "query:post-meta:$postId:"
                    . ($preflight ? 'size' : ($hashWitness ? 'hash' : 'value'));
                $rows = $postId === 10
                    ? [
                        ['meta_id' => '1', 'meta_key' => '_wp_attached_file', 'meta_value' => 'ignored.jpg'],
                        ['meta_id' => '2', 'meta_key' => 'known_post', 'meta_value' => 'classified'],
                        ['meta_id' => '3', 'meta_key' => 'shared_unknown', 'meta_value' => 'page value'],
                    ]
                    : [
                        ['meta_id' => '4', 'meta_key' => 'shared_unknown', 'meta_value' => 'menu value'],
                    ];
                if ($preflight) {
                    return array_map(static fn(array $row): array => [
                        'meta_id' => $row['meta_id'],
                        'meta_key_bytes' => (string) strlen($row['meta_key']),
                        'meta_value_bytes' => (string) strlen($row['meta_value']),
                    ], $rows);
                }
                if ($hashWitness) {
                    return array_map(static fn(array $row): array => [
                        'meta_id' => $row['meta_id'],
                        'meta_key_sha256' => hash('sha256', $row['meta_key']),
                        'meta_value_sha256' => hash('sha256', $row['meta_value']),
                    ], $rows);
                }
                return $rows;
            }
            if (str_contains($sql, 'SELECT COUNT(*) AS row_count')
                && str_contains($sql, 'FROM wp_terms t')) {
                $this->events[] = 'query:term-size';
                return [['row_count' => '1', 'total_bytes' => '48', 'max_row_bytes' => '48']];
            }
            if (str_contains($sql, 'SELECT t.term_id, t.name')) {
                $this->events[] = 'query:terms';
                return [(object) [
                    'term_id' => '20',
                    'name' => 'Fixture category',
                    'slug' => 'fixture-category',
                    'term_group' => '0',
                    'term_taxonomy_id' => '21',
                    'taxonomy' => 'category',
                    'description' => '',
                    'parent' => '0',
                ]];
            }
            if (str_contains($sql, 'FROM `wp_termmeta`')) {
                $preflight = str_contains($sql, 'OCTET_LENGTH(meta_key)');
                $hashWitness = str_contains($sql, 'SHA2(meta_key, 256)');
                $this->events[] = 'query:term-meta:20:'
                    . ($preflight ? 'size' : ($hashWitness ? 'hash' : 'value'));
                $rows = [
                    ['meta_id' => '5', 'meta_key' => 'known_term', 'meta_value' => 'classified'],
                    ['meta_id' => '6', 'meta_key' => 'unknown_term', 'meta_value' => serialize(['future' => true])],
                ];
                if ($preflight) {
                    return array_map(static fn(array $row): array => [
                        'meta_id' => $row['meta_id'],
                        'meta_key_bytes' => (string) strlen($row['meta_key']),
                        'meta_value_bytes' => (string) strlen($row['meta_value']),
                    ], $rows);
                }
                if ($hashWitness) {
                    return array_map(static fn(array $row): array => [
                        'meta_id' => $row['meta_id'],
                        'meta_key_sha256' => hash('sha256', $row['meta_key']),
                        'meta_value_sha256' => hash('sha256', $row['meta_value']),
                    ], $rows);
                }
                return $rows;
            }
            throw new \RuntimeException('unexpected gate-scanner query: ' . $sql);
        }

        public function get_col(string $sql): array {
            if (!str_contains($sql, "tt.taxonomy = 'nav_menu'")) {
                throw new \RuntimeException('unexpected gate-scanner column query: ' . $sql);
            }
            $this->events[] = 'query:menu-items';
            return [30];
        }
    }

    $scannerSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/CaptureGateScanner.php');
    $captureSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/Capture.php');

    check(class_exists(CaptureGateScanner::class, false), 'gate scanner loads as a direct boundary');
    check(!class_exists(Capture::class, false), 'gate scanner does not load Capture');
    check(str_contains($scannerSource, 'public function scan(): array'), 'gate scanner owns one focused read-only entry point');
    check(!str_contains($scannerSource, 'Ledger::'), 'gate scanner has no ledger mutation or repair path');
    check(!str_contains($scannerSource, 'Publish::'), 'gate scanner has no publication path');
    check(
        str_contains($captureSource, 'return (new CaptureGateScanner($policy, $observationReadCheckpoint))->scan();'),
        'Capture retains a thin public gate-scan compatibility wrapper'
    );

    $policy = new Policy();
    $policy->site = ['policy' => [
        'post_types' => ['page'],
        'taxonomies' => ['category'],
    ]];
    $policy->manifests = [[
        'name' => 'gate-scanner-fixture',
        'post_types' => ['book' => ['class' => 'authored']],
        'taxonomies' => ['genre' => ['class' => 'authored']],
        'option_namespaces' => [['match' => '^owned_']],
        'options' => ['owned_known' => ['class' => 'authored']],
        'widgets' => ['known' => ['settings' => ['title' => ['class' => 'authored']]]],
        'post_meta' => ['known_post' => ['class' => 'authored']],
        'term_meta' => ['known_term' => ['class' => 'authored']],
    ]];
    $wpdb = new CaptureGateScannerWpdbFixture();
    $GLOBALS['wpdb'] = $wpdb;
    $scanner = new CaptureGateScanner($policy, static function () use ($wpdb): void {
        $wpdb->events[] = 'checkpoint';
    });
    $result = $scanner->scan();

    check($result === [
        'scope' => [
            'post_type:book' => ['entities' => 2],
            'taxonomy:genre' => ['entities' => 3],
        ],
        'options' => [
            'owned_unknown' => [
                'entities' => 1,
                'owner_candidates' => ['gate-scanner-fixture'],
                'value_shapes' => ['array'],
                'reason' => 'owner namespace matched but no exact or pattern classification exists',
            ],
        ],
        'widgets' => [
            'future' => [
                'entities' => 2,
                'value_shapes' => ['multi-instance array'],
                'reason' => 'live widget instances exist but no pinned manifest declares this widget type',
            ],
        ],
        'post_meta' => [
            'shared_unknown' => [
                'entities' => 2,
                'post_types' => ['page', 'nav_menu_item'],
            ],
        ],
        'term_meta' => [
            'unknown_term' => [
                'entities' => 1,
                'taxonomies' => ['category'],
                'value_shapes' => ['array'],
                'reason' => 'unclassified term meta on an in-scope taxonomy',
            ],
        ],
        'user_meta' => [],
    ], 'scan aggregates exact scope, option, widget, post, term, and menu evidence');
    check($wpdb->events === [
        'query:post-gaps', 'checkpoint',
        'query:taxonomy-gaps', 'checkpoint',
        'query:options', 'checkpoint',
        'query:post-size', 'checkpoint',
        'query:posts', 'checkpoint',
        'query:post-meta:10:size', 'checkpoint', 'query:post-meta:10:hash', 'checkpoint',
        'query:post-meta:10:value', 'checkpoint',
        'query:term-size', 'checkpoint',
        'query:terms', 'checkpoint',
        'query:term-meta:20:size', 'checkpoint', 'query:term-meta:20:hash', 'checkpoint',
        'query:term-meta:20:value', 'checkpoint',
        'query:menu-items', 'checkpoint',
        'query:post-meta:30:size', 'checkpoint', 'query:post-meta:30:hash', 'checkpoint',
        'query:post-meta:30:value', 'checkpoint',
    ], 'every scanner query preserves its immediate read checkpoint and frozen order');

    echo "REGRESS_CAPTURE_GATE_SCANNER PASSED\n";
}
