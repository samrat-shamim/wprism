<?php
declare(strict_types=1);

// issue #3225: offline contract inventory for the exact WooCommerce 11.0.x
// fixture. The live conformance suite proves behavior; this fast test keeps a
// future option/table addition from becoming invisible by accident.

define('WPRISM_SPEC_VERSION', 3);
require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
require dirname(__DIR__, 4) . '/agent/src/Code/Code.php';
require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';
require dirname(__DIR__, 4) . '/agent/src/Review/Lint.php';

use WPrism\CaptureCandidateBuilder;
use WPrism\CommandRefusalException;
use WPrism\Policy;

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
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Capture/CaptureCandidateBuilder.php';
if (!function_exists('get_taxonomies')) {
    /** @return array<string,string> */
    function get_taxonomies(array|string $args = [], string $output = 'names', string $operator = 'and'): array {
        $names = ['product_brand', 'product_visibility'];
        return $output === 'names' ? array_combine($names, $names) : [];
    }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object|false {
        return in_array($taxonomy, ['product_brand', 'product_visibility'], true)
            ? (object) ['object_type' => ['product']]
            : false;
    }
}

$manifest = json_decode((string) file_get_contents($root . '/adapter-packages/woocommerce/package/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
// One document per subject since WP-4.4 (spec/repo-format.md § v3.4): this
// suite reads woocommerce's reviewed entry, not the whole library.
$wooDispositionDocument = json_decode((string) file_get_contents($root . '/adapter-packages/woocommerce/package/disposition.json'), true, flags: JSON_THROW_ON_ERROR);
// No scratch library: $manifest IS the WooCommerce package manifest, so the v2
// shipped-membership proof compares the frozen bytes against the very file they
// were read from — the strongest form of the claim this snapshot makes.
$policy = Policy::from_snapshot([
    'dispositions' => null,
    'format' => 'wprism-policy-snapshot/v6',
    'adapter_sources' => ['certificates' => [], 'format' => 'wprism-adapter-sources/v2', 'out_of_tree' => []],
    'manifests' => [$manifest],
    'site' => ['manifests' => ['woocommerce'], 'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []], 'spec_version' => WPRISM_SPEC_VERSION],
], \WPrism\AdapterLibrary::fromSourcePackage($root, 'woocommerce'));
woo_ok($policy->option_rule_details('pickup_location_pickup_locations') === [
    'rule' => ['class' => 'authored', 'plain_data' => true, 'allow_pii' => true, 'autoload' => 'preserve'],
    'source' => 'woocommerce',
], 'the pre-apply pickup-location diagnostic seam resolves through the loaded Woo manifest');
woo_ok($policy->option_rule_details('woocommerce_bacs_settings') === [
    'rule' => [
        'class' => 'env',
        'required' => false,
        'absent_autoload' => 'yes',
        'closed_sub_keys' => true,
        'sub_keys' => [
            'enabled' => ['class' => 'authored'],
            'title' => ['class' => 'authored'],
            'description' => ['class' => 'authored'],
            'instructions' => ['class' => 'authored'],
            'account_details' => ['class' => 'derived', 'native_default_completion' => true],
            'account_name' => ['class' => 'env'],
            'account_number' => ['class' => 'env'],
            'bank_name' => ['class' => 'env'],
            'sort_code' => ['class' => 'env'],
            'iban' => ['class' => 'env'],
            'bic' => ['class' => 'env'],
        ],
        'autoload' => 'preserve',
    ],
    'source' => 'woocommerce',
], 'the pre-apply BACS diagnostic seam retains its closed mixed-class record authority');
woo_ok($policy->post_meta_rule_details('_product_url') === [
    'rule' => ['class' => 'authored'],
    'source' => 'woocommerce',
] && $policy->term_meta_rule_details('display_type') === [
    'rule' => ['class' => 'authored'],
    'source' => 'woocommerce',
], 'the pre-apply product and term diagnostic seams resolve through Woo authority');

$settingsInventory = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/woocommerce-core-11.0-settings.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
require_once $root . '/tools/src/ArtifactLibrary.php';
$artifactLock = \WPrism\Tooling\ArtifactLibrary::loadPackage($root, 'woocommerce');
$externalProductInventory = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/woocommerce-core-11.0-external-product.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$termSurfaceInventory = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/woocommerce-core-11.0-terms.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
woo_ok(($settingsInventory['format'] ?? null) === 'wprism-woocommerce-settings-inventory/v1',
    'the source-audited settings inventory uses the exact reviewed schema');
woo_ok(count((array) ($settingsInventory['literal_ids'] ?? [])) === 148,
    'the exact 11.0.0/11.0.1 visible-settings union freezes all 148 reviewed source ids');
woo_ok(count((array) ($settingsInventory['operational_literal_ids'] ?? [])) === 105,
    'the exact whole-core scan separately freezes all 105 Woo-prefixed operational, migration, integration, and product-setting literals outside the visible-settings union');
$operationalSourceUnion = (array) ($settingsInventory['operational_source_union'] ?? []);
$operationalClassificationBytes = '';
foreach ((array) ($settingsInventory['operational_literal_ids'] ?? []) as $name => $classification) {
    $operationalClassificationBytes .= $name . "\t" . $classification . "\n";
}
$operationalSharedSources = (array) ($operationalSourceUnion['shared_source_files'] ?? []);
$operationalVersionSources = (array) ($operationalSourceUnion['version_specific_source_files'] ?? []);
$operationalSourcePaths = array_values(array_unique(array_merge(
    array_keys($operationalSharedSources),
    array_keys($operationalVersionSources)
)));
sort($operationalSourcePaths, SORT_STRING);
$operationalSourceBytes = '';
foreach ($operationalSourcePaths as $sourcePath) {
    if (array_key_exists($sourcePath, $operationalSharedSources)) {
        $sha256 = $operationalSharedSources[$sourcePath];
        woo_ok(is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', $sha256) === 1,
            "$sourcePath has one exact byte-identical operational source hash");
        $operationalSourceBytes .= $sourcePath . "\t" . $sha256 . "\n";
        continue;
    }
    $versionHashes = (array) ($operationalVersionSources[$sourcePath] ?? []);
    woo_ok(array_keys($versionHashes) === ['11.0.0', '11.0.1'],
        "$sourcePath binds both exact artifact-specific operational source hashes");
    foreach ($versionHashes as $version => $sha256) {
        woo_ok(is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', $sha256) === 1,
            "$sourcePath@$version has one exact operational source hash");
        $operationalSourceBytes .= $sourcePath . "\t" . $version . "\t" . $sha256 . "\n";
    }
}
woo_ok(($operationalSourceUnion['source_count'] ?? null) === 82
    && ($operationalSourceUnion['shared_source_count'] ?? null) === 80
    && count($operationalSharedSources) === 80
    && count($operationalVersionSources) === 2
    && count($operationalSourcePaths) === 82
    && ($operationalSourceUnion['source_set_sha256'] ?? null)
        === hash('sha256', $operationalSourceBytes)
    && ($operationalSourceUnion['classification_sha256'] ?? null)
        === hash('sha256', $operationalClassificationBytes),
    'the residual classification is bound to all eighty-two exact source paths and hashes plus its sorted classified-byte inventory');
woo_ok(($operationalSourceUnion['classification_counts'] ?? null) === [
    'authored' => 1,
    'dynamic_fragment' => 1,
    'env' => 16,
    'extension_boundary' => 5,
    'extension_prefix' => 2,
    'runtime' => 77,
    'runtime_prefix' => 3,
] && ($operationalSourceUnion['version_specific_source_files'] ?? null) === [
    'includes/class-woocommerce.php' => [
        '11.0.0' => '5982ef2ab60231218cc71a2ba9bd387496d32c1a5eeb5468116d51137bbd7ef4',
        '11.0.1' => '2f3a95ae78217be16fa1f272c1fad4d3faecfd02939041a861d65826bb3f4cb7',
    ],
    'includes/wc-product-functions.php' => [
        '11.0.0' => '19aa8c372aef52ec9526d91115003e6c1f35cf5cd783bfa873e55c951c78c9be',
        '11.0.1' => 'e73e5fe1dd2844bf2bdc3a59bf728b6d38aee79b2152f616f2f993f515975c76',
    ],
], 'the operational union freezes every class count and both exact artifact-specific source authorities');
woo_ok(count((array) ($settingsInventory['source_files'] ?? [])) === 97,
    'the inventory binds all ninety-seven byte-identical visible, operational, container, migration, gateway, email, pickup, scheduler, stock-notification, launch, image-regeneration, attachment-bootstrap, frontend-read, and conditional tracking-topology sources');
foreach ((array) ($settingsInventory['source_files'] ?? []) as $sourceFile => $sha256) {
    woo_ok(
        is_string($sourceFile) && $sourceFile !== ''
            && is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', $sha256) === 1,
        "$sourceFile carries one exact shared 11.0.0/11.0.1 source digest"
    );
}
woo_ok(
    ($settingsInventory['source_files']['includes/admin/class-wc-admin-upload-downloadable-product.php'] ?? null)
        === '9429ae47760787c84156b9c29514294e49b0d4ec98856b43bb0a704435f82c1e'
        && ($settingsInventory['source_files']['includes/admin/class-wc-admin-post-types.php'] ?? null)
            === '65743558642c7c92aa15d5971ebafc310d4e3a8dd076c84f1e033861b6d6c601'
        && ($settingsInventory['source_files']['src/Admin/API/Init.php'] ?? null)
            === 'b0d48420c2337f176bbab5d1b1bba662e6f8b58c96eef33cb04ab0e5d0727691',
    'both exact admin bootstrap paths register one byte-identical downloadable-upload callback authority'
);
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
woo_ok(
    ($settingsInventory['source_files']['includes/wc-page-functions.php'] ?? null)
        === '11281d2800a401e7cf8cc167d994bfe2fe9ad436c570deff3ae997c5b32b1338'
        && ($settingsInventory['version_specific_source_files']['src/Internal/OrderReviews/Endpoint.php'] ?? null)
            === [
                '11.0.0' => 'dda95b0edb8ac48434477aaf4664f267b477f858ab44da248752557f91bea9c8',
                '11.0.1' => '326511d748cc282caac2bfaeb22451e5b80f73209dcdbea81c2a4ef32570b6f3',
            ],
    'the review-page resolver and both exact route implementations are source-bound despite the 11.0.1 auth hardening'
);
$trackingSources = [
    'includes/tracks/class-wc-site-tracking.php' => '97906f707989abdb652de6aca71c90095ee4ae137bb2a736e9e01a8f017acdd0',
    'includes/tracks/events/class-wc-settings-tracking.php' => 'ed15be45c452f20f0e9c256f6975367972fa3a82a6450e600877d09229dad6f9',
];
foreach ($trackingSources as $sourceFile => $sha256) {
    woo_ok(($settingsInventory['source_files'][$sourceFile] ?? null) === $sha256
        && ($operationalSharedSources[$sourceFile] ?? null) === $sha256,
        "$sourceFile binds the exact conditional WC_Settings_Tracking topology in both source inventories");
}
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
$operationalAuthorities = [
    'includes/admin/class-wc-admin-notices.php' => 'bf4a07c145f7005804c23357f6c2e82b06fa5f284e21cffbf79d87c120ff507e',
    'includes/admin/class-wc-admin-permalink-settings.php' => 'db546611151444e54677ea4f3a4317181a49c4f5aa9095740fa88e4bbdf32969',
    'includes/admin/class-wc-admin-setup-wizard.php' => '920cd8767034ce6e2379ce6641524334bdbd1b47980e40227297d0943fcda301',
    'includes/wc-update-functions.php' => 'ef71483132ffca291e1672818f013a5b005673513b37b67a8934a6c28f438f94',
    'includes/wc-user-functions.php' => 'e504b14bd34a5fd156cce2e91a9933a126db549213ddb821994a0ce5efab4330',
    'src/Internal/Admin/Events.php' => 'e8e5acf756a2d35444246875723df09022bdc750048185b2309c4a9650cc562f',
    'src/Internal/RestApi/Routes/V4/Settings/Products/Controller.php' => '8cc95559c063f06d5c34fd08eb0be0a96cc8c6e24331a8f63a96616b751c4135',
    'src/Internal/RestApi/Routes/V4/Settings/Products/Schema/ProductSettingsSchema.php' => '2c1e9afc9acd4acc1590352acfafb0a9737ffe44f73fb5102ac82f2cbd2b5bdb',
];
foreach ($operationalAuthorities as $sourceFile => $sha256) {
    woo_ok(($settingsInventory['source_files'][$sourceFile] ?? null) === $sha256,
        "$sourceFile is exact operational/source-union authority shared by both admitted artifacts");
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
woo_ok(($externalProductInventory['format'] ?? null) === 'wprism-woocommerce-external-product-inventory/v1',
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
woo_ok(($termSurfaceInventory['format'] ?? null) === 'wprism-woocommerce-term-surface-inventory/v1'
    && count((array) ($termSurfaceInventory['source_files'] ?? [])) === 15,
    'the brand/category/visual inventory binds all fifteen exact native paths');
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
    if ($classification === 'authored_post_reference') {
        woo_ok(($rule['class'] ?? null) === 'authored' && ($rule['ref'] ?? null) === 'post',
            "$name resolves to its source-audited portable post identity");
        continue;
    }
    if ($classification === 'closed_mixed_record') {
        woo_ok(
            ($rule['class'] ?? null) === 'env'
                && ($rule['required'] ?? null) === false
                && ($rule['closed_sub_keys'] ?? null) === true
                && ($rule['absent_autoload'] ?? null) === 'yes'
                && is_array($rule['sub_keys'] ?? null),
            "$name resolves through its source-audited native closed sibling registry"
        );
        continue;
    }
    woo_ok($rule === null, "$name remains unclassified for its explicit $classification boundary");
    if ($classification !== 'ui') {
        woo_ok($policy->option_namespace((string) $name) !== null,
            "$name remains discovery-owned so populated unsupported state fails loudly");
    }
}
$operationalIds = (array) ($settingsInventory['operational_literal_ids'] ?? []);
$operationalPrefixProbes = [
    'woocommerce_admin_notice_' => 'woocommerce_admin_notice_extension-update',
    'woocommerce_onboarding_plugins_install_and_activate_async_' => 'woocommerce_onboarding_plugins_install_and_activate_async_jetpack',
    'woocommerce_setup_background_installing_' => 'woocommerce_setup_background_installing_woocommerce-services',
];
$extensionPrefixProbes = [
    'woocommerce_table_rate_default_priority_' => 'woocommerce_table_rate_default_priority_17',
    'woocommerce_table_rate_priorities_' => 'woocommerce_table_rate_priorities_17',
];
foreach ($operationalIds as $name => $classification) {
    if (in_array($classification, ['authored', 'runtime', 'env'], true)) {
        woo_ok(($policy->option_rule((string) $name)['class'] ?? null) === $classification,
            "$name resolves to its exact $classification operational classification");
        continue;
    }
    if ($classification === 'runtime_prefix') {
        $probe = $operationalPrefixProbes[$name] ?? null;
        woo_ok(is_string($probe)
            && ($policy->option_rule($probe)['class'] ?? null) === 'runtime'
            && $policy->option_rule((string) $name) === null,
            "$name is a bounded runtime family rather than an unbounded prefix match");
        continue;
    }
    if ($classification === 'extension_prefix') {
        $probe = $extensionPrefixProbes[$name] ?? null;
        woo_ok(is_string($probe)
            && $policy->option_rule($probe) === null
            && $policy->option_namespace($probe) !== null,
            "$name remains a discovery-visible extension-owned settings family");
        continue;
    }
    woo_ok(in_array($classification, ['dynamic_fragment', 'extension_boundary'], true)
        && $policy->option_rule((string) $name) === null
        && $policy->option_namespace((string) $name) !== null,
        "$name remains a discovery-visible $classification instead of receiving core portability authority");
}
woo_ok(($settingsInventory['dynamic_families'] ?? null) === [
    'wc_stock_notifications_cycle_state_<product-id>' => 'runtime',
    'woocommerce_<core-email-id>_settings' => 'closed_mixed_record',
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
    $rule = $policy->option_rule((string) $optionName);
    woo_ok(($rule['class'] ?? null) === 'env'
        && ($rule['required'] ?? null) === false
        && ($rule['closed_sub_keys'] ?? null) === true
        && ($rule['absent_autoload'] ?? null) === 'yes',
        "$optionName uses native closed-subkey materialization while retaining target-owned siblings");
    woo_ok(is_string($record['boundary'] ?? null)
        && str_ends_with((string) $record['boundary'], 'closed_sub_keys'),
        "$optionName has a complete adapter-native closed sibling contract");
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
    $rule = $policy->option_rule((string) $optionName);
    woo_ok(($rule['class'] ?? null) === 'env'
        && ($rule['required'] ?? null) === false
        && ($rule['closed_sub_keys'] ?? null) === true
        && ($rule['absent_autoload'] ?? null) === 'yes',
        "$optionName resolves through the reviewed mixed-subkey seam");
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

// issue #3315: this is a manifest declaration, not an engine convention. The
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

foreach ($optionNames as $name) {
    woo_ok($policy->option_namespace($name) !== null, "$name is discovery-owned");
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
    ($settingsInventory['computed_ids']['woocommerce_review_order_page_id'] ?? null) === 'authored_post_reference'
        && ($policy->option_rule('woocommerce_review_order_page_id') ?? null)
            === ['class' => 'authored', 'ref' => 'post', 'autoload' => 'preserve'],
    'the optional Review Order host page is portable authored identity rather than a source-local numeric id'
);
woo_ok(
    ($settingsInventory['computed_ids']['woocommerce_review_order_flush_rewrite_pending'] ?? null) === 'runtime'
        && ($policy->option_rule('woocommerce_review_order_flush_rewrite_pending')['class'] ?? null) === 'runtime',
    'the one-request review-route flush marker remains explicit target runtime'
);
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
woo_ok(($policy->owned_option_rule('woocommerce_cod_settings')['closed_sub_keys'] ?? null) === true
    && ($policy->owned_option_rule('woocommerce_cod_settings')['sub_keys']['enable_for_methods']['json_refs'] ?? null)
        === [['path' => '$.*.instance_id', 'kind' => 'wc_zone_method']],
    'core COD settings bind method-instance references through the reviewed typed closed schema');
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

// issue #3509: three rows a fresh WooCommerce 11.0.1 install carries that this
// inventory did not name. Two of them fall OUTSIDE this manifest's own
// option_namespaces (`^(?:action_scheduler|wc|woocommerce)_`), which is why
// they are asked through option_rule() rather than owned_option_rule() —
// they reached `wprism pending` through the journal, not through namespace
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
woo_ok(
    ($declaredTables['woocommerce_tax_rates']['columns']['tax_rate_country']['allow_pii'] ?? null) === true
        && ($declaredTables['woocommerce_tax_rates']['columns']['tax_rate_state']['allow_pii'] ?? null) === true
        && !isset($declaredTables['woocommerce_tax_rates']['columns']['tax_rate_name']['allow_pii']),
    'the exact country/state tax-jurisdiction columns carry the reviewed PII clearance without widening the table'
);

$actions = $policy->actions();
$actionSources = array_map(
    static fn(array $row): string => \WPrism\Policy::action_source($row, (int) $row['index']),
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
    'native:rewrite.flush',
    'provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes',
    'provider:woocommerce-lifecycle-migrations/settle_lifecycle_migrations',
], 'manifest owns the bounded attribute-transient, shipping/tax cache, fresh-process hierarchy/brand-route, '
    . 'read-only fulfillment prerequisite, scheduler projections, per-product lookup repairs, product-permalink route, review-page route, and lifecycle migration settlement');
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
// issue #3342: the third entry is a MIGRATED dispatch, not a new repair. It is
// bounded by the same two post-type triggers the retired regen_dependency
// declarations covered, and those declarations are gone — a manifest carrying
// both would be two dispatchers over one post type, which negotiation refuses.
woo_ok(count($productActions) === 1
    && ($productAction['triggers'] ?? null) === ['post:product', 'post:product_variation'],
    'the product lookup repair stays bounded to the two product post types it always covered');
woo_ok($policy->regen_batch_post_types() === [] && $policy->regen_dependency('product') === null,
    'and the batch regenerator channel it replaced claims no Woo post type any more');
// issue #3341: the legacy whole-catalog projection class is deleted outright,
// not quarantined. Engine core must carry no WooCommerce-named production
// source and the bootstrap must not load one; Woo semantics live in
// WooCommerce package manifest and the provider/native-action contract.
// These assertions fail against the pre-issue #3341 tree (class present,
// require_once in agent/wprism.php), which is this issue's regression proof.
// Recursive since the module move (ROUND 3 TRAIN 1): agent/src is a tree of
// module directories, so a flat scandir() would enumerate module names and let
// both checks below pass for the wrong reason. The deleted class is now looked
// for ANYWHERE under agent/src, which is the claim issue #3341 actually makes.
$engineSrcEntries = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/agent/src', FilesystemIterator::SKIP_DOTS)) as $engineSrcEntry) {
    if ($engineSrcEntry instanceof SplFileInfo && $engineSrcEntry->isFile() && $engineSrcEntry->getExtension() === 'php') {
        $engineSrcEntries[] = $engineSrcEntry->getFilename();
    }
}
woo_ok(!in_array('WooCommerceContract.php', $engineSrcEntries, true), 'the whole-catalog Woo projection class is deleted from engine core (issue #3341)');
woo_ok(count($engineSrcEntries) > 2, 'agent/src enumerates non-empty for the WooCommerce-named source scan');
$wooNamedEngineSources = array_values(array_filter(
    $engineSrcEntries,
    static fn(string $name): bool => stripos($name, 'woocommerce') !== false || stripos($name, 'woo') === 0
));
woo_ok($wooNamedEngineSources === [], 'no WooCommerce-named production class remains under agent/src (issue #3341)');
woo_ok(!str_contains((string) file_get_contents($root . '/agent/wprism.php'), 'WooCommerce'), 'agent bootstrap loads no WooCommerce-named engine source (issue #3341)');
$unsupportedDeletes = [
    'table:woocommerce_attribute_taxonomies',
    'table:woocommerce_shipping_zone_locations',
    'table:woocommerce_shipping_zone_methods',
    'table:woocommerce_shipping_zones',
    'table:woocommerce_tax_rate_locations',
    'table:woocommerce_tax_rates',
];
$supportedDeletes = ['post:product', 'post:product_variation'];
$reviewedWooExecutableIdentities = ['plugin:woocommerce/woocommerce.php' => [
    [
        'format' => 'wprism-executable-tree/v1',
        'root' => 'plugins/woocommerce',
        'sha256' => 'd6f965acbb8f1e6d036c2dc6ce5300f6fb832c4a88ba3cf062c5c5ac85c47507',
    ],
    [
        'format' => 'wprism-executable-tree/v1',
        'root' => 'plugins/woocommerce',
        'sha256' => 'feffc5f15e569bf5eb6baa04b9b7b6e8038b47f29e1a63e80f20c24bef0d1696',
    ],
]];
woo_ok(array_keys((array) ($manifest['deletions'] ?? [])) === $supportedDeletes,
    'shipped Woo manifest owns only product and variation deletion');
foreach ($supportedDeletes as $selector) {
    $capability = $policy->deletion_capability($selector);
    woo_ok(
        ($capability['executable_owner_boundary'] ?? null) === 'all_active_owners'
            && ($capability['declaring_executable_owners'] ?? null) === ['plugin:woocommerce/woocommerce.php']
            && ($capability['declaring_executable_owner_identities'] ?? null)
                === $reviewedWooExecutableIdentities
            && count((array) ($capability['guards'] ?? [])) === 13,
        "$selector deletion binds both exact official Woo trees inside its all-owner and thirteen-guard boundary"
    );
    $metaGuards = [];
    foreach ((array) ($capability['guards'] ?? []) as $guard) {
        if (isset($guard['meta_key'])) {
            $metaGuards[(string) $guard['meta_key']] = $guard;
        }
    }
    woo_ok(array_keys($metaGuards) === [
        '_children', '_crosssell_ids', '_upsell_ids', 'exclude_product_ids', 'product_ids', '_menu_item_object_id',
    ], "$selector deletion guards every declared Woo product/coupon post-reference family");
    woo_ok(
        ($metaGuards['_crosssell_ids']['cast'] ?? null) === 'string'
            && ($metaGuards['_menu_item_object_id']['cast'] ?? null) === 'string'
            && ($metaGuards['_menu_item_object_id']['ref'] ?? null) === 'post'
            && ($metaGuards['_menu_item_object_id']['forceable'] ?? null) === false
            && ($metaGuards['_upsell_ids']['cast'] ?? null) === 'string'
            && ($metaGuards['exclude_product_ids']['cast'] ?? null) === 'csv'
            && ($metaGuards['product_ids']['cast'] ?? null) === 'csv',
        "$selector deletion guard casts match the manifest's exact post-meta reference grammar and menu refs are non-forceable"
    );
    $runtimeGuards = [];
    foreach ((array) ($capability['guards'] ?? []) as $guard) {
        if (in_array($guard['table'] ?? null, ['wc_reserved_stock', 'wc_stock_notifications'], true)) {
            $runtimeGuards[(string) $guard['table']] = $guard;
        }
    }
    woo_ok(array_keys($runtimeGuards) === ['wc_reserved_stock', 'wc_stock_notifications']
        && ($runtimeGuards['wc_reserved_stock']['column'] ?? null) === 'product_id'
        && ($runtimeGuards['wc_reserved_stock']['forceable'] ?? null) === false
        && ($runtimeGuards['wc_stock_notifications']['column'] ?? null) === 'product_id'
        && ($runtimeGuards['wc_stock_notifications']['forceable'] ?? null) === false,
        "$selector deletion blocks Woo lifecycle-owned reservation and stock-notification rows even under force");
}
foreach ($unsupportedDeletes as $selector) {
    woo_ok($policy->deletion_capability($selector) === null, "$selector deletion is fail-closed");
}
$wooDisposition = $wooDispositionDocument;
woo_ok(in_array('delete', $wooDisposition['capabilities']['operations'] ?? [], true), 'external capability registry advertises reviewed Woo deletion');
woo_ok(($wooDisposition['capabilities']['deletion_semantics']['supported'] ?? null) === $supportedDeletes,
    'external capability registry declares product and variation deletion supported');
woo_ok(str_contains((string) ($wooDisposition['reason'] ?? ''),
    'exact PII clearance for coupon customer-email restrictions, tax-rate country/state business-jurisdiction fields'),
    'the human-reviewed disposition explains coupon-email and tax-jurisdiction PII exceptions');
woo_ok(
    ($policy->post_meta_rule('customer_email')['allow_pii'] ?? null) === true
        && !isset($policy->post_meta_rule('billing_email')['allow_pii']),
    'coupon customer_email has exact PII clearance without widening sibling email metadata'
);
woo_ok(
    ($policy->owned_option_rule('pickup_location_pickup_locations')['allow_pii'] ?? null) === true
        && !isset($policy->owned_option_rule('woocommerce_pickup_location_settings')['allow_pii'])
        && str_contains((string) ($wooDisposition['reason'] ?? ''), 'merchant pickup-location address record'),
    'the exact pickup-location record has a reviewed PII exception without widening sibling settings'
);
$reviewedPiiOptions = array_keys(array_filter(
    (array) ($manifest['options'] ?? []),
    static fn(array $rule): bool => ($rule['allow_pii'] ?? false) === true
));
sort($reviewedPiiOptions, SORT_STRING);
woo_ok($reviewedPiiOptions === [
    'pickup_location_pickup_locations',
    'woocommerce_default_country',
    'woocommerce_email_from_name',
    'woocommerce_email_reply_to_name',
    'woocommerce_pos_store_address',
    'woocommerce_pos_store_email',
    'woocommerce_pos_store_phone',
    'woocommerce_store_address',
    'woocommerce_store_address_2',
    'woocommerce_store_city',
    'woocommerce_store_postcode',
], 'the reviewed Woo option-level PII clearance is an exact finite merchant-configuration set');
woo_ok(str_contains((string) ($wooDisposition['reason'] ?? ''),
    'store address/city/postcode, default country, email sender/reply names, and POS store address/email/phone'),
    'the human-reviewed disposition explains every merchant-configuration PII exception');
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
$woocommerceMatrixHarness = (string) file_get_contents(dirname(__DIR__) . '/certify/version-matrix.sh');
$matrixHarness .= "\n" . $woocommerceMatrixHarness;
$woocommerceScopedDeletionHarnessPath = dirname(__DIR__) . '/live/regress_woocommerce_scoped_deletion.sh';
$woocommerceScopedDeletionHarness = (string) file_get_contents($woocommerceScopedDeletionHarnessPath);
$sshAdoptHarness = (string) file_get_contents($root . '/sandbox/tests/live/regress_ssh_adopt.sh');
woo_ok(is_file($woocommerceScopedDeletionHarnessPath)
    && str_contains($sshAdoptHarness, 'WPRISM_SSH_ADOPT_EXTENSION')
    && str_contains($sshAdoptHarness, 'wprism_ssh_adopt_extension')
    && str_contains($sshAdoptHarness, 'tests/live/*.sh'),
    'WooCommerce deletion live proof is selected only through the candidate-bound standalone SSH extension hook');
$piiObserverPath = dirname(__DIR__, 2) . '/fixtures/woocommerce-pii-fingerprints.php';
require_once $piiObserverPath;
$manifestPiiGrants = [];
foreach ((array) ($manifest['options'] ?? []) as $name => $rule) {
    if (($rule['allow_pii'] ?? false) === true) {
        $manifestPiiGrants[] = 'option:' . $name;
    }
}
foreach ((array) ($manifest['post_meta'] ?? []) as $name => $rule) {
    if (($rule['allow_pii'] ?? false) === true) {
        $manifestPiiGrants[] = 'post_meta:' . $name;
    }
}
foreach ((array) ($manifest['tables'] ?? []) as $table => $declaration) {
    foreach ((array) ($declaration['columns'] ?? []) as $column => $rule) {
        if (($rule['allow_pii'] ?? false) === true) {
            $manifestPiiGrants[] = "table:$table.$column";
        }
    }
}
sort($manifestPiiGrants, SORT_STRING);
$observerPiiGrants = WPRISM_WOO_ALLOW_PII_GRANTS;
sort($observerPiiGrants, SORT_STRING);
woo_ok($manifestPiiGrants === $observerPiiGrants && count($observerPiiGrants) === 14,
    'the hash-only WPRA-019 observer enumerates every and only the fourteen finite manifest grants');
$presentPiiFingerprint = wprism_woo_option_record_fingerprint(
    ['state' => 'present', 'value' => ['agency' => 'source']],
    'option:test'
);
$absentPiiFingerprint = wprism_woo_option_record_fingerprint(['state' => 'absent'], 'option:test');
woo_ok($presentPiiFingerprint === wprism_woo_value_fingerprint(['agency' => 'source'])
    && $absentPiiFingerprint === hash('sha256', "wprism-woo-option-record\0absent")
    && $absentPiiFingerprint !== wprism_woo_value_fingerprint(false),
    'the hash-only observer distinguishes canonical absence from a present value and native get_option false');
$malformedPiiRecordRefused = false;
try {
    wprism_woo_option_record_fingerprint(['state' => 'absent', 'value' => false], 'option:test');
} catch (RuntimeException $exception) {
    $malformedPiiRecordRefused = $exception->getMessage()
        === 'option:test lacks one valid captured option record';
}
woo_ok($malformedPiiRecordRefused,
    'the hash-only observer refuses an absent option record carrying a value');
$redactionRoster = [];
if (preg_match(
    "/woocommerce_pii_redaction_witnesses\\(\\) \\{.*?cat <<'EOF'\\n(.*?)\\nEOF\\n\\}/s",
    $woocommerceMatrixHarness,
    $redactionMatch
) === 1) {
    foreach (explode("\n", $redactionMatch[1]) as $line) {
        $parts = explode("\t", $line, 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $redactionRoster[$parts[0]] = $parts[1];
        }
    }
}
$redactionGrants = array_keys($redactionRoster);
sort($redactionGrants, SORT_STRING);
woo_ok($redactionGrants === $observerPiiGrants
    && count(array_unique(array_values($redactionRoster), SORT_STRING)) === 14
    && ($redactionRoster['table:woocommerce_tax_rates.tax_rate_country'] ?? null) === 'QZ'
    && ($redactionRoster['table:woocommerce_tax_rates.tax_rate_state'] ?? null) === 'WPRA19TAXSTATE14',
    'capture/apply redaction owns one unique grep-safe source-value witness per WPRA-019 grant, including both tax fields');
foreach ($redactionRoster as $grant => $witness) {
    woo_ok(substr_count($woocommerceMatrixHarness, $witness) >= 2,
        "$grant redaction witness is both declared and written through the native source profile");
}
foreach ([
    'woocommerce_write_pii_profile wp1 source',
    'source_native_before=$(woocommerce_native_pii_fingerprints wp1)',
    'hash("sha256", "wprism-woo-option-record\0absent")',
    'source_native=$(woocommerce_native_pii_fingerprints wp1)',
    'source_captured=$(php "$package_tests/../fixtures/woocommerce-pii-fingerprints.php" "$source_repo/state")',
    'woocommerce_write_pii_profile wp2 target',
    'target_before=$(woocommerce_native_pii_fingerprints wp2)',
    'target_divergent=$(woocommerce_native_pii_fingerprints wp2)',
    'target_applied=$(woocommerce_native_pii_fingerprints wp2)',
    'wp2 plugin deactivate woocommerce',
    'wp2 plugin delete woocommerce',
    'wp2 plugin install "$target_artifact" --activate',
    'target_reinstalled=$(woocommerce_native_pii_fingerprints wp2)',
    'target_recaptured=$(php "$package_tests/../fixtures/woocommerce-pii-fingerprints.php"',
    'pii_diff=$(diff -rq "$source_repo/state" "$target_repo/.tmp-woo-pii-final"',
    'woocommerce_assert_pii_log_redacted "WooCommerce $version WPRA-019 capture output"',
    'woocommerce_assert_pii_log_redacted "WooCommerce $version WPRA-019 apply output"',
    '"${GIT1[@]}" revert --no-edit "$pii_commit"',
    'woocommerce_write_pii_profile wp1 baseline',
    'source_restored=$(woocommerce_native_pii_fingerprints wp1)',
    'woocommerce_write_pii_profile wp2 baseline',
    'target_restored=$(woocommerce_native_pii_fingerprints wp2)',
    'WPRA-019 target restore did not recover the exact preimage',
    'check_woocommerce_allow_pii_roundtrip "$WOO_VERSION" "$ARTIFACT_2"',
] as $piiWitness) {
    woo_ok(str_contains($woocommerceMatrixHarness, $piiWitness),
        "exact WooCommerce matrix pins WPRA-019 evidence step $piiWitness");
}
$boundaryLoop = strpos($woocommerceMatrixHarness, 'for WOO_VERSION in 11.0.0 11.0.1; do');
$piiInvocation = strpos(
    $woocommerceMatrixHarness,
    'check_woocommerce_allow_pii_roundtrip "$WOO_VERSION" "$ARTIFACT_2"'
);
woo_ok($boundaryLoop !== false
    && $piiInvocation !== false
    && $boundaryLoop < $piiInvocation
    && substr_count($woocommerceMatrixHarness,
        'check_woocommerce_allow_pii_roundtrip "$WOO_VERSION" "$ARTIFACT_2"') === 1,
    'all fourteen WPRA-019 grants run once inside each exact 11.0.0/11.0.1 boundary case');
$wooEntry = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/conformance/entry.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$wooConformanceTaxonomies = $wooEntry['entry']['taxonomies'] ?? null;
$wooSeedHarness = (string) file_get_contents(dirname(__DIR__) . '/conformance/seed.sh');
$wooPostdeployHarness = (string) file_get_contents(dirname(__DIR__) . '/conformance/postdeploy.sh');
$wooPostapplyHarness = (string) file_get_contents(dirname(__DIR__) . '/conformance/postapply.sh');
$wooCheckHarness = (string) file_get_contents(dirname(__DIR__) . '/conformance/check.sh');
$wooInterpreterSource = (string) file_get_contents($root . '/adapter-packages/woocommerce/package/runtime/interpreters/woocommerce.php');

// issue #3525: deploy compiles mixed options while installed WooCommerce code can
// still be inactive, so only WordPress's plugin root exists at that boundary.
// Once Woo is active, both plugin constants must agree with that same fixed
// slug before the byte-identical settings abstract may be included.
$settingsApiPath = 'includes/abstracts/abstract-wc-settings-api.php';
$settingsApiHash = '1c7615bcd26fba9f83961db045edf6bd42acc6ee691383e8a8dd6f6a1e00434c';
$formattingFunctionsPath = 'includes/wc-formatting-functions.php';
$formattingFunctionsHash = 'c3576416420bbfb6893ad5164ccf8c439b7e731c337c04b32e058ac6a0809d41';
$settingsApiLoaderHashes = [
    '11.0.0' => '5982ef2ab60231218cc71a2ba9bd387496d32c1a5eeb5468116d51137bbd7ef4',
    '11.0.1' => '2f3a95ae78217be16fa1f272c1fad4d3faecfd02939041a861d65826bb3f4cb7',
];
woo_ok(
    ($settingsInventory['source_files'][$settingsApiPath] ?? null) === $settingsApiHash
        && ($settingsInventory['source_files'][$formattingFunctionsPath] ?? null) === $formattingFunctionsHash
        && ($settingsInventory['version_specific_source_files']['includes/class-woocommerce.php'] ?? null)
            === $settingsApiLoaderHashes,
    'the settings abstract, permalink sanitizer, and each exact WooCommerce loader remain pinned before partial-runtime loading is admitted'
);

$settingsApiLoaderStart = strpos($wooInterpreterSource, 'private function load_native_settings_api(): void');
$settingsApiLoaderEnd = $settingsApiLoaderStart === false
    ? false
    : strpos($wooInterpreterSource, 'private static function native_validation_files(): array', $settingsApiLoaderStart);
$settingsApiLoader = $settingsApiLoaderStart !== false && $settingsApiLoaderEnd !== false
    ? substr($wooInterpreterSource, $settingsApiLoaderStart, $settingsApiLoaderEnd - $settingsApiLoaderStart)
    : '';
$nativeSettingsApiStart = strpos($wooInterpreterSource, 'private function native_settings_api(): object');
$nativeSettingsApiEnd = $nativeSettingsApiStart === false
    ? false
    : strpos($wooInterpreterSource, 'private function load_native_settings_api(): void', $nativeSettingsApiStart);
$nativeSettingsApi = $nativeSettingsApiStart !== false && $nativeSettingsApiEnd !== false
    ? substr($wooInterpreterSource, $nativeSettingsApiStart, $nativeSettingsApiEnd - $nativeSettingsApiStart)
    : '';
$nativeValidationFilesStart = strpos($wooInterpreterSource, 'private static function native_validation_files(): array');
$nativeValidationFilesEnd = $nativeValidationFilesStart === false
    ? false
    : strpos($wooInterpreterSource, 'private function assert_native_permalink_record(', $nativeValidationFilesStart);
$nativeValidationFiles = $nativeValidationFilesStart !== false && $nativeValidationFilesEnd !== false
    ? substr($wooInterpreterSource, $nativeValidationFilesStart, $nativeValidationFilesEnd - $nativeValidationFilesStart)
    : '';
$nativePermalinkRecordStart = strpos($wooInterpreterSource, 'private function assert_native_permalink_record(');
$nativePermalinkRecordEnd = $nativePermalinkRecordStart === false
    ? false
    : strpos($wooInterpreterSource, 'private function assert_native_permalink_child(', $nativePermalinkRecordStart);
$nativePermalinkRecord = $nativePermalinkRecordStart !== false && $nativePermalinkRecordEnd !== false
    ? substr($wooInterpreterSource, $nativePermalinkRecordStart, $nativePermalinkRecordEnd - $nativePermalinkRecordStart)
    : '';
$nativePermalinkChildStart = strpos($wooInterpreterSource, 'private function assert_native_permalink_child(');
$nativePermalinkChildEnd = $nativePermalinkChildStart === false
    ? false
    : strpos($wooInterpreterSource, 'public static function run_native_permalink_child(): void', $nativePermalinkChildStart);
$nativePermalinkChild = $nativePermalinkChildStart !== false && $nativePermalinkChildEnd !== false
    ? substr($wooInterpreterSource, $nativePermalinkChildStart, $nativePermalinkChildEnd - $nativePermalinkChildStart)
    : '';
$nativePermalinkRunnerStart = strpos($wooInterpreterSource, 'public static function run_native_permalink_child(): void');
$nativePermalinkRunnerEnd = $nativePermalinkRunnerStart === false
    ? false
    : strpos($wooInterpreterSource, 'private function assert_native_mixed_field(', $nativePermalinkRunnerStart);
$nativePermalinkRunner = $nativePermalinkRunnerStart !== false && $nativePermalinkRunnerEnd !== false
    ? substr($wooInterpreterSource, $nativePermalinkRunnerStart, $nativePermalinkRunnerEnd - $nativePermalinkRunnerStart)
    : '';
$settingsApiClassCheck = strpos($nativeSettingsApi, "class_exists('WC_Settings_API', false)");
$settingsApiLoad = strpos($nativeSettingsApi, '$this->load_native_settings_api();');
$settingsApiRecheck = $settingsApiLoad === false
    ? false
    : strpos($nativeSettingsApi, "class_exists('WC_Settings_API', false)", $settingsApiLoad + 1);
$settingsApiReflection = strpos($nativeSettingsApi, "new \\ReflectionClass('WC_Settings_API')");
$settingsApiHashCheck = strpos($nativeValidationFiles, "hash_equals(self::WOO_SETTINGS_API_SHA256, \$settingsHash)");
$formattingHashCheck = strpos($nativeValidationFiles, "hash_equals(self::WOO_FORMATTING_FUNCTIONS_SHA256, \$formattingHash)");
$settingsApiRequire = strpos($settingsApiLoader, "require_once \$files['settings'];");
$formattingRequire = strpos($nativePermalinkRunner, "require_once \$files['formatting'];");
woo_ok(
    str_contains($wooInterpreterSource, "private const WOO_SETTINGS_API_SHA256 =\n        '$settingsApiHash';")
        && str_contains($wooInterpreterSource, "private const WOO_FORMATTING_FUNCTIONS_SHA256 =\n        '$formattingFunctionsHash';")
        && $settingsApiClassCheck !== false
        && $settingsApiLoad !== false
        && $settingsApiRecheck !== false
        && $settingsApiReflection !== false
        && $settingsApiClassCheck < $settingsApiLoad
        && $settingsApiLoad < $settingsApiRecheck
        && $settingsApiRecheck < $settingsApiReflection
        && $settingsApiLoader !== ''
        && $nativeValidationFiles !== ''
        && str_contains($nativeValidationFiles, "defined('ABSPATH')")
        && str_contains($nativeValidationFiles, "defined('WP_PLUGIN_DIR')")
        && str_contains($nativeValidationFiles, "constant('WC_ABSPATH')")
        && str_contains($nativeValidationFiles, "constant('WC_PLUGIN_FILE')")
        && str_contains($nativeValidationFiles, "realpath(\$pluginDirectoryReal . DIRECTORY_SEPARATOR . 'woocommerce')")
        && str_contains($nativeValidationFiles, "realpath(\$installedRootReal . DIRECTORY_SEPARATOR . 'woocommerce.php')")
        && str_contains($nativeValidationFiles, '$hasConfiguredRoot !== $hasPluginFile')
        && str_contains($nativeValidationFiles, '$configuredRootReal !== $installedRootReal')
        && str_contains($nativeValidationFiles, '$pluginFileReal !== $installedFileReal')
        && str_contains($nativeValidationFiles, "'abstract-wc-settings-api.php'")
        && str_contains($nativeValidationFiles, "'wc-formatting-functions.php'")
        && str_contains($nativeValidationFiles, '$settingsFileReal !== $settingsFile')
        && str_contains($nativeValidationFiles, '$formattingFileReal !== $formattingFile')
        && $settingsApiHashCheck !== false
        && $formattingHashCheck !== false
        && $settingsApiRequire !== false
        && $formattingRequire !== false
        && !str_contains($settingsApiLoader, "\$files['formatting']")
        && !str_contains($nativeSettingsApi, "function_exists('wc_sanitize_permalink')")
        && str_contains($nativePermalinkRecord, "defined('WPINC')")
        && str_contains($nativePermalinkRecord, "new \\ReflectionFunction('wc_sanitize_permalink')")
        && str_contains($nativePermalinkRecord, '$reflection->getFileName()')
        && str_contains($nativePermalinkRecord, 'realpath($declaringFile)')
        && str_contains($nativePermalinkRecord, "hash_equals(\$files['formatting'], \$declaringFileReal)")
        && str_contains($nativePermalinkRecord, 'native permalink authority is substituted')
        && str_contains($nativePermalinkChild, 'WpCliChildProcess::capture_with_input(')
        && str_contains($nativePermalinkChild, "'eval ' . escapeshellarg(\$code)")
        && str_contains($nativePermalinkChild, "'wprism-woocommerce-native-permalink-input/v1'")
        && str_contains($nativePermalinkRunner, 'stream_get_contents(STDIN, 16385)')
        && str_contains($nativePermalinkRunner, "'wprism-woocommerce-native-permalink-receipt/v1'")
        && str_contains($nativePermalinkRunner, "hash('sha256', \$canonical)"),
    'inactive validation hash-binds both native files, rejects substituted loaded sanitizers, keeps formatting bytes out of the activation process, and sends bounded permalink state to a fresh child over stdin'
);
woo_ok(
    substr_count($nativeValidationFiles, "'wprism: WooCommerce mixed option validation requires WC_Settings_API'") === 1
        && substr_count($nativeValidationFiles, 'throw new \\RuntimeException($message') >= 4,
    'missing, mismatched, and substituted settings-file authorities retain the established WC_Settings_API refusal'
);

// Receipt identities are a three-way contract: the manifest is the shipped
// declaration, the provider runtime exposes the negotiated identity, and this
// conformance check is the observed receipt expectation. Keep the exact
// versions here so a one-sided bump cannot hide behind a green offline suite.
$expectedWooProviderContracts = [
    'woocommerce-product-lookups' => [
        'plugin' => 'woocommerce/woocommerce.php',
        'version' => '3.0.0',
        'capability' => 'rebuild_product_lookups',
        'class' => \WPrism\Providers\WoocommerceProductLookups::class,
    ],
    'woocommerce-hierarchy-lookups' => [
        'plugin' => 'woocommerce/woocommerce.php',
        'version' => '2.0.0',
        'capability' => 'rebuild_hierarchy_lookups',
        'class' => \WPrism\Providers\WoocommerceHierarchyLookups::class,
    ],
];
$manifestWooProviderRows = [];
$manifestWooProviderDeclarations = [];
foreach ((array) ($manifest['providers'] ?? []) as $provider) {
    if (is_array($provider) && isset($provider['id'], $provider['version'])) {
        $manifestWooProviderDeclarations[(string) $provider['id']] = $provider;
        $manifestWooProviderRows[(string) $provider['id']] = [
            'plugin' => (string) ($provider['plugin'] ?? ''),
            'version' => (string) $provider['version'],
        ];
    }
}
foreach ($expectedWooProviderContracts as $providerId => $contract) {
    $manifestRow = $manifestWooProviderRows[$providerId] ?? [];
    woo_ok(
        $manifestRow === [
            'plugin' => $contract['plugin'],
            'version' => $contract['version'],
        ],
        "$providerId manifest row pins its exact plugin and {$contract['version']} receipt version"
    );
}
foreach ($expectedWooProviderContracts as $providerId => $contract) {
    require_once $root . "/adapter-packages/woocommerce/package/runtime/providers/$providerId.php";
    $providerClass = $contract['class'];
    $declaration = $manifestWooProviderDeclarations[$providerId] ?? null;
    woo_ok(is_array($declaration), "$providerId retains its complete manifest declaration");
    $runtimeArgument = isset($declaration['contracts']) ? $declaration : $policy;
    $identity = (new $providerClass($runtimeArgument))->identity();
    woo_ok(
        $identity === [
            'id' => $providerId,
            'plugin' => $contract['plugin'],
            'version' => $contract['version'],
        ],
        "$providerId identity() stays at the exact manifest contract"
    );
    $escapedVersion = str_replace('.', '\\.', $contract['version']);
    $receiptExpectation = "grep -Eq '{$providerId}@{$escapedVersion} {$contract['capability']} .*verified'";
    woo_ok(
        substr_count($wooCheckHarness, $receiptExpectation) === 1,
        "$providerId conformance receipt check expects exactly {$contract['version']}"
    );
}
$conformanceRunnerHarness = (string) file_get_contents($root . '/sandbox/conformance/run.sh');
$conformanceAssertsHarness = (string) file_get_contents($root . '/sandbox/conformance/asserts.sh');
$wooMultisiteHarness = (string) file_get_contents(
    dirname(__DIR__) . '/live/regress_woocommerce_multisite_refusal.sh'
);
woo_ok(($wooEntry['manifest'] ?? null) === 'woocommerce'
    && ($wooEntry['entry']['pin'] ?? null) === ['core', 'woocommerce']
    && ($wooEntry['entry']['plugins'] ?? null) === [['slug' => 'woocommerce', 'version' => '11.0.1']]
    && ($wooEntry['entry']['setup'] ?? null) === 'hpos'
    && is_array($wooConformanceTaxonomies),
    'the ordinary WooCommerce conformance entry binds the exact shipped artifact and HPOS target premise');
woo_ok(
    $wooConformanceTaxonomies === [
        'category',
        'post_tag',
        'product_brand',
        'product_cat',
        'product_shipping_class',
        'product_tag',
        'product_type',
        'product_visibility',
    ],
    'the capture policy explicitly scopes both merchant-authored brand terms and the nine-term visibility inventory'
);
woo_ok(
    ($manifest['taxonomies']['product_brand']['class'] ?? null) === 'authored'
        && ($manifest['taxonomies']['product_visibility']['class'] ?? null) === 'authored',
    'the shipped Woo manifest keeps brand and mixed authored/derived visibility taxonomies authored rather than excluding them'
);

// Exercise the production scope boundary with a real registry and term-table
// inventory. The old regression called CaptureSafetyGates with a hand-made
// gap array; that could stay green while ScopeDiscovery stopped seeing a
// native taxonomy or miscounted it. Here CaptureCandidateBuilder owns the
// complete path from registry/count discovery to the refusal.
$termRows = [];
$termTaxonomyRows = [];
$termMetaRows = [];
foreach (range(1, 13) as $id) {
    $taxonomy = $id <= 3
        ? 'product_brand'
        : ($id <= 12 ? 'product_visibility' : 'unscoped_fixture_taxonomy');
    $termRows[] = [
        'term_id' => $id,
        'name' => "Woo term $id",
        'slug' => "woo-term-$id",
        'term_group' => 0,
    ];
    $termTaxonomyRows[] = [
        'term_taxonomy_id' => $id,
        'term_id' => $id,
        'taxonomy' => $taxonomy,
        'description' => '',
        'parent' => 0,
    ];
    $termMetaRows[] = [
        'meta_id' => $id,
        'term_id' => $id,
        'meta_key' => '_wprism_uuid',
        'meta_value' => sprintf('11111111-1111-7111-8111-%012d', $id),
    ];
}
$scopeDb = \WPrismTest\FakeWpdb::install();
$scopeDb->seedTable('wp_posts', [])
    ->seedTable('wp_terms', $termRows)
    ->seedTable('wp_term_taxonomy', $termTaxonomyRows);
$wooScopeFailure = null;
try {
    (new CaptureCandidateBuilder('/siterepo', $policy))->build(false);
} catch (CommandRefusalException $failure) {
    $wooScopeFailure = $failure;
}
woo_ok(
    $wooScopeFailure instanceof CommandRefusalException
        && $wooScopeFailure->reasonCode === 'incomplete_policy_scope'
        && ($wooScopeFailure->diagnostics ?? [])[0]['surface'] === 'scope:taxonomy:product_brand'
        && ($wooScopeFailure->diagnostics ?? [])[0]['entity_count'] === 3
        && ($wooScopeFailure->diagnostics ?? [])[1]['surface'] === 'scope:taxonomy:product_visibility'
        && ($wooScopeFailure->diagnostics ?? [])[1]['entity_count'] === 9,
    'capture/pending scope discovery refuses the exact brand and nine-term visibility gaps with non-empty counts'
);

// The corrected conformance scope must not merely silence the gate: the same
// candidate builder must read wp_terms/wp_term_taxonomy, resolve each durable
// term identity, and emit every term entity. Derive the taxonomy roster from
// the already-loaded conformance entry so this fixture cannot drift to a
// hand-authored two-item policy.
$correctedTaxonomies = (array) ($wooEntry['entry']['taxonomies'] ?? []);
$coreManifest = json_decode(
    (string) file_get_contents($root . '/platform/adapter-library/core/manifest.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$capturePolicy = Policy::from_snapshot([
    'dispositions' => null,
    'format' => 'wprism-policy-snapshot/v6',
    'adapter_sources' => ['certificates' => [], 'format' => 'wprism-adapter-sources/v2', 'out_of_tree' => []],
    'manifests' => [],
    'site' => [
        'manifests' => [],
        'policy' => [
            'options' => [],
            'post_types' => [],
            'taxonomies' => $correctedTaxonomies,
            'post_meta' => [],
            'term_meta' => ['_wprism_uuid' => $coreManifest['term_meta']['_wprism_uuid']],
            'user_meta' => [],
        ],
        'spec_version' => WPRISM_SPEC_VERSION,
    ],
], \WPrism\AdapterLibrary::fromSourcePackage($root, 'woocommerce'));
$captureDb = \WPrismTest\FakeWpdb::install()->enableJoinedCaptureSql();
$captureDb->seedTable('wp_posts', [])
    ->seedTable('wp_postmeta', [])
    ->seedTable('wp_terms', $termRows)
    ->seedTable('wp_term_taxonomy', $termTaxonomyRows)
    ->seedTable('wp_termmeta', $termMetaRows)
    ->seedTable('wp_term_relationships', [])
    ->seedTable('wp_options', [])
    ->seedTable('wp_users', [])
    ->seedTable('wp_usermeta', [])
    ->seedTable('wp_wprism_map', [])
    ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
    ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id']);
$candidate = (new CaptureCandidateBuilder('/siterepo', $capturePolicy))->build(false);
$termEntities = array_values(array_filter(
    $candidate['entities'],
    static fn(array $entity): bool => ($entity['type'] ?? null) === 'term'
));
$capturedTaxonomies = [];
foreach ($termEntities as $entity) {
    $decoded = \WPrism\Canon::decode((string) ($entity['content'] ?? ''));
    $capturedTaxonomies[] = $decoded['taxonomy'] ?? null;
}
$capturedCounts = array_count_values($capturedTaxonomies);
ksort($capturedCounts, SORT_STRING);
woo_ok(count($termEntities) === 12, 'the corrected conformance scope captures all twelve real term entities through the candidate builder');
woo_ok($capturedCounts === ['product_brand' => 3, 'product_visibility' => 9],
    'the candidate output retains exact three-brand and nine-visibility taxonomy counts');
woo_ok(count(array_filter(
    $candidate['entities'],
    static fn(array $entity): bool => ($entity['type'] ?? null) === 'options'
)) === 1, 'the candidate completes its downstream options/capture assembly after term discovery');

// Exact 11.0.1 conformance found four legitimate numeric values: v3
// attribute-term REST menu_order 7/3 and the bounded Customizer thumbnail
// ratio 1/1. Lint's option scan now shares issue #3508's wholly-0/1 boolean
// suppression, so only the two genuine non-boolean collisions remain findings;
// drive the real scanner here so neither that suppression nor the reviewed
// lint_ok declarations can drift behind a static manifest assertion.
$lintState = sys_get_temp_dir() . '/wprism-woo-lint-' . bin2hex(random_bytes(6));
mkdir($lintState . '/options', 0777, true);
mkdir($lintState . '/terms/pa_conf-color', 0777, true);
$lintLibrary = $lintState . '/manifest-library';
mkdir($lintLibrary . '/interpreters', 0777, true);
// The real package interpreter was loaded above. This fixture needs a regular
// inventory member at the explicit archive path, not a second copy that would
// redeclare the already-loaded class (the retired symlink happened to collapse
// to the original require_once path, but strict AdapterLibrary rejects links).
file_put_contents($lintLibrary . '/interpreters/woocommerce.php', "<?php\n");
$lintFiles = [
    $lintState . '/options/core.json',
    $lintState . '/terms/pa_conf-color/11111111-1111-5111-8111-111111111111--red.json',
    $lintState . '/terms/pa_conf-color/22222222-2222-5222-8222-222222222222--blue.json',
    $lintLibrary . '/woocommerce.json',
    $lintLibrary . '/interpreters/woocommerce.php',
];
$removeLintTree = static function (string $path) use (&$removeLintTree): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeLintTree("$path/$entry");
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
register_shutdown_function(static function () use ($lintState, $removeLintTree): void {
    $removeLintTree($lintState);
});
file_put_contents($lintFiles[0], \WPrism\Canon::encode([
    'format' => 'wprism-options/v1',
    'records' => [
        'woocommerce_thumbnail_cropping_custom_height' => [
            'autoload' => 'auto',
            'state' => 'present',
            'value' => '1',
        ],
        'woocommerce_thumbnail_cropping_custom_width' => [
            'autoload' => 'auto',
            'state' => 'present',
            'value' => '1',
        ],
    ],
]));
foreach ([
    [$lintFiles[1], 'Red', 'red', '7'],
    [$lintFiles[2], 'Blue', 'blue', '3'],
] as [$file, $name, $slug, $order]) {
    file_put_contents($file, \WPrism\Canon::encode([
        'description' => '',
        'meta' => ['order' => $order],
        'name' => $name,
        'parent' => null,
        'relationships' => (object) [],
        'slug' => $slug,
        'taxonomy' => 'pa_conf-color',
        'uuid' => str_contains($file, '--red.json')
            ? '11111111-1111-5111-8111-111111111111'
            : '22222222-2222-5222-8222-222222222222',
    ]));
}
\WPrismTest\WpStore::reset()->seedOptions(['home' => 'https://woo-lint.test']);
\WPrismTest\FakeWpdb::install()->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'attachment', 'post_title' => 'Placeholder', 'post_status' => 'inherit'],
    ['ID' => 3, 'post_type' => 'page', 'post_title' => 'Cart', 'post_status' => 'publish'],
    ['ID' => 7, 'post_type' => 'page', 'post_title' => 'Catalog', 'post_status' => 'publish'],
]);
$lintPolicy = static function (array $wooManifest) use ($lintLibrary, $root): Policy {
    return \WPrismTest\FrozenPolicy::policy(
        [$wooManifest],
        \WPrismTest\FrozenPolicy::site([$wooManifest], WPRISM_SPEC_VERSION),
        $lintLibrary,
        \WPrism\AdapterLibrary::fromSourcePackage($root, 'woocommerce')
    );
};
$preReviewManifest = $manifest;
unset(
    $preReviewManifest['term_meta']['order']['lint_ok'],
    $preReviewManifest['options']['woocommerce_thumbnail_cropping_custom_height']['lint_ok'],
    $preReviewManifest['options']['woocommerce_thumbnail_cropping_custom_width']['lint_ok']
);
$preReviewFindings = \WPrism\Lint::scan_tree(
    $lintState,
    $lintPolicy($preReviewManifest),
    \WPrism\LintEnvironment::live()
);
$preReviewLocators = array_column($preReviewFindings, 'locator');
sort($preReviewLocators, SORT_STRING);
woo_ok($preReviewLocators === [
    'meta.order',
    'meta.order',
] && array_values(array_unique(array_column($preReviewFindings, 'class'))) === ['bare_id'],
'the pre-review Woo policy reproduces both non-boolean bare-id collisions while wholly-1 option values stay suppressed');
woo_ok(
    \WPrism\Lint::scan_tree($lintState, $lintPolicy($manifest), \WPrism\LintEnvironment::live()) === [],
    'the shipped Woo policy audits the exact term-order and thumbnail-dimension scalars without suppressing other keys'
);
foreach ($lintFiles as $file) {
    unlink($file);
}
$removeLintTree($lintState);
$hposHarnesses = [
    $conformanceRunnerHarness,
    $wooPostdeployHarness,
    $matrixHarness,
    $wooMultisiteHarness,
];
$executableHposCli = array_filter(
    $hposHarnesses,
    static fn(string $harness): bool => preg_match('/^[[:space:]]*[^#\r\n]*\bwc[[:space:]]+hpos[[:space:]]+enable\b/m', $harness) === 1
);
woo_ok(
    substr_count($conformanceAssertsHarness, 'establish_woocommerce_hpos()') === 1
        && str_contains($conformanceAssertsHarness, 'WC_Install::maybe_enable_hpos();')
        && str_contains($conformanceAssertsHarness, 'WC_Install::create_tables();')
        && str_contains($conformanceAssertsHarness, 'OrderUtil::custom_orders_table_usage_is_enabled()')
        && str_contains($conformanceAssertsHarness, 'DataSynchronizer::class')
        && str_contains($conformanceAssertsHarness, 'check_orders_table_exists()')
        && preg_match_all('/^[[:space:]]*establish_woocommerce_hpos[[:space:]]+(?:wp_env|wp_conf2)\b/m', $conformanceRunnerHarness) === 2
        && preg_match_all('/^[[:space:]]*establish_woocommerce_hpos[[:space:]]+wp_conf2\b/m', $wooPostdeployHarness) === 1
        && preg_match_all('/^[[:space:]]*establish_woocommerce_hpos[[:space:]]+wp1\b/m', $matrixHarness) === 3
        && preg_match_all('/^[[:space:]]*establish_woocommerce_hpos[[:space:]]+wp1\b/m', $wooMultisiteHarness) === 1
        && strpos($wooPostdeployHarness, 'establish_woocommerce_hpos wp_conf2')
            < strpos($wooPostdeployHarness, 'wc_create_order()')
        && $executableHposCli === [],
    'every exact Woo live track establishes and verifies HPOS through one warning-free native new-shop helper before orders'
);
$wooMatrixWorkflowStart = strpos($woocommerceMatrixHarness, 'version_matrix_workflow() {');
$wooMatrixPlaceholderNormalization = $wooMatrixWorkflowStart === false
    ? false
    : strpos(
        $woocommerceMatrixHarness,
        'normalize_woocommerce_harness_placeholder_mode wp2',
        $wooMatrixWorkflowStart
    );
$wooMatrixApplyAfterNormalization = $wooMatrixPlaceholderNormalization === false
    ? false
    : strpos(
        $woocommerceMatrixHarness,
        'wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts',
        $wooMatrixPlaceholderNormalization
    );
$wooMatrixUpgradeNormalization = $wooMatrixPlaceholderNormalization === false
    ? false
    : strpos(
        $woocommerceMatrixHarness,
        'normalize_woocommerce_harness_placeholder_mode wp2',
        $wooMatrixPlaceholderNormalization + 1
    );
$wooMatrixUpgradeApply = $wooMatrixUpgradeNormalization === false
    ? false
    : strpos(
        $woocommerceMatrixHarness,
        'wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts',
        $wooMatrixUpgradeNormalization
    );
$wooReinstallDeploy = strpos($wooCheckHarness, 'REINSTALL_DEPLOY=');
$wooReinstallNormalization = $wooReinstallDeploy === false
    ? false
    : strpos(
        $wooCheckHarness,
        'normalize_woocommerce_harness_placeholder_mode wp_conf2',
        $wooReinstallDeploy
    );
$wooFinalApply = $wooReinstallNormalization === false
    ? false
    : strpos($wooCheckHarness, 'FINAL_APPLY=', $wooReinstallNormalization);
$wooLifecycleReinstall = strpos($woocommerceMatrixHarness, 'wp2 plugin install "$artifact" --force');
$wooLifecycleNormalization = $wooLifecycleReinstall === false
    ? false
    : strpos(
        $woocommerceMatrixHarness,
        'normalize_woocommerce_harness_placeholder_mode wp2',
        $wooLifecycleReinstall
    );
woo_ok(
    substr_count($conformanceAssertsHarness, 'normalize_woocommerce_harness_placeholder_mode()') === 1
        && str_contains($conformanceAssertsHarness, '019e9beec61c9ee5b6009335c7846816452e1e3b420d2bb9e50327681dfade19')
        && str_contains($conformanceAssertsHarness, '$mode !== 0666 || !@chmod($path, 0644)')
        && str_contains(
            $conformanceRunnerHarness,
            'establish_woocommerce_hpos normalize_woocommerce_harness_placeholder_mode'
        )
        && substr_count($conformanceRunnerHarness, 'normalize_woocommerce_harness_placeholder_mode wp_conf2') === 1
        && substr_count($wooCheckHarness, 'normalize_woocommerce_harness_placeholder_mode wp_conf2') === 2
        && strpos($conformanceRunnerHarness, 'normalize_woocommerce_harness_placeholder_mode wp_conf2')
            < strpos($conformanceRunnerHarness, 'say "apply conf2 (content only')
        && $wooReinstallNormalization !== false
        && $wooFinalApply !== false
        && $wooReinstallNormalization < $wooFinalApply
        && substr_count($woocommerceMatrixHarness, 'normalize_woocommerce_harness_placeholder_mode wp2') === 5
        && $wooMatrixApplyAfterNormalization !== false
        && $wooMatrixUpgradeNormalization !== false
        && $wooMatrixUpgradeApply !== false
        && $wooMatrixUpgradeNormalization < $wooMatrixUpgradeApply
        && $wooLifecycleNormalization !== false
        && !str_contains($conformanceRunnerHarness, 'chmod -R')
        && !str_contains($wooCheckHarness, 'chmod -R')
        && !str_contains($woocommerceMatrixHarness, 'chmod -R'),
    'every exact Woo target apply, upgrade, and reinstall normalizes only the hash-bound placeholder created by the cooperative test umask'
);
woo_ok(
    str_contains($wooMultisiteHarness, 'ARTIFACT=$(fetch_artifact woocommerce 11.0.1 cli1 plugin)')
        && !str_contains($wooMultisiteHarness, 'shasum -a 256 "$ARTIFACT"'),
    'Woo multisite installs the resolver-verified container artifact without treating its container path as a host file'
);
woo_ok(
    str_contains(
        $wooMultisiteHarness,
        <<<'SH'
wp1 plugin install "$ARTIFACT" --force --activate >/dev/null
woo_plugin_identity
establish_woocommerce_hpos wp1 >/dev/null \
  || fail "could not establish HPOS through WooCommerce's native new-shop lifecycle"
woo_identity
PLUGIN_TREE=$(woo_plugin_tree_hash)
require_observed_nonempty 'WooCommerce 11.0.1 plugin tree fingerprint' "$PLUGIN_TREE"
pass 'exact WooCommerce 11.0.1 plugin tree is installed, active, and HPOS-enabled'

say 'seed the populated native WooCommerce graph'
SH
        ),
    'Woo multisite establishes and verifies native new-shop HPOS before seeding the populated refusal fixture'
);

// The live conformance script is the candidate proof, but this offline pin
// holds its twelve reviewed families to one source/target/check topology.
// Each witness is a native operation or an explicit no-partial-state assertion,
// so a future cosmetic pass line cannot replace the exercised boundary.
$conformanceFamilyWitnesses = [
    'contract-dependency' => [$matrixHarness, [
        'woocommerce 10.9.4 (real wp.org release',
        'woocommerce synthetic 11.0.2',
        'PRE_REFUSAL_HEAD=$(git -C "siterepo/${PAIR}1" rev-parse HEAD)',
    ]],
    'clean-target' => [$wooSeedHarness, [
        'new WC_Product_External()',
        'Conformance Precision Download 東京 🚀',
        'woocommerce_feature_wc_visual_attribute_enabled yes',
        'pickup_location_settings',
    ]],
    'dirty-target' => [$wooPostdeployHarness, [
        '3147484000',
        'Hostile selected review page',
        'target-secret-token-preserved',
        'wprism-target-runtime-session',
    ]],
    'identity-references' => [$wooCheckHarness, [
        'hostile terms and typed natural keys retain divergent >2^31 target identities',
        'for key in brand_child brand_excluded brand_parent category category_parent color_blue color_red review_page tag shipping_class attribute_color attribute_size tax_class',
        'WooCommerce Store API did not expose the rebound target destination',
    ]],
    'native-behavior' => [$wooCheckHarness, [
        '/wp-json/wc/store/v1/products/',
        'external product resolves through Woo CRUD, v3 REST, Store API, and frontend',
        'Brands, category hierarchy, and visual attributes resolve through exact v3 REST, Store API, lookup/rewrite, image, and archive paths',
        '.locations[0].details == "<em>بوابة ٢</em><br>南口"',
        '.rate.label == "استلام 東京 (مخزن 東京)"',
        '.rate.details == "بوابة ٢南口"',
    ]],
    'derived-state' => [$wooCheckHarness, [
        'wp_get_attachment_image_src',
        'wc_product_meta_lookup',
        'product_brand_children',
        'without a background queue',
    ]],
    'deletion' => [$wooCheckHarness, [
        'WooCommerce supported product deletion capture',
        'supported product deletion did not emit its exact canonical tombstone',
        'WooCommerce supported product_variation deletion capture',
        'supported product_variation deletion did not emit its exact canonical tombstone',
        'source did not restore byte-identically after malformed/undeclared-COD/deletion probes',
    ]],
    'failure-recovery' => [$wooCheckHarness, [
        'lookup-schema provider failure',
        'provider failure advanced applied_revision before verified effects',
        'lookup-schema failure retains authored intent and retry authority',
    ]],
    'concurrency-idempotence' => [$wooCheckHarness, [
        'WPRISM_TEST_PROMOTION_PAUSE_MS=30000',
        'CONCURRENT_PAUSE_OBSERVED_AT))" -lt 25',
        'process_fence_held',
        'deterministic WooCommerce provider race refuses the loser',
    ]],
    'lifecycle' => [$wooCheckHarness, [
        'WooCommerce deploy after deactivation',
        'WooCommerce default uninstall changed retained catalog/configuration storage',
        'deactivate/reactivate, retained-data uninstall, absent-code refusal, exact reinstall, optional-extension isolation, and final retry',
    ]],
    'data-boundary' => [$wooCheckHarness, [
        'malformed product-attribute capture',
        'WooCommerce populated COD boundary capture',
        'cod_addon_secret',
        'undeclared sibling key(s)',
        'malformed attributes and undeclared COD refuse atomically; supported product and named product_variation deletions capture exactly and restore byte-identically',
    ]],
    'scope-platform' => [$wooCheckHarness, [
        'WooCommerce scope fixture unexpectedly activated optional extensions',
        'target-secret-token-preserved',
        'target-runtime@example.test',
    ]],
];
foreach ($conformanceFamilyWitnesses as $family => [$harness, $witnesses]) {
    $missingWitnesses = array_filter(
        $witnesses,
        static fn(string $witness): bool => !str_contains($harness, $witness)
    );
    woo_ok(count($witnesses) >= 3 && $missingWitnesses === [],
        "WooCommerce $family conformance remains bound to its native hostile/recovery witnesses");
}
$applyPreparationSource = (string) file_get_contents($root . '/agent/src/Apply/ApplyPreparationCoordinator.php');
woo_ok(str_contains($applyPreparationSource, '$pauseMs > 0 && $pauseMs <= 30000')
    && !str_contains($applyPreparationSource, '$pauseMs > 0 && $pauseMs <= 10000'),
    'the bounded test-only promotion pause covers the full Woo provider mutation guard');
$codBoundaryStart = strpos($wooCheckHarness, "FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'");
$codBoundaryEnd = $codBoundaryStart === false
    ? false
    : strpos($wooCheckHarness, 'DELETE_ROW=', $codBoundaryStart);
$codBoundary = $codBoundaryStart !== false && $codBoundaryEnd !== false
    ? substr($wooCheckHarness, $codBoundaryStart, $codBoundaryEnd - $codBoundaryStart)
    : '';
woo_ok(
    str_contains($codBoundary, '"cod_addon_secret" => "AKIAABCDEFGHIJKLMNOP"')
        && str_contains($codBoundary, "grep -Fq 'woocommerce_cod_settings'")
        && str_contains($codBoundary, "grep -Fq 'undeclared sibling key(s)'")
        && str_contains($codBoundary, '! grep -Fq "$FAKE_SECRET"')
        && !str_contains($codBoundary, 'unclassified option'),
    'the live COD boundary rejects one bounded undeclared add-on sibling key, names the closed option, and redacts the sentinel instead of expecting an unclassified option'
);
$codNormalizeStart = strpos($wooInterpreterSource, 'public function normalize_captured_option_sub_keys(');
$codMaterializeStart = $codNormalizeStart === false
    ? false
    : strpos($wooInterpreterSource, 'public function materialize_option_sub_keys(', $codNormalizeStart);
$codNormalize = $codNormalizeStart !== false && $codMaterializeStart !== false
    ? substr($wooInterpreterSource, $codNormalizeStart, $codMaterializeStart - $codNormalizeStart)
    : '';
$codUnknownCheck = strpos($codNormalize, "assert_mixed_record_keys(\$name, \$decoded, \$fields, 'source');");
$codValidationTopology = strpos($codNormalize, '$this->assert_mixed_validation_topology($fields, $rawAuthored);');
$codMutationTopology = strpos($wooInterpreterSource, '$this->assert_mixed_option_mutation_hooks($name, $targetWasPresent);');
$codUnknownAbsolute = $codUnknownCheck === false || $codNormalizeStart === false
    ? false
    : $codNormalizeStart + $codUnknownCheck;
$codTrackingHook = strpos($wooInterpreterSource, "self::WOO_SETTINGS_TRACKING, 'track_setting_change'");
woo_ok(
    $codUnknownCheck !== false
        && $codValidationTopology !== false
        && $codUnknownCheck < $codValidationTopology
        && !str_contains($codNormalize, 'WOO_SETTINGS_TRACKING')
        && $codMutationTopology !== false
        && $codUnknownAbsolute !== false
        && $codUnknownAbsolute < $codMutationTopology
        && $codTrackingHook !== false
        && $codMutationTopology < $codTrackingHook,
    'COD undeclared sibling keys refuse during capture normalization before native validation and WC_Settings_Tracking mutation topology'
);
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
    'wp_conf1 theme activate twentytwentyfive',
    'restore_conformance_theme 0',
    "--type=wc-visual",
    'VisualAttributeTermMeta::save_term_visual_from_request',
    "'/wc/v3/products/attributes/",
    "update_post_meta(\$COUPON_ID, 'product_brands'",
] as $termSeedWitness) {
    woo_ok(str_contains($wooSeedHarness, $termSeedWitness),
        "category/brand/visual source fixture pins $termSeedWitness");
}
$themeScopeMatch = [];
woo_ok(
    preg_match('/^# WPRISM_THEME_SCOPE_BEGIN\n(.*?)^# WPRISM_THEME_SCOPE_END$/ms', $wooSeedHarness, $themeScopeMatch) === 1,
    'visual-attribute fixture exposes one executable theme-scope block for failure-path testing'
);
if ($themeScopeMatch !== []) {
    $scopeScript = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
ACTIVE_THEME=twentytwentyone
wp_conf1() {
    case "$1 $2" in
        'theme list')
            printf '%s\n' "$ACTIVE_THEME"
            ;;
        'theme activate')
            ACTIVE_THEME="$3"
            printf '%s\n' "$ACTIVE_THEME" >> "$WPRISM_THEME_LOG"
            ;;
        'option update')
            ;;
        'wc product_attribute')
            if [ "${WPRISM_TEST_FAIL_VISUAL:-0}" = 1 ] && [[ "$*" == *'Conf Color'* ]]; then
                return 42
            fi
            printf '42\n'
            ;;
        *)
            return 99
            ;;
    esac
}
BASH;
    $scopeScript .= "\n" . $themeScopeMatch[1];
    $scopeScript .= <<<'BASH'
