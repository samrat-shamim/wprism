<?php
/**
 * Offline characterization for `duo code-resolve` (DUO-3500).
 *
 * DUO-3499 shipped a lock that a repository can DECLARE and a compile gate
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
 *   G. `vendored-archive` origins get the identical verification with no cache
 *      and no network;
 *   H. a component present at any OTHER digest refuses rather than
 *      overwriting bytes Git does not carry;
 *   I. `--dry-run` reports and writes nothing — not even a cache entry;
 *   J. after resolving, the 3499 compile gate passes and `code_revision` is
 *      byte-identical to the same tree compiled with no lock at all;
 *   K. the transport arms: local and docker materialize on the host, ssh
 *      refuses with `code_resolve_transport_unsupported` naming DUO-3514
 *      unless the target already hashes correctly, and a repository with no
 *      lock produces NO phase output whatsoever.
 *
 * The registry is a local `file://` fixture and every archive is built in this
 * process: the offline corpus contacts no network. `DUO_CODE_ARTIFACT_BASE`
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
require_once __DIR__ . '/../../../../cli/src/Command/CodeResolveCommand.php';

use Duo\CodeCompilationException;
use Duo\CodeDescriptorCompiler;
use Duo\CodeSourceLock;
use Duo\CommandRefusalException;
use Duo\Orchestrator\CodeResolveCommand;
use Duo\Orchestrator\CodeResolver;
use Duo\Orchestrator\WpOrgReleases;

const RESOLVE_FORMAT_1 = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
const RESOLVE_FORMAT_2 = [
    'format' => 2,
    'layout' => 'wp-content',
    'lock' => 'code/duo-code.lock.json',
    'source' => 'code/wp-content',
];

$scratch = sys_get_temp_dir() . '/duo_regress_code_resolve_' . bin2hex(random_bytes(6));
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
 * A split repository: `site.duo.json` at code format 2, the root-anchored
 * ignore lines, and only the components named in `$present` materialized.
 *
 * @param list<array<string,mixed>> $lockRows
 * @param array<string,array<string,string>> $present `{root}/{component}` => file map
 */
function resolve_make_repo(string $scratch, int &$seq, array $lockRows, array $present): string {
    $repo = $scratch . '/repo' . (++$seq);
    mkdir($repo . '/code/wp-content/plugins', 0775, true);
    mkdir($repo . '/code/wp-content/themes', 0775, true);
    file_put_contents(
        $repo . '/site.duo.json',
        \Duo\Canon::encode(['code' => RESOLVE_FORMAT_2, 'format' => 1, 'site' => 'fixture'])
    );
    file_put_contents($repo . '/' . CodeSourceLock::PATH, CodeSourceLock::encode($lockRows));
    $ignore = "/.duo/\n";
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
            duo_check(false, $message);
            duo_check_detail('expected ' . $reasonCode . ', got ' . $refusal->reasonCode);
            duo_check_detail('message:  ' . $refusal->getMessage());
            return $refusal;
        }
        duo_check(true, $message);
        duo_check(
            !$refusal->detailsRedacted,
            "the $reasonCode refusal keeps its reviewed public message and remedy (nothing was redacted)"
        );
        return $refusal;
    } catch (Throwable $other) {
        duo_check(false, $message);
        duo_check_detail('expected a CommandRefusalException, got ' . get_class($other) . ': ' . $other->getMessage());
        return null;
    }
    duo_check(false, $message);
    duo_check_detail('expected refusal ' . $reasonCode . ', nothing was thrown');
    return null;
}

// ---------------------------------------------------------------------------
// The library this suite resolves against.
// ---------------------------------------------------------------------------

if (!class_exists(ZipArchive::class)) {
    // Not a skip. Without ZipArchive the host cannot verify a release at all,
    // and the one claim this suite can still pin is that it says so loudly and
    // names the remedy rather than materializing something it never verified.
    duo_check_throws(
        static fn() => (new WpOrgReleases($scratch . '/cache'))->unpack($registry . '/absent.zip', $scratch . '/out'),
        RuntimeException::class,
        'without ZipArchive the host refuses loudly and names the remedy instead of guessing',
        'install the php-zip extension'
    );
    resolve_remove_tree($scratch);
    duo_check_summary('regress_code_resolve');
}

