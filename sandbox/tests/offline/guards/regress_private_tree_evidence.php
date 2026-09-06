<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 2) . '/lib/FilesystemTreeEvidence.php';
require_once dirname(__DIR__, 2) . '/lib/PrivateCommandOutput.php';

use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateCommandOutput;

$scratch = sys_get_temp_dir() . '/wprism-private-tree-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$source = "$scratch/source";
mkdir("$source/state/0", 0700, true);
mkdir("$source/state/empty", 0700);
$raw = ["0/0" => "", 'demo.md' => "exact bytes\0\xff\r\nno final newline", 'state.json' => "{\"z\":1,\"a\":2}\n"];
foreach ($raw as $path => $bytes) file_put_contents("$source/state/$path", $bytes);
$tree = FilesystemTreeEvidence::capture($source, 'state');
FilesystemTreeEvidence::assertRecord($tree, 'state');
wprism_check_same(['', '0', 'empty'], $tree['directories'], 'retention preserves empty and numeric directories without adding a walker');
$actual = [];
foreach ($tree['files'] as $file) {
    $actual[$file['path']] = base64_decode($file['contents_base64'], true);
    wprism_check_same(hash('sha256', $actual[$file['path']]), $file['sha256'], 'retained bytes match the core snapshot hash');
}
wprism_check_same($raw, $actual, 'retention preserves binary bytes, JSON spelling, line endings and empty files');
$fileRoot = FilesystemTreeEvidence::capture($source, 'state/demo.md');
FilesystemTreeEvidence::assertRecord($fileRoot, 'state/demo.md');
wprism_check_same($raw['demo.md'], base64_decode($fileRoot['files'][0]['contents_base64'], true), 'ordinary file roots retain their exact bounded bytes too');
$empty = FilesystemTreeEvidence::capture($source, 'state/empty');
FilesystemTreeEvidence::assertRecord($empty, 'state/empty');
wprism_check_same(['root' => 'state/empty', 'directories' => [''], 'files' => []], $empty, 'an existing empty tree is distinct from a missing root');
$overEntries = $empty;
$overEntries['directories'] = array_fill(0, FilesystemTreeEvidence::MAX_ENTRIES + 1, '');
wprism_check_throws(static fn() => FilesystemTreeEvidence::assertRecord($overEntries, 'state/empty'), RuntimeException::class,
    'the retained record entry bound is enforced before traversing its roster');
file_put_contents("$source/exact-bound", str_repeat('x', FilesystemTreeEvidence::MAX_BYTES));
$exactBound = FilesystemTreeEvidence::capture($source, 'exact-bound');
FilesystemTreeEvidence::assertRecord($exactBound, 'exact-bound');
wprism_check_same(FilesystemTreeEvidence::MAX_BYTES, $exactBound['files'][0]['bytes'], 'the exact content bound is accepted through capture and retained admission');
$overRecord = $exactBound;
$overRecord['directories'] = [''];
$emptyRow = ['bytes' => 0, 'contents_base64' => '', 'mtime' => 1, 'path' => '', 'sha256' => hash('sha256', '')];
for ($index = 0; $index < 1500; $index++) {
    $overRecord['files'][] = array_replace($emptyRow, ['path' => 'z' . sprintf('%04d', $index)]);
}
wprism_check_throws(static fn() => FilesystemTreeEvidence::assertRecord($overRecord, 'exact-bound'), RuntimeException::class,
    'combined content and metadata cannot exceed the encoded transport budget', 'encoded record bound');

