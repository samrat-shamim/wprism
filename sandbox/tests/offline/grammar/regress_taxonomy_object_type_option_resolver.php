<?php
/**
 * Offline characterization for pure taxonomy option-derived object-type
 * declaration resolution (DUO-3348 slice 51).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Grammar/TaxonomyObjectTypeOptionResolver.php";
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

$resolverSource = (string) file_get_contents($resolverPath);
$check(
    !str_contains($resolverSource, 'public function resolve(string $tax)')
        && str_contains($resolverSource, 'public function resolve_all(string $tax)'),
    'resolver exposes only the complete declaration list and has no lossy singular projection'
);

$manifests = [
    [
        'name' => 'first',
        'taxonomies' => [
            'first_wins' => ['object_type_from_option' => [
                ['option' => 'first_option', 'sub_key' => 'first_key'],
                [
                    'option' => 'first_option',
                    'sub_key' => 'media_enabled',
                    'object_types_when_truthy' => ['attachment'],
                ],
            ]],
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
$firstRows = [
    ['option' => 'first_option', 'sub_key' => 'first_key'],
    [
        'option' => 'first_option',
        'sub_key' => 'media_enabled',
        'object_types_when_truthy' => ['attachment'],
    ],
];
$check(
    $resolver->resolve_all('first_wins') === $firstRows
        && $resolver->resolve_all('later_only') === [['option' => 'later_option', 'sub_key' => 'later_key']]
        && $resolver->resolve_all('projected') === [['option' => '17', 'sub_key' => '9']]
        && $resolver->resolve_all('absent') === null,
    'plural resolver preserves every ordered direct and truthy compiled-option contribution'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->object_type_option_refs('first_wins') === $firstRows
        && $policy->object_type_option_refs('later_only') === [['option' => 'later_option', 'sub_key' => 'later_key']],
    'Policy exposes the complete apply-time declaration list without a lossy singular facade'
);
$policy->manifests[0]['taxonomies']['first_wins']['object_type_from_option'] = [
    'option' => 'mutated_option', 'sub_key' => 'mutated_key',
];
$check(
    $policy->object_type_option_refs('first_wins') === [['option' => 'mutated_option', 'sub_key' => 'mutated_key']],
    'Policy builds a fresh resolver for every plural call so mutable fixture manifests stay observable'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$oldLoop = '        foreach ($this->manifests as $m) {' . "\n"
    . "            \$decl = \$m['taxonomies'][\$tax]['object_type_from_option'] ?? null;";
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/TaxonomyObjectTypeOptionResolver.php';") === 1
        && str_contains($policySource, 'return $this->taxonomy_object_type_option_resolver()->resolve_all($tax) ?? [];')
        && str_contains($policySource, 'new TaxonomyObjectTypeOptionResolver($this->manifests)')
        && !str_contains($policySource, 'object_type_option_ref(string $tax)')
        && !str_contains($policySource, $oldLoop),
    'Policy requires the resolver once, exposes only the complete declaration list, and leaves no duplicate raw manifest lookup loop'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
