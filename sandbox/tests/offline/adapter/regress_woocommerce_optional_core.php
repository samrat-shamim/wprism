<?php
/** Exact WooCommerce 11.0.0/11.0.1 optional-core storage boundary. */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int {
        return (int) ($GLOBALS['wooOptionalBlogId'] ?? 1);
    }
}

if (!function_exists('get_taxonomies')) {
    /** @return array<string,string> */
    function get_taxonomies(array|string $args = [], string $output = 'names', string $operator = 'and'): array {
        return [];
    }
}

if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): false {
        return false;
    }
}

if (!class_exists('WC_Settings_API', false)) {
    abstract class WC_Settings_API {
        private function canonical(string $key, string $value): string {
            $GLOBALS['wooMixedNativeCalls'][] = $key;
            $value = trim(stripslashes($value));
            if (($GLOBALS['wooMixedMutateField'] ?? null) === $key) {
                $value .= '-native-drift';
            }
            return $value;
        }

        public function validate_text_field($key, $value): string {
            return $this->canonical((string) $key, (string) ($value ?? ''));
        }

        public function validate_safe_text_field(string $key, ?string $value): string {
            $GLOBALS['wooMixedNativeCalls'][] = $key;
            $value = strip_tags(stripslashes((string) $value), '<br><img><p><span>');
            if (($GLOBALS['wooMixedMutateField'] ?? null) === $key) {
                $value .= '-native-drift';
            }
            return $value;
        }

        public function validate_textarea_field($key, $value): string {
            return $this->canonical((string) $key, strip_tags((string) ($value ?? ''), '<a><br><em><p><span><strong>'));
        }

        public function validate_checkbox_field($key, $value): string {
            $GLOBALS['wooMixedNativeCalls'][] = (string) $key;
            return $value === null ? 'no' : 'yes';
        }

        public function validate_select_field($key, $value): string {
            return $this->canonical((string) $key, strip_tags((string) ($value ?? '')));
        }
    }
}

