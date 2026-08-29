<?php
/**
 * Offline characterization for `wprism code-resolve` (issue #3500).
 *
 * issue #3499 shipped a lock that a repository can DECLARE and a compile gate
 * that refuses when the declared bytes are absent; it deliberately shipped no
 * resolver, so putting the bytes back was an operator build step. This suite
 * pins the resolver that closes that loop, and the properties it pins are the
 * ones that separate "a fetcher" from something a deployment may run
 * unattended:
 *
 *   A. a cache MISS fetches, verifies both digests, and materializes;
 *   B. a cache HIT is re-verified and re-used — with the registry deleted, so
 *      "did not re-fetch" is proven rather than asserted — and a component
 *      already at its locked digest is reported unchanged and NOT rewritten;
 *   C. a corrupted cache entry refuses instead of silently re-fetching, and
 *      the corrupted bytes are still there afterwards as evidence;
 *   D. a download that disagrees with the lock deletes only its partial file
 *      and caches nothing;
 *   E. `--offline` refuses every fetch on a miss and resolves happily from a
 *      warm cache, so the flag forbids the network and nothing else;
 *   F. a release that unpacks to the wrong tree refuses AFTER the archive
 *      digest matched — the two digests are not redundant;
 *   G. `imported-archive` origins get the identical verification from the
 *      host's imported store, with no network — and a store that does not
 *      hold the archive refuses by name, because WPrism never fetches from a
 *      vendor and the repository deliberately carries no copy;
 *   H. a component present at any OTHER digest refuses rather than
 *      overwriting bytes Git does not carry;
 *   I. `--dry-run` reports and writes nothing — not even a cache entry;
 *   J. after resolving, the 3499 compile gate passes and `code_revision` is
 *      byte-identical to the same tree compiled with no lock at all;
 *   K. the transport arms: local and docker materialize on the host; a driver
 *      that exposes NEITHER a host-side repository NOR a push capability
 *      refuses with `code_resolve_transport_unsupported`, naming issue #3514,
 *      unless the target already hashes correctly; and a repository with no
 *      lock produces NO phase output whatsoever;
 *   L. deploy/promote compilation resolves only inside a disposable,
 *      target-visible repository snapshot, so both success and a later
 *      compile failure leave the canonical repository unresolved and remove
 *      every staged byte.
 *
 * K3 and K4 changed MEANING, not bytes, when issue #3514 landed the host→target
 * push. `ResolveFixtureTransport` is not a `CodePushTransport`, so it is
 * exactly the un-pushable driver those refusals are still correct for; the ssh
 * transport that CAN be pushed to is pinned end to end in
 * `regress_code_resolve_push.php`, which owns the ordered push sequence, the
 * target-side digest gate and the drift refusal.
 *
 * The registry is a local `file://` fixture and every archive is built in this
 * process: the offline corpus contacts no network. `WPRISM_CODE_ARTIFACT_BASE`
 * moves only WHERE bytes are fetched from; the url in the lock stays the
 * canonical wp.org one, because a release's identity is its canonical url plus
 * its archive digest, never the host that served it.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/PathSafety.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeDescriptorCompiler.php';
require_once __DIR__ . '/../../../../cli/src/Code/CodeResolver.php';
require_once __DIR__ . '/../../../../cli/src/Code/ImportedArchives.php';
require_once __DIR__ . '/../../../../cli/src/Command/CodeResolveCommand.php';
require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';

use WPrism\CodeCompilationException;
use WPrism\CodeDescriptorCompiler;
use WPrism\CodeSourceLock;
use WPrism\CommandRefusalException;
use WPrism\Orchestrator\CodeResolveCommand;
use WPrism\Orchestrator\CodeResolver;
use WPrism\Orchestrator\ImportedArchives;
use WPrism\Orchestrator\WpOrgReleases;

const RESOLVE_FORMAT_1 = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
const RESOLVE_FORMAT_2 = [
    'format' => 2,
    'layout' => 'wp-content',
    'lock' => 'code/wprism-code.lock.json',
    'source' => 'code/wp-content',
];

$scratch = sys_get_temp_dir() . '/wprism_regress_code_resolve_' . bin2hex(random_bytes(6));
$registry = $scratch . '/registry';
mkdir($registry . '/plugin', 0775, true);
mkdir($registry . '/theme', 0775, true);
$repoSeq = 0;
$cacheSeq = 0;

// ---------------------------------------------------------------------------
// Fixture helpers.
// ---------------------------------------------------------------------------

/** @param array<string,string> $files */
function resolve_write_tree(string $root, array $files): void {
    foreach ($files as $relative => $body) {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $body);
    }
}

/** @param array<string,string> $files */
function resolve_write_archive(string $archivePath, string $componentRoot, array $files): void {
    if (!is_dir(dirname($archivePath))) {
        mkdir(dirname($archivePath), 0775, true);
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("could not create the fixture archive $archivePath");
    }
    $zip->addEmptyDir($componentRoot);
    foreach ($files as $relative => $body) {
        $zip->addFromString($componentRoot . '/' . $relative, $body);
    }
    $zip->close();
}

function resolve_remove_tree(string $path): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

/** The digest one file map hashes to once it is a component directory on disk. */
function resolve_tree_digest(string $scratch, array $files): string {
    $probe = $scratch . '/probe-' . bin2hex(random_bytes(6));
    resolve_write_tree($probe, $files);
    $digest = WpOrgReleases::treeDigest($probe);
    resolve_remove_tree($probe);
    return $digest;
}

/**
 * A split repository: `site.wprism.json` at code format 2, the root-anchored
 * ignore lines, and only the components named in `$present` materialized.
 * Every present component the lock does not declare is declared first-party
 * in the same lock: that is the only shape a carried component may take.
 *
 * @param list<array<string,mixed>> $lockRows
 * @param array<string,array<string,string>> $present `{root}/{component}` => file map
 */
function resolve_make_repo(string $scratch, int &$seq, array $lockRows, array $present): string {
    $repo = $scratch . '/repo' . (++$seq);
    mkdir($repo . '/code/wp-content/plugins', 0775, true);
    mkdir($repo . '/code/wp-content/themes', 0775, true);
    file_put_contents(
        $repo . '/site.wprism.json',
        \WPrism\Canon::encode(['code' => RESOLVE_FORMAT_2, 'format' => 1, 'site' => 'fixture'])
    );
    $locked = [];
    foreach ($lockRows as $row) {
        $locked[$row['root'] . '/' . $row['component']] = true;
    }
    $firstParty = array_values(array_filter(array_keys($present), static fn(string $key): bool => !isset($locked[$key])));
    file_put_contents($repo . '/' . CodeSourceLock::PATH, CodeSourceLock::encode($lockRows, $firstParty));
    $ignore = "/.wprism/\n";
    foreach (CodeSourceLock::sort_components($lockRows) as $row) {
        $ignore .= CodeSourceLock::gitignore_line((string) $row['root'], (string) $row['component']) . "\n";
    }
    file_put_contents($repo . '/.gitignore', $ignore);
    foreach ($present as $key => $files) {
        resolve_write_tree($repo . '/' . CodeSourceLock::SOURCE . '/' . $key, $files);
    }
    return $repo;
}

