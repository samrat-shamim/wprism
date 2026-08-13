<?php
/**
 * Offline characterization for pure taxonomy description-reference grammar
 * resolution (DUO-3348 slice 49).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/TaxonomyDescriptionReferenceResolver.php";
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
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\TaxonomyDescriptionReferenceResolver::class, false) && class_exists(\\Duo\\ReferenceRules::class, false) && class_exists(\\Duo\\ReferencePath::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load closes ReferenceRules and ReferencePath only, never Policy, RepositoryCompiler, or WordPress'
);

require_once $resolverPath;

use Duo\TaxonomyDescriptionReferenceResolver;

$manifests = [
    [
        'name' => 'first',
        'taxonomies' => [
            'legacy' => ['description_refs' => ['kind' => 'term']],
            'structured' => ['description_refs' => [
                'json_refs' => [['path' => '$.primary', 'kind' => 'post']],
                'key_refs' => ['path' => '$.by_id', 'kind' => 'term'],
            ]],
            'first_wins' => ['description_refs' => ['kind' => 'post']],
        ],
    ],
    [
        'name' => 'second',
        'taxonomies' => [
            'first_wins' => ['description_refs' => ['kind' => 'term']],
        ],
    ],
];
$resolver = new TaxonomyDescriptionReferenceResolver($manifests);
$check(
    $resolver->resolve('legacy') === [
        'json_refs' => [['path' => '$.*', 'kind' => 'term']],
        'key_refs' => null,
        'legacy_flat_map' => true,
    ]
        && $resolver->resolve('structured') === [
            'json_refs' => [['path' => '$.primary', 'kind' => 'post']],
            'key_refs' => ['path' => '$.by_id', 'kind' => 'term'],
            'legacy_flat_map' => false,
        ]
        && $resolver->resolve('first_wins')['json_refs'][0]['kind'] === 'post'
        && $resolver->resolve('missing') === null,
    'resolver preserves legacy/full normalization, manifest pin order, and absent-taxonomy null semantics'
);

$malformed = [[
    'name' => 'bad-source',
    'taxonomies' => ['broken' => ['description_refs' => ['kind' => 'Bad Kind']]],
]];
$assertThrows(
    static fn() => (new TaxonomyDescriptionReferenceResolver($malformed))->resolve('broken'),
    "manifest 'bad-source'.taxonomies.broken.description_refs.kind",
    'malformed declarations retain the exact manifest-bearing ReferenceRules refusal source'
);

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/OptionState.php";
require_once "$root/agent/src/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->description_reference_rule('legacy') === $resolver->resolve('legacy')
        && $policy->description_refs_for_taxonomy('structured') === $resolver->resolve('structured'),
    'Policy retains both current and deprecated public facades over the pure resolver'
);
$policy->manifests[0]['taxonomies']['legacy']['description_refs'] = ['kind' => 'post'];
$check(
    $policy->description_reference_rule('legacy')['json_refs'][0]['kind'] === 'post',
    'Policy builds a fresh resolver for each facade call so public fixture mutations are observed'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy.php");
$check(
    substr_count($policySource, "require_once __DIR__ . '/TaxonomyDescriptionReferenceResolver.php';") === 1
        && str_contains($policySource, 'return $this->taxonomy_description_reference_resolver()->resolve($tax);')
        && str_contains($policySource, 'new TaxonomyDescriptionReferenceResolver($this->manifests)')
        && str_contains($policySource, 'return $this->description_reference_rule($tax);')
        && !str_contains($policySource, '        foreach ($this->manifests as $m) {' . "\n"
            . "            if (isset(\$m['taxonomies'][\$tax]['description_refs'])) {"),
    'Policy requires the resolver once, retains both facades and fresh factory, and leaves no duplicate raw manifest lookup loop'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
