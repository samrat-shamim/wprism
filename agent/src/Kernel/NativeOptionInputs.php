<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseQueryIsolation.php';
require_once __DIR__ . '/ExactOptionReader.php';
require_once __DIR__ . '/PlainData.php';

/**
 * Transparent, exact-input witnessing for an audited native option consumer.
 *
 * A before/after getter can miss an alternating filter's intermediate input.
 * WordPress applies option_<name> after maybe_unserialize(), so admitting raw
 * physical/cache bytes before invocation is also necessary to prevent object
 * construction before the terminal observer. This is not a callback sandbox:
 * the caller still owns native semantics and its non-rollback effects. No
 * cache value is supplied, cleared or repaired to manufacture a premise.
 */
final class NativeOptionInputs {
    private const MAX_NAMES = 8;
    private const MAX_READS = 32;
    private const MAX_VALUE_BYTES = 1048576;
    private const MAX_DEFAULT_BYTES = 65536;
    private const SHARED_HOOKS = [
        'pre_option', 'pre_wp_load_alloptions', 'pre_cache_alloptions',
        'alloptions', 'wp_autoload_values_to_autoload',
    ];
    // These core keys redirect, trim or occupy internal cache slots rather
    // than returning an ordinary exact row through the selected terminal hook.
    private const NON_ROW_NAMES = [
        'home', 'siteurl', 'category_base', 'tag_base', 'blacklist_keys',
        'comment_whitelist', 'alloptions', 'notoptions',
    ];
    private static bool $running = false;

    /** @var array<string,array{default:mixed,passed_default:bool,reads:int,row:?array{raw:string,value:mixed},seen:int}> */
    private array $inputs = [];
    /** @var list<string> */
    private array $protectedHooks = self::SHARED_HOOKS;
    /** @var array<string,array{object:object,callbacks:array,callback:\Closure}> */
    private array $ownedHooks = [];
    private ?string $pending = null;
    private ?object $cache = null;
    private ?string $cachePrefix = null;

    private function __construct(private readonly string $context) {
    }