function resolve_cache(string $scratch, int &$seq): string {
    $cache = $scratch . '/cache' . (++$seq);
    mkdir($cache, 0775, true);
    return $cache;
}

function resolve_resolver(string $cache, string $registry, bool $offline = false): CodeResolver {
    return new CodeResolver(new WpOrgReleases($cache, $offline, 'file://' . $registry));
}

/**
 * Assert one refusal reason code, and that the refusal is publishable.
 *
 * `detailsRedacted` is checked on every single refusal because
 * CommandRefusalException silently replaces the whole public contract when a
 * reviewed field names a home directory or a credential-shaped token
 * (agent/src/Kernel/CommandRefusal.php:38-49) — a redacted refusal still
 * carries the right reason code, so nothing but this assertion would notice
 * that the operator lost the remedy sentence.
 */
function resolve_check_refuses(callable $fn, string $reasonCode, string $message): ?CommandRefusalException {
    try {
        $fn();
    } catch (CommandRefusalException $refusal) {
        if ($refusal->reasonCode !== $reasonCode) {
            wprism_check(false, $message);
            wprism_check_detail('expected ' . $reasonCode . ', got ' . $refusal->reasonCode);
            wprism_check_detail('message:  ' . $refusal->getMessage());
            return $refusal;
        }
        wprism_check(true, $message);
        wprism_check(
            !$refusal->detailsRedacted,
            "the $reasonCode refusal keeps its reviewed public message and remedy (nothing was redacted)"
        );
        return $refusal;
    } catch (Throwable $other) {
        wprism_check(false, $message);
        wprism_check_detail('expected a CommandRefusalException, got ' . get_class($other) . ': ' . $other->getMessage());
        return null;
    }
    wprism_check(false, $message);
    wprism_check_detail('expected refusal ' . $reasonCode . ', nothing was thrown');
    return null;
}

// ---------------------------------------------------------------------------
// The library this suite resolves against.
// ---------------------------------------------------------------------------

if (!class_exists(ZipArchive::class)) {
    // Not a skip. Without ZipArchive the host cannot verify a release at all,
    // and the one claim this suite can still pin is that it says so loudly and
    // names the remedy rather than materializing something it never verified.
    wprism_check_throws(
        static fn() => (new WpOrgReleases($scratch . '/cache'))->unpack($registry . '/absent.zip', $scratch . '/out'),
        RuntimeException::class,
        'without ZipArchive the host refuses loudly and names the remedy instead of guessing',
        'install the php-zip extension'
    );
    resolve_remove_tree($scratch);
    wprism_check_summary('regress_code_resolve');
}

$wooFiles = [
    'woocommerce.php' => "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n",
    'includes/class-wc.php' => "<?php\n// wc\n",
];
$themeFiles = ['style.css' => "/*\nTheme Name: Storefront\nVersion: 4.6.0\n*/\n"];
$premiumFiles = [
    'premium.php' => "<?php\n/**\n * Plugin Name: Premium\n * Version: 1.2.0\n */\n",
];
$agencyFiles = ['wprism-agency.php' => "<?php\n/**\n * Plugin Name: WPrism Agency\n * Version: 1.0.0\n */\n"];

resolve_write_archive($registry . '/plugin/woocommerce.11.0.0.zip', 'woocommerce', $wooFiles);
resolve_write_archive($registry . '/theme/storefront.4.6.0.zip', 'storefront', $themeFiles);

$wooUrl = WpOrgReleases::canonicalUrl('plugins', 'woocommerce', '11.0.0');
$themeUrl = WpOrgReleases::canonicalUrl('themes', 'storefront', '4.6.0');
$wooArchiveDigest = (string) hash_file('sha256', $registry . '/plugin/woocommerce.11.0.0.zip');
$themeArchiveDigest = (string) hash_file('sha256', $registry . '/theme/storefront.4.6.0.zip');
$wooTreeDigest = resolve_tree_digest($scratch, $wooFiles);
$themeTreeDigest = resolve_tree_digest($scratch, $themeFiles);

$lockRows = [
    [
        'root' => 'plugins',
        'component' => 'woocommerce',
        'version' => '11.0.0',
        'origin' => ['kind' => 'wp-org-release', 'url' => $wooUrl, 'archive_sha256' => $wooArchiveDigest],
        'tree_sha256' => $wooTreeDigest,
    ],
    [
        'root' => 'themes',
        'component' => 'storefront',
        'version' => '4.6.0',
        'origin' => ['kind' => 'wp-org-release', 'url' => $themeUrl, 'archive_sha256' => $themeArchiveDigest],
        'tree_sha256' => $themeTreeDigest,
    ],
];
// `wprism-agency` is the first-party control: it is in Git by declaration, is NOT
// a locked component, and must be untouched by every resolution below.
$vendored = ['plugins/wprism-agency' => $agencyFiles];

// ---------------------------------------------------------------------------
// A. Cache miss: fetch, verify both digests, materialize atomically.
// ---------------------------------------------------------------------------

$repo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$cache = resolve_cache($scratch, $cacheSeq);
$lock = CodeResolver::declaredLock($repo);
wprism_check_same(CodeSourceLock::PATH, $lock['path'], 'the declared lock path is read from site.wprism.json, never assumed');
wprism_check_same(2, count($lock['components']), 'both declared components are read from the lock');

$rows = resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false);
wprism_check_same(
    ['resolved', 'resolved'],
    array_column($rows, 'state'),
    'a cache miss fetches, verifies and materializes every declared component'
);
wprism_check_same(
    ['plugins/woocommerce', 'themes/storefront'],
    array_map(static fn(array $r): string => $r['root'] . '/' . $r['component'], $rows),
    'the report is in lock order, which is already sorted by (root, component)'
);
wprism_check_same(
    $wooTreeDigest,
    WpOrgReleases::treeDigest($repo . '/code/wp-content/plugins/woocommerce'),
    'the materialized plugin hashes to exactly the tree digest the lock declares'
);
wprism_check_same(
    $themeTreeDigest,
    WpOrgReleases::treeDigest($repo . '/code/wp-content/themes/storefront'),
    'and so does the materialized theme'
);
wprism_check(
    is_file($repo . '/code/wp-content/plugins/woocommerce/includes/class-wc.php'),
    'a nested archive entry lands at its nested path, not flattened'
);
wprism_check_same(
    $agencyFiles['wprism-agency.php'],
    (string) file_get_contents($repo . '/code/wp-content/plugins/wprism-agency/wprism-agency.php'),
    'the first-party component is not touched'
);
wprism_check(
    !is_dir($repo . '/' . CodeResolver::STAGING) || scandir($repo . '/' . CodeResolver::STAGING) === ['.', '..'],
    'no staging directory survives a successful resolve: a leftover under plugins/ would be inventoried as a component'
);
wprism_check(
    is_file((new WpOrgReleases($cache, false, 'file://' . $registry))->cachePath($wooUrl)),
    'the verified archive is published into the content-addressed host cache'
);

// ---------------------------------------------------------------------------
// B. Cache hit: re-verified, re-used, and never a rewrite.
// ---------------------------------------------------------------------------

