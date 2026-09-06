<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\Canon;
use WPrism\CacheInvalidationTransaction;
use WPrism\NativeRewriteEffects;
use WPrism\PlainData;
use WPrism\Policy;

/**
 * Validate Polylang's native mixed-option and metadata frontiers that a
 * static rule cannot safely describe. Menu switchers use one exact native
 * schema, language-term string catalogs are authored plain data, and
 * multilingual biographies retain the core KSES/user-identity boundaries.
 */
final class Polylang {
    private const CATALOG_MAX_ROWS = 10000;
    private const CATALOG_MAX_STRING_BYTES = 1048576;
    private const CATALOG_MAX_TOTAL_BYTES = 16777216;
    private const BIOGRAPHY_MAX_BYTES = 1048576;
    private const LANGUAGE_SLUG_MAX_BYTES = 200;
    private const OBJECT_TYPE_MAX_ITEMS = 2048;
    private const OBJECT_TYPE_MAX_TOTAL_BYTES = 1048576;
    private const NAV_MAX_THEMES = 256;
    private const NAV_MAX_LOCATIONS_PER_THEME = 1024;
    private const NAV_MAX_LANGUAGES_PER_LOCATION = 512;
    private const NAV_MAX_ASSIGNMENTS = 32768;
    private const NAV_MAX_TOTAL_BYTES = 4194304;
    private const THEME_COMPONENT_MAX_BYTES = 764;
    private const NAV_LOCATION_MAX_BYTES = 764;
    private const WIDGET_TITLE_MAX_BYTES = 1048576;
    private const MENU_KEYS = [
        'dropdown',
        'force_home',
        'hide_current',
        'hide_if_no_translation',
        'show_flags',
        'show_names',
    ];
    /** Registry.php order is a native dependency contract, not presentation. */
    private const NATIVE_OPTION_KEYS = [
        'force_lang',
        'domains',
        'hide_default',
        'rewrite',
        'redirect_lang',
        'browser',
        'media_support',
        'post_types',
        'taxonomies',
        'sync',
        'default_lang',
        'nav_menus',
        'first_activation',
        'previous_version',
        'version',
    ];
    private const OPTION_KEYS = [
        'browser',
        'default_lang',
        'force_lang',
        'hide_default',
        'media_support',
        'nav_menus',
        'post_types',
        'redirect_lang',
        'rewrite',
        'sync',
        'taxonomies',
    ];
    private const BOOLEAN_OPTION_KEYS = [
        'browser',
        'hide_default',
        'media_support',
        'redirect_lang',
        'rewrite',
    ];
    private const NATIVE_BOOLEAN_OPTION_KEYS = [
        'browser',
        'first_activation',
        'hide_default',
        'media_support',
        'redirect_lang',
        'rewrite',
    ];
    private const SYNC_VALUES = [
        '_thumbnail_id',
        '_wp_page_template',
        'comment_status',
        'menu_order',
        'ping_status',
        'post_date',
        'post_format',
        'post_meta',
        'post_parent',
        'sticky_posts',
        'taxonomies',
    ];
    public function __construct(Policy $policy) {
        // The reviewed official patch line is bounded by >=3.8 <3.8.8;
        // adjacent 3.7 and synthetic 3.8.8 controls refuse.
    }

    public function post_meta_rule(string $key, array $allMeta): ?array {
        if ($key !== '_pll_menu_item') {
            return null;
        }
        if (array_key_exists($key, $allMeta)) {
            $this->assert_menu_switcher($allMeta[$key], 'live _pll_menu_item');
        }
        return ['class' => 'authored', 'plain_data' => true, 'lint_ok' => true];
    }

    public function term_meta_rule(string $key, array $allMeta): ?array {
        if ($key !== '_pll_strings_translations') {
            return null;
        }
        if (array_key_exists($key, $allMeta)) {
            if ($allMeta[$key] === '') {
                return ['class' => 'runtime'];
            }
            $this->assert_string_catalog(
                $allMeta[$key],
                'live _pll_strings_translations',
                is_string($allMeta[$key])
            );
        }
        return ['class' => 'authored', 'plain_data' => true];
    }

    public function user_meta_rule(string $key, array $allMeta): ?array {
        if (in_array($key, ['pll_filter_content', 'pll_dismissed_notices'], true)) {
            return ['class' => 'runtime'];
        }
        if ($key !== 'description'
            && preg_match('/^description_([a-z][a-z0-9_-]{0,199})$/D', $key) !== 1) {
            return null;
        }
        $value = PlainData::decode($allMeta[$key] ?? '', "Polylang user meta $key");
        $this->assert_biography($value, "live user meta $key");
        return ['class' => 'authored', 'allow_pii' => true, 'missing_user' => 'block',
            'native_value_validation' => ['profile' => 'wordpress-kses/v1', 'context' => 'pre_user_description']];
    }

