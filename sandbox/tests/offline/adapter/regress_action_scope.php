<?php
declare(strict_types=1);

/**
 * Offline regression for exact manifest ACTION selection (DUO-3338) and
 * Apply's canonical surface projection. No WordPress target or WP-CLI is
 * contacted.
 *
 * The selection semantics under test are deliberately identical to the ones
 * the retired free-form `rebuilders` channel had — scoped declarations fire
 * only on an exact canonical-surface match, un-triggered declarations remain
 * unscoped, and an empty surface set fires nothing — because DUO-3338 was a
 * channel migration, not a behavior change. The probe fixture uses the closed
 * native action so the harness needs no provider code on disk.
 */

define('DUO_SPEC_VERSION', 2);
require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';
require dirname(__DIR__, 4) . '/agent/src/Repository/SidebarState.php';
require dirname(__DIR__, 4) . '/agent/src/Repository/Snapshot.php';
require dirname(__DIR__, 4) . '/agent/src/Apply/Apply.php';
require __DIR__ . '/../../lib/frozen_policy.php';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
};

$effect = static function (string $id): array {
    return [
        'id' => $id,
        'kind' => 'database',
        'mode' => 'restorable',
        'selector' => [
            'scope' => 'database_checkpoint',
            'type' => 'table',
            'value' => 'options',
        ],
    ];
};

$manifest = [
    'name' => 'trigger-probe',
    'spec_version' => DUO_SPEC_VERSION,
    'plugin' => 'trigger-probe/trigger-probe.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'actions' => [
        [
            'kind' => 'native',
            'action' => 'transient.delete',
            'args' => ['name' => 'probe_product'],
            'triggers' => ['post:product'],
            'effects' => [$effect('probe-product')],
        ],
        [
            'kind' => 'native',
            'action' => 'transient.delete',
            'args' => ['name' => 'probe_legacy'],
            'effects' => [$effect('probe-legacy')],
        ],
        [
            'kind' => 'native',
            'action' => 'transient.delete',
            'args' => ['name' => 'probe_category'],
            'triggers' => ['term:product_cat'],
            'effects' => [$effect('probe-category')],
        ],
        [
            'kind' => 'native',
            'action' => 'transient.delete',
            'args' => ['name' => 'probe_woo_option'],
            'triggers' => ['option:woocommerce_calc_taxes'],
            'effects' => [$effect('probe-woo-option')],
        ],
    ],
];

$policy = \DuoTest\FrozenPolicy::policy([$manifest], [
    'manifests' => ['trigger-probe'],
    'spec_version' => DUO_SPEC_VERSION,
    'policy' => [
        'options' => ['active_plugins' => ['class' => 'managed', 'autoload' => 'preserve']],
        'post_meta' => [],
        'term_meta' => [],
        'user_meta' => [],
    ],
]);

// Identify a selected row by the one field that distinguishes these four
// declarations from each other: the transient each names.
$selected = static fn(array $rows): array => array_values(array_map(
    static fn(array $row): string => (string) $row['args']['name'],
    $rows
));

$check(
    $selected($policy->actions_for(['post:product'])) === ['probe_product', 'probe_legacy'],
    'an exact post surface selects its scoped action and the unscoped one'
);
$check(
    $selected($policy->actions_for(['post:product_variation'])) === ['probe_legacy'],
    'near-match post types do not select an exact trigger'
);
$check(
    $selected($policy->actions_for(['term:product_cat'])) === ['probe_legacy', 'probe_category'],
    'term selection preserves declaration order across manifests'
);
$check(
    $selected($policy->actions_for(['option:woocommerce_currency'])) === ['probe_legacy'],
    'an unrelated option surface does not select the option-scoped action'
);
$check(
    $selected($policy->actions_for(['option:woocommerce_calc_taxes'])) === ['probe_legacy', 'probe_woo_option'],
    'the exact option surface selects its scoped action'
);
$check(
    $policy->actions_for([]) === [],
    'an empty/no-op surface set fires no action'
);

