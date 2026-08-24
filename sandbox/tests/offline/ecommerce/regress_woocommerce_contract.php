<?php
declare(strict_types=1);

// DUO-3225: offline contract inventory for the exact WooCommerce 11.0.x
// fixture. The live conformance suite proves behavior; this fast test keeps a
// future option/table addition from becoming invisible by accident.

define('DUO_SPEC_VERSION', 2);
require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/agent/src/Code/Code.php';
require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';

use Duo\Policy;

$GLOBALS['wooContractBlogId'] = 1;
if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int {
        return (int) ($GLOBALS['wooContractBlogId'] ?? 1);
    }
}

final class WooContractWpdb {
    public function get_blog_prefix(int $blogId): string {
        return $blogId === 1 ? 'wp_' : "wp_{$blogId}_";
    }
}

$GLOBALS['wpdb'] = new WooContractWpdb();

function woo_fail(string $message): never { fwrite(STDERR, "FAIL: $message\n");
exit(1); }
function woo_ok(bool $condition, string $message): void {
    if (!$condition) woo_fail($message);
    echo "ok: $message\n";
}

$root = dirname(__DIR__, 4);
$manifest = json_decode((string) file_get_contents($root . '/manifests/woocommerce.json'), true, flags: JSON_THROW_ON_ERROR);
// One document per subject since WP-4.4 (spec/repo-format.md § v3.4): this
// suite reads woocommerce's reviewed entry, not the whole library.
$wooDispositionDocument = json_decode((string) file_get_contents($root . '/manifests/dispositions/woocommerce.json'), true, flags: JSON_THROW_ON_ERROR);
// No scratch library: $manifest IS manifests/woocommerce.json, so the v2
// shipped-membership proof compares the frozen bytes against the very file they
// were read from — the strongest form of the claim this snapshot makes.
$policy = Policy::from_snapshot([
    'dispositions' => null,
    'format' => 'duo-policy-snapshot/v6',
    'adapter_sources' => ['certificates' => [], 'format' => 'duo-adapter-sources/v2', 'out_of_tree' => []],
    'manifests' => [$manifest],
    'site' => ['manifests' => ['woocommerce'], 'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []], 'spec_version' => DUO_SPEC_VERSION],
]);

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
$externalProductInventory = json_decode(
    (string) file_get_contents($root . '/sandbox/tests/fixtures/woocommerce-core-11.0-external-product.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$termSurfaceInventory = json_decode(
    (string) file_get_contents($root . '/sandbox/tests/fixtures/woocommerce-core-11.0-terms.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
woo_ok(($settingsInventory['format'] ?? null) === 'duo-woocommerce-settings-inventory/v1',
    'the source-audited settings inventory uses the exact reviewed schema');
woo_ok(count((array) ($settingsInventory['literal_ids'] ?? [])) === 148,
    'the exact 11.0.0/11.0.1 visible-settings union freezes all 148 reviewed source ids');
woo_ok(count((array) ($settingsInventory['source_files'] ?? [])) === 81,
    'the inventory binds all eighty-one byte-identical settings, gateway, email, pickup, scheduler, stock-notification, launch, image-regeneration, and frontend-read sources');
foreach ((array) ($settingsInventory['source_files'] ?? []) as $sourceFile => $sha256) {
    woo_ok(
        is_string($sourceFile) && $sourceFile !== ''
            && is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', $sha256) === 1,
        "$sourceFile carries one exact shared 11.0.0/11.0.1 source digest"
    );
}
woo_ok(
    ($settingsInventory['source_files']['src/Internal/CustomerEmailVerification/CustomerEmailVerification.php'] ?? null)
        === '612b2808300ffdc219f2c8764602311ceacb0fdf6cd240501c1f5783905c8cdf'
        && ($settingsInventory['version_specific_source_files']['includes/class-woocommerce.php'] ?? null) === [
            '11.0.0' => '5982ef2ab60231218cc71a2ba9bd387496d32c1a5eeb5468116d51137bbd7ef4',
            '11.0.1' => '2f3a95ae78217be16fa1f272c1fad4d3faecfd02939041a861d65826bb3f4cb7',
        ],
    'both exact bootstrap paths always resolve and register the customer verification email subsystem'
);
woo_ok(
    ($settingsInventory['source_files']['src/Internal/CustomerEmailVerification/Emails/CustomerVerifyEmail.php'] ?? null)
        === 'bd48b0c99038d9e43affe9aee1f406117930ac4af79bf49e4cd9c69b758f171f',
    'the always-registered customer verification email source is byte-identical across both exact artifacts'
);
woo_ok(
    ($settingsInventory['source_files']['includes/customizer/class-wc-shop-customizer.php'] ?? null)
        === '6ad7b3e724e21bdc0723b2385a4a5aab13b4cf26527f7d9b56a30270309f4e53'
        && ($settingsInventory['source_files']['includes/wc-template-functions.php'] ?? null)
            === '33bab39b8616f42c6ac8f9c1901977f6ea440208817268dad1b7c2f035e82485'
        && ($settingsInventory['source_files']['src/Admin/API/Options.php'] ?? null)
            === '475588425f5657d7d951ffcd33b64521bdae5d0e6fd3cad0d37e0d73b7a14dcb',
    'the store-notice Customizer writer, legacy settings endpoint, and site-wide frontend reader are exact across both artifacts'
);
$schedulerSources = [
    'includes/queue/class-wc-action-queue.php' => 'bb0a9a15659fa8cf8ddf7281a214dd12d7ef6c4b8c266711c1bcc24f46e14f10',
    'packages/action-scheduler/classes/ActionScheduler_ActionFactory.php' => '9316e8fc027e7eca88eb53918288d6b6dafbfc50a59db7c31aa2c4ea16dcf776',
    'packages/action-scheduler/classes/ActionScheduler_QueueRunner.php' => '0b5bdaf8a7fdb406edc1ac1eb93855dd483478e64278be9302cbfe5284f4e5e1',
    'packages/action-scheduler/classes/abstracts/ActionScheduler.php' => 'a0166451fa0bd3decad60bd746530d118aff72310d921728a781ac2432da4737',
    'packages/action-scheduler/classes/abstracts/ActionScheduler_Abstract_Schema.php' => '53a9c7b2b9f7f7c3f7672ba6206c9627328a7f9f508fbbd5cf663f3f13458928',
    'packages/action-scheduler/classes/data-stores/ActionScheduler_DBLogger.php' => 'bfa875ee05fcdf8bb973fe5226eaafac3f85932d3a06d63360fd0b3ed333e987',
    'packages/action-scheduler/classes/data-stores/ActionScheduler_DBStore.php' => '23e5ca462db869b2e5f4114edd116565822c963b45dfe8572cbe69803f101144',
    'packages/action-scheduler/classes/schema/ActionScheduler_LoggerSchema.php' => '12311d4a842b3ecb7602fcae7ea957d03b83a1f9f1f8a1746bc7dab453f26890',
    'packages/action-scheduler/classes/schema/ActionScheduler_StoreSchema.php' => 'c917c87ed0680f2a7881c56e7a60f8bd087dcd04497a597f388150735faf0c05',
    'src/Admin/Features/Features.php' => '095b85cd1c689dd7e712ce4b011eaf168d01e5b18fa21cf4e3bc1fba3d439fcc',
    'src/Admin/Schedulers/SchedulerTraits.php' => 'fa94da88dc1d4c367dddcf68435f3a6abad92ab27514b2f4ad04395a6e1d1a94',
    'src/Internal/Admin/Schedulers/OrdersScheduler.php' => 'f9a47c8f6b682cafca5d6c218a710a5a6b050000f2990f7a4890d2eb4d9798bb',
    'src/Internal/DataStores/Orders/CustomOrdersTableController.php' => 'b4d1a6772b064de9be6a80750074b0a9e371514f58131a1701cad6cd52ccb8bf',
    'src/Internal/DataStores/Orders/DataSynchronizer.php' => 'a10ff8e2e5820deeb5a032cccfc2ffca09a5134e3e87e388e0262a89a8805234',
    'src/Internal/Features/FeaturesController.php' => 'c39f44ebd0928be1c3f3a5066422defa5623705dc44f440f4572595def5866b2',
    'src/Internal/StockNotifications/DataRetentionController.php' => '22873bc914fae710dc9f5464057a67bd784cb7c2986e122fd5b5cfeffc5e0e0f',
];
foreach ($schedulerSources as $sourceFile => $sha256) {
    woo_ok(($settingsInventory['source_files'][$sourceFile] ?? null) === $sha256,
        "$sourceFile is exact and byte-identical across official WooCommerce 11.0.0/11.0.1");
}
$thumbnailSources = [
    'includes/class-wc-post-data.php' => '0c4cd2da527f3207083a55a6e941d26faa4962ebcc87c34d63ba2d10c1b889d9',
    'includes/class-wc-regenerate-images-request.php' => '42488e32d32f611af7a85a46e578b26f604727c8b4458ee7b86c83a621d72fb6',
    'includes/class-wc-regenerate-images.php' => '8211a8d6771a553b42799691815c2566415e762790de45d8148cb10dfe66dc5e',
    'includes/customizer/class-wc-shop-customizer.php' => '6ad7b3e724e21bdc0723b2385a4a5aab13b4cf26527f7d9b56a30270309f4e53',
    'includes/wc-core-functions.php' => '17bf218326de339c872eba8c9f855b73bb1c7874053c36774c35ef222927e684',
    'src/StoreApi/Schemas/V1/ImageAttachmentSchema.php' => 'ee557f57b75fe8849fb97002a08e5b779be98b721c770a95cad06d6d98979df9',
];
foreach ($thumbnailSources as $sourceFile => $sha256) {
    woo_ok(($settingsInventory['source_files'][$sourceFile] ?? null) === $sha256,
        "$sourceFile is exact and byte-identical across official WooCommerce 11.0.0/11.0.1");
}
foreach ((array) ($settingsInventory['artifacts'] ?? []) as $version => $sha256) {
    woo_ok(
        ($artifactLock['plugins']['woocommerce'][$version]['sha256'] ?? null) === $sha256,
        "settings source inventory is pinned to the official WooCommerce $version artifact"
    );
}
woo_ok(array_keys((array) ($settingsInventory['artifacts'] ?? [])) === ['11.0.0', '11.0.1']
    && ($manifest['version_range'] ?? null) === ['min' => '11.0.0', 'max' => '11.0.2'],
    'the source inventory and manifest admit exactly the same two official artifacts');
woo_ok(($externalProductInventory['format'] ?? null) === 'duo-woocommerce-external-product-inventory/v1',
    'external products use one exact source-derived inventory');
woo_ok(array_keys((array) ($externalProductInventory['artifacts'] ?? [])) === ['11.0.0', '11.0.1']
    && count((array) ($externalProductInventory['source_files'] ?? [])) === 7,
    'the external-product inventory binds both admitted artifacts and all seven native persistence/read paths');
foreach ((array) ($externalProductInventory['artifacts'] ?? []) as $version => $sha256) {
    woo_ok(($artifactLock['plugins']['woocommerce'][$version]['sha256'] ?? null) === $sha256,
        "external-product source evidence is pinned to official WooCommerce $version");
}
foreach ((array) ($externalProductInventory['source_files'] ?? []) as $sourceFile => $sha256) {
    woo_ok(is_string($sourceFile) && $sourceFile !== ''
        && is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', $sha256) === 1,
        "$sourceFile is byte-identical across official WooCommerce 11.0.0/11.0.1");
}
woo_ok(($externalProductInventory['contract']['authored_post_meta'] ?? null) === ['_button_text', '_product_url']
    && ($externalProductInventory['contract']['url_semantics'] ?? null) === 'source-home-tokenized-target-home-rebound',
    'the external-product inventory closes its two authored rows and target-local URL identity');
woo_ok(($termSurfaceInventory['format'] ?? null) === 'duo-woocommerce-term-surface-inventory/v1'
    && count((array) ($termSurfaceInventory['source_files'] ?? [])) === 14,
    'the brand/category/visual inventory binds all fourteen exact native paths');
foreach ((array) ($termSurfaceInventory['artifacts'] ?? []) as $version => $sha256) {
    woo_ok(($artifactLock['plugins']['woocommerce'][$version]['sha256'] ?? null) === $sha256,
        "term-surface source evidence is pinned to official WooCommerce $version");
}
foreach ((array) ($termSurfaceInventory['source_files'] ?? []) as $sourceFile => $sha256) {
    woo_ok(is_string($sourceFile) && $sourceFile !== ''
        && is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', $sha256) === 1,
        "$sourceFile binds the exact shared brand/category/visual implementation");
}
woo_ok(($termSurfaceInventory['authorities'] ?? null) === [
    'display_type' => ['product_brand', 'product_cat'],
    'image' => ['pa_*'],
    'order' => ['pa_*', 'product_brand', 'product_cat'],
    'thumbnail_id' => ['product_brand', 'product_cat'],
], 'term metadata authority is closed to the exact core category, brand, and global-attribute owners');

$inventoryClassifications = array_merge(
    (array) ($settingsInventory['literal_ids'] ?? []),
    (array) ($settingsInventory['computed_ids'] ?? [])
);
foreach ($inventoryClassifications as $name => $classification) {
    $rule = $policy->option_rule((string) $name);
    if (in_array($classification, ['authored', 'runtime', 'derived', 'env'], true)) {
        woo_ok(($rule['class'] ?? null) === $classification,
            "$name resolves to its source-audited $classification class");
        continue;
    }
    woo_ok($rule === null, "$name remains unclassified for its explicit $classification boundary");
    if ($classification !== 'ui') {
        woo_ok($policy->option_namespace((string) $name) !== null,
            "$name remains discovery-owned so populated unsupported state fails loudly");
    }
}
woo_ok(($settingsInventory['dynamic_families'] ?? null) === [
    'wc_stock_notifications_cycle_state_<product-id>' => 'runtime',
    'woocommerce_<core-email-id>_settings' => 'fail_closed_mixed_record',
    'woocommerce_email_templates_<core-email-id>_post_id' => 'fail_closed_block_email_editor',
    'woocommerce_feature_<registered-feature-slug>_enabled' => 'env',
], 'computed stock-cycle, email, template, and feature option families stay explicit in the source union');
foreach ([
    'woocommerce_feature_agentic_checkout_enabled',
    'woocommerce_feature_dual_code_graphql_api_enabled',
    'woocommerce_feature_fulfillments_enabled',
    'woocommerce_feature_point_of_sale_staff_enabled',
    'woocommerce_feature_push_notifications_enabled',
] as $featureOption) {
    woo_ok(($policy->option_rule($featureOption)['class'] ?? null) === 'env',
        "$featureOption is an explicit target platform feature control");
}
woo_ok(count((array) ($settingsInventory['feature_option_ids'] ?? [])) === 31,
    'the inventory freezes every exact core feature option key, including custom-key definitions');
foreach ((array) ($settingsInventory['feature_option_ids'] ?? []) as $featureOption => $classification) {
    woo_ok(
        ($policy->option_rule((string) $featureOption)['class'] ?? null) === $classification,
        "$featureOption resolves to its exact source-audited feature-option class"
    );
}

$closedRecords = (array) ($settingsInventory['closed_records'] ?? []);
$gatewayRecords = (array) ($closedRecords['gateway_settings'] ?? []);
woo_ok(array_keys($gatewayRecords) === [
    'woocommerce_bacs_accounts',
    'woocommerce_bacs_settings',
    'woocommerce_cheque_settings',
    'woocommerce_cod_settings',
], 'the source union enumerates every built-in gateway-owned settings record');
foreach ($gatewayRecords as $optionName => $record) {
    if ($optionName === 'woocommerce_bacs_accounts') {
        woo_ok(($policy->option_rule($optionName)['class'] ?? null) === 'env',
            "$optionName is an exact target-environment secret record");
        woo_ok(($record['boundary'] ?? null) === 'target_environment_secret_record',
            "$optionName never becomes a universal capture blocker");
        continue;
    }
    woo_ok($policy->option_rule((string) $optionName) === null
        && ($policy->option_namespace((string) $optionName)['owner'] ?? null) === 'woocommerce',
        "$optionName stays fail-closed until shared closed-subkey dispatch lands");
    woo_ok(is_string($record['boundary'] ?? null)
        && str_contains((string) $record['boundary'], 'pending_shared_dispatch'),
        "$optionName has an adapter-native contract awaiting only shared dispatch");
}
woo_ok(($gatewayRecords['woocommerce_bacs_accounts']['fields'] ?? null) === [
    'account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic',
], 'BACS account rows freeze all six bank-detail fields as target-owned secrets');
woo_ok(($gatewayRecords['woocommerce_cod_settings']['reference_fields'] ?? null) === [
    'enable_for_methods' => 'wc_zone_method[]',
], 'COD shipping-method restrictions are typed zone-method references, never opaque strings');

$emailInventory = (array) ($closedRecords['email_settings'] ?? []);
$emailRecords = (array) ($emailInventory['records'] ?? []);
$emailFieldClasses = (array) ($emailInventory['field_classes'] ?? []);
woo_ok(array_keys($emailRecords) === [
    'woocommerce_admin_payment_gateway_enabled_settings',
    'woocommerce_cancelled_order_settings',
    'woocommerce_customer_abandoned_cart_recovery_settings',
    'woocommerce_customer_cancelled_order_settings',
    'woocommerce_customer_completed_order_settings',
    'woocommerce_customer_failed_order_settings',
    'woocommerce_customer_fulfillment_created_settings',
    'woocommerce_customer_fulfillment_deleted_settings',
    'woocommerce_customer_fulfillment_updated_settings',
    'woocommerce_customer_invoice_settings',
    'woocommerce_customer_new_account_settings',
    'woocommerce_customer_note_settings',
    'woocommerce_customer_on_hold_order_settings',
    'woocommerce_customer_pos_completed_order_settings',
    'woocommerce_customer_pos_refunded_order_settings',
    'woocommerce_customer_processing_order_settings',
    'woocommerce_customer_refunded_order_settings',
    'woocommerce_customer_reset_password_settings',
    'woocommerce_customer_review_request_settings',
    'woocommerce_customer_stock_notification_settings',
    'woocommerce_customer_stock_notification_verified_settings',
    'woocommerce_customer_stock_notification_verify_settings',
    'woocommerce_customer_verify_email_settings',
    'woocommerce_failed_order_settings',
    'woocommerce_new_order_settings',
], 'WC_Emails, customer verification, and the optional stock-notification manager freeze all twenty-five distinct core settings option records by exact id');
woo_ok($emailFieldClasses === [
    'additional_content' => 'authored',
    'automated' => 'authored',
    'bcc' => 'authored',
    'cc' => 'authored',
    'delay_days' => 'authored',
    'email_type' => 'authored',
    'enabled' => 'authored',
    'heading' => 'authored',
    'heading_full' => 'authored',
    'heading_paid' => 'authored',
    'heading_partial' => 'authored',
    'intro_content' => 'authored',
    'preheader' => 'authored',
    'recipient' => 'env',
    'subject' => 'authored',
    'subject_full' => 'authored',
    'subject_paid' => 'authored',
    'subject_partial' => 'authored',
], 'every exact core email subkey has one portable or target-environment ruling');
woo_ok(count(array_filter(
    array_keys((array) ($settingsInventory['source_files'] ?? [])),
    static fn(string $path): bool => str_starts_with($path, 'includes/emails/class-wc-email')
)) === 23
    && isset($settingsInventory['source_files']['includes/class-wc-emails.php']),
    'the exact source union binds WC_Emails, the base email class, and every registered core email implementation');
woo_ok(count(array_filter(
    array_keys((array) ($settingsInventory['source_files'] ?? [])),
    static fn(string $path): bool => str_starts_with($path, 'src/Internal/StockNotifications/Emails/CustomerStockNotification')
)) === 3
    && isset($settingsInventory['source_files']['src/Internal/StockNotifications/Emails/EmailManager.php']),
    'the exact source union binds all three feature-gated stock-notification email implementations and their registrar');
foreach ($emailRecords as $optionName => $record) {
    woo_ok(preg_match('/^woocommerce_[a-z0-9_]+_settings$/D', (string) $optionName) === 1,
        "$optionName uses the exact WC_Settings_API option-key grammar");
    woo_ok($policy->option_rule((string) $optionName) === null
        && ($policy->option_namespace((string) $optionName)['owner'] ?? null) === 'woocommerce',
        "$optionName fails closed until the reviewed mixed-subkey seam is present");
    $fields = (array) ($record['fields'] ?? []);
    woo_ok($fields !== [] && count($fields) === count(array_unique($fields)),
        "$optionName has a nonempty duplicate-free native field inventory");
    foreach ($fields as $field) {
        woo_ok(isset($emailFieldClasses[$field]), "$optionName field $field has an authored or environment ruling");
    }
}
woo_ok(($emailFieldClasses['recipient'] ?? null) === 'env'
    && ($emailFieldClasses['cc'] ?? null) === 'authored'
    && ($emailFieldClasses['bcc'] ?? null) === 'authored'
    && ($emailFieldClasses['preheader'] ?? null) === 'authored',
    'admin recipient identity stays target-local while merchant CC/BCC/preheader content is explicitly classified');
woo_ok(($emailRecords['woocommerce_customer_refunded_order_settings']['availability'] ?? null)
        === 'always; also backs feature:block_email_editor customer_partially_refunded_order'
    && !isset($emailRecords['woocommerce_customer_partially_refunded_order_settings']),
    'the block-editor partial-refund class reuses the parent customer_refunded_order settings key exactly');
foreach ([
    'woocommerce_customer_stock_notification_settings',
    'woocommerce_customer_stock_notification_verified_settings',
    'woocommerce_customer_stock_notification_verify_settings',
] as $stockEmailOption) {
    woo_ok(($emailRecords[$stockEmailOption]['availability'] ?? null) === 'constant:WOOCOMMERCE_BIS_ALPHA_ENABLED'
        && in_array('intro_content', (array) ($emailRecords[$stockEmailOption]['fields'] ?? []), true),
        "$stockEmailOption is exact feature-gated core email state with its native intro-content field");
}
foreach (['woocommerce_addon_gateway_settings', 'woocommerce_customer_partially_refunded_order_settings'] as $nearMiss) {
    woo_ok($policy->option_rule($nearMiss) === null
        && ($policy->option_namespace($nearMiss)['owner'] ?? null) === 'woocommerce',
        "$nearMiss remains a loud addon or non-writer boundary");
}

$environmentEffectBoundaries = (array) ($closedRecords['target_environment_side_effect_options'] ?? []);
woo_ok(array_keys($environmentEffectBoundaries) === ['coming_soon'],
    'launch-store state is the one exact deployment-local side-effect family');
foreach ($environmentEffectBoundaries as $family => $record) {
    woo_ok(is_string($record['boundary'] ?? null) && str_starts_with((string) $record['boundary'], 'target_environment_'),
        "$family has an explicit deployment-local production boundary rather than an unverified direct write");
    foreach ((array) ($record['options'] ?? []) as $optionName) {
        woo_ok(($policy->option_rule((string) $optionName)['class'] ?? null) === 'env'
            && ($policy->option_namespace((string) $optionName)['owner'] ?? null) === 'woocommerce',
            "$optionName stays target-local across its irreversible native effects");
    }
}
woo_ok(($environmentEffectBoundaries['coming_soon']['new_install_rows'] ?? null) === [
    'woocommerce_coming_soon' => 'yes',
    'woocommerce_store_pages_only' => 'yes',
    'woocommerce_private_link' => 'absent_until_launch_api_initialization',
], 'every fresh Woo 11.0.x store remains certifiable with its exact launch-state rows');

$derivedConvergence = (array) ($closedRecords['derived_convergence_options'] ?? []);
woo_ok(array_keys($derivedConvergence) === ['thumbnail_images'],
    'thumbnail controls use one exact native request-convergence contract');
woo_ok(($derivedConvergence['thumbnail_images']['reader_defaults'] ?? null) === [
    'woocommerce_thumbnail_cropping' => '1:1',
    'woocommerce_thumbnail_cropping_custom_width' => '4',
    'woocommerce_thumbnail_cropping_custom_height' => '3',
    'woocommerce_thumbnail_image_width' => '300',
], 'unstored thumbnail controls retain every exact Woo 11.0.x reader default');
foreach ((array) ($derivedConvergence['thumbnail_images']['authored_options'] ?? []) as $optionName) {
    woo_ok(($policy->option_rule((string) $optionName)['class'] ?? null) === 'authored',
        "$optionName is portable merchant-authored thumbnail state");
}
woo_ok(($derivedConvergence['thumbnail_images']['native_callbacks'] ?? null) === [
    'image_get_intermediate_size' => ['WC_Regenerate_Images::filter_image_get_intermediate_size@10/3'],
    'update_post_metadata' => ['WC_Post_Data::update_post_metadata@10/5'],
    'wp_generate_attachment_metadata' => ['WC_Regenerate_Images::add_uncropped_metadata@10/1'],
    'wp_get_attachment_image_src' => ['WC_Regenerate_Images::maybe_resize_image@10/4'],
], 'the request-time projection binds the exact real Woo hook topology');
woo_ok(($derivedConvergence['thumbnail_images']['background_state'] ?? null) === [
    'woocommerce_maybe_regenerate_images_hash',
    'wp_<blog>_wc_regenerate_images_batch_*',
] && ($policy->option_rule('woocommerce_maybe_regenerate_images_hash')['class'] ?? null) === 'runtime',
'the Customizer hash and per-blog queue remain target runtime acceleration');

// DUO-3315: this is a manifest declaration, not an engine convention. The
// generic engine must obtain Woo's product/variation edge through Policy in
// exactly the same way an unrelated adapter obtains its own CPT relationship.
woo_ok(($manifest['post_types']['product']['children'] ?? null) === ['product_variation'],
    'Woo product declares product_variation as its manifest-owned child type');
woo_ok(method_exists($policy, 'child_post_types')
    && method_exists($policy, 'parent_post_types')
    && method_exists($policy, 'post_type_relation_closure'),
    'Policy exposes the generic parent/child relationship APIs used by the engine');
woo_ok($policy->child_post_types('product') === ['product_variation'],
    'Woo product children resolve from the shipped manifest');
woo_ok($policy->parent_post_types('product_variation') === ['product'],
    'Woo variation resolves its manifest-declared parent through the plural inverse API');
woo_ok($policy->post_type_relation_closure(['product_variation']) === ['product', 'product_variation'],
    'Woo relation closure walks the declared edge in both directions');

$optionNames = preg_split('/\s+/', trim(<<<'OPTIONS'
action_scheduler_hybrid_store_demarkation action_scheduler_migration_status
wc_blocks_db_schema_version wc_brands_show_description wc_customer_stock_notifications_admin_notice wc_customer_stock_notifications_product_sync_notice wc_downloads_approved_directories_mode wc_pending_batch_processes wc_variation_gallery_migration_completed_at
wc_feature_woocommerce_additional_variation_images_enabled
woocommerce_address_autocomplete_enabled woocommerce_admin_install_timestamp woocommerce_admin_notices
woocommerce_all_except_countries woocommerce_allow_bulk_remove_personal_data woocommerce_allow_tracking woocommerce_allowed_countries woocommerce_analytics_enabled woocommerce_brand_permalink
woocommerce_anonymize_completed_orders woocommerce_anonymize_refunded_orders woocommerce_attribute_lookup_direct_updates woocommerce_attribute_lookup_enabled woocommerce_attribute_lookup_optimized_updates
woocommerce_calc_discounts_sequentially woocommerce_calc_taxes woocommerce_cart_page_id woocommerce_cart_redirect_after_add woocommerce_cart_save_for_later_enabled woocommerce_catalog_columns woocommerce_catalog_rows
woocommerce_checkout_address_2_field woocommerce_checkout_company_field woocommerce_checkout_highlight_required_fields woocommerce_checkout_order_received_endpoint woocommerce_checkout_page_id woocommerce_checkout_pay_endpoint woocommerce_checkout_phone_field woocommerce_checkout_privacy_policy_text
woocommerce_cod_settings woocommerce_currency woocommerce_currency_pos woocommerce_custom_orders_table_created woocommerce_custom_orders_table_enabled woocommerce_db_version woocommerce_default_country woocommerce_default_customer_address woocommerce_delete_inactive_accounts woocommerce_demo_store woocommerce_dimension_unit
woocommerce_downloads_add_hash_to_filename woocommerce_downloads_count_partial woocommerce_downloads_deliver_inline woocommerce_downloads_grant_access_after_payment woocommerce_downloads_redirect_fallback_allowed woocommerce_downloads_require_login
woocommerce_email_auto_sync_with_theme woocommerce_email_background_color woocommerce_email_base_color woocommerce_email_body_background_color woocommerce_email_font_family woocommerce_email_footer_text woocommerce_email_footer_text_color woocommerce_email_from_address woocommerce_email_header_alignment woocommerce_email_header_image woocommerce_email_header_image_width woocommerce_email_improvements_disabled_count woocommerce_email_improvements_first_disabled_at woocommerce_email_improvements_last_disabled_at woocommerce_email_reply_to_address woocommerce_email_reply_to_enabled woocommerce_email_reply_to_name woocommerce_email_text_color
woocommerce_enable_ajax_add_to_cart woocommerce_enable_checkout_login_reminder woocommerce_enable_coupons woocommerce_enable_delayed_account_creation woocommerce_enable_guest_checkout woocommerce_enable_myaccount_registration woocommerce_enable_review_rating woocommerce_enable_reviews woocommerce_enable_shipping_calc woocommerce_enable_signup_and_login_from_checkout
woocommerce_erasure_request_removes_download_data woocommerce_erasure_request_removes_order_data
woocommerce_feature_abandoned_cart_recovery_enabled woocommerce_feature_block_email_editor_enabled woocommerce_feature_blueprint_enabled woocommerce_feature_cost_of_goods_sold_enabled woocommerce_feature_customer_review_request_enabled woocommerce_feature_deferred_transactional_emails_enabled woocommerce_feature_destroy-empty-sessions_enabled woocommerce_feature_email_improvements_enabled woocommerce_feature_fulfillments_enabled woocommerce_feature_mcp_integration_enabled woocommerce_feature_order_attribution_enabled woocommerce_feature_product_instance_caching_enabled woocommerce_feature_rate_limit_checkout_enabled woocommerce_feature_remote_logging_enabled woocommerce_feature_rest_api_caching_enabled woocommerce_feature_wc_visual_attribute_enabled
woocommerce_file_download_method woocommerce_force_ssl_checkout woocommerce_fulfillments_db_tables_created woocommerce_hide_out_of_stock_items woocommerce_hold_stock_minutes woocommerce_hooked_blocks_version woocommerce_hpos_datastore_caching_enabled woocommerce_hpos_fts_index_enabled woocommerce_inbox_variant_assignment woocommerce_logout_endpoint woocommerce_manage_stock woocommerce_maxmind_geolocation_settings
woocommerce_myaccount_add_payment_method_endpoint woocommerce_myaccount_delete_payment_method_endpoint woocommerce_myaccount_downloads_endpoint woocommerce_myaccount_edit_account_endpoint woocommerce_myaccount_edit_address_endpoint woocommerce_myaccount_lost_password_endpoint woocommerce_myaccount_orders_endpoint woocommerce_myaccount_page_id woocommerce_myaccount_payment_methods_endpoint woocommerce_myaccount_set_default_payment_method_endpoint woocommerce_myaccount_view_order_endpoint
woocommerce_newly_installed woocommerce_notify_backorder woocommerce_notify_low_stock woocommerce_notify_low_stock_amount woocommerce_notify_no_stock woocommerce_notify_no_stock_amount woocommerce_order_stats_has_fulfillment_column woocommerce_paypal_settings woocommerce_permalinks woocommerce_placeholder_image woocommerce_pickup_location_settings
woocommerce_pos_refund_returns_policy woocommerce_pos_store_address woocommerce_pos_store_email woocommerce_pos_store_phone
woocommerce_price_decimal_sep woocommerce_price_display_suffix woocommerce_price_num_decimals woocommerce_price_thousand_sep woocommerce_prices_include_tax woocommerce_product_match_featured_image_by_sku woocommerce_product_wishlist_enabled woocommerce_queue_flush_rewrite_rules woocommerce_refund_returns_page_id
woocommerce_registration_generate_password woocommerce_registration_generate_username woocommerce_registration_privacy_policy_text woocommerce_remote_variant_assignment woocommerce_review_rating_required woocommerce_review_rating_verification_label woocommerce_review_rating_verification_required woocommerce_schema_version
woocommerce_ship_to_countries woocommerce_ship_to_destination woocommerce_shipping_cost_requires_address woocommerce_shipping_debug_mode woocommerce_shipping_hide_rates_when_free woocommerce_shipping_tax_class woocommerce_shop_page_id woocommerce_show_marketplace_suggestions woocommerce_single_image_width woocommerce_specific_allowed_countries woocommerce_specific_ship_to_countries
woocommerce_stock_email_recipient woocommerce_stock_format woocommerce_store_address woocommerce_store_address_2 woocommerce_store_city woocommerce_store_id woocommerce_store_postcode woocommerce_task_list_tracked_completed_actions woocommerce_task_list_tracked_completed_tasks
woocommerce_tax_based_on woocommerce_tax_classes woocommerce_tax_display_cart woocommerce_tax_display_shop woocommerce_tax_round_at_subtotal woocommerce_tax_total_display woocommerce_terms_page_id woocommerce_thumbnail_image_width woocommerce_trash_cancelled_orders woocommerce_trash_failed_orders woocommerce_trash_pending_orders woocommerce_unforce_ssl_checkout woocommerce_version woocommerce_weight_unit
OPTIONS));

$failClosedOptionNames = ['woocommerce_cod_settings'];
foreach ($optionNames as $name) {
    woo_ok($policy->option_namespace($name) !== null, "$name is discovery-owned");
    if (in_array($name, $failClosedOptionNames, true)) {
        woo_ok(
            $policy->owned_option_rule($name) === null,
            "$name stays explicitly fail-closed instead of carrying an opaque mixed or reference-bearing record"
        );
        continue;
    }
    woo_ok($policy->owned_option_rule($name) !== null, "$name has an explicit class");
}
woo_ok($policy->option_namespace('woocommerce_future_unreviewed') !== null, 'future Woo option remains visible to discovery');
woo_ok($policy->owned_option_rule('woocommerce_future_unreviewed') === null, 'future Woo option remains loud, never silently classified');
foreach (['action_scheduler_migration_status', 'woocommerce_paypal_settings', 'woocommerce_maxmind_geolocation_settings', 'woocommerce_email_from_address', 'woocommerce_stock_email_recipient'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['class'] ?? '') === 'env', "$name stays environment-local");
}
foreach (['wc_blocks_db_schema_version', 'wc_customer_stock_notifications_product_sync_notice', 'wc_pending_batch_processes', 'wc_variation_gallery_migration_completed_at', 'woocommerce_admin_notices', 'woocommerce_fulfillments_db_tables_created', 'woocommerce_task_list_tracked_completed_tasks', 'woocommerce_unforce_ssl_checkout'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['class'] ?? '') === 'runtime', "$name stays runtime-local");
}
foreach (['wc_brands_show_description', 'woocommerce_brand_permalink', 'woocommerce_catalog_columns', 'woocommerce_catalog_rows', 'woocommerce_enable_delayed_account_creation', 'woocommerce_feature_wc_visual_attribute_enabled', 'woocommerce_hooked_blocks_version'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['class'] ?? '') === 'authored', "$name stays portable merchant-authored state");
}
woo_ok(
    ($policy->option_rule('woocommerce_demo_store_notice')['class'] ?? null) === 'authored'
        && ($settingsInventory['closed_records']['store_notice'] ?? null) === [
            'option' => 'woocommerce_demo_store_notice',
            'writer' => 'WC_Shop_Customizer::add_store_notice_section/wp_kses_post',
            'readers' => ['woocommerce_demo_store', 'wc-admin/options'],
            'max_bytes' => 262144,
            'boundary' => 'authored_bounded_native_html',
        ],
    'the normal Customizer store notice is portable bounded native HTML rather than an override-only gap'
);
foreach (['woocommerce_catalog_columns', 'woocommerce_catalog_rows'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['lint_ok'] ?? false) === true, "$name is audited as a numeric grid count, not an entity reference");
}
woo_ok(($policy->owned_option_rule('woocommerce_hooked_blocks_version')['ref'] ?? null) === null, 'hooked-block rendering policy remains an opaque ref-free authored record');
woo_ok($policy->owned_option_rule('woocommerce_cod_settings') === null,
    'core COD settings fail closed until method-instance references use the reviewed typed schema');
