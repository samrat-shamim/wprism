<?php

declare(strict_types=1);

namespace WPrism\Tooling;

use JsonException;
use RuntimeException;

/**
 * Produce the closed, host-side adapter-library deployment projection.
 *
 * This class only inspects source paths. The caller owns scratch allocation,
 * copying, archive construction, and publication; keeping those mutations out
 * of this planner makes the allowlist independently testable.
 */
final class AdapterPackageProjection
{
    private const DIRECTORY_MODE = 0040000;
    private const FILE_MODE = 0100000;
    private const TYPE_MODE = 0170000;

    /** @var list<string> */
    private const CAPSULE_MEMBERS = [
        'README.md',
        'evidence',
        'fixtures',
        'package',
        'tests',
    ];

    /** @var list<string> */
    private const PACKAGE_MEMBERS = [
        'disposition.json',
        'manifest.json',
        'runtime',
    ];

    /** @var list<string> */
    private const RUNTIME_KINDS = [
        'interpreters',
        'providers',
        'regenerators',
    ];

    /**
     * @return array<string,string> Absolute source path => archive-relative destination path.
     */
    public static function plan(string $repoRoot): array
    {
        $root = self::repositoryRoot($repoRoot);
        $packagesRoot = $root . '/adapter-packages';
        $platformRoot = $root . '/platform/adapter-library';
        self::assertDirectory($packagesRoot, $root, 'adapter package root');
        self::assertDirectory($platformRoot, $root, 'platform library root');

        /** @var array<string,string> $plan */
        $plan = [];
        foreach (self::entries($packagesRoot) as $slug) {
            if (!self::isSlug($slug)) {
                throw new RuntimeException(sprintf('Adapter package basename is not a canonical slug: %s', $slug));
            }

            $capsule = $packagesRoot . '/' . $slug;
            self::assertDirectory($capsule, $root, sprintf('adapter package %s', $slug));
            self::planAdapter($plan, $capsule, $slug, $root);
        }

        self::planPlatform($plan, $platformRoot, $root);

        return $plan;
    }

    /** @param array<string,string> $plan */
    private static function planAdapter(array &$plan, string $capsule, string $slug, string $root): void
    {
        self::assertAllowedMembers($capsule, self::CAPSULE_MEMBERS, sprintf('adapter package %s', $slug));

        $package = $capsule . '/package';
        self::assertDirectory($package, $root, sprintf('adapter package payload %s', $slug));
        self::assertAllowedMembers($package, self::PACKAGE_MEMBERS, sprintf('package/ for %s', $slug));

        // Authoring-only members are validated as ordinary contained nodes but
        // are deliberately never added to the deployment map.
        foreach (['tests', 'fixtures', 'evidence'] as $authoringDirectory) {
            $path = $capsule . '/' . $authoringDirectory;
            if (self::nodeExists($path)) {
                self::assertDirectory($path, $root, sprintf('%s for %s', $authoringDirectory, $slug));
                self::assertOrdinaryTree($path, $root, sprintf('%s for %s', $authoringDirectory, $slug));
            }
        }
        $readme = $capsule . '/README.md';
        if (self::nodeExists($readme)) {
            self::assertFile($readme, $root, sprintf('README for %s', $slug));
        }

        $manifestPath = $package . '/manifest.json';
        $dispositionPath = $package . '/disposition.json';
        $manifest = self::readJsonObject($manifestPath, $root, sprintf('manifest for %s', $slug));
        self::readJsonObject($dispositionPath, $root, sprintf('disposition for %s', $slug));

        if (($manifest['name'] ?? null) !== $slug) {
            throw new RuntimeException(sprintf(
                'Adapter manifest name/basename mismatch: expected %s, got %s',
                $slug,
                is_string($manifest['name'] ?? null) ? $manifest['name'] : '<missing-or-non-string>'
            ));
        }

        self::add($plan, $manifestPath, 'adapter-library/adapters/' . $slug . '/manifest.json');
        self::add($plan, $dispositionPath, 'adapter-library/adapters/' . $slug . '/disposition.json');
        self::planRuntime($plan, $package, $slug, $manifest, $root);
    }

