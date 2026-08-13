<?php
/**
 * Offline characterization for pure taxonomy option-derived object-type
 * declaration resolution (DUO-3348 slice 51).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/TaxonomyObjectTypeOptionResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\TaxonomyObjectTypeOptionResolver::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load stays independent of Policy, RepositoryCompiler, and WordPress'
);

require_once $resolverPath;

use Duo\TaxonomyObjectTypeOptionResolver;

$manifests = [
    [
        'name' => 'first',
        'taxonomies' => [
            'first_wins' => ['object_type_from_option' => ['option' => 'first_option', 'sub_key' => 'first_key']],
            'projected' => ['object_type_from_option' => ['option' => 17, 'sub_key' => 9]],
        ],
    ],
    [
        'name' => 'second',
        'taxonomies' => [
            'first_wins' => ['object_type_from_option' => ['option' => 'second_option', 'sub_key' => 'second_key']],
            'later_only' => ['object_type_from_option' => ['option' => 'later_option', 'sub_key' => 'later_key']],
        ],
    ],
];
$resolver = new TaxonomyObjectTypeOptionResolver($manifests);
$check(
    $resolver->resolve('first_wins') === ['option' => 'first_option', 'sub_key' => 'first_key']
        && $resolver->resolve('later_only') === ['option' => 'later_option', 'sub_key' => 'later_key']
        && $resolver->resolve('projected') === ['option' => '17', 'sub_key' => '9']
        && $resolver->resolve('absent') === null,
    'resolver preserves first-manifest precedence, later declarations, exact string projection, and absent null semantics'
);

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/OptionState.php";
require_once "$root/agent/src/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->object_type_option_ref('first_wins') === $resolver->resolve('first_wins')
        && $policy->object_type_option_ref('later_only') === $resolver->resolve('later_only'),
    'Policy retains its public option-derived object-type facade over the pure resolver'
);
$policy->manifests[0]['taxonomies']['first_wins']['object_type_from_option'] = [
    'option' => 'mutated_option', 'sub_key' => 'mutated_key',
];
$check(
    $policy->object_type_option_ref('first_wins') === ['option' => 'mutated_option', 'sub_key' => 'mutated_key'],
    'Policy builds a fresh resolver for every facade call so mutable fixture manifests stay observable'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy.php");
$oldLoop = '        foreach ($this->manifests as $m) {' . "\n"
    . "            \$decl = \$m['taxonomies'][\$tax]['object_type_from_option'] ?? null;";
$check(
    substr_count($policySource, "require_once __DIR__ . '/TaxonomyObjectTypeOptionResolver.php';") === 1
        && str_contains($policySource, 'return $this->taxonomy_object_type_option_resolver()->resolve($tax);')
        && str_contains($policySource, 'new TaxonomyObjectTypeOptionResolver($this->manifests)')
        && !str_contains($policySource, $oldLoop),
    'Policy requires the resolver once, retains a fresh facade factory, and leaves no duplicate raw manifest lookup loop'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
