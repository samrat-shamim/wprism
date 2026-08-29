<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/WpOrgReleases.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/PathSafety.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeCompatibility.php';

use WPrism\Canon;
use WPrism\CodeCompatibility;
use WPrism\CodeSourceLock;
use WPrism\CommandRefusalException;
use WPrism\PathSafety;

/**
 * The host's store of imported release archives — the origin a premium,
 * private, or otherwise non-wp.org plugin or theme locks against.
 *
 * WHY THIS EXISTS. The code half's invariant is that Git never carries
 * third-party bytes (agent/src/Code/CodeSourceLock.php). A wp.org release
 * satisfies it by being re-fetchable from a canonical URL; a premium plugin
 * has no such URL — its download is license-keyed, and a ZIP committed in the
 * repository (issue #3499's `vendored-archive`) is third-party bytes in Git by
 * another name. So the archive lives HERE, on the orchestrator host, in the
 * same content-addressed cache wp.org releases are fetched into, and the lock
 * records only its `archive_sha256`. Nothing credential-bearing and nothing
 * byte-bearing reaches the repository; the operator moves the vendor's ZIP to
 * each host that resolves (`wprism code-import`), exactly as they would move it
 * to each server by hand today.
 *
 * The egress rule is unchanged: nothing here runs on a target, and nothing
 * here fetches. Import reads one local file the operator named.
 *
 * ## Layout, under the shared code-artifact cache directory
 *
 *   imported/<archive_sha256>.zip    the bytes, content-addressed
 *   imported/<archive_sha256>.json   what the archive holds: archive_root,
 *                                    component, root, tree_sha256, version
 *   imported/by-tree/<tree_sha256>   the archive_sha256 whose unpacked
 *                                    component hashes to this tree — the
 *                                    index the classifier looks a site's
 *                                    installed component up by
 *
 * An entry is never overwritten: a cached byte that no longer hashes to its
 * own name is evidence of a corrupted or tampered cache and refuses
 * (`code_resolve_cache_corrupt`), the same rule WpOrgReleases::fetch() keeps.
 */
final class ImportedArchives {
    /** A lock names an imported archive this host's cache does not hold. */
    public const REASON_ARCHIVE_MISSING = 'code_resolve_archive_missing';

    /** The file the operator named cannot be imported as one component archive. */
    public const REASON_IMPORT_REFUSED = 'code_import_refused';

    public const ORIGIN_KIND = 'imported-archive';

    private const SUBDIR = 'imported';

    public function __construct(private string $cacheDir, private WpOrgReleases $releases) {
    }

    public static function forReleases(WpOrgReleases $releases): self {
        return new self($releases->cacheDir(), $releases);
    }

    /** The store root; created lazily by import(). */
    public function directory(): string {
        return rtrim($this->cacheDir, '/') . '/' . self::SUBDIR;
    }

    public function archivePathFor(string $archiveSha256): string {
        return $this->directory() . '/' . $archiveSha256 . '.zip';
    }