$expectThrow = static function (array $badManifest, string $needle, string $label) use ($check): void {
    try {
        \DuoTest\FrozenPolicy::policy(
            [$badManifest],
            \DuoTest\FrozenPolicy::site([$badManifest], DUO_SPEC_VERSION)
        );
        $check(false, "$label is rejected before selection");
    } catch (Throwable $failure) {
        $check(str_contains($failure->getMessage(), $needle), "$label is rejected before selection");
    }
};
$bad = $manifest;
$bad['actions'][0]['triggers'] = ['post:*'];
$expectThrow($bad, 'exact canonical surface', 'wildcard trigger');
$bad = $manifest;
$bad['actions'][0]['triggers'] = ['post:product:42'];
$expectThrow($bad, 'exact canonical surface', 'id-bearing trigger');
$bad = $manifest;
$bad['actions'][0]['triggers'] = ['post:product', 'post:product'];
$expectThrow($bad, 'repeats exact surface', 'duplicate trigger');
$bad = $manifest;
$bad['actions'][0]['unexpected'] = true;
$expectThrow($bad, 'unknown key', 'unknown action key');

$apply = new \Duo\ApplyPlanner(
    $policy,
    [],
    static fn(string $uuid, string $kind): ?int => null,
    static fn(string $uuid, string $kind): ?int => null
);
$surfaceMethod = new class($policy) {
    public function __construct(private readonly \Duo\Policy $policy) {}
    public function invoke(mixed $_, array $work, array $tree, array $deletions = []): array {
        return \Duo\CanonicalSurfaces::for_apply($work, $tree, $deletions, $this->policy);
    }
};
$rebuildWorkMethod = new ReflectionMethod(\Duo\ApplyPlanner::class, 'rebuild_work');
$optionNamesMethod = new ReflectionMethod(\Duo\ApplyPlanner::class, 'option_rebuild_names');
$optionDelta = $optionNamesMethod->invoke(
    $apply,
    [
        'format' => 'duo-options/v1',
        'records' => [
            'woocommerce_calc_taxes' => ['state' => 'present', 'autoload' => 'yes', 'value' => true],
            'woocommerce_currency' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'USD'],
            'woocommerce_noop' => ['state' => 'absent'],
            'active_plugins' => ['state' => 'present', 'autoload' => 'yes', 'value' => ['probe/plugin.php']],
        ],
    ],
    [
        'content' => \Duo\Canon::encode([
            'format' => 'duo-options/v1',
            'records' => [
                'woocommerce_calc_taxes' => ['state' => 'present', 'autoload' => 'yes', 'value' => false],
                'woocommerce_currency' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'EUR'],
                'woocommerce_noop' => ['state' => 'absent'],
                'target_only_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'preserve'],
                'active_plugins' => ['state' => 'present', 'autoload' => 'yes', 'value' => []],
            ],
        ]),
    ]
);
$check(
    $optionDelta === ['woocommerce_calc_taxes', 'woocommerce_currency'],
    'option delta projection excludes target-only, absent, and lifecycle-managed records'
);
$freshOptionDelta = $optionNamesMethod->invoke(
    $apply,
    [
        'format' => 'duo-options/v1',
        'records' => [
            'woocommerce_calc_taxes' => ['state' => 'present', 'autoload' => 'yes', 'value' => true],
            'woocommerce_noop' => ['state' => 'absent'],
            'active_plugins' => ['state' => 'present', 'autoload' => 'yes', 'value' => ['probe/plugin.php']],
        ],
    ],
    null
);
$check(
    $freshOptionDelta === ['woocommerce_calc_taxes'],
    'fresh-target option projection still excludes absent and lifecycle-managed records'
);
$tree = [
    'post-uuid' => ['type' => 'post', 'data' => ['type' => 'product']],
    'term-uuid' => ['type' => 'term', 'data' => ['taxonomy' => 'product_cat']],
    'options/core' => ['type' => 'options', 'data' => [
        'format' => 'duo-options/v1',
        'records' => [
            'woocommerce_calc_taxes' => ['state' => 'present', 'autoload' => 'yes', 'value' => true],
            'woocommerce_currency' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'USD'],
            'woocommerce_noop' => ['state' => 'absent'],
            'active_plugins' => ['state' => 'present', 'autoload' => 'yes', 'value' => ['probe/plugin.php']],
        ],
    ]],
    'table-uuid' => ['type' => 'woocommerce_shipping_zones', 'data' => []],
    'unknown-uuid' => ['type' => 'user-meta', 'data' => []],
];
$surfaces = $surfaceMethod->invoke(
    $apply,
    [
        ['uuid' => 'post-uuid'],
        ['uuid' => 'term-uuid'],
        ['uuid' => 'options/core', 'rebuild_option_names' => ['woocommerce_currency']],
        ['uuid' => 'table-uuid'],
        ['uuid' => 'unknown-uuid'],
    ],
    $tree,
    [
        ['deletion_kind' => 'post', 'deletion_type' => 'product_variation'],
        ['deletion_kind' => 'term', 'deletion_type' => 'product_cat'],
        ['deletion_kind' => 'table', 'deletion_type' => 'woocommerce_tax_rates'],
        ['deletion_kind' => 'option', 'deletion_type' => 'woocommerce_calc_taxes'],
    ]
);
$expectedSurfaces = [
    'entity:user-meta',
    'option:woocommerce_calc_taxes',
    'option:woocommerce_currency',
    'post:product',
    'post:product_variation',
    'table:woocommerce_shipping_zones',
    'table:woocommerce_tax_rates',
    'term:product_cat',
];
$check($surfaces === $expectedSurfaces, 'Apply derives deterministic exact surfaces for changed, deleted, and retryable entities');

