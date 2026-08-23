<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/WpOrgReleases.php';
require_once __DIR__ . '/ImportedArchives.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/PathSafety.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';

use Duo\CodeSourceLock;
use Duo\CommandRefusalException;
use Duo\PathSafety;

/**
 * Host-side materialization of the components `code/duo-code.lock.json`
 * declares (DUO-3500, phase 2 of the code-half split).
 *
 * WHAT THIS TURNS FROM A BUILD STEP INTO A VERB. DUO-3499 made the lock a
 * blocking, non-forceable compile precondition: a repository that declares a
 * component and carries none of its bytes refuses with
 * `code_component_unresolved` and is told to "run the materialization step
 * documented in docs/guides/code-updates.md … or duo code-resolve"
 * (agent/src/Code/CodeDescriptorCompiler.php:144-153). This class is that
 * step. It adds no algorithm: every byte-level decision — the content-addressed
 * cache, the refuse-don't-refetch rule, the delete-partial-and-refuse rule,
 * offline, the unpack entry check and the tree digest — already lives in
 * WpOrgReleases, where `duo init` and `duo code-classify` proved it out
 * against real archive bytes. An `imported-archive` origin takes the identical
 * path from the same cache's imported store (ImportedArchives) with no
 * network at all; what it cannot do is fetch, because the archive of a premium
 * component is the operator's to move between hosts (`duo code-import`), never
 * Duo's to download.
 *
 * ## Two invariants worth stating outright
 *
 * 1. **Nothing here ever runs on a target.** `code_release_provider`'s probe
 *    attests "off-target build and dependency resolution … no target Git
 *    history or registry credentials" (docs/code-release-runtime.md:24-28). A
 *    resolver reachable from `agent/` would make that attestation false for
 *    every site using both, which is why the whole class sits in `cli/src` and
 *    the agent is never told a registry exists.
 * 2. **A component that is already present is never rewritten.** Present with
 *    the declared digest is `unchanged`; present with any OTHER digest is a
 *    refusal (`code_resolve_component_drifted`), not an overwrite. The
 *    component's tree is `.gitignore`d by construction
 *    (CodeSourceLock::gitignore_line()), so those bytes exist in exactly one
 *    place on earth and silently replacing them would destroy the only copy of
 *    whatever the operator actually has.
 *
 * ## Atomicity
 *
 * Each component is unpacked into a fresh staging directory under
 * `<repo>/.duo/code-resolve/`, verified there, and only then `rename()`d into
 * `code/wp-content/<root>/<component>/`. The staging root is deliberately NOT
 * beside the target: `CodeDescriptorCompiler::descriptor_from_source()` walks
 * every entry under `plugins/` and `themes/` that satisfies
 * `PathSafety::safe_component()` (agent/src/Code/CodeDescriptorCompiler.php:250-256),
 * and a leading dot does not fail that predicate — so a staging directory left
 * behind by a hard kill would be inventoried as a real component and shipped.
 * `.duo/` is a repository-owned path init already writes and already ignores
 * (agent/src/Init/InitRepositoryBoundary.php:165,270), and it is inside the
 * same checkout, so the rename stays within one filesystem. A rename that
 * fails is a refusal, never a copy fallback.
 */
final class CodeResolver {
    /** The lock declares a component whose bytes are not the declared bytes. */
    public const REASON_DRIFTED = 'code_resolve_component_drifted';

    /** The unpacked release does not hash to the `tree_sha256` the lock declares. */
    public const REASON_TREE_DIGEST_MISMATCH = 'code_resolve_tree_digest_mismatch';

    /** An `imported-archive` origin names an archive this host's cache does not hold. */
    public const REASON_ARCHIVE_MISSING = ImportedArchives::REASON_ARCHIVE_MISSING;

    /** The archive could not be opened, or does not hold one component directory. */
    public const REASON_UNPACK_FAILED = 'code_resolve_unpack_failed';

    /** `site.duo.json` declares code format 2 but its lock cannot be read. */
    public const REASON_LOCK_UNREADABLE = 'code_resolve_lock_unreadable';

    /** The component path is present but is not a directory this may replace. */
    public const REASON_COMPONENT_UNSAFE = 'code_resolve_component_unsafe';

    /** Staging or the rename into place failed; the component was not written. */
    public const REASON_WRITE_FAILED = 'code_resolve_write_failed';

    /** Repository-relative staging root. Ignored by init's own `/.duo/` line. */
    public const STAGING = '.duo/code-resolve';

    private ImportedArchives $imported;

