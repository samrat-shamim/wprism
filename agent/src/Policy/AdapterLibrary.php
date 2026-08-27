<?php

declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/AdapterPackage.php';

/** A closed physical inventory of one explicitly selected adapter library. */
final class AdapterLibrary
{
    private const DISPOSITIONS_DIRECTORY = 'dispositions';
    private const CAPABILITIES_DIRECTORY = 'capabilities';
    private const RUNTIME_DIRECTORIES = ['interpreters', 'providers', 'regenerators'];
    private const PLATFORM_BOUNDARY = 'capabilities/platform.json';
    private const AUTHORITIES = 'capabilities/adapter-authorities.json';
    private const REVOCATIONS = 'capabilities/adapter-revocations.json';
    private const PROFILES = 'dispositions/profiles.json';
    private const LOGICAL_PROFILES = 'profiles.json';
    private const LOGICAL_PACKAGE_MEMBERS = ['disposition.json', 'manifest.json', 'runtime'];
    private const LOGICAL_RUNTIME_DIRECTORIES = ['interpreters', 'providers', 'regenerators'];

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

    /** Read authoring capsules plus the separately owned platform library. */
    public static function fromSourceTree(string $directory): self
    {
        $root = self::canonicalRoot($directory);
        $packagesRoot = self::assertDirectory(
            $root,
            $root . '/adapter-packages',
            'adapter package source directory'
        );
        $platformRoot = self::assertDirectory(
            $root,
            $root . '/platform/adapter-library',
            'platform adapter library directory'
        );

        $packageRoots = [];
        foreach (self::entries($packagesRoot, 'adapter package source directory') as $slug) {
            self::assertLogicalSlug($slug, "adapter package basename at $packagesRoot/$slug");
            if (isset($packageRoots[$slug])) {
                throw new \RuntimeException("duo: duplicate adapter package $slug in the source tree");
            }
            $capsule = self::assertDirectory($root, $packagesRoot . '/' . $slug, "adapter capsule $slug");
            self::assertAllowedEntries(
                $capsule,
                ['README.md', 'evidence', 'fixtures', 'package', 'tests'],
                "adapter capsule $slug"
            );
            $packageRoots[$slug] = self::assertDirectory(
                $root,
                $capsule . '/package',
                "adapter package payload $slug"
            );
            self::assertOptionalAuthoringMembers($root, $capsule, $slug);
        }

        return self::fromLogicalLayout(
            $root,
            $packageRoots,
            $platformRoot,
            [$packagesRoot, $platformRoot]
        );
    }

    /**
     * Read the deployed agent/adapter-library projection, never a neighboring
     * source tree.
     *
     * The optional revocation path is the operator-owned control document.
     * It deliberately sits outside the replaceable agent tree so an adoption
     * cannot restore a revoked authority merely by replacing agent bytes.
     */
    public static function fromEmbeddedDirectory(string $directory, ?string $revocationsPath = null): self
    {
        $root = self::canonicalRoot($directory);
        self::assertAllowedEntries($root, ['adapters', 'platform'], 'embedded adapter library');
        $adaptersRoot = self::assertDirectory(
            $root,
            $root . '/adapters',
            'embedded adapter packages directory'
        );
        $platformRoot = self::assertDirectory(
            $root,
            $root . '/platform',
            'embedded platform adapter library directory'
        );

        $packageRoots = [];
        foreach (self::entries($adaptersRoot, 'embedded adapter packages directory') as $slug) {
            self::assertLogicalSlug($slug, "embedded adapter package basename at $adaptersRoot/$slug");
            if (isset($packageRoots[$slug])) {
                throw new \RuntimeException("duo: duplicate embedded adapter package $slug");
            }
            $packageRoots[$slug] = self::assertDirectory(
                $root,
                $adaptersRoot . '/' . $slug,
                "embedded adapter package $slug"
            );
        }

        return self::fromLogicalLayout(
            $root,
            $packageRoots,
            $platformRoot,
            [$root, $adaptersRoot, $platformRoot],
            $revocationsPath
        );
    }

    public static function fromDirectory(string $directory): self
    {
        return self::fromLegacyFlatDirectory($directory);
    }

    /** Temporary explicit reader for callers that still receive manifests/. */
    public static function fromLegacyFlatDirectory(string $directory): self
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

