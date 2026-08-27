<?php
declare(strict_types=1);

namespace Duo\Providers;

use Duo\ManifestProviderRuntime;
use Duo\ProviderSdk;

/**
 * The Events Calendar Category Colors derived-CSS regeneration provider.
 *
 * TEC writes category colors as term metadata, then regenerates the
 * `tec_events_category_color_css` option only from its wp-admin save hook.
 * Duo materializes term metadata directly, so that hook is unreachable and a
 * target otherwise keeps its old colors indefinitely. The native CSS
 * generator remains the sole CSS writer here. The provider executes the exact
 * two-service body audited from Controller::generate_css() in both pins, then
 * verifies every selector/value and the cache-bust postcondition against the
 * same native identities used by TEC 6.17.2/6.17.3.
 */
final class TheEventsCalendarCategoryColors extends ManifestProviderRuntime {
    private const CSS_OPTION = 'tec_events_category_color_css';
    private const SETTINGS_OPTION = 'tribe_events_calendar_options';
    private const TAXONOMY = 'tribe_events_cat';
    private const COLOR_META = [
        'primary' => 'tec-events-cat-colors-primary',
        'secondary' => 'tec-events-cat-colors-secondary',
        'text' => 'tec-events-cat-colors-text',
    ];
    private const COLOR_PROPERTIES = [
        'primary' => '--tec-color-category-primary',
        'secondary' => '--tec-color-category-secondary',
        'text' => '--tec-color-category-text',
    ];
    private const HIDDEN_META = 'tec-events-cat-colors-hidden';
    private const PRIORITY_META = 'tec-events-cat-colors-priority';
    private const PROJECTION_BEFORE = 'before';
    private const PROJECTION_AFTER_INVOKE = 'after_invoke';
    private const PROJECTION_RECONCILE = 'reconcile';

    // TEC's native generator walks the complete taxonomy and its native
    // dropdown primes term-meta caches. Bound the physical frontier before
    // either path can allocate attacker-controlled target rows.
    private const MAX_CATEGORY_COUNT = 10000;
    private const MAX_CSS_BYTES = 8388608;
    private const MAX_SETTINGS_BYTES = 1048576;
    private const MAX_DROPDOWN_ROWS = 10000;
    private const MAX_RELEVANT_META_VALUE_BYTES = 1024;
    private const MAX_TERM_META_BYTES = 33554432;
    private const MAX_TERM_META_ROWS = 100000;
    private const MAX_TERM_TEXT_BYTES = 1024;
    // Generator::fetch_category_meta() uses an unordered LIMIT/OFFSET walk in
    // 500-row pages. One complete page is deterministic; a second populated
    // page can skip or duplicate rows even on an otherwise valid large site.
    private const MAX_NATIVE_GENERATOR_META_ROWS = 500;
    private const MAX_HOOK_RECORDS = 64;

    private const CATEGORY_OPTION = 'category-color-show-hidden-categories';

    private const GENERATOR_PROPERTIES = [
        'keys' => [
            'primary' => 'tec-events-cat-colors-primary',
            'secondary' => 'tec-events-cat-colors-secondary',
            'text' => 'tec-events-cat-colors-text',
            'priority' => 'tec-events-cat-colors-priority',
            'hide_from_legend' => 'tec-events-cat-colors-hidden',
        ],
        'option_key' => self::CSS_OPTION,
        'generated_css' => '',
    ];

    private const DROPDOWN_PROPERTIES = [
        'keys' => [
            'primary' => 'tec-events-cat-colors-primary',
            'secondary' => 'tec-events-cat-colors-secondary',
            'text' => 'tec-events-cat-colors-text',
            'priority' => 'tec-events-cat-colors-priority',
            'hide_from_legend' => 'tec-events-cat-colors-hidden',
        ],
        'disallowed_shortcodes' => ['admin-manager'],
    ];

