<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Code/Code.php';
require_once __DIR__ . '/InitCodeInventory.php';
require_once __DIR__ . '/InitExceptions.php';
require_once __DIR__ . '/InitOwnedArtifacts.php';
require_once __DIR__ . '/../Publication/PublicationJournal.php';
require_once __DIR__ . '/../Publication/Publish.php';

/**
 * Captures and validates the first immutable code baseline.
 */
final class InitCodeBaseline {
    /** @return array{0:array<string,mixed>,1:string,2:string} descriptor, staging root, ownership identity */
    public static function capture(string $repo, array $code, ?callable $onStage = null): array {
        $blockers = [];
        $inventory = InitCodeInventory::inventory((array) ($code['roots'] ?? []), (array) ($code['components'] ?? []), $blockers);
        if ($blockers !== []) {
            throw new \RuntimeException('duo: code changed into an unsupported shape after confirmation');
        }
        $revision = hash('sha256', Canon::encode($inventory['files']));
        if (!hash_equals((string) ($code['source_revision'] ?? ''), $revision)) {
            throw new \RuntimeException('duo: code changed after proposal review; rerun init and review the new digest');
        }

        $stage = $repo . '/.duo-init-code-' . bin2hex(random_bytes(8));
        if ($onStage !== null) {
            $onStage($stage, null);
        }
        $stageParent = dirname($stage);
        // DUO-3425: one inode-bound helper for the whole capture walk (the
        // 6062-file hot path) — the same per-op CWD-as-inode-capability
        // guarantee as a fresh per-file spawn, but ONE subprocess for the
        // whole tree. The finally guarantees a mid-walk throw can never leak
        // a live child. The stage-root create sits inside this try only for
        // that teardown — its own failure still bypasses the inner
        // compensation exactly as before (there is nothing to compensate).
        $helper = new BoundHelper();
        try {
            $stagePublication = Publish::create_directory_fresh(
                $stageParent,
                Publish::directory_ownership_identity($stageParent),
                basename($stage),
                0775,
                'code capture staging directory',
                $helper
            );
            // DUO-3421: the staging tree's full content identity is computed where
            // it is actually consumed -- once on success (returned to the caller,
            // which journals it as the deletion authority for this tree) and once
            // in the catch below before compensation. It used to be recomputed
            // after EVERY copied file and every created directory, into a by-ref
            // accumulator nothing ever read: directory_identity() walks and lstats
            // the whole tree, so staging a real wp-content payload cost O(n^2)
            // syscalls: a 6062-file payload -- the size an ordinary commerce site
            // carries -- meant about 18 million lstats, measured live at under one
            // file per second on a bind mount and slowing as it went, i.e. the
            // first-run experience the live evidence budget exists to bound could
            // not finish at all. Every ownership guarantee is unchanged: each file and
            // directory is still created through its parent-bound Publish
            // primitive, and the stage root inode is still re-asserted at every
            // step through assert_directory_inode().
            $stageRootIdentity = InitOwnedArtifacts::directory_inode_identity($stage, 'code capture staging directory');
            $ownedDirs = ['' => $stagePublication];
            if ($onStage !== null) {
                $onStage($stage, $stageRootIdentity);
            }
            try {
                foreach ((array) ($code['components'] ?? []) as $rootName => $names) {
                    $sourceRoot = (string) (($code['roots'][$rootName] ?? null) ?: '');
                    foreach ((array) $names as $name) {
                        InitOwnedArtifacts::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
                        self::copy_code_path(
                            rtrim($sourceRoot, '/') . '/' . $name,
                            $stage . '/' . $rootName . '/' . $name,
                            $stage,
                            $stageRootIdentity,
                            $ownedDirs,
                            $helper
                        );
                    }
                }
                InitOwnedArtifacts::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
                self::assert_staged_code_no_secrets($stage);
                $descriptor = Code::descriptor_from_source($stage);
                $copiedRevision = hash('sha256', Canon::encode($descriptor['files']));
                if (!hash_equals($revision, $copiedRevision)) {
                    throw new \RuntimeException('duo: code changed while the baseline was being copied; rerun init');
                }
                return [$descriptor, $stage, InitOwnedArtifacts::directory_identity($stage, 'code capture staging directory')];
            } catch (\Throwable $error) {
                try {
                    // A bound copy can create its destination and then reject a
                    // changed source digest before the caller refreshes the stage
                    // manifest. Re-identify the Duo-created partial tree under the
                    // confirmed repository-writer exclusion before compensating it.
                    $currentIdentity = InitOwnedArtifacts::directory_identity($stage, 'code capture staging directory');
                    InitOwnedArtifacts::remove_owned_tree($stage, $currentIdentity, 'code capture staging directory');
                } catch (\Throwable $cleanupError) {
                    throw new InitAttemptRetentionException(
                        $error->getMessage()
                        . "\nduo: init retained the partial code staging tree for sealed fresh-process recovery: "
                        . $cleanupError->getMessage(),
                        0,
                        $error
                    );
                }
                throw $error;
            }
        } finally {
            $helper->close();
        }
    }