// Delete the registry copy of the plugin archive. Everything from here to
// section D that still resolves woocommerce did so from the cache, provably.
$wooArchiveBytes = (string) file_get_contents($registry . '/plugin/woocommerce.11.0.0.zip');
unlink($registry . '/plugin/woocommerce.11.0.0.zip');

$inode = (array) stat($repo . '/code/wp-content/plugins/woocommerce/woocommerce.php');
$rows = resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false);
wprism_check_same(
    ['unchanged', 'unchanged'],
    array_column($rows, 'state'),
    'a component already present at its locked digest is reported unchanged'
);
wprism_check_same(
    $inode['ino'],
    ((array) stat($repo . '/code/wp-content/plugins/woocommerce/woocommerce.php'))['ino'],
    'and is not rewritten: the file on disk is the same inode, not a fresh copy of identical bytes'
);

resolve_remove_tree($repo . '/code/wp-content/plugins/woocommerce');
$rows = resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false);
wprism_check_same(
    ['resolved', 'unchanged'],
    array_column($rows, 'state'),
    'only the absent component is resolved; the present one is left alone'
);
wprism_check(
    str_contains($rows[0]['detail'], 'cache'),
    'and it came from the cache — the registry no longer holds that archive at all'
);
wprism_check_same(
    $wooTreeDigest,
    WpOrgReleases::treeDigest($repo . '/code/wp-content/plugins/woocommerce'),
    'a cache hit is re-verified before it is unpacked, not trusted because it is cached'
);

// ---------------------------------------------------------------------------
// C. A corrupted cache entry refuses, and the evidence survives.
// ---------------------------------------------------------------------------

resolve_remove_tree($repo . '/code/wp-content/plugins/woocommerce');
$cachedPath = (new WpOrgReleases($cache, false, 'file://' . $registry))->cachePath($wooUrl);
file_put_contents($cachedPath, 'corrupted');
resolve_check_refuses(
    static fn() => resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false),
    WpOrgReleases::REASON_CACHE_CORRUPT,
    'a cached archive that no longer matches its digest refuses instead of re-fetching'
);
wprism_check_same(
    'corrupted',
    (string) file_get_contents($cachedPath),
    'and the corrupted bytes are still there: they are evidence of a tampered cache, not a transient to overwrite'
);
wprism_check(
    !is_dir($repo . '/code/wp-content/plugins/woocommerce'),
    'nothing was materialized for the refused component'
);
wprism_check(
    is_dir($repo . '/code/wp-content/themes/storefront'),
    'and the component resolved before the refusal is left exactly as it was'
);
unlink($cachedPath);
unlink($cachedPath . '.sha256');

// ---------------------------------------------------------------------------
// D. A download that disagrees with the lock caches nothing.
// ---------------------------------------------------------------------------

// The registry now serves a DIFFERENT archive under the same canonical url —
// the shape of an upstream re-release under a reused version number.
resolve_write_archive(
    $registry . '/plugin/woocommerce.11.0.0.zip',
    'woocommerce',
    $wooFiles + ['extra.php' => "<?php\n// re-released upstream\n"]
);
$mismatchCache = resolve_cache($scratch, $cacheSeq);
resolve_check_refuses(
    static fn() => resolve_resolver($mismatchCache, $registry)->resolve($repo, $lock['components'], false),
    WpOrgReleases::REASON_ARCHIVE_DIGEST_MISMATCH,
    'a download whose digest disagrees with the lock refuses before anything is unpacked'
);
wprism_check(
    !is_file((new WpOrgReleases($mismatchCache, false, 'file://' . $registry))->cachePath($wooUrl)),
    'and nothing was published into the cache'
);
wprism_check_same(
    [],
    array_values(array_filter(
        (array) scandir($mismatchCache),
        static fn(string $entry): bool => str_contains($entry, '.part.')
    )),
    'only the partial file this attempt created was removed, and it left no residue'
);
wprism_check(
    !is_dir($repo . '/code/wp-content/plugins/woocommerce'),
    'a refused download materializes nothing'
);
// Restore the honest registry copy for the sections below.
file_put_contents($registry . '/plugin/woocommerce.11.0.0.zip', $wooArchiveBytes);

// ---------------------------------------------------------------------------
// E. --offline forbids the network and nothing else.
// ---------------------------------------------------------------------------

$offlineCache = resolve_cache($scratch, $cacheSeq);
resolve_check_refuses(
    static fn() => resolve_resolver($offlineCache, $registry, true)->resolve($repo, $lock['components'], false),
    WpOrgReleases::REASON_OFFLINE_MISS,
    '--offline refuses every fetch on a cache miss rather than falling back to anything'
);
wprism_check(
    !is_dir($repo . '/code/wp-content/plugins/woocommerce'),
    'and materializes nothing'
);
// Section C deleted this cache entry on purpose (proving the corrupt-cache
// refusal leaves its evidence in place), so warm it again before asking what
// --offline does with a cache that HAS the archive.
resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false);
resolve_remove_tree($repo . '/code/wp-content/plugins/woocommerce');
$rows = resolve_resolver($cache, $registry, true)->resolve($repo, $lock['components'], false);
wprism_check_same(
    ['resolved', 'unchanged'],
    array_column($rows, 'state'),
    '--offline resolves happily from a warm cache: the flag forbids the network, not resolution'
);

// ---------------------------------------------------------------------------
// F. Two digests, two separate questions.
// ---------------------------------------------------------------------------

// A lock whose archive_sha256 is right and whose tree_sha256 is not: exactly
// what a re-packaged release recorded under a stale tree digest looks like.
$staleRows = $lockRows;
$staleRows[0]['tree_sha256'] = hash('sha256', 'not the installed tree');
$staleRepo = resolve_make_repo($scratch, $repoSeq, $staleRows, $vendored);
$staleLock = CodeResolver::declaredLock($staleRepo);
resolve_check_refuses(
    static fn() => resolve_resolver($cache, $registry)->resolve($staleRepo, $staleLock['components'], false),
    CodeResolver::REASON_TREE_DIGEST_MISMATCH,
    'an archive whose digest matched still refuses when the unpacked tree is not the declared tree'
);
wprism_check(
    !is_dir($staleRepo . '/code/wp-content/plugins/woocommerce'),
    'the verification happens in staging, so a tree-digest refusal never half-writes the component'
);
wprism_check(
    !is_dir($staleRepo . '/' . CodeResolver::STAGING)
        || scandir($staleRepo . '/' . CodeResolver::STAGING) === ['.', '..'],
    'and the staging directory is removed even on the refusing path'
);

// ---------------------------------------------------------------------------
// G. imported-archive: same verification from the host's imported store, no
//    network, and a store that does not hold the archive refuses by name.
// ---------------------------------------------------------------------------

