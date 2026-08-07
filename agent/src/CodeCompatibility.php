<?php
namespace Duo;

/**
 * Pure source-side compatibility checks for the opt-in code payload.
 *
 * This class deliberately consumes the already-compiled descriptor and the
 * resolved adapter rows without adding facts to either.  A descriptor remains
 * an opaque byte inventory; these checks are a source/manifest/state bridge
 * that is run offline and repeated by code-stage under the target lease.
 * There are no WordPress, database, or target-environment calls here.
 */
final class CodeCompatibility {
    /**
     * Return deterministic blocking diagnostics for source plugin headers and
     * the canonical active plugin dependency graph.
     *
     * @param string $source Absolute code/wp-content source root.
     * @param array<string,mixed> $descriptor Validated code descriptor.
     * @param list<array<string,mixed>> $resolvedAdapters Compiled manifest rows.
     * @param ?list<string> $activePlugins Canonical active_plugins value;
     *        null skips dependency checks for historical comparison artifacts.
     * @return list<array{severity:string,code:string,path:string,locator:string,message:string}>
     */
    public static function diagnostics(
        string $source,
        array $descriptor,
        array $resolvedAdapters,
        ?array $activePlugins = null
    ): array {
        $diagnostics = [];
        $plugins = self::plugin_rows($descriptor);

        self::version_diagnostics($source, $plugins, $resolvedAdapters, $diagnostics);
        self::theme_version_diagnostics($source, $descriptor, $resolvedAdapters, $diagnostics);

        if ($activePlugins !== null) {
            self::dependency_diagnostics($source, $plugins, $activePlugins, $diagnostics);
        }

        return $diagnostics;
    }

    /**
     * Throw one stage-friendly error for source compatibility failures.
     * RepositoryCompiler consumes diagnostics directly so its structured
     * offline payload remains unchanged.
     *
     * @param list<array<string,mixed>> $resolvedAdapters
     * @param ?list<string> $activePlugins
     */
    public static function assert_source(
        string $source,
        array $descriptor,
        array $resolvedAdapters,
        ?array $activePlugins = null
    ): void {
        $diagnostics = self::diagnostics($source, $descriptor, $resolvedAdapters, $activePlugins);
        if (!$diagnostics) {
            return;
        }
        $lines = array_map(static function (array $diagnostic): string {
            $where = $diagnostic['path']
                . (($diagnostic['locator'] ?? '') !== '' ? ':' . $diagnostic['locator'] : '');
            return '[' . $diagnostic['code'] . '] ' . $where . ' — ' . $diagnostic['message'];
        }, $diagnostics);
        throw new \RuntimeException(
            'duo: code source compatibility failed (' . count($diagnostics)
            . " blocking diagnostic(s)); no target code was staged:\n  - "
            . implode("\n  - ", $lines)
        );
    }

    /** WordPress core's plugin-file -> dependency-slug mapping. */
    public static function plugin_slug(string $plugin): string {
        if ($plugin === 'hello.php') {
            return 'hello-dolly';
        }
        return str_contains($plugin, '/')
            ? dirname($plugin)
            : str_replace('.php', '', $plugin);
    }

