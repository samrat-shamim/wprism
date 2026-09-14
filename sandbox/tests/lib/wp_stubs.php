<?php
/**
 * Minimal, seedable WordPress function stubs for the offline suites.
 *
 * WHY THIS EXISTS
 * ---------------
 * 33 of the 204 offline regress_*.php suites declare their own global WP
 * function stubs -- get_option in 30 files, wp_upload_dir in 19,
 * apply_filters in 9, is_wp_error / add_filter / add_action in 5 each, plus
 * one-off wp_json_encode, wp_cache_*, update_option, sanitize_title,
 * maybe_unserialize, get_post_types, esc_sql. They diverge: some get_option
 * stubs return false for everything, some return a hard-coded 'home' URL,
 * some read a differently-named $GLOBALS array. A suite therefore cannot be
 * moved or split without re-deriving which flavour of WordPress it was
 * assuming.
 *
 * This file replaces those with ONE seedable in-memory store,
 * \WPrismTest\WpStore, and a set of stubs that read and write it. Every stub is
 * function_exists()-guarded, so:
 *
 *   - a suite that already declares its own stub keeps it (include order
 *     decides, and the suite's own declaration wins if it is declared first
 *     -- an unconditional `function get_option()` at file scope is hoisted
 *     before this require runs, so an existing suite can adopt the store
 *     incrementally without a redeclaration fatal);
 *   - two lib consumers in one PHP process (the PHPUnit tooling tests) do not
 *     collide.
 *
 * SEMANTIC POSTURE: these stubs implement REAL WordPress semantics where the
 * difference is observable, and say so per function below. In particular
 * update_option() returns false when the value is unchanged, and
 * maybe_serialize() leaves scalar null/false alone -- both are WordPress
 * behaviours the engine already compensates for (see
 * ApplyFieldMaterializer::option_wire_value()), so a stub that "helpfully"
 * normalised them would hide the compensation.
 *
 * NO SIDE EFFECTS AT INCLUDE TIME: no directory is created and no global
 * other than the store is written. The ABSPATH / WP_CONTENT_DIR /
 * WP_PLUGIN_DIR defines below point into a temp path but do not create it;
 * call WpStore::instance()->ensureUploadDir() when a test needs real bytes.
 * That directory is per-process and per-store (pid plus a random suffix) and
 * WpStore::reset() removes it again, because `make -j8` runs the corpus
 * concurrently in one shared temp dir -- a fixed path would let two suites
 * see each other's fixture files.
 *
 * SCOPE RULE: only functions the offline suites actually call are stubbed.
 * Adding a stub nobody uses invites a suite to depend on a fiction that no
 * live certification ever exercises.
 */
declare(strict_types=1);

namespace WPrismTest {

/**
 * The single in-memory WordPress state every stub in this file reads.
 *
 * Held as a process singleton rather than passed around because the stubs it
 * backs are global functions with WordPress's own signatures -- there is no
 * parameter to thread an instance through. reset() gives a test a clean slate
 * without unloading the functions.
 */
final class WpStore {
    private static ?self $instance = null;

    /** @var array<string,mixed> option_name => value (already unserialized) */
    public array $options = [];

    /** @var array<string,string> option_name => 'yes'|'no' */
    public array $autoload = [];

    /**
     * Absolute path returned as wp_upload_dir()['basedir'].
     *
     * Unique per PROCESS and per instance, because the authoritative gate is
     * `make -j8` and it does not give each leaf its own TMPDIR the way
     * tools/offline.php does. A fixed /tmp/wprism-uploads would be shared by
     * every suite running at that moment, so one suite's fixture files would
     * be visible to another and "the upload dir holds exactly N files" would
     * flake on scheduling alone.
     */
    public string $uploadBaseDir;

    /**
     * True once ensureUploadDir() created the directory, so reset() knows
     * there is something of ours to remove. Never remove a directory we did
     * not create: uploadBaseDir is a public property a suite may repoint.
     */
    private bool $createdUploadDir = false;