$premiumTreeDigest = resolve_tree_digest($scratch, $premiumFiles);
$vendorZip = $scratch . '/vendor-downloads/premium-1.2.0.zip';
resolve_write_archive($vendorZip, 'premium', $premiumFiles);
$importCache = resolve_cache($scratch, $cacheSeq);
// The operator imports the vendor's archive on this host; the lock will
// record only the digest the import printed — no url, no path.
$import = ImportedArchives::forReleases(new WpOrgReleases($importCache, true))->import($vendorZip, null, 'plugins');
wprism_check_same($premiumTreeDigest, $import['tree_sha256'], 'the import computes the same tree digest the lock will demand');
$importedRows = [[
    'root' => 'plugins',
    'component' => 'premium',
    'version' => '1.2.0',
    'origin' => ['kind' => 'imported-archive', 'archive_sha256' => $import['archive_sha256']],
    'tree_sha256' => $premiumTreeDigest,
]];
$importedRepo = resolve_make_repo($scratch, $repoSeq, $importedRows, $vendored);
$importedLock = CodeResolver::declaredLock($importedRepo);

// A registry base that resolves nothing: an imported archive must never reach
// it, and the cache it resolves from is the imported store alone.
$rows = resolve_resolver($importCache, $scratch . '/no-registry')
    ->resolve($importedRepo, $importedLock['components'], false);
wprism_check_same(['resolved'], array_column($rows, 'state'), 'an imported-archive origin resolves from the host\'s imported store');
wprism_check(str_contains($rows[0]['detail'], 'imported archive'), 'and says where it came from');
wprism_check_same(
    $premiumTreeDigest,
    WpOrgReleases::treeDigest($importedRepo . '/code/wp-content/plugins/premium'),
    'and is verified against the same tree digest a wp.org release would be'
);
wprism_check(
    !is_dir($scratch . '/no-registry'),
    'the registry is never reached for an imported archive'
);
wprism_check(
    !is_file($importedRepo . '/code/archives/premium-1.2.0.zip') && !is_dir($importedRepo . '/code/archives'),
    'and the repository carries no copy of the archive: the bytes live in the host store only'
);

resolve_remove_tree($importedRepo . '/code/wp-content/plugins/premium');
file_put_contents($import['path'], 'not the imported archive');
resolve_check_refuses(
    static fn() => resolve_resolver($importCache, $scratch . '/no-registry')
        ->resolve($importedRepo, $importedLock['components'], false),
    WpOrgReleases::REASON_CACHE_CORRUPT,
    'an imported archive that no longer hashes to its digest refuses as a corrupted cache entry, never silently re-imported over'
);
wprism_check_same('not the imported archive', (string) file_get_contents($import['path']), 'and the corrupted bytes survive as evidence');
unlink($import['path']);
$missing = resolve_check_refuses(
    static fn() => resolve_resolver($importCache, $scratch . '/no-registry')
        ->resolve($importedRepo, $importedLock['components'], false),
    CodeResolver::REASON_ARCHIVE_MISSING,
    'an imported archive this host\'s store does not hold is named, not silently skipped'
);
wprism_check(
    $missing !== null && str_contains($missing->remediation, 'wprism code-import <archive.zip>')
        && str_contains($missing->remediation, 'WPrism never fetches from a vendor'),
    'and the remedy is the one thing that can be done about it: import the vendor archive on this host'
);
$freshCache = resolve_cache($scratch, $cacheSeq);
resolve_check_refuses(
    static fn() => resolve_resolver($freshCache, $registry)
        ->resolve($importedRepo, $importedLock['components'], false),
    CodeResolver::REASON_ARCHIVE_MISSING,
    'a host that never imported the archive refuses the same way, whatever its registry holds: a clone resolves premium code only where the operator imported it'
);
$rows = resolve_resolver($importCache, $scratch . '/no-registry')->resolve($importedRepo, $importedLock['components'], true);
wprism_check(
    str_contains($rows[0]['detail'], 'imported archive ' . $import['archive_sha256']),
    '--dry-run names the imported archive it would unpack, by digest'
);

// ---------------------------------------------------------------------------
// G2. A declared archive_root wins over detection.
// ---------------------------------------------------------------------------

