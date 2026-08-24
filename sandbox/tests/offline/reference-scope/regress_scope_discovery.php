<?php
/**
 * Direct offline certification for DUO-3349's extracted live scope boundary.
 *
 * The fixture executes ScopeDiscovery without loading Capture, Policy, Tokens,
 * or WordPress. It pins query order, post/term selection, authored-looking gap
 * detection, taxonomy relationship ownership, warning/refusal behavior, and
 * the immediate read-error checkpoint used by bounded adapter observation.
 */
declare(strict_types=1);

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    function esc_sql(string $value): string {
        return str_replace("'", "''", $value);
    }
}

namespace Duo {
    /** @var string[] */
    $scopeDiscoveryPublicPostTypes = ['page', 'book', 'runtime_type'];
    // Registered by a plugin (`_builtin` false) but public:false — the
    // WPForms `wpforms` shape (T6 walk S1). Any plugin-registered type is a
    // gap candidate until a rule names it; core's own non-public types are
    // builtin and are not.
    $scopeDiscoveryPluginPostTypes = ['form_store'];
    $scopeDiscoveryPluginTaxonomies = ['form_group'];
    /** @var string[] */
    $scopeDiscoveryPublicTaxonomies = ['category', 'genre', 'runtime_tax'];
    /** @var array<string,object> */
    $scopeDiscoveryTaxonomies = [
        'category' => (object) ['object_type' => ['page']],
        'term_link' => (object) ['object_type' => ['opaque_term_owner']],
    ];

    /** @return string[] */
    function get_post_types(array $args, string $_output): array {
        return array_key_exists('_builtin', $args) && $args['_builtin'] === false
            ? $GLOBALS['scopeDiscoveryPluginPostTypes']
            : $GLOBALS['scopeDiscoveryPublicPostTypes'];
    }

    /** @return string[] */
    function get_taxonomies(array $args, string $_output): array {
        return array_key_exists('_builtin', $args) && $args['_builtin'] === false
            ? $GLOBALS['scopeDiscoveryPluginTaxonomies']
            : $GLOBALS['scopeDiscoveryPublicTaxonomies'];
    }

    function get_taxonomy(string $taxonomy): object|false {
        return $GLOBALS['scopeDiscoveryTaxonomies'][$taxonomy] ?? false;
    }