foreach (['pickup_location_pickup_locations', 'woocommerce_pickup_location_settings'] as $name) {
    woo_ok(($policy->option_rule($name)['class'] ?? null) === 'authored'
        && ($policy->option_rule($name)['plain_data'] ?? null) === true,
        "$name is class-disabled authored local-pickup state with a closed native record schema");
}
woo_ok(($policy->option_rule('woocommerce_placeholder_image')['ref'] ?? '') === 'post', 'placeholder image uses portable post identity');
woo_ok(($policy->option_rule('woocommerce_refund_returns_page_id')['ref'] ?? '') === 'post', 'refund page uses portable post identity');
woo_ok(($policy->option_rule('woocommerce_flat_rate_41_settings')['ref'] ?? null) === null
    && $policy->match_option_name_ref('woocommerce_flat_rate_41_settings') !== null,
    'shipping instance settings use option-name embedded typed identity');

// DUO-3509: three rows a fresh WooCommerce 11.0.1 install carries that this
// inventory did not name. Two of them fall OUTSIDE this manifest's own
// option_namespaces (`^(?:action_scheduler|wc|woocommerce)_`), which is why
// they are asked through option_rule() rather than owned_option_rule() —
// they reached `duo pending` through the journal, not through namespace
// enumeration, and a namespace claim for `default_product_cat` would be a
// claim over a name WordPress core's own `default_category` is a sibling of.
woo_ok(($policy->option_rule('default_product_cat')['class'] ?? '') === 'authored'
    && ($policy->option_rule('default_product_cat')['ref'] ?? null) === 'term',
    'the fallback product category is authored and resolves through the term ledger');