// The re-packaged shape the classifier records archive_root for: the archive
// carries BOTH a directory named after the slug and the one that actually
// holds the component, so detection alone would resolve the wrong tree. The
// archive sits in the store under its digest, as an import elsewhere left it.
$repackedFiles = ['premium.php' => "<?php\n/**\n * Plugin Name: Premium\n * Version: 1.2.0\n */\n"];
$decoyFiles = ['readme.txt' => "not the component\n"];
$repackedZip = $scratch . '/vendor-downloads/premium-repacked.zip';
$zip = new ZipArchive();
$zip->open($repackedZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('premium/' . array_key_first($decoyFiles), $decoyFiles['readme.txt']);
$zip->addFromString('premium-pro-1.2.0/premium.php', $repackedFiles['premium.php']);
$zip->close();
$repackedDigest = (string) hash_file('sha256', $repackedZip);
$repackedStore = ImportedArchives::forReleases(new WpOrgReleases($importCache, true));
if (!is_dir($repackedStore->directory())) {
    mkdir($repackedStore->directory(), 0775, true);
}
copy($repackedZip, $repackedStore->archivePathFor($repackedDigest));
$repackedRows = [[
    'root' => 'plugins',
    'component' => 'premium',
    'version' => '1.2.0',
    'origin' => [
        'kind' => 'imported-archive',
        'archive_root' => 'premium-pro-1.2.0',
        'archive_sha256' => $repackedDigest,
    ],
    'tree_sha256' => resolve_tree_digest($scratch, $repackedFiles),
]];
$repackedRepo = resolve_make_repo($scratch, $repoSeq, $repackedRows, $vendored);
$rows = resolve_resolver($importCache, $scratch . '/no-registry')
    ->resolve($repackedRepo, CodeResolver::declaredLock($repackedRepo)['components'], false);
wprism_check_same(['resolved'], array_column($rows, 'state'), 'a declared archive_root resolves the directory the lock names');
wprism_check(
    is_file($repackedRepo . '/code/wp-content/plugins/premium/premium.php')
        && !is_file($repackedRepo . '/code/wp-content/plugins/premium/readme.txt'),
    'and not the same-named decoy directory detection alone would have picked'
);
$repackedRows[0]['origin']['archive_root'] = 'no-such-directory';
file_put_contents($repackedRepo . '/' . CodeSourceLock::PATH, CodeSourceLock::encode($repackedRows, ['plugins/wprism-agency']));
resolve_remove_tree($repackedRepo . '/code/wp-content/plugins/premium');
resolve_check_refuses(
    static fn() => resolve_resolver($importCache, $scratch . '/no-registry')
        ->resolve($repackedRepo, CodeResolver::declaredLock($repackedRepo)['components'], false),
    CodeResolver::REASON_UNPACK_FAILED,
    'an archive_root the archive does not contain refuses instead of falling back to detection'
);

// ---------------------------------------------------------------------------
// H. A drifted component is a refusal, never an overwrite.
// ---------------------------------------------------------------------------

$driftRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
resolve_resolver($cache, $registry)->resolve($driftRepo, CodeResolver::declaredLock($driftRepo)['components'], false);
file_put_contents($driftRepo . '/code/wp-content/plugins/woocommerce/hotfix.php', "<?php\n// a local patch\n");
$refusal = resolve_check_refuses(
    static fn() => resolve_resolver($cache, $registry)
        ->resolve($driftRepo, CodeResolver::declaredLock($driftRepo)['components'], false),
    CodeResolver::REASON_DRIFTED,
    'a locked component present at any OTHER digest refuses rather than re-materializing over it'
);
wprism_check(
    is_file($driftRepo . '/code/wp-content/plugins/woocommerce/hotfix.php'),
    'and the local bytes survive: the tree is gitignored, so overwriting it would destroy the only copy'
);
wprism_check(
    $refusal !== null && str_contains($refusal->remediation, 'wprism code-classify'),
    'the remedy names both ways out — remove and re-resolve, or re-lock the bytes you actually have'
);


// ---------------------------------------------------------------------------
// I. --dry-run reports and writes nothing.
// ---------------------------------------------------------------------------

$dryRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$dryCache = $scratch . '/dry-cache';
$rows = resolve_resolver($dryCache, $registry)->resolve($dryRepo, CodeResolver::declaredLock($dryRepo)['components'], true);
wprism_check_same(
    ['would-resolve', 'would-resolve'],
    array_column($rows, 'state'),
    '--dry-run reports what it would resolve'
);
wprism_check(
    !is_dir($dryRepo . '/code/wp-content/plugins/woocommerce')
        && !is_dir($dryRepo . '/code/wp-content/themes/storefront'),
    'and writes nothing into code/wp-content'
);
wprism_check(!is_dir($dryCache), 'and fetches nothing: a dry run does not even warm the cache');
wprism_check(
    str_contains($rows[0]['detail'], $wooUrl),
    'the dry-run report names the exact release it would fetch, so the operator can review it'
);
// The control: the identical call without --dry-run does resolve, so the
// assertions above are about the flag and not about a broken fixture.
$rows = resolve_resolver($cache, $registry)->resolve($dryRepo, CodeResolver::declaredLock($dryRepo)['components'], false);
wprism_check_same(
    ['resolved', 'resolved'],
    array_column($rows, 'state'),
    'and the same repository resolves normally when the flag is dropped'
);

// ---------------------------------------------------------------------------
// J. After resolving, the 3499 compile gate passes and nothing moved.
// ---------------------------------------------------------------------------

$resolved = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
resolve_resolver($cache, $registry)->resolve($resolved, CodeResolver::declaredLock($resolved)['components'], false);

// The same tree assembled by hand and compiled with NO lock at all: the
// revision a fully vendored repository would produce.
$reference = $scratch . '/reference';
mkdir($reference . '/code/wp-content/plugins', 0775, true);
resolve_write_tree($reference . '/code/wp-content/plugins/woocommerce', $wooFiles);
resolve_write_tree($reference . '/code/wp-content/plugins/wprism-agency', $agencyFiles);
resolve_write_tree($reference . '/code/wp-content/themes/storefront', $themeFiles);
$referenceDescriptor = CodeDescriptorCompiler::compile($reference, RESOLVE_FORMAT_1);

$resolvedDescriptor = CodeDescriptorCompiler::compile($resolved, RESOLVE_FORMAT_2);
wprism_check(is_array($resolvedDescriptor), 'the compile gate accepts a resolved split repository');
wprism_check_same(
    $referenceDescriptor['code_revision'],
    $resolvedDescriptor['code_revision'],
    'and its code_revision equals the vendored tree: resolution restores bytes, it does not rewrite them'
);
wprism_check_same(
    \WPrism\Canon::encode($referenceDescriptor),
    \WPrism\Canon::encode($resolvedDescriptor),
    'the whole descriptor matches, so artifact_hash and every ownership root match too'
);

$unresolved = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
wprism_check_throws(
    static fn() => CodeDescriptorCompiler::compile($unresolved, RESOLVE_FORMAT_2),
    CodeCompilationException::class,
    'the same repository before resolution is exactly what the issue #3499 gate refuses'
);

// ---------------------------------------------------------------------------
// K. The transport arms.
//
// These run in a child process because the refusal renderer writes to STDERR,
// which ob_start() cannot capture: the STDERR constant is bound at startup and
// there is no in-process way to redirect fd 2. The `regress-command-output`
// suite takes the same proc_open([PHP_BINARY, ...]) seam, for the same reason,
// around CommandOutput::renderTransportDetail().
// ---------------------------------------------------------------------------

$root = dirname(__DIR__, 4);
$fixtureFile = $scratch . '/resolve_fixture.php';
$runnerFile = $scratch . '/resolve_runner.php';
file_put_contents($fixtureFile, str_replace(
    '__WPRISM_ROOT__',
    var_export($root, true),
    <<<'PHP_FIXTURE'
<?php
declare(strict_types=1);
require_once __WPRISM_ROOT__ . '/cli/src/Transport/Transport.php';
require_once __WPRISM_ROOT__ . '/cli/src/Command/CodeResolveCommand.php';

/**
 * A transport whose driver id, repo path and scripted target answers the suite
 * controls. Transport::__construct() takes the driver id straight from the
 * registry's `transport` key (cli/src/Transport/Transport.php:58-59), which is
 * exactly the closed vocabulary the resolver dispatches on.
 */
final class ResolveFixtureTransport extends \WPrism\Orchestrator\Transport {
    /** @var list<string> */
    public array $raw = [];
    /** @var list<array<int,string>> */
    public array $wp = [];

    public function __construct(
        string $transport,
        string $repoPath,
        private array $rawAnswers = [],
        private ?array $inventory = null,
        private ?string $hostRepo = null
    ) {
        parent::__construct($transport . '-fixture', ['repo_path' => $repoPath, 'transport' => $transport]);
    }

    /**
     * What the REAL driver of this transport would answer (issue #3526).
     *
     * LocalTransport returns its repo_path; DockerTransport derives the host
     * side of its bind mount from `docker compose config`; SshTransport, and
     * any docker service whose repo_path is a named volume or a read-only
     * mount, answer null. A fixture states that answer directly instead of
     * running docker, which is what makes these cases offline.
     */
    public function hostRepoPath(): ?string {
        if ($this->hostRepo !== null) {
            return $this->hostRepo;
        }
        // The default each real driver already gives: LocalTransport returns
        // its repo_path (a host path by definition), everything else null. A
        // docker fixture states its bind source explicitly, because deriving
        // it for real would need docker and these cases are offline.
        return $this->driverId() === 'local' ? rtrim($this->repoPath(), '/') : null;
    }

    public function describe(): string { return 'code resolve fixture'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    public function captureRaw(string $script): array {
        $this->raw[] = $script;
        foreach ($this->rawAnswers as $needle => $answer) {
            if (str_contains($script, (string) $needle)) {
                return $answer;
            }
        }
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'no such file'];
    }

    public function captureWp(array $wpArgs): array {
        $this->wp[] = $wpArgs;
        return $this->inventory ?? ['exit' => 1, 'stdout' => '', 'stderr' => 'no inventory'];
    }
}
PHP_FIXTURE
));
file_put_contents($runnerFile, str_replace(
    '__WPRISM_FIXTURE__',
    var_export($fixtureFile, true),
    <<<'PHP_RUNNER'
<?php
declare(strict_types=1);
require_once __WPRISM_FIXTURE__;

$spec = json_decode((string) $argv[1], true);
$driver = new ResolveFixtureTransport(
    (string) $spec['transport'],
    (string) $spec['repo_path'],
    (array) ($spec['raw'] ?? []),
    $spec['inventory'] ?? null,
    isset($spec['host_repo']) ? (string) $spec['host_repo'] : null
);
$phase = null;
if (($spec['mode'] ?? 'verb') === 'verb') {
    $exit = \WPrism\Orchestrator\CodeResolveCommand::run($driver, (array) ($spec['extra'] ?? []));
} else {
    $phase = \WPrism\Orchestrator\CodeResolveCommand::deployPhase($driver, (string) ($spec['verb'] ?? 'deploy'));
    $exit = $phase ?? 0;
}
// Written to a side channel, never to stdout: one assertion below is that an
// unlocked repository's phase prints EXACTLY nothing.
file_put_contents((string) $argv[2], json_encode([
    'phase' => $phase === null ? 'continue' : $phase,
    'raw' => count($driver->raw),
    'wp' => count($driver->wp),
]));
exit((int) $exit);
PHP_RUNNER
));

/**
 * Run one command boundary in a child process.
 *
 * The child's environment always pins `WPRISM_CODE_ARTIFACT_BASE` at the local
 * `file://` fixture. The command boundary constructs its own WpOrgReleases and
 * therefore takes the canonical downloads.wordpress.org base unless that
 * variable moves it — so without this line a cold cache in one of the cases
 * below would reach the real network, which the offline corpus must never do.
 *
 * @param array<string,mixed> $spec
 * @param array<string,string> $env
 * @return array{exit:int,stdout:string,stderr:string,phase:string|int,raw:int,wp:int}
 */
function resolve_run(string $runnerFile, string $scratch, array $spec, ?string $cwd = null, array $env = []): array {
    global $registry;
    $markers = $scratch . '/markers-' . bin2hex(random_bytes(6)) . '.json';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, $runnerFile, json_encode($spec, JSON_UNESCAPED_SLASHES), $markers],
        $descriptors,
        $pipes,
        $cwd,
        array_merge((array) getenv(), ['WPRISM_CODE_ARTIFACT_BASE' => 'file://' . $registry], $env)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start the code-resolve runner');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $side = is_file($markers) ? (array) json_decode((string) file_get_contents($markers), true) : [];
    @unlink($markers);
    return [
        'exit' => $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'phase' => $side['phase'] ?? 'absent',
        'raw' => (int) ($side['raw'] ?? -1),
        'wp' => (int) ($side['wp'] ?? -1),
    ];
}

