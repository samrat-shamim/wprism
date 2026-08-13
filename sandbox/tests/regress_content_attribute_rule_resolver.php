<?php
/**
 * Offline characterization for pure block/shortcode manifest projection
 * (DUO-3348 slice 50).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/ContentAttributeRuleResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\ContentAttributeRuleResolver::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load is dependency-free and never loads Policy, RepositoryCompiler, or WordPress'
);

require_once $resolverPath;

use Duo\ContentAttributeRuleResolver;

$manifests = [
    [
        'name' => 'first',
        'block_attrs' => [
            'core/image' => [['path' => 'id', 'kind' => 'post']],
            'core/gallery' => [['path' => 'ids', 'kind' => 'post', 'cast' => 'csv']],
        ],
        'shortcode_attrs' => [
            'gallery' => [['path' => 'ids', 'kind' => 'post', 'cast' => 'csv']],
            'acme' => [['path' => 'post', 'kind' => 'post']],
        ],
    ],
    [
        'name' => 'second',
        'block_attrs' => [
            'core/image' => [['path' => 'attachment', 'kind' => 'post']],
            'acme/card' => [['path' => 'term', 'kind' => 'term']],
        ],
        'shortcode_attrs' => [
            'gallery' => [['path' => 'include', 'kind' => 'post', 'cast' => 'csv']],
            'map' => [['path' => 'term', 'kind' => 'term']],
        ],
    ],
];
$resolver = new ContentAttributeRuleResolver($manifests);
$expectedBlocks = [
    'core/image' => [['path' => 'attachment', 'kind' => 'post']],
    'core/gallery' => [['path' => 'ids', 'kind' => 'post', 'cast' => 'csv']],
    'acme/card' => [['path' => 'term', 'kind' => 'term']],
];
$expectedShortcodes = [
    'gallery' => [['path' => 'include', 'kind' => 'post', 'cast' => 'csv']],
    'acme' => [['path' => 'post', 'kind' => 'post']],
    'map' => [['path' => 'term', 'kind' => 'term']],
];
$check(
    $resolver->block_attr_rules() === $expectedBlocks
        && $resolver->shortcode_attr_rules() === $expectedShortcodes
        && (new ContentAttributeRuleResolver([]))->block_attr_rules() === []
        && (new ContentAttributeRuleResolver([]))->shortcode_attr_rules() === [],
    'resolver preserves independent registries, last-pinned replacement, insertion order, exact rule lists, and empty manifests'
);

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/OptionState.php";
require_once "$root/agent/src/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->block_attr_rules() === $expectedBlocks
        && $policy->shortcode_attr_rules() === $expectedShortcodes,
    'Policy retains both public content-attribute registry facades over the pure resolver'
);
$policy->manifests[1]['block_attrs']['core/image'] = [['path' => 'changed', 'kind' => 'post']];
$policy->manifests[1]['shortcode_attrs']['gallery'] = [['path' => 'changed', 'kind' => 'post']];
$check(
    $policy->block_attr_rules()['core/image'] === [['path' => 'changed', 'kind' => 'post']]
        && $policy->shortcode_attr_rules()['gallery'] === [['path' => 'changed', 'kind' => 'post']],
    'Policy builds a fresh resolver for each facade call so public fixture mutations are observed'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy.php");
$check(
    substr_count($policySource, "require_once __DIR__ . '/ContentAttributeRuleResolver.php';") === 1
        && str_contains($policySource, 'return $this->content_attribute_rule_resolver()->block_attr_rules();')
        && str_contains($policySource, 'return $this->content_attribute_rule_resolver()->shortcode_attr_rules();')
        && str_contains($policySource, 'new ContentAttributeRuleResolver($this->manifests)')
        && !str_contains($policySource, '        foreach ($m[\'block_attrs\'] ?? [] as $block => $rules) {')
        && !str_contains($policySource, '        foreach ($m[\'shortcode_attrs\'] ?? [] as $tag => $rules) {'),
    'Policy requires the resolver once, retains both facades and fresh factory, and leaves no duplicated registry loops'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