woo_ok(($policy->option_rule('product_cat_children')['class'] ?? '') === 'derived',
    "core's product_cat hierarchy cache is excluded as derived, not carried as a foreign id graph");
woo_ok(($policy->option_rule('product_brand_children')['class'] ?? '') === 'derived',
    "core's product_brand hierarchy cache is excluded as derived, not carried as a foreign id graph");
foreach (['auto_fulfill_downloadable', 'auto_fulfill_virtual'] as $name) {
    woo_ok(($policy->option_rule($name)['class'] ?? '') === 'authored',
        "$name is an explicit unprefixed merchant behavior setting");
}
woo_ok(($policy->option_rule('current_theme_supports_woocommerce')['class'] ?? '') === 'env'
    && ($policy->option_rule('current_theme_supports_woocommerce')['required'] ?? null) === false,
    'current theme Woo support is optional target-environment state');
woo_ok(($manifest['taxonomies']['product_brand'] ?? null) === [
    'class' => 'authored',
    'object_type' => ['product'],
    'update_count_callback' => '_wc_term_recount',
], 'Woo core Brands taxonomy is authored with its exact product/count registration facts');
foreach (['product_brands', 'exclude_product_brands'] as $metaKey) {
    woo_ok(($policy->post_meta_rule($metaKey)['class'] ?? null) === 'authored'
        && ($policy->post_meta_rule($metaKey)['ref'] ?? null) === 'term[]',
        "coupon $metaKey stores portable brand term references");
}
woo_ok(($manifest['taxonomies']['wc_fulfillment_shipping_provider']['class'] ?? null) === 'authored'
    && !array_key_exists('object_type', $manifest['taxonomies']['wc_fulfillment_shipping_provider']),
    'feature-gated custom shipping providers are authored terms and do not invent an object relationship');