// K1. local: repo_path IS the host path, so the verb materializes into it.
$localRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'local',
    'repo_path' => $localRepo,
    'extra' => ['--cache-dir=' . $cache],
]);
wprism_check_same(0, $result['exit'], 'wprism code-resolve succeeds on a local environment');
wprism_check(
    is_dir($localRepo . '/code/wp-content/plugins/woocommerce'),
    'and materializes into the repo_path the local environment names'
);
wprism_check(
    str_contains($result['stdout'], 'RESOLVED plugins/woocommerce 11.0.0'),
    'the report names each component and its locked version'
);
wprism_check_same(0, $result['raw'] + $result['wp'], 'and reaches the target zero times: resolution is host work');

// K2. docker: the host side is THIS environment's bind mount, derived from
// the compose service — not the checkout the CLI happens to stand in
// (issue #3526). `repo_path` stays a container path and is never treated as one
// on the host.
$dockerRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$dockerElsewhere = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'docker',
    'repo_path' => '/var/www/site-repo',
    'host_repo' => $dockerRepo,
    'extra' => ['--cache-dir=' . $cache],
], $dockerElsewhere . '/code');
wprism_check_same(0, $result['exit'], 'wprism code-resolve succeeds on a docker environment');
wprism_check(
    is_dir($dockerRepo . '/code/wp-content/plugins/woocommerce'),
    'and materializes into the host side of the environment\'s OWN bind mount'
);
wprism_check(
    !is_dir($dockerElsewhere . '/code/wp-content/plugins/woocommerce'),
    'THE HAZARD, pinned: the working directory is inside a DIFFERENT site repository and that one is left '
    . 'untouched — before issue #3526 cwd chose the repository, so a rehearse resolved the source and reported '
    . 'success while the target the compile reads stayed empty'
);
wprism_check(
    !is_dir('/var/www/site-repo'),
    'never treating the container-side repo_path as a host path'
);
// A service whose repo_path is a named volume (or a read-only bind, or an
// unreadable compose file) answers null, and the host says so rather than
// guessing at a directory.
$namedVolume = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'docker',
    'repo_path' => '/siterepo',
    'extra' => ['--cache-dir=' . $cache],
], $dockerElsewhere . '/code');
wprism_check_same(1, $namedVolume['exit'], 'a docker environment with no writable bind at its repo_path refuses, with the same exit code every host-side resolve refusal uses');
wprism_check(
    str_contains($namedVolume['stdout'] . $namedVolume['stderr'], 'does not bind its repo_path to a writable host directory'),
    'and the refusal names the condition rather than the old "run from inside the checkout" remedy, which this '
    . 'change made inert'
);

// K3. A driver with no host repository AND no push capability: the verb
// refuses and names the issue #3514 runbook. Since issue #3514 that is a claim about
// the CAPABILITY, not about the word "ssh" — a real SshTransport implements
// CodePushTransport and resolves-then-pushes (regress_code_resolve_push.php).
// The bytes below are unchanged precisely because this fixture does not.
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'ssh',
    'repo_path' => '/srv/site-repo',
]);
wprism_check_same(1, $result['exit'], 'wprism code-resolve refuses on a driver that can neither write nor push');
wprism_check(
    str_contains($result['stderr'], '[' . CodeResolveCommand::REASON_TRANSPORT_UNSUPPORTED . ']'),
    'with the reason code the docs and the deploy phase both name'
);
wprism_check(
    str_contains($result['stderr'], 'issue #3514'),
    'and it names the tracked host-to-target push work rather than inventing a workaround'
);
wprism_check(
    str_contains($result['stderr'], 'docs/guides/code-updates.md'),
    'and points at the runbook for materializing on the target by hand'
);
wprism_check_same(0, $result['raw'] + $result['wp'], 'the verb refuses before contacting the target at all');

// K4. The deploy phase on an un-pushable driver: proceeds only when the target
// already hashes correctly, and refuses by name when it does not. (A pushable
// one resolves and pushes instead; regress_code_resolve_push.php pins that.)
$targetSite = json_encode(['code' => RESOLVE_FORMAT_2, 'format' => 1], JSON_UNESCAPED_SLASHES);
$targetLock = CodeSourceLock::encode($lockRows);
$targetRaw = [
    'site.wprism.json' => ['exit' => 0, 'stdout' => $targetSite, 'stderr' => ''],
    CodeSourceLock::PATH => ['exit' => 0, 'stdout' => $targetLock, 'stderr' => ''],
];
$inventoryRow = static fn(string $root, string $component, string $version, string $digest): array => [
    'root' => $root, 'component' => $component, 'version' => $version, 'tree_sha256' => $digest,
];

