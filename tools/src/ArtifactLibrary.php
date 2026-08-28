<?php

declare(strict_types=1);

namespace Duo\Tooling;

use RuntimeException;

/** Convention-discovered, package-owned artifact pins with an in-memory aggregate view. */
final class ArtifactLibrary
{
    /**
     * @return array{plugins:array<string,array<string,array<string,string>>>,themes:array<string,array<string,array<string,string>>>}
     */
    public static function load(string $repoRoot): array
    {
        $repo = self::repo($repoRoot);
        $paths = [$repo . '/platform/artifact-library/artifacts.lock.json'];
        $packages = $repo . '/adapter-packages';
        $entries = scandir($packages);
        if ($entries === false) {
            throw new RuntimeException("Cannot read adapter package root: $packages");
        }
        foreach ($entries as $package) {
            if ($package === '.' || $package === '..') {
                continue;
            }
            $directory = $packages . '/' . $package;
            if (!is_dir($directory) || is_link($directory)) {
                throw new RuntimeException("Adapter package entry is not a canonical directory: $package");
            }
            $path = self::packagePath($repo, $package);
            if (is_file($path)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return self::merge($paths);
    }

    /**
     * Read one adapter's pins without enumerating sibling capsules.
     *
     * @return array{plugins:array<string,array<string,array<string,string>>>,themes:array<string,array<string,array<string,string>>>}
     */
    public static function loadPackage(string $repoRoot, string $package): array
    {
        if (!self::safePackage($package)) {
            throw new RuntimeException("Adapter package name is not canonical: $package");
        }

        return self::loadParticipants($repoRoot, [$package]);
    }

    /**
     * Merge only the package fragments owned by one integration scenario's
     * declared participants. Unlike load(), this path never enumerates sibling
     * capsules or admits the platform fragment implicitly.
     *
     * @param list<string> $participants
     * @return array{plugins:array<string,array<string,array<string,string>>>,themes:array<string,array<string,array<string,string>>>}
     */
    public static function loadParticipants(string $repoRoot, array $participants): array
    {
        $repo = self::repo($repoRoot);
        if ($participants === [] || !array_is_list($participants)) {
            throw new RuntimeException('Artifact participant scope must be a non-empty list');
        }

        $paths = [];
        $seen = [];
        foreach ($participants as $participant) {
            if (!is_string($participant) || !self::safePackage($participant)) {
                throw new RuntimeException(
                    'Artifact participant is not a canonical adapter package: ' . var_export($participant, true)
                );
            }
            if (isset($seen[$participant])) {
                throw new RuntimeException("Artifact participant is declared more than once: $participant");
            }
            $seen[$participant] = true;
            $paths[] = self::packagePath($repo, $participant);
        }
        sort($paths, SORT_STRING);

        return self::merge($paths);
    }

    /** @return array<string,string> */
    public static function entry(string $repoRoot, string $kind, string $slug, string $version): array
    {
        if (!in_array($kind, ['plugin', 'theme'], true)) {
            throw new RuntimeException("Artifact kind must be plugin or theme: $kind");
        }
        if (!self::safeSlug($slug) || !self::safeVersion($version)) {
            throw new RuntimeException("Artifact identity is not canonical: $kind $slug $version");
        }
        $library = self::load($repoRoot);
        $entry = $library[$kind . 's'][$slug][$version] ?? null;
        if (!is_array($entry)) {
            throw new RuntimeException("Artifact library has no $kind pin for $slug $version");
        }

        return $entry;
    }

    public static function packagePath(string $repoRoot, string $package): string
    {
        return rtrim($repoRoot, '/') . '/adapter-packages/' . $package . '/evidence/artifacts.lock.json';
    }

    /**
     * Validate one proposed fragment through the same parser aggregation uses.
     *
     * @return array{plugins:array<string,array<string,array<string,string>>>,themes:array<string,array<string,array<string,string>>>}
     */
    public static function loadFragment(string $path): array
    {
        return self::fragment($path);
    }

    /**
     * @param list<string> $paths
     * @return array{plugins:array<string,array<string,array<string,string>>>,themes:array<string,array<string,array<string,string>>>}
     */
    private static function merge(array $paths): array
    {
        $aggregate = ['plugins' => [], 'themes' => []];
        foreach ($paths as $path) {
            $fragment = self::fragment($path);
            foreach (['plugins', 'themes'] as $namespace) {
                foreach ($fragment[$namespace] as $slug => $versions) {
                    if (isset($aggregate[$namespace][$slug])) {
                        throw new RuntimeException("Artifact subject is owned by more than one fragment: $namespace/$slug");
                    }
                    $aggregate[$namespace][$slug] = $versions;
                }
            }
        }
        foreach ($aggregate as &$subjects) {
            // Subject discovery order is filesystem-dependent; version order is
            // authored evidence consumed oldest-first by the boundary planner.
            ksort($subjects, SORT_STRING);
        }
        unset($subjects);

        return $aggregate;
    }

    /**
     * @return array{plugins:array<string,array<string,array<string,string>>>,themes:array<string,array<string,array<string,string>>>}
     */
    private static function fragment(string $path): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException("Artifact fragment is missing or not an ordinary file: $path");
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new RuntimeException("Artifact fragment is not valid JSON: $path", 0, $failure);
        }
        if (!is_array($decoded) || array_is_list($decoded) || self::sortedKeys($decoded) !== ['plugins', 'themes']) {
            throw new RuntimeException("Artifact fragment must contain exactly plugins and themes: $path");
        }
        if ($decoded['plugins'] === [] && $decoded['themes'] === []) {
            throw new RuntimeException("Artifact fragment owns no subjects: $path");
        }
        foreach (['plugins', 'themes'] as $namespace) {
            if (!is_array($decoded[$namespace]) || ($decoded[$namespace] !== [] && array_is_list($decoded[$namespace]))) {
                throw new RuntimeException("Artifact namespace must be an object: $path#$namespace");
            }
            foreach ($decoded[$namespace] as $slug => $versions) {
                if (!is_string($slug) || !self::safeSlug($slug)) {
                    throw new RuntimeException("Artifact slug is not canonical: $path#$namespace/$slug");
                }
                if (!is_array($versions) || $versions === [] || array_is_list($versions)) {
                    throw new RuntimeException("Artifact version map must be a non-empty object: $path#$namespace/$slug");
                }
                foreach ($versions as $version => $entry) {
                    if (!is_string($version) || !self::safeVersion($version)) {
                        throw new RuntimeException("Artifact version is not canonical: $path#$namespace/$slug/$version");
                    }
                    self::validateEntry($entry, $namespace, "$path#$namespace/$slug/$version");
                }
            }
        }

        /** @var array{plugins:array<string,array<string,array<string,string>>>,themes:array<string,array<string,array<string,string>>>} $decoded */
        return $decoded;
    }