if (!class_exists('WC_Shipping_Zones', false)) {
    // The COD interpreter must not call this resolver. Woo constructs a
    // shipping method through it, which is extension/hook-capable rather than
    // an inert identity lookup. The raw-table witness below is its replacement.
    class WC_Shipping_Zones {
        public static int $resolverCalls = 0;

        public static function get_shipping_method(int $instanceId): object|false {
            ++self::$resolverCalls;
            throw new RuntimeException('effectful Woo shipping resolver must stay unused');
        }
    }
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../support/woocommerce_mixed_option_hooks.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ScopeDiscovery.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Capture/OptionsCapture.php';

use Duo\OptionsCapture;
use Duo\Policy;
use Duo\ScopeDiscovery;
use Duo\Tokens;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

if (!function_exists('wc_sanitize_permalink')) {
    function wc_sanitize_permalink(mixed $value): string {
        return untrailingslashit(str_replace('http://', '', trim((string) $value)));
    }
}

$GLOBALS['wp_filter'] = [];
$GLOBALS['WC_Brands_Admin'] = new WC_Brands_Admin();
$GLOBALS['wooMixedOptionContainer'] = new WooMixedOptionContainer();

/** @param list<array{0:object|string,1:string,2:int,3:int}> $rows */
function woo_optional_install_hook(string $name, array $rows): void {
    global $wp_filter;
    $hook = new WP_Hook();
    foreach ($rows as $index => [$object, $method, $priority, $acceptedArgs]) {
        $hook->callbacks[$priority]['callback-' . $index] = [
            'function' => is_string($object) ? $object : [$object, $method],
            'accepted_args' => $acceptedArgs,
        ];
    }
    $wp_filter[$name] = $hook;
}

function woo_optional_clear_hooks(): void {
    $GLOBALS['wp_filter'] = [];
}

final class WooOptionalWakeupCanary {
    public static int $wakeups = 0;

    public function __wakeup(): void {
        ++self::$wakeups;
    }
}

$root = dirname(__DIR__, 4);
$manifest = json_decode(
    (string) file_get_contents($root . '/manifests/woocommerce.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$dispositions = [
    'manifests' => [
        'woocommerce' => json_decode(
            (string) file_get_contents($root . '/manifests/dispositions/woocommerce.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        ),
    ],
];
$inventory = json_decode(
    (string) file_get_contents($root . '/sandbox/tests/fixtures/woocommerce-core-11.0-optional.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$settingsInventory = json_decode(
    (string) file_get_contents($root . '/sandbox/tests/fixtures/woocommerce-core-11.0-settings.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$artifactLock = json_decode(
    (string) file_get_contents($root . '/sandbox/conformance/artifacts.lock.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);

duo_check_same(
    'duo-woocommerce-optional-core-inventory/v1',
    $inventory['format'] ?? null,
    'the optional-core source union has one explicit schema'
);
duo_check_same(
    ['11.0.0', '11.0.1'],
    array_keys((array) ($inventory['artifacts'] ?? [])),
    'the optional-core inventory admits only the two exact reviewed artifacts'
);
foreach ((array) ($inventory['artifacts'] ?? []) as $version => $sha256) {
    duo_check_same(
        $sha256,
        $artifactLock['plugins']['woocommerce'][$version]['sha256'] ?? null,
        "optional-core source evidence is pinned to the official WooCommerce $version artifact"
    );
}
duo_check_same(
    32,
    count((array) ($inventory['source_files'] ?? [])),
    'the inventory binds all 32 exact optional-core storage writers, readers, and registries'
);
foreach ((array) ($inventory['source_files'] ?? []) as $path => $sha256) {
    duo_check(
        is_string($path) && $path !== ''
            && is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', $sha256) === 1,
        "$path carries one reviewed byte-identical 11.0.0/11.0.1 source digest"
    );
}
duo_check_same(
    [
        'includes/class-woocommerce.php' => [
            '11.0.0' => '5982ef2ab60231218cc71a2ba9bd387496d32c1a5eeb5468116d51137bbd7ef4',
            '11.0.1' => '2f3a95ae78217be16fa1f272c1fad4d3faecfd02939041a861d65826bb3f4cb7',
        ],
        'src/Internal/OrderReviews/Endpoint.php' => [
            '11.0.0' => 'dda95b0edb8ac48434477aaf4664f267b477f858ab44da248752557f91bea9c8',
            '11.0.1' => '326511d748cc282caac2bfaeb22451e5b80f73209dcdbea81c2a4ef32570b6f3',
        ],
    ],
    $inventory['version_specific_source_files'] ?? null,
    'the optional review-route bootstrap and endpoint behavior bind both exact version-specific artifacts'
);
duo_check_same(
    [
        'block_email_editor',
        'cli_migrator',
        'customer_review_requests',
        'customer_stock_notifications',
        'email_unsubscribes',
        'fulfillments',
        'push_notifications',
        'variation_gallery',
    ],
    array_keys((array) ($inventory['families'] ?? [])),
    'every source-audited optional-core persistence family is machine-enumerated'
);

$policy = Policy::load(null, ['woocommerce']);

$reviewFamily = (array) ($inventory['families']['customer_review_requests'] ?? []);
duo_check_same(
    ['class' => 'authored', 'ref' => 'post', 'autoload' => 'preserve'],
    $policy->option_rule('woocommerce_review_order_page_id'),
    'the customer review host page crosses the portable post identity ledger'
);
duo_check_same(
    'runtime',
    $policy->option_rule('woocommerce_review_order_flush_rewrite_pending')['class'] ?? null,
    'the one-request review-route flush marker remains target runtime'
);
duo_check_same(
    [
        'feature_options' => ['woocommerce_feature_customer_review_request_enabled'],
        'authored_options' => ['woocommerce_review_order_page_id' => 'post_reference'],
        'runtime_options' => ['woocommerce_review_order_flush_rewrite_pending'],
        'derived_options' => ['rewrite_rules'],
        'native_action' => 'rewrite.flush',
    ],
    $reviewFamily,
    'the optional-core inventory closes the feature, authored page, runtime marker, and derived rewrite split'
);
$reviewActions = $policy->actions_for(['option:woocommerce_review_order_page_id']);
duo_check_same(1, count($reviewActions), 'a review-page identity change selects one bounded native convergence action');
duo_check_same(
    ['kind' => 'native', 'action' => 'rewrite.flush', 'args' => []],
    array_intersect_key((array) ($reviewActions[0] ?? []), array_flip(['kind', 'action', 'args'])),
    'the review page reuses the hardened fresh-process rewrite action without a manifest-controlled payload'
);

foreach (['wc_email_sync_backfill_completed_tracked', 'woocommerce_email_template_sync_backfill_complete'] as $name) {
    duo_check_same('runtime', $policy->option_rule($name)['class'] ?? null, "$name is target-local sync state");
}
foreach ((array) ($inventory['families']['customer_stock_notifications']['runtime_options'] ?? []) as $name) {
    duo_check_same('runtime', $policy->option_rule((string) $name)['class'] ?? null,
        "$name is exact target-local stock-notification UI/runtime state");
}
foreach ((array) ($inventory['families']['customer_stock_notifications']['filter_hooks'] ?? []) as $hookName) {
    duo_check_same(null, $policy->option_rule((string) $hookName),
        "$hookName is a filter hook, not a silently invented portable option");
}
$stockEmailOptions = (array) ($inventory['families']['customer_stock_notifications']['email_setting_options'] ?? []);
duo_check_same([
    'woocommerce_customer_stock_notification_settings',
    'woocommerce_customer_stock_notification_verified_settings',
    'woocommerce_customer_stock_notification_verify_settings',
], $stockEmailOptions, 'all three feature-gated stock-notification email settings records are source-enumerated');
$cycleFamily = (array) ($inventory['families']['customer_stock_notifications']['runtime_option_patterns'] ?? []);
duo_check_same(
    ['wc_stock_notifications_cycle_state_<product-id>' => 'canonical_positive_php_integer'],
    $cycleFamily,
    'per-product stock-delivery cycle state has one exact runtime option-name grammar'
);
foreach (['1', '811', (string) PHP_INT_MAX] as $productId) {
    $name = 'wc_stock_notifications_cycle_state_' . $productId;
    duo_check_same(
        'runtime',
        $policy->owned_option_rule_via_interpreter($name, [$name => 'runtime-state'])['class'] ?? null,
        "$name stays target-local through the exact native product-id suffix"
    );
}
foreach (['', '0', '01', '-1', '+1', '1.0', '9223372036854775808', '1_suffix', '١'] as $suffix) {
    $name = 'wc_stock_notifications_cycle_state_' . $suffix;
    duo_check_same(
        null,
        $policy->owned_option_rule_via_interpreter($name, [$name => 'hostile-state']),
        "$name cannot widen the native per-product cycle-state family"
    );
}
foreach (['wc_migrator_analytics', 'wc_migrator_products_count'] as $name) {
    duo_check_same('runtime', $policy->option_rule($name)['class'] ?? null, "$name is target-local migration progress");
}
foreach (['shopify', 'webflow', 'partner_extension'] as $platform) {
    duo_check_same(
        'env',
        $policy->option_rule("wc_migrator_credentials_$platform")['class'] ?? null,
        "filtered migrator platform $platform keeps its credential record target-sovereign"
    );
}
foreach ([
    'wc_migrator_credentials_',
    'wc_migrator_credentials_Bad',
    'wc_migrator_credentials_bad/slash',
    'wc_migrator_credentials_' . str_repeat('x', 65),
] as $nearMiss) {
    duo_check_same(null, $policy->option_rule($nearMiss), "$nearMiss cannot widen the closed credential family");
}
duo_check_same('runtime', $manifest['post_types']['import_session']['class'] ?? null,
    'CLI import sessions and their source/progress metadata are explicitly runtime');
duo_check_same('runtime', $manifest['post_types']['wc_push_token']['class'] ?? null,
    'push-token posts and device secrets/PII are explicitly runtime');
duo_check(!array_key_exists('woo_email', $manifest['post_types'] ?? []),
    'Block Email Editor posts remain outside portable scope instead of silently dropping merchant content');
foreach (['woocommerce_email_templates_new_order_post_id', 'woocommerce_email_templates_addon_gateway_post_id'] as $name) {
    duo_check_same(null, $policy->option_rule($name), "$name remains an explicit unsupported post-reference boundary");
    duo_check_same('woocommerce', $policy->option_namespace($name)['owner'] ?? null,
        "$name remains discovery-owned and therefore fails loudly when populated");
}

$unsupported = [];
foreach ((array) ($dispositions['manifests']['woocommerce']['unsupported'] ?? []) as $row) {
    $unsupported[(string) ($row['surface'] ?? '')] = (string) ($row['operation'] ?? '');
}
duo_check_same('capture', $unsupported['post_types.woo_email'] ?? null,
    'the reviewed disposition names the populated Block Email Editor post boundary');
duo_check_same('capture', $unsupported['options.woocommerce_email_templates_*_post_id'] ?? null,
    'the reviewed disposition names the target-local Block Email Editor mapping boundary');
duo_check_same(
    'capture',
    $unsupported['options.woocommerce_google_analytics_settings|woocommerce_paymob-main_settings|woocommerce_ppec_paypal_settings|woocommerce_stripe_settings|woocommerce_woocommerce_payments_settings'] ?? null,
    'the reviewed disposition names every source-observed integration-owned settings record'
);
duo_check_same(
    'capture',
    $unsupported['options.woocommerce_table_rate_default_priority_*|woocommerce_table_rate_priorities_*'] ?? null,
    'the reviewed disposition names both legacy table-rate extension option families'
);
foreach ([
    'options.woocommerce_bacs_settings|woocommerce_cheque_settings|woocommerce_cod_settings',
    'options.woocommerce_<core-email-id>_settings',
] as $surface) {
    duo_check(!array_key_exists($surface, $unsupported),
        "$surface graduated from its populated-source refusal into the closed native registry");
}
duo_check_same(
    'env',
    $policy->option_rule('woocommerce_bacs_accounts')['class'] ?? null,
    'the separate BACS bank-detail list is target-environment-owned rather than a universal capture blocker'
);

$nativeContract = (array) ($settingsInventory['closed_records']['native_materialization_contract'] ?? []);
duo_check_same(
    ['normalize_captured_option_sub_keys', 'materialize_option_sub_keys', 'project_materialized_option_sub_keys'],
    $nativeContract['interpreter_methods'] ?? null,
    'the exact settings inventory binds capture normalization, native apply, and finalized-storage projection'
);
duo_check_same(
    [1048576, 262144],
    [$nativeContract['max_record_bytes'] ?? null, $nativeContract['max_text_bytes'] ?? null],
    'mixed settings records and text carry explicit aggregate bounds'
);
duo_check(
    ($nativeContract['native_validator'] ?? null) === 'WC_Settings_API'
        && ($nativeContract['native_validator_abstract'] ?? null) === true
        && (new ReflectionClass('WC_Settings_API'))->isAbstract()
        && ($nativeContract['native_validator_methods'] ?? null) === [
            'validate_checkbox_field',
            'validate_safe_text_field',
            'validate_select_field',
            'validate_text_field',
            'validate_textarea_field',
        ],
    'both exact artifacts bind the abstract WC_Settings_API authority and all inherited native validators'
);

$woocommerceInterpreter = $policy->interpreters()['woocommerce'] ?? null;
duo_check(is_object($woocommerceInterpreter), 'the digest-bound WooCommerce interpreter is available');
foreach ((array) ($nativeContract['interpreter_methods'] ?? []) as $method) {
    duo_check(method_exists($woocommerceInterpreter, (string) $method), "$method is implemented by shipped Woo bytes");
}

$mixedRules = static function (string $name) use ($settingsInventory): array {
    $gateway = $settingsInventory['closed_records']['gateway_settings'][$name] ?? null;
    if (is_array($gateway) && is_array($gateway['field_types'] ?? null)) {
        $fieldTypes = $gateway['field_types'];
    } else {
        $email = $settingsInventory['closed_records']['email_settings']['records'][$name] ?? null;
        if (!is_array($email)) {
            throw new RuntimeException("missing mixed option fixture for $name");
        }
        $allTypes = (array) $settingsInventory['closed_records']['email_settings']['field_types'];
        $fieldTypes = [];
        foreach ((array) ($email['fields'] ?? []) as $field) {
            $fieldTypes[(string) $field] = $allTypes[(string) $field] ?? null;
        }
    }
    $rules = [];
    foreach ($fieldTypes as $field => $type) {
        if ($type === 'cod_methods') {
            $rules[(string) $field] = [
                'class' => 'authored',
                'json_refs' => [['path' => '$.*.instance_id', 'kind' => 'wc_zone_method']],
            ];
            continue;
        }
        if ($type === 'derived_empty') {
            $rules[(string) $field] = ['class' => 'derived', 'native_default_completion' => true];
            continue;
        }
        $rules[(string) $field] = ['class' => $type === 'env_text' ? 'env' : 'authored'];
    }
    return $rules;
};

$mixedOptionNames = array_merge(
    ['woocommerce_bacs_settings', 'woocommerce_cheque_settings', 'woocommerce_cod_settings'],
    array_keys((array) ($settingsInventory['closed_records']['email_settings']['records'] ?? []))
);
foreach ($mixedOptionNames as $optionName) {
    $rule = $policy->option_rule((string) $optionName);
    duo_check(
        ($rule['class'] ?? null) === 'env'
            && ($rule['required'] ?? null) === false
            && ($rule['autoload'] ?? null) === 'preserve'
            && ($rule['absent_autoload'] ?? null) === 'yes'
            && ($rule['closed_sub_keys'] ?? null) === true
            && ($rule['sub_keys'] ?? null) === $mixedRules((string) $optionName),
        "$optionName resolves through the exact digest-bound closed sibling registry"
    );
}
duo_check_same(
    [
        'option_name',
        'raw_authored',
        'declared_sub_keys',
        'desired_authored_keys',
    ],
    $nativeContract['projection_arguments'] ?? null,
    'the Woo projector consumes the shared four-argument sparse-presence contract'
);

$materializeMixed = static function (
    string $name,
    array $captured,
    array $subKeys,
    string $autoload,
    ?array $targetValue
) use ($policy): array {
    $effectiveRule = $policy->option_rule($name);
    if (!is_array($effectiveRule) || ($effectiveRule['sub_keys'] ?? null) !== $subKeys) {
        throw new RuntimeException("missing exact manifest-owned mixed option rule for $name");
    }
    $written = null;
    $writeCalls = 0;
    $finalizeCalls = 0;
    $runtimeRestore = null;
    $handled = $policy->materialize_option_sub_keys_via_interpreter(
        $name,
        $captured,
        $effectiveRule,
        'woocommerce',
        $autoload,
        $targetValue,
        static function (string $companion): ?array {
            throw new RuntimeException("unexpected companion lock $companion");
        },
        static function () use ($name, $autoload, &$written, &$finalizeCalls): array {
            ++$finalizeCalls;
            return [
                'option_name' => $name,
                'option_value' => serialize($written),
                'autoload' => $autoload,
            ];
        },
        static function (): ?array {
            throw new RuntimeException('unexpected immediate storage restoration');
        },
        static function (Closure $restore) use (&$runtimeRestore): void {
            $runtimeRestore = $restore;
        },
        static function (array $value) use (&$written, &$writeCalls): void {
            ++$writeCalls;
            $written = $value;
        }
    );
    $rawAuthored = [];
    foreach ($subKeys as $field => $rule) {
        if (($rule['class'] ?? null) === 'authored' && array_key_exists((string) $field, (array) $written)) {
            $rawAuthored[(string) $field] = $written[(string) $field];
        }
    }
    $projected = $policy->project_materialized_option_sub_keys_via_interpreter(
        $name,
        $rawAuthored,
        $effectiveRule,
        'woocommerce',
        array_keys($captured)
    );
    return [
        'handled' => $handled,
        'written' => $written,
        'projected' => $projected,
        'write_calls' => $writeCalls,
        'finalize_calls' => $finalizeCalls,
        'runtime_restore' => $runtimeRestore,
    ];
};

$permalinkRules = [
    'product_base' => ['class' => 'authored'],
    'category_base' => ['class' => 'authored'],
    'tag_base' => ['class' => 'authored'],
    'attribute_base' => ['class' => 'authored'],
    'use_verbose_page_rules' => ['class' => 'authored'],
];
$permalinkRule = $policy->option_rule('woocommerce_permalinks');
duo_check(
    ($permalinkRule['class'] ?? null) === 'derived'
        && ($permalinkRule['required'] ?? null) === false
        && ($permalinkRule['autoload'] ?? null) === 'preserve'
        && !array_key_exists('absent_autoload', $permalinkRule)
        && ($permalinkRule['closed_sub_keys'] ?? null) === true
        && ($permalinkRule['sub_keys'] ?? null) === $permalinkRules,
    'the merchant product permalink record has one closed five-field native contract without inventing absent storage'
);
$permalinkSource = [
    'product_base' => 'shop/%product_cat%',
    'category_base' => 'catalog',
    'tag_base' => 'labels',
    'attribute_base' => 'features',
    'use_verbose_page_rules' => true,
];
$permalinkCaptured = $permalinkSource;
ksort($permalinkCaptured, SORT_STRING);
$permalinkNormalized = $woocommerceInterpreter->normalize_captured_option_sub_keys(
    'woocommerce_permalinks',
    $permalinkCaptured,
    $permalinkRules,
    ['woocommerce_permalinks' => serialize($permalinkSource)]
);
duo_check_same($permalinkCaptured, $permalinkNormalized,
    'exact native-order Woo permalink storage normalizes to canonical repository key order');
duo_check_same(
    [],
    $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_permalinks',
        [],
        $permalinkRules,
        []
    ),
    'a never-materialized clean-install permalink row stays absent instead of synthesizing translated defaults'
);
$sparsePermalink = $permalinkSource;
unset($sparsePermalink['use_verbose_page_rules']);
duo_check_throws(
    static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_permalinks',
        array_intersect_key($permalinkCaptured, $sparsePermalink),
        $permalinkRules,
        ['woocommerce_permalinks' => serialize($sparsePermalink)]
    ),
    RuntimeException::class,
    'sparse raw permalink storage refuses unless Woo native loading first completes the exact five-field row',
    'exact native five-field record'
);
$soleBrandBase = $permalinkSource;
$soleBrandBase['product_base'] = '/%product_brand%';
$soleBrandCaptured = $soleBrandBase;
ksort($soleBrandCaptured, SORT_STRING);
duo_check_throws(
    static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_permalinks',
        $soleBrandCaptured,
        $permalinkRules,
        ['woocommerce_permalinks' => serialize($soleBrandBase)]
    ),
    RuntimeException::class,
    'repository capture cannot bypass the exact Woo Brands product-base validator',
    'native Brands product-base guard'
);
duo_check_same(
    '/product/%product_brand%',
    $GLOBALS['WC_Brands_Admin']->validate_product_base($soleBrandBase)['product_base'] ?? null,
    'the pinned native Brands validator prefixes the otherwise-invalid sole brand placeholder'
);

$container = $GLOBALS['wooMixedOptionContainer'];
$customOrders = $container->get(
    \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
);
$synchronizer = $container->get(
    \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
);
$features = $container->get(
    \Automattic\WooCommerce\Internal\Features\FeaturesController::class
);
woo_optional_install_hook('pre_update_option_woocommerce_permalinks', [[
    $GLOBALS['WC_Brands_Admin'], 'validate_product_base', 10, 1,
]]);
woo_optional_install_hook('pre_update_option', [[
    $customOrders, 'process_pre_update_option', 999, 3,
]]);
woo_optional_install_hook('updated_option', [
    [$synchronizer, 'process_updated_option', 999, 3],
    [$customOrders, 'process_updated_option', 999, 3],
    [$customOrders, 'process_updated_option_fts_index', 999, 3],
    [$features, 'process_updated_option', 999, 3],
]);
$permalinkResult = $materializeMixed(
    'woocommerce_permalinks',
    $permalinkNormalized,
    $permalinkRules,
    'yes',
    ['product_base' => 'stale-only-sparse-target']
);
duo_check(
    $permalinkResult['handled'] === true
        && $permalinkResult['written'] === $permalinkSource
        && $permalinkResult['projected'] === $permalinkNormalized,
    'native permalink materialization completes a sparse hostile target through exact source-proven hook topology'
);
woo_optional_clear_hooks();

$foreignBrands = new WC_Brands_Admin();
woo_optional_install_hook('pre_update_option_woocommerce_permalinks', [[
    $foreignBrands, 'validate_product_base', 10, 1,
]]);
$hookWriteCalls = 0;
$hookFinalizeCalls = 0;
duo_check_throws(
    static fn() => $woocommerceInterpreter->materialize_option_sub_keys(
        'woocommerce_permalinks',
        $permalinkNormalized,
        $permalinkRules,
        'yes',
        $permalinkSource,
        static fn(string $companion): ?array => null,
        static function () use (&$hookFinalizeCalls): array {
            ++$hookFinalizeCalls;
            return [];
        },
        static fn(): ?array => null,
        static function (Closure $restore): void {},
        static function (array $value) use (&$hookWriteCalls): void {
            ++$hookWriteCalls;
        }
    ),
    RuntimeException::class,
    'a same-class foreign Brands validator refuses before direct permalink storage',
    'exact native service'
);
duo_check_same([0, 0], [$hookWriteCalls, $hookFinalizeCalls],
    'foreign specific hook topology reaches no mixed-option write or finalization');
woo_optional_clear_hooks();

$hostileHook = new class {
    public function mutate(mixed $value): mixed {
        return $value;
    }
};
woo_optional_install_hook('pre_update_option', [[
    $hostileHook, 'mutate', 10, 1,
]]);
$hookWriteCalls = 0;
duo_check_throws(
    static fn() => $woocommerceInterpreter->materialize_option_sub_keys(
        'woocommerce_permalinks',
        $permalinkNormalized,
        $permalinkRules,
        'yes',
        $permalinkSource,
        static fn(string $companion): ?array => null,
        static fn(): array => [],
        static fn(): ?array => null,
        static function (Closure $restore): void {},
        static function (array $value) use (&$hookWriteCalls): void {
            ++$hookWriteCalls;
        }
    ),
    RuntimeException::class,
    'a generic extension option callback refuses before direct permalink storage',
    'extension callback'
);
duo_check_same(0, $hookWriteCalls,
    'generic hostile hook topology is observed before the engine-owned write callback');
woo_optional_clear_hooks();
duo_check_same(
    $permalinkSource,
    $materializeMixed(
        'woocommerce_permalinks',
        $permalinkNormalized,
        $permalinkRules,
        'yes',
        $permalinkSource
    )['written'],
    'removing a hostile permalink callback permits an exact idempotent retry'
);

$emailTypeValues = [
    'checkbox' => 'yes',
    'delay_days' => '14',
    'email_type' => 'multipart',
    'env_text' => 'target-admin@example.test',
    'text' => 'مرحبا \\ merchant ✓',
    'textarea' => '<p>Merchant <strong>content</strong> ✓</p>',
];
$GLOBALS['wooMixedNativeCalls'] = [];
$emailRecords = (array) ($settingsInventory['closed_records']['email_settings']['records'] ?? []);
$emailFieldTypes = (array) ($settingsInventory['closed_records']['email_settings']['field_types'] ?? []);
$emailFieldClasses = (array) ($settingsInventory['closed_records']['email_settings']['field_classes'] ?? []);
foreach ($emailRecords as $optionName => $record) {
    $rawRecord = [];
    $captured = [];
    foreach ((array) ($record['fields'] ?? []) as $field) {
        $type = (string) ($emailFieldTypes[$field] ?? '');
        $value = $emailTypeValues[$type] ?? null;
        $rawRecord[(string) $field] = $value;
        if (($settingsInventory['closed_records']['email_settings']['field_classes'][$field] ?? null) === 'authored') {
            $captured[(string) $field] = $value;
        }
    }
    $rules = $mixedRules((string) $optionName);
    $absent = $woocommerceInterpreter->normalize_captured_option_sub_keys(
        (string) $optionName,
        [],
        $rules,
        []
    );
    duo_check_same([], $absent, "$optionName admits a never-saved clean-install source row");
    $normalized = $woocommerceInterpreter->normalize_captured_option_sub_keys(
        (string) $optionName,
        $captured,
        $rules,
        [(string) $optionName => serialize($rawRecord)]
    );
    $expected = $captured;
    ksort($expected, SORT_STRING);
    duo_check_same($expected, $normalized, "$optionName normalizes every portable native email field exactly");

    $target = [];
    foreach ($rules as $field => $rule) {
        if (($rule['class'] ?? null) === 'env') {
            $target[(string) $field] = 'target-recipient-DO_NOT_ECHO@example.test';
        }
    }
    $absentExpected = [];
    foreach ($rules as $field => $rule) {
        if (($rule['class'] ?? null) === 'env' && array_key_exists((string) $field, $rawRecord)) {
            $absentExpected[(string) $field] = $rawRecord[(string) $field];
        }
    }
    $absentResult = $materializeMixed((string) $optionName, $absent, $rules, 'on', $rawRecord);
    duo_check_same(
        $absentExpected,
        $absentResult['written'],
        "$optionName removes stale authored siblings while preserving a target-owned recipient on source absence"
    );
    $result = $materializeMixed((string) $optionName, $normalized, $rules, 'on', $target);
    duo_check(
        $result['handled'] === true
            && $result['write_calls'] === 1
            && $result['finalize_calls'] === 1
            && $result['runtime_restore'] instanceof Closure
            && $result['projected'] === $normalized,
        "$optionName uses one engine-owned write/finalization and an exact native projection"
    );
    if (isset($rules['recipient'])) {
        duo_check_same(
            'target-recipient-DO_NOT_ECHO@example.test',
            $result['written']['recipient'] ?? null,
            "$optionName preserves target recipient identity"
        );
    }
}
duo_check(count($GLOBALS['wooMixedNativeCalls']) >= count($emailRecords),
    'every exact email record crosses WC_Settings_API native validation');

$partialEmail = ['enabled' => 'yes'];
$partialEmailRules = $mixedRules('woocommerce_new_order_settings');
$partialEmailNormalized = $woocommerceInterpreter->normalize_captured_option_sub_keys(
    'woocommerce_new_order_settings',
    $partialEmail,
    $partialEmailRules,
    ['woocommerce_new_order_settings' => serialize($partialEmail)]
);
$partialEmailResult = $materializeMixed(
    'woocommerce_new_order_settings',
    $partialEmailNormalized,
    $partialEmailRules,
    'yes',
    ['recipient' => 'target@example.test', 'subject' => 'delete-stale-subject']
);
duo_check_same(
    ['recipient' => 'target@example.test', 'enabled' => 'yes'],
    $partialEmailResult['written'],
    'a valid partial native email record preserves its target recipient and removes omitted authored fields'
);
$partialEmailRepeat = $materializeMixed(
    'woocommerce_new_order_settings',
    $partialEmailNormalized,
    $partialEmailRules,
    'yes',
    $partialEmailResult['written']
);
duo_check_same($partialEmailResult['written'], $partialEmailRepeat['written'],
    'partial email materialization is byte-stable on repeat');

$gatewayRules = [
    'woocommerce_bacs_settings' => $mixedRules('woocommerce_bacs_settings'),
    'woocommerce_cheque_settings' => $mixedRules('woocommerce_cheque_settings'),
    'woocommerce_cod_settings' => $mixedRules('woocommerce_cod_settings'),
];
duo_check_same(
    ['account_details' => 'native_empty_ui_placeholder'],
    $settingsInventory['closed_records']['gateway_settings']['woocommerce_bacs_settings']['derived_fields'] ?? null,
    'BACS inventory includes the exact empty account_details placeholder persisted by its native admin save'
);
$absentGatewayTargets = [
    'woocommerce_bacs_settings' => [
        'enabled' => 'yes',
        'title' => 'Stale',
        'account_details' => '',
        'account_name' => 'TARGET-BANK-SECRET-DO_NOT-ECHO',
    ],
    'woocommerce_cheque_settings' => ['enabled' => 'yes', 'title' => 'Stale'],
    'woocommerce_cod_settings' => ['enabled' => 'yes', 'enable_for_methods' => ['flat_rate']],
];
foreach ($gatewayRules as $optionName => $rules) {
    $absent = $woocommerceInterpreter->normalize_captured_option_sub_keys($optionName, [], $rules, []);
    duo_check_same([], $absent, "$optionName admits a never-saved clean-install source row");
    $absentResult = $materializeMixed(
        $optionName,
        $absent,
        $rules,
        'no',
        $absentGatewayTargets[$optionName]
    );
    $expected = $optionName === 'woocommerce_bacs_settings'
        ? ['account_details' => '', 'account_name' => 'TARGET-BANK-SECRET-DO_NOT-ECHO']
        : [];
    duo_check_same(
        $expected,
        $absentResult['written'],
        "$optionName materializes source absence as authored deletion without erasing target-owned state"
    );
}
duo_check_throws(
    static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_cheque_settings',
        ['enabled' => 'yes'],
        $gatewayRules['woocommerce_cheque_settings'],
        []
    ),
    RuntimeException::class,
    'an absent raw row cannot disagree with nonempty captured authored siblings'
);
duo_check_same(
    [],
    $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_cheque_settings',
        [],
        $gatewayRules['woocommerce_cheque_settings'],
        ['woocommerce_cheque_settings' => serialize([])]
    ),
    'a present canonical empty record remains distinguishable from source-row absence'
);
$bacsSource = [
    'enabled' => 'yes',
    'title' => '<span>BACS \\ transfer</span>',
    'description' => '<p>Bank transfer</p>',
    'account_details' => '',
    'account_name' => 'SOURCE-BANK-SECRET-DO_NOT-ECHO',
];
$bacsCaptured = array_intersect_key($bacsSource, array_filter(
    $gatewayRules['woocommerce_bacs_settings'],
    static fn(array $rule): bool => ($rule['class'] ?? null) === 'authored'
));
$bacsNormalized = $woocommerceInterpreter->normalize_captured_option_sub_keys(
    'woocommerce_bacs_settings',
    $bacsCaptured,
    $gatewayRules['woocommerce_bacs_settings'],
    ['woocommerce_bacs_settings' => serialize($bacsSource)]
);
$bacsResult = $materializeMixed(
    'woocommerce_bacs_settings',
    $bacsNormalized,
    $gatewayRules['woocommerce_bacs_settings'],
    'yes',
    [
        'enabled' => 'no',
        'title' => 'Stale',
        'instructions' => 'delete-me',
        'account_details' => '',
        'account_name' => 'TARGET-BANK-SECRET-DO_NOT-ECHO',
    ]
);
duo_check_same('TARGET-BANK-SECRET-DO_NOT-ECHO', $bacsResult['written']['account_name'] ?? null,
    'BACS materialization preserves the target bank identity instead of copying source secrets');
duo_check(!array_key_exists('instructions', (array) $bacsResult['written']),
    'a source-absent authored BACS sibling deletes stale target content');
duo_check_same('', $bacsResult['written']['account_details'] ?? null,
    'BACS preserves only the exact target-local derived account-details placeholder');
$bacsRepeat = $materializeMixed(
    'woocommerce_bacs_settings',
    $bacsNormalized,
    $gatewayRules['woocommerce_bacs_settings'],
    'yes',
    $bacsResult['written']
);
duo_check_same($bacsResult['written'], $bacsRepeat['written'],
    'BACS authored/target-owned materialization is byte-stable on repeat');

$malformedBacsPlaceholder = $bacsSource;
$malformedBacsPlaceholder['account_details'] = 'not-native-empty';
duo_check_throws(
    static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_bacs_settings',
        $bacsCaptured,
        $gatewayRules['woocommerce_bacs_settings'],
        ['woocommerce_bacs_settings' => serialize($malformedBacsPlaceholder)]
    ),
    RuntimeException::class,
    'a nonempty BACS account_details placeholder refuses as non-native derived state'
);
duo_check_throws(
    static fn() => $materializeMixed(
        'woocommerce_bacs_settings',
        ['enabled' => 'no'],
        $gatewayRules['woocommerce_bacs_settings'],
        'no',
        ['account_details' => 'not-native-empty']
    ),
    RuntimeException::class,
    'a dirty target cannot carry a nonempty BACS account_details placeholder through materialization'
);

$chequeResult = $materializeMixed(
    'woocommerce_cheque_settings',
    ['enabled' => 'no', 'title' => '<span>Cheque</span>'],
    $gatewayRules['woocommerce_cheque_settings'],
    'off',
    ['enabled' => 'yes', 'title' => 'Old', 'description' => 'remove', 'instructions' => 'remove']
);
duo_check_same(
    ['enabled' => 'no', 'title' => '<span>Cheque</span>'],
    $chequeResult['written'],
    'cheque native materialization removes every absent authored sibling and preserves exact inline safe text'
);
duo_check_same(
    ['enabled' => 'no'],
    $woocommerceInterpreter->project_materialized_option_sub_keys(
        'woocommerce_cheque_settings',
        ['enabled' => 'no', 'title' => '<span>Physical carrier</span>'],
        $gatewayRules['woocommerce_cheque_settings'],
        ['enabled']
    ),
    'the four-argument projector may omit a native physical carrier that is absent from desired authored state'
);
foreach ([
    'duplicate desired key' => ['enabled', 'enabled'],
    'target-owned desired key' => ['account_name'],
] as $label => $desiredKeys) {
    duo_check_throws(
        static fn() => $woocommerceInterpreter->project_materialized_option_sub_keys(
            'woocommerce_bacs_settings',
            ['enabled' => 'no'],
            $gatewayRules['woocommerce_bacs_settings'],
            $desiredKeys
        ),
        RuntimeException::class,
        "mixed-option $label refuses at the adapter-owned sparse projection boundary"
    );
}

$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_woocommerce_shipping_zone_methods', [
    ['instance_id' => 17, 'zone_id' => 2, 'method_id' => 'flat_rate', 'method_order' => 0, 'is_enabled' => 1],
    ['instance_id' => 19, 'zone_id' => 2, 'method_id' => 'free_shipping', 'method_order' => 1, 'is_enabled' => 1],
]);
$codSource = [
    'enabled' => 'yes',
    'title' => '<span>Cash</span>',
    'description' => '<p>Pay on delivery</p>',
    'enable_for_methods' => ['flat_rate', 'flat_rate:17', 'free_shipping:19'],
    'enable_for_virtual' => 'no',
];
$codNormalized = $woocommerceInterpreter->normalize_captured_option_sub_keys(
    'woocommerce_cod_settings',
    $codSource,
    $gatewayRules['woocommerce_cod_settings'],
    ['woocommerce_cod_settings' => serialize($codSource)]
);
duo_check_same(
    [
        ['method_id' => 'flat_rate'],
        ['instance_id' => 17, 'method_id' => 'flat_rate'],
        ['instance_id' => 19, 'method_id' => 'free_shipping'],
    ],
    $codNormalized['enable_for_methods'] ?? null,
    'COD capture separates stable method-wide identities from typed instance references'
);
duo_check_same(
    2,
    count(array_filter(
        $wpdb->queryLog(),
        static fn(array $entry): bool => ($entry['method'] ?? null) === 'get_results'
            && str_contains((string) ($entry['sql'] ?? ''), 'woocommerce_shipping_zone_methods')
    )),
    'COD capture witnesses each instance through one bounded raw shipping-zone-method query'
);
duo_check_same(0, WC_Shipping_Zones::$resolverCalls,
    'COD capture does not construct or resolve a hook-capable Woo shipping service');

