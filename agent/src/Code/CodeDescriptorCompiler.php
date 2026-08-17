<?php
namespace Duo;

// Most production/bootstrap paths load Canon before Code. A few offline
// contract fixtures deliberately install a local Duo\\Canon seam first, so
// only load the production implementation when no Canon class exists yet.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
require_once __DIR__ . '/CodeCompatibility.php';
require_once __DIR__ . '/../Kernel/PathSafety.php';

/**
 * Structured diagnostics raised while compiling a code descriptor.
 *
 * The exception lives with the compiler so descriptor consumers can load the
 * pure boundary without loading the materializer. Code.php requires this
 * file first and retains the historical class name for callers.
 */
final class CodeCompilationException extends \RuntimeException {
    /** @var list<array{severity:string,code:string,path:string,locator:string,message:string}> */
    public array $diagnostics;

    public function __construct(array $diagnostics) {
        $this->diagnostics = array_values($diagnostics);
        $lines = array_map(static function (array $d): string {
            $where = $d['path'] . (($d['locator'] ?? '') !== '' ? ':' . $d['locator'] : '');
            return '[' . $d['code'] . '] ' . $where . ' — ' . $d['message'];
        }, $this->diagnostics);
        parent::__construct(
            'duo: code payload validation failed (' . count($this->diagnostics)
            . " blocking diagnostic(s)); no target code was staged:\n  - "
            . implode("\n  - ", $lines)
        );
    }

    public function payload(): array {
        return [
            'ok' => false,
            'error' => 'code_compilation_failed',
            'diagnostics' => $this->diagnostics,
        ];
    }
}

/**
 * Stateless descriptor compiler and validator.
 *
 * This boundary owns only deterministic source inspection and descriptor
 * shape/revision validation. It never touches WordPress, the ledger, a
 * target root, or a stage. Code.php keeps the historical public methods as
 * thin facades and owns all materialization side effects.
 */
final class CodeDescriptorCompiler {
    public const DESCRIPTOR_FORMAT = 'duo-code/v1';
    public const LAYOUT = 'wp-content';
    public const SOURCE = 'code/wp-content';

    /** @var list<string> */
    private const ROOTS = ['mu-plugins', 'plugins', 'themes'];

    /** @return ?array<string,mixed> */
    public static function compile(string $repo, ?array $config): ?array {
        if ($config === null) {
            return null;
        }
        self::assert_config($config);
        return self::descriptor_from_source(rtrim($repo, '/') . '/' . self::SOURCE);
    }

