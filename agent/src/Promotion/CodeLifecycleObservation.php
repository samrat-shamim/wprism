<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Code/CodeCompatibility.php';
require_once __DIR__ . '/../Kernel/DatabaseLockBoundary.php';
require_once __DIR__ . '/../Kernel/PathSafety.php';
require_once __DIR__ . '/../Kernel/PlainData.php';

/** Cache-independent WordPress lifecycle rows plus direct code-header facts. */
final class CodeLifecycleObservation {
    private const OPTION_NAMES = ['active_plugins', 'stylesheet', 'template'];
    private const MAX_ACTIVE_PLUGINS = 4096;
    private const MAX_OPTION_VALUE_BYTES = 4194304;
    private const MAX_AUTOLOAD_BYTES = 64;
    private const MAX_THEME_ROOTS = 64;

    /** @return list<string> */
    public static function option_names(): array {
        return self::OPTION_NAMES;
    }

    /**
     * Advisory preflight observation. All three lifecycle rows and their
     * bounded witnesses come from one SQL statement; authoritative writers
     * use from_locked_rows() beneath next-key locks instead.
     *
     * @return array<string,mixed>
     */
    public static function read_unlocked(array $desired, ?string $recordedRaw): array {
        return self::from_option_rows($desired, self::read_unlocked_options(), $recordedRaw);
    }

    /**
     * Compose already-locked option rows with fresh direct header samples.
     *
     * @param array<string,array{option_name:string,option_value:string,autoload:string}> $optionRows
     * @return array<string,mixed>
     */
    public static function from_locked_rows(
        array $desired,
        array $optionRows,
        ?string $recordedRaw
    ): array {
        return self::from_option_rows($desired, $optionRows, $recordedRaw);
    }

