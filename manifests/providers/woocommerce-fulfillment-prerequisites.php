<?php
namespace Duo\Providers;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\Utilities\DatabaseUtil;

/**
 * Read-only WooCommerce 11.0.x fulfillment lifecycle prerequisite verifier.
 *
 * Woo registers the authored shipping-provider taxonomy only from the enabled
 * feature's init lifecycle. Its table marker short-circuits after checking the
 * main table alone, so a stale marker plus a missing meta table is a reachable
 * target state. Negotiation observes the exact native option/taxonomy/schema
 * projection before Apply mutates a term; invoke and reconcile repeat the same
 * observation so a feature toggle or partial-table race cannot be blessed.
 */
final class WoocommerceFulfillmentPrerequisites {
    private const CAPABILITY = 'verify_fulfillment_prerequisites';
    private const FEATURE_OPTION = 'woocommerce_feature_fulfillments_enabled';
    private const MARKER_OPTION = 'woocommerce_fulfillments_db_tables_created';
    private const TAXONOMY = 'wc_fulfillment_shipping_provider';
    private const MAX_OPTION_BYTES = 16;
    // MySQL's physical table/index ceilings make SHOW output finite; these
    // explicit caps keep hostile driver output from entering a receipt as an
    // apparently ordinary schema inventory.
    private const MAX_TABLE_COLUMNS = 4096;
    private const MAX_TABLE_INDEX_ROWS = 1040;
    /** Mirrors Ledger::TABLE_IDENTIFIER_WIDTH and the journal SQL grammar. */
    private const TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';

    /** @var array<string,array<string,array{type:string,null:string,default:?string,extra:string}>> */
    private const COLUMNS = [
        'wc_order_fulfillments' => [
            'fulfillment_id' => ['type' => 'bigint unsigned', 'null' => 'NO', 'default' => null, 'extra' => 'auto_increment'],
            'entity_type' => ['type' => 'varchar(255)', 'null' => 'NO', 'default' => null, 'extra' => ''],
            'entity_id' => ['type' => 'bigint unsigned', 'null' => 'NO', 'default' => null, 'extra' => ''],
            'status' => ['type' => 'varchar(255)', 'null' => 'NO', 'default' => null, 'extra' => ''],
            'is_fulfilled' => ['type' => 'tinyint', 'null' => 'NO', 'default' => '0', 'extra' => ''],
            'date_updated' => ['type' => 'datetime', 'null' => 'NO', 'default' => null, 'extra' => ''],
            'date_deleted' => ['type' => 'datetime', 'null' => 'YES', 'default' => null, 'extra' => ''],
        ],
        'wc_order_fulfillment_meta' => [
            'meta_id' => ['type' => 'bigint unsigned', 'null' => 'NO', 'default' => null, 'extra' => 'auto_increment'],
            'fulfillment_id' => ['type' => 'bigint unsigned', 'null' => 'NO', 'default' => null, 'extra' => ''],
            'meta_key' => ['type' => 'varchar(255)', 'null' => 'YES', 'default' => null, 'extra' => ''],
            'meta_value' => ['type' => 'longtext', 'null' => 'YES', 'default' => null, 'extra' => ''],
            'date_updated' => ['type' => 'datetime', 'null' => 'NO', 'default' => null, 'extra' => ''],
            'date_deleted' => ['type' => 'datetime', 'null' => 'YES', 'default' => null, 'extra' => ''],
        ],
    ];

    public function __construct(\Duo\Policy $policy) {
    }

    /** @return array{id:string,plugin:string,version:string} */
    public function identity(): array {
        return [
            'id' => 'woocommerce-fulfillment-prerequisites',
            'plugin' => 'woocommerce/woocommerce.php',
            'version' => '1.0.0',
        ];
    }

