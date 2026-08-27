<?php

declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/AdapterPackage.php';

/**
 * A closed inventory of the current flat shipped adapter library.
 *
 * The reader owns physical discovery only. It intentionally does not accept a
 * second layout, search neighboring directories, or normalize manifest data:
 * the package-layout cutover replaces it atomically after consumers have one
 * source boundary to depend on.
 */
final class AdapterLibrary
{
    private const DISPOSITIONS_DIRECTORY = 'dispositions';
    private const CAPABILITIES_DIRECTORY = 'capabilities';
    private const RUNTIME_DIRECTORIES = ['interpreters', 'providers', 'regenerators'];
    private const PLATFORM_BOUNDARY = 'capabilities/platform.json';
    private const AUTHORITIES = 'capabilities/adapter-authorities.json';
    private const REVOCATIONS = 'capabilities/adapter-revocations.json';
    private const PROFILES = 'dispositions/profiles.json';

    private string $root;
    /** @var array<string,AdapterPackage> */
    private array $packagesByName;
    private string $platformBoundaryPath;
    private string $authoritiesPath;
    private string $revocationsPath;
    private string $profilesPath;
    /** @var list<string> */
    private array $scanAnchors;
    /** @var list<string> */
    private array $scanFiles;

    /**
     * @param array<string,AdapterPackage> $packagesByName
     * @param list<string> $scanAnchors
     * @param list<string> $scanFiles
     */
    private function __construct(
        string $root,
        array $packagesByName,
        string $platformBoundaryPath,
        string $authoritiesPath,
        string $revocationsPath,
        string $profilesPath,
        array $scanAnchors,
        array $scanFiles
    ) {
        $this->root = $root;
        $this->packagesByName = $packagesByName;
        $this->platformBoundaryPath = $platformBoundaryPath;
        $this->authoritiesPath = $authoritiesPath;
        $this->revocationsPath = $revocationsPath;
        $this->profilesPath = $profilesPath;
        $this->scanAnchors = $scanAnchors;
        $this->scanFiles = $scanFiles;
    }

