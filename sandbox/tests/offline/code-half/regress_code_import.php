<?php
/**
 * Offline characterization for `wprism code-import` — the host verb that makes a
 * component with no wp.org release lockable instead of carried in Git.
 *
 * The store's own semantics (import, idempotence, corruption, the lookup the
 * classifier performs, the multi-root refusal) are pinned beside the
 * classification in regress_init_code_split.php, because that is where the
 * import changes an outcome. This suite pins the COMMAND: its closed flag
 * surface, that it reads one local file and touches no environment, what it
 * prints for the operator (the digests the lock will record, and where the
 * archive now lives), its `--format=json` shape, and that every refusal is a
 * reason-coded one the operator can act on. It contacts no network: the verb
 * never fetches anything, which is the whole point of it.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../cli/src/Code/WpOrgReleases.php';
require_once __DIR__ . '/../../../../cli/src/Code/ImportedArchives.php';
require_once __DIR__ . '/../../../../cli/src/Command/CodeImportCommand.php';

use WPrism\CodeSourceLock;
use WPrism\Orchestrator\CodeImportCommand;
use WPrism\Orchestrator\ImportedArchives;
use WPrism\Orchestrator\WpOrgReleases;

$scratch = sys_get_temp_dir() . '/wprism_regress_code_import_' . bin2hex(random_bytes(6));
$cache = $scratch . '/cache';
mkdir($cache, 0775, true);

function import_remove_tree(string $path): void {
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

/** @param array<string,string> $files */
function import_write_archive(string $archivePath, string $componentRoot, array $files): void {
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

/**
 * Run the command in a child process: its refusals go to STDERR, which
 * ob_start() cannot capture (the same seam regress_code_resolve.php uses).
 *
 * @param list<string> $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function import_run(array $args): array {
    $root = dirname(__DIR__, 4);
    $script = 'require ' . var_export($root . '/cli/src/Command/CodeImportCommand.php', true) . ';'
        . ' exit(\WPrism\Orchestrator\CodeImportCommand::run(json_decode($argv[1], true)));';
    $process = proc_open(
        [PHP_BINARY, '-r', $script, json_encode($args, JSON_UNESCAPED_SLASHES)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start the code-import runner');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

if (!class_exists(ZipArchive::class)) {
    wprism_check_throws(
        static fn() => (new WpOrgReleases($cache))->unpack($scratch . '/absent.zip', $scratch . '/out'),
        RuntimeException::class,
        'without ZipArchive the host refuses loudly and names the remedy instead of guessing',
        'install the php-zip extension'
    );
    import_remove_tree($scratch);
    wprism_check_summary('regress_code_import');
}

$premiumFiles = [
    'premium.php' => "<?php\n/**\n * Plugin Name: Premium\n * Version: 1.2.0\n */\n",
    'includes/licensing.php' => "<?php\n// license client\n",
];
$themeFiles = ['style.css' => "/*\nTheme Name: Agency Child\nVersion: 3.1.0\n*/\n"];
$premiumZip = $scratch . '/premium-1.2.0.zip';
$taggedZip = $scratch . '/premium-v1.2.0-tag.zip';
$themeZip = $scratch . '/agency-child.zip';
import_write_archive($premiumZip, 'premium', $premiumFiles);
import_write_archive($taggedZip, 'premium-1.2.0', $premiumFiles);
import_write_archive($themeZip, 'agency-child', $themeFiles);
$premiumDigest = (string) hash_file('sha256', $premiumZip);

// ---------------------------------------------------------------------------
// The flag surface is closed, and every refusal names its argument.
// ---------------------------------------------------------------------------

$result = import_run([]);
wprism_check_same(1, $result['exit'], 'no archive refuses');
wprism_check(str_contains($result['stderr'], 'requires the path of one release archive'), 'and says an archive is required');
$result = import_run([$premiumZip, $themeZip, '--cache-dir=' . $cache]);
wprism_check_same(1, $result['exit'], 'two archives refuse');
wprism_check(str_contains($result['stderr'], 'exactly one archive per run'), 'and say why');
$result = import_run([$premiumZip, '--force', '--cache-dir=' . $cache]);
wprism_check_same(1, $result['exit'], 'an unsupported flag refuses');
wprism_check(str_contains($result['stderr'], "unsupported argument '--force'"), 'naming the argument rather than printing usage');
$result = import_run([$premiumZip, '--cache-dir=relative/cache']);
wprism_check_same(1, $result['exit'], 'a relative --cache-dir refuses: the cache is shared across checkouts');
$result = import_run([$premiumZip, '--component=', '--cache-dir=' . $cache]);
wprism_check_same(1, $result['exit'], 'an empty --component refuses');
$result = import_run([$premiumZip, '--root=mu-plugins', '--cache-dir=' . $cache]);
wprism_check_same(1, $result['exit'], 'a root that is not plugins or themes refuses');
wprism_check(
    str_contains($result['stderr'], '[' . ImportedArchives::REASON_IMPORT_REFUSED . ']')
        && str_contains($result['stderr'], 'remedy: pass --root=plugins or --root=themes'),
    'with a reason code and the remedy'
);
$result = import_run([$scratch . '/missing.zip', '--cache-dir=' . $cache]);
wprism_check_same(1, $result['exit'], 'an archive that does not exist refuses');
wprism_check(str_contains($result['stderr'], '[' . ImportedArchives::REASON_IMPORT_REFUSED . ']'), 'with the import reason code');
wprism_check(!is_dir($cache . '/imported'), 'and no refusal above created the store');

// ---------------------------------------------------------------------------
// The import: what the operator reads, and what the store now holds.
// ---------------------------------------------------------------------------