$unrelatedOptions = $surfaceMethod->invoke(
    $apply,
    [['uuid' => 'options/core', 'rebuild_option_names' => ['woocommerce_currency']]],
    $tree,
    []
);
$check(
    $selected($policy->actions_for($unrelatedOptions)) === ['probe_legacy'],
    'Apply carries only the touched unrelated option and leaves the option-scoped action skipped'
);
$retryOptions = $surfaceMethod->invoke(
    $apply,
    [['uuid' => 'options/core', 'retry' => true]],
    $tree,
    []
);
$check(
    $selected($policy->actions_for($retryOptions)) === ['probe_legacy', 'probe_woo_option'],
    'an incomplete-apply retry widens the options row to all canonical records'
);
$check(!in_array('option:woocommerce_noop', $retryOptions, true),
    'retry widening still excludes explicit absent option intent');
$filteredOptionSurfaces = $surfaceMethod->invoke(
    $apply,
    [['uuid' => 'options/core', 'rebuild_option_names' => ['woocommerce_noop', 'active_plugins']]],
    $tree,
    []
);
$check(
    $filteredOptionSurfaces === [] && $selected($policy->actions_for($filteredOptionSurfaces)) === [],
    'all-absent or lifecycle-managed option work contributes no surface and fires no action'
);

// build_plan() and run() must derive provider selection from the same
// work/delete/retry projection. Exercise the private shared helper directly
// here rather than building a database-backed plan: the assertions show which
// exact action declarations either caller would negotiate.
$projectionPlan = [
    'create' => [['uuid' => 'post-uuid']],
    'adopt' => [],
    'update' => [['uuid' => 'options/core', 'rebuild_option_names' => ['woocommerce_currency']]],
    'conflict' => [],
    'delete' => [[
        'uuid' => 'term-uuid',
        'deletion_kind' => 'term',
        'deletion_type' => 'product_cat',
    ]],
    'delete_conflict' => [[
        'uuid' => 'options/core',
        'deletion_kind' => 'option',
        'deletion_type' => 'woocommerce_calc_taxes',
    ]],
    'deleted' => [[
        'uuid' => 'options/core',
        'deletion_kind' => 'option',
        'deletion_type' => 'woocommerce_calc_taxes',
    ]],
];
$projectionSurfaces = static function (array $projection) use ($apply, $surfaceMethod, $tree): array {
    return $surfaceMethod->invoke(
        $apply,
        $projection['work'],
        $tree,
        $projection['rebuild_delete_work']
    );
};
$ordinaryProjection = $rebuildWorkMethod->invoke($apply, $projectionPlan, $tree, [], false);
$ordinaryProjectionSurfaces = $projectionSurfaces($ordinaryProjection);
$check(
    $selected($policy->actions_for($ordinaryProjectionSurfaces))
        === ['probe_product', 'probe_legacy', 'probe_category'],
    'shared rebuild_work projection selects changed post, unrelated option, and planned term-delete actions exactly'
);

