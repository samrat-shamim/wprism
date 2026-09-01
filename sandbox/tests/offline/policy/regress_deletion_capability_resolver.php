<?php
/**
 * Offline characterization for the pure manifest deletion-capability resolver
 * (issue #3348 slice 46).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$resolverPath = "$root/agent/src/Policy/DeletionCapabilityResolver.php";
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
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\WPrism\DeletionCapabilityResolver::class, false) && class_exists(\WPrism\OptionNameReferenceResolver::class, false) && !class_exists(\WPrism\Policy::class, false) && !class_exists(\WPrism\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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

use WPrism\DeletionCapabilityResolver;
use WPrism\OptionNameReferenceResolver;

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
$lockColumn = $manifests;
$lockColumn[0]['deletions']['table:things']['guards'][0]['lock_column'] = 'active';
$lockCapability = (new DeletionCapabilityResolver(
    $lockColumn,
    $optionRules($lockColumn),
    ['string', 'csv']
))->capability('table:things');
$check(
    ($lockCapability['guards'][0]['lock_column'] ?? null) === 'active',
    'a guard may lock an exact indexed where predicate instead of its unindexed reference-value column'
);
$badLockColumn = $lockColumn;
$badLockColumn[0]['deletions']['table:things']['guards'][0]['lock_column'] = 'missing';
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $badLockColumn,
        $optionRules($badLockColumn),
        ['string', 'csv']
    ))->capability('table:things'),
    'lock_column must name an exact where predicate',
    'an unrelated lock column cannot claim a next-key boundary'
);

$absenceEmpty = $manifests;
$absenceEmpty[0]['tables']['children'] = ['class' => 'runtime'];
$absenceEmpty[0]['deletions']['table:things']['guards'][0]['table_absence'] = 'empty';
$absenceCapability = (new DeletionCapabilityResolver(
    $absenceEmpty,
    $optionRules($absenceEmpty),
    ['string', 'csv']
))->capability('table:things');
$check(
    ($absenceCapability['guards'][0]['table_absence'] ?? null) === 'empty',
    'a same-manifest table may explicitly declare the absence-means-empty topology contract'
);
foreach ([false, true, 'optional'] as $invalidAbsence) {
    $badAbsence = $absenceEmpty;
    $badAbsence[0]['deletions']['table:things']['guards'][0]['table_absence'] = $invalidAbsence;
    $assertThrows(
        static fn() => (new DeletionCapabilityResolver(
            $badAbsence,
            $optionRules($badAbsence),
            ['string', 'csv']
        ))->capability('table:things'),
        'table_absence must be empty',
        'table_absence is the exact enum empty, never a truthy or optional-like hint'
    );
}
$foreignAbsence = $absenceEmpty;
unset($foreignAbsence[0]['tables']['children']);
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $foreignAbsence,
        $optionRules($foreignAbsence),
        ['string', 'csv']
    ))->capability('table:things'),
    'may only describe a table declared by the same manifest',
    'absence-means-empty authority cannot be borrowed from another manifest or an undeclared table'
);
$mixedAbsence = $absenceEmpty;
$mixedAbsence[0]['deletions']['table:things']['guards'][] = $guard();
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $mixedAbsence,
        $optionRules($mixedAbsence),
        ['string', 'csv']
    ))->capability('table:things'),
    'disagrees on table_absence',
    'one selector cannot treat the same physical guard table as both required and absence-means-empty'
);

$closed = $manifests;
foreach ($closed as $position => &$manifest) {
    $manifest['plugin'] = "fixture-$position/plugin.php";
    $manifest['deletions']['table:things']['executable_owner_boundary'] = 'all_active_owners';
    $manifest['deletions']['table:things']['executable_owner_identities'] = [
        "plugin:fixture-$position/plugin.php" => [[
            'format' => 'wprism-executable-tree/v1',
            'root' => "plugins/fixture-$position",
            'sha256' => str_repeat((string) ($position + 1), 64),
        ]],
    ];
}
$closed[1]['theme'] = 'fixture-theme';
$closed[1]['deletions']['table:things']['executable_owner_identities']['theme:fixture-theme'] = [[
    'format' => 'wprism-executable-tree/v1',
    'root' => 'themes/fixture-theme',
    'sha256' => str_repeat('3', 64),
]];
unset($manifest);
$closedCapability = (new DeletionCapabilityResolver($closed, $optionRules($closed), ['string', 'csv']))
    ->capability('table:things');
$check(
    ($closedCapability['executable_owner_boundary'] ?? null) === 'all_active_owners'
        && ($closedCapability['declaring_executable_owners'] ?? null) === [
            'plugin:fixture-0/plugin.php',
            'plugin:fixture-1/plugin.php',
            'theme:fixture-theme',
        ]
        && ($closedCapability['declaring_executable_owner_identities'] ?? null) === [
            'plugin:fixture-0/plugin.php' => [[
                'format' => 'wprism-executable-tree/v1',
                'root' => 'plugins/fixture-0',
                'sha256' => str_repeat('1', 64),
            ]],
            'plugin:fixture-1/plugin.php' => [[
                'format' => 'wprism-executable-tree/v1',
                'root' => 'plugins/fixture-1',
                'sha256' => str_repeat('2', 64),
            ]],
            'theme:fixture-theme' => [[
                'format' => 'wprism-executable-tree/v1',
                'root' => 'themes/fixture-theme',
                'sha256' => str_repeat('3', 64),
            ]],
        ],
    'closed deletion authority enumerates every participating owner and exact reviewed executable identity'
);
$withoutExecutableOwnerBoundary = static function (array $declaration): array {
    unset($declaration['executable_owner_boundary'], $declaration['executable_owner_identities']);
    return $declaration;
};
$missingOwnerIdentity = $closed;
unset(
    $missingOwnerIdentity[1]['deletions']['table:things']['executable_owner_identities']['theme:fixture-theme']
);
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $missingOwnerIdentity,
        $optionRules($missingOwnerIdentity),
        ['string', 'csv']
    ))->capability('table:things'),
    'must cover exactly its declaring plugin/theme owners',
    'closed deletion authority refuses a declaring owner with no reviewed executable identity'
);
$wrongOwnerRoot = $closed;
$wrongOwnerRoot[0]['deletions']['table:things']['executable_owner_identities']['plugin:fixture-0/plugin.php'][0]['root']
    = 'plugins/not-fixture-0';
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $wrongOwnerRoot,
        $optionRules($wrongOwnerRoot),
        ['string', 'csv']
    ))->capability('table:things'),
    'identity[0] is malformed',
    'reviewed executable identities cannot name a root outside their exact owner'
);
$legacyBoundary = $closed;
$legacyBoundary[0]['deletions']['table:things'] = $withoutExecutableOwnerBoundary(
    $legacyBoundary[0]['deletions']['table:things']
);
$legacyBoundary[0]['deletions']['table:things']['active_plugin_boundary'] = 'declarers_only';
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $legacyBoundary,
        $optionRules($legacyBoundary),
        ['string', 'csv']
    ))->capability('table:things'),
    'active_plugin_boundary is retired',
    'the active-plugins-only boundary cannot silently survive as deletion authority'
);
$mixedBoundary = $closed;
$mixedBoundary[1]['deletions']['table:things'] = $withoutExecutableOwnerBoundary(
    $mixedBoundary[1]['deletions']['table:things']
);
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $mixedBoundary,
        $optionRules($mixedBoundary),
        ['string', 'csv']
    ))->capability('table:things'),
    'every declaration',
    'all co-owners must agree on a closed executable-owner boundary'
);
$reverseMixedBoundary = array_reverse($mixedBoundary);
$assertThrows(
    static fn() => (new DeletionCapabilityResolver(
        $reverseMixedBoundary,
        $optionRules($reverseMixedBoundary),
        ['string', 'csv']
    ))->capability('table:things'),
    'every declaration',
    'executable-owner boundary disagreement refuses independently of manifest order'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Delete/Deletion.php";

$policy = new WPrism\Policy();
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

$closedPolicy = new WPrism\Policy();
$closedPolicy->manifests = [[
    'name' => 'closed-product-owner',
    'plugin' => 'shop/shop.php',
    'deletions' => ['post:product' => [
        'executable_owner_boundary' => 'all_active_owners',
        'executable_owner_identities' => ['plugin:shop/shop.php' => [[
            'format' => 'wprism-executable-tree/v1',
            'root' => 'plugins/shop',
            'sha256' => str_repeat('a', 64),
        ]]],
        'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
        'guards' => [],
    ]],
]];
$check(
    WPrism\Deletion::capability($closedPolicy, 'post', 'product')['declaring_executable_owners']
        === ['plugin:shop/shop.php'],
    'static deletion authority exposes its exact executable owner without consulting cached activation facts'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
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
