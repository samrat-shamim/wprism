<?php
namespace Duo;

// Most production/bootstrap paths load Canon before Code. A few offline
// contract fixtures deliberately install a local Duo\\Canon seam first, so
// only load the production implementation when no Canon class exists yet.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
require_once __DIR__ . '/CodeCompatibility.php';
require_once __DIR__ . '/CodeSourceLock.php';
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
        $descriptor = self::descriptor_from_source(rtrim($repo, '/') . '/' . self::SOURCE);
        // DUO-3499: the lock is a PRECONDITION gate, never an indirection the
        // descriptor follows. descriptor_from_source() above still hashes only
        // the bytes on disk, so code_revision, artifact_hash and
        // assert_verified_staged() keep meaning exactly what they meant before
        // a lock existed; all the lock adds is a refusal when the bytes those
        // digests describe are not the bytes the repository declared.
        $lock = self::load_lock($repo, $config);
        if ($lock !== null) {
            $diagnostics = self::lock_diagnostics($repo, $lock, $descriptor);
            if ($diagnostics) {
                self::throw_diagnostics($diagnostics);
            }
        }
        return $descriptor;
    }

    /**
     * The declared lock path, or null for a format-1 (fully vendored) repo.
     *
     * @param array<string,mixed> $config
     */
    public static function lock_path(array $config): ?string {
        return ($config['format'] ?? null) === 2 ? (string) $config['lock'] : null;
    }

    /**
     * Read and validate the declared lock. A missing or malformed lock is a
     * hard failure rather than a lock-free fallback: format 2 states that this
     * repository does not carry every component's bytes, so proceeding without
     * the declaration would compile a payload nobody declared complete.
     *
     * @param array<string,mixed> $config
     * @return ?array<string,mixed>
     */
    public static function load_lock(string $repo, array $config): ?array {
        $relative = self::lock_path($config);
        if ($relative === null) {
            return null;
        }
        $path = rtrim($repo, '/') . '/' . $relative;
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException(
                "duo: site.duo.json code format 2 declares $relative, but it is not a regular file in this repository"
            );
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException("duo: $relative could not be read");
        }
        return CodeSourceLock::parse($raw);
    }

    /**
     * The four blocking, non-forceable lock diagnostics.
     *
     * They are computed from the descriptor that was just built, so they see
     * exactly the bytes the artifact would carry. None of them has a force
     * flag by design: a payload that does not match its own declaration is not
     * a preference, and DESIGN.md's posture is an honest refusal over hollow
     * coverage.
     *
     * The fourth, `code_component_undeclared`, is the one that makes "Git
     * never carries third-party code" a property the compile enforces rather
     * than a convention init follows: every plugin or theme component the
     * payload carries must be either a locked component (Git does not carry
     * it; the lock says where it comes from) or a declared first-party one
     * (Git carries it because the operator said it is the site's own code).
     * A component in neither list is exactly the "vendored by omission" shape
     * a premium plugin dropped into `code/wp-content/plugins/` would take, and
     * it is refused by name. A legacy `duo-code-lock/v1` declares no
     * first-party list, so a v1 repository that still carries such a
     * component reaches this refusal and its remedy, `duo code-classify`.
     *
     * @param array<string,mixed> $lock
     * @param array<string,mixed> $descriptor
     * @return list<array{severity:string,code:string,path:string,locator:string,message:string}>
     */
    public static function lock_diagnostics(string $repo, array $lock, array $descriptor): array {
        $diagnostics = [];
        $index = CodeSourceLock::index($lock);
        foreach ($index as $key => $entry) {
            [$root, $component] = explode('/', (string) $key, 2);
            $version = (string) ($entry['version'] ?? '');
            $declared = (string) ($entry['tree_sha256'] ?? '');
            $actual = CodeSourceLock::tree_sha256_from_descriptor($descriptor, $root, $component);
            if ($actual === null) {
                self::diagnostic(
                    $diagnostics,
                    'code_component_unresolved',
                    self::SOURCE . '/' . $key,
                    '',
                    "the lock declares $key version $version, but this repository carries none of its bytes; "
                    . 'run the materialization step documented in docs/guides/code-updates.md '
                    . '(composer install, unzip the locked release archive, or duo code-resolve) before compiling'
                );
                continue;
            }
            if (!hash_equals($declared, $actual)) {
                self::diagnostic(
                    $diagnostics,
                    'code_component_digest_mismatch',
                    self::SOURCE . '/' . $key,
                    '',
                    "the present bytes of $key hash to $actual, but the lock declares $declared for version $version; "
                    . 're-materialize the locked release, or re-lock the component with duo code-classify if these '
                    . 'bytes are the intended ones'
                );
            }
        }
        foreach (self::ignored_components($repo) as $key => $_ignored) {
            if (isset($index[$key])) {
                continue;
            }
            self::diagnostic(
                $diagnostics,
                'code_component_unlocked',
                self::SOURCE . '/' . $key,
                '',
                ".gitignore excludes $key from this repository, but the lock does not declare it, so a fresh clone "
                . 'would carry neither its bytes nor any way to obtain them; declare the component in '
                . CodeSourceLock::PATH . ' or remove its .gitignore line'
            );
        }
        $firstParty = CodeSourceLock::first_party($lock);
        foreach ((array) ($descriptor['owned_roots'] ?? []) as $owned) {
            [$root, $component] = explode('/', (string) $owned, 2) + [null, null];
            if (!in_array($root, CodeSourceLock::ROOTS, true) || !is_string($component)) {
                // mu-plugins are the site's own by construction (a user
                // mu-plugin is an init blocker, never a lockable component),
                // and anything else under the payload root is refused by
                // descriptor_from_source() before this runs.
                continue;
            }
            $key = $root . '/' . $component;
            if (isset($index[$key]) || isset($firstParty[$key])) {
                continue;
            }
            if (CodeSourceLock::tree_sha256_from_descriptor($descriptor, $root, $component) === null) {
                // An owned root with no file beneath it is not a component
                // Git carries; component_inventory() skips it for the same
                // reason and a lock can never name it.
                continue;
            }
            self::diagnostic(
                $diagnostics,
                'code_component_undeclared',
                self::SOURCE . '/' . $key,
                '',
                "this repository carries $key but the lock neither declares it as a locked component nor as "
                . 'first-party, and Git must not carry third-party code; if it is the site\'s own code, declare it '
                . 'with `duo code-classify --first-party=' . $key . '`; otherwise import its release archive on '
                . 'the host with `duo code-import <archive.zip>` and re-lock it with `duo code-classify`'
            );
        }
        return $diagnostics;
    }

    /**
     * Components the repository-root `.gitignore` excludes from Git.
     *
     * The repository root is the ONLY supported location, and that is a
     * property of the code half rather than a convention: a `.gitignore` under
     * `code/wp-content/` is refused outright by descriptor_from_source()
     * (:97-99 — the payload may contain only plugins/, themes/ and
     * mu-plugins/), and one under `code/wp-content/plugins/` is inventoried as
     * an owned component file and SHIPPED to the target. Reading only the root
     * file means the gate answers identically with no git binary and with no
     * .git directory at all.
     *
     * @return array<string,bool>
     */
    private static function ignored_components(string $repo): array {
        $path = rtrim($repo, '/') . '/.gitignore';
        if (is_link($path) || !is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        return is_string($raw) ? CodeSourceLock::ignored_components($raw) : [];
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

    /**
     * Per-component identity for one already-published `code/wp-content` tree
     * (DUO-3499): what each lockable component is, and what its bytes hash to.
     *
     * This is the repository-side twin of InitCodeInventory::probe()'s
     * `component_inventory`, which reports the same shape for a LIVE site. Both
     * derive `tree_sha256` through CodeSourceLock from the same descriptor rows,
     * so a component classified from one is the same component the compile gate
     * checks against the other. `wp duo code-inventory` publishes this, and
     * `duo code-classify` consumes it.
     *
     * @return list<array{bytes:int,component:string,files:int,root:string,tree_sha256:string,version:string}>
     */
    public static function component_inventory(string $source): array {
        $source = rtrim($source, '/');
        $descriptor = self::descriptor_from_source($source);
        $rows = [];
        foreach ((array) $descriptor['owned_roots'] as $owned) {
            [$root, $component] = explode('/', (string) $owned, 2) + [null, null];
            if (!in_array($root, CodeSourceLock::ROOTS, true) || !is_string($component)) {
                continue;
            }
            $componentRows = CodeSourceLock::component_rows((array) $descriptor['files'], $root, $component);
            if ($componentRows === []) {
                continue;
            }
            $bytes = 0;
            foreach ($componentRows as $row) {
                $size = @filesize($source . '/' . $owned . '/' . $row['path']);
                $bytes += is_int($size) ? $size : 0;
            }
            $rows[] = [
                'bytes' => $bytes,
                'component' => $component,
                'files' => count($componentRows),
                'root' => $root,
                'tree_sha256' => CodeSourceLock::tree_sha256($componentRows),
                'version' => self::component_version($source, $descriptor, $root, $component),
            ];
        }
        usort($rows, static fn(array $a, array $b): int =>
            [$a['root'], $a['component']] <=> [$b['root'], $b['component']]);
        return $rows;
    }

    /**
     * The `Version:` header the component itself declares, read through the
     * same bounded 8KB header reader every other code-half consumer uses. An
     * empty string means the component states no version, which is a fact a
     * classifier must see rather than guess around.
     *
     * @param array<string,mixed> $descriptor
     */
    private static function component_version(string $source, array $descriptor, string $root, string $component): string {
        if ($root === 'themes') {
            $style = $source . '/themes/' . $component . '/style.css';
            return is_file($style) && !is_link($style)
                ? (string) (CodeCompatibility::header_value($style, 'Version') ?? '')
                : '';
        }
        foreach ((array) $descriptor['plugin_main_files'] as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path === 'plugins/' . $component . '.php'
                || str_starts_with($path, 'plugins/' . $component . '/')) {
                $file = $source . '/' . $path;
                return is_file($file) && !is_link($file)
                    ? (string) (CodeCompatibility::header_value($file, 'Version') ?? '')
                    : '';
            }
        }
        return '';
    }

    public static function assert_config(array $config): void {
        // DUO-3499: format 2 is the split declaration. It is selected by its
        // own format integer OR by the presence of the `lock` key, so a
        // format-1 declaration that grew a stray key still reaches the
        // original refusal below with its exact historical bytes.
        if (($config['format'] ?? null) === 2 || array_key_exists('lock', $config)) {
            self::assert_config_v2($config);
            return;
        }
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

    /**
     * The split declaration. Only one lock path is legal in v1: the path is
     * part of the repository format, not an operator preference, and a second
     * spelling would mean two places a reviewer has to look for the same fact.
     *
     * @param array<string,mixed> $config
     */
    private static function assert_config_v2(array $config): void {
        $keys = array_keys($config);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'layout', 'lock', 'source']
            || $config['format'] !== 2
            || $config['layout'] !== self::LAYOUT
            || $config['lock'] !== CodeSourceLock::PATH
            || $config['source'] !== self::SOURCE) {
            throw new \RuntimeException(
                'duo: site.duo.json code format 2 must contain exactly '
                . '{"format":2,"layout":"wp-content","lock":"' . CodeSourceLock::PATH . '","source":"code/wp-content"}'
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
