<?php
/**
 * Offline characterization for `duo init --code=split` (DUO-3499).
 *
 * Three separate things have to hold for the split to be a REVIEWED decision
 * rather than a convenience, and this suite pins each:
 *
 *   1. classification happens on the HOST, against real archive bytes, and a
 *      component is locked only when a release actually unpacks to the tree
 *      that is installed — never because a slug and a version look right;
 *   2. the agent verifies rather than trusts: a classification naming a
 *      component this site does not have, at a version it does not have, or
 *      leaving one component unclassified, is refused;
 *   3. the classification is inside the proposal, so a stale `--confirm`
 *      cannot apply a split the operator never read.
 *
 * The registry is a local `file://` fixture. The offline corpus contacts no
 * network: `DUO_CODE_ARTIFACT_BASE` moves only WHERE bytes are fetched from,
 * and the url recorded in the lock stays the canonical wp.org one, because a
 * wp-org-release's identity is its canonical url plus its archive digest.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/PathSafety.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeDescriptorCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Init/InitRepositoryBoundary.php';
require_once __DIR__ . '/../../../../cli/src/Code/WpOrgReleases.php';

use Duo\Canon;
use Duo\CodeDescriptorCompiler;
use Duo\CodeSourceLock;
use Duo\InitRepositoryBoundary;
use Duo\Orchestrator\WpOrgReleases;

$scratch = sys_get_temp_dir() . '/duo_regress_init_code_split_' . bin2hex(random_bytes(6));
$registry = $scratch . '/registry';
$cache = $scratch . '/cache';
$site = $scratch . '/site/code/wp-content';
mkdir($registry . '/plugin', 0775, true);
mkdir($registry . '/theme', 0775, true);
mkdir($cache, 0775, true);

/** @param array<string,string> $files */
function write_tree(string $root, array $files): void {
    foreach ($files as $relative => $body) {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $body);
    }
}

/** @param array<string,string> $files */
function write_archive(string $archivePath, string $componentRoot, array $files): bool {
    if (!class_exists(ZipArchive::class)) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $zip->addEmptyDir($componentRoot);
    foreach ($files as $relative => $body) {
        $zip->addFromString($componentRoot . '/' . $relative, $body);
    }
    return $zip->close();
}

