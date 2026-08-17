<?php
namespace Duo;

/**
 * Deterministic traversal primitives for canonical state trees.
 *
 * State artifacts are not one homogeneous format, so this class reports the
 * stable surface name with each sorted relative file and leaves semantic
 * scanning to its caller. It also owns the dotted/bracketed locator walk
 * shared by every string-oriented scanner. Neither operation loads WordPress
 * nor interprets a policy, which makes this a safe foundation for later
 * manifest-declared scanner registration.
 */
final class StateTreeWalker {
    /**
     * @return list<array{surface:string,path:string}>
     */
    public static function files(string $stateDir): array {
        $surfaces = [
            'post' => 'posts/*/*.md',
            'term' => 'terms/*/*.json',
            'menu' => 'menus/*.json',
            'sidebar' => 'sidebars/*.json',
            'options' => null,
            'user_meta' => 'user-meta/*.json',
            'table' => 'tables/*/*.json',
        ];
        $files = [];
        foreach ($surfaces as $surface => $pattern) {
            if ($pattern === null) {
                if (is_file($stateDir . '/options/core.json')) {
                    $files[] = ['surface' => $surface, 'path' => 'options/core.json'];
                }
                continue;
            }
            foreach (self::globRelative($stateDir, $pattern) as $path) {
                $files[] = ['surface' => $surface, 'path' => $path];
            }
        }
        return $files;
    }

    /**
     * Recursively visits every string leaf with the historical JSON-ish
     * locator spelling: map keys append `.key`, while list offsets append
     * `[index]`. PHP preserves insertion order for both forms, so callers'
     * finding ordering remains byte-for-byte stable.
     */
    public static function strings($value, string $path, callable $visit): void {
        if (is_string($value)) {
            $visit($path, $value);
            return;
        }
        if (!is_array($value)) {
            return;
        }
        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            self::strings(
                $child,
                $isList ? ($path . '[' . $key . ']') : ($path . '.' . $key),
                $visit
            );
        }
    }

    /** @return list<string> */
    private static function globRelative(string $stateDir, string $pattern): array {
        $matches = glob(rtrim($stateDir, '/') . '/' . $pattern) ?: [];
        sort($matches, SORT_STRING);
        $prefixLength = strlen(rtrim($stateDir, '/')) + 1;
        return array_map(static fn(string $path): string => substr($path, $prefixLength), $matches);
    }
}
