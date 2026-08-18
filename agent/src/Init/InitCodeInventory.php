<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Code/Code.php';
require_once __DIR__ . '/../Kernel/Secrets.php';

/** Read-only inventory and credential classification for the initial code baseline. */
final class InitCodeInventory {
    /** @return array<string,mixed> */
    public static function probe(array $activePlugins, string $template, string $stylesheet): array {
        $roots = [
            'content' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
            'mu_plugins' => defined('DUO_CONTROL_PLANE') && DUO_CONTROL_PLANE === true
                && defined('DUO_CONTROL_WPMU_PLUGIN_DIR')
                ? DUO_CONTROL_WPMU_PLUGIN_DIR
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
        return [
            'active_plugins' => self::pluginInventory($activePlugins),
            'active_theme' => ['stylesheet' => $stylesheet, 'template' => $template],
            'bytes' => $inventory['bytes'],
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
     * @param array<string,mixed> $roots
     * @param array<string,list<string>> $components
     * @param list<array<string,string>> $blockers
     * @param ?list<array<string,string>> $advisories the non-blocking findings
     *        (a JWT inside a code file — see inventoryFile()); null when the
     *        caller re-walks only to prove the tree unchanged (InitCodeBaseline)
     * @return array{files:list<array{path:string,sha256:string}>,bytes:int}
     */
    public static function inventory(array $roots, array $components, array &$blockers, ?array &$advisories = null): array {
        $files = [];
        $bytes = 0;
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
                try {
                    self::inventoryPath($source, $prefix, $files, $bytes, $blockers, $advisories);
                } catch (\Throwable $error) {
                    $blockers[] = [
                        'code' => 'code_component_unreadable', 'extension' => $prefix, 'kind' => 'code',
                        'reason' => $error->getMessage(), 'remediation' => 'restore a readable regular-file component with no symlinks',
                    ];
                }
            }
        }
        usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return ['files' => $files, 'bytes' => $bytes];
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
        if ($secretLabel === 'jwt') {
            // A complete JWT inside SHIPPED code is, far more often than not, a
            // public artifact rather than a live credential — Yoast SEO's
            // OIDC software statement (`issuer-config.php`), id-token fixtures
            // in test trees — and a hard block here refused `duo init` on
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