    /** @return array<string,array{basename:string,path:string,sha256:string}> */
    private static function plugin_rows(array $descriptor): array {
        $rows = [];
        foreach ($descriptor['plugin_main_files'] ?? [] as $row) {
            if (!is_array($row)
                || !is_string($row['basename'] ?? null)
                || !is_string($row['path'] ?? null)
                || !is_string($row['sha256'] ?? null)) {
                continue;
            }
            $rows[$row['basename']] = [
                'basename' => $row['basename'],
                'path' => $row['path'],
                'sha256' => $row['sha256'],
            ];
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }

    /**
     * Check every pinned plugin whose main file is in the payload.  A pinned
     * plugin omitted from the descriptor is handled by the existing lifecycle
     * identity gate when state wants it active; it has no source header to
     * validate here.
     *
     * @param array<string,array{basename:string,path:string,sha256:string}> $plugins
     * @param list<array<string,mixed>> $resolvedAdapters
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function version_diagnostics(
        string $source,
        array $plugins,
        array $resolvedAdapters,
        array &$diagnostics
    ): void {
        foreach ($resolvedAdapters as $adapter) {
            $plugin = $adapter['plugin'] ?? null;
            $range = $adapter['version_range'] ?? null;
            if (!is_string($plugin) || $plugin === '' || !is_array($range)) {
                continue;
            }
            $row = $plugins[$plugin] ?? null;
            if ($row === null) {
                continue;
            }
            $path = $row['path'];
            $absolute = self::source_path($source, $path);
            $version = $absolute === null ? null : self::header_value($absolute, 'Version');
            $adapterName = (string) ($adapter['name'] ?? '?');
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            if (!self::valid_range($min, $max)) {
                self::diagnostic(
                    $diagnostics,
                    'code_source_version_range_invalid',
                    $path,
                    'version_range',
                    "resolved adapter for '$plugin' has no well-formed min/max range"
                );
                continue;
            }
            if ($version === null || $version === '') {
                self::diagnostic(
                    $diagnostics,
                    'code_source_version_missing',
                    $path,
                    'Version',
                    "source plugin '$plugin' has no readable Version header for the '$adapterName' manifest"
                );
                continue;
            }
            if (!self::in_range($version, $min, $max)) {
                self::diagnostic(
                    $diagnostics,
                    'code_source_outside_version_range',
                    $path,
                    'Version',
                    "$plugin $version is outside the '$adapterName' manifest's declared version_range "
                    . ">=$min <$max"
                );
            }
        }
    }

    /**
     * Theme adapters use the same bounded Version-header contract as plugins,
     * with style.css as the source identity.  The descriptor already records
     * theme slugs and inventories style.css; no new descriptor field is
     * necessary.
     *
     * @param array<string,mixed> $descriptor
     * @param list<array<string,mixed>> $resolvedAdapters
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function theme_version_diagnostics(
        string $source,
        array $descriptor,
        array $resolvedAdapters,
        array &$diagnostics
    ): void {
        $themes = [];
        foreach ($descriptor['theme_slugs'] ?? [] as $slug) {
            if (is_string($slug) && $slug !== '') {
                $themes[$slug] = true;
            }
        }
        foreach ($resolvedAdapters as $adapter) {
            $theme = $adapter['theme'] ?? null;
            $range = $adapter['theme_version_range'] ?? null;
            if (!is_string($theme) || $theme === '' || !is_array($range) || !isset($themes[$theme])) {
                continue;
            }
            $path = 'themes/' . $theme . '/style.css';
            $absolute = self::source_path($source, $path);
            $version = $absolute === null ? null : self::header_value($absolute, 'Version');
            $adapterName = (string) ($adapter['name'] ?? '?');
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            if (!self::valid_range($min, $max)) {
                self::diagnostic(
                    $diagnostics,
                    'code_source_theme_version_range_invalid',
                    $path,
                    'theme_version_range',
                    "resolved adapter for theme '$theme' has no well-formed min/max range"
                );
                continue;
            }
            if ($version === null || $version === '') {
                self::diagnostic(
                    $diagnostics,
                    'code_source_theme_version_missing',
                    $path,
                    'Version',
                    "source theme '$theme' has no readable Version header for the '$adapterName' manifest"
                );
                continue;
            }
            if (!self::in_range($version, $min, $max)) {
                self::diagnostic(
                    $diagnostics,
                    'code_source_theme_outside_version_range',
                    $path,
                    'Version',
                    "$theme theme $version is outside the '$adapterName' manifest's declared theme_version_range "
                    . ">=$min <$max"
                );
            }
        }
    }

    /**
     * Validate the desired active set against Requires Plugins headers.
     * Provider closure is a canonical state requirement, while the order of
     * active_plugins remains authored/native WordPress state. Lifecycle code
     * performs provider-first activation separately before restoring that
     * exact desired order.
     *
     * @param array<string,array{basename:string,path:string,sha256:string}> $plugins
     * @param list<string> $activePlugins
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function dependency_diagnostics(
        string $source,
        array $plugins,
        array $activePlugins,
        array &$diagnostics
    ): void {
        $providers = [];
        $requires = [];
        $paths = [];

        foreach ($plugins as $basename => $row) {
            $slug = self::plugin_slug($basename);
            $paths[$basename] = $row['path'];
            if (isset($providers[$slug]) && $providers[$slug] !== $basename) {
                self::diagnostic(
                    $diagnostics,
                    'code_plugin_dependency_duplicate_slug',
                    $row['path'],
                    'Plugin Name',
                    "plugin dependency slug '$slug' identifies both '{$providers[$slug]}' and '$basename'"
                );
            } else {
                $providers[$slug] = $basename;
            }

            $requires[$basename] = [];
            $absolute = self::source_path($source, $row['path']);
            $raw = $absolute === null ? null : self::header_value($absolute, 'Requires Plugins');
            if ($raw === null || $raw === '') {
                continue;
            }
            $requires[$basename] = self::dependency_slugs($raw);
        }

        self::cycle_diagnostics($requires, $paths, $diagnostics);

        $positions = [];
        foreach ($activePlugins as $index => $basename) {
            if (!is_string($basename) || $basename === '') {
                continue;
            }
            if (isset($positions[$basename])) {
                self::diagnostic(
                    $diagnostics,
                    'code_plugin_active_duplicate',
                    $paths[$basename] ?? ('plugins/' . $basename),
                    'active_plugins',
                    "canonical active_plugins lists '$basename' more than once"
                );
                continue;
            }
            $positions[$basename] = $index;
        }

        $reportedMissing = [];
        foreach (array_keys($positions) as $basename) {
            if (!isset($requires[$basename])) {
                continue;
            }
            self::check_active_dependencies(
                $basename,
                $requires,
                $providers,
                $positions,
                $paths,
                [],
                $reportedMissing,
                $diagnostics
            );
        }
    }

    /**
     * @param array<string,list<string>> $requires
     * @param array<string,string> $paths
     * @param list<string> $stack
     * @param array<string,bool> $reportedMissing
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function check_active_dependencies(
        string $basename,
        array $requires,
        array $providers,
        array $positions,
        array $paths,
        array $stack,
        array &$reportedMissing,
        array &$diagnostics
    ): void {
        if (in_array($basename, $stack, true)) {
            return; // cycle_diagnostics() owns the cycle diagnostic.
        }
        $stack[] = $basename;
        foreach ($requires[$basename] ?? [] as $requiredSlug) {
            $provider = $providers[$requiredSlug] ?? null;
            $key = $basename . '|' . $requiredSlug;
            if ($provider === null) {
                if (!isset($reportedMissing[$key])) {
                    $reportedMissing[$key] = true;
                    self::diagnostic(
                        $diagnostics,
                        'code_plugin_dependency_missing',
                        $paths[$basename] ?? ('plugins/' . $basename),
                        'Requires Plugins',
                        "active plugin '$basename' requires provider '$requiredSlug', but that provider is not in the code payload"
                    );
                }
                continue;
            }
            if (!array_key_exists($provider, $positions)) {
                if (!isset($reportedMissing[$key])) {
                    $reportedMissing[$key] = true;
                    self::diagnostic(
                        $diagnostics,
                        'code_plugin_dependency_inactive',
                        $paths[$basename] ?? ('plugins/' . $basename),
                        'Requires Plugins',
                        "active plugin '$basename' requires provider '$requiredSlug' ('$provider'), but that provider is not active in canonical active_plugins"
                    );
                }
                continue;
            }
            self::check_active_dependencies(
                $provider,
                $requires,
                $providers,
                $positions,
                $paths,
                $stack,
                $reportedMissing,
                $diagnostics
            );
        }
    }

    /**
     * Detect dependency cycles across the source graph, including inactive
     * components. A cycle makes future activation/retirement ordering
     * unknowable, so compilation fails closed before any target contact.
     *
     * @param array<string,list<string>> $requires
     * @param array<string,string> $paths
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function cycle_diagnostics(array $requires, array $paths, array &$diagnostics): void {
        $colors = [];
        $reported = [];
        foreach (array_keys($requires) as $basename) {
            self::visit_cycle(
                $basename,
                $requires,
                $paths,
                $colors,
                [],
                $reported,
                $diagnostics
            );
        }
    }

    /**
     * @param array<string,list<string>> $requires
     * @param array<string,string> $paths
     * @param array<string,int> $colors
     * @param list<string> $stack
     * @param array<string,bool> $reported
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function visit_cycle(
        string $basename,
        array $requires,
        array $paths,
        array &$colors,
        array $stack,
        array &$reported,
        array &$diagnostics
    ): void {
        $color = $colors[$basename] ?? 0;
        if ($color === 2) {
            return;
        }
        if ($color === 1) {
            $start = array_search($basename, $stack, true);
            $cycle = $start === false ? [$basename] : array_slice($stack, $start);
            $cycle[] = $basename;
            $keyParts = $cycle;
            sort($keyParts, SORT_STRING);
            $key = implode('|', array_unique($keyParts));
            if (!isset($reported[$key])) {
                $reported[$key] = true;
                self::diagnostic(
                    $diagnostics,
                    'code_plugin_dependency_cycle',
                    $paths[$basename] ?? ('plugins/' . $basename),
                    'Requires Plugins',
                    'plugin dependency cycle prevents safe lifecycle ordering: ' . implode(' -> ', $cycle)
                );
            }
            return;
        }
        $colors[$basename] = 1;
        $stack[] = $basename;
        foreach ($requires[$basename] ?? [] as $requiredSlug) {
            // Unknown providers are reported only when an active dependent
            // needs them. They cannot participate in a source cycle.
            foreach ($requires as $candidate => $_candidateRequires) {
                if (self::plugin_slug($candidate) === $requiredSlug) {
                    self::visit_cycle($candidate, $requires, $paths, $colors, $stack, $reported, $diagnostics);
                    break;
                }
            }
        }
        $colors[$basename] = 2;
    }

    /**
     * Match WordPress's dependency-slug contract without consulting runtime
     * filters: trim comma-separated values, retain only lowercase standalone
     * slug segments, de-duplicate, and sort. In particular, uppercase values
     * and paths such as "vendor/provider" are ignored rather than turned into
     * false dependency diagnostics.
     *
     * @return list<string>
     */
    public static function dependency_slugs(string $raw): array {
        $slugs = [];
        foreach (explode(',', $raw) as $slug) {
            $slug = trim($slug);
            if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/mu', $slug)) {
                $slugs[] = $slug;
            }
        }
        $slugs = array_unique($slugs);
        sort($slugs);
        return $slugs;
    }

