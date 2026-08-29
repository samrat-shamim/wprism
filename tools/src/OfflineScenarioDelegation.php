<?php

declare(strict_types=1);

namespace WPrism\Tooling;

use RuntimeException;

/** Prevent a changed run's explicit scenario task from also running inside the package aggregate. */
final class OfflineScenarioDelegation
{
    public const ENVIRONMENT = 'WPRISM_OFFLINE_DELEGATED_SCENARIOS';
    public const AGGREGATE_TARGET = 'regress-adapter-packages';

    private const TARGET_PATTERN =
        '/^integration-scenario:[a-z][a-z0-9]*(?:-[a-z0-9]+)*:offline:'
        . '(?:regress|certify|spike)_[a-z0-9][a-z0-9._-]*\.(?:php|sh)$/D';

    /**
     * @param list<string> $scopedTargets
     * @return list<string>
     */
    public static function fromScopedTargets(array $scopedTargets): array
    {
        $delegated = [];
        foreach ($scopedTargets as $target) {
            if (preg_match(self::TARGET_PATTERN, $target) === 1) {
                $delegated[$target] = true;
            }
        }
        $targets = array_keys($delegated);
        sort($targets, SORT_STRING);
        return $targets;
    }

    /** @param list<string> $targets */
    public static function encode(array $targets): string
    {
        $canonical = self::canonicalTargets($targets);
        return implode(',', $canonical);
    }

    /** @return list<string> */
    public static function decode(string|false $encoded): array
    {
        if ($encoded === false || $encoded === '') {
            return [];
        }
        $targets = explode(',', $encoded);
        $canonical = self::canonicalTargets($targets);
        if ($targets !== $canonical || implode(',', $canonical) !== $encoded) {
            throw new RuntimeException('Delegated offline scenario targets are not sorted and unique');
        }
        return $canonical;
    }

    /**
     * The make variable is accepted only from make's command line; Makefile
     * unexports an ambient copy so the canonical aggregate cannot be weakened
     * by inherited process state.
     *
     * @param list<string> $scopedTargets
     * @return non-empty-list<string>
     */
    public static function makeArgv(string $make, string $target, array $scopedTargets): array
    {
        $argv = [$make, '--no-print-directory'];
        if ($target === self::AGGREGATE_TARGET) {
            $delegated = self::fromScopedTargets($scopedTargets);
            if ($delegated !== []) {
                $argv[] = self::ENVIRONMENT . '=' . self::encode($delegated);
            }
        }
        $argv[] = $target;
        return $argv;
    }

    /**
     * @param array<string,mixed> $scenario
     * @param array<string,mixed> $gate
     */
    public static function target(array $scenario, array $gate): string
    {
        $name = $scenario['name'] ?? null;
        $class = $gate['class'] ?? null;
        $path = $gate['path'] ?? null;
        if (!is_string($name) || !is_string($class) || !is_string($path)) {
            throw new RuntimeException('Integration scenario gate cannot form a delegated target');
        }
        return 'integration-scenario:' . $name . ':' . $class . ':' . basename($path);
    }

    /**
     * Validate every requested exclusion against the already checked scenario
     * catalog before any package test runs.
     *
     * @param list<string> $delegated
     * @param array<string,mixed> $catalog
     * @return array<string,true>
     */
    public static function checkedSet(array $delegated, array $catalog): array
    {
        $available = [];
        $scenarios = $catalog['scenarios'] ?? null;
        if (!is_array($scenarios)) {
            throw new RuntimeException('Integration scenario catalog is malformed');
        }
        foreach ($scenarios as $scenario) {
            if (!is_array($scenario) || !is_array($scenario['gates'] ?? null)) {
                throw new RuntimeException('Integration scenario catalog is malformed');
            }
            foreach ($scenario['gates'] as $gate) {
                if (!is_array($gate) || ($gate['class'] ?? null) !== 'offline') {
                    continue;
                }
                $available[self::target($scenario, $gate)] = true;
            }
        }
        foreach ($delegated as $target) {
            if (!isset($available[$target])) {
                throw new RuntimeException("Delegated offline scenario target is not in the catalog: $target");
            }
        }
        return array_fill_keys($delegated, true);
    }

    /**
     * @param list<string> $targets
     * @return list<string>
     */
    private static function canonicalTargets(array $targets): array
    {
        $seen = [];
        foreach ($targets as $target) {
            if (preg_match(self::TARGET_PATTERN, $target) !== 1) {
                throw new RuntimeException("Delegated offline scenario target is not canonical: $target");
            }
            if (isset($seen[$target])) {
                throw new RuntimeException("Delegated offline scenario target is repeated: $target");
            }
            $seen[$target] = true;
        }
        $canonical = array_keys($seen);
        sort($canonical, SORT_STRING);
        return $canonical;
    }
}
