<?php
/**
 * Offline characterization for pure manifest-declared taxonomy-pattern
 * resolution (DUO-3348 slice 47).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/TaxonomyPatternResolver.php";
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
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\TaxonomyPatternResolver::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load is pure and does not load Policy, RepositoryCompiler, or WordPress'
);

require_once $resolverPath;

use Duo\TaxonomyPatternResolver;

$manifests = [
    [
        'name' => 'first',
        'taxonomy_patterns' => [[
            'match' => '^pa_',
            'object_type' => ['product', 'product_variation', 'product'],
            'update_count_callback' => 'wc_update_product_terms',
            'object_keyspace' => 'term',
        ]],
    ],
    [
        'name' => 'second',
        'taxonomy_patterns' => [[
            'match' => '^genre_',
            'object_type' => ['book'],
        ]],
    ],
];
$resolver = new TaxonomyPatternResolver($manifests);
$rules = $resolver->rules();
$check(
    count($rules) === 2
        && $rules[0] === [
            'match' => '^pa_',
            'object_type' => ['product', 'product_variation'],
            'update_count_callback' => 'wc_update_product_terms',
            'object_keyspace' => 'term',
            'source' => "manifest 'first' taxonomy_patterns[0]",
        ]
        && ($rules[1]['object_keyspace'] ?? null) === 'post'
        && array_key_exists('update_count_callback', $rules[1])
        && $rules[1]['update_count_callback'] === null,
    'resolver preserves pin/declaration order, normalizes object types, and applies legacy defaults'
);
$check(
    $resolver->match('pa_color') === $rules[0]
        && $resolver->match('genre_fiction') === $rules[1]
        && $resolver->match('ordinary_taxonomy') === null,
    'resolver returns the complete matching contract and leaves an unowned taxonomy null'
);
$check(
    TaxonomyPatternResolver::matches('^pa_', 'pa_size')
        && !TaxonomyPatternResolver::matches('^pa_', 'genre_fiction')
        && !TaxonomyPatternResolver::matches('(', 'pa_size'),
    'resolver retains undelimited-PCRE matching and invalid-regex false behavior'
);

$ambiguous = new TaxonomyPatternResolver([
    $manifests[0],
    [
        'name' => 'overlap',
        'taxonomy_patterns' => [[
            'match' => '^pa_color$',
            'object_type' => ['catalog_item'],
            'update_count_callback' => 'wc_update_product_terms',
            'object_keyspace' => 'term',
        ]],
    ],
]);
$assertThrows(
    static fn() => $ambiguous->match('pa_color'),
    'ambiguous taxonomy_patterns contracts',
    'concrete overlapping patterns with distinct object_type contracts refuse instead of choosing pin order'
);
$keyspaceAmbiguous = new TaxonomyPatternResolver([
    $manifests[0],
    [
        'name' => 'keyspace-overlap',
        'taxonomy_patterns' => [[
            'match' => '^pa_color$',
            'object_type' => ['product', 'product_variation'],
            'update_count_callback' => 'wc_update_product_terms',
            'object_keyspace' => 'post',
        ]],
    ],
]);
$assertThrows(
    static fn() => $keyspaceAmbiguous->match('pa_color'),
    'ambiguous object_keyspace declarations',
    'concrete overlapping patterns with distinct relationship keyspaces refuse separately'
);

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/OptionState.php";
require_once "$root/agent/src/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->taxonomy_pattern_rules() === $rules
        && $policy->pattern_object_type('pa_color') === ['product', 'product_variation']
        && $policy->pattern_update_count_callback('pa_color') === 'wc_update_product_terms'
        && $policy->taxonomy_object_keyspace('pa_color', ['product']) === 'term'
        && Duo\Policy::taxonomy_pattern_matches('^pa_', 'pa_color') === TaxonomyPatternResolver::matches('^pa_', 'pa_color'),
    'Policy retains byte-equivalent public facades and keyspace resolution over the pure resolver'
);
$policy->manifests[0]['taxonomy_patterns'][0]['match'] = '^changed_';
$check(
    $policy->pattern_object_type('pa_color') === null
        && $policy->pattern_object_type('changed_color') === ['product', 'product_variation'],
    'Policy builds a fresh resolver for each facade call so public fixture mutations are observed'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy.php");
$check(
    substr_count($policySource, "require_once __DIR__ . '/TaxonomyPatternResolver.php';") === 1
        && str_contains($policySource, 'return $this->taxonomy_pattern_resolver()->rules();')
        && str_contains($policySource, 'return $this->taxonomy_pattern_resolver()->match($tax)[\'object_type\'] ?? null;')
        && str_contains($policySource, 'return $this->taxonomy_pattern_resolver()->match($tax)[\'update_count_callback\'] ?? null;')
        && str_contains($policySource, 'return TaxonomyPatternResolver::matches($match, $tax);')
        && str_contains($policySource, 'new TaxonomyPatternResolver($this->manifests)')
        && str_contains($policySource, '$pattern = $this->taxonomy_pattern_resolver()->match($tax);')
        && !str_contains($policySource, 'private function matching_taxonomy_pattern_rule(')
        && !str_contains($policySource, 'foreach ($this->taxonomy_pattern_rules() as $pattern) {'),
    'Policy requires the resolver once, retains explicit public facades, and leaves no duplicate concrete-pattern matcher'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
