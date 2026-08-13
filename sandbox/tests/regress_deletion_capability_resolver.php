<?php
/**
 * Offline characterization for the pure manifest deletion-capability resolver
 * (DUO-3348 slice 46).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/DeletionCapabilityResolver.php";
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
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\Duo\DeletionCapabilityResolver::class, false) && class_exists(\Duo\OptionNameReferenceResolver::class, false) && !class_exists(\Duo\Policy::class, false) && !class_exists(\Duo\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load closes only option-name grammar, never Policy, RepositoryCompiler, or WordPress'
);

require_once $resolverPath;

use Duo\DeletionCapabilityResolver;
use Duo\OptionNameReferenceResolver;

$optionRules = static function (array $manifests): OptionNameReferenceResolver {
    return new OptionNameReferenceResolver($manifests, static fn(array $rule, array $_source): array => $rule);
};
$guard = static function (array $extra = []): array {
    return $extra + [
        'table' => 'children',
        'column' => 'parent_id',
        'id_kind' => 'thing',
        'source_id_kind' => 'child',
        'source_pk' => 'child_id',
    ];
};
$manifests = [
    [
        'name' => 'first',
        'option_name_refs' => [[
            'match' => '^thing_(?<id>[1-9][0-9]*)$', 'id_kind' => 'thing', 'class' => 'authored',
        ]],
        'deletions' => [
            'table:things' => [
                'cascades' => ['rows', 'children', 'rows'],
                'guards' => [
                    $guard(['where' => ['active' => 1]]),
                    [
                        'table' => 'postmeta', 'column' => 'post_id', 'id_kind' => 'post',
                        'meta_key' => '_children', 'ref' => 'post[]', 'cast' => 'csv',
                        'source_id_kind' => 'post', 'source_pk' => 'post_id', 'identity_column' => 'meta_id',
                    ],
                ],
            ],
        ],
    ],
    [
        'name' => 'second',
        'deletions' => [
            'table:things' => [
                'cascades' => ['children', 'rows'],
                'guards' => [[
                    'table' => 'options', 'column' => 'option_name', 'id_kind' => 'thing',
                    'identity_column' => 'option_id', 'option_name_ref' => true,
                ]],
            ],
        ],
    ],
];
$resolver = new DeletionCapabilityResolver($manifests, $optionRules($manifests), ['string', 'csv']);
$capability = $resolver->capability('table:things');
$check(
    $capability === [
        'cascades' => ['children', 'rows'],
        'guards' => [
            $guard(['where' => ['active' => 1]]),
            [
                'table' => 'postmeta', 'column' => 'post_id', 'id_kind' => 'post',
                'meta_key' => '_children', 'ref' => 'post[]', 'cast' => 'csv',
                'source_id_kind' => 'post', 'source_pk' => 'post_id', 'identity_column' => 'meta_id',
            ],
            [
                'table' => 'options', 'column' => 'option_name', 'id_kind' => 'thing',
                'identity_column' => 'option_id', 'option_name_ref' => true,
            ],
        ],
        'declared_by' => ['first', 'second'],
    ] && $resolver->capability('table:missing') === null,
    'resolver normalizes agreeing cascades, preserves guard/declaration order, and leaves unknown selectors unsupported'
);

$conflict = $manifests;
$conflict[1]['deletions']['table:things']['cascades'] = ['other'];
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($conflict, $optionRules($conflict), ['string', 'csv']))->capability('table:things'),
    'pinned manifests disagree on cascade effects',
    'cascade disagreement refuses instead of choosing pin order'
);
$missingRule = $manifests;
unset($missingRule[0]['option_name_refs']);
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($missingRule, $optionRules($missingRule), ['string', 'csv']))->capability('table:things'),
    'no loaded authored option_name_refs rule',
    'option-name guards refuse when their authored id-kind declaration is absent'
);
$badOptionTarget = $manifests;
$badOptionTarget[1]['deletions']['table:things']['guards'][0]['table'] = 'posts';
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($badOptionTarget, $optionRules($badOptionTarget), ['string', 'csv']))->capability('table:things'),
    'must target options.option_name',
    'option-name guards refuse a non-option target'
);
$badOptionQualifier = $manifests;
$badOptionQualifier[1]['deletions']['table:things']['guards'][0]['where'] = ['active' => 1];
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($badOptionQualifier, $optionRules($badOptionQualifier), ['string', 'csv']))->capability('table:things'),
    'cannot declare table-row source or predicate qualifiers',
    'option-name guards refuse table-row predicates'
);
$badMeta = $manifests;
$badMeta[0]['deletions']['table:things']['guards'][1]['ref'] = 'term';
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($badMeta, $optionRules($badMeta), ['string', 'csv']))->capability('table:things'),
    'metadata guard must target postmeta with a ref matching id_kind',
    'metadata guards refuse a mismatched reference kind'
);
$badCast = $manifests;
$badCast[0]['deletions']['table:things']['guards'][1]['cast'] = 'json';
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($badCast, $optionRules($badCast), ['string', 'csv']))->capability('table:things'),
    'cast must be string or csv',
    'metadata guards use only Policy supplied cast vocabulary'
);
$badPredicate = $manifests;
$badPredicate[0]['deletions']['table:things']['guards'][0]['where'] = ['not-an-object'];
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($badPredicate, $optionRules($badPredicate), ['string', 'csv']))->capability('table:things'),
    'where must be an object',
    'guard predicates refuse list-shaped declarations'
);
$badSource = $manifests;
unset($badSource[0]['deletions']['table:things']['guards'][0]['source_pk']);
$assertThrows(
    static fn() => (new DeletionCapabilityResolver($badSource, $optionRules($badSource), ['string', 'csv']))->capability('table:things'),
    'must declare source_id_kind and source_pk together',
    'table-row guards refuse half-declared source identity'
);

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/OptionState.php";
require_once "$root/agent/src/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->deletion_capability('table:things') === $capability,
    'Policy retains its public deletion-capability facade over the pure resolver'
);
$policy->manifests[0]['deletions']['table:things']['cascades'] = ['changed'];
$policy->manifests[1]['deletions']['table:things']['cascades'] = ['changed'];
$check(
    ($policy->deletion_capability('table:things')['cascades'] ?? null) === ['changed'],
    'Policy builds a fresh resolver for each facade call so public fixture mutations are observed'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy.php");
$check(
    substr_count($policySource, "require_once __DIR__ . '/DeletionCapabilityResolver.php';") === 1
        && str_contains($policySource, 'return $this->deletion_capability_resolver()->capability($selector);')
        && str_contains($policySource, 'new DeletionCapabilityResolver(')
        && str_contains($policySource, 'self::CASTS')
        && !str_contains($policySource, '$decl = $manifest[\'deletions\'][$selector] ?? null;'),
    'Policy requires the resolver once, retains only the explicit facade, and keeps its cast vocabulary at the compatibility port'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
