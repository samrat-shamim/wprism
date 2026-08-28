<?php
/** Offline adversarial product-path regression for Woo hierarchy repair. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
duo_test_define_agent_versions();
$root = dirname(__DIR__, 4);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/adapter-packages/woocommerce/fixtures/woocommerce_mixed_option_hooks.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/support/wp_cli_child_process_fake.php';
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Kernel/PlainData.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Adapter/ProviderSdk.php';
require_once $root . '/agent/src/Adapter/Providers.php';
require_once $root . '/agent/src/Rebuild/NativeActions.php';
require_once $root . '/tools/src/ArtifactLibrary.php';

use Duo\Policy;
use DuoTest\FakeWpdb;
use DuoTest\WpCliChildRuntime;
use DuoTest\WpStore;

$coinstallTopology = json_decode(
    (string) file_get_contents(
        $root . '/integration-scenarios/woocommerce-rewrite-coinstall/fixtures/'
        . 'woocommerce-rewrite-coinstall-topology.json'
    ),
    true,
    flags: JSON_THROW_ON_ERROR
);
$artifactLock = \Duo\Tooling\ArtifactLibrary::load($root);
$settingsInventory = json_decode(
    (string) file_get_contents($root . '/adapter-packages/woocommerce/fixtures/woocommerce-core-11.0-settings.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
duo_check_same('duo-woocommerce-rewrite-coinstall-topology/v1', $coinstallTopology['format'] ?? null,
    'the mixed rewrite fixture has the reviewed source-topology format');
foreach ((array) ($coinstallTopology['artifacts'] ?? []) as $slug => $artifact) {
    duo_check_same(
        $artifact['sha256'] ?? null,
        $artifactLock['plugins'][$slug][$artifact['version'] ?? '']['sha256'] ?? null,
        "mixed rewrite topology pins the exact $slug artifact that supplies its hooks"
    );
}
$yoastArtifactMatrix = (array) ($coinstallTopology['artifacts']['wordpress-seo']['versions'] ?? []);
duo_check_same([
    '28.0' => '348ac1e90fc5a1e50b716757728e2d6300918b3c8a0795d84e264f23cbf3776f',
    '28.2' => 'f464e509d5f642023dc0a47082b3cdfed6b1fd5d5e4bf6584d6d43e0b53e8e23',
    '28.3' => '381edc1603147bd76af81341f21c9155ff3e9f6ce29ed20886d889fb9d6744fb',
], $yoastArtifactMatrix, 'mixed rewrite topology binds every admitted Yoast 28.x artifact ZIP');
foreach ($yoastArtifactMatrix as $version => $sha256) {
    duo_check_same(
        $sha256,
        $artifactLock['plugins']['wordpress-seo'][$version]['sha256'] ?? null,
        "mixed rewrite topology pins the exact Yoast $version artifact lock"
    );
}
$yoastMainRows = array_values(array_filter(
    (array) ($coinstallTopology['source_files'] ?? []),
    static fn(array $source): bool => ($source['plugin'] ?? null) === 'wordpress-seo'
        && ($source['path'] ?? null) === 'wp-seo-main.php'
));
duo_check_same([
    '28.0' => 'c1eabcbc2c5e8243d7ee9c0a787330355492701603e1869c49eb78a9b51d3a0b',
    '28.2' => '9fdfe9f87a5c11c4d45673d121c81db9117d138357d297d7d2d3a4be5b387117',
    '28.3' => '5ecb2632b7997782e7efda714ab11e4a1ca479a8f3277c8e3137600bcb575ff1',
], $yoastMainRows[0]['versions'] ?? null,
    'mixed rewrite topology binds each version-specific Yoast main source hash');
duo_check_same([
    ['plugin' => 'woocommerce', 'path' => 'includes/wc-core-functions.php', 'sha256' => '17bf218326de339c872eba8c9f855b73bb1c7874053c36774c35ef222927e684'],
    ['plugin' => 'woocommerce', 'path' => 'includes/wc-formatting-functions.php', 'sha256' => 'c3576416420bbfb6893ad5164ccf8c439b7e731c337c04b32e058ac6a0809d41'],
    ['plugin' => 'woocommerce', 'path' => 'includes/class-woocommerce.php', 'sha256' => '2f3a95ae78217be16fa1f272c1fad4d3faecfd02939041a861d65826bb3f4cb7'],
    ['plugin' => 'woocommerce', 'path' => 'src/Container.php', 'sha256' => '05893eda7dcffa910185fdabe7a0dd5ac783b8169db0fec463f4b69ab4a4d312'],
    ['plugin' => 'woocommerce', 'path' => 'src/Internal/DependencyManagement/RuntimeContainer.php', 'sha256' => 'e3e84d93da8994fe01875ca1322559c5817b4af5a1b8b841bc2a104e68e6c62f'],
    ['plugin' => 'woocommerce', 'path' => 'src/Internal/Features/FeaturesController.php', 'sha256' => 'c39f44ebd0928be1c3f3a5066422defa5623705dc44f440f4572595def5866b2'],
    ['plugin' => 'woocommerce', 'path' => 'src/Internal/DataStores/Orders/DataSynchronizer.php', 'sha256' => 'a10ff8e2e5820deeb5a032cccfc2ffca09a5134e3e87e388e0262a89a8805234'],
    ['plugin' => 'woocommerce', 'path' => 'src/Internal/DataStores/Orders/CustomOrdersTableController.php', 'sha256' => 'b4d1a6772b064de9be6a80750074b0a9e371514f58131a1701cad6cd52ccb8bf'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/class-yoast-dynamic-rewrites.php', 'sha256' => '3b07ec0af1f94269b2a5a98bba078edbee73e1697aeeed119ae12ff4a3ca7553'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/class-rewrite.php', 'sha256' => 'd8e168e467b06e6c49f1f1c60b2c5437d7eb9081ef96aa472ed1de880639dbda'],
    ['plugin' => 'wordpress-seo', 'path' => 'wp-seo-main.php', 'sha256' => '5ecb2632b7997782e7efda714ab11e4a1ca479a8f3277c8e3137600bcb575ff1', 'versions' => [
        '28.0' => 'c1eabcbc2c5e8243d7ee9c0a787330355492701603e1869c49eb78a9b51d3a0b',
        '28.2' => '9fdfe9f87a5c11c4d45673d121c81db9117d138357d297d7d2d3a4be5b387117',
        '28.3' => '5ecb2632b7997782e7efda714ab11e4a1ca479a8f3277c8e3137600bcb575ff1',
    ]],
    ['plugin' => 'wordpress-seo', 'path' => 'admin/class-admin.php', 'sha256' => '6b18d8e8aab6089b1d425259343f3f0a784c648fbf42ad95f908b67c050a7883'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/sitemaps/class-sitemaps-admin.php', 'sha256' => '03b1fdcb3da0fd6d82edc2d6d9e24f9ede8744d6fb68c3f8877b0d03c433bf82'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-options.php', 'sha256' => 'dfa12977fe7d8e44a46e55106dbd6beff2f62ded44bb72130eb40092c8aa3c93'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-option.php', 'sha256' => '9be7b8c73ec223dc2349b5976a51c3fcf66d21d12ddd8985742c4ddaaf4057e9'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-option-wpseo.php', 'sha256' => '39b7002ff87b9b3e44d72c06ddaba43ef9d02539a7a6f74781d8723641cf6f34'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-option-titles.php', 'sha256' => 'd3ab1e747666f0b8219a4531ad34c262c8c849ed178119f500eeba6fe9774c4f'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-option-social.php', 'sha256' => 'c59cb35218f7868b99f03efb027d23ed4b004cbe31473733281d17f9a158292e'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-taxonomy-meta.php', 'sha256' => 'b1c7b6e96c0c7d248028ec596ab24877b984913f563dbd4f11196c4ff73ea1f8'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-option-llmstxt.php', 'sha256' => '3626daec1fd21fbf4128a9891f208d9402cce198b3703641221b6973f23786a7'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/options/class-wpseo-option-tracking-only.php', 'sha256' => '24902a45b2912e0f2d8cd731bc1e0990c824c61f35bb5076a7a4b091a8949c8c'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/sitemaps/class-sitemaps-cache.php', 'sha256' => 'dc99816988fef1554775757fb8ab18b65ec2d46a08f03c475bf6da4dfdc72cc8'],
    ['plugin' => 'wordpress-seo', 'path' => 'inc/sitemaps/class-sitemaps.php', 'sha256' => 'e436a8c3702e6c8c954d8bb3b4dd099124c47a4d6007c87f6693a79a7759a885'],
    ['plugin' => 'wordpress-seo', 'path' => 'src/integrations/third-party/woocommerce-permalinks.php', 'sha256' => '8913e5e888d96cd4d9797d055cb862381cf4dfe6d2ddd4f1b8c6225c7aaa85a5'],
    ['plugin' => 'wordpress-seo', 'path' => 'src/generated/container.php', 'sha256' => 'f41aad93f9c02c150763720d07cfe03fd697805149aa671d628714ef9cde84b4'],
    ['plugin' => 'wordpress-seo', 'path' => 'lib/dependency-injection/container-registry.php', 'sha256' => '36fdda743db041f6dae37e51b70456c52c661dceb8b411fa0e3c2d2f5e92349a'],
    ['plugin' => 'wordpress-seo', 'path' => 'vendor_prefixed/symfony/dependency-injection/Container.php', 'sha256' => '4fc50ac8b32a60246f11173ebe11e9c947152cafea3359f4846da3fa0c407e38'],
    ['plugin' => 'wordpress-seo', 'path' => 'src/helpers/indexable-helper.php', 'sha256' => 'b462c43e61fcb755f8357e711d80a0aa2c267ce593d361885e686aa26f9cfe8e'],
    ['plugin' => 'polylang', 'path' => 'src/admin/admin.php', 'sha256' => '7ed2774c6c73c514c64fc1a4b6533e41bacc8278a54785e8246492ce597bfdc5'],
    ['plugin' => 'polylang', 'path' => 'src/modules/sitemaps/sitemaps.php', 'sha256' => '364cf0f52c51aeba8702e5108e2ddc66c35dc7b93bb4f694a3c35f862ed25856'],
    ['plugin' => 'polylang', 'path' => 'src/modules/sitemaps/load.php', 'sha256' => 'f8f29cc916bd931ad1d2e886fff55beabba537ffcf21af055dddf4b918b24e8e'],
    ['plugin' => 'polylang', 'path' => 'src/links-directory.php', 'sha256' => '5cadce6a89e87278bdd021d8f049d9c4e511acecc6c6366808740f04027d2dc0'],
    ['plugin' => 'polylang', 'path' => 'src/links-permalinks.php', 'sha256' => 'cc15a8ffa92ffb045cd5c5ef350688c7b2e36c6b43ceb9c68bdf6f8c5ed68f98'],
    ['plugin' => 'polylang', 'path' => 'src/base.php', 'sha256' => '23c6fad9a329966eb841ac86f468f347c4bf9cc4bb382e2f9a777c1b2f450762'],
    ['plugin' => 'polylang', 'path' => 'src/api.php', 'sha256' => '4ff84b4c80783cefaa497009812b492d816ad6be8f5f5c79613f18906a462793'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/src/Tribe/Cache_Listener.php', 'sha256' => '14a63e60db2f047b7dd62fa708d464b170d87485227cc63583c989a45fcb248b'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/src/Tribe/Container.php', 'sha256' => '9596db7968d4d0b5506dc482f99fed2387d1b2cbc7f11f507d52fbdf902d4b03'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/src/Tribe/Settings_Manager.php', 'sha256' => '9f51c59cfa2398a66958159db4a66368aa728f4428295dc07dabb834396f9591'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Aggregator.php', 'sha256' => 'cdd66b28165dda1f45fa79ddb6aeddf22dd3b3bc44fa2a71365e6f39c7173288'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Views/V2/Hooks.php', 'sha256' => 'd746a05d4e7979a0bbdae0938f009d0012e550d605c4a331a1cd288e7b746b5f'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Views/V2/Rewrite.php', 'sha256' => '10ad020cac5de505fe0874135a3783422b1e1f88223d4bc65156a4877b5f2377'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Views/V2/Kitchen_Sink.php', 'sha256' => '9f26d8aed55135352eb89107b5851517a6955b761831db264275e325505e578f'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Views/V2/Service_Provider.php', 'sha256' => '29e613ac58ae57ece7206f9db697749c41a5370d491088f5833a45d8f0f593b1'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/src/Common/Integrations/Harbor/PUE.php', 'sha256' => 'abe0ef81332c52aff2983b8f78700169dbcfbeb663497af84e681be245629988'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/vendor/vendor-prefixed/lucatume/di52/src/Container.php', 'sha256' => '6ca7656082adf59d784f30656e85930d20c787222b852d488af3ee654e69b488'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/vendor/vendor-prefixed/lucatume/di52/src/ServiceProvider.php', 'sha256' => 'b8a361b68b3d426d406e9c92e46467d6a8a7969e8dc6306fb0ed263f7cea7f84'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/vendor/vendor-prefixed/lucatume/di52/src/Builders/Resolver.php', 'sha256' => '20c08ab15bc604a99c4d7fe1c91043641002698ff65593605cb933dbcff432fb'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/vendor/vendor-prefixed/lucatume/di52/src/Builders/ValueBuilder.php', 'sha256' => 'b88c1b92cae835c69e324e9bfce2ba4dce779a7ac7b3f6ed8ca384b53412aadc'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/src/Tribe/Rewrite.php', 'sha256' => '0e198faca151aeca66680e916a038eab5c264f7d0ee6472d8f07d1845d0a7b9a'],
    ['plugin' => 'the-events-calendar', 'path' => 'common/src/Tribe/Deprecation.php', 'sha256' => '71050d6b3644f5570df03b4f9c1584c4d8ba08775e958f9fb8bb62f0a3bf8d2b'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Rewrite.php', 'sha256' => '2f447a4120a349d5f596c834192b17a5b911c6c94e8a62cfaee58af89cc86aab'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Views/V2/Manager.php', 'sha256' => 'c7138bf36ebd78bf2c749ed6b6548255064710559e31a9716f4fc8af86dde353'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Views/V2/View_Register.php', 'sha256' => '1a6d490cb4627fb282fd8fb1c9312c06308af87c3fa99be268b50db53cf3ac9f'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Events/QR/Routes.php', 'sha256' => '13970bae6bc23da3db24a44c14194568c7baf25f46b6b62166972c63b3e89acf'],
    ['plugin' => 'the-events-calendar', 'path' => 'src/Tribe/Main.php', 'sha256' => '3f7b3c50960071a350077ee1c72bd342ebe4613c374913522361371ca30aaa94'],
], $coinstallTopology['source_files'] ?? null,
    'mixed rewrite topology binds each installed extension callback to its exact audited source bytes');
duo_check_same([
    'wpseo' => 'WPSEO_Option_Wpseo',
    'wpseo_titles' => 'WPSEO_Option_Titles',
    'wpseo_social' => 'WPSEO_Option_Social',
    'wpseo_taxonomy_meta' => 'WPSEO_Taxonomy_Meta',
    'wpseo_llmstxt' => 'WPSEO_Option_Llmstxt',
    'wpseo_tracking_only' => 'WPSEO_Option_Tracking_Only',
], $coinstallTopology['yoast_normal_option_topology']['option_cache_map'] ?? null,
    'normal Yoast option cache map closes every registered option singleton');
duo_check_same([
    'global' => 'wpseo_sitemaps',
    'class' => 'WPSEO_Sitemaps',
    'cache_property' => 'cache',
    'cache_class' => 'WPSEO_Sitemaps_Cache',
    'cache_callback' => [
        'hook' => 'update_option',
        'method' => 'clear_on_option_update',
        'priority' => 10,
        'accepted_args' => 1,
    ],
], $coinstallTopology['yoast_normal_option_topology']['sitemap'] ?? null,
    'normal Yoast sitemap global, cache object, and cache invalidation semantics stay exact');
$wooVersion = (string) ($coinstallTopology['artifacts']['woocommerce']['version'] ?? '');
$wooSourceAuthority = (array) ($settingsInventory['source_files'] ?? []);
foreach ((array) ($settingsInventory['version_specific_source_files'] ?? []) as $path => $versions) {
    if (is_array($versions) && array_key_exists($wooVersion, $versions)) {
        $wooSourceAuthority[$path] = $versions[$wooVersion];
    }
}
$unboundWooSources = [];
foreach ((array) ($coinstallTopology['source_files'] ?? []) as $source) {
    if (($source['plugin'] ?? null) !== 'woocommerce') {
        continue;
    }
    $path = $source['path'] ?? null;
    if (!is_string($path) || ($wooSourceAuthority[$path] ?? null) !== ($source['sha256'] ?? null)) {
        $unboundWooSources[] = $path;
    }
}
duo_check_same([], $unboundWooSources,
    'every Woo rewrite callback source is bound to the exact source inventory for the installed 11.0.1 artifact');
duo_check_same([
    ['hook' => 'rewrite_rules_array', 'callback' => 'wc_fix_rewrite_rules', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'category_rewrite_rules', 'callback' => 'WPSEO_Rewrite::category_rewrite_rules_wrapper', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'updated_option', 'callback' => 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::process_updated_option', 'priority' => 999, 'accepted_args' => 3],
    ['hook' => 'added_option', 'callback' => 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::process_added_option', 'priority' => 999, 'accepted_args' => 3],
    ['hook' => 'updated_option', 'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer::process_updated_option', 'priority' => 999, 'accepted_args' => 3],
    ['hook' => 'added_option', 'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer::process_added_option', 'priority' => 999, 'accepted_args' => 2],
    ['hook' => 'updated_option', 'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_updated_option', 'priority' => 999, 'accepted_args' => 3],
    ['hook' => 'updated_option', 'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_updated_option_fts_index', 'priority' => 999, 'accepted_args' => 3],
    ['hook' => 'pre_update_option', 'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_pre_update_option', 'priority' => 999, 'accepted_args' => 3],
    ['hook' => 'pre_update_option', 'callback' => 'WPSEO_Option_Wpseo::add_default_filters_if_not_changed', 'priority' => PHP_INT_MAX, 'accepted_args' => 3],
    ['hook' => 'pre_update_option', 'callback' => 'WPSEO_Option_Titles::add_default_filters_if_not_changed', 'priority' => PHP_INT_MAX, 'accepted_args' => 3],
    ['hook' => 'pre_update_option', 'callback' => 'WPSEO_Option_Social::add_default_filters_if_not_changed', 'priority' => PHP_INT_MAX, 'accepted_args' => 3],
    ['hook' => 'pre_update_option', 'callback' => 'WPSEO_Taxonomy_Meta::add_default_filters_if_not_changed', 'priority' => PHP_INT_MAX, 'accepted_args' => 3],
    ['hook' => 'pre_update_option', 'callback' => 'WPSEO_Option_Llmstxt::add_default_filters_if_not_changed', 'priority' => PHP_INT_MAX, 'accepted_args' => 3],
    ['hook' => 'pre_update_option', 'callback' => 'WPSEO_Option_Tracking_Only::add_default_filters_if_not_changed', 'priority' => PHP_INT_MAX, 'accepted_args' => 3],
    ['hook' => 'update_option', 'callback' => 'WPSEO_Option_Wpseo::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'update_option', 'callback' => 'WPSEO_Option_Titles::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'update_option', 'callback' => 'WPSEO_Option_Social::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'update_option', 'callback' => 'WPSEO_Taxonomy_Meta::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'update_option', 'callback' => 'WPSEO_Option_Llmstxt::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'update_option', 'callback' => 'WPSEO_Option_Tracking_Only::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'update_option', 'callback' => 'WPSEO_Sitemaps_Cache::clear_on_option_update', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'add_option', 'callback' => 'WPSEO_Option_Wpseo::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'add_option', 'callback' => 'WPSEO_Option_Titles::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'add_option', 'callback' => 'WPSEO_Option_Social::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'add_option', 'callback' => 'WPSEO_Taxonomy_Meta::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'add_option', 'callback' => 'WPSEO_Option_Llmstxt::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'add_option', 'callback' => 'WPSEO_Option_Tracking_Only::add_default_filters_if_same_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'option_rewrite_rules', 'callback' => 'Yoast_Dynamic_Rewrites::filter_rewrite_rules_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'sanitize_option_rewrite_rules', 'callback' => 'Yoast_Dynamic_Rewrites::sanitize_rewrite_rules_option', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'generate_rewrite_rules', 'callback' => 'Tribe__Cache_Listener::generate_rewrite_rules', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'updated_option', 'callback' => 'Tribe__Settings_Manager::update_options_cache', 'priority' => 10, 'accepted_args' => 3],
    ['hook' => 'updated_option', 'callback' => 'Tribe__Cache_Listener::update_last_updated_option', 'priority' => 10, 'accepted_args' => 3],
    ['hook' => 'updated_option', 'callback' => 'Tribe__Cache_Listener::update_last_save_post', 'priority' => 10, 'accepted_args' => 3],
    ['hook' => 'updated_option', 'callback' => 'Tribe__Events__Aggregator::action_purge_transients', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'updated_option', 'callback' => 'Tribe\\Events\\Views\\V2\\Hooks::action_save_wplang', 'priority' => 10, 'accepted_args' => 3],
    ['hook' => 'tribe_events_rewrite_i18n_slugs_raw', 'callback' => 'Tribe\\Events\\Views\\V2\\Hooks::filter_rewrite_i18n_slugs_raw', 'priority' => 50, 'accepted_args' => 2],
    ['hook' => 'generate_rewrite_rules', 'callback' => 'Tribe__Events__Rewrite::filter_generate', 'priority' => 10, 'accepted_args' => 1],
    ['hook' => 'rewrite_rules_array', 'callback' => 'Tribe__Events__Rewrite::filter_rewrite_rules_array', 'priority' => 25, 'accepted_args' => 1],
], $coinstallTopology['static_callbacks'] ?? null,
    'mixed rewrite topology closes every static Woo, Yoast, and TEC callback with priority and accepted-argument identity');
duo_check_same([
    'updated_option' => [
        ['callback' => 'Tribe__Settings_Manager::update_options_cache', 'priority' => 10, 'accepted_args' => 3],
        ['callback' => 'Tribe__Cache_Listener::update_last_updated_option', 'priority' => 10, 'accepted_args' => 3],
        ['callback' => 'Tribe__Cache_Listener::update_last_save_post', 'priority' => 10, 'accepted_args' => 3],
        ['callback' => 'Tribe__Events__Aggregator::action_purge_transients', 'priority' => 10, 'accepted_args' => 1],
        ['callback' => 'Tribe\\Events\\Views\\V2\\Hooks::action_save_wplang', 'priority' => 10, 'accepted_args' => 3],
    ],
    'pre_option' => [
        'optional_callback' => 'TEC\\Common\\Integrations\\Harbor\\PUE::filter_pre_get_option',
        'priority' => 10,
        'accepted_args' => 3,
    ],
    'wp_default_autoload_value' => [
        'callback' => 'wp_filter_default_autoload_value_via_option_size',
        'priority' => 5,
        'accepted_args' => 4,
    ],
], $coinstallTopology['marker_option_topology'] ?? null,
    'the exact TEC/core marker option callback union stays closed; arbitrary updated_option or option-hook callbacks are never admitted');
duo_check_same([
    'updated_option' => [
        ['callback' => 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::process_updated_option', 'priority' => 999, 'accepted_args' => 3],
        ['callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer::process_updated_option', 'priority' => 999, 'accepted_args' => 3],
        ['callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_updated_option', 'priority' => 999, 'accepted_args' => 3],
        ['callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_updated_option_fts_index', 'priority' => 999, 'accepted_args' => 3],
    ],
    'pre_update_option' => [
        ['callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_pre_update_option', 'priority' => 999, 'accepted_args' => 3],
    ],
    'added_option' => [
        ['callback' => 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::process_added_option', 'priority' => 999, 'accepted_args' => 3],
        ['callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer::process_added_option', 'priority' => 999, 'accepted_args' => 2],
    ],
], $coinstallTopology['woocommerce_normal_option_topology'] ?? null,
    'the normal Woo boot option callback union is source-bound and is distinct from unsupported request-conditional tracking callbacks');
duo_check_same([
    'rewrite_rules',
    'tribe_last_generate_rewrite_rules',
    'tribe_last_save_post',
    'tribe_last_updated_option',
], $coinstallTopology['durable_effects'] ?? null,
    'mixed rewrite topology binds the exact durable Core and TEC mutation set');
duo_check_same([
    [
        'hook' => 'rewrite_rules_array',
        'callback' => 'PLL_Links_Directory::rewrite_rules',
        'priority' => 10,
        'accepted_args' => 1,
        'activation' => 'at least one Polylang language, wp_loaded, and directory permalink links',
    ],
    [
        'hook' => 'rewrite_rules_array',
        'callback' => 'PLL_Sitemaps::rewrite_rules',
        'priority' => 10,
        'accepted_args' => 1,
        'activation' => 'at least one Polylang language and the normal sitemap loader initializes the runtime-owned sitemap service',
    ],
    [
        'hook' => '{type}_rewrite_rules',
        'callback' => 'PLL_Links_Directory::rewrite_rules',
        'priority' => 10,
        'accepted_args' => 1,
        'activation' => 'each name returned by the filtered pll_rewrite_rules type set',
    ],
    [
        'hook' => 'pll_modify_rewrite_rule',
        'callback' => 'third-party filter chain',
        'priority' => 'open',
        'accepted_args' => 4,
        'arguments' => ['bool', 'array<string,string>', 'string', 'string|false'],
    ],
], $coinstallTopology['dynamic_callback_containers'] ?? null,
    'Polylang dynamic rewrite types and its open four-argument third-party filter are source-bound, never hand-whitelisted');

final class WooHierarchyWakeupCanary {
    public static int $wakeups = 0;

    public function __wakeup(): void {
        self::$wakeups++;
    }
}

if (!class_exists('WP_CLI')) {
    final class WP_CLI {
        use WpCliChildRuntime;

        public static string $mode = 'success';
        /** @var null|callable(string,array):object */
        public static $handler = null;
        /** @var list<array{command:string,options:array}> */
        public static array $calls = [];

        public static function runcommand(string $command, array $options): object {
            self::$calls[] = ['command' => $command, 'options' => $options];
            if (self::$mode === 'throw') {
                throw new RuntimeException('secret=child-launch-detail');
            }
            if (self::$mode === 'exit') {
                return (object) [
                    'return_code' => 23,
                    'stdout' => 'secret=child-stdout',
                    'stderr' => 'secret=child-stderr',
                ];
            }
            if (!is_callable(self::$handler)) {
                throw new RuntimeException('test handler missing');
            }
            $result = (self::$handler)($command, $options);
            if (self::$mode === 'stderr') {
                $result->stderr = "warning: secret=stderr-marker\n";
            } elseif (self::$mode === 'trailing') {
                $result->stdout .= "\nwarning: secret=trailing-marker";
            } elseif (self::$mode === 'mismatch') {
                $decoded = json_decode((string) $result->stdout, true, flags: JSON_THROW_ON_ERROR);
                $field = ($decoded['format'] ?? null) === 'duo-woocommerce-permalink-child/v1'
                    ? 'product_permalink_sha256'
                    : 'category_lookup_sha256';
                $decoded['after'][$field] = str_repeat('0', 64);
                $result->stdout = json_encode(
                    $decoded,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            } elseif (self::$mode === 'stdout-overflow') {
                // The provider caps its canonical receipt at 16 KiB. Keep
                // credential-shaped bytes in this fault injection so the
                // product boundary proves the transport never reflects them.
                $result->stdout = str_repeat('secret=stdout-boundary;', 1024);
            } elseif (self::$mode === 'stderr-overflow') {
                $result->stderr = str_repeat('secret=stderr-boundary;', 1024);
            }
            return $result;
        }
    }
}