    /** @return array<string,mixed> */
    public static function descriptor_from_source(string $source): array {
        $diagnostics = [];
        $source = rtrim($source, '/');
        if (is_link($source)) {
            self::diagnostic($diagnostics, 'unsafe_code_path', self::SOURCE, '', 'the code payload root is a symbolic link');
        } elseif (!is_dir($source)) {
            self::diagnostic($diagnostics, 'code_source_missing', self::SOURCE, '', 'site.duo.json opts into code materialization but code/wp-content is missing');
        }

        $files = [];
        $pluginMainFiles = [];
        $ownedRoots = [];
        $themeSlugs = [];
        /** @var array<string,?string> $themeTemplates */
        $themeTemplates = [];
        if (is_dir($source) && !is_link($source)) {
            $children = @scandir($source);
            if ($children === false) {
                self::diagnostic($diagnostics, 'unsafe_code_path', self::SOURCE, '', 'the code payload root cannot be read');
            } else {
                foreach ($children as $child) {
                    if ($child === '.' || $child === '..') {
                        continue;
                    }
                    $absolute = $source . '/' . $child;
                    if (!in_array($child, self::ROOTS, true)) {
                        self::diagnostic($diagnostics, 'unsafe_code_path', self::SOURCE . '/' . $child, '', 'the payload may contain only plugins/, themes/, and mu-plugins/');
                        continue;
                    }
                    if (is_link($absolute)) {
                        self::diagnostic($diagnostics, 'unsafe_code_path', $child, '', 'symbolic links are not valid code payload entries');
                        continue;
                    }
                    if (!is_dir($absolute)) {
                        self::diagnostic($diagnostics, 'unsafe_code_path', $child, '', 'code payload components must be directories');
                        continue;
                    }
                    $rootChildren = @scandir($absolute);
                    if ($rootChildren === false) {
                        self::diagnostic($diagnostics, 'unsafe_code_path', $child, '', 'code payload component root cannot be read');
                        continue;
                    }
                    foreach ($rootChildren as $component) {
                        if ($component === '.' || $component === '..') {
                            continue;
                        }
                        if (!PathSafety::safe_component($component)) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $child . '/' . $component, '', 'component names must be a single safe path segment');
                            continue;
                        }
                        $componentAbsolute = $absolute . '/' . $component;
                        $componentRelative = $child . '/' . $component;
                        if (is_link($componentAbsolute)) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'symbolic links are not valid code payload entries');
                            continue;
                        }
                        if ($child === 'themes' && !is_dir($componentAbsolute)) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'theme components must be directories');
                            continue;
                        }
                        if (!is_dir($componentAbsolute) && !is_file($componentAbsolute)) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'code payload entries must be regular files or directories');
                            continue;
                        }
                        if (PathSafety::reserved_path($componentRelative)) {
                            self::diagnostic($diagnostics, 'code_loader_collision', $componentRelative, '', 'payload may not own mu-plugins/duo or mu-plugins/duo-loader.php');
                            continue;
                        }
                        $ownedRoots[] = $componentRelative;
                        if ($child === 'themes') {
                            $style = $componentAbsolute . '/style.css';
                            $template = null;
                            if (is_link($style) || !is_file($style)) {
                                self::diagnostic($diagnostics, 'invalid_theme', $componentRelative, 'style.css', 'each theme must contain a regular style.css with a Theme Name header');
                            } else {
                                if (CodeCompatibility::header_value($style, 'Theme Name') === null) {
                                    self::diagnostic($diagnostics, 'invalid_theme', $componentRelative, 'style.css', 'style.css must contain a non-empty Theme Name header in its first 8KB');
                                }
                                $template = CodeCompatibility::header_value($style, 'Template');
                                if ($template !== null && !PathSafety::safe_component($template)) {
                                    self::diagnostic($diagnostics, 'invalid_theme', $componentRelative, 'style.css', 'Template header must name one safe theme directory slug in its first 8KB');
                                }
                            }
                            $themeSlugs[] = $component;
                            $themeTemplates[$component] = $template;
                        }
                        try {
                            self::walk_component($source, $child, $componentAbsolute, $files, $pluginMainFiles, $diagnostics);
                        } catch (\Throwable $t) {
                            self::diagnostic($diagnostics, 'unsafe_code_path', $componentRelative, '', 'could not safely enumerate code payload component: ' . $t->getMessage());
                        }
                    }
                }
            }
        }

        if ($diagnostics) {
            self::throw_diagnostics($diagnostics);
        }

        usort($files, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        usort($pluginMainFiles, static fn(array $a, array $b): int => $a['basename'] <=> $b['basename']);
        sort($ownedRoots, SORT_STRING);
        sort($themeSlugs, SORT_STRING);
        ksort($themeTemplates, SORT_STRING);

        $base = [
            'format' => self::DESCRIPTOR_FORMAT,
            'layout' => self::LAYOUT,
            'source' => self::SOURCE,
            'owned_roots' => $ownedRoots,
            'files' => $files,
            'plugin_main_files' => $pluginMainFiles,
            'theme_slugs' => $themeSlugs,
            'theme_templates' => $themeTemplates,
        ];
        $base['code_revision'] = self::revision_for($base);
        self::assert_descriptor($base);
        return $base;
    }

    public static function assert_config(array $config): void {
        $keys = array_keys($config);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'layout', 'source']
            || $config['format'] !== 1
            || $config['layout'] !== self::LAYOUT
            || $config['source'] !== self::SOURCE) {
            throw new \RuntimeException(
                'duo: site.duo.json code must contain exactly '
                . '{"format":1,"layout":"wp-content","source":"code/wp-content"}'
            );
        }
    }

    public static function assert_descriptor(array $descriptor): void {
        $legacyRequired = ['code_revision', 'files', 'format', 'layout', 'owned_roots', 'plugin_main_files', 'source', 'theme_slugs'];
        $currentRequired = [...$legacyRequired, 'theme_templates'];
        $keys = array_keys($descriptor);
        sort($keys, SORT_STRING);
        $hasThemeTemplates = array_key_exists('theme_templates', $descriptor);
        $expected = $hasThemeTemplates ? $currentRequired : $legacyRequired;
        sort($expected, SORT_STRING);
        if ($keys !== $expected
            || $descriptor['format'] !== self::DESCRIPTOR_FORMAT
            || $descriptor['layout'] !== self::LAYOUT
            || $descriptor['source'] !== self::SOURCE
            || !is_array($descriptor['owned_roots']) || !array_is_list($descriptor['owned_roots'])
            || !is_array($descriptor['files']) || !array_is_list($descriptor['files'])
            || !is_array($descriptor['plugin_main_files']) || !array_is_list($descriptor['plugin_main_files'])
            || !is_array($descriptor['theme_slugs']) || !array_is_list($descriptor['theme_slugs'])
            || ($hasThemeTemplates && !is_array($descriptor['theme_templates']))
            || !preg_match('/^[0-9a-f]{64}$/', (string) $descriptor['code_revision'])) {
            throw new \RuntimeException('duo: compiled code descriptor has an unsupported or malformed shape');
        }

        $ownedRoots = [];
        foreach ($descriptor['owned_roots'] as $i => $root) {
            if (!is_string($root) || !PathSafety::safe_component_root($root, self::ROOTS) || PathSafety::reserved_path($root)) {
                throw new \RuntimeException("duo: compiled code descriptor owned_roots[$i] is malformed");
            }
            if (isset($ownedRoots[$root])) {
                throw new \RuntimeException("duo: compiled code descriptor contains duplicate owned root '$root'");
            }
            $ownedRoots[$root] = true;
        }
        $sortedRoots = array_keys($ownedRoots);
        $expectedRoots = $sortedRoots;
        sort($expectedRoots, SORT_STRING);
        if ($descriptor['owned_roots'] !== $expectedRoots) {
            throw new \RuntimeException('duo: compiled code descriptor owned_roots are not deterministically sorted');
        }

        $files = [];
        $fileRows = [];
        foreach ($descriptor['files'] as $i => $row) {
            if (!is_array($row) || array_keys($row) !== ['path', 'sha256']
                || !PathSafety::safe_relative((string) ($row['path'] ?? ''))
                || !preg_match('/^[0-9a-f]{64}$/', (string) ($row['sha256'] ?? ''))) {
                throw new \RuntimeException("duo: compiled code descriptor files[$i] is malformed");
            }
            $path = (string) $row['path'];
            if (isset($files[$path])) {
                throw new \RuntimeException("duo: compiled code descriptor contains duplicate file '$path'");
            }
            $files[$path] = (string) $row['sha256'];
            $fileRows[] = $path;
            if (!PathSafety::owned_path($path, $ownedRoots)) {
                throw new \RuntimeException("duo: compiled code descriptor contains file outside owned roots '$path'");
            }
            if (PathSafety::reserved_path($path)) {
                throw new \RuntimeException("duo: compiled code descriptor collides with the Duo loader '$path'");
            }
        }
        $expectedFileRows = $fileRows;
        sort($expectedFileRows, SORT_STRING);
        if ($fileRows !== $expectedFileRows) {
            throw new \RuntimeException('duo: compiled code descriptor files are not deterministically sorted');
        }
        $pluginSeen = [];
        foreach ($descriptor['plugin_main_files'] as $i => $row) {
            if (!is_array($row) || array_keys($row) !== ['basename', 'path', 'sha256']
                || !PathSafety::safe_relative((string) ($row['basename'] ?? ''))
                || !str_starts_with((string) ($row['path'] ?? ''), 'plugins/')
                || !preg_match('/^[0-9a-f]{64}$/', (string) ($row['sha256'] ?? ''))) {
                throw new \RuntimeException("duo: compiled code descriptor plugin_main_files[$i] is malformed");
            }
            $basename = (string) $row['basename'];
            $path = (string) $row['path'];
            if (!PathSafety::plugin_main_candidate($path)
                || $basename !== substr($path, strlen('plugins/'))
                || isset($pluginSeen[$basename])
                || !isset($files[$path])
                || !PathSafety::owned_path($path, $ownedRoots)) {
                throw new \RuntimeException("duo: compiled code descriptor plugin_main_files[$i] is inconsistent");
            }
            $pluginSeen[$basename] = true;
            if (!hash_equals($files[$path], (string) $row['sha256'])) {
                throw new \RuntimeException("duo: compiled code descriptor plugin_main_files[$i] hash disagrees with files inventory");
            }
        }
        $pluginBasenames = array_keys($pluginSeen);
        $expectedPluginBasenames = $pluginBasenames;
        sort($expectedPluginBasenames, SORT_STRING);
        if ($pluginBasenames !== $expectedPluginBasenames) {
            throw new \RuntimeException('duo: compiled code descriptor plugin_main_files are not deterministically sorted');
        }
        $themesSeen = [];
        foreach ($descriptor['theme_slugs'] as $i => $slug) {
            if (!is_string($slug) || $slug === '' || !PathSafety::safe_component($slug)) {
                throw new \RuntimeException("duo: compiled code descriptor theme_slugs[$i] is malformed");
            }
            if (isset($themesSeen[$slug]) || !isset($ownedRoots['themes/' . $slug])) {
                throw new \RuntimeException("duo: compiled code descriptor theme_slugs[$i] is not an owned theme component");
            }
            $style = 'themes/' . $slug . '/style.css';
            if (!isset($files[$style])) {
                throw new \RuntimeException("duo: compiled code descriptor theme '$slug' has no style.css inventory entry");
            }
            $themesSeen[$slug] = true;
        }
        $expectedThemes = array_keys($themesSeen);
        sort($expectedThemes, SORT_STRING);
        if ($descriptor['theme_slugs'] !== $expectedThemes) {
            throw new \RuntimeException('duo: compiled code descriptor theme_slugs are not deterministically sorted');
        }
        $ownedThemes = [];
        foreach (array_keys($ownedRoots) as $root) {
            if (str_starts_with($root, 'themes/')) {
                $ownedThemes[] = substr($root, strlen('themes/'));
            }
        }
        sort($ownedThemes, SORT_STRING);
        if ($descriptor['theme_slugs'] !== $ownedThemes) {
            throw new \RuntimeException('duo: compiled code descriptor theme ownership is inconsistent');
        }
        if ($hasThemeTemplates) {
            $themeTemplates = $descriptor['theme_templates'];
            if (array_keys($themeTemplates) !== $descriptor['theme_slugs']) {
                throw new \RuntimeException('duo: compiled code descriptor theme_templates must map every theme slug in deterministic order');
            }
            foreach ($themeTemplates as $slug => $template) {
                if (!is_string($slug) || !PathSafety::safe_component($slug)
                    || ($template !== null && (!is_string($template) || !PathSafety::safe_component($template)))) {
                    throw new \RuntimeException("duo: compiled code descriptor theme_templates['$slug'] is malformed");
                }
            }
        }
        $copy = $descriptor;
        $revision = (string) $copy['code_revision'];
        unset($copy['code_revision']);
        if (!hash_equals(self::revision_for($copy), $revision)) {
            throw new \RuntimeException('duo: compiled code descriptor revision does not verify');
        }
    }

    public static function revision_for(array $descriptorWithoutRevision): string {
        return hash('sha256', Canon::encode($descriptorWithoutRevision));
    }

    /** @param list<array<string,mixed>> $diagnostics */
    private static function diagnostic(array &$diagnostics, string $code, string $path, string $locator, string $message): void {
        $diagnostics[] = ['severity' => 'blocking', 'code' => $code, 'path' => $path, 'locator' => $locator, 'message' => $message];
    }

    /** @param list<array<string,mixed>> $diagnostics */
    private static function throw_diagnostics(array $diagnostics): never {
        usort($diagnostics, static fn(array $a, array $b): int => [$a['path'], $a['locator'], $a['code'], $a['message']] <=> [$b['path'], $b['locator'], $b['code'], $b['message']]);
        throw new CodeCompilationException($diagnostics);
    }

    /** @param list<array<string,mixed>> $files @param list<array<string,mixed>> $pluginMainFiles @param list<array<string,mixed>> $diagnostics */
    private static function walk_component(string $source, string $root, string $absolute, array &$files, array &$pluginMainFiles, array &$diagnostics): void {
        if (is_file($absolute)) {
            $relative = str_replace('\\', '/', substr($absolute, strlen($source) + 1));
            if (!PathSafety::safe_relative($relative) || PathSafety::reserved_path($relative)) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'code payload path is not allowed');
                return;
            }
            $hash = hash_file('sha256', $absolute);
            if ($hash === false) {
                self::diagnostic($diagnostics, 'unreadable_code_file', $relative, '', 'could not hash code payload file');
                return;
            }
            $files[] = ['path' => $relative, 'sha256' => $hash];
            if ($root === 'plugins' && PathSafety::plugin_main_candidate($relative)
                && CodeCompatibility::header_value($absolute, 'Plugin Name') !== null) {
                $pluginMainFiles[] = [
                    'basename' => substr($relative, strlen('plugins/')),
                    'path' => $relative,
                    'sha256' => $hash,
                ];
            }
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $info) {
            $full = $info->getPathname();
            $relative = str_replace('\\', '/', substr($full, strlen($source) + 1));
            if (!PathSafety::safe_relative($relative)) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'code payload path is not normalized');
                continue;
            }
            if (PathSafety::reserved_path($relative)) {
                self::diagnostic($diagnostics, 'code_loader_collision', $relative, '', 'payload may not own mu-plugins/duo or mu-plugins/duo-loader.php');
                continue;
            }
            if ($info->isLink()) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'symbolic links are not valid code payload entries');
                continue;
            }
            if ($info->isDir()) {
                continue;
            }
            if (!$info->isFile()) {
                self::diagnostic($diagnostics, 'unsafe_code_path', $relative, '', 'code payload entries must be regular files or directories');
                continue;
            }
            $hash = hash_file('sha256', $full);
            if ($hash === false) {
                self::diagnostic($diagnostics, 'unreadable_code_file', $relative, '', 'could not hash code payload file');
                continue;
            }
            $files[] = ['path' => $relative, 'sha256' => $hash];
            if ($root === 'plugins' && PathSafety::plugin_main_candidate($relative)
                && CodeCompatibility::header_value($full, 'Plugin Name') !== null) {
                $pluginMainFiles[] = [
                    'basename' => substr($relative, strlen('plugins/')),
                    'path' => $relative,
                    'sha256' => $hash,
                ];
            }
        }
    }
}