    /**
     * @param array<string,string> $adapterRoots adapter slug => package payload root
     * @param list<string> $initialAnchors
     */
    private static function fromLogicalLayout(
        string $root,
        array $adapterRoots,
        string $platformRoot,
        array $initialAnchors,
        ?string $operatorRevocationsPath = null
    ): self {
        self::assertAllowedEntries(
            $platformRoot,
            ['capabilities', 'core', self::LOGICAL_PROFILES],
            'platform adapter library'
        );
        $coreRoot = self::assertDirectory($root, $platformRoot . '/core', 'platform core package');
        self::assertAllowedEntries(
            $coreRoot,
            ['disposition.json', 'manifest.json'],
            'platform core package'
        );
        $capabilitiesRoot = self::assertDirectory(
            $root,
            $platformRoot . '/capabilities',
            'platform adapter capabilities directory'
        );
        self::assertAllowedEntries(
            $capabilitiesRoot,
            [basename(self::PLATFORM_BOUNDARY), basename(self::AUTHORITIES), basename(self::REVOCATIONS)],
            'platform adapter capabilities directory'
        );

        $profiles = self::assertFile(
            $root,
            $platformRoot . '/' . self::LOGICAL_PROFILES,
            'adapter disposition profiles'
        );
        $platform = self::assertFile(
            $root,
            $platformRoot . '/' . self::PLATFORM_BOUNDARY,
            'adapter platform boundary'
        );
        $authorities = self::assertFile(
            $root,
            $platformRoot . '/' . self::AUTHORITIES,
            'adapter authorities'
        );
        $platformRevocations = $platformRoot . '/' . self::REVOCATIONS;
        if ($operatorRevocationsPath !== null && self::nodeExists($platformRevocations)) {
            throw new \RuntimeException(
                'duo: embedded platform adapter library may not carry operator revocations; '
                . "the durable control path is $operatorRevocationsPath"
            );
        }
        $revocations = $operatorRevocationsPath ?? $platformRevocations;
        if ($operatorRevocationsPath === null) {
            if (self::nodeExists($revocations)) {
                self::assertFile($root, $revocations, 'adapter revocations');
            }
        } else {
            $revocations = self::externalControlPath($operatorRevocationsPath, 'adapter revocations');
        }

        if (isset($adapterRoots['core'])) {
            throw new \RuntimeException('duo: adapter package core collides with the platform-owned core package');
        }
        $adapterRoots['core'] = $coreRoot;
        ksort($adapterRoots, SORT_STRING);

        $packages = [];
        $anchors = array_merge($initialAnchors, [$coreRoot, $capabilitiesRoot, $revocations]);
        foreach ($adapterRoots as $slug => $packageRoot) {
            [$package, $packageAnchors] = self::logicalPackage($root, $slug, $packageRoot);
            if (isset($packages[$package->name()])) {
                throw new \RuntimeException("duo: duplicate adapter name {$package->name()} in the logical library");
            }
            $packages[$package->name()] = $package;
            $anchors = array_merge($anchors, $packageAnchors);
        }
        ksort($packages, SORT_STRING);

        $files = [$profiles, $platform, $authorities];
        if (is_file($revocations)) {
            $files[] = $revocations;
        }
        foreach ($packages as $package) {
            $files = array_merge($files, $package->shippablePaths());
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);
        $anchors = array_values(array_unique($anchors));
        sort($anchors, SORT_STRING);

        return new self($root, $packages, $platform, $authorities, $revocations, $profiles, $anchors, $files);
    }

