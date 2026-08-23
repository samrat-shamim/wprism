<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/PathSafety.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';

use Duo\CodeSourceLock;
use Duo\CommandRefusalException;
use Duo\PathSafety;

/**
 * Host-side wp.org release sourcing: cache, fetch, verify, unpack, digest.
 *
 * WHY THIS IS ON THE HOST AND NOWHERE ELSE. `code_release_provider`'s probe
 * already attests "off-target build and dependency resolution … no mutable
 * resolution, and no target Git history or registry credentials"
 * (docs/code-release-runtime.md:24-28). A fetcher inside `agent/` would make
 * that attestation false for any site using both, so this class exists on the
 * orchestrator side and the agent is never told a registry exists. It is also
 * dependency-free host PHP (AGENTS.md rule 1): `curl` or PHP streams for the
 * download, ZipArchive for the unpack, and a loud refusal naming the remedy
 * when ZipArchive is absent.
 *
 * ## API, and what DUO-3500's `duo code-resolve` reuses
 *
 * The verb split is deliberate so the resolver adds an entry point, not an
 * algorithm:
 *
 * - `defaultCacheDir()` / `cachePath()` — the content-addressed host cache
 *   (`$XDG_CACHE_HOME/duo/code-artifacts`, else `~/.cache/duo/code-artifacts`),
 *   keyed by the sha256 of the CANONICAL url so two callers with different
 *   mirrors still share one entry.
 * - `fetch(string $canonicalUrl, ?string $expectedSha256)` — cache-first, with
 *   the four semantics the sandbox artifact cache proved out: a cache HIT is
 *   re-verified rather than trusted; a corrupt cache entry REFUSES instead of
 *   silently re-fetching (a byte that changed under a digest is evidence, not
 *   a transient); a download whose digest does not match deletes only the
 *   partial file and refuses; and `--offline` refuses every fetch on a miss.
 *   There is no latest-fallback anywhere: a version is pinned or it is not
 *   resolved.
 * - `unpack(archive, destination)` / `archiveRoot()` — extraction with every
 *   traversing or absolute entry refused BEFORE anything is written.
 * - `treeDigest(dir)` — the unpacked tree's `tree_sha256`, computed through
 *   `CodeSourceLock::tree_sha256()`, i.e. the same rows the agent's descriptor
 *   compiler builds. This is the one function that makes "the release equals
 *   the installed tree" a decidable question rather than a guess.
 * - `verifiedRelease(root, slug, version, tree)` — the wp.org leg of the
 *   classification CodeClassifier composes: the `wp-org-release` origin when
 *   the published release unpacks to exactly these bytes, otherwise the
 *   STATED reason it does not. Init's and `code-classify`'s shared decision
 *   itself (locked / first-party / unsourced) lives in CodeClassifier, which
 *   adds the imported-archive leg (ImportedArchives) and the operator's
 *   first-party declarations; a resolver needs neither.
 *
 * ## Why fetch() raises a REASON-CODED refusal
 *
 * The three fetch failures below are the only ones a caller can act on
 * differently, and `duo code-resolve` (DUO-3500) is the caller that lets them
 * escape: `verifiedRelease()` folds every one of them into a stated "no
 * verified wp.org release…" reason and never rethrows. Naming the reason where the failure is
 * DETECTED — rather than re-deriving it in the resolver by matching on message
 * text — is what keeps `code_resolve_cache_corrupt` and
 * `code_resolve_archive_digest_mismatch` from silently collapsing into one
 * "fetch failed" arm the day a message is reworded. The operator message each
 * refusal carries is unchanged and still holds the url, the digests and the
 * cache path; the PUBLIC message must not, because
 * CommandRefusalException redacts every public field naming a home directory
 * (agent/src/Kernel/CommandRefusal.php:199).
 *
 * `DUO_CODE_ARTIFACT_BASE` overrides only where bytes are FETCHED from (a
 * mirror, or a `file://` fixture in the offline suite). The url recorded in the
 * lock is always the canonical downloads.wordpress.org one, because a
 * wp-org-release's identity is its canonical url plus its `archive_sha256` —
 * the digest is what is verified, never the host that served it.
 */
final class WpOrgReleases {
    public const CANONICAL_BASE = 'https://downloads.wordpress.org';
    public const FETCH_BASE_ENV = 'DUO_CODE_ARTIFACT_BASE';

    /** A cached archive no longer hashes to the digest recorded beside it. */
    public const REASON_CACHE_CORRUPT = 'code_resolve_cache_corrupt';

