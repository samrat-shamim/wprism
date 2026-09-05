<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Pure, bounded snapshot for one caller-selected filesystem tree.
 *
 * This class owns generic confinement, traversal budgets, nonregular-node
 * refusal and race-checked open-handle hashing. It knows nothing about active
 * executable owners, adapter policy, plugin semantics, or deletion authority.
 */
final class FilesystemTreeSnapshot {
    public const MAX_TREE_ENTRIES = 100000;
    public const MAX_TREE_DEPTH = 128;
    public const MAX_TREE_BYTES = 1073741824;

    // No production setter: the offline regression reaches this through
    // Reflection to place mutations at exact directory observation boundaries.
    private static ?\Closure $testDirectoryObservationHook = null;

    /**
     * @return array{
     *   root:string,
     *   directories:list<string>,
     *   files:list<array{path:string,bytes:int,mtime:int,sha256:string}>
     * }
     */
    public static function observe(
        string $contentRoot,
        string $absoluteRoot,
        string $canonicalRoot,
        string $subject,
        string $containmentLabel
    ): array {
        $boundary = self::confined_root(
            $contentRoot,
            $absoluteRoot,
            $canonicalRoot,
            $subject,
            $containmentLabel
        );
        $contentInfo = $boundary['content'];
        $absoluteInfo = $boundary['absolute'];
        $content = $contentInfo['path'];
        $absolute = $absoluteInfo['path'];

        $stat = $absoluteInfo['stat'];
        $tree = self::observe_tree($absolute, $canonicalRoot, $stat, $subject);
        self::assert_path_identity($content, $contentInfo['stat'], $containmentLabel, $subject);
        self::assert_path_version($absolute, $stat, "$subject root", $subject);

        // PHP exposes whole-second mtime/ctime values on supported runtimes.
        // A same-size in-place rewrite can therefore retain every stat field;
        // a complete second byte pass is the only generic way to prove the
        // first observation still names the tree returned to the caller.
        $verifiedTree = self::observe_tree($absolute, $canonicalRoot, $stat, $subject);
        if ($verifiedTree !== $tree) {
            throw new \RuntimeException("wprism: $subject tree changed while being inspected");
        }
        self::assert_path_identity($content, $contentInfo['stat'], $containmentLabel, $subject);
        self::assert_path_version($absolute, $stat, "$subject root", $subject);
        return [
            'root' => $canonicalRoot,
            'directories' => $verifiedTree['directories'],
            'files' => $verifiedTree['files'],
        ];
    }

    /**
     * @return array{
     *   content:array{path:string,stat:array<string|int,mixed>},
     *   absolute:array{path:string,stat:array<string|int,mixed>}
     * }
     */
    private static function confined_root(
        string $contentRoot,
        string $absoluteRoot,
        string $canonicalRoot,
        string $subject,
        string $containmentLabel
    ): array {
        self::assert_canonical_root($canonicalRoot, $subject);
        $contentInfo = self::normalized_existing_path($contentRoot, $containmentLabel);
        $absoluteInfo = self::normalized_existing_path($absoluteRoot, "$subject root");
        $content = $contentInfo['path'];
        $absolute = $absoluteInfo['path'];
        if (!self::path_within($absolute, $content)) {
            throw new \RuntimeException("wprism: $subject root escapes $containmentLabel");
        }
        $expected = $content . '/' . $canonicalRoot;
        if (!self::same_path($absolute, $expected)) {
            throw new \RuntimeException(
                "wprism: $subject root does not match canonical identity '$canonicalRoot'"
            );
        }
        return ['content' => $contentInfo, 'absolute' => $absoluteInfo];
    }

    /**
     * @param array<string|int,mixed> $stat
     * @return array{
     *   directories:list<string>,
     *   files:list<array{path:string,bytes:int,mtime:int,sha256:string}>
     * }
     */
    private static function observe_tree(
        string $absolute,
        string $canonicalRoot,
        array $stat,
        string $subject
    ): array {
        $rows = [];
        $directories = [];
        $bytes = 0;
        // Count the canonical root as well as every descendant. Empty
        // directories consume the same finite traversal budget as files.
        $entries = 1;
        $kind = ((int) $stat['mode']) & 0170000;
        if ($kind === 0100000) {
            self::append_file_identity(
                $absolute,
                basename($canonicalRoot),
                $rows,
                $bytes,
                $stat,
                $subject
            );
        } elseif ($kind === 0040000) {
            $directories[] = '';
            self::walk_tree(
                $absolute,
                '',
                $directories,
                $rows,
                $bytes,
                $entries,
                0,
                $stat,
                $subject
            );
        } else {
            throw new \RuntimeException("wprism: $subject root is not a regular file or directory");
        }
        sort($directories, SORT_STRING);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return ['directories' => $directories, 'files' => $rows];
    }