    /** URL returned as wp_upload_dir()['baseurl']. */
    public string $uploadBaseUrl = 'http://example.test/wp-content/uploads';

    /**
     * Hook registry. WordPress keeps filters and actions in ONE structure
     * ($wp_filter) -- add_action() is literally add_filter() -- so this stub
     * does too. A suite that asserts "the action was registered" is really
     * asserting a row here.
     *
     * @var array<string,list<array{callback:callable,priority:int,accepted_args:int,seq:int}>>
     */
    public array $hooks = [];

    /** Monotonic registration counter, so equal priorities keep FIFO order. */
    public int $hookSeq = 0;

    /** @var list<array{hook:string,args:list<mixed>}> every do_action() call, in order */
    public array $firedActions = [];

    /** @var array<string,array<string,mixed>> group => key => value */
    public array $cache = [];

    /**
     * Every wp_cache_get/set/delete call in order, including MISSES.
     * Several suites assert the exact cache-invalidation sequence a write
     * path performs (ApplyFieldMaterializer::upsert_option() must invalidate
     * both the named option and 'alloptions'), and a miss leaves no trace in
     * $cache -- so the sequence has to be recorded separately.
     *
     * @var list<array{op:string,group:string,key:string}>
     */
    public array $cacheEvents = [];

    /** @var array<string,object> post type name => registered object */
    public array $postTypes = [];

    /** Fixed clock in unix seconds; 0 means "use the real clock". */
    public int $now = 0;

    /** Value returned by get_bloginfo('version'). */
    public string $version = '6.5';

    /** Values returned by get_bloginfo() for anything else, e.g. 'url'. */
    public array $blogInfo = [
        'url' => 'http://example.test',
        'wpurl' => 'http://example.test',
        'name' => 'WPrism Offline Fixture',
        'charset' => 'UTF-8',
        'language' => 'en-US',
    ];

    public function __construct() {
        // pid keeps concurrent `make -j8` leaves apart; the random suffix
        // keeps successive WpStore::reset() calls inside ONE process apart, so
        // a suite that resets mid-run starts from an empty root rather than
        // inheriting the files the previous store left behind.
        $this->uploadBaseDir = sys_get_temp_dir()
            . '/wprism-uploads-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Replace the singleton with a pristine store and return it.
     *
     * Removes the outgoing store's upload directory if it created one:
     * nothing else ever would, so without this the fixture bytes outlive the
     * run and leak into the next one (and between the isolated runner and the
     * plain make gate, which share sys_get_temp_dir()).
     */
    public static function reset(): self {
        self::$instance?->removeUploadDir();
        return self::$instance = new self();
    }

    /**
     * @param array<string,mixed> $options
     */
    public function seedOptions(array $options, string $autoload = 'yes'): self {
        foreach ($options as $name => $value) {
            $this->options[$name] = $value;
            $this->autoload[$name] = $autoload;
        }
        return $this;
    }

    /** Register a post type as get_post_types() should report it. */
    public function seedPostType(string $name, array $properties = []): self {
        $this->postTypes[$name] = (object) ($properties + ['name' => $name, 'public' => true]);
        return $this;
    }

    /** Create the upload basedir on disk. Never called implicitly. */
    public function ensureUploadDir(): string {
        if (!is_dir($this->uploadBaseDir)) {
            mkdir($this->uploadBaseDir, 0o777, true);
            $this->createdUploadDir = true;
        }
        return $this->uploadBaseDir;
    }

    /**
     * Delete the upload directory this store created, recursively.
     *
     * Deliberately silent about failures and deliberately scoped to a
     * directory we created under sys_get_temp_dir(): this runs from reset(),
     * i.e. during another suite's setup, and a warning there would be
     * indistinguishable from a real PHP diagnostic to
     * sandbox/tests/offline_diagnostics_guard.sh.
     */
    public function removeUploadDir(): void {
        if (!$this->createdUploadDir || !is_dir($this->uploadBaseDir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadBaseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
                continue;
            }
            @unlink($entry->getPathname());
        }
        @rmdir($this->uploadBaseDir);
        $this->createdUploadDir = false;
    }

    /** Registration records for one hook, sorted the way WordPress runs them. */
    public function sortedHooks(string $hook): array {
        $entries = $this->hooks[$hook] ?? [];
        usort($entries, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq'];
        });
        return $entries;
    }

