<?php
namespace Duo;

/**
 * First-run, target-local onboarding for an existing WordPress installation.
 *
 * Discovery is deliberately read-only and produces a content-addressed plan.
 * The confirmed path recomputes that plan before writing, so an activation,
 * version, scope, or capability change between review and confirmation cannot
 * smuggle different authority into site.duo.json. The reviewed confirmation
 * publishes state and executable code as separate canonical contracts: code
 * receives its own content-addressed descriptor and completed baseline marker,
 * while state keeps its independent capture revision and ledger semantics.
 */
final class Init {
    public const FORMAT = 'duo-init-plan/v1';

    /** @return array<string,mixed> */
    public static function proposal(string $repo): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('duo: init requires a loaded WordPress database connection');
        }

        $repo = rtrim($repo, '/');
        $existing = self::existing_config($repo);
        $manifests = self::installed_manifests();
        $activePlugins = array_values(array_filter(
            (array) get_option('active_plugins', []),
            static fn($value): bool => is_string($value) && $value !== ''
        ));

        $selected = ['core'];
        $unsupported = [];
        $byPlugin = [];
        $byTheme = [];
        foreach ($manifests as $name => $manifest) {
            if (is_string($manifest['plugin'] ?? null) && $manifest['plugin'] !== '') {
                $byPlugin[(string) $manifest['plugin']][] = $name;
            }
            if (is_string($manifest['theme'] ?? null) && $manifest['theme'] !== '') {
                $byTheme[(string) $manifest['theme']][] = $name;
            }
        }
        foreach ($activePlugins as $plugin) {
            $owners = $byPlugin[$plugin] ?? [];
            if (count($owners) !== 1) {
                $unsupported[] = [
                    'code' => $owners === [] ? 'active_plugin_without_adapter' : 'ambiguous_plugin_adapter',
                    'extension' => $plugin,
                    'kind' => 'plugin',
                    'reason' => $owners === []
                        ? 'no installed manifest declares this active plugin identity'
                        : 'multiple installed manifests declare this active plugin identity',
                    'remediation' => 'install or review one versioned adapter, then rerun duo init',
                ];
                continue;
            }
            $selected[] = $owners[0];
        }

        $template = (string) get_option('template', '');
        $stylesheet = (string) get_option('stylesheet', '');
        foreach (array_values(array_unique(array_filter([$template, $stylesheet]))) as $theme) {
            $owners = $byTheme[$theme] ?? [];
            if (count($owners) > 1) {
                $unsupported[] = [
                    'code' => 'ambiguous_theme_adapter',
                    'extension' => $theme,
                    'kind' => 'theme',
                    'reason' => 'multiple installed manifests declare this active theme identity',
                    'remediation' => 'retain one versioned theme adapter, then rerun duo init',
                ];
            } elseif (count($owners) === 1) {
                $selected[] = $owners[0];
            }
        }

        if (!function_exists('get_mu_plugins') || !function_exists('get_dropins')) {
            $pluginApi = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/plugin.php' : '';
            if ($pluginApi !== '' && is_file($pluginApi)) {
                require_once $pluginApi;
            }
        }
        $muPlugins = function_exists('get_mu_plugins') ? array_keys(get_mu_plugins()) : [];
        $userMuPlugins = array_values(array_filter($muPlugins, static function ($name): bool {
            return is_string($name) && $name !== 'duo-loader.php';
        }));
        foreach ($userMuPlugins as $plugin) {
            $unsupported[] = [
                'code' => 'mu_plugin_requires_review',
                'extension' => $plugin,
                'kind' => 'mu-plugin',
                'reason' => 'must-use plugin execution is active but no portable authored-state contract can be inferred',
                'remediation' => 'review its state surfaces and install a manifest/provider before initialization',
            ];
        }
        $dropins = function_exists('get_dropins') ? array_keys(get_dropins()) : [];
        foreach ($dropins as $dropin) {
            $unsupported[] = [
                'code' => 'dropin_requires_review',
                'extension' => (string) $dropin,
                'kind' => 'drop-in',
                'reason' => 'WordPress drop-in behavior is active and cannot be classified from its filename',
                'remediation' => 'review the drop-in boundary before initialization',
            ];
        }
        $code = self::code_probe($activePlugins, $template, $stylesheet);
        $unsupported = array_merge($unsupported, $code['blockers']);
        unset($code['blockers']);

        $selected = array_values(array_unique($selected));
        sort($selected, SORT_STRING);
        if ($selected[0] !== 'core') {
            $selected = array_values(array_unique(array_merge(['core'], $selected)));
        }
        $policy = Policy::load(null, $selected);
        $capabilities = $policy->capability_report(['operation' => 'capture']);
        foreach ($capabilities['blockers'] ?? [] as $blocker) {
            $unsupported[] = [
                'code' => (string) ($blocker['code'] ?? 'capability_not_ready'),
                'extension' => (string) ($blocker['name'] ?? 'registry'),
                'kind' => 'adapter',
                'reason' => (string) ($blocker['reason'] ?? 'adapter capability is not ready'),
                'remediation' => 'install a certified compatible adapter/runtime or leave the site unmanaged',
            ];
        }

        $postTypes = ['attachment', 'page', 'post'];
        $taxonomies = ['category', 'post_tag'];
        foreach ($selected as $name) {
            $manifest = $manifests[$name] ?? null;
            if (!is_array($manifest)) {
                continue;
            }
            foreach (($manifest['post_types'] ?? []) as $postType => $rule) {
                if (is_array($rule) && ($rule['class'] ?? null) === 'authored') {
                    $postTypes[] = (string) $postType;
                }
            }
            foreach (($manifest['taxonomies'] ?? []) as $taxonomy => $rule) {
                if (is_array($rule) && ($rule['class'] ?? 'authored') === 'authored') {
                    $taxonomies[] = (string) $taxonomy;
                }
            }
        }
        $postTypes = array_values(array_unique($postTypes));
        $taxonomies = array_values(array_unique($taxonomies));
        sort($postTypes, SORT_STRING);
        sort($taxonomies, SORT_STRING);

        $resolved = RepositoryCompiler::resolved_adapters($policy);
        $pins = [];
        $adapterRows = [];
        foreach ($resolved as $row) {
            $pins[] = ['digest' => (string) $row['digest'], 'name' => (string) $row['name']];
            $adapterRows[] = [
                'digest' => (string) $row['digest'],
                'name' => (string) $row['name'],
                'plugin' => $row['plugin'] ?? null,
                'theme' => $row['theme'] ?? null,
                'status' => (string) (($row['capability']['status'] ?? null) ?: 'unsupported'),
            ];
        }

        $config = [
            'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE],
            'manifests' => $pins,
            'policy' => [
                'options' => new \stdClass(),
                'post_meta' => new \stdClass(),
                'post_types' => $postTypes,
                'taxonomies' => $taxonomies,
                'term_meta' => new \stdClass(),
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ];

        $dbVersion = (string) $wpdb->get_var('SELECT VERSION()');
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('duo: init database probe failed: ' . $wpdb->last_error);
        }
        $media = self::media_probe();
        $risks = self::risk_probe();
        if (!empty($media['unavailable'])) {
            $unsupported[] = [
                'code' => 'attachment_bytes_unavailable',
                'extension' => 'media',
                'kind' => 'media',
                'reason' => $media['unavailable'] . ' attachment(s) have neither readable local bytes nor a valid provider source',
                'remediation' => 'install an offload provider for duo_attachment_capture_source or restore the originals',
            ];
        }
        if (function_exists('is_multisite') && is_multisite()) {
            $unsupported[] = [
                'code' => 'multisite_unsupported',
                'extension' => 'wordpress',
                'kind' => 'platform',
                'reason' => 'the current repository format is single-site only',
                'remediation' => 'initialize a supported single-site target',
            ];
        }
        if ($existing['mode'] === 'owned') {
            $unsupported[] = [
                'code' => 'existing_configuration',
                'extension' => 'site.duo.json',
                'kind' => 'repository',
                'reason' => 'the repository already has a non-seed Duo configuration',
                'remediation' => 'use ordinary capture/plan workflows or move the existing repository before initialization',
            ];
        }
        if (file_exists($repo . '/' . Code::SOURCE)) {
            $unsupported[] = [
                'code' => 'existing_code_payload',
                'extension' => Code::SOURCE,
                'kind' => 'repository',
                'reason' => 'the repository already contains a code payload that init does not own',
                'remediation' => 'review or move the existing code payload before initialization',
            ];
        }

        usort($unsupported, static function (array $a, array $b): int {
            return [$a['kind'], $a['extension'], $a['code']] <=> [$b['kind'], $b['extension'], $b['code']];
        });
        $proposal = [
            'format' => self::FORMAT,
            'environment' => [
                'database' => ['access' => 'verified-read', 'server' => $dbVersion],
                'home' => (string) get_option('home', ''),
                'php' => PHP_VERSION,
                'wordpress' => (string) get_bloginfo('version'),
            ],
            'code' => $code,
            'state' => [
                'adapters' => $adapterRows,
                'baseline' => 'capture-consistent-snapshot',
                'config' => $config,
                'existing_config' => $existing['mode'],
                'media' => $media,
                'repository' => $repo,
                'risk_surfaces' => $risks,
            ],
            'unsupported' => $unsupported,
            'ready' => $unsupported === [],
        ];
        $proposal['digest'] = hash('sha256', Canon::encode($proposal));
        return $proposal;
    }

    /** @return array<string,mixed> */
    public static function confirm(string $repo, string $expectedDigest): array {
        $proposal = self::proposal($repo);
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedDigest)
            || !hash_equals((string) $proposal['digest'], $expectedDigest)) {
            throw new \RuntimeException(
                'duo: init proposal changed before confirmation; review the fresh proposal and confirm its new digest'
            );
        }
        if (!$proposal['ready']) {
            throw new \RuntimeException('duo: init proposal is not ready; resolve every reported unsupported capability first');
        }

        $repo = rtrim($repo, '/');
        if (!is_dir($repo) && !mkdir($repo, 0775, true) && !is_dir($repo)) {
            throw new \RuntimeException("duo: could not create repository directory $repo");
        }
        $siteFile = $repo . '/site.duo.json';
        $previous = is_file($siteFile) ? Canon::read_file($siteFile) : null;
        $publishedCode = false;
        $stagedCode = null;
        try {
            [$descriptor, $stagedCode] = self::capture_code($repo, $proposal['code']);
            $codeTarget = $repo . '/' . Code::SOURCE;
            $codeParent = dirname($codeTarget);
            if (!is_dir($codeParent) && !mkdir($codeParent, 0775, true) && !is_dir($codeParent)) {
                throw new \RuntimeException("duo: could not create code payload parent $codeParent");
            }
            if (!rename($stagedCode, $codeTarget)) {
                throw new \RuntimeException('duo: could not publish the verified code baseline');
            }
            $stagedCode = null;
            $publishedCode = true;
            Canon::write_file($siteFile, Canon::encode($proposal['state']['config']));
            $policy = Policy::load($repo);
            $capture = Capture::run($repo);
            $compiled = RepositoryCompiler::compile($repo, $policy);
            $codeBaseline = Code::complete_initial_baseline($repo, $compiled);
            return [
                'format' => 'duo-init-result/v1',
                'proposal_digest' => $expectedDigest,
                'baseline' => [
                    'kind' => 'state-capture',
                    'revision_hash' => $compiled->revision_hash(),
                    'rollback_note' => 'This is a state baseline, not a code-and-database rollback checkpoint.',
                ],
                'capture' => $capture,
                'code' => [
                    'descriptor' => $descriptor,
                    'management' => 'managed-baseline',
                    'revision_hash' => $descriptor['code_revision'],
                    'source' => Code::SOURCE,
                    'lifecycle' => $codeBaseline,
                ],
                'state' => ['repository' => $repo, 'site_config' => $siteFile],
                'unsupported' => [],
            ];
        } catch (\Throwable $error) {
            if (is_string($stagedCode) && file_exists($stagedCode)) {
                self::remove_tree($stagedCode);
            }
            if ($publishedCode && file_exists($repo . '/' . Code::SOURCE)) {
                self::remove_tree($repo . '/' . Code::SOURCE);
                $codeParent = dirname($repo . '/' . Code::SOURCE);
                if (is_dir($codeParent) && iterator_count(new \FilesystemIterator($codeParent)) === 0) {
                    @rmdir($codeParent);
                }
            }
            if ($previous === null) {
                @unlink($siteFile);
            } else {
                try {
                    Canon::write_file($siteFile, $previous);
                } catch (\Throwable $restoreError) {
                    throw new \RuntimeException(
                        $error->getMessage() . "\nduo: init could not restore the prior site.duo.json: " . $restoreError->getMessage(),
                        0,
                        $error
                    );
                }
            }
            throw $error;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private static function installed_manifests(): array {
        $out = [];
        foreach (glob(rtrim(Policy::manifests_dir(), '/') . '/*.json') ?: [] as $file) {
            if (basename($file) === 'dispositions.json') {
                continue;
            }
            $manifest = Canon::decode(Canon::read_file($file));
            $name = (string) ($manifest['name'] ?? basename($file, '.json'));
            $out[$name] = $manifest;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array{mode:string} */
    private static function existing_config(string $repo): array {
        $file = $repo . '/site.duo.json';
        if (!is_file($file)) {
            return ['mode' => 'absent'];
        }
        $data = Canon::decode(Canon::read_file($file));
        $seed = [
            'manifests' => ['core'],
            'policy' => [
                'options' => [], 'post_meta' => [], 'term_meta' => [],
                'post_types' => ['post', 'page', 'attachment'],
                'taxonomies' => ['category', 'post_tag'],
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ];
        return ['mode' => Canon::encode($data) === Canon::encode($seed) ? 'adoption-seed' : 'owned'];
    }

    /** @return list<array{basename:string,version:string}> */
    private static function plugin_inventory(array $activePlugins): array {
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

    /** @return array<string,mixed> */
    private static function code_probe(array $activePlugins, string $template, string $stylesheet): array {
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
            if (!self::safe_component($component)) {
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
            if (!self::safe_component($theme)) {
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

        $inventory = self::code_inventory($roots, $components, $blockers);
        return [
            'active_plugins' => self::plugin_inventory($activePlugins),
            'active_theme' => ['stylesheet' => $stylesheet, 'template' => $template],
            'bytes' => $inventory['bytes'],
            'components' => $components,
            'declaration' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE],
            'files' => count($inventory['files']),
            'management' => 'managed-baseline-proposed',
            'roots' => $roots,
            'source_revision' => hash('sha256', Canon::encode($inventory['files'])),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param array<string,mixed> $roots
     * @param array<string,list<string>> $components
     * @param list<array<string,string>> $blockers
     * @return array{files:list<array{path:string,sha256:string}>,bytes:int}
     */
    private static function code_inventory(array $roots, array $components, array &$blockers): array {
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
                    self::inventory_path($source, $prefix, $files, $bytes, $blockers);
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

    /** @param list<array{path:string,sha256:string}> $files @param list<array<string,string>> $blockers */
    private static function inventory_path(string $source, string $prefix, array &$files, int &$bytes, array &$blockers): void {
        if (is_link($source)) {
            throw new \RuntimeException("symbolic link is not portable: $prefix");
        }
        if (is_file($source)) {
            self::inventory_file($source, $prefix, $files, $bytes, $blockers);
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
            self::inventory_file($path, $relative, $files, $bytes, $blockers);
        }
    }

    /** @param list<array{path:string,sha256:string}> $files @param list<array<string,string>> $blockers */
    private static function inventory_file(string $path, string $relative, array &$files, int &$bytes, array &$blockers): void {
        if (!is_readable($path)) {
            throw new \RuntimeException("code file is unreadable: $relative");
        }
        $digest = hash_file('sha256', $path);
        $size = filesize($path);
        if (!is_string($digest) || $size === false) {
            throw new \RuntimeException("code file could not be hashed: $relative");
        }
        $base = strtolower(basename($relative));
        $head = $size <= 131072 ? file_get_contents($path) : file_get_contents($path, false, null, 0, 131072);
        if ($base === '.env' || str_starts_with($base, '.env.') || $base === 'wp-config.php'
            || (is_string($head) && preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $head))) {
            $blockers[] = [
                'code' => 'credential_bearing_code_file', 'extension' => $relative, 'kind' => 'code',
                'reason' => 'an obvious environment or private-key file is inside the proposed code payload',
                'remediation' => 'remove the credential from executable code and inject it as environment-owned configuration',
            ];
        }
        $files[] = ['path' => $relative, 'sha256' => $digest];
        $bytes += (int) $size;
    }

    /** @return array{0:array<string,mixed>,1:string} verified descriptor and unpublished staging root */
    private static function capture_code(string $repo, array $code): array {
        $blockers = [];
        $inventory = self::code_inventory((array) ($code['roots'] ?? []), (array) ($code['components'] ?? []), $blockers);
        if ($blockers !== []) {
            throw new \RuntimeException('duo: code changed into an unsupported shape after confirmation');
        }
        $revision = hash('sha256', Canon::encode($inventory['files']));
        if (!hash_equals((string) ($code['source_revision'] ?? ''), $revision)) {
            throw new \RuntimeException('duo: code changed after proposal review; rerun init and review the new digest');
        }

        $stage = $repo . '/.duo-init-code-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0775, true) && !is_dir($stage)) {
            throw new \RuntimeException("duo: could not create code capture staging directory $stage");
        }
        try {
            foreach ((array) ($code['components'] ?? []) as $rootName => $names) {
                $sourceRoot = (string) (($code['roots'][$rootName] ?? null) ?: '');
                foreach ((array) $names as $name) {
                    self::copy_code_path(
                        rtrim($sourceRoot, '/') . '/' . $name,
                        $stage . '/' . $rootName . '/' . $name
                    );
                }
            }
            $descriptor = Code::descriptor_from_source($stage);
            $copiedRevision = hash('sha256', Canon::encode($descriptor['files']));
            if (!hash_equals($revision, $copiedRevision)) {
                throw new \RuntimeException('duo: code changed while the baseline was being copied; rerun init');
            }
            return [$descriptor, $stage];
        } catch (\Throwable $error) {
            self::remove_tree($stage);
            throw $error;
        }
    }

    private static function copy_code_path(string $source, string $destination): void {
        if (is_link($source)) {
            throw new \RuntimeException("duo: refusing symbolic-link code source $source");
        }
        if (is_file($source)) {
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new \RuntimeException("duo: could not create code directory $parent");
            }
            if (!copy($source, $destination)) {
                throw new \RuntimeException("duo: could not copy code file $source");
            }
            @chmod($destination, fileperms($source) & 0777);
            return;
        }
        if (!is_dir($source)) {
            throw new \RuntimeException("duo: code source disappeared before copy: $source");
        }
        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException("duo: could not create code component $destination");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $target = $destination . '/' . substr($path, strlen(rtrim($source, '/')) + 1);
            if ($item->isLink()) {
                throw new \RuntimeException("duo: refusing symbolic-link code source $path");
            }
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("duo: could not create code directory $target");
                }
                continue;
            }
            if (!$item->isFile() || !copy($path, $target)) {
                throw new \RuntimeException("duo: could not copy regular code file $path");
            }
            @chmod($target, fileperms($path) & 0777);
        }
    }

    private static function safe_component(string $value): bool {
        return $value !== '' && $value !== '.' && $value !== '..'
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    private static function remove_tree(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** @return array{attachments:int,local:int,provider:int,unavailable:int,strategy:string} */
    private static function media_probe(): array {
        $ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'numberposts' => -1]);
        $local = 0; $provider = 0; $unavailable = 0;
        foreach ($ids as $id) {
            $path = get_attached_file((int) $id, true);
            if (is_string($path) && is_file($path) && is_readable($path)) {
                $local++;
                continue;
            }
            $source = apply_filters('duo_attachment_capture_source', null, (int) $id, $path);
            if (is_array($source)
                && ((is_string($source['path'] ?? null) && is_file($source['path']) && is_readable($source['path']))
                    || is_string($source['bytes'] ?? null))) {
                $provider++;
            } else {
                $unavailable++;
            }
        }
        $strategy = $unavailable > 0 ? 'incomplete' : ($provider > 0 ? 'local+provider' : 'local');
        return ['attachments' => count($ids), 'local' => $local, 'provider' => $provider, 'unavailable' => $unavailable, 'strategy' => $strategy];
    }

    /** @return array{options:array<string,int>,user_meta:array<string,int>,truncated:bool} */
    private static function risk_probe(): array {
        global $wpdb;
        $secretCounts = [];
        $piiCounts = [];
        $limit = 5000;
        $options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE LENGTH(option_value) <= 65536 LIMIT $limit", ARRAY_A);
        foreach ((array) $options as $row) {
            $value = (string) ($row['option_value'] ?? '');
            $label = Secrets::hard_match($value);
            if ($label === null && Secrets::suspicious((string) ($row['option_name'] ?? ''), $value)) $label = 'suspicious-name-and-shape';
            if ($label !== null) $secretCounts[$label] = ($secretCounts[$label] ?? 0) + 1;
        }
        $userMeta = $wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE LENGTH(meta_value) <= 65536 LIMIT $limit", ARRAY_A);
        foreach ((array) $userMeta as $row) {
            $value = maybe_unserialize($row['meta_value'] ?? '');
            $label = PersonalData::match_deep((string) ($row['meta_key'] ?? ''), $value);
            if ($label !== null) $piiCounts[$label] = ($piiCounts[$label] ?? 0) + 1;
        }
        ksort($secretCounts, SORT_STRING); ksort($piiCounts, SORT_STRING);
        return [
            'options' => $secretCounts,
            'user_meta' => $piiCounts,
            'truncated' => count((array) $options) === $limit || count((array) $userMeta) === $limit,
        ];
    }
}