    public function __construct(private WpOrgReleases $releases) {
        // One cache root for both origins: the imported store is a
        // subdirectory of the same content-addressed cache wp.org releases
        // are fetched into, so `--cache-dir` moves both together.
        $this->imported = ImportedArchives::forReleases($releases);
    }

    /**
     * The nearest enclosing site repository, or null.
     *
     * Deliberately narrower than `AssessCommand::siteRepo()`, which also
     * asserts the Git worktree root and raises a typed refusal. Two reasons:
     * this verb writes only inside `code/wp-content` and runs no `git`
     * subprocess, so a worktree assertion would refuse a checkout it has no
     * need to refuse; and pulling AssessCommand into DeployCommand's load
     * graph would cross cli/duo's plain-`require` of the same file
     * (cli/duo:88, and the load-order note at :89-93) and fatal on
     * redeclaration.
     */
    public static function locateSiteRepo(string $startDir): ?string {
        $cursor = realpath($startDir) ?: $startDir;
        while (true) {
            if (is_file($cursor . '/site.duo.json')) {
                return $cursor;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                return null;
            }
            $cursor = $parent;
        }
    }

    /**
     * The lock one repository declares, or null when it declares none.
     *
     * Null means "code format 1, or no code half at all" — a fully vendored
     * repository that carries every byte in Git and has nothing to resolve.
     * A repository that declares format 2 and cannot produce a readable lock
     * refuses instead, exactly as compilation does
     * (agent/src/Code/CodeDescriptorCompiler.php:110-119): format 2 states
     * that this repository does NOT carry every component's bytes, so an
     * unreadable declaration is missing information, not an absent one.
     *
     * @return ?array{path:string,components:list<array<string,mixed>>}
     */
    public static function declaredLock(string $repo): ?array {
        $sitePath = rtrim($repo, '/') . '/site.duo.json';
        if (is_link($sitePath) || !is_file($sitePath)) {
            return null;
        }
        $document = json_decode((string) @file_get_contents($sitePath), true);
        $code = is_array($document) ? ($document['code'] ?? null) : null;
        if (!is_array($code) || ($code['format'] ?? null) !== 2) {
            return null;
        }
        $relative = $code['lock'] ?? null;
        if (!is_string($relative) || !PathSafety::safe_relative($relative)) {
            throw new CommandRefusalException(
                self::REASON_LOCK_UNREADABLE,
                'site.duo.json declares code format 2 without naming a repository-relative lock file',
                'restore the code.lock declaration, or return the repository to code format 1',
                [],
                "duo: $sitePath declares code format 2 but code.lock does not name a repository-relative path"
            );
        }
        $lockPath = rtrim($repo, '/') . '/' . $relative;
        if (is_link($lockPath) || !is_file($lockPath)) {
            throw new CommandRefusalException(
                self::REASON_LOCK_UNREADABLE,
                'site.duo.json declares code format 2 but the declared lock is not a regular file here',
                'restore the declared lock file from the repository, then rerun',
                [],
                "duo: site.duo.json code format 2 declares $relative, but it is not a regular file in $repo"
            );
        }
        try {
            $lock = CodeSourceLock::parse((string) @file_get_contents($lockPath));
        } catch (\Throwable $error) {
            throw new CommandRefusalException(
                self::REASON_LOCK_UNREADABLE,
                'the declared code lock is not a valid duo-code-lock/v1 document',
                'regenerate the lock with duo code-classify, or restore the reviewed one from Git',
                [],
                "duo: $relative is not a valid " . CodeSourceLock::FORMAT . ': ' . $error->getMessage(),
                $error
            );
        }
        return ['path' => $relative, 'components' => array_values((array) $lock['components'])];
    }

