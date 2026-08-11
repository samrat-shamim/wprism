<?php
namespace Duo;

/**
 * Generic path-safety primitives for code materialization (DUO-3350 slice
 * 1, extracted from Code): relative-path shape validation, symlink-crossing
 * detection below WP_CONTENT_DIR, and ownership/reservation membership
 * tests. Every method here is a pure predicate or a read-only filesystem
 * check (is_link/realpath) — nothing here writes. Materialization
 * (directory creation, staging, descriptor-layout preflight) stays on
 * Code, which is the only caller and composes these primitives with its
 * own state.
 */
final class PathSafety {
    public static function safe_join(string $root, string $relative): string {
        if (!self::safe_relative($relative)) {
            throw new \RuntimeException("duo: unsafe code path '$relative'");
        }
        return rtrim($root, '/') . '/' . $relative;
    }

    public static function same_target_path(string $actual, string $expected): bool {
        $actual = rtrim($actual, '/');
        $expected = rtrim($expected, '/');
        if ($actual === $expected) {
            return true;
        }
        $actualReal = realpath($actual);
        $expectedReal = realpath($expected);
        return $actualReal !== false && $expectedReal !== false && rtrim($actualReal, '/') === rtrim($expectedReal, '/');
    }

    /**
     * Reject a symlink at any path segment below the configured content
     * boundary. A descriptor path can be textually below WP_CONTENT_DIR
     * while an intermediate plugins/themes/mu-plugins (or component) link
     * redirects traversal elsewhere. Recheck this before every verification
     * and prune boundary; stage already performs the same walk while creating
     * each target parent.
     */
    public static function assert_no_symlinked_target_path(
        string $relative,
        bool $includeLeaf,
        string $operation
    ): void {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException("duo: $operation requires WordPress WP_CONTENT_DIR");
        }
        if (!self::safe_relative($relative)) {
            throw new \RuntimeException("duo: $operation refuses unsafe target path '$relative'");
        }
        $parts = explode('/', $relative);
        if (!$includeLeaf) {
            array_pop($parts);
        }
        $cursor = rtrim(WP_CONTENT_DIR, '/');
        $walked = [];
        foreach ($parts as $part) {
            $walked[] = $part;
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                $path = implode('/', $walked);
                throw new \RuntimeException("duo: $operation refuses symbolic-link target path '$path'");
            }
        }
    }

    public static function safe_relative(string $path): bool {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        $parts = explode('/', $path);
        return !in_array('', $parts, true) && !in_array('.', $parts, true) && !in_array('..', $parts, true);
    }

    public static function safe_component(string $name): bool {
        return $name !== '' && self::safe_relative($name) && !str_contains($name, '/');
    }

    /** Match WordPress get_plugins(): root PHP files or PHP files one directory deep. */
    public static function plugin_main_candidate(string $path): bool {
        if (!str_ends_with(strtolower($path), '.php')) {
            return false;
        }
        $parts = explode('/', $path);
        return $parts[0] === 'plugins' && (count($parts) === 2 || count($parts) === 3);
    }

    /** @param array<string,bool> $currentPaths */
    public static function has_current_path_at_or_below(string $path, array $currentPaths): bool {
        foreach ($currentPaths as $current => $_present) {
            if ($current === $path || str_starts_with($current, $path . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,bool> $ownedRoots */
    public static function owned_path(string $path, array $ownedRoots): bool {
        foreach ($ownedRoots as $root => $_owned) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $roots the caller's declared top-level component
     *     roots (Code::ROOTS: mu-plugins/plugins/themes) -- passed in rather
     *     than duplicated here, so this stays a narrow, caller-agnostic check.
     */
    public static function safe_component_root(string $path, array $roots): bool {
        if (!self::safe_relative($path)) {
            return false;
        }
        $parts = explode('/', $path);
        return count($parts) === 2
            && in_array($parts[0], $roots, true)
            && self::safe_component($parts[1]);
    }

    public static function reserved_path(string $path): bool {
        $lower = strtolower($path);
        return $lower === 'mu-plugins/duo'
            || str_starts_with($lower, 'mu-plugins/duo/')
            || $lower === 'mu-plugins/duo-loader.php';
    }
}
