<?php
/**
 * Offline direct regression for RepositoryEntityParser (DUO-3348 slice 34).
 *
 * The parser owns one canonical state file's route, decode, schema boundary,
 * and typed IR. RepositoryCompiler deliberately retains traversal, identity
 * ownership, deletion routing, graph checks, and the final artifact.
 */
declare(strict_types=1);

namespace Duo {
    final class Canon {
        /** @return array<string,mixed> */
        public static function decode(string $content): array {
            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $error) {
                throw new \RuntimeException('fixture canonical decode failed');
            }
            if (!is_array($decoded)) {
                throw new \RuntimeException('fixture canonical document must decode to an object');
            }
            return $decoded;
        }

        /** @return array{0:array<string,mixed>,1:string} */
        public static function parse_post_file(string $content): array {
            if (!preg_match('/\\A---\\n(.*?)\\n---\\n(.*)\\z/s', $content, $parts)) {
                throw new \RuntimeException('fixture post front matter is malformed');
            }
            return [self::decode($parts[1]), rtrim($parts[2], "\n")];
        }

        /** @param array<string,mixed> $front */
        public static function post_hash_basis(array $front, string $body, Policy $policy): string {
            return json_encode($front, JSON_THROW_ON_ERROR) . "\0" . $body;
        }

        public static function read_file(string $path): string {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException("fixture cannot read $path");
            }
            return $bytes;
        }
    }

    final class Policy {
        /** @var array<string,mixed> */
        public array $authored = [];
        /** @var array<string,mixed> */
        public array $subKeyed = [];
        /** @var array<string,array<string,mixed>> */
        public array $optionRules = [];
        /** @var array<string,array<string,mixed>> */
        public array $widgets = [];

        /** @return array<string,mixed> */
        public function authored_options(): array { return $this->authored; }
        /** @return array<string,mixed> */
        public function sub_keyed_options(): array { return $this->subKeyed; }
        /** @return ?array<string,mixed> */
        public function option_rule(string $name): ?array { return $this->optionRules[$name] ?? null; }
        public function menu_field_class(string $field): string { return 'managed'; }
        /** @return array<string,array<string,mixed>> */
        public function widget_types(): array { return $this->widgets; }
        public function taxonomy_object_keyspace(string $taxonomy): string { return 'term'; }
    }
}

namespace {
    require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryEntityParser.php';

