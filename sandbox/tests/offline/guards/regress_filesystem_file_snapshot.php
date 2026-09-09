<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderSdk.php';
require_once dirname(__DIR__, 4) . '/agent/src/Adapter/AdapterContractGrammar.php';
wprism_test_define_agent_versions();

use WPrism\AdapterContractGrammar;
use WPrism\FilesystemTreeSnapshot;
use WPrism\ProviderSdk;
use WPrismTest\FrozenPolicy;

$scratch = sys_get_temp_dir() . '/wprism-optional-file-' . bin2hex(random_bytes(8));
$root = $scratch . '/site';
mkdir($root . '/nested', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$read = static fn(string $path = '.htaccess'): array => ProviderSdk::filesystem_file_snapshot($root, $path);
$absent = ['path' => '.htaccess', 'state' => 'absent', 'file' => null];

// Existing tree callers require an existing root. New optional-file evidence
// must not turn their unreadable-root refusal into an empty successful tree.
wprism_check_throws(static fn() => ProviderSdk::filesystem_tree_snapshot($root, $root . '/.htaccess', '.htaccess'),
    RuntimeException::class, 'the existing tree API still refuses an absent root', 'absent');
wprism_check_same($absent, $read(), 'the SDK proves an exact file absent under an existing parent');
file_put_contents($root . '/.htaccess', '');
$empty = $read();
wprism_check_same(['path' => '.htaccess', 'state' => 'present', 'file' => [
    'bytes' => 0, 'mtime' => filemtime($root . '/.htaccess'), 'mode' => fileperms($root . '/.htaccess') & 07777,
    'sha256' => hash('sha256', ''),
]], $empty, 'a present empty file differs from absence with a complete bounded witness');
file_put_contents($root . '/.htaccess', "# retained\r\n\0\xff\n");
$present = $read();
wprism_check_same(hash('sha256', "# retained\r\n\0\xff\n"), $present['file']['sha256'], 'binary bytes and line endings are hashed without decoding');
wprism_check_same($present, $read(), 'repeated observation reaches an exact fixed point');
chmod($root . '/.htaccess', 0600);
wprism_check_same(0600, $read()['file']['mode'], 'permissions participate in the file witness');
$tree = ProviderSdk::filesystem_tree_snapshot($root, $root . '/.htaccess', '.htaccess');
wprism_check_same($tree['files'][0]['sha256'], $read()['file']['sha256'], 'present-file hashing reuses the existing tree observation semantics');
file_put_contents($root . '/nested/1', 'numeric basename');
wprism_check_same('present', $read('nested/1')['state'], 'nested canonical paths and numeric roster keys preserve identity');
wprism_check_same('absent', $read('nested/web.config')['state'], 'an absent nested file is a first-class result');

foreach (['', '.', '..', '../outside', '/.htaccess', 'nested//file', 'nested/../file', 'nested/./file', "nested/\0file", 'nested\\file'] as $path) {
    wprism_check_throws(static fn() => $read($path), RuntimeException::class, 'noncanonical file identity refuses', 'not canonical');
}
wprism_check_throws(static fn() => $read(str_repeat('x/', 128) . 'file'), RuntimeException::class, 'excess depth refuses before filesystem contact', 'path exceeds');
wprism_check_throws(static fn() => $read(str_repeat('x', 4097)), RuntimeException::class, 'excess path bytes refuse before filesystem contact', 'path exceeds');
wprism_check_throws(static fn() => $read('missing-parent/file'), RuntimeException::class, 'a missing parent cannot prove a missing leaf', 'parent is absent');
wprism_check_throws(static fn() => $read('nested'), RuntimeException::class, 'an existing directory is never a file or absence', 'nonregular');
wprism_check_throws(static fn() => ProviderSdk::filesystem_file_snapshot($root . '/.htaccess', 'file'), RuntimeException::class,
    'a regular containment root cannot mint a directory observation', 'not a directory');
wprism_check_throws(static fn() => ProviderSdk::filesystem_file_snapshot($root . '/absent', 'file'), RuntimeException::class,
    'an absent containment root is a refusal', 'absent');

foreach (['.htaccess', 'does-not-exist'] as $target) {
    symlink($root . '/' . $target, $root . '/link');
    wprism_check_throws(static fn() => $read('link'), RuntimeException::class, 'both live and dangling leaf symlinks refuse', 'nonregular');
    unlink($root . '/link');
}
symlink($root . '/nested', $root . '/alias');
wprism_check_throws(static fn() => $read('alias/file'), RuntimeException::class, 'an intermediate symlink cannot supply absence', 'parent is absent');
unlink($root . '/alias');
symlink($root, $scratch . '/root-alias');
wprism_check_throws(static fn() => ProviderSdk::filesystem_file_snapshot($scratch . '/root-alias', '.htaccess'), RuntimeException::class,
    'a symlinked containment root refuses', 'symlinked');
unlink($scratch . '/root-alias');
mkdir($root . '/unreadable', 0700);
chmod($root . '/unreadable', 0000);
try {
    if (!is_readable($root . '/unreadable')) {
        wprism_check_throws(static fn() => $read('unreadable/file'), RuntimeException::class,
            'an unreadable existing parent cannot manufacture an absent file', 'unreadable directory');
    }
} finally {
    chmod($root . '/unreadable', 0700);
    rmdir($root . '/unreadable');
}
// APFS commonly resolves different-case spellings to one inode. The exact
// parent roster must refuse that alias, not publish an absent lower-case name.
file_put_contents($root . '/CaseName', 'case');
clearstatcache(true, $root . '/casename');
if (file_exists($root . '/casename')) {
    wprism_check_throws(static fn() => $read('casename'), RuntimeException::class,
        'a case-folding filesystem cannot turn an alias into exact-name absence', 'presence disagrees');
} else {
    wprism_check_same('absent', $read('casename')['state'], 'case-sensitive filesystems retain distinct exact names');
}
unlink($root . '/CaseName');
if (is_dir($root . '/NESTED')) {
    wprism_check_throws(static fn() => $read('NESTED/web.config'), RuntimeException::class,
        'a different-case parent cannot mint another canonical file identity', 'exact canonical path');
}
file_put_contents($root . '/unreadable-sibling', 'private sibling');
chmod($root . '/unreadable-sibling', 0000);
wprism_check_same('absent', $read('absent-sibling')['state'], 'absence needs the parent roster, not sibling file contents');
chmod($root . '/unreadable-sibling', 0600);
unlink($root . '/unreadable-sibling');
if (function_exists('posix_mkfifo')) {
    posix_mkfifo($root . '/pipe', 0600);
    wprism_check_throws(static fn() => $read('pipe'), RuntimeException::class, 'a FIFO refuses before a blocking open', 'nonregular');
    unlink($root . '/pipe');
}
$large = fopen($root . '/large', 'wb');
ftruncate($large, FilesystemTreeSnapshot::MAX_FILE_BYTES);
fclose($large);
wprism_check_same(FilesystemTreeSnapshot::MAX_FILE_BYTES, $read('large')['file']['bytes'], 'the exact 16 MiB file limit is admitted');
$large = fopen($root . '/large', 'ab');
fwrite($large, 'x');
fclose($large);
wprism_check_throws(static fn() => $read('large'), RuntimeException::class, 'the next byte refuses before hashing', 'byte bound');
unlink($root . '/large');

$hook = new ReflectionProperty(FilesystemTreeSnapshot::class, 'testDirectoryObservationHook');
foreach (['insert', 'late-insert', 'delete', 'replace', 'rewrite', 'parent', 'symlink-parent', 'ancestor'] as $fault) {
    mkdir($root . '/race');
    if (!in_array($fault, ['insert', 'late-insert'], true)) file_put_contents($root . '/race/file', 'old');
    $ran = false;
    $hook->setValue(null, static function (string $phase, string $directory) use ($fault, &$ran, $root): void {
        $selected = $fault === 'late-insert' ? 'before-file-parent-revalidation' : 'after-file-hash';
        if ($phase !== $selected || $ran) return;
        $ran = true;
        if (in_array($fault, ['insert', 'late-insert'], true)) file_put_contents($directory . '/file', 'new');
        elseif ($fault === 'delete') unlink($directory . '/file');
        elseif ($fault === 'replace') { rename($directory . '/file', $directory . '/old'); file_put_contents($directory . '/file', 'old'); }
        elseif ($fault === 'rewrite') { $time = filemtime($directory . '/file'); file_put_contents($directory . '/file', 'new'); touch($directory . '/file', $time); }
        elseif ($fault === 'ancestor') { rename($root, $root . '-moved'); symlink($root . '-moved', $root); }
        else {
            rename($directory, $root . '/moved');
            if ($fault === 'parent') { mkdir($directory); file_put_contents($directory . '/file', 'old'); }
            else symlink($root . '/moved', $directory);
        }
    });
    try {
        wprism_check_throws(static fn() => $read('race/file'), RuntimeException::class, "$fault during observation cannot publish a stale witness");
        wprism_check($ran, "$fault ran through the public SDK observation boundary");
    } finally {
        $hook->setValue(null, null);
        if ($fault === 'ancestor') { unlink($root); rename($root . '-moved', $root); }
        $remove($root . '/race');
        $remove($root . '/moved');
    }
}

$feature = ProviderSdk::FILESYSTEM_FILE_SNAPSHOT_FEATURE;
wprism_check_same(['since' => 3, 'keys' => [], 'sections' => []], AdapterContractGrammar::implemented_feature_rows()[$feature],
    'the SDK feature is negotiated independently without adding manifest storage authority');
$manifest = ['name' => 'file-fixture', 'spec_version' => 3, 'engine_features' => [$feature, 'spec-window/v1']];
$policy = FrozenPolicy::policy([$manifest], FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION));
wprism_check_same($manifest['engine_features'], $policy->manifests[0]['engine_features'], 'the real frozen policy admits the negotiated feature');
$manifest['engine_features'][0] = 'provider-filesystem-file-snapshot/v2';
wprism_check_throws(static fn() => FrozenPolicy::policy([$manifest], FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION)),
    RuntimeException::class, 'an unknown SDK version refuses at policy load', 'provider-filesystem-file-snapshot/v2');
wprism_check_summary('filesystem file snapshot');