    /** @return array<string,array{option_name:string,option_value:string,autoload:string}> */
    private static function read_unlocked_options(): array {
        global $wpdb;
        $table = (string) ($wpdb->options ?? '');
        DatabaseLockBoundary::assert_table_identifiers([$table], 'code lifecycle observation');
        $placeholders = implode(',', array_fill(0, count(self::OPTION_NAMES), '%s'));
        $valueLimit = self::MAX_OPTION_VALUE_BYTES + 1;
        $autoloadLimit = self::MAX_AUTOLOAD_BYTES + 1;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, OCTET_LENGTH(option_value) AS option_value_bytes, '
                . 'SHA2(option_value, 256) AS option_value_sha256, '
                . "LEFT(option_value, $valueLimit) AS bounded_option_value, "
                . 'OCTET_LENGTH(autoload) AS autoload_bytes, '
                . 'SHA2(autoload, 256) AS autoload_sha256, '
                . "LEFT(autoload, $autoloadLimit) AS bounded_autoload "
                . "FROM `$table` WHERE option_name IN ($placeholders) "
                . 'ORDER BY option_name ASC LIMIT 4',
            ...self::OPTION_NAMES
        ), ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        if (!is_array($rows) || !array_is_list($rows) || $error !== '') {
            throw new \RuntimeException('wprism: code lifecycle option observation failed');
        }
        $observed = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['option_name'] ?? null) : null;
            $valueBytes = is_array($row)
                ? self::canonical_size($row['option_value_bytes'] ?? null)
                : null;
            $autoloadBytes = is_array($row)
                ? self::canonical_size($row['autoload_bytes'] ?? null)
                : null;
            $valueHash = is_array($row)
                ? self::canonical_sha256($row['option_value_sha256'] ?? null)
                : null;
            $autoloadHash = is_array($row)
                ? self::canonical_sha256($row['autoload_sha256'] ?? null)
                : null;
            $value = is_array($row) ? ($row['bounded_option_value'] ?? null) : null;
            $autoload = is_array($row) ? ($row['bounded_autoload'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== [
                    'option_name', 'option_value_bytes', 'option_value_sha256',
                    'bounded_option_value', 'autoload_bytes', 'autoload_sha256',
                    'bounded_autoload',
                ]
                || !is_string($name)
                || !in_array($name, self::OPTION_NAMES, true)
                || isset($observed[$name])
                || $valueBytes === null
                || $autoloadBytes === null
                || $valueBytes > self::MAX_OPTION_VALUE_BYTES
                || $autoloadBytes > self::MAX_AUTOLOAD_BYTES
                || $valueHash === null
                || $autoloadHash === null
                || !is_string($value)
                || !is_string($autoload)
                || strlen($value) !== $valueBytes
                || strlen($autoload) !== $autoloadBytes
                || !hash_equals($valueHash, hash('sha256', $value))
                || !hash_equals($autoloadHash, hash('sha256', $autoload))) {
                throw new \RuntimeException(
                    'wprism: code lifecycle option observation returned malformed, duplicate, NULL, or oversized evidence'
                );
            }
            $observed[$name] = [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => $autoload,
            ];
        }
        foreach (self::OPTION_NAMES as $name) {
            if (!isset($observed[$name])) {
                throw new \RuntimeException(
                    'wprism: code lifecycle observation requires every canonical lifecycle option row'
                );
            }
        }
        ksort($observed, SORT_STRING);
        return $observed;
    }

    /**
     * @param array<string,array{option_name:string,option_value:string,autoload:string}> $optionRows
     * @return array<string,mixed>
     */
    private static function from_option_rows(
        array $desired,
        array $optionRows,
        ?string $recordedRaw
    ): array {
        $keys = array_keys($optionRows);
        sort($keys, SORT_STRING);
        if ($keys !== self::OPTION_NAMES) {
            throw new \RuntimeException('wprism: code lifecycle option roster is incomplete or malformed');
        }
        foreach (self::OPTION_NAMES as $name) {
            $row = $optionRows[$name] ?? null;
            if (!is_array($row)
                || array_keys($row) !== ['option_name', 'option_value', 'autoload']
                || ($row['option_name'] ?? null) !== $name
                || !is_string($row['option_value'] ?? null)
                || !is_string($row['autoload'] ?? null)) {
                throw new \RuntimeException('wprism: code lifecycle option roster is malformed');
            }
        }

        $active = self::active_plugins($optionRows['active_plugins']['option_value']);
        $template = self::theme_option($optionRows['template']['option_value'], 'template');
        $stylesheet = self::theme_option($optionRows['stylesheet']['option_value'], 'stylesheet');

        $pluginRoster = [];
        foreach (array_merge($active, self::desired_plugins($desired)) as $plugin) {
            self::assert_plugin_identity($plugin);
            $pluginRoster[$plugin] = true;
        }
        $plugins = [];
        $pluginExists = [];
        $pluginNames = array_keys($pluginRoster);
        sort($pluginNames, SORT_STRING);
        foreach ($pluginNames as $plugin) {
            [$exists, $version] = self::plugin_header($plugin);
            $pluginExists[$plugin] = $exists;
            $plugins[$plugin] = $version;
        }

        $themeRoster = [];
        foreach ([$template, $stylesheet, $desired['template'] ?? null, $desired['stylesheet'] ?? null] as $theme) {
            if ($theme === null || $theme === '') {
                continue;
            }
            if (!is_string($theme) || !PathSafety::safe_component($theme)) {
                throw new \RuntimeException('wprism: theme lifecycle identity is unsafe');
            }
            $themeRoster[$theme] = true;
        }
        $themes = [];
        $themeExists = [];
        $themeNames = array_keys($themeRoster);
        sort($themeNames, SORT_STRING);
        foreach ($themeNames as $theme) {
            [$exists, $version] = self::theme_header($theme);
            $themeExists[$theme] = $exists;
            $themes[$theme] = $version;
        }

        return [
            'active_plugins' => $active,
            'plugins' => $plugins,
            'plugin_exists' => $pluginExists,
            'template' => $template,
            'stylesheet' => $stylesheet,
            'themes' => $themes,
            'theme_exists' => $themeExists,
            'recorded_raw' => $recordedRaw,
        ];
    }

    /** @return list<string> */
    private static function active_plugins(string $raw): array {
        if (strlen($raw) > self::MAX_OPTION_VALUE_BYTES || $raw !== trim($raw)) {
            throw new \RuntimeException('wprism: active_plugins option is malformed or oversized');
        }
        $decoded = PlainData::decode_serialized($raw, 'active_plugins option');
        // WordPress 7.1 deactivate_plugins() unsets the retired numeric slot
        // and persists the remaining keys (wp-admin/includes/plugin.php:758-850).
        // Live 5142fef1 exposed this legitimate sparse postimage at preflight.
        // Keys are storage positions, not plugin identities: preserve iteration
        // order in the observed list below without rewriting the native row.
        if (!is_array($decoded)
            || count($decoded) > self::MAX_ACTIVE_PLUGINS
            || array_filter(array_keys($decoded), static fn($key): bool => !is_int($key) || $key < 0) !== []) {
            throw new \RuntimeException('wprism: active_plugins option is malformed or oversized');
        }
        $active = [];
        $seen = [];
        foreach ($decoded as $plugin) {
            if (!is_string($plugin) || isset($seen[$plugin])) {
                throw new \RuntimeException('wprism: active_plugins option is malformed');
            }
            self::assert_plugin_identity($plugin);
            $seen[$plugin] = true;
            $active[] = $plugin;
        }
        return $active;
    }

    private static function theme_option(string $raw, string $name): string {
        if ($raw === '' || strlen($raw) > 512) {
            throw new \RuntimeException("wprism: $name option is malformed");
        }
        $decoded = PlainData::decode($raw, "$name option");
        if (!is_string($decoded)
            || !hash_equals($raw, $decoded)
            || !PathSafety::safe_component($raw)) {
            throw new \RuntimeException("wprism: $name option is malformed");
        }
        return $raw;
    }

    /** @return list<string> */
    private static function desired_plugins(array $desired): array {
        if (!array_key_exists('active_plugins', $desired)) {
            return [];
        }
        $plugins = $desired['active_plugins'];
        if (!is_array($plugins) || !array_is_list($plugins) || count($plugins) > self::MAX_ACTIVE_PLUGINS) {
            throw new \RuntimeException('wprism: desired plugin lifecycle roster is malformed');
        }
        foreach ($plugins as $plugin) {
            if (!is_string($plugin)) {
                throw new \RuntimeException('wprism: desired plugin lifecycle roster is malformed');
            }
        }
        return $plugins;
    }

    private static function assert_plugin_identity(string $plugin): void {
        if (strlen($plugin) > 512
            || !PathSafety::safe_relative($plugin)
            || !PathSafety::plugin_main_candidate('plugins/' . $plugin)) {
            throw new \RuntimeException('wprism: plugin lifecycle identity is unsafe or noncanonical');
        }
    }

    /** @return array{bool,string} */
    private static function plugin_header(string $plugin): array {
        if (!defined('WP_PLUGIN_DIR')
            || !is_string(WP_PLUGIN_DIR)
            || WP_PLUGIN_DIR === '') {
            throw new \RuntimeException('wprism: direct plugin-header observation is unavailable');
        }
        $path = rtrim(WP_PLUGIN_DIR, '/\\') . '/' . $plugin;
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return [false, ''];
        }
        $headers = CodeCompatibility::header_values($path, ['Plugin Name', 'Version']);
        if ($headers === null) {
            throw new \RuntimeException('wprism: direct plugin-header observation failed');
        }
        $name = $headers['Plugin Name'] ?? null;
        if ($name === null) {
            return [false, ''];
        }
        self::assert_header_atom($name, 'plugin name');
        $version = $headers['Version'] ?? '';
        self::assert_header_atom($version, 'plugin version');
        return [true, $version];
    }

    /** @return array{bool,string} */
    private static function theme_header(string $theme): array {
        $candidates = [];
        foreach (self::theme_roots() as $root) {
            $path = rtrim($root, '/\\') . '/' . $theme . '/style.css';
            clearstatcache(true, $path);
            if (!is_file($path)) {
                continue;
            }
            $identity = realpath($path);
            $identity = $identity === false ? $path : $identity;
            $candidates[$identity] = $path;
        }
        if ($candidates === []) {
            return [false, ''];
        }
        if (count($candidates) !== 1) {
            throw new \RuntimeException(
                'wprism: theme lifecycle identity resolves to multiple registered style.css files'
            );
        }
        $path = array_values($candidates)[0];
        $headers = CodeCompatibility::header_values($path, ['Theme Name', 'Version']);
        if ($headers === null) {
            throw new \RuntimeException('wprism: direct theme-header observation failed');
        }
        $name = $headers['Theme Name'] ?? null;
        if ($name === null) {
            return [false, ''];
        }
        self::assert_header_atom($name, 'theme name');
        $version = $headers['Version'] ?? '';
        self::assert_header_atom($version, 'theme version');
        return [true, $version];
    }

    /** @return list<string> */
    private static function theme_roots(): array {
        if (!defined('WP_CONTENT_DIR')
            || !is_string(WP_CONTENT_DIR)
            || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('wprism: direct theme-header observation is unavailable');
        }
        $roots = [rtrim(WP_CONTENT_DIR, '/\\') . '/themes'];
        $registered = $GLOBALS['wp_theme_directories'] ?? [];
        if (!is_array($registered) || count($registered) > self::MAX_THEME_ROOTS) {
            throw new \RuntimeException('wprism: registered theme-root roster is malformed or oversized');
        }
        foreach ($registered as $root) {
            if (!is_string($root)
                || $root === ''
                || strlen($root) > 4096
                || str_contains($root, "\0")) {
                throw new \RuntimeException('wprism: registered theme-root roster is malformed');
            }
            $roots[] = rtrim($root, '/\\');
        }
        $unique = [];
        foreach ($roots as $root) {
            $real = realpath($root);
            $key = $real === false ? $root : $real;
            $unique[$key] = $root;
        }
        $roots = array_values($unique);
        sort($roots, SORT_STRING);
        return $roots;
    }

    private static function assert_header_atom(string $value, string $label): void {
        if (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \RuntimeException("wprism: $label header is malformed");
        }
    }

    private static function canonical_size(mixed $value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($integer) && $integer >= 0 ? $integer : null;
    }

    private static function canonical_sha256(mixed $value): ?string {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1
            ? $value
            : null;
    }
}