    /**
     * Resolve every declared component into `code/wp-content`.
     *
     * Returns one row per component in lock order (which is already sorted by
     * `(root, component)`), so the caller renders a report rather than
     * re-deriving one. Any refusal aborts: a partially-resolved repository is
     * still refused by the compile gate, and continuing past the first failure
     * would bury the one line that named it.
     *
     * @param list<array<string,mixed>> $components
     * @return list<array{root:string,component:string,version:string,state:string,detail:string}>
     */
    public function resolve(string $repo, array $components, bool $dryRun): array {
        $repo = rtrim($repo, '/');
        $rows = [];
        foreach ($components as $entry) {
            $root = (string) $entry['root'];
            $component = (string) $entry['component'];
            $version = (string) $entry['version'];
            $declared = (string) $entry['tree_sha256'];
            $origin = (array) $entry['origin'];
            $target = $repo . '/' . CodeSourceLock::SOURCE . '/' . $root . '/' . $component;

            $present = self::presentDigest($target, $root, $component);
            if ($present !== null) {
                if (!hash_equals($declared, $present)) {
                    throw new CommandRefusalException(
                        self::REASON_DRIFTED,
                        'a locked component is present with bytes other than the ones the lock declares',
                        'remove the component directory and rerun to re-materialize the locked release, or '
                        . 're-lock it with duo code-classify if the present bytes are the intended ones; '
                        . 'nothing overwrites a tree Git does not carry',
                        [],
                        "duo: $root/$component is present hashing to $present, but the lock declares $declared "
                        . "for version $version; nothing was written"
                    );
                }
                $rows[] = self::row($root, $component, $version, 'unchanged', 'already present at the locked digest');
                continue;
            }

            $kind = (string) ($origin['kind'] ?? '');
            if ($dryRun) {
                $rows[] = self::row(
                    $root,
                    $component,
                    $version,
                    'would-resolve',
                    $kind === 'wp-org-release'
                        ? 'would fetch and verify ' . (string) $origin['url']
                        : 'would unpack and verify imported archive ' . (string) $origin['archive_sha256']
                );
                continue;
            }

            $archive = $kind === 'wp-org-release'
                ? $this->releaseArchive($origin)
                : $this->importedArchive($origin, $root, $component, $version);
            $this->materialize($repo, $root, $component, $version, $declared, $archive['path'], $origin);
            $rows[] = self::row($root, $component, $version, 'resolved', 'verified and materialized from ' . $archive['source']);
        }
        return $rows;
    }

    /**
     * The digest of the component already on disk, or null when absent.
     *
     * A symlink or a plain file at the component path is neither "absent" nor
     * "present at some digest": it is a repository the descriptor compiler
     * would itself refuse (agent/src/Code/CodeDescriptorCompiler.php:260-271),
     * and renaming a verified tree over it would hide that. A LOCKED component
     * is always a directory — `component_inventory()` skips any owned root
     * with no rows beneath it
     * (agent/src/Code/CodeDescriptorCompiler.php:351-354), so a single-file
     * plugin can never be classified and can never appear in a lock.
     */
    private static function presentDigest(string $target, string $root, string $component): ?string {
        if (is_link($target) || (file_exists($target) && !is_dir($target))) {
            throw new CommandRefusalException(
                self::REASON_COMPONENT_UNSAFE,
                'a locked component path exists but is not an ordinary directory',
                'remove the link or file at the component path, then rerun',
                [],
                "duo: $root/$component exists at $target but is not an ordinary directory"
            );
        }
        if (!is_dir($target)) {
            return null;
        }
        try {
            return WpOrgReleases::treeDigest($target);
        } catch (\Throwable $error) {
            throw new CommandRefusalException(
                self::REASON_COMPONENT_UNSAFE,
                'a locked component is present but cannot be hashed as a plain file tree',
                'remove the offending entry (a symbolic link, a device node, an unreadable file) and rerun',
                [],
                "duo: $root/$component could not be hashed in place: " . $error->getMessage(),
                $error
            );
        }
    }

    /**
     * The verified archive for a `wp-org-release` origin.
     *
     * `fetch()` is handed the lock's `archive_sha256`, so a cache HIT is
     * re-verified against the LOCK rather than against the sidecar it wrote
     * itself, and a download that disagrees deletes only its partial file and
     * refuses (cli/src/Code/WpOrgReleases.php:145-224). Both refusals arrive
     * already carrying their reason code, which is why nothing here re-derives
     * one from message text.
     *
     * @param array<string,mixed> $origin
     * @return array{path:string,source:string}
     */
    private function releaseArchive(array $origin): array {
        $result = $this->releases->fetch((string) $origin['url'], (string) $origin['archive_sha256']);
        return ['path' => $result['path'], 'source' => 'the host cache (' . $result['source'] . ')'];
    }

    /**
     * The verified archive for an `imported-archive` origin.
     *
     * Same verification, no network: the archive is in this host's imported
     * store or it is not, and the store re-verifies its bytes against the
     * digest the lock names before handing the path over
     * (ImportedArchives::archivePath()). The one refusal names the one
     * remedy — import the vendor's archive on this host — because there is
     * nothing Duo could fetch on the operator's behalf.
     *
     * @param array<string,mixed> $origin
     * @return array{path:string,source:string}
     */
    private function importedArchive(array $origin, string $root, string $component, string $version): array {
        return [
            'path' => $this->imported->archivePath((string) $origin['archive_sha256'], $root, $component, $version),
            'source' => 'the host cache (imported archive)',
        ];
    }

