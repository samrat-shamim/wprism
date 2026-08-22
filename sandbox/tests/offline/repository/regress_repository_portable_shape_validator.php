<?php
/**
 * Offline characterization for RepositoryPortableShapeValidator (DUO-3348
 * slice 42). The validator proves declared canonical-reference field shapes
 * through the compiler's aggregate sink; it neither traverses files nor
 * proves graph target existence.
 */
declare(strict_types=1);

namespace Duo {
    final class Policy {
        /** @var array<string,array<string,mixed>> */
        public array $postRules = [];
        /** @var array<string,array<string,mixed>> */
        public array $termRules = [];
        /** @var array<string,array<string,mixed>> */
        public array $userRules = [];
        /** @var array<string,array<string,mixed>> */
        public array $optionRules = [];
        /** @var array<string,array<string,mixed>> */
        public array $canonicalOptionRules = [];
        /** @var array<string,array<string,mixed>> */
        public array $descriptionRules = [];
        /** @var array<string,array<string,mixed>> */
        public array $widgets = [];

        public function meta_rule_for_post(string $key, array $allMeta): ?array { return $this->postRules[$key] ?? null; }
        public function meta_rule_for_term(string $key, array $allMeta): ?array { return $this->termRules[$key] ?? null; }
        public function meta_rule_for_user(string $key, array $allMeta): ?array { return $this->userRules[$key] ?? null; }
        public function description_reference_rule(string $taxonomy): ?array { return $this->descriptionRules[$taxonomy] ?? null; }
        /** @return array{rule:?array,source:?string} */
        public function canonical_option_name_ref_details(string $name): array {
            return ['rule' => $this->canonicalOptionRules[$name] ?? null, 'source' => null];
        }
        /** @return array{rule:?array,source:?string} */
        public function option_rule_details_for_option(string $name, array $allOptions): array {
            return ['rule' => $this->optionRules[$name] ?? null, 'source' => null];
        }
        /** @return array<string,array<string,mixed>> */
        public function widget_types(): array { return $this->widgets; }
    }

    final class Snapshot {
        /** @var array<string,array<string,mixed>> */
        public static array $rows = [];
        /** @var array<string,array<string,mixed>> */
        public static array $meta = [];
        /** @return array<string,array<string,mixed>> */
        public static function row_tables(Policy $policy): array { return self::$rows; }
        /** @return array<string,array<string,mixed>> */
        public static function meta_tables(Policy $policy): array { return self::$meta; }
    }

    final class OptionState {
        /** @return array<string,mixed> */
        public static function classification_values(array $document): array { return $document; }
        /** @return array<string,array<string,mixed>> */
        public static function records(array $document): array { return (array) ($document['records'] ?? []); }
    }

    final class SidebarState { public const ENTITY_TYPE = 'sidebar'; }

    final class ReferenceRules {
        /** @return array<string,mixed> */
        public static function attached_meta_key(array $declaration, string $key): array {
            return (array) (($declaration['keys'] ?? [])[$key] ?? []);
        }
    }
}