$result = import_run([$premiumZip, '--cache-dir=' . $cache]);
wprism_check_same(0, $result['exit'], 'a wp.org-shaped archive (one directory named after the component) imports with no flags');
wprism_check(
    str_contains($result['stdout'], 'wprism: code-import: imported plugins/premium 1.2.0'),
    'the report names the component, its root and the Version header it declares'
);
wprism_check(str_contains($result['stdout'], 'archive_sha256: ' . $premiumDigest), 'and the archive digest the lock will record');
$store = ImportedArchives::forReleases(new WpOrgReleases($cache, true));
$treeDigest = WpOrgReleases::treeDigest((static function () use ($scratch, $premiumFiles): string {
    $probe = $scratch . '/probe';
    foreach ($premiumFiles as $relative => $body) {
        if (!is_dir(dirname($probe . '/' . $relative))) {
            mkdir(dirname($probe . '/' . $relative), 0775, true);
        }
        file_put_contents($probe . '/' . $relative, $body);
    }
    return $probe;
})());
wprism_check(str_contains($result['stdout'], 'tree_sha256:    ' . $treeDigest), 'and the tree digest an installed component must hash to');
wprism_check(!str_contains($result['stdout'], 'archive_root:'), 'and no archive_root line when the directory is named after the component');
wprism_check(str_contains($result['stdout'], 'stored at:      ' . $store->archivePathFor($premiumDigest)), 'and where the archive now lives');
wprism_check(
    str_contains($result['stdout'], 'move the archive to every host that resolves, with this same command'),
    'and tells the operator the one thing WPrism will not do for them'
);
wprism_check(is_file($store->archivePathFor($premiumDigest)), 'the archive is in the store under its digest');
wprism_check_same(
    ['archive_sha256' => $premiumDigest, 'kind' => 'imported-archive'],
    (static function (?array $o): ?array { if ($o !== null) ksort($o, SORT_STRING); return $o; })($store->originForTree($treeDigest, 'plugins', 'premium')),
    'and the classifier can now look the installed tree up and get the lock origin — digest only, no url, no path'
);
wprism_check_same(null, $store->originForTree($treeDigest, 'themes', 'premium'), 'but not under a different root');
wprism_check_same(null, $store->originForTree($treeDigest, 'plugins', 'other'), 'nor under a different component name');
CodeSourceLock::assert_lock(['format' => CodeSourceLock::FORMAT, 'first_party' => [], 'components' => [[
    'root' => 'plugins', 'component' => 'premium', 'version' => '1.2.0',
    'origin' => $store->originForTree($treeDigest, 'plugins', 'premium'), 'tree_sha256' => $treeDigest,
]]]);
wprism_check(true, 'the origin the import produces is one the lock grammar accepts unchanged');

$again = import_run([$premiumZip, '--cache-dir=' . $cache]);
wprism_check_same(0, $again['exit'], 'importing the same archive again succeeds');
wprism_check(str_contains($again['stdout'], 'already imported plugins/premium'), 'and says it was already imported');

// A tag archive whose directory is not named after the component needs
// --component, and records archive_root.
$tagged = import_run([$taggedZip, '--cache-dir=' . $cache]);
wprism_check_same(0, $tagged['exit'], 'a single-directory archive imports without --component: its one directory names the component');
wprism_check(str_contains($tagged['stdout'], 'imported plugins/premium-1.2.0'), 'but as the directory it unpacks to, which is NOT the slug here');
$taggedAs = import_run([$taggedZip, '--component=premium', '--cache-dir=' . $cache]);
wprism_check_same(0, $taggedAs['exit'], '--component=premium imports the same archive under the right identity');
wprism_check(str_contains($taggedAs['stdout'], 'imported plugins/premium 1.2.0'), 'naming the component');
wprism_check(str_contains($taggedAs['stdout'], 'archive_root:   premium-1.2.0'), 'and recording the archive_root the resolver will unpack');
wprism_check(
    str_contains($taggedAs['stdout'], 'already imported plugins/premium 1.2.0'),
    'the archive bytes are recognised as already stored, and the corrected identity is what the operator passed'
);

// A theme.
$theme = import_run([$themeZip, '--root=themes', '--cache-dir=' . $cache]);
wprism_check_same(0, $theme['exit'], 'a theme imports under --root=themes');
wprism_check(str_contains($theme['stdout'], 'imported themes/agency-child 3.1.0'), 'with the Version header read from style.css');

// --format=json carries the same facts for scripts.
$json = import_run([$premiumZip, '--cache-dir=' . $cache, '--format=json']);
wprism_check_same(0, $json['exit'], '--format=json succeeds');
$decoded = json_decode(trim($json['stdout']), true);
wprism_check_same('wprism-code-import/v1', $decoded['format'] ?? null, 'and declares its format');
wprism_check_same(
    ['archive_root' => 'premium', 'archive_sha256' => $premiumDigest, 'component' => 'premium', 'format' => 'wprism-code-import/v1',
        'path' => $store->archivePathFor($premiumDigest), 'root' => 'plugins', 'state' => 'already-imported',
        'tree_sha256' => $treeDigest, 'version' => '1.2.0'],
    (static function (array $d): array { ksort($d, SORT_STRING); return $d; })($decoded),
    'and carries exactly the identity the human report prints'
);

// The store is a subdirectory of the same content-addressed cache wp.org
// releases are fetched into, so `--cache-dir` moves both together.
wprism_check_same($cache . '/imported', $store->directory(), 'the imported store lives under the code-artifact cache');
wprism_check(
    !is_file($cache . '/' . hash('sha256', 'anything') . '.zip') && glob($cache . '/*.zip') === [],
    'and nothing was written beside it: the import fetched no release and warmed no wp.org entry'
);

import_remove_tree($scratch);
wprism_check_summary('regress_code_import');