    /** @var list<string> */
    private const OUTPUT_FILTER_HOOKS = [
        'tec_events_category_color_category_meta',
        'tec_events_category_color_dropdown_categories',
        'tec_events_category_color_filtered_categories',
        'tec_events_category_color_generator_batch_size',
        'tec_events_category_color_generator_final_css',
        'tec_events_category_color_raw_categories',
        'tec_events_category_color_sorted_categories',
        'tec_events_category_validate_meta_key',
        'default_option_tribe_events_calendar_options',
        'option_tribe_events_calendar_options',
        'pre_option_tribe_events_calendar_options',
        'tribe_get_option',
        'tribe_get_option_category-color-show-hidden-categories',
        'tribe_get_single_option',
        // Generator::save_css() crosses WordPress's complete option hook
        // topology. Any callback can rewrite the stored receipt or add an
        // undeclared effect, so exact free-plugin regeneration refuses before
        // the controller when one is populated.
        'default_option_tec_events_category_color_css',
        'alloptions',
        'pre_cache_alloptions',
        'pre_option',
        'pre_option_tec_events_category_color_css',
        'pre_wp_load_alloptions',
        'option_tec_events_category_color_css',
        'pre_update_option_tec_events_category_color_css',
        'sanitize_option_tec_events_category_color_css',
        'update_option_tec_events_category_color_css',
        'add_option_tec_events_category_color_css',
        'pre_update_option',
        'update_option',
        'updated_option',
        'add_option',
        'added_option',
        'wp_autoload_values_to_autoload',
    ];

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_regenerate_css(array $args): array {
        $services = $this->assert_runtime_contract(true);
        $before = $this->projection(self::PROJECTION_BEFORE);

        if (!is_array($services)) {
            throw new \LogicException('duo: Category Colors invocation lost its prepared native services');
        }
        $generator = $services['generator'];
        $dropdown = $services['dropdown'];
        // The bounded projection performs several target reads after service
        // resolution. Hold the exact reviewed value services and revalidate
        // their pre-call state so a mutable container cannot switch in a
        // second same-class implementation between preflight and execution.
        $this->assert_native_service_state(
            $generator,
            \TEC\Events\Category_Colors\CSS\Generator::class,
            'generate_and_save_css',
            self::GENERATOR_PROPERTIES,
            'CSS generator'
        );
        $this->assert_native_service_state(
            $dropdown,
            \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class,
            'bust_dropdown_categories_cache',
            self::DROPDOWN_PROPERTIES,
            'dropdown provider'
        );
        // This is the exact two-call body of Controller::generate_css() in
        // both pinned artifacts. Resolving each reviewed native service here
        // prevents a container override hidden behind Controller::$container
        // from executing an unreviewed Generator implementation.
        $generator->generate_and_save_css();
        $this->assert_native_service_state(
            $dropdown,
            \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class,
            'bust_dropdown_categories_cache',
            self::DROPDOWN_PROPERTIES,
            'dropdown provider'
        );
        $dropdown->bust_dropdown_categories_cache();

        return [
            'before' => $before,
            'after' => $this->projection(self::PROJECTION_AFTER_INVOKE),
            'verified' => true,
        ];
    }

    /** @return array<string,mixed> */
    protected function reconcile_regenerate_css(array $args): array {
        return $this->projection(self::PROJECTION_RECONCILE);
    }