    require_once __DIR__ . '/../../../../agent/src/Policy/ScopeDiscovery.php';

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };
    $throws = static function (callable $run, string $fragment, string $message) use ($check): void {
        try {
            $run();
            $check(false, $message);
        } catch (\Throwable $failure) {
            $check(str_contains($failure->getMessage(), $fragment), $message);
        }
    };

    $check(class_exists(ScopeDiscovery::class, false), 'ScopeDiscovery loads as a direct offline boundary');
    foreach (['Duo\\Capture', 'Duo\\Policy', 'Duo\\Tokens', 'Duo\\ScopeClosure', 'Duo\\ScopeContract'] as $runtimeClass) {
        $check(!class_exists($runtimeClass, false), "ScopeDiscovery does not load $runtimeClass");
    }

    final class ScopeDiscoveryPolicyFixture {
        /** @var list<array{string,string[]}> */
        public array $keyspaceCalls = [];

        /** @return string[] */
        public function post_types(): array {
            return ['page', 'attachment'];
        }

        /** @return string[] */
        public function declared_post_types(): array {
            return ['page', 'book', 'runtime_type'];
        }

        public function post_type_rule_details(string $name): array {
            return match ($name) {
                'runtime_type' => ['rule' => ['class' => 'runtime']],
                default => [],
            };
        }

        /** @return string[] */
        public function taxonomies(): array {
            return ['category', 'term_link', 'dynamic_link', 'missing_link'];
        }

        /** @return string[] */
        public function declared_taxonomies(): array {
            return ['category', 'genre', 'runtime_tax'];
        }

        public function taxonomy_rule_details(string $name): array {
            return match ($name) {
                'runtime_tax' => ['rule' => ['class' => 'runtime']],
                default => [],
            };
        }

        /** @return string[]|null */
        public function pattern_object_type(string $taxonomy): ?array {
            return $taxonomy === 'dynamic_link' ? ['page'] : null;
        }

        /**
         * The manifest-declared registration facts ScopeDiscovery consults
         * when get_taxonomy() cannot answer: an exact `taxonomies.<tax>.object_type`
         * declaration first (`exact_link`, round-3 T5 — the isolated control
         * bootstrap case), then the pattern fallback (`dynamic_link`).
         *
         * @return string[]|null
         */
        public function declared_object_type(string $taxonomy): ?array {
            return $taxonomy === 'exact_link' ? ['page'] : $this->pattern_object_type($taxonomy);
        }

        /** @param string[] $objectTypes */
        public function taxonomy_object_keyspace(string $taxonomy, array $objectTypes): string {
            $this->keyspaceCalls[] = [$taxonomy, $objectTypes];
            return $taxonomy === 'term_link' ? 'term' : 'post';
        }
    }

    final class ScopeDiscoveryWpdbFixture {
        public string $posts = 'wp_posts';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $last_error = '';
        /** @var string[] */
        public array $queries = [];
        /** @var string[] */
        public array $events = [];
        /** @var array<string,mixed> */
        public array $responses = [];
        /** @var array<string,string> */
        public array $errors = [];

        public function get_results(string $sql, mixed $_mode = null): mixed {
            $kind = match (true) {
                str_contains($sql, 'SELECT post_type, COUNT(*) AS entities') => 'post-gaps',
                str_contains($sql, 'SELECT taxonomy, COUNT(*) AS entities') => 'taxonomy-gaps',
                str_contains($sql, 'SELECT COUNT(*) AS row_count') && str_contains($sql, 'FROM wp_posts') => 'post-preflight',
                str_contains($sql, 'FROM wp_posts') => 'posts',
                str_contains($sql, 'SELECT COUNT(*) AS row_count') && str_contains($sql, 'FROM wp_terms') => 'term-preflight',
                str_contains($sql, 'SELECT t.term_id') => 'terms',
                default => '',
            };
            if ($kind === '') {
                throw new \RuntimeException('unexpected ScopeDiscovery query: ' . $sql);
            }
            $this->queries[] = $kind;
            if (in_array($kind, ['posts', 'terms'], true)) {
                $this->queries[] = $sql;
            }
            $this->events[] = "query:$kind";
            $this->last_error = $this->errors[$kind] ?? '';
            if (array_key_exists($kind, $this->responses)) {
                return $this->responses[$kind];
            }
            if (str_contains($sql, 'SELECT post_type, COUNT(*) AS entities')) {
                return [
                    ['post_type' => 'page', 'entities' => '2'],
                    ['post_type' => 'book', 'entities' => '3'],
                    ['post_type' => 'runtime_type', 'entities' => '4'],
                    ['post_type' => 'not_a_candidate', 'entities' => '5'],
                    ['post_type' => 'form_store', 'entities' => '2'],
                ];
            }
            if (str_contains($sql, 'SELECT taxonomy, COUNT(*) AS entities')) {
                return [
                    ['taxonomy' => 'category', 'entities' => '2'],
                    ['taxonomy' => 'genre', 'entities' => '6'],
                    ['taxonomy' => 'runtime_tax', 'entities' => '7'],
                    ['taxonomy' => 'not_a_candidate', 'entities' => '8'],
                    ['taxonomy' => 'form_group', 'entities' => '1'],
                ];
            }
            if ($kind === 'post-preflight') {
                return [['row_count' => '2', 'total_bytes' => '8192', 'max_row_bytes' => '4096']];
            }
            if (str_contains($sql, 'SELECT * FROM')) {
                return [];
            }
            if ($kind === 'posts') {
                $row = static fn(int $id): object => (object) [
                    'ID' => (string) $id,
                    'post_author' => '1',
                    'post_date' => '2026-08-24 00:00:00',
                    'post_date_gmt' => '2026-08-24 00:00:00',
                    'post_content' => '',
                    'post_title' => "Post $id",
                    'post_excerpt' => '',
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'post_password' => '',
                    'post_name' => "post-$id",
                    'post_modified' => '2026-08-24 00:00:00',
                    'post_modified_gmt' => '2026-08-24 00:00:00',
                    'post_parent' => '0',
                    'menu_order' => '0',
                    'post_type' => 'page',
                    'post_mime_type' => '',
                ];
                return [$row(2), $row(9)];
            }
            if ($kind === 'term-preflight') {
                return [['row_count' => '2', 'total_bytes' => '8192', 'max_row_bytes' => '4096']];
            }
            if (str_contains($sql, 'SELECT t.term_id')) {
                $withTermGroup = str_contains($sql, 't.term_group');
                return [
                    (object) array_merge(
                        [
                            'term_id' => 4,
                            'name' => 'General',
                            'slug' => 'general',
                            'term_taxonomy_id' => 14,
                            'taxonomy' => 'category',
                            'description' => '',
                            'parent' => '0',
                        ],
                        $withTermGroup ? ['term_group' => '0'] : []
                    ),
                    (object) array_merge(
                        [
                            'term_id' => 11,
                            'name' => 'Linked',
                            'slug' => 'linked',
                            'term_taxonomy_id' => 21,
                            'taxonomy' => 'term_link',
                            'description' => '',
                            'parent' => '4',
                        ],
                        $withTermGroup ? ['term_group' => '37'] : []
                    ),
                ];
            }
            throw new \RuntimeException('unexpected ScopeDiscovery query: ' . $sql);
        }
    }

    $policy = new ScopeDiscoveryPolicyFixture();
    $wpdb = new ScopeDiscoveryWpdbFixture();
    $GLOBALS['wpdb'] = $wpdb;
    $checkpoints = 0;
    $warnings = [];
    $discovery = new ScopeDiscovery(
        $policy,
        static function () use (&$checkpoints, $wpdb): void {
            $checkpoints++;
            $wpdb->events[] = 'checkpoint';
        },
        static function (string $warning) use (&$warnings): void {
            $warnings[] = $warning;
        }
    );

    $scope = $discovery->discover();
    $check(
        $scope['gaps'] === [
            'post_type:book' => ['entities' => 3],
            'post_type:form_store' => ['entities' => 2],
            'taxonomy:form_group' => ['entities' => 1],
            'taxonomy:genre' => ['entities' => 6],
        ],
        'scope gaps retain absent authored dispositions — public OR plugin-registered types — and exclude scoped, explicit-runtime, and unrelated rows'
    );
    $check(
        array_map(static fn(object $row): int => (int) $row->ID, $scope['posts']) === [2, 9],
        'post discovery returns the database order unchanged'
    );
    $check(
        array_map(static fn(object $row): int => (int) $row->term_id, $scope['terms']) === [4, 11],
        'term discovery returns the database order unchanged'
    );
    $check(
        array_map(static fn(object $row): int => (int) $row->term_group, $scope['terms']) === [0, 37],
        'term discovery carries native term_group values into the canonical capture path'
    );
    $check(
        $scope['by_post_type'] === ['page' => ['category', 'dynamic_link'], 'attachment' => []],
        'registered and manifest-fallback post taxonomies retain declaration order'
    );
    $check($scope['term_object'] === ['term_link'], 'term-keyspace taxonomy is separated from post relationships');
    $check(
        array_values(array_filter($wpdb->queries, static fn(string $row): bool => !str_starts_with($row, 'SELECT ')))
            === ['post-gaps', 'taxonomy-gaps', 'post-preflight', 'posts', 'term-preflight', 'terms'],
        'complete discovery preserves the frozen gap, post, term read order'
    );
    $check(
        $wpdb->events === [
            'query:post-gaps', 'checkpoint',
            'query:taxonomy-gaps', 'checkpoint',
            'query:post-preflight', 'checkpoint',
            'query:posts', 'checkpoint',
            'query:term-preflight', 'checkpoint',
            'query:terms', 'checkpoint',
        ],
        'every live SQL read is immediately checkpointed exactly once'
    );
    $check($checkpoints === 6, 'complete discovery runs exactly six read checkpoints including compact size preflights');
    $postSql = $wpdb->queries[array_search('posts', $wpdb->queries, true) + 1] ?? '';
    $termSql = $wpdb->queries[array_search('terms', $wpdb->queries, true) + 1] ?? '';
    $check(
        str_contains($postSql, "post_type IN ('page')")
            && str_contains($postSql, "post_type = 'attachment' AND post_status = 'inherit'")
            && str_contains($postSql, 'ORDER BY ID ASC')
            && !str_contains($postSql, 'post_content_filtered')
            && !str_contains($postSql, 'to_ping')
            && !str_contains($postSql, 'pinged')
            && !str_contains($postSql, 'guid'),
        'post query transfers only downstream-consumed fields, excluding otherwise-unbounded legacy text columns'
    );
    $check(
        str_contains($termSql, "WHERE tt.taxonomy IN ('category','term_link','dynamic_link','missing_link')")
            && str_contains($termSql, 't.term_group')
            && str_contains($termSql, 'ORDER BY t.term_id ASC'),
        'term query preserves taxonomy ordering fields, the policy roster and deterministic entity ordering'
    );
    $check(
        $warnings === [
            "taxonomy 'missing_link' is in policy scope but not registered on this environment"
            . " (plugin inactive?) — cannot determine which object type its relationships"
            . ' belong to, so its relationships are skipped for every post and term',
        ],
        'ordinary capture reports an unregistered exact taxonomy and omits only its unknown relationships'
    );

    $strictWarnings = [];
    $strict = new ScopeDiscovery(
        $policy,
        null,
        static function (string $warning) use (&$strictWarnings): void {
            $strictWarnings[] = $warning;
        }
    );
    $throws(
        static fn() => $strict->taxonomyOwnership(['missing_link'], ['page'], true),
        'refresh export refused',
        'strict read-only discovery refuses an unregistered taxonomy instead of returning partial production truth'
    );
    $check($strictWarnings === [], 'strict read-only refusal cannot degrade into a warning');
    $throws(
        static fn() => $strict->taxonomyOwnership(['missing_link'], ['page'], true),
        'taxonomies.<name>.object_type or taxonomy_patterns object_type declaration',
        'the strict refusal names both manifest declarations that would have resolved it'
    );
    // Round-3 T5: an unregistered EXACT taxonomy whose manifest declares its
    // registration facts (`taxonomies.<tax>.object_type`) resolves in strict
    // read-only mode exactly like a registered one — this is what lets
    // refresh-export attribute WooCommerce's product_cat under the isolated
    // control bootstrap, where no plugin is loaded.
    $exact = $strict->taxonomyOwnership(['exact_link', 'dynamic_link'], ['page'], true);
    $check(
        $exact['by_post_type'] === ['page' => ['exact_link', 'dynamic_link']] && $exact['term_object'] === [],
        'strict read-only discovery attributes an unregistered exact taxonomy through its manifest object_type declaration, then the pattern fallback'
    );
    $check($strictWarnings === [], 'a declared exact taxonomy produces no warning in strict mode');

    $wpdb->queries = [];
    $checkpointFailure = new ScopeDiscovery(
        $policy,
        static function (): void {
            throw new \RuntimeException('fixture read checkpoint');
        }
    );
    $throws(
        static fn() => $checkpointFailure->gaps(),
        'fixture read checkpoint',
        'a read checkpoint failure aborts discovery immediately'
    );
    $check($wpdb->queries === ['post-gaps'], 'a failed post-count checkpoint prevents every later scope read');

    $wpdb->queries = [];
    $gapGate = new ScopeDiscovery($policy);
    $throws(
        static fn() => $gapGate->discover(false, static function (array $gaps): void {
            if ($gaps !== []) {
                throw new \RuntimeException('fixture scope-gap gate');
            }
        }),
        'fixture scope-gap gate',
        'the injected scope-gap gate runs before entity discovery'
    );
    $check(
        $wpdb->queries === ['post-gaps', 'taxonomy-gaps'],
        'a scope-gap refusal prevents post, term, taxonomy-ownership, and warning work'
    );

    foreach ([false, null, ['not-a-list' => true]] as $badResult) {
        $wpdb = new ScopeDiscoveryWpdbFixture();
        $wpdb->responses['post-gaps'] = $badResult;
        $GLOBALS['wpdb'] = $wpdb;
        $throws(
            static fn() => (new ScopeDiscovery($policy))->gaps(),
            'post-type gap discovery read failed',
            'post-type scope discovery refuses false, null, and non-list driver results'
        );
    }
    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->errors['post-gaps'] = 'fixture read error';
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->gaps(),
        'post-type gap discovery read failed',
        'post-type scope discovery refuses a value returned with a database error'
    );
    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->errors['post-gaps'] = 'fixture read error masked by checkpoint';
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery(
            $policy,
            static function () use ($wpdb): void {
                $wpdb->last_error = '';
            }
        ))->gaps(),
        'post-type gap discovery read failed',
        'scope discovery snapshots the query error before a checkpoint can clear or overwrite last_error'
    );

    $savedPublicPostTypes = $GLOBALS['scopeDiscoveryPublicPostTypes'];
    $GLOBALS['scopeDiscoveryPublicPostTypes'] = ['page' => 'page'];
    $wpdb = new ScopeDiscoveryWpdbFixture();
    $GLOBALS['wpdb'] = $wpdb;
    $nativeMapGaps = (new ScopeDiscovery($policy))->gaps();
    $check(
        isset($nativeMapGaps['post_type:form_store']),
        'scope discovery accepts WordPress names-output name=>name maps after exact key/value validation'
    );
    $GLOBALS['scopeDiscoveryPublicPostTypes'] = ['page' => 'Page'];
    $throws(
        static fn() => (new ScopeDiscovery($policy))->gaps(),
        'malformed native name map',
        'scope discovery refuses an aliased WordPress names-output map before candidate expansion'
    );
    $GLOBALS['scopeDiscoveryPublicPostTypes'] = array_fill(0, 4097, 'page');
    $throws(
        static fn() => (new ScopeDiscovery($policy))->gaps(),
        'bounded type limit',
        'scope discovery rejects a saturated runtime post-type roster before candidate expansion'
    );
    $GLOBALS['scopeDiscoveryPublicPostTypes'] = $savedPublicPostTypes;

    $savedPluginTaxonomies = $GLOBALS['scopeDiscoveryPluginTaxonomies'];
    $GLOBALS['scopeDiscoveryPluginTaxonomies'] = ["bad\0taxonomy"];
    $throws(
        static fn() => (new ScopeDiscovery($policy))->gaps(),
        'plugin taxonomy registry contains a malformed',
        'scope discovery rejects hostile runtime taxonomy names before candidate expansion'
    );
    $GLOBALS['scopeDiscoveryPluginTaxonomies'] = $savedPluginTaxonomies;

    foreach ([
        [['post_type' => 'bad type', 'entities' => '1']],
        [['post_type' => 'book', 'entities' => '01']],
        [
            ['post_type' => 'book', 'entities' => '1'],
            ['post_type' => 'book', 'entities' => '1'],
        ],
    ] as $badRows) {
        $wpdb = new ScopeDiscoveryWpdbFixture();
        $wpdb->responses['post-gaps'] = $badRows;
        $GLOBALS['wpdb'] = $wpdb;
        $throws(
            static fn() => (new ScopeDiscovery($policy))->gaps(),
            'malformed/duplicate row',
            'post-type scope discovery refuses malformed identities, loose counts, and duplicates'
        );
    }

    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->responses['post-preflight'] = [[
        'row_count' => '1000001',
        'total_bytes' => '8192',
        'max_row_bytes' => '4096',
    ]];
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->posts(),
        'bounded row/byte frontier',
        'post scope discovery refuses a saturated compact row-count preflight before payload transfer'
    );
    $check(!in_array('posts', $wpdb->queries, true), 'post saturation refuses before the full-value query');

    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->responses['post-preflight'] = [[
        'row_count' => '100000',
        'total_bytes' => '8192',
        'max_row_bytes' => '4096',
    ]];
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->posts(),
        'bounded PHP allocation frontier',
        'post discovery accounts for materialized wpdb object overhead and refuses before payload transfer'
    );
    $check(!in_array('posts', $wpdb->queries, true), 'allocation saturation refuses before the full-value query');

    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->responses['post-preflight'] = [[
        'row_count' => '2',
        'total_bytes' => '268435457',
        'max_row_bytes' => '4096',
    ]];
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->posts(),
        'bounded row/byte frontier',
        'post scope discovery refuses aggregate payload bytes before the full-value query'
    );

    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->responses['posts'] = [(object) ['ID' => '2'], (object) ['ID' => '2']];
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->posts(),
        'malformed/duplicate row',
        'post scope discovery refuses duplicate canonical identities after its bounded preflight'
    );

    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->responses['term-preflight'] = [[
        'row_count' => '2',
        'total_bytes' => '134217729',
        'max_row_bytes' => '4096',
    ]];
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->terms(),
        'bounded row/byte frontier',
        'term scope discovery refuses aggregate payload bytes before the full-value query'
    );
    $check(!in_array('terms', $wpdb->queries, true), 'term saturation refuses before the full-value query');

    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->responses['terms'] = [
        (object) [
            'term_id' => '4',
            'name' => 'General',
            'slug' => 'general',
            'term_group' => '0',
            'term_taxonomy_id' => '14',
            'taxonomy' => 'category',
            'description' => '',
            'parent' => '01',
        ],
        (object) [
            'term_id' => '11',
            'name' => 'Linked',
            'slug' => 'linked',
            'term_group' => '37',
            'term_taxonomy_id' => '21',
            'taxonomy' => 'term_link',
            'description' => '',
            'parent' => '4',
        ],
    ];
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->terms(),
        'malformed/duplicate row',
        'term scope discovery refuses noncanonical driver identities and parent values'
    );

    $wpdb = new ScopeDiscoveryWpdbFixture();
    $wpdb->responses['terms'] = false;
    $GLOBALS['wpdb'] = $wpdb;
    $throws(
        static fn() => (new ScopeDiscovery($policy))->terms(),
        'term discovery read failed',
        'term scope discovery refuses a failed payload read instead of publishing an empty roster'
    );

    $captureSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/Capture.php');
    $workflowSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/CapturePublicationWorkflow.php');
    $candidateSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/CaptureCandidateBuilder.php');
    $check(is_string($captureSource)
        && str_contains($captureSource, "require_once __DIR__ . '/CapturePublicationWorkflow.php';")
        && is_string($workflowSource)
        && str_contains($workflowSource, "require_once __DIR__ . '/CaptureCandidateBuilder.php';")
        && str_contains($workflowSource, '$c = new CaptureCandidateBuilder('),
        'Capture explicitly delegates entity assembly to the candidate builder');
    $check(is_string($candidateSource)
        && str_contains($candidateSource, "require_once __DIR__ . '/../Policy/ScopeDiscovery.php';")
        && str_contains($candidateSource, '$this->scopeDiscovery = new ScopeDiscovery('),
        'candidate builder explicitly owns one shared ScopeDiscovery collaborator');
    $check(is_string($candidateSource)
        && str_contains($candidateSource, '$scope = $this->scopeDiscovery->discover(')
        && str_contains($candidateSource, '$this->safetyGates->assertScopeGaps($gaps);')
        && str_contains($candidateSource, '$this->taxonomiesByPostType = $scope[\'by_post_type\'];')
        && str_contains($candidateSource, '$this->termObjectTaxonomies = $scope[\'term_object\'];'),
        'the full candidate build consumes the complete ScopeDiscovery result');

    if ($failures !== []) {
        fwrite(STDERR, "\n" . count($failures) . " scope-discovery assertion(s) failed\n");
        exit(1);
    }
    echo "\nscope-discovery regression passed\n";
}