$seedCodSourceMethods = static function (array $rows) use ($wpdb): void {
    $wpdb->seedTable('wp_woocommerce_shipping_zone_methods', $rows);
};
$sourceMethodRows = [
    ['instance_id' => 17, 'zone_id' => 2, 'method_id' => 'flat_rate', 'method_order' => 0, 'is_enabled' => 1],
    ['instance_id' => 19, 'zone_id' => 2, 'method_id' => 'free_shipping', 'method_order' => 1, 'is_enabled' => 1],
];
$wpdb->onQuery(static function (string $sql, string $method): ?string {
    return $method === 'get_results' && str_contains($sql, 'woocommerce_shipping_zone_methods')
        ? 'simulated raw shipping-method witness query failure'
        : null;
});
duo_check_throws(
    static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_cod_settings',
        $codSource,
        $gatewayRules['woocommerce_cod_settings'],
        ['woocommerce_cod_settings' => serialize($codSource)]
    ),
    RuntimeException::class,
    'COD refuses a failed raw shipping-zone-method witness instead of resolving a service',
    'raw witness query failed'
);
$wpdb->onQuery(null);

foreach ([
    'duplicate row' => [
        ['instance_id' => 17, 'zone_id' => 2, 'method_id' => 'flat_rate', 'method_order' => 0, 'is_enabled' => 1],
        ['instance_id' => 17, 'zone_id' => 2, 'method_id' => 'flat_rate', 'method_order' => 1, 'is_enabled' => 1],
        ['instance_id' => 19, 'zone_id' => 2, 'method_id' => 'free_shipping', 'method_order' => 2, 'is_enabled' => 1],
    ],
    'extension-owned row' => [
        ['instance_id' => 17, 'zone_id' => 2, 'method_id' => 'table_rate', 'method_order' => 0, 'is_enabled' => 1],
        ['instance_id' => 19, 'zone_id' => 2, 'method_id' => 'free_shipping', 'method_order' => 1, 'is_enabled' => 1],
    ],
    'noncanonical bounded column' => [
        ['instance_id' => 17, 'zone_id' => '02', 'method_id' => 'flat_rate', 'method_order' => 0, 'is_enabled' => 1],
        ['instance_id' => 19, 'zone_id' => 2, 'method_id' => 'free_shipping', 'method_order' => 1, 'is_enabled' => 1],
    ],
] as $label => $rows) {
    $seedCodSourceMethods($rows);
    duo_check_throws(
        static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
            'woocommerce_cod_settings',
            $codSource,
            $gatewayRules['woocommerce_cod_settings'],
            ['woocommerce_cod_settings' => serialize($codSource)]
        ),
        RuntimeException::class,
        "COD $label raw witness refuses before canonical publication"
    );
}
$seedCodSourceMethods($sourceMethodRows);

