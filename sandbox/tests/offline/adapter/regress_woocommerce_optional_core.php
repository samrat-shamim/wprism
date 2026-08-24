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

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
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
$dispositions = json_decode(
    (string) file_get_contents($root . '/manifests/dispositions.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
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
    31,
    count((array) ($inventory['source_files'] ?? [])),
    'the inventory binds all 31 exact optional-core storage writers and registries'
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
        'block_email_editor',
        'cli_migrator',
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
foreach ([
    'options.woocommerce_bacs_accounts|woocommerce_bacs_settings|woocommerce_cheque_settings|woocommerce_cod_settings',
    'options.woocommerce_<core-email-id>_settings',
] as $surface) {
    duo_check_same('capture', $unsupported[$surface] ?? null,
        "$surface is a reviewed populated-source fail-closed boundary");
}

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
$gatewayMarker = 'gateway_boundary_payload_DO_NOT_ECHO';
$emailMarker = 'email_boundary_payload_DO_NOT_ECHO';
$sideEffectMarker = 'side_effect_payload_DO_NOT_ECHO';
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
];
$nextOptionId = 13;
$gatewayRecords = (array) ($settingsInventory['closed_records']['gateway_settings'] ?? []);
foreach (array_keys($gatewayRecords) as $optionName) {
    $value = $optionName === 'woocommerce_bacs_accounts'
        ? [['account_name' => $gatewayMarker, 'account_number' => '000', 'bank_name' => 'Bank', 'sort_code' => '', 'iban' => '', 'bic' => '']]
        : ['enabled' => 'yes', 'title' => $gatewayMarker];
    $optionRows[] = [
        'option_id' => $nextOptionId++,
        'option_name' => $optionName,
        'option_value' => serialize($value),
        'autoload' => 'yes',
    ];
}
$emailRecords = (array) ($settingsInventory['closed_records']['email_settings']['records'] ?? []);
$tooDeep = 'leaf';
for ($depth = 0; $depth < 300; ++$depth) {
    $tooDeep = [$tooDeep];
}
$hostileEmailPayloads = [
    'woocommerce_new_order_settings' => serialize(new WooOptionalWakeupCanary()),
    'woocommerce_cancelled_order_settings' => 'a:0:{}trailing-bytes',
    'woocommerce_failed_order_settings' => 'a:1:{i:0;R:1;}',
    'woocommerce_customer_failed_order_settings' => serialize($tooDeep),
];
foreach (array_keys($emailRecords) as $optionName) {
    $optionRows[] = [
        'option_id' => $nextOptionId++,
        'option_name' => $optionName,
        'option_value' => $hostileEmailPayloads[$optionName]
            ?? serialize(['enabled' => 'yes', 'subject' => $emailMarker]),
        'autoload' => 'no',
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
$captureResult = $capture->capture(false, false, null, [], false, true);
$pending = $captureResult['unclassified'];
sort($pending, SORT_STRING);
$expectedPendingNames = array_merge(
    ['wc_migrator_credentials_bad/slash', 'wc_stock_notifications_cycle_state_01', 'woocommerce_email_templates_addon_gateway_post_id', 'woocommerce_email_templates_new_order_post_id'],
    array_keys($gatewayRecords),
    array_keys($emailRecords),
    [$addonEmailOption]
);
sort($expectedPendingNames, SORT_STRING);
$expectedPending = array_map(
    static fn(string $name): string => "options:$name (owner candidate woocommerce; namespace matched without a classification)",
    $expectedPendingNames
);
duo_check_same($expectedPending, $pending,
    'real option capture atomically refuses every exact mixed/secret/reference record and addon near-miss while deployment-local launch state remains clean');
duo_check_same(0, WooOptionalWakeupCanary::$wakeups,
    'fail-closed option discovery never decodes object, trailing, reference-shaped, or over-deep record bytes');
$captureEvidence = json_encode($captureResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
duo_check(
    is_string($captureEvidence)
        && !str_contains($captureEvidence, $mappingMarker)
        && !str_contains($captureEvidence, $credentialMarker)
        && !str_contains($captureEvidence, $gatewayMarker)
        && !str_contains($captureEvidence, $emailMarker)
        && !str_contains($captureEvidence, $sideEffectMarker),
    'capture refusal and repository output never echo mapping, credential, bank, email, or side-effect values'
);
duo_check_same(
    array_map(
        static fn(string $name, string $value): array => ['options', $name, $value, 'authored'],
        array_keys($thumbnailOptions),
        array_values($thumbnailOptions)
    ),
    $secretCalls,
    'only portable thumbnail values enter the authored guard while runtime, environment-secret, and fail-closed optional state never does'
);
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