    /**
     * Unpack, verify, and rename one component into place.
     *
     * The order is the whole contract: nothing reaches `code/wp-content` until
     * the unpacked tree has been hashed and found equal to the lock's
     * `tree_sha256`. A wp.org version can be re-packaged under an unchanged
     * archive digest, which is exactly why the lock carries both digests and
     * why this second check is not redundant.
     *
     * @param array<string,mixed> $origin
     */
    private function materialize(
        string $repo,
        string $root,
        string $component,
        string $version,
        string $declared,
        string $archivePath,
        array $origin
    ): void {
        $staging = $repo . '/' . self::STAGING . '/' . bin2hex(random_bytes(8));
        if (!@mkdir($staging, 0775, true) && !is_dir($staging)) {
            throw new CommandRefusalException(
                self::REASON_WRITE_FAILED,
                'the host could not create a staging directory inside the site repository',
                'restore write permission on the repository, then rerun',
                [],
                "duo: could not create the code-resolve staging directory $staging"
            );
        }
        try {
            try {
                $this->releases->unpack($archivePath, $staging);
                // A DECLARED archive_root wins over detection. The classifier
                // records it precisely when the archive's component directory
                // is not named after the slug
                // (cli/src/Code/WpOrgReleases.php:428-430), and archiveRoot()
                // would answer `<slug>` for an archive that carries both, so
                // detecting here would resolve a different directory than the
                // one the lock was written against. The grammar has already
                // proved the value is a safe relative path
                // (agent/src/Code/CodeSourceLock.php:205-210).
                $archiveRoot = is_string($origin['archive_root'] ?? null)
                    ? (string) $origin['archive_root']
                    : WpOrgReleases::archiveRoot($staging, $component);
                if (!is_dir($staging . '/' . $archiveRoot) || is_link($staging . '/' . $archiveRoot)) {
                    throw new \RuntimeException(
                        "the archive holds no '$archiveRoot' directory for this component"
                    );
                }
            } catch (\Throwable $error) {
                throw new CommandRefusalException(
                    self::REASON_UNPACK_FAILED,
                    'a release archive could not be unpacked into one component directory',
                    'inspect the archive the lock names; a re-packaged or multi-root archive is not a component '
                    . 'tree and is refused rather than guessed at',
                    [],
                    "duo: $root/$component could not be unpacked: " . $error->getMessage(),
                    $error
                );
            }
            $digest = WpOrgReleases::treeDigest($staging . '/' . $archiveRoot);
            if (!hash_equals($declared, $digest)) {
                throw new CommandRefusalException(
                    self::REASON_TREE_DIGEST_MISMATCH,
                    'an unpacked release does not hash to the tree digest the lock declares',
                    're-lock the component with duo code-classify if the release was legitimately re-packaged; '
                    . 'nothing was written into the component tree',
                    [],
                    "duo: $root/$component version $version unpacks to $digest, but the lock declares $declared; "
                    . 'nothing was written'
                );
            }
            $parent = $repo . '/' . CodeSourceLock::SOURCE . '/' . $root;
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new CommandRefusalException(
                    self::REASON_WRITE_FAILED,
                    'the host could not create the component root inside code/wp-content',
                    'restore write permission on the repository, then rerun',
                    [],
                    "duo: could not create $parent"
                );
            }
            if (!@rename($staging . '/' . $archiveRoot, $parent . '/' . $component)) {
                throw new CommandRefusalException(
                    self::REASON_WRITE_FAILED,
                    'the verified component could not be renamed into place',
                    'the staging root and code/wp-content must be on one filesystem; nothing was copied and '
                    . 'nothing partial was left behind',
                    [],
                    "duo: could not publish $root/$component from $staging/$archiveRoot into $parent/$component"
                );
            }
        } finally {
            self::removeStaging($staging);
        }
    }

    /**
     * Remove one staging directory this class created, and nothing else.
     *
     * The guard is the point: this runs in a `finally` on a path built from
     * caller-supplied strings, and a recursive delete with no identity check
     * is one refactor away from being pointed at `code/wp-content`.
     */
    private static function removeStaging(string $path): void {
        if (!str_contains($path, '/' . self::STAGING . '/') || !is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** @return array{root:string,component:string,version:string,state:string,detail:string} */
    private static function row(string $root, string $component, string $version, string $state, string $detail): array {
        return [
            'root' => $root,
            'component' => $component,
            'version' => $version,
            'state' => $state,
            'detail' => $detail,
        ];
    }
}
