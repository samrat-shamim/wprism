<?php
/** Offline adversarial product-path regression for Woo fulfillment lifecycle gating. */
declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Features {
    final class FeaturesController {
        public bool $enabled = true;

        public function feature_is_enabled(string $feature): bool {
            return $feature === 'fulfillments' && $this->enabled;
        }
    }
}

namespace Automattic\WooCommerce\Internal\Utilities {
    final class DatabaseUtil {
        public int $maxIndexLength = 191;

        public function get_max_index_length(): int {
            return $this->maxIndexLength;
        }
    }
}

namespace {
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
    duo_test_define_agent_versions();
    define('ABSPATH', __DIR__ . '/../../../../sandbox/tmp/fulfillment-wordpress/');
    define('WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins');

    $root = dirname(__DIR__, 4);
    putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');

    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';
    require_once $root . '/agent/src/Kernel/Canon.php';
    require_once $root . '/agent/src/Kernel/OptionState.php';
    require_once $root . '/agent/src/Policy/Policy.php';
    require_once $root . '/agent/src/Promotion/Deploy.php';
    require_once $root . '/agent/src/Adapter/ProviderSdk.php';
    require_once $root . '/agent/src/Adapter/Providers.php';

    use Automattic\WooCommerce\Internal\Features\FeaturesController;
    use Automattic\WooCommerce\Internal\Utilities\DatabaseUtil;
    use Duo\Policy;
    use Duo\Providers;
    use DuoTest\FakeWpdb;
    use DuoTest\WpStore;

    final class WooFulfillmentContainer {
        public function __construct(
            public FeaturesController $features,
            public DatabaseUtil $database
        ) {
        }

        public function get(string $class): object {
            return match ($class) {
                FeaturesController::class => $this->features,
                DatabaseUtil::class => $this->database,
                default => throw new RuntimeException('unregistered test service'),
            };
        }
    }

    $GLOBALS['wooFulfillmentTaxonomy'] = null;
    $GLOBALS['wooFulfillmentContainer'] = new WooFulfillmentContainer(
        new FeaturesController(),
        new DatabaseUtil()
    );

    function wc_get_container(): WooFulfillmentContainer {
        return $GLOBALS['wooFulfillmentContainer'];
    }

    function taxonomy_exists(string $taxonomy): bool {
        return $taxonomy === 'wc_fulfillment_shipping_provider'
            && is_object($GLOBALS['wooFulfillmentTaxonomy'] ?? null);
    }

    function get_taxonomy(string $taxonomy): object|false {
        return taxonomy_exists($taxonomy) ? $GLOBALS['wooFulfillmentTaxonomy'] : false;
    }

    function validate_plugin(string $plugin): int|WP_Error {
        return $plugin === 'woocommerce/woocommerce.php' ? 0 : new WP_Error('missing');
    }

    function get_plugins(): array {
        return ['woocommerce/woocommerce.php' => ['Version' => '11.0.1']];
    }

    /** @return object */
    function woo_fulfillment_taxonomy(): object {
        return (object) [
            'name' => 'wc_fulfillment_shipping_provider',
            'object_type' => [],
            'description' => '',
            'hierarchical' => false,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'show_admin_column' => false,
            'show_in_nav_menus' => false,
            'show_tagcloud' => false,
            'show_in_quick_edit' => false,
            'meta_box_cb' => 'post_tags_meta_box',
            'meta_box_sanitize_cb' => 'taxonomy_meta_box_sanitize_cb_input',
            'cap' => (object) [
                'manage_terms' => 'manage_categories',
                'edit_terms' => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ],
            'query_var' => false,
            'rewrite' => false,
            'update_count_callback' => '',
            'rest_base' => false,
            'rest_namespace' => false,
            'rest_controller_class' => false,
            'rest_controller' => null,
            'default_term' => null,
            'sort' => null,
            'args' => null,
            '_builtin' => false,
        ];
    }

