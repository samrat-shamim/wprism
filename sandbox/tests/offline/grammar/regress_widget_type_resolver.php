<?php
/**
 * Offline characterization for pure widget-type declaration resolution
 * (issue #3348 slice 52).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Grammar/WidgetTypeResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\WPrism\\WidgetTypeResolver::class, false) && !class_exists(\\WPrism\\Policy::class, false) && !class_exists(\\WPrism\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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

use WPrism\WidgetTypeResolver;

$manifests = [
    [
        'name' => 'first',
        'widgets' => [
            'zeta' => ['class' => 'authored', 'json_refs' => [['path' => '$.first', 'kind' => 'post']]],
            'shared' => ['class' => 'authored'],
            'scalar_fixture' => 'historical-array-cast',
        ],
    ],
    [
        'name' => 'second',
        'widgets' => [
            'alpha' => ['class' => 'env'],
            'shared' => ['class' => 'derived'],
        ],
    ],
];
$resolver = new WidgetTypeResolver($manifests);
$check(
    array_keys($resolver->types()) === ['alpha', 'scalar_fixture', 'shared', 'zeta']
        && $resolver->types()['shared'] === ['class' => 'derived']
        && $resolver->types()['scalar_fixture'] === [0 => 'historical-array-cast']
        && $resolver->details('shared') === ['rule' => ['class' => 'derived'], 'source' => 'second']
        && $resolver->details('zeta') === ['rule' => $manifests[0]['widgets']['zeta'], 'source' => 'first']
        && $resolver->details('scalar_fixture') === ['rule' => null, 'source' => null]
        && $resolver->details('absent') === ['rule' => null, 'source' => null],
    'resolver preserves lexical registry order, last-pin rules/provenance, historical array casting, and absent details'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new WPrism\Policy();
$policy->manifests = $manifests;
$check(
    $policy->widget_types() === $resolver->types()
        && $policy->widget_type_rule_details('shared') === $resolver->details('shared'),
    'Policy retains its public widget registry and provenance facades over the pure resolver'
);
$policy->manifests[1]['widgets']['shared'] = ['class' => 'env', 'json_refs' => []];
$check(
    $policy->widget_types()['shared'] === ['class' => 'env', 'json_refs' => []]
        && $policy->widget_type_rule_details('shared') === [
            'rule' => ['class' => 'env', 'json_refs' => []], 'source' => 'second',
        ],
    'Policy constructs a fresh resolver per call so mutable fixture manifests remain observable'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$oldTypesLoop = '        foreach ($this->manifests as $manifest) {' . "\n"
    . "            foreach ((array) (\$manifest['widgets'] ?? []) as \$type => \$rule) {";
$oldDetailsLoop = '        foreach ($this->manifests as $manifest) {' . "\n"
    . "            if (isset(\$manifest['widgets'][\$type]) && is_array(\$manifest['widgets'][\$type])) {";
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/WidgetTypeResolver.php';") === 1
        && str_contains($policySource, 'return $this->widget_type_resolver()->types();')
        && str_contains($policySource, 'return $this->widget_type_resolver()->details($type);')
        && str_contains($policySource, 'new WidgetTypeResolver($this->manifests)')
        && !str_contains($policySource, $oldTypesLoop)
        && !str_contains($policySource, $oldDetailsLoop),
    'Policy requires the resolver once, retains fresh facades, and leaves no duplicate widget precedence loops'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