    /** @param array<string,array<string,string>> $ownedDirs */
    private static function copy_code_path(
        string $source,
        string $destination,
        string $stage,
        string $stageRootIdentity,
        array &$ownedDirs,
        BoundHelper $helper
    ): void {
        if (is_link($source)) {
            throw new \RuntimeException("duo: refusing symbolic-link code source $source");
        }
        if (is_file($source)) {
            $parent = dirname($destination);
            self::ensure_code_stage_directory($stage, $parent, $stageRootIdentity, $ownedDirs, $helper);
            $parentKey = trim(substr($parent, strlen(rtrim($stage, '/'))), '/');
            self::copy_code_file_fresh(
                $source,
                $destination,
                $stage,
                $stageRootIdentity,
                $ownedDirs[$parentKey],
                $helper
            );
            return;
        }
        if (!is_dir($source)) {
            throw new \RuntimeException("duo: code source disappeared before copy: $source");
        }
        self::ensure_code_stage_directory($stage, $destination, $stageRootIdentity, $ownedDirs, $helper);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $target = $destination . '/' . substr($path, strlen(rtrim($source, '/')) + 1);
            if ($item->isLink()) {
                throw new \RuntimeException("duo: refusing symbolic-link code source $path");
            }
            if ($item->isDir()) {
                self::ensure_code_stage_directory($stage, $target, $stageRootIdentity, $ownedDirs, $helper);
                continue;
            }
            if (!$item->isFile()) {
                throw new \RuntimeException("duo: could not copy regular code file $path");
            }
            self::ensure_code_stage_directory($stage, dirname($target), $stageRootIdentity, $ownedDirs, $helper);
            $parentKey = trim(substr(dirname($target), strlen(rtrim($stage, '/'))), '/');
            self::copy_code_file_fresh(
                $path,
                $target,
                $stage,
                $stageRootIdentity,
                $ownedDirs[$parentKey],
                $helper
            );
        }
    }

    /** @param array<string,array<string,string>> $ownedDirs */
    private static function ensure_code_stage_directory(
        string $stage,
        string $directory,
        string $stageRootIdentity,
        array &$ownedDirs,
        BoundHelper $helper
    ): void {
        InitOwnedArtifacts::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
        $prefix = rtrim($stage, '/') . '/';
        if (!str_starts_with($directory . '/', $prefix)) {
            throw new \RuntimeException('duo: code staging destination escaped its owned root');
        }
        $relative = trim(substr($directory, strlen(rtrim($stage, '/'))), '/');
        $current = rtrim($stage, '/');
        $key = '';
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            if (!self::safe_stage_component($part)) {
                throw new \RuntimeException('duo: code staging destination has an unsafe component');
            }
            $key = $key === '' ? $part : $key . '/' . $part;
            if (!isset($ownedDirs[$key])) {
                $parentKey = str_contains($key, '/') ? substr($key, 0, (int) strrpos($key, '/')) : '';
                $ownedDirs[$key] = Publish::create_directory_fresh(
                    $current,
                    $ownedDirs[$parentKey],
                    $part,
                    0775,
                    'code staging child directory',
                    $helper
                );
                $current .= '/' . $part;
            } else {
                $current .= '/' . $part;
                if (Publish::directory_ownership_identity($current) !== $ownedDirs[$key]) {
                    throw new \RuntimeException('duo: code staging child directory changed identity');
                }
            }
        }
        InitOwnedArtifacts::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
    }

    private static function copy_code_file_fresh(
        string $source,
        string $destination,
        string $stage,
        string $stageRootIdentity,
        array $expectedParent,
        BoundHelper $helper
    ): void {
        InitOwnedArtifacts::assert_directory_inode($stage, $stageRootIdentity, 'code capture staging directory');
        if (file_exists($destination) || is_link($destination)) {
            throw new \RuntimeException('duo: code staging gained an unowned destination file');
        }
        $digest = @hash_file('sha256', $source);
        $mode = @fileperms($source);
        if (!is_string($digest) || !is_int($mode)) {
            throw new \RuntimeException('duo: could not identify the reviewed code source before copy');
        }
        $expectedDigest = $digest;
        if (getenv('DUO_TEST_MODE') === '1'
            && getenv('DUO_TEST_INIT_FAIL_PHASE') === 'code-copy-after-file') {
            // Exercise the bound helper's post-create source-digest refusal:
            // the helper must remove only the destination inode it created.
            $expectedDigest = str_repeat('0', 64);
        }
        Publish::copy_file_fresh(
            $source,
            $destination,
            $expectedDigest,
            $mode & 0777,
            'code staging file',
            $expectedParent,
            $helper
        );
    }

    /** The exact copied bytes get their own redacted credential gate. */
    private static function assert_staged_code_no_secrets(string $stage): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $label = InitCodeInventory::secretLabel($item->getPathname());
            if ($label !== null) {
                throw new \RuntimeException(
                    "duo: captured code contains a high-confidence $label; the value is redacted and was not published"
                );
            }
        }
    }

    /**
     * A path component the code STAGING tree may create — deliberately not
     * InitCodeInventory::safeIdentifier()'s identifier charset.
     *
     * DUO-3421. safeIdentifier() names something Duo SELECTS: an active
     * plugin's basename, an active theme's slug. A staging component is
     * different in kind: it names a directory the site already has, inside a
     * payload Duo's job is to carry, and real extension and theme trees ship
     * names outside [A-Za-z0-9._-] as a matter of course — build outputs keep
     * their npm scope directories, whose names begin with '@', and font assets
     * carry ',' in their filenames. The identifier charset therefore made
     * `duo init` refuse to stage ordinary shipped bytes with "code staging
     * destination has an unsafe component", after the journal and lock already
     * existed. (File leaves were never charset-checked at all, so a directory
     * was held to a stricter rule than the files beside it.)
     *
     * The predicate that matters here is traversal and literal-component
     * safety, and the code half already has exactly that one for the entire
     * rest of these paths' lifecycle — Code::safe_relative()/safe_component(),
     * which every descriptor, materializer, and deploy check applies. This
     * mirrors it component-wise, so init can never stage a path the code half
     * would later refuse to carry, nor refuse one it would accept. Containment
     * is unchanged and does not rest on the charset: the caller still requires
     * the destination to start with the owned stage prefix, still creates each
     * directory through Publish::create_directory_fresh() bound to its
     * parent's identity, and still re-asserts the stage root inode every step.
     */
    public static function safe_stage_component(string $value): bool {
        return $value !== '' && $value !== '.' && $value !== '..'
            && !str_contains($value, '/') && !str_contains($value, '\\')
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

}