foreach (['_button_text', '_cogs_total_value', '_cogs_value_is_additive', '_product_url'] as $metaKey) {
    woo_ok(($policy->post_meta_rule($metaKey)['class'] ?? null) === 'authored',
        "$metaKey is exact merchant-authored core product state");
}
foreach (['_headstart_post', '_migration_data', '_original_id', '_original_product_id', '_original_url', '_original_variant_id', '_product_template_id', '_wc_attachment_source', '_wc_variation_gallery_legacy_fallback_disabled'] as $metaKey) {
    woo_ok(($policy->post_meta_rule($metaKey)['class'] ?? null) === 'env',
        "$metaKey is target-local importer/migration provenance");
}
woo_ok(($policy->post_meta_rule('duplicate_temp_brand_ids')['class'] ?? null) === 'runtime',
    'the transient Brands duplication handoff is runtime workflow state');
woo_ok($policy->meta_rule_for_post('_wc_additional_variation_images', []) === null
    && ($policy->meta_rule_for_post('_wc_additional_variation_images', [
        '_wc_variation_gallery_legacy_fallback_disabled' => 'yes',
    ])['class'] ?? null) === 'env',
    'legacy extension gallery rows fail closed until the exact core migration sentinel proves them inert');
foreach (['display_type', 'order', 'color', 'tracking_url_template', 'icon'] as $metaKey) {
    woo_ok(($policy->term_meta_rule($metaKey)['class'] ?? null) === 'authored',
        "$metaKey is exact authored Woo term metadata");
}
woo_ok(($policy->term_meta_rule('image')['ref'] ?? null) === 'post'
    && ($policy->term_meta_rule('thumbnail_id')['ref'] ?? null) === 'post',
    'category/brand/visual term images rebind through attachment identities');