    /**
     * @param array<string,string> $plan
     * @param array<string,mixed> $manifest
     */
    private static function planRuntime(
        array &$plan,
        string $package,
        string $slug,
        array $manifest,
        string $root
    ): void {
        $declared = self::declaredRuntime($manifest, $slug);
        $runtime = $package . '/runtime';

        if (!self::nodeExists($runtime)) {
            foreach ($declared as $kind => $names) {
                if ($names !== []) {
                    throw new RuntimeException(sprintf(
                        'Missing declared runtime PHP for %s: runtime/%s/%s.php',
                        $slug,
                        $kind,
                        array_key_first($names)
                    ));
                }
            }

            return;
        }

        self::assertDirectory($runtime, $root, sprintf('runtime for %s', $slug));
        self::assertAllowedMembers($runtime, self::RUNTIME_KINDS, sprintf('runtime for %s', $slug));

        foreach (self::RUNTIME_KINDS as $kind) {
            $kindRoot = $runtime . '/' . $kind;
            if (!self::nodeExists($kindRoot)) {
                if ($declared[$kind] !== []) {
                    throw new RuntimeException(sprintf(
                        'Missing declared runtime PHP for %s: runtime/%s/%s.php',
                        $slug,
                        $kind,
                        array_key_first($declared[$kind])
                    ));
                }

                continue;
            }

            self::assertDirectory($kindRoot, $root, sprintf('runtime/%s for %s', $kind, $slug));
            foreach (self::entries($kindRoot) as $filename) {
                $source = $kindRoot . '/' . $filename;
                self::assertFile($source, $root, sprintf('runtime member %s for %s', $filename, $slug));
                if (!str_ends_with($filename, '.php')) {
                    throw new RuntimeException(sprintf('Unknown non-PHP runtime member for %s: runtime/%s/%s', $slug, $kind, $filename));
                }

                $name = substr($filename, 0, -4);
                if (!self::isRuntimeName($name) || !isset($declared[$kind][$name])) {
                    throw new RuntimeException(sprintf('Undeclared runtime PHP for %s: runtime/%s/%s', $slug, $kind, $filename));
                }

                self::add(
                    $plan,
                    $source,
                    sprintf('adapter-library/adapters/%s/runtime/%s/%s', $slug, $kind, $filename)
                );
                unset($declared[$kind][$name]);
            }

            if ($declared[$kind] !== []) {
                throw new RuntimeException(sprintf(
                    'Missing declared runtime PHP for %s: runtime/%s/%s.php',
                    $slug,
                    $kind,
                    array_key_first($declared[$kind])
                ));
            }
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{interpreters:array<string,true>,providers:array<string,true>,regenerators:array<string,true>}
     */
    private static function declaredRuntime(array $manifest, string $slug): array
    {
        $declared = [
            'interpreters' => [],
            'providers' => [],
            'regenerators' => [],
        ];

        if (array_key_exists('interpreter', $manifest)) {
            $name = self::runtimeName($manifest['interpreter'], $slug, 'interpreter');
            $declared['interpreters'][$name] = true;
        }

        if (array_key_exists('providers', $manifest)) {
            if (!is_array($manifest['providers']) || !array_is_list($manifest['providers'])) {
                throw new RuntimeException(sprintf('Malformed provider declarations for %s', $slug));
            }

            foreach ($manifest['providers'] as $index => $provider) {
                if (!is_array($provider)) {
                    throw new RuntimeException(sprintf('Malformed provider declaration %d for %s', $index, $slug));
                }
                if (($provider['source'] ?? null) !== 'manifest') {
                    continue;
                }

                $name = self::runtimeName($provider['id'] ?? null, $slug, sprintf('provider %d', $index));
                $declared['providers'][$name] = true;
            }
        }

        if (array_key_exists('post_types', $manifest)) {
            if (!is_array($manifest['post_types'])) {
                throw new RuntimeException(sprintf('Malformed post type declarations for %s', $slug));
            }

            foreach ($manifest['post_types'] as $postType => $configuration) {
                if (!is_array($configuration) || !isset($configuration['regen_dependency'])) {
                    continue;
                }
                $dependency = $configuration['regen_dependency'];
                if (!is_array($dependency) || !array_key_exists('regenerator', $dependency)) {
                    throw new RuntimeException(sprintf('Malformed regenerator declaration for %s post type %s', $slug, (string) $postType));
                }

                $name = self::runtimeName(
                    $dependency['regenerator'],
                    $slug,
                    sprintf('regenerator for post type %s', (string) $postType)
                );
                $declared['regenerators'][$name] = true;
            }
        }

        foreach ($declared as &$names) {
            ksort($names, SORT_STRING);
        }
        unset($names);

        return $declared;
    }

    private static function runtimeName(mixed $value, string $slug, string $field): string
    {
        if (!is_string($value) || !self::isRuntimeName($value)) {
            throw new RuntimeException(sprintf('Unsafe declared runtime path in %s %s', $slug, $field));
        }

        return $value;
    }

    /** @param array<string,string> $plan */
    private static function planPlatform(array &$plan, string $platform, string $root): void
    {
        self::assertAllowedMembers($platform, ['capabilities', 'core', 'profiles.json'], 'platform library');
        $core = $platform . '/core';
        $capabilities = $platform . '/capabilities';
        self::assertDirectory($core, $root, 'platform core');
        self::assertDirectory($capabilities, $root, 'platform capabilities');
        self::assertAllowedMembers($core, ['disposition.json', 'manifest.json'], 'platform core');
        self::assertAllowedMembers(
            $capabilities,
            ['adapter-authorities.json', 'platform.json'],
            'platform capabilities'
        );

        $coreManifest = $core . '/manifest.json';
        $coreDisposition = $core . '/disposition.json';
        $profiles = $platform . '/profiles.json';
        $platformCapabilities = $capabilities . '/platform.json';
        $adapterAuthorities = $capabilities . '/adapter-authorities.json';
        $manifest = self::readJsonObject($coreManifest, $root, 'platform core manifest');
        self::readJsonObject($coreDisposition, $root, 'platform core disposition');
        self::readJsonObject($profiles, $root, 'platform profiles');
        self::readJsonObject($platformCapabilities, $root, 'platform capabilities');
        self::readJsonObject($adapterAuthorities, $root, 'platform adapter authorities');

        if (($manifest['name'] ?? null) !== 'core') {
            throw new RuntimeException('Platform core manifest name/basename mismatch: expected core');
        }

        self::add($plan, $coreManifest, 'adapter-library/platform/core/manifest.json');
        self::add($plan, $coreDisposition, 'adapter-library/platform/core/disposition.json');
        self::add($plan, $profiles, 'adapter-library/platform/profiles.json');
        self::add($plan, $platformCapabilities, 'adapter-library/platform/capabilities/platform.json');
        self::add($plan, $adapterAuthorities, 'adapter-library/platform/capabilities/adapter-authorities.json');
    }

    private static function repositoryRoot(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Repository root is empty or contains a NUL byte');
        }

        $spelling = rtrim($path, '/');
        $stat = @lstat($spelling);
        $resolved = realpath($spelling);
        if ($stat === false || $resolved === false || ($stat['mode'] & self::TYPE_MODE) !== self::DIRECTORY_MODE) {
            throw new RuntimeException(sprintf('Repository root is not an ordinary directory: %s', $path));
        }
        if (is_link($spelling)) {
            throw new RuntimeException(sprintf('Repository root must not be a symbolic link: %s', $path));
        }

        return $resolved;
    }

    /**
     * @param list<string> $allowed
     */
    private static function assertAllowedMembers(string $directory, array $allowed, string $label): void
    {
        foreach (self::entries($directory) as $entry) {
            if (!in_array($entry, $allowed, true)) {
                throw new RuntimeException(sprintf('Unknown %s member: %s', $label, $entry));
            }
        }
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException(sprintf('Cannot inspect directory: %s', $directory));
        }

        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);