$wpdb->seedTable('wp_woocommerce_shipping_zone_methods', [
    ['instance_id' => 117, 'zone_id' => 7, 'method_id' => 'flat_rate', 'method_order' => 0, 'is_enabled' => 1],
    ['instance_id' => 119, 'zone_id' => 7, 'method_id' => 'free_shipping', 'method_order' => 1, 'is_enabled' => 1],
]);
$codRebound = $codNormalized;
$codRebound['enable_for_methods'][1]['instance_id'] = 117;
$codRebound['enable_for_methods'][2]['instance_id'] = 119;
$targetMethodRows = [
    ['instance_id' => 117, 'zone_id' => 7, 'method_id' => 'flat_rate', 'method_order' => 0, 'is_enabled' => 1],
    ['instance_id' => 119, 'zone_id' => 7, 'method_id' => 'free_shipping', 'method_order' => 1, 'is_enabled' => 1],
];
$targetWitnessReads = 0;
$raceWriteCalls = 0;
$raceFinalizeCalls = 0;
$raceRestores = 0;
$wpdb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (
    &$targetWitnessReads,
    $targetMethodRows
): null {
    if ($method !== 'get_results'
        || !str_contains($sql, 'woocommerce_shipping_zone_methods')
        || !str_contains($sql, 'instance_id = 117')) {
        return null;
    }
    ++$targetWitnessReads;
    if ($targetWitnessReads === 2) {
        $raced = $targetMethodRows;
        $raced[0]['method_id'] = 'free_shipping';
        $db->seedTable('wp_woocommerce_shipping_zone_methods', $raced);
    }
    return null;
});
duo_check_throws(
    static function () use (
        $woocommerceInterpreter,
        $codRebound,
        $gatewayRules,
        &$raceFinalizeCalls,
        &$raceRestores,
        &$raceWriteCalls
    ): bool {
        return $woocommerceInterpreter->materialize_option_sub_keys(
        'woocommerce_cod_settings',
        $codRebound,
        $gatewayRules['woocommerce_cod_settings'],
        'auto-off',
        ['enabled' => 'no', 'title' => 'Stale', 'enable_for_methods' => ['local_pickup']],
        static fn(string $companion): ?array => null,
        static function () use (&$raceFinalizeCalls): array {
            ++$raceFinalizeCalls;
            return [];
        },
        static function () use (&$raceRestores): ?array {
            ++$raceRestores;
            return null;
        },
        static function (Closure $restore): void {},
        static function (array $value) use (&$raceWriteCalls): void {
            ++$raceWriteCalls;
        }
        );
    },
    RuntimeException::class,
    'COD immediate target raw-row recheck refuses a method replacement race after storage is armed',
    'does not match its exact core method identity'
);
duo_check_same([2, 1, 0, 0], [$targetWitnessReads, $raceWriteCalls, $raceFinalizeCalls, $raceRestores],
    'COD race refusal reaches one engine-owned write but no finalization or unrequested restoration');