    /**
     * Import one archive the operator holds. Returns the identity the lock
     * will record and the facts the operator reviews.
     *
     * `$component` is required only when the archive's single top-level
     * directory is not named after the component (a tag archive that unpacks
     * to `<slug>-<version>/`); wp.org-shaped archives carry the slug as
     * their only top-level directory and need nothing.
     *
     * @return array{archive_root:string,archive_sha256:string,component:string,path:string,root:string,state:string,tree_sha256:string,version:string}
     */
    public function import(string $archivePath, ?string $component, string $root): array {
        if (!in_array($root, CodeSourceLock::ROOTS, true)) {
            throw new CommandRefusalException(
                self::REASON_IMPORT_REFUSED,
                'an imported archive must be a plugin or a theme component',
                'pass --root=plugins or --root=themes',
                [],
                'wprism: code-import root must be one of ' . implode('/', CodeSourceLock::ROOTS) . ", not '$root'"
            );
        }
        if ($component !== null && !PathSafety::safe_component($component)) {
            throw new CommandRefusalException(
                self::REASON_IMPORT_REFUSED,
                'the declared component is not one safe path segment',
                'pass --component=<slug> with the directory name the component installs under',
                [],
                "wprism: code-import --component '$component' is not one safe path segment"
            );
        }
        if (is_link($archivePath) || !is_file($archivePath) || !is_readable($archivePath)) {
            throw new CommandRefusalException(
                self::REASON_IMPORT_REFUSED,
                'the named archive is not a readable regular file',
                'name the vendor release archive (.zip) as a regular file on this host',
                [],
                "wprism: code-import cannot read $archivePath as a regular file"
            );
        }
        $archiveSha256 = hash_file('sha256', $archivePath);
        if (!is_string($archiveSha256)) {
            throw new \RuntimeException("wprism: could not digest $archivePath");
        }

        $unpacked = WpOrgReleases::temporaryDirectory();
        try {
            try {
                $this->releases->unpack($archivePath, $unpacked);
                $archiveRoot = $component !== null
                    ? WpOrgReleases::archiveRoot($unpacked, $component)
                    : self::soleDirectory($unpacked);
                $component ??= $archiveRoot;
                $treeSha256 = WpOrgReleases::treeDigest($unpacked . '/' . $archiveRoot);
                $version = self::headerVersion($unpacked . '/' . $archiveRoot, $root);
            } catch (\Throwable $error) {
                throw new CommandRefusalException(
                    self::REASON_IMPORT_REFUSED,
                    'the archive could not be read as exactly one component directory',
                    'a release archive unpacks to one plugin or theme directory; pass --component=<slug> when that '
                    . 'directory is not named after the component, and do not import multi-component bundles',
                    [],
                    "wprism: code-import could not read $archivePath as one component: " . $error->getMessage(),
                    $error
                );
            }
        } finally {
            WpOrgReleases::removeTree($unpacked);
        }

        $directory = $this->directory();
        foreach ([$directory, $directory . '/by-tree'] as $needed) {
            if (!is_dir($needed) && !@mkdir($needed, 0775, true) && !is_dir($needed)) {
                throw new \RuntimeException("wprism: could not create the imported-archive store at $needed");
            }
        }
        $stored = $this->archivePathFor($archiveSha256);
        $state = 'imported';
        if (is_file($stored)) {
            $this->assertIntact($stored, $archiveSha256);
            $state = 'already-imported';
        } else {
            $temporary = $stored . '.part.' . bin2hex(random_bytes(6));
            try {
                if (!@copy($archivePath, $temporary)) {
                    throw new \RuntimeException("wprism: could not copy $archivePath into the imported-archive store");
                }
                $copied = hash_file('sha256', $temporary);
                if (!is_string($copied) || !hash_equals($archiveSha256, $copied)) {
                    throw new \RuntimeException('wprism: the archive changed while being imported; nothing was stored');
                }
                if (!@rename($temporary, $stored)) {
                    throw new \RuntimeException("wprism: could not publish the imported archive into $stored");
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        $metadata = [
            'archive_root' => $archiveRoot,
            'component' => $component,
            'root' => $root,
            'tree_sha256' => $treeSha256,
            'version' => $version,
        ];
        Canon::write_file($this->directory() . '/' . $archiveSha256 . '.json', Canon::encode($metadata));
        // First import of a tree wins the index: a second archive unpacking to
        // the same bytes is a re-packaging, and the classifier needs one
        // answer per tree, not the most recent one.
        $indexPath = $this->directory() . '/by-tree/' . $treeSha256;
        if (!is_file($indexPath)) {
            Canon::write_file($indexPath, $archiveSha256 . "\n");
        }
        return [
            'archive_root' => $archiveRoot,
            'archive_sha256' => $archiveSha256,
            'component' => $component,
            'path' => $stored,
            'root' => $root,
            'state' => $state,
            'tree_sha256' => $treeSha256,
            'version' => $version,
        ];
    }

    /**
     * The lock origin for a component whose installed tree hashes to
     * `$treeSha256`, or null when no imported archive unpacks to it.
     *
     * The archive's own bytes are re-verified on every lookup, so an entry
     * the store can no longer honour is never offered to a classifier.
     *
     * @return ?array{archive_root?:string,archive_sha256:string,kind:string}
     */
    public function originForTree(string $treeSha256, string $root, string $component): ?array {
        if (preg_match('/^[0-9a-f]{64}$/', $treeSha256) !== 1) {
            return null;
        }
        $indexPath = $this->directory() . '/by-tree/' . $treeSha256;
        if (is_link($indexPath) || !is_file($indexPath)) {
            return null;
        }
        $archiveSha256 = trim((string) @file_get_contents($indexPath));
        if (preg_match('/^[0-9a-f]{64}$/', $archiveSha256) !== 1) {
            return null;
        }
        $metadata = $this->metadata($archiveSha256);
        if ($metadata === null
            || ($metadata['tree_sha256'] ?? null) !== $treeSha256
            || ($metadata['root'] ?? null) !== $root
            || ($metadata['component'] ?? null) !== $component) {
            // An index entry that points at an archive describing a
            // different identity is not "almost right": the classifier would
            // lock plugins/a against an archive that unpacks to plugins/b.
            return null;
        }
        $this->assertIntact($this->archivePathFor($archiveSha256), $archiveSha256);
        $origin = ['kind' => self::ORIGIN_KIND, 'archive_sha256' => $archiveSha256];
        if (($metadata['archive_root'] ?? $component) !== $component) {
            $origin['archive_root'] = (string) $metadata['archive_root'];
        }
        return $origin;
    }

    /**
     * The verified archive for an `imported-archive` lock origin, or the
     * refusal that names the one remedy: import it on this host.
     */
    public function archivePath(string $archiveSha256, string $root, string $component, string $version): string {
        $path = $this->archivePathFor($archiveSha256);
        if (is_link($path) || !is_file($path)) {
            throw new CommandRefusalException(
                self::REASON_ARCHIVE_MISSING,
                'a locked component names an imported archive this host\'s code-artifact cache does not hold',
                'obtain the component\'s release archive from its vendor and import it on this host with '
                . '`wprism code-import <archive.zip>`, then rerun; WPrism never fetches from a vendor and the repository '
                . 'deliberately carries no copy',
                [],
                "wprism: $root/$component version $version is locked to imported archive $archiveSha256, which is not "
                . 'in the imported-archive store at ' . $this->directory()
            );
        }
        $this->assertIntact($path, $archiveSha256);
        return $path;
    }

    /** @return ?array<string,mixed> */
    private function metadata(string $archiveSha256): ?array {
        $path = $this->directory() . '/' . $archiveSha256 . '.json';
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function assertIntact(string $path, string $archiveSha256): void {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || !hash_equals($archiveSha256, $digest)) {
            throw new CommandRefusalException(
                WpOrgReleases::REASON_CACHE_CORRUPT,
                'an imported archive no longer hashes to the digest it is stored under',
                'inspect the named store entry, remove it by hand, and re-import the archive; nothing overwrites '
                . 'evidence of a corrupted or tampered cache',
                [],
                "wprism: the imported archive at $path no longer hashes to $archiveSha256; inspect and remove it by hand"
            );
        }
    }

    /** The one top-level directory of an unpacked archive, which names the component. */
    private static function soleDirectory(string $unpacked): string {
        $children = array_values(array_diff((array) @scandir($unpacked), ['.', '..']));
        if (count($children) !== 1 || !is_dir($unpacked . '/' . $children[0]) || is_link($unpacked . '/' . $children[0])
            || !PathSafety::safe_component((string) $children[0])) {
            throw new \RuntimeException(
                'the archive does not unpack to exactly one component directory; pass --component=<slug> if its '
                . 'top-level directory is not named after the component'
            );
        }
        return (string) $children[0];
    }

    /**
     * The `Version:` header the unpacked component declares — informational
     * for the operator; the lock's version comes from the installed bytes the
     * classifier was handed, which are the same bytes by construction.
     */
    private static function headerVersion(string $componentDir, string $root): string {
        if ($root === 'themes') {
            $style = $componentDir . '/style.css';
            return is_file($style) && !is_link($style)
                ? (string) (CodeCompatibility::header_value($style, 'Version') ?? '')
                : '';
        }
        foreach ((array) @scandir($componentDir) as $entry) {
            $file = $componentDir . '/' . $entry;
            if (!is_string($entry) || !str_ends_with($entry, '.php') || is_link($file) || !is_file($file)) {
                continue;
            }
            if (CodeCompatibility::header_value($file, 'Plugin Name') !== null) {
                return (string) (CodeCompatibility::header_value($file, 'Version') ?? '');
            }
        }
        return '';
    }
}
