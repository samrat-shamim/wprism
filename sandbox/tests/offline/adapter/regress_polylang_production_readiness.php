<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../lib/check.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PlainData.php';

    $GLOBALS['pll_options'] = [];
    $GLOBALS['pll_menus'] = [];
    $GLOBALS['pll_term_languages'] = [];
    $GLOBALS['pll_term_translations'] = [];
    $GLOBALS['pll_command_result'] = null;
    $GLOBALS['pll_native_command_result'] = null;
    $GLOBALS['pll_command_throw'] = null;
    $GLOBALS['pll_after_command'] = null;
    $GLOBALS['pll_command_calls'] = [];
    $GLOBALS['pll_child_calls'] = [];
    $GLOBALS['pll_child_throw'] = null;
    $GLOBALS['pll_child_result'] = null;
    $GLOBALS['pll_cache_deletes'] = [];
    $GLOBALS['pll_cache_delete_result'] = true;
    $GLOBALS['pll_option_cache'] = [];
    $GLOBALS['pll_retain_option_cache'] = [];
    $GLOBALS['pll_option_filter'] = null;
    $GLOBALS['pll_retain_theme_mod'] = false;
    $GLOBALS['pll_runtime'] = null;
    $GLOBALS['pll_removed_actions'] = [];
    $GLOBALS['pll_add_filter_attempts'] = [];
    $GLOBALS['pll_add_filter_fail_once'] = [];
    $GLOBALS['pll_remove_filter_attempts'] = [];
    $GLOBALS['pll_remove_filter_fail_once'] = [];
    $GLOBALS['pll_term_meta'] = [];
    $GLOBALS['pll_cleaned_terms'] = [];
    $GLOBALS['wp_filter'] = [];

    final class PllHookRegistry {
        /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
        public array $callbacks = [];
    }

    function pll_hook_id(callable $callback): string {
        if ($callback instanceof \Closure) {
            return spl_object_hash($callback);
        }
        if (is_array($callback)) {
            $owner = is_object($callback[0]) ? spl_object_hash($callback[0]) : (string) $callback[0];
            return $owner . '::' . (string) $callback[1];
        }
        return is_object($callback) ? spl_object_hash($callback) : (string) $callback;
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        $GLOBALS['pll_add_filter_attempts'][] = $hook;
        if (in_array($hook, $GLOBALS['pll_add_filter_fail_once'], true)) {
            $GLOBALS['pll_add_filter_fail_once'] = array_values(array_filter(
                $GLOBALS['pll_add_filter_fail_once'],
                static fn(string $candidate): bool => $candidate !== $hook
            ));
            return false;
        }
        $registry = $GLOBALS['wp_filter'][$hook] ??= new PllHookRegistry();
        $registry->callbacks[$priority][pll_hook_id($callback)] = [
            'function' => $callback,
            'accepted_args' => $acceptedArgs,
        ];
        ksort($registry->callbacks, SORT_NUMERIC);
        return true;
    }

    function remove_filter(string $hook, callable $callback, int $priority = 10): bool {
        $GLOBALS['pll_remove_filter_attempts'][] = $hook;
        $registry = $GLOBALS['wp_filter'][$hook] ?? null;
        $id = pll_hook_id($callback);
        if (!$registry instanceof PllHookRegistry || !isset($registry->callbacks[$priority][$id])) {
            return false;
        }
        unset($registry->callbacks[$priority][$id]);
        if ($registry->callbacks[$priority] === []) unset($registry->callbacks[$priority]);
        if ($registry->callbacks === []) unset($GLOBALS['wp_filter'][$hook]);
        if (in_array($hook, $GLOBALS['pll_remove_filter_fail_once'], true)) {
            $GLOBALS['pll_remove_filter_fail_once'] = array_values(array_filter(
                $GLOBALS['pll_remove_filter_fail_once'],
                static fn(string $candidate): bool => $candidate !== $hook
            ));
            return false;
        }
        return true;
    }

    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        return add_filter($hook, $callback, $priority, $acceptedArgs);
    }

    function remove_action(string $hook, callable $callback, int $priority = 10): bool {
        $GLOBALS['pll_removed_actions'][] = [$hook, $callback, $priority];
        return remove_filter($hook, $callback, $priority);
    }

    function has_filter(string $hook, callable|false $callback = false): bool|int {
        $registry = $GLOBALS['wp_filter'][$hook] ?? null;
        if (!$registry instanceof PllHookRegistry) return false;
        foreach ($registry->callbacks as $priority => $entries) {
            if ($callback === false || isset($entries[pll_hook_id($callback)])) return $priority;
        }
        return false;
    }

    function has_action(string $hook, callable|false $callback = false): bool|int {
        return has_filter($hook, $callback);
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        $registry = $GLOBALS['wp_filter'][$hook] ?? null;
        if (!$registry instanceof PllHookRegistry) return $value;
        foreach ($registry->callbacks as $entries) {
            foreach ($entries as $entry) {
                $all = array_merge([$value], $args);
                $value = ($entry['function'])(...array_slice($all, 0, $entry['accepted_args']));
            }
        }
        return $value;
    }

    function do_action(string $hook, mixed ...$args): void {
        apply_filters($hook, null, ...$args);
    }

    function get_option(string $name, mixed $default = false): mixed {
        $pre = apply_filters("pre_option_$name", false, $name, $default);
        $pre = apply_filters('pre_option', $pre, $name, $default);
        if ($pre !== false) {
            $value = $pre;
        } elseif (array_key_exists($name, $GLOBALS['pll_option_cache'])) {
            $value = $GLOBALS['pll_option_cache'][$name];
        } else {
            $value = array_key_exists($name, $GLOBALS['pll_options']) ? $GLOBALS['pll_options'][$name] : $default;
        }
        $value = apply_filters("option_$name", $value, $name);
        return is_callable($GLOBALS['pll_option_filter'])
            ? ($GLOBALS['pll_option_filter'])($name, $value)
            : $value;
    }

    function get_theme_mod(string $name, mixed $default = false): mixed {
        $stylesheet = get_option('stylesheet');
        $mods = $GLOBALS['pll_options']['theme_mods_' . $stylesheet] ?? [];
        return is_array($mods) ? ($mods[$name] ?? $default) : $default;
    }

    function update_option(string $name, mixed $value): bool {
        $old = get_option($name, []);
        $value = apply_filters("pre_update_option_$name", $value, $old, $name);
        $value = apply_filters('pre_update_option', $value, $name, $old);
        if ($value === $old) return false;
        $GLOBALS['pll_options'][$name] = $value;
        unset($GLOBALS['pll_option_cache'][$name]);
        return true;
    }

    function set_theme_mod(string $name, mixed $value): void {
        if ($GLOBALS['pll_retain_theme_mod']) {
            return;
        }
        $stylesheet = get_option('stylesheet');
        $key = 'theme_mods_' . $stylesheet;
        $mods = $GLOBALS['pll_options'][$key] ?? [];
        $mods = is_array($mods) ? $mods : [];
        $mods[$name] = $value;
        $GLOBALS['pll_options'][$key] = $mods;
    }

    function wp_cache_delete(string $key, string $group = ''): bool {
        $GLOBALS['pll_cache_deletes'][] = [$key, $group];
        if ($group === 'options') {
            if (!empty($GLOBALS['pll_retain_option_cache'][$key])) {
                // Hostile retained-cache fixture: the product path must prove
                // a fresh read, not trust the deletion call's return shape.
            } elseif ($key === 'alloptions') {
                $GLOBALS['pll_option_cache'] = [];
            } else {
                unset($GLOBALS['pll_option_cache'][$key]);
            }
        }
        return $GLOBALS['pll_cache_delete_result'];
    }

    function wp_kses(string $value, string|array $context): string {
        $sanitized = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $value);
        $sanitized = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $sanitized);
        return (string) preg_replace('/(?:javascript|data)\s*:/i', '', (string) $sanitized);
    }

    function sanitize_text_field(string $value): string {
        return trim((string) preg_replace('/[\r\n\t<>]/', '', $value));
    }

    function PLL(): mixed {
        return $GLOBALS['pll_runtime'];
    }

    function get_term_meta(int $termId, string $key, bool $single = false): mixed {
        return $GLOBALS['pll_term_meta'][$termId][$key] ?? '';
    }

    function clean_term_cache(int|array $termIds, string $taxonomy = ''): void {
        foreach ((array) $termIds as $termId) {
            $GLOBALS['pll_cleaned_terms'][] = [(int) $termId, $taxonomy];
        }
    }

    final class PllNativeResult {
        /** @param list<string> $codes */
        public function __construct(private array $codes = []) {}
        public function get_error_codes(): array { return $this->codes; }
    }

    final class PllNativeOptions {
        public const ORDER = [
            'force_lang', 'domains', 'hide_default', 'rewrite', 'redirect_lang', 'browser',
            'media_support', 'post_types', 'taxonomies', 'sync', 'default_lang', 'nav_menus',
            'first_activation', 'previous_version', 'version',
        ];
        public const DEFAULTS = [
            'force_lang' => 1, 'domains' => [], 'hide_default' => true, 'rewrite' => true,
            'redirect_lang' => false, 'browser' => false, 'media_support' => true,
            'post_types' => [], 'taxonomies' => [], 'sync' => [], 'default_lang' => '',
            'nav_menus' => [], 'first_activation' => false, 'previous_version' => '', 'version' => '3.8.6',
        ];
        public array $values;
        public array $setOrder = [];
        public ?string $warnOnceAt = null;
        public ?string $throwOnceAt = null;
        public ?\Throwable $saveThrowOnce = null;
        public string $warningCode = 'pll_native_warning';
        public bool $modified = false;
        public int $saveCalls = 0;
        public int $saveAllCalls = 0;
        public $afterSave = null;

        public function __construct(array $values) { $this->values = $values; }
        public function get(string $key): mixed { return $this->values[$key] ?? null; }
        public function get_all(): array { return $this->values; }
        public function get_schema(): array {
            $properties = [];
            foreach (self::ORDER as $key) {
                $properties[$key] = ['default' => self::DEFAULTS[$key], 'type' => 'fixture'];
            }
            return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        }
        public function merge(array $values): PllNativeResult {
            $codes = [];
            foreach (self::ORDER as $key) {
                if (!array_key_exists($key, $values)) {
                    continue;
                }
                $result = $this->set($key, $values[$key]);
                $codes = array_merge($codes, $result->get_error_codes());
            }
            return new PllNativeResult($codes);
        }
        public function set(string $key, mixed $value): PllNativeResult {
            $this->setOrder[] = $key;
            if ($this->throwOnceAt === $key) {
                $this->throwOnceAt = null;
                throw new \RuntimeException('fixture native setter failed');
            }
            if (!array_key_exists($key, $this->values) || $this->values[$key] !== $value) {
                $this->modified = true;
            }
            $this->values[$key] = $value;
            if ($this->warnOnceAt === $key) {
                $this->warnOnceAt = null;
                return new PllNativeResult([$this->warningCode]);
            }
            return new PllNativeResult();
        }
        public function reset(string $key): mixed {
            if (!array_key_exists($key, $this->values) || $this->values[$key] !== self::DEFAULTS[$key]) {
                $this->modified = true;
            }
            return $this->values[$key] = self::DEFAULTS[$key];
        }
        public function protect_wp_option_storage(mixed $value): array {
            return $value instanceof self ? $value->get_all() : (array) $value;
        }
        public function save(): bool {
            ++$this->saveCalls;
            if ($this->saveThrowOnce !== null) {
                $failure = $this->saveThrowOnce;
                $this->saveThrowOnce = null;
                throw $failure;
            }
            if (!$this->modified) return false;
            $this->modified = false;
            $result = update_option('polylang', array_merge((array) get_option('polylang', []), $this->values));
            if (is_callable($this->afterSave)) {
                ($this->afterSave)();
            }
            return $result;
        }
        public function save_all(): void {
            ++$this->saveAllCalls;
            $this->save();
        }
    }

    final class PllNativeModel {
        public int $cachePurges = 0;
        /** @param list<object> $languages */
        public function __construct(public array $languages = []) {}
        public function clean_languages_cache(): void { ++$this->cachePurges; }
        public function get_languages_list(): array { return $this->languages; }
        public function has_languages(): bool { return $this->languages !== []; }
    }

    function wp_get_nav_menu_object(int $menuId): object|false {
        return isset($GLOBALS['pll_menus'][$menuId]) ? (object) ['term_id' => $menuId] : false;
    }

    function pll_get_term_language(int $termId, string $field = 'slug'): string|false {
        return $GLOBALS['pll_term_languages'][$termId] ?? false;
    }

    function pll_get_term(int $termId, string $language): int|false {
        return $GLOBALS['pll_term_translations'][$termId][$language] ?? false;
    }

    final class WP_CLI {
        public static function runcommand(string $command, array $options): mixed {
            $GLOBALS['pll_command_calls'][] = [$command, $options];
            if ($GLOBALS['pll_command_throw'] instanceof \Throwable) {
                throw $GLOBALS['pll_command_throw'];
            }
            if (is_callable($GLOBALS['pll_after_command'])) {
                ($GLOBALS['pll_after_command'])();
            }
            return str_starts_with($command, 'eval ')
                ? $GLOBALS['pll_native_command_result']
                : $GLOBALS['pll_command_result'];
        }
    }
}

