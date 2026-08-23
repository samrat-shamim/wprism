<?php
/**
 * Offline characterization for `duo init`'s code classification (DUO-3499,
 * then the no-third-party-bytes invariant).
 *
 * Four separate things have to hold for the classification to be a REVIEWED
 * decision rather than a convenience, and this suite pins each:
 *
 *   1. classification happens on the HOST, against real archive bytes, and a
 *      component is locked only when a release — published on wp.org, or
 *      imported on this host with `duo code-import` — actually unpacks to the
 *      tree that is installed, never because a slug and a version look right;
 *   2. the agent verifies rather than trusts: a classification naming a
 *      component this site does not have, at a version it does not have, or
 *      leaving one component unclassified, is refused;
 *   3. the classification is inside the proposal, so a stale `--confirm`
 *      cannot apply a split the operator never read;
 *   4. there is no third shape: a component that neither locks nor is declared
 *      first-party is UNSOURCED, which the agent turns into a blocking
 *      `code_component_unsourced` row — Git never carries third-party code by
 *      omission, and `--code=full` no longer exists to ask for it.
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
require_once __DIR__ . '/../../../../cli/src/Code/ImportedArchives.php';
require_once __DIR__ . '/../../../../cli/src/Code/CodeClassifier.php';

use Duo\Canon;
use Duo\CodeDescriptorCompiler;
use Duo\CodeSourceLock;
use Duo\InitRepositoryBoundary;
use Duo\Orchestrator\CodeClassifier;
use Duo\Orchestrator\ImportedArchives;
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
    $classifier = new CodeClassifier($releases, ImportedArchives::forReleases($releases));
    $plan = $classifier->classify($inventory);
    $byKey = [];
    foreach ($plan as $row) {
        $byKey[$row['root'] . '/' . $row['component']] = $row;
    }

    duo_check_same('locked', $byKey['plugins/woocommerce']['classification'], 'a release that unpacks to the installed bytes locks');
    duo_check_same('locked', $byKey['themes/storefront']['classification'], 'a theme locks through the same comparison');
    duo_check_same('unsourced', $byKey['plugins/akismet']['classification'], 'a locally modified component does NOT lock, and is not vendored either: it is unsourced');
    duo_check_same('unsourced', $byKey['plugins/duo-agency']['classification'], 'a component with no published release and no declaration is unsourced');

    duo_check(
        str_contains($byKey['plugins/akismet']['reason'], 'not the installed tree')
            && str_contains($byKey['plugins/akismet']['reason'], 'no imported archive on this host unpacks to this tree'),
        'the drifted component states WHY it is unsourced — both digests, and that no imported archive matched — rather than being silently omitted'
    );
    duo_check(
        str_contains($byKey['plugins/duo-agency']['reason'], 'no verified wp.org release'),
        'a premium or first-party component states that no release was verified'
    );
    duo_check(
        !array_key_exists('origin', $byKey['plugins/akismet']) && !array_key_exists('origin', $byKey['plugins/duo-agency']),
        'an unsourced row carries no origin: nothing claims provenance it did not verify'
    );

    // The operator's declaration is the one thing that makes Git carry a
    // component, and it is verified against nothing: the declaration IS the
    // decision, spelled out on the row.
    $declared = $classifier->classify($inventory, ['plugins/duo-agency']);
    $declaredByKey = [];
    foreach ($declared as $row) {
        $declaredByKey[$row['root'] . '/' . $row['component']] = $row;
    }
    duo_check_same('first-party', $declaredByKey['plugins/duo-agency']['classification'], '--first-party declares a component the site\'s own');
    duo_check(
        str_contains($declaredByKey['plugins/duo-agency']['reason'], 'declared first-party by the operator')
            && !array_key_exists('origin', $declaredByKey['plugins/duo-agency']),
        'a first-party row states the declaration as its reason and claims no origin'
    );
    duo_check_same('unsourced', $declaredByKey['plugins/akismet']['classification'], 'declaring one component says nothing about another');
    duo_check_throws(
        static fn() => CodeClassifier::assertFirstPartyKnown($inventory, ['plugins/ghost']),
        RuntimeException::class,
        'a first-party declaration naming no component of this site is refused, not silently ignored',
        'which is not a component this site has'
    );
    duo_check_same(
        ['plugins/duo-agency', 'themes/storefront'],
        CodeClassifier::parseFirstParty(['themes/storefront,plugins/duo-agency', 'plugins/duo-agency', '']),
        '--first-party is repeatable and comma-separable, and parses to one sorted, deduplicated identity list'
    );
    duo_check_throws(
        static fn() => CodeClassifier::parseFirstParty(['woocommerce']),
        RuntimeException::class,
        'a bare slug is not a first-party identity: the root is part of it',
        'must be <root>/<slug>'
    );

    // The imported-archive leg: the operator holds the drifted Akismet's exact
    // bytes as an archive (a vendor build, a premium release), imports it on
    // this host, and the SAME tree that was unsourced now locks — against the
    // import's digest, with no URL and no path anywhere in the origin.
    $importedStore = ImportedArchives::forReleases($releases);
    $akismetArchive = $scratch . '/akismet-vendor-5.3.0.zip';
    write_archive($akismetArchive, 'akismet-5.3.0', $driftFiles);
    $import = $importedStore->import($akismetArchive, 'akismet', 'plugins');
    duo_check_same('imported', $import['state'], 'an archive is imported once');
    duo_check_same(hash_file('sha256', $akismetArchive), $import['archive_sha256'], 'the import is identified by the archive digest');
    duo_check_same($inventory[0]['tree_sha256'], $import['tree_sha256'], 'and unpacks to exactly the installed tree digest, computed by the one shared algorithm');
    duo_check_same('akismet-5.3.0', $import['archive_root'], 'an archive whose directory is not named after the component records the archive_root');
    duo_check_same('5.3.0', $import['version'], 'the Version header is read for the operator to review');
    duo_check_same('already-imported', $importedStore->import($akismetArchive, 'akismet', 'plugins')['state'], 'importing the same archive again is idempotent');
    $imported = $classifier->classify($inventory);
    $importedByKey = [];
    foreach ($imported as $row) {
        $importedByKey[$row['root'] . '/' . $row['component']] = $row;
    }
    duo_check_same('locked', $importedByKey['plugins/akismet']['classification'], 'after the import the drifted component LOCKS');
    duo_check_same(
        ['archive_root' => 'akismet-5.3.0', 'archive_sha256' => $import['archive_sha256'], 'kind' => 'imported-archive'],
        (static function (array $origin): array { ksort($origin, SORT_STRING); return $origin; })($importedByKey['plugins/akismet']['origin']),
        'its origin is the imported-archive kind, the archive digest and the archive root — no url, no path'
    );
    duo_check(
        str_contains($importedByKey['plugins/akismet']['reason'], 'imported archive ' . substr($import['archive_sha256'], 0, 12)),
        'the reason names the import'
    );
    duo_check_same('locked', $importedByKey['plugins/woocommerce']['classification'], 'a wp.org release still wins where it matches: public provenance before a private copy');
    duo_check_same('unsourced', $importedByKey['plugins/duo-agency']['classification'], 'and a component nobody imported or declared is still unsourced');
    duo_check_throws(
        static fn() => $importedStore->import($scratch . '/nope.zip', null, 'plugins'),
        \Duo\CommandRefusalException::class,
        'importing a file that does not exist refuses by name',
        'cannot read'
    );
    duo_check_throws(
        static fn() => $importedStore->import($akismetArchive, null, 'mu-plugins'),
        \Duo\CommandRefusalException::class,
        'an imported archive is a plugin or a theme, nothing else',
        'root must be one of plugins/themes'
    );
    file_put_contents($scratch . '/two-roots.zip', '');
    $twoRoots = new ZipArchive();
    $twoRoots->open($scratch . '/two-roots.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $twoRoots->addFromString('a/a.php', "<?php\n");
    $twoRoots->addFromString('b/b.php', "<?php\n");
    $twoRoots->close();
    duo_check_throws(
        static fn() => $importedStore->import($scratch . '/two-roots.zip', null, 'plugins'),
        \Duo\CommandRefusalException::class,
        'a multi-root bundle is refused rather than guessed at',
        'exactly one component directory'
    );
    // Corruption is evidence, never silently replaced: a stored archive whose
    // bytes no longer hash to its name refuses on the next lookup.
    file_put_contents($import['path'], 'corrupted');
    duo_check_throws(
        static fn() => $classifier->classify($inventory),
        \Duo\CommandRefusalException::class,
        'a corrupted imported archive refuses the classification by name instead of being re-imported over',
        'no longer hashes to'
    );
    copy($akismetArchive, $import['path']);

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

    // The lock rows a classification produces must satisfy the grammar, with
    // the first-party declarations beside them.
    $withDeclaration = $classifier->classify($inventory, ['plugins/duo-agency']);
    $lockRows = CodeClassifier::lockRows($withDeclaration);
    duo_check_same(
        ['plugins/akismet', 'plugins/woocommerce', 'themes/storefront'],
        array_map(static fn(array $r): string => $r['root'] . '/' . $r['component'], $lockRows),
        'lockRows() carries exactly the locked components, sorted'
    );
    duo_check_same(['plugins/duo-agency'], CodeClassifier::firstPartyIdentities($withDeclaration), 'firstPartyIdentities() carries exactly the declared ones');
    duo_check_same([], CodeClassifier::unsourced($withDeclaration), 'and with every component locked or declared, nothing is unsourced');
    $parsedLock = CodeSourceLock::parse(CodeSourceLock::encode($lockRows, CodeClassifier::firstPartyIdentities($withDeclaration)));
    duo_check_same(['plugins/duo-agency'], $parsedLock['first_party'], 'the classification produces a lock the grammar accepts unchanged, declarations included');

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

// Offline mode contacts nothing: with an empty host cache nothing locks and
// every row says why; with a primed cache the cached release still locks,
// because "offline" is about the network, not about the evidence already here.
$offlineReleases = new WpOrgReleases($scratch . '/empty-cache', true, 'file://' . $registry);
$offlinePlan = (new CodeClassifier($offlineReleases, ImportedArchives::forReleases($offlineReleases)))->classify($inventory);
duo_check_same(
    ['unsourced', 'unsourced', 'unsourced', 'unsourced'],
    array_column($offlinePlan, 'classification'),
    '--offline with an empty host cache classifies every component unsourced — never vendored'
);
duo_check(
    str_contains($offlinePlan[2]['reason'], 'offline mode refuses to fetch'),
    'and states the reason on the row rather than leaving the operator to infer it'
);
duo_check_throws(
    static fn() => $offlineReleases->fetch(WpOrgReleases::canonicalUrl('plugins', 'woocommerce', '11.0.0')),
    RuntimeException::class,
    'offline mode refuses an outright fetch on a cache miss',
    'offline mode refuses to fetch'
);
if ($zipAvailable) {
    // The theme's archive is still in $cache from section A (the WooCommerce
    // entry was deliberately corrupted and removed there), so offline against
    // THAT cache locks the theme and nothing else.
    $primedOffline = new WpOrgReleases($cache, true, 'file://' . $registry);
    $primedPlan = (new CodeClassifier($primedOffline, ImportedArchives::forReleases($primedOffline)))->classify($inventory);
    $primedByKey = [];
    foreach ($primedPlan as $row) {
        $primedByKey[$row['root'] . '/' . $row['component']] = $row;
    }
    duo_check_same('locked', $primedByKey['themes/storefront']['classification'], '--offline locks a component whose release is already in the host cache');
    duo_check_same('locked', $primedByKey['plugins/akismet']['classification'], 'and an imported archive locks offline exactly as it does online');
    duo_check_same('unsourced', $primedByKey['plugins/woocommerce']['classification'], 'while a cache miss stays unsourced rather than being fetched');
}

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
$unsourcedRow = [
    'classification' => 'unsourced',
    'component' => 'akismet',
    'reason' => 'the wp.org release akismet 5.3.0 unpacks to something else; no imported archive on this host unpacks to this tree',
    'root' => 'plugins',
    'tree_sha256' => str_repeat('1', 64),
    'version' => '5.3.0',
];
$firstPartyRow = array_replace($unsourcedRow, [
    'classification' => 'first-party',
    'reason' => 'declared first-party by the operator; Git carries it as the site\'s own code',
]);

[$unclassified, $blockers] = $codeSplit->invoke(null, $code, null);
duo_check_same([], $unclassified['split'], 'no classification means an empty split');
duo_check_same(
    ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    $unclassified['declaration'],
    'and the format-1 declaration, which the host sends only for a site with no lockable component'
);
duo_check(!array_key_exists('lock', $unclassified), 'and no lock is proposed');
duo_check_same([], $blockers, 'and nothing blocks');

[$split, $blockers] = $codeSplit->invoke(null, $code, [$lockedRow, $unsourcedRow]);
duo_check_same(
    ['format' => 2, 'layout' => 'wp-content', 'lock' => 'code/duo-code.lock.json', 'source' => 'code/wp-content'],
    $split['declaration'],
    'a classified site is the format-2 declaration naming the lock'
);
duo_check_same(
    [['component' => 'woocommerce', 'origin' => $lockedRow['origin'], 'root' => 'plugins', 'tree_sha256' => str_repeat('2', 64), 'version' => '11.0.0']],
    array_map(static function (array $row): array {
        ksort($row, SORT_STRING);
        return $row;
    }, $split['lock']),
    'the lock carries exactly the locked components, and nothing about the unsourced ones'
);
duo_check_same([], $split['first_party'], 'and no first-party declaration, since none was made');
duo_check_same(
    ['plugins/akismet', 'plugins/woocommerce'],
    array_map(static fn(array $r): string => $r['root'] . '/' . $r['component'], $split['split']),
    'the reviewed split is deterministically sorted regardless of the order the host sent'
);
duo_check_same(1, count($blockers), 'the unsourced component is exactly one blocker');
duo_check_same('code_component_unsourced', $blockers[0]['code'], 'named code_component_unsourced');
duo_check_same('plugins/akismet', $blockers[0]['extension'], 'naming the component');
duo_check_same('code', $blockers[0]['kind'], 'as a code-kind unsupported row, so the proposal reads BLOCKED');
duo_check(
    str_contains($blockers[0]['reason'], 'unpacks to something else')
        && str_contains($blockers[0]['reason'], 'Git must not carry third-party code'),
    'the blocker carries the host\'s reason and states the invariant'
);
duo_check(
    str_contains($blockers[0]['remediation'], '`duo code-import <archive.zip>`')
        && str_contains($blockers[0]['remediation'], '`duo init --first-party=plugins/akismet`'),
    'and names both remedies: import the archive, or declare the component first-party'
);

// A declaration instead: nothing blocks, the lock records it, and a site with
// NOTHING locked still gets the format-2 declaration — the first_party list is
// what lets the compile gate tell declared from omitted.
[$declared, $blockers] = $codeSplit->invoke(null, $code, [$lockedRow, $firstPartyRow]);
duo_check_same([], $blockers, 'a first-party declaration blocks nothing');
duo_check_same(['plugins/akismet'], $declared['first_party'], 'and is recorded in the proposed lock');
[$onlyDeclared, $blockers] = $codeSplit->invoke(null, $code, [
    ['classification' => 'first-party', 'reason' => 'declared first-party by the operator'] + array_diff_key($lockedRow, ['classification' => null, 'origin' => null, 'reason' => null]),
    $firstPartyRow,
]);
duo_check_same(2, (int) $onlyDeclared['declaration']['format'], 'a site with nothing locked and everything declared is STILL the format-2 declaration');
duo_check_same([], $onlyDeclared['lock'], 'with an empty components list');
duo_check_same(['plugins/akismet', 'plugins/woocommerce'], $onlyDeclared['first_party'], 'and both declarations, sorted');

// A changed classification changes the code block, which is inside the digest.
[$allUnsourced] = $codeSplit->invoke(null, $code, [
    ['classification' => 'unsourced'] + array_diff_key($lockedRow, ['classification' => null, 'origin' => null]),
    $unsourcedRow,
]);
duo_check(
    Canon::encode($split) !== Canon::encode($allUnsourced) && Canon::encode($split) !== Canon::encode($declared),
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
        [['classification' => 'unsourced', 'component' => 'ghost', 'reason' => 'r', 'root' => 'plugins', 'tree_sha256' => str_repeat('3', 64), 'version' => '1.0'], $lockedRow, $unsourcedRow],
        'is not an active component of this site',
    ],
    'a version the site does not have' => [
        [array_replace($lockedRow, ['version' => '10.0.0']), $unsourcedRow],
        'describes a different version or tree digest',
    ],
    'bytes that moved since the probe' => [
        [array_replace($lockedRow, ['tree_sha256' => str_repeat('9', 64)]), $unsourcedRow],
        'describes a different version or tree digest',
    ],
    'a component left unclassified' => [
        [$lockedRow],
        'it says nothing about plugins/akismet',
    ],
    'a duplicate component' => [
        [$lockedRow, $lockedRow, $unsourcedRow],
        'more than once',
    ],
    'an unknown classification' => [
        [array_replace($lockedRow, ['classification' => 'maybe']), $unsourcedRow],
        'must be locked, first-party or unsourced',
    ],
    'the retired vendored classification' => [
        [array_replace($lockedRow, ['classification' => 'vendored']), $unsourcedRow],
        'must be locked, first-party or unsourced',
    ],
    'a locked row with no origin' => [
        [array_diff_key($lockedRow, ['origin' => null]), $unsourcedRow],
        'must contain exactly',
    ],
    'an unsourced row claiming an origin' => [
        [$lockedRow, $unsourcedRow + ['origin' => $lockedRow['origin']]],
        'must contain exactly',
    ],
    'a first-party row claiming an origin' => [
        [$lockedRow, $firstPartyRow + ['origin' => $lockedRow['origin']]],
        'must contain exactly',
    ],
    'a reason nobody can read' => [
        [array_replace($lockedRow, ['reason' => '   ']), $unsourcedRow],
        'must state a single-line reason',
    ],
    'an origin the lock grammar refuses' => [
        [array_replace($lockedRow, ['origin' => ['kind' => 'wp-org-release', 'url' => 'http://insecure/x.zip', 'archive_sha256' => str_repeat('a', 64)]]), $unsourcedRow],
        'origin.url must be an https:// URL',
    ],
    'the retired vendored-archive origin' => [
        [array_replace($lockedRow, ['origin' => ['kind' => 'vendored-archive', 'path' => 'code/archives/x.zip', 'archive_sha256' => str_repeat('a', 64)]]), $unsourcedRow],
        "origin.kind 'vendored-archive' is no longer a lock origin",
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
// D. The host command: no --code mode exists, the classification ALWAYS
//    re-proposes for a site with components, and only an empty inventory
//    skips the registry and the second target call.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Command/InitCommand.php';
$classify = new ReflectionMethod(\Duo\Orchestrator\InitCommand::class, 'classify');

/** A transport that records every target call and refuses it, so the suite can tell WHETHER init reached the target. */
final class SplitFixtureTransport extends \Duo\Orchestrator\Transport {
    /** @var list<list<string>> */
    public array $calls = [];
    public function __construct() { parent::__construct('split-fixture', ['repo_path' => '/srv/site']); }
    public function describe(): string { return 'code split fixture'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }
    public function captureWp(array $wpArgs): array {
        $this->calls[] = array_values(array_map('strval', $wpArgs));
        throw new RuntimeException('fixture: the target was called');
    }
}
$driver = new SplitFixtureTransport();