woo_ok(($policy->term_meta_rule('product_ids')['class'] ?? null) === 'derived'
    && ($policy->meta_rule_for_term('product_count_product_cat', [])['class'] ?? null) === 'derived'
    && ($policy->meta_rule_for_term('product_count_product_tag', [])['class'] ?? null) === 'derived'
    && ($policy->meta_rule_for_term('product_count_product_brand', [])['class'] ?? null) === 'derived',
    'Woo term product-id/count caches are explicitly derived');
woo_ok($policy->meta_rule_for_term('product_count_pa_color', []) === null
    && $policy->meta_rule_for_term('product_count_product_cat_extra', []) === null
    && $policy->meta_rule_for_post('product_count_product_cat', []) === null,
    'the exact term-only product-count ruling cannot hide attribute, near-miss, or post metadata');
woo_ok(($policy->meta_rule_for_user('wc_push_notification_preferences_wp', [])['class'] ?? null) === 'runtime',
    'blog-1 push preferences are runtime per-device user state');
$GLOBALS['wooContractBlogId'] = 9;
woo_ok(($policy->meta_rule_for_user('wc_push_notification_preferences_wp_9', [])['class'] ?? null) === 'runtime'
    && $policy->meta_rule_for_user('wc_push_notification_preferences_wp_8', []) === null
    && $policy->meta_rule_for_user('wp_9_wc_push_notification_preferences', []) === null,
    'push preference classification binds the exact current blog suffix and refuses sibling/prefix-shaped keys');