    /** @return list<array{Field:string,Type:string,Null:string,Key:string,Default:mixed,Extra:string}> */
    function woo_fulfillment_columns(string $table): array {
        if ($table === 'wc_order_fulfillments') {
            return [
                ['Field' => 'fulfillment_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
                ['Field' => 'entity_type', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => 'MUL', 'Default' => null, 'Extra' => ''],
                ['Field' => 'entity_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
                ['Field' => 'status', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
                ['Field' => 'is_fulfilled', 'Type' => 'tinyint(1)', 'Null' => 'NO', 'Key' => '', 'Default' => '0', 'Extra' => ''],
                ['Field' => 'date_updated', 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
                ['Field' => 'date_deleted', 'Type' => 'datetime', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ];
        }
        return [
            ['Field' => 'meta_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
            ['Field' => 'fulfillment_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Key' => 'MUL', 'Default' => null, 'Extra' => ''],
            ['Field' => 'meta_key', 'Type' => 'varchar(255)', 'Null' => 'YES', 'Key' => 'MUL', 'Default' => null, 'Extra' => ''],
            ['Field' => 'meta_value', 'Type' => 'longtext', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'date_updated', 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ['Field' => 'date_deleted', 'Type' => 'datetime', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
        ];
    }

    /** @return list<array<string,mixed>> */
    function woo_fulfillment_indexes(string $table, int $prefix = 191): array {
        if ($table === 'wc_order_fulfillments') {
            return [
                ['Key_name' => 'entity_type_id', 'Non_unique' => '1', 'Seq_in_index' => '2', 'Column_name' => 'entity_id', 'Sub_part' => null, 'Index_type' => 'BTREE'],
                ['Key_name' => 'PRIMARY', 'Non_unique' => '0', 'Seq_in_index' => '1', 'Column_name' => 'fulfillment_id', 'Sub_part' => null, 'Index_type' => 'BTREE'],
                ['Key_name' => 'entity_type_id', 'Non_unique' => '1', 'Seq_in_index' => '1', 'Column_name' => 'entity_type', 'Sub_part' => (string) $prefix, 'Index_type' => 'BTREE'],
            ];
        }
        return [
            ['Key_name' => 'meta_key', 'Non_unique' => '1', 'Seq_in_index' => '1', 'Column_name' => 'meta_key', 'Sub_part' => (string) $prefix, 'Index_type' => 'BTREE'],
            ['Key_name' => 'PRIMARY', 'Non_unique' => '0', 'Seq_in_index' => '1', 'Column_name' => 'meta_id', 'Sub_part' => null, 'Index_type' => 'BTREE'],
            ['Key_name' => 'fulfillment_id', 'Non_unique' => '1', 'Seq_in_index' => '1', 'Column_name' => 'fulfillment_id', 'Sub_part' => null, 'Index_type' => 'BTREE'],
        ];
    }

    function woo_fulfillment_install_tables(FakeWpdb $wpdb, int $prefix = 191): void {
        foreach (['wc_order_fulfillments', 'wc_order_fulfillment_meta'] as $table) {
            $wpdb->seedTable($table, [])
                ->setColumnDefinitions($table, woo_fulfillment_columns($table))
                ->setIndexes($table, woo_fulfillment_indexes($table, $prefix));
        }
    }

    function woo_fulfillment_set_options(FakeWpdb $wpdb, string $feature = 'yes', string $marker = '1'): void {
        $store = WpStore::instance();
        $store->options['active_plugins'] = ['woocommerce/woocommerce.php'];
        $store->options['woocommerce_feature_fulfillments_enabled'] = $feature;
        $store->options['woocommerce_fulfillments_db_tables_created'] = $marker;
        $wpdb->seedTable('options', [
            ['option_id' => 1, 'option_name' => 'woocommerce_feature_fulfillments_enabled', 'option_value' => $feature, 'autoload' => 'yes'],
            ['option_id' => 2, 'option_name' => 'woocommerce_fulfillments_db_tables_created', 'option_value' => $marker, 'autoload' => 'yes'],
        ])->setColumns('options', [
            'option_id' => 'bigint(20) unsigned',
            'option_name' => 'varchar(191)',
            'option_value' => 'longtext',
            'autoload' => 'varchar(20)',
        ]);
    }

    function woo_fulfillment_ready_target(): FakeWpdb {
        WpStore::reset();
        $wpdb = FakeWpdb::install();
        woo_fulfillment_set_options($wpdb);
        woo_fulfillment_install_tables($wpdb);
        $GLOBALS['wooFulfillmentTaxonomy'] = woo_fulfillment_taxonomy();
        $GLOBALS['wooFulfillmentContainer']->features->enabled = true;
        $GLOBALS['wooFulfillmentContainer']->database->maxIndexLength = 191;
        $wpdb->seedTable('term_taxonomy', [[
            'term_taxonomy_id' => 71,
            'term_id' => 9000000001,
            'taxonomy' => 'wc_fulfillment_shipping_provider',
            'parent' => 0,
        ]])->setColumns('term_taxonomy', [
            'term_taxonomy_id' => 'bigint(20) unsigned',
            'term_id' => 'bigint(20) unsigned',
            'taxonomy' => 'varchar(32)',
            'parent' => 'bigint(20) unsigned',
        ]);
        return $wpdb;
    }

    function woo_fulfillment_provider(Policy $policy): \Duo\Providers\WoocommerceFulfillmentPrerequisites {
        return new \Duo\Providers\WoocommerceFulfillmentPrerequisites($policy);
    }

    $policy = Policy::load(null, ['woocommerce']);
    $wpdb = woo_fulfillment_ready_target();
    require_once $root . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-fulfillment-prerequisites.php';
    $provider = woo_fulfillment_provider($policy);

    duo_check_same([
        'id' => 'woocommerce-fulfillment-prerequisites',
        'plugin' => 'woocommerce/woocommerce.php',
        'version' => '1.0.0',
    ], $provider->identity(), 'fulfillment prerequisite provider identity is exact and manifest-bindable');

    $capabilities = $provider->capabilities();
    duo_check_same([], $capabilities['verify_fulfillment_prerequisites']['writes'] ?? null,
        'fulfillment prerequisite capability advertises exact writes: []');
    duo_check_same([
        'entity:woocommerce-fulfillment-runtime',
        'option:woocommerce_feature_fulfillments_enabled',
        'option:woocommerce_fulfillments_db_tables_created',
        'table:wc_order_fulfillment_meta',
        'table:wc_order_fulfillments',
    ], $capabilities['verify_fulfillment_prerequisites']['reads'] ?? null,
        'capability declares only the option/table/native-registry observations it actually performs');
    duo_check_same([], $capabilities['verify_fulfillment_prerequisites']['args'] ?? null,
        'fulfillment prerequisite capability accepts no provider-controlled payload');

    $selected = $policy->actions_for(['term:wc_fulfillment_shipping_provider']);
    duo_check(count($selected) === 1
        && ($selected[0]['provider'] ?? null) === 'woocommerce-fulfillment-prerequisites'
        && array_key_exists('effects', $selected[0])
        && $selected[0]['effects'] === [],
        'an authored fulfillment-provider term selects the exact explicit read-only preflight action');
    duo_check_same([], Policy::action_effects($selected[0], (int) $selected[0]['index']),
        'read-only fulfillment preflight projects no fabricated recovery effect');
    duo_check(count(array_filter(
        $policy->effects_inventory(),
        static fn(array $row): bool => ($row['source'] ?? null)
            === 'provider:woocommerce-fulfillment-prerequisites/verify_fulfillment_prerequisites'
    )) === 0, 'fulfillment preflight contributes no global or scoped effect inventory row');

    $negotiation = Providers::negotiate($policy, $selected);
    duo_check($negotiation['problems'] === []
        && isset($negotiation['providers']['woocommerce-fulfillment-prerequisites']),
        'source-on/target-on exact lifecycle state negotiates before mutation');

    $beforeRows = [
        'options' => $wpdb->rows('options'),
        'term_taxonomy' => $wpdb->rows('term_taxonomy'),
        'fulfillments' => $wpdb->rows('wc_order_fulfillments'),
        'meta' => $wpdb->rows('wc_order_fulfillment_meta'),
    ];
    $beforeStore = WpStore::instance()->options;
    $beforeTaxonomy = serialize($GLOBALS['wooFulfillmentTaxonomy']);
    $wpdb->resetLog();
    $receipt = $provider->invoke('verify_fulfillment_prerequisites', []);
    duo_check(($receipt['verified'] ?? false) === true
        && ($receipt['before'] ?? null) === ($receipt['after'] ?? null)
        && ($receipt['after']['feature_native_enabled'] ?? null) === 1
        && ($receipt['after']['fulfillment_tables'] ?? null) === 2
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['fulfillment_schema_sha256'] ?? '')) === 1,
        'exact ready state produces a bounded same-state read-only receipt');
    $queries = $wpdb->queries();
    duo_check($queries !== [] && count(array_filter(
        $queries,
        static fn(string $sql): bool => preg_match('/^(?:SELECT|SHOW)\b/i', $sql) !== 1
    )) === 0 && $wpdb->ddlLog() === [],
        'provider verification executes only checked SELECT/SHOW reads and no DDL/DML');
    duo_check(count(array_filter(
        $queries,
        static fn(string $sql): bool => str_contains($sql, 'LENGTH(option_value) AS option_bytes')
            && str_contains($sql, 'WHERE option_name =')
            && str_contains($sql, 'ORDER BY option_id ASC LIMIT 2')
    )) === 4 && count(array_filter(
        $queries,
        static fn(string $sql): bool => str_contains($sql, 'BINARY option_name = BINARY')
            && str_contains($sql, 'AND LENGTH(option_value) =')
    )) === 8,
        'both prerequisite options use compact witnesses followed by two exact size-bound payload fetches');
    duo_check(count(array_filter(
        $queries,
        static fn(string $sql): bool => str_contains($sql, 'information_schema.COLUMNS')
            || str_contains($sql, 'information_schema.STATISTICS')
    )) === 8,
        'both fulfillment schemas use compact column/index cardinality witnesses before SHOW transfer');
    duo_check_same($beforeRows, [
        'options' => $wpdb->rows('options'),
        'term_taxonomy' => $wpdb->rows('term_taxonomy'),
        'fulfillments' => $wpdb->rows('wc_order_fulfillments'),
        'meta' => $wpdb->rows('wc_order_fulfillment_meta'),
    ], 'provider verification leaves every database row byte-for-byte unchanged');
    duo_check_same($beforeStore, WpStore::instance()->options,
        'provider verification leaves effective option state unchanged');
    duo_check_same($beforeTaxonomy, serialize($GLOBALS['wooFulfillmentTaxonomy']),
        'provider verification leaves the native taxonomy registration unchanged');

    $operation = [
        'authority_hash' => str_repeat('a', 64),
        'lease_session_id' => 'fulfillment-session-1',
        'operation_id' => 'fulfillment-operation-1',
        'input_hash' => str_repeat('b', 64),
        'effect_hash' => str_repeat('c', 64),
    ];
    $scoped = $provider->invoke_scoped('verify_fulfillment_prerequisites', [], $operation);
    $reconciled = $provider->reconcile_scoped('verify_fulfillment_prerequisites', [], $operation);
    duo_check(($scoped['operation'] ?? null) === $operation
        && ($reconciled['operation'] ?? null) === $operation
        && ($scoped['after'] ?? null) === ($reconciled['after'] ?? null),
        'scoped invoke and reconcile bind the exact operation and prerequisite fingerprint');

    // Source-off means no fulfillment canonical surface. Target-off must not
    // load or negotiate this provider merely because its manifest is pinned.
    $wpdb = woo_fulfillment_ready_target();
    woo_fulfillment_set_options($wpdb, 'no', '0');
    $GLOBALS['wooFulfillmentContainer']->features->enabled = false;
    $GLOBALS['wooFulfillmentTaxonomy'] = null;
    $wpdb->resetLog();
    duo_check_same([], $policy->actions_for([]), 'source-off selects no fulfillment prerequisite action');
    duo_check_same(
        ['problems' => [], 'providers' => [], 'capabilities' => [], 'surface_observation' => []],
        Providers::negotiate($policy, $policy->actions_for([])),
        'source-off/target-off is a clean no-provider negotiation'
    );
    duo_check_same([], $wpdb->queries(), 'source-off/target-off performs no prerequisite target reads');

    // Source-on/target-off reaches the real selected negotiation gate and
    // refuses while preserving the populated target term and all options.
    $targetOffRows = $wpdb->rows('term_taxonomy');
    $targetOffOptions = $wpdb->rows('options');
    $targetOff = Providers::negotiate($policy, $selected);
    duo_check(count($targetOff['problems']) === 1
        && ($targetOff['problems'][0]['code'] ?? null) === 'missing_capability'
        && $targetOff['providers'] === [],
        'source-on/target-off refuses at provider negotiation before materialization');
    duo_check_same($targetOffRows, $wpdb->rows('term_taxonomy'),
        'target-off refusal preserves populated target fulfillment terms');
    duo_check_same($targetOffOptions, $wpdb->rows('options'),
        'target-off refusal preserves target options for rollback-free retry');
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'invoke rechecks a feature disabled after negotiation',
        'feature to be enabled'
    );

    // Re-enabling the option/controller without running Woo's native init is
    // not enough: taxonomy registration and table lifecycle must both exist.
    woo_fulfillment_set_options($wpdb, 'yes', '1');
    $GLOBALS['wooFulfillmentContainer']->features->enabled = true;
    duo_check_same([], $provider->capabilities(),
        'feature re-enable without native taxonomy initialization remains unavailable');
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'feature re-enable requires native init before retry',
        'taxonomy is not registered'
    );
    $GLOBALS['wooFulfillmentTaxonomy'] = woo_fulfillment_taxonomy();
    duo_check(isset($provider->capabilities()['verify_fulfillment_prerequisites']),
        'native taxonomy lifecycle completion makes the exact target retryable');

    // A stale truthy marker plus one surviving table is the exact partial
    // state Woo 11.0.x's marker/main-table shortcut can otherwise bless.
    $wpdb = woo_fulfillment_ready_target();
    $wpdb->query('DROP TABLE wp_wc_order_fulfillment_meta');
    $wpdb->resetLog();
    duo_check_same([], $provider->capabilities(),
        'truthy marker plus missing fulfillment meta table cannot negotiate');
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'partial fulfillment tables remain loud at invoke',
        'database table is absent'
    );
    woo_fulfillment_install_tables($wpdb);
    duo_check(isset($provider->capabilities()['verify_fulfillment_prerequisites']),
        'native table lifecycle repair makes the partial target retry cleanly');

    $wpdb = woo_fulfillment_ready_target();
    $wpdb->query('DROP TABLE wp_wc_order_fulfillments');
    $wpdb->resetLog();
    duo_check_same([], $provider->capabilities(),
        'truthy marker plus a missing main fulfillment table cannot negotiate');
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'missing main fulfillment table remains loud at invoke',
        'database table is absent'
    );

    $wpdb = woo_fulfillment_ready_target();
    $wpdb->seedTable('WC_order_fulfillments', []);
    duo_check_same([], $provider->capabilities(),
        'a collation-equivalent fulfillment table alias cannot satisfy exact table identity');

    $wpdb = woo_fulfillment_ready_target();
    woo_fulfillment_set_options($wpdb, 'yes', 'yes');
    duo_check_same([], $provider->capabilities(),
        'non-writer marker spelling is stale even when PHP truthiness would pass Woo core');
    woo_fulfillment_set_options($wpdb);
    WpStore::instance()->options['woocommerce_fulfillments_db_tables_created'] = '0';
    duo_check_same([], $provider->capabilities(),
        'raw/effective marker cache disagreement cannot be blessed');

    $wpdb = woo_fulfillment_ready_target();
    WpStore::instance()->options['woocommerce_feature_fulfillments_enabled'] = 'no';
    duo_check_same([], $provider->capabilities(),
        'raw/effective feature cache disagreement cannot be blessed');

    $wpdb = woo_fulfillment_ready_target();
    $optionRows = $wpdb->rows('options');
    $optionRows[] = [
        'option_id' => 90,
        'option_name' => 'WooCommerce_feature_fulfillments_enabled',
        'option_value' => 'yes',
        'autoload' => 'yes',
    ];
    $wpdb->seedTable('options', $optionRows)->setColumns('options', [
        'option_id' => 'bigint(20) unsigned',
        'option_name' => 'varchar(191)',
        'option_value' => 'longtext',
        'autoload' => 'varchar(20)',
    ]);
    duo_check_same([], $provider->capabilities(),
        'a collation-equivalent fulfillment feature option alias fails closed');
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'an aliased fulfillment option identity remains loud at invoke',
        'aliased'
    );

    $wpdb = woo_fulfillment_ready_target();
    $wpdb->update('options', ['option_value' => str_repeat('x', 17)], [
        'option_name' => 'woocommerce_feature_fulfillments_enabled',
    ]);
    WpStore::instance()->options['woocommerce_feature_fulfillments_enabled'] = str_repeat('x', 17);
    $wpdb->resetLog();
    duo_check_same([], $provider->capabilities(),
        'an oversized fulfillment option fails before its LONGTEXT payload is fetched');
    $oversizedOptionQueries = $wpdb->queries();
    duo_check(count($oversizedOptionQueries) === 1
        && str_contains($oversizedOptionQueries[0], 'LENGTH(option_value) AS option_bytes')
        && !str_contains($oversizedOptionQueries[0], ', option_value,'),
        'oversized fulfillment option refusal stops at the compact witness');

    $wpdb = woo_fulfillment_ready_target();
    $wpdb->update('options', ['option_id' => 9000000001], [
        'option_name' => 'woocommerce_feature_fulfillments_enabled',
    ]);
    duo_check(isset($provider->capabilities()['verify_fulfillment_prerequisites']),
        'a valid divergent 64-bit option identity remains inside the canonical uint boundary');

    $wpdb = woo_fulfillment_ready_target();
    $grewDuringRead = false;
    $wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$grewDuringRead): null {
        if (!$grewDuringRead
            && str_contains($sql, "BINARY option_name = BINARY 'woocommerce_feature_fulfillments_enabled'")) {
            $grewDuringRead = true;
            $db->onQuery(null);
            $db->update('options', ['option_value' => str_repeat('z', 17)], [
                'option_name' => 'woocommerce_feature_fulfillments_enabled',
            ]);
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'fulfillment option growth after its witness cannot cross the bounded payload read',
        'changed during bounded readback'
    );
    duo_check($grewDuringRead,
        'the fulfillment size race is injected between witness and payload fetch');

    $wpdb = woo_fulfillment_ready_target();
    $sameLengthPayloadReads = 0;
    $wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$sameLengthPayloadReads): null {
        if (str_contains(
            $sql,
            "BINARY option_name = BINARY 'woocommerce_feature_fulfillments_enabled'"
        ) && ++$sameLengthPayloadReads === 2) {
            $db->onQuery(null);
            $db->update('options', ['option_value' => 'no!'], [
                'option_name' => 'woocommerce_feature_fulfillments_enabled',
            ]);
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'same-length fulfillment option replacement cannot cross the confirming payload read',
        'changed during bounded readback'
    );
    duo_check_same(2, $sameLengthPayloadReads,
        'the same-length race is injected between the first and confirming bounded payload reads');

    $wpdb = woo_fulfillment_ready_target();
    foreach ([
        'name' => 'wc_fulfillment_shipping_provider_hijack',
        'object_type' => ['product'],
        'description' => 'extension-mutated',
        'hierarchical' => true,
        'public' => true,
        'publicly_queryable' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true,
        'show_admin_column' => true,
        'show_in_nav_menus' => true,
        'show_tagcloud' => true,
        'show_in_quick_edit' => true,
        'meta_box_cb' => false,
        'meta_box_sanitize_cb' => false,
        'cap' => (object) [
            'manage_terms' => 'manage_options',
            'edit_terms' => 'manage_categories',
            'delete_terms' => 'manage_categories',
            'assign_terms' => 'edit_posts',
        ],
        'query_var' => 'fulfillment-provider',
        'rewrite' => ['slug' => 'fulfillment-provider'],
        'update_count_callback' => '_wc_term_recount',
        'rest_base' => 'fulfillment-providers',
        'rest_namespace' => 'wc/v3',
        'rest_controller_class' => 'Hijacked_Rest_Controller',
        'rest_controller' => new stdClass(),
        'default_term' => ['name' => 'Injected'],
        'sort' => true,
        'args' => ['orderby' => 'term_order'],
        '_builtin' => true,
    ] as $field => $hostileValue) {
        $wpdb = woo_fulfillment_ready_target();
        $malformedTaxonomy = woo_fulfillment_taxonomy();
        $malformedTaxonomy->$field = $hostileValue;
        $GLOBALS['wooFulfillmentTaxonomy'] = $malformedTaxonomy;
        duo_check_same([], $provider->capabilities(),
            "extension-mutated fulfillment taxonomy field $field fails closed independently");
    }

    $wpdb = woo_fulfillment_ready_target();
    $columns = woo_fulfillment_columns('wc_order_fulfillments');
    $columns[] = ['Field' => 'addon_payload', 'Type' => 'longtext', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''];
    $wpdb->setColumnDefinitions('wc_order_fulfillments', $columns);
    duo_check_same([], $provider->capabilities(),
        'unknown fulfillment table column cannot inherit the core schema claim');

    $wpdb = woo_fulfillment_ready_target();
    $columns = woo_fulfillment_columns('wc_order_fulfillments');
    array_pop($columns);
    $wpdb->setColumnDefinitions('wc_order_fulfillments', $columns);
    duo_check_same([], $provider->capabilities(),
        'a missing native fulfillment column cannot inherit the core schema claim');

    $wpdb = woo_fulfillment_ready_target();
    $columns = woo_fulfillment_columns('wc_order_fulfillments');
    $columns[] = $columns[0];
    $wpdb->setColumnDefinitions('wc_order_fulfillments', $columns);
    duo_check_same([], $provider->capabilities(),
        'duplicate schema rows cannot be collapsed into a valid fulfillment table');

    $wpdb = woo_fulfillment_ready_target();
    $columns = woo_fulfillment_columns('wc_order_fulfillments');
    unset($columns[1]['Type']);
    $wpdb->setColumnDefinitions('wc_order_fulfillments', $columns);
    duo_check_same([], $provider->capabilities(),
        'malformed schema rows fail closed without PHP coercion');

    $wpdb = woo_fulfillment_ready_target();
    $columns = woo_fulfillment_columns('wc_order_fulfillments');
    for ($columnIndex = count($columns); $columnIndex <= 4096; ++$columnIndex) {
        $columns[] = [
            'Field' => 'hostile_' . $columnIndex,
            'Type' => 'longtext',
            'Null' => 'YES',
            'Key' => '',
            'Default' => null,
            'Extra' => '',
        ];
    }
    $wpdb->setColumnDefinitions('wc_order_fulfillments', $columns);
    $wpdb->resetLog();
    duo_check_same([], $provider->capabilities(),
        'a hostile schema inventory beyond the explicit MySQL column ceiling fails closed');
    $oversizedColumnQueries = $wpdb->queries();
    duo_check((bool) array_filter(
        $oversizedColumnQueries,
        static fn(string $sql): bool => str_contains($sql, 'information_schema.COLUMNS')
    ) && !array_filter(
        $oversizedColumnQueries,
        static fn(string $sql): bool => str_contains($sql, 'SHOW FULL COLUMNS FROM `wp_wc_order_fulfillments`')
    ), 'oversized fulfillment columns refuse from compact cardinality before SHOW transfers schema rows');

    $wpdb = woo_fulfillment_ready_target();
    $indexes = woo_fulfillment_indexes('wc_order_fulfillments');
    $indexes[] = ['Key_name' => 'addon_index', 'Non_unique' => '1', 'Seq_in_index' => '1', 'Column_name' => 'status', 'Sub_part' => null, 'Index_type' => 'BTREE'];
    $wpdb->setIndexes('wc_order_fulfillments', $indexes);
    duo_check_same([], $provider->capabilities(),
        'unknown fulfillment table index cannot inherit the core schema claim');

    $wpdb = woo_fulfillment_ready_target();
    $indexes = woo_fulfillment_indexes('wc_order_fulfillments');
    array_pop($indexes);
    $wpdb->setIndexes('wc_order_fulfillments', $indexes);
    duo_check_same([], $provider->capabilities(),
        'a missing native fulfillment index cannot inherit the core schema claim');

    $wpdb = woo_fulfillment_ready_target();
    $indexes = woo_fulfillment_indexes('wc_order_fulfillments');
    unset($indexes[0]['Column_name']);
    $wpdb->setIndexes('wc_order_fulfillments', $indexes);
    duo_check_same([], $provider->capabilities(),
        'a malformed index row fails closed before string coercion');

    $wpdb = woo_fulfillment_ready_target();
    $indexes = woo_fulfillment_indexes('wc_order_fulfillments');
    $indexes[] = $indexes[0];
    $wpdb->setIndexes('wc_order_fulfillments', $indexes);
    duo_check_same([], $provider->capabilities(),
        'duplicate index rows cannot be collapsed into the native index contract');

    $wpdb = woo_fulfillment_ready_target();
    $indexes = woo_fulfillment_indexes('wc_order_fulfillments');
    while (count($indexes) <= 1040) {
        $indexes[] = [
            'Key_name' => 'hostile_' . count($indexes),
            'Non_unique' => '1',
            'Seq_in_index' => '1',
            'Column_name' => 'status',
            'Sub_part' => null,
            'Index_type' => 'BTREE',
        ];
    }
    $wpdb->setIndexes('wc_order_fulfillments', $indexes);
    $wpdb->resetLog();
    duo_check_same([], $provider->capabilities(),
        'a hostile index inventory beyond the explicit MySQL index ceiling fails closed');
    $oversizedIndexQueries = $wpdb->queries();
    duo_check((bool) array_filter(
        $oversizedIndexQueries,
        static fn(string $sql): bool => str_contains($sql, 'information_schema.STATISTICS')
    ) && !array_filter(
        $oversizedIndexQueries,
        static fn(string $sql): bool => str_contains($sql, 'SHOW INDEX FROM `wp_wc_order_fulfillments`')
    ), 'oversized fulfillment indexes refuse from compact cardinality before SHOW transfers index rows');

    foreach ([
        ['field' => 'Non_unique', 'value' => '00', 'label' => 'noncanonical Non_unique'],
        ['field' => 'Seq_in_index', 'value' => '1.0', 'label' => 'noncanonical Seq_in_index'],
        ['field' => 'Sub_part', 'value' => '0191', 'label' => 'noncanonical Sub_part'],
        ['field' => 'Index_type', 'value' => 'HASH', 'label' => 'non-BTREE index'],
        ['field' => 'Visible', 'value' => 'NO', 'label' => 'invisible index'],
        ['field' => 'Ignored', 'value' => 'YES', 'label' => 'ignored index'],
    ] as $hostileIndex) {
        $wpdb = woo_fulfillment_ready_target();
        $indexes = woo_fulfillment_indexes('wc_order_fulfillments');
        $indexes[0][$hostileIndex['field']] = $hostileIndex['value'];
        $wpdb->setIndexes('wc_order_fulfillments', $indexes);
        duo_check_same([], $provider->capabilities(),
            $hostileIndex['label'] . ' cannot satisfy the usable native index contract');
    }

    $wpdb = woo_fulfillment_ready_target();
    $wpdb->prefix = "wp_`hostile";
    $wpdb->options = "wp_`hostileoptions";
    $wpdb->resetLog();
    duo_check_same([], $provider->capabilities(),
        'a backtick-bearing site prefix is refused before SQL interpolation');
    duo_check_same([], $wpdb->queries(),
        'invalid database identifiers reach no checked SQL read');

    WpStore::reset();
    $longPrefix = str_repeat('p', 57);
    $wpdb = FakeWpdb::install($longPrefix);
    woo_fulfillment_set_options($wpdb);
    woo_fulfillment_install_tables($wpdb);
    $GLOBALS['wooFulfillmentTaxonomy'] = woo_fulfillment_taxonomy();
    $GLOBALS['wooFulfillmentContainer']->features->enabled = true;
    $GLOBALS['wooFulfillmentContainer']->database->maxIndexLength = 191;
    $wpdb->resetLog();
    duo_check_same([], $provider->capabilities(),
        'a derived fulfillment table name beyond the database identifier ceiling is refused');
    duo_check(count(array_filter(
        $wpdb->queries(),
        static fn(string $sql): bool => str_starts_with($sql, 'SHOW')
    )) === 0, 'an over-bound derived table name is never interpolated into SHOW SQL');

    $wpdb = woo_fulfillment_ready_target();
    $GLOBALS['wooFulfillmentContainer']->database->maxIndexLength = 250;
    duo_check_same([], $provider->capabilities(),
        'native filtered index length must agree with the checked table indexes');
    foreach (['wc_order_fulfillments', 'wc_order_fulfillment_meta'] as $table) {
        $wpdb->setIndexes($table, woo_fulfillment_indexes($table, 250));
    }
    duo_check(isset($provider->capabilities()['verify_fulfillment_prerequisites']),
        'a bounded native index-length filter is accepted when both exact schemas agree');

    $wpdb = woo_fulfillment_ready_target();
    $provider->capabilities();
    WpStore::instance()->options['woocommerce_feature_fulfillments_enabled'] = 'no';
    $wpdb->update('options', ['option_value' => 'no'], ['option_name' => 'woocommerce_feature_fulfillments_enabled']);
    $GLOBALS['wooFulfillmentContainer']->features->enabled = false;
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'invoke closes a feature-toggle race after successful negotiation',
        'feature to be enabled'
    );