/** Exact TEC listener shape admitted by the product-route child boundary. */
if (!class_exists('Tribe__Cache_Listener')) {
    final class Tribe__Cache_Listener {
        private static ?self $instance = null;
        /** @var list<string> */
        public array $writes = [];

        public static function install(): self {
            return self::$instance ??= new self();
        }

        public function generate_rewrite_rules(): void {
            $this->mark('tribe_last_generate_rewrite_rules');
        }

        public function update_last_updated_option(string $option, mixed $old, mixed $new): void {
            if ($option === 'rewrite_rules') {
                $this->mark('tribe_last_updated_option');
            }
        }

        public function update_last_save_post(string $option, mixed $old, mixed $new): void {
            if ($option === 'rewrite_rules') {
                $this->mark('tribe_last_save_post');
            }
        }

        private function mark(string $option): void {
            $this->writes[] = $option;
            $GLOBALS['wooHierarchyTecPurgeRequested'] = true;
            if (function_exists('woo_hierarchy_test_set_option')) {
                woo_hierarchy_test_set_option($option, (float) count($this->writes));
            }
        }
    }
}

/** Minimal exact-shaped extension doubles used only to prove delegation. */
if (!class_exists('Yoast_Dynamic_Rewrites')) {
final class Yoast_Dynamic_Rewrites {
    public function sanitize_rewrite_rules_option(mixed $rules): mixed {
        if (!is_array($rules)) {
            return $rules;
        }
        unset($rules['^yoast-sitemap\\.xml$']);
        return $rules;
    }

    public function filter_rewrite_rules_option(mixed $rules): mixed {
        if (!is_array($rules)) {
            return $rules;
        }
        $rules['^yoast-sitemap\\.xml$'] = 'index.php?yoast-sitemap=1';
        return $rules;
    }
}
}

