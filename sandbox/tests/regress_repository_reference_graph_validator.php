<?php
/**
 * Offline characterization for RepositoryReferenceGraphValidator (DUO-3348
 * slice 41). The validator consumes ReferenceGraph rather than re-walking
 * canonical data, and reports through the compiler's aggregate callback.
 */
declare(strict_types=1);

namespace Duo {
    final class Policy {
        /** @var array<string,array<string,mixed>> */
        public array $widgets = [];

        /** @return array<string,array<string,mixed>> */
        public function widget_types(): array { return $this->widgets; }
    }

    final class Snapshot {
        /** @var array<string,array<string,mixed>> */
        public static array $rows = [];

        /** @return array<string,array<string,mixed>> */
        public static function row_tables(Policy $policy): array { return self::$rows; }
    }

    final class SidebarState {
        public const ENTITY_TYPE = 'sidebar';
        public static function kind(string $type): string { return 'widget_' . $type; }
    }

    final class RepositoryIdentityRegistry {
        /** @var array<string,array{kind:string,path:string}> */
        private array $rows;

        /** @param array<string,array{kind:string,path:string}> $rows */
        public function __construct(array $rows = []) { $this->rows = $rows; }
        public static function is_uuid(string $uuid): bool {
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1;
        }
        /** @return ?array{kind:string,path:string} */
        public function find(string $uuid): ?array { return $this->rows[$uuid] ?? null; }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    $validatorPath = "$root/agent/src/Repository/RepositoryReferenceGraphValidator.php";
    $graphPath = "$root/agent/src/Repository/ReferenceGraph.php";
    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $child = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\RepositoryReferenceGraphValidator::class, false) && class_exists(\\Duo\\Policy::class, false) && class_exists(\\Duo\\Snapshot::class, false) && class_exists(\\Duo\\SidebarState::class, false) && class_exists(\\Duo\\ReferenceGraph::class, false) && class_exists(\\Duo\\RepositoryIdentityRegistry::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $validatorPath],
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
        'normal direct loading closes every graph dependency without loading RepositoryCompiler'
    );

    require_once $graphPath;
    require_once $validatorPath;

    use Duo\Policy;
    use Duo\RepositoryIdentityRegistry;
    use Duo\RepositoryReferenceGraphValidator;

    $uuid = static fn(int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
    $post = $uuid(1);
    $term = $uuid(2);
    $deleted = $uuid(3);
    $menu = $uuid(4);
    $itemA = $uuid(5);
    $itemB = $uuid(6);
    $missing = $uuid(7);
    $booking = $uuid(8);
    $rawMalformed = $uuid(9);
    \Duo\Snapshot::$rows = [
        'booking' => ['id_kind' => 'booking'],
    ];
    $rows = [
        $post => ['kind' => 'post', 'path' => 'posts/page/post.md'],
        $term => ['kind' => 'term', 'path' => 'terms/category/term.json'],
        $menu => ['kind' => 'menu', 'path' => 'menus/main.json'],
        $itemA => ['kind' => 'menu_item', 'path' => 'menus/main.json#items[0]'],
        $itemB => ['kind' => 'menu_item', 'path' => 'menus/main.json#items[1]'],
    ];
    $diagnostics = [];
    $cleanValidator = new RepositoryReferenceGraphValidator(
        new Policy(),
        new RepositoryIdentityRegistry([
            $booking => ['kind' => 'booking', 'path' => 'tables/booking/one.json'],
        ]),
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $cleanValidator->validate([
        $booking => [
            'type' => 'booking', 'path' => 'tables/booking/one.json', 'body' => '',
            'data' => ['uuid' => $booking, 'columns' => ['self' => "{{booking:$booking}}"]],
        ],
    ], []);
    $check($diagnostics === [], 'a manifest-declared table id_kind validates through the shared graph without an engine branch');

    $validator = new RepositoryReferenceGraphValidator(
        new Policy(),
        new RepositoryIdentityRegistry($rows),
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $tree = [
        $post => [
            'type' => 'post', 'path' => 'posts/page/post.md', 'body' => '',
            'data' => ['uuid' => $post, 'parent' => "{{post:$term}}", 'terms' => ['category' => [$deleted]]],
        ],
        $term => [
            'type' => 'term', 'path' => 'terms/category/term.json', 'body' => '',
            'data' => ['uuid' => $term, 'parent' => $post, 'relationships' => []],
        ],
        $rawMalformed => [
            'type' => 'term', 'path' => 'terms/category/raw-malformed.json', 'body' => '',
            'data' => ['uuid' => $rawMalformed, 'parent' => 'not-a-uuid', 'relationships' => []],
        ],
        $menu => [
            'type' => 'menu', 'path' => 'menus/main.json', 'body' => '',
            'data' => ['uuid' => $menu, 'items' => [
                ['uuid' => $itemA, 'parent' => $itemB, 'title' => "{{made_up:$post}}"],
                ['uuid' => $itemB, 'parent' => $itemA, 'title' => '{{post:not-a-uuid}}'],
                ['uuid' => $uuid(8), 'parent' => $missing, 'title' => "{{post:$missing}}"],
            ]],
        ],
    ];
    $validator->validate($tree, [
        $deleted => ['path' => 'deletions/removed.json'],
    ]);
    $byCode = [];
    foreach ($diagnostics as $diagnostic) {
        $byCode[$diagnostic['code']][] = $diagnostic;
    }
    $check(
        isset($byCode['invalid_reference_kind'])
        && isset($byCode['malformed_reference'])
        && isset($byCode['semantic_delete_reference'])
        && isset($byCode['reference_kind_mismatch'])
        && isset($byCode['reference_cycle'])
        && count($byCode['semantic_delete_reference']) === 3
        && count($byCode['malformed_reference']) === 2,
        'ReferenceGraph-driven validation reports invalid kinds, malformed values, deleted and absent targets, kind mismatch, and hierarchy cycles'
    );
    $exactReferenceWitnesses = $byCode['semantic_delete_reference'] === [
            ['code' => 'semantic_delete_reference', 'path' => 'posts/page/post.md', 'locator' => 'terms.category[0]', 'message' => "reference target $deleted is explicitly deleted by deletions/removed.json", 'relatedPath' => 'deletions/removed.json'],
            ['code' => 'semantic_delete_reference', 'path' => 'menus/main.json', 'locator' => '$.items[2].title', 'message' => "reference target $missing is absent from the compiled revision", 'relatedPath' => null],
            ['code' => 'semantic_delete_reference', 'path' => 'menus/main.json', 'locator' => 'items[2].parent', 'message' => "menu-item parent $missing is absent from this menu", 'relatedPath' => null],
        ]
        && $byCode['reference_cycle'] === [[
            'code' => 'reference_cycle', 'path' => 'terms/category/term.json', 'locator' => 'parent',
            'message' => "parent graph contains cycle $post -> $term -> $post", 'relatedPath' => null,
        ], [
            'code' => 'reference_cycle', 'path' => 'menus/main.json', 'locator' => 'items[1].parent',
            'message' => "parent graph contains cycle $itemA -> $itemB -> $itemA", 'relatedPath' => null,
        ]];
    $check(
        $exactReferenceWitnesses,
        'explicit deletion provenance, absent menu membership, and deterministic parent-cycle witnesses remain exact'
    );
    $check(
        $byCode['invalid_reference_kind'] === [[
            'code' => 'invalid_reference_kind', 'path' => 'menus/main.json', 'locator' => '$.items[0].title',
            'message' => "reference kind 'made_up' is not registered by core or a pinned table schema", 'relatedPath' => null,
        ]]
        && $byCode['malformed_reference'] === [
            ['code' => 'malformed_reference', 'path' => 'terms/category/raw-malformed.json', 'locator' => 'parent', 'message' => "reference 'not-a-uuid' is not a valid UUID", 'relatedPath' => null],
            ['code' => 'malformed_reference', 'path' => 'menus/main.json', 'locator' => '$.items[1].title', 'message' => "reference token '{{post:not-a-uuid}}' is malformed", 'relatedPath' => null],
        ]
        && $byCode['reference_kind_mismatch'] === [
            ['code' => 'reference_kind_mismatch', 'path' => 'posts/page/post.md', 'locator' => '$.parent', 'message' => "reference target $term is term; expected post|menu_item", 'relatedPath' => null],
            ['code' => 'reference_kind_mismatch', 'path' => 'terms/category/term.json', 'locator' => 'parent', 'message' => "reference target $post is post; expected term", 'relatedPath' => null],
        ],
        'token and raw UUID grammar plus registered-kind mismatch diagnostics remain exact'
    );

    $compiler = (string) file_get_contents("$root/agent/src/Repository/RepositoryCompiler.php");
    $validatorSource = (string) file_get_contents($validatorPath);
    $check(
        substr_count($compiler, "require_once __DIR__ . '/RepositoryReferenceGraphValidator.php';") === 1
        && substr_count($compiler, 'new RepositoryReferenceGraphValidator(') === 1
        && substr_count($compiler, '->referenceGraphValidator->validate($tree, $this->deletions)') === 1
        && !str_contains($compiler, 'private function validate_graph(')
        && !str_contains($compiler, 'private function validate_reference_cycles(')
        && !str_contains($compiler, 'private function validate_token(')
        && !str_contains($compiler, 'private function validate_raw_ref(')
        && str_contains($validatorSource, 'ReferenceGraph::edges($tree, $this->policy)')
        && str_contains($validatorSource, 'private function validate_reference_cycles('),
        'RepositoryCompiler delegates one graph pass and retains neither a parallel walker nor graph-validation bodies'
    );

    if ($failures !== []) {
        fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}