ob_start();
[$returned, $lockPlan] = $classify->invoke(null, $driver, ['ready' => true, 'code' => ['component_inventory' => []]], false, [], false, $cache);
$printed = (string) ob_get_clean();
duo_check_same(null, $lockPlan, 'a site with no lockable component proposes no lock and contacts nothing');
duo_check(str_contains($printed, 'no lockable plugin or theme component'), 'and says so');
duo_check_same([], $driver->calls, 'and the target is not called a second time');
duo_check_throws(
    static fn() => $classify->invoke(null, $driver, ['ready' => true, 'code' => ['component_inventory' => []]], false, ['plugins/duo-agency'], false, $cache),
    RuntimeException::class,
    'a first-party declaration against a site with no component is refused rather than ignored',
    'has no lockable plugin or theme component'
);

$proposal = ['ready' => true, 'code' => ['component_inventory' => $inventory]];
duo_check_throws(
    static fn() => $classify->invoke(null, $driver, $proposal, false, ['plugins/ghost'], false, $cache),
    RuntimeException::class,
    'a first-party declaration naming no component of this site is refused before the registry or the target is reached',
    'which is not a component this site has'
);
duo_check_same([], $driver->calls, 'and the target was not called for it');

if ($zipAvailable) {
    // With a real inventory the classification re-proposes EVERY time — even
    // offline, even when nothing locks — because the classification (and any
    // unsourced blocker) has to be inside the digest the operator confirms.
    ob_start();
    $caught = null;
    try {
        $classify->invoke(null, $driver, $proposal, false, ['plugins/duo-agency'], true, $cache);
    } catch (RuntimeException $e) {
        $caught = $e->getMessage();
    }
    $printed = (string) ob_get_clean();
    duo_check_same('fixture: the target was called', $caught, '--offline still re-proposes: the classification goes back to the target for the digest');
    duo_check(str_contains($printed, '--offline: no release registry is contacted'), 'and --offline states what it changes before the proposal is rendered');
    duo_check(
        str_contains($printed, 'component(s) could not be sourced; the proposal below is blocked'),
        'and the unsourced components are announced before the proposal, with both remedies'
    );
    duo_check_same(
        ['duo', 'init', '--repo=/srv/site', '--format=json'],
        array_slice($driver->calls[0], 0, 4),
        'the second call is the proposal call'
    );
    duo_check(
        str_starts_with((string) ($driver->calls[0][4] ?? ''), '--code-lock-b64='),
        'carrying the classification as --code-lock-b64, inside the digest'
    );
    $carried = json_decode(base64_decode(substr((string) $driver->calls[0][4], strlen('--code-lock-b64=')), true), true);
    duo_check_same(
        ['locked', 'first-party', 'unsourced', 'locked'],
        array_column($carried, 'classification'),
        'and the carried plan is the full classification: locked (cached theme and imported akismet), declared, and unsourced, nothing omitted'
    );
}