$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'transport' => 'ssh',
    'repo_path' => '/srv/site-repo',
    'raw' => $targetRaw,
    'inventory' => ['exit' => 0, 'stdout' => json_encode([
        'format' => 'wprism-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [
            $inventoryRow('plugins', 'woocommerce', '11.0.0', $wooTreeDigest),
            $inventoryRow('themes', 'storefront', '4.6.0', $themeTreeDigest),
        ],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
]);
wprism_check_same('continue', $result['phase'], 'deploy proceeds when every locked component already hashes correctly on the target');
wprism_check(
    str_contains($result['stdout'], "deploy phase: code-resolve\n"),
    'and says so with its own phase line'
);
wprism_check(
    str_contains($result['stdout'], 'UNCHANGED plugins/woocommerce'),
    'reporting each locked component as already present on the target'
);

$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'transport' => 'ssh',
    'repo_path' => '/srv/site-repo',
    'raw' => $targetRaw,
    'inventory' => ['exit' => 0, 'stdout' => json_encode([
        'format' => 'wprism-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [$inventoryRow('plugins', 'woocommerce', '11.0.0', $wooTreeDigest)],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
]);
wprism_check_same(1, $result['exit'], 'deploy refuses on an un-pushable driver when a locked component is not already correct on the target');
wprism_check(
    str_contains($result['stderr'], '[' . CodeResolveCommand::REASON_TRANSPORT_UNSUPPORTED . ']'),
    'with the same reason code the verb raises'
);
wprism_check(
    str_contains($result['stderr'], 'themes/storefront'),
    'naming the component that is missing rather than the whole repository'
);
wprism_check(
    str_contains($result['stderr'], 'refusing before compile'),
    'and stating that it stopped before compile, so no lease and no checkpoint exist to compensate'
);

// K4b. The deploy phase on DOCKER (issue #3526): the host materializes into the
// derived bind source, and then PROVES through the target that the bytes
// landed where the target reads. That proof is what makes a derived path safe
// to act on — a stale compose file or an edited mount would otherwise resolve
// somewhere harmless and leave the compile one phase later to fail with
// `code_source_missing` and no explanation.
$dockerPhaseRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, []);
$dockerPhase = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'env materialize',
    'transport' => 'docker',
    'repo_path' => '/siterepo',
    'host_repo' => $dockerPhaseRepo,
    'inventory' => ['exit' => 0, 'stdout' => json_encode([
        'format' => 'wprism-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [
            $inventoryRow('plugins', 'woocommerce', '11.0.0', $wooTreeDigest),
            $inventoryRow('themes', 'storefront', '4.6.0', $themeTreeDigest),
        ],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
// deployPhase() passes no --cache-dir (an unattended phase has no flags), so
// the child needs a writable XDG cache of its own rather than the developer's.
], null, ['XDG_CACHE_HOME' => $scratch . '/xdg']);
wprism_check_same('continue', $dockerPhase['phase'], 'the docker phase proceeds once the host has resolved and the target confirms the digests');
wprism_check(
    is_dir($dockerPhaseRepo . '/code/wp-content/plugins/woocommerce')
        && is_dir($dockerPhaseRepo . '/code/wp-content/themes/storefront'),
    'and the components are on disk in the host side of the target\'s bind mount, before anything is compiled'
);
wprism_check(
    str_contains($dockerPhase['stdout'], "env materialize phase: code-resolve\n")
        && str_contains($dockerPhase['stdout'], 'env materialize: 2 materialized, 0 unchanged.'),
    'reported with the verb it ran under, in the vocabulary wprism code-resolve already prints'
);

// The same resolve, but the target does not report the digests: the host wrote
// somewhere the target does not read, and that must refuse BEFORE the compile
// rather than surface as an unexplained compile failure.
$dockerStrayRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, []);
$dockerStray = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'env materialize',
    'transport' => 'docker',
    'repo_path' => '/siterepo',
    'host_repo' => $dockerStrayRepo,
    'inventory' => ['exit' => 0, 'stdout' => json_encode([
        'format' => 'wprism-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
], null, ['XDG_CACHE_HOME' => $scratch . '/xdg']);
wprism_check_same(1, $dockerStray['phase'], 'a derived path the target does not read refuses the phase');
wprism_check(
    str_contains($dockerStray['stdout'] . $dockerStray['stderr'], 'the target does not report them at the locked digest')
        && str_contains($dockerStray['stdout'] . $dockerStray['stderr'], 'plugins/woocommerce 11.0.0 (absent)'),
    'naming every component the target still lacks, with a typed reason code, before any compile happens'
);


// K5. A repository with no lock produces no phase output at all. This is the
// rule-8 assertion: every format-1 deploy prints exactly the bytes it always
// did, because the phase is silent unless it has something to say.
$plainRepo = $scratch . '/plain-repo';
mkdir($plainRepo . '/code/wp-content/plugins', 0775, true);
file_put_contents(
    $plainRepo . '/site.wprism.json',
    \WPrism\Canon::encode(['code' => RESOLVE_FORMAT_1, 'format' => 1, 'site' => 'fixture'])
);
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'transport' => 'local',
    'repo_path' => $plainRepo,
]);
wprism_check_same('continue', $result['phase'], 'a format-1 repository has nothing to resolve');
wprism_check_same('', $result['stdout'], 'and the phase prints nothing at all, so existing deploy output is byte-identical');
wprism_check_same('', $result['stderr'], 'on either stream');
wprism_check_same(0, $result['raw'] + $result['wp'], 'and it costs no target round trip');

// K6. A driver outside the closed transport vocabulary is left alone: it owns
// no site repository this host can reach, and the compile gate below still
// refuses anything unresolved.
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'transport' => 'fixture-only',
    'repo_path' => '/fixture/repo',
]);
wprism_check_same('continue', $result['phase'], 'a driver that is not one of the three shipped transports is left to the compile gate');
wprism_check_same('', $result['stdout'] . $result['stderr'], 'and prints nothing');
wprism_check_same(0, $result['raw'] + $result['wp'], 'and contacts nothing');

// K7. The automatic phase resolves through the same code path as the verb, and
// uses the default XDG cache: an unattended deploy has no --cache-dir to pass.
$phaseRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$result = resolve_run(
    $runnerFile,
    $scratch,
    ['mode' => 'phase', 'verb' => 'promote', 'transport' => 'local', 'repo_path' => $phaseRepo],
    null,
    ['XDG_CACHE_HOME' => $scratch . '/xdg']
);
wprism_check_same('continue', $result['phase'], 'the promote phase resolves and lets promotion continue');
wprism_check(str_contains($result['stdout'], "promote phase: code-resolve\n"), 'and labels itself with the verb it is running under');
wprism_check(
    is_dir($phaseRepo . '/code/wp-content/plugins/woocommerce'),
    'materializing into the host checkout before anything is compiled'
);
wprism_check(
    is_dir($scratch . '/xdg/wprism/code-artifacts'),
    'through the default XDG cache, because an unattended deploy passes no --cache-dir'
);

// K8. The verb's flag surface is closed.
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'local',
    'repo_path' => $localRepo,
    'extra' => ['--force'],
]);
wprism_check_same(1, $result['exit'], 'an unsupported flag refuses');
wprism_check(
    str_contains($result['stderr'], "unsupported argument '--force'"),
    'naming the argument rather than printing usage'
);
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'local',
    'repo_path' => $localRepo,
    'extra' => ['--cache-dir=relative/path'],
]);
wprism_check_same(1, $result['exit'], 'a relative --cache-dir refuses: the cache is shared across checkouts');