    private static function validateEntry(mixed $entry, string $namespace, string $where): void
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new RuntimeException("Artifact entry must be an object: $where");
        }
        $keys = self::sortedKeys($entry);
        if ($keys !== ['role', 'sha256', 'url'] && $keys !== ['archive_root', 'role', 'sha256', 'url']) {
            throw new RuntimeException("Artifact entry has unexpected members: $where");
        }
        if (!in_array($entry['role'], ['certified-boundary', 'refusal-fixture', 'exercise-fixture'], true)) {
            throw new RuntimeException("Artifact role is not supported: $where");
        }
        if ($namespace === 'themes' && $entry['role'] !== 'exercise-fixture') {
            throw new RuntimeException("Theme artifact roles are exercise-fixture only: $where");
        }
        if (!is_string($entry['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $entry['sha256']) !== 1) {
            throw new RuntimeException("Artifact digest is not a lowercase SHA-256: $where");
        }
        if (!is_string($entry['url']) || preg_match('/^https:\/\/[^\s\'\"]+$/D', $entry['url']) !== 1) {
            throw new RuntimeException("Artifact URL is not bounded HTTPS: $where");
        }
        if (isset($entry['archive_root']) && (!is_string($entry['archive_root']) || !self::safeSlug($entry['archive_root']))) {
            throw new RuntimeException("Artifact archive root is not canonical: $where");
        }
    }

    /** @param array<mixed> $value @return list<int|string> */
    private static function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys;
    }

    private static function repo(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        $repo = realpath($path);
        if ($repo === false || !is_dir($repo)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        return rtrim($repo, '/');
    }

    private static function safePackage(string $package): bool
    {
        return preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $package) === 1;
    }

    private static function safeSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/D', $slug) === 1;
    }

    private static function safeVersion(string $version): bool
    {
        return preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/D', $version) === 1;
    }
}