    /** @param list<string> $directories */
    /** @param list<array{path:string,bytes:int,mtime:int,sha256:string}> $rows */
    private static function walk_tree(
        string $root,
        string $relative,
        array &$directories,
        array &$rows,
        int &$bytes,
        int &$entries,
        int $depth,
        array $expectedStat,
        string $subject
    ): void {
        if ($depth > self::MAX_TREE_DEPTH) {
            throw new \RuntimeException("wprism: $subject tree exceeds its depth bound");
        }
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $initial = self::directory_snapshot($directory, $relative, $entries, true, $subject);
        if (!self::same_directory_version($expectedStat, $initial['stat'])) {
            throw new \RuntimeException(self::directory_changed_message($relative, $subject));
        }
        self::directory_observation_checkpoint('after-directory-snapshot', $directory, $relative);
        self::assert_directory_version($directory, $initial['stat'], $relative, $subject);

        foreach ($initial['roster'] as $entry => $stat) {
            self::assert_directory_version($directory, $initial['stat'], $relative, $subject);
            $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;
            $path = $root . '/' . $childRelative;
            $kind = ((int) $stat['mode']) & 0170000;
            if ($kind === 0040000) {
                $directories[] = $childRelative;
                self::walk_tree(
                    $root,
                    $childRelative,
                    $directories,
                    $rows,
                    $bytes,
                    $entries,
                    $depth + 1,
                    $stat,
                    $subject
                );
            } elseif ($kind === 0100000) {
                self::append_file_identity($path, $childRelative, $rows, $bytes, $stat, $subject);
            } else {
                throw new \RuntimeException(
                    "wprism: $subject tree entry '$childRelative' is symlinked or nonregular"
                );
            }
            self::assert_directory_version($directory, $initial['stat'], $relative, $subject);
        }

        self::directory_observation_checkpoint('before-directory-revalidation', $directory, $relative);
        $final = self::directory_snapshot($directory, $relative, $entries, false, $subject);
        self::assert_same_directory_roster($initial['roster'], $final['roster'], $relative, $subject);
        if (!self::same_directory_version($initial['stat'], $final['stat'])) {
            throw new \RuntimeException(self::directory_changed_message($relative, $subject));
        }
        self::assert_directory_version($directory, $initial['stat'], $relative, $subject);
    }