if (!class_exists('PLL_Links_Directory')) {
final class PLL_Links_Directory {
    public int $dynamicTypeCalls = 0;

    public function rewrite_rules(mixed $rules): mixed {
        if (!is_array($rules)) {
            return $rules;
        }
        ++$this->dynamicTypeCalls;
        $pattern = '^fr/produit/(.+?)/?$';
        $query = 'index.php?product=$matches[1]&lang=fr';
        $allow = woo_hierarchy_test_apply_native_filter(
            'pll_modify_rewrite_rule',
            true,
            [$pattern => $query],
            'rewrite_rules_array',
            false
        );
        if (!is_bool($allow)) {
            throw new RuntimeException('Polylang rewrite-rule filter returned an invalid wire value');
        }
        if ($allow) {
            $rules[$pattern] = $query;
        }
        return $rules;
    }
}
}

if (!class_exists('Tribe__Events__Rewrite')) {
final class Tribe__Events__Rewrite {
    private static ?self $instance = null;
    public int $generationCalls = 0;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function filter_generate(object $rewrite): void {
        ++$this->generationCalls;
    }

    public function filter_rewrite_rules_array(mixed $rules): mixed {
        if (!is_array($rules)) {
            return $rules;
        }
        $rules['^events/(.+?)/?$'] = 'index.php?post_type=tribe_events&name=$matches[1]';
        return $rules;
    }
}
}

if (!function_exists('wc_sanitize_permalink')) {
    $GLOBALS['wooHierarchyPermalinkSanitizerCalls'] = 0;
    function wc_sanitize_permalink(mixed $value): string {
        ++$GLOBALS['wooHierarchyPermalinkSanitizerCalls'];
        $value = trim((string) $value);
        $value = str_replace('http://', '', $value);
        return untrailingslashit($value);
    }
}

