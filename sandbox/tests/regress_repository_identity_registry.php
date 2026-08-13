<?php
/**
 * Offline regression for RepositoryIdentityRegistry (DUO-3348 slice 39).
 *
 * UUID ownership and natural-key uniqueness are pure canonical-tree facts.
 * The registry reports through an injected compiler sink; it neither walks
 * files nor throws, so RepositoryCompiler can retain one aggregate refusal
 * and deterministic final diagnostic sort.
 */
declare(strict_types=1);

namespace Duo {
    final class Canon {
        public static function encode(mixed $value): string {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
    }

    final class Policy {
        /** @var array<string,list<string>> */
        public static array $naturalColumns = [];

        /** @return list<string> */
        public static function natural_key_columns(array $declaration): array {
            return self::$naturalColumns[(string) ($declaration['id'] ?? '')] ?? [];
        }
    }

    final class Snapshot {
        /** @var array<string,array<string,mixed>> */
        public static array $rows = [];

        /** @return array<string,array<string,mixed>> */
        public static function row_tables(Policy $policy): array {
            return self::$rows;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    $registryPath = "$root/agent/src/RepositoryIdentityRegistry.php";

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $child = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\Canon::class, false) && class_exists(\\Duo\\Policy::class, false) && class_exists(\\Duo\\Snapshot::class, false) && class_exists(\\Duo\\RepositoryIdentityRegistry::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) ? "loaded\\n" : "broken\\n";', $registryPath],
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
        'normal direct loading closes Canon, Policy, and Snapshot without loading RepositoryCompiler'
    );

    require_once $registryPath;

    use Duo\Policy;
    use Duo\RepositoryIdentityRegistry;
    use Duo\Snapshot;

    $diagnostics = [];
    $registry = new RepositoryIdentityRegistry(
        new Policy(),
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $uuid = '11111111-1111-4111-8111-111111111111';
    $check(!$registry->register('not-a-uuid', 'post', 'posts/page/bad.md') && $diagnostics === [],
        'invalid UUID registration remains caller-owned and emits no duplicate diagnosis');
    $check($registry->register($uuid, 'post', 'posts/page/one.md'), 'the first valid UUID claimant registers');
    $check(!$registry->register($uuid, 'term', 'terms/category/two.json'), 'a second claimant is refused');
    $check(
        $registry->find($uuid) === ['kind' => 'post', 'path' => 'posts/page/one.md']
        && $diagnostics === [[
            'code' => 'duplicate_uuid', 'path' => 'terms/category/two.json', 'locator' => 'uuid',
            'message' => "uuid $uuid is already used by posts/page/one.md", 'relatedPath' => 'posts/page/one.md',
        ]],
        'the first UUID claimant remains the lookup and related-path witness'
    );

    $diagnostics = [];
    Snapshot::$rows = [
        'booking' => ['id' => 'booking', 'identity' => ['mode' => 'natural_key']],
    ];
    Policy::$naturalColumns = ['booking' => ['room', 'slot']];
    $registry->validate_natural_identities([
        'post-a' => ['type' => 'post', 'path' => 'posts/page/a.md', 'data' => ['type' => 'page', 'slug' => 'same', 'parent' => null]],
        'post-b' => ['type' => 'post', 'path' => 'posts/page/b.md', 'data' => ['type' => 'page', 'slug' => 'same', 'parent' => null]],
        'term-a' => ['type' => 'term', 'path' => 'terms/category/a.json', 'data' => ['taxonomy' => 'category', 'slug' => 'same']],
        'menu-a' => ['type' => 'menu', 'path' => 'menus/a.json', 'data' => ['slug' => 'same']],
        'booking-a' => ['type' => 'booking', 'path' => 'tables/booking/a.json', 'data' => ['columns' => ['room' => 'red', 'slot' => 'morning']]],
        'booking-b' => ['type' => 'booking', 'path' => 'tables/booking/b.json', 'data' => ['columns' => ['room' => 'red', 'slot' => 'evening']]],
        'booking-c' => ['type' => 'booking', 'path' => 'tables/booking/c.json', 'data' => ['columns' => ['room' => 'red', 'slot' => 'morning']]],
    ]);
    $check(
        count($diagnostics) === 2
        && $diagnostics[0]['code'] === 'duplicate_natural_identity'
        && $diagnostics[0]['path'] === 'posts/page/b.md'
        && $diagnostics[0]['relatedPath'] === 'posts/page/a.md'
        && $diagnostics[1]['code'] === 'duplicate_natural_identity'
        && $diagnostics[1]['path'] === 'tables/booking/c.json'
        && $diagnostics[1]['relatedPath'] === 'tables/booking/a.json',
        'post natural keys and complete ordered table tuples reject only exact first-wins collisions'
    );

    $compiler = (string) file_get_contents("$root/agent/src/RepositoryCompiler.php");
    $registrySource = (string) file_get_contents($registryPath);
    $check(
        substr_count($compiler, 'new RepositoryIdentityRegistry(') === 1
        && substr_count($compiler, '->identityRegistry->register(') === 3
        && substr_count($compiler, '->identityRegistry->validate_natural_identities($tree)') === 1
        && substr_count($compiler, '->identityRegistry->find(') === 2
        && !str_contains($compiler, 'private function register_identity(')
        && !str_contains($compiler, 'private function validate_natural_identities(')
        && str_contains($registrySource, 'private function add('),
        'RepositoryCompiler delegates identity collection and natural-key validation without retaining duplicate bodies'
    );

    if ($failures !== []) {
        fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}