$GLOBALS['wooContractBlogId'] = 1;
woo_ok(($manifest['post_types']['wc_push_token']['class'] ?? null) === 'runtime',
    'push token posts are target-sovereign runtime state');
woo_ok(($policy->owned_option_rule('wc_installing')['class'] ?? '') === 'runtime',
    "WC_Install's raw-SQL install mutex row stays runtime-local");
// The name is computed (`'schema-' . static::class`), so the rule is a
// pattern and must cover a schema class this repo has never seen.
foreach (['ActionScheduler_StoreSchema', 'ActionScheduler_LoggerSchema', 'ActionScheduler_FutureSchema'] as $schemaClass) {
    woo_ok(($policy->option_rule("schema-$schemaClass")['class'] ?? '') === 'runtime',
        "schema-$schemaClass is runtime — its value embeds this environment's own migration timestamp");
}

$expectedTables = preg_split('/\s+/', trim(<<<'TABLES'
actionscheduler_actions actionscheduler_claims actionscheduler_groups actionscheduler_logs
wc_admin_note_actions wc_admin_notes wc_category_lookup wc_customer_lookup wc_download_log wc_email_unsubscribes wc_order_addresses wc_order_coupon_lookup wc_order_fulfillment_meta wc_order_fulfillments wc_order_operational_data wc_order_product_lookup wc_order_stats wc_order_tax_lookup wc_orders wc_orders_meta wc_product_attributes_lookup wc_product_download_directories wc_product_meta_lookup wc_rate_limits wc_reserved_stock wc_stock_notificationmeta wc_stock_notifications wc_tax_rate_classes wc_webhooks
woocommerce_api_keys woocommerce_attribute_taxonomies woocommerce_downloadable_product_permissions woocommerce_log woocommerce_order_itemmeta woocommerce_order_items woocommerce_payment_tokenmeta woocommerce_payment_tokens woocommerce_sessions woocommerce_shipping_zone_locations woocommerce_shipping_zone_methods woocommerce_shipping_zones woocommerce_tax_rate_locations woocommerce_tax_rates
TABLES));
$declaredTables = $policy->declared_tables();
foreach ($expectedTables as $table) woo_ok(isset($declaredTables[$table]), "$table has an explicit disposition");
foreach (['wc_product_meta_lookup', 'wc_product_attributes_lookup', 'wc_category_lookup'] as $table) {
    woo_ok(($declaredTables[$table]['class'] ?? '') === 'derived', "$table is rebuilt derived state");
}
foreach (['wc_order_fulfillment_meta', 'wc_order_fulfillments', 'wc_stock_notificationmeta', 'wc_stock_notifications'] as $table) {
    woo_ok(($declaredTables[$table]['class'] ?? '') === 'runtime', "$table remains target operational/customer state");
}
woo_ok(($declaredTables['wc_tax_rate_classes']['class'] ?? '') === 'authored_snapshot', 'merchant tax classes are portable authored state');