$wpdb->onQuery(null);
$wpdb->seedTable('wp_woocommerce_shipping_zone_methods', $targetMethodRows);
$wpdb->resetLog();
$codResult = $materializeMixed(
    'woocommerce_cod_settings',
    $codRebound,
    $gatewayRules['woocommerce_cod_settings'],
    'auto-off',
    ['enabled' => 'no', 'title' => 'Stale', 'enable_for_methods' => ['local_pickup']]
);
duo_check_same(
    ['flat_rate', 'flat_rate:117', 'free_shipping:119'],
    $codResult['written']['enable_for_methods'] ?? null,
    'COD native storage rebuilds exact target-local method_id:instance_id bytes'
);
duo_check_same($codRebound, $codResult['projected'],
    'COD finalized native bytes project back to the exact materialized typed-reference shape');
duo_check_same(
    6,
    count(array_filter(
        $wpdb->queryLog(),
        static fn(array $entry): bool => ($entry['method'] ?? null) === 'get_results'
            && str_contains((string) ($entry['sql'] ?? ''), 'woocommerce_shipping_zone_methods')
    )),
    'COD materialization witnesses target rows before storage, immediately after it, and during finalized projection'
);
duo_check_same(0, WC_Shipping_Zones::$resolverCalls,
    'COD raw witness and race recheck execute no shipping-service construction path');
$codRepeat = $materializeMixed(
    'woocommerce_cod_settings',
    $codRebound,
    $gatewayRules['woocommerce_cod_settings'],
    'auto-off',
    $codResult['written']
);
duo_check_same($codResult['written'], $codRepeat['written'],
    'COD typed-reference materialization is byte-stable on repeat');

$emptyCod = $codSource;
$emptyCod['enable_for_methods'] = '';
$emptyNormalized = $woocommerceInterpreter->normalize_captured_option_sub_keys(
    'woocommerce_cod_settings',
    $emptyCod,
    $gatewayRules['woocommerce_cod_settings'],
    ['woocommerce_cod_settings' => serialize($emptyCod)]
);
$emptyResult = $materializeMixed(
    'woocommerce_cod_settings',
    $emptyNormalized,
    $gatewayRules['woocommerce_cod_settings'],
    'no',
    null
);
duo_check_same('', $emptyResult['written']['enable_for_methods'] ?? null,
    'COD empty restrictions normalize canonically and return to the exact native empty writer shape');