$wooFiles = [
    'woocommerce.php' => "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n",
    'includes/class-wc.php' => "<?php\n// wc\n",
];
$themeFiles = ['style.css' => "/*\nTheme Name: Storefront\nVersion: 4.6.0\n*/\n"];
$premiumFiles = [
    'premium.php' => "<?php\n/**\n * Plugin Name: Premium\n * Version: 1.2.0\n */\n",
];
$agencyFiles = ['duo-agency.php' => "<?php\n/**\n * Plugin Name: Duo Agency\n * Version: 1.0.0\n */\n"];

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
// `duo-agency` is the vendored control: it is in Git, is NOT in the lock, and
// must be untouched by every resolution below.
$vendored = ['plugins/duo-agency' => $agencyFiles];

// ---------------------------------------------------------------------------
// A. Cache miss: fetch, verify both digests, materialize atomically.
// ---------------------------------------------------------------------------

$repo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$cache = resolve_cache($scratch, $cacheSeq);
$lock = CodeResolver::declaredLock($repo);
duo_check_same(CodeSourceLock::PATH, $lock['path'], 'the declared lock path is read from site.duo.json, never assumed');
duo_check_same(2, count($lock['components']), 'both declared components are read from the lock');

$rows = resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false);
duo_check_same(
    ['resolved', 'resolved'],
    array_column($rows, 'state'),
    'a cache miss fetches, verifies and materializes every declared component'
);
duo_check_same(
    ['plugins/woocommerce', 'themes/storefront'],
    array_map(static fn(array $r): string => $r['root'] . '/' . $r['component'], $rows),
    'the report is in lock order, which is already sorted by (root, component)'
);
duo_check_same(
    $wooTreeDigest,
    WpOrgReleases::treeDigest($repo . '/code/wp-content/plugins/woocommerce'),
    'the materialized plugin hashes to exactly the tree digest the lock declares'
);
duo_check_same(
    $themeTreeDigest,
    WpOrgReleases::treeDigest($repo . '/code/wp-content/themes/storefront'),
    'and so does the materialized theme'
);
duo_check(
    is_file($repo . '/code/wp-content/plugins/woocommerce/includes/class-wc.php'),
    'a nested archive entry lands at its nested path, not flattened'
);
duo_check_same(
    $agencyFiles['duo-agency.php'],
    (string) file_get_contents($repo . '/code/wp-content/plugins/duo-agency/duo-agency.php'),
    'the vendored component nobody declared is not touched'
);
duo_check(
    !is_dir($repo . '/' . CodeResolver::STAGING) || scandir($repo . '/' . CodeResolver::STAGING) === ['.', '..'],
    'no staging directory survives a successful resolve: a leftover under plugins/ would be inventoried as a component'
);
duo_check(
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
duo_check_same(
    ['unchanged', 'unchanged'],
    array_column($rows, 'state'),
    'a component already present at its locked digest is reported unchanged'
);
duo_check_same(
    $inode['ino'],
    ((array) stat($repo . '/code/wp-content/plugins/woocommerce/woocommerce.php'))['ino'],
    'and is not rewritten: the file on disk is the same inode, not a fresh copy of identical bytes'
);

resolve_remove_tree($repo . '/code/wp-content/plugins/woocommerce');
$rows = resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false);
duo_check_same(
    ['resolved', 'unchanged'],
    array_column($rows, 'state'),
    'only the absent component is resolved; the present one is left alone'
);
duo_check(
    str_contains($rows[0]['detail'], 'cache'),
    'and it came from the cache — the registry no longer holds that archive at all'
);
duo_check_same(
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
duo_check_same(
    'corrupted',
    (string) file_get_contents($cachedPath),
    'and the corrupted bytes are still there: they are evidence of a tampered cache, not a transient to overwrite'
);
duo_check(
    !is_dir($repo . '/code/wp-content/plugins/woocommerce'),
    'nothing was materialized for the refused component'
);
duo_check(
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
duo_check(
    !is_file((new WpOrgReleases($mismatchCache, false, 'file://' . $registry))->cachePath($wooUrl)),
    'and nothing was published into the cache'
);
duo_check_same(
    [],
    array_values(array_filter(
        (array) scandir($mismatchCache),
        static fn(string $entry): bool => str_contains($entry, '.part.')
    )),
    'only the partial file this attempt created was removed, and it left no residue'
);
duo_check(
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
duo_check(
    !is_dir($repo . '/code/wp-content/plugins/woocommerce'),
    'and materializes nothing'
);
// Section C deleted this cache entry on purpose (proving the corrupt-cache
// refusal leaves its evidence in place), so warm it again before asking what
// --offline does with a cache that HAS the archive.
resolve_resolver($cache, $registry)->resolve($repo, $lock['components'], false);
resolve_remove_tree($repo . '/code/wp-content/plugins/woocommerce');
$rows = resolve_resolver($cache, $registry, true)->resolve($repo, $lock['components'], false);
duo_check_same(
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
duo_check(
    !is_dir($staleRepo . '/code/wp-content/plugins/woocommerce'),
    'the verification happens in staging, so a tree-digest refusal never half-writes the component'
);
duo_check(
    !is_dir($staleRepo . '/' . CodeResolver::STAGING)
        || scandir($staleRepo . '/' . CodeResolver::STAGING) === ['.', '..'],
    'and the staging directory is removed even on the refusing path'
);

// ---------------------------------------------------------------------------
// G. vendored-archive: same verification, no cache and no network.
// ---------------------------------------------------------------------------

$premiumTreeDigest = resolve_tree_digest($scratch, $premiumFiles);
$premiumArchive = 'code/archives/premium.1.2.0.zip';
$vendoredRows = [[
    'root' => 'plugins',
    'component' => 'premium',
    'version' => '1.2.0',
    'origin' => [
        'kind' => 'vendored-archive',
        'path' => $premiumArchive,
        'archive_sha256' => str_repeat('0', 64),
    ],
    'tree_sha256' => $premiumTreeDigest,
]];
$vendoredRepo = resolve_make_repo($scratch, $repoSeq, $vendoredRows, $vendored);
resolve_write_archive($vendoredRepo . '/' . $premiumArchive, 'premium', $premiumFiles);
$vendoredRows[0]['origin']['archive_sha256'] = (string) hash_file('sha256', $vendoredRepo . '/' . $premiumArchive);
file_put_contents($vendoredRepo . '/' . CodeSourceLock::PATH, CodeSourceLock::encode($vendoredRows));
$vendoredLock = CodeResolver::declaredLock($vendoredRepo);

// An empty cache and a registry base that resolves nothing: a vendored archive
// must reach neither.
$rows = resolve_resolver($scratch . '/never-used-cache', $scratch . '/no-registry')
    ->resolve($vendoredRepo, $vendoredLock['components'], false);
duo_check_same(['resolved'], array_column($rows, 'state'), 'a vendored-archive origin unpacks from the repository itself');
duo_check_same(
    $premiumTreeDigest,
    WpOrgReleases::treeDigest($vendoredRepo . '/code/wp-content/plugins/premium'),
    'and is verified against the same tree digest a wp.org release would be'
);
duo_check(
    !is_dir($scratch . '/never-used-cache'),
    'the release cache is never even created for a vendored archive'
);

resolve_remove_tree($vendoredRepo . '/code/wp-content/plugins/premium');
file_put_contents($vendoredRepo . '/' . $premiumArchive, 'not the reviewed archive');
resolve_check_refuses(
    static fn() => resolve_resolver($scratch . '/never-used-cache', $scratch . '/no-registry')
        ->resolve($vendoredRepo, $vendoredLock['components'], false),
    WpOrgReleases::REASON_ARCHIVE_DIGEST_MISMATCH,
    'a vendored archive that no longer hashes to the declared digest refuses with the same reason a download does'
);
unlink($vendoredRepo . '/' . $premiumArchive);
resolve_check_refuses(
    static fn() => resolve_resolver($scratch . '/never-used-cache', $scratch . '/no-registry')
        ->resolve($vendoredRepo, $vendoredLock['components'], false),
    CodeResolver::REASON_ARCHIVE_MISSING,
    'a vendored-archive path the repository does not carry is named, not silently skipped'
);

// ---------------------------------------------------------------------------
// G2. A declared archive_root wins over detection.
// ---------------------------------------------------------------------------

// The re-packaged shape the classifier records archive_root for: the archive
// carries BOTH a directory named after the slug and the one that actually
// holds the component, so detection alone would resolve the wrong tree.
$repackedFiles = ['premium.php' => "<?php\n/**\n * Plugin Name: Premium\n * Version: 1.2.0\n */\n"];
$decoyFiles = ['readme.txt' => "not the component\n"];
$repackedArchive = 'code/archives/premium-repacked.zip';
$repackedRepo = resolve_make_repo($scratch, $repoSeq, $vendoredRows, $vendored);
$zip = new ZipArchive();
mkdir(dirname($repackedRepo . '/' . $repackedArchive), 0775, true);
$zip->open($repackedRepo . '/' . $repackedArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('premium/' . array_key_first($decoyFiles), $decoyFiles['readme.txt']);
$zip->addFromString('premium-pro-1.2.0/premium.php', $repackedFiles['premium.php']);
$zip->close();
$repackedRows = [[
    'root' => 'plugins',
    'component' => 'premium',
    'version' => '1.2.0',
    'origin' => [
        'kind' => 'vendored-archive',
        'path' => $repackedArchive,
        'archive_root' => 'premium-pro-1.2.0',
        'archive_sha256' => (string) hash_file('sha256', $repackedRepo . '/' . $repackedArchive),
    ],
    'tree_sha256' => resolve_tree_digest($scratch, $repackedFiles),
]];
file_put_contents($repackedRepo . '/' . CodeSourceLock::PATH, CodeSourceLock::encode($repackedRows));
$rows = resolve_resolver($scratch . '/never-used-cache', $scratch . '/no-registry')
    ->resolve($repackedRepo, CodeResolver::declaredLock($repackedRepo)['components'], false);
duo_check_same(['resolved'], array_column($rows, 'state'), 'a declared archive_root resolves the directory the lock names');
duo_check(
    is_file($repackedRepo . '/code/wp-content/plugins/premium/premium.php')
        && !is_file($repackedRepo . '/code/wp-content/plugins/premium/readme.txt'),
    'and not the same-named decoy directory detection alone would have picked'
);
$repackedRows[0]['origin']['archive_root'] = 'no-such-directory';
file_put_contents($repackedRepo . '/' . CodeSourceLock::PATH, CodeSourceLock::encode($repackedRows));
resolve_remove_tree($repackedRepo . '/code/wp-content/plugins/premium');
resolve_check_refuses(
    static fn() => resolve_resolver($scratch . '/never-used-cache', $scratch . '/no-registry')
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
duo_check(
    is_file($driftRepo . '/code/wp-content/plugins/woocommerce/hotfix.php'),
    'and the local bytes survive: the tree is gitignored, so overwriting it would destroy the only copy'
);
duo_check(
    $refusal !== null && str_contains($refusal->remediation, 'duo code-classify'),
    'the remedy names both ways out — remove and re-resolve, or re-lock the bytes you actually have'
);


// ---------------------------------------------------------------------------
// I. --dry-run reports and writes nothing.
// ---------------------------------------------------------------------------

$dryRepo = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
$dryCache = $scratch . '/dry-cache';
$rows = resolve_resolver($dryCache, $registry)->resolve($dryRepo, CodeResolver::declaredLock($dryRepo)['components'], true);
duo_check_same(
    ['would-resolve', 'would-resolve'],
    array_column($rows, 'state'),
    '--dry-run reports what it would resolve'
);
duo_check(
    !is_dir($dryRepo . '/code/wp-content/plugins/woocommerce')
        && !is_dir($dryRepo . '/code/wp-content/themes/storefront'),
    'and writes nothing into code/wp-content'
);
duo_check(!is_dir($dryCache), 'and fetches nothing: a dry run does not even warm the cache');
duo_check(
    str_contains($rows[0]['detail'], $wooUrl),
    'the dry-run report names the exact release it would fetch, so the operator can review it'
);
// The control: the identical call without --dry-run does resolve, so the
// assertions above are about the flag and not about a broken fixture.
$rows = resolve_resolver($cache, $registry)->resolve($dryRepo, CodeResolver::declaredLock($dryRepo)['components'], false);
duo_check_same(
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
resolve_write_tree($reference . '/code/wp-content/plugins/duo-agency', $agencyFiles);
resolve_write_tree($reference . '/code/wp-content/themes/storefront', $themeFiles);
$referenceDescriptor = CodeDescriptorCompiler::compile($reference, RESOLVE_FORMAT_1);

$resolvedDescriptor = CodeDescriptorCompiler::compile($resolved, RESOLVE_FORMAT_2);
duo_check(is_array($resolvedDescriptor), 'the compile gate accepts a resolved split repository');
duo_check_same(
    $referenceDescriptor['code_revision'],
    $resolvedDescriptor['code_revision'],
    'and its code_revision equals the vendored tree: resolution restores bytes, it does not rewrite them'
);
duo_check_same(
    \Duo\Canon::encode($referenceDescriptor),
    \Duo\Canon::encode($resolvedDescriptor),
    'the whole descriptor matches, so artifact_hash and every ownership root match too'
);

$unresolved = resolve_make_repo($scratch, $repoSeq, $lockRows, $vendored);
duo_check_throws(
    static fn() => CodeDescriptorCompiler::compile($unresolved, RESOLVE_FORMAT_2),
    CodeCompilationException::class,
    'the same repository before resolution is exactly what the DUO-3499 gate refuses'
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
    '__DUO_ROOT__',
    var_export($root, true),
    <<<'PHP_FIXTURE'
<?php
declare(strict_types=1);
require_once __DUO_ROOT__ . '/cli/src/Transport/Transport.php';
require_once __DUO_ROOT__ . '/cli/src/Command/CodeResolveCommand.php';

/**
 * A transport whose driver id, repo path and scripted target answers the suite
 * controls. Transport::__construct() takes the driver id straight from the
 * registry's `transport` key (cli/src/Transport/Transport.php:58-59), which is
 * exactly the closed vocabulary the resolver dispatches on.
 */
final class ResolveFixtureTransport extends \Duo\Orchestrator\Transport {
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
     * What the REAL driver of this transport would answer (DUO-3526).
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
    '__DUO_FIXTURE__',
    var_export($fixtureFile, true),
    <<<'PHP_RUNNER'
<?php
declare(strict_types=1);
require_once __DUO_FIXTURE__;

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
    $exit = \Duo\Orchestrator\CodeResolveCommand::run($driver, (array) ($spec['extra'] ?? []));
} else {
    $phase = \Duo\Orchestrator\CodeResolveCommand::deployPhase($driver, (string) ($spec['verb'] ?? 'deploy'));
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
 * The child's environment always pins `DUO_CODE_ARTIFACT_BASE` at the local
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
        array_merge((array) getenv(), ['DUO_CODE_ARTIFACT_BASE' => 'file://' . $registry], $env)
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
duo_check_same(0, $result['exit'], 'duo code-resolve succeeds on a local environment');
duo_check(
    is_dir($localRepo . '/code/wp-content/plugins/woocommerce'),
    'and materializes into the repo_path the local environment names'
);
duo_check(
    str_contains($result['stdout'], 'RESOLVED plugins/woocommerce 11.0.0'),
    'the report names each component and its locked version'
);
duo_check_same(0, $result['raw'] + $result['wp'], 'and reaches the target zero times: resolution is host work');

// K2. docker: the host side is THIS environment's bind mount, derived from
// the compose service — not the checkout the CLI happens to stand in
// (DUO-3526). `repo_path` stays a container path and is never treated as one
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
duo_check_same(0, $result['exit'], 'duo code-resolve succeeds on a docker environment');
duo_check(
    is_dir($dockerRepo . '/code/wp-content/plugins/woocommerce'),
    'and materializes into the host side of the environment\'s OWN bind mount'
);
duo_check(
    !is_dir($dockerElsewhere . '/code/wp-content/plugins/woocommerce'),
    'THE HAZARD, pinned: the working directory is inside a DIFFERENT site repository and that one is left '
    . 'untouched — before DUO-3526 cwd chose the repository, so a rehearse resolved the source and reported '
    . 'success while the target the compile reads stayed empty'
);
duo_check(
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
duo_check_same(1, $namedVolume['exit'], 'a docker environment with no writable bind at its repo_path refuses, with the same exit code every host-side resolve refusal uses');
duo_check(
    str_contains($namedVolume['stdout'] . $namedVolume['stderr'], 'does not bind its repo_path to a writable host directory'),
    'and the refusal names the condition rather than the old "run from inside the checkout" remedy, which this '
    . 'change made inert'
);

// K3. ssh: the verb refuses and names the DUO-3514 runbook.
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'ssh',
    'repo_path' => '/srv/site-repo',
]);
duo_check_same(1, $result['exit'], 'duo code-resolve refuses on an ssh environment');
duo_check(
    str_contains($result['stderr'], '[' . CodeResolveCommand::REASON_TRANSPORT_UNSUPPORTED . ']'),
    'with the reason code the docs and the deploy phase both name'
);
duo_check(
    str_contains($result['stderr'], 'DUO-3514'),
    'and it names the tracked host-to-target push work rather than inventing a workaround'
);
duo_check(
    str_contains($result['stderr'], 'docs/guides/code-updates.md'),
    'and points at the runbook for materializing on the target by hand'
);
duo_check_same(0, $result['raw'] + $result['wp'], 'the verb refuses before contacting the target at all');

// K4. The deploy phase on ssh: proceeds only when the target already hashes
// correctly, and refuses by name when it does not.
$targetSite = json_encode(['code' => RESOLVE_FORMAT_2, 'format' => 1], JSON_UNESCAPED_SLASHES);
$targetLock = CodeSourceLock::encode($lockRows);
$targetRaw = [
    'site.duo.json' => ['exit' => 0, 'stdout' => $targetSite, 'stderr' => ''],
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
        'format' => 'duo-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [
            $inventoryRow('plugins', 'woocommerce', '11.0.0', $wooTreeDigest),
            $inventoryRow('themes', 'storefront', '4.6.0', $themeTreeDigest),
        ],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
]);
duo_check_same('continue', $result['phase'], 'deploy proceeds on ssh when every locked component already hashes correctly there');
duo_check(
    str_contains($result['stdout'], "deploy phase: code-resolve\n"),
    'and says so with its own phase line'
);
duo_check(
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
        'format' => 'duo-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [$inventoryRow('plugins', 'woocommerce', '11.0.0', $wooTreeDigest)],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
]);
duo_check_same(1, $result['exit'], 'deploy refuses on ssh when a locked component is not already correct on the target');
duo_check(
    str_contains($result['stderr'], '[' . CodeResolveCommand::REASON_TRANSPORT_UNSUPPORTED . ']'),
    'with the same reason code the verb raises'
);
duo_check(
    str_contains($result['stderr'], 'themes/storefront'),
    'naming the component that is missing rather than the whole repository'
);
duo_check(
    str_contains($result['stderr'], 'refusing before compile'),
    'and stating that it stopped before compile, so no lease and no checkpoint exist to compensate'
);

// K4b. The deploy phase on DOCKER (DUO-3526): the host materializes into the
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
        'format' => 'duo-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [
            $inventoryRow('plugins', 'woocommerce', '11.0.0', $wooTreeDigest),
            $inventoryRow('themes', 'storefront', '4.6.0', $themeTreeDigest),
        ],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
// deployPhase() passes no --cache-dir (an unattended phase has no flags), so
// the child needs a writable XDG cache of its own rather than the developer's.
], null, ['XDG_CACHE_HOME' => $scratch . '/xdg']);
duo_check_same('continue', $dockerPhase['phase'], 'the docker phase proceeds once the host has resolved and the target confirms the digests');
duo_check(
    is_dir($dockerPhaseRepo . '/code/wp-content/plugins/woocommerce')
        && is_dir($dockerPhaseRepo . '/code/wp-content/themes/storefront'),
    'and the components are on disk in the host side of the target\'s bind mount, before anything is compiled'
);
duo_check(
    str_contains($dockerPhase['stdout'], "env materialize phase: code-resolve\n")
        && str_contains($dockerPhase['stdout'], 'env materialize: 2 materialized, 0 unchanged.'),
    'reported with the verb it ran under, in the vocabulary duo code-resolve already prints'
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
        'format' => 'duo-code-inventory/v1',
        'source' => CodeSourceLock::SOURCE,
        'components' => [],
    ], JSON_UNESCAPED_SLASHES), 'stderr' => ''],
], null, ['XDG_CACHE_HOME' => $scratch . '/xdg']);
duo_check_same(1, $dockerStray['phase'], 'a derived path the target does not read refuses the phase');
duo_check(
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
    $plainRepo . '/site.duo.json',
    \Duo\Canon::encode(['code' => RESOLVE_FORMAT_1, 'format' => 1, 'site' => 'fixture'])
);
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'transport' => 'local',
    'repo_path' => $plainRepo,
]);
duo_check_same('continue', $result['phase'], 'a format-1 repository has nothing to resolve');
duo_check_same('', $result['stdout'], 'and the phase prints nothing at all, so existing deploy output is byte-identical');
duo_check_same('', $result['stderr'], 'on either stream');
duo_check_same(0, $result['raw'] + $result['wp'], 'and it costs no target round trip');

