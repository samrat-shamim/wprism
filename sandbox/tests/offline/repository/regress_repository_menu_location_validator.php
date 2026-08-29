<?php
/**
 * Offline characterization for RepositoryMenuLocationValidator (issue #3348
 * slice 43). It owns only the authored menu-location uniqueness topology;
 * RepositoryCompiler retains traversal, parsing, aggregate refusal, and the
 * surrounding identity/graph/portable-shape ordering.
 */
declare(strict_types=1);

namespace WPrism {
    final class Policy {
        public string $locationsClass = 'authored';
        public function menu_field_class(string $field): string {
            return $field === 'locations' ? $this->locationsClass : 'authored';
        }
    }
}

namespace {
    $root = dirname(__DIR__, 4);
    $validatorPath = "$root/agent/src/Repository/RepositoryMenuLocationValidator.php";
    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $child = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\WPrism\\RepositoryMenuLocationValidator::class, false) && class_exists(\\WPrism\\Policy::class, false) && !class_exists(\\WPrism\\RepositoryCompiler::class, false) && !class_exists(\\WPrism\\RepositoryReferenceGraphValidator::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $validatorPath],
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
        'normal direct loading closes Policy without loading RepositoryCompiler, sibling validators, or WordPress'
    );

    require_once $validatorPath;

    use WPrism\Policy;
    use WPrism\RepositoryMenuLocationValidator;

    $policy = new Policy();
    $diagnostics = [];
    $validator = new RepositoryMenuLocationValidator(
        $policy,
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $validator->validate([
        'menu-a' => ['type' => 'menu', 'path' => 'menus/a.json', 'data' => ['locations' => ['primary']]],
        'menu-b' => ['type' => 'menu', 'path' => 'menus/b.json', 'data' => ['locations' => ['footer']]],
    ]);
    $check($diagnostics === [], 'one authored holder per active theme location remains valid');

    $diagnostics = [];
    $validator->validate([
        'not-a-menu' => ['type' => 'post', 'path' => 'posts/ignored.md', 'data' => ['locations' => ['primary']]],
        'menu-a' => ['type' => 'menu', 'path' => 'menus/a.json', 'data' => ['locations' => ['primary', 'footer', '', 0, null]]],
        'menu-b' => ['type' => 'menu', 'path' => 'menus/b.json', 'data' => ['locations' => ['primary', 'footer']]],
        'menu-c' => ['type' => 'menu', 'path' => 'menus/c.json', 'data' => ['locations' => ['primary']]],
    ]);
    $check(
        $diagnostics === [
            [
                'code' => 'duplicate_menu_location', 'path' => 'menus/b.json', 'locator' => 'locations[0]',
                'message' => "menu location 'primary' is already assigned by menus/a.json", 'relatedPath' => 'menus/a.json',
            ],
            [
                'code' => 'duplicate_menu_location', 'path' => 'menus/b.json', 'locator' => 'locations[1]',
                'message' => "menu location 'footer' is already assigned by menus/a.json", 'relatedPath' => 'menus/a.json',
            ],
            [
                'code' => 'duplicate_menu_location', 'path' => 'menus/c.json', 'locator' => 'locations[0]',
                'message' => "menu location 'primary' is already assigned by menus/a.json", 'relatedPath' => 'menus/a.json',
            ],
        ],
        'authored locations retain exact first-holder, duplicate diagnostics, ordering, and schema-owned leaf skips'
    );

    $policy->locationsClass = 'derived';
    $diagnostics = [];
    $validator->validate([
        'menu-a' => ['type' => 'menu', 'path' => 'menus/a.json', 'data' => ['locations' => ['primary']]],
        'menu-b' => ['type' => 'menu', 'path' => 'menus/b.json', 'data' => ['locations' => ['primary']]],
    ]);
    $check($diagnostics === [], 'derived locations remain wholly owned by the manifest and bypass authored uniqueness proof');

    $compiler = (string) file_get_contents("$root/agent/src/Repository/RepositoryCompiler.php");
    $source = (string) file_get_contents($validatorPath);
    $natural = strpos($compiler, '$this->identityRegistry->validate_natural_identities($tree);');
    $menu = strpos($compiler, '$this->menuLocationValidator->validate($tree);');
    $graph = strpos($compiler, '$this->referenceGraphValidator->validate($tree, $this->deletions);');
    $check(
        substr_count($compiler, "require_once __DIR__ . '/RepositoryMenuLocationValidator.php';") === 1
        && substr_count($compiler, 'new RepositoryMenuLocationValidator(') === 1
        && substr_count($compiler, '->menuLocationValidator->validate($tree)') === 1
        && $natural !== false && $menu !== false && $graph !== false && $natural < $menu && $menu < $graph
        && !str_contains($compiler, 'private function validate_menu_locations(')
        && str_contains($source, "menu_field_class('locations')")
        && str_contains($source, "'duplicate_menu_location'"),
        'RepositoryCompiler delegates one menu topology pass between identity and graph validation without retaining the moved invariant'
    );

    if ($failures !== []) {
        fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}