foreach (['hash', 'bytes', 'contents', 'missing-content', 'extra-field', 'float-size', 'mtime', 'absolute', 'parent', 'slash', 'control',
    'empty-path', 'duplicate-file', 'duplicate-dir', 'missing-parent', 'missing-root', 'root', 'unordered', 'nonlist', 'oversized'] as $fault) {
    $bad = $tree;
    switch ($fault) {
        case 'hash': $bad['files'][1]['sha256'] = str_repeat('a', 64); break;
        case 'bytes': $bad['files'][1]['bytes']++; break;
        case 'contents': $bad['files'][1]['contents_base64'] = base64_encode('different'); break;
        case 'missing-content': unset($bad['files'][1]['contents_base64']); break;
        case 'extra-field': $bad['files'][1]['trusted'] = true; break;
        case 'float-size': $bad['files'][1]['bytes'] = (float) $bad['files'][1]['bytes']; break;
        case 'mtime': $bad['files'][1]['mtime'] = 'unknown'; break;
        case 'absolute': $bad['files'][1]['path'] = '/outside'; break;
        case 'parent': $bad['files'][1]['path'] = '../outside'; break;
        case 'slash': $bad['files'][1]['path'] = 'a\\outside'; break;
        case 'control': $bad['files'][1]['path'] = "a\noutside"; break;
        case 'empty-path': $bad['files'][1]['path'] = ''; break;
        case 'duplicate-file': $bad['files'][] = $bad['files'][0]; break;
        case 'duplicate-dir': $bad['directories'][] = 'empty'; break;
        case 'missing-parent': $bad['directories'] = ['', 'empty']; break;
        case 'missing-root': $bad['directories'] = ['0', 'empty']; break;
        case 'root': $bad['root'] = 'different'; break;
        case 'unordered': $bad['files'] = array_reverse($bad['files']); break;
        case 'nonlist': $bad['files'] = ['file' => $bad['files'][0]]; break;
        case 'oversized': $bad['files'][1]['bytes'] = FilesystemTreeEvidence::MAX_BYTES + 1; break;
    }
    wprism_check_throws(static fn() => FilesystemTreeEvidence::assertRecord($bad, 'state'), RuntimeException::class,
        "retained tree decoder refuses $fault without the original filesystem");
}

file_put_contents("$scratch/foreign", 'must remain outside the selected tree');
symlink("$scratch/foreign", "$source/state/link");
wprism_check_throws(static fn() => FilesystemTreeEvidence::capture($source, 'state'), RuntimeException::class, 'core traversal refuses a linked child');
unlink("$source/state/link");
symlink("$source/state", "$source/linked-root");
wprism_check_throws(static fn() => FilesystemTreeEvidence::capture($source, 'linked-root'), RuntimeException::class, 'core traversal refuses a linked root');
wprism_check_throws(static fn() => FilesystemTreeEvidence::capture($source, '../foreign'), RuntimeException::class, 'core confinement refuses escaped roots');
wprism_check_throws(static fn() => FilesystemTreeEvidence::capture($source, 'absent'), RuntimeException::class, 'missing roots never become empty evidence');
file_put_contents("$source/state/oversized", str_repeat('x', FilesystemTreeEvidence::MAX_BYTES + 1));
wprism_check_throws(static fn() => FilesystemTreeEvidence::capture($source, 'state'), RuntimeException::class, 'a single oversized file refuses before byte retention');
unlink("$source/state/oversized");
file_put_contents("$source/state/half-a", str_repeat('x', intdiv(FilesystemTreeEvidence::MAX_BYTES, 2)));
file_put_contents("$source/state/half-b", str_repeat('x', intdiv(FilesystemTreeEvidence::MAX_BYTES, 2)));
wprism_check_throws(static fn() => FilesystemTreeEvidence::capture($source, 'state'), RuntimeException::class, 'the aggregate content budget cannot be bypassed with small files');
unlink("$source/state/half-a"); unlink("$source/state/half-b");