// The removed flag is refused by name with the reason, not as "unsupported argument".
require_once __DIR__ . '/../../../../cli/src/Onboarding/Init.php';
$flagDriver = new SplitFixtureTransport();
ob_start();
$exit = \Duo\Orchestrator\InitCommand::run(
    $flagDriver,
    ['--code=full'],
    static function (array $refusal): void {},
    static fn(): int => 0,
    static fn(): mixed => "n\n"
);
ob_end_clean();
duo_check_same(1, $exit, '--code=full is refused');
duo_check_same([], $flagDriver->calls, 'before the target is ever called');
ob_start();
$exit = \Duo\Orchestrator\InitCommand::run(
    $flagDriver,
    ['--code=split'],
    static function (array $refusal): void {},
    static fn(): int => 0,
    static fn(): mixed => "n\n"
);
ob_end_clean();
duo_check_same(1, $exit, 'and so is --code=split: there is no mode, the split is the only shape');
ob_start();
$exit = \Duo\Orchestrator\InitCommand::run(
    $flagDriver,
    ['--first-party=woocommerce'],
    static function (array $refusal): void {},
    static fn(): int => 0,
    static fn(): mixed => "n\n"
);
ob_end_clean();
duo_check_same(1, $exit, 'a malformed --first-party identity is refused before the target is called');
duo_check_same([], $flagDriver->calls, 'with no target call made');

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
        && str_contains($confirmationSource, 'Canon::write_file($codeLockPath, CodeSourceLock::encode($lockRows, $firstParty));'),
    'and the code lock is published through its own distinct path variable, declarations included'
);
duo_check(
    str_contains($confirmationSource, "if (((\$proposal['code']['declaration']['format'] ?? null) === 2)) {"),
    'and is published for EVERY format-2 proposal, not only when something locked: an all-first-party site still needs its declaration on disk'
);

remove_tree($scratch);

duo_check_summary('regress_init_code_split');