// ---------------------------------------------------------------------------
// A malformed declaration is missing information, not absent information.
// ---------------------------------------------------------------------------

$brokenRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
unlink($brokenRepo . '/' . CodeSourceLock::PATH);
resolve_check_refuses(
    static fn() => CodeResolver::declaredLock($brokenRepo),
    CodeResolver::REASON_LOCK_UNREADABLE,
    'code format 2 with no lock file on disk refuses rather than resolving nothing'
);
file_put_contents($brokenRepo . '/' . CodeSourceLock::PATH, '{"format":"wprism-code-lock/v0","components":[]}');
resolve_check_refuses(
    static fn() => CodeResolver::declaredLock($brokenRepo),
    CodeResolver::REASON_LOCK_UNREADABLE,
    'and a lock the grammar rejects is refused too, not partially honoured'
);
file_put_contents($brokenRepo . '/' . CodeSourceLock::PATH, json_encode([
    'format' => 'wprism-code-lock/v1',
    'components' => [[
        'root' => 'plugins', 'component' => 'premium', 'version' => '1.2.0',
        'origin' => ['kind' => 'vendored-archive', 'path' => 'code/archives/premium.zip', 'archive_sha256' => str_repeat('0', 64)],
        'tree_sha256' => str_repeat('1', 64),
    ]],
]));
$retired = resolve_check_refuses(
    static fn() => CodeResolver::declaredLock($brokenRepo),
    CodeResolver::REASON_LOCK_UNREADABLE,
    'a legacy lock naming the retired vendored-archive origin is refused at the reader, so nothing ever resolves a ZIP committed inside the repository again'
);
wprism_check(
    $retired !== null && str_contains($retired->getMessage(), '`wprism code-import <archive.zip>`'),
    'and the refusal carries the remedy: import the archive on the host and re-lock'
);

// ---------------------------------------------------------------------------
// L. The automatic release path is transactional with respect to repository
// materialization. The explicit verb cases above intentionally leave bytes in
// the repository; deploy/promote must not.
// ---------------------------------------------------------------------------

final class ReleaseCompileFixtureTransport extends \WPrism\Orchestrator\Transport {
    public function __construct(string $repo) {
        parent::__construct('release-compile-fixture', [
            'repo_path' => $repo,
            'transport' => 'local',
        ]);
    }

    public function hostRepoPath(): ?string {
        return rtrim($this->repoPath(), '/');
    }

    public function describe(): string { return 'release compile fixture'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return $script; }

    public function captureRaw(string $script): array {
        return self::shell($script);
    }

    public function captureWp(array $wpArgs): array {
        $repoArg = array_values(array_filter(
            $wpArgs,
            static fn(string $arg): bool => str_starts_with($arg, '--repo=')
        ));
        if (!in_array('code-inventory', $wpArgs, true) || count($repoArg) !== 1) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'unexpected wp fixture call'];
        }
        $repo = substr($repoArg[0], strlen('--repo='));
        $lock = CodeSourceLock::parse((string) file_get_contents($repo . '/' . CodeSourceLock::PATH));
        $rows = [];
        foreach ($lock['components'] as $entry) {
            $path = $repo . '/' . CodeSourceLock::SOURCE . '/' . $entry['root'] . '/' . $entry['component'];
            if (!is_dir($path)) {
                continue;
            }
            $rows[] = [
                'root' => $entry['root'],
                'component' => $entry['component'],
                'version' => $entry['version'],
                'tree_sha256' => WpOrgReleases::treeDigest($path),
            ];
        }
        return [
            'exit' => 0,
            'stdout' => json_encode([
                'format' => 'wprism-code-inventory/v1',
                'source' => CodeSourceLock::SOURCE,
                'components' => $rows,
            ], JSON_UNESCAPED_SLASHES),
            'stderr' => '',
        ];
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function shell(string $script): array {
        $process = proc_open(['/bin/sh', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'could not start shell'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}

$releaseRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, []);
$releaseDriver = new ReleaseCompileFixtureTransport($releaseRepo);
$priorBase = getenv('WPRISM_CODE_ARTIFACT_BASE');
$priorXdg = getenv('XDG_CACHE_HOME');
putenv('WPRISM_CODE_ARTIFACT_BASE=file://' . $registry);
putenv('XDG_CACHE_HOME=' . $scratch . '/release-xdg');
try {
    $preparedPath = CodeResolveCommand::releaseCompile(
        $releaseDriver,
        'deploy',
        static function (string $prepared) use ($releaseRepo): string {
            wprism_check(
                str_contains($prepared, '/' . CodeResolveCommand::RELEASE_STAGING . '/'),
                'release compilation receives a target-visible repository with a minted staging identity'
            );
            wprism_check(
                is_dir($prepared . '/code/wp-content/plugins/woocommerce')
                    && is_dir($prepared . '/code/wp-content/themes/storefront'),
                'the disposable repository carries every locked component at compile time'
            );
            wprism_check(
                !is_dir($releaseRepo . '/code/wp-content/plugins/woocommerce')
                    && !is_dir($releaseRepo . '/code/wp-content/themes/storefront'),
                'THE boundary: automatic resolution has not materialized the canonical repository before compile'
            );
            return $prepared;
        }
    );
    wprism_check(is_string($preparedPath), 'the product compile callback result is returned unchanged');
    wprism_check(!is_dir((string) $preparedPath), 'the isolated repository is removed after successful compile');
    wprism_check(
        !is_dir($releaseRepo . '/code/wp-content/plugins/woocommerce')
            && !is_dir($releaseRepo . '/code/wp-content/themes/storefront'),
        'successful preparation leaves the canonical repository byte-identical too'
    );

    $failed = CodeResolveCommand::releaseCompile(
        $releaseDriver,
        'promote',
        static function (string $prepared): never {
            wprism_check(is_dir($prepared), 'the refusal fixture reaches the real prepared repository');
            throw new RuntimeException('synthetic compile refusal after resolution');
        }
    );
    wprism_check_same(1, $failed, 'a compile exception is a closed release preparation refusal');
    wprism_check_same(
        [],
        array_values((array) glob($releaseRepo . '/' . CodeResolveCommand::RELEASE_STAGING . '/*')),
        'the finally removes every target-side prepared repository after the refusal'
    );
    wprism_check(
        !is_dir($releaseRepo . '/code/wp-content/plugins/woocommerce')
            && !is_dir($releaseRepo . '/code/wp-content/themes/storefront'),
        'and a failed release leaves no resolved dependency bytes for an operator to clean up'
    );
} finally {
    $priorBase === false ? putenv('WPRISM_CODE_ARTIFACT_BASE') : putenv('WPRISM_CODE_ARTIFACT_BASE=' . $priorBase);
    $priorXdg === false ? putenv('XDG_CACHE_HOME') : putenv('XDG_CACHE_HOME=' . $priorXdg);
}

resolve_remove_tree($scratch);

wprism_check_summary('regress_code_resolve');