    public function option_rule(string $name, array $allOptions): ?array {
        if ($name === 'polylang') {
            $rawValue = $allOptions[$name] ?? '';
            $value = PlainData::decode($rawValue, 'Polylang option namespace');
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new \RuntimeException('wprism: Polylang option namespace row must be an object');
            }
            if (!is_string($rawValue)) {
                // Immutable repository values contain no target-local marker.
                // Mode 0 remains portable but is re-authorized from the exact
                // locked marker on every target apply.
                $this->assert_supported_topology(
                    $value['force_lang'] ?? null,
                    ($value['force_lang'] ?? null) === 0 ? 'yes' : null,
                    'repository'
                );
            }
            return null; // The exact static mixed-option rule owns the row.
        }
        if ($name === 'polylang_licenses') {
            return ['class' => 'env'];
        }
        if (in_array($name, [
            'pll_dismissed_notices',
            'pll_language_from_content_available',
            'pll_language_taxonomies',
        ], true)) {
            return ['class' => 'runtime'];
        }
        if ($name === 'polylang_wpml_strings') {
            $value = PlainData::decode($allOptions[$name] ?? '', 'Polylang WPML string registry');
            if ($value === []) {
                return ['class' => 'runtime'];
            }
        }
        if (preg_match('/^(?:polylang(?:_|$)|pll_)/D', $name) === 1) {
            throw new \RuntimeException(
                'wprism: Polylang option namespace contains an unreviewed row '
                . self::fingerprint($name)
                . '; refusing silent target ownership'
            );
        }
        return null;
    }

    /**
     * Fill missing raw authored siblings from the registered native defaults.
     * This hook receives raw values before the engine's ordinary secret/ref/
     * text codec, so native equality is proved before nav-menu ids are turned
     * into repository tokens.
     *
     * @return array<string,mixed>
     */
    public function normalize_captured_option_sub_keys(
        string $name,
        array $captured,
        array $subKeys,
        array $rawOptionSnapshot,
        bool $strictReadOnly
    ): array {
        if ($name !== 'polylang') {
            return $captured;
        }
        if (!function_exists('PLL')) {
            if ($strictReadOnly) {
                // Lifecycle snapshots deliberately run before activation. The
                // native singleton is unavailable, but the authored frontier
                // is still exact: normalize the historical 0/1 boolean bytes
                // and run the same closed portable/topology validators before
                // allowing the ordinary capture codec to proceed.
                if ($captured === []) {
                    // A clean target has no primary mixed-option row yet;
                    // native activation will materialize its reviewed defaults
                    // later in the lifecycle, so absence is the exact state.
                    return [];
                }
                foreach (self::BOOLEAN_OPTION_KEYS as $key) {
                    if (array_key_exists($key, $captured)
                        && is_int($captured[$key])
                        && in_array($captured[$key], [0, 1], true)) {
                        $captured[$key] = (bool) $captured[$key];
                    }
                }
                $this->assert_portable_options($captured, false);
                $this->assert_supported_topology(
                    $captured['force_lang'],
                    $rawOptionSnapshot['pll_language_from_content_available'] ?? null,
                    'source'
                );
                ksort($captured, SORT_STRING);
                return $captured;
            }
            throw new \RuntimeException('wprism: Polylang native option capture normalization requires PLL()');
        }
        $rawPrimary = $rawOptionSnapshot['polylang'] ?? null;
        if ($rawPrimary !== null && !is_string($rawPrimary)) {
            throw new \RuntimeException('wprism: Polylang native option capture snapshot has malformed primary bytes');
        }
        $topologyMarker = $rawOptionSnapshot['pll_language_from_content_available'] ?? null;
        $runtime = PLL();
        $options = is_object($runtime) ? ($runtime->options ?? null) : null;
        if (!is_object($options) || !is_callable([$options, 'get']) || !is_callable([$options, 'get_schema'])) {
            throw new \RuntimeException(
                'wprism: Polylang native option capture normalization requires Options::get()/get_schema()'
            );
        }
        $defaults = $this->native_schema_defaults($options);
        foreach (self::OPTION_KEYS as $key) {
            if (($subKeys[$key]['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "wprism: Polylang native option capture normalization has no authored declaration for '$key'"
                );
            }
            $native = $options->get($key);
            if ($native === null) {
                throw new \RuntimeException(
                    "wprism: Polylang native option capture normalization is missing registered option '$key'"
                );
            }
            if (!array_key_exists($key, $captured)) {
                $default = $defaults[$key];
                if ($native !== $default) {
                    throw new \RuntimeException(
                        "wprism: Polylang raw option '$key' is missing while its native singleton disagrees "
                        . 'with the registered schema default; refusing stale process-local source state'
                    );
                }
                $captured[$key] = $default;
                continue;
            }
            $raw = $captured[$key];
            $equivalent = $raw === $native;
            if (in_array($key, self::BOOLEAN_OPTION_KEYS, true)
                && is_bool($native)
                && is_int($raw)
                && in_array($raw, [0, 1], true)) {
                $equivalent = (bool) $raw === $native;
            }
            if (!$equivalent) {
                throw new \RuntimeException(
                    "wprism: Polylang raw option '$key' disagrees with its registered native normalization; "
                    . 'refusing to publish silently coerced source state'
                );
            }
            // Polylang stores historical boolean bytes as 0/1 but its 3.8
            // registry exposes real booleans. Canonical state represents the
            // runtime value that the native apply path verifies and saves.
            $captured[$key] = $native;
        }
        ksort($captured, SORT_STRING);
        // Capture normalization still observes native option bytes. The
        // ordinary capture codec runs immediately afterward and rewrites the
        // nav_menus ids through the declared json_refs path; validating those
        // raw ids as repository tokens here rejects every real source menu.
        $this->assert_portable_options($captured, false);
        $this->assert_supported_topology($captured['force_lang'], $topologyMarker, 'source');
        $this->assert_source_language_flags($runtime);
        return $captured;
    }

    /** @return array<string,mixed> */
    private function native_schema_defaults(object $options): array {
        $schema = $options->get_schema();
        if (!is_array($schema) || ($schema !== [] && array_is_list($schema))) {
            throw new \RuntimeException('wprism: Polylang native option schema must be an object');
        }
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            throw new \RuntimeException('wprism: Polylang native option schema properties must be an object');
        }
        if (array_keys($properties) !== self::NATIVE_OPTION_KEYS) {
            throw new \RuntimeException(
                'wprism: Polylang native option schema does not match the reviewed 15-key registry order'
            );
        }
        $defaults = [];
        foreach ($properties as $key => $property) {
            if (!is_array($property)
                || ($property !== [] && array_is_list($property))
                || !array_key_exists('default', $property)) {
                throw new \RuntimeException(
                    "wprism: Polylang native option schema has no exact default for registered option '$key'"
                );
            }
            PlainData::assert($property['default'], "Polylang native option schema default $key");
            $defaults[(string) $key] = $property['default'];
        }
        return $defaults;
    }

    /** @return list<string> */
    public function option_sub_key_materialization_companions(string $name): array {
        return $name === 'polylang' ? ['pll_language_from_content_available'] : [];
    }

    /**
     * Apply the reviewed mixed option through Polylang's own registry. This
     * hook is called only from OptionsMaterializer's authored transaction.
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
        if ($name !== 'polylang') {
            return false;
        }
        if ($writeStorage === null || $registerRuntimeRestore === null) {
            throw new \RuntimeException(
                'wprism: Polylang native option materialization requires engine-owned storage and rollback callbacks'
            );
        }
        $this->assert_portable_options($captured, false);
        ksort($captured, SORT_STRING);
        if (!function_exists('PLL')) {
            throw new \RuntimeException('wprism: Polylang native option materialization requires PLL()');
        }
        $runtime = PLL();
        $options = is_object($runtime) ? ($runtime->options ?? null) : null;
        foreach (['get', 'get_all', 'merge', 'protect_wp_option_storage', 'reset', 'save', 'save_all', 'set'] as $method) {
            if (!is_object($options) || !is_callable([$options, $method])) {
                throw new \RuntimeException(
                    "wprism: Polylang native option materialization requires Options::$method()"
                );
            }
        }
        $this->assert_native_option_hook_topology($options);

        $beforeRaw = $targetValue ?? [];
        $targetOwned = [];
        foreach ($beforeRaw as $key => $value) {
            if (($subKeys[(string) $key]['class'] ?? null) !== 'authored') {
                $targetOwned[(string) $key] = $value;
            }
        }
        $originalNative = $this->native_option_values($options, 'before native materialization');
        $runtimeRestored = false;
        $nativeMutationStarted = false;
        $shutdownDisarmed = false;
        $disarmShutdown = function () use ($options, &$shutdownDisarmed): void {
            if ($shutdownDisarmed) {
                return;
            }
            $removed = remove_action('shutdown', [$options, 'save_all'], 1000);
            $stillRegistered = has_action('shutdown', [$options, 'save_all']);
            if ($stillRegistered === false) {
                $shutdownDisarmed = true;
            }
            if (!$removed || $stillRegistered !== false) {
                throw new \RuntimeException(
                    'wprism: Polylang could not disarm the reviewed native shutdown writer before mutation'
                );
            }
        };
        $restoreShutdown = function () use ($options, &$shutdownDisarmed): void {
            if (!$shutdownDisarmed) {
                return;
            }
            if (!add_action('shutdown', [$options, 'save_all'], 1000, 0)
                || has_action('shutdown', [$options, 'save_all']) !== 1000) {
                throw new \RuntimeException(
                    'wprism: Polylang could not restore the reviewed native shutdown writer'
                );
            }
            $shutdownDisarmed = false;
        };
        $beginNativeMutation = function () use (
            $disarmShutdown,
            &$nativeMutationStarted
        ): void {
            $disarmShutdown();
            $nativeMutationStarted = true;
        };
        $runtimeRestore = function () use (
            $options,
            $originalNative,
            $targetValue,
            $disarmShutdown,
            $restoreShutdown,
            &$nativeMutationStarted,
            &$shutdownDisarmed,
            &$runtimeRestored
        ): void {
            if ($runtimeRestored) {
                return;
            }
            if (!$nativeMutationStarted && !$shutdownDisarmed) {
                // A refusal before the first native setter/reset must leave an
                // already-pending plugin save and its shutdown hook untouched.
                $runtimeRestored = true;
                return;
            }
            $failures = [];
            try {
                $disarmShutdown();
            } catch (\Throwable $failure) {
                $failures[] = ['disarm', $failure];
            }
            try {
                $this->restore_native_option_values($options, $originalNative);
            } catch (\Throwable $failure) {
                $failures[] = ['restore-values', $failure];
            }
            $modifiedConsumed = false;
            try {
                $this->consume_native_modified_state(
                    $options,
                    $targetValue ?? [],
                    'after transaction rollback'
                );
                $modifiedConsumed = true;
            } catch (\Throwable $failure) {
                $failures[] = ['consume-modified', $failure];
            }
            if ($modifiedConsumed) {
                try {
                    $restoreShutdown();
                } catch (\Throwable $failure) {
                    $failures[] = ['restore-shutdown', $failure];
                }
            }
            try {
                $this->purge_option_cache();
            } catch (\Throwable $failure) {
                $failures[] = ['purge-cache', $failure];
            }
            try {
                if ($this->native_option_values($options, 'after transaction rollback') !== $originalNative) {
                    throw new \RuntimeException(
                        'wprism: Polylang native option runtime state did not restore after transaction rollback'
                    );
                }
            } catch (\Throwable $failure) {
                $failures[] = ['verify-values', $failure];
            }
            if ($failures !== []) {
                $parts = [];
                foreach ($failures as [$stage, $failure]) {
                    $parts[] = $stage . '=' . self::failure_fingerprint($failure);
                }
                throw new \RuntimeException(
                    'wprism: Polylang native rollback cleanup was incomplete; ' . implode('; ', $parts),
                    0,
                    $failures[0][1]
                );
            }
            $runtimeRestored = true;
        };
        $registerRuntimeRestore($runtimeRestore);
        $beforeNative = $originalNative;
        $storageMayHaveChanged = false;
        try {
            $beforeNative = $this->prepare_fresh_locked_primary_option(
                $options,
                $targetValue,
                $originalNative,
                $beginNativeMutation
            );
            $topologyMarker = null;
            if ($captured['force_lang'] === 0 || $beforeNative['force_lang'] === 0) {
                $markerRow = $lockTargetOption('pll_language_from_content_available');
                $topologyMarker = $this->fresh_locked_topology_marker($markerRow);
            }
            $this->assert_supported_topology($captured['force_lang'], $topologyMarker, 'target');
            $this->assert_supported_topology(
                $beforeNative['force_lang'],
                $topologyMarker,
                'target current'
            );
            if ($captured['default_lang'] === '') {
                $beginNativeMutation();
                $beforeDefault = array_diff_key($captured, ['default_lang' => true, 'nav_menus' => true]);
                $this->assert_native_result($options->merge($beforeDefault), 'Options::merge() before default_lang');
                if ($options->reset('default_lang') !== '') {
                    throw new \RuntimeException(
                        'wprism: Polylang Options::reset(default_lang) did not produce the native empty default'
                    );
                }
                $this->assert_native_result(
                    $options->merge(['nav_menus' => $captured['nav_menus']]),
                    'Options::merge() after default_lang'
                );
            } else {
                $beginNativeMutation();
                $errors = $options->merge($captured);
                $this->assert_native_result($errors, 'Options::merge()');
            }
            $native = [];
            foreach (self::OPTION_KEYS as $key) {
                $native[$key] = $options->get($key);
            }
            // Polylang's Nav_Menus sanitizer rebuilds each language map in
            // the native language-term order. Canonical repository JSON
            // sorts object keys, so the same menu IDs can arrive here with a
            // different associative-key order. Canon preserves list order
            // and scalar types while ignoring only object-key order; a raw
            // PHP `!==` would falsely reject that byte-equivalent object.
            if (Canon::encode($native) !== Canon::encode($captured)) {
                throw new \RuntimeException(
                    'wprism: Polylang native option normalization changed portable values; refusing non-convergent apply'
                );
            }
            // Options::save()/update_option() publish uncommitted bytes into
            // persistent object caches before the surrounding InnoDB COMMIT.
            // A fatal in that window rolls storage back while leaving those
            // desired cache bytes fleet-visible. Native merge/set remains the
            // validation authority; the engine performs the one locked raw
            // write and refuses persistent option-cache topologies.
            $storageMayHaveChanged = true;
            $writeStorage(array_merge($beforeRaw, $options->get_all()));
            $this->consume_native_modified_state(
                $options,
                array_merge($beforeRaw, $options->get_all()),
                'after native materialization'
            );
            $restoreShutdown();
            $this->assert_native_option_hook_topology($options);
            $storageRow = $finalizeStorage();
            $afterRaw = $this->decoded_locked_primary_storage(
                $storageRow,
                'after native materialization'
            );
            $this->purge_option_cache();
            $missing = new \stdClass();
            $afterEffective = get_option('polylang', $missing);
            if ($afterEffective === $missing || $afterEffective !== $afterRaw) {
                throw new \RuntimeException(
                    'wprism: Polylang native option effective postcondition disagrees with exact raw storage; recovery_required'
                );
            }
            foreach ($captured as $key => $value) {
                // The native sanitizer may reorder associative objects such
                // as nav_menus by its language registry order. Compare the
                // portable group by canonical value (lists and scalar types
                // remain strict), while target-owned siblings below retain
                // their exact raw PHP-array order.
                if (!array_key_exists($key, $afterRaw)
                    || Canon::encode($afterRaw[$key]) !== Canon::encode($value)) {
                    throw new \RuntimeException(
                        'wprism: Polylang native option raw postcondition does not match the portable group; recovery_required'
                    );
                }
            }
            foreach ($targetOwned as $key => $value) {
                if (!array_key_exists($key, $afterRaw) || $afterRaw[$key] !== $value) {
                    throw new \RuntimeException(
                        'wprism: Polylang native option save changed a target-owned sibling; recovery_required'
                    );
                }
            }
            $afterNative = $this->native_option_values($options, 'after native materialization');
            foreach (self::NATIVE_OPTION_KEYS as $key) {
                if (!array_key_exists($key, $afterRaw) || $afterRaw[$key] !== $afterNative[$key]) {
                    throw new \RuntimeException(
                        'wprism: Polylang native option save did not persist the complete registry; recovery_required'
                    );
                }
            }
            $afterPortable = array_intersect_key($afterNative, array_flip(self::OPTION_KEYS));
            ksort($afterPortable, SORT_STRING);
            if (Canon::encode($afterPortable) !== Canon::encode($captured)) {
                throw new \RuntimeException(
                    'wprism: Polylang native in-memory postcondition drifted from the portable group; recovery_required'
                );
            }
        } catch (\Throwable $failure) {
            $restored = true;
            $restoredStorage = null;
            if ($storageMayHaveChanged) {
                try {
                    $restoredStorage = $restoreStorage();
                } catch (\Throwable) {
                    $restored = false;
                }
            }
            try {
                $runtimeRestore();
            } catch (\Throwable) {
                $restored = false;
            }
            $this->purge_option_cache();
            try {
                $nativeRestored = $this->native_option_values($options, 'after failed native materialization')
                    === $originalNative;
                $rawRestored = true;
                if ($storageMayHaveChanged) {
                    $rawRestored = $targetValue === null
                        ? $restoredStorage === null
                        : $this->decoded_locked_primary_storage(
                            $restoredStorage,
                            'after failed native materialization restoration'
                        ) === $targetValue;
                    $missing = new \stdClass();
                    $effectiveRestored = get_option('polylang', $missing);
                    $rawRestored = $rawRestored && ($targetValue === null
                        ? $effectiveRestored === $missing
                        : $effectiveRestored === $targetValue);
                }
                $restored = $restored && $nativeRestored && $rawRestored;
            } catch (\Throwable) {
                $restored = false;
            }
            if (!$restored) {
                throw new \RuntimeException(
                    'wprism: Polylang native option failure could not restore exact raw and in-memory state; recovery_required',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function decoded_locked_primary_storage(mixed $row, string $where): array {
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value', 'autoload']
            || ($row['option_name'] ?? null) !== 'polylang'
            || !is_string($row['option_value'] ?? null)
            || !is_string($row['autoload'] ?? null)) {
            throw new \RuntimeException("wprism: Polylang $where returned malformed raw option storage");
        }
        $value = PlainData::decode($row['option_value'], "Polylang $where raw option storage");
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException("wprism: Polylang $where raw option storage must be an object");
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function native_option_values(object $options, string $where): array {
        $values = [];
        foreach (self::NATIVE_OPTION_KEYS as $key) {
            $value = $options->get($key);
            if ($value === null) {
                throw new \RuntimeException(
                    "wprism: Polylang $where is missing registered option '$key'"
                );
            }
            $values[$key] = $value;
        }
        return $values;
    }

    private function restore_native_option_values(object $options, array $before): void {
        foreach (self::NATIVE_OPTION_KEYS as $key) {
            if ($options->get($key) === $before[$key]) {
                continue;
            }
            $reset = $options->reset($key);
            if ($reset !== $before[$key]) {
                $this->assert_native_result($options->set($key, $before[$key]), "Options::set('$key') restore");
            }
            if ($options->get($key) !== $before[$key]) {
                throw new \RuntimeException(
                    "wprism: Polylang native option '$key' did not restore in memory"
                );
            }
        }
    }

    /**
     * The 3.8.0--3.8.7 constructor owns exactly one storage filter and one
     * shutdown writer. Native validation is unsafe if another callback can
     * alter get_option()/update_option() bytes or if the official callback was
     * displaced. The exact optional co-install union is bounded separately:
     * TEC Harbor pre_option (PUE.php SHA-256
     * abe0ef81332c52aff2983b8f78700169dbcfbeb663497af84e681be245629988),
     * Woo pre-update (CustomOrdersTableController.php SHA-256
     * b4d1a6772b064de9be6a80750074b0a9e371514f58131a1701cad6cd52ccb8bf),
     * and Yoast's six option services (class-wpseo-option.php SHA-256
     * 9be7b8c73ec223dc2349b5976a51c3fcf66d21d12ddd8985742c4ddaaf4057e9)
     * all return their input unchanged for option `polylang`. Refuse before
     * the first setter so an existing pending native update remains untouched.
     */
    private function assert_native_option_hook_topology(object $options, bool $shutdownExpected = true): void {
        foreach (['add_filter', 'remove_filter', 'add_action', 'remove_action', 'has_filter', 'has_action'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    "wprism: Polylang native option materialization requires WordPress $function()"
                );
            }
        }
        $shutdownPriority = has_action('shutdown', [$options, 'save_all']);
        if (($shutdownExpected && $shutdownPriority !== 1000)
            || (!$shutdownExpected && $shutdownPriority !== false)) {
            throw new \RuntimeException(
                'wprism: Polylang native option shutdown callback does not match the reviewed 3.8.x topology'
            );
        }
        $callbacks = $this->hook_callbacks('pre_update_option_polylang');
        if (count($callbacks) !== 1
            || $callbacks[0]['priority'] !== 1
            || $callbacks[0]['accepted_args'] !== 1
            || !is_array($callbacks[0]['function'])
            || count($callbacks[0]['function']) !== 2
            || $callbacks[0]['function'][0] !== $options
            || $callbacks[0]['function'][1] !== 'protect_wp_option_storage') {
            throw new \RuntimeException(
                'wprism: Polylang native option storage filter does not match the reviewed 3.8.x topology'
            );
        }
        try {
            NativeRewriteEffects::assert_inert_polylang_option_filter_topology();
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: Polylang native option materialization refuses an unaudited option filter topology',
                0,
                $failure
            );
        }
        foreach ([
            'pre_option_polylang',
            'option_polylang',
            'default_option_polylang',
            'default_option',
            'sanitize_option_polylang',
            'pre_wp_load_alloptions',
            'pre_cache_alloptions',
            'alloptions',
        ] as $hook) {
            if ($this->hook_callbacks($hook) !== []) {
                throw new \RuntimeException(
                    'wprism: Polylang native option materialization refuses an unaudited option filter topology'
                );
            }
        }
    }

    /** @return list<array{priority:int,function:mixed,accepted_args:int}> */
    private function hook_callbacks(string $name): array {
        global $wp_filter;
        if (!isset($wp_filter[$name])) {
            return [];
        }
        $hook = $wp_filter[$name];
        $buckets = is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks)
            ? $hook->callbacks
            : (is_array($hook) ? $hook : null);
        if (!is_array($buckets)) {
            throw new \RuntimeException('wprism: Polylang option hook registry is malformed');
        }
        $out = [];
        foreach ($buckets as $priority => $entries) {
            if (!is_int($priority) || !is_array($entries)) {
                throw new \RuntimeException('wprism: Polylang option hook registry is malformed');
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)
                    || !array_key_exists('function', $entry)
                    || !isset($entry['accepted_args'])
                    || !is_int($entry['accepted_args'])) {
                    throw new \RuntimeException('wprism: Polylang option hook registry is malformed');
                }
                $out[] = [
                    'priority' => $priority,
                    'function' => $entry['function'],
                    'accepted_args' => $entry['accepted_args'],
                ];
            }
        }
        return $out;
    }

    /**
     * Options::set()/reset()/merge() arm a private modified bit. Let the public
     * save boundary consume it while terminal filters force old===new, so core
     * performs no SQL or cache publication. The second save and save_all calls
     * are executable proof that neither a direct caller nor shutdown can write
     * after WPrism releases its row locks.
     *
     * @param array<string,mixed> $lockedValue
     */
    private function consume_native_modified_state(object $options, array $lockedValue, string $where): void {
        $this->assert_native_option_hook_topology($options, false);
        $forceRead = static fn(mixed $pre): array => $lockedValue;
        $forceSpecificOld = static fn(mixed $value, mixed $old): mixed => $old;
        $forceGenericOld = static fn(mixed $value, string $name, mixed $old): mixed => $old;
        $added = [];
        $primaryFailure = null;
        try {
            foreach ([
                ['pre_option_polylang', $forceRead, PHP_INT_MAX, 3],
                ['pre_update_option_polylang', $forceSpecificOld, PHP_INT_MAX, 3],
                ['pre_update_option', $forceGenericOld, PHP_INT_MAX, 3],
            ] as [$hook, $callback, $priority, $acceptedArgs]) {
                if (!add_filter($hook, $callback, $priority, $acceptedArgs)) {
                    throw new \RuntimeException("wprism: Polylang $where could not install a native no-write guard");
                }
                $added[] = [$hook, $callback, $priority];
            }
            if ($options->save() !== false) {
                throw new \RuntimeException("wprism: Polylang $where native modified-state consume attempted a write");
            }
        } catch (\Throwable $failure) {
            $primaryFailure = $failure;
        } finally {
            $cleanupFailures = [];
            foreach (array_reverse($added) as [$hook, $callback, $priority]) {
                try {
                    if (!remove_filter($hook, $callback, $priority)) {
                        throw new \RuntimeException(
                            "wprism: Polylang $where could not remove a native no-write guard"
                        );
                    }
                } catch (\Throwable $failure) {
                    $cleanupFailures[] = $failure;
                }
            }
            if ($cleanupFailures !== []) {
                $parts = [];
                if ($primaryFailure !== null) {
                    $parts[] = 'primary=' . self::failure_fingerprint($primaryFailure);
                }
                foreach ($cleanupFailures as $failure) {
                    $parts[] = 'remove=' . self::failure_fingerprint($failure);
                }
                throw new \RuntimeException(
                    "wprism: Polylang $where native no-write guard cleanup was incomplete; " . implode('; ', $parts),
                    0,
                    $primaryFailure ?? $cleanupFailures[0]
                );
            }
        }
        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }
        $this->assert_native_option_hook_topology($options, false);
        if ($options->save() !== false) {
            throw new \RuntimeException("wprism: Polylang $where left native modified state armed");
        }
        $options->save_all();
        if ($options->save() !== false) {
            throw new \RuntimeException("wprism: Polylang $where shutdown save re-armed native modified state");
        }
    }

    private function assert_native_result(mixed $errors, string $where): void {
        if (!is_object($errors) || !is_callable([$errors, 'get_error_codes'])) {
            throw new \RuntimeException("wprism: Polylang $where returned an unreadable validation result");
        }
        $codes = $errors->get_error_codes();
        if (!is_array($codes)) {
            throw new \RuntimeException("wprism: Polylang $where returned malformed error codes");
        }
        if ($codes === []) {
            return;
        }
        $safeCodes = array_slice(array_values(array_filter(
            array_map('strval', $codes),
            static fn(string $code): bool => preg_match('/^[a-z0-9_-]{1,96}$/D', $code) === 1
        )), 0, 8);
        throw new \RuntimeException(
            "wprism: Polylang $where refused the option group"
            . ($safeCodes === [] ? '' : ' (' . implode(', ', $safeCodes) . ')')
        );
    }

    private function purge_option_cache(): void {
        if (class_exists(CacheInvalidationTransaction::class, false)
            && CacheInvalidationTransaction::is_active()) {
            CacheInvalidationTransaction::queue_option('polylang', 'Polylang native option cache purge');
            CacheInvalidationTransaction::queue_option(
                'pll_language_from_content_available',
                'Polylang topology-marker cache purge'
            );
            return;
        }
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        // Core returns false when a cache key is already absent. Absence is
        // the desired state, so exact DB/native readback below is the proof.
        wp_cache_delete('polylang', 'options');
        wp_cache_delete('pll_language_from_content_available', 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * Options::save() merges get_option('polylang') before it writes. The
     * row lock therefore protects nothing unless that cached read is first
     * invalidated and proven byte-equivalent to the decoded locked row. The
     * already-instantiated registry is checked too: a singleton constructed
     * from older cache bytes may not become the base of a native merge.
     *
     * @param ?array<string,mixed> $targetValue
     * @param array<string,mixed> $nativeValues
     */
    private function prepare_fresh_locked_primary_option(
        object $options,
        ?array $targetValue,
        array $nativeValues,
        ?\Closure $beforeMutation = null
    ): array {
        $this->purge_option_cache();
        $missing = new \stdClass();
        $effective = get_option('polylang', $missing);
        if ($targetValue === null) {
            if ($effective !== $missing) {
                throw new \RuntimeException(
                    'wprism: Polylang primary option cache disagrees with the locked absent row'
                );
            }
            $targetValue = [];
        } elseif (!is_array($effective) || $effective !== $targetValue) {
            throw new \RuntimeException(
                'wprism: Polylang primary option cache disagrees with the exact locked row'
            );
        }
        $prepared = [];
        foreach (self::NATIVE_OPTION_KEYS as $key) {
            if (!array_key_exists($key, $targetValue)) {
                if ($beforeMutation !== null) {
                    $beforeMutation();
                }
                $default = $options->reset($key);
                $current = $options->get($key);
                if ($current === null || $default !== $current) {
                    throw new \RuntimeException(
                        'wprism: Polylang native registry could not project a raw-missing registered default'
                    );
                }
                $prepared[$key] = $current;
                continue;
            }
            $raw = $targetValue[$key];
            $native = $nativeValues[$key];
            $equivalent = $raw === $native;
            if (in_array($key, self::NATIVE_BOOLEAN_OPTION_KEYS, true)
                && is_bool($native)
                && is_int($raw)
                && in_array($raw, [0, 1], true)) {
                $equivalent = (bool) $raw === $native;
            }
            if (!$equivalent) {
                throw new \RuntimeException(
                    'wprism: Polylang in-memory option registry disagrees with the exact locked row'
                );
            }
            $prepared[$key] = $native;
        }
        return $prepared;
    }

    /**
     * Force_Lang::get_data_structure() calls get_option() during merge(). The
     * raw row/gap lock is authoritative for concurrency, but the native API
     * can still consume a stale object-cache byte unless both cache frontiers
     * are purged and the effective read is checked against that locked row.
     */
    private function fresh_locked_topology_marker(?array $row): ?string {
        $this->purge_option_cache();
        $missing = new \stdClass();
        $effective = get_option('pll_language_from_content_available', $missing);
        if ($row === null) {
            if ($effective !== $missing) {
                throw new \RuntimeException(
                    'wprism: Polylang target topology marker cache disagrees with the locked absent row'
                );
            }
            return null;
        }
        $raw = $row['option_value'] ?? null;
        if (!is_string($raw)
            || $effective === $missing
            || !is_string($effective)
            || !hash_equals($raw, $effective)) {
            throw new \RuntimeException(
                'wprism: Polylang target topology marker cache disagrees with the exact locked row'
            );
        }
        return hash_equals($raw, 'yes') ? 'yes' : null;
    }

    private function assert_biography(mixed $value, string $where): void {
        if (!is_string($value) || strlen($value) > self::BIOGRAPHY_MAX_BYTES) {
            throw new \RuntimeException(
                "wprism: Polylang $where must be a scalar string of at most "
                . self::BIOGRAPHY_MAX_BYTES . ' bytes'
            );
        }
        if (preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \RuntimeException(
                "wprism: Polylang $where must be valid UTF-8 without unsafe control bytes"
            );
        }
        // Classification and repository diagnostics run in the standalone
        // compiler too. The returned native profile delegates actual KSES to
        // Capture, target planning and resolved-value Apply, never a host stub.
    }

    private function assert_source_language_flags(mixed $runtime): void {
        $model = is_object($runtime) ? ($runtime->model ?? null) : null;
        if (!is_object($model) || !is_callable([$model, 'get_languages_list'])) {
            throw new \RuntimeException(
                'wprism: Polylang native option capture cannot audit language flag dependencies'
            );
        }
        $languages = $model->get_languages_list();
        if (!is_array($languages) || !array_is_list($languages) || count($languages) > 512) {
            throw new \RuntimeException('wprism: Polylang language flag audit returned an invalid bounded language list');
        }
        foreach ($languages as $language) {
            if (!is_object($language)) {
                throw new \RuntimeException('wprism: Polylang language flag audit returned a malformed language');
            }
            $slug = $this->language_slug($language->slug ?? null, false, 'language flag audit slug');
            $flagCode = $language->flag_code ?? null;
            if (!is_string($flagCode) || strlen($flagCode) > 64
                || preg_match('/^[a-z0-9_-]*$/D', $flagCode) !== 1) {
                throw new \RuntimeException("wprism: Polylang language flag dependency for '$slug' is invalid");
            }
            if ((string) ($language->custom_flag_url ?? '') !== ''
                || (string) ($language->custom_flag ?? '') !== '') {
                throw new \RuntimeException(
                    "wprism: Polylang language '$slug' uses a custom flag asset; wp-content/polylang/theme flag "
                    . 'files and pll_custom_flag filters are code/environment topology, not portable state'
                );
            }
            if ($flagCode !== '' && (string) ($language->flag_url ?? '') === '') {
                throw new \RuntimeException(
                    "wprism: Polylang language '$slug' names a flag code that is unavailable in this deployed artifact"
                );
            }
        }
    }

    public function taxonomy_description_lint_rule(string $taxonomy, mixed $description): ?array {
        if ($taxonomy !== 'language') {
            return null;
        }
        $this->assert_language_description($description, 'live language description');
        return ['lint_ok' => true];
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        $languages = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'term') {
                continue;
            }
            $front = $entity['data'] ?? Canon::decode((string) ($entity['content'] ?? ''));
            if (($front['taxonomy'] ?? '') === 'language') {
                try {
                    $slug = $this->language_slug($front['slug'] ?? null, false, 'repository language slug');
                    $languages[$slug] = true;
                } catch (\RuntimeException $failure) {
                    $out[] = [
                        'code' => 'adapter_schema_content_mismatch',
                        'path' => (string) ($entity['path'] ?? ''),
                        'locator' => 'slug',
                        'message' => $failure->getMessage(),
                    ];
                }
            }
        }
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') === 'term') {
                $front = $entity['data'] ?? Canon::decode((string) ($entity['content'] ?? ''));
                $meta = (array) ($front['meta'] ?? []);
                if (array_key_exists('_pll_strings_translations', $meta)) {
                    if (($front['taxonomy'] ?? '') !== 'language') {
                        $out[] = [
                            'code' => 'adapter_schema_content_mismatch',
                            'path' => (string) ($entity['path'] ?? ''),
                            'locator' => 'meta._pll_strings_translations',
                            'message' => 'wprism: Polylang string catalogs are valid only on language terms',
                        ];
                    } else {
                        try {
                            $this->assert_string_catalog(
                                $meta['_pll_strings_translations'],
                                'repository _pll_strings_translations',
                                false
                            );
                        } catch (\RuntimeException $e) {
                            $out[] = [
                                'code' => 'adapter_schema_content_mismatch',
                                'path' => (string) ($entity['path'] ?? ''),
                                'locator' => 'meta._pll_strings_translations',
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                }
                if (($front['taxonomy'] ?? '') !== 'language') {
                    continue;
                }
                try {
                    $this->assert_language_description(
                        $front['description'] ?? null,
                        'repository language description'
                    );
                } catch (\RuntimeException $e) {
                    $out[] = [
                        'code' => 'adapter_schema_content_mismatch',
                        'path' => (string) ($entity['path'] ?? ''),
                        'locator' => 'description',
                        'message' => $e->getMessage(),
                    ];
                }
                continue;
            }
            if (($entity['type'] ?? '') === 'options') {
                $records = $entity['data']['records'] ?? null;
                $record = is_array($records) ? ($records['polylang'] ?? null) : null;
                if (($record['state'] ?? null) === 'present') {
                    try {
                        $portable = $record['value'] ?? null;
                        $this->assert_portable_options($portable, true);
                        $defaultLanguage = (string) $portable['default_lang'];
                        if (($defaultLanguage === '' && $languages !== [])
                            || ($defaultLanguage !== '' && !isset($languages[$defaultLanguage]))) {
                            throw new \RuntimeException(
                                'wprism: Polylang portable option default_lang must be empty exactly when the '
                                . 'repository has no language terms, or name an exact repository language slug'
                            );
                        }
                    } catch (\RuntimeException $e) {
                        $out[] = [
                            'code' => 'adapter_schema_content_mismatch',
                            'path' => (string) ($entity['path'] ?? 'state/options/core.json'),
                            'locator' => 'records.polylang.value',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
                continue;
            }
            if (($entity['type'] ?? '') === 'sidebar') {
                foreach ((array) ($entity['data']['widgets'] ?? []) as $position => $widget) {
                    if (($widget['type'] ?? null) !== 'polylang') {
                        continue;
                    }
                    try {
                        $this->assert_widget_settings(
                            $widget['settings'] ?? null,
                            $languages,
                            'repository language-switcher widget'
                        );
                    } catch (\RuntimeException $e) {
                        $out[] = [
                            'code' => 'adapter_schema_content_mismatch',
                            'path' => (string) ($entity['path'] ?? ''),
                            'locator' => "widgets[$position].settings",
                            'message' => $e->getMessage(),
                        ];
                    }
                }
                continue;
            }
            if (($entity['type'] ?? '') === 'user-meta') {
                $front = $entity['data'] ?? Canon::decode((string) ($entity['content'] ?? ''));
                foreach ((array) ($front['meta'] ?? []) as $key => $value) {
                    if ($key !== 'description'
                        && preg_match('/^description_([a-z][a-z0-9_-]{0,199})$/D', (string) $key, $match) === 1
                        && !isset($languages[$match[1]])) {
                        $out[] = [
                            'code' => 'adapter_schema_content_mismatch',
                            'path' => (string) ($entity['path'] ?? ''),
                            'locator' => 'meta.' . $key,
                            'message' => "wprism: Polylang biography suffix '{$match[1]}' has no repository language term",
                        ];
                    }
                    if ($key === 'description' || str_starts_with((string) $key, 'description_')) {
                        try {
                            $this->assert_biography($value, "repository user meta $key");
                        } catch (\RuntimeException $failure) {
                            $out[] = [
                                'code' => 'adapter_schema_content_mismatch',
                                'path' => (string) ($entity['path'] ?? ''),
                                'locator' => 'meta.' . $key,
                                'message' => $failure->getMessage(),
                            ];
                        }
                    }
                }
                continue;
            }
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $front = $entity['data'] ?? Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
            $meta = (array) ($front['meta'] ?? []);
            if (!array_key_exists('_pll_menu_item', $meta)) {
                continue;
            }
            try {
                $this->assert_menu_switcher($meta['_pll_menu_item'], 'repository _pll_menu_item');
            } catch (\RuntimeException $e) {
                $out[] = [
                    'code' => 'adapter_schema_content_mismatch',
                    'path' => (string) ($entity['path'] ?? ''),
                    'locator' => 'meta._pll_menu_item',
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $out;
    }

    /** @param array<string,bool> $languages */
    private function assert_widget_settings(mixed $settings, array $languages, string $where): void {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw new \RuntimeException("wprism: Polylang $where settings must be an object");
        }
        $required = array_merge(['title'], self::MENU_KEYS);
        $allowed = array_merge($required, ['pll_lang']);
        $keys = array_keys($settings);
        sort($keys, SORT_STRING);
        $expected = $required;
        sort($expected, SORT_STRING);
        $withLanguage = $allowed;
        sort($withLanguage, SORT_STRING);
        if ($keys !== $expected && $keys !== $withLanguage) {
            throw new \RuntimeException(
                "wprism: Polylang $where must contain title and exactly the six native switcher toggles, "
                . 'with only optional pll_lang'
            );
        }
        $title = $settings['title'];
        if (!is_string($title)
            || strlen($title) > self::WIDGET_TITLE_MAX_BYTES
            || preg_match('//u', $title) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title) === 1) {
            throw new \RuntimeException("wprism: Polylang $where title is not a bounded canonical scalar string");
        }
        if (function_exists('sanitize_text_field') && sanitize_text_field($title) !== $title) {
            throw new \RuntimeException("wprism: Polylang $where title is not canonical under sanitize_text_field()");
        }
        foreach (self::MENU_KEYS as $key) {
            if (!is_int($settings[$key]) || !in_array($settings[$key], [0, 1], true)) {
                throw new \RuntimeException("wprism: Polylang $where.$key must be the native integer 0 or 1");
            }
        }
        if (array_key_exists('pll_lang', $settings)) {
            $language = $this->language_slug($settings['pll_lang'], false, "$where pll_lang");
            if (!isset($languages[$language])) {
                throw new \RuntimeException(
                    "wprism: Polylang $where pll_lang has no exact repository language term"
                );
            }
        }
    }

    private function assert_language_description(mixed $value, string $where): void {
        if (!is_string($value)) {
            throw new \RuntimeException("wprism: Polylang $where must be canonical PHP-serialized plain data");
        }
        try {
            $decoded = PlainData::decode_serialized($value, "Polylang $where");
        } catch (\RuntimeException $failure) {
            throw new \RuntimeException(
                "wprism: Polylang $where must be canonical PHP-serialized plain data",
                0,
                $failure
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("wprism: Polylang $where must be an object");
        }
        $keys = array_keys($decoded);
        sort($keys, SORT_STRING);
        if ($keys !== ['flag_code', 'locale', 'rtl']) {
            throw new \RuntimeException(
                "wprism: Polylang $where must contain exactly flag_code, locale, rtl"
            );
        }
        if (!is_string($decoded['locale'])
            || preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/D', $decoded['locale']) !== 1) {
            throw new \RuntimeException("wprism: Polylang $where.locale is invalid");
        }
        if (!(is_bool($decoded['rtl'])
            || (is_int($decoded['rtl']) && in_array($decoded['rtl'], [0, 1], true)))) {
            throw new \RuntimeException("wprism: Polylang $where.rtl must be native boolean/integer 0 or 1");
        }
        if (!is_string($decoded['flag_code'])
            || preg_match('/^[a-z0-9_-]{0,64}$/D', $decoded['flag_code']) !== 1) {
            throw new \RuntimeException("wprism: Polylang $where.flag_code is invalid");
        }
    }

    private function assert_portable_options(mixed $value, bool $repository): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('wprism: Polylang portable option must be an object');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== self::OPTION_KEYS) {
            throw new \RuntimeException(
                'wprism: Polylang portable option must contain exactly ' . implode(', ', self::OPTION_KEYS)
            );
        }
        $this->language_slug($value['default_lang'], true, 'portable option default_lang');
        if (!is_int($value['force_lang']) || !in_array($value['force_lang'], [0, 1], true)) {
            throw new \RuntimeException('wprism: Polylang portable option force_lang supports only native modes 0 or 1');
        }
        foreach (['browser', 'hide_default', 'media_support', 'redirect_lang', 'rewrite'] as $booleanKey) {
            if (!is_bool($value[$booleanKey])) {
                throw new \RuntimeException("wprism: Polylang portable option $booleanKey must be a native boolean");
            }
        }
        $this->assert_slug_list($value['post_types'], 'post_types', 20, self::OBJECT_TYPE_MAX_ITEMS);
        $this->assert_slug_list($value['taxonomies'], 'taxonomies', 32, self::OBJECT_TYPE_MAX_ITEMS);
        $this->assert_slug_list($value['sync'], 'sync', 64, count(self::SYNC_VALUES), self::SYNC_VALUES);
        $this->assert_nav_menus($value['nav_menus'], $repository);
    }

    /** @param list<string>|null $allow */
    private function assert_slug_list(
        mixed $value,
        string $key,
        int $maxLength,
        int $maxItems,
        ?array $allow = null
    ): void {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException("wprism: Polylang portable option $key must be a list");
        }
        if (count($value) > $maxItems) {
            throw new \RuntimeException("wprism: Polylang portable option $key exceeds the bounded item limit");
        }
        $bytes = 0;
        foreach ($value as $item) {
            if (!is_string($item) || preg_match('/^[a-z0-9_-]{1,' . $maxLength . '}$/D', $item) !== 1
                || ($allow !== null && !in_array($item, $allow, true))) {
                throw new \RuntimeException("wprism: Polylang portable option $key contains an unsupported value");
            }
            $bytes += strlen($item);
            if ($bytes > self::OBJECT_TYPE_MAX_TOTAL_BYTES) {
                throw new \RuntimeException("wprism: Polylang portable option $key exceeds the bounded byte limit");
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new \RuntimeException("wprism: Polylang portable option $key contains duplicates");
        }
    }

    private function assert_nav_menus(mixed $value, bool $repository): void {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException('wprism: Polylang portable option nav_menus must be an object');
        }
        if (count($value) > self::NAV_MAX_THEMES) {
            throw new \RuntimeException('wprism: Polylang portable option nav_menus exceeds the bounded theme limit');
        }
        $assignments = 0;
        $bytes = 0;
        foreach ($value as $stylesheet => $locations) {
            if (!$this->valid_stylesheet($stylesheet)
                || !is_array($locations) || ($locations !== [] && array_is_list($locations))) {
                throw new \RuntimeException('wprism: Polylang portable option nav_menus theme map is invalid');
            }
            if (count($locations) > self::NAV_MAX_LOCATIONS_PER_THEME) {
                throw new \RuntimeException(
                    'wprism: Polylang portable option nav_menus exceeds the bounded per-theme location limit'
                );
            }
            $bytes += strlen($stylesheet);
            foreach ($locations as $location => $languages) {
                if (!is_string($location)
                    || $location === ''
                    || strlen($location) > self::NAV_LOCATION_MAX_BYTES
                    || preg_match('//u', $location) !== 1
                    || preg_match('/[\x00-\x1F\x7F]/', $location) === 1
                    || !is_array($languages) || ($languages !== [] && array_is_list($languages))) {
                    throw new \RuntimeException('wprism: Polylang portable option nav_menus location map is invalid');
                }
                if (count($languages) > self::NAV_MAX_LANGUAGES_PER_LOCATION) {
                    throw new \RuntimeException(
                        'wprism: Polylang portable option nav_menus exceeds the bounded per-location language limit'
                    );
                }
                $bytes += strlen($location);
                foreach ($languages as $language => $menu) {
                    try {
                        $language = $this->language_slug($language, false, 'portable nav_menus language');
                    } catch (\RuntimeException) {
                        throw new \RuntimeException(
                            'wprism: Polylang portable option nav_menus language entry is invalid'
                        );
                    }
                    if (!($menu === 0 || ($repository
                            ? (is_string($menu)
                                && preg_match('/^\{\{term:[0-9a-f-]{36}\}\}$/D', $menu) === 1)
                            : (is_int($menu) && $menu > 0)))) {
                        throw new \RuntimeException('wprism: Polylang portable option nav_menus language entry is invalid');
                    }
                    ++$assignments;
                    $bytes += strlen($language) + (is_string($menu) ? strlen($menu) : 8);
                    if ($assignments > self::NAV_MAX_ASSIGNMENTS || $bytes > self::NAV_MAX_TOTAL_BYTES) {
                        throw new \RuntimeException(
                            'wprism: Polylang portable option nav_menus exceeds the bounded aggregate limit'
                        );
                    }
                }
            }
        }
    }

    private function language_slug(mixed $value, bool $allowEmpty, string $where): string {
        if (!is_string($value)
            || strlen($value) > self::LANGUAGE_SLUG_MAX_BYTES
            || ($value === '' && !$allowEmpty)
            || ($value !== '' && preg_match('/^[a-z][a-z0-9_-]*$/D', $value) !== 1)) {
            throw new \RuntimeException("wprism: Polylang $where is invalid");
        }
        return $value;
    }

    private function valid_stylesheet(mixed $value): bool {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::THEME_COMPONENT_MAX_BYTES
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && preg_match('/^[^\/:<>*?"|]+$/uD', $value) === 1;
    }

    private function assert_supported_topology(mixed $mode, mixed $marker, string $where): void {
        if (!is_int($mode) || !in_array($mode, [0, 1, 2, 3], true)) {
            throw new \RuntimeException("wprism: Polylang $where force_lang is invalid");
        }
        if (in_array($mode, [2, 3], true)) {
            throw new \RuntimeException(
                "wprism: Polylang $where force_lang mode $mode is topology-bound; subdomain/domain DNS, TLS, "
                . 'cookie and canonical-host bindings are not portable in this adapter'
            );
        }
        if ($mode === 0 && $marker !== 'yes') {
            throw new \RuntimeException(
                "wprism: Polylang $where force_lang mode 0 requires target-local "
                . "pll_language_from_content_available='yes'; WPrism never copies or forges that marker"
            );
        }
    }

    private static function fingerprint(mixed $value): string {
        $bytes = is_string($value) ? $value : get_debug_type($value);
        return get_debug_type($value) . ':' . strlen($bytes) . ':' . substr(hash('sha256', $bytes), 0, 16);
    }

    private static function failure_fingerprint(\Throwable $failure): string {
        $message = $failure->getMessage();
        return get_class($failure) . ':' . strlen($message) . ':'
            . substr(hash('sha256', $message), 0, 16);
    }

    private function assert_string_catalog(mixed $value, string $where, bool $serialized): void {
        if ($serialized) {
            if (!is_string($value)) {
                throw new \RuntimeException("wprism: Polylang $where must be canonical serialized plain data");
            }
            $value = PlainData::decode_serialized($value, "Polylang $where");
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException("wprism: Polylang $where must be a list of [source, translation] rows");
        }
        if (count($value) > self::CATALOG_MAX_ROWS) {
            throw new \RuntimeException("wprism: Polylang $where exceeds the bounded row limit");
        }
        $sources = [];
        $bytes = 0;
        foreach ($value as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 2
                || !is_string($row[0] ?? null) || !is_string($row[1] ?? null)
                || $row[0] === '') {
                throw new \RuntimeException(
                    "wprism: Polylang $where rows must contain exactly a nonempty source and string translation"
                );
            }
            $sourceBytes = strlen($row[0]);
            $translationBytes = strlen($row[1]);
            if ($sourceBytes > self::CATALOG_MAX_STRING_BYTES
                || $translationBytes > self::CATALOG_MAX_STRING_BYTES) {
                throw new \RuntimeException("wprism: Polylang $where contains an oversized string");
            }
            $bytes += $sourceBytes + $translationBytes;
            if ($bytes > self::CATALOG_MAX_TOTAL_BYTES) {
                throw new \RuntimeException("wprism: Polylang $where exceeds the bounded byte limit");
            }
            $sourceHash = hash('sha256', $row[0]);
            if (isset($sources[$sourceHash])) {
                throw new \RuntimeException("wprism: Polylang $where contains duplicate source strings");
            }
            $sources[$sourceHash] = true;
        }
    }

    private function assert_menu_switcher(mixed $value, string $where): void {
        if (is_string($value)) {
            $value = PlainData::decode_serialized($value, "Polylang $where");
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("wprism: Polylang $where must be an object with the exact native switcher keys");
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== self::MENU_KEYS) {
            throw new \RuntimeException(
                "wprism: Polylang $where must contain exactly " . implode(', ', self::MENU_KEYS)
            );
        }
        foreach (self::MENU_KEYS as $key) {
            $candidate = $value[$key];
            if (!(is_bool($candidate) || (is_int($candidate) && ($candidate === 0 || $candidate === 1)))) {
                throw new \RuntimeException(
                    "wprism: Polylang $where.$key must be native boolean/integer 0 or 1"
                );
            }
        }
    }
}
