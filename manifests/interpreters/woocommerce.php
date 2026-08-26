<?php
declare(strict_types=1);

namespace Duo\Interpreters;

use Duo\Canon;
use Duo\PlainData;
use Duo\Policy;

/**
 * Exact WooCommerce 11.0.0 and 11.0.1 read `_product_attributes` as a
 * six-field row map.
 * Its native reader silently drops a non-array value and fills missing row
 * fields with defaults, so canonical JSON can otherwise be valid while the
 * promoted catalog loses merchant-authored attributes. Both native attribute
 * and download writers merge extension `extra_data` into postmeta, so unknown
 * row fields are addon-owned schema and must refuse rather than inherit this
 * core adapter's production claim.
 */
final class Woocommerce {
    private const REQUIRED_FIELDS = [
        'name',
        'value',
        'position',
        'is_visible',
        'is_variation',
        'is_taxonomy',
    ];

    private const DOWNLOAD_FIELDS = [
        'id',
        'name',
        'file',
        'enabled',
    ];

    private const HANDLED_POST_META = [
        '_button_text',
        '_cogs_total_value',
        '_cogs_value_is_additive',
        '_downloadable_files',
        '_product_attributes',
        '_product_url',
    ];

    private const HANDLED_TERM_META = [
        'color',
        'display_type',
        'icon',
        'image',
        'order',
        'product_ids',
        'thumbnail_id',
        'tracking_url_template',
    ];

    private const FULFILLMENT_OPTIONS = [
        'auto_fulfill_downloadable',
        'auto_fulfill_virtual',
    ];

    private const STOCK_NOTIFICATION_CYCLE_PREFIX = 'wc_stock_notifications_cycle_state_';

    private const YES_NO_OPTIONS = [
        'auto_fulfill_downloadable',
        'auto_fulfill_virtual',
        'woocommerce_analytics_scheduled_import',
        'woocommerce_customer_stock_notifications_allow_signups',
        'woocommerce_customer_stock_notifications_create_account_on_signup',
        'woocommerce_customer_stock_notifications_require_account',
        'woocommerce_customer_stock_notifications_require_double_opt_in',
        'woocommerce_enable_order_comments',
        'woocommerce_graphql_apq_enabled',
        'woocommerce_graphql_get_endpoint_enabled',
        'woocommerce_graphql_object_cache_enabled',
        'woocommerce_graphql_opcache_enabled',
        'woocommerce_rest_api_enable_cache_headers',
    ];

    private const ENUM_OPTIONS = [
        'woocommerce_category_archive_display' => ['', 'subcategories', 'both'],
        'woocommerce_date_type' => ['date_created', 'date_paid', 'date_completed'],
        'woocommerce_default_catalog_orderby' => [
            'menu_order',
            'popularity',
            'rating',
            'date',
            'price',
            'price-desc',
        ],
        'woocommerce_product_type' => ['simple', 'grouped', 'external', 'variable'],
        'woocommerce_shop_page_display' => ['', 'subcategories', 'both'],
        'woocommerce_thumbnail_cropping' => ['1:1', 'custom', 'uncropped'],
    ];

    private const IMAGE_DIMENSION_OPTIONS = [
        'woocommerce_single_image_width' => 32768,
        'woocommerce_thumbnail_cropping_custom_height' => 1000,
        'woocommerce_thumbnail_cropping_custom_width' => 1000,
        'woocommerce_thumbnail_image_width' => 32768,
    ];

    private const MAX_IMAGE_DIMENSION = 32768;

    private const ORDER_STATUS_OPTIONS = [
        'woocommerce_actionable_order_statuses',
        'woocommerce_excluded_report_order_statuses',
    ];

    private const NATIVE_TEXT_OPTIONS = [
        'woocommerce_default_date_range' => 1024,
        'woocommerce_email_from_name' => 4096,
        'woocommerce_pos_store_name' => 4096,
    ];

    private const NATIVE_HTML_OPTIONS = [
        'woocommerce_checkout_terms_and_conditions_checkbox_text' => 16384,
        'woocommerce_demo_store_notice' => 262144,
    ];

    private const POSITIVE_INTEGER_OPTIONS = [
        'woocommerce_graphql_max_query_complexity',
        'woocommerce_graphql_max_query_depth',
        'woocommerce_graphql_query_cache_ttl',
    ];

    private const PICKUP_SETTINGS_FIELDS = [
        'cost',
        'enabled',
        'tax_status',
        'title',
    ];

    private const PICKUP_LOCATION_FIELDS = [
        'address',
        'details',
        'enabled',
        'name',
    ];

    private const PICKUP_ADDRESS_FIELDS = [
        'address_1',
        'city',
        'country',
        'postcode',
        'state',
    ];

    private const MAX_PICKUP_LOCATIONS = 256;
    private const MAX_PICKUP_BYTES = 1048576;

    private const MAX_MIXED_OPTION_BYTES = 1048576;
    private const MAX_MIXED_TEXT_BYTES = 262144;
    private const MAX_COD_METHODS = 256;
    private const MAX_PERMALINK_BYTES = 2048;

    private const WOO_CONTAINER = 'Automattic\\WooCommerce\\Container';
    private const WOO_RUNTIME_CONTAINER =
        'Automattic\\WooCommerce\\Internal\\DependencyManagement\\RuntimeContainer';
    private const WOO_FEATURES = 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController';
    private const WOO_SYNCHRONIZER =
        'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
    private const WOO_CUSTOM_ORDERS =
        'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';
    private const WOO_SETTINGS_TRACKING = 'WC_Settings_Tracking';
    private const WOO_SETTINGS_API_SHA256 =
        '1c7615bcd26fba9f83961db045edf6bd42acc6ee691383e8a8dd6f6a1e00434c';
    private const WOO_FORMATTING_FUNCTIONS_SHA256 =
        'c3576416420bbfb6893ad5164ccf8c439b7e731c337c04b32e058ac6a0809d41';
    private const WPSEO_SITEMAPS = 'WPSEO_Sitemaps';
    private const WPSEO_SITEMAPS_CACHE = 'WPSEO_Sitemaps_Cache';
    private const WPSEO_CONTAINER_REGISTRY = 'Yoast\\WP\\Lib\\Dependency_Injection\\Container_Registry';
    private const WPSEO_CONTAINER = 'Yoast\\WP\\SEO\\Generated\\Cached_Container';
    private const WPSEO_CONTAINER_BASE = 'YoastSEO_Vendor\\Symfony\\Component\\DependencyInjection\\Container';
    private const WPSEO_WOO_PERMALINKS =
        'Yoast\\WP\\SEO\\Integrations\\Third_Party\\Woocommerce_Permalinks';
    private const WPSEO_INDEXABLE_HELPER = 'Yoast\\WP\\SEO\\Helpers\\Indexable_Helper';
    private const TEC_CONTAINER = 'Tribe__Container';
    private const TEC_CONTRACT_CONTAINER = 'TEC\\Common\\Contracts\\Container';
    private const TEC_DI_CONTAINER = 'TEC\\Common\\lucatume\\DI52\\Container';
    private const TEC_RESOLVER = 'TEC\\Common\\lucatume\\DI52\\Builders\\Resolver';
    private const TEC_VALUE_BUILDER = 'TEC\\Common\\lucatume\\DI52\\Builders\\ValueBuilder';
    private const TEC_SETTINGS = 'Tribe__Settings_Manager';
    private const TEC_CACHE_LISTENER = 'Tribe__Cache_Listener';
    private const TEC_CACHE = 'Tribe__Cache';
    private const TEC_AGGREGATOR = 'Tribe__Events__Aggregator';
    private const TEC_VIEWS = 'Tribe\\Events\\Views\\V2\\Hooks';
    /** @var array<string,string> */
    private const WPSEO_OPTIONS = [
        'wpseo' => 'WPSEO_Option_Wpseo',
        'wpseo_titles' => 'WPSEO_Option_Titles',
        'wpseo_social' => 'WPSEO_Option_Social',
        'wpseo_taxonomy_meta' => 'WPSEO_Taxonomy_Meta',
        'wpseo_llmstxt' => 'WPSEO_Option_Llmstxt',
        'wpseo_tracking_only' => 'WPSEO_Option_Tracking_Only',
    ];

    private const PERMALINK_OPTION_FIELDS = [
        'product_base' => 'permalink',
        'category_base' => 'permalink',
        'tag_base' => 'permalink',
        'attribute_base' => 'permalink',
        'use_verbose_page_rules' => 'permalink_bool',
    ];

    private const GATEWAY_OPTION_FIELDS = [
        'woocommerce_bacs_settings' => [
            'enabled' => 'checkbox',
            'title' => 'safe_text',
            'description' => 'textarea',
            'instructions' => 'textarea',
            'account_details' => 'derived_empty',
            'account_name' => 'env_text',
            'account_number' => 'env_text',
            'bank_name' => 'env_text',
            'sort_code' => 'env_text',
            'iban' => 'env_text',
            'bic' => 'env_text',
        ],
        'woocommerce_cheque_settings' => [
            'enabled' => 'checkbox',
            'title' => 'safe_text',
            'description' => 'textarea',
            'instructions' => 'textarea',
        ],
        'woocommerce_cod_settings' => [
            'enabled' => 'checkbox',
            'title' => 'safe_text',
            'description' => 'textarea',
            'instructions' => 'textarea',
            'enable_for_methods' => 'cod_methods',
            'enable_for_virtual' => 'checkbox',
        ],
    ];

    private const EMAIL_FIELD_TYPES = [
        'additional_content' => 'textarea',
        'automated' => 'checkbox',
        'bcc' => 'text',
        'cc' => 'text',
        'delay_days' => 'delay_days',
        'email_type' => 'email_type',
        'enabled' => 'checkbox',
        'heading' => 'text',
        'heading_full' => 'text',
        'heading_paid' => 'text',
        'heading_partial' => 'text',
        'intro_content' => 'textarea',
        'preheader' => 'text',
        'recipient' => 'env_text',
        'subject' => 'text',
        'subject_full' => 'text',
        'subject_paid' => 'text',
        'subject_partial' => 'text',
    ];