$driftOnlyPlan = [
    'create' => [],
    'adopt' => [],
    'update' => [],
    'drift' => [['uuid' => 'options/core', 'rebuild_option_names' => ['woocommerce_currency']]],
    'conflict' => [],
    'delete' => [],
    'delete_conflict' => [],
    'deleted' => [],
];
$ordinaryDriftProjection = $rebuildWorkMethod->invoke($apply, $driftOnlyPlan, $tree, [], false);
$scopedPromotionDriftProjection = $rebuildWorkMethod->invoke($apply, $driftOnlyPlan, $tree, [], false, true);
$check(
    $ordinaryDriftProjection['work'] === [],
    'ordinary and local scoped apply leave environment-only drift outside authored rebuild work'
);
$check(
    count($scopedPromotionDriftProjection['work']) === 1
        && ($scopedPromotionDriftProjection['work'][0]['uuid'] ?? null) === 'options/core',
    'receipt-bearing scoped promotion explicitly promotes selected drift into its bounded authored work set'
);
$check(
    $selected($policy->actions_for($projectionSurfaces($scopedPromotionDriftProjection)))
        === ['probe_legacy'],
    'scoped promotion preflight and receipt-bearing apply diagnose the same action from selected drift'
);

$forcedProjection = $rebuildWorkMethod->invoke($apply, $projectionPlan, $tree, ['force_theirs' => true], false);
$check(
    $selected($policy->actions_for($projectionSurfaces($forcedProjection)))
        === ['probe_product', 'probe_legacy', 'probe_category', 'probe_woo_option'],
    'shared rebuild_work projection includes a forced conflicting deletion before selecting its exact option action'
);

$retryProjection = $rebuildWorkMethod->invoke($apply, $projectionPlan, $tree, [], true);
$check(
    $selected($policy->actions_for($projectionSurfaces($retryProjection)))
        === ['probe_product', 'probe_legacy', 'probe_category', 'probe_woo_option'],
    'shared rebuild_work projection includes retry tombstones before selecting their exact action'
);

$emptyProjection = $rebuildWorkMethod->invoke($apply, [
    'create' => [], 'adopt' => [], 'update' => [], 'conflict' => [],
    'delete' => [], 'delete_conflict' => [], 'deleted' => [],
], $tree, [], false);
$check(
    $projectionSurfaces($emptyProjection) === []
        && $policy->actions_for($projectionSurfaces($emptyProjection)) === [],
    'shared rebuild_work projection keeps an empty plan action-free'
);

$unrelatedProjection = $rebuildWorkMethod->invoke($apply, [
    'create' => [],
    'adopt' => [],
    'update' => [['uuid' => 'unknown-uuid']],
    'conflict' => [],
    'delete' => [[
        'uuid' => 'table-uuid',
        'deletion_kind' => 'table',
        'deletion_type' => 'woocommerce_shipping_zones',
    ]],
    'delete_conflict' => [],
    'deleted' => [],
], $tree, [], false);
$check(
    $selected($policy->actions_for($projectionSurfaces($unrelatedProjection))) === ['probe_legacy'],
    'shared rebuild_work projection leaves unrelated changed/delete surfaces to the unscoped action only'
);

$retrySurfaces = $surfaceMethod->invoke(
    $apply,
    [],
    [],
    [['deletion_kind' => 'table', 'deletion_type' => 'woocommerce_attribute_taxonomies']]
);
$check(
    $selected($policy->actions_for($retrySurfaces)) === ['probe_legacy'],
    'a retry-only tombstone still contributes a surface while unrelated scoped actions stay skipped'
);

exit($failures === 0 ? 0 : 1);