namespace {
    $root = dirname(__DIR__, 4);
    $validatorPath = "$root/agent/src/Repository/RepositoryPortableShapeValidator.php";
    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $child = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\Canon::class, false) && class_exists(\\Duo\\Policy::class, false) && class_exists(\\Duo\\Snapshot::class, false) && class_exists(\\Duo\\OptionState::class, false) && class_exists(\\Duo\\SidebarState::class, false) && class_exists(\\Duo\\ReferenceRules::class, false) && class_exists(\\Duo\\JsonRefs::class, false) && class_exists(\\Duo\\RepositoryPortableShapeValidator::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !class_exists(\\Duo\\ReferenceGraph::class, false) && !class_exists(\\Duo\\RepositoryIdentityRegistry::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $validatorPath],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $childOut = is_resource($child) ? stream_get_contents($pipes[1]) : '';
    $childErr = is_resource($child) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($child)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $childExit = proc_close($child);
    } else {
        $childExit = 1;
    }
    $check(
        $childExit === 0 && $childOut === "loaded\n" && $childErr === '',
        'normal direct loading closes portability dependencies without loading compiler, graph, identity, or WordPress'
    );

    require_once $validatorPath;

    use Duo\Policy;
    use Duo\RepositoryPortableShapeValidator;
    use Duo\Snapshot;

    $uuid = static fn(int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
    $post = $uuid(1);
    $term = $uuid(2);
    $table = $uuid(3);
    $policy = new Policy();
    $policy->postRules = [
        'scalar' => ['ref' => 'post'],
        'structured_json' => ['json_refs' => [['path' => '$.image', 'kind' => 'post']]],
        'structured_keys' => ['key_refs' => ['kind' => 'term']],
        'list' => ['ref' => 'term[]'],
        'map_at_path' => ['key_refs' => ['path' => '$.map', 'kind' => 'term']],
    ];
    $policy->termRules = ['related' => ['ref' => 'term']];
    $policy->userRules = ['owner' => ['ref' => 'user']];
    $policy->descriptionRules = ['category' => ['json_refs' => [['path' => '$.term', 'kind' => 'term']]]];
    $policy->optionRules = [
        'plain' => ['ref' => 'post'],
        'unset' => ['ref' => 'post'],
        'subkeyed' => ['sub_keys' => ['author' => ['class' => 'authored', 'ref' => 'user']]],
    ];
    $policy->canonicalOptionRules = ["choice-{{post:$post}}" => ['ref' => 'post']];
    $policy->widgets = [
        'text' => ['settings' => [
            'selected' => ['ref' => 'post'],
            'content' => ['codec' => 'blocks'],
        ]],
    ];
    Snapshot::$rows = ['booking' => ['refs' => [['column' => 'post_id', 'kind' => 'post']]]];
    Snapshot::$meta = [
        'booking_meta' => [
            'attached_to' => ['table' => 'booking'],
            'keys' => ['related' => ['ref' => 'term']],
        ],
    ];

    $tree = [
        'post' => ['type' => 'post', 'path' => 'posts/page/portable.md', 'data' => [
            'author' => 12,
            'parent' => '12',
            'meta' => [
                'scalar' => '13',
                'structured_json' => ['image' => '14', 'empty' => '', 'zero' => 0, 'nested' => []],
                'structured_keys' => ['17' => true],
                'list' => ["{{term:$term}}", '15'],
                'map_at_path' => ['map' => ['16' => true]],
            ],
        ]],
        'menu' => ['type' => 'menu', 'path' => 'menus/main.json', 'data' => [
            'items' => [['type' => 'post_type', 'ref' => '16'], ['type' => 'taxonomy', 'ref' => "{{term:$term}}"]],
        ]],
        'term' => ['type' => 'term', 'path' => 'terms/category/portable.json', 'data' => [
            'taxonomy' => 'category', 'description' => ['term' => '17'], 'meta' => ['related' => '18'],
        ]],
        'options' => ['type' => 'options', 'path' => 'options/core.json', 'data' => ['records' => [
            'plain' => ['state' => 'present', 'value' => '19'],
            'subkeyed' => ['state' => 'present', 'value' => ['author' => '20']],
            "choice-{{post:$post}}" => ['state' => 'present', 'value' => '21'],
            'deleted' => ['state' => 'deleted', 'value' => 'not-read'],
        ]]],
        'user' => ['type' => 'user-meta', 'path' => 'user-meta/alice.json', 'data' => ['meta' => ['owner' => '22']]],
        'sidebar' => ['type' => 'sidebar', 'path' => 'sidebars/sidebar-1.json', 'data' => ['widgets' => [
            ['type' => 'text', 'settings' => ['selected' => '23', 'content' => ['not-a-string']]],
        ]]],
        'table' => ['type' => 'booking', 'path' => 'tables/booking/portable.json', 'data' => [
            'columns' => ['post_id' => '24'], 'meta' => ['related' => '25'],
        ]],
    ];
    $diagnostics = [];
    $validator = new RepositoryPortableShapeValidator(
        $policy,
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $validator->validate($tree);
    $byLocator = [];
    foreach ($diagnostics as $diagnostic) {
        $byLocator[$diagnostic['path'] . ':' . $diagnostic['locator']] = $diagnostic;
    }
    $expectedLocators = [
        'posts/page/portable.md:author',
        'posts/page/portable.md:parent',
        'posts/page/portable.md:meta.scalar',
        'posts/page/portable.md:meta.structured_json.image',
        'posts/page/portable.md:meta.structured_keys (key)',
        'posts/page/portable.md:meta.list[1]',
        'posts/page/portable.md:meta.map_at_path.map (key)',
        'menus/main.json:items[0].ref',
        'terms/category/portable.json:description.term',
        'terms/category/portable.json:meta.related',
        'options/core.json:options.plain',
        'options/core.json:options.subkeyed.author',
        "options/core.json:options.choice-{{post:$post}}",
        'user-meta/alice.json:meta.owner',
        'sidebars/sidebar-1.json:widgets[0].settings.selected',
        'sidebars/sidebar-1.json:widgets[0].settings.content',
        'tables/booking/portable.json:columns.post_id',
        'tables/booking/portable.json:meta:booking_meta.related',
    ];
    $check(
        count($diagnostics) === count($expectedLocators)
        && array_keys($byLocator) === $expectedLocators
        && $byLocator['posts/page/portable.md:meta.list[1]']['message'] === 'declared term reference must be a canonical token, never a raw target id'
        && $byLocator['posts/page/portable.md:meta.structured_keys (key)']['message'] === 'declared term reference must be a canonical token, never a raw target id'
        && $byLocator['posts/page/portable.md:meta.map_at_path.map (key)']['message'] === 'declared term reference must be a canonical token, never a raw target id'
        && $byLocator['sidebars/sidebar-1.json:widgets[0].settings.content']['code'] === 'schema_content_mismatch'
        && $byLocator['sidebars/sidebar-1.json:widgets[0].settings.content']['message'] === 'block-content widget setting must be a string',
        'post/menu/term/options/user/sidebar/table scalar, structured, list, key-map, and block-shape diagnostics remain exact'
    );

    $diagnostics = [];
    $validTree = [
        'post' => ['type' => 'post', 'path' => 'posts/page/valid.md', 'data' => [
            'author' => 'user:alice', 'parent' => "{{post:$post}}",
            'meta' => [
                'scalar' => "{{post:$post}}",
                'structured_json' => ['image' => "{{post:$post}}", 'empty' => '', 'zero' => 0, 'nested' => []],
                'structured_keys' => ["{{term:$term}}" => true],
                'list' => ["{{term:$term}}"],
                'map_at_path' => ['map' => ["{{term:$term}}" => true]],
            ],
        ]],
        'menu' => ['type' => 'menu', 'path' => 'menus/valid.json', 'data' => ['items' => [['type' => 'post_type', 'ref' => "{{post:$post}}"]]]],
        'term' => ['type' => 'term', 'path' => 'terms/category/valid.json', 'data' => ['taxonomy' => 'category', 'description' => ['term' => "{{term:$term}}"], 'meta' => ['related' => "{{term:$term}}"]]],
        'options' => ['type' => 'options', 'path' => 'options/valid.json', 'data' => ['records' => [
            'plain' => ['state' => 'present', 'value' => "{{post:$post}}"],
            'unset' => ['state' => 'present', 'value' => 0],
            'subkeyed' => ['state' => 'present', 'value' => ['author' => 'user:alice']],
            "choice-{{post:$post}}" => ['state' => 'present', 'value' => "{{post:$post}}"],
        ]]],
        'user' => ['type' => 'user-meta', 'path' => 'user-meta/valid.json', 'data' => ['meta' => ['owner' => 'user:alice']]],
        'sidebar' => ['type' => 'sidebar', 'path' => 'sidebars/valid.json', 'data' => ['widgets' => [['type' => 'text', 'settings' => ['selected' => "{{post:$post}}", 'content' => '<!-- wp:paragraph -->']]]]],
        'table' => ['type' => 'booking', 'path' => 'tables/booking/valid.json', 'data' => ['columns' => ['post_id' => "{{post:$post}}"], 'meta' => ['related' => "{{term:$term}}"]]],
    ];
    $validator->validate($validTree);
    $check(
        $diagnostics === [],
        'canonical tokens, a whole-option scalar zero, and declared unset structured leaves remain accepted across every dispatch branch'
    );

    $diagnostics = [];
    $invalidUnsetTree = [[
        'type' => 'options',
        'path' => 'options/unset-shapes.json',
        'data' => ['records' => [
            'plain' => ['state' => 'present', 'value' => -7],
            'unset' => ['state' => 'present', 'value' => '0'],
        ]],
    ]];
    $validator->validate($invalidUnsetTree);
    $check(
        array_map(
            static fn(array $diagnostic): array => [
                $diagnostic['code'],
                $diagnostic['path'],
                $diagnostic['locator'],
                $diagnostic['message'],
            ],
            $diagnostics
        ) === [
            [
                'nonportable_reference',
                'options/unset-shapes.json',
                'options.plain',
                'declared post reference must be a canonical token, never a raw target id',
            ],
            [
                'nonportable_reference',
                'options/unset-shapes.json',
                'options.unset',
                'declared post reference must be a canonical token, never a raw target id',
            ],
        ],
        'whole-option scalar unset accepts only integer zero while negative ids and string zero remain nonportable'
    );

    $compiler = (string) file_get_contents("$root/agent/src/Repository/RepositoryCompiler.php");
    $source = (string) file_get_contents($validatorPath);
    $prime = strpos($compiler, '$this->policy->prime_interpreters_from_repository($tree);');
    $graph = strpos($compiler, '$this->referenceGraphValidator->validate($tree, $this->deletions);');
    $portable = strpos($compiler, '$this->portableShapeValidator->validate($tree);');
    $check(
        substr_count($compiler, "require_once __DIR__ . '/RepositoryPortableShapeValidator.php';") === 1
        && substr_count($compiler, 'new RepositoryPortableShapeValidator(') === 1
        && substr_count($compiler, '->portableShapeValidator->validate($tree)') === 1
        && $prime !== false && $graph !== false && $portable !== false && $prime < $graph && $graph < $portable
        && !str_contains($compiler, 'private function validate_portable_shapes(')
        && !str_contains($compiler, 'private function validate_structured_rule(')
        && !str_contains($compiler, 'private function validate_declared_ref(')
        && str_contains($source, 'OptionState::classification_values($d)')
        && str_contains($source, 'JsonRefs::walk(')
        && str_contains($source, 'ReferenceRules::attached_meta_key('),
        'RepositoryCompiler delegates one portable-shape pass after interpreter and graph validation without retaining the moved policy dispatch or reference-shape bodies'
    );

    if ($failures !== []) {
        fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}
