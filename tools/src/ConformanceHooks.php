<?php

declare(strict_types=1);

namespace WPrism\Tooling;

use RuntimeException;

/** One hook declaration is consumed by package validation and the live runner. */
final class ConformanceHooks
{
    /** @param array<string,mixed> $entry
     * @return array<string,?string>|null
     */
    public static function resolve(array $entry, string $capsule): ?array
    {
        if (!array_key_exists('hooks', $entry)) {
            return null;
        }
        $hooks = $entry['hooks'];
        if (!is_array($hooks)) {
            throw new RuntimeException('conformance hooks must be an object');
        }
        $keys = array_keys($hooks);
        sort($keys, SORT_STRING);
        if ($keys !== ['capture-check', 'check', 'postapply', 'postdeploy', 'seed']) {
            throw new RuntimeException('conformance hooks must explicitly account for all five phases');
        }
        $base = realpath($capsule);
        if ($base === false || !is_dir($base) || is_link($capsule)) {
            throw new RuntimeException('conformance hooks require an ordinary owning capsule');
        }
        $resolved = [];
        foreach ($hooks as $phase => $relative) {
            if ($relative === null && !in_array($phase, ['seed', 'check'], true)) {
                $resolved[$phase] = null;
                continue;
            }
            if (!is_string($relative) || preg_match(
                '~^(?:fixtures|tests/conformance)/(?:[a-z0-9][a-z0-9._-]*/)*[a-z0-9][a-z0-9._-]*\.sh$~D',
                $relative
            ) !== 1) {
                throw new RuntimeException("conformance hook '$phase' must name an owned shell fixture");
            }
            $file = $base . '/' . $relative;
            if (!is_file($file) || is_link($file) || realpath($file) !== $file) {
                throw new RuntimeException("conformance hook '$phase' is missing or escapes its owning capsule");
            }
            $resolved[$phase] = $file;
        }
        return $resolved;
    }
}