$actions = $policy->actions();
$actionSources = array_map(
    static fn(array $row): string => \Duo\Policy::action_source($row, (int) $row['index']),
    $actions
);
woo_ok($actionSources === [
    'native:transient.delete',
    'provider:woocommerce-cache/invalidate_cache_groups',
    'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
    'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
    'provider:woocommerce-fulfillment-prerequisites/verify_fulfillment_prerequisites',
    'provider:woocommerce-scheduler-settings/reconcile_analytics_import_schedule',
    'provider:woocommerce-scheduler-settings/reconcile_stock_notification_retention',
    'provider:woocommerce-product-lookups/rebuild_product_lookups',
], 'manifest owns the bounded attribute-transient, shipping/tax cache, fresh-process hierarchy/brand-route, '
    . 'read-only fulfillment prerequisite, scheduler projections, and per-product lookup repairs');
$productActions = array_values(array_filter(
    $actions,
    static fn(array $row): bool => ($row['provider'] ?? null) === 'woocommerce-product-lookups'
));
$cacheAction = array_values(array_filter(
    $actions,
    static fn(array $row): bool => ($row['provider'] ?? null) === 'woocommerce-cache'
))[0] ?? [];
woo_ok(in_array('option:pickup_location_pickup_locations', $cacheAction['triggers'] ?? [], true)
    && in_array('option:woocommerce_pickup_location_settings', $cacheAction['triggers'] ?? [], true),
    'both local-pickup REST records trigger the receipt-bound native shipping-cache invalidation');
$productAction = $productActions[0] ?? [];
// DUO-3342: the third entry is a MIGRATED dispatch, not a new repair. It is
// bounded by the same two post-type triggers the retired regen_dependency
// declarations covered, and those declarations are gone — a manifest carrying
// both would be two dispatchers over one post type, which negotiation refuses.
woo_ok(count($productActions) === 1
    && ($productAction['triggers'] ?? null) === ['post:product', 'post:product_variation'],
    'the product lookup repair stays bounded to the two product post types it always covered');
woo_ok($policy->regen_batch_post_types() === [] && $policy->regen_dependency('product') === null,
    'and the batch regenerator channel it replaced claims no Woo post type any more');