foreach ([
    'addon method id' => ['table_rate:117'],
    'duplicate identity' => ['flat_rate:117', 'flat_rate:117'],
    'leading-zero instance' => ['flat_rate:0117'],
    'missing instance' => ['flat_rate:999'],
    'method-instance mismatch' => ['free_shipping:117'],
] as $label => $methods) {
    $hostile = $codSource;
    $hostile['enable_for_methods'] = $methods;
    duo_check_throws(
        static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
            'woocommerce_cod_settings',
            $hostile,
            $gatewayRules['woocommerce_cod_settings'],
            ['woocommerce_cod_settings' => serialize($hostile)]
        ),
        RuntimeException::class,
        "COD $label refuses before canonical publication"
    );
}

$codExtra = $codRebound;
$codExtra['enable_for_methods'][1]['extension_data'] = 'secret-DO_NOT-ECHO';
duo_check_throws(
    static fn() => $materializeMixed(
        'woocommerce_cod_settings',
        $codExtra,
        $gatewayRules['woocommerce_cod_settings'],
        'no',
        []
    ),
    RuntimeException::class,
    'COD canonical rows refuse extension-owned fields without echoing them',
    'unknown fields'
);

$GLOBALS['wooMixedMutateField'] = 'subject';
$mutatedEmail = ['enabled' => 'yes', 'subject' => 'marker-DO_NOT-ECHO'];
$newOrderRules = $mixedRules('woocommerce_new_order_settings');
duo_check_throws(
    static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_new_order_settings',
        $mutatedEmail,
        $newOrderRules,
        ['woocommerce_new_order_settings' => serialize($mutatedEmail)]
    ),
    RuntimeException::class,
    'a native sanitizer drift cannot be blessed as portable state'
);
unset($GLOBALS['wooMixedMutateField']);

$unknownMarker = 'UNKNOWN-SIBLING-SECRET-DO_NOT-ECHO';
$unknownBacs = $bacsSource + [$unknownMarker => 'payload'];
try {
    $woocommerceInterpreter->normalize_captured_option_sub_keys(
        'woocommerce_bacs_settings',
        $bacsCaptured,
        $gatewayRules['woocommerce_bacs_settings'],
        ['woocommerce_bacs_settings' => serialize($unknownBacs)]
    );
    duo_check(false, 'unknown mixed-record siblings refuse atomically');
} catch (RuntimeException $failure) {
    duo_check(!str_contains($failure->getMessage(), $unknownMarker)
        && !str_contains($failure->getMessage(), 'payload'),
        'unknown mixed-record sibling diagnostics contain only a bounded key fingerprint');
}

$tooDeepMixed = 'leaf';
for ($depth = 0; $depth < 300; ++$depth) {
    $tooDeepMixed = [$tooDeepMixed];
}
$hostileMixedStorage = [
    'non-string present row' => null,
    'object with wakeup hook' => serialize(new WooOptionalWakeupCanary()),
    'trailing serialized bytes' => serialize(['enabled' => 'yes']) . 'trailing',
    'recursive reference graph' => 'a:1:{s:7:"enabled";R:1;}',
    'over-deep plain-data graph' => serialize($tooDeepMixed),
    'over-bound raw row' => str_repeat('x', 1048577),
];
foreach ($hostileMixedStorage as $label => $wire) {
    duo_check_throws(
        static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
            'woocommerce_cheque_settings',
            [],
            $gatewayRules['woocommerce_cheque_settings'],
            ['woocommerce_cheque_settings' => $wire]
        ),
        RuntimeException::class,
        "mixed option $label refuses at the safe raw-storage boundary"
    );
}
duo_check_same(0, WooOptionalWakeupCanary::$wakeups,
    'mixed option native normalization executes no object wakeup hooks');

foreach ([
    'invalid checkbox alias' => ['woocommerce_new_order_settings', ['enabled' => '1']],
    'invalid email type' => ['woocommerce_new_order_settings', ['email_type' => 'amp']],
    'zero review delay' => ['woocommerce_customer_review_request_settings', ['delay_days' => '0']],
    'over-bound review delay' => ['woocommerce_customer_review_request_settings', ['delay_days' => '61']],
    'noncanonical review delay' => ['woocommerce_customer_review_request_settings', ['delay_days' => '1e1']],
    'invalid UTF-8 text' => ['woocommerce_new_order_settings', ['subject' => "bad\xFF"]],
    'over-bound text' => ['woocommerce_new_order_settings', ['subject' => str_repeat('x', 262145)]],
] as $label => [$optionName, $record]) {
    duo_check_throws(
        static fn() => $woocommerceInterpreter->normalize_captured_option_sub_keys(
            $optionName,
            $record,
            $mixedRules($optionName),
            [$optionName => serialize($record)]
        ),
        RuntimeException::class,
        "$label refuses before canonical publication"
    );
}

$nativeDriftCalls = ['restore' => 0, 'write' => 0, 'finalize' => 0];
$GLOBALS['wooMixedMutateField'] = 'subject';
duo_check_throws(
    static function () use ($woocommerceInterpreter, $mixedRules, &$nativeDriftCalls): void {
        $woocommerceInterpreter->materialize_option_sub_keys(
            'woocommerce_new_order_settings',
            ['subject' => 'marker-DO_NOT-ECHO'],
            $mixedRules('woocommerce_new_order_settings'),
            'no',
            [],
            static fn(string $companion): ?array => null,
            static function () use (&$nativeDriftCalls): array {
                ++$nativeDriftCalls['finalize'];
                return [];
            },
            static fn(): ?array => null,
            static function (Closure $restore) use (&$nativeDriftCalls): void {
                ++$nativeDriftCalls['restore'];
            },
            static function (array $value) use (&$nativeDriftCalls): void {
                ++$nativeDriftCalls['write'];
            }
        );
    },
    RuntimeException::class,
    'native validator drift refuses repository materialization before mutation'
);
unset($GLOBALS['wooMixedMutateField']);
duo_check_same(
    ['restore' => 0, 'write' => 0, 'finalize' => 0],
    $nativeDriftCalls,
    'native validator refusal reaches no runtime restore, storage write, or finalization callback'
);

$mutationCalls = ['restore' => 0, 'write' => 0, 'finalize' => 0];
$nativeCallsBeforeInvalidRepository = count($GLOBALS['wooMixedNativeCalls']);
duo_check_throws(
    static fn() => $woocommerceInterpreter->materialize_option_sub_keys(
        'woocommerce_new_order_settings',
        ['subject' => ['not' => 'text']],
        $mixedRules('woocommerce_new_order_settings'),
        'no',
        [],
        static fn(string $companion): ?array => null,
        static function () use (&$mutationCalls): array {
            ++$mutationCalls['finalize'];
            return [];
        },
        static fn(): ?array => null,
        static function (Closure $restore) use (&$mutationCalls): void {
            ++$mutationCalls['restore'];
        },
        static function (array $value) use (&$mutationCalls): void {
            ++$mutationCalls['write'];
        }
    ),
    RuntimeException::class,
    'invalid repository coercion refuses without emitting a PHP warning or reaching native mutation'
);
duo_check_same(
    [
        'calls' => ['restore' => 0, 'write' => 0, 'finalize' => 0],
        'native_delta' => 0,
    ],
    [
        'calls' => $mutationCalls,
        'native_delta' => count($GLOBALS['wooMixedNativeCalls']) - $nativeCallsBeforeInvalidRepository,
    ],
    'repository type validation completes before runtime restore, storage write, finalization, or native string coercion'
);

duo_check_throws(
    static fn() => $woocommerceInterpreter->materialize_option_sub_keys(
        'woocommerce_cheque_settings',
        ['enabled' => 'no'],
        $gatewayRules['woocommerce_cheque_settings'],
        'no',
        [],
        static fn(string $companion): ?array => null,
        static fn(): array => [],
        static fn(): ?array => null
    ),
    RuntimeException::class,
    'mixed option materialization refuses without both engine-owned rollback/write callbacks'
);

$failedWriteCalls = ['restore' => 0, 'write' => 0, 'finalize' => 0];
duo_check_throws(
    static function () use ($woocommerceInterpreter, $gatewayRules, &$failedWriteCalls): void {
        $woocommerceInterpreter->materialize_option_sub_keys(
            'woocommerce_cheque_settings',
            ['enabled' => 'no'],
            $gatewayRules['woocommerce_cheque_settings'],
            'no',
            [],
            static fn(string $companion): ?array => null,
            static function () use (&$failedWriteCalls): array {
                ++$failedWriteCalls['finalize'];
                return [];
            },
            static fn(): ?array => null,
            static function (Closure $restore) use (&$failedWriteCalls): void {
                ++$failedWriteCalls['restore'];
            },
            static function (array $value) use (&$failedWriteCalls): void {
                ++$failedWriteCalls['write'];
                throw new RuntimeException('injected storage write failure');
            }
        );
    },
    RuntimeException::class,
    'an injected engine storage failure stays loud for outer rollback/retry'
);
duo_check_same(
    ['restore' => 1, 'write' => 1, 'finalize' => 0],
    $failedWriteCalls,
    'a failed storage write registers rollback exactly once and never finalizes partial state'
);