    /**
     * @return array{
     *   stat:array<string|int,mixed>,
     *   roster:array<string,array<string|int,mixed>>
     * }
     */
    private static function directory_snapshot(
        string $directory,
        string $relative,
        int &$entries,
        bool $consumeBudget,
        string $subject
    ): array {
        clearstatcache(true, $directory);
        $before = @lstat($directory);
        if (!is_array($before) || (((int) $before['mode']) & 0170000) !== 0040000) {
            throw new \RuntimeException(self::directory_changed_message($relative, $subject));
        }
        $anchor = @fopen($directory, 'rb');
        if (!is_resource($anchor)) {
            throw new \RuntimeException("wprism: $subject tree contains an unreadable directory");
        }
        $handle = null;
        try {
            $opened = @fstat($anchor);
            if (!is_array($opened) || !self::same_directory_version($before, $opened)) {
                throw new \RuntimeException(self::directory_changed_message($relative, $subject));
            }
            $handle = @opendir($directory);
            if (!is_resource($handle)) {
                throw new \RuntimeException("wprism: $subject tree contains an unreadable directory");
            }
            self::assert_directory_version($directory, $opened, $relative, $subject);

            $roster = [];
            $snapshotEntries = 0;
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::assert_safe_path_component($entry, $subject);
                if ($consumeBudget) {
                    self::consume_tree_entry($entries, $subject);
                } elseif ($snapshotEntries >= self::MAX_TREE_ENTRIES) {
                    throw new \RuntimeException("wprism: $subject tree exceeds its entry bound");
                }
                $snapshotEntries++;
                $path = $directory . '/' . $entry;
                clearstatcache(true, $path);
                $stat = @lstat($path);
                if (!is_array($stat)) {
                    throw new \RuntimeException("wprism: $subject tree entry cannot be inspected");
                }
                $roster[$entry] = $stat;
            }

            $after = @fstat($anchor);
            if (!is_array($after) || !self::same_directory_version($opened, $after)) {
                throw new \RuntimeException(self::directory_changed_message($relative, $subject));
            }
            self::assert_directory_version($directory, $opened, $relative, $subject);
            uksort($roster, static fn(string $left, string $right): int => strcmp($left, $right));
            return ['stat' => $opened, 'roster' => $roster];
        } finally {
            if (is_resource($handle)) {
                closedir($handle);
            }
            fclose($anchor);
        }
    }

    /**
     * @param array<string,array<string|int,mixed>> $initial
     * @param array<string,array<string|int,mixed>> $final
     */
    private static function assert_same_directory_roster(
        array $initial,
        array $final,
        string $relative,
        string $subject
    ): void {
        if (array_keys($initial) !== array_keys($final)) {
            throw new \RuntimeException(self::directory_roster_changed_message($relative, $subject));
        }
        foreach ($initial as $entry => $stat) {
            if (!self::same_node_version($stat, $final[$entry])) {
                $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;
                throw new \RuntimeException(
                    "wprism: $subject tree entry '$childRelative' changed while being inspected"
                );
            }
        }
    }

    private static function assert_safe_path_component(string $entry, string $subject): void {
        if ($entry === '' || str_contains($entry, "\0") || str_contains($entry, '/')
            || str_contains($entry, '\\') || preg_match('/[\x00-\x1f\x7f]/', $entry) === 1) {
            throw new \RuntimeException("wprism: $subject tree contains an unsafe path component");
        }
    }

    /** @param array<string|int,mixed> $expected */
    private static function assert_directory_version(
        string $path,
        array $expected,
        string $relative,
        string $subject
    ): void {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($current) || !self::same_directory_version($expected, $current)) {
            throw new \RuntimeException(self::directory_changed_message($relative, $subject));
        }
    }

    /** @param array<string|int,mixed> $expected */
    private static function assert_path_identity(
        string $path,
        array $expected,
        string $label,
        string $subject
    ): void {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($current) || !self::same_node_identity($expected, $current)) {
            throw new \RuntimeException("wprism: $label changed while $subject identity was observed");
        }
    }

    /** @param array<string|int,mixed> $expected */
    private static function assert_path_version(
        string $path,
        array $expected,
        string $label,
        string $subject
    ): void {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (!is_array($current) || !self::same_node_version($expected, $current)) {
            throw new \RuntimeException("wprism: $label changed while $subject identity was observed");
        }
    }

    private static function directory_changed_message(string $relative, string $subject): string {
        $label = $relative === '' ? '<root>' : $relative;
        return "wprism: $subject directory '$label' changed while being inspected";
    }

    private static function directory_roster_changed_message(string $relative, string $subject): string {
        $label = $relative === '' ? '<root>' : $relative;
        return "wprism: $subject directory '$label' roster changed while being inspected";
    }

    private static function directory_observation_checkpoint(
        string $phase,
        string $directory,
        string $relative
    ): void {
        if (self::$testDirectoryObservationHook !== null) {
            (self::$testDirectoryObservationHook)($phase, $directory, $relative);
        }
    }

    private static function consume_tree_entry(int &$entries, string $subject = 'filesystem snapshot'): void {
        if ($entries >= self::MAX_TREE_ENTRIES) {
            throw new \RuntimeException("wprism: $subject tree exceeds its entry bound");
        }
        $entries++;
    }

    /** @param list<array{path:string,bytes:int,mtime:int,sha256:string}> $rows @param ?array<string,mixed> $stat */
    private static function append_file_identity(
        string $path,
        string $relative,
        array &$rows,
        int &$bytes,
        ?array $stat = null,
        string $subject = 'filesystem snapshot'
    ): void {
        $stat ??= @lstat($path);
        if (!is_array($stat) || (((int) $stat['mode']) & 0170000) !== 0100000) {
            throw new \RuntimeException("wprism: $subject file '$relative' is nonregular or unreadable");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException("wprism: $subject file '$relative' is nonregular or unreadable");
        }
        try {
            $opened = @fstat($handle);
            if (!is_array($opened) || !self::same_file_version($stat, $opened)) {
                throw new \RuntimeException("wprism: $subject file '$relative' changed while being inspected");
            }
            $size = $opened['size'] ?? null;
            if (!is_int($size) || $size < 0 || $bytes > self::MAX_TREE_BYTES - $size) {
                throw new \RuntimeException("wprism: $subject tree exceeds its byte bound");
            }

            $context = hash_init('sha256');
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1048576, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException("wprism: $subject file '$relative' changed while being hashed");
                }
                hash_update($context, $chunk);
                $remaining -= strlen($chunk);
            }
            $extra = fread($handle, 1);
            if (!is_string($extra) || $extra !== '') {
                throw new \RuntimeException("wprism: $subject file '$relative' changed while being hashed");
            }
            $after = @fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($after) || !is_array($current)
                || !self::same_file_version($opened, $after)
                || !self::same_file_version($opened, $current)) {
                throw new \RuntimeException("wprism: $subject file '$relative' changed while being hashed");
            }

            $bytes += $size;
            $rows[] = [
                'path' => $relative,
                'bytes' => $size,
                'mtime' => (int) $opened['mtime'],
                'sha256' => hash_final($context),
            ];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function same_file_version(array $left, array $right): bool {
        return self::same_node_version($left, $right)
            && ((((int) $left['mode']) & 0170000) === 0100000);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function same_directory_version(array $left, array $right): bool {
        return self::same_node_version($left, $right)
            && ((((int) $left['mode']) & 0170000) === 0040000);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function same_node_version(array $left, array $right): bool {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!isset($left[$field], $right[$field]) || !is_int($left[$field]) || !is_int($right[$field])
                || $left[$field] !== $right[$field]) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function same_node_identity(array $left, array $right): bool {
        foreach (['dev', 'ino', 'mode'] as $field) {
            if (!isset($left[$field], $right[$field]) || !is_int($left[$field]) || !is_int($right[$field])) {
                return false;
            }
        }
        return $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino']
            && ((((int) $left['mode']) & 0170000) === (((int) $right['mode']) & 0170000));
    }

    private static function assert_canonical_root(string $root, string $subject): void {
        if ($root === '' || str_starts_with($root, '/') || str_contains($root, '\\')) {
            throw new \RuntimeException("wprism: $subject identity root is not canonical");
        }
        foreach (explode('/', $root) as $component) {
            if ($component === '.' || $component === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/D', $component) !== 1) {
                throw new \RuntimeException("wprism: $subject identity root is not canonical");
            }
        }
    }

    /** @return array{path:string,stat:array<string|int,mixed>} */
    private static function normalized_existing_path(string $path, string $label): array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $resolved = @realpath($path);
        if (!is_array($stat) || !is_string($resolved) || $resolved === ''
            || ((((int) $stat['mode']) & 0170000) !== 0040000
                && (((int) $stat['mode']) & 0170000) !== 0100000)
            || is_link($path)) {
            throw new \RuntimeException("wprism: $label is absent, symlinked, or nonregular");
        }
        $normalized = rtrim(str_replace('\\', '/', $resolved), '/');
        clearstatcache(true, $normalized);
        $resolvedStat = @lstat($normalized);
        if (!is_array($resolvedStat) || !self::same_node_identity($stat, $resolvedStat)) {
            throw new \RuntimeException("wprism: $label changed while its physical path was resolved");
        }
        return ['path' => $normalized, 'stat' => $resolvedStat];
    }

    private static function same_path(string $left, string $right): bool {
        $leftReal = @realpath($left);
        $rightReal = @realpath($right);
        return is_string($leftReal) && is_string($rightReal)
            && hash_equals(rtrim(str_replace('\\', '/', $leftReal), '/'), rtrim(str_replace('\\', '/', $rightReal), '/'));
    }

    private static function path_within(string $path, string $root): bool {
        return hash_equals($root, $path) || str_starts_with($path, $root . '/');
    }
}