    /** Current time in unix seconds, honouring the fixed clock when set. */
    public function timestamp(): int {
        return $this->now > 0 ? $this->now : time();
    }
}

}

namespace {

// wpdb output constants. Declared here as well as in FakeWpdb.php so a suite
// that needs only the function stubs (no database) still gets ARRAY_A.
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// Path constants. Defined, never created: an offline suite that only reads
// paths must not leave directories behind in a parallel `make -j8` run.
//
// UNIQUE PER PROCESS, for the reason uploadBaseDir already is (:143-147) and
// one that is worse. "Never created" is this file's contract, not a property
// of the tree, and the cost of relying on it is not a stray directory -- it
// is a permanently red corpus. AdapterSources::plugin_source() decides
// whether to call get_option('active_plugins') on `is_dir($dir)`
// (agent/src/Adapter/AdapterSources.php:1629), so the moment anything creates
// <tmp>/wprism-root/wp-content/plugins, every later suite on that host that
// calls Policy::load() before installing its $wpdb fatals with "Call to a
// member function rows() on null". Observed exactly that way: green on a
// fresh checkout, red once a live-tier run had left `hello` and `newly`
// there, and red from then on.
//
// tools/offline.php hid it behind a per-worker TMPDIR; `make
// regress-offline-all` inherits the ambient one, so the canonical gate was
// the one that broke. A per-pid root cannot be inherited from an earlier run,
// which is the whole property -- a leftover tree under THIS pid's root is
// unreachable by any other run and therefore harmless.
//
// Nothing is registered to delete it. Every suite that mkdirs under
// WP_CONTENT_DIR / WP_PLUGIN_DIR today defines its own root first (four of
// them do not include this file at all), so a cleanup hook here would have
// nothing to collect -- while arming a recursive delete inside a file
// tools/adapter-kit.php ships to adapter authors, in processes where a caller
// has already defined ABSPATH as something real. Uniqueness is the fix;
// deletion was machinery for a leak that does not exist.
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/wprism-root-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', rtrim(ABSPATH, '/') . '/wp-content');
}
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!function_exists('wprism_wp_store')) {
    /** Shorthand accessor for the store the stubs below read. */
    function wprism_wp_store(): \WPrismTest\WpStore {
        return \WPrismTest\WpStore::instance();
    }
}

