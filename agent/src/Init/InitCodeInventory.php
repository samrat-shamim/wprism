<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Code/Code.php';
require_once __DIR__ . '/../Code/CodeCompatibility.php';
require_once __DIR__ . '/../Code/CodeSourceLock.php';
require_once __DIR__ . '/../Kernel/Secrets.php';

/** Read-only inventory and credential classification for the initial code baseline. */
final class InitCodeInventory {
    /** @return array<string,mixed> */
    public static function probe(array $activePlugins, string $template, string $stylesheet): array {
        $roots = [
            'content' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
            'mu_plugins' => defined('WPRISM_CONTROL_PLANE') && WPRISM_CONTROL_PLANE === true
                && defined('WPRISM_CONTROL_WPMU_PLUGIN_DIR')
                ? WPRISM_CONTROL_WPMU_PLUGIN_DIR
                : (defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : null),
            'plugins' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
            'themes' => function_exists('get_theme_root') ? get_theme_root() : null,
        ];
        $blockers = [];
        try {
            Code::assert_initial_capture_layout();
        } catch (\Throwable $error) {
            $blockers[] = [
                'code' => 'unsupported_code_layout',
                'extension' => 'wp-content',
                'kind' => 'code',
                'reason' => $error->getMessage(),
                'remediation' => 'use standard non-symlinked plugins, themes, and mu-plugins roots or initialize state separately',
            ];
        }

        $components = ['plugins' => [], 'themes' => []];
        foreach ($activePlugins as $plugin) {
            $component = explode('/', (string) $plugin, 2)[0];
            if (!self::safeIdentifier($component)) {
                $blockers[] = [
                    'code' => 'unsafe_plugin_path', 'extension' => (string) $plugin, 'kind' => 'code',
                    'reason' => 'the active plugin basename does not resolve to one safe component',
                    'remediation' => 'restore the plugin under a standard safe plugin basename',
                ];
                continue;
            }
            $components['plugins'][] = $component;
        }
        foreach (array_values(array_unique(array_filter([$template, $stylesheet]))) as $theme) {
            if (!self::safeIdentifier($theme)) {
                $blockers[] = [
                    'code' => 'unsafe_theme_path', 'extension' => $theme, 'kind' => 'code',
                    'reason' => 'the active theme slug is not one safe component',
                    'remediation' => 'restore the theme under a standard safe directory slug',
                ];
                continue;
            }
            $components['themes'][] = $theme;
        }
        foreach ($components as &$list) {
            $list = array_values(array_unique($list));
            sort($list, SORT_STRING);
        }
        unset($list);

        $advisories = [];
        $inventory = self::inventory($roots, $components, $blockers, $advisories);
        $pluginRows = self::pluginInventory($activePlugins);
        return [
            'active_plugins' => $pluginRows,
            'active_theme' => ['stylesheet' => $stylesheet, 'template' => $template],
            'bytes' => $inventory['bytes'],
            'component_inventory' => self::componentInventory($roots, $components, $inventory, $pluginRows),
            'components' => $components,
            'declaration' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE],
            'files' => count($inventory['files']),
            'management' => 'managed-baseline-proposed',
            'roots' => $roots,
            'source_revision' => hash('sha256', Canon::encode($inventory['files'])),
            'blockers' => $blockers,
            'advisories' => $advisories,
        ];
    }

    /**
     * Per-component identity for the issue #3499 code-half split.
     *
     * This is READ-ONLY reporting and nothing more. The agent states what each
     * active component is and what its bytes hash to; deciding whether a
     * component can be sourced from a release archive is the HOST's job, and
     * deliberately so — a target that reached a package registry would falsify
     * the `code_release_provider` probe attestation "off-target build and
     * dependency resolution … no target Git history or registry credentials"
     * (docs/code-release-runtime.md:24-28).
     *
     * `tree_sha256` is computed through CodeSourceLock over the same rows the
     * descriptor compiler builds, so the digest the host classifies against is
     * bit-for-bit the digest the compile gate will later demand.
     *
     * @param array<string,mixed> $roots
     * @param array<string,list<string>> $components
     * @param array{files:list<array{path:string,sha256:string}>,bytes:int,component_bytes:array<string,int>} $inventory
     * @param list<array{basename:string,version:string}> $pluginRows
     * @return list<array{bytes:int,component:string,files:int,root:string,tree_sha256:string,version:string}>
     */
    private static function componentInventory(array $roots, array $components, array $inventory, array $pluginRows): array {
        $pluginVersions = [];
        foreach ($pluginRows as $row) {
            $component = explode('/', (string) $row['basename'], 2)[0];
            // First sorted basename wins: two active entries under one
            // directory are the same component and therefore one version.
            if (!isset($pluginVersions[$component])) {
                $pluginVersions[$component] = (string) $row['version'];
            }
        }
        $rows = [];
        foreach ($components as $rootName => $names) {
            if (!in_array($rootName, CodeSourceLock::ROOTS, true)) {
                continue;
            }
            foreach ($names as $name) {
                $componentRows = CodeSourceLock::component_rows($inventory['files'], $rootName, $name);
                if ($componentRows === []) {
                    continue;
                }
                $version = $rootName === 'plugins'
                    ? ($pluginVersions[$name] ?? '')
                    : self::themeVersion($roots, $name);
                $rows[] = [
                    'bytes' => (int) ($inventory['component_bytes'][$rootName . '/' . $name] ?? 0),
                    'component' => $name,
                    'files' => count($componentRows),
                    'root' => $rootName,
                    'tree_sha256' => CodeSourceLock::tree_sha256($componentRows),
                    'version' => $version,
                ];
            }
        }
        usort($rows, static fn(array $a, array $b): int =>
            [$a['root'], $a['component']] <=> [$b['root'], $b['component']]);
        return $rows;
    }

    /**
     * A theme's `Version:` header, read straight from style.css rather than
     * through wp_get_theme(): the same 8KB header reader the descriptor
     * compiler already applies to every payload theme
     * (agent/src/Code/CodeDescriptorCompiler.php:147-152), so the version the
     * proposal reports is the version the payload declares.
     *
     * @param array<string,mixed> $roots
     */
    private static function themeVersion(array $roots, string $slug): string {
        $root = $roots['themes'] ?? null;
        if (!is_string($root) || $root === '') {
            return '';
        }
        $style = rtrim($root, '/') . '/' . $slug . '/style.css';
        if (is_link($style) || !is_file($style)) {
            return '';
        }
        return (string) (CodeCompatibility::header_value($style, 'Version') ?? '');
    }

    /**
     * @param array<string,mixed> $roots
     * @param array<string,list<string>> $components
     * @param list<array<string,string>> $blockers
     * @param ?list<array<string,string>> $advisories the non-blocking findings
     *        (a JWT inside a code file — see inventoryFile()); null when the
     *        caller re-walks only to prove the tree unchanged (InitCodeBaseline)
     * @return array{files:list<array{path:string,sha256:string}>,bytes:int,component_bytes:array<string,int>}
     */
    public static function inventory(array $roots, array $components, array &$blockers, ?array &$advisories = null): array {
        $files = [];
        $bytes = 0;
        // issue #3499: per-component byte totals, accumulated from the ONE walk
        // this method already performs. Summing filesize() again per component
        // would be a second full stat pass over a payload measured at 8,918
        // files -- the exact cost issue #3421/issue #3425 removed from this path.
        $componentBytes = [];
        foreach ($components as $rootName => $names) {
            $root = $roots[$rootName] ?? null;
            if (!is_string($root) || $root === '') {
                $blockers[] = [
                    'code' => 'code_root_unavailable', 'extension' => $rootName, 'kind' => 'code',
                    'reason' => "the $rootName root is unavailable", 'remediation' => 'restore the standard WordPress code root',
                ];
                continue;
            }
            foreach ($names as $name) {
                $source = rtrim($root, '/') . '/' . $name;
                $prefix = $rootName . '/' . $name;
                $before = $bytes;
                try {
                    self::inventoryPath($source, $prefix, $files, $bytes, $blockers, $advisories);
                    $componentBytes[$prefix] = $bytes - $before;
                } catch (\Throwable $error) {
                    $blockers[] = [
                        'code' => 'code_component_unreadable', 'extension' => $prefix, 'kind' => 'code',
                        'reason' => $error->getMessage(), 'remediation' => 'restore a readable regular-file component with no symlinks',
                    ];
                }
            }
        }
        usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        ksort($componentBytes, SORT_STRING);
        return ['files' => $files, 'bytes' => $bytes, 'component_bytes' => $componentBytes];
    }

    /**
     * The credential shapes that are STATED rather than blocking when found
     * inside shipped code (see inventoryFile()): a complete JWT is a public
     * software statement or a fixture far more often than a live credential.
     * One list, so the proposal's inventory and the confirm-time staged-code
     * gate (InitCodeBaseline) draw the same line.
     */
    public const ADVISORY_SECRET_LABELS = ['jwt'];

    /** secretLabel(), or null when the only finding is an advisory-tier shape. */
    public static function blockingSecretLabel(string $path): ?string {
        $label = self::secretLabel($path);

        return $label !== null && in_array($label, self::ADVISORY_SECRET_LABELS, true) ? null : $label;
    }

    /** Scan every byte without returning a credential value. */
    public static function secretLabel(string $path): ?string {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('code file could not be opened for credential scanning');
        }
        $tail = '';
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 32768);
                if ($chunk === false) {
                    throw new \RuntimeException('code file could not be read for credential scanning');
                }
                $window = $tail . $chunk;
                $label = Secrets::hard_match($window);
                if ($label !== null) {
                    return $label;
                }
                $tail = substr($window, -32768);
            }
        } finally {
            fclose($handle);
        }
        return null;
    }

    public static function safeIdentifier(string $value): bool {
        return $value !== '' && $value !== '.' && $value !== '..'
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    /** @return list<array{basename:string,version:string}> */
    private static function pluginInventory(array $activePlugins): array {
        if (!function_exists('get_plugin_data') && defined('ABSPATH')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_file($file)) require_once $file;
        }
        $rows = [];
        foreach ($activePlugins as $basename) {
            $version = '';
            $file = defined('WP_PLUGIN_DIR') ? rtrim(WP_PLUGIN_DIR, '/') . '/' . ltrim($basename, '/') : '';
            if ($file !== '' && is_file($file) && function_exists('get_plugin_data')) {
                $data = get_plugin_data($file, false, false);
                $version = is_array($data) ? (string) ($data['Version'] ?? '') : '';
            }
            $rows[] = ['basename' => $basename, 'version' => $version];
        }
        usort($rows, static fn(array $a, array $b): int => $a['basename'] <=> $b['basename']);
        return $rows;
    }

    /** @param list<array{path:string,sha256:string}> $files @param list<array<string,string>> $blockers */
    private static function inventoryPath(string $source, string $prefix, array &$files, int &$bytes, array &$blockers, ?array &$advisories = null): void {
        if (is_link($source)) {
            throw new \RuntimeException("symbolic link is not portable: $prefix");
        }
        if (is_file($source)) {
            self::inventoryFile($source, $prefix, $files, $bytes, $blockers, $advisories);
            return;
        }
        if (!is_dir($source) || !is_readable($source)) {
            throw new \RuntimeException("code component is missing or unreadable: $prefix");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = $prefix . '/' . substr($path, strlen(rtrim($source, '/')) + 1);
            if ($item->isLink()) {
                throw new \RuntimeException("symbolic link is not portable: $relative");
            }
            if ($item->isDir()) continue;
            if (!$item->isFile()) {
                throw new \RuntimeException("non-regular code entry is not portable: $relative");
            }
            self::inventoryFile($path, $relative, $files, $bytes, $blockers, $advisories);
        }
    }

    /** @param list<array{path:string,sha256:string}> $files @param list<array<string,string>> $blockers @param ?list<array<string,string>> $advisories */
    private static function inventoryFile(string $path, string $relative, array &$files, int &$bytes, array &$blockers, ?array &$advisories = null): void {
        if (!is_readable($path)) {
            throw new \RuntimeException("code file is unreadable: $relative");
        }
        $digest = hash_file('sha256', $path);
        $size = filesize($path);
        if (!is_string($digest) || $size === false) {
            throw new \RuntimeException("code file could not be hashed: $relative");
        }
        $base = strtolower(basename($relative));
        $secretLabel = self::secretLabel($path);
        if ($secretLabel !== null && in_array($secretLabel, self::ADVISORY_SECRET_LABELS, true)) {
            // A complete JWT inside SHIPPED code is, far more often than not, a
            // public artifact rather than a live credential — Yoast SEO's
            // OIDC software statement (`issuer-config.php`), id-token fixtures
            // in test trees — and a hard block here refused `wprism init` on
            // every site running that plugin (T7 grind A4). It is still named,
            // redacted, on its own advisory line, so an in-house plugin that
            // really did hard-code a bearer token is not passed over in
            // silence; the operator decides. Private keys, cloud/API tokens
            // and environment-owned config files below stay blocking: those
            // shapes are not published on purpose.
            if ($advisories !== null) {
                $advisories[] = [
                    'code' => 'jwt_in_code_file', 'extension' => $relative, 'kind' => 'code',
                    'reason' => 'a complete JWT is inside the proposed code payload; the value is redacted. Shipped code '
                        . 'carries public tokens (an OIDC software statement, an id-token fixture) far more often than '
                        . 'a live credential, so this is stated, not blocked',
                    'remediation' => 'if this token is a live credential, remove it from executable code and inject it as '
                        . 'environment-owned configuration; otherwise nothing to do',
                ];
            }
        } elseif ($base === '.env' || str_starts_with($base, '.env.') || $base === 'wp-config.php'
            || $secretLabel !== null) {
            $blockers[] = [
                'code' => 'credential_bearing_code_file', 'extension' => $relative, 'kind' => 'code',
                'reason' => $secretLabel === null
                    ? 'an environment-owned configuration file is inside the proposed code payload'
                    : "a high-confidence $secretLabel is inside the proposed code payload; the value is redacted",
                'remediation' => 'remove the credential from executable code and inject it as environment-owned configuration',
            ];
        }
        $files[] = ['path' => $relative, 'sha256' => $digest];
        $bytes += (int) $size;
    }
}