if (!function_exists('wc_fix_rewrite_rules')) {
    function wc_fix_rewrite_rules(array $rules): array {
        return $rules;
    }
}

if (!function_exists('wc_get_permalink_structure')) {
    /** @return array<string,string|bool> */
    function wc_get_permalink_structure(): array {
        ++$GLOBALS['wooHierarchyPermalinkNativeReads'];
        $saved = (array) get_option('woocommerce_permalinks', []);
        $permalinks = array_merge(
            [
                'product_base' => 'product',
                'category_base' => 'product-category',
                'tag_base' => 'product-tag',
                'attribute_base' => '',
                'use_verbose_page_rules' => false,
            ],
            array_filter($saved)
        );
        if ($saved !== $permalinks) {
            woo_hierarchy_test_set_option('woocommerce_permalinks', $permalinks);
        }
        if (($GLOBALS['wooHierarchyPermalinkReadMode'] ?? 'stable') === 'mutate-storage') {
            $permalinks['category_base'] = 'raced-category';
            woo_hierarchy_test_set_option('woocommerce_permalinks', $permalinks);
        }
        $permalinks['product_rewrite_slug'] = untrailingslashit((string) $permalinks['product_base']);
        $permalinks['category_rewrite_slug'] = untrailingslashit((string) $permalinks['category_base']);
        $permalinks['tag_rewrite_slug'] = untrailingslashit((string) $permalinks['tag_base']);
        $permalinks['attribute_rewrite_slug'] = untrailingslashit((string) $permalinks['attribute_base']);
        if (($GLOBALS['wooHierarchyPermalinkReadMode'] ?? 'stable') === 'projection-drift') {
            $permalinks['product_rewrite_slug'] = 'wrong-native-product-route';
        }
        return $permalinks;
    }
}

require_once $root . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-hierarchy-lookups.php';

/** @return array<int,int> */
function woo_hierarchy_test_parent_map(string $taxonomy): array {
    global $wpdb;
    $map = [];
    foreach ($wpdb->rows($wpdb->term_taxonomy) as $row) {
        if (($row['taxonomy'] ?? null) === $taxonomy) {
            $map[(int) $row['term_id']] = (int) $row['parent'];
        }
    }
    ksort($map, SORT_NUMERIC);
    return $map;
}

/** @param array<int,int> $map @return array<int,list<int>> */
function woo_hierarchy_test_children(array $map): array {
    $children = [];
    foreach ($map as $id => $parent) {
        if ($parent > 0) {
            $children[$parent][] = $id;
        }
    }
    ksort($children, SORT_NUMERIC);
    foreach ($children as &$ids) {
        sort($ids, SORT_NUMERIC);
    }
    unset($ids);
    return $children;
}

/** @param array<int,int> $map @return list<array{category_tree_id:int,category_id:int}> */
function woo_hierarchy_test_category_rows(array $map): array {
    $rows = [];
    foreach ($map as $id => $_parent) {
        for ($cursor = $id; $cursor > 0; $cursor = $map[$cursor] ?? 0) {
            $rows[] = ['category_tree_id' => $cursor, 'category_id' => $id];
        }
    }
    usort($rows, static fn(array $left, array $right): int => [
        $left['category_tree_id'], $left['category_id'],
    ] <=> [
        $right['category_tree_id'], $right['category_id'],
    ]);
    return $rows;
}

function woo_hierarchy_test_set_option(string $name, mixed $value): void {
    global $wpdb;
    $store = WpStore::instance();
    $store->options[$name] = $value;
    $store->autoload[$name] = 'yes';
    // WordPress maybe_serialize() leaves scalar strings unchanged and only
    // serializes structured values. Mirroring that byte boundary matters for
    // the brand permalink raw/effective identity check.
    $raw = is_array($value) || is_object($value) ? serialize($value) : (string) $value;
    $found = array_values(array_filter(
        $wpdb->rows($wpdb->options),
        static fn(array $row): bool => ($row['option_name'] ?? null) === $name
    ));
    if ($found === []) {
        $wpdb->insert($wpdb->options, [
            'option_name' => $name,
            'option_value' => $raw,
            'autoload' => 'yes',
        ]);
        return;
    }
    $wpdb->update($wpdb->options, ['option_value' => $raw], ['option_name' => $name]);
}

function woo_hierarchy_test_set_raw_option(string $name, string $raw, mixed $effective): void {
    global $wpdb;
    $store = WpStore::instance();
    $store->options[$name] = $effective;
    $store->autoload[$name] = 'yes';
    $wpdb->update($wpdb->options, ['option_value' => $raw], ['option_name' => $name]);
}

function woo_hierarchy_test_remove_option(string $name): void {
    global $wpdb;
    unset(WpStore::instance()->options[$name], WpStore::instance()->autoload[$name]);
    $wpdb->delete($wpdb->options, ['option_name' => $name]);
}

/** @param list<array{0:callable,1:int,2:int}> $callbacks */
function woo_hierarchy_test_install_native_hook(string $name, array $callbacks): void {
    $hook = new WP_Hook();
    foreach ($callbacks as $index => [$callback, $priority, $acceptedArgs]) {
        $hook->callbacks[$priority]['callback-' . $index] = [
            'function' => $callback,
            'accepted_args' => $acceptedArgs,
        ];
    }
    $GLOBALS['wp_filter'][$name] = $hook;
}

function woo_hierarchy_test_clear_native_hooks(): void {
    $GLOBALS['wp_filter'] = [];
}

function woo_hierarchy_test_fire_native_hook(string $name, mixed ...$args): void {
    $hook = $GLOBALS['wp_filter'][$name] ?? null;
    if (!$hook instanceof WP_Hook) {
        return;
    }
    $callbacks = $hook->callbacks;
    ksort($callbacks, SORT_NUMERIC);
    foreach ($callbacks as $priorityCallbacks) {
        foreach ($priorityCallbacks as $callback) {
            $function = $callback['function'] ?? null;
            $acceptedArgs = $callback['accepted_args'] ?? null;
            if (!is_callable($function) || !is_int($acceptedArgs)) {
                throw new RuntimeException('malformed native hook fixture');
            }
            $function(...array_slice($args, 0, $acceptedArgs));
        }
    }
}

function woo_hierarchy_test_apply_native_filter(string $name, mixed $value, mixed ...$args): mixed {
    $hook = $GLOBALS['wp_filter'][$name] ?? null;
    if (!$hook instanceof WP_Hook) {
        return $value;
    }
    $callbacks = $hook->callbacks;
    ksort($callbacks, SORT_NUMERIC);
    foreach ($callbacks as $priorityCallbacks) {
        foreach ($priorityCallbacks as $callback) {
            $function = $callback['function'] ?? null;
            $acceptedArgs = $callback['accepted_args'] ?? null;
            if (!is_callable($function) || !is_int($acceptedArgs)) {
                throw new RuntimeException('malformed native hook fixture');
            }
            $value = $function(...array_slice(array_merge([$value], $args), 0, $acceptedArgs));
        }
    }
    return $value;
}

/** @param list<array<string,mixed>> $rows */
function woo_hierarchy_test_seed_option_rows(array $rows): void {
    global $wpdb;
    $wpdb->seedTable('wp_options', $rows)->setColumns('wp_options', [
        'option_id' => 'bigint(20) unsigned',
        'option_name' => 'varchar(191)',
        'option_value' => 'longtext',
        'autoload' => 'varchar(20)',
    ]);
}

/** @return array<string,string> */
function woo_hierarchy_test_product_rewrite_rules(): array {
    $permalinks = (array) (WpStore::instance()->options['woocommerce_permalinks'] ?? []);
    foreach (['product_base', 'category_base', 'tag_base', 'attribute_base'] as $field) {
        if (!is_string($permalinks[$field] ?? null)) {
            throw new RuntimeException('product permalink fixture is missing a native base');
        }
    }
    $productBase = untrailingslashit($permalinks['product_base']);
    $categoryBase = untrailingslashit($permalinks['category_base']);
    $tagBase = untrailingslashit($permalinks['tag_base']);
    $attributeBase = untrailingslashit($permalinks['attribute_base']);
    $rules = [
        '^' . $productBase . '/(.+?)/?$' => 'index.php?product=$matches[1]',
        '^' . $categoryBase . '/(.+?)/?$' => 'index.php?product_cat=$matches[1]',
        '^' . $tagBase . '/(.+?)/?$' => 'index.php?product_tag=$matches[1]',
    ];
    if ($attributeBase !== '') {
        $rules['^' . $attributeBase . '/(.+?)/?$'] = 'index.php?product_attribute=$matches[1]';
    }
    return $rules;
}

/** Simulate Core's fixed fresh rewrite child, including durable/effective split. */
function woo_hierarchy_test_native_rewrite_child(): object {
    $beforeRules = WpStore::instance()->options['rewrite_rules'] ?? null;
    woo_hierarchy_test_fire_native_hook('generate_rewrite_rules', new stdClass());
    $permalinkStructure = WpStore::instance()->options['permalink_structure'] ?? '';
    if (!is_string($permalinkStructure)) {
        throw new RuntimeException('core permalink structure fixture is not a string');
    }
    $generated = $permalinkStructure === '' ? '' : woo_hierarchy_test_product_rewrite_rules();
    $generated = woo_hierarchy_test_apply_native_filter('rewrite_rules_array', $generated);
    $durable = woo_hierarchy_test_apply_native_filter('sanitize_option_rewrite_rules', $generated);
    $durable = woo_hierarchy_test_apply_native_filter(
        'pre_update_option_rewrite_rules',
        $durable,
        $beforeRules
    );
    $durable = woo_hierarchy_test_apply_native_filter(
        'pre_update_option',
        $durable,
        'rewrite_rules',
        $beforeRules
    );
    woo_hierarchy_test_fire_native_hook('update_option', 'rewrite_rules', $beforeRules, $durable);
    woo_hierarchy_test_fire_native_hook('update_option_rewrite_rules', $beforeRules, $durable);
    $effective = woo_hierarchy_test_apply_native_filter('option_rewrite_rules', $durable);
    woo_hierarchy_test_set_raw_option(
        'rewrite_rules',
        $durable === '' ? '' : serialize($durable),
        $effective
    );
    woo_hierarchy_test_fire_native_hook(
        'updated_option',
        'rewrite_rules',
        $beforeRules,
        $effective
    );
    $GLOBALS['wooHierarchyFreshRewriteRules'] = $effective;
    $structurePresent = array_key_exists('permalink_structure', WpStore::instance()->options);
    $hash = static fn(mixed $value): string => hash('sha256', serialize($value));
    $after = [
        'permalink_present' => $structurePresent,
        'permalink_hash' => hash('sha256', $permalinkStructure),
        'runtime_permalink_matches' => true,
        'rules_present' => true,
        'rules_type' => get_debug_type($durable),
        'rules_count' => is_array($durable) ? count($durable) : 0,
        'rules_hash' => $hash($durable),
        'runtime_rules_type' => get_debug_type($effective),
        'runtime_rules_count' => is_array($effective) ? count($effective) : 0,
        'runtime_rules_hash' => $hash($effective),
    ];
    return (object) [
        'return_code' => 0,
        'stdout' => json_encode(
            ['format' => 'duo-rewrite-flush-fresh/v1', 'after' => $after],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ),
        'stderr' => '',
    ];
}