    use Duo\Policy;
    use Duo\RepositoryEntityParser;
    use Duo\RepositoryMediaCatalog;
    use Duo\UserMetaState;

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };
    $path = realpath(__DIR__ . '/../../../../agent/src/Repository/RepositoryEntityParser.php');
    $process = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\Canon::class, false) && class_exists(\\Duo\\Policy::class, false) && class_exists(\\Duo\\RepositorySchemaValidator::class, false) && class_exists(\\Duo\\RepositoryMediaCatalog::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) ? "loaded\\n" : "broken\\n";', (string) $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out = is_resource($process) ? stream_get_contents($pipes[1]) : '';
    $err = is_resource($process) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($process)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    } else {
        $exit = 1;
    }
    $check($exit === 0 && $out === "loaded\n" && $err === '', 'normal direct loading closes parser collaborators without loading RepositoryCompiler');
    $check(!class_exists(\Duo\RepositoryCompiler::class, false), 'preloaded Canon and Policy test doubles preserve the target-free parser boundary');

    $temporary = sys_get_temp_dir() . '/duo-repository-entity-parser-' . bin2hex(random_bytes(6));
    if (!mkdir($temporary, 0777, true) && !is_dir($temporary)) {
        throw new \RuntimeException("could not create $temporary");
    }
    register_shutdown_function(static function () use ($temporary): void {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        @rmdir($temporary);
    });

    $uuid = '11111111-1111-4111-8111-111111111111';
    $diagnostics = [];
    $policy = new Policy();
    $lastMediaCatalog = null;
    $newParser = static function (bool $completenessOptional = false) use (&$diagnostics, &$lastMediaCatalog, $temporary, $policy): RepositoryEntityParser {
        $diagnostics = [];
        $lastMediaCatalog = new RepositoryMediaCatalog(
            $temporary,
            static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
                $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
            }
        );
        return new RepositoryEntityParser(
            $policy,
            $completenessOptional,
            $lastMediaCatalog,
            static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
                $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
            }
        );
    };
    $codes = static function () use (&$diagnostics): array { return array_column($diagnostics, 'code'); };
    $document = static fn(array $data): string => json_encode($data, JSON_THROW_ON_ERROR);

    $parser = $newParser();
    $term = ['uuid' => $uuid, 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news', 'description' => '', 'parent' => null, 'meta' => [], 'relationships' => []];
    $entry = $parser->parse("terms/category/$uuid--news.json", $document($term));
    $check($entry !== null && $entry['type'] === 'term' && $entry['data'] === $term && $diagnostics === [], 'term JSON routes to canonical typed IR through the injected schema sink');

    $parser = $newParser();
    $menu = ['uuid' => $uuid, 'name' => 'Primary', 'slug' => 'primary', 'locations' => ['primary'], 'items' => []];
    $entry = $parser->parse('menus/primary.json', $document($menu));
    $check($entry !== null && $entry['type'] === 'menu' && $entry['path'] === 'menus/primary.json' && $diagnostics === [], 'menu JSON preserves its slug-keyed canonical route');

    $parser = $newParser();
    $sidebar = ['widgets' => []];
    $entry = $parser->parse('sidebars/sidebar-1.json', $document($sidebar));
    $check($entry !== null && $entry['type'] === 'sidebar' && $entry['data'] === $sidebar && $diagnostics === [], 'sidebar JSON routes through the exact declared sidebar entity type');

    $parser = $newParser();
    $userMeta = ['login' => 'alice', 'meta' => []];
    $entry = $parser->parse(UserMetaState::path('alice'), $document($userMeta));
    $check($entry !== null && $entry['type'] === 'user-meta' && $entry['data'] === $userMeta && $diagnostics === [], 'login-keyed user-meta JSON validates its canonical hashed filename');

    $parser = $newParser();
    $table = ['uuid' => $uuid, 'table' => 'duo_rows', 'columns' => [], 'meta' => []];
    $entry = $parser->parse("tables/duo_rows/$uuid--one.json", $document($table));
    $check($entry !== null && $entry['type'] === 'duo_rows' && $entry['data'] === $table && $diagnostics === [], 'table JSON retains its declared table type in typed IR');

    $policy->authored = ['required_option' => []];
    $parser = $newParser();
    $entry = $parser->parse('options/core.json', $document(['format' => 'duo-options/v1', 'records' => []]));
    $check($entry !== null && $codes() === ['schema_content_mismatch'] && $diagnostics[0]['locator'] === 'records.required_option', 'strict action compilation retains the required option-record diagnostic');
    $parser = $newParser(true);
    $entry = $parser->parse('options/core.json', $document(['format' => 'duo-options/v1', 'records' => []]));
    $check($entry !== null && $entry['type'] === 'options' && $diagnostics === [], 'historical comparison parsing suppresses only the required option-record gate');
    $policy->authored = [];

    $bytes = "portable attachment\n";
    $hash = hash('sha256', $bytes);
    file_put_contents("$temporary/$hash.txt", $bytes);
    $post = [
        'uuid' => $uuid, 'type' => 'attachment', 'slug' => 'photo', 'title' => 'Photo', 'status' => 'inherit',
        'date' => '', 'date_gmt' => '', 'modified_gmt' => '', 'author' => null, 'parent' => null,
        'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed', 'excerpt' => '',
        'meta' => [], 'terms' => [], 'file' => '2026/08/photo.txt', 'media' => "$hash.txt", 'mime' => 'text/plain', 'alt' => 'Photo',
    ];
    $parser = $newParser();
    $entry = $parser->parse("posts/attachment/$uuid--photo.md", "---\n" . $document($post) . "\n---\nbody\n");
    $check(
        $entry !== null && $entry['type'] === 'post' && $entry['post_type'] === 'attachment' && $entry['body'] === 'body'
        && $lastMediaCatalog instanceof RepositoryMediaCatalog
        && $lastMediaCatalog->referenced_media() === ["$hash.txt" => ['sha256' => $hash, 'base64' => base64_encode($bytes)]]
        && $diagnostics === [],
        'post front matter, body hash basis, and attachment-media validation share one entity parse boundary'
    );

    $parser = $newParser();
    $parser->parse('unknown.json', '{}');
    $check(count($diagnostics) === 1 && $diagnostics[0]['code'] === 'invalid_entity_kind', 'unsupported state paths remain one structured invalid-entity diagnostic');
    $parser = $newParser();
    $parser->parse("terms/category/$uuid--news.json", '{');
    $check(count($diagnostics) === 1 && $diagnostics[0]['code'] === 'malformed_entity', 'canonical decode failures remain one structured malformed-entity diagnostic');
    $parser = $newParser();
    $parser->parse("terms/category/$uuid--wrong.json", $document($term));
    $check($codes() === ['schema_content_mismatch'] && $diagnostics[0]['locator'] === 'slug', 'filename identity mismatches retain their historical schema locator');

    $compiler = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php');
    $entityParser = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryEntityParser.php');
    $check(
        substr_count($compiler, 'new RepositoryEntityParser(') === 1
        && substr_count($compiler, '->entityParser->parse(') === 1
        && !str_contains($compiler, 'private function parse_entity(')
        && substr_count($entityParser, '->schemaValidator->validate(') === 3,
        'RepositoryCompiler delegates one-file decoding to the entity parser without retaining the moved schema body'
    );

    if ($failures !== []) {
        fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}