    $wpdb = woo_fulfillment_ready_target();
    $featureWitness = 0;
    $wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$featureWitness): null {
        if (str_contains($sql, "WHERE option_name = 'woocommerce_feature_fulfillments_enabled'")) {
            $featureWitness++;
        }
        if ($featureWitness === 2) {
            $db->onQuery(null);
            WpStore::instance()->options['woocommerce_feature_fulfillments_enabled'] = 'no';
            $db->update('options', ['option_value' => 'no'], [
                'option_name' => 'woocommerce_feature_fulfillments_enabled',
            ]);
            $GLOBALS['wooFulfillmentContainer']->features->enabled = false;
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'invoke rechecks a feature flip between its two prerequisite snapshots',
        'feature to be enabled'
    );

    $wpdb = woo_fulfillment_ready_target();
    $markerWitness = 0;
    $wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$markerWitness): null {
        if (str_contains($sql, "WHERE option_name = 'woocommerce_fulfillments_db_tables_created'")) {
            $markerWitness++;
        }
        if ($markerWitness === 2) {
            $db->onQuery(null);
            WpStore::instance()->options['woocommerce_fulfillments_db_tables_created'] = '0';
            $db->update('options', ['option_value' => '0'], [
                'option_name' => 'woocommerce_fulfillments_db_tables_created',
            ]);
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'invoke rechecks a marker flip between its two prerequisite snapshots',
        'marker is absent or stale'
    );

    $wpdb = woo_fulfillment_ready_target();
    $featureWitness = 0;
    $wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$featureWitness): null {
        if (str_contains($sql, "WHERE option_name = 'woocommerce_feature_fulfillments_enabled'")) {
            $featureWitness++;
        }
        if ($featureWitness === 2) {
            $db->onQuery(null);
            $GLOBALS['wooFulfillmentContainer']->database->maxIndexLength = 250;
            foreach (['wc_order_fulfillments', 'wc_order_fulfillment_meta'] as $table) {
                $db->setIndexes($table, woo_fulfillment_indexes($table, 250));
            }
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('verify_fulfillment_prerequisites', []),
        RuntimeException::class,
        'two individually valid but different native schema snapshots cannot produce a false receipt',
        'changed during read-only verification'
    );

    $wpdb = woo_fulfillment_ready_target();
    $featureWitness = 0;
    $wpdb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$featureWitness): null {
        if (str_contains($sql, "WHERE option_name = 'woocommerce_feature_fulfillments_enabled'")) {
            $featureWitness++;
        }
        if ($featureWitness === 2) {
            $db->onQuery(null);
            WpStore::instance()->options['woocommerce_feature_fulfillments_enabled'] = 'no';
            $db->update('options', ['option_value' => 'no'], [
                'option_name' => 'woocommerce_feature_fulfillments_enabled',
            ]);
            $GLOBALS['wooFulfillmentContainer']->features->enabled = false;
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->reconcile_scoped('verify_fulfillment_prerequisites', [], $operation),
        RuntimeException::class,
        'reconcile independently rechecks a feature flip before retiring recovery',
        'feature to be enabled'
    );

    $wpdb = woo_fulfillment_ready_target();
    $saved = $provider->invoke_scoped('verify_fulfillment_prerequisites', [], $operation);
    $wpdb->query('DROP TABLE wp_wc_order_fulfillment_meta');
    duo_check_throws(
        static fn() => $provider->reconcile_scoped('verify_fulfillment_prerequisites', [], $operation),
        RuntimeException::class,
        'reconcile cannot retire a receipt after partial-table drift',
        'database table is absent'
    );
    woo_fulfillment_install_tables($wpdb);
    $retried = $provider->invoke_scoped('verify_fulfillment_prerequisites', [], $operation);
    duo_check_same($saved['after'], $retried['after'],
        'partial-table failure retries to the exact prior prerequisite fingerprint');

    $wpdb = woo_fulfillment_ready_target();
    $driverSecret = 'secret=FULFILLMENT_DRIVER_MARKER' . str_repeat('x', 20000);
    $wpdb->failNextQuery($driverSecret, 'option_value');
    duo_check_same([], $provider->capabilities(),
        'checked database failure makes the capability unavailable rather than guessing absence');
    $wpdb->failNextQuery($driverSecret, 'option_value');
    $failure = '';
    try {
        $provider->invoke('verify_fulfillment_prerequisites', []);
    } catch (Throwable $caught) {
        $failure = $caught->getMessage();
    }
    duo_check(strlen($failure) < 256
        && str_contains($failure, 'provider checked read failed')
        && !str_contains($failure, 'FULFILLMENT_DRIVER_MARKER'),
        'checked-read failure is bounded, loud/retryable, and never echoes driver detail');
    duo_check(($provider->invoke('verify_fulfillment_prerequisites', [])['verified'] ?? false) === true,
        'checked-read failure retries cleanly without target repair by the provider');

    $wpdb = woo_fulfillment_ready_target();
    $wpdb->failNextQuery($driverSecret, 'BINARY option_name = BINARY');
    $fetchFailure = '';
    try {
        $provider->invoke('verify_fulfillment_prerequisites', []);
    } catch (Throwable $caught) {
        $fetchFailure = $caught->getMessage();
    }
    duo_check(strlen($fetchFailure) < 256
        && str_contains($fetchFailure, 'provider checked read failed')
        && !str_contains($fetchFailure, 'FULFILLMENT_DRIVER_MARKER'),
        'bounded option payload read failure is loud, retryable, and redacted');
    duo_check(($provider->invoke('verify_fulfillment_prerequisites', [])['verified'] ?? false) === true,
        'bounded payload read failure leaves exact prerequisite state retryable');

    $artifactLock = json_decode((string) file_get_contents(
        $root . '/sandbox/conformance/artifacts.lock.json'
    ), true, 32, JSON_THROW_ON_ERROR);
    $lockedWoo = $artifactLock['plugins']['woocommerce'] ?? [];
    duo_check_same(
        'ba08c7fc58c98a11f22866269c5832d85c52b664806ec206036f09737ba21666',
        $lockedWoo['11.0.0']['sha256'] ?? null,
        'official WooCommerce 11.0.0 prerequisite source artifact is exact-digest pinned'
    );
    duo_check_same(
        'da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21',
        $lockedWoo['11.0.1']['sha256'] ?? null,
        'official WooCommerce 11.0.1 prerequisite source artifact is exact-digest pinned'
    );
    $fulfillmentNote = (string) ($policy->manifests[0]['notes']['Fulfillment, push, and variation-gallery boundaries'] ?? '');
    duo_check(str_contains($fulfillmentNote, '102878a616b30119fac2e93ac32b74ca267d41c2e33c67bf4311bec3f9498604')
        && str_contains($fulfillmentNote, 'efb36ce2fd680e07061a3d4c8ae483ae855a6f9ee6fea2c8dc7ca948ec8e6199')
        && str_contains($fulfillmentNote, '11.0.0 and 11.0.1'),
        'reviewed source inventory binds the byte-identical controller/settings files behind the exact lifecycle contract');

    duo_check_summary('WooCommerce fulfillment prerequisite provider');
}