    private const EMAIL_OPTION_FIELDS = [
        'woocommerce_admin_payment_gateway_enabled_settings' => ['enabled', 'recipient', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_cancelled_order_settings' => ['enabled', 'recipient', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_abandoned_cart_recovery_settings' => ['enabled', 'automated', 'subject', 'heading', 'additional_content', 'email_type'],
        'woocommerce_customer_cancelled_order_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_completed_order_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_failed_order_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_fulfillment_created_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_fulfillment_deleted_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_fulfillment_updated_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_invoice_settings' => ['subject', 'heading', 'subject_paid', 'heading_paid', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_new_account_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_note_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_on_hold_order_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_pos_completed_order_settings' => ['subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc'],
        'woocommerce_customer_pos_refunded_order_settings' => ['subject_full', 'subject_partial', 'heading_full', 'heading_partial', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_processing_order_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_refunded_order_settings' => ['enabled', 'subject_full', 'subject_partial', 'heading_full', 'heading_partial', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_reset_password_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_review_request_settings' => ['enabled', 'delay_days', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_stock_notification_settings' => ['enabled', 'subject', 'heading', 'intro_content', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_stock_notification_verified_settings' => ['enabled', 'subject', 'heading', 'intro_content', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_stock_notification_verify_settings' => ['enabled', 'subject', 'heading', 'intro_content', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_customer_verify_email_settings' => ['enabled', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_failed_order_settings' => ['enabled', 'recipient', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
        'woocommerce_new_order_settings' => ['enabled', 'recipient', 'subject', 'heading', 'additional_content', 'email_type', 'cc', 'bcc', 'preheader'],
    ];

    private const PRODUCT_VISIBILITY_TERMS = [
        'exclude-from-search',
        'exclude-from-catalog',
        'featured',
        'outofstock',
        'rated-1',
        'rated-2',
        'rated-3',
        'rated-4',
        'rated-5',
    ];

    public function __construct(private readonly Policy $policy) {}

    /**
     * Normalize only the reviewed portable siblings before the engine's
     * ordinary token codec runs. COD is the exceptional composite shape:
     * Woo stores `method_id[:instance_id]`, while only instance_id is local.
     *
     * @return array<string,mixed>
     */
    public function normalize_captured_option_sub_keys(
        string $name,
        array $captured,
        array $subKeys,
        array $rawOptionSnapshot
    ): array {
        $fields = $this->mixed_option_fields($name);
        if ($fields === null) {
            return $captured;
        }
        $this->assert_mixed_sub_key_contract($name, $fields, $subKeys);
        if (!array_key_exists($name, $rawOptionSnapshot)) {
            if ($captured !== []) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name' absent raw storage disagrees with authored capture"
                );
            }
            return [];
        }
        $raw = $rawOptionSnapshot[$name];
        if (!is_string($raw) || strlen($raw) > self::MAX_MIXED_OPTION_BYTES) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' has missing or oversized raw capture bytes"
            );
        }
        $decoded = PlainData::decode_serialized($raw, "WooCommerce mixed option '$name' source storage");
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' source storage is not an exact named record"
            );
        }
        $this->assert_mixed_record_keys($name, $decoded, $fields, 'source');
        $this->assert_permalink_record_complete($name, $decoded, $fields, 'source');

        $rawAuthored = [];
        foreach ($decoded as $key => $value) {
            $type = $fields[(string) $key];
            if ($this->is_target_owned_mixed_type($type)) {
                $this->assert_target_owned_mixed_field(
                    $name,
                    (string) $key,
                    $type,
                    $value,
                    'target-owned source'
                );
                continue;
            }
            $rawAuthored[(string) $key] = $value;
        }
        $expectedCaptured = $rawAuthored;
        ksort($expectedCaptured, SORT_STRING);
        $actualCaptured = $captured;
        ksort($actualCaptured, SORT_STRING);
        if ($actualCaptured !== $expectedCaptured) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' authored capture disagrees with exact raw storage"
            );
        }

        $this->assert_mixed_validation_topology($fields, $rawAuthored);
        $api = $this->native_settings_api();
        foreach ($rawAuthored as $key => $value) {
            if ($fields[$key] === 'cod_methods') {
                $captured[$key] = $this->canonical_cod_methods($value, 'source');
                continue;
            }
            $this->assert_native_mixed_field($api, $name, $key, $fields[$key], $value, 'source');
        }
        ksort($captured, SORT_STRING);
        return $captured;
    }

    /**
     * Persist the native record shape while leaving target-owned siblings
     * byte-for-byte sovereign. Engine callbacks own the one SQL write, raw
     * readback, cache transaction, and rollback; this stateless validator
     * registers a no-op runtime restore before that write.
     */
    public function materialize_option_sub_keys(
        string $name,
        array $captured,
        array $subKeys,
        string $autoload,
        ?array $targetValue,
        \Closure $lockTargetOption,
        \Closure $finalizeStorage,
        \Closure $restoreStorage,
        ?\Closure $registerRuntimeRestore = null,
        ?\Closure $writeStorage = null
    ): bool {
        $fields = $this->mixed_option_fields($name);
        if ($fields === null) {
            return false;
        }
        if ($registerRuntimeRestore === null || $writeStorage === null) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' requires engine-owned storage and rollback callbacks"
            );
        }
        $this->assert_mixed_sub_key_contract($name, $fields, $subKeys);
        $this->assert_mixed_record_keys($name, $captured, $fields, 'repository');
        $this->assert_permalink_record_complete($name, $captured, $fields, 'repository');

        $this->assert_mixed_validation_topology($fields, $captured);
        $api = $this->native_settings_api();
        $nativeAuthored = [];
        foreach ($captured as $key => $value) {
            $type = $fields[(string) $key];
            if ($this->is_target_owned_mixed_type($type)) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name' repository contains target-owned sibling '$key'"
                );
            }
            if ($type === 'cod_methods') {
                $nativeAuthored[(string) $key] = $this->native_cod_methods($value, 'target');
                continue;
            }
            $this->assert_native_mixed_field($api, $name, (string) $key, $type, $value, 'repository');
            $nativeAuthored[(string) $key] = $value;
        }

        $targetWasPresent = $targetValue !== null;
        $targetValue ??= [];
        $this->assert_mixed_record_keys($name, $targetValue, $fields, 'target');
        $next = [];
        foreach ($fields as $key => $type) {
            if (!$this->is_target_owned_mixed_type($type) || !array_key_exists($key, $targetValue)) {
                continue;
            }
            $this->assert_target_owned_mixed_field(
                $name,
                $key,
                $type,
                $targetValue[$key],
                'target-owned target'
            );
            $next[$key] = $targetValue[$key];
        }
        foreach ($fields as $key => $type) {
            if ($this->is_target_owned_mixed_type($type) || !array_key_exists($key, $nativeAuthored)) {
                continue;
            }
            $next[$key] = $nativeAuthored[$key];
        }
        PlainData::assert($next, "WooCommerce mixed option '$name' native materialization");
        $wire = serialize($next);
        if (strlen($wire) > self::MAX_MIXED_OPTION_BYTES) {
            throw new \RuntimeException("duo: WooCommerce mixed option '$name' exceeds its native storage bound");
        }

        // OptionsMaterializer deliberately owns the SQL/cache transaction and
        // therefore does not call update_option(). Refuse any callback that
        // native WordPress would have crossed unless exact 11.0.x source proves
        // it is a no-op for this closed option family or its normalization is
        // reproduced above. This proof is immediately adjacent to the write so
        // no plugin code can alter the in-process topology between them.
        $this->assert_mixed_option_mutation_hooks($name, $targetWasPresent);

        $registerRuntimeRestore(static function (): void {
            // WC_Settings_API validation is stateless; the engine owns and
            // restores storage/cache state if the enclosing transaction fails.
        });
        $writeStorage($next);
        if ($name === 'woocommerce_cod_settings' && array_key_exists('enable_for_methods', $captured)) {
            $confirmedMethods = $this->native_cod_methods(
                $captured['enable_for_methods'] ?? null,
                'target immediate recheck'
            );
            if ($confirmedMethods !== ($nativeAuthored['enable_for_methods'] ?? null)) {
                throw new \RuntimeException(
                    'duo: WooCommerce COD target shipping-method witness changed during native storage; '
                    . 'recovery_required'
                );
            }
        }
        $row = $finalizeStorage();
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value', 'autoload']
            || ($row['option_name'] ?? null) !== $name
            || !is_string($row['option_value'] ?? null)
            || strlen($row['option_value']) > self::MAX_MIXED_OPTION_BYTES
            || ($row['autoload'] ?? null) !== $autoload) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' final raw storage witness is malformed"
            );
        }
        $stored = PlainData::decode_serialized(
            $row['option_value'],
            "WooCommerce mixed option '$name' finalized storage"
        );
        if (is_array($stored)) {
            $this->assert_permalink_record_complete($name, $stored, $fields, 'finalized storage');
        }
        if ($stored !== $next) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' finalized storage disagrees with its native projection"
            );
        }
        return true;
    }

    /**
     * Map finalized native authored siblings back to the engine's materialized
     * comparison shape. The engine separately proves an identical key set and
     * plain-data result before accepting this adapter-owned projection.
     *
     * @return array<string,mixed>
     */
    public function project_materialized_option_sub_keys(
        string $name,
        array $rawAuthored,
        array $subKeys,
        array $desiredAuthoredKeys
    ): array {
        $fields = $this->mixed_option_fields($name);
        if ($fields === null) {
            return $rawAuthored;
        }
        $this->assert_mixed_sub_key_contract($name, $fields, $subKeys);
        $this->assert_mixed_record_keys($name, $rawAuthored, $fields, 'finalized authored storage');
        if (!array_is_list($desiredAuthoredKeys)) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' projection requires an exact desired authored-key list"
            );
        }
        $desired = [];
        foreach ($desiredAuthoredKeys as $position => $key) {
            if (!is_string($key)
                || isset($desired[$key])
                || (($subKeys[$key]['class'] ?? null) !== 'authored')) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name' projection has a malformed desired key at position $position"
                );
            }
            $desired[$key] = true;
        }
        $this->assert_mixed_validation_topology($fields, $rawAuthored);
        $api = $this->native_settings_api();
        $projected = [];
        foreach ($rawAuthored as $key => $value) {
            $type = $fields[(string) $key];
            if ($this->is_target_owned_mixed_type($type)) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name' projection received target-owned sibling '$key'"
                );
            }
            if ($type === 'cod_methods') {
                $projected[(string) $key] = $this->canonical_cod_methods($value, 'finalized target');
                continue;
            }
            $this->assert_native_mixed_field(
                $api,
                $name,
                (string) $key,
                $type,
                $value,
                'finalized target'
            );
            if (!isset($desired[(string) $key])) {
                continue;
            }
            $projected[(string) $key] = $value;
        }
        foreach ($desired as $key => $_present) {
            if (!array_key_exists($key, $projected)) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name' projection is missing a desired authored sibling"
                );
            }
        }
        ksort($projected, SORT_STRING);
        return $projected;
    }

    /** @return ?array<string,string> */
    private function mixed_option_fields(string $name): ?array {
        if ($name === 'woocommerce_permalinks') {
            return self::PERMALINK_OPTION_FIELDS;
        }
        if (isset(self::GATEWAY_OPTION_FIELDS[$name])) {
            return self::GATEWAY_OPTION_FIELDS[$name];
        }
        $emailFields = self::EMAIL_OPTION_FIELDS[$name] ?? null;
        if ($emailFields === null) {
            return null;
        }
        $fields = [];
        foreach ($emailFields as $field) {
            $fields[$field] = self::EMAIL_FIELD_TYPES[$field];
        }
        return $fields;
    }

    private function assert_mixed_sub_key_contract(string $name, array $fields, array $subKeys): void {
        $expected = [];
        foreach ($fields as $key => $type) {
            if ($type === 'cod_methods') {
                $expected[$key] = [
                    'class' => 'authored',
                    'json_refs' => [[
                        'path' => '$.*.instance_id',
                        'kind' => 'wc_zone_method',
                    ]],
                ];
                continue;
            }
            if ($type === 'env_text') {
                $expected[$key] = ['class' => 'env'];
                continue;
            }
            if ($type === 'derived_empty') {
                $expected[$key] = ['class' => 'derived', 'native_default_completion' => true];
                continue;
            }
            $expected[$key] = ['class' => 'authored'];
        }
        // sub_keys is a JSON object, so key insertion order carries no
        // authority. Compiled snapshots canonicalize nested maps; compare the
        // canonical contract bytes so a source-manifest order and a compiled
        // order cannot disagree while every key/rule remains exact.
        if (!hash_equals(Canon::encode($subKeys), Canon::encode($expected))) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' sub-key contract disagrees with the exact 11.0.x registry"
            );
        }
    }

    private function assert_mixed_record_keys(string $name, array $value, array $fields, string $where): void {
        if ($value !== [] && array_is_list($value)) {
            throw new \RuntimeException("duo: WooCommerce mixed option '$name' $where is not a named record");
        }
        foreach ($value as $key => $_value) {
            if (!is_string($key) || !array_key_exists($key, $fields)) {
                $fingerprint = 'key:' . strlen((string) $key) . ':'
                    . substr(hash('sha256', (string) $key), 0, 16);
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name' $where contains undeclared sibling key(s) ($fingerprint)"
                );
            }
        }
    }

    private function assert_permalink_record_complete(
        string $name,
        array $value,
        array $fields,
        string $where
    ): void {
        if ($name !== 'woocommerce_permalinks') {
            return;
        }
        $actualKeys = array_keys($value);
        $expectedKeys = array_keys($fields);
        if ($where === 'repository') {
            sort($actualKeys, SORT_STRING);
            $sortedExpected = $expectedKeys;
            sort($sortedExpected, SORT_STRING);
            $expectedKeys = $sortedExpected;
        }
        if ($actualKeys !== $expectedKeys) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name' $where is not the exact native five-field record"
            );
        }
    }

    private function assert_mixed_option_mutation_hooks(string $name, bool $targetWasPresent): void {
        foreach ([
            "sanitize_option_$name",
            "pre_option_$name",
            'pre_option',
            'pre_wp_load_alloptions',
            'pre_cache_alloptions',
            'alloptions',
            "default_option_$name",
            'wp_autoload_values_to_autoload',
            'wp_max_autoloaded_option_size',
        ] as $hook) {
            $this->assert_closed_mixed_option_hook($hook, []);
        }
        $this->assert_closed_mixed_option_hook('wp_default_autoload_value', [[
            'wp_filter_default_autoload_value_via_option_size', '', 5, 4, 'wordpress_function',
        ]]);

        // These generic callbacks run even though the checked writer bypasses
        // WordPress's dispatcher.  A visible runtime is therefore an exact
        // finite union, not permission to silently discard another plugin's
        // observer.  Both resolvers only inspect already-built services.
        $woo = $this->resolve_mixed_option_woo_services();
        $wpseo = $this->resolve_mixed_option_wpseo_services($name);
        $tracking = $targetWasPresent ? $this->resolve_mixed_option_tracking_service($name) : null;
        $tec = $targetWasPresent ? $this->resolve_mixed_option_tec_services() : null;

        if ($targetWasPresent) {
            $this->assert_closed_mixed_option_hook("option_$name", []);
            $this->assert_closed_mixed_option_hook("pre_update_option_$name", $name === 'woocommerce_permalinks'
                ? [['WC_Brands_Admin', 'validate_product_base', 10, 1, 'brands_global']]
                : []);
            $preUpdated = $woo === null ? [] : [
                [self::WOO_CUSTOM_ORDERS, 'process_pre_update_option', 999, 3, 'woo'],
            ];
            $update = [];
            if ($wpseo !== null) {
                foreach ($wpseo['options'] as $class => $_service) {
                    $preUpdated[] = [$class, 'add_default_filters_if_not_changed', PHP_INT_MAX, 3, 'yoast'];
                    $update[] = [$class, 'add_default_filters_if_same_option', 10, 1, 'yoast'];
                }
                if ($wpseo['sitemaps_cache'] !== null) {
                    $update[] = [self::WPSEO_SITEMAPS_CACHE, 'clear_on_option_update', 10, 1, 'yoast_static'];
                }
            }
            if ($tracking !== null) {
                $update[] = [self::WOO_SETTINGS_TRACKING, 'track_setting_change', 10, 3, 'tracking'];
            }
            $this->assert_closed_mixed_option_hook('pre_update_option', $preUpdated, $woo, $wpseo);
            $this->assert_closed_mixed_option_hook('update_option', $update, $woo, $wpseo, $tracking);
            if ($name === 'woocommerce_permalinks') {
                $this->assert_mixed_option_yoast_permalink_hook();
            } else {
                $this->assert_closed_mixed_option_hook("update_option_$name", []);
            }
            $updated = $woo === null ? [] : [
                [self::WOO_SYNCHRONIZER, 'process_updated_option', 999, 3, 'woo'],
                [self::WOO_CUSTOM_ORDERS, 'process_updated_option', 999, 3, 'woo'],
                [self::WOO_CUSTOM_ORDERS, 'process_updated_option_fts_index', 999, 3, 'woo'],
                [self::WOO_FEATURES, 'process_updated_option', 999, 3, 'woo'],
            ];
            if ($tec !== null) {
                $updated = array_merge($updated, [
                    [self::TEC_SETTINGS, 'update_options_cache', 10, 3, 'tec'],
                    [self::TEC_CACHE_LISTENER, 'update_last_updated_option', 10, 3, 'tec'],
                    [self::TEC_CACHE_LISTENER, 'update_last_save_post', 10, 3, 'tec'],
                    [self::TEC_AGGREGATOR, 'action_purge_transients', 10, 1, 'tec'],
                    [self::TEC_VIEWS, 'action_save_wplang', 10, 3, 'tec'],
                ]);
            }
            $this->assert_closed_mixed_option_hook('updated_option', $updated, $woo, $wpseo, null, $tec);
            return;
        }

        $add = [];
        if ($wpseo !== null) {
            foreach ($wpseo['options'] as $class => $_service) {
                $add[] = [$class, 'add_default_filters_if_same_option', 10, 1, 'yoast'];
            }
        }
        $this->assert_closed_mixed_option_hook('add_option', $add, $woo, $wpseo);
        $this->assert_closed_mixed_option_hook("add_option_$name", []);
        $added = $woo === null ? [] : [
            [self::WOO_SYNCHRONIZER, 'process_added_option', 999, 2, 'woo'],
            [self::WOO_FEATURES, 'process_added_option', 999, 3, 'woo'],
        ];
        $this->assert_closed_mixed_option_hook('added_option', $added, $woo, $wpseo);
    }

    private function assert_mixed_option_yoast_permalink_hook(): void {
        global $wp_filter;
        $registered = $wp_filter['update_option_woocommerce_permalinks'] ?? null;
        if ($registered === null) {
            return;
        }
        if (!$registered instanceof \WP_Hook || !is_array($registered->callbacks ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option Yoast permalink hook topology is unreadable or extension-owned'
            );
        }
        $records = [];
        foreach ($registered->callbacks as $priority => $callbacks) {
            foreach (is_array($callbacks) ? $callbacks : [] as $callback) {
                $records[] = [$priority, $callback];
            }
        }
        if (count($records) !== 1
            || $records[0][0] !== 10
            || !is_array($records[0][1])
            || array_keys($records[0][1]) !== ['function', 'accepted_args']
            || $records[0][1]['accepted_args'] !== 2
            || !is_array($records[0][1]['function'] ?? null)
            || count($records[0][1]['function']) !== 2
            || !is_object($records[0][1]['function'][0] ?? null)
            || get_class($records[0][1]['function'][0]) !== self::WPSEO_WOO_PERMALINKS
            || ($records[0][1]['function'][1] ?? null) !== 'reset_woocommerce_permalinks') {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option Yoast permalink hook topology is extended or substituted'
            );
        }
        foreach ([
            self::WPSEO_CONTAINER_REGISTRY,
            self::WPSEO_CONTAINER,
            self::WPSEO_CONTAINER_BASE,
            self::WPSEO_WOO_PERMALINKS,
            self::WPSEO_INDEXABLE_HELPER,
        ] as $class) {
            if (!class_exists($class, false)) {
                throw new \RuntimeException(
                    'duo: WooCommerce mixed option Yoast permalink service is unavailable'
                );
            }
        }
        try {
            $containers = (new \ReflectionProperty(self::WPSEO_CONTAINER_REGISTRY, 'containers'))->getValue();
            $container = is_array($containers) ? ($containers['yoast-seo'] ?? null) : null;
            $services = is_object($container) && get_class($container) === self::WPSEO_CONTAINER
                ? (new \ReflectionProperty(self::WPSEO_CONTAINER_BASE, 'services'))->getValue($container)
                : null;
            $service = is_array($services) ? ($services[self::WPSEO_WOO_PERMALINKS] ?? null) : null;
            $helper = is_object($service) && get_class($service) === self::WPSEO_WOO_PERMALINKS
                ? (new \ReflectionProperty(self::WPSEO_WOO_PERMALINKS, 'indexable_helper'))->getValue($service)
                : null;
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option Yoast permalink service is unavailable',
                0,
                $failure
            );
        }
        if (!is_array($services)
            || !is_object($service)
            || $service !== $records[0][1]['function'][0]
            || !is_object($helper)
            || get_class($helper) !== self::WPSEO_INDEXABLE_HELPER
            || ($services[self::WPSEO_INDEXABLE_HELPER] ?? null) !== $helper) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option Yoast permalink callback is not the exact resolved service'
            );
        }

        // Yoast's exact callback invalidates product and product-taxonomy
        // indexables. The option writer deliberately bypasses WordPress hooks,
        // so admitting that callback requires the selected Yoast action to
        // replace the skipped invalidation with a checkpointed full reindex.
        foreach ($this->policy->actions_for(['option:woocommerce_permalinks']) as $action) {
            if (($action['kind'] ?? null) !== 'provider'
                || ($action['provider'] ?? null) !== 'yoast-index'
                || ($action['capability'] ?? null) !== 'reindex') {
                continue;
            }
            $covered = [];
            foreach ((array) ($action['effects'] ?? []) as $effect) {
                $selector = is_array($effect) ? ($effect['selector'] ?? null) : null;
                if (($effect['kind'] ?? null) === 'database'
                    && ($effect['mode'] ?? null) === 'restorable'
                    && is_array($selector)
                    && ($selector['scope'] ?? null) === 'database_checkpoint') {
                    $covered[(string) ($selector['type'] ?? '') . ':' . (string) ($selector['value'] ?? '')] = true;
                }
            }
            $required = [
                'table:options',
                'table:yoast_indexable',
                'table:yoast_indexable_hierarchy',
                'table:yoast_primary_term',
                'table:yoast_seo_links',
            ];
            if (array_diff_key(array_fill_keys($required, true), $covered) === []) {
                return;
            }
        }
        throw new \RuntimeException(
            'duo: WooCommerce mixed option Yoast permalink callback requires the checkpointed yoast-index action'
        );
    }

    /** @param array<string,string> $fields @param array<string,mixed> $value */
    private function assert_mixed_validation_topology(array $fields, array $value): void {
        $types = [];
        foreach ($value as $key => $_fieldValue) {
            if (isset($fields[(string) $key])) {
                $types[$fields[(string) $key]] = true;
            }
        }
        if (isset($types['text']) || isset($types['textarea']) || isset($types['delay_days'])) {
            $this->assert_closed_mixed_option_hook('pre_kses', []);
            $this->assert_closed_mixed_option_hook('wp_kses_allowed_html', []);
        }
        if (isset($types['safe_text'])) {
            $this->assert_closed_mixed_option_hook('pre_kses', []);
            $this->native_container_service(
                'Automattic\\WooCommerce\\Internal\\Utilities\\HtmlSanitizer'
            );
        }
        if (isset($types['email_type'])) {
            $this->assert_closed_mixed_option_hook('sanitize_text_field', []);
        }
        if (isset($types['permalink'])) {
            $this->assert_closed_mixed_option_hook('clean_url', []);
        }
    }

    /**
     * @param list<array{0:string,1:string,2:int,3:int,4:'brands_global'|'tec'|'woo'|'yoast'|'yoast_static'|'tracking'|'wordpress_function'}> $allowed
     * @param ?array{features:object,synchronizer:object,custom_orders:object} $woo
     * @param ?array{options:array<string,object>,sitemaps:?object,sitemaps_cache:?object} $wpseo
     * @param ?array{settings:object,cache_listener:object,aggregator:object,views:object} $tec
     */
    private function assert_closed_mixed_option_hook(
        string $hook,
        array $allowed,
        ?array $woo = null,
        ?array $wpseo = null,
        ?object $tracking = null,
        ?array $tec = null
    ): void {
        global $wp_filter;
        if (isset($wp_filter) && !is_array($wp_filter)) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option mutation hook registry is unreadable'
            );
        }
        $registered = $wp_filter[$hook] ?? null;
        if ($registered === null) {
            return;
        }
        if (!$registered instanceof \WP_Hook || !is_array($registered->callbacks ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option mutation hook topology is unreadable or extension-owned'
            );
        }
        $seen = [];
        foreach ($registered->callbacks as $priority => $callbacks) {
            if (!is_int($priority) || !is_array($callbacks)) {
                throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology is malformed');
            }
            foreach ($callbacks as $callback) {
                if (!is_array($callback)
                    || array_keys($callback) !== ['function', 'accepted_args']
                    || !is_int($callback['accepted_args'])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce mixed option mutation hook topology has an extension callback'
                    );
                }
                $matched = false;
                foreach ($allowed as $index => [$class, $method, $expectedPriority, $acceptedArgs, $owner]) {
                    if (isset($seen[$index])
                        || $priority !== $expectedPriority
                        || $callback['accepted_args'] !== $acceptedArgs) {
                        continue;
                    }
                    if ($owner === 'wordpress_function') {
                        if ($callback['function'] !== $class
                            || !function_exists($class)) {
                            continue;
                        }
                        $seen[$index] = true;
                        $matched = true;
                        break;
                    }
                    if ($owner === 'yoast_static') {
                        if ($callback['function'] !== [$class, $method]) {
                            continue;
                        }
                        $seen[$index] = true;
                        $matched = true;
                        break;
                    }
                    if (!is_array($callback['function'])
                        || count($callback['function']) !== 2
                        || !is_object($callback['function'][0])
                        || $callback['function'][1] !== $method
                        || get_class($callback['function'][0]) !== $class) {
                        continue;
                    }
                    $expected = match ($owner) {
                        'brands_global' => $this->native_brands_admin(),
                        'woo' => $woo[$this->mixed_woo_service_key($class)] ?? null,
                        'yoast' => $wpseo['options'][$class] ?? null,
                        'yoast_static' => $class,
                        'tracking' => $tracking,
                        'tec' => $tec[$this->mixed_tec_service_key($class)] ?? null,
                        default => null,
                    };
                    if ($callback['function'][0] !== $expected) {
                        throw new \RuntimeException(
                            'duo: WooCommerce mixed option mutation hook callback is not the exact native service'
                        );
                    }
                    $seen[$index] = true;
                    $matched = true;
                    break;
                }
                if (!$matched) {
                    throw new \RuntimeException(
                        'duo: WooCommerce mixed option mutation hook topology has an extension callback'
                    );
                }
            }
        }
        if (count($seen) !== count($allowed)) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option mutation hook topology is incomplete'
            );
        }
    }

    private function native_brands_admin(): object {
        $service = $GLOBALS['WC_Brands_Admin'] ?? null;
        if (!is_object($service) || get_class($service) !== 'WC_Brands_Admin') {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option native Brands permalink validator is unavailable'
            );
        }
        return $service;
    }

    private function resolve_mixed_option_tracking_service(string $mutatedOption): ?object {
        // WC_Settings_Tracking is byte-identical in the reviewed 11.0.0/11.0.1
        // sources (SHA-256 ed15be45…). init() installs five ancillary hooks,
        // but only a native settings save installs track_setting_change; no
        // executable update_option observer therefore means no boundary.
        $records = [];
        global $wp_filter;
        $update = $wp_filter['update_option'] ?? null;
        if ($update instanceof \WP_Hook && is_array($update->callbacks ?? null)) {
            foreach ($update->callbacks as $priority => $callbacks) {
                foreach (is_array($callbacks) ? $callbacks : [] as $callback) {
                    if (is_array($callback)
                        && is_array($callback['function'] ?? null)
                        && is_object($callback['function'][0] ?? null)
                        && get_class($callback['function'][0]) === self::WOO_SETTINGS_TRACKING
                        && ($callback['function'][1] ?? null) === 'track_setting_change') {
                        $records[] = [$priority, $callback];
                    }
                }
            }
        }
        if ($records === []) {
            return null;
        }
        if (count($records) !== 1
            || $records[0][0] !== 10
            || !is_array($records[0][1])
            || array_keys($records[0][1]) !== ['function', 'accepted_args']
            || $records[0][1]['accepted_args'] !== 3) {
            throw new \RuntimeException('duo: WooCommerce mixed option tracking observer roster is extended or substituted');
        }
        $tracker = $records[0][1]['function'][0];
        $this->assert_mixed_option_tracking_roster($tracker);
        try {
            $allowed = (new \ReflectionProperty(self::WOO_SETTINGS_TRACKING, 'allowed_options'))->getValue($tracker);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option tracking observer allowed-options map is unreadable',
                0,
                $failure
            );
        }
        if (!is_array($allowed)) {
            throw new \RuntimeException('duo: WooCommerce mixed option tracking observer allowed-options map is malformed');
        }
        if (in_array($mutatedOption, $allowed, true)) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option tracking observer would track the current option'
            );
        }
        return $tracker;
    }

    private function assert_mixed_option_tracking_roster(object $tracker): void {
        $roster = [
            'woocommerce_settings_page_init' => ['track_settings_page_view', 1],
            'woocommerce_update_option' => ['add_option_to_list', 1],
            'woocommerce_update_non_option_setting' => ['add_option_to_list_and_track_setting_change', 1],
            'woocommerce_update_options' => ['send_settings_change_event', 1],
            'admin_enqueue_scripts' => ['possibly_add_settings_tracking_scripts', 1],
        ];
        global $wp_filter;
        foreach ($roster as $hook => [$method, $acceptedArgs]) {
            $registered = $wp_filter[$hook] ?? null;
            if (!$registered instanceof \WP_Hook || !is_array($registered->callbacks ?? null)) {
                throw new \RuntimeException('duo: WooCommerce mixed option tracking observer roster is incomplete');
            }
            $records = [];
            foreach ($registered->callbacks as $priority => $callbacks) {
                foreach (is_array($callbacks) ? $callbacks : [] as $callback) {
                    if (is_array($callback)
                        && is_array($callback['function'] ?? null)
                        && is_object($callback['function'][0] ?? null)
                        && get_class($callback['function'][0]) === self::WOO_SETTINGS_TRACKING) {
                        $records[] = [$priority, $callback];
                    }
                }
            }
            if (count($records) !== 1
                || $records[0][0] !== 10
                || !is_array($records[0][1])
                || array_keys($records[0][1]) !== ['function', 'accepted_args']
                || $records[0][1]['accepted_args'] !== $acceptedArgs
                || $records[0][1]['function'] !== [$tracker, $method]) {
                throw new \RuntimeException('duo: WooCommerce mixed option tracking observer roster is extended or substituted');
            }
        }
    }

    /** @return 'features'|'synchronizer'|'custom_orders' */
    private function mixed_woo_service_key(string $class): string {
        return match ($class) {
            self::WOO_FEATURES => 'features',
            self::WOO_SYNCHRONIZER => 'synchronizer',
            self::WOO_CUSTOM_ORDERS => 'custom_orders',
            default => throw new \LogicException('duo: unknown WooCommerce mixed option service'),
        };
    }

    /** @return 'settings'|'cache_listener'|'aggregator'|'views' */
    private function mixed_tec_service_key(string $class): string {
        return match ($class) {
            self::TEC_SETTINGS => 'settings',
            self::TEC_CACHE_LISTENER => 'cache_listener',
            self::TEC_AGGREGATOR => 'aggregator',
            self::TEC_VIEWS => 'views',
            default => throw new \LogicException('duo: unknown The Events Calendar mixed option service'),
        };
    }

    /** @return list<mixed> */
    private function mixed_option_callbacks(): array {
        global $wp_filter;
        if (!isset($wp_filter)) {
            return [];
        }
        if (!is_array($wp_filter)) {
            throw new \RuntimeException('duo: WooCommerce mixed option mutation hook registry is unreadable');
        }
        $callbacks = [];
        foreach ($wp_filter as $registered) {
            if (!$registered instanceof \WP_Hook || !is_array($registered->callbacks ?? null)) {
                continue;
            }
            foreach ($registered->callbacks as $records) {
                if (!is_array($records)) {
                    continue;
                }
                foreach ($records as $record) {
                    $callbacks[] = is_array($record) ? ($record['function'] ?? null) : null;
                }
            }
        }
        return $callbacks;
    }

    /** @return ?array{features:object,synchronizer:object,custom_orders:object} */
    private function resolve_mixed_option_woo_services(): ?array {
        $callbacks = $this->mixed_option_callbacks();
        $visible = function_exists('wc_get_container')
            || array_key_exists('wc_container', $GLOBALS)
            || class_exists(self::WOO_CONTAINER, false)
            || class_exists(self::WOO_RUNTIME_CONTAINER, false);
        foreach ([self::WOO_FEATURES, self::WOO_SYNCHRONIZER, self::WOO_CUSTOM_ORDERS] as $class) {
            $visible = $visible || class_exists($class, false);
            foreach ($callbacks as $callback) {
                $owner = is_array($callback) ? ($callback[0] ?? null) : null;
                $visible = $visible || (is_object($owner) && get_class($owner) === $class);
            }
        }
        if (!$visible) {
            return null;
        }
        if (!function_exists('wc_get_container')
            || !array_key_exists('wc_container', $GLOBALS)
            || !class_exists(self::WOO_CONTAINER, false)
            || !class_exists(self::WOO_RUNTIME_CONTAINER, false)
            || !class_exists(self::WOO_FEATURES, false)
            || !class_exists(self::WOO_SYNCHRONIZER, false)
            || !class_exists(self::WOO_CUSTOM_ORDERS, false)) {
            throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has an incomplete WooCommerce runtime');
        }
        try {
            $container = $GLOBALS['wc_container'];
            if (!is_object($container)
                || get_class($container) !== self::WOO_CONTAINER
                || wc_get_container() !== $container) {
                throw new \RuntimeException('substituted WooCommerce container');
            }
            $runtime = (new \ReflectionProperty(self::WOO_CONTAINER, 'container'))->getValue($container);
            $cache = is_object($runtime) && get_class($runtime) === self::WOO_RUNTIME_CONTAINER
                ? (new \ReflectionProperty(self::WOO_RUNTIME_CONTAINER, 'resolved_cache'))->getValue($runtime)
                : null;
            $features = is_array($cache) ? ($cache[self::WOO_FEATURES] ?? null) : null;
            $synchronizer = is_array($cache) ? ($cache[self::WOO_SYNCHRONIZER] ?? null) : null;
            $customOrders = is_array($cache) ? ($cache[self::WOO_CUSTOM_ORDERS] ?? null) : null;
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option mutation hook topology could not resolve WooCommerce services',
                0,
                $failure
            );
        }
        foreach ([
            [$features, self::WOO_FEATURES],
            [$synchronizer, self::WOO_SYNCHRONIZER],
            [$customOrders, self::WOO_CUSTOM_ORDERS],
        ] as [$service, $class]) {
            if (!is_object($service) || get_class($service) !== $class) {
                throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has substituted WooCommerce services');
            }
        }
        return ['features' => $features, 'synchronizer' => $synchronizer, 'custom_orders' => $customOrders];
    }

    /** @return ?array{settings:object,cache_listener:object,aggregator:object,views:object} */
    private function resolve_mixed_option_tec_services(): ?array {
        $callbacks = $this->mixed_option_callbacks();
        $classes = [
            self::TEC_CONTAINER,
            self::TEC_CONTRACT_CONTAINER,
            self::TEC_DI_CONTAINER,
            self::TEC_RESOLVER,
            self::TEC_VALUE_BUILDER,
            self::TEC_SETTINGS,
            self::TEC_CACHE_LISTENER,
            self::TEC_CACHE,
            self::TEC_AGGREGATOR,
            self::TEC_VIEWS,
            'Tribe__Main',
        ];
        $visible = false;
        foreach ($classes as $class) {
            $visible = $visible || class_exists($class, false);
        }
        foreach ($callbacks as $callback) {
            $owner = is_array($callback) ? ($callback[0] ?? null) : null;
            $ownerClass = is_object($owner) ? get_class($owner) : null;
            $visible = $visible || in_array($ownerClass, [
                self::TEC_SETTINGS,
                self::TEC_CACHE_LISTENER,
                self::TEC_AGGREGATOR,
                self::TEC_VIEWS,
            ], true);
        }
        if (!$visible) {
            return null;
        }
        foreach ($classes as $class) {
            if (!class_exists($class, false)) {
                throw new \RuntimeException(
                    'duo: WooCommerce mixed option mutation hook topology has an incomplete The Events Calendar runtime'
                );
            }
        }
        if (get_parent_class(self::TEC_CONTAINER) !== self::TEC_CONTRACT_CONTAINER
            || get_parent_class(self::TEC_CONTRACT_CONTAINER) !== self::TEC_DI_CONTAINER
            || !defined('Tribe__Main::OPTIONNAME')
            || constant('Tribe__Main::OPTIONNAME') !== 'tribe_events_calendar_options') {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option mutation hook topology has a substituted The Events Calendar runtime'
            );
        }
        try {
            $container = (new \ReflectionProperty(self::TEC_CONTAINER, 'instance'))->getValue();
            $resolver = is_object($container) && get_class($container) === self::TEC_CONTAINER
                ? (new \ReflectionProperty(self::TEC_DI_CONTAINER, 'resolver'))->getValue($container)
                : null;
            $bindings = is_object($resolver) && get_class($resolver) === self::TEC_RESOLVER
                ? (new \ReflectionProperty(self::TEC_RESOLVER, 'bindings'))->getValue($resolver)
                : null;
            $settings = is_array($bindings)
                ? $this->resolved_tec_binding($bindings, 'settings.manager', self::TEC_SETTINGS)
                : null;
            $aggregator = is_array($bindings)
                ? $this->resolved_tec_binding($bindings, 'events-aggregator.main', self::TEC_AGGREGATOR)
                : null;
            $views = is_array($bindings)
                ? $this->resolved_tec_binding($bindings, self::TEC_VIEWS, self::TEC_VIEWS)
                : null;
            $viewsAlias = is_array($bindings)
                ? $this->resolved_tec_binding($bindings, 'events.views.v2.hooks', self::TEC_VIEWS)
                : null;
            $listener = (new \ReflectionProperty(self::TEC_CACHE_LISTENER, 'instance'))->getValue();
            $listenerCache = is_object($listener) && get_class($listener) === self::TEC_CACHE_LISTENER
                ? (new \ReflectionProperty(self::TEC_CACHE_LISTENER, 'cache'))->getValue($listener)
                : null;
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option mutation hook topology could not inspect The Events Calendar services',
                0,
                $failure
            );
        }
        if (!is_object($settings) || get_class($settings) !== self::TEC_SETTINGS
            || !is_object($aggregator) || get_class($aggregator) !== self::TEC_AGGREGATOR
            || !is_object($views) || get_class($views) !== self::TEC_VIEWS
            || $viewsAlias !== $views
            || !is_object($listener) || get_class($listener) !== self::TEC_CACHE_LISTENER
            || !is_object($listenerCache) || get_class($listenerCache) !== self::TEC_CACHE) {
            throw new \RuntimeException(
                'duo: WooCommerce mixed option mutation hook topology has substituted The Events Calendar services'
            );
        }

        // Cache_Listener passes both exact trigger maps through these filters.
        // Any observer can turn an otherwise inert Woo option into a native TEC
        // cache mutation, so all three registries are part of the closed union.
        foreach ([
            'tribe_cache_last_occurrence_option_triggers',
            'tribe_cache_last_occurrence_option_triggers:updated_option',
            'tribe_cache_last_occurrence_option_triggers:save_post',
        ] as $filter) {
            $this->assert_closed_mixed_option_hook($filter, []);
        }
        return [
            'settings' => $settings,
            'cache_listener' => $listener,
            'aggregator' => $aggregator,
            'views' => $views,
        ];
    }

    /** @param array<string,mixed> $bindings */
    private function resolved_tec_binding(array $bindings, string $id, string $class): ?object {
        $binding = $bindings[$id] ?? null;
        if (is_object($binding) && get_class($binding) === self::TEC_VALUE_BUILDER) {
            $binding = (new \ReflectionProperty(self::TEC_VALUE_BUILDER, 'value'))->getValue($binding);
        }
        return is_object($binding) && get_class($binding) === $class ? $binding : null;
    }

    /** @return ?array{options:array<string,object>,sitemaps:?object,sitemaps_cache:?object} */
    private function resolve_mixed_option_wpseo_services(string $mutatedOption): ?array {
        $callbacks = $this->mixed_option_callbacks();
        $visible = class_exists('WPSEO_Options', false)
            || class_exists(self::WPSEO_SITEMAPS_CACHE, false)
            || array_key_exists('wpseo_sitemaps', $GLOBALS);
        foreach (self::WPSEO_OPTIONS as $class) {
            $visible = $visible || class_exists($class, false);
            foreach ($callbacks as $callback) {
                $owner = is_array($callback) ? ($callback[0] ?? null) : null;
                $ownerClass = is_object($owner) ? get_class($owner) : $owner;
                $visible = $visible || $ownerClass === $class;
            }
        }
        foreach ($callbacks as $callback) {
            $visible = $visible || (is_array($callback) && ($callback[0] ?? null) === self::WPSEO_SITEMAPS_CACHE);
        }
        if (!$visible) {
            return null;
        }
        if (!class_exists('WPSEO_Options', false)
            || !is_callable(['WPSEO_Options', 'get_option_instance'])) {
            throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has incomplete Yoast SEO option singletons');
        }
        $options = [];
        foreach (self::WPSEO_OPTIONS as $optionName => $class) {
            try {
                // This reads the constructed singleton map only. get_instance()
                // would manufacture a service and turn partial boot into admission.
                $service = \WPSEO_Options::get_option_instance($optionName);
            } catch (\Throwable $failure) {
                throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology could not resolve Yoast SEO option services', 0, $failure);
            }
            if (!is_object($service) || get_class($service) !== $class) {
                throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has incomplete Yoast SEO option singletons');
            }
            $options[$class] = $service;
        }
        $sitemapCallbacks = [];
        global $wp_filter;
        $update = $wp_filter['update_option'] ?? null;
        if ($update instanceof \WP_Hook && is_array($update->callbacks ?? null)) {
            foreach ($update->callbacks as $priority => $records) {
                foreach (is_array($records) ? $records : [] as $record) {
                    if (is_array($record) && ($record['function'] ?? null) === [self::WPSEO_SITEMAPS_CACHE, 'clear_on_option_update']) {
                        $sitemapCallbacks[] = [$priority, $record];
                    }
                }
            }
        }
        $sitemapsPresent = array_key_exists('wpseo_sitemaps', $GLOBALS);
        if (class_exists(self::WPSEO_SITEMAPS_CACHE, false)) {
            try {
                $cacheClear = (new \ReflectionProperty(self::WPSEO_SITEMAPS_CACHE, 'cache_clear'))->getValue();
            } catch (\Throwable $failure) {
                throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology could not inspect the Yoast SEO sitemap cache registration map', 0, $failure);
            }
            if (!is_array($cacheClear)) {
                throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has malformed Yoast SEO sitemap cache registration map');
            }
            if (array_key_exists($mutatedOption, $cacheClear)) {
                throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has the current WooCommerce option registered for Yoast SEO sitemap cache invalidation');
            }
        }
        if (!$sitemapsPresent && $sitemapCallbacks === []) {
            return ['options' => $options, 'sitemaps' => null, 'sitemaps_cache' => null];
        }
        if (!$sitemapsPresent || count($sitemapCallbacks) !== 1
            || $sitemapCallbacks[0][0] !== 10
            || ($sitemapCallbacks[0][1]['accepted_args'] ?? null) !== 1) {
            throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has incomplete or substituted Yoast SEO sitemap cache topology');
        }
        $sitemaps = $GLOBALS['wpseo_sitemaps'];
        $cache = is_object($sitemaps) ? ($sitemaps->cache ?? null) : null;
        if (!is_object($sitemaps) || get_class($sitemaps) !== self::WPSEO_SITEMAPS
            || !is_object($cache) || get_class($cache) !== self::WPSEO_SITEMAPS_CACHE
            || !class_exists(self::WPSEO_SITEMAPS_CACHE, false)
            || !is_callable([self::WPSEO_SITEMAPS_CACHE, 'clear_on_option_update'])) {
            throw new \RuntimeException('duo: WooCommerce mixed option mutation hook topology has incomplete or substituted Yoast SEO sitemap cache service');
        }
        return ['options' => $options, 'sitemaps' => $sitemaps, 'sitemaps_cache' => $cache];
    }

    private function native_container_service(string $class): object {
        if (!function_exists('wc_get_container')
            || !array_key_exists('wc_container', $GLOBALS)
            || !class_exists(self::WOO_CONTAINER, false)
            || !class_exists(self::WOO_RUNTIME_CONTAINER, false)) {
            throw new \RuntimeException('duo: WooCommerce mixed option native hook service is unavailable');
        }
        try {
            $container = $GLOBALS['wc_container'];
            if (!is_object($container) || get_class($container) !== self::WOO_CONTAINER
                || wc_get_container() !== $container) {
                throw new \RuntimeException('substituted WooCommerce container');
            }
            $runtime = (new \ReflectionProperty(self::WOO_CONTAINER, 'container'))->getValue($container);
            $cache = is_object($runtime) && get_class($runtime) === self::WOO_RUNTIME_CONTAINER
                ? (new \ReflectionProperty(self::WOO_RUNTIME_CONTAINER, 'resolved_cache'))->getValue($runtime)
                : null;
            $service = is_array($cache) ? ($cache[$class] ?? null) : null;
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: WooCommerce mixed option native hook service is unavailable', 0, $failure);
        }
        if (!is_object($service) || get_class($service) !== $class) {
            throw new \RuntimeException('duo: WooCommerce mixed option native hook service is unavailable');
        }
        return $service;
    }


    private function native_settings_api(): object {
        if (!class_exists('WC_Settings_API', false)
            || !function_exists('wc_sanitize_permalink')) {
            $this->load_native_settings_api();
        }
        if (!class_exists('WC_Settings_API', false)) {
            throw new \RuntimeException('duo: WooCommerce mixed option validation requires WC_Settings_API');
        }
        $reflection = new \ReflectionClass('WC_Settings_API');
        if (!$reflection->isAbstract()) {
            throw new \RuntimeException('duo: WooCommerce WC_Settings_API is not the exact abstract native authority');
        }
        foreach ([
            'validate_checkbox_field',
            'validate_safe_text_field',
            'validate_select_field',
            'validate_text_field',
            'validate_textarea_field',
        ] as $method) {
            if (!$reflection->hasMethod($method)
                || $reflection->getMethod($method)->getDeclaringClass()->getName() !== 'WC_Settings_API') {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option validation requires exact WC_Settings_API::$method()"
                );
            }
        }
        // Exact Woo 11.0.0/11.0.1 declares WC_Settings_API abstract even
        // though its five validators are concrete. An anonymous subclass
        // exercises those inherited native bytes without constructing a
        // gateway/email service and crossing its hooks or target state.
        return new class extends \WC_Settings_API {};
    }

    private function load_native_settings_api(): void {
        $message = 'duo: WooCommerce mixed option validation requires WC_Settings_API';
        if (!defined('ABSPATH')
            || !defined('WP_PLUGIN_DIR')) {
            throw new \RuntimeException($message);
        }
        $wpRoot = constant('ABSPATH');
        $pluginDirectory = constant('WP_PLUGIN_DIR');
        if (!is_string($wpRoot) || $wpRoot === ''
            || !is_string($pluginDirectory) || $pluginDirectory === '') {
            throw new \RuntimeException($message);
        }
        $wpRootReal = realpath(rtrim($wpRoot, "/\\"));
        $pluginDirectoryReal = realpath(rtrim($pluginDirectory, "/\\"));
        $installedRootReal = $pluginDirectoryReal === false
            ? false
            : realpath($pluginDirectoryReal . DIRECTORY_SEPARATOR . 'woocommerce');
        $installedFileReal = $installedRootReal === false
            ? false
            : realpath($installedRootReal . DIRECTORY_SEPARATOR . 'woocommerce.php');
        if ($wpRootReal === false
            || $pluginDirectoryReal === false
            || $installedRootReal === false
            || $installedFileReal === false
            || dirname($installedFileReal) !== $installedRootReal) {
            throw new \RuntimeException($message);
        }

        $hasConfiguredRoot = defined('WC_ABSPATH');
        $hasPluginFile = defined('WC_PLUGIN_FILE');
        if ($hasConfiguredRoot !== $hasPluginFile) {
            throw new \RuntimeException($message);
        }
        $pluginRootReal = $installedRootReal;
        if ($hasConfiguredRoot) {
            $configuredRoot = constant('WC_ABSPATH');
            $pluginFile = constant('WC_PLUGIN_FILE');
            if (!is_string($configuredRoot) || $configuredRoot === ''
                || !is_string($pluginFile) || $pluginFile === '') {
                throw new \RuntimeException($message);
            }
            $configuredRootReal = realpath(rtrim($configuredRoot, "/\\"));
            $pluginFileReal = realpath($pluginFile);
            if ($configuredRootReal !== $installedRootReal
                || $pluginFileReal !== $installedFileReal) {
                throw new \RuntimeException($message);
            }
            $pluginRootReal = $configuredRootReal;
        }
        $settingsFile = $pluginRootReal
            . DIRECTORY_SEPARATOR . 'includes'
            . DIRECTORY_SEPARATOR . 'abstracts'
            . DIRECTORY_SEPARATOR . 'abstract-wc-settings-api.php';
        $formattingFile = $pluginRootReal
            . DIRECTORY_SEPARATOR . 'includes'
            . DIRECTORY_SEPARATOR . 'wc-formatting-functions.php';
        $settingsFileReal = realpath($settingsFile);
        $formattingFileReal = realpath($formattingFile);
        $settingsHash = $settingsFileReal === false || !is_file($settingsFileReal)
            ? false
            : hash_file('sha256', $settingsFileReal);
        $formattingHash = $formattingFileReal === false || !is_file($formattingFileReal)
            ? false
            : hash_file('sha256', $formattingFileReal);
        if ($settingsFileReal !== $settingsFile
            || !is_string($settingsHash)
            || !hash_equals(self::WOO_SETTINGS_API_SHA256, $settingsHash)
            || $formattingFileReal !== $formattingFile
            || !is_string($formattingHash)
            || !hash_equals(self::WOO_FORMATTING_FUNCTIONS_SHA256, $formattingHash)) {
            throw new \RuntimeException($message);
        }
        try {
            // Exact Woo 11.0.0/11.0.1 includes both byte-identical files from
            // class-woocommerce.php. An inactive deploy needs the permalink
            // sanitizer as well as WC_Settings_API before activation can run.
            if (!function_exists('wc_sanitize_permalink')) {
                require_once $formattingFileReal;
            }
            if (!class_exists('WC_Settings_API', false)) {
                require_once $settingsFileReal;
            }
        } catch (\Throwable $failure) {
            throw new \RuntimeException($message, 0, $failure);
        }
    }

    private function assert_native_mixed_field(
        object $api,
        string $name,
        string $key,
        string $type,
        mixed $value,
        string $where
    ): void {
        if ($type === 'permalink_bool') {
            if (!is_bool($value)) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name.$key' $where is not exact native boolean state"
                );
            }
            return;
        }
        $this->assert_bounded_mixed_text($name, $key, $value, $where);
        if ($type === 'permalink') {
            if (strlen($value) > self::MAX_PERMALINK_BYTES
                || ($key !== 'attribute_base' && $value === '')
                || !function_exists('wc_sanitize_permalink')) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name.$key' $where is outside the bounded native permalink state"
                );
            }
            $canonical = wc_sanitize_permalink($value);
            if (!is_string($canonical) || !hash_equals($value, $canonical)) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name.$key' $where is not canonical native permalink storage"
                );
            }
            if ($key === 'product_base'
                && rtrim($value, "/\\") . '/' === '/%product_brand%/') {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name.$key' $where bypasses the native Brands product-base guard"
                );
            }
            return;
        }
        if ($type === 'checkbox') {
            if (!in_array($value, ['yes', 'no'], true)
                || $api->validate_checkbox_field($key, $value === 'yes' ? '1' : null) !== $value) {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name.$key' $where is not exact native yes/no state"
                );
            }
            return;
        }
        if ($type === 'email_type' && !in_array($value, ['plain', 'html', 'multipart'], true)) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name.$key' $where is outside the native email type set"
            );
        }
        if ($type === 'delay_days'
            && (preg_match('/^(?:[1-9]|[1-5][0-9]|60)$/D', $value) !== 1)) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name.$key' $where must be whole days from 1 through 60"
            );
        }
        // WC_Settings_API validators receive WordPress-slashed request bytes.
        // Re-slash stored state before replaying that exact boundary so a
        // legitimate authored backslash is not mistaken for noncanonical data.
        $submitted = addslashes($value);
        $canonical = match ($type) {
            'safe_text' => $api->validate_safe_text_field($key, $submitted),
            'email_type' => $api->validate_select_field($key, $submitted),
            'textarea' => $api->validate_textarea_field($key, $submitted),
            'text', 'delay_days' => $api->validate_text_field($key, $submitted),
            default => throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name.$key' has an unsupported native field type"
            ),
        };
        if (!is_string($canonical) || !hash_equals($value, $canonical)) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name.$key' $where is not canonical native storage"
            );
        }
    }

    private function assert_bounded_mixed_text(
        string $name,
        string $key,
        mixed $value,
        string $where
    ): void {
        if (!is_string($value)
            || strlen($value) > self::MAX_MIXED_TEXT_BYTES
            || preg_match('//u', $value) !== 1) {
            throw new \RuntimeException(
                "duo: WooCommerce mixed option '$name.$key' $where must be bounded UTF-8 text"
            );
        }
    }

    private function is_target_owned_mixed_type(string $type): bool {
        return in_array($type, ['env_text', 'derived_empty'], true);
    }

    private function assert_target_owned_mixed_field(
        string $name,
        string $key,
        string $type,
        mixed $value,
        string $where
    ): void {
        if ($type === 'derived_empty') {
            if ($value !== '') {
                throw new \RuntimeException(
                    "duo: WooCommerce mixed option '$name.$key' $where is not the native empty derived placeholder"
                );
            }
            return;
        }
        $this->assert_bounded_mixed_text($name, $key, $value, $where);
    }

    /** @return list<array{method_id:string,instance_id?:int}> */
    private function canonical_cod_methods(mixed $value, string $where): array {
        if ($value === '') {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_COD_METHODS) {
            throw new \RuntimeException(
                "duo: WooCommerce COD $where shipping restrictions must be a bounded native list"
            );
        }
        $out = [];
        $seen = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || strlen($entry) > 128) {
                throw new \RuntimeException(
                    "duo: WooCommerce COD $where shipping restriction has malformed native bytes"
                );
            }
            if (preg_match('/^([a-z][a-z0-9_]{0,63})(?::([1-9][0-9]*))?$/D', $entry, $match) !== 1
                || !array_key_exists($match[1], $this->core_shipping_method_classes())) {
                throw new \RuntimeException(
                    "duo: WooCommerce COD $where shipping restriction is not an exact core method identity"
                );
            }
            if (isset($seen[$entry])) {
                throw new \RuntimeException(
                    "duo: WooCommerce COD $where shipping restrictions contain a duplicate identity"
                );
            }
            $seen[$entry] = true;
            if (isset($match[2])) {
                $instanceId = Policy::strict_positive_local_id($match[2]);
                if ($instanceId === null || (string) $instanceId !== $match[2]) {
                    throw new \RuntimeException(
                        "duo: WooCommerce COD $where shipping restriction has a noncanonical instance identity"
                    );
                }
                $this->assert_raw_shipping_method_witness($instanceId, $match[1], $where);
                // Canon sorts object keys; return that same order so the
                // post-write projection can be compared strictly to the
                // token codec's materialized repository object.
                $row = ['instance_id' => $instanceId, 'method_id' => $match[1]];
            } else {
                $row = ['method_id' => $match[1]];
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<string>|string */
    private function native_cod_methods(mixed $value, string $where): array|string {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_COD_METHODS) {
            throw new \RuntimeException(
                "duo: WooCommerce COD $where repository restrictions must be a bounded canonical list"
            );
        }
        if ($value === []) {
            return '';
        }
        $out = [];
        $seen = [];
        foreach ($value as $row) {
            if (!is_array($row)
                || ($row !== [] && array_is_list($row))
                || !isset($row['method_id'])
                || !is_string($row['method_id'])
                || !array_key_exists($row['method_id'], $this->core_shipping_method_classes())) {
                throw new \RuntimeException(
                    "duo: WooCommerce COD $where repository restriction has malformed method identity"
                );
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $expectedKeys = array_key_exists('instance_id', $row)
                ? ['instance_id', 'method_id']
                : ['method_id'];
            if ($keys !== $expectedKeys) {
                throw new \RuntimeException(
                    "duo: WooCommerce COD $where repository restriction contains unknown fields"
                );
            }
            $native = $row['method_id'];
            if (array_key_exists('instance_id', $row)) {
                $instanceId = $row['instance_id'];
                if (!is_int($instanceId) || $instanceId <= 0) {
                    throw new \RuntimeException(
                        "duo: WooCommerce COD $where repository restriction has malformed instance identity"
                    );
                }
                $this->assert_raw_shipping_method_witness($instanceId, $row['method_id'], $where);
                $native .= ':' . $instanceId;
            }
            if (isset($seen[$native])) {
                throw new \RuntimeException(
                    "duo: WooCommerce COD $where repository restrictions contain a duplicate identity"
                );
            }
            $seen[$native] = true;
            $out[] = $native;
        }
        return $out;
    }

    /** @return array<string,string> */
    private function core_shipping_method_classes(): array {
        return [
            'flat_rate' => 'WC_Shipping_Flat_Rate',
            'free_shipping' => 'WC_Shipping_Free_Shipping',
            'local_pickup' => 'WC_Shipping_Local_Pickup',
            'legacy_flat_rate' => 'WC_Shipping_Legacy_Flat_Rate',
            'legacy_free_shipping' => 'WC_Shipping_Legacy_Free_Shipping',
            'legacy_international_delivery' => 'WC_Shipping_Legacy_International_Delivery',
            'legacy_local_delivery' => 'WC_Shipping_Legacy_Local_Delivery',
            'legacy_local_pickup' => 'WC_Shipping_Legacy_Local_Pickup',
        ];
    }

    /**
     * Prove an instance reference against the one raw Woo table row instead
     * of WC_Shipping_Zones::get_shipping_method(). That public resolver can
     * construct arbitrary shipping services and cross their filters/actions;
     * direct bounded SQL is the only side-effect-free identity witness for
     * this already-ledger-resolved id. The method is called before storage,
     * immediately after the engine-owned write, and again from finalized
     * projection, so a concurrent row replacement cannot be blessed.
     */
    private function assert_raw_shipping_method_witness(int $instanceId, string $methodId, string $where): void {
        global $wpdb;
        $table = $this->shipping_zone_methods_table();
        try {
            $wpdb->last_error = '';
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT instance_id, zone_id, method_id, method_order, is_enabled '
                . "FROM $table WHERE instance_id = %d ORDER BY instance_id ASC LIMIT 2",
                $instanceId
            ), ARRAY_A);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                "duo: WooCommerce COD $where shipping-method raw witness query failed",
                0,
                $exception
            );
        }
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException(
                "duo: WooCommerce COD $where shipping-method raw witness query failed"
            );
        }
        if (count($rows) !== 1
            || !is_array($rows[0])
            || array_keys($rows[0]) !== [
                'instance_id', 'zone_id', 'method_id', 'method_order', 'is_enabled',
            ]) {
            throw new \RuntimeException(
                "duo: WooCommerce COD $where shipping-method raw witness is missing or duplicated"
            );
        }
        $row = $rows[0];
        if ($this->strict_raw_shipping_uint($row['instance_id'] ?? null, false) !== $instanceId
            || $this->strict_raw_shipping_uint($row['zone_id'] ?? null, true) === null
            || $this->strict_raw_shipping_uint($row['method_order'] ?? null, true) === null
            || !in_array($row['is_enabled'] ?? null, ['0', '1'], true)
            || !is_string($row['method_id'] ?? null)
            || !hash_equals($methodId, $row['method_id'])) {
            throw new \RuntimeException(
                "duo: WooCommerce COD $where shipping-method raw witness does not match its exact core method identity"
            );
        }
    }

    private function shipping_zone_methods_table(): string {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->prefix)
            || !is_string($wpdb->prefix)
            || !is_callable([$wpdb, 'prepare'])
            || !is_callable([$wpdb, 'get_results'])) {
            throw new \RuntimeException('duo: WooCommerce COD raw shipping-method witness is unavailable');
        }
        $table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
            throw new \RuntimeException('duo: WooCommerce COD raw shipping-method table identity is invalid');
        }
        return $table;
    }

    private function strict_raw_shipping_uint(mixed $value, bool $allowZero): ?int {
        if (!is_string($value)
            || preg_match($allowZero ? '/^(?:0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $number = (int) $value;
        if ($number < ($allowZero ? 0 : 1) || (string) $number !== $value) {
            return null;
        }
        return $number;
    }

    public function post_meta_rule(string $key, array $allMeta): ?array {
        // Woo 11's optional Variation Gallery consumes the legacy extension
        // row only until its core migration sentinel is present. Excluding the
        // inert residue is safe; a populated unsentinelled row remains
        // deliberately unclassified so capture refuses before copying an
        // extension-owned attachment schema under the core adapter claim.
        if ($key === '_wc_additional_variation_images') {
            return ($allMeta['_wc_variation_gallery_legacy_fallback_disabled'] ?? null) === 'yes'
                ? ['class' => 'env']
                : null;
        }
        if (!in_array($key, self::HANDLED_POST_META, true)) {
            return null;
        }
        return $this->policy->post_meta_rule($key);
    }

    public function term_meta_rule(string $key, array $allMeta): ?array {
        // WC_Term_Data_Store writes this cache family only as term metadata.
        // Manifest meta_patterns are shared by post and term policy lookup, so
        // keeping this exact context ruling here prevents an identically named
        // post row from being silently discarded as derived state.
        if (preg_match('/^product_count_(?:product_cat|product_tag|product_brand)$/D', $key) === 1) {
            return ['class' => 'derived'];
        }
        if (!in_array($key, self::HANDLED_TERM_META, true)) {
            return null;
        }
        return $this->policy->term_meta_rule($key);
    }

    public function option_rule(string $name, array $allOptions): ?array {
        if (str_starts_with($name, self::STOCK_NOTIFICATION_CYCLE_PREFIX)) {
            $suffix = substr($name, strlen(self::STOCK_NOTIFICATION_CYCLE_PREFIX));
            $productId = Policy::strict_positive_local_id($suffix);
            return $productId !== null && (string) $productId === $suffix
                ? ['class' => 'runtime']
                : null;
        }
        if (!in_array($name, self::FULFILLMENT_OPTIONS, true)) {
            return null;
        }
        return $this->policy->option_rule($name);
    }

    /** Push preferences are site-scoped device state keyed by the current blog-table suffix. */
    public function user_meta_rule(string $key, array $allMeta): ?array {
        global $wpdb;

        if (!function_exists('get_current_blog_id')
            || !is_object($wpdb)
            || !is_callable([$wpdb, 'get_blog_prefix'])) {
            return null;
        }

        $prefix = $wpdb->get_blog_prefix((int) get_current_blog_id());
        if (!is_string($prefix) || preg_match('/^[a-z0-9_]+$/Di', $prefix) !== 1) {
            return null;
        }
        $siteSuffix = rtrim($prefix, '_');
        if ($siteSuffix === '' || strlen($siteSuffix) > 220) {
            return null;
        }

        $expected = 'wc_push_notification_preferences_' . $siteSuffix;
        return hash_equals($expected, $key) ? ['class' => 'runtime'] : null;
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        $postIndex = [];
        $termIndex = [];
        $visibilityInventory = [];
        $hasProduct = false;
        foreach ($tree as $entity) {
            $entityType = (string) ($entity['type'] ?? '');
            if ($entityType === 'post') {
                $front = $entity['data'] ?? Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
                $uuid = (string) ($front['uuid'] ?? '');
                if ($uuid !== '') {
                    $postIndex[$uuid] = $front;
                }
                $hasProduct = $hasProduct
                    || in_array((string) ($front['type'] ?? ''), ['product', 'product_variation'], true);
                continue;
            }
            if ($entityType === 'term') {
                $front = (array) ($entity['data'] ?? []);
                $uuid = (string) ($front['uuid'] ?? '');
                if ($uuid !== '') {
                    $termIndex[$uuid] = $front;
                }
                if (($front['taxonomy'] ?? null) === 'product_visibility') {
                    $visibilityInventory[] = (string) ($front['slug'] ?? '');
                }
            }
        }
        if ($hasProduct || $visibilityInventory !== []) {
            sort($visibilityInventory, SORT_STRING);
            $expected = self::PRODUCT_VISIBILITY_TERMS;
            sort($expected, SORT_STRING);
            if ($visibilityInventory !== $expected) {
                $out[] = $this->diagnostic(
                    'state/terms/product_visibility',
                    'taxonomy.inventory',
                    'WooCommerce repositories with products require exactly one of every core product_visibility term'
                );
            }
        }
        foreach ($tree as $entity) {
            $entityType = (string) ($entity['type'] ?? '');
            if ($entityType === 'post') {
                $out = array_merge($out, $this->post_diagnostics($entity, $postIndex, $termIndex));
                continue;
            }
            if ($entityType === 'term') {
                $out = array_merge($out, $this->term_diagnostics($entity, $postIndex));
                continue;
            }
            if ($entityType === 'options') {
                $out = array_merge($out, $this->option_diagnostics($entity));
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function post_diagnostics(array $entity, array $postIndex, array $termIndex): array {
        $out = [];
        $front = $entity['data'] ?? Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
        $meta = (array) ($front['meta'] ?? []);
        $postType = (string) ($front['type'] ?? '');
        $path = (string) ($entity['path'] ?? '');
        if (in_array($postType, ['product', 'product_variation'], true)) {
            $out = array_merge(
                $out,
                $this->visibility_diagnostics($path, $front, $postIndex, $termIndex)
            );
        }
        if (array_key_exists('_product_attributes', $meta)) {
            if ($postType !== 'product') {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._product_attributes',
                    'WooCommerce product attributes are valid only on product entities'
                );
            } else {
                $out = array_merge($out, $this->attribute_diagnostics($path, $meta['_product_attributes']));
            }
        }
        if (array_key_exists('_downloadable_files', $meta)) {
            if (!in_array($postType, ['product', 'product_variation'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._downloadable_files',
                    'WooCommerce downloadable files are valid only on product or product_variation entities'
                );
            } else {
                $out = array_merge($out, $this->download_diagnostics($path, $meta['_downloadable_files']));
            }
        }
        foreach (['_product_url', '_button_text'] as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            if ($postType !== 'product') {
                $out[] = $this->diagnostic(
                    $path,
                    "meta.$key",
                    'WooCommerce external-product fields are valid only on product entities'
                );
                continue;
            }
            if ($key === '_product_url') {
                $out = array_merge($out, $this->url_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    false,
                    'WooCommerce external product URL',
                    false
                ));
            } else {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    4096,
                    'WooCommerce external product button text'
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($meta[$key])) {
                    $out = array_merge($out, $this->native_text_canonical_diagnostics(
                        $path,
                        "meta.$key",
                        $meta[$key],
                        'WooCommerce external product button text'
                    ));
                }
            }
        }
        if (array_key_exists('_cogs_total_value', $meta)) {
            if (!in_array($postType, ['product', 'product_variation'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_total_value',
                    'WooCommerce Cost of Goods value is valid only on product or product_variation entities'
                );
            } elseif (!is_string($meta['_cogs_total_value'])
                || !self::cogs_meta_value_supported($meta['_cogs_total_value'])) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_total_value',
                    'WooCommerce Cost of Goods value must use the exact native float storage spelling and fit the DECIMAL(19,4) lookup boundary'
                );
            } elseif ($postType === 'product' && (float) $meta['_cogs_total_value'] === 0.0) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_total_value',
                    'WooCommerce base-product Cost of Goods zero must be represented by metadata absence'
                );
            }
        }
        if (array_key_exists('_cogs_value_is_additive', $meta)) {
            if ($postType !== 'product_variation') {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_value_is_additive',
                    'WooCommerce additive Cost of Goods state is valid only on product_variation entities'
                );
            } elseif ($meta['_cogs_value_is_additive'] !== 'yes') {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_value_is_additive',
                    "WooCommerce additive Cost of Goods state must be exact 'yes'; false is represented by absence"
                );
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function visibility_diagnostics(string $path, array $front, array $postIndex, array $termIndex): array {
        $out = [];
        $postType = (string) ($front['type'] ?? '');
        $terms = (array) ($front['terms'] ?? []);
        $visibility = $this->relationship_slugs(
            $path,
            'terms.product_visibility',
            $terms['product_visibility'] ?? [],
            'product_visibility',
            $termIndex,
            $out
        );
        $pos = $this->relationship_slugs(
            $path,
            'terms.pos_product_visibility',
            $terms['pos_product_visibility'] ?? [],
            'pos_product_visibility',
            $termIndex,
            $out
        );

        $ratings = array_values(array_filter(
            $visibility,
            static fn(string $slug): bool => str_starts_with($slug, 'rated-')
        ));
        if (count($ratings) > 1) {
            $out[] = $this->diagnostic(
                $path,
                'terms.product_visibility',
                'WooCommerce product visibility may contain at most one native rated-* projection term'
            );
        }
        if ($postType === 'product_variation') {
            $unsupported = array_values(array_diff($visibility, ['outofstock']));
            if ($unsupported !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    'terms.product_visibility',
                    'WooCommerce variations may carry only the native outofstock visibility projection'
                );
            }
        }
        if (array_diff($pos, ['pos-hidden']) !== [] || count($pos) > 1) {
            $out[] = $this->diagnostic(
                $path,
                'terms.pos_product_visibility',
                'WooCommerce POS visibility accepts only one exact pos-hidden relationship'
            );
        }

        if ($postType === 'product') {
            $productTypes = $this->relationship_slugs(
                $path,
                'terms.product_type',
                $terms['product_type'] ?? [],
                'product_type',
                $termIndex,
                $out
            );
            $productType = count($productTypes) === 1 ? $productTypes[0] : null;
            if ($productType === null
                || !in_array($productType, ['simple', 'grouped', 'variable', 'external'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'terms.product_type',
                    'WooCommerce products require exactly one admitted core product_type relationship'
                );
            }
            $postMeta = (array) ($front['meta'] ?? []);
            $downloadable = (($postMeta['_downloadable'] ?? null) === 'yes');
            if ($pos !== [] && ($downloadable || !in_array($productType, ['simple', 'variable'], true))) {
                $out[] = $this->diagnostic(
                    $path,
                    'terms.pos_product_visibility',
                    'WooCommerce pos-hidden is valid only for a non-downloadable simple or variable product'
                );
            }
        } else {
            // A variation's parent is an authored repository edge, not an
            // optional runtime convenience.  The compiler accepts a null or
            // absent post.parent field syntactically, but Woo's visibility
            // projection cannot prove inheritance without one exact
            // canonical post token.  Refuse every other shape before POS
            // inheritance can make a parentless variation look valid.
            $parentReference = $front['parent'] ?? null;
            $parentUuid = null;
            if (is_string($parentReference)
                && preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\}\}$/D', $parentReference, $match) === 1) {
                $parentUuid = $match[1];
            }
            $parent = $parentUuid !== null && is_array($postIndex[$parentUuid] ?? null)
                ? $postIndex[$parentUuid]
                : null;
            if ($parent === null || ($parent['type'] ?? null) !== 'product') {
                $out[] = $this->diagnostic(
                    $path,
                    'parent',
                    'WooCommerce variation visibility requires an exact product parent in the repository'
                );
            } elseif ($parent !== null) {
                $parentTerms = (array) ($parent['terms'] ?? []);
                $parentTypes = $this->relationship_slugs(
                    $path,
                    'parent.terms.product_type',
                    $parentTerms['product_type'] ?? [],
                    'product_type',
                    $termIndex,
                    $out
                );
                if ($parentTypes !== ['variable']) {
                    $out[] = $this->diagnostic(
                        $path,
                        'parent.terms.product_type',
                        'WooCommerce variations require exactly one variable product_type parent'
                    );
                }
                $parentPos = $this->relationship_slugs(
                    $path,
                    'parent.terms.pos_product_visibility',
                    $parentTerms['pos_product_visibility'] ?? [],
                    'pos_product_visibility',
                    $termIndex,
                    $out
                );
                if (($pos === ['pos-hidden']) !== ($parentPos === ['pos-hidden'])) {
                    $out[] = $this->diagnostic(
                        $path,
                        'terms.pos_product_visibility',
                        'WooCommerce variation POS visibility must exactly inherit its variable parent'
                    );
                }
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $out @return list<string> */
    private function relationship_slugs(
        string $path,
        string $locator,
        mixed $references,
        string $taxonomy,
        array $termIndex,
        array &$out
    ): array {
        if (!is_array($references) || !array_is_list($references)) {
            $out[] = $this->diagnostic(
                $path,
                $locator,
                "WooCommerce $taxonomy relationships must be a canonical list of term identities"
            );
            return [];
        }
        $slugs = [];
        $seen = [];
        foreach ($references as $reference) {
            if (!is_string($reference) || isset($seen[$reference])) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce $taxonomy relationships contain a malformed or duplicate term identity"
                );
                continue;
            }
            $seen[$reference] = true;
            $term = $termIndex[$reference] ?? null;
            if (!is_array($term) || ($term['taxonomy'] ?? null) !== $taxonomy) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce $taxonomy relationship does not resolve to the exact taxonomy"
                );
                continue;
            }
            $slugs[] = (string) ($term['slug'] ?? '');
        }
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    private static function cogs_meta_value_supported(string $value): bool {
        if ($value === '' || strlen($value) > 128
            || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:E[+-]?(?:0|[1-9][0-9]*))?$/D', $value) !== 1) {
            return false;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return false;
        }
        // Every exact 11.0.x core writer passes a float to post metadata.
        // WordPress therefore persists PHP's string cast, not an arbitrary
        // equivalent decimal spelling. Refusing e.g. 1.2300/1.0E+3/0E+9
        // keeps direct SQL apply inside the same byte grammar a native save
        // can create and makes recapture stable without normalizing data.
        if ($value !== (string) $number) {
            return false;
        }
        $mantissa = explode('E', ltrim($value, '-'), 2)[0];
        if ($number === 0.0 && preg_match('/[1-9]/', $mantissa) === 1) {
            // A source float this small reaches set_cogs_value() as zero and
            // cannot persist the exponent bytes captured in the repository.
            return false;
        }
        // wpdb binds Woo's float derivation through its string form. Reparse
        // that exact transport spelling before applying the DECIMAL bound so
        // PHP precision that renders a near-limit value as 1.0E+15 refuses.
        $transport = (float) (string) $number;
        return is_finite($transport) && abs($transport) < 1000000000000000.0;
    }

    /** @return list<array<string,mixed>> */
    private function term_diagnostics(array $entity, array $postIndex): array {
        $front = $entity['data'] ?? Canon::decode((string) ($entity['content'] ?? ''));
        $taxonomy = (string) ($front['taxonomy'] ?? '');
        $meta = (array) ($front['meta'] ?? []);
        $path = (string) ($entity['path'] ?? '');
        $out = [];

        if (in_array($taxonomy, ['product_type', 'product_visibility', 'pos_product_visibility'], true)) {
            $slug = (string) ($front['slug'] ?? '');
            $name = (string) ($front['name'] ?? '');
            $allowed = $taxonomy === 'product_type'
                ? ['simple', 'grouped', 'variable', 'external']
                : ($taxonomy === 'product_visibility' ? self::PRODUCT_VISIBILITY_TERMS : ['pos-hidden']);
            if (!in_array($slug, $allowed, true) || $name !== $slug) {
                $out[] = $this->diagnostic(
                    $path,
                    'slug',
                    "WooCommerce $taxonomy permits only its exact core slug/name identities"
                );
            }
            if (($front['parent'] ?? null) !== null
                || (string) ($front['description'] ?? '') !== ''
                || $meta !== []
                || (array) ($front['relationships'] ?? []) !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    'taxonomy',
                    "WooCommerce $taxonomy core projection terms must have no parent, description, metadata, or outbound relationships"
                );
            }
        }

        $allowedTaxonomies = [
            'color' => 'global product attribute',
            'image' => 'global product attribute',
            'display_type' => 'product_cat or product_brand',
            'order' => 'product_cat, product_brand, or global product attribute',
            'thumbnail_id' => 'product_cat or product_brand',
            'tracking_url_template' => 'wc_fulfillment_shipping_provider',
            'icon' => 'wc_fulfillment_shipping_provider',
            'product_ids' => 'product taxonomy cache',
        ];
        foreach ($allowedTaxonomies as $key => $description) {
            if (!array_key_exists($key, $meta) || $this->term_key_matches_taxonomy($key, $taxonomy)) {
                continue;
            }
            $out[] = $this->diagnostic(
                $path,
                "meta.$key",
                "WooCommerce term meta $key is valid only on $description terms"
            );
        }
        if (array_key_exists('display_type', $meta)
            && (!is_string($meta['display_type'])
                || !in_array($meta['display_type'], ['', 'products', 'subcategories', 'both'], true))) {
            $out[] = $this->diagnostic(
                $path,
                'meta.display_type',
                'WooCommerce category/brand REST display type must be default, products, subcategories, or both'
            );
        }
        if (array_key_exists('order', $meta)
            && (!is_string($meta['order'])
                || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $meta['order']) !== 1
                || (int) $meta['order'] > 2147483647)) {
            $out[] = $this->diagnostic(
                $path,
                'meta.order',
                'WooCommerce term order must be a canonical non-negative 32-bit integer string'
            );
        }
        if (array_key_exists('color', $meta)
            && (!is_string($meta['color'])
                || preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/D', $meta['color']) !== 1)) {
            $out[] = $this->diagnostic(
                $path,
                'meta.color',
                'WooCommerce visual attribute color must be an exact three- or six-digit hex color'
            );
        }
        if (array_key_exists('color', $meta) && array_key_exists('image', $meta)) {
            $out[] = $this->diagnostic(
                $path,
                'meta',
                'WooCommerce visual attribute color and image are mutually exclusive native states'
            );
        }
        foreach (['image', 'thumbnail_id'] as $key) {
            if (array_key_exists($key, $meta)) {
                $out = array_merge($out, $this->image_attachment_ref_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    $postIndex
                ));
            }
        }
        foreach (['tracking_url_template', 'icon'] as $key) {
            if (array_key_exists($key, $meta)) {
                $out = array_merge($out, $this->url_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    true,
                    "WooCommerce fulfillment provider $key",
                    true
                ));
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function image_attachment_ref_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        array $postIndex
    ): array {
        if (!is_string($value)
            || preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\}\}$/D', $value, $match) !== 1) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce term image metadata must be one canonical post reference token'
            )];
        }
        $target = $postIndex[$match[1]] ?? null;
        if (!is_array($target)
            || ($target['type'] ?? null) !== 'attachment'
            || !is_string($target['mime'] ?? null)
            || !str_starts_with($target['mime'], 'image/')
            || !is_string($target['file'] ?? null)
            || $target['file'] === '') {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce term image metadata must resolve to a live image attachment in this repository revision'
            )];
        }
        return [];
    }

    private function term_key_matches_taxonomy(string $key, string $taxonomy): bool {
        return match ($key) {
            'color', 'image' => $this->is_global_attribute_name($taxonomy),
            'display_type' => in_array($taxonomy, ['product_cat', 'product_brand'], true),
            'order' => in_array($taxonomy, ['product_cat', 'product_brand'], true)
                || $this->is_global_attribute_name($taxonomy),
            'thumbnail_id' => in_array($taxonomy, ['product_cat', 'product_brand'], true),
            'tracking_url_template', 'icon' => $taxonomy === 'wc_fulfillment_shipping_provider',
            'product_ids' => $taxonomy === 'product_cat'
                || $taxonomy === 'product_brand'
                || $taxonomy === 'product_tag'
                || $this->is_global_attribute_name($taxonomy),
            default => false,
        };
    }

    /** @return list<array<string,mixed>> */
    private function option_diagnostics(array $entity): array {
        $front = $entity['data'] ?? Canon::decode((string) ($entity['content'] ?? ''));
        $records = (array) ($front['records'] ?? []);
        $path = (string) ($entity['path'] ?? '');
        $out = [];
        foreach ($records as $name => $record) {
            if (!is_string($name) || !is_array($record) || ($record['state'] ?? null) !== 'present') {
                continue;
            }
            $value = $record['value'] ?? null;
            $locator = "records.$name.value";
            if (in_array($name, self::YES_NO_OPTIONS, true)) {
                if (is_string($value) && in_array($value, ['yes', 'no'], true)) {
                    continue;
                }
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce option $name must be exact yes or no"
                );
                continue;
            }
            if (array_key_exists($name, self::ENUM_OPTIONS)) {
                if (!is_string($value) || !in_array($value, self::ENUM_OPTIONS[$name], true)) {
                    $out[] = $this->diagnostic(
                        $path,
                        $locator,
                        "WooCommerce option $name is outside its exact native value set"
                    );
                }
                continue;
            }
            if (in_array($name, self::ORDER_STATUS_OPTIONS, true)) {
                $out = array_merge($out, $this->order_statuses_diagnostics($path, $locator, $value, $name));
                continue;
            }
            if (array_key_exists($name, self::NATIVE_TEXT_OPTIONS)) {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    $locator,
                    $value,
                    self::NATIVE_TEXT_OPTIONS[$name],
                    "WooCommerce option $name"
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($value)) {
                    $out = array_merge($out, $this->native_text_canonical_diagnostics(
                        $path,
                        $locator,
                        $value,
                        "WooCommerce option $name"
                    ));
                }
                continue;
            }
            if (array_key_exists($name, self::IMAGE_DIMENSION_OPTIONS)) {
                $out = array_merge($out, $this->bounded_absint_diagnostics(
                    $path,
                    $locator,
                    $value,
                    $name,
                    self::IMAGE_DIMENSION_OPTIONS[$name]
                ));
                continue;
            }
            if (in_array($name, self::POSITIVE_INTEGER_OPTIONS, true)) {
                $out = array_merge($out, $this->positive_integer_diagnostics($path, $locator, $value, $name));
                continue;
            }
            if ($name === 'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold') {
                if (!is_string($value)
                    || ($value !== ''
                        && (preg_match('/^(?:0|[1-9][0-9]{0,6})$/D', $value) !== 1
                            || (int) $value > 3650000))) {
                    $out[] = $this->diagnostic(
                        $path,
                        $locator,
                        'WooCommerce stock-notification retention must be canonical whole days from 0 through 3650000'
                    );
                }
                continue;
            }
            if ($name === 'woocommerce_graphql_endpoint_url') {
                $out = array_merge($out, $this->graphql_endpoint_diagnostics($path, $locator, $value));
                continue;
            }
            if ($name === 'woocommerce_gateway_order') {
                $out = array_merge($out, $this->gateway_order_diagnostics($path, $locator, $value));
                continue;
            }
            if ($name === 'woocommerce_pickup_location_settings') {
                $out = array_merge($out, $this->pickup_settings_diagnostics($path, $locator, $value));
                continue;
            }
            if ($name === 'pickup_location_pickup_locations') {
                $out = array_merge($out, $this->pickup_locations_diagnostics($path, $locator, $value));
                continue;
            }
            if (array_key_exists($name, self::NATIVE_HTML_OPTIONS)) {
                $out = array_merge($out, $this->native_html_diagnostics(
                    $path,
                    $locator,
                    $value,
                    self::NATIVE_HTML_OPTIONS[$name],
                    "WooCommerce option $name"
                ));
            }
        }
        $out = array_merge($out, $this->thumbnail_projection_diagnostics($path, $records));
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function bounded_absint_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        string $name,
        int $max
    ): array {
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > 19
            || (string) (int) $value !== $value
            || (int) $value > $max) {
            return [$this->diagnostic(
                $path,
                $locator,
                "WooCommerce option $name must be a canonical native absint string from 0 through $max"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function thumbnail_projection_diagnostics(string $path, array $records): array {
        $mode = $this->present_option_string($records, 'woocommerce_thumbnail_cropping') ?? '1:1';
        if ($mode !== 'custom') {
            return [];
        }

        $thumbnailWidth = $this->canonical_present_absint(
            $records,
            'woocommerce_thumbnail_image_width',
            300,
            self::IMAGE_DIMENSION_OPTIONS['woocommerce_thumbnail_image_width']
        );
        $ratioWidth = $this->canonical_present_absint(
            $records,
            'woocommerce_thumbnail_cropping_custom_width',
            4,
            self::IMAGE_DIMENSION_OPTIONS['woocommerce_thumbnail_cropping_custom_width']
        );
        $ratioHeight = $this->canonical_present_absint(
            $records,
            'woocommerce_thumbnail_cropping_custom_height',
            3,
            self::IMAGE_DIMENSION_OPTIONS['woocommerce_thumbnail_cropping_custom_height']
        );
        if ($thumbnailWidth === null || $ratioWidth === null || $ratioHeight === null) {
            return [];
        }

        $projectedHeight = (int) round(
            ($thumbnailWidth / max(1, $ratioWidth)) * max(1, $ratioHeight)
        );
        if ($projectedHeight <= self::MAX_IMAGE_DIMENSION) {
            return [];
        }
        return [$this->diagnostic(
            $path,
            'records.woocommerce_thumbnail_cropping_custom_height.value',
            'WooCommerce custom thumbnail ratio would exceed the 32768-pixel derived image boundary'
        )];
    }

    private function present_option_string(array $records, string $name): ?string {
        $record = $records[$name] ?? null;
        return is_array($record)
            && ($record['state'] ?? null) === 'present'
            && is_string($record['value'] ?? null)
                ? $record['value']
                : null;
    }

    private function canonical_present_absint(array $records, string $name, int $default, int $max): ?int {
        $value = $this->present_option_string($records, $name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > 19
            || (string) (int) $value !== $value
            || (int) $value > $max) {
            return null;
        }
        return (int) $value;
    }

    /** @return list<array<string,mixed>> */
    private function order_statuses_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        string $name
    ): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > 128) {
            return [$this->diagnostic(
                $path,
                $locator,
                "WooCommerce option $name must be a list of at most 128 order-status slugs"
            )];
        }
        $seen = [];
        foreach ($value as $status) {
            if (!is_string($status)
                || strlen($status) > 64
                || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $status) !== 1
                || isset($seen[$status])) {
                return [$this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce option $name must contain unique bounded order-status slugs"
                )];
            }
            $seen[$status] = true;
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function pickup_settings_diagnostics(string $path, string $locator, mixed $value): array {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce local-pickup settings must be the exact native named record'
            )];
        }
        if ($value === []) {
            return [];
        }
        $fields = array_keys($value);
        sort($fields, SORT_STRING);
        if (array_diff($fields, self::PICKUP_SETTINGS_FIELDS) !== []) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce local-pickup settings permit only enabled, title, tax_status, and cost'
            )];
        }

        $out = [];
        if (array_key_exists('enabled', $value)
            && (!is_string($value['enabled']) || !in_array($value['enabled'], ['yes', 'no'], true))) {
            $out[] = $this->diagnostic(
                $path,
                "$locator.enabled",
                'WooCommerce local-pickup enabled must be exact yes or no'
            );
        }
        if (array_key_exists('tax_status', $value)
            && (!is_string($value['tax_status']) || !in_array($value['tax_status'], ['taxable', 'none'], true))) {
            $out[] = $this->diagnostic(
                $path,
                "$locator.tax_status",
                'WooCommerce local-pickup tax_status must be exact taxable or none'
            );
        }
        if (array_key_exists('title', $value)) {
            $titleDiagnostics = $this->bounded_text_diagnostics(
                $path,
                "$locator.title",
                $value['title'],
                4096,
                'WooCommerce local-pickup title'
            );
            $out = array_merge($out, $titleDiagnostics);
            if ($titleDiagnostics === [] && is_string($value['title'])) {
                $out = array_merge($out, $this->native_text_canonical_diagnostics(
                    $path,
                    "$locator.title",
                    $value['title'],
                    'WooCommerce local-pickup title'
                ));
            }
        }
        if (array_key_exists('cost', $value)) {
            $out = array_merge($out, $this->pickup_cost_diagnostics(
                $path,
                "$locator.cost",
                $value['cost']
            ));
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function pickup_cost_diagnostics(string $path, string $locator, mixed $value): array {
        $textDiagnostics = $this->bounded_text_diagnostics(
            $path,
            $locator,
            $value,
            1024,
            'WooCommerce local-pickup cost'
        );
        if ($textDiagnostics !== [] || !is_string($value) || $value === '') {
            return $textDiagnostics;
        }
        // Deploy compiles before plugin activation (Policy.php:2566-2574), so
        // repository validity cannot depend on Woo's runtime being loaded.
        // Both admitted artifacts' wc_format_decimal($string, false) branch
        // has this exact fixed-point language once its is_numeric() guard is
        // applied: finite base-10 bytes, optional leading minus, no exponent,
        // locale separator, whitespace, trailing dot, or normalization.
        if (preg_match('/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D', $value) !== 1) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce local-pickup cost must already equal its exact native decimal calculation bytes'
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function pickup_locations_diagnostics(string $path, string $locator, mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_PICKUP_LOCATIONS) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce pickup locations must be an ordered list of at most 256 native records'
            )];
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_PICKUP_BYTES) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce pickup locations exceed the one-megabyte aggregate boundary'
            )];
        }

        $out = [];
        foreach ($value as $index => $location) {
            $rowLocator = "$locator.$index";
            if (!is_array($location) || array_is_list($location)) {
                $out[] = $this->diagnostic(
                    $path,
                    $rowLocator,
                    'WooCommerce pickup location must be the exact native named record'
                );
                continue;
            }
            $fields = array_keys($location);
            sort($fields, SORT_STRING);
            if ($fields !== self::PICKUP_LOCATION_FIELDS) {
                $out[] = $this->diagnostic(
                    $path,
                    $rowLocator,
                    'WooCommerce pickup location permits only name, address, details, and enabled'
                );
                continue;
            }
            foreach (['name' => 4096, 'details' => 16384] as $field => $maxBytes) {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    "$rowLocator.$field",
                    $location[$field],
                    $maxBytes,
                    "WooCommerce pickup location $field"
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($location[$field])) {
                    $out = array_merge(
                        $out,
                        in_array($field, ['name', 'details'], true)
                            ? $this->native_html_diagnostics(
                                $path,
                                "$rowLocator.$field",
                                $location[$field],
                                $maxBytes,
                                "WooCommerce pickup location $field"
                            )
                            : $this->native_text_canonical_diagnostics(
                                $path,
                                "$rowLocator.$field",
                                $location[$field],
                                'WooCommerce pickup location name'
                            )
                    );
                }
            }
            if (!is_bool($location['enabled'])) {
                $out[] = $this->diagnostic(
                    $path,
                    "$rowLocator.enabled",
                    'WooCommerce pickup location enabled must be a native REST boolean'
                );
            }
            $address = $location['address'];
            if (!is_array($address) || array_is_list($address)) {
                $out[] = $this->diagnostic(
                    $path,
                    "$rowLocator.address",
                    'WooCommerce pickup location address must be the exact native named record'
                );
                continue;
            }
            $addressFields = array_keys($address);
            sort($addressFields, SORT_STRING);
            if ($addressFields !== self::PICKUP_ADDRESS_FIELDS) {
                $out[] = $this->diagnostic(
                    $path,
                    "$rowLocator.address",
                    'WooCommerce pickup location address permits only address_1, city, state, postcode, and country'
                );
                continue;
            }
            foreach (self::PICKUP_ADDRESS_FIELDS as $field) {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    "$rowLocator.address.$field",
                    $address[$field],
                    4096,
                    "WooCommerce pickup location address $field"
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($address[$field])) {
                    $out = array_merge($out, $this->native_text_canonical_diagnostics(
                        $path,
                        "$rowLocator.address.$field",
                        $address[$field],
                        "WooCommerce pickup location address $field"
                    ));
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function positive_integer_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        string $name
    ): array {
        $normalized = is_string($value) ? ltrim($value, '0') : '';
        if (!is_string($value)
            || preg_match('/^[0-9]+$/D', $value) !== 1
            || strlen($value) > 19
            || (int) $value < 1
            || (string) (int) $normalized !== $normalized) {
            return [$this->diagnostic(
                $path,
                $locator,
                "WooCommerce option $name must be a positive PHP-range integer string"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function graphql_endpoint_diagnostics(string $path, string $locator, mixed $value): array {
        $textDiagnostics = $this->bounded_text_diagnostics(
            $path,
            $locator,
            $value,
            512,
            'WooCommerce GraphQL endpoint path'
        );
        if ($textDiagnostics !== [] || !is_string($value)) {
            return $textDiagnostics;
        }
        $parts = explode('/', $value);
        if (count($parts) < 2) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce GraphQL endpoint path must contain at least two segments'
            )];
        }
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $part) !== 1) {
                return [$this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce GraphQL endpoint path must already equal its native normalized segment grammar'
                )];
            }
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function gateway_order_diagnostics(string $path, string $locator, mixed $value): array {
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 256) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce gateway order must be a named map of at most 256 stable provider ids'
            )];
        }
        $orders = [];
        foreach ($value as $gateway => $order) {
            if (!is_string($gateway)
                || strlen($gateway) > 128
                || preg_match('/^[a-z0-9_][a-z0-9_.-]*$/D', $gateway) !== 1
                || !is_int($order)
                || $order < 0
                || $order > 100000
                || isset($orders[$order])) {
                return [$this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce gateway order must contain unique bounded integer positions keyed by stable provider ids'
                )];
            }
            $orders[$order] = true;
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function native_html_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        int $maxBytes,
        string $label
    ): array {
        $textDiagnostics = $this->bounded_text_diagnostics($path, $locator, $value, $maxBytes, $label);
        if ($textDiagnostics !== [] || !is_string($value)) {
            return $textDiagnostics;
        }
        if (!function_exists('wp_kses_post')) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label cannot be validated because the native WordPress HTML sanitizer is unavailable"
            )];
        }
        $sanitized = \wp_kses_post($value);
        if (!is_string($sanitized) || !hash_equals($value, $sanitized)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must already equal the exact native WordPress HTML-sanitized bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function bounded_text_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        int $maxBytes,
        string $label
    ): array {
        if (!is_string($value)) {
            return [$this->diagnostic($path, $locator, "$label must be a string")];
        }
        if (strlen($value) > $maxBytes || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must be valid UTF-8 without controls and at most $maxBytes bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function url_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        bool $allowEmpty,
        string $label,
        bool $requireNativeFilter
    ): array {
        $textDiagnostics = $this->bounded_text_diagnostics($path, $locator, $value, 8192, $label);
        if ($textDiagnostics !== [] || !is_string($value)) {
            return $textDiagnostics;
        }
        if ($value === '' && $allowEmpty) {
            return [];
        }
        if ($value === '') {
            return [$this->diagnostic($path, $locator, "$label must be non-empty")];
        }
        $testable = str_replace('__PLACEHOLDER__', 'test', $value);
        foreach (['{{home}}', '{{uploads}}'] as $token) {
            if (str_starts_with($testable, $token)) {
                $testable = 'https://portable.invalid' . substr($testable, strlen($token));
                break;
            }
        }
        $parts = parse_url($testable);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || preg_match('/\s/u', $testable) === 1
            || ($requireNativeFilter && filter_var($testable, FILTER_VALIDATE_URL) === false)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must be a portable HTTP or HTTPS URL without credentials"
            )];
        }
        return $this->native_url_canonical_diagnostics($path, $locator, $value, $label);
    }

    /** @return list<array<string,mixed>> */
    private function native_url_canonical_diagnostics(
        string $path,
        string $locator,
        string $value,
        string $label
    ): array {
        if (!function_exists('esc_url_raw')) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label cannot be validated because the native WordPress URL sanitizer is unavailable"
            )];
        }
        $candidate = str_replace(
            ['{{home}}', '{{uploads}}'],
            ['https://portable.invalid', 'https://portable.invalid/uploads'],
            $value
        );
        $candidate = preg_replace(
            '/\{\{post:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}\}/D',
            '1',
            $candidate
        );
        $sanitized = is_string($candidate) ? \esc_url_raw($candidate, ['http', 'https']) : null;
        if (!is_string($sanitized) || !is_string($candidate) || !hash_equals($candidate, $sanitized)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must already equal the exact native WordPress URL-sanitized bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function native_text_canonical_diagnostics(
        string $path,
        string $locator,
        string $value,
        string $label
    ): array {
        if (!function_exists('sanitize_text_field')) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label cannot be validated because the native WordPress text sanitizer is unavailable"
            )];
        }
        $sanitized = \sanitize_text_field($value);
        if (!is_string($sanitized) || !hash_equals($value, $sanitized)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must already equal the exact native WordPress text-sanitized bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function download_diagnostics(string $path, mixed $downloads): array {
        if (!is_array($downloads)) {
            return [$this->diagnostic(
                $path,
                'meta._downloadable_files',
                'WooCommerce downloadable files must be an object keyed by download identity'
            )];
        }
        if ($downloads !== [] && array_is_list($downloads)) {
            return [$this->diagnostic(
                $path,
                'meta._downloadable_files',
                'WooCommerce downloadable files must be a named object, not a positional list'
            )];
        }

        $out = [];
        foreach ($downloads as $downloadKey => $row) {
            $locator = 'meta._downloadable_files.' . (is_string($downloadKey) ? $downloadKey : (string) $downloadKey);
            if (!is_string($downloadKey) || $downloadKey === '' || strlen($downloadKey) > 128) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce download identities must be non-empty strings of at most 128 bytes'
                );
                continue;
            }
            if (!is_array($row) || array_is_list($row)) {
                $out[] = $this->diagnostic($path, $locator, 'WooCommerce downloadable-file rows must be named objects');
                continue;
            }
            $unknown = array_values(array_diff(array_keys($row), self::DOWNLOAD_FIELDS));
            if ($unknown !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce downloadable-file row has unsupported addon-owned field(s): '
                    . implode(', ', array_map('strval', $unknown))
                );
            }
            foreach (['name', 'file'] as $field) {
                if (!array_key_exists($field, $row) || !is_string($row[$field])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.$field",
                        "WooCommerce downloadable-file $field must be a string"
                    );
                }
            }
            if (is_string($row['file'] ?? null)) {
                if ($row['file'] === '') {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.file",
                        'WooCommerce downloadable-file file must be non-empty'
                    );
                } elseif (str_starts_with($row['file'], '[') && str_ends_with($row['file'], ']')) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.file",
                        'WooCommerce shortcode download locators are extension-executed and outside this adapter contract'
                    );
                }
            }
            if (array_key_exists('id', $row)
                && (!is_string($row['id']) || $row['id'] !== $downloadKey)) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.id",
                    'WooCommerce downloadable-file id must equal its object key'
                );
            }
            if (array_key_exists('enabled', $row)) {
                if (!is_bool($row['enabled'])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.enabled",
                        'WooCommerce downloadable-file enabled must be boolean when present'
                    );
                } elseif ($row['enabled'] !== true) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.enabled",
                        'WooCommerce disabled download rows reflect site-local approval state and are outside the portable authored contract'
                    );
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function attribute_diagnostics(string $path, mixed $attributes): array {
        if (!is_array($attributes)) {
            return [$this->diagnostic(
                $path,
                'meta._product_attributes',
                'WooCommerce product attributes must be an object keyed by the native attribute name'
            )];
        }
        if ($attributes !== [] && array_is_list($attributes)) {
            return [$this->diagnostic(
                $path,
                'meta._product_attributes',
                'WooCommerce product attributes must be a named object, not a positional list'
            )];
        }

        $out = [];
        foreach ($attributes as $attributeKey => $row) {
            $locator = 'meta._product_attributes.' . (is_string($attributeKey) ? $attributeKey : (string) $attributeKey);
            if (!is_string($attributeKey) || $attributeKey === '') {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute keys must be non-empty strings'
                );
                continue;
            }
            if (!is_array($row) || array_is_list($row)) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute rows must be named objects'
                );
                continue;
            }
            $missing = array_values(array_diff(self::REQUIRED_FIELDS, array_keys($row)));
            if ($missing !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute row is missing required field(s): ' . implode(', ', $missing)
                );
                continue;
            }
            $unknown = array_values(array_diff(array_keys($row), self::REQUIRED_FIELDS));
            if ($unknown !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute row has unsupported addon-owned field(s): '
                    . implode(', ', array_map('strval', $unknown))
                );
            }
            if (!is_string($row['name']) || $row['name'] === '') {
                $out[] = $this->diagnostic($path, "$locator.name", 'WooCommerce product attribute name must be a non-empty string');
            }
            if (!is_string($row['value'])) {
                $out[] = $this->diagnostic($path, "$locator.value", 'WooCommerce product attribute value must be a string');
            }
            if (!is_int($row['position']) || $row['position'] < 0) {
                $out[] = $this->diagnostic($path, "$locator.position", 'WooCommerce product attribute position must be a non-negative integer');
            }
            foreach (['is_visible', 'is_variation', 'is_taxonomy'] as $flag) {
                if (!is_int($row[$flag]) || ($row[$flag] !== 0 && $row[$flag] !== 1)) {
                    $out[] = $this->diagnostic($path, "$locator.$flag", "WooCommerce product attribute $flag must be integer 0 or 1");
                }
            }
            if (($row['is_taxonomy'] ?? null) === 1) {
                if (($row['name'] ?? null) !== $attributeKey
                    || !$this->is_global_attribute_name($attributeKey)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.name",
                        'WooCommerce global attribute name must equal its pa_* object key'
                    );
                }
                if (($row['value'] ?? null) !== '') {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.value",
                        'WooCommerce global attribute value must be empty; term relationships carry its options'
                    );
                }
            }
        }
        return $out;
    }

    /**
     * WordPress rejects taxonomy names over 32 bytes, while Woo prefixes the
     * stored attribute slug with `pa_`. Lowercase/caseless Unicode letters
     * and decimal digits are the multibyte counterpart of the prior ASCII
     * grammar; uppercase, symbols, number-letters, invalid UTF-8, and an empty
     * slug remain outside the reviewed boundary.
     */
    private function is_global_attribute_name(string $name): bool {
        return strlen($name) <= 32
            && preg_match('/^pa_[\p{Ll}\p{Lo}\p{Nd}][\p{Ll}\p{Lo}\p{Nd}_-]*$/uD', $name) === 1;
    }

    /** @return array{code:string,path:string,locator:string,message:string} */
    private function diagnostic(string $path, string $locator, string $message): array {
        return [
            'code' => 'adapter_schema_content_mismatch',
            'path' => $path,
            'locator' => $locator,
            'message' => $message,
        ];
    }
}
