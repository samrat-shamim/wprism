<?php
/**
 * Offline characterization of Policy's pure exact/pattern rule-selection
 * kernel (issue #3348 slice 54).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Policy/PolicyRuleResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\WPrism\\PolicyRuleResolver::class, false) && !class_exists(\\WPrism\\Policy::class, false) && !class_exists(\\WPrism\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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

use WPrism\PolicyRuleResolver;

$withOptionAutoload = static function (array $rule, array $source): array {
    if (!array_key_exists('autoload', $rule) && array_key_exists('option_autoload', $source)) {
        $rule['autoload'] = $source['option_autoload'];
    }
    return $rule;
};

$site = [
    'policy' => [
        'option_autoload' => 'no',
        'options' => ['site_exact' => ['class' => 'authored']],
        'post_meta' => ['site_meta' => ['class' => 'derived']],
    ],
];
$manifests = [
    [
        'name' => 'core',
        'option_autoload' => 'preserve',
        'options' => [
            'shared' => ['class' => 'authored'],
            'core_only' => ['class' => 'runtime'],
        ],
        'post_meta' => ['core_meta' => ['class' => 'authored']],
        'term_meta' => ['term_meta' => ['class' => 'env']],
        'option_patterns' => [['match' => '^option_pattern_', 'class' => 'env']],
        'post_meta_patterns' => [['match' => '^post_only_pattern_', 'class' => 'derived']],
        'meta_patterns' => [['match' => '^meta_pattern_', 'class' => 'runtime']],
    ],
    [
        'name' => 'plugin',
        'option_autoload' => 'yes',
        'options' => ['shared' => ['class' => 'derived']],
    ],
];
$resolver = new PolicyRuleResolver($site, $manifests, $withOptionAutoload);

$check(
    $resolver->details('options', 'site_exact') === [
        'rule' => ['class' => 'authored', 'autoload' => 'no'],
        'source' => 'site.wprism.json',
    ]
        && $resolver->details('options', 'shared') === [
            'rule' => ['class' => 'derived', 'autoload' => 'yes'],
            'source' => 'plugin',
        ]
        && $resolver->details('options', 'core_only') === [
            'rule' => ['class' => 'runtime', 'autoload' => 'preserve'],
            'source' => 'core',
        ]
        && $resolver->details('post_meta', 'site_meta') === [
            'rule' => ['class' => 'derived'],
            'source' => 'site.wprism.json',
        ],
    'site overrides, core-yields-to-plugin precedence, source provenance, and option-autoload normalization remain exact'
);

$check(
    $resolver->details('options', 'option_pattern_example') === [
        'rule' => ['class' => 'env', 'autoload' => 'preserve'],
        'source' => 'core',
    ]
        && $resolver->details('post_meta', 'meta_pattern_example') === [
            'rule' => ['class' => 'runtime'],
            'source' => 'core',
        ]
        && $resolver->details('post_meta', 'post_only_pattern_example') === [
            'rule' => ['class' => 'derived'],
            'source' => 'core',
        ]
        && $resolver->details('term_meta', 'post_only_pattern_example') === ['rule' => null, 'source' => null]
        && $resolver->details('term_meta', 'meta_pattern_example') === [
            'rule' => ['class' => 'runtime'],
            'source' => 'core',
        ]
        && $resolver->details('user_meta', 'meta_pattern_example') === ['rule' => null, 'source' => null]
        && PolicyRuleResolver::pattern_keys() === [
            'options' => ['option_patterns'],
            'post_meta' => ['post_meta_patterns', 'meta_patterns'],
            'term_meta' => ['meta_patterns'],
        ],
    'pattern fallback strips match, preserves first-manifest order, keeps post-only patterns off term meta, and retains the published key map'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new WPrism\Policy();
$policy->site = $site;
$policy->manifests = $manifests;
$check(
    $policy->option_rule_details('shared') === $resolver->details('options', 'shared')
        && $policy->post_meta_rule_details('meta_pattern_example') === $resolver->details('post_meta', 'meta_pattern_example')
        && $policy->term_meta_rule('meta_pattern_example') === $resolver->details('term_meta', 'meta_pattern_example')['rule']
        && $policy->user_meta_rule('meta_pattern_example') === null
        && WPrism\Policy::closed_vocabularies()['pattern_keys'] === PolicyRuleResolver::pattern_keys(),
    'Policy preserves public exact/pattern rule facades and publishes the resolver-owned pattern vocabulary'
);
$policy->site['policy']['options']['site_exact'] = ['class' => 'runtime'];
$policy->manifests[1]['options']['plugin_late'] = ['class' => 'authored'];
$check(
    $policy->option_rule_details('site_exact') === [
        'rule' => ['class' => 'runtime', 'autoload' => 'no'],
        'source' => 'site.wprism.json',
    ]
        && $policy->option_rule('plugin_late') === ['class' => 'authored', 'autoload' => 'yes'],
    'Policy constructs a fresh resolver per query so mutable fixture site/manifests remain observable'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$oldResolverBody = "        \$sitePolicy = \$this->site['policy'][\$section][\$name] ?? null;";
$check(
    substr_count($policySource, "require_once __DIR__ . '/PolicyRuleResolver.php';") === 1
        && str_contains($policySource, 'return $this->policy_rule_resolver()->details($section, $name);')
        && str_contains($policySource, 'new PolicyRuleResolver(')
        && str_contains($policySource, "'pattern_keys' => PolicyRuleResolver::pattern_keys()")
        && !str_contains($policySource, $oldResolverBody),
    'Policy has one direct resolver dependency/factory and retains no duplicate rule-selection body'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