/** Install the exact state the real child must create, then return its bounded receipt. */
function woo_hierarchy_test_native_child(bool $flushRewrite): object {
    global $wpdb;
    $catMap = woo_hierarchy_test_parent_map('product_cat');
    $brandMap = woo_hierarchy_test_parent_map('product_brand');
    $wpdb->query('TRUNCATE TABLE wp_wc_category_lookup');
    foreach (woo_hierarchy_test_category_rows($catMap) as $row) {
        $wpdb->insert('wp_wc_category_lookup', $row);
    }
    woo_hierarchy_test_set_option('product_cat_children', woo_hierarchy_test_children($catMap));
    woo_hierarchy_test_set_option('product_brand_children', woo_hierarchy_test_children($brandMap));

    if ($flushRewrite) {
        \Duo\NativeActions::execute('rewrite.flush', []);
        \Duo\NativeActions::rewrite_evidence();
    }

    $snapshot = new ReflectionMethod(
        \Duo\Providers\WoocommerceHierarchyLookups::class,
        'projection_snapshot'
    );
    $after = $snapshot->invoke(null, true, $flushRewrite);
    return (object) [
        'return_code' => 0,
        'stdout' => json_encode(
            ['format' => 'duo-woocommerce-hierarchy-child/v1', 'after' => $after],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ),
        'stderr' => '',
    ];
}

$store = WpStore::reset()->seedOptions([
    'product_cat_children' => [9000000000 => [9000000002], 9000000001 => [9000000002]],
    'product_brand_children' => [8000000000 => [8000000002]],
    'rewrite_rules' => ['^stale/(.+)$' => 'index.php?stale=$matches[1]'],
    'woocommerce_brand_permalink' => 'maker-houses',
    'woocommerce_permalinks' => [
        'product_base' => 'shop/%product_cat%',
        'category_base' => 'catalog',
        'tag_base' => 'labels',
        'attribute_base' => 'features',
        'use_verbose_page_rules' => true,
    ],
]);
$GLOBALS['wooHierarchyPermalinkReadMode'] = 'stable';
$GLOBALS['wooHierarchyPermalinkNativeReads'] = 0;
$GLOBALS['wp_filter'] = [];
$GLOBALS['wooHierarchyFreshRewriteRules'] = $store->options['rewrite_rules'];
$wp_rewrite = new class {
    public string|false $permalink_structure = false;
    public mixed $rules = null;

    public function wp_rewrite_rules(): mixed {
        return $this->rules;
    }
};
$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_term_taxonomy', [
    ['term_taxonomy_id' => 1, 'term_id' => 9000000000, 'taxonomy' => 'product_cat', 'parent' => 0],
    ['term_taxonomy_id' => 2, 'term_id' => 9000000001, 'taxonomy' => 'product_cat', 'parent' => 9000000000],
    ['term_taxonomy_id' => 3, 'term_id' => 9000000002, 'taxonomy' => 'product_cat', 'parent' => 9000000001],
    ['term_taxonomy_id' => 4, 'term_id' => 8000000000, 'taxonomy' => 'product_brand', 'parent' => 0],
    ['term_taxonomy_id' => 5, 'term_id' => 8000000002, 'taxonomy' => 'product_brand', 'parent' => 8000000000],
])->setColumns('wp_term_taxonomy', [
    'term_taxonomy_id' => 'bigint(20) unsigned',
    'term_id' => 'bigint(20) unsigned',
    'taxonomy' => 'varchar(32)',
    'parent' => 'bigint(20) unsigned',
]);
$wpdb->seedTable('wp_wc_category_lookup', [
    ['category_tree_id' => 9000000000, 'category_id' => 9000000000],
    ['category_tree_id' => 9000000000, 'category_id' => 9000000001],
    ['category_tree_id' => 9000000000, 'category_id' => 9000000002],
    ['category_tree_id' => 9000000001, 'category_id' => 9000000001],
    ['category_tree_id' => 9000000002, 'category_id' => 9000000001],
    ['category_tree_id' => 9000000002, 'category_id' => 9000000002],
])->setColumns('wp_wc_category_lookup', [
    'category_tree_id' => 'bigint(20) unsigned',
    'category_id' => 'bigint(20) unsigned',
])->setUniqueKey('wp_wc_category_lookup', ['category_tree_id', 'category_id']);
$wpdb->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'product_cat_children', 'option_value' => serialize($store->options['product_cat_children']), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'product_brand_children', 'option_value' => serialize($store->options['product_brand_children']), 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'rewrite_rules', 'option_value' => serialize($store->options['rewrite_rules']), 'autoload' => 'yes'],
    ['option_id' => 4, 'option_name' => 'woocommerce_brand_permalink', 'option_value' => 'maker-houses', 'autoload' => 'yes'],
    ['option_id' => 5, 'option_name' => 'woocommerce_permalinks', 'option_value' => serialize($store->options['woocommerce_permalinks']), 'autoload' => 'yes'],
])->setColumns('wp_options', [
    'option_id' => 'bigint(20) unsigned',
    'option_name' => 'varchar(191)',
    'option_value' => 'longtext',
    'autoload' => 'varchar(20)',
])->setUniqueKey('wp_options', ['option_name']);

WP_CLI::$handler = static function (string $command, array $options): object {
    $isHierarchy = str_contains($command, 'WoocommerceHierarchyLookups::run_child(');
    $isNativeRewrite = str_contains($command, 'Duo\\NativeActions::execute("rewrite.flush", [])');
    duo_check(($isHierarchy xor $isNativeRewrite),
        'the bounded process transport contains only its fixed hierarchy child or Core\'s fixed native rewrite child');
    duo_check_same(
        ['launch' => true, 'return' => 'all', 'exit_error' => false],
        $options,
        'WP-CLI child invocation is isolated and returns the complete process result'
    );
    if ($isNativeRewrite) {
        if (WP_CLI::$mode === 'product-route-race') {
            $raced = (array) WpStore::instance()->options['woocommerce_permalinks'];
            $raced['product_base'] = 'raced-products';
            woo_hierarchy_test_set_option('woocommerce_permalinks', $raced);
        } elseif (WP_CLI::$mode === 'product-raw-race') {
            global $wpdb;
            $raw = serialize(WpStore::instance()->options['woocommerce_permalinks']);
            $noncanonical = preg_replace('/^a:5:/', 'a:05:', $raw, 1);
            if (!is_string($noncanonical) || $noncanonical === $raw) {
                throw new RuntimeException('test could not create same-semantic serialized bytes');
            }
            $wpdb->update('wp_options', ['option_value' => $noncanonical], [
                'option_name' => 'woocommerce_permalinks',
            ]);
        } elseif (WP_CLI::$mode === 'product-autoload-race') {
            global $wpdb;
            WpStore::instance()->autoload['woocommerce_permalinks'] = 'no';
            $wpdb->update('wp_options', ['autoload' => 'no'], [
                'option_name' => 'woocommerce_permalinks',
            ]);
        } elseif (WP_CLI::$mode === 'product-reinsert-race') {
            $value = WpStore::instance()->options['woocommerce_permalinks'];
            woo_hierarchy_test_remove_option('woocommerce_permalinks');
            woo_hierarchy_test_set_option('woocommerce_permalinks', $value);
        } elseif (WP_CLI::$mode === 'product-brand-race') {
            woo_hierarchy_test_set_option('woocommerce_brand_permalink', 'raced-product-brand');
        } elseif (WP_CLI::$mode === 'product-core-permalink-race') {
            woo_hierarchy_test_set_option('permalink_structure', '/%year%/%postname%/');
        }
        return woo_hierarchy_test_native_rewrite_child();
    }
    $flushRewrite = str_contains($command, 'run_child(true)');
    if (WP_CLI::$mode === 'parent-race') {
        global $wpdb;
        $wpdb->update('wp_term_taxonomy', ['parent' => 9000000000], ['term_id' => 9000000002]);
    } elseif (WP_CLI::$mode === 'brand-route-race') {
        woo_hierarchy_test_set_option('woocommerce_brand_permalink', 'raced-maker-route');
    }
    return woo_hierarchy_test_native_child($flushRewrite);
};

$policy = Policy::load(
    null,
    ['woocommerce'],
    false,
    null,
    \Duo\AdapterLibrary::fromSourcePackage($root, 'woocommerce')
);
$provider = new \Duo\Providers\WoocommerceHierarchyLookups($policy);
$capabilities = $provider->capabilities();
duo_check_same([
    'id' => 'woocommerce-hierarchy-lookups',
    'plugin' => 'woocommerce/woocommerce.php',
    'version' => '2.0.0',
], $provider->identity(), 'provider identity is exact and manifest-bindable');
duo_check_same(
    ['flush_rewrite' => ['type' => 'bool', 'required' => true]],
    $capabilities['rebuild_hierarchy_lookups']['args'] ?? null,
    'brand rewrite authority is an exact required boolean, never inferred from a dirty target'
);
duo_check_same([], $capabilities['rebuild_product_permalink_routes']['args'] ?? null,
    'product permalink repair takes no target-controlled arguments');
duo_check_same(
    [
        'option:permalink_structure',
        'option:rewrite_rules',
        'option:woocommerce_brand_permalink',
        'option:woocommerce_permalinks',
        'table:options',
    ],
    $capabilities['rebuild_product_permalink_routes']['reads'] ?? null,
    'product permalink repair declares its exact authored and derived reads'
);
duo_check_same(
    ['option:rewrite_rules'],
    $capabilities['rebuild_product_permalink_routes']['writes'] ?? null,
    'product permalink repair writes only the derived rewrite option'
);