    /** @return ?array{generator:object,dropdown:object} */
    private function assert_runtime_contract(bool $invokeServices): ?array {
        foreach ([
            'apply_filters',
            'get_option',
            'get_term_meta',
            'get_terms',
            'has_filter',
            'is_wp_error',
            'sanitize_html_class',
            'sanitize_title',
            'tribe',
            'tribe_cache',
            'tribe_get_option',
            'wp_using_ext_object_cache',
        ] as $required) {
            if (!function_exists($required)) {
                throw new \RuntimeException(
                    "duo: The Events Calendar Category Colors regeneration requires $required()"
                );
            }
        }
        $services = null;
        if ($invokeServices) {
            $generator = $this->native_service(
                \TEC\Events\Category_Colors\CSS\Generator::class,
                'generate_and_save_css',
                self::GENERATOR_PROPERTIES,
                'CSS generator'
            );
            $dropdown = $this->native_service(
                \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class,
                'bust_dropdown_categories_cache',
                self::DROPDOWN_PROPERTIES,
                'dropdown provider'
            );
            $this->native_cache();
            $services = ['generator' => $generator, 'dropdown' => $dropdown];
        }
        if (wp_using_ext_object_cache()) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors regeneration does not admit an external object-cache topology'
            );
        }
        global $wpdb;
        if (!is_object($wpdb)
            || !is_callable([$wpdb, 'get_results'])
            || !is_callable([$wpdb, 'prepare'])
            || !isset($wpdb->options, $wpdb->term_taxonomy, $wpdb->termmeta, $wpdb->terms)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors regeneration requires the WordPress database reader'
            );
        }
        $this->assert_hook_contract();
        foreach ([
            \TEC\Events\Category_Colors\CSS\Controller::class,
            \TEC\Events\Category_Colors\CSS\Generator::class,
            \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class,
            \Tribe__Cache::class,
            \Tribe__Utils__Color::class,
        ] as $required) {
            if (!class_exists($required)) {
                throw new \RuntimeException(
                    "duo: The Events Calendar Category Colors regeneration requires class $required"
                );
            }
        }
        return $services;
    }

    /** @return array<string,mixed> */
    private function projection(string $mode): array {
        if (!in_array($mode, [
            self::PROJECTION_BEFORE,
            self::PROJECTION_AFTER_INVOKE,
            self::PROJECTION_RECONCILE,
        ], true)) {
            throw new \LogicException('duo: invalid Category Colors projection mode');
        }
        $verify = $mode !== self::PROJECTION_BEFORE;
        $this->assert_runtime_contract(false);
        $inventory = $this->category_inventory();
        $rawStored = $this->raw_css_option();
        if ($rawStored === null) {
            if ($verify) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors CSS option is absent after regeneration; '
                    . 'recovery_required'
                );
            }
            $stored = '';
        } else {
            $stored = $rawStored;
        }
        $expected = $inventory['css'];
        $expectedDropdown = $inventory['dropdown'];
        [$actualCss, $selectorMismatchCount, $valueMismatchCount, $orderMismatchCount, $exactMismatchCount] =
            $this->css_projection($stored, $expected);
        $requireAbsentCache = $mode === self::PROJECTION_AFTER_INVOKE;
        $cacheProjection = $this->dropdown_cache_projection($expectedDropdown, $requireAbsentCache);

        // Native generation and the cache bust happen outside a lock owned by
        // this provider. Re-read both physical inputs and output after those
        // calls: a target-side race may force a retry, but can never certify a
        // CSS/cache projection assembled from two different generations.
        $inventoryWitness = $this->category_inventory();
        $storedWitness = $this->raw_css_option();
        if ($inventoryWitness !== $inventory || $storedWitness !== $rawStored) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors inputs or output changed during verification; '
                . 'recovery_required'
            );
        }
        if ($verify) {
            // Tribe__Cache::get() with its exact default arguments is a raw
            // wp_cache_get observation. Unlike get_dropdown_categories(), it
            // cannot populate a miss. A second fresh read prevents recovery
            // from blessing a stale cache inserted during the durable-input
            // witness reads above.
            $cacheProjection = $this->dropdown_cache_projection($expectedDropdown, $requireAbsentCache);
            if (($cacheProjection['dropdown_cache_mismatch_count'] ?? 1) !== 0
                || ($cacheProjection['dropdown_cache_malformed_count'] ?? 1) !== 0) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors dropdown cache readback is stale or malformed; '
                    . 'recovery_required'
                );
            }
        }

        if ($verify && ($selectorMismatchCount > 0
            || $valueMismatchCount > 0
            || $orderMismatchCount > 0
            || $exactMismatchCount > 0)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors CSS readback has '
                . $selectorMismatchCount . ' selector-set mismatch(es) and '
                . $valueMismatchCount . ' value mismatch(es), '
                . $orderMismatchCount . ' priority-order mismatch(es), '
                . $exactMismatchCount . ' exact-byte grammar mismatch(es); recovery_required'
            );
        }
        return array_merge([
            'category_count' => $inventory['category_count'],
            'colored_category_count' => count($expected),
            'css_bytes' => strlen($stored),
            'css_sha256' => hash('sha256', $stored),
            'css_expected_projection_sha256' => $this->rows_digest($expected),
            'css_actual_projection_sha256' => $this->rows_digest($actualCss),
            'css_selector_count' => count($actualCss),
            'css_selector_mismatch_count' => $selectorMismatchCount,
            'css_value_mismatch_count' => $valueMismatchCount,
            'css_priority_order_mismatch_count' => $orderMismatchCount,
            'css_exact_byte_mismatch_count' => $exactMismatchCount,
            'css_option_present' => $rawStored !== null,
            'dropdown_expected_count' => count($expectedDropdown),
            'dropdown_expected_sha256' => $this->rows_digest($expectedDropdown),
        ], $cacheProjection);
    }

    /**
     * Observe TEC's cache entry without calling get_dropdown_categories(),
     * whose cache-miss path writes. Tribe__Cache::get() with no callback or
     * non-false default is the exact read-only wp_cache_get path in both pins.
     * Native generation must finish absent; recovery accepts absence or the
     * exact current semantic rows after a legitimate frontend repopulation.
     *
     * @param array<string,array<string,mixed>> $expected
     * @return array<string,mixed>
     */
    private function dropdown_cache_projection(array $expected, bool $requireAbsent): array {
        $cache = $this->native_cache();
        try {
            $rows = $cache->get(
                \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::CACHE_KEY
            );
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors dropdown cache is unreadable',
                0,
                $failure
            );
        }
        if ($rows === false) {
            return [
                'dropdown_cache_present' => false,
                'dropdown_cache_actual_count' => 0,
                'dropdown_cache_actual_sha256' => $this->rows_digest([]),
                'dropdown_cache_mismatch_count' => 0,
                'dropdown_cache_malformed_count' => 0,
            ];
        }
        if ($requireAbsent) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors dropdown cache remains populated after the native bust; '
                . 'recovery_required'
            );
        }
        if (!is_array($rows)) {
            return [
                'dropdown_cache_present' => true,
                'dropdown_cache_actual_count' => 0,
                'dropdown_cache_actual_sha256' => $this->rows_digest([]),
                'dropdown_cache_mismatch_count' => count($expected) + 1,
                'dropdown_cache_malformed_count' => 1,
            ];
        }
        if (count($rows) > self::MAX_DROPDOWN_ROWS) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors dropdown exceeds the bounded row frontier'
            );
        }

        $normalized = [];
        $malformed = array_is_list($rows) ? 0 : 1;
        $orderMismatch = 0;
        $previousPriority = null;
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['slug'] ?? null)
                || !is_string($row['name'] ?? null)
                || !is_string($row['primary'] ?? null)
                || !is_int($row['priority'] ?? null)
                || !is_bool($row['hidden'] ?? null)
                || strlen($row['slug']) > self::MAX_TERM_TEXT_BYTES
                || strlen($row['name']) > self::MAX_TERM_TEXT_BYTES
                || strlen($row['primary']) > self::MAX_RELEVANT_META_VALUE_BYTES) {
                ++$malformed;
                continue;
            }
            $slug = $row['slug'];
            if (isset($normalized[$slug])) {
                ++$malformed;
                continue;
            }
            $normalized[$slug] = [
                'slug' => $slug,
                'name' => $row['name'],
                'primary' => $row['primary'],
                'priority' => $row['priority'],
                'hidden' => $row['hidden'],
            ];
            if ($previousPriority !== null && $row['priority'] > $previousPriority) {
                ++$orderMismatch;
            }
            $previousPriority = $row['priority'];
        }
        ksort($normalized, SORT_STRING);
        $mismatch = $malformed + $orderMismatch;
        foreach ($expected as $slug => $row) {
            if (!isset($normalized[$slug]) || $normalized[$slug] !== $row) {
                ++$mismatch;
            }
        }
        $mismatch += count(array_diff_key($normalized, $expected));
        return [
            'dropdown_cache_present' => true,
            'dropdown_cache_actual_count' => count($normalized),
            'dropdown_cache_actual_sha256' => $this->rows_digest($normalized),
            'dropdown_cache_mismatch_count' => $mismatch,
            'dropdown_cache_malformed_count' => $malformed,
        ];
    }

    /**
     * @param array<string,array{block:string,priority:int}> $expected
     * @return array{0:array<string,array{block:string,priority:int}>,1:int,2:int,3:int,4:int}
     */
    private function css_projection(string $stored, array $expected): array {
        $actual = [];
        $duplicates = 0;
        $unknown = 0;
        $valueMismatch = 0;
        $orderMismatch = 0;
        $exactMismatch = 0;
        $previousPriority = null;
        $offset = 0;
        $bytes = strlen($stored);
        $selectorPrefix = '.tribe_events_cat-';
        $selectorPrefixBytes = strlen($selectorPrefix);
        while ($offset < $bytes) {
            if (substr_compare($stored, $selectorPrefix, $offset, $selectorPrefixBytes) !== 0) {
                ++$exactMismatch;
                break;
            }
            $open = strpos($stored, '{', $offset);
            $close = $open === false ? false : strpos($stored, '}', $open + 1);
            $nestedOpen = $open === false ? false : strpos($stored, '{', $open + 1);
            if ($open === false || $close === false
                || $nestedOpen !== false && $nestedOpen < $close) {
                ++$exactMismatch;
                break;
            }
            $selector = substr($stored, $offset, $open - $offset);
            $block = substr($stored, $offset, $close - $offset + 1);
            if (!isset($expected[$selector])) {
                ++$unknown;
                $offset = $close + 1;
                continue;
            }
            if (isset($actual[$selector])) {
                ++$duplicates;
            }
            $actual[$selector] = [
                'block' => $block,
                'priority' => $expected[$selector]['priority'],
            ];
            if (!hash_equals($expected[$selector]['block'], $block)) {
                ++$valueMismatch;
            }
            $priority = $expected[$selector]['priority'];
            if ($previousPriority !== null && $priority < $previousPriority) {
                ++$orderMismatch;
            }
            $previousPriority = $priority;
            $offset = $close + 1;
        }
        $missing = count(array_diff_key($expected, $actual));
        ksort($actual, SORT_STRING);
        return [$actual, $missing + $unknown + $duplicates, $valueMismatch, $orderMismatch, $exactMismatch];
    }

    /**
     * Read the exact physical free-plugin inputs with hard result/value bounds.
     * The native generator/dropdown may run only after this proves their full
     * taxonomy and term-meta cache frontier is finite.
     *
     * @return array{
     *   category_count:int,
     *   css:array<string,array{block:string,priority:int}>,
     *   dropdown:array<string,array{slug:string,name:string,primary:string,priority:int,hidden:bool}>
     * }
     */
    private function category_inventory(): array {
        global $wpdb;
        // This call validates the physical mixed option and its effective
        // native cache/filter view before any plugin category traversal can
        // load the same option implicitly.
        $showHidden = $this->settings_show_hidden();
        $taxonomyRows = ProviderSdk::checked_get_results(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s ORDER BY term_id ASC LIMIT "
                    . (self::MAX_CATEGORY_COUNT + 1),
                self::TAXONOMY
            ),
            'taxonomy identities',
            $wpdb,
            'duo: The Events Calendar Category Colors taxonomy identities is unreadable'
        );
        if (count($taxonomyRows) > self::MAX_CATEGORY_COUNT) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors taxonomy exceeds the bounded category frontier'
            );
        }
        $termIds = [];
        foreach ($taxonomyRows as $row) {
            if (!is_array($row) || array_keys($row) !== ['term_id']) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors taxonomy identity row is malformed'
                );
            }
            $termId = $this->positive_driver_int($row['term_id'], 'taxonomy identity');
            if (isset($termIds[$termId])) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors taxonomy identity is duplicated'
                );
            }
            $termIds[$termId] = true;
        }
        if ($termIds === []) {
            return ['category_count' => 0, 'css' => [], 'dropdown' => []];
        }

        $idList = array_keys($termIds);
        $idPlaceholders = implode(',', array_fill(0, count($idList), '%d'));
        $termValueLimit = self::MAX_TERM_TEXT_BYTES + 1;
        $termRows = ProviderSdk::checked_get_results(
            $wpdb->prepare(
                "SELECT term_id, LENGTH(slug) AS slug_bytes, "
                    . "LEFT(BINARY slug, $termValueLimit) AS slug_prefix, "
                    . "LENGTH(name) AS name_bytes, LEFT(BINARY name, $termValueLimit) AS name_prefix "
                    . "FROM {$wpdb->terms} WHERE term_id IN ($idPlaceholders) "
                    . 'ORDER BY term_id ASC LIMIT ' . (self::MAX_CATEGORY_COUNT + 1),
                ...$idList
            ),
            'term values',
            $wpdb,
            'duo: The Events Calendar Category Colors term values is unreadable'
        );
        $categories = [];
        foreach ($termRows as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['term_id', 'slug_bytes', 'slug_prefix', 'name_bytes', 'name_prefix']
                || !is_string($row['slug_prefix'])
                || !is_string($row['name_prefix'])) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors term value row is malformed or oversized'
                );
            }
            $termId = $this->positive_driver_int($row['term_id'], 'term value identity');
            $slugBytes = $this->nonnegative_driver_int($row['slug_bytes'], 'term slug byte length');
            $nameBytes = $this->nonnegative_driver_int($row['name_bytes'], 'term name byte length');
            if ($slugBytes < 1
                || $slugBytes > self::MAX_TERM_TEXT_BYTES
                || $nameBytes > self::MAX_TERM_TEXT_BYTES
                || strlen($row['slug_prefix']) !== $slugBytes
                || strlen($row['name_prefix']) !== $nameBytes) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors term value row is malformed or oversized'
                );
            }
            if (!isset($termIds[$termId]) || isset($categories[$termId])) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors term value identity is missing or duplicated'
                );
            }
            $categories[$termId] = [
                'slug' => $row['slug_prefix'],
                'name' => $row['name_prefix'],
                'colors' => ['primary' => '', 'secondary' => '', 'text' => ''],
                'priority' => -1,
                'hidden' => false,
            ];
        }
        if (count($categories) !== count($termIds)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors taxonomy references a missing term value row'
            );
        }

        $metaBounds = ProviderSdk::checked_get_results(
            $wpdb->prepare(
                "SELECT meta_id, term_id, meta_key, LENGTH(meta_value) AS value_bytes FROM {$wpdb->termmeta} "
                    . "WHERE term_id IN ($idPlaceholders) ORDER BY meta_id ASC LIMIT "
                    . (self::MAX_TERM_META_ROWS + 1),
                ...$idList
            ),
            'term metadata bounds',
            $wpdb,
            'duo: The Events Calendar Category Colors term metadata bounds is unreadable'
        );
        if (count($metaBounds) > self::MAX_TERM_META_ROWS) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors term metadata exceeds the bounded row frontier'
            );
        }
        $metaBytes = 0;
        foreach ($metaBounds as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'term_id', 'meta_key', 'value_bytes']
                || !is_string($row['meta_key'])) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors term metadata bound row is malformed'
                );
            }
            $this->positive_driver_int($row['meta_id'], 'term metadata identity');
            $termId = $this->positive_driver_int($row['term_id'], 'term metadata owner');
            if (!isset($termIds[$termId])) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors term metadata owner is outside the taxonomy'
                );
            }
            $valueBytes = $this->nonnegative_driver_int($row['value_bytes'], 'term metadata byte length');
            if ($valueBytes > self::MAX_TERM_META_BYTES - $metaBytes) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors term metadata exceeds the bounded byte frontier'
                );
            }
            $metaBytes += $valueBytes;
        }

        $metaKeys = array_merge(array_values(self::COLOR_META), [self::PRIORITY_META, self::HIDDEN_META]);
        $metaKeyPlaceholders = implode(',', array_fill(0, count($metaKeys), '%s'));
        $metaValueLimit = self::MAX_RELEVANT_META_VALUE_BYTES + 1;
        $uniqueRowFrontier = count($idList) * count($metaKeys) + 1;
        $relevantRowLimit = min($uniqueRowFrontier, self::MAX_NATIVE_GENERATOR_META_ROWS + 1);
        $relevantRows = ProviderSdk::checked_get_results(
            $wpdb->prepare(
                "SELECT meta_id, term_id, meta_key, LENGTH(meta_value) AS value_bytes, "
                    . "LEFT(BINARY meta_value, $metaValueLimit) AS value_prefix FROM {$wpdb->termmeta} "
                    . "WHERE term_id IN ($idPlaceholders) AND meta_key IN ($metaKeyPlaceholders) "
                    . "ORDER BY meta_id ASC LIMIT $relevantRowLimit",
                ...array_merge($idList, $metaKeys)
            ),
            'category color metadata',
            $wpdb,
            'duo: The Events Calendar Category Colors category color metadata is unreadable'
        );
        if (count($relevantRows) > self::MAX_NATIVE_GENERATOR_META_ROWS) {
            throw new \RuntimeException(
                'duo: The Events Calendar native Category Colors Generator exceeds its safe one-page metadata frontier'
            );
        }
        if (count($relevantRows) >= $uniqueRowFrontier) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors native metadata exceeds its unique-row frontier'
            );
        }
        $seenMeta = [];
        $colorNames = array_flip(self::COLOR_META);
        foreach ($relevantRows as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'term_id', 'meta_key', 'value_bytes', 'value_prefix']
                || !is_string($row['meta_key'])
                || !is_string($row['value_prefix'])) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors native metadata row is malformed or oversized'
                );
            }
            $this->positive_driver_int($row['meta_id'], 'category color metadata identity');
            $termId = $this->positive_driver_int($row['term_id'], 'category color metadata owner');
            $valueBytes = $this->nonnegative_driver_int(
                $row['value_bytes'],
                'category color metadata byte length'
            );
            if ($valueBytes > self::MAX_RELEVANT_META_VALUE_BYTES
                || strlen($row['value_prefix']) !== $valueBytes) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors native metadata row is malformed or oversized'
                );
            }
            $metaValue = $row['value_prefix'];
            $pair = $termId . ':' . $row['meta_key'];
            if (!isset($categories[$termId]) || isset($seenMeta[$pair])) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors native metadata row is duplicated or ownerless'
                );
            }
            $seenMeta[$pair] = true;
            if (isset($colorNames[$row['meta_key']])) {
                $categories[$termId]['colors'][$colorNames[$row['meta_key']]] = $metaValue;
            } elseif ($row['meta_key'] === self::PRIORITY_META) {
                $categories[$termId]['priority'] = is_numeric($metaValue)
                    ? (int) $metaValue
                    : -1;
            } elseif ($row['meta_key'] === self::HIDDEN_META) {
                $categories[$termId]['hidden'] = (bool) $metaValue;
            }
        }

        $expectedCss = [];
        $expectedDropdown = [];
        foreach ($categories as $category) {
            $properties = [];
            foreach ($category['colors'] as $name => $rawColor) {
                $hex = $this->native_hex($rawColor);
                if ($hex !== '') {
                    $properties[] = self::COLOR_PROPERTIES[$name] . ':' . $hex;
                }
            }
            if ($properties !== []) {
                $selector = '.tribe_events_cat-'
                    . sanitize_html_class(sanitize_title($category['slug']));
                if ($selector === '.tribe_events_cat-' || isset($expectedCss[$selector])) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar Category Colors selector identity is empty or duplicated'
                    );
                }
                $expectedCss[$selector] = [
                    'block' => $selector . '{' . implode(';', $properties) . '}',
                    'priority' => $category['priority'],
                ];
            }
            if ($category['colors']['primary'] !== '' && ($showHidden || !$category['hidden'])) {
                $slug = $category['slug'];
                if (isset($expectedDropdown[$slug])) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar Category Colors dropdown slug identity is duplicated'
                    );
                }
                $expectedDropdown[$slug] = [
                    'slug' => $slug,
                    'name' => $category['name'],
                    'primary' => $category['colors']['primary'],
                    'priority' => $category['priority'],
                    'hidden' => $category['hidden'],
                ];
            }
        }
        ksort($expectedCss, SORT_STRING);
        ksort($expectedDropdown, SORT_STRING);
        return [
            'category_count' => count($categories),
            'css' => $expectedCss,
            'dropdown' => $expectedDropdown,
        ];
    }

    private function raw_css_option(): ?string {
        return $this->bounded_raw_option(
            self::CSS_OPTION,
            self::MAX_CSS_BYTES,
            'CSS option'
        );
    }

    private function settings_show_hidden(): bool {
        $raw = $this->bounded_raw_option(
            self::SETTINGS_OPTION,
            self::MAX_SETTINGS_BYTES,
            'main settings option'
        );
        $expected = false;
        if ($raw !== null) {
            $settings = \Duo\PlainData::decode_serialized(
                $raw,
                'The Events Calendar main settings option'
            );
            if (!is_array($settings)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors main settings option is not a native array'
                );
            }
            if (array_key_exists('category-color-show-hidden-categories', $settings)) {
                if (!is_bool($settings['category-color-show-hidden-categories'])) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar Category Colors show-hidden setting is not a native boolean'
                    );
                }
                $expected = $settings['category-color-show-hidden-categories'];
            }
        }
        $effective = tribe_get_option('category-color-show-hidden-categories', false);
        if (!is_bool($effective) || $effective !== $expected) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors show-hidden setting disagrees with bounded raw storage'
            );
        }
        return $expected;
    }

    private function bounded_raw_option(string $name, int $maxBytes, string $context): ?string {
        global $wpdb;
        $valueLimit = $maxBytes + 1;
        $rows = ProviderSdk::checked_get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, LENGTH(option_value) AS value_bytes, "
                    . "LEFT(BINARY option_value, $valueLimit) AS value_prefix FROM {$wpdb->options} "
                    . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
                $name
            ),
            "$context value",
            $wpdb,
            "duo: The Events Calendar Category Colors $context value is unreadable"
        );
        if (count($rows) > 1) {
            throw new \RuntimeException(
                "duo: The Events Calendar Category Colors $context identity is duplicated"
            );
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_id', 'option_name', 'value_bytes', 'value_prefix']
            || !is_string($row['option_name'])
            || !is_string($row['value_prefix'])
            || !hash_equals($name, $row['option_name'])) {
            throw new \RuntimeException(
                "duo: The Events Calendar Category Colors $context value row is malformed or aliased"
            );
        }
        $this->positive_driver_int($row['option_id'], "$context identity");
        $valueBytes = $this->nonnegative_driver_int($row['value_bytes'], "$context byte length");
        if ($valueBytes > $maxBytes) {
            throw new \RuntimeException(
                "duo: The Events Calendar Category Colors $context exceeds the bounded byte frontier"
            );
        }
        if (strlen($row['value_prefix']) !== $valueBytes) {
            throw new \RuntimeException(
                "duo: The Events Calendar Category Colors $context byte witness is inconsistent"
            );
        }
        return $row['value_prefix'];
    }

    private function positive_driver_int(mixed $value, string $context): int {
        $parsed = $this->nonnegative_driver_int($value, $context);
        if ($parsed < 1) {
            throw new \RuntimeException(
                "duo: The Events Calendar Category Colors $context is not a positive driver integer"
            );
        }
        return $parsed;
    }

    private function nonnegative_driver_int(mixed $value, string $context): int {
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0) {
            throw new \RuntimeException(
                "duo: The Events Calendar Category Colors $context is not a bounded driver integer"
            );
        }
        return (int) $value;
    }

    /** @param array<string,array<string,mixed>> $rows */
    private function rows_digest(array $rows): string {
        return hash('sha256', (string) json_encode(array_values($rows), JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $projection @return array<string,mixed> */
    protected function project_regenerate_css(array $projection): array {
        // Generator::fetch_category_meta() has no SQL ORDER BY and its stable
        // priority-only usort therefore permits byte permutations inside an
        // equal-priority bucket. Selectors are disjoint, so the canonical
        // projection hashes above are the exact semantic postcondition; a raw
        // storage hash would turn an equivalent native reorder into permanent
        // scoped recovery_required debt.
        unset(
            $projection['css_sha256'],
            $projection['dropdown_cache_present'],
            $projection['dropdown_cache_actual_count'],
            $projection['dropdown_cache_actual_sha256'],
            $projection['dropdown_cache_mismatch_count'],
            $projection['dropdown_cache_malformed_count']
        );
        return $projection;
    }

    /** @param array<string,mixed> $properties */
    private function native_service(
        string $class,
        string $method,
        array $properties,
        string $label
    ): object {
        $service = tribe($class);
        $this->assert_native_service_state($service, $class, $method, $properties, $label);
        return $service;
    }

    /** @param array<string,mixed> $properties */
    private function assert_native_service_state(
        mixed $service,
        string $class,
        string $method,
        array $properties,
        string $label
    ): void {
        if (!is_object($service) || get_class($service) !== $class || !is_callable([$service, $method])) {
            throw new \RuntimeException(
                "duo: The Events Calendar 6.17.x Category Colors $label identity is unavailable or overridden"
            );
        }
        try {
            $reflection = new \ReflectionClass($service);
            $actual = [];
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic()) {
                    throw new \RuntimeException('static property');
                }
                $actual[$property->getName()] = $property->getValue($service);
            }
            ksort($actual, SORT_STRING);
            ksort($properties, SORT_STRING);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                "duo: The Events Calendar 6.17.x Category Colors $label state is unreadable or overridden",
                0,
                $failure
            );
        }
        if ($actual !== $properties) {
            throw new \RuntimeException(
                "duo: The Events Calendar 6.17.x Category Colors $label state is unavailable or overridden"
            );
        }
    }

    private function assert_hook_contract(): void {
        $records = [];
        foreach (self::OUTPUT_FILTER_HOOKS as $hook) {
            // Preserve the source-audited has_filter() reachability assertion
            // as well as inspecting WP_Hook records: a partial test/runtime
            // shim that reports a callback but withholds its record is not an
            // empty WordPress topology.
            $signal = has_filter($hook);
            if (!is_bool($signal) && !is_int($signal)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar free Category Colors hook signal is malformed'
                );
            }
            $records[$hook] = $this->hook_records($hook);
            if (($signal === false) !== ($records[$hook] === [])) {
                throw new \RuntimeException(
                    "duo: The Events Calendar free Category Colors hook topology is malformed for '$hook'"
                );
            }
        }
        foreach ($records as $hook => $hookRecords) {
            if ($hook === 'tribe_get_option' || $hook === 'updated_option') {
                continue;
            }
            if ($hookRecords !== []) {
                throw new \RuntimeException(
                    "duo: The Events Calendar free Category Colors output contract does not admit filter '$hook'"
                );
            }
        }
        $this->assert_tribe_get_option_callbacks($records['tribe_get_option']);
        $this->assert_updated_option_callbacks($records['updated_option']);
        foreach ([
            'tribe_cache_last_occurrence_option_triggers',
            'tribe_cache_last_occurrence_option_triggers:updated_option',
            'tribe_cache_last_occurrence_option_triggers:save_post',
        ] as $hook) {
            if ($this->hook_records($hook) !== []) {
                throw new \RuntimeException(
                    'duo: The Events Calendar free Category Colors cache-listener trigger topology is extended'
                );
            }
        }
    }

    /** @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records */
    private function assert_tribe_get_option_callbacks(array $records): void {
        if (count($records) !== 3
            || !class_exists('Tribe\\Events\\Views\\V2\\Hooks', false)) {
            throw new \RuntimeException(
                'duo: The Events Calendar free Category Colors tribe_get_option topology is incomplete'
            );
        }
        try {
            $views = tribe('Tribe\\Events\\Views\\V2\\Hooks');
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: The Events Calendar free Category Colors option service is unavailable',
                0,
                $failure
            );
        }
        if (!is_object($views) || get_class($views) !== 'Tribe\\Events\\Views\\V2\\Hooks') {
            throw new \RuntimeException(
                'duo: The Events Calendar free Category Colors option service is substituted'
            );
        }
        $this->assert_exact_object_callbacks($records, [
            [$views, 'filter_get_stylesheet_option', 10, 2],
            [$views, 'filter_live_filters_option_value', 10, 2],
            [$views, 'filter_date_escaping', 10, 2],
        ], 'tribe_get_option');
        $sentinel = ['duo_category_colors' => true];
        if (apply_filters('tribe_get_option', $sentinel, self::CATEGORY_OPTION) !== $sentinel) {
            throw new \RuntimeException(
                'duo: The Events Calendar free Category Colors option callbacks changed the reviewed category setting'
            );
        }
    }

    /** @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records */
    private function assert_updated_option_callbacks(array $records): void {
        if (count($records) !== 5
            || !class_exists('Tribe__Settings_Manager', false)
            || !class_exists('Tribe__Events__Aggregator', false)
            || !class_exists('Tribe__Cache_Listener', false)
            || !is_callable(['Tribe__Settings_Manager', 'instance'])
            || !is_callable(['Tribe__Events__Aggregator', 'instance'])
            || !is_callable(['Tribe__Cache_Listener', 'instance'])) {
            throw new \RuntimeException(
                'duo: The Events Calendar free Category Colors updated_option topology is incomplete'
            );
        }
        try {
            $manager = \Tribe__Settings_Manager::instance();
            $aggregator = \Tribe__Events__Aggregator::instance();
            $listener = \Tribe__Cache_Listener::instance();
            $views = tribe('Tribe\\Events\\Views\\V2\\Hooks');
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: The Events Calendar free Category Colors updated_option services are unavailable',
                0,
                $failure
            );
        }
        foreach ([
            [$manager, 'Tribe__Settings_Manager'],
            [$aggregator, 'Tribe__Events__Aggregator'],
            [$listener, 'Tribe__Cache_Listener'],
            [$views, 'Tribe\\Events\\Views\\V2\\Hooks'],
        ] as [$service, $class]) {
            if (!is_object($service) || get_class($service) !== $class) {
                throw new \RuntimeException(
                    'duo: The Events Calendar free Category Colors updated_option service is substituted'
                );
            }
        }
        $this->assert_exact_object_callbacks($records, [
            [$manager, 'update_options_cache', 10, 3],
            [$views, 'action_save_wplang', 10, 3],
            [$aggregator, 'action_purge_transients', 10, 1],
            [$listener, 'update_last_updated_option', 10, 3],
            [$listener, 'update_last_save_post', 10, 3],
        ], 'updated_option');
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     * @param list<array{0:object,1:string,2:int,3:int}> $expected
     */
    private function assert_exact_object_callbacks(array $records, array $expected, string $hook): void {
        foreach ($records as [$priority, $record]) {
            $match = null;
            foreach ($expected as $index => [$object, $method, $expectedPriority, $acceptedArgs]) {
                if ($priority === $expectedPriority
                    && $record['accepted_args'] === $acceptedArgs
                    && $record['function'] === [$object, $method]) {
                    $match = $index;
                    break;
                }
            }
            if ($match === null) {
                throw new \RuntimeException(
                    "duo: The Events Calendar free Category Colors $hook topology is extended or substituted"
                );
            }
            unset($expected[$match]);
        }
        if ($expected !== []) {
            throw new \RuntimeException(
                "duo: The Events Calendar free Category Colors $hook topology is incomplete"
            );
        }
    }

    /** @return list<array{0:int,1:array{function:mixed,accepted_args:int}}> */
    private function hook_records(string $name): array {
        global $wp_filter;
        $hook = is_array($wp_filter ?? null) ? ($wp_filter[$name] ?? null) : null;
        if ($hook === null) {
            return [];
        }
        if (!is_object($hook)
            || get_class($hook) !== 'WP_Hook'
            || !is_array($hook->callbacks ?? null)) {
            throw new \RuntimeException(
                'duo: The Events Calendar free Category Colors found malformed WordPress hook topology'
            );
        }
        $records = [];
        foreach ($hook->callbacks as $priority => $atPriority) {
            if (!is_int($priority) || !is_array($atPriority)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar free Category Colors found malformed WordPress hook topology'
                );
            }
            foreach ($atPriority as $record) {
                if (count($records) >= self::MAX_HOOK_RECORDS
                    || !is_array($record)
                    || array_keys($record) !== ['function', 'accepted_args']
                    || !is_int($record['accepted_args'] ?? null)) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar free Category Colors found malformed or oversized hook topology'
                    );
                }
                $records[] = [$priority, $record];
            }
        }
        return $records;
    }

    private function native_cache(): \Tribe__Cache {
        $cache = tribe_cache();
        if (!is_object($cache)
            || get_class($cache) !== \Tribe__Cache::class
            || !is_callable([$cache, 'get'])) {
            throw new \RuntimeException(
                'duo: The Events Calendar 6.17.x Category Colors cache identity is unavailable or overridden'
            );
        }
        return $cache;
    }

    private function native_hex(mixed $value): string {
        if (!is_string($value) || $value === '') {
            return '';
        }
        try {
            $hex = (new \Tribe__Utils__Color($value))->get_hex_with_hash();
        } catch (\Throwable) {
            return '';
        }
        return is_string($hex) ? strtolower($hex) : '';
    }
}