function remove_tree(string $path): void {
    if (!is_dir($path)) {
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

$wooFiles = [
    'woocommerce.php' => "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n",
    'includes/class-wc.php' => "<?php\n// wc\n",
];
$themeFiles = ['style.css' => "/*\nTheme Name: Storefront\nVersion: 4.6.0\n*/\n"];
$agencyFiles = ['duo-agency.php' => "<?php\n/**\n * Plugin Name: Duo Agency\n * Version: 1.0.0\n */\n"];
$driftFiles = [
    'akismet.php' => "<?php\n/**\n * Plugin Name: Akismet\n * Version: 5.3.0\n */\n",
    'patched.php' => "<?php\n// a local hotfix nobody upstreamed\n",
];

write_tree($site . '/plugins/woocommerce', $wooFiles);
write_tree($site . '/plugins/duo-agency', $agencyFiles);
write_tree($site . '/plugins/akismet', $driftFiles);
write_tree($site . '/themes/storefront', $themeFiles);

$zipAvailable = class_exists(ZipArchive::class);
if ($zipAvailable) {
    // WooCommerce and the theme publish exactly what is installed; Akismet's
    // published release is missing the local hotfix beside it.
    write_archive($registry . '/plugin/woocommerce.11.0.0.zip', 'woocommerce', $wooFiles);
    write_archive($registry . '/theme/storefront.4.6.0.zip', 'storefront', $themeFiles);
    write_archive($registry . '/plugin/akismet.5.3.0.zip', 'akismet', ['akismet.php' => $driftFiles['akismet.php']]);
}

$inventory = CodeDescriptorCompiler::component_inventory($site);
duo_check_same(
    ['plugins/akismet', 'plugins/duo-agency', 'plugins/woocommerce', 'themes/storefront'],
    array_map(static fn(array $r): string => $r['root'] . '/' . $r['component'], $inventory),
    'the repository inventory names every lockable component, sorted'
);
duo_check_same(
    ['5.3.0', '1.0.0', '11.0.0', '4.6.0'],
    array_column($inventory, 'version'),
    'each component reports the Version header it declares'
);

// ---------------------------------------------------------------------------
// A. Host classification against the local registry fixture.
// ---------------------------------------------------------------------------

duo_check_same(
    'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
    WpOrgReleases::canonicalUrl('plugins', 'woocommerce', '11.0.0'),
    'a plugin release identity is its canonical downloads.wordpress.org url'
);
duo_check_same(
    'https://downloads.wordpress.org/theme/storefront.4.6.0.zip',
    WpOrgReleases::canonicalUrl('themes', 'storefront', '4.6.0'),
    'themes resolve under the theme path segment'
);
duo_check_throws(
    static fn() => WpOrgReleases::canonicalUrl('plugins', 'woocommerce', '../../etc'),
    RuntimeException::class,
    'a version that could escape a URL path is not a release identity'
);
duo_check(!WpOrgReleases::safeVersion(''), 'a component with no version header has no release identity');

if ($zipAvailable) {
    $releases = new WpOrgReleases($cache, false, 'file://' . $registry);
    $plan = $releases->classify($inventory);
    $byKey = [];
    foreach ($plan as $row) {
        $byKey[$row['root'] . '/' . $row['component']] = $row;
    }

    duo_check_same('locked', $byKey['plugins/woocommerce']['classification'], 'a release that unpacks to the installed bytes locks');
    duo_check_same('locked', $byKey['themes/storefront']['classification'], 'a theme locks through the same comparison');
    duo_check_same('vendored', $byKey['plugins/akismet']['classification'], 'a locally modified component does NOT lock');
    duo_check_same('vendored', $byKey['plugins/duo-agency']['classification'], 'a component with no published release stays vendored');

    duo_check(
        str_contains($byKey['plugins/akismet']['reason'], 'not the installed tree'),
        'the drifted component states WHY it is vendored, with both digests, rather than being silently omitted'
    );
    duo_check(
        str_contains($byKey['plugins/duo-agency']['reason'], 'no verified wp.org release'),
        'a premium or first-party component states that no release was verified'
    );
    duo_check(
        !array_key_exists('origin', $byKey['plugins/akismet']) && !array_key_exists('origin', $byKey['plugins/duo-agency']),
        'a vendored row carries no origin: nothing claims provenance it did not verify'
    );

    $origin = $byKey['plugins/woocommerce']['origin'];
    duo_check_same('wp-org-release', $origin['kind'], 'a locked component records the wp-org-release origin kind');
    duo_check_same(
        'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
        $origin['url'],
        'the CANONICAL url is recorded even though the bytes came from the local fixture: identity is url plus digest, not the host that served it'
    );
    duo_check(
        preg_match('/^[0-9a-f]{64}$/', (string) $origin['archive_sha256']) === 1,
        'the archive digest recorded is the digest of the bytes that were actually fetched'
    );
    duo_check_same(
        $byKey['plugins/woocommerce']['tree_sha256'],
        $inventory[2]['tree_sha256'],
        'the locked row carries the installed tree digest the compile gate will demand'
    );

    // The lock rows a locked classification produces must satisfy the grammar.
    $lockRows = [];
    foreach ($plan as $row) {
        if ($row['classification'] === 'locked') {
            $lockRows[] = [
                'root' => $row['root'],
                'component' => $row['component'],
                'version' => $row['version'],
                'origin' => $row['origin'],
                'tree_sha256' => $row['tree_sha256'],
            ];
        }
    }
    CodeSourceLock::assert_lock(['format' => CodeSourceLock::FORMAT, 'components' => CodeSourceLock::sort_components($lockRows)]);
    duo_check(true, 'the classification produces lock rows the lock grammar accepts unchanged');

    // Cache: a second run must not re-fetch, and a corrupted entry must refuse
    // rather than silently replace the only copy of that evidence.
    $canonical = WpOrgReleases::canonicalUrl('plugins', 'woocommerce', '11.0.0');
    duo_check_same('cache', $releases->fetch($canonical)['source'], 'a second fetch is served from the content-addressed host cache');
    $cached = $releases->cachePath($canonical);
    file_put_contents($cached, 'corrupted');
    duo_check_throws(
        static fn() => $releases->fetch($canonical),
        RuntimeException::class,
        'a cache entry that no longer matches its recorded digest refuses instead of re-fetching',
        'no longer matches its recorded digest'
    );
    unlink($cached);
    unlink($cached . '.sha256');

    // A declared archive digest that the download does not match must leave
    // nothing cached.
    duo_check_throws(
        static fn() => $releases->fetch($canonical, str_repeat('e', 64)),
        RuntimeException::class,
        'a download whose digest disagrees with the lock refuses',
        'the partial download was removed and nothing was cached'
    );
    duo_check(!is_file($cached), 'and nothing was published into the cache');

    duo_check_same(
        WpOrgReleases::treeDigest($site . '/plugins/woocommerce'),
        $inventory[2]['tree_sha256'],
        'the host tree digest is the agent tree digest: one algorithm, two sides of the wire'
    );
} else {
    duo_check_throws(
        static fn() => (new WpOrgReleases($cache))->unpack($registry . '/absent.zip', $cache . '/out'),
        RuntimeException::class,
        'without ZipArchive the host refuses loudly and names the remedy instead of guessing',
        'install the php-zip extension'
    );
}

// Offline mode contacts nothing and locks nothing, whatever the registry holds.
$offlineReleases = new WpOrgReleases($scratch . '/empty-cache', true, 'file://' . $registry);
$offlinePlan = $offlineReleases->classify($inventory);
duo_check_same(
    ['vendored', 'vendored', 'vendored', 'vendored'],
    array_column($offlinePlan, 'classification'),
    '--offline classifies every component vendored'
);
duo_check(
    str_contains($offlinePlan[0]['reason'], 'offline: no release registry was contacted'),
    'and states the reason on every row rather than leaving the operator to infer it'
);
duo_check_throws(
    static fn() => $offlineReleases->fetch(WpOrgReleases::canonicalUrl('plugins', 'woocommerce', '11.0.0')),
    RuntimeException::class,
    'offline mode refuses an outright fetch on a cache miss',
    'offline mode refuses to fetch'
);

// ---------------------------------------------------------------------------
// B. The agent verifies the classification instead of trusting it.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../../../../agent/src/Init/InitPlanner.php';
$codeSplit = new ReflectionMethod(\Duo\InitPlanner::class, 'code_split');

// Reflection, deliberately: code_split() is reached from proposal_bound(),
// which needs a loaded WordPress and a live $wpdb. The verification rules are
// what this section is about, and they are pure.
$probeInventory = [
    ['bytes' => 10, 'component' => 'akismet', 'files' => 2, 'root' => 'plugins', 'tree_sha256' => str_repeat('1', 64), 'version' => '5.3.0'],
    ['bytes' => 10, 'component' => 'woocommerce', 'files' => 2, 'root' => 'plugins', 'tree_sha256' => str_repeat('2', 64), 'version' => '11.0.0'],
];
$code = [
    'component_inventory' => $probeInventory,
    'declaration' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
];
$lockedRow = [
    'classification' => 'locked',
    'component' => 'woocommerce',
    'origin' => [
        'kind' => 'wp-org-release',
        'url' => 'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
        'archive_sha256' => str_repeat('a', 64),
    ],
    'reason' => 'the wp.org release woocommerce 11.0.0 unpacks to exactly these bytes',
    'root' => 'plugins',
    'tree_sha256' => str_repeat('2', 64),
    'version' => '11.0.0',
];
$vendoredRow = [
    'classification' => 'vendored',
    'component' => 'akismet',
    'reason' => 'the wp.org release akismet 5.3.0 unpacks to something else',
    'root' => 'plugins',
    'tree_sha256' => str_repeat('1', 64),
    'version' => '5.3.0',
];

$unclassified = $codeSplit->invoke(null, $code, null);
duo_check_same([], $unclassified['split'], 'no classification means an empty split');
duo_check_same(
    ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    $unclassified['declaration'],
    'and the fully vendored format-1 declaration every pre-DUO-3499 repository has'
);
duo_check(!array_key_exists('lock', $unclassified), 'and no lock is proposed');

$split = $codeSplit->invoke(null, $code, [$lockedRow, $vendoredRow]);
duo_check_same(
    ['format' => 2, 'layout' => 'wp-content', 'lock' => 'code/duo-code.lock.json', 'source' => 'code/wp-content'],
    $split['declaration'],
    'one locked component switches the declaration to format 2 naming the lock'
);
duo_check_same(
    [['component' => 'woocommerce', 'origin' => $lockedRow['origin'], 'root' => 'plugins', 'tree_sha256' => str_repeat('2', 64), 'version' => '11.0.0']],
    array_map(static function (array $row): array {
        ksort($row, SORT_STRING);
        return $row;
    }, $split['lock']),
    'the lock carries exactly the locked components, and nothing about the vendored ones'
);
duo_check_same(
    ['plugins/akismet', 'plugins/woocommerce'],
    array_map(static fn(array $r): string => $r['root'] . '/' . $r['component'], $split['split']),
    'the reviewed split is deterministically sorted regardless of the order the host sent'
);

// A changed classification changes the code block, which is inside the digest.
$allVendored = $codeSplit->invoke(null, $code, [
    ['classification' => 'vendored'] + array_diff_key($lockedRow, ['classification' => null, 'origin' => null]),
    $vendoredRow,
]);
duo_check(
    Canon::encode($split) !== Canon::encode($allVendored),
    'a different classification produces a different code block, so a stale --confirm digest cannot apply it'
);

$plannerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Init/InitPlanner.php');
duo_check(
    str_contains($plannerSource, "'code' => \$code,")
        && str_contains($plannerSource, "\$proposal['digest'] = hash('sha256', Canon::encode(\$proposal));"),
    'and the code block really is inside the digested proposal'
);

foreach ([
    'a component this site does not have' => [
        [['classification' => 'vendored', 'component' => 'ghost', 'reason' => 'r', 'root' => 'plugins', 'tree_sha256' => str_repeat('3', 64), 'version' => '1.0'], $lockedRow, $vendoredRow],
        'is not an active component of this site',
    ],
    'a version the site does not have' => [
        [array_replace($lockedRow, ['version' => '10.0.0']), $vendoredRow],
        'describes a different version or tree digest',
    ],
    'bytes that moved since the probe' => [
        [array_replace($lockedRow, ['tree_sha256' => str_repeat('9', 64)]), $vendoredRow],
        'describes a different version or tree digest',
    ],
    'a component left unclassified' => [
        [$lockedRow],
        'it says nothing about plugins/akismet',
    ],
    'a duplicate component' => [
        [$lockedRow, $lockedRow, $vendoredRow],
        'more than once',
    ],
    'an unknown classification' => [
        [array_replace($lockedRow, ['classification' => 'maybe']), $vendoredRow],
        'must be locked or vendored',
    ],
    'a locked row with no origin' => [
        [array_diff_key($lockedRow, ['origin' => null]), $vendoredRow],
        'must contain exactly',
    ],
    'a vendored row claiming an origin' => [
        [$lockedRow, $vendoredRow + ['origin' => $lockedRow['origin']]],
        'must contain exactly',
    ],
    'a reason nobody can read' => [
        [array_replace($lockedRow, ['reason' => '   ']), $vendoredRow],
        'must state a single-line reason',
    ],
    'an origin the lock grammar refuses' => [
        [array_replace($lockedRow, ['origin' => ['kind' => 'wp-org-release', 'url' => 'http://insecure/x.zip', 'archive_sha256' => str_repeat('a', 64)]]), $vendoredRow],
        'origin.url must be an https:// URL',
    ],
] as $label => [$plan, $needle]) {
    duo_check_throws(
        static fn() => $codeSplit->invoke(null, $code, $plan),
        RuntimeException::class,
        "the agent refuses a classification naming $label",
        $needle
    );
}

// ---------------------------------------------------------------------------
// C. The generated .gitignore lines, in the one placement the code half allows.
// ---------------------------------------------------------------------------

duo_check_same(
    ['/code/wp-content/plugins/woocommerce/', '/code/wp-content/themes/storefront/'],
    InitRepositoryBoundary::locked_component_ignore_lines([
        ['root' => 'themes', 'component' => 'storefront'],
        ['root' => 'plugins', 'component' => 'woocommerce'],
    ]),
    'locked components produce root-anchored ignore lines, sorted'
);
duo_check_throws(
    static fn() => InitRepositoryBoundary::locked_component_ignore_lines([['root' => 'plugins']]),
    RuntimeException::class,
    'a malformed locked row cannot produce an ignore line'
);

$repo = $scratch . '/gitignore-repo';
mkdir($repo, 0775, true);
file_put_contents($repo . '/.gitignore', "node_modules/\n");
require_once __DIR__ . '/../../../../agent/src/Init/InitOwnedArtifacts.php';
$identity = \Duo\InitOwnedArtifacts::owned_file_boundary_identity($repo . '/.gitignore', '.gitignore');
InitRepositoryBoundary::ensure_gitignore($repo, $identity, ['/code/wp-content/plugins/woocommerce/']);
$written = (string) file_get_contents($repo . '/.gitignore');
duo_check(str_contains($written, "node_modules/\n"), 'an existing .gitignore is preserved and only appended to');
duo_check(
    str_contains($written, "# Duo code lock: these components are declared in code/duo-code.lock.json, not carried in Git\n/code/wp-content/plugins/woocommerce/\n"),
    'the locked components get their own labelled block, separate from Duo local artifacts'
);
duo_check(str_contains($written, "/.duo/\n"), 'and Duo\'s own local artifacts are still published in the same transaction');
duo_check(
    !is_file($repo . '/code/wp-content/.gitignore') && !is_file($repo . '/code/wp-content/plugins/.gitignore'),
    'no ignore file is ever written inside code/wp-content: one placement refuses compile, the other ships to the target'
);
$identityAfter = \Duo\InitOwnedArtifacts::owned_file_boundary_identity($repo . '/.gitignore', '.gitignore');
duo_check_same(
    null,
    InitRepositoryBoundary::ensure_gitignore($repo, $identityAfter, ['/code/wp-content/plugins/woocommerce/']),
    'a second run with the same locked components rewrites nothing'
);

// ---------------------------------------------------------------------------
// D. --code=full and --offline never reach a registry, and say why.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Command/InitCommand.php';
$classify = new ReflectionMethod(\Duo\Orchestrator\InitCommand::class, 'classify');

/** A transport that fails the suite if init calls the target again when nothing locks. */
final class SplitFixtureTransport extends \Duo\Orchestrator\Transport {
    public function __construct() { parent::__construct('split-fixture', ['repo_path' => '/srv/site']); }
    public function describe(): string { return 'code split fixture'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }
    public function captureWp(array $wpArgs): array {
        throw new RuntimeException('the target must not be called again when nothing locks');
    }
}
$driver = new SplitFixtureTransport();

$proposal = ['ready' => true, 'code' => ['component_inventory' => $inventory]];
foreach ([
    'full' => ['full', false, '--code=full: every active component is vendored into Git'],
    'offline' => ['split', true, '--offline: no release registry was contacted'],
] as $label => [$mode, $offline, $expected]) {
    ob_start();
    [$returned, $lockPlan] = $classify->invoke(null, $driver, $proposal, false, $mode, $offline, $cache);
    $printed = (string) ob_get_clean();
    duo_check_same(null, $lockPlan, "--code=$label proposes no lock");
    duo_check_same($proposal, $returned, "--code=$label leaves the reviewed proposal exactly as the target produced it");
    duo_check(str_contains($printed, $expected), "--code=$label states its reason before the proposal is rendered");
}

ob_start();
[$returned, $lockPlan] = $classify->invoke(null, $driver, ['ready' => true, 'code' => ['component_inventory' => []]], false, 'split', false, $cache);
$printed = (string) ob_get_clean();
duo_check_same(null, $lockPlan, 'a site with no lockable component proposes no lock and contacts nothing');
duo_check(str_contains($printed, 'no lockable plugin or theme component'), 'and says so');

// ---------------------------------------------------------------------------
// E. The capture-lock name is never rebound by the code-lock write.
// ---------------------------------------------------------------------------

// InitConfirmation's full transaction needs a loaded WordPress and a live
// $wpdb, so it cannot run here -- but the defect this pins is structural and
// therefore is pinnable. `$lockPath` is bound once, ~340 lines before the code
// lock is published, to the state.capture.lock this transaction holds, and is
// read again AFTER the capture payload is staged to prove the lock pathname
// still names the held inode. DUO-3499 shipped the code-lock write reusing that
// same variable name, which pointed that gate at code/duo-code.lock.json and
// made it refuse every split init with "capture lock pathname no longer names
// the held lock inode" -- a split init could not complete at all. The invariant
// is "the capture-lock name is assigned exactly once, and to the capture lock",
// so that is what this asserts, rather than the absence of one bad spelling.
$confirmationSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Init/InitConfirmation.php');
preg_match_all('/\$lockPath\s*=(?!=)/', $confirmationSource, $lockPathAssignments);
duo_check_same(
    1,
    count($lockPathAssignments[0]),
    'InitConfirmation binds $lockPath exactly once, so no later write can retarget the capture-lock gate'
);
duo_check(
    str_contains($confirmationSource, '$lockPath = Publish::lock_path($stateDir);'),
    'and binds it to the state.capture.lock this transaction holds'
);
duo_check(
    str_contains($confirmationSource, "InitOwnedArtifacts::regular_file_identity(\$lockPath, 'state.capture.lock')"),
    'so the post-staging re-verification still identifies the capture lock by that name'
);
duo_check(
    str_contains($confirmationSource, "\$codeLockPath = \$codeRoot . '/' . basename(CodeSourceLock::PATH);")
        && str_contains($confirmationSource, 'Canon::write_file($codeLockPath, CodeSourceLock::encode($lockRows));'),
    'and the code lock is published through its own distinct path variable'
);

remove_tree($scratch);

duo_check_summary('regress_init_code_split');