namespace Duo {
    final class Policy {}

    /**
     * The generic transport owns process groups, concurrent pipe draining and
     * limits. This product fixture records only the fixed caller contract so
     * it can refuse a widened timeout/output boundary without reimplementing
     * a second process fake (the generic transport suite covers descendants).
     */
    final class WpCliChildProcess {
        /** @return array{return_code:int,stdout:string,stderr:string} */
        public static function capture(
            string $command,
            int $timeoutSeconds,
            int $stdoutLimit,
            int $stderrLimit
        ): array {
            $GLOBALS['pll_child_calls'][] = [$command, $timeoutSeconds, $stdoutLimit, $stderrLimit];
            if ($GLOBALS['pll_child_throw'] instanceof \Throwable) {
                throw $GLOBALS['pll_child_throw'];
            }
            return $GLOBALS['pll_child_result'];
        }
    }

    final class Providers {
        public const SCOPED_OPERATION_FORMAT = 'duo-scoped-effect-operation/v1';
    }

    final class Canon {
        public static function parse_post_file(string $content): array {
            throw new \RuntimeException('fixture supplies parsed repository data');
        }
    }
}

namespace {
    require_once dirname(__DIR__, 4) . '/manifests/providers/polylang-nav-menus.php';
    require_once dirname(__DIR__, 4) . '/manifests/interpreters/polylang.php';

    use Duo\Interpreters\Polylang;
    use Duo\Providers\PolylangNavMenus;

    $contextFixture = dirname(__DIR__, 2) . '/support/polylang_media_support_context.php';
    $contextProcess = proc_open(
        [PHP_BINARY, $contextFixture],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $contextPipes
    );
    $contextOut = is_resource($contextProcess) ? stream_get_contents($contextPipes[1]) : '';
    $contextErr = is_resource($contextProcess) ? stream_get_contents($contextPipes[2]) : '';
    if (is_resource($contextProcess)) {
        fclose($contextPipes[1]);
        fclose($contextPipes[2]);
        $contextExit = proc_close($contextProcess);
    } else {
        $contextExit = 1;
    }
    $context = json_decode($contextOut, true);
    duo_check(
        $contextExit === 0 && $contextErr === '' && is_array($context),
        'real compiled-option taxonomy context fixture executes without WordPress or diagnostics'
    );
    duo_check_same(
        ['language', 'post_translations'],
        $context['enabled']['by_post_type']['attachment'] ?? null,
        'compiled media_support=1 owns attachment relationships despite a process-start registry frozen at disabled'
    );
    duo_check_same(
        ['language', 'post_translations'],
        $context['disabled']['by_post_type']['book'] ?? null,
        'compiled array-valued custom post types retain the original additive ownership contract'
    );
    duo_check(
        !isset($context['disabled']['by_post_type']['attachment'])
            && !isset($context['malformed']['by_post_type']['attachment'])
            && ($context['enabled']['warnings'] ?? null) === [],
        'false and stringly malformed media gates never gain attachment mutation authority'
    );