    public static function fromDirectory(string $directory): self
    {
        $root = self::canonicalRoot($directory);
        $directories = [self::DISPOSITIONS_DIRECTORY, self::CAPABILITIES_DIRECTORY];
        $directories = array_merge($directories, self::RUNTIME_DIRECTORIES);
        foreach ($directories as $relative) {
            self::assertDirectory($root, $root . '/' . $relative, "adapter library $relative directory");
        }

        $profiles = self::assertFile($root, $root . '/' . self::PROFILES, 'adapter disposition profiles');
        $platform = self::assertFile(
            $root,
            $root . '/' . self::PLATFORM_BOUNDARY,
            'adapter platform boundary'
        );
        $authorities = self::assertFile($root, $root . '/' . self::AUTHORITIES, 'adapter authorities');
        $revocations = $root . '/' . self::REVOCATIONS;
        if (is_link($revocations) || file_exists($revocations)) {
            self::assertFile($root, $revocations, 'adapter revocations');
        }

        $manifestPaths = self::manifestPaths($root);
        if ($manifestPaths === []) {
            throw new \RuntimeException("duo: adapter library has no manifests: $root");
        }
        $dispositionPaths = self::dispositionPaths($root, $profiles);
        $runtimePaths = [];
        foreach (self::RUNTIME_DIRECTORIES as $runtimeDirectory) {
            $runtimePaths[$runtimeDirectory] = self::runtimePaths($root, $runtimeDirectory);
        }
        self::assertCapabilityEntries($root);

        /**
         * @var array<string,array{interpreter:?string,providers:list<string>,regenerators:list<string>}> $specs
         */
        $specs = [];
        foreach ($manifestPaths as $basename => $manifestPath) {
            $manifest = Canon::decode(Canon::read_file($manifestPath));
            if (!is_array($manifest)) {
                throw new \RuntimeException("duo: adapter manifest is not a JSON object: $manifestPath");
            }
            $declaredName = $manifest['name'] ?? null;
            if (!is_string($declaredName)) {
                throw new \RuntimeException("duo: adapter manifest has no string name: $manifestPath");
            }
            self::assertName($declaredName, "adapter name declared by $manifestPath");
            if (isset($specs[$declaredName])) {
                throw new \RuntimeException("duo: duplicate adapter name $declaredName in the flat library");
            }
            if ($declaredName !== $basename) {
                throw new \RuntimeException(
                    "duo: adapter manifest basename $basename disagrees with its declared name $declaredName"
                );
            }
            $specs[$declaredName] = self::runtimeDeclarations($declaredName, $manifest);
        }

        $manifestNames = array_keys($specs);
        sort($manifestNames, SORT_STRING);
        $dispositionNames = array_keys($dispositionPaths);
        sort($dispositionNames, SORT_STRING);
        if ($manifestNames !== $dispositionNames) {
            $missing = array_values(array_diff($manifestNames, $dispositionNames));
            $orphaned = array_values(array_diff($dispositionNames, $manifestNames));
            throw new \RuntimeException(
                'duo: adapter disposition coverage disagrees with manifests; missing=[' . implode(',', $missing)
                . '], orphaned=[' . implode(',', $orphaned) . ']'
            );
        }

        $expectedRuntime = [
            'interpreters' => [],
            'providers' => [],
            'regenerators' => [],
        ];
        foreach ($specs as $name => $spec) {
            if ($spec['interpreter'] !== null) {
                self::claimRuntime($expectedRuntime['interpreters'], $spec['interpreter'], $name, 'interpreter');
            }
            foreach ($spec['providers'] as $provider) {
                self::claimRuntime($expectedRuntime['providers'], $provider, $name, 'provider');
            }
            foreach ($spec['regenerators'] as $regenerator) {
                self::claimRuntime($expectedRuntime['regenerators'], $regenerator, $name, 'regenerator');
            }
        }
        foreach (self::RUNTIME_DIRECTORIES as $runtimeDirectory) {
            self::assertRuntimeCoverage($runtimeDirectory, $expectedRuntime[$runtimeDirectory], $runtimePaths[$runtimeDirectory]);
        }

        $packages = [];
        foreach ($specs as $name => $spec) {
            $interpreter = $spec['interpreter'] === null
                ? null
                : $runtimePaths['interpreters'][$spec['interpreter']];
            $providers = [];
            foreach ($spec['providers'] as $provider) {
                $providers[$provider] = $runtimePaths['providers'][$provider];
            }
            $regenerators = [];
            foreach ($spec['regenerators'] as $regenerator) {
                $regenerators[$regenerator] = $runtimePaths['regenerators'][$regenerator];
            }
            $packages[$name] = new AdapterPackage(
                $name,
                $root,
                $manifestPaths[$name],
                $dispositionPaths[$name],
                $interpreter,
                $providers,
                $regenerators
            );
        }
        ksort($packages, SORT_STRING);

        $anchors = array_map(static fn(string $relative): string => $root . '/' . $relative, $directories);
        $anchors[] = $root;
        sort($anchors, SORT_STRING);

        $files = [$profiles, $platform, $authorities];
        if (is_file($revocations)) {
            $files[] = $revocations;
        }
        foreach ($packages as $package) {
            $files = array_merge($files, $package->shippablePaths());
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return new self($root, $packages, $platform, $authorities, $revocations, $profiles, $anchors, $files);
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<AdapterPackage> */
    public function packages(): array
    {
        return array_values($this->packagesByName);
    }

    public function package(string $name): ?AdapterPackage
    {
        self::assertName($name, 'adapter name');
        return $this->packagesByName[$name] ?? null;
    }

    public function platformBoundaryPath(): string
    {
        return $this->platformBoundaryPath;
    }

    public function authoritiesPath(): string
    {
        return $this->authoritiesPath;
    }

    public function revocationsPath(): string
    {
        return $this->revocationsPath;
    }

    public function profilesPath(): string
    {
        return $this->profilesPath;
    }

    /** @return list<string> */
    public function scanAnchors(): array
    {
        return $this->scanAnchors;
    }

    /** @return list<string> */
    public function scanFiles(): array
    {
        return $this->scanFiles;
    }

    private static function canonicalRoot(string $directory): string
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new \RuntimeException('duo: adapter library root is invalid');
        }
        if (is_link($directory)) {
            throw new \RuntimeException("duo: adapter library root may not be a symlink: $directory");
        }
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new \RuntimeException("duo: adapter library root is not a readable directory: $directory");
        }
        return rtrim($root, '/');
    }

    private static function assertDirectory(string $root, string $path, string $label): string
    {
        if (is_link($path)) {
            throw new \RuntimeException("duo: $label may not be a symlink: $path");
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_dir($canonical) || !is_readable($canonical)) {
            throw new \RuntimeException("duo: $label is not a readable directory: $path");
        }
        self::assertContained($root, $canonical, $label);
        return $canonical;
    }

    private static function assertFile(string $root, string $path, string $label): string
    {
        if (is_link($path)) {
            throw new \RuntimeException("duo: $label may not be a symlink: $path");
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical) || !is_readable($canonical)) {
            throw new \RuntimeException("duo: $label is not a readable regular file: $path");
        }
        self::assertContained($root, $canonical, $label);
        return $canonical;
    }

    private static function assertContained(string $root, string $path, string $label): void
    {
        if (!str_starts_with($path, $root . '/')) {
            throw new \RuntimeException("duo: $label escapes the adapter library root: $path");
        }
    }

    /** @return array<string,string> manifest basename => canonical path */
    private static function manifestPaths(string $root): array
    {
        $allowedDirectories = array_fill_keys(
            array_merge([self::DISPOSITIONS_DIRECTORY, self::CAPABILITIES_DIRECTORY], self::RUNTIME_DIRECTORIES),
            true
        );
        $paths = [];
        foreach (self::entries($root, 'adapter library root') as $entry) {
            $path = $root . '/' . $entry;
            if (isset($allowedDirectories[$entry])) {
                if (is_link($path) || !is_dir($path)) {
                    throw new \RuntimeException("duo: adapter library entry $entry is not its required directory");
                }
                continue;
            }
            if (is_link($path)) {
                throw new \RuntimeException("duo: adapter library entry may not be a symlink: $path");
            }
            if (!is_file($path) || !str_ends_with($entry, '.json')) {
                throw new \RuntimeException("duo: unexpected entry in adapter library root: $path");
            }
            $name = substr($entry, 0, -5);
            self::assertName($name, "adapter manifest basename at $path");
            $paths[$name] = self::assertFile($root, $path, "adapter manifest $name");
        }
        ksort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array<string,string> adapter name => canonical path */
    private static function dispositionPaths(string $root, string $profiles): array
    {
        $directory = $root . '/' . self::DISPOSITIONS_DIRECTORY;
        $paths = [];
        foreach (self::entries($directory, 'adapter dispositions directory') as $entry) {
            $path = $directory . '/' . $entry;
            if ($path === $profiles) {
                continue;
            }
            if (is_link($path)) {
                throw new \RuntimeException("duo: adapter disposition may not be a symlink: $path");
            }
            if (!is_file($path) || !str_ends_with($entry, '.json')) {
                throw new \RuntimeException("duo: unexpected adapter disposition entry: $path");
            }
            $name = substr($entry, 0, -5);
            self::assertName($name, "adapter disposition basename at $path");
            $paths[$name] = self::assertFile($root, $path, "adapter $name disposition");
        }
        ksort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array<string,string> runtime id => canonical path */
    private static function runtimePaths(string $root, string $directory): array
    {
        $base = $root . '/' . $directory;
        $paths = [];
        foreach (self::entries($base, "adapter $directory directory") as $entry) {
            $path = $base . '/' . $entry;
            if (is_link($path)) {
                throw new \RuntimeException("duo: adapter runtime may not be a symlink: $path");
            }
            if (!is_file($path) || !str_ends_with($entry, '.php')) {
                throw new \RuntimeException("duo: unexpected adapter runtime entry: $path");
            }
            $id = substr($entry, 0, -4);
            self::assertName($id, "adapter runtime basename at $path");
            $paths[$id] = self::assertFile($root, $path, "adapter runtime $directory/$id.php");
        }
        ksort($paths, SORT_STRING);
        return $paths;
    }

    private static function assertCapabilityEntries(string $root): void
    {
        $directory = $root . '/' . self::CAPABILITIES_DIRECTORY;
        $allowed = array_fill_keys(
            [basename(self::PLATFORM_BOUNDARY), basename(self::AUTHORITIES), basename(self::REVOCATIONS)],
            true
        );
        foreach (self::entries($directory, 'adapter capabilities directory') as $entry) {
            $path = $directory . '/' . $entry;
            if (!isset($allowed[$entry])) {
                throw new \RuntimeException("duo: unexpected adapter capability entry: $path");
            }
            self::assertFile($root, $path, "adapter capability $entry");
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{interpreter:?string,providers:list<string>,regenerators:list<string>}
     */
    private static function runtimeDeclarations(string $name, array $manifest): array
    {
        $interpreter = null;
        if (array_key_exists('interpreter', $manifest)) {
            if (!is_string($manifest['interpreter'])) {
                throw new \RuntimeException("duo: adapter $name interpreter must be a string");
            }
            self::assertName($manifest['interpreter'], "adapter $name interpreter id");
            $interpreter = $manifest['interpreter'];
        }

        $providers = [];
        if (array_key_exists('providers', $manifest)) {
            if (!is_array($manifest['providers']) || !array_is_list($manifest['providers'])) {
                throw new \RuntimeException("duo: adapter $name providers must be a JSON list");
            }
            foreach ($manifest['providers'] as $index => $provider) {
                if (!is_array($provider)) {
                    throw new \RuntimeException("duo: adapter $name provider $index must be a JSON object");
                }
                if (($provider['source'] ?? null) !== 'manifest') {
                    continue;
                }
                $id = $provider['id'] ?? null;
                if (!is_string($id)) {
                    throw new \RuntimeException("duo: adapter $name manifest provider $index has no string id");
                }
                self::assertName($id, "adapter $name provider id");
                if (in_array($id, $providers, true)) {
                    throw new \RuntimeException("duo: adapter $name declares provider $id more than once");
                }
                $providers[] = $id;
            }
        }

        $regenerators = [];
        if (array_key_exists('post_types', $manifest)) {
            if (!is_array($manifest['post_types'])) {
                throw new \RuntimeException("duo: adapter $name post_types must be a JSON object");
            }
            foreach ($manifest['post_types'] as $postType => $postTypePolicy) {
                if (!is_array($postTypePolicy) || !array_key_exists('regen_dependency', $postTypePolicy)) {
                    continue;
                }
                $dependency = $postTypePolicy['regen_dependency'];
                if (!is_array($dependency)) {
                    throw new \RuntimeException("duo: adapter $name post type $postType regen_dependency must be an object");
                }
                $regenerator = $dependency['regenerator'] ?? null;
                if (!is_string($regenerator)) {
                    throw new \RuntimeException(
                        "duo: adapter $name post type $postType regen_dependency has no string regenerator"
                    );
                }
                self::assertName($regenerator, "adapter $name regenerator id");
                if (!in_array($regenerator, $regenerators, true)) {
                    $regenerators[] = $regenerator;
                }
            }
        }
        sort($providers, SORT_STRING);
        sort($regenerators, SORT_STRING);
        return ['interpreter' => $interpreter, 'providers' => $providers, 'regenerators' => $regenerators];
    }

    /** @param array<string,string> $owners */
    private static function claimRuntime(array &$owners, string $id, string $name, string $kind): void
    {
        if (isset($owners[$id]) && $owners[$id] !== $name) {
            throw new \RuntimeException(
                "duo: adapter runtime $kind $id is declared by both {$owners[$id]} and $name"
            );
        }
        $owners[$id] = $name;
    }

    /**
     * @param array<string,string> $expected id => owning adapter
     * @param array<string,string> $actual id => canonical path
     */
    private static function assertRuntimeCoverage(string $directory, array $expected, array $actual): void
    {
        $missing = array_values(array_diff(array_keys($expected), array_keys($actual)));
        $undeclared = array_values(array_diff(array_keys($actual), array_keys($expected)));
        sort($missing, SORT_STRING);
        sort($undeclared, SORT_STRING);
        if ($missing !== [] || $undeclared !== []) {
            throw new \RuntimeException(
                "duo: adapter runtime coverage disagrees in $directory; missing=[" . implode(',', $missing)
                . '], undeclared=[' . implode(',', $undeclared) . ']'
            );
        }
    }

    /** @return list<string> */
    private static function entries(string $directory, string $label): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException("duo: cannot read $label: $directory");
        }
        $entries = array_values(array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
        sort($entries, SORT_STRING);
        return $entries;
    }

    private static function assertName(string $name, string $label): void
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $name) !== 1
            || preg_match('/[a-z]/D', $name) !== 1) {
            throw new \RuntimeException("duo: $label is not a canonical lowercase ASCII slug: " . var_export($name, true));
        }
    }
}