$operation = [
    'format' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
    'authority_sha256' => str_repeat('a', 64),
    'session_sha256' => str_repeat('b', 64),
    'input_sha256' => str_repeat('c', 64),
    'effects_sha256' => str_repeat('d', 64),
];

// Core persists rewrite_rules as an exact empty string when permalinks are
// disabled. The raw source record is deliberately absent at first: absence
// and an authored empty structure are separate native grammars and both must
// remain bound to the child receipt.
$productRoute = $provider->invoke_scoped('rebuild_product_permalink_routes', [], $operation);
duo_check(($productRoute['after']['permalink_structure_present'] ?? null) === false
    && ($productRoute['after']['rewrite_rules_valid'] ?? false) === true
    && ($productRoute['after']['rewrite_rules'] ?? null) === 0
    && ($productRoute['after']['rewrite_rules_raw_sha256'] ?? null) === hash('sha256', ''),
    'absent core permalink_structure binds a native plain-permalink empty-string rewrite receipt');
duo_check_same(0, $GLOBALS['wooHierarchyPermalinkNativeReads'],
    'product permalink receipt never calls hookful wc_get_permalink_structure');

woo_hierarchy_test_set_option('permalink_structure', '');
$productEmptyCore = $provider->invoke('rebuild_product_permalink_routes', []);
duo_check(($productEmptyCore['after']['permalink_structure_present'] ?? null) === true
    && ($productEmptyCore['after']['permalink_structure_option_id'] ?? 0) > 0
    && ($productEmptyCore['after']['rewrite_rules_valid'] ?? false) === true
    && ($productEmptyCore['after']['rewrite_rules'] ?? null) === 0,
    'present empty core permalink_structure remains distinct while converging to the exact plain rewrite sentinel');

woo_hierarchy_test_set_option('permalink_structure', '/%postname%/');
$productPretty = $provider->invoke('rebuild_product_permalink_routes', []);
duo_check(($productPretty['after']['permalink_structure_present'] ?? null) === true
    && ($productPretty['after']['rewrite_rules_valid'] ?? false) === true
    && ($productPretty['after']['rewrite_rules'] ?? null) === 4,
    'pretty core permalinks bind the single native child generation to ordered rewrite bytes');
$productPrettyNoOp = \Duo\Providers::invoke(
    $provider,
    [
        'provider' => 'woocommerce-hierarchy-lookups',
        'capability' => 'rebuild_product_permalink_routes',
        'args' => [],
    ],
    $capabilities['rebuild_product_permalink_routes'],
    []
);
duo_check(($productPrettyNoOp['verified'] ?? null) === true
    && ($productPrettyNoOp['before'] ?? null) === ($productPrettyNoOp['after'] ?? null)
    && array_filter(
        array_keys((array) ($productPrettyNoOp['before'] ?? [])),
        static fn(string $key): bool => str_starts_with($key, 'native_rewrite_')
    ) === [],
    'a converged product-route receipt publishes only its true pre/post durable projection, never post-action evidence as a preimage');

$nativeFirstHelperRow = [
    'product_base' => 'shop/%product_cat%',
    'category_base' => 'catalog',
    'attribute_base' => 'features',
    'tag_base' => 'labels',
    'use_verbose_page_rules' => true,
];
woo_hierarchy_test_set_option('woocommerce_permalinks', $nativeFirstHelperRow);
$alternateOrderRoute = $provider->invoke('rebuild_product_permalink_routes', []);
duo_check(($alternateOrderRoute['after']['product_permalink_valid'] ?? false) === true
    && ($alternateOrderRoute['after']['product_permalink_fields'] ?? null) === 5
    && ($alternateOrderRoute['after']['product_permalink_raw_sha256'] ?? null)
        !== ($productPretty['after']['product_permalink_raw_sha256'] ?? null),
    'native migration/helper five-field order is accepted semantically while its distinct raw bytes remain receipt-bound');
duo_check_same(0, $GLOBALS['wooHierarchyPermalinkNativeReads'],
    'alternate native permalink key order does not reach a hookful Woo option reader');

$sanitizerCallsBeforeHostile = $GLOBALS['wooHierarchyPermalinkSanitizerCalls'];
$sameValueCleanUrlCalls = 0;
woo_hierarchy_test_install_native_hook('clean_url', [[
    static function (string $value) use (&$sameValueCleanUrlCalls): string {
        ++$sameValueCleanUrlCalls;
        return $value;
    }, 10, 3,
]]);
duo_check_throws(
    static fn() => $provider->invoke('rebuild_product_permalink_routes', []),
    RuntimeException::class,
    'same-value clean_url callback is refused before Woo native permalink sanitization',
    'extension callback'
);
duo_check_same(0, $sameValueCleanUrlCalls,
    'hostile clean_url callback has zero runtime effects because topology is checked before sanitization');
duo_check_same($sanitizerCallsBeforeHostile, $GLOBALS['wooHierarchyPermalinkSanitizerCalls'],
    'hostile clean_url callback cannot enter wc_sanitize_permalink before the refusal');
woo_hierarchy_test_clear_native_hooks();

$tecListener = Tribe__Cache_Listener::install();
$tecRewrite = Tribe__Events__Rewrite::instance();
$yoastRewrites = new Yoast_Dynamic_Rewrites();
$polylangLinks = new PLL_Links_Directory();
$pllModifyCalls = 0;
$tecListener->writes = [];
$GLOBALS['wooHierarchyTecPurgeRequested'] = false;
woo_hierarchy_test_install_native_hook('rewrite_rules_array', [
    ['wc_fix_rewrite_rules', 10, 1],
    [[$polylangLinks, 'rewrite_rules'], 10, 1],
    [[$tecRewrite, 'filter_rewrite_rules_array'], 25, 1],
    [static function (array $rules): array {
        $rules['^yoast-sitemap\\.xml$'] = 'index.php?yoast-sitemap=1';
        return $rules;
    }, 40, 1],
]);
woo_hierarchy_test_install_native_hook('pll_modify_rewrite_rule', [
    [static function (bool $modify, array $rule, string $type, string|false $archive) use (&$pllModifyCalls): bool {
        ++$pllModifyCalls;
        if (!$modify
            || $rule !== ['^fr/produit/(.+?)/?$' => 'index.php?product=$matches[1]&lang=fr']
            || $type !== 'rewrite_rules_array'
            || $archive !== false) {
            throw new RuntimeException('Polylang dynamic rewrite type/language drifted');
        }
        return true;
    }, 15, 4],
]);
woo_hierarchy_test_install_native_hook('sanitize_option_rewrite_rules', [
    [[$yoastRewrites, 'sanitize_rewrite_rules_option'], 10, 1],
]);
woo_hierarchy_test_install_native_hook('option_rewrite_rules', [
    [[$yoastRewrites, 'filter_rewrite_rules_option'], 10, 1],
]);
woo_hierarchy_test_install_native_hook('generate_rewrite_rules', [
    [[$tecListener, 'generate_rewrite_rules'], 10, 1],
    [[$tecRewrite, 'filter_generate'], 10, 1],
]);
woo_hierarchy_test_install_native_hook('updated_option', [
    [[$tecListener, 'update_last_updated_option'], 10, 3],
    [[$tecListener, 'update_last_save_post'], 10, 3],
]);
$mixedRoute = $provider->invoke('rebuild_product_permalink_routes', []);
$mixedNativeEvidence = \Duo\NativeActions::rewrite_evidence();
duo_check(($mixedRoute['after']['rewrite_rules_valid'] ?? false) === true
    && array_filter(
        array_keys((array) ($mixedRoute['after'] ?? [])),
        static fn(string $key): bool => str_starts_with($key, 'native_rewrite_')
    ) === []
    && ($mixedNativeEvidence['rules_type'] ?? null) === 'array'
    && ($mixedNativeEvidence['runtime_rules_type'] ?? null) === 'array'
    && ($mixedNativeEvidence['rules_count'] ?? null) === 6
    && ($mixedNativeEvidence['runtime_rules_count'] ?? null) === 7
    && ($mixedNativeEvidence['rules_hash'] ?? null)
        !== ($mixedNativeEvidence['runtime_rules_hash'] ?? null)
    && $tecRewrite->generationCalls === 1
    && $polylangLinks->dynamicTypeCalls === 1
    && $pllModifyCalls === 1
    && $tecListener->writes === [
        'tribe_last_generate_rewrite_rules',
        'tribe_last_updated_option',
        'tribe_last_save_post',
    ]
    && $GLOBALS['wooHierarchyTecPurgeRequested'] === true,
    'mixed Woo+Yoast+Polylang+TEC delegates one generation to Core: native evidence verifies the durable/effective split without entering the receipt preimage, Polylang dynamic type/third-party callback runs, and TEC records all marker effects');
duo_check(array_keys(array_intersect_key(WpStore::instance()->options, array_flip($tecListener->writes)))
    === $tecListener->writes,
    'mixed Woo+TEC listener writes are explicit option effects rather than an untracked request-local side effect');
woo_hierarchy_test_clear_native_hooks();

WP_CLI::$mode = 'product-core-permalink-race';
duo_check_throws(
    static fn() => $provider->invoke('rebuild_product_permalink_routes', []),
    RuntimeException::class,
    'a concurrent core permalink grammar edit cannot be blessed by a matching child rewrite receipt',
    'fresh-process evidence disagrees with checked durable storage'
);
WP_CLI::$mode = 'success';
woo_hierarchy_test_set_option('permalink_structure', '/%postname%/');
duo_check(($provider->invoke('rebuild_product_permalink_routes', [])['after']['rewrite_rules_valid'] ?? false) === true,
    'product permalink retry converges after a core permalink grammar race');

foreach ([
    'product-route-race' => 'authored product permalink state changed',
    'product-autoload-race' => 'authored product permalink state changed',
    'product-reinsert-race' => 'authored product permalink state changed',
    'product-brand-race' => 'authored product permalink state changed',
] as $mode => $needle) {
    WP_CLI::$mode = $mode;
    duo_check_throws(
        static fn() => $provider->invoke('rebuild_product_permalink_routes', []),
        RuntimeException::class,
        "raw Woo source witness ($mode) cannot be replaced while Core regenerates rewrites",
        $needle
    );
    WP_CLI::$mode = 'success';
    woo_hierarchy_test_set_option('woocommerce_permalinks', $nativeFirstHelperRow);
    woo_hierarchy_test_set_option('woocommerce_brand_permalink', 'maker-houses');
    duo_check(($provider->invoke('rebuild_product_permalink_routes', [])['after']['product_permalink_valid'] ?? false) === true,
        "product permalink retry converges after raw Woo source race $mode");
}
WP_CLI::$mode = 'product-raw-race';
duo_check_throws(
    static fn() => $provider->invoke('rebuild_product_permalink_routes', []),
    RuntimeException::class,
    'a same-semantic but noncanonical Woo permalink byte replacement cannot inherit the first raw witness',
    'noncanonical PHP-serialized data'
);
WP_CLI::$mode = 'success';
woo_hierarchy_test_set_option('woocommerce_permalinks', $nativeFirstHelperRow);
duo_check(($provider->invoke('rebuild_product_permalink_routes', [])['after']['product_permalink_valid'] ?? false) === true,
    'product permalink retry converges after same-semantic raw-byte replacement');

