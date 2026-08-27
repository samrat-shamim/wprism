<?php

declare(strict_types=1);

namespace Duo\Tooling;

use RuntimeException;

/**
 * Discover one adapter package's tests from its closed authoring boundary.
 *
 * No manifest or registry participates. The requested package directory is
 * the authority, and the complete tests tree is validated before a result is
 * returned so an unknown entry cannot become an empty, green work list.
 *
 * @phpstan-type TestRow array{path:string,file:string,runtime:'php'|'bash'}
 * @phpstan-type Discovery array{
 *     repository:string,
 *     package:string,
 *     adapter:string,
 *     class:'offline'|'live'|'certify'|'conformance'|'spike',
 *     tests:list<TestRow>
 * }
 */
final class AdapterPackageTestDiscovery
{
    private const DIRECTORY_MODE = 0040000;
    private const FILE_MODE = 0100000;
    private const TYPE_MODE = 0170000;

    /** @var list<'offline'|'live'|'certify'|'conformance'|'spike'> */
    private const CLASSES = ['offline', 'live', 'certify', 'conformance', 'spike'];

    /**
     * @return Discovery
     */
    public static function discover(string $repoRoot, string $slug, string $class = 'offline'): array
    {
        if (!self::isSlug($slug)) {
            throw new RuntimeException("Adapter package slug is not canonical: $slug");
        }
        if (!in_array($class, self::CLASSES, true)) {
            throw new RuntimeException("Unknown adapter test class: $class");
        }

        $repository = self::root($repoRoot);
        $packages = $repository . '/adapter-packages';
        self::ordinaryDirectory($packages, $repository, 'adapter package root');
        $package = $packages . '/' . $slug;
        if (!self::nodeExists($package)) {
            throw new RuntimeException("Adapter package '$slug' is missing: $package");
        }
        self::ordinaryDirectory($package, $repository, "adapter package '$slug'");

        $testsRoot = $package . '/tests';
        if (!self::nodeExists($testsRoot)) {
            throw new RuntimeException("Adapter package '$slug' has no tests directory: $testsRoot");
        }
        self::ordinaryDirectory($testsRoot, $package, "tests for adapter '$slug'");

        $requested = null;
        foreach (self::entries($testsRoot) as $entry) {
            if (!in_array($entry, self::CLASSES, true)) {
                throw new RuntimeException("Unknown adapter test class entry for '$slug': tests/$entry");
            }
            $classRoot = $testsRoot . '/' . $entry;
            self::ordinaryDirectory($classRoot, $testsRoot, "tests/$entry for adapter '$slug'");
            $rows = [];
            self::walk($rows, $repository, $classRoot, $classRoot, $slug, $entry);
            if ($entry === $class) {
                $requested = $rows;
            }
        }

        if ($requested === null) {
            throw new RuntimeException("Adapter package '$slug' has no tests/$class directory");
        }
        if ($requested === []) {
            throw new RuntimeException("Adapter package '$slug' has no class-named *.php/.sh suites in tests/$class");
        }
        usort($requested, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));

        return [
            'repository' => $repository,
            'package' => $package,
            'adapter' => $slug,
            'class' => $class,
            'tests' => $requested,
        ];
    }

    /**
     * @param list<TestRow> $rows
     */
    private static function walk(
        array &$rows,
        string $repository,
        string $directory,
        string $classRoot,
        string $slug,
        string $class
    ): void {
        foreach (self::entries($directory) as $entry) {
            $path = $directory . '/' . $entry;
            $stat = @lstat($path);
            if ($stat === false) {
                throw new RuntimeException("Adapter test entry disappeared during discovery: $path");
            }
            if (is_link($path)) {
                throw new RuntimeException("Adapter test entry must not be a symbolic link: $path");
            }
            $type = $stat['mode'] & self::TYPE_MODE;
            if ($type === self::DIRECTORY_MODE) {
                if (!self::isTreeSegment($entry)) {
                    throw new RuntimeException("Adapter test directory name is not canonical: $path");
                }
                self::assertContained($path, $directory, 'adapter test directory');
                self::walk($rows, $repository, $path, $classRoot, $slug, $class);
                continue;
            }
            if ($type !== self::FILE_MODE) {
                throw new RuntimeException("Adapter test entry is not an ordinary file or directory: $path");
            }
            if ($directory === $classRoot && self::isClassAsset($entry, $class)) {
                continue;
            }
            if (!self::isTestFile($entry, $class)) {
                throw new RuntimeException(
                    "Unknown adapter test file for '$slug' in tests/$class: $path; expected a class-named *.php or *.sh suite"
                );
            }
            if (!is_readable($path)) {
                throw new RuntimeException("Adapter test file is not readable: $path");
            }
            self::assertContained($path, $directory, 'adapter test file');
            $canonical = realpath($path);
            if ($canonical === false || $canonical !== $path) {
                throw new RuntimeException("Adapter test path changed or escaped during discovery: $path");
            }
            $relative = substr($canonical, strlen($repository) + 1);
            $rows[] = [
                'path' => $relative,
                'file' => $canonical,
                'runtime' => str_ends_with($entry, '.php') ? 'php' : 'bash',
            ];
        }
    }

    private static function root(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        $root = realpath($path);
        if ($root === false) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        self::ordinaryDirectory($root, $root, 'repository root');
        return rtrim($root, '/');
    }

    private static function ordinaryDirectory(string $path, string $boundary, string $label): void
    {
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & self::TYPE_MODE) !== self::DIRECTORY_MODE || is_link($path)) {
            throw new RuntimeException("$label is not an ordinary directory: $path");
        }
        if (!is_readable($path)) {
            throw new RuntimeException("$label is not readable: $path");
        }
        self::assertContained($path, $boundary, $label);
    }

    private static function assertContained(string $path, string $boundary, string $label): void
    {
        $canonicalBoundary = realpath($boundary);
        $canonicalPath = realpath($path);
        if ($canonicalBoundary === false
            || $canonicalPath === false
            || ($canonicalPath !== $canonicalBoundary
                && !str_starts_with($canonicalPath, rtrim($canonicalBoundary, '/') . '/'))) {
            throw new RuntimeException("$label escapes its package boundary: $path");
        }
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Cannot read adapter test directory: $directory");
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        return $entries;
    }

    private static function isTestFile(string $entry, string $class): bool
    {
        $prefixes = match ($class) {
            'certify' => ['certify', 'regress'],
            'spike' => ['spike'],
            default => ['regress'],
        };
        foreach ($prefixes as $prefix) {
            if (preg_match('/^' . $prefix . '_[a-z0-9][a-z0-9._-]*\.(?:php|sh)$/D', $entry) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isClassAsset(string $entry, string $class): bool
    {
        return match ($class) {
            'certify' => $entry === 'version-matrix.sh',
            'conformance' => in_array(
                $entry,
                ['entry.json', 'seed.sh', 'capture-check.sh', 'postdeploy.sh', 'postapply.sh', 'check.sh'],
                true
            ),
            default => false,
        };
    }

    private static function isTreeSegment(string $entry): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $entry) === 1;
    }

    private static function isSlug(string $slug): bool
    {
        return preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug) === 1;
    }

    private static function nodeExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }
}