    /** @param list<array<string,mixed>> $diagnostics */
    private static function diagnostic(
        array &$diagnostics,
        string $code,
        string $path,
        string $locator,
        string $message
    ): void {
        $diagnostics[] = [
            'severity' => 'blocking',
            'code' => $code,
            'path' => $path,
            'locator' => $locator,
            'message' => $message,
        ];
    }

    private static function in_range(string $version, string $min, string $max): bool {
        return version_compare($version, $min, '>=') && version_compare($version, $max, '<');
    }

    private static function valid_range($min, $max): bool {
        return is_string($min) && $min !== ''
            && is_string($max) && $max !== ''
            && version_compare($min, $max, '<');
    }

    private static function source_path(string $source, string $relative): ?string {
        if (!self::safe_relative($relative)) {
            return null;
        }
        $path = rtrim($source, '/') . '/' . $relative;
        return is_file($path) && !is_link($path) ? $path : null;
    }

    /**
     * Read one WordPress-style header from only the first 8 KiB.
     *
     * This is the shared pure equivalent of WordPress get_file_data(); Code's
     * descriptor discovery and source compatibility checks must agree about
     * which plugin/theme files are discoverable and how values are cleaned.
     */
    public static function header_value(string $path, string $header): ?string {
        $bytes = @file_get_contents($path, false, null, 0, 8192);
        if ($bytes === false) {
            return null;
        }
        // This is the bounded get_file_data() grammar: headers may be in
        // block, line, shell, or PHP comments (including an opening <?php),
        // and values are cleaned exactly like _cleanup_header_comment().
        $bytes = str_replace("\r", "\n", $bytes);
        $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*'
            . preg_quote($header, '/') . ':(.*)$/mi';
        if (!preg_match($pattern, $bytes, $m) || !$m[1]) {
            return null;
        }
        $value = preg_replace('/\s*(?:\*\/|\?>).*/', '', (string) $m[1]);
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function safe_relative(string $path): bool {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        $parts = explode('/', $path);
        return !in_array('', $parts, true) && !in_array('.', $parts, true) && !in_array('..', $parts, true);
    }
}
