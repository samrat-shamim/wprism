<?php
declare(strict_types=1);

/**
 * issue #3354: the scanner foundation must be standalone and deterministic
 * before a registry can rely on it. This pins the historical Lint surface
 * ordering, per-surface pathname sort, locator spelling, and optional
 * `matches` wire shape without loading WordPress, Policy, or Pending.
 */

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

require __DIR__ . '/../../../../agent/src/Review/LintFinding.php';
require __DIR__ . '/../../../../agent/src/Repository/StateTreeWalker.php';

use WPrism\LintFinding;
use WPrism\StateTreeWalker;

check(!class_exists(\WPrism\Policy::class, false), 'primitives standalone load does not load Policy');
check(!class_exists(\WPrism\Pending::class, false), 'primitives standalone load does not load Pending');
check(!function_exists('get_option'), 'primitives standalone load does not need WordPress');

$root = sys_get_temp_dir() . '/wprism-lint-primitives-' . bin2hex(random_bytes(6));
$paths = [
    'posts/page/z.md',
    'posts/post/a.md',
    'terms/category/z.json',
    'terms/post_tag/a.json',
    'menus/z.json',
    'menus/a.json',
    'sidebars/z.json',
    'sidebars/a.json',
    'options/core.json',
    'user-meta/z.json',
    'user-meta/a.json',
    'tables/z/z.json',
    'tables/a/a.json',
];
foreach ($paths as $path) {
    $full = $root . '/' . $path;
    if (!is_dir(dirname($full))) {
        mkdir(dirname($full), 0777, true);
    }
    touch($full);
}

try {
    check(
        StateTreeWalker::files($root) === [
            ['surface' => 'post', 'path' => 'posts/page/z.md'],
            ['surface' => 'post', 'path' => 'posts/post/a.md'],
            ['surface' => 'term', 'path' => 'terms/category/z.json'],
            ['surface' => 'term', 'path' => 'terms/post_tag/a.json'],
            ['surface' => 'menu', 'path' => 'menus/a.json'],
            ['surface' => 'menu', 'path' => 'menus/z.json'],
            ['surface' => 'sidebar', 'path' => 'sidebars/a.json'],
            ['surface' => 'sidebar', 'path' => 'sidebars/z.json'],
            ['surface' => 'options', 'path' => 'options/core.json'],
            ['surface' => 'user_meta', 'path' => 'user-meta/a.json'],
            ['surface' => 'user_meta', 'path' => 'user-meta/z.json'],
            ['surface' => 'table', 'path' => 'tables/a/a.json'],
            ['surface' => 'table', 'path' => 'tables/z/z.json'],
        ],
        'state files preserve Lint’s historic surface order and bytewise per-surface pathname sort'
    );

    $visited = [];
    StateTreeWalker::strings(
        ['map' => ['one' => 'first', 'list' => ['second', ['three' => 'third']]], 'tail' => 'fourth'],
        'meta',
        static function (string $locator, string $value) use (&$visited): void {
            $visited[] = [$locator, $value];
        }
    );
    check(
        $visited === [
            ['meta.map.one', 'first'],
            ['meta.map.list[0]', 'second'],
            ['meta.map.list[1].three', 'third'],
            ['meta.tail', 'fourth'],
        ],
        'string walker preserves historical nested map/list locator spelling and insertion order'
    );

    $withoutMatch = LintFinding::make('bare_id', 'options/core.json', 'options.example', 7, null, 'note');
    check(
        $withoutMatch === [
            'class' => 'bare_id',
            'path' => 'options/core.json',
            'locator' => 'options.example',
            'value' => 7,
            'note' => 'note',
        ],
        'finding omits matches when the resolver has no live identity'
    );
    $match = ['kind' => 'post', 'id' => 7, 'title' => 'Example', 'post_type' => 'post'];
    check(
        LintFinding::make('bare_id', 'options/core.json', 'options.example', 7, $match, 'note')['matches'] === $match,
        'finding preserves an exact resolved-identity match shape'
    );

    $lintSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Review/Lint.php');
    $structuredSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Review/StructuredReferenceScanner.php');
    check(str_contains($lintSource, 'StateTreeWalker::files('), 'Lint delegates state-surface traversal to the shared walker');
    check(str_contains($lintSource, 'StateTreeWalker::strings('), 'Lint delegates recursive string traversal to the shared walker');
    check(str_contains($lintSource, 'LintFinding::make('), 'Lint delegates result construction to the shared finding model');
    check(str_contains($structuredSource, 'LintFinding::make('), 'structured scanner shares the same finding model');
} finally {
    foreach (array_reverse($paths) as $path) {
        @unlink($root . '/' . $path);
    }
    foreach ([
        'posts/page', 'posts/post', 'posts', 'terms/category', 'terms/post_tag', 'terms',
        'menus', 'sidebars', 'options', 'user-meta', 'tables/z', 'tables/a', 'tables',
    ] as $dir) {
        @rmdir($root . '/' . $dir);
    }
    @rmdir($root);
}

fwrite(STDOUT, "ALL PASSED\n");