// DUO-3341: the legacy whole-catalog projection class is deleted outright,
// not quarantined. Engine core must carry no WooCommerce-named production
// source and the bootstrap must not load one; Woo semantics live in
// manifests/woocommerce.json and the provider/native-action contract.
// These assertions fail against the pre-DUO-3341 tree (class present,
// require_once in agent/duo.php), which is this issue's regression proof.
// Recursive since the module move (ROUND 3 TRAIN 1): agent/src is a tree of
// module directories, so a flat scandir() would enumerate module names and let
// both checks below pass for the wrong reason. The deleted class is now looked
// for ANYWHERE under agent/src, which is the claim DUO-3341 actually makes.
$engineSrcEntries = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/agent/src', FilesystemIterator::SKIP_DOTS)) as $engineSrcEntry) {
    if ($engineSrcEntry instanceof SplFileInfo && $engineSrcEntry->isFile() && $engineSrcEntry->getExtension() === 'php') {
        $engineSrcEntries[] = $engineSrcEntry->getFilename();
    }
}
woo_ok(!in_array('WooCommerceContract.php', $engineSrcEntries, true), 'the whole-catalog Woo projection class is deleted from engine core (DUO-3341)');
woo_ok(count($engineSrcEntries) > 2, 'agent/src enumerates non-empty for the WooCommerce-named source scan');
$wooNamedEngineSources = array_values(array_filter(
    $engineSrcEntries,
    static fn(string $name): bool => stripos($name, 'woocommerce') !== false || stripos($name, 'woo') === 0
));
woo_ok($wooNamedEngineSources === [], 'no WooCommerce-named production class remains under agent/src (DUO-3341)');
woo_ok(!str_contains((string) file_get_contents($root . '/agent/duo.php'), 'WooCommerce'), 'agent bootstrap loads no WooCommerce-named engine source (DUO-3341)');
$unsupportedDeletes = [
    'post:product',
    'post:product_variation',
    'table:woocommerce_attribute_taxonomies',
    'table:woocommerce_shipping_zone_locations',
    'table:woocommerce_shipping_zone_methods',
    'table:woocommerce_shipping_zones',
    'table:woocommerce_tax_rate_locations',
    'table:woocommerce_tax_rates',
];
woo_ok(!array_key_exists('deletions', $manifest), 'shipped Woo manifest declares no deletion authority');
foreach ($unsupportedDeletes as $selector) {
    woo_ok($policy->deletion_capability($selector) === null, "$selector deletion is fail-closed");
}
$wooDisposition = $wooDispositionDocument;
woo_ok(!in_array('delete', $wooDisposition['capabilities']['operations'] ?? [], true), 'external capability registry does not advertise Woo deletion');
woo_ok(($wooDisposition['capabilities']['deletion_semantics']['supported'] ?? null) === [], 'external capability registry declares no supported Woo deletion surface');
$declaredUnsupportedDeletes = $wooDisposition['capabilities']['deletion_semantics']['unsupported'] ?? null;
woo_ok($declaredUnsupportedDeletes === $unsupportedDeletes,
    'external capability registry enumerates every shipped Woo deletion selector as unsupported');
$unsupportedApplySurfaces = array_values(array_filter(
    (array) ($wooDisposition['unsupported'] ?? []),
    static fn(array $row): bool => ($row['operation'] ?? null) === 'apply'
));
woo_ok($unsupportedApplySurfaces === [],
    'external capability registry advertises no derived apply gap after exact hierarchy/attribute repair');
woo_ok(!in_array('derived.wc_product_attributes_lookup', array_column(
    (array) ($wooDisposition['unsupported'] ?? []),
    'surface'
), true), 'attribute lookup repair is no longer mislabeled as an unsupported apply surface');
$matrixHarness = (string) file_get_contents($root . '/sandbox/tests/certify/certify_version_matrix.sh');
$wooSeedHarness = (string) file_get_contents($root . '/sandbox/conformance/seeds/woocommerce.sh');
$wooCheckHarness = (string) file_get_contents($root . '/sandbox/conformance/checks/woocommerce.sh');
foreach ([
    'new WC_Product_External()',
    "new WP_REST_Request('PUT', '/wc/v3/products/",
    "home_url('/partner/checkout?campaign=summer&locale=ja')",
    'اشتر الآن — 東京',
] as $externalSeedWitness) {
    woo_ok(str_contains($wooSeedHarness, $externalSeedWitness),
        "external-product source fixture pins $externalSeedWitness");
}
foreach ([
    "'/wc/v3/products/categories/",
    "'/wc/v3/products/brands/",
    "'display' => 'subcategories'",
    "--type=wc-visual",
    'VisualAttributeTermMeta::save_term_visual_from_request',
    "'/wc/v3/products/attributes/",
    "update_post_meta(\$COUPON_ID, 'product_brands'",
] as $termSeedWitness) {
    woo_ok(str_contains($wooSeedHarness, $termSeedWitness),
        "category/brand/visual source fixture pins $termSeedWitness");
}
foreach ([
    "new WP_REST_Request('GET', '/wc/v3/products/",
    '/wp-json/wc/store/v1/products/',
    '/product/conformance-external-partner/',
    'html_entity_decode',
    'external-product Store API leaked the source host',
    'external-product frontend leaked the source host',
] as $externalCheckWitness) {
    woo_ok(str_contains($wooCheckHarness, $externalCheckWitness),
        "external-product target fixture pins $externalCheckWitness");
}
foreach ([
    "'/wc/v3/products/brands/'",
    'VisualAttributeTermMeta::prime_term_visual_caches',
    '/wp-json/wc/store/v1/products/brands',
    '__experimental_visual=true',
    'product-category archive leaked the source host',
    'woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
] as $termCheckWitness) {
    woo_ok(str_contains($wooCheckHarness, $termCheckWitness),
        "category/brand/visual target fixture pins $termCheckWitness");
}
woo_ok(str_contains($matrixHarness, 'update_option("default_category", (int) $category->term_id)'), 'version-matrix resets the core default-category reference before each plugin boundary');
woo_ok(str_contains($matrixHarness, 'check_woocommerce_boundary_lifecycle "$WOO_VERSION" "$ARTIFACT_2"'),
    'each exact WooCommerce boundary runs lifecycle evidence before the populated upgrade leg');
foreach ([
    'woocommerce_boundary_storage_hash',
    'WC_REMOVE_ALL_DATA',
    'e06e0c2086f695d39f5d9edead87cd4faeb0ea45184d77e7d8fe5588abfde48e',
    'default uninstall changed retained authored or target-runtime storage',
    'missing-code refusal partially changed retained storage',
    'lifecycle artifact digest moved before reinstall proof',
    'check_woocommerce_content',
    '.tmp-woo-lifecycle-final',
] as $lifecycleWitness) {
    woo_ok(str_contains($matrixHarness, $lifecycleWitness),
        "exact WooCommerce lifecycle matrix pins $lifecycleWitness");
}
woo_ok(substr_count($matrixHarness, 'check_woocommerce_boundary_lifecycle "$WOO_VERSION" "$ARTIFACT_2"') === 1
    && str_contains($matrixHarness, 'for WOO_VERSION in 11.0.0 11.0.1; do'),
    'one lifecycle call inside the exact two-artifact loop covers 11.0.0 and 11.0.1 independently');

echo "PASS: WooCommerce 11.0.x option/table inventory and rebuild contract are explicit\n";