$hierarchyArgs = ['flush_rewrite' => false];
$receipt = $provider->invoke_scoped('rebuild_hierarchy_lookups', $hierarchyArgs, $operation);
duo_check_same(false, $receipt['before']['category_lookup_valid'] ?? null,
    'same-count hostile category rows are observed as dirty before native regeneration');
duo_check_same(false, $receipt['before']['product_cat_children_valid'] ?? null,
    'same-count hostile hierarchy-option drift is observed instead of blessed');
duo_check(($receipt['after']['category_lookup_valid'] ?? false) === true
    && ($receipt['after']['category_lookup_rows'] ?? null) === 6
    && ($receipt['after']['product_cat_children'] ?? null) === 2
    && ($receipt['after']['product_brand_children'] ?? null) === 1,
    'native child repairs exact category closure and both hierarchy caches for divergent large term ids');
$receiptBytes = json_encode($receipt, JSON_THROW_ON_ERROR);
duo_check(!str_contains($receiptBytes, '9000000000')
    && !str_contains($receiptBytes, '8000000000')
    && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['category_lookup_sha256'] ?? '')) === 1,
    'bounded receipts retain counts and exact hashes without leaking term identities');

$idempotent = $provider->invoke_scoped('rebuild_hierarchy_lookups', $hierarchyArgs, $operation);
duo_check_same($receipt['after'], $idempotent['before'],
    'a second apply begins from the exact converged projection');
duo_check_same($receipt['after'], $idempotent['after'],
    'concurrent/retried idempotent repair produces the same exact receipt');

$exactAfter = $receipt['after'];
woo_hierarchy_test_set_option('product_cat_children', [9000000000 => [9000000002], 9000000001 => [9000000002]]);
duo_check_throws(
    static fn() => $provider->reconcile_scoped(
        'rebuild_hierarchy_lookups',
        $hierarchyArgs,
        $operation
    ),
    RuntimeException::class,
    'same-count hierarchy drift cannot retire the saved receipt',
    'hierarchy option disagrees'
);
$recovered = $provider->invoke_scoped('rebuild_hierarchy_lookups', $hierarchyArgs, $operation);
duo_check_same($exactAfter, $recovered['after'], 'retry restores exact hierarchy state after same-count drift');

$wpdb->delete('wp_wc_category_lookup', ['category_tree_id' => 9000000001, 'category_id' => 9000000002]);
duo_check_throws(
    static fn() => $provider->reconcile_scoped(
        'rebuild_hierarchy_lookups',
        $hierarchyArgs,
        $operation
    ),
    RuntimeException::class,
    'a missing category closure row is a loud recovery failure',
    'exact row projection mismatch'
);
$provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);

$wpdb->update('wp_term_taxonomy', ['parent' => 9000000000], ['term_id' => 9000000002]);
$moved = $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
duo_check(($moved['after']['category_lookup_rows'] ?? null) === 5
    && ($moved['after']['product_cat_children'] ?? null) === 2
    && ($moved['after']['product_cat_parent_sha256'] ?? null)
        !== ($exactAfter['product_cat_parent_sha256'] ?? null),
    'moving a deep category parent regenerates exact closure and hierarchy bytes');

$wpdb->delete('wp_term_taxonomy', ['term_id' => 9000000001]);
$wpdb->update('wp_term_taxonomy', ['parent' => 0], ['term_id' => 9000000002]);
$removedParent = $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
duo_check(($removedParent['after']['product_cat_terms'] ?? null) === 2
    && ($removedParent['after']['category_lookup_rows'] ?? null) === 2
    && ($removedParent['after']['product_cat_children'] ?? null) === 0,
    'parent removal and child reparenting remove stale closure and hierarchy rows');

// Restore the three-level category graph for malformed/failure cases.
$wpdb->insert('wp_term_taxonomy', [
    'term_taxonomy_id' => 2,
    'term_id' => 9000000001,
    'taxonomy' => 'product_cat',
    'parent' => 9000000000,
]);
$wpdb->update('wp_term_taxonomy', ['parent' => 9000000001], ['term_id' => 9000000002]);
$provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);

$callsBeforeMalformed = count(WP_CLI::$calls);
$wpdb->update('wp_term_taxonomy', ['parent' => 7777777777], ['term_id' => 9000000002]);
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'dangling authored parent refuses before the native child can mutate derived state',
    'dangling parent'
);
duo_check_same($callsBeforeMalformed, count(WP_CLI::$calls),
    'pre-mutation hierarchy refusal launches no child process');
$wpdb->update('wp_term_taxonomy', ['parent' => 9000000001], ['term_id' => 9000000002]);

$objectPayload = serialize(new WooHierarchyWakeupCanary());
woo_hierarchy_test_set_raw_option(
    'product_cat_children',
    $objectPayload,
    new WooHierarchyWakeupCanary()
);
$objectRepair = $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
duo_check_same(0, WooHierarchyWakeupCanary::$wakeups,
    'hostile serialized hierarchy bytes cross PlainData with zero object execution');
duo_check_same(true, $objectRepair['after']['product_cat_children_valid'] ?? null,
    'hostile derived option bytes are replaced through the native repair path');

WP_CLI::$mode = 'parent-race';
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'a concurrent authored parent move cannot be blessed even when child and parent projections agree',
    'authored hierarchy source changed'
);
WP_CLI::$mode = 'success';
$wpdb->update('wp_term_taxonomy', ['parent' => 9000000001], ['term_id' => 9000000002]);
$provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);

WP_CLI::$mode = 'exit';
$failureMessage = '';
try {
    $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
} catch (Throwable $failure) {
    $failureMessage = $failure->getMessage();
}
duo_check(str_contains($failureMessage, 'exited 23')
    && str_contains($failureMessage, 'recovery_required')
    && !str_contains($failureMessage, 'secret='),
    'child failure is loud/retryable and redacts stdout plus stderr');
WP_CLI::$mode = 'success';
duo_check(($provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs)['after']['category_lookup_valid'] ?? false) === true,
    'failed child invocation retries to exact convergence');

foreach (['stderr', 'trailing', 'mismatch', 'stdout-overflow', 'stderr-overflow'] as $mode) {
    WP_CLI::$mode = $mode;
    $message = '';
    try {
        $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    duo_check($message !== '' && str_contains($message, 'recovery_required')
        && !str_contains($message, 'secret='),
        "$mode child output cannot verify or leak through the repair boundary");
}
WP_CLI::$mode = 'success';
duo_check(($provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs)['after']['category_lookup_valid'] ?? false) === true,
    'a bounded child-output refusal leaves the same exact hierarchy repair retryable');

$rewriteArgs = ['flush_rewrite' => true];
WP_CLI::$mode = 'brand-route-race';
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $rewriteArgs),
    RuntimeException::class,
    'a concurrent authored brand-route edit cannot be blessed with its matching regenerated rules',
    'authored hierarchy source changed'
);
WP_CLI::$mode = 'success';
woo_hierarchy_test_set_option('woocommerce_brand_permalink', 'maker-houses');
$routeReceipt = $provider->invoke_scoped('rebuild_hierarchy_lookups', $rewriteArgs, $operation);
duo_check(($routeReceipt['after']['rewrite_rules_valid'] ?? false) === true
    && ($routeReceipt['after']['rewrite_rules'] ?? null) === 4
    && preg_match('/^[a-f0-9]{64}$/D', (string) ($routeReceipt['after']['brand_permalink_sha256'] ?? '')) === 1,
    'brand permalink path flushes and binds exact fresh-process rewrite state');
$savedRoute = $routeReceipt['after'];
woo_hierarchy_test_set_option('rewrite_rules', [
    '^maker-houses/(.+?)/?$' => 'index.php?wrong=$matches[1]',
    '^shop/?$' => 'index.php?post_type=product',
    '^catalog/(.+?)/?$' => 'index.php?wrong-product-cat=$matches[1]',
    '^labels/(.+?)/?$' => 'index.php?wrong-product-tag=$matches[1]',
]);
$routeDrift = $provider->reconcile_scoped(
    'rebuild_hierarchy_lookups',
    $rewriteArgs,
    $operation
);
duo_check(($routeDrift['after']['rewrite_rules'] ?? null) === ($savedRoute['rewrite_rules'] ?? null)
    && ($routeDrift['after']['rewrite_rules_canonical_sha256'] ?? null)
        !== ($savedRoute['rewrite_rules_canonical_sha256'] ?? null),
    'same-count durable rewrite drift changes the scoped recovery fingerprint');
$routeRecovered = $provider->invoke('rebuild_hierarchy_lookups', $rewriteArgs);
duo_check_same($savedRoute, $routeRecovered['after'], 'brand-route retry restores exact native rewrite state');

woo_hierarchy_test_remove_option('woocommerce_brand_permalink');
$defaultRoute = $provider->invoke('rebuild_hierarchy_lookups', $rewriteArgs);
duo_check(($defaultRoute['after']['rewrite_rules_valid'] ?? false) === true
    && ($defaultRoute['after']['brand_permalink_sha256'] ?? null) === hash('sha256', ''),
    'absent brand permalink uses Woo core default while remaining distinctly receipt-bound');

$wpdb->failNextQuery('secret=database-marker', 'term_taxonomy');
$dbFailure = '';
try {
    $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
} catch (Throwable $failure) {
    $dbFailure = $failure->getMessage();
}
duo_check(str_contains($dbFailure, 'provider checked read failed')
    && !str_contains($dbFailure, 'database-marker'),
    'checked parent-map read failure stays loud and redacts driver detail');
duo_check(($provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs)['after']['category_lookup_valid'] ?? false) === true,
    'checked-read failure retries cleanly');