    public function capabilities(): array {
        $declaration = [
            'args' => [],
            'reads' => [
                'entity:woocommerce-fulfillment-runtime',
                'option:woocommerce_feature_fulfillments_enabled',
                'option:woocommerce_fulfillments_db_tables_created',
                'table:wc_order_fulfillment_meta',
                'table:wc_order_fulfillments',
            ],
            'writes' => [],
            'scope' => 'site',
            'idempotent' => true,
            'timeout_seconds' => 30,
            'scoped' => [
                'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                'reconcile' => true,
            ],
        ];

        // Offline manifest/library inspection has no target to diagnose. On a
        // real target, capability absence is the existing pre-mutation
        // negotiation refusal; no action method is called and no repository
        // term can be materialized into an unregistered taxonomy.
        if (\Duo\Providers::runtime_negotiation_available()) {
            try {
                self::snapshot();
            } catch (\Throwable $failure) {
                return [];
            }
        }
        return [self::CAPABILITY => $declaration];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        self::assert_call($capability, $args);
        $before = self::snapshot();
        $after = self::snapshot();
        if ($before !== $after) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisites changed during read-only verification; recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args */
    private static function assert_call(string $capability, array $args): void {
        if ($capability !== self::CAPABILITY) {
            throw new \RuntimeException(
                "duo: WooCommerce fulfillment prerequisite provider does not implement capability '$capability'"
            );
        }
        if ($args !== []) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisite verification accepts no arguments'
            );
        }
    }

    /** @return array<string,int|string> */
    private static function snapshot(): array {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->prefix, $wpdb->options)
            || !is_string($wpdb->prefix)
            || !is_string($wpdb->options)
            || $wpdb->options !== $wpdb->prefix . 'options'
            || preg_match(self::TABLE_IDENTIFIER_PATTERN, $wpdb->options) !== 1) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisite verification requires the exact site database identity'
            );
        }

        $featureRaw = self::raw_option(self::FEATURE_OPTION);
        $featureEffective = get_option(self::FEATURE_OPTION, null);
        if ($featureRaw !== 'yes' || $featureEffective !== 'yes') {
            throw new \RuntimeException(
                'duo: WooCommerce custom fulfillment providers require the target fulfillments feature to be enabled'
            );
        }

        $container = wc_get_container();
        if (!is_object($container) || !is_callable([$container, 'get'])) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisite verification requires the native service container'
            );
        }
        $features = $container->get(FeaturesController::class);
        if (!is_object($features)
            || !is_callable([$features, 'feature_is_enabled'])
            || $features->feature_is_enabled('fulfillments') !== true) {
            throw new \RuntimeException(
                'duo: WooCommerce native feature state disagrees with the fulfillment option'
            );
        }

        $taxonomy = self::taxonomy_state();
        $markerRaw = self::raw_option(self::MARKER_OPTION);
        $markerEffective = get_option(self::MARKER_OPTION, null);
        if ($markerRaw !== '1' || $markerEffective !== '1') {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment database lifecycle marker is absent or stale'
            );
        }

        $databaseUtil = $container->get(DatabaseUtil::class);
        if (!is_object($databaseUtil) || !is_callable([$databaseUtil, 'get_max_index_length'])) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisite verification requires the native database utility'
            );
        }
        $maxIndexLength = $databaseUtil->get_max_index_length();
        if (!is_int($maxIndexLength) || $maxIndexLength < 1 || $maxIndexLength > 767) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment database index length is outside the native bound'
            );
        }

        $schemas = [];
        foreach (array_keys(self::COLUMNS) as $suffix) {
            $schemas[$suffix] = self::table_schema($suffix, $maxIndexLength);
        }
        ksort($schemas, SORT_STRING);

        return [
            'feature_raw_sha256' => hash('sha256', $featureRaw),
            'feature_native_enabled' => 1,
            'marker_raw_sha256' => hash('sha256', $markerRaw),
            'taxonomy_sha256' => self::digest($taxonomy),
            'fulfillment_tables' => count($schemas),
            'fulfillment_schema_sha256' => self::digest($schemas),
        ];
    }

    private static function raw_option(string $name): string {
        global $wpdb;
        // The first read transfers only an identity and byte-count witness.
        // Plain equality intentionally discovers case/collation aliases; the
        // byte-exact returned name below then refuses them rather than letting
        // WordPress's default CI collation select a sibling option.
        $witnesses = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, "
            . "LENGTH(option_value) AS option_bytes FROM {$wpdb->options} "
            . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
            $name
        ), 'WooCommerce fulfillment option witness');
        if (count($witnesses) !== 1
            || ($witnesses[0]['option_name'] ?? null) !== $name) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisite option is absent, aliased, or duplicated'
            );
        }
        $optionId = self::db_uint($witnesses[0]['option_id'] ?? null, 'option_id');
        $optionBytes = self::db_uint($witnesses[0]['option_bytes'] ?? null, 'option_bytes');
        if ($optionId < 1 || $optionBytes > self::MAX_OPTION_BYTES) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisite option is outside the bounded byte contract'
            );
        }

        // Bind the payload fetch to the witnessed row, exact binary identity,
        // and safe size. A concurrent growth/change therefore yields no row
        // instead of transferring an unbounded LONGTEXT value.
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, option_value, "
            . "LENGTH(option_value) AS option_bytes FROM {$wpdb->options} "
            . 'WHERE option_id = %d AND BINARY option_name = BINARY %s '
            . 'AND LENGTH(option_value) = %d ORDER BY option_id ASC LIMIT 2',
            $optionId,
            $name,
            $optionBytes
        ), 'WooCommerce fulfillment option bounded readback');
        if (count($rows) !== 1
            || ($rows[0]['option_name'] ?? null) !== $name
            || ($rows[0]['option_id'] ?? null) !== (string) $optionId
            || ($rows[0]['option_bytes'] ?? null) !== (string) $optionBytes
            || !is_string($rows[0]['option_value'] ?? null)
            || strlen($rows[0]['option_value']) !== $optionBytes) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment prerequisite option changed during bounded readback'
            );
        }
        return $rows[0]['option_value'];
    }

    /** @return array<string,mixed> */
    private static function taxonomy_state(): array {
        if (!taxonomy_exists(self::TAXONOMY)) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment shipping-provider taxonomy is not registered by the native lifecycle'
            );
        }
        $taxonomy = get_taxonomy(self::TAXONOMY);
        if (!is_object($taxonomy)) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment shipping-provider taxonomy is unreadable'
            );
        }
        $capabilities = is_object($taxonomy->cap ?? null)
            ? get_object_vars($taxonomy->cap)
            : null;
        if (is_array($capabilities)) {
            ksort($capabilities, SORT_STRING);
        }
        $actual = [
            'name' => $taxonomy->name ?? null,
            'object_type' => $taxonomy->object_type ?? null,
            'description' => $taxonomy->description ?? null,
            'hierarchical' => $taxonomy->hierarchical ?? null,
            'public' => $taxonomy->public ?? null,
            'publicly_queryable' => $taxonomy->publicly_queryable ?? null,
            'show_ui' => $taxonomy->show_ui ?? null,
            'show_in_menu' => $taxonomy->show_in_menu ?? null,
            'show_in_rest' => $taxonomy->show_in_rest ?? null,
            'show_admin_column' => $taxonomy->show_admin_column ?? null,
            'show_in_nav_menus' => $taxonomy->show_in_nav_menus ?? null,
            'show_tagcloud' => $taxonomy->show_tagcloud ?? null,
            'show_in_quick_edit' => $taxonomy->show_in_quick_edit ?? null,
            'meta_box_cb' => $taxonomy->meta_box_cb ?? null,
            'meta_box_sanitize_cb' => $taxonomy->meta_box_sanitize_cb ?? null,
            'capabilities' => $capabilities,
            'query_var' => $taxonomy->query_var ?? null,
            'rewrite' => $taxonomy->rewrite ?? null,
            'update_count_callback' => $taxonomy->update_count_callback ?? null,
            'rest_base' => $taxonomy->rest_base ?? null,
            'rest_namespace' => $taxonomy->rest_namespace ?? null,
            'rest_controller_class' => $taxonomy->rest_controller_class ?? null,
            'rest_controller' => ($taxonomy->rest_controller ?? null) === null ? null : 'present',
            'default_term' => $taxonomy->default_term ?? null,
            'sort' => $taxonomy->sort ?? null,
            'args' => $taxonomy->args ?? null,
            '_builtin' => $taxonomy->_builtin ?? null,
        ];
        $expected = [
            'name' => self::TAXONOMY,
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
            'capabilities' => [
                'assign_terms' => 'edit_posts',
                'delete_terms' => 'manage_categories',
                'edit_terms' => 'manage_categories',
                'manage_terms' => 'manage_categories',
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
        if ($actual !== $expected) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment shipping-provider taxonomy registration disagrees with core 11.0.x'
            );
        }
        return $actual;
    }

    /** @return array{columns:array<string,array<string,?string>>,indexes:list<array<string,int|string|null>>} */
    private static function table_schema(string $suffix, int $maxIndexLength): array {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        if (preg_match(self::TABLE_IDENTIFIER_PATTERN, $table) !== 1) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment table identity is outside the bounded database identifier grammar'
            );
        }
        $foundRows = \Duo\ProviderSdk::checked_get_results(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)),
            'WooCommerce fulfillment table existence'
        );
        if (count($foundRows) !== 1
            || count($foundRows[0]) !== 1
            || array_values($foundRows[0])[0] !== $table) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment database table is absent or outside the exact site prefix'
            );
        }

        $rows = \Duo\ProviderSdk::checked_get_results(
            "SHOW FULL COLUMNS FROM `$table`",
            'WooCommerce fulfillment table schema'
        );
        if (count($rows) > self::MAX_TABLE_COLUMNS) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment table returned an oversized column inventory'
            );
        }
        $actual = [];
        foreach ($rows as $row) {
            $field = $row['Field'] ?? null;
            if (!is_string($field) || $field === '' || isset($actual[$field])
                || !is_string($row['Type'] ?? null)
                || !is_string($row['Null'] ?? null)
                || (!is_string($row['Default'] ?? null) && ($row['Default'] ?? null) !== null)
                || !array_key_exists('Default', $row)
                || !is_string($row['Extra'] ?? null)) {
                throw new \RuntimeException(
                    'duo: WooCommerce fulfillment table returned an unreadable or duplicate column'
                );
            }
            $actual[$field] = [
                'type' => self::normalized_type($row['Type']),
                'null' => strtoupper($row['Null']),
                'default' => $row['Default'],
                'extra' => strtolower($row['Extra']),
            ];
        }
        $expected = self::COLUMNS[$suffix] ?? null;
        if (!is_array($expected) || $actual !== $expected) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment table has unknown, missing, or incompatible columns'
            );
        }

        $indexRows = \Duo\ProviderSdk::checked_get_results(
            "SHOW INDEX FROM `$table`",
            'WooCommerce fulfillment table indexes'
        );
        if (count($indexRows) > self::MAX_TABLE_INDEX_ROWS) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment table returned an oversized index inventory'
            );
        }
        $indexes = [];
        foreach ($indexRows as $row) {
            $key = $row['Key_name'] ?? null;
            $column = $row['Column_name'] ?? null;
            if (!is_string($key) || $key === '' || !is_string($column) || $column === ''
                || !array_key_exists('Non_unique', $row)
                || !array_key_exists('Seq_in_index', $row)
                || !array_key_exists('Sub_part', $row)
                || !is_string($row['Index_type'] ?? null)
                || (array_key_exists('Visible', $row) && !is_string($row['Visible']))
                || (array_key_exists('Ignored', $row) && !is_string($row['Ignored']))) {
                throw new \RuntimeException('duo: WooCommerce fulfillment table returned an unreadable index');
            }
            if ((array_key_exists('Visible', $row) && strtoupper((string) $row['Visible']) !== 'YES')
                || (array_key_exists('Ignored', $row) && strtoupper((string) $row['Ignored']) !== 'NO')) {
                throw new \RuntimeException(
                    'duo: WooCommerce fulfillment table requires visible, non-ignored native indexes'
                );
            }
            $nonUnique = self::db_uint($row['Non_unique'], 'Non_unique');
            $sequence = self::db_uint($row['Seq_in_index'], 'Seq_in_index');
            $subPart = $row['Sub_part'] === null
                ? null
                : self::db_uint($row['Sub_part'], 'Sub_part');
            $indexType = strtoupper($row['Index_type']);
            if (!in_array($nonUnique, [0, 1], true)
                || $sequence < 1 || $sequence > 64
                || ($subPart !== null && ($subPart < 1 || $subPart > 767))
                || $indexType !== 'BTREE') {
                throw new \RuntimeException(
                    'duo: WooCommerce fulfillment table returned an incompatible index definition'
                );
            }
            $indexes[] = [
                'key' => $key,
                'non_unique' => $nonUnique,
                'sequence' => $sequence,
                'column' => $column,
                'sub_part' => $subPart,
                'type' => $indexType,
            ];
        }
        usort($indexes, static fn(array $left, array $right): int => [
            $left['key'], $left['sequence'],
        ] <=> [
            $right['key'], $right['sequence'],
        ]);
        $expectedIndexes = self::expected_indexes($suffix, $maxIndexLength);
        if ($indexes !== $expectedIndexes) {
            throw new \RuntimeException(
                'duo: WooCommerce fulfillment table has unknown, missing, or incompatible indexes'
            );
        }
        return ['columns' => $actual, 'indexes' => $indexes];
    }

    private static function normalized_type(string $type): string {
        $type = strtolower(trim($type));
        $type = preg_replace('/^bigint(?:\(20\))? unsigned$/D', 'bigint unsigned', $type) ?? $type;
        $type = preg_replace('/^tinyint(?:\(1\))?$/D', 'tinyint', $type) ?? $type;
        return $type;
    }

    private static function db_uint(mixed $value, string $field): int {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value)
            && strlen($value) <= strlen((string) PHP_INT_MAX)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $number = (int) $value;
            if ((string) $number !== $value) {
                throw new \RuntimeException(
                    "duo: WooCommerce fulfillment table returned an out-of-range $field index integer"
                );
            }
        } else {
            throw new \RuntimeException(
                "duo: WooCommerce fulfillment table returned a noncanonical $field index integer"
            );
        }
        if ($number < 0) {
            throw new \RuntimeException(
                "duo: WooCommerce fulfillment table returned an out-of-range $field index integer"
            );
        }
        return $number;
    }

    /** @return list<array{key:string,non_unique:int,sequence:int,column:string,sub_part:?int,type:string}> */
    private static function expected_indexes(string $suffix, int $maxIndexLength): array {
        if ($suffix === 'wc_order_fulfillments') {
            return [
                ['key' => 'PRIMARY', 'non_unique' => 0, 'sequence' => 1, 'column' => 'fulfillment_id', 'sub_part' => null, 'type' => 'BTREE'],
                ['key' => 'entity_type_id', 'non_unique' => 1, 'sequence' => 1, 'column' => 'entity_type', 'sub_part' => $maxIndexLength, 'type' => 'BTREE'],
                ['key' => 'entity_type_id', 'non_unique' => 1, 'sequence' => 2, 'column' => 'entity_id', 'sub_part' => null, 'type' => 'BTREE'],
            ];
        }
        return [
            ['key' => 'PRIMARY', 'non_unique' => 0, 'sequence' => 1, 'column' => 'meta_id', 'sub_part' => null, 'type' => 'BTREE'],
            ['key' => 'fulfillment_id', 'non_unique' => 1, 'sequence' => 1, 'column' => 'fulfillment_id', 'sub_part' => null, 'type' => 'BTREE'],
            ['key' => 'meta_key', 'non_unique' => 1, 'sequence' => 1, 'column' => 'meta_key', 'sub_part' => $maxIndexLength, 'type' => 'BTREE'],
        ];
    }

    private static function digest(array $value): string {
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }
}
