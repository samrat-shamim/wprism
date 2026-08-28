<?php

declare(strict_types=1);

namespace Duo\Tooling;

use RuntimeException;

/** Read package-owned readiness records without a flat adapter registry. */
final class AdapterProductionReadiness
{
    public const AGGREGATE_FORMAT = 'duo-adapter-production-readiness/v1';
    public const RECORD_FORMAT = 'duo-adapter-production-readiness-record/v1';

    /** @var list<string> */
    public const SCENARIO_FAMILIES = [
        'contract-dependency',
        'clean-target',
        'dirty-target',
        'identity-references',
        'native-behavior',
        'derived-state',
        'deletion',
        'failure-recovery',
        'concurrency-idempotence',
        'lifecycle',
        'data-boundary',
        'scope-platform',
    ];

    /**
     * Build the legacy aggregate view in memory for cross-adapter tooling.
     * The view is never written: each adapter remains the only authority for
     * its own row, while core's row stays under the platform boundary.
     *
     * @return array{format:string,scenario_families:list<string>,adapters:array<string,array<string,mixed>>}
     */
    public static function load(string $repoRoot): array
    {
        $repo = self::repo($repoRoot);
        $adapters = ['core' => self::record($repo, 'core')];
        $packages = $repo . '/adapter-packages';
        $entries = scandir($packages);
        if ($entries === false) {
            throw new RuntimeException("Cannot read adapter package root: $packages");
        }
        foreach ($entries as $slug) {
            if ($slug === '.' || $slug === '..') {
                continue;
            }
            if (!self::isSlug($slug) || !is_dir($packages . '/' . $slug) || is_link($packages . '/' . $slug)) {
                throw new RuntimeException("Adapter package entry is not a canonical directory: $slug");
            }
            $path = self::path($repo, $slug);
            if (!is_file($path)) {
                continue;
            }
            $adapters[$slug] = self::record($repo, $slug);
        }
        ksort($adapters, SORT_STRING);

        return [
            'format' => self::AGGREGATE_FORMAT,
            'scenario_families' => self::SCENARIO_FAMILIES,
            'adapters' => $adapters,
        ];
    }

    /**
     * Read one package in isolation. This method never enumerates siblings,
     * so an adapter-local test cannot fail because another capsule is absent.
     *
     * @return array{readiness:string,covered:array<string,mixed>,gaps:array<string,mixed>,blocked:array<string,mixed>,not_applicable:array<string,mixed>}
     */
    public static function record(string $repoRoot, string $slug, ?callable $evidenceOwnership = null): array
    {
        $repo = self::repo($repoRoot);
        if ($slug !== 'core' && !self::isSlug($slug)) {
            throw new RuntimeException("Adapter readiness slug is not canonical: $slug");
        }
        $path = self::path($repo, $slug);
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException("Adapter readiness record is missing or not an ordinary file: $path");
        }
        $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_is_list($record)) {
            throw new RuntimeException("Adapter readiness record root must be an object: $path");
        }
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        if ($keys !== ['adapter', 'blocked', 'covered', 'format', 'gaps', 'not_applicable', 'readiness']) {
            throw new RuntimeException("Adapter readiness record has unexpected root members: $path");
        }
        if (($record['format'] ?? null) !== self::RECORD_FORMAT || ($record['adapter'] ?? null) !== $slug) {
            throw new RuntimeException("Adapter readiness record identity does not match '$slug': $path");
        }
        foreach (['covered', 'gaps', 'blocked', 'not_applicable'] as $bucket) {
            if (!is_array($record[$bucket]) || ($record[$bucket] !== [] && array_is_list($record[$bucket]))) {
                throw new RuntimeException("Adapter readiness record '$slug'.$bucket must be an object");
            }
        }

        if (!in_array($record['readiness'], ['ready', 'unready'], true)) {
            throw new RuntimeException("Adapter readiness record '$slug'.readiness must be ready or unready");
        }
        $accounted = [];
        foreach (['covered', 'gaps', 'blocked', 'not_applicable'] as $bucket) {
            foreach ($record[$bucket] as $family => $value) {
                if (!is_string($family) || !in_array($family, self::SCENARIO_FAMILIES, true)) {
                    throw new RuntimeException("Adapter readiness record '$slug'.$bucket names unknown family '$family'");
                }
                if (isset($accounted[$family])) {
                    throw new RuntimeException("Adapter readiness record '$slug' accounts for '$family' more than once");
                }
                $accounted[$family] = true;
                if ($bucket === 'covered') {
                    if (!is_array($value) || !array_is_list($value) || $value === []) {
                        throw new RuntimeException("Adapter readiness record '$slug'.covered.$family must name evidence files");
                    }
                    foreach ($value as $evidence) {
                        if (!is_string($evidence)
                            || $evidence === ''
                            || str_starts_with($evidence, '/')
                            || str_contains($evidence, '..')
                            || !is_file($repo . '/' . $evidence)
                            || is_link($repo . '/' . $evidence)) {
                            throw new RuntimeException(
                                "Adapter readiness record '$slug'.covered.$family names invalid evidence "
                                . var_export($evidence, true)
                            );
                        }
                        if ($evidenceOwnership !== null) {
                            $evidenceOwnership($repo, $slug, $evidence);
                        }
                    }
                } elseif (!is_string($value) || trim($value) === '') {
                    throw new RuntimeException("Adapter readiness record '$slug'.$bucket.$family must carry a reason");
                }
            }
        }
        $families = array_keys($accounted);
        sort($families, SORT_STRING);
        $expectedFamilies = self::SCENARIO_FAMILIES;
        sort($expectedFamilies, SORT_STRING);
        if ($families !== $expectedFamilies) {
            throw new RuntimeException("Adapter readiness record '$slug' does not account for every scenario family");
        }
        $expectedReadiness = $record['gaps'] === [] && $record['blocked'] === [] ? 'ready' : 'unready';
        if ($record['readiness'] !== $expectedReadiness) {
            throw new RuntimeException(
                "Adapter readiness record '$slug' cannot be {$record['readiness']} while its gap/blocked buckets imply "
                . $expectedReadiness
            );
        }

        unset($record['format'], $record['adapter']);
        /** @var array{readiness:string,covered:array<string,mixed>,gaps:array<string,mixed>,blocked:array<string,mixed>,not_applicable:array<string,mixed>} $record */
        return $record;
    }

    public static function path(string $repoRoot, string $slug): string
    {
        $repo = self::repo($repoRoot);
        return $slug === 'core'
            ? $repo . '/platform/adapter-evidence/production-readiness.json'
            : $repo . '/adapter-packages/' . $slug . '/evidence/production-readiness.json';
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

    private static function isSlug(string $slug): bool
    {
        return preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug) === 1;
    }
}