duo_check_throws(
    static fn() => $woocommerceInterpreter->materialize_option_sub_keys(
        'woocommerce_cheque_settings',
        ['enabled' => 'no'],
        $gatewayRules['woocommerce_cheque_settings'],
        'no',
        [],
        static fn(string $companion): ?array => null,
        static fn(): array => [
            'option_name' => 'woocommerce_cheque_settings',
            'option_value' => serialize(['enabled' => 'yes']),
            'autoload' => 'no',
        ],
        static fn(): ?array => null,
        static function (Closure $restore): void {},
        static function (array $value): void {}
    ),
    RuntimeException::class,
    'same-shape finalized storage drift cannot be accepted after the engine-owned write'
);

foreach ($hostileMixedStorage as $label => $wire) {
    duo_check_throws(
        static fn() => $woocommerceInterpreter->materialize_option_sub_keys(
            'woocommerce_cheque_settings',
            ['enabled' => 'no'],
            $gatewayRules['woocommerce_cheque_settings'],
            'no',
            [],
            static fn(string $companion): ?array => null,
            static fn(): array => [
                'option_name' => 'woocommerce_cheque_settings',
                'option_value' => $wire,
                'autoload' => 'no',
            ],
            static fn(): ?array => null,
            static function (Closure $restore): void {},
            static function (array $value): void {}
        ),
        RuntimeException::class,
        "final mixed option $label refuses at the bounded safe-storage boundary"
    );
}
duo_check_same(0, WooOptionalWakeupCanary::$wakeups,
    'final mixed-option verification executes no object wakeup hooks');

$aggregateTarget = [];
foreach (['account_name', 'account_number', 'bank_name', 'sort_code'] as $field) {
    $aggregateTarget[$field] = str_repeat('x', 262144);
}
$aggregateWriteCalls = 0;
duo_check_throws(
    static fn() => $woocommerceInterpreter->materialize_option_sub_keys(
        'woocommerce_bacs_settings',
        ['enabled' => 'no'],
        $gatewayRules['woocommerce_bacs_settings'],
        'no',
        $aggregateTarget,
        static fn(string $companion): ?array => null,
        static fn(): array => [],
        static fn(): ?array => null,
        static function (Closure $restore): void {},
        static function (array $value) use (&$aggregateWriteCalls): void {
            ++$aggregateWriteCalls;
        }
    ),
    RuntimeException::class,
    'aggregate target-owned mixed-record bytes refuse before an oversized native write'
);
duo_check_same(0, $aggregateWriteCalls,
    'the aggregate record bound is enforced before engine-owned mutation');

foreach ((array) ($inventory['families']['fulfillments']['runtime_tables'] ?? []) as $table) {
    duo_check_same('runtime', $manifest['tables'][$table]['class'] ?? null, "$table remains runtime fulfillment state");
}
foreach ((array) ($inventory['families']['customer_stock_notifications']['runtime_tables'] ?? []) as $table) {
    duo_check_same('runtime', $manifest['tables'][$table]['class'] ?? null, "$table remains subscriber runtime/PII state");
}
foreach ((array) ($inventory['families']['email_unsubscribes']['runtime_tables'] ?? []) as $table) {
    duo_check_same('runtime', $manifest['tables'][$table]['class'] ?? null, "$table remains email-recipient runtime state");
}
$GLOBALS['wpdb'] = new class {
    public function get_blog_prefix(int $blogId): string {
        return $blogId === 1 ? 'wp_' : "wp_{$blogId}_";
    }
};
duo_check_same(
    ['class' => 'runtime'],
    $policy->meta_rule_for_user('wc_push_notification_preferences_wp', []),
    'push preferences resolve only for the exact current-site suffix'
);
foreach (['wc_push_notification_preferences', 'wp_wc_push_notification_preferences', 'wc_push_notification_preferences_wp_2'] as $nearMiss) {
    duo_check_same(null, $policy->meta_rule_for_user($nearMiss, []), "$nearMiss cannot widen push-preference ownership");
}

$store = WpStore::reset()
    ->seedOptions(['home' => 'https://optional.example.test'])
    ->seedPostType('woo_email', ['public' => false, '_builtin' => false])
    ->seedPostType('wc_push_token', ['public' => false, '_builtin' => false]);
$wpdb = FakeWpdb::install();
$contentMarker = 'merchant_email_content_DO_NOT_ECHO';
$wpdb->seedTable('wp_posts', [
    ['ID' => 811, 'post_type' => 'woo_email', 'post_status' => 'publish', 'post_content' => $contentMarker],
    ['ID' => 812, 'post_type' => 'woo_email', 'post_status' => 'draft', 'post_content' => 'customized blocks'],
    ['ID' => 813, 'post_type' => 'woo_email', 'post_status' => 'trash', 'post_content' => 'deleted template'],
    ['ID' => 814, 'post_type' => 'wc_push_token', 'post_status' => 'private', 'post_content' => 'device secret'],
    ['ID' => 815, 'post_type' => 'import_session', 'post_status' => 'publish', 'post_content' => 'source URL'],
]);
$wpdb->seedTable('wp_term_taxonomy', []);

$gaps = (new ScopeDiscovery($policy))->gaps();
duo_check_same(
    ['post_type:woo_email' => ['entities' => 2]],
    $gaps,
    'customized and uncustomized live Block Email Editor posts fail the real scope gate while trash and runtime entities do not'
);
duo_check(!str_contains((string) json_encode($gaps), $contentMarker),
    'the Block Email Editor scope refusal exposes only a bounded row count, never merchant block content');

$wpdb->seedTable('wp_posts', [
    ['ID' => 814, 'post_type' => 'wc_push_token', 'post_status' => 'private', 'post_content' => 'device secret'],
    ['ID' => 815, 'post_type' => 'import_session', 'post_status' => 'publish', 'post_content' => 'source URL'],
]);
duo_check_same([], (new ScopeDiscovery($policy))->gaps(),
    'feature-disabled/no-template state stays clean while push and import runtime state remains local');

$mappingMarker = 'mapping_value_secret_DO_NOT_ECHO';
$credentialMarker = 'migration_api_secret_DO_NOT_ECHO';
$gatewayMarker = 'portable_gateway_title';
$bankMarker = 'TARGET-BANK-SECRET-DO_NOT-ECHO';
$emailMarker = 'portable_email_content';
$recipientMarker = 'TARGET-RECIPIENT-DO-NOT-ECHO@example.test';
$sideEffectMarker = 'side_effect_payload_DO_NOT_ECHO';
$integrationMarker = 'EXTENSION-CREDENTIAL-DO-NOT-ECHO';
$thumbnailOptions = [
    'woocommerce_thumbnail_cropping' => 'custom',
    'woocommerce_thumbnail_cropping_custom_height' => '3',
    'woocommerce_thumbnail_cropping_custom_width' => '4',
    'woocommerce_thumbnail_image_width' => '500',
];
$optionRows = [
    ['option_id' => 1, 'option_name' => 'woocommerce_email_templates_new_order_post_id', 'option_value' => $mappingMarker, 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'woocommerce_email_templates_addon_gateway_post_id', 'option_value' => '999', 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'wc_email_sync_backfill_completed_tracked', 'option_value' => 'yes', 'autoload' => 'no'],
    ['option_id' => 4, 'option_name' => 'woocommerce_email_template_sync_backfill_complete', 'option_value' => 'yes', 'autoload' => 'yes'],
    ['option_id' => 5, 'option_name' => 'wc_migrator_analytics', 'option_value' => serialize(['sessions' => 7]), 'autoload' => 'no'],
    ['option_id' => 6, 'option_name' => 'wc_migrator_products_count', 'option_value' => '42', 'autoload' => 'yes'],
    ['option_id' => 7, 'option_name' => 'wc_migrator_credentials_shopify', 'option_value' => json_encode(['token' => $credentialMarker]), 'autoload' => 'yes'],
    ['option_id' => 8, 'option_name' => 'wc_migrator_credentials_partner_extension', 'option_value' => json_encode(['token' => $credentialMarker]), 'autoload' => 'yes'],
    ['option_id' => 9, 'option_name' => 'wc_migrator_credentials_bad/slash', 'option_value' => 'near-miss', 'autoload' => 'yes'],
    ['option_id' => 10, 'option_name' => 'wc_customer_stock_notifications_admin_notice', 'option_value' => serialize(new WooOptionalWakeupCanary()), 'autoload' => 'no'],
    ['option_id' => 11, 'option_name' => 'wc_stock_notifications_cycle_state_811', 'option_value' => serialize(new WooOptionalWakeupCanary()), 'autoload' => 'no'],
    ['option_id' => 12, 'option_name' => 'wc_stock_notifications_cycle_state_01', 'option_value' => serialize(new WooOptionalWakeupCanary()), 'autoload' => 'no'],
    ['option_id' => 13, 'option_name' => 'woocommerce_google_analytics_settings', 'option_value' => serialize(['api_secret' => $integrationMarker]), 'autoload' => 'yes'],
    ['option_id' => 14, 'option_name' => 'woocommerce_table_rate_priorities_17', 'option_value' => serialize([$integrationMarker]), 'autoload' => 'yes'],
];
$nextOptionId = 15;
$gatewayRecords = (array) ($settingsInventory['closed_records']['gateway_settings'] ?? []);
foreach (array_keys($gatewayRecords) as $optionName) {
    $value = match ($optionName) {
        'woocommerce_bacs_accounts' => [[
            'account_name' => $bankMarker,
            'account_number' => '000',
            'bank_name' => 'Bank',
            'sort_code' => '',
            'iban' => '',
            'bic' => '',
        ]],
        'woocommerce_bacs_settings' => [
            'enabled' => 'yes',
            'title' => '<span>' . $gatewayMarker . '</span>',
            'description' => '<p>Bank transfer</p>',
            'instructions' => '<p>Use the reference</p>',
            'account_details' => '',
            'account_name' => $bankMarker,
        ],
        'woocommerce_cheque_settings' => [
            'enabled' => 'yes',
            'title' => '<span>' . $gatewayMarker . '</span>',
            'description' => '<p>Cheque payment</p>',
            'instructions' => '<p>Mail the cheque</p>',
        ],
        'woocommerce_cod_settings' => [
            'enabled' => 'yes',
            'title' => '<span>' . $gatewayMarker . '</span>',
            'description' => '<p>Cash on delivery</p>',
            'instructions' => '<p>Pay the courier</p>',
            'enable_for_methods' => '',
            'enable_for_virtual' => 'no',
        ],
        default => throw new RuntimeException("unexpected gateway record $optionName"),
    };
    $optionRows[] = [
        'option_id' => $nextOptionId++,
        'option_name' => $optionName,
        'option_value' => serialize($value),
        'autoload' => 'yes',
    ];
}
$emailRecords = (array) ($settingsInventory['closed_records']['email_settings']['records'] ?? []);
$captureEmailValues = [
    'checkbox' => 'yes',
    'delay_days' => '14',
    'email_type' => 'multipart',
    'env_text' => $recipientMarker,
    'text' => $emailMarker . ' ✓',
    'textarea' => '<p>' . $emailMarker . ' <strong>✓</strong></p>',
];
foreach ($emailRecords as $optionName => $record) {
    $value = [];
    foreach ((array) ($record['fields'] ?? []) as $field) {
        $type = (string) ($emailFieldTypes[$field] ?? '');
        if (!array_key_exists($type, $captureEmailValues)) {
            throw new RuntimeException("missing capture value for $optionName.$field ($type)");
        }
        $value[(string) $field] = $captureEmailValues[$type];
    }
    $optionRows[] = [
        'option_id' => $nextOptionId++,
        'option_name' => $optionName,
        'option_value' => serialize($value),
        'autoload' => 'yes',
    ];
}
$addonEmailOption = 'woocommerce_extension_delivery_notice_settings';
$optionRows[] = [
    'option_id' => $nextOptionId++,
    'option_name' => $addonEmailOption,
    'option_value' => serialize(['unknown_extension_field' => $emailMarker]),
    'autoload' => 'yes',
];
$targetEnvironmentOptions = [];
foreach ((array) ($settingsInventory['closed_records']['target_environment_side_effect_options'] ?? []) as $record) {
    foreach ((array) ($record['options'] ?? []) as $optionName) {
        $targetEnvironmentOptions[] = $optionName;
        $optionRows[] = [
            'option_id' => $nextOptionId++,
            'option_name' => $optionName,
            'option_value' => $sideEffectMarker,
            'autoload' => 'yes',
        ];
    }
}
foreach ($thumbnailOptions as $optionName => $optionValue) {
    $optionRows[] = [
        'option_id' => $nextOptionId++,
        'option_name' => $optionName,
        'option_value' => $optionValue,
        'autoload' => 'yes',
    ];
}
$wpdb->seedTable('wp_options', $optionRows);

