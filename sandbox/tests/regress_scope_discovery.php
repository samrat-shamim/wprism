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
    /** @var string[] */
    $scopeDiscoveryPublicTaxonomies = ['category', 'genre', 'runtime_tax'];
    /** @var array<string,object> */
    $scopeDiscoveryTaxonomies = [
        'category' => (object) ['object_type' => ['page']],
        'term_link' => (object) ['object_type' => ['opaque_term_owner']],
    ];

    /** @return string[] */
    function get_post_types(array $_args, string $_output): array {
        return $GLOBALS['scopeDiscoveryPublicPostTypes'];
    }

    /** @return string[] */
    function get_taxonomies(array $_args, string $_output): array {
        return $GLOBALS['scopeDiscoveryPublicTaxonomies'];
    }

    function get_taxonomy(string $taxonomy): object|false {
        return $GLOBALS['scopeDiscoveryTaxonomies'][$taxonomy] ?? false;
    }

    require_once __DIR__ . '/../../agent/src/ScopeDiscovery.php';

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
        /** @var string[] */
        public array $queries = [];
        /** @var string[] */
        public array $events = [];

        public function get_results(string $sql, mixed $_mode = null): array {
            if (str_contains($sql, 'SELECT post_type, COUNT(*) AS entities')) {
                $this->queries[] = 'post-gaps';
                $this->events[] = 'query:post-gaps';
                return [
                    ['post_type' => 'page', 'entities' => '2'],
                    ['post_type' => 'book', 'entities' => '3'],
                    ['post_type' => 'runtime_type', 'entities' => '4'],
                    ['post_type' => 'not_a_candidate', 'entities' => '5'],
                ];
            }
            if (str_contains($sql, 'SELECT taxonomy, COUNT(*) AS entities')) {
                $this->queries[] = 'taxonomy-gaps';
                $this->events[] = 'query:taxonomy-gaps';
                return [
                    ['taxonomy' => 'category', 'entities' => '2'],
                    ['taxonomy' => 'genre', 'entities' => '6'],
                    ['taxonomy' => 'runtime_tax', 'entities' => '7'],
                    ['taxonomy' => 'not_a_candidate', 'entities' => '8'],
                ];
            }
            if (str_contains($sql, 'SELECT * FROM')) {
                $this->queries[] = 'posts';
                $this->queries[] = $sql;
                $this->events[] = 'query:posts';
                return [(object) ['ID' => 2], (object) ['ID' => 9]];
            }
            if (str_contains($sql, 'SELECT t.term_id')) {
                $this->queries[] = 'terms';
                $this->queries[] = $sql;
                $this->events[] = 'query:terms';
                return [
                    (object) ['term_id' => 4, 'taxonomy' => 'category'],
                    (object) ['term_id' => 11, 'taxonomy' => 'term_link'],
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
            'taxonomy:genre' => ['entities' => 6],
        ],
        'scope gaps retain absent authored dispositions and exclude scoped, explicit-runtime, and unrelated rows'
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
        $scope['by_post_type'] === ['page' => ['category', 'dynamic_link'], 'attachment' => []],
        'registered and manifest-fallback post taxonomies retain declaration order'
    );
    $check($scope['term_object'] === ['term_link'], 'term-keyspace taxonomy is separated from post relationships');
    $check(
        array_values(array_filter($wpdb->queries, static fn(string $row): bool => !str_starts_with($row, 'SELECT ')))
            === ['post-gaps', 'taxonomy-gaps', 'posts', 'terms'],
        'complete discovery preserves the frozen gap, post, term read order'
    );
    $check(
        $wpdb->events === [
            'query:post-gaps', 'checkpoint',
            'query:taxonomy-gaps', 'checkpoint',
            'query:posts', 'checkpoint',
            'query:terms', 'checkpoint',
        ],
        'every live SQL read is immediately checkpointed exactly once'
    );
    $check($checkpoints === 4, 'complete discovery runs exactly four read checkpoints');
    $postSql = $wpdb->queries[array_search('posts', $wpdb->queries, true) + 1] ?? '';
    $termSql = $wpdb->queries[array_search('terms', $wpdb->queries, true) + 1] ?? '';
    $check(
        str_contains($postSql, "post_type IN ('page')")
            && str_contains($postSql, "post_type = 'attachment' AND post_status = 'inherit'")
            && str_contains($postSql, 'ORDER BY ID ASC'),
        'post query preserves authored status/attachment semantics and deterministic ordering'
    );
    $check(
        str_contains($termSql, "WHERE tt.taxonomy IN ('category','term_link','dynamic_link','missing_link')")
            && str_contains($termSql, 'ORDER BY t.term_id ASC'),
        'term query preserves the policy taxonomy roster and deterministic ordering'
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

    $captureSource = file_get_contents(__DIR__ . '/../../agent/src/Capture.php');
    $workflowSource = file_get_contents(__DIR__ . '/../../agent/src/CapturePublicationWorkflow.php');
    $candidateSource = file_get_contents(__DIR__ . '/../../agent/src/CaptureCandidateBuilder.php');
    $check(is_string($captureSource)
        && str_contains($captureSource, "require_once __DIR__ . '/CapturePublicationWorkflow.php';")
        && is_string($workflowSource)
        && str_contains($workflowSource, "require_once __DIR__ . '/CaptureCandidateBuilder.php';")
        && str_contains($workflowSource, '$c = new CaptureCandidateBuilder('),
        'Capture explicitly delegates entity assembly to the candidate builder');
    $check(is_string($candidateSource)
        && str_contains($candidateSource, "require_once __DIR__ . '/ScopeDiscovery.php';")
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