// K6. A driver outside the closed transport vocabulary is left alone: it owns
// no site repository this host can reach, and the compile gate below still
// refuses anything unresolved.
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'phase',
    'verb' => 'deploy',
    'transport' => 'fixture-only',
    'repo_path' => '/fixture/repo',
]);
duo_check_same('continue', $result['phase'], 'a driver that is not one of the three shipped transports is left to the compile gate');
duo_check_same('', $result['stdout'] . $result['stderr'], 'and prints nothing');
duo_check_same(0, $result['raw'] + $result['wp'], 'and contacts nothing');

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
duo_check_same('continue', $result['phase'], 'the promote phase resolves and lets promotion continue');
duo_check(str_contains($result['stdout'], "promote phase: code-resolve\n"), 'and labels itself with the verb it is running under');
duo_check(
    is_dir($phaseRepo . '/code/wp-content/plugins/woocommerce'),
    'materializing into the host checkout before anything is compiled'
);
duo_check(
    is_dir($scratch . '/xdg/duo/code-artifacts'),
    'through the default XDG cache, because an unattended deploy passes no --cache-dir'
);

// K8. The verb's flag surface is closed.
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'local',
    'repo_path' => $localRepo,
    'extra' => ['--force'],
]);
duo_check_same(1, $result['exit'], 'an unsupported flag refuses');
duo_check(
    str_contains($result['stderr'], "unsupported argument '--force'"),
    'naming the argument rather than printing usage'
);
$result = resolve_run($runnerFile, $scratch, [
    'mode' => 'verb',
    'transport' => 'local',
    'repo_path' => $localRepo,
    'extra' => ['--cache-dir=relative/path'],
]);
duo_check_same(1, $result['exit'], 'a relative --cache-dir refuses: the cache is shared across checkouts');

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
file_put_contents($brokenRepo . '/' . CodeSourceLock::PATH, '{"format":"duo-code-lock/v0","components":[]}');
resolve_check_refuses(
    static fn() => CodeResolver::declaredLock($brokenRepo),
    CodeResolver::REASON_LOCK_UNREADABLE,
    'and a lock the v1 grammar rejects is refused too, not partially honoured'
);

resolve_remove_tree($scratch);

duo_check_summary('regress_code_resolve');