    $sidebarFixture = dirname(__DIR__, 2) . '/support/polylang_sidebar_uninstall_context.php';
    $sidebarProcess = proc_open(
        [PHP_BINARY, $sidebarFixture],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $sidebarPipes
    );
    $sidebarOut = is_resource($sidebarProcess) ? stream_get_contents($sidebarPipes[1]) : '';
    $sidebarErr = is_resource($sidebarProcess) ? stream_get_contents($sidebarPipes[2]) : '';
    if (is_resource($sidebarProcess)) {
        fclose($sidebarPipes[1]);
        fclose($sidebarPipes[2]);
        $sidebarExit = proc_close($sidebarProcess);
    } else {
        $sidebarExit = 1;
    }
    $sidebar = json_decode($sidebarOut, true);
    duo_check(
        $sidebarExit === 0 && $sidebarErr === '' && is_array($sidebar),
        'real SidebarState complete-uninstall fixture executes through the shared row-backed database'
    );
    $recoveredWidgets = $sidebar['recovered_widgets'] ?? null;
    duo_check(
        is_array($recoveredWidgets) && count($recoveredWidgets) === 2
            && array_reduce($recoveredWidgets, static fn(bool $valid, mixed $widget): bool => $valid
                && is_array($widget)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) ($widget['uuid'] ?? '')) === 1
                && ($widget['type'] ?? null) === 'polylang'
                && ($widget['settings'] ?? null) === ['_duo_unmanaged' => true], true),
        'compiled file-owned sidebar retains every Polylang contentless uninstall assignment as target-only deletion evidence'
    );
    duo_check(
        count($sidebar['recovery_warnings'] ?? []) === 2
            && str_contains((string) ($sidebar['recovery_warnings'][0] ?? ''), 'target-only deletion evidence'),
        'each native residue assignment is named and bounded by complete repository ownership'
    );
    duo_check(
        str_contains((string) ($sidebar['capture_refusal'] ?? ''), 'polylang-1 is absent')
            && str_contains((string) ($sidebar['unowned_refusal'] ?? ''), 'polylang-1 is absent'),
        'ordinary capture and forged or unowned sidebar recovery remain loudly blocked'
    );
    duo_check_same(
        true,
        $sidebar['orphan_identity_ignored'] ?? null,
        'raw-SQL complete uninstall orphan metadata is not fabricated into a live identity collision'
    );
    duo_check(
        str_contains((string) ($sidebar['live_duplicate_refusal'] ?? ''), 'term:19, term:20'),
        'two attached replacement terms with one UUID still refuse as a real live collision'
    );

    $termGroupFixture = dirname(__DIR__, 2) . '/support/polylang_term_group_context.php';
    $termGroupProcess = proc_open(
        [PHP_BINARY, $termGroupFixture],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $termGroupPipes
    );
    $termGroupOut = is_resource($termGroupProcess) ? stream_get_contents($termGroupPipes[1]) : '';
    $termGroupErr = is_resource($termGroupProcess) ? stream_get_contents($termGroupPipes[2]) : '';
    if (is_resource($termGroupProcess)) {
        fclose($termGroupPipes[1]);
        fclose($termGroupPipes[2]);
        $termGroupExit = proc_close($termGroupProcess);
    } else {
        $termGroupExit = 1;
    }
    $termGroup = json_decode($termGroupOut, true);
    duo_check(
        $termGroupExit === 0 && $termGroupErr === '' && is_array($termGroup),
        'real Polylang term-order fixture executes capture, schema and apply through the shared database'
    );
    duo_check_same(2, $termGroup['captured_language_term_group'] ?? null, 'language capture preserves native term_group order');
    duo_check_same(2, $termGroup['string_term_group'] ?? null, 'canonical decimal database term_group normalizes without widening grammar');
    duo_check(
        count($termGroup['term_group_refusals'] ?? []) === 11
            && count(array_filter(
                $termGroup['term_group_refusals'] ?? [],
                static fn(mixed $message): bool => is_string($message)
                    && str_contains($message, 'malformed or out-of-range database value')
            )) === 11,
        'negative, overflow, float, scientific, junk, leading-zero and nonscalar term_group values refuse before publication'
    );
    duo_check_same(false, $termGroup['captured_category_has_term_group'] ?? null, 'ordinary taxonomy bytes remain unchanged without exact opt-in');
    duo_check_same(2, $termGroup['materialized_term_group'] ?? null, 'term materialization restores native language order');
    duo_check(
        ($termGroup['language_description'] ?? null) === ($termGroup['materialized_description'] ?? null)
            && str_contains((string) ($termGroup['language_description'] ?? ''), 's:3:"rtl";i:1;'),
        'real capture/apply preserves legacy integer RTL metadata after schema validation'
    );
    duo_check(
        str_contains((string) ($termGroup['malformed_description_refusal'] ?? ''), 'canonical PHP-serialized plain data'),
        'real capture path refuses malformed language metadata before canonical publication'
    );
    duo_check(
        ($termGroup['valid_schema_findings'] ?? null) === 0
            && array_column($termGroup['schema_findings'] ?? [], 'locator') === ['term_group', 'term_group', 'term_group'],
        'missing, stringly and undeclared term_group repository values all refuse at schema validation'
    );
    duo_check(
        str_contains((string) ($termGroup['invalid_exact'] ?? ''), "must be the literal 'authored'")
            && str_contains((string) ($termGroup['invalid_pattern'] ?? ''), 'requires an exact taxonomy declaration'),
        'manifest grammar rejects alternate classes and dynamic term-order authority'
    );

    function pll_reset(): PolylangNavMenus {
        $GLOBALS['pll_options'] = [
            'polylang' => [
                'default_lang' => 'en',
                'nav_menus' => [
                    'twentytwentyone' => [
                        'primary' => ['en' => 11, 'fr' => 22],
                        'footer' => ['en' => 0, 'fr' => 22],
                    ],
                ],
            ],
            'stylesheet' => 'twentytwentyone',
            'permalink_structure' => '/%postname%/',
            'default_category' => 101,
            'rewrite_rules' => ['^stale/?$' => 'index.php?stale=1'],
            'theme_mods_twentytwentyone' => [
                'custom_target_neighbor' => 'preserve-me',
                'nav_menu_locations' => ['primary' => 22, 'footer' => 22],
            ],
        ];
        $GLOBALS['pll_menus'] = [11 => true, 22 => true];
        $GLOBALS['pll_term_languages'] = [101 => 'fr', 102 => 'en'];
        $GLOBALS['pll_term_translations'] = [
            101 => ['en' => 102, 'fr' => 101],
            102 => ['en' => 102, 'fr' => 101],
        ];
        $GLOBALS['pll_command_result'] = (object) [
            'return_code' => 0,
            'stdout' => "Success: Rewrite rules flushed.\n",
            'stderr' => '',
        ];
        $GLOBALS['pll_command_throw'] = null;
        $nativeProjection = ['catalogs' => []];
        $GLOBALS['pll_native_command_result'] = (object) [
            'return_code' => 0,
            'stdout' => 'DUO_PLL_NATIVE:' . base64_encode((string) json_encode($nativeProjection)) . "\n",
            'stderr' => '',
        ];
        $GLOBALS['pll_child_result'] = [
            'return_code' => 0,
            'stdout' => 'DUO_PLL_NATIVE:' . base64_encode((string) json_encode($nativeProjection)) . "\n",
            'stderr' => '',
        ];
        $GLOBALS['pll_child_throw'] = null;
        $GLOBALS['pll_after_command'] = static function (): void {
            $GLOBALS['pll_options']['rewrite_rules'] = [
                '^fr/?$' => 'index.php?lang=fr',
                '^en/?$' => 'index.php?lang=en',
            ];
        };
        $GLOBALS['pll_command_calls'] = [];
        $GLOBALS['pll_child_calls'] = [];
        $GLOBALS['pll_cache_deletes'] = [];
        $GLOBALS['pll_option_cache'] = [];
        $GLOBALS['pll_retain_theme_mod'] = false;
        $GLOBALS['pll_term_meta'] = [];
        $GLOBALS['pll_cleaned_terms'] = [];
        $GLOBALS['pll_runtime'] = (object) ['model' => new PllNativeModel()];
        return new PolylangNavMenus(new \Duo\Policy());
    }

    function pll_operation(): array {
        return [
            'format' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
            'authority_hash' => str_repeat('a', 64),
            'lease_session_id' => 'polylang-fixture-session',
            'operation_id' => 'polylang-fixture-operation',
            'input_hash' => str_repeat('b', 64),
            'effect_hash' => str_repeat('c', 64),
        ];
    }

    $provider = pll_reset();
    duo_check_same(
        ['id' => 'polylang-nav-menus', 'plugin' => 'polylang/polylang.php', 'version' => '2.0.0'],
        $provider->identity(),
        'Polylang provider identity makes the full derived-state contract fleet-visible'
    );
    duo_check_same(
        [
            'args' => [],
            'reads' => [
                'option:polylang', 'option:stylesheet', 'option:default_category',
                'option:permalink_structure', 'option:rewrite_rules',
                'term:language', 'entity:nav-menu',
            ],
            'writes' => ['entity:theme-mods-nav-menu-locations', 'option:default_category', 'option:rewrite_rules'],
            'scope' => 'site',
            'idempotent' => true,
            'timeout_seconds' => 120,
            'scoped' => ['operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT, 'reconcile' => true],
        ],
        $provider->capabilities()['synchronize_runtime'] ?? null,
        'provider declares the exact bounded reads, writes, timeout and recovery surface'
    );
    duo_check_throws(
        static fn(): array => $provider->invoke('unknown', []),
        \RuntimeException::class,
        'unknown provider capability refuses',
        'does not implement capability'
    );
    duo_check_throws(
        static fn(): array => $provider->reconcile_scoped('unknown', [], pll_operation()),
        \RuntimeException::class,
        'unknown recovery capability refuses',
        'does not implement capability'
    );

    $provider = pll_reset();
    $receipt = $provider->invoke_scoped('synchronize_runtime', [], pll_operation());
    duo_check_same(true, $receipt['verified'] ?? null, 'scoped invocation verifies all three projections');
    duo_check_same(pll_operation(), $receipt['operation'] ?? null, 'scoped receipt binds the exact recovery operation');
    duo_check_same(102, get_option('default_category'), 'default category repoints to the target-local English translation');
    duo_check_same(
        ['footer' => 0, 'primary' => 11],
        get_option('theme_mods_twentytwentyone')['nav_menu_locations'] ?? null,
        'raw theme locations project the declared default language with target-local menu ids'
    );
    duo_check_same(
        'preserve-me',
        get_option('theme_mods_twentytwentyone')['custom_target_neighbor'] ?? null,
        'provider preserves unrelated target theme-mod bytes'
    );
    duo_check_same(2, $receipt['after']['rewrite_rules_count'] ?? null, 'receipt counts the fresh native rewrite projection');
    duo_check_same('en', $receipt['after']['default_category_language'] ?? null, 'receipt proves the native category language');
    duo_check_same(2, $receipt['after']['nav_menu_locations_count'] ?? null, 'receipt bounds the complete raw menu projection');
    duo_check(
        preg_match('/^[0-9a-f]{64}$/D', (string) ($receipt['after']['rewrite_rules_hash'] ?? '')) === 1
            && preg_match('/^[0-9a-f]{64}$/D', (string) ($receipt['after']['nav_menu_locations_hash'] ?? '')) === 1
            && preg_match('/^[0-9a-f]{64}$/D', (string) ($receipt['after']['native_catalogs_hash'] ?? '')) === 1,
        'receipt publishes bounded fingerprints rather than option contents'
    );
    duo_check(
        count($GLOBALS['pll_command_calls']) === 1
            && ($GLOBALS['pll_command_calls'][0] ?? null) === [
                'rewrite flush', ['launch' => true, 'return' => 'all', 'exit_error' => false],
            ]
            && count($GLOBALS['pll_child_calls']) === 1
            && str_starts_with((string) ($GLOBALS['pll_child_calls'][0][0] ?? ''), 'eval ')
            && array_slice($GLOBALS['pll_child_calls'][0] ?? [], 1) === [120, 262144, 131072],
        'provider uses a bounded fresh catalog-verification child with fixed process limits'
    );
    duo_check_same(
        [['rewrite_rules', 'options'], ['alloptions', 'options']],
        $GLOBALS['pll_cache_deletes'],
        'parent option caches expire after the child process commits rewrite rules'
    );
    $published = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    duo_check(
        is_string($published) && !str_contains($published, 'preserve-me') && !str_contains($published, 'index.php'),
        'provider receipt excludes authored, target-owned, and rewrite-rule bytes'
    );

    $provider = pll_reset();
    $GLOBALS['pll_child_throw'] = new \RuntimeException('fixture transport timeout secret');
    try {
        $provider->invoke('synchronize_runtime', []);
        duo_check(false, 'bounded catalog-child launch failure refuses');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === 'duo: Polylang native registry/catalog verification child could not start; recovery_required'
                && $e->getPrevious() === null
                && !str_contains($e->getMessage(), 'fixture transport timeout secret'),
            'bounded catalog-child timeout failure stays redacted at the provider boundary'
        );
    }

    $provider = pll_reset();
    $GLOBALS['pll_child_result'] = [
        'return_code' => 70,
        'stdout' => 'child stdout secret',
        'stderr' => 'child stderr secret',
    ];
    try {
        $provider->invoke('synchronize_runtime', []);
        duo_check(false, 'nonzero bounded catalog child refuses');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === 'duo: Polylang native registry/catalog verification child failed; recovery_required'
                && !str_contains($e->getMessage(), 'secret'),
            'nonzero bounded catalog child never exposes captured output'
        );
    }

    $provider = pll_reset();
    $GLOBALS['pll_child_result']['stderr'] = 'child stderr secret';
    try {
        $provider->invoke('synchronize_runtime', []);
        duo_check(false, 'stderr-bearing bounded catalog child refuses');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === 'duo: Polylang native registry/catalog verification child failed; recovery_required'
                && !str_contains($e->getMessage(), 'secret'),
            'bounded catalog-child stderr is redacted from the provider refusal'
        );
    }

    $validCatalogReceipt = $GLOBALS['pll_child_result']['stdout'];
    foreach ([
        "notice\n" . $validCatalogReceipt,
        $validCatalogReceipt . "\n",
        str_replace("\n", "\r\n", $validCatalogReceipt),
        substr($validCatalogReceipt, 0, -1) . " \n",
    ] as $label => $stdout) {
        $provider = pll_reset();
        $GLOBALS['pll_child_result']['stdout'] = $stdout;
        duo_check_throws(
            static fn(): array => $provider->invoke('synchronize_runtime', []),
            \RuntimeException::class,
            "catalog child noncanonical receipt #$label refuses before a provider receipt",
            'recovery_required'
        );
    }

    $provider = pll_reset();
    $provider->invoke('synchronize_runtime', []);
    $GLOBALS['pll_after_command'] = static function (): void {};
    $idempotent = $provider->invoke('synchronize_runtime', []);
    duo_check(
        !array_key_exists('outcome', $idempotent['after'])
            && $idempotent['before'] === array_diff_key($idempotent['after'], ['native_catalogs_hash' => true]),
        'identical retry publishes a phase-independent exact after projection'
    );
    $reconciled = $provider->reconcile_scoped('synchronize_runtime', [], pll_operation());
    duo_check(
        ($reconciled['verified'] ?? null) === true
            && $reconciled['after'] === $idempotent['after'],
        'direct recovery reconciliation reruns the same phase-independent value projection'
    );

    $scopedFixture = dirname(__DIR__, 2) . '/support/polylang_provider_scoped_context.php';
    $scopedProcess = proc_open(
        [PHP_BINARY, $scopedFixture],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $scopedPipes
    );
    $scopedOut = is_resource($scopedProcess) ? stream_get_contents($scopedPipes[1]) : '';
    $scopedErr = is_resource($scopedProcess) ? stream_get_contents($scopedPipes[2]) : '';
    if (is_resource($scopedProcess)) {
        fclose($scopedPipes[1]);
        fclose($scopedPipes[2]);
        $scopedExit = proc_close($scopedProcess);
    } else {
        $scopedExit = 1;
    }
    $durableScoped = json_decode($scopedOut, true);
    duo_check(
        $scopedExit === 0
            && $scopedErr === ''
            && is_array($durableScoped)
            && ($durableScoped['invoke_status'] ?? null) === 'verified'
            && ($durableScoped['reconcile_status'] ?? null) === 'verified'
            && ($durableScoped['invoke_after_hash'] ?? null) === ($durableScoped['reconcile_after_hash'] ?? null),
        'real Providers invoke/reconcile accepts the durable Polylang projection instead of phase-label drift'
    );

    $configurationCases = [
        ['option is not an array', static function (): void { $GLOBALS['pll_options']['polylang'] = 'broken'; }, 'option is not an array'],
        ['stylesheet grammar', static function (): void { $GLOBALS['pll_options']['stylesheet'] = '../bad'; }, 'active stylesheet is invalid'],
        ['default language grammar', static function (): void { $GLOBALS['pll_options']['polylang']['default_lang'] = 'EN/../../'; }, 'default language is invalid'],
        ['theme map shape', static function (): void { $GLOBALS['pll_options']['polylang']['nav_menus']['twentytwentyone'] = 'broken'; }, 'theme map is invalid'],
        ['location shape', static function (): void { $GLOBALS['pll_options']['polylang']['nav_menus']['twentytwentyone']['primary'] = 'broken'; }, 'location map is invalid'],
        ['language id type', static function (): void { $GLOBALS['pll_options']['polylang']['nav_menus']['twentytwentyone']['primary']['en'] = '11'; }, 'language entry is invalid'],
        ['negative menu id', static function (): void { $GLOBALS['pll_options']['polylang']['nav_menus']['twentytwentyone']['primary']['en'] = -1; }, 'language entry is invalid'],
        ['missing menu', static function (): void { $GLOBALS['pll_options']['polylang']['nav_menus']['twentytwentyone']['primary']['en'] = 999; }, 'missing target menu'],
        ['invalid default category', static function (): void { $GLOBALS['pll_options']['default_category'] = '0'; }, 'default category is invalid'],
        ['missing category translation', static function (): void { $GLOBALS['pll_term_translations'][101] = []; }, 'has no target-language translation'],
    ];
    foreach ($configurationCases as [$label, $mutate, $message]) {
        $provider = pll_reset();
        $mutate();
        duo_check_throws(
            static fn(): array => $provider->invoke('synchronize_runtime', []),
            \RuntimeException::class,
            "$label refuses before an unverifiable receipt",
            $message
        );
    }

    $provider = pll_reset();
    $GLOBALS['pll_retain_theme_mod'] = true;
    duo_check_throws(
        static fn(): array => $provider->invoke('synchronize_runtime', []),
        \RuntimeException::class,
        'hostile retained theme-mod write refuses at exact raw readback',
        'raw nav_menu_locations does not match'
    );

    $provider = pll_reset();
    $GLOBALS['pll_command_throw'] = new \RuntimeException('fixture launch credential');
    try {
        $provider->invoke('synchronize_runtime', []);
        duo_check(false, 'native command throw is wrapped at the launch boundary');
    } catch (\RuntimeException $e) {
        duo_check(
            $e->getMessage() === "duo: Polylang 'wp rewrite flush' could not start"
                && $e->getPrevious()?->getMessage() === 'fixture launch credential',
            'native command throw is wrapped at the launch boundary'
        );
    }

    foreach ([null, (object) ['return_code' => '0', 'stdout' => 'Success: Rewrite rules flushed.', 'stderr' => '']] as $result) {
        $provider = pll_reset();
        $GLOBALS['pll_command_result'] = $result;
        duo_check_throws(
            static fn(): array => $provider->invoke('synchronize_runtime', []),
            \RuntimeException::class,
            'malformed native process result refuses without coercion',
            'returned an unreadable process result'
        );
    }

    $provider = pll_reset();
    $GLOBALS['pll_command_result'] = (object) ['return_code' => 70, 'stdout' => 'context', 'stderr' => 'secret'];
    duo_check_throws(
        static fn(): array => $provider->invoke('synchronize_runtime', []),
        \RuntimeException::class,
        'nonzero native rewrite refuses with bounded exit context',
        'exited 70'
    );

    $provider = pll_reset();
    $GLOBALS['pll_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => "Success: Rewrite rules flushed.\n",
        'stderr' => 'AKIAABCDEFGHIJKLMNOP',
    ];
    try {
        $provider->invoke('synchronize_runtime', []);
        duo_check(false, 'exit-zero stderr refuses without exposing its bytes');
    } catch (\RuntimeException $e) {
        duo_check(
            str_contains($e->getMessage(), 'emitted stderr despite exit 0')
                && !str_contains($e->getMessage(), 'AKIAABCDEFGHIJKLMNOP'),
            'exit-zero stderr refuses without exposing its bytes'
        );
    }

    $provider = pll_reset();
    $GLOBALS['pll_command_result'] = (object) ['return_code' => 0, 'stdout' => 'Success: unrelated', 'stderr' => ''];
    duo_check_throws(
        static fn(): array => $provider->invoke('synchronize_runtime', []),
        \RuntimeException::class,
        'exit zero without the native rewrite receipt refuses',
        'without its native success receipt'
    );

    $provider = pll_reset();
    $GLOBALS['pll_after_command'] = static function (): void {
        $GLOBALS['pll_options']['rewrite_rules'] = [];
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('synchronize_runtime', []),
        \RuntimeException::class,
        'native success with empty rewrite state refuses',
        'rewrite_rules postcondition is missing or invalid'
    );

    $provider = pll_reset();
    $GLOBALS['pll_options']['theme_mods_twentytwentyone']['nav_menu_locations'] = 'broken';
    duo_check_throws(
        static fn(): array => $provider->reconcile_scoped('synchronize_runtime', [], pll_operation()),
        \RuntimeException::class,
        'recovery reconciliation refuses malformed raw theme state',
        'raw nav_menu_locations storage is invalid'
    );

    $interpreter = new Polylang(new \Duo\Policy());
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 4) . '/manifests/polylang.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $nativeSubKeys = $manifest['options']['polylang']['sub_keys'];
    duo_check(
        !array_key_exists('polylang_wpml_strings', $manifest['options']),
        'unobserved populated WPML registry remains outside Polylang authored ownership'
    );
    duo_check(
        !in_array('option:polylang_wpml_strings', $manifest['actions'][0]['triggers'] ?? [], true),
        'unobserved WPML registry cannot trigger a provider-side native read or effect'
    );
    duo_check_throws(
        static fn(): ?array => $interpreter->option_rule(
            'polylang_wpml_strings',
            ['polylang_wpml_strings' => serialize(['unreviewed' => 'fixture'])]
        ),
        \RuntimeException::class,
        'populated WPML registry refuses through the closed unreviewed-option boundary',
        'contains an unreviewed row'
    );
    $artifactLock = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/conformance/artifacts.lock.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    duo_check_same(
        [
            'url' => 'https://downloads.wordpress.org/plugin/polylang.3.8.7.zip',
            'sha256' => 'bdb1e8d929410b3083f0884e6a356f91d659159771cc63893c9c06e83daddcb0',
            'role' => 'certified-boundary',
        ],
        $artifactLock['plugins']['polylang']['3.8.7'] ?? null,
        'official Polylang 3.8.7 archive is digest-pinned as the admitted upper boundary'
    );
    $versionMatrix = (string) file_get_contents(dirname(__DIR__, 2) . '/certify/certify_version_matrix.sh');
    duo_check(
        str_contains($versionMatrix, 'for POLYLANG_VERSION in 3.8 3.8.7; do')
            && str_contains($versionMatrix, 'fetch_artifact polylang 3.8.7 cli1')
            && str_contains($versionMatrix, "[ \"\$(wp1 plugin get polylang --field=version)\" = '3.8.7' ]"),
        'version matrix installs and exercises the exact official admitted upper boundary'
    );
    duo_check(
        str_contains($versionMatrix, 'synthetic Polylang 3.8.8')
            && str_contains($versionMatrix, 'Version:           3.8.8')
            && str_contains($versionMatrix, 'outside_version_range')
            && str_contains($versionMatrix, 'POLY_SYNTHETIC_HEAD_BEFORE')
            && str_contains($versionMatrix, 'did not restore exact 3.8.7 artifact bytes'),
        'version matrix creates only the real header-parser 3.8.8 control, proves refusal/no ref mutation, and restores the exact artifact'
    );
    $conformanceRoot = dirname(__DIR__, 3) . '/conformance';
    $seedScript = (string) file_get_contents($conformanceRoot . '/seeds/polylang.sh');
    $hostileScript = (string) file_get_contents($conformanceRoot . '/postdeploy/polylang.sh');
    $checksScript = (string) file_get_contents($conformanceRoot . '/checks/polylang.sh');
    $entry = json_decode(
        (string) file_get_contents($conformanceRoot . '/entries/polylang.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    duo_check_same(
        ['post', 'page', 'wp_block', 'attachment'],
        $entry['entry']['post_types'] ?? null,
        'Polylang conformance scopes posts, pages, synced patterns and attachments explicitly'
    );
    duo_check(
        str_contains($seedScript, '$pageFixtures = [')
            && str_contains($seedScript, "'fr' => ['private'")
            && str_contains($seedScript, "'ar' => ['draft'")
            && str_contains($seedScript, '$blockFixtures = [')
            && str_contains($seedScript, "'en' => ['pending'")
            && str_contains($seedScript, "'fr' => ['future'")
            && str_contains($seedScript, "'ar' => ['private'")
            && str_contains($seedScript, "'post_type' => 'wp_block'")
            && str_contains($seedScript, 'pll_save_post_translations($pages);')
            && str_contains($seedScript, 'pll_save_post_translations($blocks);'),
        'source fixture covers every persistent non-deletion status in translated page and synced-pattern groups'
    );
    duo_check(
        str_contains($hostileScript, "'en' => ['portable-polylang-page-en', 'draft']")
            && str_contains($hostileScript, "'fr' => ['portable-polylang-page-fr', 'publish']")
            && str_contains($hostileScript, "'en' => ['portable-polylang-block-en', 'private']")
            && str_contains($hostileScript, "'fr' => ['portable-polylang-block-fr', 'draft']")
            && str_contains($hostileScript, 'source/target hostile identities did not diverge')
            && str_contains($hostileScript, '.pages | to_entries')
            && str_contains($hostileScript, '.blocks | to_entries'),
        'hostile target fixture owns same-key page/pattern rows at disjoint identities and incompatible statuses'
    );
    duo_check(
        str_contains($checksScript, '$pageSlugs =')
            && str_contains($checksScript, '$blockSlugs =')
            && str_contains($checksScript, '.pages.fr.status == "private"')
            && str_contains($checksScript, '.blocks.fr.status == "future"')
            && str_contains($checksScript, 'for kind in posts pages blocks attachments terms language_terms menus; do')
            && str_contains($checksScript, 'for OBJECT_ID in "$POST_EN_ID" "$PAGE_EN_ID" "$BLOCK_EN_ID"; do')
            && str_contains($checksScript, 'Polylang native page translation map did not bind target-local identities')
            && str_contains($checksScript, 'Polylang native synced-pattern translation map did not bind target-local identities')
            && str_contains($checksScript, 'wp-json/wp/v2/pages/$PAGE_EN_ID')
            && str_contains($checksScript, 'conformance: recapture Polylang private page and scheduled pattern')
            && str_contains($checksScript, 'Polylang page/pattern recapture retry reran effects'),
        'Polylang checker proves source-authored status/content, native translation-group id rewrites, hostile target adoption, page routing and recapture idempotence'
    );
    duo_check(
        substr_count($versionMatrix, '"post_types": ["post", "page", "wp_block", "attachment"],') === 2,
        'both Polylang candidate-bound version-matrix fixtures retain synced patterns in scope'
    );
    $nativeBefore = [
        'force_lang' => 1,
        'domains' => ['en' => 'https://target.example.test'],
        'hide_default' => true,
        'rewrite' => true,
        'redirect_lang' => false,
        'browser' => false,
        'media_support' => true,
        'post_types' => [],
        'taxonomies' => [],
        'sync' => [],
        'default_lang' => 'en',
        'nav_menus' => [],
        'first_activation' => false,
        'previous_version' => '3.8.5',
        'version' => '3.8.6',
    ];
    $nativeDesired = [
        'browser' => true,
        'default_lang' => 'en',
        'force_lang' => 1,
        'hide_default' => false,
        'media_support' => true,
        'nav_menus' => ['theme' => ['primary' => ['en' => 41]]],
        'post_types' => ['book'],
        'redirect_lang' => true,
        'rewrite' => false,
        'sync' => ['taxonomies', 'post_meta'],
        'taxonomies' => ['genre'],
    ];
    $installNative = static function (array $values, ?array $raw = null): PllNativeOptions {
        $native = new PllNativeOptions($values);
        $GLOBALS['pll_runtime'] = (object) ['options' => $native, 'model' => new PllNativeModel()];
        if ($raw === null) {
            unset($GLOBALS['pll_options']['polylang']);
        } else {
            $GLOBALS['pll_options']['polylang'] = $raw;
        }
        $GLOBALS['pll_removed_actions'] = [];
        $GLOBALS['pll_add_filter_attempts'] = [];
        $GLOBALS['pll_add_filter_fail_once'] = [];
        $GLOBALS['pll_remove_filter_attempts'] = [];
        $GLOBALS['pll_remove_filter_fail_once'] = [];
        $GLOBALS['pll_cache_deletes'] = [];
        $GLOBALS['pll_option_cache'] = [];
        $GLOBALS['pll_retain_option_cache'] = [];
        $GLOBALS['pll_option_filter'] = null;
        $GLOBALS['wp_filter'] = [];
        add_filter('pre_update_option_polylang', [$native, 'protect_wp_option_storage'], 1, 1);
        add_action('shutdown', [$native, 'save_all'], 1000, 0);
        return $native;
    };
    $markerLocks = [];
    $lockMarker = static function (string $name) use (&$markerLocks): ?array {
        $markerLocks[] = $name;
        $raw = $GLOBALS['pll_options'][$name] ?? null;
        return is_string($raw) ? ['option_value' => $raw, 'autoload' => 'yes'] : null;
    };
    $readRawStorage = static function (): ?array {
        $raw = $GLOBALS['pll_options']['polylang'] ?? null;
        return is_array($raw) ? [
            'option_name' => 'polylang',
            'option_value' => serialize($raw),
            'autoload' => 'yes',
        ] : null;
    };
    $restoreRaw = static function () use ($nativeBefore, $readRawStorage): ?array {
        $GLOBALS['pll_options']['polylang'] = $nativeBefore;
        return $readRawStorage();
    };
    $registeredRuntimeRestore = null;
    $registerRuntimeRestore = static function (\Closure $restore) use (&$registeredRuntimeRestore): void {
        $registeredRuntimeRestore = $restore;
    };
    $writeRawStorage = static function (array $value): void {
        $GLOBALS['pll_options']['polylang'] = $value;
        unset($GLOBALS['pll_option_cache']['polylang']);
    };

    $native = $installNative($nativeBefore, $nativeBefore);
    $GLOBALS['pll_cache_delete_result'] = false;
    $GLOBALS['pll_option_cache']['polylang'] = ['stale' => 'cached-primary'];
    $finalized = 0;
    duo_check_same(
        true,
        $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            static function () use (&$finalized, $readRawStorage): array {
                ++$finalized;
                return $readRawStorage() ?? throw new \RuntimeException('fixture raw row is absent');
            },
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        'native grouped materialization succeeds through the complete registered option API'
    );
    $expectedNativeOrder = array_values(array_filter(
        PllNativeOptions::ORDER,
        static fn(string $key): bool => array_key_exists($key, $nativeDesired)
    ));
    duo_check_same($expectedNativeOrder, $native->setOrder, 'native merge validates authored settings in registry dependency order');
    $nativeAfter = $GLOBALS['pll_options']['polylang'];
    $nativePortableAfter = array_intersect_key($nativeAfter, $nativeDesired);
    $nativePortableExpected = $nativeDesired;
    ksort($nativePortableAfter, SORT_STRING);
    ksort($nativePortableExpected, SORT_STRING);
    duo_check(
        $finalized === 1
            && $markerLocks === []
            && $nativePortableAfter === $nativePortableExpected
            && $nativeAfter['domains'] === $nativeBefore['domains']
            && $nativeAfter['previous_version'] === '3.8.5'
            && array_keys($nativeAfter) === PllNativeOptions::ORDER,
        'native save persists all 15 registered keys, converges stale authored bytes, and preserves target-owned siblings'
    );
    duo_check(
        count($GLOBALS['pll_cache_deletes']) >= 2,
        'stale primary cache and cache-key absence (`wp_cache_delete=false`) are accepted only after exact raw/native readback'
    );
    $GLOBALS['pll_cache_delete_result'] = true;

    $native = $installNative($nativeBefore, $nativeBefore);
    add_filter('option_polylang', static fn(mixed $value): mixed => $nativeDesired, 10, 1);
    duo_check_throws(
        static fn(): bool => $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        \RuntimeException::class,
        'effective option filters cannot repair retained wrong database bytes into a false native success',
        'refuses an unaudited option filter topology'
    );
    duo_check(
        $nativeBefore === $GLOBALS['pll_options']['polylang'] && $native->setOrder === [],
        'unaudited filter topology refuses before raw or native state can change'
    );

    $native = $installNative(PllNativeOptions::DEFAULTS, [
        'default_lang' => '',
        'nav_menus' => [],
    ]);
    $interpreter->option_rule('polylang', [
        'polylang' => serialize(['default_lang' => '', 'nav_menus' => []]),
    ]);
    $normalized = $interpreter->normalize_captured_option_sub_keys(
        'polylang',
        ['nav_menus' => [], 'default_lang' => ''],
        $nativeSubKeys,
        ['polylang' => serialize(['default_lang' => '', 'nav_menus' => []])]
    );
    $normalizedKeys = array_keys($normalized);
    sort($normalizedKeys, SORT_STRING);
    duo_check_same(
        ['browser', 'default_lang', 'force_lang', 'hide_default', 'media_support', 'nav_menus', 'post_types', 'redirect_lang', 'rewrite', 'sync', 'taxonomies'],
        $normalizedKeys,
        'capture normalization projects every native authored default when raw upgrade rows omit keys'
    );
    $native->values['nav_menus'] = ['theme' => ['primary' => ['en' => 99]]];
    $interpreter->option_rule('polylang', [
        'polylang' => serialize(['default_lang' => '']),
    ]);
    duo_check_throws(
        static fn(): array => $interpreter->normalize_captured_option_sub_keys(
            'polylang',
            ['default_lang' => ''],
            $nativeSubKeys,
            ['polylang' => serialize(['default_lang' => ''])]
        ),
        \RuntimeException::class,
        'missing raw ref-bearing nav menus cannot bypass ordinary id tokenization',
        'native singleton disagrees with the registered schema default'
    );
    $native = $installNative(PllNativeOptions::DEFAULTS, ['default_lang' => '', 'nav_menus' => []]);
    $native->values['force_lang'] = 0;
    $interpreter->option_rule('polylang', [
        'polylang' => serialize(['default_lang' => '', 'nav_menus' => []]),
        'pll_language_from_content_available' => 'yes',
    ]);
    duo_check_throws(
        static fn(): array => $interpreter->normalize_captured_option_sub_keys(
            'polylang',
            ['default_lang' => '', 'nav_menus' => []],
            $nativeSubKeys,
            [
                'polylang' => serialize(['default_lang' => '', 'nav_menus' => []]),
                'pll_language_from_content_available' => 'yes',
            ]
        ),
        \RuntimeException::class,
        'raw-missing force_lang refuses a stale process-local singleton even when its topology marker is healthy',
        'native singleton disagrees with the registered schema default'
    );

    $native = $installNative($nativeBefore, $nativeBefore);
    $retainedPrimary = $nativeBefore;
    $retainedPrimary['domains'] = ['en' => 'https://stale-cache.invalid'];
    $GLOBALS['pll_option_cache']['polylang'] = $retainedPrimary;
    $GLOBALS['pll_retain_option_cache'] = ['polylang' => true, 'alloptions' => true];
    duo_check_throws(
        static fn(): bool => $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        \RuntimeException::class,
        'retained stale primary cache refuses before native setters',
        'primary option cache disagrees with the exact locked row'
    );
    duo_check_same([], $native->setOrder, 'retained stale primary cache performs no native setter side effects');

    $native = $installNative($nativeBefore, $nativeBefore);
    $native->values['domains'] = ['en' => 'https://stale-singleton.invalid'];
    duo_check_throws(
        static fn(): bool => $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        \RuntimeException::class,
        'stale in-memory native registry refuses before grouped merge',
        'in-memory option registry disagrees with the exact locked row'
    );
    duo_check_same([], $native->setOrder, 'stale native singleton cannot become the base of a grouped save');

    $cleanDefaults = $nativeBefore;
    $cleanDefaults['domains'] = [];
    $cleanDefaults['default_lang'] = '';
    $cleanDefaults['previous_version'] = '';
    $cleanDesired = array_intersect_key($cleanDefaults, array_flip([
        'browser', 'default_lang', 'force_lang', 'hide_default', 'media_support', 'nav_menus',
        'post_types', 'redirect_lang', 'rewrite', 'sync', 'taxonomies',
    ]));
    $native = $installNative($cleanDefaults, null);
    $GLOBALS['pll_option_cache']['polylang'] = $nativeBefore;
    $absentRestores = 0;
    duo_check_same(
        true,
        $interpreter->materialize_option_sub_keys(
            'polylang',
            $cleanDesired,
            $nativeSubKeys,
            'yes',
            null,
            $lockMarker,
            $readRawStorage,
            static function () use (&$absentRestores): ?array {
                ++$absentRestores;
                unset($GLOBALS['pll_options']['polylang']);
                return null;
            },
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        'clean-install absent target materializes native defaults without a generic partial-row fallback'
    );
    duo_check(
        $absentRestores === 0
            && is_array($GLOBALS['pll_options']['polylang'] ?? null)
            && array_keys($GLOBALS['pll_options']['polylang']) === PllNativeOptions::ORDER
            && $GLOBALS['pll_options']['polylang']['default_lang'] === '',
        'clean-install lifecycle persists the complete 15-key native shape including the empty no-language default'
    );

    $sparseRaw = $nativeBefore;
    unset(
        $sparseRaw['domains'],
        $sparseRaw['first_activation'],
        $sparseRaw['media_support'],
        $sparseRaw['version']
    );
    $staleSparseNative = $nativeBefore;
    $staleSparseNative['domains'] = ['en' => 'https://stale-missing-env.invalid'];
    $staleSparseNative['first_activation'] = true;
    $staleSparseNative['media_support'] = false;
    $staleSparseNative['version'] = '3.7.99';
    $native = $installNative($staleSparseNative, $sparseRaw);
    duo_check_same(
        true,
        $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $sparseRaw,
            $lockMarker,
            $readRawStorage,
            static function () use ($sparseRaw, $readRawStorage): ?array {
                $GLOBALS['pll_options']['polylang'] = $sparseRaw;
                return $readRawStorage();
            },
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        'upgrade-era raw-missing registered keys reset through the native registry before grouped save'
    );
    duo_check(
        $GLOBALS['pll_options']['polylang']['domains'] === []
            && $GLOBALS['pll_options']['polylang']['first_activation'] === false
            && $GLOBALS['pll_options']['polylang']['media_support'] === true
            && $GLOBALS['pll_options']['polylang']['version'] === '3.8.6',
        'missing authored and env keys persist native defaults/current values, never stale singleton bytes'
    );

    foreach ([2, 3] as $unsupportedMode) {
        $native = $installNative($nativeBefore, $nativeBefore);
        $unsupported = $nativeDesired;
        $unsupported['force_lang'] = $unsupportedMode;
        duo_check_throws(
            static fn(): bool => $interpreter->materialize_option_sub_keys(
                'polylang',
                $unsupported,
                $nativeSubKeys,
                'yes',
                $nativeBefore,
                $lockMarker,
                $readRawStorage,
                $restoreRaw,
                $registerRuntimeRestore,
                $writeRawStorage
            ),
            \RuntimeException::class,
            "topology mode $unsupportedMode refuses before domain/browser coercion or reachability warnings",
            'supports only native modes 0 or 1'
        );
        duo_check_same([], $native->setOrder, "topology mode $unsupportedMode performs no native setter side effects");
    }

    $modeZeroDesired = $nativeDesired;
    $modeZeroDesired['force_lang'] = 0;
    foreach ([null, 'no', 'yes'] as $marker) {
        $native = $installNative($nativeBefore, $nativeBefore);
        if ($marker === null) {
            unset($GLOBALS['pll_options']['pll_language_from_content_available']);
        } else {
            $GLOBALS['pll_options']['pll_language_from_content_available'] = $marker;
        }
        $markerLocks = [];
        if ($marker !== 'yes') {
            duo_check_throws(
                static fn(): bool => $interpreter->materialize_option_sub_keys(
                    'polylang',
                    $modeZeroDesired,
                    $nativeSubKeys,
                    'yes',
                    $nativeBefore,
                    $lockMarker,
                    $readRawStorage,
                    $restoreRaw,
                    $registerRuntimeRestore,
                    $writeRawStorage
                ),
                \RuntimeException::class,
                'mode 0 refuses an absent/non-yes exact locked capability marker',
                "requires target-local pll_language_from_content_available='yes'"
            );
            duo_check_same([], $native->setOrder, 'mode-0 marker refusal happens before native setters');
            continue;
        }
        duo_check_same(
            true,
            $interpreter->materialize_option_sub_keys(
                'polylang',
                $modeZeroDesired,
                $nativeSubKeys,
                'yes',
                $nativeBefore,
                $lockMarker,
                $readRawStorage,
                $restoreRaw,
                $registerRuntimeRestore,
                $writeRawStorage
            ),
            'mode 0 is supported only with the exact locked target capability marker'
        );
        duo_check_same(
            ['pll_language_from_content_available'],
            $markerLocks,
            'mode 0 obtains one raw companion row/gap lock instead of trusting object-cache state'
        );
    }

    $native = $installNative($nativeBefore, $nativeBefore);
    $GLOBALS['pll_options']['pll_language_from_content_available'] = 'yes';
    $GLOBALS['pll_option_cache']['pll_language_from_content_available'] = 'no';
    duo_check_same(
        true,
        $interpreter->materialize_option_sub_keys(
            'polylang',
            $modeZeroDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        'raw yes plus stale cached no purges both option caches and converges through the locked marker'
    );
    duo_check(
        in_array(['pll_language_from_content_available', 'options'], $GLOBALS['pll_cache_deletes'], true)
            && in_array(['alloptions', 'options'], $GLOBALS['pll_cache_deletes'], true),
        'native mode-0 materialization purges the exact companion and alloptions before native setters'
    );

    $native = $installNative($nativeBefore, $nativeBefore);
    $GLOBALS['pll_options']['pll_language_from_content_available'] = 'no';
    $GLOBALS['pll_option_cache']['pll_language_from_content_available'] = 'yes';
    duo_check_throws(
        static fn(): bool => $interpreter->materialize_option_sub_keys(
            'polylang',
            $modeZeroDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        \RuntimeException::class,
        'raw no plus stale cached yes purges the stale capability and refuses before native setters',
        "requires target-local pll_language_from_content_available='yes'"
    );
    duo_check_same([], $native->setOrder, 'stale cached yes cannot authorize a raw non-yes topology marker');

    $native = $installNative($nativeBefore, $nativeBefore);
    $native->warnOnceAt = 'sync';
    $native->warningCode = 'pll_invalid_domains';
    $warningText = '';
    try {
        $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        );
    } catch (\RuntimeException $failure) {
        $warningText = $failure->getMessage();
    }
    duo_check(
        str_contains($warningText, 'pll_invalid_domains')
            && $native->values === $nativeBefore
            && $GLOBALS['pll_options']['polylang'] === $nativeBefore
            && has_action('shutdown', [$native, 'save_all']) === 1000
            && !$native->modified
            && $native->save() === false,
        'warning-class native results restore exact raw/in-memory state, consume modified state, and preserve shutdown topology'
    );
    duo_check_same(
        true,
        $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        'same-process retry converges after a restored native warning failure'
    );

    $temporaryGuardRemovalOrder = [
        'pre_update_option',
        'pre_update_option_polylang',
        'pre_option_polylang',
    ];
    foreach ($temporaryGuardRemovalOrder as $position => $failedHook) {
        $native = $installNative($nativeBefore, $nativeBefore);
        $GLOBALS['pll_remove_filter_fail_once'] = [$failedHook];
        if ($position === 1) {
            $native->saveThrowOnce = new \RuntimeException('fixture original native save failure');
        }
        $cleanupText = '';
        try {
            $interpreter->materialize_option_sub_keys(
                'polylang',
                $nativeDesired,
                $nativeSubKeys,
                'yes',
                $nativeBefore,
                $lockMarker,
                $readRawStorage,
                $restoreRaw,
                $registerRuntimeRestore,
                $writeRawStorage
            );
        } catch (\RuntimeException $failure) {
            $cleanupText = $failure->getMessage();
        }
        $temporaryAttempts = array_values(array_filter(
            $GLOBALS['pll_remove_filter_attempts'],
            static fn(string $hook): bool => in_array($hook, $temporaryGuardRemovalOrder, true)
        ));
        duo_check(
            str_contains($cleanupText, 'guard cleanup was incomplete')
                && array_slice($temporaryAttempts, 0, 3) === $temporaryGuardRemovalOrder
                && !isset($GLOBALS['wp_filter']['pre_option_polylang'])
                && !isset($GLOBALS['wp_filter']['pre_update_option'])
                && $native->values === $nativeBefore
                && !$native->modified
                && has_action('shutdown', [$native, 'save_all']) === 1000
                && $native->save() === false,
            'first/middle/last temporary-guard removal failures attempt every cleanup and leave no later guard or armed shutdown write'
        );
        if ($position === 1) {
            duo_check(
                str_contains($cleanupText, 'primary=') && str_contains($cleanupText, 'remove='),
                'native save plus guard-cleanup failure retains bounded fingerprints for both causes'
            );
        }
    }

    $temporaryGuardInstallOrder = [
        'pre_option_polylang',
        'pre_update_option_polylang',
        'pre_update_option',
    ];
    foreach ($temporaryGuardInstallOrder as $failedHook) {
        $native = $installNative($nativeBefore, $nativeBefore);
        $GLOBALS['pll_add_filter_fail_once'] = [$failedHook];
        $installText = '';
        try {
            $interpreter->materialize_option_sub_keys(
                'polylang',
                $nativeDesired,
                $nativeSubKeys,
                'yes',
                $nativeBefore,
                $lockMarker,
                $readRawStorage,
                $restoreRaw,
                $registerRuntimeRestore,
                $writeRawStorage
            );
        } catch (\RuntimeException $failure) {
            $installText = $failure->getMessage();
        }
        $temporaryAdds = array_values(array_filter(
            $GLOBALS['pll_add_filter_attempts'],
            static fn(string $hook): bool => in_array($hook, $temporaryGuardInstallOrder, true)
        ));
        duo_check(
            str_contains($installText, 'could not install a native no-write guard')
                && in_array($failedHook, $temporaryAdds, true)
                && !isset($GLOBALS['wp_filter']['pre_option_polylang'])
                && !isset($GLOBALS['wp_filter']['pre_update_option'])
                && $native->values === $nativeBefore
                && !$native->modified
                && has_action('shutdown', [$native, 'save_all']) === 1000
                && $native->save() === false,
            'first/middle/last native guard-install failures restore storage/runtime and cannot leave an armed shutdown writer'
        );
    }

    foreach ([
        'first' => ['key' => 'force_lang', 'before' => ['force_lang' => 0]],
        'middle' => ['key' => 'post_types', 'before' => ['post_types' => ['legacy_book']]],
        'last' => ['key' => 'nav_menus', 'before' => [
            'nav_menus' => ['theme' => ['primary' => ['en' => 40]]],
        ]],
    ] as $position => $restoreFailure) {
        $restoreBefore = array_replace($nativeBefore, $restoreFailure['before']);
        $native = $installNative($restoreBefore, $restoreBefore);
        $GLOBALS['pll_options']['pll_language_from_content_available'] = 'yes';
        $registeredRuntimeRestore = null;
        $failedRestoreWrite = static function (array $value) use ($native, $restoreFailure): void {
            $native->throwOnceAt = $restoreFailure['key'];
            throw new \RuntimeException('fixture storage failure before native rollback restoration');
        };
        $restoreText = '';
        try {
            $interpreter->materialize_option_sub_keys(
                'polylang',
                $nativeDesired,
                $nativeSubKeys,
                'yes',
                $restoreBefore,
                $lockMarker,
                $readRawStorage,
                static function () use ($restoreBefore, $readRawStorage): ?array {
                    $GLOBALS['pll_options']['polylang'] = $restoreBefore;
                    return $readRawStorage();
                },
                $registerRuntimeRestore,
                $failedRestoreWrite
            );
        } catch (\RuntimeException $failure) {
            $restoreText = $failure->getMessage();
        }
        duo_check(
            str_contains($restoreText, 'recovery_required')
                && is_callable($registeredRuntimeRestore)
                && !$native->modified
                && has_action('shutdown', [$native, 'save_all']) === 1000
                && $native->save() === false
                && in_array(['notoptions', 'options'], $GLOBALS['pll_cache_deletes'], true),
            "$position native restore-setter failure still consumes modified state before any shutdown writer is rearmed"
        );
        $registeredRuntimeRestore();
        duo_check(
            $native->values === $restoreBefore
                && !$native->modified
                && has_action('shutdown', [$native, 'save_all']) === 1000
                && $native->save() === false,
            "$position native restore-setter retry converges exact runtime state through the registered rollback participant"
        );
    }

    $native = $installNative($nativeBefore, $nativeBefore);
    $failedWrite = static function (array $value): void {
        throw new \RuntimeException('injected engine-owned raw write failure');
    };
    duo_check_throws(
        static fn(): bool => $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $failedWrite
        ),
        \RuntimeException::class,
        'engine-owned native storage failure cannot be mistaken for idempotent false',
        'injected engine-owned raw write failure'
    );
    duo_check(
        $native->values === $nativeBefore && $GLOBALS['pll_options']['polylang'] === $nativeBefore,
        'storage failure restores exact same-process native and raw state'
    );
    duo_check_same(
        true,
        $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            $readRawStorage,
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        'same-process retry converges after a restored engine-owned storage failure'
    );

    $native = $installNative($nativeBefore, $nativeBefore);
    duo_check_throws(
        static fn(): bool => $interpreter->materialize_option_sub_keys(
            'polylang',
            $nativeDesired,
            $nativeSubKeys,
            'yes',
            $nativeBefore,
            $lockMarker,
            static function (): void { throw new \RuntimeException('autoload reconciliation failed'); },
            $restoreRaw,
            $registerRuntimeRestore,
            $writeRawStorage
        ),
        \RuntimeException::class,
        'post-save storage/autoload failure stays inside native restoration boundary',
        'autoload reconciliation failed'
    );
    duo_check(
        $native->values === $nativeBefore && $GLOBALS['pll_options']['polylang'] === $nativeBefore,
        'post-save storage failure restores exact raw and in-memory state before returning'
    );

    $native = [
        'hide_if_no_translation' => 0,
        'hide_current' => 1,
        'force_home' => false,
        'show_flags' => 1,
        'show_names' => true,
        'dropdown' => 0,
    ];
    duo_check_same(
        ['class' => 'authored', 'plain_data' => true, 'lint_ok' => true],
        $interpreter->post_meta_rule('_pll_menu_item', ['_pll_menu_item' => serialize($native)]),
        'native switcher metadata receives the authored plain-data rule'
    );
    duo_check_same(null, $interpreter->post_meta_rule('_foreign', []), 'interpreter never claims foreign post metadata');
    foreach ([
        'list' => [0, 1, 0, 1, 0, 1],
        'missing key' => array_diff_key($native, ['dropdown' => true]),
        'unknown key' => $native + ['future' => 1],
        'wrong value type' => array_replace($native, ['show_names' => '1']),
        'out-of-range value' => array_replace($native, ['show_names' => 2]),
    ] as $label => $invalid) {
        duo_check_throws(
            static fn(): ?array => $interpreter->post_meta_rule('_pll_menu_item', ['_pll_menu_item' => serialize($invalid)]),
            \RuntimeException::class,
            "switcher $label refuses at capture schema classification",
            'Polylang live _pll_menu_item'
        );
    }
    duo_check_same(
        ['class' => 'authored', 'plain_data' => true],
        $interpreter->term_meta_rule('_pll_strings_translations', [
            '_pll_strings_translations' => serialize([['Hello', 'Bonjour']]),
        ]),
        'populated string-translation termmeta enters the authored plain-data term sidecar'
    );
    foreach ([
        'not a list' => serialize(['Hello' => 'Bonjour']),
        'wrong row arity' => serialize([['Hello']]),
        'empty source' => serialize([['', 'Bonjour']]),
        'duplicate source' => serialize([['Hello', 'Bonjour'], ['Hello', 'Salut']]),
        'trailing serialization' => serialize([['Hello', 'Bonjour']]) . 'tail',
    ] as $label => $invalidCatalog) {
        duo_check_throws(
            static fn(): ?array => $interpreter->term_meta_rule('_pll_strings_translations', [
                '_pll_strings_translations' => $invalidCatalog,
            ]),
            \RuntimeException::class,
            "string catalog $label refuses before authored classification"
        );
    }
    duo_check_same(null, $interpreter->term_meta_rule('_foreign', []), 'interpreter never claims foreign term metadata');

    $legacyLanguageDescription = serialize(['locale' => 'ar', 'rtl' => 1, 'flag_code' => 'sa']);
    $modernLanguageDescription = serialize(['locale' => 'fr_FR', 'rtl' => false, 'flag_code' => 'fr']);
    duo_check_same(
        ['lint_ok' => true],
        $interpreter->taxonomy_description_lint_rule('language', $legacyLanguageDescription),
        'legacy integer RTL language metadata is schema-validated before its lint exemption'
    );
    duo_check_same(
        ['lint_ok' => true],
        $interpreter->taxonomy_description_lint_rule('language', $modernLanguageDescription),
        'Polylang 3.8 boolean RTL language metadata is schema-validated before its lint exemption'
    );
    duo_check_same(
        null,
        $interpreter->taxonomy_description_lint_rule('category', $legacyLanguageDescription),
        'interpreter never exempts foreign taxonomy descriptions'
    );
    foreach ([
        'list shape' => serialize(['ar', 1, 'sa']),
        'missing key' => serialize(['locale' => 'ar', 'rtl' => 1]),
        'unknown key' => serialize(['locale' => 'ar', 'rtl' => 1, 'flag_code' => 'sa', 'future' => 'value']),
        'invalid locale' => serialize(['locale' => '../ar', 'rtl' => 1, 'flag_code' => 'sa']),
        'invalid RTL' => serialize(['locale' => 'ar', 'rtl' => 2, 'flag_code' => 'sa']),
        'invalid flag' => serialize(['locale' => 'ar', 'rtl' => 1, 'flag_code' => '../secret']),
        'malformed serialization' => 'a:3:{broken',
    ] as $label => $invalidDescription) {
        duo_check_throws(
            static fn(): ?array => $interpreter->taxonomy_description_lint_rule('language', $invalidDescription),
            \RuntimeException::class,
            "language description $label refuses before exemption",
            'Polylang live language description'
        );
    }
    $fakeDescriptionSecret = 'AKIAABCDEFGHIJKLMNOP';
    try {
        $interpreter->taxonomy_description_lint_rule('language', serialize([
            'locale' => 'ar', 'rtl' => 1, 'flag_code' => 'sa', 'future_secret' => $fakeDescriptionSecret,
        ]));
        $descriptionSecretFailure = '';
    } catch (\RuntimeException $failure) {
        $descriptionSecretFailure = $failure->getMessage();
    }
    duo_check(
        $descriptionSecretFailure !== '' && !str_contains($descriptionSecretFailure, $fakeDescriptionSecret),
        'language-description schema refusal never echoes untrusted secret-shaped bytes'
    );

    $portableOptions = [
        'browser' => false,
        'default_lang' => 'en',
        'force_lang' => 1,
        'hide_default' => true,
        'media_support' => true,
        'nav_menus' => [
            'twentytwentyone' => [
                'primary' => ['en' => '{{term:00000000-0000-4000-8000-000000000001}}'],
            ],
        ],
        'post_types' => [],
        'redirect_lang' => false,
        'rewrite' => true,
        'sync' => ['taxonomies', 'post_meta', 'post_date'],
        'taxonomies' => [],
    ];
    $validTree = [
        [
            'type' => 'options',
            'path' => 'state/options/core.json',
            'data' => ['records' => ['polylang' => ['state' => 'present', 'value' => $portableOptions]]],
        ],
        [
            'type' => 'post',
            'path' => 'posts/nav_menu_item/switcher.md',
            'data' => ['type' => 'nav_menu_item', 'meta' => ['_pll_menu_item' => $native]],
        ],
        [
            'type' => 'term',
            'path' => 'terms/language/ar.json',
            'data' => ['taxonomy' => 'language', 'slug' => 'ar', 'description' => $legacyLanguageDescription],
        ],
        [
            'type' => 'term',
            'path' => 'terms/language/en.json',
            'data' => [
                'taxonomy' => 'language',
                'slug' => 'en',
                'description' => serialize(['locale' => 'en_US', 'rtl' => false, 'flag_code' => 'us']),
                'meta' => ['_pll_strings_translations' => [['Hello', 'Hello'], ['Welcome', 'Welcome']]],
            ],
        ],
    ];
    duo_check_same([], $interpreter->repository_diagnostics($validTree), 'native switcher and language repository schemas pass');
    $tamperedTree = $validTree;
    $tamperedTree[1]['data']['meta']['_pll_menu_item']['future'] = 1;
    $diagnostics = $interpreter->repository_diagnostics($tamperedTree);
    duo_check_same('adapter_schema_content_mismatch', $diagnostics[0]['code'] ?? null, 'tampered repository switcher emits a stable schema diagnostic');
    duo_check_same('meta._pll_menu_item', $diagnostics[0]['locator'] ?? null, 'tamper diagnostic points at the exact meta frontier');
    $tamperedDescriptionTree = $validTree;
    $tamperedDescriptionTree[2]['data']['description'] = serialize([
        'locale' => 'ar', 'rtl' => 1, 'flag_code' => 'sa', 'future' => 1,
    ]);
    $diagnostics = $interpreter->repository_diagnostics($tamperedDescriptionTree);
    duo_check_same('adapter_schema_content_mismatch', $diagnostics[0]['code'] ?? null, 'tampered repository language description emits a stable schema diagnostic');
    duo_check_same('description', $diagnostics[0]['locator'] ?? null, 'language-description diagnostic points at the exact frontier');

    foreach ([
        'missing portable key' => array_diff_key($portableOptions, ['media_support' => true]),
        'string media gate' => array_replace($portableOptions, ['media_support' => '1']),
        'unknown sync value' => array_replace($portableOptions, ['sync' => ['future_secret_mode']]),
        'duplicate object type' => array_replace($portableOptions, ['post_types' => ['book', 'book']]),
        'raw local menu id' => array_replace_recursive($portableOptions, [
            'nav_menus' => ['twentytwentyone' => ['primary' => ['en' => 991]]],
        ]),
    ] as $label => $invalidOptions) {
        $tree = $validTree;
        $tree[0]['data']['records']['polylang']['value'] = $invalidOptions;
        $diagnostics = $interpreter->repository_diagnostics($tree);
        duo_check(
            ($diagnostics[0]['code'] ?? null) === 'adapter_schema_content_mismatch'
                && ($diagnostics[0]['locator'] ?? null) === 'records.polylang.value',
            "portable option $label refuses at immutable repository schema validation"
        );
    }

    duo_check_summary('Polylang production readiness');
}