$tokens = new Tokens();
$tokens->policy = $policy;
$secretCalls = [];
$capture = new OptionsCapture(
    $policy,
    $tokens,
    static function (string $section, string $key, mixed $value, array $rule) use (&$secretCalls): void {
        $secretCalls[] = [$section, $key, $value, $rule['class'] ?? null];
    },
    static fn(int $id, string $kind, bool $force): ?string => null,
    static fn(Policy $candidatePolicy, string $kind, int $id): bool => false
);
$tooDeep = 'leaf';
for ($depth = 0; $depth < 300; ++$depth) {
    $tooDeep = [$tooDeep];
}
$hostileMixedProductRows = [
    'object wakeup payload' => serialize(new WooOptionalWakeupCanary()),
    'trailing serialized bytes' => 'a:0:{}trailing-bytes',
    'recursive reference graph' => 'a:1:{i:0;R:1;}',
    'over-deep graph' => serialize($tooDeep),
    'unknown sibling' => serialize(['enabled' => 'yes', 'extension_secret_key' => 'DO-NOT-ECHO']),
];
foreach ($hostileMixedProductRows as $label => $wire) {
    $wpdb->seedTable('wp_options', [[
        'option_id' => 1,
        'option_name' => 'woocommerce_new_order_settings',
        'option_value' => $wire,
        'autoload' => 'yes',
    ]]);
    duo_check_throws(
        static fn() => $capture->capture(false, false, null, [], false, true),
        RuntimeException::class,
        "real mixed-option capture refuses $label before canonical publication"
    );
}
duo_check_same(0, WooOptionalWakeupCanary::$wakeups,
    'real closed mixed-option capture executes no object wakeup hooks');
$wpdb->seedTable('wp_options', $optionRows);
$captureResult = $capture->capture(false, false, null, [], false, true);
$pending = $captureResult['unclassified'];
sort($pending, SORT_STRING);
$expectedPendingNames = array_merge(
    ['wc_migrator_credentials_bad/slash', 'wc_stock_notifications_cycle_state_01', 'woocommerce_email_templates_addon_gateway_post_id', 'woocommerce_email_templates_new_order_post_id', 'woocommerce_google_analytics_settings', 'woocommerce_table_rate_priorities_17'],
    [$addonEmailOption]
);
sort($expectedPendingNames, SORT_STRING);
$expectedPending = array_map(
    static fn(string $name): string => "options:$name (owner candidate woocommerce; namespace matched without a classification)",
    $expectedPendingNames
);
duo_check_same($expectedPending, $pending,
    'real option capture accepts exact core mixed records while addon/template/near-miss state remains loudly unclassified');
$bacsCapturedRecord = $captureResult['document']['records']['woocommerce_bacs_settings']['value'] ?? null;
$newOrderCapturedRecord = $captureResult['document']['records']['woocommerce_new_order_settings']['value'] ?? null;
duo_check(is_array($bacsCapturedRecord)
    && ($bacsCapturedRecord['title'] ?? null) === '<span>' . $gatewayMarker . '</span>'
    && !array_key_exists('account_name', $bacsCapturedRecord)
    && !array_key_exists('account_details', $bacsCapturedRecord),
    'real capture publishes portable BACS content while excluding bank identity and derived carriers');
duo_check(is_array($newOrderCapturedRecord)
    && ($newOrderCapturedRecord['subject'] ?? null) === $emailMarker . ' ✓'
    && !array_key_exists('recipient', $newOrderCapturedRecord),
    'real capture publishes merchant email content while excluding target recipient identity');
$capturedMixedCount = count(array_filter(
    $mixedOptionNames,
    static fn(string $name): bool => array_key_exists($name, (array) ($captureResult['document']['records'] ?? []))
));
duo_check_same(count($mixedOptionNames), $capturedMixedCount,
    'every exact core gateway/email settings record crosses the real closed-subkey capture path');
$captureEvidence = json_encode($captureResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
duo_check(
    is_string($captureEvidence)
        && !str_contains($captureEvidence, $mappingMarker)
        && !str_contains($captureEvidence, $credentialMarker)
        && !str_contains($captureEvidence, $bankMarker)
        && !str_contains($captureEvidence, $recipientMarker)
        && !str_contains($captureEvidence, $sideEffectMarker)
        && !str_contains($captureEvidence, $integrationMarker),
    'capture refusal and repository output never echo mapping, credential, bank, recipient, side-effect, or integration-owned values'
);
$expectedGuardKeys = array_keys($thumbnailOptions);
foreach (['woocommerce_bacs_settings', 'woocommerce_cheque_settings'] as $optionName) {
    foreach ((array) ($settingsInventory['closed_records']['gateway_settings'][$optionName]['authored_fields'] ?? []) as $field) {
        $expectedGuardKeys[] = $optionName . '.' . $field;
    }
}
foreach (['enabled', 'title', 'description', 'instructions', 'enable_for_virtual'] as $field) {
    $expectedGuardKeys[] = 'woocommerce_cod_settings.' . $field;
}
foreach ($emailRecords as $optionName => $record) {
    foreach ((array) ($record['fields'] ?? []) as $field) {
        if (($emailFieldClasses[$field] ?? null) === 'authored') {
            $expectedGuardKeys[] = $optionName . '.' . $field;
        }
    }
}
$actualGuardKeys = array_map(static fn(array $call): string => (string) ($call[1] ?? ''), $secretCalls);
sort($expectedGuardKeys, SORT_STRING);
sort($actualGuardKeys, SORT_STRING);
duo_check_same($expectedGuardKeys, $actualGuardKeys,
    'the real capture secret guard sees every portable mixed/thumbnail field and no target-owned sibling');
duo_check(count(array_filter(
    $secretCalls,
    static fn(array $call): bool => ($call[3] ?? null) === 'authored'
)) === count($secretCalls), 'every real mixed-option guard call retains the authored sibling class');
foreach ($targetEnvironmentOptions as $optionName) {
    duo_check_same('env', $policy->option_rule((string) $optionName)['class'] ?? null,
        "$optionName is explicitly deployment-local and never enters repository state");
    duo_check(!array_key_exists((string) $optionName, (array) ($captureResult['document']['records'] ?? [])),
        "$optionName remains absent from the captured options document");
}
foreach ($thumbnailOptions as $optionName => $optionValue) {
    duo_check_same(
        ['state' => 'present', 'autoload' => 'yes', 'value' => $optionValue],
        $captureResult['document']['records'][$optionName] ?? null,
        "$optionName crosses real option capture as exact merchant-authored thumbnail state"
    );
}

duo_check_summary('WooCommerce optional-core inventory');