$rawOption = new ReflectionMethod(
    \Duo\Providers\WoocommerceHierarchyLookups::class,
    'raw_option'
);
$optionRows = $wpdb->rows('wp_options');
$aliasedRows = $optionRows;
$aliasedRows[] = [
    'option_id' => 99,
    'option_name' => 'Product_cat_children',
    'option_value' => 'a:0:{}',
    'autoload' => 'yes',
];
woo_hierarchy_test_seed_option_rows($aliasedRows);
$callsBeforeAlias = count(WP_CLI::$calls);
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'a collation-equivalent hierarchy option alias refuses before native mutation',
    'aliased'
);
duo_check_same($callsBeforeAlias, count(WP_CLI::$calls),
    'an aliased option identity launches no repair child');
woo_hierarchy_test_seed_option_rows($optionRows);

$oversizedRaw = str_repeat('x', 16777217);
woo_hierarchy_test_set_raw_option('product_cat_children', $oversizedRaw, []);
$wpdb->resetLog();
duo_check_same(null, $rawOption->invoke(null, 'product_cat_children', false),
    'an oversized dirty derived option is observed without transferring its LONGTEXT payload');
$oversizedQueries = $wpdb->queries();
duo_check(count($oversizedQueries) === 1
    && str_contains($oversizedQueries[0], 'LENGTH(option_value) AS option_bytes')
    && !str_contains($oversizedQueries[0], ', option_value,'),
    'oversized option refusal stops after the compact identity/size witness');
duo_check_throws(
    static fn() => $rawOption->invoke(null, 'product_cat_children', true),
    RuntimeException::class,
    'verified readback refuses an oversized hierarchy option loudly',
    'bounded plain-data contract'
);
woo_hierarchy_test_set_option(
    'product_cat_children',
    woo_hierarchy_test_children(woo_hierarchy_test_parent_map('product_cat'))
);

$wpdb->resetLog();
$grewDuringRead = false;
$wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$grewDuringRead): null {
    if (!$grewDuringRead
        && str_contains($sql, "BINARY option_name = BINARY 'product_cat_children'")) {
        $grewDuringRead = true;
        $db->onQuery(null);
        $db->update('wp_options', ['option_value' => str_repeat('y', 4096)], [
            'option_name' => 'product_cat_children',
        ]);
    }
    return null;
});
duo_check_throws(
    static fn() => $rawOption->invoke(null, 'product_cat_children', true),
    RuntimeException::class,
    'option growth between compact witness and payload fetch cannot cross the byte bound',
    'changed during bounded readback'
);
duo_check($grewDuringRead,
    'the growth race is injected after the witness and before the exact payload fetch');
woo_hierarchy_test_set_option(
    'product_cat_children',
    woo_hierarchy_test_children(woo_hierarchy_test_parent_map('product_cat'))
);

$sameLengthPayloadReads = 0;
$currentChildrenRaw = (string) array_values(array_filter(
    $wpdb->rows('wp_options'),
    static fn(array $row): bool => ($row['option_name'] ?? null) === 'product_cat_children'
))[0]['option_value'];
$sameLengthChildrenRaw = str_replace('9000000002', '9000000003', $currentChildrenRaw);
duo_check(strlen($sameLengthChildrenRaw) === strlen($currentChildrenRaw)
    && $sameLengthChildrenRaw !== $currentChildrenRaw,
    'same-length hierarchy race fixture changes bytes without changing the compact size witness');
$wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (
    &$sameLengthPayloadReads,
    $sameLengthChildrenRaw
): null {
    if (str_contains($sql, "BINARY option_name = BINARY 'product_cat_children'")
        && ++$sameLengthPayloadReads === 2) {
        $db->onQuery(null);
        $db->update('wp_options', ['option_value' => $sameLengthChildrenRaw], [
            'option_name' => 'product_cat_children',
        ]);
    }
    return null;
});
duo_check_throws(
    static fn() => $rawOption->invoke(null, 'product_cat_children', true),
    RuntimeException::class,
    'same-length hierarchy option replacement cannot cross the confirming payload read',
    'changed during bounded readback'
);
duo_check_same(2, $sameLengthPayloadReads,
    'same-length hierarchy mutation occurs between first and confirming bounded payload reads');
woo_hierarchy_test_set_option(
    'product_cat_children',
    woo_hierarchy_test_children(woo_hierarchy_test_parent_map('product_cat'))
);

$wpdb->failNextQuery('secret=HIERARCHY_OPTION_DRIVER', 'LENGTH(option_value) AS option_bytes');
$optionFailure = '';
try {
    $rawOption->invoke(null, 'product_cat_children', true);
} catch (Throwable $failure) {
    $optionFailure = $failure->getMessage();
}
duo_check(str_contains($optionFailure, 'provider checked read failed')
    && !str_contains($optionFailure, 'HIERARCHY_OPTION_DRIVER'),
    'option witness database failure is loud, bounded, and redacted');

$wpdb->prefix = "wp_`hostile";
$wpdb->options = "wp_`hostileoptions";
$wpdb->term_taxonomy = "wp_`hostileterm_taxonomy";
$wpdb->resetLog();
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'a hostile hierarchy database prefix is refused before identifier interpolation',
    'exact site options-table identity'
);
duo_check_same([], $wpdb->queries(),
    'invalid hierarchy database identifiers reach no checked SQL read');
$wpdb->prefix = 'wp_';
$wpdb->options = 'wp_options';
$wpdb->term_taxonomy = 'wp_term_taxonomy';

$wpdb->setColumns('wp_wc_category_lookup', array_merge(
    ['category_tree_id' => 'bigint(20) unsigned', 'category_id' => 'bigint(20) unsigned'],
    array_fill_keys(array_map(
        static fn(int $index): string => 'hostile_' . $index,
        range(1, 4095)
    ), 'longtext')
));
$wpdb->resetLog();
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'a hostile schema inventory beyond the explicit MySQL column ceiling refuses before repair',
    'oversized column inventory'
);
$oversizedSchemaQueries = $wpdb->queries();
duo_check(count($oversizedSchemaQueries) === 1
    && str_contains($oversizedSchemaQueries[0], 'information_schema.COLUMNS')
    && !array_filter(
        $oversizedSchemaQueries,
        static fn(string $sql): bool => str_contains($sql, 'SHOW COLUMNS')
    ), 'oversized category schema refuses from the compact count before SHOW transfers any column rows');
$wpdb->setColumns('wp_wc_category_lookup', [
    'category_tree_id' => 'bigint(20) unsigned',
    'category_id' => 'bigint(20) unsigned',
]);
$provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);

$source = (string) file_get_contents($root . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-hierarchy-lookups.php');
duo_check(str_contains($source, 'use Duo\\WpCliChildProcess;')
    && str_contains($source, "\$duoLayoutRoot = dirname(__DIR__, 5);")
    && str_contains($source, "is_dir(\$duoLayoutRoot . '/agent/src')")
    && str_contains($source, "basename(\$duoLayoutRoot) === 'agent'")
    && str_contains($source, "require_once \$duoAgentRoot . '/src/Kernel/WpCliChildProcess.php';")
    && preg_match('/WpCliChildProcess::capture\(\s*\'eval \' \. escapeshellarg\(\$code\),\s*120,\s*16384,\s*16384\s*\)/', $source) === 1
    && !str_contains($source, 'WP_CLI::runcommand('),
    'hierarchy repair owns the reviewed bounded 120-second/16-KiB child transport rather than WP-CLI return=all');
duo_check(str_contains($source, 'CategoryLookup')
    && str_contains($source, '->regenerate()')
    && str_contains($source, '_get_term_hierarchy($taxonomy)')
    && str_contains($source, 'clean_taxonomy_cache($taxonomy)')
    && str_contains($source, "wp_cache_delete('get', 'term-queries')"),
    'shipped child uses Woo/WordPress native writers after explicit persistent-cache invalidation');
duo_check(str_contains($source, "NativeActions::execute('rewrite.flush', [])")
    && !str_contains($source, 'flush_rewrite_rules(false)')
    && !str_contains($source, '$wp_rewrite->rewrite_rules()')
    && str_contains($source, 'NativeActions owns the extension interpreter'),
    'Woo binds exactly one shared native rewrite receipt and never re-enters the effectful generator for verification');
duo_check(str_contains($source, 'PlainData::decode_serialized(')
    && !str_contains($source, 'maybe_unserialize('),
    'all raw serialized hierarchy/rewrite bytes use the shared class-disabled plain-data boundary');

$acyclic = new ReflectionMethod(
    \Duo\Providers\WoocommerceHierarchyLookups::class,
    'assert_acyclic_parent_map'
);
$longChain = [];
for ($termId = 1; $termId <= 50000; ++$termId) {
    $longChain[$termId] = $termId - 1;
}
$acyclic->invoke(null, $longChain, 'product_cat');
duo_check(true,
    'a 50k-deep acyclic hierarchy completes through the linear iterative graph validator');
duo_check_throws(
    static fn() => $acyclic->invoke(null, [1 => 2, 2 => 1], 'product_cat'),
    RuntimeException::class,
    'the linear graph validator still refuses an authored parent cycle',
    'contains a cycle'
);

$closure = new ReflectionMethod(
    \Duo\Providers\WoocommerceHierarchyLookups::class,
    'category_closure'
);
$overBoundChain = [];
for ($termId = 1; $termId <= 632; ++$termId) {
    $overBoundChain[$termId] = $termId - 1;
}
duo_check_throws(
    static fn() => $closure->invoke(null, $overBoundChain),
    RuntimeException::class,
    'a valid deep hierarchy refuses before allocating a category closure beyond the exact row cap',
    'bounded row contract'
);

$queries = $wpdb->queries();
duo_check(
    count(array_filter($queries, static fn(string $sql): bool =>
        str_contains($sql, 'SELECT term_id, parent FROM wp_term_taxonomy')
        && str_contains($sql, 'LIMIT 200001'))) > 0
    && count(array_filter($queries, static fn(string $sql): bool =>
        str_contains($sql, 'SELECT category_tree_id, category_id FROM `wp_wc_category_lookup`')
        && str_contains($sql, 'LIMIT 200001'))) > 0,
    'authored parent and derived lookup reads carry exact MAX+1 SQL limits'
);

$wpdb->setColumns('wp_wc_category_lookup', [
    'category_tree_id' => 'bigint(20) unsigned',
    'category_id' => 'bigint(20) unsigned',
    'addon_payload' => 'longtext',
]);
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'unknown category lookup schema refuses before native truncation',
    'unknown or missing columns'
);

duo_check_summary('WooCommerce hierarchy lookup provider');
