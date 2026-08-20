<?php
/**
 * Offline characterization for pure taxonomy relationship-keyspace
 * resolution (DUO-3348 slice 48).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Grammar/TaxonomyKeyspaceResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};
$assertThrows = static function (callable $fn, string $needle, string $label) use ($check): void {
    try {
        $fn();
        $check(false, "$label: expected a refusal, none thrown");
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), $needle),
            "$label: refusal names '$needle' (got: {$e->getMessage()})");
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\TaxonomyKeyspaceResolver::class, false) && class_exists(\\Duo\\TaxonomyPatternResolver::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load closes only taxonomy-pattern resolution, never Policy, RepositoryCompiler, or WordPress'
);

require_once $resolverPath;

use Duo\TaxonomyKeyspaceResolver;
use Duo\TaxonomyPatternResolver;

$manifests = [
    [
        'name' => 'first',
        'taxonomies' => [
            'post_links' => ['object_keyspace' => 'post'],
            'term_links' => ['object_keyspace' => 'term'],
            'legacy_links' => ['class' => 'authored'],
        ],
        'taxonomy_patterns' => [[
            'match' => '^dynamic_', 'object_type' => ['product'], 'object_keyspace' => 'term',
        ]],
    ],
    [
        'name' => 'second',
        'taxonomies' => ['post_links' => ['object_keyspace' => 'post']],
    ],
];
$patterns = new TaxonomyPatternResolver($manifests);
$resolver = new TaxonomyKeyspaceResolver($manifests, $patterns);
$check(
    $resolver->resolve('post_links', ['post']) === 'post'
        && $resolver->resolve('term_links', ['opaque_term_owner']) === 'term'
        && $resolver->resolve('legacy_links', ['post']) === 'post'
        && $resolver->resolve('dynamic_taxonomy', ['product']) === 'term'
        && $resolver->resolve('ordinary_taxonomy', ['post']) === 'post',
    'resolver preserves exact declarations, legacy defaults, pattern declarations, and undeclared post compatibility'
);
$assertThrows(
    static fn() => $resolver->resolve('ordinary_term_taxonomy', ['term']),
    'no manifest object_keyspace declaration',
    'undeclared runtime term ownership refuses rather than inferring a sentinel keyspace'
);
$assertThrows(
    static fn() => $resolver->resolve('post_links', ['term']),
    'declaration/runtime relationship ownership contradicts',
    'declared post keyspace refuses contradictory runtime term ownership'
);
$assertThrows(
    static fn() => $resolver->resolve('term_links', ['product', 'term']),
    'mixed runtime object_type values',
    'one relationship declaration refuses a mixed runtime object-type set'
);

$conflictingExact = $manifests;
$conflictingExact[1]['taxonomies']['post_links']['object_keyspace'] = 'term';
$assertThrows(
    static fn() => (new TaxonomyKeyspaceResolver($conflictingExact, new TaxonomyPatternResolver($conflictingExact)))->resolve('post_links'),
    'ambiguous object_keyspace declarations',
    'conflicting exact declarations refuse rather than using manifest pin order'
);
$exactPatternConflict = $manifests;
$exactPatternConflict[0]['taxonomy_patterns'] = [[
    'match' => '^post_links$', 'object_type' => ['post'], 'object_keyspace' => 'term',
]];
$assertThrows(
    static fn() => (new TaxonomyKeyspaceResolver($exactPatternConflict, new TaxonomyPatternResolver($exactPatternConflict)))->resolve('post_links'),
    'ambiguous object_keyspace declarations',
    'an exact and matching pattern declaration must agree'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->taxonomy_object_keyspace('dynamic_taxonomy', ['product']) === 'term'
        && $policy->taxonomy_object_keyspace('legacy_links', ['post']) === 'post',
    'Policy retains its public taxonomy-keyspace facade over the pure resolver'
);
$policy->manifests[0]['taxonomies']['term_links']['object_keyspace'] = 'post';
$check(
    $policy->taxonomy_object_keyspace('term_links', ['product']) === 'post',
    'Policy builds a fresh keyspace resolver for each facade call so public fixture mutations are observed'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/TaxonomyKeyspaceResolver.php';") === 1
        && str_contains($policySource, 'return $this->taxonomy_keyspace_resolver()->resolve($tax, $runtimeObjectTypes);')
        && str_contains($policySource, 'new TaxonomyKeyspaceResolver($this->manifests, $this->taxonomy_pattern_resolver())')
        && !str_contains($policySource, '        $declared = [];' . "\n"
            . '        foreach ($this->manifests as $manifest) {' . "\n"
            . "            \$name = (string) (\$manifest['name'] ?? '?');"),
    'Policy requires the resolver once, retains only the explicit facade/factory, and leaves no duplicate declaration loop'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