        return $entries;
    }

    private static function assertOrdinaryTree(string $path, string $root, string $label): void
    {
        $stat = self::nodeStat($path, $root, $label);
        $type = $stat['mode'] & self::TYPE_MODE;
        if ($type === self::FILE_MODE) {
            return;
        }
        if ($type !== self::DIRECTORY_MODE) {
            throw new RuntimeException(sprintf('%s is not an ordinary file or directory: %s', $label, $path));
        }

        foreach (self::entries($path) as $entry) {
            self::assertOrdinaryTree($path . '/' . $entry, $root, $label);
        }
    }

    private static function assertDirectory(string $path, string $root, string $label): void
    {
        $stat = self::nodeStat($path, $root, $label);
        if (($stat['mode'] & self::TYPE_MODE) !== self::DIRECTORY_MODE) {
            throw new RuntimeException(sprintf('%s is not an ordinary directory: %s', $label, $path));
        }
    }

    private static function assertFile(string $path, string $root, string $label): void
    {
        $stat = self::nodeStat($path, $root, $label);
        if (($stat['mode'] & self::TYPE_MODE) !== self::FILE_MODE) {
            throw new RuntimeException(sprintf('%s is not an ordinary regular file: %s', $label, $path));
        }
    }

    /** @return array{mode:int} */
    private static function nodeStat(string $path, string $root, string $label): array
    {
        $stat = @lstat($path);
        if ($stat === false) {
            throw new RuntimeException(sprintf('Missing required %s: %s', $label, $path));
        }
        if (($stat['mode'] & self::TYPE_MODE) === 0120000) {
            throw new RuntimeException(sprintf('%s must not be a symbolic link: %s', $label, $path));
        }

        $resolved = realpath($path);
        if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . '/'))) {
            throw new RuntimeException(sprintf('%s escapes the repository root: %s', $label, $path));
        }

        /** @var array{mode:int} $stat */
        return $stat;
    }

    /** @return array<string,mixed> */
    private static function readJsonObject(string $path, string $root, string $label): array
    {
        self::assertFile($path, $root, $label);
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException(sprintf('Cannot read %s: %s', $label, $path));
        }

        try {
            $rootValue = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid JSON in %s: %s', $label, $path), 0, $exception);
        }
        if (!is_object($rootValue) || !is_array($decoded)) {
            throw new RuntimeException(sprintf('%s must be a JSON object: %s', $label, $path));
        }

        return $decoded;
    }

    /** @param array<string,string> $plan */
    private static function add(array &$plan, string $source, string $destination): void
    {
        if (isset($plan[$source]) || in_array($destination, $plan, true)) {
            throw new RuntimeException(sprintf('Duplicate adapter-library projection destination: %s', $destination));
        }

        $plan[$source] = $destination;
    }

    private static function nodeExists(string $path): bool
    {
        return @lstat($path) !== false;
    }

    private static function isSlug(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', $value) === 1;
    }

    private static function isRuntimeName(string $value): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $value) === 1;
    }
}