if (!class_exists('WP_Error', false)) {
    /**
     * Minimal WP_Error: enough for is_wp_error() plus the code/message
     * accessors the engine's error paths read. Not a faithful port -- WP's
     * real class carries per-code data and error chains that no offline suite
     * inspects.
     */
    class WP_Error {
        /** @var array<string,list<string>> */
        public array $errors = [];
        /** @var array<string,mixed> */
        public array $error_data = [];

        public function __construct(string|int $code = '', string $message = '', mixed $data = '') {
            if ($code !== '' && $code !== 0) {
                $this->errors[(string) $code][] = $message;
                if ($data !== '') {
                    $this->error_data[(string) $code] = $data;
                }
            }
        }

        public function get_error_code(): string {
            return (string) (array_key_first($this->errors) ?? '');
        }

        public function get_error_message(string|int $code = ''): string {
            $code = $code === '' ? $this->get_error_code() : (string) $code;
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data(string|int $code = ''): mixed {
            $code = $code === '' ? $this->get_error_code() : (string) $code;
            return $this->error_data[$code] ?? null;
        }

        /** @return list<string> */
        public function get_error_codes(): array {
            return array_map('strval', array_keys($this->errors));
        }

        public function has_errors(): bool {
            return $this->errors !== [];
        }
    }
}

if (!function_exists('is_wp_error')) {
    /** WordPress's own definition: an instanceof test, nothing more. */
    function is_wp_error(mixed $thing): bool {
        return $thing instanceof WP_Error;
    }
}

// --------------------------------------------------------------- options

if (!function_exists('get_option')) {
    /**
     * Reads the store. Returns $default when the option is absent -- note
     * that WordPress cannot distinguish "absent" from "stored false", and
     * neither does this stub, because the engine's option paths are written
     * against that ambiguity.
     */
    function get_option(string $option, mixed $default = false): mixed {
        $store = wprism_wp_store();
        return array_key_exists($option, $store->options) ? $store->options[$option] : $default;
    }
}

if (!function_exists('add_option')) {
    /** WordPress semantics: refuses (returns false) if the option exists. */
    function add_option(string $option, mixed $value = '', string $deprecated = '', string|bool $autoload = 'yes'): bool {
        $store = wprism_wp_store();
        if (array_key_exists($option, $store->options)) {
            return false;
        }
        $store->options[$option] = $value;
        $store->autoload[$option] = ($autoload === 'no' || $autoload === false) ? 'no' : 'yes';
        return true;
    }
}

if (!function_exists('update_option')) {
    /**
     * WordPress semantics, deliberately including the surprising part:
     * update_option() returns FALSE when the new value equals the stored one,
     * because WordPress skips the write. Code that treats a false return as
     * "the write failed" is buggy against real WordPress, so this stub must
     * not smooth it over.
     *
     * Autoload is only changed when explicitly passed, matching WP >= 4.2.
     */
    function update_option(string $option, mixed $value, string|bool|null $autoload = null): bool {
        $store = wprism_wp_store();
        $exists = array_key_exists($option, $store->options);
        if ($exists && $store->options[$option] === $value) {
            return false;
        }
        $store->options[$option] = $value;
        if ($autoload !== null) {
            $store->autoload[$option] = ($autoload === 'no' || $autoload === false) ? 'no' : 'yes';
        } elseif (!$exists) {
            $store->autoload[$option] = 'yes';
        }
        return true;
    }
}

if (!function_exists('delete_option')) {
    /** False when the option was not there, matching WordPress. */
    function delete_option(string $option): bool {
        $store = wprism_wp_store();
        if (!array_key_exists($option, $store->options)) {
            return false;
        }
        unset($store->options[$option], $store->autoload[$option]);
        return true;
    }
}

// --------------------------------------------------------------- uploads

if (!function_exists('wp_upload_dir')) {
    /**
     * basedir/baseurl come from the store; path/url add the month subdir the
     * same way WordPress does when uploads are organised by date, because
     * attachment paths in captured documents contain it.
     *
     * No directory is created and no 'error' is ever reported -- a suite that
     * needs bytes on disk calls WpStore::ensureUploadDir() itself.
     */
    function wp_upload_dir(?string $time = null, bool $create_dir = true, bool $refresh_cache = false): array {
        $store = wprism_wp_store();
        $subdir = '/' . gmdate('Y/m', $time !== null ? (int) strtotime($time) : $store->timestamp());
        return [
            'path' => $store->uploadBaseDir . $subdir,
            'url' => $store->uploadBaseUrl . $subdir,
            'subdir' => $subdir,
            'basedir' => $store->uploadBaseDir,
            'baseurl' => $store->uploadBaseUrl,
            'error' => false,
        ];
    }
}

// ------------------------------------------------------------ hooks

if (!function_exists('add_filter')) {
    /**
     * Registers into the shared hook table. Always returns true, as
     * WordPress does. Priority ties keep registration (FIFO) order, which is
     * the ordering guarantee the engine's boot sequence relies on.
     */
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        global $wp_filter;
        $gate = is_array($wp_filter ?? null) ? ($wp_filter[$hook_name] ?? null) : null;
        if (is_object($gate)
            && method_exists($gate, 'hook_name')
            && method_exists($gate, 'add_filter')) {
            $gate->add_filter($hook_name, $callback, $priority, $accepted_args);
            return true;
        }
        $store = wprism_wp_store();
        $store->hooks[$hook_name][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
            'seq' => $store->hookSeq++,
        ];
        if (isset($wp_filter) && is_array($wp_filter) && class_exists('WP_Hook')) {
            $hook = $wp_filter[$hook_name] ??= new \WP_Hook();
            if (is_object($hook) && property_exists($hook, 'callbacks')) {
                $hook->callbacks[$priority][] = [
                    'function' => $callback,
                    'accepted_args' => $accepted_args,
                ];
            }
        }
        return true;
    }
}