printf 'active=%s\n' "$ACTIVE_THEME"
BASH;
    $scopePath = tempnam(sys_get_temp_dir(), 'wprism-woo-theme-scope-');
    $scopeLog = tempnam(sys_get_temp_dir(), 'wprism-woo-theme-log-');
    file_put_contents($scopePath, $scopeScript);
    chmod($scopePath, 0700);
    $runScope = static function (string $script, string $log, bool $fail): array {
        $pipes = [];
        $environment = $_ENV;
        $environment['WPRISM_THEME_LOG'] = $log;
        $environment['WPRISM_TEST_FAIL_VISUAL'] = $fail ? '1' : '0';
        $process = proc_open(
            ['/bin/bash', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            return [127, '', 'proc_open failed'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    };
    [$successStatus, $successOutput, $successError] = $runScope($scopePath, $scopeLog, false);
    $successLog = (string) file_get_contents($scopeLog);
    woo_ok(
        $successStatus === 0
        && trim($successOutput) === 'active=twentytwentyone'
        && $successError === ''
        && $successLog === "twentytwentyfive\ntwentytwentyone\n",
        'visual-attribute scope restores the original theme and proves the success ordering'
    );
    file_put_contents($scopeLog, '');
    [$failureStatus, $failureOutput, $failureError] = $runScope($scopePath, $scopeLog, true);
    $failureLog = (string) file_get_contents($scopeLog);
    woo_ok(
        $failureStatus !== 0
        && $failureOutput === ''
        && $failureError === ''
        && $failureLog === "twentytwentyfive\ntwentytwentyone\n",
        'injected visual-attribute failure still restores the original theme before refusing'
    );
    unlink($scopePath);
    unlink($scopeLog);
}
foreach ([
    'OrderReviews\\Endpoint::class',
    'woocommerce_review_order_page_id',
    'review-order-source',
] as $reviewSeedWitness) {
    woo_ok(str_contains($wooSeedHarness, $reviewSeedWitness),
        "customer-review source fixture pins $reviewSeedWitness");
}
foreach ([
    'Hostile selected review page',
    'TARGET_REVIEW_PAGE_ID',
    "--slug=conformance-widgets --parent=\"\$TARGET_CAT_PARENT_ID\" --porcelain",
    "--slug=atelier-tokyo --parent=\"\$TARGET_BRAND_PARENT_ID\" --porcelain",
    'woocommerce_review_order_flush_rewrite_pending',
] as $reviewTargetWitness) {
    woo_ok(str_contains($wooPostdeployHarness, $reviewTargetWitness),
        "customer-review hostile target fixture pins $reviewTargetWitness");
}
foreach ([
    '.hostile_review_page',
    'length == 1 and .[0] ==',
    'option_points_here',
    'wp_delete_post($id, true)',
    'distinct target-local Review Order page',
] as $reviewPostapplyWitness) {
    woo_ok(str_contains($wooPostapplyHarness, $reviewPostapplyWitness),
        "customer-review target-local preservation fixture pins $reviewPostapplyWitness");
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
woo_ok(
    str_contains($wooCheckHarness, '/wp-json/wc/store/v1/products/brands/atelier-tokyo')
        && !str_contains($wooCheckHarness, "--data-urlencode 'slug=atelier-tokyo'"),
    'the Store API brand witness uses Woo 11.0.x single-brand slug routing instead of an unsupported collection parameter'
);
foreach ([
    'wc_get_review_order_url',
    '.review_order.rule_actual == .review_order.rule_expected',
    'native Review Order URL resolves the migrated page',
] as $reviewCheckWitness) {
    woo_ok(str_contains($wooCheckHarness, $reviewCheckWitness),
        "customer-review native route fixture pins $reviewCheckWitness");
}
foreach ([
    '$project_stock([$simple, $small], 0, "outofstock")',
    '$project_parent_status($variable, "outofstock")',
    'target-local stock projection did not converge exactly',
    '$project_stock([$simple, $small], 5, "instock")',
    '$project_parent_status($variable, "instock")',
] as $stockProjectionWitness) {
    woo_ok(str_contains($wooCheckHarness, $stockProjectionWitness),
        "repeatable target-stock projection pins $stockProjectionWitness");
}
woo_ok(
    strpos($wooCheckHarness, '$project_stock([$simple, $small], 0, "outofstock")')
        < strpos($wooCheckHarness, '$blocked_without_target_stock = !$empty_stock_cart->add_to_cart')
        && strpos($wooCheckHarness, '$blocked_without_target_stock = !$empty_stock_cart->add_to_cart')
        < strpos($wooCheckHarness, '$project_stock([$simple, $small], 5, "instock")'),
    'each lifecycle invocation establishes the empty-stock premise before proving target-local stock'
);
foreach ([
    'THUMBNAIL_LAZY_RC=0',
    'THUMBNAIL_LAZY_RAW=$($COMPOSE run',
    ') || THUMBNAIL_LAZY_RC=$?',
    'thumbnail convergence WP-CLI probe failed (exit $THUMBNAIL_LAZY_RC)',
    "awk 'NF { line=\$0 } END { print line }'",
    'get_theme_support("woocommerce")',
    'remove_theme_support("woocommerce")',
    'wp_cache_delete("size-woocommerce_thumbnail", "woocommerce")',
    'WC()->add_image_sizes()',
    'wp_get_registered_image_subsizes()',
    'managed product image preimage is unavailable for isolation evidence',
    '$managed_product_files_before = $attachment_state($managed_product_file)',
    '$create_image(800, 800, "product-probe")',
    '$temporary_ids[] = (int) $id',
    '$temporary_files[(int) $id] = $file',
    '$owned_before_delete = $attachment_state($temporary_file, false)',
    'temporary attachment prefix changed outside its frozen ownership',
    'isolated thumbnail probe left attachment or file-prefix residue',
    'isolated thumbnail probe cleanup failed:',
    'wp_get_attachment_metadata($managed_product_image_id) !== $managed_product_metadata_before',
    '$attachment_state($managed_product_file) !== $managed_product_files_before',
    'isolated thumbnail probe changed the exact managed product preimage or left owned residue',
    '.fixture_isolated == true',
    '"theme_override_width"',
    'static fn(array $editors): array => []',
    '"failed_metadata_changed"',
    '"failed_full_dims"',
    '"failed_filesize_positive"',
    '"failed_size_names"',
] as $thumbnailProbeWitness) {
    woo_ok(str_contains($wooCheckHarness, $thumbnailProbeWitness),
        "thumbnail live probe preserves nonzero WP-CLI evidence: $thumbnailProbeWitness");
}
woo_ok(!str_contains($wooCheckHarness, '$restore_attachment_files')
    && !str_contains($wooCheckHarness, 'unlink($managed_product_file)')
    && !str_contains($wooCheckHarness, 'file_put_contents($managed_product_file'),
    'thumbnail evidence observes the managed product preimage without restoring, deleting, or overwriting it');
woo_ok(!str_contains($wooCheckHarness, '$create_image(800, 600, "product-probe")'),
    'same-aspect live evidence uses the exact square original required to prove native full-image fallback');
woo_ok(
    str_contains($wooCheckHarness, '.simple.image[1] == 450 and .simple.image[2] == 450')
        && !str_contains($wooCheckHarness, '.simple.image[1] == 500 and .simple.image[2] == 500'),
    'the final aggregate witness retains the exact Twenty Twenty-One 450px Woo thumbnail override'
);
woo_ok(
    strpos($wooCheckHarness, 'thumbnail convergence WP-CLI probe failed (exit $THUMBNAIL_LAZY_RC)')
        < strpos($wooCheckHarness, 'require_observed_nonempty "conf2 WooCommerce thumbnail lazy-convergence observation"'),
    'thumbnail live probe reports the raw failing command before set -e or JSON parsing can swallow it'
);
woo_ok(!str_contains($wooCheckHarness, 'WPrism_Woo_Missing_Image_Editor'),
    'thumbnail failure injection asks WordPress for its native no-editor error instead of naming an unloadable callback');
woo_ok(!str_contains($wooCheckHarness, 'failed_metadata_unchanged'),
    'thumbnail evidence does not retain the disproved metadata-preservation assertion');
$thumbnailBehavior = (string) ($manifest['notes'][
    'thumbnail image settings and request-time convergence (supersedes every earlier thumbnail fail-closed sentence)'
] ?? '');
foreach ([
    'Missing attachment metadata or source-file preconditions preserve the prior image and metadata',
    'On the evidenced normal 800-by-600 JPEG path with no scale, format conversion, or EXIF rotation',
    'WordPress 7.1 writes base metadata (file, width, height, filesize, sizes=[]) before subsize editor selection',
    'An unavailable subsize editor therefore returns the bounded full image with that base metadata persisted',
] as $thumbnailBehaviorWitness) {
    woo_ok(str_contains($thumbnailBehavior, $thumbnailBehaviorWitness),
        "shipped Woo thumbnail claim pins native failure behavior: $thumbnailBehaviorWitness");
}
$seedUpdate = strpos($wooCheckHarness, 'wp_update_attachment_metadata((int) $id, $metadata)');
$seedReadback = strpos($wooCheckHarness, 'wp_get_attachment_metadata((int) $id)', $seedUpdate === false ? 0 : $seedUpdate);
woo_ok($seedUpdate !== false && $seedReadback !== false && $seedReadback > $seedUpdate,
    'thumbnail seed verifies native metadata by readback after the ambiguous update result');
foreach ([
    $conformanceRunnerHarness,
    $matrixHarness,
    $wooMultisiteHarness,
] as $directComposeHarness) {
    woo_ok(str_contains($directComposeHarness, 'pair_identity_export_source_mounts'),
        'Woo live evidence keeps candidate mounts caller-local across parallel shared-.env rewrites');
}
woo_ok(str_contains($matrixHarness, 'update_option("default_category", (int) $category->term_id)'), 'version-matrix resets the core default-category reference before each plugin boundary');
$wooMatrixTaxonomies = '"taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]';
woo_ok(substr_count($matrixHarness, $wooMatrixTaxonomies) === 3,
    'the two exact Woo boundaries and both range controls share the reviewed closed taxonomy roster');
woo_ok(str_contains($matrixHarness, 'check_woocommerce_boundary_lifecycle "$WOO_VERSION" "$ARTIFACT_2"'),
    'each exact WooCommerce boundary runs lifecycle evidence before the populated upgrade leg');
foreach ([
    'woocommerce_boundary_storage_hash',
    'wc_product_download_directories ORDER BY url_id',
    'WC_REMOVE_ALL_DATA',
    'e06e0c2086f695d39f5d9edead87cd4faeb0ea45184d77e7d8fe5588abfde48e',
    'default uninstall changed retained authored or target-runtime storage',
    'missing-code refusal partially changed retained storage',
    'lifecycle artifact digest moved before reinstall proof',
    'check_woocommerce_content',
    '.tmp-woo-lifecycle-final',
] as $lifecycleWitness) {
    woo_ok(str_contains($woocommerceMatrixHarness, $lifecycleWitness),
        "exact WooCommerce lifecycle matrix pins $lifecycleWitness");
}
$productFields = (array) ($manifest['post_types']['product']['fields'] ?? []);
$variationFields = (array) ($manifest['post_types']['product_variation']['fields'] ?? []);
woo_ok(
    ($productFields['modified'] ?? null) === ['class' => 'derived']
        && ($productFields['modified_gmt'] ?? null) === ['class' => 'derived']
        && ($variationFields['modified'] ?? null) === ['class' => 'derived']
        && ($variationFields['modified_gmt'] ?? null) === ['class' => 'derived'],
    'only Woo product and variation persistence timestamps authorize lifecycle rendered-byte variance'
);
$downgradeComparatorPath = dirname(__DIR__, 2) . '/fixtures/woocommerce-downgrade-recapture.php';
$downgradeComparatorSource = (string) file_get_contents($downgradeComparatorPath);
require_once $downgradeComparatorPath;
woo_ok(
    str_contains($downgradeComparatorSource, "str_starts_with(\$path, 'posts/product/')")
        && str_contains($downgradeComparatorSource, "['conformance-widget', 'conformance-precision-download']")
        && str_contains($downgradeComparatorSource, "['modified', 'modified_gmt']")
        && str_contains($downgradeComparatorSource, 'source and target tree inventories differ')
        && str_contains($downgradeComparatorSource, 'recapture differs outside derived product timestamps')
        && !str_contains($downgradeComparatorSource, 'product_variation')
        && !str_contains($downgradeComparatorSource, 'del(.modified'),
    'downgrade recapture comparator permits only both existing timestamp values on the two evidenced product fixtures'
);

$downgradeFixtureRoot = sys_get_temp_dir() . '/wprism-woo-downgrade-' . bin2hex(random_bytes(6));
$downgradeSourceRoot = $downgradeFixtureRoot . '/source';
$downgradeTargetRoot = $downgradeFixtureRoot . '/target';
$removeDowngradeFixture = static function () use ($downgradeFixtureRoot): void {
    if (!is_dir($downgradeFixtureRoot)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($downgradeFixtureRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($downgradeFixtureRoot);
};
register_shutdown_function($removeDowngradeFixture);
foreach ([$downgradeSourceRoot, $downgradeTargetRoot] as $fixtureRoot) {
    mkdir($fixtureRoot . '/posts/product', 0777, true);
    mkdir($fixtureRoot . '/posts/product_variation', 0777, true);
    mkdir($fixtureRoot . '/options', 0777, true);
}
$renderDowngradeProduct = static fn(
    string $slug,
    string $modified,
    string $modifiedGmt,
    string $body
): string => "---\n{\n"
    . "    \"modified\": \"$modified\",\n"
    . "    \"modified_gmt\": \"$modifiedGmt\",\n"
    . "    \"slug\": \"$slug\"\n"
    . "}\n---\n$body\n";
$downgradePaths = [
    'widget' => 'posts/product/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa--conformance-widget.md',
    'precision' => 'posts/product/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb--conformance-precision-download.md',
];
$downgradeSourceBytes = [
    'widget' => $renderDowngradeProduct('conformance-widget', '2026-08-27 00:45:28', '2026-08-27 00:45:28', 'widget body'),
    'precision' => $renderDowngradeProduct('conformance-precision-download', '2026-08-27 00:47:28', '2026-08-27 00:47:28', 'precision body'),
];
$downgradeTargetBytes = [
    'widget' => $renderDowngradeProduct('conformance-widget', '2026-08-27 00:43:31', '2026-08-27 00:43:31', 'widget body'),
    'precision' => $renderDowngradeProduct('conformance-precision-download', '2026-08-27 00:39:29', '2026-08-27 00:39:29', 'precision body'),
];
foreach ($downgradePaths as $name => $relative) {
    file_put_contents($downgradeSourceRoot . '/' . $relative, $downgradeSourceBytes[$name]);
    file_put_contents($downgradeTargetRoot . '/' . $relative, $downgradeTargetBytes[$name]);
}
$strictRelative = 'options/woocommerce.json';
file_put_contents($downgradeSourceRoot . '/' . $strictRelative, "{\"strict\":true}\n");
file_put_contents($downgradeTargetRoot . '/' . $strictRelative, "{\"strict\":true}\n");
$variationRelative = 'posts/product_variation/cccccccc-cccc-4ccc-8ccc-cccccccccccc--variation.md';
$variationBytes = $renderDowngradeProduct('variation', '2026-08-27 00:40:00', '2026-08-27 00:40:00', 'variation body');
file_put_contents($downgradeSourceRoot . '/' . $variationRelative, $variationBytes);
file_put_contents($downgradeTargetRoot . '/' . $variationRelative, $variationBytes);

\WPrism\Tests\Support\assert_woocommerce_downgrade_recapture($downgradeSourceRoot, $downgradeTargetRoot);
woo_ok(true, 'downgrade comparator accepts exactly the two evidenced product timestamp pairs');
$downgradeComparatorRefuses = static function (string $message) use ($downgradeSourceRoot, $downgradeTargetRoot): void {
    $threw = false;
    try {
        \WPrism\Tests\Support\assert_woocommerce_downgrade_recapture($downgradeSourceRoot, $downgradeTargetRoot);
    } catch (RuntimeException) {
        $threw = true;
    }
    woo_ok($threw, $message);
};

file_put_contents(
    $downgradeTargetRoot . '/' . $downgradePaths['widget'],
    str_replace('    "modified_gmt": "2026-08-27 00:43:31",' . "\n", '', $downgradeTargetBytes['widget'])
);
$downgradeComparatorRefuses('downgrade comparator rejects a missing derived timestamp field');
file_put_contents($downgradeTargetRoot . '/' . $downgradePaths['widget'], $downgradeTargetBytes['widget']);

file_put_contents(
    $downgradeTargetRoot . '/' . $downgradePaths['precision'],
    $downgradeTargetBytes['precision'] . 'unexpected body change'
);
$downgradeComparatorRefuses('downgrade comparator rejects body drift on an evidenced product');
file_put_contents($downgradeTargetRoot . '/' . $downgradePaths['precision'], $downgradeTargetBytes['precision']);

file_put_contents(
    $downgradeTargetRoot . '/' . $variationRelative,
    str_replace('2026-08-27 00:40:00', '2026-08-27 00:41:00', $variationBytes)
);
$downgradeComparatorRefuses('downgrade comparator rejects product-variation timestamp drift');
file_put_contents($downgradeTargetRoot . '/' . $variationRelative, $variationBytes);

file_put_contents($downgradeTargetRoot . '/options/unexpected.json', "{}\n");
$downgradeComparatorRefuses('downgrade comparator rejects any added tree entry');
unlink($downgradeTargetRoot . '/options/unexpected.json');

file_put_contents($downgradeTargetRoot . '/' . $downgradePaths['widget'], $downgradeSourceBytes['widget']);
\WPrism\Tests\Support\assert_woocommerce_downgrade_recapture($downgradeSourceRoot, $downgradeTargetRoot);
woo_ok(true, 'downgrade comparator accepts exact equality for one evidenced product');
file_put_contents($downgradeTargetRoot . '/' . $downgradePaths['precision'], $downgradeSourceBytes['precision']);
\WPrism\Tests\Support\assert_woocommerce_downgrade_recapture($downgradeSourceRoot, $downgradeTargetRoot);
woo_ok(true, 'downgrade comparator accepts a completely byte-identical tree');
file_put_contents($downgradeTargetRoot . '/' . $downgradePaths['widget'], $downgradeTargetBytes['widget']);
file_put_contents($downgradeTargetRoot . '/' . $downgradePaths['precision'], $downgradeTargetBytes['precision']);
$removeDowngradeFixture();
woo_ok(
    str_contains(
        $woocommerceMatrixHarness,
        'exact-reinstall recapture diverged outside declared derived product timestamps'
    )
        && str_contains(
            $woocommerceMatrixHarness,
            "posts/(product|product_variation)/[^ ]+ .*/\\.tmp-woo-lifecycle-final/posts/(product|product_variation)/[^ ]+"
        )
        && str_contains(
            $woocommerceMatrixHarness,
            '"modified(_gmt)?": "[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}"'
        )
        && !str_contains($woocommerceMatrixHarness, 'exact-reinstall recapture lost byte identity'),
    'exact WooCommerce lifecycle recapture permits only manifest-declared product timestamp drift'
);
woo_ok(
    str_contains(
        $matrixHarness,
        'in-place upgrade recapture diverged outside declared derived product timestamps'
    )
        && str_contains(
            $matrixHarness,
            "posts/(product|product_variation)/[^ ]+ .*/\\.tmp-woo-upgrade-final/posts/(product|product_variation)/[^ ]+"
        )
        && str_contains(
            $matrixHarness,
            'UPGRADE_DIFF=$(diff -r'
        )
        && !str_contains(
            $matrixHarness,
            'WooCommerce 11.0.0 to 11.0.1 in-place upgrade lost byte identity'
        ),
    'exact WooCommerce upgrade recapture permits only manifest-declared product timestamp drift'
);
woo_ok(
    str_contains(
        $woocommerceMatrixHarness,
        '$package_tests/../fixtures/woocommerce-downgrade-recapture.php'
    )
        && str_contains(
            $woocommerceMatrixHarness,
            'in-range downgrade recapture diverged outside declared derived product timestamps'
        )
        && strpos($woocommerceMatrixHarness, 'wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-woo-downgrade-final')
            < strpos($woocommerceMatrixHarness, '$package_tests/../fixtures/woocommerce-downgrade-recapture.php')
        && strpos($woocommerceMatrixHarness, '$package_tests/../fixtures/woocommerce-downgrade-recapture.php')
            < strpos($woocommerceMatrixHarness, 'rm -rf "siterepo/${PAIR}2/.tmp-woo-downgrade-final"')
        && !str_contains($woocommerceMatrixHarness, 'downgrade_diff=$(diff -rq'),
    'exact WooCommerce downgrade recapture invokes the strict two-product timestamp comparator before cleanup'
);
woo_ok(str_contains($wooCheckHarness, 'wc_product_download_directories ORDER BY url_id')
    && !str_contains($wooCheckHarness, 'wc_product_download_directories ORDER BY id'),
    'provider-race storage evidence orders WooCommerce approved directories by the exact url_id primary key');
foreach ([
    'woocommerce_preapply_authority_assertion()',
    'wprism-woocommerce-preapply-authority/v1',
    'loaded_manifests',
    'jq -se',
    'length == 1',
    'option:pickup_location_pickup_locations',
    'option:woocommerce_bacs_settings',
    'post_meta:_product_url',
    'term_meta:display_type',
    'WooCommerce pre-apply authority assertion failed:',
] as $preapplyAuthorityWitness) {
    woo_ok(str_contains($woocommerceMatrixHarness, $preapplyAuthorityWitness),
        "exact WooCommerce pre-apply authority guard pins $preapplyAuthorityWitness");
}
woo_ok(
    substr_count($woocommerceMatrixHarness, 'woocommerce_preapply_authority_assertion ') === 3
        && str_contains($woocommerceMatrixHarness, "woocommerce_preapply_authority_assertion \"\$WOO_VERSION\" 'exact boundary'")
        && str_contains($woocommerceMatrixHarness, "woocommerce_preapply_authority_assertion 11.0.0 'in-range downgrade'")
        && strpos($woocommerceMatrixHarness, "woocommerce_preapply_authority_assertion 11.0.0 'in-range downgrade'")
            < strpos(
                $woocommerceMatrixHarness,
                'wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$revision"',
                strpos($woocommerceMatrixHarness, "woocommerce_preapply_authority_assertion 11.0.0 'in-range downgrade'")
            )
        && str_contains($woocommerceMatrixHarness, "woocommerce_preapply_authority_assertion 11.0.1 'in-place upgrade'")
        && strpos($woocommerceMatrixHarness, "woocommerce_preapply_authority_assertion 11.0.1 'in-place upgrade'")
            < strpos(
                $woocommerceMatrixHarness,
                'wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV"',
                strpos($woocommerceMatrixHarness, "woocommerce_preapply_authority_assertion 11.0.1 'in-place upgrade'")
            ),
    'every successful exact WooCommerce boundary, upgrade, and downgrade apply emits deterministic loaded-manifest and diagnostic-seam rule authority evidence first'
);
woo_ok(substr_count($woocommerceMatrixHarness, 'check_woocommerce_boundary_lifecycle "$WOO_VERSION" "$ARTIFACT_2"') === 1
    && str_contains($woocommerceMatrixHarness, 'for WOO_VERSION in 11.0.0 11.0.1; do'),
    'one lifecycle call inside the exact two-artifact loop covers 11.0.0 and 11.0.1 independently');
woo_ok(substr_count($woocommerceMatrixHarness, 'check_woocommerce_product_deletion "$WOO_VERSION"') === 1
    && str_contains($woocommerceMatrixHarness, 'for WOO_VERSION in 11.0.0 11.0.1; do'),
    'one guarded product plus successful product/variation deletion call inside the exact two-artifact loop covers 11.0.0 and 11.0.1 independently');
$wooMatrixCaseStart = strpos($woocommerceMatrixHarness, 'version_matrix_workflow() {');
$wooMatrixCase = $wooMatrixCaseStart !== false
    ? substr($woocommerceMatrixHarness, $wooMatrixCaseStart)
    : '';
$wooPostapplyCall = strpos($wooMatrixCase, 'postapply_woocommerce_content');
$wooApplySuccess = strpos($wooMatrixCase, 'pass "deploy + apply succeeded on side 2');
$wooCheck = strpos($wooMatrixCase, 'check_woocommerce_content');
$wooCanonicalRecapture = strpos($wooMatrixCase, 'wp2 wprism capture --repo=/siterepo --out="/siterepo/.tmp-final"');
$wooBoundaryApply = strpos(
    $wooMatrixCase,
    'wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"'
);
$wooBoundaryCanary = $wooBoundaryApply === false
    ? false
    : strpos($wooMatrixCase, 'grep -q \'canary clean\' "$VMATRIX_APPLY_LOG"', $wooBoundaryApply);
$wooBoundaryReceipt = $wooBoundaryCanary === false
    ? false
    : strpos($wooMatrixCase, 'WOOCOMMERCE_BOUNDARY_PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")', $wooBoundaryCanary);
$wooUpgradeAuthority = strpos(
    $wooMatrixCase,
    "woocommerce_preapply_authority_assertion 11.0.1 'in-place upgrade'"
);
$wooUpgradeApply = $wooUpgradeAuthority === false
    ? false
    : strpos(
        $wooMatrixCase,
        'wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV"',
        $wooUpgradeAuthority
    );
$wooUpgradeCanary = $wooUpgradeApply === false
    ? false
    : strpos($wooMatrixCase, 'grep -q \'canary clean\' "$VMATRIX_APPLY_LOG"', $wooUpgradeApply);
$wooUpgradeProviderCount = $wooUpgradeCanary === false
    ? false
    : strpos(
        $wooMatrixCase,
        'UPGRADE_PROVIDER_COUNT=$(grep -Ec \'provider capability fired:\' "$VMATRIX_APPLY_LOG" || true)',
        $wooUpgradeCanary
    );
$wooUpgradeProviderAssertion = $wooUpgradeProviderCount === false
    ? false
    : strpos($wooMatrixCase, '[ "$UPGRADE_PROVIDER_COUNT" -eq 1 ]', $wooUpgradeProviderCount);
$wooUpgradeLookupReceipt = $wooUpgradeProviderAssertion === false
    ? false
    : strpos(
        $wooMatrixCase,
        'woocommerce-product-lookups@3\\.0\\.0 rebuild_product_lookups \\([0-9]+(\\.[0-9]+)?s, verified\\)',
        $wooUpgradeProviderAssertion
    );
$wooUpgradeCheck = $wooUpgradeLookupReceipt === false
    ? false
    : strpos($wooMatrixCase, 'check_woocommerce_content', $wooUpgradeLookupReceipt);
woo_ok(
    substr_count($wooMatrixCase, 'WOOCOMMERCE_BOUNDARY_PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")') === 1
        && str_contains($woocommerceMatrixHarness, 'local APPLY_JSON="${WOOCOMMERCE_BOUNDARY_PROVIDER_RECEIPT:-}"')
        && str_contains($wooCheckHarness, 'PROVIDER_RECEIPT="${APPLY_JSON:-}"')
        && $wooBoundaryApply !== false
        && $wooBoundaryCanary !== false
        && $wooBoundaryReceipt !== false
        && $wooPostapplyCall !== false
        && $wooBoundaryApply < $wooBoundaryCanary
        && $wooBoundaryCanary < $wooBoundaryReceipt
        && $wooBoundaryReceipt < $wooPostapplyCall
        && $wooUpgradeApply !== false
        && $wooUpgradeCanary !== false
        && $wooUpgradeProviderCount !== false
        && $wooUpgradeProviderAssertion !== false
        && $wooUpgradeLookupReceipt !== false
        && $wooUpgradeCheck !== false
        && $wooUpgradeCanary < $wooUpgradeProviderCount
        && $wooUpgradeProviderCount < $wooUpgradeProviderAssertion
        && $wooUpgradeProviderAssertion < $wooUpgradeLookupReceipt
        && $wooUpgradeLookupReceipt < $wooUpgradeCheck,
    'exact Woo matrix preserves each full-import receipt before the shared check and proves the product-note upgrade invoked only verified product lookups'
);
woo_ok(
    substr_count($woocommerceMatrixHarness, 'postapply_woocommerce_content() {') === 1
        && str_contains(
            $woocommerceMatrixHarness,
            '. "$(dirname "${BASH_SOURCE[0]}")/../conformance/postapply.sh"'
        )
        && substr_count($wooMatrixCase, 'postapply_woocommerce_content') === 1
        && $wooApplySuccess !== false
        && $wooPostapplyCall !== false
        && $wooCheck !== false
        && $wooCanonicalRecapture !== false
        && $wooApplySuccess < $wooPostapplyCall
        && $wooPostapplyCall < $wooCheck
        && $wooCheck < $wooCanonicalRecapture,
    'each exact WooCommerce boundary invokes its target-local post-apply hook once after apply and before canonical recapture'
);
foreach ([
    'wc_get_product_id_by_sku("CONF-EXTERNAL-1")',
    '*--conformance-external-partner.md',
    'SELECT content_hash FROM wp_wprism_state',
    'wc_order_product_lookup',
    'deletes blocked by referential guards',
    'WPRISM-DELETE-${version//./-}',
    '.deletion_type == "product"',
    'wp_wc_product_attributes_lookup',
    '.reason_code == "deletion_writer_exclusion_required"',
    'external-exclusion refusal changed product lookup rows',
    'local product deletion refuses before mutation without signed external writer exclusion',
] as $deletionWitness) {
    woo_ok(str_contains($woocommerceMatrixHarness, $deletionWitness),
        "exact WooCommerce product-deletion matrix pins $deletionWitness");
}
foreach ([
    'WPRISM-DELETE-VARIATION-${version//./-}',
    'new WC_Product_Variation()',
    'source capture omitted the named product_variation',
    'local product_variation tombstone was malformed',
    '.deletion_type == "product_variation"',
    'wp_wc_product_meta_lookup WHERE product_id=$variation_target',
    'wp_wc_product_attributes_lookup WHERE product_id=$variation_target',
    '.reason_code == "deletion_writer_exclusion_required"',
    'external-exclusion refusal changed the named variation',
    'external-exclusion refusal changed variation lookup rows',
    'local product_variation deletion refuses before mutation without signed external writer exclusion',
] as $variationDeletionWitness) {
    woo_ok(str_contains($woocommerceMatrixHarness, $variationDeletionWitness),
        "exact WooCommerce product_variation-deletion matrix pins $variationDeletionWitness");
}
$malformedShellInterpolation = <<<'SHELL'
'"'"'"$
SHELL;
$variableSkuInterpolation = <<<'SHELL'
wc_get_product_id_by_sku('"'"$
SHELL;
woo_ok(
    !str_contains($woocommerceMatrixHarness, $malformedShellInterpolation)
        && substr_count($woocommerceMatrixHarness, $variableSkuInterpolation) >= 6,
    'exact WooCommerce deletion fixture commands interpolate their disposable SKUs instead of passing literal shell variables'
);
foreach ([
    'wprism_ssh_adopt_extension() {',
    'wp plugin install woocommerce --version=$woo_version --activate',
    'wp wprism manifest-pin --repo=/home/wprism/site --name=woocommerce',
    '"wprism-deletion-owner-agreements/v2"',
    '--roots="tombstone:$product_uuid" --contract',
    'promote target --scope-contract="$contract" --with-deletes --format=json',
    'provider-state.json.fail-verify-after',
    'delete commit boundary',
    '.state == "rolled_back" and .terminal == true',
    'failed_product" = "$product_id"',
    'failed_lookup" = "$lookup_before"',
    '.scoped_apply.verification.selected_deletions == 1',
    'success_product" = "0"',
    'success_lookup" = "0"',
    '.delete == [] and .delete_conflict == []',
] as $scopedDeletionWitness) {
    woo_ok(str_contains($woocommerceScopedDeletionHarness, $scopedDeletionWitness),
        "candidate-bound WooCommerce scoped-deletion live proof pins $scopedDeletionWitness");
}
$failurePromotion = strpos(
    $woocommerceScopedDeletionHarness,
    'promote target --scope-contract="$contract" --with-deletes --format=json >"$failure_stdout"'
);
$failureCommitFrontier = strpos($woocommerceScopedDeletionHarness, "grep -Fq 'delete commit boundary'");
$failureRollbackReceipt = strpos(
    $woocommerceScopedDeletionHarness,
    '.state == "rolled_back" and .terminal == true'
);
$retryPromotion = strpos(
    $woocommerceScopedDeletionHarness,
    'promote target --scope-contract="$contract" --with-deletes --format=json >"$success_stdout"'
);
$retryDeletionReceipt = strpos(
    $woocommerceScopedDeletionHarness,
    '.scoped_apply.verification.selected_deletions == 1'
);
woo_ok($failurePromotion !== false
    && $failureCommitFrontier !== false
    && $failureRollbackReceipt !== false
    && $retryPromotion !== false
    && $retryDeletionReceipt !== false
    && $failurePromotion < $failureCommitFrontier
    && $failureCommitFrontier < $failureRollbackReceipt
    && $failureRollbackReceipt < $retryPromotion
    && $retryPromotion < $retryDeletionReceipt
    && substr_count($woocommerceScopedDeletionHarness, 'printf "6\\n"') === 1,
    'public SSH deletion fails once at the calibrated final pre-COMMIT verify, proves signed rollback, then retries once to a terminal deletion receipt');
woo_ok(
    str_contains($woocommerceMatrixHarness, 'woocommerce_deletion_owner_agreements()')
        && str_contains($woocommerceMatrixHarness, '"format": "wprism-deletion-owner-agreements/v2"')
        && str_contains($woocommerceMatrixHarness,
            '{"selector": "post:product", "owners": $deletion_owner_agreements}')
        && str_contains($woocommerceMatrixHarness,
            '{"selector": "post:product_variation", "owners": $deletion_owner_agreements}')
        && str_contains($woocommerceMatrixHarness, '"format" => "wprism-executable-tree/v1"')
        && str_contains($woocommerceMatrixHarness, '"owner" => "plugin:" . $plugin')
        && str_contains($woocommerceMatrixHarness, '"root" => $canonicalRoot')
        && !str_contains($woocommerceMatrixHarness, '"theme:twentytwentyfive"'),
    'matrix writes duplicate-resistant v2 exact observed plugin and theme tree identities instead of a permissive filename agreement'
);
woo_ok(str_contains($woocommerceMatrixHarness, 'version_matrix_preflight()')
    && str_contains($woocommerceMatrixHarness, '$VMATRIX_MANIFEST version-matrix evidence requires WPRISM_EXPECTED_SOURCE_SHA')
    && str_contains($woocommerceMatrixHarness, 'export WPRISM_SOURCE_ROOT="$(cd .. && pwd -P)"'),
    'the WooCommerce artifact matrix mounts its invoking candidate worktree before pair reset');

echo "PASS: WooCommerce 11.0.x option/table inventory and rebuild contract are explicit\n";