$hook = new ReflectionProperty(WPrism\FilesystemTreeSnapshot::class, 'testDirectoryObservationHook');
$observations = 0;
$mtime = filemtime("$source/state/state.json");
$hook->setValue(null, static function (string $phase, string $path, string $relative) use (&$observations, $source, $mtime): void {
    if ($phase === 'after-directory-snapshot' && $relative === '' && ++$observations === 3) {
        file_put_contents("$source/state/state.json", "{\"z\":9,\"a\":2}\n");
        touch("$source/state/state.json", $mtime);
    }
});
try {
    wprism_check_throws(static fn() => FilesystemTreeEvidence::capture($source, 'state'), RuntimeException::class,
        'a same-size same-mtime mutation after content retention cannot produce accepted mixed evidence');
} finally {
    $hook->setValue(null, null);
}
wprism_check($observations >= 3, 'the race control executes after the first complete core observation and content read');
$remove($source);
FilesystemTreeEvidence::assertRecord($tree, 'state');
wprism_check_same($raw['demo.md'], base64_decode($tree['files'][1]['contents_base64'], true), 'retained bytes remain independently verifiable after disposable teardown');

$private = "$scratch/private";
mkdir($private, 0700);
$stem = "$private/command";
$write = static function (string $suffix, string $bytes) use ($stem): void {
    file_put_contents("$stem.$suffix", $bytes);
    chmod("$stem.$suffix", 0600);
};
$answer = json_encode(['tree' => $tree], JSON_THROW_ON_ERROR);
$reset = static function () use ($write, $answer, $private): void {
    chmod($private, 0700);
    $write('stdout', $answer); $write('stderr', ''); $write('exit', "0\n");
};
$reset();
wprism_check_same($answer, PrivateCommandOutput::readObject($stem), 'private output admission returns the original complete object bytes');
$write('stderr', " Container bound-cli1-run-abcd Created \n");
wprism_check_same($answer, PrivateCommandOutput::readObject($stem, '/^ Container bound-cli1-run-[a-f0-9]+ Created $/D'), 'only the caller-bound lifecycle prelude is admitted');
wprism_check_throws(static fn() => PrivateCommandOutput::readObject($stem), RuntimeException::class, 'host-only observations admit no nonempty diagnostic prelude');
foreach (['nonzero', 'bad-exit', 'duplicate', 'array', 'empty-object', 'noise', 'large-stdout', 'large-stderr', 'large-exit', 'directory-mode',
    'stdout-mode', 'stderr-mode', 'exit-mode', 'missing', 'hardlink', 'symlink'] as $fault) {
    $reset();
    switch ($fault) {
        case 'nonzero': $write('exit', "1\n"); break;
        case 'bad-exit': $write('exit', '0'); break;
        case 'duplicate': $write('stdout', $answer . "\n" . $answer); break;
        case 'array': $write('stdout', '[]'); break;
        case 'empty-object': $write('stdout', '{}'); break;
        case 'noise': $write('stderr', "PHP Warning: unaccepted diagnostic\n"); break;
        case 'large-stdout': $write('stdout', str_repeat(' ', 1048577)); break;
        case 'large-stderr': $write('stderr', str_repeat("\n", 1048577)); break;
        case 'large-exit': $write('exit', str_repeat('0', 9)); break;
        case 'directory-mode': chmod($private, 0755); break;
        case 'stdout-mode': chmod("$stem.stdout", 0644); break;
        case 'stderr-mode': chmod("$stem.stderr", 0644); break;
        case 'exit-mode': chmod("$stem.exit", 0644); break;
        case 'missing': unlink("$stem.stdout"); break;
        case 'hardlink': link("$stem.stdout", "$scratch/shared"); break;
        case 'symlink': rename("$stem.stdout", "$scratch/shared"); symlink("$scratch/shared", "$stem.stdout"); break;
    }
    wprism_check_throws(static fn() => PrivateCommandOutput::readObject($stem), Throwable::class, "private output admission refuses $fault");
    if ($fault === 'hardlink') unlink("$scratch/shared");
    if ($fault === 'symlink') { unlink("$stem.stdout"); unlink("$scratch/shared"); }
}
wprism_check_summary('private filesystem tree evidence');