if (!function_exists('add_action')) {
    /** In WordPress this IS add_filter(); keep the aliasing visible. */
    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        return add_filter($hook_name, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('remove_filter')) {
    /**
     * Removes the first registration matching callback AND priority, exactly
     * like WordPress -- a callback registered at two priorities is only
     * detached from the one named here.
     */
    function remove_filter(string $hook_name, callable $callback, int $priority = 10): bool {
        global $wp_filter;
        $gate = is_array($wp_filter ?? null) ? ($wp_filter[$hook_name] ?? null) : null;
        if (is_object($gate)
            && method_exists($gate, 'hook_name')
            && method_exists($gate, 'remove_filter')) {
            return $gate->remove_filter($hook_name, $callback, $priority);
        }
        $store = wprism_wp_store();
        foreach ($store->hooks[$hook_name] ?? [] as $index => $entry) {
            if ($entry['priority'] === $priority && $entry['callback'] == $callback) {
                unset($store->hooks[$hook_name][$index]);
                $store->hooks[$hook_name] = array_values($store->hooks[$hook_name]);
                $hook = is_array($wp_filter ?? null) ? ($wp_filter[$hook_name] ?? null) : null;
                if (is_object($hook) && property_exists($hook, 'callbacks')) {
                    foreach ($hook->callbacks[$priority] ?? [] as $callbackIndex => $registered) {
                        if (($registered['function'] ?? null) == $callback) {
                            unset($hook->callbacks[$priority][$callbackIndex]);
                            $hook->callbacks[$priority] = array_values($hook->callbacks[$priority]);
                            if ($hook->callbacks[$priority] === []) unset($hook->callbacks[$priority]);
                            break;
                        }
                    }
                    if ($hook->callbacks === []) unset($wp_filter[$hook_name]);
                }
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('remove_action')) {
    function remove_action(string $hook_name, callable $callback, int $priority = 10): bool {
        return remove_filter($hook_name, $callback, $priority);
    }
}

if (!function_exists('remove_all_filters')) {
    function remove_all_filters(string $hook_name, int|false $priority = false): bool {
        global $wp_filter;
        $gate = is_array($wp_filter ?? null) ? ($wp_filter[$hook_name] ?? null) : null;
        if (is_object($gate)
            && method_exists($gate, 'hook_name')
            && method_exists($gate, 'remove_all_filters')) {
            $gate->remove_all_filters($priority);
            return true;
        }
        $store = wprism_wp_store();
        if ($priority === false) {
            unset($store->hooks[$hook_name]);
            if (is_array($wp_filter ?? null)) {
                unset($wp_filter[$hook_name]);
            }
            return true;
        }
        $store->hooks[$hook_name] = array_values(array_filter(
            $store->hooks[$hook_name] ?? [],
            static fn(array $entry): bool => $entry['priority'] !== $priority
        ));
        if ($store->hooks[$hook_name] === []) {
            unset($store->hooks[$hook_name]);
        }
        if (is_array($wp_filter ?? null)
            && is_object($wp_filter[$hook_name] ?? null)
            && method_exists($wp_filter[$hook_name], 'remove_all_filters')) {
            $wp_filter[$hook_name]->remove_all_filters($priority);
        }
        return true;
    }
}

if (!function_exists('remove_all_actions')) {
    function remove_all_actions(string $hook_name, int|false $priority = false): bool {
        return remove_all_filters($hook_name, $priority);
    }
}

if (!function_exists('has_filter')) {
    /**
     * WordPress returns the priority (an int, possibly 0) when a specific
     * callback is registered, false when it is not, and a bool for the
     * "anything at all on this hook" form. Callers use !== false, so the
     * int-0 case must stay an int.
     */
    function has_filter(string $hook_name, callable|false $callback = false): bool|int {
        global $wp_filter;
        $gate = is_array($wp_filter ?? null) ? ($wp_filter[$hook_name] ?? null) : null;
        if (is_object($gate)
            && method_exists($gate, 'hook_name')
            && method_exists($gate, 'has_filter')) {
            return $gate->has_filter($hook_name, $callback);
        }
        $store = wprism_wp_store();
        $entries = $store->hooks[$hook_name] ?? [];
        if ($callback === false) {
            return $entries !== [];
        }
        foreach ($entries as $entry) {
            if ($entry['callback'] == $callback) {
                return $entry['priority'];
            }
        }
        return false;
    }
}

if (!function_exists('has_action')) {
    function has_action(string $hook_name, callable|false $callback = false): bool|int {
        return has_filter($hook_name, $callback);
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Functional: every registered callback runs, in priority order then
     * registration order, each receiving the PREVIOUS callback's return value
     * as $value. accepted_args truncates the extra arguments, as WordPress
     * does -- a filter declared with one parameter must not receive three.
     */
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed {
        global $wp_filter;
        $allGate = is_array($wp_filter ?? null) ? ($wp_filter['all'] ?? null) : null;
        if (is_object($allGate)
            && method_exists($allGate, 'hook_name')
            && method_exists($allGate, 'do_all_hook')) {
            $allArgs = array_merge([$hook_name, $value], $args);
            $allGate->do_all_hook($allArgs);
        }
        $gate = is_array($wp_filter ?? null) ? ($wp_filter[$hook_name] ?? null) : null;
        if (is_object($gate)
            && method_exists($gate, 'hook_name')
            && method_exists($gate, 'apply_filters')) {
            return $gate->apply_filters($value, array_merge([$value], $args));
        }
        $store = wprism_wp_store();
        foreach ($store->sortedHooks($hook_name) as $entry) {
            $callArgs = array_slice(array_merge([$value], $args), 0, max(1, $entry['accepted_args']));
            $value = ($entry['callback'])(...$callArgs);
        }
        return $value;
    }
}

if (!function_exists('do_action')) {
    /**
     * Runs the callbacks for their side effects and records the call in
     * $store->firedActions so a suite can assert an action fired without
     * registering a spy of its own. Return values are discarded, as in
     * WordPress.
     */
    function do_action(string $hook_name, mixed ...$args): void {
        $store = wprism_wp_store();
        $store->firedActions[] = ['hook' => $hook_name, 'args' => $args];
        foreach ($store->sortedHooks($hook_name) as $entry) {
            $callArgs = array_slice($args, 0, $entry['accepted_args']);
            ($entry['callback'])(...$callArgs);
        }
    }
}

// --------------------------------------------------------------- cache

if (!function_exists('wp_cache_get')) {
    /**
     * $found is set by reference so the caller can distinguish a cached false
     * from a miss -- the same distinction the real object cache offers and
     * the engine's option cache invalidation depends on.
     */
    function wp_cache_get(string|int $key, string $group = '', bool $force = false, mixed &$found = null): mixed {
        $store = wprism_wp_store();
        $group = $group === '' ? 'default' : $group;
        $store->cacheEvents[] = ['op' => 'get', 'group' => $group, 'key' => (string) $key];
        $found = isset($store->cache[$group]) && array_key_exists((string) $key, $store->cache[$group]);
        return $found ? $store->cache[$group][(string) $key] : false;
    }
}

if (!function_exists('wp_cache_set')) {
    /** $expire is accepted and ignored: nothing in the offline suites ages out. */
    function wp_cache_set(string|int $key, mixed $data, string $group = '', int $expire = 0): bool {
        $store = wprism_wp_store();
        $group = $group === '' ? 'default' : $group;
        $store->cacheEvents[] = ['op' => 'set', 'group' => $group, 'key' => (string) $key];
        $store->cache[$group][(string) $key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    /** False on a miss, matching WordPress, so "did we invalidate?" is assertable. */
    function wp_cache_delete(string|int $key, string $group = ''): bool {
        $store = wprism_wp_store();
        $group = $group === '' ? 'default' : $group;
        $store->cacheEvents[] = ['op' => 'delete', 'group' => $group, 'key' => (string) $key];
        if (!isset($store->cache[$group]) || !array_key_exists((string) $key, $store->cache[$group])) {
            return false;
        }
        unset($store->cache[$group][(string) $key]);
        return true;
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): mixed {
        $store = wprism_wp_store();
        $store->cacheEvents[] = ['op' => 'flush', 'group' => '', 'key' => ''];
        $results = $GLOBALS['wprism_wp_cache_flush_results'] ?? null;
        if (is_array($results) && $results !== []) {
            $result = array_shift($results);
            $GLOBALS['wprism_wp_cache_flush_results'] = $results;
            if ($result instanceof \Throwable) {
                throw $result;
            }
            if ($result !== true) {
                return $result;
            }
        }
        $store->cache = [];
        return true;
    }
}

// ------------------------------------------------------- serialization

require_once __DIR__ . '/wp_serialization_stubs.php';

// ------------------------------------------------------------ sanitizing

if (!function_exists('esc_sql')) {
    /**
     * wpdb::_real_escape() over a scalar or (recursively) an array, which is
     * addslashes()-equivalent for the ASCII/UTF-8 payloads these suites use.
     * NOT a substitute for prepare(): FakeWpdb::prepare() is.
     */
    function esc_sql(array|string $data): array|string {
        if (is_array($data)) {
            return array_map('esc_sql', $data);
        }
        return addslashes($data);
    }
}

if (!function_exists('sanitize_key')) {
    /** Lowercase, then keep only a-z 0-9 _ - . Exactly WordPress's filter. */
    function sanitize_key(string $key): string {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('sanitize_title')) {
    /**
     * sanitize_title_with_dashes() without the accent-folding table: lower,
     * strip tags and entities, non-alphanumerics to dashes, collapse and
     * trim. Sufficient for slug assertions on ASCII fixtures; a suite that
     * needs accent folding should assert on real captured bytes instead.
     */
    function sanitize_title(string $title, string $fallback_title = '', string $context = 'save'): string {
        $slug = strtolower(strip_tags($title));
        $slug = (string) preg_replace('/&.+?;/', '', $slug);
        $slug = str_replace(['.', '/', '_'], '-', $slug);
        $slug = (string) preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = (string) preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug === '' ? $fallback_title : $slug;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * WordPress's wrapper returns false on failure rather than throwing, and
     * does not add JSON_PRETTY_PRINT or escape slashes differently. Keep both
     * properties: canonical export bytes are asserted elsewhere.
     */
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false {
        return json_encode($data, $options, $depth);
    }
}

// ------------------------------------------------------------- misc WP API

if (!function_exists('get_post_types')) {
    /**
     * Filters the store's registered post types by the given property
     * constraints. $operator 'and' (default) requires every constraint;
     * 'or' requires one; 'not' excludes matches -- WordPress's own contract.
     *
     * @return array<string,string>|array<string,object>
     */
    function get_post_types(array|string $args = [], string $output = 'names', string $operator = 'and'): array {
        $store = wprism_wp_store();
        $args = is_string($args) ? [] : $args;
        $matched = [];
        foreach ($store->postTypes as $name => $object) {
            $hits = 0;
            foreach ($args as $property => $expected) {
                if (($object->$property ?? null) === $expected) {
                    $hits++;
                }
            }
            $isMatch = match ($operator) {
                'or' => $args === [] || $hits > 0,
                'not' => $hits === 0,
                default => $hits === count($args),
            };
            if ($isMatch) {
                $matched[$name] = $output === 'objects' ? $object : $name;
            }
        }
        return $matched;
    }
}

if (!function_exists('get_bloginfo')) {
    /** 'version' comes from the store's $version; other keys from $blogInfo. */
    function get_bloginfo(string $show = '', string $filter = 'raw'): string {
        $store = wprism_wp_store();
        if ($show === 'version') {
            return $store->version;
        }
        return (string) ($store->blogInfo[$show] ?? '');
    }
}

if (!function_exists('wp_parse_args')) {
    /**
     * Accepts an array, an object, or a query string, as WordPress does --
     * the string form is what makes this worth stubbing rather than inlining
     * array_merge() at call sites.
     */
    function wp_parse_args(mixed $args, array $defaults = []): array {
        if (is_object($args)) {
            $parsed = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed = $args;
        } else {
            parse_str((string) $args, $parsed);
        }
        return array_merge($defaults, $parsed);
    }
}

if (!function_exists('absint')) {
    /** abs(intval()) -- the coercion, not a validation. */
    function absint(mixed $maybeint): int {
        return abs((int) $maybeint);
    }
}

if (!function_exists('trailingslashit')) {
    /** Exactly one trailing slash, whatever the input had. */
    function trailingslashit(string $value): string {
        return untrailingslashit($value) . '/';
    }
}

if (!function_exists('untrailingslashit')) {
    /** Strips every trailing forward AND back slash, as WordPress does. */
    function untrailingslashit(string $value): string {
        return rtrim($value, '/\\');
    }
}

if (!function_exists('wp_normalize_path')) {
    /**
     * Backslashes to forward slashes, duplicate slashes collapsed (but a
     * leading '//' UNC prefix preserved), Windows drive letter upcased --
     * WordPress's own implementation, which the engine's path comparisons
     * assume when normalizing captured attachment paths.
     */
    function wp_normalize_path(string $path): string {
        $wrapper = '';
        if (preg_match('|^([a-zA-Z0-9+.-]+://)(.*)|', $path, $matches) === 1) {
            $wrapper = $matches[1];
            $path = $matches[2];
        }
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('|(?<=.)/+|', '/', $path);
        if (substr($path, 1, 1) === ':') {
            $path = ucfirst($path);
        }
        return $wrapper . $path;
    }
}

if (!function_exists('is_admin')) {
    /** WordPress request context is independent of the current user's role. */
    function is_admin(): bool {
        if (isset($GLOBALS['current_screen'])) {
            return $GLOBALS['current_screen']->in_admin();
        }
        return defined('WP_ADMIN') && WP_ADMIN;
    }
}

if (!function_exists('is_multisite')) {
    /**
     * Always false. WPrism refuses multisite outright (see the multisite refusal
     * regression), so an offline suite that observes true here would be
     * asserting against a configuration the product does not support.
     */
    function is_multisite(): bool {
        return false;
    }
}

if (!function_exists('current_time')) {
    /**
     * Honours the store's fixed clock so timestamped assertions are
     * deterministic under a parallel gate. 'mysql' returns the SQL datetime
     * string, 'timestamp'/'U' an int; $gmt is accepted and ignored because
     * the fixture clock is UTC by construction.
     */
    function current_time(string $type, int|bool $gmt = 0): string|int {
        $now = wprism_wp_store()->timestamp();
        return match ($type) {
            'mysql' => gmdate('Y-m-d H:i:s', $now),
            'timestamp', 'U' => $now,
            default => gmdate($type, $now),
        };
    }
}

}