    /**
     * Read expectations from durable rows inside the existing profile; exact
     * default/passed-default/read counts describe the native API, not values.
     * Bounds cover selected inputs, not arbitrary allocations by native code.
     * Request-local cache warming and hit counters are real, non-durable effects.
     *
     * @template T
     * @param list<array{name:string,default:mixed,passed_default:bool,reads:int}> $inputs
     * @param callable():T $native
     * @return T
     */
    public static function observe(array $inputs, callable $native, string $context): mixed {
        if (self::$running) {
            DatabaseQueryIsolation::violation("wprism: $context cannot nest native option input scopes");
        }
        $scope = new self($context);
        self::$running = true;
        $failure = null;
        try {
            $scope->admit($inputs);
            $scope->install();
            $scope->assert_topology();
            $scope->assert_cache();
            $result = $native();
            $scope->assert_topology();
            $scope->assert_cache();
            if ($scope->pending !== null) {
                $scope->refuse('a native option read did not reach its terminal observer');
            }
            foreach ($scope->inputs as $input) {
                if ($input['seen'] !== $input['reads']) {
                    $scope->refuse('native option read counts disagree with the declared inputs');
                }
            }
            return $result;
        } catch (\Throwable $caught) {
            $failure = $caught;
            DatabaseQueryIsolation::poison();
            if ($caught instanceof DatabaseQueryIsolationViolationException) {
                throw $caught;
            }
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context native option input witness failed", 0, $caught
            );
        } finally {
            try {
                $scope->remove_owned_hooks();
            } catch (\Throwable $cleanupFailure) {
                DatabaseQueryIsolation::poison();
                throw new DatabaseQueryIsolationViolationException(
                    "wprism: $context native option observer cleanup found changed hook topology",
                    0,
                    $failure ?? $cleanupFailure
                );
            } finally {
                self::$running = false;
            }
        }
    }

    private function admit(array $inputs): void {
        global $wpdb;
        DatabaseQueryIsolation::assert_original_all_hook_absent($this->context);
        DatabaseQueryIsolation::assert_profile_contains([$wpdb->options], false, $this->context);
        if (!array_is_list($inputs) || $inputs === [] || count($inputs) > self::MAX_NAMES) {
            $this->refuse('native option inputs require a bounded nonempty list');
        }
        $totalReads = 0;
        foreach ($inputs as $input) {
            $keys = is_array($input) ? array_keys($input) : [];
            sort($keys, SORT_STRING);
            $name = is_array($input) ? ($input['name'] ?? null) : null;
            if ($keys !== ['default', 'name', 'passed_default', 'reads']
                || !is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,190}$/D', $name) !== 1
                || in_array($name, self::NON_ROW_NAMES, true) || isset($this->inputs[$name])
                || !is_bool($input['passed_default']) || !is_int($input['reads'])
                || $input['reads'] < 1 || $input['reads'] > self::MAX_READS
                || (!$input['passed_default'] && $input['default'] !== false)) {
                $this->refuse('native option input descriptor is malformed or outside the exact-row frontier');
            }
            PlainData::assert($input['default'], $this->context . ' native option default');
            if (strlen(serialize($input['default'])) > self::MAX_DEFAULT_BYTES
                || ($totalReads += $input['reads']) > self::MAX_READS) {
                $this->refuse('native option defaults or read counts exceed the bounded frontier');
            }
            $this->inputs[$name] = [
                'default' => $input['default'], 'passed_default' => $input['passed_default'],
                'reads' => $input['reads'], 'seen' => 0, 'row' => null,
            ];
            foreach (['pre_option_', 'default_option_', 'option_'] as $prefix) {
                $this->protectedHooks[] = $prefix . $name;
            }
        }
        $this->assert_topology();
        $this->assert_core_cache();
        foreach ($this->inputs as $name => &$input) {
            $input['row'] = ExactOptionReader::read_row($name, $this->context . ' native option input', $wpdb, self::MAX_VALUE_BYTES);
        }
        unset($input);
    }

    private function install(): void {
        $this->add_observer('pre_option', function ($value, $name, $default): mixed {
            $this->assert_topology();
            $this->assert_cache();
            if ($value !== false || !is_string($name) || !isset($this->inputs[$name]) || $this->pending !== null
                || $default !== $this->inputs[$name]['default']
                || $this->inputs[$name]['seen'] >= $this->inputs[$name]['reads']) {
                $this->refuse('native option read changed its name, default, count or short-circuit premise');
            }
            $this->pending = $name;
            return $value;
        }, 3);
        foreach ($this->inputs as $name => $_input) {
            foreach (['option_', 'default_option_'] as $prefix) {
                $defaultPath = $prefix === 'default_option_';
                $this->add_observer($prefix . $name, function ($value, $option, $passedDefault = null) use ($name, $defaultPath): mixed {
                    $this->assert_topology();
                    $this->assert_cache();
                    $input = $this->inputs[$name];
                    $expected = $input['row'] === null ? $input['default'] : $input['row']['value'];
                    if ($this->pending !== $name || $option !== $name
                        || $defaultPath !== ($input['row'] === null) || $value !== $expected
                        || ($defaultPath && $passedDefault !== $input['passed_default'])) {
                        $this->refuse('native option terminal value or presence/default path disagrees with its physical input');
                    }
                    $this->inputs[$name]['seen']++;
                    $this->pending = null;
                    return $value;
                }, $defaultPath ? 3 : 2);
            }
        }
    }

    private function add_observer(string $name, \Closure $callback, int $args): void {
        add_filter($name, $callback, PHP_INT_MAX, $args);
        $hook = $GLOBALS['wp_filter'][$name] ?? null;
        if (!is_object($hook) || get_class($hook) !== 'WP_Hook' || !is_array($hook->callbacks)) {
            $this->refuse('native option observers require standard WordPress hook objects');
        }
        $this->ownedHooks[$name] = ['object' => $hook, 'callbacks' => $hook->callbacks, 'callback' => $callback];
    }

    private function assert_topology(): void {
        DatabaseQueryIsolation::assert_original_all_hook_absent($this->context);
        $hooks = $GLOBALS['wp_filter'] ?? null;
        if (!is_array($hooks)) {
            $this->refuse('native option inputs require canonical WordPress hook storage');
        }
        foreach ($this->protectedHooks as $name) {
            $owned = $this->ownedHooks[$name] ?? null;
            if ($owned === null) {
                if (array_key_exists($name, $hooks)) {
                    $this->refuse('native option inputs found a pre-existing or newly installed option hook');
                }
            } elseif (($hooks[$name] ?? null) !== $owned['object'] || $owned['object']->callbacks !== $owned['callbacks']) {
                $this->refuse('native option observer topology changed');
            }
        }
    }

    private function assert_core_cache(): void {
        if (defined('WP_SETUP_CONFIG') || !function_exists('wp_installing') || wp_installing()
            || !function_exists('wp_using_ext_object_cache') || wp_using_ext_object_cache() !== false
            || !defined('ABSPATH') || !defined('WPINC')) {
            $this->refuse('native option inputs require ordinary WordPress with its request-local core cache');
        }
        $cache = $GLOBALS['wp_object_cache'] ?? null;
        $core = rtrim(ABSPATH, '/\\') . '/' . WPINC . '/';
        if (!is_object($cache) || get_class($cache) !== 'WP_Object_Cache'
            || realpath($core . 'class-wp-object-cache.php') === false
            || (new \ReflectionClass($cache))->getFileName() !== realpath($core . 'class-wp-object-cache.php')) {
            $this->refuse('native option inputs cannot witness a substituted object cache');
        }
        foreach (['wp_cache_get', 'wp_cache_set', 'wp_cache_add'] as $function) {
            if (!function_exists($function) || realpath($core . 'cache.php') === false
                || (new \ReflectionFunction($function))->getFileName() !== realpath($core . 'cache.php')) {
                $this->refuse('native option inputs cannot witness substituted cache functions');
            }
        }
        foreach (['cache', 'multisite', 'global_groups', 'blog_prefix'] as $property) {
            if (!$cache->__isset($property)) {
                $this->refuse('native option inputs found incomplete core cache state');
            }
        }
        // Core explicitly exposes these properties through its public __get
        // compatibility view. Do not use get(): it clones objects before a
        // caller can reject them (WP 7.1 class-wp-object-cache.php:378-379).
        $multisite = $cache->__get('multisite');
        $groups = $cache->__get('global_groups');
        if (!is_bool($multisite) || !is_array($groups)) {
            $this->refuse('native option inputs found malformed core cache routing');
        }
        $prefix = $multisite && !isset($groups['options']) ? $cache->__get('blog_prefix') : '';
        if (!is_string($prefix) || ($prefix !== '' && preg_match('/^[1-9][0-9]*:$/D', $prefix) !== 1)
            || ($this->cache !== null && ($cache !== $this->cache || $prefix !== $this->cachePrefix))) {
            $this->refuse('native option inputs found changed core cache routing');
        }
        $this->cache = $cache;
        $this->cachePrefix = $prefix;
    }

    private function assert_cache(): void {
        $this->assert_core_cache();
        $cache = $this->cache->__get('cache');
        if (!is_array($cache) || (array_key_exists('options', $cache) && !is_array($cache['options']))) {
            $this->refuse('native option inputs found malformed local option-cache storage');
        }
        $options = $cache['options'] ?? [];
        $allKey = $this->cachePrefix . 'alloptions';
        $notKey = $this->cachePrefix . 'notoptions';
        $all = array_key_exists($allKey, $options) ? $options[$allKey] : [];
        $not = array_key_exists($notKey, $options) ? $options[$notKey] : [];
        if (!is_array($all) || !is_array($not)) {
            $this->refuse('native option inputs found non-array alloptions or notoptions cache entries');
        }
        foreach ($this->inputs as $name => $input) {
            $row = $input['row'];
            foreach ([[$all, $name], [$options, $this->cachePrefix . $name]] as [$values, $key]) {
                if (array_key_exists($key, $values)
                    && ($row === null || !is_string($values[$key]) || !hash_equals($row['raw'], $values[$key]))) {
                    $this->refuse('native option cache bytes disagree with the exact physical input');
                }
            }
            if (array_key_exists($name, $not) && ($row !== null || $not[$name] !== true)) {
                $this->refuse('native option absence cache disagrees with the exact physical input');
            }
        }
    }

    private function remove_owned_hooks(): void {
        if ($this->ownedHooks === []) {
            return;
        }
        $drift = false;
        foreach ($this->ownedHooks as $name => $owned) {
            $hook = is_array($GLOBALS['wp_filter'] ?? null) ? ($GLOBALS['wp_filter'][$name] ?? null) : null;
            $drift = $drift || $hook !== $owned['object'] || $owned['object']->callbacks !== $owned['callbacks'];
            // A replaced standard hook may still contain our closure. Remove
            // only that identity; never restore an old object over foreign work.
            if (is_object($hook) && get_class($hook) === 'WP_Hook') {
                remove_filter($name, $owned['callback'], PHP_INT_MAX);
            }
            if (is_array($GLOBALS['wp_filter'] ?? null) && array_key_exists($name, $GLOBALS['wp_filter'])) {
                $drift = true;
            }
        }
        $this->ownedHooks = [];
        if ($drift) {
            $this->refuse('native option observer cleanup did not restore the admitted topology');
        }
    }

    private function refuse(string $reason): never {
        DatabaseQueryIsolation::violation("wprism: {$this->context} $reason");
    }
}