    /** A downloaded archive does not hash to the digest the caller declared. */
    public const REASON_ARCHIVE_DIGEST_MISMATCH = 'code_resolve_archive_digest_mismatch';

    /** `--offline` was requested and the host cache has no entry for the url. */
    public const REASON_OFFLINE_MISS = 'code_resolve_offline_miss';

    /** wp.org serves plugins and themes from two fixed path segments. */
    private const RELEASE_PATH = ['plugins' => 'plugin', 'themes' => 'theme'];

    private string $fetchBase;

    public function __construct(
        private string $cacheDir,
        private bool $offline = false,
        ?string $fetchBase = null
    ) {
        $env = getenv(self::FETCH_BASE_ENV);
        $this->fetchBase = rtrim($fetchBase ?? (is_string($env) && $env !== '' ? $env : self::CANONICAL_BASE), '/');
    }

    /** The content-addressed cache root this instance reads and writes. */
    public function cacheDir(): string {
        return $this->cacheDir;
    }

    public function isOffline(): bool {
        return $this->offline;
    }

    public static function defaultCacheDir(): string {
        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && $xdg !== '' && str_starts_with($xdg, '/')) {
            return rtrim($xdg, '/') . '/duo/code-artifacts';
        }
        $home = getenv('HOME');
        if (!is_string($home) || $home === '' || !str_starts_with($home, '/')) {
            throw new \RuntimeException(
                'duo: no host cache directory is available (neither XDG_CACHE_HOME nor HOME is an absolute path); pass --cache-dir=<path>'
            );
        }
        return rtrim($home, '/') . '/.cache/duo/code-artifacts';
    }

    public static function canonicalUrl(string $root, string $slug, string $version): string {
        $segment = self::RELEASE_PATH[$root] ?? null;
        if ($segment === null || !PathSafety::safe_component($slug) || !self::safeVersion($version)) {
            throw new \RuntimeException("duo: no wp.org release identity exists for '$root/$slug' at version '$version'");
        }
        return self::CANONICAL_BASE . "/$segment/$slug.$version.zip";
    }

    /**
     * A version that can appear in a URL path segment at all. Anything else is
     * not "unfetchable", it is not a release identity: the component states a
     * version string that no archive name could encode.
     */
    public static function safeVersion(string $version): bool {
        return $version !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $version) === 1;
    }

    /** The content-addressed cache entry for one canonical url. */
    public function cachePath(string $canonicalUrl): string {
        return rtrim($this->cacheDir, '/') . '/' . hash('sha256', $canonicalUrl) . '.zip';
    }

    /**
     * Cache-first fetch. Returns `{path, sha256, source}` where `source` is
     * `cache` or `network`.
     *
     * @return array{path:string,sha256:string,source:string}
     */
    public function fetch(string $canonicalUrl, ?string $expectedSha256 = null): array {
        $path = $this->cachePath($canonicalUrl);
        $sidecar = $path . '.sha256';
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("duo: could not create the code artifact cache at $directory");
        }
        // One writer per entry across processes: several environments can be
        // classified concurrently and they share this cache.
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("duo: could not lock the code artifact cache entry for $canonicalUrl");
        }
        try {
            flock($lock, LOCK_EX);
            if (is_file($path)) {
                $digest = hash_file('sha256', $path);
                $recorded = is_file($sidecar) ? trim((string) @file_get_contents($sidecar)) : '';
                $expected = $expectedSha256 ?? ($recorded !== '' ? $recorded : null);
                if (!is_string($digest) || ($expected !== null && !hash_equals($expected, $digest))) {
                    // Refuse, do NOT re-fetch. A cached byte that no longer
                    // matches the digest recorded beside it is evidence of a
                    // corrupted or tampered cache, and quietly replacing it
                    // would destroy the only copy of that evidence.
                    throw new CommandRefusalException(
                        self::REASON_CACHE_CORRUPT,
                        'a cached release archive no longer hashes to the digest recorded beside it',
                        'inspect the named cache entry, remove it by hand, then retry; nothing re-fetches over '
                        . 'evidence of a corrupted or tampered cache',
                        [],
                        "duo: the cached archive for $canonicalUrl no longer matches its recorded digest; "
                        . "inspect and remove $path by hand before retrying"
                    );
                }
                return ['path' => $path, 'sha256' => $digest, 'source' => 'cache'];
            }
            if ($this->offline) {
                throw new CommandRefusalException(
                    self::REASON_OFFLINE_MISS,
                    'offline mode refuses every network fetch and the host cache holds no entry for this release',
                    'prime the host cache from a machine that can reach the release registry, or rerun without '
                    . '--offline; there is no latest-fallback and nothing is guessed',
                    [],
                    "duo: offline mode refuses to fetch $canonicalUrl and the host cache has no entry for it"
                );
            }
            $temporary = $path . '.part.' . bin2hex(random_bytes(6));
            try {
                $this->download($canonicalUrl, $temporary);
                $digest = hash_file('sha256', $temporary);
                if (!is_string($digest)) {
                    throw new \RuntimeException("duo: could not digest the downloaded archive for $canonicalUrl");
                }
                if ($expectedSha256 !== null && !hash_equals($expectedSha256, $digest)) {
                    throw new CommandRefusalException(
                        self::REASON_ARCHIVE_DIGEST_MISMATCH,
                        'a downloaded release archive does not hash to the digest the lock declares',
                        'the partial download was discarded and nothing was cached; re-lock the component with '
                        . 'duo code-classify if the upstream archive legitimately changed',
                        [],
                        "duo: $canonicalUrl downloaded as $digest, but the lock declares $expectedSha256; "
                        . 'the partial download was removed and nothing was cached'
                    );
                }
                if (!@rename($temporary, $path)) {
                    throw new \RuntimeException("duo: could not publish the fetched archive into $path");
                }
            } finally {
                // Only ever the partial file this call created.
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            @file_put_contents($sidecar, $digest . "\n");
            return ['path' => $path, 'sha256' => $digest, 'source' => 'network'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The fetch url for one canonical url, after the mirror override. */
    public function fetchUrl(string $canonicalUrl): string {
        return $this->fetchBase === self::CANONICAL_BASE
            ? $canonicalUrl
            : $this->fetchBase . substr($canonicalUrl, strlen(self::CANONICAL_BASE));
    }

    private function download(string $canonicalUrl, string $destination): void {
        $url = $this->fetchUrl($canonicalUrl);
        $curl = self::curlBinary();
        if ($curl !== null) {
            // --fail so a 404 body is never mistaken for an archive; no
            // --location beyond the bounded redirect count wp.org itself uses.
            $command = escapeshellarg($curl) . ' --fail --silent --show-error --location --max-redirs 3'
                . ' --max-time 120 --output ' . escapeshellarg($destination) . ' ' . escapeshellarg($url) . ' 2>&1';
            exec($command, $output, $status);
            if ($status !== 0) {
                throw new \RuntimeException(
                    "duo: could not fetch $canonicalUrl (curl exit $status: " . trim(implode(' ', $output)) . ')'
                );
            }
            return;
        }
        $stream = @fopen($url, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("duo: could not fetch $canonicalUrl (no curl binary and the stream could not be opened)");
        }
        try {
            $written = @file_put_contents($destination, $stream);
            if ($written === false) {
                throw new \RuntimeException("duo: could not write the fetched archive for $canonicalUrl");
            }
        } finally {
            fclose($stream);
        }
    }

    private static function curlBinary(): ?string {
        foreach (['/usr/bin/curl', '/bin/curl', '/usr/local/bin/curl', '/opt/homebrew/bin/curl'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Extract one archive into a fresh destination directory.
     *
     * Every entry name is checked BEFORE extraction: an absolute or traversing
     * name is refused outright rather than extracted and then cleaned up.
     */
    public function unpack(string $archivePath, string $destination): void {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'duo: PHP ZipArchive is not available on this host, so no release archive can be verified; '
                . 'install the php-zip extension'
            );
        }
        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new \RuntimeException("duo: could not open the release archive $archivePath");
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $entry = rtrim($name, '/');
                if ($entry === '' || !PathSafety::safe_relative($entry)) {
                    throw new \RuntimeException("duo: the release archive $archivePath contains an unsafe entry '$name'");
                }
            }
            if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new \RuntimeException("duo: could not create the unpack directory $destination");
            }
            if (!$zip->extractTo($destination)) {
                throw new \RuntimeException("duo: could not extract the release archive $archivePath");
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The directory inside an unpacked archive that holds the component.
     *
     * wp.org ships `<slug>/…`; a re-packaged archive may use a single
     * differently-named top-level directory. Anything else — several top-level
     * directories, or files at the archive root — is not a component tree, and
     * saying so is better than picking one and hoping.
     */
    public static function archiveRoot(string $unpacked, string $slug): string {
        if (is_dir($unpacked . '/' . $slug)) {
            return $slug;
        }
        $children = array_values(array_diff((array) @scandir($unpacked), ['.', '..']));
        if (count($children) === 1 && is_dir($unpacked . '/' . $children[0])
            && PathSafety::safe_component((string) $children[0])) {
            return (string) $children[0];
        }
        throw new \RuntimeException(
            "duo: the release archive does not contain a single '$slug' component directory"
        );
    }

    /**
     * `tree_sha256` for one unpacked component directory, through the SAME
     * rows the agent's descriptor compiler builds — sorted `{path, sha256}`
     * relative to the component root, canonically encoded
     * (agent/src/Code/CodeDescriptorCompiler.php:376,418). Symlinks and
     * non-regular entries are refused here exactly as they are there, so a
     * digest computed on the host can never accept a tree the target would.
     */
    public static function treeDigest(string $directory): string {
        $directory = rtrim($directory, '/');
        $rows = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
            if ($item->isLink()) {
                throw new \RuntimeException("duo: the unpacked release contains a symbolic link '$relative'");
            }
            if ($item->isDir()) {
                continue;
            }
            if (!$item->isFile() || !PathSafety::safe_relative($relative)) {
                throw new \RuntimeException("duo: the unpacked release contains an unusable entry '$relative'");
            }
            $digest = hash_file('sha256', $item->getPathname());
            if (!is_string($digest)) {
                throw new \RuntimeException("duo: could not digest the unpacked release entry '$relative'");
            }
            $rows[] = ['path' => $relative, 'sha256' => $digest];
        }
        usort($rows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        return CodeSourceLock::tree_sha256($rows);
    }

    /**
     * The wp.org leg of a component's classification: the `wp-org-release`
     * origin when the published release for its declared version unpacks to
     * exactly the installed tree, otherwise the stated reason it does not.
     *
     * A release is offered only when its bytes were fetched (or found in the
     * host cache), unpacked and hashed, because a wp.org version can be
     * re-packaged and a ZIP digest is not a tree digest — which is exactly why
     * the lock carries both `archive_sha256` (what to fetch) and `tree_sha256`
     * (what the unpacked component must hash to). Offline, fetch() answers
     * from the cache or refuses, and that refusal becomes the reason here: no
     * registry is contacted, and nothing is guessed.
     *
     * @return array{reason:string,origin?:array{kind:string,url:string,archive_sha256:string,archive_root?:string}}
     */
    public function verifiedRelease(string $root, string $slug, string $version, string $tree): array {
        if (!isset(self::RELEASE_PATH[$root])) {
            return ['reason' => "$root components have no wp.org release identity"];
        }
        if (!self::safeVersion($version)) {
            return ['reason' => $version === ''
                ? 'the component declares no Version header, so no wp.org release can be pinned'
                : "the declared version '$version' cannot name a wp.org release archive"];
        }
        $canonical = self::canonicalUrl($root, $slug, $version);
        $unpacked = null;
        try {
            $archive = $this->fetch($canonical);
            $unpacked = self::temporaryDirectory();
            $this->unpack($archive['path'], $unpacked);
            $archiveRoot = self::archiveRoot($unpacked, $slug);
            $digest = self::treeDigest($unpacked . '/' . $archiveRoot);
            if (!hash_equals($tree, $digest)) {
                return ['reason' => "the wp.org release $slug $version unpacks to $digest, not the installed tree $tree"];
            }
            $origin = [
                'kind' => 'wp-org-release',
                'url' => $canonical,
                'archive_sha256' => $archive['sha256'],
            ];
            if ($archiveRoot !== $slug) {
                $origin['archive_root'] = $archiveRoot;
            }
            return [
                'reason' => "the wp.org release $slug $version unpacks to exactly these bytes",
                'origin' => $origin,
            ];
        } catch (\Throwable $error) {
            return ['reason' => 'no verified wp.org release: ' . self::oneLine($error->getMessage())];
        } finally {
            if ($unpacked !== null) {
                self::removeTree($unpacked);
            }
        }
    }

    /** A reason is one line: it is rendered in a proposal and refused otherwise. */
    private static function oneLine(string $message): string {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    /** A fresh unpack directory under the temp root; removeTree() removes only these. */
    public static function temporaryDirectory(): string {
        $path = rtrim(sys_get_temp_dir(), '/') . '/duo-code-unpack-' . bin2hex(random_bytes(8));
        if (!@mkdir($path, 0700, true)) {
            throw new \RuntimeException("duo: could not create the unpack directory $path");
        }
        return $path;
    }

    /** Removes only a directory temporaryDirectory() created under the temp root. */
    public static function removeTree(string $path): void {
        if (!str_contains($path, '/duo-code-unpack-') || !is_dir($path)) {
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
}
