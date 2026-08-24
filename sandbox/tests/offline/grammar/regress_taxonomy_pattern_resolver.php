<?php
/**
 * Offline characterization for pure manifest-declared taxonomy-pattern
 * resolution (DUO-3348 slice 47).
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Grammar/TaxonomyPatternResolver.php";
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
            'hierarchical' => null,
            'object_keyspace' => 'term',
            'source' => "manifest 'first' taxonomy_patterns[0]",
        ]
        && ($rules[1]['object_keyspace'] ?? null) === 'post'
        && array_key_exists('update_count_callback', $rules[1])
        && $rules[1]['update_count_callback'] === null
        && array_key_exists('hierarchical', $rules[1])
        && $rules[1]['hierarchical'] === null,
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

$patternHierarchy = static function (?bool $first, ?bool $second): TaxonomyPatternResolver {
    $rule = static function (string $name, ?bool $hierarchical): array {
        $pattern = [
            'match' => '^pa_color$',
            'object_type' => ['product'],
        ];
        if ($hierarchical !== null) {
            $pattern['hierarchical'] = $hierarchical;
        }
        return ['name' => $name, 'taxonomy_patterns' => [$pattern]];
    };
    return new TaxonomyPatternResolver([
        $rule('first', $first),
        $rule('second', $second),
    ]);
};
foreach ([[null, false], [false, null], [null, true], [true, null], [false, true], [true, false]] as [$first, $second]) {
    $assertThrows(
        static fn() => $patternHierarchy($first, $second)->match('pa_color'),
        'ambiguous taxonomy_patterns contracts',
        'overlapping patterns distinguish omitted, false, and true hierarchy declarations in both source orders'
    );
}
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

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

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

final class TaxonomyPatternWpdbFixture {
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $last_error = '';
    public string $next_error = '';
    public mixed $result = [
        ['taxonomy' => 'category'],
        ['taxonomy' => 'pa_color'],
        ['taxonomy' => 'pa_size'],
        ['taxonomy' => 'unowned'],
    ];
    public string $query = '';

    public function get_results(string $query, mixed $mode = null): mixed {
        $this->query = $query;
        $this->last_error = $this->next_error;
        return $this->result;
    }
}

$scopePolicy = new Duo\Policy();
$scopePolicy->manifests = [[
    'name' => 'woocommerce',
    'taxonomy_patterns' => [[
        'match' => '^pa_',
        'object_type' => ['product'],
    ]],
]];
$scopeWpdb = new TaxonomyPatternWpdbFixture();
$GLOBALS['wpdb'] = $scopeWpdb;
$check(
    $scopePolicy->taxonomies() === ['category', 'post_tag', 'pa_color', 'pa_size'],
    'Policy expands only exact checked live taxonomy names matching a declared pattern'
);
$check(
    str_contains($scopeWpdb->query, 'SELECT BINARY taxonomy AS taxonomy')
        && str_contains($scopeWpdb->query, 'ORDER BY BINARY taxonomy ASC LIMIT 4097'),
    'taxonomy-pattern expansion uses a bounded byte-identity query instead of an unchecked get_col scan'
);
foreach ([false, null, ['taxonomy' => 'pa_color']] as $badResult) {
    $scopeWpdb = new TaxonomyPatternWpdbFixture();
    $scopeWpdb->result = $badResult;
    $GLOBALS['wpdb'] = $scopeWpdb;
    $assertThrows(
        static fn() => $scopePolicy->taxonomies(),
        'taxonomy-pattern scope discovery read failed',
        'taxonomy-pattern expansion rejects false, null, and non-list driver results'
    );
}
$scopeWpdb = new TaxonomyPatternWpdbFixture();
$scopeWpdb->next_error = 'fixture database error';
$GLOBALS['wpdb'] = $scopeWpdb;
$assertThrows(
    static fn() => $scopePolicy->taxonomies(),
    'taxonomy-pattern scope discovery read failed',
    'taxonomy-pattern expansion rejects a value returned with a database error'
);
foreach ([
    [['taxonomy' => 'Pa_Color']],
    [['taxonomy' => "pa_\0color"]],
    [['taxonomy' => str_repeat('x', 33)]],
    [['taxonomy' => 'pa_color'], ['taxonomy' => 'pa_color']],
    [['taxonomy' => 'pa_color', 'extra' => 'x']],
] as $badRows) {
    $scopeWpdb = new TaxonomyPatternWpdbFixture();
    $scopeWpdb->result = $badRows;
    $GLOBALS['wpdb'] = $scopeWpdb;
    $assertThrows(
        static fn() => $scopePolicy->taxonomies(),
        'malformed/duplicate row',
        'taxonomy-pattern expansion rejects malformed identities, aliases, extra columns, and duplicates'
    );
}
$scopeWpdb = new TaxonomyPatternWpdbFixture();
$scopeWpdb->result = array_fill(0, 4097, ['taxonomy' => 'pa_color']);
$GLOBALS['wpdb'] = $scopeWpdb;
$assertThrows(
    static fn() => $scopePolicy->taxonomies(),
    'bounded taxonomy limit',
    'taxonomy-pattern expansion rejects saturation before processing live names'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/TaxonomyPatternResolver.php';") === 1
        && str_contains($policySource, 'return $this->taxonomy_pattern_resolver()->rules();')
        && str_contains($policySource, 'return $this->taxonomy_pattern_resolver()->match($tax)[\'object_type\'] ?? null;')
        && str_contains($policySource, 'return $this->taxonomy_pattern_resolver()->match($tax)[\'update_count_callback\'] ?? null;')
        && str_contains($policySource, 'return TaxonomyPatternResolver::matches($match, $tax);')
        && str_contains($policySource, 'new TaxonomyPatternResolver($this->manifests)')
        && str_contains($policySource, 'return $this->taxonomy_keyspace_resolver()->resolve($tax, $runtimeObjectTypes);')
        && !str_contains($policySource, 'private function matching_taxonomy_pattern_rule(')
        && !str_contains($policySource, 'foreach ($this->taxonomy_pattern_rules() as $pattern) {'),
    'Policy requires the resolver once, retains explicit public facades, and leaves no duplicate concrete-pattern matcher or keyspace body'
);

// ---- round-3 T5: exact registration declarations (taxonomies.<tax>.object_type)
$exact = new TaxonomyPatternResolver([
    ['name' => 'woocommerce', 'taxonomies' => [
        'product_cat' => ['class' => 'authored', 'object_type' => ['product'], 'update_count_callback' => '_wc_term_recount', 'hierarchical' => true],
        'product_type' => ['class' => 'authored', 'object_type' => ['product_variation', 'product']],
        'product_visibility' => ['class' => 'runtime'],
    ], 'taxonomy_patterns' => [
        ['match' => '^pa_', 'object_type' => ['product'], 'update_count_callback' => '_update_post_term_count', 'hierarchical' => false],
    ]],
]);
$check(
    $exact->declaredRegistration('product_cat') === [
        'object_type' => ['product'],
        'update_count_callback' => '_wc_term_recount',
        'hierarchical' => true,
        'source' => "manifest 'woocommerce' taxonomies.product_cat",
    ],
    'an exact taxonomies.<tax>.object_type declaration is served with its callback and source'
);
$check(
    $exact->declaredRegistration('product_type') === [
        'object_type' => ['product', 'product_variation'],
        'update_count_callback' => null,
        'hierarchical' => null,
        'source' => "manifest 'woocommerce' taxonomies.product_type",
    ],
    'object types are sorted and a missing callback is null, as for a pattern rule'
);
$check(
    $exact->declaredRegistration('product_visibility') === null && $exact->declaredRegistration('pa_color') === null,
    'a rule without object_type, and a name only a pattern matches, have no exact registration declaration'
);
$conflicting = new TaxonomyPatternResolver([
    ['name' => 'a', 'taxonomies' => ['shared' => ['class' => 'authored', 'object_type' => ['post']]]],
    ['name' => 'b', 'taxonomies' => ['shared' => ['class' => 'authored', 'object_type' => ['page']]]],
]);
$assertThrows(
    static fn () => $conflicting->declaredRegistration('shared'),
    'ambiguous registration declarations',
    'two manifests declaring different registration facts for one exact taxonomy refuse'
);

$exactHierarchy = static function (?bool $first, ?bool $second): TaxonomyPatternResolver {
    $rule = static function (string $name, ?bool $hierarchical): array {
        $taxonomy = ['class' => 'authored', 'object_type' => ['post']];
        if ($hierarchical !== null) {
            $taxonomy['hierarchical'] = $hierarchical;
        }
        return ['name' => $name, 'taxonomies' => ['shared' => $taxonomy]];
    };
    return new TaxonomyPatternResolver([
        $rule('first', $first),
        $rule('second', $second),
    ]);
};
foreach ([[null, false], [false, null], [null, true], [true, null], [false, true], [true, false]] as [$first, $second]) {
    $assertThrows(
        static fn() => $exactHierarchy($first, $second)->declaredRegistration('shared'),
        'ambiguous registration declarations',
        'exact declarations distinguish omitted, false, and true hierarchy facts in both source orders'
    );
}

$exactPatternHierarchy = static function (?bool $exactHierarchy, bool $patternHierarchy): Duo\Policy {
    $exactRule = ['class' => 'authored', 'object_type' => ['product']];
    if ($exactHierarchy !== null) {
        $exactRule['hierarchical'] = $exactHierarchy;
    }
    $policy = new Duo\Policy();
    $policy->manifests = [[
        'name' => 'taxonomy-owner',
        'taxonomies' => ['pa_color' => $exactRule],
        'taxonomy_patterns' => [[
            'match' => '^pa_',
            'object_type' => ['product'],
            'hierarchical' => $patternHierarchy,
        ]],
    ]];
    return $policy;
};
$check(
    $exactPatternHierarchy(null, false)->declared_taxonomy_hierarchical('pa_color') === false
        && $exactPatternHierarchy(null, true)->declared_taxonomy_hierarchical('pa_color') === true
        && $exactPatternHierarchy(false, true)->declared_taxonomy_hierarchical('pa_color') === false
        && $exactPatternHierarchy(true, false)->declared_taxonomy_hierarchical('pa_color') === true,
    'exact hierarchy facts take strict precedence while an omitted exact fact falls through to the reviewed pattern fact'
);

$policy = new Duo\Policy();
$policy->manifests = [
    ['name' => 'woocommerce', 'taxonomies' => [
        'product_cat' => ['class' => 'authored', 'object_type' => ['product'], 'update_count_callback' => '_wc_term_recount', 'hierarchical' => true],
    ], 'taxonomy_patterns' => [
        ['match' => '^pa_', 'object_type' => ['product'], 'update_count_callback' => '_update_post_term_count', 'hierarchical' => false],
    ]],
];
$check(
    $policy->declared_object_type('product_cat') === ['product']
        && $policy->declared_update_count_callback('product_cat') === '_wc_term_recount'
        && $policy->declared_object_type('pa_color') === ['product']
        && $policy->declared_update_count_callback('pa_color') === '_update_post_term_count'
        && $policy->declared_taxonomy_hierarchical('product_cat') === true
        && $policy->declared_taxonomy_hierarchical('pa_color') === false
        && $policy->declared_taxonomy_hierarchical('unknown_tax') === null
        && $policy->declared_object_type('unknown_tax') === null
        && $policy->declared_update_count_callback('unknown_tax') === null
        && $policy->pattern_object_type('product_cat') === null,
    'Policy::declared_object_type()/declared_update_count_callback() consult the exact declaration first, then the pattern fallback, and leave the pattern-only facades untouched'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
