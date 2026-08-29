<?php
/**
 * Offline characterization for the pure manifest-declared post-type
 * relationship resolver (issue #3348 slice 40).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Grammar/PostTypeRelationResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\WPrism\\PostTypeRelationResolver::class, false) && !class_exists(\\WPrism\\Policy::class, false) && !class_exists(\\WPrism\\RepositoryCompiler::class, false) ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load is pure and does not load Policy or RepositoryCompiler'
);

require_once $resolverPath;

use WPrism\PostTypeRelationResolver;

$resolver = new PostTypeRelationResolver([
    [
        'name' => 'first',
        'post_types' => [
            'wprism_album' => ['children' => ['wprism_chapter', 'wprism_asset', '', 7]],
            'wprism_chapter' => ['children' => ['wprism_page']],
        ],
    ],
    [
        'name' => 'second',
        'post_types' => [
            'wprism_story' => ['children' => ['wprism_chapter']],
            'wprism_album' => ['children' => ['wprism_asset']],
            'wprism_page' => [],
            'wprism_isolated' => [],
            'wprism_invalid' => 'not-a-declaration',
        ],
    ],
]);

$check(
    $resolver->children('wprism_album') === ['wprism_asset', 'wprism_chapter'],
    'children compose additively, discard invalid leaves, deduplicate, and sort'
);
$check(
    $resolver->parents('wprism_chapter') === ['wprism_album', 'wprism_story'],
    'plural inverse parents are manifest-declared and deterministic'
);
$check(
    $resolver->parents('wprism_asset') === ['wprism_album']
    && $resolver->children('wprism_invalid') === [],
    'one-parent and malformed-declaration cases never infer a relationship'
);
$check(
    $resolver->closure(['wprism_story', 'wprism_isolated', 'wprism_unknown', '', 7])
        === ['wprism_album', 'wprism_asset', 'wprism_chapter', 'wprism_isolated', 'wprism_page', 'wprism_story', 'wprism_unknown'],
    'closure is transitive, bidirectional, lexical, root-preserving, and never guesses unknown types'
);

$policy = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$check(
    substr_count($policy, "require_once __DIR__ . '/../Grammar/PostTypeRelationResolver.php';") === 1
    && substr_count($policy, 'new PostTypeRelationResolver($this->manifests)') === 3
    && str_contains($policy, '->children($postType)')
    && str_contains($policy, '->parents($postType)')
    && str_contains($policy, '->closure($postTypes)')
    && !str_contains($policy, 'foreach ($this->manifests as $manifest) {\n            $decl = $manifest[\'post_types\'][$postType] ?? null;'),
    'Policy keeps three compatibility facades and no longer retains the relationship collector body'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
