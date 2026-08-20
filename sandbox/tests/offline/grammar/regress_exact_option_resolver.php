<?php
/** Offline characterization of Policy's exact option projection (DUO-3348 slice 55). */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Grammar/ExactOptionResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\ExactOptionResolver::class, false) && class_exists(\\Duo\\PolicyRuleResolver::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load closes only its pure PolicyRuleResolver dependency'
);

require_once $resolverPath;

use Duo\ExactOptionResolver;
use Duo\PolicyRuleResolver;

$withOptionAutoload = static function (array $rule, array $source): array {
    if (!array_key_exists('autoload', $rule) && array_key_exists('option_autoload', $source)) {
        $rule['autoload'] = $source['option_autoload'];
    }
    return $rule;
};
$site = [
    'policy' => [
        'option_autoload' => 'no',
        'options' => [
            'site_authored' => ['class' => 'authored'],
            'shared' => ['class' => 'env', 'required' => true],
        ],
    ],
];
$manifests = [
    [
        'name' => 'core',
        'option_autoload' => 'preserve',
        'options' => [
            'alpha' => ['class' => 'authored'],
            'shared' => ['class' => 'runtime'],
            'subkeyed' => ['class' => 'env', 'required' => false, 'sub_keys' => ['locale' => ['class' => 'authored']]],
        ],
        'option_patterns' => [['match' => '^pattern_', 'class' => 'authored']],
    ],
    [
        'name' => 'plugin',
        'option_autoload' => 'yes',
        'options' => [
            'shared' => ['class' => 'derived'],
            'zeta' => ['class' => 'env', 'required' => true],
        ],
    ],
];
$rules = new PolicyRuleResolver($site, $manifests, $withOptionAutoload);
$resolver = new ExactOptionResolver($site, $manifests, $rules);

$check(
    $resolver->all() === [
        'alpha' => ['class' => 'authored', 'autoload' => 'preserve'],
        'shared' => ['class' => 'env', 'required' => true, 'autoload' => 'no'],
        'site_authored' => ['class' => 'authored', 'autoload' => 'no'],
        'subkeyed' => ['class' => 'env', 'required' => false, 'sub_keys' => ['locale' => ['class' => 'authored']], 'autoload' => 'preserve'],
        'zeta' => ['class' => 'env', 'required' => true, 'autoload' => 'yes'],
    ],
    'exact declarations are deduplicated, resolve through existing precedence, inherit autoload, and sort lexically'
);
$check(
    $resolver->authored() === [
        'alpha' => ['class' => 'authored', 'autoload' => 'preserve'],
        'site_authored' => ['class' => 'authored', 'autoload' => 'no'],
    ]
        && $resolver->env() === [
            'shared' => ['class' => 'env', 'required' => true, 'autoload' => 'no'],
            'subkeyed' => ['class' => 'env', 'required' => false, 'sub_keys' => ['locale' => ['class' => 'authored']], 'autoload' => 'preserve'],
            'zeta' => ['class' => 'env', 'required' => true, 'autoload' => 'yes'],
        ]
        && $resolver->sub_keyed() === [
            'subkeyed' => ['class' => 'env', 'required' => false, 'sub_keys' => ['locale' => ['class' => 'authored']], 'autoload' => 'preserve'],
        ]
        && !isset($resolver->all()['pattern_example']),
    'authored, env, and sub-key inventories share the exact-only projection without enumerating patterns'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new Duo\Policy();
$policy->site = $site;
$policy->manifests = $manifests;
$check(
    $policy->authored_options() === $resolver->authored()
        && $policy->env_options() === $resolver->env()
        && $policy->sub_keyed_options() === $resolver->sub_keyed(),
    'Policy preserves all three public exact-option inventory facades'
);
$policy->site['policy']['options']['site_authored'] = ['class' => 'env', 'required' => false];
$policy->manifests[1]['options']['late_authored'] = ['class' => 'authored'];
$check(
    !isset($policy->authored_options()['site_authored'])
        && isset($policy->env_options()['site_authored'])
        && isset($policy->authored_options()['late_authored']),
    'Policy constructs a fresh exact-option resolver so mutable fixture declarations remain observable'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$oldCollection = "        \$names = [];\n        foreach (\$this->manifests as \$manifest) {";
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/ExactOptionResolver.php';") === 1
        && substr_count($policySource, 'return $this->exact_option_resolver()->authored();') === 1
        && substr_count($policySource, 'return $this->exact_option_resolver()->env();') === 1
        && substr_count($policySource, 'return $this->exact_option_resolver()->sub_keyed();') === 1
        && substr_count($policySource, 'new ExactOptionResolver(') === 1
        && !str_contains($policySource, $oldCollection),
    'Policy has one direct resolver factory, three thin facades, and no retained exact-option collection body'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
