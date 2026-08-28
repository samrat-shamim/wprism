<?php

declare(strict_types=1);

namespace Duo\Tooling;

use RuntimeException;

require_once __DIR__ . '/AdapterPackageTestDiscovery.php';

/** Resolve a legacy make target from convention-discovered package suites. */
final class AdapterPackageMakeTarget
{
    private const DIRECTORY_MODE = 0040000;
    private const FILE_MODE = 0100000;
    private const TYPE_MODE = 0170000;
    private const CLASSES = ['offline', 'live', 'certify', 'conformance', 'spike'];

    /**
     * @return array{
     *     adapter:string,
     *     class:'offline'|'live'|'certify'|'conformance'|'spike',
     *     path:string,
     *     file:string,
     *     runtime:'php'|'bash',
     *     package:string,
     *     repository:string
     * }
     */
    public static function resolve(string $repoRoot, string $target): array
    {
        if (preg_match('/^regress-[a-z0-9][a-z0-9.-]*$/D', $target) !== 1) {
            throw new RuntimeException("Adapter package make target is not canonical: $target");
        }
        $root = self::ordinaryRoot($repoRoot);
        $packages = $root . '/adapter-packages';
        self::ordinaryDirectory($packages, 'adapter package root');

        $matches = [];
        foreach (self::entries($packages) as $slug) {
            if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
                continue;
            }
            $package = $packages . '/' . $slug;
            if (!self::isOrdinaryDirectory($package)) {
                continue;
            }
            $tests = $package . '/tests';
            if (!self::isOrdinaryDirectory($tests)) {
                continue;
            }
            foreach (self::CLASSES as $class) {
                $classRoot = $tests . '/' . $class;
                if (!self::isOrdinaryDirectory($classRoot)) {
                    continue;
                }
                self::findTarget($matches, $root, $slug, $class, $classRoot, $target);
            }
        }

        if ($matches === []) {
            throw new RuntimeException("No adapter package suite maps to make target '$target'");
        }
        if (count($matches) !== 1) {
            $paths = array_column($matches, 'path');
            sort($paths, SORT_STRING);
            throw new RuntimeException(
                "Adapter package make target '$target' is ambiguous: " . implode(', ', $paths)
            );
        }

        $match = $matches[0];
        $discovery = AdapterPackageTestDiscovery::discover($root, $match['adapter'], $match['class']);
        foreach ($discovery['tests'] as $test) {
            if ($test['path'] !== $match['path']) {
                continue;
            }
            return [
                'adapter' => $match['adapter'],
                'class' => $match['class'],
                'path' => $test['path'],
                'file' => $test['file'],
                'runtime' => $test['runtime'],
                'package' => $discovery['package'],
                'repository' => $root,
            ];
        }

        throw new RuntimeException("Adapter package make target '$target' changed during discovery");
    }

    public static function run(string $repoRoot, string $target): int
    {
        $test = self::resolve($repoRoot, $target);
        $command = $test['runtime'] === 'php'
            ? [PHP_BINARY, $test['file']]
            : ['bash', $test['file']];
        $process = @proc_open(
            $command,
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $test['repository']
        );
        if (!is_resource($process)) {
            throw new RuntimeException("Could not start adapter package suite {$test['path']}");
        }
        $status = proc_close($process);
        if ($status < 0) {
            throw new RuntimeException("Adapter package suite {$test['path']} returned no exit status");
        }
        return $status;
    }

    /**
     * @param list<array{adapter:string,class:string,path:string}> $matches
     */
    private static function findTarget(
        array &$matches,
        string $root,
        string $slug,
        string $class,
        string $directory,
        string $target
    ): void {
        foreach (self::entries($directory) as $entry) {
            $path = $directory . '/' . $entry;
            $stat = @lstat($path);
            if ($stat === false || is_link($path)) {
                continue;
            }
            $type = $stat['mode'] & self::TYPE_MODE;
            if ($type === self::DIRECTORY_MODE) {
                self::findTarget($matches, $root, $slug, $class, $path, $target);
                continue;
            }
            if ($type !== self::FILE_MODE || !preg_match('/\.(?:php|sh)$/D', $entry)) {
                continue;
            }
            $stem = substr($entry, 0, (int) strrpos($entry, '.'));
            if (str_replace('_', '-', $stem) !== $target) {
                continue;
            }
            $matches[] = [
                'adapter' => $slug,
                'class' => $class,
                'path' => substr($path, strlen($root) + 1),
            ];
        }
    }

    private static function ordinaryRoot(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        $root = realpath($path);
        if ($root === false) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        self::ordinaryDirectory($root, 'repository root');
        return rtrim($root, '/');
    }

    private static function ordinaryDirectory(string $path, string $label): void
    {
        if (!self::isOrdinaryDirectory($path) || !is_readable($path)) {
            throw new RuntimeException("$label is not an ordinary readable directory: $path");
        }
    }

    private static function isOrdinaryDirectory(string $path): bool
    {
        $stat = @lstat($path);
        return $stat !== false
            && ($stat['mode'] & self::TYPE_MODE) === self::DIRECTORY_MODE
            && !is_link($path);
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Cannot read adapter package test directory: $directory");
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        return $entries;
    }
}
