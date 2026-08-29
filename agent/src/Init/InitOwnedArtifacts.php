<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Publication/Publish.php';
require_once __DIR__ . '/InitFaults.php';

/**
 * Exact identity and compensation authority for first-init-owned artifacts.
 *
 * These witnesses intentionally preserve the v1 init journal representation;
 * they are not interchangeable with the newer structured capture manifests.
 */
final class InitOwnedArtifacts {
    public static function assert_regular_file_or_absent(string $path, string $label): void {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException("wprism: init refuses non-regular repository-owned $label path $path");
        }
    }

    public static function assert_absent_owned_path(string $path, string $label): void {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("wprism: init refuses pre-existing $label $path");
        }
    }

    public static function regular_file_identity(string $path, string $label): string {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("wprism: init lost ownership of repository-owned $label path $path");
        }
        $stat = @lstat($path);
        $raw = Canon::read_file($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("wprism: init could not identify repository-owned $label path $path");
        }
        return 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'sha256' => hash('sha256', $raw),
        ]));
    }

    public static function regular_file_inode_identity(string $path, string $label): string {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("wprism: init lost ownership of repository-owned $label path $path");
        }
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("wprism: init could not identify repository-owned $label path $path");
        }
        return 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
        ]));
    }

    public static function owned_file_boundary_identity(string $path, string $label): string {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            return 'unsafe';
        }
        return is_file($path) ? self::regular_file_identity($path, $label) : 'absent';
    }

    /** @return array{previous:?string,published:string} */
    public static function publish_owned_file(
        string $path,
        string $content,
        string $expectedIdentity,
        string $label
    ): array {
        $dir = dirname($path);
        if (is_link($dir) || !is_dir($dir)) {
            throw new \RuntimeException("wprism: init refuses unsafe parent for repository-owned $label path $path");
        }
        $tmp = $dir . '/.' . basename($path) . '.wprism-init-' . bin2hex(random_bytes(8));
        $previous = null;
        try {
            Canon::write_file($tmp, $content);
            InitFaults::checkpoint('owned-file-temp');
            if ($expectedIdentity === 'absent') {
                if (!@link($tmp, $path)) {
                    throw new \RuntimeException(
                        "wprism: init $label boundary changed after review; a concurrent writer was preserved"
                    );
                }
            } else {
                if (preg_match('/^sha256:[a-f0-9]{64}$/D', $expectedIdentity) !== 1) {
                    throw new \RuntimeException("wprism: init has no valid reviewed identity for $label");
                }
                self::assert_regular_file_or_absent($path, $label);
                if (!hash_equals($expectedIdentity, self::regular_file_identity($path, $label))) {
                    throw new \RuntimeException(
                        "wprism: init $label boundary changed after review; the concurrent bytes were preserved"
                    );
                }
                $previous = Canon::read_file($path);
                if (!@rename($tmp, $path)) {
                    throw new \RuntimeException("wprism: init could not publish the reviewed $label boundary");
                }
            }
            Publish::sync_parent($path);
            return ['previous' => $previous, 'published' => self::regular_file_identity($path, $label)];
        } finally {
            if (is_file($tmp) || is_link($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Whether a publication's compensation has already happened: with no
     * prior version, the file is gone; with a prior version (an adoption
     * seed's site.wprism.json), the file is back to exactly those bytes. Both
     * shapes are what Init::confirm()'s own catch leaves behind before it
     * re-enters recovery to PROVE the rollback from the sealed journal (see
     * InitRecovery) — the second shape refused as "preserved a replacement
     * site.wprism.json instead of deleting external bytes" until T7 grind A4,
     * turning every failed init on an adoption seed into a retained journal.
     * Anything else — absent with a prior version to put back, present with
     * bytes that are neither the publication nor the prior version — is
     * still a compensation to perform (or refuse) exactly as before.
     *
     * @param array<string,mixed> $publication
     */
    public static function owned_file_already_compensated(string $path, array $publication): bool {
        $previous = $publication['previous'] ?? null;
        if ($previous === null) {
            return !file_exists($path) && !is_link($path);
        }
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            return false;
        }

        return hash_equals((string) $previous, Canon::read_file($path));
    }

    /** @param array{previous:?string,published:string} $publication */
    public static function compensate_owned_file(string $path, array $publication, string $label): void {
        if (!is_file($path) || is_link($path)
            || !hash_equals((string) $publication['published'], self::regular_file_identity($path, $label))) {
            throw new \RuntimeException("wprism: init preserved a replacement $label instead of deleting external bytes");
        }
        $dir = dirname($path);
        $claim = $dir . '/.' . basename($path) . '.wprism-init-compensate-' . bin2hex(random_bytes(8));
        if (!@rename($path, $claim)) {
            throw new \RuntimeException("wprism: init could not claim its published $label for compensation");
        }
        InitFaults::checkpoint('owned-file-claim');
        if (!hash_equals((string) $publication['published'], self::regular_file_identity($claim, $label))) {
            self::restore_claimed_file($claim, $path, $label);
            throw new \RuntimeException("wprism: init preserved a raced $label during compensation");
        }
        $previous = $publication['previous'];
        if ($previous !== null) {
            $restore = $dir . '/.' . basename($path) . '.wprism-init-restore-' . bin2hex(random_bytes(8));
            Canon::write_file($restore, $previous);
            InitFaults::checkpoint('owned-file-restore');
            if (!@link($restore, $path)) {
                throw new \RuntimeException(
                    "wprism: init retained its prior $label at $restore because a concurrent writer owns $path"
                );
            }
            @unlink($restore);
        }
        @unlink($claim);
    }

    public static function remove_exact_owned_file(
        string $path,
        string $expectedIdentity,
        string $label,
        bool $completedJournal = false
    ): void {
        if (!is_file($path) || is_link($path)
            || !hash_equals($expectedIdentity, self::regular_file_identity($path, $label))) {
            throw new \RuntimeException("wprism: init preserved a replacement $label instead of removing external bytes");
        }
        if ($completedJournal) {
            InitFaults::checkpoint('attempt-remove-pre-unlink');
        }
        if (!@unlink($path)) {
            throw new \RuntimeException("wprism: init could not remove its exact $label");
        }
        Publish::sync_parent($path);
        if ($completedJournal) {
            InitFaults::checkpoint('attempt-remove-post-unlink');
        }
    }

    public static function directory_inode_identity(string $path, string $label): string {
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new \RuntimeException("wprism: init lost ownership of $label $path");
        }
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("wprism: init could not identify $label $path");
        }
        return 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
        ]));
    }

    public static function assert_directory_inode(string $path, string $expected, string $label): void {
        $actual = self::directory_inode_identity($path, $label);
        if (!hash_equals($expected, $actual)) {
            throw new \RuntimeException("wprism: init preserved a replacement $label instead of writing through it");
        }
    }

    public static function directory_identity(string $path, string $label): string {
        $inode = self::directory_inode_identity($path, $label);
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new \RuntimeException("wprism: init could not identify $label $path");
        }
        $rows = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $prefixLength = strlen(rtrim($path, '/')) + 1;
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            $relative = substr($itemPath, $prefixLength);
            $childStat = @lstat($itemPath);
            if (!is_array($childStat) || !isset($childStat['dev'], $childStat['ino'])) {
                throw new \RuntimeException("wprism: init could not identify $label child $relative");
            }
            $ownership = ['dev' => (string) $childStat['dev'], 'ino' => (string) $childStat['ino']];
            if ($item->isLink()) {
                $rows[] = ['path' => $relative, 'type' => 'link', 'ownership' => $ownership, 'target' => (string) readlink($itemPath)];
            } elseif ($item->isDir()) {
                $rows[] = ['path' => $relative, 'type' => 'directory', 'ownership' => $ownership];
            } elseif ($item->isFile()) {
                $digest = hash_file('sha256', $itemPath);
                if (!is_string($digest)) {
                    throw new \RuntimeException("wprism: init could not hash $label child $relative");
                }
                $rows[] = ['path' => $relative, 'type' => 'file', 'ownership' => $ownership, 'sha256' => $digest];
            } else {
                $rows[] = ['path' => $relative, 'type' => 'special', 'ownership' => $ownership];
            }
        }
        usort($rows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return 'sha256:' . hash('sha256', Canon::encode([
            'inode' => $inode,
            'tree' => $rows,
        ]));
    }

    public static function remove_owned_tree(string $path, string $identity, string $label): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)
            || !hash_equals($identity, self::directory_identity($path, $label))) {
            throw new \RuntimeException("wprism: init preserved a replacement $label instead of deleting external data");
        }
        $rootInode = self::directory_inode_identity($path, $label);
        $claim = dirname($path) . '/.' . basename($path) . '.wprism-init-remove-' . bin2hex(random_bytes(8));
        if (!@rename($path, $claim)) {
            throw new \RuntimeException("wprism: init could not claim its $label for cleanup");
        }
        InitFaults::checkpoint('owned-tree-claim');
        if (!hash_equals($identity, self::directory_identity($claim, $label))) {
            try {
                self::restore_claimed_tree($claim, $path, $rootInode, $label);
            } catch (\Throwable $restoreFailure) {
                throw new \RuntimeException(
                    "wprism: init retained a raced $label at $claim because it could not restore its canonical boundary: "
                    . $restoreFailure->getMessage(), 0, $restoreFailure
                );
            }
            throw new \RuntimeException("wprism: init preserved a raced $label instead of recursively deleting unowned data");
        }
        try {
            self::remove_tree($claim, $label);
            clearstatcache(true, $claim);
            if (file_exists($claim) || is_link($claim)) {
                throw new \RuntimeException("wprism: init could not remove its exact $label root completely");
            }
        } catch (\Throwable $cleanupFailure) {
            try {
                self::restore_claimed_tree($claim, $path, $rootInode, $label);
            } catch (\Throwable $restoreFailure) {
                throw new \RuntimeException(
                    "wprism: init retained its failed $label cleanup claim at $claim because it could not restore its canonical boundary: "
                    . $restoreFailure->getMessage(), 0, $cleanupFailure
                );
            }
            throw new \RuntimeException(
                "wprism: init could not remove its exact $label completely; restored its canonical boundary: "
                . $cleanupFailure->getMessage(), 0, $cleanupFailure
            );
        }
    }

    private static function restore_claimed_file(string $claim, string $path, string $label): void {
        if (!is_file($claim) || is_link($claim) || !@link($claim, $path)) {
            throw new \RuntimeException(
                "wprism: init retained a raced $label boundary at $claim because $path is no longer absent"
            );
        }
        @unlink($claim);
    }

    private static function restore_claimed_tree(
        string $claim,
        string $path,
        string $expectedInode,
        string $label
    ): void {
        if (is_link($claim) || !is_dir($claim)) {
            throw new \RuntimeException("wprism: init retained $label claim $claim because its root is no longer an ordinary directory");
        }
        self::assert_directory_inode($claim, $expectedInode, $label);
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("wprism: init retained $label claim $claim because its canonical boundary is no longer absent");
        }
        if (!@rename($claim, $path)) {
            throw new \RuntimeException("wprism: init could not restore its exact $label canonical boundary");
        }
        self::assert_directory_inode($path, $expectedInode, $label);
    }

    private static function remove_tree(string $path, string $label): void {
        if (is_link($path) || is_file($path)) {
            self::remove_tree_entry($path, false, $label);
            return;
        }
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            self::remove_tree_entry($item->getPathname(), $item->isDir() && !$item->isLink(), $label);
        }
        self::remove_tree_entry($path, true, $label);
    }

    private static function remove_tree_entry(string $path, bool $directory, string $label): void {
        $operation = $directory ? 'rmdir' : 'unlink';
        if (getenv('WPRISM_TEST_MODE') === '1'
            && getenv('WPRISM_TEST_INIT_FAIL_PHASE') === 'owned-tree-remove-' . $operation) {
            throw new \RuntimeException("wprism: injected exact-owned tree $operation refusal");
        }
        $removed = $directory ? @rmdir($path) : @unlink($path);
        if (!$removed) {
            throw new \RuntimeException("wprism: init could not $operation its exact $label path $path");
        }
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("wprism: init $operation left its exact $label path $path present");
        }
    }
}