    /** Validate an explicitly selected operator file without requiring it to exist yet. */
    private static function externalControlPath(string $path, string $label): string
    {
        if ($path === '' || str_contains($path, "\0") || !str_starts_with($path, '/')) {
            throw new \RuntimeException("duo: $label control path is invalid: " . var_export($path, true));
        }
        if (is_link($path)) {
            throw new \RuntimeException("duo: $label may not be a symlink: $path");
        }
        if (!file_exists($path)) {
            return $path;
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical) || !is_readable($canonical)) {
            throw new \RuntimeException("duo: $label is not a readable regular file: $path");
        }
        return $canonical;
    }

    /**
     * @return array{0:AdapterPackage,1:list<string>}
     */
    private static function logicalPackage(string $libraryRoot, string $slug, string $packageRoot): array
    {
        self::assertLogicalSlug($slug, 'adapter package name');
        self::assertAllowedEntries($packageRoot, self::LOGICAL_PACKAGE_MEMBERS, "adapter package $slug");
        $manifestPath = self::assertFile(
            $libraryRoot,
            $packageRoot . '/manifest.json',
            "adapter $slug manifest"
        );
        $dispositionPath = self::assertFile(
            $libraryRoot,
            $packageRoot . '/disposition.json',
            "adapter $slug disposition"
        );
        $manifest = Canon::decode(Canon::read_file($manifestPath));
        if (!is_array($manifest)) {
            throw new \RuntimeException("duo: adapter manifest is not a JSON object: $manifestPath");
        }
        $declaredName = $manifest['name'] ?? null;
        if (!is_string($declaredName)) {
            throw new \RuntimeException("duo: adapter manifest has no string name: $manifestPath");
        }
        self::assertLogicalSlug($declaredName, "adapter name declared by $manifestPath");
        if ($declaredName !== $slug) {
            throw new \RuntimeException(
                "duo: adapter package basename $slug disagrees with its declared name $declaredName"
            );
        }

        $spec = self::runtimeDeclarations($slug, $manifest);
        if ($spec['interpreter'] !== null) {
            self::assertRuntimeName($spec['interpreter'], "adapter $slug interpreter id");
        }
        foreach ($spec['providers'] as $provider) {
            self::assertRuntimeName($provider, "adapter $slug provider id");
        }
        foreach ($spec['regenerators'] as $regenerator) {
            self::assertRuntimeName($regenerator, "adapter $slug regenerator id");
        }

        $expected = [
            'interpreters' => $spec['interpreter'] === null ? [] : [$spec['interpreter']],
            'providers' => $spec['providers'],
            'regenerators' => $spec['regenerators'],
        ];
        $actual = ['interpreters' => [], 'providers' => [], 'regenerators' => []];
        $anchors = [$packageRoot];
        $runtimeRoot = $packageRoot . '/runtime';
        if (self::nodeExists($runtimeRoot)) {
            $runtimeRoot = self::assertDirectory($libraryRoot, $runtimeRoot, "adapter $slug runtime directory");
            self::assertAllowedEntries($runtimeRoot, self::LOGICAL_RUNTIME_DIRECTORIES, "adapter $slug runtime");
            $anchors[] = $runtimeRoot;
            foreach (self::LOGICAL_RUNTIME_DIRECTORIES as $kind) {
                $kindRoot = $runtimeRoot . '/' . $kind;
                if (!self::nodeExists($kindRoot)) {
                    continue;
                }
                $kindRoot = self::assertDirectory(
                    $libraryRoot,
                    $kindRoot,
                    "adapter $slug runtime $kind directory"
                );
                $anchors[] = $kindRoot;
                foreach (self::entries($kindRoot, "adapter $slug runtime $kind directory") as $entry) {
                    $path = $kindRoot . '/' . $entry;
                    if (is_link($path)) {
                        throw new \RuntimeException("duo: adapter runtime may not be a symlink: $path");
                    }
                    if (!is_file($path) || !str_ends_with($entry, '.php')) {
                        throw new \RuntimeException("duo: unexpected adapter runtime entry: $path");
                    }
                    $id = substr($entry, 0, -4);
                    self::assertRuntimeName($id, "adapter runtime basename at $path");
                    $actual[$kind][$id] = self::assertFile(
                        $libraryRoot,
                        $path,
                        "adapter $slug runtime $kind/$entry"
                    );
                }
                ksort($actual[$kind], SORT_STRING);
            }
        }

        foreach (self::LOGICAL_RUNTIME_DIRECTORIES as $kind) {
            $owned = array_fill_keys($expected[$kind], $slug);
            self::assertRuntimeCoverage($kind, $owned, $actual[$kind]);
        }

        $interpreter = $spec['interpreter'] === null
            ? null
            : $actual['interpreters'][$spec['interpreter']];
        $providers = [];
        foreach ($spec['providers'] as $provider) {
            $providers[$provider] = $actual['providers'][$provider];
        }
        $regenerators = [];
        foreach ($spec['regenerators'] as $regenerator) {
            $regenerators[$regenerator] = $actual['regenerators'][$regenerator];
        }

        return [
            new AdapterPackage(
                $slug,
                $packageRoot,
                $manifestPath,
                $dispositionPath,
                $interpreter,
                $providers,
                $regenerators
            ),
            $anchors,
        ];
    }

    private static function assertOptionalAuthoringMembers(string $root, string $capsule, string $slug): void
    {
        foreach (['evidence', 'fixtures', 'tests'] as $member) {
            $path = $capsule . '/' . $member;
            if (self::nodeExists($path)) {
                self::assertDirectory($root, $path, "adapter $slug authoring $member directory");
            }
        }
        $readme = $capsule . '/README.md';
        if (self::nodeExists($readme)) {
            self::assertFile($root, $readme, "adapter $slug README");
        }
    }

    /** @param list<string> $allowed */
    private static function assertAllowedEntries(string $directory, array $allowed, string $label): void
    {
        foreach (self::entries($directory, $label) as $entry) {
            if (!in_array($entry, $allowed, true)) {
                throw new \RuntimeException("duo: unexpected $label entry: $directory/$entry");
            }
        }
    }

    private static function nodeExists(string $path): bool
    {
        return is_link($path) || file_exists($path);
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

    private static function assertLogicalSlug(string $name, string $label): void
    {
        if (preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', $name) !== 1) {
            throw new \RuntimeException("duo: $label is not a canonical adapter slug: " . var_export($name, true));
        }
    }

    private static function assertRuntimeName(string $name, string $label): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $name) !== 1) {
            throw new \RuntimeException("duo: $label is not a canonical runtime name: " . var_export($name, true));
        }
    }
}
