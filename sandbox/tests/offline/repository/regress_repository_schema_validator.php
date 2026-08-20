<?php
declare(strict_types=1);

namespace Duo {
    final class Policy {
        public bool $derivedLocations = false;
        /** @var array<string,string> */
        public array $keyspaces = ['category' => 'post'];
        /** @return array<string,mixed> */
        public function menu_field_class(string $field): string { return $this->derivedLocations ? 'derived' : 'authored'; }
        public function taxonomy_object_keyspace(string $taxonomy): string {
            if (!isset($this->keyspaces[$taxonomy])) {
                throw new \RuntimeException("no keyspace for $taxonomy");
            }
            return $this->keyspaces[$taxonomy];
        }
        /** @return array<string,array<string,mixed>> */
        public function widget_types(): array { return ['text' => ['settings' => ['title' => []]]]; }
    }
}

namespace {
    require_once __DIR__ . '/../../../../agent/src/Repository/RepositorySchemaValidator.php';

    use Duo\Policy;
    use Duo\RepositorySchemaValidator;

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) $failures[] = $message;
    };
    $validatorPath = realpath(__DIR__ . '/../../../../agent/src/Repository/RepositorySchemaValidator.php');
    $probe = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\Policy::class, false) ? "loaded\\n" : "missing\\n";', (string) $validatorPath],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $probeOut = is_resource($probe) ? stream_get_contents($pipes[1]) : '';
    $probeErr = is_resource($probe) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($probe)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $probeExit = proc_close($probe);
    } else {
        $probeExit = 1;
    }
    $check($probeExit === 0 && $probeOut === "loaded\n" && $probeErr === '', 'normal direct loading closes the real Policy dependency before validation');
    $policy = new Policy();
    $diagnostics = [];
    $validator = new RepositorySchemaValidator($policy, 'sidebar', static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
        $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    });
    $codes = static function () use (&$diagnostics): array { return array_column($diagnostics, 'code'); };
    $reset = static function () use (&$diagnostics): void { $diagnostics = []; };

    $check(!class_exists(\Duo\RepositoryCompiler::class, false), 'direct schema-validator loading does not pull RepositoryCompiler');
    $post = ['uuid' => 'u', 'type' => 'post', 'slug' => 'one', 'title' => '', 'status' => 'publish', 'date' => '', 'date_gmt' => '', 'modified_gmt' => '', 'author' => null, 'parent' => null, 'menu_order' => 0, 'comment_status' => '', 'ping_status' => '', 'excerpt' => '', 'meta' => [], 'terms' => ['category' => []]];
    $validator->validate('post', 'posts/post/u--one.md', $post, '');
    $check($diagnostics === [], 'a complete post shape with the declared post keyspace is accepted without compiler state');

    $reset(); $policy->keyspaces['category'] = 'term';
    $validator->validate('post', 'posts/post/u--one.md', $post, '');
    $check(in_array('taxonomy_object_keyspace_mismatch', $codes(), true), 'a post relationship refuses a taxonomy resolved to the term keyspace');

    $reset(); $policy->derivedLocations = true;
    $validator->validate('menu', 'menus/main.json', ['uuid' => 'u', 'name' => 'Main', 'slug' => 'main', 'items' => []], null);
    $check($diagnostics === [], 'derived menu locations are not required by decoded schema validation');

    $reset(); $policy->derivedLocations = false;
    $validator->validate('sidebar', 'sidebars/sidebar-1.json', ['widgets' => [['uuid' => 'w', 'type' => 'missing', 'settings' => ['undeclared' => true]]]], null);
    $check(in_array('schema_content_mismatch', $codes(), true), 'undeclared sidebar widget shapes are diagnosed through the injected compiler sink');

    $reset(); unset($policy->keyspaces['category']);
    $validator->validate('term', 'terms/category/u--one.json', ['uuid' => 'u', 'taxonomy' => 'category', 'name' => 'One', 'slug' => 'one', 'description' => '', 'parent' => null, 'meta' => [], 'relationships' => ['category' => []]], null);
    $check(in_array('taxonomy_object_keyspace_invalid', $codes(), true), 'a policy keyspace resolver refusal remains a structured schema diagnostic');

    $compiler = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php');
    $entityParser = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryEntityParser.php');
    $check(
        substr_count($compiler, 'new RepositoryEntityParser(') === 1
        && substr_count($compiler, '->entityParser->parse(') === 1
        && !str_contains($compiler, 'private function parse_entity(')
        && substr_count($entityParser, '->schemaValidator->validate(') === 3
        && !str_contains($entityParser, 'private function validate_schema(')
        && !str_contains($entityParser, 'private function validate_taxonomy_relationship_keyspace('),
        'RepositoryEntityParser owns every moved schema call while RepositoryCompiler retains only its one-file delegate'
    );
    if ($failures !== []) { fwrite(STDERR, "FAILED\n"); exit(1); }
    echo "ALL PASSED\n";
}
